# noneDB

[![Version](https://img.shields.io/badge/version-4.0.0-blue.svg)](CHANGES.md)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Concurrency](https://img.shields.io/badge/multi--process-safe-success.svg)](#concurrency-and-crashes)

**noneDB** is an embedded document database for PHP: one file, no server, no extension. Include it and store
records in a directory.

## Features

- **One file, no dependencies**: `noneDB.php`, PHP 8.1 or later
- **Records and queries**: insert, find, update, delete; a fluent query builder with MongoDB-style operators,
  sorting, joins, grouping, aggregates and `explain()`
- **Indexes on disk**: key lookups through key-index checkpoints, paged field indexes, a global field index
  per sharded database, R-tree spatial indexes for GeoJSON; a lookup reads only the pages it needs
- **Large databases**: automatic sharding; queries on 10 million records run in PHP's default `memory_limit` (128 MB)
- **Safe under processes and crashes**: database locks across processes, writes that throw are undone, a
  killed process loses no acknowledged write, recovery is deterministic, damaged files are quarantined and
  never silently read
- **Durable mode**: every write synced to stable storage before it returns (Linux)
- **Operations**: online backup and one-commit restore, metrics, integrity checks, key rotation
- **Migration** of databases written by noneDB 1.x to 3.x: a separate tool, `noneDB-migrate.php`

## Requirements

- PHP 8.1 to 8.5 (`json` and `ctype`, on by default; `mbstring` recommended)
- A local file system (ext4, xfs, btrfs, APFS, NTFS). Network file systems and volumes shared by several
  machines are not supported: locks use `flock()`.
- Durable mode: Linux. Performance mode (the default): Linux, macOS, Windows.

## Installation

```bash
composer require orhanayd/nonedb
```

or copy `noneDB.php` into your project:

<!-- doctest: skip -->
```php
require 'noneDB.php';
```

## Quick start

```php
$db = new noneDB(); // reads .nonedb (below)

$db->insert("users", ["name" => "John", "age" => 25, "email" => "john@example.com"]);
$db->insert("users", [["name" => "Jane", "age" => 31], ["name" => "Ali", "age" => 17]]);

$adults = $db->query("users")
    ->where(['age' => ['$gte' => 18]])
    ->sort('age', 'desc')
    ->limit(10)
    ->get();

$db->update("users", [["name" => "John"], ["set" => ["email" => "john.doe@example.com"]]]);
$db->delete("users", ["name" => "Ali"]);
```

## Configuration

Put a `.nonedb` file outside the web root, next to your entry script or in the project root:

```json
{
    "secretKey": "YOUR_SECURE_RANDOM_STRING",
    "dbDir": "./db/"
}
```

- `secretKey`: a random secret of your own: `php -r "echo bin2hex(random_bytes(32));"` prints one. Published
  placeholders, the one above too, are refused. It names the files of your databases, so they can't be guessed; it
  does **not** encrypt them. Keep `dbDir` out of the web root.
- `dbDir`: where the databases live. A relative path is relative to the `.nonedb` file.
- Or pass the settings as an array: `new noneDB(['secretKey' => getenv('NONEDB_SECRET'), 'dbDir' => '/var/lib/app/db'])`.

Every setting (sharding, buffer, locks, durability, cache, permissions): [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## Queries

```php
$db->insert("products", [
    ["name" => "Lamp", "price" => 120, "category" => "home", "stock" => 4, "tags" => ["featured"]],
    ["name" => "Phone", "price" => 480, "category" => "electronics", "stock" => 0, "tags" => []],
]);

$db->query("products")
    ->where([
        'price' => ['$gte' => 100, '$lte' => 500],
        'category' => ['$in' => ['electronics', 'home']],
        'stock' => ['$gt' => 0],
    ])
    ->sort('price', 'desc')
    ->get();

$db->query("products")->where(['tags' => ['$contains' => 'featured']])->count();
$db->query("products")->where(['name' => ['$like' => '^La']])->first();
```

| Operator | Matches | Example |
|---|---|---|
| `$eq`, `$ne` | equal / not equal (`===`) | `['status' => ['$ne' => 'guest']]` |
| `$gt`, `$gte`, `$lt`, `$lte` | numbers and numeric strings by value, strings lexically | `['age' => ['$gte' => 18]]` |
| `$in`, `$nin` | in / not in a list (`===`) | `['role' => ['$in' => ['a', 'b']]]` |
| `$exists` | the field is there (or not) | `['email' => ['$exists' => true]]` |
| `$like` | a pattern: `^` starts with, `$` ends with | `['name' => ['$like' => '^Jo']]` |
| `$regex` | a regular expression, case-insensitive | `['email' => ['$regex' => '@gmail\\.com$']]` |
| `$contains` | an array element or a substring | `['tags' => ['$contains' => 'featured']]` |

A field index makes equality, `$in` and range filters read only the matching records:

```php
$db->createFieldIndex("products", "category");
$db->query("products")->where(['category' => 'home'])->explain(); // the plan: which index, which shards
```

The query reference: [docs/QUERY.md](docs/QUERY.md).

## Spatial queries

```php
$db->createSpatialIndex("restaurants", "location");
$db->insert("restaurants", [
    'name' => 'Ottoman Kitchen',
    'open' => true,
    'location' => ['type' => 'Point', 'coordinates' => [28.9784, 41.0082]],
]);

$nearby = $db->query("restaurants")
    ->withinDistance('location', 28.9784, 41.0082, 5000)   // meters
    ->where(['open' => true])
    ->get();
$closest = $db->nearest("restaurants", "location", 28.9784, 41.0082, 10);
```

Geometries, rules and the R-tree: [docs/SPATIAL.md](docs/SPATIAL.md).

## Concurrency and crashes

- Every operation locks its database: shared for reads, exclusive for writes, across processes. Concurrent
  writes are serialized; keys are never reused.
- A write that throws leaves no record behind. A process that is killed loses no write that returned: complete
  lines are kept, an unterminated line is never a record and is moved to a verified quarantine file before the
  next writer cuts it, indexes replay the data after their checkpoints.
- Files that don't fit their checksums or their records are never silently used: a database that can't be
  proven consistent fails with `FORMAT_DAMAGED`, and `restore()` from a backup is the way out.
- A multi-record call (`insert([...1000 records])`) is not a transaction under a kill: a prefix of it may stay.

| Mode | A write that returned survives |
|---|---|
| `'durability' => 'performance'` (default) | the process dying (SIGKILL, fatal error), as long as the operating system runs on |
| `'durability' => 'durable'` (Linux) | a power loss as well: every sync is done before the write returns, or it fails |

The contract: [docs/DURABILITY.md](docs/DURABILITY.md); the file format: [docs/STORAGE_FORMAT.md](docs/STORAGE_FORMAT.md).

## Operations

```php
$backup = __DIR__ . '/backups/users-' . date('Ymd-His'); // outside dbDir
$db->backup("users", $backup);           // online: one consistent moment, verified, manifest last
$db->restore($backup, "users");          // one commit: the database is as it was, or restored

$db->checkIntegrity("users");            // a full read-only check
$db->getMetrics("users");                // cache, locks, checkpoints, sizes, state: cheap counters
```

Backups, restores, monitoring, key rotation and what to do when the engine refuses to work:
[docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md), [docs/OPERATIONS.md](docs/OPERATIONS.md).

## Upgrading from noneDB 1.x to 3.x

noneDB 4 reads only its own format and refuses a directory that holds older files (`UNSUPPORTED_FORMAT`): it
never mistakes them for an empty database. Convert them once with the migration tool, which verifies every
database and keeps a backup:

```bash
NONEDB_SECRET=... php noneDB-migrate.php --dir=./db --backup-dir=/backups --confirm-stopped
```

The procedure and every source format: [docs/MIGRATION.md](docs/MIGRATION.md). What changed: [CHANGES.md](CHANGES.md).

## Performance

Measured against a fixed baseline with a statistical gate (two independent runs), on a database of 10 million
records, and against SQLite: [docs/PERFORMANCE.md](docs/PERFORMANCE.md).

## Documentation

| Document | Contents |
|---|---|
| [docs/API.md](docs/API.md) | Every method, its results and errors |
| [docs/QUERY.md](docs/QUERY.md) | The query builder |
| [docs/SPATIAL.md](docs/SPATIAL.md) | Geospatial indexes and queries |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | Settings |
| [docs/DURABILITY.md](docs/DURABILITY.md) | What a successful, failed or uncertain write means |
| [docs/STORAGE_FORMAT.md](docs/STORAGE_FORMAT.md) | The files, normative |
| [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md) | Backups and restores |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | Metrics, maintenance, failure situations |
| [docs/MIGRATION.md](docs/MIGRATION.md) | Converting databases of earlier versions |
| [docs/PERFORMANCE.md](docs/PERFORMANCE.md) | Measurements and how they are made |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | The source tree and the build |
| [CHANGES.md](CHANGES.md) | Release notes |

Runnable examples: [examples/](examples/) (`php examples/find.php`).

## Development

`noneDB.php` and `noneDB-migrate.php` are built from `src/` and `migrate/`: edit those, never the artifacts.

```bash
composer build                         # php tools/build.php
php tools/make-migration-fixtures.php  # first: the databases the Migration suite reads (git history, PHP 7.4)
composer test                          # build check + every PHPUnit suite
vendor/bin/phpunit --testsuite Crash   # Unit, Integration, Crash, Concurrency, Differential, Migration
php tests/Docs/doctest.php             # the examples of the documentation, against noneDB.php
```

## Disclaimer

noneDB is provided "as is", without warranty of any kind, as the [MIT License](LICENSE) states. To the fullest
extent the law allows, its authors and contributors accept no liability for any loss or damage arising from its use,
including lost or corrupted data, downtime, security incidents and lost profits.

You are responsible for deciding whether noneDB suits your use, testing it with your own data and workload, keeping
backups you have verified, and protecting your keys and files. noneDB does not encrypt data. The test results and
performance figures of this project describe the runs that produced them; they are no promise of how it will behave
for you.

## License

MIT License: see [LICENSE](LICENSE).

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
