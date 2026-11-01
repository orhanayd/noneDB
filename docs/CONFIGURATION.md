# noneDB Configuration

Every setting of noneDB 4.0.0, the files it keeps, and how to size its cache.

---

## Contents

1. [Where the settings come from](#where-the-settings-come-from)
2. [Settings](#settings)
3. [The secret key](#the-secret-key)
4. [Files and permissions](#files-and-permissions)
5. [Development mode](#development-mode)
6. [Sharding](#sharding)
7. [Durability](#durability)
8. [Indexes and the cache](#indexes-and-the-cache)
9. [Tuning](#tuning)
10. [Example configurations](#example-configurations)
11. [Troubleshooting](#troubleshooting)

---

## Where the settings come from

### A `.nonedb` file

```json
{
    "secretKey": "a random secret of your own",
    "dbDir": "./db/",
    "autoCreateDB": false
}
```

```php
$db = new noneDB(); // finds and reads .nonedb
```

The file is looked for, in this order, and the first one found serves the whole process:
1. the directory of the running script (`$_SERVER['SCRIPT_FILENAME']`);
2. the directory of `noneDB.php`;
3. the Composer project root (when noneDB is installed under `vendor/`);
4. the working directory.

Keep it **outside the web root**: it holds the secret key. A template is `.nonedb.example`
(`noneDB::getConfigTemplate()` gives its path).

### An array

```php
$db = new noneDB([
    'secretKey' => getenv('NONEDB_SECRET'),
    'dbDir' => __DIR__ . '/data/',
    'shardSize' => 50000,
]);
```

An array is merged over the `.nonedb` file: its values win, the rest comes from the file. An array with both
`secretKey` and `dbDir` needs no file.

### `dbDir`

- A relative `dbDir` in `.nonedb` is relative to the **`.nonedb` file's directory**, so web pages and CLI or
  cron scripts find the same databases. In an array it is relative to the entry script's directory; absolute
  paths are clearer.
- `dbDir` is used by its **canonical path**: symbolic links and `.`/`..` are resolved when the instance is
  configured (for a directory that doesn't exist yet, its nearest existing parent). Two spellings of one
  directory are one store, with one lock per file in a process.
- noneDB creates it when it is missing, with an `.htaccess` that denies web access and an empty `index.html`.

---

## Settings

Values are checked when the instance is created; an invalid value throws `InvalidArgumentException`. So does a
key that is not one of the settings below (the message names it, suggests the setting it was probably meant to be,
and lists them), in the array and in a `.nonedb` file, and a setting given as `null`. Boolean settings accept `true`/`false`, `1`/`0` and `"1"`/`"0"`/
`"true"`/`"false"` (any case), nothing else. Numeric settings take numbers or numeric strings; booleans,
arrays, `"10abc"` and NAN are refused.

| Setting | Type | Default | Meaning |
|---|---|---|---|
| `secretKey` | string | required | The key of your databases ([below](#the-secret-key)). A non-empty string that is not a published placeholder. |
| `dbDir` | string | required (dev mode: `db/` in the project root) | The store directory. |
| `autoCreateDB` | bool | `true` | Writes (`insert()`, `createFieldIndex()`, ...) create a missing database. Reads never create anything; with `false`, `find()` on a missing database returns `false` instead of `[]`. `false` is the safer choice in production. |
| `shardingEnabled` | bool | `true` | A database switches to the sharded layout once it has assigned `shardSize` keys. With `false` it stays in the single layout until `convertToSharded()`. |
| `shardSize` | int ≥ 1 | `10000` | Keys per shard of databases **created from now on**; each database keeps the size it was created with. |
| `autoCompactThreshold` | float > 0 | `0.3` | Compact a segment when its deleted and superseded lines exceed this share of its lines. |
| `lockTimeout` | float ≥ 0 | `5` | Seconds to wait for a lock before `LOCK_FAILED`; fractions work, `0` waits forever. |
| `lockRetryDelay` | int ≥ 1 | `10000` | Microseconds between lock attempts. |
| `bufferEnabled` | bool | `false` | Collect inserts in a buffer file and add them in batches (API.md "Write buffer"). |
| `fieldIndexEnabled` | bool | `true` | Use field indexes in queries. Indexes are kept up to date either way. |
| `spatialIndexEnabled` | bool | `true` | Allow creating and using spatial indexes. |
| `durability` | string | `'performance'` | `'performance'` or `'durable'` (Linux only; elsewhere `UNSUPPORTED_DURABILITY` at construction). [DURABILITY.md](DURABILITY.md). |
| `cacheMemoryLimit` | int ≥ 0 \| `'auto'` | `'auto'` | Process memory (bytes) above which cached indexes are dropped; `'auto'`: half of `memory_limit` (none when it is `-1`); `0`: no limit ([below](#the-cache-cachememorylimit)). |
| `filePermissions` | int \| octal string | `0640` | Permissions of every file noneDB creates in `dbDir`, whatever the umask. The owner needs read and write; the world may not write (a group may). |
| `directoryPermissions` | int \| octal string | `0750` | The same for directories; the owner needs read, write and search. |

`filePermissions` and `directoryPermissions` take an integer (`0640` in PHP) or an octal string (`"0640"`,
`"640"`, the form a JSON file needs). They apply to files created from now on; existing files keep theirs.

The key maintenance methods (`migrateKey()`, `registerKey()`, `repairStore()`) take `['durability' => ...]` as
an option.

---

## The secret key

- A database lives in `{dbDir}/{locator}.ndb4/`, where the locator is an HMAC of the database name with the key
  ([STORAGE_FORMAT.md](STORAGE_FORMAT.md) §1). Without the key the directory names can't be guessed or linked
  to the names.
- The key does **not** encrypt anything: records are plain JSON lines. Protect `dbDir` and `.nonedb` with file
  permissions, and use disk encryption where the data needs it.
- The store records which keys may open it (the `STORE` file holds an HMAC of each key, never the key). The
  first key that writes to an empty directory creates the store; another key gets `KEY_NOT_REGISTERED` until
  `noneDB::registerKey()` adds it. A wrong key never sees an empty database where data exists.
- Generate a key with `php -r "echo bin2hex(random_bytes(32));"` (32 random bytes). Keep it out of version
  control: in `.nonedb` (ignored by git) or in the environment:

```php
$db = new noneDB(['secretKey' => getenv('NONEDB_SECRET'), 'dbDir' => __DIR__ . '/data/']);
```

To change the key of existing databases, rotate it: `noneDB::migrateKey($dbDir, $oldKey, $newKey)` renames
every database directory of the old key ([OPERATIONS.md](OPERATIONS.md) §4).

---

## Files and permissions

```text
{dbDir}/
  STORE, STORE.2, STORE.LOCK   the store record (two copies) and its lock
  .htaccess, index.html        deny web access
  {locator}.ndb4/              one directory per database
    FORMAT, CURRENT, LOCK      identity, active generation, lock and state record
    generations/{N}/           the data, the indexes, the write buffer
    recovery/                  recovery log and quarantine files
  .nonedb-maintenance/         maintenance journal and the store's event log
```

The complete layout is [STORAGE_FORMAT.md](STORAGE_FORMAT.md). Rules for operators:
- Don't edit, move or delete anything in a store while processes use it. A `LOCK` file in particular must stay:
  the locks and the state record live there.
- Anything noneDB doesn't know in `dbDir` is a *foreign entry*: databases that exist keep working, but no
  database is created or answered as missing while one is there (`UNSUPPORTED_FORMAT`). Keep other files
  elsewhere.
- A `{locator}.ndb4` that is a symbolic link is not a database: noneDB follows no link into a store.
- Files are `0640`, directories `0750` (`filePermissions`, `directoryPermissions`), whatever the umask. Backups
  are `0600`/`0700`.

For a web server that serves the directory anyway:

```apache
Require all denied
Deny from all
```

```nginx
location /db/ { deny all; }
```

`.gitignore` (the template is in the repository):

```gitignore
.nonedb
db/
!.nonedb.example
```

---

## Development mode

Without a `.nonedb` file, and when the array doesn't give both `secretKey` and `dbDir`, development mode fills in
what is missing:

<!-- doctest: skip -->
```php
noneDB::setDevMode(true);           // or putenv('NONEDB_DEV_MODE=1'), or define('NONEDB_DEV_MODE', true)
$db = new noneDB();
noneDB::isDevMode();                // true
```

| Setting | In development mode |
|---|---|
| `secretKey` | `"nonedb_dev_mode_key_" . md5(<directory of noneDB.php>)`: predictable by design |
| `dbDir` | `db/` in the project root (next to `vendor/` with Composer, else next to `noneDB.php`) |

Never use it in production: its key is predictable. Outside the CLI noneDB raises an `E_USER_NOTICE` once per
process while it is active.

---

## Sharding

A database starts in the single layout (one data file). Once it has assigned `shardSize` keys it switches to
the sharded layout: shard N holds the keys `[N × shardSize, (N + 1) × shardSize)`. The switch is a generation
switch: the new layout is built and verified completely before it is published (STORAGE_FORMAT.md §5.2).

```php
$db = new noneDB(['shardSize' => 50000, 'shardingEnabled' => false]);
$db->insert("big", ["v" => 1]);
$db->convertToSharded("big");      // switch now: ["success" => true, "status" => "converted"]
$db->getShardInfo("big");          // ["sharded" => true, "shards" => 1, ..., "shardSize" => 50000, "nextKey" => 1]
```

| Shard size | Effect |
|---|---|
| 10,000 (default) | More files; a cold lookup loads less (one shard's key index) |
| 100,000 | Fewer files; faster queries that read every shard |

Measured on 10 million records ([PERFORMANCE.md](PERFORMANCE.md)): a key lookup in a new process takes about
1.5 ms with either size; an indexed query of 1000 results 120 ms with 100 shards and 310 ms with 1000 shards in
a new process, 12 and 26 ms once its indexes are cached.

---

## Durability

```php
$db = new noneDB(['durability' => 'performance']); // the default
```

| Mode | A write that returned survives |
|---|---|
| `performance` | the process dying (SIGKILL, fatal error), as long as the operating system keeps running; a power loss is best effort |
| `durable` (Linux) | a power loss too: every sync the write needs is done before it returns, or it fails (`DURABILITY_FAILED`, rolled back; `OUTCOME_UNKNOWN` after the commit point) |

Durable mode on macOS or Windows is refused at construction (`UNSUPPORTED_DURABILITY`): macOS's `fsync()`
doesn't flush the drive cache and PHP can't call `F_FULLFSYNC`; Windows can't sync a directory. Network file
systems are supported in neither mode. Every write waits for the disk in durable mode: it is slower.

The contract (commit points, sync order, the three outcomes, `getDurability()`): [DURABILITY.md](DURABILITY.md).

---

## Indexes and the cache

### Index files

Every index is a **checkpoint** of the data: it covers the data file up to an offset, and loading it replays
the complete lines after that. Inserts, updates and deletes only append to the data file.

| File (in `generations/{N}/`) | Contents |
|---|---|
| `segments/{seg}.kix` | key index: where each key's current line is |
| `indexes/{seg}.{token}.fx` | field index of a segment: the keys of each value, by type (`1`, `1.0` and `"1"` are three values) |
| `indexes/{token}.gfx` | global field index (sharded): a filter per shard of its values |
| `spatial/{seg}.{token}.rx` | R-tree of a segment's geometries |
| `spatial/{token}.grx` | global spatial index (sharded): the bounding box of each shard |
| `indexes.json` | the indexed fields |

- `{seg}` is `main` (single layout) or `s{N}` (shard N). `{token}` is the field name when it matches
  `[a-z0-9_]{1,40}`, else a lower-case prefix and 12 hex digits of its SHA-256: any field name can be indexed,
  and `city` and `City` have files of their own on every file system.
- A key-index checkpoint is written at the end of a write session once 64 lines plus 1/16 of the records (or
  1 MiB) follow it. A field or spatial index checkpoint is written by a process that uses the index once the
  data after it reaches 64 KiB or a quarter of the index file, whichever is larger. A process that only writes
  never loads field or spatial indexes.
- A checkpoint is trusted only for the data file it was made from (the offset, a checksum of the bytes before
  it, an epoch that changes when compaction or a generation switch replaces the file). One that doesn't fit is
  rebuilt from the data. Index files are never synced: they can always be rebuilt.
- **Sharded lookups:** a lookup on an indexed field reads the shards the global index says may hold the value,
  and the shards whose newer lines may. A write that adds a value doesn't rewrite the global index.

### The cache (`cacheMemoryLimit`)

Indexes, metadata and the bytes of data files in repeated use are cached per process and shared by all its
instances. `cacheMemoryLimit` is the memory of the process above which they are dropped, least recently used first:

```php
new noneDB(['cacheMemoryLimit' => 32 * 1024 * 1024]); // 32 MiB
new noneDB(['cacheMemoryLimit' => 0]);                // no limit
new noneDB(['cacheMemoryLimit' => 'auto']);           // the default: half of memory_limit
```

- **When:** when the last lock of the process is released and something new was cached; and **between the
  segments** of an operation over many segments, once the process uses more than the limit (entries go until
  it is under three quarters of it; the next drop waits until it has grown by another quarter). The cache of
  one operation over a large database stays within the limit: the indexes of the segments it finished go first.
  A dropped index is loaded again when it is needed.
- **Near `memory_limit`.** `memory_limit` counts what PHP holds for the process, which a large read leaves above what
  the process uses. Once the process's peak has come within an eighth of `memory_limit`, the drop between segments
  measures what PHP holds too, and what the process has freed goes back to the system.
- Only what is cached is dropped; what else the process holds (an answer, the caller's data) stays. A limit
  below what the process needs without the cache leaves nothing cached, and every lookup loads again
  (`getMetrics()`: `indexCache.evictions` and `misses` grow with every operation).
- The setting is process-wide: the last instance created with it in its configuration sets it.
- **Data bytes.** In a database of more than 16 segments, a data file in repeated use is held whole while the process
  stays under half of the limit, and its records no longer cost an open and a read each. Repeated indexed queries over
  many shards gain from a limit that leaves room for the shards they use; with `memory_limit = 512M` (a limit of 256 MiB,
  so 128 MiB of bytes at most) on a 10M database they gain little. A process with no limit holds no data bytes, because
  nothing would bound them: `cacheMemoryLimit` 0, or `'auto'` with `memory_limit = -1`.
- **It is not a memory cap.** One operation loads what it needs and can take the process above the limit while it
  runs; entries go between segments and when the lock is released. PHP's `memory_limit` stays the hard limit: size
  it for your largest operation, and the cache below it.
- After `noneDB::disableStaticCache()` each new instance keeps its own cache, which no limit applies to.

**Sizes** (PHP 8.2):
- **Key index:** about 12 bytes per record (its checkpoint body). A segment in constant use is decoded to about
  80 bytes per record for faster lookups: in a database of at most 16 segments at once, in a larger one once
  its lookups pay for it. A process's first operation never decodes, so a one-shot script stays light.
- **Field index:** a file of at most 64 KiB is loaded whole at its first use. A larger one is read by equality
  lookups along their path only, about 4 KiB per page, and the pages are kept; it is loaded whole once lookups
  pay for it. Ranges and sorting by the field load it whole (20–80 bytes per record with up to 1000 distinct values, about 190 when every value differs).
- **Global field index:** 4 to 8 bytes per value of the largest shard, per shard (a unique field over 10 million
  records in shards of 10,000: 64 MiB on disk); a lookup reads six short rows. From its second operation the
  rows are kept. A command-line process also builds an exact map of the shards' values (about 100 bytes per
  value), within the budget, in a database of at most a million records; a web request doesn't.
- **10 million records with one indexed field:** an indexed query of 1000 results peaks at 150–210 MiB; with
  PHP's default `memory_limit` of `128M` (a 64 MiB cache) it takes 0.3 s at 1000 shards and peaks below 128 MiB.

### Memory of operations over many segments

An operation holds the cache, one batch of the records of the segment it works on, and its answer: never the
whole database, nor a whole segment. Reads and aggregations, writes by filter, field index builds,
`rebuildIndex()`, `checkIntegrity()`, recounting shard counters, the switch to shards and the first pass of a join
go segment by segment and read a segment **10,000 records at a time** (the default shard size: a segment of that size
is read at once), so the memory does not grow with `shardSize` or with a database that is not sharded. Besides the
batch an operation keeps at most a few hundred bytes per key of that segment (its key index's arrays, offsets and
lengths, the buckets of a field index it builds); a write by filter holds a batch and its new versions. Two hold
more of the segment: `compact()` holds the bytes of its live records (the new data file), and a spatial index
build holds an entry per record with a geometry, about 512 bytes each (a segment's R-tree is built and loaded whole).
A sharded database's global field index is made in parts of at most a quarter of the cache limit.

Shards of 100,000 records (PHP 8.2): a scan or an aggregation peaks at about 41 MiB,
`checkIntegrity()` at 43 MiB, a field index build at 49 MiB, `rebuildIndex()` at 52 MiB, `compact()` at 35 MiB.

These peaks are without a cache, and the cache comes on top of them. On 10 million records in shards of 100,000 at
PHP's default `memory_limit` of `128M` (a 64 MiB cache), an unindexed `find()` takes 29 s and peaks at 123 MiB, a sum over
every record 32 s and 121 MiB, `createFieldIndex()` 36 s and 121 MiB. They finish with little left for what the script
itself holds. A `cacheMemoryLimit` of 32 MiB brings the field index build down to 99 MiB in the same time.

A field whose every value differs is the most a field index costs, and a database that isn't sharded holds it in one
segment. On one segment of 300,000 records with a different value each (PHP 8.2), `createFieldIndex()` peaks at 97 MiB and
a range with a small answer at 119 MiB, within `128M`. `checkIntegrity()` takes 133 MiB and a range of 78,000 records
165 MiB, so those two need a larger `memory_limit`. Shards keep such a field small.

What an answer holds is the caller's: `find()` of every record returns every record; `sort()` without a small
`limit()` sorts all matches; a join holds the joined records, `groupBy()` its groups. Besides the cache the
engine keeps a few KiB per segment (about 7 MiB for 1000 segments).

---

## Tuning

| Setting | When to change it |
|---|---|
| `autoCompactThreshold` | `0.2` for many deletes, `0.5`–`0.7` to write less often (more disk space) |
| `lockTimeout` | larger for long compactions or backups of large databases; `0` waits forever |
| `shardSize` | larger for queries that read every shard, smaller for cold lookups |
| `cacheMemoryLimit` | larger for long-running workers with large databases |

Under PHP-FPM or `mod_php` the cache lives as long as one request: each request loads the index pages it needs.
In a long-running process (a CLI worker, a queue consumer) they stay loaded. For large databases in web
requests prefer queries that touch few shards: key lookups and indexed fields whose values sit in a few shards.

---

## Example configurations

Development:

```json
{ "secretKey": "a random secret of your own", "dbDir": "./db/", "shardingEnabled": false }
```

Production:

```json
{
    "secretKey": "a random secret of your own",
    "dbDir": "/var/lib/app/nonedb/",
    "autoCreateDB": false,
    "shardSize": 50000,
    "lockTimeout": 10,
    "durability": "durable",
    "filePermissions": "0640",
    "directoryPermissions": "0750"
}
```

---

## Troubleshooting

| Message or code | Cause | What to do |
|---|---|---|
| `'secretKey' must be a non-empty string` / `is a publicly known key` | No key, or a placeholder from the documentation | Generate one; existing data under a placeholder: rotate it with `migrateKey()` |
| `KEY_NOT_REGISTERED` | The key is not registered for this store (a typo, another application's store, or a rotated key) | Use the registered key, or `registerKey()` (OPERATIONS.md §4) |
| `UNSUPPORTED_FORMAT` | `dbDir` holds files that are not noneDB 4 objects: databases of noneDB 1.x–3.x, or anything else | Migrate them (MIGRATION.md) or move them out |
| `FORMAT_DAMAGED` | A store record, FORMAT, CURRENT or generation can't be read and can't be repaired with proof | OPERATIONS.md §5 |
| `MAINTENANCE_IN_PROGRESS` | Key maintenance or a migration is running or was interrupted | Run it again to finish it (OPERATIONS.md §4) |
| `LOCK_FAILED` ("timed out ... waiting for the lock") | Another process held the lock longer than `lockTimeout` (a long write, a backup), or readers that never stop kept a writer out (no queue, PERFORMANCE.md "Known limits") | A larger `lockTimeout`; find the long holder; retry the write |
| `DURABILITY_FAILED` | A required sync failed before the commit point (durable mode): rolled back | Check the disk; `getDurability()['lastSyncError']` |
| `OUTCOME_UNKNOWN` | A failure after the commit point, or an undo that failed | Read the state again before retrying; the next access recovers |
| "could not create ... a quarantine file" | A write must move a damaged tail aside first and can't (disk full, permissions) | Free space or fix permissions; reads keep working |
| `Permission denied` | The PHP user can't read or write `dbDir` | Give the user that runs PHP (only) access: `chown -R www-data /var/lib/app/nonedb` |
