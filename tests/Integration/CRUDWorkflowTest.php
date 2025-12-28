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
        $dbName = 'workflow_test';

        // CREATE
        $insertResult = $this->noneDB->insert($dbName, [
            'username' => 'testuser',
            'email' => 'test@example.com',
            'status' => 'active'
        ]);
        $this->assertEquals(1, $insertResult['n']);

        // READ
        $findResult = $this->noneDB->find($dbName, ['username' => 'testuser']);
        $this->assertCount(1, $findResult);
        $this->assertEquals('test@example.com', $findResult[0]['email']);

        // UPDATE
        $updateResult = $this->noneDB->update($dbName, [
            ['username' => 'testuser'],
            ['set' => ['email' => 'updated@example.com', 'status' => 'inactive']]
        ]);
        $this->assertEquals(1, $updateResult['n']);

        // Verify update
        $afterUpdate = $this->noneDB->find($dbName, ['username' => 'testuser']);
        $this->assertEquals('updated@example.com', $afterUpdate[0]['email']);
        $this->assertEquals('inactive', $afterUpdate[0]['status']);

        // DELETE
        $deleteResult = $this->noneDB->delete($dbName, ['username' => 'testuser']);
        $this->assertEquals(1, $deleteResult['n']);

        // Verify delete
        $afterDelete = $this->noneDB->find($dbName, ['username' => 'testuser']);
        $this->assertCount(0, $afterDelete);
    }

    /**
     * @test
     */
    public function multipleOperationsSequence(): void
    {
        $dbName = 'sequence_test';

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
        $this->assertCount(5, $all);

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
        $this->assertCount(2, $beginners);

        // Find advanced
        $advanced = $this->noneDB->find($dbName, ['level' => 'advanced']);
        $this->assertCount(2, $advanced);

        // Delete intermediate
        $this->noneDB->delete($dbName, ['level' => 'intermediate']);

        // Verify remaining count
        $remaining = $this->noneDB->find($dbName, 0);
        // Deleted records are filtered out, so 5 - 1 = 4 remaining
        $this->assertCount(4, $remaining);
    }

    /**
     * @test
     */
    public function dataPersistenceAcrossInstances(): void
    {
        $dbName = 'persistence_test';

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

        $this->assertCount(1, $result);
        $this->assertEquals('value1', $result[0]['mykey']);

        // Update with third instance
        $db3 = new \noneDB($testConfig);
        $db3->update($dbName, [
            ['mykey' => 'value1'],
            ['set' => ['mykey' => 'value2']]
        ]);

        // Verify with fourth instance
        $db4 = new \noneDB($testConfig);
        $updated = $db4->find($dbName, ['mykey' => 'value2']);

        $this->assertCount(1, $updated);
    }

    /**
     * @test
     */
    public function databaseRecoveryAfterDeleteAll(): void
    {
        $dbName = 'recovery_test';

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

        // Find new data
        $new1 = $this->noneDB->find($dbName, ['item' => 'new1']);
        $new2 = $this->noneDB->find($dbName, ['item' => 'new2']);

        $this->assertCount(1, $new1);
        $this->assertCount(1, $new2);
    }

    /**
     * @test
     */
    public function bulkOperationsPerformance(): void
    {
        $dbName = 'bulk_test';

        // Bulk insert
        $data = [];
        for ($i = 0; $i < 500; $i++) {
            $data[] = ['index' => $i, 'data' => 'item_' . $i];
        }

        $start = microtime(true);
        $result = $this->noneDB->insert($dbName, $data);
        $insertTime = microtime(true) - $start;

        $this->assertEquals(500, $result['n']);
        $this->assertLessThan(5, $insertTime, 'Bulk insert should complete in under 5 seconds');

        // Find all
        $start = microtime(true);
        $all = $this->noneDB->find($dbName, 0);
        $findTime = microtime(true) - $start;

        $this->assertCount(500, $all);
        $this->assertLessThan(2, $findTime, 'Find all should complete in under 2 seconds');
    }

    /**
     * @test
     */
    public function complexFilteringScenario(): void
    {
        $dbName = 'filter_test';

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
        $this->assertCount(2, $typeA);

        // Find active
        $active = $this->noneDB->find($dbName, ['status' => 'active']);
        $this->assertCount(3, $active);

        // Find type B AND active
        $typeB_active = $this->noneDB->find($dbName, ['type' => 'B', 'status' => 'active']);
        $this->assertCount(2, $typeB_active);

        // Update all type A to inactive
        $this->noneDB->update($dbName, [
            ['type' => 'A'],
            ['set' => ['status' => 'inactive']]
        ]);

        // Verify update
        $activeAfter = $this->noneDB->find($dbName, ['status' => 'active']);
        $this->assertCount(2, $activeAfter); // Only type B active remains
    }

    /**
     * @test
     */
    public function updateThenDeleteSequence(): void
    {
        $dbName = 'update_delete_test';

        $this->noneDB->insert($dbName, ['name' => 'item1', 'quantity' => 10]);

        // Update quantity
        $this->noneDB->update($dbName, [
            ['name' => 'item1'],
            ['set' => ['quantity' => 5]]
        ]);

        // Verify update
        $item = $this->noneDB->find($dbName, ['name' => 'item1']);
        $this->assertEquals(5, $item[0]['quantity']);

        // Update again
        $this->noneDB->update($dbName, [
            ['name' => 'item1'],
            ['set' => ['quantity' => 0]]
        ]);

        // Delete items with quantity 0
        $this->noneDB->delete($dbName, ['quantity' => 0]);

        // Verify deletion
        $remaining = $this->noneDB->find($dbName, ['name' => 'item1']);
        $this->assertCount(0, $remaining);
    }

    /**
     * @test
     */
    public function getDBsAfterOperations(): void
    {
        // Use name without underscores (they get sanitized out)
        $dbName = 'dbinfotest';

        // Insert data
        $this->noneDB->insert($dbName, [
            ['data' => str_repeat('x', 1000)],
        ]);

        // Get DB info
        $info = $this->noneDB->getDBs($dbName);

        $this->assertIsArray($info);
        $this->assertEquals($dbName, $info['name']);
        $this->assertArrayHasKey('size', $info);
        $this->assertArrayHasKey('createdTime', $info);
    }
}
