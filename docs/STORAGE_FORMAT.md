# noneDB 4 Storage Format

**Storage format:** 4 · **Engine:** noneDB 4.0.0 · **Status:** normative

This document is the contract for every file noneDB 4 reads or writes. The engine recognises
only what is described here. Anything else in a store directory is a *foreign entry* (§3.4) and
is never interpreted. Sync requirements and operation outcomes are in [DURABILITY.md](DURABILITY.md).

---

## 1. Terms

| Term | Meaning |
|---|---|
| store | The configured `dbDir`. It holds the databases of one or more secret keys. |
| key | A configured `secretKey`. |
| keyCheck | `substr(hash_hmac('sha256', 'nonedb4/key', key), 0, 32)`. It identifies a key without revealing it. |
| locator | `substr(hash_hmac('sha256', 'nonedb4/db/' . name, key), 0, 32)`: the directory name of a database. It is stable for a name and key, unpredictable without the key, and changes when the key is rotated. |
| databaseId | A random UUIDv4 assigned once when a database is created. It never changes: it survives key rotation, backup and restore. |
| generation | A complete, self-consistent file set of one database layout. `CURRENT` names the active one. |
| segment | One data file with its key-index checkpoint: `main` (single layout) or `s{N}` (shard N of the sharded layout). |
| lock epoch | 8 lowercase hex digits in the lock record (§4.2). They change before any file a process may have cached is replaced. |
| segment epoch `e` | 8 lowercase hex digits in a key-index checkpoint. They change whenever the segment's data file is replaced or its index rebuilt without a trusted predecessor. |

## 2. Encodings

- **Metadata files** (`STORE`, `FORMAT`, `CURRENT`, `metadata`, `shards.json`, `indexes.json`, journals, manifests):
  - UTF-8 JSON on a single line, terminated by `"\n"` (`indexes.json` alone has no final `"\n"`).
  - Encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION` and `serialize_precision = -1`.
  - Object members are written in the order listed in this document.
- **Checksum:**
  - `checksum` is the last member: the lowercase hex SHA-256 of the encoding of the object **without** its `checksum` member, followed by `"\n"`.
  - To verify, use the bytes as written: the file must end with `,"checksum":"<64 hex>"}\n`; the bytes before `,"checksum"` followed by `}\n` must hash to it. A re-encoding is never compared (an empty object would come back as `[]`).
  - A file that does not decode, lacks a required member, has a wrong `magic`/`kind`, or fails the checksum is **invalid**.
- **Atomic replacement:**
  - The new content is written to `{target}.tmp{8 hex}` in the target's directory.
  - That file is flushed and, in durable mode, synced; it is then renamed over the target; in durable mode the directory is synced.
  - A temp file left by a crash is never read; until it is removed it only takes disk space. It is removed by the next writer that owns its directory exclusively and works on that file: the temp of a STORE copy or of the maintenance journal by the next write of that file under `STORE.LOCK` held exclusively, and the journal's also when a maintenance closes (an abort writes none); the temp of a segment's data file by the segment's next compaction (§6.2); every temp of a database, a key index's too, by the recovery of a session that finds `j`/`J` (§7.3); a generation's with the generation (§5.2). No other operation lists a directory for them.
- **Data and buffer lines:** see §6.

## 3. Store directory

```text
{dbDir}/
  STORE                 store record, copy 1 (§3.1)
  STORE.2               store record, copy 2
  STORE.LOCK            store lock (flock only, empty; never replaced or deleted)
  .htaccess, index.html deny web access (written when the store is created)
  {locator}.ndb4/       one directory per database (§4)
  .nonedb-maintenance/  maintenance journal, migration staging (§9) and log.jsonl: store-level events
                        (copy repaired, store restored or rebuilt; JSONL, no key material)
  .nonedb-retained/     migrated source files awaiting cleanup (§9.6)
```

- **Path:** `dbDir` is used by its canonical path: symbolic links and `.`/`..` are resolved when an instance is
  configured (for a directory that doesn't exist yet, its nearest existing parent is). Two spellings of one
  directory are one store, with one lock per file in a process.
- **Permissions:** every file noneDB creates in `dbDir` gets `filePermissions` (default `0640`), every
  directory `directoryPermissions` (default `0750`), whatever the process's umask (CONFIGURATION.md). Files that
  exist keep theirs. Backups are `0600`/`0700` (§10.1).

### 3.1 STORE record

```json
{"magic":"NONEDB4","kind":"store","storageFormat":4,"storeId":"<uuid>","seq":7,"keys":["<keyCheck>",...],"checksum":"<sha256>"}
```

- `keys` is sorted and has no duplicates.
- Every write goes through `STORE.LOCK` held exclusively. It writes `STORE.2` and then `STORE`, both with the same content and a `seq` one higher than before, each by atomic replacement.
- **Invariant:** while no maintenance journal is open, both copies are valid and identical.

**Reading, with no open maintenance journal (§9.1):**

| Copies | Result |
|---|---|
| Both valid and identical | Use them. |
| One valid; the other missing or invalid | The invariant says they were identical. The missing or invalid copy is rewritten from the valid one under `STORE.LOCK` (held exclusively; a process that holds it shared uses the valid copy and leaves the repair to the next opening), and a `store-copy-repaired` event is appended to `.nonedb-maintenance/log.jsonl`. This also covers a first creation interrupted between the two copies. |
| Both valid, but `seq` or content differ | The invariant is broken: `FORMAT_DAMAGED`. Nothing is guessed. |
| None valid | `FORMAT_DAMAGED`. Only `repairStore()` can fix it (§9.4). |

### 3.2 Opening a store

The engine opens a store once per process and caches the result. The cache is re-checked on the paths named in §3.3.

1. **A STORE copy exists:** read it by §3.1.
   - If the configured key's keyCheck is listed, the store is open.
   - Otherwise: `KEY_NOT_REGISTERED`. No file is created.
2. **No STORE copy exists:** scan `dbDir` read-only.
   - Allowed entries: `.htaccess`, `index.html`, `.gitkeep`, `.gitignore`, `.DS_Store`, `STORE.LOCK`, and `STORE.tmp*` / `STORE.2.tmp*`.
   - Any other entry: if `STORE.LOCK` exists, step 1 is repeated holding it shared (a store created by another process since the first read is found there; its creation holds the lock exclusively). Still no STORE copy: `UNSUPPORTED_FORMAT`; if the entries include noneDB 4 objects (`*.ndb4`, `.nonedb-maintenance`), the store record itself is missing: `FORMAT_DAMAGED` (see `repairStore()`). **No file or directory is created on this path.**
3. **Empty, or `dbDir` does not exist:** create the store.
   1. Create `dbDir` if needed.
   2. Open or create `STORE.LOCK` and lock it exclusively.
   3. Re-check step 1: another process may have created the store.
   4. Re-scan with the step-2 allow-list.
   5. Remove stale `STORE*.tmp*` files.
   6. Write the STORE record with `seq` 1 and this keyCheck (two copies).
   7. Write `.htaccess` and `index.html`.
   8. Unlock.

   `STORE.LOCK` never makes a directory non-empty. A crash before the STORE record leaves a directory that the next opening still treats as empty.

### 3.3 Re-validation of an open store

The STORE record and the journal check (§9.1) are read again:

- after a database's lock epoch changed (the cache-invalidation path);
- every time the engine is about to answer "this database does not exist", or to create a database. This is the missing-database path. It holds `STORE.LOCK` shared, so maintenance (exclusive) cannot run at the same time.

CRUD on an existing database never reads the STORE record, and lists a directory only in the rare cases of §12.

### 3.4 Foreign entries

- **v4 allow-list of a store with a STORE record:** `STORE`, `STORE.2`, `STORE.LOCK`, `STORE*.tmp*`, directories named `[0-9a-f]{32}.ndb4`, `.nonedb-maintenance`, `.nonedb-retained`, `.htaccess`, `index.html`, `.gitkeep`, `.gitignore`, `.DS_Store`. Every other entry is **foreign**.
- **The engine never inspects foreign entries.** On the missing-database path (§3.3), however, it lists `dbDir` directly, every time, with no cache keyed on mtime or inode. If any foreign entry exists:
  - the engine answers neither "does not exist" nor creates the database;
  - the operation fails with `UNSUPPORTED_FORMAT`;
  - the message says the directory holds files that are not noneDB 4 objects, names up to five of them, and says no database is created or answered as missing while they are there.
- Databases that exist are unaffected. Their directories are found without a listing.
- **Links:** a `{locator}.ndb4` entry that is a symbolic link, or not a directory, is never a database. It is
  a foreign entry; an operation on its database fails with `UNSUPPORTED_FORMAT` and follows no link;
  `getDBs()` skips it.

## 4. Database directory `{locator}.ndb4/`

```text
FORMAT                    identity (§4.1)
LOCK                      lock + state record (§4.2)
CURRENT                   active generation (§4.3)
generations/{N}/          generation N (§5)
recovery/                 recovery log and quarantine (§7)
```

### 4.1 FORMAT

```json
{"magic":"NONEDB4","kind":"database","storageFormat":4,"databaseId":"<uuid>","name":"users","shardSize":10000,"createdAt":1793103240,"formatOptions":{},"checksum":"<sha256>"}
```

- FORMAT carries no key material. A directory belongs to a key K when `locator(K, FORMAT.name)` equals its name.
- FORMAT is written once, at the end of creation (§8.1). It is never rewritten except by the repair in §8.3
  and by `restore()` over a damaged database (§10.3).
- `shardSize` is fixed at creation: the configuration's `shardSize` applies only to new databases.

### 4.2 LOCK and the state record

`LOCK` is opened with `c+` and locked with `flock`. Its inode never changes while the database directory exists, and `dropDB` keeps it. The first 128 bytes are the state record, space padded and overwritten in place:

```text
record   = gen ":" epoch *( " " flag )
gen      = 1*18DIGIT               ; generation counter of the lock file, monotonic
epoch    = 8 lowercase hex digits
flag     = "b"                     ; the write buffer may hold records
         / "c"                     ; creation in progress (§8.1)
         / "d"                     ; drop in progress (§8.2)
         / "m"                     ; maintenance in progress (§9)
         / "g=" N ">" X            ; generation switch from N to X in progress (§5.2), 1*9DIGIT each
         / "j=" seg *( "," seg )   ; journal: these segments' shard counters may be stale
         / "J"                     ; journal: all segments
seg      = "main" / "s" 1*9DIGIT   ; a segment (§1)
```

- A record that is empty, all NUL bytes, or all spaces is a **new record**. The next exclusive session writes `1:{new epoch}`; its file writes keep generation 1.
- `gen` grows by one in every exclusive session that writes a file of the database. A new or damaged record starts again at 1 with a new epoch: caches are valid only for the same generation **and** epoch, so a restarted counter never validates an old cache.
- A record that does not match the grammar is **damaged**. The next exclusive session treats it as `J`: full recovery of the database (§7.3) and a new epoch.
  - It does not treat a damaged record as `g`: a generation that is not `CURRENT` is reported by `checkIntegrity()` and never removed on this path.
- Every operation reads the record when it takes the lock. When the epoch differs from what the process cached, every cache of the database is dropped and `CURRENT` is read again.
- `m` → `MAINTENANCE_IN_PROGRESS`.
- `c`, `d`, `g`, `j` and `J` each trigger their recovery under the exclusive lock before any data is returned (§8, §5.2, §7.3).

### 4.3 CURRENT

```json
{"magic":"NONEDB4","kind":"current","databaseId":"<uuid>","generation":3,"checksum":"<sha256>"}
```

`CURRENT` is **valid** when:
- its checksum verifies;
- `databaseId` equals FORMAT's;
- `generations/{generation}/metadata` is valid (§5.1) with the same `databaseId`.

It is only ever replaced atomically.

## 5. Generations

### 5.1 Generation directory `generations/{N}/`

```text
metadata                  immutable after creation of the generation
shards.json               sharded layout only: shard list and counters (mutable)
indexes.json              index definitions (mutable; absent = no indexes)
segments/{seg}.jsonl      data (§6)
segments/{seg}.kix        key-index checkpoint (§6.4; derived)
indexes/{seg}.{token}.fx  field index of a segment (derived)
indexes/{token}.gfx       global field index (sharded layout; derived)
spatial/{seg}.{token}.rx  R-tree of a segment (derived)
spatial/{token}.grx       global spatial index (sharded layout; derived)
buffer/pending.jsonl      write buffer
buffer/flushing.{K}.jsonl buffer being flushed from key K on
```

**`metadata`:**

```json
{"magic":"NONEDB4","kind":"generation","storageFormat":4,"databaseId":"<uuid>","name":"users","shardSize":10000,"createdAt":1793103240,"formatOptions":{},"generation":3,"layout":"sharded","checksum":"<sha256>"}
```

- The identity members are a copy of FORMAT's; they let FORMAT be repaired (§8.3).
- `layout` is `single` (one segment, `main`) or `sharded` (segments `s{N}`, keys `[N·shardSize, (N+1)·shardSize)`).

**`shards.json`:**

```json
{"kind":"shards","v":1,"shardSize":10000,"nextKey":123456,"totalRecords":120001,"deletedCount":17,"shards":[{"id":0,"count":9998,"deleted":2},...]}
```

- Replaced atomically by inserts, deletes, compaction and recounts. An update changes no counter and leaves it.
- Keys are reserved in it before data is appended.
- Its counters are protected by the `j`/`J` journal (§7.3).

**`indexes.json`:**

```json
{"kind":"indexes","v":1,"fields":["city"],"spatial":["loc"],"spatialOptions":{"loc":{"nodeSize":32}}}
```

- `spatialOptions` is `[]` when no index has options.
- Written after the index files it lists exist; a layout switch writes it first and builds them after PUBLISH.
- An empty or invalid file is derived again from the headers of the generation's `.fx`/`.rx` files.

**Field tokens:**
- A field name matching `[a-z0-9_]{1,40}` is its own token.
- Otherwise the token is a lower-case prefix (non-`[a-z0-9_]` characters become `_`, at most 24 characters), then `-`, then the first 12 hex digits of the name's SHA-256.
- Every index header carries the exact field name, and a header whose field differs is ignored.

### 5.2 Generation switch (state machine)

A generation switch is used for:
- the change from the single to the sharded layout: PREPARE writes the shards one at a time, each read from the
  single segment by its key range, with its key-index checkpoint (§6.4) covering the whole file, and verifies that
  every live record is a line of its shard; the field and spatial indexes are built after PUBLISH;
- `restore()` of a present database (§10.3).

Creation has its own steps (§8.1), without INTENT or `g`.

Every step runs while `LOCK` is held exclusively. In durable mode, every persistent write is synced as in DURABILITY.md.

| Step | Action | After a crash here |
|---|---|---|
| PREPARE | Build `generations/X` completely (X = N+1) and verify it (a layout switch: every live record is a line of its shard; a restore: checksums, `checkIntegrity()`, expected record count). CURRENT still names N. A PREPARE that fails (an exception) removes X before it rethrows. | The record has no `g`. X is an unreferenced directory: reported by `checkIntegrity()`; the next PREPARE that reuses the number removes it first. |
| INTENT | Write the state record with `g=N>X` and a **new lock epoch** (synced), and `b` when X's `buffer/` holds a file (a `b` already set stays). From now on, no process can use a cache of the old epoch, and every process that takes the lock runs recovery before returning data. | Recovery (below). |
| PUBLISH | Replace `CURRENT` atomically with generation X. **Commit point.** | Recovery. |
| CLEANUP | Remove `generations/N` (in durable mode, then sync `generations/`). Readers read only under the lock and keep no data handle between operations, so no reader can be inside N. | Recovery. |
| DONE | Clear `g`, only once N is gone (and, in durable mode, its removal synced), and `b` unless X's `buffer/` holds a file. Otherwise `g` stays and the next session's recovery finishes the cleanup. | None. |

**Recovery** (`g=N>X` found; exclusive lock):

1. Read `CURRENT` and check validity (§4.3). Its generation must also be N or X.
2. CURRENT valid and names X → X is committed: remove N if it still exists, then clear `g`.
3. CURRENT valid and names N → X was never published: remove X, then clear `g`.
   - In both cases the recovery log gets `generation-recovered`. If the obsolete generation can't be removed (permissions, I/O), it gets `generation-cleanup-failed` instead. `g` is cleared anyway: CURRENT decides, so the data is consistent. The leftover is reported by `checkIntegrity()` rather than retried by every operation.
4. Otherwise (CURRENT unreadable, invalid, or naming anything else) → which generation was committed cannot be proven:
   - **nothing is removed and no generation is chosen**;
   - every read and write fails with `FORMAT_DAMAGED`, naming both candidate generations;
   - `checkIntegrity()`, `explain()` and `getMetrics()` fail with `FORMAT_DAMAGED` too, without naming them. They do so for every pending `g`, a recoverable one as well, and for an invalid CURRENT without `g`.
   - The documented ways out are `restore()` from a backup, or the manual procedure in OPERATIONS.md.

An invalid `CURRENT` without `g` is handled the same way: fail closed, nothing removed.

## 6. Segments

### 6.1 Data file `{seg}.jsonl`

- One JSON object per line, terminated by `"\n"`. Every record line carries `"key":K` as its **last** member.
- Lines are encoded as metadata is (§2): `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION`, `serialize_precision -1`.
- A delete appends a tombstone `{"__nonedb_deleted":K}`.
- An update appends the new record; the last line of a key wins.
- A line counts only if:
  - it is complete (ends with `"\n"`);
  - it decodes;
  - its key lies in the segment's range: `main` = `[0, ∞)`, `s{N}` = `[N·shardSize, (N+1)·shardSize)`.
- An unterminated last line is never a record. Readers ignore it.
- The next writer copies it to a verified quarantine file (§7.2) and cuts it before appending.
- A complete line that does not decode as a whole is analysed by the salvage rule: from right to left, the first `{` from which a record or tombstone with a valid key decodes up to the line end (tried first at the line's offset in the old index, then for at most 4096 `{`). If found, it is applied and logged; otherwise the line is skipped and logged.

### 6.2 Data replacement (segment compaction)

Compaction stays inside a generation (§5.2 is not used). Steps under the exclusive lock, after the temps an earlier
crash left of `{seg}.jsonl` are removed (§2):

1. **EPOCH:** new lock epoch (synced).
2. **DROP-CHECKPOINT:** remove `{seg}.kix`.
3. **REPLACE:** write the compacted data to a temp file, sync it, rename it over `{seg}.jsonl`, and sync the directory.
4. **CHECKPOINT:** write a fresh checkpoint with a new segment epoch `e`.

- A crash between any two steps leaves either the old or the new data file, never a mix.
- A missing checkpoint means a full replay of whichever file is there.
- Secondary indexes whose `e` differs are rebuilt.
- Compaction keeps keys. It writes a top tombstone when the highest live key is below the segment's highest assigned key, so keys are never reused.

### 6.3 Shard creation

- A shard is created by the first append to its key range: `{seg}.jsonl` is created in the active generation.
- The key reservation in `shards.json` and the `j` journal entry are written **before** the append.
- Data files that `shards.json` does not list are set aside as `{name}.orphan.{YmdHis}` (never deleted) before a shard of that number is created.

### 6.4 Key-index checkpoint `{seg}.kix` (derived)

The key index maps every live key of a segment to its record: byte offset and length of the line in
`{seg}.jsonl`. Its checkpoint is a binary file: a header, then the slots in checksummed blocks.

**Header:** the first 512 bytes, a JSON object padded with spaces to 511 bytes, then `"\n"`. Its last member is `crc` (§6.5).

```json
{"kind":"kix","v":1,"layout":"dense","base":30000,"slots":10000,"live":9998,"n":10000,"d":2,"t":2,"s":S,"h":"<crc32b>","e":"<8 hex>","created":T,"updated":T,"z":10000,"sid":3,"crc":C}
```

| Member | Meaning |
|---|---|
| `layout` | `dense` or `sparse` (below). |
| `base` | The key of local key 0: `sid × z` for a shard, 0 for `main`. |
| `slots` | Dense: number of slots (local keys `0 … slots-1`). Sparse: number of entries. |
| `live` | Live keys in the body. |
| `s` | Bytes of the data file the checkpoint covers; always a line end. |
| `h` | crc32b of the up to 256 bytes before `s`. |
| `e` | Segment epoch. |
| `n` | Next local key. |
| `d` | Garbage lines. |
| `t` | Tombstones. |
| `z`, `sid` | Shard size and shard id; sharded layout only. |

**Body:** starts at byte 512, in blocks of 4096 bytes. The last block may be shorter.
- Each block ends with the crc32 of its other bytes: 4 bytes, little-endian, `hash('crc32b')` as an unsigned integer.
- **Dense layout:** a block holds the slots of 341 consecutive local keys (the last block: the rest, `m` slots).
  - First the `m` offsets (u64, little-endian), then the `m` lengths (u32, little-endian).
  - Local key `k` is in block `b = ⌊k / 341⌋` at index `i = k mod 341`. Its offset is at `8i` and its length at `8m + 4i` of the block (`m` = 341 except in the last block).
  - Length 0 means the key has no record (never written, or deleted).
  - Separate offset and length runs let a whole block be decoded in a few operations.
- **Sparse layout:** a block holds up to 255 entries, ascending by key; only the last block may hold fewer (`m`).
  - First the `m` local keys (u32), then the `m` offsets (u64), then the `m` lengths (u32), all little-endian.
  - Then zero bytes up to 4092, then the crc.
- **Choice of layout:** dense while `slots ≤ max(1024, 4 × live)`, otherwise sparse. A segment of a deletion-heavy database thus stays bounded by its live keys.
- **Limit:** local keys (key − `base`) are below 2^32.

**Rules:**
- **Fits** its data file when `kind`, `v` and `layout` are known, `s ≤ size`, byte `s-1` is `"\n"`, and `h` matches.
- A block whose crc doesn't match makes the whole file invalid. So does a body whose length doesn't match the header.
- Loading reads the checkpoint and replays the complete lines after `s`.
- **Does not fit** (`s` or `h`): a full replay rebuilds it under the exclusive lock, with a new `e`.
- **Absent, invalid, or `v` unknown:** no checkpoint. The lines are replayed from the start in memory, under the shared lock too, and the next write session writes a checkpoint.
- Writes append data only. Checkpoints are written at the end of a write session once the tail reaches 64 lines + live/16, or 1 MiB.
- In memory a key index is the verified body (12 bytes per slot) plus the keys written or deleted since the checkpoint.
  - A body is decoded into per-key arrays when it has at most 250 000 keys and memory allows (the process, with about 80 bytes per key more, stays under half of `cacheMemoryLimit`; no limit: always):
    - in a database of at most 16 segments (each is in constant use): at its first use when the body is at most 64 KiB (about 5000 keys, under 1 ms to decode), except in the process's first operation (lock session), where a single cold operation keeps the body; otherwise at the start of the database's next operation, whether that operation reads the index or not (`rebuildIndex()`, which makes the key indexes again, decodes none);
    - otherwise once its use has paid for it.
    - Paid for: its operations (lock sessions) plus the keys looked up in bulk reach an eighth of its live keys. Decoding costs about 0.17 µs per key; a lookup costs about 1.3 µs more on a body than on arrays.
  - Light use keeps the body: a cold query over many segments, a few reads of each segment.

### 6.5 Secondary indexes (derived)

- **Header and validity:**
  - The header of every derived file (`.kix`, `.fx`, `.gfx`, `.rx`, `.grx`) ends with the member `crc`: the crc32, a decimal integer, of the header's JSON without that member. A header whose `crc` doesn't match makes the file invalid.
  - The body of an `.rx` file is one line of JSON, id → node, then the entries of its leaves in the order of those nodes, each five 64-bit little-endian integers (key, then its box). A node is `[level, box, items]`: a box is four integers (null only without items), the items of a leaf (level 0) the number of its entries, the items above a leaf the ids of nodes of a lower level, each named once. The header's `bc` is the crc32 of the body. A body that doesn't match it, or that is not such a tree, makes the file invalid. (The other formats check their pages or blocks; a `.grx` has no body.)
  - A number in a file never decides how much is read: a page, a body or a record the file doesn't hold makes the file invalid.
  - Line 1 of an `.fx`, `.gfx`, `.rx` or `.grx` file is a JSON header that starts with `kind`, `v` and `field`; the body follows. An `.fx` or `.rx` header holds `s`, `e` and `h` (an `.fx` has `w` before them, an `.rx` `q` and `ns`); a global header holds one such entry per shard in `sh`.
  - A segment index (`.fx`, `.rx`) is valid when `kind`, `v` and `field` match, `e` equals the segment's checkpoint epoch, `s` ≤ the checkpoint's `s`, and `h` matches. Loading replays the data lines after `s`.
  - An invalid index is rebuilt from the data under the exclusive lock.
- **Global indexes:**
  - `.gfx` and `.grx` hold, per shard, `{s, e, h}` (`.grx`: and the shard's box) in the header member `sh`, and the lock epoch `le` they were written under. An entry describes the shard's segment index at `s`.
  - A shard is skipped only when its entry fits its data, its summary rules the value out, and the newer lines don't hold it. A shard without an entry is never skipped.
- **Version rule:** an index or checkpoint file whose `v` is unknown is rebuilt from the data. Derived files are never trusted without the checks above.

#### 6.5.1 Field indexes: paged files (`.fx`, `v` 1)

A field index maps each value of a field to the keys of a segment that hold it. The file is a static B+tree, so a lookup reads a few small pages instead of the whole file.

- **Entries:** one per value, identified by its bucket id (the typed identity `Values::valueKey`, a string that begins with a type letter: `n`, `t`, `f`, `i`, `d` or `s`). Entries are sorted by bucket id in byte order.
- **Header:** the JSON line starts with `kind`, `v`, `field` and `w`, in this order. `w` is a token of this write: 16 random hex digits.
  - It also holds `pg`: `{"root": [offset, length], "levels": L, "pages": P, "data": D}`.
  - Offsets are counted from the first byte after the header line.
  - `data` is the number of bytes of the data pages, which come first.
  - It adds `mk` (highest key covered), `c` (entries) and `n` (keys).
- **Pages:** a page holds about 4096 bytes in all. An entry larger than that is a page of its own. Every page ends with a crc (below).
  - **Data page:** its entries as one JSON object, then the crc.
  - **Directory page:** its entries, then a table of where they start, then the crc. The table is one u32 per entry, the offset of the entry from the page's first byte, ascending; then the number of entries (u16).
- **Data pages:** a JSON object with one member per entry, `"<bucket id>":<keys>`, in bucket id order, without spaces.
  - `<keys>` is the key (a JSON integer) when the entry has one key, else a JSON array of its keys, ascending.
  - **Canonical JSON:** names are written as PHP `json_encode` writes them with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS`: only `"`, `\` and the control characters below U+0020 are escaped. Bucket ids are valid UTF-8 (a record that isn't can't be stored).
  - Decoding a page whole is one `json_decode`; a decoded page is already the in-memory form of its entries.
- **Directory pages:** entries of the first bucket id of a child page (u32 length + bytes: a string value has no length limit), then the child's offset (u32) and length (u32). All integers little-endian.
  - A directory page holds at least two entries, even when they make it larger than 4096 bytes (bucket ids of more than about 2 KiB): each level has fewer pages than the one below. Only the last page of a level may hold one.
  - Level 1 points at data pages, level 2 at level-1 pages, and so on.
  - The root is the only page of the top level.
  - With one data page there is no directory: the root is that data page (`levels` 0).
- **Checksums:** every page ends with the crc32 of its other bytes (u32, `hash('crc32b')` as an unsigned integer). A page whose crc doesn't match, or a directory entry that leaves its page, makes the file invalid.
  - A whole read also checks each page: a table names exactly the entries of its page; a data page decodes to a JSON object whose canonical JSON is the page's bytes.
  - A lookup checks that the offsets it follows lie inside the entries.
- **Lookup:**
  1. Read the root.
  2. In each directory page, follow the last entry whose first bucket id is ≤ the one searched.
  3. Read the data page and find the entry: the bytes `"<bucket id>":` (canonical JSON) at a position whose preceding byte is `{` or `,`. Such a match starts a member name, since a bucket id begins with a type letter (a closing quote is followed by `:`, not by a letter), and the name is exactly the bucket id. The value follows. Equivalently: the name without its opening quote, preceded by `{"` or `,"` (inside a name a quote is always escaped as `\"`, and a value holds no quote). The search never leaves the page's bytes.
  4. Replay the data lines after `s`: a key whose newest line holds another value, or a tombstone, is not a key of this value; a newer line with this value adds its key.
- **Whole read:** the data pages in order (bytes `0 … data`).
  - A process loads an index whole for ranges and other uses of all its values.
  - It also loads one in use, only while memory allows (the process, with the decoded index, under half of `cacheMemoryLimit`; no limit: always):
    - an `.fx` file of at most 64 KiB at its first use in an operation other than the process's first (lock session); in the first, a lookup reads its pages. Decoding pays off only when more operations follow, and the decodes a process needs then happen in the same operation as its key indexes' (§6.4);
    - a larger `.fx` once its operations have paid for the load: at least two, and `c / 20` of them (a whole load costs about one paged lookup per twenty entries).
- **Pages kept between lookups:** a reader may write a new checkpoint under the shared lock, so the file can be replaced while another process keeps pages of the old one.
  - Before reading another page, that process reads the header up to the end of `w` from the same handle.
  - If those bytes differ from the header it holds, the file was replaced. The kept pages are dropped and the lookup starts again from the new header.
  - Pages of one file are never combined with pages of another.
  - **Body kept as bytes:** an `.fx` in use from its second operation whose body (bytes `0 … root end`) is at most 256 KiB, while memory allows twice its size, is read whole at its next page miss: from the lookup's handle, after the header check. From then on its pages come from those bytes: a page's crc is checked at its first use, then the entry is searched for in place (lookup step 3). Nothing is decoded, so this is not a whole read; the bytes are the pages of one file and follow the rules above.
- **Unchanged:** these files are still derived, written whole by atomic replacement and never synced. A writer never changes them in place.

#### 6.5.2 Global field index: shard filters (`.gfx`, `v` 1)

The global field index of a sharded database tells which shards may hold a value: per shard, a Bloom filter of the bucket ids of the shard's `.fx`. The filters are stored bit-sliced: row `r` holds bit `r` of every shard's filter, one bit per shard, so a lookup ANDs six short rows, whatever the number of values.

- **Size:** four to eight bytes per value of the largest shard, per shard (≥ 32 bits per value). Building, updating and looking up never hold more than the file.
- **Header:** the JSON line starts with `kind` (`"gfx"`), `v` (`5`), `field` and `w`, in this order, then:
  - `le`: the lock epoch it was written under (§6.5);
  - `bits` M (of each shard's filter) and `width` W (shards a row has room for): powers of two, M at least 64, W at least 8;
  - `sh`: `{"<shard>": {"s":…,"e":…,"h":…}}`, the `.fx` checkpoint each shard's filter was made from (§6.5). A shard id is below W.
- **Body:** M rows of W / 8 bytes; bit `i mod 8` (value `1 << (i mod 8)`) of byte `⌊i / 8⌋` of row `r` is bit `r` of shard `i`'s filter. The bits of a shard without an entry mean nothing.
  - Rows are grouped into pages of P = min(M, max(1, 4096 / (W / 8))) rows, in order: page `p` holds rows `p · P … p · P + P − 1`, then the crc32 of those bytes (u32 little-endian). Page `p` starts at `p · (P · W / 8 + 4)` from the first byte after the header line.
- **Probes of a bucket id:** `d` = `hash('xxh3', id, true)` (8 bytes); `x` = bytes 0–3 of `d` as a u32 big-endian; `y` = bytes 4–7 as a u32 big-endian, its lowest bit set. For `i` = 0 … 5: row `(x + i · y) mod 2³² mod M`.
- **Filter of a shard:** M bits, those of every probe row of every bucket id of its `.fx` (at `s`) set, and no other.
- **Lookup of a value:** the AND of its six probe rows; a shard may hold the value when its bit is set in it. It is skipped as §6.5 says: its entry fits, its bit is clear, and the newer lines don't hold the value. A shard that may hold it costs a lookup in its `.fx` (of the shards whose filter rules the value out, at most about 1 in 40 000 answer "may"), never a wrong answer.
  - Rows read from the file: the header up to the end of `w` is read again first from the lookup's handle (§6.5.1: a replaced file is read again), then the pages that hold the probe rows, each crc checked.
  - A process that doesn't make the exact map below loads the body whole at the file's second operation (lock session) when memory allows (as an `.fx`, §6.5.1: under half of `cacheMemoryLimit` with it), checking every page's crc; its lookups then read the rows from memory. A body held so is that of one file: the entries it came with say which bytes of each shard it describes (§6.5).
  - **Exact map:** at the same second operation, in a process of PHP's command line (SAPI `cli`) and a database of at most 1 000 000 records (its metadata's `totalRecords`), a process with room for it (under half of `cacheMemoryLimit` once it is built) reads every shard's `.fx` file that fits its data and keeps a map of their bucket ids to the shards that hold them, with an entry per shard (`s`, `e`, `h` of that file). The build reads those files whole, every key they list; in a larger database that takes seconds, so no map is made there and nothing is read for one. A web server's request ends with its statics, before a map could pay for itself, so no other SAPI makes one either. The file's sizes bound the map first: at most `bits / 32` values per entry of `sh`, about 32 bytes a pair; a map whose bound doesn't fit the room left is not built, and nothing is read for it. A file whose buckets the process holds exactly as the file has them (read whole, no line replayed since) is not decoded again. Its lookups are a map lookup; §6.5's rule applies to its entries as to the file's. When the process replaces a shard's filter, the map takes the shard's new entry and its values (a value the shard no longer holds may stay listed: the shard is then read for it). Not built in `checkIntegrity()`, `explain()` or a join; it changes nothing on disk.
- **Sizes:** `bits` is the smallest power of two ≥ max(64, 32 · c) for the largest `c` (entries) of the shards' `.fx`; `width` the smallest power of two ≥ max(8, highest shard id + 1). A build takes `c` and each entry from the header of a shard's `.fx` that fits its data, and decodes each file for its bucket ids, once per part of the build (the cache limit decides the parts).
- **Update:** when a shard's `.fx` is checkpointed, its filter and entry are replaced; once per session, after its last change (§6.5). If its `32 · c` exceeds M or its id is ≥ W, the file is rebuilt with the new sizes from every shard's `.fx`. Otherwise every page is copied with the shard's bits replaced. Either way the file is written whole by atomic replacement, derived and never synced.
- **Damage:** a page whose crc doesn't match, or a header that isn't valid, makes the file invalid. It is rebuilt from the shards' `.fx` under the exclusive lock; until then it skips no shard.

## 7. Recovery files and journal flags

### 7.1 Recovery log `recovery/log.jsonl`

- One JSON object per event: `{"t":…,"action":…,…}`, with `seg` on salvage and skip events. The file rotates to `log.1.jsonl` past 1 MiB.
- Events record what an exclusive session did:
  - quarantine and cut,
  - salvage, skip line, dropped line, dropped buffer,
  - FORMAT repair, shard metadata rebuilt, index dropped,
  - a generation recovered, or its cleanup failed,
  - deferred job failure,
  - restore, foreign restore.
- Store-level events (a STORE copy repaired, a manual rebuild) go to `.nonedb-maintenance/log.jsonl` (§3).
- The log never contains record values or key material.

### 7.2 Quarantine `recovery/q.{seg}.{off}.{len}.{sha256}.bin`

Bytes that must be removed from a data or buffer file are first copied here. `{seg}` is the segment (`main`, `s{N}`) or `buffer` for the pending buffer and the flushing files; recovery events about the bytes use `{seg}.{off}.{len}.{sha256}` as their id. The copy is read back and its length and SHA-256 are verified before the source is cut. A failure leaves the source unchanged and fails the write.

### 7.3 Journal `j` / `J`

- `j` (or `J` for all segments) is set before a sharded segment's data changes. It is cleared at the end of the session.
- When found at lock time, the next exclusive session:
  - recounts the shard counters from the data,
  - removes stale temp files,
  - re-validates the listed segments.
- A failed rollback sets `J`.

## 8. Database lifecycle

### 8.1 Creation

Creation takes `STORE.LOCK` shared and re-validates the store (§3.3). Then:

1. `mkdir {locator}.ndb4`. A creator that loses this race goes on: `LOCK` serializes them.
2. Open `LOCK` and lock it exclusively.
3. Write the state record `{gen}:{epoch} c` (synced). The epoch is new for a new record; a re-creation keeps the one the drop wrote.
4. Generation 1 (§5.2 PREPARE) and `CURRENT`. Generation 1 is empty, or a backup's copy (`restore()`, §10.3);
   a copy that holds a buffer file sets `b` (synced) before `CURRENT`.
5. `FORMAT` (atomic). Its existence means "creation complete".
6. Clear `c`.

No data is written before step 6, except the copy of a restore, which its backup still holds.

### 8.2 Drop

1. Write the state record with `d` and a new epoch (synced).
2. Remove `FORMAT`. The database no longer exists (commit point). If `FORMAT` can't be removed, `d` is cleared, nothing else is touched and `dropDB()` fails naming the files.
3. Remove `CURRENT`, `generations/` and `recovery/`.
4. Clear `d`. If files are left, `d` stays and the next exclusive session removes them (the drop is committed).

`LOCK` and the directory stay. A directory holding only `LOCK` is an empty location (§8.3).

### 8.3 Interpreting a database directory

On every path that needs the database, the engine resolves the directory's state as follows. Resolving may upgrade to the exclusive lock. `checkIntegrity()`, `explain()` and `getMetrics()` resolve nothing and write nothing: they report `c` and `d` as pending recovery, and fail with `FORMAT_DAMAGED` on a Damaged directory, with or without the proof a repair needs. The next read or write repairs what it can prove.

| State | Evidence | Behaviour |
|---|---|---|
| Absent | No directory, or only `LOCK` and no `c`/`d` flag | Reads return empty results and create nothing; writes create the database (§8.1). |
| Interrupted creation | No FORMAT, `c` set | Nothing was committed: creation writes no data, and a restore's copy is still in its backup. Remove the partial `CURRENT`/`generations`, clear `c`, then treat as **Absent**. |
| Interrupted drop | `d` set | Finish the drop (§8.2), then treat as **Absent**. |
| Creation finished, flag left | FORMAT valid, `c` set | Clear `c`. |
| Damaged | FORMAT missing or invalid, no `c`/`d`, entries other than `LOCK` | **Never treated as absent.** |
| Present | FORMAT valid | Open; CURRENT is checked by §4.3 and §5.2. |

**Repairing a Damaged directory:**
- A repair happens only with full proof: a valid CURRENT whose generation `metadata` is valid and whose identity members agree.
- If there is proof, FORMAT is rewritten from `metadata` and the repair is logged.
- If not, `FORMAT_DAMAGED`. No file changes and the data stays.
- The way out without proof is `restore()` from a backup (§10.3), which keeps the damaged generations.

## 9. Maintenance

### 9.1 Maintenance journal `.nonedb-maintenance/journal.json`

```json
{"magic":"NONEDB4","kind":"journal","op":"rotate","id":"<run id>","state":"COMMITTED","targets":[...],"store":{...},"clear":[...],"checksum":"<sha256>"}
```

- `op` is one of: `migrate`, `rotate`, `register`, `repair-store`, `restore-old`.
- `store` is the complete target STORE record, `clear` the directories whose `m` the commit clears. A rotation writes `store` from `STARTED` on, `register` and `repair-store` start at `COMMITTED`. `repair-store` adds `mode`.
- **While the file exists:**
  - opening a store fails with `MAINTENANCE_IN_PROGRESS`;
  - re-validating a store (§3.3) fails the same way.
- A finished journal is moved out as `journal.done.json` (`restore.done.json` after `restore-old`) into the operation's backup directory, or removed.

### 9.2 Order (normative)

1. Lock `STORE.LOCK` exclusively (`LOCK_EX|LOCK_NB`).
2. Lock every target database's `LOCK` in locator order, also `LOCK_EX|LOCK_NB`.
   - If any lock fails: release all, `MAINTENANCE_BUSY`. **No journal, epoch or `m` flag has been written.**
3. Write the journal, state `STARTED` (a migration: `DISCOVERED`, §9.5), with the operation and its targets.
4. Write `m` and a new epoch into every target's state record (synced). A rotation releases the database locks here: `m` keeps every process out, and step 7 takes each lock again to clear it.
5. Do the work. A re-run works out from the file system how far it came.
6. **Commit:** write the journal with state `COMMITTED` and the target STORE record. **This is the logical commit point.**
7. Apply:
   1. write the STORE copies (§3.1) with the target content;
   2. clear `m` with a new epoch;
   3. close the journal;
   4. release `STORE.LOCK`.

**On re-run:**
- Before `COMMITTED`: roll forward, or roll back with the `abort` option.
- From `COMMITTED` on: only roll forward (idempotent).
- A failure while applying step 7 is not reported as success: `MAINTENANCE_IN_PROGRESS`, with a message that says it is committed but not completed and must be run again (a failed directory sync of a STORE copy: `OUTCOME_UNKNOWN`). The journal stays open, so the store stays closed until the re-run.
- If the `COMMITTED` journal write cannot be confirmed durable: `OUTCOME_UNKNOWN`. The re-run decides from the journal on disk.

**Lock order rule for the whole engine:** `STORE.LOCK` before any database `LOCK`, and database `LOCK`s in locator order. CRUD takes only a database `LOCK`. The missing-database path and creation take `STORE.LOCK` shared first.

### 9.3 Key operations

- **`registerKey($dbDir, $registered, $new)`:**
  - Needs `$registered` to be in the STORE record.
  - Journal → `COMMITTED` (STORE with the new keyCheck added) → copies → close.
  - No database lock is needed.
- **`migrateKey($dbDir, $old, $new, $options)`:**
  - Targets are every database of `$old`. If `{new locator}.ndb4` already exists for one of them, the rotation is refused before anything is written (`MAINTENANCE_BUSY`, naming the database).
  - In step 5, each `{old locator}.ndb4` is renamed to `{new locator}.ndb4`. FORMAT and metadata are key-independent and unchanged.
  - Every renamed directory is then verified: FORMAT, CURRENT, metadata and `databaseId`. A failure reverses the renames, clears `m`, closes the journal and ends with `FORMAT_DAMAGED`. If a rename back fails, the journal stays open (the store stays closed) and the result is `MAINTENANCE_IN_PROGRESS`; `['abort' => true]` finishes the reversal.
  - Commit: the STORE record without the old keyCheck and with the new one.
  - Running processes are covered in both cases:
    - **During the rotation:** a process finds `m` in the state record once it has the `LOCK`, so it gets `MAINTENANCE_IN_PROGRESS`.
    - **After the rotation:** a process that still uses the old locator finds the path gone. Every lock acquisition checks that its path still names the locked file. The process then takes the missing-database path (§3.3), which re-validates the store and fails with `KEY_NOT_REGISTERED` (or `MAINTENANCE_IN_PROGRESS` while the journal is open). It never gets an empty result and never creates a directory.

### 9.4 `repairStore($dbDir, array $keys, array $options)`

Only for the case where both STORE copies are invalid. One valid copy is repaired automatically (§3.1). The result names the mode and whether it is complete.

**Ownership rule (both modes):** every `*.ndb4` directory must belong to one of `$keys`, i.e. `locator(key, FORMAT.name)` equals its name. Otherwise: `FORMAT_DAMAGED`, the unattributable directories are listed, and nothing changes.

| Mode | Input | Result |
|---|---|---|
| A — verified manifest | `['manifest' => path]` or `['manifest' => storeManifest]` | The manifest is a complete STORE record from a maintenance result, a `backup()` (`store.json`) or the migration tool's backup; its checksum must verify. The new record is the manifest's keys ∪ `$keys`. Result `complete: true`: the manifest's state is restored completely. Keys registered after the manifest that have no database are outside this guarantee by definition. |
| B — manual rebuild | `['confirmManualRebuild' => true]` | The new record is `$keys`. Result `complete: false`, with the warning that earlier keys without databases cannot be recovered. Logged as `manual-store-rebuild`. Without the confirmation option: refused. |

Every successful key operation returns the new STORE record, checksum included, as `storeManifest`; it can be passed to `repairStore()` as it is or saved as a file. The documentation tells operators to keep it.

### 9.5 Migration publish (tool)

The migration tool (MIGRATION.md) follows §9.2 and adds:

- **Before each publish:** the source fingerprint is re-checked under the locks. The staged database's state record carries `m` **before** it is renamed into `dbDir`.
  - A published database therefore answers `MAINTENANCE_IN_PROGRESS` until the commit, even when its key is already registered.
- **Commit:** the target STORE record has the key added; if the key was already registered, it is unchanged.
- **Apply after commit:** STORE copies → move the sources to `.nonedb-retained` (§9.6) → clear `m` on the published databases → close the journal.
- **Collision:** if the target `{locator}.ndb4` already exists, that database is `AMBIGUOUS_SOURCE`. Nothing is merged or overwritten.
- **Key registration:** a key is registered only by the run that completes the migration of all of its old databases in `dbDir`.

### 9.6 Retained sources `.nonedb-retained/{runId}/`

- After the commit, the migrated databases' source files are moved here by rename, the files under `files/`, together with a `manifest.json` (file names, sizes, SHA-256, backup reference).
- They are safe to ignore when both hold:
  1. every database they belong to is committed;
  2. their bytes equal the verified backup.
- The engine never looks inside.
- `--cleanup` re-verifies the backup and removes the directory. `--restore` moves the files back.

## 10. Backup and restore of a database

`backup($db, $dir)` copies one database, as it is at one moment, into a directory of its own.
`restore($dir, $db, $options)` makes such a copy the content of a database, with one commit.

### 10.1 Backup directory

```text
{dir}/                    0700; files 0600
  FORMAT                  the database's FORMAT and CURRENT, byte for byte
  CURRENT
  generations/{G}/...     the files of the active generation G that §5.1 names, byte for byte; a data or
                          buffer file only up to its last "\n" (an unterminated tail is never a record, §6.1)
  recovery/...            the database's recovery log and quarantine files (for inspection only)
  store.json              the STORE record (§3.1), sealed as STORE is: a manifest for repairStore() (§9.4)
  manifest.json           written last
```

The layout is that of a database directory (§4), so the copy is checked by the same rules. Temp files and
data files the database set aside (`*.orphan.*`, §6.3) are not part of it.

**`manifest.json`:**

```json
{"magic":"NONEDB4","kind":"backup","storageFormat":4,"databaseId":"<uuid>","name":"users","generation":3,"nextKey":123456,"records":120001,"bufferedRecords":0,"takenAt":1793103240,"files":[{"path":"CURRENT","size":118,"sha256":"<hex>"},...],"checksum":"<sha256>"}
```

- `nextKey` and `records` are the database's counters when the copy was taken (those `getShardInfo()`
  reports as `nextKey` and `totalRecords`). `bufferedRecords` counts the records of its buffer files.
- `files` lists every other file of the backup, sorted by `path` (byte order): the path relative to `{dir}`
  with `/` separators, the size and the SHA-256.

A backup is **valid** when all of these hold; otherwise it is incomplete or damaged:
1. `manifest.json` is valid (§2), with `kind` `backup` and `storageFormat` 4.
2. Every path in `files` is one of:
   - `FORMAT`, `CURRENT`, `store.json`;
   - `recovery/{name}`, a name of `[A-Za-z0-9._-]` that is not `.` or `..`;
   - `generations/{G}/` (G = the manifest's `generation`) followed by `metadata`, `shards.json`,
     `indexes.json`, `segments/{seg}.jsonl`, `segments/{seg}.kix`, `indexes/{seg}.{token}.fx`,
     `indexes/{token}.gfx`, `spatial/{seg}.{token}.rx`, `spatial/{token}.grx`, `buffer/pending.jsonl` or
     `buffer/flushing.{K}.jsonl` (seg and token as in §1 and §5.1).
3. `files` holds `FORMAT`, `CURRENT`, `store.json` and `generations/{G}/metadata`.
4. Every listed file exists, is a regular file, and has its size and SHA-256.
5. `FORMAT` is valid with the manifest's `databaseId` and `name`; `CURRENT` is valid (§4.3) and names G;
   `generations/{G}/metadata` is valid with the same identity.

Files the manifest does not list are ignored. The checksums detect damage, not a deliberate change: a
backup directory needs the protection dbDir has.

### 10.2 `backup($db, $dir)`

1. `$dir` must lie outside `dbDir` once symbolic links are resolved, and must not exist or be an empty
   directory. Otherwise: `InvalidArgumentException`, and nothing is created.
2. Lock `STORE.LOCK` shared and check the store: an open maintenance journal is `MAINTENANCE_IN_PROGRESS`,
   an unregistered key `KEY_NOT_REGISTERED`. Then lock the database's `LOCK` shared. Acquiring it recovers
   what any operation would (§4.2). A database that does not exist is a `RuntimeException`, and nothing is
   created. Both locks are held until step 5:
   - the copy is the state of one moment: data, buffer, counters, indexes and the STORE record;
   - writers of this database wait (`lockTimeout`), and maintenance fails with `MAINTENANCE_BUSY`; other
     databases are not affected;
   - nothing of the database is written: the buffer is not flushed, and no checkpoint is written.
3. Copy the files of §10.1, hashing what is copied.
4. Read every copy back. Its size and SHA-256 must be what was copied.
5. Write `manifest.json` by atomic replacement. In durable mode, every copy and every directory is synced
   before it.

A failure removes what the call created, then rethrows. An interrupted backup has no `manifest.json`, and
`restore()` refuses it.

### 10.3 `restore($dir, $db, ['force' => false])`

**1. Verify** the backup by §10.1. An invalid backup is `RESTORE_REFUSED`, and nothing is written.

**2. Lock** the database's `LOCK` exclusively, as a write does. An absent database takes the creation path
(§8.1, under `STORE.LOCK` shared). Inside a write to the database that has not committed, `restore()` is a
`LogicException`. The state is resolved as for any operation, with one exception: a **damaged** database
(FORMAT or CURRENT invalid and no proof, §5.2, §8.3) is handed to `restore()` instead of failing. This is
the documented way out of `FORMAT_DAMAGED`.

**3. Identity** of the restored database. It is decided before anything is written: a refusal leaves the
store byte for byte as it was.

| Target | Without `force` | With `force` |
|---|---|---|
| Present or damaged, FORMAT valid, the backup's `databaseId` | FORMAT's identity | The same. |
| Present or damaged, FORMAT valid, another `databaseId` | `RESTORE_REFUSED` | FORMAT's identity; FORMAT stays as it is. A `foreign-restore` event. |
| Absent, or damaged with FORMAT invalid; the backup's `name` is `$db` | The backup's identity. | The same. |
| Absent, or damaged with FORMAT invalid; another `name` | `RESTORE_REFUSED` | A new identity: a new `databaseId`, `createdAt` now, the backup's `shardSize` and `formatOptions`. A `foreign-restore` event. |

A sharded backup whose `shardSize` differs from the identity's is `RESTORE_REFUSED`, with `force` as well:
its shard files would not fit the key ranges.

**4. PREPARE** the new generation X:
- present: X = N + 1 (§5.2);
- absent: X = 1, inside the creation (§8.1);
- damaged: X = 1 + the highest number under `generations/`.

Then:
1. **Copy** the backup's `generations/{G}/` into `generations/X/`, except `metadata`. Each file is written
   with the durability of its kind (DURABILITY.md §3): data and buffer files as replaced data files,
   `shards.json` and `indexes.json` as records, derived files unsynced. Every copy is read back: it must
   hold the backup's bytes (size and SHA-256).
2. **Metadata:** write X's `metadata` with the identity of step 3, generation X and the layout of the
   backup's `metadata`.
3. **Next key.** T is the database's next key:
   - present: N's next key;
   - absent: 0;
   - damaged: 1 + the highest key of any complete line in any segment file under `generations/`. The keys a
     damaged database handed out are not known otherwise.

   When T is above the manifest's `nextKey`:
   - a top tombstone `{"__nonedb_deleted":T−1}` is appended to the segment of key T−1: `main` in the single
     layout, `s{⌊(T−1)/shardSize⌋}` in the sharded one (created, with a shard entry, when the backup has
     none);
   - the sharded `shards.json` gets `nextKey` T.

   New keys then start at max(T, the manifest's `nextKey`): a restore never lets keys go back. The records
   of a `flushing.{K}` file keep the keys from K on that its name gave them (§5.1).
4. **Verify X.** `checkIntegrity()` runs over X as if it were the active generation, and writes nothing.
   - Its `records` and `bufferedRecords` must equal the manifest's.
   - The shard list and the counters must fit the data (no `meta` mismatch).
   - The next key must be max(T, the manifest's `nextKey`).

   Otherwise: `RESTORE_REFUSED`. X is removed, and the database stays as it was.

**5. Commit:**
- **Present:** INTENT → PUBLISH → CLEANUP → DONE (§5.2). Generation N is removed only after X is published.
- **Absent:** `CURRENT`, then `FORMAT` (the commit point), then `c` is cleared (§8.1).
- **Damaged:**
  1. the state record gets a new epoch, loses `g`, and gets `b` when X's `buffer/` holds a file (synced);
  2. `CURRENT` is replaced to name X: the commit point;
  3. an invalid FORMAT is rewritten with the identity. A crash before that is repaired from X's `metadata`
     (§8.3).

  The earlier generations stay as unreferenced directories: nothing proves which of them was committed.
  `checkIntegrity()` reports them, and OPERATIONS.md says how to inspect and remove them.

**6. Log** a `restore` event in `recovery/log.jsonl` (`foreign-restore` when `force` changed the identity):
generation X, the backup's `databaseId` and `takenAt`, and `records`. The database's earlier `recovery/`
stays.

**After a crash**, the database opens either as it was or as restored:
- present: by the recovery of §5.2;
- absent: an interrupted creation is removed (§8.3);
- damaged: it stays damaged until `CURRENT` names X.

## 11. Errors

| Code | Constant | Meaning |
|---|---|---|
| 3301 | `OUTCOME_UNKNOWN` | A failure after a commit point, or an undo that could not be completed. The next access recovers. |
| 3302 | `LOCK_FAILED` | A lock was not acquired within `lockTimeout`, or was lost. |
| 3304 | `UNSUPPORTED_FORMAT` | The location is not a noneDB 4 store or database, or it holds foreign entries on the missing-database path. |
| 3305 | `FORMAT_DAMAGED` | A noneDB 4 identity or pointer record is invalid and cannot be repaired with proof. Data files are kept. |
| 3306 | `UNSUPPORTED_DURABILITY` | Durable mode was requested on a platform that cannot provide it. |
| 3307 | `DURABILITY_FAILED` | A required sync failed before the commit point; the operation was rolled back. |
| 3308 | `KEY_NOT_REGISTERED` | The configured key is not in the STORE record. |
| 3309 | `MAINTENANCE_IN_PROGRESS` | A maintenance journal is open, or the database's state record has `m`. |
| 3310 | `RESTORE_REFUSED` | The backup is incomplete or damaged, belongs to another database (without `force`), or has another shard size than the database (§10.3). Nothing was changed. |
| 3311 | `MAINTENANCE_BUSY` | Maintenance could not take every lock it needs. Nothing was changed. |

All are thrown as `RuntimeException` with the code above, except that `migrateKey()`, `registerKey()` and `repairStore()` return theirs in the result array (`success` false, `error`, `code`).

## 12. Directory listings

The engine lists a directory only in these cases:
- when a store is first created (§3.2);
- on the missing-database and creation paths (§3.4);
- in `getDBs()`;
- in recovery triggered by `g`, `j`, `J` or a damaged record;
- in `checkIntegrity()`, `getMetrics()` of a database, `backup()`, `restore()`, `dropDB()`, a generation switch and maintenance;
- when a write creates a shard, for files an earlier crash left under its number;
- before a compaction, for the temps of §6.2;
- when a buffer is flushed, for its `flushing` files;
- when `shards.json` or `indexes.json` is derived again from the files.

Other reads and writes of an existing database list no directory. A test counts the listings.
