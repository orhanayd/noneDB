# noneDB

[![Version](https://img.shields.io/badge/version-3.0.0-orange.svg)](CHANGES.md)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-723%20passed-brightgreen.svg)](tests/)
[![Thread Safe](https://img.shields.io/badge/thread--safe-atomic%20locking-success.svg)](#concurrent-access--atomic-operations)

**noneDB** is a lightweight, file-based NoSQL database for PHP. No installation required - just include and go!

## Features

- **Zero dependencies** - single PHP file (~4500 lines)
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
| **find(key)** | ~70 ms | **~7 ms** | O(1) byte-offset lookup |
| **find(filter)** | ~65 ms | ~60 ms | Scans all shards |
| **update** | ~160 ms | ~150 ms | Only modifies target shard |
| **insert** | ~590 ms | - | Distributes across shards |

> **Key Benefit:** With O(1) byte-offset indexing, key lookups are fast. Warm cache eliminates index reload overhead. Filter operations scan all shards but each shard file is smaller.

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
// ["ok" => true, "freedSlots" => 15, "totalRecords" => 100]
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
// Returns: ["n" => 0, "error" => "Please check your update paramters"]
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
| **Single-Pass Filtering** | 30% for complex queries |
| **Early Exit** | Variable (limit without sort) |

### O(1) Key Lookup (Warmed Cache)

| Records | Cold | Warm | Notes |
|---------|------|------|-------|
| 100 | <1 ms | 0.04 ms | Non-sharded |
| 1K | <1 ms | 0.03 ms | Non-sharded |
| 10K | 55 ms | ~0.05 ms | Sharded (1 shard) |
| 50K | 48 ms | ~0.05 ms | Sharded (5 shards) |
| 100K | 81 ms | ~0.05 ms | Sharded (10 shards) |
| 500K | 383 ms | ~0.05 ms | Sharded (50 shards) |

> **Key lookups are O(1)** - constant time regardless of database size after cache warm-up!

### Write Operations
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| insert() | 5 ms | 14 ms | 132 ms | 723 ms | 1.8 s | 9 s |
| update() | 4 ms | 73 ms | 29 ms | 150 ms | 362 ms | 1.7 s |
| delete() | 4 ms | 66 ms | 28 ms | 149 ms | 418 ms | 1.6 s |

> Note: 10K+ triggers sharding, making update/delete faster than 1K (smaller shard files)

### Read Operations
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| find(all) | 2 ms | 12 ms | 41 ms | 258 ms | 568 ms | 2.5 s |
| find(key) | <1 ms | <1 ms | 57 ms | 222 ms | 443 ms | 2.2 s |
| find(filter) | <1 ms | 7 ms | 43 ms | 216 ms | 463 ms | 2.5 s |

> **find(key)** first call includes index loading. Subsequent calls: ~0.05ms (see O(1) table above)

### Query & Aggregation
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| count() | <1 ms | 7 ms | 40 ms | 282 ms | 602 ms | 2.5 s |
| distinct() | <1 ms | 7 ms | 44 ms | 307 ms | 578 ms | 3.1 s |
| sum() | <1 ms | 7 ms | 44 ms | 230 ms | 586 ms | 2.8 s |
| like() | <1 ms | 9 ms | 60 ms | 310 ms | 733 ms | 3.7 s |
| between() | <1 ms | 8 ms | 54 ms | 284 ms | 677 ms | 3.3 s |
| sort() | 2 ms | 16 ms | 147 ms | 862 ms | 2.2 s | 11.8 s |
| first() | <1 ms | 7 ms | 45 ms | 245 ms | 590 ms | 2.7 s |
| exists() | <1 ms | 7 ms | 45 ms | 258 ms | 611 ms | 2.8 s |

### Method Chaining
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| whereIn() | <1 ms | 8 ms | 55 ms | 305 ms | 778 ms | 4.3 s |
| orWhere() | 1 ms | 8 ms | 56 ms | 331 ms | 716 ms | 4.5 s |
| search() | 4 ms | 9 ms | 70 ms | 392 ms | 828 ms | 5.1 s |
| groupBy() | <1 ms | 7 ms | 50 ms | 313 ms | 695 ms | 4.6 s |
| select() | 2 ms | 8 ms | 74 ms | 544 ms | 1.2 s | 5.6 s |
| complex chain | 1 ms | 9 ms | 62 ms | 381 ms | 759 ms | 4 s |

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
| 🚀 **Bulk Insert** | **17x faster** than SleekDB |
| 🔍 **Find All** | **58x faster** at scale |
| 🎯 **Filter Queries** | **58x faster** at scale |
| ✏️ **Update Operations** | **73x faster** on large datasets |
| 🗑️ **Delete Operations** | **47x faster** on large datasets |
| 📦 **Large Datasets** | Handles 500K+ records with auto-sharding |
| 🔒 **Thread Safety** | Atomic file locking for concurrent access |
| ⚡ **Static Cache** | Cross-instance cache sharing |

**Best for:** Bulk operations, analytics, batch processing, filter-heavy workloads

### When to Consider SleekDB?

| Scenario | SleekDB Advantage |
|----------|-------------------|
| 🎯 **High-frequency key lookups** | <1ms vs ~100ms (file-per-record architecture) |
| 📊 **Count operations** | 6x faster (uses file count) |
| 💾 **Very low memory** | Lower RAM usage |

> **Note:** SleekDB stores each record as a separate file, making single-record lookups instant but bulk operations slow.

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
| 100 | 5ms | 20ms | **noneDB 4x** |
| 1K | 17ms | 166ms | **noneDB 10x** |
| 10K | 133ms | 1.76s | **noneDB 13x** |
| 50K | 696ms | 12.07s | **noneDB 17x** |
| 100K | 1.52s | 26.26s | **noneDB 17x** |

#### Find All Records
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 3ms | 6ms | **noneDB 2x** |
| 1K | 7ms | 33ms | **noneDB 5x** |
| 10K | 23ms | 359ms | **noneDB 15x** |
| 50K | 107ms | 1.98s | **noneDB 19x** |
| 100K | 244ms | 14.08s | **noneDB 58x** |

#### Find by Key (Single Record)
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 3ms | <1ms | SleekDB |
| 1K | 3ms | <1ms | SleekDB |
| 10K | 43ms | <1ms | **SleekDB** |
| 50K | 131ms | <1ms | **SleekDB** |
| 100K | 249ms | <1ms | **SleekDB** |

> **Note:** SleekDB's file-per-record design gives O(1) key lookup. noneDB must load shard index first.

#### Find with Filter
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | <1ms | 4ms | **noneDB 11x** |
| 1K | 4ms | 35ms | **noneDB 9x** |
| 10K | 23ms | 373ms | **noneDB 16x** |
| 50K | 120ms | 2.06s | **noneDB 17x** |
| 100K | 252ms | 14.51s | **noneDB 58x** |

#### Update Operations
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 4ms | 7ms | **noneDB 2x** |
| 1K | 73ms | 65ms | ~Tie |
| 10K | 30ms | 762ms | **noneDB 25x** |
| 50K | 144ms | 4.63s | **noneDB 32x** |
| 100K | 294ms | 21.44s | **noneDB 73x** |

#### Delete Operations
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 4ms | 5ms | ~Tie |
| 1K | 68ms | 49ms | SleekDB 1.4x |
| 10K | 31ms | 525ms | **noneDB 17x** |
| 50K | 162ms | 3.59s | **noneDB 22x** |
| 100K | 343ms | 16.07s | **noneDB 47x** |

#### Complex Query (where + sort + limit)
| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | <1ms | 21ms | **noneDB 49x** |
| 1K | 4ms | 37ms | **noneDB 10x** |
| 10K | 26ms | 403ms | **noneDB 15x** |
| 50K | 155ms | 2.07s | **noneDB 13x** |
| 100K | 421ms | 14.76s | **noneDB 35x** |

---

### Summary (v3.0)

| Use Case | Winner | Advantage |
|----------|--------|-----------|
| **Bulk Insert** | **noneDB** | 10-17x faster |
| **Find All** | **noneDB** | 15-58x faster |
| **Find with Filter** | **noneDB** | 16-58x faster |
| **Update** | **noneDB** | 25-73x faster |
| **Delete** | **noneDB** | 17-47x faster |
| **Complex Query** | **noneDB** | 13-49x faster |
| **Find by Key** | **SleekDB** | O(1) file access |
| **Count** | **SleekDB** | ~6x faster |

> **Choose noneDB** for: Bulk operations, large datasets, filter queries, update/delete workloads, complex queries
>
> **Choose SleekDB** for: High-frequency single-record lookups by ID, count-heavy operations

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
