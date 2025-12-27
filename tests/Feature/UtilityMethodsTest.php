<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for utility methods: first(), last(), exists(), sort()
 */
class UtilityMethodsTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Insert test data
        $data = [
            ['name' => 'Alice', 'age' => 25, 'score' => 85],
            ['name' => 'Bob', 'age' => 30, 'score' => 90],
            ['name' => 'Charlie', 'age' => 35, 'score' => 75],
            ['name' => 'David', 'age' => 28, 'score' => 95],
            ['name' => 'Eve', 'age' => 22, 'score' => 80],
        ];

        $this->noneDB->insert($this->testDbName, $data);
    }

    // ==========================================
    // FIRST TESTS
    // ==========================================

    /**
     * @test
     */
    public function firstReturnsFirstRecord(): void
    {
        $result = $this->noneDB->first($this->testDbName);

        $this->assertIsArray($result);
        $this->assertEquals('Alice', $result['name']);
    }

    /**
     * @test
     */
    public function firstWithFilter(): void
    {
        $result = $this->noneDB->first($this->testDbName, ['age' => 30]);

        $this->assertIsArray($result);
        $this->assertEquals('Bob', $result['name']);
    }

    /**
     * @test
     */
    public function firstReturnsNullForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->first('emptydb');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function firstReturnsNullForNoMatch(): void
    {
        $result = $this->noneDB->first($this->testDbName, ['age' => 99]);

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function firstIncludesKeyField(): void
    {
        $result = $this->noneDB->first($this->testDbName);

        $this->assertArrayHasKey('key', $result);
        $this->assertEquals(0, $result['key']);
    }

    // ==========================================
    // LAST TESTS
    // ==========================================

    /**
     * @test
     */
    public function lastReturnsLastRecord(): void
    {
        $result = $this->noneDB->last($this->testDbName);

        $this->assertIsArray($result);
        $this->assertEquals('Eve', $result['name']);
    }

    /**
     * @test
     */
    public function lastWithFilter(): void
    {
        $result = $this->noneDB->last($this->testDbName, ['age' => 30]);

        $this->assertIsArray($result);
        $this->assertEquals('Bob', $result['name']);
    }

    /**
     * @test
     */
    public function lastReturnsNullForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->last('emptydb');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function lastReturnsNullForNoMatch(): void
    {
        $result = $this->noneDB->last($this->testDbName, ['age' => 99]);

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function lastIncludesKeyField(): void
    {
        $result = $this->noneDB->last($this->testDbName);

        $this->assertArrayHasKey('key', $result);
        $this->assertEquals(4, $result['key']);
    }

    /**
     * @test
     */
    public function lastWithMultipleMatches(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Frank', 'age' => 25, 'score' => 70],
        ]);

        $result = $this->noneDB->last($this->testDbName, ['age' => 25]);

        $this->assertEquals('Frank', $result['name']);
    }

    // ==========================================
    // EXISTS TESTS
    // ==========================================

    /**
     * @test
     */
    public function existsReturnsTrueWhenFound(): void
    {
        $result = $this->noneDB->exists($this->testDbName, ['name' => 'Alice']);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function existsReturnsFalseWhenNotFound(): void
    {
        $result = $this->noneDB->exists($this->testDbName, ['name' => 'Nonexistent']);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function existsReturnsFalseForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->exists('emptydb', ['name' => 'Test']);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function existsWithMultipleFilters(): void
    {
        $result = $this->noneDB->exists($this->testDbName, ['name' => 'Alice', 'age' => 25]);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function existsWithPartialMatch(): void
    {
        $result = $this->noneDB->exists($this->testDbName, ['name' => 'Alice', 'age' => 99]);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function existsAfterDelete(): void
    {
        $this->noneDB->delete($this->testDbName, ['name' => 'Alice']);
        $result = $this->noneDB->exists($this->testDbName, ['name' => 'Alice']);

        $this->assertFalse($result);
    }

    // ==========================================
    // SORT TESTS
    // ==========================================

    /**
     * @test
     */
    public function sortAscending(): void
    {
        $data = $this->noneDB->find($this->testDbName, 0);
        $result = $this->noneDB->sort($data, 'age', 'asc');

        $this->assertIsArray($result);
        $this->assertEquals(22, $result[0]['age']);
        $this->assertEquals(35, $result[4]['age']);
    }

    /**
     * @test
     */
    public function sortDescending(): void
    {
        $data = $this->noneDB->find($this->testDbName, 0);
        $result = $this->noneDB->sort($data, 'age', 'desc');

        $this->assertIsArray($result);
        $this->assertEquals(35, $result[0]['age']);
        $this->assertEquals(22, $result[4]['age']);
    }

    /**
     * @test
     */
    public function sortByString(): void
    {
        $data = $this->noneDB->find($this->testDbName, 0);
        $result = $this->noneDB->sort($data, 'name', 'asc');

        $this->assertEquals('Alice', $result[0]['name']);
        $this->assertEquals('Eve', $result[4]['name']);
    }

    /**
     * @test
     */
    public function sortReturnsFalseForEmptyArray(): void
    {
        $result = $this->noneDB->sort([], 'age');

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function sortReturnsFalseForNonArray(): void
    {
        $result = $this->noneDB->sort('not an array', 'age');

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function sortDefaultsToAsc(): void
    {
        $data = $this->noneDB->find($this->testDbName, 0);
        $result = $this->noneDB->sort($data, 'score');

        $this->assertEquals(75, $result[0]['score']);
        $this->assertEquals(95, $result[4]['score']);
    }

    /**
     * @test
     */
    public function sortPreservesAllFields(): void
    {
        $data = $this->noneDB->find($this->testDbName, 0);
        $result = $this->noneDB->sort($data, 'age');

        $this->assertArrayHasKey('name', $result[0]);
        $this->assertArrayHasKey('age', $result[0]);
        $this->assertArrayHasKey('score', $result[0]);
        $this->assertArrayHasKey('key', $result[0]);
    }

    /**
     * @test
     */
    public function sortIsCaseInsensitiveForOrder(): void
    {
        $data = $this->noneDB->find($this->testDbName, 0);
        $result = $this->noneDB->sort($data, 'age', 'DESC');

        $this->assertEquals(35, $result[0]['age']);
    }

    /**
     * @test
     */
    public function sortWithMissingField(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Frank'], // No age field
        ]);

        $data = $this->noneDB->find($this->testDbName, 0);
        $result = $this->noneDB->sort($data, 'age');

        // Should still return array, records with missing field stay in place
        $this->assertIsArray($result);
        $this->assertCount(6, $result);
    }
}
