# noneDB Backup and Restore

How to back up a database while it is in use, how to check a backup, and how to bring it back. The rules are
normative in [STORAGE_FORMAT.md](STORAGE_FORMAT.md) §10; the methods are in [API.md](API.md#backup-and-restore).
The examples run in order (CI runs them).

```php
$db = new noneDB();
$db->insert("users", [["name" => "John", "plan" => "pro"], ["name" => "Jane", "plan" => "free"]]);
$db->createFieldIndex("users", "plan");
```

---

## 1. What a backup is

`backup($db, $dir)` copies **one database, as it was at one moment**, into a directory of its own:

- **One moment.** The backup holds the store's lock and the database's lock (both shared) until the copy is
  complete: data, write buffer, counters, indexes and the store record all belong to the same state. Writers of
  this database wait meanwhile (up to their `lockTimeout`); readers and other databases don't.
- **Nothing of the database is written.** The write buffer is copied, not flushed; no checkpoint is written.
- **Verified.** Every copy is read back and compared before `manifest.json` is written, last. A backup without a
  manifest (interrupted, failed) is refused by `restore()`.
- **Protected.** The directory is `0700`, its files `0600`. It must lie outside `dbDir` and must not exist or be
  empty.
- **Complete for a restore.** It holds `FORMAT`, `CURRENT`, the active generation (data, buffer, shard list,
  indexes), the recovery log and `store.json`, the store record. Indexes come along, so a restored database
  answers at full speed at once.

```php
$dir = __DIR__ . '/backups/users-' . date('Ymd-His');
$result = $db->backup("users", $dir);
$result['records'];   // 2
$result['files'];     // the files copied (manifest.json not counted)
```

The directory follows the layout of a database directory:

```text
users-20261029-142233/
  manifest.json        written last: identity, counters, every file's size and SHA-256
  store.json           the STORE record: keep it for repairStore()
  FORMAT, CURRENT
  generations/1/...    metadata, segments, indexes, buffer
  recovery/...         the database's recovery log and quarantine files (for inspection)
```

A backup takes about the time of copying the database's files twice (the copy and its read-back) and as much
space as they take. A large database keeps its writers waiting that long: plan backups of large databases when
writes are rare, or give the writers a `lockTimeout` that covers them.

## 2. Backing up several databases

Each `backup()` is one database at one moment. Databases backed up one after the other belong to different
moments; that is usually what is wanted. When two databases must agree (orders and their items), back them up
while their writers are stopped.

```php
$db->insert("orders", ["user" => 0, "total" => 49.9]);
$stamp = date('Ymd-His');
foreach ($db->getDBs(false) as $name) {
    $db->backup($name, __DIR__ . "/backups/all-$stamp/$name");
}
```

Keep backups on another disk or another machine, and keep several generations of them. A backup directory is
plain files: `rsync`, `tar` or object storage can carry it. Its checksums detect damage, not a deliberate
change: protect it as you protect `dbDir`.

## 3. Checking a backup

`restore()` checks a backup completely before it changes anything (its manifest, every SHA-256, the identity
records). A restore into a scratch store is the full test of a backup: it is also what a restore drill should
do from time to time.

```php
$scratch = new noneDB(['secretKey' => getenv('NONEDB_SECRET'), 'dbDir' => __DIR__ . '/restore-check/']);
$check = $scratch->restore($dir, "users");  // "absent": created there with the backup's identity
$check['records'];                          // 2, as the manifest says
$scratch->checkIntegrity("users")['ok'];    // true
```

## 4. Restoring

```php
$db->insert("users", ["name" => "written after the backup"]);
$result = $db->restore($dir, "users");
$result['target'];    // "present"
$result['nextKey'];   // 3: keys handed out after the backup are not handed out again
$db->count("users");  // 2
```

- **One commit.** The backup is copied into a new generation, verified (checksums, counts, the shard list, the
  next key), and published by one replacement of `CURRENT` (STORAGE_FORMAT.md §5.2). After a crash at any step
  the database opens either as it was or as restored, never a mix. The old generation is removed only after the
  new one is published.
- **Keys never go back.** Records written after the backup are gone, but their keys stay used: new records get
  keys above every key the database handed out (a top tombstone keeps the highest one even if the counters are
  lost).
- **Buffered records** of the backup come back and are flushed by the next read.
- **Writers wait.** A restore is a write: it waits for the database's lock like any write.
- **Recorded.** The database's recovery log gets a `restore` event (generation, the backup's `databaseId`,
  `takenAt`, `records`).

### Into a database that doesn't exist

```php
$db->dropDB("users");
$db->restore($dir, "users");   // target "absent": the database is created with the backup's identity
```

A backup is restored into the name it was taken from without `force`. Under another name it is a copy: with
`['force' => true]` it gets a new identity (a new `databaseId`).

### A backup of another database

The backup's `databaseId` must be the database's: restoring the wrong backup over a live database is refused
(`RESTORE_REFUSED`, nothing changed). To load another database's backup on purpose (a copy of production into
staging), pass `['force' => true]`: the database keeps its own identity and the event is logged as
`foreign-restore`.

A sharded backup can't be loaded into a database of another shard size (its shard files wouldn't fit the key
ranges): `RESTORE_REFUSED`, with `force` too. Restore it into a database of its own.

### Over a damaged database

A database that fails with `FORMAT_DAMAGED` (its `CURRENT` or `FORMAT` can't be read and can't be repaired with
proof) can still be restored: `restore()` is the way out. The result's `target` is `"damaged"`. The damaged
generations are **kept** in the database directory as unreferenced generations: nothing proves which of them was
the committed one, so noneDB removes none. `checkIntegrity()` reports them; once you have looked at them (or
copied them away), remove them as OPERATIONS.md §5 describes. The next key is above every key found in their
data.

## 5. When the store record is lost

Every backup carries `store.json`, the store record of that moment (the keys registered for the store). When
both copies of the store record are damaged, `repairStore()` restores it from there:

<!-- doctest: skip -->
```php
noneDB::repairStore('/var/lib/app/nonedb', [$key], ['manifest' => '/backups/users-20261029-142233/store.json']);
// ["success" => true, "mode" => "manifest", "complete" => true, ...]
```

Keys registered after that backup and without a database of their own are outside it: keep the newest
`storeManifest` a key operation returns (OPERATIONS.md §4).

## 6. Errors

| Error | Meaning | Changed |
|---|---|---|
| `InvalidArgumentException` (backup) | The directory is inside `dbDir`, or exists and is not empty | nothing |
| `InvalidArgumentException` (restore) | An option other than `force`, or a `force` that is not `true` or `false` | nothing |
| `RuntimeException` (backup) | The database doesn't exist; a copy failed or didn't read back (the backup is removed) | nothing |
| `MAINTENANCE_IN_PROGRESS`, `KEY_NOT_REGISTERED`, `LOCK_FAILED` | As for any operation | nothing |
| `RESTORE_REFUSED` | An incomplete or damaged backup; another database's without `force`; another shard size; a restored generation that doesn't verify | nothing (a generation that didn't verify is removed) |
| `DURABILITY_FAILED` | A sync failed before the commit point (restored data files are synced in performance mode too) | nothing: rolled back |
| `OUTCOME_UNKNOWN` | A failure after the commit point | read the state again: the next access recovers |
