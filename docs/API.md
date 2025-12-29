# noneDB API Reference

Complete reference for all noneDB methods and operations.

**Version:** 3.1.0

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
// Returns: true or false
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

// Single database info
$info = $db->getDBs("users");
// ["name" => "users", "createdTime" => 1703123456, "size" => "2,5 KB"]
```

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
```

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

**Returns:** Array of records with `key` field added.

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

### delete()

Delete matching records.

```php
// By field
$result = $db->delete("users", ["name" => "John"]);
// ["n" => 1]

// By key
$result = $db->delete("users", ["key" => [0, 2]]);

// All records
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
| `where($filters)` | AND filter with operator support |
| `orWhere($filters)` | OR filter |
| `whereIn($field, $values)` | Value in array |
| `whereNotIn($field, $values)` | Value not in array |
| `whereNot($filters)` | Not equal |
| `like($field, $pattern)` | Pattern match |
| `notLike($field, $pattern)` | Pattern not match |
| `between($field, $min, $max)` | Range (inclusive) |
| `notBetween($field, $min, $max)` | Outside range |
| `search($term, $fields)` | Full-text search |

### Comparison Operators

```php
$db->query("users")->where([
    'age' => ['$gt' => 18],      // Greater than
    'age' => ['$gte' => 18],     // Greater than or equal
    'age' => ['$lt' => 65],      // Less than
    'age' => ['$lte' => 65],     // Less than or equal
    'role' => ['$ne' => 'guest'],// Not equal
    'role' => ['$eq' => 'admin'],// Equal (explicit)
    'status' => ['$in' => ['active', 'pending']],   // In array
    'status' => ['$nin' => ['banned', 'deleted']],  // Not in array
    'email' => ['$exists' => true],  // Field exists
    'name' => ['$like' => 'John'],   // Pattern match
    'email' => ['$regex' => '@gmail.com$'], // Regex
    'tags' => ['$contains' => 'featured']   // Array/string contains
]);
```

### Modifiers

| Method | Description |
|--------|-------------|
| `select($fields)` | Include only specified fields |
| `except($fields)` | Exclude specified fields |
| `sort($field, $order)` | Sort results |
| `limit($count)` | Limit results |
| `offset($count)` | Skip records |
| `join($db, $localKey, $foreignKey)` | Join databases |
| `groupBy($field)` | Group results |
| `having($aggregate, $op, $value)` | Filter groups |

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
| `update($set)` | Update matches |
| `delete()` | Delete matches |

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

```php
$result = $db->compact("users");
// ["success" => true, "freedSlots" => 150, ...]
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
// ["success" => true]
```

### hasFieldIndex()

```php
$exists = $db->hasFieldIndex("users", "email");
// true/false
```

### dropFieldIndex()

```php
$result = $db->dropFieldIndex("users", "email");
```

---

## Spatial Indexing

### createSpatialIndex()

```php
$result = $db->createSpatialIndex("locations", "coords");
// ["success" => true, "indexed" => 150]

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
// ["valid" => true] or ["valid" => false, "error" => "..."]
```

---

## Utility Methods

### sort()

```php
$sorted = $db->sort($results, "name", "asc");
$sorted = $db->sort($results, "created_at", "desc");
```

### limit()

```php
$limited = $db->limit($results, 10);
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

### Configuration

```php
noneDB::configExists();       // Check config file
noneDB::getConfigTemplate();  // Get template path
noneDB::clearConfigCache();   // Clear cached config
noneDB::setDevMode(true);     // Enable dev mode
noneDB::isDevMode();          // Check dev mode
```
