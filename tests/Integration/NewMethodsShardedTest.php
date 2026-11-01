<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Tests for new chaining methods on sharded databases
 * Ensures all new methods work correctly with auto-sharding enabled
 *
 * The 12 records (key i = User{i}) are spread over 3 shards of 5 (keys 0-4, 5-9, 10-11). Expected values
 * are derived by hand or by an independent reference model (plain PHP over the inserted records, written
 * from the rules in docs/QUERY.md: === for plain values and $in, numeric comparison for ranges, key order
 * without sort(), stable sort with ties in key order also for desc).
 */
class NewMethodsShardedTest extends noneDBTestCase
{
    private string $shardedDbName = 'sharded-test-db';

    private const CITIES = ['Istanbul', 'Ankara', 'Izmir', 'Bursa'];
    private const DEPARTMENTS = ['IT', 'HR', 'Sales', 'Marketing'];

    protected function setUp(): void
    {
        parent::setUp();

        // Configure for small shards to test sharding behavior
        $this->setPrivateProperty('shardSize', 5);
        $this->setPrivateProperty('shardingEnabled', true);

        // Insert enough data to trigger sharding (more than shardSize)
        $this->noneDB->insert($this->shardedDbName, array_values($this->records()));
    }

    /**
     * The inserted records, key => record (without key)
     */
    private function records(): array
    {
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $data[$i] = [
                'name' => 'User' . $i,
                'age' => 20 + $i,
                'city' => self::CITIES[$i % 4],
                'department' => self::DEPARTMENTS[$i % 4],
                'score' => 70 + ($i * 2),
                'active' => ($i % 3 !== 0)
            ];
        }
        return $data;
    }

    /**
     * Stored record as get() returns it, field order normalized
     */
    private function record(int $key, ?array $only = null): array
    {
        $record = $this->records()[$key];
        if ($only !== null) {
            $record = array_intersect_key($record, array_flip($only));
        }
        $record['key'] = $key;
        ksort($record);
        return $record;
    }

    private function keys(array $results): array
    {
        return array_map(function ($r) { return $r['key']; }, $results);
    }

    private function names(array $results): array
    {
        return array_map(function ($r) { return $r['name']; }, $results);
    }

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

    /**
     * @test
     */
    public function databaseIsSharded(): void
    {
        // Verify data was inserted correctly
        $this->assertSame(12, $this->noneDB->count($this->shardedDbName));

        // 12 keys / 5 per shard = shards 0 (0-4), 1 (5-9), 2 (10-11)
        $this->assertSame([
            'sharded' => true,
            'shards' => 3,
            'totalRecords' => 12,
            'deletedCount' => 0,
            'shardSize' => 5,
            'nextKey' => 12,
        ], $this->noneDB->getShardInfo($this->shardedDbName));

        // All records are readable, in key order across the shards
        $all = $this->noneDB->query($this->shardedDbName)->get();
        $expected = [];
        foreach (array_keys($this->records()) as $key) {
            $expected[] = $this->record($key);
        }
        $this->assertSame($expected, self::canon($all));
    }

    /**
     * @test
     */
    public function whereInOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->get();

        // Istanbul (i % 4 == 0) and Ankara (i % 4 == 1), in key order across the shards
        $this->assertSame([0, 1, 4, 5, 8, 9], $this->keys($results));
        $this->assertSame([$this->record(0), $this->record(1), $this->record(4), $this->record(5), $this->record(8), $this->record(9)], self::canon($results));
    }

    /**
     * @test
     */
    public function whereNotInOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->whereNotIn('city', ['Istanbul', 'Ankara'])
            ->get();

        // Izmir and Bursa each appear 3 times
        $this->assertSame([2, 3, 6, 7, 10, 11], $this->keys($results));
    }

    /**
     * @test
     */
    public function orWhereOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->where(['city' => 'Istanbul'])
            ->orWhere(['city' => 'Izmir'])
            ->get();

        $this->assertSame([0, 2, 4, 6, 8, 10], $this->keys($results));
    }

    /**
     * @test
     */
    public function whereNotOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->whereNot(['active' => false])
            ->get();

        // 8 active users (indices 1,2,4,5,7,8,10,11)
        $this->assertSame([1, 2, 4, 5, 7, 8, 10, 11], $this->keys($results));

        // whereNot with operators: excluded when all operators of the field match
        $ops = $this->noneDB->query($this->shardedDbName)->whereNot(['age' => ['$gte' => 23, '$lte' => 27]])->get();
        $this->assertSame([0, 1, 2, 8, 9, 10, 11], $this->keys($ops));
    }

    /**
     * @test
     */
    public function notLikeOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->notLike('name', 'User1')
            ->get();

        // Excludes User1, User10, User11 = 9 remaining
        $this->assertSame([0, 2, 3, 4, 5, 6, 7, 8, 9], $this->keys($results));
        $this->assertSame([1, 10, 11], $this->keys($this->noneDB->query($this->shardedDbName)->like('name', 'user1')->get()));
    }

    /**
     * @test
     */
    public function notBetweenOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->notBetween('age', 22, 28)
            ->get();

        // Ages: 20,21 (below) + 29,30,31 (above) = 5
        $this->assertSame([0, 1, 9, 10, 11], $this->keys($results));
        $this->assertSame([2, 3, 4, 5, 6, 7, 8], $this->keys($this->noneDB->query($this->shardedDbName)->between('age', 22, 28)->get()));
    }

    /**
     * @test
     */
    public function selectOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->select(['name', 'city'])
            ->get();

        $expected = [];
        foreach (array_keys($this->records()) as $key) {
            $expected[] = $this->record($key, ['name', 'city']); // key always included
        }
        $this->assertSame($expected, self::canon($results));
    }

    /**
     * @test
     */
    public function exceptOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->except(['score', 'active'])
            ->get();

        $expected = [];
        foreach (array_keys($this->records()) as $key) {
            $expected[] = $this->record($key, ['name', 'age', 'city', 'department']);
        }
        $this->assertSame($expected, self::canon($results));
    }

    /**
     * @test
     */
    public function groupByOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->groupBy('city')
            ->get();

        $this->assertCount(4, $results);

        $byCity = [];
        foreach ($results as $group) {
            $this->assertSame(['_group', '_items', '_count'], array_keys($group));
            $byCity[$group['_group']] = [$group['_count'], $this->keys($group['_items'])];
        }
        ksort($byCity);
        // Each city has 3 users, items in key order across the shards
        $this->assertSame([
            'Ankara' => [3, [1, 5, 9]],
            'Bursa' => [3, [3, 7, 11]],
            'Istanbul' => [3, [0, 4, 8]],
            'Izmir' => [3, [2, 6, 10]],
        ], $byCity);
    }

    /**
     * @test
     */
    public function havingOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->groupBy('department')
            ->having('avg:score', '>', 80)
            ->get();

        // avg score: IT (70,78,86) 78; HR (72,80,88) 80; Sales (74,82,90) 82; Marketing (76,84,92) 84
        $groups = array_column($results, '_group');
        sort($groups);
        $this->assertSame(['Marketing', 'Sales'], $groups);

        // >= 80 adds HR (exactly 80)
        $ge = array_column($this->noneDB->query($this->shardedDbName)->groupBy('department')->having('avg:score', '>=', 80)->get(), '_group');
        sort($ge);
        $this->assertSame(['HR', 'Marketing', 'Sales'], $ge);
    }

    /**
     * @test
     */
    public function searchOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->search('istanbul')
            ->get();

        $this->assertSame([0, 4, 8], $this->keys($results));
    }

    /**
     * @test
     */
    public function joinOnShardedDb(): void
    {
        // Create a small non-sharded lookup table
        $this->noneDB->insert('city-info', [
            ['name' => 'Istanbul', 'country' => 'Turkey', 'population' => 15000000],
            ['name' => 'Ankara', 'country' => 'Turkey', 'population' => 5500000],
        ]);

        $results = $this->noneDB->query($this->shardedDbName)
            ->join('city-info', 'city', 'name')
            ->get();

        $this->assertSame(range(0, 11), $this->keys($results));

        $info = [
            'Istanbul' => ['country' => 'Turkey', 'key' => 0, 'name' => 'Istanbul', 'population' => 15000000],
            'Ankara' => ['country' => 'Turkey', 'key' => 1, 'name' => 'Ankara', 'population' => 5500000],
        ];
        foreach ($results as $record) {
            // Izmir and Bursa have no city-info record: null
            $this->assertSame($info[$record['city']] ?? null, self::canon($record['city-info']), $record['name']);
        }
    }

    /**
     * A sharded database joined on its record keys from another database (keys in every shard)
     *
     * @test
     */
    public function joinOnKeyOfShardedDb(): void
    {
        $this->noneDB->insert('city-info', [
            ['name' => 'a', 'user' => 11],
            ['name' => 'b', 'user' => '3'],
            ['name' => 'c', 'user' => 7.0],
            ['name' => 'd', 'user' => 99],
        ]);

        $results = $this->noneDB->query('city-info')->join($this->shardedDbName, 'user', 'key', 'u')->get();

        // 11, "3" and 7.0 join keys 11, 3 and 7 (QUERY.md join: integers, integral floats and canonical
        // integer strings match each other); 99 doesn't exist
        $this->assertSame([11, 3, 7, null], array_map(function ($r) { return $r['u']['key'] ?? null; }, $results));
        $this->assertSame($this->record(3), self::canon($results[1]['u']));
    }

    /**
     * @test
     */
    public function complexQueryOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->whereNot(['active' => false])
            ->between('age', 21, 28)
            ->sort('score', 'desc')
            ->limit(3)
            ->select(['name', 'city', 'score'])
            ->get();

        // whereIn: 0,1,4,5,8,9; active drops 0 and 9; age 21..28: 1(21),4(24),5(25),8(28);
        // score desc: 8 (86), 5 (80), 4 (78), 1 (72); limit 3
        $this->assertSame([
            $this->record(8, ['name', 'city', 'score']),
            $this->record(5, ['name', 'city', 'score']),
            $this->record(4, ['name', 'city', 'score']),
        ], self::canon($results));
    }

    /**
     * @test
     */
    public function updateOnShardedDbWithNewFilters(): void
    {
        $result = $this->noneDB->query($this->shardedDbName)
            ->whereIn('city', ['Istanbul'])
            ->update(['region' => 'Marmara']);

        $this->assertSame(['n' => 3], $result);

        // Verify update: exactly the Istanbul records (one per shard) got the field, nothing else changed
        $updated = $this->noneDB->find($this->shardedDbName, ['region' => 'Marmara']);
        $expected = [];
        foreach ([0, 4, 8] as $key) {
            $row = $this->record($key) + ['region' => 'Marmara'];
            ksort($row);
            $expected[] = $row;
        }
        $this->assertSame($expected, self::canon($updated));
        foreach ($this->noneDB->find($this->shardedDbName, 0) as $record) {
            if (!in_array($record['key'], [0, 4, 8], true)) {
                $this->assertSame($this->record($record['key']), self::canon($record));
            }
        }
    }

    /**
     * @test
     */
    public function deleteOnShardedDbWithNewFilters(): void
    {
        $result = $this->noneDB->query($this->shardedDbName)
            ->whereNot(['active' => true])
            ->delete();

        $this->assertSame(['n' => 4], $result); // Users 0,3,6,9 are inactive

        // Verify remaining records
        $this->assertSame(8, $this->noneDB->count($this->shardedDbName));
        $this->assertSame([1, 2, 4, 5, 7, 8, 10, 11], $this->keys($this->noneDB->find($this->shardedDbName, 0)));
    }

    /**
     * QUERY.md update(): sort(), offset() and limit() choose which records are written
     *
     * @test
     */
    public function updateWithSortAndLimitChoosesRecordsAcrossShards(): void
    {
        // The 3 highest scores are 11 (92), 10 (90), 9 (88); skip the first one
        $result = $this->noneDB->query($this->shardedDbName)->sort('score', 'desc')->offset(1)->limit(2)->update(['top' => true]);
        $this->assertSame(['n' => 2], $result);
        $this->assertSame([9, 10], $this->keys($this->noneDB->find($this->shardedDbName, ['top' => true])));

        $deleted = $this->noneDB->query($this->shardedDbName)->sort('age')->limit(3)->delete();
        $this->assertSame(['n' => 3], $deleted);
        $this->assertSame(range(3, 11), $this->keys($this->noneDB->find($this->shardedDbName, 0)));
    }

    /**
     * @test
     */
    public function terminalMethodsOnShardedDb(): void
    {
        // first
        $first = $this->noneDB->query($this->shardedDbName)
            ->whereIn('city', ['Izmir'])
            ->sort('age')
            ->first();
        $this->assertSame($this->record(2), self::canon($first)); // Izmir: 2 (22), 6 (26), 10 (30)

        // last
        $last = $this->noneDB->query($this->shardedDbName)->whereIn('city', ['Izmir'])->sort('age')->last();
        $this->assertSame($this->record(10), self::canon($last));

        // count
        $count = $this->noneDB->query($this->shardedDbName)
            ->whereNotIn('department', ['IT'])
            ->count();
        $this->assertSame(9, $count);

        // exists
        $this->assertTrue($this->noneDB->query($this->shardedDbName)->search('User5')->exists());
        $this->assertFalse($this->noneDB->query($this->shardedDbName)->search('User99')->exists());

        // sum (float, QUERY.md: the builder's sum() returns a float): 70 + 78 + 86
        $sum = $this->noneDB->query($this->shardedDbName)
            ->whereIn('city', ['Istanbul'])
            ->sum('score');
        $this->assertSame(234.0, $sum);
        $this->assertSame(78.0, $this->noneDB->query($this->shardedDbName)->whereIn('city', ['Istanbul'])->avg('score'));
        $this->assertSame(70, $this->noneDB->query($this->shardedDbName)->min('score'));
        $this->assertSame(92, $this->noneDB->query($this->shardedDbName)->max('score'));

        // distinct, in order of first appearance
        $cities = $this->noneDB->query($this->shardedDbName)
            ->distinct('city');
        $this->assertSame(['Istanbul', 'Ankara', 'Izmir', 'Bursa'], $cities);
    }

    /**
     * QUERY.md: limit() and offset() apply to count(); limit(0) returns nothing
     *
     * @test
     */
    public function countWithLimitOffsetAndLimitZeroOnShardedDb(): void
    {
        $q = function () { return $this->noneDB->query($this->shardedDbName)->whereIn('city', ['Istanbul', 'Ankara']); }; // 6 matches
        $this->assertSame(6, $q()->count());
        $this->assertSame(4, $q()->offset(2)->count());
        $this->assertSame(3, $q()->limit(3)->count());
        $this->assertSame(1, $q()->offset(5)->limit(3)->count());
        $this->assertSame(0, $q()->offset(6)->count());
        $this->assertSame(8, $this->noneDB->query($this->shardedDbName)->offset(4)->count());

        $this->assertSame([], $q()->limit(0)->get());
        $this->assertSame(0, $q()->limit(0)->count());
        $this->assertNull($q()->limit(0)->first());
        $this->assertFalse($q()->limit(0)->exists());
        $this->assertSame([4, 5], $this->keys($q()->offset(2)->limit(2)->get()));
    }

    /**
     * QUERY.md sort(): stable, reversed for desc; ties keep key order (also across shards)
     *
     * @test
     */
    public function sortTiesKeepKeyOrderAcrossShards(): void
    {
        $asc = $this->noneDB->query($this->shardedDbName)->sort('city')->get();
        $this->assertSame([1, 5, 9, 3, 7, 11, 0, 4, 8, 2, 6, 10], $this->keys($asc));

        $desc = $this->noneDB->query($this->shardedDbName)->sort('city', 'desc')->get();
        $this->assertSame([2, 6, 10, 0, 4, 8, 3, 7, 11, 1, 5, 9], $this->keys($desc));

        // Top-k (offset + limit <= 1000) gives the same rows as the full sort
        $this->assertSame([0, 4, 8, 3], $this->keys($this->noneDB->query($this->shardedDbName)->sort('city', 'desc')->offset(3)->limit(4)->get()));

        // A second sort key breaks ties: city asc, then age desc
        $two = $this->noneDB->query($this->shardedDbName)->sort('city')->sort('age', 'desc')->get();
        $this->assertSame([9, 5, 1, 11, 7, 3, 8, 4, 0, 10, 6, 2], $this->keys($two));
    }

    /**
     * @test
     */
    public function paginationOnShardedDb(): void
    {
        $pageSize = 4;

        $page1 = $this->noneDB->query($this->shardedDbName)
            ->sort('name')
            ->limit($pageSize)
            ->skip(0)
            ->get();

        $page2 = $this->noneDB->query($this->shardedDbName)
            ->sort('name')
            ->limit($pageSize)
            ->skip($pageSize)
            ->get();

        $page3 = $this->noneDB->query($this->shardedDbName)
            ->sort('name')
            ->limit($pageSize)
            ->skip($pageSize * 2)
            ->get();

        // Names are non-numeric strings: byte order ("User10" < "User2")
        $this->assertSame(['User0', 'User1', 'User10', 'User11'], $this->names($page1));
        $this->assertSame(['User2', 'User3', 'User4', 'User5'], $this->names($page2));
        $this->assertSame(['User6', 'User7', 'User8', 'User9'], $this->names($page3));
    }

    /**
     * Results on the sharded database equal an independent reference model, with and without field indexes
     * (QUERY.md: an index only decides what is read, never what matches)
     *
     * @test
     */
    public function queriesMatchReferenceModelWithAndWithoutFieldIndexes(): void
    {
        $records = $this->records();
        $all = function (callable $keep) use ($records): array {
            $keys = [];
            foreach ($records as $key => $r) {
                if ($keep($r)) {
                    $keys[] = $key;
                }
            }
            return $keys;
        };
        $sorted = function (array $keys, string $field, bool $desc) use ($records): array {
            usort($keys, function ($a, $b) use ($records, $field, $desc) {
                $c = $records[$a][$field] <=> $records[$b][$field]; // all ints or non-numeric strings here
                if ($desc) {
                    $c = -$c;
                }
                return $c !== 0 ? $c : $a <=> $b; // ties: key order
            });
            return $keys;
        };

        $cases = [
            'eq' => [function ($q) { return $q->where(['city' => 'Izmir']); }, $all(function ($r) { return $r['city'] === 'Izmir'; })],
            'eq-type' => [function ($q) { return $q->where(['age' => '25']); }, []],
            'range' => [function ($q) { return $q->where(['age' => ['$gte' => 23, '$lt' => 29]]); }, $all(function ($r) { return $r['age'] >= 23 && $r['age'] < 29; })],
            'range-numeric-string' => [function ($q) { return $q->where(['age' => ['$gt' => '29']]); }, $all(function ($r) { return $r['age'] > 29; })],
            'in' => [function ($q) { return $q->where(['score' => ['$in' => [72, 90, 999, '80']]]); }, $all(function ($r) { return in_array($r['score'], [72, 90, 999], true); })],
            'whereIn' => [function ($q) { return $q->whereIn('department', ['HR', 'Sales']); }, $all(function ($r) { return in_array($r['department'], ['HR', 'Sales'], true); })],
            'or' => [function ($q) { return $q->where(['city' => 'Bursa'])->orWhere(['age' => ['$lte' => 21]]); }, $all(function ($r) { return $r['city'] === 'Bursa' || $r['age'] <= 21; })],
            'and-groups' => [function ($q) { return $q->where(['age' => ['$gte' => 22]])->where(['age' => ['$lte' => 26]])->where(['active' => true]); }, $all(function ($r) { return $r['age'] >= 22 && $r['age'] <= 26 && $r['active'] === true; })],
            'between' => [function ($q) { return $q->between('score', 76, 84); }, $all(function ($r) { return $r['score'] >= 76 && $r['score'] <= 84; })],
            'ne' => [function ($q) { return $q->where(['city' => ['$ne' => 'Istanbul'], 'department' => ['$nin' => ['HR']]]); }, $all(function ($r) { return $r['city'] !== 'Istanbul' && $r['department'] !== 'HR'; })],
            'whereNot' => [function ($q) { return $q->whereNot(['department' => 'IT'])->where(['active' => true]); }, $all(function ($r) { return $r['department'] !== 'IT' && $r['active'] === true; })],
            // Ascending list: the order of a key list vs. key order for sort ties isn't specified for the
            // builder; "05" and 99 are no keys (QUERY.md key filters: canonical digit strings only)
            'key' => [function ($q) { return $q->where(['key' => [3, '7', 11, 99, '05']]); }, [3, 7, 11]],
            'key-and-field' => [function ($q) { return $q->where(['key' => [0, 1, 2, 3, 4, 5, 6]])->where(['city' => 'Istanbul']); }, [0, 4]],
        ];

        foreach ([false, true] as $indexed) {
            if ($indexed) {
                foreach (['city', 'age', 'department', 'score', 'active'] as $field) {
                    $created = $this->noneDB->createFieldIndex($this->shardedDbName, $field);
                    $this->assertTrue($created['success'], $field);
                    $this->assertSame(12, $created['indexed'], $field);
                }
            }
            foreach ($cases as $name => [$build, $expected]) {
                $label = $name . ($indexed ? ' (indexed)' : '');
                $this->assertSame($expected, $this->keys($build($this->noneDB->query($this->shardedDbName))->get()), $label);
                $this->assertSame(count($expected), $build($this->noneDB->query($this->shardedDbName))->count(), $label . ' count');
                $this->assertSame($expected !== [], $build($this->noneDB->query($this->shardedDbName))->exists(), $label . ' exists');
                $first = $build($this->noneDB->query($this->shardedDbName))->first();
                $this->assertSame($expected[0] ?? null, $first['key'] ?? null, $label . ' first');

                // Sorted by score desc, top-k (limit 2) and full
                $byScore = $sorted($expected, 'score', true);
                $this->assertSame($byScore, $this->keys($build($this->noneDB->query($this->shardedDbName))->sort('score', 'desc')->get()), $label . ' sort');
                $this->assertSame(array_slice($byScore, 1, 2), $this->keys($build($this->noneDB->query($this->shardedDbName))->sort('score', 'desc')->offset(1)->limit(2)->get()), $label . ' top-k');
                // Ties: city asc keeps key order
                $byCity = $sorted($expected, 'city', false);
                $this->assertSame($byCity, $this->keys($build($this->noneDB->query($this->shardedDbName))->sort('city')->get()), $label . ' sort ties');
            }
        }
    }
}
