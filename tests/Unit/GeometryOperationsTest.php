<?php
/**
 * Geometry Operations Unit Tests
 * Tests MBR calculation, Haversine distance, Point-in-polygon, etc.
 */

namespace noneDB\Tests\Unit;

use noneDB\Tests\noneDBTestCase;
use noneDB\Tests\Integration\SpecSpatialTest as Model;

class GeometryOperationsTest extends noneDBTestCase
{
    // ========== MBR Tests ==========

    /**
     * Test MBR calculation for Point
     */
    public function testCalculateMBRPoint(): void
    {
        $method = $this->getPrivateMethod('calculateMBR');

        $point = [
            'type' => 'Point',
            'coordinates' => [28.9803, 41.0086]
        ];

        $mbr = $method->invoke($this->noneDB, $point);

        // For a point, MBR is the point itself
        $this->assertEquals(28.9803, $mbr[0]); // minLon
        $this->assertEquals(41.0086, $mbr[1]); // minLat
        $this->assertEquals(28.9803, $mbr[2]); // maxLon
        $this->assertEquals(41.0086, $mbr[3]); // maxLat
        $this->assertSame([28.9803, 41.0086, 28.9803, 41.0086], $mbr);
    }

    /**
     * Test MBR calculation for LineString
     */
    public function testCalculateMBRLineString(): void
    {
        $method = $this->getPrivateMethod('calculateMBR');

        $line = [
            'type' => 'LineString',
            'coordinates' => [
                [28.0, 40.0],
                [29.0, 41.0],
                [30.0, 40.5]
            ]
        ];

        $mbr = $method->invoke($this->noneDB, $line);

        $this->assertEquals(28.0, $mbr[0]); // minLon
        $this->assertEquals(40.0, $mbr[1]); // minLat
        $this->assertEquals(30.0, $mbr[2]); // maxLon
        $this->assertEquals(41.0, $mbr[3]); // maxLat
        $this->assertSame([28.0, 40.0, 30.0, 41.0], $mbr);
    }

    /**
     * Test MBR calculation for Polygon
     */
    public function testCalculateMBRPolygon(): void
    {
        $method = $this->getPrivateMethod('calculateMBR');

        $polygon = [
            'type' => 'Polygon',
            'coordinates' => [[
                [28.0, 40.0],
                [30.0, 40.0],
                [30.0, 42.0],
                [28.0, 42.0],
                [28.0, 40.0]
            ]]
        ];

        $mbr = $method->invoke($this->noneDB, $polygon);

        $this->assertEquals(28.0, $mbr[0]);
        $this->assertEquals(40.0, $mbr[1]);
        $this->assertEquals(30.0, $mbr[2]);
        $this->assertEquals(42.0, $mbr[3]);
        $this->assertSame([28.0, 40.0, 30.0, 42.0], $mbr);
    }

    /**
     * MBR of the multi-part types and collections: every part counts; no position gives null
     * (docs: an empty GeometryCollection has no location)
     */
    public function testCalculateMBRMultiPartsAndCollections(): void
    {
        $method = $this->getPrivateMethod('calculateMBR');
        $cases = [
            [['type' => 'MultiPoint', 'coordinates' => [[3, 4], [-1, 2], [5, -6]]], [-1, -6, 5, 4]],
            [['type' => 'MultiLineString', 'coordinates' => [[[0, 0], [1, 1]], [[10, -5], [12, 3]]]], [0, -5, 12, 3]],
            [['type' => 'MultiPolygon', 'coordinates' => [
                [[[0, 0], [1, 0], [1, 1], [0, 0]]],
                [[[5, 5], [8, 5], [8, 9], [5, 5]], [[6, 6], [7, 6], [7, 7], [6, 6]]],
            ]], [0, 0, 8, 9]],
            [['type' => 'Polygon', 'coordinates' => [[[0, 0], [10, 0], [10, 10], [0, 0]], [[6, 2], [8, 2], [8, 4], [6, 2]]]], [0, 0, 10, 10]],
            [['type' => 'GeometryCollection', 'geometries' => [
                ['type' => 'Point', 'coordinates' => [50, 50]],
                ['type' => 'GeometryCollection', 'geometries' => []],
                ['type' => 'LineString', 'coordinates' => [[60, 60], [61, 49]]],
            ]], [50, 49, 61, 60]],
            [['type' => 'Point', 'coordinates' => [1, 2, 300]], [1, 2, 1, 2]],
        ];
        foreach ($cases as [$geometry, $expected]) {
            $this->assertEquals($expected, $method->invoke($this->noneDB, $geometry), json_encode($geometry));
            $this->assertEquals(Model::bbox($geometry), $method->invoke($this->noneDB, $geometry), 'model ' . json_encode($geometry));
        }
        $this->assertNull($method->invoke($this->noneDB, ['type' => 'GeometryCollection', 'geometries' => []]));
        $this->assertNull($method->invoke($this->noneDB, ['type' => 'GeometryCollection', 'geometries' => [['type' => 'GeometryCollection', 'geometries' => []]]]));
    }

    /**
     * Test MBR union
     */
    public function testMBRUnion(): void
    {
        $method = $this->getPrivateMethod('mbrUnion');

        $mbr1 = [28.0, 40.0, 29.0, 41.0];
        $mbr2 = [29.5, 40.5, 30.0, 42.0];

        $union = $method->invoke($this->noneDB, $mbr1, $mbr2);

        $this->assertEquals(28.0, $union[0]); // min of minLons
        $this->assertEquals(40.0, $union[1]); // min of minLats
        $this->assertEquals(30.0, $union[2]); // max of maxLons
        $this->assertEquals(42.0, $union[3]); // max of maxLats
        $this->assertSame([28.0, 40.0, 30.0, 42.0], $union);
    }

    /**
     * Test MBR overlap detection (overlapping)
     */
    public function testMBROverlapsTrue(): void
    {
        $method = $this->getPrivateMethod('mbrOverlaps');

        $mbr1 = [28.0, 40.0, 30.0, 42.0];
        $mbr2 = [29.0, 41.0, 31.0, 43.0];

        $overlaps = $method->invoke($this->noneDB, $mbr1, $mbr2);
        $this->assertTrue($overlaps);
        // docs (withinBBox): boxes that only touch count as well, so does containment
        $this->assertTrue($method->invoke($this->noneDB, [0.0, 0.0, 1.0, 1.0], [1.0, 0.5, 2.0, 2.0]), 'touching edge');
        $this->assertTrue($method->invoke($this->noneDB, [0.0, 0.0, 1.0, 1.0], [1.0, 1.0, 2.0, 2.0]), 'touching corner');
        $this->assertTrue($method->invoke($this->noneDB, [0.0, 0.0, 10.0, 10.0], [2.0, 2.0, 3.0, 3.0]), 'contained');
        $this->assertTrue($method->invoke($this->noneDB, [5.0, 5.0, 5.0, 5.0], [5.0, 5.0, 5.0, 5.0]), 'equal points');
    }

    /**
     * Test MBR overlap detection (non-overlapping)
     */
    public function testMBROverlapsFalse(): void
    {
        $method = $this->getPrivateMethod('mbrOverlaps');

        $mbr1 = [28.0, 40.0, 29.0, 41.0];
        $mbr2 = [30.0, 42.0, 31.0, 43.0];

        $overlaps = $method->invoke($this->noneDB, $mbr1, $mbr2);
        $this->assertFalse($overlaps);
        $this->assertFalse($method->invoke($this->noneDB, [0.0, 0.0, 1.0, 1.0], [1.0000001, 0.0, 2.0, 1.0]), 'apart in x only');
        $this->assertFalse($method->invoke($this->noneDB, [0.0, 0.0, 1.0, 1.0], [0.0, 1.0000001, 1.0, 2.0]), 'apart in y only');
        $this->assertFalse($method->invoke($this->noneDB, [2.0, 2.0, 3.0, 3.0], [0.0, 0.0, 1.0, 1.0]), 'either order');
    }

    // ========== Haversine Distance Tests ==========

    /**
     * Test Haversine distance between two points
     */
    public function testHaversineDistance(): void
    {
        $method = $this->getPrivateMethod('haversineDistance');

        // Istanbul to Ankara (approx 350 km = 350000 meters)
        $distance = $method->invoke($this->noneDB, 28.9784, 41.0082, 32.8597, 39.9334);

        // Allow 10km tolerance (in meters)
        $this->assertGreaterThan(340000, $distance);
        $this->assertLessThan(360000, $distance);
        // docs: haversine with earth radius 6,371 km (independent implementation)
        $this->assertEqualsWithDelta(Model::hav(28.9784, 41.0082, 32.8597, 39.9334), $distance, 1e-6);
        $this->assertEqualsWithDelta(6371000 * M_PI / 180, $method->invoke($this->noneDB, 0, 0, 1, 0), 1e-6, 'one degree on the equator');
        $this->assertEqualsWithDelta(6371000 * M_PI, $method->invoke($this->noneDB, 10, 20, -170, -20), 1e-6, 'antipodes');
        $this->assertEqualsWithDelta($distance, $method->invoke($this->noneDB, 32.8597, 39.9334, 28.9784, 41.0082), 1e-9, 'symmetric');
    }

    /**
     * Test Haversine distance for same point (should be 0)
     */
    public function testHaversineDistanceSamePoint(): void
    {
        $method = $this->getPrivateMethod('haversineDistance');

        $distance = $method->invoke($this->noneDB, 28.9784, 41.0082, 28.9784, 41.0082);

        $this->assertEquals(0, $distance);
        $this->assertSame(0.0, $distance);
        $this->assertEqualsWithDelta(0.0, $method->invoke($this->noneDB, 180, 10, -180, 10), 1e-6, 'the same meridian (sin(pi) is not exactly 0)');
    }

    /**
     * Test circle to bounding box conversion
     */
    public function testCircleToBBox(): void
    {
        $method = $this->getPrivateMethod('circleToBBoxes');

        // 100km radius circle around Istanbul (one box: no antimeridian, no pole)
        $boxes = $method->invoke($this->noneDB, 28.9784, 41.0082, 100000.0);
        $this->assertCount(1, $boxes);
        $bbox = $boxes[0];

        // BBox should extend approximately 100km in each direction
        // At ~41 degrees latitude, 1 degree longitude ≈ 85km
        // 100km / 85km ≈ 1.2 degrees longitude change
        $this->assertLessThan(28.9784, $bbox[0]); // minLon
        $this->assertLessThan(41.0082, $bbox[1]); // minLat
        $this->assertGreaterThan(28.9784, $bbox[2]); // maxLon
        $this->assertGreaterThan(41.0082, $bbox[3]); // maxLat
    }

    /**
     * Destination point on the sphere (independent of noneDB): start, bearing (radians), distance (m)
     */
    private static function destination(float $lon, float $lat, float $bearing, float $meters): array
    {
        $d = $meters / 6371000.0;
        $p1 = deg2rad($lat);
        $p2 = asin(sin($p1) * cos($d) + cos($p1) * sin($d) * cos($bearing));
        $l2 = deg2rad($lon) + atan2(sin($bearing) * sin($d) * cos($p1), cos($d) - sin($p1) * sin($p2));
        $lon2 = fmod(rad2deg($l2) + 540.0, 360.0) - 180.0;
        return [$lon2, rad2deg($p2)];
    }

    /**
     * docs: "Circles that cross the antimeridian (±180°) or reach a pole are searched correctly": the search
     * boxes cover every point of the circle
     */
    public function testCircleSearchBoxesCoverTheCircle(): void
    {
        $method = $this->getPrivateMethod('circleToBBoxes');
        $cases = [
            [28.9784, 41.0082, 100000.0, 1], [179.9, 10.0, 50000.0, 2], [-179.9, -10.0, 50000.0, 2],
            [0.0, 89.5, 100000.0, 1], [120.0, -89.9, 30000.0, 1], [0.0, 0.0, 5000000.0, 1], [170.0, 60.0, 2000000.0, 2],
        ];
        foreach ($cases as [$lon, $lat, $radius, $count]) {
            $boxes = $method->invoke($this->noneDB, $lon, $lat, $radius);
            $this->assertCount($count, $boxes, "($lon, $lat, $radius): one box, or two split at the antimeridian");
            foreach ($boxes as $box) {
                $this->assertGreaterThanOrEqual(-180.0, $box[0]);
                $this->assertLessThanOrEqual(180.0, $box[2]);
                $this->assertGreaterThanOrEqual(-90.0, $box[1]);
                $this->assertLessThanOrEqual(90.0, $box[3]);
            }
            for ($i = 0; $i < 360; $i += 5) {
                foreach ([1.0, 0.5] as $fraction) {
                    [$x, $y] = self::destination($lon, $lat, deg2rad($i), $radius * $fraction);
                    $this->assertEqualsWithDelta($radius * $fraction, Model::hav($lon, $lat, $x, $y), 0.01, 'setup: on the circle');
                    $inside = false;
                    foreach ($boxes as $box) {
                        $inside = $inside || ($box[0] <= $x && $x <= $box[2] && $box[1] <= $y && $y <= $box[3]);
                    }
                    $this->assertTrue($inside, "($lon, $lat, $radius): bearing $i at " . ($fraction * 100) . "% = ($x, $y) is covered by " . json_encode($boxes));
                }
            }
        }
    }

    // ========== Point in Polygon Tests ==========

    /**
     * Test point inside polygon
     */
    public function testPointInPolygonInside(): void
    {
        $method = $this->getPrivateMethod('pointInPolygon');

        $polygon = [
            'type' => 'Polygon',
            'coordinates' => [[
                [0, 0], [10, 0], [10, 10], [0, 10], [0, 0]
            ]]
        ];

        $inside = $method->invoke($this->noneDB, 5, 5, $polygon);
        $this->assertTrue($inside);
    }

    /**
     * Test point outside polygon
     */
    public function testPointInPolygonOutside(): void
    {
        $method = $this->getPrivateMethod('pointInPolygon');

        $polygon = [
            'type' => 'Polygon',
            'coordinates' => [[
                [0, 0], [10, 0], [10, 10], [0, 10], [0, 0]
            ]]
        ];

        $inside = $method->invoke($this->noneDB, 15, 5, $polygon);
        $this->assertFalse($inside);
    }

    /**
     * Test point on polygon edge
     */
    public function testPointOnPolygonEdge(): void
    {
        $method = $this->getPrivateMethod('pointInPolygon');

        $polygon = [
            'type' => 'Polygon',
            'coordinates' => [[
                [0, 0], [10, 0], [10, 10], [0, 10], [0, 0]
            ]]
        ];

        // Point on edge should be considered inside
        $inside = $method->invoke($this->noneDB, 5, 0, $polygon);
        // Edge behavior may vary, but typically inside
        $this->assertTrue($inside);

        // docs (withinPolygon): "The polygon is closed: points on its boundary are inside, on every edge"
        $closed = $this->getPrivateMethod('pointInPolygonClosed');
        $coords = $polygon['coordinates'];
        foreach ([[5, 0], [10, 5], [5, 10], [0, 5], [0, 0], [10, 0], [10, 10], [0, 10]] as [$x, $y]) {
            $this->assertTrue($closed->invoke($this->noneDB, $x, $y, $coords), "boundary point ($x, $y)");
        }
        foreach ([[5, -0.0000001], [10.0000001, 5], [5, 10.0000001], [-0.0000001, 5]] as [$x, $y]) {
            $this->assertFalse($closed->invoke($this->noneDB, $x, $y, $coords), "outside ($x, $y)");
        }
        $triangle = [[[0, 0], [8, 0], [0, 8], [0, 0]]];
        foreach ([[4, 4], [2, 6], [0.5, 7.5], [7.75, 0.25]] as [$x, $y]) {
            $this->assertTrue($closed->invoke($this->noneDB, $x, $y, $triangle), "on the diagonal ($x, $y)");
        }
        $this->assertFalse($closed->invoke($this->noneDB, 4.0000001, 4, $triangle));
        $withHole = [[[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]], [[3, 3], [7, 3], [7, 7], [3, 7], [3, 3]]];
        $this->assertTrue($closed->invoke($this->noneDB, 3, 5, $withHole), 'a hole\'s boundary belongs to the polygon');
        $this->assertTrue($closed->invoke($this->noneDB, 7, 7, $withHole));
        $this->assertFalse($closed->invoke($this->noneDB, 5, 5, $withHole), 'inside the hole');
    }

    /**
     * Test point inside polygon with hole
     */
    public function testPointInPolygonWithHole(): void
    {
        $method = $this->getPrivateMethod('pointInPolygon');

        $polygon = [
            'type' => 'Polygon',
            'coordinates' => [
                [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]],  // Outer
                [[3, 3], [7, 3], [7, 7], [3, 7], [3, 3]]       // Hole
            ]
        ];

        // Point in outer ring but outside hole
        $inside1 = $method->invoke($this->noneDB, 1, 1, $polygon);
        $this->assertTrue($inside1);

        // Point inside hole
        $inside2 = $method->invoke($this->noneDB, 5, 5, $polygon);
        $this->assertFalse($inside2);
    }

    // ========== Line Segment Intersection Tests ==========

    /**
     * Test intersecting line segments
     */
    public function testLineSegmentsIntersect(): void
    {
        $method = $this->getPrivateMethod('segmentsMeet');

        // X pattern
        $intersects = $method->invoke(
            null,
            0, 0, 10, 10,  // Line 1: diagonal
            0, 10, 10, 0   // Line 2: other diagonal
        );

        $this->assertTrue($intersects);
    }

    /**
     * Test non-intersecting parallel lines
     */
    public function testLineSegmentsParallel(): void
    {
        $method = $this->getPrivateMethod('segmentsMeet');

        $intersects = $method->invoke(
            null,
            0, 0, 10, 0,  // Horizontal line at y=0
            0, 5, 10, 5   // Horizontal line at y=5
        );

        $this->assertFalse($intersects);
    }

    /**
     * Test non-intersecting non-parallel lines
     */
    public function testLineSegmentsNoIntersect(): void
    {
        $method = $this->getPrivateMethod('segmentsMeet');

        $intersects = $method->invoke(
            null,
            0, 0, 5, 5,    // Short diagonal
            6, 0, 10, 4    // Other segment far away
        );

        $this->assertFalse($intersects);
    }

    /**
     * docs (Validation Cost): edges "share a point" when they cross, touch or overlap: closed segments, exact
     */
    public function testSegmentsMeetAtSharedPointsOnly(): void
    {
        $method = $this->getPrivateMethod('segmentsMeet');
        $cases = [
            [[0, 0, 10, 0, 10, 0, 20, 5], true],      // a shared end point
            [[0, 0, 10, 0, 5, 0, 5, 5], true],        // T: an end on the other's inside
            [[0, 0, 10, 0, 5, 0, 15, 0], true],       // collinear overlap
            [[0, 0, 10, 0, 10, 0, 15, 0], true],      // collinear, touching ends
            [[0, 0, 10, 0, 11, 0, 15, 0], false],     // collinear, apart
            [[0, 0, 10, 10, 5, 5.0000001, 6, 6.0000001], false], // parallel, just apart
            [[0, 0, 10, 10, 10, 0, 6, 4.0000001], false],        // ends just short of the other
            [[0, 0, 10, 10, 10, 0, 5, 5], true],      // ends on the other
            [[0, 0, 0, 10, 0, 5, 0, 7], true],        // vertical, contained
        ];
        foreach ($cases as [$p, $expected]) {
            $this->assertSame($expected, $method->invoke(null, ...$p), json_encode($p));
            $this->assertSame($expected, $method->invoke(null, $p[4], $p[5], $p[6], $p[7], $p[0], $p[1], $p[2], $p[3]), 'swapped ' . json_encode($p));
        }
    }
}
