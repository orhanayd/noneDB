# noneDB Performance Benchmarks

Comprehensive performance benchmarks and database comparisons.

**Version:** 3.1.0
**Test Environment:** PHP 8.2, macOS (Apple Silicon M-series)

---

## Table of Contents

1. [Test Data Structure](#test-data-structure)
2. [v3.0+ Optimizations](#v30-optimizations)
3. [O(1) Key Lookup Performance](#o1-key-lookup-performance)
4. [Write Operations](#write-operations)
5. [Read Operations](#read-operations)
6. [Query & Aggregation](#query--aggregation)
7. [Method Chaining](#method-chaining)
8. [Spatial Query Performance](#spatial-query-performance)
9. [Storage & Memory](#storage--memory)
10. [SleekDB Comparison](#sleekdb-comparison)

---

## Test Data Structure

All benchmarks use 7 fields per record:

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

## v3.0+ Optimizations

| Optimization | Improvement |
|--------------|-------------|
| **Static Cache Sharing** | 80%+ for multi-instance |
| **Batch File Read** | 40-50% for bulk reads |
| **Batch Update/Delete** | **25-30x faster** for bulk operations |
| **Single-Pass Filtering** | 30% for complex queries |
| **O(1) Sharded Key Lookup** | True O(1) for all database sizes |
| **O(1) Count** | **100-330x faster** (index metadata lookup) |
| **Hash Cache Persistence** | Faster cold startup |
| **atomicReadFast()** | Optimized index reads |

### v3.1.0 Spatial Optimizations

| Optimization | Improvement |
|--------------|-------------|
| **Parent Pointer Map** | O(1) parent lookup (was O(n)) |
| **Linear Split Algorithm** | O(n) seed selection (was O(n²)) |
| **Dirty Flag Pattern** | Single disk write per batch (was n writes) |
| **Distance Memoization** | Cached Haversine calculations |
| **Centroid Caching** | Cached geometry centroids |
| **Node Size 32** | Fewer tree levels and splits |
| **Adaptive nearest()** | Exponential radius expansion |

---

## O(1) Key Lookup Performance

Key lookups are **constant time** regardless of database size after cache warm-up.

| Records | Cold (first access) | Warm (cached) | Notes |
|---------|---------------------|---------------|-------|
| 100 | 3 ms | **0.03 ms** | Non-sharded |
| 1K | 3 ms | **0.03 ms** | Non-sharded |
| 10K | 49 ms | **0.03 ms** | Sharded (1 shard) |
| 50K | 243 ms | **0.05 ms** | Sharded (5 shards) |
| 100K | 497 ms | **0.05 ms** | Sharded (10 shards) |
| 500K | 2.5 s | **0.16 ms** | Sharded (50 shards) |

> **Key insight:** Cold time includes loading shard index. After cache warm-up, all lookups are near-instant.

---

## Write Operations

| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| insert() | 7 ms | 25 ms | 289 ms | 1.5 s | 3.1 s | 16.5 s |
| update() | 1 ms | 11 ms | 120 ms | 660 ms | 1.5 s | 11.3 s |
| delete() | 2 ms | 13 ms | 144 ms | 773 ms | 1.7 s | 12.5 s |

> Update/delete use batch operations for efficient bulk modifications (single index write per shard)

---

## Read Operations

| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| find(all) | 3 ms | 23 ms | 48 ms | 268 ms | 602 ms | 2.7 s |
| find(key) | <1 ms | <1 ms | 49 ms | 243 ms | 497 ms | 2.5 s |
| find(filter) | <1 ms | 4 ms | 50 ms | 252 ms | 515 ms | 2.6 s |

> **find(key)** first call includes index loading. Subsequent calls: ~0.05ms (see O(1) table above)

---

## Query & Aggregation

| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| count() | **<1 ms** | **<1 ms** | **<1 ms** | **<1 ms** | **<1 ms** | **<1 ms** |
| distinct() | <1 ms | 4 ms | 49 ms | 270 ms | 590 ms | 2.9 s |
| sum() | <1 ms | 4 ms | 49 ms | 261 ms | 588 ms | 3 s |
| like() | <1 ms | 5 ms | 57 ms | 311 ms | 670 ms | 3.4 s |
| between() | <1 ms | 4 ms | 53 ms | 288 ms | 628 ms | 3.2 s |
| sort() | <1 ms | 8 ms | 105 ms | 565 ms | 1.3 s | 7.1 s |
| first() | <1 ms | 4 ms | 50 ms | 285 ms | 589 ms | 2.9 s |
| exists() | <1 ms | 4 ms | 49 ms | 272 ms | 588 ms | 3 s |

> **count()** uses O(1) index metadata lookup - no record scanning required!

---

## Method Chaining

| Operation | 100 | 1K | 10K | 50K | 100K | 500K |
|-----------|-----|-----|------|------|-------|-------|
| whereIn() | <1 ms | 4 ms | 53 ms | 302 ms | 657 ms | 3.6 s |
| orWhere() | <1 ms | 4 ms | 55 ms | 316 ms | 673 ms | 3.5 s |
| search() | <1 ms | 5 ms | 61 ms | 350 ms | 762 ms | 4.2 s |
| groupBy() | <1 ms | 4 ms | 52 ms | 307 ms | 657 ms | 3.5 s |
| select() | <1 ms | 5 ms | 57 ms | 400 ms | 854 ms | 4.5 s |
| complex chain | <1 ms | 5 ms | 60 ms | 322 ms | 684 ms | 3.6 s |

> **Complex chain:** `where() + whereIn() + between() + select() + sort() + limit()`

---

## Comparison Operators (v3.1.0)

MongoDB-style operators add minimal overhead:

| Operator | 100 | 500 | 1K | 5K |
|----------|-----|-----|-----|-----|
| `$gt` | 0.7 ms | 3 ms | 6 ms | 33 ms |
| `$gte + $lte` (range) | 0.7 ms | 3.4 ms | 7 ms | 38 ms |
| `$in` (2 values) | 0.7 ms | 3.3 ms | 6.5 ms | 36 ms |
| `$nin` (2 values) | 0.7 ms | 3.5 ms | 7 ms | 37 ms |
| `$ne` | 0.7 ms | 3 ms | 6 ms | 36 ms |
| `$like` | 0.7 ms | 3.4 ms | 7 ms | 39 ms |
| `$exists` | 0.6 ms | 3.2 ms | 6 ms | 37 ms |
| Complex (4 operators) | 0.7 ms | 3.5 ms | 7.5 ms | 43 ms |

> Operators add <1ms overhead per operation. Linear scaling with record count.

---

## Spatial Query Performance

Tested with R-tree spatial index (v3.1.0):

| Operation | 100 | 500 | 1K | 5K |
|-----------|-----|-----|-----|-----|
| createSpatialIndex | 2.4 ms | 34 ms | 81 ms | 423 ms |
| withinDistance (10km) | 3.1 ms | 16 ms | 32 ms | 166 ms |
| withinBBox | 0.7 ms | 4.5 ms | 7 ms | 38 ms |
| nearest(10) | 1.9 ms | 1.3 ms | 2 ms | 2.4 ms |

> Spatial queries use R-tree indexing for O(log n + k) performance where k = matching records.

### Spatial + Operator Combination

| Query Type | 100 | 500 | 1K | 5K |
|------------|-----|-----|-----|-----|
| withinDistance only | 3 ms | 16 ms | 32 ms | 166 ms |
| + where (simple) | 3 ms | 17 ms | 34 ms | 174 ms |
| + where (`$gte`) | 3 ms | 17 ms | 33 ms | 175 ms |
| + where (`$in`) | 3 ms | 16.5 ms | 33 ms | 174 ms |
| + range (`$gte` + `$lte`) | 3 ms | 17 ms | 36 ms | 212 ms |
| + complex (4 operators) | 3 ms | 16.5 ms | 34.5 ms | 179 ms |
| + sort + limit | 3 ms | 17 ms | 35 ms | 183 ms |
| nearest + operators + limit | 8 ms | 4.5 ms | 7 ms | 12 ms |

> Spatial + operator combinations add minimal overhead. R-tree filtering happens first, then operators applied to candidates.

---

## Storage & Memory

| Records | File Size | Peak Memory |
|---------|-----------|-------------|
| 100 | 10 KB | 2 MB |
| 1,000 | 98 KB | 4 MB |
| 10,000 | 1 MB | 8 MB |
| 50,000 | 5 MB | 34 MB |
| 100,000 | 10 MB | 134 MB |
| 500,000 | 50 MB | ~600 MB |

---

## SleekDB Comparison

### Why Choose noneDB?

noneDB v3.0+ excels in **bulk operations** and **large datasets**:

| Strength | Performance |
|----------|-------------|
| **Bulk Insert** | **8-10x faster** than SleekDB |
| **Find All** | **8-66x faster** at scale |
| **Filter Queries** | **20-80x faster** at scale |
| **Update Operations** | **15-40x faster** on large datasets |
| **Delete Operations** | **5-23x faster** on large datasets |
| **Count Operations** | **90-330x faster** (O(1) index lookup) |
| **Complex Queries** | **22-70x faster** at scale |
| **Large Datasets** | Handles 500K+ records with auto-sharding |
| **Thread Safety** | Atomic file locking for concurrent access |
| **Static Cache** | Cross-instance cache sharing |
| **Spatial Queries** | R-tree indexing (SleekDB: none) |

**Best for:** Bulk operations, analytics, batch processing, filter-heavy workloads, count operations, geospatial queries

### When to Consider SleekDB?

| Scenario | SleekDB Advantage |
|----------|-------------------|
| **High-frequency key lookups** | <1ms vs ~500ms cold (file-per-record architecture) |
| **Very low memory** | Lower RAM usage |

> **Note:** SleekDB stores each record as a separate file, making single-record lookups instant but bulk operations slow.

---

### Architectural Differences

| Feature | SleekDB | noneDB |
|---------|---------|--------|
| **Storage** | One JSON file per record | JSONL + byte-offset index |
| **ID Access** | Direct file read (O(1)) | Index lookup + seek |
| **Bulk Read** | Traverse all files | Single file read |
| **Sharding** | None | Automatic (10K+) |
| **Cache** | Per-query | Static cross-instance |
| **Indexing** | None | Byte-offset (.jidx) |
| **Spatial Index** | None | R-tree (v3.1) |

---

### Detailed Benchmark Results

#### Bulk Insert

| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 7ms | 24ms | **noneDB 3x** |
| 1K | 26ms | 250ms | **noneDB 10x** |
| 10K | 306ms | 2.89s | **noneDB 9x** |
| 50K | 1.59s | 12.4s | **noneDB 8x** |
| 100K | 3.34s | 30.76s | **noneDB 9x** |

#### Find All Records

| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 3ms | 28ms | **noneDB 8x** |
| 1K | 7ms | 286ms | **noneDB 42x** |
| 10K | 65ms | 2.71s | **noneDB 42x** |
| 50K | 300ms | 16.83s | **noneDB 56x** |
| 100K | 595ms | 39.03s | **noneDB 66x** |

#### Find by Key (Single Record - Cold)

| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 3ms | <1ms | SleekDB |
| 1K | 3ms | <1ms | SleekDB |
| 10K | 55ms | <1ms | **SleekDB** |
| 50K | 287ms | <1ms | **SleekDB** |
| 100K | 561ms | <1ms | **SleekDB** |

> **Note:** SleekDB's file-per-record design gives O(1) key lookup. noneDB must load shard index first (but subsequent lookups are O(1) with cache).

#### Find with Filter

| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | <1ms | 10ms | **noneDB 24x** |
| 1K | 4ms | 94ms | **noneDB 25x** |
| 10K | 49ms | 998ms | **noneDB 20x** |
| 50K | 254ms | 13.18s | **noneDB 52x** |
| 100K | 524ms | 41.64s | **noneDB 79x** |

#### Count Operations

| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | <1ms | <1ms | **noneDB 4x** |
| 1K | <1ms | 1ms | **noneDB 11x** |
| 10K | <1ms | 9ms | **noneDB 90x** |
| 50K | <1ms | 51ms | **noneDB 330x** |
| 100K | <1ms | 96ms | **noneDB 258x** |

> **v3.0 Optimization:** noneDB uses O(1) index metadata lookup for count() - no record scanning!

#### Update Operations

| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 1ms | 20ms | **noneDB 15x** |
| 1K | 11ms | 188ms | **noneDB 17x** |
| 10K | 118ms | 2.14s | **noneDB 18x** |
| 50K | 669ms | 20.91s | **noneDB 31x** |
| 100K | 1.53s | 61.27s | **noneDB 40x** |

#### Delete Operations

| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | 2ms | 10ms | **noneDB 5x** |
| 1K | 15ms | 105ms | **noneDB 7x** |
| 10K | 150ms | 1.27s | **noneDB 8x** |
| 50K | 839ms | 14.61s | **noneDB 17x** |
| 100K | 1.75s | 40.01s | **noneDB 23x** |

#### Complex Query (where + sort + limit)

| Records | noneDB | SleekDB | Winner |
|---------|--------|---------|--------|
| 100 | <1ms | 12ms | **noneDB 27x** |
| 1K | 4ms | 114ms | **noneDB 30x** |
| 10K | 55ms | 1.2s | **noneDB 22x** |
| 50K | 295ms | 15.33s | **noneDB 52x** |
| 100K | 591ms | 41.3s | **noneDB 70x** |

---

### Summary

| Use Case | Winner | Advantage |
|----------|--------|-----------|
| **Bulk Insert** | **noneDB** | 3-10x faster |
| **Find All** | **noneDB** | 8-66x faster |
| **Find with Filter** | **noneDB** | 20-79x faster |
| **Update** | **noneDB** | 15-40x faster |
| **Delete** | **noneDB** | 5-23x faster |
| **Complex Query** | **noneDB** | 22-70x faster |
| **Count** | **noneDB** | 4-330x faster (O(1) index lookup) |
| **Spatial Queries** | **noneDB** | R-tree indexing (SleekDB: none) |
| **Find by Key (cold)** | **SleekDB** | O(1) file access |

> **Choose noneDB** for: Bulk operations, large datasets, filter queries, update/delete workloads, complex queries, count operations, geospatial queries
>
> **Choose SleekDB** for: High-frequency single-record lookups by ID (cold cache scenarios)

---

## Running Benchmarks

```bash
# Performance benchmark
php tests/performance_benchmark.php

# SleekDB comparison (requires SleekDB installed)
php tests/sleekdb_comparison.php

# Spatial benchmark
php tests/spatial_benchmark.php
```
