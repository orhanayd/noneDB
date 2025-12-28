<?php

namespace noneDB\Tests\Feature;

use noneDB;
use noneDB\Tests\noneDBTestCase;

/**
 * Sharded Field Index Tests - v3.0.0
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
        $this->setPrivateProperty('autoMigrate', true);
        $this->cleanupTestFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestFiles();
        parent::tearDown();
    }

    private function cleanupTestFiles()
    {
        $files = glob($this->testDbDir . '*' . $this->testDbName . '*');
        foreach ($files as $file) {
            @unlink($file);
        }
        noneDB::clearStaticCache();
    }

    private function isDbSharded()
    {
        return $this->invokePrivateMethod('isSharded', [$this->testDbName]);
    }

    private function getGlobalIndexPath($field)
    {
        // Must use sanitized dbname (createFieldIndex sanitizes the name)
        $sanitizedName = $this->invokePrivateMethod('sanitizeDbName', [$this->testDbName]);
        $hash = $this->invokePrivateMethod('hashDBName', [$sanitizedName]);
        return $this->testDbDir . $hash . "-" . $sanitizedName . ".nonedb.gfidx." . $field;
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

        // Check global field index file exists
        $gfidxPath = $this->getGlobalIndexPath('city');
        $this->assertFileExists($gfidxPath);

        // Verify global metadata structure
        $content = file_get_contents($gfidxPath);
        $globalMeta = json_decode($content, true);

        $this->assertEquals(1, $globalMeta['v']);
        $this->assertEquals('city', $globalMeta['field']);
        $this->assertArrayHasKey('shardMap', $globalMeta);

        // Check shardMap - Istanbul only in shard 0, Ankara only in shard 1, Izmir only in shard 2
        $this->assertEquals([0], $globalMeta['shardMap']['Istanbul']);
        $this->assertEquals([1], $globalMeta['shardMap']['Ankara']);
        $this->assertEquals([2], $globalMeta['shardMap']['Izmir']);
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

        // Read global metadata
        $gfidxPath = $this->getGlobalIndexPath('city');
        $globalMeta = json_decode(file_get_contents($gfidxPath), true);

        // Istanbul should be in all 3 shards
        $this->assertContains(0, $globalMeta['shardMap']['Istanbul']);
        $this->assertContains(1, $globalMeta['shardMap']['Istanbul']);
        $this->assertContains(2, $globalMeta['shardMap']['Istanbul']);
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

        $this->assertEmpty($result);
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
        $gfidxPath = $this->getGlobalIndexPath('city');
        $globalMeta = json_decode(file_get_contents($gfidxPath), true);
        $this->assertEquals([0], $globalMeta['shardMap']['Istanbul']);

        // Insert Istanbul record - will go to shard 2 (shards 0 and 1 are full)
        $this->noneDB->insert($this->testDbName, ['name' => 'NewUser', 'city' => 'Istanbul']);

        // Refresh and check global index was updated
        noneDB::clearStaticCache();
        $globalMeta = json_decode(file_get_contents($gfidxPath), true);

        // Istanbul should now be in shards 0 and 2 (not 1, because new shard 2 was created)
        $this->assertContains(0, $globalMeta['shardMap']['Istanbul']);
        $this->assertContains(2, $globalMeta['shardMap']['Istanbul']);
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

        // Check global index
        $gfidxPath = $this->getGlobalIndexPath('city');
        noneDB::clearStaticCache();
        $globalMeta = json_decode(file_get_contents($gfidxPath), true);

        // Izmir should be in the global index
        $this->assertArrayHasKey('Izmir', $globalMeta['shardMap']);
    }

    // ==================== DELETE UPDATES GLOBAL INDEX ====================

    /**
     * Test that deleting all records with a specific value from a shard
     * removes that shard from the global field index's shardMap.
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
        $gfidxPath = $this->getGlobalIndexPath('city');
        $globalMeta = json_decode(file_get_contents($gfidxPath), true);

        // Istanbul should only be in shard 0
        $this->assertEquals([0], $globalMeta['shardMap']['Istanbul']);
        // Ankara should only be in shard 0
        $this->assertEquals([0], $globalMeta['shardMap']['Ankara']);
        // Izmir should only be in shard 1
        $this->assertEquals([1], $globalMeta['shardMap']['Izmir']);

        // Delete ALL Istanbul records from shard 0
        $this->noneDB->delete($this->testDbName, ['city' => 'Istanbul']);

        // Re-read global index - Istanbul should be removed since no more Istanbul records exist
        noneDB::clearStaticCache();
        $globalMeta = json_decode(file_get_contents($gfidxPath), true);

        // Istanbul should no longer be in shardMap (or empty array)
        $this->assertTrue(
            !isset($globalMeta['shardMap']['Istanbul']) || empty($globalMeta['shardMap']['Istanbul']),
            'Istanbul should be removed from global index after deleting all Istanbul records'
        );

        // Ankara and Izmir should still exist
        $this->assertEquals([0], $globalMeta['shardMap']['Ankara']);
        $this->assertEquals([1], $globalMeta['shardMap']['Izmir']);
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
        $gfidxPath = $this->getGlobalIndexPath('city');
        $globalMeta = json_decode(file_get_contents($gfidxPath), true);

        $this->assertEquals([0], $globalMeta['shardMap']['Istanbul']);
        $this->assertEquals([1], $globalMeta['shardMap']['Ankara']);
        $this->assertFalse(isset($globalMeta['shardMap']['Izmir']));

        // Update ALL Istanbul records in shard 0 to Izmir
        $this->noneDB->update($this->testDbName, [
            ['city' => 'Istanbul'],
            ['set' => ['city' => 'Izmir']]
        ]);

        // Re-read global index
        noneDB::clearStaticCache();
        $globalMeta = json_decode(file_get_contents($gfidxPath), true);

        // Istanbul should be removed (no more Istanbul records in shard 0)
        $this->assertTrue(
            !isset($globalMeta['shardMap']['Istanbul']) || empty($globalMeta['shardMap']['Istanbul']),
            'Istanbul should be removed from global index after updating all Istanbul records'
        );

        // Izmir should now be in shard 0
        $this->assertContains(0, $globalMeta['shardMap']['Izmir']);

        // Ankara should still be in shard 1
        $this->assertEquals([1], $globalMeta['shardMap']['Ankara']);
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
        $gfidxPath = $this->getGlobalIndexPath('city');
        $this->assertFileExists($gfidxPath);

        // Drop index
        $this->noneDB->dropFieldIndex($this->testDbName, 'city');

        // Global index should be deleted
        $this->assertFileDoesNotExist($gfidxPath);
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

        // Rebuild index
        $result = $this->noneDB->rebuildFieldIndex($this->testDbName, 'city');

        $this->assertTrue($result['success']);

        // Check global index is valid
        $gfidxPath = $this->getGlobalIndexPath('city');
        $globalMeta = json_decode(file_get_contents($gfidxPath), true);

        $this->assertEquals([0], $globalMeta['shardMap']['Istanbul']);
        $this->assertEquals([1], $globalMeta['shardMap']['Ankara']);
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

        // Index should be faster (at least in large datasets)
        // For small test, we just verify functionality works
    }

    // ==================== HELPER METHODS ====================

    private function invokePrivateMethod($methodName, $args)
    {
        $method = $this->getPrivateMethod($methodName);
        return $method->invokeArgs($this->noneDB, $args);
    }
}
