# noneDB

[![Version](https://img.shields.io/badge/version-3.1.0-orange.svg)](CHANGES.md)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-970%20passed-brightgreen.svg)](tests/)
[![Thread Safe](https://img.shields.io/badge/thread--safe-atomic%20locking-success.svg)](#concurrent-access)

**noneDB** is a lightweight, file-based NoSQL database for PHP. No installation required - just include and go!

## Features

- **Zero dependencies** - single PHP file
- **No database server required** - just include and use
- **O(1) key lookups** - JSONL storage with byte-offset indexing
- **Spatial indexing** - R-tree for geospatial queries (v3.1)
- **MongoDB-style operators** - `$gt`, `$gte`, `$lt`, `$lte`, `$ne`, `$in`, `$nin`, `$exists`, `$like`, `$regex`, `$contains` (v3.1)
- **Auto-sharding** for large datasets (500K+ tested)
- **Thread-safe** - atomic file locking for concurrent access
- **Method chaining** - fluent query builder interface

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

## Quick Start

```php
<?php
include("noneDB.php");
$db = new noneDB();

// Insert
$db->insert("users", ["name" => "John", "age" => 25, "email" => "john@example.com"]);

// Find
$users = $db->find("users", ["name" => "John"]);

// Query Builder with Operators
$results = $db->query("users")
    ->where([
        'age' => ['$gte' => 18, '$lte' => 65],
        'status' => 'active'
    ])
    ->sort('age', 'desc')
    ->limit(10)
    ->get();

// Update
$db->update("users", [
    ["name" => "John"],
    ["set" => ["email" => "john.doe@example.com"]]
]);

// Delete
$db->delete("users", ["name" => "John"]);
```

---

## Configuration

Create a `.nonedb` file in your project root:

```json
{
    "secretKey": "YOUR_SECURE_RANDOM_STRING",
    "dbDir": "./db/",
    "autoCreateDB": true
}
```

**Or programmatically:**

```php
$db = new noneDB([
    'secretKey' => 'your_secure_key',
    'dbDir' => '/path/to/db/'
]);
```

**Development mode (no config required):**

```php
noneDB::setDevMode(true);
$db = new noneDB();
```

> See [docs/CONFIGURATION.md](docs/CONFIGURATION.md) for all options.

---

## Query Builder

```php
// Comparison operators
$db->query("products")
    ->where([
        'price' => ['$gte' => 100, '$lte' => 500],
        'category' => ['$in' => ['electronics', 'gadgets']],
        'stock' => ['$gt' => 0]
    ])
    ->sort('rating', 'desc')
    ->limit(20)
    ->get();

// Pattern matching
$db->query("users")
    ->where(['email' => ['$like' => 'gmail.com$']])
    ->get();

// Existence check
$db->query("users")
    ->where(['phone' => ['$exists' => true]])
    ->get();
```

### Available Operators

| Operator | Description | Example |
|----------|-------------|---------|
| `$gt` | Greater than | `['age' => ['$gt' => 18]]` |
| `$gte` | Greater than or equal | `['price' => ['$gte' => 100]]` |
| `$lt` | Less than | `['stock' => ['$lt' => 10]]` |
| `$lte` | Less than or equal | `['rating' => ['$lte' => 5]]` |
| `$ne` | Not equal | `['role' => ['$ne' => 'guest']]` |
| `$in` | In array | `['category' => ['$in' => ['a', 'b']]]` |
| `$nin` | Not in array | `['tag' => ['$nin' => ['spam']]]` |
| `$exists` | Field exists | `['email' => ['$exists' => true]]` |
| `$like` | Pattern match | `['name' => ['$like' => '^John']]` |
| `$regex` | Regex match | `['email' => ['$regex' => '@gmail']]` |
| `$contains` | Array/string contains | `['tags' => ['$contains' => 'featured']]` |

> See [docs/QUERY.md](docs/QUERY.md) for complete query reference.

---

## Spatial Queries

```php
// Create spatial index
$db->createSpatialIndex("restaurants", "location");

// Insert GeoJSON data
$db->insert("restaurants", [
    'name' => 'Ottoman Kitchen',
    'location' => ['type' => 'Point', 'coordinates' => [28.9784, 41.0082]]
]);

// Find within radius
$nearby = $db->query("restaurants")
    ->withinDistance('location', 28.9784, 41.0082, 5000)  // 5000 meters (5km)
    ->where(['open_now' => true])
    ->get();

// Find nearest K
$closest = $db->nearest("restaurants", "location", 28.9784, 41.0082, 10);

// Find in bounding box
$inArea = $db->withinBBox("restaurants", "location", 28.97, 41.00, 29.00, 41.03);
```

> See [docs/SPATIAL.md](docs/SPATIAL.md) for complete spatial reference.

---

## Documentation

| Document | Description |
|----------|-------------|
| [docs/QUERY.md](docs/QUERY.md) | Query builder, operators, filters |
| [docs/SPATIAL.md](docs/SPATIAL.md) | Geospatial indexing and queries |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | Configuration options |
| [docs/API.md](docs/API.md) | Complete API reference |

---

## Performance

| Operation | 10K Records | 100K Records |
|-----------|-------------|--------------|
| find(key) | **0.03 ms** | **0.05 ms** |
| find(filter) | 50 ms | 520 ms |
| insert (batch) | 290 ms | 3.1 s |
| count() | **< 1 ms** | **< 1 ms** |
| withinDistance | 10-20 ms | 50-100 ms |

> Benchmarks on Apple Silicon. Key lookups are O(1) with byte-offset indexing.

---

## Testing

```bash
# Run all tests
composer test

# Run with details
vendor/bin/phpunit --testdox

# Run specific suite
vendor/bin/phpunit --testsuite Feature
```

---

## Concurrent Access

noneDB uses atomic file locking (`flock()`) for thread-safe operations:

- **No lost updates** - concurrent writes are serialized
- **Read consistency** - reads wait for ongoing writes
- **Crash safety** - locks auto-release on process termination

---

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## License

MIT License - see [LICENSE](LICENSE) file.

---

## Author

**Orhan Aydogdu**
- Website: [orhanaydogdu.com.tr](https://orhanaydogdu.com.tr)
- GitHub: [@orhanayd](https://github.com/orhanayd)

---

> "Hayatta en hakiki mürşit ilimdir."
>
> "The truest guide in life is science."
>
> — **Mustafa Kemal Atatürk**
