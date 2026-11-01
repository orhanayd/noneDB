<?php
/**
 * Comparison Operator Tests
 * MongoDB-style operators: $gt, $gte, $lt, $lte, $ne, $eq, $in, $nin, $exists, $like, $regex, $contains
 * (docs/QUERY.md "Comparison Operators" and "How values compare"; docs/API.md "Comparison Operators").
 * Expected results are derived by hand from the fixture; results without sort() come in key order.
 */

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

class ComparisonOperatorTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keys 0..6
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25, 'salary' => 50000, 'active' => true, 'tags' => ['developer', 'frontend'], 'department' => 'Engineering'],
            ['name' => 'Bob', 'age' => 30, 'salary' => 75000, 'active' => true, 'tags' => ['developer', 'backend'], 'department' => 'Engineering'],
            ['name' => 'Charlie', 'age' => 35, 'salary' => 100000, 'active' => false, 'tags' => ['manager'], 'department' => 'Management'],
            ['name' => 'Diana', 'age' => 28, 'salary' => 60000, 'active' => true, 'tags' => ['designer'], 'department' => 'Design'],
            ['name' => 'Eve', 'age' => 45, 'salary' => 150000, 'active' => true, 'tags' => ['director', 'manager'], 'department' => 'Executive'],
            ['name' => 'Frank', 'age' => 22, 'salary' => 40000, 'active' => false, 'department' => 'Engineering'], // No tags
            ['name' => 'Grace', 'age' => 33, 'salary' => 85000, 'active' => true, 'tags' => ['developer', 'fullstack'], 'department' => 'Engineering'],
        ]);
    }

    private function names(array $rows): array
    {
        return array_column($rows, 'name');
    }

    private function where(array $filter, ?string $db = null): array
    {
        return $this->names($this->noneDB->query($db ?? $this->testDbName)->where($filter)->get());
    }

    /**
     * A database with one record per value type in field "v" (name = label), keys in this order
     */
    private function typedDb(): string
    {
        $this->noneDB->insert('typed', [
            ['name' => 'int30', 'v' => 30],
            ['name' => 'float18.5', 'v' => 18.5],
            ['name' => 'str30', 'v' => '30'],
            ['name' => 'str100', 'v' => '100'],
            ['name' => 'text', 'v' => 'abc'],
            ['name' => 'empty', 'v' => ''],
            ['name' => 'null', 'v' => null],
            ['name' => 'true', 'v' => true],
            ['name' => 'false', 'v' => false],
            ['name' => 'array', 'v' => [30]],
            ['name' => 'missing'],
            ['name' => 'zero', 'v' => 0],
            ['name' => 'neg', 'v' => -5],
        ]);
        return 'typed';
    }

    // ========== GREATER THAN ($gt) ==========

    public function testGreaterThan(): void
    {
        $this->assertSame(['Charlie', 'Eve', 'Grace'], $this->where(['age' => ['$gt' => 30]])); // 35, 45, 33 (30 excluded)
    }

    public function testGreaterThanFloat(): void
    {
        $this->assertSame(['Charlie', 'Eve', 'Grace'], $this->where(['salary' => ['$gt' => 75000.50]]));
        $this->assertSame(['Bob', 'Charlie', 'Eve', 'Grace'], $this->where(['salary' => ['$gt' => 74999.99]]));
    }

    public function testGreaterThanNoMatch(): void
    {
        $this->assertSame([], $this->where(['age' => ['$gt' => 100]]));
        $this->assertSame([], $this->where(['age' => ['$gt' => 45]]));
    }

    // ========== GREATER THAN OR EQUAL ($gte) ==========

    public function testGreaterThanOrEqual(): void
    {
        $this->assertSame(['Charlie', 'Eve'], $this->where(['age' => ['$gte' => 35]]));
    }

    public function testGreaterThanOrEqualBoundary(): void
    {
        // All except Frank (40000); Alice's 50000 is the boundary
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Grace'], $this->where(['salary' => ['$gte' => 50000]]));
        $this->assertSame(['Eve'], $this->where(['salary' => ['$gte' => 150000]]));
    }

    // ========== LESS THAN ($lt) ==========

    public function testLessThan(): void
    {
        $this->assertSame(['Alice', 'Frank'], $this->where(['age' => ['$lt' => 28]])); // 25, 22 (28 excluded)
    }

    public function testLessThanSalary(): void
    {
        $this->assertSame(['Alice', 'Frank'], $this->where(['salary' => ['$lt' => 60000]]));
    }

    // ========== LESS THAN OR EQUAL ($lte) ==========

    public function testLessThanOrEqual(): void
    {
        $this->assertSame(['Alice', 'Frank'], $this->where(['age' => ['$lte' => 25]]));
    }

    public function testLessThanOrEqualBoundary(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Diana', 'Frank'], $this->where(['salary' => ['$lte' => 75000]]));
    }

    // ========== NOT EQUAL ($ne) ==========

    public function testNotEqual(): void
    {
        $this->assertSame(['Charlie', 'Diana', 'Eve'], $this->where(['department' => ['$ne' => 'Engineering']]));
    }

    public function testNotEqualBoolean(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Diana', 'Eve', 'Grace'], $this->where(['active' => ['$ne' => false]]));
    }

    public function testNotEqualInteger(): void
    {
        $this->assertSame(['Alice', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace'], $this->where(['age' => ['$ne' => 30]]));
    }

    /**
     * $ne matches a record without the field (it is not equal to anything)
     */
    public function testNotEqualMatchesMissingField(): void
    {
        $this->assertSame(['Frank'], $this->where(['tags' => ['$ne' => ['manager']], 'name' => ['$ne' => 'Alice'], 'salary' => ['$lt' => 50000]]));
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace'], $this->where(['nickname' => ['$ne' => 'x']]));
    }

    // ========== EQUAL ($eq) ==========

    public function testEqual(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Frank', 'Grace'], $this->where(['department' => ['$eq' => 'Engineering']]));
        $this->assertSame([], $this->where(['nickname' => ['$eq' => null]]), 'a missing field is not null');
    }

    // ========== IN ($in) ==========

    public function testIn(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Diana', 'Frank', 'Grace'], $this->where(['department' => ['$in' => ['Engineering', 'Design']]]));
    }

    public function testInIntegers(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->where(['age' => ['$in' => [25, 30, 35]]]));
    }

    public function testInSingleValue(): void
    {
        $this->assertSame(['Alice'], $this->where(['name' => ['$in' => ['Alice']]]));
    }

    public function testInEmptyArray(): void
    {
        $this->assertSame([], $this->where(['department' => ['$in' => []]]));
    }

    /**
     * $in with more than 8 values (hash set) is as strict as with a short list
     */
    public function testInLongListIsStrict(): void
    {
        $values = ['25', 30.0, 35, true, null, 'x', 'y', 'z', 'w', 45];
        $this->assertSame(['Charlie', 'Eve'], $this->where(['age' => ['$in' => $values]]));
        $this->assertSame(['Alice', 'Bob', 'Diana', 'Frank', 'Grace'], $this->where(['age' => ['$nin' => $values]]));
    }

    // ========== NOT IN ($nin) ==========

    public function testNotIn(): void
    {
        $this->assertSame(['Charlie', 'Diana'], $this->where(['department' => ['$nin' => ['Engineering', 'Executive']]]));
    }

    public function testNotInIntegers(): void
    {
        $this->assertSame(['Bob', 'Charlie', 'Diana', 'Frank', 'Grace'], $this->where(['age' => ['$nin' => [25, 45]]]));
    }

    /**
     * $nin matches a record without the field; an empty list excludes nothing
     */
    public function testNotInMissingFieldAndEmptyList(): void
    {
        $this->assertSame(['Frank'], $this->where(['tags' => ['$nin' => [['manager']]], 'salary' => ['$lt' => 50000]]));
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace'], $this->where(['department' => ['$nin' => []]]));
    }

    // ========== EXISTS ($exists) ==========

    public function testExistsTrue(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Grace'], $this->where(['tags' => ['$exists' => true]]));
    }

    public function testExistsFalse(): void
    {
        $this->assertSame(['Frank'], $this->where(['tags' => ['$exists' => false]]));
    }

    public function testExistsNonExistentField(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace'], $this->where(['nonexistent' => ['$exists' => false]]));
        $this->assertSame([], $this->where(['nonexistent' => ['$exists' => true]]));
    }

    // ========== LIKE ($like) ==========

    public function testLikeContains(): void
    {
        $this->assertSame(['Alice', 'Charlie'], $this->where(['name' => ['$like' => 'li']]));
    }

    public function testLikeStartsWith(): void
    {
        $this->assertSame(['Alice'], $this->where(['name' => ['$like' => '^A']]));
        $this->assertSame(['Alice'], $this->where(['name' => ['$like' => '^a']]));
        $this->assertSame([], $this->where(['name' => ['$like' => '^lice']]));
    }

    public function testLikeEndsWith(): void
    {
        // case insensitive: Alice, Charlie, Eve, Grace end with 'e'
        $this->assertSame(['Alice', 'Charlie', 'Eve', 'Grace'], $this->where(['name' => ['$like' => 'e$']]));
        $this->assertSame(['Alice', 'Charlie', 'Eve', 'Grace'], $this->where(['name' => ['$like' => 'E$']]));
        // both anchors: the whole value
        $this->assertSame(['Eve'], $this->where(['name' => ['$like' => '^eve$']]));
        $this->assertSame([], $this->where(['name' => ['$like' => '^ev$']]));
    }

    public function testLikeCaseInsensitive(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Frank', 'Grace'], $this->where(['department' => ['$like' => 'ENGINEERING']]));
    }

    /**
     * $like is literal except a leading ^ and a trailing $: ".", "*", "%", "(", "/" match themselves
     */
    public function testLikeSpecialCharactersAreLiteral(): void
    {
        $this->noneDB->insert('patterns', [
            ['name' => 'abc'], ['name' => 'a.c'], ['name' => 'a*c'], ['name' => '100%'], ['name' => '(x)'], ['name' => 'http://a'],
            ['name' => 'price $5'], ['name' => '^start'],
        ]);
        $this->assertSame(['a.c'], $this->where(['name' => ['$like' => 'a.c']], 'patterns'));
        $this->assertSame(['a*c'], $this->where(['name' => ['$like' => 'a*c']], 'patterns'));
        $this->assertSame(['100%'], $this->where(['name' => ['$like' => '0%']], 'patterns'));
        $this->assertSame(['(x)'], $this->where(['name' => ['$like' => '(x)']], 'patterns'));
        $this->assertSame(['http://a'], $this->where(['name' => ['$like' => '^http://']], 'patterns'));
        // "$" is special only at the end, "^" only at the start
        $this->assertSame(['price $5'], $this->where(['name' => ['$like' => '$5']], 'patterns'));
        $this->assertSame(['^start'], $this->where(['name' => ['$like' => 'start']], 'patterns'));
        $this->assertSame(['^start'], $this->where(['name' => ['$like' => '^^start']], 'patterns'));
    }

    /**
     * $like matches strings and numbers; booleans, null, arrays and missing fields never match
     */
    public function testLikeValueTypes(): void
    {
        $db = $this->typedDb();
        $this->assertSame(['int30', 'str30'], $this->where(['v' => ['$like' => '30']], $db));
        $this->assertSame(['float18.5'], $this->where(['v' => ['$like' => '18.5']], $db));
        $this->assertSame(['neg'], $this->where(['v' => ['$like' => '^-5']], $db));
        $this->assertSame(['zero'], $this->where(['v' => ['$like' => '^0$']], $db));
        // true would be "1" and false "" as strings: booleans never match
        $this->assertSame(['float18.5', 'str100'], $this->where(['v' => ['$like' => '1']], $db));
        // an empty pattern matches every string and number, nothing else
        $this->assertSame(['int30', 'float18.5', 'str30', 'str100', 'text', 'empty', 'zero', 'neg'], $this->where(['v' => ['$like' => '']], $db));
    }

    // ========== REGEX ($regex) ==========

    public function testRegex(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana'], $this->where(['name' => ['$regex' => '^[A-D]']]));
        // case-insensitive
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana'], $this->where(['name' => ['$regex' => '^[a-d]']]));
    }

    public function testRegexWordPattern(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Frank', 'Grace'], $this->where(['department' => ['$regex' => '.*ing$']]));
    }

    /**
     * $regex: numbers are matched as strings; booleans, null, arrays and missing fields never; "~" needs no escaping
     */
    public function testRegexValueTypesAndDelimiter(): void
    {
        $db = $this->typedDb();
        $this->assertSame(['int30', 'str30', 'str100', 'zero'], $this->where(['v' => ['$regex' => '^[0-9]+$']], $db));
        $this->assertSame(['float18.5'], $this->where(['v' => ['$regex' => '^18\\.5$']], $db));
        $this->assertSame(['empty'], $this->where(['v' => ['$regex' => '^$']], $db));

        $this->noneDB->insert('tilde', [['p' => 'a~b'], ['p' => 'ab'], ['p' => 'a/b']]);
        $this->assertSame([0], array_column($this->noneDB->query('tilde')->where(['p' => ['$regex' => 'a~b']])->get(), 'key'));
        $this->assertSame([0], array_column($this->noneDB->query('tilde')->where(['p' => ['$regex' => 'a\\~b']])->get(), 'key'));
        $this->assertSame([2], array_column($this->noneDB->query('tilde')->where(['p' => ['$regex' => 'a/b']])->get(), 'key'));
    }

    // ========== CONTAINS ($contains) ==========

    public function testContainsArray(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Grace'], $this->where(['tags' => ['$contains' => 'developer']]));
        $this->assertSame([], $this->where(['tags' => ['$contains' => 'develop']]), 'array elements are compared whole');
    }

    public function testContainsString(): void
    {
        $this->assertSame(['Diana'], $this->where(['department' => ['$contains' => 'sign']]));
        // substring matching is case-sensitive (QUERY.md: use $like for case-insensitive matching)
        $this->assertSame([], $this->where(['department' => ['$contains' => 'SIGN']]));
        $this->assertSame(['Alice', 'Bob', 'Frank', 'Grace'], $this->where(['department' => ['$contains' => 'Engineer']]));
    }

    /**
     * $contains on arrays is strict (=== per element); a number or boolean field is neither an array nor a string
     */
    public function testContainsIsStrictAndTyped(): void
    {
        $this->noneDB->insert('lists', [
            ['name' => 'ints', 'l' => [1, 2, 3]],
            ['name' => 'strings', 'l' => ['1', '2']],
            ['name' => 'number', 'l' => 123],
            ['name' => 'bool', 'l' => true],
            ['name' => 'nested', 'l' => [[1]]],
        ]);
        $this->assertSame(['ints'], $this->where(['l' => ['$contains' => 1]], 'lists'));
        $this->assertSame(['strings'], $this->where(['l' => ['$contains' => '1']], 'lists'));
        $this->assertSame(['nested'], $this->where(['l' => ['$contains' => [1]]], 'lists'));
        $this->assertSame([], $this->where(['l' => ['$contains' => true]], 'lists'));
        $this->assertSame([], $this->where(['l' => ['$contains' => '12']], 'lists'), 'a number field is not a string');
    }

    // ========== COMBINED OPERATORS ==========

    public function testRangeQuery(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Grace'], $this->where(['age' => ['$gte' => 25, '$lte' => 35]]));
        // an empty range
        $this->assertSame([], $this->where(['age' => ['$gt' => 35, '$lt' => 45]]));
    }

    public function testMultiFieldOperators(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Diana'], $this->where([
            'age' => ['$gte' => 25],
            'salary' => ['$lt' => 80000],
            'active' => true
        ]));
    }

    public function testOperatorsWithEquality(): void
    {
        $this->assertSame(['Alice', 'Bob', 'Grace'], $this->where([
            'department' => 'Engineering',
            'age' => ['$gt' => 24]
        ]));
    }

    /**
     * Several where() calls are all applied (AND), also on the same field
     */
    public function testSeveralWhereCallsAreAnded(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$gte' => 25]])
            ->where(['age' => ['$lt' => 35]])
            ->where(['department' => ['$ne' => 'Design']])
            ->get();

        $this->assertSame(['Alice', 'Bob', 'Grace'], $this->names($results));
    }

    // ========== HOW VALUES COMPARE (type matrix) ==========

    /**
     * $gt/$gte/$lt/$lte: numbers and numeric strings numerically; null, booleans, arrays, missing fields and a
     * number against a non-numeric string never match
     */
    public function testRangeOperatorsTypeMatrix(): void
    {
        $db = $this->typedDb();

        $this->assertSame(['int30', 'float18.5', 'str30', 'str100'], $this->where(['v' => ['$gt' => 18]], $db));
        $this->assertSame(['int30', 'str30', 'str100'], $this->where(['v' => ['$gte' => 30]], $db));
        $this->assertSame(['float18.5', 'zero', 'neg'], $this->where(['v' => ['$lt' => 30]], $db));
        $this->assertSame(['int30', 'float18.5', 'str30', 'zero', 'neg'], $this->where(['v' => ['$lte' => 30]], $db));
        // a numeric string operand compares numerically too: "100" > "30" (lexically it would be smaller)
        $this->noneDB->insert('numbers', [['name' => 'int30', 'v' => 30], ['name' => 'str30', 'v' => '30'], ['name' => 'str100', 'v' => '100'], ['name' => 'float18.5', 'v' => 18.5]]);
        $this->assertSame(['str100'], $this->where(['v' => ['$gt' => '30']], 'numbers'));
        $this->assertSame(['int30', 'str30', 'str100'], $this->where(['v' => ['$gte' => '30.0']], 'numbers'));
        $this->assertSame(['float18.5'], $this->where(['v' => ['$lt' => '1e2', '$lte' => '18.5']], 'numbers'));
        // a non-numeric string operand compares only with strings, lexically
        $this->assertSame(['text'], $this->where(['v' => ['$gte' => 'a']], $db));
        // null, boolean and array operands never match
        foreach ([null, true, false, [30]] as $operand) {
            foreach (['$gt', '$gte', '$lt', '$lte'] as $op) {
                $this->assertSame([], $this->where(['v' => [$op => $operand]], $db), $op . ' ' . json_encode($operand));
            }
        }
    }

    /**
     * Strings compare lexically (dates in ISO format)
     */
    public function testRangeOperatorsCompareStringsLexically(): void
    {
        $this->noneDB->insert('events', [
            ['name' => 'feb', 'date' => '2024-02-01'],
            ['name' => 'jan15', 'date' => '2024-01-15'],
            ['name' => 'jan9', 'date' => '2024-01-09'],
            ['name' => 'dec', 'date' => '2023-12-31'],
        ]);
        $this->assertSame(['feb'], $this->where(['date' => ['$gt' => '2024-01-15']], 'events'));
        $this->assertSame(['feb', 'jan15'], $this->where(['date' => ['$gte' => '2024-01-15']], 'events'));
        $this->assertSame(['jan9', 'dec'], $this->where(['date' => ['$lt' => '2024-01-15']], 'events'));
        $this->assertSame(['jan15', 'jan9'], $this->where(['date' => ['$gte' => '2024-01-01', '$lte' => '2024-01-31']], 'events'));
    }

    /**
     * A NAN operand never matches (also $gt NAN matches no number), also not with $eq / $in
     */
    public function testNanOperandNeverMatches(): void
    {
        $db = $this->typedDb();
        foreach (['$gt', '$gte', '$lt', '$lte', '$eq'] as $op) {
            $this->assertSame([], $this->where(['v' => [$op => NAN]], $db), $op);
        }
        $this->assertSame([], $this->where(['v' => ['$in' => [NAN]]], $db));
        $this->assertSame([], $this->noneDB->query($db)->between('v', NAN, 1000)->get());
        $this->assertSame([], $this->noneDB->query($db)->between('v', -1000, NAN)->get());
    }

    /**
     * $eq, $ne, $in, $nin and plain values are strict (===): '30' is not 30, 30.0 is not 30, 0 is not false
     */
    public function testEqualityOperatorsAreStrict(): void
    {
        $db = $this->typedDb();
        $this->assertSame(['int30'], $this->where(['v' => ['$eq' => 30]], $db));
        $this->assertSame(['str30'], $this->where(['v' => ['$eq' => '30']], $db));
        $this->assertSame([], $this->where(['v' => ['$eq' => 30.0]], $db));
        $this->assertSame(['false'], $this->where(['v' => ['$eq' => false]], $db));
        $this->assertSame(['zero'], $this->where(['v' => ['$eq' => 0]], $db));
        $this->assertSame(['null'], $this->where(['v' => ['$eq' => null]], $db));
        $this->assertSame(['empty'], $this->where(['v' => ['$eq' => '']], $db));
        $this->assertSame(['array'], $this->where(['v' => ['$eq' => [30]]], $db));
        $this->assertSame([], $this->where(['v' => ['$eq' => ['30']]], $db));
        $this->assertSame(['int30', 'str30'], $this->where(['v' => ['$in' => [30, '30']]], $db));
        $this->assertSame(['null', 'false'], $this->where(['v' => ['$in' => [null, false]]], $db));

        $all = ['int30', 'float18.5', 'str30', 'str100', 'text', 'empty', 'null', 'true', 'false', 'array', 'missing', 'zero', 'neg'];
        $this->assertSame(array_values(array_diff($all, ['int30'])), $this->where(['v' => ['$ne' => 30]], $db));
        $this->assertSame(array_values(array_diff($all, ['str30', 'null'])), $this->where(['v' => ['$nin' => ['30', null]]], $db));
        // plain values: the same as $eq
        $this->assertSame(['int30'], $this->where(['v' => 30], $db));
        $this->assertSame(['true'], $this->where(['v' => true], $db));
    }

    /**
     * $exists: a field that holds null exists
     */
    public function testExistsWithNullValue(): void
    {
        $db = $this->typedDb();
        $this->assertSame(['missing'], $this->where(['v' => ['$exists' => false]], $db));
        $this->assertCount(12, $this->noneDB->query($db)->where(['v' => ['$exists' => true]])->get());
        $this->assertContains('null', $this->where(['v' => ['$exists' => true]], $db));
    }

    /**
     * Numeric strings with trailing whitespace: the sort order treats "30 " as the number 30 on every PHP version
     * (QUERY.md sort()); the range operators should then compare it numerically on every PHP version as well
     * (query results don't depend on the PHP version)
     */
    public function testNumericStringWithTrailingWhitespaceComparesNumericallyOnEveryVersion(): void
    {
        $this->noneDB->insert('ws', [
            ['name' => 'trailing', 'v' => '30 '],
            ['name' => 'leading', 'v' => ' 30'],
            ['name' => 'plain', 'v' => '30'],
        ]);

        $this->assertSame(['trailing', 'leading', 'plain'], $this->where(['v' => ['$gt' => 18]], 'ws'));
        $this->assertSame(['trailing', 'leading', 'plain'], $this->names($this->noneDB->query('ws')->between('v', 20, 40)->get()));
    }

    // ========== FILTER VALUE SHAPES AND ERRORS ==========

    /**
     * An array that is not made of operators is compared as a whole (===)
     */
    public function testPlainArrayValueIsComparedAsAWhole(): void
    {
        $this->assertSame(['Alice'], $this->where(['tags' => ['developer', 'frontend']]));
        $this->assertSame([], $this->where(['tags' => ['frontend', 'developer']]));
        $this->assertSame([], $this->where(['tags' => ['developer']]));

        $this->noneDB->insert('addresses', [
            ['name' => 'exact', 'addr' => ['city' => 'Ist']],
            ['name' => 'more', 'addr' => ['city' => 'Ist', 'zip' => 1]],
            ['name' => 'string', 'addr' => 'Ist'],
        ]);
        $this->assertSame(['exact'], $this->where(['addr' => ['city' => 'Ist']], 'addresses'));
    }

    /**
     * Operators and plain keys mixed in one value: InvalidArgumentException as soon as where()/orWhere() is called
     */
    public function testMixingOperatorsAndPlainKeysThrowsAtCall(): void
    {
        foreach (['where', 'orWhere'] as $method) {
            try {
                $this->noneDB->query($this->testDbName)->$method(['age' => ['$gt' => 18, 'max' => 65]]);
                $this->fail("$method() must throw");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('noneDB: a filter value cannot mix operators ($gt, $in, ...) with plain keys', $e->getMessage());
            }
        }
    }

    /**
     * An unknown operator throws when the query runs (not when where() is called), also on an empty database
     */
    public function testUnknownOperatorThrowsWhenTheQueryRuns(): void
    {
        $query = $this->noneDB->query($this->testDbName)->where(['age' => ['$gtee' => 18]]);
        $this->noneDB->createDB('emptydb');
        $empty = $this->noneDB->query('emptydb')->where(['age' => ['$gtee' => 18]]);

        foreach ([$query, $empty] as $q) {
            try {
                $q->get();
                $this->fail('get() must throw');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith("noneDB: unknown operator '\$gtee' (supported: ", $e->getMessage());
            }
        }
        foreach (['count', 'exists', 'first'] as $terminal) {
            try {
                $this->noneDB->query('emptydb')->where(['age' => ['$GT' => 1]])->$terminal();
                $this->fail("$terminal() must throw");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("unknown operator '\$GT'", $e->getMessage());
            }
        }
    }

    /**
     * $in / $nin without an array throw InvalidArgumentException when the query runs
     */
    public function testInAndNinNeedAnArray(): void
    {
        foreach (['$in', '$nin'] as $op) {
            foreach (['Engineering', 1, null] as $operand) {
                try {
                    $this->noneDB->query($this->testDbName)->where(['department' => [$op => $operand]])->get();
                    $this->fail("$op " . json_encode($operand) . ' must throw');
                } catch (\InvalidArgumentException $e) {
                    $this->assertSame("noneDB: {$op} needs an array", $e->getMessage());
                }
            }
        }
    }

    /**
     * An invalid $regex throws InvalidArgumentException when the query runs, also on an empty database
     */
    public function testInvalidRegexThrows(): void
    {
        $this->noneDB->createDB('emptydb');
        foreach ([$this->testDbName, 'emptydb'] as $db) {
            foreach (['[unclosed', '(', '*a', 'a{2,1}'] as $pattern) {
                try {
                    $this->noneDB->query($db)->where(['name' => ['$regex' => $pattern]])->get();
                    $this->fail("'$pattern' must throw on $db");
                } catch (\InvalidArgumentException $e) {
                    $this->assertSame("noneDB: invalid \$regex pattern '{$pattern}'", $e->getMessage());
                }
            }
        }
    }

    // ========== EDGE CASES ==========

    public function testOperatorsWithNull(): void
    {
        $this->noneDB->insert($this->testDbName, [
            'name' => 'TestNull',
            'age' => null,
            'salary' => 0,
            'active' => true,
            'department' => 'Test'
        ]);

        // null never satisfies a range operator
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace'], $this->where(['age' => ['$gt' => 0]]));
        $this->assertSame([], $this->where(['age' => ['$lt' => 100], 'name' => 'TestNull']));

        // a field holding null exists
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'TestNull'], $this->where(['age' => ['$exists' => true]]));
    }

    public function testOperatorsWithZero(): void
    {
        $this->noneDB->insert($this->testDbName, [
            'name' => 'TestZero',
            'age' => 0,
            'salary' => 0,
            'active' => false,
            'department' => 'Test'
        ]);

        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'TestZero'], $this->where(['age' => ['$gte' => 0]]));
        $this->assertSame(['TestZero'], $this->where(['salary' => ['$lte' => 0]]));
        $this->assertSame([], $this->where(['age' => ['$lt' => 0]]));
    }

    public function testOperatorsWithNegative(): void
    {
        $this->noneDB->insert($this->testDbName, [
            'name' => 'TestNegative',
            'age' => 30,
            'salary' => -100,
            'active' => true,
            'department' => 'Test'
        ]);

        $this->assertSame(['TestNegative'], $this->where(['salary' => ['$lt' => 0]]));
        $this->assertSame(['TestNegative'], $this->where(['salary' => ['$gte' => -100, '$lte' => -100]]));
    }

    public function testOperatorsWithEmptyString(): void
    {
        $this->noneDB->insert($this->testDbName, [
            'name' => '',
            'age' => 20,
            'salary' => 30000,
            'active' => true,
            'department' => ''
        ]);

        $results = $this->noneDB->query($this->testDbName)->where(['name' => ['$eq' => '']])->get();
        $this->assertSame([7], array_column($results, 'key'));
        $this->assertSame('', $results[0]['name']);

        // $ne with empty string: the original 7
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace'], $this->where(['department' => ['$ne' => '']]));
    }

    // ========== SORTING WITH OPERATORS ==========

    public function testOperatorsWithSort(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$gte' => 50000, '$lte' => 100000]])
            ->sort('salary', 'desc')
            ->get();

        $this->assertSame(['Charlie', 'Grace', 'Bob', 'Diana', 'Alice'], $this->names($results));
    }

    public function testOperatorsWithLimitOffset(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->sort('age', 'asc')
            ->limit(2)
            ->offset(1)
            ->get();

        // active by age: Alice 25, Diana 28, Bob 30, Grace 33, Eve 45 -> skip 1, take 2
        $this->assertSame(['Diana', 'Bob'], $this->names($results));
    }

    // ========== CHAINABLE METHOD COMPATIBILITY ==========

    public function testOperatorsWithWhereIn(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$gt' => 50000]])
            ->whereIn('department', ['Engineering', 'Design'])
            ->get();

        $this->assertSame(['Bob', 'Diana', 'Grace'], $this->names($results));
    }

    public function testOperatorsWithBetween(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => ['$ne' => 'Executive']])
            ->between('age', 25, 35)
            ->get();

        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Diana', 'Grace'], $this->names($results));
    }

    public function testOperatorsWithOrWhere(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$gte' => 100000]])
            ->orWhere(['age' => ['$lt' => 25]])
            ->get();

        // Charlie, Eve (high salary), Frank (young), in key order
        $this->assertSame(['Charlie', 'Eve', 'Frank'], $this->names($results));
    }

    /**
     * Operators give the same results with a field index (an index decides what is read, never what matches)
     */
    public function testOperatorsWithFieldIndexGiveTheSameResults(): void
    {
        $filters = [
            ['age' => ['$gt' => 30]], ['age' => ['$gte' => 25, '$lt' => 35]], ['age' => ['$in' => [25, '30', 45]]],
            ['age' => ['$ne' => 30]], ['age' => ['$nin' => [22, 45]]], ['age' => ['$eq' => 28]], ['age' => 33],
            ['department' => ['$in' => ['Design', 'Executive']]], ['department' => ['$gt' => 'E']],
        ];
        $before = [];
        foreach ($filters as $i => $filter) {
            $before[$i] = $this->where($filter);
        }
        $this->noneDB->createFieldIndex($this->testDbName, 'age');
        $this->noneDB->createFieldIndex($this->testDbName, 'department');
        foreach ($filters as $i => $filter) {
            $this->assertSame($before[$i], $this->where($filter), json_encode($filter));
        }
        $this->assertSame(['Charlie', 'Eve', 'Grace'], $before[0]);
        $this->assertSame(['Alice', 'Eve'], $before[2]);
        $this->assertSame(['Diana', 'Eve'], $before[7]);
        // "Engineering", "Management", "Executive" > "E" lexically; "Design" is not
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Eve', 'Frank', 'Grace'], $before[8]);
    }
}
