<?php

namespace noneDB\Tests\Migration;

/**
 * A run as a process (docs/MIGRATION.md §7-§13): a dry run writes nothing; a busy lock stops it before anything is
 * written; a crash at every step leaves a state that the next run completes or --abort rolls back, and that no process
 * reads in between; a source changed under a run rolls it back; --restore puts the old files back byte for byte;
 * --cleanup; several keys in one store; option errors.
 */
class MigrationProcessTest extends MigrationTestCase
{
    /** Two databases of one key, written by 3.2: one sharded, one with a field index */
    private const FIXTURE = '3.2-two-databases';

    /** The run of FIXTURE, completed: both databases as 3.2 returned them, the key registered */
    private function assertMigrated(string $dir, string $label): void
    {
        $data = $this->fixture(self::FIXTURE);
        $db = $this->v4($dir);
        foreach (['users', 'orders'] as $name) {
            $this->assertSame($this->oracleRecords($data, '3.2', $name), $this->v4Records($db, $name), "$label: $name");
            $this->assertTrue($db->checkIntegrity($name)['ok'], "$label: $name integrity");
        }
        $this->assertSame(['city'], $db->getFieldIndexes('orders')['fields'], $label);
        $this->assertFileDoesNotExist($dir . '.nonedb-maintenance/journal.json', "$label: the journal is closed");
        // STORAGE_FORMAT.md §9.6: the retained files come with a sealed manifest naming each with its SHA-256
        $retained = glob($dir . '.nonedb-retained/*/manifest.json');
        $this->assertCount(1, $retained, $label);
        $manifest = $this->openSealedRecord((string)file_get_contents($retained[0]), 'migration-retained');
        $this->assertIsArray($manifest, "$label: a valid retained manifest");
        foreach ($manifest['files'] as $entry) {
            $this->assertSame($entry['sha256'], hash_file('sha256', dirname($retained[0]) . '/files/' . $entry['name']), "$label: {$entry['name']}");
        }
        $this->assertDirectoryDoesNotExist($dir . '.nonedb-maintenance/staging', "$label: no staging left");
    }

    /** The directory as it was, apart from the store's entries a stopped run may leave (an empty STORE.LOCK) */
    private function assertUnchanged(array $before, string $dir, string $label, array $allowed = []): void
    {
        $after = $this->snapshot($dir);
        foreach ($allowed as $file => $sha) {
            if (($after[$file] ?? null) === $sha) {
                unset($after[$file]);
            }
        }
        $this->assertSame($before, $after, $label);
    }

    /** The id of the run a crash interrupted: its open journal's, or the closed journal's in the backup */
    private function interruptedRun(string $dir): string
    {
        if (is_file($dir . '.nonedb-maintenance/journal.json')) {
            $journal = $this->openSealedRecord((string)file_get_contents($dir . '.nonedb-maintenance/journal.json'), 'journal');
            $this->assertIsArray($journal, 'a valid open journal');
            return $journal['id'];
        }
        $closed = glob($this->testDbDir . 'backup/*/journal.done.json');
        $this->assertCount(1, $closed, 'one closed journal');
        return basename(dirname($closed[0]));
    }

    /** §7.8: the closed journal of the run records FINAL_VERIFIED, and its report is next to it */
    private function assertFinalVerified(string $run, string $label): void
    {
        $backup = $this->testDbDir . "backup/$run/";
        $done = $this->openSealedRecord((string)@file_get_contents($backup . 'journal.done.json'), 'journal');
        $this->assertIsArray($done, "$label: a valid closed journal");
        $this->assertSame('FINAL_VERIFIED', $done['state'], "$label: the final verification is recorded");
        $report = json_decode((string)@file_get_contents($backup . 'report.json'), true);
        $this->assertSame($run, $report['run'] ?? null, "$label: the report of the run");
        $data = $this->fixture(self::FIXTURE);
        $expected = [];
        foreach (['orders', 'users'] as $name) {
            $expected[$name] = count($this->oracleRecords($data, '3.2', $name));
        }
        $counts = $report['databases'] ?? [];
        ksort($counts);
        $this->assertSame($expected, $counts, "$label: the records of each database");
    }

    private function args(string $dir, array $more = []): array
    {
        return array_merge(["--dir=$dir", '--backup-dir=' . $this->testDbDir . 'backup/', '--confirm-stopped'], $more);
    }

    private function key(): string
    {
        return $this->fixture(self::FIXTURE)['sourceKey'];
    }

    /** @test §7.1: a dry run reads and reports, and writes nothing */
    public function aDryRunWritesNothing(): void
    {
        $dir = $this->stage(self::FIXTURE);
        $before = $this->snapshot($dir);
        [$status, $out] = $this->tool(["--dir=$dir", '--dry-run'], $this->key());
        $this->assertSame(0, $status);
        $this->assertSame('READY', $out['report']['result']);
        $this->assertSame(['orders', 'users'], array_keys($out['report']['databases']));
        $this->assertSame(21, $out['report']['databases']['users']['records']);
        $this->assertSame(23, $out['report']['databases']['users']['nextKey']);
        $this->assertSame(['city'], $out['report']['databases']['orders']['fields']);
        $this->assertSame($before, $this->snapshot($dir));
        $this->assertDirectoryDoesNotExist($this->testDbDir . 'backup');
    }

    /**
     * @test STORAGE_FORMAT.md §3, MIGRATION.md §14: what a run writes into the store has the engine's permissions (files
     * 0640, directories 0700 + group read and search: 0750), its backup 0600/0700, whatever the umask
     */
    public function aRunWritesWithTheEnginesPermissions(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX permissions');
        }
        $dir = $this->stage(self::FIXTURE);
        $umask = umask(0077);
        try {
            [$status] = $this->tool($this->args($dir), $this->key());
        } finally {
            umask($umask);
        }
        $this->assertSame(0, $status);
        $this->assertMigrated($dir, 'run');
        $modes = function (string $root, string $prefix) use (&$modes): array {
            $out = [$prefix => fileperms($root) & 0777];
            if (is_dir($root)) {
                foreach (array_diff(scandir($root), ['.', '..']) as $entry) {
                    $out += $modes("$root/$entry", "$prefix/$entry");
                }
            }
            return $out;
        };
        $store = [];
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            if (preg_match('/^(STORE|STORE\.2|STORE\.LOCK|\.htaccess|index\.html|[0-9a-f]{32}\.ndb4|\.nonedb-maintenance)$/', $entry) === 1) {
                $store += $modes($dir . $entry, $entry);
            }
        }
        $this->assertGreaterThan(20, count($store));
        foreach ($store as $path => $mode) {
            $this->assertSame(sprintf('%o', is_dir($dir . $path) ? 0750 : 0640), sprintf('%o', $mode), $path);
        }
        foreach ($modes(rtrim($this->testDbDir, '/') . '/backup', 'backup') as $path => $mode) {
            $this->assertSame(sprintf('%o', is_dir($this->testDbDir . $path) ? 0700 : 0600), sprintf('%o', $mode), $path);
        }
    }

    /** @test §7.2: a lock another process holds stops the run before any journal, backup or flag is written */
    public function aBusyLockStopsTheRunBeforeAnythingIsWritten(): void
    {
        $dir = $this->stage(self::FIXTURE);
        $before = $this->snapshot($dir);
        $lock = fopen(glob($dir . '*-orders.nonedb.lock')[0], 'c');
        $this->assertTrue(flock($lock, LOCK_EX));
        [$status, $out] = $this->tool($this->args($dir), $this->key());
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertSame(3, $status);
        $this->assertSame('MAINTENANCE_BUSY', $out['result']);
        $this->assertUnchanged($before, $dir, 'busy', ['STORE.LOCK' => hash('sha256', '')]);
        $this->assertDirectoryDoesNotExist($this->testDbDir . 'backup');
    }

    public function crashPoints(): array
    {
        $points = [];
        foreach (['state:DISCOVERED', 'state:BACKED_UP', 'state:STAGING', 'state:VERIFIED', 'state:PUBLISHING', 'published',
            'state:COMMITTED', 'applied:store', 'applied:retained', 'applied:unmarked', 'closed', 'reported'] as $point) {
            $points[$point] = [$point];
        }
        return $points;
    }

    /**
     * @test §10: a crash at every step. Until the journal is closed every process gets MAINTENANCE_IN_PROGRESS; the
     * next run completes the migration; before the commit --abort leaves the old files as they were, after it --abort
     * is refused and the next run completes
     * @dataProvider crashPoints
     */
    public function aCrashAtEveryStepIsCompletedOrRolledBack(string $point): void
    {
        $this->requireCrashPoints();
        $committed = array_search($point, array_keys($this->crashPoints()), true) >= 6;
        $closed = in_array($point, ['closed', 'reported'], true); // the journal is closed: the final verification is left
        foreach (['run again', 'abort'] as $then) {
            $this->cleanTestDirectory();
            $dir = $this->stage(self::FIXTURE);
            $before = $this->snapshot($dir);
            putenv("NONEDB_MIGRATE_KILL=$point");
            [$status] = $this->tool($this->args($dir), $this->key());
            putenv('NONEDB_MIGRATE_KILL');
            $this->assertNotSame(0, $status, "$point: the process was killed");
            foreach (glob($dir . '*.ndb4') as $home) {
                // §7.6: a published database carries m until the run's commit has been applied
                $flags = explode(' ', trim(substr((string)file_get_contents("$home/LOCK"), 0, 128)));
                $this->assertSame(!in_array($point, ['applied:unmarked', 'closed', 'reported'], true), in_array('m', $flags, true), "$point: m on " . basename($home));
            }
            if (!$closed) {
                foreach ([self::TARGET, 'some-other-key-of-this-store'] as $key) {
                    try {
                        $this->v4($dir, $key)->find('users', 0);
                        $this->fail("$point: a process read the store during the migration");
                    } catch (\RuntimeException $e) {
                        $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $e->getCode(), "$point: " . $e->getMessage());
                    }
                }
            }
            if ($then === 'run again') {
                $killed = $this->interruptedRun($dir);
                [$status, $out, $stdout, $stderr] = $this->tool($this->args($dir), $this->key());
                $this->assertSame(0, $status, "$point, run again: $stdout$stderr");
                $this->assertMigrated($dir, "$point, run again");
                // §10: the next run completes the interrupted one, through its final verification (§7.8)
                $this->assertSame($killed, $out['migrated']['run'], "$point: the run again reports the interrupted run");
                $this->assertFinalVerified($killed, "$point, run again");
                continue;
            }
            [$status, $out, $stdout] = $this->tool(["--dir=$dir", '--abort'], $this->key());
            if ($closed) {
                $this->assertSame(5, $status, "$point: no open run to abort");
                $this->assertMigrated($dir, "$point, after the run");
            } elseif ($committed) {
                $this->assertSame(5, $status, "$point: a committed run is not aborted\n$stdout");
                [$status] = $this->tool($this->args($dir), $this->key());
                $this->assertSame(0, $status, "$point, run again after the refused abort");
                $this->assertMigrated($dir, "$point, completed");
            } else {
                $this->assertSame(0, $status, "$point, abort: $stdout");
                $after = $this->snapshot($dir);
                $this->assertSame($before, array_intersect_key($after, $before), "$point, abort: the old files are untouched");
                $this->assertSame([], array_values(array_diff(array_keys($after), array_keys($before), ['STORE.LOCK'])),
                    "$point, abort: nothing of the run is left but STORE.LOCK");
            }
        }
    }

    /** @test §7.6: a source file that changes during the run rolls the run back */
    public function aSourceChangedDuringTheRunRollsItBack(): void
    {
        $this->requireCrashPoints();
        $dir = $this->stage(self::FIXTURE);
        putenv('NONEDB_MIGRATE_KILL=state:VERIFIED');
        $this->tool($this->args($dir), $this->key());
        putenv('NONEDB_MIGRATE_KILL');
        $data = glob($dir . '*-orders_s1.nonedb')[0];
        file_put_contents($data, "{\"n\":\"late\",\"key\":7}\n", FILE_APPEND);
        $before = $this->snapshot($dir);
        [$status, $out] = $this->tool($this->args($dir), $this->key());
        $this->assertSame(2, $status);
        $this->assertSame('AMBIGUOUS_SOURCE', $out['result']);
        $this->assertStringContainsString(basename($data), $out['message']);
        $after = $this->snapshot($dir);
        $this->assertSame(array_filter($before, function ($file) { return strpos($file, '.nonedb-maintenance/') !== 0; }, ARRAY_FILTER_USE_KEY),
            array_intersect_key($after, $before), 'the old files are untouched');
        $this->assertFileDoesNotExist($dir . '.nonedb-maintenance/journal.json');
        $this->assertSame([], glob($dir . '*.ndb4'), 'nothing published');
    }

    /**
     * A run during which another process appends to every source file the nth time the run reaches $point: its
     * result, the points it reached (the test build's trace), the files before the run (with the appended line)
     * and after it
     */
    private function runWithSourcesChangedAt(string $point, int $nth): array
    {
        $this->requireCrashPoints();
        $dir = $this->stage(self::FIXTURE);
        $sources = glob($dir . '*.nonedb');
        $this->assertCount(7, $sources, 'precondition: the data files of users (5 shards) and orders (2)');
        $trace = $this->testDbDir . 'trace.txt';
        $line = "{\"n\":\"late\",\"key\":7}\n";
        putenv('NONEDB_MIGRATE_TRACE=' . $trace);
        putenv('NONEDB_MIGRATE_CHANGE=' . json_encode(['at' => $point, 'nth' => $nth, 'files' => $sources, 'append' => $line]));
        try {
            $before = $this->snapshot($dir);
            [$status, $out] = $this->tool($this->args($dir), $this->key());
        } finally {
            putenv('NONEDB_MIGRATE_TRACE');
            putenv('NONEDB_MIGRATE_CHANGE');
        }
        foreach ($sources as $source) {
            $this->assertStringEndsWith($line, (string)file_get_contents($source), 'precondition: changed during the run');
        }
        $changed = $before;
        foreach ($sources as $source) {
            $changed[substr($source, strlen($dir))] = hash_file('sha256', $source);
        }
        return [$status, $out, file($trace, FILE_IGNORE_NEW_LINES), $changed, $this->snapshot($dir), $dir];
    }

    /**
     * @test §7.6: a database whose source files changed after the run checked them is not published: the run stops
     * at that database (the first one is published, the second never) and is rolled back
     */
    public function aDatabaseWhoseSourceChangedIsNotPublished(): void
    {
        [$status, $out, $points, $changed, $after, $dir] = $this->runWithSourcesChangedAt('published', 1);
        $this->assertSame([2, 'AMBIGUOUS_SOURCE'], [$status, $out['result']]);
        $this->assertSame(1, count(array_keys($points, 'published', true)), 'one database published, the other not');
        $this->assertNotContains('state:COMMITTED', $points);
        $this->assertSame(array_filter($changed, function ($file) { return strpos($file, '.nonedb-maintenance/') !== 0; }, ARRAY_FILTER_USE_KEY),
            array_intersect_key($after, $changed), 'the old files are as the other process left them');
        $this->assertFileDoesNotExist($dir . '.nonedb-maintenance/journal.json');
        $this->assertSame([], glob($dir . '*.ndb4'), 'the published database was taken back');
    }

    /**
     * @test §7.7: source files that changed after the last database was published stop the commit: nothing is
     * committed and the run is rolled back
     */
    public function aSourceChangedAfterTheLastPublishStopsTheCommit(): void
    {
        [$status, $out, $points, $changed, $after, $dir] = $this->runWithSourcesChangedAt('published', 2);
        $this->assertSame([2, 'AMBIGUOUS_SOURCE'], [$status, $out['result']]);
        $this->assertSame(2, count(array_keys($points, 'published', true)), 'precondition: both databases published');
        $this->assertNotContains('state:COMMITTED', $points);
        $this->assertSame(array_filter($changed, function ($file) { return strpos($file, '.nonedb-maintenance/') !== 0; }, ARRAY_FILTER_USE_KEY),
            array_intersect_key($after, $changed), 'the old files are as the other process left them');
        $this->assertFileDoesNotExist($dir . '.nonedb-maintenance/journal.json');
        $this->assertSame([], glob($dir . '*.ndb4'), 'both published databases were taken back');
    }

    /** @test §10: an interrupted run is continued only with the keys it was started with */
    public function anInterruptedRunNeedsItsOwnKeys(): void
    {
        $this->requireCrashPoints();
        $dir = $this->stage(self::FIXTURE);
        putenv('NONEDB_MIGRATE_KILL=state:VERIFIED');
        $this->tool($this->args($dir), $this->key());
        putenv('NONEDB_MIGRATE_KILL');
        $before = $this->snapshot($dir);
        [$status, $out] = $this->tool($this->args($dir), $this->key(), 'migration-test-another-target-key');
        $this->assertSame([4, 'MAINTENANCE_IN_PROGRESS'], [$status, $out['result']]);
        $this->assertSame($before, $this->snapshot($dir));
        [$status] = $this->tool($this->args($dir), $this->key());
        $this->assertSame(0, $status);
        $this->assertMigrated($dir, 'continued with its keys');
    }

    /**
     * @test §2 "Settings": noneDB 4 refuses a setting it doesn't know, also in a `.nonedb` file it finds (here in the
     * working directory, with noneDB 3.x's autoMigrate): the pre-check stops the run, names the setting and the file,
     * and nothing is written
     */
    public function aSettingNoneDB4DoesNotKnowStopsTheRunFirst(): void
    {
        $dir = $this->stage(self::FIXTURE);
        $cwd = realpath(sys_get_temp_dir()) . '/nonedb_migrate_cwd_' . bin2hex(random_bytes(4));
        mkdir($cwd);
        try {
            file_put_contents("$cwd/.nonedb", json_encode(['secretKey' => self::TARGET, 'dbDir' => './db/', 'autoMigrate' => true]));
            $before = $this->snapshot($dir);
            [$status, $out] = $this->tool($this->args($dir), $this->key(), self::TARGET, $cwd);
            $this->assertSame([5, 'FAILED'], [$status, $out['result']]);
            $this->assertStringContainsString('noneDB 4 refuses the key or the configuration', $out['message']);
            $this->assertStringContainsString("unknown setting 'autoMigrate' in " . realpath($cwd) . '/.nonedb', $out['message']);
            $this->assertSame($before, $this->snapshot($dir), 'the directory is untouched');
            $this->assertFileDoesNotExist($this->testDbDir . 'backup', 'no backup started');
            $this->assertSame(['.nonedb'], array_values(array_diff(scandir($cwd), ['.', '..'])), 'nothing created there');
        } finally {
            exec('rm -rf ' . escapeshellarg($cwd));
        }
    }

    /** @test §8: a backup that no longer matches its manifest is never trusted: the run does not go on */
    public function aDamagedBackupStopsTheRun(): void
    {
        $this->requireCrashPoints();
        $dir = $this->stage(self::FIXTURE);
        putenv('NONEDB_MIGRATE_KILL=state:BACKED_UP');
        $this->tool($this->args($dir), $this->key());
        putenv('NONEDB_MIGRATE_KILL');
        $copy = glob($this->testDbDir . 'backup/*/files/*-users_s0.nonedb')[0];
        file_put_contents($copy, 'x', FILE_APPEND);
        $before = $this->snapshot($dir);
        [$status, $out] = $this->tool($this->args($dir), $this->key());
        $this->assertSame([5, 'FAILED'], [$status, $out['result']]);
        $this->assertStringContainsString('does not match the manifest', $out['message']);
        $this->assertSame($before, $this->snapshot($dir));
    }

    /** @test §11, §12: --restore puts the directory back byte for byte, before or after --cleanup */
    public function restorePutsTheOldFilesBack(): void
    {
        foreach ([false, true] as $cleanup) {
            $this->cleanTestDirectory();
            $dir = $this->stage(self::FIXTURE);
            $before = $this->snapshot($dir);
            [$status, $out] = $this->tool($this->args($dir), $this->key());
            $this->assertSame(0, $status);
            $run = $out['migrated']['run'];
            $backup = $this->testDbDir . 'backup/';
            if ($cleanup) {
                [$status, $out] = $this->tool(["--dir=$dir", "--backup-dir=$backup", "--cleanup=$run"], $this->key());
                $this->assertSame(0, $status);
                $this->assertDirectoryDoesNotExist($dir . ".nonedb-retained/$run");
            }
            [$status, $out, $stdout] = $this->tool(["--dir=$dir", "--backup-dir=$backup", "--restore=$run"], $this->key());
            $this->assertSame(0, $status, $stdout);
            $this->assertSame($before, $this->snapshot($dir), $cleanup ? 'restored after cleanup' : 'restored');
            $this->assertCount(2, glob("$backup$run/v4/*.ndb4"), 'the noneDB 4 databases are kept in the backup');
        }
    }

    /**
     * @test §7.8, §10, §12: a crash after the journal is closed, before the final verification is recorded (it runs
     * after the close; with --verify it reads every record). --cleanup refuses the run, nothing removed; the next run
     * verifies it (it migrates nothing again) and reports it; then --cleanup removes the retained files
     */
    public function aRunClosedBeforeItsFinalVerificationIsVerifiedByTheNextRun(): void
    {
        $this->requireCrashPoints();
        $dir = $this->stage(self::FIXTURE);
        putenv('NONEDB_MIGRATE_KILL=closed');
        [$status] = $this->tool($this->args($dir, ['--verify']), $this->key());
        putenv('NONEDB_MIGRATE_KILL');
        $this->assertNotSame(0, $status, 'the process was killed');
        $run = $this->interruptedRun($dir);
        $backup = $this->testDbDir . 'backup/';
        $done = $this->openSealedRecord((string)file_get_contents("$backup$run/journal.done.json"), 'journal');
        $this->assertSame('COMMITTED', $done['state'], 'closed, not verified');
        $this->assertFileDoesNotExist("$backup$run/report.json");

        $retained = $this->snapshot($dir . ".nonedb-retained/$run");
        [$status, $out, $stdout] = $this->tool(["--dir=$dir", "--backup-dir=$backup", "--cleanup=$run"], $this->key());
        $this->assertSame(5, $status, $stdout);
        $this->assertStringContainsString("the run $run has no final verification; run the tool again first", $out['message'] ?? '');
        $this->assertSame('FAILED', $out['result'] ?? null);
        $this->assertSame($retained, $this->snapshot($dir . ".nonedb-retained/$run"), 'nothing removed');

        // §10: a run with other keys never takes the run as its own: it finds nothing of its keys, the run stays unverified
        foreach ([['migration-test-another-source-key', null], [$this->key(), 'migration-test-another-target-key']] as [$source, $target]) {
            [$status, $out, $stdout] = $target === null ? $this->tool($this->args($dir), $source) : $this->tool($this->args($dir), $source, $target);
            $this->assertSame(0, $status, $stdout);
            $this->assertNull($out['migrated']['run'], "other keys: nothing to do");
            $this->assertSame('COMMITTED', $this->openSealedRecord((string)file_get_contents("$backup$run/journal.done.json"), 'journal')['state']);
        }
        // §7.2: the final verification is maintenance too: another holder of STORE.LOCK stops it, nothing written
        $lock = fopen($dir . 'STORE.LOCK', 'c');
        $this->assertTrue(flock($lock, LOCK_EX));
        $held = $this->snapshot($this->testDbDir);
        [$status, $out] = $this->tool($this->args($dir, ['--verify']), $this->key());
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertSame(3, $status, 'MAINTENANCE_BUSY');
        $this->assertSame('MAINTENANCE_BUSY', $out['result']);
        $this->assertSame($held, $this->snapshot($this->testDbDir), 'nothing written while busy');
        $published = $this->snapshot($dir);
        [$status, $out, $stdout] = $this->tool($this->args($dir, ['--verify']), $this->key());
        $this->assertSame(0, $status, $stdout);
        $this->assertSame($run, $out['migrated']['run'], 'the run again reports the interrupted run');
        $this->assertFinalVerified($run, 'run again');
        $this->assertTrue($this->openSealedRecord((string)file_get_contents("$backup$run/journal.done.json"), 'journal')['options']['verify'],
            'verified as the run was asked: every record (--verify)');
        $this->assertMigrated($dir, 'run again');
        $this->assertCount(1, glob("$backup*", GLOB_ONLYDIR), 'no other run');
        $this->assertSame(array_keys($published), array_keys($this->snapshot($dir)), 'nothing migrated again');
        [$status, $out] = $this->tool($this->args($dir), $this->key());
        $this->assertSame(0, $status);
        $this->assertNull($out['migrated']['run'], 'a verified run is not reported again: nothing left to do');

        [$status, $out, $stdout] = $this->tool(["--dir=$dir", "--backup-dir=$backup", "--cleanup=$run"], $this->key());
        $this->assertSame(0, $status, $stdout);
        $this->assertDirectoryDoesNotExist($dir . ".nonedb-retained/$run");
    }

    /** The temp files of atomic replacements the tool makes in the directory (STORAGE_FORMAT.md §2) */
    private function temps(string $dir): array
    {
        return array_values(array_filter(array_keys($this->snapshot($dir)), function ($path) {
            return preg_match('/\.tmp[0-9a-f]{8}$/', $path) === 1;
        }));
    }

    /**
     * @test STORAGE_FORMAT.md §2: a run killed between writing a temp and renaming it (the journal, a STORE copy)
     * leaves that temp; the next run, an abort and a restore leave none: the restore gives back the old directory
     * byte for byte
     */
    public function aTempFileOfAKilledRunIsRemovedByTheNextOne(): void
    {
        $this->requireCrashPoints();
        $cases = [
            'the first journal, run again' => ['renaming:journal.json#1', '.nonedb-maintenance/journal.json', 'run again'],
            'a later journal state, run again' => ['renaming:journal.json#3', '.nonedb-maintenance/journal.json', 'run again'],
            'a later journal state, aborted' => ['renaming:journal.json#3', '.nonedb-maintenance/journal.json', 'abort'],
            'the staging store, run again' => ['renaming:STORE.2#1', '.nonedb-maintenance/staging/STORE.2', 'run again'],
            'STORE.2, run again' => ['renaming:STORE.2#2', 'STORE.2', 'run again'],
            'STORE, run again' => ['renaming:STORE#2', 'STORE', 'run again'],
        ];
        foreach ($cases as $label => [$point, $file, $then]) {
            $this->cleanTestDirectory();
            $dir = $this->stage(self::FIXTURE);
            $before = $this->snapshot($dir);
            putenv("NONEDB_MIGRATE_KILL=$point");
            [$status] = $this->tool($this->args($dir), $this->key());
            putenv('NONEDB_MIGRATE_KILL');
            $this->assertNotSame(0, $status, "$label: killed");
            $temps = $this->temps($dir);
            $this->assertCount(1, $temps, "$label: the temp of the file being written");
            $this->assertMatchesRegularExpression('/^' . preg_quote($file, '/') . '\.tmp[0-9a-f]{8}$/', $temps[0], $label);
            if ($then === 'abort') {
                [$status, $out, $stdout] = $this->tool(["--dir=$dir", '--abort'], $this->key());
                $this->assertSame(0, $status, "$label: $stdout");
                $this->assertUnchanged($before, $dir, "$label: nothing of the run is left but STORE.LOCK", ['STORE.LOCK' => hash('sha256', '')]);
                continue;
            }
            [$status, $out, $stdout] = $this->tool($this->args($dir), $this->key());
            $this->assertSame(0, $status, "$label: $stdout");
            $this->assertMigrated($dir, $label);
            $this->assertSame([], $this->temps($dir), "$label: no temp left");
            [$status, $out, $stdout] = $this->tool(["--dir=$dir", '--backup-dir=' . $this->testDbDir . 'backup/', '--restore=' . $out['migrated']['run']], $this->key());
            $this->assertSame(0, $status, "$label, restore: $stdout");
            $this->assertSame($before, $this->snapshot($dir), "$label: restored byte for byte");
        }
    }

    /**
     * @test §7.2, §11: a run stopped before its journal leaves only an empty STORE.LOCK (killed once its locks were
     * taken, or stopped by a busy lock). Without a STORE record that file is a leftover of the tool (noneDB 4 creates
     * none next to old files): the run that follows counts it as its own, and its restore gives back the directory the
     * old release left, byte for byte
     */
    public function aRestoreAfterARunStoppedBeforeItsJournalLeavesNoStoreLock(): void
    {
        foreach (['busy', 'killed'] as $stop) {
            $this->cleanTestDirectory();
            $dir = $this->stage(self::FIXTURE);
            $before = $this->snapshot($dir);
            $this->assertArrayNotHasKey('STORE.LOCK', $before);
            if ($stop === 'killed') {
                $this->requireCrashPoints();
                putenv('NONEDB_MIGRATE_KILL=locked');
                [$status] = $this->tool($this->args($dir), $this->key());
                putenv('NONEDB_MIGRATE_KILL');
                $this->assertNotSame(0, $status, 'the process was killed');
            } else {
                $lock = fopen(glob($dir . '*-orders.nonedb.lock')[0], 'c');
                $this->assertTrue(flock($lock, LOCK_EX));
                [$status] = $this->tool($this->args($dir), $this->key());
                flock($lock, LOCK_UN);
                fclose($lock);
                $this->assertSame(3, $status, 'MAINTENANCE_BUSY');
            }
            $this->assertUnchanged($before, $dir, "$stop: only an empty STORE.LOCK", ['STORE.LOCK' => hash('sha256', '')]);
            $this->assertFileExists($dir . 'STORE.LOCK', "$stop: the stopped run left STORE.LOCK");
            [$status, $out, $stdout] = $this->tool($this->args($dir), $this->key());
            $this->assertSame(0, $status, "$stop, run again: $stdout");
            $this->assertMigrated($dir, "$stop, run again");
            $run = $out['migrated']['run'];
            \noneDB::clearStaticCache(); // assertMigrated() read it here: Windows moves no directory with an open file in it
            [$status, $out, $stdout] = $this->tool(["--dir=$dir", '--backup-dir=' . $this->testDbDir . 'backup/', "--restore=$run"], $this->key());
            $this->assertSame(0, $status, "$stop, restore: $stdout");
            $this->assertSame($before, $this->snapshot($dir), "$stop: restored byte for byte, STORE.LOCK gone");
        }
    }

    /**
     * @test §11: restore needs the run's target key. With another key it changes nothing: it could neither see the
     * databases that key gained since the run nor take the right key out of the STORE record.
     */
    public function restoreNeedsTheRunsTargetKey(): void
    {
        $dir = $this->stage(self::FIXTURE);
        [$status, $out] = $this->tool($this->args($dir), $this->key());
        $this->assertSame(0, $status);
        $run = $out['migrated']['run'];
        $before = $this->snapshot($dir);
        $backup = $this->snapshot($this->testDbDir . 'backup/');
        [$status, $out] = $this->tool(["--dir=$dir", '--backup-dir=' . $this->testDbDir . 'backup/', "--restore=$run"], $this->key(), 'migration-test-another-target-key');
        $this->assertSame([5, 'FAILED'], [$status, $out['result']]);
        $this->assertStringContainsString('another key', $out['message']);
        $this->assertSame($before, $this->snapshot($dir));
        $this->assertSame($backup, $this->snapshot($this->testDbDir . 'backup/'));
        [$status] = $this->tool(["--dir=$dir", '--backup-dir=' . $this->testDbDir . 'backup/', "--restore=$run"], $this->key());
        $this->assertSame(0, $status, 'with its key');
    }

    /** @test §11: a key that gained a database after the run is not restored: the database would lose its key */
    public function restoreRefusesWhenTheKeyGainedADatabase(): void
    {
        $dir = $this->stage(self::FIXTURE);
        [, $out] = $this->tool($this->args($dir), $this->key());
        $run = $out['migrated']['run'];
        $this->assertSame(['n' => 1], $this->v4($dir, self::TARGET, ['autoCreateDB' => true])->insert('later', ['n' => 1]));
        $before = $this->snapshot($dir);
        [$status, $out] = $this->tool(["--dir=$dir", '--backup-dir=' . $this->testDbDir . 'backup/', "--restore=$run"], $this->key());
        $this->assertSame(5, $status);
        $this->assertStringContainsString('later', $out['message']);
        $this->assertSame($before, $this->snapshot($dir));
    }

    /** @test §3, §9: each key is migrated by its own run into the same store; files of the other key are untouched */
    public function twoKeysMigrateIntoOneStore(): void
    {
        $dir = $this->stage('3.2-two-keys');
        $first = $this->fixture('3.2-two-keys')['sourceKey'];
        $second = 'migration-fixture-other-key-4b2d';
        [$status, $out] = $this->tool($this->args($dir), $first);
        $this->assertSame(0, $status);
        $this->assertSame(['users'], array_keys($out['migrated']['databases']));
        // The other key's files are still there: the engine answers for existing databases, never "doesn't exist"
        $this->assertSame([0], array_keys($this->v4Records($this->v4($dir), 'users')));
        try {
            $this->v4($dir, self::TARGET, ['autoCreateDB' => true])->insert('new', ['n' => 1]);
            $this->fail('a database was created next to files of another key');
        } catch (\RuntimeException $e) {
            $this->assertSame(\noneDB::UNSUPPORTED_FORMAT, $e->getCode());
        }
        [$status, $out] = $this->tool($this->args($dir), $second, 'migration-test-second-target-key');
        $this->assertSame(0, $status);
        $this->assertSame(['orders'], array_keys($out['migrated']['databases']));
        $this->assertSame([0], array_keys($this->v4Records($this->v4($dir, 'migration-test-second-target-key'), 'orders')));
        $this->assertSame([0], array_keys($this->v4Records($this->v4($dir), 'users')));
        $this->assertSame(['n' => 1], $this->v4($dir, self::TARGET, ['autoCreateDB' => true])->insert('new', ['n' => 1]), 'no foreign entry is left');
    }

    /**
     * @test §9: in a store another key's run made, a --db subset publishes its database but does not register its
     * key while another database of the key is still in the old format; the run that completes the key does
     */
    public function aSubsetRunIntoAnExistingStoreLeavesItsKeyUnregistered(): void
    {
        $dir = $this->stage(self::FIXTURE);
        foreach (glob(self::fixturesDir() . '/3.2-two-keys/db/04b1755905ee958a7ffe-orders*') as $file) { // the other key's orders
            copy($file, $dir . basename($file));
        }
        [$status, $out] = $this->tool($this->args($dir), 'migration-fixture-other-key-4b2d', 'migration-test-second-target-key');
        $this->assertSame([0, true], [$status, $out['migrated']['keyRegistered']], 'precondition: the other key made the store');
        [$status, $out] = $this->tool($this->args($dir, ['--db=users']), $this->key());
        $this->assertSame(0, $status);
        $this->assertSame(['users'], array_keys($out['migrated']['databases']));
        $this->assertFalse($out['migrated']['keyRegistered'], 'orders of the key is still in the old format');
        try {
            $this->v4($dir)->find('users', 0);
            $this->fail('the key was registered');
        } catch (\RuntimeException $e) {
            $this->assertSame(\noneDB::KEY_NOT_REGISTERED, $e->getCode());
        }
        [$status, $out] = $this->tool($this->args($dir), $this->key());
        $this->assertSame([0, true], [$status, $out['migrated']['keyRegistered']]);
        $this->assertSame(['orders'], array_keys($out['migrated']['databases']));
        $data = $this->fixture(self::FIXTURE);
        $db = $this->v4($dir);
        foreach (['users', 'orders'] as $name) {
            $this->assertSame($this->oracleRecords($data, '3.2', $name), $this->v4Records($db, $name), $name);
            $this->assertTrue($db->checkIntegrity($name)['ok'], "$name integrity");
        }
        $this->assertCount(3, glob($dir . '.nonedb-retained/*/manifest.json'), 'each run retained the sources it migrated');
        $this->assertSame([0], array_keys($this->v4Records($this->v4($dir, 'migration-test-second-target-key'), 'orders')), 'the other key\'s database');
    }

    /** @test §9: a run with nothing left to migrate registers the key a partial run left unregistered */
    public function aRunWithNothingLeftRegistersTheKey(): void
    {
        $dir = $this->stage('2.2-empty-name');
        $key = $this->fixture('2.2-empty-name')['sourceKey'];
        [$status, $out] = $this->tool($this->args($dir, ['--db=users']), $key);
        $this->assertSame(0, $status);
        $this->assertFalse($out['migrated']['keyRegistered']);
        unlink(glob($dir . '*-.nonedb')[0]); // the database with no noneDB 4 name, moved away by its owner
        [$status, $out] = $this->tool($this->args($dir), $key);
        $this->assertSame(0, $status);
        $this->assertTrue($out['migrated']['keyRegistered']);
        $this->assertSame([0], array_keys($this->v4Records($this->v4($dir), 'users')));
        [$status, $out] = $this->tool($this->args($dir), $key);
        $this->assertSame([0, null], [$status, $out['migrated']['run']], 'nothing left to do');
    }

    /** @test M1 scenario 29: a directory the run cannot write is refused, naming what failed, nothing changes; the next run succeeds */
    public function aDirectoryThatCannotBeWrittenIsRefused(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('a directory that cannot be written is not made with chmod on Windows or for root');
        }
        $dir = $this->stage(self::FIXTURE);
        $before = $this->snapshot($dir);
        chmod($dir, 0555);
        try {
            [$status, $out] = $this->tool($this->args($dir), $this->key());
        } finally {
            chmod($dir, 0777);
        }
        $this->assertSame([5, 'FAILED'], [$status, $out['result']]);
        $this->assertStringContainsString('STORE.LOCK', $out['message']);
        $this->assertSame($before, $this->snapshot($dir));
        [$status] = $this->tool($this->args($dir), $this->key());
        $this->assertSame(0, $status);
        $this->assertMigrated($dir, 'after the permissions were fixed');
    }

    /** @test §9: a run that finds no old file of its key registers nothing and creates nothing (a mistyped key) */
    public function aMistypedKeyIsNeverRegistered(): void
    {
        $dir = $this->stage('3.2-two-keys');
        $before = $this->snapshot($dir);
        [$status, $out] = $this->tool($this->args($dir), 'a-key-these-files-never-had');
        $this->assertSame([0, null, false], [$status, $out['migrated']['run'], $out['migrated']['keyRegistered']]);
        $this->assertSame($before, $this->snapshot($dir));
    }

    /** @test §7.1: a run stops before writing anything when the backup and the staged databases don't fit */
    public function notEnoughSpaceStopsTheRunFirst(): void
    {
        $dir = $this->stage(self::FIXTURE);
        $before = $this->snapshot($dir);
        putenv('NONEDB_MIGRATE_FREE=1000');
        [$status, $out] = $this->tool($this->args($dir), $this->key());
        putenv('NONEDB_MIGRATE_FREE');
        $this->assertSame([5, 'FAILED'], [$status, $out['result']]);
        $this->assertStringContainsString('not enough free space', $out['message']);
        $this->assertSame($before, $this->snapshot($dir));
        $this->assertDirectoryDoesNotExist($this->testDbDir . 'backup');
    }

    /** @test composer.json "bin": the tool runs through the PHP proxy Composer writes to vendor/bin (it includes the file) */
    public function theToolRunsThroughComposersBinProxy(): void
    {
        $proxy = $this->testDbDir . 'proxy.php';
        file_put_contents($proxy, "<?php\nnamespace Composer;\n\$GLOBALS['_composer_bin_dir'] = __DIR__;\nreturn include " . var_export(NONEDB_TEST_TOOL, true) . ";\n");
        $process = proc_open([PHP_BINARY, $proxy, '--json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $err = stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(64, proc_close($process), 'the usage of the tool');
        $this->assertStringContainsString('usage: NONEDB_SECRET=', $err);
    }

    /** @test §2, §13: usage errors (64) and refused settings (5) change nothing */
    public function optionErrorsChangeNothing(): void
    {
        $dir = $this->stage(self::FIXTURE);
        $before = $this->snapshot($dir);
        $usage = [
            ["--dir=$dir"],                                                   // neither --dry-run nor --backup-dir/--confirm-stopped
            ["--dir=$dir", '--backup-dir=/tmp/x'],                            // no --confirm-stopped
            ["--dir=$dir", '--dry-run', '--abort'],                           // two modes
            ["--dir=$dir", '--dry-run', '--flushing=maybe'],
            ["--dir=$dir", '--dry-run', '--shard-size=0'],
            ["--dir=$dir", '--dry-run', '--unknown'],
            ["--dir=$dir", '--restore=x'],                                    // no --backup-dir
        ];
        foreach ($usage as $args) {
            $this->assertSame(64, $this->tool($args, $this->key())[0], json_encode($args));
        }
        $this->assertSame(5, $this->tool(["--dir=$dir", "--backup-dir={$dir}inside", '--confirm-stopped'], $this->key())[0], 'backup inside');
        $this->assertSame(5, $this->tool($this->args($dir), $this->key(), 'nonedb_123')[0], 'a public target key');
        $this->assertSame(5, $this->tool($this->args($dir), $this->key(), null)[0], 'no key');
        $this->assertSame($before, $this->snapshot($dir));
    }
}
