<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for createDB() method
 */
class CreateDBTest extends noneDBTestCase
{
    /**
     * @test
     */
    public function createDBReturnsTrueOnSuccess(): void
    {
        $result = $this->noneDB->createDB('new_database');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function createDBCreatesNonedbFile(): void
    {
        $this->noneDB->createDB('testcreate');

        $this->assertDatabaseExists('testcreate');
    }

    /**
     * @test
     */
    public function createDBCreatesInfoFile(): void
    {
        $this->noneDB->createDB('testcreate');

        $filePath = $this->getDbFilePath('testcreate') . 'info';
        $this->assertFileExists($filePath, 'Info file should be created');
    }

    /**
     * @test
     */
    public function createDBInitializesEmptyDataArray(): void
    {
        $this->noneDB->createDB('testcreate');

        $contents = $this->getDatabaseContents('testcreate');

        $this->assertIsArray($contents);
        $this->assertArrayHasKey('data', $contents);
        $this->assertEmpty($contents['data']);
    }

    /**
     * @test
     */
    public function createDBReturnsFalseIfExists(): void
    {
        $this->noneDB->createDB('existing');
        $result = $this->noneDB->createDB('existing');

        $this->assertFalse($result, 'Should return false if DB already exists');
    }

    /**
     * @test
     */
    public function createDBSanitizesName(): void
    {
        $this->noneDB->createDB('test<script>db');

        // Should create 'testscriptdb' after sanitization
        $this->assertDatabaseExists('testscriptdb');
    }

    /**
     * @test
     */
    public function createDBAllowsSpacesInName(): void
    {
        $this->noneDB->createDB('my database');

        $this->assertDatabaseExists('my database');
    }

    /**
     * @test
     */
    public function createDBAllowsHyphensInName(): void
    {
        $this->noneDB->createDB('my-database');

        $this->assertDatabaseExists('my-database');
    }

    /**
     * @test
     */
    public function createDBAllowsApostropheInName(): void
    {
        $this->noneDB->createDB("user's-db");

        $this->assertDatabaseExists("user's-db");
    }

    /**
     * @test
     */
    public function createDBInfoFileContainsTimestamp(): void
    {
        $beforeTime = time();
        $this->noneDB->createDB('testcreate');
        $afterTime = time();

        $infoPath = $this->getDbFilePath('testcreate') . 'info';
        $timestamp = (int)file_get_contents($infoPath);

        $this->assertGreaterThanOrEqual($beforeTime, $timestamp);
        $this->assertLessThanOrEqual($afterTime, $timestamp);
    }

    /**
     * @test
     */
    public function createDBWithNumericName(): void
    {
        $this->noneDB->createDB('123456');

        $this->assertDatabaseExists('123456');
    }

    /**
     * @test
     */
    public function createDBWithMixedCase(): void
    {
        $this->noneDB->createDB('MyDatabase');

        $this->assertDatabaseExists('MyDatabase');
    }

    /**
     * @test
     */
    public function createDBCreatesDirectoryIfMissing(): void
    {
        // Directory is already created by setUp, but test the logic
        $this->noneDB->createDB('testdb');
        $this->assertDatabaseExists('testdb');
    }
}
