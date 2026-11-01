<?php

namespace noneDB\Tests\Concurrency;

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
        $dbName = 'rapid-insert-test';

        // Perform rapid sequential inserts
        for ($i = 0; $i < 50; $i++) {
            $result = $this->noneDB->insert($dbName, ['index' => $i]);
            $this->assertSame(['n' => 1], $result, "Insert $i should succeed");
        }

        // Verify all data: record i got key i
        $all = $this->noneDB->find($dbName, 0);
        $this->assertSame(range(0, 49), array_column($all, 'index'));
        $this->assertSame(range(0, 49), array_column($all, 'key'));
    }

    /**
     * @test
     */
    public function rapidSequentialReads(): void
    {
        $dbName = 'rapid-read-test';

        // Setup data
        $this->noneDB->insert($dbName, [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ]);

        // Perform rapid sequential reads: always the same records
        $expected = [['id' => 1, 'key' => 0], ['id' => 2, 'key' => 1], ['id' => 3, 'key' => 2]];
        for ($i = 0; $i < 100; $i++) {
            $result = $this->noneDB->find($dbName, 0);
            $this->assertSame($expected, $result, "Read $i should return the 3 records");
        }
    }

    /**
     * @test
     */
    public function insertThenImmediateRead(): void
    {
        $dbName = 'insert-read-test';

        for ($i = 0; $i < 20; $i++) {
            // Insert
            $this->noneDB->insert($dbName, ['index' => $i]);

            // Immediate read
            $result = $this->noneDB->find($dbName, ['index' => $i]);

            $this->assertSame([['index' => $i, 'key' => $i]], $result, "Should find record immediately after insert (iteration $i)");
        }
    }

    /**
     * @test
     */
    public function updateThenImmediateRead(): void
    {
        $dbName = 'update-read-test';

        $this->noneDB->insert($dbName, ['counter' => 0]);

        for ($i = 1; $i <= 10; $i++) {
            // Update
            $this->noneDB->update($dbName, [
                ['counter' => $i - 1],
                ['set' => ['counter' => $i]]
            ]);

            // Immediate read
            $result = $this->noneDB->find($dbName, ['counter' => $i]);

            $this->assertSame([['counter' => $i, 'key' => 0]], $result, "Should find updated record (iteration $i)");
            $this->assertSame([], $this->noneDB->find($dbName, ['counter' => $i - 1]), 'the old version is gone');
        }
    }

    /**
     * @test
     */
    public function clearStatCacheEffectiveness(): void
    {
        $dbName = 'statcache-test';

        // Insert initial data
        $this->noneDB->insert($dbName, ['value' => 'initial']);
        $this->noneDB->flush($dbName);

        // Read to populate stat cache
        $result1 = $this->noneDB->find($dbName, 0);
        $this->assertSame([['value' => 'initial', 'key' => 0]], $result1);

        // Update using API (direct file modification not supported in JSONL)
        $this->noneDB->update($dbName, [
            ['value' => 'initial'],
            ['set' => ['value' => 'modified']]
        ]);

        // Read again - should get updated data
        $result2 = $this->noneDB->find($dbName, 0);

        $this->assertSame([['value' => 'modified', 'key' => 0]], $result2);
    }

    /**
     * @test
     * Instances see each other's writes without any manual cache clearing: shared static caches are
     * validated against the database's lock state record, and with static caching disabled every instance keeps
     * its own cache, validated the same way (docs/STORAGE_FORMAT.md §4.2)
     */
    public function multipleInstancesConcurrent(): void
    {
        $dbName = 'multi-instance-test';

        // Test config for creating new instances
        $testConfig = [
            'secretKey' => 'test_secret_key_for_unit_tests',
            'dbDir' => TEST_DB_DIR,
            'autoCreateDB' => true
        ];

        foreach (['shared cache' => true, 'separate caches' => false] as $case => $shared) {
            \noneDB::clearStaticCache();
            if (!$shared) {
                \noneDB::disableStaticCache();
            }
            try {
                $name = $dbName . ($shared ? '-a' : '-b');
                $db1 = new \noneDB($testConfig);
                $db2 = new \noneDB($testConfig);
                $db3 = new \noneDB($testConfig);

                $db1->insert($name, ['from' => 'db1']);
                $this->assertSame([['from' => 'db1', 'key' => 0]], $db2->find($name, 0), $case);
                $this->assertSame([['from' => 'db1', 'key' => 0]], $db1->find($name, 0), "$case: db1 has its index cached now");

                $db3->insert($name, ['from' => 'db3']);
                $expected = [['from' => 'db1', 'key' => 0], ['from' => 'db3', 'key' => 1]];
                $this->assertSame($expected, $db1->find($name, 0), "$case: db1 sees db3's insert");
                $this->assertSame($expected, $db2->find($name, 0), "$case: db2 sees db3's insert");

                $db2->update($name, [['from' => 'db1'], ['set' => ['seen' => true]]]);
                $db1->delete($name, ['from' => 'db3']);
                $this->assertSame([['from' => 'db1', 'seen' => true, 'key' => 0]], $db3->find($name, 0), $case);
                $db3->insert($name, ['from' => 'db3 again']);
                $this->assertSame([0, 2], array_column($db1->find($name, 0), 'key'), "$case: the key of the deleted record is not reused");
            } finally {
                \noneDB::enableStaticCache();
            }
        }
    }

    /**
     * @test
     */
    public function fileLockPreventsCorruption(): void
    {
        $dbName = 'lock-test';

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

        // Exactly one record, with the final value, still key 0
        $this->assertSame([['id' => 100, 'key' => 0]], $result);
    }

    /**
     * @test
     */
    public function retryMechanismDoesNotHang(): void
    {
        $dbName = 'retry-test';

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
        $dbName = 'empty-file-test';

        // Create DB
        $this->noneDB->createDB($dbName);

        // Read from empty file
        $result = $this->noneDB->find($dbName, 0);

        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function largeDataHandling(): void
    {
        $dbName = 'large-data-test';

        // Insert large record
        $largeData = [
            'content' => str_repeat('x', 100000), // 100KB of data
            'metadata' => array_fill(0, 100, 'item')
        ];

        $result = $this->noneDB->insert($dbName, $largeData);
        $this->assertSame(['n' => 1], $result);

        // Read back
        $found = $this->noneDB->find($dbName, 0);

        $this->assertSame([$largeData + ['key' => 0]], $found);
    }

    /**
     * @test
     */
    public function writeReadWriteSequence(): void
    {
        $dbName = 'wrw-test';

        // Write
        $this->noneDB->insert($dbName, ['step' => 1]);

        // Read
        $r1 = $this->noneDB->find($dbName, 0);
        $this->assertSame([['step' => 1, 'key' => 0]], $r1);

        // Write
        $this->noneDB->insert($dbName, ['step' => 2]);

        // Read
        $r2 = $this->noneDB->find($dbName, 0);
        $this->assertSame([['step' => 1, 'key' => 0], ['step' => 2, 'key' => 1]], $r2);

        // Write
        $this->noneDB->update($dbName, [
            ['step' => 1],
            ['set' => ['step' => 3]]
        ]);

        // Final read
        $r3 = $this->noneDB->find($dbName, ['step' => 3]);
        $this->assertSame([['step' => 3, 'key' => 0]], $r3);
        $this->assertSame([3, 2], array_column($this->noneDB->find($dbName, 0), 'step'));
    }

    /**
     * @test
     */
    public function getDBsDoesNotBlockOperations(): void
    {
        $dbName = 'getdbs-test';

        $this->noneDB->insert($dbName, ['data' => 'test']);

        // Alternating getDBs and CRUD operations
        for ($i = 0; $i < 10; $i++) {
            $this->noneDB->getDBs(true);
            $this->noneDB->insert($dbName, ['index' => $i]);
            $this->noneDB->getDBs($dbName);
            $this->noneDB->find($dbName, ['index' => $i]);
        }

        // Verify data integrity: 1 initial + 10 loop inserts, keys 0..10
        $all = $this->noneDB->find($dbName, 0);
        $this->assertSame(range(0, 10), array_column($all, 'key'));
        $this->assertSame(range(0, 9), array_column(array_slice($all, 1), 'index'));
    }
}
