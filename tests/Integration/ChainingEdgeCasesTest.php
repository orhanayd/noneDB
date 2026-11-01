<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Edge case tests for method chaining (noneDBQuery)
 * Tests non-numeric values, missing fields, type coercion, boundaries.
 *
 * Expected values come from docs/QUERY.md (comparison rules, total sort order, like rules, aggregates,
 * limit(0)) and are derived by hand from the inserted records. Results without sort() are in key order.
 */
class ChainingEdgeCasesTest extends noneDBTestCase
{
    /**
     * Names of the result records, in result order
     */
    private function names(array $records): array
    {
        return array_column($records, 'name');
    }

    /**
     * Keys of the result records, in result order
     */
    private function keys(array $records): array
    {
        return array_column($records, 'key');
    }

    // ==========================================
    // AGGREGATION WITH NON-NUMERIC VALUES
    // sum()/avg() of the builder return float (public signature); only numbers and numeric strings count
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

        $this->assertSame(150.0, $sum); // Only 100 + 50
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

        $this->assertSame(150.0, $sum);
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

        $this->assertSame(150.0, $sum);
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
        $avg = $this->noneDB->query($this->testDbName)->avg('score');

        // true is not counted as 1: 100 + 50, and the average is over the two numbers only
        $this->assertSame(150.0, $sum);
        $this->assertSame(75.0, $avg);
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

        $this->assertSame(175.5, $sum);
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

        $this->assertSame(0.0, $sum);
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

        $this->assertSame(0.0, $sum);
    }

    /**
     * @test
     */
    public function sumReturnsZeroForEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $sum = $this->noneDB->query($this->testDbName)->sum('score');

        $this->assertSame(0.0, $sum);
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

        $this->assertSame(75.0, $avg); // (100 + 50) / 2
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

        $this->assertSame(0.0, $avg);
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

        $this->assertSame(0.0, $avg);
    }

    /**
     * @test
     */
    public function avgReturnsZeroForEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $avg = $this->noneDB->query($this->testDbName)->avg('score');

        $this->assertSame(0.0, $avg);
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

        $this->assertSame(100.0, $avg);
    }

    /**
     * min()/max() use the sort order (QUERY.md "Sorting & Pagination"): numbers < other strings,
     * so a non-numeric string is never the minimum of a field that also holds numbers, and it is the
     * maximum.
     * @test
     */
    public function minAndMaxUseSortOrderAcrossTypes(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob', 'score' => 'invalid'],
            ['name' => 'Charlie', 'score' => 50],
        ]);

        $this->assertSame(50, $this->noneDB->query($this->testDbName)->min('score'));
        $this->assertSame('invalid', $this->noneDB->query($this->testDbName)->max('score'));
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

        $this->assertSame(150.0, $sum);
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

        $this->assertSame(75.0, $avg); // (100 + 50) / 2, Bob is ignored
    }

    /**
     * distinct(): order of first appearance, records without the field left out (QUERY.md "distinct()")
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

        $this->assertSame(['Istanbul', 'Ankara'], $cities);
    }

    /**
     * A missing field sorts first ("missing / null" is the first class of the total order) and last
     * with desc (the order reversed)
     * @test
     */
    public function sortHandlesMissingField(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob'],  // no score field
            ['name' => 'Charlie', 'score' => 50],
        ]);

        $asc = $this->noneDB->query($this->testDbName)
            ->sort('score', 'asc')
            ->get();
        $desc = $this->noneDB->query($this->testDbName)
            ->sort('score', 'desc')
            ->get();

        $this->assertSame(['Bob', 'Charlie', 'Alice'], $this->names($asc));
        $this->assertSame(['Alice', 'Charlie', 'Bob'], $this->names($desc));
        // The record without the field is returned as stored (no field added)
        $this->assertArrayNotHasKey('score', $asc[0]);
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
        $this->assertSame([], $results);
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

        $this->assertSame(['Bob'], $this->names($results));
        $this->assertSame([1], $this->keys($results));
    }

    /**
     * "a number against a non-numeric string never match" (QUERY.md "How values compare"): 'thirty' is
     * neither in the range nor an error; notBetween() returns it (complement)
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
        $outside = $this->noneDB->query($this->testDbName)
            ->notBetween('age', 20, 40)
            ->get();

        $this->assertSame(['Alice', 'Charlie'], $this->names($results));
        $this->assertSame(['Bob'], $this->names($outside));
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

        $this->assertSame(['Alice', 'Charlie'], $this->names($results));
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

        // Inclusive on both ends
        $this->assertSame(['Alice', 'Bob'], $this->names($results));
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

        $this->assertSame(['Bob'], $this->names($results));
    }

    // ==========================================
    // LIKE EDGE CASES
    // like(): strings and numbers match; booleans, arrays, null and missing fields never do
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

        $this->assertSame(['Alice', 'Charlie'], $this->names($results));
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
        $notLike = $this->noneDB->query($this->testDbName)
            ->notLike('email', 'gmail')
            ->get();

        // Arrays never match like(), so notLike() keeps them
        $this->assertSame(['Alice', 'Charlie'], $this->names($results));
        $this->assertSame(['Bob'], $this->names($notLike));
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
        $startsWith = $this->noneDB->query($this->testDbName)
            ->like('code', '^123')
            ->get();

        // The integer matches as its decimal text
        $this->assertSame(['Alice', 'Bob'], $this->names($results));
        $this->assertSame(['Bob'], $this->names($startsWith));
    }

    /**
     * @test
     */
    public function likeWithBooleanFieldValueNeverMatches(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'flag' => true],
            ['name' => 'Bob', 'flag' => '1'],
            ['name' => 'Charlie', 'flag' => false],
        ]);

        // true would be "1" as text: like() ignores booleans (QUERY.md "like() / notLike()")
        $this->assertSame(['Bob'], $this->names($this->noneDB->query($this->testDbName)->like('flag', '1')->get()));
        $this->assertSame([], $this->noneDB->query($this->testDbName)->like('flag', '')->where(['name' => 'Charlie'])->get());
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

        $this->assertSame(['Alice', 'Charlie'], $this->names($results));
    }

    /**
     * @test
     */
    public function likeWithEmptyPattern(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'email' => 'alice@gmail.com'],
            ['name' => 'Bob', 'email' => 'bob@yahoo.com'],
            ['name' => 'Charlie'],  // no email field: never matches, not even the empty pattern
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->like('email', '')
            ->get();

        // Empty pattern matches every string (contains the empty string)
        $this->assertSame(['Alice', 'Bob'], $this->names($results));
    }

    /**
     * Only a leading ^ and a trailing $ are special; "+", "." and the rest match themselves
     * @test
     */
    public function likeWithRegexSpecialChars(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'email' => 'alice+test@gmail.com'],
            ['name' => 'Bob', 'email' => 'bob.test@yahoo.com'],
            ['name' => 'Charlie', 'email' => 'charlie@gmail.com'],
            ['name' => 'David', 'email' => 'davidxtest@gmail.com'],
        ]);

        $plus = $this->noneDB->query($this->testDbName)->like('email', '+test')->get();
        $dot = $this->noneDB->query($this->testDbName)->like('email', '.test')->get();
        $star = $this->noneDB->query($this->testDbName)->like('email', 'a.*test')->get();

        $this->assertSame(['Alice'], $this->names($plus));
        // "." is literal: davidxtest doesn't match ".test"
        $this->assertSame(['Bob'], $this->names($dot));
        $this->assertSame([], $star);
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

        $this->assertSame([], $results);
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

        $this->assertSame([], $results);
        // One less than the total leaves the last record
        $this->assertSame(['Charlie'], $this->names($this->noneDB->query($this->testDbName)->offset(2)->get()));
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

        $this->assertSame(['Alice', 'Bob'], $this->names($results));
        $this->assertSame([0, 1], $this->keys($results));
    }

    /**
     * limit(0) returns nothing: get() is empty, first() null, exists() false, count() 0
     * (QUERY.md "limit() / offset()" and "count()": limit applies to count)
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

        $this->assertSame([], $results);
        $this->assertNull($this->noneDB->query($this->testDbName)->limit(0)->first());
        $this->assertNull($this->noneDB->query($this->testDbName)->limit(0)->last());
        $this->assertFalse($this->noneDB->query($this->testDbName)->limit(0)->exists());
        $this->assertSame(0, $this->noneDB->query($this->testDbName)->limit(0)->count());
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

        $this->assertSame(['Alice', 'Bob'], $this->names($results));
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

        $this->assertSame(['Charlie', 'David'], $this->names($results));
        $this->assertSame([2, 3], $this->keys($results));
        // count() applies offset and limit too
        $this->assertSame(2, $this->noneDB->query($this->testDbName)->offset(2)->limit(2)->count());
        $this->assertSame(1, $this->noneDB->query($this->testDbName)->offset(4)->limit(2)->count());
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

        $this->assertSame(['name' => 'Alice', 'age' => 25, 'key' => 0], $this->sorted($first, ['name', 'age', 'key']));
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

        $this->assertSame(['name' => 'Alice', 'age' => 25, 'key' => 0], $this->sorted($last, ['name', 'age', 'key']));
    }

    /**
     * first()/last() follow the result order: key order without sort(), the sort order with it
     * @test
     */
    public function firstAndLastFollowResultOrder(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Bob', 'age' => 30],
            ['name' => 'Alice', 'age' => 40],
            ['name' => 'Charlie', 'age' => 20],
        ]);

        $q = function () { return $this->noneDB->query($this->testDbName); };
        $this->assertSame('Bob', $q()->first()['name']);
        $this->assertSame('Charlie', $q()->last()['name']);
        $this->assertSame('Charlie', $q()->sort('age')->first()['name']);
        $this->assertSame('Alice', $q()->sort('age')->last()['name']);
        $this->assertSame('Alice', $q()->sort('age', 'desc')->first()['name']);
        // last() is the last record after offset/limit
        $this->assertSame('Bob', $q()->sort('age')->limit(2)->last()['name']);
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

        $this->assertSame(0, $count);
        $this->assertSame(0, $this->noneDB->query($this->testDbName)->where(['name' => 'x'])->count());
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
        // The matching counterpart, so the false above isn't a constant
        $this->assertTrue($this->noneDB->query($this->testDbName)->where(['name' => 'Alice'])->exists());
    }

    // ==========================================
    // DISTINCT EDGE CASES
    // ==========================================

    /**
     * distinct() is typed: 1, 1.0 and "1" are three values (QUERY.md "distinct()"); floats keep their
     * type when stored (API.md "insert()")
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

        $this->assertSame([100, '100', 100.0], $distinct);
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

        // null is left out
        $this->assertSame(['Istanbul', 'Ankara'], $distinct);
    }

    /**
     * @test
     */
    public function distinctOnEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $distinct = $this->noneDB->query($this->testDbName)->distinct('city');

        $this->assertSame([], $distinct);
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

        $this->assertSame([], $distinct);
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

        $this->assertSame(0, $result['n']);
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertCount(1, $all);
        $this->assertSame(['name' => 'Alice', 'key' => 0], $this->sorted($all[0], ['name', 'key']));
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

        $this->assertSame(0, $result['n']);
        $this->assertSame(1, $this->noneDB->count($this->testDbName));
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertCount(1, $all);
        $this->assertSame(['name' => 'Alice', 'key' => 0], $this->sorted($all[0], ['name', 'key']));
    }

    /**
     * @test
     */
    public function updateOnEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $result = $this->noneDB->query($this->testDbName)
            ->update(['active' => true]);

        $this->assertSame(0, $result['n']);
        $this->assertSame(0, $this->noneDB->count($this->testDbName));
    }

    /**
     * @test
     */
    public function deleteOnEmptyDatabase(): void
    {
        $this->noneDB->createDB($this->testDbName);

        $result = $this->noneDB->query($this->testDbName)->delete();

        $this->assertSame(0, $result['n']);
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

        $this->assertSame(3, $result['n']);
        $this->assertSame(0, $this->noneDB->count($this->testDbName));
        $this->assertSame([], $this->noneDB->find($this->testDbName, 0));
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

        $this->assertSame(3, $result['n']);

        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($all));
        $this->assertSame([0, 1, 2], $this->keys($all));
        foreach ($all as $record) {
            $this->assertTrue($record['active']);
            $this->assertCount(3, $record); // name, active, key: nothing else was added
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
            ['name' => 'David', 'active' => 1],   // strict: 1 is not true
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->get();
        $false = $this->noneDB->query($this->testDbName)
            ->where(['active' => false])
            ->get();

        $this->assertSame(['Alice', 'Charlie'], $this->names($results));
        $this->assertSame(['Bob'], $this->names($false));
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
            ['name' => 'David'],  // missing field: null matches only a field that exists with null
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['email' => null])
            ->get();

        $this->assertSame(['Bob'], $this->names($results));
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
            ['name' => 'David', 'score' => '0'],
            ['name' => 'Eve', 'score' => false],
            ['name' => 'Frank', 'score' => null],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['score' => 0])
            ->get();

        // Strict: "0", false and null are not 0
        $this->assertSame(['Bob'], $this->names($results));
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
            ['name' => 'David', 'nickname' => null],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['nickname' => ''])
            ->get();

        $this->assertSame(['Bob'], $this->names($results));
    }

    /**
     * A plain array value is compared as a whole (===): same elements in the same order
     * @test
     */
    public function whereWithArrayValue(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'tags' => ['php', 'js']],
            ['name' => 'Bob', 'tags' => ['python']],
            ['name' => 'Charlie', 'tags' => ['php', 'js']],
            ['name' => 'David', 'tags' => ['js', 'php']],
            ['name' => 'Eve', 'tags' => ['php', 'js', 'go']],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['tags' => ['php', 'js']])
            ->get();

        $this->assertSame(['Alice', 'Charlie'], $this->names($results));
    }

    // ==========================================
    // TYPE COERCION EDGE CASES
    // ==========================================

    /**
     * Plain where() values compare with === ("'25' is not 25", QUERY.md "How values compare")
     * @test
     */
    public function whereNumericStringVsIntegerIsStrict(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'code' => 100],
            ['name' => 'Bob', 'code' => '100'],
            ['name' => 'Charlie', 'code' => 200],
            ['name' => 'David', 'code' => 100.0],
        ]);

        $int = $this->noneDB->query($this->testDbName)->where(['code' => 100])->get();
        $string = $this->noneDB->query($this->testDbName)->where(['code' => '100'])->get();
        $float = $this->noneDB->query($this->testDbName)->where(['code' => 100.0])->get();

        $this->assertSame(['Alice'], $this->names($int));
        $this->assertSame(['Bob'], $this->names($string));
        $this->assertSame(['David'], $this->names($float));
    }

    /**
     * Total order: numbers (int and float together) < numeric strings (by number), reversed for desc
     * @test
     */
    public function sortWithMixedNumericTypes(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob', 'score' => '50'],  // string
            ['name' => 'Charlie', 'score' => 75.5],  // float
        ]);

        $asc = $this->noneDB->query($this->testDbName)
            ->sort('score', 'asc')
            ->get();
        $desc = $this->noneDB->query($this->testDbName)
            ->sort('score', 'desc')
            ->get();

        $this->assertSame(['Charlie', 'Alice', 'Bob'], $this->names($asc));
        $this->assertSame(['Bob', 'Alice', 'Charlie'], $this->names($desc));
    }

    /**
     * Sorting is stable: equal values keep key order, with asc and with desc (QUERY.md: "Sorting is stable",
     * top-k "ties keep key order")
     * @test
     */
    public function sortTiesKeepKeyOrderAscAndDesc(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'A', 'g' => 2],
            ['name' => 'B', 'g' => 1],
            ['name' => 'C', 'g' => 2],
            ['name' => 'D', 'g' => 1],
            ['name' => 'E', 'g' => 2],
        ]);

        $asc = $this->noneDB->query($this->testDbName)->sort('g')->get();
        $desc = $this->noneDB->query($this->testDbName)->sort('g', 'desc')->get();
        $descLimited = $this->noneDB->query($this->testDbName)->sort('g', 'desc')->limit(2)->get();

        $this->assertSame(['B', 'D', 'A', 'C', 'E'], $this->names($asc));
        $this->assertSame(['A', 'C', 'E', 'B', 'D'], $this->names($desc));
        $this->assertSame(['A', 'C'], $this->names($descLimited));
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

        $this->assertSame(['Alice'], $this->names($results));
    }

    /**
     * Two where() calls on the same field both apply (AND), they don't overwrite each other
     * @test
     */
    public function multipleWhereCallsOnSameFieldAreAnded(): void
    {
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 17],
            ['name' => 'Bob', 'age' => 18],
            ['name' => 'Charlie', 'age' => 40],
            ['name' => 'David', 'age' => 65],
        ]);

        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => ['$gte' => 18]])
            ->where(['age' => ['$lt' => 65]])
            ->get();
        $contradiction = $this->noneDB->query($this->testDbName)
            ->where(['age' => 18])
            ->where(['age' => 40])
            ->get();

        $this->assertSame(['Bob', 'Charlie'], $this->names($results));
        $this->assertSame([], $contradiction);
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

        $this->assertSame(['Alice'], $this->names($results));
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

        $this->assertSame(['Alice'], $this->names($results));
    }

    /**
     * The fields of a record in a fixed order, so records compare with assertSame regardless of the
     * order the fields come in (field order is not part of the documented result)
     */
    private function sorted(?array $record, array $order): ?array
    {
        if ($record === null) {
            return null;
        }
        $this->assertSame([], array_values(array_diff(array_keys($record), $order)), 'unexpected fields');
        $out = [];
        foreach ($order as $field) {
            if (array_key_exists($field, $record)) {
                $out[$field] = $record[$field];
            }
        }
        return $out;
    }
}
