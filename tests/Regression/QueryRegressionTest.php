<?php

namespace noneDB\Tests\Regression;

/**
 * CRUD and query builder: K-04, Y-15..Y-22, Y-26, Y-27, O-01..O-14, O-29, O-31..O-33, P-06, P-07, D-05, D-06
 */
class QueryRegressionTest extends RegressionTestCase
{
    private function seed(\noneDB $db, string $name = 'q'): void
    {
        $db->insert($name, [
            ['name' => 'Alice', 'age' => 25, 'city' => 'Istanbul', 'score' => 85, 'tags' => ['php', 'js']],
            ['name' => 'Bob', 'age' => '30', 'city' => 'Ankara', 'score' => 90],
            ['name' => 'Charlie', 'age' => 35, 'city' => 'Istanbul', 'score' => null],
            ['name' => 'abc', 'age' => 'abc', 'city' => 'Izmir'],
            ['name' => 'AYDOĞDU', 'age' => [1], 'city' => 'İzmir'],
        ]);
    }

    /** @test K-04 Y-26 P-06 */
    public function removeFieldsOnlyRemovesTheGivenFields(): void
    {
        $db = $this->db(['shardSize' => 5]);
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['name' => 'n' . $i, 'pw' => 'secret' . $i, 'tmp' => $i, 'loc' => ['type' => 'Point', 'coordinates' => [1, 1]]];
        }
        $db->insert('rf', $rows);
        $db->insert('dept', ['id' => 'x', 'title' => 'X']);
        $r = $db->query('rf')->select(['name', 'tmp'])->withDistance('loc', 1, 1)->join('dept', 'name', 'id')->removeFields(['tmp']);
        $this->assertSame(12, $r['n']);
        $this->assertSame(['tmp'], $r['fields_removed']);
        foreach ($db->find('rf', 0) as $record) {
            $this->assertArrayNotHasKey('tmp', $record);
            $this->assertArrayHasKey('pw', $record, 'select() must not leak into storage');
            $this->assertArrayNotHasKey('_distance', $record);
            $this->assertArrayNotHasKey('dept', $record);
        }
        $this->assertSame(12, $db->query('rf')->except(['key'])->removeFields(['name'])['n']);
        $this->assertArrayHasKey('pw', $db->find('rf', ['key' => 11])[0]);
    }

    /** @test Y-15 */
    public function keyFilterIsCombinedWithOtherFilters(): void
    {
        $db = $this->db();
        $this->seed($db);
        $this->assertSame([], $db->find('q', ['key' => 0, 'name' => 'zzz']));
        $this->assertSame(0, $db->delete('q', ['key' => 0, 'name' => 'zzz'])['n']);
        $this->assertSame(0, $db->update('q', [['key' => 0, 'name' => 'zzz'], ['set' => ['x' => 1]]])['n']);
        $this->assertSame('Bob', $db->find('q', ['name' => 'Bob', 'key' => '1'])[0]['name'], 'key filter in any position');
        $this->assertCount(5, $db->find('q', 0));
    }

    /** @test Y-17 */
    public function nullFiltersBehaveTheSameEverywhere(): void
    {
        foreach ([[], ['shardSize' => 2]] as $config) {
            $name = empty($config) ? 'nu' : 'nus';
            $db = $this->db($config);
            $this->seed($db, $name);
            $this->assertCount(1, $db->find($name, ['score' => null]));
            $this->assertSame(1, $db->update($name, [['score' => null], ['set' => ['flag' => 1]]])['n']);
            $this->assertSame(1, $db->delete($name, ['score' => null])['n']);
        }
    }

    /** @test Y-18 */
    public function malformedUpdatesAreRejected(): void
    {
        $db = $this->db();
        $this->seed($db);
        foreach ([[1 => ['set' => ['x' => 1]]], [['name' => 'Bob'], ['set' => 'str']], [['name' => 'Bob']], 'x', [['name' => 'Bob'], ['set' => ['key' => 5]]]] as $bad) {
            $r = $db->update('q', $bad);
            $this->assertSame(0, $r['n']);
            $this->assertSame('Please check your update parameters', $r['error']);
        }
        foreach ($db->find('q', 0) as $record) {
            $this->assertArrayNotHasKey('x', $record);
        }
        $this->assertSame(1, $db->update('q', [['name' => 'Bob'], ['set' => []]])['n'], 'empty set reports matches, writes nothing');
    }

    /** @test Y-19 */
    public function likePatternsAreLiteralAndSafe(): void
    {
        $db = $this->db();
        $db->insert('lk', [['url' => 'http://a.com'], ['url' => 'https://b.com'], ['url' => 'h1tp://c'], ['name' => 'john.doe'], ['name' => 'johnXdoe']]);
        $this->assertSame(['http://a.com'], array_column($db->like('lk', 'url', '^http://'), 'url'));
        $this->assertSame([], $db->like('lk', 'url', '^h.tp'));
        $this->assertSame(['john.doe'], array_column($db->like('lk', 'name', '^john.doe$'), 'name'));
        $this->assertSame(['http://a.com'], array_column($db->query('lk')->like('url', '^http://')->get(), 'url'));
        $this->assertSame(['http://a.com'], array_column($db->query('lk')->where(['url' => ['$like' => '^http://']])->get(), 'url'));
        $this->assertSame(['http://a.com', 'https://b.com'], array_column($db->query('lk')->where(['url' => ['$regex' => '^https?://[a-z]']])->get(), 'url'));
        $this->assertCount(2, $db->query('lk')->where(['url' => ['$regex' => 'a.com|b.com']])->get(), 'slashes inside $regex are fine');
        try {
            $db->query('lk')->where(['url' => ['$regex' => '(unclosed']])->get();
            $this->fail('invalid regex must be reported');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid', $e->getMessage());
        }
        try {
            $db->query('lk')->where(['url' => ['$like' => ['not', 'a', 'string']]])->get();
            $this->fail('non-string $like must be reported');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('like', $e->getMessage());
        }
    }

    /** @test Y-20 Y-21 */
    public function whereAndOrWhereLogic(): void
    {
        $db = $this->db();
        $this->seed($db);
        $this->assertSame(['Bob'], array_column($db->query('q')->orWhere(['name' => 'Bob'])->get(), 'name'));
        $this->assertSame(['Alice', 'Charlie'], array_column($db->query('q')->where(['age' => ['$gt' => 20]])->where(['age' => ['$lt' => 40]])->where(['city' => 'Istanbul'])->get(), 'name'));
        $this->assertSame(['Alice', 'Bob'], array_column($db->query('q')->where(['age' => ['$gt' => 20]])->where(['age' => ['$lt' => 31]])->get(), 'name'));
        $db->insert('num', [['100' => 'x'], ['100' => 'y']]);
        $this->assertCount(1, $db->query('num')->where(['100' => 'x'])->where(['100' => ['$ne' => 'z']])->get(), 'numeric field names');

        $db->insert('sp', [
            ['n' => 'a', 'loc' => ['type' => 'Point', 'coordinates' => [29, 41]]],
            ['n' => 'b', 'loc' => ['type' => 'Point', 'coordinates' => [29.001, 41]]],
            ['n' => 'far', 'loc' => ['type' => 'Point', 'coordinates' => [40, 41]]],
        ]);
        $with = $db->query('sp')->withinDistance('loc', 29, 41, 1000)->where(['n' => 'a'])->orWhere(['n' => 'b'])->get();
        $this->assertSame(['a', 'b'], array_column($with, 'n'), 'same logic with spatial filters');
    }

    /** @test Y-22 */
    public function sortIsATotalOrder(): void
    {
        $db = $this->db();
        $rows = [['v' => 5], ['x' => 1], ['v' => 3], ['v' => 9], ['v' => null], ['v' => 1], ['v' => 'b'], ['v' => 7]];
        $db->insert('so', $rows);
        $asc = array_map(function ($r) { return $r['v'] ?? '-'; }, $db->query('so')->sort('v')->get());
        $this->assertSame(['-', '-', 1, 3, 5, 7, 9, 'b'], $asc);
        $desc = array_map(function ($r) { return $r['v'] ?? '-'; }, $db->query('so')->sort('v', 'desc')->get());
        $this->assertSame(['b', 9, 7, 5, 3, 1, '-', '-'], $desc);
        $multi = $db->query('q2')->get();
        $db->insert('q2', [['c' => 'b', 'n' => 2], ['c' => 'a', 'n' => 3], ['c' => 'b', 'n' => 1]]);
        $this->assertSame([3, 1, 2], array_column($db->query('q2')->sort('c')->sort('n')->get(), 'n'), 'second sort() breaks ties');
    }

    /** @test Y-22: query sort, the sort() helper and an independent reference agree on mixed values */
    public function sortOrderMatchesReferenceOnMixedTypes(): void
    {
        $db = $this->db();
        mt_srand(77);
        $pool = [null, true, false, 0, 1, -1, 2.5, -0.5, 10, '10', '9', '1e1', 'abc', 'ABC', 'b', '', ['x'], ['a' => 1], [1, 2], 3.0];
        $rows = [];
        for ($i = 0; $i < 120; $i++) {
            $row = ['i' => $i, 'g' => mt_rand(0, 2)];
            if (mt_rand(0, 9) > 0) {
                $row['v'] = $pool[mt_rand(0, count($pool) - 1)];
            }
            $rows[] = $row;
        }
        $db->insert('mix', $rows);

        // Reference: missing/null < booleans < numbers < strings < arrays; same kind with <=> (arrays by JSON); ties by insertion order
        $kind = function ($v) {
            if ($v === null) return 0;
            if (is_bool($v)) return 1;
            if (is_int($v) || is_float($v)) return 2;
            if (is_string($v)) return 3;
            return 4;
        };
        $cmp = function ($a, $b) use ($kind) {
            $ka = $kind($a);
            $kb = $kind($b);
            if ($ka !== $kb) return $ka <=> $kb;
            return $ka === 4 ? strcmp(json_encode($a), json_encode($b)) <=> 0 : $a <=> $b;
        };
        $reference = function (array $keys) use ($rows, $cmp) {
            $sorted = $rows;
            usort($sorted, function ($x, $y) use ($keys, $cmp) {
                foreach ($keys as [$field, $order]) {
                    $c = $cmp($x[$field] ?? null, $y[$field] ?? null);
                    if ($c !== 0) return $order === 'desc' ? -$c : $c;
                }
                return $x['i'] <=> $y['i'];
            });
            return array_column($sorted, 'i');
        };

        foreach ([[['v', 'asc']], [['v', 'desc']], [['g', 'asc'], ['v', 'desc']], [['g', 'desc'], ['v', 'asc']]] as $keys) {
            $q = $db->query('mix');
            foreach ($keys as [$field, $order]) {
                $q->sort($field, $order);
            }
            $this->assertSame($reference($keys), array_column($q->get(), 'i'), json_encode($keys));
        }
        $this->assertSame($reference([['v', 'desc']]), array_column($db->sort($db->find('mix', 0), 'v', 'desc'), 'i'), 'sort() helper');
        $this->assertSame(array_slice($reference([['v', 'asc']]), 5, 7), array_column($db->query('mix')->sort('v')->offset(5)->limit(7)->get(), 'i'));
    }

    /** @test Y-27 */
    public function nestedValuesCompareTheSameWay(): void
    {
        $db = $this->db();
        $db->insert('ne', [['addr' => ['city' => 'Ist', 'zip' => 1]], ['addr' => ['city' => 'Ist']], ['addr' => ['city' => 'Ank']]]);
        $filter = ['addr' => ['city' => 'Ist']];
        $viaFind = array_column($db->find('ne', $filter), 'key');
        $viaQuery = array_column($db->query('ne')->where($filter)->get(), 'key');
        $viaOr = array_column($db->query('ne')->where($filter)->orWhere(['addr' => ['city' => 'none']])->get(), 'key');
        $this->assertSame([1], $viaFind);
        $this->assertSame($viaFind, $viaQuery);
        $this->assertSame($viaFind, $viaOr);
    }

    /** @test O-01 O-02 */
    public function countMatchesGet(): void
    {
        $db = $this->db(['bufferEnabled' => true]);
        for ($i = 0; $i < 5; $i++) {
            $db->insert('ct', ['i' => $i, 'g' => $i % 2, 'loc' => ['type' => 'Point', 'coordinates' => [$i, 0]]]);
        }
        $this->assertSame(5, $db->count('ct'), 'buffered records are counted');
        $this->assertSame(5, $db->query('ct')->count());
        $this->assertSame(2, $db->query('ct')->groupBy('g')->count(), 'groups');
        $this->assertSame(2, $db->query('ct')->limit(2)->count());
        $this->assertSame(3, $db->query('ct')->offset(2)->count());
        $this->assertSame(count($db->query('ct')->withinDistance('loc', 0, 0, 350000)->get()), $db->query('ct')->withinDistance('loc', 0, 0, 350000)->count());
    }

    /** @test O-03 */
    public function getDBsListsEachDatabaseOnce(): void
    {
        $db = $this->db(['shardSize' => 3]);
        $db->insert('users', ['a' => 1]);
        $db->createFieldIndex('users', 'a');
        $db->insert('sh', [['i' => 1], ['i' => 2], ['i' => 3], ['i' => 4]]);
        $this->assertEqualsCanonicalizing(['users', 'sh'], $db->getDBs());
        $info = $db->getDBs('sh');
        $this->assertSame('sh', $info['name']);
        $this->assertNotSame('0 B', $info['size']);
        $this->assertCount(2, $db->getDBs(true));
    }

    /** @test O-04 */
    public function withDistanceIsComputedBeforeSortAndKeptBySelect(): void
    {
        $db = $this->db();
        $db->insert('wd', [
            ['n' => 'a', 'loc' => ['type' => 'Point', 'coordinates' => [1, 0]]],
            ['n' => 'b', 'loc' => ['type' => 'Point', 'coordinates' => [0.5, 0]]],
            ['n' => 'c', 'loc' => ['type' => 'Point', 'coordinates' => [0.1, 0]]],
            ['n' => 'm', 'loc' => ['type' => 'MultiPolygon', 'coordinates' => [[[[2, 2], [2.001, 2], [2.001, 2.001], [2, 2.001], [2, 2]]]]]],
        ]);
        $r = $db->query('wd')->withDistance('loc', 0, 0)->sort('_distance')->limit(2)->select(['n'])->get();
        $this->assertSame(['c', 'b'], array_column($r, 'n'));
        $this->assertArrayHasKey('_distance', $r[0]);
        $m = $db->query('wd')->where(['n' => 'm'])->withDistance('loc', 2.0005, 2.0005)->first();
        $this->assertLessThan(100, $m['_distance'], 'MultiPolygon centroid');
        $own = $db->query('wd')->withinDistance('loc', 1, 0, 200000)->withDistance('loc', 0, 0)->sort('_distance')->get();
        $this->assertEqualsWithDelta(11119.49, $own[0]['_distance'], 1, 'withDistance uses its own point');
    }

    /** @test O-04: withDistance() measures like the spatial filters (nearest point of the geometry) */
    public function withDistanceMeasuresLikeTheSpatialFilters(): void
    {
        $db = $this->db();
        $square = [[[0.01, -0.005], [0.02, -0.005], [0.02, 0.005], [0.01, 0.005], [0.01, -0.005]]];
        $db->insert('wm', [
            ['n' => 'sq', 'loc' => ['type' => 'Polygon', 'coordinates' => $square]],
            ['n' => 'pt', 'loc' => ['type' => 'Point', 'coordinates' => [0.005, 0]]],
        ]);
        // The square's nearest edge is ~1112 m away, its centroid ~1668 m
        $r = $db->query('wm')->withinDistance('loc', 0, 0, 1200)->withDistance('loc', 0, 0)->sort('_distance')->get();
        $this->assertSame(['pt', 'sq'], array_column($r, 'n'));
        foreach ($r as $row) {
            $this->assertLessThanOrEqual(1200, $row['_distance'], $row['n'] . ' passed a 1200 m filter');
        }
        $filterOnly = $db->query('wm')->withinDistance('loc', 0, 0, 1200)->get();
        $this->assertEqualsWithDelta(array_column($filterOnly, '_distance', 'n'), array_column($r, '_distance', 'n'), 0.01);
        $inside = $db->query('wm')->where(['n' => 'sq'])->withDistance('loc', 0.015, 0)->first();
        $this->assertSame(0.0, (float)$inside['_distance'], 'a point inside the polygon is at distance 0');
    }

    /** @test O-10: the direct between() compares like query()->between() */
    public function directBetweenIsTypeAwareToo(): void
    {
        $db = $this->db();
        $this->seed($db);
        $db->insert('q', [['name' => 'yes', 'age' => true], ['name' => 'float', 'age' => 26.5], ['name' => 'nul', 'age' => null]]);
        foreach ([[26, 40], [0, 100], ['a', 'z'], [25, 25]] as [$min, $max]) {
            $this->assertSame(
                array_column($db->query('q')->between('age', $min, $max)->get(), 'name'),
                array_column($db->between('q', 'age', $min, $max), 'name'),
                "between $min and $max"
            );
        }
        $this->assertSame(['Bob', 'Charlie', 'float'], array_column($db->between('q', 'age', 26, 40), 'name'));
        $this->assertSame(['Charlie'], array_column($db->between('q', 'age', 26, 40, ['city' => 'Istanbul']), 'name'));
    }

    /** @test O-05 */
    public function nearestAppliesKAfterOtherFilters(): void
    {
        $db = $this->db();
        $db->insert('nw', [
            ['t' => 'x', 'loc' => ['type' => 'Point', 'coordinates' => [0, 0]]],
            ['t' => 'x', 'loc' => ['type' => 'Point', 'coordinates' => [0.1, 0]]],
            ['t' => 'z', 'loc' => ['type' => 'Point', 'coordinates' => [1, 0]]],
            ['t' => 'z', 'loc' => ['type' => 'Point', 'coordinates' => [2, 0]]],
        ]);
        $this->assertSame([2, 3], array_column($db->query('nw')->nearest('loc', 0, 0, 2)->where(['t' => 'z'])->get(), 'key'));
    }

    /** @test O-06 */
    public function groupByKeepsValueTypes(): void
    {
        $db = $this->db();
        $db->insert('gb', [['v' => 1.5], ['v' => 1.7], ['v' => [1]], ['v' => '__null__'], ['v' => null], ['x' => 1], ['v' => 1], ['v' => '1']]);
        $groups = $db->query('gb')->groupBy('v')->get();
        $this->assertCount(7, $groups);
        $byGroup = [];
        foreach ($groups as $g) {
            $byGroup[json_encode($g['_group'])] = $g['_count'];
        }
        $this->assertSame(2, $byGroup['null'], 'missing and null share a group');
        $this->assertSame(1, $byGroup['"__null__"']);
        $this->assertSame(1, $byGroup['1.5']);
        $this->assertSame(1, $byGroup['1']);
        $this->assertSame(1, $byGroup['"1"']);
    }

    /** @test O-07 */
    public function writesAfterProjectionWorkAndGroupByIsRejected(): void
    {
        $db = $this->db();
        $this->seed($db);
        $this->assertSame(2, $db->query('q')->where(['city' => 'Istanbul'])->except(['key'])->update(['seen' => 1])['n']);
        try {
            $db->query('q')->groupBy('city')->delete();
            $this->fail('groupBy + delete must be rejected');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('groupBy', $e->getMessage());
        }
        $this->assertCount(5, $db->find('q', 0));
    }

    /** @test O-08 */
    public function searchIgnoresKeyAndIsMultibyteAware(): void
    {
        $db = $this->db();
        $this->seed($db);
        $this->assertSame([], $db->query('q')->search('4')->get(), '"4" only appears as a record key');
        $this->assertSame(['AYDOĞDU'], array_column($db->query('q')->search('aydoğdu')->get(), 'name'));
        $this->assertCount(2, $db->query('q')->search('izmir')->get(), 'Izmir and İzmir');
        $this->assertCount(2, $db->query('q')->search('İZMİR')->get());
        $this->assertCount(2, $db->query('q')->search('ZMIR')->get());
    }

    /** @test O-09 */
    public function joinDoesNotCreateDatabases(): void
    {
        $db = $this->db();
        $this->seed($db);
        $before = glob($this->testDbDir . '*');
        $r = $db->query('q')->join('nothere', 'city', 'id')->get();
        $this->assertNull($r[0]['nothere']);
        $this->assertSame($before, glob($this->testDbDir . '*'));
    }

    /** @test O-10 O-11 */
    public function operatorsAreTypeAware(): void
    {
        $db = $this->db();
        $this->seed($db);
        $this->assertSame(['Alice', 'Bob', 'Charlie'], array_column($db->query('q')->where(['age' => ['$gt' => 3]])->get(), 'name'), '"abc" and arrays are not greater than 3; "30" is');
        $this->assertSame([], $db->query('q')->where(['score' => ['$lt' => 18]])->get(), 'null is not less than 18');
        $this->assertSame(['Bob', 'Charlie'], array_column($db->query('q')->between('age', 26, 40)->get(), 'name'));
        try {
            $db->query('q')->where(['age' => ['$gtee' => 1]])->get();
            $this->fail('unknown operator');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('$gtee', $e->getMessage());
        }
        try {
            $db->query('q')->where(['age' => ['$gt' => 1, 'plain' => 2]])->get();
            $this->fail('mixed operator spec');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('mix', $e->getMessage());
        }
        // Filters are compiled before records are scanned: an invalid query fails the same way
        // whether or not there are records to look at
        $db->insert('empty-q', ['a' => 1]);
        $db->delete('empty-q', []);
        foreach ([['$gtee' => 1], ['$in' => 'notarray'], ['$regex' => '(']] as $spec) {
            foreach (['q', 'empty-q'] as $name) {
                try {
                    $db->query($name)->where(['age' => $spec])->get();
                    $this->fail('invalid spec ' . json_encode($spec) . " on $name");
                } catch (\InvalidArgumentException $e) {
                    $this->assertNotSame('', $e->getMessage());
                }
            }
        }
    }

    /** @test O-13 O-14 O-29 */
    public function insertAndNameEdgeCases(): void
    {
        $db = $this->db();
        $r = $db->insert('ie', [0 => ['a' => 1], 'name' => 'x']);
        $this->assertSame(0, $r['n']);
        $this->assertArrayHasKey('error', $r);
        $this->assertSame(0, $db->insert('ie', [])['n']);
        $this->assertSame(['n' => 2], $db->insert('ie', [['a' => 1], ['a' => 2]]));
        $this->assertSame(['n' => 1], $db->insert('0', ['z' => 1]));
        $this->assertSame([['z' => 1, 'key' => 0]], $db->find('0', 0));
        $this->assertCount(2, $db->find('ie', null));
        $this->assertFalse($db->find('ie', 'not-a-filter'));
    }

    /** @test D-05 D-06 */
    public function smallApiFixes(): void
    {
        $db = $this->db();
        $this->assertSame([], $db->limit([], 2));
        $this->assertSame([], $db->sort([], 'a'));
        $this->assertSame('Please check your delete parameters', $db->delete('x', 'bad')['error']);
        $this->assertSame('1,5 KB', $this->getPrivateMethod('fileSizeConvert')->invoke($this->noneDB, 1536), 'documented format');
    }

    /** @test O-33 */
    public function queryWritesRespectSortAndLimit(): void
    {
        $db = $this->db();
        $this->seed($db);
        $this->assertSame(1, $db->query('q')->where(['city' => 'Istanbul'])->sort('name', 'desc')->limit(1)->delete()['n']);
        $this->assertSame(['Alice'], array_column($db->find('q', ['city' => 'Istanbul']), 'name'));
    }

    /** @test P-07 */
    public function queryBuilderDoesNotUseReflection(): void
    {
        $source = file_get_contents(__DIR__ . '/../../noneDB.php');
        $query = substr($source, strpos($source, 'class noneDBQuery'));
        $this->assertStringNotContainsString('ReflectionClass', $query);
    }
}
