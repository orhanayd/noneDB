# noneDB API Reference

Complete reference for all noneDB methods and operations.

**Version:** 3.2.0

**Database names** may contain `A-Z a-z 0-9`, space, `'` and `-`; every method throws
`InvalidArgumentException` for anything else (names are never changed silently). `"0"` is valid.

**Locking:** every method locks its database for its whole duration (shared for reads, exclusive for
writes). Waiting longer than `lockTimeout` seconds throws `RuntimeException`.

---

## Table of Contents

1. [Database Operations](#database-operations)
2. [CRUD Operations](#crud-operations)
3. [Query Operations](#query-operations)
4. [Aggregation](#aggregation)
5. [Sharding](#sharding)
6. [Field Indexing](#field-indexing)
7. [Spatial Indexing](#spatial-indexing)
8. [Utility Methods](#utility-methods)

---

## Database Operations

### createDB()

Create a new database.

```php
$result = $db->createDB("users");
// Returns: true (created) or false (already exists)
```

### checkDB()

Check if database exists. Creates it if `autoCreateDB` is true.

```php
$exists = $db->checkDB("users");
// Returns: true or false (false for null / "")
```

### dropDB()

Delete a database and all of its files (data, shards, indexes, buffers, metadata).

```php
$result = $db->dropDB("users");
// ["success" => true, "files" => 7]   or   ["success" => false, "files" => 0, "error" => "Database does not exist"]
```

### migrateKey() (static)

Re-key existing databases (all files of every database created with `$oldKey` are renamed). Run it
while nothing else uses the databases, then put the new key in your config.

```php
$result = noneDB::migrateKey('/var/data/db', $oldKey, $newKey);
// ["success" => true, "databases" => ["users", "orders"], "files" => 14, "error" => null]
```

### getDBs()

List databases.

```php
// Names only
$names = $db->getDBs(false);
// ["users", "posts", "comments"]

// With metadata
$dbs = $db->getDBs(true);
// [
//     ["name" => "users", "createdTime" => 1703123456, "size" => "2,5 KB"],
//     ...
// ]

// Single database info (false if it doesn't exist; never creates it)
$info = $db->getDBs("users");
// ["name" => "users", "createdTime" => 1703123456, "size" => "2,5 KB"]
```

Only databases of the current `secretKey` are listed; sharded databases appear once, with the total
size of their shards. Sizes use a comma as decimal separator (`"2,5 KB"`).

---

## CRUD Operations

### insert()

Insert one or more records.

```php
// Single record
$result = $db->insert("users", ["name" => "John", "email" => "john@test.com"]);
// ["n" => 1]

// Multiple records
$result = $db->insert("users", [
    ["name" => "John"],
    ["name" => "Jane"]
]);
// ["n" => 2]

// With nested data
$result = $db->insert("users", [
    "name" => "John",
    "address" => ["city" => "Istanbul", "country" => "Turkey"]
]);
```

**Errors:**

```php
// Reserved field
$db->insert("users", ["key" => "value"]);
// ["n" => 0, "error" => "You cannot set key name to key"]

// Invalid data
$db->insert("users", "not an array");
// ["n" => 0, "error" => "insert data must be array"]

// Empty data, or a mix of a list and named fields
$db->insert("users", []);                              // ["n" => 0, "error" => "insert data must not be empty"]
$db->insert("users", [0 => ["a" => 1], "name" => "x"]); // ["n" => 0, "error" => "insert data must be either one record or a list of records"]

// Values JSON can't represent (invalid UTF-8, NAN, INF): nothing is written
$db->insert("users", ["v" => NAN]);                  // ["n" => 0, "error" => "insert data cannot be encoded as JSON ..."]
```

Keys are assigned in order and are **never reused or changed** (not by deletes, not by `compact()`).
Floats keep their type (`2.0` is read back as `2.0`).

### find()

Find records matching filter.

```php
// All records
$all = $db->find("users", 0);
$all = $db->find("users", []);

// By field
$results = $db->find("users", ["name" => "John"]);

// By multiple fields (AND)
$results = $db->find("users", ["name" => "John", "active" => true]);

// By key
$results = $db->find("users", ["key" => 0]);
$results = $db->find("users", ["key" => [0, 1, 2]]);
```

**Matching rules** (shared by `find()`, `update()` and `delete()`):

- Every filter field must exist in the record and be **identical** (`===`): `"25"` does not match `25`,
  `null` matches only a field that exists with the value `null`, arrays must be equal as a whole.
- `key` accepts an integer, a digit string (`"5"`) or a list of them; anything else matches nothing.
  It is combined with the other filters (AND).
- Any integer (normally `0`), `null` and `[]` select every record. Other non-array filters return `false`.

**Returns:** Array of records with `key` field added (in key order, or in the order of a `key` list).
Returns `[]` for a database that doesn't exist (`false` if `autoCreateDB` is off); reading never
creates a database.

### update()

Update matching records.

```php
// By field
$result = $db->update("users", [
    ["name" => "John"],           // Filter
    ["set" => ["active" => true]] // Updates
]);
// ["n" => 1]

// By key
$result = $db->update("users", [
    ["key" => [0, 1, 2]],
    ["set" => ["status" => "inactive"]]
]);

// All records
$result = $db->update("users", [
    [],
    ["set" => ["updated_at" => time()]]
]);
```

The first element must be a filter array and `set` a non-`key` array of fields; otherwise
`["n" => 0, "error" => "Please check your update parameters"]`. An empty `set` changes nothing and
returns the number of matching records.

### delete()

Delete matching records.

```php
// By field
$result = $db->delete("users", ["name" => "John"]);
// ["n" => 1]

// By key
$result = $db->delete("users", ["key" => [0, 2]]);

// All records (an empty filter matches everything)
$result = $db->delete("users", []);
```

---

## Query Operations

### Query Builder

```php
$query = $db->query("users");
```

### Filter Methods

| Method | Description |
|--------|-------------|
| `where($filters)` | AND filter with operator support; several calls are all applied (AND) |
| `orWhere($filters)` | OR group: matches if all `where()` groups match **or** any `orWhere()` group does |
| `whereIn($field, $values)` | Value in array |
| `whereNotIn($field, $values)` | Value not in array |
| `whereNot($filters)` | Excludes records where **any** of the given fields equals its value (`===`); several calls all apply |
| `like($field, $pattern)` | Pattern match |
| `notLike($field, $pattern)` | Pattern not match |
| `between($field, $min, $max)` | Range (inclusive) |
| `notBetween($field, $min, $max)` | Not inside the range: also returns records without the field or with a value that can't be compared (e.g. `null`, `true`, text vs numbers) |
| `search($term, $fields)` | Full-text search |

### Comparison Operators

```php
// Several operators on one field go into one array (a PHP array can't repeat a key)
$db->query("users")->where([
    'age' => ['$gte' => 18, '$lt' => 65],            // Range
    'role' => ['$ne' => 'guest'],                     // Not equal
    'status' => ['$in' => ['active', 'pending']],      // In array (strict)
    'email' => ['$exists' => true],                   // Field exists
    'name' => ['$like' => '^John'],                   // Pattern (only ^ and $ are special)
    'tags' => ['$contains' => 'featured']             // Array element / substring
])->get();

// ...or one where() per condition: all of them apply
$db->query("users")
    ->where(['age' => ['$gt' => 18]])
    ->where(['age' => ['$lte' => 65]])
    ->where(['email' => ['$regex' => '@gmail\\.com$']])   // regular expression, case-insensitive
    ->get();
```

- `$gt`, `$gte`, `$lt`, `$lte` (and `between()`) compare numbers and numeric strings numerically and
  other strings lexically. `null`, booleans, arrays, and a number against a non-numeric string never
  match.
- `$eq`, `$ne`, `$in`, `$nin` use strict comparison (`===`).
- An unknown operator (e.g. a typo like `$gtee`), `$in`/`$nin` without an array or an invalid `$regex`
  throws `InvalidArgumentException`, also when there are no records to check.

### Modifiers

| Method | Description |
|--------|-------------|
| `select($fields)` | Include only specified fields |
| `except($fields)` | Exclude specified fields |
| `sort($field, $order)` | Sort results; further calls add tie-breaking keys |
| `limit($count)` | Limit results |
| `offset($count)` | Skip records |
| `join($db, $localKey, $foreignKey, $alias = null)` | Attach the matching foreign record under `$alias` (default: the foreign database name; it replaces a field of that name in the result only). With duplicate foreign keys the last record wins |
| `groupBy($field)` | Group results (values keep their type: `1`, `"1"` and `1.5` are different groups) |
| `having($aggregate, $op, $value)` | Filter groups: `count`, `sum:f`, `avg:f`, `min:f`, `max:f`. Ignored without `groupBy()` or for unknown aggregates/operators |

### Terminal Methods

| Method | Returns |
|--------|---------|
| `get()` | All matching records |
| `first()` | First record or null |
| `last()` | Last record or null |
| `count()` | Number of matches |
| `exists()` | Boolean |
| `sum($field)` | Sum of field |
| `avg($field)` | Average of field |
| `min($field)` | Minimum value |
| `max($field)` | Maximum value |
| `distinct($field)` | Unique values |
| `update($set)` | Update matches (sort/offset/limit choose which ones) |
| `delete()` | Delete matches (sort/offset/limit choose which ones) |
| `removeFields($fields)` | Remove fields from the **stored** matching records |

---

## Aggregation

### count()

```php
$total = $db->count("users", 0);
$active = $db->count("users", ["active" => true]);
```

### sum() / avg()

```php
$totalSalary = $db->sum("users", "salary");
$avgAge = $db->avg("users", "age");
$filteredSum = $db->sum("orders", "amount", ["status" => "paid"]);
```

### min() / max()

```php
$minPrice = $db->min("products", "price");
$maxScore = $db->max("scores", "points");
```

### distinct()

```php
$cities = $db->distinct("users", "city");
// ["Istanbul", "Ankara", "Izmir"]
```

---

## Sharding

### getShardInfo()

```php
$info = $db->getShardInfo("users");
// [
//     "sharded" => true,
//     "shards" => 5,
//     "totalRecords" => 45000,
//     "deletedCount" => 150,
//     "shardSize" => 10000,
//     "nextKey" => 45150
// ]
```

### compact()

Rewrites data files without deleted and superseded records. Keys never change; shards that became
empty are removed. Usually not needed: compaction also runs automatically.

```php
$result = $db->compact("users");
// ["success" => true, "freedSlots" => 150, "totalRecords" => 44850, "sharded" => true, "newShardCount" => 5]
```

### migrate()

```php
$result = $db->migrate("users");
// ["success" => true, "status" => "migrated"]
```

### Configuration

```php
$db->isShardingEnabled();  // true/false
$db->getShardSize();       // 10000
```

---

## Field Indexing

### createFieldIndex()

```php
$result = $db->createFieldIndex("users", "email");
// ["success" => true, "indexed" => 150, "values" => 120, "error" => null]  (+ "shards" => N when sharded)
// Calling it again for an indexed field rebuilds the index
```

### hasFieldIndex()

```php
$exists = $db->hasFieldIndex("users", "email");
// true/false
```

### getFieldIndexes() / rebuildFieldIndex() / getKeysFromFieldIndex()

```php
$db->getFieldIndexes("users");                       // ["fields" => ["email", "e-mail"], "sharded" => false]
$db->rebuildFieldIndex("users", "email");            // same result as createFieldIndex()
$db->getKeysFromFieldIndex("users", "status", "active"); // [0, 4, 7] or null without an index
```

Field names may contain any characters. Indexes are maintained by every write and give exactly the
results of a full scan.

### dropFieldIndex()

```php
$result = $db->dropFieldIndex("users", "email");
// ["success" => true, ...] or ["success" => false, "error" => "Index does not exist"]
```

---

## Spatial Indexing

### createSpatialIndex()

```php
$result = $db->createSpatialIndex("locations", "coords");
// ["success" => true, "indexed" => 150, "error" => null]
// An existing index: ["success" => false, "indexed" => 0, "error" => "Spatial index already exists for field 'coords'"]

// Records with invalid/missing GeoJSON are skipped (not indexed)
// Always check 'indexed' count to verify
```

### hasSpatialIndex()

```php
$exists = $db->hasSpatialIndex("locations", "coords");
// true/false
```

### getSpatialIndexes()

```php
$indexes = $db->getSpatialIndexes("locations");
// ["coords", "boundary"]
```

### dropSpatialIndex()

```php
$result = $db->dropSpatialIndex("locations", "coords");
// ["success" => true, "error" => null], also when there was no such index (unlike dropFieldIndex())
```

### rebuildSpatialIndex()

```php
$result = $db->rebuildSpatialIndex("locations", "coords");
// Drops and recreates the spatial index from existing data
```

### withinDistance()

```php
$nearby = $db->withinDistance("locations", "coords", 28.97, 41.00, 5000);
// Records within 5000 meters (5km)
```

### withinBBox()

```php
$inArea = $db->withinBBox("locations", "coords", 28.97, 41.00, 29.00, 41.03);
```

### nearest()

```php
$closest = $db->nearest("locations", "coords", 28.97, 41.00, 5);
// 5 nearest records
```

### withinPolygon()

```php
$polygon = ['type' => 'Polygon', 'coordinates' => [...]];
$inside = $db->withinPolygon("locations", "coords", $polygon);
```

### validateGeoJSON()

```php
$result = $db->validateGeoJSON($geometry);
// ["valid" => true, "error" => null, "type" => "Point"]
// ["valid" => false, "error" => "Longitude must be between -180 and 180", "type" => null]
```

---

## Utility Methods

### sort()

```php
$sorted = $db->sort($results, "name", "asc");
$sorted = $db->sort($results, "created_at", "desc");
```

Stable, total order: missing/`null` < booleans < numbers < strings < arrays (reversed for `desc`).
Returns `[]` for an empty array and `false` for a non-array.

### limit()

```php
$limited = $db->limit($results, 10);   // [] for an empty array, false for invalid arguments
```

### first() / last()

```php
$first = $db->first("users");
$last = $db->last("users", ["active" => true]);
```

### exists()

```php
$exists = $db->exists("users", ["email" => "test@test.com"]);
// true/false
```

### like()

```php
$results = $db->like("users", "email", "gmail");    // Contains
$results = $db->like("users", "name", "^John");     // Starts with
$results = $db->like("users", "name", "son$");      // Ends with
```

Case-insensitive. Only a leading `^` and a trailing `$` are special; every other character (`.`, `/`,
`*`, ...) matches itself.

### between()

```php
$results = $db->between("products", "price", 100, 500);
$results = $db->between("products", "price", 100, 500, ["active" => true]);
```

### Static Cache

```php
noneDB::clearStaticCache();
noneDB::disableStaticCache();
noneDB::enableStaticCache();
```

### Buffering and indexes

```php
$db->enableBuffering(true);          // off by default
$db->setBufferSizeLimit(1048576);    // bytes (min 1KB)
$db->setBufferCountLimit(10000);     // records (min 10)
$db->setBufferFlushInterval(30);     // seconds, 0 = no time-based flush
$db->flush("users");                // ["success" => true, "flushed" => 12, "error" => null]
$db->flushAllBuffers();             // ["databases" => 2, "flushed" => 40]
$db->getBufferInfo("users");        // ["enabled" => true, ..., "buffers" => ["main" => ["exists" => true, "size" => 812, "records" => 12]]]

$db->rebuildIndex("users");         // rebuild the key indexes from the data files (recovery tool)
$db->getIndexInfo("users");         // ["exists" => true, "totalRecords" => 120, "sharded" => false, ...]
```

### Configuration

```php
noneDB::configExists();       // Check config file
noneDB::getConfigTemplate();  // Get template path
noneDB::clearConfigCache();   // Clear cached config
noneDB::setDevMode(true);     // Enable dev mode
noneDB::isDevMode();          // Check dev mode
```
