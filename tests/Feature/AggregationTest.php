<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for aggregation methods: sum(), avg(), min(), max(), count()
 */
class AggregationTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Insert test data
        $data = [
            ['product' => 'Apple', 'price' => 10, 'quantity' => 100, 'category' => 'fruit'],
            ['product' => 'Banana', 'price' => 5, 'quantity' => 150, 'category' => 'fruit'],
            ['product' => 'Carrot', 'price' => 3, 'quantity' => 200, 'category' => 'vegetable'],
            ['product' => 'Orange', 'price' => 8, 'quantity' => 80, 'category' => 'fruit'],
            ['product' => 'Potato', 'price' => 2, 'quantity' => 300, 'category' => 'vegetable'],
        ];

        $this->noneDB->insert($this->testDbName, $data);
    }

    // ==========================================
    // SUM TESTS
    // ==========================================

    /**
     * @test
     */
    public function sumCalculatesTotal(): void
    {
        $result = $this->noneDB->sum($this->testDbName, 'price');

        $this->assertEquals(28, $result); // 10+5+3+8+2
    }

    /**
     * @test
     */
    public function sumWithFilter(): void
    {
        $result = $this->noneDB->sum($this->testDbName, 'price', ['category' => 'fruit']);

        $this->assertEquals(23, $result); // 10+5+8
    }

    /**
     * @test
     */
    public function sumReturnsZeroForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->sum('emptydb', 'price');

        $this->assertEquals(0, $result);
    }

    /**
     * @test
     */
    public function sumReturnsZeroForNonExistentField(): void
    {
        $result = $this->noneDB->sum($this->testDbName, 'nonexistent');

        $this->assertEquals(0, $result);
    }

    /**
     * @test
     */
    public function sumIgnoresNonNumericValues(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['product' => 'Test', 'price' => 'free', 'category' => 'other'],
        ]);

        $result = $this->noneDB->sum($this->testDbName, 'price');

        $this->assertEquals(28, $result); // Ignores 'free'
    }

    /**
     * @test
     */
    public function sumWorksWithFloats(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['product' => 'Test', 'price' => 1.5, 'category' => 'other'],
        ]);

        $result = $this->noneDB->sum($this->testDbName, 'price');

        $this->assertEquals(29.5, $result);
    }

    // ==========================================
    // AVG TESTS
    // ==========================================

    /**
     * @test
     */
    public function avgCalculatesAverage(): void
    {
        $result = $this->noneDB->avg($this->testDbName, 'price');

        $this->assertEquals(5.6, $result); // 28/5
    }

    /**
     * @test
     */
    public function avgWithFilter(): void
    {
        $result = $this->noneDB->avg($this->testDbName, 'price', ['category' => 'vegetable']);

        $this->assertEquals(2.5, $result); // (3+2)/2
    }

    /**
     * @test
     */
    public function avgReturnsZeroForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->avg('emptydb', 'price');

        $this->assertEquals(0, $result);
    }

    /**
     * @test
     */
    public function avgIgnoresNonNumericValues(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['product' => 'Test', 'price' => 'free', 'category' => 'other'],
        ]);

        $result = $this->noneDB->avg($this->testDbName, 'price');

        // Should still be 28/5 = 5.6, ignoring 'free'
        $this->assertEquals(5.6, $result);
    }

    // ==========================================
    // MIN TESTS
    // ==========================================

    /**
     * @test
     */
    public function minFindsMinimum(): void
    {
        $result = $this->noneDB->min($this->testDbName, 'price');

        $this->assertEquals(2, $result);
    }

    /**
     * @test
     */
    public function minWithFilter(): void
    {
        $result = $this->noneDB->min($this->testDbName, 'price', ['category' => 'fruit']);

        $this->assertEquals(5, $result);
    }

    /**
     * @test
     */
    public function minReturnsNullForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->min('emptydb', 'price');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function minWorksWithStrings(): void
    {
        $result = $this->noneDB->min($this->testDbName, 'product');

        $this->assertEquals('Apple', $result);
    }

    /**
     * @test
     */
    public function minReturnsNullForNonExistentField(): void
    {
        $result = $this->noneDB->min($this->testDbName, 'nonexistent');

        $this->assertNull($result);
    }

    // ==========================================
    // MAX TESTS
    // ==========================================

    /**
     * @test
     */
    public function maxFindsMaximum(): void
    {
        $result = $this->noneDB->max($this->testDbName, 'price');

        $this->assertEquals(10, $result);
    }

    /**
     * @test
     */
    public function maxWithFilter(): void
    {
        $result = $this->noneDB->max($this->testDbName, 'price', ['category' => 'vegetable']);

        $this->assertEquals(3, $result);
    }

    /**
     * @test
     */
    public function maxReturnsNullForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->max('emptydb', 'price');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function maxWorksWithStrings(): void
    {
        $result = $this->noneDB->max($this->testDbName, 'product');

        $this->assertEquals('Potato', $result);
    }

    /**
     * @test
     */
    public function maxReturnsNullForNonExistentField(): void
    {
        $result = $this->noneDB->max($this->testDbName, 'nonexistent');

        $this->assertNull($result);
    }

    // ==========================================
    // COUNT TESTS
    // ==========================================

    /**
     * @test
     */
    public function countReturnsTotal(): void
    {
        $result = $this->noneDB->count($this->testDbName);

        $this->assertEquals(5, $result);
    }

    /**
     * @test
     */
    public function countWithFilter(): void
    {
        $result = $this->noneDB->count($this->testDbName, ['category' => 'fruit']);

        $this->assertEquals(3, $result);
    }

    /**
     * @test
     */
    public function countReturnsZeroForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->count('emptydb');

        $this->assertEquals(0, $result);
    }

    /**
     * @test
     */
    public function countReturnsZeroForNoMatch(): void
    {
        $result = $this->noneDB->count($this->testDbName, ['category' => 'nonexistent']);

        $this->assertEquals(0, $result);
    }

    /**
     * @test
     */
    public function countAfterDelete(): void
    {
        $this->noneDB->delete($this->testDbName, ['category' => 'vegetable']);
        $result = $this->noneDB->count($this->testDbName);

        $this->assertEquals(3, $result);
    }

    /**
     * @test
     */
    public function countWithMultipleFilters(): void
    {
        $result = $this->noneDB->count($this->testDbName, ['category' => 'fruit', 'price' => 10]);

        $this->assertEquals(1, $result);
    }
}
