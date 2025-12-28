<?php

namespace noneDB\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Base test case class for noneDB tests
 *
 * Provides common setup, teardown, and utility methods for all tests.
 */
abstract class noneDBTestCase extends TestCase
{
    /**
     * @var \noneDB
     */
    protected $noneDB;

    /**
     * @var string Test database directory
     */
    protected $testDbDir;

    /**
     * @var string Test database name
     */
    protected $testDbName = 'phpunit_test_db';

    /**
     * Set up test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->testDbDir = TEST_DB_DIR;

        // Clean test directory before each test
        $this->cleanTestDirectory();

        // Clear config cache to ensure fresh config loading
        \noneDB::clearConfigCache();

        // Create fresh noneDB instance with test config
        $this->noneDB = new \noneDB([
            'secretKey' => 'test_secret_key_for_unit_tests',
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => true,
            'shardingEnabled' => true,
            'shardSize' => 10000,
            'autoMigrate' => true
        ]);

        // Buffer is enabled by default (v2.3.0+)
        // getDatabaseContents() flushes buffer automatically for consistency
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void
    {
        // Clean test directory after each test
        $this->cleanTestDirectory();

        parent::tearDown();
    }

    /**
     * Remove all files from test database directory
     */
    protected function cleanTestDirectory(): void
    {
        // Clear PHP's file stat cache
        clearstatcache(true);

        // Clear noneDB's static cache to prevent cross-test pollution
        \noneDB::clearStaticCache();

        // Clear config cache for fresh config on each test
        \noneDB::clearConfigCache();

        if (!file_exists($this->testDbDir)) {
            mkdir($this->testDbDir, 0777, true);
            return;
        }

        $files = glob($this->testDbDir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                clearstatcache(true, $file);
            }
        }

        // Clear stat cache again after cleanup
        clearstatcache(true);
    }

    /**
     * Get a private/protected method for testing
     *
     * @param string $methodName The name of the method
     * @return ReflectionMethod
     */
    protected function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $reflector = new ReflectionClass(\noneDB::class);
        $method = $reflector->getMethod($methodName);
        $method->setAccessible(true);

        return $method;
    }

    /**
     * Get a private/protected property value
     *
     * @param string $propertyName The name of the property
     * @return mixed
     */
    protected function getPrivateProperty(string $propertyName)
    {
        $reflector = new ReflectionClass(\noneDB::class);
        $property = $reflector->getProperty($propertyName);
        $property->setAccessible(true);

        return $property->getValue($this->noneDB);
    }

    /**
     * Set a private/protected property value
     *
     * @param string $propertyName The name of the property
     * @param mixed $value The value to set
     */
    protected function setPrivateProperty(string $propertyName, $value): void
    {
        $reflector = new ReflectionClass(\noneDB::class);
        $property = $reflector->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($this->noneDB, $value);
    }

    /**
     * Insert sample data for testing
     *
     * @param string $dbName Database name
     * @param int $count Number of records to insert
     * @return array The inserted data
     */
    protected function insertSampleData(string $dbName, int $count = 3): array
    {
        $data = [];
        for ($i = 1; $i <= $count; $i++) {
            $data[] = [
                'username' => 'user' . $i,
                'email' => 'user' . $i . '@example.com',
                'age' => 20 + $i,
                'active' => ($i % 2 === 0)
            ];
        }

        $this->noneDB->insert($dbName, $data);

        return $data;
    }

    /**
     * Get the database file path
     *
     * @param string $dbName Database name
     * @return string Full path to database file
     */
    protected function getDbFilePath(string $dbName): string
    {
        // Sanitize name the same way noneDB does
        $sanitizedName = preg_replace("/[^A-Za-z0-9' -]/", '', $dbName);

        $hashMethod = $this->getPrivateMethod('hashDBName');
        $hash = $hashMethod->invoke($this->noneDB, $sanitizedName);
        $dbDir = $this->getPrivateProperty('dbDir');

        return $dbDir . $hash . '-' . $sanitizedName . '.nonedb';
    }

    /**
     * Assert that database file exists
     *
     * @param string $dbName Database name
     */
    protected function assertDatabaseExists(string $dbName): void
    {
        $filePath = $this->getDbFilePath($dbName);
        $this->assertFileExists($filePath, "Database file should exist: {$dbName}");
    }

    /**
     * Assert that database file does not exist
     *
     * @param string $dbName Database name
     */
    protected function assertDatabaseNotExists(string $dbName): void
    {
        $filePath = $this->getDbFilePath($dbName);
        $this->assertFileDoesNotExist($filePath, "Database file should not exist: {$dbName}");
    }

    /**
     * Get database contents directly from file
     * Flushes any buffered data first to ensure consistency
     * v3.0: JSONL-only format - uses .jidx index to determine valid records
     *
     * @param string $dbName Database name
     * @return array|null Returns normalized format: ['data' => [...records...]]
     */
    protected function getDatabaseContents(string $dbName): ?array
    {
        // Flush buffer first to ensure all data is written to file
        $this->noneDB->flush($dbName);

        $filePath = $this->getDbFilePath($dbName);

        if (!file_exists($filePath)) {
            return null;
        }

        $contents = file_get_contents($filePath);
        $indexPath = $filePath . '.jidx';

        // JSONL format with index - only return records that exist in index
        $records = [];

        if (file_exists($indexPath)) {
            $index = json_decode(file_get_contents($indexPath), true);
            if ($index !== null && isset($index['o'])) {
                // Read all lines and filter by index
                $lines = explode("\n", trim($contents));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    $record = json_decode($line, true);
                    if ($record !== null && isset($record['key'])) {
                        $key = $record['key'];
                        // Only include records that exist in index (not deleted)
                        if (isset($index['o'][$key])) {
                            unset($record['key']);
                            $records[$key] = $record;
                        }
                    }
                }
            }
        } else {
            // No index file - read all records (legacy or new DB)
            $lines = explode("\n", trim($contents));
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $record = json_decode($line, true);
                if ($record !== null) {
                    $key = $record['key'] ?? count($records);
                    unset($record['key']);
                    $records[$key] = $record;
                }
            }
        }

        // Sort by key to maintain order
        ksort($records);
        return ['data' => array_values($records)];
    }

    /**
     * Create a new noneDB instance configured for test directory
     *
     * @return \noneDB
     */
    protected function createTestInstance(): \noneDB
    {
        return new \noneDB([
            'secretKey' => 'test_secret_key_for_unit_tests',
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => true,
            'shardingEnabled' => true,
            'shardSize' => 10000,
            'autoMigrate' => true
        ]);
    }
}
