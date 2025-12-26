<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Integration tests for concurrency and file handling
 *
 * Tests retry mechanisms, file locking, and stat cache handling.
 */
class ConcurrencyTest extends noneDBTestCase
{
    /**
     * @test
     */
    public function rapidSequentialInserts(): void
    {
        $dbName = 'rapid_insert_test';

        // Perform rapid sequential inserts
        for ($i = 0; $i < 50; $i++) {
            $result = $this->noneDB->insert($dbName, ['index' => $i]);
            $this->assertEquals(1, $result['n'], "Insert $i should succeed");
        }

        // Verify all data
        $all = $this->noneDB->find($dbName, 0);
        $this->assertCount(50, $all);
    }

    /**
     * @test
     */
    public function rapidSequentialReads(): void
    {
        $dbName = 'rapid_read_test';

        // Setup data
        $this->noneDB->insert($dbName, [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ]);

        // Perform rapid sequential reads
        for ($i = 0; $i < 100; $i++) {
            $result = $this->noneDB->find($dbName, 0);
            $this->assertCount(3, $result, "Read $i should return 3 records");
        }
    }

    /**
     * @test
     */
    public function insertThenImmediateRead(): void
    {
        $dbName = 'insert_read_test';

        for ($i = 0; $i < 20; $i++) {
            // Insert
            $this->noneDB->insert($dbName, ['index' => $i]);

            // Immediate read
            $result = $this->noneDB->find($dbName, ['index' => $i]);

            $this->assertCount(1, $result, "Should find record immediately after insert (iteration $i)");
            $this->assertEquals($i, $result[0]['index']);
        }
    }

    /**
     * @test
     */
    public function updateThenImmediateRead(): void
    {
        $dbName = 'update_read_test';

        $this->noneDB->insert($dbName, ['counter' => 0]);

        for ($i = 1; $i <= 10; $i++) {
            // Update
            $this->noneDB->update($dbName, [
                ['counter' => $i - 1],
                ['set' => ['counter' => $i]]
            ]);

            // Immediate read
            $result = $this->noneDB->find($dbName, ['counter' => $i]);

            $this->assertCount(1, $result, "Should find updated record (iteration $i)");
            $this->assertEquals($i, $result[0]['counter']);
        }
    }

    /**
     * @test
     */
    public function clearStatCacheEffectiveness(): void
    {
        $dbName = 'statcache_test';

        // Insert initial data
        $this->noneDB->insert($dbName, ['value' => 'initial']);

        // Read to populate stat cache
        $result1 = $this->noneDB->find($dbName, 0);

        // Modify directly (simulating external modification)
        $filePath = $this->getDbFilePath($dbName);
        $newContent = json_encode(['data' => [['value' => 'modified']]]);
        file_put_contents($filePath, $newContent, LOCK_EX);

        // Read again - should get updated data due to clearstatcache
        $result2 = $this->noneDB->find($dbName, 0);

        $this->assertEquals('modified', $result2[0]['value']);
    }

    /**
     * @test
     */
    public function multipleInstancesConcurrent(): void
    {
        $dbName = 'multi_instance_test';

        // Create multiple instances and set them to use test directory
        $db1 = new \noneDB();
        $db2 = new \noneDB();
        $db3 = new \noneDB();

        $reflector = new \ReflectionClass(\noneDB::class);
        $property = $reflector->getProperty('dbDir');
        $property->setAccessible(true);
        $property->setValue($db1, TEST_DB_DIR);
        $property->setValue($db2, TEST_DB_DIR);
        $property->setValue($db3, TEST_DB_DIR);

        // Insert from instance 1
        $db1->insert($dbName, ['from' => 'db1']);

        // Read from instance 2
        $result2 = $db2->find($dbName, 0);
        $this->assertCount(1, $result2);

        // Insert from instance 3
        $db3->insert($dbName, ['from' => 'db3']);

        // Read from instance 1
        $result1 = $db1->find($dbName, 0);
        $this->assertCount(2, $result1);
    }

    /**
     * @test
     */
    public function fileLockPreventsCorruption(): void
    {
        $dbName = 'lock_test';

        // Insert initial data
        $this->noneDB->insert($dbName, ['id' => 0]);

        // Simulate concurrent updates by rapid updates
        for ($i = 1; $i <= 100; $i++) {
            $this->noneDB->update($dbName, [
                ['id' => $i - 1],
                ['set' => ['id' => $i]]
            ]);
        }

        // Verify final state
        $result = $this->noneDB->find($dbName, 0);

        // Should have exactly one record
        $this->assertCount(1, $result);

        // Should have the final value
        $this->assertEquals(100, $result[0]['id']);
    }

    /**
     * @test
     */
    public function retryMechanismDoesNotHang(): void
    {
        $dbName = 'retry_test';

        // Insert data
        $this->noneDB->insert($dbName, ['data' => 'test']);

        // Multiple reads should complete without hanging
        $startTime = microtime(true);

        for ($i = 0; $i < 50; $i++) {
            $this->noneDB->find($dbName, 0);
        }

        $elapsed = microtime(true) - $startTime;

        // Should complete in reasonable time (not stuck in retry loops)
        $this->assertLessThan(5, $elapsed, "Operations should not hang in retry loops");
    }

    /**
     * @test
     */
    public function emptyFileHandling(): void
    {
        $dbName = 'empty_file_test';

        // Create DB
        $this->noneDB->createDB($dbName);

        // Read from empty file
        $result = $this->noneDB->find($dbName, 0);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function largeDataHandling(): void
    {
        $dbName = 'large_data_test';

        // Insert large record
        $largeData = [
            'content' => str_repeat('x', 100000), // 100KB of data
            'metadata' => array_fill(0, 100, 'item')
        ];

        $result = $this->noneDB->insert($dbName, $largeData);
        $this->assertEquals(1, $result['n']);

        // Read back
        $found = $this->noneDB->find($dbName, 0);

        $this->assertCount(1, $found);
        $this->assertEquals(100000, strlen($found[0]['content']));
    }

    /**
     * @test
     */
    public function writeReadWriteSequence(): void
    {
        $dbName = 'wrw_test';

        // Write
        $this->noneDB->insert($dbName, ['step' => 1]);

        // Read
        $r1 = $this->noneDB->find($dbName, 0);
        $this->assertCount(1, $r1);

        // Write
        $this->noneDB->insert($dbName, ['step' => 2]);

        // Read
        $r2 = $this->noneDB->find($dbName, 0);
        $this->assertCount(2, $r2);

        // Write
        $this->noneDB->update($dbName, [
            ['step' => 1],
            ['set' => ['step' => 3]]
        ]);

        // Final read
        $r3 = $this->noneDB->find($dbName, ['step' => 3]);
        $this->assertCount(1, $r3);
    }

    /**
     * @test
     */
    public function getDBsDoesNotBlockOperations(): void
    {
        $dbName = 'getdbs_test';

        $this->noneDB->insert($dbName, ['data' => 'test']);

        // Alternating getDBs and CRUD operations
        for ($i = 0; $i < 10; $i++) {
            $this->noneDB->getDBs(true);
            $this->noneDB->insert($dbName, ['index' => $i]);
            $this->noneDB->getDBs($dbName);
            $this->noneDB->find($dbName, ['index' => $i]);
        }

        // Verify data integrity
        $all = $this->noneDB->find($dbName, 0);
        $this->assertCount(11, $all); // 1 initial + 10 loop inserts
    }
}
