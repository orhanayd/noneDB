<?php
/**
 * Spatial Index Feature Tests
 * Tests spatial index creation, CRUD integration, and queries
 */

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;
use noneDB\Tests\Integration\SpecSpatialTest as Model;

class SpatialIndexTest extends noneDBTestCase
{
    /** The records of setUp(), in key order */
    private const PLACES = [
        'Hagia Sophia' => [28.9803, 41.0086],
        'Blue Mosque' => [28.9768, 41.0054],
        'Topkapi Palace' => [28.9833, 41.0115],
        'Grand Bazaar' => [28.9680, 41.0106],
        'Galata Tower' => [28.9741, 41.0256],
    ];

    /** Names within $meters of a point, nearest first (independent haversine, docs: R = 6,371 km) */
    private function expectedWithin(float $lon, float $lat, float $meters, array $places = self::PLACES): array
    {
        $distances = [];
        foreach ($places as $name => [$x, $y]) {
            $d = Model::hav($lon, $lat, $x, $y);
            if ($d <= $meters) {
                $distances[$name] = $d;
            }
        }
        asort($distances);
        return $distances;
    }

    /** Names whose point lies in the closed box */
    private function expectedInBox(float $minLon, float $minLat, float $maxLon, float $maxLat): array
    {
        $names = [];
        foreach (self::PLACES as $name => [$x, $y]) {
            if ($minLon <= $x && $x <= $maxLon && $minLat <= $y && $y <= $maxLat) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    private static function sortedNames(array $rows): array
    {
        $names = array_column($rows, 'name');
        sort($names);
        return $names;
    }
    protected function setUp(): void
    {
        parent::setUp();

        // Insert test data
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Hagia Sophia', 'location' => ['type' => 'Point', 'coordinates' => [28.9803, 41.0086]]],
            ['name' => 'Blue Mosque', 'location' => ['type' => 'Point', 'coordinates' => [28.9768, 41.0054]]],
            ['name' => 'Topkapi Palace', 'location' => ['type' => 'Point', 'coordinates' => [28.9833, 41.0115]]],
            ['name' => 'Grand Bazaar', 'location' => ['type' => 'Point', 'coordinates' => [28.9680, 41.0106]]],
            ['name' => 'Galata Tower', 'location' => ['type' => 'Point', 'coordinates' => [28.9741, 41.0256]]]
        ]);
    }

    // ========== Spatial Index Management ==========

    /**
     * Test creating spatial index
     */
    public function testCreateSpatialIndex(): void
    {
        $result = $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['error'] ?? '');
        $this->assertSame(['success' => true, 'indexed' => 5, 'error' => null], $result);
    }

    /**
     * Test creating spatial index on same field twice fails
     */
    public function testCreateSpatialIndexDuplicate(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');
        $result = $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(['success' => false, 'indexed' => 0, 'error' => "Spatial index already exists for field 'location'"], $result);
    }

    /**
     * Test getting spatial indexes list
     */
    public function testGetSpatialIndexes(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $indexes = $this->noneDB->getSpatialIndexes($this->testDbName);

        $this->assertIsArray($indexes);
        $this->assertContains('location', $indexes);
        $this->assertSame(['location'], $indexes);
    }

    /**
     * Test dropping spatial index
     */
    public function testDropSpatialIndex(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');
        $result = $this->noneDB->dropSpatialIndex($this->testDbName, 'location');

        $this->assertTrue($result['success']);

        $indexes = $this->noneDB->getSpatialIndexes($this->testDbName);
        $this->assertNotContains('location', $indexes);
        $this->assertSame(['success' => true, 'error' => null], $result);
        $this->assertSame([], $indexes);
        $this->assertFalse($this->noneDB->hasSpatialIndex($this->testDbName, 'location'));
        // the data stays and is still queried (by a scan)
        $this->assertSame(['Hagia Sophia'], array_column($this->noneDB->withinDistance($this->testDbName, 'location', 28.9803, 41.0086, 10), 'name'));
    }

    /**
     * Test hasSpatialIndex method
     */
    public function testHasSpatialIndex(): void
    {
        $this->assertFalse($this->noneDB->hasSpatialIndex($this->testDbName, 'location'));

        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $this->assertTrue($this->noneDB->hasSpatialIndex($this->testDbName, 'location'));
        $this->assertFalse($this->noneDB->hasSpatialIndex($this->testDbName, 'name'));
        $this->noneDB->dropSpatialIndex($this->testDbName, 'location');
        $this->assertFalse($this->noneDB->hasSpatialIndex($this->testDbName, 'location'));
    }

    // ========== WithinDistance Queries ==========

    /**
     * Test withinDistance query
     */
    public function testWithinDistance(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Query within 1000m (1km) of Hagia Sophia
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9803, 41.0086, 1000);

        $this->assertIsArray($results);
        $this->assertGreaterThan(0, count($results));

        // Hagia Sophia should be in results (distance 0)
        $names = array_column($results, 'name');
        $this->assertContains('Hagia Sophia', $names);
        // exactly the places within 1000 m, nearest first (Grand Bazaar is about 1060 m away)
        $this->assertSame(array_keys($this->expectedWithin(28.9803, 41.0086, 1000)), $names);
        $this->assertSame(['Hagia Sophia', 'Topkapi Palace', 'Blue Mosque'], $names);
    }

    /**
     * Test withinDistance returns empty for far locations
     */
    public function testWithinDistanceNoResults(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Query in completely different location (London)
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', -0.1276, 51.5074, 1000);

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    // ========== WithinBBox Queries ==========

    /**
     * Test withinBBox query
     */
    public function testWithinBBox(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // BBox covering Sultanahmet area
        $results = $this->noneDB->withinBBox($this->testDbName, 'location',
            28.97, 41.00,  // minLon, minLat
            28.99, 41.02   // maxLon, maxLat
        );

        $this->assertIsArray($results);
        $this->assertGreaterThan(0, count($results));
        $this->assertSame($this->expectedInBox(28.97, 41.00, 28.99, 41.02), self::sortedNames($results));
        $this->assertSame(['Blue Mosque', 'Hagia Sophia', 'Topkapi Palace'], self::sortedNames($results));
    }

    /**
     * Test withinBBox returns empty for non-overlapping area
     */
    public function testWithinBBoxNoResults(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // BBox far from Istanbul
        $results = $this->noneDB->withinBBox($this->testDbName, 'location',
            0, 0, 1, 1  // Near Africa
        );

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    // ========== Nearest Queries ==========

    /**
     * Test nearest query
     */
    public function testNearest(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Find 3 nearest to Hagia Sophia
        $results = $this->noneDB->nearest($this->testDbName, 'location', 28.9803, 41.0086, 3);

        $this->assertIsArray($results);
        $this->assertCount(3, $results);

        // First result should be Hagia Sophia itself (distance 0)
        $this->assertEquals('Hagia Sophia', $results[0]['name']);
        $this->assertSame(array_slice(array_keys($this->expectedWithin(28.9803, 41.0086, INF)), 0, 3), array_column($results, 'name'));
    }

    /**
     * Test nearest with distance field
     */
    public function testNearestWithDistance(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $results = $this->noneDB->nearest($this->testDbName, 'location', 28.9803, 41.0086, 3, [
            'includeDistance' => true
        ]);

        $this->assertIsArray($results);
        $this->assertArrayHasKey('_distance', $results[0]);
        $this->assertEquals(0, $results[0]['_distance']); // Hagia Sophia distance to itself
        $expected = array_slice($this->expectedWithin(28.9803, 41.0086, INF), 0, 3, true);
        $this->assertSame(array_keys($expected), array_column($results, 'name'));
        foreach ($results as $i => $row) {
            $this->assertEqualsWithDelta(array_values($expected)[$i], $row['_distance'], 0.006, $row['name'] . ': meters, 2 decimals');
            $this->assertSame(round($row['_distance'], 2), $row['_distance']);
        }
    }

    // ========== WithinPolygon Queries ==========

    /**
     * Test withinPolygon query
     */
    public function testWithinPolygon(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Polygon covering Sultanahmet
        $polygon = [
            'type' => 'Polygon',
            'coordinates' => [[
                [28.96, 41.00],
                [28.99, 41.00],
                [28.99, 41.02],
                [28.96, 41.02],
                [28.96, 41.00]
            ]]
        ];

        $results = $this->noneDB->withinPolygon($this->testDbName, 'location', $polygon);

        $this->assertIsArray($results);
        $this->assertGreaterThan(0, count($results));
        // a rectangle: the same points as the box (docs)
        $this->assertSame($this->expectedInBox(28.96, 41.00, 28.99, 41.02), self::sortedNames($results));
        $this->assertSame(['Blue Mosque', 'Grand Bazaar', 'Hagia Sophia', 'Topkapi Palace'], self::sortedNames($results));
    }

    // ========== CRUD Integration ==========

    /**
     * Test insert automatically updates spatial index
     */
    public function testInsertUpdatesSpatialIndex(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Insert new location
        $this->noneDB->insert($this->testDbName, [
            'name' => 'Maiden Tower',
            'location' => ['type' => 'Point', 'coordinates' => [29.0041, 41.0211]]
        ]);

        // Should find the new location (100m radius)
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 29.0041, 41.0211, 100);

        $names = array_column($results, 'name');
        $this->assertContains('Maiden Tower', $names);
        $this->assertSame(['Maiden Tower'], $names);
        $this->assertSame('Maiden Tower', $this->noneDB->nearest($this->testDbName, 'location', 29.0041, 41.0211, 1)[0]['name']);
    }

    /**
     * Test delete removes from spatial index
     */
    public function testDeleteRemovesFromSpatialIndex(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Find Hagia Sophia first (10m radius)
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9803, 41.0086, 10);
        $this->assertGreaterThan(0, count($results));
        $hagiaSophiaKey = null;
        foreach ($results as $r) {
            if ($r['name'] === 'Hagia Sophia') {
                $hagiaSophiaKey = $r['key'];
                break;
            }
        }
        $this->assertNotNull($hagiaSophiaKey);
        $this->assertSame(['Hagia Sophia'], array_column($results, 'name'));
        $this->assertSame(0, $hagiaSophiaKey);

        // Delete it
        $this->noneDB->delete($this->testDbName, ['key' => $hagiaSophiaKey]);

        // Should no longer find it
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9803, 41.0086, 10);
        $names = array_column($results, 'name');
        $this->assertNotContains('Hagia Sophia', $names);
        $this->assertSame([], $results);
        $this->assertSame(['Topkapi Palace', 'Blue Mosque'], array_column($this->noneDB->nearest($this->testDbName, 'location', 28.9803, 41.0086, 2), 'name'));
    }

    /**
     * Test update updates spatial index
     */
    public function testUpdateUpdatesSpatialIndex(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Find Hagia Sophia (10m radius)
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9803, 41.0086, 10);
        $hagiaSophiaKey = null;
        foreach ($results as $r) {
            if ($r['name'] === 'Hagia Sophia') {
                $hagiaSophiaKey = $r['key'];
                break;
            }
        }

        // Update location
        $newLocation = ['type' => 'Point', 'coordinates' => [32.8597, 39.9334]]; // Ankara
        $this->noneDB->update($this->testDbName, [
            ['key' => $hagiaSophiaKey],
            ['set' => ['location' => $newLocation]]
        ]);

        // Should find it at new location (1000m radius)
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 32.8597, 39.9334, 1000);
        $names = array_column($results, 'name');
        $this->assertContains('Hagia Sophia', $names);
        $this->assertSame(['Hagia Sophia'], $names);

        // Should NOT find it at old location
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9803, 41.0086, 10);
        $names = array_column($results, 'name');
        $this->assertNotContains('Hagia Sophia', $names);
        $this->assertSame([], $results);
        $moved = self::PLACES;
        $moved['Hagia Sophia'] = [32.8597, 39.9334];
        $this->assertSame(array_keys($this->expectedWithin(28.9803, 41.0086, 5000, $moved)),
            array_column($this->noneDB->withinDistance($this->testDbName, 'location', 28.9803, 41.0086, 5000), 'name'));
    }

    // ========== Query Builder Integration ==========

    /**
     * Test query builder withinDistance
     */
    public function testQueryBuilderWithinDistance(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.9803, 41.0086, 1000)
            ->get();

        $this->assertIsArray($results);
        $this->assertGreaterThan(0, count($results));
        $expected = array_keys($this->expectedWithin(28.9803, 41.0086, 1000));
        sort($expected);
        $this->assertSame($expected, self::sortedNames($results));
    }

    /**
     * Test query builder withinBBox
     */
    public function testQueryBuilderWithinBBox(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $results = $this->noneDB->query($this->testDbName)
            ->withinBBox('location', 28.97, 41.00, 28.99, 41.02)
            ->get();

        $this->assertIsArray($results);
        $this->assertGreaterThan(0, count($results));
        $this->assertSame($this->expectedInBox(28.97, 41.00, 28.99, 41.02), self::sortedNames($results));
    }

    /**
     * Test query builder nearest
     */
    public function testQueryBuilderNearest(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $results = $this->noneDB->query($this->testDbName)
            ->nearest('location', 28.9803, 41.0086, 2)
            ->get();

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertSame(array_slice(array_keys($this->expectedWithin(28.9803, 41.0086, INF)), 0, 2), array_column($results, 'name'));
    }

    /**
     * Test query builder withinPolygon
     */
    public function testQueryBuilderWithinPolygon(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $polygon = [
            'type' => 'Polygon',
            'coordinates' => [[
                [28.96, 41.00],
                [28.99, 41.00],
                [28.99, 41.02],
                [28.96, 41.02],
                [28.96, 41.00]
            ]]
        ];

        $results = $this->noneDB->query($this->testDbName)
            ->withinPolygon('location', $polygon)
            ->get();

        $this->assertIsArray($results);
        $this->assertGreaterThan(0, count($results));
        $this->assertSame($this->expectedInBox(28.96, 41.00, 28.99, 41.02), self::sortedNames($results));
    }

    /**
     * Test query builder with WHERE and spatial filter
     */
    public function testQueryBuilderSpatialWithWhere(): void
    {
        // Add categorized data
        $this->noneDB->insert($this->testDbName, [
            'name' => 'Restaurant A',
            'category' => 'restaurant',
            'location' => ['type' => 'Point', 'coordinates' => [28.9780, 41.0070]]
        ]);
        $this->noneDB->insert($this->testDbName, [
            'name' => 'Hotel B',
            'category' => 'hotel',
            'location' => ['type' => 'Point', 'coordinates' => [28.9790, 41.0080]]
        ]);

        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.9780, 41.0070, 1000)
            ->where(['category' => 'restaurant'])
            ->get();

        $this->assertIsArray($results);
        foreach ($results as $r) {
            if (isset($r['category'])) {
                $this->assertEquals('restaurant', $r['category']);
            }
        }
        $this->assertSame(['Restaurant A'], array_column($results, 'name'), 'the only restaurant; records without the field never match');
    }

    /**
     * Test query builder withDistance
     */
    public function testQueryBuilderWithDistance(): void
    {
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.9803, 41.0086, 2000)
            ->withDistance('location', 28.9803, 41.0086)
            ->sort('_distance', 'asc')
            ->get();

        $this->assertIsArray($results);
        $this->assertArrayHasKey('_distance', $results[0]);

        // Check results are sorted by distance
        $prevDistance = -1;
        foreach ($results as $r) {
            $this->assertGreaterThanOrEqual($prevDistance, $r['_distance']);
            $prevDistance = $r['_distance'];
        }
        $expected = $this->expectedWithin(28.9803, 41.0086, 2000);
        $this->assertSame(array_keys($expected), array_column($results, 'name'));
        foreach ($results as $r) {
            $this->assertEqualsWithDelta($expected[$r['name']], $r['_distance'], 0.00006, 'withDistance: meters, 4 decimals');
        }
    }
}
