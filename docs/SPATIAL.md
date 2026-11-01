# noneDB Spatial Index Reference

GeoJSON geometries, the R-tree spatial index and spatial queries in noneDB.

Geospatial indexes and queries of noneDB 4.0.0. The examples run in order (CI runs them).

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
9. [Error Handling](#error-handling)

---

## Overview


- **R-tree indexing** with O(log n) query performance
- **GeoJSON support** for Point, LineString, Polygon, MultiPoint, MultiLineString, MultiPolygon and
  GeometryCollection
- **Distances in meters**, using the Haversine formula (earth radius 6,371 km)
- **Query builder integration** for combining spatial + attribute filters
- **Always current:** queries see every insert, update and delete; the index catches up from the data
  when it is loaded (see [R-tree Index Structure](#r-tree-index-structure))

---

## Creating Spatial Indexes

### createSpatialIndex()

Create an R-tree spatial index on a geometry field. A missing database is created when `autoCreateDB`
is on. Field names may contain any characters (`home-loc`, `konum.ev`); `''` and `key` are rejected.

```php
$db = new noneDB();

// Create spatial index on 'location' field
$result = $db->createSpatialIndex("restaurants", "location");
// Returns: ["success" => true, "indexed" => 150, "error" => null]

// If index already exists:
// Returns: ["success" => false, "indexed" => 0, "error" => "Spatial index already exists for field 'location'"]

// Optional: entries per R-tree node (an integer of at least 4, default 32); rebuilds keep it
$result = $db->createSpatialIndex("zones", "boundary", ['nodeSize' => 16]);
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
$geometry = ['type' => 'Point', 'coordinates' => [28.9784, 41.0082]];
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

// With a shard id: true when the index exists and the database has that shard
$exists = $db->hasSpatialIndex("restaurants", "location", 2);
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
// Returns: ["success" => true, "error" => null], also when there was no such index
```

### rebuildSpatialIndex()

Delete the index files and build them again from the data, with the same `nodeSize`. Returns the same
array as `createSpatialIndex()`; a field without an index gets one.

```php
$result = $db->rebuildSpatialIndex("restaurants", "location");
// Returns: ["success" => true, "indexed" => 150, "error" => null]
```

---

## GeoJSON Data Format

noneDB supports standard GeoJSON geometry types. Coordinates are `[longitude, latitude]`. A geometry
that `validateGeoJSON()` rejects is stored but never indexed or matched (see
[Error Handling](#error-handling)).

### Point

A single location. A third value (altitude) is accepted and ignored.

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

An area with boundary. A ring needs at least 4 positions, its first and last points must be identical
(closed ring), and it must not intersect itself.

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

Polygon with inner exclusion zone. Every vertex of a hole must lie inside the outer ring or on it.

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

### GeometryCollection

Geometries of any type in one value. An empty collection is valid but has no location: it is never
indexed and never matches a spatial query.

```php
$site = [
    'type' => 'GeometryCollection',
    'geometries' => [
        ['type' => 'Point', 'coordinates' => [28.98, 41.00]],
        ['type' => 'LineString', 'coordinates' => [[28.97, 41.00], [28.98, 41.01]]]
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

// Each result now has _distance in meters, rounded to 2 decimals
foreach ($nearby as $r) {
    echo "{$r['name']}: {$r['_distance']} meters\n";
}
```

Distance is measured to the **nearest point of the geometry**: 0 for a point inside a polygon, the
closest vertex or edge otherwise (a Point is just that point; an edge is straight in longitude/latitude).
The distance is exact, also to an edge across the antimeridian from the center. Results are sorted by the distance rounded to centimeters (`_distance`),
ties by key; `nearest()` takes its k in that order. Circles that cross the antimeridian (±180°) or
reach a pole are searched correctly.

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

Compares bounding boxes: returns the records whose geometry's bounding box overlaps or touches the box,
so a line or polygon can match without entering the box itself. The latitudes may be given in either
order; `minLon > maxLon` means a box across the antimeridian.

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
`'includeDistance' => true`. Both round it to 2 decimals. The options are exactly these, `includeDistance` a
boolean and `maxDistance` a number or `null`; another option or another type is an `InvalidArgumentException`.
The same holds for `createSpatialIndex()`'s `nodeSize`, an integer of at least 4.

With `maxDistance`, the records within that distance are returned even when there are fewer than k.
`k = 0` returns `[]`; with fewer than k records in total, all of them are returned.

Arguments that aren't usable numbers:
- a NaN longitude, latitude, radius or `maxDistance` returns `[]` in every spatial query;
- an infinite radius or `maxDistance` means "the whole earth": every geometry;
- infinite box coordinates are clamped to ±180/±90;
- an infinite center, a negative radius or a negative `maxDistance` returns `[]`.

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

`$polygon` may be a Polygon (holes are excluded) or a MultiPolygon; anything else, or an invalid
polygon, returns `[]`. A geometry matches when it lies entirely inside: every vertex inside and no edge
crossing the boundary (a line that leaves and re-enters a concave polygon does not match).

Rules:
- **The polygon is closed:** points on its boundary are inside, on every edge. For points, a rectangle
  matches the same records as `withinBBox()` (which also returns lines and polygons that only overlap
  the box).
- **Holes are respected:** a record that covers a hole of the query polygon (a polygon whose area
  holds it, a line through it) is not within the polygon. A ring that goes around a hole without covering it is. A record may touch a hole's boundary.
- **MultiPolygon query:** each component of a multi-part record (each point of a MultiPoint, each part
  of a MultiPolygon or GeometryCollection) may lie in a different part. A single component that spans
  two adjacent parts doesn't match: the union of the parts isn't computed.
- **Empty GeometryCollection:** never within anything; empty members of a collection are ignored.
- A polygon can't cross the antimeridian (GeoJSON coordinates are plain lon/lat); split it into a
  MultiPolygon at ±180°.
- Containment uses floating-point arithmetic: a point exactly on an edge counts as inside when its
  coordinates are exact in binary (integers, halves, ...); a decimal coordinate such as 41.02 on an edge
  can land on either side of it.

### haversineDistance()

Distance in meters between two points (Haversine formula, earth radius 6,371 km).

```php
$meters = $db->haversineDistance(28.9784, 41.0082, 29.0, 41.0);  // lon1, lat1, lon2, lat2
```

---

## Query Builder Integration

The four spatial filters and `withDistance()` are available in the query builder (rules below).

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

### Rules

- **The spatial filter runs first**, whatever the order of the calls; `where()` and the other filters
  are then checked on its results (field indexes are not used for them).
- `withinBBox()` compares bounding boxes and `withinPolygon()` requires the geometry to lie inside, as
  the direct methods do.
- `withinDistance()` and `nearest()` add `_distance`: meters to their own center, rounded to 2
  decimals. `nearest()` takes its k after the other filters and has no `maxDistance` option; use
  `withinDistance($field, $lon, $lat, $max)->nearest($field, $lon, $lat, $k)`.
- `withDistance()` adds `_distance`, or replaces it, with the distance to its point, measured like the
  spatial filters (0 for a point inside a polygon, otherwise to the geometry's nearest point) and
  rounded to 4 decimals. It is computed before `sort()`, and `select()` keeps it (it drops the
  `_distance` of `withinDistance()`/`nearest()`).
- Several spatial filters must all match, except that of several `nearest()` filters only the smallest `k` and
  the last one's order apply. With `nearest()` among them, its order and `_distance` are
  used: `withinDistance(A)->nearest(B)` orders and cuts by the distance to B. Of two `withinDistance()`
  filters, the first one's `_distance` is kept.
- A record whose field holds no geometry gets no `_distance` from `withDistance()`.

---

## Combining Spatial with Filters

Spatial filters combine with `where()` and its comparison operators.

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

A complete combined query is in [Food Delivery App](#food-delivery-app).

---

## Performance Optimization

### R-tree Index Structure

noneDB uses an R-tree with at most 32 entries per node (the `nodeSize` option):

- **Bulk loading (STR):** `createSpatialIndex()` and rebuilds sort the boxes into nodes
  filled to 3/4 (24 of 32 entries) instead of inserting one by one.
- **Integer boxes:** coordinates are stored as integers in units of 1e-7 degrees (about 1 cm), rounded
  outwards, so a box always covers its geometry, whatever `serialize_precision` is.
- **A checkpoint, not a file per write:** a write appends to the data file only; the R-tree file
  (`spatial/{seg}.{token}.rx` in the generation, STORAGE_FORMAT.md §6.5) covers the data up to a byte offset and the lines after it are added
  when the index is loaded. Once the new data reaches both 64 KiB and a quarter of the index file's
  size, the process that loads the R-tree writes a new checkpoint (a writer only if it has the R-tree
  loaded).
- **Sharded databases:** one R-tree per shard (`spatial/s{N}.{token}.rx`) and one global file per field
  (`spatial/{token}.grx`) with each shard's bounding box. A shard is skipped
  only when its box and its newer lines can't match; a shard without an entry is always searched.
  Indexes follow the automatic migration to shards and cover shards created later.
- **File names:** `{token}` is the field name when it matches `[a-z0-9_]{1,40}`, otherwise a lower-case
  prefix and 12 hex digits of its SHA-256 (`home-loc` → `home_loc-17e07f2f0d56`).
- **Fewer checks:** candidates found in an R-tree are not validated again (it only holds geometries that
  passed validation); candidates from a scan are.

Measurements (the spatial workloads of the performance gate): [PERFORMANCE.md](PERFORMANCE.md).

### Best Practices

1. **Always create spatial index before queries**
   ```php
   $db->createSpatialIndex("locations", "coords");
   // Then run queries
   ```

2. **Use withinDistance for radius search**
   ```php
   // Good: Uses R-tree to narrow candidates
   [$lon, $lat] = [28.9784, 41.0082];
   $db->withinDistance("locations", "coords", $lon, $lat, 10000);  // 10000 meters
   ```

3. **Combine spatial with where**
   ```php
   // The spatial filter runs first (whatever the call order); where() is checked on its results
   $db->query("locations")
       ->withinDistance('coords', $lon, $lat, 5000)  // 5000 meters
       ->where(['active' => true])
       ->get();
   ```

4. **Use nearest() for K-nearest queries**
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

### Validation Cost

Validation costs O(n log n) for any polygon (n = its vertices): a ring is
valid when no two non-adjacent edges share a point, a hole when each of its vertices is inside or on the
outer ring, computed with exact orientation tests. Usual rings take a quick sort-and-sweep; rings built
to defeat it (e.g. a comb whose edges all overlap in x) and polygons with many holes switch to a sweep
line over a balanced tree.

Inserts and updates don't validate. Geometries are validated when an R-tree is built or adds new data
lines (the first query of a process after a write), and by queries that don't find them in an R-tree
(a scan), so polygons with tens of thousands of vertices can still cost noticeable
time per query (`validateGeoJSON()` of one polygon, PHP 8.2: 0.06 s for a 20,000-vertex circle, 1.5 s
for a 16,000-edge comb, 6.5 s for a 64,000-edge comb). `withinPolygon()` then compares each candidate
with the query polygon: O(n·m) for a candidate with n and a query with m vertices.

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

        $zone = $this->db->query("zones")
            ->where(['key' => $user['assigned_zone']])
            ->first();

        if (!$zone) {
            return false;
        }

        // Store the current position, then check it against the zone (Polygon or MultiPolygon).
        // A spatial index on users.position (created once) keeps the check fast.
        $point = ['type' => 'Point', 'coordinates' => [$userLon, $userLat]];
        $this->db->update("users", [['key' => $userId], ['set' => ['position' => $point]]]);

        return $this->db->query("users")
            ->withinPolygon('position', $zone['boundary'])
            ->where(['key' => $userId])
            ->exists();
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
| "Spatial indexing is disabled" | `spatialIndexEnabled` is false (create/rebuild; existing indexes are kept, queries scan) | Enable it |
| "Invalid field name" | Field `''` or `key` | Use another field |
| "Database does not exist" | `autoCreateDB` is false | Create the database first |
| "Missing type property", "Unknown geometry type: X", "Coordinates must be an array", ... | Malformed geometry | Validate with validateGeoJSON() |
| "Ring 0 must be closed (first and last positions must be identical)" | Polygon not closed | Ensure first == last point (compared numerically: `29` equals `29.0`) |
| "Ring 0 must have at least 4 positions" | Too few positions | A closed ring needs 4+ positions |
| "Ring 0 must not intersect itself" | Bow-tie / self-crossing ring | Fix the ring order |
| "Hole 1 must lie inside the outer ring" | Hole outside its shell | Fix the polygon |
| "Point coordinates must be finite numbers" | NaN, INF or strings | Use numbers |
| "Longitude must be between -180 and 180" | lon > 180 or < -180 | Use valid coordinates |
| "Latitude must be between -90 and 90" | lat > 90 or < -90 | Use valid coordinates |

Queries work without a spatial index too (they scan the records); an index only makes them faster.
Records whose geometry is invalid are not indexed and never match spatial queries; such records can be
inserted and are kept. Invalid are also: flat coordinates (`[1, 2]` for a LineString), string-keyed
coordinates (`{"a": ...}`) and a non-string `type` (`true`).
Validation costs: see [Validation Cost](#validation-cost).

