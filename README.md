# noneDB

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://github.com/orhanayd/noneDB/actions/workflows/tests.yml/badge.svg)](https://github.com/orhanayd/noneDB/actions)

**noneDB** is a lightweight, file-based NoSQL database for PHP. No installation required - just include and go!

## Features

- Zero dependencies - single PHP file
- No database server required
- JSON-based storage
- CRUD operations (Create, Read, Update, Delete)
- Auto-create databases
- Secure hashed file names
- File locking for concurrent access

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
- Optimized for datasets up to 10,000 records
- Usable up to 100,000 records with acceptable latency
- Not recommended for 500K+ records (high memory, slow operations)
- Each operation reads/writes entire file
- No indexing support

### Data Integrity
- No transactions support
- No foreign key constraints
- Concurrent writes use file locking but race conditions possible
- Deleted records leave `null` entries (file doesn't shrink)

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

```
project/
├── noneDB.php
└── db/
    ├── a1b2c3...-users.nonedb       # Database file (JSON)
    ├── a1b2c3...-users.nonedbinfo   # Metadata (creation time)
    ├── d4e5f6...-posts.nonedb
    └── d4e5f6...-posts.nonedbinfo
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
