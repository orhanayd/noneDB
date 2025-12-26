<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for getDBs() method
 */
class GetDBsTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create some test databases
        $this->noneDB->createDB('testdb1');
        $this->noneDB->createDB('testdb2');
        $this->noneDB->createDB('testdb3');
    }

    // ==========================================
    // GET ALL DATABASES
    // ==========================================

    /**
     * @test
     */
    public function getDBsWithoutInfoReturnsArray(): void
    {
        $result = $this->noneDB->getDBs(false);

        $this->assertIsArray($result);
    }

    /**
     * @test
     */
    public function getDBsWithoutInfoReturnsNames(): void
    {
        $result = $this->noneDB->getDBs(false);

        $this->assertContains('testdb1', $result);
        $this->assertContains('testdb2', $result);
        $this->assertContains('testdb3', $result);
    }

    /**
     * @test
     */
    public function getDBsWithInfoReturnsArray(): void
    {
        $result = $this->noneDB->getDBs(true);

        $this->assertIsArray($result);
    }

    /**
     * @test
     */
    public function getDBsWithInfoReturnsMetadata(): void
    {
        $result = $this->noneDB->getDBs(true);

        $this->assertNotEmpty($result);

        $firstDb = $result[0];
        $this->assertArrayHasKey('name', $firstDb);
        $this->assertArrayHasKey('createdTime', $firstDb);
        $this->assertArrayHasKey('size', $firstDb);
    }

    /**
     * @test
     */
    public function getDBsCreatedTimeIsInteger(): void
    {
        $result = $this->noneDB->getDBs(true);

        $this->assertIsInt($result[0]['createdTime']);
    }

    /**
     * @test
     */
    public function getDBsSizeIsString(): void
    {
        $result = $this->noneDB->getDBs(true);

        $this->assertIsString($result[0]['size']);
    }

    // ==========================================
    // GET SINGLE DATABASE
    // ==========================================

    /**
     * @test
     */
    public function getDBsSingleDatabaseReturnsInfo(): void
    {
        $result = $this->noneDB->getDBs('testdb1');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
        $this->assertEquals('testdb1', $result['name']);
    }

    /**
     * @test
     */
    public function getDBsSingleDatabaseHasCreatedTime(): void
    {
        $result = $this->noneDB->getDBs('testdb1');

        $this->assertArrayHasKey('createdTime', $result);
        $this->assertIsInt($result['createdTime']);
    }

    /**
     * @test
     */
    public function getDBsSingleDatabaseHasSize(): void
    {
        $result = $this->noneDB->getDBs('testdb1');

        $this->assertArrayHasKey('size', $result);
        $this->assertIsString($result['size']);
    }

    /**
     * @test
     */
    public function getDBsNonExistentDBReturnsFalse(): void
    {
        // Disable auto-create
        $this->setPrivateProperty('autoCreateDB', false);

        $result = $this->noneDB->getDBs('nonexistent');

        $this->assertFalse($result);
    }

    // ==========================================
    // MULTIPLE CALLS TEST
    // ==========================================

    /**
     * @test
     */
    public function getDBsMultipleCallsNoError(): void
    {
        // This tests the fix for "Cannot redeclare FileSizeConvert()"
        $result1 = $this->noneDB->getDBs(true);
        $result2 = $this->noneDB->getDBs(true);
        $result3 = $this->noneDB->getDBs(true);

        $this->assertIsArray($result1);
        $this->assertIsArray($result2);
        $this->assertIsArray($result3);
    }

    /**
     * @test
     */
    public function getDBsWithAndWithoutInfoAlternating(): void
    {
        $withInfo = $this->noneDB->getDBs(true);
        $withoutInfo = $this->noneDB->getDBs(false);
        $withInfo2 = $this->noneDB->getDBs(true);

        $this->assertIsArray($withInfo);
        $this->assertIsArray($withoutInfo);
        $this->assertIsArray($withInfo2);
    }

    // ==========================================
    // FILE SIZE TESTS
    // ==========================================

    /**
     * @test
     */
    public function getDBsFileSizeIncreasesWithData(): void
    {
        // Get initial size
        $before = $this->noneDB->getDBs('testdb1');

        // Add some data
        $this->noneDB->insert('testdb1', array_fill(0, 100, ['data' => 'test value']));

        // Get new size
        $after = $this->noneDB->getDBs('testdb1');

        // Sizes should be different (assuming the format is parseable)
        $this->assertNotEquals($before['size'], $after['size']);
    }

    /**
     * @test
     */
    public function getDBsEmptyDBHasMinimalSize(): void
    {
        $result = $this->noneDB->getDBs('testdb1');

        // Empty DB should have size like "14 B" or similar (for {"data":[]})
        $this->assertStringContainsString('B', $result['size']);
    }

    // ==========================================
    // EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function getDBsWithSpecialCharsInName(): void
    {
        $this->noneDB->createDB('test-special db');

        $result = $this->noneDB->getDBs('test-special db');

        $this->assertIsArray($result);
        $this->assertEquals('test-special db', $result['name']);
    }

    /**
     * @test
     */
    public function getDBsWithNumericName(): void
    {
        $this->noneDB->createDB('12345');

        $result = $this->noneDB->getDBs('12345');

        $this->assertIsArray($result);
        $this->assertEquals('12345', $result['name']);
    }

    /**
     * @test
     */
    public function getDBsCreatedTimeIsRecent(): void
    {
        $beforeCreate = time();
        $this->noneDB->createDB('timetest');
        $afterCreate = time();

        $result = $this->noneDB->getDBs('timetest');

        $this->assertGreaterThanOrEqual($beforeCreate, $result['createdTime']);
        $this->assertLessThanOrEqual($afterCreate, $result['createdTime']);
    }

    /**
     * @test
     */
    public function getDBsEmptyDirectoryReturnsEmptyArray(): void
    {
        // Clean all databases
        $this->cleanTestDirectory();

        $result = $this->noneDB->getDBs(false);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }
}
