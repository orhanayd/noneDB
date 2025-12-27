<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for query methods: distinct(), like(), between()
 */
class QueryMethodsTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Insert test data
        $data = [
            ['name' => 'John', 'email' => 'john@gmail.com', 'city' => 'Istanbul', 'age' => 25],
            ['name' => 'Jane', 'email' => 'jane@yahoo.com', 'city' => 'Ankara', 'age' => 30],
            ['name' => 'Bob', 'email' => 'bob@gmail.com', 'city' => 'Istanbul', 'age' => 35],
            ['name' => 'Alice', 'email' => 'alice@hotmail.com', 'city' => 'Izmir', 'age' => 28],
            ['name' => 'Johnson', 'email' => 'johnson@gmail.com', 'city' => 'Ankara', 'age' => 40],
        ];

        $this->noneDB->insert($this->testDbName, $data);
    }

    // ==========================================
    // DISTINCT TESTS
    // ==========================================

    /**
     * @test
     */
    public function distinctReturnsUniqueValues(): void
    {
        $result = $this->noneDB->distinct($this->testDbName, 'city');

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertContains('Istanbul', $result);
        $this->assertContains('Ankara', $result);
        $this->assertContains('Izmir', $result);
    }

    /**
     * @test
     */
    public function distinctReturnsAllValuesWhenUnique(): void
    {
        $result = $this->noneDB->distinct($this->testDbName, 'name');

        $this->assertCount(5, $result);
    }

    /**
     * @test
     */
    public function distinctReturnsEmptyForNonExistentField(): void
    {
        $result = $this->noneDB->distinct($this->testDbName, 'nonexistent');

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function distinctReturnsEmptyForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->distinct('emptydb', 'field');

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function distinctPreservesDataTypes(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Test', 'active' => true],
            ['name' => 'Test2', 'active' => false],
            ['name' => 'Test3', 'active' => true],
        ]);

        $result = $this->noneDB->distinct($this->testDbName, 'active');

        $this->assertCount(2, $result);
        $this->assertContains(true, $result);
        $this->assertContains(false, $result);
    }

    // ==========================================
    // LIKE TESTS
    // ==========================================

    /**
     * @test
     */
    public function likeFindsContainingPattern(): void
    {
        $result = $this->noneDB->like($this->testDbName, 'email', 'gmail');

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
    }

    /**
     * @test
     */
    public function likeStartsWithPattern(): void
    {
        $result = $this->noneDB->like($this->testDbName, 'name', '^John');

        $this->assertIsArray($result);
        $this->assertCount(2, $result); // John and Johnson
    }

    /**
     * @test
     */
    public function likeEndsWithPattern(): void
    {
        $result = $this->noneDB->like($this->testDbName, 'email', 'com$');

        $this->assertIsArray($result);
        $this->assertCount(5, $result);
    }

    /**
     * @test
     */
    public function likeIsCaseInsensitive(): void
    {
        $result = $this->noneDB->like($this->testDbName, 'name', 'JOHN');

        $this->assertCount(2, $result); // John and Johnson
    }

    /**
     * @test
     */
    public function likeReturnsEmptyForNoMatch(): void
    {
        $result = $this->noneDB->like($this->testDbName, 'email', 'outlook');

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function likeHandlesSpecialChars(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Test', 'email' => 'test+tag@gmail.com'],
        ]);

        $result = $this->noneDB->like($this->testDbName, 'email', 'test+tag');

        $this->assertCount(1, $result);
    }

    /**
     * @test
     */
    public function likeWorksWithNonExistentField(): void
    {
        $result = $this->noneDB->like($this->testDbName, 'nonexistent', 'pattern');

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // ==========================================
    // BETWEEN TESTS
    // ==========================================

    /**
     * @test
     */
    public function betweenFindsRecordsInRange(): void
    {
        $result = $this->noneDB->between($this->testDbName, 'age', 25, 30);

        $this->assertIsArray($result);
        $this->assertCount(3, $result); // 25, 30, 28
    }

    /**
     * @test
     */
    public function betweenIncludesBoundaries(): void
    {
        $result = $this->noneDB->between($this->testDbName, 'age', 25, 25);

        $this->assertCount(1, $result);
        $this->assertEquals(25, $result[0]['age']);
    }

    /**
     * @test
     */
    public function betweenReturnsEmptyForNoMatch(): void
    {
        $result = $this->noneDB->between($this->testDbName, 'age', 50, 60);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function betweenWithFilter(): void
    {
        $result = $this->noneDB->between($this->testDbName, 'age', 25, 35, ['city' => 'Istanbul']);

        $this->assertCount(2, $result); // John (25) and Bob (35)
    }

    /**
     * @test
     */
    public function betweenWorksWithStrings(): void
    {
        $result = $this->noneDB->between($this->testDbName, 'name', 'A', 'C');

        $this->assertIsArray($result);
        $this->assertCount(2, $result); // Alice and Bob
    }

    /**
     * @test
     */
    public function betweenWithNonExistentField(): void
    {
        $result = $this->noneDB->between($this->testDbName, 'nonexistent', 0, 100);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * @test
     */
    public function betweenWithEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->between('emptydb', 'age', 0, 100);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }
}
