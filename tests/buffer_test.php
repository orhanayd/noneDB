<?php
/**
 * noneDB v2.3.0 Write Buffer Test
 * Tests buffer functionality for fast inserts
 */

require_once __DIR__ . '/../noneDB.php';

$db = new noneDB();

// Colors
function green($t) { return "\033[32m{$t}\033[0m"; }
function red($t) { return "\033[31m{$t}\033[0m"; }
function yellow($t) { return "\033[33m{$t}\033[0m"; }
function blue($t) { return "\033[34m{$t}\033[0m"; }
function cyan($t) { return "\033[36m{$t}\033[0m"; }

echo blue("╔════════════════════════════════════════════════════════════╗\n");
echo blue("║           noneDB v2.3.0 Write Buffer Test                  ║\n");
echo blue("╚════════════════════════════════════════════════════════════╝\n\n");

$testDb = 'buffer_test_' . time();

// Test 1: Check buffer is enabled
echo yellow("Test 1: Buffer Configuration\n");
echo "  Buffer enabled: " . ($db->isBufferingEnabled() ? green("YES") : red("NO")) . "\n";
$info = $db->getBufferInfo($testDb);
echo "  Size limit: " . number_format($info['sizeLimit']) . " bytes (" . round($info['sizeLimit']/1024/1024, 1) . "MB)\n";
echo "  Count limit: " . number_format($info['countLimit']) . " records\n";
echo "  Flush interval: " . $info['flushInterval'] . " seconds\n\n";

// Test 2: Insert with buffer (empty DB)
echo yellow("Test 2: Buffered Insert (Empty DB)\n");
$insertCount = 1000;

$start = microtime(true);
for ($i = 0; $i < $insertCount; $i++) {
    $db->insert($testDb, [
        'name' => 'User' . $i,
        'email' => "user{$i}@test.com",
        'score' => rand(1, 100)
    ]);
}
$bufferedTime = (microtime(true) - $start) * 1000;

$info = $db->getBufferInfo($testDb);
$bufferRecords = $info['buffers']['main']['records'] ?? 0;
echo "  Inserted: {$insertCount} records\n";
echo "  Time: " . green(round($bufferedTime, 1) . " ms") . "\n";
echo "  Buffer records: {$bufferRecords}\n";

// Test 3: Manual flush
echo "\n" . yellow("Test 3: Manual Flush\n");
$flushResult = $db->flush($testDb);
echo "  Flushed: " . $flushResult['flushed'] . " records\n";
echo "  Success: " . ($flushResult['success'] ? green("YES") : red("NO")) . "\n";

// Test 4: Read after flush
echo "\n" . yellow("Test 4: Read Verification\n");
$data = $db->find($testDb, []);
echo "  Records in DB: " . count($data) . "\n";
echo "  Expected: {$insertCount}\n";
echo "  Match: " . (count($data) === $insertCount ? green("YES") : red("NO")) . "\n";

// Test 5: THE REAL BUFFER ADVANTAGE - Insert into large database
echo "\n" . cyan("═══════════════════════════════════════════════════════════════\n");
echo cyan("  TEST 5: Buffer Advantage on Large Database (10K records)\n");
echo cyan("═══════════════════════════════════════════════════════════════\n\n");

$largeDb = 'large_buffer_test_' . time();

// Create a 10K record database first
echo yellow("  Step 1: Creating 10K record database...\n");
$bulkData = [];
for ($i = 0; $i < 10000; $i++) {
    $bulkData[] = ['name' => "User$i", 'score' => rand(1,100)];
}
$db->insert($largeDb, $bulkData);
$db->flush($largeDb);
echo "  Created 10K records\n\n";

// Test A: Buffered individual inserts
echo yellow("  Step 2: Adding 100 records WITH buffer...\n");
$db->enableBuffering(true);
$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    $db->insert($largeDb, ['name' => "NewUser$i", 'type' => 'buffered']);
}
$bufferedLargeTime = (microtime(true) - $start) * 1000;
$db->flush($largeDb);
echo "  Time: " . green(round($bufferedLargeTime, 1) . " ms") . " (100 inserts)\n";
echo "  Per insert: " . green(round($bufferedLargeTime/100, 2) . " ms") . "\n\n";

// Test B: Non-buffered individual inserts (only 20 - it's slow!)
echo yellow("  Step 3: Adding 20 records WITHOUT buffer...\n");
$db->enableBuffering(false);
$start = microtime(true);
for ($i = 0; $i < 20; $i++) {
    $db->insert($largeDb, ['name' => "SlowUser$i", 'type' => 'nobuffer']);
}
$nonBufferedLargeTime = (microtime(true) - $start) * 1000;
echo "  Time: " . red(round($nonBufferedLargeTime, 1) . " ms") . " (20 inserts)\n";
echo "  Per insert: " . red(round($nonBufferedLargeTime/20, 2) . " ms") . "\n\n";

// Calculate speedup
$perInsertBuffered = $bufferedLargeTime / 100;
$perInsertNonBuffered = $nonBufferedLargeTime / 20;
$speedup = $perInsertNonBuffered / $perInsertBuffered;

echo cyan("  ┌────────────────────────────────────────────────────────────┐\n");
echo cyan("  │  RESULT: Buffer is ") . green(round($speedup, 0) . "x FASTER") . cyan(" on large databases!    │\n");
echo cyan("  │                                                            │\n");
echo cyan("  │  Buffered:     ") . sprintf("%-6s", round($perInsertBuffered, 2) . " ms") . cyan(" per insert                      │\n");
echo cyan("  │  Non-buffered: ") . sprintf("%-6s", round($perInsertNonBuffered, 2) . " ms") . cyan(" per insert                      │\n");
echo cyan("  └────────────────────────────────────────────────────────────┘\n");

// Cleanup
echo "\n" . yellow("Cleanup\n");
$db->enableBuffering(true);
$files = glob(__DIR__ . '/../db/*buffer_test*');
foreach ($files as $f) @unlink($f);
echo "  Cleaned up test files\n";

echo "\n" . green("Buffer test completed!\n");
