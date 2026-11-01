# noneDB 4 Operations

**Engine:** noneDB 4.0.0

This document is for the people who run noneDB in production: what to monitor, how to back up and restore,
how to rotate keys, and what to do when the engine refuses to work. File formats are in
[STORAGE_FORMAT.md](STORAGE_FORMAT.md), outcomes of writes in [DURABILITY.md](DURABILITY.md).

---

## 1. Metrics: `getMetrics(?string $db = null)`

`getMetrics()` returns cheap counters of the process and, for a database, its metadata. Nothing is scanned
and nothing is written: the buffer is not flushed, no recovery runs, no checkpoint is written. A full check
of the data is `checkIntegrity()` (§2).

```php
$db = new noneDB();
$db->insert("users", [["name" => "John"], ["name" => "Jane"]]);
$metrics = $db->getMetrics();         // ['process' => [...]]
$metrics = $db->getMetrics("users");  // ['process' => [...], 'database' => [...]]
```

### 1.1 `process`

Counters of the PHP process since it started, for every instance and database it used. They last one request
under a web server (PHP frees them with the request); a long-running CLI process keeps adding to them.

| Key | Meaning |
|---|---|
| `indexCache.hits` | An operation needed an index of a segment (key index, field index, R-tree) or a global index, and the process had it in memory. |
| `indexCache.misses` | It had to read it from disk: the whole file, or the header and first page of a paged field index. |
| `indexCache.evictions` | Cached indexes and data bytes the process dropped to stay under `cacheMemoryLimit` (CONFIGURATION.md). A count that grows with every operation means the limit is too small for the working set. |
| `indexCache.limit` | The cache limit in bytes; 0: none. |
| `indexReads.pages` | 4 KiB pages read from index files: key-index checkpoints, field and global field indexes, R-trees (a whole file counts its pages). |
| `indexReads.bytes` | Bytes read from index files. |
| `checkpoints.written` | Derived files written: key-index checkpoints, field, global and spatial index files. |
| `checkpoints.seconds` | Time spent writing them. |
| `compactions.count` | Segments compacted (each data file replaced, STORAGE_FORMAT.md §6.2). |
| `compactions.seconds` | Time spent compacting them. |
| `locks.acquired` | Locks taken: database locks and `STORE.LOCK`. |
| `locks.waited` | Of these, the ones that had to wait for another process. |
| `locks.waitSeconds` | Time spent waiting. |
| `locks.timeouts` | Waits that ended with `LOCK_FAILED` after `lockTimeout`. |
| `recovery.events` | Events the process logged: a database's recovery log (STORAGE_FORMAT.md §7.1) and the store log (§3). |
| `recovery.actions` | The same, by `action` (for example `cut-tail`, `generation-recovered`, `restore`). |
| `durability` | `mode`, `lastOperation`, `lastSyncError` and `syncs`, as `getDurability()` (DURABILITY.md §6). |
| `integrity` | The last `checkIntegrity()` of the process: `database`, `ok`, `time`, `records`, `issues` (bad lines, torn tails and index mismatches together); `null` before the first. |
| `memory.usage`, `memory.peak` | `memory_get_usage()` and `memory_get_peak_usage()`. |

### 1.2 `database`

Read under the database's shared lock, from its files' metadata: the counters noneDB keeps, and one listing
of its directory for the sizes. A database that does not exist gives `['name' => ..., 'exists' => false]`,
and nothing is created.

| Key | Meaning |
|---|---|
| `name`, `exists` | |
| `databaseId`, `generation`, `layout`, `shardSize` | From FORMAT, CURRENT and the generation's metadata. |
| `segments` | Data files: 1 in the single layout, the shards in the sharded one. |
| `records`, `nextKey` | Live records and the next key, as `getShardInfo()` reports them. |
| `bufferedRecords` | Records in the write buffer, not yet in the data files. |
| `fieldIndexes`, `spatialIndexes` | The indexed fields. |
| `bytes` | Sizes of the database directory's files: `data` (segment data files), `indexes` (checkpoints and index files), `buffer`, `metadata` (FORMAT, CURRENT, LOCK, metadata, shards.json, indexes.json), `recovery` (log and quarantine), `other` (anything else: an unreferenced generation, a set-aside shard file, a temp file), `total`. |
| `state.buffered` | The state record says the buffer may hold records (`b`). |
| `state.recoveryPending` | A crashed writer left work for the next one (`j`, `J`, `c`, `d`). |
| `integrity` | The last `checkIntegrity()` of this database in this process, as in §1.1; `null` before the first. |

What `checkIntegrity()` can't read, `getMetrics()` can't either: a damaged database (the next read or write
repairs what it can prove), or one whose generation switch was interrupted and waits for the next read or write to
recover it, is `FORMAT_DAMAGED`; one under maintenance `MAINTENANCE_IN_PROGRESS`.

```php
$process = $db->getMetrics()['process'];
$database = $db->getMetrics("users")['database'];
$hitRate = $process['indexCache']['hits'] / max(1, $process['indexCache']['hits'] + $process['indexCache']['misses']);
```

**What to watch:**
- `indexCache.evictions` and `misses` growing with every operation of a long-running process: the cache limit
  is too small for the working set (CONFIGURATION.md "The cache").
- `locks.waited` / `locks.timeouts`: writers waiting for each other, or for a backup.
- `recovery.events`: above 0 a crash was recovered, damage was found, or a restore or a shard list rebuild ran;
  `recovery.actions` says which, `getRecoveryLog()` the details.
- `durability.lastSyncError` (durable mode): the disk reported an error.
- `database.bytes.other` above 0: leftovers (§6). `database.bytes.recovery`: quarantine files (§6).
- `database.state.recoveryPending`: a writer died; the next read or write recovers.

## 2. Integrity checks and the recovery log

`checkIntegrity($db)` reads every line of a database and compares its indexes and counters with the data; it
writes nothing. Run it after an unclean shutdown, after restoring files by other means, and from time to time
(it reads the whole database: run it when the load is low). `getRecoveryLog($db)` lists what recovery did
(API.md "getRecoveryLog()").

```php
$report = $db->checkIntegrity("users");
if (!$report['ok']) {
    // badLines, tornTail, indexMismatches, pendingRecovery say what
}
$events = $db->getRecoveryLog("users", 20);
```

| Finding | What it is | What to do |
|---|---|---|
| `tornTail` | An append that a crash cut | Nothing: the next write moves it to quarantine and cuts it |
| `badLines` | Lines that don't decode (damage) | Restore from a backup, or `compact()` (moves them to quarantine) |
| `indexMismatches` (key, field, spatial, meta) | An index or the counters disagree with the data | `rebuildIndex()`: it makes the key indexes again, the field and spatial indexes of every segment and the global ones, and recounts the shard counters |
| `indexMismatches` (`generation`) | A generation other than the active one (§6) | Inspect and remove it (§6) |
| `pendingRecovery` | A crashed writer's journal, an interrupted creation or drop | Any read or write recovers it |

A field index or an R-tree that fits its segment is compared with the records through its own format, with the data lines
after it replayed; one whose pages can't be read counts as a mismatch. One of another epoch is not compared: its next use
rebuilds it. The global field and spatial indexes are made from the segments' files and are not compared.

## 3. Backups

[BACKUP_RESTORE.md](BACKUP_RESTORE.md): online backups of one moment, checks, restores, the store record.

## 4. Keys

A store lists the keys that may open it (STORAGE_FORMAT.md §3.1). Key operations are static methods that take
the store's directory; each returns `storeManifest`, the complete store record: keep the newest one somewhere
safe, `repairStore()` restores it.

<!-- doctest: skip -->
```php
// A second application with a key of its own
$r = noneDB::registerKey('/var/lib/app/nonedb', $key, $otherKey);

// Rotating a key: stop the application processes first
$r = noneDB::migrateKey('/var/lib/app/nonedb', $oldKey, $newKey);
if (!$r['success']) {
    // $r['code']: MAINTENANCE_BUSY (a database is in use: nothing was written), MAINTENANCE_IN_PROGRESS
    // (interrupted after its commit: run it again), FORMAT_DAMAGED (a directory didn't verify: rolled back)
}
file_put_contents('/secure/place/store-manifest.json', json_encode($r['storeManifest']) . "\n");
```

**Rotating a key:**
1. Stop the processes that use the store (a database in use makes the rotation `MAINTENANCE_BUSY`, and nothing
   is written).
2. Run `migrateKey()`. Every database directory of the old key is renamed to the new key's locator; contents
   don't change. A process that still runs with the old key gets `MAINTENANCE_IN_PROGRESS` during the rotation and
   `KEY_NOT_REGISTERED` after it, never an empty database.
3. Put the new key in the configuration; restart long-running processes (FPM workers).
4. Keep the returned `storeManifest`.

An interrupted rotation leaves the store closed (`MAINTENANCE_IN_PROGRESS` for every process) until it is run
again with the same keys: before its commit it continues, or `['abort' => true]` undoes it; after the commit it
only finishes.

**A store whose record is lost** (both copies damaged, `FORMAT_DAMAGED` naming the store record):
`repairStore($dir, $keys, ['manifest' => $storeManifestOrStoreJson])` restores it completely. Without a manifest,
`['confirmManualRebuild' => true]` rebuilds it from the keys given (`complete` false: keys registered before
that have no database are lost). Every database directory must belong to one of the keys, else nothing changes.
One damaged copy is repaired automatically and logged in `.nonedb-maintenance/log.jsonl`.

## 5. When noneDB refuses to work

noneDB fails closed: when it can't prove a state, it stops with a code instead of guessing. The data files are
kept in every case.

| Code | Meaning | What to do |
|---|---|---|
| `UNSUPPORTED_FORMAT` | `dbDir` holds files that are not noneDB 4 objects: databases of noneDB 1.x–3.x, other files, a database directory that is a link. Existing databases keep working; no database is created or answered as missing | Migrate old databases (MIGRATION.md); move other files out; replace a linked database directory by the directory itself |
| `KEY_NOT_REGISTERED` | The configured key is not registered for the store | Use the registered key, or `registerKey()` |
| `MAINTENANCE_IN_PROGRESS` | A key operation or a migration is running or was interrupted (the journal `.nonedb-maintenance/journal.json` exists), or a database is marked by one | Wait, or run the interrupted operation again with the same arguments |
| `MAINTENANCE_BUSY` | A key operation found a database in use or the store locked (a backup holds it shared). Nothing was written | Stop the processes, wait for the backup, run it again |
| `LOCK_FAILED` | A lock wasn't taken within `lockTimeout`: a long holder, or readers that never stop in front of a writer (no queue) | A larger `lockTimeout`, find the long holder (`getMetrics()`: `locks`), retry the write |
| `OUTCOME_UNKNOWN` | A failure after a commit point, or an undo that failed | Read the state again before retrying; the next access recovers |
| `DURABILITY_FAILED` | A required sync failed (durable mode); rolled back | Check the disk (`getDurability()['lastSyncError']`) |
| `FORMAT_DAMAGED` | See below | See below |

**`FORMAT_DAMAGED`** names what can't be proven:
- **The store record** (both `STORE` copies invalid, or the two differ): §4, `repairStore()`.
- **A database's `CURRENT`** is invalid, or an interrupted generation switch left a `CURRENT` that names neither of
  its two generations: noneDB can't tell which generation was committed, so it removes neither.
  - **The way out:** `restore()` from a backup (BACKUP_RESTORE.md §4, "Over a damaged database"). The damaged
    generations stay in the directory for inspection.
  - **Without a backup, by hand** (with every process stopped):
    1. Copy the whole database directory `{dbDir}/{locator}.ndb4/` away first.
    2. In `generations/`, each numbered directory is a complete generation. Its `metadata` names its number, and
       `segments/` holds its data (JSON lines). Pick the generation to keep: usually the higher number, the
       switch's target; check its data files are complete (every line ends with a newline).
    3. Write `CURRENT` for it: the single line
       `{"magic":"NONEDB4","kind":"current","databaseId":"<FORMAT's databaseId>","generation":<N>,"checksum":"<sha256>"}`
       where the checksum is the SHA-256 of the line without its `,"checksum":...` member, followed by `}` and a
       newline (STORAGE_FORMAT.md §2, §4.3).
    4. Replace the state record of `LOCK` (its first 128 bytes; don't delete the file) with `1:`, 8 new
       lowercase hex digits (a new epoch), ` b` when the kept generation's `buffer/` holds a file, and ` J`
       (recount the counters), padded with spaces to 128 bytes: for example `1:5e0c7a21 b J` (STORAGE_FORMAT.md
       §4.2).
    5. Read the database once (until then `checkIntegrity()` says `pendingRecovery`), run `checkIntegrity()`; then
       remove the other generation's directory.
- **A database's `FORMAT`** is invalid and `CURRENT` and its generation's metadata don't prove the identity: as for
  `CURRENT`, `restore()` is the way out (it rewrites `FORMAT`).

## 6. Leftovers

Files noneDB keeps and never removes on its own, because nothing proves they are garbage:

| Leftover | Where | When it can go |
|---|---|---|
| An unreferenced generation | `generations/{N}/` other than `CURRENT`'s: an interrupted generation switch, or the damaged generations a restore kept | Once you have checked it holds nothing you need (§5). `checkIntegrity()` reports it (`generation` mismatch); `getMetrics()` counts it under `bytes.other` |
| A set-aside shard file | `segments/s{N}.jsonl.orphan.{YmdHis}`: a shard file the shard list didn't name, found when that shard was created again | After inspection; `getDBs($name)` lists it (`orphanFiles`) |
| Quarantine files | `recovery/q.*.bin`: bytes cut from a data or buffer file (a torn tail, a damaged line), verified copies | When the recovery log's events about them are understood; `dropDB()` removes them with the database |
| Retained sources of a migration | `.nonedb-retained/{runId}/` | `noneDB-migrate.php --dir=… --backup-dir=… --cleanup=RUNID`, with the key set (MIGRATION.md) |

Remove leftovers only while no process uses the database.

## 7. Deployment checklist

- PHP 8.1–8.5 with `json` (and `mbstring` for Unicode case folding in `like`).
- `dbDir` on a local file system, outside the web root; owned by the user PHP runs as. Files `0640`,
  directories `0750` by default (`filePermissions`, `directoryPermissions`); group access for a second user
  (CLI, cron) works through the group.
- `durability`: `durable` on Linux where a power loss must not lose acknowledged writes (every write waits for
  the disk); `performance` otherwise.
- `lockTimeout` that covers your longest write and your backups.
- `cacheMemoryLimit`: the default (half of `memory_limit`) for web requests; more for long-running workers on
  large databases.
- `autoCreateDB` `false` in production.
- Backups (BACKUP_RESTORE.md) and a restore drill.
- Monitoring: `getMetrics()` (§1), `checkIntegrity()` after unclean shutdowns (§2).
- After key maintenance, restart long-running processes.
- A new version of noneDB: stop every process of the old one first. Two versions on one store rewrite each other's index files.
- Upgrading from noneDB 1.x–3.x: MIGRATION.md (the engine refuses the old files until they are migrated).
