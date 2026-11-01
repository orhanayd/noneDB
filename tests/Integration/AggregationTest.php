<?php

namespace noneDB\Tests\Integration;

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

    /**
     * sum()/avg() of nothing: 0 (the docblock allows int or float; null, false or "0" would be wrong)
     */
    private function assertZero($value, string $message = ''): void
    {
        $this->assertNumber(0, $value, $message);
    }

    /**
     * noneDB::sum()/avg() return int or float (docblock): the value must be a number equal to $expected
     */
    private function assertNumber($expected, $actual, string $message = ''): void
    {
        $this->assertTrue((is_int($actual) || is_float($actual)) && (float)$actual === (float)$expected,
            $message . ': ' . var_export($actual, true) . ' is not the number ' . var_export($expected, true));
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

        $this->assertNumber(28, $result); // 10+5+3+8+2
    }

    /**
     * @test
     */
    public function sumWithFilter(): void
    {
        $result = $this->noneDB->sum($this->testDbName, 'price', ['category' => 'fruit']);

        $this->assertNumber(23, $result); // 10+5+8
    }

    /**
     * @test
     */
    public function sumReturnsZeroForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->sum('emptydb', 'price');

        $this->assertZero($result);
    }

    /**
     * @test
     */
    public function sumReturnsZeroForNonExistentField(): void
    {
        $result = $this->noneDB->sum($this->testDbName, 'nonexistent');

        $this->assertZero($result);
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

        $this->assertNumber(28, $result); // Ignores 'free'
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

        $this->assertSame(29.5, $result);
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

        $this->assertSame(5.6, $result); // 28/5
    }

    /**
     * @test
     */
    public function avgWithFilter(): void
    {
        $result = $this->noneDB->avg($this->testDbName, 'price', ['category' => 'vegetable']);

        $this->assertSame(2.5, $result); // (3+2)/2
    }

    /**
     * @test
     */
    public function avgReturnsZeroForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->avg('emptydb', 'price');

        $this->assertZero($result);
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
        $this->assertSame(5.6, $result);
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

        $this->assertSame(2, $result);
    }

    /**
     * @test
     */
    public function minWithFilter(): void
    {
        $result = $this->noneDB->min($this->testDbName, 'price', ['category' => 'fruit']);

        $this->assertSame(5, $result);
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

        $this->assertSame('Apple', $result);
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

        $this->assertSame(10, $result);
    }

    /**
     * @test
     */
    public function maxWithFilter(): void
    {
        $result = $this->noneDB->max($this->testDbName, 'price', ['category' => 'vegetable']);

        $this->assertSame(3, $result);
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

        $this->assertSame('Potato', $result);
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

        $this->assertSame(5, $result);
    }

    /**
     * @test
     */
    public function countWithFilter(): void
    {
        $result = $this->noneDB->count($this->testDbName, ['category' => 'fruit']);

        $this->assertSame(3, $result);
    }

    /**
     * @test
     */
    public function countReturnsZeroForEmptyDB(): void
    {
        $this->noneDB->createDB('emptydb');
        $result = $this->noneDB->count('emptydb');

        $this->assertSame(0, $result);
    }

    /**
     * @test
     */
    public function countReturnsZeroForNoMatch(): void
    {
        $result = $this->noneDB->count($this->testDbName, ['category' => 'nonexistent']);

        $this->assertSame(0, $result);
    }

    /**
     * @test
     */
    public function countAfterDelete(): void
    {
        $this->assertSame(['n' => 2], $this->noneDB->delete($this->testDbName, ['category' => 'vegetable']));
        $result = $this->noneDB->count($this->testDbName);

        $this->assertSame(3, $result);
        $this->assertSame(0, $this->noneDB->count($this->testDbName, ['category' => 'vegetable']));
        $this->assertSame(['Apple', 'Banana', 'Orange'], array_column($this->noneDB->find($this->testDbName, 0), 'product'));
    }

    /**
     * @test
     */
    public function countWithMultipleFilters(): void
    {
        $result = $this->noneDB->count($this->testDbName, ['category' => 'fruit', 'price' => 10]);

        $this->assertSame(1, $result);
        $this->assertSame(0, $this->noneDB->count($this->testDbName, ['category' => 'fruit', 'price' => '10']), 'filters are strict');
    }

    // ==========================================
    // DOCUMENTED RULES (API.md "Aggregation", "Matching rules"; QUERY.md sort order)
    // ==========================================

    /**
     * @test
     */
    public function sumAndAvgCountNumericStringsButNotBooleansOrNull(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['product' => 'S', 'price' => '4', 'category' => 'other'],
            ['product' => 'T', 'price' => true, 'category' => 'other'],
            ['product' => 'N', 'price' => null, 'category' => 'other'],
            ['product' => 'A', 'price' => [7], 'category' => 'other'],
            ['product' => 'F', 'price' => '0.5', 'category' => 'other'],
        ]);
        $this->assertSame(32.5, $this->noneDB->sum($this->testDbName, 'price'), '28 + "4" + "0.5"');
        $this->assertSame(32.5 / 7, $this->noneDB->avg($this->testDbName, 'price'), 'seven numeric values');
        $this->assertNumber(4.5, $this->noneDB->sum($this->testDbName, 'price', ['category' => 'other']));
        $this->assertNumber(2.25, $this->noneDB->avg($this->testDbName, 'price', ['category' => 'other']));
    }

    /**
     * @test
     */
    public function minAndMaxUseTheSortOrderAndSkipNull(): void
    {
        // Order: null < booleans < numbers < numeric strings < other strings < arrays; null is not a value
        $this->noneDB->insert($this->testDbName, [
            ['product' => 'X', 'price' => null],
            ['product' => 'Y', 'price' => '1'],
        ]);
        $this->assertSame(2, $this->noneDB->min($this->testDbName, 'price'), 'null is skipped');
        $this->assertSame('1', $this->noneDB->max($this->testDbName, 'price'), 'numeric strings sort after numbers');
        $this->noneDB->insert($this->testDbName, [['product' => 'Z', 'price' => false], ['product' => 'W', 'price' => 'free'], ['product' => 'V', 'price' => [0]]]);
        $this->assertFalse($this->noneDB->min($this->testDbName, 'price'), 'booleans sort before numbers');
        $this->assertSame([0], $this->noneDB->max($this->testDbName, 'price'), 'arrays sort last');
        $this->assertSame('free', $this->noneDB->max($this->testDbName, 'price', ['product' => 'W']));
        $this->assertNull($this->noneDB->min($this->testDbName, 'price', ['product' => 'X']), 'only null: no value');
    }

    /**
     * @test
     */
    public function aggregatesWithInvalidFiltersFindNothing(): void
    {
        foreach (['fruit', false, 1.5, true] as $invalid) {
            $label = var_export($invalid, true);
            $this->assertSame(0, $this->noneDB->count($this->testDbName, $invalid), "count $label (API.md: counts 0)");
            $this->assertZero($this->noneDB->sum($this->testDbName, 'price', $invalid), "sum $label");
            $this->assertZero($this->noneDB->avg($this->testDbName, 'price', $invalid), "avg $label");
            $this->assertNull($this->noneDB->min($this->testDbName, 'price', $invalid), "min $label");
            $this->assertNull($this->noneDB->max($this->testDbName, 'price', $invalid), "max $label");
        }
        // 0 and null mean every record (API.md)
        $this->assertNumber(28, $this->noneDB->sum($this->testDbName, 'price', null));
        $this->assertNumber(28, $this->noneDB->sum($this->testDbName, 'price', 0));
        $this->assertSame(5, $this->noneDB->count($this->testDbName, null));
    }

    /**
     * @test
     */
    public function aggregatesWithKeyFilters(): void
    {
        $this->assertNumber(15, $this->noneDB->sum($this->testDbName, 'price', ['key' => [0, '1']]), '10 + 5');
        $this->assertNumber(13, $this->noneDB->sum($this->testDbName, 'price', ['key' => [0, 2, 99]]), '10 + 3');
        $this->assertSame(2, $this->noneDB->count($this->testDbName, ['key' => [0, '1', 0]]));
        $this->assertSame(0, $this->noneDB->count($this->testDbName, ['key' => '01']), 'not a canonical key');
        $this->assertSame(8, $this->noneDB->min($this->testDbName, 'price', ['key' => [3, 0]]));
        $this->assertSame(10, $this->noneDB->max($this->testDbName, 'price', ['key' => [3, 0]]));
        $this->assertNumber(9, $this->noneDB->avg($this->testDbName, 'price', ['key' => [3, 0]]));
    }

    /**
     * @test
     */
    public function aggregatesOnAShardedDatabase(): void
    {
        $db = new \noneDB(['secretKey' => 'test_secret_key_for_unit_tests', 'dbDir' => $this->testDbDir, 'shardSize' => 2]);
        $rows = [];
        for ($i = 1; $i <= 9; $i++) {
            $rows[] = ['n' => $i, 'odd' => $i % 2 === 1];
        }
        $db->insert('aggshard', $rows);
        $this->assertTrue($db->getShardInfo('aggshard')['sharded']);
        $this->assertNumber(45, $db->sum('aggshard', 'n'));
        $this->assertNumber(25, $db->sum('aggshard', 'n', ['odd' => true]), '1+3+5+7+9');
        $this->assertNumber(5, $db->avg('aggshard', 'n', ['odd' => true]));
        $this->assertSame(1, $db->min('aggshard', 'n'));
        $this->assertSame(9, $db->max('aggshard', 'n'));
        $this->assertSame(8, $db->max('aggshard', 'n', ['odd' => false]));
        $this->assertSame(9, $db->count('aggshard'));
        $this->assertSame(4, $db->count('aggshard', ['odd' => false]));
    }
}

