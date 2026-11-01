<?php

namespace noneDB\Tests\Integration;

use noneDB;
use noneDB\Tests\noneDBTestCase;

/**
 * Field Indexing Tests
 * Tests for O(1) filter-based lookups using field indexes
 */
class FieldIndexTest extends noneDBTestCase
{
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

        // 3 records indexed, 2 distinct values: Istanbul, Ankara
        $this->assertSame(['success' => true, 'indexed' => 3, 'error' => null], $result);
        $this->assertTrue($this->noneDB->hasFieldIndex($this->testDbName, 'city'));
        $this->assertFileExists($this->dbPath($this->testDbName, 'fx', 'city'));
        $this->assertSame([0, 2], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'city', 'Istanbul'));
    }

    public function testCreateFieldIndexOnEmptyDatabase()
    {
        // Create empty database
        $this->noneDB->insert($this->testDbName, ['name' => 'temp']);
        $this->noneDB->delete($this->testDbName, ['name' => 'temp']);

        // Create index should work but have 0 values
        $result = $this->noneDB->createFieldIndex($this->testDbName, 'city');

        $this->assertSame(['success' => true, 'indexed' => 0, 'error' => null], $result);
        $this->assertSame([], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'city', 'temp'));
    }

    public function testCreateFieldIndexOnNonExistentField()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
        ]);

        // Create index on field that doesn't exist
        $result = $this->noneDB->createFieldIndex($this->testDbName, 'nonexistent');

        $this->assertSame(['success' => true, 'indexed' => 0, 'error' => null], $result);
        $this->assertSame(['nonexistent'], $this->noneDB->getFieldIndexes($this->testDbName)['fields']);
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['nonexistent' => null]), 'a missing field is not null');

        // A record that gets the field later is found through the index
        $this->noneDB->insert($this->testDbName, ['name' => 'Jane', 'nonexistent' => 'now']);
        $this->assertSame([1], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'nonexistent', 'now'));
    }

    public function testCreateFieldIndexWithNullValues()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
            ['name' => 'Jane', 'city' => null],
            ['name' => 'Bob'], // city field missing
        ]);

        $result = $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Istanbul and null are indexed (2 values); Bob's missing field is not indexed at all
        $this->assertSame(['success' => true, 'indexed' => 2, 'error' => null], $result);
        $this->assertSame([1], array_column($this->noneDB->find($this->testDbName, ['city' => null]), 'key'), 'missing is not null');
        $this->assertSame([1], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'city', null));
    }

    public function testCreateFieldIndexWithBooleanValues()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'active' => true],
            ['name' => 'Jane', 'active' => false],
            ['name' => 'Bob', 'active' => true],
        ]);

        $result = $this->noneDB->createFieldIndex($this->testDbName, 'active');

        $this->assertSame(['success' => true, 'indexed' => 3, 'error' => null], $result); // true and false
        $this->assertSame([0, 2], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'active', true));
        $this->assertSame([1], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'active', false));
        $this->assertSame([], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'active', 1), 'true is not 1');
    }

    // ==================== DROP INDEX TESTS ====================

    public function testDropFieldIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        $result = $this->noneDB->dropFieldIndex($this->testDbName, 'city');

        $this->assertSame(['success' => true, 'error' => null], $result);
        $this->assertFalse($this->noneDB->hasFieldIndex($this->testDbName, 'city'));
        $this->assertSame(['fields' => [], 'sharded' => false], $this->noneDB->getFieldIndexes($this->testDbName));
        $this->assertFileDoesNotExist($this->dbPath($this->testDbName, 'fx', 'city'));
        $this->assertNull($this->noneDB->getKeysFromFieldIndex($this->testDbName, 'city', 'Istanbul'));
        $this->assertSame([0], array_column($this->noneDB->find($this->testDbName, ['city' => 'Istanbul']), 'key'), 'found without the index');
    }

    public function testDropNonExistentIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
        ]);

        $result = $this->noneDB->dropFieldIndex($this->testDbName, 'nonexistent');

        $this->assertSame(['success' => false, 'error' => 'Index does not exist'], $result);
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

        $this->assertSame(['fields', 'sharded'], array_keys($result));
        $this->assertFalse($result['sharded']);
        $this->assertCount(2, $result['fields']);
        $this->assertEqualsCanonicalizing(['city', 'age'], $result['fields']);
    }

    public function testGetFieldIndexesEmpty()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John'],
        ]);

        $result = $this->noneDB->getFieldIndexes($this->testDbName);

        $this->assertSame(['fields' => [], 'sharded' => false], $result);
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

        $this->assertSame(range(0, 95, 5), array_column($result, 'key')); // Every 5th record = 20 records

        // Verify all results have correct city
        foreach ($result as $record) {
            $this->assertEquals('Istanbul', $record['city']);
        }

        // ...and that they were read through the index: only the 20 candidates
        $explain = $this->noneDB->query($this->testDbName)->where(['city' => 'Istanbul'])->explain(true);
        $this->assertSame(['index', 20, 20, 20], [$explain['access'], $explain['estimatedRead'], $explain['actual']['recordsRead'], $explain['actual']['returned']]);
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
        $this->assertSame('scan', $this->noneDB->query($this->testDbName)->where(['city' => 'Istanbul'])->explain()['access']);
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

        $this->assertSame([[0, 'John'], [3, 'Alice']], array_map(function ($r) { return [$r['key'], $r['name']]; }, $result));
        $explain = $this->noneDB->query($this->testDbName)->where(['city' => 'Istanbul', 'dept' => 'IT'])->explain();
        $this->assertSame(['index', 2], [$explain['access'], $explain['estimatedRead']], 'the intersection of both indexes');
    }

    public function testFindWithNoMatchingRecords()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        $result = $this->noneDB->find($this->testDbName, ['city' => 'NonExistent']);

        $this->assertSame([], $result);
        $this->assertSame([], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'city', 'NonExistent'));
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
        $this->assertSame(1, $result[0]['key']);
        noneDB::clearStaticCache();
        $this->assertSame([1], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'city', 'Ankara'), 'also for a new process');
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
        $this->assertSame([0, 3], array_column($result, 'key')); // John and Alice
        $this->assertSame([2], array_column($this->noneDB->find($this->testDbName, ['city' => 'Izmir']), 'key'));
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
        $this->assertSame([1], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'city', 'Istanbul'));
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
        $this->assertSame([], $result);
        $this->assertSame([], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'city', 'Istanbul'));

        // Ankara should still work
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Ankara']);
        $this->assertSame([2], array_column($result, 'key'));
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
        $this->assertSame([], $result);

        // New value should find John
        $result = $this->noneDB->find($this->testDbName, ['city' => 'Izmir']);
        $this->assertCount(1, $result);
        $this->assertEquals('John', $result[0]['name']);
        $this->assertSame([0], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'city', 'Izmir'));
        $this->assertSame([1], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'city', 'Ankara'), 'Jane is untouched');
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
        $this->assertSame(0, $result[0]['key']);
        $this->assertSame(31, $result[0]['age']);
    }

    // ==================== REBUILD INDEX TESTS ====================

    public function testRebuildFieldIndex()
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'John', 'city' => 'Istanbul'],
            ['name' => 'Jane', 'city' => 'Ankara'],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'city');

        // Damage the index: wrong buckets under a header that still fits the data
        $fx = $this->dbPath($this->testDbName, 'fx', 'city');
        $header = strtok(file_get_contents($fx), "\n");
        file_put_contents($fx, $header . "\n" . json_encode(['sIstanbul' => 1, 'sAnkara' => 0]) . "\n");
        noneDB::clearStaticCache();

        // Rebuild index
        $result = $this->noneDB->rebuildFieldIndex($this->testDbName, 'city');

        $this->assertSame(['success' => true, 'indexed' => 2, 'error' => null], $result);
        $this->assertSame([0], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'city', 'Istanbul'), 'rebuilt from the data');
        $this->assertSame('Jane', $this->noneDB->find($this->testDbName, ['city' => 'Ankara'])[0]['name']);
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
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['city' => '']), 'null is not ""');
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['city' => false]), 'null is not false');
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
        $this->assertSame([0, 2], array_column($result, 'key'));
        $this->assertSame([], $this->noneDB->find($this->testDbName, ['active' => 1]), 'true is not 1');

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
            ['name' => 'Ann', 'score' => '100'],
            ['name' => 'Eve', 'score' => 100.0],
        ]);

        $this->noneDB->createFieldIndex($this->testDbName, 'score');

        $result = $this->noneDB->find($this->testDbName, ['score' => 100]);

        // Matched with ===: the string "100" and the float 100.0 are other values
        $this->assertSame([0, 2], array_column($result, 'key'));
        $this->assertSame([3], array_column($this->noneDB->find($this->testDbName, ['score' => '100']), 'key'));
        $this->assertSame([4], array_column($this->noneDB->find($this->testDbName, ['score' => 100.0]), 'key'));
        $this->assertSame([0, 2], $this->noneDB->getKeysFromFieldIndex($this->testDbName, 'score', 100));
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

        // 1000/10 = 100 records per category: keys 5, 15, ..., 995
        $this->assertSame(range(5, 995, 10), array_column($result, 'key'));

        // Timing is not asserted (too noisy); what makes the indexed lookup cheaper is: it reads only the
        // 100 candidates, the unindexed one all 1000 records
        $explain = $this->noneDB->query($this->testDbName)->where(['category' => 'cat5'])->explain(true);
        $this->assertSame(['index', 100, 100], [$explain['access'], $explain['estimatedRead'], $explain['actual']['recordsRead']]);
        $this->noneDB->dropFieldIndex($this->testDbName, 'category');
        $explain = $this->noneDB->query($this->testDbName)->where(['category' => 'cat5'])->explain(true);
        $this->assertSame(['scan', 1000, 1000, 100], [$explain['access'], $explain['estimatedRead'], $explain['actual']['recordsRead'], $explain['actual']['returned']]);
    }
}
