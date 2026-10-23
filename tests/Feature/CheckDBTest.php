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
        // v3.2.0: invalid names are rejected instead of being silently stripped
        $this->expectException(\InvalidArgumentException::class);
        $this->noneDB->checkDB('@#$%^&*()');
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
        // v3.2.0: names with characters outside [A-Za-z0-9' -] are rejected, nothing is created
        try {
            $this->noneDB->checkDB('test<script>db');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid database name', $e->getMessage());
        }
        $this->assertDatabaseNotExists('testscriptdb');
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
        // v3.2.0: non-ASCII characters are rejected ('çalışan' used to collide with 'alan')
        try {
            $this->noneDB->checkDB('veritabanı');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid database name', $e->getMessage());
        }
        $this->assertDatabaseNotExists('veritaban');
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
        // v3.2.0: "0" is a valid database name (it used to be treated as empty)
        $this->assertTrue($this->noneDB->checkDB(0));
        $this->noneDB->insert('0', ['v' => 1]);
        $this->assertCount(1, $this->noneDB->find('0', 0));
    }
}
