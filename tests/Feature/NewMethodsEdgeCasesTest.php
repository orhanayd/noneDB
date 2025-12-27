<?php

namespace noneDB\Tests\Feature;

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
        $this->assertCount(3, $results);
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
        $this->assertCount(3, $results);
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
        $this->assertCount(3, $results);
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
        $this->assertCount(3, $results);
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
        $this->assertCount(3, $results);
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

        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', [null, 'Istanbul'])
            ->get();

        // Should match Istanbul (3) + null (1)
        $this->assertCount(4, $results);
    }

    /**
     * @test
     */
    public function whereNotInWithNullInArray(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NullCity', 'city' => null]);

        $results = $this->noneDB->query($this->testDbName)
            ->whereNotIn('city', [null])
            ->get();

        // Should exclude null, return 6 original records
        $this->assertCount(6, $results);
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
        $this->assertCount(4, $results);
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
        $this->assertCount(1, $results);
        $this->assertEquals('Mixed', $results[0]['name']);
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
        $this->assertCount(6, $results);
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

        $this->assertCount(1, $results);
        $this->assertEquals('Special', $results[0]['name']);
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

        $this->assertCount(1, $results);
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
        $this->assertEmpty($results);
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

        $this->assertIsArray($results);
        $this->assertEmpty($results);
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
        $this->assertCount(2, $results);
        $this->assertEquals('Istanbul', $results[0]['_group']); // 3 records
        $this->assertEquals('Ankara', $results[1]['_group']); // 2 records
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
        $this->assertCount(2, $results);
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
        $this->assertEquals('IT', $results[0]['_group']);

        // Each item should have all original fields
        foreach ($results[0]['_items'] as $item) {
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('email', $item);
            $this->assertArrayHasKey('age', $item);
        }
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
        $this->assertCount(6, $results);
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

        $this->assertEmpty($results);
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

        // select takes priority, age should be included
        foreach ($results as $record) {
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('age', $record);
            $this->assertArrayHasKey('city', $record);
            $this->assertArrayNotHasKey('email', $record);
        }
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

        // select on grouped results
        foreach ($results as $record) {
            $this->assertArrayHasKey('_group', $record);
            $this->assertArrayHasKey('_count', $record);
            $this->assertArrayNotHasKey('_items', $record);
        }
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

        $this->assertNotNull($result);
        $this->assertEquals('Alice', $result['name']);
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

        $this->assertNotNull($result);
        $this->assertEquals('Frank', $result['name']);
    }

    /**
     * @test
     */
    public function countWithWhereNot(): void
    {
        $count = $this->noneDB->query($this->testDbName)
            ->whereNot(['active' => false])
            ->count();

        $this->assertEquals(4, $count); // Alice, Bob, David, Eve
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
    }

    /**
     * @test
     */
    public function sumWithNotBetween(): void
    {
        $sum = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 25, 35)
            ->sum('score');

        // Eve(22)=80, Frank(40)=70
        $this->assertEquals(150, $sum);
    }

    /**
     * @test
     */
    public function avgWithSearch(): void
    {
        $avg = $this->noneDB->query($this->testDbName)
            ->search('gmail')
            ->avg('age');

        // Alice(25), Charlie(35), Eve(22) = avg(82/3) ≈ 27.33
        $this->assertEqualsWithDelta(27.33, $avg, 0.01);
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
        $this->assertEquals(22, $min);
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
        $this->assertEquals(95, $max);
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

        // IT: Istanbul(3). HR: Ankara(2). Distinct cities = Istanbul, Ankara
        $this->assertCount(2, $cities);
        $this->assertContains('Istanbul', $cities);
        $this->assertContains('Ankara', $cities);
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

        $this->assertEquals(5, $result['n']);

        // Verify update
        $updated = $this->noneDB->find($this->testDbName, ['region' => 'Marmara']);
        $this->assertCount(5, $updated);
    }

    /**
     * @test
     */
    public function updateWithWhereIn(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->whereIn('department', ['IT', 'HR'])
            ->update(['type' => 'office']);

        $this->assertEquals(5, $result['n']); // 3 IT + 2 HR
    }

    /**
     * @test
     */
    public function deleteWithWhereNot(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->whereNot(['active' => true])
            ->delete();

        $this->assertEquals(2, $result['n']); // Charlie, Frank

        // Verify remaining
        $remaining = $this->noneDB->count($this->testDbName);
        $this->assertEquals(4, $remaining);
    }

    /**
     * @test
     */
    public function deleteWithNotBetween(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 25, 35)
            ->delete();

        $this->assertEquals(2, $result['n']); // Eve(22), Frank(40)

        // Verify remaining ages are 25-35
        $remaining = $this->noneDB->find($this->testDbName, 0);
        foreach ($remaining as $record) {
            $this->assertGreaterThanOrEqual(25, $record['age']);
            $this->assertLessThanOrEqual(35, $record['age']);
        }
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
        $this->assertCount(6, $results);
    }

    /**
     * @test
     */
    public function notBetweenWithStringValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notBetween('name', 'B', 'D')
            ->get();

        // Bob, Charlie are between B-D alphabetically
        // Alice, David, Eve, Frank are outside
        $this->assertCount(4, $results);
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

        $this->assertLessThanOrEqual(3, count($results));

        // All results should match criteria
        foreach ($results as $record) {
            $this->assertContains($record['city'], ['Istanbul', 'Ankara']);
            $this->assertStringContainsString('gmail', $record['email']);
        }
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
        $this->assertCount(2, $results);
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

        $this->assertEquals(3, $totalCount); // Alice, Charlie, Eve
        $this->assertCount(2, $page1);
        $this->assertCount(1, $page2);
    }

    // ==========================================
    // JOIN EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function joinWithEmptyForeignDb(): void
    {
        $this->noneDB->createDB('empty_departments');

        $results = $this->noneDB->query($this->testDbName)
            ->join('empty_departments', 'department', 'id')
            ->get();

        // All joins should be null
        foreach ($results as $record) {
            $this->assertNull($record['empty_departments']);
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
        foreach ($results as $record) {
            $this->assertEquals('IT Department v2', $record['departments']['name']);
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
        $this->assertCount(3, $results);
        foreach ($results as $record) {
            $this->assertArrayHasKey('departments', $record);
            $this->assertNotNull($record['departments']);
            $this->assertEquals('Information Technology', $record['departments']['name']);
        }
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

        $nullRecord = array_values(array_filter($results, fn($r) => $r['name'] === 'NullDept'))[0];
        $this->assertArrayHasKey('departments', $nullRecord);
        $this->assertNull($nullRecord['departments']); // null key should result in null join
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

        // Should not crash, array key won't match string foreign key
        $arrayRecord = array_values(array_filter($results, fn($r) => $r['name'] === 'ArrayDept'))[0];
        $this->assertArrayHasKey('departments', $arrayRecord);
        $this->assertNull($arrayRecord['departments']);
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

        $numericRecord = array_values(array_filter($results, fn($r) => $r['name'] === 'NumericDept'))[0];
        $this->assertArrayHasKey('departments', $numericRecord);
        // Numeric 123 should match either 123 or '123' depending on indexing
        $this->assertNotNull($numericRecord['departments']);
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

        // Should not crash
        $this->assertGreaterThan(0, count($results));
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

        // Records with IT department should match IT Dept
        $itRecord = array_values(array_filter($results, fn($r) => $r['department'] === 'IT'))[0];
        $this->assertNotNull($itRecord['departments']);
        $this->assertEquals('IT Dept', $itRecord['departments']['name']);
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

        $this->assertEquals(count($results1), count($results2));
    }

    /**
     * @test
     */
    public function sortAndLimitOrderMatters(): void
    {
        // Sort then limit - should get sorted top 2
        $sorted = $this->noneDB->query($this->testDbName)
            ->sort('score', 'desc')
            ->limit(2)
            ->get();

        // Verify sorted correctly
        $this->assertEquals(95, $sorted[0]['score']); // David
        $this->assertEquals(90, $sorted[1]['score']); // Bob
    }

    protected function tearDown(): void
    {
        // Clean up additional test databases
        $dbDir = $this->getPrivateProperty('dbDir');
        $hashMethod = $this->getPrivateMethod('hashDBName');
        foreach (['departments', 'empty_departments', 'emptydb'] as $db) {
            $hash = $hashMethod->invoke($this->noneDB, $db);
            $files = glob($dbDir . $hash . '-' . $db . '*');
            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        parent::tearDown();
    }
}
