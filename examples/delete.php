<?php
/**
 * noneDB - Delete Example
 *
 * Demonstrates various ways to delete records.
 */

include("../noneDB.php");
$db = new noneDB();

echo "=== noneDB Delete Example ===\n\n";

// Setup: Insert sample data
$db->insert("users", [
    ["name" => "Alice", "role" => "admin", "active" => true],
    ["name" => "Bob", "role" => "user", "active" => true],
    ["name" => "Charlie", "role" => "user", "active" => false],
    ["name" => "David", "role" => "moderator", "active" => true],
    ["name" => "Eve", "role" => "user", "active" => true],
    ["name" => "Frank", "role" => "user", "active" => false],
]);

echo "Initial: " . $db->count("users") . " users\n\n";

// -----------------------------------------
// 1. Delete by Field Value
// -----------------------------------------
echo "1. Delete by Field Value:\n";

$result = $db->delete("users", ["name" => "Frank"]);
echo "   Deleted: " . $result['n'] . " record(s)\n";
echo "   Remaining: " . $db->count("users") . " users\n";

// -----------------------------------------
// 2. Delete by Multiple Fields (AND)
// -----------------------------------------
echo "\n2. Delete by Multiple Fields (AND condition):\n";

$result = $db->delete("users", [
    "role" => "user",
    "active" => false
]);
echo "   Deleted inactive users with role=user: " . $result['n'] . "\n";
echo "   Remaining: " . $db->count("users") . " users\n";

// -----------------------------------------
// 3. Delete by Key (Index)
// -----------------------------------------
echo "\n3. Delete by Key (Index):\n";

// First, find current keys
$users = $db->find("users", 0);
echo "   Before - Keys: ";
foreach ($users as $u) {
    echo $u['key'] . "(" . $u['name'] . ") ";
}
echo "\n";

// Delete specific key
$result = $db->delete("users", ["key" => 1]);  // Delete Bob
echo "   Deleted key 1: " . $result['n'] . " record(s)\n";

// Verify
echo "   Bob exists: " . ($db->exists("users", ["name" => "Bob"]) ? "yes" : "no") . "\n";

// -----------------------------------------
// 4. Delete Multiple by Keys
// -----------------------------------------
echo "\n4. Delete Multiple by Keys:\n";

// Add more data for this example
$db->insert("temp", [
    ["id" => 1, "value" => "a"],
    ["id" => 2, "value" => "b"],
    ["id" => 3, "value" => "c"],
    ["id" => 4, "value" => "d"],
    ["id" => 5, "value" => "e"],
]);

$result = $db->delete("temp", ["key" => [1, 3]]);  // Delete keys 1 and 3
echo "   Deleted keys [1, 3]: " . $result['n'] . " records\n";
echo "   Remaining in temp: " . $db->count("temp") . "\n";

// -----------------------------------------
// 5. Delete ALL Matching Records
// -----------------------------------------
echo "\n5. Delete ALL Matching Records:\n";

$db->insert("logs", [
    ["level" => "info", "msg" => "Started"],
    ["level" => "error", "msg" => "Failed 1"],
    ["level" => "info", "msg" => "Processing"],
    ["level" => "error", "msg" => "Failed 2"],
    ["level" => "info", "msg" => "Done"],
]);

echo "   Logs before: " . $db->count("logs") . "\n";

$result = $db->delete("logs", ["level" => "error"]);
echo "   Deleted all errors: " . $result['n'] . "\n";
echo "   Logs after: " . $db->count("logs") . "\n";

// -----------------------------------------
// 6. Delete ALL Records (Empty Filter)
// -----------------------------------------
echo "\n6. Delete ALL Records (Empty Filter):\n";

$db->insert("session", [
    ["user" => "u1", "token" => "abc"],
    ["user" => "u2", "token" => "def"],
]);

echo "   Sessions before: " . $db->count("session") . "\n";

$result = $db->delete("session", []);  // Delete ALL
echo "   Deleted all: " . $result['n'] . "\n";
echo "   Sessions after: " . $db->count("session") . "\n";

// -----------------------------------------
// 7. Deleted Records Become NULL
// -----------------------------------------
echo "\n7. Technical Note - Deleted Records:\n";
echo "   - Deleted records become NULL internally\n";
echo "   - They are filtered from find() results\n";
echo "   - Use compact() to reclaim space\n";
echo "   - Keys may be reassigned after compact()\n";

// -----------------------------------------
// 8. Using compact() After Deletes
// -----------------------------------------
echo "\n8. Using compact() After Deletes:\n";

// Current state
$info = $db->getShardInfo("users");
echo "   Before compact - Total records: " . ($info['totalRecords'] ?? 'N/A') . "\n";

$result = $db->compact("users");
echo "   Freed slots: " . $result['freedSlots'] . "\n";
echo "   After compact - Total records: " . $result['totalRecords'] . "\n";

// -----------------------------------------
// 9. Final State
// -----------------------------------------
echo "\n9. Final State:\n";

$users = $db->find("users", 0);
echo "   Remaining users:\n";
foreach ($users as $user) {
    echo "   - " . $user['name'] . " (" . $user['role'] . ")\n";
}

echo "\n=== Delete Examples Complete ===\n";
