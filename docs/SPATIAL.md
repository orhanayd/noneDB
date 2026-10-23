# noneDB Spatial Index Reference

Comprehensive documentation for noneDB's geospatial capabilities using R-tree indexing.

**Version:** 3.2.0

---

## Table of Contents

1. [Overview](#overview)
2. [Creating Spatial Indexes](#creating-spatial-indexes)
3. [GeoJSON Data Format](#geojson-data-format)
4. [Spatial Query Methods](#spatial-query-methods)
5. [Query Builder Integration](#query-builder-integration)
6. [Combining Spatial with Filters](#combining-spatial-with-filters)
7. [Performance Optimization](#performance-optimization)
8. [Real-World Examples](#real-world-examples)

---

## Overview

noneDB v3.1 introduces spatial indexing with R-tree data structure for efficient geospatial queries. Features include:

- **R-tree indexing** with O(log n) query performance
- **GeoJSON support** for Point, LineString, Polygon, and Multi* types
- **Distance calculations** using Haversine formula
- **Query builder integration** for combining spatial + attribute filters
- **Automatic CRUD synchronization** - index updates on insert/update/delete

---

## Creating Spatial Indexes

### createSpatialIndex()

Create an R-tree spatial index on a geometry field.

```php
$db = new noneDB();

// Create spatial index on 'location' field
$result = $db->createSpatialIndex("restaurants", "location");
// Returns: ["success" => true, "indexed" => 150]

// If index already exists:
// Returns: ["success" => false, "indexed" => 0, "error" => "Spatial index already exists..."]
```

**Important:** Records with invalid or missing GeoJSON are silently skipped during indexing:

```php
// These records will be SKIPPED (not indexed):
$db->insert("places", ["name" => "No location"]);                    // Missing field
$db->insert("places", ["name" => "Bad", "location" => "not array"]); // Not an array
$db->insert("places", ["name" => "Bad", "location" => ["x" => 1]]);  // Missing 'type'
$db->insert("places", ["name" => "Bad", "location" => [
    "type" => "Point",
    "coordinates" => [200, 100]  // Invalid: lon > 180
]]);

// Check how many records were actually indexed:
$result = $db->createSpatialIndex("places", "location");
echo "Indexed: {$result['indexed']} records\n";  // May be 0!

// To validate before insert:
$validation = $db->validateGeoJSON($geometry);
if (!$validation['valid']) {
    echo "Error: " . $validation['error'];
}
```

### hasSpatialIndex()

Check if a spatial index exists.

```php
$exists = $db->hasSpatialIndex("restaurants", "location");
// Returns: true or false
```

### getSpatialIndexes()

List all spatial indexes for a database.

```php
$indexes = $db->getSpatialIndexes("restaurants");
// Returns: ["location", "delivery_area"]
```

### dropSpatialIndex()

Remove a spatial index.

```php
$result = $db->dropSpatialIndex("restaurants", "location");
// Returns: ["success" => true]
```

---

## GeoJSON Data Format

noneDB supports standard GeoJSON geometry types. Coordinates are `[longitude, latitude]`.

### Point

A single location.

```php
$location = [
    'type' => 'Point',
    'coordinates' => [28.9784, 41.0082]  // [lon, lat]
];

$db->insert("restaurants", [
    'name' => 'Ottoman Kitchen',
    'location' => $location
]);
```

### LineString

A path or route.

```php
$route = [
    'type' => 'LineString',
    'coordinates' => [
        [28.97, 41.00],
        [28.98, 41.01],
        [28.99, 41.02]
    ]
];

$db->insert("routes", [
    'name' => 'Scenic Drive',
    'path' => $route
]);
```

### Polygon

An area with boundary. First and last points must be identical (closed ring).

```php
$area = [
    'type' => 'Polygon',
    'coordinates' => [[
        [28.97, 41.00],  // First point
        [29.00, 41.00],
        [29.00, 41.03],
        [28.97, 41.03],
        [28.97, 41.00]   // Last point = First point (closed)
    ]]
];

$db->insert("zones", [
    'name' => 'Delivery Zone A',
    'boundary' => $area
]);
```

### Polygon with Hole

Polygon with inner exclusion zone.

```php
$parkWithLake = [
    'type' => 'Polygon',
    'coordinates' => [
        // Outer ring (park boundary)
        [[28.97, 41.00], [29.03, 41.00], [29.03, 41.06], [28.97, 41.06], [28.97, 41.00]],
        // Inner ring (lake - hole)
        [[28.99, 41.02], [29.01, 41.02], [29.01, 41.04], [28.99, 41.04], [28.99, 41.02]]
    ]
];
```

### MultiPoint

Multiple points in one geometry.

```php
$locations = [
    'type' => 'MultiPoint',
    'coordinates' => [
        [28.98, 41.00],
        [28.99, 41.01],
        [29.00, 41.02]
    ]
];
```

### MultiLineString

Multiple paths in one geometry.

```php
$routes = [
    'type' => 'MultiLineString',
    'coordinates' => [
        [[28.97, 41.00], [28.98, 41.01]],
        [[29.00, 41.02], [29.01, 41.03]]
    ]
];
```

### MultiPolygon

Multiple polygons in one geometry.

```php
$zones = [
    'type' => 'MultiPolygon',
    'coordinates' => [
        [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]],
        [[[2, 2], [3, 2], [3, 3], [2, 3], [2, 2]]]
    ]
];
```

---

## Spatial Query Methods

### withinDistance()

Find records within a radius of a point. **All distances are in meters.**

```php
// Direct method
$nearby = $db->withinDistance(
    "restaurants",  // database
    "location",     // spatial field
    28.9784,        // center longitude
    41.0082,        // center latitude
    5000            // radius in meters (5km)
);

// Returns array of records within 5000 meters
foreach ($nearby as $restaurant) {
    echo $restaurant['name'] . "\n";
}
```

**Options:**

```php
$nearby = $db->withinDistance("restaurants", "location", 28.9784, 41.0082, 5000, [
    'includeDistance' => true  // Add _distance field to results
]);

// Each result now has _distance in meters
foreach ($nearby as $r) {
    echo "{$r['name']}: {$r['_distance']} meters\n";
}
```

Distance is measured to the **nearest point of the geometry**: 0 for a point inside a polygon, the
closest vertex or edge otherwise (a Point is just that point). Results are sorted by distance.
Circles that cross the antimeridian (±180°) or reach a pole are searched correctly.

### withinBBox()

Find records within a bounding box.

```php
$inArea = $db->withinBBox(
    "restaurants",
    "location",
    28.97, 41.00,  // minLon, minLat (SW corner)
    29.00, 41.03   // maxLon, maxLat (NE corner)
);

// A box crossing the antimeridian: minLon > maxLon
$pacific = $db->withinBBox("ships", "location", 170, -10, -170, 10);
```

Returns geometries whose bounding box overlaps the box.

### nearest()

Find K nearest records to a point.

```php
// Find 5 nearest restaurants
$closest = $db->nearest(
    "restaurants",
    "location",
    28.9784, 41.0082,  // reference point
    5                   // number of results
);

// Results are sorted by distance (nearest first)
echo "Nearest: " . $closest[0]['name'];
```

**Options:**

```php
$closest = $db->nearest("restaurants", "location", 28.9784, 41.0082, 5, [
    'includeDistance' => false, // default true for nearest(): each result has _distance in meters
    'maxDistance' => 10000      // Maximum distance in meters (10km)
]);
```

`nearest()` adds `_distance` unless `'includeDistance' => false`; `withinDistance()` adds it only with
`'includeDistance' => true`.

With `maxDistance`, the records within that distance are returned even when there are fewer than k.
`k = 0` returns `[]`; with fewer than k records in total, all of them are returned.

### withinPolygon()

Find records within a polygon boundary.

```php
$polygon = [
    'type' => 'Polygon',
    'coordinates' => [[
        [28.97, 41.00],
        [29.00, 41.00],
        [29.00, 41.03],
        [28.97, 41.03],
        [28.97, 41.00]
    ]]
];

$inPolygon = $db->withinPolygon("restaurants", "location", $polygon);
```

`$polygon` may be a Polygon (holes are excluded) or a MultiPolygon. A geometry matches when it lies
entirely inside: every vertex inside and no edge crossing the boundary (a line that leaves and re-enters
a concave polygon does not match).

---

## Query Builder Integration

All spatial methods are available in the query builder.

### withinDistance()

```php
$results = $db->query("restaurants")
    ->withinDistance('location', 28.9784, 41.0082, 5000)  // 5000 meters
    ->get();
```

### withinBBox()

```php
$results = $db->query("restaurants")
    ->withinBBox('location', 28.97, 41.00, 29.00, 41.03)
    ->get();
```

### nearest()

```php
// The 10 nearest open restaurants: k is applied after the other filters
$results = $db->query("restaurants")
    ->nearest('location', 28.9784, 41.0082, 10)
    ->where(['open_now' => true])
    ->get();
```

### withinPolygon()

```php
$results = $db->query("restaurants")
    ->withinPolygon('location', $polygon)
    ->get();
```

### withDistance()

Add distance field to results.

```php
$results = $db->query("restaurants")
    ->withinDistance('location', 28.9784, 41.0082, 10000)  // 10000 meters
    ->withDistance('location', 28.9784, 41.0082)
    ->sort('_distance', 'asc')
    ->get();

// Each result has _distance in meters, computed before sort()
```

`_distance` is measured like the spatial filters: 0 for a point inside a polygon, otherwise to the
geometry's nearest point (4 decimals). In the query builder `withinDistance()` and `nearest()` already
add `_distance` to their own center (2 decimals); `withDistance()` replaces it with the distance to its
point.

---

## Combining Spatial with Filters

The real power comes from combining spatial queries with attribute filters using comparison operators.

### Spatial + Simple Where

```php
$openNearby = $db->query("restaurants")
    ->withinDistance('location', 28.9784, 41.0082, 3000)  // 3000 meters
    ->where(['open_now' => true])
    ->get();
```

### Spatial + Comparison Operators

```php
// Find highly-rated affordable restaurants nearby
$results = $db->query("restaurants")
    ->withinDistance('location', 28.9784, 41.0082, 5000)  // 5000 meters
    ->where([
        'rating' => ['$gte' => 4.0],
        'price_range' => ['$lte' => 3],
        'review_count' => ['$gt' => 50]
    ])
    ->get();
```

### Spatial + $in/$nin

```php
// Find nearby Turkish or Italian restaurants
$results = $db->query("restaurants")
    ->withinDistance('location', 28.9784, 41.0082, 5000)  // 5000 meters
    ->where([
        'cuisine' => ['$in' => ['turkish', 'italian', 'greek']]
    ])
    ->get();

// Exclude fast food
$results = $db->query("restaurants")
    ->withinDistance('location', 28.9784, 41.0082, 5000)  // 5000 meters
    ->where([
        'category' => ['$nin' => ['fast_food']]
    ])
    ->get();
```

### Spatial + Range Query

```php
// Mid-range restaurants nearby
$results = $db->query("restaurants")
    ->withinDistance('location', 28.9784, 41.0082, 3000)  // 3000 meters
    ->where([
        'price_range' => ['$gte' => 2, '$lte' => 4]
    ])
    ->get();
```

### Spatial + $like

```php
// Find places with "Cafe" in name
$results = $db->query("restaurants")
    ->withinDistance('location', 28.9784, 41.0082, 2000)  // 2000 meters
    ->where([
        'name' => ['$like' => 'Cafe']
    ])
    ->get();
```

### Spatial + $exists

```php
// Find places with delivery available
$results = $db->query("restaurants")
    ->withinDistance('location', 28.9784, 41.0082, 5000)  // 5000 meters
    ->where([
        'delivery' => true,
        'menu' => ['$exists' => true]
    ])
    ->get();
```

### Complex Combined Query

```php
// Food delivery app: nearby, open, delivers, good rating, affordable
$restaurants = $db->query("restaurants")
    ->withinDistance('location', $userLon, $userLat, 5000)  // 5000 meters
    ->where([
        'open_now' => true,
        'delivery' => true,
        'rating' => ['$gte' => 4.0],
        'price_range' => ['$lte' => 3],
        'cuisine' => ['$in' => ['turkish', 'italian', 'chinese']],
        'review_count' => ['$gt' => 20]
    ])
    ->withDistance('location', $userLon, $userLat)
    ->sort('rating', 'desc')
    ->limit(20)
    ->get();
```

---

## Performance Optimization

### R-tree Index Structure

noneDB uses an optimized R-tree with:

- **Node size:** 32 entries per node (reduces tree depth)
- **Linear split algorithm:** O(n) instead of O(n²)
- **Parent pointer map:** O(1) parent lookup
- **Dirty flag pattern:** Batched disk writes

### Best Practices

1. **Always create spatial index before queries**
   ```php
   $db->createSpatialIndex("locations", "coords");
   // Then run queries
   ```

2. **Use withinDistance for radius search**
   ```php
   // Good: Uses R-tree to narrow candidates
   $db->withinDistance("locations", "coords", $lon, $lat, 10000);  // 10000 meters
   ```

3. **Combine spatial with where for efficiency**
   ```php
   // Spatial filter first, then attribute filter
   $db->query("locations")
       ->withinDistance('coords', $lon, $lat, 5000)  // 5000 meters, spatial first
       ->where(['active' => true])                    // Then filter
       ->get();
   ```

4. **Use withDistance + sort for distance-ordered results**
   ```php
   $db->query("locations")
       ->withinDistance('coords', $lon, $lat, 10000)  // 10000 meters
       ->withDistance('coords', $lon, $lat)
       ->sort('_distance', 'asc')
       ->limit(10)
       ->get();
   ```

5. **Use nearest() for K-nearest queries**
   ```php
   // More efficient than withinDistance + limit for finding closest
   $db->nearest("locations", "coords", $lon, $lat, 5);
   ```

### Performance Characteristics

| Operation | Without Index | With R-tree Index |
|-----------|---------------|-------------------|
| withinDistance | O(n) | O(log n + k) |
| withinBBox | O(n) | O(log n + k) |
| nearest | O(n log n) | O(log n + k) |
| withinPolygon | O(n) | O(log n + k) |

Where n = total records, k = matching records.

---

## Real-World Examples

### Food Delivery App

```php
class DeliveryService {
    private $db;

    public function findRestaurants($userLat, $userLon, $filters = []) {
        $query = $this->db->query("restaurants")
            ->withinDistance('location', $userLon, $userLat, 5000)  // 5000 meters
            ->where([
                'open_now' => true,
                'delivery' => true,
                'rating' => ['$gte' => 3.5]
            ]);

        if (!empty($filters['cuisine'])) {
            $query->where(['cuisine' => ['$in' => $filters['cuisine']]]);
        }

        if (!empty($filters['maxPrice'])) {
            $query->where(['price_range' => ['$lte' => $filters['maxPrice']]]);
        }

        return $query
            ->withDistance('location', $userLon, $userLat)
            ->sort('rating', 'desc')
            ->limit(20)
            ->get();
    }
}
```

### Ride-Sharing App

```php
class DriverService {
    public function findNearestDrivers($passengerLat, $passengerLon, $carType = null) {
        $query = $this->db->query("drivers")
            ->nearest('current_location', $passengerLon, $passengerLat, 20)
            ->where([
                'status' => 'available',
                'rating' => ['$gte' => 4.0]
            ]);

        if ($carType) {
            $query->where(['car_type' => $carType]);
        }

        return $query
            ->withDistance('current_location', $passengerLon, $passengerLat)
            ->limit(5)
            ->get();
    }
}
```

### Real Estate Search

```php
class PropertyService {
    public function searchProperties($searchArea, $filters) {
        return $this->db->query("properties")
            ->withinPolygon('location', $searchArea)
            ->where([
                'type' => $filters['type'] ?? ['$in' => ['apartment', 'house']],
                'price' => [
                    '$gte' => $filters['minPrice'] ?? 0,
                    '$lte' => $filters['maxPrice'] ?? PHP_INT_MAX
                ],
                'bedrooms' => ['$gte' => $filters['minBedrooms'] ?? 1],
                'status' => 'available'
            ])
            ->sort('price', 'asc')
            ->get();
    }
}
```

### Store Locator

```php
class StoreLocator {
    public function findStores($userLat, $userLon, $options = []) {
        $radius = $options['radius'] ?? 10000; // Default 10km (10000 meters)
        $limit = $options['limit'] ?? 10;

        return $this->db->query("stores")
            ->withinDistance('location', $userLon, $userLat, $radius)  // meters
            ->where([
                'active' => true,
                'type' => ['$in' => $options['types'] ?? ['retail', 'flagship']]
            ])
            ->withDistance('location', $userLon, $userLat)
            ->sort('_distance', 'asc')
            ->limit($limit)
            ->get();
    }
}
```

### Geofencing

```php
class GeofenceService {
    public function checkUserInZone($userId, $userLat, $userLon) {
        // Get user's assigned zone
        $user = $this->db->query("users")
            ->where(['key' => $userId])
            ->first();

        if (!$user || !isset($user['assigned_zone'])) {
            return false;
        }

        // Check if current location is within zone
        $zone = $this->db->query("zones")
            ->where(['key' => $user['assigned_zone']])
            ->first();

        if (!$zone) {
            return false;
        }

        // Use polygon intersection
        $point = ['type' => 'Point', 'coordinates' => [$userLon, $userLat]];

        return $this->isPointInPolygon($point, $zone['boundary']);
    }

    private function isPointInPolygon($point, $polygon) {
        // Create temp record
        $this->db->insert("temp_check", ['location' => $point]);
        $this->db->createSpatialIndex("temp_check", "location");

        $result = $this->db->withinPolygon("temp_check", "location", $polygon);

        // Cleanup
        $this->db->delete("temp_check", []);
        $this->db->dropSpatialIndex("temp_check", "location");

        return count($result) > 0;
    }
}
```

---

## Error Handling

### Validation

```php
$validation = $db->validateGeoJSON($geometry);

if (!$validation['valid']) {
    echo "Invalid GeoJSON: " . $validation['error'];
}
```

### Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| "Spatial index already exists for field 'x'" | Duplicate createSpatialIndex | Check with hasSpatialIndex first |
| "Missing type property", "Unknown geometry type: X", "Coordinates must be an array", ... | Malformed geometry | Validate with validateGeoJSON() |
| "Ring 0 must be closed (first and last positions must be identical)" | Polygon not closed | Ensure first == last point (compared numerically: `29` equals `29.0`) |
| "Ring 0 must have at least 4 positions" | Too few positions | A closed ring needs 4+ positions |
| "Ring 0 must not intersect itself" | Bow-tie / self-crossing ring | Fix the ring order |
| "Hole 1 must lie inside the outer ring" | Hole outside its shell | Fix the polygon |
| "Point coordinates must be finite numbers" | NaN, INF or strings | Use numbers |
| "Longitude must be between -180 and 180" | lon > 180 or < -180 | Use valid coordinates |
| "Latitude must be between -90 and 90" | lat > 90 or < -90 | Use valid coordinates |

Queries work without a spatial index too (they scan the records); an index only makes them faster.
Records whose geometry is invalid are not indexed and never match spatial queries.

### Sharded databases and field names

Spatial indexes work the same on sharded databases (one R-tree per shard plus each shard's bounding
box for shard skipping), follow automatic migration to shards, and cover shards created later.
Field names may contain any characters (`home-loc`, `konum.ev`).

---

## Version History

| Version | Changes |
|---------|---------|
| 3.2.0 | Sharded databases supported; buffered inserts indexed; antimeridian/pole searches; nearest() maxDistance returns partial results; distance to the nearest point of a geometry; MultiPolygon queries; edge-aware withinPolygon; stricter validation; crash-safe index maintenance |
| 3.1.0 | Initial spatial indexing with R-tree |
| 3.1.0 | GeoJSON validation |
| 3.1.0 | withinDistance, withinBBox, nearest, withinPolygon |
| 3.1.0 | Query builder spatial integration |
| 3.1.0 | Performance optimizations (parent pointers, linear split) |
