<?php
/**
 * noneDB - Find (Query) Example
 *
 * Demonstrates various ways to query and retrieve data.
 */

include("../noneDB.php");
$db = new noneDB();

echo "=== noneDB Find Example ===\n\n";

// Setup: Insert sample data
$db->insert("users", [
    ["name" => "Alice", "email" => "alice@example.com", "age" => 25, "city" => "Istanbul", "active" => true],
    ["name" => "Bob", "email" => "bob@example.com", "age" => 30, "city" => "Ankara", "active" => true],
    ["name" => "Charlie", "email" => "charlie@example.com", "age" => 35, "city" => "Istanbul", "active" => false],
    ["name" => "David", "email" => "david@example.com", "age" => 28, "city" => "Izmir", "active" => true],
    ["name" => "Eve", "email" => "eve@example.com", "age" => 22, "city" => "Istanbul", "active" => true],
]);

// -----------------------------------------
// 1. Find ALL Records
// -----------------------------------------
echo "1. Find ALL Records:\n";

// Method 1: Using 0
$all = $db->find("users", 0);
echo "   Using 0: " . count($all) . " records\n";

// Method 2: Using empty array
$all = $db->find("users", []);
echo "   Using []: " . count($all) . " records\n";

// -----------------------------------------
// 2. Find by Single Field
// -----------------------------------------
echo "\n2. Find by Single Field:\n";

$istanbul = $db->find("users", ["city" => "Istanbul"]);
echo "   Users in Istanbul: " . count($istanbul) . "\n";
foreach ($istanbul as $user) {
    echo "   - " . $user['name'] . " (key: " . $user['key'] . ")\n";
}

// -----------------------------------------
// 3. Find by Multiple Fields (AND)
// -----------------------------------------
echo "\n3. Find by Multiple Fields (AND condition):\n";

$activeIstanbul = $db->find("users", [
    "city" => "Istanbul",
    "active" => true
]);
echo "   Active users in Istanbul: " . count($activeIstanbul) . "\n";

// -----------------------------------------
// 4. Find by Key (Index)
// -----------------------------------------
echo "\n4. Find by Key (Index):\n";

// Single key
$user = $db->find("users", ["key" => 0]);
echo "   Key 0: " . $user[0]['name'] . "\n";

// Multiple keys
$users = $db->find("users", ["key" => [0, 2, 4]]);
echo "   Keys [0,2,4]: ";
foreach ($users as $u) {
    echo $u['name'] . " ";
}
echo "\n";

// -----------------------------------------
// 5. Find by Boolean Value
// -----------------------------------------
echo "\n5. Find by Boolean Value:\n";

$active = $db->find("users", ["active" => true]);
echo "   Active users: " . count($active) . "\n";

$inactive = $db->find("users", ["active" => false]);
echo "   Inactive users: " . count($inactive) . "\n";

// -----------------------------------------
// 6. Each Result Includes 'key' Field
// -----------------------------------------
echo "\n6. Each Result Includes 'key' Field:\n";

$firstUser = $db->find("users", ["name" => "Alice"]);
echo "   Alice's data: " . json_encode($firstUser[0]) . "\n";
echo "   Alice's key: " . $firstUser[0]['key'] . "\n";

// -----------------------------------------
// 7. Non-existent Data Returns Empty Array
// -----------------------------------------
echo "\n7. Non-existent Data Returns Empty Array:\n";

$notFound = $db->find("users", ["name" => "NonExistent"]);
echo "   Result: " . json_encode($notFound) . "\n";
echo "   Count: " . count($notFound) . "\n";

// -----------------------------------------
// 8. Combining with Other Methods
// -----------------------------------------
echo "\n8. Combining with Other Methods:\n";

// Find and sort
$all = $db->find("users", 0);
$sorted = $db->sort($all, "age", "desc");
echo "   Oldest user: " . $sorted[0]['name'] . " (age " . $sorted[0]['age'] . ")\n";

// Find and limit
$limited = $db->limit($all, 3);
echo "   First 3 users: ";
foreach ($limited as $u) {
    echo $u['name'] . " ";
}
echo "\n";

echo "\n=== Find Examples Complete ===\n";
