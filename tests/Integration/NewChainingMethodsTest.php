<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Comprehensive tests for newly added chaining methods
 * Tests: orWhere, whereIn, whereNotIn, whereNot, notLike, notBetween,
 *        select, except, groupBy, having, search, join, skip, orderBy
 *
 * Expected values are derived by hand from the six records below and the rules in docs/QUERY.md and
 * docs/API.md. Results without sort() come back in key order (API.md find(): "in key order"; the planner
 * reads segments in key order), so lists of names are compared in that order.
 */
class NewChainingMethodsTest extends noneDBTestCase
{
    /** The records of setUp(), key => record */
    private const PEOPLE = [
        0 => ['name' => 'Alice', 'age' => 25, 'city' => 'Istanbul', 'email' => 'alice@gmail.com', 'score' => 85, 'department' => 'IT'],
        1 => ['name' => 'Bob', 'age' => 30, 'city' => 'Ankara', 'email' => 'bob@yahoo.com', 'score' => 90, 'department' => 'HR'],
        2 => ['name' => 'Charlie', 'age' => 35, 'city' => 'Istanbul', 'email' => 'charlie@gmail.com', 'score' => 75, 'department' => 'IT'],
        3 => ['name' => 'David', 'age' => 28, 'city' => 'Izmir', 'email' => 'david@hotmail.com', 'score' => 95, 'department' => 'Sales'],
        4 => ['name' => 'Eve', 'age' => 22, 'city' => 'Istanbul', 'email' => 'eve@gmail.com', 'score' => 80, 'department' => 'IT'],
        5 => ['name' => 'Frank', 'age' => 40, 'city' => 'Ankara', 'email' => 'frank@yahoo.com', 'score' => 70, 'department' => 'HR'],
    ];

    private const ALL_NAMES = ['Alice', 'Bob', 'Charlie', 'David', 'Eve', 'Frank'];

    protected function setUp(): void
    {
        parent::setUp();

        // Insert standard test data
        $this->noneDB->insert($this->testDbName, array_values(self::PEOPLE));
    }

    /**
     * Names of the results, in result order
     */
    private function names(array $results): array
    {
        return array_map(function ($r) { return $r['name']; }, $results);
    }

    /**
     * Keys of the results, in result order
     */
    private function keys(array $results): array
    {
        return array_map(function ($r) { return $r['key']; }, $results);
    }

    /**
     * A stored record as find()/get() return it (fields + key), field order normalized
     */
    private function person(int $key, ?array $only = null): array
    {
        $record = self::PEOPLE[$key];
        if ($only !== null) {
            $record = array_intersect_key($record, array_flip($only));
        }
        $record['key'] = $key;
        return $record;
    }

    /**
     * Recursively sort the keys of associative arrays (field order is not part of the contract; values and
     * types are)
     */
    private static function canon($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $item) {
            $value[$k] = self::canon($item);
        }
        return $value;
    }

    private function assertSameRecords(array $expected, array $actual, string $message = ''): void
    {
        $this->assertSame(self::canon($expected), self::canon($actual), $message);
    }

    /**
     * Group rows by their _group value (order-independent): json of _group => [_count, item names]
     */
    private function groupsByValue(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $this->assertSame(['_group', '_items', '_count'], array_keys($group), 'a group row has exactly _group, _items, _count');
            $id = json_encode($group['_group']);
            $this->assertArrayNotHasKey($id, $out, 'every group value appears once');
            $out[$id] = [$group['_count'], $this->names($group['_items'])];
        }
        ksort($out);
        return $out;
    }

    // ==========================================
    // orWhere() TESTS
    // ==========================================

    /**
     * @test
     */
    public function orWhereBasicUsage(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->orWhere(['city' => 'Ankara'])
            ->get();

        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Eve', 'Frank'], $this->names($results));
        $this->assertSameRecords([$this->person(0), $this->person(1), $this->person(2), $this->person(4), $this->person(5)], $results);
    }

    /**
     * @test
     */
    public function orWhereWithMultipleConditions(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Izmir'])
            ->orWhere(['name' => 'Alice'])
            ->orWhere(['name' => 'Bob'])
            ->get();

        $this->assertSame(['Alice', 'Bob', 'David'], $this->names($results));
    }

    /**
     * @test
     */
    public function orWhereOnlyMatchesOrCondition(): void
    {
        // WHERE matches nothing, OR matches something
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'NonExistent'])
            ->orWhere(['city' => 'Istanbul'])
            ->get();

        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));
    }

    /**
     * @test
     */
    public function orWhereWithNoMatches(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'NonExistent1'])
            ->orWhere(['city' => 'NonExistent2'])
            ->get();

        $this->assertSame([], $results);
    }

    /**
     * @test
     */
    public function orWhereWithMultipleFieldsInCondition(): void
    {
        // A record without a match on both fields of a group doesn't match that group: Bob is Ankara+HR
        $this->noneDB->insert($this->testDbName, ['name' => 'Grace', 'city' => 'Ankara', 'department' => 'IT']);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul', 'department' => 'IT'])
            ->orWhere(['city' => 'Ankara', 'department' => 'HR'])
            ->get();

        // 3 IT Istanbul + 2 HR Ankara; Grace (Ankara, IT) matches neither group as a whole
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Eve', 'Frank'], $this->names($results));
    }

    /**
     * @test
     */
    public function orWhereWithNullValue(): void
    {
        // Insert record with null, and one without the field (a plain null matches only an existing null)
        $this->noneDB->insert($this->testDbName, ['name' => 'NullCity', 'city' => null]);
        $this->noneDB->insert($this->testDbName, ['name' => 'NoCity']);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->orWhere(['city' => null])
            ->get();

        $this->assertSame(['Alice', 'Charlie', 'Eve', 'NullCity'], $this->names($results)); // 3 Istanbul + 1 null
        $this->assertSame([0, 2, 4, 6], $this->keys($results));
    }

    /**
     * @test
     */
    public function orWhereWithoutWhere(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->orWhere(['city' => 'Istanbul'])
            ->orWhere(['city' => 'Ankara'])
            ->get();

        // Without where(), only the orWhere() groups decide: Istanbul (3) + Ankara (2)
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Eve', 'Frank'], $this->names($results));
    }

    /**
     * QUERY.md orWhere(): "An empty group (orWhere([])) has no condition and matches every record"
     *
     * @test
     */
    public function orWhereEmptyGroupMatchesEveryRecord(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'NonExistent'])
            ->orWhere([])
            ->get();

        $this->assertSame(self::ALL_NAMES, $this->names($results));
        $this->assertSame(6, $this->noneDB->query($this->testDbName)->where(['city' => 'NonExistent'])->orWhere([])->count());
    }

    /**
     * QUERY.md: a mix of operators and plain keys in one value throws as soon as orWhere() is called
     *
     * @test
     */
    public function orWhereMixingOperatorsAndPlainKeysThrowsAtCall(): void
    {
        $query = $this->noneDB->query($this->testDbName);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot mix operators');
        $query->orWhere(['age' => ['$gt' => 20, 'plain' => 1]]);
    }

    /**
     * QUERY.md: an unknown operator throws when the query runs, also on an empty database
     *
     * @test
     */
    public function orWhereUnknownOperatorThrowsWhenTheQueryRunsEvenOnEmptyDb(): void
    {
        $this->noneDB->createDB('orwhere-empty');
        $query = $this->noneDB->query('orwhere-empty')->orWhere(['age' => ['$gtee' => 1]]); // no exception yet
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown operator '\$gtee'");
        $query->get();
    }

    // ==========================================
    // whereIn() TESTS
    // ==========================================

    /**
     * @test
     */
    public function whereInBasicUsage(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->get();

        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Eve', 'Frank'], $this->names($results));
    }

    /**
     * @test
     */
    public function whereInWithSingleValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Izmir'])
            ->get();

        $this->assertSameRecords([$this->person(3)], $results);
    }

    /**
     * @test
     */
    public function whereInWithEmptyArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', [])
            ->get();

        $this->assertSame([], $results);
    }

    /**
     * @test
     */
    public function whereInWithNonExistentValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Paris', 'London'])
            ->get();

        $this->assertSame([], $results);
    }

    /**
     * @test
     */
    public function whereInWithMixedExistentNonExistent(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Istanbul', 'Paris', 'London'])
            ->get();

        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));
    }

    /**
     * @test
     */
    public function whereInWithNumericValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('age', [25, 30, 35])
            ->get();

        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($results));
    }

    /**
     * @test
     */
    public function whereInCombinedWithWhere(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->get();

        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results)); // All IT are in Istanbul
    }

    /**
     * @test
     */
    public function whereInWithMissingField(): void
    {
        // Insert record without the field
        $this->noneDB->insert($this->testDbName, ['name' => 'NoCity']);

        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Istanbul'])
            ->get();

        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results)); // Only records WITH city field

        // A null in the list doesn't match a missing field either
        $withNull = $this->noneDB->query($this->testDbName)->whereIn('city', [null])->get();
        $this->assertSame([], $withNull);
    }

    /**
     * @test
     */
    public function whereInStrictTypeComparison(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'TypeTest', 'code' => '100']);
        $this->noneDB->insert($this->testDbName, ['name' => 'FloatTest', 'code' => 100.0]);

        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('code', [100]) // Integer 100
            ->get();

        // Should NOT match string '100' nor float 100.0 due to strict comparison
        $this->assertSame([], $results);

        // The same value with the same type matches
        $this->assertSame(['TypeTest'], $this->names($this->noneDB->query($this->testDbName)->whereIn('code', ['100'])->get()));
        $this->assertSame(['FloatTest'], $this->names($this->noneDB->query($this->testDbName)->whereIn('code', [100.0])->get()));
    }

    /**
     * More than 8 values take another code path (a hash set); the comparison stays strict (===)
     *
     * @test
     */
    public function whereInWithLongValueListIsStillStrict(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'TypeTest', 'code' => '100']);
        $this->noneDB->insert($this->testDbName, ['name' => 'IntTest', 'code' => 100]);
        $this->noneDB->insert($this->testDbName, ['name' => 'FloatTest', 'code' => 100.0]);
        $this->noneDB->insert($this->testDbName, ['name' => 'TrueTest', 'code' => true]);

        $ints = [1, 2, 3, 4, 5, 6, 7, 8, 9, 100]; // 10 values
        $this->assertSame(['IntTest'], $this->names($this->noneDB->query($this->testDbName)->whereIn('code', $ints)->get()));

        $strings = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '100'];
        $this->assertSame(['TypeTest'], $this->names($this->noneDB->query($this->testDbName)->whereIn('code', $strings)->get()));

        // 1 (int) in a long list is not true
        $this->assertSame([], $this->noneDB->query($this->testDbName)->where(['name' => 'TrueTest'])->whereIn('code', $ints)->get());

        // whereNotIn with a long list: strict as well (string '100' is kept)
        $kept = $this->noneDB->query($this->testDbName)->where(['code' => ['$exists' => true]])->whereNotIn('code', $ints)->get();
        $this->assertSame(['TypeTest', 'FloatTest', 'TrueTest'], $this->names($kept));
    }

    /**
     * @test
     */
    public function multipleWhereInStacksFilters(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->whereIn('department', ['IT'])
            ->get();

        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results)); // IT in Istanbul
    }

    // ==========================================
    // whereNotIn() TESTS
    // ==========================================

    /**
     * @test
     */
    public function whereNotInBasicUsage(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNotIn('city', ['Istanbul'])
            ->get();

        $this->assertSame(['Bob', 'David', 'Frank'], $this->names($results)); // Ankara(2) + Izmir(1)
    }

    /**
     * @test
     */
    public function whereNotInWithEmptyArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNotIn('city', [])
            ->get();

        // Empty array = nothing excluded = all returned
        $this->assertSame(self::ALL_NAMES, $this->names($results));
    }

    /**
     * @test
     */
    public function whereNotInExcludesAll(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNotIn('city', ['Istanbul', 'Ankara', 'Izmir'])
            ->get();

        $this->assertSame([], $results);
    }

    /**
     * @test
     */
    public function whereNotInWithMissingField(): void
    {
        // Insert record without the field
        $this->noneDB->insert($this->testDbName, ['name' => 'NoCity']);

        $results = $this->noneDB->query($this->testDbName)
            ->whereNotIn('city', ['Istanbul'])
            ->get();

        // Records without field should be included (not in the list)
        $this->assertSame(['Bob', 'David', 'Frank', 'NoCity'], $this->names($results)); // 3 non-Istanbul + 1 without city
    }

    /**
     * @test
     */
    public function whereNotInIsStrict(): void
    {
        // '25' (string) is not 25: nothing is excluded
        $results = $this->noneDB->query($this->testDbName)->whereNotIn('age', ['25', '30'])->get();
        $this->assertSame(self::ALL_NAMES, $this->names($results));

        $results = $this->noneDB->query($this->testDbName)->whereNotIn('age', [25, 30])->get();
        $this->assertSame(['Charlie', 'David', 'Eve', 'Frank'], $this->names($results));
    }

    /**
     * @test
     */
    public function whereNotInCombinedWithWhereIn(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->whereNotIn('department', ['IT'])
            ->get();

        $this->assertSame(['Bob', 'Frank'], $this->names($results)); // HR in Ankara
    }

    // ==========================================
    // whereNot() TESTS
    // ==========================================

    /**
     * @test
     */
    public function whereNotBasicUsage(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNot(['city' => 'Istanbul'])
            ->get();

        $this->assertSame(['Bob', 'David', 'Frank'], $this->names($results));
    }

    /**
     * @test
     */
    public function whereNotWithMultipleFilters(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNot(['city' => 'Istanbul', 'department' => 'HR'])
            ->get();

        // Excludes records where city IS Istanbul OR department IS HR
        $this->assertSame(['David'], $this->names($results)); // Only David (Izmir, Sales)
    }

    /**
     * @test
     */
    public function whereNotWithMissingField(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NoCity']);

        $results = $this->noneDB->query($this->testDbName)
            ->whereNot(['city' => 'Istanbul'])
            ->get();

        // Records without field should be included
        $this->assertSame(['Bob', 'David', 'Frank', 'NoCity'], $this->names($results));
    }

    /**
     * @test
     */
    public function whereNotCombinedWithWhere(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->whereNot(['city' => 'Istanbul'])
            ->get();

        // IT but not in Istanbul - none should match
        $this->assertSame([], $results);
    }

    /**
     * @test
     */
    public function whereNotWithNullValue(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NullCity', 'city' => null]);
        $this->noneDB->insert($this->testDbName, ['name' => 'NoCity']);

        $results = $this->noneDB->query($this->testDbName)
            ->whereNot(['city' => null])
            ->get();

        // All except the null city; a missing field doesn't match the plain value null (QUERY.md whereNot())
        $this->assertSame(array_merge(self::ALL_NAMES, ['NoCity']), $this->names($results));
    }

    /**
     * @test
     */
    public function whereNotPlainValueIsStrict(): void
    {
        // '25' is not 25 (===): nothing excluded
        $results = $this->noneDB->query($this->testDbName)->whereNot(['age' => '25'])->get();
        $this->assertSame(self::ALL_NAMES, $this->names($results));
    }

    /**
     * QUERY.md whereNot(): an operator array matches when all of that field's operators match
     *
     * @test
     */
    public function whereNotWithOperatorsExcludesWhenAllOperatorsMatch(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNot(['age' => ['$gte' => 25, '$lt' => 30]])
            ->get();

        // Excluded: Alice (25), David (28); 30 is not < 30
        $this->assertSame(['Bob', 'Charlie', 'Eve', 'Frank'], $this->names($results));

        // Operators of different fields: any field that matches excludes the record
        $results = $this->noneDB->query($this->testDbName)
            ->whereNot(['age' => ['$gt' => 35], 'score' => ['$gte' => 90]])
            ->get();
        // Frank (40) by age; Bob (90) and David (95) by score
        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));
    }

    /**
     * QUERY.md whereNot(): $ne, $nin and $exists => false match a missing field, so those records are dropped
     *
     * @test
     */
    public function whereNotNegativeOperatorsAlsoDropRecordsWithoutTheField(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NoDept']);

        $ne = $this->noneDB->query($this->testDbName)->whereNot(['department' => ['$ne' => 'IT']])->get();
        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($ne));

        $nin = $this->noneDB->query($this->testDbName)->whereNot(['department' => ['$nin' => ['IT', 'HR']]])->get();
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Eve', 'Frank'], $this->names($nin));

        $notExists = $this->noneDB->query($this->testDbName)->whereNot(['department' => ['$exists' => false]])->get();
        $this->assertSame(self::ALL_NAMES, $this->names($notExists));

        // Most operators don't match a missing field: the record is kept
        $in = $this->noneDB->query($this->testDbName)->whereNot(['department' => ['$in' => ['IT']]])->get();
        $this->assertSame(['Bob', 'David', 'Frank', 'NoDept'], $this->names($in));
    }

    /**
     * QUERY.md: whereNot() checks its operators when it is called
     *
     * @test
     */
    public function whereNotUnknownOperatorThrowsAtCall(): void
    {
        $query = $this->noneDB->query($this->testDbName);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown operator '\$gtee'");
        $query->whereNot(['age' => ['$gtee' => 18]]);
    }

    /**
     * @test
     */
    public function whereNotInOperatorWithoutArrayThrowsAtCall(): void
    {
        $query = $this->noneDB->query($this->testDbName);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$in needs an array');
        $query->whereNot(['age' => ['$in' => 25]]);
    }

    /**
     * @test
     */
    public function whereNotInvalidRegexThrowsAtCall(): void
    {
        $query = $this->noneDB->query($this->testDbName);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid $regex pattern');
        $query->whereNot(['name' => ['$regex' => '(unclosed']]);
    }

    /**
     * @test
     */
    public function whereNotMixingOperatorsAndPlainKeysThrowsAtCall(): void
    {
        $query = $this->noneDB->query($this->testDbName);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot mix operators');
        $query->whereNot(['age' => ['$gt' => 1, 'x' => 2]]);
    }

    /**
     * @test
     */
    public function multipleWhereNotCallsStackDifferentFields(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNot(['city' => 'Istanbul'])
            ->whereNot(['department' => 'HR'])
            ->get();

        // Excludes Istanbul (3) AND HR (Bob, Frank in Ankara)
        // Result: David (Izmir, Sales) only
        $this->assertSameRecords([$this->person(3)], $results);
    }

    /**
     * Renamed from whereNotSameFieldOverrides: every whereNot() call applies, none overrides another
     *
     * @test
     */
    public function whereNotSameFieldCallsAllApply(): void
    {
        // Every whereNot() call applies (calls for the same field never overwrite each other)
        $results = $this->noneDB->query($this->testDbName)
            ->whereNot(['city' => 'Istanbul'])
            ->whereNot(['city' => 'Ankara'])
            ->get();

        $this->assertSameRecords([$this->person(3)], $results); // only David (Izmir)
    }

    // ==========================================
    // notLike() TESTS
    // ==========================================

    /**
     * @test
     */
    public function notLikeBasicUsage(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notLike('email', 'gmail')
            ->get();

        $this->assertSame(['Bob', 'David', 'Frank'], $this->names($results));
    }

    /**
     * @test
     */
    public function notLikeStartsWith(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notLike('name', '^A')
            ->get();

        $this->assertSame(['Bob', 'Charlie', 'David', 'Eve', 'Frank'], $this->names($results));

        // Case-insensitive: '^a' excludes Alice too
        $this->assertSame(['Bob', 'Charlie', 'David', 'Eve', 'Frank'], $this->names($this->noneDB->query($this->testDbName)->notLike('name', '^a')->get()));
    }

    /**
     * @test
     */
    public function notLikeEndsWith(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notLike('email', 'gmail.com$')
            ->get();

        $this->assertSame(['Bob', 'David', 'Frank'], $this->names($results));
    }

    /**
     * @test
     */
    public function notLikeWithMissingField(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NoEmail']);

        $results = $this->noneDB->query($this->testDbName)
            ->notLike('email', 'gmail')
            ->get();

        // Records without field should be included
        $this->assertSame(['Bob', 'David', 'Frank', 'NoEmail'], $this->names($results));
    }

    /**
     * @test
     */
    public function notLikeWithArrayValue(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'ArrayEmail', 'email' => ['gmail', 'a@gmail.com']]);

        $results = $this->noneDB->query($this->testDbName)
            ->notLike('email', 'gmail')
            ->get();

        // Array values never match a pattern, so notLike keeps them (even with matching elements)
        $this->assertSame(['Bob', 'David', 'Frank', 'ArrayEmail'], $this->names($results));
        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($this->noneDB->query($this->testDbName)->like('email', 'gmail')->get()));
    }

    /**
     * QUERY.md like()/notLike(): strings and numbers are matched; booleans, arrays and missing fields never are
     *
     * @test
     */
    public function likeAndNotLikeMatchNumbersButNeverBooleans(): void
    {
        $this->assertSame(['Alice', 'David', 'Eve'], $this->names($this->noneDB->query($this->testDbName)->like('age', '^2')->get()));
        $this->assertSame(['Bob', 'Charlie', 'Frank'], $this->names($this->noneDB->query($this->testDbName)->notLike('age', '^2')->get()));

        $this->noneDB->insert($this->testDbName, [
            ['name' => 'T', 'flag' => true],
            ['name' => 'F', 'flag' => false],
            ['name' => 'S', 'flag' => '1'],
        ]);
        $this->assertSame(['S'], $this->names($this->noneDB->query($this->testDbName)->like('flag', '1')->get()));
        $this->assertSame([], $this->noneDB->query($this->testDbName)->like('flag', 'true')->get());
        // notLike keeps the booleans and the records without the field
        $this->assertSame(array_merge(self::ALL_NAMES, ['T', 'F']), $this->names($this->noneDB->query($this->testDbName)->notLike('flag', '1')->get()));
    }

    /**
     * @test
     */
    public function notLikeCombinedWithLike(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('email', 'gmail')
            ->notLike('name', '^E')
            ->get();

        $this->assertSame(['Alice', 'Charlie'], $this->names($results)); // gmail but not E*
    }

    /**
     * @test
     */
    public function notLikeWithEmptyPattern(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notLike('email', '')
            ->get();

        // Empty pattern matches all, so notLike excludes all
        $this->assertSame([], $results);
    }

    /**
     * @test
     */
    public function notLikeWithRegexSpecialChars(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'Test', 'email' => 'test+special@test.com']);

        $results = $this->noneDB->query($this->testDbName)
            ->notLike('email', '+special')
            ->get();

        $this->assertSame(self::ALL_NAMES, $this->names($results)); // All original records

        // '+' is literal: like() finds exactly that record; '.' is literal too
        $this->assertSame(['Test'], $this->names($this->noneDB->query($this->testDbName)->like('email', '+special')->get()));
        $this->assertSame([], $this->noneDB->query($this->testDbName)->like('email', 'gmail.co.')->get());
    }

    // ==========================================
    // notBetween() TESTS
    // ==========================================

    /**
     * @test
     */
    public function notBetweenBasicUsage(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 25, 35)
            ->get();

        // Ages outside 25-35: Eve(22), Frank(40)
        $this->assertSame(['Eve', 'Frank'], $this->names($results));
    }

    /**
     * @test
     */
    public function notBetweenBoundaryValues(): void
    {
        // Values AT boundaries should be excluded (they ARE between)
        $results = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 25, 25) // Exact match
            ->get();

        $this->assertSame(['Bob', 'Charlie', 'David', 'Eve', 'Frank'], $this->names($results)); // All except Alice (25)
    }

    /**
     * @test
     */
    public function notBetweenWithMissingField(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NoAge']);

        $results = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 20, 30)
            ->get();

        // Records without field should be included
        $this->assertSame(['Charlie', 'Frank', 'NoAge'], $this->names($results));
    }

    /**
     * QUERY.md between()/notBetween(): notBetween() returns every other record, including values that can't be
     * compared (null, booleans, text against numbers); numeric strings compare numerically
     *
     * @test
     */
    public function notBetweenIsTheComplementOfBetweenIncludingIncomparableValues(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'NullAge', 'age' => null],
            ['name' => 'BoolAge', 'age' => true],
            ['name' => 'TextAge', 'age' => 'old'],
            ['name' => 'NumStr', 'age' => '27'],
            ['name' => 'ArrAge', 'age' => [27]],
        ]);

        $between = $this->names($this->noneDB->query($this->testDbName)->between('age', 25, 30)->get());
        $notBetween = $this->names($this->noneDB->query($this->testDbName)->notBetween('age', 25, 30)->get());

        $this->assertSame(['Alice', 'Bob', 'David', 'NumStr'], $between);
        $this->assertSame(['Charlie', 'Eve', 'Frank', 'NullAge', 'BoolAge', 'TextAge', 'ArrAge'], $notBetween);
        $this->assertSame([], array_intersect($between, $notBetween));
        $this->assertCount(11, array_merge($between, $notBetween));
    }

    /**
     * @test
     */
    public function notBetweenWithNegativeNumbers(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'Negative', 'balance' => -50]);
        $this->noneDB->insert($this->testDbName, ['name' => 'Positive', 'balance' => 50]);

        $results = $this->noneDB->query($this->testDbName)
            ->notBetween('balance', -100, 0)
            ->get();

        // notBetween includes records without the field (6 original + 1 Positive)
        // Negative (-50) is between -100 and 0, so excluded
        $this->assertSame(array_merge(self::ALL_NAMES, ['Positive']), $this->names($results));
        $this->assertSame(['Negative'], $this->names($this->noneDB->query($this->testDbName)->between('balance', -100, 0)->get()));
    }

    /**
     * @test
     */
    public function notBetweenWithFloats(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'Float1', 'price' => 10.5]);
        $this->noneDB->insert($this->testDbName, ['name' => 'Float2', 'price' => 20.5]);

        $results = $this->noneDB->query($this->testDbName)
            ->notBetween('price', 10.0, 15.0)
            ->get();

        // notBetween includes records without the field (6 original + 1 Float2)
        // Float1 (10.5) is between 10.0 and 15.0, so excluded
        $this->assertSame(array_merge(self::ALL_NAMES, ['Float2']), $this->names($results));
        $this->assertSame(20.5, $results[6]['price']);
    }

    /**
     * @test
     */
    public function notBetweenCombinedWithBetween(): void
    {
        // Age between 20-40 but not between 28-32
        $results = $this->noneDB->query($this->testDbName)
            ->between('age', 20, 40)
            ->notBetween('age', 28, 32)
            ->get();

        // Alice(25), Charlie(35), Eve(22), Frank(40) in key order
        $this->assertSame(['Alice', 'Charlie', 'Eve', 'Frank'], $this->names($results));
    }

    /**
     * @test
     */
    public function multipleNotBetweenCallsStack(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 20, 25)
            ->notBetween('age', 35, 40)
            ->get();

        // Bob(30), David(28) - not in 20-25 AND not in 35-40
        $this->assertSame(['Bob', 'David'], $this->names($results));
    }

    // ==========================================
    // select() TESTS
    // ==========================================

    /**
     * @test
     */
    public function selectBasicUsage(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select(['name', 'age'])
            ->get();

        $expected = [];
        foreach (array_keys(self::PEOPLE) as $key) {
            $expected[] = $this->person($key, ['name', 'age']); // key is always included
        }
        $this->assertSameRecords($expected, $results);
    }

    /**
     * @test
     */
    public function selectSingleField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select(['name'])
            ->get();

        $expected = [];
        foreach (array_keys(self::PEOPLE) as $key) {
            $expected[] = $this->person($key, ['name']); // Only name + key
        }
        $this->assertSameRecords($expected, $results);
    }

    /**
     * @test
     */
    public function selectNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select(['nonexistent'])
            ->get();

        // Only the key is left (a missing field isn't added, not even as null)
        $this->assertSame([['key' => 0], ['key' => 1], ['key' => 2], ['key' => 3], ['key' => 4], ['key' => 5]], $results);
    }

    /**
     * @test
     */
    public function selectIncludesKeyExplicitly(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select(['name', 'key'])
            ->get();

        $expected = [];
        foreach (array_keys(self::PEOPLE) as $key) {
            $expected[] = $this->person($key, ['name']);
        }
        $this->assertSameRecords($expected, $results);
    }

    /**
     * select([]) selects nothing specific: the records are returned whole (QUERY.md doesn't define an empty
     * list; this pins the behaviour of the existing test: no projection)
     *
     * @test
     */
    public function selectWithEmptyArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select([])
            ->get();

        $expected = [];
        foreach (array_keys(self::PEOPLE) as $key) {
            $expected[] = $this->person($key);
        }
        $this->assertSameRecords($expected, $results);
        $this->assertSame($this->noneDB->query($this->testDbName)->get(), $results);
    }

    /**
     * @test
     */
    public function selectCombinedWithFilters(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->select(['name', 'department'])
            ->get();

        $this->assertSameRecords([
            $this->person(0, ['name', 'department']),
            $this->person(2, ['name', 'department']),
            $this->person(4, ['name', 'department']),
        ], $results);
    }

    /**
     * QUERY.md select(): filters and sort() see all fields, the projection only shapes the result
     *
     * @test
     */
    public function selectDoesNotHideFieldsFromFiltersAndSort(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select(['name'])
            ->where(['age' => ['$gte' => 28]])
            ->sort('score', 'desc')
            ->get();

        // Ages >= 28: Bob 90, Charlie 75, David 95, Frank 70 -> by score desc
        $this->assertSameRecords([
            ['name' => 'David', 'key' => 3],
            ['name' => 'Bob', 'key' => 1],
            ['name' => 'Charlie', 'key' => 2],
            ['name' => 'Frank', 'key' => 5],
        ], $results);
    }

    // ==========================================
    // except() TESTS
    // ==========================================

    /**
     * @test
     */
    public function exceptBasicUsage(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->except(['email', 'score'])
            ->get();

        $expected = [];
        foreach (array_keys(self::PEOPLE) as $key) {
            $expected[] = $this->person($key, ['name', 'age', 'city', 'department']);
        }
        $this->assertSameRecords($expected, $results);
    }

    /**
     * @test
     */
    public function exceptSingleField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->except(['email'])
            ->get();

        $expected = [];
        foreach (array_keys(self::PEOPLE) as $key) {
            $expected[] = $this->person($key, ['name', 'age', 'city', 'score', 'department']);
        }
        $this->assertSameRecords($expected, $results);
    }

    /**
     * @test
     */
    public function exceptNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->except(['nonexistent'])
            ->get();

        // Should return all fields (nonexistent field doesn't affect)
        $expected = [];
        foreach (array_keys(self::PEOPLE) as $key) {
            $expected[] = $this->person($key);
        }
        $this->assertSameRecords($expected, $results);
    }

    /**
     * QUERY.md "select() / except()": "`key` is always kept." (the bullet applies to both methods)
     *
     * @test
     */
    public function exceptKey(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->except(['key'])
            ->get();

        // except() removes key when it is listed (QUERY.md select() / except());
        // every other field stays
        $this->assertSameRecords(array_values(self::PEOPLE), $results);
    }

    /**
     * @test
     */
    public function exceptWithEmptyArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->except([])
            ->get();

        // Empty except = all fields returned
        $this->assertSame($this->noneDB->query($this->testDbName)->get(), $results);
        $this->assertSame(self::ALL_NAMES, $this->names($results));
    }

    /**
     * @test
     */
    public function exceptAllFields(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->except(['name', 'age', 'city', 'email', 'score', 'department', 'key'])
            ->get();

        // Every field listed, key too: empty records (QUERY.md select() / except())
        $this->assertSame([[], [], [], [], [], []], $results);
    }

    // ==========================================
    // groupBy() TESTS
    // ==========================================

    /**
     * @test
     */
    public function groupByBasicUsage(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->get();

        $this->assertSame([
            '"Ankara"' => [2, ['Bob', 'Frank']],
            '"Istanbul"' => [3, ['Alice', 'Charlie', 'Eve']],
            '"Izmir"' => [1, ['David']],
        ], $this->groupsByValue($results));
    }

    /**
     * @test
     */
    public function groupByReturnsCorrectCounts(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->get();

        $grouped = [];
        foreach ($results as $group) {
            $grouped[$group['_group']] = $group['_count'];
        }
        ksort($grouped);

        $this->assertSame(['Ankara' => 2, 'Istanbul' => 3, 'Izmir' => 1], $grouped);
    }

    /**
     * @test
     */
    public function groupByContainsItems(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('department')
            ->get();

        $this->assertSame([
            '"HR"' => [2, ['Bob', 'Frank']],
            '"IT"' => [3, ['Alice', 'Charlie', 'Eve']],
            '"Sales"' => [1, ['David']],
        ], $this->groupsByValue($results));
        // The items are the whole records
        foreach ($results as $group) {
            if ($group['_group'] === 'Sales') {
                $this->assertSameRecords([$this->person(3)], $group['_items']);
            }
        }
    }

    /**
     * @test
     */
    public function groupByWithNullValues(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NoCity1', 'city' => null]);
        $this->noneDB->insert($this->testDbName, ['name' => 'NoCity2', 'city' => null]);

        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->get();

        // Should have group with null
        $nullGroup = array_values(array_filter($results, function ($g) { return $g['_group'] === null; }));
        $this->assertCount(1, $nullGroup);
        $this->assertSame(2, $nullGroup[0]['_count']);
        $this->assertSame(['NoCity1', 'NoCity2'], $this->names($nullGroup[0]['_items']));
        $this->assertCount(4, $results);
    }

    /**
     * @test
     */
    public function groupByWithMissingField(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NoCity']);
        $this->noneDB->insert($this->testDbName, ['name' => 'NullCity', 'city' => null]);

        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->get();

        // Missing field is grouped under null, together with an explicit null (QUERY.md groupBy())
        $this->assertSame([
            '"Ankara"' => [2, ['Bob', 'Frank']],
            '"Istanbul"' => [3, ['Alice', 'Charlie', 'Eve']],
            '"Izmir"' => [1, ['David']],
            'null' => [2, ['NoCity', 'NullCity']],
        ], $this->groupsByValue($results));
    }

    /**
     * QUERY.md groupBy(): group values keep their type (1, "1", 1.5 and 1.0 are separate groups);
     * -0.0 and 0.0 are one group
     *
     * @test
     */
    public function groupByKeepsValueTypes(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'i1', 'code' => 1],
            ['name' => 's1', 'code' => '1'],
            ['name' => 'f15', 'code' => 1.5],
            ['name' => 'i1b', 'code' => 1],
            ['name' => 'f1', 'code' => 1.0],
            ['name' => 'zneg', 'zero' => -0.0],
            ['name' => 'zpos', 'zero' => 0.0],
        ]);

        $groups = $this->noneDB->query($this->testDbName)->where(['code' => ['$exists' => true]])->groupBy('code')->get();
        $this->assertCount(4, $groups);
        $byValue = [];
        foreach ($groups as $group) {
            $type = gettype($group['_group']);
            $byValue[$type . ':' . var_export($group['_group'], true)] = [$group['_count'], $this->names($group['_items'])];
        }
        ksort($byValue);
        $this->assertSame([
            'double:1.0' => [1, ['f1']],
            'double:1.5' => [1, ['f15']],
            'integer:1' => [2, ['i1', 'i1b']],
            "string:'1'" => [1, ['s1']],
        ], $byValue);

        $zero = $this->noneDB->query($this->testDbName)->where(['zero' => ['$exists' => true]])->groupBy('zero')->get();
        $this->assertCount(1, $zero);
        $this->assertSame(2, $zero[0]['_count']);
        $this->assertIsFloat($zero[0]['_group']);
        $this->assertSame(0.0, abs($zero[0]['_group']));
        $this->assertSame(['zneg', 'zpos'], $this->names($zero[0]['_items']));
    }

    /**
     * @test
     */
    public function groupByCombinedWithFilters(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->groupBy('city')
            ->get();

        // All IT are in Istanbul
        $this->assertSame(['"Istanbul"' => [3, ['Alice', 'Charlie', 'Eve']]], $this->groupsByValue($results));
    }

    /**
     * @test
     */
    public function groupByWithSort(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->sort('_count', 'desc')
            ->get();

        // Istanbul(3), Ankara(2), Izmir(1)
        $this->assertSame(['Istanbul', 'Ankara', 'Izmir'], array_column($results, '_group'));
        $this->assertSame([3, 2, 1], array_column($results, '_count'));

        $asc = $this->noneDB->query($this->testDbName)->groupBy('city')->sort('_group')->get();
        $this->assertSame(['Ankara', 'Istanbul', 'Izmir'], array_column($asc, '_group'));
    }

    // ==========================================
    // having() TESTS
    // ==========================================

    /**
     * Group values of a grouped result, sorted
     */
    private function groupValues(array $groups): array
    {
        $values = array_column($groups, '_group');
        sort($values);
        return $values;
    }

    /**
     * @test
     */
    public function havingWithCount(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->having('count', '>', 1)
            ->get();

        // Istanbul(3) and Ankara(2) have count > 1
        $this->assertSame(['Ankara', 'Istanbul'], $this->groupValues($results));
    }

    /**
     * @test
     */
    public function havingWithCountEquals(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->having('count', '=', 1)
            ->get();

        // Only Izmir has exactly 1
        $this->assertCount(1, $results);
        $this->assertSame('Izmir', $results[0]['_group']);
        $this->assertSame(1, $results[0]['_count']);
        $this->assertSameRecords([$this->person(3)], $results[0]['_items']);
    }

    /**
     * @test
     */
    public function havingWithSumAggregate(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->having('sum:score', '>', 150)
            ->get();

        // Istanbul: 85+75+80=240, Ankara: 90+70=160, Izmir: 95
        $this->assertSame(['Ankara', 'Istanbul'], $this->groupValues($results));

        // Boundary: Ankara's sum is exactly 160
        $this->assertSame(['Istanbul'], $this->groupValues($this->noneDB->query($this->testDbName)->groupBy('city')->having('sum:score', '>', 160)->get()));
        $this->assertSame(['Ankara', 'Istanbul'], $this->groupValues($this->noneDB->query($this->testDbName)->groupBy('city')->having('sum:score', '>=', 160)->get()));
    }

    /**
     * @test
     */
    public function havingWithAvgAggregate(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->having('avg:age', '>=', 30)
            ->get();

        // Istanbul: avg(25,35,22)=27.33, Ankara: avg(30,40)=35, Izmir: 28
        $this->assertSame(['Ankara'], $this->groupValues($results));
    }

    /**
     * @test
     */
    public function havingWithMinAggregate(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->having('min:age', '<', 25)
            ->get();

        // Istanbul min: 22
        $this->assertSame(['Istanbul'], $this->groupValues($results));
    }

    /**
     * @test
     */
    public function havingWithMaxAggregate(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->having('max:score', '>=', 90)
            ->get();

        // Ankara max: 90, Izmir max: 95, Istanbul max: 85
        $this->assertSame(['Ankara', 'Izmir'], $this->groupValues($results));
    }

    /**
     * @test
     */
    public function havingWithNotEquals(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->having('count', '!=', 3)
            ->get();

        // Ankara(2) and Izmir(1)
        $this->assertSame(['Ankara', 'Izmir'], $this->groupValues($results));
    }

    /**
     * @test
     */
    public function havingWithLessThanOrEqual(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->having('count', '<=', 2)
            ->get();

        $this->assertSame(['Ankara', 'Izmir'], $this->groupValues($results));

        $lt = $this->noneDB->query($this->testDbName)->groupBy('city')->having('count', '<', 2)->get();
        $this->assertSame(['Izmir'], $this->groupValues($lt));
    }

    /**
     * @test
     */
    public function multipleHavingConditions(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->having('count', '>=', 2)
            ->having('avg:score', '>', 75)
            ->get();

        // Istanbul: count=3, avg=80. Ankara: count=2, avg=80. Izmir: count 1
        $this->assertSame(['Ankara', 'Istanbul'], $this->groupValues($results));

        // Both conditions must hold: avg:score > 80 drops both
        $none = $this->noneDB->query($this->testDbName)->groupBy('city')->having('count', '>=', 2)->having('avg:score', '>', 80)->get();
        $this->assertSame([], $none);
    }

    /**
     * @test
     */
    public function havingWithInvalidAggregatePassesThrough(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->having('invalid:field', '>', 0)
            ->get();

        // Unknown aggregate: the condition is ignored, all groups pass
        $this->assertSame(['Ankara', 'Istanbul', 'Izmir'], $this->groupValues($results));
    }

    /**
     * QUERY.md having(): an unknown operator ignores the condition
     *
     * @test
     */
    public function havingWithUnknownOperatorIsIgnored(): void
    {
        foreach (['<>', '==', 'gt', ''] as $operator) {
            $results = $this->noneDB->query($this->testDbName)
                ->groupBy('city')
                ->having('count', $operator, 100)
                ->get();
            $this->assertSame(['Ankara', 'Istanbul', 'Izmir'], $this->groupValues($results), "operator '{$operator}'");
        }
    }

    /**
     * QUERY.md having(): without groupBy() the condition is ignored (plain records are returned)
     *
     * @test
     */
    public function havingWithoutGroupByIsIgnored(): void
    {
        $results = $this->noneDB->query($this->testDbName)->having('count', '>', 100)->get();

        $expected = [];
        foreach (array_keys(self::PEOPLE) as $key) {
            $expected[] = $this->person($key);
        }
        $this->assertSameRecords($expected, $results);
    }

    // ==========================================
    // search() TESTS
    // ==========================================

    /**
     * @test
     */
    public function searchBasicUsage(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('alice')
            ->get();

        $this->assertSameRecords([$this->person(0)], $results);
    }

    /**
     * @test
     */
    public function searchCaseInsensitive(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('ALICE')
            ->get();

        $this->assertSameRecords([$this->person(0)], $results);
        $this->assertSame(['Alice'], $this->names($this->noneDB->query($this->testDbName)->search('aLiCe@GMAIL')->get()));
    }

    /**
     * @test
     */
    public function searchAcrossMultipleFields(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('istanbul')
            ->get();

        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));

        // 'hr' is found in the department of Bob and Frank, 'hotmail' in David's email
        $this->assertSame(['Bob', 'Frank'], $this->names($this->noneDB->query($this->testDbName)->search('hr')->get()));
        $this->assertSame(['David'], $this->names($this->noneDB->query($this->testDbName)->search('hotmail')->get()));
    }

    /**
     * @test
     */
    public function searchInSpecificFields(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('gmail', ['email'])
            ->get();

        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));

        // Only the listed fields are searched
        $this->assertSame([], $this->noneDB->query($this->testDbName)->search('gmail', ['name', 'city'])->get());
        $this->assertSame(['Alice'], $this->names($this->noneDB->query($this->testDbName)->search('alice', ['name'])->get()));
    }

    /**
     * @test
     */
    public function searchWithNoMatches(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('nonexistent')
            ->get();

        $this->assertSame([], $results);
    }

    /**
     * QUERY.md search(): the whole term must appear in one field value (no word splitting)
     *
     * @test
     */
    public function searchDoesNotSplitWords(): void
    {
        $this->assertSame([], $this->noneDB->query($this->testDbName)->search('alice istanbul')->get());
        $this->assertSame([], $this->noneDB->query($this->testDbName)->search('istanbul alice')->get());
    }

    /**
     * @test
     */
    public function searchPartialMatch(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('li')
            ->get();

        // Alice, Charlie contain 'li'
        $this->assertSame(['Alice', 'Charlie'], $this->names($results));
    }

    /**
     * @test
     */
    public function searchIgnoresArrayFields(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'ArrayTest', 'tags' => ['alice', 'test']]);

        $results = $this->noneDB->query($this->testDbName)
            ->search('alice')
            ->get();

        // Should only find Alice, not ArrayTest (array values not searched)
        $this->assertSame(['Alice'], $this->names($results));
    }

    /**
     * @test
     */
    public function searchIgnoresNullFields(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => null, 'email' => 'test@test.com']);

        $results = $this->noneDB->query($this->testDbName)
            ->search('test')
            ->get();

        // Should find the record via email (and only it: no original record contains "test")
        $this->assertSame([['email' => 'test@test.com', 'key' => 6, 'name' => null]], self::canon($results));
    }

    /**
     * @test
     */
    public function searchWithNumericValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('25')
            ->get();

        // Matches Alice's age (25) and nothing else (no other field contains "25")
        $this->assertSame(['Alice'], $this->names($results));

        // '5' appears in Alice (25, 85), Charlie (35, 75) and David (95)
        $this->assertSame(['Alice', 'Charlie', 'David'], $this->names($this->noneDB->query($this->testDbName)->search('5')->get()));
    }

    /**
     * QUERY.md search(): without a field list every field except key is searched
     *
     * @test
     */
    public function searchWithoutFieldListIgnoresKey(): void
    {
        // Frank has key 5 but no "5" in any field; Bob has key 1 and no "1" anywhere
        $this->assertSame(['Alice', 'Charlie', 'David'], $this->names($this->noneDB->query($this->testDbName)->search('5')->get()));
        $this->assertSame([], $this->noneDB->query($this->testDbName)->search('1')->get());
        // With an explicit list that names key, key is searched
        $this->assertSame(['Bob'], $this->names($this->noneDB->query($this->testDbName)->search('1', ['key'])->get()));
    }

    /**
     * @test
     */
    public function multipleSearchCallsStack(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('gmail')
            ->search('alice')
            ->get();

        // Must match both 'gmail' AND 'alice'
        $this->assertSame(['Alice'], $this->names($results));
    }

    /**
     * @test
     */
    public function searchCombinedWithFilters(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->search('gmail')
            ->get();

        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));
    }

    // ==========================================
    // join() TESTS
    // ==========================================

    private const DEPARTMENTS = [
        ['id' => 'IT', 'name' => 'Information Technology', 'budget' => 100000],
        ['id' => 'HR', 'name' => 'Human Resources', 'budget' => 50000],
        ['id' => 'Sales', 'name' => 'Sales Department', 'budget' => 75000],
    ];

    /**
     * @test
     */
    public function joinBasicUsage(): void
    {
        // Create foreign database
        $this->noneDB->insert('departments', self::DEPARTMENTS);
        $deptByName = ['IT' => 0, 'HR' => 1, 'Sales' => 2];

        $results = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'id')
            ->get();

        $expected = [];
        foreach (array_keys(self::PEOPLE) as $key) {
            $row = $this->person($key);
            $deptKey = $deptByName[$row['department']];
            $row['departments'] = self::DEPARTMENTS[$deptKey] + ['key' => $deptKey];
            $expected[] = $row;
        }
        $this->assertSameRecords($expected, $results);
    }

    /**
     * @test
     */
    public function joinWithCustomAlias(): void
    {
        $this->noneDB->insert('departments', [
            ['id' => 'IT', 'name' => 'Information Technology'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'id', 'dept')
            ->get();

        $this->assertSame(self::ALL_NAMES, $this->names($results));
        foreach ($results as $record) {
            $this->assertArrayHasKey('dept', $record);
            $this->assertArrayNotHasKey('departments', $record);
            $expected = $record['department'] === 'IT' ? ['id' => 'IT', 'key' => 0, 'name' => 'Information Technology'] : null;
            $this->assertSame($expected, self::canon($record['dept']), $record['name']);
        }
    }

    /**
     * @test
     */
    public function joinWithNoMatch(): void
    {
        $this->noneDB->insert('departments', [
            ['id' => 'Finance', 'name' => 'Finance Department'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'id')
            ->get();

        $this->assertSame(self::ALL_NAMES, $this->names($results));
        foreach ($results as $record) {
            $this->assertArrayHasKey('departments', $record);
            $this->assertNull($record['departments']); // No match
        }
    }

    /**
     * @test
     */
    public function joinPartialMatch(): void
    {
        $this->noneDB->insert('departments', [
            ['id' => 'IT', 'name' => 'Information Technology'],
            // HR and Sales not present
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'id')
            ->get();

        $this->assertSame(self::ALL_NAMES, $this->names($results));
        foreach ($results as $record) {
            if ($record['department'] === 'IT') {
                $this->assertSame(['id' => 'IT', 'key' => 0, 'name' => 'Information Technology'], self::canon($record['departments']));
            } else {
                $this->assertNull($record['departments'], $record['name']); // HR and Sales
            }
        }
    }

    /**
     * @test
     */
    public function joinWithMissingLocalKey(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NoDept']); // No department field
        $this->noneDB->insert('departments', [['id' => 'IT', 'name' => 'IT']]);

        $results = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'id')
            ->get();

        $noDeptRecord = array_values(array_filter($results, function ($r) { return $r['name'] === 'NoDept'; }));
        $this->assertCount(1, $noDeptRecord);

        $this->assertSame(['departments' => null, 'key' => 6, 'name' => 'NoDept'], self::canon($noDeptRecord[0]));
    }

    /**
     * QUERY.md join(): "Joining a database that doesn't exist attaches null and creates nothing."
     *
     * @test
     */
    public function joinWithNonExistentForeignDb(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->join('nonexistent-db', 'department', 'id')
            ->get();

        $expected = [];
        foreach (array_keys(self::PEOPLE) as $key) {
            $expected[] = $this->person($key) + ['nonexistent-db' => null];
        }
        $this->assertSameRecords($expected, $results);

        // Nothing was created: no database, no file of any kind (not even a lock file)
        $this->assertFalse($this->noneDB->getDBs('nonexistent-db'));
        $this->assertNotContains('nonexistent-db', $this->noneDB->getDBs(false));
        $this->assertDirectoryDoesNotExist($this->dbPath('nonexistent-db', 'home'));

        // The same with a key join
        $byKey = $this->noneDB->query($this->testDbName)->join('nonexistent-db', 'age', 'key', 'x')->get();
        $this->assertSame(array_fill(0, 6, null), array_column($byKey, 'x'));
        $this->assertDirectoryDoesNotExist($this->dbPath('nonexistent-db', 'home'));
    }

    /**
     * QUERY.md join(): if several foreign records have the same value, the last one wins
     *
     * @test
     */
    public function joinDuplicateForeignKeyLastRecordWins(): void
    {
        $this->noneDB->insert('departments', [
            ['id' => 'IT', 'name' => 'First IT'],
            ['id' => 'HR', 'name' => 'Human Resources'],
            ['id' => 'IT', 'name' => 'Second IT'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'id')
            ->get();

        foreach ($results as $record) {
            if ($record['department'] === 'IT') {
                $this->assertSame(['id' => 'IT', 'key' => 2, 'name' => 'Second IT'], self::canon($record['departments']), $record['name']);
            } elseif ($record['department'] === 'HR') {
                $this->assertSame(['id' => 'HR', 'key' => 1, 'name' => 'Human Resources'], self::canon($record['departments']), $record['name']);
            } else {
                $this->assertNull($record['departments'], $record['name']);
            }
        }
    }

    /**
     * QUERY.md join(): the attached record replaces a field of the same name in the result only
     *
     * @test
     */
    public function joinReplacesSameNamedFieldInResultOnly(): void
    {
        $this->noneDB->insert('departments', [['id' => 'IT', 'name' => 'IT Dept']]);
        $this->noneDB->insert($this->testDbName, ['name' => 'Grace', 'department' => 'IT', 'departments' => 'stored value']);

        $grace = $this->noneDB->query($this->testDbName)->where(['name' => 'Grace'])->join('departments', 'department', 'id')->get();
        $this->assertCount(1, $grace);
        $this->assertSame(['id' => 'IT', 'key' => 0, 'name' => 'IT Dept'], self::canon($grace[0]['departments']));

        // Storage unchanged
        $stored = $this->noneDB->find($this->testDbName, ['name' => 'Grace']);
        $this->assertSame([['department' => 'IT', 'departments' => 'stored value', 'key' => 6, 'name' => 'Grace']], self::canon($stored));
        $this->assertSame([['id' => 'IT', 'key' => 0, 'name' => 'IT Dept']], self::canon($this->noneDB->find('departments', 0)));
    }

    /**
     * @test
     */
    public function multipleJoins(): void
    {
        $this->noneDB->insert('departments', [
            ['id' => 'IT', 'name' => 'IT Dept'],
        ]);
        $this->noneDB->insert('cities', [
            ['name' => 'Istanbul', 'country' => 'Turkey'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'id', 'dept')
            ->join('cities', 'city', 'name', 'cityInfo')
            ->get();

        $this->assertSame(self::ALL_NAMES, $this->names($results));
        $it = ['id' => 'IT', 'key' => 0, 'name' => 'IT Dept'];
        $istanbul = ['country' => 'Turkey', 'key' => 0, 'name' => 'Istanbul'];
        foreach ($results as $record) {
            $this->assertSame($record['department'] === 'IT' ? $it : null, self::canon($record['dept']), $record['name']);
            $this->assertSame($record['city'] === 'Istanbul' ? $istanbul : null, self::canon($record['cityInfo']), $record['name']);
        }
    }

    /**
     * @test
     */
    public function joinCombinedWithFilters(): void
    {
        $this->noneDB->insert('departments', [
            ['id' => 'IT', 'name' => 'Information Technology'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->join('departments', 'department', 'id')
            ->get();

        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));
        foreach ($results as $record) {
            $this->assertSame(['id' => 'IT', 'key' => 0, 'name' => 'Information Technology'], self::canon($record['departments']));
        }
    }

    // ==========================================
    // skip() AND orderBy() ALIASES TESTS
    // ==========================================

    /**
     * @test
     */
    public function skipIsAliasForOffset(): void
    {
        $skipResults = $this->noneDB->query($this->testDbName)
            ->skip(2)
            ->get();

        $offsetResults = $this->noneDB->query($this->testDbName)
            ->offset(2)
            ->get();

        $this->assertSame($offsetResults, $skipResults);
        $this->assertSame(['Charlie', 'David', 'Eve', 'Frank'], $this->names($skipResults));
    }

    /**
     * @test
     */
    public function orderByIsAliasForSort(): void
    {
        $orderByResults = $this->noneDB->query($this->testDbName)
            ->orderBy('age', 'desc')
            ->get();

        $sortResults = $this->noneDB->query($this->testDbName)
            ->sort('age', 'desc')
            ->get();

        $this->assertSame($sortResults, $orderByResults);
        $this->assertSame(['Frank', 'Charlie', 'Bob', 'David', 'Alice', 'Eve'], $this->names($orderByResults));
    }

    /**
     * @test
     */
    public function skipWithLimit(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->skip(1)
            ->limit(3)
            ->get();

        $this->assertSame(['Bob', 'Charlie', 'David'], $this->names($results));
    }

    /**
     * @test
     */
    public function orderByAscending(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->orderBy('age', 'asc')
            ->get();

        $this->assertSame([22, 25, 28, 30, 35, 40], array_column($results, 'age'));
        $this->assertSame(['Eve', 'Alice', 'David', 'Bob', 'Charlie', 'Frank'], $this->names($results));
    }

    /**
     * @test
     */
    public function orderByDescending(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->orderBy('score', 'desc')
            ->get();

        $this->assertSame([95, 90, 85, 80, 75, 70], array_column($results, 'score'));
        $this->assertSame(['David', 'Bob', 'Alice', 'Eve', 'Charlie', 'Frank'], $this->names($results));
    }

    /**
     * @test
     */
    public function chainableMethodsReturnSelf(): void
    {
        $query = $this->noneDB->query($this->testDbName);

        $this->assertSame($query, $query->where(['city' => 'Istanbul']));
        $this->assertSame($query, $query->orWhere(['city' => 'Istanbul']));
        $this->assertSame($query, $query->whereIn('city', ['Istanbul']));
        $this->assertSame($query, $query->whereNotIn('city', ['Ankara']));
        $this->assertSame($query, $query->whereNot(['active' => false]));
        $this->assertSame($query, $query->like('email', 'mail'));
        $this->assertSame($query, $query->notLike('email', 'yahoo'));
        $this->assertSame($query, $query->between('age', 1, 99));
        $this->assertSame($query, $query->notBetween('age', 50, 60));
        $this->assertSame($query, $query->select(['name']));
        $this->assertSame($query, $query->except(['email']));
        $this->assertSame($query, $query->groupBy('city'));
        $this->assertSame($query, $query->having('count', '>', 0));
        $this->assertSame($query, $query->search('test'));
        $this->assertSame($query, $query->join('other', 'id', 'id'));
        $this->assertSame($query, $query->sort('name'));
        $this->assertSame($query, $query->limit(5));
        $this->assertSame($query, $query->offset(0));
        $this->assertSame($query, $query->skip(1));
        $this->assertSame($query, $query->orderBy('name'));
    }

    // ==========================================
    // COMPLEX COMBINATION TESTS
    // ==========================================

    /**
     * @test
     */
    public function complexQueryWithMultipleFilters(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->whereNotIn('department', ['Sales'])
            ->between('age', 20, 35)
            ->notBetween('score', 70, 75)
            ->like('email', 'gmail')
            ->notLike('name', '^F')
            ->sort('score', 'desc')
            ->limit(2)
            ->select(['name', 'score', 'city'])
            ->get();

        // whereIn: Alice Bob Charlie Eve Frank; between age 20..35 drops Frank; notBetween score 70..75 drops
        // Charlie (75); like gmail drops Bob; left: Alice (85), Eve (80) -> by score desc
        $this->assertSame([
            ['city' => 'Istanbul', 'key' => 0, 'name' => 'Alice', 'score' => 85],
            ['city' => 'Istanbul', 'key' => 4, 'name' => 'Eve', 'score' => 80],
        ], self::canon($results));
    }

    /**
     * @test
     */
    public function paginationWithSearch(): void
    {
        $page1 = $this->noneDB->query($this->testDbName)
            ->search('istanbul')
            ->orderBy('name', 'asc')
            ->limit(2)
            ->skip(0)
            ->get();

        $page2 = $this->noneDB->query($this->testDbName)
            ->search('istanbul')
            ->orderBy('name', 'asc')
            ->limit(2)
            ->skip(2)
            ->get();

        $this->assertSame(['Alice', 'Charlie'], $this->names($page1));
        $this->assertSame(['Eve'], $this->names($page2));
    }
}
