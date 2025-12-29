<?php
/**
 * Spatial Index Feature Tests
 * Tests spatial index creation, CRUD integration, and queries
 * @version 3.1.0
 */

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

class SpatialIndexTest extends noneDBTestCase
{
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
    }

    /**
     * Test hasSpatialIndex method
     */
    public function testHasSpatialIndex(): void
    {
        $this->assertFalse($this->noneDB->hasSpatialIndex($this->testDbName, 'location'));

        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        $this->assertTrue($this->noneDB->hasSpatialIndex($this->testDbName, 'location'));
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

        // Delete it
        $this->noneDB->delete($this->testDbName, ['key' => $hagiaSophiaKey]);

        // Should no longer find it
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9803, 41.0086, 10);
        $names = array_column($results, 'name');
        $this->assertNotContains('Hagia Sophia', $names);
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

        // Should NOT find it at old location
        $results = $this->noneDB->withinDistance($this->testDbName, 'location', 28.9803, 41.0086, 10);
        $names = array_column($results, 'name');
        $this->assertNotContains('Hagia Sophia', $names);
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
    }
}
