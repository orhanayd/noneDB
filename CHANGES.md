# noneDB Changelog

## v3.0.0 (2025-12-28)

### Major: Pure JSONL Storage Engine + Maximum Performance Optimizations

This release introduces a **pure JSONL storage format** with O(1) key-based lookups, plus PHP-only performance optimizations for maximum speed without requiring any extensions.

> **BREAKING CHANGE:** V2 format (`{"data": [...]}`) is no longer supported. Existing databases will be automatically migrated to JSONL format on first access.

---

### Part 1: JSONL Storage Engine

#### Storage Format Changes

```
Before v3 (V2 Format):
┌─────────────────────────────────────────┐
│ hash-dbname.nonedb                      │
│ {"data": [{"name":"John"}, null, ...]}  │
└─────────────────────────────────────────┘

After v3 (JSONL Format):
┌─────────────────────────────────────────┐
│ hash-dbname.nonedb                      │
│ {"key":0,"name":"John"}                 │
│ {"key":1,"name":"Jane"}                 │
│ ...                                     │
├─────────────────────────────────────────┤
│ hash-dbname.nonedb.jidx                 │
│ {"v":3,"n":2,"d":0,"o":{"0":[0,26],...}}│
└─────────────────────────────────────────┘
```

#### Index File Structure (.jidx)

```json
{
  "v": 3,
  "format": "jsonl",
  "created": 1735344000,
  "n": 100,
  "d": 5,
  "o": {
    "0": [0, 45],
    "1": [46, 52]
  }
}
```

| Field | Description |
|-------|-------------|
| `v` | Index version (3) |
| `format` | Storage format ("jsonl") |
| `created` | Creation timestamp |
| `n` | Next key counter |
| `d` | Dirty count (deleted records pending compaction) |
| `o` | Offset map: `{key: [byteOffset, length]}` |

#### Algorithmic Improvements

| Operation | V2 Format | V3 JSONL |
|-----------|-----------|----------|
| Find by key | O(n) scan | **O(1) lookup** |
| Insert | O(n) read+write | **O(1) append** |
| Update | O(n) read+write | **O(1) in-place** |
| Delete | O(n) read+write | **O(1) mark** |

#### Delete Behavior Change

**Before (V2):** Deleted records became `null` placeholders in the array, requiring `compact()` to reclaim space.

**After (V3):** Deleted records are immediately removed from the index. The record data remains in the file until auto-compaction triggers (when dirty > 30% of total records).

```php
// Old behavior (v2)
$db->delete("users", ["id" => 5]);
// Data: [rec0, rec1, null, rec3, ...]  // null placeholder

// New behavior (v3)
$db->delete("users", ["id" => 5]);
// Data file unchanged, index entry removed
// find() returns no result for deleted record
```

#### Auto-Compaction

JSONL format includes automatic compaction:
- Triggers when dirty records exceed 30% of total
- Rewrites file removing stale data
- Updates all byte offsets in index
- No manual intervention needed

```php
// Manual compaction still available
$result = $db->compact("users");
// ["ok" => true, "freedSlots" => 15, "totalRecords" => 100]
```

#### Sharding JSONL Support

Sharded databases now use JSONL format for each shard:
```
hash-dbname_s0.nonedb       # Shard 0 data (JSONL)
hash-dbname_s0.nonedb.jidx  # Shard 0 index
hash-dbname_s1.nonedb       # Shard 1 data (JSONL)
hash-dbname_s1.nonedb.jidx  # Shard 1 index
hash-dbname.nonedb.meta     # Shard metadata
```

---

### Part 2: Performance Optimizations

#### Static Cache Sharing

Multiple noneDB instances now share cache data via static properties:

```php
// Before: Each instance had separate cache
$db1 = new noneDB();
$db1->find("users", ['key' => 1]); // Loads index
$db2 = new noneDB();
$db2->find("users", ['key' => 1]); // Loads index AGAIN

// After: Instances share cache
$db1 = new noneDB();
$db1->find("users", ['key' => 1]); // Loads index, caches statically
$db2 = new noneDB();
$db2->find("users", ['key' => 1]); // Uses cached index - instant!
```

**New Static Cache Methods:**
```php
noneDB::clearStaticCache();      // Clear all static caches
noneDB::disableStaticCache();    // Disable static caching
noneDB::enableStaticCache();     // Re-enable static caching
```

**Improvement:** 80%+ faster for multi-instance scenarios

#### Batch File Read

Sequential disk reads are now batched with 64KB buffering:

```php
// Before: Each record = separate fseek + fread
// 1000 records = 1000 disk operations

// After: Sorted offsets + 64KB buffer
// 1000 records = ~16 disk operations (64KB chunks)
```

**Improvement:** 40-50% faster for bulk read operations

#### Single-Pass Filtering

Query builder now uses single-pass filtering instead of multiple `array_filter` calls:

```php
// Before: 8 separate array_filter passes
$results = array_filter($records, whereNot);
$results = array_filter($results, whereIn);
$results = array_filter($results, whereNotIn);
// ... 5 more passes

// After: Single loop with combined predicate
foreach ($records as $record) {
    if ($this->matchesAdvancedFilters($record)) {
        $filtered[] = $record;
    }
}
```

**Improvement:** 30% faster for complex queries

#### Early Exit Optimization

Queries with `limit()` (without `sort()`) now exit early:

```php
// Before: Always process ALL records
$db->query("users")->where(['active' => true])->limit(10)->get();
// Processes 100K records, returns 10

// After: Exit as soon as limit reached
$db->query("users")->where(['active' => true])->limit(10)->get();
// Processes until 10 matches found, exits early
```

**Improvement:** Variable, up to 90%+ faster for limit queries on large datasets

#### O(1) Count via Index Metadata

```php
// Before: count() loaded ALL records into memory
$db->count("users");  // 100K records = 536ms (full scan)

// After: count() uses index metadata directly
$db->count("users");  // 100K records = <1ms (O(1) lookup)
```

**How it works:**
- Non-sharded: `count(index['o'])` - offset map entry count
- Sharded: `meta['totalRecords']` - metadata value

**Improvement:** 100-330x faster for count operations

#### Hash Cache Persistence

PBKDF2 hash computations are now persisted to disk:

```php
// Before: Cold start = 10-50ms per database (1000 PBKDF2 iterations)
// After: Cold start = <1ms (loaded from .nonedb_hash_cache file)
```

**File:** `db/.nonedb_hash_cache` (JSON format)

#### atomicReadFast() for Index Reads

Optimized read path for index files:

```php
// Before: atomicRead() with clearstatcache() + retry loop
// After: atomicReadFast() - direct blocking lock, no retry overhead
```

**Improvement:** 2-5ms faster per index read

---

### Performance Results

| Operation | v2.x | v3.0 | Improvement |
|-----------|------|------|-------------|
| insert 50K | 1.3s | 704ms | **2x faster** |
| insert 100K | 2.8s | 1.6s | **1.8x faster** |
| find(all) 100K | 1.1s | 554ms | **2x faster** |
| find(filter) 100K | 854ms | 434ms | **2x faster** |
| update 100K | 1.1s | 367ms | **3x faster** |

### SleekDB Comparison (100K Records)

| Operation | noneDB | SleekDB | Winner |
|-----------|--------|---------|--------|
| Bulk Insert | 3.34s | 30.76s | **noneDB 9x** |
| Find All | 595ms | 39.03s | **noneDB 66x** |
| Find Filter | 524ms | 41.64s | **noneDB 79x** |
| Update | 1.53s | 61.27s | **noneDB 40x** |
| Delete | 1.75s | 40.01s | **noneDB 23x** |
| Complex Query | 591ms | 41.3s | **noneDB 70x** |
| Count | **<1ms** | 96ms | **noneDB 258x** |
| Find by Key (cold) | 561ms | <1ms | SleekDB |

> **Note:** noneDB now wins **7 out of 8** operations. Count uses O(1) index metadata lookup.

---

### Part 3: Configuration File System

#### External Config File (.nonedb)

Configuration is now stored in an external JSON file instead of hardcoded in `noneDB.php`:

```json
{
    "secretKey": "YOUR_SECURE_RANDOM_STRING",
    "dbDir": "./db/",
    "autoCreateDB": true,
    "shardingEnabled": true,
    "shardSize": 10000,
    "autoMigrate": true,
    "autoCompactThreshold": 0.3,
    "lockTimeout": 5,
    "lockRetryDelay": 10000
}
```

**Benefits:**
- Secrets stay out of source code
- Easier upgrades (just replace `noneDB.php`)
- Different configs for different environments
- `.nonedb` can be gitignored

#### Configuration Methods

```php
// Method 1: Config file (recommended)
// Place .nonedb in project root or parent directories
$db = new noneDB();

// Method 2: Programmatic config
$db = new noneDB([
    'secretKey' => 'your_key',
    'dbDir' => './db/'
]);

// Method 3: Dev mode (skips config requirement)
noneDB::setDevMode(true);
$db = new noneDB();
```

#### Dev Mode

For development without a config file:

```php
// Option 1: Environment variable
putenv('NONEDB_DEV_MODE=1');

// Option 2: Constant
define('NONEDB_DEV_MODE', true);

// Option 3: Static method
noneDB::setDevMode(true);
```

#### New Static Methods

```php
noneDB::configExists();       // Check if config file exists
noneDB::getConfigTemplate();  // Get config template array
noneDB::clearConfigCache();   // Clear cached config
noneDB::setDevMode(true);     // Enable dev mode
```

### Breaking Changes

1. **V2 format no longer supported** - Databases are auto-migrated on first access
2. **Delete no longer creates null placeholders** - Records removed from index immediately
3. **Index file (.jidx) required** - Each database/shard needs its index file
4. **compact() behavior changed** - Now rewrites JSONL file, not JSON array
5. **Config file or programmatic config required** - Use `.nonedb` file, constructor config array, or enable dev mode

### Migration

Automatic migration occurs on first database access:
1. V2 format detected (`{"data": [...]}`)
2. Records converted to JSONL (one per line)
3. Byte-offset index created (`.jidx` file)
4. Original file overwritten with JSONL content

**No manual intervention required.**

### Test Results

- **774 tests, 2157 assertions** (all passing)
- Full sharding support verified
- Concurrency tests updated for JSONL behavior
- Count fast-path tests added
- Configuration system tests added (15 tests)

---

## v2.3.0 (2025-12-28)

### Major: Write Buffer System + Performance Caching + Index System

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
   - Buffer reaches 1MB size limit
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
private $bufferSizeLimit = 1048576;      // 1MB buffer size
private $bufferCountLimit = 10000;       // Max records per buffer
private $bufferFlushInterval = 30;       // Auto-flush every 30 seconds
private $bufferAutoFlushOnShutdown = true;
private $shardSize = 100000;             // 100K records per shard
```

#### New Public API

```php
// Manual flush
$db->flush("users");              // Flush specific database
$db->flushAllBuffers();           // Flush all databases

// Buffer info
$info = $db->getBufferInfo("users");
// ['enabled' => true, 'sizeLimit' => 1048576, 'buffers' => [...]]

// Configuration
$db->enableBuffering(true);       // Enable/disable
$db->setBufferSizeLimit(1048576); // Set to 1MB
$db->setBufferFlushInterval(60);  // Set to 60 seconds
$db->setBufferCountLimit(5000);   // Set to 5000 records
$db->isBufferingEnabled();        // Check if enabled
```

---

### Performance Caching System

#### Hash Caching
PBKDF2 hash computation is now cached per instance:
```php
// Before: 1000 iterations per call (~0.5-1ms each)
// After: Computed once, cached for subsequent calls
```

#### Meta Caching with TTL
Metadata is cached with a 1-second TTL to reduce file reads:
```php
$meta = $this->getCachedMeta($dbname);  // Uses cache if valid
```

---

### Primary Key Index System

New index file provides O(1) key existence checks:
```
hash-dbname.nonedb.idx
```

```json
{
    "version": 1,
    "totalRecords": 100000,
    "sharded": true,
    "entries": {
        "0": [0, 0],
        "10000": [1, 0]
    }
}
```

#### Index Public API

```php
$db->enableIndexing(true);        // Enable/disable indexing
$db->isIndexingEnabled();         // Check if enabled
$db->rebuildIndex("users");       // Rebuild index for database
$db->getIndexInfo("users");       // Get index statistics
```

#### How Index Works

1. **Auto-build**: Index is built on first key-based lookup
2. **Auto-update**: Index updated on insert/delete operations
3. **Auto-rebuild**: Index rebuilt after compact() operation
4. **Graceful fallback**: If index is corrupted, falls back to full scan

#### Breaking Changes

None. All existing APIs work without modification.

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
