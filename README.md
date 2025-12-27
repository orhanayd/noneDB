# noneDB

[![Version](https://img.shields.io/badge/version-2.0.0-orange.svg)](CHANGES.md)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-448%20passed-brightgreen.svg)](tests/)

**noneDB** is a lightweight, file-based NoSQL database for PHP. No installation required - just include and go!

## Features

- Zero dependencies - single PHP file
- No database server required
- JSON-based storage
- CRUD operations (Create, Read, Update, Delete)
- Auto-create databases
- Secure hashed file names
- File locking for concurrent access
- **Method chaining** (fluent interface) for clean queries
- **Auto-sharding** for large datasets (100K+ records)

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

## Configuration

> **IMPORTANT: Change these settings before production use!**

Edit `noneDB.php`:

```php
private $dbDir = __DIR__."/db/";      // Database directory path
private $secretKey = "nonedb_123";     // Secret key for hashing - CHANGE THIS!
private $autoCreateDB = true;          // Auto-create databases on first use

// Sharding configuration
private $shardingEnabled = true;       // Enable auto-sharding for large datasets
private $shardSize = 10000;            // Records per shard (default: 10,000)
private $autoMigrate = true;           // Auto-migrate when threshold reached
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

| Method | Description | Example |
|--------|-------------|---------|
| `where($filters)` | Filter by field values | `->where(["active" => true])` |
| `like($field, $pattern)` | Pattern matching (^start, end$) | `->like("email", "gmail")` |
| `between($field, $min, $max)` | Range filter | `->between("age", 18, 65)` |
| `sort($field, $order)` | Sort results | `->sort("created_at", "desc")` |
| `limit($count)` | Limit results | `->limit(10)` |
| `offset($count)` | Skip results (pagination) | `->offset(20)` |

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
| `update($set)` | `array` | Update matches |
| `delete()` | `array` | Delete matches |

### Examples

```php
// Complex query
$topUsers = $db->query("users")
    ->where(["active" => true])
    ->between("age", 18, 35)
    ->like("email", "gmail.com$")
    ->sort("score", "desc")
    ->limit(10)
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
    ->update(["status" => "active"]);

// Delete with chain
$db->query("logs")
    ->where(["level" => "debug"])
    ->delete();

// Pagination
$page = 2;
$perPage = 20;
$users = $db->query("users")
    ->sort("created_at", "desc")
    ->limit($perPage)
    ->offset(($page - 1) * $perPage)
    ->get();
```

---

## Auto-Sharding

noneDB automatically partitions large databases into smaller shards for better performance. When a database reaches the threshold (default: 10,000 records), it's automatically split into multiple shard files.

### How It Works

```
Without Sharding (500K records):
├── hash-users.nonedb          # 50 MB, entire file read for every operation

With Sharding (500K records, 50 shards):
├── hash-users.nonedb.meta     # Shard metadata
├── hash-users_s0.nonedb       # Shard 0: records 0-9,999
├── hash-users_s1.nonedb       # Shard 1: records 10,000-19,999
├── ...
└── hash-users_s49.nonedb      # Shard 49: records 490,000-499,999
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
//     "shards" => 50,
//     "totalRecords" => 500000,
//     "deletedCount" => 150,
//     "shardSize" => 10000,
//     "nextKey" => 500150
// ]

// For non-sharded database:
// ["sharded" => false, "shards" => 0, "totalRecords" => 5000, "shardSize" => 10000]
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
$db->getShardSize();       // Returns: 10000
```

### Configuration Options

```php
// Disable sharding entirely
private $shardingEnabled = false;

// Change shard size (records per shard)
private $shardSize = 5000;  // Smaller shards = faster single-record ops, more files

// Disable auto-migration (manual control)
private $autoMigrate = false;
```

### When to Use Sharding

| Dataset Size | Recommendation |
|--------------|----------------|
| < 10K records | Sharding unnecessary |
| 10K - 100K | Sharding beneficial for key-based lookups |
| 100K - 500K | **Sharding recommended** |
| > 500K | Consider a dedicated database server |

### Sharding Limitations

- Filter-based queries still scan all shards
- Slightly slower for bulk inserts (writes to multiple files)
- More files to manage in the database directory
- Backup requires copying all shard files

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

Tested on PHP 8.2, macOS (Apple Silicon)

**Test data structure (6 fields per record):**
```php
[
    "name" => "User123",
    "email" => "user123@test.com",
    "age" => 25,
    "salary" => 8500,
    "city" => "Istanbul",
    "active" => true
]
```

### Write Operations
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| insert() | 10 ms | 10 ms | 19 ms | 70 ms | 139 ms | 706 ms |
| update() | 12 ms | 15 ms | 48 ms | 203 ms | 392 ms | 1.9 s |
| delete() | 12 ms | 16 ms | 48 ms | 199 ms | 401 ms | 1.9 s |

### Read Operations
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| find(all) | 6 ms | 7 ms | 27 ms | 115 ms | 226 ms | 1.2 s |
| find(key) | 6 ms | 7 ms | 21 ms | 80 ms | 159 ms | 772 ms |
| find(filter) | 6 ms | 8 ms | 24 ms | 104 ms | 192 ms | 971 ms |

### Query & Aggregation
| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| count() | 6 ms | 8 ms | 24 ms | 100 ms | 228 ms | 1.2 s |
| distinct() | 6 ms | 8 ms | 26 ms | 111 ms | 228 ms | 1.4 s |
| sum() | 6 ms | 8 ms | 26 ms | 112 ms | 222 ms | 1.5 s |
| like() | 6 ms | 8 ms | 27 ms | 113 ms | 229 ms | 1.4 s |
| between() | 6 ms | 8 ms | 26 ms | 115 ms | 216 ms | 1.4 s |
| sort() | <1 ms | 4 ms | 51 ms | 331 ms | 719 ms | 4.6 s |
| first() | 6 ms | 8 ms | 25 ms | 123 ms | 237 ms | 1.2 s |
| exists() | 6 ms | 8 ms | 26 ms | 103 ms | 204 ms | 991 ms |

### Storage
| Records | File Size | Peak Memory |
|---------|-----------|-------------|
| 100 | 10 KB | 0.7 MB |
| 1,000 | 98 KB | 2.7 MB |
| 10,000 | 1 MB | 23 MB |
| 50,000 | 5 MB | 110 MB |
| 100,000 | 10 MB | 220 MB |
| 500,000 | 50 MB | 1.1 GB |

---

## Warnings & Limitations

### Security
- **Change `$secretKey`** before deploying to production
- **Protect `db/` directory** from direct web access
- Sanitize user input before using as database names
- Database names are sanitized to `[A-Za-z0-9' -]` only

### Performance
- Optimized for datasets up to 10,000 records per shard
- **With sharding:** Usable up to 500,000 records with good performance for key-based lookups
- Without sharding: Not recommended for 100K+ records
- Filter-based queries scan all shards (or entire file)
- No indexing support (use key-based lookups for best performance)

### Data Integrity
- No transactions support
- No foreign key constraints
- Concurrent writes use file locking but race conditions possible
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

### Standard Database (< 10K records)
```
project/
├── noneDB.php
└── db/
    ├── a1b2c3...-users.nonedb       # Database file (JSON)
    ├── a1b2c3...-users.nonedbinfo   # Metadata (creation time)
    ├── d4e5f6...-posts.nonedb
    └── d4e5f6...-posts.nonedbinfo
```

### Sharded Database (10K+ records)
```
project/
├── noneDB.php
└── db/
    ├── a1b2c3...-users.nonedb.meta  # Shard metadata
    ├── a1b2c3...-users_s0.nonedb    # Shard 0
    ├── a1b2c3...-users_s1.nonedb    # Shard 1
    ├── a1b2c3...-users_s2.nonedb    # Shard 2
    └── a1b2c3...-users.nonedbinfo   # Creation time
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
