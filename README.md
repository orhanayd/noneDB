# noneDB

[![Version](https://img.shields.io/badge/version-3.0.0-orange.svg)](CHANGES.md)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-759%20passed-brightgreen.svg)](tests/)
[![Thread Safe](https://img.shields.io/badge/thread--safe-atomic%20locking-success.svg)](#concurrent-access--atomic-operations)

**noneDB** is a lightweight, file-based NoSQL database for PHP. No installation required - just include and go!

## Features

- **Zero dependencies** - single PHP file (~6200 lines)
- **No database server required** - just include and use
- **JSONL storage with byte-offset indexing** - O(1) key lookups
- **Static cache sharing** - cross-instance cache for maximum performance
- **Atomic file locking** - thread-safe concurrent operations
- **Auto-compaction** - automatic cleanup of deleted records
- **Auto-sharding** for large datasets (500K+ records tested)
- **Method chaining** (fluent interface) for clean queries
- Full CRUD operations with advanced filtering
- Aggregation functions (sum, avg, min, max, count, distinct)
- Full-text search, pattern matching, range queries

## Requirements

- PHP 7.4 or higher
- Write permission on database directory

## Installation

### Manual
```php
include("noneDB.php");
$db = new noneDB();
```

### Composer
```bash
composer require orhanayd/nonedb
```

---

## Upgrading

> **CRITICAL: Before updating noneDB, you MUST backup your `$secretKey`!**

The `$secretKey` is used to hash database filenames. If you lose it or it changes, you will **lose access to all your existing data**.

### Upgrade Steps

1. **Before update:** Copy your current `$secretKey` from `noneDB.php`
   ```php
   private $secretKey = "your_current_key";  // SAVE THIS!
   ```

2. **Update:** Replace `noneDB.php` with the new version

3. **After update:** Restore your `$secretKey` in the new `noneDB.php`
   ```php
   private $secretKey = "your_current_key";  // PASTE IT BACK!
   ```

4. **Verify:** Test that your databases are accessible

> **Warning:** If you use the default key `"nonedb_123"` in production, change it immediately. But once changed, never change it again or you'll lose access to your data.

---

## Configuration

> **IMPORTANT: Change these settings before production use!**

Edit `noneDB.php`:

```php
private $dbDir = __DIR__."/db/";      // Database directory path
private $secretKey = "nonedb_123";     // Secret key for hashing - CHANGE THIS!
private $autoCreateDB = true;          // Auto-create databases on first use

// Sharding configuration
private $shardingEnabled = true;       // Enable auto-sharding for large datasets
private $shardSize = 10000;            // Records per shard (default: 10K)
private $autoMigrate = true;           // Auto-migrate when threshold reached

// Auto-compaction configuration
private $autoCompactThreshold = 0.3;   // Compact when 30% of records are deleted
```

### Security Warnings

| Setting | Warning |
|---------|---------|
| `$secretKey` | **MUST change before production!** Used for hashing database names. Never share or commit to public repos. |
| `$dbDir` | Should be outside web root or protected with `.htaccess` |
| `$autoCreateDB` | Set to `false` in production to prevent accidental database creation |

### Protecting Database Directory

Create `db/.htaccess`:
```apache
Deny from all
```

---

## Quick Start

```php
<?php
include("noneDB.php");
$db = new noneDB();

// Insert
$db->insert("users", ["name" => "John", "email" => "john@example.com"]);

// Find
$users = $db->find("users", ["name" => "John"]);

// Update
$db->update("users", [
    ["name" => "John"],
    ["set" => ["email" => "john.doe@example.com"]]
]);

// Delete
$db->delete("users", ["name" => "John"]);
```

---

## API Reference

### insert($dbname, $data)

Insert one or more records.

```php
// Single record
$result = $db->insert("users", [
    "name" => "John",
    "email" => "john@example.com"
]);
// Returns: ["n" => 1]

// Multiple records
$result = $db->insert("users", [
    ["name" => "John", "email" => "john@example.com"],
    ["name" => "Jane", "email" => "jane@example.com"]
]);
// Returns: ["n" => 2]

// Nested data is supported
$result = $db->insert("users", [
    "name" => "John",
    "address" => [
        "city" => "Istanbul",
        "country" => "Turkey"
    ]
]);
```

> **Warning:** Field name `key` is reserved at the top level. You cannot use `["key" => "value"]` but nested `["data" => ["key" => "value"]]` is allowed.

---

### find($dbname, $filter)

Find records matching filter criteria.

```php
// Get ALL records
$all = $db->find("users", 0);
// or
$all = $db->find("users", []);

// Find by field value
$result = $db->find("users", ["name" => "John"]);

// Find by multiple fields (AND condition)
$result = $db->find("users", ["name" => "John", "status" => "active"]);

// Find by key (index)
$result = $db->find("users", ["key" => 0]);       // Single key
$result = $db->find("users", ["key" => [0, 2, 5]]); // Multiple keys
```

**Response:**
```php
[
    ["name" => "John", "email" => "john@example.com", "key" => 0],
    ["name" => "Jane", "email" => "jane@example.com", "key" => 1]
]
```

> **Note:** Each result includes a `key` field with the record's index.

---

### update($dbname, $data)

Update records matching criteria.

```php
// Update by field
$result = $db->update("users", [
    ["name" => "John"],                    // Filter
    ["set" => ["email" => "new@email.com"]] // New values
]);
// Returns: ["n" => 1] (number of updated records)

// Update by key
$result = $db->update("users", [
    ["key" => [0, 1, 2]],
    ["set" => ["status" => "inactive"]]
]);

// Add new field to existing records
$result = $db->update("users", [
    ["name" => "John"],
    ["set" => ["phone" => "555-1234"]]
]);

// Update ALL records
$result = $db->update("users", [
    [],                                    // Empty filter = all records
    ["set" => ["updated_at" => time()]]
]);
```

> **Warning:** You cannot set `key` field in update - it's reserved.

---

### delete($dbname, $filter)

Delete records matching criteria.

```php
// Delete by field
$result = $db->delete("users", ["name" => "John"]);
// Returns: ["n" => 1]

// Delete by key
$result = $db->delete("users", ["key" => [0, 2]]);

// Delete ALL records
$result = $db->delete("users", []);
```

> **Note:** Deleted records are immediately removed from the index. Data stays in file until auto-compaction triggers (when deleted > 30%).

---

### createDB($dbname)

Manually create a database.

```php
$result = $db->createDB("mydb");
// Returns: true (success) or false (already exists)
```

---

### checkDB($dbname)

Check if database exists. Creates it if `autoCreateDB` is `true`.

```php
$exists = $db->checkDB("mydb");
// Returns: true or false
```

---

### getDBs($info)

List databases.

```php
// Get database names only
$names = $db->getDBs(false);
// Returns: ["users", "posts", "comments"]

// Get databases with metadata
$dbs = $db->getDBs(true);
// Returns:
// [
//     ["name" => "users", "createdTime" => 1703123456, "size" => "2,5 KB"],
//     ["name" => "posts", "createdTime" => 1703123789, "size" => "1,2 KB"]
// ]

// Get specific database info
$info = $db->getDBs("users");
// Returns: ["name" => "users", "createdTime" => 1703123456, "size" => "2,5 KB"]
```

---

### limit($array, $count)

Limit results.

```php
$all = $db->find("users", 0);
$first10 = $db->limit($all, 10);
```

---

### sort($array, $field, $order)

Sort results by field.

```php
$users = $db->find("users", 0);
$sorted = $db->sort($users, "age", "asc");   // Ascending
$sorted = $db->sort($users, "name", "desc"); // Descending
```

---

### count($dbname, $filter)

Count records matching filter.

```php
$total = $db->count("users", 0);                    // All records
$active = $db->count("users", ["active" => true]);  // Filtered count
```

---

### distinct($dbname, $field)

Get unique values for a field.

```php
$cities = $db->distinct("users", "city");
// Returns: ["Istanbul", "Ankara", "Izmir"]
```

---

### like($dbname, $field, $pattern)

Pattern matching search.

```php
$db->like("users", "email", "gmail");    // Contains "gmail"
$db->like("users", "name", "^John");     // Starts with "John"
$db->like("users", "name", "son$");      // Ends with "son"
```

---

### between($dbname, $field, $min, $max, $filter)

Range query.

```php
$products = $db->between("products", "price", 100, 500);
$active = $db->between("products", "price", 100, 500, ["active" => true]);
```

---

### sum($dbname, $field, $filter) / avg($dbname, $field, $filter)

Aggregation functions.

```php
$total = $db->sum("orders", "amount");
$average = $db->avg("users", "age");
$filtered = $db->sum("orders", "amount", ["status" => "paid"]);
```

---

### min($dbname, $field, $filter) / max($dbname, $field, $filter)

Get minimum/maximum values.

```php
$cheapest = $db->min("products", "price");
$mostExpensive = $db->max("products", "price");
```

---

### first($dbname, $filter) / last($dbname, $filter)

Get first or last matching record.

```php
$firstUser = $db->first("users");
$lastOrder = $db->last("orders", ["user_id" => 5]);
```

---

### exists($dbname, $filter)

Check if records exist.

```php
if ($db->exists("users", ["email" => "john@test.com"])) {
    echo "User exists!";
}
```

---

## Method Chaining (Fluent Interface)

noneDB supports fluent method chaining for building complex queries with clean, readable syntax.

### Basic Usage

```php
// Old API (still works)
$results = $db->find("users", ["active" => true]);
$sorted = $db->sort($results, "score", "desc");
$limited = $db->limit($sorted, 10);

// New Fluent API
$results = $db->query("users")
    ->where(["active" => true])
    ->sort("score", "desc")
    ->limit(10)
    ->get();
```

### Chainable Methods

#### Basic Filters

| Method | Description | Example |
|--------|-------------|---------|
| `where($filters)` | Filter by field values (AND) | `->where(["active" => true])` |
| `orWhere($filters)` | OR condition filter | `->orWhere(["role" => "admin"])` |
| `whereIn($field, $values)` | Field value in array | `->whereIn("status", ["active", "pending"])` |
| `whereNotIn($field, $values)` | Field value NOT in array | `->whereNotIn("role", ["banned", "suspended"])` |
| `whereNot($filters)` | NOT equal filter | `->whereNot(["deleted" => true])` |

#### Pattern & Range Filters

| Method | Description | Example |
|--------|-------------|---------|
| `like($field, $pattern)` | Pattern match (^start, end$) | `->like("email", "gmail")` |
| `notLike($field, $pattern)` | Pattern NOT match | `->notLike("email", "test")` |
| `between($field, $min, $max)` | Range filter (inclusive) | `->between("age", 18, 65)` |
| `notBetween($field, $min, $max)` | Outside range | `->notBetween("price", 100, 500)` |

#### Advanced Filters

| Method | Description | Example |
|--------|-------------|---------|
| `search($term, $fields)` | Full-text search | `->search("john", ["name", "email"])` |
| `join($db, $localKey, $foreignKey)` | Join with another database | `->join("orders", "id", "user_id")` |

#### Grouping & Aggregation

| Method | Description | Example |
|--------|-------------|---------|
| `groupBy($field)` | Group results by field | `->groupBy("category")` |
| `having($aggregate, $op, $value)` | Filter groups | `->having("count", ">", 5)` |

#### Field Selection

| Method | Description | Example |
|--------|-------------|---------|
| `select($fields)` | Include only specific fields | `->select(["name", "email"])` |
| `except($fields)` | Exclude specific fields | `->except(["password", "token"])` |

#### Sorting & Pagination

| Method | Description | Example |
|--------|-------------|---------|
| `sort($field, $order)` | Sort results | `->sort("created_at", "desc")` |
| `orderBy($field, $order)` | Alias for sort | `->orderBy("name", "asc")` |
| `limit($count)` | Limit results | `->limit(10)` |
| `offset($count)` | Skip results | `->offset(20)` |
| `skip($count)` | Alias for offset | `->skip(20)` |

### Terminal Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `get()` | `array` | All matching records |
| `first()` | `?array` | First record or null |
| `last()` | `?array` | Last record or null |
| `count()` | `int` | Number of matches |
| `exists()` | `bool` | True if any match |
| `sum($field)` | `float` | Sum of field values |
| `avg($field)` | `float` | Average of field |
| `min($field)` | `mixed` | Minimum value |
| `max($field)` | `mixed` | Maximum value |
| `distinct($field)` | `array` | Unique values |
| `update($set)` | `array` | Update matching records |
| `delete()` | `array` | Delete matching records |
| `removeFields($fields)` | `array` | Remove fields permanently |

### Examples

```php
// Complex query with multiple filters
$topUsers = $db->query("users")
    ->where(["active" => true])
    ->whereIn("role", ["admin", "moderator"])
    ->between("age", 18, 35)
    ->like("email", "gmail.com$")
    ->sort("score", "desc")
    ->limit(10)
    ->get();

// OR conditions
$users = $db->query("users")
    ->where(["department" => "IT"])
    ->orWhere(["department" => "Engineering"])
    ->orWhere(["role" => "admin"])
    ->get();

// Full-text search
$results = $db->query("products")
    ->search("wireless keyboard")
    ->sort("price", "asc")
    ->get();

// Join databases
$orders = $db->query("orders")
    ->where(["status" => "completed"])
    ->join("users", "user_id", "id")
    ->get();
// Each order now has a "users" field with the joined user data

// Group by with having
$categories = $db->query("products")
    ->groupBy("category")
    ->having("count", ">", 10)
    ->having("avg:price", ">", 100)
    ->get();

// Select specific fields only
$users = $db->query("users")
    ->select(["name", "email", "avatar"])
    ->limit(50)
    ->get();

// Exclude sensitive fields
$users = $db->query("users")
    ->except(["password", "token", "secret_key"])
    ->get();

// Aggregation
$avgSalary = $db->query("employees")
    ->where(["department" => "Engineering"])
    ->avg("salary");

// Existence check
if ($db->query("users")->where(["email" => $email])->exists()) {
    echo "Email already registered!";
}

// Update with chain
$db->query("users")
    ->where(["status" => "pending"])
    ->whereIn("created_at", $oldDates)
    ->update(["status" => "expired"]);

// Delete with chain
$db->query("logs")
    ->where(["level" => "debug"])
    ->notBetween("created_at", $startDate, $endDate)
    ->delete();

// Remove fields permanently
$db->query("users")
    ->where(["status" => "deleted"])
    ->removeFields(["personal_data", "payment_info"]);

// Pagination
$page = 2;
$perPage = 20;
$users = $db->query("users")
    ->sort("created_at", "desc")
    ->limit($perPage)
    ->skip(($page - 1) * $perPage)
    ->get();
```

---

## Auto-Sharding

noneDB automatically partitions large databases into smaller shards for better performance. When a database reaches the threshold (default: 10,000 records), it's automatically split into multiple shard files.

### How It Works

```
Without Sharding (50K records):
├── hash-users.nonedb          # 5 MB, entire file read for filter operations
├── hash-users.nonedb.jidx     # Index file for O(1) key lookups

With Sharding (50K records, 5 shards):
├── hash-users.nonedb.meta     # Shard metadata
├── hash-users_s0.nonedb       # Shard 0: records 0-9,999
├── hash-users_s0.nonedb.jidx  # Shard 0 index
├── hash-users_s1.nonedb       # Shard 1: records 10,000-19,999
├── hash-users_s1.nonedb.jidx  # Shard 1 index
├── ...
└── hash-users_s4.nonedb       # Shard 4: records 40,000-49,999
```

### Performance Characteristics (50K Records, 5 Shards)

| Operation | Cold (first access) | Warm (cached) | Notes |
|-----------|---------------------|---------------|-------|
| **find(key)** | ~66 ms | **~0.05 ms** | O(1) byte-offset lookup |
| **find(filter)** | ~219 ms | ~200 ms | Scans all shards |
| **update** | ~148 ms | ~140 ms | Only modifies target shard |
| **insert** | ~704 ms | - | Distributes across shards |

> **Key Benefit:** With O(1) byte-offset indexing, key lookups are near-instant after cache warm-up. Filter operations scan all shards but each shard file is smaller.

### Sharding API

#### getShardInfo($dbname)

Get sharding information for a database.

```php
$info = $db->getShardInfo("users");
// Returns:
// [
//     "sharded" => true,
//     "shards" => 5,
//     "totalRecords" => 500000,
//     "deletedCount" => 150,
//     "shardSize" => 100000,
//     "nextKey" => 500150
// ]

// For non-sharded database:
// ["sharded" => false, "shards" => 0, "totalRecords" => 50000, "shardSize" => 100000]
```

#### compact($dbname)

Remove deleted records and reclaim space. Works for both sharded and non-sharded databases.

```php
$result = $db->compact("users");

// Sharded database:
// [
//     "success" => true,
//     "freedSlots" => 1500,
//     "newShardCount" => 48,
//     "sharded" => true
// ]

// Non-sharded database:
// [
//     "success" => true,
//     "freedSlots" => 50,
//     "totalRecords" => 950,
//     "sharded" => false
// ]

// Error cases:
// ["success" => false, "status" => "database_not_found"]
// ["success" => false, "status" => "read_error"]
```

> **Note:** Auto-compaction runs automatically when deleted records exceed 30% of total. Manual compaction is optional but can be used to immediately reclaim disk space.

#### migrate($dbname)

Manually trigger migration to sharded format (normally happens automatically).

```php
$result = $db->migrate("users");
// Returns:
// ["success" => true, "status" => "migrated"]           - Successfully migrated
// ["success" => true, "status" => "already_sharded"]    - Already sharded, no action taken
// ["success" => false, "status" => "database_not_found"] - Database doesn't exist
// ["success" => false, "status" => "migration_failed"]   - Migration error
```

#### isShardingEnabled() / getShardSize()

Check current sharding configuration.

```php
$db->isShardingEnabled();  // Returns: true
$db->getShardSize();       // Returns: 10000
```

### Configuration Options

```php
// Disable sharding entirely
private $shardingEnabled = false;

// Change shard size (records per shard)
private $shardSize = 10000;  // Default: 10K records per shard

// Disable auto-migration (manual control)
private $autoMigrate = false;
```

### When to Use Sharding

| Dataset Size | Recommendation |
|--------------|----------------|
| < 10K records | Sharding unnecessary |
| 10K - 500K | **Auto-sharding enabled (default)** |
| > 500K | Works well, tested up to 500K records |

### Sharding Limitations

- Filter-based queries still scan all shards
- Slightly slower for bulk inserts (writes to multiple files)
- More files to manage in the database directory
- Backup requires copying all shard files

---

## JSONL Storage Engine

noneDB v3.0 introduces a **pure JSONL storage format** with byte-offset indexing for O(1) key lookups. This replaces the previous JSON array format.

### Storage Format

**Database file (JSONL):** `hash-dbname.nonedb`
```
{"key":0,"name":"John","email":"john@example.com"}
{"key":1,"name":"Jane","email":"jane@example.com"}
{"key":2,"name":"Bob","email":"bob@example.com"}
```

**Index file:** `hash-dbname.nonedb.jidx`
```json
{
  "v": 3,
  "format": "jsonl",
  "n": 3,
  "d": 0,
  "o": {
    "0": [0, 52],
    "1": [53, 52],
    "2": [106, 50]
  }
}
```

| Index Field | Description |
|-------------|-------------|
| `v` | Index version (3) |
| `format` | Storage format ("jsonl") |
| `n` | Next key counter |
| `d` | Dirty count (deleted records pending compaction) |
| `o` | Offset map: `{key: [byteOffset, length]}` |

### Performance Improvements

| Operation | Old (JSON) | New (JSONL) | Improvement |
|-----------|------------|-------------|-------------|
| Find by key | O(n) scan | **O(1) lookup** | **Instant** |
| Insert | O(n) read+write | **O(1) append** | **Constant time** |
| Update | O(n) read+write | **O(1) in-place** | **Constant time** |
| Delete | O(n) read+write | **O(1) mark** | **Constant time** |

### Auto-Compaction

Deleted records are immediately removed from the index. The data stays in the file until auto-compaction triggers:

- **Trigger:** When dirty records exceed 30% of total
- **Action:** Rewrites file removing stale data, updates all byte offsets
- **Result:** No manual intervention needed

```php
// Manual compaction still available
$result = $db->compact("users");
// ["success" => true, "freedSlots" => 15, "totalRecords" => 100]
```

### Static Cache

Multiple noneDB instances share cache via static properties:

```php
// Instances share index cache - no duplicate disk reads
$db1 = new noneDB();
$db1->find("users", ['key' => 1]); // Loads index, caches statically

$db2 = new noneDB();
$db2->find("users", ['key' => 1]); // Uses cached index - instant!

// Clear cache (useful for testing/benchmarking)
noneDB::clearStaticCache();

// Disable/enable static caching
noneDB::disableStaticCache();
noneDB::enableStaticCache();
```

### Migration from v2.x

Automatic migration occurs on first database access:
1. Old format detected (`{"data": [...]}`)
2. Records converted to JSONL (one per line)
3. Byte-offset index created (`.jidx` file)
4. Original file overwritten with JSONL content

**No manual intervention required.**

---

## Error Handling

Operations return error information when they fail:

```php
$result = $db->insert("users", "invalid");
// Returns: ["n" => 0, "error" => "insert data must be array"]

$result = $db->insert("users", ["key" => "value"]);
// Returns: ["n" => 0, "error" => "You cannot set key name to key"]

$result = $db->update("users", "invalid");
// Returns: ["n" => 0, "error" => "Please check your update parameters"]
```

---

## Performance Benchmarks

Tested on PHP 8.2, macOS (Apple Silicon M-series) - **v3.0 JSONL Storage Engine**

**Test data structure (7 fields per record):**
```php
[
    "name" => "User123",
    "email" => "user123@test.com",
    "age" => 25,
    "salary" => 8500,
    "city" => "Istanbul",
    "department" => "IT",
    "active" => true
]
```

### v3.0 Optimizations

| Optimization | Improvement |
|--------------|-------------|
| **Static Cache Sharing** | 80%+ for multi-instance |
| **Batch File Read** | 40-50% for bulk reads |
| **Batch Update/Delete** | **25-30x faster** for bulk operations |
| **Single-Pass Filtering** | 30% for complex queries |
| **O(1) Sharded Key Lookup** | True O(1) for all database sizes |
| **O(1) Count** | **100-330x faster** (index metadata lookup) |
| **Hash Cache Persistence** | Faster cold startup |
| **atomicReadFast()** | Optimized index reads |

### O(1) Key Lookup (Warmed Cache)

| Records | Cold | Warm | Notes |
|---------|------|------|-------|
| 100 | 3 ms | 0.03 ms | Non-sharded |
| 1K | 3 ms | 0.03 ms | Non-sharded |
| 10K | 49 ms | 0.03 ms | Sharded (1 shard) |
| 50K | 243 ms | 0.05 ms | Sharded (5 shards) |
| 100K | 497 ms | 0.05 ms | Sharded (10 shards) |
| 500K | 2.5 s | 0.16 ms | Sharded (50 shards) |

> **Key lookups are O(1)** - constant time regardless of database size after cache warm-up!

### Write Operations
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| insert() | 7 ms | 25 ms | 289 ms | 1.5 s | 3.1 s | 16.5 s |
| update() | 1 ms | 11 ms | 120 ms | 660 ms | 1.5 s | 11.3 s |
| delete() | 2 ms | 13 ms | 144 ms | 773 ms | 1.7 s | 12.5 s |

> Note: Update/delete use batch operations for efficient bulk modifications (single index write per shard)

### Read Operations
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| find(all) | 3 ms | 23 ms | 48 ms | 268 ms | 602 ms | 2.7 s |
| find(key) | <1 ms | <1 ms | 49 ms | 243 ms | 497 ms | 2.5 s |
| find(filter) | <1 ms | 4 ms | 50 ms | 252 ms | 515 ms | 2.6 s |

> **find(key)** first call includes index loading. Subsequent calls: ~0.05ms (see O(1) table above)

### Query & Aggregation
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| count() | **<1 ms** | **<1 ms** | **<1 ms** | **<1 ms** | **<1 ms** | **<1 ms** |
| distinct() | <1 ms | 4 ms | 49 ms | 270 ms | 590 ms | 2.9 s |
| sum() | <1 ms | 4 ms | 49 ms | 261 ms | 588 ms | 3 s |
| like() | <1 ms | 5 ms | 57 ms | 311 ms | 670 ms | 3.4 s |
| between() | <1 ms | 4 ms | 53 ms | 288 ms | 628 ms | 3.2 s |
| sort() | <1 ms | 8 ms | 105 ms | 565 ms | 1.3 s | 7.1 s |
| first() | <1 ms | 4 ms | 50 ms | 285 ms | 589 ms | 2.9 s |
| exists() | <1 ms | 4 ms | 49 ms | 272 ms | 588 ms | 3 s |

> **count()** now uses O(1) index metadata lookup - no record scanning required!

### Method Chaining
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| whereIn() | <1 ms | 4 ms | 53 ms | 302 ms | 657 ms | 3.6 s |
| orWhere() | <1 ms | 4 ms | 55 ms | 316 ms | 673 ms | 3.5 s |
| search() | <1 ms | 5 ms | 61 ms | 350 ms | 762 ms | 4.2 s |
| groupBy() | <1 ms | 4 ms | 52 ms | 307 ms | 657 ms | 3.5 s |
| select() | <1 ms | 5 ms | 57 ms | 400 ms | 854 ms | 4.5 s |
| complex chain | <1 ms | 5 ms | 60 ms | 322 ms | 684 ms | 3.6 s |

> **Complex chain:** `where() + whereIn() + between() + select() + sort() + limit()`

### Storage
| Records | File Size | Peak Memory |
|---------|-----------|-------------|
| 100 | 10 KB | 2 MB |
| 1,000 | 98 KB | 4 MB |
| 10,000 | 1 MB | 8 MB |
| 50,000 | 5 MB | 34 MB |
| 100,000 | 10 MB | 134 MB |
| 500,000 | 50 MB | ~600 MB |

---

## SleekDB vs noneDB Comparison

### Why Choose noneDB?

noneDB v3.0 excels in **bulk operations** and **large datasets**:

| Strength | Performance |
|----------|-------------|
| 🚀 **Bulk Insert** | **8-10x faster** than SleekDB |
| 🔍 **Find All** | **8-66x faster** at scale |
| 🎯 **Filter Queries** | **20-80x faster** at scale |
| ✏️ **Update Operations** | **15-40x faster** on large datasets |
| 🗑️ **Delete Operations** | **5-23x faster** on large datasets |
| 📊 **Count Operations** | **90-330x faster** (O(1) index lookup) |
| 🔗 **Complex Queries** | **22-70x faster** at scale |
| 📦 **Large Datasets** | Handles 500K+ records with auto-sharding |
| 🔒 **Thread Safety** | Atomic file locking for concurrent access |
| ⚡ **Static Cache** | Cross-instance cache sharing |

**Best for:** Bulk operations, analytics, batch processing, filter-heavy workloads, count operations

### When to Consider SleekDB?

| Scenario | SleekDB Advantage |
|----------|-------------------|
| 🎯 **High-frequency key lookups** | <1ms vs ~500ms cold (file-per-record architecture) |
| 💾 **Very low memory** | Lower RAM usage |

> **Note:** SleekDB stores each record as a separate file, making single-record lookups instant but bulk operations slow.
>
> **Update v3.0:** noneDB's count() is now **90-330x faster** than SleekDB using O(1) index metadata lookup!

---

### Architectural Differences

| Feature | SleekDB | noneDB |
|---------|---------|--------|
| **Storage** | One JSON file per record | JSONL + byte-offset index |
| **ID Access** | Direct file read (O(1)) | Index lookup + seek |
| **Bulk Read** | Traverse all files | Single file read |
| **Sharding** | None | Automatic (10K+) |
| **Cache** | Per-query | Static cross-instance |
| **Indexing** | None | Byte-offset (.jidx) |

---

### Benchmark Results (v3.0)

#### Bulk Insert
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 7ms | 24ms | **noneDB 3x** |
| 1K | 26ms | 250ms | **noneDB 10x** |
| 10K | 306ms | 2.89s | **noneDB 9x** |
| 50K | 1.59s | 12.4s | **noneDB 8x** |
| 100K | 3.34s | 30.76s | **noneDB 9x** |

#### Find All Records
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 3ms | 28ms | **noneDB 8x** |
| 1K | 7ms | 286ms | **noneDB 42x** |
| 10K | 65ms | 2.71s | **noneDB 42x** |
| 50K | 300ms | 16.83s | **noneDB 56x** |
| 100K | 595ms | 39.03s | **noneDB 66x** |

#### Find by Key (Single Record - Cold)
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 3ms | <1ms | SleekDB |
| 1K | 3ms | <1ms | SleekDB |
| 10K | 55ms | <1ms | **SleekDB** |
| 50K | 287ms | <1ms | **SleekDB** |
| 100K | 561ms | <1ms | **SleekDB** |

> **Note:** SleekDB's file-per-record design gives O(1) key lookup. noneDB must load shard index first (but subsequent lookups are O(1) with cache - see warmed cache table above).

#### Find with Filter
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | <1ms | 10ms | **noneDB 24x** |
| 1K | 4ms | 94ms | **noneDB 25x** |
| 10K | 49ms | 998ms | **noneDB 20x** |
| 50K | 254ms | 13.18s | **noneDB 52x** |
| 100K | 524ms | 41.64s | **noneDB 79x** |

#### Count Operations
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | <1ms | <1ms | **noneDB 4x** |
| 1K | <1ms | 1ms | **noneDB 11x** |
| 10K | <1ms | 9ms | **noneDB 90x** |
| 50K | <1ms | 51ms | **noneDB 330x** |
| 100K | <1ms | 96ms | **noneDB 258x** |

> **v3.0 Optimization:** noneDB now uses O(1) index metadata lookup for count() - no record scanning!

#### Update Operations
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 1ms | 20ms | **noneDB 15x** |
| 1K | 11ms | 188ms | **noneDB 17x** |
| 10K | 118ms | 2.14s | **noneDB 18x** |
| 50K | 669ms | 20.91s | **noneDB 31x** |
| 100K | 1.53s | 61.27s | **noneDB 40x** |

#### Delete Operations
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 2ms | 10ms | **noneDB 5x** |
| 1K | 15ms | 105ms | **noneDB 7x** |
| 10K | 150ms | 1.27s | **noneDB 8x** |
| 50K | 839ms | 14.61s | **noneDB 17x** |
| 100K | 1.75s | 40.01s | **noneDB 23x** |

#### Complex Query (where + sort + limit)
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | <1ms | 12ms | **noneDB 27x** |
| 1K | 4ms | 114ms | **noneDB 30x** |
| 10K | 55ms | 1.2s | **noneDB 22x** |
| 50K | 295ms | 15.33s | **noneDB 52x** |
| 100K | 591ms | 41.3s | **noneDB 70x** |

---

### Summary (v3.0)

| Use Case | Winner | Advantage |
|----------|--------|-----------|
| **Bulk Insert** | **noneDB** | 3-10x faster |
| **Find All** | **noneDB** | 8-66x faster |
| **Find with Filter** | **noneDB** | 20-79x faster |
| **Update** | **noneDB** | 15-40x faster |
| **Delete** | **noneDB** | 5-23x faster |
| **Complex Query** | **noneDB** | 22-70x faster |
| **Count** | **noneDB** | 4-330x faster (O(1) index lookup) |
| **Find by Key (cold)** | **SleekDB** | O(1) file access |

> **Choose noneDB** for: Bulk operations, large datasets, filter queries, update/delete workloads, complex queries, count operations
>
> **Choose SleekDB** for: High-frequency single-record lookups by ID (cold cache scenarios)

---

## Concurrent Access & Atomic Operations

noneDB v2.2 implements **professional-grade atomic file locking** using `flock()` to ensure thread-safe concurrent access:

```
┌─────────────────────────────────────────────────────────────────┐
│  Process A                      │  Process B                   │
├─────────────────────────────────────────────────────────────────┤
│  1. atomicModify() called       │                               │
│  2. flock(LOCK_EX) acquired     │  3. atomicModify() called     │
│  4. Read data                   │  5. flock() waits...          │
│  6. Modify data                 │     (blocked)                 │
│  7. Write data                  │                               │
│  8. flock(LOCK_UN) released     │                               │
│                                 │  9. flock(LOCK_EX) acquired   │
│                                 │  10. Read data (sees A's changes) │
│                                 │  11. Modify & Write           │
│                                 │  12. flock(LOCK_UN) released  │
└─────────────────────────────────────────────────────────────────┘
```

### Atomic Operations Guarantee
- **No lost updates** - All concurrent writes are serialized
- **Read consistency** - Reads wait for ongoing writes to complete
- **Crash safety** - Uses `flock()` which is automatically released on process termination

### Tested Scenarios
| Scenario | Result |
|----------|--------|
| 2 processes × 100 inserts | **200/200** records (100% success) |
| 5 processes × 50 inserts | **250/250** records (100% success) |
| Repeated stress tests | **0% data loss** across all runs |

---

## Warnings & Limitations

### Security
- **Change `$secretKey`** before deploying to production
- **Protect `db/` directory** from direct web access
- Sanitize user input before using as database names
- Database names are sanitized to `[A-Za-z0-9' -]` only

### Performance Considerations
- Optimized for datasets up to 100,000 records per shard
- **With sharding:** Tested up to 500,000 records with excellent performance
- Filter-based queries scan all shards (linear complexity)
- Primary key index system for faster key lookups
- For full-table scans on 500K+ records, expect 6-8 second response times

### Data Integrity
- No transactions support (each operation is atomic individually)
- No foreign key constraints
- **Concurrent writes are fully atomic** - no race conditions
- **Auto-compaction** - deleted records are automatically cleaned up when threshold reached

### Character Encoding
- Database names: Only `A-Z`, `a-z`, `0-9`, space, hyphen, apostrophe allowed
- Other characters in names are silently removed
- Data content: Full UTF-8 support

---

## Database Name Sanitization

Database names are sanitized automatically:

```php
$db->insert("my_database!", ["data" => "test"]);
// Actually creates/uses "mydatabase" (underscore and ! removed)

$db->insert("test-db", ["data" => "test"]);  // OK - hyphen allowed
$db->insert("test db", ["data" => "test"]);  // OK - space allowed
$db->insert("test'db", ["data" => "test"]);  // OK - apostrophe allowed
```

---

## File Structure

### Standard Database (< 10K records)
```
project/
├── noneDB.php
└── db/
    ├── a1b2c3...-users.nonedb        # Database file (JSONL)
    ├── a1b2c3...-users.nonedb.jidx   # Byte-offset index
    ├── a1b2c3...-users.nonedbinfo    # Metadata (creation time)
    ├── d4e5f6...-posts.nonedb
    ├── d4e5f6...-posts.nonedb.jidx
    └── d4e5f6...-posts.nonedbinfo
```

### Sharded Database (10K+ records)
```
project/
├── noneDB.php
└── db/
    ├── a1b2c3...-users.nonedb.meta   # Shard metadata
    ├── a1b2c3...-users_s0.nonedb     # Shard 0 data (JSONL)
    ├── a1b2c3...-users_s0.nonedb.jidx # Shard 0 index
    ├── a1b2c3...-users_s1.nonedb     # Shard 1 data (JSONL)
    ├── a1b2c3...-users_s1.nonedb.jidx # Shard 1 index
    ├── a1b2c3...-users_s2.nonedb     # Shard 2 data (JSONL)
    ├── a1b2c3...-users_s2.nonedb.jidx # Shard 2 index
    └── a1b2c3...-users.nonedbinfo    # Creation time
```

Database file format (JSONL - one record per line):
```
{"key":0,"name":"John","email":"john@example.com"}
{"key":1,"name":"Jane","email":"jane@example.com"}
{"key":2,"name":"Bob","email":"bob@example.com"}
```

Index file format (`.jidx`):
```json
{
    "v": 3,
    "format": "jsonl",
    "n": 3,
    "d": 0,
    "o": {"0": [0, 52], "1": [53, 52], "2": [106, 50]}
}
```

Shard metadata format (`.meta` file):
```json
{
    "version": 1,
    "shardSize": 10000,
    "totalRecords": 25000,
    "deletedCount": 150,
    "nextKey": 25150,
    "shards": [
        {"id": 0, "file": "_s0", "count": 9850, "deleted": 150},
        {"id": 1, "file": "_s1", "count": 10000, "deleted": 0},
        {"id": 2, "file": "_s2", "count": 5000, "deleted": 0}
    ]
}
```

---

## Testing

```bash
# Install dependencies
composer install

# Run tests
vendor/bin/phpunit

# Run with details
vendor/bin/phpunit --testdox
```

---

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## Roadmap

- [x] `distinct()` - Get unique values
- [x] `sort()` - Sort results
- [x] `count()` - Count records
- [x] `like()` - Pattern matching search
- [x] `sum()` / `avg()` - Aggregation functions
- [x] `min()` / `max()` - Min/Max values
- [x] `first()` / `last()` - First/Last record
- [x] `exists()` - Check if records exist
- [x] `between()` - Range queries
- [x] **Auto-sharding** - Horizontal partitioning for large datasets
- [x] `orWhere()` - OR condition queries
- [x] `whereIn()` / `whereNotIn()` - Array membership filters
- [x] `whereNot()` - Negation filters
- [x] `notLike()` / `notBetween()` - Negated pattern and range filters
- [x] `search()` - Full-text search
- [x] `join()` - Database joins
- [x] `groupBy()` / `having()` - Grouping and aggregate filtering
- [x] `select()` / `except()` - Field projection
- [x] `removeFields()` - Permanent field removal
- [x] **JSONL Storage Engine** - O(1) key lookups with byte-offset indexing (v3.0)
- [x] **Static Cache Sharing** - Cross-instance cache for 80%+ improvement (v3.0)
- [x] **Auto-Compaction** - Automatic cleanup when deleted > 30% (v3.0)
- [x] **Batch File Read** - 40-50% faster bulk reads (v3.0)
- [x] **Single-Pass Filtering** - 30% faster complex queries (v3.0)

---

## License

MIT License - see [LICENSE](LICENSE) file.

---

## Author

**Orhan Aydogdu**
- Website: [orhanaydogdu.com.tr](https://orhanaydogdu.com.tr)
- Email: info@orhanaydogdu.com.tr
- GitHub: [@orhanayd](https://github.com/orhanayd)

---

**Free Software, Hell Yeah!**

---

> "Hayatta en hakiki mürşit ilimdir."
>
> "The truest guide in life is science."
>
> — **Mustafa Kemal Atatürk**
