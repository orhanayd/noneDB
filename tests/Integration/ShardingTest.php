<?php

namespace noneDB\Tests\Integration;

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
    }

    /** Shard file of the test database */
    private function shardFile(int $shard): string
    {
        return $this->dbPath($this->testDbName, 'data', $shard);
    }

    /** Keys stored in a shard file (record lines only) */
    private function shardKeys(int $shard): array
    {
        $keys = [];
        foreach (explode("\n", (string)@file_get_contents($this->shardFile($shard))) as $line) {
            $record = json_decode($line, true);
            if (is_array($record) && isset($record['key'])) {
                $keys[] = $record['key'];
            }
        }
        return $keys;
    }

    // ==========================================
    // THE SWITCH TO SHARDS
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

        // docs/CONFIGURATION.md shardingEnabled: switched to shards once shardSize (5) keys are assigned: one shard [0, 5)
        $info = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertSame(['sharded' => true, 'shards' => 1, 'totalRecords' => 5, 'deletedCount' => 0, 'shardSize' => 5, 'nextKey' => 5], $info);
        $this->assertDatabaseNotExists($this->testDbName);
        $this->assertSame(range(0, 4), $this->shardKeys(0));
        $this->assertSame($data, array_map(function ($r) { unset($r['key']); return $r; }, $this->noneDB->find($this->testDbName, 0)));
    }

    /**
     * @test
     */
    public function smallDatabaseIsNotSharded(): void
    {
        // Insert less than threshold
        $this->noneDB->insert($this->testDbName, ['name' => 'Test', 'age' => 25]);

        $info = $this->noneDB->getShardInfo($this->testDbName);
        // docs/API.md: not sharded: ["sharded" => false, "shards" => 0, "totalRecords" => n, "shardSize" => size, "nextKey" => k]
        $this->assertSame(['sharded' => false, 'shards' => 0, 'totalRecords' => 1, 'shardSize' => 5, 'nextKey' => 1], $info);
    }

    /**
     * @test
     */
    public function convertToShardedSwitchesTheDatabase(): void
    {
        // Insert some records
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'User1', 'age' => 25],
            ['name' => 'User2', 'age' => 30],
        ]);

        // Switch by hand
        $result = $this->noneDB->convertToSharded($this->testDbName);
        $this->assertSame(['success' => true, 'status' => 'converted'], $result);

        // Verify sharded, keys and records kept
        $info = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertSame(['sharded' => true, 'shards' => 1, 'totalRecords' => 2, 'deletedCount' => 0, 'shardSize' => 5, 'nextKey' => 2], $info);
        $this->assertSame([
            ['name' => 'User1', 'age' => 25, 'key' => 0],
            ['name' => 'User2', 'age' => 30, 'key' => 1],
        ], $this->noneDB->find($this->testDbName, 0));
        $this->assertDatabaseNotExists($this->testDbName);
    }

    /**
     * @test
     */
    public function theSwitchToShardsPreservesAllData(): void
    {
        // Insert records before the switch
        $data = [
            ['name' => 'Alice', 'email' => 'alice@test.com'],
            ['name' => 'Bob', 'email' => 'bob@test.com'],
            ['name' => 'Charlie', 'email' => 'charlie@test.com'],
        ];
        $this->noneDB->insert($this->testDbName, $data);

        // Switch
        $this->noneDB->convertToSharded($this->testDbName);

        // Verify all records are accessible, with their keys
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertSame([
            ['name' => 'Alice', 'email' => 'alice@test.com', 'key' => 0],
            ['name' => 'Bob', 'email' => 'bob@test.com', 'key' => 1],
            ['name' => 'Charlie', 'email' => 'charlie@test.com', 'key' => 2],
        ], $all);
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
        // key K lives in shard intdiv(K, 5): keys 0-4 in shard 0, 5-7 in shard 1
        $this->assertSame(['sharded' => true, 'shards' => 2, 'totalRecords' => 8, 'deletedCount' => 0, 'shardSize' => 5, 'nextKey' => 8], $info);
        $this->assertSame(range(0, 4), $this->shardKeys(0));
        $this->assertSame([5, 6, 7], $this->shardKeys(1));
        $this->assertFileDoesNotExist($this->shardFile(2));
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

        // Insert a single record: key 5, the first of shard 1
        $result = $this->noneDB->insert($this->testDbName, ['name' => 'NewUser']);
        $this->assertSame(['n' => 1], $result);

        // Verify
        $info = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertSame(6, $info['totalRecords']);
        $this->assertSame(6, $info['nextKey']);
        $this->assertSame(2, $info['shards']);
        $this->assertSame([['name' => 'NewUser', 'key' => 5]], $this->noneDB->find($this->testDbName, ['name' => 'NewUser']));
        $this->assertSame([5], $this->shardKeys(1));
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

        // Verify order is preserved: key order across shards, record i has key i
        $this->assertSame(range(0, 11), array_column($all, 'index'));
        $this->assertSame(range(0, 11), array_column($all, 'key'));
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

        // Find by key in first, second and third shard
        foreach ([2, 7, 10] as $key) {
            $this->assertSame([['name' => 'User' . $key, 'index' => $key, 'key' => $key]], $this->noneDB->find($this->testDbName, ['key' => $key]));
        }
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['key' => 12]), 'beyond the last shard');
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['key' => 99]), 'a shard that does not exist');
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

        // Find multiple keys across shards: in the order of the list
        $result = $this->noneDB->find($this->testDbName, ['key' => [11, 1, 6]]);
        $this->assertSame([11, 1, 6], array_column($result, 'key'));
        $this->assertSame([11, 1, 6], array_column($result, 'index'));
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
        $this->assertSame([0, 2, 4, 6, 8], array_column($evens, 'key'));

        $odds = $this->noneDB->find($this->testDbName, ['type' => 'odd']);
        $this->assertSame([1, 3, 5, 7, 9], array_column($odds, 'key'));
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
        $this->assertSame(['n' => 1], $result);

        // Verify update: only User5 (key 5, shard 1)
        $user = $this->noneDB->find($this->testDbName, ['name' => 'User5']);
        $this->assertSame([['name' => 'User5', 'status' => 'inactive', 'key' => 5]], $user);
        $this->assertSame([5], array_column($this->noneDB->find($this->testDbName, ['status' => 'inactive']), 'key'));
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
        $this->assertSame(['n' => 12], $result);

        // Verify all updated, keys unchanged
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertSame(array_fill(0, 12, true), array_column($all, 'updated'));
        $this->assertSame(range(0, 11), array_column($all, 'key'));
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
        $this->assertSame(['n' => 1], $result);

        // Verify deletion
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertSame([0, 1, 2, 4, 5, 6, 7, 8, 9], array_column($all, 'key'));

        $info = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertSame(9, $info['totalRecords']);
        $this->assertSame(1, $info['deletedCount']);
        $this->assertSame(10, $info['nextKey'], 'a delete never lowers the next key');
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
        $this->assertSame(['n' => 6], $result);

        // Verify
        $remaining = $this->noneDB->find($this->testDbName, 0);
        $this->assertSame([1, 3, 5, 7, 9, 11], array_column($remaining, 'key'));
        $this->assertSame(array_fill(0, 6, 'odd'), array_column($remaining, 'type'));
        $this->assertSame(6, $this->noneDB->getShardInfo($this->testDbName)['deletedCount']);
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

        // Compact (docs/API.md result; no shard became empty)
        $result = $this->noneDB->compact($this->testDbName);
        $this->assertSame(['success' => true, 'freedSlots' => 3, 'totalRecords' => 7, 'sharded' => true, 'newShardCount' => 2], $result);

        // After compact: keys never change
        $infoAfter = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertSame(0, $infoAfter['deletedCount']);
        $this->assertSame(7, $infoAfter['totalRecords']);
        $this->assertSame(10, $infoAfter['nextKey']);
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertSame([0, 1, 3, 4, 6, 7, 9], array_column($all, 'key'));
        $this->assertSame([0, 1, 3, 4, 6, 7, 9], array_column($all, 'index'));
        $this->assertSame([0, 1, 3, 4], $this->shardKeys(0), 'only live records are left in the data');
        $this->assertSame([6, 7, 9], $this->shardKeys(1));
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

        // docs/API.md: shards that became empty (0 and 1) are removed, the shard of the newest key (2) stays
        $this->assertSame(1, $result['newShardCount']);
        $info = $this->noneDB->getShardInfo($this->testDbName);
        $this->assertSame(1, $info['shards']);
        $this->assertSame(2, $info['totalRecords']);
        $this->assertSame(12, $info['nextKey']);
        $this->assertFileDoesNotExist($this->shardFile(0));
        $this->assertFileDoesNotExist($this->shardFile(1));
        $this->assertSame([['name' => 'User10', 'key' => 10], ['name' => 'User11', 'key' => 11]], $this->noneDB->find($this->testDbName, 0), 'keys never change');
        $this->noneDB->insert($this->testDbName, ['name' => 'User12']);
        $this->assertSame([10, 11, 12], array_column($this->noneDB->find($this->testDbName, 0), 'key'));
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

        $this->assertSame(['sharded' => true, 'shards' => 2, 'totalRecords' => 8, 'deletedCount' => 0, 'shardSize' => 5, 'nextKey' => 8], $info);
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
    public function convertingAShardedDatabaseReturnsAlreadySharded(): void
    {
        // Create sharded database
        $data = [];
        for ($i = 0; $i < 5; $i++) {
            $data[] = ['name' => 'User' . $i];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Try to switch again
        $result = $this->noneDB->convertToSharded($this->testDbName);
        $this->assertSame(['success' => true, 'status' => 'already_sharded'], $result);

        // Verify still works
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertSame(range(0, 4), array_column($all, 'key'));
    }

    /**
     * @test
     */
    public function convertingAMissingDatabaseFails(): void
    {
        $result = $this->noneDB->convertToSharded('nonexistent-db');
        $this->assertSame(['success' => false, 'status' => 'database_not_found'], $result);
        $this->assertDatabaseNotExists('nonexistent-db');
    }

    // ==========================================
    // COMPACT ON NON-SHARDED DB TESTS
    // ==========================================

    /**
     * @test
     * Deletes compact automatically past the garbage threshold, so compact() finds nothing left to free
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

        // Delete one record: 1 garbage line of 3 record versions is more than the 30% threshold, so the delete
        // compacts by itself (docs/CONFIGURATION.md "Compaction Settings")
        $this->noneDB->delete($this->testDbName, ['name' => 'User2']);

        // Compact: nothing left to free
        $result = $this->noneDB->compact($this->testDbName);

        $this->assertSame(['success' => true, 'freedSlots' => 0, 'totalRecords' => 2, 'sharded' => false], $result);

        // Verify data is still accessible, keys unchanged, and the data file holds only the two records
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertSame([['name' => 'User1', 'key' => 0], ['name' => 'User3', 'key' => 2]], $all);
        $lines = array_values(array_filter(explode("\n", file_get_contents($this->getDbFilePath($this->testDbName))), 'strlen'));
        $this->assertCount(2, $lines);
    }

    /**
     * @test
     */
    public function compactOnNonExistentDatabaseReturnsFalse(): void
    {
        $result = $this->noneDB->compact('nonexistent-db');
        $this->assertSame(['success' => false, 'freedSlots' => 0, 'status' => 'database_not_found'], $result);
        $this->assertDatabaseNotExists('nonexistent-db');
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

        $this->assertSame(['success' => true, 'freedSlots' => 1, 'totalRecords' => 9, 'sharded' => true, 'newShardCount' => 2], $result);
    }
}
