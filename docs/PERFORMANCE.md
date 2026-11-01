# noneDB 4 Performance

**Engine:** noneDB 4.0.0 · **Measured with:** PHP 8.2.0 (CLI)

This document covers what is measured and against what, with the results and the limits found. The numbers
come from the scripts of §8, except the costs of the exact map in §4 and §7, which come from one-off measurements.

**The baseline** is the frozen 3.3 development state (`build/baseline/noneDB-3.3dev.php`, sha256 `32c3d641…`): the
last code of the 3.x line. It was itself measured against 3.2.0: single writes to 10,000 records were 12–17 times
faster, with no verified regression.

---

## 1. Summary

- **The performance gate** (§2): two independent runs of 66 workloads.
  - 46 workloads are verified improvements and 13 show no verified change.
  - 5 are findings, significant in both runs: one insert into a small shard is 3.2–7.0% slower, the others at most 3.1%.
  - 2 are fixed costs of the fail-closed store, within their budgets.
  - None is a verified regression.
  - Cold operations (a new process) are up to 30 times faster. One is slower: a miss on one shard, +0.19 ms,
    within its budget.
- **Ten million records** (§3): the first indexed query of a process takes 0.04–0.27 s (3.3-dev: 1.9–7.3 s), and
  queries need 68–244 MiB where 3.3-dev ran out of 512 MiB. All seven targets of the specification are met.
- **noneDB and SQLite** (§5): SQLite is faster everywhere, as a C library should be. noneDB needs no extension, and
  its files are lines of JSON.

## 2. The performance gate

`tests/Performance/perf_gate.php` compares two libraries workload by workload.

**Method**
- **Alternation:** the baseline and the candidate run alternately (ABBA). Each run is a child process on a fresh
  copy of a fixture built by the same library. The shard matrix reuses one fixture per library: its lookups run
  before its inserts. Seeds are fixed.
- **Runs:** 40 per library and workload (30 for the concurrent ones).
- **Kinds of workload:**
  - *warm*: k timed operations after 3 untimed ones;
  - *cold*: a new instance and its first operation;
  - *bulk*: one timed operation after one key lookup.
- **Statistics:**
  - Ratio = candidate / baseline (> 1 is slower), with a 99% bootstrap confidence interval of the median ratio.
  - A one-sided Mann-Whitney test and the Hodges-Lehmann shift.
  - p95 values pool up to 200 latencies per run, and use a cluster bootstrap over runs.
- **A regression** must show in two independent runs. It is one of:
  - a median more than 5% slower and significant (p < 0.01 and CI above 1);
  - a p95 more than 5% slower with its CI above 1;
  - a throughput CI entirely below 0.95 (concurrent workloads);
  - peak memory more than 5% and more than 64 KiB higher.

  A significant slowdown of 5% or less is a **finding**, explained here and not counted.
- **Fixed costs of the fail-closed store** are judged against absolute budgets, not a ratio:
  - 0.25 ms for a cold workload (a new instance and its first operation);
  - 0.1 ms for `find_missing_db_warm` (a read of a database that doesn't exist: the directory is checked every
    time);
  - 0.1 ms for `find_key_after_epoch_change`.

  A time regression within its budget in both runs is reported as "within budget".
- **Settings:** performance mode; child processes with `memory_limit=-1`; the operating system's file cache warm.

**Result** (2026-11-01, 2 × 20 minutes; the 15 workloads of the field index code were measured again after its last change, 2 × 2.5 minutes, and their rows below are those):

| Workload | 3.3-dev median per run | Ratio run 1 [99% CI] | Ratio run 2 [99% CI] | p95 ratio (1 / 2) | Memory ratio (1) | Verdict |
|---|---|---|---|---|---|---|
| `find_key_warm` | 87.756 ms | 0.965 [0.961, 0.969] | 0.965 [0.962, 0.967] | 0.991 / 1.044 | 0.867 | improvement |
| `insert_one_100_warm` | 14.204 ms | 1.040 [1.007, 1.074] | 1.013 [0.987, 1.060] | 1.049 / 1.030 | 1.038 | no verified change |
| `insert_one_10k_warm` | 4.026 ms | 1.025 [0.980, 1.064] | 1.019 [0.985, 1.062] | 1.023 / 1.022 | 0.949 | no verified change |
| `update_one_10k_warm` | 5.377 ms | 0.950 [0.918, 0.966] | 0.953 [0.920, 0.973] | 0.960 / 0.954 | 0.852 | improvement |
| `delete_one_10k_warm` | 11.942 ms | 0.938 [0.908, 0.967] | 0.927 [0.904, 0.956] | 0.927 / 0.932 | 0.919 | improvement |
| `insert_one_indexed_warm` | 4.103 ms | 0.996 [0.968, 1.017] | 1.000 [0.986, 1.026] | 0.960 / 0.984 | 0.949 | no verified change |
| `update_indexed_10k_warm` | 52.550 ms | 0.937 [0.931, 0.945] | 0.905 [0.902, 0.912] | 0.951 / 0.911 | 0.912 | improvement |
| `range_indexed_warm` | 85.285 ms | 0.862 [0.860, 0.866] | 0.864 [0.863, 0.866] | 0.854 / 0.880 | 0.755 | improvement |
| `find_indexed_eq_warm` | 39.120 ms | 1.030 [1.029, 1.033] | 1.031 [1.028, 1.033] | 1.033 / 1.030 | 1.042 | finding to investigate |
| `count_filter_warm` | 170.948 ms | 0.934 [0.931, 0.936] | 0.934 [0.932, 0.937] | 0.925 / 0.944 | 0.948 | improvement |
| `sort_limit_warm` | 181.813 ms | 0.956 [0.951, 0.960] | 0.956 [0.954, 0.962] | 0.951 / 0.965 | 0.958 | improvement |
| `within_bbox_warm` | 45.696 ms | 1.017 [1.014, 1.019] | 1.016 [1.013, 1.019] | 1.016 / 1.014 | 1.010 | finding to investigate |
| `within_distance_warm` | 39.899 ms | 0.986 [0.985, 0.990] | 0.987 [0.985, 0.989] | 0.994 / 0.993 | 0.980 | improvement |
| `nearest_warm` | 161.566 ms | 0.993 [0.992, 0.995] | 0.993 [0.991, 0.995] | 1.015 / 1.016 | 1.001 | improvement |
| `sharded_find_key_warm` | 106.406 ms | 0.895 [0.892, 0.898] | 0.896 [0.894, 0.900] | 0.943 / 0.961 | 1.037 | improvement |
| `sharded_find_indexed_warm` | 52.033 ms | 0.871 [0.868, 0.875] | 0.870 [0.866, 0.873] | 0.878 / 0.864 | 1.033 | improvement |
| `sharded_insert_one_warm` | 38.311 ms | 1.037 [1.014, 1.058] | 1.013 [0.986, 1.040] | 1.060 / 0.993 | 1.005 | no verified change |
| `find_missing_db_warm` | 27.496 ms | 1.598 [1.595, 1.604] | 1.598 [1.595, 1.601] | 8.683 / 8.574 | 1.769 | within budget (+0.041 ms ≤ 0.10 ms per operation) |
| `find_key_after_epoch_change` | 62.243 ms | 0.243 [0.239, 0.252] | 0.242 [0.241, 0.243] | 0.256 / 0.248 | 0.197 | improvement |
| `find_key_cold` | 1.825 ms | 0.450 [0.445, 0.456] | 0.459 [0.455, 0.463] | - | 0.368 | improvement |
| `insert_one_cold` | 1.926 ms | 0.499 [0.495, 0.502] | 0.503 [0.499, 0.511] | - | 0.364 | improvement |
| `find_indexed_cold` | 3.396 ms | 0.555 [0.552, 0.557] | 0.556 [0.552, 0.559] | - | 0.506 | improvement |
| `within_bbox_cold` | 8.742 ms | 0.749 [0.747, 0.754] | 0.753 [0.749, 0.756] | - | 0.918 | improvement |
| `sharded_find_indexed_cold` | 7.241 ms | 0.485 [0.482, 0.489] | 0.485 [0.480, 0.491] | - | 0.396 | improvement |
| `bulk_insert_1k` | 8.330 ms | 0.970 [0.967, 0.976] | 0.969 [0.963, 0.977] | - | 0.976 | improvement |
| `bulk_insert_10k` | 82.531 ms | 0.959 [0.956, 0.961] | 0.958 [0.957, 0.961] | - | 0.986 | improvement |
| `bulk_insert_100k` | 1011.011 ms | 0.906 [0.905, 0.908] | 0.907 [0.903, 0.911] | - | 0.994 | improvement |
| `sharded_bulk_insert_10k` | 209.913 ms | 1.006 [1.003, 1.008] | 1.006 [1.003, 1.007] | - | 0.259 | finding to investigate |
| `find_all_10k` | 116.097 ms | 0.943 [0.942, 0.945] | 0.944 [0.943, 0.946] | 0.944 / 0.943 | 0.975 | improvement |
| `filter_unindexed_10k` | 128.849 ms | 0.942 [0.938, 0.947] | 0.943 [0.937, 0.947] | 0.954 / 0.941 | 0.965 | improvement |
| `bulk_update_10k` | 58.068 ms | 0.970 [0.967, 0.973] | 0.969 [0.967, 0.972] | - | 0.992 | improvement |
| `bulk_delete_10k` | 49.591 ms | 0.979 [0.977, 0.981] | 0.980 [0.973, 0.989] | - | 0.992 | improvement |
| `buffer_insert_flush` | 91.742 ms | 0.973 [0.955, 1.019] | 1.000 [0.984, 1.027] | - | 1.014 | no verified change |
| `compact_10k` | 65.825 ms | 0.998 [0.991, 1.002] | 0.998 [0.996, 1.001] | - | 0.632 | no verified change |
| `sharded_compact_10k` | 74.241 ms | 0.974 [0.971, 0.976] | 0.971 [0.967, 0.974] | - | 0.863 | improvement |
| `delete_autocompact_10k` | 45.954 ms | 0.985 [0.982, 0.987] | 0.985 [0.983, 0.988] | - | 0.991 | improvement |
| `create_field_index_10k` | 46.712 ms | 1.003 [1.001, 1.006] | 1.004 [1.002, 1.007] | - | 1.002 | finding to investigate |
| `rebuild_index_10k` | 45.311 ms | 0.996 [0.994, 1.000] | 0.997 [0.994, 1.000] | - | 0.834 | improvement |
| `conc_writers_4` | 65.553 ms | 1.044 [0.999, 1.104] | 1.009 [0.979, 1.188] | - | 1.000 | no verified change |
| `conc_writers_8` | 125.087 ms | 0.996 [0.976, 1.021] | 1.017 [0.996, 1.131] | - | 1.000 | no verified change |
| `conc_readers_4` | 103.960 ms | 0.972 [0.914, 1.034] | 0.980 [0.914, 1.032] | - | 1.000 | no verified change |
| `conc_mixed_8` | 150.450 ms | 1.035 [0.955, 1.090] | 1.060 [0.972, 1.113] | - | 1.000 | no verified change |
| `m1_lookup_hit_warm` | 10.245 ms | 1.007 [1.001, 1.009] | 1.005 [1.000, 1.010] | 1.049 / 1.019 | 0.700 | no verified change |
| `m1_lookup_miss_warm` | 4.387 ms | 0.959 [0.954, 0.964] | 0.961 [0.957, 0.964] | 0.965 / 0.963 | 1.000 | improvement |
| `m1_g_lookup_warm` | 12.512 ms | 0.993 [0.988, 0.996] | 0.995 [0.991, 0.998] | 0.992 / 0.998 | 0.632 | improvement |
| `m1_lookup_hit_cold` | 1.079 ms | 0.996 [0.985, 1.010] | 0.990 [0.979, 1.006] | - | 0.685 | no verified change |
| `m1_lookup_miss_cold` | 0.613 ms | 1.303 [1.288, 1.321] | 1.306 [1.291, 1.322] | - | 1.297 | within budget (+0.188 ms ≤ 0.25 ms per operation) |
| `m1_insert_warm` | 9.225 ms | 1.070 [1.007, 1.189] | 1.032 [1.013, 1.050] | 1.137 / 1.034 | 1.061 | finding to investigate |
| `m10_lookup_hit_warm` | 15.944 ms | 0.850 [0.846, 0.855] | 0.852 [0.846, 0.855] | 1.032 / 1.019 | 0.504 | improvement |
| `m10_lookup_miss_warm` | 4.396 ms | 0.962 [0.957, 0.968] | 0.964 [0.959, 0.968] | 0.965 / 0.963 | 1.000 | improvement |
| `m10_g_lookup_warm` | 38.128 ms | 0.917 [0.915, 0.920] | 0.918 [0.915, 0.923] | 0.918 / 0.926 | 0.647 | improvement |
| `m10_lookup_hit_cold` | 2.852 ms | 0.400 [0.397, 0.405] | 0.407 [0.402, 0.413] | - | 0.131 | improvement |
| `m10_lookup_miss_cold` | 2.350 ms | 0.374 [0.369, 0.378] | 0.371 [0.367, 0.376] | - | 0.130 | improvement |
| `m10_insert_warm` | 9.682 ms | 1.005 [0.977, 1.017] | 0.999 [0.969, 1.023] | 0.954 / 0.966 | 0.999 | no verified change |
| `m100_lookup_hit_warm` | 69.729 ms | 0.646 [0.644, 0.648] | 0.646 [0.644, 0.647] | 0.595 / 0.594 | 0.474 | improvement |
| `m100_lookup_miss_warm` | 4.411 ms | 0.965 [0.961, 0.969] | 0.964 [0.959, 0.967] | 0.970 / 0.967 | 1.000 | improvement |
| `m100_g_lookup_warm` | 102.906 ms | 0.943 [0.942, 0.945] | 0.943 [0.942, 0.945] | 1.025 / 1.016 | 0.481 | improvement |
| `m100_lookup_hit_cold` | 21.592 ms | 0.081 [0.081, 0.082] | 0.081 [0.080, 0.081] | - | 0.028 | improvement |
| `m100_lookup_miss_cold` | 20.775 ms | 0.070 [0.070, 0.071] | 0.070 [0.069, 0.070] | - | 0.025 | improvement |
| `m100_insert_warm` | 14.618 ms | 0.839 [0.823, 0.862] | 0.844 [0.821, 0.868] | 0.837 / 0.838 | 0.744 | improvement |
| `m1000_lookup_hit_warm` | 161.291 ms | 0.653 [0.651, 0.656] | 0.653 [0.651, 0.656] | 0.643 / 0.638 | 0.492 | improvement |
| `m1000_lookup_miss_warm` | 4.443 ms | 0.964 [0.960, 0.969] | 0.963 [0.961, 0.971] | 0.963 / 0.965 | 1.000 | improvement |
| `m1000_g_lookup_warm` | 718.900 ms | 0.612 [0.610, 0.614] | 0.612 [0.611, 0.615] | 0.565 / 0.565 | 0.468 | improvement |
| `m1000_lookup_hit_cold` | 215.761 ms | 0.036 [0.036, 0.036] | 0.036 [0.036, 0.036] | - | 0.018 | improvement |
| `m1000_lookup_miss_cold` | 215.431 ms | 0.034 [0.034, 0.034] | 0.034 [0.034, 0.034] | - | 0.017 | improvement |
| `m1000_insert_warm` | 54.718 ms | 0.713 [0.709, 0.717] | 0.701 [0.694, 0.708] | 0.725 / 0.623 | 0.779 | improvement |

Workload names: `m{N}_*` is the shard matrix: N shards of 1000 records, both fields indexed. Field `u` is unique;
field `g` has 32 × N values, each in every shard at 10 shards and in about 31 shards at 100 and 1000. `*_10k` is a database of 10,000 records. The definitions are in
`perf_gate.php`.

**At the 5% line:** single inserts sit near the line and move with the session:
- `m1_insert_warm`: +7.0% / +3.2% here. It is over the line in one run only, so it is not a verified regression. One
  insert into a shard of 1,000 records with two indexed fields. The few µs are the steps of the v4 contract per write: the
  operation's outcome (DURABILITY.md §6), generation and rollback marks, the store and directory checks.
- `insert_one_100_warm`: +4.0% / +1.3% and `insert_one_10k_warm` +2.5% / +1.9% here, no verified change.

**Findings** (significant in both runs):
- **Single inserts:** `m1_insert_warm`, above.
- **Indexed reads:** `find_indexed_eq_warm` (100 results) +3.0–3.1%, `within_bbox_warm` +1.6–1.7%.
  Every segment's key index is checked against its data (epoch, size) before it is used.
- **Within 1%:** `create_field_index_10k`, `sharded_bulk_insert_10k`.

## 3. Ten million records

`tests/Performance/large.php` runs this workload: 10,000,000 records `{group, metric, tag, payload}`, inserted in
batches, then `createFieldIndex('group')`. `group` has 10,000 values and every value is in every shard. That is
the worst case for an indexed lookup, which has to visit every shard. It is run with 100 shards of 100,000 records
and with 1000 shards of 10,000.

- **Conditions:** the files are read once after the build, so the operating system's cache is warm.
- **Cold:** 40 new processes.
- **Warm:** 200 key lookups or updates, or 40 indexed queries, in a process that has already run one of the kind.
- **Memory:** `cacheMemoryLimit` is `'auto'` unless a row says otherwise.
- **What was measured:** version 4.0.0 (2026-11-01), and 3.3-dev
  (`32c3d641…`).

| | 4.0, 100 shards | 3.3-dev, 100 shards | 4.0, 1000 shards | 3.3-dev, 1000 shards |
|---|---|---|---|---|
| Bulk insert | 35.6 s | 40.9 s | 32.0 s | 36.4 s |
| `createFieldIndex('group')` | 34.4 s | 29.5 s | 37.7 s | 38.4 s |
| Peak memory of the build | 1512 MiB | 1609 MiB | 2582 MiB | 2878 MiB |
| Disk | 1157 MiB | 1328 MiB | 1280 MiB | 1420 MiB |
| Warm key lookup p50 / p95 | 0.064 / 0.769 ms | 0.326 / 44.0 ms | 0.275 / 0.285 ms | 4.89 / 5.01 ms |
| Warm indexed query, 1000 results, p50 / p95 / p99 | 12.4 / 13.1 / 21.9 ms | 19.4 / 26.8 / 28.2 ms | 24.1 / 74.5 / 219 ms | 30.8 / 100.4 / 307 ms |
| First indexed query of a process | 38 ms | 1.93 s | 270 ms | 7.27 s |
| Warm update by key p50 / p95 | 0.171 / 0.415 ms | 0.345 / 1.22 ms | 0.229 / 0.244 ms | 0.479 / 1.05 ms |
| Warm process peak | 218 MiB | 1480 MiB | 1380 MiB | 2392 MiB |
| Cold key lookup p50 / p95 | 1.33 / 1.36 ms | 45.2 / 46.6 ms | 1.39 / 1.43 ms | 6.40 / 7.17 ms |
| Cold indexed query p50 / p95 | 116 / 116 ms | 6.91 / 6.98 s | 303 / 305 ms | 7.97 / 8.24 s |
| Indexed query, `cacheMemoryLimit` 384 MiB, p95 | 16.2 ms | 5.98 s | 58.2 ms | 7.58 s |
| Query process at `memory_limit=512M` | works, peak 210 MiB | out of memory | works, peak 244 MiB | out of memory |
| Query process at `memory_limit=128M` | works, peak 112 MiB | — | works, peak 68 MiB | — |

**The release targets** for 10 million records:

| Target | Result |
|---|---|
| Bulk insert: no verified regression | Met: 12–13% faster |
| Warm key lookup: no verified regression | Met: 5–18 times faster |
| Warm indexed query: no verified regression | Met: 12.4 against 19.4 ms on 100 shards, 24.1 against 30.8 ms on 1000 |
| Cold key lookup: better, p95 ≤ 100 ms | Met: 1.36 / 1.43 ms |
| Cold indexed query of 1000 results: p95 ≤ 500 ms | Met: 116 / 305 ms |
| 384 MiB cache: no reload loop of seconds | Met: 16.2 / 58.2 ms |
| Queries within a 512 MiB process | Met: peak 210 / 244 MiB |

The two columns of `createFieldIndex()` come from different sessions. Run one after the other in one session, at the
same memory, the two are level: 1.00–1.02 with PHP 8.2, 6–7% faster in a Linux container with PHP 8.4. Reading the
records takes most of the time, in both.

**The 1000-shard query holds data files** under the automatic cache limit (§4). That is where its median comes from, and it has
a price: a warm process peaks at 1380 MiB, and the queries that load a file show as the p95 and p99. All three stay under
3.3-dev's. A build that held none had a median of 42.4 ms at a peak of 380 MiB.

## 4. First use and later use

noneDB decodes an index into PHP arrays only once its use pays for the decoding. Until then it reads the index's
pages, or keeps its body as bytes. A single cold operation therefore stays cheap: the cold rows above are 4 to 60
times faster than 3.3-dev, which decodes every index it touches at once. Later operations take the decoded form.
Where the work falls:

- **Key indexes** of a database with at most 16 segments are decoded at the start of its second operation: about
  1.8 ms per 10,000 keys. In a larger database a segment's key index is decoded once its lookups pay for it.
- **A field index** of up to 64 KiB is read whole at its first use, except in the process's first operation. A
  larger one is read whole once it has been used `c / 20` times (c: its values).
- **The global field index of a sharded database:** its filters answer. From its second operation the process holds
  the index's body: 1–7 ms at a million records. A command-line process also builds an exact map of values to shards
  at that operation, from the shards' field index files (a file it already holds as it is, it doesn't read again), when
  the database has at most a million records. That takes 0.15–1 s at a million records, once; later lookups are a map
  lookup. Above that size reading every shard's file would take seconds, and in a web request the map couldn't repay
  itself before the request ends, so neither builds one.
- **Data files of a database of more than 16 segments:** reading one record costs about what reading 128 KiB at once
  does, so a file in repeated use is read whole and held. It needs a process with a memory limit, and room under half of the
  cache limit (CONFIGURATION.md "The cache"). A process with no limit holds none.
- **Field indexes built later:** a writer that only writes never loads field indexes. After a bulk load, the first
  query of a field builds the indexes of the shards that have none, from their data, and the field's global index.
  For a field of unique values over 100,000 records in 10 shards that took 0.8 s, once (3.3-dev: 0.46 s).
  `createFieldIndex()` after the load does it in advance.

Under PHP-FPM every request is a new process, so the cold numbers apply. A long-running worker reaches the warm
ones.

## 5. noneDB and SQLite

`tests/Performance/sqlite_comparison.php`, 2026-10-29, PHP 8.2.0, SQLite 3.43.2, 5 runs per engine and size, each in
its own process. Every workload returned the same records in the same order on all four engines.

- **Engines:**
  - **noneDB-indexed:** field indexes on `city`, `age` and `email`; from 10,000 records the database is sharded
    (its default `shardSize`).
  - **noneDB-scan:** the same indexes with `fieldIndexEnabled => false`: writes keep them, queries don't use them.
  - **SQLite-doc:** JSON documents with expression indexes on the same fields: the closest to what noneDB stores.
  - **SQLite-columns:** typed columns with B-tree indexes: an upper bound, not a document store.
- **Settings:** SQLite in WAL mode with `synchronous = OFF`, noneDB in performance mode.
- **Warm:** the calls a workload measures ran once unmeasured first.

Milliseconds per operation, median of the runs (insert_500 and update_300: all 500 and 300 calls together):

**10,000 records**

| Workload | noneDB-indexed | noneDB-scan | SQLite-doc | SQLite-columns |
|---|---|---|---|---|
| find by key | 0.044 | 0.046 | 0.006 | 0.008 |
| find, equality (2000 results) | 7.76 | 30.1 | 0.81 | 1.41 |
| find, unique value | 0.053 | 29.8 | 0.008 | 0.008 |
| range, 4 ages (800 results) | 3.82 | 42.8 | 0.38 | 0.63 |
| count with a filter | 6.74 | 30.4 | 0.067 | 0.048 |
| first() | 0.228 | 0.512 | 0.008 | 0.009 |
| exists() | 0.071 | 0.507 | 0.007 | 0.006 |
| where + sort + limit 10 | 8.81 | 33.4 | 1.38 | 0.76 |
| distinct (977 values) | 35.2 | 34.9 | 4.65 | 0.92 |
| sum with a filter | 7.14 | 31.0 | 1.14 | 0.35 |
| join, limit 50 | 1.90 | 5.10 | 0.069 | 0.048 |
| 500 single inserts | 196 | 212 | 20.6 | 19.8 |
| 300 single updates | 91.6 | 86.7 | 12.4 | 3.65 |

**1,000 and 100,000 records** (medians)

| Workload | noneDB-indexed 1k | SQLite-doc 1k | noneDB-indexed 100k | SQLite-doc 100k |
|---|---|---|---|---|
| find by key | 0.042 | 0.005 | 0.046 | 0.006 |
| find, equality | 0.688 | 0.082 | 80.7 | 11.0 |
| find, unique value | 0.047 | 0.007 | 0.063 | 0.008 |
| range | 0.421 | 0.043 | 39.5 | 7.13 |
| where + sort + limit 10 | 0.934 | 0.144 | 90.8 | 15.4 |
| 500 single inserts | 57.5 | 15.9 | 208 | 18.7 |
| 300 single updates | 38.4 | 8.88 | 40.5 | 14.8 |

SQLite is a C library with B-trees and prepared statements:
- A key lookup takes 0.006–0.008 ms against noneDB's 0.04–0.05 ms.
- Queries that return or count many records are 6–170 times faster in SQLite, because noneDB decodes every record
  it reads in PHP.
- Single writes are 3–11 times faster in SQLite-doc (up to 25 times with typed columns).

noneDB needs no extension. It stores plain JSON lines, which a crash can only cut at the end, and it runs where
`pdo_sqlite` isn't available. For heavy querying of large data, SQLite is the better tool.

## 6. Durable mode

`durability => 'durable'` is supported on Linux only: every write waits for its data, and for its directory when
it created or renamed a file, to reach the device (DURABILITY.md). Its cost is the device's sync latency per write.
That is a property of the disk and its cache, from tens of microseconds on an SSD with power-loss protection to
milliseconds elsewhere. PHP can't flush the drive cache on macOS, so durable mode isn't measured there. Performance mode, which every number of this document uses, issues no sync on the write path.

Measured on Linux: PHP 8.5 in a Docker container, a database of 10,000 records, not sharded, 300 runs
of each single operation. The container's virtual disk syncs at two speeds, about 0.35 and about 1.3 ms, and the median of a run
can fall on either; a real device gives its own number.

| Operation | performance, median | durable, median | Syncs (file + directory) |
|---|---|---|---|
| Insert one record | 0.018 ms | 0.388 ms | 1 + 0 |
| Insert 100 records | 0.074 ms | 0.508 ms | 1 + 0 |
| Update one record by key | 0.024 ms | 0.380 ms | 1 + 0 |
| Delete one record by key | 0.023 ms | 0.380 ms | 1 + 0 |
| Create a database | 0.784 ms | 6.990 ms | 5 + 8 |
| Compact 10,000 records | 16.3 ms | 14.5 ms | 2 + 1 |

A batch costs one sync, whatever its size. A data file's replacement is synced in both modes, so `compact()` costs the
same in each.

## 7. Known limits

- **Memory and tail of a warm query over 1000 shards** (§3): a process under the automatic cache limit holds data files, and
  peaks at 1380 MiB (3.3-dev: 2392). The queries that load a file are its p95 and p99, 74.5 and 219 ms (3.3-dev: 100 and 307).
  `cacheMemoryLimit` bounds it: at 384 MiB the peak is 332 MiB and the median 56 ms.
- **The first query on a cold file cache:** a process whose files are not in the operating system's cache reads them from
  disk. At 1000 shards the first indexed query took 400–834 ms (a Linux container, PHP 8.4), 101–120 ms with the cache
  warm. The queries after it are the warm ones.
- **Close to `memory_limit`** (CONFIGURATION.md "Memory of operations over many segments"): an operation over large shards
  fills what the automatic cache limit leaves. On 10 million records in shards of 100,000 at `memory_limit=128M`, an
  unindexed `find()` peaks at 123 MiB, a sum at 121 MiB and `createFieldIndex()` at 121 MiB. They finish; a script that
  holds much besides needs more memory or a smaller `cacheMemoryLimit`. One segment of 300,000 records with a different
  value each needs more for `checkIntegrity()` (133 MiB) and for a range with a large answer.
- **The exact map in a short command-line script** (§4): a script that runs two or more indexed queries on a sharded
  database of up to a million records pays for the map once, 0.15–1 s, and a map saves 7–20 µs per lookup: it repays
  itself after 15,000–66,000 lookups. Long-running workers get there; a short script doesn't. Without the map the gate's
  warm lookups, which leave its build out, would be 5–15% slower than 3.3-dev's (1–4 µs per lookup).
- **A writer behind readers that never stop:** locks are polled with `flock`, there is no queue, so readers that always
  overlap can keep a writer out until its `lockTimeout` ends in `LOCK_FAILED`. With two nonstop readers on 2 million
  records and a 16 MiB cache, 216 of 1.26 million updates timed out at a `lockTimeout` of 30 s; on 1.8 million records with
  the data held in the cache, none of 22 million. A writer under such a load retries `LOCK_FAILED`;
  `getMetrics()` counts them in `locks.timeouts`.
- **The gate's findings and budgeted costs** (§2).
- **Field indexes built at first use after a bulk load** (§4).
- **A cache limit below the working set** makes queries load indexes again. `getMetrics()` shows it as evictions
  and misses that grow with every operation; size `cacheMemoryLimit` as CONFIGURATION.md "The cache" describes.

## 8. Running the measurements

Every script uses its own temporary directory and key; none of them touches your databases.

<!-- doctest: skip -->
```bash
# The performance gate: A/B against another noneDB.php, about 20 minutes per run; a regression only when two
# independent runs show it
php tests/Performance/perf_gate.php run --baseline=build/baseline/noneDB-3.3dev.php --candidate=noneDB.php --out=run1.json --md=run1.md
php tests/Performance/perf_gate.php run --baseline=build/baseline/noneDB-3.3dev.php --candidate=noneDB.php --out=run2.json --md=run2.md
php tests/Performance/perf_gate.php verify run1.json run2.json

# Large databases (10M records, one library per run; --purge empties the macOS file cache first)
php tests/Performance/large.php run --lib=noneDB.php --out=large --total=10000000 --shard-sizes=100000,10000

# noneDB and SQLite (needs pdo_sqlite)
php tests/Performance/sqlite_comparison.php 1000,10000,100000 --runs=5 --markdown

# Single operations, operators, spatial queries, dataset sizes
php tests/Performance/operation_benchmark.php
php tests/Performance/operator_benchmark.php
php tests/Performance/spatial_benchmark.php
php tests/Performance/performance_benchmark.php 100,1000,10000

# The cost of durable mode (Linux only)
php tests/Performance/durability_cost.php

# Soak: hours of work by several processes under random SIGKILL, checked by a process of its own
php tools/soak.php run --out=DIR --hours=N
```
