<?php

namespace noneDB\Tests\Regression;

/**
 * Spatial: Y-01..Y-08, O-24, O-25, O-34, P-04, P-05, D-08
 */
class SpatialRegressionTest extends RegressionTestCase
{
    private function point(float $lon, float $lat): array
    {
        return ['type' => 'Point', 'coordinates' => [$lon, $lat]];
    }

    /** @test Y-01 */
    public function bufferedInsertsAreSpatiallyIndexed(): void
    {
        $db = $this->db(['bufferEnabled' => true]);
        $db->insert('pl', ['n' => 'seed', 'loc' => $this->point(29.0, 41.0)]);
        $db->createSpatialIndex('pl', 'loc');
        $db->insert('pl', ['n' => 'A', 'loc' => $this->point(29.0001, 41.0001)]);
        $db->insert('pl', ['n' => 'B', 'loc' => $this->point(29.0002, 41.0)]);
        $this->assertSame(['seed', 'A', 'B'], array_column($db->withinDistance('pl', 'loc', 29.0, 41.0, 1000, ['includeDistance' => true]), 'n'));
        $this->assertCount(3, $db->withinBBox('pl', 'loc', 28.99, 40.99, 29.01, 41.01));
        $this->assertCount(3, $db->query('pl')->withinDistance('loc', 29.0, 41.0, 1000)->get());
    }

    /** @test Y-02 Y-05 */
    public function shardedSpatialIndexWorksAndCoversNewShards(): void
    {
        $db = $this->db(['shardSize' => 50]);
        $rows = [];
        for ($i = 0; $i < 120; $i++) {
            $rows[] = ['i' => $i, 'loc' => $this->point(29 + $i / 1000, 41)];
        }
        $db->insert('sh', $rows);
        $r = $db->createSpatialIndex('sh', 'loc');
        $this->assertTrue($r['success']);
        $this->assertSame(120, $r['indexed']);
        $this->assertFalse($db->createSpatialIndex('sh', 'loc')['success'], 'second create reports "already exists"');

        $more = [];
        for ($i = 120; $i < 200; $i++) {
            $more[] = ['i' => $i, 'loc' => $this->point(29 + $i / 1000, 41)];
        }
        $db->insert('sh', $more);
        $this->assertCount(200, $db->withinBBox('sh', 'loc', 28.9, 40.9, 29.3, 41.1));
        $this->assertSame(199, $db->nearest('sh', 'loc', 29.199, 41.0, 1)[0]['i']);
        $this->assertTrue($db->hasSpatialIndex('sh', 'loc'));
        $this->assertSame(['loc'], $db->getSpatialIndexes('sh'));
    }

    /** @test Y-04 */
    public function migrationKeepsTheSpatialIndex(): void
    {
        $db = $this->db(['shardSize' => 30]);
        $db->insert('mg', ['n' => 'first', 'loc' => $this->point(10, 10)]);
        $db->createSpatialIndex('mg', 'loc');
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $rows[] = ['n' => 'p' . $i, 'loc' => $this->point(10 + $i / 100, 10)];
        }
        $db->insert('mg', $rows); // crosses the threshold: migrates
        $this->assertTrue($db->getShardInfo('mg')['sharded']);
        $this->assertSame(['loc'], $db->getSpatialIndexes('mg'));
        $this->assertTrue($db->hasSpatialIndex('mg', 'loc'));
        $this->assertCount(41, $db->withinBBox('mg', 'loc', 9.9, 9.9, 10.5, 10.1));
        $this->assertFileDoesNotExist($this->testDbDir . hash_pbkdf2('sha256', 'mg', self::SECRET, 1000, 20) . '.nonedb.sidx.loc');
        $this->assertTrue($db->rebuildSpatialIndex('mg', 'loc')['success']);
        $this->assertCount(41, $db->withinBBox('mg', 'loc', 9.9, 9.9, 10.5, 10.1));
    }

    /** @test Y-03 P-04: R-tree never hangs and agrees with brute force */
    public function randomizedRtreeMatchesBruteForce(): void
    {
        mt_srand(7);
        $db = $this->db();
        $rows = [];
        // three clusters
        foreach ([[29, 41], [32.8, 39.9], [27.1, 38.4]] as $c => $center) {
            for ($i = 0; $i < 700; $i++) {
                $rows[] = ['c' => $c, 'loc' => $this->point($center[0] + mt_rand(-1000, 1000) / 10000, $center[1] + mt_rand(-1000, 1000) / 10000)];
            }
        }
        $db->insert('rt', $rows);
        $db->createSpatialIndex('rt', 'loc');
        $db->delete('rt', ['c' => 1]);                                   // remove a whole cluster
        $start = microtime(true);
        $db->insert('rt', ['c' => 9, 'loc' => $this->point(32.8, 39.9)]); // used to loop forever
        $this->assertLessThan(5, microtime(true) - $start);
        for ($i = 0; $i < 150; $i++) {
            $db->update('rt', [['key' => mt_rand(0, 2099)], ['set' => ['loc' => $this->point(26 + mt_rand(0, 8000) / 1000, 37 + mt_rand(0, 5000) / 1000)]]]);
        }

        $all = $db->find('rt', 0);
        for ($q = 0; $q < 25; $q++) {
            $lon = 26 + mt_rand(0, 8000) / 1000;
            $lat = 37 + mt_rand(0, 5000) / 1000;
            $radius = mt_rand(1000, 150000);
            $expected = [];
            foreach ($all as $r) {
                if ($db->haversineDistance($lon, $lat, $r['loc']['coordinates'][0], $r['loc']['coordinates'][1]) <= $radius) {
                    $expected[] = $r['key'];
                }
            }
            $got = array_column($db->withinDistance('rt', 'loc', $lon, $lat, $radius), 'key');
            sort($expected);
            sort($got);
            $this->assertSame($expected, $got, "withinDistance query $q");

            $box = [$lon - 0.5, $lat - 0.3, $lon + 0.5, $lat + 0.3];
            $expectedBox = [];
            foreach ($all as $r) {
                [$x, $y] = $r['loc']['coordinates'];
                if ($x >= $box[0] && $x <= $box[2] && $y >= $box[1] && $y <= $box[3]) {
                    $expectedBox[] = $r['key'];
                }
            }
            $gotBox = array_column($db->withinBBox('rt', 'loc', ...$box), 'key');
            sort($expectedBox);
            sort($gotBox);
            $this->assertSame($expectedBox, $gotBox, "withinBBox query $q");

            $dist = [];
            foreach ($all as $r) {
                $dist[$r['key']] = $db->haversineDistance($lon, $lat, $r['loc']['coordinates'][0], $r['loc']['coordinates'][1]);
            }
            asort($dist);
            $nearest = $db->nearest('rt', 'loc', $lon, $lat, 5);
            $this->assertEqualsWithDelta(array_slice(array_values($dist), 0, 5), array_column($nearest, '_distance'), 0.01, "nearest query $q");
        }
    }

    /** @test Y-06 */
    public function antimeridianAndPolesAreSearched(): void
    {
        $db = $this->db();
        $db->insert('am', [
            ['n' => 'east', 'loc' => $this->point(179.99, 0)],
            ['n' => 'west', 'loc' => $this->point(-179.99, 0.001)],
            ['n' => 'pole-a', 'loc' => $this->point(0, 89.5)],
            ['n' => 'pole-b', 'loc' => $this->point(180, 89.5)],
        ]);
        $db->createSpatialIndex('am', 'loc');
        $this->assertSame(['west', 'east'], array_column($db->withinDistance('am', 'loc', -179.99, 0, 5000, ['includeDistance' => true]), 'n'));
        $nearPole = array_column($db->withinDistance('am', 'loc', 0, 88.5, 300000), 'n');
        sort($nearPole);
        $this->assertSame(['pole-a', 'pole-b'], $nearPole);
        $this->assertSame(['east', 'west'], array_column($db->withinBBox('am', 'loc', 179.9, -1, -179.9, 1), 'n'), 'box crossing the antimeridian');
    }

    /** @test Y-07 */
    public function nearestRespectsMaxDistanceAndReturnsPartialResults(): void
    {
        $db = $this->db();
        $db->insert('nr', [
            ['n' => 'a', 'loc' => $this->point(29.0, 41.0)],
            ['n' => 'b', 'loc' => $this->point(29.002, 41.0)],
            ['n' => 'c', 'loc' => $this->point(29.004, 41.0)],
            ['n' => 'far', 'loc' => $this->point(35, 41)],
        ]);
        foreach ([false, true] as $indexed) {
            if ($indexed) {
                $db->createSpatialIndex('nr', 'loc');
            }
            $this->assertSame(['a', 'b', 'c'], array_column($db->nearest('nr', 'loc', 29.0, 41.0, 5, ['maxDistance' => 1000]), 'n'));
            $this->assertSame(['a'], array_column($db->nearest('nr', 'loc', 29.0, 41.0, 1, ['maxDistance' => 100]), 'n'));
            $this->assertSame(['far'], array_column($db->nearest('nr', 'loc', 35, 41, 1), 'n'));
            $this->assertSame([], $db->nearest('nr', 'loc', 29, 41, 0));
            $this->assertCount(4, $db->nearest('nr', 'loc', 29, 41, 10));
        }
    }

    /** @test Y-08 */
    public function spatialFieldNamesWithAnyCharacters(): void
    {
        $db = $this->db();
        $db->insert('sf', ['n' => 'first', 'home-loc' => $this->point(1, 1), 'homeloc' => $this->point(50, 50)]);
        $db->createSpatialIndex('sf', 'home-loc');
        $db->createSpatialIndex('sf', 'homeloc');
        $db->insert('sf', ['n' => 'second', 'home-loc' => $this->point(1.001, 1), 'homeloc' => $this->point(50, 50)]);
        $this->assertSame(['first', 'second'], array_column($db->withinDistance('sf', 'home-loc', 1, 1, 1000), 'n'));
        $this->assertCount(2, $db->withinDistance('sf', 'homeloc', 50, 50, 10));
        $this->assertEqualsCanonicalizing(['home-loc', 'homeloc'], $db->getSpatialIndexes('sf'));
    }

    /** @test O-24 */
    public function geometrySemantics(): void
    {
        $db = $this->db();
        // concave "C" shaped polygon: the notch is outside
        $c = ['type' => 'Polygon', 'coordinates' => [[[0, 0], [10, 0], [10, 3], [3, 3], [3, 7], [10, 7], [10, 10], [0, 10], [0, 0]]]];
        $db->insert('gs', [
            ['n' => 'crossing-line', 'g' => ['type' => 'LineString', 'coordinates' => [[5, 2], [5, 8]]]],
            ['n' => 'inside-line', 'g' => ['type' => 'LineString', 'coordinates' => [[1, 1], [1, 9]]]],
            ['n' => 'big-poly', 'g' => ['type' => 'Polygon', 'coordinates' => [[[20, 20], [30, 20], [30, 30], [20, 30], [20, 20]]]]],
        ]);
        $this->assertSame(['inside-line'], array_column($db->withinPolygon('gs', 'g', $c), 'n'));
        $multi = ['type' => 'MultiPolygon', 'coordinates' => [$c['coordinates'], [[[19, 19], [31, 19], [31, 31], [19, 31], [19, 19]]]]];
        $this->assertSame(['inside-line', 'big-poly'], array_column($db->withinPolygon('gs', 'g', $multi), 'n'));
        // a point inside a large polygon is at distance 0 from it
        $this->assertSame(['big-poly'], array_column($db->withinDistance('gs', 'g', 25, 25, 10), 'n'));

        $this->assertTrue($db->validateGeoJSON(['type' => 'Polygon', 'coordinates' => [[[29, 41], [30, 41], [30, 42], [29.0, 41.0]]]])['valid'], '29 == 29.0');
        $this->assertFalse($db->validateGeoJSON(['type' => 'Polygon', 'coordinates' => [[[0, 0], [10, 10], [10, 0], [0, 10], [0, 0]]]])['valid'], 'bow-tie');
        $this->assertFalse($db->validateGeoJSON(['type' => 'Polygon', 'coordinates' => [[[0, 0], [5, 0], [5, 5], [0, 5], [0, 0]], [[6, 6], [7, 6], [7, 7], [6, 6]]]])['valid'], 'hole outside');
        $this->assertFalse($db->validateGeoJSON($this->point(NAN, 1))['valid']);
        $this->assertFalse($db->validateGeoJSON(['type' => 'Point', 'coordinates' => ['1', '2']])['valid'], 'strings are not coordinates');
    }

    /** @test O-34 P-05 */
    public function queriesWithoutHitsDoNotScan(): void
    {
        $db = $this->db();
        $rows = [];
        for ($i = 0; $i < 3000; $i++) {
            $rows[] = ['loc' => $this->point(29 + ($i % 100) / 1000, 41 + intdiv($i, 100) / 1000)];
        }
        $db->insert('zh', $rows);
        $db->createSpatialIndex('zh', 'loc');
        $start = microtime(true);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame([], $db->withinDistance('zh', 'loc', -70, -30, 1000));
            $this->assertSame([], $db->withinBBox('zh', 'loc', -71, -31, -69, -29));
        }
        $this->assertLessThan(0.5, microtime(true) - $start);
    }

    /** @test O-25 */
    public function spatialIndexFilesAreEncodedOnce(): void
    {
        $db = $this->db();
        $db->insert('en', ['loc' => $this->point(1, 1)]);
        $db->createSpatialIndex('en', 'loc');
        $file = $this->testDbDir . hash_pbkdf2('sha256', 'en', self::SECRET, 1000, 20) . '.nonedb.sidx.loc';
        $decoded = json_decode(file_get_contents($file), true);
        $this->assertIsArray($decoded);
        $this->assertSame(3, $decoded['v']);
    }

    /** @test D-08 */
    public function centroidOfPointNeedsNoHashing(): void
    {
        $method = $this->getPrivateMethod('getGeometryCentroid');
        $this->assertSame([28.9803, 41.0086], $method->invoke($this->noneDB, $this->point(28.9803, 41.0086)));
    }

    /** @test D-08 the memo caches stay bounded and keep caching once full */
    public function memoCachesKeepWorkingWhenFull(): void
    {
        $db = $this->noneDB;
        for ($i = 0; $i < 12000; $i++) {
            $db->haversineDistance(0, 0, $i / 1000, 1);
        }
        $before = $this->getPrivateProperty('distanceCache');
        $this->assertLessThanOrEqual(10000, count($before));
        $d = $db->haversineDistance(10, 10, 20, 20);
        $after = $this->getPrivateProperty('distanceCache');
        $this->assertLessThanOrEqual(10000, count($after));
        $this->assertSame([$d], array_values(array_diff_key($after, $before)), 'a new result is still cached');
        $this->assertSame($d, $db->haversineDistance(10, 10, 20, 20));

        $centroid = $this->getPrivateMethod('getGeometryCentroid');
        for ($i = 0; $i < 6000; $i++) {
            $centroid->invoke($db, ['type' => 'LineString', 'coordinates' => [[0, 0], [$i / 100, 1]]]);
        }
        $before = $this->getPrivateProperty('centroidCache');
        $this->assertLessThanOrEqual(5000, count($before));
        $line = ['type' => 'LineString', 'coordinates' => [[5, 5], [7, 9]]];
        $c = $centroid->invoke($db, $line);
        $after = $this->getPrivateProperty('centroidCache');
        $this->assertLessThanOrEqual(5000, count($after));
        $this->assertSame([$c], array_values(array_diff_key($after, $before)), 'a new centroid is still cached');
    }

    /**
     * @test Y-07: the expanding radius search must not cut through a rounding tie. A record just outside
     * the first radius (1000.003 m) rounds to the same 1000.00 m as one just inside (999.998 m) and has the
     * smaller key, so it comes first in distance order.
     */
    public function nearestDoesNotCutThroughRoundingTies(): void
    {
        $at = function (float $meters) {
            return ['type' => 'Point', 'coordinates' => [29.0, 41.0 + rad2deg($meters / 6371000.0)]];
        };
        foreach ([true, false] as $indexed) {
            $name = $indexed ? 'tie-idx' : 'tie-scan';
            $db = $this->db();
            $db->insert($name, [['loc' => $at(1000.003)], ['loc' => $at(999.998)], ['loc' => $at(500)]]);
            if ($indexed) {
                $db->createSpatialIndex($name, 'loc');
            }
            $distances = array_column($db->withinDistance($name, 'loc', 29.0, 41.0, 2000, ['includeDistance' => true]), '_distance', 'key');
            ksort($distances);
            $this->assertSame([0 => 1000.0, 1 => 1000.0, 2 => 500.0], array_map('floatval', $distances), 'setup: both round to 1000.00');
            $this->assertCount(1, array_filter($db->withinDistance($name, 'loc', 29.0, 41.0, 1000), function ($r) { return $r['key'] === 1; }), 'setup: only key 1 lies within 1000 m');
            $this->assertSame([2, 0], array_column($db->nearest($name, 'loc', 29.0, 41.0, 2), 'key'), "$name direct");
            $this->assertSame([2, 0], array_column($db->query($name)->nearest('loc', 29.0, 41.0, 2)->get(), 'key'), "$name builder");
            $this->assertSame([2, 0], array_column($db->query($name)->nearest('loc', 29.0, 41.0, 2)->where(['loc' => ['$exists' => true]])->get(), 'key'), "$name builder + where");
        }
    }

    /** @test O-05: query()->nearest(k) with other filters equals a brute-force answer (ties included) */
    public function nearestWithFiltersMatchesBruteForce(): void
    {
        mt_srand(4242);
        $center = [29.0, 41.0];
        foreach ([['idx' => true, 'cfg' => []], ['idx' => false, 'cfg' => []], ['idx' => true, 'cfg' => ['shardSize' => 150]]] as $n => $case) {
            $name = 'nb' . $n;
            $db = $this->db($case['cfg']);
            $rows = [];
            for ($i = 0; $i < 600; $i++) {
                if ($i % 10 === 0 && $i > 0) {
                    $p = $rows[$i - 1]['loc']['coordinates']; // same point again: equal distances
                } elseif ($i % 10 === 1) {
                    $p = [2 * $center[0] - 29.01, 41.0]; // mirrored pairs at the same distance
                    $p = mt_rand(0, 1) ? $p : [29.01, 41.0];
                } else {
                    $p = [$center[0] + mt_rand(-20000, 20000) / 100000, $center[1] + mt_rand(-20000, 20000) / 100000];
                }
                $rows[] = ['t' => mt_rand(0, 19), 'loc' => ['type' => 'Point', 'coordinates' => $p]];
            }
            $db->insert($name, $rows);
            if ($case['idx']) {
                $db->createSpatialIndex($name, 'loc');
            }
            $all = $db->find($name, 0);
            foreach ([[1, 0], [5, 3], [10, 19], [7, 100], [40, 0]] as [$k, $tMax]) {
                // Reference: distance rounded like the library, ties by key, filter, then the k cut
                $expected = [];
                foreach ($all as $r) {
                    if ($r['t'] <= $tMax) {
                        $d = round($db->haversineDistance($center[0], $center[1], $r['loc']['coordinates'][0], $r['loc']['coordinates'][1]), 2);
                        $expected[] = [$d, $r['key']];
                    }
                }
                usort($expected, function ($a, $b) { return $a[0] <=> $b[0] ?: $a[1] <=> $b[1]; });
                $expected = array_column(array_slice($expected, 0, $k), 1);
                $got = $db->query($name)->nearest('loc', $center[0], $center[1], $k)->where(['t' => ['$lte' => $tMax]])->get();
                $this->assertSame($expected, array_column($got, 'key'), "$name k=$k t<=$tMax");
                $this->assertSame($expected, array_column($db->query($name)->where(['t' => ['$lte' => $tMax]])->nearest('loc', $center[0], $center[1], $k)->get(), 'key'), "$name order of calls");
            }
        }
    }
}
