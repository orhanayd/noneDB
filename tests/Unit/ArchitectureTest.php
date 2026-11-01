<?php

namespace noneDB\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Responsibility boundaries of the source, checked on tokens
 */
class ArchitectureTest extends TestCase
{
    /** File-system functions: only the storage, durability and recovery layers touch files */
    private const FILE_FUNCTIONS = ['fopen', 'fread', 'fgets', 'fwrite', 'fputs', 'fseek', 'ftell', 'fstat', 'ftruncate',
        'fflush', 'fclose', 'flock', 'file', 'file_get_contents', 'file_put_contents', 'scandir', 'glob', 'opendir',
        'readdir', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch', 'filesize', 'filemtime', 'is_file', 'is_dir',
        'file_exists', 'stat', 'clearstatcache', 'realpath', 'tempnam', 'fsync', 'fdatasync'];

    /** Names of the storage format (docs/STORAGE_FORMAT.md) */
    private const FORMAT_NAMES = ['.jsonl', '.kix', '.fx', '.rx', '.gfx', '.grx', '.ndb4', 'STORE', 'FORMAT', 'CURRENT',
        'LOCK', 'generations/', 'segments/', 'shards.json', 'indexes.json', 'pending.jsonl', 'flushing.', 'recovery/',
        '__nonedb_deleted', 'checksum'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return string[] PHP files under a directory of the repository, recursively */
    private static function files(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (substr((string)$file, -4) === '.php') {
                $files[] = (string)$file;
            }
        }
        sort($files);
        return $files;
    }

    /** @return array<int, array{0: int|null, 1: string, 2: int}> significant tokens of a file */
    private static function tokens(string $file): array
    {
        $out = [];
        foreach (token_get_all((string)file_get_contents($file)) as $token) {
            $token = is_array($token) ? $token : [null, $token, 0];
            if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $out[] = $token;
            }
        }
        return $out;
    }

    private static function relative(string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen(self::root()) + 1));
    }

    /**
     * @test the Query layer works on records and plans; it has no file-system access and no knowledge of the
     * storage format (it calls the storage services)
     */
    public function theQueryLayerKnowsNoFilesAndNoFormat(): void
    {
        $files = array_merge(self::files('src/Internal/Query'), self::files('src/noneDBQuery'));
        $this->assertGreaterThan(10, count($files));
        $found = [];
        foreach ($files as $file) {
            $tokens = self::tokens($file);
            foreach ($tokens as $i => [$id, $text, $line]) {
                $next = $tokens[$i + 1][1] ?? null;
                $previous = $tokens[$i - 1][0] ?? null;
                if ($id === T_STRING && $next === '(' && in_array(strtolower($text), self::FILE_FUNCTIONS, true)
                        && !in_array($previous, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    $found[] = self::relative($file) . ":$line $text()";
                }
                if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                    foreach (self::FORMAT_NAMES as $name) {
                        if (strpos($text, $name) !== false) {
                            $found[] = self::relative($file) . ":$line $text";
                        }
                    }
                }
            }
        }
        $this->assertSame([], $found);
    }

    /**
     * @test dependencies point inwards: the engine (src/Internal) never uses the query builder, which is part
     * of the public API (noneDBQuery calls the engine's Query services, not the other way round)
     */
    public function theEngineDoesNotDependOnTheQueryBuilder(): void
    {
        $found = [];
        foreach (self::files('src/Internal') as $file) {
            foreach (self::tokens($file) as [$id, $text, $line]) {
                if (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ltrim($text, '\\') === 'noneDBQuery') {
                    $found[] = self::relative($file) . ":$line";
                }
            }
        }
        $this->assertSame([], $found);
    }

    /** @test the migration tool is separate: the engine artifact has none of its symbols */
    public function theEngineHasNoMigrationToolSymbols(): void
    {
        $found = [];
        foreach (self::tokens(self::root() . '/noneDB.php') as [$id, $text, $line]) {
            if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true) && preg_match('/\bMigrate\b|\\\\Migrate\\\\|noneDBMigrate/', $text)) {
                $found[] = "$line $text";
            }
        }
        $this->assertSame([], $found);
        $this->assertDoesNotMatchRegularExpression('/^namespace noneDB\\\\Migrate/m', (string)file_get_contents(self::root() . '/noneDB.php'));
    }

    /**
     * @test the engine knows no earlier format: no old file names, no old encodings, no version-dated wording
     * (the M2 audit, kept as a test), no pointer to the migration tool, no
     * names of earlier releases (the code audit of 2026-10-29)
     */
    public function theEngineHasNoTraceOfEarlierVersions(): void
    {
        $code = (string)file_get_contents(self::root() . '/noneDB.php');
        $patterns = [
            'old file names' => '/jidx|fidx|gfidx|\bsidx|gsidx|nonedbinfo|\.nonedb\.(meta|buffer|indexes|recovery|lock)\b|_s[0-9]+\.nonedb|hash_cache|pbkdf2/',
            'old conversions' => '/migrateToJsonl|convertSegmentIndexes|upgradeIndexes|"data":\[|legacy/i',
            'version-dated wording' => '/\bv[0-3]\.[0-9]|\bsince [0-3]\.[0-9]|\bbefore [0-3]\.[0-9]|\bpre-[0-3]\.[0-9]|\bas in [0-3]\.[0-9]|kept for compatib|backward compatib/i',
            'PHP version branches' => '/PHP_VERSION_ID|function_exists\(.fsync|func_overload/',
            // the engine sends no one to the converter of old files (its documentation does)
            'the migration tool' => '/noneDB-migrate|MIGRATION\.md/',
            // domain strings of hashes are noneDB 4's ("nonedb4/..."), never a labelled earlier one ("...-v1")
            'version-labelled names' => '/[a-z]-v[0-9]+[\'"]/',
            'names of earlier releases' => '/\bROLLBACK_FAILED\b|\bautoMigrate\b|function migrate\(|jsonlGarbageThreshold/',
        ];
        foreach ($patterns as $label => $pattern) {
            preg_match_all($pattern, $code, $m);
            $this->assertSame([], $m[0], $label);
        }
    }

    /** @test every internal class is in the namespace of its directory, one class per file */
    public function internalClassesFollowTheirDirectories(): void
    {
        foreach (self::files('src/Internal') as $file) {
            $relative = substr($file, strlen(self::root() . '/src/'), -4);
            $expected = 'noneDB\\' . str_replace('/', '\\', dirname($relative));
            $content = (string)file_get_contents($file);
            $this->assertMatchesRegularExpression('/^namespace ' . preg_quote($expected, '/') . ';$/m', $content, $relative);
            $this->assertSame(1, preg_match_all('/^(?:final |abstract )?class \w+/m', $content), "$relative: one class");
            $this->assertMatchesRegularExpression('/^(?:final |abstract )?class ' . preg_quote(basename($relative), '/') . '\b/m', $content, $relative);
        }
    }

    /**
     * @test noneDB.php holds only the engine: no test interface, no test seam (fault points, test hooks and
     * counters exist only in the test build, tools/build.php), no seam marker
     */
    public function theEngineHoldsNoTestCode(): void
    {
        $code = (string)file_get_contents(self::root() . '/noneDB.php');
        foreach (['class Testing', '@test', '@prod', 'faultHook', '->fault(', 'syncFn', 'flushFn', 'writeFn', 'renameFn', 'mbstringFn',
                  'sweepBudget', 'segmentPairTests', 'pointEdgeTests', 'geometryValidations', 'segmentReads', 'directoryListings',
                  'indexStats', 'cacheEvictions', '$counter'] as $name) {
            $this->assertStringNotContainsString($name, $code, $name);
        }
        $this->assertFileDoesNotExist(self::root() . '/src/Internal/Testing.php');
    }

    /** @test the migration tool's classes are in the namespace of their directory under migrate/, one class per file */
    public function migrationToolClassesFollowTheirDirectories(): void
    {
        $files = array_filter(self::files('migrate'), function ($file) {
            return !in_array(basename($file), ['header.php', 'footer.php', 'manifest.php'], true);
        });
        $this->assertGreaterThan(8, count($files));
        $manifest = require self::root() . '/migrate/manifest.php';
        $this->assertSame(count($files), count($manifest['files']), 'every class file is built');
        foreach ($files as $file) {
            $relative = substr($file, strlen(self::root() . '/migrate/'), -4);
            $directory = dirname($relative);
            $expected = 'noneDB\\Migrate' . ($directory === '.' ? '' : '\\' . str_replace('/', '\\', $directory));
            $content = (string)file_get_contents($file);
            $this->assertMatchesRegularExpression('/^namespace ' . preg_quote($expected, '/') . ';$/m', $content, $relative);
            $this->assertSame(1, preg_match_all('/^(?:final |abstract )?class \w+/m', $content), "$relative: one class");
            $this->assertMatchesRegularExpression('/^(?:final |abstract )?class ' . preg_quote(basename($relative), '/') . '\b/m', $content, $relative);
        }
    }

    /** @test noneDB-migrate.php holds only the tool: its crash points exist in the test build only */
    public function theMigrationToolHoldsNoTestCode(): void
    {
        $code = (string)file_get_contents(self::root() . '/noneDB-migrate.php');
        foreach (['@test', '@prod', 'NONEDB_MIGRATE_KILL', 'posix_kill', 'self::point('] as $name) {
            $this->assertStringNotContainsString($name, $code, $name);
        }
        $this->assertStringContainsString('NONEDB_MIGRATE_KILL', (string)file_get_contents(NONEDB_TEST_TOOL), 'the test build has them');
    }

    /** @test tests reach the engine through noneDB\Tests\Support\Testing only (plan D8), never through Reflection */
    public function testsUseTheTestingInterface(): void
    {
        $found = [];
        foreach (self::files('tests') as $file) {
            // The interface itself, this test (its patterns) and the generated test build
            if (in_array(self::relative($file), ['tests/Unit/ArchitectureTest.php', 'tests/Support/Testing.php'], true)
                    || strncmp(self::relative($file), 'tests/build/', 12) === 0) {
                continue;
            }
            foreach (self::tokens($file) as [$id, $text, $line]) {
                if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match('/^\\\\?noneDB\\\\Internal\\\\/', $text)) {
                    $found[] = self::relative($file) . ":$line $text";
                }
                if (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) && in_array(ltrim($text, '\\'), ['ReflectionProperty', 'ReflectionMethod', 'ReflectionClass'], true)) {
                    $found[] = self::relative($file) . ":$line $text";
                }
            }
        }
        $this->assertSame([], $found);
    }
}
