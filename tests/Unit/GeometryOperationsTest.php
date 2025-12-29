<?php
/**
 * Geometry Operations Unit Tests
 * Tests MBR calculation, Haversine distance, Point-in-polygon, etc.
 * @version 3.1.0
 */

namespace noneDB\Tests\Unit;

use noneDB\Tests\noneDBTestCase;

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
    }

    /**
     * Test MBR area calculation
     */
    public function testMBRArea(): void
    {
        $method = $this->getPrivateMethod('mbrArea');

        $mbr = [0.0, 0.0, 2.0, 3.0];  // 2 x 3 = 6

        $area = $method->invoke($this->noneDB, $mbr);
        $this->assertEquals(6.0, $area);
    }

    // ========== Haversine Distance Tests ==========

    /**
     * Test Haversine distance between two points
     */
    public function testHaversineDistance(): void
    {
        $method = $this->getPrivateMethod('haversineDistance');

        // Istanbul to Ankara (approx 350 km)
        $distance = $method->invoke($this->noneDB, 28.9784, 41.0082, 32.8597, 39.9334);

        // Allow 10km tolerance
        $this->assertGreaterThan(340, $distance);
        $this->assertLessThan(360, $distance);
    }

    /**
     * Test Haversine distance for same point (should be 0)
     */
    public function testHaversineDistanceSamePoint(): void
    {
        $method = $this->getPrivateMethod('haversineDistance');

        $distance = $method->invoke($this->noneDB, 28.9784, 41.0082, 28.9784, 41.0082);

        $this->assertEquals(0, $distance);
    }

    /**
     * Test circle to bounding box conversion
     */
    public function testCircleToBBox(): void
    {
        $method = $this->getPrivateMethod('circleToBBox');

        // 100km radius circle around Istanbul
        $bbox = $method->invoke($this->noneDB, 28.9784, 41.0082, 100);

        // BBox should extend approximately 100km in each direction
        // At ~41 degrees latitude, 1 degree longitude ≈ 85km
        // 100km / 85km ≈ 1.2 degrees longitude change
        $this->assertLessThan(28.9784, $bbox[0]); // minLon
        $this->assertLessThan(41.0082, $bbox[1]); // minLat
        $this->assertGreaterThan(28.9784, $bbox[2]); // maxLon
        $this->assertGreaterThan(41.0082, $bbox[3]); // maxLat
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
        $method = $this->getPrivateMethod('lineSegmentsIntersect');

        // X pattern
        $intersects = $method->invoke(
            $this->noneDB,
            [0, 0], [10, 10],  // Line 1: diagonal
            [0, 10], [10, 0]   // Line 2: other diagonal
        );

        $this->assertTrue($intersects);
    }

    /**
     * Test non-intersecting parallel lines
     */
    public function testLineSegmentsParallel(): void
    {
        $method = $this->getPrivateMethod('lineSegmentsIntersect');

        $intersects = $method->invoke(
            $this->noneDB,
            [0, 0], [10, 0],  // Horizontal line at y=0
            [0, 5], [10, 5]   // Horizontal line at y=5
        );

        $this->assertFalse($intersects);
    }

    /**
     * Test non-intersecting non-parallel lines
     */
    public function testLineSegmentsNoIntersect(): void
    {
        $method = $this->getPrivateMethod('lineSegmentsIntersect');

        $intersects = $method->invoke(
            $this->noneDB,
            [0, 0], [5, 5],    // Short diagonal
            [6, 0], [10, 4]    // Other segment far away
        );

        $this->assertFalse($intersects);
    }

    // ========== Polygon Intersection Tests ==========

    /**
     * Test overlapping polygons
     */
    public function testPolygonsIntersectOverlapping(): void
    {
        $method = $this->getPrivateMethod('polygonsIntersect');

        $poly1 = [
            'type' => 'Polygon',
            'coordinates' => [[[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]]]
        ];
        $poly2 = [
            'type' => 'Polygon',
            'coordinates' => [[[5, 5], [15, 5], [15, 15], [5, 15], [5, 5]]]
        ];

        $intersects = $method->invoke($this->noneDB, $poly1, $poly2);
        $this->assertTrue($intersects);
    }

    /**
     * Test non-overlapping polygons
     */
    public function testPolygonsIntersectNonOverlapping(): void
    {
        $method = $this->getPrivateMethod('polygonsIntersect');

        $poly1 = [
            'type' => 'Polygon',
            'coordinates' => [[[0, 0], [5, 0], [5, 5], [0, 5], [0, 0]]]
        ];
        $poly2 = [
            'type' => 'Polygon',
            'coordinates' => [[[10, 10], [15, 10], [15, 15], [10, 15], [10, 10]]]
        ];

        $intersects = $method->invoke($this->noneDB, $poly1, $poly2);
        $this->assertFalse($intersects);
    }

    /**
     * Test one polygon inside another
     */
    public function testPolygonsIntersectContained(): void
    {
        $method = $this->getPrivateMethod('polygonsIntersect');

        $poly1 = [
            'type' => 'Polygon',
            'coordinates' => [[[0, 0], [20, 0], [20, 20], [0, 20], [0, 0]]]
        ];
        $poly2 = [
            'type' => 'Polygon',
            'coordinates' => [[[5, 5], [15, 5], [15, 15], [5, 15], [5, 5]]]
        ];

        $intersects = $method->invoke($this->noneDB, $poly1, $poly2);
        $this->assertTrue($intersects);
    }

    // ========== Centroid Tests ==========

    /**
     * Test centroid calculation for Point
     */
    public function testGetGeometryCentroidPoint(): void
    {
        $method = $this->getPrivateMethod('getGeometryCentroid');

        $point = [
            'type' => 'Point',
            'coordinates' => [28.9803, 41.0086]
        ];

        $centroid = $method->invoke($this->noneDB, $point);

        $this->assertEquals(28.9803, $centroid[0]);
        $this->assertEquals(41.0086, $centroid[1]);
    }

    /**
     * Test centroid calculation for Polygon
     */
    public function testGetGeometryCentroidPolygon(): void
    {
        $method = $this->getPrivateMethod('getGeometryCentroid');

        $polygon = [
            'type' => 'Polygon',
            'coordinates' => [[
                [0, 0], [10, 0], [10, 10], [0, 10], [0, 0]
            ]]
        ];

        $centroid = $method->invoke($this->noneDB, $polygon);

        // Centroid of a square should be at center
        $this->assertEquals(5.0, $centroid[0]);
        $this->assertEquals(5.0, $centroid[1]);
    }
}
