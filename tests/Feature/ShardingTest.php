<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for sharding functionality
 */
class ShardingTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set smaller shard size for testing (5 records per shard)
        $this->setPrivateProperty('shardSize', 5);
        $this->setPrivateProperty('shardingEnabled', true);
        $this->setPrivateProperty('autoMigrate', true);
    }

    // ==========================================
    // MIGRATION TESTS
    // ==========================================

    /**
     * @test
     */
    public function insertTriggersShardingAtThreshold(): void
    {
        // Insert exactly threshold records
        $data = [];
        for ($i = 0; $i < 5; $i++) {
            $data[] = ['name' => 'User' . $i, 'age' => 20 + $i];
        }

        $result = $this->noneDB->insert($this->testDbName, $data);
        $this->assertEquals(5, $result['n']);

        // Check that database is now sharded
        $info = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertTrue($info['sharded']);
        $this->assertEquals(1, $info['shards']);
        $this->assertEquals(5, $info['totalRecords']);
    }

    /**
     * @test
     */
    public function legacyDatabaseNotSharded(): void
    {
        // Insert less than threshold
        $this->noneDB->insert($this->testDbName, ['name' => 'Test', 'age' => 25]);

        $info = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertFalse($info['sharded']);
        $this->assertEquals(1, $info['totalRecords']);
    }

    /**
     * @test
     */
    public function manualMigration(): void
    {
        // Insert some records
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'User1', 'age' => 25],
            ['name' => 'User2', 'age' => 30],
        ]);

        // Manually migrate
        $result = $this->noneDB->migrate($this->testDbName);
        $this->assertTrue($result['success']);
        $this->assertEquals('migrated', $result['status']);

        // Verify sharded
        $info = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertTrue($info['sharded']);
    }

    /**
     * @test
     */
    public function migrationPreservesAllData(): void
    {
        // Insert records before migration
        $data = [
            ['name' => 'Alice', 'email' => 'alice@test.com'],
            ['name' => 'Bob', 'email' => 'bob@test.com'],
            ['name' => 'Charlie', 'email' => 'charlie@test.com'],
        ];
        $this->noneDB->insert($this->testDbName, $data);

        // Migrate
        $this->noneDB->migrate($this->testDbName);

        // Verify all records are accessible
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertCount(3, $all);
        $this->assertEquals('Alice', $all[0]['name']);
        $this->assertEquals('Bob', $all[1]['name']);
        $this->assertEquals('Charlie', $all[2]['name']);
    }

    // ==========================================
    // SHARDED INSERT TESTS
    // ==========================================

    /**
     * @test
     */
    public function insertCreatesNewShardWhenFull(): void
    {
        // Insert more than one shard's worth
        $data = [];
        for ($i = 0; $i < 8; $i++) {
            $data[] = ['name' => 'User' . $i, 'value' => $i];
        }

        $this->noneDB->insert($this->testDbName, $data);

        $info = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertTrue($info['sharded']);
        $this->assertEquals(2, $info['shards']); // 5 + 3 = 2 shards
        $this->assertEquals(8, $info['totalRecords']);
    }

    /**
     * @test
     */
    public function insertSingleRecordToShardedDB(): void
    {
        // First create a sharded database
        $data = [];
        for ($i = 0; $i < 5; $i++) {
            $data[] = ['name' => 'User' . $i];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Insert a single record
        $result = $this->noneDB->insert($this->testDbName, ['name' => 'NewUser']);
        $this->assertEquals(1, $result['n']);

        // Verify
        $info = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertEquals(6, $info['totalRecords']);
    }

    // ==========================================
    // SHARDED FIND TESTS
    // ==========================================

    /**
     * @test
     */
    public function findAllInShardedDB(): void
    {
        // Create sharded database with multiple shards
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $data[] = ['name' => 'User' . $i, 'index' => $i];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Find all
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertCount(12, $all);

        // Verify order is preserved
        for ($i = 0; $i < 12; $i++) {
            $this->assertEquals($i, $all[$i]['index']);
        }
    }

    /**
     * @test
     */
    public function findByKeyInShardedDB(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $data[] = ['name' => 'User' . $i, 'index' => $i];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Find by key in first shard
        $result = $this->noneDB->find($this->testDbName, ['key' => 2]);
        $this->assertCount(1, $result);
        $this->assertEquals(2, $result[0]['index']);

        // Find by key in second shard
        $result = $this->noneDB->find($this->testDbName, ['key' => 7]);
        $this->assertCount(1, $result);
        $this->assertEquals(7, $result[0]['index']);

        // Find by key in third shard
        $result = $this->noneDB->find($this->testDbName, ['key' => 10]);
        $this->assertCount(1, $result);
        $this->assertEquals(10, $result[0]['index']);
    }

    /**
     * @test
     */
    public function findByMultipleKeysAcrossShards(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $data[] = ['name' => 'User' . $i, 'index' => $i];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Find multiple keys across shards
        $result = $this->noneDB->find($this->testDbName, ['key' => [1, 6, 11]]);
        $this->assertCount(3, $result);
    }

    /**
     * @test
     */
    public function findByFilterInShardedDB(): void
    {
        // Create sharded database with mixed data
        $data = [];
        for ($i = 0; $i < 10; $i++) {
            $data[] = ['name' => 'User' . $i, 'type' => ($i % 2 === 0) ? 'even' : 'odd'];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Find by filter
        $evens = $this->noneDB->find($this->testDbName, ['type' => 'even']);
        $this->assertCount(5, $evens);

        $odds = $this->noneDB->find($this->testDbName, ['type' => 'odd']);
        $this->assertCount(5, $odds);
    }

    // ==========================================
    // SHARDED UPDATE TESTS
    // ==========================================

    /**
     * @test
     */
    public function updateInShardedDB(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 10; $i++) {
            $data[] = ['name' => 'User' . $i, 'status' => 'active'];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Update specific record
        $result = $this->noneDB->update($this->testDbName, [
            ['name' => 'User5'],
            ['set' => ['status' => 'inactive']]
        ]);
        $this->assertEquals(1, $result['n']);

        // Verify update
        $user = $this->noneDB->find($this->testDbName, ['name' => 'User5']);
        $this->assertEquals('inactive', $user[0]['status']);
    }

    /**
     * @test
     */
    public function updateMultipleRecordsAcrossShards(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $data[] = ['name' => 'User' . $i, 'type' => 'standard', 'updated' => false];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Update all records (spans multiple shards)
        $result = $this->noneDB->update($this->testDbName, [
            [],
            ['set' => ['updated' => true]]
        ]);
        $this->assertEquals(12, $result['n']);

        // Verify all updated
        $all = $this->noneDB->find($this->testDbName, 0);
        foreach ($all as $record) {
            $this->assertTrue($record['updated']);
        }
    }

    // ==========================================
    // SHARDED DELETE TESTS
    // ==========================================

    /**
     * @test
     */
    public function deleteInShardedDB(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 10; $i++) {
            $data[] = ['name' => 'User' . $i, 'index' => $i];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Delete specific record
        $result = $this->noneDB->delete($this->testDbName, ['name' => 'User3']);
        $this->assertEquals(1, $result['n']);

        // Verify deletion
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertCount(9, $all);

        $info = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertEquals(9, $info['totalRecords']);
        $this->assertEquals(1, $info['deletedCount']);
    }

    /**
     * @test
     */
    public function deleteMultipleRecordsAcrossShards(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $data[] = ['name' => 'User' . $i, 'type' => ($i % 2 === 0) ? 'even' : 'odd'];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Delete all even types
        $result = $this->noneDB->delete($this->testDbName, ['type' => 'even']);
        $this->assertEquals(6, $result['n']);

        // Verify
        $remaining = $this->noneDB->find($this->testDbName, 0);
        $this->assertCount(6, $remaining);
        foreach ($remaining as $record) {
            $this->assertEquals('odd', $record['type']);
        }
    }

    // ==========================================
    // COMPACT TESTS
    // ==========================================

    /**
     * @test
     */
    public function compactRemovesDeletedRecords(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 10; $i++) {
            $data[] = ['name' => 'User' . $i, 'index' => $i];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Delete some records
        $this->noneDB->delete($this->testDbName, ['index' => 2]);
        $this->noneDB->delete($this->testDbName, ['index' => 5]);
        $this->noneDB->delete($this->testDbName, ['index' => 8]);

        // Before compact
        $infoBefore = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertEquals(3, $infoBefore['deletedCount']);

        // Compact
        $result = $this->noneDB->compact($this->testDbName);
        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['freedSlots']);

        // After compact
        $infoAfter = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertEquals(0, $infoAfter['deletedCount']);
        $this->assertEquals(7, $infoAfter['totalRecords']);
    }

    /**
     * @test
     */
    public function compactRebuildsShards(): void
    {
        // Create sharded database with 12 records (3 shards of 5 each, minus overhead)
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $data[] = ['name' => 'User' . $i];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Delete most records
        for ($i = 0; $i < 10; $i++) {
            $this->noneDB->delete($this->testDbName, ['name' => 'User' . $i]);
        }

        // Compact should reduce shard count
        $result = $this->noneDB->compact($this->testDbName);
        $this->assertTrue($result['success']);

        // Should now have fewer shards since we only have 2 records
        $info = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertEquals(1, $info['shards']); // 2 records fits in 1 shard
        $this->assertEquals(2, $info['totalRecords']);
    }

    // ==========================================
    // SHARD INFO TESTS
    // ==========================================

    /**
     * @test
     */
    public function getShardInfoReturnsCorrectData(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 8; $i++) {
            $data[] = ['name' => 'User' . $i];
        }
        $this->noneDB->insert($this->testDbName, $data);

        $info = $this->noneDB->getShardInfo($this->testDbName);

        $this->assertTrue($info['sharded']);
        $this->assertEquals(2, $info['shards']);
        $this->assertEquals(8, $info['totalRecords']);
        $this->assertEquals(0, $info['deletedCount']);
        $this->assertEquals(5, $info['shardSize']);
    }

    /**
     * @test
     */
    public function getShardInfoForNonExistentDB(): void
    {
        $info = $this->noneDB->getShardInfo('nonexistent');
        $this->assertFalse($info);
    }

    // ==========================================
    // AGGREGATION ON SHARDED DB TESTS
    // ==========================================

    /**
     * @test
     */
    public function countOnShardedDB(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $data[] = ['name' => 'User' . $i, 'type' => ($i % 3 === 0) ? 'special' : 'normal'];
        }
        $this->noneDB->insert($this->testDbName, $data);

        $total = $this->noneDB->count($this->testDbName);
        $this->assertEquals(12, $total);

        $special = $this->noneDB->count($this->testDbName, ['type' => 'special']);
        $this->assertEquals(4, $special); // 0, 3, 6, 9
    }

    /**
     * @test
     */
    public function sumOnShardedDB(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 10; $i++) {
            $data[] = ['name' => 'User' . $i, 'value' => $i * 10];
        }
        $this->noneDB->insert($this->testDbName, $data);

        $sum = $this->noneDB->sum($this->testDbName, 'value');
        $this->assertEquals(450, $sum); // 0+10+20+...+90
    }

    /**
     * @test
     */
    public function distinctOnShardedDB(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $data[] = ['name' => 'User' . $i, 'group' => 'Group' . ($i % 3)];
        }
        $this->noneDB->insert($this->testDbName, $data);

        $groups = $this->noneDB->distinct($this->testDbName, 'group');
        $this->assertCount(3, $groups);
        $this->assertContains('Group0', $groups);
        $this->assertContains('Group1', $groups);
        $this->assertContains('Group2', $groups);
    }

    // ==========================================
    // UTILITY METHOD TESTS
    // ==========================================

    /**
     * @test
     */
    public function isShardingEnabledReturnsCorrectValue(): void
    {
        $this->assertTrue($this->noneDB->isShardingEnabled());

        $this->setPrivateProperty('shardingEnabled', false);
        $this->assertFalse($this->noneDB->isShardingEnabled());
    }

    /**
     * @test
     */
    public function getShardSizeReturnsCorrectValue(): void
    {
        $this->assertEquals(5, $this->noneDB->getShardSize());

        $this->setPrivateProperty('shardSize', 1000);
        $this->assertEquals(1000, $this->noneDB->getShardSize());
    }

    /**
     * @test
     */
    public function migrateAlreadyShardedReturnsAlreadySharded(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 5; $i++) {
            $data[] = ['name' => 'User' . $i];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Try to migrate again
        $result = $this->noneDB->migrate($this->testDbName);
        $this->assertTrue($result['success']);
        $this->assertEquals('already_sharded', $result['status']);

        // Verify still works
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertCount(5, $all);
    }

    /**
     * @test
     */
    public function migrateNonExistentDatabaseReturnsFalse(): void
    {
        $result = $this->noneDB->migrate('nonexistent_db');
        $this->assertFalse($result['success']);
        $this->assertEquals('database_not_found', $result['status']);
    }

    // ==========================================
    // COMPACT ON NON-SHARDED DB TESTS
    // ==========================================

    /**
     * @test
     */
    public function compactWorksOnNonShardedDatabase(): void
    {
        // Disable auto-sharding for this test
        $this->setPrivateProperty('shardingEnabled', false);

        // Insert records
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'User1'],
            ['name' => 'User2'],
            ['name' => 'User3'],
        ]);

        // Delete one record (creates null entry)
        $this->noneDB->delete($this->testDbName, ['name' => 'User2']);

        // Compact
        $result = $this->noneDB->compact($this->testDbName);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['freedSlots']);
        $this->assertEquals(2, $result['totalRecords']);
        $this->assertFalse($result['sharded']);

        // Verify data is still accessible
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertCount(2, $all);
    }

    /**
     * @test
     */
    public function compactOnNonExistentDatabaseReturnsFalse(): void
    {
        $result = $this->noneDB->compact('nonexistent_db');
        $this->assertFalse($result['success']);
        $this->assertEquals('database_not_found', $result['status']);
    }

    /**
     * @test
     */
    public function compactReturnsShardedFlag(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 10; $i++) {
            $data[] = ['name' => 'User' . $i];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Delete some
        $this->noneDB->delete($this->testDbName, ['name' => 'User5']);

        // Compact sharded
        $result = $this->noneDB->compact($this->testDbName);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['sharded']);
    }
}
