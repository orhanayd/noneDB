<?php
/**
 * noneDB - Multiple Insert (Batch Insert) Example
 *
 * Demonstrates inserting multiple records at once.
 */

include("../noneDB.php");
$db = new noneDB();

echo "=== noneDB Multiple Insert Example ===\n\n";

// -----------------------------------------
// 1. Basic Batch Insert
// -----------------------------------------
echo "1. Basic Batch Insert:\n";

$users = [
    ["name" => "Alice", "email" => "alice@example.com", "age" => 25],
    ["name" => "Bob", "email" => "bob@example.com", "age" => 30],
    ["name" => "Charlie", "email" => "charlie@example.com", "age" => 35],
];

$result = $db->insert("employees", $users);
echo "   Inserted: " . $result['n'] . " records\n";
// Output: Inserted: 3 records

// -----------------------------------------
// 2. Large Batch Insert
// -----------------------------------------
echo "\n2. Large Batch Insert (1000 records):\n";

$products = [];
for ($i = 1; $i <= 1000; $i++) {
    $products[] = [
        "sku" => "PROD-" . str_pad($i, 5, "0", STR_PAD_LEFT),
        "name" => "Product " . $i,
        "price" => rand(10, 1000) + (rand(0, 99) / 100),
        "stock" => rand(0, 500),
        "category" => ["Electronics", "Clothing", "Food", "Books"][rand(0, 3)],
        "active" => rand(0, 1) === 1
    ];
}

$start = microtime(true);
$result = $db->insert("products", $products);
$time = round((microtime(true) - $start) * 1000, 2);

echo "   Inserted: " . $result['n'] . " records in " . $time . " ms\n";

// -----------------------------------------
// 3. Insert with Mixed Data Structures
// -----------------------------------------
echo "\n3. Insert with Mixed Data Structures:\n";

$orders = [
    [
        "order_id" => "ORD-001",
        "customer" => ["name" => "John", "email" => "john@test.com"],
        "items" => [
            ["product" => "Laptop", "qty" => 1, "price" => 999],
            ["product" => "Mouse", "qty" => 2, "price" => 25]
        ],
        "total" => 1049,
        "status" => "pending"
    ],
    [
        "order_id" => "ORD-002",
        "customer" => ["name" => "Jane", "email" => "jane@test.com"],
        "items" => [
            ["product" => "Keyboard", "qty" => 1, "price" => 150]
        ],
        "total" => 150,
        "status" => "shipped"
    ]
];

$result = $db->insert("orders", $orders);
echo "   Inserted: " . $result['n'] . " orders\n";

// Verify
$allOrders = $db->find("orders", 0);
echo "   First order items: " . count($allOrders[0]['items']) . "\n";

// -----------------------------------------
// 4. Verify All Data
// -----------------------------------------
echo "\n4. Verify Inserted Data:\n";

$employees = $db->find("employees", 0);
echo "   Employees: " . count($employees) . "\n";

$allProducts = $db->find("products", 0);
echo "   Products: " . count($allProducts) . "\n";

$allOrders = $db->find("orders", 0);
echo "   Orders: " . count($allOrders) . "\n";

// -----------------------------------------
// 5. Performance Tips
// -----------------------------------------
echo "\n5. Performance Tips:\n";
echo "   - Batch inserts are faster than individual inserts\n";
echo "   - For 10K+ records, consider chunking (e.g., 1000 at a time)\n";
echo "   - Auto-sharding kicks in at " . $db->getShardSize() . " records\n";

echo "\n=== Multiple Insert Examples Complete ===\n";
