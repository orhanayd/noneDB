<?php

namespace noneDB\Tests\Integration;

use noneDB;
use noneDB\Tests\noneDBTestCase;

/**
 * Sharded Field Index Tests
 * Tests for Global Field Index with Shard-Skip Optimization
 */
class ShardedFieldIndexTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Set small shard size to test sharding easily
        $this->setPrivateProperty('shardSize', 100);
        $this->setPrivateProperty('shardingEnabled', true);
    }

    private function isDbSharded()
    {
        return $this->invokePrivateMethod('isSharded', [$this->testDbName]);
    }

    private function getGlobalIndexPath($field)
    {
        return $this->dbPath($this->testDbName, 'gfx', $field);
    }

    /**
     * The global field index checkpoint, decoded by the test's own reader (STORAGE_FORMAT.md §6.5.2): its header,
     * the filter of each shard, and the shards whose filter may hold a value
     */
    private function readGlobalIndex($field)
    {
        [$header, $filters] = $this->readGlobalFieldFile($this->getGlobalIndexPath($field));
        return ['header' => $header, 'filters' => $filters, 'holders' => function ($value) use ($filters) {
            $shards = [];
            foreach ($filters as $shard => $filter) {
                if ($this->filterMayHold($filter, $value)) {
                    $shards[] = $shard;
                }
            }
            return $shards;
        }];
    }

    /**
     * Shards a find() on city = $value reads: the global index's holders, the shards whose newer
     * lines may hold it, and the shards it has no valid entry for
     */
    private function shardsFor($value)
    {
        $name = $this->invokePrivateMethod('normalizeDbName', [$this->testDbName]);
        $shards = $this->invokePrivateMethod('targetShardsFromGlobalIndex', [$name, ['city' => $value]]);
        sort($shards);
        return $shards;
    }

    /**
     * Access per shard of a find on city = $value (explain(): skip / index / scan)
     */
    private function planFor($value)
    {
        return array_column($this->noneDB->query($this->testDbName)->where(['city' => $value])->explain()['plan'], 'access', 'segment');
    }

    // ==================== GLOBAL FIELD INDEX CREATE TESTS ====================

    public function testCreateFieldIndexCreatesGlobalMetadata()
    {
        // Insert 300 records (will create 3 shards with shardSize=100)
        $records = [];
        for ($i = 0; $i < 300; $i++) {
            $records[] = [
                'name' => 'User' . $i,
                'city' => $i < 100 ? 'Istanbul' : ($i < 200 ? 'Ankara' : 'Izmir')
            ];
        }
        $this->noneDB->insert($this->testDbName, $records);

        // Verify it's sharded
        $this->assertTrue($this->isDbSharded());

        // Create index
        $result = $this->noneDB->createFieldIndex($this->testDbName, 'city');

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['shards']); // 3 shards indexed
        $this->assertSame(['success' => true, 'indexed' => 300, 'error' => null, 'shards' => 3], $result);

        // Check global field index file exists
        $gfxPath = $this->getGlobalIndexPath('city');
        $this->assertFileExists($gfxPath);

        // Verify global metadata structure (a checkpoint with an entry per shard; header kind "gfx", v 5:
        // docs/STORAGE_FORMAT.md §5.1, §6.5, §6.5.2)
        $global = $this->readGlobalIndex('city');

        $this->assertEquals(1, $global['header']['v']);
        $this->assertEquals('gfx', $global['header']['kind']);
        $this->assertEquals('city', $global['header']['field']);
        $this->assertEquals([0, 1, 2], array_keys($global['header']['sh']));
        foreach ($global['header']['sh'] as $shard => $entry) {
            // Each entry covers its whole shard: the bytes of its data file
            $this->assertSame(filesize($this->dbPath($this->testDbName, 'data', $shard)), $entry['s']);
        }
        // One value per shard: 64 bits (the minimum, ≥ 32 per value), 8 shards wide (the minimum)
        $this->assertSame([64, 8], [$global['header']['bits'], $global['header']['width']]);
        $this->assertSame([0 => $this->shardFilter(['Istanbul'], 64), 1 => $this->shardFilter(['Ankara'], 64), 2 => $this->shardFilter(['Izmir'], 64)], $global['filters']);

        // Istanbul only in shard 0, Ankara only in shard 1, Izmir only in shard 2
        $this->assertEquals([0], $global['holders']('Istanbul'));
        $this->assertEquals([1], $global['holders']('Ankara'));
        $this->assertEquals([2], $global['holders']('Izmir'));
        $this->assertEquals([1], $this->shardsFor('Ankara'));
    }

    public function testGlobalFieldIndexWithValueInMultipleShards()
    {
        // Insert records with same city in multiple shards
        $records = [];
        for ($i = 0; $i < 300; $i++) {
            $records[] = [
                'name' => 'User' . $i,
                'city' => $i % 3 === 0 ? 'Istanbul' : 'Other' // Istanbul in all shards
            ];
        }
        $this->noneDB->insert($this->testDbName, $records);

        // Verify sharded
        $this->assertTrue($this->isDbSharded());

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Istanbul should be in all 3 shards
        $this->assertEquals([0, 1, 2], $this->readGlobalIndex('city')['holders']('Istanbul'));
        $this->assertEquals([0, 1, 2], $this->shardsFor('Istanbul'));
    }

    // ==================== SHARD-SKIP FIND TESTS ====================

    public function testFindUsesShardSkipOptimization()
    {
        // Insert 300 records where Istanbul is ONLY in shard 0
        $records = [];
        for ($i = 0; $i < 300; $i++) {
            $records[] = [
                'name' => 'User' . $i,
                'city' => $i < 100 ? 'Istanbul' : 'Other'
            ];
        }
        $this->noneDB->insert($this->testDbName, $records);
        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Find Istanbul - should only look at shard 0
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']);

        $this->assertCount(100, $result);
        foreach ($result as $record) {
            $this->assertEquals('Istanbul', $record['city']);
        }
        $this->assertSame(range(0, 99), array_column($result, 'key'));
        $this->assertEquals([0], $this->shardsFor('Istanbul'));
        $this->assertSame([0 => 'index', 1 => 'skip', 2 => 'skip'], $this->planFor('Istanbul'), 'shards 1 and 2 are skipped');
    }

    public function testFindWithValueInMultipleShards()
    {
        // Insert records with Istanbul in shards 0 and 2 only
        $records = [];
        for ($i = 0; $i < 300; $i++) {
            // Shard 0: i < 100, Shard 1: 100 <= i < 200, Shard 2: i >= 200
            $records[] = [
                'name' => 'User' . $i,
                'city' => ($i < 100 || $i >= 200) ? 'Istanbul' : 'Ankara'
            ];
        }
        $this->noneDB->insert($this->testDbName, $records);
        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Find Istanbul - should look at shards 0 and 2 only
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']);

        $this->assertCount(200, $result); // 100 from shard 0 + 100 from shard 2
        $this->assertSame(array_merge(range(0, 99), range(200, 299)), array_column($result, 'key'));
        $this->assertEquals([0, 2], $this->shardsFor('Istanbul'));
        $this->assertSame([0 => 'index', 1 => 'skip', 2 => 'index'], $this->planFor('Istanbul'));
    }

    public function testFindWithNonExistentValue()
    {
        $records = [];
        for ($i = 0; $i < 200; $i++) {
            $records[] = [
                'name' => 'User' . $i,
                'city' => $i < 100 ? 'Istanbul' : 'Ankara'
            ];
        }
        $this->noneDB->insert($this->testDbName, $records);
        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Find non-existent value - should return empty immediately
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Izmir']);

        $this->assertSame([], $result);
        $this->assertSame([], $this->shardsFor('Izmir'), 'no shard is read');
        $this->assertSame('none', $this->noneDB->query($this->testDbName)->where(['city' => 'Izmir'])->explain()['access']);
    }

    // ==================== INSERT UPDATES GLOBAL INDEX ====================

    public function testInsertUpdatesGlobalFieldIndex()
    {
        // Create initial data with Istanbul only in shard 0
        $records = [];
        for ($i = 0; $i < 200; $i++) {
            $records[] = [
                'name' => 'User' . $i,
                'city' => $i < 100 ? 'Istanbul' : 'Ankara'
            ];
        }
        $this->noneDB->insert($this->testDbName, $records);
        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Verify Istanbul is only in shard 0
        $gfxPath = $this->getGlobalIndexPath('city');
        $this->assertEquals([0], $this->readGlobalIndex('city')['holders']('Istanbul'));
        $file = file_get_contents($gfxPath);

        // Insert Istanbul record - will go to shard 2 (shards 0 and 1 are full)
        $this->noneDB->insert($this->testDbName, ['name' => 'NewUser', 'city' => 'Istanbul']);

        // A write doesn't rewrite the global index; shard 2 has no entry yet, so it is read
        noneDB::clearStaticCache();
        $this->assertSame($file, file_get_contents($gfxPath));
        $this->assertEquals([0, 2], $this->shardsFor('Istanbul'));
        $this->assertEquals([1, 2], $this->shardsFor('Ankara'), 'shard 2 has no entry: read for every value');
        $this->assertCount(101, $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']));
        $this->assertSame(array_merge(range(0, 99), [200]), array_column($this->noneDB->find($this->testDbName, ['city' => 'Istanbul']), 'key'));
    }

    public function testInsertNewValueCreatesGlobalEntry()
    {
        $records = [];
        for ($i = 0; $i < 200; $i++) {
            $records[] = [
                'name' => 'User' . $i,
                'city' => $i < 100 ? 'Istanbul' : 'Ankara'
            ];
        }
        $this->noneDB->insert($this->testDbName, $records);
        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Insert new city
        $this->noneDB->insert($this->testDbName, ['name' => 'IzmirUser', 'city' => 'Izmir']);

        // Izmir is found in shard 2 (no entry yet: read) and nowhere else
        noneDB::clearStaticCache();
        $this->assertEquals([2], $this->shardsFor('Izmir'));
        $this->assertSame(['Izmir'], array_column($this->noneDB->find($this->testDbName, ['city' => 'Izmir']), 'city'));
    }

    // ==================== DELETE UPDATES GLOBAL INDEX ====================

    /**
     * Test that deleting all records with a specific value from a shard keeps lookups exact, and
     * that the value leaves the global field index once the shard's index is checkpointed (rebuilt).
     */
    public function testDeleteUpdatesGlobalFieldIndex()
    {
        // Insert 200 records across 2 shards
        // Shard 0 (0-99): has "Istanbul" (indices 0-49) and "Ankara" (indices 50-99)
        // Shard 1 (100-199): has only "Izmir" (indices 100-199)
        $records = [];
        for ($i = 0; $i < 200; $i++) {
            if ($i < 50) {
                $city = 'Istanbul';
            } elseif ($i < 100) {
                $city = 'Ankara';
            } else {
                $city = 'Izmir';
            }
            $records[] = ['name' => 'User' . $i, 'city' => $city];
        }
        $this->noneDB->insert($this->testDbName, $records);

        // Create field index
        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Verify global index structure before delete
        $global = $this->readGlobalIndex('city');

        // Istanbul should only be in shard 0
        $this->assertEquals([0], $global['holders']('Istanbul'));
        // Ankara should only be in shard 0
        $this->assertEquals([0], $global['holders']('Ankara'));
        // Izmir should only be in shard 1
        $this->assertEquals([1], $global['holders']('Izmir'));

        // Delete ALL Istanbul records from shard 0
        $this->noneDB->delete($this->testDbName, ['city' => 'Istanbul']);

        // A value leaves the global index when the shard's index is checkpointed; until then the
        // shard is read for it (and holds none)
        noneDB::clearStaticCache();
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']));
        $this->assertContains($this->shardsFor('Istanbul'), [[], [0]]);

        // Ankara and Izmir still exist
        $this->assertEquals([0], $this->shardsFor('Ankara'));
        $this->assertEquals([1], $this->shardsFor('Izmir'));

        // A rebuilt global index has the data's values only
        $this->noneDB->rebuildFieldIndex($this->testDbName, 'city');
        $global = $this->readGlobalIndex('city');
        $this->assertSame([0 => $this->shardFilter(['Ankara'], 64), 1 => $this->shardFilter(['Izmir'], 64)], $global['filters']);
        $this->assertSame([], $global['holders']('Istanbul'));
        $this->assertEquals([0], $global['holders']('Ankara'));
        $this->assertEquals([1], $global['holders']('Izmir'));
    }

    // ==================== UPDATE UPDATES GLOBAL INDEX ====================

    /**
     * Test that updating a record's indexed field value updates the global field index.
     */
    public function testUpdateUpdatesGlobalFieldIndex()
    {
        // Insert 200 records across 2 shards
        // Shard 0 (0-99): all "Istanbul"
        // Shard 1 (100-199): all "Ankara"
        $records = [];
        for ($i = 0; $i < 200; $i++) {
            $city = $i < 100 ? 'Istanbul' : 'Ankara';
            $records[] = ['name' => 'User' . $i, 'city' => $city];
        }
        $this->noneDB->insert($this->testDbName, $records);

        // Create field index
        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Verify global index structure before update
        $global = $this->readGlobalIndex('city');

        $this->assertEquals([0], $global['holders']('Istanbul'));
        $this->assertEquals([1], $global['holders']('Ankara'));
        $this->assertSame([], $global['holders']('Izmir'));

        // Update ALL Istanbul records in shard 0 to Izmir
        $this->noneDB->update($this->testDbName, [
            ['city' => 'Istanbul'],
            ['set' => ['city' => 'Izmir']]
        ]);

        // Shard 0's newer lines (or its new data file, after compaction) are read for Izmir
        noneDB::clearStaticCache();
        $this->assertEquals([0], $this->shardsFor('Izmir'));
        $this->assertCount(100, $this->noneDB->find($this->testDbName, ['city' => 'Izmir']));
        $this->assertSame(range(0, 99), array_column($this->noneDB->find($this->testDbName, ['city' => 'Izmir']), 'key'));
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']));

        // Ankara should still be in shard 1 only
        $this->assertEquals([1], $this->shardsFor('Ankara'));

        // A rebuilt global index has the data's values only
        $this->noneDB->rebuildFieldIndex($this->testDbName, 'city');
        $global = $this->readGlobalIndex('city');
        $this->assertSame([0 => $this->shardFilter(['Izmir'], 64), 1 => $this->shardFilter(['Ankara'], 64)], $global['filters']);
        $this->assertSame([], $global['holders']('Istanbul'));
        $this->assertEquals([0], $global['holders']('Izmir'));
        $this->assertEquals([1], $global['holders']('Ankara'));
    }

    // ==================== DROP INDEX TESTS ====================

    public function testDropFieldIndexDeletesGlobalIndex()
    {
        $records = [];
        for ($i = 0; $i < 200; $i++) {
            $records[] = [
                'name' => 'User' . $i,
                'city' => $i < 100 ? 'Istanbul' : 'Ankara'
            ];
        }
        $this->noneDB->insert($this->testDbName, $records);
        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Verify global index exists
        $gfxPath = $this->getGlobalIndexPath('city');
        $this->assertFileExists($gfxPath);
        $shardFiles = glob($this->dbPath($this->testDbName, 'gen') . 'indexes/s*.city.fx');
        $this->assertSame([$this->dbPath($this->testDbName, 'fx', 'city', 0), $this->dbPath($this->testDbName, 'fx', 'city', 1)], $shardFiles);

        // Drop index
        $this->assertSame(['success' => true, 'error' => null], $this->noneDB->dropFieldIndex($this->testDbName, 'city'));

        // Global index should be deleted, the shard indexes too
        $this->assertFileDoesNotExist($gfxPath);
        foreach ($shardFiles as $file) {
            $this->assertFileDoesNotExist($file);
        }
        $this->assertFalse($this->noneDB->hasFieldIndex($this->testDbName, 'city'));
        // Lookups still exact, without skipping shards
        $this->assertSame(range(100, 199), array_column($this->noneDB->find($this->testDbName, ['city' => 'Ankara']), 'key'));
        $this->assertSame([0 => 'scan', 1 => 'scan'], $this->planFor('Ankara'));
    }

    // ==================== REBUILD INDEX TESTS ====================

    public function testRebuildFieldIndexRebuildsGlobalIndex()
    {
        $records = [];
        for ($i = 0; $i < 200; $i++) {
            $records[] = [
                'name' => 'User' . $i,
                'city' => $i < 100 ? 'Istanbul' : 'Ankara'
            ];
        }
        $this->noneDB->insert($this->testDbName, $records);
        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // A broken global index: rebuildFieldIndex() must write it again from the data
        file_put_contents($this->getGlobalIndexPath('city'), 'garbage');
        noneDB::clearStaticCache();

        // Rebuild index
        $result = $this->noneDB->rebuildFieldIndex($this->testDbName, 'city');

        $this->assertTrue($result['success']);
        $this->assertSame(['success' => true, 'indexed' => 200, 'error' => null, 'shards' => 2], $result);

        // Check global index is valid
        $global = $this->readGlobalIndex('city');

        $this->assertEquals([0], $global['holders']('Istanbul'));
        $this->assertEquals([1], $global['holders']('Ankara'));
        $this->assertSame([0 => $this->shardFilter(['Istanbul'], 64), 1 => $this->shardFilter(['Ankara'], 64)], $global['filters']);
        $this->assertEquals([0, 1], array_keys($global['header']['sh']));
        $this->assertSame([0 => 'skip', 1 => 'index'], $this->planFor('Ankara'));
    }

    // ==================== PERFORMANCE TESTS ====================

    public function testShardSkipPerformanceImprovement()
    {
        // Insert 500 records (5 shards) with rare value in only 1 shard
        $records = [];
        for ($i = 0; $i < 500; $i++) {
            $records[] = [
                'name' => 'User' . $i,
                'city' => $i < 10 ? 'Rare' : 'Common'  // Rare only in first 10 records (shard 0)
            ];
        }
        $this->noneDB->insert($this->testDbName, $records);

        // Time WITHOUT index
        $start = microtime(true);
        noneDB::clearStaticCache();
        $this->noneDB->find($this->testDbName, ['city' => 'Rare']);
        $timeWithoutIndex = (microtime(true) - $start) * 1000;

        // Create index
        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Time WITH index (should skip 4 shards)
        $start = microtime(true);
        noneDB::clearStaticCache();
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Rare']);
        $timeWithIndex = (microtime(true) - $start) * 1000;

        // Verify correct results
        $this->assertCount(10, $result);
        $this->assertSame(range(0, 9), array_column($result, 'key'));

        // Timing is not asserted (too noisy); what the optimization guarantees: shards 1..4 are skipped and
        // only the 10 matching records of shard 0 are read
        $explain = $this->noneDB->query($this->testDbName)->where(['city' => 'Rare'])->explain(true);
        $this->assertSame(['total' => 5, 'skipped' => 4, 'indexed' => 1, 'scanned' => 0], $explain['segments']);
        $this->assertSame(['recordsRead' => 10, 'segmentsRead' => 1, 'returned' => 10], array_diff_key($explain['actual'], ['ms' => 0]));
    }

    // ==================== HELPER METHODS ====================

    private function invokePrivateMethod($methodName, $args)
    {
        $method = $this->getPrivateMethod($methodName);
        return $method->invokeArgs($this->noneDB, $args);
    }
}
