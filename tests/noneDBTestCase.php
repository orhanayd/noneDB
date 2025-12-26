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

        // Create fresh noneDB instance
        $this->noneDB = new \noneDB();

        // Set noneDB to use test directory
        $this->setPrivateProperty('dbDir', $this->testDbDir);
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
     *
     * @param string $dbName Database name
     * @return array|null
     */
    protected function getDatabaseContents(string $dbName): ?array
    {
        $filePath = $this->getDbFilePath($dbName);

        if (!file_exists($filePath)) {
            return null;
        }

        $contents = file_get_contents($filePath);
        return json_decode($contents, true);
    }

    /**
     * Create a new noneDB instance configured for test directory
     *
     * @return \noneDB
     */
    protected function createTestInstance(): \noneDB
    {
        $db = new \noneDB();
        $reflector = new ReflectionClass(\noneDB::class);
        $property = $reflector->getProperty('dbDir');
        $property->setAccessible(true);
        $property->setValue($db, $this->testDbDir);
        return $db;
    }
}
