# noneDB Query Reference

Comprehensive documentation for noneDB's Query Builder API.

**Version:** 3.2.0

---

## Table of Contents

1. [Quick Start](#quick-start)
2. [Query Builder Basics](#query-builder-basics)
3. [Comparison Operators](#comparison-operators)
4. [Filter Methods](#filter-methods)
5. [Sorting & Pagination](#sorting--pagination)
6. [Aggregation](#aggregation)
7. [Joins](#joins)
8. [Grouping & Having](#grouping--having)
9. [Spatial Queries](#spatial-queries)
10. [Terminal Methods](#terminal-methods)
11. [Real-World Examples](#real-world-examples)
12. [Performance Tips](#performance-tips)

---

## Quick Start

```php
$db = new noneDB();

// Simple query
$users = $db->query("users")
    ->where(['active' => true])
    ->sort('created_at', 'desc')
    ->limit(10)
    ->get();

// Advanced query with operators
$products = $db->query("products")
    ->where([
        'price' => ['$gte' => 100, '$lte' => 500],
        'category' => ['$in' => ['electronics', 'gadgets']],
        'stock' => ['$gt' => 0]
    ])
    ->sort('rating', 'desc')
    ->get();
```

---

## Query Builder Basics

### Creating a Query

```php
// Get query builder instance
$query = $db->query("database_name");

// Chain methods and execute
$results = $query
    ->where(['field' => 'value'])
    ->get();
```

### Method Chaining

All filter and modifier methods return `$this`, allowing fluent chaining:

```php
$results = $db->query("users")
    ->where(['status' => 'active'])
    ->whereIn('role', ['admin', 'moderator'])
    ->sort('name', 'asc')
    ->limit(20)
    ->offset(0)
    ->get();
```

---

## Comparison Operators

noneDB supports MongoDB-style comparison operators in `where()` clauses.

### Operator Reference

| Operator | Description | Example |
|----------|-------------|---------|
| `$gt` | Greater than | `['age' => ['$gt' => 18]]` |
| `$gte` | Greater than or equal | `['price' => ['$gte' => 100]]` |
| `$lt` | Less than | `['stock' => ['$lt' => 10]]` |
| `$lte` | Less than or equal | `['rating' => ['$lte' => 5]]` |
| `$eq` | Equal (explicit) | `['status' => ['$eq' => 'active']]` |
| `$ne` | Not equal | `['role' => ['$ne' => 'guest']]` |
| `$in` | Value in array | `['category' => ['$in' => ['a', 'b']]]` |
| `$nin` | Value not in array | `['tag' => ['$nin' => ['spam']]]` |
| `$exists` | Field exists/not exists | `['email' => ['$exists' => true]]` |
| `$like` | Pattern matching (literal; only a leading `^` / trailing `$` are special) | `['name' => ['$like' => '^John']]` |
| `$regex` | Regular expression (case-insensitive, no delimiters) | `['email' => ['$regex' => '@gmail\\.com$']]` |
| `$contains` | Array element (strict) / substring | `['tags' => ['$contains' => 'featured']]` |

**How values compare**

- `$gt`, `$gte`, `$lt`, `$lte` (and `between()` / `notBetween()`): numbers and numeric strings compare
  numerically (`"30" > 18`), other strings lexically (`'2024-02-01' > '2024-01-15'`). `null`, booleans,
  arrays and a number against a non-numeric string never match. A missing field never matches.
- `$eq`, `$ne`, `$in`, `$nin`, `whereIn()`, `whereNot()` and plain `['field' => value]` filters use strict
  comparison (`===`): `'25'` is not `25`.
- A filter value that is an array of operators applies all of them; any other array is compared as a
  whole (`['addr' => ['city' => 'Ist']]` matches only that exact array).
- A mix of operators and plain keys in one value throws `InvalidArgumentException` as soon as `where()`
  / `orWhere()` is called. An unknown operator (`$gtee`), `$in`/`$nin` without an array or an invalid
  `$regex` throws it when the query runs, also on an empty database (filters are compiled once per
  query, before records are read).

### Comparison Examples

#### Greater Than / Less Than

```php
// Find adults
$adults = $db->query("users")
    ->where(['age' => ['$gte' => 18]])
    ->get();

// Find products under $100
$affordable = $db->query("products")
    ->where(['price' => ['$lt' => 100]])
    ->get();

// Range query (between 18 and 65)
$workingAge = $db->query("users")
    ->where(['age' => ['$gte' => 18, '$lte' => 65]])
    ->get();
```

#### Equality / Inequality

```php
// Not equal
$nonAdmins = $db->query("users")
    ->where(['role' => ['$ne' => 'admin']])
    ->get();

// Explicit equality (same as simple value)
$active = $db->query("users")
    ->where(['status' => ['$eq' => 'active']])
    ->get();
```

#### In / Not In

```php
// Find users in specific roles
$staff = $db->query("users")
    ->where(['role' => ['$in' => ['admin', 'moderator', 'editor']]])
    ->get();

// Exclude certain categories
$products = $db->query("products")
    ->where(['category' => ['$nin' => ['discontinued', 'draft']]])
    ->get();
```

#### Exists

```php
// Find records with email field
$withEmail = $db->query("users")
    ->where(['email' => ['$exists' => true]])
    ->get();

// Find records without phone field
$noPhone = $db->query("users")
    ->where(['phone' => ['$exists' => false]])
    ->get();
```

#### Pattern Matching

```php
// Contains (case-insensitive)
$johns = $db->query("users")
    ->where(['name' => ['$like' => 'john']])
    ->get();

// Starts with (use ^)
$mNames = $db->query("users")
    ->where(['name' => ['$like' => '^M']])
    ->get();

// Ends with (use $)
$gmails = $db->query("users")
    ->where(['email' => ['$like' => 'gmail.com$']])
    ->get();

// Regular expression
$validEmails = $db->query("users")
    ->where(['email' => ['$regex' => '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$']])
    ->get();
```

#### Contains (Arrays/Strings)

```php
// Check if array field contains value
$featuredProducts = $db->query("products")
    ->where(['tags' => ['$contains' => 'featured']])
    ->get();

// Check if string field contains substring (case-sensitive; use $like for case-insensitive matching)
$techNews = $db->query("articles")
    ->where(['title' => ['$contains' => 'Technology']])
    ->get();
```

### Combining Multiple Operators

```php
// Multiple operators on same field
$midRange = $db->query("products")
    ->where([
        'price' => ['$gte' => 50, '$lte' => 200],
        'rating' => ['$gte' => 4.0]
    ])
    ->get();

// Mix operators with simple equality
$activeAdmins = $db->query("users")
    ->where([
        'role' => 'admin',                    // Simple equality
        'status' => 'active',                 // Simple equality
        'login_count' => ['$gt' => 100],      // Operator
        'last_login' => ['$exists' => true]   // Operator
    ])
    ->get();
```

---

## Filter Methods

### where()

Primary filter method. Applies AND logic across all conditions. Each `where()` call adds another group
that must also match, so conditions on the same field can be split over several calls:

```php
$db->query("users")->where(['age' => ['$gte' => 18]])->where(['age' => ['$lt' => 65]]);
```

```php
// Simple equality
$db->query("users")->where(['name' => 'John', 'active' => true]);

// With operators
$db->query("users")->where([
    'age' => ['$gte' => 18],
    'country' => ['$in' => ['US', 'UK', 'CA']]
]);
```

### orWhere()

Adds OR groups: a record matches if **all** `where()` groups match **or any** `orWhere()` group matches.
Without `where()`, only the `orWhere()` groups decide.

```php
// Users who are admins OR have high reputation
$db->query("users")
    ->where(['role' => 'admin'])
    ->orWhere(['reputation' => ['$gte' => 1000]])
    ->get();

// Multiple OR conditions
$db->query("products")
    ->where(['category' => 'electronics'])
    ->orWhere(['featured' => true])
    ->orWhere(['discount' => ['$gt' => 50]])
    ->get();
```

### whereIn() / whereNotIn()

Filter by array membership.

```php
// Users in specific departments
$db->query("users")
    ->whereIn('department', ['engineering', 'design', 'product'])
    ->get();

// Products NOT in these categories
$db->query("products")
    ->whereNotIn('category', ['archived', 'deleted'])
    ->get();
```

### whereNot()

Exclude records where **any** of the given fields equals its value (`===`):
`whereNot(['role' => 'guest', 'dept' => 'hr'])` drops guests and everyone in HR. Several calls all apply.

```php
// All users except guests
$db->query("users")
    ->whereNot(['role' => 'guest'])
    ->get();
```

### like() / notLike()

Pattern matching on string fields.

```php
// Names starting with 'A'
$db->query("users")->like('name', '^A')->get();

// Names ending with 'son'
$db->query("users")->like('name', 'son$')->get();

// Names containing 'john' (case-insensitive, UTF-8 aware)
$db->query("users")->like('name', 'john')->get();

// Everything except a leading ^ and a trailing $ is literal: this matches 'http://...' only
$db->query("links")->like('url', '^http://')->get();

// Exclude spam titles
$db->query("posts")->notLike('title', 'SPAM')->get();
```

### between() / notBetween()

Range filtering (inclusive).

```php
// Products priced $50-$200
$db->query("products")
    ->between('price', 50, 200)
    ->get();

// Ages outside 18-65 range
$db->query("users")
    ->notBetween('age', 18, 65)
    ->get();
```

`between()` matches only comparable values inside the range (see the comparison rules above).
`notBetween()` returns every other record, including records without the field or with a value that
can't be compared (`null`, booleans, text against numbers).

### search()

Full-text search across fields.

```php
// Search in all fields
$db->query("articles")
    ->search('php tutorial')
    ->get();

// Search in specific fields
$db->query("articles")
    ->search('php tutorial', ['title', 'content', 'tags'])
    ->get();
```

Case-insensitive substring match on scalar values (`İzmir`, `IZMIR` and `izmir` match each other).
Without a field list, every field except `key` is searched.

---

## Sorting & Pagination

### sort()

Sort results by field.

```php
// Ascending (default)
$db->query("users")->sort('name')->get();
$db->query("users")->sort('name', 'asc')->get();

// Descending
$db->query("products")->sort('price', 'desc')->get();

// Several keys: category first, then price
$db->query("products")->sort('category')->sort('price', 'desc')->get();

// Sort by distance (spatial queries; distances are in meters)
$db->query("locations")
    ->withinDistance('coords', $lon, $lat, 10000)
    ->withDistance('coords', $lon, $lat)
    ->sort('_distance', 'asc')
    ->get();
```

Sorting is stable and uses a total order: missing/`null` first, then booleans, numbers, strings and
arrays (reversed for `desc`).

### limit() / offset()

Paginate results.

```php
// First 10 records
$db->query("products")->limit(10)->get();

// Page 2 (records 11-20)
$db->query("products")->limit(10)->offset(10)->get();

// Helper function for pagination
function paginate($db, $dbname, $page, $perPage = 20) {
    return $db->query($dbname)
        ->limit($perPage)
        ->offset(($page - 1) * $perPage)
        ->get();
}
```

---

## Aggregation

### count()

Count matching records.

```php
$totalUsers = $db->query("users")->count();

$activeUsers = $db->query("users")
    ->where(['active' => true])
    ->count();
```

### sum() / avg() / min() / max()

Numeric aggregations.

```php
// Total revenue
$revenue = $db->query("orders")
    ->where(['status' => 'completed'])
    ->sum('total');

// Average rating
$avgRating = $db->query("products")
    ->where(['category' => 'electronics'])
    ->avg('rating');

// Price range
$minPrice = $db->query("products")->min('price');
$maxPrice = $db->query("products")->max('price');
```

---

## Joins

### join()

Combine data from multiple databases.

```php
// Join orders with users
$ordersWithUsers = $db->query("orders")
    ->join("users", "user_id", "key")  // orders.user_id = users.key
    ->get();

// The matching user record is attached under the foreign database's name (or null if none matches)
foreach ($ordersWithUsers as $order) {
    echo $order['users']['name'] ?? '-';  // User's name
}

// Custom field name (alias)
$db->query("orders")->join("users", "user_id", "key", "buyer")->get(); // $order['buyer']
```

- The attached record replaces a field of the same name in the result only; stored records are never changed.
- If several foreign records have the same `$foreignKey` value, the last one wins.
- Joining a database that doesn't exist attaches `null` and creates nothing.

### Multiple Joins

```php
$data = $db->query("order_items")
    ->join("orders", "order_id", "key")      // $item['orders']
    ->join("products", "product_id", "key")  // $item['products']
    ->get();
```

---

## Grouping & Having

### groupBy()

Group results by field value.

```php
// Group orders by status
$ordersByStatus = $db->query("orders")
    ->groupBy('status')
    ->get();

// Returns a list of groups:
// [
//   ['_group' => 'pending',   '_items' => [...orders], '_count' => 12],
//   ['_group' => 'completed', '_items' => [...orders], '_count' => 40],
//   ...
// ]
```

Group values keep their type (`1`, `"1"` and `1.5` are separate groups); records without the field
are grouped under `null`.

### having()

Filter groups by aggregate conditions.

```php
// Customers with more than 5 orders
$frequentCustomers = $db->query("orders")
    ->groupBy('customer_id')
    ->having('count', '>', 5)
    ->get();

// Categories with average rating >= 4
$goodCategories = $db->query("products")
    ->groupBy('category')
    ->having('avg:rating', '>=', 4)
    ->get();
```

Aggregates: `count`, `sum:field`, `avg:field`, `min:field`, `max:field`; operators: `>`, `<`, `>=`, `<=`,
`=`, `!=`. `having()` only applies to groups: without `groupBy()`, or with an unknown aggregate or
operator, the condition is ignored.

---

## Spatial Queries

noneDB supports geospatial queries with R-tree indexing.

### Creating Spatial Index

```php
// Create index on location field (optional: spatial queries work without it, the index makes them fast)
$db->createSpatialIndex("restaurants", "location");

// Check if index exists
$db->hasSpatialIndex("restaurants", "location"); // true/false

// List all spatial indexes
$db->getSpatialIndexes("restaurants"); // ['location']

// Drop spatial index
$db->dropSpatialIndex("restaurants", "location");
```

### GeoJSON Data Format

```php
// Point
$point = [
    'type' => 'Point',
    'coordinates' => [28.9784, 41.0082]  // [longitude, latitude]
];

// LineString
$line = [
    'type' => 'LineString',
    'coordinates' => [
        [28.97, 41.00],
        [28.98, 41.01],
        [28.99, 41.02]
    ]
];

// Polygon
$polygon = [
    'type' => 'Polygon',
    'coordinates' => [[
        [28.97, 41.00],
        [29.00, 41.00],
        [29.00, 41.03],
        [28.97, 41.03],
        [28.97, 41.00]  // First point repeated to close
    ]]
];
```

### withinDistance()

Find records within radius of a point.

```php
// Direct method
$nearby = $db->withinDistance("restaurants", "location",
    28.9784,  // longitude
    41.0082,  // latitude
    5000      // radius in meters
);

// Query builder
$nearby = $db->query("restaurants")
    ->withinDistance('location', 28.9784, 41.0082, 5000)   // 5 km
    ->where(['open_now' => true])
    ->get();
```

### withinBBox()

Find records whose geometry's bounding box overlaps the box (a point inside it, or a line/polygon that
reaches into or touches it).

```php
// Direct method
$inArea = $db->withinBBox("restaurants", "location",
    28.97, 41.00,  // minLon, minLat
    29.00, 41.03   // maxLon, maxLat
);

// Query builder
$inArea = $db->query("restaurants")
    ->withinBBox('location', 28.97, 41.00, 29.00, 41.03)
    ->where(['category' => 'cafe'])
    ->get();
```

### nearest()

Find K nearest records.

```php
// Direct method
$closest = $db->nearest("restaurants", "location",
    28.9784, 41.0082,  // reference point
    5                   // number of results
);

// Query builder: the 10 nearest records among those rated 4+ (k is applied after the other filters)
$closest = $db->query("restaurants")
    ->nearest('location', 28.9784, 41.0082, 10)
    ->where(['rating' => ['$gte' => 4.0]])
    ->withDistance('location', 28.9784, 41.0082)
    ->sort('_distance', 'asc')
    ->limit(5)
    ->get();
```

### withinPolygon()

Find records within polygon boundary.

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

// Direct method
$inPolygon = $db->withinPolygon("restaurants", "location", $polygon);

// Query builder
$inPolygon = $db->query("restaurants")
    ->withinPolygon('location', $polygon)
    ->where(['price_range' => ['$lte' => 3]])
    ->get();
```

### withDistance()

Add `_distance` to every result: meters from the point to the geometry, measured like
`withinDistance()` / `nearest()` (0 for a point inside a polygon, otherwise to the geometry's nearest
point), rounded to 4 decimals. It is computed before sorting and kept by `select()`.

In the query builder, `withinDistance()` and `nearest()` already add `_distance` (to their own center,
rounded to 2 decimals); `withDistance()` replaces it with the distance to its point.

```php
$results = $db->query("restaurants")
    ->withinDistance('location', $userLon, $userLat, 10000)   // 10 km
    ->withDistance('location', $userLon, $userLat)
    ->sort('_distance', 'asc')
    ->get();

foreach ($results as $r) {
    echo "{$r['name']}: {$r['_distance']} m away\n";
}
```

### Combining Spatial + Operators

```php
// Food delivery app: find nearby open restaurants with delivery
$restaurants = $db->query("restaurants")
    ->withinDistance('location', $userLon, $userLat, 5000)
    ->where([
        'open_now' => true,
        'delivery' => true,
        'rating' => ['$gte' => 4.0],
        'price_range' => ['$lte' => 3],
        'cuisine' => ['$in' => ['turkish', 'italian', 'chinese']]
    ])
    ->withDistance('location', $userLon, $userLat)
    ->sort('rating', 'desc')
    ->limit(20)
    ->get();
```

---

## Terminal Methods

Terminal methods execute the query and return results.

### get()

Returns all matching records as array.

```php
$results = $db->query("users")->where(['active' => true])->get();
```

### first()

Returns first matching record or null.

```php
$user = $db->query("users")->where(['email' => 'john@example.com'])->first();
```

### count()

Returns number of matching records.

```php
$count = $db->query("users")->where(['role' => 'admin'])->count();
```

### exists()

Returns boolean indicating if any records match.

```php
$hasAdmins = $db->query("users")->where(['role' => 'admin'])->exists();
```

### update()

Updates matching records and returns result.

```php
$result = $db->query("users")
    ->where(['status' => 'inactive'])
    ->update(['status' => 'archived']);
// Returns: ['n' => 5]
```

`sort()`, `offset()` and `limit()` choose which records are written (e.g. "the 10 oldest"); `select()`,
`except()` and `join()` don't matter. Selecting and writing happen under one database lock.
`update()`/`delete()` can't be combined with `groupBy()` (`LogicException`).

### delete()

Deletes matching records and returns result.

```php
$result = $db->query("logs")
    ->where(['created_at' => ['$lt' => strtotime('-30 days')]])
    ->delete();
// Returns: ['n' => 100]
```

### removeFields()

Removes fields from the **stored** matching records (never affected by `select()`, `except()`,
`join()` or `withDistance()`).

```php
$db->query("users")->where(['legacy' => true])->removeFields(['old_password', 'tmp']);
// Returns: ['n' => 12, 'fields_removed' => ['old_password']]
```

---

## Real-World Examples

### E-commerce Product Search

```php
$products = $db->query("products")
    ->where([
        'category' => ['$in' => ['electronics', 'computers']],
        'price' => ['$gte' => 100, '$lte' => 1000],
        'stock' => ['$gt' => 0],
        'rating' => ['$gte' => 4.0]
    ])
    ->whereNot(['status' => 'discontinued'])
    ->search($searchTerm, ['name', 'description'])
    ->sort('rating', 'desc')
    ->limit(20)
    ->offset($page * 20)
    ->get();
```

### User Authentication

```php
$user = $db->query("users")
    ->where([
        'email' => $email,
        'password_hash' => $passwordHash,
        'status' => 'active',
        'email_verified' => true
    ])
    ->first();
```

### Analytics Dashboard

```php
// Orders by status
$orderStats = $db->query("orders")
    ->where(['created_at' => ['$gte' => $startDate, '$lte' => $endDate]])
    ->groupBy('status')
    ->get();

// Revenue by category
$categoryRevenue = [];
foreach ($db->query("orders")->where(['status' => 'completed'])->get() as $order) {
    $cat = $order['category'];
    $categoryRevenue[$cat] = ($categoryRevenue[$cat] ?? 0) + $order['total'];
}
```

### Location-Based Service

```php
// Find nearby services with filters
$services = $db->query("services")
    ->withinDistance('location', $userLon, $userLat, 10000)
    ->where([
        'available' => true,
        'rating' => ['$gte' => 4.0],
        'price_per_hour' => ['$lte' => $maxBudget],
        'category' => ['$in' => $selectedCategories]
    ])
    ->withDistance('location', $userLon, $userLat)
    ->sort('_distance', 'asc')
    ->limit(10)
    ->get();
```

### Content Management

```php
// Published articles with tags
$articles = $db->query("articles")
    ->where([
        'status' => 'published',
        'published_at' => ['$lte' => time()],
        'tags' => ['$contains' => 'featured']
    ])
    ->sort('published_at', 'desc')
    ->limit(10)
    ->get();

// Search with category filter
$results = $db->query("articles")
    ->where(['category' => ['$in' => ['tech', 'science']]])
    ->search($query, ['title', 'content', 'excerpt'])
    ->sort('published_at', 'desc')
    ->get();
```

---

## Performance Tips

### 1. Use Indexes

```php
// Create field index for frequently queried fields
$db->createFieldIndex("users", "email");
$db->createFieldIndex("products", "category");

// Create spatial index for location queries
$db->createSpatialIndex("locations", "coords");
```

### 2. Limit Results

```php
// Always limit when you don't need all results
$db->query("logs")->limit(100)->get();

// Use first() instead of get()[0]
$db->query("users")->where(['id' => $id])->first();
```

### 3. Select Only Needed Fields

```php
// Only fetch name and email
$db->query("users")->select(['name', 'email'])->get();

// Exclude large fields
$db->query("articles")->except(['content', 'raw_html'])->get();
```

### 4. Filter Early

```php
// Good: Filter with where() first
$db->query("orders")
    ->where(['status' => 'completed'])  // Filter first
    ->between('total', 100, 500)         // Then range
    ->sort('created_at', 'desc')
    ->get();
```

### 5. Use Spatial Indexes for Location Queries

```php
// Create the spatial index once
$db->createSpatialIndex("restaurants", "location");

// Then queries use the R-tree instead of scanning every record
$db->withinDistance("restaurants", "location", $lon, $lat, 5000); // 5 km (meters)
```

### 6. Batch Operations

```php
// Batch insert instead of individual inserts
$db->insert("logs", $arrayOf1000Records);  // Single operation

// Batch update
$db->query("orders")
    ->where(['status' => 'pending', 'created_at' => ['$lt' => $oldDate]])
    ->update(['status' => 'expired']);
```

---

## Operator Quick Reference

### Comparison Operators

```php
['$gt' => value]      // Greater than
['$gte' => value]     // Greater than or equal
['$lt' => value]      // Less than
['$lte' => value]     // Less than or equal
['$eq' => value]      // Equal (explicit)
['$ne' => value]      // Not equal
['$in' => [a,b,c]]    // In array
['$nin' => [a,b,c]]   // Not in array
['$exists' => bool]   // Field exists
['$like' => pattern]  // Pattern match (^start, end$)
['$regex' => pattern] // Regex match
['$contains' => val]  // Array/string contains
```

### Spatial Methods

```php
->withinDistance($field, $lon, $lat, $meters)
->withinBBox($field, $minLon, $minLat, $maxLon, $maxLat)
->nearest($field, $lon, $lat, $k)
->withinPolygon($field, $polygon)
->withDistance($field, $lon, $lat)
```

### Filter Methods

```php
->where([...])
->orWhere([...])
->whereIn($field, [...])
->whereNotIn($field, [...])
->whereNot([...])
->like($field, $pattern)
->notLike($field, $pattern)
->between($field, $min, $max)
->notBetween($field, $min, $max)
->search($term, $fields)
```

### Modifier Methods

```php
->sort($field, 'asc'|'desc')
->limit($count)
->offset($count)
->select([...])
->except([...])
->join($db, $localKey, $foreignKey)
->groupBy($field)
->having($aggregate, $operator, $value)
```

### Terminal Methods

```php
->get()        // Array of records
->first()      // Single record or null
->count()      // Integer count
->exists()     // Boolean
->sum($field)  // Numeric sum
->avg($field)  // Numeric average
->min($field)  // Minimum value
->max($field)  // Maximum value
->update([...])// Update result
->delete()     // Delete result
```

---

## Version History

| Version | Changes |
|---------|---------|
| 3.2.0 | `where()` calls combine with AND; `orWhere()` without `where()` fixed; type-aware comparisons; unknown operators throw; safe `like`/`$regex`; multi-key stable `sort()`; `nearest()` applies k after filters; `withDistance()` before sort and measured like `withinDistance()`; typed `groupBy()`; `removeFields()` on stored records only |
| 3.1.0 | Added comparison operators ($gt, $gte, $lt, $lte, $ne, $eq, $in, $nin, $exists, $like, $regex, $contains) |
| 3.1.0 | Added spatial indexing with R-tree (withinDistance, withinBBox, nearest, withinPolygon) |
| 3.1.0 | Added withDistance() for distance calculations |
| 3.0.0 | JSONL storage with O(1) key lookup |
| 3.0.0 | Single-pass filtering optimization |
| 3.0.0 | Static cache sharing across instances |
