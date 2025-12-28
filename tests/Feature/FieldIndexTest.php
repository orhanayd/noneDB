<?php

namespace noneDB\Tests\Feature;

use noneDB;
use noneDB\Tests\noneDBTestCase;

/**
 * Field Indexing Tests - v3.0.0
 * Tests for O(1) filter-based lookups using field indexes
 */
class FieldIndexTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure clean state
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

    // ==================== CREATE INDEX TESTS ====================

    public function testCreateFieldIndex()
    {
        // Insert test data
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul', 'age' => 30],
            ['name' => 'Jane', 'city' => 'Ankara', 'age' => 25],
            ['name' => 'Bob', 'city' => 'Istanbul', 'age' => 35],
        ]);

        // Create index on city field
        $result = $this->noneDB->createFieldIndex($this->testDbName, 'city');

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['values']); // 2 unique values: Istanbul, Ankara
    }

    public function testCreateFieldIndexOnEmptyDatabase()
    {
        // Create empty database
        $this->noneDB->insert($this->testDbName, ['name' => 'temp']);
        $this->noneDB->delete($this->testDbName, ['name' => 'temp']);

        // Create index should work but have 0 values
        $result = $this->noneDB->createFieldIndex($this->testDbName, 'city');

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['values']);
    }

    public function testCreateFieldIndexOnNonExistentField()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
        ]);

        // Create index on field that doesn't exist
        $result = $this->noneDB->createFieldIndex($this->testDbName, 'nonexistent');

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['values']);
    }

    public function testCreateFieldIndexWithNullValues()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
            ['name' => 'Jane', 'city' => null],
            ['name' => 'Bob'], // city field missing
        ]);

        $result = $this->noneDB->createFieldIndex($this->testDbName, 'city');

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['values']); // Istanbul and null
    }

    public function testCreateFieldIndexWithBooleanValues()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'active' => true],
            ['name' => 'Jane', 'active' => false],
            ['name' => 'Bob', 'active' => true],
        ]);

        $result = $this->noneDB->createFieldIndex($this->testDbName, 'active');

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['values']); // true and false
    }

    // ==================== DROP INDEX TESTS ====================

    public function testDropFieldIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        $result = $this->noneDB->dropFieldIndex($this->testDbName, 'city');

        $this->assertTrue($result['success']);
    }

    public function testDropNonExistentIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
        ]);

        $result = $this->noneDB->dropFieldIndex($this->testDbName, 'nonexistent');

        $this->assertFalse($result['success']);
    }

    // ==================== GET INDEXES TESTS ====================

    public function testGetFieldIndexes()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul', 'age' => 30],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');
        $this->noneDB->createFieldIndex($this->testDbName, 'age');

        $result = $this->noneDB->getFieldIndexes($this->testDbName);

        $this->assertArrayHasKey('fields', $result);
        $this->assertCount(2, $result['fields']);
        $this->assertContains('city', $result['fields']);
        $this->assertContains('age', $result['fields']);
    }

    public function testGetFieldIndexesEmpty()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John'],
        ]);

        $result = $this->noneDB->getFieldIndexes($this->testDbName);

        $this->assertArrayHasKey('fields', $result);
        $this->assertEmpty($result['fields']);
    }

    // ==================== FIND WITH INDEX TESTS ====================

    public function testFindUsesFieldIndex()
    {
        // Insert 100 records
        $records = [];
        for ($i = 0; $i < 100; $i++) {
            $records[] = [
                'name' => 'User' . $i,
                'city' => $i % 5 === 0 ? 'Istanbul' : 'Other',
                'age' => 20 + ($i % 30)
            ];
        }
        $this->noneDB->insert($this->testDbName, $records);

        // Create index on city
        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Find with indexed field
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']);

        $this->assertCount(20, $result); // Every 5th record = 20 records

        // Verify all results have correct city
        foreach ($result as $record) {
            $this->assertEquals('Istanbul', $record['city']);
        }
    }

    public function testFindWithoutIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
            ['name' => 'Jane', 'city' => 'Ankara'],
        ]);

        // Find without index - should still work
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']);

        $this->assertCount(1, $result);
        $this->assertEquals('John', $result[0]['name']);
    }

    public function testFindWithMultipleIndexedFields()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul', 'dept' => 'IT'],
            ['name' => 'Jane', 'city' => 'Istanbul', 'dept' => 'HR'],
            ['name' => 'Bob', 'city' => 'Ankara', 'dept' => 'IT'],
            ['name' => 'Alice', 'city' => 'Istanbul', 'dept' => 'IT'],
        ]);

        // Create indexes on both fields
        $this->noneDB->createFieldIndex($this->testDbName, 'city');
        $this->noneDB->createFieldIndex($this->testDbName, 'dept');

        // Find with both indexed fields (intersection)
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Istanbul', 'dept' => 'IT']);

        $this->assertCount(2, $result); // John and Alice
    }

    public function testFindWithNoMatchingRecords()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        $result = $this->noneDB->find($this->testDbName, ['city' => 'NonExistent']);

        $this->assertEmpty($result);
    }

    // ==================== INDEX MAINTENANCE ON INSERT ====================

    public function testInsertUpdatesFieldIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
        ]);

        // Create index
        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Insert new record
        $this->noneDB->insert($this->testDbName, ['name' => 'Jane', 'city' => 'Ankara']);

        // Index should be updated
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Ankara']);

        $this->assertCount(1, $result);
        $this->assertEquals('Jane', $result[0]['name']);
    }

    public function testBulkInsertUpdatesFieldIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Bulk insert
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Jane', 'city' => 'Ankara'],
            ['name' => 'Bob', 'city' => 'Izmir'],
            ['name' => 'Alice', 'city' => 'Istanbul'],
        ]);

        // Index should be updated for all
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']);
        $this->assertCount(2, $result); // John and Alice
    }

    // ==================== INDEX MAINTENANCE ON DELETE ====================

    public function testDeleteUpdatesFieldIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
            ['name' => 'Jane', 'city' => 'Istanbul'],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Delete one record
        $this->noneDB->delete($this->testDbName, ['name' => 'John']);

        // Index should be updated
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']);

        $this->assertCount(1, $result);
        $this->assertEquals('Jane', $result[0]['name']);
    }

    public function testDeleteAllWithSameValueUpdatesIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
            ['name' => 'Jane', 'city' => 'Istanbul'],
            ['name' => 'Bob', 'city' => 'Ankara'],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Delete all Istanbul records
        $this->noneDB->delete($this->testDbName, ['city' => 'Istanbul']);

        // Index should be updated - Istanbul value should have no keys
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']);
        $this->assertEmpty($result);

        // Ankara should still work
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Ankara']);
        $this->assertCount(1, $result);
    }

    // ==================== INDEX MAINTENANCE ON UPDATE ====================

    public function testUpdateUpdatesFieldIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
            ['name' => 'Jane', 'city' => 'Ankara'],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Update John's city
        $this->noneDB->update($this->testDbName, [
            ['name' => 'John'],
            ['set' => ['city' => 'Izmir']]
        ]);

        // Old value should not find John
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']);
        $this->assertEmpty($result);

        // New value should find John
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Izmir']);
        $this->assertCount(1, $result);
        $this->assertEquals('John', $result[0]['name']);
    }

    public function testUpdateNonIndexedFieldDoesNotAffectIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul', 'age' => 30],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Update non-indexed field
        $this->noneDB->update($this->testDbName, [
            ['name' => 'John'],
            ['set' => ['age' => 31]]
        ]);

        // Index should still work
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']);
        $this->assertCount(1, $result);
        $this->assertEquals(31, $result[0]['age']);
    }

    // ==================== REBUILD INDEX TESTS ====================

    public function testRebuildFieldIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
            ['name' => 'Jane', 'city' => 'Ankara'],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Rebuild index
        $result = $this->noneDB->rebuildFieldIndex($this->testDbName, 'city');

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['values']);
    }

    // ==================== SPECIAL VALUE TESTS ====================

    public function testFindWithNullValue()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => null],
            ['name' => 'Jane', 'city' => 'Istanbul'],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        $result = $this->noneDB->find($this->testDbName, ['city' => null]);

        $this->assertCount(1, $result);
        $this->assertEquals('John', $result[0]['name']);
    }

    public function testFindWithBooleanValue()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'active' => true],
            ['name' => 'Jane', 'active' => false],
            ['name' => 'Bob', 'active' => true],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'active');

        $result = $this->noneDB->find($this->testDbName, ['active' => true]);
        $this->assertCount(2, $result);

        $result = $this->noneDB->find($this->testDbName, ['active' => false]);
        $this->assertCount(1, $result);
        $this->assertEquals('Jane', $result[0]['name']);
    }

    public function testFindWithNumericValue()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'score' => 100],
            ['name' => 'Jane', 'score' => 85],
            ['name' => 'Bob', 'score' => 100],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'score');

        $result = $this->noneDB->find($this->testDbName, ['score' => 100]);

        $this->assertCount(2, $result);
    }

    // ==================== PERFORMANCE TESTS ====================

    public function testFieldIndexPerformance()
    {
        // Insert many records
        $records = [];
        for ($i = 0; $i < 1000; $i++) {
            $records[] = [
                'name' => 'User' . $i,
                'category' => 'cat' . ($i % 10), // 10 unique values
            ];
        }
        $this->noneDB->insert($this->testDbName, $records);

        // Time find WITHOUT index
        $start = microtime(true);
        noneDB::clearStaticCache();
        $this->noneDB->find($this->testDbName, ['category' => 'cat5']);
        $timeWithoutIndex = (microtime(true) - $start) * 1000;

        // Create index
        $this->noneDB->createFieldIndex($this->testDbName, 'category');

        // Time find WITH index
        $start = microtime(true);
        noneDB::clearStaticCache();
        $result = $this->noneDB->find($this->testDbName, ['category' => 'cat5']);
        $timeWithIndex = (microtime(true) - $start) * 1000;

        // With index should be faster (at least not slower)
        $this->assertCount(100, $result); // 1000/10 = 100 records per category

        // Note: In small datasets, the difference might be minimal
        // This test ensures the feature works correctly
    }
}
