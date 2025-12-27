<?php
/**
 * noneDB - Sharding Example
 *
 * Demonstrates auto-sharding features: getShardInfo(), compact(), migrate()
 * and sharding behavior with large datasets.
 */

include("../noneDB.php");
$db = new noneDB();

echo "=== noneDB Sharding Example ===\n\n";

// ==========================================
// SHARDING CONFIGURATION
// ==========================================
echo "=== SHARDING CONFIGURATION ===\n\n";

echo "Current settings:\n";
echo "   Sharding enabled: " . ($db->isShardingEnabled() ? "true" : "false") . "\n";
echo "   Shard size: " . $db->getShardSize() . " records\n";
echo "   (Records per shard before creating a new one)\n";

// ==========================================
// SMALL DATABASE (NOT SHARDED)
// ==========================================
echo "\n=== SMALL DATABASE (NOT SHARDED) ===\n\n";

// Create a small database
$db->insert("small_db", [
    ["id" => 1, "name" => "Item 1"],
    ["id" => 2, "name" => "Item 2"],
    ["id" => 3, "name" => "Item 3"],
]);

$info = $db->getShardInfo("small_db");
echo "small_db info:\n";
echo "   Sharded: " . ($info['sharded'] ? "true" : "false") . "\n";
echo "   Total records: " . $info['totalRecords'] . "\n";
echo "   Shards: " . $info['shards'] . "\n";

// ==========================================
// GET SHARD INFO
// ==========================================
echo "\n=== GET SHARD INFO ===\n\n";

// For non-sharded database
echo "1. Non-sharded database info:\n";
$info = $db->getShardInfo("small_db");
if ($info) {
    echo "   " . json_encode($info, JSON_PRETTY_PRINT) . "\n";
}

// For non-existent database
echo "\n2. Non-existent database info:\n";
$info = $db->getShardInfo("nonexistent");
echo "   Result: " . ($info === false ? "false (database not found)" : json_encode($info)) . "\n";

// ==========================================
// MANUAL MIGRATION
// ==========================================
echo "\n=== MANUAL MIGRATION ===\n\n";

echo "1. Migrate non-existent database:\n";
$result = $db->migrate("nonexistent");
echo "   " . json_encode($result) . "\n";

echo "\n2. Migrate small database (will create shards):\n";
$result = $db->migrate("small_db");
echo "   " . json_encode($result) . "\n";

// Check after migration
$info = $db->getShardInfo("small_db");
echo "   After migration - Sharded: " . ($info['sharded'] ? "true" : "false") . "\n";

echo "\n3. Try to migrate again (already sharded):\n";
$result = $db->migrate("small_db");
echo "   " . json_encode($result) . "\n";

// ==========================================
// COMPACT
// ==========================================
echo "\n=== COMPACT ===\n\n";

// Create database with deletions
$db->insert("with_deletions", [
    ["id" => 1, "name" => "Keep 1"],
    ["id" => 2, "name" => "Delete me"],
    ["id" => 3, "name" => "Keep 2"],
    ["id" => 4, "name" => "Delete me too"],
    ["id" => 5, "name" => "Keep 3"],
]);

echo "1. Before deletion:\n";
echo "   Records: " . $db->count("with_deletions") . "\n";

// Delete some records
$db->delete("with_deletions", ["name" => "Delete me"]);
$db->delete("with_deletions", ["name" => "Delete me too"]);

echo "\n2. After deletion (records still show null internally):\n";
echo "   Visible records: " . $db->count("with_deletions") . "\n";

// Compact to remove null entries
echo "\n3. After compact:\n";
$result = $db->compact("with_deletions");
echo "   Result: " . json_encode($result) . "\n";
echo "   Freed slots: " . $result['freedSlots'] . "\n";
echo "   Total records: " . $result['totalRecords'] . "\n";

// ==========================================
// LARGE DATASET SIMULATION
// ==========================================
echo "\n=== LARGE DATASET SIMULATION ===\n\n";

echo "NOTE: Auto-sharding triggers at " . $db->getShardSize() . " records.\n";
echo "For this demo, we'll simulate with smaller numbers.\n\n";

// For demonstration, let's create a moderately sized dataset
echo "Creating 500 records...\n";
$data = [];
for ($i = 1; $i <= 500; $i++) {
    $data[] = [
        "id" => $i,
        "name" => "User " . $i,
        "email" => "user" . $i . "@example.com",
    ];
}

$start = microtime(true);
$db->insert("medium_db", $data);
$insertTime = round((microtime(true) - $start) * 1000, 2);

echo "   Insert time: " . $insertTime . " ms\n";

$info = $db->getShardInfo("medium_db");
echo "   Sharded: " . ($info['sharded'] ? "true" : "false") . "\n";
echo "   Total records: " . $info['totalRecords'] . "\n";

// ==========================================
// KEY-BASED LOOKUP PERFORMANCE
// ==========================================
echo "\n=== KEY-BASED LOOKUP ===\n\n";

echo "Key-based lookups are fast because they only read one shard.\n\n";

// Find by key
$start = microtime(true);
$result = $db->find("medium_db", ["key" => 250]);
$findTime = round((microtime(true) - $start) * 1000, 4);

echo "Find by key 250:\n";
echo "   Time: " . $findTime . " ms\n";
echo "   Result: " . $result[0]['name'] . "\n";

// Find by multiple keys
$start = microtime(true);
$result = $db->find("medium_db", ["key" => [100, 200, 300, 400]]);
$findTime = round((microtime(true) - $start) * 1000, 4);

echo "\nFind by keys [100, 200, 300, 400]:\n";
echo "   Time: " . $findTime . " ms\n";
echo "   Found: " . count($result) . " records\n";

// ==========================================
// SHARDING BENEFITS SUMMARY
// ==========================================
echo "\n=== SHARDING BENEFITS ===\n\n";

echo "Without Sharding (500K records):\n";
echo "   - Every operation reads entire 50MB file\n";
echo "   - find(key) takes ~772ms, uses ~1.1GB RAM\n";
echo "   - insert() takes ~706ms\n\n";

echo "With Sharding (500K records, 50 shards):\n";
echo "   - Key lookups read only 1 shard (~1MB)\n";
echo "   - find(key) takes ~16ms, uses ~1MB RAM\n";
echo "   - 50x faster, 1000x less memory\n\n";

echo "When to use sharding:\n";
echo "   - < 10K records: Sharding unnecessary\n";
echo "   - 10K - 100K: Beneficial for key lookups\n";
echo "   - 100K - 500K: Highly recommended\n";
echo "   - > 500K: Consider dedicated database\n";

echo "\n=== Sharding Examples Complete ===\n";
