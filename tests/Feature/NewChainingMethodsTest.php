<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Comprehensive tests for newly added chaining methods
 * Tests: orWhere, whereIn, whereNotIn, whereNot, notLike, notBetween,
 *        select, except, groupBy, having, search, join, skip, orderBy
 */
class NewChainingMethodsTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Insert standard test data
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25, 'city' => 'Istanbul', 'email' => 'alice@gmail.com', 'score' => 85, 'department' => 'IT'],
            ['name' => 'Bob', 'age' => 30, 'city' => 'Ankara', 'email' => 'bob@yahoo.com', 'score' => 90, 'department' => 'HR'],
            ['name' => 'Charlie', 'age' => 35, 'city' => 'Istanbul', 'email' => 'charlie@gmail.com', 'score' => 75, 'department' => 'IT'],
            ['name' => 'David', 'age' => 28, 'city' => 'Izmir', 'email' => 'david@hotmail.com', 'score' => 95, 'department' => 'Sales'],
            ['name' => 'Eve', 'age' => 22, 'city' => 'Istanbul', 'email' => 'eve@gmail.com', 'score' => 80, 'department' => 'IT'],
            ['name' => 'Frank', 'age' => 40, 'city' => 'Ankara', 'email' => 'frank@yahoo.com', 'score' => 70, 'department' => 'HR'],
        ]);
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

        $this->assertCount(5, $results);
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

        $this->assertCount(3, $results);
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

        $this->assertCount(3, $results);
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

        $this->assertEmpty($results);
    }

    /**
     * @test
     */
    public function orWhereWithMultipleFieldsInCondition(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul', 'department' => 'IT'])
            ->orWhere(['city' => 'Ankara', 'department' => 'HR'])
            ->get();

        $this->assertCount(5, $results); // 3 IT Istanbul + 2 HR Ankara
    }

    /**
     * @test
     */
    public function orWhereWithNullValue(): void
    {
        // Insert record with null
        $this->noneDB->insert($this->testDbName, ['name' => 'NullCity', 'city' => null]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->orWhere(['city' => null])
            ->get();

        $this->assertCount(4, $results); // 3 Istanbul + 1 null
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

        // Without WHERE, all records pass WHERE check (true), OR adds matching records
        // So this returns all records that match Istanbul OR Ankara OR pass WHERE (all)
        $this->assertCount(6, $results);
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

        $this->assertCount(5, $results);
    }

    /**
     * @test
     */
    public function whereInWithSingleValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Izmir'])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('David', $results[0]['name']);
    }

    /**
     * @test
     */
    public function whereInWithEmptyArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', [])
            ->get();

        $this->assertEmpty($results);
    }

    /**
     * @test
     */
    public function whereInWithNonExistentValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Paris', 'London'])
            ->get();

        $this->assertEmpty($results);
    }

    /**
     * @test
     */
    public function whereInWithMixedExistentNonExistent(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Istanbul', 'Paris', 'London'])
            ->get();

        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function whereInWithNumericValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('age', [25, 30, 35])
            ->get();

        $this->assertCount(3, $results);
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

        $this->assertCount(3, $results); // All IT are in Istanbul
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

        $this->assertCount(3, $results); // Only records WITH city field
    }

    /**
     * @test
     */
    public function whereInStrictTypeComparison(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'TypeTest', 'code' => '100']);

        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('code', [100]) // Integer 100
            ->get();

        // Should NOT match string '100' due to strict comparison
        $this->assertCount(0, $results);
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

        $this->assertCount(3, $results); // IT in Istanbul
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

        $this->assertCount(3, $results); // Ankara(2) + Izmir(1)
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
        $this->assertCount(6, $results);
    }

    /**
     * @test
     */
    public function whereNotInExcludesAll(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNotIn('city', ['Istanbul', 'Ankara', 'Izmir'])
            ->get();

        $this->assertEmpty($results);
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
        $this->assertCount(4, $results); // 3 non-Istanbul + 1 without city
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

        $this->assertCount(2, $results); // HR in Ankara
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

        $this->assertCount(3, $results);
        foreach ($results as $record) {
            $this->assertNotEquals('Istanbul', $record['city']);
        }
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
        $this->assertCount(1, $results); // Only David (Izmir, Sales)
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
        $this->assertCount(4, $results);
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
        $this->assertEmpty($results);
    }

    /**
     * @test
     */
    public function whereNotWithNullValue(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NullCity', 'city' => null]);

        $results = $this->noneDB->query($this->testDbName)
            ->whereNot(['city' => null])
            ->get();

        $this->assertCount(6, $results); // All except null city
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

        // whereNot filters are merged for DIFFERENT fields
        // Excludes Istanbul (3) AND HR (2, but Bob is in Ankara)
        // Result: David (Izmir, Sales) only
        $this->assertCount(1, $results);
        $this->assertEquals('David', $results[0]['name']);
    }

    /**
     * @test
     */
    public function whereNotSameFieldOverrides(): void
    {
        // Note: array_merge with same keys keeps last value
        // Use whereNotIn for multiple values of same field
        $results = $this->noneDB->query($this->testDbName)
            ->whereNot(['city' => 'Istanbul'])
            ->whereNot(['city' => 'Ankara'])
            ->get();

        // Only last whereNot for 'city' is applied (Ankara)
        $this->assertCount(4, $results); // All except Ankara (2)
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

        $this->assertCount(3, $results);
        foreach ($results as $record) {
            $this->assertStringNotContainsString('gmail', $record['email']);
        }
    }

    /**
     * @test
     */
    public function notLikeStartsWith(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notLike('name', '^A')
            ->get();

        $this->assertCount(5, $results);
        foreach ($results as $record) {
            $this->assertStringStartsNotWith('A', $record['name']);
        }
    }

    /**
     * @test
     */
    public function notLikeEndsWith(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notLike('email', 'gmail.com$')
            ->get();

        $this->assertCount(3, $results);
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
        $this->assertCount(4, $results);
    }

    /**
     * @test
     */
    public function notLikeWithArrayValue(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'ArrayEmail', 'email' => ['a@b.com']]);

        $results = $this->noneDB->query($this->testDbName)
            ->notLike('email', 'gmail')
            ->get();

        // Array values should be included (treated as non-matching)
        $this->assertCount(4, $results);
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

        $this->assertCount(2, $results); // Alice and Charlie (gmail but not E*)
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
        $this->assertEmpty($results);
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

        $this->assertCount(6, $results); // All original records
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
        $this->assertCount(2, $results);
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

        $this->assertCount(5, $results); // All except Alice (25)
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
        $this->assertCount(3, $results); // Charlie(35), Frank(40), NoAge
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
        $this->assertCount(7, $results);

        // Verify Positive is included
        $positiveRecord = array_filter($results, fn($r) => isset($r['balance']) && $r['balance'] === 50);
        $this->assertCount(1, $positiveRecord);
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
        $this->assertCount(7, $results);

        // Verify Float2 is included
        $float2Record = array_filter($results, fn($r) => isset($r['price']) && $r['price'] === 20.5);
        $this->assertCount(1, $float2Record);
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

        // Alice(25), Charlie(35), Eve(22), Frank(40)
        $this->assertCount(4, $results);
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
        $this->assertCount(2, $results);
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

        $this->assertCount(6, $results);
        foreach ($results as $record) {
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('age', $record);
            $this->assertArrayHasKey('key', $record); // key is always included
            $this->assertArrayNotHasKey('city', $record);
            $this->assertArrayNotHasKey('email', $record);
        }
    }

    /**
     * @test
     */
    public function selectSingleField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select(['name'])
            ->get();

        foreach ($results as $record) {
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('key', $record);
            $this->assertCount(2, $record); // Only name + key
        }
    }

    /**
     * @test
     */
    public function selectNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select(['nonexistent'])
            ->get();

        foreach ($results as $record) {
            $this->assertArrayNotHasKey('nonexistent', $record);
            $this->assertArrayHasKey('key', $record);
        }
    }

    /**
     * @test
     */
    public function selectIncludesKeyExplicitly(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select(['name', 'key'])
            ->get();

        foreach ($results as $record) {
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('key', $record);
        }
    }

    /**
     * @test
     */
    public function selectWithEmptyArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select([])
            ->get();

        $this->assertCount(6, $results);

        // Empty select = no filtering, all fields returned (like Laravel Eloquent)
        // This is the expected behavior: select([]) means "select nothing specific" = return all
        foreach ($results as $record) {
            $this->assertArrayHasKey('key', $record);
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('age', $record);
            $this->assertArrayHasKey('city', $record);
        }
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

        $this->assertCount(3, $results);
        foreach ($results as $record) {
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('department', $record);
            $this->assertArrayNotHasKey('city', $record);
        }
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

        foreach ($results as $record) {
            $this->assertArrayNotHasKey('email', $record);
            $this->assertArrayNotHasKey('score', $record);
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('city', $record);
        }
    }

    /**
     * @test
     */
    public function exceptSingleField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->except(['email'])
            ->get();

        foreach ($results as $record) {
            $this->assertArrayNotHasKey('email', $record);
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('age', $record);
        }
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
        foreach ($results as $record) {
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('email', $record);
        }
    }

    /**
     * @test
     */
    public function exceptKey(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->except(['key'])
            ->get();

        foreach ($results as $record) {
            $this->assertArrayNotHasKey('key', $record);
        }
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
        foreach ($results as $record) {
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('email', $record);
            $this->assertArrayHasKey('key', $record);
        }
    }

    /**
     * @test
     */
    public function exceptAllFields(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->except(['name', 'age', 'city', 'email', 'score', 'department', 'key'])
            ->get();

        foreach ($results as $record) {
            $this->assertEmpty($record);
        }
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

        $this->assertCount(3, $results); // Istanbul, Ankara, Izmir

        foreach ($results as $group) {
            $this->assertArrayHasKey('_group', $group);
            $this->assertArrayHasKey('_items', $group);
            $this->assertArrayHasKey('_count', $group);
        }
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

        $this->assertEquals(3, $grouped['Istanbul']);
        $this->assertEquals(2, $grouped['Ankara']);
        $this->assertEquals(1, $grouped['Izmir']);
    }

    /**
     * @test
     */
    public function groupByContainsItems(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('department')
            ->get();

        foreach ($results as $group) {
            $this->assertIsArray($group['_items']);
            $this->assertCount($group['_count'], $group['_items']);
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
        $nullGroup = array_filter($results, fn($g) => $g['_group'] === null);
        $this->assertCount(1, $nullGroup);
        $this->assertEquals(2, array_values($nullGroup)[0]['_count']);
    }

    /**
     * @test
     */
    public function groupByWithMissingField(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'NoCity']);

        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('city')
            ->get();

        // Missing field treated as null
        $this->assertCount(4, $results); // 3 cities + 1 null group
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
        $this->assertCount(1, $results);
        $this->assertEquals('Istanbul', $results[0]['_group']);
        $this->assertEquals(3, $results[0]['_count']);
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

        // Istanbul(3) should be first
        $this->assertEquals('Istanbul', $results[0]['_group']);
    }

    // ==========================================
    // having() TESTS
    // ==========================================

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
        $this->assertCount(2, $results);
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
        $this->assertEquals('Izmir', $results[0]['_group']);
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
        $this->assertCount(2, $results);
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
        $this->assertCount(1, $results);
        $this->assertEquals('Ankara', $results[0]['_group']);
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
        $this->assertCount(1, $results);
        $this->assertEquals('Istanbul', $results[0]['_group']);
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

        // Ankara max: 90, Izmir max: 95
        $this->assertCount(2, $results);
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
        $this->assertCount(2, $results);
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

        $this->assertCount(2, $results);
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

        // Istanbul: count=3, avg=80. Ankara: count=2, avg=80
        $this->assertCount(2, $results);
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

        // Invalid aggregate returns true, so all groups pass
        $this->assertCount(3, $results);
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

        $this->assertCount(1, $results);
        $this->assertEquals('Alice', $results[0]['name']);
    }

    /**
     * @test
     */
    public function searchCaseInsensitive(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('ALICE')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Alice', $results[0]['name']);
    }

    /**
     * @test
     */
    public function searchAcrossMultipleFields(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('istanbul')
            ->get();

        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function searchInSpecificFields(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('gmail', ['email'])
            ->get();

        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function searchWithNoMatches(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('nonexistent')
            ->get();

        $this->assertEmpty($results);
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
        $this->assertCount(2, $results);
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
        $this->assertCount(1, $results);
        $this->assertEquals('Alice', $results[0]['name']);
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

        // Should find the record via email
        $this->assertCount(1, $results);
    }

    /**
     * @test
     */
    public function searchWithNumericValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('25')
            ->get();

        // Should match Alice's age (25)
        $this->assertGreaterThanOrEqual(1, count($results));
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
        $this->assertCount(1, $results);
        $this->assertEquals('Alice', $results[0]['name']);
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

        $this->assertCount(3, $results);
    }

    // ==========================================
    // join() TESTS
    // ==========================================

    /**
     * @test
     */
    public function joinBasicUsage(): void
    {
        // Create foreign database
        $this->noneDB->insert('departments', [
            ['id' => 'IT', 'name' => 'Information Technology', 'budget' => 100000],
            ['id' => 'HR', 'name' => 'Human Resources', 'budget' => 50000],
            ['id' => 'Sales', 'name' => 'Sales Department', 'budget' => 75000],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'id')
            ->get();

        $this->assertCount(6, $results);
        foreach ($results as $record) {
            $this->assertArrayHasKey('departments', $record);
        }
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

        foreach ($results as $record) {
            $this->assertArrayHasKey('dept', $record);
            $this->assertArrayNotHasKey('departments', $record);
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

        $itRecords = array_filter($results, fn($r) => $r['department'] === 'IT');
        $hrRecords = array_filter($results, fn($r) => $r['department'] === 'HR');

        foreach ($itRecords as $record) {
            $this->assertNotNull($record['departments']);
        }
        foreach ($hrRecords as $record) {
            $this->assertNull($record['departments']);
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

        $noDeptRecord = array_filter($results, fn($r) => $r['name'] === 'NoDept');
        $noDeptRecord = array_values($noDeptRecord)[0];

        $this->assertArrayHasKey('departments', $noDeptRecord);
        $this->assertNull($noDeptRecord['departments']);
    }

    /**
     * @test
     */
    public function joinWithNonExistentForeignDb(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->join('nonexistent_db', 'department', 'id')
            ->get();

        // Should return records without join data (graceful handling)
        $this->assertCount(6, $results);
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

        $aliceRecord = array_filter($results, fn($r) => $r['name'] === 'Alice');
        $aliceRecord = array_values($aliceRecord)[0];

        $this->assertArrayHasKey('dept', $aliceRecord);
        $this->assertArrayHasKey('cityInfo', $aliceRecord);
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

        $this->assertCount(3, $results);
        foreach ($results as $record) {
            $this->assertNotNull($record['departments']);
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

        $this->assertEquals($skipResults, $offsetResults);
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

        $this->assertEquals($orderByResults, $sortResults);
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

        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function orderByAscending(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->orderBy('age', 'asc')
            ->get();

        $this->assertEquals(22, $results[0]['age']);
        $this->assertEquals(40, $results[5]['age']);
    }

    /**
     * @test
     */
    public function orderByDescending(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->orderBy('score', 'desc')
            ->get();

        $this->assertEquals(95, $results[0]['score']);
        $this->assertEquals(70, $results[5]['score']);
    }

    /**
     * @test
     */
    public function chainableMethodsReturnSelf(): void
    {
        $query = $this->noneDB->query($this->testDbName);

        $this->assertSame($query, $query->orWhere(['city' => 'Istanbul']));
        $this->assertSame($query, $query->whereIn('city', ['Istanbul']));
        $this->assertSame($query, $query->whereNotIn('city', ['Ankara']));
        $this->assertSame($query, $query->whereNot(['active' => false]));
        $this->assertSame($query, $query->notLike('email', 'yahoo'));
        $this->assertSame($query, $query->notBetween('age', 50, 60));
        $this->assertSame($query, $query->select(['name']));
        $this->assertSame($query, $query->except(['email']));
        $this->assertSame($query, $query->groupBy('city'));
        $this->assertSame($query, $query->having('count', '>', 0));
        $this->assertSame($query, $query->search('test'));
        $this->assertSame($query, $query->join('other', 'id', 'id'));
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

        $this->assertLessThanOrEqual(2, count($results));
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

        $this->assertCount(2, $page1);
        $this->assertCount(1, $page2);
        $this->assertNotEquals($page1[0]['name'], $page2[0]['name']);
    }

    protected function tearDown(): void
    {
        // Clean up additional test databases
        $dbDir = $this->getPrivateProperty('dbDir');
        $hashMethod = $this->getPrivateMethod('hashDBName');
        foreach (['departments', 'cities'] as $db) {
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
