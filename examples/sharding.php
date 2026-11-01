<?php
/**
 * noneDB - Sharding Example
 *
 * Demonstrates auto-sharding features: getShardInfo(), compact(), convertToSharded()
 * and sharding behavior with large datasets.
 */

require __DIR__ . '/_bootstrap.php';
$db = exampleDb();
freshDatabases($db, 'small-db', 'medium-db', 'with-deletions');

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
$db->insert("small-db", [
    ["id" => 1, "name" => "Item 1"],
    ["id" => 2, "name" => "Item 2"],
    ["id" => 3, "name" => "Item 3"],
]);

$info = $db->getShardInfo("small-db");
echo "small-db info:\n";
echo "   Sharded: " . ($info['sharded'] ? "true" : "false") . "\n";
echo "   Total records: " . $info['totalRecords'] . "\n";
echo "   Shards: " . $info['shards'] . "\n";

// ==========================================
// GET SHARD INFO
// ==========================================
echo "\n=== GET SHARD INFO ===\n\n";

// For non-sharded database
echo "1. Non-sharded database info:\n";
$info = $db->getShardInfo("small-db");
if ($info) {
    echo "   " . json_encode($info, JSON_PRETTY_PRINT) . "\n";
}

// For non-existent database
echo "\n2. Non-existent database info:\n";
$info = $db->getShardInfo("nonexistent");
echo "   Result: " . ($info === false ? "false (database not found)" : json_encode($info)) . "\n";

// ==========================================
// SWITCHING TO SHARDS BY HAND
// ==========================================
echo "\n=== SWITCHING TO SHARDS BY HAND ===\n\n";

echo "1. convertToSharded() on a database that doesn't exist:\n";
$result = $db->convertToSharded("nonexistent");
echo "   " . json_encode($result) . "\n";

echo "\n2. convertToSharded() on a small database (creates its shards):\n";
$result = $db->convertToSharded("small-db");
echo "   " . json_encode($result) . "\n";

// Check after the switch
$info = $db->getShardInfo("small-db");
echo "   After the switch - Sharded: " . ($info['sharded'] ? "true" : "false") . "\n";

echo "\n3. Again (already sharded):\n";
$result = $db->convertToSharded("small-db");
echo "   " . json_encode($result) . "\n";

// ==========================================
// COMPACT
// ==========================================
echo "\n=== COMPACT ===\n\n";

// Create database with deletions. A high autoCompactThreshold keeps auto-compaction (by default once
// deleted records pass 30%) from running first, so compact() has something to do
$manual = exampleDb(["autoCompactThreshold" => 0.9]);
$manual->insert("with-deletions", [
    ["id" => 1, "name" => "Keep 1"],
    ["id" => 2, "name" => "Delete me"],
    ["id" => 3, "name" => "Keep 2"],
    ["id" => 4, "name" => "Delete me too"],
    ["id" => 5, "name" => "Keep 3"],
]);

echo "1. Before deletion:\n";
echo "   Records: " . $manual->count("with-deletions") . "\n";

// Delete some records
$manual->delete("with-deletions", ["name" => "Delete me"]);
$manual->delete("with-deletions", ["name" => "Delete me too"]);

echo "\n2. After deletion (deleted records are hidden immediately; their bytes stay in the file until compaction):\n";
echo "   Visible records: " . $manual->count("with-deletions") . "\n";

// Compact: rewrite the file without the superseded and deleted lines
echo "\n3. After compact:\n";
$result = $manual->compact("with-deletions");
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
$db->insert("medium-db", $data);
$insertTime = round((microtime(true) - $start) * 1000, 2);

echo "   Insert time: " . $insertTime . " ms\n";

$info = $db->getShardInfo("medium-db");
echo "   Sharded: " . ($info['sharded'] ? "true" : "false") . "\n";
echo "   Total records: " . $info['totalRecords'] . "\n";

// ==========================================
// KEY-BASED LOOKUP PERFORMANCE
// ==========================================
echo "\n=== KEY-BASED LOOKUP ===\n\n";

echo "Key lookups use the byte-offset index, sharded or not.\n\n";

// Find by key
$start = microtime(true);
$result = $db->find("medium-db", ["key" => 250]);
$findTime = round((microtime(true) - $start) * 1000, 4);

echo "Find by key 250:\n";
echo "   Time: " . $findTime . " ms\n";
echo "   Result: " . $result[0]['name'] . "\n";

// Find by multiple keys
$start = microtime(true);
$result = $db->find("medium-db", ["key" => [100, 200, 300, 400]]);
$findTime = round((microtime(true) - $start) * 1000, 4);

echo "\nFind by keys [100, 200, 300, 400]:\n";
echo "   Time: " . $findTime . " ms\n";
echo "   Found: " . count($result) . " records\n";

// ==========================================
// WHAT SHARDING CHANGES
// ==========================================
echo "\n=== WHAT SHARDING CHANGES ===\n\n";

echo "   - The first key lookup of a process loads one shard's index, not the whole database's\n";
echo "   - Auto-compaction rewrites one shard file at a time\n";
echo "   - Field indexes let filtered queries skip shards that can't match\n";
echo "   - Filters on fields without an index scan the shards (limit(), first() and exists() stop early)\n";
echo "   Measurements: docs/PERFORMANCE.md\n";

echo "\n=== Sharding Examples Complete ===\n";
