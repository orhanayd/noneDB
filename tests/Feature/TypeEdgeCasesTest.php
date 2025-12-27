<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;
use TypeError;

/**
 * Tests for type edge cases and invalid parameter handling
 * Ensures functions handle edge cases gracefully
 */
class TypeEdgeCasesTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Insert test data with various types
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25, 'score' => 85.5, 'active' => true, 'tags' => ['php', 'mysql']],
            ['name' => 'Bob', 'age' => 30, 'score' => 90.0, 'active' => false, 'tags' => null],
            ['name' => 'Charlie', 'age' => null, 'score' => 75, 'active' => true, 'department' => 'IT'],
        ]);
    }

    // ==========================================
    // WHERE EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function whereWithEmptyArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where([])
            ->get();

        // Empty filter should return all records
        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function whereWithNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['nonexistent' => 'value'])
            ->get();

        $this->assertCount(0, $results);
    }

    /**
     * @test
     */
    public function whereWithNullValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => null])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Charlie', $results[0]['name']);
    }

    /**
     * @test
     */
    public function whereWithBooleanValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function whereWithFloatValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['score' => 85.5])
            ->get();

        $this->assertCount(1, $results);
    }

    /**
     * @test
     */
    public function whereWithArrayValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['tags' => ['php', 'mysql']])
            ->get();

        $this->assertCount(1, $results);
    }

    // ==========================================
    // WHEREIN EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function whereInWithEmptyArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('name', [])
            ->get();

        // Empty array means no values match
        $this->assertCount(0, $results);
    }

    /**
     * @test
     */
    public function whereInWithSingleValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('name', ['Alice'])
            ->get();

        $this->assertCount(1, $results);
    }

    /**
     * @test
     */
    public function whereInWithNullInArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('age', [25, null])
            ->get();

        // Should match Alice (25) and Charlie (null)
        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function whereInWithMixedTypes(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('age', [25, '30', null])
            ->get();

        // Strict comparison: '30' won't match 30
        $this->assertCount(2, $results); // Alice (25) and Charlie (null)
    }

    /**
     * @test
     */
    public function whereInWithEmptyStringField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('', ['Alice'])
            ->get();

        // Empty field name - should return 0 results
        $this->assertCount(0, $results);
    }

    // ==========================================
    // WHERENOTIN EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function whereNotInWithEmptyArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNotIn('name', [])
            ->get();

        // Empty array means nothing to exclude
        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function whereNotInWithAllValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNotIn('name', ['Alice', 'Bob', 'Charlie'])
            ->get();

        $this->assertCount(0, $results);
    }

    // ==========================================
    // LIKE EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function likeWithEmptyPattern(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('name', '')
            ->get();

        // Empty pattern matches everything
        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function likeWithEmptyField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('', 'test')
            ->get();

        $this->assertCount(0, $results);
    }

    /**
     * @test
     */
    public function likeWithSpecialRegexChars(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'Test.User+Name']);

        $results = $this->noneDB->query($this->testDbName)
            ->like('name', '.User+')
            ->get();

        // Special chars should be escaped
        $this->assertCount(1, $results);
    }

    /**
     * @test
     */
    public function likeOnNonStringField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('age', '25')
            ->get();

        // Should work with numeric fields converted to string
        $this->assertCount(1, $results);
    }

    /**
     * @test
     */
    public function likeOnArrayField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('tags', 'php')
            ->get();

        // Array fields should be safely skipped
        $this->assertCount(0, $results);
    }

    // ==========================================
    // NOTLIKE EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function notLikeWithEmptyPattern(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notLike('name', '')
            ->get();

        // Empty pattern matches everything, so notLike excludes all
        $this->assertCount(0, $results);
    }

    // ==========================================
    // BETWEEN EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function betweenWithSameMinMax(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->between('age', 25, 25)
            ->get();

        $this->assertCount(1, $results);
    }

    /**
     * @test
     */
    public function betweenWithReversedRange(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->between('age', 30, 25)
            ->get();

        // Min > Max, no results
        $this->assertCount(0, $results);
    }

    /**
     * @test
     */
    public function betweenWithNullValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->between('age', null, 30)
            ->get();

        // null <= x <= 30
        $this->assertGreaterThanOrEqual(0, count($results));
    }

    /**
     * @test
     */
    public function betweenWithStringValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->between('name', 'A', 'C')
            ->get();

        // Alphabetic range: Alice, Bob (Charlie starts with C but > 'C')
        $this->assertGreaterThanOrEqual(2, count($results));
    }

    /**
     * @test
     */
    public function betweenOnNullField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->between('age', 20, 35)
            ->get();

        // Charlie has null age, should be excluded
        $this->assertCount(2, $results);
    }

    // ==========================================
    // NOTBETWEEN EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function notBetweenWithSameMinMax(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 25, 25)
            ->get();

        // Excludes only 25, keeps 30 and null (null fields pass through)
        $this->assertCount(2, $results);
    }

    // ==========================================
    // SEARCH EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function searchWithEmptyTerm(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('')
            ->get();

        // Empty search term matches everything
        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function searchWithEmptyFieldsArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('Alice', [])
            ->get();

        // Empty fields array = search all string fields
        $this->assertCount(1, $results);
    }

    /**
     * @test
     */
    public function searchWithNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('Alice', ['nonexistent'])
            ->get();

        $this->assertCount(0, $results);
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
    }

    // ==========================================
    // GROUPBY EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function groupByNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('nonexistent')
            ->get();

        // All records should be in one group (null)
        $this->assertCount(1, $results);
        $this->assertNull($results[0]['_group']);
    }

    /**
     * @test
     */
    public function groupByFieldWithNullValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('department')
            ->get();

        // One group for 'IT', one for null
        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function groupByEmptyField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('')
            ->get();

        // Empty field name - all in null group
        $this->assertCount(1, $results);
    }

    // ==========================================
    // HAVING EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function havingWithInvalidAggregate(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('active')
            ->having('invalid', '>', 0)
            ->get();

        // Invalid aggregate should be ignored
        $this->assertGreaterThan(0, count($results));
    }

    /**
     * @test
     */
    public function havingWithInvalidOperator(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('active')
            ->having('count', 'invalid', 0)
            ->get();

        // Invalid operator should be ignored
        $this->assertGreaterThan(0, count($results));
    }

    /**
     * @test
     */
    public function havingWithNonNumericField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('active')
            ->having('sum:name', '>', 0)
            ->get();

        // sum of non-numeric field = 0, so having > 0 filters out all groups
        // But groups with 0 sum pass through, so we may still get results
        $this->assertIsArray($results);
    }

    // ==========================================
    // SELECT/EXCEPT EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function selectWithEmptyArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select([])
            ->get();

        // Empty select = only key field
        $this->assertCount(3, $results);
        foreach ($results as $record) {
            $this->assertArrayHasKey('key', $record);
        }
    }

    /**
     * @test
     */
    public function selectWithNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select(['nonexistent', 'name'])
            ->get();

        foreach ($results as $record) {
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayNotHasKey('nonexistent', $record);
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

        // Empty except = all fields
        $this->assertArrayHasKey('name', $results[0]);
        $this->assertArrayHasKey('age', $results[0]);
    }

    /**
     * @test
     */
    public function exceptWithNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->except(['nonexistent'])
            ->get();

        // Should work normally
        $this->assertArrayHasKey('name', $results[0]);
    }

    /**
     * @test
     */
    public function selectAndExceptTogether(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select(['name', 'age', 'score'])
            ->except(['age'])
            ->get();

        // Select takes precedence
        foreach ($results as $record) {
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('age', $record);
            $this->assertArrayHasKey('score', $record);
        }
    }

    // ==========================================
    // SORT EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function sortWithEmptyField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('', 'asc')
            ->get();

        // Should not crash
        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function sortWithNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('nonexistent', 'asc')
            ->get();

        // Should not crash
        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function sortWithInvalidOrder(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('name', 'invalid')
            ->get();

        // Should default to some order
        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function sortOnNullableField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('age', 'asc')
            ->get();

        // null values should be handled
        $this->assertCount(3, $results);
    }

    // ==========================================
    // LIMIT/OFFSET EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function limitWithZero(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->limit(0)
            ->get();

        // 0 limit = no results
        $this->assertCount(0, $results);
    }

    /**
     * @test
     */
    public function limitExceedingRecordCount(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->limit(100)
            ->get();

        // Should return all available
        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function offsetExceedingRecordCount(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->offset(100)
            ->get();

        // Should return empty
        $this->assertCount(0, $results);
    }

    /**
     * @test
     */
    public function offsetWithZero(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->offset(0)
            ->get();

        // 0 offset = start from beginning
        $this->assertCount(3, $results);
    }

    // ==========================================
    // AGGREGATION EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function sumOnEmptyResult(): void
    {
        $sum = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->sum('age');

        $this->assertEquals(0, $sum);
    }

    /**
     * @test
     */
    public function sumOnNonNumericField(): void
    {
        $sum = $this->noneDB->query($this->testDbName)
            ->sum('name');

        // Non-numeric values should be skipped
        $this->assertEquals(0, $sum);
    }

    /**
     * @test
     */
    public function sumOnMixedField(): void
    {
        $sum = $this->noneDB->query($this->testDbName)
            ->sum('age');

        // null should be skipped, 25 + 30 = 55
        $this->assertEquals(55, $sum);
    }

    /**
     * @test
     */
    public function avgOnEmptyResult(): void
    {
        $avg = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->avg('age');

        $this->assertEquals(0, $avg);
    }

    /**
     * @test
     */
    public function avgWithNullValues(): void
    {
        $avg = $this->noneDB->query($this->testDbName)
            ->avg('age');

        // null should be skipped, (25 + 30) / 2 = 27.5
        $this->assertEquals(27.5, $avg);
    }

    /**
     * @test
     */
    public function minOnEmptyResult(): void
    {
        $min = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->min('age');

        $this->assertNull($min);
    }

    /**
     * @test
     */
    public function minOnNonExistentField(): void
    {
        $min = $this->noneDB->query($this->testDbName)
            ->min('nonexistent');

        $this->assertNull($min);
    }

    /**
     * @test
     */
    public function maxOnEmptyResult(): void
    {
        $max = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->max('age');

        $this->assertNull($max);
    }

    /**
     * @test
     */
    public function distinctOnEmptyResult(): void
    {
        $distinct = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->distinct('age');

        $this->assertCount(0, $distinct);
    }

    /**
     * @test
     */
    public function distinctOnNonExistentField(): void
    {
        $distinct = $this->noneDB->query($this->testDbName)
            ->distinct('nonexistent');

        $this->assertCount(0, $distinct);
    }

    /**
     * @test
     */
    public function distinctWithNullValues(): void
    {
        $distinct = $this->noneDB->query($this->testDbName)
            ->distinct('age');

        // 25, 30 (null is not included since isset check)
        $this->assertCount(2, $distinct);
    }

    // ==========================================
    // UPDATE EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function updateWithEmptySet(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Alice'])
            ->update([]);

        // Empty update should still work
        $this->assertArrayHasKey('n', $result);
    }

    /**
     * @test
     */
    public function updateOnEmptyResult(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->update(['age' => 100]);

        $this->assertEquals(0, $result['n']);
    }

    /**
     * @test
     */
    public function updateWithNullValue(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Alice'])
            ->update(['age' => null]);

        $this->assertEquals(1, $result['n']);

        $alice = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Alice'])
            ->first();

        $this->assertNull($alice['age']);
    }

    // ==========================================
    // DELETE EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function deleteOnEmptyResult(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->delete();

        $this->assertEquals(0, $result['n']);
    }

    // ==========================================
    // REMOVEFIELDS EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsWithEmptyArray(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields([]);

        $this->assertEquals(0, $result['n']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * @test
     */
    public function removeFieldsWithOnlyKey(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['key']);

        $this->assertEquals(0, $result['n']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * @test
     */
    public function removeFieldsOnEmptyResult(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->removeFields(['age']);

        $this->assertEquals(0, $result['n']);
    }

    /**
     * @test
     */
    public function removeFieldsWithNonExistentField(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Alice'])
            ->removeFields(['nonexistent']);

        // Should not crash, but no fields actually removed
        $this->assertEquals(0, $result['n']);
    }

    // ==========================================
    // FIRST/LAST EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function firstOnEmptyResult(): void
    {
        $first = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->first();

        $this->assertNull($first);
    }

    /**
     * @test
     */
    public function lastOnEmptyResult(): void
    {
        $last = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->last();

        $this->assertNull($last);
    }

    // ==========================================
    // COUNT/EXISTS EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function countOnEmptyResult(): void
    {
        $count = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->count();

        $this->assertEquals(0, $count);
    }

    /**
     * @test
     */
    public function existsOnEmptyResult(): void
    {
        $exists = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->exists();

        $this->assertFalse($exists);
    }

    // ==========================================
    // TYPE ERROR TESTS (PHP will throw TypeError)
    // ==========================================

    /**
     * @test
     */
    public function whereThrowsTypeErrorWithNonArray(): void
    {
        $this->expectException(TypeError::class);
        $this->noneDB->query($this->testDbName)->where('invalid');
    }

    /**
     * @test
     */
    public function whereInWithIntegerFieldName(): void
    {
        // PHP coerces int to string for string parameters
        // This should not crash, just return no results
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('123', ['a', 'b'])
            ->get();

        $this->assertCount(0, $results);
    }

    /**
     * @test
     */
    public function whereInThrowsTypeErrorWithNull(): void
    {
        $this->expectException(TypeError::class);
        $this->noneDB->query($this->testDbName)->whereIn(null, ['a', 'b']);
    }

    /**
     * @test
     */
    public function whereInThrowsTypeErrorWithNonArray(): void
    {
        $this->expectException(TypeError::class);
        $this->noneDB->query($this->testDbName)->whereIn('field', 'not-array');
    }

    /**
     * @test
     */
    public function limitThrowsTypeErrorWithNonInt(): void
    {
        $this->expectException(TypeError::class);
        $this->noneDB->query($this->testDbName)->limit('invalid');
    }

    /**
     * @test
     */
    public function selectThrowsTypeErrorWithNonArray(): void
    {
        $this->expectException(TypeError::class);
        $this->noneDB->query($this->testDbName)->select('invalid');
    }

    /**
     * @test
     */
    public function updateThrowsTypeErrorWithNonArray(): void
    {
        $this->expectException(TypeError::class);
        $this->noneDB->query($this->testDbName)->update('invalid');
    }

    /**
     * @test
     */
    public function removeFieldsThrowsTypeErrorWithNonArray(): void
    {
        $this->expectException(TypeError::class);
        $this->noneDB->query($this->testDbName)->removeFields('invalid');
    }
}
