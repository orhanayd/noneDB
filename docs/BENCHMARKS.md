# noneDB Performance Benchmarks

Performance measurements of noneDB 3.2.0, compared with 3.1.0 and SleekDB.

**Version:** 3.2.0
**Test environment:** PHP 8.2.0 (CLI), macOS 15.8, Apple M2, 16 GB RAM
**Measured:** 2026-10-24. The 3.1.0 numbers come from the same scripts, run on the same machine in the same session.

All 3.2.0 numbers include the database lock every operation takes (shared for reads, exclusive for
writes) and crash-safe writes. 3.1.0 took no lock for reads and overwrote index files in place.

---

## Table of Contents

1. [Test Data Structure](#test-data-structure)
2. [What Changed in 3.2](#what-changed-in-32)
3. [Single Operations (5,000 records)](#single-operations-5000-records)
4. [Key Lookup](#key-lookup)
5. [3.2 vs 3.1 at Scale](#32-vs-31-at-scale)
6. [Write Operations](#write-operations)
7. [Read Operations](#read-operations)
8. [Query & Aggregation](#query--aggregation)
9. [Method Chaining](#method-chaining)
10. [Comparison Operators](#comparison-operators)
11. [Spatial Query Performance](#spatial-query-performance)
12. [Storage & Memory](#storage--memory)
13. [SleekDB Comparison](#sleekdb-comparison)
14. [Running Benchmarks](#running-benchmarks)

---

## Test Data Structure

The performance and SleekDB benchmarks use 7 fields per record:

```php
[
    "name" => "User123",
    "email" => "user123@test.com",
    "age" => 25,
    "salary" => 8500,
    "city" => "Istanbul",
    "department" => "IT",
    "active" => true
]
```

---

## What Changed in 3.2

| Change | Effect |
|--------|--------|
| **Database lock per operation** | Correct results with several processes; costs ~0.01-0.02 ms per call |
| **Lock file state record** | Generation counter, buffer flag, index journal and the one-time old-version check are read with the lock: no per-operation `stat`/`glob` |
| **Kept lock handles** | No `fopen`/`fclose` per operation (pid- and inode-checked) |
| **Atomic index writes** (temp file + rename) | A crash never leaves a half-written index; a single-record write costs ~0.15 ms more |
| **Batch inserts/updates** | Records encoded and appended in one write, each index written once: insert 10x, filtered update 4x faster |
| **Per-shard key lookup** | A cold lookup loads only the target shard's index: 5 ms instead of 156 ms at 500K |
| **Compiled query filters** | `where()`/`orWhere()` compiled once per query; `array_multisort` on precomputed sort keys |
| **Adaptive `query()->nearest()`** | With other filters, searches a growing radius instead of sorting the whole database |

### Earlier optimizations

| Optimization | Since |
|--------------|-------|
| JSONL storage with byte-offset index (O(1) key lookup) | 3.0 |
| Static cache shared across instances | 3.0 |
| Batch file read (64 KB buffered) | 3.0 |
| O(1) count from index metadata | 3.0 |
| R-tree spatial index: parent pointer map, linear split, dirty-flag batch writes, node size 32 | 3.1 |
| Distance and centroid memoization | 3.1 |

---

## Single Operations (5,000 records)

Milliseconds per call, best of 5 runs (`tests/operation_benchmark.php`).

| Operation | 3.1.0 | 3.2.0 |
|-----------|-------|-------|
| insert (5,000 records, one call) | 175.4 | **17.2** |
| insert (one record) | **0.169** | 0.321 |
| find (all records) | 16.2 | **10.4** |
| find (by key) | **0.026** | 0.047 |
| find (filter) | 17.4 | **12.0** |
| count | **0.002** | 0.018 |
| query (where range + sort + limit) | 25.5 | **19.9** |
| update (by key) | **0.830** | 0.977 |
| update (filter, 1,250 records) | 102.6 | **25.0** |
| delete (by key) | **0.933** | 1.1 |
| find (filter on indexed field) | 4.0 | **3.2** |
| insert (one record, indexed field) | **1.2** | 1.5 |
| new instance + find (by key) | **0.029** | 0.056 |

Bulk work is much faster. The smallest operations pay a fixed cost: the lock (all operations) and the
atomic index replacement (single writes). Those are what make 3.2 safe with concurrent processes and
crashes.

---

## Key Lookup

`find($db, ['key' => $k])`. Cold = nothing cached (first lookup of a new process); warm = average of
200 lookups spread over all shards, after each shard's index was loaded once.

| Records | Cold 3.1.0 | Cold 3.2.0 | Warm 3.1.0 | Warm 3.2.0 |
|---------|------------|------------|------------|------------|
| 100 | 3 ms | **0.21 ms** | **0.030 ms** | 0.045 ms |
| 1,000 | 3 ms | **0.45 ms** | **0.030 ms** | 0.046 ms |
| 10,000 | 9 ms | **3 ms** | **0.030 ms** | 0.054 ms |
| 50,000 | 22 ms | **5 ms** | **0.042 ms** | 0.057 ms |
| 100,000 | 37 ms | **4 ms** | 0.060 ms | **0.057 ms** |
| 500,000 | 156 ms | **5 ms** | 0.206 ms | **0.066 ms** |

A cold lookup in 3.2 reads one shard's index, so it stays ~5 ms at any size.

---

## 3.2 vs 3.1 at Scale

Same script (`tests/performance_benchmark.php`), same machine.

| Operation | 100K 3.1.0 | 100K 3.2.0 | 500K 3.1.0 | 500K 3.2.0 |
|-----------|------------|------------|------------|------------|
| insert() | 3.6 s | **1.3 s** | 18.8 s | **7.1 s** |
| update() (city filter) | 2.1 s | **455 ms** | 19 s | **2.4 s** |
| delete() (department filter) | 2.4 s | **831 ms** | 20.8 s | **4.4 s** |
| find(all) | 598 ms | **376 ms** | 2.7 s | **1.8 s** |
| find(key), first after insert | 490 ms | **<1 ms** | 2.5 s | **<1 ms** |
| find(filter) | 514 ms | **336 ms** | 2.7 s | **1.8 s** |
| distinct() | 586 ms | **361 ms** | 3 s | **2.1 s** |
| sort() + limit | 1.3 s | **452 ms** | 7.1 s | **2.7 s** |
| search() | **736 ms** | 849 ms | **4.2 s** | 4.6 s |
| select() | 864 ms | **596 ms** | 4.6 s | **3.6 s** |
| complex chain | 676 ms | **484 ms** | 3.7 s | **2.7 s** |

`search()` is slightly slower because it now matches Unicode text case-insensitively (`İzmir` = `izmir`).

---

## Write Operations

| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| insert() | 6 ms | 7 ms | 121 ms | 596 ms | 1.3 s | 7.1 s |
| update() | <1 ms | 4 ms | 40 ms | 216 ms | 455 ms | 2.4 s |
| delete() | 2 ms | 8 ms | 78 ms | 426 ms | 831 ms | 4.4 s |

> `update()` changes the ~20% of records in one city, `delete()` the ~20% in one department.

---

## Read Operations

| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| find(all) | <1 ms | 3 ms | 28 ms | 169 ms | 376 ms | 1.8 s |
| find(key) | <1 ms | <1 ms | <1 ms | <1 ms | <1 ms | <1 ms |
| find(filter) | <1 ms | 3 ms | 32 ms | 163 ms | 336 ms | 1.8 s |

---

## Query & Aggregation

| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| count() | **<1 ms** | **<1 ms** | **<1 ms** | **<1 ms** | **<1 ms** | **<1 ms** |
| distinct() | <1 ms | 3 ms | 34 ms | 170 ms | 361 ms | 2.1 s |
| sum() | <1 ms | 3 ms | 31 ms | 167 ms | 354 ms | 2.1 s |
| like() | <1 ms | 4 ms | 38 ms | 209 ms | 432 ms | 2.5 s |
| between() | <1 ms | 4 ms | 43 ms | 229 ms | 502 ms | 2.7 s |
| sort() | <1 ms | 4 ms | 38 ms | 211 ms | 452 ms | 2.7 s |
| first() | <1 ms | 3 ms | 31 ms | 168 ms | 364 ms | 1.8 s |
| exists() | <1 ms | 3 ms | 32 ms | 173 ms | 370 ms | 1.9 s |

---

## Method Chaining

| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| whereIn() | <1 ms | 3 ms | 35 ms | 227 ms | 419 ms | 2.5 s |
| orWhere() | <1 ms | 4 ms | 39 ms | 220 ms | 460 ms | 2.5 s |
| search() | <1 ms | 7 ms | 76 ms | 398 ms | 849 ms | 4.6 s |
| groupBy() | <1 ms | 3 ms | 35 ms | 209 ms | 434 ms | 2.4 s |
| select() | <1 ms | 4 ms | 38 ms | 277 ms | 596 ms | 3.6 s |
| complex chain | <1 ms | 4 ms | 39 ms | 235 ms | 484 ms | 2.7 s |

---

## Comparison Operators

`tests/operator_benchmark.php`, best of 3 runs (records with 10 fields, including a GeoJSON point).

| Operator | 100 | 500 | 1K | 5K |
|----------|-----|-----|-----|-----|
| `$gt` | 0.68 ms | 2.9 ms | 5.8 ms | 29 ms |
| `$gte + $lte` (range) | 0.72 ms | 3.5 ms | 7.1 ms | 36 ms |
| `$in` (2 values) | 0.6 ms | 2.8 ms | 5.6 ms | 30 ms |
| `$nin` (2 values) | 0.6 ms | 2.8 ms | 5.7 ms | 29 ms |
| `$ne` | 0.59 ms | 2.8 ms | 5.5 ms | 30 ms |
| `$like` | 0.64 ms | 3 ms | 6 ms | 32 ms |
| `$exists` | 0.61 ms | 2.8 ms | 5.7 ms | 30 ms |
| Complex (4 operators) | 0.8 ms | 3.7 ms | 7.5 ms | 40 ms |

---

## Spatial Query Performance

| Operation | 100 | 500 | 1K | 5K |
|-----------|-----|-----|-----|-----|
| createSpatialIndex | 2.6 ms | 23 ms | 61 ms | 434 ms |
| withinDistance (10 km) | 1.1 ms | 5.2 ms | 10.5 ms | 64 ms |
| withinBBox | 0.31 ms | 1 ms | 2.1 ms | 10 ms |
| nearest(10) | 0.33 ms | 1.4 ms | 2.6 ms | 3.1 ms |

> The 10 km circle covers most of the test area, so `withinDistance` returns most records; its cost is
> dominated by the matches, not by the R-tree.

### Spatial + Operator Combination

| Query Type | 100 | 500 | 1K | 5K |
|------------|-----|-----|-----|-----|
| withinDistance + where (simple) | 1.1 ms | 5.5 ms | 11 ms | 64 ms |
| + where (`$gte`) | 1.1 ms | 5.7 ms | 11 ms | 66 ms |
| + where (`$in`) | 1.1 ms | 5.6 ms | 12.5 ms | 74 ms |
| + range (`$gte` + `$lte`) | 1.2 ms | 6 ms | 12.4 ms | 70 ms |
| + complex (4 operators) | 1.2 ms | 5.8 ms | 11.5 ms | 66 ms |
| + sort + limit | 1.6 ms | 8 ms | 15 ms | 88 ms |
| nearest(50) + operators + limit | 1.6 ms | 5.5 ms | 3.9 ms | 7.8 ms |

> Since 3.2 `nearest(k)` returns the k nearest records **that match the other filters** (3.1 cut to k
> first and then filtered, which could return fewer or wrong records). With a spatial index this uses a
> growing search radius, so it stays fast as the database grows.

---

## Storage & Memory

| Records | Data Size | Peak Memory |
|---------|-----------|-------------|
| 100 | 12 KB | 4 MB |
| 1,000 | 127 KB | 6 MB |
| 10,000 | 1.3 MB | 34 MB |
| 50,000 | 6.5 MB | 124 MB |
| 100,000 | 13 MB | 238 MB |
| 500,000 | 66 MB | ~1.2 GB |

> Peak memory of the benchmark process, including the generated test data and full result sets
> (`find(all)` of 500K records); a single key lookup needs only one shard's index.

---

## SleekDB Comparison

`tests/sleekdb_comparison.php` with SleekDB 2.15.

### Why Choose noneDB?

| Strength | Performance |
|----------|-------------|
| **Bulk Insert** | **12-23x faster** than SleekDB |
| **Find All** | **10-75x faster** |
| **Filter Queries** | **11-53x faster** |
| **Update Operations** | **9-48x faster** |
| **Delete Operations** | **3.5-21x faster** |
| **Count Operations** | **7-450x faster** (O(1) index lookup) |
| **Complex Queries** | **7-18x faster** |
| **Large Datasets** | Auto-sharding, 500K+ records |
| **Concurrency** | Database-level locks across processes, crash recovery |
| **Spatial Queries** | R-tree indexing (SleekDB: none) |

### When to Consider SleekDB?

| Scenario | SleekDB Advantage |
|----------|-------------------|
| **First key lookup of a process** | ≤2 ms vs up to 15 ms at 100K (noneDB loads one shard index first; later lookups take ~0.05 ms) |
| **Very low memory** | Lower RAM usage |

> SleekDB stores each record as a separate file, so a single-record lookup is one file read while bulk
> operations have to open every file.

### Architectural Differences

| Feature | SleekDB | noneDB |
|---------|---------|--------|
| **Storage** | One JSON file per record | JSONL + byte-offset index |
| **ID Access** | Direct file read (O(1)) | Index lookup + seek |
| **Bulk Read** | Traverse all files | Single file read per shard |
| **Sharding** | None | Automatic (10K+) |
| **Cache** | Per-query | Static cross-instance, validated per write |
| **Indexing** | None | Byte-offset (.jidx), field indexes |
| **Spatial Index** | None | R-tree |

### Detailed Benchmark Results

| Operation | 100 noneDB | 100 SleekDB | 1K noneDB | 1K SleekDB | 10K noneDB | 10K SleekDB | 50K noneDB | 50K SleekDB | 100K noneDB | 100K SleekDB |
|-----------|-----|-----|-----|-----|-----|-----|-----|-----|-----|-----|
| Bulk Insert | 2 ms | 21 ms | 7 ms | 175 ms | 120 ms | 1.97 s | 714 ms | 11.94 s | 1.25 s | 24.35 s |
| Find All | <1 ms | 5 ms | 3 ms | 37 ms | 32 ms | 419 ms | 181 ms | 2.28 s | 372 ms | 27.98 s |
| Find by Key (cold) | <1 ms | <1 ms | <1 ms | <1 ms | 3 ms | <1 ms | 4 ms | <1 ms | 15 ms | 2 ms |
| Find with Filter | <1 ms | 4 ms | 3 ms | 47 ms | 32 ms | 411 ms | 174 ms | 2.41 s | 697 ms | 36.73 s |
| Count | <1 ms | <1 ms | <1 ms | <1 ms | <1 ms | 4 ms | <1 ms | 17 ms | <1 ms | 34 ms |
| Update | <1 ms | 7 ms | 5 ms | 73 ms | 41 ms | 974 ms | 229 ms | 4.89 s | 444 ms | 21.47 s |
| Delete | 2 ms | 6 ms | 9 ms | 49 ms | 81 ms | 745 ms | 434 ms | 3.65 s | 937 ms | 19.72 s |
| Complex Query | <1 ms | 5 ms | 4 ms | 39 ms | 47 ms | 464 ms | 173 ms | 2.33 s | 372 ms | 6.52 s |

### Performance Ratio (noneDB vs SleekDB)

| Operation | 100 | 1K | 10K | 50K | 100K |
|-----------|------|------|------|------|------|
| Bulk Insert | **11.6x** | **23.4x** | **16.4x** | **16.7x** | **19.5x** |
| Find All | **10x** | **10.6x** | **13.2x** | **12.6x** | **75.2x** |
| Find by Key (cold) | 0.27x | 0.09x | 0.01x | 0.01x | 0.12x |
| Find with Filter | **11.1x** | **13.4x** | **12.9x** | **13.9x** | **52.7x** |
| Count | **7.2x** | **10.7x** | **64.5x** | **283.4x** | **447.9x** |
| Update | **9.3x** | **14.9x** | **23.6x** | **21.3x** | **48.3x** |
| Delete | **3.5x** | **5.4x** | **9.2x** | **8.4x** | **21x** |
| Complex Query | **6.8x** | **11.1x** | **9.9x** | **13.5x** | **17.5x** |

> Find by Key (cold) is the first lookup after the data was written and the caches were cleared. Warm
> lookups take ~0.05 ms (see [Key Lookup](#key-lookup)).

---

## Running Benchmarks

Every script uses its own temporary directory and key; none of them touches your databases.

```bash
# Performance benchmark (default sizes 100 ... 500K; pass your own as a comma-separated list)
php tests/performance_benchmark.php
php tests/performance_benchmark.php 100,1000,10000

# Single-operation latency; optionally compare another version of noneDB.php
php tests/operation_benchmark.php
php tests/operation_benchmark.php /path/to/other/noneDB.php 5   # best of 5

# Comparison operators + spatial combinations
php tests/operator_benchmark.php

# Spatial benchmark
php tests/spatial_benchmark.php

# SleekDB comparison (requires the dev dependency rakibtg/sleekdb)
php tests/sleekdb_comparison.php
php tests/sleekdb_comparison.php 100,1000
```
