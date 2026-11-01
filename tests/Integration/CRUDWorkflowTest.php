<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Integration tests for complete CRUD workflows
 *
 * Tests end-to-end scenarios combining multiple operations.
 */
class CRUDWorkflowTest extends noneDBTestCase
{
    /**
     * @test
     */
    public function completeCreateReadUpdateDeleteWorkflow(): void
    {
        $dbName = 'workflow-test';

        // CREATE
        $insertResult = $this->noneDB->insert($dbName, [
            'username' => 'testuser',
            'email' => 'test@example.com',
            'status' => 'active'
        ]);
        $this->assertSame(['n' => 1], $insertResult);

        // READ
        $findResult = $this->noneDB->find($dbName, ['username' => 'testuser']);
        $this->assertSame([['username' => 'testuser', 'email' => 'test@example.com', 'status' => 'active', 'key' => 0]], $findResult);

        // UPDATE
        $updateResult = $this->noneDB->update($dbName, [
            ['username' => 'testuser'],
            ['set' => ['email' => 'updated@example.com', 'status' => 'inactive']]
        ]);
        $this->assertSame(['n' => 1], $updateResult);

        // Verify update
        $afterUpdate = $this->noneDB->find($dbName, ['username' => 'testuser']);
        $this->assertSame([['username' => 'testuser', 'email' => 'updated@example.com', 'status' => 'inactive', 'key' => 0]], $afterUpdate);

        // DELETE
        $deleteResult = $this->noneDB->delete($dbName, ['username' => 'testuser']);
        $this->assertSame(['n' => 1], $deleteResult);

        // Verify delete
        $afterDelete = $this->noneDB->find($dbName, ['username' => 'testuser']);
        $this->assertSame([], $afterDelete);
        $this->assertSame([], $this->noneDB->find($dbName, 0));
        $this->assertSame(0, $this->noneDB->count($dbName));
    }

    /**
     * @test
     */
    public function multipleOperationsSequence(): void
    {
        $dbName = 'sequence-test';

        // Insert 5 users
        for ($i = 1; $i <= 5; $i++) {
            $this->noneDB->insert($dbName, [
                'id' => $i,
                'username' => 'user' . $i,
                'score' => $i * 10
            ]);
        }

        // Find all
        $all = $this->noneDB->find($dbName, 0);
        $this->assertSame([0, 1, 2, 3, 4], array_column($all, 'key'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($all, 'id'));

        // Update users with score < 30
        $this->noneDB->update($dbName, [
            ['score' => 10],
            ['set' => ['level' => 'beginner']]
        ]);
        $this->noneDB->update($dbName, [
            ['score' => 20],
            ['set' => ['level' => 'beginner']]
        ]);

        // Update users with score >= 30
        $this->noneDB->update($dbName, [
            ['score' => 30],
            ['set' => ['level' => 'intermediate']]
        ]);
        $this->noneDB->update($dbName, [
            ['score' => 40],
            ['set' => ['level' => 'advanced']]
        ]);
        $this->noneDB->update($dbName, [
            ['score' => 50],
            ['set' => ['level' => 'advanced']]
        ]);

        // Find beginners
        $beginners = $this->noneDB->find($dbName, ['level' => 'beginner']);
        $this->assertSame([1, 2], array_column($beginners, 'id'));

        // Find advanced
        $advanced = $this->noneDB->find($dbName, ['level' => 'advanced']);
        $this->assertSame([4, 5], array_column($advanced, 'id'));

        // Delete intermediate
        $this->noneDB->delete($dbName, ['level' => 'intermediate']);

        // Verify remaining count
        $remaining = $this->noneDB->find($dbName, 0);
        // Deleted records are filtered out, so 5 - 1 = 4 remaining, with their keys
        $this->assertSame([0, 1, 3, 4], array_column($remaining, 'key'));
        $this->assertSame(['beginner', 'beginner', 'advanced', 'advanced'], array_column($remaining, 'level'));
    }

    /**
     * @test
     */
    public function dataPersistenceAcrossInstances(): void
    {
        $dbName = 'persistence-test';

        // Test config for creating new instances
        $testConfig = [
            'secretKey' => 'test_secret_key_for_unit_tests',
            'dbDir' => TEST_DB_DIR,
            'autoCreateDB' => true
        ];

        // Insert with first instance
        $db1 = new \noneDB($testConfig);
        $db1->insert($dbName, ['mykey' => 'value1']);

        // Read with second instance
        $db2 = new \noneDB($testConfig);
        $result = $db2->find($dbName, ['mykey' => 'value1']);

        $this->assertSame([['mykey' => 'value1', 'key' => 0]], $result);

        // Update with third instance
        $db3 = new \noneDB($testConfig);
        $db3->update($dbName, [
            ['mykey' => 'value1'],
            ['set' => ['mykey' => 'value2']]
        ]);

        // Verify with fourth instance
        $db4 = new \noneDB($testConfig);
        $updated = $db4->find($dbName, ['mykey' => 'value2']);

        $this->assertSame([['mykey' => 'value2', 'key' => 0]], $updated);
        $this->assertSame([], $db4->find($dbName, ['mykey' => 'value1']));
    }

    /**
     * @test
     */
    public function databaseRecoveryAfterDeleteAll(): void
    {
        $dbName = 'recovery-test';

        // Insert initial data
        $this->noneDB->insert($dbName, [
            ['item' => 'first'],
            ['item' => 'second'],
            ['item' => 'third'],
        ]);

        // Delete all
        $this->noneDB->delete($dbName, ['item' => 'first']);
        $this->noneDB->delete($dbName, ['item' => 'second']);
        $this->noneDB->delete($dbName, ['item' => 'third']);

        // Insert new data
        $this->noneDB->insert($dbName, ['item' => 'new1']);
        $this->noneDB->insert($dbName, ['item' => 'new2']);

        // Find new data. docs/API.md: keys are never reused, not by deletes and not by compaction (the deletes
        // above compacted the file; the last one kept a tombstone for key 2): the new records get 3 and 4
        $this->assertSame([['item' => 'new1', 'key' => 3], ['item' => 'new2', 'key' => 4]], $this->noneDB->find($dbName, 0));
    }

    /**
     * @test
     */
    public function bulkOperationsPerformance(): void
    {
        $dbName = 'bulk-test';

        // Bulk insert
        $data = [];
        for ($i = 0; $i < 500; $i++) {
            $data[] = ['index' => $i, 'data' => 'item_' . $i];
        }

        $start = microtime(true);
        $result = $this->noneDB->insert($dbName, $data);
        $insertTime = microtime(true) - $start;

        $this->assertSame(['n' => 500], $result);
        $this->assertLessThan(5, $insertTime, 'Bulk insert should complete in under 5 seconds');

        // Find all
        $start = microtime(true);
        $all = $this->noneDB->find($dbName, 0);
        $findTime = microtime(true) - $start;

        $this->assertSame(range(0, 499), array_column($all, 'key'));
        $this->assertSame(range(0, 499), array_column($all, 'index'), 'record i has key i');
        $this->assertLessThan(2, $findTime, 'Find all should complete in under 2 seconds');
    }

    /**
     * @test
     */
    public function complexFilteringScenario(): void
    {
        $dbName = 'filter-test';

        // Insert diverse data
        $this->noneDB->insert($dbName, [
            ['type' => 'A', 'status' => 'active', 'value' => 100],
            ['type' => 'A', 'status' => 'inactive', 'value' => 50],
            ['type' => 'B', 'status' => 'active', 'value' => 75],
            ['type' => 'B', 'status' => 'active', 'value' => 200],
            ['type' => 'C', 'status' => 'inactive', 'value' => 30],
        ]);

        // Find type A
        $typeA = $this->noneDB->find($dbName, ['type' => 'A']);
        $this->assertSame([0, 1], array_column($typeA, 'key'));

        // Find active
        $active = $this->noneDB->find($dbName, ['status' => 'active']);
        $this->assertSame([0, 2, 3], array_column($active, 'key'));

        // Find type B AND active
        $typeB_active = $this->noneDB->find($dbName, ['type' => 'B', 'status' => 'active']);
        $this->assertSame([75, 200], array_column($typeB_active, 'value'));

        // Update all type A to inactive
        $this->noneDB->update($dbName, [
            ['type' => 'A'],
            ['set' => ['status' => 'inactive']]
        ]);

        // Verify update
        $activeAfter = $this->noneDB->find($dbName, ['status' => 'active']);
        $this->assertSame([2, 3], array_column($activeAfter, 'key')); // Only type B active remains
        $this->assertSame(['inactive', 'inactive', 'active', 'active', 'inactive'], array_column($this->noneDB->find($dbName, 0), 'status'));
    }

    /**
     * @test
     */
    public function updateThenDeleteSequence(): void
    {
        $dbName = 'update-delete-test';

        $this->noneDB->insert($dbName, ['name' => 'item1', 'quantity' => 10]);

        // Update quantity
        $this->noneDB->update($dbName, [
            ['name' => 'item1'],
            ['set' => ['quantity' => 5]]
        ]);

        // Verify update
        $item = $this->noneDB->find($dbName, ['name' => 'item1']);
        $this->assertSame([['name' => 'item1', 'quantity' => 5, 'key' => 0]], $item);

        // Update again
        $this->noneDB->update($dbName, [
            ['name' => 'item1'],
            ['set' => ['quantity' => 0]]
        ]);

        // Delete items with quantity 0
        $this->noneDB->delete($dbName, ['quantity' => 0]);

        // Verify deletion
        $remaining = $this->noneDB->find($dbName, ['name' => 'item1']);
        $this->assertSame([], $remaining);
        $this->assertSame([], $this->noneDB->find($dbName, 0));
    }

    /**
     * @test
     */
    public function getDBsAfterOperations(): void
    {
        // Use name without underscores (they get sanitized out)
        $dbName = 'dbinfotest';

        // Insert data
        $before = time();
        $this->noneDB->insert($dbName, [
            ['data' => str_repeat('x', 1000)],
        ]);
        $after = time();

        // Get DB info (docs/API.md getDBs($name): name, createdTime, size)
        $info = $this->noneDB->getDBs($dbName);

        $this->assertIsArray($info);
        $this->assertSame($dbName, $info['name']);
        $this->assertIsString($info['size']);
        $this->assertIsInt($info['createdTime']);
        $this->assertGreaterThanOrEqual($before, $info['createdTime'], 'created by this insert');
        $this->assertLessThanOrEqual($after, $info['createdTime']);
        $this->assertFalse($this->noneDB->getDBs('not-created'), 'false if it does not exist');
    }
}
