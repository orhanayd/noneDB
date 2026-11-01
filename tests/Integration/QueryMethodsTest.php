<?php

namespace noneDB\Tests\Integration;

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

        // In order of first appearance (API.md distinct())
        $this->assertSame(['Istanbul', 'Ankara', 'Izmir'], $result);
    }

    /**
     * @test
     */
    public function distinctReturnsAllValuesWhenUnique(): void
    {
        $result = $this->noneDB->distinct($this->testDbName, 'name');

        $this->assertSame(['John', 'Jane', 'Bob', 'Alice', 'Johnson'], $result);
    }

    /**
     * @test
     */
    public function distinctReturnsEmptyForNonExistentField(): void
    {
        $result = $this->noneDB->distinct($this->testDbName, 'nonexistent');

        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function distinctReturnsEmptyForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->distinct('emptydb', 'field');

        $this->assertSame([], $result);
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

        $this->assertSame([true, false], $result);
        // Distinct by type: 1, 1.0 and "1" are three values; null is left out
        $this->noneDB->insert($this->testDbName, [['t' => 1], ['t' => '1'], ['t' => 1.0], ['t' => null], ['t' => 1], ['t' => true]]);
        $this->assertSame([1, '1', 1.0, true], $this->noneDB->distinct($this->testDbName, 't'));
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

        $this->assertSame(['John', 'Bob', 'Johnson'], array_column($result, 'name'));
        $this->assertSame([0, 2, 4], array_column($result, 'key'), 'records with their key, in key order');
    }

    /**
     * @test
     */
    public function likeStartsWithPattern(): void
    {
        $result = $this->noneDB->like($this->testDbName, 'name', '^John');

        $this->assertSame(['John', 'Johnson'], array_column($result, 'name'));
        $this->assertSame([], $this->noneDB->like($this->testDbName, 'name', '^ohn'), 'the start is anchored');
    }

    /**
     * @test
     */
    public function likeEndsWithPattern(): void
    {
        $result = $this->noneDB->like($this->testDbName, 'email', 'com$');

        $this->assertSame(['John', 'Jane', 'Bob', 'Alice', 'Johnson'], array_column($result, 'name'));
        $this->assertSame([], $this->noneDB->like($this->testDbName, 'email', 'gmail$'), 'the end is anchored');
        $this->assertSame(['Bob'], array_column($this->noneDB->like($this->testDbName, 'email', '^bob@gmail.com$'), 'name'), 'both anchors: the whole value');
    }

    /**
     * @test
     */
    public function likeIsCaseInsensitive(): void
    {
        $result = $this->noneDB->like($this->testDbName, 'name', 'JOHN');

        $this->assertSame(['John', 'Johnson'], array_column($result, 'name'));
        $this->assertSame(['Alice'], array_column($this->noneDB->like($this->testDbName, 'name', 'aLiCe'), 'name'));
    }

    /**
     * @test
     */
    public function likeReturnsEmptyForNoMatch(): void
    {
        $result = $this->noneDB->like($this->testDbName, 'email', 'outlook');

        $this->assertSame([], $result);
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

        $this->assertSame(['test+tag@gmail.com'], array_column($result, 'email'));
        // Every character but a leading ^ and a trailing $ is literal: "." and "+" are no regex operators
        $this->noneDB->insert($this->testDbName, [['name' => 'T2', 'email' => 'testtttag@gmail.com'], ['name' => 'T3', 'email' => 'aXb']]);
        $this->assertSame(['test+tag@gmail.com'], array_column($this->noneDB->like($this->testDbName, 'email', 'test+tag'), 'email'));
        $this->assertSame([], $this->noneDB->like($this->testDbName, 'email', 'a.b'));
        $this->assertSame([], $this->noneDB->like($this->testDbName, 'email', '.*'));
        $this->assertSame(['aXb'], array_column($this->noneDB->like($this->testDbName, 'email', '^axb$'), 'email'));
    }

    /**
     * @test
     */
    public function likeWorksWithNonExistentField(): void
    {
        $result = $this->noneDB->like($this->testDbName, 'nonexistent', 'pattern');

        $this->assertSame([], $result);
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

        $this->assertSame(['John', 'Jane', 'Alice'], array_column($result, 'name')); // 25, 30, 28 in key order
        $this->assertSame([0, 1, 3], array_column($result, 'key'));
    }

    /**
     * @test
     */
    public function betweenIncludesBoundaries(): void
    {
        $result = $this->noneDB->between($this->testDbName, 'age', 25, 25);

        $this->assertSame(['John'], array_column($result, 'name'));
        $this->assertSame(25, $result[0]['age']);
        $this->assertSame(['Jane', 'Bob', 'Johnson'], array_column($this->noneDB->between($this->testDbName, 'age', 30, 40), 'name'), 'both ends inclusive');
    }

    /**
     * @test
     */
    public function betweenReturnsEmptyForNoMatch(): void
    {
        $result = $this->noneDB->between($this->testDbName, 'age', 50, 60);

        $this->assertSame([], $result);
        $this->assertSame([], $this->noneDB->between($this->testDbName, 'age', 40, 25), 'min above max: nothing');
    }

    /**
     * @test
     */
    public function betweenWithFilter(): void
    {
        $result = $this->noneDB->between($this->testDbName, 'age', 25, 35, ['city' => 'Istanbul']);

        $this->assertSame(['John', 'Bob'], array_column($result, 'name')); // John (25) and Bob (35)
        $this->assertFalse($this->noneDB->between($this->testDbName, 'age', 25, 35, 'Istanbul'), 'an invalid filter: false');
        // A key list keeps its order, as in find() (API.md)
        $this->assertSame(['Jane', 'John', 'Bob'], array_column($this->noneDB->between($this->testDbName, 'age', 25, 35, ['key' => [1, '0', 2]]), 'name'));
        $this->assertSame(['Jane', 'John'], array_column($this->noneDB->between($this->testDbName, 'age', 25, 30, ['key' => [1, '0', 2]]), 'name'));
    }

    /**
     * @test
     */
    public function betweenWorksWithStrings(): void
    {
        $result = $this->noneDB->between($this->testDbName, 'name', 'A', 'C');

        // Strings compare lexically (byte order): Bob and Alice, in key order
        $this->assertSame(['Bob', 'Alice'], array_column($result, 'name'));
        $this->assertSame([], $this->noneDB->between($this->testDbName, 'name', 'a', 'c'), 'upper case sorts before lower case');
    }

    /**
     * @test
     */
    public function betweenWithNonExistentField(): void
    {
        $result = $this->noneDB->between($this->testDbName, 'nonexistent', 0, 100);

        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function betweenWithEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->between('emptydb', 'age', 0, 100);

        $this->assertSame([], $result);
    }

    /**
     * @test
     */
    public function betweenIsTypeAware(): void
    {
        // Numbers and numeric strings numerically; null, booleans, arrays and text against numbers never match
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'S', 'age' => '27'], ['name' => 'T', 'age' => true], ['name' => 'N', 'age' => null],
            ['name' => 'A', 'age' => [27]], ['name' => 'X', 'age' => 'abc'], ['name' => 'F', 'age' => 26.5],
        ]);
        $this->assertSame(['Alice', 'S', 'F'], array_column($this->noneDB->between($this->testDbName, 'age', 26, 29), 'name'));
        $this->assertSame(['Alice', 'S', 'F'], array_column($this->noneDB->query($this->testDbName)->between('age', 26, 29)->get(), 'name'));
    }

    /**
     * @test
     */
    public function likeNeverMatchesBooleansAndRejectsNonScalarPatterns(): void
    {
        $this->noneDB->insert($this->testDbName, [['name' => 'B1', 'flag' => true], ['name' => 'B2', 'flag' => 1], ['name' => 'B3', 'flag' => '1']]);
        $this->assertSame(['B2', 'B3'], array_column($this->noneDB->like($this->testDbName, 'flag', '1'), 'name'));
        $this->assertFalse($this->noneDB->like($this->testDbName, 'name', null));
        $this->assertFalse($this->noneDB->like($this->testDbName, 'name', ['J']));
        $this->assertSame(['Jane'], array_column($this->noneDB->like($this->testDbName, 'age', 30), 'name'), 'numbers are matched as text');
    }

    /**
     * @test
     */
    public function queryMethodsOnAMissingDatabase(): void
    {
        $before = glob($this->testDbDir . '*');
        $this->assertSame([], $this->noneDB->distinct('ghostdb', 'a'));
        $this->assertSame([], $this->noneDB->like('ghostdb', 'a', 'x'));
        $this->assertSame([], $this->noneDB->between('ghostdb', 'a', 1, 2));
        $this->assertSame($before, glob($this->testDbDir . '*'), 'reads create nothing');
        $strict = new \noneDB(['secretKey' => 'test_secret_key_for_unit_tests', 'dbDir' => $this->testDbDir, 'autoCreateDB' => false]);
        $this->assertFalse($strict->distinct('ghostdb', 'a'));
        $this->assertFalse($strict->like('ghostdb', 'a', 'x'));
        $this->assertFalse($strict->between('ghostdb', 'a', 1, 2));
    }
}
