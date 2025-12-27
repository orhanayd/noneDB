<?php
/**
 * SleekDB vs noneDB Performance Benchmark
 *
 * Compares performance between SleekDB and noneDB on various operations
 * with different record counts (100, 1K, 10K, 50K, 100K)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../noneDB.php';

use SleekDB\Store;

ini_set('memory_limit', '-1');
set_time_limit(0);

// Colors
function green($t) { return "\033[32m{$t}\033[0m"; }
function red($t) { return "\033[31m{$t}\033[0m"; }
function yellow($t) { return "\033[33m{$t}\033[0m"; }
function blue($t) { return "\033[34m{$t}\033[0m"; }
function cyan($t) { return "\033[36m{$t}\033[0m"; }
function magenta($t) { return "\033[35m{$t}\033[0m"; }

// Format time
function formatTime($ms) {
    if ($ms < 1) return "<1 ms";
    if ($ms >= 1000) return round($ms / 1000, 2) . " s";
    return round($ms, 1) . " ms";
}

// Format memory
function formatMemory($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . " GB";
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . " MB";
    return round($bytes / 1024, 1) . " KB";
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

// Test directories
$sleekDbDir = __DIR__ . '/sleekdb_bench/';
$noneDbDir = __DIR__ . '/nonedb_bench/';

// Cleanup function
function cleanup($sleekDbDir, $noneDbDir) {
    // Remove SleekDB files
    if (is_dir($sleekDbDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sleekDbDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($sleekDbDir);
    }

    // Remove noneDB files
    $noneFiles = glob($noneDbDir . '*');
    foreach ($noneFiles as $f) {
        if (is_file($f)) @unlink($f);
    }
    if (is_dir($noneDbDir)) @rmdir($noneDbDir);
}

// Create directories
if (!is_dir($sleekDbDir)) mkdir($sleekDbDir, 0777, true);
if (!is_dir($noneDbDir)) mkdir($noneDbDir, 0777, true);

echo blue("╔══════════════════════════════════════════════════════════════════════╗\n");
echo blue("║            SleekDB vs noneDB Performance Benchmark                   ║\n");
echo blue("╚══════════════════════════════════════════════════════════════════════╝\n\n");

echo "PHP Version: " . PHP_VERSION . "\n";
echo "SleekDB: v2.15 (cache OFF)\n";
echo "noneDB: v2.3.0 (sharding ON, buffer ON/OFF)\n\n";

// Test sizes
$sizes = [100, 1000, 10000, 50000, 100000];

// Results storage
$results = [];

foreach ($sizes as $size) {
    echo yellow("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");
    echo yellow("  Testing with " . number_format($size) . " records\n");
    echo yellow("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n");

    // Cleanup before test
    cleanup($sleekDbDir, $noneDbDir);
    if (!is_dir($sleekDbDir)) mkdir($sleekDbDir, 0777, true);
    if (!is_dir($noneDbDir)) mkdir($noneDbDir, 0777, true);

    // Prepare test data
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        $data[] = generateRecord($i);
    }

    $results[$size] = [
        'sleekdb' => [],
        'nonedb_default' => [],
        'nonedb_nobuffer' => []
    ];

    // =====================================================================
    // SLEEKDB TESTS
    // =====================================================================
    echo cyan("  ┌─ SleekDB (cache OFF) ─────────────────────────────────────────────┐\n");

    $sleekConfig = [
        "auto_cache" => false,
        "cache_lifetime" => null,
        "timeout" => false
    ];

    // Bulk Insert
    $store = new Store("benchmark", $sleekDbDir, $sleekConfig);
    gc_collect_cycles();
    $memBefore = memory_get_usage(true);
    $start = microtime(true);
    $store->insertMany($data);
    $sleekBulkInsert = (microtime(true) - $start) * 1000;
    $sleekBulkMem = memory_get_peak_usage(true) - $memBefore;
    $results[$size]['sleekdb']['bulk_insert'] = $sleekBulkInsert;
    $results[$size]['sleekdb']['bulk_insert_mem'] = $sleekBulkMem;
    echo "  │  Bulk Insert:      " . green(formatTime($sleekBulkInsert)) . " (mem: " . formatMemory($sleekBulkMem) . ")\n";

    // Find All
    gc_collect_cycles();
    $start = microtime(true);
    $allData = $store->findAll();
    $sleekFindAll = (microtime(true) - $start) * 1000;
    $results[$size]['sleekdb']['find_all'] = $sleekFindAll;
    echo "  │  Find All:         " . green(formatTime($sleekFindAll)) . "\n";

    // Find by ID
    $testId = (int)($size / 2);
    gc_collect_cycles();
    $start = microtime(true);
    $record = $store->findById($testId);
    $sleekFindId = (microtime(true) - $start) * 1000;
    $results[$size]['sleekdb']['find_id'] = $sleekFindId;
    echo "  │  Find by ID:       " . green(formatTime($sleekFindId)) . "\n";

    // Find by Filter
    gc_collect_cycles();
    $start = microtime(true);
    $filtered = $store->findBy(["city", "=", "Istanbul"]);
    $sleekFindFilter = (microtime(true) - $start) * 1000;
    $results[$size]['sleekdb']['find_filter'] = $sleekFindFilter;
    echo "  │  Find by Filter:   " . green(formatTime($sleekFindFilter)) . "\n";

    // Count
    gc_collect_cycles();
    $start = microtime(true);
    $count = $store->count();
    $sleekCount = (microtime(true) - $start) * 1000;
    $results[$size]['sleekdb']['count'] = $sleekCount;
    echo "  │  Count:            " . green(formatTime($sleekCount)) . "\n";

    // Sequential Insert (100 records on existing DB)
    gc_collect_cycles();
    $start = microtime(true);
    for ($i = 0; $i < 100; $i++) {
        $store->insert(generateRecord($size + $i));
    }
    $sleekSeqInsert = (microtime(true) - $start) * 1000;
    $results[$size]['sleekdb']['seq_insert'] = $sleekSeqInsert;
    echo "  │  Seq Insert (100): " . green(formatTime($sleekSeqInsert)) . "\n";

    // Update (using QueryBuilder)
    gc_collect_cycles();
    $start = microtime(true);
    $store->createQueryBuilder()
        ->where(["city", "=", "Istanbul"])
        ->getQuery()
        ->update(["region" => "Marmara"]);
    $sleekUpdate = (microtime(true) - $start) * 1000;
    $results[$size]['sleekdb']['update'] = $sleekUpdate;
    echo "  │  Update:           " . green(formatTime($sleekUpdate)) . "\n";

    // Delete (using QueryBuilder)
    gc_collect_cycles();
    $start = microtime(true);
    $store->createQueryBuilder()
        ->where(["department", "=", "HR"])
        ->getQuery()
        ->delete();
    $sleekDelete = (microtime(true) - $start) * 1000;
    $results[$size]['sleekdb']['delete'] = $sleekDelete;
    echo "  │  Delete:           " . green(formatTime($sleekDelete)) . "\n";

    echo cyan("  └──────────────────────────────────────────────────────────────────────┘\n\n");

    // Cleanup SleekDB
    cleanup($sleekDbDir, $noneDbDir);
    if (!is_dir($noneDbDir)) mkdir($noneDbDir, 0777, true);

    // =====================================================================
    // NONEDB (DEFAULT - Buffer ON, Sharding ON)
    // =====================================================================
    echo magenta("  ┌─ noneDB (default: buffer ON, sharding ON) ───────────────────────┐\n");

    $nonedb = new noneDB();
    $ref = new ReflectionClass($nonedb);
    $prop = $ref->getProperty('dbDir');
    $prop->setAccessible(true);
    $prop->setValue($nonedb, $noneDbDir);

    // Bulk Insert
    gc_collect_cycles();
    $memBefore = memory_get_usage(true);
    $start = microtime(true);
    $nonedb->insert("benchmark", $data);
    $nonedb->flush("benchmark");
    $noneBulkInsert = (microtime(true) - $start) * 1000;
    $noneBulkMem = memory_get_peak_usage(true) - $memBefore;
    $results[$size]['nonedb_default']['bulk_insert'] = $noneBulkInsert;
    $results[$size]['nonedb_default']['bulk_insert_mem'] = $noneBulkMem;
    echo "  │  Bulk Insert:      " . green(formatTime($noneBulkInsert)) . " (mem: " . formatMemory($noneBulkMem) . ")\n";

    // Find All
    gc_collect_cycles();
    $start = microtime(true);
    $allData = $nonedb->find("benchmark", []);
    $noneFindAll = (microtime(true) - $start) * 1000;
    $results[$size]['nonedb_default']['find_all'] = $noneFindAll;
    echo "  │  Find All:         " . green(formatTime($noneFindAll)) . "\n";

    // Find by Key
    $testKey = (int)($size / 2);
    gc_collect_cycles();
    $start = microtime(true);
    $record = $nonedb->find("benchmark", ["key" => $testKey]);
    $noneFindKey = (microtime(true) - $start) * 1000;
    $results[$size]['nonedb_default']['find_id'] = $noneFindKey;
    echo "  │  Find by Key:      " . green(formatTime($noneFindKey)) . "\n";

    // Find by Filter
    gc_collect_cycles();
    $start = microtime(true);
    $filtered = $nonedb->find("benchmark", ["city" => "Istanbul"]);
    $noneFindFilter = (microtime(true) - $start) * 1000;
    $results[$size]['nonedb_default']['find_filter'] = $noneFindFilter;
    echo "  │  Find by Filter:   " . green(formatTime($noneFindFilter)) . "\n";

    // Count
    gc_collect_cycles();
    $start = microtime(true);
    $count = $nonedb->count("benchmark");
    $noneCount = (microtime(true) - $start) * 1000;
    $results[$size]['nonedb_default']['count'] = $noneCount;
    echo "  │  Count:            " . green(formatTime($noneCount)) . "\n";

    // Sequential Insert (100 records with buffer)
    gc_collect_cycles();
    $start = microtime(true);
    for ($i = 0; $i < 100; $i++) {
        $nonedb->insert("benchmark", generateRecord($size + $i));
    }
    $nonedb->flush("benchmark");
    $noneSeqInsert = (microtime(true) - $start) * 1000;
    $results[$size]['nonedb_default']['seq_insert'] = $noneSeqInsert;
    echo "  │  Seq Insert (100): " . green(formatTime($noneSeqInsert)) . "\n";

    // Update
    gc_collect_cycles();
    $start = microtime(true);
    $nonedb->update("benchmark", [["city" => "Istanbul"], ["set" => ["region" => "Marmara"]]]);
    $noneUpdate = (microtime(true) - $start) * 1000;
    $results[$size]['nonedb_default']['update'] = $noneUpdate;
    echo "  │  Update:           " . green(formatTime($noneUpdate)) . "\n";

    // Delete
    gc_collect_cycles();
    $start = microtime(true);
    $nonedb->delete("benchmark", ["department" => "HR"]);
    $noneDelete = (microtime(true) - $start) * 1000;
    $results[$size]['nonedb_default']['delete'] = $noneDelete;
    echo "  │  Delete:           " . green(formatTime($noneDelete)) . "\n";

    echo magenta("  └──────────────────────────────────────────────────────────────────────┘\n\n");

    // Cleanup noneDB
    $noneFiles = glob($noneDbDir . '*');
    foreach ($noneFiles as $f) @unlink($f);

    // =====================================================================
    // NONEDB (Buffer OFF)
    // =====================================================================
    echo magenta("  ┌─ noneDB (buffer OFF, sharding ON) ────────────────────────────────┐\n");

    $nonedb2 = new noneDB();
    $ref2 = new ReflectionClass($nonedb2);
    $prop2 = $ref2->getProperty('dbDir');
    $prop2->setAccessible(true);
    $prop2->setValue($nonedb2, $noneDbDir);
    $nonedb2->enableBuffering(false);

    // Bulk Insert (no buffer)
    gc_collect_cycles();
    $memBefore = memory_get_usage(true);
    $start = microtime(true);
    $nonedb2->insert("benchmark", $data);
    $noneNoBufBulk = (microtime(true) - $start) * 1000;
    $noneNoBufMem = memory_get_peak_usage(true) - $memBefore;
    $results[$size]['nonedb_nobuffer']['bulk_insert'] = $noneNoBufBulk;
    $results[$size]['nonedb_nobuffer']['bulk_insert_mem'] = $noneNoBufMem;
    echo "  │  Bulk Insert:      " . green(formatTime($noneNoBufBulk)) . " (mem: " . formatMemory($noneNoBufMem) . ")\n";

    // Find All
    gc_collect_cycles();
    $start = microtime(true);
    $allData = $nonedb2->find("benchmark", []);
    $noneNoBufFindAll = (microtime(true) - $start) * 1000;
    $results[$size]['nonedb_nobuffer']['find_all'] = $noneNoBufFindAll;
    echo "  │  Find All:         " . green(formatTime($noneNoBufFindAll)) . "\n";

    // Sequential Insert (only 10 - no buffer is SLOW on large DB)
    $seqCount = ($size >= 50000) ? 10 : 100;
    gc_collect_cycles();
    $start = microtime(true);
    for ($i = 0; $i < $seqCount; $i++) {
        $nonedb2->insert("benchmark", generateRecord($size + $i));
    }
    $noneNoBufSeq = (microtime(true) - $start) * 1000;
    $noneNoBufSeqNorm = ($seqCount == 10) ? $noneNoBufSeq * 10 : $noneNoBufSeq; // Normalize to 100
    $results[$size]['nonedb_nobuffer']['seq_insert'] = $noneNoBufSeqNorm;
    echo "  │  Seq Insert (" . $seqCount . "):  " . green(formatTime($noneNoBufSeq)) . ($seqCount == 10 ? " (×10 = " . formatTime($noneNoBufSeqNorm) . ")" : "") . "\n";

    echo magenta("  └──────────────────────────────────────────────────────────────────────┘\n\n");

    // Cleanup
    cleanup($sleekDbDir, $noneDbDir);
    if (!is_dir($sleekDbDir)) mkdir($sleekDbDir, 0777, true);
    if (!is_dir($noneDbDir)) mkdir($noneDbDir, 0777, true);
}

// Final cleanup
cleanup($sleekDbDir, $noneDbDir);

// =====================================================================
// PRINT MARKDOWN TABLES
// =====================================================================
echo blue("\n╔══════════════════════════════════════════════════════════════════════╗\n");
echo blue("║                    MARKDOWN TABLES FOR README                         ║\n");
echo blue("╚══════════════════════════════════════════════════════════════════════╝\n\n");

echo "## SleekDB vs noneDB Performance Comparison\n\n";
echo "Tested on PHP " . PHP_VERSION . ", " . PHP_OS . "\n\n";

// Bulk Insert Table
echo "### Bulk Insert\n";
echo "| Records | SleekDB | noneDB (buffer) | noneDB (no buffer) |\n";
echo "|---------|---------|-----------------|--------------------|\n";
foreach ($sizes as $size) {
    $label = $size >= 1000 ? ($size / 1000) . "K" : $size;
    $sleek = formatTime($results[$size]['sleekdb']['bulk_insert']);
    $noneB = formatTime($results[$size]['nonedb_default']['bulk_insert']);
    $noneNB = formatTime($results[$size]['nonedb_nobuffer']['bulk_insert']);
    echo "| {$label} | {$sleek} | {$noneB} | {$noneNB} |\n";
}
echo "\n";

// Sequential Insert Table
echo "### Sequential Insert (100 records on existing DB)\n";
echo "| Records | SleekDB | noneDB (buffer) | noneDB (no buffer) |\n";
echo "|---------|---------|-----------------|--------------------|\n";
foreach ($sizes as $size) {
    $label = $size >= 1000 ? ($size / 1000) . "K" : $size;
    $sleek = formatTime($results[$size]['sleekdb']['seq_insert']);
    $noneB = formatTime($results[$size]['nonedb_default']['seq_insert']);
    $noneNB = formatTime($results[$size]['nonedb_nobuffer']['seq_insert'] ?? 0);
    echo "| {$label} | {$sleek} | {$noneB} | {$noneNB} |\n";
}
echo "\n";

// Find All Table
echo "### Find All Records\n";
echo "| Records | SleekDB | noneDB |\n";
echo "|---------|---------|--------|\n";
foreach ($sizes as $size) {
    $label = $size >= 1000 ? ($size / 1000) . "K" : $size;
    $sleek = formatTime($results[$size]['sleekdb']['find_all']);
    $none = formatTime($results[$size]['nonedb_default']['find_all']);
    echo "| {$label} | {$sleek} | {$none} |\n";
}
echo "\n";

// Find by ID Table
echo "### Find by ID/Key\n";
echo "| Records | SleekDB | noneDB |\n";
echo "|---------|---------|--------|\n";
foreach ($sizes as $size) {
    $label = $size >= 1000 ? ($size / 1000) . "K" : $size;
    $sleek = formatTime($results[$size]['sleekdb']['find_id']);
    $none = formatTime($results[$size]['nonedb_default']['find_id']);
    echo "| {$label} | {$sleek} | {$none} |\n";
}
echo "\n";

// Memory Usage Table
echo "### Memory Usage (Bulk Insert)\n";
echo "| Records | SleekDB | noneDB |\n";
echo "|---------|---------|--------|\n";
foreach ($sizes as $size) {
    $label = $size >= 1000 ? ($size / 1000) . "K" : $size;
    $sleek = formatMemory($results[$size]['sleekdb']['bulk_insert_mem']);
    $none = formatMemory($results[$size]['nonedb_default']['bulk_insert_mem']);
    echo "| {$label} | {$sleek} | {$none} |\n";
}
echo "\n";

echo green("\nBenchmark completed!\n");
