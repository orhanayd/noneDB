<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * docs/SPATIAL.md and CHANGES.md as an executable specification of the spatial features.
 *
 * Every expected value comes from the documentation, from hand geometry (small coordinates where the
 * answer is obvious), or from the independent model below: haversine with R = 6,371,000 m, a closed
 * point-in-polygon test, bounding boxes. Nothing is copied from noneDB's output. Indexed results are
 * compared with 'spatialIndexEnabled' => false (a scan) and with the model, sharded and not, after
 * inserts, updates, deletes, compaction and clearStaticCache().
 *
 * The model's public static helpers are also used by the other spatial test files.
 */
class SpecSpatialTest extends RegressionTestCase
{
    // ==========================================================================================
    // Independent model (written from docs/SPATIAL.md, not from the implementation)
    // ==========================================================================================

    /** docs: "Distances in meters, using the Haversine formula (earth radius 6,371 km)" */
    public const EARTH_RADIUS = 6371000.0;

    /** Haversine distance in meters (asin form; noneDB uses the atan2 form) */
    public static function hav(float $lon1, float $lat1, float $lon2, float $lat2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
        return 2 * self::EARTH_RADIUS * asin(min(1.0, sqrt($a)));
    }

    /**
     * Distance to the nearest point of a straight lon/lat segment: golden-section search of the haversine
     * distance along it (unimodal for the short segments used here), both ends included
     */
    public static function segmentDistance(float $lon, float $lat, array $a, array $b): float
    {
        $ax = (float)$a[0];
        $ay = (float)$a[1];
        $bx = (float)$b[0];
        $by = (float)$b[1];
        $f = function (float $t) use ($lon, $lat, $ax, $ay, $bx, $by): float {
            return self::hav($lon, $lat, $ax + ($bx - $ax) * $t, $ay + ($by - $ay) * $t);
        };
        $g = (sqrt(5) - 1) / 2;
        $lo = 0.0;
        $hi = 1.0;
        $x1 = $hi - $g * ($hi - $lo);
        $x2 = $lo + $g * ($hi - $lo);
        $f1 = $f($x1);
        $f2 = $f($x2);
        for ($i = 0; $i < 64; $i++) { // the bracket shrinks to 0.618^64 = 4e-14 of the segment
            if ($f1 < $f2) {
                $hi = $x2;
                $x2 = $x1;
                $f2 = $f1;
                $x1 = $hi - $g * ($hi - $lo);
                $f1 = $f($x1);
            } else {
                $lo = $x1;
                $x1 = $x2;
                $f1 = $f2;
                $x2 = $lo + $g * ($hi - $lo);
                $f2 = $f($x2);
            }
        }
        return min($f(0.0), $f(1.0), $f1, $f2);
    }

    public static function pathDistance(float $lon, float $lat, array $points): float
    {
        $best = INF;
        $n = count($points);
        for ($i = 0; $i + 1 < $n; $i++) {
            $best = min($best, self::segmentDistance($lon, $lat, $points[$i], $points[$i + 1]));
        }
        return $best;
    }

    /** Where a point is against a ring: 1 inside, 0 on its boundary, -1 outside (winding number) */
    public static function locate(float $x, float $y, array $ring): int
    {
        $winding = 0;
        $n = count($ring) - 1;
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = [(float)$ring[$i][0], (float)$ring[$i][1]];
            [$bx, $by] = [(float)$ring[$i + 1][0], (float)$ring[$i + 1][1]];
            $o = ($bx - $ax) * ($y - $ay) - ($x - $ax) * ($by - $ay);
            if ($o == 0 && min($ax, $bx) <= $x && $x <= max($ax, $bx) && min($ay, $by) <= $y && $y <= max($ay, $by)) {
                return 0;
            }
            if ($ay <= $y) {
                if ($by > $y && $o > 0) {
                    $winding++;
                }
            } elseif ($by <= $y && $o < 0) {
                $winding--;
            }
        }
        return $winding !== 0 ? 1 : -1;
    }

    /** docs: "The polygon is closed": inside or on the outer ring, and not strictly inside a hole */
    public static function inClosedPolygon(float $x, float $y, array $rings): bool
    {
        if (self::locate($x, $y, $rings[0]) < 0) {
            return false;
        }
        for ($h = 1; $h < count($rings); $h++) {
            if (self::locate($x, $y, $rings[$h]) > 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * docs: "Distance is measured to the nearest point of the geometry: 0 for a point inside a polygon,
     * the closest vertex or edge otherwise". INF for a geometry without any position.
     */
    public static function distance(float $lon, float $lat, array $g): float
    {
        $c = $g['coordinates'] ?? [];
        switch ($g['type']) {
            case 'Point':
                return self::hav($lon, $lat, (float)$c[0], (float)$c[1]);
            case 'MultiPoint':
                $best = INF;
                foreach ($c as $p) {
                    $best = min($best, self::hav($lon, $lat, (float)$p[0], (float)$p[1]));
                }
                return $best;
            case 'LineString':
                return self::pathDistance($lon, $lat, $c);
            case 'MultiLineString':
                $best = INF;
                foreach ($c as $line) {
                    $best = min($best, self::pathDistance($lon, $lat, $line));
                }
                return $best;
            case 'Polygon':
                if (self::inClosedPolygon($lon, $lat, $c)) {
                    return 0.0;
                }
                $best = INF;
                foreach ($c as $ring) {
                    $best = min($best, self::pathDistance($lon, $lat, $ring));
                }
                return $best;
            case 'MultiPolygon':
                $best = INF;
                foreach ($c as $polygon) {
                    $best = min($best, self::distance($lon, $lat, ['type' => 'Polygon', 'coordinates' => $polygon]));
                }
                return $best;
            case 'GeometryCollection':
                $best = INF;
                foreach ($g['geometries'] as $member) {
                    $best = min($best, self::distance($lon, $lat, $member));
                }
                return $best;
        }
        throw new \LogicException('model: unknown type');
    }

    /** Every [lon, lat] of a geometry (an altitude is dropped) */
    public static function positions(array $g): array
    {
        $c = $g['coordinates'] ?? [];
        switch ($g['type']) {
            case 'Point':
                return [[(float)$c[0], (float)$c[1]]];
            case 'MultiPoint':
            case 'LineString':
                return array_map(function ($p) { return [(float)$p[0], (float)$p[1]]; }, $c);
            case 'MultiLineString':
            case 'Polygon':
                $out = [];
                foreach ($c as $part) {
                    foreach ($part as $p) {
                        $out[] = [(float)$p[0], (float)$p[1]];
                    }
                }
                return $out;
            case 'MultiPolygon':
                $out = [];
                foreach ($c as $polygon) {
                    foreach ($polygon as $ring) {
                        foreach ($ring as $p) {
                            $out[] = [(float)$p[0], (float)$p[1]];
                        }
                    }
                }
                return $out;
            case 'GeometryCollection':
                $out = [];
                foreach ($g['geometries'] as $member) {
                    $out = array_merge($out, self::positions($member));
                }
                return $out;
        }
        throw new \LogicException('model: unknown type');
    }

    /** Bounding box [minLon, minLat, maxLon, maxLat]; null without any position */
    public static function bbox(array $g): ?array
    {
        $box = null;
        foreach (self::positions($g) as [$x, $y]) {
            $box = $box === null ? [$x, $y, $x, $y] : [min($box[0], $x), min($box[1], $y), max($box[2], $x), max($box[3], $y)];
        }
        return $box;
    }

    /** Overlap or touch */
    public static function boxesTouch(array $a, array $b): bool
    {
        return $a[0] <= $b[2] && $b[0] <= $a[2] && $a[1] <= $b[3] && $b[1] <= $a[3];
    }

    /** docs (withinBBox): minLon > maxLon crosses the antimeridian; infinite edges are clamped to +-180/+-90 */
    public static function bboxQueryBoxes(float $minLon, float $minLat, float $maxLon, float $maxLat): array
    {
        $minLon = max(-180.0, min(180.0, $minLon));
        $maxLon = max(-180.0, min(180.0, $maxLon));
        $minLat = max(-90.0, min(90.0, $minLat));
        $maxLat = max(-90.0, min(90.0, $maxLat));
        return $minLon <= $maxLon ? [[$minLon, $minLat, $maxLon, $maxLat]]
            : [[$minLon, $minLat, 180.0, $maxLat], [-180.0, $minLat, $maxLon, $maxLat]];
    }

    /** withinBBox() per the docs: the geometry's bounding box overlaps or touches the box */
    public static function inBBoxQuery(array $g, array $queryBoxes): bool
    {
        $box = self::bbox($g);
        if ($box === null) {
            return false;
        }
        foreach ($queryBoxes as $q) {
            if (self::boxesTouch($box, $q)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Components of a record for withinPolygon(): "each component of a multi-part record (each point of a
     * MultiPoint, each part of a MultiPolygon or GeometryCollection)"; members without a position dropped
     * @return array list of position lists
     */
    public static function components(array $g): array
    {
        switch ($g['type']) {
            case 'Point':
            case 'LineString':
            case 'Polygon':
                return [self::positions($g)];
            case 'MultiPoint':
                return array_map(function ($p) { return [[(float)$p[0], (float)$p[1]]]; }, $g['coordinates']);
            case 'MultiLineString':
                return array_map(function ($l) { return self::positions(['type' => 'LineString', 'coordinates' => $l]); }, $g['coordinates']);
            case 'MultiPolygon':
                return array_map(function ($p) { return self::positions(['type' => 'Polygon', 'coordinates' => $p]); }, $g['coordinates']);
            case 'GeometryCollection':
                $out = [];
                foreach ($g['geometries'] as $member) {
                    foreach (self::components($member) as $component) {
                        if (!empty($component)) {
                            $out[] = $component;
                        }
                    }
                }
                return $out;
        }
        throw new \LogicException('model: unknown type');
    }

    /**
     * withinPolygon() for a query made of convex parts without holes (exact there): a geometry with a
     * position matches when every component lies in one part (all its positions inside or on the part)
     * @param array $parts list of polygon coordinates (rings), each convex
     */
    public static function withinConvexParts(array $g, array $parts): bool
    {
        $components = self::components($g);
        if (empty($components)) {
            return false;
        }
        foreach ($components as $component) {
            $fits = false;
            foreach ($parts as $rings) {
                $all = true;
                foreach ($component as [$x, $y]) {
                    if (!self::inClosedPolygon($x, $y, $rings)) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    $fits = true;
                    break;
                }
            }
            if (!$fits) {
                return false;
            }
        }
        return true;
    }

    // ==========================================================================================
    // Helpers
    // ==========================================================================================

    private const LOC = 'loc';

    private static function point(float $lon, float $lat): array
    {
        return ['type' => 'Point', 'coordinates' => [$lon, $lat]];
    }

    private static function rect(float $x1, float $y1, float $x2, float $y2): array
    {
        return [[$x1, $y1], [$x2, $y1], [$x2, $y2], [$x1, $y2], [$x1, $y1]];
    }

    private static function polygon(array ...$rings): array
    {
        return ['type' => 'Polygon', 'coordinates' => $rings];
    }

    private static function keys(array $rows): array
    {
        $keys = array_column($rows, 'key');
        sort($keys);
        return $keys;
    }

    private function stat(string $name): int
    {
        $property = new \noneDB\Tests\InternalValue($name);
        return $property->getValue();
    }

    private function setStatic(string $name, $value): void
    {
        $property = new \noneDB\Tests\InternalValue($name);
        $property->setValue(null, $value);
    }

    protected function tearDown(): void
    {
        $this->setStatic('renameFn', null);
        parent::tearDown();
    }

    /** [indexed instance, scanning instance] */
    private function pair(array $config = []): array
    {
        return [$this->db($config), $this->db(array_merge($config, ['spatialIndexEnabled' => false]))];
    }

    /** The same query on the indexed and the scanning instance: equal results (order included), returned once */
    private function both(\noneDB $idx, \noneDB $scan, callable $query, string $label = ''): array
    {
        $a = $query($idx);
        $b = $query($scan);
        $this->assertSame($b, $a, "index == scan $label");
        return $a;
    }

    /** Insert records with the field 'loc' (null: no field); returns their keys (keys are 0, 1, 2, ... in order) */
    private function insertGeometries(\noneDB $db, string $name, array $geometries): void
    {
        $rows = [];
        foreach ($geometries as $n => $g) {
            $rows[] = $g === null ? ['n' => (string)$n] : ['n' => (string)$n, self::LOC => $g];
        }
        $this->assertSame(count($rows), $db->insert($name, $rows)['n']);
    }

    /** @var array Model distances already computed (the same center is asked for again and again) */
    private static $distanceMemo = [];

    private static function memoDistance(float $lon, float $lat, array $g): float
    {
        $id = $lon . '|' . $lat . '|' . md5(serialize($g));
        if (count(self::$distanceMemo) > 200000) {
            self::$distanceMemo = [];
        }
        return self::$distanceMemo[$id] ?? (self::$distanceMemo[$id] = self::distance($lon, $lat, $g));
    }

    /**
     * Tolerance for a modelled distance: distances are exact for every geometry (SPATIAL.md: "measured to the
     * nearest point of the geometry"), so only the rounding of _distance to 2 decimals is left
     */
    private static function tolerance(array $g, float $d = 0.0): float
    {
        return 0.006;
    }

    /**
     * withinDistance() and nearest() against the model: distances to the model's, membership exact
     * outside a band of +-tolerance around the radius, sorted by distance
     * @param array $model key => geometry (valid ones only)
     */
    private function assertWithinDistanceMatchesModel(array $rows, array $model, float $lon, float $lat, float $radius, string $label): void
    {
        $got = [];
        $previous = -INF;
        foreach ($rows as $row) {
            $this->assertArrayHasKey('_distance', $row, $label);
            $this->assertGreaterThanOrEqual($previous, $row['_distance'], "$label: sorted by distance");
            $previous = $row['_distance'];
            $got[$row['key']] = $row['_distance'];
        }
        foreach ($model as $key => $g) {
            $d = self::memoDistance($lon, $lat, $g);
            $eps = self::tolerance($g, min($d, $radius));
            if ($d <= $radius - $eps) {
                $this->assertArrayHasKey($key, $got, "$label: key $key at " . $d . " m is within $radius m");
            } elseif ($d > $radius + $eps) {
                $this->assertArrayNotHasKey($key, $got, "$label: key $key at " . $d . " m is outside $radius m");
            }
            if (isset($got[$key])) {
                $this->assertEqualsWithDelta($d, $got[$key], $eps, "$label: _distance of key $key");
            }
        }
        $this->assertSame([], array_diff(array_keys($got), array_keys($model)), "$label: only valid geometries");
    }

    /** nearest() against the model: the k smallest distances (ties at the cut may go either way) */
    private function assertNearestMatchesModel(array $rows, array $model, float $lon, float $lat, int $k, ?float $max, string $label): void
    {
        $distances = [];
        foreach ($model as $key => $g) {
            $d = self::memoDistance($lon, $lat, $g);
            if (is_finite($d)) {
                $distances[$key] = $d;
            }
        }
        asort($distances);
        $sure = [];
        $maybe = [];
        foreach ($distances as $key => $d) {
            $eps = self::tolerance($model[$key], $d);
            if ($max === null || $d <= $max - $eps) {
                $sure[$key] = $d;
            }
            if ($max === null || $d <= $max + $eps) {
                $maybe[$key] = $d;
            }
        }
        $this->assertGreaterThanOrEqual(min($k, count($sure)), count($rows), "$label: count");
        $this->assertLessThanOrEqual(min($k, count($maybe)), count($rows), "$label: count");
        if (empty($rows)) {
            return;
        }
        $cut = array_values($maybe)[count($rows) - 1]; // the model's distance at the cut
        $previous = -INF;
        $got = [];
        foreach ($rows as $row) {
            $key = $row['key'];
            $this->assertArrayHasKey($key, $maybe, "$label: key $key may be returned");
            $eps = self::tolerance($model[$key], $distances[$key]);
            $this->assertEqualsWithDelta($distances[$key], $row['_distance'], $eps, "$label: _distance of key $key");
            $this->assertLessThanOrEqual($cut + 2 * $eps, $distances[$key], "$label: key $key is among the nearest");
            $this->assertGreaterThanOrEqual($previous, $row['_distance'], "$label: sorted");
            $previous = $row['_distance'];
            $got[$key] = true;
        }
        foreach ($maybe as $key => $d) {
            if ($d < $cut - 2 * self::tolerance($model[$key], $d)) {
                $this->assertArrayHasKey($key, $got, "$label: key $key at $d m is nearer than the cut $cut m");
            }
        }
    }

    // ==========================================================================================
    // Distances
    // ==========================================================================================

    /** @test docs: meters, haversine with R = 6,371 km, _distance rounded to 2 decimals, altitude ignored */
    public function distancesAreHaversineMetersOnTheDocumentedEarthRadius(): void
    {
        $db = $this->db();
        // 1 degree of a great circle is R * pi / 180 = 111194.9266 m; antipodes are pi * R = 20015086.796 m apart
        $this->assertEqualsWithDelta(self::EARTH_RADIUS * M_PI / 180, $db->haversineDistance(0, 0, 1, 0), 1e-6);
        $this->assertEqualsWithDelta(self::EARTH_RADIUS * M_PI / 180, $db->haversineDistance(0, 0, 0, 1), 1e-6);
        $this->assertEqualsWithDelta(self::EARTH_RADIUS * M_PI, $db->haversineDistance(0, 0, 180, 0), 1e-6);
        $this->assertEqualsWithDelta(self::EARTH_RADIUS * M_PI / 2, $db->haversineDistance(0, 0, 0, 90), 1e-6);
        $this->assertSame(0.0, $db->haversineDistance(28.9784, 41.0082, 28.9784, 41.0082));
        // across the antimeridian: 179.5 E and 179.5 W on the equator are 1 degree apart
        $this->assertEqualsWithDelta(self::EARTH_RADIUS * M_PI / 180, $db->haversineDistance(179.5, 0, -179.5, 0), 1e-6);
        mt_srand(11);
        for ($i = 0; $i < 200; $i++) {
            $p = [mt_rand(-1800, 1800) / 10, mt_rand(-900, 900) / 10, mt_rand(-1800, 1800) / 10, mt_rand(-900, 900) / 10];
            $this->assertEqualsWithDelta(self::hav(...$p), $db->haversineDistance(...$p), 1e-6, json_encode($p));
        }

        foreach ([['shardingEnabled' => false], ['shardSize' => 2]] as $config) {
            $this->cleanTestDirectory();
            [$idx, $scan] = $this->pair($config);
            $this->insertGeometries($idx, 'hv', [
                self::point(1, 0),                                   // 0: 111194.93 m
                self::point(0, 1),                                   // 1: 111194.93 m
                self::point(180, 0),                                 // 2: 20015086.80 m (antipode)
                ['type' => 'Point', 'coordinates' => [0, 0, 1000]], // 3: 0 m, altitude ignored
            ]);
            $idx->createSpatialIndex('hv', self::LOC);
            foreach ([$idx, $scan] as $db) {
                $get = function ($radius) use ($db) {
                    return array_column($db->withinDistance('hv', self::LOC, 0, 0, $radius, ['includeDistance' => true]), '_distance', 'key');
                };
                $this->assertSame([3 => 0.0], $get(111194.92), 'just below one degree');
                $within = $get(111194.93);
                $this->assertSame([3, 0, 1], array_keys($within), 'one degree: both, after the record at 0 m');
                $this->assertSame([0.0, 111194.93, 111194.93], array_values($within), '2 decimals');
                $this->assertCount(3, $get(20015086.79), 'the antipode is 20015086.796 m away');
                $this->assertSame([3, 0, 1, 2], array_keys($get(20015086.80)));
                $this->assertSame(20015086.8, $get(INF)[2]);
                $this->assertSame([0.0, 111194.93, 111194.93, 20015086.8], array_column($db->nearest('hv', self::LOC, 0, 0, 4), '_distance'));
            }
        }
    }

    /** @test docs: distance to the nearest point of each geometry type; 0 inside a polygon; withDistance 4 decimals */
    public function distanceIsToTheNearestPointOfEveryGeometryType(): void
    {
        $c = [29.5, 41.5];
        $geometries = [
            'polygon around'  => self::polygon(self::rect(29.25, 41.25, 29.75, 41.75)),
            'center in hole'  => self::polygon(self::rect(29.25, 41.25, 29.75, 41.75), self::rect(29.375, 41.375, 29.625, 41.625)),
            'line east'       => ['type' => 'LineString', 'coordinates' => [[29.625, 41.375], [29.625, 41.625]]],
            'line diagonal'   => ['type' => 'LineString', 'coordinates' => [[29.4, 41.2], [29.8, 41.4]]],
            'line 3d'         => ['type' => 'LineString', 'coordinates' => [[29.5, 41.48, 10], [29.6, 41.48, 20]]],
            'multipoint'      => ['type' => 'MultiPoint', 'coordinates' => [[29.5, 41.6], [29.5, 41.45], [31, 41]]],
            'multiline'       => ['type' => 'MultiLineString', 'coordinates' => [[[30, 42], [30.1, 42]], [[29.45, 41.55], [29.6, 41.55]]]],
            'multipolygon'    => ['type' => 'MultiPolygon', 'coordinates' => [[self::rect(31, 41, 31.5, 41.5)], [self::rect(29.51, 41.51, 29.52, 41.52)]]],
            'collection'      => ['type' => 'GeometryCollection', 'geometries' => [self::point(35, 35), ['type' => 'LineString', 'coordinates' => [[29.4, 41.53], [29.6, 41.53]]]]],
            'empty member'    => ['type' => 'GeometryCollection', 'geometries' => [['type' => 'GeometryCollection', 'geometries' => []], self::point(29.5, 41.52)]],
            'point 3d'        => ['type' => 'Point', 'coordinates' => [29.5, 41.49, 1234.5]],
            'empty'           => ['type' => 'GeometryCollection', 'geometries' => []],
        ];
        $names = array_keys($geometries);
        $model = [];
        foreach ($names as $key => $label) {
            if ($label !== 'empty') {
                $model[$key] = $geometries[$label];
            }
        }
        // hand checks of the model itself
        $this->assertSame(0.0, self::distance($c[0], $c[1], $geometries['polygon around']));
        $this->assertEqualsWithDelta(self::hav(29.5, 41.5, 29.5, 41.45), self::distance($c[0], $c[1], $geometries['multipoint']), 1e-9);
        $this->assertEqualsWithDelta(self::hav(29.5, 41.5, 29.5, 41.55), self::distance($c[0], $c[1], $geometries['multiline']), 0.01);

        foreach ([['shardingEnabled' => false], ['shardSize' => 4]] as $config) {
            $this->cleanTestDirectory();
            [$idx, $scan] = $this->pair($config);
            $this->insertGeometries($idx, 'dt', array_values($geometries));
            $this->assertSame(count($model), $idx->createSpatialIndex('dt', self::LOC)['indexed'], 'the empty collection is not indexed');
            $rows = $this->both($idx, $scan, function ($db) use ($c) {
                return $db->withinDistance('dt', self::LOC, $c[0], $c[1], 1000000, ['includeDistance' => true]);
            });
            $this->assertWithinDistanceMatchesModel($rows, $model, $c[0], $c[1], 1000000, 'withinDistance');
            $this->assertNotContains(array_search('empty', $names, true), array_column($rows, 'key'), 'empty collection never matches');
            $this->assertSame(0.0, array_column($rows, '_distance', 'key')[0], 'a point inside a polygon is at 0 m');

            // each geometry is found exactly when the radius reaches it
            foreach ($model as $key => $g) {
                $d = self::distance($c[0], $c[1], $g);
                $in = $this->both($idx, $scan, function ($db) use ($c, $d) {
                    return self::keys($db->withinDistance('dt', self::LOC, $c[0], $c[1], $d + 1));
                });
                $this->assertContains($key, $in, "{$names[$key]} within d + 1 m");
                if ($d > 1) {
                    $out = $this->both($idx, $scan, function ($db) use ($c, $d) {
                        return self::keys($db->withinDistance('dt', self::LOC, $c[0], $c[1], $d - 1));
                    });
                    $this->assertNotContains($key, $out, "{$names[$key]} not within d - 1 m");
                }
            }

            $near = $this->both($idx, $scan, function ($db) use ($c) {
                return $db->nearest('dt', self::LOC, $c[0], $c[1], 100);
            });
            $this->assertNearestMatchesModel($near, $model, $c[0], $c[1], 100, null, 'nearest');

            // withDistance(): measured the same way, rounded to 4 decimals; the empty collection gets none
            $all = $this->both($idx, $scan, function ($db) use ($c) {
                return $db->query('dt')->withDistance(self::LOC, $c[0], $c[1])->get();
            });
            foreach ($all as $row) {
                if (!isset($model[$row['key']])) {
                    $this->assertArrayNotHasKey('_distance', $row, 'no distance without a position');
                    continue;
                }
                $this->assertEqualsWithDelta(self::distance($c[0], $c[1], $model[$row['key']]), $row['_distance'],
                    0.00006, 'withDistance ' . $names[$row['key']]);
                $this->assertSame(round($row['_distance'], 4), $row['_distance'], '4 decimals');
            }
        }
    }

    /**
     * @test BUG CANDIDATE (docs: "Distance is measured to the nearest point of the geometry ... Circles
     * that cross the antimeridian are searched correctly"): an edge on the other side of the antimeridian
     * is measured to one of its ends, not to its nearest point, so a line 7.2 km away is reported at
     * 111.9 km and withinDistance(50 km) misses it.
     */
    public function distanceToEdgesAcrossTheAntimeridian(): void
    {
        $geometries = [
            ['type' => 'LineString', 'coordinates' => [[179.9, -1], [179.99, 1]]],
            self::polygon([[179.9, -1], [179.99, 1], [179.9, 1], [179.9, -1]]),
            ['type' => 'LineString', 'coordinates' => [[-179.99, -1], [-179.9, 1]]],
        ];
        [$idx, $scan] = $this->pair();
        $this->insertGeometries($idx, 'amd', $geometries);
        $idx->createSpatialIndex('amd', self::LOC);
        foreach ([[-179.99, 0.0], [179.99, 0.0]] as [$lon, $lat]) {
            $expected = [0, 1, 2];
            foreach ($expected as $key) {
                $this->assertLessThan(10000, self::distance($lon, $lat, $geometries[$key]), 'model: every geometry is within 10 km');
            }
            $rows = $this->both($idx, $scan, function ($db) use ($lon, $lat) {
                return $db->withinDistance('amd', self::LOC, $lon, $lat, 50000, ['includeDistance' => true]);
            });
            $this->assertSame($expected, self::keys($rows), "within 50 km of ($lon, $lat)");
            $model = array_intersect_key($geometries, array_flip($expected));
            $this->assertWithinDistanceMatchesModel($rows, $model, $lon, $lat, 50000, "($lon, $lat)");
        }
    }

    /**
     * @test BUG CANDIDATE (docs: "Distance is measured to the nearest point of the geometry"): the point of an
     * edge is chosen in a local equirectangular projection around the center, so a long edge far from the
     * center is measured to a point that isn't its nearest one (72.7 m too far at 409 km here).
     */
    public function distanceToLongEdgesIsToTheirNearestPoint(): void
    {
        $line = ['type' => 'LineString', 'coordinates' => [[0, 60], [20, 70]]];
        $polygon = self::polygon([[0, 60], [20, 70], [0, 70], [0, 60]]);
        $model = self::distance(15, 62, $line);
        $this->assertEqualsWithDelta($model, self::distance(15, 62, $polygon), 1e-6, 'model: the same nearest edge');
        $this->assertLessThan(self::hav(15, 62, 20, 70), $model, 'model: nearer than both ends');
        $this->assertLessThan(self::hav(15, 62, 0, 60), $model);
        [$idx, $scan] = $this->pair(['shardingEnabled' => false]);
        $this->insertGeometries($idx, 'le', [$line, $polygon]);
        $idx->createSpatialIndex('le', self::LOC);
        $rows = $this->both($idx, $scan, function ($db) { return $db->nearest('le', self::LOC, 15, 62, 2); });
        foreach ($rows as $row) {
            $this->assertEqualsWithDelta($model, $row['_distance'], 1.0, 'nearest: _distance of key ' . $row['key']);
        }
        $this->assertSame([0, 1], self::keys($this->both($idx, $scan, function ($db) use ($model) {
            return $db->withinDistance('le', self::LOC, 15, 62, $model + 10);
        })), 'within 10 m more than the nearest point');
    }

    /** @test docs: NaN -> [], infinite radius -> every geometry, infinite center or negative radius -> [] */
    public function withinDistanceArgumentRules(): void
    {
        foreach ([['shardingEnabled' => false], ['shardSize' => 3]] as $config) {
            $this->cleanTestDirectory();
            [$idx, $scan] = $this->pair($config);
            $this->insertGeometries($idx, 'wa', [
                self::point(0.01, 0), self::point(-0.01, 0), self::point(0.02, 0), self::point(0, 0.03), // 0-3
                ['type' => 'LineString', 'coordinates' => [1, 2]],                                        // 4 invalid
                ['type' => 'GeometryCollection', 'geometries' => []],                                     // 5 no position
                null,                                                                                     // 6 no field
                self::point(-179.99, -89.99),                                                             // 7 far away
            ]);
            $idx->createSpatialIndex('wa', self::LOC);
            $q = function (float $lon, float $lat, float $r, array $o = []) use ($idx, $scan) {
                return $this->both($idx, $scan, function ($db) use ($lon, $lat, $r, $o) {
                    return $db->withinDistance('wa', self::LOC, $lon, $lat, $r, $o);
                }, json_encode([$lon, $lat, $r]));
            };
            $this->assertSame([], $q(0, 0, -1));
            $this->assertSame([], $q(0, 0, NAN));
            $this->assertSame([], $q(NAN, 0, 1000));
            $this->assertSame([], $q(0, NAN, 1000));
            $this->assertSame([], $q(INF, 0, 1000));
            $this->assertSame([], $q(0, -INF, INF));
            $this->assertSame([], $q(0, 0, 0), 'no record at the center');
            $this->assertSame([0], self::keys($q(0.01, 0, 0)), 'radius 0: the record at the center');
            $this->assertSame([0, 1, 2, 3, 7], self::keys($q(0, 0, INF)), 'INF: every valid geometry with a position');
            $this->assertSame([0, 1, 2, 3, 7], self::keys($q(0, 0, 1e12)), 'beyond half the earth: everything');
            $default = $q(0, 0, 5000);
            $this->assertSame([0, 1, 2, 3], self::keys($default));
            foreach ($default as $row) {
                $this->assertArrayNotHasKey('_distance', $row, 'includeDistance defaults to false');
            }
            $this->assertSame([], $idx->query('wa')->withinDistance(self::LOC, 0, 0, NAN)->get());
            $this->assertSame([], $idx->query('wa')->withinDistance(self::LOC, 0, 0, -5)->get());
        }
    }

    /**
     * @test docs/SPATIAL.md nearest(): without an index every record is measured and the k nearest are returned,
     * also when there are more records than are kept while measuring (the best are kept as they come, in one
     * segment or across shards). 3000 points along the equator, each 0.001 degree further than the one before:
     * the nearest are the first keys, measured before the others.
     */
    public function nearestWithoutAnIndexKeepsTheBestOfManyRecords(): void
    {
        foreach ([['shardingEnabled' => false], ['shardSize' => 1000]] as $config) {
            $this->cleanTestDirectory();
            $scan = $this->db(array_merge($config, ['spatialIndexEnabled' => false]));
            $this->insertGeometries($scan, 'nk', array_map(function ($i) { return self::point(0.001 * ($i + 1), 0); }, range(0, 2999)));
            foreach ([1, 5, 300, 2999] as $k) {
                $rows = $scan->nearest('nk', self::LOC, 0, 0, $k);
                $this->assertSame(range(0, $k - 1), array_column($rows, 'key'), json_encode($config) . " k = $k");
            }
        }
    }

    /** @test docs: nearest() k, ordering, ties, includeDistance, maxDistance (NaN, INF, negative, 0) */
    public function nearestRules(): void
    {
        $one = self::EARTH_RADIUS * 0.01 * M_PI / 180; // 1111.9493 m: 0.01 degree
        foreach ([['shardingEnabled' => false], ['shardSize' => 3]] as $config) {
            $this->cleanTestDirectory();
            [$idx, $scan] = $this->pair($config);
            $this->insertGeometries($idx, 'nr', [
                self::point(0.01, 0),                                   // 0: 1111.95 m east
                self::point(-0.01, 0),                                  // 1: 1111.95 m west (tie)
                self::point(0.02, 0),                                   // 2: 2223.90 m
                self::point(0, 0.03),                                   // 3: 3335.85 m
                ['type' => 'Point', 'coordinates' => [200, 0]],         // 4 invalid
                ['type' => 'GeometryCollection', 'geometries' => []],   // 5 no position
                null,                                                   // 6 no field
            ]);
            $idx->createSpatialIndex('nr', self::LOC);
            $q = function (int $k, array $o = [], float $lon = 0.0, float $lat = 0.0) use ($idx, $scan) {
                return $this->both($idx, $scan, function ($db) use ($k, $o, $lon, $lat) {
                    return $db->nearest('nr', self::LOC, $lon, $lat, $k, $o);
                }, json_encode([$k, $o]));
            };
            $expected = [round($one, 2), round($one, 2), round(2 * $one, 2), round(3 * $one, 2)];
            foreach ([3, 4, 6, 10, 1000] as $k) { // below and above the record count (the index path and the scan path)
                $rows = $q($k);
                $this->assertSame(array_slice($expected, 0, min($k, 4)), array_column($rows, '_distance'), "k = $k");
                $this->assertEqualsCanonicalizing([0, 1], array_slice(array_column($rows, 'key'), 0, 2), "k = $k: the tie first");
                if ($k >= 4) {
                    $this->assertSame([2, 3], array_slice(array_column($rows, 'key'), 2), "k = $k");
                }
            }
            $first = $q(1);
            $this->assertCount(1, $first);
            $this->assertContains($first[0]['key'], [0, 1], 'k = 1: one of the two nearest');
            $this->assertSame([], $q(0));
            $this->assertSame([], $q(-1));

            foreach ($q(10, ['includeDistance' => false]) as $row) {
                $this->assertArrayNotHasKey('_distance', $row);
            }
            $this->assertSame([0, 1, 2, 3], self::keys($q(10, ['includeDistance' => false])));

            $this->assertSame([0, 1], self::keys($q(10, ['maxDistance' => 2000])), 'fewer than k within maxDistance');
            $this->assertSame([], $q(10, ['maxDistance' => 1111.9492]), 'just below 1111.94927 m');
            $this->assertSame([0, 1], self::keys($q(10, ['maxDistance' => 1111.9493])));
            $this->assertSame([0], array_column($q(10, ['maxDistance' => 1000], 0.01, 0), 'key'));
            $this->assertSame([], $q(10, ['maxDistance' => NAN]));
            $this->assertSame([], $q(10, ['maxDistance' => -1]));
            $this->assertSame([], $q(10, ['maxDistance' => 0]));
            $this->assertSame([0], array_column($q(10, ['maxDistance' => 0], 0.01, 0), 'key'), 'maxDistance 0: the record at the center');
            $this->assertSame(array_column($q(10), '_distance'), array_column($q(10, ['maxDistance' => INF]), '_distance'), 'INF: no limit');
            $this->assertSame([], $q(3, [], INF, 0));
            $this->assertSame([], $q(3, [], 0, NAN));
        }
    }

    // ==========================================================================================
    // withinBBox
    // ==========================================================================================

    /** @test docs: bounding boxes overlap or touch; antimeridian boxes; INF clamped; NaN -> [] */
    public function withinBBoxComparesBoundingBoxes(): void
    {
        $geometries = [
            self::point(0, 0),                                                                    // 0
            self::point(10, 10),                                                                  // 1 corner
            self::point(10.0000001, 5),                                                           // 2 just east of the box
            ['type' => 'LineString', 'coordinates' => [[0, 0], [10, 10]]],                         // 3 diagonal
            self::polygon(self::rect(20, 20, 30, 30), self::rect(22, 22, 28, 28)),                 // 4 with a hole
            ['type' => 'MultiPolygon', 'coordinates' => [[self::rect(40, 40, 41, 41)], [self::rect(45, 45, 46, 46)]]], // 5
            self::point(175, 5), self::point(-175, 5), self::point(180, 5), self::point(-180, 5),    // 6-9
            ['type' => 'GeometryCollection', 'geometries' => []],                                  // 10 no position
            ['type' => 'LineString', 'coordinates' => [1, 2]],                                     // 11 invalid
            ['type' => 'GeometryCollection', 'geometries' => [self::point(50, 50), ['type' => 'LineString', 'coordinates' => [[60, 60], [61, 61]]]]], // 12
            ['type' => 'Point', 'coordinates' => [0, 89.5]],                                       // 13
            ['type' => true, 'coordinates' => [3, 3]],                                             // 14 invalid type
        ];
        $cases = [
            [[0, 0, 10, 10], [0, 1, 3]],
            [[6, 0, 10, 4], [3]],                           // the diagonal's box overlaps; the line doesn't enter
            [[24, 24, 26, 26], [4]],                        // inside the hole: the box still overlaps
            [[42, 42, 44, 44], [5]],                        // between the two parts of the MultiPolygon
            [[55, 55, 56, 56], [12]],                       // between the members of the collection
            [[10, 10, 12, 12], [1, 3]],                     // touching corners
            [[10.00000005, 0, 11, 6], [2]],
            [[170, 0, -170, 10], [6, 7, 8, 9]],             // crosses the antimeridian
            [[180, 0, -180, 10], [8, 9]],                   // only the meridian itself
            [[-INF, -INF, INF, INF], [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 12, 13]],
            [[-1, 89, 1, INF], [13]],
            [[NAN, 0, 10, 10], []],
            [[0, 0, 10, NAN], []],
        ];
        foreach ([['shardingEnabled' => false], ['shardSize' => 4]] as $config) {
            $this->cleanTestDirectory();
            [$idx, $scan] = $this->pair($config);
            $this->insertGeometries($idx, 'bb', $geometries);
            $this->assertSame(12, $idx->createSpatialIndex('bb', self::LOC)['indexed']);
            foreach ($cases as [$box, $expected]) {
                $got = $this->both($idx, $scan, function ($db) use ($box) {
                    return self::keys($db->withinBBox('bb', self::LOC, ...$box));
                }, json_encode($box));
                $this->assertSame($expected, $got, json_encode($box));
                if (array_filter($box, 'is_nan') === []) {
                    $model = [];
                    foreach ($geometries as $key => $g) {
                        if (!in_array($key, [11, 14], true) && self::inBBoxQuery($g, self::bboxQueryBoxes(...$box))) {
                            $model[] = $key;
                        }
                    }
                    $this->assertSame($model, $got, 'model ' . json_encode($box));
                }
            }
            $this->assertSame([0, 1, 3], self::keys($idx->query('bb')->withinBBox(self::LOC, 0, 0, 10, 10)->get()), 'builder');
        }
    }

    /** @test docs: integer boxes in 1e-7 degree units rounded outwards: sub-grid differences still count */
    public function quantizedBoxesRoundOutwardsAndQueriesStayExact(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $quantize = function (array $box) use ($db) {
            return (function () use ($box) { return $this->quantizeBox($box); })->call(\noneDB\Tests\Support\Testing::engine($db));
        };
        $this->assertSame([12345678, -12345679, 12345679, -12345678], $quantize([1.23456789, -1.23456789, 1.23456789, -1.23456789]));
        $this->assertSame([5000000, -5000000, 5000000, -5000000], $quantize([0.5, -0.5, 0.5, -0.5]), 'grid values stay');
        // 29 + 1/1024 degrees is 290009765.625 units
        $this->assertSame([290009765, 410009765, 290009766, 410009766], $quantize([29 + 1 / 1024, 41 + 1 / 1024, 29 + 1 / 1024, 41 + 1 / 1024]));
        $this->assertSame([-1800000000, -900000000, 1800000000, 900000000], $quantize([-200, -100, 200, 100]), 'clamped to the globe');
        $this->assertSame([-1800000000, -900000000, 1800000000, 900000000], $quantize([-INF, -INF, INF, INF]));

        [$idx, $scan] = $this->pair(['shardingEnabled' => false]);
        $geometries = [
            self::point(1.23456789, -1.23456789),
            self::point(29.00000004, 41.0),
            self::point(29.00000001, 41.0),
            self::point(-29.00000004, -41.0),
        ];
        $this->insertGeometries($idx, 'qz', $geometries);
        $idx->createSpatialIndex('qz', self::LOC);
        [$header, $nodes] = $this->readRTreeFile($this->dbPath('qz', 'rx', 'loc'));
        $this->assertSame(10000000, $header['q']);
        $entries = [];
        foreach ($nodes as [$level, , $items]) {
            if ($level === 0) {
                foreach ($items as $item) {
                    $entries[$item[0]] = array_slice($item, 1);
                }
            }
        }
        ksort($entries);
        $this->assertSame([12345678, -12345679, 12345679, -12345678], $entries[0], 'stored rounded outwards');
        foreach ($entries as $key => $box) {
            foreach ($box as $v) {
                $this->assertIsInt($v);
            }
            [$x, $y] = $geometries[$key]['coordinates'];
            $this->assertLessThanOrEqual($x * 1e7, $box[0]);
            $this->assertGreaterThanOrEqual($x * 1e7, $box[2]);
            $this->assertLessThanOrEqual($y * 1e7, $box[1]);
            $this->assertGreaterThanOrEqual($y * 1e7, $box[3]);
        }
        $cases = [
            [[29.0, 40.9, 29.00000001, 41.1], [2]],              // 29.00000004 shares the grid cell, but lies east
            [[29.00000002, 40.9, 29.00000003, 41.1], []],          // between the two points
            [[29.00000004, 41.0, 29.00000004, 41.0], [1]],
            [[-29.00000005, -41.0, -29.00000004, -41.0], [3]],
            [[-29.00000003, -41.1, -29, -40.9], []],
            [[1.23456789, -1.23456789, 1.23456789, -1.23456789], [0]],
        ];
        foreach ($cases as [$box, $expected]) {
            $got = $this->both($idx, $scan, function ($db) use ($box) {
                return self::keys($db->withinBBox('qz', self::LOC, ...$box));
            });
            $this->assertSame($expected, $got, json_encode($box));
        }
    }

    // ==========================================================================================
    // withinPolygon
    // ==========================================================================================

    /** Keys of the records within $query, the indexed and the scanning instance agreeing */
    private function withinKeys(\noneDB $idx, \noneDB $scan, string $name, array $query): array
    {
        return $this->both($idx, $scan, function ($db) use ($name, $query) {
            return self::keys($db->withinPolygon($name, self::LOC, $query));
        }, json_encode($query));
    }

    /** @test docs: closed boundary on every edge, holes (touching allowed), concave polygons */
    public function withinPolygonBoundaryAndHoles(): void
    {
        $query = self::polygon(self::rect(0, 0, 10, 10), self::rect(4, 4, 6, 6));
        $cases = [
            // points: every edge and vertex of the outer ring is inside
            [self::point(10, 5), true], [self::point(5, 10), true], [self::point(10, 10), true], [self::point(0, 5), true],
            [self::point(5, 0), true], [self::point(0, 0), true], [self::point(0, 10), true], [self::point(3, 3), true],
            [self::point(10.0000001, 5), false], [self::point(-0.0000001, 5), false], [self::point(5, 10.0000001), false],
            // the hole: inside it is outside the polygon, its boundary belongs to the polygon
            [self::point(5, 5), false], [self::point(4, 5), true], [self::point(5, 4), true], [self::point(6, 6), true],
            [self::point(4.0000001, 5), false],
            // lines
            [['type' => 'LineString', 'coordinates' => [[1, 5], [4, 5]]], true],             // touches the hole
            [['type' => 'LineString', 'coordinates' => [[1, 4], [9, 4]]], true],             // along the hole's edge
            [['type' => 'LineString', 'coordinates' => [[0, 0], [10, 0], [10, 10]]], true], // along the outer boundary
            [['type' => 'LineString', 'coordinates' => [[3, 5], [9.5, 5]]], false],          // through the hole
            [['type' => 'LineString', 'coordinates' => [[1, 1], [11, 1]]], false],           // leaves
            // polygons
            [self::polygon(self::rect(1, 1, 4, 4)), true],                                     // touches a hole corner
            [self::polygon(self::rect(1, 4, 4, 6)), true],                                     // shares the hole's west edge
            [self::polygon(self::rect(0, 0, 10, 10)), false],                                  // covers the hole
            [self::polygon(self::rect(2, 2, 8, 8)), false],                                    // a ring around the hole
            [self::polygon(self::rect(2, 2, 8, 8), self::rect(4, 4, 6, 6)), true],             // the same hole
            [self::polygon(self::rect(5, 5, 8, 8)), false],                                    // a vertex in the hole
            [self::polygon(self::rect(7, 7, 10, 10)), true],
            // multi-part records: every component inside
            [['type' => 'MultiPoint', 'coordinates' => [[1, 1], [9, 9], [4, 4]]], true],
            [['type' => 'MultiPoint', 'coordinates' => [[1, 1], [5, 5]]], false],
            [['type' => 'MultiLineString', 'coordinates' => [[[1, 1], [2, 2]], [[7, 7], [9, 9]]]], true],
            [['type' => 'MultiLineString', 'coordinates' => [[[1, 1], [2, 2]], [[3, 5], [9, 5]]]], false],
            [['type' => 'MultiPolygon', 'coordinates' => [[self::rect(1, 1, 2, 2)], [self::rect(7, 7, 9, 9)]]], true],
            [['type' => 'MultiPolygon', 'coordinates' => [[self::rect(1, 1, 2, 2)], [self::rect(9, 9, 11, 11)]]], false],
            [['type' => 'GeometryCollection', 'geometries' => [self::point(1, 1), ['type' => 'LineString', 'coordinates' => [[2, 2], [3, 3]]],
                ['type' => 'GeometryCollection', 'geometries' => []]]], true],                // empty members are ignored
            [['type' => 'GeometryCollection', 'geometries' => [self::point(1, 1), self::point(5, 5)]], false],
            [['type' => 'GeometryCollection', 'geometries' => []], false],                     // never within anything
            [['type' => 'GeometryCollection', 'geometries' => [['type' => 'GeometryCollection', 'geometries' => []]]], false],
            [['type' => 'Point', 'coordinates' => [3, 3, 99]], true],                          // altitude ignored
        ];
        $concave = self::polygon([[0, 0], [10, 0], [10, 3], [3, 3], [3, 7], [10, 7], [10, 10], [0, 10], [0, 0]]); // a "C"
        $concaveCases = [
            [['type' => 'LineString', 'coordinates' => [[5, 3], [5, 7]]], false],    // a chord across the notch
            [['type' => 'LineString', 'coordinates' => [[10, 3], [10, 7]]], false],  // between two boundary vertices
            [['type' => 'LineString', 'coordinates' => [[5, 2], [5, 8]]], false],    // leaves and re-enters
            [['type' => 'LineString', 'coordinates' => [[1, 1], [1, 9]]], true],
            [['type' => 'LineString', 'coordinates' => [[3, 3], [3, 7]]], true],     // along the notch's edge
            [self::point(10, 5), false], [self::point(3, 5), true], [self::point(6.5, 3), true],
        ];
        // a zero-area hole (all its vertices on one line): nothing is excluded, its boundary belongs to the polygon
        $flatHole = self::polygon(self::rect(0, 0, 10, 10), [[2, 5], [8, 5], [5, 5], [2, 5]]);
        $flatCases = [[self::point(5, 5), true], [self::point(3, 5), true], [self::point(5, 6), true]];

        foreach ([['shardingEnabled' => false], ['shardSize' => 5]] as $config) {
            foreach ([[$query, $cases], [$concave, $concaveCases], [$flatHole, $flatCases]] as $n => [$q, $list]) {
                $this->cleanTestDirectory();
                [$idx, $scan] = $this->pair($config);
                $this->insertGeometries($idx, 'wp', array_column($list, 0));
                $idx->createSpatialIndex('wp', self::LOC);
                $expected = array_keys(array_filter(array_column($list, 1)));
                $got = $this->withinKeys($idx, $scan, 'wp', $q);
                foreach ($list as $key => [$g, $in]) {
                    $this->assertSame($in, in_array($key, $got, true), "query $n, record $key " . json_encode($g));
                }
                $this->assertSame($expected, $got);
                $this->assertSame($expected, self::keys($idx->query('wp')->withinPolygon(self::LOC, $q)->get()), 'builder');
            }
        }
        $this->assertTrue($this->db()->validateGeoJSON($flatHole)['valid'], 'setup: the flat hole is a valid polygon');
    }

    /** @test docs: MultiPolygon query: components may lie in different parts, one component in one part */
    public function withinMultiPolygonComponentRule(): void
    {
        $query = ['type' => 'MultiPolygon', 'coordinates' => [[self::rect(0, 0, 5, 5)], [self::rect(5, 0, 10, 5)], [self::rect(20, 20, 30, 30)]]];
        $cases = [
            [['type' => 'MultiPoint', 'coordinates' => [[2, 2], [7, 2], [25, 25]]], true],
            [['type' => 'LineString', 'coordinates' => [[2, 2], [7, 2]]], false],                  // spans two adjacent parts
            [['type' => 'LineString', 'coordinates' => [[5, 1], [5, 4]]], true],                   // on the shared edge
            [['type' => 'MultiLineString', 'coordinates' => [[[1, 1], [4, 1]], [[6, 1], [9, 1]]]], true],
            [['type' => 'MultiPolygon', 'coordinates' => [[self::rect(1, 1, 2, 2)], [self::rect(21, 21, 22, 22)]]], true],
            [['type' => 'MultiPolygon', 'coordinates' => [[self::rect(1, 1, 2, 2)], [self::rect(12, 12, 13, 13)]]], false],
            [self::polygon(self::rect(4, 1, 6, 2)), false],                                         // spans two parts
            [self::polygon(self::rect(21, 21, 29, 29)), true],
            [['type' => 'GeometryCollection', 'geometries' => [self::point(1, 1), self::polygon(self::rect(6, 1, 7, 2)),
                ['type' => 'LineString', 'coordinates' => [[20, 20], [30, 30]]]]], true],
            [['type' => 'GeometryCollection', 'geometries' => [self::point(1, 1), self::point(15, 15)]], false],
            [self::point(15, 15), false],
            [self::point(30, 30), true],
        ];
        $parts = array_map(function ($p) { return $p; }, $query['coordinates']);
        foreach ($cases as [$g, $in]) {
            $this->assertSame($in, self::withinConvexParts($g, $parts), 'model agrees with the hand answer: ' . json_encode($g));
        }
        foreach ([['shardingEnabled' => false], ['shardSize' => 3]] as $config) {
            $this->cleanTestDirectory();
            [$idx, $scan] = $this->pair($config);
            $this->insertGeometries($idx, 'mp', array_column($cases, 0));
            $idx->createSpatialIndex('mp', self::LOC);
            $this->assertSame(array_keys(array_filter(array_column($cases, 1))), $this->withinKeys($idx, $scan, 'mp', $query));
        }

        // docs: "A polygon can't cross the antimeridian ...; split it into a MultiPolygon at +-180"
        $pacific = ['type' => 'MultiPolygon', 'coordinates' => [[self::rect(170, -10, 180, 10)], [self::rect(-180, -10, -170, 10)]]];
        $cases = [
            [self::point(175, 0), true], [self::point(-175, 5), true], [self::point(180, 0), true], [self::point(-180, -10), true],
            [self::point(0, 0), false], [self::point(-169.9, 0), false],
            [['type' => 'MultiPoint', 'coordinates' => [[175, 0], [-175, 0]]], true],
            [['type' => 'LineString', 'coordinates' => [[179, 0], [-179, 0]]], false],   // plain coordinates: the long way round
            [['type' => 'MultiLineString', 'coordinates' => [[[171, 1], [179, 1]], [[-179, 1], [-171, 1]]]], true],
        ];
        foreach ($cases as [$g, $in]) {
            $this->assertSame($in, self::withinConvexParts($g, $pacific['coordinates']), 'model: ' . json_encode($g));
        }
        $this->cleanTestDirectory();
        [$idx, $scan] = $this->pair(['shardingEnabled' => false]);
        $this->insertGeometries($idx, 'pc', array_column($cases, 0));
        $idx->createSpatialIndex('pc', self::LOC);
        $this->assertSame(array_keys(array_filter(array_column($cases, 1))), $this->withinKeys($idx, $scan, 'pc', $pacific));
    }

    /** @test docs: a query that isn't a valid Polygon or MultiPolygon returns [] */
    public function withinPolygonRejectsInvalidQueries(): void
    {
        [$idx, $scan] = $this->pair(['shardingEnabled' => false]);
        $this->insertGeometries($idx, 'iq', [self::point(1, 1), self::point(5, 5)]);
        $idx->createSpatialIndex('iq', self::LOC);
        $valid = self::polygon(self::rect(0, 0, 10, 10));
        $this->assertSame([0, 1], $this->withinKeys($idx, $scan, 'iq', $valid), 'setup');
        $invalid = [
            'unclosed' => self::polygon([[0, 0], [10, 0], [10, 10], [0, 10]]),
            'bow-tie' => self::polygon([[0, 0], [10, 10], [10, 0], [0, 10], [0, 0]]),
            'hole outside' => self::polygon(self::rect(0, 0, 10, 10), self::rect(20, 20, 21, 21)),
            'NaN vertex' => self::polygon([[0, 0], [10, 0], [NAN, 10], [0, 10], [0, 0]]),
            'flat' => ['type' => 'Polygon', 'coordinates' => [0, 0, 10, 10]],
            'line' => ['type' => 'LineString', 'coordinates' => [[0, 0], [10, 10]]],
            'point' => self::point(1, 1),
            'collection' => ['type' => 'GeometryCollection', 'geometries' => [$valid]],
            'bad part' => ['type' => 'MultiPolygon', 'coordinates' => [$valid['coordinates'], [[[0, 0], [1, 0], [0, 0]]]]],
            'no type' => ['coordinates' => $valid['coordinates']],
            'empty' => [],
        ];
        foreach ($invalid as $label => $q) {
            $this->assertSame([], $this->withinKeys($idx, $scan, 'iq', $q), $label);
            $this->assertSame([], $idx->query('iq')->withinPolygon(self::LOC, $q)->get(), "builder $label");
        }
    }

    // ==========================================================================================
    // Query builder rules (docs: "Rules")
    // ==========================================================================================

    /** @test */
    public function builderRulesFromTheDocs(): void
    {
        $points = [];
        mt_srand(31);
        for ($i = 0; $i < 40; $i++) {
            $points[] = ['t' => $i % 3, 'name' => "p$i", self::LOC => self::point(29.5 + mt_rand(-200, 200) / 2048, 41.5 + mt_rand(-200, 200) / 2048)];
        }
        $points[] = ['t' => 0, 'name' => 'area', self::LOC => self::polygon(self::rect(29.45, 41.45, 29.55, 41.55))];
        $model = array_map(function ($r) { return $r[self::LOC]; }, $points);
        $a = [29.5, 41.5];
        $b = [29.55, 41.47];
        foreach ([['shardingEnabled' => false], ['shardSize' => 7]] as $config) {
            $this->cleanTestDirectory();
            [$idx, $scan] = $this->pair($config);
            $idx->insert('br', $points);
            $idx->createSpatialIndex('br', self::LOC);
            foreach ([$idx, $scan] as $which => $db) {
                $label = $which === 0 ? 'indexed' : 'scan';
                // the spatial filter runs first, whatever the order of the calls
                $inA = array_keys(array_filter($model, function ($g) use ($a) { return self::distance($a[0], $a[1], $g) <= 5000; }));
                $t1 = array_values(array_filter($inA, function ($k) use ($points) { return $points[$k]['t'] === 1; }));
                $x = $db->query('br')->where(['t' => 1])->withinDistance(self::LOC, $a[0], $a[1], 5000)->get();
                $y = $db->query('br')->withinDistance(self::LOC, $a[0], $a[1], 5000)->where(['t' => 1])->get();
                $this->assertSame($x, $y, "$label: call order");
                $this->assertSame($t1, self::keys($x), "$label: model");
                foreach ($x as $row) {
                    $this->assertEqualsWithDelta(self::distance($a[0], $a[1], $model[$row['key']]), $row['_distance'], 0.006, "$label: _distance of withinDistance");
                }
                // limit() and sort() apply to the spatial results, wherever they were called
                $t1ByDistance = [];
                foreach ($t1 as $k) {
                    $t1ByDistance[$k] = self::distance($a[0], $a[1], $model[$k]);
                }
                asort($t1ByDistance);
                $limited = $db->query('br')->limit(3)->sort('_distance', 'asc')->where(['t' => 1])->withinDistance(self::LOC, $a[0], $a[1], 5000)->get();
                $this->assertSame(array_slice(array_keys($t1ByDistance), 0, 3), array_column($limited, 'key'), "$label: limit after the spatial filter");

                // nearest() takes its k after the other filters
                $byDistance = [];
                foreach ($model as $k => $g) {
                    if ($points[$k]['t'] === 2) {
                        $byDistance[$k] = self::distance($b[0], $b[1], $g);
                    }
                }
                asort($byDistance);
                $near = $db->query('br')->nearest(self::LOC, $b[0], $b[1], 4)->where(['t' => 2])->get();
                $this->assertSame(array_slice(array_keys($byDistance), 0, 4), array_column($near, 'key'), "$label: k after where()");

                // withinDistance(A)->nearest(B): ordered and cut by the distance to B, _distance to B
                $candidates = [];
                foreach ($inA as $k) {
                    $candidates[$k] = self::distance($b[0], $b[1], $model[$k]);
                }
                asort($candidates);
                foreach ([
                    'A then B' => $db->query('br')->withinDistance(self::LOC, $a[0], $a[1], 5000)->nearest(self::LOC, $b[0], $b[1], 5)->get(),
                    'B then A' => $db->query('br')->nearest(self::LOC, $b[0], $b[1], 5)->withinDistance(self::LOC, $a[0], $a[1], 5000)->get(),
                ] as $order => $rows) {
                    $this->assertSame(array_slice(array_keys($candidates), 0, 5), array_column($rows, 'key'), "$label $order");
                    foreach ($rows as $row) {
                        $this->assertEqualsWithDelta($candidates[$row['key']], $row['_distance'], 0.006, "$label $order: distance to B");
                    }
                }

                // withDistance() replaces _distance (4 decimals), is computed before sort(); select() keeps it
                $rows = $db->query('br')->withinDistance(self::LOC, $a[0], $a[1], 5000)->withDistance(self::LOC, $b[0], $b[1])->sort('_distance', 'desc')->get();
                $this->assertSame($inA, self::keys($rows), "$label: withDistance doesn't filter");
                $previous = INF;
                foreach ($rows as $row) {
                    $this->assertEqualsWithDelta(self::distance($b[0], $b[1], $model[$row['key']]), $row['_distance'],
                        $row['name'] === 'area' ? 0.5 : 0.00006, "$label: withDistance replaced _distance");
                    $this->assertLessThanOrEqual($previous, $row['_distance'], "$label: sorted by withDistance's _distance");
                    $previous = $row['_distance'];
                }
                $selected = $db->query('br')->withinDistance(self::LOC, $a[0], $a[1], 5000)->select(['name'])->get();
                $this->assertNotEmpty($selected);
                foreach ($selected as $row) {
                    $this->assertSame(['name', 'key'], array_keys($row), "$label: select() drops withinDistance's _distance");
                }
                foreach ($db->query('br')->nearest(self::LOC, $a[0], $a[1], 3)->select(['name'])->get() as $row) {
                    $this->assertSame(['name', 'key'], array_keys($row), "$label: select() drops nearest's _distance");
                }
                foreach ($db->query('br')->withinDistance(self::LOC, $a[0], $a[1], 5000)->withDistance(self::LOC, $b[0], $b[1])->select(['name'])->get() as $row) {
                    $this->assertSame(['name', 'key', '_distance'], array_keys($row), "$label: select() keeps withDistance's _distance");
                }
                $area = $db->query('br')->where(['name' => 'area'])->withDistance(self::LOC, $a[0], $a[1])->first();
                $this->assertSame(0.0, $area['_distance'], "$label: 0 inside a polygon");

                // several spatial filters must all match; a later withinDistance() adds its _distance
                $box = [29.45, 41.45, 29.52, 41.6];
                $inBox = array_keys(array_filter($model, function ($g) use ($box) { return self::inBBoxQuery($g, [$box]); }));
                $both = $db->query('br')->withinBBox(self::LOC, ...$box)->withinDistance(self::LOC, $a[0], $a[1], 3000)->get();
                $within3 = array_keys(array_filter($model, function ($g) use ($a) { return self::distance($a[0], $a[1], $g) <= 3000; }));
                $this->assertSame(array_values(array_intersect($inBox, $within3)), self::keys($both), "$label: bbox AND distance");
                foreach ($both as $row) {
                    $this->assertEqualsWithDelta(self::distance($a[0], $a[1], $model[$row['key']]), $row['_distance'], 0.006, "$label: _distance of the later withinDistance()");
                }
                $poly = self::polygon(self::rect(29.4, 41.4, 29.5, 41.6));
                $inPoly = array_keys(array_filter($model, function ($g) use ($poly) { return self::withinConvexParts($g, [$poly['coordinates']]); }));
                $this->assertSame(array_values(array_intersect($inBox, $inPoly)),
                    self::keys($db->query('br')->withinBBox(self::LOC, ...$box)->withinPolygon(self::LOC, $poly)->get()), "$label: bbox AND polygon");

                // k = 0 (or negative) returns nothing; count()/exists()/first() agree with get()
                $this->assertSame([], $db->query('br')->nearest(self::LOC, $a[0], $a[1], 0)->get(), "$label: k = 0");
                $this->assertSame([], $db->query('br')->nearest(self::LOC, $a[0], $a[1], -2)->get(), "$label: k < 0");
                $this->assertSame(count($y), $db->query('br')->withinDistance(self::LOC, $a[0], $a[1], 5000)->where(['t' => 1])->count(), "$label: count()");
                $this->assertTrue($db->query('br')->withinDistance(self::LOC, $a[0], $a[1], 5000)->exists());
                $this->assertFalse($db->query('br')->withinDistance(self::LOC, 0, 0, 5000)->exists());
                $this->assertSame(array_keys($byDistance)[0], $db->query('br')->nearest(self::LOC, $b[0], $b[1], 1)->where(['t' => 2])->first()['key'], "$label: first()");
            }
        }
    }

    // ==========================================================================================
    // Index management
    // ==========================================================================================

    /** @test docs: return shapes and errors of createSpatialIndex / dropSpatialIndex / rebuildSpatialIndex */
    public function indexManagementResultsAndErrors(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $this->insertGeometries($db, 'mg', [
            self::point(1, 1), self::point(2, 2),
            null,                                                    // missing field
            'not array',                                             // not an array
            ['x' => 1],                                              // missing type
            self::point(200, 100),                                   // lon > 180
            ['type' => 'LineString', 'coordinates' => [1, 2]],       // flat coordinates
            ['type' => 'GeometryCollection', 'geometries' => []],   // valid, no location: never indexed
        ]);
        $this->assertSame(['success' => true, 'indexed' => 2, 'error' => null], $db->createSpatialIndex('mg', self::LOC));
        $this->assertSame(['success' => false, 'indexed' => 0, 'error' => "Spatial index already exists for field 'loc'"], $db->createSpatialIndex('mg', self::LOC));
        $this->assertSame([self::LOC], $db->getSpatialIndexes('mg'));
        $this->assertTrue($db->hasSpatialIndex('mg', self::LOC));
        $this->assertFalse($db->hasSpatialIndex('mg', self::LOC, 0), 'a database without shards has no shard 0');
        $this->assertFalse($db->hasSpatialIndex('mg', 'other'));
        foreach (['', 'key'] as $field) {
            $this->assertSame(['success' => false, 'indexed' => 0, 'error' => 'Invalid field name'], $db->createSpatialIndex('mg', $field));
        }

        $disabled = $this->db(['shardingEnabled' => false, 'spatialIndexEnabled' => false]);
        $this->assertSame(['success' => false, 'indexed' => 0, 'error' => 'Spatial indexing is disabled'], $disabled->createSpatialIndex('mg', 'other'));
        $this->assertSame(['success' => false, 'indexed' => 0, 'error' => 'Spatial indexing is disabled'], $disabled->rebuildSpatialIndex('mg', self::LOC));
        $this->assertSame([self::LOC], $disabled->getSpatialIndexes('mg'), 'existing indexes are kept');
        $this->assertSame([0, 1], self::keys($disabled->withinBBox('mg', self::LOC, 0, 0, 3, 3)), 'disabled: queries scan');

        $this->assertSame(['success' => true, 'indexed' => 2, 'error' => null], $db->rebuildSpatialIndex('mg', self::LOC));
        $this->assertSame(['success' => true, 'indexed' => 0, 'error' => null], $db->rebuildSpatialIndex('mg', 'other'), 'a field without an index gets one');
        $this->assertSame([self::LOC, 'other'], $db->getSpatialIndexes('mg'));
        $this->assertSame(['success' => true, 'error' => null], $db->dropSpatialIndex('mg', 'other'));
        $this->assertSame(['success' => true, 'error' => null], $db->dropSpatialIndex('mg', 'other'), 'also when there was no such index');
        $this->assertSame(['success' => true, 'error' => null], $db->dropSpatialIndex('mg', self::LOC));
        $this->assertSame([], $db->getSpatialIndexes('mg'));
        $this->assertFalse($db->hasSpatialIndex('mg', self::LOC));
        $this->assertFileDoesNotExist($this->dbPath('mg', 'rx', 'loc'));
        $this->assertSame([0, 1], self::keys($db->withinBBox('mg', self::LOC, 0, 0, 3, 3)), 'no index: a scan');

        // a missing database: created with autoCreateDB, an error without
        $strict = $this->db(['autoCreateDB' => false]);
        $this->assertSame(['success' => false, 'indexed' => 0, 'error' => 'Database does not exist'], $strict->createSpatialIndex('nodb', self::LOC));
        $this->assertSame([], $strict->getSpatialIndexes('nodb'));
        $this->assertSame(['success' => true, 'indexed' => 0, 'error' => null], $db->createSpatialIndex('newdb', self::LOC));
        $this->assertSame([self::LOC], $db->getSpatialIndexes('newdb'));

        // any field name; documented file name token (STORAGE_FORMAT.md §5.1: spatial/{segment}.{token}.rx)
        $this->assertTrue($db->createSpatialIndex('mg', 'home-loc')['success']);
        $this->assertTrue($db->createSpatialIndex('mg', 'konum.ev')['success']);
        $this->assertFileExists($this->dbPath('mg', 'gen') . 'spatial/main.home_loc-17e07f2f0d56.rx');

        // index file names hold no database name (STORAGE_FORMAT.md §4, §5.1): a long name indexes any field
        $field = str_repeat('b', 40);
        $ok = str_repeat('a', 158);
        $this->assertSame(['success' => true, 'indexed' => 0, 'error' => null], $db->createSpatialIndex($ok, $field));
        $long = str_repeat('a', 159);
        $this->assertSame(['success' => true, 'indexed' => 0, 'error' => null], $db->createSpatialIndex($long, $field));
        $this->assertSame([$field], $db->getSpatialIndexes($long));
        $this->assertTrue($db->createSpatialIndex($long, 'loc')['success'], 'a second field');
        $this->assertSame([$field, 'loc'], $db->getSpatialIndexes($long));
    }

    /** @test docs: hasSpatialIndex with a shard id: the index exists and the database has that shard */
    public function hasSpatialIndexWithShardIds(): void
    {
        $db = $this->db(['shardSize' => 5]);
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = [self::LOC => self::point($i, $i)];
        }
        $db->insert('hs', $rows);
        $this->assertFalse($db->hasSpatialIndex('hs', self::LOC, 0));
        $db->createSpatialIndex('hs', self::LOC);
        $this->assertTrue($db->hasSpatialIndex('hs', self::LOC));
        foreach ([0, 1, 2] as $shard) {
            $this->assertTrue($db->hasSpatialIndex('hs', self::LOC, $shard), "shard $shard");
            $this->assertFileExists($this->dbPath('hs', 'rx', 'loc', $shard));
        }
        $this->assertFalse($db->hasSpatialIndex('hs', self::LOC, 3));
        $this->assertFalse($db->hasSpatialIndex('hs', 'other', 0));
    }

    /** @test CHANGES 9: spatial reads of a database that doesn't exist return nothing and create nothing */
    public function spatialReadsOfAMissingDatabaseCreateNothing(): void
    {
        $db = $this->db();
        $before = array_values(array_diff(scandir($this->testDbDir), ['.', '..']));
        $this->assertSame([], $db->withinDistance('ghost', self::LOC, 0, 0, 1000));
        $this->assertSame([], $db->withinBBox('ghost', self::LOC, -1, -1, 1, 1));
        $this->assertSame([], $db->nearest('ghost', self::LOC, 0, 0, 3));
        $this->assertSame([], $db->withinPolygon('ghost', self::LOC, self::polygon(self::rect(0, 0, 1, 1))));
        $this->assertSame([], $db->query('ghost')->withinDistance(self::LOC, 0, 0, 1000)->withDistance(self::LOC, 0, 0)->get());
        $this->assertFalse($db->hasSpatialIndex('ghost', self::LOC));
        $this->assertSame([], $db->getSpatialIndexes('ghost'));
        clearstatcache();
        $this->assertSame($before, array_values(array_diff(scandir($this->testDbDir), ['.', '..'])));
    }

    /** @test docs: invalid GeoJSON is stored, never indexed or matched, and blocks nothing */
    public function invalidGeometriesAreKeptAndNeverBlockTheDatabase(): void
    {
        $bad = [
            ['type' => 'LineString', 'coordinates' => [1, 2]],
            ['type' => 'MultiPoint', 'coordinates' => ['a' => [1, 2]]],
            ['type' => true, 'coordinates' => [1, 2]],
            ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0], [0, 1]]]],
            ['type' => 'Point', 'coordinates' => ['1', '2']],
            ['type' => 'GeometryCollection', 'geometries' => [5]],
        ];
        foreach ([['shardingEnabled' => false], ['shardSize' => 2]] as $config) {
            $this->cleanTestDirectory();
            [$idx, $scan] = $this->pair($config);
            $idx->createSpatialIndex('ib', self::LOC);
            $this->insertGeometries($idx, 'ib', array_merge($bad, [self::point(1, 1)]));
            foreach ([$idx, $scan] as $db) {
                $this->assertSame([6], self::keys($db->withinBBox('ib', self::LOC, -10, -10, 10, 10)));
                $this->assertSame([6], self::keys($db->withinDistance('ib', self::LOC, 1, 1, 1e6)));
                $this->assertSame([6], self::keys($db->nearest('ib', self::LOC, 1, 1, 10)));
                $this->assertSame([6], self::keys($db->withinPolygon('ib', self::LOC, self::polygon(self::rect(-10, -10, 10, 10)))));
            }
            $this->assertCount(7, $idx->find('ib', 0), 'such records are kept');
            $this->assertSame(['success' => true, 'indexed' => 1, 'error' => null], $idx->rebuildSpatialIndex('ib', self::LOC));
            $this->assertSame(1, $idx->update('ib', [['key' => 0], ['set' => ['n' => 'x']]])['n']);
            $this->assertSame(1, $idx->delete('ib', ['key' => 1])['n']);
            $this->assertTrue($idx->checkIntegrity('ib')['ok']);
            $this->assertTrue($idx->dropDB('ib')['success']);
        }
    }

    /**
     * @test docs (Validation Cost): inserts and updates don't validate; geometries are validated when an
     * R-tree is built or adds new data lines (the first query of a process after a write), and by queries
     * that don't find them in an R-tree
     */
    public function geometriesAreValidatedWhenTheTreeTakesThemNotOnWrite(): void
    {
        [$idx, $scan] = $this->pair(['shardingEnabled' => false]);
        $idx->createSpatialIndex('vc', self::LOC);
        $idx->withinBBox('vc', self::LOC, 0, 0, 1, 1); // the (empty) tree is loaded
        $polygons = [];
        for ($i = 0; $i < 10; $i++) {
            $polygons[] = self::polygon(self::rect($i, 0, $i + 0.5, 0.5));
        }
        $before = $this->stat('geometryValidations');
        $this->insertGeometries($idx, 'vc', $polygons);
        $this->assertSame(0, $this->stat('geometryValidations') - $before, 'insert: no validation');
        $this->assertCount(10, $idx->withinBBox('vc', self::LOC, -1, -1, 11, 1));
        $this->assertSame(10, $this->stat('geometryValidations') - $before, 'the first query validates the 10 new lines once');
        $before = $this->stat('geometryValidations');
        $this->assertCount(10, $idx->withinBBox('vc', self::LOC, -1, -1, 11, 1));
        $this->assertSame(0, $this->stat('geometryValidations') - $before, 'candidates of the tree are not validated again');
        $this->assertCount(3, $idx->withinPolygon('vc', self::LOC, self::polygon(self::rect(-1, -1, 2.5, 1))));
        $this->assertSame(1, $this->stat('geometryValidations') - $before, 'withinPolygon(): only the query polygon is validated');
        $before = $this->stat('geometryValidations');
        $this->assertSame(1, $idx->update('vc', [['key' => 3], ['set' => [self::LOC => self::polygon(self::rect(20, 0, 21, 1))]]])['n']);
        $this->assertSame(0, $this->stat('geometryValidations') - $before, 'update: no validation');
        $this->assertSame([3], self::keys($idx->withinBBox('vc', self::LOC, 19, -1, 22, 2)));
        $this->assertSame(1, $this->stat('geometryValidations') - $before, 'the changed line, once');
        $before = $this->stat('geometryValidations');
        $this->assertCount(10, $scan->withinBBox('vc', self::LOC, -1, -1, 22, 2));
        $this->assertSame(10, $this->stat('geometryValidations') - $before, 'a scan validates every record');
    }

    // ==========================================================================================
    // R-tree format
    // ==========================================================================================

    /** [header, nodes] of an R-tree file */
    private function readTree(string $path): array
    {
        return $this->readRTreeFile($path);
    }

    /** Entries per leaf, root level and children of the root */
    private function treeShape(array $nodes, int $root): array
    {
        $leaves = [];
        foreach ($nodes as [$level, , $items]) {
            if ($level === 0) {
                $leaves[] = count($items);
            }
        }
        sort($leaves);
        return ['leaves' => $leaves, 'rootLevel' => $nodes[$root][0], 'rootChildren' => count($nodes[$root][2])];
    }

    /**
     * Structure of an in-memory R-tree: root a leaf or with 2+ children, every node at most $nodeSize items,
     * boxes the union of their items, levels decreasing by one, every key in one leaf
     */
    private function assertTreeIsSound(array $tree, int $nodeSize, array $expectedKeys, string $label): void
    {
        $root = $tree['nodes'][$tree['root']];
        if ($root[0] > 0) {
            $this->assertGreaterThanOrEqual(2, count($root[2]), "$label: an inner root has 2+ children");
        }
        $seen = [];
        $visited = 0;
        $stack = [$tree['root']];
        while ($stack) {
            $id = array_pop($stack);
            $visited++;
            [$level, $box, $items] = $tree['nodes'][$id];
            $this->assertLessThanOrEqual($nodeSize, count($items), "$label: node $id holds at most nodeSize entries");
            $union = null;
            foreach ($items as $item) {
                if ($level === 0) {
                    $itemBox = array_slice($item, 1);
                    $this->assertArrayNotHasKey($item[0], $seen, "$label: key {$item[0]} once");
                    $seen[$item[0]] = true;
                } else {
                    $this->assertSame($level - 1, $tree['nodes'][$item][0], "$label: child level");
                    $itemBox = $tree['nodes'][$item][1];
                    $stack[] = $item;
                }
                $union = $union === null ? $itemBox : [min($union[0], $itemBox[0]), min($union[1], $itemBox[1]), max($union[2], $itemBox[2]), max($union[3], $itemBox[3])];
            }
            $this->assertSame($union, $box, "$label: box of node $id");
        }
        $this->assertSame(count($tree['nodes']), $visited, "$label: no unreachable node");
        $keys = array_keys($seen);
        sort($keys);
        $this->assertSame($expectedKeys, $keys, "$label: keys");
        $this->assertSame(count($expectedKeys), $tree['cnt'], "$label: count");
    }

    private function loadedTree(\noneDB $db, string $name): array
    {
        return (function () use ($name) {
            return $this->withReadLock($name, function () use ($name) {
                return $this->readSpatialIndex($name, 'loc');
            });
        })->call(\noneDB\Tests\Support\Testing::engine($db));
    }

    /** @test docs: STR bulk load fills nodes to 3/4; nodeSize option (>= 4) kept by rebuilds */
    public function bulkLoadFillsThreeQuartersAndNodeSizeIsKept(): void
    {
        $grid = function (int $n): array {
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = self::point(29 + ($i % 12) / 64, 41 + intdiv($i, 12) / 64);
            }
            return $out;
        };
        $db = $this->db(['shardingEnabled' => false]);
        // default nodeSize 32: 96 entries in 4 leaves of 24, one root above them
        $this->insertGeometries($db, 'st', $grid(96));
        $this->assertSame(['success' => true, 'indexed' => 96, 'error' => null], $db->createSpatialIndex('st', self::LOC));
        [$header, $nodes] = $this->readTree($this->dbPath('st', 'rx', 'loc'));
        $this->assertSame(32, $header['ns']);
        $this->assertSame(['leaves' => [24, 24, 24, 24], 'rootLevel' => 1, 'rootChildren' => 4], $this->treeShape($nodes, $header['root']));

        // nodeSize 16: leaves of 12
        $this->insertGeometries($db, 'st16', $grid(48));
        $db->createSpatialIndex('st16', self::LOC, ['nodeSize' => 16]);
        $path16 = $this->dbPath('st16', 'rx', 'loc');
        [$header, $nodes] = $this->readTree($path16);
        $this->assertSame(16, $header['ns']);
        $this->assertSame([12, 12, 12, 12], $this->treeShape($nodes, $header['root'])['leaves']);
        $this->assertSame(['success' => true, 'indexed' => 48, 'error' => null], $db->rebuildSpatialIndex('st16', self::LOC));
        $this->assertSame(16, $this->readTree($path16)[0]['ns'], 'rebuildSpatialIndex keeps nodeSize');
        \noneDB::clearStaticCache();
        unlink($path16);
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertCount(48, $db->withinBBox('st16', self::LOC, 28, 40, 30, 42));
        $this->assertSame(16, $this->readTree($path16)[0]['ns'], 'an index built again after a loss keeps nodeSize');

        // below 4: refused, nothing made (API.md "Options are the documented ones"); without the option: 32; 4 is
        // accepted: leaves of 3
        $this->insertGeometries($db, 'st3', $grid(12));
        try {
            $db->createSpatialIndex('st3', self::LOC, ['nodeSize' => 3]);
            $this->fail('nodeSize 3 accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("'nodeSize' must be an integer of at least 4", $e->getMessage());
        }
        $this->assertSame([], $db->getSpatialIndexes('st3'), 'nothing made');
        $db->createSpatialIndex('st3', self::LOC);
        $this->assertSame(32, $this->readTree($this->dbPath('st3', 'rx', 'loc'))[0]['ns']);
        $db->rebuildSpatialIndex('st3', self::LOC);
        $this->assertSame(32, $this->readTree($this->dbPath('st3', 'rx', 'loc'))[0]['ns']);
        $this->insertGeometries($db, 'st4', $grid(12));
        $db->createSpatialIndex('st4', self::LOC, ['nodeSize' => 4]);
        [$header, $nodes] = $this->readTree($this->dbPath('st4', 'rx', 'loc'));
        $this->assertSame(4, $header['ns']);
        $this->assertSame([3, 3, 3, 3], $this->treeShape($nodes, $header['root'])['leaves']);

        // every tree answers like the model
        foreach (['st' => 96, 'st16' => 48, 'st3' => 12, 'st4' => 12] as $name => $n) {
            $model = $grid($n);
            foreach ([[29, 41, 29.05, 41.05], [29.1, 41.02, 29.2, 41.2], [28, 40, 30, 42]] as $box) {
                $expected = array_keys(array_filter($model, function ($g) use ($box) { return self::inBBoxQuery($g, [$box]); }));
                $this->assertSame($expected, self::keys($db->withinBBox($name, self::LOC, ...$box)), "$name " . json_encode($box));
            }
        }
    }

    /** @test the R-tree follows inserts (splits, identical boxes), deletes (root collapse) and inserts again */
    public function rtreeStaysSoundThroughSplitsAndRootCollapse(): void
    {
        // no compaction: the tombstones are replayed into the loaded tree instead of a rebuild
        $config = ['shardingEnabled' => false, 'autoCompactThreshold' => 1000];
        $db = $this->db($config);
        $scan = $this->db($config + ['spatialIndexEnabled' => false]);
        $db->createSpatialIndex('rc', self::LOC, ['nodeSize' => 4]);
        $model = [];
        $check = function (string $label) use (&$db, $scan, &$model) {
            foreach ([[0, 0, 100, 100], [10, 10, 10, 10], [0, 0, 5, 5], [40, 40, 60, 60]] as $box) {
                $expected = array_keys(array_filter($model, function ($g) use ($box) { return self::inBBoxQuery($g, [$box]); }));
                $this->assertSame($expected, self::keys($this->both($db, $scan, function ($d) use ($box) { return $d->withinBBox('rc', self::LOC, ...$box); })), "$label " . json_encode($box));
            }
            $this->assertTreeIsSound($this->loadedTree($db, 'rc'), 4, array_keys($model), $label);
        };
        // identical boxes overflow a node: the split must still divide them
        for ($i = 0; $i < 9; $i++) {
            $db->insert('rc', [self::LOC => self::point(10, 10)]);
            $model[$i] = self::point(10, 10);
        }
        $check('9 identical points');
        mt_srand(3);
        for ($i = 9; $i < 120; $i++) {
            $g = self::point(mt_rand(0, 100), mt_rand(0, 80));
            $db->insert('rc', [self::LOC => $g]);
            $model[$i] = $g;
            if ($i % 37 === 0) {
                $check("insert $i");
            }
        }
        $check('120 inserted');
        // rebuilt by bulk loading, then emptied through the replayed tombstones: the root collapses
        $db->rebuildSpatialIndex('rc', self::LOC);
        $check('bulk loaded');
        $keys = array_keys($model);
        foreach (array_slice($keys, 0, 119) as $n => $key) {
            $db->delete('rc', ['key' => $key]);
            unset($model[$key]);
            if ($n % 40 === 0 || $n > 112) {
                $check("deleted $n");
            }
        }
        $tree = $this->loadedTree($db, 'rc');
        $this->assertSame(0, $tree['nodes'][$tree['root']][0], 'one record left: the root is a leaf');
        $db->delete('rc', ['key' => 119]);
        unset($model[119]);
        $check('empty');
        for ($i = 0; $i < 30; $i++) {
            $g = self::point(50 + $i / 10, 50);
            $db->insert('rc', [self::LOC => $g]);
            $model[120 + $i] = $g;
        }
        $check('grown again');
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $check('after clearStaticCache');
    }

    // ==========================================================================================
    // Index, scan and model through the whole life of a database
    // ==========================================================================================

    private const G = 1 / 256; // grid of the generated coordinates (exact binary fractions)

    private function gx(): float
    {
        return 29 + mt_rand(0, 256) * self::G;
    }

    private function gy(): float
    {
        return 41 + mt_rand(0, 256) * self::G;
    }

    /** A random record value: [present, value, valid] */
    private function randomValue(): array
    {
        $r = mt_rand(0, 99);
        $x = $this->gx();
        $y = $this->gy();
        $d = function () { return mt_rand(1, 6) * self::G; };
        if ($r < 25) {
            return [true, self::point($x, $y), true];
        }
        if ($r < 30) {
            return [true, ['type' => 'Point', 'coordinates' => [$x, $y, 12.5]], true];
        }
        if ($r < 34) {
            // far away: near the antimeridian or a pole
            $edge = [[180, $y], [-180, $y], [179.75, $y], [-179.75, $y], [$x, 89.75], [$x - 180, 89.875]][mt_rand(0, 5)];
            return [true, self::point($edge[0], $edge[1]), true];
        }
        if ($r < 46) {
            $coords = [[$x, $y]];
            for ($i = mt_rand(1, 3); $i > 0; $i--) {
                $coords[] = [$x + mt_rand(-4, 4) * self::G, $y + mt_rand(-4, 4) * self::G];
            }
            return [true, ['type' => 'LineString', 'coordinates' => $coords], true];
        }
        if ($r < 58) {
            $w = $d() + 2 * self::G;
            $h = $d() + 2 * self::G;
            $rings = [self::rect($x, $y, $x + $w, $y + $h)];
            if (mt_rand(0, 2) === 0) {
                $rings[] = self::rect($x + self::G, $y + self::G, $x + $w - self::G, $y + $h - self::G);
            }
            return [true, ['type' => 'Polygon', 'coordinates' => $rings], true];
        }
        if ($r < 64) {
            return [true, ['type' => 'MultiPoint', 'coordinates' => [[$x, $y], [$this->gx(), $this->gy()]]], true];
        }
        if ($r < 70) {
            return [true, ['type' => 'MultiLineString', 'coordinates' => [[[$x, $y], [$x + $d(), $y]], [[$x, $y + $d()], [$x + $d(), $y + 2 * $d()]]]], true];
        }
        if ($r < 76) {
            $x2 = $this->gx();
            $y2 = $this->gy();
            return [true, ['type' => 'MultiPolygon', 'coordinates' => [[self::rect($x, $y, $x + $d(), $y + $d())], [self::rect($x2, $y2, $x2 + $d(), $y2 + $d())]]], true];
        }
        if ($r < 84) {
            $members = [[], [self::point($x, $y), ['type' => 'LineString', 'coordinates' => [[$x, $y], [$x + $d(), $y + $d()]]]],
                [['type' => 'GeometryCollection', 'geometries' => []], self::polygon(self::rect($x, $y, $x + $d(), $y + $d()))],
                [self::point($x, $y), self::point($this->gx(), $this->gy())]][mt_rand(0, 3)];
            return [true, ['type' => 'GeometryCollection', 'geometries' => $members], true];
        }
        switch (mt_rand(0, 6)) {
            case 0:
                return [false, null, false];                                                  // no field
            case 1:
                return [true, ['type' => 'LineString', 'coordinates' => [$x, $y]], false];    // flat
            case 2:
                return [true, self::point($x + 200, $y), false];                               // lon > 180
            case 3:
                return [true, ['type' => true, 'coordinates' => [$x, $y]], false];
            case 4:
                return [true, ['type' => 'Polygon', 'coordinates' => [[[$x, $y], [$x + 1, $y], [$x + 1, $y + 1], [$x, $y + 1]]]], false]; // unclosed
            case 5:
                return [true, ['type' => 'MultiPoint', 'coordinates' => ['a' => [$x, $y]]], false];
            default:
                return [true, null, false];                                                    // null
        }
    }

    /** Every kind of query on both instances, compared with each other and with the model */
    private function checkLife(\noneDB $idx, \noneDB $scan, string $name, array $model, string $label): void
    {
        $valid = [];
        foreach ($model as $key => [, $value, $isValid]) {
            if ($isValid) {
                $valid[$key] = $value;
            }
        }
        // the model's bookkeeping matches what is stored
        $stored = array_column($idx->find($name, 0), null, 'key');
        ksort($stored);
        $this->assertSame(array_keys($model), array_keys($stored), "$label: keys");
        foreach ($model as $key => [$present, $value]) {
            $this->assertSame($present ? $value : null, $stored[$key][self::LOC] ?? null, "$label: stored value $key");
        }

        $boxes = [[170, 41, -170, 42], [-INF, -INF, INF, INF], [29.25, 41.25, 29.25, 41.75]];
        for ($i = 0; $i < 6; $i++) {
            $x = $this->gx() - 0.1;
            $y = $this->gy() - 0.1;
            $boxes[] = [$x, $y, $x + mt_rand(0, 80) * self::G, $y + mt_rand(0, 80) * self::G];
        }
        foreach ($boxes as $box) {
            $got = $this->both($idx, $scan, function ($db) use ($name, $box) { return self::keys($db->withinBBox($name, self::LOC, ...$box)); }, "$label bbox");
            $expected = array_keys(array_filter($valid, function ($g) use ($box) { return self::inBBoxQuery($g, self::bboxQueryBoxes(...$box)); }));
            $this->assertSame($expected, $got, "$label: withinBBox " . json_encode($box));
        }

        $centers = [[$this->gx(), $this->gy()], [$this->gx(), $this->gy()], [$this->gx(), $this->gy()], [-179.875, 41.5], [179.875, 41.25], [12.5, 89.9]];
        foreach ($centers as $n => [$lon, $lat]) {
            $radius = (float)mt_rand(0, 40000);
            $rows = $this->both($idx, $scan, function ($db) use ($name, $lon, $lat, $radius) {
                return $db->withinDistance($name, self::LOC, $lon, $lat, $radius, ['includeDistance' => true]);
            }, "$label distance");
            $this->assertWithinDistanceMatchesModel($rows, $valid, $lon, $lat, $radius, "$label: withinDistance($lon, $lat, $radius)");
            if ($n < 3) {
                foreach ([[1, null], [7, null], [25, 4000.0], [1000, null]] as [$k, $max]) {
                    $options = $max === null ? [] : ['maxDistance' => $max];
                    $near = $this->both($idx, $scan, function ($db) use ($name, $lon, $lat, $k, $options) {
                        return $db->nearest($name, self::LOC, $lon, $lat, $k, $options);
                    }, "$label nearest");
                    $this->assertNearestMatchesModel($near, $valid, $lon, $lat, $k, $max, "$label: nearest($lon, $lat, $k, $max)");
                }
            }
        }

        $polygons = [];
        for ($i = 0; $i < 3; $i++) {
            $x = $this->gx() - 0.2;
            $y = $this->gy() - 0.2;
            $polygons[] = [self::rect($x, $y, $x + mt_rand(1, 100) * self::G, $y + mt_rand(1, 100) * self::G)];
        }
        $polygons[] = [[[29, 41], [30, 41], [29.5, 42], [29, 41]]];                         // a triangle
        $queries = [];
        foreach ($polygons as $rings) {
            $queries[] = [self::polygon(...$rings), [$rings]];
        }
        $queries[] = [['type' => 'MultiPolygon', 'coordinates' => [$polygons[0], $polygons[1]]], [$polygons[0], $polygons[1]]];
        foreach ($queries as [$query, $parts]) {
            $got = $this->withinKeys($idx, $scan, $name, $query);
            $expected = array_keys(array_filter($valid, function ($g) use ($parts) { return self::withinConvexParts($g, $parts); }));
            $this->assertSame($expected, $got, "$label: withinPolygon " . json_encode($query));
        }
    }

    /** @test */
    public function indexScanAndModelAgreeThroughWritesCompactionAndRestarts(): void
    {
        foreach (['plain' => ['shardingEnabled' => false], 'sharded' => ['shardSize' => 30]] as $label => $config) {
            $this->cleanTestDirectory();
            mt_srand(20261027);
            $name = "life $label";
            [$idx, $scan] = $this->pair($config);
            $model = [];
            $next = 0;
            $insert = function (int $count) use (&$model, &$next, &$idx, $name) {
                $rows = [];
                foreach (range(1, $count) as $unused) {
                    $value = $this->randomValue();
                    $rows[] = $value[0] ? ['i' => $next, self::LOC => $value[1]] : ['i' => $next];
                    $model[$next++] = $value;
                }
                $this->assertSame($count, $idx->insert($name, count($rows) === 1 ? $rows[0] : $rows)['n']);
            };
            $indexedCount = function () use (&$model) {
                return count(array_filter($model, function ($m) { return $m[2] && self::bbox($m[1]) !== null; }));
            };

            $insert(120);
            $this->assertSame(['success' => true, 'indexed' => $indexedCount(), 'error' => null], $idx->createSpatialIndex($name, self::LOC));
            $this->checkLife($idx, $scan, $name, $model, "$label created");

            for ($i = 0; $i < 25; $i++) {
                $insert(1);
            }
            $insert(20);
            $this->checkLife($idx, $scan, $name, $model, "$label inserted");

            $keys = array_keys($model);
            for ($i = 0; $i < 30; $i++) {
                $key = $keys[mt_rand(0, count($keys) - 1)];
                $value = $this->randomValue();
                $this->assertSame(1, $idx->update($name, [['key' => $key], ['set' => [self::LOC => $value[1]]]])['n']);
                $model[$key] = [true, $value[1], $value[2]];
            }
            $this->checkLife($idx, $scan, $name, $model, "$label updated");

            for ($i = 0; $i < 25; $i++) {
                $keys = array_keys($model);
                $key = $keys[mt_rand(0, count($keys) - 1)];
                $this->assertSame(1, $idx->delete($name, ['key' => $key])['n']);
                unset($model[$key]);
            }
            $this->checkLife($idx, $scan, $name, $model, "$label deleted");

            $this->assertTrue($idx->compact($name)['success']);
            $this->checkLife($idx, $scan, $name, $model, "$label compacted");

            \noneDB::clearStaticCache();
            [$idx, $scan] = $this->pair($config);
            $this->checkLife($idx, $scan, $name, $model, "$label restarted");

            $insert(25);
            $this->checkLife($idx, $scan, $name, $model, "$label inserted after restart");

            $this->assertSame(['success' => true, 'indexed' => $indexedCount(), 'error' => null], $idx->rebuildSpatialIndex($name, self::LOC));
            $this->checkLife($idx, $scan, $name, $model, "$label rebuilt");
            $this->assertTrue($idx->checkIntegrity($name)['ok'], "$label integrity");
        }
    }

    // ==========================================================================================
    // Sharded databases: the global .grx file per field
    // ==========================================================================================

    /** Integer box of a dyadic coordinate list: floor/ceil of degrees * 1e7, in integer arithmetic */
    private static function unitsFloor(int $whole, int $k1024): int
    {
        return $whole * 10000000 + intdiv($k1024 * 78125, 8); // k / 1024 degrees = k * 9765.625 units
    }

    private static function unitsCeil(int $whole, int $k1024): int
    {
        return $whole * 10000000 + intdiv($k1024 * 78125 + 7, 8);
    }

    /** @test docs: one global file per field with each shard's bounding box (integers, rounded outwards) */
    public function globalFilePerFieldHoldsEachShardsBox(): void
    {
        $db = $this->db(['shardSize' => 20]);
        $rows = [];
        $expected = ['loc' => [], 'home-loc' => []];
        mt_srand(17);
        for ($i = 0; $i < 60; $i++) {
            $shard = intdiv($i, 20);
            $kx = mt_rand(1, 1023);
            $ky = mt_rand(1, 1023);
            $row = [self::LOC => self::point(29 + $kx / 1024, 41 + $ky / 1024)];
            $box = [self::unitsFloor(29, $kx), self::unitsFloor(41, $ky), self::unitsCeil(29, $kx), self::unitsCeil(41, $ky)];
            $prev = $expected['loc'][$shard] ?? null;
            $expected['loc'][$shard] = $prev === null ? $box : [min($prev[0], $box[0]), min($prev[1], $box[1]), max($prev[2], $box[2]), max($prev[3], $box[3])];
            if ($shard === 1) {
                $row['home-loc'] = self::point(-(10 + $kx / 1024), 5); // only shard 1 has this field
                $hb = [-self::unitsCeil(10, $kx), 50000000, -self::unitsFloor(10, $kx), 50000000];
                $prev = $expected['home-loc'][1] ?? null;
                $expected['home-loc'][1] = $prev === null ? $hb : [min($prev[0], $hb[0]), 50000000, max($prev[2], $hb[2]), 50000000];
            }
            $rows[] = $row;
        }
        $expected['home-loc'][0] = null; // "null = empty"
        $expected['home-loc'][2] = null;
        ksort($expected['home-loc']);
        $db->insert('gl', $rows);
        $db->createSpatialIndex('gl', 'loc');
        $db->createSpatialIndex('gl', 'home-loc');
        $base = $this->dbPath('gl', 'gen') . 'spatial/';
        foreach (['loc' => 'loc', 'home-loc' => 'home_loc-17e07f2f0d56'] as $field => $token) {
            $file = "$base$token.grx";
            $this->assertFileExists($file, "global file of $field");
            $header = json_decode(strtok(file_get_contents($file), "\n"), true);
            $this->assertSame(['grx', $field], [$header['kind'], $header['field']]);
            $boxes = [];
            foreach ($header['sh'] as $shard => $entry) {
                $boxes[(int)$shard] = $entry['b'];
            }
            ksort($boxes);
            $this->assertSame($expected[$field], $boxes, "shard boxes of $field");
            foreach ([0, 1, 2] as $shard) {
                $this->assertFileExists("{$base}s$shard.$token.rx");
            }
        }
        $this->assertCount(20, $db->withinBBox('gl', 'home-loc', -11, 4, -10, 6));
        $this->assertSame([], $db->withinBBox('gl', 'home-loc', 28, 40, 31, 43));
    }

    /**
     * @test docs: "A shard is skipped only when its box and its newer lines can't match; a shard without an
     * entry is always searched." Writes don't rewrite the global file, so its boxes are older than the data.
     */
    public function staleOrMissingGlobalEntriesNeverSkipAShard(): void
    {
        $config = ['shardSize' => 30];
        [$idx, $scan] = $this->pair($config);
        $model = [];
        $rows = [];
        for ($i = 0; $i < 80; $i++) { // shards 0, 1 and 2 (2 not full)
            $model[$i] = self::point(29 + ($i % 10) / 64, 41 + intdiv($i, 10) / 64);
            $rows[] = [self::LOC => $model[$i]];
        }
        $idx->insert('sk', $rows);
        $idx->createSpatialIndex('sk', self::LOC);
        $global = $this->dbPath('sk', 'grx', 'loc');
        $before = file_get_contents($global);
        $this->assertSame([0, 1, 2, 3, 4], self::keys($idx->withinBBox('sk', self::LOC, 28.9, 40.9, 29.07, 41.01)), 'setup, loads the trees');

        $idx->insert('sk', [self::LOC => self::point(-70, -30)]);                               // key 80, shard 2
        $model[80] = self::point(-70, -30);
        $idx->update('sk', [['key' => 5], ['set' => [self::LOC => self::point(-70.01, -30)]]]); // shard 0
        $model[5] = self::point(-70.01, -30);
        $idx->update('sk', [['key' => 40], ['set' => [self::LOC => ['type' => 'LineString', 'coordinates' => [[100, 10], [101, 11]]]]]]); // shard 1
        $model[40] = ['type' => 'LineString', 'coordinates' => [[100, 10], [101, 11]]];
        clearstatcache();
        $this->assertSame($before, file_get_contents($global), 'setup: writes leave the global boxes as they were');

        $check = function (string $label) use (&$idx, &$scan, &$model) {
            foreach ([[-71, -31, -69, -29], [99, 9, 102, 12], [28.9, 40.9, 29.2, 41.2], [-INF, -INF, INF, INF]] as $box) {
                $expected = array_keys(array_filter($model, function ($g) use ($box) { return self::inBBoxQuery($g, self::bboxQueryBoxes(...$box)); }));
                $this->assertSame($expected, self::keys($this->both($idx, $scan, function ($db) use ($box) { return $db->withinBBox('sk', self::LOC, ...$box); })), "$label " . json_encode($box));
            }
            $rows = $this->both($idx, $scan, function ($db) { return $db->withinDistance('sk', self::LOC, -70, -30, 5000, ['includeDistance' => true]); });
            $this->assertWithinDistanceMatchesModel($rows, $model, -70, -30, 5000, "$label distance");
            $near = $this->both($idx, $scan, function ($db) { return $db->nearest('sk', self::LOC, -70, -30, 3); });
            $this->assertNearestMatchesModel($near, $model, -70, -30, 3, null, "$label nearest");
            $this->assertSame(80, $near[0]['key'], "$label nearest: the record at the center");
            $this->assertSame([40], self::keys($this->both($idx, $scan, function ($db) {
                return $db->withinPolygon('sk', self::LOC, self::polygon(self::rect(99, 9, 102, 12)));
            })), "$label polygon");
        };
        $check('stale entries');
        \noneDB::clearStaticCache();
        [$idx, $scan] = $this->pair($config);
        $check('stale entries, fresh process');

        // a new shard has no entry: always searched
        $more = [];
        for ($i = 81; $i < 95; $i++) {
            $model[$i] = self::point(-70 + ($i - 80) / 1000, -30.001);
            $more[] = [self::LOC => $model[$i]];
        }
        $idx->insert('sk', $more);
        $this->assertTrue($idx->hasSpatialIndex('sk', self::LOC, 3), 'setup: shard 3 exists');
        $check('new shard');
        // no global file at all: every shard is searched
        \noneDB::clearStaticCache();
        unlink($global);
        [$idx, $scan] = $this->pair($config);
        $check('no global file');
    }

    /** @test compaction that removes emptied shards removes their spatial files; queries stay right */
    public function compactionRemovingShardsRemovesTheirSpatialFiles(): void
    {
        $config = ['shardSize' => 20];
        [$idx, $scan] = $this->pair($config);
        $model = [];
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $model[$i] = self::point($i / 10, $i / 20);
            $rows[] = [self::LOC => $model[$i]];
        }
        $idx->insert('cp', $rows);
        $idx->createSpatialIndex('cp', self::LOC);
        $idx->createSpatialIndex('cp', 'other');
        for ($i = 0; $i < 40; $i++) {
            $idx->delete('cp', ['key' => $i]);
            unset($model[$i]);
        }
        $this->assertTrue($idx->compact('cp')['success']);
        foreach ([0, 1] as $shard) {
            $this->assertFileDoesNotExist($this->dbPath('cp', 'rx', 'loc', $shard));
            $this->assertFileDoesNotExist($this->dbPath('cp', 'rx', 'other', $shard));
            $this->assertFalse($idx->hasSpatialIndex('cp', self::LOC, $shard));
        }
        $this->assertFileExists($this->dbPath('cp', 'rx', 'loc', 2));
        $this->assertTrue($idx->hasSpatialIndex('cp', self::LOC, 2));
        foreach (['loc', 'other'] as $token) {
            $header = json_decode(strtok(file_get_contents($this->dbPath('cp', 'grx', $token)), "\n"), true);
            $this->assertSame(['2'], array_map('strval', array_keys($header['sh'])), "only the remaining shard in the global file of $token");
        }
        foreach ([false, true] as $restart) {
            if ($restart) {
                \noneDB::clearStaticCache();
                [$idx, $scan] = $this->pair($config);
            }
            $this->assertSame(array_keys($model), self::keys($this->both($idx, $scan, function ($db) { return $db->withinBBox('cp', self::LOC, -1, -1, 10, 10); })));
            $rows = $this->both($idx, $scan, function ($db) { return $db->nearest('cp', self::LOC, 0, 0, 3); });
            $this->assertNearestMatchesModel($rows, $model, 0, 0, 3, null, 'nearest after compaction');
            $this->assertSame([40, 41, 42], array_column($rows, 'key'));
        }
    }

    // ==========================================================================================
    // Damaged and missing index files
    // ==========================================================================================

    /** @test CHANGES: damaged index files are detected and rebuilt from the data */
    public function damagedRtreeIsRebuiltFromTheData(): void
    {
        foreach (['body' => function ($content) { return strtok($content, "\n") . "\n{\"0\": broken"; },
                  'root' => function ($content) {
                      $header = json_decode(strtok($content, "\n"), true);
                      $header['root'] = 999999;
                      return json_encode($header) . "\n" . substr($content, strpos($content, "\n") + 1);
                  },
                  'header' => function ($content) { return "not json\n" . substr($content, strpos($content, "\n") + 1); }] as $label => $damage) {
            $this->cleanTestDirectory();
            [$idx, $scan] = $this->pair(['shardingEnabled' => false]);
            $model = [];
            for ($i = 0; $i < 30; $i++) {
                $model[$i] = self::point($i, $i / 2);
            }
            $this->insertGeometries($idx, 'dm', $model);
            $idx->createSpatialIndex('dm', self::LOC);
            $path = $this->dbPath('dm', 'rx', 'loc');
            \noneDB::clearStaticCache();
            file_put_contents($path, $damage(file_get_contents($path)));
            [$idx, $scan] = $this->pair(['shardingEnabled' => false]);
            $expected = array_keys(array_filter($model, function ($g) { return self::inBBoxQuery($g, [[5, 0, 12, 100]]); }));
            $this->assertSame($expected, self::keys($this->both($idx, $scan, function ($db) { return $db->withinBBox('dm', self::LOC, 5, 0, 12, 100); })), $label);
            [$header, $nodes] = $this->readTree($path);
            $this->assertIsArray($nodes, "$label: rebuilt");
            $this->assertSame(30, $header['cnt'], "$label: every geometry indexed again");
        }
    }

    /** @test a lost shard R-tree is built again from the data; the global file keeps the same shard boxes */
    public function lostShardRtreeIsRebuiltAndTheGlobalBoxesStay(): void
    {
        $config = ['shardSize' => 10];
        [$idx, $scan] = $this->pair($config);
        $model = [];
        for ($i = 0; $i < 30; $i++) {
            $model[$i] = self::point($i, -$i);
        }
        $this->insertGeometries($idx, 'ls', $model);
        $idx->createSpatialIndex('ls', self::LOC);
        $global = file_get_contents($this->dbPath('ls', 'grx', 'loc'));
        \noneDB::clearStaticCache();
        unlink($this->dbPath('ls', 'rx', 'loc', 1));
        [$idx, $scan] = $this->pair($config);
        foreach ([[-1, -40, 40, 1], [12, -15, 14, -11], [9.5, -30, 20.5, 0]] as $box) {
            $expected = array_keys(array_filter($model, function ($g) use ($box) { return self::inBBoxQuery($g, [$box]); }));
            $this->assertSame($expected, self::keys($this->both($idx, $scan, function ($db) use ($box) { return $db->withinBBox('ls', self::LOC, ...$box); })), json_encode($box));
        }
        clearstatcache();
        $this->assertFileExists($this->dbPath('ls', 'rx', 'loc', 1), 'built again');
        $header = json_decode(strtok(file_get_contents($this->dbPath('ls', 'rx', 'loc', 1)), "\n"), true);
        $this->assertSame(10, $header['cnt']);
        $this->assertSame($global, file_get_contents($this->dbPath('ls', 'grx', 'loc')), 'the same shard boxes');
    }

    /**
     * @test CHANGES (Locking): "A lock that couldn't be taken throws RuntimeException with code
     * noneDB::LOCK_FAILED. Fallback paths never continue without the lock": a spatial query that must build
     * its index doesn't scan instead when the exclusive lock can't be had
     */
    public function indexBuildWithoutTheLockThrowsLockFailed(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $this->insertGeometries($db, 'lk', [self::point(1, 1), self::point(2, 2)]);
        $db->createSpatialIndex('lk', self::LOC);
        \noneDB::clearStaticCache();
        $rx = $this->dbPath('lk', 'rx', 'loc');
        unlink($rx);
        $script = $this->childScript('$fp = fopen(' . var_export($this->dbPath('lk', 'lock'), true) . ', "r");'
            . ' flock($fp, LOCK_SH); echo "locked\n"; usleep(5000000);');
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w']], $pipes);
        try {
            $this->assertSame("locked\n", fgets($pipes[1]), 'setup: another process holds a shared lock');
            $db = $this->db(['shardingEnabled' => false, 'lockTimeout' => 0.3]);
            try {
                $db->withinBBox('lk', self::LOC, 0, 0, 3, 3);
                $this->fail('a query whose index needs the exclusive lock must not go on without it');
            } catch (\RuntimeException $e) {
                $this->assertSame(\noneDB::LOCK_FAILED, $e->getCode(), $e->getMessage());
            }
            $this->assertFileDoesNotExist($rx);
        } finally {
            proc_terminate($proc);
            fclose($pipes[1]);
            proc_close($proc);
        }
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame([0, 1], self::keys($db->withinBBox('lk', self::LOC, 0, 0, 3, 3)), 'once the lock is free');
        $this->assertFileExists($rx);
    }

    /** @test B-1: an index that can't be built (its file can't be written) leaves the query to a scan */
    public function indexThatCannotBeWrittenFallsBackToAScan(): void
    {
        [$idx, $scan] = $this->pair(['shardingEnabled' => false]);
        $model = [];
        for ($i = 0; $i < 20; $i++) {
            $model[$i] = self::point($i / 10, 0);
        }
        $this->insertGeometries($idx, 'fb', $model);
        $idx->createSpatialIndex('fb', self::LOC);
        $path = $this->dbPath('fb', 'rx', 'loc');
        \noneDB::clearStaticCache();
        unlink($path);
        $this->setStatic('renameFn', function ($from, $to) {
            return substr($to, -3) !== '.rx' && rename($from, $to);
        });
        [$idx, $scan] = $this->pair(['shardingEnabled' => false]);
        $this->assertSame([5, 6, 7], self::keys($idx->withinBBox('fb', self::LOC, 0.5, -1, 0.7, 1)));
        $rows = $idx->withinDistance('fb', self::LOC, 0, 0, 25000, ['includeDistance' => true]);
        $this->assertWithinDistanceMatchesModel($rows, $model, 0, 0, 25000, 'scan fallback');
        $this->assertSame([0, 1, 2], self::keys($rows));
        $this->assertFileDoesNotExist($path, 'setup: the index could not be written');
        $this->setStatic('renameFn', null);
        $this->assertSame([5, 6, 7], self::keys($idx->withinBBox('fb', self::LOC, 0.5, -1, 0.7, 1)));
        $this->assertFileExists($path, 'built once it can be written');
    }
}
