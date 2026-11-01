# noneDB API Reference

Every public method of noneDB 4.0.0. The query builder (`$db->query()`) is in [QUERY.md](QUERY.md), the spatial
queries in [SPATIAL.md](SPATIAL.md), the settings in [CONFIGURATION.md](CONFIGURATION.md).

**Database names** may contain `A-Z a-z 0-9`, space, `'` and `-`. Anything else throws
`InvalidArgumentException`, a line feed at the end too; a name is never changed silently. `"0"` is a valid name.
`checkDB()` answers `false` for `''`, `null` and `false` without throwing.

**Locking.** An operation locks its database for its whole duration: shared for reads, exclusive for writes.
A read of a database that does not exist takes no database lock (only the store's, shared) and creates nothing. Joins lock every database of the
query at once. Waiting longer than `lockTimeout` seconds throws `RuntimeException` with code
`noneDB::LOCK_FAILED`.

**A write that throws leaves no record of itself behind.** When `insert()`, `update()`, `delete()` or a query
builder write throws, the lines it appended are cut back, together with writes nested in it on the same
database (from an error handler, for example). The details:
- Records flushed from the write buffer before the call's own writes stay written: they were acknowledged.
- In a sharded database the keys it reserved stay used and the shards it created stay listed, empty: the next
  insert gets a later key.
- Work a write triggers after it committed (compaction, the switch to shards, a buffer flush) is not part of
  it. If that work fails, the write still succeeds; the failure is logged (`deferred-failed`) and the work runs
  again later.
- Operations that replace or remove files (`dropDB()`, `compact()`, `convertToSharded()`, `restore()`), and
  `backup()`, refuse to run inside a write of the same database from the write's first step, before its first append
  too (a value's `jsonSerialize()` runs there): `LogicException`, no file touched. Call them after the write.
- The outcomes of a write are in [DURABILITY.md](DURABILITY.md) §2: committed, rolled back, or unknown
  (`noneDB::OUTCOME_UNKNOWN`).

**Options are the documented ones.** A method that takes an options array (`createSpatialIndex()`,
`withinDistance()`, `nearest()`, `restore()`, `migrateKey()`, `registerKey()`, `repairStore()`) accepts exactly the
options its section names, of the types it names. Anything else is an `InvalidArgumentException` before the method
does anything, also from the static key maintenance methods: the message names the option, suggests the one it was
probably meant to be, and lists them. A misspelled `abort` can't let a rotation go on.

The examples below run in order against one store (CI runs them).

```php
$db = new noneDB(); // reads .nonedb (CONFIGURATION.md)
```

---

## Contents

1. [Databases](#databases)
2. [Records](#records)
3. [Aggregation and helpers](#aggregation-and-helpers)
4. [Sharding and compaction](#sharding-and-compaction)
5. [Field indexes](#field-indexes)
6. [Spatial indexes](#spatial-indexes)
7. [Write buffer](#write-buffer)
8. [Durability, integrity and metrics](#durability-integrity-and-metrics)
9. [Backup and restore](#backup-and-restore)
10. [Key maintenance](#key-maintenance)
11. [Static helpers](#static-helpers)
12. [Errors](#errors)

---

## Databases

### createDB() / checkDB()

```php
$db->createDB("users");   // true: created
$db->createDB("users");   // false: it exists
$db->checkDB("users");    // true; creates a missing database when autoCreateDB is on, else false
```

### getDBs()

```php
$db->getDBs(false);       // ["users"]: names only, sorted
$db->getDBs(true);        // [["name" => "users", "createdTime" => 1793291271, "size" => "185 B"], ...]
$db->getDBs("users");     // ["name" => "users", "createdTime" => 1793291271, "size" => "185 B"]; false when missing
```

Only the databases of this instance's `secretKey` are listed. `size` is the size of the data files, with a
comma as decimal separator (`"2,5 KB"`). A database whose directory holds shard files its shard list doesn't
name lists them as `orphanFiles` (set aside as `*.orphan.{YmdHis}`, never deleted automatically).

### dropDB()

```php
$db->createDB("old");
$db->dropDB("old");       // ["success" => true, "files" => 5]
$db->dropDB("old");       // ["success" => false, "files" => 0, "error" => "Database does not exist"]
```

Removes every file of the database except its lock file (STORAGE_FORMAT.md §8.2).

---

## Records

### insert()

```php
$db->insert("users", ["name" => "John", "email" => "john@example.com"]);   // ["n" => 1]
$db->insert("users", [["name" => "Jane", "age" => 31], ["name" => "Ali", "age" => 27]]); // ["n" => 2]
$db->insert("users", ["name" => "Ayşe", "address" => ["city" => "Istanbul"]]);
```

Invalid input returns an `error` and writes nothing:

```php
$db->insert("users", ["key" => 5]);         // ["n" => 0, "error" => "You cannot set key name to key"]
$db->insert("users", "not an array");       // ["n" => 0, "error" => "insert data must be array"]
$db->insert("users", []);                   // ["n" => 0, "error" => "insert data must not be empty"]
$db->insert("users", [0 => ["a" => 1], "name" => "x"]); // "insert data must be either one record or a list of records"
$db->insert("users", ["v" => NAN]);         // "insert data cannot be encoded as JSON (invalid UTF-8, NAN/INF or unsupported value)"
```

Keys are assigned in order and **never reused or changed**: not by a delete, not by `compact()`, not by a
`restore()` of an older backup. Floats keep their type (`2.0` reads back as `2.0`).

### find()

```php
$db->find("users", 0);                       // every record
$db->find("users", ["name" => "John"]);      // by field
$db->find("users", ["name" => "Jane", "age" => 31]); // several fields: all must match
$db->find("users", ["key" => 0]);            // by key
$db->find("users", ["key" => [0, 1, 2]]);    // by keys, in this order
```

**Matching** (shared by `find()`, `update()` and `delete()`):
- every filter field must exist in the record and be **identical** (`===`): `"25"` does not match `25`;
  `null` matches only a field that exists with the value `null`; arrays must be equal as a whole;
- `key` takes an integer, a digit string or a list of them, and is combined with the other fields (AND);
- `[]` selects every record. `find()` (and `count()`, `sum()`, `first()`, ...) also take an integer (normally
  `0`) or `null` for every record. Any other non-array filter matches nothing: `find()` returns `false`,
  `count()`/`sum()`/`avg()` 0, `min()`/`max()`/`first()`/`last()` `null`, `exists()`/`between()` `false`.

Records come back with their `key`, in key order (or in the order of a `key` list). A database that does not
exist gives `[]` (`false` with `autoCreateDB` off); a read never creates one.

### update()

```php
$db->update("users", [["name" => "John"], ["set" => ["active" => true]]]);       // ["n" => 1]
$db->update("users", [["key" => [0, 1]], ["set" => ["status" => "checked"]]]);
$db->update("users", [[], ["set" => ["updated_at" => time()]]]);                 // every record
```

The first element is a filter, `set` an array of fields other than `key`; anything else returns
`["n" => 0, "error" => "Please check your update parameters"]`. A `set` that can't be encoded as JSON returns the
error `update data cannot be encoded as JSON (invalid UTF-8, NAN/INF or unsupported value)`. An empty `set` changes
nothing and returns the number of matching records.

### delete()

```php
$db->delete("users", ["name" => "Ali"]);     // ["n" => 1]
$db->delete("users", ["key" => [99]]);       // ["n" => 0]
$db->delete("users", 0);                     // ["n" => 0, "error" => "Please check your delete parameters"]
```

`delete("users", [])` deletes every record: an empty filter matches everything.

---

## Aggregation and helpers

```php
$db->insert("orders", [["amount" => 120, "status" => "paid", "city" => "Izmir"],
                       ["amount" => 80, "status" => "open", "city" => "Ankara"],
                       ["amount" => 45, "status" => "paid", "city" => "Izmir"]]);
$db->count("orders", 0);                         // 3
$db->count("orders", ["status" => "paid"]);      // 2
$db->sum("orders", "amount");                    // 245
$db->sum("orders", "amount", ["status" => "paid"]); // 165
$db->avg("orders", "amount");                    // 81.666...
$db->min("orders", "amount");                    // 45
$db->max("orders", "amount");                    // 120
$db->distinct("orders", "city");                 // ["Izmir", "Ankara"]
$db->first("orders");                            // the record with the lowest key
$db->last("orders", ["status" => "paid"]);       // the paid record with the highest key
$db->exists("orders", ["city" => "Ankara"]);     // true
$db->between("orders", "amount", 50, 150);       // records with 50 <= amount <= 150
$db->between("orders", "amount", 50, 150, ["status" => "paid"]);
$db->like("users", "email", "example.com$");     // ends with
```

- `min()`/`max()` use the total order of `sort()`: missing/`null` < booleans < numbers < numeric strings (by
  number) < other strings (byte order) < arrays. `max([50, "10"])` is `"10"`.
- `distinct()` keeps the order of first appearance and tells types apart (`1`, `1.0` and `"1"` are three
  values); `null` and missing fields are left out.
- `like()`: only a leading `^` and a trailing `$` are special; case-insensitive with Unicode case folding
  (QUERY.md). Strings and numbers are matched; booleans, `null` and arrays never are.

Helpers on arrays of records:

```php
$records = $db->find("orders", 0);
$db->sort($records, "amount", "desc");   // stable, the total order above; false for a non-array
$db->limit($records, 2);                 // the first 2; false for invalid arguments
```

---

## Sharding and compaction

A database switches to the sharded layout once it has assigned `shardSize` keys (`shardingEnabled`, on by default):
shard N holds the keys `[N × shardSize, (N + 1) × shardSize)`. The shard size is fixed when the database is
created.

```php
$db->getShardInfo("orders");
// ["sharded" => false, "shards" => 0, "totalRecords" => 3, "shardSize" => 10000, "nextKey" => 3]
// sharded: ["sharded" => true, "shards" => 2, "totalRecords" => 5, "deletedCount" => 0, "shardSize" => 4, "nextKey" => 5]
// false when the database doesn't exist

$db->compact("orders");
// ["success" => true, "freedSlots" => 0, "totalRecords" => 3, "sharded" => false]
// sharded databases add "newShardCount"; a missing one: ["success" => false, "freedSlots" => 0, "status" => "database_not_found"]

$db->convertToSharded("orders"); // ["success" => true, "status" => "converted"] (or "already_sharded")
$db->isShardingEnabled();      // true
$db->getShardSize();           // 10000: the setting for databases created from now on
```

- `nextKey` is the key the next insert gets.
- `compact()` rewrites data files without deleted and superseded lines. Keys don't change; shards that became
  empty are removed, except the one of the newest key. Usually not needed: compaction runs by itself
  (`autoCompactThreshold`).
- `convertToSharded()` switches a database to the sharded layout now (a generation switch: STORAGE_FORMAT.md
  §5.2). A database that doesn't exist: `["success" => false, "status" => "database_not_found"]`.

---

## Field indexes

```php
$db->createFieldIndex("users", "email");     // ["success" => true, "indexed" => 1, "error" => null] (+ "shards" => N)
$db->hasFieldIndex("users", "email");        // true
$db->getFieldIndexes("users");               // ["fields" => ["email"], "sharded" => false]
$db->getKeysFromFieldIndex("users", "email", "john@example.com"); // [0]; null without an index
$db->rebuildFieldIndex("users", "email");    // as createFieldIndex()
$db->dropFieldIndex("users", "email");       // ["success" => true, "error" => null]; without one: "Index does not exist"
```

- `indexed` counts the records whose value can be indexed (a scalar or `null`). An index keeps values by type,
  as `find()` compares them with `===`: `1`, `1.0` and `"1"` are three values.
- A field name is any non-empty string except `key`; names that differ in letter case get their own indexes.
- An index always gives the results of a full scan. A write appends to the data file only; an index replays
  the lines after the bytes it covers when it is used.
- `getKeysFromFieldIndex()` takes a shard number as a fourth argument (sharded databases).

---

## Spatial indexes

```php
$db->insert("places", [["name" => "Galata", "loc" => ["type" => "Point", "coordinates" => [28.9741, 41.0256]]],
                       ["name" => "Kadikoy", "loc" => ["type" => "Point", "coordinates" => [29.0275, 40.9906]]]]);
$db->createSpatialIndex("places", "loc");    // ["success" => true, "indexed" => 2, "error" => null]
$db->createSpatialIndex("places", "loc");    // ["success" => false, "indexed" => 0, "error" => "Spatial index already exists for field 'loc'"]
$db->hasSpatialIndex("places", "loc");       // true (a shard number as third argument: that shard)
$db->getSpatialIndexes("places");            // ["loc"]
$db->rebuildSpatialIndex("places", "loc");   // dropped and made again from the data
$db->withinDistance("places", "loc", 28.97, 41.02, 2000);   // within 2 km, nearest first
$db->withinBBox("places", "loc", 28.9, 40.9, 29.1, 41.1);
$db->nearest("places", "loc", 28.97, 41.02, 1);             // [Galata with "_distance" => 711.37] (meters)
$db->withinPolygon("places", "loc", ["type" => "Polygon", "coordinates" => [[[28.9, 41.0], [29.0, 41.0], [29.0, 41.1], [28.9, 41.1], [28.9, 41.0]]]]);
$db->validateGeoJSON(["type" => "Point", "coordinates" => [200, 0]]); // ["valid" => false, "error" => "...", "type" => null]
$db->haversineDistance(28.97, 41.00, 28.98, 41.00);                    // 839.2 (meters)
$db->dropSpatialIndex("places", "loc");      // ["success" => true, "error" => null], also without an index
```

Records whose field is not valid GeoJSON are not indexed (`indexed` counts the others).
`createSpatialIndex($db, $field, ["nodeSize" => 16])` sets the R-tree node size (an integer of at least 4, default
32). `withinDistance()` takes `includeDistance` (bool, default false), `nearest()` takes `maxDistance` (meters, a
number or `null`) and `includeDistance` (bool, default true).
Options, rules and the query builder integration: [SPATIAL.md](SPATIAL.md).

---

## Write buffer

Off by default. With the buffer on, inserts go to a buffer file first and reach the data files in batches;
buffered records are flushed before the next read or write of the database, so they are always visible.

```php
$db->enableBuffering(true);
$db->isBufferingEnabled();          // true
$db->setBufferSizeLimit(1048576);   // bytes (at least 1 KB)
$db->setBufferCountLimit(10000);    // records (at least 10)
$db->setBufferFlushInterval(30);    // seconds, 0: no flush by time
$db->insert("events", ["type" => "login"]);
$db->getBufferInfo("events");       // ["enabled" => true, "sizeLimit" => ..., "countLimit" => ..., "flushInterval" => 30,
                                    //  "buffers" => ["main" => ["exists" => true, "size" => 17, "records" => 1]]]
$db->flush("events");               // ["success" => true, "flushed" => 1, "error" => null]
$db->flushAllBuffers();             // ["databases" => 0, "flushed" => 0]
$db->enableBuffering(false);
```

Buffered records get their keys when they are flushed. A buffered insert is durable once it returns, like a
direct one (DURABILITY.md).

---

## Durability, integrity and metrics

### getDurability()

```php
$db->getDurability();
// ["mode" => "performance",
//  "platform" => ["os" => "Linux", "durableSupported" => true, "notes" => []],
//  "lastOperation" => ["op" => "flush", "db" => "events", "outcome" => "committed", "required" => [], "completed" => [], "reason" => null],
//  "lastSyncError" => null,
//  "syncs" => ["file" => 7, "dir" => 7, "failed" => 0]]
```

The contract, the outcomes and every field: [DURABILITY.md](DURABILITY.md) §6.

### checkIntegrity()

```php
$db->checkIntegrity("users");
// ["ok" => true, "database" => "users", "exists" => true, "segments" => 1, "lines" => 3, "records" => 3,
//  "badLines" => [], "tornTail" => [], "indexMismatches" => [], "bufferedRecords" => 0,
//  "pendingRecovery" => false, "quarantineFiles" => [], "recoveryLog" => [...]]
```

A full check of the database against its data, read-only: every line is decoded; the key index, the field and
spatial indexes of each segment and the shard list are compared with the data. Nothing is created, changed, cut or flushed.
- `badLines`: `[segment, offset, length]` of lines that don't decode;
- `tornTail`: an unterminated last line (a crashed append), with its segment, offset and length;
- `indexMismatches`: entries that disagree with the data, and generations other than the active one;
- `pendingRecovery`: the state record says recovery is due (the next read or write runs it);
- `quarantineFiles`, `recoveryLog`: the quarantine files and the newest 20 events.

A damaged database and an interrupted generation switch throw `FORMAT_DAMAGED` here too; the next read or write
repairs what it can prove. `rebuildIndex()` repairs index mismatches.

### getRecoveryLog()

```php
$db->getRecoveryLog("users", 100);  // the newest 100 events, oldest first: [["t" => ..., "action" => ..., ...], ...]
```

| Action | What happened |
|---|---|
| `cut-tail`, `drop-line`, `drop-buffer` | Bytes removed from a data or buffer file, kept in a verified quarantine file (`quarantine`) |
| `salvaged`, `skip-line` | A damaged line found while an index was rebuilt: the record at its end was recovered, or the line skipped |
| `meta-rebuilt` | `shards.json` rebuilt from the shard files |
| `index-dropped` | An index that couldn't be rebuilt was dropped (queries scan; it is made again later) |
| `deferred-failed` | Work after a committed write failed; it runs again |
| `format-repaired` | FORMAT rewritten from the proof of CURRENT and the generation's metadata |
| `generation-recovered`, `generation-cleanup-failed` | An interrupted generation switch finished, or its obsolete generation couldn't be removed |
| `restore`, `foreign-restore` | A `restore()`, of the database's own backup or of another one with `force` |

Events never hold record values or key material. Reads never write the log: what a read found is listed
with `"persisted" => false` until a write session of the same process logs it.

### getMetrics()

```php
$db->getMetrics();          // ["process" => [...]]
$db->getMetrics("users");   // ["process" => [...], "database" => [...]]
```

Counters of the process (index cache hits, misses and evictions, index pages read, checkpoints, compactions,
lock waits and timeouts, recovery events, durability, the last `checkIntegrity()`) and a database's metadata
(identity, counters, sizes by class, state). Nothing is scanned or written. Every member:
[OPERATIONS.md](OPERATIONS.md) §1.

### rebuildIndex() / getIndexInfo()

```php
$db->rebuildIndex("users");  // ["success" => true, "totalRecords" => 3, "time" => "0.3ms"]
$db->getIndexInfo("users");  // ["exists" => true, "version" => 1, "created" => ..., "updated" => ..., "totalRecords" => 3,
                             //  "sharded" => false, "path" => ".../segments/main.kix"]; null when missing
```

`rebuildIndex()` makes the key indexes again from the data files, and the field and spatial indexes of every
segment and the global ones. Normal operation never needs it; it is a repair tool.

---

## Backup and restore

```php
$backup = __DIR__ . '/backups/users-' . date('Ymd-His'); // outside dbDir
$db->backup("users", $backup);
// ["database" => "users", "dir" => "/var/www/app/backups/users-20261029-142233", "databaseId" => "...", "generation" => 1,
//  "nextKey" => 4, "records" => 3, "bufferedRecords" => 0, "files" => 6, "bytes" => 1688]

$db->insert("users", ["name" => "after the backup"]);
$db->restore($backup, "users");
// ["database" => "users", "generation" => 2, "nextKey" => 5, "records" => 3, "bufferedRecords" => 0,
//  "target" => "present", "foreign" => false]
```

- `backup($db, $dir)` copies one database as it is at one moment into `$dir`: a directory outside `dbDir` that
  doesn't exist or is empty (0700, files 0600). `manifest.json` is written last; `store.json` is the store
  record, usable by `repairStore()`. Writers of the database wait while it runs, other databases don't; key
  maintenance fails with `MAINTENANCE_BUSY`. The buffer is copied, not flushed: nothing of the database is
  written.
- `restore($dir, $db, ['force' => false])` makes the backup the content of the database with one commit: after
  a crash it opens as it was or as restored. New records get keys above every key the database handed out
  (`nextKey` 5 above, not 4).
- A backup of another database is `RESTORE_REFUSED` unless `['force' => true]`, which loads it under this
  database's identity. An incomplete or damaged backup is always refused; nothing changes.
- `target`: `present`, `absent` (the database is created), or `damaged` (`restore()` is the way out of
  `FORMAT_DAMAGED`; the damaged generations are kept for inspection).

Procedures, the directory layout and the rules: [BACKUP_RESTORE.md](BACKUP_RESTORE.md).

---

## Key maintenance

Static methods for the store in a directory; each returns `success`, `error` and `code` instead of throwing,
and `storeManifest`: the complete store record (keep it; `repairStore()` restores it).

<!-- doctest: skip -->
```php
noneDB::registerKey($dbDir, $registeredKey, $newKey);
// ["success" => true, "storeManifest" => [...], "error" => null, "code" => null]

noneDB::migrateKey($dbDir, $oldKey, $newKey);             // ['abort' => true] undoes an interrupted one
// ["success" => true, "databases" => ["users", "orders"], "storeManifest" => [...], "error" => null, "code" => null]

noneDB::repairStore($dbDir, [$key1, $key2], ['manifest' => '/backups/users/store.json']);
// ["success" => true, "mode" => "manifest", "complete" => true, "storeManifest" => [...], "error" => null, "code" => null]
```

- `registerKey()` lets a second key open the store; `$registeredKey` must be registered already.
- `migrateKey()` rotates every database of `$oldKey` to `$newKey` (their directories are renamed; the contents
  don't change). Stop the application processes first: a database in use is `MAINTENANCE_BUSY` and nothing is
  written. An interrupted rotation is finished by running it again.
- `repairStore()` is only for a store whose two record copies are both invalid. With a manifest (a backup's
  `store.json`, a `storeManifest`) the record comes back complete; without one only
  `['confirmManualRebuild' => true]` rebuilds it from the given keys (`complete` false). Every database
  directory must belong to one of the keys.
- `['durability' => 'durable']` syncs every step as durable mode does.
- The options: `migrateKey()` `abort` (bool) and `durability`; `registerKey()` `durability`; `repairStore()`
  `manifest` (a file path or a store record array), `confirmManualRebuild` (bool) and `durability`.

Order of the steps and what happens after a crash: STORAGE_FORMAT.md §9; procedures: OPERATIONS.md.

---

## Static helpers

```php
noneDB::configExists();       // a .nonedb file was found
noneDB::getConfigTemplate();  // path of the template file, or null
noneDB::isDevMode();          // false
noneDB::clearStaticCache();   // drop the process's caches (tests; long-running workers after maintenance)
noneDB::disableStaticCache(); // each instance keeps its own caches
noneDB::enableStaticCache();
```

<!-- doctest: skip -->
```php
noneDB::setDevMode(true);     // no config file needed (development only)
noneDB::clearConfigCache();   // forget the loaded .nonedb (tests)
```

---

## Errors

Invalid input to a CRUD method returns an array with an `error` member (above). Wrong names, settings and
query operators throw `InvalidArgumentException`. States throw `RuntimeException` with a code:

| Code | Constant | Meaning |
|---|---|---|
| 3301 | `OUTCOME_UNKNOWN` | A failure after a commit point, or an undo that failed: read the state again; the next access recovers |
| 3302 | `LOCK_FAILED` | A lock was not taken within `lockTimeout`, or was lost |
| 3304 | `UNSUPPORTED_FORMAT` | `dbDir` holds files that are not noneDB 4 objects: migrate them (MIGRATION.md) or move them |
| 3305 | `FORMAT_DAMAGED` | A store record, FORMAT, CURRENT or generation is damaged and can't be repaired with proof; the data files are kept |
| 3306 | `UNSUPPORTED_DURABILITY` | Durable mode on a platform that can't provide it (only Linux can) |
| 3307 | `DURABILITY_FAILED` | A sync durable mode requires failed before the commit point: rolled back |
| 3308 | `KEY_NOT_REGISTERED` | The configured `secretKey` is not registered for the store |
| 3309 | `MAINTENANCE_IN_PROGRESS` | Key maintenance or a migration is running or was interrupted (run it again) |
| 3310 | `RESTORE_REFUSED` | `restore()` refused the backup; nothing changed |
| 3311 | `MAINTENANCE_BUSY` | Maintenance found a database in use; nothing changed |

`LogicException`: a file of a database written without its exclusive lock (a bug), `update()`/`delete()`/
`removeFields()` together with `groupBy()`, a replacing operation inside an uncommitted write, serializing an
instance. Other I/O failures are plain `RuntimeException`s.

```php
try {
    $db->restore('/nonexistent/backup', "users");
} catch (RuntimeException $e) {
    $e->getCode() === noneDB::RESTORE_REFUSED; // true
}
```
