<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for find() method
 *
 * Comprehensive tests for querying and filtering records.
 */
class FindTest extends noneDBTestCase
{
    /**
     * Set up test data
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Insert sample data for most tests
        $data = [
            ['username' => 'john', 'email' => 'john@test.com', 'age' => 25, 'active' => true],
            ['username' => 'jane', 'email' => 'jane@test.com', 'age' => 30, 'active' => true],
            ['username' => 'bob', 'email' => 'bob@test.com', 'age' => 25, 'active' => false],
            ['username' => 'alice', 'email' => 'alice@test.com', 'age' => 35, 'active' => true],
        ];

        $this->noneDB->insert($this->testDbName, $data);
    }

    // ==========================================
    // FIND ALL RECORDS
    // ==========================================

    /**
     * @test
     */
    public function findAllWithInteger0(): void
    {
        $result = $this->noneDB->find($this->testDbName, 0);

        $this->assertIsArray($result);
        $this->assertCount(4, $result);
    }

    /**
     * @test
     */
    public function findAllReturnsAllRecords(): void
    {
        $result = $this->noneDB->find($this->testDbName, 0);

        $this->assertEquals('john', $result[0]['username']);
        $this->assertEquals('jane', $result[1]['username']);
        $this->assertEquals('bob', $result[2]['username']);
        $this->assertEquals('alice', $result[3]['username']);
    }

    /**
     * @test
     */
    public function findAllAddsKeyField(): void
    {
        // Key IS added for all find results for consistency
        $result = $this->noneDB->find($this->testDbName, 0);

        // Each record should have 'key' field with its index
        $this->assertArrayHasKey('key', $result[0]);
        $this->assertEquals(0, $result[0]['key']);
        $this->assertEquals(1, $result[1]['key']);
    }

    // ==========================================
    // FIND BY KEY
    // ==========================================

    /**
     * @test
     */
    public function findBySingleKey(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['key' => 0]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('john', $result[0]['username']);
        $this->assertEquals(0, $result[0]['key']);
    }

    /**
     * @test
     */
    public function findByMultipleKeys(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['key' => [0, 2]]);

        $this->assertCount(2, $result);
        $this->assertEquals('john', $result[0]['username']);
        $this->assertEquals('bob', $result[1]['username']);
    }

    /**
     * @test
     */
    public function findByKeyReturnsKeyInResult(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['key' => 1]);

        $this->assertArrayHasKey('key', $result[0]);
        $this->assertEquals(1, $result[0]['key']);
    }

    /**
     * @test
     */
    public function findByNonExistentKey(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['key' => 999]);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function findByKeyArrayWithNonExistent(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['key' => [0, 999, 1]]);

        // Should return only existing keys
        $this->assertCount(2, $result);
    }

    /**
     * @test
     */
    public function findByLastKey(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['key' => 3]);

        $this->assertCount(1, $result);
        $this->assertEquals('alice', $result[0]['username']);
    }

    // ==========================================
    // FIND BY FIELD FILTER
    // ==========================================

    /**
     * @test
     */
    public function findBySingleField(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertCount(1, $result);
        $this->assertEquals('john', $result[0]['username']);
    }

    /**
     * @test
     */
    public function findBySingleFieldReturnsKeyField(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertArrayHasKey('key', $result[0]);
        $this->assertEquals(0, $result[0]['key']);
    }

    /**
     * @test
     */
    public function findByMultipleFieldsWithANDLogic(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['age' => 25, 'active' => true]);

        // Only john has age=25 AND active=true
        $this->assertCount(1, $result);
        $this->assertEquals('john', $result[0]['username']);
    }

    /**
     * @test
     */
    public function findNoMatchReturnsEmptyArray(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['username' => 'nonexistent']);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function findMultipleMatches(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['age' => 25]);

        // john and bob both have age=25
        $this->assertCount(2, $result);
    }

    /**
     * @test
     */
    public function findByBooleanField(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['active' => false]);

        $this->assertCount(1, $result);
        $this->assertEquals('bob', $result[0]['username']);
    }

    /**
     * @test
     */
    public function findByIntegerField(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['age' => 35]);

        $this->assertCount(1, $result);
        $this->assertEquals('alice', $result[0]['username']);
    }

    /**
     * @test
     */
    public function findByNonExistentField(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['nonexistent' => 'value']);

        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function findUsesStrictTypeComparison(): void
    {
        // Insert data with different types
        $this->noneDB->insert('typetest', ['value' => '25']);
        $this->noneDB->insert('typetest', ['value' => 25]);

        // Should use strict comparison
        $result = $this->noneDB->find('typetest', ['value' => 25]);

        // Only integer 25 should match
        $this->assertCount(1, $result);
    }

    // ==========================================
    // FIND WITH NULL RECORDS
    // ==========================================

    /**
     * @test
     */
    public function findSkipsNullRecords(): void
    {
        // Delete a record (sets it to null)
        $this->noneDB->delete($this->testDbName, ['username' => 'jane']);

        $result = $this->noneDB->find($this->testDbName, 0);

        // Should still return 4 items (null is included in raw data)
        // But find with filter should skip null
        $filtered = $this->noneDB->find($this->testDbName, ['active' => true]);

        // jane was deleted, so only john and alice should match
        $this->assertCount(2, $filtered);
    }

    /**
     * @test
     */
    public function findAfterDeleteReturnsCorrectRecords(): void
    {
        $this->noneDB->delete($this->testDbName, ['username' => 'bob']);

        $result = $this->noneDB->find($this->testDbName, ['age' => 25]);

        // Only john should remain with age=25
        $this->assertCount(1, $result);
        $this->assertEquals('john', $result[0]['username']);
    }

    // ==========================================
    // EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function findNonExistentDB(): void
    {
        $result = $this->noneDB->find('nonexistent_db', 0);

        // Should auto-create and return empty
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function findEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');

        $result = $this->noneDB->find('emptydb', 0);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function findPreservesAllFields(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertArrayHasKey('username', $result[0]);
        $this->assertArrayHasKey('email', $result[0]);
        $this->assertArrayHasKey('age', $result[0]);
        $this->assertArrayHasKey('active', $result[0]);
    }

    /**
     * @test
     */
    public function findWithEmptyFilter(): void
    {
        $result = $this->noneDB->find($this->testDbName, []);

        // Empty array filter should return all records (same as filter 0)
        $this->assertCount(4, $result);
    }

    /**
     * @test
     */
    public function findWithNullValue(): void
    {
        $this->noneDB->insert('nulltest', ['field' => null, 'other' => 'test']);

        $result = $this->noneDB->find('nulltest', ['field' => null]);

        $this->assertCount(1, $result);
    }

    /**
     * @test
     */
    public function findWithEmptyStringValue(): void
    {
        $this->noneDB->insert('emptytest', ['field' => '', 'other' => 'test']);

        $result = $this->noneDB->find('emptytest', ['field' => '']);

        $this->assertCount(1, $result);
    }

    /**
     * @test
     */
    public function findResultsAreIndexedFromZero(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['age' => 25]);

        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
    }

    /**
     * @test
     */
    public function findWithSpecialCharsInDBName(): void
    {
        $this->noneDB->insert('test<>db', ['data' => 'test']);

        $result = $this->noneDB->find('test<>db', 0);

        $this->assertCount(1, $result);
    }

    /**
     * @test
     */
    public function findWithZeroAsFieldValue(): void
    {
        $this->noneDB->insert('zerotest', ['value' => 0]);

        $result = $this->noneDB->find('zerotest', ['value' => 0]);

        $this->assertCount(1, $result);
    }

    /**
     * @test
     */
    public function findWithFalseAsFieldValue(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['active' => false]);

        $this->assertCount(1, $result);
        $this->assertEquals('bob', $result[0]['username']);
    }

    /**
     * @test
     */
    public function findKeyFieldIsInteger(): void
    {
        $result = $this->noneDB->find($this->testDbName, ['username' => 'john']);

        $this->assertIsInt($result[0]['key']);
    }
}
