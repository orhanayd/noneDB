<?php
/**
 * noneDB performance gate: is a candidate noneDB.php slower than a baseline copy?
 *
 *   php tests/Performance/perf_gate.php run --baseline=build/baseline/noneDB-3.3dev.php --candidate=noneDB.php \
 *       --out=first.json [--md=first.md] [--runs=40] [--only=REGEX] [--work=DIR] [--quick] [--warm-up=N]
 *   --warm-up=N: untimed operations before a warm workload is timed (default 3). A larger N measures the
 *   steady state; the default measures what the release rule judges.
 *       [--baseline-config=JSON] [--candidate-config=JSON]
 *   php tests/Performance/perf_gate.php verify first.json second.json [--md=verdict.md]
 *
 * Method:
 * - Every run is its own child process on a fresh copy of a fixture the same library built.
 *   Baseline (A) and candidate (B) alternate in ABBA order.
 * - Each workload times a unit of work (one sample per run, >= 40 runs for the median) and, for
 *   single operations, every operation's latency (pooled over runs for the p95, >= 100 samples).
 * - ratio = candidate / baseline (> 1: slower). 99% bootstrap confidence interval of the median
 *   ratio, one-sided Mann-Whitney U test, Hodges-Lehmann shift; p95 ratio with a cluster bootstrap
 *   (runs are resampled, so latencies of one process are not treated as independent).
 * - regression = p < 0.01 and CI lower bound > 1 and ratio > 1.05 (median, or p95 with >= 100
 *   samples); concurrent workloads: throughput ratio CI entirely below 0.95; memory: peak of the
 *   workload (memory_reset_peak_usage, PHP >= 8.2; approximate before) > 5% and > 64 KiB higher.
 *   Significant but smaller slowdowns are reported as findings.
 * - Fixed costs of the fail-closed store are judged by absolute budgets (timeBudget()): a time regression
 *   within its budget in both runs is reported as "within budget", not counted.
 * - A regression counts only when a second, independent run shows it too: "verify" compares two
 *   run files and exits with 1 if any workload regressed in both.
 * - Baseline and candidate the same file (A/A): the noise floor; a workload whose CI is wider than
 *   +-2% is marked unstable (more runs or a bigger unit of work needed before deciding on it).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
error_reporting(E_ALL);

const GATE_SECRET = '5f3c8a1e9b2d4f6a7c0e1b3d5f7a9c2e4b6d8f0a1c3e5b7d9f2a4c6e8b0d1f3a';
const GATE_DB = 'bench';

$mode = $argv[1] ?? '';
if ($mode === 'child') {
    exit(childMain(array_slice($argv, 2)));
}
if ($mode === 'conc') {
    exit(concurrentChildMain(array_slice($argv, 2)));
}
if ($mode === 'fixture') {
    exit(fixtureMain(array_slice($argv, 2)));
}
if ($mode === 'run') {
    exit(runMain(parseOptions(array_slice($argv, 2))));
}
if ($mode === 'verify') {
    exit(verifyMain(array_slice($argv, 2)));
}
fwrite(STDERR, "usage: php tests/Performance/perf_gate.php run --baseline=FILE --candidate=FILE --out=FILE [--md=FILE] [--runs=N] [--only=REGEX] [--quick]\n"
    . "       php tests/Performance/perf_gate.php verify FIRST.json SECOND.json [--md=FILE]\n");
exit(2);

// ======================================================================
// Fixtures and workloads
// ======================================================================

/**
 * Fixture specs: records inserted in batches of 1000, then the listed indexes created
 */
function fixtureSpecs(): array {
    return [
        'empty'      => ['records' => 0,     'config' => ['shardingEnabled' => false]],
        'emptySh'    => ['records' => 0,     'config' => ['shardSize' => 1000]],
        'plain100'   => ['records' => 100,   'config' => ['shardingEnabled' => false]],
        'plain10k'   => ['records' => 10000, 'config' => ['shardingEnabled' => false]],
        'indexed10k' => ['records' => 10000, 'config' => ['shardingEnabled' => false], 'fields' => ['g'], 'spatial' => ['loc']],
        'fields10k'  => ['records' => 10000, 'config' => ['shardingEnabled' => false], 'fields' => ['g', 'p']],
        'sharded10k' => ['records' => 10000, 'config' => ['shardSize' => 1000], 'fields' => ['g']],
        'buffer1k'   => ['records' => 1000,  'config' => ['shardingEnabled' => false, 'bufferEnabled' => true]],
        // Maintenance: 2000 records updated once (old lines to compact; no automatic compaction while built)
        'garbage10k'   => ['records' => 10000, 'config' => ['shardingEnabled' => false, 'autoCompactThreshold' => 5], 'supersede' => 'c1'],
        'garbageSh10k' => ['records' => 10000, 'config' => ['shardSize' => 1000, 'autoCompactThreshold' => 5], 'supersede' => 'c1'],
        // Shard scale matrix: shardSize 1000, a unique field u and a field g with 32 distinct values per shard
        // (below the default limit of 64 per shard); 100 slots per shard left free for the insert workload
        'matrix1'    => ['records' => 900, 'config' => ['shardSize' => 1000], 'fields' => ['u', 'g'], 'matrix' => 1],
        'matrix10'   => ['records' => 9900, 'config' => ['shardSize' => 1000], 'fields' => ['u', 'g'], 'matrix' => 10],
        'matrix100'  => ['records' => 99900, 'config' => ['shardSize' => 1000], 'fields' => ['u', 'g'], 'matrix' => 100],
        'matrix1000' => ['records' => 999900, 'config' => ['shardSize' => 1000], 'fields' => ['u', 'g'], 'matrix' => 1000],
    ];
}

function makeMatrixRecord(int $n, int $shards): array {
    return ['u' => 'u' . $n, 'g' => 'g' . ($n % max(1, 32 * $shards)), 'n' => $n];
}

function makeRecord(int $n): array {
    return [
        'n' => $n,
        'c' => 'c' . ($n % 5),
        'g' => 'g' . ($n % 100),
        'p' => ($n * 7919 % 10007) / 100,
        's' => 'text-' . str_repeat(chr(97 + $n % 26), 20 + $n % 30),
        'loc' => ['type' => 'Point', 'coordinates' => [(($n * 37) % 3600) / 10 - 179.95, (($n * 53) % 1700) / 10 - 84.95]],
    ];
}

/**
 * kind: warm (k timed operations after 3 untimed ones), cold (new noneDB + first operation),
 *       bulk (one timed operation after the index is loaded), conc (parallel processes)
 */
function workloads(): array {
    $key = function () { return mt_rand(0, 9999); };
    $box = function () {
        $x = mt_rand(-170, 160);
        $y = mt_rand(-80, 70);
        return [$x, $y, $x + 10, $y + 10];
    };
    $w = [];
    // Single operations, warm cache
    $w['find_key_warm'] = ['fixture' => 'plain10k', 'kind' => 'warm', 'k' => 2000,
        'op' => function ($db) use ($key) { $db->find(GATE_DB, ['key' => $key()]); }];
    $w['insert_one_100_warm'] = ['fixture' => 'plain100', 'kind' => 'warm', 'k' => 150,
        'op' => function ($db, $i) { $db->insert(GATE_DB, makeRecord(100000 + $i)); }];
    $w['insert_one_10k_warm'] = ['fixture' => 'plain10k', 'kind' => 'warm', 'k' => 40,
        'op' => function ($db, $i) { $db->insert(GATE_DB, makeRecord(100000 + $i)); }];
    $w['update_one_10k_warm'] = ['fixture' => 'plain10k', 'kind' => 'warm', 'k' => 40,
        'op' => function ($db, $i) use ($key) { $db->update(GATE_DB, [['key' => $key()], ['set' => ['p' => $i + 0.5]]]); }];
    $w['delete_one_10k_warm'] = ['fixture' => 'plain10k', 'kind' => 'warm', 'k' => 100,
        'op' => function ($db, $i) { $db->delete(GATE_DB, ['key' => ($i * 2477) % 10000]); }];
    $w['insert_one_indexed_warm'] = ['fixture' => 'indexed10k', 'kind' => 'warm', 'k' => 40,
        'op' => function ($db, $i) { $db->insert(GATE_DB, makeRecord(100000 + $i)); }];
    // Updates of an unindexed field next to two field indexes: 400 lines reach an index checkpoint (64 KiB)
    $w['update_indexed_10k_warm'] = ['fixture' => 'fields10k', 'kind' => 'warm', 'k' => 400,
        'op' => function ($db, $i) { $db->update(GATE_DB, [['key' => (($i + 10) * 2477) % 10000], ['set' => ['n2' => $i]]]); }];
    // A range on an indexed numeric field (about 5% of the records)
    $w['range_indexed_warm'] = ['fixture' => 'fields10k', 'kind' => 'warm', 'k' => 20,
        'op' => function ($db) { $x = mt_rand(0, 9500) / 100; $db->between(GATE_DB, 'p', $x, $x + 5); }];
    $w['find_indexed_eq_warm'] = ['fixture' => 'indexed10k', 'kind' => 'warm', 'k' => 60,
        'op' => function ($db) { $db->find(GATE_DB, ['g' => 'g' . mt_rand(0, 99)]); }];
    $w['count_filter_warm'] = ['fixture' => 'plain10k', 'kind' => 'warm', 'k' => 4,
        'op' => function ($db) { $db->count(GATE_DB, ['c' => 'c' . mt_rand(0, 4)]); }];
    $w['sort_limit_warm'] = ['fixture' => 'plain10k', 'kind' => 'warm', 'k' => 4,
        'op' => function ($db) { $db->query(GATE_DB)->where(['c' => 'c' . mt_rand(0, 4)])->sort('p', 'desc')->limit(10)->get(); }];
    $w['within_bbox_warm'] = ['fixture' => 'indexed10k', 'kind' => 'warm', 'k' => 200,
        'op' => function ($db) use ($box) { $b = $box(); $db->withinBBox(GATE_DB, 'loc', $b[0], $b[1], $b[2], $b[3]); }];
    $w['within_distance_warm'] = ['fixture' => 'indexed10k', 'kind' => 'warm', 'k' => 200,
        'op' => function ($db) { $db->withinDistance(GATE_DB, 'loc', mt_rand(-170, 170), mt_rand(-80, 80), 300000); }];
    $w['nearest_warm'] = ['fixture' => 'indexed10k', 'kind' => 'warm', 'k' => 120,
        'op' => function ($db) { $db->nearest(GATE_DB, 'loc', mt_rand(-170, 170), mt_rand(-80, 80), 10); }];
    $w['sharded_find_key_warm'] = ['fixture' => 'sharded10k', 'kind' => 'warm', 'k' => 2000,
        'op' => function ($db) use ($key) { $db->find(GATE_DB, ['key' => $key()]); }];
    $w['sharded_find_indexed_warm'] = ['fixture' => 'sharded10k', 'kind' => 'warm', 'k' => 60,
        'op' => function ($db) { $db->find(GATE_DB, ['g' => 'g' . mt_rand(0, 99)]); }];
    $w['sharded_insert_one_warm'] = ['fixture' => 'sharded10k', 'kind' => 'warm', 'k' => 100,
        'op' => function ($db, $i) { $db->insert(GATE_DB, makeRecord(100000 + $i)); }];
    // Store rules: a read of a missing database (the dbDir is checked every time), the first operation
    // after another process changed the epoch (caches dropped, layout and store checked again)
    $w['find_missing_db_warm'] = ['fixture' => 'plain10k', 'kind' => 'warm', 'k' => 400,
        'op' => function ($db) { $db->find('missing' . mt_rand(0, 9), 0); }];
    $w['find_key_after_epoch_change'] = ['fixture' => 'plain10k', 'kind' => 'warm', 'k' => 40,
        'op' => function ($db) use ($key) { newLockEpoch($GLOBALS['gateDir']); $db->find(GATE_DB, ['key' => $key()]); }];
    // Single operations, cold (new process, empty static caches)
    $w['find_key_cold'] = ['fixture' => 'plain10k', 'kind' => 'cold',
        'op' => function ($db) use ($key) { $db->find(GATE_DB, ['key' => $key()]); }];
    $w['insert_one_cold'] = ['fixture' => 'plain10k', 'kind' => 'cold',
        'op' => function ($db) { $db->insert(GATE_DB, makeRecord(100000)); }];
    $w['find_indexed_cold'] = ['fixture' => 'indexed10k', 'kind' => 'cold',
        'op' => function ($db) { $db->find(GATE_DB, ['g' => 'g' . mt_rand(0, 99)]); }];
    $w['within_bbox_cold'] = ['fixture' => 'indexed10k', 'kind' => 'cold',
        'op' => function ($db) use ($box) { $b = $box(); $db->withinBBox(GATE_DB, 'loc', $b[0], $b[1], $b[2], $b[3]); }];
    $w['sharded_find_indexed_cold'] = ['fixture' => 'sharded10k', 'kind' => 'cold',
        'op' => function ($db) { $db->find(GATE_DB, ['g' => 'g' . mt_rand(0, 99)]); }];
    // Bulk operations
    foreach ([1000 => '1k', 10000 => '10k', 100000 => '100k'] as $size => $label) {
        $w['bulk_insert_' . $label] = ['fixture' => 'empty', 'kind' => 'bulk',
            'prepare' => function () use ($size) {
                $rows = [];
                for ($n = 0; $n < $size; $n++) {
                    $rows[] = makeRecord($n);
                }
                return $rows;
            },
            'op' => function ($db, $rows) { $db->insert(GATE_DB, $rows); }];
    }
    $w['sharded_bulk_insert_10k'] = ['fixture' => 'emptySh', 'kind' => 'bulk',
        'prepare' => function () {
            $rows = [];
            for ($n = 0; $n < 10000; $n++) {
                $rows[] = makeRecord($n);
            }
            return $rows;
        },
        'op' => function ($db, $rows) { $db->insert(GATE_DB, $rows); }];
    $w['find_all_10k'] = ['fixture' => 'plain10k', 'kind' => 'warm', 'k' => 3,
        'op' => function ($db) { $db->find(GATE_DB, 0); }];
    $w['filter_unindexed_10k'] = ['fixture' => 'plain10k', 'kind' => 'warm', 'k' => 3,
        'op' => function ($db) { $db->find(GATE_DB, ['c' => 'c' . mt_rand(0, 4)]); }];
    $w['bulk_update_10k'] = ['fixture' => 'plain10k', 'kind' => 'bulk',
        'op' => function ($db) { $db->update(GATE_DB, [['c' => 'c1'], ['set' => ['flag' => true]]]); }];
    $w['bulk_delete_10k'] = ['fixture' => 'plain10k', 'kind' => 'bulk',
        'op' => function ($db) { $db->delete(GATE_DB, ['c' => 'c1']); }];
    $w['buffer_insert_flush'] = ['fixture' => 'buffer1k', 'kind' => 'bulk',
        'op' => function ($db) {
            for ($i = 0; $i < 1000; $i++) {
                $db->insert(GATE_DB, makeRecord(200000 + $i));
            }
            $db->flush(GATE_DB);
        }];
    // Maintenance (data file replacement, index builds)
    $w['compact_10k'] = ['fixture' => 'garbage10k', 'kind' => 'bulk',
        'op' => function ($db) { $db->compact(GATE_DB); }];
    $w['sharded_compact_10k'] = ['fixture' => 'garbageSh10k', 'kind' => 'bulk',
        'op' => function ($db) { $db->compact(GATE_DB); }];
    $w['delete_autocompact_10k'] = ['fixture' => 'plain10k', 'kind' => 'bulk', // 40%: crosses the 30% garbage threshold
        'op' => function ($db) { $db->delete(GATE_DB, ['c' => ['$in' => ['c1', 'c2']]]); }];
    $w['create_field_index_10k'] = ['fixture' => 'plain10k', 'kind' => 'bulk',
        'op' => function ($db) { $db->createFieldIndex(GATE_DB, 'g'); }];
    $w['rebuild_index_10k'] = ['fixture' => 'plain10k', 'kind' => 'bulk',
        'op' => function ($db) { $db->rebuildIndex(GATE_DB); }];
    // Concurrent processes on one database (unit = wall time from a common start)
    $w['conc_writers_4'] = ['fixture' => 'plain10k', 'kind' => 'conc', 'runs' => 30, 'roles' => ['w' => 4], 'k' => ['w' => 60]];
    $w['conc_writers_8'] = ['fixture' => 'plain10k', 'kind' => 'conc', 'runs' => 30, 'roles' => ['w' => 8], 'k' => ['w' => 60]];
    $w['conc_readers_4'] = ['fixture' => 'plain10k', 'kind' => 'conc', 'runs' => 30, 'roles' => ['r' => 4], 'k' => ['r' => 1500]];
    $w['conc_mixed_8'] = ['fixture' => 'plain10k', 'kind' => 'conc', 'runs' => 30, 'roles' => ['w' => 4, 'r' => 4], 'k' => ['w' => 60, 'r' => 1500]];

    // Shard scale matrix (T2-7: global field index of a high-cardinality field). Fixtures are used in place
    // (1M records at 1000 shards are too big to copy per run): lookups run before the inserts.
    foreach ([1, 10, 100, 1000] as $shards) {
        $fixture = 'matrix' . $shards;
        $u = function () use ($shards) { return 'u' . mt_rand(0, $shards * 1000 - 101); };
        $w["m{$shards}_lookup_hit_warm"] = ['fixture' => $fixture, 'kind' => 'warm', 'k' => 200, 'inPlace' => true,
            'op' => function ($db) use ($u) { $db->find(GATE_DB, ['u' => $u()]); }];
        $w["m{$shards}_lookup_miss_warm"] = ['fixture' => $fixture, 'kind' => 'warm', 'k' => 200, 'inPlace' => true,
            'op' => function ($db, $i) { $db->find(GATE_DB, ['u' => 'absent' . $i]); }];
        $w["m{$shards}_g_lookup_warm"] = ['fixture' => $fixture, 'kind' => 'warm', 'k' => 100, 'inPlace' => true,
            'op' => function ($db) use ($shards) { $db->find(GATE_DB, ['g' => 'g' . mt_rand(0, max(1, 32 * $shards) - 1), 'n' => -1]); }];
        $w["m{$shards}_lookup_hit_cold"] = ['fixture' => $fixture, 'kind' => 'cold', 'inPlace' => true,
            'op' => function ($db) use ($u) { $db->find(GATE_DB, ['u' => $u()]); }];
        $w["m{$shards}_lookup_miss_cold"] = ['fixture' => $fixture, 'kind' => 'cold', 'inPlace' => true,
            'op' => function ($db) { $db->find(GATE_DB, ['u' => 'absent']); }];
        $w["m{$shards}_insert_warm"] = ['fixture' => $fixture, 'kind' => 'warm', 'k' => 25, 'inPlace' => true,
            'op' => function ($db, $i) use ($shards) {
                static $next = 0;
                $db->insert(GATE_DB, ['u' => 'new' . getmypid() . '-' . ($next++), 'g' => 'g0', 'n' => -2]);
            }];
    }
    return $w;
}

function dbConfig(string $dir, string $fixture, array $extra): array {
    $spec = fixtureSpecs()[$fixture];
    return array_merge(['secretKey' => GATE_SECRET, 'dbDir' => $dir, 'autoCreateDB' => true, 'lockTimeout' => 60],
        $spec['config'], $extra);
}

// ======================================================================
// Child processes
// ======================================================================

function installErrorHandler(): void {
    set_error_handler(function ($no, $str, $file, $line) {
        if (!(error_reporting() & $no)) {
            return false; // suppressed with @ by noneDB itself
        }
        throw new ErrorException($str, 0, $no, $file, $line);
    });
}

function fixtureMain(array $args): int {
    [$lib, $fixture, $dir, $extraJson] = $args;
    installErrorHandler();
    require $lib;
    $spec = fixtureSpecs()[$fixture];
    $db = new noneDB(dbConfig($dir, $fixture, json_decode($extraJson, true)));
    $db->createDB(GATE_DB);
    for ($start = 0; $start < $spec['records']; $start += 1000) {
        $rows = [];
        for ($n = $start; $n < min($spec['records'], $start + 1000); $n++) {
            $rows[] = isset($spec['matrix']) ? makeMatrixRecord($n, $spec['matrix']) : makeRecord($n);
        }
        $result = $db->insert(GATE_DB, $rows);
        if (($result['n'] ?? 0) !== count($rows)) {
            fwrite(STDERR, 'fixture insert failed: ' . json_encode($result) . "\n");
            return 1;
        }
    }
    if (!empty($spec['config']['bufferEnabled'])) {
        $db->flush(GATE_DB);
    }
    if (isset($spec['supersede'])) {
        $db->update(GATE_DB, [['c' => $spec['supersede']], ['set' => ['flag' => true]]]);
    }
    if (isset($spec['matrix'])) {
        // also the 1-shard fixture is sharded (900 records < shardSize); the baseline names the method migrate()
        method_exists($db, 'convertToSharded') ? $db->convertToSharded(GATE_DB) : $db->migrate(GATE_DB);
    }
    foreach ($spec['fields'] ?? [] as $field) {
        $db->createFieldIndex(GATE_DB, $field);
    }
    foreach ($spec['spatial'] ?? [] as $field) {
        $db->createSpatialIndex(GATE_DB, $field);
    }
    if ($db->count(GATE_DB) !== $spec['records'] + 0) {
        fwrite(STDERR, "fixture count mismatch\n");
        return 1;
    }
    return 0;
}

/**
 * One measured run: prints {"unit": seconds, "ops": [seconds...], "mem": bytes}
 */
function childMain(array $args): int {
    [$lib, $name, $dir, $seed, $extraJson] = $args;
    $GLOBALS['gateDir'] = $dir;
    installErrorHandler();
    mt_srand((int)$seed);
    $w = workloads()[$name];
    $prepared = isset($w['prepare']) ? $w['prepare']() : null;
    require $lib;
    $config = dbConfig($dir, $w['fixture'], json_decode($extraJson, true));
    $ops = [];

    if ($w['kind'] === 'cold') {
        $base = memory_get_usage();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $t = hrtime(true);
        $db = new noneDB($config);
        $w['op']($db);
        $unit = (hrtime(true) - $t) / 1e9;
        $ops[] = $unit;
    } else {
        $db = new noneDB($config);
        $db->find(GATE_DB, ['key' => 0]); // load the index (and lock handle) before measuring
        if ($w['kind'] === 'warm') {
            // 3 untimed operations; --warm-up=N for steady-state measurements (one-time costs excluded)
            $warmUp = (int)(getenv('NONEDB_GATE_WARMUP') ?: 3);
            for ($i = 0; $i < $warmUp; $i++) {
                $w['op']($db, -1 - $i);
            }
        }
        $base = memory_get_usage();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        if ($w['kind'] === 'warm') {
            $unit = 0.0;
            for ($i = 0; $i < $w['k']; $i++) {
                $t = hrtime(true);
                $w['op']($db, $i);
                $d = (hrtime(true) - $t) / 1e9;
                $ops[] = $d;
                $unit += $d;
            }
        } else {
            $t = hrtime(true);
            $w['op']($db, $prepared);
            $unit = (hrtime(true) - $t) / 1e9;
            $ops[] = $unit;
        }
    }
    $mem = memory_get_peak_usage() - $base;
    // Keep at most 200 latencies per run (every m-th): enough for a pooled p95, cheap to bootstrap
    if (count($ops) > 200) {
        $step = (int)ceil(count($ops) / 200);
        $kept = [];
        for ($i = 0; $i < count($ops); $i += $step) {
            $kept[] = $ops[$i];
        }
        $ops = $kept;
    }
    echo json_encode(['unit' => $unit, 'ops' => $ops, 'mem' => $mem]), "\n";
    return 0;
}

function concurrentChildMain(array $args): int {
    [$lib, $name, $dir, $role, $k, $startAt, $seed, $extraJson] = $args;
    installErrorHandler();
    mt_srand((int)$seed);
    require $lib;
    $w = workloads()[$name];
    $db = new noneDB(dbConfig($dir, $w['fixture'], json_decode($extraJson, true)));
    $db->find(GATE_DB, ['key' => 0]);
    $late = microtime(true) > (float)$startAt;
    while (microtime(true) < (float)$startAt) {
        usleep(200);
    }
    for ($i = 0; $i < (int)$k; $i++) {
        if ($role === 'w') {
            $db->insert(GATE_DB, makeRecord(300000 + (int)$seed * 1000 + $i));
        } else {
            $db->find(GATE_DB, ['key' => mt_rand(0, 9999)]);
        }
    }
    echo json_encode(['end' => microtime(true), 'late' => $late]), "\n";
    return 0;
}

// ======================================================================
// Parent: runs
// ======================================================================

function parseOptions(array $args): array {
    $o = [];
    foreach ($args as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
            $o[$m[1]] = $m[2] ?? true;
        }
    }
    return $o;
}

function spawn(array $cmd): array {
    // Same limits for both libraries; bulk workloads need more than the default 128M
    array_splice($cmd, 1, 0, ['-d', 'memory_limit=-1']);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('proc_open failed');
    }
    return [$proc, $pipes];
}

function collect(array $spawned, string $what): string {
    [$proc, $pipes] = $spawned;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);
    if ($status !== 0 || trim($err) !== '') {
        throw new RuntimeException("$what failed (exit $status): " . trim($err . "\n" . $out));
    }
    return $out;
}

function runChild(array $cmd, string $what): array {
    $out = collect(spawn($cmd), $what);
    $lines = array_values(array_filter(explode("\n", trim($out)), 'strlen'));
    $data = json_decode((string)end($lines), true);
    if (!is_array($data)) {
        throw new RuntimeException("$what: unexpected output: $out");
    }
    return $data;
}

/**
 * Give the fixture database's lock state record a new epoch, as another process replacing a data file
 * would (both storage layouts: "{gen}:{epoch}[ flags]" padded in place)
 */
function newLockEpoch(string $dir): void {
    foreach (array_merge(glob($dir . '/*.ndb4/LOCK') ?: [], glob($dir . '/*.nonedb.lock') ?: []) as $lock) {
        $fp = fopen($lock, 'c+');
        flock($fp, LOCK_EX);
        $raw = (string)stream_get_contents($fp);
        $record = preg_replace('/^(\d+):[0-9a-f]{8}/', '$1:' . bin2hex(random_bytes(4)), $raw);
        fseek($fp, 0);
        fwrite($fp, $record);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function removeTree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function copyTree(string $from, string $to): void {
    mkdir($to, 0700, true);
    foreach (scandir($from) as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir($from . '/' . $f) ? copyTree($from . '/' . $f, $to . '/' . $f) : copy($from . '/' . $f, $to . '/' . $f);
        }
    }
}

function env(): array {
    $fs = trim((string)@shell_exec('df -P ' . escapeshellarg(sys_get_temp_dir()) . ' 2>/dev/null | tail -1'));
    return [
        'date' => date('c'),
        'php' => PHP_VERSION,
        'os' => php_uname(),
        'tmpFs' => $fs,
        'load' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
        'opcache' => (bool)ini_get('opcache.enable_cli'),
        'xdebug' => extension_loaded('xdebug'),
    ];
}

function runMain(array $o): int {
    foreach (['baseline', 'candidate', 'out'] as $required) {
        if (!isset($o[$required]) || !is_string($o[$required])) {
            fwrite(STDERR, "--$required is required\n");
            return 2;
        }
    }
    $libs = ['A' => realpath($o['baseline']), 'B' => realpath($o['candidate'])];
    if (!$libs['A'] || !$libs['B']) {
        fwrite(STDERR, "library not found\n");
        return 2;
    }
    $extra = ['A' => $o['baseline-config'] ?? '{}', 'B' => $o['candidate-config'] ?? '{}'];
    foreach ($extra as $json) {
        if (!is_array(json_decode($json, true))) {
            fwrite(STDERR, "invalid --*-config JSON\n");
            return 2;
        }
    }
    $quick = isset($o['quick']);
    if (isset($o['warm-up'])) {
        putenv('NONEDB_GATE_WARMUP=' . max(0, (int)$o['warm-up'])); // inherited by every child
    }
    $defaultRuns = $quick ? 6 : max(2, (int)($o['runs'] ?? 40));
    $work = rtrim($o['work'] ?? sys_get_temp_dir() . '/nonedb-perf-gate-' . bin2hex(random_bytes(4)), '/');
    @mkdir($work, 0700, true);
    $aa = $libs['A'] === $libs['B'] && $extra['A'] === $extra['B'];

    $result = [
        'meta' => env() + [
            'mode' => $aa ? 'A/A' : 'A/B',
            'quick' => $quick,
            'warmUp' => (int)(getenv('NONEDB_GATE_WARMUP') ?: 3),
            'baseline' => ['path' => $libs['A'], 'sha256' => hash_file('sha256', $libs['A']), 'config' => json_decode($extra['A'], true)],
            'candidate' => ['path' => $libs['B'], 'sha256' => hash_file('sha256', $libs['B']), 'config' => json_decode($extra['B'], true)],
        ],
        'workloads' => [],
    ];
    $started = microtime(true);

    $fixtures = [];
    $fixtureDir = function (string $variant, string $fixture) use (&$fixtures, $work, $libs, $extra) {
        $key = $variant . '/' . $fixture;
        if (!isset($fixtures[$key])) {
            $dir = "$work/fixtures/$variant/$fixture";
            removeTree($dir);
            mkdir($dir, 0700, true);
            fwrite(STDERR, "  building fixture $key\n");
            runChildRaw([PHP_BINARY, __FILE__, 'fixture', $libs[$variant], $fixture, $dir . '/', $extra[$variant]], "fixture $key");
            $fixtures[$key] = $dir;
        }
        return $fixtures[$key];
    };

    $runCounter = 0;
    foreach (workloads() as $name => $w) {
        if (isset($o['only']) && !preg_match('/' . $o['only'] . '/', $name)) {
            continue;
        }
        $runs = $quick ? $defaultRuns : max($defaultRuns, 0);
        if (!$quick && isset($w['runs'])) {
            $runs = min($runs, $w['runs']);
        }
        fwrite(STDERR, sprintf("[%s] %s (%d runs per variant)\n", date('H:i:s'), $name, $runs));
        $samples = ['A' => [], 'B' => []];
        for ($r = 0; $r < $runs; $r++) {
            $order = $r % 2 === 0 ? ['A', 'B'] : ['B', 'A'];
            foreach ($order as $variant) {
                $template = $fixtureDir($variant, $w['fixture']);
                if (!empty($w['inPlace'])) {
                    $runDir = $template; // big fixtures: the runs share it (each variant has its own)
                } else {
                    $runDir = "$work/run-" . (++$runCounter);
                    copyTree($template, $runDir);
                }
                try {
                    $seed = 1000; // the same operations in every run: only the system varies
                    if ($w['kind'] === 'conc') {
                        $samples[$variant][] = concurrentRun($libs[$variant], $name, $w, $runDir . '/', $seed, $extra[$variant]);
                    } else {
                        $samples[$variant][] = runChild([PHP_BINARY, __FILE__, 'child', $libs[$variant], $name, $runDir . '/', (string)$seed, $extra[$variant]], "$name/$variant");
                    }
                } finally {
                    if (empty($w['inPlace'])) {
                        removeTree($runDir);
                    }
                }
            }
        }
        $result['workloads'][$name] = ['kind' => $w['kind'], 'fixture' => $w['fixture'], 'runs' => $runs]
            + analyse($samples, $w['kind'], $aa, $quick);
        $s = $result['workloads'][$name];
        fwrite(STDERR, sprintf("    median ratio %.3f [%.3f, %.3f] p=%.4f  status=%s\n",
            $s['median']['ratio'], $s['median']['ci'][0], $s['median']['ci'][1], $s['median']['p'], $s['status']));
        // Partial results survive an interrupted run
        file_put_contents($o['out'] . '.partial', json_encode($result, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n");
    }
    @unlink($o['out'] . '.partial');
    removeTree("$work/fixtures");
    @rmdir($work);
    $result['meta']['seconds'] = round(microtime(true) - $started, 1);
    $result['meta']['loadEnd'] = function_exists('sys_getloadavg') ? sys_getloadavg() : null;

    file_put_contents($o['out'], json_encode($result, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n");
    $md = markdownReport($result);
    if (isset($o['md']) && is_string($o['md'])) {
        file_put_contents($o['md'], $md);
    }
    echo $md;
    return 0;
}

function runChildRaw(array $cmd, string $what): void {
    collect(spawn($cmd), $what);
}

function concurrentRun(string $lib, string $name, array $w, string $dir, int $seed, string $extra): array {
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $startAt = microtime(true) + 1.0 + $attempt;
        $children = [];
        $i = 0;
        foreach ($w['roles'] as $role => $count) {
            for ($c = 0; $c < $count; $c++) {
                $children[] = spawn([PHP_BINARY, __FILE__, 'conc', $lib, $name, $dir, $role, (string)$w['k'][$role],
                    sprintf('%.6f', $startAt), (string)($seed * 100 + $i++), $extra]);
            }
        }
        $end = 0.0;
        $late = false;
        foreach ($children as $child) {
            $out = collect($child, "$name child");
            $data = json_decode(trim($out), true);
            $end = max($end, (float)$data['end']);
            $late = $late || $data['late'];
        }
        if (!$late) {
            return ['unit' => $end - $startAt, 'ops' => [], 'mem' => 0];
        }
    }
    throw new RuntimeException("$name: children were not ready at the common start time");
}

// ======================================================================
// Statistics
// ======================================================================

function quantile(array $sorted, float $p): float {
    $n = count($sorted);
    if ($n === 0) {
        return NAN;
    }
    $h = ($n - 1) * $p;
    $lo = (int)floor($h);
    $hi = min($n - 1, $lo + 1);
    return $sorted[$lo] + ($h - $lo) * ($sorted[$hi] - $sorted[$lo]);
}

function median(array $values): float {
    sort($values);
    return quantile($values, 0.5);
}

function p95(array $values): float {
    sort($values);
    return quantile($values, 0.95);
}

function erfcApprox(float $x): float {
    // Numerical Recipes erfcc, fractional error < 1.2e-7
    $z = abs($x);
    $t = 1.0 / (1.0 + 0.5 * $z);
    $r = $t * exp(-$z * $z - 1.26551223 + $t * (1.00002368 + $t * (0.37409196 + $t * (0.09678418 + $t * (-0.18628806
        + $t * (0.27886807 + $t * (-1.13520398 + $t * (1.48851587 + $t * (-0.82215223 + $t * 0.17087277)))))))));
    return $x >= 0 ? $r : 2.0 - $r;
}

function normalCdf(float $z): float {
    return 0.5 * erfcApprox(-$z / M_SQRT2);
}

/**
 * One-sided Mann-Whitney U test, H1: values of $b tend to be larger than values of $a
 * (normal approximation with tie and continuity correction)
 */
function mannWhitneyGreater(array $a, array $b): float {
    $nA = count($a);
    $nB = count($b);
    $n = $nA + $nB;
    if ($nA < 2 || $nB < 2) {
        return 1.0;
    }
    $all = [];
    foreach ($a as $v) {
        $all[] = [$v, 0];
    }
    foreach ($b as $v) {
        $all[] = [$v, 1];
    }
    usort($all, function ($x, $y) { return $x[0] <=> $y[0]; });
    $rankB = 0.0;
    $ties = 0.0;
    for ($i = 0; $i < $n;) {
        $j = $i;
        while ($j + 1 < $n && $all[$j + 1][0] == $all[$i][0]) {
            $j++;
        }
        $rank = ($i + $j) / 2 + 1;
        $t = $j - $i + 1;
        $ties += $t * $t * $t - $t;
        for ($k = $i; $k <= $j; $k++) {
            if ($all[$k][1] === 1) {
                $rankB += $rank;
            }
        }
        $i = $j + 1;
    }
    $u = $rankB - $nB * ($nB + 1) / 2;
    $var = $nA * $nB / 12 * (($n + 1) - $ties / ($n * ($n - 1)));
    if ($var <= 0) {
        return 1.0;
    }
    return 1.0 - normalCdf(($u - $nA * $nB / 2 - 0.5) / sqrt($var));
}

function hodgesLehmann(array $a, array $b): float {
    $d = [];
    foreach ($a as $x) {
        foreach ($b as $y) {
            $d[] = $y - $x;
        }
    }
    return median($d);
}

/**
 * Percentile bootstrap CI (99%) of stat(B) / stat(A); groups are resampled independently.
 * $a and $b hold whatever $stat takes (samples, or runs of latencies for a cluster bootstrap).
 */
function bootstrapRatio(array $a, array $b, callable $stat, int $iterations, int $seed): array {
    mt_srand($seed);
    $nA = count($a);
    $nB = count($b);
    $ratios = [];
    for ($it = 0; $it < $iterations; $it++) {
        $ra = [];
        for ($i = 0; $i < $nA; $i++) {
            $ra[] = $a[mt_rand(0, $nA - 1)];
        }
        $rb = [];
        for ($i = 0; $i < $nB; $i++) {
            $rb[] = $b[mt_rand(0, $nB - 1)];
        }
        $sa = $stat($ra);
        $ratios[] = $sa > 0 ? $stat($rb) / $sa : INF;
    }
    sort($ratios);
    return [quantile($ratios, 0.005), quantile($ratios, 0.995)];
}

function analyse(array $samples, string $kind, bool $aa, bool $quick): array {
    $unitA = array_column($samples['A'], 'unit');
    $unitB = array_column($samples['B'], 'unit');
    $medA = median($unitA);
    $medB = median($unitB);
    $ratio = $medA > 0 ? $medB / $medA : INF;
    $ci = bootstrapRatio($unitA, $unitB, 'median', 2000, 7);
    $pSlower = mannWhitneyGreater($unitA, $unitB);
    $pFaster = mannWhitneyGreater($unitB, $unitA);
    $out = [
        'median' => [
            'A' => $medA, 'B' => $medB, 'ratio' => $ratio, 'ci' => $ci, 'p' => $pSlower, 'pFaster' => $pFaster,
            'hodgesLehmann' => $medA > 0 ? hodgesLehmann($unitA, $unitB) / $medA : NAN,
            'minA' => min($unitA), 'minB' => min($unitB),
        ],
    ];

    // p95 of single-operation latencies, pooled over runs (cluster bootstrap over runs)
    $opsA = array_column($samples['A'], 'ops');
    $opsB = array_column($samples['B'], 'ops');
    $pooledA = $opsA ? array_merge(...$opsA) : [];
    $pooledB = $opsB ? array_merge(...$opsB) : [];
    $p95Decides = $kind === 'warm' && count($pooledA) >= 100 && count($pooledB) >= 100;
    if ($p95Decides) {
        $pool = function (array $runs) { return p95(array_merge(...$runs)); };
        $p95A = p95($pooledA);
        $out['p95'] = ['A' => $p95A, 'B' => p95($pooledB), 'ratio' => $p95A > 0 ? p95($pooledB) / $p95A : INF,
            'ci' => bootstrapRatio($opsA, $opsB, $pool, 1000, 11), 'samples' => [count($pooledA), count($pooledB)]];
    }

    // Peak memory of the workload (deterministic: median of the runs)
    $memA = median(array_column($samples['A'], 'mem'));
    $memB = median(array_column($samples['B'], 'mem'));
    $out['memory'] = ['A' => $memA, 'B' => $memB, 'ratio' => $memA > 0 ? $memB / $memA : ($memB > 0 ? INF : 1.0)];

    // Decision
    $significant = $pSlower < 0.01 && $ci[0] > 1.0;
    $regressions = [];
    if ($kind === 'conc') {
        // unit is wall time: throughput ratio = 1 / time ratio; regression when its CI is below 0.95
        if ($significant && $ci[0] > 1 / 0.95) {
            $regressions[] = 'throughput';
        }
    } elseif ($significant && $ratio > 1.05) {
        $regressions[] = 'median';
    }
    if (isset($out['p95']) && $out['p95']['ci'][0] > 1.0 && $out['p95']['ratio'] > 1.05) {
        $regressions[] = 'p95';
    }
    if ($kind !== 'conc' && $out['memory']['ratio'] > 1.05 && $memB - $memA > 64 * 1024) {
        $regressions[] = 'memory';
    }
    if ($regressions) {
        $status = 'regression';
    } elseif ($significant) {
        $status = 'finding';
    } elseif ($pFaster < 0.01 && $ci[1] < 1.0) {
        $status = 'improvement';
    } else {
        $status = 'neutral';
    }
    if ($quick) {
        $status .= ' (quick: not a decision)';
    }
    $out['status'] = $status;
    $out['regressions'] = $regressions;
    if ($aa) {
        $width = max(1 - $ci[0], $ci[1] - 1);
        $out['noise'] = $width;
        $out['stable'] = $width <= 0.02;
    }
    return $out;
}

// ======================================================================
// Reports
// ======================================================================

function fmtCi(array $ci): string {
    return sprintf('[%.3f, %.3f]', $ci[0], $ci[1]);
}

function markdownReport(array $r): string {
    $m = $r['meta'];
    $md = "# noneDB performance gate ({$m['mode']})\n\n";
    $md .= "- date: {$m['date']}, PHP {$m['php']}, " . ($m['quick'] ? 'QUICK (no decisions)' : 'full') . ", warm-up " . ($m['warmUp'] ?? 3) . ", {$m['seconds']} s\n";
    $md .= "- os: {$m['os']}\n- tmp fs: {$m['tmpFs']}\n";
    $md .= '- load at start / end: ' . json_encode($m['load']) . ' / ' . json_encode($m['loadEnd']) . "\n";
    $md .= "- baseline: {$m['baseline']['path']} (sha256 " . substr($m['baseline']['sha256'], 0, 12) . ') config ' . json_encode($m['baseline']['config']) . "\n";
    $md .= "- candidate: {$m['candidate']['path']} (sha256 " . substr($m['candidate']['sha256'], 0, 12) . ') config ' . json_encode($m['candidate']['config']) . "\n\n";
    $md .= "Ratio = candidate / baseline (> 1 slower). CI = 99% bootstrap; p = one-sided Mann-Whitney (candidate slower).\n\n";
    $md .= "| workload | runs | median A (ms) | median B (ms) | ratio [99% CI] | p | HL shift | p95 ratio [99% CI] | memory ratio | status |\n";
    $md .= "|---|---|---|---|---|---|---|---|---|---|\n";
    foreach ($r['workloads'] as $name => $s) {
        $p95 = isset($s['p95']) ? sprintf('%.3f %s', $s['p95']['ratio'], fmtCi($s['p95']['ci'])) : '-';
        $status = $s['status'] . ($s['regressions'] ? ' (' . implode(', ', $s['regressions']) . ')' : '');
        if (isset($s['stable'])) {
            $status .= sprintf(' noise ±%.1f%%%s', $s['noise'] * 100, $s['stable'] ? '' : ' UNSTABLE');
        }
        $md .= sprintf("| %s | %d | %.3f | %.3f | %.3f %s | %.4f | %+.1f%% | %s | %.3f | %s |\n",
            $name, $s['runs'], $s['median']['A'] * 1000, $s['median']['B'] * 1000, $s['median']['ratio'], fmtCi($s['median']['ci']),
            $s['median']['p'], $s['median']['hodgesLehmann'] * 100, $p95, $s['memory']['ratio'], $status);
    }
    return $md;
}

/**
 * Absolute budgets for the fixed costs of the fail-closed store (user's decision, 2026-10-28): seconds a
 * workload may add per operation before its time counts as a regression. Every cold workload (a new instance and its first operation) has the cold-open budget.
 */
function timeBudget(string $name, array $workload): ?float {
    $budgets = ['find_missing_db_warm' => 0.0001, 'find_key_after_epoch_change' => 0.0001];
    if (isset($budgets[$name])) {
        return $budgets[$name];
    }
    return ($workload['kind'] ?? null) === 'cold' ? 0.00025 : null;
}

/** Median seconds a run of a workload adds per operation (warm: a run is k operations) */
function addedPerOperation(array $result, array $workload): float {
    $k = ($workload['kind'] ?? null) === 'warm' ? (int)$workload['k'] : 1;
    return ($result['median']['B'] - $result['median']['A']) / max(1, $k);
}

function verifyMain(array $args): int {
    $o = parseOptions($args);
    $files = array_values(array_filter($args, function ($a) { return strncmp($a, '--', 2) !== 0; }));
    if (count($files) !== 2) {
        fwrite(STDERR, "verify needs two run files\n");
        return 2;
    }
    $runs = [];
    foreach ($files as $f) {
        $runs[] = json_decode((string)file_get_contents($f), true);
        if (!is_array(end($runs))) {
            fwrite(STDERR, "cannot read $f\n");
            return 2;
        }
    }
    if ($runs[0]['meta']['candidate']['sha256'] !== $runs[1]['meta']['candidate']['sha256']
        || $runs[0]['meta']['baseline']['sha256'] !== $runs[1]['meta']['baseline']['sha256']) {
        fwrite(STDERR, "the two runs compare different libraries\n");
        return 2;
    }
    $md = "# noneDB performance gate verdict\n\n- first: {$files[0]} ({$runs[0]['meta']['date']})\n- second: {$files[1]} ({$runs[1]['meta']['date']})\n\n";
    $md .= "| workload | first | second | verdict |\n|---|---|---|---|\n";
    $verified = 0;
    foreach ($runs[0]['workloads'] as $name => $first) {
        $second = $runs[1]['workloads'][$name] ?? null;
        if ($second === null) {
            $md .= "| $name | {$first['status']} | (missing) | not compared |\n";
            continue;
        }
        $both = array_values(array_intersect($first['regressions'], $second['regressions']));
        $definition = workloads()[$name] ?? [];
        $budget = timeBudget($name, $definition);
        $timeOnly = array_values(array_diff($both, ['median', 'p95']));
        if ($both && $budget !== null && $timeOnly === []
            && max(addedPerOperation($first, $definition), addedPerOperation($second, $definition)) <= $budget) {
            // The time regression is a fixed cost within its absolute budget (both runs)
            $verdict = sprintf('within budget (+%.3f ms ≤ %.2f ms per operation)', 1000 * max(addedPerOperation($first, $definition),
                addedPerOperation($second, $definition)), 1000 * $budget);
        } elseif ($both) {
            $verdict = 'VERIFIED REGRESSION (' . implode(', ', $both) . ')';
            $verified++;
        } elseif (in_array($first['status'], ['finding', 'regression'], true) && in_array($second['status'], ['finding', 'regression'], true)) {
            $verdict = 'finding to investigate';
        } elseif (strpos($first['status'], 'improvement') === 0 && strpos($second['status'], 'improvement') === 0) {
            $verdict = 'improvement';
        } else {
            $verdict = 'no verified change';
        }
        $md .= sprintf("| %s | %s %.3f | %s %.3f | %s |\n", $name, $first['status'], $first['median']['ratio'],
            $second['status'], $second['median']['ratio'], $verdict);
    }
    $md .= "\nVerified regressions: $verified\n";
    if (isset($o['md']) && is_string($o['md'])) {
        file_put_contents($o['md'], $md);
    }
    echo $md;
    return $verified > 0 ? 1 : 0;
}
