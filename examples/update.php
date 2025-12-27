<?php
/**
 * noneDB - Update Example
 *
 * Demonstrates various ways to update records.
 */

include("../noneDB.php");
$db = new noneDB();

echo "=== noneDB Update Example ===\n\n";

// Setup: Insert sample data
$db->insert("users", [
    ["name" => "Alice", "email" => "alice@example.com", "age" => 25, "status" => "active"],
    ["name" => "Bob", "email" => "bob@example.com", "age" => 30, "status" => "active"],
    ["name" => "Charlie", "email" => "charlie@example.com", "age" => 35, "status" => "active"],
    ["name" => "David", "email" => "david@example.com", "age" => 28, "status" => "pending"],
    ["name" => "Eve", "email" => "eve@example.com", "age" => 22, "status" => "active"],
]);

echo "Initial data inserted: 5 users\n\n";

// -----------------------------------------
// 1. Update by Field Value
// -----------------------------------------
echo "1. Update by Field Value:\n";

$result = $db->update("users", [
    ["name" => "Alice"],                           // Filter: find Alice
    ["set" => ["email" => "alice.new@example.com"]] // Set new email
]);
echo "   Updated: " . $result['n'] . " record(s)\n";

// Verify
$alice = $db->find("users", ["name" => "Alice"]);
echo "   New email: " . $alice[0]['email'] . "\n";

// -----------------------------------------
// 2. Update Multiple Fields at Once
// -----------------------------------------
echo "\n2. Update Multiple Fields at Once:\n";

$result = $db->update("users", [
    ["name" => "Bob"],
    ["set" => [
        "age" => 31,
        "status" => "premium",
        "updated_at" => time()
    ]]
]);
echo "   Updated: " . $result['n'] . " record(s)\n";

$bob = $db->find("users", ["name" => "Bob"]);
echo "   Bob's new data: age=" . $bob[0]['age'] . ", status=" . $bob[0]['status'] . "\n";

// -----------------------------------------
// 3. Update by Key (Index)
// -----------------------------------------
echo "\n3. Update by Key (Index):\n";

$result = $db->update("users", [
    ["key" => 2],                    // Update record at index 2 (Charlie)
    ["set" => ["verified" => true]]
]);
echo "   Updated: " . $result['n'] . " record(s)\n";

$charlie = $db->find("users", ["key" => 2]);
echo "   Charlie verified: " . ($charlie[0]['verified'] ? "true" : "false") . "\n";

// -----------------------------------------
// 4. Update Multiple Records by Key
// -----------------------------------------
echo "\n4. Update Multiple Records by Key:\n";

$result = $db->update("users", [
    ["key" => [0, 1, 2]],            // Update keys 0, 1, 2
    ["set" => ["tier" => "gold"]]
]);
echo "   Updated: " . $result['n'] . " record(s)\n";

// -----------------------------------------
// 5. Update ALL Records Matching a Condition
// -----------------------------------------
echo "\n5. Update ALL Records Matching a Condition:\n";

$result = $db->update("users", [
    ["status" => "active"],          // All active users
    ["set" => ["newsletter" => true]]
]);
echo "   Updated: " . $result['n'] . " record(s) with newsletter=true\n";

// -----------------------------------------
// 6. Update ALL Records (Empty Filter)
// -----------------------------------------
echo "\n6. Update ALL Records (Empty Filter):\n";

$result = $db->update("users", [
    [],                              // Empty filter = all records
    ["set" => ["last_check" => date("Y-m-d")]]
]);
echo "   Updated: " . $result['n'] . " record(s)\n";

// -----------------------------------------
// 7. Add New Field to Existing Records
// -----------------------------------------
echo "\n7. Add New Field to Existing Records:\n";

$result = $db->update("users", [
    ["name" => "Eve"],
    ["set" => ["phone" => "+90-555-1234"]]  // New field
]);
echo "   Added phone to Eve\n";

$eve = $db->find("users", ["name" => "Eve"]);
echo "   Eve's phone: " . $eve[0]['phone'] . "\n";

// -----------------------------------------
// 8. Error: Cannot Set 'key' Field
// -----------------------------------------
echo "\n8. Error: Cannot Set 'key' Field:\n";

$result = $db->update("users", [
    ["name" => "Alice"],
    ["set" => ["key" => 999]]  // ERROR: 'key' is reserved
]);
echo "   Result: " . json_encode($result) . "\n";
// Output: {"n":0,"error":"Please check your update paramters"}

// -----------------------------------------
// 9. Show Final Data
// -----------------------------------------
echo "\n9. Final Data:\n";

$all = $db->find("users", 0);
foreach ($all as $user) {
    echo "   [" . $user['key'] . "] " . $user['name'];
    echo " - " . $user['email'];
    echo " (status: " . $user['status'] . ")\n";
}

echo "\n=== Update Examples Complete ===\n";
