<?php
/**
 * noneDB - Database Management Example
 *
 * Demonstrates createDB(), checkDB(), and getDBs() methods.
 */

include("../noneDB.php");
$db = new noneDB();

echo "=== noneDB Database Management Example ===\n\n";

// ==========================================
// CREATE DATABASE
// ==========================================
echo "=== CREATE DATABASE ===\n\n";

// -----------------------------------------
// 1. Create a New Database
// -----------------------------------------
echo "1. Create a New Database:\n";

$result = $db->createDB("my_new_database");
echo "   createDB('my_new_database'): " . ($result ? "true (created)" : "false (exists)") . "\n";

// Try to create again
$result = $db->createDB("my_new_database");
echo "   createDB('my_new_database') again: " . ($result ? "true (created)" : "false (already exists)") . "\n";

// -----------------------------------------
// 2. Create Multiple Databases
// -----------------------------------------
echo "\n2. Create Multiple Databases:\n";

$databases = ["users", "products", "orders", "logs"];
foreach ($databases as $dbName) {
    $result = $db->createDB($dbName);
    echo "   " . $dbName . ": " . ($result ? "created" : "exists") . "\n";
}

// ==========================================
// CHECK DATABASE
// ==========================================
echo "\n=== CHECK DATABASE ===\n\n";

// -----------------------------------------
// 3. Check Existing Database
// -----------------------------------------
echo "3. Check Existing Database:\n";

$exists = $db->checkDB("users");
echo "   checkDB('users'): " . ($exists ? "true" : "false") . "\n";

// -----------------------------------------
// 4. Check Non-existing Database (Auto-create)
// -----------------------------------------
echo "\n4. Check Non-existing Database (Auto-create enabled):\n";
echo "   Note: autoCreateDB is enabled by default.\n";

$exists = $db->checkDB("auto_created_db");
echo "   checkDB('auto_created_db'): " . ($exists ? "true (auto-created)" : "false") . "\n";

// -----------------------------------------
// 5. Database Name Sanitization
// -----------------------------------------
echo "\n5. Database Name Sanitization:\n";
echo "   Only [A-Za-z0-9' -] allowed, others removed.\n\n";

$db->createDB("test_db!");       // becomes "testdb"
$db->createDB("my-database");    // hyphen is OK
$db->createDB("user's data");    // apostrophe is OK

echo "   'test_db!' becomes 'testdb'\n";
echo "   'my-database' stays 'my-database'\n";
echo "   \"user's data\" stays \"user's data\"\n";

// ==========================================
// GET DATABASES
// ==========================================
echo "\n=== GET DATABASES ===\n\n";

// First, add some data to databases
$db->insert("users", [["name" => "Alice"], ["name" => "Bob"]]);
$db->insert("products", [["name" => "Laptop", "price" => 999]]);
$db->insert("orders", [["id" => 1, "total" => 150]]);

// -----------------------------------------
// 6. List Database Names Only
// -----------------------------------------
echo "6. List Database Names Only:\n";

$names = $db->getDBs(false);
echo "   Databases: " . implode(", ", $names) . "\n";
echo "   Count: " . count($names) . "\n";

// -----------------------------------------
// 7. List Databases with Metadata
// -----------------------------------------
echo "\n7. List Databases with Metadata:\n";

$dbs = $db->getDBs(true);
echo "   +-----------------+---------------------+---------+\n";
echo "   | Database        | Created             | Size    |\n";
echo "   +-----------------+---------------------+---------+\n";

foreach ($dbs as $database) {
    printf("   | %-15s | %s | %-7s |\n",
        $database['name'],
        date("Y-m-d H:i:s", $database['createdTime']),
        $database['size']
    );
}
echo "   +-----------------+---------------------+---------+\n";

// -----------------------------------------
// 8. Get Specific Database Info
// -----------------------------------------
echo "\n8. Get Specific Database Info:\n";

$info = $db->getDBs("users");
if ($info) {
    echo "   Database: " . $info['name'] . "\n";
    echo "   Created: " . date("Y-m-d H:i:s", $info['createdTime']) . "\n";
    echo "   Size: " . $info['size'] . "\n";
    echo "   Records: " . $db->count("users") . "\n";
} else {
    echo "   Database not found.\n";
}

// -----------------------------------------
// 9. Check Non-existent Database Info
// -----------------------------------------
echo "\n9. Check Non-existent Database Info:\n";

$info = $db->getDBs("this_does_not_exist_xyz");
echo "   Result: " . ($info === false ? "false (not found)" : json_encode($info)) . "\n";

// ==========================================
// COMPLETE DATABASE LISTING
// ==========================================
echo "\n=== COMPLETE DATABASE LISTING ===\n\n";

$names = $db->getDBs(false);
echo "+-----------------+--------+---------+----------+--------+\n";
echo "| Database        | Records| Size    | Sharded  | Shards |\n";
echo "+-----------------+--------+---------+----------+--------+\n";

foreach ($names as $name) {
    $count = $db->count($name);
    $info = $db->getDBs($name);
    $shardInfo = $db->getShardInfo($name);

    $size = $info ? $info['size'] : "N/A";
    $sharded = ($shardInfo && $shardInfo['sharded']) ? "Yes" : "No";
    $shards = ($shardInfo && $shardInfo['sharded']) ? $shardInfo['shards'] : 0;

    printf("| %-15s | %6d | %-7s | %-8s | %6d |\n",
        $name, $count, $size, $sharded, $shards);
}
echo "+-----------------+--------+---------+----------+--------+\n";

// ==========================================
// BEST PRACTICES
// ==========================================
echo "\n=== BEST PRACTICES ===\n\n";

echo "1. Database Naming:\n";
echo "   - Use lowercase letters and hyphens\n";
echo "   - Avoid special characters (they get removed)\n";
echo "   - Be consistent: 'user-profiles' not 'userProfiles'\n";

echo "\n2. Database Organization:\n";
echo "   - Separate concerns: users, orders, logs\n";
echo "   - Don't mix unrelated data in one database\n";
echo "   - Consider sharding for large datasets\n";

echo "\n3. Security:\n";
echo "   - Change \$secretKey before production!\n";
echo "   - Protect /db directory with .htaccess\n";
echo "   - Validate/sanitize database names from user input\n";

echo "\n=== Database Management Examples Complete ===\n";
