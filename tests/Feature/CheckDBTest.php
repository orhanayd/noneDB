<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for checkDB() method
 */
class CheckDBTest extends noneDBTestCase
{
    /**
     * @test
     */
    public function checkDBReturnsFalseForNull(): void
    {
        $result = $this->noneDB->checkDB(null);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function checkDBReturnsFalseForEmptyString(): void
    {
        $result = $this->noneDB->checkDB('');

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function checkDBReturnsFalseForOnlySpecialChars(): void
    {
        $result = $this->noneDB->checkDB('@#$%^&*()');

        $this->assertFalse($result, 'Only special chars should return false after sanitization');
    }

    /**
     * @test
     */
    public function checkDBReturnsTrueForExistingDB(): void
    {
        $this->noneDB->createDB('existing');

        $result = $this->noneDB->checkDB('existing');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function checkDBAutoCreatesWhenEnabled(): void
    {
        // autoCreateDB is true by default
        $result = $this->noneDB->checkDB('autotest');

        $this->assertTrue($result);
        $this->assertDatabaseExists('autotest');
    }

    /**
     * @test
     */
    public function checkDBReturnsFalseWhenAutoCreateDisabled(): void
    {
        $this->setPrivateProperty('autoCreateDB', false);

        $result = $this->noneDB->checkDB('nonexistent');

        $this->assertFalse($result);
        $this->assertDatabaseNotExists('nonexistent');
    }

    /**
     * @test
     */
    public function checkDBSanitizesDBName(): void
    {
        $result = $this->noneDB->checkDB('test<script>db');

        $this->assertTrue($result);
        $this->assertDatabaseExists('testscriptdb');
    }

    /**
     * @test
     */
    public function checkDBAllowsAlphanumeric(): void
    {
        $result = $this->noneDB->checkDB('TestDB123');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function checkDBAllowsSpaces(): void
    {
        $result = $this->noneDB->checkDB('my test db');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function checkDBAllowsHyphens(): void
    {
        $result = $this->noneDB->checkDB('my-test-db');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function checkDBAllowsApostrophes(): void
    {
        $result = $this->noneDB->checkDB("user's-db");

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function checkDBRemovesUnicodeChars(): void
    {
        $result = $this->noneDB->checkDB('veritabanı');

        $this->assertTrue($result);
        // 'ı' and 'ş' etc should be removed
        $this->assertDatabaseExists('veritaban');
    }

    /**
     * @test
     */
    public function checkDBCreatesDbDirectory(): void
    {
        $dbDir = $this->getPrivateProperty('dbDir');

        // Directory should exist
        $this->assertDirectoryExists($dbDir);
    }

    /**
     * @test
     */
    public function checkDBWithVeryLongName(): void
    {
        $longName = str_repeat('a', 100);
        $result = $this->noneDB->checkDB($longName);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function checkDBReturnsFalseForFalseValue(): void
    {
        // Testing with boolean false
        $result = @$this->noneDB->checkDB(false);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function checkDBReturnsFalseForZero(): void
    {
        // Testing with integer 0
        $result = $this->noneDB->checkDB(0);

        $this->assertFalse($result);
    }
}
