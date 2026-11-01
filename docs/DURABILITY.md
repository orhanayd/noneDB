# noneDB 4 Durability Contract

**Engine:** noneDB 4.0.0 · **Status:** normative

This document defines:
- what a successful, failed or uncertain write means;
- which syncs each mode performs, and in which order;
- what `getDurability()` reports.

File formats and state machines are in [STORAGE_FORMAT.md](STORAGE_FORMAT.md).

---

## 1. Modes

| Mode | Setting | Guarantee |
|---|---|---|
| Performance (default) | `'durability' => 'performance'` | **Process crash:** a write that returned success stays applied after the process dies (SIGKILL, fatal error), as long as the operating system keeps running. Recovery is deterministic: complete lines roll forward, unterminated lines are never records, keys are never reused, and indexes are rebuilt from the data when they don't fit it. **Power loss:** no guarantee. Recent writes may be lost; damage is detected and recovered on a best-effort basis, and `checkIntegrity()` reports what remains. |
| Durable | `'durability' => 'durable'` | Everything in performance mode, **plus power loss:** a write that returned success is on stable storage, provided the file system and device honour `fsync`. Every sync the operation needs (§3) is performed. If one fails, the operation does not report success (§2); a cleanup sync after the commit point is the exception (§6). |

Durable mode is never silently downgraded:

| Platform | Durable mode |
|---|---|
| Linux, local file system (ext4, xfs, btrfs) | Supported. CI tests the real sync paths. |
| macOS | **Refused** with `UNSUPPORTED_DURABILITY` at construction. PHP's `fsync()` does not flush the drive cache, and PHP cannot call `F_FULLFSYNC`. |
| Windows | **Refused** with `UNSUPPORTED_DURABILITY`. A directory cannot be synced, so a new file or a rename is not durable. |
| Network file systems (NFS, SMB), stream wrappers | Not supported in either mode: `flock` and atomic rename are not reliable there. |

A wrong `durability` value is an `InvalidArgumentException`, and so is a misspelled setting name
(CONFIGURATION.md "Settings"): a typo never means performance mode.

The key maintenance methods (`migrateKey()`, `registerKey()`, `repairStore()`) take the same choice as an
option, `['durability' => 'durable']`, with the same validation (default `'performance'`).

## 2. Outcomes

Every write operation has one **commit point** (§3). It ends in exactly one of three outcomes:

| Outcome | When | Caller sees |
|---|---|---|
| **committed** | The commit point was reached and every sync the mode requires succeeded, cleanup after the commit point aside (§6). | Success. |
| **rolled back** | The failure happened **before** the commit point and the undo completed. In durable mode, "completed" includes the sync of the undo itself (the `ftruncate` and its `fsync`). In performance mode, the undo is guaranteed at the process and operating-system level only. | `DURABILITY_FAILED` when a sync failed; otherwise the original error. Nothing of the operation is applied. |
| **unknown** | The failure happened **after** the commit point (for example, the directory sync after a rename failed), or the undo or its sync could not be completed. | `OUTCOME_UNKNOWN`. The next access recovers the database and decides. The caller must read the state again before retrying. |

- An operation never returns success in the rolled-back or unknown cases.
- A rollback is never claimed when its sync did not succeed.
- `getDurability()['lastOperation']['outcome']` is `committed`, `rolled_back` or `unknown`.
- An *operation* is one public write call (`insert`, `update`, `delete`, `createDB`, `dropDB`, `flush`,
  `compact`, `convertToSharded`, `restore`, the index methods, the query builder's `update`/`delete`/`removeFields`, and
  `recovery` for recovery work a read had to do). A call that wrote nothing (an update that matched no
  record) is not one and leaves the report as it was. Work deferred until an operation committed
  (auto-compaction, a buffer flush, a migration to shards) is part of no operation's outcome: its failure
  is logged (`deferred-failed`) and retried.
- An unknown outcome also sets `J` in the state record: the next session recovers the whole database.

## 3. Commit points and sync order

"Sync file" = `fsync` of the file. "Sync dir" = `fsync` of its directory. Performance mode performs none of them. Durable mode performs all of them, in the order listed; the operation's commit point is marked **⇒**.

| Operation | Steps (durable mode) |
|---|---|
| Append to an existing data or buffer file | Write the lines and check the byte count and `fflush` → sync file **⇒ committed** |
| Append that creates the file (new shard, new buffer) | Create and write → sync file → sync dir **⇒ committed** |
| Set a state-record flag (`b`, `c`, `d`, `m`, `g`, `j`, `J`) or a new epoch | Write the 128-byte record → sync file. This always happens **before** the data change it protects. Clearing a flag needs no sync. |
| Atomic replacement (`FORMAT`, `CURRENT`, `metadata`, `shards.json`, `indexes.json`, STORE copies, journal) | Write the temp file → sync file → rename **⇒ committed in the process** → sync dir **⇒ durable**. A failing dir sync after the rename is *unknown*. |
| New directory | `mkdir` → sync its parent directory, for every directory created (a generation, `segments/`, `buffer/`, `recovery/`, dbDir itself) |
| Sharded write session | Set `j` (synced) → an insert reserves keys in `shards.json` (atomic replacement) → append (above) **⇒** → a delete writes `shards.json` → clear `j`. An update writes no `shards.json`. |
| Segment compaction (STORAGE_FORMAT §6.2) | New epoch (synced) → remove the checkpoint → temp data file, sync file → rename **⇒** → sync dir → fresh checkpoint |
| Generation switch (§5.2) | PREPARE: every file synced, every new directory synced into its parent → INTENT record (synced) → CURRENT replacement **⇒** → CLEANUP → sync `generations/` → DONE. `g` is cleared only after that sync; if it fails, `g` stays and the next session finishes the cleanup. A failure during PREPARE removes the new generation. |
| Database creation (§8.1) | `c` record (synced) → sync dbDir (the directory's entry) → generation 1, `CURRENT` (each file and new directory synced) → `FORMAT` (atomic replacement) **⇒** when its dir sync succeeded → clear `c` |
| Database drop (§8.2) | `d` and a new epoch (one record, synced) → remove `FORMAT` → sync dir **⇒** → remove the rest → sync dir → clear `d`. `d` stays when that last sync fails (the next session finishes the drop). If the `d` record can't be synced, `d` is withdrawn by a synced record (*rolled back*); if that fails too, *unknown*. |
| Quarantine before a cut (§7.2) | Copy → sync file → rename → sync dir → read back and verify, all **before** the source is cut. A failed sync: nothing is cut, the write fails (*rolled back*). |
| Backup (§10.2) | The database is not written. Every copied file synced, every new directory synced into its parent → `manifest.json` (atomic replacement) **⇒** |
| Restore (§10.3) | PREPARE: every file of X with the sync of its kind (data and buffer files synced in both modes, as replaced data files) → a present database: the generation-switch row; an absent one: the creation row; a damaged one: the state record with a new epoch (synced) → `CURRENT` replacement **⇒** → `FORMAT` replacement when it was invalid |
| Maintenance (§9) | `m` + epoch records (synced) → work (a rotation's renames, then sync dbDir) → journal `COMMITTED` (atomic replacement) **⇒** → STORE copies → clear `m` (synced) → remove the journal → sync its directory. A failure before the commit keeps the journal open: the store stays closed until the maintenance is run again (it continues) or aborted. Only a rotation whose verification fails undoes its renames and closes the journal. A failure after the commit: `MAINTENANCE_IN_PROGRESS` (run it again); a failed dir sync of the journal's commit or removal, or of a STORE copy: *unknown*. |

Checkpoints and secondary indexes are **derived files**:
- they are written by atomic replacement but never synced, in either mode;
- they are validated against the data when loaded (`s`, `h`, `e`), so losing or corrupting one only costs a rebuild.

**Performance mode** syncs nothing for its own guarantee, with three exceptions that cost one sync per rare
operation and keep a power loss from taking a whole data file or the list of shards:
- a data file that is **replaced** (compaction, the shard files of a generation switch) is synced before
  its rename; if that sync fails the replacement is not made (`DURABILITY_FAILED`, *rolled back*); its
  directory is synced after the rename, and a failure there is not an error in performance mode;
- a **quarantine copy** is synced; a failed sync is reported in the recovery log (`powerLossDurable`), the
  cut goes ahead;
- `shards.json` is synced, with its directory, when compaction removes empty shards and when it is rebuilt.
`required` stays empty in performance mode: these syncs make no claim.

## 4. Undo on failure

- **Append failure** (an `fwrite` that makes no progress, failed `fflush`, failed sync file, failed dir sync of a created file): the appended bytes are cut back to the recorded size (`undoAppend`). A file the append created is cut to zero bytes, never removed, so the cut needs no directory sync.
  - In durable mode, the truncation is synced as well.
  - If the cut or its sync fails, the outcome is *unknown*: `OUTCOME_UNKNOWN`, and `J` is set so the next session recovers.
- **Rollback scope:** an exception that leaves the outermost exclusive `withLock()` cuts every file the scope appended to back to its recorded size.
  - The same rules apply: synced cuts in durable mode, *unknown* when a cut fails.
- **Replacement failure before the rename:** the temp file is removed and the target is unchanged. The outcome is *rolled back*.
- **Replacement failure after the rename:** the outcome is *unknown*. In-process state treats the new file as current; the next process decides by reading the disk.

## 5. Process crash recovery (both modes)

Recovery runs at the next lock acquisition. Data is not returned before it completes.

| Condition | Handling |
|---|---|
| Unterminated tail | Ignored by readers; quarantined and cut by the next writer. |
| Complete lines past a checkpoint | Replayed (roll forward). |
| `j` / `J` | Shard counters recounted from the data. |
| `g` | Generation recovery (STORAGE_FORMAT §5.2). |
| `c` / `d` | Creation is undone; a drop is finished (§8.3). |
| `m` or an open maintenance journal | Closed until the maintenance is run again. |

**What is not atomic:** a multi-record API call (for example an insert of 1000 records) is **not** a transaction under SIGKILL. A prefix of it may be applied.
- In the sharded layout keys are reserved before data, so no key is reused. In the single layout the key of a line
  that never became a record (torn, or rolled back) goes to the next insert; no reader ever saw it.
- Counters and indexes are recomputed from what was applied.
- An exception (as opposed to a kill) rolls the whole scope back (§4).

## 6. `getDurability()`

<!-- doctest: skip -->
```php
[
    'mode'          => 'performance' | 'durable',            // this instance
    'platform'      => ['os' => 'Linux', 'durableSupported' => true, 'notes' => [...]],
    'lastOperation' => null | ['op' => 'insert', 'db' => 'users',
                        'outcome' => 'committed' | 'rolled_back' | 'unknown',
                        'required' => ['file', 'dir'], 'completed' => ['file', 'dir'], 'reason' => null],
    'lastSyncError' => null | ['path' => ..., 'op' => 'file' | 'dir', 'time' => ...],
    'syncs'         => ['file' => int, 'dir' => int, 'failed' => int],
]
```

- `lastOperation` is the last write operation of the **process** (§2), whichever instance ran it; `null`
  before the first one.
- `required` lists the kinds of sync the last operation needed in this mode. It is empty in performance mode.
- `completed` lists the kinds whose every sync up to the commit succeeded. A cleanup sync after the commit point that
  fails (`generations/` after a switch, the last one of a drop) changes neither `completed` nor the outcome: the call
  returns success, `g` or `d` stays for the next session, and `lastSyncError` shows it.
- `outcome` follows §2; `reason` is the exception's message when the operation failed.
- `platform.notes` says why durable mode is not supported where it isn't (empty on Linux).
- `syncs` and `lastSyncError` count every sync of the process, the ones performance mode does too.
- Paths are relative to `dbDir`. No key material appears.

## 7. Tests (summary)

- **Every row of §3:** the failure of each sync is injected through the sync hook. The test checks the outcome, the exception code and the record set after the database is reopened, compared with the independent model (`tests/Crash/DurabilityContractTest.php`).
- **The crash and fault matrix** (`tests/Crash/FaultMatrixTest.php`): every write operation killed at every step and hit by every kind of I/O error; the reopened record set must be one of the states the operation may leave.
- **Every state-machine step in STORAGE_FORMAT:** SIGKILL before and after the step. The reopened database must be one of the two consistent states.
- **Durable mode on Linux:** tested in CI and in docker. On macOS and Windows, the `UNSUPPORTED_DURABILITY` refusal is tested.
- **Power loss:** simulated by placing the artefacts it can leave (zero-length files, NUL regions, missing renames). Each is checked to be recovered or reported.
