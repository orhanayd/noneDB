<?php
/**
 * Spatial + Comparison Operator Combination Tests
 * Tests combining spatial queries with MongoDB-style comparison operators
 */

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;
use noneDB\Tests\Integration\SpecSpatialTest as Model;

class SpatialOperatorCombinationTest extends noneDBTestCase
{
    /** @var array The records of setUp() */
    private $places = [];

    /**
     * The places within $meters of (28.978, 41.006) that pass $filter: name => distance, nearest first
     * (independent haversine, docs: R = 6,371 km)
     */
    private function expected(float $meters, callable $filter): array
    {
        $out = [];
        foreach ($this->places as $place) {
            $d = Model::hav(28.978, 41.006, $place['location']['coordinates'][0], $place['location']['coordinates'][1]);
            if ($d <= $meters && $filter($place)) {
                $out[$place['name']] = $d;
            }
        }
        asort($out);
        return $out;
    }

    private function assertNames(array $expected, array $results, string $message = ''): void
    {
        $names = array_column($results, 'name');
        sort($names);
        sort($expected);
        $this->assertSame($expected, $names, $message);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Create spatial index
        $this->noneDB->createSpatialIndex($this->testDbName, 'location');

        // Insert realistic test data - restaurants in Istanbul
        $this->noneDB->insert($this->testDbName, $this->places = [
            // Sultanahmet area
            [
                'name' => 'Ottoman Kitchen',
                'category' => 'restaurant',
                'cuisine' => 'turkish',
                'price_range' => 3,  // 1-5 scale
                'rating' => 4.5,
                'review_count' => 120,
                'open_now' => true,
                'delivery' => true,
                'location' => ['type' => 'Point', 'coordinates' => [28.9780, 41.0065]]
            ],
            [
                'name' => 'Blue Cafe',
                'category' => 'cafe',
                'cuisine' => 'international',
                'price_range' => 2,
                'rating' => 4.2,
                'review_count' => 85,
                'open_now' => true,
                'delivery' => false,
                'location' => ['type' => 'Point', 'coordinates' => [28.9770, 41.0055]]
            ],
            [
                'name' => 'Sultan Kebab',
                'category' => 'restaurant',
                'cuisine' => 'turkish',
                'price_range' => 2,
                'rating' => 4.8,
                'review_count' => 250,
                'open_now' => false,
                'delivery' => true,
                'location' => ['type' => 'Point', 'coordinates' => [28.9790, 41.0070]]
            ],
            // Taksim area
            [
                'name' => 'Modern Bistro',
                'category' => 'restaurant',
                'cuisine' => 'french',
                'price_range' => 4,
                'rating' => 4.6,
                'review_count' => 95,
                'open_now' => true,
                'delivery' => false,
                'location' => ['type' => 'Point', 'coordinates' => [28.9850, 41.0350]]
            ],
            [
                'name' => 'Street Food Corner',
                'category' => 'fast_food',
                'cuisine' => 'turkish',
                'price_range' => 1,
                'rating' => 4.0,
                'review_count' => 500,
                'open_now' => true,
                'delivery' => true,
                'location' => ['type' => 'Point', 'coordinates' => [28.9860, 41.0360]]
            ],
            // Kadikoy area
            [
                'name' => 'Seafood Paradise',
                'category' => 'restaurant',
                'cuisine' => 'seafood',
                'price_range' => 4,
                'rating' => 4.7,
                'review_count' => 180,
                'open_now' => true,
                'delivery' => false,
                'location' => ['type' => 'Point', 'coordinates' => [29.0250, 40.9900]]
            ],
            [
                'name' => 'Budget Bites',
                'category' => 'fast_food',
                'cuisine' => 'international',
                'price_range' => 1,
                'rating' => 3.5,
                'review_count' => 300,
                'open_now' => false,
                'delivery' => true,
                'location' => ['type' => 'Point', 'coordinates' => [29.0260, 40.9910]]
            ],
            // Besiktas area
            [
                'name' => 'Premium Steak House',
                'category' => 'restaurant',
                'cuisine' => 'steakhouse',
                'price_range' => 5,
                'rating' => 4.9,
                'review_count' => 75,
                'open_now' => true,
                'delivery' => false,
                'location' => ['type' => 'Point', 'coordinates' => [29.0050, 41.0430]]
            ]
        ]);
        $this->assertCount(8, $this->noneDB->find($this->testDbName, 0), 'setup');
    }

    // ========== SPATIAL + $gt/$gte COMBINATIONS ==========

    /**
     * Test withinDistance + $gte rating filter
     */
    public function testWithinDistanceWithGteRating(): void
    {
        // Find highly rated places near Sultanahmet
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 2000)  // 2000m radius
            ->where(['rating' => ['$gte' => 4.5]])
            ->get();

        $this->assertGreaterThan(0, count($results));

        foreach ($results as $record) {
            $this->assertGreaterThanOrEqual(4.5, $record['rating']);
        }
        $this->assertNames(array_keys($this->expected(2000, function ($p) { return $p['rating'] >= 4.5; })), $results);
        $this->assertNames(['Ottoman Kitchen', 'Sultan Kebab'], $results);
    }

    /**
     * Test withinDistance + $gt review_count filter
     */
    public function testWithinDistanceWithGtReviews(): void
    {
        // Find popular places (>100 reviews) near Sultanahmet
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 2000)
            ->where(['review_count' => ['$gt' => 100]])
            ->get();

        foreach ($results as $record) {
            $this->assertGreaterThan(100, $record['review_count']);
        }
        $this->assertNames(array_keys($this->expected(2000, function ($p) { return $p['review_count'] > 100; })), $results);
        $this->assertNotEmpty($results);
    }

    // ========== SPATIAL + $lt/$lte COMBINATIONS ==========

    /**
     * Test withinBBox + $lte price filter
     */
    public function testWithinBBoxWithLtePriceRange(): void
    {
        // Find budget-friendly places in Sultanahmet area
        $results = $this->noneDB->query($this->testDbName)
            ->withinBBox('location', 28.97, 41.00, 28.99, 41.02)
            ->where(['price_range' => ['$lte' => 2]])
            ->get();

        $this->assertGreaterThan(0, count($results));

        foreach ($results as $record) {
            $this->assertLessThanOrEqual(2, $record['price_range']);
        }
        $inBox = [];
        foreach ($this->places as $p) {
            [$x, $y] = $p['location']['coordinates'];
            if ($x >= 28.97 && $x <= 28.99 && $y >= 41.00 && $y <= 41.02 && $p['price_range'] <= 2) {
                $inBox[] = $p['name'];
            }
        }
        $this->assertNames($inBox, $results);
        $this->assertNames(['Blue Cafe', 'Sultan Kebab'], $results);
    }

    /**
     * Test nearest + $lt price filter
     */
    public function testNearestWithLtPrice(): void
    {
        // Find nearest cheap places
        $results = $this->noneDB->query($this->testDbName)
            ->nearest('location', 28.978, 41.006, 10)
            ->where(['price_range' => ['$lt' => 3]])
            ->withDistance('location', 28.978, 41.006)
            ->get();

        $this->assertGreaterThan(0, count($results));

        foreach ($results as $record) {
            $this->assertLessThan(3, $record['price_range']);
        }

        // Verify sorted by distance
        for ($i = 1; $i < count($results); $i++) {
            $this->assertGreaterThanOrEqual(
                $results[$i-1]['_distance'],
                $results[$i]['_distance']
            );
        }
        // the 10 nearest among the cheap ones (all of them), nearest first; withDistance: 4 decimals
        $expected = $this->expected(INF, function ($p) { return $p['price_range'] < 3; });
        $this->assertSame(array_keys($expected), array_column($results, 'name'));
        foreach ($results as $record) {
            $this->assertEqualsWithDelta($expected[$record['name']], $record['_distance'], 0.00006);
        }
    }

    // ========== SPATIAL + $ne COMBINATIONS ==========

    /**
     * Test withinDistance + $ne category filter
     */
    public function testWithinDistanceWithNeCategory(): void
    {
        // Find everything except fast food
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 5000)
            ->where(['category' => ['$ne' => 'fast_food']])
            ->get();

        foreach ($results as $record) {
            $this->assertNotEquals('fast_food', $record['category']);
        }
        $this->assertNames(array_keys($this->expected(5000, function ($p) { return $p['category'] !== 'fast_food'; })), $results);
        $this->assertCount(6, $results);
    }

    // ========== SPATIAL + $in COMBINATIONS ==========

    /**
     * Test withinDistance + $in category filter
     */
    public function testWithinDistanceWithInCategories(): void
    {
        // Find restaurants or cafes near Sultanahmet
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 2000)
            ->where(['category' => ['$in' => ['restaurant', 'cafe']]])
            ->get();

        $this->assertGreaterThan(0, count($results));

        foreach ($results as $record) {
            $this->assertContains($record['category'], ['restaurant', 'cafe']);
        }
        $this->assertNames(array_keys($this->expected(2000, function ($p) { return in_array($p['category'], ['restaurant', 'cafe'], true); })), $results);
        $this->assertNames(['Blue Cafe', 'Ottoman Kitchen', 'Sultan Kebab'], $results);
    }

    /**
     * Test withinPolygon + $in cuisine filter
     */
    public function testWithinPolygonWithInCuisine(): void
    {
        // Sultanahmet polygon
        $polygon = [
            'type' => 'Polygon',
            'coordinates' => [[[28.97, 41.00], [28.99, 41.00], [28.99, 41.02], [28.97, 41.02], [28.97, 41.00]]]
        ];

        // Find Turkish or international cuisine places
        $results = $this->noneDB->query($this->testDbName)
            ->withinPolygon('location', $polygon)
            ->where(['cuisine' => ['$in' => ['turkish', 'international']]])
            ->get();

        foreach ($results as $record) {
            $this->assertContains($record['cuisine'], ['turkish', 'international']);
        }
        $inside = [];
        foreach ($this->places as $p) {
            [$x, $y] = $p['location']['coordinates'];
            if (Model::inClosedPolygon($x, $y, $polygon['coordinates']) && in_array($p['cuisine'], ['turkish', 'international'], true)) {
                $inside[] = $p['name'];
            }
        }
        $this->assertNames($inside, $results);
        $this->assertNames(['Blue Cafe', 'Ottoman Kitchen', 'Sultan Kebab'], $results);
    }

    // ========== SPATIAL + $nin COMBINATIONS ==========

    /**
     * Test withinDistance + $nin cuisine filter
     */
    public function testWithinDistanceWithNinCuisine(): void
    {
        // Find places NOT serving turkish or french food
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 10000)
            ->where(['cuisine' => ['$nin' => ['turkish', 'french']]])
            ->get();

        foreach ($results as $record) {
            $this->assertNotContains($record['cuisine'], ['turkish', 'french']);
        }
        $this->assertNames(array_keys($this->expected(10000, function ($p) { return !in_array($p['cuisine'], ['turkish', 'french'], true); })), $results);
        $this->assertCount(4, $results);
    }

    // ========== SPATIAL + RANGE ($gte + $lte) ==========

    /**
     * Test withinDistance + range filter
     */
    public function testWithinDistanceWithRangeFilter(): void
    {
        // Find moderately priced places (2-3 range) near Sultanahmet
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 2000)
            ->where(['price_range' => ['$gte' => 2, '$lte' => 3]])
            ->get();

        foreach ($results as $record) {
            $this->assertGreaterThanOrEqual(2, $record['price_range']);
            $this->assertLessThanOrEqual(3, $record['price_range']);
        }
        $this->assertNames(array_keys($this->expected(2000, function ($p) { return $p['price_range'] >= 2 && $p['price_range'] <= 3; })), $results);
        $this->assertCount(3, $results);
    }

    /**
     * Test withinDistance + rating range
     */
    public function testWithinDistanceWithRatingRange(): void
    {
        // Find good but not perfect places (4.0-4.5)
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 5000)
            ->where(['rating' => ['$gte' => 4.0, '$lt' => 4.6]])
            ->get();

        foreach ($results as $record) {
            $this->assertGreaterThanOrEqual(4.0, $record['rating']);
            $this->assertLessThan(4.6, $record['rating']);
        }
        $this->assertNames(array_keys($this->expected(5000, function ($p) { return $p['rating'] >= 4.0 && $p['rating'] < 4.6; })), $results);
        $this->assertNotEmpty($results);
    }

    // ========== SPATIAL + MIXED OPERATORS ==========

    /**
     * Test withinDistance + mixed operators on different fields
     */
    public function testWithinDistanceWithMixedOperators(): void
    {
        // Complex query: near, highly rated, affordable, with delivery
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 3000)
            ->where([
                'rating' => ['$gte' => 4.0],
                'price_range' => ['$lte' => 3],
                'delivery' => true
            ])
            ->get();

        foreach ($results as $record) {
            $this->assertGreaterThanOrEqual(4.0, $record['rating']);
            $this->assertLessThanOrEqual(3, $record['price_range']);
            $this->assertTrue($record['delivery']);
        }
        $this->assertNames(array_keys($this->expected(3000, function ($p) {
            return $p['rating'] >= 4.0 && $p['price_range'] <= 3 && $p['delivery'] === true;
        })), $results);
        $this->assertNames(['Ottoman Kitchen', 'Sultan Kebab'], $results);
    }

    /**
     * Test nearest + multiple operator filters
     */
    public function testNearestWithMultipleOperators(): void
    {
        // Find nearest open restaurants with good ratings
        $results = $this->noneDB->query($this->testDbName)
            ->nearest('location', 28.978, 41.006, 10)
            ->where([
                'category' => 'restaurant',
                'open_now' => true,
                'rating' => ['$gt' => 4.0],
                'review_count' => ['$gte' => 50]
            ])
            ->limit(3)
            ->get();

        foreach ($results as $record) {
            $this->assertEquals('restaurant', $record['category']);
            $this->assertTrue($record['open_now']);
            $this->assertGreaterThan(4.0, $record['rating']);
            $this->assertGreaterThanOrEqual(50, $record['review_count']);
        }
        // docs: nearest()'s k after the other filters, then limit(3): the 3 nearest matching ones, nearest first
        $expected = $this->expected(INF, function ($p) {
            return $p['category'] === 'restaurant' && $p['open_now'] === true && $p['rating'] > 4.0 && $p['review_count'] >= 50;
        });
        $this->assertSame(array_slice(array_keys($expected), 0, 3), array_column($results, 'name'));
        $this->assertCount(3, $results);
    }

    // ========== SPATIAL + $like COMBINATIONS ==========

    /**
     * Test withinDistance + $like name filter
     */
    public function testWithinDistanceWithLikeName(): void
    {
        // Find places with "Sultan" in name
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 5000)
            ->where(['name' => ['$like' => 'Sultan']])
            ->get();

        foreach ($results as $record) {
            $this->assertStringContainsStringIgnoringCase('Sultan', $record['name']);
        }
        $this->assertNames(array_keys($this->expected(5000, function ($p) { return stripos($p['name'], 'Sultan') !== false; })), $results);
        $this->assertNames(['Sultan Kebab'], $results);
    }

    /**
     * Test withinDistance + $like starts with
     */
    public function testWithinDistanceWithLikeStartsWith(): void
    {
        // Find places starting with "B"
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 10000)
            ->where(['name' => ['$like' => '^B']])
            ->get();

        foreach ($results as $record) {
            $this->assertMatchesRegularExpression('/^B/i', $record['name']);
        }
        $this->assertNames(array_keys($this->expected(10000, function ($p) { return stripos($p['name'], 'B') === 0; })), $results);
        $this->assertNames(['Blue Cafe', 'Budget Bites'], $results);
    }

    // ========== SPATIAL + $exists COMBINATIONS ==========

    /**
     * Test withinDistance + $exists
     */
    public function testWithinDistanceWithExists(): void
    {
        // Add a place without delivery field
        $this->noneDB->insert($this->testDbName, [
            'name' => 'Mystery Place',
            'category' => 'restaurant',
            'cuisine' => 'mystery',
            'price_range' => 3,
            'rating' => 4.0,
            'review_count' => 10,
            'open_now' => true,
            // NO delivery field
            'location' => ['type' => 'Point', 'coordinates' => [28.9785, 41.0068]]
        ]);

        // Find places with delivery option specified
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 2000)
            ->where(['delivery' => ['$exists' => true]])
            ->get();

        foreach ($results as $record) {
            $this->assertArrayHasKey('delivery', $record);
        }
        $this->assertNames(array_keys($this->expected(2000, function ($p) { return true; })), $results, 'every place within 2 km but the new one');
        $this->assertCount(3, $results);

        // Find places without delivery option specified
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 2000)
            ->where(['delivery' => ['$exists' => false]])
            ->get();

        foreach ($results as $record) {
            $this->assertArrayNotHasKey('delivery', $record);
        }
        $this->assertNames(['Mystery Place'], $results);
    }

    // ========== SPATIAL + SORT + OPERATORS ==========

    /**
     * Test spatial + operators + sort
     */
    public function testSpatialWithOperatorsAndSort(): void
    {
        // Find affordable places, sorted by rating
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 5000)
            ->where(['price_range' => ['$lte' => 3]])
            ->sort('rating', 'desc')
            ->get();

        // Verify sorted by rating descending
        $prevRating = PHP_INT_MAX;
        foreach ($results as $record) {
            $this->assertLessThanOrEqual($prevRating, $record['rating']);
            $prevRating = $record['rating'];
        }
        $expected = $this->expected(5000, function ($p) { return $p['price_range'] <= 3; });
        uksort($expected, function ($a, $b) { return $this->rating($b) <=> $this->rating($a); });
        $this->assertSame(array_keys($expected), array_column($results, 'name'));
        $this->assertSame(['Sultan Kebab', 'Ottoman Kitchen', 'Blue Cafe', 'Street Food Corner', 'Budget Bites'], array_column($results, 'name'));
    }

    private function rating(string $name): float
    {
        foreach ($this->places as $p) {
            if ($p['name'] === $name) {
                return $p['rating'];
            }
        }
        throw new \LogicException($name);
    }

    /**
     * Test spatial + operators + distance sort
     */
    public function testSpatialWithOperatorsAndDistanceSort(): void
    {
        // Find highly rated places, sorted by distance
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 10000)
            ->where(['rating' => ['$gte' => 4.0]])
            ->withDistance('location', 28.978, 41.006)
            ->sort('_distance', 'asc')
            ->get();

        // Verify sorted by distance ascending
        $prevDistance = -1;
        foreach ($results as $record) {
            $this->assertGreaterThanOrEqual($prevDistance, $record['_distance']);
            $prevDistance = $record['_distance'];
        }
        $expected = $this->expected(10000, function ($p) { return $p['rating'] >= 4.0; });
        $this->assertSame(array_keys($expected), array_column($results, 'name'));
        foreach ($results as $record) {
            $this->assertEqualsWithDelta($expected[$record['name']], $record['_distance'], 0.00006, 'withDistance: 4 decimals');
        }
    }

    // ========== REAL WORLD SCENARIOS ==========

    /**
     * Scenario: Food delivery app - find restaurants that can deliver
     */
    public function testFoodDeliveryAppScenario(): void
    {
        $userLon = 28.978;
        $userLat = 41.006;
        $maxDeliveryRadius = 3000; // meters

        // User wants: Turkish food, open now, delivery available, affordable, good rating
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', $userLon, $userLat, $maxDeliveryRadius)
            ->where([
                'cuisine' => ['$in' => ['turkish', 'international']],
                'open_now' => true,
                'delivery' => true,
                'price_range' => ['$lte' => 3],
                'rating' => ['$gte' => 4.0]
            ])
            ->withDistance('location', $userLon, $userLat)
            ->sort('rating', 'desc')
            ->get();

        foreach ($results as $record) {
            $this->assertContains($record['cuisine'], ['turkish', 'international']);
            $this->assertTrue($record['open_now']);
            $this->assertTrue($record['delivery']);
            $this->assertLessThanOrEqual(3, $record['price_range']);
            $this->assertGreaterThanOrEqual(4.0, $record['rating']);
        }
        $expected = $this->expected($maxDeliveryRadius, function ($p) {
            return in_array($p['cuisine'], ['turkish', 'international'], true) && $p['open_now'] && $p['delivery']
                && $p['price_range'] <= 3 && $p['rating'] >= 4.0;
        });
        $this->assertSame(array_keys($expected), array_column($results, 'name'));
        $this->assertSame(['Ottoman Kitchen'], array_column($results, 'name'));
        $this->assertEqualsWithDelta($expected['Ottoman Kitchen'], $results[0]['_distance'], 0.00006);
    }

    /**
     * Scenario: Tourist app - find top attractions in area
     */
    public function testTouristAppScenario(): void
    {
        // Tourist at Sultanahmet looking for popular, highly reviewed restaurants
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 2000)
            ->where([
                'category' => 'restaurant',
                'review_count' => ['$gte' => 100],
                'rating' => ['$gte' => 4.5]
            ])
            ->sort('review_count', 'desc')
            ->limit(5)
            ->get();

        foreach ($results as $record) {
            $this->assertEquals('restaurant', $record['category']);
            $this->assertGreaterThanOrEqual(100, $record['review_count']);
            $this->assertGreaterThanOrEqual(4.5, $record['rating']);
        }
        $this->assertSame(['Sultan Kebab', 'Ottoman Kitchen'], array_column($results, 'name'), 'sorted by review_count desc');
        $this->assertNames(array_keys($this->expected(2000, function ($p) {
            return $p['category'] === 'restaurant' && $p['review_count'] >= 100 && $p['rating'] >= 4.5;
        })), $results);
    }

    /**
     * Scenario: Yelp-like search - exclude certain categories
     */
    public function testExcludeCategoriesScenario(): void
    {
        // User wants anything except fast food and cafes
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 10000)
            ->where([
                'category' => ['$nin' => ['fast_food', 'cafe']],
                'open_now' => true
            ])
            ->get();

        foreach ($results as $record) {
            $this->assertNotContains($record['category'], ['fast_food', 'cafe']);
            $this->assertTrue($record['open_now']);
        }
        $this->assertNames(array_keys($this->expected(10000, function ($p) {
            return !in_array($p['category'], ['fast_food', 'cafe'], true) && $p['open_now'] === true;
        })), $results);
        $this->assertNames(['Modern Bistro', 'Ottoman Kitchen', 'Premium Steak House', 'Seafood Paradise'], $results);
    }

    /**
     * Scenario: Budget traveler - cheapest options nearby
     */
    public function testBudgetTravelerScenario(): void
    {
        // Find cheapest options with acceptable ratings
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 5000)
            ->where([
                'price_range' => ['$lte' => 2],
                'rating' => ['$gte' => 3.5]
            ])
            ->sort('price_range', 'asc')
            ->get();

        foreach ($results as $record) {
            $this->assertLessThanOrEqual(2, $record['price_range']);
            $this->assertGreaterThanOrEqual(3.5, $record['rating']);
        }
        $this->assertNames(array_keys($this->expected(5000, function ($p) { return $p['price_range'] <= 2 && $p['rating'] >= 3.5; })), $results);
        $this->assertSame([1, 1, 2, 2], array_column($results, 'price_range'), 'sorted by price_range asc');
    }

    // ========== EDGE CASES ==========

    /**
     * Test operators with no spatial results
     */
    public function testOperatorsWithNoSpatialResults(): void
    {
        // Search in area with no data (London)
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', -0.1276, 51.5074, 1000)
            ->where(['rating' => ['$gte' => 4.0]])
            ->get();

        $this->assertCount(0, $results);
    }

    /**
     * Test operators that filter all spatial results
     */
    public function testOperatorsThatFilterAllResults(): void
    {
        // Find places with impossibly high rating
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 5000)
            ->where(['rating' => ['$gt' => 5.0]])
            ->get();

        $this->assertCount(0, $results);
    }

    /**
     * Test empty operator arrays
     */
    public function testEmptyOperatorArrays(): void
    {
        // $in with empty array should return nothing
        $results = $this->noneDB->query($this->testDbName)
            ->withinDistance('location', 28.978, 41.006, 5000)
            ->where(['category' => ['$in' => []]])
            ->get();

        $this->assertCount(0, $results);
    }
}
