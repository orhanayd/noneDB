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

        $this->assertSame(array_slice($array, 0, 3), $result);
    }

    /**
     * @test
     */
    public function limitReturnsFirstNElements(): void
    {
        $array = $this->getSampleArray();
        $result = $this->noneDB->limit($array, 2);

        $this->assertSame([['id' => 1, 'name' => 'Item 1'], ['id' => 2, 'name' => 'Item 2']], $result);
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

        $this->assertFalse($this->noneDB->limit($array, 2.0), 'a float, even a whole one');
        $this->assertFalse($this->noneDB->limit($array, null));
        $this->assertFalse($this->noneDB->limit($array, true));
        $this->assertFalse($this->noneDB->limit($array), 'the default limit 0 is not a limit');
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
    public function emptyArrayReturnsEmptyArray(): void
    {
        // An empty result set limited is still an empty result set (not false)
        $this->assertSame([], $this->noneDB->limit([], 3));
        $this->assertSame([], $this->noneDB->limit([], 1));
        $this->assertFalse($this->noneDB->limit([], 0), 'an invalid limit is invalid also for an empty array');
    }

    /**
     * @test
     */
    public function limitExceedsArraySizeReturnsAll(): void
    {
        $array = $this->getSampleArray();
        $result = $this->noneDB->limit($array, 100);

        $this->assertSame($array, $result, 'Should return all elements when limit exceeds array size');
        $this->assertSame($array, $this->noneDB->limit($array, 5), 'limit equal to the size');
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

        $this->assertSame([['id' => 1, 'name' => 'Item 1']], $result);
    }

    /**
     * @test
     */
    public function preservesArrayStructure(): void
    {
        $array = $this->getSampleArray();
        $result = $this->noneDB->limit($array, 2);

        $this->assertSame($array[0], $result[0]);
        $this->assertSame($array[1], $result[1]);
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

        $this->assertSame([['username' => 'john', 'email' => 'john@test.com'], ['username' => 'jane', 'email' => 'jane@test.com']], $result);
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

        $this->assertSame([['id' => 1, 'active' => true, 'score' => 85.5], ['id' => 2, 'active' => false, 'score' => 92.0]], $result);
    }

    /**
     * @test
     */
    public function returnsAListOfTheFirstRecords(): void
    {
        $array = $this->getSampleArray();
        $result = $this->noneDB->limit($array, 3);

        $this->assertSame([0, 1, 2], array_keys($result));
        // A result with other keys (e.g. after array_filter) gives the first N records as a list
        $filtered = [3 => ['id' => 4], 7 => ['id' => 8], 9 => ['id' => 10]];
        $this->assertSame([['id' => 4], ['id' => 8]], $this->noneDB->limit($filtered, 2));
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

        $this->assertSame([['data' => ['nested' => 'value1']], ['data' => ['nested' => 'value2']]], $result);
    }
}
