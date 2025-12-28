<?php
/**
 * noneDB vs SleekDB Comprehensive Benchmark
 * Tests all operations from 100 to 100K records
 */

require_once __DIR__ . '/../noneDB.php';
require_once __DIR__ . '/../vendor/autoload.php';

use SleekDB\Store;

ini_set('memory_limit', '-1');
set_time_limit(0);

// Test sizes
$sizes = [100, 1000, 10000, 50000, 100000];

// Colors
function green($t) { return "\033[32m{$t}\033[0m"; }
function red($t) { return "\033[31m{$t}\033[0m"; }
function yellow($t) { return "\033[33m{$t}\033[0m"; }
function blue($t) { return "\033[34m{$t}\033[0m"; }
function cyan($t) { return "\033[36m{$t}\033[0m"; }
function bold($t) { return "\033[1m{$t}\033[0m"; }

// Format time
function formatTime($ms) {
    if ($ms < 1) return "<1ms";
    if ($ms >= 1000) return round($ms / 1000, 2) . "s";
    return round($ms) . "ms";
}

// Generate test record
function generateRecord($i) {
    $cities = ['Istanbul', 'Ankara', 'Izmir', 'Bursa', 'Antalya'];
    $depts = ['IT', 'HR', 'Sales', 'Marketing', 'Finance'];
    return [
        "name" => "User" . $i,
        "email" => "user{$i}@test.com",
        "age" => 20 + ($i % 50),
        "salary" => 5000 + ($i % 10000),
        "city" => $cities[$i % 5],
        "department" => $depts[$i % 5],
        "active" => ($i % 3 !== 0)
    ];
}

// Winner indicator
function winner($nonedb, $sleekdb) {
    if ($nonedb < $sleekdb * 0.9) return green("noneDB ✓");
    if ($sleekdb < $nonedb * 0.9) return red("SleekDB ✓");
    return yellow("~tie");
}

// Ratio
function ratio($nonedb, $sleekdb) {
    if ($sleekdb == 0) return "∞";
    $r = $sleekdb / max($nonedb, 0.1);
    if ($r >= 1) return green(round($r, 1) . "x faster");
    return red(round(1/$r, 1) . "x slower");
}

echo blue("╔══════════════════════════════════════════════════════════════════════════╗\n");
echo blue("║           noneDB v3.0 vs SleekDB Comprehensive Benchmark                 ║\n");
echo blue("╚══════════════════════════════════════════════════════════════════════════╝\n\n");

echo "PHP Version: " . PHP_VERSION . "\n";
echo "noneDB: v3.0.0 (JSONL + Static Cache + Batch Read)\n";
echo "SleekDB: v2.x\n\n";

$results = [];

foreach ($sizes as $size) {
    echo yellow("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");
    echo yellow("  Testing with " . number_format($size) . " records\n");
    echo yellow("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n");

    // Cleanup
    $nonedbName = "benchmark_nonedb_" . $size;
    $sleekdbDir = __DIR__ . "/sleekdb_benchmark_" . $size;

    // Clean noneDB
    $files = glob(__DIR__ . '/../db/*benchmark_nonedb_' . $size . '*');
    foreach ($files as $f) @unlink($f);
    \noneDB::clearStaticCache();
    clearstatcache(true);

    // Clean SleekDB
    if (is_dir($sleekdbDir)) {
        $files = glob($sleekdbDir . '/*');
        foreach ($files as $f) {
            if (is_dir($f)) {
                $subfiles = glob($f . '/*');
                foreach ($subfiles as $sf) @unlink($sf);
                @rmdir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($sleekdbDir);
    }

    // Generate data
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        $data[] = generateRecord($i);
    }

    $nonedb = new noneDB();

    // ===== BULK INSERT =====
    echo cyan("  Bulk Insert ($size records):\n");

    // noneDB
    $start = microtime(true);
    $nonedb->insert($nonedbName, $data);
    $nonedbInsert = (microtime(true) - $start) * 1000;

    // SleekDB
    @mkdir($sleekdbDir, 0777, true);
    $sleekStore = new Store("users", $sleekdbDir, ['timeout' => false]);
    $start = microtime(true);
    $sleekStore->insertMany($data);
    $sleekdbInsert = (microtime(true) - $start) * 1000;

    echo "    noneDB:  " . green(formatTime($nonedbInsert)) . "\n";
    echo "    SleekDB: " . formatTime($sleekdbInsert) . "\n";
    echo "    Result:  " . ratio($nonedbInsert, $sleekdbInsert) . "\n\n";

    $results[$size]['insert'] = ['nonedb' => $nonedbInsert, 'sleekdb' => $sleekdbInsert];

    // Clear caches for fair read tests
    \noneDB::clearStaticCache();
    clearstatcache(true);
    $sleekStore = new Store("users", $sleekdbDir, ['timeout' => false]);

    // ===== FIND ALL =====
    echo cyan("  Find All:\n");

    $start = microtime(true);
    $nonedb->find($nonedbName, 0);
    $nonedbFindAll = (microtime(true) - $start) * 1000;

    $start = microtime(true);
    $sleekStore->findAll();
    $sleekdbFindAll = (microtime(true) - $start) * 1000;

    echo "    noneDB:  " . green(formatTime($nonedbFindAll)) . "\n";
    echo "    SleekDB: " . formatTime($sleekdbFindAll) . "\n";
    echo "    Result:  " . ratio($nonedbFindAll, $sleekdbFindAll) . "\n\n";

    $results[$size]['find_all'] = ['nonedb' => $nonedbFindAll, 'sleekdb' => $sleekdbFindAll];

    // ===== FIND BY ID/KEY =====
    echo cyan("  Find by Key (single record):\n");

    $testKey = (int)($size / 2);

    // Clear cache for cold read
    \noneDB::clearStaticCache();
    clearstatcache(true);

    $start = microtime(true);
    $nonedb->find($nonedbName, ['key' => $testKey]);
    $nonedbFindKey = (microtime(true) - $start) * 1000;

    $sleekStore = new Store("users", $sleekdbDir, ['timeout' => false]);
    $start = microtime(true);
    $sleekStore->findById($testKey + 1); // SleekDB uses 1-based IDs
    $sleekdbFindKey = (microtime(true) - $start) * 1000;

    echo "    noneDB:  " . green(formatTime($nonedbFindKey)) . "\n";
    echo "    SleekDB: " . formatTime($sleekdbFindKey) . "\n";
    echo "    Result:  " . ratio($nonedbFindKey, $sleekdbFindKey) . "\n\n";

    $results[$size]['find_key'] = ['nonedb' => $nonedbFindKey, 'sleekdb' => $sleekdbFindKey];

    // ===== FIND WITH FILTER =====
    echo cyan("  Find with Filter (city = 'Ankara'):\n");

    $start = microtime(true);
    $nonedb->find($nonedbName, ['city' => 'Ankara']);
    $nonedbFilter = (microtime(true) - $start) * 1000;

    $start = microtime(true);
    $sleekStore->findBy(['city', '=', 'Ankara']);
    $sleekdbFilter = (microtime(true) - $start) * 1000;

    echo "    noneDB:  " . green(formatTime($nonedbFilter)) . "\n";
    echo "    SleekDB: " . formatTime($sleekdbFilter) . "\n";
    echo "    Result:  " . ratio($nonedbFilter, $sleekdbFilter) . "\n\n";

    $results[$size]['filter'] = ['nonedb' => $nonedbFilter, 'sleekdb' => $sleekdbFilter];

    // ===== COUNT =====
    echo cyan("  Count:\n");

    $start = microtime(true);
    $nonedb->count($nonedbName);
    $nonedbCount = (microtime(true) - $start) * 1000;

    $start = microtime(true);
    $sleekStore->count();
    $sleekdbCount = (microtime(true) - $start) * 1000;

    echo "    noneDB:  " . green(formatTime($nonedbCount)) . "\n";
    echo "    SleekDB: " . formatTime($sleekdbCount) . "\n";
    echo "    Result:  " . ratio($nonedbCount, $sleekdbCount) . "\n\n";

    $results[$size]['count'] = ['nonedb' => $nonedbCount, 'sleekdb' => $sleekdbCount];

    // ===== UPDATE =====
    echo cyan("  Update (set region for city='Istanbul'):\n");

    $start = microtime(true);
    $nonedb->update($nonedbName, [
        ['city' => 'Istanbul'],
        ['set' => ['region' => 'Marmara']]
    ]);
    $nonedbUpdate = (microtime(true) - $start) * 1000;

    // SleekDB: Find matching records then update each
    $start = microtime(true);
    $matching = $sleekStore->findBy(['city', '=', 'Istanbul']);
    foreach ($matching as $record) {
        $sleekStore->updateById($record['_id'], ['region' => 'Marmara']);
    }
    $sleekdbUpdate = (microtime(true) - $start) * 1000;

    echo "    noneDB:  " . green(formatTime($nonedbUpdate)) . "\n";
    echo "    SleekDB: " . formatTime($sleekdbUpdate) . "\n";
    echo "    Result:  " . ratio($nonedbUpdate, $sleekdbUpdate) . "\n\n";

    $results[$size]['update'] = ['nonedb' => $nonedbUpdate, 'sleekdb' => $sleekdbUpdate];

    // ===== DELETE =====
    echo cyan("  Delete (department = 'HR'):\n");

    $start = microtime(true);
    $nonedb->delete($nonedbName, ['department' => 'HR']);
    $nonedbDelete = (microtime(true) - $start) * 1000;

    $start = microtime(true);
    $sleekStore->deleteBy(['department', '=', 'HR']);
    $sleekdbDelete = (microtime(true) - $start) * 1000;

    echo "    noneDB:  " . green(formatTime($nonedbDelete)) . "\n";
    echo "    SleekDB: " . formatTime($sleekdbDelete) . "\n";
    echo "    Result:  " . ratio($nonedbDelete, $sleekdbDelete) . "\n\n";

    $results[$size]['delete'] = ['nonedb' => $nonedbDelete, 'sleekdb' => $sleekdbDelete];

    // ===== COMPLEX QUERY =====
    echo cyan("  Complex Query (where + sort + limit):\n");

    $start = microtime(true);
    $nonedb->query($nonedbName)
        ->where(['active' => true])
        ->whereIn('city', ['Istanbul', 'Ankara'])
        ->between('age', 25, 40)
        ->sort('salary', 'desc')
        ->limit(50)
        ->get();
    $nonedbComplex = (microtime(true) - $start) * 1000;

    $start = microtime(true);
    $sleekStore->createQueryBuilder()
        ->where(['active', '=', true])
        ->where(['city', 'IN', ['Istanbul', 'Ankara']])
        ->where([['age', '>=', 25], ['age', '<=', 40]])
        ->orderBy(['salary' => 'desc'])
        ->limit(50)
        ->getQuery()
        ->fetch();
    $sleekdbComplex = (microtime(true) - $start) * 1000;

    echo "    noneDB:  " . green(formatTime($nonedbComplex)) . "\n";
    echo "    SleekDB: " . formatTime($sleekdbComplex) . "\n";
    echo "    Result:  " . ratio($nonedbComplex, $sleekdbComplex) . "\n\n";

    $results[$size]['complex'] = ['nonedb' => $nonedbComplex, 'sleekdb' => $sleekdbComplex];

    // Cleanup
    $files = glob(__DIR__ . '/../db/*benchmark_nonedb_' . $size . '*');
    foreach ($files as $f) @unlink($f);

    if (is_dir($sleekdbDir)) {
        $files = glob($sleekdbDir . '/*');
        foreach ($files as $f) {
            if (is_dir($f)) {
                $subfiles = glob($f . '/*');
                foreach ($subfiles as $sf) @unlink($sf);
                @rmdir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($sleekdbDir);
    }
}

// ===== PRINT MARKDOWN TABLES =====
echo blue("\n╔══════════════════════════════════════════════════════════════════════════╗\n");
echo blue("║                    MARKDOWN TABLES FOR README                             ║\n");
echo blue("╚══════════════════════════════════════════════════════════════════════════╝\n\n");

$operations = [
    'insert' => 'Bulk Insert',
    'find_all' => 'Find All',
    'find_key' => 'Find by Key',
    'filter' => 'Find with Filter',
    'count' => 'Count',
    'update' => 'Update',
    'delete' => 'Delete',
    'complex' => 'Complex Query'
];

echo "### noneDB vs SleekDB Performance Comparison\n\n";

// Header
echo "| Operation |";
foreach ($sizes as $s) {
    $label = $s >= 1000 ? ($s / 1000) . "K" : $s;
    echo " {$label} noneDB | {$label} SleekDB |";
}
echo "\n|-----------|";
foreach ($sizes as $s) echo "--------|--------|";
echo "\n";

// Data rows
foreach ($operations as $key => $label) {
    echo "| {$label} |";
    foreach ($sizes as $s) {
        $n = isset($results[$s][$key]) ? formatTime($results[$s][$key]['nonedb']) : "-";
        $sl = isset($results[$s][$key]) ? formatTime($results[$s][$key]['sleekdb']) : "-";
        echo " {$n} | {$sl} |";
    }
    echo "\n";
}

echo "\n### Performance Ratio (noneDB vs SleekDB)\n\n";
echo "| Operation |";
foreach ($sizes as $s) {
    $label = $s >= 1000 ? ($s / 1000) . "K" : $s;
    echo " {$label} |";
}
echo "\n|-----------|";
foreach ($sizes as $s) echo "------|";
echo "\n";

foreach ($operations as $key => $label) {
    echo "| {$label} |";
    foreach ($sizes as $s) {
        if (isset($results[$s][$key])) {
            $n = $results[$s][$key]['nonedb'];
            $sl = $results[$s][$key]['sleekdb'];
            if ($n > 0) {
                $r = $sl / $n;
                if ($r >= 1) {
                    echo " **" . round($r, 1) . "x** |";
                } else {
                    echo " " . round($r, 2) . "x |";
                }
            } else {
                echo " ∞ |";
            }
        } else {
            echo " - |";
        }
    }
    echo "\n";
}

echo green("\n\nBenchmark completed!\n");
