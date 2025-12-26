<?php

namespace noneDB\Tests\Unit;

use noneDB\Tests\noneDBTestCase;

/**
 * Unit tests for the private hashDBName() method
 *
 * Tests the PBKDF2-SHA256 hashing functionality used to secure database names.
 */
class HashDBNameTest extends noneDBTestCase
{
    /**
     * @test
     */
    public function hashReturns20Characters(): void
    {
        $method = $this->getPrivateMethod('hashDBName');
        $hash = $method->invoke($this->noneDB, 'testdb');

        $this->assertEquals(20, strlen($hash), 'Hash should be exactly 20 characters');
    }

    /**
     * @test
     */
    public function hashIsConsistentForSameInput(): void
    {
        $method = $this->getPrivateMethod('hashDBName');

        $hash1 = $method->invoke($this->noneDB, 'mydb');
        $hash2 = $method->invoke($this->noneDB, 'mydb');

        $this->assertEquals($hash1, $hash2, 'Same input should produce same hash');
    }

    /**
     * @test
     */
    public function differentInputsProduceDifferentHashes(): void
    {
        $method = $this->getPrivateMethod('hashDBName');

        $hash1 = $method->invoke($this->noneDB, 'database1');
        $hash2 = $method->invoke($this->noneDB, 'database2');

        $this->assertNotEquals($hash1, $hash2, 'Different inputs should produce different hashes');
    }

    /**
     * @test
     */
    public function hashWorksWithEmptyString(): void
    {
        $method = $this->getPrivateMethod('hashDBName');
        $hash = $method->invoke($this->noneDB, '');

        $this->assertEquals(20, strlen($hash), 'Empty string should still produce 20 char hash');
        $this->assertIsString($hash, 'Hash should be a string');
    }

    /**
     * @test
     */
    public function hashWorksWithSpecialCharacters(): void
    {
        $method = $this->getPrivateMethod('hashDBName');
        $hash = $method->invoke($this->noneDB, "test'db-name 123");

        $this->assertEquals(20, strlen($hash), 'Special chars should produce 20 char hash');
    }

    /**
     * @test
     */
    public function hashWorksWithUnicodeCharacters(): void
    {
        $method = $this->getPrivateMethod('hashDBName');
        $hash = $method->invoke($this->noneDB, 'veritabanı');

        $this->assertEquals(20, strlen($hash), 'Unicode chars should produce 20 char hash');
    }

    /**
     * @test
     */
    public function hashWorksWithVeryLongString(): void
    {
        $method = $this->getPrivateMethod('hashDBName');
        $longString = str_repeat('a', 10000);
        $hash = $method->invoke($this->noneDB, $longString);

        $this->assertEquals(20, strlen($hash), 'Very long string should produce 20 char hash');
    }

    /**
     * @test
     */
    public function hashOnlyContainsHexCharacters(): void
    {
        $method = $this->getPrivateMethod('hashDBName');
        $hash = $method->invoke($this->noneDB, 'testdb');

        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $hash, 'Hash should only contain hex characters');
    }

    /**
     * @test
     */
    public function hashIsCaseSensitive(): void
    {
        $method = $this->getPrivateMethod('hashDBName');

        $hash1 = $method->invoke($this->noneDB, 'TestDB');
        $hash2 = $method->invoke($this->noneDB, 'testdb');

        $this->assertNotEquals($hash1, $hash2, 'Hash should be case sensitive');
    }

    /**
     * @test
     */
    public function hashWithWhitespace(): void
    {
        $method = $this->getPrivateMethod('hashDBName');

        $hash1 = $method->invoke($this->noneDB, 'test db');
        $hash2 = $method->invoke($this->noneDB, 'testdb');

        $this->assertNotEquals($hash1, $hash2, 'Whitespace should affect hash');
    }
}
