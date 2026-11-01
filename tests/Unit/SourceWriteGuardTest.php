<?php

namespace noneDB\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every write to a database file goes through the write helpers (S3): they move the generation before the
 * file changes (noteWrite), so another process never trusts a cache of a file that changed.
 * A raw file-system write call anywhere else in noneDB.php fails this test.
 */
class SourceWriteGuardTest extends TestCase
{
    /** Functions that may call the raw functions: the helpers themselves and files of no database */
    private const ALLOWED = [
        'fsUnlink', 'fsRename', 'fsTouch', 'fsTruncate', 'fsMkdir', 'fsRmdir', 'writeFileAtomic',
        'writeDerivedFile', 'appendToFile', 'undoAppend', 'renameWithRetry', 'quarantineBytes', 'truncateBack',
        'removeStaleTemp',
        // Steps of the helpers above, never called before noteWrite(): writeAll (by appendToFile and
        // writeFileAtomic) and makeDirectory (by fsMkdir, writeFileAtomic, appendToFile; and for the store-level
        // directories dbDir and .nonedb-maintenance/, which belong to no database)
        'writeAll', 'makeDirectory',
        // The permissions of a file or directory one of the helpers above (or the store's) has just created
        'setPermissions',
        'saveLockState', 'lockFile',                     // the lock file (its state record is the generation)
        // mkdir {locator}.ndb4: creating the directory is the creation race lock and comes before the database's
        // LOCK, which lives inside it, can be taken (STORAGE_FORMAT.md §8.1 step 1); STORE.LOCK is held shared
        'withMissingDatabase',
        // Store-level files belong to no database: STORE.LOCK protects them, not a database lock (§3, §9)
        'withStoreLock',                                 // creates dbDir and opens STORE.LOCK (flock only, never written)
        'openStore',                                     // store creation under STORE.LOCK held exclusively: stale
                                                         // STORE*.tmp* removed, .htaccess and index.html (§3.2 steps 5, 7)
        'writeStoreFile',                                // atomic replacement of the STORE copies and the journal (§2, §3.1)
        'logStoreEvent',                                 // store-level recovery events in .nonedb-maintenance/
        'writeJournal', 'closeJournal',                  // creates .nonedb-maintenance/, removes the closed journal (§9.1)
        // Maintenance holds the target databases' LOCKs itself, outside the lock registry (§9.2): it opens them
        // and writes "m" with a new epoch into their state records (directory renames go through renameWithRetry)
        'lockMaintenanceTargets', 'clearMaintenanceMarks', 'markMaintenance',
        // A backup directory belongs to no database and lies outside dbDir (STORAGE_FORMAT.md §10.2): its
        // directories and files are created there, and removed again when the backup fails
        'makeBackupDirectory', 'writeBackupFile', 'removeBackupFiles',
    ];

    private const RAW = ['unlink', 'rename', 'touch', 'ftruncate', 'file_put_contents', 'fwrite', 'fputs', 'copy',
        'mkdir', 'rmdir', 'tempnam', 'symlink', 'link', 'chmod', 'fopen'];

    /**
     * Raw write calls per enclosing function: name => ["unlink@123", "fopen(c+b)@456", ...]
     */
    public static function rawWriteCalls(string $file): array
    {
        $tokens = token_get_all((string)file_get_contents($file));
        $function = null;
        $functionDepth = null;
        $depth = 0;
        $calls = [];
        $skip = function (int $i, int $step) use ($tokens) {
            while (isset($tokens[$i]) && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i += $step;
            }
            return $i;
        };
        foreach ($tokens as $i => $token) {
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                if ($functionDepth !== null && $depth < $functionDepth) {
                    $function = null;
                    $functionDepth = null;
                }
                continue;
            }
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_FUNCTION && $function === null) {
                $before = $skip($i - 1, -1);
                if (is_array($tokens[$before]) && $tokens[$before][0] === T_USE) {
                    continue; // "use function ...": an import, not a declaration
                }
                $j = $skip($i + 1, 1);
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $function = $tokens[$j][1];
                    $functionDepth = $depth + 1;
                }
                continue;
            }
            if ($token[0] !== T_STRING || !in_array(strtolower($token[1]), self::RAW, true)) {
                continue;
            }
            $next = $skip($i + 1, 1);
            $previous = $skip($i - 1, -1);
            if ($tokens[$next] !== '(' || (is_array($tokens[$previous])
                    && in_array($tokens[$previous][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true))) {
                continue; // not a call of the global function
            }
            $label = $token[1];
            if (strtolower($token[1]) === 'fopen') {
                // Read-only modes are fine
                $nesting = 0;
                $argument = 0;
                $mode = null;
                for ($k = $next + 1; isset($tokens[$k]); $k++) {
                    $t = $tokens[$k];
                    if ($t === '(') {
                        $nesting++;
                    } elseif ($t === ')') {
                        if ($nesting-- === 0) {
                            break;
                        }
                    } elseif ($t === ',' && $nesting === 0) {
                        $argument++;
                    } elseif ($argument === 1 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $mode = trim($t[1], "'\"");
                    }
                }
                if ($mode !== null && strpbrk($mode, 'waxc+') === false) {
                    continue;
                }
                $label .= '(' . ($mode ?? '?') . ')';
            }
            $calls[$function ?? '(file)'][] = $label . '@' . $token[2];
        }
        return $calls;
    }

    /** @test */
    public function databaseFilesAreOnlyWrittenThroughTheHelpers(): void
    {
        $calls = self::rawWriteCalls(dirname(__DIR__, 2) . '/noneDB.php');
        $outside = array_diff_key($calls, array_flip(self::ALLOWED));
        $this->assertSame([], $outside, 'raw file-system writes outside the write helpers (use fsUnlink/fsRename/'
            . 'fsTouch/fsTruncate/fsMkdir/fsRmdir/writeFileAtomic/appendToFile)');
        // The scanner finds what it should (the helpers themselves use the raw calls)
        $this->assertArrayHasKey('fsUnlink', $calls);
        $this->assertArrayHasKey('appendToFile', $calls);
    }
}
