<?php

namespace noneDB\Tests\Unit;

use noneDB\Tests\noneDBTestCase;

/**
 * Unit tests for the public limit() method
 *
 * Tests array slicing functionality similar to SQL LIMIT.
 */
class LimitTest extends noneDBTestCase
{
    /**
     * Sample multidimensional array for testing
     */
    private function getSampleArray(): array
    {
        return [
            ['id' => 1, 'name' => 'Item 1'],
            ['id' => 2, 'name' => 'Item 2'],
            ['id' => 3, 'name' => 'Item 3'],
            ['id' => 4, 'name' => 'Item 4'],
            ['id' => 5, 'name' => 'Item 5'],
        ];
    }

    /**
     * @test
     */
    public function validLimitReturnsCorrectCount(): void
    {
        $array = $this->getSampleArray();
        $result = $this->noneDB->limit($array, 3);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
    }

    /**
     * @test
     */
    public function limitReturnsFirstNElements(): void
    {
        $array = $this->getSampleArray();
        $result = $this->noneDB->limit($array, 2);

        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals(2, $result[1]['id']);
    }

    /**
     * @test
     */
    public function limitZeroReturnsFalse(): void
    {
        $array = $this->getSampleArray();
        $result = $this->noneDB->limit($array, 0);

        $this->assertFalse($result, 'Limit 0 should return false');
    }

    /**
     * @test
     */
    public function limitNegativeReturnsFalse(): void
    {
        $array = $this->getSampleArray();
        $result = $this->noneDB->limit($array, -1);

        $this->assertFalse($result, 'Negative limit should return false');
    }

    /**
     * @test
     */
    public function limitNonIntegerReturnsFalse(): void
    {
        $array = $this->getSampleArray();

        $result = $this->noneDB->limit($array, 2.5);
        $this->assertFalse($result, 'Float limit should return false');

        $result = $this->noneDB->limit($array, '3');
        $this->assertFalse($result, 'String limit should return false');
    }

    /**
     * @test
     */
    public function flatArrayReturnsFalse(): void
    {
        $flatArray = [1, 2, 3, 4, 5];
        $result = $this->noneDB->limit($flatArray, 3);

        $this->assertFalse($result, 'Flat array should return false');
    }

    /**
     * @test
     */
    public function emptyArrayReturnsFalse(): void
    {
        $result = $this->noneDB->limit([], 3);

        $this->assertFalse($result, 'Empty array should return false');
    }

    /**
     * @test
     */
    public function limitExceedsArraySizeReturnsAll(): void
    {
        $array = $this->getSampleArray();
        $result = $this->noneDB->limit($array, 100);

        $this->assertIsArray($result);
        $this->assertCount(5, $result, 'Should return all elements when limit exceeds array size');
    }

    /**
     * @test
     */
    public function nonArrayInputReturnsFalse(): void
    {
        $result = $this->noneDB->limit('not an array', 3);
        $this->assertFalse($result, 'String input should return false');

        $result = $this->noneDB->limit(123, 3);
        $this->assertFalse($result, 'Integer input should return false');

        $result = $this->noneDB->limit(null, 3);
        $this->assertFalse($result, 'Null input should return false');
    }

    /**
     * @test
     */
    public function limitOneReturnsFirstElement(): void
    {
        $array = $this->getSampleArray();
        $result = $this->noneDB->limit($array, 1);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['id']);
    }

    /**
     * @test
     */
    public function preservesArrayStructure(): void
    {
        $array = $this->getSampleArray();
        $result = $this->noneDB->limit($array, 2);

        $this->assertArrayHasKey('id', $result[0]);
        $this->assertArrayHasKey('name', $result[0]);
    }

    /**
     * @test
     */
    public function worksWithAssociativeInnerArrays(): void
    {
        $array = [
            ['username' => 'john', 'email' => 'john@test.com'],
            ['username' => 'jane', 'email' => 'jane@test.com'],
            ['username' => 'bob', 'email' => 'bob@test.com'],
        ];

        $result = $this->noneDB->limit($array, 2);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('john', $result[0]['username']);
    }

    /**
     * @test
     */
    public function worksWithMixedValueTypes(): void
    {
        $array = [
            ['id' => 1, 'active' => true, 'score' => 85.5],
            ['id' => 2, 'active' => false, 'score' => 92.0],
            ['id' => 3, 'active' => true, 'score' => 78.25],
        ];

        $result = $this->noneDB->limit($array, 2);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertTrue($result[0]['active']);
        $this->assertEquals(85.5, $result[0]['score']);
    }

    /**
     * @test
     */
    public function preservesNumericArrayKeys(): void
    {
        $array = $this->getSampleArray();
        $result = $this->noneDB->limit($array, 3);

        // Keys should be reindexed (0, 1, 2)
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
    }

    /**
     * @test
     */
    public function nestedMultidimensionalArrayWorks(): void
    {
        $array = [
            ['data' => ['nested' => 'value1']],
            ['data' => ['nested' => 'value2']],
            ['data' => ['nested' => 'value3']],
        ];

        $result = $this->noneDB->limit($array, 2);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('value1', $result[0]['data']['nested']);
    }
}
