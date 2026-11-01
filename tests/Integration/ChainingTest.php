<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for method chaining (fluent interface)
 *
 * Expected values are derived by hand from the five records inserted in setUp() (keys 0-4 in
 * insertion order) and the rules of docs/QUERY.md / docs/API.md: unsorted results come in key order,
 * sort() is stable (ties keep key order, also for desc), limit()/offset() slice the result, the
 * builder's sum()/avg() return floats, min()/max() return the stored value, distinct() keeps the
 * order of first appearance.
 */
class ChainingTest extends noneDBTestCase
{
    /** The records of setUp(), in key order */
    private const DATA = [
        ['name' => 'Alice', 'age' => 25, 'score' => 85, 'city' => 'Istanbul', 'email' => 'alice@gmail.com', 'active' => true],
        ['name' => 'Bob', 'age' => 30, 'score' => 90, 'city' => 'Ankara', 'email' => 'bob@yahoo.com', 'active' => true],
        ['name' => 'Charlie', 'age' => 35, 'score' => 75, 'city' => 'Istanbul', 'email' => 'charlie@gmail.com', 'active' => false],
        ['name' => 'David', 'age' => 28, 'score' => 95, 'city' => 'Izmir', 'email' => 'david@hotmail.com', 'active' => true],
        ['name' => 'Eve', 'age' => 22, 'score' => 80, 'city' => 'Istanbul', 'email' => 'eve@gmail.com', 'active' => true],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->noneDB->insert($this->testDbName, self::DATA);
    }

    /**
     * Names of the records, in result order
     */
    private function names(array $records): array
    {
        return array_column($records, 'name');
    }

    /**
     * The stored record with this key as find()/get() return it (fields compared order-independently)
     */
    private function record(int $key): array
    {
        $record = self::DATA[$key];
        $record['key'] = $key;
        ksort($record);
        return $record;
    }

    private function canon(array $record): array
    {
        ksort($record);
        return $record;
    }

    /**
     * All records as a fresh instance reads them from disk: [key => record] (fields sorted)
     */
    private function stored(): array
    {
        \noneDB::clearStaticCache();
        $out = [];
        foreach ($this->createTestInstance()->find($this->testDbName, 0) as $record) {
            $out[$record['key']] = $this->canon($record);
        }
        return $out;
    }

    // ==========================================
    // QUERY BUILDER CREATION
    // ==========================================

    /**
     * @test
     */
    public function queryReturnsQueryBuilder(): void
    {
        $query = $this->noneDB->query($this->testDbName);

        $this->assertInstanceOf(\noneDBQuery::class, $query);
        // Every call starts a new query: filters of one builder don't leak into another
        $this->assertNotSame($query, $this->noneDB->query($this->testDbName));
    }

    // ==========================================
    // CHAINABLE METHODS
    // ==========================================

    /**
     * @test
     */
    public function whereFiltersResults(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->get();

        // Whole records with their key, in key order
        $this->assertSame([$this->record(0), $this->record(2), $this->record(4)], array_map([$this, 'canon'], $results));
    }

    /**
     * @test
     */
    public function whereWithMultipleFilters(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul', 'active' => true])
            ->get();

        // AND of both fields: Charlie (Istanbul, inactive) is out
        $this->assertSame(['Alice', 'Eve'], $this->names($results));
        $this->assertSame([0, 4], array_column($results, 'key'));
    }

    /**
     * @test
     */
    public function whereValuesCompareStrictly(): void
    {
        // Plain values compare with === (QUERY.md): '25' is not 25, 1 is not true
        $this->assertSame([], $this->noneDB->query($this->testDbName)->where(['age' => '25'])->get());
        $this->assertSame([], $this->noneDB->query($this->testDbName)->where(['active' => 1])->get());
        $this->assertSame(['Alice'], $this->names($this->noneDB->query($this->testDbName)->where(['age' => 25])->get()));
    }

    /**
     * @test
     */
    public function likeFiltersWithContains(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('email', 'gmail')
            ->get();

        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));
        // Case-insensitive (QUERY.md: like() compares case-insensitively)
        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($this->noneDB->query($this->testDbName)->like('email', 'GMAIL')->get()));
    }

    /**
     * @test
     */
    public function likeFiltersWithStartsWith(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('name', '^A')
            ->get();

        $this->assertSame(['Alice'], $this->names($results));
        // Without the anchor, "a" anywhere matches (case-insensitive): Alice, Charlie, David
        $this->assertSame(['Alice', 'Charlie', 'David'], $this->names($this->noneDB->query($this->testDbName)->like('name', 'a')->get()));
        // The anchor is really the start: "^l" matches no name although Alice contains "l"
        $this->assertSame([], $this->noneDB->query($this->testDbName)->like('name', '^l')->get());
    }

    /**
     * @test
     */
    public function likeFiltersWithEndsWith(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('email', 'gmail.com$')
            ->get();

        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));
        // The anchor is really the end: "gmail$" matches nothing (every email ends with ".com")
        $this->assertSame([], $this->noneDB->query($this->testDbName)->like('email', 'gmail$')->get());
        // "." is literal (QUERY.md): "gmail_com$" with any other character in place of the dot doesn't match
        $this->assertSame([], $this->noneDB->query($this->testDbName)->like('email', 'gmailxcom$')->get());
    }

    /**
     * @test
     */
    public function betweenFiltersRange(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->between('age', 25, 30)
            ->get();

        // Inclusive on both ends: 25 (Alice), 30 (Bob) and 28 (David); 22 and 35 are outside
        $this->assertSame(['Alice', 'Bob', 'David'], $this->names($results));
        $this->assertSame([25, 30, 28], array_column($results, 'age'));
    }

    /**
     * @test
     */
    public function sortAscending(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('age', 'asc')
            ->get();

        $this->assertSame([22, 25, 28, 30, 35], array_column($results, 'age'));
        $this->assertSame(['Eve', 'Alice', 'David', 'Bob', 'Charlie'], $this->names($results));
        // asc is the default order
        $this->assertSame($results, $this->noneDB->query($this->testDbName)->sort('age')->get());
    }

    /**
     * @test
     */
    public function sortDescending(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('score', 'desc')
            ->get();

        $this->assertSame([95, 90, 85, 80, 75], array_column($results, 'score'));
        $this->assertSame(['David', 'Bob', 'Alice', 'Eve', 'Charlie'], $this->names($results));
    }

    /**
     * @test
     */
    public function sortTiesKeepKeyOrderAscendingAndDescending(): void
    {
        // QUERY.md: sorting is stable; ties (three Istanbul records) keep key order, also for desc
        $asc = $this->noneDB->query($this->testDbName)->sort('city')->get();
        $this->assertSame(['Bob', 'Alice', 'Charlie', 'Eve', 'David'], $this->names($asc));

        $desc = $this->noneDB->query($this->testDbName)->sort('city', 'desc')->get();
        $this->assertSame(['David', 'Alice', 'Charlie', 'Eve', 'Bob'], $this->names($desc));

        // With a small limit (top-k) the result is the same as the full sort, sliced
        $this->assertSame(['Bob', 'Alice', 'Charlie'], $this->names($this->noneDB->query($this->testDbName)->sort('city')->limit(3)->get()));
        $this->assertSame(['Charlie', 'Eve'], $this->names($this->noneDB->query($this->testDbName)->sort('city', 'desc')->offset(2)->limit(2)->get()));
    }

    /**
     * @test
     */
    public function secondSortCallBreaksTies(): void
    {
        // QUERY.md: sort('category')->sort('price', 'desc'): city first, then score descending
        $results = $this->noneDB->query($this->testDbName)->sort('city')->sort('score', 'desc')->get();

        $this->assertSame(['Bob', 'Alice', 'Eve', 'Charlie', 'David'], $this->names($results));
    }

    /**
     * @test
     */
    public function limitResults(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->limit(3)
            ->get();

        // Without sort() the first records in key order
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($results));
        $this->assertSame([0, 1, 2], array_column($results, 'key'));
    }

    /**
     * @test
     */
    public function offsetResults(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->offset(2)
            ->get();

        $this->assertSame(['Charlie', 'David', 'Eve'], $this->names($results));
        // An offset past the end returns nothing
        $this->assertSame([], $this->noneDB->query($this->testDbName)->offset(5)->get());
    }

    /**
     * @test
     */
    public function limitWithOffset(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->offset(1)
            ->limit(2)
            ->get();

        $this->assertSame(['Bob', 'Charlie'], $this->names($results));
        // A limit larger than what is left returns the rest
        $this->assertSame(['David', 'Eve'], $this->names($this->noneDB->query($this->testDbName)->offset(3)->limit(10)->get()));
    }

    /**
     * @test
     */
    public function aliasesSkipAndOrderByBehaveLikeOffsetAndSort(): void
    {
        // QUERY.md: skip() is an alias of offset(), orderBy() of sort()
        $this->assertSame(
            $this->noneDB->query($this->testDbName)->sort('score', 'desc')->offset(1)->limit(2)->get(),
            $this->noneDB->query($this->testDbName)->orderBy('score', 'desc')->skip(1)->limit(2)->get()
        );
        $this->assertSame(['Bob', 'Alice'], $this->names($this->noneDB->query($this->testDbName)->orderBy('score', 'desc')->skip(1)->limit(2)->get()));
    }

    /**
     * @test
     */
    public function multipleChaining(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->between('age', 20, 30)
            ->sort('score', 'desc')
            ->limit(2)
            ->get();

        // active and 20 <= age <= 30: Alice 85, Bob 90, David 95, Eve 80 -> by score desc, first two
        $this->assertSame(['David', 'Bob'], $this->names($results));
    }

    // ==========================================
    // TERMINAL METHODS
    // ==========================================

    /**
     * @test
     */
    public function getReturnsArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)->get();

        // Every record, whole, with its key, in key order (a list)
        $this->assertSame([0, 1, 2, 3, 4], array_keys($results));
        $this->assertSame(array_map([$this, 'record'], [0, 1, 2, 3, 4]), array_map([$this, 'canon'], $results));
    }

    /**
     * @test
     */
    public function firstReturnsFirstRecord(): void
    {
        $result = $this->noneDB->query($this->testDbName)->first();

        $this->assertSame($this->record(0), $this->canon($result));
    }

    /**
     * @test
     */
    public function firstWithFilterReturnsFirstMatch(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->first();

        $this->assertSame($this->record(0), $this->canon($result));
        // With sort(): the first of the sorted result (youngest in Istanbul: Eve)
        $this->assertSame($this->record(4), $this->canon($this->noneDB->query($this->testDbName)->where(['city' => 'Istanbul'])->sort('age')->first()));
    }

    /**
     * @test
     */
    public function firstReturnsNullForNoMatch(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'NonExistent'])
            ->first();

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function lastReturnsLastRecord(): void
    {
        $result = $this->noneDB->query($this->testDbName)->last();

        $this->assertSame($this->record(4), $this->canon($result));
        $this->assertNull($this->noneDB->query($this->testDbName)->where(['city' => 'NonExistent'])->last());
    }

    /**
     * @test
     */
    public function lastWithSortReturnsCorrectRecord(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->sort('age', 'asc')
            ->last();

        $this->assertSame($this->record(2), $this->canon($result)); // oldest: Charlie
    }

    /**
     * @test
     */
    public function lastAppliesOffsetAndLimit(): void
    {
        // QUERY.md: last() is the last record of the result after sort(), offset() and limit()
        // ages ascending: Eve 22, Alice 25, David 28, Bob 30, Charlie 35
        $this->assertSame('Alice', $this->noneDB->query($this->testDbName)->sort('age')->limit(2)->last()['name']);
        $this->assertSame('Bob', $this->noneDB->query($this->testDbName)->sort('age')->offset(2)->limit(2)->last()['name']);
        $this->assertNull($this->noneDB->query($this->testDbName)->sort('age')->offset(5)->last());
    }

    /**
     * @test
     */
    public function countReturnsInteger(): void
    {
        $count = $this->noneDB->query($this->testDbName)->count();

        $this->assertSame(5, $count);
    }

    /**
     * @test
     */
    public function countWithFilter(): void
    {
        $count = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->count();

        $this->assertSame(4, $count);
    }

    /**
     * @test
     */
    public function countAppliesLimitAndOffset(): void
    {
        // QUERY.md: limit() and offset() apply to count() (4 active records)
        $this->assertSame(2, $this->noneDB->query($this->testDbName)->where(['active' => true])->offset(2)->count());
        $this->assertSame(3, $this->noneDB->query($this->testDbName)->where(['active' => true])->limit(3)->count());
        $this->assertSame(1, $this->noneDB->query($this->testDbName)->where(['active' => true])->offset(3)->limit(3)->count());
        $this->assertSame(0, $this->noneDB->query($this->testDbName)->where(['active' => true])->offset(4)->count());
    }

    /**
     * @test
     */
    public function existsReturnsTrueWhenFound(): void
    {
        $exists = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Alice'])
            ->exists();

        $this->assertTrue($exists);
    }

    /**
     * @test
     */
    public function existsReturnsFalseWhenNotFound(): void
    {
        $exists = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->exists();

        $this->assertFalse($exists);
    }

    // ==========================================
    // AGGREGATION METHODS
    // ==========================================

    /**
     * @test
     */
    public function sumCalculatesTotal(): void
    {
        $sum = $this->noneDB->query($this->testDbName)->sum('score');

        $this->assertSame(425.0, $sum); // 85+90+75+95+80, the builder's sum() returns a float
    }

    /**
     * @test
     */
    public function sumWithFilter(): void
    {
        $sum = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->sum('score');

        $this->assertSame(240.0, $sum); // 85+75+80
        $this->assertSame(0.0, $this->noneDB->query($this->testDbName)->where(['city' => 'Nowhere'])->sum('score'));
    }

    /**
     * @test
     */
    public function avgCalculatesAverage(): void
    {
        $avg = $this->noneDB->query($this->testDbName)->avg('score');

        $this->assertSame(85.0, $avg); // 425/5
    }

    /**
     * @test
     */
    public function avgWithFilter(): void
    {
        $avg = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->avg('age');

        $this->assertSame(26.25, $avg); // (25+30+28+22)/4
        $this->assertSame(0.0, $this->noneDB->query($this->testDbName)->where(['city' => 'Nowhere'])->avg('age'));
    }

    /**
     * @test
     */
    public function minReturnsMinimum(): void
    {
        $min = $this->noneDB->query($this->testDbName)->min('age');

        $this->assertSame(22, $min); // the stored value, int
        $this->assertNull($this->noneDB->query($this->testDbName)->where(['city' => 'Nowhere'])->min('age'));
    }

    /**
     * @test
     */
    public function maxReturnsMaximum(): void
    {
        $max = $this->noneDB->query($this->testDbName)->max('score');

        $this->assertSame(95, $max);
        $this->assertSame(85, $this->noneDB->query($this->testDbName)->where(['city' => 'Istanbul'])->max('score'));
        $this->assertNull($this->noneDB->query($this->testDbName)->max('nonexistent'));
    }

    /**
     * @test
     */
    public function distinctReturnsUniqueValues(): void
    {
        $cities = $this->noneDB->query($this->testDbName)->distinct('city');

        // Order of first appearance (QUERY.md)
        $this->assertSame(['Istanbul', 'Ankara', 'Izmir'], $cities);
    }

    /**
     * @test
     */
    public function distinctWithFilter(): void
    {
        $cities = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->distinct('city');

        $this->assertSame(['Istanbul', 'Ankara', 'Izmir'], $cities);
        $this->assertSame(['Istanbul'], $this->noneDB->query($this->testDbName)->where(['active' => false])->distinct('city'));
        $this->assertSame([], $this->noneDB->query($this->testDbName)->where(['active' => false])->distinct('nonexistent'));
    }

    // ==========================================
    // WRITE METHODS
    // ==========================================

    /**
     * @test
     */
    public function updateWithChaining(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->update(['verified' => true]);

        $this->assertSame(['n' => 3], $result);

        // Stored: Istanbul records (0, 2, 4) got the field, the others are unchanged
        $expected = [];
        foreach ([0, 1, 2, 3, 4] as $key) {
            $record = $this->record($key);
            if (in_array($key, [0, 2, 4], true)) {
                $record['verified'] = true;
                ksort($record);
            }
            $expected[$key] = $record;
        }
        $this->assertSame($expected, $this->stored());
    }

    /**
     * @test
     */
    public function updateWithMultipleFilters(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->between('age', 25, 30)
            ->update(['tier' => 'gold']);

        // active and 25 <= age <= 30: Alice (0), Bob (1), David (3)
        $this->assertSame(['n' => 3], $result);
        $stored = $this->stored();
        foreach ([0, 1, 2, 3, 4] as $key) {
            if (in_array($key, [0, 1, 3], true)) {
                $this->assertSame('gold', $stored[$key]['tier'], "key $key");
            } else {
                $this->assertArrayNotHasKey('tier', $stored[$key], "key $key");
            }
        }
    }

    /**
     * @test
     */
    public function updateReturnsZeroForNoMatch(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'NonExistent'])
            ->update(['verified' => true]);

        $this->assertSame(['n' => 0], $result);
        $this->assertSame(array_map([$this, 'record'], [0, 1, 2, 3, 4]), $this->stored());
    }

    /**
     * @test
     */
    public function updateWithSortAndLimitChoosesTheRecords(): void
    {
        // QUERY.md: sort(), offset() and limit() choose which records are written: the two oldest
        $result = $this->noneDB->query($this->testDbName)->sort('age', 'desc')->limit(2)->update(['senior' => true]);

        $this->assertSame(['n' => 2], $result);
        $this->assertSame([1, 2], array_column($this->noneDB->find($this->testDbName, ['senior' => true]), 'key')); // Bob 30, Charlie 35
    }

    /**
     * @test
     */
    public function deleteWithChaining(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['active' => false])
            ->delete();

        $this->assertSame(['n' => 1], $result);
        $this->assertSame(4, $this->noneDB->count($this->testDbName));
        $this->assertSame(array_map([$this, 'record'], [0 => 0, 1 => 1, 3 => 3, 4 => 4]), $this->stored());
    }

    /**
     * @test
     */
    public function deleteWithLikeFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->like('email', 'yahoo')
            ->delete();

        $this->assertSame(['n' => 1], $result);
        $this->assertSame(4, $this->noneDB->count($this->testDbName));
        $this->assertSame([0, 2, 3, 4], array_keys($this->stored())); // Bob (1) is gone
    }

    /**
     * @test
     */
    public function deleteReturnsZeroForNoMatch(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'NonExistent'])
            ->delete();

        $this->assertSame(['n' => 0], $result);
        $this->assertSame(5, $this->noneDB->count($this->testDbName));
        $this->assertSame(array_map([$this, 'record'], [0, 1, 2, 3, 4]), $this->stored());
    }

    /**
     * @test
     */
    public function deleteWithSortOffsetAndLimitChoosesTheRecords(): void
    {
        // Scores descending: David 95, Bob 90, Alice 85, Eve 80, Charlie 75 -> offset 1, limit 2: Bob, Alice
        $result = $this->noneDB->query($this->testDbName)->sort('score', 'desc')->offset(1)->limit(2)->delete();

        $this->assertSame(['n' => 2], $result);
        $this->assertSame([2, 3, 4], array_keys($this->stored()));
    }

    /**
     * @test
     */
    public function writesWithGroupByThrowLogicException(): void
    {
        // API.md: update(), delete() and removeFields() throw LogicException together with groupBy()
        foreach (['update', 'delete'] as $method) {
            try {
                $query = $this->noneDB->query($this->testDbName)->groupBy('city');
                $method === 'update' ? $query->update(['x' => 1]) : $query->delete();
                $this->fail("$method() with groupBy() must throw");
            } catch (\LogicException $e) {
                $this->assertSame(\LogicException::class, get_class($e));
                $this->assertStringContainsString('groupBy()', $e->getMessage());
            }
        }
        $this->assertSame(array_map([$this, 'record'], [0, 1, 2, 3, 4]), $this->stored()); // nothing written
    }

    // ==========================================
    // EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function emptyDatabaseReturnsEmptyArray(): void
    {
        $this->noneDB->createDB('emptydb');
        $results = $this->noneDB->query('emptydb')->get();

        $this->assertSame([], $results);
        $this->assertSame(0, $this->noneDB->query('emptydb')->count());
        $this->assertNull($this->noneDB->query('emptydb')->first());
        $this->assertFalse($this->noneDB->query('emptydb')->exists());
    }

    /**
     * @test
     */
    public function queryOnMissingDatabaseReturnsNothingAndCreatesNothing(): void
    {
        // API.md: reading never creates a database
        $this->assertSame([], $this->noneDB->query('missingdb')->where(['a' => 1])->get());
        $this->assertSame(0, $this->noneDB->query('missingdb')->count());
        $this->assertNotContains('missingdb', $this->noneDB->getDBs(false));
        $this->assertFalse($this->noneDB->getDBs('missingdb'));
    }

    /**
     * @test
     */
    public function chainMethodsReturnSelf(): void
    {
        $query = $this->noneDB->query($this->testDbName);

        $this->assertSame($query, $query->where(['active' => true]));
        $this->assertSame($query, $query->orWhere(['city' => 'Izmir']));
        $this->assertSame($query, $query->whereIn('city', ['Istanbul']));
        $this->assertSame($query, $query->whereNotIn('city', ['Ankara']));
        $this->assertSame($query, $query->whereNot(['name' => 'Bob']));
        $this->assertSame($query, $query->like('name', 'A'));
        $this->assertSame($query, $query->notLike('name', 'Z'));
        $this->assertSame($query, $query->between('age', 20, 40));
        $this->assertSame($query, $query->notBetween('age', 100, 200));
        $this->assertSame($query, $query->search('a', ['name']));
        $this->assertSame($query, $query->select(['name']));
        $this->assertSame($query, $query->except(['email']));
        $this->assertSame($query, $query->join('other', 'city', 'city'));
        $this->assertSame($query, $query->groupBy('city'));
        $this->assertSame($query, $query->having('count', '>', 0));
        $this->assertSame($query, $query->sort('name'));
        $this->assertSame($query, $query->orderBy('age', 'desc'));
        $this->assertSame($query, $query->limit(10));
        $this->assertSame($query, $query->offset(5));
        $this->assertSame($query, $query->skip(1));
        $this->assertSame($query, $query->withinDistance('loc', 28.9, 41.0, 1000));
        $this->assertSame($query, $query->withinBBox('loc', 28.0, 40.0, 29.0, 42.0));
        $this->assertSame($query, $query->nearest('loc', 28.9, 41.0, 3));
        $this->assertSame($query, $query->withinPolygon('loc', ['type' => 'Polygon', 'coordinates' => [[[28, 40], [29, 40], [29, 41], [28, 40]]]]));
        $this->assertSame($query, $query->withDistance('loc', 28.9, 41.0));
    }

    /**
     * @test
     */
    public function paginationExample(): void
    {
        $pageSize = 2;
        $page = function (int $n) use ($pageSize) {
            return $this->names($this->noneDB->query($this->testDbName)
                ->sort('name')
                ->limit($pageSize)
                ->offset(($n - 1) * $pageSize)
                ->get());
        };

        // Names sorted: Alice, Bob, Charlie, David, Eve
        $this->assertSame(['Alice', 'Bob'], $page(1));
        $this->assertSame(['Charlie', 'David'], $page(2));
        $this->assertSame(['Eve'], $page(3));
        $this->assertSame([], $page(4));
    }
}
