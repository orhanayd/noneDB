<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;
use TypeError;

/**
 * Tests for type edge cases and invalid parameter handling
 * Ensures functions handle edge cases gracefully.
 *
 * Expected values follow docs/QUERY.md and docs/API.md (strict === for plain values and whereIn,
 * comparison rules of between(), like() on strings and numbers only, total sort order, typed groups and
 * distinct values) and are derived by hand from the three records of setUp(). Results without sort()
 * are in key order.
 */
class TypeEdgeCasesTest extends noneDBTestCase
{
    /** The records of setUp() (key = position) */
    private const RECORDS = [
        ['name' => 'Alice', 'age' => 25, 'score' => 85.5, 'active' => true, 'tags' => ['php', 'mysql']],
        ['name' => 'Bob', 'age' => 30, 'score' => 90.0, 'active' => false, 'tags' => null],
        ['name' => 'Charlie', 'age' => null, 'score' => 75, 'active' => true, 'department' => 'IT'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Insert test data with various types
        $this->noneDB->insert($this->testDbName, self::RECORDS);
    }

    /**
     * Names of the result records, in result order
     */
    private function names(array $records): array
    {
        return array_column($records, 'name');
    }

    /**
     * A stored record as find()/get() returns it: its fields plus "key" (fields compared regardless of
     * their order, which is not part of the documented result)
     */
    private function assertRecord(array $expected, array $actual, string $message = ''): void
    {
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual, $message);
    }

    private function storedRecord(int $key): array
    {
        return self::RECORDS[$key] + ['key' => $key];
    }

    /**
     * The database holds exactly the records of setUp(), unchanged
     */
    private function assertUnchanged(): void
    {
        $all = $this->noneDB->find($this->testDbName, 0);
        $this->assertCount(3, $all);
        foreach ($all as $i => $record) {
            $this->assertRecord($this->storedRecord($i), $record);
        }
    }

    /**
     * Groups of a groupBy() result by their _group value (group order is not documented)
     * @return array 'v' . var_export(_group) => ['count' => _count, 'names' => names of _items]
     */
    private function groupsByValue(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $this->assertSame(['_count', '_group', '_items'], $this->sortedKeys($group));
            $this->assertSame(count($group['_items']), $group['_count']);
            // "v" prefix: keys stay strings ('25' would become an int key and sort differently)
            $out['v' . var_export($group['_group'], true)] = ['count' => $group['_count'], 'names' => $this->names($group['_items'])];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private function sortedKeys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);
        return $keys;
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

        // Empty filter should return all records, unchanged, in key order
        $this->assertCount(3, $results);
        foreach ($results as $i => $record) {
            $this->assertRecord($this->storedRecord($i), $record);
        }
    }

    /**
     * @test
     */
    public function whereWithNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['nonexistent' => 'value'])
            ->get();

        $this->assertSame([], $results);
        // Not even null matches a missing field
        $this->assertSame([], $this->noneDB->query($this->testDbName)->where(['nonexistent' => null])->get());
    }

    /**
     * @test
     */
    public function whereWithNullValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['age' => null])
            ->get();

        $this->assertSame(['Charlie'], $this->names($results));
        $this->assertRecord($this->storedRecord(2), $results[0]);
    }

    /**
     * @test
     */
    public function whereWithBooleanValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->get();

        $this->assertSame(['Alice', 'Charlie'], $this->names($results));
        $this->assertSame(['Bob'], $this->names($this->noneDB->query($this->testDbName)->where(['active' => false])->get()));
    }

    /**
     * Floats keep their type (API.md "insert()") and plain values compare with ===: 90.0 is not 90,
     * 75 is not 75.0
     * @test
     */
    public function whereWithFloatValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['score' => 85.5])
            ->get();

        $this->assertSame(['Alice'], $this->names($results));
        $this->assertSame(['Bob'], $this->names($this->noneDB->query($this->testDbName)->where(['score' => 90.0])->get()));
        $this->assertSame([], $this->noneDB->query($this->testDbName)->where(['score' => 90])->get());
        $this->assertSame(['Charlie'], $this->names($this->noneDB->query($this->testDbName)->where(['score' => 75])->get()));
        $this->assertSame([], $this->noneDB->query($this->testDbName)->where(['score' => 75.0])->get());
    }

    /**
     * @test
     */
    public function whereWithArrayValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['tags' => ['php', 'mysql']])
            ->get();

        $this->assertSame(['Alice'], $this->names($results));
        // Compared as a whole: another order or a subset doesn't match
        $this->assertSame([], $this->noneDB->query($this->testDbName)->where(['tags' => ['mysql', 'php']])->get());
        $this->assertSame([], $this->noneDB->query($this->testDbName)->where(['tags' => ['php']])->get());
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
        $this->assertSame([], $results);
    }

    /**
     * @test
     */
    public function whereInWithSingleValue(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('name', ['Alice'])
            ->get();

        $this->assertSame(['Alice'], $this->names($results));
    }

    /**
     * @test
     */
    public function whereInWithNullInArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('age', [25, null])
            ->get();

        // Alice (25) and Charlie (null)
        $this->assertSame(['Alice', 'Charlie'], $this->names($results));
        // null in the list doesn't match a missing field (Alice and Bob have no "department")
        $this->assertSame([], $this->noneDB->query($this->testDbName)->whereIn('department', [null])->get());
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
        $this->assertSame(['Alice', 'Charlie'], $this->names($results)); // Alice (25) and Charlie (null)
    }

    /**
     * Lists longer than 8 values use a hash set: the result must stay strict
     * @test
     */
    public function whereInWithLongMixedTypeListIsStillStrict(): void
    {
        $values = ['25', 30.0, '30', 85.5, 'alice', 'ALICE', 0, false, true, '', 90];
        $this->assertSame([], $this->noneDB->query($this->testDbName)->whereIn('age', $values)->get());
        $this->assertSame(['Alice'], $this->names($this->noneDB->query($this->testDbName)->whereIn('score', $values)->get()));
        $this->assertSame(['Bob'], $this->names($this->noneDB->query($this->testDbName)->whereIn('active', $values)->where(['active' => false])->get()));
        $this->assertSame(['Alice', 'Charlie'], $this->names($this->noneDB->query($this->testDbName)->whereIn('active', $values)->where(['active' => true])->get()));
        $this->assertSame(['Charlie'], $this->names($this->noneDB->query($this->testDbName)->whereNotIn('score', $values)->where(['active' => true])->get()));
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
        $this->assertSame([], $results);
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
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($results));
    }

    /**
     * @test
     */
    public function whereNotInWithAllValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->whereNotIn('name', ['Alice', 'Bob', 'Charlie'])
            ->get();

        $this->assertSame([], $results);
    }

    /**
     * whereNotIn() is strict and keeps records without the field
     * @test
     */
    public function whereNotInIsStrictAndKeepsMissingFields(): void
    {
        $this->assertSame(['Bob', 'Charlie'], $this->names($this->noneDB->query($this->testDbName)->whereNotIn('age', [25, '30'])->get()));
        $this->assertSame(['Alice', 'Bob'], $this->names($this->noneDB->query($this->testDbName)->whereNotIn('department', ['IT'])->get()));
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

        // Empty pattern matches every string
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($results));
    }

    /**
     * @test
     */
    public function likeWithEmptyField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('', 'test')
            ->get();

        $this->assertSame([], $results);
    }

    /**
     * @test
     */
    public function likeWithSpecialRegexChars(): void
    {
        $this->noneDB->insert($this->testDbName, ['name' => 'Test.User+Name']);
        $this->noneDB->insert($this->testDbName, ['name' => 'TestXUserrName']);

        $results = $this->noneDB->query($this->testDbName)
            ->like('name', '.User+')
            ->get();

        // Special chars are literal: only the record that contains ".User+" itself
        $this->assertSame(['Test.User+Name'], $this->names($results));
        $this->assertSame([3], array_column($results, 'key'));
        $this->assertSame([], $this->noneDB->query($this->testDbName)->like('name', 'T.st')->get());
        $this->assertSame([], $this->noneDB->query($this->testDbName)->like('name', 'User+?')->get());
    }

    /**
     * @test
     */
    public function likeOnNonStringField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('age', '25')
            ->get();

        // Numbers match as their text; null (Charlie) never matches
        $this->assertSame(['Alice'], $this->names($results));
        $this->assertSame(['Alice'], $this->names($this->noneDB->query($this->testDbName)->like('score', '85.5')->get()));
        $this->assertSame([], $this->noneDB->query($this->testDbName)->like('age', '')->where(['name' => 'Charlie'])->get());
    }

    /**
     * @test
     */
    public function likeOnArrayField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('tags', 'php')
            ->get();

        // Array fields are never matched
        $this->assertSame([], $results);
    }

    /**
     * Booleans never match like() (true would be "1") and notLike() keeps them
     * @test
     */
    public function likeOnBooleanFieldNeverMatches(): void
    {
        $this->assertSame([], $this->noneDB->query($this->testDbName)->like('active', '1')->get());
        $this->assertSame([], $this->noneDB->query($this->testDbName)->like('active', '')->get());
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($this->noneDB->query($this->testDbName)->notLike('active', '')->get()));
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
        $this->assertSame([], $results);
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

        $this->assertSame(['Alice'], $this->names($results));
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
        $this->assertSame([], $results);
    }

    /**
     * A null bound can't be compared ("null ... never match"): between() matches nothing and
     * notBetween() returns every record (its complement)
     * @test
     */
    public function betweenWithNullBoundMatchesNothing(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->between('age', null, 30)
            ->get();
        $complement = $this->noneDB->query($this->testDbName)
            ->notBetween('age', null, 30)
            ->get();

        $this->assertSame([], $results);
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($complement));
    }

    /**
     * Strings compare lexically (byte order): "Charlie" > "C", so only Alice and Bob are in ['A', 'C']
     * @test
     */
    public function betweenWithStringValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->between('name', 'A', 'C')
            ->get();

        $this->assertSame(['Alice', 'Bob'], $this->names($results));
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($this->noneDB->query($this->testDbName)->between('name', 'A', 'Charlie')->get()));
        // Lexical, not case-insensitive: lower case letters come after upper case ones
        $this->assertSame([], $this->noneDB->query($this->testDbName)->between('name', 'a', 'z')->get());
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
        $this->assertSame(['Alice', 'Bob'], $this->names($results));
    }

    /**
     * Booleans and arrays are never in a range, even when PHP's loose comparison would say so
     * @test
     */
    public function betweenNeverMatchesBooleansOrArrays(): void
    {
        $this->assertSame([], $this->noneDB->query($this->testDbName)->between('active', 0, 1)->get());
        $this->assertSame([], $this->noneDB->query($this->testDbName)->between('active', false, true)->get());
        $this->assertSame([], $this->noneDB->query($this->testDbName)->between('tags', 'a', 'z')->get());
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

        // Excludes only 25, keeps 30 and null (null can't be compared)
        $this->assertSame(['Bob', 'Charlie'], $this->names($results));
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
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($results));
    }

    /**
     * @test
     */
    public function searchWithEmptyFieldsArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('Alice', [])
            ->get();

        // Empty fields array = search all fields
        $this->assertSame(['Alice'], $this->names($results));
        // Every field: "IT" only appears in Charlie's department
        $this->assertSame(['Charlie'], $this->names($this->noneDB->query($this->testDbName)->search('it', [])->get()));
    }

    /**
     * @test
     */
    public function searchWithNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('Alice', ['nonexistent'])
            ->get();

        $this->assertSame([], $results);
    }

    /**
     * @test
     */
    public function searchCaseInsensitive(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->search('ALICE')
            ->get();

        $this->assertSame(['Alice'], $this->names($results));
        $this->assertSame(['Alice'], $this->names($this->noneDB->query($this->testDbName)->search('aLiCe')->get()));
    }

    /**
     * Only scalar values are searched: the array "tags" (["php","mysql"]) isn't, booleans aren't,
     * numbers are (as text); without a field list "key" isn't searched either
     * @test
     */
    public function searchOnlyMatchesScalarNonBooleanValues(): void
    {
        $this->assertSame([], $this->noneDB->query($this->testDbName)->search('php')->get());
        $this->assertSame([], $this->noneDB->query($this->testDbName)->search('1', ['active'])->get());
        $this->assertSame(['Bob'], $this->names($this->noneDB->query($this->testDbName)->search('30')->get()));
        // "0" is in Bob's age (30) only: Alice's key 0 is not content
        $this->assertSame(['Bob'], $this->names($this->noneDB->query($this->testDbName)->search('0')->get()));
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
        $this->assertSame(3, $results[0]['_count']);
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($results[0]['_items']));
    }

    /**
     * Records without the field are grouped under null
     * @test
     */
    public function groupByFieldWithNullValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('department')
            ->get();

        // One group for 'IT', one for null (Alice and Bob have no department)
        $this->assertCount(2, $results);
        $this->assertSame([
            "v'IT'" => ['count' => 1, 'names' => ['Charlie']],
            'vNULL' => ['count' => 2, 'names' => ['Alice', 'Bob']],
        ], $this->groupsByValue($results));
    }

    /**
     * A field holding null and a missing field fall into the same null group; the group value keeps
     * its type (true/false are groups of their own)
     * @test
     */
    public function groupByKeepsValueTypes(): void
    {
        $this->assertSame([
            'v25' => ['count' => 1, 'names' => ['Alice']],
            'v30' => ['count' => 1, 'names' => ['Bob']],
            'vNULL' => ['count' => 1, 'names' => ['Charlie']],
        ], $this->groupsByValue($this->noneDB->query($this->testDbName)->groupBy('age')->get()));
        $this->assertSame([
            'vfalse' => ['count' => 1, 'names' => ['Bob']],
            'vtrue' => ['count' => 2, 'names' => ['Alice', 'Charlie']],
        ], $this->groupsByValue($this->noneDB->query($this->testDbName)->groupBy('active')->get()));
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
        $this->assertNull($results[0]['_group']);
        $this->assertSame(3, $results[0]['_count']);
    }

    // ==========================================
    // HAVING EDGE CASES
    // groupBy('active'): true => Alice, Charlie; false => Bob
    // ==========================================

    private function activeGroups(): array
    {
        return [
            'vfalse' => ['count' => 1, 'names' => ['Bob']],
            'vtrue' => ['count' => 2, 'names' => ['Alice', 'Charlie']],
        ];
    }

    /**
     * @test
     */
    public function havingWithInvalidAggregate(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('active')
            ->having('invalid', '>', 0)
            ->get();

        // Invalid aggregate is ignored: every group stays
        $this->assertSame($this->activeGroups(), $this->groupsByValue($results));
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

        // Invalid operator is ignored: every group stays
        $this->assertSame($this->activeGroups(), $this->groupsByValue($results));
        // A valid condition next to it still applies
        $this->assertSame(['vtrue' => ['count' => 2, 'names' => ['Alice', 'Charlie']]], $this->groupsByValue(
            $this->noneDB->query($this->testDbName)->groupBy('active')->having('count', '==', 0)->having('count', '>', 1)->get()));
    }

    /**
     * sum:name of non-numeric values is the sum of no values, 0 (as the builder's sum() of a field
     * without numbers): "> 0" keeps no group, "= 0" keeps both
     * @test
     */
    public function havingWithNonNumericField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->groupBy('active')
            ->having('sum:name', '>', 0)
            ->get();

        $this->assertSame([], $results);
        $this->assertSame($this->activeGroups(), $this->groupsByValue(
            $this->noneDB->query($this->testDbName)->groupBy('active')->having('sum:name', '=', 0)->get()));
    }

    /**
     * having() only applies to groups: without groupBy() it is ignored
     * @test
     */
    public function havingWithoutGroupByIsIgnored(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->having('count', '>', 100)
            ->get();

        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($results));
        $this->assertSame(3, $this->noneDB->query($this->testDbName)->having('count', '>', 100)->count());
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

        // key is always kept, records stay in key order
        $this->assertCount(3, $results);
        $this->assertSame([0, 1, 2], array_column($results, 'key'));
    }

    /**
     * @test
     */
    public function selectWithNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->select(['nonexistent', 'name'])
            ->get();

        // Only the selected fields that exist, plus key
        $this->assertCount(3, $results);
        foreach (['Alice', 'Bob', 'Charlie'] as $i => $name) {
            $this->assertRecord(['name' => $name, 'key' => $i], $results[$i]);
        }
    }

    /**
     * select() keeps key even when it isn't listed, and a selected null value is kept
     * @test
     */
    public function selectKeepsKeyAndNullValues(): void
    {
        $results = $this->noneDB->query($this->testDbName)->select(['age', 'tags'])->get();

        $this->assertRecord(['age' => 25, 'tags' => ['php', 'mysql'], 'key' => 0], $results[0]);
        $this->assertRecord(['age' => 30, 'tags' => null, 'key' => 1], $results[1]);
        $this->assertRecord(['age' => null, 'key' => 2], $results[2]);
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
        $this->assertCount(3, $results);
        foreach ($results as $i => $record) {
            $this->assertRecord($this->storedRecord($i), $record);
        }
    }

    /**
     * @test
     */
    public function exceptWithNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->except(['nonexistent'])
            ->get();

        // Should work normally: every record complete
        $this->assertCount(3, $results);
        foreach ($results as $i => $record) {
            $this->assertRecord($this->storedRecord($i), $record);
        }
    }

    /**
     * except() drops the listed fields but never key; the stored records keep them
     * @test
     */
    public function exceptDropsTheListedFieldsKeyToo(): void
    {
        // QUERY.md select() / except(): except() removes key when it is listed
        $results = $this->noneDB->query($this->testDbName)->except(['tags', 'score', 'key', 'department'])->get();

        $this->assertCount(3, $results);
        $this->assertRecord(['name' => 'Alice', 'age' => 25, 'active' => true], $results[0]);
        $this->assertRecord(['name' => 'Bob', 'age' => 30, 'active' => false], $results[1]);
        $this->assertRecord(['name' => 'Charlie', 'age' => null, 'active' => true], $results[2]);
        $this->assertUnchanged();
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

        // Select takes precedence; fields outside select() never appear
        $this->assertCount(3, $results);
        foreach ($results as $i => $record) {
            $this->assertSame($i, $record['key']);
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('age', $record);
            $this->assertArrayHasKey('score', $record);
            foreach (['active', 'tags', 'department'] as $field) {
                $this->assertArrayNotHasKey($field, $record);
            }
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

        // The field is missing everywhere: all values tie, a stable sort keeps key order
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($results));
    }

    /**
     * @test
     */
    public function sortWithNonExistentField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('nonexistent', 'asc')
            ->get();
        $desc = $this->noneDB->query($this->testDbName)
            ->sort('nonexistent', 'desc')
            ->get();

        // All ties: key order for asc and for desc (stable)
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($results));
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($desc));
    }

    /**
     * Anything but 'desc' sorts ascending (the default order)
     * @test
     */
    public function sortWithInvalidOrder(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('age', 'invalid')
            ->get();

        // Ascending by age: null (Charlie) < 25 (Alice) < 30 (Bob) - not key order
        $this->assertSame(['Charlie', 'Alice', 'Bob'], $this->names($results));
    }

    /**
     * null sorts first ("missing / null" < numbers), last with desc
     * @test
     */
    public function sortOnNullableField(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('age', 'asc')
            ->get();
        $desc = $this->noneDB->query($this->testDbName)
            ->sort('age', 'desc')
            ->get();

        $this->assertSame(['Charlie', 'Alice', 'Bob'], $this->names($results));
        $this->assertSame(['Bob', 'Alice', 'Charlie'], $this->names($desc));
    }

    /**
     * Total order: booleans (false < true) < numbers; arrays last; a field holding null and a missing
     * field are both in the first class
     * @test
     */
    public function sortAcrossTypesUsesTotalOrder(): void
    {
        $this->assertSame(['Bob', 'Alice', 'Charlie'], $this->names($this->noneDB->query($this->testDbName)->sort('active')->get()));
        // tags: Bob null, Charlie missing (tie, key order) < Alice's array
        $this->assertSame(['Bob', 'Charlie', 'Alice'], $this->names($this->noneDB->query($this->testDbName)->sort('tags')->get()));
        // score: 75 < 85.5 < 90.0 (int and float together)
        $this->assertSame(['Charlie', 'Alice', 'Bob'], $this->names($this->noneDB->query($this->testDbName)->sort('score')->get()));
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

        // 0 limit = no results (also for first(), exists() and count())
        $this->assertSame([], $results);
        $this->assertNull($this->noneDB->query($this->testDbName)->limit(0)->first());
        $this->assertFalse($this->noneDB->query($this->testDbName)->limit(0)->exists());
        $this->assertSame(0, $this->noneDB->query($this->testDbName)->limit(0)->count());
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
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($results));
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
        $this->assertSame([], $results);
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
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($results));
    }

    // ==========================================
    // AGGREGATION EDGE CASES
    // sum()/avg() of the builder return float; min()/max() use the sort order and skip null
    // ==========================================

    /**
     * @test
     */
    public function sumOnEmptyResult(): void
    {
        $sum = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->sum('age');

        $this->assertSame(0.0, $sum);
    }

    /**
     * @test
     */
    public function sumOnNonNumericField(): void
    {
        $sum = $this->noneDB->query($this->testDbName)
            ->sum('name');

        // Non-numeric values should be skipped
        $this->assertSame(0.0, $sum);
        // Booleans are not numbers either (true is not 1)
        $this->assertSame(0.0, $this->noneDB->query($this->testDbName)->sum('active'));
    }

    /**
     * @test
     */
    public function sumOnMixedField(): void
    {
        $sum = $this->noneDB->query($this->testDbName)
            ->sum('age');

        // null should be skipped, 25 + 30 = 55
        $this->assertSame(55.0, $sum);
        // int and float together: 85.5 + 90.0 + 75
        $this->assertSame(250.5, $this->noneDB->query($this->testDbName)->sum('score'));
    }

    /**
     * @test
     */
    public function avgOnEmptyResult(): void
    {
        $avg = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->avg('age');

        $this->assertSame(0.0, $avg);
    }

    /**
     * @test
     */
    public function avgWithNullValues(): void
    {
        $avg = $this->noneDB->query($this->testDbName)
            ->avg('age');

        // null should be skipped, (25 + 30) / 2 = 27.5
        $this->assertSame(27.5, $avg);
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
     * min()/max() of a field with a null value: the null is no value (as distinct() leaves it out),
     * the extremes are 25 and 30; the int/float field keeps the stored types
     * @test
     */
    public function minAndMaxOnNullableAndMixedNumberFields(): void
    {
        $this->assertSame(25, $this->noneDB->query($this->testDbName)->min('age'));
        $this->assertSame(30, $this->noneDB->query($this->testDbName)->max('age'));
        $this->assertSame(75, $this->noneDB->query($this->testDbName)->min('score'));
        $this->assertSame(90.0, $this->noneDB->query($this->testDbName)->max('score'));
        // Booleans: false < true
        $this->assertFalse($this->noneDB->query($this->testDbName)->min('active'));
        $this->assertTrue($this->noneDB->query($this->testDbName)->max('active'));
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

        $this->assertSame([], $distinct);
    }

    /**
     * @test
     */
    public function distinctOnNonExistentField(): void
    {
        $distinct = $this->noneDB->query($this->testDbName)
            ->distinct('nonexistent');

        $this->assertSame([], $distinct);
    }

    /**
     * @test
     */
    public function distinctWithNullValues(): void
    {
        $distinct = $this->noneDB->query($this->testDbName)
            ->distinct('age');

        // 25, 30 in order of first appearance; null is left out
        $this->assertSame([25, 30], $distinct);
        // Booleans are values; arrays too (null of Bob's tags left out)
        $this->assertSame([true, false], $this->noneDB->query($this->testDbName)->distinct('active'));
        $this->assertSame([['php', 'mysql']], $this->noneDB->query($this->testDbName)->distinct('tags'));
    }

    // ==========================================
    // UPDATE EDGE CASES
    // ==========================================

    /**
     * An empty set changes nothing and returns the number of matching records (API.md "update()")
     * @test
     */
    public function updateWithEmptySet(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Alice'])
            ->update([]);

        $this->assertSame(1, $result['n']);
        $this->assertUnchanged();
    }

    /**
     * @test
     */
    public function updateOnEmptyResult(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->update(['age' => 100]);

        $this->assertSame(0, $result['n']);
        $this->assertUnchanged();
    }

    /**
     * @test
     */
    public function updateWithNullValue(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Alice'])
            ->update(['age' => null]);

        $this->assertSame(1, $result['n']);

        $all = $this->noneDB->find($this->testDbName, 0);
        // The field stays, with null; the other records are untouched
        $this->assertRecord(['age' => null] + $this->storedRecord(0), $all[0]);
        $this->assertRecord($this->storedRecord(1), $all[1]);
        $this->assertRecord($this->storedRecord(2), $all[2]);
        $this->assertSame(['Alice', 'Charlie'], $this->names($this->noneDB->query($this->testDbName)->where(['age' => null])->get()));
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

        $this->assertSame(0, $result['n']);
        $this->assertUnchanged();
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

        $this->assertSame(0, $result['n']);
        $this->assertSame([], $result['fields_removed']);
        $this->assertIsString($result['error']);
        $this->assertNotSame('', $result['error']);
        $this->assertUnchanged();
    }

    /**
     * @test
     */
    public function removeFieldsWithOnlyKey(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['key']);

        $this->assertSame(0, $result['n']);
        $this->assertSame([], $result['fields_removed']);
        $this->assertIsString($result['error']);
        $this->assertNotSame('', $result['error']);
        $this->assertUnchanged();
    }

    /**
     * "key" next to other fields is skipped, the others are removed
     * @test
     */
    public function removeFieldsSkipsKeyNextToOtherFields(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Bob'])
            ->removeFields(['key', 'tags']);

        $this->assertSame(1, $result['n']);
        $this->assertSame(['tags'], $result['fields_removed']);
        $bob = $this->noneDB->find($this->testDbName, ['key' => 1]);
        $expected = $this->storedRecord(1);
        unset($expected['tags']);
        $this->assertRecord($expected, $bob[0]);
    }

    /**
     * fields_removed lists the fields actually removed (QUERY.md "removeFields()": 'tmp' isn't listed in
     * the example); with no matching record nothing is removed
     * @test
     */
    public function removeFieldsOnEmptyResult(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->removeFields(['age']);

        $this->assertSame(0, $result['n']);
        $this->assertUnchanged();
        $this->assertSame([], $result['fields_removed']);
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
        $this->assertSame(0, $result['n']);
        $this->assertSame([], $result['fields_removed']);
        $this->assertUnchanged();
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

        $this->assertSame(0, $count);
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
        // A numeric field name is a string like any other: no record has field "123"
        $results = $this->noneDB->query($this->testDbName)
            ->whereIn('123', ['a', 'b'])
            ->get();

        $this->assertSame([], $results);
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
        try {
            $this->noneDB->query($this->testDbName)->update('invalid');
        } finally {
            $this->assertUnchanged();
        }
    }

    /**
     * @test
     */
    public function removeFieldsThrowsTypeErrorWithNonArray(): void
    {
        $this->expectException(TypeError::class);
        try {
            $this->noneDB->query($this->testDbName)->removeFields('invalid');
        } finally {
            $this->assertUnchanged();
        }
    }

    /**
     * A filter value mixing operators and plain keys throws InvalidArgumentException as soon as where()
     * is called (QUERY.md "How values compare")
     * @test
     */
    public function whereMixingOperatorsAndPlainKeysThrowsAtCall(): void
    {
        $query = $this->noneDB->query($this->testDbName);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot mix operators');
        $query->where(['age' => ['$gt' => 1, 'plain' => 2]]);
    }
}
