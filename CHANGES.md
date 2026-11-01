# noneDB Changelog

## v4.0.0 (2026-11-01)

### A new engine and a new storage format

noneDB 4 keeps what noneDB is: one PHP file, no server, no extension, plain JSON lines on disk. Underneath, the
engine is new. It has its own storage format (format 4) and opens a database only when it can prove what the
database is. Writes are rolled back when they fail, and crash recovery never loses a write it acknowledged. Durable
mode survives a power loss. Backups are consistent, metrics are cheap, and the costs of operations are bounded.

noneDB 4 does not read the files of noneDB 1.x–3.x, and has no compatibility layer for them. A separate tool,
`noneDB-migrate.php`, converts them once, verified and reversible ([docs/MIGRATION.md](docs/MIGRATION.md)).

Requirements: PHP 8.1 or later, 64-bit (tested on 8.1–8.5); the `json` extension, and `mbstring` for Unicode case
folding in `like()`.

What "production-ready" covers: embedded use on one machine with a local file system. It does not cover
multi-record ACID transactions, or network file systems (NFS, SMB, a volume shared by several containers).

---

### Upgrading from 1.x–3.x (read first)

1. **Migrate the databases.** Stop every process that uses the directory, then run the tool with the key the
   databases were written with:
   ```bash
   NONEDB_SECRET=... php noneDB-migrate.php --dir=./db --dry-run
   NONEDB_SECRET=... php noneDB-migrate.php --dir=./db --backup-dir=/var/backups/nonedb --confirm-stopped --verify
   ```
   The tool reads 1.4, 2.0–2.3, 3.0–3.2 and the unreleased 3.3 development state. Each version's records come
   through as that version returned them, with their keys. Records a version had deleted stay deleted, even where
   its data file still holds them. The old files are backed up first, then kept until `--cleanup`; `--restore`
   brings them back. A database whose files don't determine its records is reported and left alone: nothing is
   guessed.
2. **Until then the engine refuses the old files.** While `dbDir` holds files that are not noneDB 4 objects, no
   database is created and none is answered as missing (`UNSUPPORTED_FORMAT`). An old database never looks empty.
   noneDB 4 databases in the same directory keep working.
3. **PHP 8.1+.** PHP 7.4 and 8.0 are no longer supported.
4. **Removed methods:** `enableIndexing()`, `isIndexingEnabled()`, `flushSpatialIndexes()`, `clearDistanceCache()`,
   `clearCentroidCache()` and `noneDBQuery::compareValues()`. Index use follows `fieldIndexEnabled` and
   `spatialIndexEnabled`; spatial indexes are written by the operations themselves; there are no distance caches.
5. **Changed:**
   - `migrate()` is now `convertToSharded()`. It reports `"converted"` (else `"already_sharded"`,
     `"database_not_found"`, `"conversion_failed"`).
   - The `autoMigrate` setting is gone: `shardingEnabled` alone decides whether a database switches to shards
     by itself once it has assigned `shardSize` keys. Remove it from your `.nonedb` before you migrate: noneDB 4
     refuses settings it doesn't know (item 7), and so does the migration tool's pre-check, for a `.nonedb` the engine
     finds itself.
   - `migrateKey()` is a journaled store operation with its own rules (see "Keys" below). Its fourth argument
     takes options (`abort`).
6. **Error codes are new:** `RuntimeException` codes, with constants of `noneDB` (`LOCK_FAILED`,
   `UNSUPPORTED_FORMAT`, `FORMAT_DAMAGED`, `KEY_NOT_REGISTERED`, …; docs/API.md "Errors"). A failure after a
   commit point, or an undo that failed, is `OUTCOME_UNKNOWN` (3301).
7. **Settings are checked:**
   - A key that is not a setting is an `InvalidArgumentException`, in the array and in a `.nonedb` file. The
     message names it and suggests the setting it was probably meant to be, so a misspelled `durability` never
     means performance mode.
   - So is a setting given as `null`.
   - The options of a method are checked the same way: `createSpatialIndex()`, `withinDistance()`, `nearest()`,
     `restore()` and the key maintenance methods.
   - `durability`, `cacheMemoryLimit`, `filePermissions` and `directoryPermissions` are new.
   - Bool settings accept only `true/false`, `1/0` and `"1"/"0"/"true"/"false"`.
   - `lockTimeout` takes fractions of a second (docs/CONFIGURATION.md).
8. **Keys:** a store accepts only the keys registered for it. The first key creates the store; another key gets
   `KEY_NOT_REGISTERED` (never an empty database) until `registerKey()` adds it.

---

### Storage format 4 (docs/STORAGE_FORMAT.md, normative)

- **A store record** (`STORE`, `STORE.2`: two copies, a sequence number and a checksum) lists the keys that may open
  the directory.
- **One directory per database**, `{locator}.ndb4/`. The locator is an HMAC of the name under the key: a name
  can't be guessed without the key.
  - `FORMAT` is the database's identity: its `databaseId` (a UUID), its name and its shard size. It doesn't
    depend on the key.
  - `LOCK` holds the database lock and a 128-byte state record (generation, epoch, flags).
  - `CURRENT` names the active generation, `generations/N/`. Changing a database's layout, or restoring it, builds
    a new generation and publishes it by one atomic replacement of `CURRENT`.
- **Fail closed.** A database whose identity or active generation can't be proven fails with `FORMAT_DAMAGED`;
  nothing is guessed and nothing is removed. A file is repaired only with proof (the identity copy in the
  generation's metadata), and the repair is logged.
- **Data** stays JSON lines, with the key last in every line. Updates and deletes append; an unterminated last
  line is never a record. The next writer moves it to a verified quarantine file and cuts it.
- **Indexes are checkpoints of the data** and are rebuilt from it when they don't fit:
  - key indexes (`.kix`): 4 KiB blocks, each with its crc; dense or sparse;
  - field indexes (`.fx`): a static B+tree of ~4 KiB pages;
  - the global field index (`.gfx`) of a sharded database: one Bloom filter per shard, stored bit-sliced;
  - R-trees (`.rx`), and each shard's bounding box (`.grx`).

  A write appends to the data and leaves the index files alone. Checkpoints are written at the end of a session,
  past a threshold.

### Writes, durability and recovery (docs/DURABILITY.md)

- **Every write is one operation with one outcome:**
  - `committed`;
  - `rolled_back`: a failure before the commit point; every append of the operation is cut back;
  - `unknown`: a failure after the commit point, `OUTCOME_UNKNOWN`. The next access recovers.

  `getDurability()['lastOperation']` reports it.
- **`durability`:** `'performance'` (the default: what the operating system has accepted survives a crash of the
  process) or `'durable'` (Linux; every write is synced before it returns, so a power loss doesn't lose it).
  `'durable'` elsewhere is `UNSUPPORTED_DURABILITY` at construction. A sync that fails before the commit point is
  `DURABILITY_FAILED`, rolled back.
- **Locks:** a database lock across processes for every operation (shared to read, exclusive to write), and
  `lockTimeout` for every wait (`LOCK_FAILED`).
- **Crash recovery** applies complete lines and quarantines the rest; the recovery log (`getRecoveryLog()`) says
  what was done. A SIGKILL at any point of any operation leaves the state before or after it: tested at every
  step of every state machine (creation, drop, generation switch, compaction, key maintenance, migration, restore).
- **`checkIntegrity($db)`** reads every line and compares indexes and counters with the data. It writes nothing.

### Keys (STORAGE_FORMAT.md §9)

- `registerKey($dir, $registeredKey, $newKey)`: lets a second key open the store.
- `migrateKey($dir, $oldKey, $newKey)`: renames every database of the old key to its locator under the new one.
  - **Journaled, one commit point.** An interruption leaves the store closed (`MAINTENANCE_IN_PROGRESS`) until the
    same call runs again: before the commit it continues, or rolls back with `['abort' => true]`; after it, it
    only finishes.
  - **Busy:** a database in use makes it `MAINTENANCE_BUSY`, and nothing is written.
  - **No stale answers:** a process still running with the old key gets `MAINTENANCE_IN_PROGRESS` or
    `KEY_NOT_REGISTERED`, never an empty database.
- `repairStore($dir, $keys, $options)`: restores a store record whose two copies are both lost, from a saved
  `storeManifest` (complete) or, explicitly confirmed, from the keys given (`complete` false). One damaged copy is
  repaired automatically.

### Backup and restore (new, docs/BACKUP_RESTORE.md)

- **`backup($db, $dir)`:** one database at one moment, taken while it is in use. Its files are verified,
  `manifest.json` (SHA-256 of every file) is written last, and `store.json` is included. The directory is 0700,
  its files 0600.
- **`restore($dir, $db, $options)`:**
  - checks the backup completely, then publishes it as a new generation in one commit;
  - into the database it was taken from, into an absent one, or over a damaged one (whose generations are kept);
  - keys handed out after the backup are never handed out again;
  - another database's backup only with `force`.

### Monitoring (new, docs/OPERATIONS.md)

- **`getMetrics($db = null)`:** counters of the process and metadata of a database. Nothing is scanned or written.
  - the process: cache hits, misses and evictions, index reads, checkpoints, compactions, lock waits and
    timeouts, recovery events, durability, the last integrity check, memory;
  - a database: its identity, layout, counters and sizes by kind of file.

### Security

- **Permissions:** files `0640`, directories `0750` by default (`filePermissions`, `directoryPermissions`).
- **Paths:** `dbDir` is used by its canonical path, and a database directory that is a link is refused.
- **No secrets in logs:** recovery logs, metrics and error messages hold no key and no record value (tested).

### Queries (docs/QUERY.md)

- **A planner** reads only the candidates of each segment:
  - field index buckets and ranges, and key lookups;
  - it skips the shards the global indexes rule out;
  - it stops as soon as `first()`, `exists()` or `limit()` has enough;
  - top-k for `sort()` with a limit of up to 1000.

  `explain()` shows the plan without reading records. Every record read is still checked against every filter.
- **Sort order is total:** null < booleans < numbers < numeric strings < other strings < arrays; `min()`/`max()` use
  it too.
- **Semantics fixed since 3.2:**
  - `whereNot()` applies operators;
  - a `key` filter means the same in every clause;
  - NAN matches nothing;
  - `limit(0)` returns nothing;
  - aggregates ignore `select()`;
  - groups and `distinct()` use exact typed identity;
  - joins compare strictly and don't truncate floats;
  - `like()` and `search()` fold Unicode case;
  - a trailing `$` of a like pattern means "ends with".
- **Field indexes match by type**, as `find()` does: `1`, `1.0`, `"1"` and `true` are different values, and floats
  don't depend on `precision` or the locale.
- **Spatial fixes:**
  - polygons are closed (their east and north edges are included), and holes are respected;
  - MultiPolygon components are handled one by one;
  - malformed GeoJSON is invalid;
  - distances to edges are exact;
  - polygon validation is O(n log n), with exact orientation tests.

### Performance (docs/PERFORMANCE.md)

- Measured against the frozen 3.3 development state with the statistical performance gate (two independent runs,
  ABBA child processes). That state was itself measured against 3.2.0: single writes to 10,000 records 12–17 times
  faster, and no verified regression. The results and the known limits are in PERFORMANCE.md.
- **Bounded memory.** An operation holds the cache, its answer and one batch of the segment it works on, never the
  whole database or a whole segment. A segment is read 10,000 records at a time, so memory doesn't grow with
  `shardSize` or with a database that is not sharded (docs/CONFIGURATION.md). This holds for:
  - reads, aggregations and writes by filter;
  - field index builds and recovery;
  - `checkIntegrity()` and the switch to shards.

  `compact()` holds its segment's live bytes, and a spatial index build its entries. With shards of 100,000 records,
  a scan peaks at about 41 MiB and `checkIntegrity()` at 43 MiB (3.3-dev: 139 and 215 MiB). The migration tool
  converts 10 million records at PHP's default `memory_limit` of 128M.
- **10 million records** (1000 shards):
  - the first indexed query of a process takes 0.27 s (3.3-dev: 7.3 s);
  - a warm query takes 26 ms and its process peaks at 1.3 GiB (3.3-dev: 31 ms, 2.3 GiB);
  - queries work within a 512 MiB process (3.3-dev ran out of memory).
- **Data files in repeated use are held.** In a database of more than 16 segments, a process with a memory limit reads a
  data file whole once its use pays for it, and its records no longer cost an open and a read each. The bytes stay under
  half of the cache limit (docs/CONFIGURATION.md "The cache"), and they never change an answer.

### Code and tests

- **Modules:** `noneDB.php` is built from `src/` (one class per responsibility, `noneDB\Internal\…`), and
  `noneDB-migrate.php` from `migrate/`. `php tools/build.php --check` proves both artifacts are the build. Neither
  artifact contains test code: the test build adds fault points and counters.
- **Suites:** about 1950 tests (unit, integration, crash, concurrency, differential, migration), on PHP 8.1–8.5
  (8.3–8.5 and durable mode on Linux).
  - Expected values come from the specification or from the test's own models of the formats.
  - Mutation checks for every new mechanism.
  - Migration fixtures are written by the old releases themselves.
- **Documentation examples** run in CI against the built library (`tests/Docs/doctest.php`).

---

## v3.2.0 (2026-10-23)

### Reliability, security and correctness release

This release fixes every finding of a full code review. The IDs in parentheses (K-01, Y-14, ...) name
those findings; the tests in `tests/Regression` are annotated with the same IDs. Most changes are
internal; the few behaviour changes are listed under **Upgrade notes** below.

---

### Upgrade notes (read before upgrading)

1. **secretKey is validated.** An empty, missing, non-string or placeholder key
   (`CHANGE_THIS_TO_A_SECURE_RANDOM_STRING`, ...) now throws `InvalidArgumentException`.
   If your databases were created with the placeholder key, re-key them once:
   ```php
   noneDB::migrateKey('/path/to/db', 'CHANGE_THIS_TO_A_SECURE_RANDOM_STRING', bin2hex(random_bytes(32)));
   // then put the new key in .nonedb
   ```
2. **Database names are validated, not sanitized.** Only `A-Z a-z 0-9`, space, `'` and `-` are allowed.
   Other characters (`_`, `.`, `!`, non-ASCII, ...) throw `InvalidArgumentException` instead of being
   silently removed (`"my_db"` used to become `"mydb"`, and `"çalışan"` became `"alan"`, colliding with
   another database). Data written under the old, sanitized name is still there: use that name
   (e.g. `"mydb"`) or rename your code's names to valid ones.
3. **Relative `dbDir` in `.nonedb` is resolved against the `.nonedb` file** (`./db/` used to follow the
   running script, `db/` the working directory). Web pages and CLI/cron scripts therefore share one
   database directory. In a config array a relative `dbDir` follows the entry script. If an old
   directory exists and the new one doesn't, it keeps being used and an `E_USER_DEPRECATED` notice
   tells you to move it.
4. **A config array is merged over the `.nonedb` file** (as the documentation always said). Before, an
   array without `secretKey` silently ran with an empty key.
5. **The write buffer is off by default** (`bufferEnabled: false`). Direct writes are now faster and
   always visible to other processes. Buffers left by older versions are flushed automatically.
6. **Keys never change.** `compact()` keeps keys on sharded databases too (it used to renumber them),
   and drops shards that became empty.
7. **Reads never create anything.** `find()`, `count()`, `getDBs('name')`, `join()`, spatial queries...
   on a database that doesn't exist return an empty result (or `false` when `autoCreateDB` is off) and
   create no files. Only writes (`insert`, `createDB`, ...) create databases.
8. **Floats keep their type.** `2.0` is stored as `2.0` and read back as a float (it used to come back
   as the integer `2`). Already stored values are unchanged.
9. **`lockTimeout` is honoured.** Waiting longer than `lockTimeout` seconds for a database lock throws
   `RuntimeException` (it used to fall back to waiting forever). `0` waits forever.
10. **`noneDB.php` no longer sets `memory_limit` to `-1`** when it is included.
11. **Lock files carry state.** Each database has a `{db}.nonedb.lock` file holding a small state record
    (change counter, pending buffer, index journal). Don't delete lock files while processes use the
    database. Old-version leftovers (pre-3.2 buffer files, missing index list) are looked for once per
    database, not on every operation. Don't run 3.1 and 3.2 on the same database directory; if you go
    back to 3.1 and later return to 3.2, delete the `*.nonedb.lock` files first (with no process
    running) so that check runs again.

### Concurrency and durability

- **Database-level locking.** Every operation holds a lock on its database for its whole duration
  (shared for reads, exclusive for writes), across processes and instances. Concurrent inserts,
  updates and deletes no longer lose records or reuse keys (K-01).
- **Caches can't go stale.** A generation counter in the lock file's state record invalidates every
  cached index, meta and field/spatial index of a database as soon as another process writes it
  (K-07, Y-11, Y-13).
- **Atomic file writes.** Index, meta and other JSON files are written to a temp file and renamed;
  a crash can no longer leave a truncated index (Y-14). Unencodable data (invalid UTF-8, `NAN`, `INF`)
  is rejected before anything is written (K-03).
- **Self-healing indexes.** A missing, corrupt or out-of-date key index is rebuilt from the data file;
  deletions append a tombstone line so deleted records stay deleted after a rebuild. A torn last line
  (crash during an append) is cut off. Records are verified against their key when read.
- Lock file handles are opened close-on-exec: programs started by the process don't inherit them (an
  inherited copy would keep a lock held after the process died in the middle of a write).
- **Write journal.** Field indexes, spatial indexes and shard counters are rebuilt automatically if a
  writer dies half-way through an operation (the journal entry lives in the lock file's state record).
- **Idempotent buffer flush.** A crash during a buffer flush can no longer lose or duplicate records (K-08).
- Tested with real `SIGKILL` crashes and multi-process stress tests (`tests/Regression`).

### Performance

- **No per-operation checks for old versions.** The lock file is read on every lock acquisition anyway;
  its state record says whether the database was already checked for pre-3.2 leftovers, whether the
  write buffer holds records and whether the index journal has entries. Normal reads and writes
  therefore don't stat buffer files, glob for old files or re-derive the index list. Pre-3.0 data files
  are converted when their (missing) index is first needed.
- Lock file handles stay open between operations (one per database, at most 64; never shared with a
  forked child, never trusted after the file was replaced), and the change counter is written once per
  write session with a single fixed-size write.
- Loaded key indexes are trusted until another process writes the database (no `stat` per read).
- Query builder: `where()`/`orWhere()` filters are compiled once per query (operators validated,
  `$like`/`$regex` compiled), sort keys are computed once per record, and reading records in file order
  no longer sorts the offset map when it is already in order.
- `new noneDB()` no longer resolves the script directory unless a relative `dbDir` needs it, and a
  `dbDir` given in the config array skips resolving the file's one.
- Query sorting uses `array_multisort` on precomputed sort keys (same order as before, stable on every
  PHP version).
- `query()->nearest(k)` combined with other filters reads the nearest candidates in growing batches
  instead of putting every record in distance order (same results; it used to sort the whole database
  since k is applied after the other filters).

### Security

- `secretKey` is always honoured: the persistent hash cache is per key
  (`.nonedb_hash_cache_<fingerprint>`, mode 0600) and can no longer let a wrong key read data or
  "poison" the right key (S-01, S-02, S-03, S-14). The old `.nonedb_hash_cache` file is ignored
  (`migrateKey()` removes it).
- The key is never shown by `print_r`/`var_dump`/`var_export`, and instances can't be serialized (S-09).
- New database directories get an `.htaccess` (`Require all denied`) and an empty `index.html` (S-06).
- `noneDB::migrateKey($dir, $old, $new)` re-keys existing databases (also those from before 3.2, without
  leaving anything under the old key).
- A `secretKey` given in the config array is always validated as given: `null` is rejected also when
  there is no `.nonedb` file (it was reported as a missing config file) and in dev mode (it was silently
  replaced by the predictable dev mode key).
- Config values are validated (`shardSize`, `lockTimeout`, `lockRetryDelay`, `autoCompactThreshold`).

### Storage engine

- Pre-3.0 files are detected safely: a record whose first field is `data` is no longer mistaken for the
  old format and destroyed (K-02).
- Sharding: keys come from `meta.nextKey` and the shard size stored in each database's meta, so
  reopening with a different `shardSize` is safe (Y-10); migration never reuses keys (K-06); key lookups
  on sharded databases see new records (Y-09); empty shards are removed by `compact()`.
- Auto-compaction triggers when deleted/superseded records exceed the threshold relative to the file
  (not to the key counter) (O-22).
- Key filters accept integers and digit strings only (`'abc'`, `null`, `true`, `1.9` match nothing) and
  are combined (AND) with the other filters (Y-15, Y-16). `null` filters behave the same in `find`,
  `update` and `delete` (Y-17). Malformed `update()` input is rejected (Y-18).
- New `dropDB($name)`.

### Field and spatial indexes

- Indexed fields are recorded in `{db}.nonedb.indexes`; field names may contain any characters
  (`e-mail`, `şehir`, `first.name`) (Y-08). New shards, `null` values and type changes are indexed
  correctly (Y-12). Index files written by older versions are rebuilt on first use.
- Batch operations write each index file once (P-02).
- Spatial: indexes work on sharded databases (Y-02), cover new shards (Y-05) and survive migration
  (Y-04); buffered inserts are indexed (Y-01); the R-tree can no longer loop forever (Y-03).
- `nearest()` no longer cuts through a rounding tie at its search radius: a record just outside the
  radius whose distance rounds to the same value (and has the smaller key) was skipped although it sorts
  first.
- Spatial queries: correct results near the antimeridian and the poles (Y-06); `nearest()` with
  `maxDistance` returns the matches it finds (Y-07); `withinDistance()` measures to the nearest point of
  a geometry (a point inside a polygon is at distance 0); `withinPolygon()` accepts MultiPolygon and
  checks edges, not only vertices; queries without hits return immediately (O-24, O-34).
- GeoJSON validation rejects NaN/INF, strings as coordinates, self-intersecting rings and holes outside
  their shell; ring closure is compared numerically.

### Query builder

- `where()` calls are combined with AND (they used to overwrite the same field) (Y-21).
- `orWhere()` without `where()` returns only the records matching an OR group (it returned everything) (Y-20).
- Operators compare by type: numbers (and numeric strings) numerically, strings lexically; `null`,
  booleans and arrays never satisfy `$gt/$gte/$lt/$lte/between` (O-10). Unknown operators and invalid
  operands (`$in` without an array, a broken `$regex`) throw, whether or not there are records (O-11).
- `like()`, `$like` and `$regex` are safe: like patterns are literal except a leading `^` / trailing `$`;
  invalid regular expressions throw `InvalidArgumentException` (Y-19).
- `sort()` is a stable total order (missing/null first, then booleans, numbers, strings, arrays); several
  `sort()` calls sort by several fields (Y-22, O-12).
- `count()`, `withDistance()`, `nearest()`+`where()`, `groupBy()` key types, `search()` (Unicode,
  ignores `key`) fixed (O-01, O-04..O-08). `withDistance()` measures like `withinDistance()`/`nearest()`
  (0 inside a polygon, else to the nearest point of the geometry); it used the centroid, so a polygon
  could pass a 1 km filter and show a larger `_distance`.
- The direct `$db->between()` compares like `query()->between()` (it used PHP's loose comparison, so
  e.g. `true` was "between" 100 and 500).
- `removeFields()` removes fields from the stored records only; `select/except/join/withDistance`
  output never reaches storage (K-04, Y-26).
- `update()`, `delete()` and `removeFields()` run selection and write under one lock; the reads of one
  query (`get()`, `count()`, aggregates) run under one shared lock, so they see one state of the database.

### Other

- `getDBs()` lists each database once, includes sharded databases with their total size (O-03).
- `limit([])` and `sort([])` return `[]` (D-05). Typo "paramters" fixed (D-06).
- Removed dead code (legacy `.idx` key index, unused helpers); the query builder no longer uses Reflection.
- Examples use `examples/_bootstrap.php`, run from any directory and can be run repeatedly.
- Distance and centroid memo caches keep working when full (the older half is dropped) (D-08).
- Docs: `join()` example corrected (the record is attached under the foreign database's name), and the
  docs were checked against the code (return shapes, error messages, `whereNot()`/`notBetween()`
  semantics, spatial distance options).
- Benchmark scripts (`tests/*_benchmark.php`, `tests/sleekdb_comparison.php`) use their own temporary
  directory and key; `performance_benchmark.php` and `sleekdb_comparison.php` used to delete every file
  in the project's `db/` directory. Sizes can be passed on the command line (`php tests/performance_benchmark.php 100,1000`).
  New `tests/operation_benchmark.php` measures single-operation latency and can compare two versions;
  `docs/BENCHMARKS.md` was re-measured (3.2 vs 3.1 on the same machine).

### Test Results

- **PHP 8.5 compatible.** Tested on PHP 7.4, 8.2, 8.4 and 8.5 (Linux and macOS); CI runs 7.4 to 8.5.
  PHPUnit now fails on deprecations, so a new PHP version's deprecations show up in CI. The tests no
  longer call `Reflection*::setAccessible()` on PHP 8.1+ (deprecated in 8.5), tests that kill child
  processes don't go through a shell (with dash as `/bin/sh` the shell was killed and the child kept
  running), and the dev mode test runs in its own process (its constant leaked into later tests).
- **1084 tests** (about 19,000 assertions), new suite `tests/Regression` (Regression):
  one or more tests per review finding, multi-process tests, real `SIGKILL` crash tests, differential
  fuzzing against an independent model, lock-state tests.
- Expectations of existing tests changed only where a documented behaviour change requires it
  (database name validation, secretKey validation, `limit([])`/`sort([])`, `insert([])`, `orWhere()` and
  repeated `where()` semantics).

---


## v3.1.0 (2025-12-29)

### Major: Spatial Indexing + MongoDB-Style Comparison Operators

This release introduces **R-tree spatial indexing** for geospatial queries and **MongoDB-style comparison operators** for advanced filtering.

---

### Part 1: R-tree Spatial Indexing

#### GeoJSON Support

Full GeoJSON geometry support:
- **Point** - Single location `[lon, lat]`
- **LineString** - Path/route
- **Polygon** - Area with boundary (supports holes)
- **MultiPoint**, **MultiLineString**, **MultiPolygon**
- **GeometryCollection**

```php
// Insert GeoJSON data
$db->insert("restaurants", [
    'name' => 'Ottoman Kitchen',
    'location' => [
        'type' => 'Point',
        'coordinates' => [28.9784, 41.0082]  // [longitude, latitude]
    ]
]);
```

#### Spatial Index Management

```php
// Create R-tree index
$db->createSpatialIndex("restaurants", "location");
// Returns: ["success" => true, "indexed" => 150]

// Check if index exists
$db->hasSpatialIndex("restaurants", "location");  // true/false

// List all spatial indexes
$db->getSpatialIndexes("restaurants");  // ["location"]

// Drop index
$db->dropSpatialIndex("restaurants", "location");

// Rebuild index
$db->rebuildSpatialIndex("restaurants", "location");
```

#### Spatial Query Methods

All distance parameters and results are in **meters**.

```php
// Find within radius
$nearby = $db->withinDistance("restaurants", "location", 28.97, 41.00, 5000);  // 5000 meters

// Find in bounding box
$inArea = $db->withinBBox("restaurants", "location", 28.97, 41.00, 29.00, 41.03);

// Find K nearest
$closest = $db->nearest("restaurants", "location", 28.97, 41.00, 10);

// Find within polygon
$inPolygon = $db->withinPolygon("restaurants", "location", $polygon);
```

#### Query Builder Integration

```php
$results = $db->query("restaurants")
    ->withinDistance('location', 28.97, 41.00, 5000)  // 5000 meters
    ->where(['open_now' => true])
    ->withDistance('location', 28.97, 41.00)  // _distance field in meters
    ->sort('_distance', 'asc')
    ->limit(10)
    ->get();
```

#### Spatial Index File Structure

```
hash-dbname.nonedb.sidx.location      # R-tree spatial index
hash-dbname.nonedb.gsidx.location     # Global spatial index (sharded)
```

---

### Part 2: MongoDB-Style Comparison Operators

#### Operator Reference

| Operator | Description | Example |
|----------|-------------|---------|
| `$gt` | Greater than | `['age' => ['$gt' => 18]]` |
| `$gte` | Greater than or equal | `['price' => ['$gte' => 100]]` |
| `$lt` | Less than | `['stock' => ['$lt' => 10]]` |
| `$lte` | Less than or equal | `['rating' => ['$lte' => 5]]` |
| `$eq` | Equal (explicit) | `['status' => ['$eq' => 'active']]` |
| `$ne` | Not equal | `['role' => ['$ne' => 'guest']]` |
| `$in` | Value in array | `['category' => ['$in' => ['a', 'b']]]` |
| `$nin` | Value not in array | `['tag' => ['$nin' => ['spam']]]` |
| `$exists` | Field exists | `['email' => ['$exists' => true]]` |
| `$like` | Pattern match | `['name' => ['$like' => '^John']]` |
| `$regex` | Regular expression | `['email' => ['$regex' => '@gmail.com$']]` |
| `$contains` | Array/string contains | `['tags' => ['$contains' => 'featured']]` |

#### Usage Examples

```php
// Range query
$results = $db->query("products")
    ->where([
        'price' => ['$gte' => 100, '$lte' => 500],
        'stock' => ['$gt' => 0]
    ])
    ->get();

// Multiple operators
$results = $db->query("users")
    ->where([
        'role' => ['$in' => ['admin', 'moderator']],
        'status' => ['$ne' => 'banned'],
        'email' => ['$exists' => true]
    ])
    ->get();

// Combined with spatial queries
$results = $db->query("restaurants")
    ->withinDistance('location', 28.97, 41.00, 5000)  // 5000 meters
    ->where([
        'rating' => ['$gte' => 4.0],
        'cuisine' => ['$in' => ['turkish', 'italian']]
    ])
    ->get();
```

---

### Part 3: R-tree Performance Optimizations

| Optimization | Description |
|--------------|-------------|
| **Parent Pointer Map** | O(1) parent lookup instead of O(n) tree scan |
| **Linear Split Algorithm** | O(n) seed selection instead of O(n²) quadratic split |
| **Dirty Flag Pattern** | Single disk write per batch instead of n writes |
| **Distance Memoization** | Cached Haversine distance calculations |
| **Centroid Caching** | Cached geometry centroid calculations |
| **Node Size 32** | Fewer tree levels and splits (increased from 16) |
| **Adaptive nearest()** | Exponential radius expansion for efficient k-NN |

#### Performance Results

| Operation | 100 | 1K | 5K |
|-----------|-----|-----|-----|
| createSpatialIndex | 2.4 ms | 81 ms | 423 ms |
| withinDistance (10km) | 3 ms | 32 ms | 166 ms |
| withinBBox | 0.7 ms | 7 ms | 38 ms |
| nearest(10) | 2 ms | 2 ms | 2.4 ms |

#### Comparison Operator Performance

| Operator | 100 | 1K | 5K |
|----------|-----|-----|-----|
| `$gt`, `$gte`, `$lt`, `$lte` | 0.7 ms | 6-7 ms | 33-38 ms |
| `$in`, `$nin` | 0.7 ms | 6.5-7 ms | 36-37 ms |
| `$like`, `$regex` | 0.7 ms | 7 ms | 39 ms |
| Complex (4 operators) | 0.7 ms | 7.5 ms | 43 ms |

---

### New Methods

#### Spatial Index Methods (noneDB)
- `createSpatialIndex($dbname, $field)` - Create R-tree index
- `hasSpatialIndex($dbname, $field)` - Check if index exists
- `getSpatialIndexes($dbname)` - List all spatial indexes
- `dropSpatialIndex($dbname, $field)` - Remove index
- `rebuildSpatialIndex($dbname, $field)` - Rebuild index
- `withinDistance($dbname, $field, $lon, $lat, $meters)` - Find within radius (meters)
- `withinBBox($dbname, $field, $minLon, $minLat, $maxLon, $maxLat)` - Find in bbox
- `nearest($dbname, $field, $lon, $lat, $k)` - Find K nearest
- `withinPolygon($dbname, $field, $polygon)` - Find in polygon
- `validateGeoJSON($geometry)` - Validate GeoJSON

#### Query Builder Methods (noneDBQuery)
- `withinDistance($field, $lon, $lat, $meters)` - Spatial: within radius (meters)
- `withinBBox($field, $minLon, $minLat, $maxLon, $maxLat)` - Spatial: within bbox
- `nearest($field, $lon, $lat, $k)` - Spatial: K nearest
- `withinPolygon($field, $polygon)` - Spatial: within polygon
- `withDistance($field, $lon, $lat)` - Add `_distance` field to results (meters)

#### Comparison Operators in where()
- `$gt`, `$gte`, `$lt`, `$lte` - Numeric comparisons
- `$eq`, `$ne` - Equality comparisons
- `$in`, `$nin` - Array membership
- `$exists` - Field existence
- `$like` - Pattern matching (case-insensitive)
- `$regex` - Regular expression matching
- `$contains` - Array/string contains

---

### Test Results

- **970 tests, 3079 assertions** (all passing)
- 42 new comparison operator tests
- 24 new spatial + operator combination tests
- 48 new query documentation tests
- Full sharded spatial index support verified

### Documentation

New documentation files in `docs/`:
- `QUERY.md` - Complete query builder reference
- `SPATIAL.md` - Spatial indexing guide
- `CONFIGURATION.md` - Configuration options
- `API.md` - Complete API reference
- `BENCHMARKS.md` - Performance benchmarks

### Breaking Changes

None. Spatial indexing is a new feature in v3.1.0.

**Note:** All spatial distance parameters and results use **meters** as the unit:
- `withinDistance()` - distance parameter in meters
- `_distance` field in results is in meters
- `nearest()` `maxDistance` option is in meters

---

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

**Backwards Compatibility:** Databases created with any previous version (v1.x `{"data":[...]}` or v2.x JSON array format) are automatically migrated to the new JSONL format on first access. Your existing data is preserved - just upgrade and go.

**How it works:**
1. Old format detected (`{"data": [...]}` or JSON array)
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
