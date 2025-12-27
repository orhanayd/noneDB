# noneDB

[![Version](https://img.shields.io/badge/version-2.3.0-orange.svg)](CHANGES.md)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-723%20passed-brightgreen.svg)](tests/)
[![Thread Safe](https://img.shields.io/badge/thread--safe-atomic%20locking-success.svg)](#concurrent-access--atomic-operations)

**noneDB** is a lightweight, file-based NoSQL database for PHP. No installation required - just include and go!

## Features

- **Zero dependencies** - single PHP file (~2500 lines)
- **No database server required** - just include and use
- **JSON-based storage** with PBKDF2-hashed filenames
- **Atomic file locking** - thread-safe concurrent operations
- **Write buffer system** - fast append-only inserts
- **Primary key index** - O(1) key existence checks
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
private $shardSize = 100000;           // Records per shard (default: 100K)
private $autoMigrate = true;           // Auto-migrate when threshold reached

// Write buffer configuration (v2.3.0+)
private $bufferEnabled = true;         // Enable write buffer for fast inserts
private $bufferSizeLimit = 1048576;    // Buffer size limit (1MB default)
private $bufferCountLimit = 10000;     // Max records per buffer
private $bufferFlushInterval = 30;     // Auto-flush interval in seconds
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

> **Note:** Deleted records are set to `null` internally but filtered from `find()` results.

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

noneDB automatically partitions large databases into smaller shards for better performance. When a database reaches the threshold (default: 100,000 records), it's automatically split into multiple shard files.

### How It Works

```
Without Sharding (500K records):
├── hash-users.nonedb          # 50 MB, entire file read for every operation

With Sharding (500K records, 5 shards):
├── hash-users.nonedb.meta     # Shard metadata
├── hash-users_s0.nonedb       # Shard 0: records 0-99,999
├── hash-users_s1.nonedb       # Shard 1: records 100,000-199,999
├── ...
└── hash-users_s4.nonedb       # Shard 4: records 400,000-499,999
```

### Performance Comparison (500K Records)

| Operation | Without Sharding | With Sharding | Improvement |
|-----------|------------------|---------------|-------------|
| **find(key)** | 772 ms | **16 ms** | **~50x faster** |
| RAM per key lookup | 1.1 GB | **~1 MB** | **~1000x less** |
| find(all) | 1.2 s | 1.18 s | Similar |
| insert | 706 ms | 1.53 s | Slightly slower |

> **Key Benefit:** Single-record operations (login, profile view, etc.) only read one shard instead of the entire database.

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

> **Recommendation:** We strongly recommend running `compact()` periodically (e.g., via cron job) on databases with frequent delete operations. Deleted records leave `null` entries in the data file, which waste disk space and slightly slow down read operations. Regular compaction keeps your database healthy and performant.

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
$db->getShardSize();       // Returns: 100000
```

### Configuration Options

```php
// Disable sharding entirely
private $shardingEnabled = false;

// Change shard size (records per shard)
private $shardSize = 100000;  // Default: 100K records per shard

// Disable auto-migration (manual control)
private $autoMigrate = false;
```

### When to Use Sharding

| Dataset Size | Recommendation |
|--------------|----------------|
| < 100K records | Sharding unnecessary |
| 100K - 500K | **Sharding recommended** |
| > 500K | Consider a dedicated database server |

### Sharding Limitations

- Filter-based queries still scan all shards
- Slightly slower for bulk inserts (writes to multiple files)
- More files to manage in the database directory
- Backup requires copying all shard files

---

## Write Buffer System

noneDB v2.3 introduces a **write buffer system** for dramatically faster insert operations. Instead of reading and writing the entire database file for each insert, records are buffered and flushed in batches.

### The Problem (Before v2.3)

Every insert required reading and writing the ENTIRE database file:

```
100K records (~10MB) → Each insert: Read 10MB → Decode → Append → Encode → Write 10MB
1000 inserts on 100K DB = ~500 seconds (8+ minutes!)
```

### The Solution

```
┌─────────────────────────────────────────────────────────────────┐
│  Before v2.3: Full File Read/Write Per Insert                  │
├─────────────────────────────────────────────────────────────────┤
│  insert() → read entire DB → append 1 record → write entire DB │
│  Time per insert: O(n) where n = total records                 │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│  After v2.3: Append-Only Buffer                                 │
├─────────────────────────────────────────────────────────────────┤
│  insert() → append to buffer file (no read!)                    │
│  When buffer full → flush to main DB                            │
│  Time per insert: O(1) constant time!                          │
└─────────────────────────────────────────────────────────────────┘
```

### Performance Improvement

**Non-sharded database (single file):**
| Scenario | Without Buffer | With Buffer | Speedup |
|----------|----------------|-------------|---------|
| Insert on 100K DB | 101 ms/insert | 8.5 ms/insert | **12x** |
| 1000 inserts (100K DB) | ~100 sec | ~8.5 sec | **12x** |

> **Note:** When sharding is enabled (default), each shard is already small (~1MB), so the buffer advantage is less pronounced. The buffer provides the most benefit for non-sharded databases or individual large shards.

### How It Works

1. **Inserts go to buffer file** (JSONL format - one JSON per line)
2. **No full-file read** required for each insert
3. **Auto-flush when:**
   - Buffer reaches 1MB size limit
   - 30 seconds pass since last flush
   - Graceful shutdown occurs (shutdown handler)
4. **Read operations flush first** (flush-before-read for consistency)

### Buffer File Format

```
hash-dbname.nonedb           # Main database
hash-dbname.nonedb.buffer    # Write buffer (JSONL)
```

For sharded databases, each shard has its own buffer:
```
hash-dbname_s0.nonedb.buffer  # Shard 0 buffer
hash-dbname_s1.nonedb.buffer  # Shard 1 buffer
```

### Buffer API

#### flush($dbname)

Manually flush buffer to main database.

```php
$result = $db->flush("users");
// Returns: ["success" => true, "flushed" => 150]
```

#### flushAllBuffers()

Flush all database buffers.

```php
$db->flushAllBuffers();
```

#### getBufferInfo($dbname)

Get buffer status and statistics.

```php
$info = $db->getBufferInfo("users");
// Returns:
// [
//     "enabled" => true,
//     "sizeLimit" => 1048576,
//     "countLimit" => 10000,
//     "flushInterval" => 30,
//     "buffers" => [
//         "main" => ["size" => 15360, "records" => 150]
//     ]
// ]
```

#### enableBuffering($enable)

Enable or disable write buffering.

```php
$db->enableBuffering(true);   // Enable
$db->enableBuffering(false);  // Disable (direct writes)
```

#### isBufferingEnabled()

Check if buffering is enabled.

```php
if ($db->isBufferingEnabled()) {
    echo "Buffer is active";
}
```

#### setBufferSizeLimit($bytes)

Set buffer size threshold for auto-flush.

```php
$db->setBufferSizeLimit(1048576);  // 1MB
```

#### setBufferFlushInterval($seconds)

Set time interval for auto-flush.

```php
$db->setBufferFlushInterval(60);  // Flush every 60 seconds
```

#### setBufferCountLimit($count)

Set maximum records per buffer.

```php
$db->setBufferCountLimit(5000);  // Flush after 5000 records
```

### Transparency

The buffer system is **fully transparent** - existing code works without modification:

```php
// This code works identically before and after v2.3
$db->insert("users", ["name" => "John"]);
$users = $db->find("users", []);  // Buffer auto-flushed before read
```

### When Buffer Flushes Automatically

| Trigger | Description |
|---------|-------------|
| Size limit | Buffer reaches 1MB (configurable) |
| Record count | Buffer has 10,000 records (configurable) |
| Time interval | 30 seconds since last flush (configurable) |
| Read operation | Any `find()`, `count()`, etc. flushes first |
| Write operation | `update()` and `delete()` flush first |
| Shutdown | PHP shutdown handler flushes all buffers |

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

Tested on PHP 8.2, macOS (Apple Silicon M-series)

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

### Write Operations
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| insert() | 7 ms | 28 ms | 99 ms | 408 ms | 743 ms | 4.1 s |
| update() | 1 ms | 13 ms | 147 ms | 832 ms | 1.8 s | 9.5 s |
| delete() | 1 ms | 13 ms | 132 ms | 728 ms | 2 s | 9.4 s |

### Read Operations
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| find(all) | 3 ms | 25 ms | 134 ms | 743 ms | 2 s | 8.2 s |
| find(key) | 3 ms | 29 ms | 138 ms | 612 ms | 1.6 s | 6.5 s |
| find(filter) | 1 ms | 11 ms | 126 ms | 629 ms | 1.6 s | 6.6 s |

### Query & Aggregation
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| count() | 1 ms | 11 ms | 130 ms | 668 ms | 1.7 s | 7.9 s |
| distinct() | 1 ms | 12 ms | 130 ms | 839 ms | 2.2 s | 9.8 s |
| sum() | 1 ms | 13 ms | 130 ms | 866 ms | 2.1 s | 9.8 s |
| like() | 2 ms | 16 ms | 161 ms | 1 s | 2.4 s | 11.5 s |
| between() | 1 ms | 14 ms | 143 ms | 906 ms | 2.1 s | 11 s |
| sort() | 5 ms | 36 ms | 451 ms | 3 s | 7.1 s | 40.1 s |
| first() | 1 ms | 11 ms | 168 ms | 760 ms | 1.6 s | 8.4 s |
| exists() | 1 ms | 12 ms | 140 ms | 770 ms | 1.7 s | 8.7 s |

### Method Chaining (v2.1+)
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| whereIn() | 1 ms | 13 ms | 154 ms | 866 ms | 2.6 s | 14.8 s |
| orWhere() | 2 ms | 15 ms | 184 ms | 975 ms | 2.9 s | 15.1 s |
| search() | 2 ms | 15 ms | 190 ms | 1 s | 3.4 s | 15.7 s |
| groupBy() | 1 ms | 13 ms | 165 ms | 939 ms | 2.5 s | 16.8 s |
| select() | 2 ms | 17 ms | 276 ms | 1.6 s | 3.4 s | 20.7 s |
| complex chain | 1 ms | 15 ms | 188 ms | 1 s | 2.5 s | 14 s |

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

noneDB excels in **bulk operations** and **large dataset handling**:

| Strength | Performance |
|----------|-------------|
| 🚀 **Bulk Insert** | **20-25x faster** than SleekDB |
| 🔍 **Find All / Filters** | **56-68x faster** at scale |
| ✏️ **Update Operations** | **56x faster** on large datasets |
| 🗑️ **Delete Operations** | **48x faster** on large datasets |
| 📦 **Large Datasets** | Handles 500K+ records with auto-sharding |
| 🔒 **Thread Safety** | Atomic file locking for concurrent access |
| ⚡ **Write Buffer** | Append-only inserts, no full-file rewrites |

**Best for:** E-commerce catalogs, log aggregation, analytics, batch processing, data migrations, reporting systems

### When to Consider SleekDB?

SleekDB has advantages in **specific scenarios**:

| Scenario | SleekDB Advantage |
|----------|-------------------|
| 🎯 **Frequent ID lookups** | <1ms vs 400ms (when you need thousands of single-record lookups per second) |
| 💾 **Very low memory** | 8x less RAM (embedded systems, shared hosting with strict limits) |

**Consider SleekDB only if:** Your primary workload is high-frequency single-record ID lookups (e.g., 1000+ lookups/sec) AND memory is severely constrained.

> **Note:** For most applications, noneDB's 400ms ID lookup is acceptable, and you gain 20-60x performance on all other operations.

---

*Detailed benchmark comparisons below.*

---

### Detailed Comparison

Performance comparison with [SleekDB](https://github.com/SleekDB/SleekDB) v2.15 (PHP flat-file database).

### Architectural Differences

| Feature | SleekDB | noneDB |
|---------|---------|--------|
| **Storage** | One JSON file per record | Single file (sharded) |
| **ID Access** | Direct file read | Shard lookup |
| **Bulk Read** | Traverse all files | Single decode |
| **Sharding** | None | Automatic (100K+) |
| **Cache** | Built-in | Hash/Meta cache |
| **Buffer** | None | Write buffer |
| **Indexing** | None | Primary key index |

### Benchmark Results (100K Records)

#### Bulk Insert
| Records | SleekDB | noneDB | Winner |
|---------|---------|--------|--------|
| 100 | 20 ms | 5 ms | **noneDB 4x** |
| 1K | 162 ms | 12 ms | **noneDB 14x** |
| 10K | 1.88 s | 86 ms | **noneDB 22x** |
| 50K | 12.84 s | 517 ms | **noneDB 25x** |
| 100K | 25.67 s | 1.26 s | **noneDB 20x** |

#### Find All Records
| Records | SleekDB | noneDB | Winner |
|---------|---------|--------|--------|
| 100 | 5 ms | <1 ms | **noneDB 5x** |
| 1K | 32 ms | 2 ms | **noneDB 16x** |
| 10K | 347 ms | 22 ms | **noneDB 16x** |
| 50K | 7.41 s | 109 ms | **noneDB 68x** |
| 100K | 14.15 s | 251 ms | **noneDB 56x** |

#### Find by ID/Key
| Records | SleekDB | noneDB | Winner |
|---------|---------|--------|--------|
| 100 | <1 ms | <1 ms | Tie |
| 1K | <1 ms | 6 ms | **SleekDB** |
| 10K | <1 ms | 58 ms | **SleekDB** |
| 50K | <1 ms | 289 ms | **SleekDB** |
| 100K | <1 ms | 405 ms | **SleekDB** |

#### Sequential Insert (100 records on existing DB)
| Records | SleekDB | noneDB (buffer) | Winner |
|---------|---------|-----------------|--------|
| 100 | 25 ms | 13 ms | **noneDB 2x** |
| 1K | 22 ms | 15 ms | **noneDB 1.5x** |
| 10K | 24 ms | 39 ms | SleekDB 1.6x |
| 50K | 36 ms | 141 ms | SleekDB 4x |
| 100K | 36 ms | 22 ms | **noneDB 1.6x** |

#### Update & Delete (100K Records)
| Operation | SleekDB | noneDB | Winner |
|-----------|---------|--------|--------|
| Update | 17.44 s | 309 ms | **noneDB 56x** |
| Delete | 15.57 s | 325 ms | **noneDB 48x** |
| Count | 37 ms | 222 ms | SleekDB 6x |

#### Memory Usage (Bulk Insert)
| Records | SleekDB | noneDB | Winner |
|---------|---------|--------|--------|
| 10K | 4 MB | 8 MB | SleekDB 2x |
| 50K | 18 MB | 34 MB | SleekDB 2x |
| 100K | 16 MB | 134 MB | **SleekDB 8x** |

### Summary

| Use Case | Winner | Advantage |
|----------|--------|-----------|
| **Bulk Insert** | **noneDB** | 20-25x faster |
| **Find All** | **noneDB** | 56x faster |
| **Update/Delete** | **noneDB** | 48-56x faster |
| **Filter Queries** | **noneDB** | 61x faster |
| **ID-based lookup** | **SleekDB** | 400x faster |
| **Memory usage** | **SleekDB** | 8x less |

> **Choose noneDB** for: Bulk operations, large datasets, filter queries, update/delete heavy workloads
>
> **Choose SleekDB** for: Frequent single-record access by ID, memory-constrained environments

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
- Deleted records leave `null` entries - run [`compact()`](#compactdbname) periodically to reclaim space

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

### Standard Database (< 100K records)
```
project/
├── noneDB.php
└── db/
    ├── a1b2c3...-users.nonedb        # Database file (JSON)
    ├── a1b2c3...-users.nonedb.buffer # Write buffer (JSONL, v2.3.0+)
    ├── a1b2c3...-users.nonedbinfo    # Metadata (creation time)
    ├── d4e5f6...-posts.nonedb
    └── d4e5f6...-posts.nonedbinfo
```

### Sharded Database (100K+ records)
```
project/
├── noneDB.php
└── db/
    ├── a1b2c3...-users.nonedb.meta   # Shard metadata
    ├── a1b2c3...-users_s0.nonedb     # Shard 0
    ├── a1b2c3...-users_s0.nonedb.buffer  # Shard 0 buffer (v2.3.0+)
    ├── a1b2c3...-users_s1.nonedb     # Shard 1
    ├── a1b2c3...-users_s1.nonedb.buffer  # Shard 1 buffer (v2.3.0+)
    ├── a1b2c3...-users_s2.nonedb     # Shard 2
    └── a1b2c3...-users.nonedbinfo    # Creation time
```

Database file format:
```json
{
    "data": [
        {"name": "John", "email": "john@example.com"},
        {"name": "Jane", "email": "jane@example.com"},
        null
    ]
}
```

Shard metadata format (`.meta` file):
```json
{
    "version": 1,
    "shardSize": 100000,
    "totalRecords": 250000,
    "deletedCount": 150,
    "nextKey": 250150,
    "shards": [
        {"id": 0, "file": "_s0", "count": 99850, "deleted": 150},
        {"id": 1, "file": "_s1", "count": 100000, "deleted": 0},
        {"id": 2, "file": "_s2", "count": 50000, "deleted": 0}
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
- [x] **Write buffer system** - 12x faster inserts on large databases (v2.3.0)
- [x] **Primary key index** - O(1) key existence checks (v2.3.0)
- [x] **Hash/Meta caching** - Reduced PBKDF2 overhead (v2.3.0)

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
