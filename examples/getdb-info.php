<?php
/**
 * noneDB - Database Information Example
 *
 * Demonstrates getDBs() method for listing databases and getting info.
 */

include("../noneDB.php");
$db = new noneDB();

echo "=== noneDB Database Information Example ===\n\n";

// Setup: Create some databases with data
$db->insert("users", [
    ["name" => "Alice", "email" => "alice@example.com"],
    ["name" => "Bob", "email" => "bob@example.com"],
]);

$db->insert("products", [
    ["name" => "Laptop", "price" => 999],
    ["name" => "Phone", "price" => 599],
    ["name" => "Tablet", "price" => 399],
]);

$db->insert("logs", [
    ["level" => "info", "message" => "System started"],
]);

echo "Created 3 databases with sample data.\n\n";

// -----------------------------------------
// 1. List All Database Names
// -----------------------------------------
echo "1. List All Database Names:\n";

$names = $db->getDBs(false);
echo "   Databases: " . json_encode($names) . "\n";
echo "   Count: " . count($names) . "\n";

// -----------------------------------------
// 2. List All Databases with Metadata
// -----------------------------------------
echo "\n2. List All Databases with Metadata:\n";

$dbs = $db->getDBs(true);
foreach ($dbs as $database) {
    echo "   - " . $database['name'];
    echo " (Size: " . $database['size'];
    echo ", Created: " . date("Y-m-d H:i:s", $database['createdTime']) . ")\n";
}

// -----------------------------------------
// 3. Get Specific Database Info
// -----------------------------------------
echo "\n3. Get Specific Database Info:\n";

$info = $db->getDBs("users");
if ($info) {
    echo "   Database: " . $info['name'] . "\n";
    echo "   Size: " . $info['size'] . "\n";
    echo "   Created: " . date("Y-m-d H:i:s", $info['createdTime']) . "\n";
} else {
    echo "   Database not found\n";
}

// -----------------------------------------
// 4. Check Non-existent Database
// -----------------------------------------
echo "\n4. Check Non-existent Database:\n";

$info = $db->getDBs("nonexistent");
echo "   Result: " . ($info === false ? "false (not found)" : json_encode($info)) . "\n";

// -----------------------------------------
// 5. Combined with Record Count
// -----------------------------------------
echo "\n5. Database Summary:\n";

$names = $db->getDBs(false);
echo "   +-----------------+--------+---------+\n";
echo "   | Database        | Records| Size    |\n";
echo "   +-----------------+--------+---------+\n";

foreach ($names as $name) {
    $count = $db->count($name);
    $info = $db->getDBs($name);
    $size = $info ? $info['size'] : "N/A";
    printf("   | %-15s | %6d | %-7s |\n", $name, $count, $size);
}
echo "   +-----------------+--------+---------+\n";

// -----------------------------------------
// 6. Sharding Information
// -----------------------------------------
echo "\n6. Sharding Information:\n";

foreach ($names as $name) {
    $shardInfo = $db->getShardInfo($name);
    if ($shardInfo) {
        echo "   " . $name . ": ";
        if ($shardInfo['sharded']) {
            echo "Sharded (" . $shardInfo['shards'] . " shards, ";
            echo $shardInfo['totalRecords'] . " records)\n";
        } else {
            echo "Not sharded (" . $shardInfo['totalRecords'] . " records)\n";
        }
    }
}

echo "\n=== Database Information Examples Complete ===\n";
