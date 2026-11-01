<?php

namespace noneDB\Tests\Concurrency;

use noneDB\Tests\RegressionTestCase;

/**
 * Generation and epoch of the lock state record (STORAGE_FORMAT.md §4.2): "{gen}:{epoch}[ flags]".
 * The generation moves only when a database file is written; the epoch changes when a data file is
 * replaced or removed (compaction, generation switch, drop) and is new for every new lock file, so a cache
 * is never trusted across a recreated lock file even if its generation matches again.
 */
class LockEpochTest extends RegressionTestCase
{
    private function epoch(string $dbName): ?string
    {
        $first = explode(' ', trim((string)file_get_contents($this->dbPath($dbName, 'lock'))))[0];
        $parts = explode(':', $first, 2);
        return $parts[1] ?? null;
    }

    /** @test */
    public function sessionsThatWriteNothingKeepTheGeneration(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('noop', [['n' => 1], ['n' => 2]]);
        $gen = $this->lockState('noop')['gen'];

        $db->checkDB('noop');
        $this->assertSame(0, $db->update('noop', [['n' => 99], ['set' => ['n' => 100]]])['n']);
        $this->assertSame(0, $db->delete('noop', ['n' => 99])['n']);
        $db->flush('noop');
        $this->assertFalse($db->createDB('noop'));
        $db->find('noop', 0);
        $db->count('noop');
        $this->assertSame($gen, $this->lockState('noop')['gen'], 'nothing was written');

        $db->insert('noop', ['n' => 3]);
        $this->assertGreaterThan($gen, $this->lockState('noop')['gen']);
    }

    /** @test */
    public function writesWithoutTheExclusiveLockAreRefused(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('guard', ['n' => 1]);
        $file = $this->dbPath('guard', 'data');
        $unlink = function (string $file) {
            return $this->fsUnlink($file);
        };
        try {
            $unlink->call(\noneDB\Tests\Support\Testing::engine($db), $file);
            $this->fail('no lock: refused');
        } catch (\LogicException $e) {
        }
        $withLock = function (callable $fn) {
            return $this->withLock('guard', false, $fn);
        };
        try {
            $withLock->call(\noneDB\Tests\Support\Testing::engine($db), function () use ($unlink, $db, $file) {
                $unlink->call(\noneDB\Tests\Support\Testing::engine($db), $file);
            });
            $this->fail('shared lock: refused');
        } catch (\LogicException $e) {
        }
        $this->assertFileExists($file);
        $this->assertSame(1, $db->count('guard'));
    }

    /** @test */
    public function aRecreatedLockFileNeverValidatesAnOldCache(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('a file that is open keeps its name until it is closed: no new LOCK while this process has one');
        }
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('epo', [['n' => 0], ['n' => 1]]);
        $db->insert('epo', ['n' => 2]); // a generation the child can reach again however a new lock file starts
        $this->assertCount(3, $db->find('epo', 0)); // cached in this process
        $gen = $this->lockState('epo')['gen'];
        $this->assertGreaterThan(0, $gen);

        // Another process starts over with a new lock file and writes until the generation is the same again
        $this->assertTrue(unlink($this->dbPath('epo', 'lock')));
        $out = $this->runChild('
            $db = ' . $this->childDb(['shardingEnabled' => false]) . ';
            $lock = $argv[1];
            $gen = function () use ($lock) { return (int)explode(" ", trim((string)@file_get_contents($lock)))[0]; };
            for ($i = 0; $gen() < ' . ($gen - 1) . ' && $i < 50; $i++) {
                $db->insert("epo", ["n" => 100 + $i]);
            }
            $db->delete("epo", ["n" => 0]);
            echo $gen();
        ', [$this->dbPath('epo', 'lock')]);
        $this->assertSame((string)$gen, $out, 'the child reached the same generation');

        $ns = array_column($db->find('epo', 0), 'n');
        $this->assertNotContains(0, $ns, 'the cache from before the new lock file is not used');
        $this->assertContains(1, $ns);
    }

    /** @test */
    public function replacingADataFileChangesTheEpochAppendingDoesNot(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('rep', [['n' => 1], ['n' => 2], ['n' => 3]]);
        $epoch = $this->epoch('rep');
        $this->assertNotNull($epoch);
        $db->insert('rep', ['n' => 4]);
        $db->update('rep', [['n' => 4], ['set' => ['m' => 1]]]);
        $this->assertSame($epoch, $this->epoch('rep'), 'appends keep the epoch');
        $db->compact('rep');
        $this->assertNotSame($epoch, $this->epoch('rep'), 'compaction replaced the data file');
        $epoch = $this->epoch('rep');
        $this->assertSame(4, $db->count('rep'));
        $db->dropDB('rep');
        $this->assertNotSame($epoch, $this->epoch('rep'), 'drop removed the data files');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string)$this->epoch('rep'));
    }

    /**
     * @test a new epoch comes before a data file is replaced or removed (STORAGE_FORMAT.md §5.2 INTENT for the
     * change to the sharded layout, §6.2 for compaction, §8.2 for a drop) and for every new lock record (§4.2)
     */
    public function theSwitchToShardsShardRemovalAndNewLockFilesChangeTheEpoch(): void
    {
        $db = $this->db(['shardSize' => 3, 'shardingEnabled' => false]);
        $db->insert('mig', array_map(function ($n) { return ['n' => $n]; }, range(0, 7)));
        $epoch = $this->epoch('mig');
        $this->assertSame(['success' => true, 'status' => 'converted'], $db->convertToSharded('mig'));
        $this->assertNotSame($epoch, $this->epoch('mig'), 'the main data file was replaced by shards (generation switch)');

        // Shard 1 (keys 3-5) emptied: compaction removes it
        foreach ([3, 4, 5] as $n) {
            $db->delete('mig', ['n' => $n]);
        }
        $epoch = $this->epoch('mig');
        $result = $db->compact('mig');
        $this->assertSame(2, $result['newShardCount']);
        $this->assertFileDoesNotExist($this->dbPath('mig', 'data', 1));
        $this->assertNotSame($epoch, $this->epoch('mig'));
        $this->assertSame([0, 1, 2, 6, 7], array_column($db->find('mig', 0), 'n'));

        // A new lock file (deleted while nothing runs: documented as harmless) gets a new epoch
        $epoch = $this->epoch('mig');
        $gen = $this->lockState('mig')['gen'];
        $this->removeLockFile('mig');
        $this->assertSame([0, 1, 2, 6, 7], array_column($this->db(['shardSize' => 3])->find('mig', 0), 'n'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string)$this->epoch('mig'));
        $this->assertNotSame($epoch, $this->epoch('mig'));
        $this->assertLessThan($gen, $this->lockState('mig')['gen'], 'the generation starts over: only the epoch tells the caches apart');
        $this->assertSame([], $this->lockState('mig')['flags']);
    }

    /**
     * @test a record whose epoch isn't 8 lowercase hex digits (or that has no ":") does not match the grammar:
     * it is damaged (STORAGE_FORMAT.md §4.2), treated as "J" by the next exclusive session (full recovery,
     * §7.3) with a new epoch; the journal is cleared at the end of that session
     */
    public function anInvalidEpochIsReplaced(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('bad', [['n' => 1], ['n' => 2]]);
        \noneDB::clearStaticCache();
        foreach (['5:xyz', '5:0123456789', '5:GHIJKLMN', '5:', '7'] as $record) {
            $this->writeLockState('bad', $record);
            $this->assertDoesNotMatchRegularExpression('/^[0-9]+:[0-9a-f]{8}$/', trim((string)file_get_contents($this->dbPath('bad', 'lock'))), $record . ': set up');
            \noneDB::clearStaticCache();
            $this->assertSame([1, 2], array_column($this->db(['shardingEnabled' => false])->find('bad', 0), 'n'), $record);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string)$this->epoch('bad'), $record);
            $this->assertMatchesRegularExpression('/^[0-9]+:[0-9a-f]{8} *$/', (string)file_get_contents($this->dbPath('bad', 'lock')), $record . ': a valid record, nothing pending');
            $this->assertSame([], $this->lockState('bad')['flags'], $record);
            $this->assertSame(1, $this->lockState('bad')['gen'], $record . ': a damaged record starts again at generation 1 (§4.2)');
        }
    }

    /** @test bumpEpoch() without the database's exclusive lock is a bug: LogicException, nothing written */
    public function anEpochChangeNeedsTheExclusiveLock(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('ep', ['n' => 1]);
        $record = (string)file_get_contents($this->dbPath('ep', 'lock'));
        $bump = function () {
            $this->bumpEpoch('ep');
        };
        $shared = function (callable $fn) {
            return $this->withLock('ep', false, $fn);
        };
        foreach (['no lock' => function () use ($bump, $db) { $bump->call(\noneDB\Tests\Support\Testing::engine($db)); },
                  'shared lock' => function () use ($bump, $shared, $db) { $shared->call(\noneDB\Tests\Support\Testing::engine($db), function () use ($bump, $db) { $bump->call(\noneDB\Tests\Support\Testing::engine($db)); }); }] as $label => $attempt) {
            try {
                $attempt();
                $this->fail($label . ': refused');
            } catch (\LogicException $e) {
                $this->assertStringContainsString("'ep'", $e->getMessage(), $label);
            }
        }
        $this->assertSame($record, file_get_contents($this->dbPath('ep', 'lock')));
    }

    /**
     * @test another generation at lock time with the same epoch: the key indexes are kept and only the new
     * lines are replayed; another epoch: every cache of the database is dropped (STORAGE_FORMAT.md §4.2) and
     * everything is loaded again. Results are the other process's in both cases.
     */
    public function cachesAreRefreshedOnAnotherGenerationAndDroppedOnAnotherEpoch(): void
    {
        $config = ['shardingEnabled' => false];
        $db = $this->db($config);
        $db->createSpatialIndex('xp', 'loc');
        $db->insert('xp', [['n' => 1, 'loc' => ['type' => 'Point', 'coordinates' => [1, 1]]], ['n' => 2]]);
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame([1, 2], array_column($db->find('xp', 0), 'n'));
        $this->assertSame([1], array_column($db->withinBBox('xp', 'loc', 0, 0, 5, 5), 'n'));
        $stats = new \noneDB\Tests\InternalValue('indexStats');
        $loads = $stats->getValue()['loads'];
        $epoch = $this->epoch('xp');

        // Another process appends (same epoch)
        $this->runChild('$d = ' . $this->childDb($config) . '; $d->insert("xp", ["n" => 3, "loc" => ["type" => "Point", "coordinates" => [2, 2]]]);');
        $this->assertSame($epoch, $this->epoch('xp'));
        $this->assertSame([1, 2, 3], array_column($db->find('xp', 0), 'n'));
        $this->assertSame([1, 3], array_column($db->withinBBox('xp', 'loc', 0, 0, 5, 5), 'n'));
        $this->assertSame($loads, $stats->getValue()['loads'], 'the key index was kept and only replayed');

        // Another process replaces the data file (new epoch)
        $this->runChild('$d = ' . $this->childDb($config) . '; $d->delete("xp", ["n" => 2]); $d->compact("xp");');
        $this->assertNotSame($epoch, $this->epoch('xp'));
        $this->assertSame([1, 3], array_column($db->find('xp', 0), 'n'));
        $this->assertSame([1, 3], array_column($db->withinBBox('xp', 'loc', 0, 0, 5, 5), 'n'));
        $this->assertSame($loads + 1, $stats->getValue()['loads'], 'the key index was loaded again');
    }
}
