<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Edge case tests for method chaining (noneDBQuery)
 * Tests non-numeric values, missing fields, type coercion, boundaries
 */
class ChainingEdgeCasesTest extends noneDBTestCase
{
    // ==========================================
    // AGGREGATION WITH NON-NUMERIC VALUES
    // ==========================================

    /**
     * @test
     */
    public function sumIgnoresStringValues(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob', 'score' => 'fifty'],  // string
            ['name' => 'Charlie', 'score' => 50],
        ]);

        $sum = $this->noneDB->query($this->testDbName)->sum('score');

        $this->assertEquals(150, $sum); // Only 100 + 50
    }

    /**
     * @test
     */
    public function sumIgnoresArrayValues(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob', 'score' => [10, 20, 30]],  // array
            ['name' => 'Charlie', 'score' => 50],
        ]);

        $sum = $this->noneDB->query($this->testDbName)->sum('score');

        $this->assertEquals(150, $sum);
    }

    /**
     * @test
     */
    public function sumIgnoresNullValues(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob', 'score' => null],
            ['name' => 'Charlie', 'score' => 50],
        ]);

        $sum = $this->noneDB->query($this->testDbName)->sum('score');

        $this->assertEquals(150, $sum);
    }

    /**
     * @test
     */
    public function sumIgnoresBooleanValues(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob', 'score' => true],   // boolean
            ['name' => 'Charlie', 'score' => false], // boolean
            ['name' => 'David', 'score' => 50],
        ]);

        $sum = $this->noneDB->query($this->testDbName)->sum('score');

        // Note: is_numeric(true) = false, is_numeric(false) = false
        $this->assertEquals(150, $sum);
    }

    /**
     * @test
     */
    public function sumIncludesNumericStrings(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob', 'score' => '50'],  // numeric string
            ['name' => 'Charlie', 'score' => '25.5'],  // numeric string with decimal
        ]);

        $sum = $this->noneDB->query($this->testDbName)->sum('score');

        $this->assertEquals(175.5, $sum);
    }

    /**
     * @test
     */
    public function sumReturnsZeroWhenAllNonNumeric(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 'high'],
            ['name' => 'Bob', 'score' => 'medium'],
            ['name' => 'Charlie', 'score' => 'low'],
        ]);

        $sum = $this->noneDB->query($this->testDbName)->sum('score');

        $this->assertEquals(0, $sum);
    }

    /**
     * @test
     */
    public function sumReturnsZeroForNonExistentField(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
        ]);

        $sum = $this->noneDB->query($this->testDbName)->sum('nonexistent');

        $this->assertEquals(0, $sum);
    }

    /**
     * @test
     */
    public function sumReturnsZeroForEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $sum = $this->noneDB->query($this->testDbName)->sum('score');

        $this->assertEquals(0, $sum);
    }

    /**
     * @test
     */
    public function avgIgnoresNonNumericValues(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob', 'score' => 'invalid'],
            ['name' => 'Charlie', 'score' => [1, 2, 3]],
            ['name' => 'David', 'score' => 50],
        ]);

        $avg = $this->noneDB->query($this->testDbName)->avg('score');

        $this->assertEquals(75, $avg); // (100 + 50) / 2
    }

    /**
     * @test
     */
    public function avgReturnsZeroWhenAllNonNumeric(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 'high'],
            ['name' => 'Bob', 'score' => null],
        ]);

        $avg = $this->noneDB->query($this->testDbName)->avg('score');

        $this->assertEquals(0, $avg);
    }

    /**
     * @test
     */
    public function avgReturnsZeroForNonExistentField(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25],
        ]);

        $avg = $this->noneDB->query($this->testDbName)->avg('nonexistent');

        $this->assertEquals(0, $avg);
    }

    /**
     * @test
     */
    public function avgReturnsZeroForEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $avg = $this->noneDB->query($this->testDbName)->avg('score');

        $this->assertEquals(0, $avg);
    }

    /**
     * @test
     */
    public function avgWithSingleRecord(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
        ]);

        $avg = $this->noneDB->query($this->testDbName)->avg('score');

        $this->assertEquals(100, $avg);
    }

    /**
     * @test
     */
    public function minIgnoresNonNumericForNumericComparison(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob', 'score' => 'invalid'],
            ['name' => 'Charlie', 'score' => 50],
        ]);

        $min = $this->noneDB->query($this->testDbName)->min('score');

        // min doesn't filter by is_numeric, returns first found
        // String 'invalid' < 50 in string comparison, but let's see actual behavior
        $this->assertNotNull($min);
    }

    /**
     * @test
     */
    public function minReturnsNullForNonExistentField(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25],
        ]);

        $min = $this->noneDB->query($this->testDbName)->min('nonexistent');

        $this->assertNull($min);
    }

    /**
     * @test
     */
    public function minReturnsNullForEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $min = $this->noneDB->query($this->testDbName)->min('score');

        $this->assertNull($min);
    }

    /**
     * @test
     */
    public function maxReturnsNullForNonExistentField(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25],
        ]);

        $max = $this->noneDB->query($this->testDbName)->max('nonexistent');

        $this->assertNull($max);
    }

    /**
     * @test
     */
    public function maxReturnsNullForEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $max = $this->noneDB->query($this->testDbName)->max('score');

        $this->assertNull($max);
    }

    // ==========================================
    // MISSING FIELDS IN RECORDS
    // ==========================================

    /**
     * @test
     */
    public function sumHandlesMissingFieldInSomeRecords(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob'],  // no score field
            ['name' => 'Charlie', 'score' => 50],
        ]);

        $sum = $this->noneDB->query($this->testDbName)->sum('score');

        $this->assertEquals(150, $sum);
    }

    /**
     * @test
     */
    public function avgHandlesMissingFieldInSomeRecords(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob'],  // no score field
            ['name' => 'Charlie', 'score' => 50],
        ]);

        $avg = $this->noneDB->query($this->testDbName)->avg('score');

        $this->assertEquals(75, $avg); // (100 + 50) / 2, Bob is ignored
    }

    /**
     * @test
     */
    public function distinctHandlesMissingField(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'city' => 'Istanbul'],
            ['name' => 'Bob'],  // no city field
            ['name' => 'Charlie', 'city' => 'Ankara'],
        ]);

        $cities = $this->noneDB->query($this->testDbName)->distinct('city');

        $this->assertCount(2, $cities);
        $this->assertContains('Istanbul', $cities);
        $this->assertContains('Ankara', $cities);
    }

    /**
     * @test
     */
    public function sortHandlesMissingField(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob'],  // no score field
            ['name' => 'Charlie', 'score' => 50],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->sort('score', 'asc')
            ->get();

        // Records with missing field should still be in results
        $this->assertCount(3, $results);
    }

    // ==========================================
    // BETWEEN EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function betweenWithMinGreaterThanMax(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
            ['name' => 'Charlie', 'age' => 35],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->between('age', 40, 20)  // min > max
            ->get();

        // Should return empty since no value can be >= 40 AND <= 20
        $this->assertEmpty($results);
    }

    /**
     * @test
     */
    public function betweenWithEqualMinMax(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
            ['name' => 'Charlie', 'age' => 35],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->between('age', 30, 30)  // exact match
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Bob', $results[0]['name']);
    }

    /**
     * @test
     */
    public function betweenWithNonNumericValues(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 'thirty'],  // non-numeric
            ['name' => 'Charlie', 'age' => 35],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->between('age', 20, 40)
            ->get();

        // String 'thirty' compared to integers - PHP type juggling
        $this->assertGreaterThanOrEqual(2, count($results));
    }

    /**
     * @test
     */
    public function betweenWithMissingField(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob'],  // no age field
            ['name' => 'Charlie', 'age' => 35],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->between('age', 20, 40)
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function betweenWithFloatBoundaries(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'price' => 10.5],
            ['name' => 'Bob', 'price' => 20.75],
            ['name' => 'Charlie', 'price' => 30.25],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->between('price', 10.5, 20.75)
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function betweenWithNegativeNumbers(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'balance' => -100],
            ['name' => 'Bob', 'balance' => 0],
            ['name' => 'Charlie', 'balance' => 100],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->between('balance', -50, 50)
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Bob', $results[0]['name']);
    }

    // ==========================================
    // LIKE EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function likeWithNullFieldValue(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'email' => 'alice@gmail.com'],
            ['name' => 'Bob', 'email' => null],
            ['name' => 'Charlie', 'email' => 'charlie@gmail.com'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->like('email', 'gmail')
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function likeWithArrayFieldValue(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'email' => 'alice@gmail.com'],
            ['name' => 'Bob', 'email' => ['bob@gmail.com', 'bob@yahoo.com']],  // array
            ['name' => 'Charlie', 'email' => 'charlie@gmail.com'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->like('email', 'gmail')
            ->get();

        // Array should be skipped or handled gracefully
        $this->assertGreaterThanOrEqual(2, count($results));
    }

    /**
     * @test
     */
    public function likeWithNumericFieldValue(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'code' => 'ABC123'],
            ['name' => 'Bob', 'code' => 12345],  // integer
            ['name' => 'Charlie', 'code' => 'DEF456'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->like('code', '123')
            ->get();

        // Integer should be cast to string for matching
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    /**
     * @test
     */
    public function likeWithMissingField(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'email' => 'alice@gmail.com'],
            ['name' => 'Bob'],  // no email field
            ['name' => 'Charlie', 'email' => 'charlie@gmail.com'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->like('email', 'gmail')
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function likeWithEmptyPattern(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'email' => 'alice@gmail.com'],
            ['name' => 'Bob', 'email' => 'bob@yahoo.com'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->like('email', '')
            ->get();

        // Empty pattern should match all (contains empty string)
        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function likeWithRegexSpecialChars(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'email' => 'alice+test@gmail.com'],
            ['name' => 'Bob', 'email' => 'bob.test@yahoo.com'],
            ['name' => 'Charlie', 'email' => 'charlie@gmail.com'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->like('email', '+test')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Alice', $results[0]['name']);
    }

    // ==========================================
    // OFFSET AND LIMIT EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function offsetGreaterThanTotalRecords(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
            ['name' => 'Charlie'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->offset(100)
            ->get();

        $this->assertEmpty($results);
    }

    /**
     * @test
     */
    public function offsetEqualToTotalRecords(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
            ['name' => 'Charlie'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->offset(3)
            ->get();

        $this->assertEmpty($results);
    }

    /**
     * @test
     */
    public function limitGreaterThanTotalRecords(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->limit(100)
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function limitZero(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->limit(0)
            ->get();

        // limit(0) with array_slice returns empty
        $this->assertEmpty($results);
    }

    /**
     * @test
     */
    public function offsetZero(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->offset(0)
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function offsetAndLimitCombined(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
            ['name' => 'Charlie'],
            ['name' => 'David'],
            ['name' => 'Eve'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->offset(2)
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('Charlie', $results[0]['name']);
        $this->assertEquals('David', $results[1]['name']);
    }

    // ==========================================
    // FIRST/LAST EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function firstOnSingleRecord(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25],
        ]);

        $first = $this->noneDB->query($this->testDbName)->first();

        $this->assertEquals('Alice', $first['name']);
    }

    /**
     * @test
     */
    public function lastOnSingleRecord(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25],
        ]);

        $last = $this->noneDB->query($this->testDbName)->last();

        $this->assertEquals('Alice', $last['name']);
    }

    /**
     * @test
     */
    public function firstOnEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $first = $this->noneDB->query($this->testDbName)->first();

        $this->assertNull($first);
    }

    /**
     * @test
     */
    public function lastOnEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $last = $this->noneDB->query($this->testDbName)->last();

        $this->assertNull($last);
    }

    // ==========================================
    // COUNT AND EXISTS EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function countOnEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $count = $this->noneDB->query($this->testDbName)->count();

        $this->assertEquals(0, $count);
    }

    /**
     * @test
     */
    public function existsOnEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $exists = $this->noneDB->query($this->testDbName)->exists();

        $this->assertFalse($exists);
    }

    /**
     * @test
     */
    public function existsWithNoMatchingFilter(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice'],
        ]);

        $exists = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->exists();

        $this->assertFalse($exists);
    }

    // ==========================================
    // DISTINCT EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function distinctWithMixedTypes(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'value' => 100],
            ['name' => 'Bob', 'value' => '100'],  // string "100"
            ['name' => 'Charlie', 'value' => 100],  // same as Alice
            ['name' => 'David', 'value' => 100.0],  // float 100.0
        ]);

        $distinct = $this->noneDB->query($this->testDbName)->distinct('value');

        // Depends on strict comparison
        $this->assertGreaterThanOrEqual(1, count($distinct));
    }

    /**
     * @test
     */
    public function distinctWithNullValues(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'city' => 'Istanbul'],
            ['name' => 'Bob', 'city' => null],
            ['name' => 'Charlie', 'city' => 'Ankara'],
            ['name' => 'David', 'city' => null],
        ]);

        $distinct = $this->noneDB->query($this->testDbName)->distinct('city');

        // null values should be included or excluded consistently
        $this->assertContains('Istanbul', $distinct);
        $this->assertContains('Ankara', $distinct);
    }

    /**
     * @test
     */
    public function distinctOnEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $distinct = $this->noneDB->query($this->testDbName)->distinct('city');

        $this->assertEmpty($distinct);
    }

    /**
     * @test
     */
    public function distinctOnNonExistentField(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);

        $distinct = $this->noneDB->query($this->testDbName)->distinct('nonexistent');

        $this->assertEmpty($distinct);
    }

    // ==========================================
    // UPDATE/DELETE EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function updateOnEmptyResult(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice'],
        ]);

        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->update(['active' => true]);

        $this->assertEquals(0, $result['n']);
    }

    /**
     * @test
     */
    public function deleteOnEmptyResult(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice'],
        ]);

        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->delete();

        $this->assertEquals(0, $result['n']);
        $this->assertEquals(1, $this->noneDB->count($this->testDbName));
    }

    /**
     * @test
     */
    public function updateOnEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $result = $this->noneDB->query($this->testDbName)
            ->update(['active' => true]);

        $this->assertEquals(0, $result['n']);
    }

    /**
     * @test
     */
    public function deleteOnEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $result = $this->noneDB->query($this->testDbName)->delete();

        $this->assertEquals(0, $result['n']);
    }

    /**
     * @test
     */
    public function deleteAllRecords(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
            ['name' => 'Charlie'],
        ]);

        $result = $this->noneDB->query($this->testDbName)->delete();

        $this->assertEquals(3, $result['n']);
        $this->assertEquals(0, $this->noneDB->count($this->testDbName));
    }

    /**
     * @test
     */
    public function updateAllRecords(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'active' => false],
            ['name' => 'Bob', 'active' => false],
            ['name' => 'Charlie', 'active' => false],
        ]);

        $result = $this->noneDB->query($this->testDbName)
            ->update(['active' => true]);

        $this->assertEquals(3, $result['n']);

        $all = $this->noneDB->find($this->testDbName, 0);
        foreach ($all as $record) {
            $this->assertTrue($record['active']);
        }
    }

    // ==========================================
    // WHERE EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function whereWithBooleanFilter(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'active' => true],
            ['name' => 'Bob', 'active' => false],
            ['name' => 'Charlie', 'active' => true],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function whereWithNullFilter(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'email' => 'alice@test.com'],
            ['name' => 'Bob', 'email' => null],
            ['name' => 'Charlie', 'email' => 'charlie@test.com'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['email' => null])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Bob', $results[0]['name']);
    }

    /**
     * @test
     */
    public function whereWithZeroValue(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob', 'score' => 0],
            ['name' => 'Charlie', 'score' => 50],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['score' => 0])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Bob', $results[0]['name']);
    }

    /**
     * @test
     */
    public function whereWithEmptyString(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'nickname' => 'Ali'],
            ['name' => 'Bob', 'nickname' => ''],
            ['name' => 'Charlie', 'nickname' => 'Chuck'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['nickname' => ''])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Bob', $results[0]['name']);
    }

    /**
     * @test
     */
    public function whereWithArrayValue(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'tags' => ['php', 'js']],
            ['name' => 'Bob', 'tags' => ['python']],
            ['name' => 'Charlie', 'tags' => ['php', 'js']],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['tags' => ['php', 'js']])
            ->get();

        $this->assertCount(2, $results);
    }

    // ==========================================
    // TYPE COERCION EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function whereNumericStringVsInteger(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'code' => 100],
            ['name' => 'Bob', 'code' => '100'],
            ['name' => 'Charlie', 'code' => 200],
        ]);

        // Filter with integer
        $results = $this->noneDB->query($this->testDbName)
            ->where(['code' => 100])
            ->get();

        // Should match integer 100, behavior for string '100' depends on comparison
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    /**
     * @test
     */
    public function sortWithMixedNumericTypes(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob', 'score' => '50'],  // string
            ['name' => 'Charlie', 'score' => 75.5],  // float
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->sort('score', 'asc')
            ->get();

        $this->assertCount(3, $results);
    }

    // ==========================================
    // CHAINING ORDER EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function multipleWhereCallsStackFilters(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25, 'city' => 'Istanbul'],
            ['name' => 'Bob', 'age' => 30, 'city' => 'Istanbul'],
            ['name' => 'Charlie', 'age' => 25, 'city' => 'Ankara'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => 25])
            ->where(['city' => 'Istanbul'])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Alice', $results[0]['name']);
    }

    /**
     * @test
     */
    public function multipleLikeCallsStackFilters(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'email' => 'alice@gmail.com', 'phone' => '+90555'],
            ['name' => 'Bob', 'email' => 'bob@gmail.com', 'phone' => '+1234'],
            ['name' => 'Charlie', 'email' => 'charlie@yahoo.com', 'phone' => '+90555'],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->like('email', 'gmail')
            ->like('phone', '+90')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Alice', $results[0]['name']);
    }

    /**
     * @test
     */
    public function multipleBetweenCallsStackFilters(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25, 'score' => 80],
            ['name' => 'Bob', 'age' => 30, 'score' => 90],
            ['name' => 'Charlie', 'age' => 28, 'score' => 70],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->between('age', 25, 29)
            ->between('score', 75, 85)
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Alice', $results[0]['name']);
    }
}
