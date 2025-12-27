# noneDB Changelog

## v2.3.0 (2025-12-27)

### Major: Write Buffer System - 12x Faster Inserts

This release implements a **write buffer system** for dramatically faster insert operations on large non-sharded databases.

#### The Problem

Every insert previously required reading and writing the ENTIRE database file:
```
100K records (~10MB) → Each insert: Read 10MB → Decode → Append → Encode → Write 10MB
1000 inserts on 100K DB = ~500 seconds (8+ minutes!)
```

#### The Solution

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

#### How It Works

1. **Inserts go to buffer file** (JSONL format - one JSON per line)
2. **No full-file read** required for each insert
3. **Auto-flush when:**
   - Buffer reaches 2MB size limit
   - 30 seconds pass since last flush
   - Graceful shutdown occurs
4. **Read operations flush first** (flush-before-read strategy)

#### Buffer File Format

```
hash-dbname.nonedb           # Main database
hash-dbname.nonedb.buffer    # Write buffer (JSONL)
```

For sharded databases, each shard has its own buffer:
```
hash-dbname_s0.nonedb.buffer  # Shard 0 buffer
hash-dbname_s1.nonedb.buffer  # Shard 1 buffer
```

#### Configuration

```php
private $bufferEnabled = true;           // Enable/disable buffering
private $bufferSizeLimit = 2097152;      // 2MB buffer size
private $bufferCountLimit = 10000;       // Max records per buffer
private $bufferFlushInterval = 30;       // Auto-flush every 30 seconds
private $bufferAutoFlushOnShutdown = true;
```

#### New Public API

```php
// Manual flush
$db->flush("users");              // Flush specific database
$db->flushAllBuffers();           // Flush all databases

// Buffer info
$info = $db->getBufferInfo("users");
// ['enabled' => true, 'sizeLimit' => 2097152, 'buffers' => [...]]

// Configuration
$db->enableBuffering(true);       // Enable/disable
$db->setBufferSizeLimit(1048576); // Set to 1MB
$db->setBufferFlushInterval(60);  // Set to 60 seconds
$db->setBufferCountLimit(5000);   // Set to 5000 records
$db->isBufferingEnabled();        // Check if enabled
```

#### Breaking Changes

None. Buffer is transparent - existing code works without modification.

---

## v2.2.0 (2025-12-27)

### Major: Atomic File Locking

This release implements **professional-grade atomic file locking** to ensure thread-safe concurrent access. No more lost updates or race conditions!

#### New Core Methods

Three new private methods handle all file operations atomically:

```php
// Atomic read with shared lock (LOCK_SH)
private function atomicRead($path, $default = null)

// Atomic write with exclusive lock (LOCK_EX)
private function atomicWrite($path, $data, $prettyPrint = false)

// Atomic read-modify-write in single locked operation
private function atomicModify($path, callable $modifier, $default = null)
```

#### How It Works

```
┌───────────────────────────────────────────────────────────┐
│  Before v2.2 (Race Condition)                             │
├───────────────────────────────────────────────────────────┤
│  Process A: read → modify → write                         │
│  Process B:    read → modify → write                      │
│  Result: Process A's changes LOST!                        │
└───────────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────────┐
│  After v2.2 (Atomic Operations)                           │
├───────────────────────────────────────────────────────────┤
│  Process A: [LOCK → read → modify → write → UNLOCK]       │
│  Process B:        [wait...] [LOCK → read → modify → ...]│
│  Result: ALL changes preserved!                           │
└───────────────────────────────────────────────────────────┘
```

#### Test Results

| Scenario | Before v2.2 | After v2.2 |
|----------|-------------|------------|
| 2 processes × 100 inserts | 46-199 records (up to 77% loss) | **200/200** (0% loss) |
| 5 processes × 50 inserts | 41 records (83.6% loss!) | **250/250** (0% loss) |

#### Updated Methods

All write operations now use atomic locking:
- `insert()`, `update()`, `delete()`
- `insertSharded()`, `updateSharded()`, `deleteSharded()`
- `readMeta()`, `writeMeta()`, `getShardData()`, `writeShardData()`

#### Configuration

New configuration options for fine-tuning:

```php
private $lockTimeout = 5;        // Max seconds to wait for lock
private $lockRetryDelay = 10000; // Microseconds between retry attempts
```

### Performance Benchmarks Updated

Added 500K record benchmarks. Key highlights:
- `find(key)` stays at **23ms** even at 500K records (thanks to sharding)
- Full table operations scale linearly (~3-5s for 500K records)

---

## v2.1.0 (2025-12-27)

### New Features

#### Advanced Filter Methods

New chainable filter methods added:

```php
// OR condition
$db->query("users")
    ->where(["department" => "IT"])
    ->orWhere(["department" => "Engineering"])
    ->orWhere(["role" => "admin"])
    ->get();

// Array membership
$db->query("users")
    ->whereIn("status", ["active", "pending"])
    ->whereNotIn("role", ["banned", "suspended"])
    ->get();

// Negation filters
$db->query("users")
    ->whereNot(["deleted" => true])
    ->notLike("email", "test")
    ->notBetween("age", 0, 18)
    ->get();
```

**New Methods:**
- `orWhere($filters)` - OR condition filtering
- `whereIn($field, $values)` - Value in array
- `whereNotIn($field, $values)` - Value not in array
- `whereNot($filters)` - Not equal filter
- `notLike($field, $pattern)` - Pattern should not match
- `notBetween($field, $min, $max)` - Value outside range

#### Full-Text Search

Multi-field search support:

```php
$results = $db->query("products")
    ->search("wireless keyboard")
    ->get();

// Search in specific fields
$results = $db->query("users")
    ->search("john", ["name", "email", "bio"])
    ->get();
```

#### Database Joins

Cross-database join support:

```php
$orders = $db->query("orders")
    ->where(["status" => "completed"])
    ->join("users", "user_id", "id")
    ->get();
// Each order now includes related user data
```

#### Grouping & Aggregation

GROUP BY and HAVING support:

```php
$categories = $db->query("products")
    ->groupBy("category")
    ->having("count", ">", 10)
    ->having("avg:price", ">", 100)
    ->get();
```

**Having aggregate types:**
- `count` - Group count
- `sum:field` - Field sum
- `avg:field` - Field average
- `min:field` - Minimum value
- `max:field` - Maximum value

#### Field Projection

Select or exclude fields from results:

```php
// Get only specific fields
$users = $db->query("users")
    ->select(["name", "email", "avatar"])
    ->get();

// Exclude sensitive fields
$users = $db->query("users")
    ->except(["password", "token", "secret_key"])
    ->get();
```

#### removeFields() Terminal Method

Permanently remove fields from matching records:

```php
$result = $db->query("users")
    ->where(["status" => "deleted"])
    ->removeFields(["personal_data", "payment_info"]);
// ["n" => 5, "fields_removed" => ["personal_data", "payment_info"]]
```

**Features:**
- Works with sharded databases
- `key` field is protected (cannot be removed)
- Can be combined with all filter methods

#### Method Aliases

Convenience aliases:

```php
->skip(20)      // Same as offset(20)
->orderBy("name", "asc")  // Same as sort("name", "asc")
```

### Bug Fixes

- **whereIn/whereNotIn null handling**: Fixed null value filtering by using `array_key_exists()` instead of `isset()`
- **join() array/object key handling**: Fixed crash when local or foreign key values are arrays or objects - now gracefully returns null for non-scalar keys

### Improvements

#### Test Suite
- **723 tests, 1916 assertions** (v2.0: 448 tests, 1005 assertions)
- +275 new tests added
- `NewChainingMethodsTest.php` - 100 tests (new methods)
- `NewMethodsEdgeCasesTest.php` - 50 tests (edge cases)
- `NewMethodsShardedTest.php` - 18 tests (sharded DB support)
- `RemoveFieldsTest.php` - 29 tests (removeFields)
- `TypeEdgeCasesTest.php` - 79 tests (type validation & edge cases)

#### Documentation
- `README.md` - All new methods documented
- Method Chaining section organized into 5 categories
- 15+ new code examples added

### Breaking Changes

None. All v2.0.x APIs remain backward compatible.

### Migration Guide

> **CRITICAL:** Before updating, backup your `$secretKey` from the old `noneDB.php`. Restore it after update or you'll lose access to your data!

To upgrade from v2.0.x to v2.1.0:
1. **Backup** your `$secretKey` from current `noneDB.php`
2. Update `noneDB.php` file
3. **Restore** your `$secretKey` in the new file
4. Start using new features (optional)

---

## v2.0.0 (2025-12-27)

### New Features

#### Method Chaining (Fluent Interface)
Query builder pattern with method chaining support.

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

**New noneDBQuery Class:**
- `query($dbname)` - Starts query builder
- Chainable methods: `where()`, `like()`, `between()`, `sort()`, `limit()`, `offset()`
- Terminal methods: `get()`, `first()`, `last()`, `count()`, `exists()`
- Aggregation: `sum()`, `avg()`, `min()`, `max()`, `distinct()`
- Write: `update()`, `delete()`

#### Auto-Sharding
Automatic sharding support for large databases.

```php
// Sharding is auto-enabled (after 10K records)
$db->isShardingEnabled();  // true
$db->getShardSize();       // 10000

// Shard info
$info = $db->getShardInfo("users");
// ["sharded" => true, "shards" => 5, "totalRecords" => 50000]

// Manual operations
$db->migrate("users");     // Migrate to sharded format
$db->compact("users");     // Clean up deleted records
```

### Bug Fixes

- **like() array handling**: `like()` function no longer crashes on array/object field values, safely skips them

### Improvements

#### Test Suite
- **448 tests, 1005 assertions** (v1.4: 0 tests)
- Unit, Feature, Integration test suites
- 67 edge case tests
- 40 chaining tests
- 28 sharding tests

#### Examples
11 example files rewritten/added:
- `basic-usage.php` - Basic CRUD operations
- `filtering.php` - Filtering and search
- `chaining.php` - Method chaining examples
- `aggregation.php` - Aggregation functions
- `query-methods.php` - Query methods
- `utility-methods.php` - Utility functions
- `sharding.php` - Sharding examples
- `database-management.php` - DB management
- `data-types.php` - Data types
- `real-world.php` - Real world scenarios
- `performance.php` - Performance tests

#### Documentation
- `README.md` - Comprehensive documentation

### Breaking Changes

None. All v1.x APIs remain backward compatible.

### Migration Guide

> **CRITICAL:** Before updating, backup your `$secretKey` from the old `noneDB.php`. Restore it after update or you'll lose access to your data!

To upgrade from v1.x to v2.0:
1. **Backup** your `$secretKey` from current `noneDB.php`
2. Update `noneDB.php` file
3. **Restore** your `$secretKey` in the new file
4. Start using new features (optional)

---

## v1.4.0 (2025-12-27)

- Initial release with core CRUD operations
- JSON-based file storage
- PBKDF2-hashed filenames
- Basic query methods (find, like, between, distinct)
- Aggregation functions (sum, avg, min, max, count)
- Utility methods (first, last, exists, sort, limit)

## v1.3.0

- Database management improvements

## v1.2.0

- Performance optimizations

## v1.1.0

- Bug fixes

## v1.0.0

- Initial release
