<?php
/**
 * noneDB - Aggregation Methods Example
 *
 * Demonstrates count(), sum(), avg(), min(), and max() methods.
 */

include("../noneDB.php");
$db = new noneDB();

echo "=== noneDB Aggregation Methods Example ===\n\n";

// Setup: Insert sample data
$db->insert("employees", [
    ["name" => "Alice", "department" => "Engineering", "salary" => 75000, "years" => 5, "active" => true],
    ["name" => "Bob", "department" => "Engineering", "salary" => 85000, "years" => 8, "active" => true],
    ["name" => "Charlie", "department" => "Sales", "salary" => 65000, "years" => 3, "active" => true],
    ["name" => "David", "department" => "Sales", "salary" => 70000, "years" => 6, "active" => false],
    ["name" => "Eve", "department" => "Engineering", "salary" => 95000, "years" => 10, "active" => true],
    ["name" => "Frank", "department" => "HR", "salary" => 55000, "years" => 2, "active" => true],
    ["name" => "Grace", "department" => "HR", "salary" => 60000, "years" => 4, "active" => true],
    ["name" => "Henry", "department" => "Sales", "salary" => 72000, "years" => 5, "active" => true],
]);

$db->insert("orders", [
    ["product" => "Laptop", "amount" => 1299, "qty" => 2, "status" => "completed"],
    ["product" => "Phone", "amount" => 899, "qty" => 5, "status" => "completed"],
    ["product" => "Tablet", "amount" => 599, "qty" => 3, "status" => "pending"],
    ["product" => "Monitor", "amount" => 399, "qty" => 4, "status" => "completed"],
    ["product" => "Keyboard", "amount" => 149, "qty" => 10, "status" => "completed"],
    ["product" => "Mouse", "amount" => 79, "qty" => 15, "status" => "pending"],
]);

echo "Sample data: 8 employees, 6 orders.\n\n";

// ==========================================
// COUNT
// ==========================================
echo "=== COUNT ===\n\n";

// -----------------------------------------
// 1. Count All Records
// -----------------------------------------
echo "1. Count All Records:\n";

$totalEmployees = $db->count("employees");
echo "   Total employees: " . $totalEmployees . "\n";

$totalOrders = $db->count("orders");
echo "   Total orders: " . $totalOrders . "\n";

// -----------------------------------------
// 2. Count with Filter
// -----------------------------------------
echo "\n2. Count with Filter:\n";

$engineers = $db->count("employees", ["department" => "Engineering"]);
echo "   Engineers: " . $engineers . "\n";

$activeEmployees = $db->count("employees", ["active" => true]);
echo "   Active employees: " . $activeEmployees . "\n";

$completedOrders = $db->count("orders", ["status" => "completed"]);
echo "   Completed orders: " . $completedOrders . "\n";

// ==========================================
// SUM
// ==========================================
echo "\n=== SUM ===\n\n";

// -----------------------------------------
// 3. Sum All Values
// -----------------------------------------
echo "3. Sum All Values:\n";

$totalSalary = $db->sum("employees", "salary");
echo "   Total salary budget: $" . number_format($totalSalary) . "\n";

$totalYears = $db->sum("employees", "years");
echo "   Total years of experience: " . $totalYears . "\n";

$totalOrderAmount = $db->sum("orders", "amount");
echo "   Total order amount: $" . number_format($totalOrderAmount) . "\n";

// -----------------------------------------
// 4. Sum with Filter
// -----------------------------------------
echo "\n4. Sum with Filter:\n";

$engSalary = $db->sum("employees", "salary", ["department" => "Engineering"]);
echo "   Engineering salary budget: $" . number_format($engSalary) . "\n";

$salesSalary = $db->sum("employees", "salary", ["department" => "Sales"]);
echo "   Sales salary budget: $" . number_format($salesSalary) . "\n";

$completedAmount = $db->sum("orders", "amount", ["status" => "completed"]);
echo "   Completed orders amount: $" . number_format($completedAmount) . "\n";

// ==========================================
// AVG (Average)
// ==========================================
echo "\n=== AVG (Average) ===\n\n";

// -----------------------------------------
// 5. Average All Values
// -----------------------------------------
echo "5. Average All Values:\n";

$avgSalary = $db->avg("employees", "salary");
echo "   Average salary: $" . number_format($avgSalary, 2) . "\n";

$avgYears = $db->avg("employees", "years");
echo "   Average years: " . round($avgYears, 1) . "\n";

$avgOrderAmount = $db->avg("orders", "amount");
echo "   Average order amount: $" . number_format($avgOrderAmount, 2) . "\n";

// -----------------------------------------
// 6. Average with Filter
// -----------------------------------------
echo "\n6. Average with Filter:\n";

$avgEngSalary = $db->avg("employees", "salary", ["department" => "Engineering"]);
echo "   Avg Engineering salary: $" . number_format($avgEngSalary, 2) . "\n";

$avgActiveSalary = $db->avg("employees", "salary", ["active" => true]);
echo "   Avg Active employee salary: $" . number_format($avgActiveSalary, 2) . "\n";

// ==========================================
// MIN / MAX
// ==========================================
echo "\n=== MIN / MAX ===\n\n";

// -----------------------------------------
// 7. Min/Max Values
// -----------------------------------------
echo "7. Min/Max Values:\n";

$minSalary = $db->min("employees", "salary");
$maxSalary = $db->max("employees", "salary");
echo "   Salary range: $" . number_format($minSalary) . " - $" . number_format($maxSalary) . "\n";

$minYears = $db->min("employees", "years");
$maxYears = $db->max("employees", "years");
echo "   Experience range: " . $minYears . " - " . $maxYears . " years\n";

$minOrder = $db->min("orders", "amount");
$maxOrder = $db->max("orders", "amount");
echo "   Order amount range: $" . $minOrder . " - $" . $maxOrder . "\n";

// -----------------------------------------
// 8. Min/Max with Filter
// -----------------------------------------
echo "\n8. Min/Max with Filter:\n";

$maxEngSalary = $db->max("employees", "salary", ["department" => "Engineering"]);
echo "   Highest Engineering salary: $" . number_format($maxEngSalary) . "\n";

$minSalesSalary = $db->min("employees", "salary", ["department" => "Sales"]);
echo "   Lowest Sales salary: $" . number_format($minSalesSalary) . "\n";

// ==========================================
// COMBINED EXAMPLES
// ==========================================
echo "\n=== COMBINED EXAMPLES ===\n\n";

// -----------------------------------------
// 9. Department Summary
// -----------------------------------------
echo "9. Department Summary:\n";

$departments = $db->distinct("employees", "department");
echo "   +---------------+-------+------------+------------+\n";
echo "   | Department    | Count | Avg Salary | Total      |\n";
echo "   +---------------+-------+------------+------------+\n";

foreach ($departments as $dept) {
    $count = $db->count("employees", ["department" => $dept]);
    $avg = $db->avg("employees", "salary", ["department" => $dept]);
    $sum = $db->sum("employees", "salary", ["department" => $dept]);
    printf("   | %-13s | %5d | $%9s | $%9s |\n",
        $dept, $count, number_format($avg), number_format($sum));
}
echo "   +---------------+-------+------------+------------+\n";

// -----------------------------------------
// 10. Order Statistics
// -----------------------------------------
echo "\n10. Order Statistics:\n";

$pending = $db->count("orders", ["status" => "pending"]);
$completed = $db->count("orders", ["status" => "completed"]);
$pendingAmount = $db->sum("orders", "amount", ["status" => "pending"]);
$completedAmount = $db->sum("orders", "amount", ["status" => "completed"]);

echo "   Pending: " . $pending . " orders ($" . number_format($pendingAmount) . ")\n";
echo "   Completed: " . $completed . " orders ($" . number_format($completedAmount) . ")\n";
echo "   Total: " . ($pending + $completed) . " orders ($" . number_format($pendingAmount + $completedAmount) . ")\n";

echo "\n=== Aggregation Methods Examples Complete ===\n";
