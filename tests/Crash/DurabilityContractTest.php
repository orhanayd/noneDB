<?php

namespace noneDB\Tests\Crash;

use noneDB\Tests\Support\Testing;
use noneDB\Tests\RegressionTestCase;

/**
 * docs/DURABILITY.md §2–§4, row by row: every sync of §3 is made to fail through the sync hook, and the
 * test checks the outcome the document gives (code, getDurability()['lastOperation']) and the record set
 * after a restart (no cache of this process, a new instance) against the records the test itself wrote.
 *
 * The rules are checked on every platform with the platform set to Linux (durable mode's sync order is the
 * same everywhere); the real fsync paths run in DurabilityLinuxTest on Linux.
 */
class DurabilityContractTest extends RegressionTestCase
{
    /** @var array[] syncs seen by the hook: [kind, path relative to dbDir, succeeded] */
    private $calls = [];

    /** @var string[] directories made outside dbDir */
    private $outside = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('durable mode needs directory syncs');
        }
        Testing::set('osFamily', 'Linux');
    }

    protected function tearDown(): void
    {
        foreach (['osFamily', 'syncFn', 'flushFn', 'writeFn', 'renameFn'] as $hook) {
            Testing::set($hook, null);
        }
        foreach ($this->outside as $dir) {
            $this->removeDirectoryTree($dir);
        }
        $this->outside = [];
        parent::tearDown();
    }

    /** A path outside dbDir (not created), resolved as backup() resolves it */
    private function outsideDir(): string
    {
        $dir = realpath(sys_get_temp_dir()) . '/nonedb_backup_' . bin2hex(random_bytes(6));
        $this->outside[] = $dir;
        return $dir;
    }

    // ------------------------------------------------------------------ helpers

    private function durable(array $config = []): \noneDB
    {
        return $this->db(array_merge(['durability' => 'durable'], $config));
    }

    private function rel(string $path): string
    {
        $dir = rtrim($this->testDbDir, '/');
        if ($path === $dir) {
            return '.';
        }
        return strncmp($path, $dir . '/', strlen($dir) + 1) === 0 ? substr($path, strlen($dir) + 1) : $path;
    }

    /**
     * Sync hook: $fail(kind, relative path, absolute path) says whether this sync fails; the others succeed
     * without touching the disk (the order and the outcome are what is tested here)
     */
    private function syncs(callable $fail): void
    {
        $this->calls = [];
        Testing::set('syncFn', function ($handle, string $kind, $path) use ($fail) {
            $ok = !$fail($kind, $this->rel((string)$path), (string)$path);
            $this->calls[] = [$kind, $this->rel((string)$path), $ok];
            return $ok;
        });
    }

    /** Fail the $n-th sync of $kind whose relative path matches $pattern (fnmatch) */
    private function failNth(string $kind, string $pattern, int $n = 1): void
    {
        $seen = 0;
        $this->syncs(function ($k, $rel) use ($kind, $pattern, $n, &$seen) {
            return $k === $kind && fnmatch($pattern, $rel) && ++$seen === $n;
        });
    }

    /** Record the syncs without failing any */
    private function recordSyncs(): void
    {
        $this->syncs(function () { return false; });
    }

    private function failing(int $code, callable $fn): \RuntimeException
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            $this->assertSame($code, $e->getCode(), get_class($e) . ': ' . $e->getMessage());
            return $e;
        }
        $this->fail('expected a RuntimeException with code ' . $code);
    }

    private function last(\noneDB $db): array
    {
        $last = $db->getDurability()['lastOperation'];
        $this->assertIsArray($last);
        return $last;
    }

    /**
     * Records of a database after a restart (static caches cleared, hooks off, a new instance);
     * checkIntegrity() must find nothing to report unless $integrity is false
     */
    private function afterRestart(string $name, array $config = [], bool $integrity = true): array
    {
        Testing::set('syncFn', null);
        Testing::set('flushFn', null);
        Testing::set('writeFn', null);
        Testing::set('renameFn', null);
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $records = $db->find($name, 0);
        if ($integrity) {
            $report = $db->checkIntegrity($name);
            $this->assertTrue($report['ok'], json_encode($report));
        }
        return $this->plain($records);
    }

    private function bytes(string $file): ?string
    {
        clearstatcache(true, $file);
        return is_file($file) ? (string)file_get_contents($file) : null;
    }

    private function lockRecordHas(string $path, string $token): bool
    {
        $raw = (string)@file_get_contents($path);
        return in_array($token, explode(' ', trim($raw)), true) || strpos($raw, ' ' . $token) !== false;
    }

    // ------------------------------------------------------------------ §3 row 1: append to an existing file

    /** @test a failed file sync of an append: rolled back, DURABILITY_FAILED, nothing applied */
    public function anAppendWhoseSyncFailsIsRolledBack(): void
    {
        foreach (['single' => ['shardingEnabled' => false], 'sharded' => ['shardSize' => 2], 'buffered' => ['shardingEnabled' => false, 'bufferEnabled' => true]] as $case => $config) {
            $this->cleanTestDirectory();
            $db = $this->durable($config);
            $model = [['v' => 'a'], ['v' => 'b'], ['v' => 'c']];
            $db->insert('ap', $model);
            $file = $case === 'sharded' ? '*/segments/s1.jsonl' : ($case === 'buffered' ? '*/buffer/pending.jsonl' : '*/segments/main.jsonl');
            $data = glob($this->dbPath('ap', 'gen') . ($case === 'buffered' ? 'buffer/pending.jsonl' : 'segments/*.jsonl'));
            $before = array_map([$this, 'bytes'], $data);

            $this->failNth('file', $file);
            $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->insert('ap', ['v' => 'd']); });
            $last = $this->last($db);
            $this->assertSame(['insert', 'ap', 'rolled_back'], [$last['op'], $last['db'], $last['outcome']], $case);
            $this->assertContains('file', $last['required'], $case);
            $this->assertNotContains('file', $last['completed'], $case);
            $this->assertSame($before, array_map([$this, 'bytes'], $data), "$case: the appended bytes were cut back");
            $this->assertSame($model, $this->afterRestart('ap', $config), $case);

            // A failure is never carried over: the next write commits with every required sync done
            $db = $this->durable($config);
            $db->insert('ap', ['v' => 'e']);
            $last = $this->last($db);
            $this->assertSame('committed', $last['outcome'], $case);
            $this->assertSame($last['required'], $last['completed'], $case);
            $model[] = ['v' => 'e'];
            $this->assertSame($model, $this->afterRestart('ap', $config), $case);
        }
    }

    /** @test §4: the cut of a failed append is synced; when that sync fails the outcome is unknown and "J" is set */
    public function anUndoWhoseSyncFailsIsUnknown(): void
    {
        $db = $this->durable(['shardingEnabled' => false]);
        $db->insert('un', [['v' => 1], ['v' => 2]]);
        // 1st sync of the data file: the append (fails); 2nd: its cut (fails too)
        $this->syncs(function ($kind, $rel) { return $kind === 'file' && fnmatch('*/segments/main.jsonl', $rel); });
        $this->failing(\noneDB::OUTCOME_UNKNOWN, function () use ($db) { $db->insert('un', ['v' => 3]); });
        $this->assertSame('unknown', $this->last($db)['outcome']);
        $this->assertSame(2, count(array_filter($this->calls, function ($c) { return $c[0] === 'file' && fnmatch('*/segments/main.jsonl', $c[1]); })),
            'the append and its cut were each synced once');
        $this->assertContains('J', $this->lockState('un')['flags'], 'the next session recovers the database');
        // The cut itself happened in this process: the record is not there; the next access recovers
        $this->assertSame([['v' => 1], ['v' => 2]], $this->afterRestart('un'));
        $this->assertNotContains('J', $this->lockState('un')['flags']);
    }

    /** @test §4: an append whose cut fails (any mode) is unknown, never a plain failure */
    public function anAppendThatCannotBeCutBackIsUnknown(): void
    {
        foreach (['performance', 'durable'] as $mode) {
            $this->cleanTestDirectory();
            $db = $this->db(['durability' => $mode, 'shardingEnabled' => false]);
            $db->insert('cut', ['v' => 1]);
            Testing::set('flushFn', function () { return false; });
            $this->setFaultHook(function ($point) {
                if ($point === 'append:undo' || $point === 'rollback:truncate') {
                    throw new \RuntimeException('disk gone');
                }
            });
            $this->failing(\noneDB::OUTCOME_UNKNOWN, function () use ($db) { $db->insert('cut', ['v' => 2]); });
            $this->setFaultHook(null);
            $this->assertSame('unknown', $this->last($db)['outcome'], $mode);
            // Nothing was cut: the complete line is a record (roll forward), for every reader
            $this->assertSame([['v' => 1], ['v' => 2]], $this->afterRestart('cut'), $mode);
        }
    }

    // ------------------------------------------------------------------ §3 row 2: append that creates the file

    /** @test the directory sync of an append that created its file is part of the commit */
    public function aCreatingAppendWhoseDirectorySyncFailsIsRolledBack(): void
    {
        // A new buffer file
        $db = $this->durable(['shardingEnabled' => false, 'bufferEnabled' => true]);
        $db->createDB('cb');
        $this->failNth('dir', '*/buffer');
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->insert('cb', ['v' => 1]); });
        $last = $this->last($db);
        $this->assertSame('rolled_back', $last['outcome']);
        $this->assertSame(['file', 'dir'], $last['required']);
        $this->assertSame(['file'], $last['completed']);
        $this->assertSame('', (string)$this->bytes($this->dbPath('cb', 'buffer')), 'cut back to empty');
        $this->assertSame([], $this->afterRestart('cb', ['bufferEnabled' => true]));

        // A new shard file
        $this->cleanTestDirectory();
        $db = $this->durable(['shardSize' => 2]);
        $db->insert('cs', [['v' => 0], ['v' => 1]]);
        $this->failNth('dir', '*/segments');
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->insert('cs', ['v' => 2]); });
        $this->assertSame('rolled_back', $this->last($db)['outcome']);
        $this->assertSame('', (string)$this->bytes($this->dbPath('cs', 'data', 1)));
        $this->assertSame([['v' => 0], ['v' => 1]], $this->afterRestart('cs'));
        $db = $this->durable(['shardSize' => 2]);
        $db->insert('cs', ['v' => 2]);
        $this->assertSame([['v' => 0], ['v' => 1], ['v' => 2]], $this->afterRestart('cs'));
    }

    // ------------------------------------------------------------------ §3 row 3: state-record flags

    /** @test "b", "j", "c" and a new epoch are synced before the change they protect; a failure changes nothing */
    public function aFlagWhoseSyncFailsStopsTheWriteBeforeTheData(): void
    {
        // b: the first buffered insert after a flush sets it
        $db = $this->durable(['shardingEnabled' => false, 'bufferEnabled' => true]);
        $db->insert('fb', ['v' => 1]);
        $db->flush('fb');
        $this->assertNotContains('b', $this->lockState('fb')['flags']);
        $buffer = $this->bytes($this->dbPath('fb', 'buffer'));
        $this->syncs(function ($kind, $rel, $path) { return $kind === 'file' && fnmatch('*/LOCK', $rel) && $this->lockRecordHas($path, 'b'); });
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->insert('fb', ['v' => 2]); });
        $this->assertSame('rolled_back', $this->last($db)['outcome']);
        $this->assertSame($buffer, $this->bytes($this->dbPath('fb', 'buffer')), 'nothing appended to the buffer');
        $this->assertSame([['v' => 1]], $this->afterRestart('fb', ['bufferEnabled' => true]));

        // j: a sharded write
        $this->cleanTestDirectory();
        $db = $this->durable(['shardSize' => 2]);
        $db->insert('fj', [['v' => 0], ['v' => 1], ['v' => 2]]);
        $data = [$this->bytes($this->dbPath('fj', 'data', 0)), $this->bytes($this->dbPath('fj', 'data', 1))];
        $this->syncs(function ($kind, $rel, $path) { return $kind === 'file' && fnmatch('*/LOCK', $rel) && strpos((string)file_get_contents($path), ' j=') !== false; });
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->insert('fj', ['v' => 3]); });
        $this->assertSame('rolled_back', $this->last($db)['outcome']);
        $this->assertSame($data, [$this->bytes($this->dbPath('fj', 'data', 0)), $this->bytes($this->dbPath('fj', 'data', 1))]);
        $this->assertSame([['v' => 0], ['v' => 1], ['v' => 2]], $this->afterRestart('fj'));

        // c: creation
        $this->cleanTestDirectory();
        $db = $this->durable();
        $db->createDB('other'); // the store exists
        $this->syncs(function ($kind, $rel, $path) { return $kind === 'file' && fnmatch('*/LOCK', $rel) && $this->lockRecordHas($path, 'c'); });
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->createDB('fc'); });
        $this->assertSame(['createDB', 'rolled_back'], [$this->last($db)['op'], $this->last($db)['outcome']]);
        $this->assertFileDoesNotExist($this->dbPath('fc', 'format'));
        Testing::set('syncFn', null);
        \noneDB::clearStaticCache();
        $this->assertSame(['other'], $this->db()->getDBs(false), 'the half-created database is absent');
        $this->assertSame([], $this->db()->find('fc', 0));

        // epoch: compaction replaces the data file only after its new epoch is durable
        $this->cleanTestDirectory();
        $db = $this->durable(['shardingEnabled' => false, 'autoCompactThreshold' => 1000]);
        $db->insert('fe', [['v' => 1], ['v' => 2]]);
        $db->update('fe', [['v' => 1], ['set' => ['w' => 1]]]);
        $data = $this->bytes($this->dbPath('fe', 'data'));
        $epoch = $this->lockState('fe')['epoch'];
        $this->syncs(function ($kind, $rel, $path) use ($epoch) { return $kind === 'file' && fnmatch('*/LOCK', $rel) && strpos((string)file_get_contents($path), ':' . $epoch) === false; });
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->compact('fe'); });
        $this->assertSame(['compact', 'rolled_back'], [$this->last($db)['op'], $this->last($db)['outcome']]);
        $this->assertSame($data, $this->bytes($this->dbPath('fe', 'data')), 'the data file was not replaced');
        $this->assertSame([['v' => 1, 'w' => 1], ['v' => 2]], $this->afterRestart('fe'));
    }

    /** @test "d": its sync failing withdraws the drop (durably); if that fails too the outcome is unknown */
    public function aDropWhoseFlagCannotBeSyncedLeavesTheDatabase(): void
    {
        $db = $this->durable(['shardingEnabled' => false]);
        $db->insert('fd', [['v' => 1], ['v' => 2]]);
        // Only the record with "d" fails: the withdrawal (without "d") is synced
        $this->syncs(function ($kind, $rel, $path) { return $kind === 'file' && fnmatch('*/LOCK', $rel) && $this->lockRecordHas($path, 'd'); });
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->dropDB('fd'); });
        $this->assertSame(['dropDB', 'rolled_back'], [$this->last($db)['op'], $this->last($db)['outcome']]);
        $this->assertNotContains('d', $this->lockState('fd')['flags']);
        $this->assertFileExists($this->dbPath('fd', 'format'));
        $this->assertSame([['v' => 1], ['v' => 2]], $this->afterRestart('fd'));

        // Every record sync fails: the drop flag can't be withdrawn durably
        $db = $this->durable(['shardingEnabled' => false]);
        $this->syncs(function ($kind, $rel) { return $kind === 'file' && fnmatch('*/LOCK', $rel); });
        $this->failing(\noneDB::OUTCOME_UNKNOWN, function () use ($db) { $db->dropDB('fd'); });
        $this->assertSame('unknown', $this->last($db)['outcome']);
        $this->assertNotContains('d', $this->lockState('fd')['flags'], 'withdrawn in this process');
        $this->assertSame([['v' => 1], ['v' => 2]], $this->afterRestart('fd'));
    }

    /** @test "g": a generation switch whose intent can't be synced keeps the old generation */
    public function aGenerationSwitchWhoseIntentCannotBeSyncedKeepsTheOldGeneration(): void
    {
        $db = $this->durable(['shardingEnabled' => false]);
        $model = [['v' => 1], ['v' => 2], ['v' => 3]];
        $db->insert('fg', $model);
        $this->syncs(function ($kind, $rel, $path) { return $kind === 'file' && fnmatch('*/LOCK', $rel) && strpos((string)file_get_contents($path), ' g=') !== false; });
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->convertToSharded('fg'); });
        $this->assertSame(['convertToSharded', 'rolled_back'], [$this->last($db)['op'], $this->last($db)['outcome']]);
        $this->assertSame($model, $this->afterRestart('fg'));
        $info = $this->db()->getShardInfo('fg');
        $this->assertFalse($info['sharded']);
        $this->assertSame(1, json_decode((string)file_get_contents($this->dbPath('fg', 'current')), true)['generation']);
        $this->assertDirectoryDoesNotExist($this->dbPath('fg', 'home') . 'generations/2', 'the prepared generation was removed by recovery');
        $this->assertTrue($this->durable()->convertToSharded('fg')['success']);
        $this->assertSame($model, $this->afterRestart('fg'));
    }

    // ------------------------------------------------------------------ §3 row 4: atomic replacement

    /** @test a failed sync of the temp file: nothing replaced; of the directory after the rename: unknown */
    public function anAtomicReplacementFailsBeforeOrAfterItsRename(): void
    {
        // shards.json (the key reservation of a sharded write)
        $db = $this->durable(['shardSize' => 2]);
        $model = [['v' => 0], ['v' => 1], ['v' => 2]];
        $db->insert('ar', $model);
        $shards = $this->bytes($this->dbPath('ar', 'shards'));
        $this->failNth('file', '*/shards.json');
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->insert('ar', ['v' => 3]); });
        $this->assertSame('rolled_back', $this->last($db)['outcome']);
        $this->assertSame($shards, $this->bytes($this->dbPath('ar', 'shards')), 'not replaced');
        $this->assertSame([], glob($this->dbPath('ar', 'gen') . '*.tmp*'), 'no temp file left');
        $this->assertSame($model, $this->afterRestart('ar'));

        $db = $this->durable(['shardSize' => 2]);
        $genDir = $this->rel(rtrim($this->dbPath('ar', 'gen'), '/'));
        $this->syncs(function ($kind, $rel) use ($genDir) { return $kind === 'dir' && $rel === $genDir; });
        $this->failing(\noneDB::OUTCOME_UNKNOWN, function () use ($db) { $db->insert('ar', ['v' => 3]); });
        $this->assertSame('unknown', $this->last($db)['outcome']);
        $this->assertContains('J', $this->lockState('ar')['flags']);
        // The reservation was replaced, the data append never ran
        $this->assertSame($model, $this->afterRestart('ar'));

        // FORMAT: the commit point of a creation
        $this->cleanTestDirectory();
        $db = $this->durable();
        $db->createDB('other');
        $this->failNth('file', '*/FORMAT');
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->createDB('af'); });
        Testing::set('syncFn', null);
        \noneDB::clearStaticCache();
        $this->assertSame(['other'], $this->db()->getDBs(false));

        $db = $this->durable();
        $this->syncs(function ($kind, $rel, $path) { return $kind === 'dir' && fnmatch('*.ndb4', $rel) && is_file($path . '/FORMAT') && strpos($rel, $this->locatorOf('af')) === 0; });
        $this->failing(\noneDB::OUTCOME_UNKNOWN, function () use ($db) { $db->createDB('af'); });
        $this->assertSame(['createDB', 'unknown'], [$this->last($db)['op'], $this->last($db)['outcome']]);
        Testing::set('syncFn', null);
        \noneDB::clearStaticCache();
        $dbs = $this->db()->getDBs(false);
        sort($dbs);
        $this->assertSame(['af', 'other'], $dbs, 'in this process FORMAT is there: the creation completes');
        $this->assertSame([], $this->db()->find('af', 0));
        $this->assertNotContains('c', $this->lockState('af')['flags'], 'its next access finished the creation');

        // CURRENT: the commit point of a generation switch
        $this->cleanTestDirectory();
        $db = $this->durable(['shardingEnabled' => false]);
        $model = [['v' => 1], ['v' => 2]];
        $db->insert('ac', $model);
        $this->syncs(function ($kind, $rel, $path) {
            return $kind === 'dir' && fnmatch('*.ndb4', $rel) && is_file($path . '/CURRENT')
                && (json_decode((string)file_get_contents($path . '/CURRENT'), true)['generation'] ?? 0) === 2;
        });
        $this->failing(\noneDB::OUTCOME_UNKNOWN, function () use ($db) { $db->convertToSharded('ac'); });
        $this->assertSame('unknown', $this->last($db)['outcome']);
        $this->assertSame($model, $this->afterRestart('ac'));
        $this->assertTrue($this->db()->getShardInfo('ac')['sharded'], 'CURRENT names generation 2 in this process');
        $this->assertDirectoryDoesNotExist($this->dbPath('ac', 'home') . 'generations/1');

        // indexes.json: createFieldIndex() commits with it
        $this->cleanTestDirectory();
        $db = $this->durable(['shardingEnabled' => false]);
        $db->insert('ai', [['v' => 1], ['v' => 2]]);
        $this->failNth('file', '*/indexes.json');
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->createFieldIndex('ai', 'v'); });
        $this->assertSame(['createFieldIndex', 'rolled_back'], [$this->last($db)['op'], $this->last($db)['outcome']]);
        $this->assertSame([['v' => 1], ['v' => 2]], $this->afterRestart('ai'));
        $this->assertFalse($this->db()->hasFieldIndex('ai', 'v'));
    }

    /** @test §4: a replacement whose rename fails leaves the target and no temp file; the outcome is rolled back */
    public function aReplacementWhoseRenameFailsChangesNothing(): void
    {
        $db = $this->durable(['shardSize' => 2]);
        $model = [['v' => 0], ['v' => 1], ['v' => 2]];
        $db->insert('rn', $model);
        $shards = $this->bytes($this->dbPath('rn', 'shards'));
        Testing::set('renameFn', function ($from, $to) { return substr($to, -12) === '/shards.json' ? false : rename($from, $to); });
        try {
            $db->insert('rn', ['v' => 3]);
            $this->fail('must throw');
        } catch (\RuntimeException $e) {
            $this->assertNotContains($e->getCode(), [\noneDB::OUTCOME_UNKNOWN, \noneDB::DURABILITY_FAILED], 'the original error');
        }
        $this->assertSame('rolled_back', $this->last($db)['outcome']);
        $this->assertSame($shards, $this->bytes($this->dbPath('rn', 'shards')));
        $this->assertSame([], glob($this->dbPath('rn', 'gen') . '*.tmp*'));
        $this->assertSame($model, $this->afterRestart('rn'));
    }

    // ------------------------------------------------------------------ §3 rows 5–7: compaction, generation, create/drop

    /** @test compaction: temp file sync (both modes), directory sync after the rename (durable mode) */
    public function compactionFailsBeforeOrAfterTheReplacement(): void
    {
        foreach (['performance', 'durable'] as $mode) {
            $this->cleanTestDirectory();
            $config = ['shardingEnabled' => false, 'autoCompactThreshold' => 1000]; // garbage stays until compact()
            $db = $this->db(['durability' => $mode] + $config);
            $db->insert('cp', [['v' => 1], ['v' => 2], ['v' => 3]]);
            $db->update('cp', [['v' => 2], ['set' => ['w' => 2]]]);
            $db->delete('cp', ['v' => 3]);
            $model = [['v' => 1], ['v' => 2, 'w' => 2]];
            $data = $this->bytes($this->dbPath('cp', 'data'));

            $this->failNth('file', '*/segments/main.jsonl');
            $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->compact('cp'); });
            $this->assertSame(['compact', 'rolled_back'], [$this->last($db)['op'], $this->last($db)['outcome']], $mode);
            $this->assertSame($data, $this->bytes($this->dbPath('cp', 'data')), $mode);
            $this->assertSame([], glob($this->dbPath('cp', 'gen') . 'segments/*.tmp*'), $mode);
            $this->assertSame($model, $this->afterRestart('cp', $config), $mode);
            $this->assertSame($data, $this->bytes($this->dbPath('cp', 'data')), "$mode: still not compacted");

            $db = $this->db(['durability' => $mode] + $config);
            $this->failNth('dir', '*/segments');
            if ($mode === 'durable') {
                $this->failing(\noneDB::OUTCOME_UNKNOWN, function () use ($db) { $db->compact('cp'); });
                $this->assertSame('unknown', $this->last($db)['outcome']);
            } else {
                $db->compact('cp'); // performance mode makes no power-loss claim: the directory sync is best effort
                $this->assertSame('committed', $this->last($db)['outcome']);
            }
            $this->assertNotSame($data, $this->bytes($this->dbPath('cp', 'data')), "$mode: replaced in this process");
            $this->assertSame($model, $this->afterRestart('cp', $config), $mode);
        }
    }

    /** @test generation switch: a failed sync while the new generation is prepared keeps the old one */
    public function aGenerationThatCannotBePreparedIsDropped(): void
    {
        $db = $this->durable(['shardingEnabled' => false, 'shardSize' => 2]);
        $model = [['v' => 1], ['v' => 2], ['v' => 3]];
        $db->insert('gp', $model);
        $this->failNth('file', '*/generations/2/segments/s1.jsonl');
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->convertToSharded('gp'); });
        $this->assertSame(['convertToSharded', 'rolled_back'], [$this->last($db)['op'], $this->last($db)['outcome']]);
        $this->assertSame($model, $this->afterRestart('gp'));
        $this->assertFalse($this->db()->getShardInfo('gp')['sharded']);
        $this->assertNotContains('g', array_map(function ($f) { return $f[0]; }, $this->lockState('gp')['flags']));
        $this->assertTrue($this->durable()->convertToSharded('gp')['success']);
        $this->assertSame($model, $this->afterRestart('gp'));
        $this->assertTrue($this->db()->getShardInfo('gp')['sharded']);
    }

    /** @test creation: a failed sync before FORMAT leaves no database; drop: FORMAT's removal commits */
    public function creationAndDropCommitWithFormat(): void
    {
        $db = $this->durable();
        $db->createDB('keep');
        foreach (['*/generations/1/metadata', '*/CURRENT'] as $pattern) {
            $this->failNth('file', $pattern);
            $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->insert('cr', ['v' => 1]); });
            $this->assertSame(['insert', 'rolled_back'], [$this->last($db)['op'], $this->last($db)['outcome']], $pattern);
            Testing::set('syncFn', null);
            \noneDB::clearStaticCache();
            $this->assertSame(['keep'], $this->db()->getDBs(false), $pattern);
            $this->assertSame([], $this->db()->find('cr', 0), $pattern);
            $this->assertSame(['LOCK'], $this->dbFiles('cr'), "$pattern: the next access removed the half creation");
            $this->assertNotContains('c', $this->lockState('cr')['flags'], $pattern);
            $db = $this->durable();
        }

        $db->insert('dr', [['v' => 1]]);
        $this->syncs(function ($kind, $rel, $path) { return $kind === 'dir' && fnmatch('*.ndb4', $rel) && !is_file($path . '/FORMAT') && is_file($path . '/LOCK') && strpos($rel, $this->locatorOf('dr')) === 0; });
        $this->failing(\noneDB::OUTCOME_UNKNOWN, function () use ($db) { $db->dropDB('dr'); });
        $this->assertSame(['dropDB', 'unknown'], [$this->last($db)['op'], $this->last($db)['outcome']]);
        $this->assertContains('d', $this->lockState('dr')['flags'], 'the next session finishes the drop');
        Testing::set('syncFn', null);
        \noneDB::clearStaticCache();
        $this->assertSame([], $this->db()->find('dr', 0));
        $this->assertSame(['LOCK'], $this->dbFiles('dr'));
        $this->assertNotContains('d', $this->lockState('dr')['flags']);
    }

    // ------------------------------------------------------------------ §3 row 8: quarantine

    /** @test the quarantine copy is durable before the source is cut; a failed sync leaves the source as it was */
    public function aQuarantineThatCannotBeSyncedCutsNothing(): void
    {
        foreach (['file' => '*/recovery/q.tmp*', 'dir' => '*/recovery'] as $kind => $pattern) {
            $this->cleanTestDirectory();
            $db = $this->durable(['shardingEnabled' => false]);
            $db->insert('qt', [['v' => 1], ['v' => 2]]);
            $file = $this->dbPath('qt', 'data');
            file_put_contents($file, '{"v":3,"ke', FILE_APPEND); // a torn tail (a crashed append)
            $torn = $this->bytes($file);
            \noneDB::clearStaticCache();
            $db = $this->durable(['shardingEnabled' => false]);
            $this->failNth($kind, $pattern);
            $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->insert('qt', ['v' => 4]); });
            $this->assertSame('rolled_back', $this->last($db)['outcome'], $kind);
            $this->assertSame($torn, $this->bytes($file), "$kind: the torn tail is still there");
            $this->assertSame([['v' => 1], ['v' => 2]], $this->afterRestart('qt', [], false), $kind);
            $this->assertSame([['segment' => 'main', 'offset' => strlen($torn) - 10, 'length' => 10]], $this->db()->checkIntegrity('qt')['tornTail'], $kind);

            $db = $this->durable(['shardingEnabled' => false]);
            $db->insert('qt', ['v' => 4]);
            $this->assertSame([['v' => 1], ['v' => 2], ['v' => 4]], $this->afterRestart('qt'), $kind);
            $this->assertCount(1, glob($this->dbPath('qt', 'quarantine') . 'q.main.*.bin'), $kind);
        }
    }

    // ------------------------------------------------------------------ §3 row 9: maintenance

    /** @test the maintenance journal's COMMITTED record: a failed directory sync after its rename is unknown */
    public function aMaintenanceCommitThatCannotBeSyncedIsUnknown(): void
    {
        $this->db()->insert('mt', ['v' => 1]);
        $other = 'second_registered_key_0123';
        $this->syncs(function ($kind, $rel, $path) {
            return $kind === 'dir' && $rel === '.nonedb-maintenance' && is_file($path . '/journal.json')
                && strpos((string)file_get_contents($path . '/journal.json'), '"COMMITTED"') !== false;
        });
        $result = \noneDB::registerKey($this->testDbDir, self::SECRET, $other, ['durability' => 'durable']);
        $this->assertFalse($result['success']);
        $this->assertSame(\noneDB::OUTCOME_UNKNOWN, $result['code'], (string)$result['error']);
        Testing::set('syncFn', null);
        // The journal is open: every process is kept out until the maintenance is run again
        $code = null;
        try {
            $this->db()->find('mt', 0);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
        }
        $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $code);
        $this->assertTrue(\noneDB::registerKey($this->testDbDir, self::SECRET, $other, ['durability' => 'durable'])['success']);
        $this->assertSame([['v' => 1]], $this->afterRestart('mt'));
        $this->assertSame([], (new \noneDB(['secretKey' => $other, 'dbDir' => $this->testDbDir]))->find('mt', 0));
    }

    /** @test a rotation whose "m" can't be synced fails before its commit; abort restores everything */
    public function aRotationWhoseMarkCannotBeSyncedCanBeAborted(): void
    {
        $this->db()->insert('rt', [['v' => 1], ['v' => 2]]);
        $new = 'rotated_secret_key_456789';
        $this->syncs(function ($kind, $rel, $path) { return $kind === 'file' && fnmatch('*.ndb4/LOCK', $rel) && $this->lockRecordHas($path, 'm'); });
        $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, $new, ['durability' => 'durable']);
        $this->assertFalse($result['success']);
        $this->assertSame(\noneDB::DURABILITY_FAILED, $result['code'], (string)$result['error']);
        Testing::set('syncFn', null);
        $abort = \noneDB::migrateKey($this->testDbDir, self::SECRET, $new, ['abort' => true, 'durability' => 'durable']);
        $this->assertTrue($abort['success'], (string)$abort['error']);
        $this->assertSame([['v' => 1], ['v' => 2]], $this->afterRestart('rt'));
    }

    /** @test a rotation's renames are durable before its commit: when that sync fails, nothing is committed */
    public function aRotationWhoseRenamesCannotBeSyncedDoesNotCommit(): void
    {
        $new = 'rotated_secret_key_456789';
        foreach ([true, false] as $abort) {
            $this->cleanTestDirectory();
            $this->db()->insert('rr', [['v' => 1], ['v' => 2]]);
            $this->syncs(function ($kind, $rel) {
                $journal = (string)@file_get_contents($this->testDbDir . '.nonedb-maintenance/journal.json');
                return $kind === 'dir' && $rel === '.' && strpos($journal, '"STARTED"') !== false;
            });
            $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, $new, ['durability' => 'durable']);
            $this->assertFalse($result['success']);
            $this->assertSame(\noneDB::DURABILITY_FAILED, $result['code'], (string)$result['error']);
            $this->assertStringContainsString('"STARTED"', (string)file_get_contents($this->testDbDir . '.nonedb-maintenance/journal.json'), 'not committed');
            Testing::set('syncFn', null);
            $again = \noneDB::migrateKey($this->testDbDir, self::SECRET, $new, ['abort' => $abort, 'durability' => 'durable']);
            $this->assertTrue($again['success'], (string)$again['error']);
            \noneDB::clearStaticCache();
            $key = $abort ? self::SECRET : $new;
            $this->assertSame([['v' => 1], ['v' => 2]], $this->plain((new \noneDB(['secretKey' => $key, 'dbDir' => $this->testDbDir]))->find('rr', 0)),
                $abort ? 'aborted: the old key' : 'run again: finished');
        }
    }

    // ------------------------------------------------------------------ §4: the rollback scope

    /** @test a scope's rollback cuts are synced; a cut whose sync fails makes the outcome unknown */
    public function aRollbackWhoseCutCannotBeSyncedIsUnknown(): void
    {
        $db = $this->durable(['shardSize' => 2]);
        $db->insert('rs', [['v' => 0, 'g' => 1], ['v' => 1, 'g' => 1], ['v' => 2, 'g' => 1], ['v' => 3, 'g' => 1]]);
        $model = $this->plain($db->find('rs', 0));
        // One update over both shards: s0 appended and synced, s1's sync fails (cut, synced), then the
        // rollback cuts s0 and its sync fails
        $s0 = 0;
        $s1 = 0;
        $this->syncs(function ($kind, $rel) use (&$s0, &$s1) {
            if ($kind !== 'file') {
                return false;
            }
            if (fnmatch('*/segments/s0.jsonl', $rel)) {
                return ++$s0 === 2;
            }
            return fnmatch('*/segments/s1.jsonl', $rel) && ++$s1 === 1;
        });
        $this->failing(\noneDB::OUTCOME_UNKNOWN, function () use ($db) { $db->update('rs', [['g' => 1], ['set' => ['h' => 1]]]); });
        $this->assertSame([2, 2], [$s0, $s1], 's0: append + rollback cut; s1: append + its cut');
        $this->assertSame('unknown', $this->last($db)['outcome']);
        $this->assertContains('J', $this->lockState('rs')['flags']);
        $this->assertSame($model, $this->afterRestart('rs'));

        // The same with every cut synced: rolled back
        $db = $this->durable(['shardSize' => 2]);
        $this->failNth('file', '*/segments/s1.jsonl');
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db) { $db->update('rs', [['g' => 1], ['set' => ['h' => 1]]]); });
        $this->assertSame('rolled_back', $this->last($db)['outcome']);
        $this->assertSame($model, $this->afterRestart('rs'));
    }

    /** @test a failure that is not a sync keeps its own error, also in durable mode */
    public function otherFailuresKeepTheirError(): void
    {
        $db = $this->durable(['shardingEnabled' => false]);
        $db->insert('ot', ['v' => 1]);
        Testing::set('flushFn', function () { return false; });
        try {
            $db->insert('ot', ['v' => 2]);
            $this->fail('a failed fflush must fail the write');
        } catch (\RuntimeException $e) {
            $this->assertNotContains($e->getCode(), [\noneDB::OUTCOME_UNKNOWN, \noneDB::DURABILITY_FAILED]);
        }
        $this->assertSame('rolled_back', $this->last($db)['outcome']);
        $this->assertSame([['v' => 1]], $this->afterRestart('ot'));
    }

    // ------------------------------------------------------------------ what is synced

    /** @test derived files (checkpoints, field and spatial indexes) are never synced (§3) */
    public function derivedFilesAreNeverSynced(): void
    {
        $db = $this->durable(['shardSize' => 50]);
        $db->createFieldIndex('dv', 'g');
        $db->createSpatialIndex('dv', 'loc');
        $this->recordSyncs();
        for ($i = 0; $i < 6; $i++) {
            $batch = [];
            for ($j = 0; $j < 40; $j++) {
                $batch[] = ['g' => $j % 3, 'loc' => ['type' => 'Point', 'coordinates' => [$j / 10, $i / 10]]];
            }
            $db->insert('dv', $batch);
        }
        $db->find('dv', ['g' => 1]);
        $db->withinBBox('dv', 'loc', 0, 0, 1, 1);
        $paths = array_column($this->calls, 1);
        $this->assertNotEmpty($paths);
        foreach ($paths as $path) {
            $this->assertDoesNotMatchRegularExpression('/\.(kix|fx|gfx|rx|grx)$/', $path);
        }
        $this->assertNotEmpty(glob($this->dbPath('dv', 'gen') . 'segments/*.kix'), 'checkpoints were written');
    }

    // ------------------------------------------------------------------ §3: backup and restore

    /** @test backup: every copy and every directory is synced before the manifest; a failed sync leaves no backup */
    public function aBackupIsDurableBeforeItsManifest(): void
    {
        $db = $this->durable(['shardSize' => 4]);
        $db->insert('bk', array_map(function ($n) { return ['v' => $n]; }, range(0, 5)));
        $dir = $this->outsideDir();
        $this->recordSyncs();
        $db->backup('bk', $dir);
        $manifest = json_decode(file_get_contents("$dir/manifest.json"), true);
        $order = [];
        foreach ($this->calls as [$kind, $path]) {
            if (strncmp($path, $dir, strlen($dir)) === 0) {
                $order[] = [$kind, substr($path, strlen($dir))];
            }
        }
        $files = array_values(array_map(function ($c) { return $c[1]; }, array_filter($order, function ($c) { return $c[0] === 'file'; })));
        $this->assertEqualsCanonicalizing(array_map(function ($e) { return '/' . $e['path']; }, $manifest['files']), array_slice($files, 0, -1),
            'every copy is synced');
        $this->assertMatchesRegularExpression('#^/manifest\.json\.tmp[0-9a-f]{8}$#', end($files), 'the manifest last');
        $manifestAt = array_search(['file', end($files)], $order, true);
        $dirsBefore = array_map(function ($c) { return $c[1]; }, array_filter(array_slice($order, 0, $manifestAt), function ($c) { return $c[0] === 'dir'; }));
        foreach ($manifest['files'] as $entry) {
            $parent = dirname('/' . $entry['path']);
            $this->assertContains($parent === '/' ? '' : $parent, $dirsBefore, "{$entry['path']}: its directory synced before the manifest");
        }
        $this->assertSame(['dir', ''], end($order), 'the backup directory after the manifest\'s rename');
        $lastDirSync = count(array_filter($order, function ($c) { return $c === ['dir', '']; }));

        foreach ([['file', '/generations/*/segments/s1.jsonl', 1], ['dir', '/generations/*/segments', 1], ['file', '/manifest.json.tmp*', 1],
                  ['dir', '', $lastDirSync]] as [$kind, $pattern, $nth]) {
            $failed = $this->outsideDir();
            $seen = 0;
            $this->syncs(function ($k, $rel) use ($kind, $pattern, $nth, $failed, &$seen) {
                return $k === $kind && fnmatch($failed . $pattern, $rel) && ++$seen === $nth;
            });
            $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db, $failed) { $db->backup('bk', $failed); });
            $this->assertFileDoesNotExist($failed, "$kind $pattern #$nth: the backup is removed");
        }
    }

    /** @test restore: PREPARE's copies synced (a failure keeps the database), INTENT synced, CURRENT the commit point */
    public function aRestoreCommitsWithCurrentAndKeepsTheDatabaseWhenASyncFails(): void
    {
        $config = ['shardSize' => 4];
        $db = $this->durable($config);
        $db->insert('rs', array_map(function ($n) { return ['v' => $n]; }, range(0, 5)));
        $dir = $this->outsideDir();
        $db->backup('rs', $dir);
        $backup = $this->plain($db->find('rs', 0));
        $db->insert('rs', [['v' => 6], ['v' => 7]]);
        $model = $this->plain($db->find('rs', 0));
        $x = json_decode((string)file_get_contents($this->dbPath('rs', 'current')), true)['generation'] + 1;

        $this->failNth('file', "*/generations/$x/segments/s0.jsonl");
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db, $dir) { $db->restore($dir, 'rs'); });
        $this->assertSame(['restore', 'rolled_back'], [$this->last($db)['op'], $this->last($db)['outcome']]);
        $this->assertDirectoryDoesNotExist($this->dbPath('rs', 'home') . "generations/$x", 'a failed PREPARE removes X');
        $this->assertSame($model, $this->afterRestart('rs', $config));

        $db = $this->durable($config);
        $this->syncs(function ($kind, $rel, $path) { return $kind === 'file' && fnmatch('*/LOCK', $rel) && strpos((string)file_get_contents($path), ' g=') !== false; });
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db, $dir) { $db->restore($dir, 'rs'); });
        $this->assertSame(['restore', 'rolled_back'], [$this->last($db)['op'], $this->last($db)['outcome']]);
        $this->assertSame($model, $this->afterRestart('rs', $config));

        $db = $this->durable($config);
        $this->syncs(function ($kind, $rel, $path) use ($x) {
            return $kind === 'dir' && fnmatch('*.ndb4', $rel) && is_file($path . '/CURRENT')
                && (json_decode((string)file_get_contents($path . '/CURRENT'), true)['generation'] ?? 0) === $x;
        });
        $this->failing(\noneDB::OUTCOME_UNKNOWN, function () use ($db, $dir) { $db->restore($dir, 'rs'); });
        $this->assertSame(['restore', 'unknown'], [$this->last($db)['op'], $this->last($db)['outcome']]);
        $this->assertSame($backup, $this->afterRestart('rs', $config), 'CURRENT names X: restored');

        // Absent: a failure in PREPARE undoes the creation at once; FORMAT is the commit point
        $db = $this->durable($config);
        $this->assertTrue($db->dropDB('rs')['success']);
        $this->failNth('file', '*/generations/1/segments/s1.jsonl'); // s0 is copied already
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db, $dir) { $db->restore($dir, 'rs'); });
        $this->assertSame(['LOCK'], $this->dbFiles('rs'), 'nothing of the creation is left');
        $this->assertDirectoryDoesNotExist($this->dbPath('rs', 'home') . 'generations');
        $this->assertNotContains('c', $this->lockState('rs')['flags'], 'undone at once, not by the next access');
        $this->assertSame([], $this->afterRestart('rs', $config));
        $db = $this->durable($config);
        $this->failNth('file', '*/FORMAT');
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db, $dir) { $db->restore($dir, 'rs'); });
        $this->assertSame([], $this->afterRestart('rs', $config));
        $this->assertSame(['LOCK'], $this->dbFiles('rs'), 'the next access removed the half creation');

        // Damaged: CURRENT is the commit point
        $db = $this->durable($config);
        $db->restore($dir, 'rs');
        file_put_contents($this->dbPath('rs', 'current'), 'x');
        \noneDB::clearStaticCache();
        $db = $this->durable($config);
        $this->failNth('file', '*/CURRENT');
        $this->failing(\noneDB::DURABILITY_FAILED, function () use ($db, $dir) { $db->restore($dir, 'rs'); });
        Testing::set('syncFn', null);
        \noneDB::clearStaticCache();
        $this->failing(\noneDB::FORMAT_DAMAGED, function () use ($config) { $this->db($config)->find('rs', 0); });
        $db = $this->durable($config);
        $this->syncs(function ($kind, $rel, $path) { return $kind === 'dir' && fnmatch('*.ndb4', $rel) && is_file($path . '/CURRENT') && strpos($rel, $this->locatorOf('rs')) === 0; });
        $this->failing(\noneDB::OUTCOME_UNKNOWN, function () use ($db, $dir) { $db->restore($dir, 'rs'); });
        $this->assertSame($backup, $this->afterRestart('rs', $config, false), 'CURRENT names X: restored');
    }

    /** @test performance mode syncs only data-file replacements (and quarantine copies) */
    public function performanceModeSyncsOnlyReplacements(): void
    {
        $db = $this->db(['shardSize' => 3, 'autoCompactThreshold' => 1000]); // compaction replaces files: tested below
        $db->insert('pm', [['v' => 1], ['v' => 2], ['v' => 3], ['v' => 4]]); // sharded: a generation switch (replacements)
        $this->recordSyncs();
        $db->insert('pm', [['v' => 5], ['v' => 6], ['v' => 7]]); // and a new shard
        $db->update('pm', [['v' => 1], ['set' => ['w' => 1]]]);
        $db->delete('pm', ['v' => 2]);
        $db->createFieldIndex('pm', 'v');
        $buffered = $this->db(['bufferEnabled' => true, 'shardingEnabled' => false]);
        $buffered->insert('pb', ['v' => 1]);
        $buffered->flush('pb');
        $db->dropDB('pb');
        $this->assertSame([], $this->calls, 'no sync in performance mode');
        $this->assertSame('committed', $this->last($db)['outcome']);
        $this->assertSame([], $this->last($db)['required']);

        $single = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 1000]);
        $single->insert('pc', [['v' => 1], ['v' => 2]]);
        $single->update('pc', [['v' => 1], ['set' => ['w' => 1]]]);
        $this->assertSame([], $this->calls);
        $single->compact('pc');
        $this->assertSame([['file', $this->locatorOf('pc') . '.ndb4/generations/1/segments/main.jsonl', true],
            ['dir', $this->locatorOf('pc') . '.ndb4/generations/1/segments', true]], $this->calls,
            'the replaced data file and its directory');
        $this->assertSame([], $this->last($single)['required'], 'none required in performance mode');
    }
}
