<?php
/**
 * Real-World Spatial Index Tests
 * Tests that simulate actual use cases and edge cases
 */

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;
use noneDB\Tests\Integration\SpecSpatialTest as Model;

class SpatialRealWorldTest extends noneDBTestCase
{
    /** Keys of the stored records whose $field lies within $meters (independent model), nearest first */
    private function expectedKeys(string $field, float $lon, float $lat, float $meters): array
    {
        $out = [];
        foreach ($this->noneDB->find($this->testDbName, 0) as $record) {
            if (isset($record[$field])) {
                $d = Model::distance($lon, $lat, $record[$field]);
                if ($d <= $meters) {
                    $out[$record['key']] = $d;
                }
            }
        }
        asort($out);
        return $out;
    }

    /** The expected records (key => model distance), in an order of non-decreasing distance (ties in any order) */
    private function assertSortedByDistance(array $expected, array $rows, string $message = ''): void
    {
        $keys = array_column($rows, 'key');
        $sorted = $keys;
        sort($sorted);
        $expectedKeys = array_keys($expected);
        sort($expectedKeys);
        $this->assertSame($expectedKeys, $sorted, $message);
        $previous = -INF;
        foreach ($keys as $key) {
            $this->assertGreaterThanOrEqual($previous - 0.01, $expected[$key], "$message: sorted by distance");
            $previous = $expected[$key];
        }
    }

    // ========== BULK OPERATIONS ==========

    /**
     * Test bulk insert with spatial index (100+ records)
     */
    public function testBulkInsertWithSpatialIndex(): void
    {
        // Create spatial index first
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Generate 100 random locations in Istanbul
        mt_srand(42);
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $data[] = [
                'name' => "Location $i",
                'category' => ['restaurant', 'cafe', 'hotel', 'museum'][$i % 4],
                'location' => [
                    'type' => 'Point',
                    'coordinates' => [
                        28.8 + (mt_rand(0, 400) / 1000), // 28.8-29.2
                        40.9 + (mt_rand(0, 200) / 1000)  // 40.9-41.1
                    ]
                ]
            ];
        }

        $result = $this->noneDB->insert($this->testDbName, $data);
        $this->assertEquals(100, $result['n']);

        // Verify spatial queries work (50km = 50000m)
        $nearby = $this->noneDB->withinDistance($this->testDbName, 'location', 29.0, 41.0, 50000);
        $this->assertGreaterThan(0, count($nearby));
        // every point is within 50 km (the area is about 34 x 22 km), nearest first
        $this->assertCount(100, $nearby);
        $this->assertSortedByDistance($this->expectedKeys('location', 29.0, 41.0, 50000), $nearby);
        foreach ([2000, 5000, 10000] as $radius) {
            $this->assertSortedByDistance($this->expectedKeys('location', 29.05, 41.02, $radius),
                $this->noneDB->withinDistance($this->testDbName, 'location', 29.05, 41.02, $radius), "radius $radius");
        }
    }

    /**
     * Test bulk delete with spatial index
     */
    public function testBulkDeleteWithSpatialIndex(): void
    {
        // Insert data with spatial index
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $data = [];
        for ($i = 0; $i < 50; $i++) {
            $data[] = [
                'name' => "Place $i",
                'active' => $i < 25, // First 25 are active
                'location' => [
                    'type' => 'Point',
                    'coordinates' => [28.9 + ($i * 0.001), 41.0]
                ]
            ];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Delete inactive records
        $deleteResult = $this->noneDB->delete($this->testDbName, ['active' => false]);
        $this->assertEquals(25, $deleteResult['n']);

        // Verify spatial index is updated - deleted records should not appear (100km = 100000m)
        $all = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9, 41.0, 100000);
        $this->assertCount(25, $all);

        // All remaining should be active
        foreach ($all as $record) {
            $this->assertTrue($record['active']);
        }
        $keys = array_column($all, 'key');
        sort($keys);
        $this->assertSame(range(0, 24), $keys);
        $this->assertSame([], $this->noneDB->withinBBox($this->testDbName, 'location', 28.925, 40.9, 29.0, 41.1), 'the deleted ones are gone from the index');
    }

    /**
     * Test bulk update with spatial index (location changes)
     */
    public function testBulkUpdateWithSpatialIndex(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Insert in Istanbul
        $data = [];
        for ($i = 0; $i < 20; $i++) {
            $data[] = [
                'name' => "Business $i",
                'city' => 'istanbul',
                'location' => [
                    'type' => 'Point',
                    'coordinates' => [28.9 + ($i * 0.01), 41.0]
                ]
            ];
        }
        $this->noneDB->insert($this->testDbName, $data);

        // Move Business 10 to Ankara
        $updateResult = $this->noneDB->update($this->testDbName, [
            ['name' => 'Business 10'],
            ['set' => [
                'city' => 'ankara',
                'location' => ['type' => 'Point', 'coordinates' => [32.8, 39.9]]
            ]]
        ]);
        $this->assertEquals(1, $updateResult['n']);

        // Verify spatial index updated (50km = 50000m)
        $inIstanbul = $this->noneDB->withinDistance($this->testDbName, 'location', 29.0, 41.0, 50000);
        $inAnkara = $this->noneDB->withinDistance($this->testDbName, 'location', 32.8, 39.9, 50000);

        $this->assertEquals(19, count($inIstanbul)); // 19 left in Istanbul
        $this->assertEquals(1, count($inAnkara));     // 1 moved to Ankara
        $this->assertSame(['Business 10'], array_column($inAnkara, 'name'));
        $this->assertNotContains('Business 10', array_column($inIstanbul, 'name'));
        $this->assertSortedByDistance($this->expectedKeys('location', 29.0, 41.0, 50000), $inIstanbul);
    }

    // ========== EDGE CASES ==========

    /**
     * Test empty result queries
     */
    public function testEmptyResultQueries(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Insert in Istanbul
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Place 1', 'location' => ['type' => 'Point', 'coordinates' => [28.9, 41.0]]]
        ]);

        // Query in completely different location (London) - 10km = 10000m
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', -0.1, 51.5, 10000);
        $this->assertCount(0, $results);

        // Nearest with no results in range
        $nearest = $this->noneDB->nearest($this->testDbName, 'location', -0.1, 51.5, 5, [
            'maxDistance' => 100000 // 100km = 100000m max
        ]);
        $this->assertCount(0, $nearest);
    }

    /**
     * Test queries on empty database
     */
    public function testQueriesOnEmptyDatabase(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9, 41.0, 10000);
        $this->assertCount(0, $results);

        $bbox = $this->noneDB->withinBBox($this->testDbName, 'location', 28, 40, 30, 42);
        $this->assertCount(0, $bbox);

        $nearest = $this->noneDB->nearest($this->testDbName, 'location', 28.9, 41.0, 5);
        $this->assertCount(0, $nearest);
    }

    /**
     * Test very small distances (meters)
     */
    public function testVerySmallDistances(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Two points ~100 meters apart
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Point A', 'location' => ['type' => 'Point', 'coordinates' => [28.9800, 41.0000]]],
            ['name' => 'Point B', 'location' => ['type' => 'Point', 'coordinates' => [28.9810, 41.0000]]] // ~80m east
        ]);

        // 50m radius - should find only Point A
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9800, 41.0000, 50);
        $this->assertCount(1, $results);
        $this->assertEquals('Point A', $results[0]['name']);

        // 150m radius - should find both
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9800, 41.0000, 150);
        $this->assertCount(2, $results);
    }

    /**
     * Test very large distances (global)
     */
    public function testVeryLargeDistances(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Points on different continents
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Istanbul', 'location' => ['type' => 'Point', 'coordinates' => [28.9, 41.0]]],
            ['name' => 'New York', 'location' => ['type' => 'Point', 'coordinates' => [-74.0, 40.7]]],
            ['name' => 'Tokyo', 'location' => ['type' => 'Point', 'coordinates' => [139.7, 35.7]]]
        ]);

        // 10000km should find Istanbul and NY from Istanbul (10000000m)
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9, 41.0, 10000000);
        $this->assertGreaterThanOrEqual(2, count($results));
        // New York is about 8,060 km and Tokyo about 8,970 km from Istanbul: all three, nearest first
        $this->assertSame(['Istanbul', 'New York', 'Tokyo'], array_column($results, 'name'));
        $this->assertSame(array_keys($this->expectedKeys('location', 28.9, 41.0, 10000000)), array_column($results, 'key'));
        $this->assertSame(['Istanbul', 'New York'], array_column($this->noneDB->withinDistance($this->testDbName, 'location', 28.9, 41.0, 8500000), 'name'));

        // 20000km should find all (20000000m)
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9, 41.0, 20000000);
        $this->assertCount(3, $results);
        // between New York and Tokyo, about 10,850 km
        $ny = Model::hav(-74.0, 40.7, 139.7, 35.7);
        $this->assertSame(['New York', 'Istanbul', 'Tokyo'], array_column($this->noneDB->withinDistance($this->testDbName, 'location', -74.0, 40.7, $ny + 1), 'name'));
        $this->assertSame(['New York', 'Istanbul'], array_column($this->noneDB->withinDistance($this->testDbName, 'location', -74.0, 40.7, $ny - 1000), 'name'));
    }

    /**
     * Test international date line - edge case
     * Note: Date line crossing BBox requires special handling (not currently supported)
     * This test verifies normal queries work near the date line
     */
    public function testInternationalDateLine(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Points near date line (both on same side)
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Fiji', 'location' => ['type' => 'Point', 'coordinates' => [179.0, -18.0]]],
            ['name' => 'Tonga', 'location' => ['type' => 'Point', 'coordinates' => [175.0, -21.0]]]
        ]);

        // BBox on one side of date line
        $results = $this->noneDB->withinBBox($this->testDbName, 'location', 174, -22, 180, -17);
        $this->assertEquals(2, count($results));

        // Distance query works across date line (1000km = 1000000m)
        $nearby = $this->noneDB->withinDistance($this->testDbName, 'location', 179.0, -18.0, 1000000);
        $this->assertGreaterThanOrEqual(1, count($nearby));
        $this->assertSame(['Fiji', 'Tonga'], array_column($nearby, 'name'), 'Tonga is about 530 km away');

        // docs: searches across the antimeridian work: a box with minLon > maxLon, a circle around 180
        $this->noneDB->insert($this->testDbName, ['name' => 'Samoa', 'location' => ['type' => 'Point', 'coordinates' => [-172.0, -13.8]]]);
        $this->assertSame(['Fiji', 'Samoa'], array_column($this->noneDB->withinBBox($this->testDbName, 'location', 178, -20, -170, -10), 'name'));
        $fromDateLine = $this->noneDB->withinDistance($this->testDbName, 'location', -179.5, -16.0, 1200000, ['includeDistance' => true]);
        $expected = $this->expectedKeys('location', -179.5, -16.0, 1200000);
        $this->assertSame(array_keys($expected), array_column($fromDateLine, 'key'));
        $this->assertSame(['Fiji', 'Tonga', 'Samoa'], array_column($fromDateLine, 'name'));
        foreach ($fromDateLine as $row) {
            $this->assertEqualsWithDelta($expected[$row['key']], $row['_distance'], 0.006);
        }
    }

    /**
     * Test poles proximity
     */
    public function testPolesProximity(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Point near North Pole
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Arctic Station', 'location' => ['type' => 'Point', 'coordinates' => [0, 89.0]]]
        ]);

        // Query near pole (100km = 100000m)
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 0, 89.5, 100000);
        $this->assertCount(1, $results);
        $this->assertSame(['Arctic Station'], array_column($results, 'name'));

        // across the pole: (180, 89.5) is 1 degree away from (0, 89.5) over the pole, 111.2 km
        $this->noneDB->insert($this->testDbName, ['name' => 'Other Side', 'location' => ['type' => 'Point', 'coordinates' => [180, 89.5]]]);
        $this->assertSame(['Arctic Station', 'Other Side'], array_column($this->noneDB->withinDistance($this->testDbName, 'location', 0, 89.5, 112000), 'name'));
        $this->assertSame(['Arctic Station'], array_column($this->noneDB->withinDistance($this->testDbName, 'location', 0, 89.5, 111000), 'name'));
    }

    // ========== COMBINED QUERIES ==========

    /**
     * Test spatial + attribute filtering (real-world scenario)
     */
    public function testSpatialWithAttributeFiltering(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Insert diverse data
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Cheap Restaurant', 'type' => 'restaurant', 'rating' => 3, 'price' => 'low',
             'location' => ['type' => 'Point', 'coordinates' => [28.980, 41.008]]],
            ['name' => 'Fancy Restaurant', 'type' => 'restaurant', 'rating' => 5, 'price' => 'high',
             'location' => ['type' => 'Point', 'coordinates' => [28.981, 41.009]]],
            ['name' => 'Budget Hotel', 'type' => 'hotel', 'rating' => 3, 'price' => 'low',
             'location' => ['type' => 'Point', 'coordinates' => [28.982, 41.007]]],
            ['name' => 'Luxury Hotel', 'type' => 'hotel', 'rating' => 5, 'price' => 'high',
             'location' => ['type' => 'Point', 'coordinates' => [28.983, 41.010]]],
            ['name' => 'Cafe', 'type' => 'cafe', 'rating' => 4, 'price' => 'medium',
             'location' => ['type' => 'Point', 'coordinates' => [28.979, 41.006]]]
        ]);

        // Find nearby restaurants with high rating (5km = 5000m radius to include all)
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.980, 41.008, 5000)
            ->where(['type' => 'restaurant', 'rating' => ['$gte' => 4]])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Fancy Restaurant', $results[0]['name']);

        // Find nearby budget places (5km = 5000m)
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.980, 41.008, 5000)
            ->where(['price' => 'low'])
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * Test nearest with filters (find nearest open restaurant)
     */
    public function testNearestWithFilters(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Restaurant A', 'open' => false, 'location' => ['type' => 'Point', 'coordinates' => [28.980, 41.008]]],
            ['name' => 'Restaurant B', 'open' => true, 'location' => ['type' => 'Point', 'coordinates' => [28.985, 41.010]]],
            ['name' => 'Restaurant C', 'open' => true, 'location' => ['type' => 'Point', 'coordinates' => [28.990, 41.012]]]
        ]);

        // Nearest open restaurant
        $results = $this->noneDB->query($this->testDbName)
            ->nearest('location', 28.980, 41.008, 10)
            ->where(['open' => true])
            ->withDistance('location', 28.980, 41.008)
            ->get();

        $this->assertGreaterThan(0, count($results));
        $this->assertTrue($results[0]['open']);
        // Restaurant B should be first (closest open)
        $this->assertEquals('Restaurant B', $results[0]['name']);
        $this->assertSame(['Restaurant B', 'Restaurant C'], array_column($results, 'name'));
        foreach ($results as $row) {
            $this->assertEqualsWithDelta(Model::distance(28.980, 41.008, $row['location']), $row['_distance'], 0.00006, 'withDistance replaces nearest\'s _distance');
        }
    }

    // ========== GEOMETRY TYPES ==========

    /**
     * Test different geometry types together
     */
    public function testMixedGeometryTypes(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'geometry');

        $this->noneDB->insert($this->testDbName, [
            [
                'name' => 'Point Location',
                'type' => 'poi',
                'geometry' => ['type' => 'Point', 'coordinates' => [28.980, 41.008]]
            ],
            [
                'name' => 'Road Segment',
                'type' => 'road',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [[28.975, 41.005], [28.980, 41.008], [28.985, 41.010]]
                ]
            ],
            [
                'name' => 'Park Area',
                'type' => 'park',
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[28.970, 41.000], [28.990, 41.000], [28.990, 41.015], [28.970, 41.015], [28.970, 41.000]]]
                ]
            ]
        ]);

        // All should be found within the area
        $results = $this->noneDB->withinBBox($this->testDbName, 'geometry', 28.96, 40.99, 29.00, 41.02);
        $this->assertCount(3, $results);
        $this->assertSame(['Point Location', 'Road Segment', 'Park Area'], array_column($results, 'name'));
        // a box that only the road's and the park's bounding boxes reach
        $this->assertSame(['Road Segment', 'Park Area'], array_column($this->noneDB->withinBBox($this->testDbName, 'geometry', 28.9825, 41.0095, 28.984, 41.0098), 'name'));
        // a point inside the park is at distance 0 from it; the road passes through the point
        $near = $this->noneDB->withinDistance($this->testDbName, 'geometry', 28.980, 41.008, 1, ['includeDistance' => true]);
        $this->assertSame([0.0, 0.0, 0.0], array_column($near, '_distance'));
    }

    /**
     * Test polygon with hole
     */
    public function testPolygonWithHole(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'boundary');

        // Park with a lake (hole) in the middle
        $this->noneDB->insert($this->testDbName, [
            'name' => 'City Park',
            'boundary' => [
                'type' => 'Polygon',
                'coordinates' => [
                    [[28.97, 41.00], [29.03, 41.00], [29.03, 41.06], [28.97, 41.06], [28.97, 41.00]], // Outer
                    [[28.99, 41.02], [29.01, 41.02], [29.01, 41.04], [28.99, 41.04], [28.99, 41.02]]  // Inner (hole)
                ]
            ]
        ]);

        // Point in outer ring but outside hole should be found
        $results = $this->noneDB->withinBBox($this->testDbName, 'boundary', 28.96, 40.99, 29.04, 41.07);
        $this->assertCount(1, $results);
        // docs: withinBBox compares bounding boxes: a box inside the lake still finds the park
        $this->assertCount(1, $this->noneDB->withinBBox($this->testDbName, 'boundary', 28.995, 41.025, 29.005, 41.035));
        // a point in the lake is not inside the park: its distance is the one to the lake's shore
        $lake = Model::distance(29.0, 41.03, $this->noneDB->find($this->testDbName, 0)[0]['boundary']);
        $this->assertGreaterThan(800, $lake, 'model: about 840 m to the nearest shore');
        $this->assertSame([], $this->noneDB->withinDistance($this->testDbName, 'boundary', 29.0, 41.03, $lake - 1));
        $found = $this->noneDB->withinDistance($this->testDbName, 'boundary', 29.0, 41.03, $lake + 1, ['includeDistance' => true]);
        $this->assertCount(1, $found);
        $this->assertEqualsWithDelta($lake, $found[0]['_distance'], 0.5);
        // a point in the park itself is at distance 0
        $this->assertSame(0.0, $this->noneDB->nearest($this->testDbName, 'boundary', 28.98, 41.01, 1)[0]['_distance']);
        // withinPolygon: a point record in the lake is not within the park polygon
        $park = $this->noneDB->find($this->testDbName, 0)[0]['boundary'];
        $this->noneDB->createSpatialIndex($this->testDbName, 'spot');
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'in the lake', 'spot' => ['type' => 'Point', 'coordinates' => [29.0, 41.03]]],
            ['name' => 'on the shore', 'spot' => ['type' => 'Point', 'coordinates' => [28.99, 41.03]]],
            ['name' => 'in the park', 'spot' => ['type' => 'Point', 'coordinates' => [28.98, 41.01]]],
        ]);
        $this->assertSame(['on the shore', 'in the park'], array_column($this->noneDB->withinPolygon($this->testDbName, 'spot', $park), 'name'));
    }

    // ========== CONCURRENT OPERATIONS ==========

    /**
     * Test multiple spatial index creates on same field (should fail)
     */
    public function testDuplicateSpatialIndexCreate(): void
    {
        $result1 = $this->noneDB->createSpatialIndex($this->testDbName, 'location');
        $this->assertTrue($result1['success']);

        $result2 = $this->noneDB->createSpatialIndex($this->testDbName, 'location');
        $this->assertFalse($result2['success']);
        $this->assertArrayHasKey('error', $result2);
        $this->assertSame(['success' => false, 'indexed' => 0, 'error' => "Spatial index already exists for field 'location'"], $result2);
        $this->assertSame(['location'], $this->noneDB->getSpatialIndexes($this->testDbName));
    }

    /**
     * Test drop and recreate spatial index
     */
    public function testDropAndRecreateSpatialIndex(): void
    {
        // Create and populate
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'A', 'location' => ['type' => 'Point', 'coordinates' => [28.9, 41.0]]]
        ]);

        // Verify works (1km = 1000m)
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9, 41.0, 1000);
        $this->assertCount(1, $results);

        // Drop
        $this->noneDB->dropSpatialIndex($this->testDbName, 'location');

        // Recreate
        $this->assertSame(['success' => true, 'indexed' => 1, 'error' => null], $this->noneDB->createSpatialIndex($this->testDbName, 'location'));

        // Should still work (rebuild from data)
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9, 41.0, 1000);
        $this->assertCount(1, $results);
        $this->assertSame(['A'], array_column($results, 'name'));
    }

    // ========== REAL-WORLD SCENARIOS ==========

    /**
     * Test food delivery app scenario: find restaurants within delivery radius
     */
    public function testFoodDeliveryScenario(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Restaurants with delivery radius in meters
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Pizza Place', 'cuisine' => 'italian', 'delivery_radius_m' => 5000,
             'min_order' => 50, 'rating' => 4.5, 'open' => true,
             'location' => ['type' => 'Point', 'coordinates' => [28.980, 41.008]]],
            ['name' => 'Burger Joint', 'cuisine' => 'american', 'delivery_radius_m' => 3000,
             'min_order' => 30, 'rating' => 4.2, 'open' => true,
             'location' => ['type' => 'Point', 'coordinates' => [28.985, 41.010]]],
            ['name' => 'Sushi Bar', 'cuisine' => 'japanese', 'delivery_radius_m' => 7000,
             'min_order' => 100, 'rating' => 4.8, 'open' => false,
             'location' => ['type' => 'Point', 'coordinates' => [28.990, 41.005]]]
        ]);

        // Customer location
        $customerLon = 28.982;
        $customerLat = 41.009;

        // Find open restaurants that can deliver to customer, sorted by rating (10km = 10000m)
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', $customerLon, $customerLat, 10000)
            ->where(['open' => true])
            ->withDistance('location', $customerLon, $customerLat)
            ->sort('rating', 'desc')
            ->get();

        // Filter by delivery radius (customer must be within restaurant's delivery range, both in meters)
        $canDeliver = array_filter($results, function($r) {
            return $r['_distance'] <= $r['delivery_radius_m'];
        });

        $this->assertGreaterThan(0, count($canDeliver));
        foreach ($canDeliver as $restaurant) {
            $this->assertTrue($restaurant['open']);
            $this->assertLessThanOrEqual($restaurant['delivery_radius_m'], $restaurant['_distance']);
        }
        // both open restaurants are within 10 km and deliver that far; sorted by rating desc
        $this->assertSame(['Pizza Place', 'Burger Joint'], array_column($results, 'name'));
        $this->assertSame(['Pizza Place', 'Burger Joint'], array_column(array_values($canDeliver), 'name'));
        foreach ($results as $row) {
            $this->assertEqualsWithDelta(Model::distance(28.982, 41.009, $row['location']), $row['_distance'], 0.00006);
        }
    }

    /**
     * Test ride-sharing app scenario: find nearest available drivers
     */
    public function testRideSharingScenario(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'current_location');

        // Drivers with various statuses
        $this->noneDB->insert($this->testDbName, [
            ['driver_id' => 'D1', 'status' => 'available', 'car_type' => 'sedan', 'rating' => 4.8,
             'current_location' => ['type' => 'Point', 'coordinates' => [28.980, 41.008]]],
            ['driver_id' => 'D2', 'status' => 'busy', 'car_type' => 'suv', 'rating' => 4.5,
             'current_location' => ['type' => 'Point', 'coordinates' => [28.981, 41.009]]],
            ['driver_id' => 'D3', 'status' => 'available', 'car_type' => 'sedan', 'rating' => 4.9,
             'current_location' => ['type' => 'Point', 'coordinates' => [28.985, 41.010]]],
            ['driver_id' => 'D4', 'status' => 'available', 'car_type' => 'suv', 'rating' => 4.7,
             'current_location' => ['type' => 'Point', 'coordinates' => [28.990, 41.012]]]
        ]);

        // Passenger location
        $passengerLon = 28.982;
        $passengerLat = 41.009;

        // Find nearest available drivers
        $nearestDrivers = $this->noneDB->query($this->testDbName)
            ->nearest('current_location', $passengerLon, $passengerLat, 10)
            ->where(['status' => 'available'])
            ->withDistance('current_location', $passengerLon, $passengerLat)
            ->limit(3)
            ->get();

        $this->assertGreaterThan(0, count($nearestDrivers));
        foreach ($nearestDrivers as $driver) {
            $this->assertEquals('available', $driver['status']);
        }

        // Distances should be in ascending order
        for ($i = 1; $i < count($nearestDrivers); $i++) {
            $this->assertGreaterThanOrEqual(
                $nearestDrivers[$i-1]['_distance'],
                $nearestDrivers[$i]['_distance']
            );
        }
        // the available drivers, nearest first (D3 ~ 300 m, D1 ~ 200 m, D4 ~ 780 m from the passenger)
        $expected = [];
        foreach ($this->noneDB->find($this->testDbName, ['status' => 'available']) as $d) {
            $expected[$d['driver_id']] = Model::distance($passengerLon, $passengerLat, $d['current_location']);
        }
        asort($expected);
        $this->assertSame(array_keys($expected), array_column($nearestDrivers, 'driver_id'));
        $this->assertSame(['D1', 'D3', 'D4'], array_column($nearestDrivers, 'driver_id'));
    }

    /**
     * Test real estate app scenario: find properties in area with price filter
     */
    public function testRealEstateScenario(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Properties
        $this->noneDB->insert($this->testDbName, [
            ['title' => 'Luxury Apartment', 'price' => 500000, 'bedrooms' => 3, 'type' => 'apartment',
             'location' => ['type' => 'Point', 'coordinates' => [28.980, 41.008]]],
            ['title' => 'Budget Studio', 'price' => 150000, 'bedrooms' => 1, 'type' => 'apartment',
             'location' => ['type' => 'Point', 'coordinates' => [28.985, 41.010]]],
            ['title' => 'Family House', 'price' => 800000, 'bedrooms' => 4, 'type' => 'house',
             'location' => ['type' => 'Point', 'coordinates' => [28.990, 41.012]]],
            ['title' => 'Modern Condo', 'price' => 350000, 'bedrooms' => 2, 'type' => 'apartment',
             'location' => ['type' => 'Point', 'coordinates' => [28.975, 41.005]]]
        ]);

        // Search area polygon (neighborhood boundary)
        $searchArea = [
            'type' => 'Polygon',
            'coordinates' => [[[28.97, 41.00], [29.00, 41.00], [29.00, 41.02], [28.97, 41.02], [28.97, 41.00]]]
        ];

        // Find apartments under 400k in the area
        $results = $this->noneDB->query($this->testDbName)
            ->withinPolygon('location', $searchArea)
            ->where([
                'type' => 'apartment',
                'price' => ['$lte' => 400000]
            ])
            ->sort('price', 'asc')
            ->get();

        $this->assertGreaterThan(0, count($results));
        foreach ($results as $property) {
            $this->assertEquals('apartment', $property['type']);
            $this->assertLessThanOrEqual(400000, $property['price']);
        }
        // every property lies in the area; the two apartments under 400k, cheapest first
        $this->assertSame(['Budget Studio', 'Modern Condo'], array_column($results, 'title'));
        $this->assertCount(4, $this->noneDB->withinPolygon($this->testDbName, 'location', $searchArea));
    }
}
