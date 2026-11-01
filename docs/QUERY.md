# noneDB Query Reference

The query builder of noneDB 4.0.0: filters, operators, sorting, joins, grouping, aggregates, writes and query
plans. The examples run in order (CI runs them).

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
11. [Query Planning & explain()](#query-planning--explain)
12. [Real-World Examples](#real-world-examples)
13. [Performance Tips](#performance-tips)
14. [Operator Quick Reference](#operator-quick-reference)

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
$query = $db->query("articles");

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

- `$gt`, `$gte`, `$lt`, `$lte` and `between()`: numbers and numeric strings compare numerically
  (`"30" > 18`), other strings lexically (`'2024-02-01' > '2024-01-15'`). A numeric string may have
  leading and trailing whitespace (`"7 "` is 7). `null`, booleans, arrays and a number against a non-numeric string never match. A missing field never matches. A `NAN` operand never
  matches. `notBetween()` returns the complement (see
  [between()](#between--notbetween)).
- `$eq`, `$ne`, `$in`, `$nin`, `whereIn()`, `whereNot()` and plain `['field' => value]` filters use strict
  comparison (`===`): `'25'` is not `25`.
- A filter value that is an array of operators applies all of them; any other array is compared as a
  whole (`['addr' => ['city' => 'Ist']]` matches only that exact array).
- A mix of operators and plain keys in one value throws `InvalidArgumentException` as soon as `where()`
  / `orWhere()` is called. An unknown operator (`$gtee`), `$in`/`$nin` without an array or an invalid
  `$regex` throws it when the query runs, also on an empty database (filters are compiled once per
  query, before records are read).
- A `key` filter (`['key' => 5]`, `['key' => '5']`, `['key' => [1, 2]]`) means the same in every clause
  (`where()`, `orWhere()`, several `where()` calls, with spatial filters) as in `find()`: integers and
  canonical digit strings only (`"5\n"`, `" 5"` and `"05"` are not key 5). Operators on `key`
  compare strictly, as on any field: `['key' => ['$eq' => '1']]` and `['key' => ['$in' => ['1']]]` match
  nothing, because keys are integers.

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

A pattern is literal except a leading `^` (starts with) and a trailing `$` (ends with: `son$` doesn't match
`"Jackson\n"`).
`$like`, `like()` and `notLike()` compare case-insensitively with Unicode case folding:
`straße` matches `STRASSE`, `İstanbul` matches `istanbul`. Folding is the same for every language, so
the Turkish dotless `ı` and `I` don't match each other. Without the mbstring extension the PCRE `/iu`
rules apply (they miss `ß`/`ss` and `İ`/`i`).

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
Without `where()`, only the `orWhere()` groups decide. An empty group (`orWhere([])`) has no condition and
matches every record, so the query returns everything; this is intended (an empty `where([])` doesn't
filter either).

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

Exclude records where **any** of the given fields matches: a plain value matches with `===`; an
operator array matches when **all** of that field's operators match.
`whereNot(['role' => 'guest', 'dept' => 'hr'])` drops guests and everyone in HR. Several calls all apply.
A missing field doesn't match a plain value or most operators, so the record is kept; but `$ne`, `$nin`
and `$exists => false` do match a missing field: `whereNot(['role' => ['$ne' => 'admin']])` also drops
records without `role`.

```php
// All users except guests
$db->query("users")
    ->whereNot(['role' => 'guest'])
    ->get();

// Everyone except working-age users: all the operators of a field must match for a record to be excluded
$db->query("users")
    ->whereNot(['age' => ['$gte' => 18, '$lt' => 65]])
    ->get();
```

`whereNot()` checks its operators when it is called: an unknown operator, `$in`/`$nin` without an array
or an invalid `$regex` throws `InvalidArgumentException` at once (`where()` checks them when the query
runs).

### like() / notLike()

Pattern matching on string and number fields (booleans, arrays and missing fields never match).

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

Case-insensitive substring search: a record matches when the whole term appears in one of its string
or number field values (booleans and arrays aren't searched; no word splitting or ranking:
`search('php tutorial')` doesn't match "tutorial on php").

```php
// Search in all fields
$db->query("articles")
    ->search('php')
    ->get();

// Search in specific fields
$db->query("articles")
    ->search('php tutorial', ['title', 'content', 'tags'])
    ->get();
```

Matching uses Unicode case folding, as in `like()` (`İzmir`, `IZMIR` and `izmir` match each other; so do
`straße` and `STRASSE`); without the mbstring extension only ASCII letters are folded. Without a field
list, every field except `key` is searched, and also not the `_distance` that a spatial filter adds.

---

## Sorting & Pagination

### sort()

Sort results by field (`orderBy()` is an alias).

```php
// Ascending (default)
$db->query("users")->sort('name')->get();
$db->query("users")->sort('name', 'asc')->get();

// Descending
$db->query("products")->sort('price', 'desc')->get();

// Several keys: category first, then price
$db->query("products")->sort('category')->sort('price', 'desc')->get();

// Sort by distance (spatial queries; distances are in meters)
[$lon, $lat] = [28.9784, 41.0082];
$db->query("locations")
    ->withinDistance('coords', $lon, $lat, 10000)
    ->withDistance('coords', $lon, $lat)
    ->sort('_distance', 'asc')
    ->get();
```

Sorting is stable and uses a total order, reversed for `desc`:

1. missing / `null`
2. booleans (`false` < `true`)
3. numbers (int and float together)
4. numeric strings, by their number (`"9"` < `"10"` < `"1e3"`; leading and trailing whitespace allowed)
5. other strings, in byte order (`"1a"` < `"Z"` < `"a"`)
6. arrays (by their JSON)

The order is transitive (unlike PHP's `<=>` on mixed strings, where `"9"` < `"10"` < `"1a"` < `"9"`), so a result
never depends on the order of insertion. `min()` and `max()` (the builder's and `$db->min()/max()`) use the same
order.

### limit() / offset()

Paginate results (`skip()` is an alias of `offset()`).

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

`limit(0)` returns nothing: `limit(0)->first()` is `null` and `limit(0)->exists()` is `false`.

### select() / except()

Choose the fields of the returned records: `select()` keeps only the given fields, `except()` drops them.

```php
$db->query("users")->select(['name', 'email'])->get();   // [['name' => ..., 'email' => ..., 'key' => 3], ...]
$db->query("articles")->except(['content'])->get();
```

- `select()` always keeps `key`; `except()` removes it only when it is listed. With both, only
  `select()` applies.
- They only shape the result: every matching record is still read in full, and filters, `sort()` and
  aggregates see all fields.
- The `_distance` added by `withDistance()` is kept by `select()`; the one that `withinDistance()` and
  `nearest()` add is dropped unless selected.
- With `groupBy()` they apply to the group rows and their items (see [groupBy()](#groupby)).

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

`limit()` and `offset()` apply (`offset(2)->count()` on 6 matches is 4), as for every aggregate below;
with `groupBy()` it counts the groups.

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

### distinct()

The distinct values of a field among the matching records, in order of first appearance (records
without the field, or with `null`, are left out).

```php
$cities = $db->query("users")->where(['active' => true])->distinct('city'); // ['Istanbul', 'Ankara', ...]
```

Aggregates (`count`, `sum`, `avg`, `min`, `max`, `distinct`) work on the matching records before
`select()`/`except()`: `select(['name'])->sum('price')` sums the prices. With `groupBy()` only `count()` is defined
(it counts the groups): the others see the group rows, not the records.
`sum()`/`avg()` add numbers and numeric strings (booleans, `null`, arrays and other strings are left out;
the result of none is 0). `min()`/`max()` compare every value that isn't `null` in the sort order above
(so `max()` of `5` and `"x"` is `"x"`) and return `null` when there is none. `distinct()` takes O(n) time;
values are distinct by type (`1`, `1.0` and `"1"` are three values; `-0.0` and `0.0` are one).

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
- How values match:
  - integers, integral floats and canonical integer strings match each other (`5`, `5.0`, `"5"`), so
    `user_id = users.key` works whether `user_id` is stored as a number or a string;
  - other floats match only the same float (`1.5` doesn't match `1`);
  - other strings match only the same string (`"007"` doesn't match `7`);
  - `true` matches only `true` and `false` only `false`;
  - `null` and arrays match nothing.
- Joins on `key` read only the needed foreign records, joins on another field look the
  values up through that field's index when it has one (`createFieldIndex("users", "email")`). Without
  `groupBy()` and when `sort()` doesn't use the joined field, joins run after `sort()`/`limit()/offset()`,
  so only the returned records are joined.
- One consistent state: a query with joins holds shared locks on all its databases at once, so
  a writer can't change the foreign database half-way through. The locks are taken in a fixed order and
  never while waiting for a writer, so queries and writers can't deadlock.

### Multiple Joins

```php
$data = $db->query("order-items")
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

Groups come in the order their value first appears; `sort('_group')`, `sort('_count')`, `limit()` and
`offset()` apply to the groups. Group values keep their type (`1`, `"1"` and `1.5` are separate groups); records without the
field are grouped under `null`. `-0.0` and `0.0` are one group, and the `serialize_precision` setting doesn't
merge groups.

`select()`/`except()` with `groupBy()`: `_group`, `_count` and `_items` select or drop parts of each
group row, and any other field applies to the records in `_items`.

```php
$db->query("orders")->groupBy('status')->select(['_group', 'total'])->get();
// [['_group' => 'pending', '_items' => [['total' => 12.5, 'key' => 3], ...]], ...]
```

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
`=`, `!=`. `sum`, `avg`, `min` and `max` use the group's numbers and numeric strings: `sum` and `avg` of
none are 0, a group without any has no `min`/`max` and doesn't match. `having()` only applies to groups:
without `groupBy()`, or with an unknown aggregate or operator, the condition is ignored.

---

## Spatial Queries

Geometries are stored as GeoJSON (`[longitude, latitude]`); distances are in meters. Index management,
the GeoJSON formats, the direct methods (`$db->withinDistance()`, ...) and the exact matching rules are in
[SPATIAL.md](SPATIAL.md). Spatial queries work without an index; `createSpatialIndex()` makes them fast.

```php
$polygon = ['type' => 'Polygon', 'coordinates' => [[[28.95, 41.00], [29.01, 41.00], [29.01, 41.03], [28.95, 41.03], [28.95, 41.00]]]];
[$userLon, $userLat] = [28.9784, 41.0082];
$db->query("restaurants")->withinDistance('location', 28.9784, 41.0082, 5000)->get();    // within 5 km
$db->query("restaurants")->withinBBox('location', 28.97, 41.00, 29.00, 41.03)->get();   // bounding boxes overlap
$db->query("restaurants")->withinPolygon('location', $polygon)->get();                   // entirely inside
$db->query("restaurants")->nearest('location', 28.9784, 41.0082, 10)->get();            // the 10 nearest

// Spatial filters combine with every other filter; nearest() picks its k after the other filters
$restaurants = $db->query("restaurants")
    ->withinDistance('location', $userLon, $userLat, 5000)
    ->where(['open_now' => true, 'rating' => ['$gte' => 4.0], 'cuisine' => ['$in' => ['turkish', 'italian']]])
    ->withDistance('location', $userLon, $userLat)
    ->sort('_distance', 'asc')
    ->limit(20)
    ->get();
```

- `withinBBox()` compares bounding boxes: a line or polygon can match without entering the box itself.
- `withinDistance()` and `nearest()` add `_distance` (meters to their own center, 2 decimals);
  `withDistance()` adds or replaces it with the distance to its point (4 decimals), before sorting, and
  `select()` keeps it.
- The spatial filter runs first, whatever the order of the calls; the other filters are checked on its
  results.

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

### last()

Returns the last record of the result (after `sort()`, `offset()` and `limit()`) or null.

```php
$newest = $db->query("posts")->sort('created_at')->last();
```

### count() / sum() / distinct() / ...

The aggregates are terminal methods too; see [Aggregation](#aggregation).

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
`update()`, `delete()` and `removeFields()` can't be combined with `groupBy()` (`LogicException`).

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

## Query Planning & explain()

A query reads only what its filters need:

- **Indexes narrow the records down.** Plain values and `$eq`, `$in`, `whereIn()` on an indexed field
  read the keys from its field index; `$gt`, `$gte`, `$lt`, `$lte` and `between()` use the same index as a
  range index. `key` filters (plain value, `$eq`, `$in`) read only those keys. Several `where()` calls
  intersect their candidates; `orWhere()` uses indexes when every branch can. Negations (`$ne`, `$nin`,
  `whereNot()`, `whereNotIn()`, `notLike()`, `notBetween()`), `$exists`, `$like`/`like()`, `$regex`,
  `$contains`, ranges on `key` and `search()` read every record (of the segments that the other filters
  don't rule out). Every record read is checked against every filter, so an index only decides what is
  read, never what matches. Field indexes are used while `fieldIndexEnabled` is on (the default).
- **Shards are skipped** when their global field index rules the value out (sharded databases).
- **Records are read in batches** (32, then 128, ...) and the scan stops when enough match:
  `first()`, `exists()` and `limit()` without `sort()` read only what they need.
- **Top-k:** `sort()` with `offset + limit` up to 1000 keeps only the best rows while scanning instead of
  sorting every match. The result is the same as a full sort (ties keep key order). Not used with
  `withDistance()`, `groupBy()` or joins that must run before the sort (`explain()` then shows
  `"sort" => "full"`). A spatial filter or a plain `key` filter sorts all its matches too, though `explain()` shows
  `"top-k"` for it.

`explain()` shows what a query would do, without reading records or building anything:

```php
// 2000 users in 4 shards of 500, one city per shard; "city" and "age" are indexed
$plan = $db->query("users")->where(['city' => 'Istanbul', 'age' => ['$gte' => 30]])->limit(10)->explain();
// [
//   "access" => "index",          // index | scan | mixed | spatial | none
//   "segments" => ["total" => 4, "skipped" => 3, "indexed" => 1, "scanned" => 0],
//   "plan" => [["segment" => 0, "access" => "index", "candidates" => 380], ["segment" => 1, "access" => "skip", ...], ...],
//   "estimatedRead" => 380,       // candidates of the plan (limit() stops earlier)
//   "sort" => "none",             // none | top-k | full
//   "earlyExit" => true,
//   "joins" => [],                // per join: db, on, strategy (key-lookup | in-lookup), when (after-slice | before-sort)
//   ...                           // also: explain (1), db, sharded, planTruncated, filter, residual, spatial, groupBy
// ]

$plan = $db->query("users")->where(['city' => 'Istanbul'])->explain(true); // also runs the query
// $plan['actual'] = ["recordsRead" => 500, "segmentsRead" => 1, "returned" => 500, "ms" => 1.2]
```

An index that a query would build first (missing or out of date) counts as a scan in `explain()`; the
query itself builds it.

---

## Real-World Examples

The values a request gives:

```php
$searchTerm = 'laptop';
$page = 0;
$email = 'jane@example.com';
$password = 'what the user typed';
[$startDate, $endDate] = ['2026-10-01', '2026-10-31'];
[$userLon, $userLat] = [28.9784, 41.0082];
$maxBudget = 50;
$selectedCategories = ['plumbing', 'electrics'];
$term = 'php';
```

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
    ->where(['email' => $email, 'status' => 'active', 'email_verified' => true])
    ->first();
$ok = $user !== null && password_verify($password, $user['password_hash']);
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
    ->search($term, ['title', 'content', 'excerpt'])
    ->sort('published_at', 'desc')
    ->get();
```

---

## Performance Tips

### 1. Use Indexes

```php
// Field indexes for fields you filter on with plain values, $in, whereIn(), ranges or between()
$db->createFieldIndex("users", "email");
$db->createFieldIndex("products", "category");

// Spatial index for location queries (the R-tree instead of scanning every record)
$db->createSpatialIndex("locations", "coords");
```

`explain()` shows whether a query uses them (see [Query Planning & explain()](#query-planning--explain)).

### 2. Limit Results

```php
// Always limit when you don't need all results
$db->query("logs")->limit(100)->get();

// Use first() instead of get()[0]
$db->query("users")->where(['email' => 'jane@example.com'])->first();
```

### 3. Filters Decide What Is Read

The order of the calls doesn't matter: every `where()`, `whereIn()` and `between()` filter is planned
together, and filters on indexed fields narrow down what is read. `select()`/`except()` only shape the
returned arrays; they don't reduce reading.

### 4. Batch Operations

```php
// Batch insert instead of individual inserts: one operation, one lock, one append per shard
$db->insert("logs", array_map(function ($i) { return ['n' => $i, 'level' => 'info']; }, range(1, 1000)));

// Batch update
$db->query("orders")
    ->where(['status' => 'pending', 'created_at' => ['$lt' => '2026-01-01']])
    ->update(['status' => 'expired']);
```

---

## Operator Quick Reference

### Comparison Operators

<!-- doctest: skip -->
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

<!-- doctest: skip -->
```php
->withinDistance($field, $lon, $lat, $distanceMeters)
->withinBBox($field, $minLon, $minLat, $maxLon, $maxLat)
->nearest($field, $lon, $lat, $k)
->withinPolygon($field, $polygon)
->withDistance($field, $lon, $lat)
```

### Filter Methods

<!-- doctest: skip -->
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

<!-- doctest: skip -->
```php
->sort($field, 'asc'|'desc')  // alias: orderBy()
->limit($count)
->offset($count)              // alias: skip()
->select([...])
->except([...])
->join($foreignDb, $localKey, $foreignKey, $alias = null)
->groupBy($field)
->having($aggregate, $operator, $value)
```

### Terminal Methods

<!-- doctest: skip -->
```php
->get()                // Array of records
->first() / ->last()   // Single record or null
->count()              // Integer count
->exists()             // Boolean
->sum($field)          // Numeric sum
->avg($field)          // Numeric average
->min($field)          // Minimum value
->max($field)          // Maximum value
->distinct($field)     // Distinct values
->explain($analyze)    // The query plan
->update([...])        // ['n' => ...]
->delete()             // ['n' => ...]
->removeFields([...])  // ['n' => ..., 'fields_removed' => [...]]
```

