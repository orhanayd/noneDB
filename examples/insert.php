<?php
/**
 * noneDB - Insert Example
 *
 * Demonstrates single record insertion with various data types.
 */

include("../noneDB.php");
$db = new noneDB();

echo "=== noneDB Insert Example ===\n\n";

// -----------------------------------------
// 1. Simple Insert
// -----------------------------------------
echo "1. Simple Insert:\n";

$user = [
    "name" => "John Doe",
    "email" => "john@example.com",
    "age" => 28
];

$result = $db->insert("users", $user);
echo "   Result: " . json_encode($result) . "\n";
// Output: {"n":1}

// -----------------------------------------
// 2. Insert with Nested Data
// -----------------------------------------
echo "\n2. Insert with Nested Data:\n";

$profile = [
    "name" => "Jane Smith",
    "email" => "jane@example.com",
    "address" => [
        "street" => "123 Main St",
        "city" => "Istanbul",
        "country" => "Turkey"
    ],
    "tags" => ["developer", "designer"]
];

$result = $db->insert("users", $profile);
echo "   Result: " . json_encode($result) . "\n";

// -----------------------------------------
// 3. Insert with Various Data Types
// -----------------------------------------
echo "\n3. Insert with Various Data Types:\n";

$product = [
    "name" => "Laptop Pro",
    "price" => 1299.99,           // Float
    "stock" => 50,                 // Integer
    "active" => true,              // Boolean
    "specs" => [                   // Nested array
        "cpu" => "Intel i7",
        "ram" => 16,
        "storage" => "512GB SSD"
    ],
    "created_at" => time()         // Timestamp
];

$result = $db->insert("products", $product);
echo "   Result: " . json_encode($result) . "\n";

// -----------------------------------------
// 4. Verify Inserted Data
// -----------------------------------------
echo "\n4. Verify Inserted Data:\n";

$users = $db->find("users", 0);
echo "   Total users: " . count($users) . "\n";

$firstUser = $db->first("users");
echo "   First user: " . json_encode($firstUser) . "\n";

// -----------------------------------------
// 5. Error Handling - Reserved 'key' Field
// -----------------------------------------
echo "\n5. Error Handling - Reserved 'key' Field:\n";

$invalid = [
    "key" => "my-value",  // ERROR: 'key' is reserved
    "name" => "Test"
];

$result = $db->insert("users", $invalid);
echo "   Result: " . json_encode($result) . "\n";
// Output: {"n":0,"error":"You cannot set key name to key"}

// -----------------------------------------
// 6. Nested 'key' Field is OK
// -----------------------------------------
echo "\n6. Nested 'key' Field is OK:\n";

$valid = [
    "name" => "API Config",
    "settings" => [
        "key" => "api-key-12345",  // OK: nested 'key' is allowed
        "secret" => "xxxxx"
    ]
];

$result = $db->insert("configs", $valid);
echo "   Result: " . json_encode($result) . "\n";

echo "\n=== Insert Examples Complete ===\n";
