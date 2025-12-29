<?php
/**
 * Comparison Operator Tests
 * Tests MongoDB-style operators: $gt, $gte, $lt, $lte, $ne, $eq, $in, $nin, $exists, $like, $regex, $contains
 * @version 3.1.0
 */

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

class ComparisonOperatorTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Insert test data with various types
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

    // ========== GREATER THAN ($gt) ==========

    /**
     * Test $gt operator with integers
     */
    public function testGreaterThan(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$gt' => 30]])
            ->get();

        $this->assertCount(3, $results); // Charlie(35), Eve(45), Grace(33)

        foreach ($results as $record) {
            $this->assertGreaterThan(30, $record['age']);
        }
    }

    /**
     * Test $gt with floats
     */
    public function testGreaterThanFloat(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$gt' => 75000.50]])
            ->get();

        $this->assertCount(3, $results); // Charlie, Eve, Grace

        foreach ($results as $record) {
            $this->assertGreaterThan(75000.50, $record['salary']);
        }
    }

    /**
     * Test $gt returns empty when no matches
     */
    public function testGreaterThanNoMatch(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$gt' => 100]])
            ->get();

        $this->assertCount(0, $results);
    }

    // ========== GREATER THAN OR EQUAL ($gte) ==========

    /**
     * Test $gte operator
     */
    public function testGreaterThanOrEqual(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$gte' => 35]])
            ->get();

        $this->assertCount(2, $results); // Charlie(35), Eve(45)

        foreach ($results as $record) {
            $this->assertGreaterThanOrEqual(35, $record['age']);
        }
    }

    /**
     * Test $gte at exact boundary
     */
    public function testGreaterThanOrEqualBoundary(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$gte' => 50000]])
            ->get();

        $this->assertCount(6, $results); // All except Frank (40000)

        foreach ($results as $record) {
            $this->assertGreaterThanOrEqual(50000, $record['salary']);
        }
    }

    // ========== LESS THAN ($lt) ==========

    /**
     * Test $lt operator
     */
    public function testLessThan(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$lt' => 28]])
            ->get();

        $this->assertCount(2, $results); // Alice(25), Frank(22)

        foreach ($results as $record) {
            $this->assertLessThan(28, $record['age']);
        }
    }

    /**
     * Test $lt with salary
     */
    public function testLessThanSalary(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$lt' => 60000]])
            ->get();

        $this->assertCount(2, $results); // Alice(50000), Frank(40000)

        foreach ($results as $record) {
            $this->assertLessThan(60000, $record['salary']);
        }
    }

    // ========== LESS THAN OR EQUAL ($lte) ==========

    /**
     * Test $lte operator
     */
    public function testLessThanOrEqual(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$lte' => 25]])
            ->get();

        $this->assertCount(2, $results); // Alice(25), Frank(22)

        foreach ($results as $record) {
            $this->assertLessThanOrEqual(25, $record['age']);
        }
    }

    /**
     * Test $lte at exact boundary
     */
    public function testLessThanOrEqualBoundary(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$lte' => 75000]])
            ->get();

        $this->assertCount(4, $results); // Alice(50000), Bob(75000), Diana(60000), Frank(40000)

        foreach ($results as $record) {
            $this->assertLessThanOrEqual(75000, $record['salary']);
        }
    }

    // ========== NOT EQUAL ($ne) ==========

    /**
     * Test $ne operator with string
     */
    public function testNotEqual(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => ['$ne' => 'Engineering']])
            ->get();

        $this->assertCount(3, $results); // Charlie, Diana, Eve

        foreach ($results as $record) {
            $this->assertNotEquals('Engineering', $record['department']);
        }
    }

    /**
     * Test $ne with boolean
     */
    public function testNotEqualBoolean(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['active' => ['$ne' => false]])
            ->get();

        $this->assertCount(5, $results); // All active ones

        foreach ($results as $record) {
            $this->assertTrue($record['active']);
        }
    }

    /**
     * Test $ne with integer
     */
    public function testNotEqualInteger(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$ne' => 30]])
            ->get();

        $this->assertCount(6, $results); // All except Bob

        foreach ($results as $record) {
            $this->assertNotEquals(30, $record['age']);
        }
    }

    // ========== EQUAL ($eq) ==========

    /**
     * Test $eq operator (explicit equality)
     */
    public function testEqual(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => ['$eq' => 'Engineering']])
            ->get();

        $this->assertCount(4, $results); // Alice, Bob, Frank, Grace

        foreach ($results as $record) {
            $this->assertEquals('Engineering', $record['department']);
        }
    }

    // ========== IN ($in) ==========

    /**
     * Test $in operator with array
     */
    public function testIn(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => ['$in' => ['Engineering', 'Design']]])
            ->get();

        $this->assertCount(5, $results); // Alice, Bob, Diana, Frank, Grace

        foreach ($results as $record) {
            $this->assertContains($record['department'], ['Engineering', 'Design']);
        }
    }

    /**
     * Test $in with integers
     */
    public function testInIntegers(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$in' => [25, 30, 35]]])
            ->get();

        $this->assertCount(3, $results); // Alice, Bob, Charlie

        foreach ($results as $record) {
            $this->assertContains($record['age'], [25, 30, 35]);
        }
    }

    /**
     * Test $in with single value
     */
    public function testInSingleValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['name' => ['$in' => ['Alice']]])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Alice', $results[0]['name']);
    }

    /**
     * Test $in with empty array returns nothing
     */
    public function testInEmptyArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => ['$in' => []]])
            ->get();

        $this->assertCount(0, $results);
    }

    // ========== NOT IN ($nin) ==========

    /**
     * Test $nin operator
     */
    public function testNotIn(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => ['$nin' => ['Engineering', 'Executive']]])
            ->get();

        $this->assertCount(2, $results); // Charlie(Management), Diana(Design)

        foreach ($results as $record) {
            $this->assertNotContains($record['department'], ['Engineering', 'Executive']);
        }
    }

    /**
     * Test $nin with integers
     */
    public function testNotInIntegers(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$nin' => [25, 45]]])
            ->get();

        $this->assertCount(5, $results); // All except Alice and Eve

        foreach ($results as $record) {
            $this->assertNotContains($record['age'], [25, 45]);
        }
    }

    // ========== EXISTS ($exists) ==========

    /**
     * Test $exists true - field must exist
     */
    public function testExistsTrue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['tags' => ['$exists' => true]])
            ->get();

        $this->assertCount(6, $results); // All except Frank

        foreach ($results as $record) {
            $this->assertArrayHasKey('tags', $record);
        }
    }

    /**
     * Test $exists false - field must not exist
     */
    public function testExistsFalse(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['tags' => ['$exists' => false]])
            ->get();

        $this->assertCount(1, $results); // Only Frank

        foreach ($results as $record) {
            $this->assertArrayNotHasKey('tags', $record);
        }
    }

    /**
     * Test $exists for non-existent field
     */
    public function testExistsNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['nonexistent' => ['$exists' => false]])
            ->get();

        $this->assertCount(7, $results); // All records
    }

    // ========== LIKE ($like) ==========

    /**
     * Test $like operator with contains
     */
    public function testLikeContains(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['name' => ['$like' => 'li']])
            ->get();

        $this->assertCount(2, $results); // Alice, Charlie

        foreach ($results as $record) {
            $this->assertStringContainsStringIgnoringCase('li', $record['name']);
        }
    }

    /**
     * Test $like with starts with (^)
     */
    public function testLikeStartsWith(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['name' => ['$like' => '^A']])
            ->get();

        $this->assertCount(1, $results); // Alice
        $this->assertEquals('Alice', $results[0]['name']);
    }

    /**
     * Test $like with ends with ($)
     */
    public function testLikeEndsWith(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['name' => ['$like' => 'e$']])
            ->get();

        $this->assertCount(4, $results); // Alice, Charlie, Eve, Grace - all end with 'e'
        // Note: case insensitive, so Grace ends with 'e'

        foreach ($results as $record) {
            $this->assertMatchesRegularExpression('/e$/i', $record['name']);
        }
    }

    /**
     * Test $like case insensitive
     */
    public function testLikeCaseInsensitive(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => ['$like' => 'ENGINEERING']])
            ->get();

        $this->assertCount(4, $results);
    }

    // ========== REGEX ($regex) ==========

    /**
     * Test $regex operator
     */
    public function testRegex(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['name' => ['$regex' => '^[A-D]']])
            ->get();

        $this->assertCount(4, $results); // Alice, Bob, Charlie, Diana

        foreach ($results as $record) {
            $this->assertMatchesRegularExpression('/^[A-D]/i', $record['name']);
        }
    }

    /**
     * Test $regex with word pattern
     */
    public function testRegexWordPattern(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => ['$regex' => '.*ing$']])
            ->get();

        $this->assertCount(4, $results); // Engineering
    }

    // ========== CONTAINS ($contains) ==========

    /**
     * Test $contains for array field
     */
    public function testContainsArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['tags' => ['$contains' => 'developer']])
            ->get();

        $this->assertCount(3, $results); // Alice, Bob, Grace

        foreach ($results as $record) {
            $this->assertContains('developer', $record['tags']);
        }
    }

    /**
     * Test $contains for string field
     */
    public function testContainsString(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => ['$contains' => 'sign']])
            ->get();

        $this->assertCount(1, $results); // Design
        $this->assertEquals('Design', $results[0]['department']);
    }

    // ========== COMBINED OPERATORS ==========

    /**
     * Test multiple operators on same field (range query)
     */
    public function testRangeQuery(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$gte' => 25, '$lte' => 35]])
            ->get();

        $this->assertCount(5, $results); // Alice(25), Bob(30), Charlie(35), Diana(28), Grace(33)

        foreach ($results as $record) {
            $this->assertGreaterThanOrEqual(25, $record['age']);
            $this->assertLessThanOrEqual(35, $record['age']);
        }
    }

    /**
     * Test operators across different fields
     */
    public function testMultiFieldOperators(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where([
                'age' => ['$gte' => 25],
                'salary' => ['$lt' => 80000],
                'active' => true
            ])
            ->get();

        $this->assertCount(3, $results); // Alice, Bob, Diana

        foreach ($results as $record) {
            $this->assertGreaterThanOrEqual(25, $record['age']);
            $this->assertLessThan(80000, $record['salary']);
            $this->assertTrue($record['active']);
        }
    }

    /**
     * Test operators with simple equality mixed
     */
    public function testOperatorsWithEquality(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where([
                'department' => 'Engineering',
                'age' => ['$gt' => 24]
            ])
            ->get();

        $this->assertCount(3, $results); // Alice(25), Bob(30), Grace(33)

        foreach ($results as $record) {
            $this->assertEquals('Engineering', $record['department']);
            $this->assertGreaterThan(24, $record['age']);
        }
    }

    // ========== EDGE CASES ==========

    /**
     * Test operators with null values
     */
    public function testOperatorsWithNull(): void
    {
        // Insert record with null value
        $this->noneDB->insert($this->testDbName, [
            'name' => 'TestNull',
            'age' => null,
            'salary' => 0,
            'active' => true,
            'department' => 'Test'
        ]);

        // $gt with null should not match
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$gt' => 0]])
            ->get();

        $names = array_column($results, 'name');
        $this->assertNotContains('TestNull', $names);

        // $exists should work with null
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$exists' => true]])
            ->get();

        $names = array_column($results, 'name');
        $this->assertContains('TestNull', $names);
    }

    /**
     * Test operators with zero
     */
    public function testOperatorsWithZero(): void
    {
        // Insert record with zero values
        $this->noneDB->insert($this->testDbName, [
            'name' => 'TestZero',
            'age' => 0,
            'salary' => 0,
            'active' => false,
            'department' => 'Test'
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$gte' => 0]])
            ->get();

        $names = array_column($results, 'name');
        $this->assertContains('TestZero', $names);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$lte' => 0]])
            ->get();

        $names = array_column($results, 'name');
        $this->assertContains('TestZero', $names);
    }

    /**
     * Test operators with negative numbers
     */
    public function testOperatorsWithNegative(): void
    {
        // Insert record with negative value
        $this->noneDB->insert($this->testDbName, [
            'name' => 'TestNegative',
            'age' => 30,
            'salary' => -100,
            'active' => true,
            'department' => 'Test'
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$lt' => 0]])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('TestNegative', $results[0]['name']);
    }

    /**
     * Test operators with empty string
     */
    public function testOperatorsWithEmptyString(): void
    {
        // Insert record with empty string
        $this->noneDB->insert($this->testDbName, [
            'name' => '',
            'age' => 20,
            'salary' => 30000,
            'active' => true,
            'department' => ''
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['name' => ['$eq' => '']])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('', $results[0]['name']);

        // $ne with empty string
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => ['$ne' => '']])
            ->get();

        $this->assertCount(7, $results); // Original 7
    }

    // ========== SORTING WITH OPERATORS ==========

    /**
     * Test operators combined with sorting
     */
    public function testOperatorsWithSort(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$gte' => 50000, '$lte' => 100000]])
            ->sort('salary', 'desc')
            ->get();

        $this->assertCount(5, $results);

        // Verify descending order
        $prevSalary = PHP_INT_MAX;
        foreach ($results as $record) {
            $this->assertLessThanOrEqual($prevSalary, $record['salary']);
            $prevSalary = $record['salary'];
        }
    }

    /**
     * Test operators with limit and offset
     */
    public function testOperatorsWithLimitOffset(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->sort('age', 'asc')
            ->limit(2)
            ->offset(1)
            ->get();

        $this->assertCount(2, $results);
    }

    // ========== CHAINABLE METHOD COMPATIBILITY ==========

    /**
     * Test operators work with whereIn
     */
    public function testOperatorsWithWhereIn(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$gt' => 50000]])
            ->whereIn('department', ['Engineering', 'Design'])
            ->get();

        $this->assertCount(3, $results); // Bob, Diana, Grace

        foreach ($results as $record) {
            $this->assertGreaterThan(50000, $record['salary']);
            $this->assertContains($record['department'], ['Engineering', 'Design']);
        }
    }

    /**
     * Test operators work with between()
     */
    public function testOperatorsWithBetween(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['department' => ['$ne' => 'Executive']])
            ->between('age', 25, 35)
            ->get();

        $this->assertCount(5, $results);

        foreach ($results as $record) {
            $this->assertNotEquals('Executive', $record['department']);
            $this->assertGreaterThanOrEqual(25, $record['age']);
            $this->assertLessThanOrEqual(35, $record['age']);
        }
    }

    /**
     * Test operators with orWhere
     */
    public function testOperatorsWithOrWhere(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['salary' => ['$gte' => 100000]])
            ->orWhere(['age' => ['$lt' => 25]])
            ->get();

        $this->assertCount(3, $results); // Charlie, Eve (high salary), Frank (young)
    }
}
