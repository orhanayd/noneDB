# Migrating to noneDB 4

**Tool:** `noneDB-migrate.php` · **Target:** storage format 4 (STORAGE_FORMAT.md) · **Status:** normative

noneDB 4 does not read the files of earlier versions. `noneDB-migrate.php`, a separate tool shipped next to
`noneDB.php`, converts them: it reads the old files of one secret key, builds noneDB 4 databases beside
them, verifies them, and publishes them in one committed step. It never changes the old files while it
works, keeps a verified backup of them, and can put them back.

The tool knows the old formats; the engine does not. Everything the tool writes is noneDB 4 as
STORAGE_FORMAT.md defines it, and it is verified with the engine before it becomes visible.

---

## 1. Guarantees

- **The live data of each version is kept as that version defines it** (§4): the records it returns, with
  their keys. Records a version had deleted stay deleted, even where its data file still holds them.
- **Keys are never reused:** the next key of a migrated database is at least every key the old files ever
  used (§5).
- **Fail closed:** when the files do not determine the live data (a lost or damaged index, an interrupted
  flush of unknown state, a shard size that does not fit the files, …), the database is reported and
  nothing is migrated for it, unless an option states the decision explicitly (§6).
- **Nothing is visible before it is verified:** each database is built in a staging area, checked with the
  engine against the expected records, and only then published. A published database answers
  `MAINTENANCE_IN_PROGRESS` until the whole run commits.
- **One commit point per run:** the maintenance journal (STORAGE_FORMAT.md §9) records `COMMITTED`. Before
  it, a run can be resumed or rolled back (`--abort`); after it, only completed.
- **The old files are never deleted by a migration:** they are copied to a verified backup first and, after
  the commit, moved into `.nonedb-retained/{runId}/`. Only `--cleanup` removes them, after verifying the
  backup again.

## 2. Usage

```bash
# Check only: what would be migrated, what stops. Writes nothing.
NONEDB_SECRET=... php noneDB-migrate.php --dir=./db --dry-run

# Migrate
NONEDB_SECRET=... php noneDB-migrate.php --dir=./db --backup-dir=/var/backups/nonedb --confirm-stopped

# Also compare every migrated record with the backup after the commit
NONEDB_SECRET=... php noneDB-migrate.php --dir=./db --backup-dir=/var/backups/nonedb --confirm-stopped --verify
```

| Option | Meaning |
|---|---|
| `--dir=DIR` | The directory that holds the old databases (the engine's `dbDir`). |
| `--config=FILE` | A `.nonedb` file to take `secretKey` (and `dbDir` when `--dir` is absent) from. |
| `--dry-run` | Pre-check only (§7.1). Nothing is written anywhere. |
| `--backup-dir=DIR` | Where the backup of the old files is written (§8). Required to migrate, with `--restore` and with `--cleanup`. Must be outside `--dir`. |
| `--confirm-stopped` | Required to migrate: states that no application uses the directory. Versions before 3.2 take no database lock, so this cannot be checked for them (§7.2). |
| `--db=NAME[,NAME…]` | Migrate only these databases. The key is registered only by the run that leaves no old database of the key behind (§9). |
| `--verify` | After the commit, read every migrated database again with a new engine instance and compare every record with the backup (§7.8). Without it, the final check is `checkIntegrity()` and the record counts. |
| `--rebuild-from-data` | Consent for databases whose index is lost or damaged: their live set is rebuilt from the data file (§6.2). The report says that an index was replaced; it can't say which records come back. |
| `--flushing=import\|skip` | Decision for interrupted flushes of versions 2.3–3.1 (§6.3). |
| `--shard-size=N` | Shard size of databases that were not sharded (default: the `shardSize` of the config file, else 10000). Sharded databases keep their own. |
| `--abort` | Roll back an interrupted run that has not committed (§10). |
| `--restore=RUNID` | Put the old files of a run back and remove its noneDB 4 databases (§11). |
| `--cleanup=RUNID` | Remove `.nonedb-retained/RUNID/` after verifying it against the backup again (§12). Needs the key. |
| `--json` | Print the result as one JSON object (§13). |

**Settings.** noneDB 4 refuses a setting it doesn't know, in a config array and in a `.nonedb` file
(CONFIGURATION.md "Settings"). The `.nonedb` of noneDB 3.0–3.2 may hold `autoMigrate`, which noneDB 4 doesn't
have (`shardingEnabled` alone decides whether a database switches to shards by itself): remove that line before
the run. Otherwise the pre-check stops with `FAILED`, naming the setting and the file, and nothing is written. That
holds for a `.nonedb` the engine finds itself; a file given with `--config` elsewhere is only read for its key and `dbDir`.

**Keys.** The secret key is read from the environment or a config file, never from the command line:

- `NONEDB_SECRET` (or the config file's `secretKey`): the key the migrated databases are registered with.
- `NONEDB_SOURCE_SECRET`: the key the old files were written with, when it differs. Releases before 3.0 shipped with the key `nonedb_123`, and noneDB 4 refuses that key (and every documented placeholder) for a store. Such databases are migrated with `NONEDB_SOURCE_SECRET=nonedb_123` and a real `NONEDB_SECRET`. A dev-mode source key is `nonedb_dev_mode_key_` followed by the MD5 of the directory the old `noneDB.php` was in.

The tool loads `noneDB.php` from its own directory.

## 3. Sources

| Source | Recognised by |
|---|---|
| 1.4, 2.0–2.2 | `{h}-{name}.nonedb` whose content is one JSON object `{"data":…}`; sharded: `{h}-{name}.nonedb.meta` and `{h}-{name}_s{N}.nonedb` of the same form. |
| 2.3 | As 2.x, plus `.nonedb.idx`, write buffers `.nonedb.buffer`, `_s{N}.nonedb.buffer`, `.buffer.flushing`. |
| 3.0, 3.1 | JSONL data files, `.jidx` key indexes `"v":3` without `s`; 3.1 also `.sidx` spatial files. |
| 3.2 | A lock file `{h}-{name}.nonedb.lock` whose record has no epoch, `.jidx` `"v":3` with `s`, tombstone lines, one buffer per database with `.flushing.{K}`. |
| 3.3-dev | A lock record `{gen}:{epoch}`, `.jidx` `"v":4` checkpoints, `.fx`/`.rx` index files. |

`{h}` is `hash_pbkdf2('sha256', name, key, 1000, 20)` (all versions). The tool lists `--dir` and groups the files of the
source key: a file belongs to database `name` when its name starts with `{h}-{name}.` or `{h}-{name}_s{N}.`
with `{h}` computed from `name` and the source key, or (spatial and quarantine files of 3.1–3.3-dev) with
`{h}.` or `{h}_s{N}.`. Names are what the old versions stored: `[A-Za-z0-9' -]`.

- Files of other keys are not touched and are listed in the report. As long as they stay in the directory,
  the engine refuses to answer "does not exist" for any database and refuses to create one (STORAGE_FORMAT.md
  §3.4); run the tool with each key, or move them away.
- A 3.0/3.1 `.nonedb_hash_cache` can name files with the hash of another key. The tool does not follow it:
  such files belong to that other key.
- Files of the store itself (`STORE*`, `*.ndb4/`, `.nonedb-maintenance/`, `.nonedb-retained/`, `.htaccess`,
  `index.html`, `.gitkeep`, `.gitignore`, `.DS_Store`) are not sources.

A **database** of the source key is a name with a data file `{h}-{name}.nonedb`, a meta `{h}-{name}.nonedb.meta`,
or a write buffer holding records. Files of the key that belong to no database (an info or lock file left by
a drop, shard files without a meta, …) are **leftovers**: §6.4.

The version is decided **per segment** (a data file with its index), not per directory: versions from 3.0 on
convert files lazily, so one database can hold segments written by different versions. §4 names the rule
for each kind of segment.

## 4. Live set

Every database is read without the engine. Layout: **sharded** when `{h}-{name}.nonedb.meta` exists (every
version); its segments are the shards the meta lists, in its order; shard files it does not list are
leftovers (§6.4). Otherwise **single**: the segment `{h}-{name}.nonedb`. The live set is the union of the
segments' live sets plus the buffered records (§4.3).

### 4.1 Segment kinds

| Kind | How it is recognised | Live set | Key of a record |
|---|---|---|---|
| **W** — whole JSON (1.4–2.3, and segments no later version touched) | The file decodes as one JSON object whose only member is `data`, an array or an object | Every entry of `data` that is an array (a JSON object or list). `null` and other values are not records (they are reported); they count for the next key. | Single: the entry's integer index. Shard N: `N·S + index` (S: §4.2). |
| **J30** — JSONL with a 3.0/3.1 index | `.jidx` with `"v":3` and an `o` map, no `s` | The keys of `o`. The record is the line at `[offset, length]`, which must decode to an object whose `key` equals the map key. An entry of length 0 is not live (a failed write of 3.0/3.1; reported). Lines `o` does not point at are not live: 3.0/3.1 deleted by removing the key from `o` and wrote no tombstone. | The map key. |
| **J32** — JSONL with a 3.2 index | `.jidx` with `"v":3`, `o` and `s` | As J30. In addition `s` must not exceed the file size and must be a line end; complete lines after `s` are not live (3.2 never showed them; reported, kept in the backup). | The map key. |
| **J33** — JSONL of 3.3-dev | `.jidx` with `"v":4`, or, in a database whose lock record has an epoch, no `.jidx` or one of neither 3.0/3.1 nor 3.2 form | The data file is authoritative: every complete line from the start, the last line of a key wins, a tombstone `{"__nonedb_deleted":K}` removes K. A line that does not decode is salvaged as 3.3-dev did (STORAGE_FORMAT.md §6.1 salvage rule); one that cannot be salvaged is not live (reported). An unterminated last line is not a record (reported). Shard lines whose key is outside the shard's range are not live. | The line's `key` (an integer). |

3.3-dev replaced a 3.0/3.1 index by its own checkpoint as soon as it opened the database, reading included, and
replayed the whole data file: from then on the files no longer tell which records 3.0/3.1 had deleted, and such a
segment is J33 (3.3-dev was never released; the fixture `3.1-3.3-dev-read` shows it).

A JSONL segment that matches no row (no `.jidx` outside 3.3-dev, an unreadable `.jidx`, an index of an unknown
version, a J30/J32 entry that points at the wrong line, J32 `s` beyond the file) stops: §6.2.

A record is carried over as stored: for J30/J32/J33 the bytes of its line are reused when the line ends with
`"key":K}`; otherwise (W segments, lines with `key` elsewhere) it is encoded the way the engine encodes a record
(STORAGE_FORMAT.md §6.1: `key` last, the encoding of §2). A JSON list record of 1.4 becomes an object with members `"0"`, `"1"`, …
(the same PHP array as before).

### 4.2 Shard size

For a sharded database S is the meta's `shardSize`, checked against the files, because versions before 3.2
computed keys with the shard size of the running process, not the meta's:
- a W shard N must hold at most S entries;
- every key of a J30/J32 shard N must lie in `[N·S, (N+1)·S)`; a J33 line outside that range is not live, and its
  key still raises the next key;
- the meta's shard ids must be distinct non-negative integers.
If any check fails, the database stops (§6.2). A single-layout database gets the shard size of
`--shard-size` (its keys don't depend on it).

### 4.3 Write buffers

Buffered records are live in every version that has buffers (each flushes them before it reads). The tool
assigns their keys as the version's flush would, in this order:

1. **Shard buffers of 2.3–3.1** `{h}-{name}_s{N}.nonedb.buffer`: shard N must be listed in the meta. Keys
   `N·S + c, N·S + c + 1, …` where `c` is the shard's slot count (W: entries of `data`; J30: `o`'s `n`). Their
   records are already counted in the meta.
2. **Interrupted flushes of 3.2/3.3-dev** `….nonedb.buffer.flushing.{K}`, by ascending K: keys `K, K+1, …`;
   a key the live set already holds is skipped (the flush had stored it).
3. **The current buffer** `{h}-{name}.nonedb.buffer` (single layout of 2.3–3.1; every layout of 3.2/3.3-dev):
   keys from the next key (§5), in line order.
4. **Interrupted flushes of 2.3–3.1** `….buffer.flushing` and `_s{N}.nonedb.buffer.flushing`: only with
   `--flushing=import` (§6.3), with fresh keys after everything else, so they never take a key an interrupted
   flush of 3.2/3.3-dev reserved.

A buffer line is a record when it decodes to an array; blank lines are ignored; any other line is reported and
not imported. A `key` member in a buffered record is replaced by the assigned key. A buffer of a layout the
version never flushes (a single-layout buffer next to a meta, a shard buffer of a shard the meta doesn't list)
stops: §6.2.

## 5. Next key

The next key of a migrated database is the maximum of:
- for W segments: single `count(data)` (the largest index + 1, null entries included); shard N: `N·S` + its
  entry count;
- the `n` of a J30/J32 `.jidx` (single: as is; shard N: `N·S + n`), and of a J33 checkpoint (shard: `N·S + n`);
- the meta's `nextKey` (sharded);
- every key in every line of every JSONL segment + 1, record or tombstone, live or not;
- every key given to a buffered record + 1;
- 0.

If the highest live key is below `next key − 1`, the database gets a tombstone for `next key − 1` (STORAGE_FORMAT.md
§6.2), so the engine never hands out a key the old version had used.

## 6. Stops and decisions

### 6.1 Results

Each database gets one result in the pre-check (§7.1):

| Result | Meaning |
|---|---|
| `READY` | It can be migrated as described in §4–§5. |
| `AMBIGUOUS_SOURCE` | The files don't determine its live data. Nothing is written for it. The report names the files and the decision that is missing. |
| `UNSUPPORTED_SOURCE` | Its files are recognised but cannot become noneDB 4: a name noneDB 4 cannot hold (an empty name, possible in 2.2), a meta of an unknown `version`. |
| `TARGET_EXISTS` | The noneDB 4 directory of this name and key already exists (a database created after an earlier run). Nothing is merged or overwritten: it is reported as `AMBIGUOUS_SOURCE`. |

A run migrates the `READY` databases it is asked to migrate (all, or `--db`) only when every one of them is
`READY`; otherwise it stops before writing anything.

### 6.2 `AMBIGUOUS_SOURCE` conditions

- A JSONL segment without a usable index outside 3.3-dev: missing, empty, not JSON, unknown `v`, an `o` entry
  whose line does not decode to its key, J32 `s` beyond the file or not at a line end. **`--rebuild-from-data`**
  replaces the index by a replay of the data file (rule J33). For a segment last written by 3.0/3.1 this brings
  back every record they deleted and not yet compacted; the report says the index was replaced, not what came back.
- A data file that decodes neither as W nor as JSONL lines of records.
- A sharded database whose meta is unreadable or fails §4.2 (§4.2 also keeps every key in one segment).
- A buffer the version never flushes (§4.3).
- An interrupted flush of 2.3–3.1 without `--flushing` (§6.3).
- The source files changed during the run (§7.6).

### 6.3 Interrupted flushes of 2.3–3.1

2.3–3.1 renamed the buffer to `….flushing` while flushing and never read that file again. After a crash its
records are either only there, or already stored too, and nothing on disk tells which. `--flushing=import`
inserts them with new keys (what 3.2 and 3.3-dev did when they opened such a database); `--flushing=skip` leaves
them out of the migrated database (what 2.3–3.1 themselves showed). In both cases the file is backed up and
retained.

### 6.4 Leftovers

Files of the source key that belong to no database (§3), for example an info file, a lock file without data,
shard files without a meta, temp files of interrupted writes: they make the database's name `AMBIGUOUS_SOURCE`
when they contain data the tool cannot place (shard files without a meta), and are otherwise backed up and
retained with the run. The report lists every one.

## 7. A run

The steps follow STORAGE_FORMAT.md §9.2 and §9.5. The journal `{dir}/.nonedb-maintenance/journal.json`
(op `migrate`) is written after every step; its states are in parentheses.

### 7.1 Pre-check (no journal)
Lists the directory, groups the files (§3), reads every database (§4–§5) and decides its result (§6.1).
Computes the space needed: the backup and the staged databases each take about the size of the old files (twice
that when both are on one file system); a run stops before writing anything when it is not free. Records each source file's
**fingerprint**: name, size, mtime, inode and SHA-256. With `--dry-run` the run ends here and prints the report.

### 7.2 Locks
`STORE.LOCK` (created if missing) and the lock files of the source databases that have one (3.2, 3.3-dev) are taken
with `LOCK_EX|LOCK_NB`. If one is busy, every lock taken is
released and the run ends with `MAINTENANCE_BUSY`; nothing has been written but an empty `STORE.LOCK` where there was
none. The locks are held until the run ends. Then the journal is written (`DISCOVERED`) with the files of the store
the run makes: `STORE.LOCK`, `.htaccess`, `index.html` where they are missing, and a `STORE.LOCK` without a STORE record
(a run stopped before its journal left it; noneDB 4 creates none next to old files).

### 7.3 Backup (`BACKED_UP`)
Every source file of the databases and leftovers of this run is copied into
`{backup-dir}/{runId}/files/`, with `manifest.json` written last: run id, source key's keyCheck, every file's
name, size, mtime and SHA-256, the databases of the run, and the STORE record when one exists.
Directories are created `0700`, files `0600`. The copy is read back and compared before the state is recorded.

### 7.4 Staging (`STAGING`)
`{dir}/.nonedb-maintenance/staging/` is a noneDB 4 store of its own, holding the target key. For each database
the tool writes, by STORAGE_FORMAT.md: FORMAT (a new `databaseId`), the state record `1:{epoch}`, CURRENT,
`generations/1/` with `metadata`, `shards.json` (sharded) and the segments (§4.1, §5). It then opens the staging
store with the engine and creates the field and spatial indexes the source had (`createFieldIndex`,
`createSpatialIndex` with the source's node size), taken from the source's index list or index files.

### 7.5 Verification (`VERIFIED`)
With the engine, for each database of the staging store:
`checkIntegrity()` is ok; every record, read in batches of keys, equals the expected record (`===`); `count()`
equals the number of live records; the next key (`getShardInfo()` / the key index) equals the computed one;
for every indexed field and a sample of values, the indexed lookup equals a scan; the recovery log is empty.
A difference stops the run with the database named (nothing published).

### 7.6 Publish (`PUBLISHING`)
For each database: the fingerprints of its source files are checked again (a difference: `AMBIGUOUS_SOURCE`, the run is rolled
back). The staged database's state record gets `m`, and its directory is renamed to `{dir}/{locator}.ndb4`. The
target must not exist and must be on the same file system. From here until the commit the published
databases answer `MAINTENANCE_IN_PROGRESS`.

### 7.7 Commit (`COMMITTED`)
The fingerprints of every file the run moves are checked a last time. The journal is written with state `COMMITTED` and the target STORE
record: the current one with the target key added if this run completes the key (§9), else unchanged; a new
store (`seq` 1) when the directory has none. **This is the commit point.** Then, in this order:
1. both STORE copies;
2. the source files of the migrated databases and leftovers are moved to `{dir}/.nonedb-retained/{runId}/`
   with a `manifest.json` (file names, sizes, SHA-256, backup reference);
3. `m` is cleared (new epoch) on the published databases;
4. the staging directory is removed;
5. the journal is closed: it moves to `{backup-dir}/{runId}/journal.done.json`.

### 7.8 Final verification (`FINAL_VERIFIED`)
After the journal is closed (the engine opens no store while one is open), a new engine instance opens every
migrated database: `checkIntegrity()` and `count()`; with `--verify` every record is compared, and every index with a
scan, against the live set read again from the backup. The report is then written next to the closed journal
(`report.json`), and the state `FINAL_VERIFIED` recorded in `journal.done.json`. The migration is finished when the
tool exits with 0: the applications stay stopped until then. A run whose key is not registered yet
(§9) has no final verification: the engine cannot open its databases until a later run registers the key.
`FINAL_VERIFIED` is recorded all the same, so `--cleanup` of such a run relies on the verified backup alone.

## 8. Backup

`{backup-dir}/{runId}/`: `files/` (the source files, byte for byte), `manifest.json`, `journal.done.json`,
`report.json`, and after a restore `v4/` and `restore.done.json` (§11). A backup is valid only with a `manifest.json` whose checksum and every file's SHA-256 verify.
The tool never deletes a backup.

**Permissions.** The backup and its records are private: directories `0700`, files `0600` (`restore.done.json`
`0640`). The noneDB 4 files the
tool writes get the engine's default permissions, files `0640` and directories `0750` (CONFIGURATION.md "Files and
permissions"); other `filePermissions` or `directoryPermissions` apply to the files the engine creates later.
The retained old files (§12) keep their own.

## 9. Key registration

A key is added to the STORE record only by the run that leaves no database or leftover of that key in the old
format in the directory: a `--db` subset publishes its databases, but they stay unreachable (`KEY_NOT_REGISTERED`)
until a later run completes the rest. A run that finds no old file of the key registers it only when the directory
already holds noneDB 4 databases of that key (published by such a partial run); otherwise it does nothing, so a
mistyped key is never registered. A key that is already registered (databases created with it earlier)
is not added again; its published databases become reachable at the commit.

## 10. Interruption

The tool can be stopped at any point (a crash, `kill -9`). Run it again with the same options. A temp file a stopped
run left (STORAGE_FORMAT.md §2) is removed when the run writes that file again, or by `--abort` (the journal's):

| Journal state | Run again | With `--abort` |
|---|---|---|
| none | starts over | nothing to do |
| `DISCOVERED`, `BACKED_UP` | makes the backup or verifies it, continues | releases the run: journal removed, the backup stays |
| `STAGING`, `VERIFIED` | removes the staging directory, stages again | removes the staging directory and the journal |
| `PUBLISHING` | checks the fingerprints, continues publishing (published directories still carry `m`) | removes the published directories; the old files are untouched |
| `COMMITTED` | completes §7.7 (each step is idempotent) and §7.8 | refused: the run is committed |
| closed, without `FINAL_VERIFIED` (a crash during §7.8) | runs §7.8 for that run (found through `.nonedb-retained/{runId}/manifest.json`) and reports it; nothing is migrated again | nothing to abort |

While the journal exists, every process using the directory gets `MAINTENANCE_IN_PROGRESS`. A journal whose
state contradicts the files (a published directory missing, an unreadable journal) stops the tool: it reports what it found and changes nothing.

## 11. Restore

`--restore=RUNID` puts the directory back into its state before the run. It needs `--backup-dir`, the run's target key
(`NONEDB_SECRET`), and refuses, changing nothing, when the key has a database the run did not create: the key leaves
the STORE record, and that database would become unreachable.
1. verifies the backup (manifest and every SHA-256);
2. takes the locks (§7.2): `STORE.LOCK` and every `LOCK` of the run's databases, `LOCK_EX|LOCK_NB`;
3. writes the journal (op `restore-old`, state `RESTORING`);
4. moves the run's noneDB 4 directories into `{backup-dir}/{runId}/v4/`; removes the key from the STORE record
   (the STORE files go there too when no key remains);
5. moves the retained files back from `.nonedb-retained/{runId}/` (or copies them from the backup after a
   cleanup), checking every SHA-256;
6. closes the journal; when no STORE record is left, removes the files of the store the run made (§7.2) and the
   empty `.nonedb-maintenance/` and `.nonedb-retained/`.

The old files are then usable by the old version again; noneDB 4 does not read them.

## 12. Cleanup

`--cleanup=RUNID`, with `--backup-dir` and the key, verifies `.nonedb-retained/{runId}/` against the backup's manifest (every SHA-256) and removes
it. It can be repeated. A run without `FINAL_VERIFIED` (§10) is refused, nothing removed: run the tool again first.

## 13. Result

Exit status 0 when the requested operation completed; otherwise the status names the first stop:

| Status | Result |
|---|---|
| 0 | done (or a dry run whose databases are all `READY`) |
| 2 | `AMBIGUOUS_SOURCE` / `UNSUPPORTED_SOURCE` (nothing written) |
| 3 | `MAINTENANCE_BUSY` (nothing written but an empty `STORE.LOCK`, §7.2) |
| 4 | `MAINTENANCE_IN_PROGRESS`: committed, not completed, run again; or another maintenance, or a run of other keys, is open |
| 5 | another failure; the message names it |
| 64 | usage error |

The pre-check's report lists, per database: its status with the reasons, segment kinds, layout and shard size, live
records, buffered records, next key, the field and spatial indexes, and records and lines not carried over (with the
reason). The report of a run (`report.json`) lists the run, the backup, each database's records, whether the key was
registered, and the retained directory. `--json` prints either as one JSON object.

## 14. Time, memory and space

Measured with PHP 8.2. The sources were databases written by
3.2 itself: shards of 10 000 records, a field index on a field whose values are in every shard.

| Records | Dry run | Run | Run with `--verify` | Peak memory (RSS) |
|---|---|---|---|---|
| 1M (137 MB, 306 files) | 6 s | 63 s | 98 s | 98 / 116 MiB |
| 10M (1.4 GB, 3006 files) | 61 s | 27 min | 47 min | 125 / 135 MiB |

- **Memory:** the run holds one segment at a time and the engine's cache (`cacheMemoryLimit`, half of
  `memory_limit` by default), so PHP's default `memory_limit` of 128M is enough at every size measured.
  A smaller limit gives the cache less room and leaves less for one segment's work: 1M records ran at 64M.
  More memory shortens the index builds: at 10M the global index of `group` takes 100 s at 128M and
  32 s without a limit.
- **Time** grows with the records: the tool reads every record twice (conversion and verification) and
  `--verify` reads the published databases a third time. A field index of a sharded database built under a
  memory limit takes one pass over the shards per part (§7.4; CONFIGURATION.md "Memory of operations
  over many segments").
- **Disk:** free space for the staged databases (about the size of the source) on the directory's file
  system and for the backup (the same) on `--backup-dir`'s: twice the source's size when both are on one
  file system, which then holds three times the source during the run (10M: 4.2 GB). The pre-check stops a
  run without that room (§7.1). After the commit the old files are retained (moved, not copied) until
  `--cleanup`.
- **Downtime:** the applications stay stopped for the whole run (§7.2): plan for the times above. A
  `--dry-run` (a minute at 10M) checks first that every database is `READY` and, with `--json`, reports the bytes
  of the old files.
