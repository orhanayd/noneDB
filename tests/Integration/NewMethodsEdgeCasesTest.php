<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Additional edge case tests for newly added chaining methods
 * These tests cover scenarios not covered in NewChainingMethodsTest
 */
class NewMethodsEdgeCasesTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Insert standard test data
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25, 'city' => 'Istanbul', 'email' => 'alice@gmail.com', 'score' => 85, 'department' => 'IT', 'active' => true],
            ['name' => 'Bob', 'age' => 30, 'city' => 'Ankara', 'email' => 'bob@yahoo.com', 'score' => 90, 'department' => 'HR', 'active' => true],
            ['name' => 'Charlie', 'age' => 35, 'city' => 'Istanbul', 'email' => 'charlie@gmail.com', 'score' => 75, 'department' => 'IT', 'active' => false],
            ['name' => 'David', 'age' => 28, 'city' => 'Izmir', 'email' => 'david@hotmail.com', 'score' => 95, 'department' => 'Sales', 'active' => true],
            ['name' => 'Eve', 'age' => 22, 'city' => 'Istanbul', 'email' => 'eve@gmail.com', 'score' => 80, 'department' => 'IT', 'active' => true],
            ['name' => 'Frank', 'age' => 40, 'city' => 'Ankara', 'email' => 'frank@yahoo.com', 'score' => 70, 'department' => 'HR', 'active' => false],
        ]);
    }

    /** Names of the result rows in result order (results without sort() come in key order) */
    private function names(array $rows): array
    {
        return array_column($rows, 'name');
    }

    /** A stored record as find() returns it, fields compared regardless of their position */
    private function normalized(array $record): array
    {
        ksort($record);
        return $record;
    }

    // ==========================================
    // orWhere + OTHER FILTERS COMBINATION
    // ==========================================

    /**
     * @test
     */
    public function orWhereWithLike(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Izmir'])
            ->orWhere(['city' => 'Istanbul'])
            ->like('email', 'gmail')
            ->get();

        // Izmir OR Istanbul, then filter by gmail
        // Istanbul: Alice, Charlie, Eve (all gmail)
        // Izmir: David (hotmail) - filtered out
        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));
    }

    /**
     * @test
     */
    public function orWhereWithBetween(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->orWhere(['department' => 'HR'])
            ->between('age', 25, 35)
            ->get();

        // IT OR HR, then age 25-35
        // IT: Alice(25), Charlie(35), Eve(22-out)
        // HR: Bob(30), Frank(40-out)
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($results));
    }

    /**
     * @test
     */
    public function orWhereWithWhereIn(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Izmir'])
            ->orWhere(['city' => 'Ankara'])
            ->whereIn('department', ['HR', 'Sales'])
            ->get();

        // Izmir OR Ankara = Bob, David, Frank
        // Then whereIn HR or Sales = Bob(HR), David(Sales), Frank(HR)
        $this->assertSame(['Bob', 'David', 'Frank'], $this->names($results));
    }

    /**
     * @test
     */
    public function orWhereWithWhereNot(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->orWhere(['city' => 'Ankara'])
            ->whereNot(['active' => false])
            ->get();

        // Istanbul OR Ankara = Alice, Bob, Charlie, Eve, Frank
        // Then exclude active=false: Charlie, Frank excluded
        $this->assertSame(['Alice', 'Bob', 'Eve'], $this->names($results));
    }

    /**
     * @test
     */
    public function orWhereWithSearch(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->orWhere(['department' => 'Sales'])
            ->search('gmail')
            ->get();

        // IT OR Sales = Alice, Charlie, Eve, David
        // Search gmail = Alice, Charlie, Eve (David is hotmail)
        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));
    }

    // ==========================================
    // whereIn WITH NULL VALUES
    // ==========================================

    /**
     * @test
     */
    public function whereInWithNullInArray(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NullCity', 'city' => null]);
        $this->noneDB->insert($this->testDbName, ['name' => 'NoCity']);

        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', [null, 'Istanbul'])
            ->get();

        // Istanbul (3) + the stored null (1); a missing field is not a null value
        $this->assertSame(['Alice', 'Charlie', 'Eve', 'NullCity'], $this->names($results));
    }

    /**
     * @test
     */
    public function whereNotInWithNullInArray(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NullCity', 'city' => null]);
        $this->noneDB->insert($this->testDbName, ['name' => 'NoCity']);

        $results = $this->noneDB->query($this->testDbName)
            ->whereNotIn('city', [null])
            ->get();

        // Excludes the stored null only: the 6 original records and the record without city
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'David', 'Eve', 'Frank', 'NoCity'], $this->names($results));
    }

    /**
     * @test
     */
    public function whereInWithBooleanValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('active', [true])
            ->get();

        // Alice, Bob, David, Eve are active
        $this->assertSame(['Alice', 'Bob', 'David', 'Eve'], $this->names($results));
        // strict: 1 is not true
        $this->assertSame([], $this->noneDB->query($this->testDbName)->whereIn('active', [1, 'true'])->get());
    }

    /**
     * @test
     */
    public function whereInWithMixedTypes(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'Mixed', 'value' => 0]);
        $this->noneDB->insert($this->testDbName, ['name' => 'Mixed2', 'value' => '0']);
        $this->noneDB->insert($this->testDbName, ['name' => 'Mixed3', 'value' => false]);

        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('value', [0]) // Integer 0
            ->get();

        // Strict comparison: only integer 0 matches
        $this->assertSame(['Mixed'], $this->names($results));
        $this->assertSame(['Mixed2'], $this->names($this->noneDB->query($this->testDbName)->whereIn('value', ['0'])->get()));
        $this->assertSame(['Mixed3'], $this->names($this->noneDB->query($this->testDbName)->whereIn('value', [false])->get()));
        $this->assertSame(['Mixed', 'Mixed3'], $this->names($this->noneDB->query($this->testDbName)->whereIn('value', [0, false])->get()));
        // also strict with a long list (more than 8 values)
        $long = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
        $this->assertSame(['Mixed'], $this->names($this->noneDB->query($this->testDbName)->whereIn('value', $long)->get()));
        $this->assertSame(['Mixed2', 'Mixed3'], $this->names($this->noneDB->query($this->testDbName)->whereNotIn('value', $long)->where(['name' => ['$like' => '^Mixed']])->get()));
    }

    // ==========================================
    // search() EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function searchWithEmptyTerm(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('')
            ->get();

        // Empty string matches everything
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'David', 'Eve', 'Frank'], $this->names($results));
    }

    /**
     * @test
     */
    public function searchWithSpecialCharacters(): void
    {
        $this->noneDB->insert($this->testDbName, [
            'name' => 'Special',
            'email' => 'test+special@test.com',
            'note' => 'Contains (parentheses) and [brackets]'
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->search('(parentheses)')
            ->get();

        $this->assertSame(['Special'], $this->names($results));
        $this->assertSame(['Special'], $this->names($this->noneDB->query($this->testDbName)->search('+special@')->get()));
        $this->assertSame(['Special'], $this->names($this->noneDB->query($this->testDbName)->search('[BRACKETS]')->get()));
    }

    /**
     * @test
     */
    public function searchWithUnicodeCharacters(): void
    {
        $this->noneDB->insert($this->testDbName, [
            'name' => 'Turkish',
            'note' => 'Merhaba Dünya!'
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->search('dünya')
            ->get();

        $this->assertSame(['Turkish'], $this->names($results));
        $this->assertSame([], $this->noneDB->query($this->testDbName)->search('dunya')->get(), 'ü is not u');
    }

    /**
     * @test
     */
    public function searchOnlyInNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('alice', ['nonexistent_field'])
            ->get();

        // No records have nonexistent_field, so nothing matches
        $this->assertSame([], $results);
        $this->assertSame(['Alice'], $this->names($this->noneDB->query($this->testDbName)->search('alice', ['nonexistent_field', 'name'])->get()));
    }

    // ==========================================
    // groupBy() EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function groupByWithEmptyDatabase(): void
    {
        $this->noneDB->createDB('emptydb');

        $results = $this->noneDB->query('emptydb')
            ->groupBy('city')
            ->get();

        $this->assertSame([], $results);
        $this->assertSame(0, $this->noneDB->query('emptydb')->groupBy('city')->count());
    }

    /**
     * @test
     */
    public function groupByWithLimit(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->sort('_count', 'desc')
            ->limit(2)
            ->get();

        // Should get top 2 cities by count
        $this->assertSame([['Istanbul', 3], ['Ankara', 2]], array_map(function ($g) { return [$g['_group'], $g['_count']]; }, $results));
    }

    /**
     * @test
     */
    public function groupByWithOffset(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->sort('_count', 'desc')
            ->offset(1)
            ->get();

        // Skip Istanbul (first), get Ankara and Izmir
        $this->assertSame([['Ankara', 2], ['Izmir', 1]], array_map(function ($g) { return [$g['_group'], $g['_count']]; }, $results));
    }

    /**
     * @test
     */
    public function groupByPreservesOriginalRecordsInItems(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->groupBy('department')
            ->get();

        // All Istanbul are IT, so 1 group
        $this->assertCount(1, $results);
        $this->assertSame('IT', $results[0]['_group']);
        $this->assertSame(3, $results[0]['_count']);

        // The items are the complete stored records
        $stored = $this->noneDB->find($this->testDbName, ['key' => [0, 2, 4]]);
        $this->assertSame(array_map([$this, 'normalized'], $stored), array_map([$this, 'normalized'], $results[0]['_items']));
    }

    // ==========================================
    // having() WITHOUT groupBy
    // ==========================================

    /**
     * @test
     */
    public function havingWithoutGroupByIsIgnored(): void
    {
        // having without groupBy should have no effect
        $results = $this->noneDB->query($this->testDbName)
            ->having('count', '>', 1)
            ->get();

        // Should return all records (having ignored)
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'David', 'Eve', 'Frank'], $this->names($results));
        $this->assertSame(6, $this->noneDB->query($this->testDbName)->having('count', '>', 1)->count());
        $this->assertTrue($this->noneDB->query($this->testDbName)->having('count', '>', 100)->exists());
    }

    /**
     * @test
     */
    public function havingWithEmptyGroups(): void
    {
        // Filter out all records first, then groupBy
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'NonExistent'])
            ->groupBy('department')
            ->having('count', '>', 0)
            ->get();

        $this->assertSame([], $results);
    }

    // ==========================================
    // select() + except() TOGETHER
    // ==========================================

    /**
     * @test
     */
    public function selectAndExceptTogether(): void
    {
        // When both are set, select takes priority
        $results = $this->noneDB->query($this->testDbName)
            ->select(['name', 'age', 'city'])
            ->except(['age']) // Should be ignored since select is set
            ->get();

        // select takes priority, age should be included (not documented; see QUERY.md select()/except())
        $this->assertSame([
            ['name' => 'Alice', 'age' => 25, 'city' => 'Istanbul', 'key' => 0],
            ['name' => 'Bob', 'age' => 30, 'city' => 'Ankara', 'key' => 1],
            ['name' => 'Charlie', 'age' => 35, 'city' => 'Istanbul', 'key' => 2],
            ['name' => 'David', 'age' => 28, 'city' => 'Izmir', 'key' => 3],
            ['name' => 'Eve', 'age' => 22, 'city' => 'Istanbul', 'key' => 4],
            ['name' => 'Frank', 'age' => 40, 'city' => 'Ankara', 'key' => 5],
        ], array_map(function ($r) {
            // field order of the expectation (the position of "key" is not documented)
            return ['name' => $r['name'] ?? null, 'age' => $r['age'] ?? null, 'city' => $r['city'] ?? null, 'key' => $r['key'] ?? null] + $r;
        }, $results));
    }

    /**
     * @test
     */
    public function selectAfterGroupBy(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->select(['_group', '_count'])
            ->get();

        // QUERY.md: _group, _count and _items select parts of each group row: only those two
        $rows = array_map([$this, 'normalized'], $results);
        usort($rows, function ($a, $b) { return strcmp($a['_group'], $b['_group']); });
        $this->assertSame([
            ['_count' => 2, '_group' => 'Ankara'],
            ['_count' => 3, '_group' => 'Istanbul'],
            ['_count' => 1, '_group' => 'Izmir'],
        ], $rows);
    }

    // ==========================================
    // TERMINAL METHODS WITH NEW FILTERS
    // ==========================================

    /**
     * @test
     */
    public function firstWithOrWhere(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Izmir'])
            ->orWhere(['city' => 'Istanbul'])
            ->sort('name')
            ->first();

        $this->assertSame($this->normalized($this->noneDB->find($this->testDbName, ['key' => 0])[0]), $this->normalized($result));
    }

    /**
     * @test
     */
    public function lastWithWhereIn(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->sort('name')
            ->last();

        $this->assertSame('Frank', $result['name']);
        $this->assertSame(5, $result['key']);
    }

    /**
     * @test
     */
    public function countWithWhereNot(): void
    {
        $count = $this->noneDB->query($this->testDbName)
            ->whereNot(['active' => false])
            ->count();

        $this->assertSame(4, $count); // Alice, Bob, David, Eve
    }

    /**
     * @test
     */
    public function existsWithNotLike(): void
    {
        $exists = $this->noneDB->query($this->testDbName)
            ->notLike('email', 'gmail')
            ->notLike('email', 'yahoo')
            ->exists();

        $this->assertTrue($exists); // David (hotmail)
        $this->assertFalse($this->noneDB->query($this->testDbName)
            ->notLike('email', 'gmail')->notLike('email', 'yahoo')->notLike('email', 'HOTMAIL')->exists());
    }

    /**
     * @test
     */
    public function sumWithNotBetween(): void
    {
        $sum = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 25, 35)
            ->sum('score');

        // Eve(22)=80, Frank(40)=70; the builder's sum() is a float
        $this->assertSame(150.0, $sum);
    }

    /**
     * @test
     */
    public function avgWithSearch(): void
    {
        $avg = $this->noneDB->query($this->testDbName)
            ->search('gmail')
            ->avg('age');

        // Alice(25), Charlie(35), Eve(22) = 82/3
        $this->assertSame(82 / 3, $avg);
    }

    /**
     * @test
     */
    public function minWithWhereIn(): void
    {
        $min = $this->noneDB->query($this->testDbName)
            ->whereIn('department', ['IT', 'Sales'])
            ->min('age');

        // IT: 25, 35, 22. Sales: 28. Min = 22
        $this->assertSame(22, $min);
    }

    /**
     * @test
     */
    public function maxWithWhereNotIn(): void
    {
        $max = $this->noneDB->query($this->testDbName)
            ->whereNotIn('city', ['Istanbul'])
            ->max('score');

        // Ankara: 90, 70. Izmir: 95. Max = 95
        $this->assertSame(95, $max);
    }

    /**
     * @test
     */
    public function distinctWithOrWhere(): void
    {
        $cities = $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->orWhere(['department' => 'HR'])
            ->distinct('city');

        // IT: Istanbul(3). HR: Ankara(2). Distinct cities in order of first appearance (Alice, Bob)
        $this->assertSame(['Istanbul', 'Ankara'], $cities);
    }

    // ==========================================
    // UPDATE/DELETE WITH NEW FILTERS
    // ==========================================

    /**
     * @test
     */
    public function updateWithOrWhere(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->orWhere(['city' => 'Ankara'])
            ->update(['region' => 'Marmara']);

        $this->assertSame(['n' => 5], $result);

        // Verify update: every Istanbul and Ankara record, nothing else
        $updated = $this->noneDB->find($this->testDbName, ['region' => 'Marmara']);
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Eve', 'Frank'], $this->names($updated));
        $this->assertArrayNotHasKey('region', $this->noneDB->find($this->testDbName, ['key' => 3])[0]);
    }

    /**
     * @test
     */
    public function updateWithWhereIn(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->whereIn('department', ['IT', 'HR'])
            ->update(['type' => 'office']);

        $this->assertSame(['n' => 5], $result); // 3 IT + 2 HR
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Eve', 'Frank'], $this->names($this->noneDB->find($this->testDbName, ['type' => 'office'])));
        $this->assertSame(6, $this->noneDB->count($this->testDbName));
    }

    /**
     * @test
     */
    public function deleteWithWhereNot(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->whereNot(['active' => true])
            ->delete();

        $this->assertSame(['n' => 2], $result); // Charlie, Frank

        // Verify remaining
        $this->assertSame(['Alice', 'Bob', 'David', 'Eve'], $this->names($this->noneDB->find($this->testDbName, 0)));
        $this->assertSame(4, $this->noneDB->count($this->testDbName));
    }

    /**
     * @test
     */
    public function deleteWithNotBetween(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 25, 35)
            ->delete();

        $this->assertSame(['n' => 2], $result); // Eve(22), Frank(40)

        // Remaining: the records with ages 25-35
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'David'], $this->names($this->noneDB->find($this->testDbName, 0)));
    }

    // ==========================================
    // EDGE CASES FOR notBetween
    // ==========================================

    /**
     * @test
     */
    public function notBetweenWithMinGreaterThanMax(): void
    {
        // min > max should return all records (nothing is between reversed range)
        $results = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 50, 20)
            ->get();

        // All records should be returned
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'David', 'Eve', 'Frank'], $this->names($results));
        // and nothing lies in a reversed range
        $this->assertSame([], $this->noneDB->query($this->testDbName)->between('age', 50, 20)->get());
    }

    /**
     * @test
     */
    public function notBetweenWithStringValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notBetween('name', 'B', 'D')
            ->get();

        // Bob, Charlie are between B-D alphabetically ("David" > "D" lexically)
        // Alice, David, Eve, Frank are outside
        $this->assertSame(['Alice', 'David', 'Eve', 'Frank'], $this->names($results));
        $this->assertSame(['Bob', 'Charlie'], $this->names($this->noneDB->query($this->testDbName)->between('name', 'B', 'D')->get()));
    }

    // ==========================================
    // COMPLEX REAL-WORLD SCENARIOS
    // ==========================================

    /**
     * @test
     */
    public function realWorldUserFilteringScenario(): void
    {
        // Find active users from Istanbul or Ankara,
        // who have gmail emails, age between 20-35,
        // sorted by score, limited to top 3
        $results = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->like('email', 'gmail')
            ->between('age', 20, 35)
            ->sort('score', 'desc')
            ->limit(3)
            ->select(['name', 'score', 'city', 'email'])
            ->get();

        // active + Istanbul/Ankara + gmail + 20..35: Alice (85), Eve (80); Charlie is inactive
        $this->assertSame([
            ['name' => 'Alice', 'score' => 85, 'city' => 'Istanbul', 'email' => 'alice@gmail.com', 'key' => 0],
            ['name' => 'Eve', 'score' => 80, 'city' => 'Istanbul', 'email' => 'eve@gmail.com', 'key' => 4],
        ], $results);
    }

    /**
     * @test
     */
    public function realWorldReportingScenario(): void
    {
        // Generate department report with aggregate conditions
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('department')
            ->having('count', '>=', 2)
            ->having('avg:score', '>', 75)
            ->sort('_count', 'desc')
            ->get();

        // IT: count=3, avg=80. HR: count=2, avg=80. Both pass.
        // Sales: count=1, fails count condition
        $this->assertSame([['IT', 3], ['HR', 2]], array_map(function ($g) { return [$g['_group'], $g['_count']]; }, $results));
        // a stricter average drops both
        $this->assertSame([], $this->noneDB->query($this->testDbName)->groupBy('department')->having('count', '>=', 2)->having('avg:score', '>', 80)->get());
    }

    /**
     * @test
     */
    public function realWorldSearchWithPagination(): void
    {
        $pageSize = 2;

        // Search for gmail users with pagination
        $totalCount = $this->noneDB->query($this->testDbName)
            ->search('gmail')
            ->count();

        $page1 = $this->noneDB->query($this->testDbName)
            ->search('gmail')
            ->sort('name')
            ->limit($pageSize)
            ->skip(0)
            ->get();

        $page2 = $this->noneDB->query($this->testDbName)
            ->search('gmail')
            ->sort('name')
            ->limit($pageSize)
            ->skip($pageSize)
            ->get();

        $this->assertSame(3, $totalCount); // Alice, Charlie, Eve
        $this->assertSame(['Alice', 'Charlie'], $this->names($page1));
        $this->assertSame(['Eve'], $this->names($page2));
    }

    // ==========================================
    // JOIN EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function joinWithEmptyForeignDb(): void
    {
        $this->noneDB->createDB('empty-departments');

        $results = $this->noneDB->query($this->testDbName)
            ->join('empty-departments', 'department', 'id')
            ->get();

        // All joins should be null
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'David', 'Eve', 'Frank'], $this->names($results));
        foreach ($results as $record) {
            $this->assertArrayHasKey('empty-departments', $record);
            $this->assertNull($record['empty-departments']);
        }
    }

    /**
     * @test
     */
    public function joinWithDuplicateForeignKeys(): void
    {
        // Foreign db has duplicate keys
        $this->noneDB->insert('departments', [
            ['id' => 'IT', 'name' => 'IT Department v1'],
            ['id' => 'IT', 'name' => 'IT Department v2'], // Duplicate
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->join('departments', 'department', 'id')
            ->get();

        // Last matching record should be used (v2)
        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));
        foreach ($results as $record) {
            $this->assertSame('IT Department v2', $record['departments']['name']);
            $this->assertSame(1, $record['departments']['key']);
        }
    }

    /**
     * @test
     */
    public function joinThenFilter(): void
    {
        $this->noneDB->insert('departments', [
            ['id' => 'IT', 'name' => 'Information Technology', 'budget' => 100000],
            ['id' => 'HR', 'name' => 'Human Resources', 'budget' => 50000],
        ]);

        // Note: Search is applied BEFORE join in execution order
        // So searching for "Information" won't find it in joined data
        // This tests that join adds data to filtered results
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->join('departments', 'department', 'id')
            ->get();

        // Should have IT employees with joined department info
        $this->assertSame(['Alice', 'Charlie', 'Eve'], $this->names($results));
        foreach ($results as $record) {
            $this->assertSame(
                ['budget' => 100000, 'id' => 'IT', 'key' => 0, 'name' => 'Information Technology'],
                $this->normalized($record['departments'])
            );
        }
        // the joined data is not searched: search() runs on the stored fields only
        $this->assertSame([], $this->noneDB->query($this->testDbName)->where(['department' => 'IT'])->join('departments', 'department', 'id')->search('Information')->get());
    }

    /**
     * @test
     */
    public function joinWithNullLocalKeyValue(): void
    {
        // Insert record with null department
        $this->noneDB->insert($this->testDbName, ['name' => 'NullDept', 'department' => null]);
        $this->noneDB->insert('departments', [['id' => 'IT', 'name' => 'IT Dept']]);

        $results = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'id')
            ->get();

        $this->assertSame(['Alice', 'Bob', 'Charlie', 'David', 'Eve', 'Frank', 'NullDept'], $this->names($results));
        $nullRecord = $results[6];
        $this->assertArrayHasKey('departments', $nullRecord);
        $this->assertNull($nullRecord['departments']); // null key should result in null join
        $this->assertSame(['IT Dept', null, 'IT Dept', null, 'IT Dept', null, null],
            array_map(function ($r) { return $r['departments']['name'] ?? null; }, $results));
    }

    /**
     * @test
     */
    public function joinWithArrayLocalKeyValue(): void
    {
        // Insert record with array as department value
        $this->noneDB->insert($this->testDbName, [
            'name' => 'ArrayDept',
            'department' => ['primary' => 'IT', 'secondary' => 'HR']
        ]);
        $this->noneDB->insert('departments', [['id' => 'IT', 'name' => 'IT Dept']]);

        $results = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'id')
            ->get();

        // Arrays match nothing (QUERY.md join matching)
        $this->assertCount(7, $results);
        $arrayRecord = $results[6];
        $this->assertSame('ArrayDept', $arrayRecord['name']);
        $this->assertArrayHasKey('departments', $arrayRecord);
        $this->assertNull($arrayRecord['departments']);
        $this->assertSame('IT Dept', $results[0]['departments']['name']);
    }

    /**
     * @test
     */
    public function joinWithNumericKeyValue(): void
    {
        // Insert record with numeric department id
        $this->noneDB->insert($this->testDbName, ['name' => 'NumericDept', 'department' => 123]);
        $this->noneDB->insert('departments', [
            ['id' => 123, 'name' => 'Dept 123'],
            ['id' => '123', 'name' => 'Dept String 123'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'id')
            ->get();

        $numericRecord = $results[6];
        $this->assertSame('NumericDept', $numericRecord['name']);
        // QUERY.md: integers and canonical integer strings match each other, and the last duplicate wins
        $this->assertSame('Dept String 123', $numericRecord['departments']['name']);
        $this->assertSame(1, $numericRecord['departments']['key']);
    }

    /**
     * @test
     */
    public function joinWithBooleanKeyValue(): void
    {
        // Insert record with boolean as key value
        $this->noneDB->insert($this->testDbName, ['name' => 'BoolDept', 'active' => true]);
        $this->noneDB->insert('statuses', [
            ['id' => true, 'label' => 'Active'],
            ['id' => false, 'label' => 'Inactive'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->join('statuses', 'active', 'id')
            ->get();

        // QUERY.md: true matches only true and false only false
        $this->assertSame(
            ['Active', 'Active', 'Inactive', 'Active', 'Active', 'Inactive', 'Active'],
            array_map(function ($r) { return $r['statuses']['label'] ?? null; }, $results)
        );
        $this->assertSame('BoolDept', $results[6]['name']);
    }

    /**
     * @test
     */
    public function joinWithNullForeignKeyValue(): void
    {
        // Foreign db has null as id
        $this->noneDB->insert('departments', [
            ['id' => null, 'name' => 'Null Dept'],
            ['id' => 'IT', 'name' => 'IT Dept'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'id')
            ->get();

        // IT records match IT Dept; HR/Sales match nothing
        $this->assertSame(['IT Dept', null, 'IT Dept', null, 'IT Dept', null],
            array_map(function ($r) { return $r['departments']['name'] ?? null; }, $results));

        // a local null doesn't join the foreign null (null matches nothing)
        $this->noneDB->insert($this->testDbName, ['name' => 'NullDept', 'department' => null]);
        $withNull = $this->noneDB->query($this->testDbName)->where(['name' => 'NullDept'])->join('departments', 'department', 'id')->get();
        $this->assertCount(1, $withNull);
        $this->assertNull($withNull[0]['departments']);
    }

    // ==========================================
    // CHAINING ORDER INDEPENDENCE
    // ==========================================

    /**
     * @test
     */
    public function filterOrderDoesNotMatter(): void
    {
        $results1 = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->whereIn('department', ['IT'])
            ->between('age', 20, 30)
            ->get();

        $results2 = $this->noneDB->query($this->testDbName)
            ->between('age', 20, 30)
            ->whereIn('department', ['IT'])
            ->where(['city' => 'Istanbul'])
            ->get();

        // Istanbul + IT + age 20..30: Alice (25), Eve (22)
        $this->assertSame(['Alice', 'Eve'], $this->names($results1));
        $this->assertSame($results1, $results2);
    }

    /**
     * @test
     */
    public function limitAppliesAfterSortWhateverTheCallOrder(): void
    {
        // Sort then limit - should get sorted top 2
        $sorted = $this->noneDB->query($this->testDbName)
            ->sort('score', 'desc')
            ->limit(2)
            ->get();

        $this->assertSame(['David', 'Bob'], $this->names($sorted)); // 95, 90

        // limit() called before sort(): the same records (the order of the calls doesn't matter)
        $limitFirst = $this->noneDB->query($this->testDbName)
            ->limit(2)
            ->sort('score', 'desc')
            ->get();
        $this->assertSame($sorted, $limitFirst);
    }
}
