<?php

namespace noneDB\Tests\Integration;

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

        $this->assertSame(['age' => 25, 'key' => 0, 'name' => 'Alice', 'score' => 85], $this->canon($result));
    }

    /**
     * @test
     */
    public function firstWithFilter(): void
    {
        $result = $this->noneDB->first($this->testDbName, ['age' => 30]);

        $this->assertSame(['age' => 30, 'key' => 1, 'name' => 'Bob', 'score' => 90], $this->canon($result));
        $this->assertNull($this->noneDB->first($this->testDbName, ['age' => '30']), 'filters are strict');
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
        $this->assertSame(0, $result['key']);
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

        $this->assertSame(['age' => 22, 'key' => 4, 'name' => 'Eve', 'score' => 80], $this->canon($result));
    }

    /**
     * @test
     */
    public function lastWithFilter(): void
    {
        $result = $this->noneDB->last($this->testDbName, ['age' => 30]);

        $this->assertSame(['age' => 30, 'key' => 1, 'name' => 'Bob', 'score' => 90], $this->canon($result));
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
        $this->assertSame(4, $result['key']);
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

        $this->assertSame(['age' => 25, 'key' => 5, 'name' => 'Frank', 'score' => 70], $this->canon($result));
        $this->assertSame('Alice', $this->noneDB->first($this->testDbName, ['age' => 25])['name']);
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

        $this->assertSame([22, 25, 28, 30, 35], array_column($result, 'age'));
        $this->assertSame(['Eve', 'Alice', 'David', 'Bob', 'Charlie'], array_column($result, 'name'));
    }

    /**
     * @test
     */
    public function sortDescending(): void
    {
        $data = $this->noneDB->find($this->testDbName, 0);
        $result = $this->noneDB->sort($data, 'age', 'desc');

        $this->assertSame([35, 30, 28, 25, 22], array_column($result, 'age'));
        $this->assertSame(['Charlie', 'Bob', 'David', 'Alice', 'Eve'], array_column($result, 'name'));
    }

    /**
     * @test
     */
    public function sortByString(): void
    {
        $data = $this->noneDB->find($this->testDbName, 0);
        $result = $this->noneDB->sort($data, 'name', 'asc');

        $this->assertSame(['Alice', 'Bob', 'Charlie', 'David', 'Eve'], array_column($result, 'name'));
        // Byte order (QUERY.md): upper case before lower case, "1a" before "Z"
        $mixed = [['name' => 'a'], ['name' => 'Z'], ['name' => '1a'], ['name' => 'B']];
        $this->assertSame(['1a', 'B', 'Z', 'a'], array_column($this->noneDB->sort($mixed, 'name'), 'name'));
    }

    /**
     * @test
     */
    public function sortReturnsEmptyArrayForEmptyInput(): void
    {
        // Sorting an empty result returns an empty result (not false)
        $this->assertSame([], $this->noneDB->sort([], 'age'));
        $this->assertFalse($this->noneDB->sort('not an array', 'age'));
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

        $this->assertSame([75, 80, 85, 90, 95], array_column($result, 'score'));
        $this->assertSame($result, $this->noneDB->sort($data, 'score', 'asc'));
        $this->assertSame($result, $this->noneDB->sort($data, 'score', 'other'), 'anything but desc is ascending');
    }

    /**
     * @test
     */
    public function sortPreservesAllFields(): void
    {
        $data = $this->noneDB->find($this->testDbName, 0);
        $result = $this->noneDB->sort($data, 'age');

        // The same records, untouched, in the new order
        $byKey = [];
        foreach ($data as $record) {
            $byKey[$record['key']] = $record;
        }
        $this->assertSame([$byKey[4], $byKey[0], $byKey[3], $byKey[1], $byKey[2]], $result);
    }

    /**
     * @test
     */
    public function sortIsCaseInsensitiveForOrder(): void
    {
        $data = $this->noneDB->find($this->testDbName, 0);
        $result = $this->noneDB->sort($data, 'age', 'DESC');

        $this->assertSame([35, 30, 28, 25, 22], array_column($result, 'age'));
        $this->assertSame($result, $this->noneDB->sort($data, 'age', 'Desc'));
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

        // A missing field sorts like null: first, reversed for desc (API.md sort())
        $this->assertSame(['Frank', 'Eve', 'Alice', 'David', 'Bob', 'Charlie'], array_column($result, 'name'));
        $this->assertSame(['Charlie', 'Bob', 'David', 'Alice', 'Eve', 'Frank'], array_column($this->noneDB->sort($data, 'age', 'desc'), 'name'));
    }

    /**
     * @test
     */
    public function sortIsStableForTies(): void
    {
        $rows = [['n' => 'a', 'v' => 2], ['n' => 'b', 'v' => 1], ['n' => 'c', 'v' => 2], ['n' => 'd', 'v' => 1.0], ['n' => 'e', 'v' => null], ['n' => 'f']];
        // Ties keep the input order, also with desc: 1 and 1.0 are the same number, null and missing the same
        $this->assertSame(['e', 'f', 'b', 'd', 'a', 'c'], array_column($this->noneDB->sort($rows, 'v'), 'n'));
        $this->assertSame(['a', 'c', 'b', 'd', 'e', 'f'], array_column($this->noneDB->sort($rows, 'v', 'desc'), 'n'));
    }

    /**
     * @test
     */
    public function firstLastExistsWithKeyFiltersKeepTheKeyListOrder(): void
    {
        $this->assertSame('David', $this->noneDB->first($this->testDbName, ['key' => [3, '1']])['name']);
        $this->assertSame('Bob', $this->noneDB->last($this->testDbName, ['key' => [3, '1']])['name']);
        $this->assertTrue($this->noneDB->exists($this->testDbName, ['key' => '2']));
        $this->assertFalse($this->noneDB->exists($this->testDbName, ['key' => '02']));
        $this->assertFalse($this->noneDB->exists($this->testDbName, ['key' => 2, 'name' => 'Bob']));
        $this->assertTrue($this->noneDB->exists($this->testDbName, ['key' => 2, 'name' => 'Charlie']));
        $this->assertNull($this->noneDB->first($this->testDbName, ['key' => 9]));
    }

    /**
     * @test
     */
    public function invalidFiltersFindNothing(): void
    {
        foreach (['Alice', false, 1.5] as $invalid) {
            $this->assertNull($this->noneDB->first($this->testDbName, $invalid));
            $this->assertNull($this->noneDB->last($this->testDbName, $invalid));
            $this->assertFalse($this->noneDB->exists($this->testDbName, $invalid));
        }
        // 0 and null mean every record
        $this->assertSame('Alice', $this->noneDB->first($this->testDbName, null)['name']);
        $this->assertSame('Eve', $this->noneDB->last($this->testDbName, 0)['name']);
        $this->assertTrue($this->noneDB->exists($this->testDbName, null));
    }

    /**
     * @test
     */
    public function utilityMethodsOnAMissingDatabaseCreateNothing(): void
    {
        $before = glob($this->testDbDir . '*');
        $this->assertNull($this->noneDB->first('ghostdb'));
        $this->assertNull($this->noneDB->last('ghostdb'));
        $this->assertFalse($this->noneDB->exists('ghostdb', ['a' => 1]));
        $this->assertSame($before, glob($this->testDbDir . '*'));
    }

    /**
     * Fields sorted by name (field order is not part of the contract)
     */
    private function canon(?array $record): ?array
    {
        if ($record === null) {
            return null;
        }
        ksort($record);
        return $record;
    }
}
