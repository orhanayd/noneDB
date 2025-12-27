<?php
/**
 * noneDB - Utility Methods Example
 *
 * Demonstrates first(), last(), exists(), sort(), and limit() methods.
 */

include("../noneDB.php");
$db = new noneDB();

echo "=== noneDB Utility Methods Example ===\n\n";

// Setup: Insert sample data
$db->insert("users", [
    ["name" => "Alice", "age" => 25, "score" => 85, "registered" => "2023-01-15"],
    ["name" => "Bob", "age" => 30, "score" => 92, "registered" => "2023-02-20"],
    ["name" => "Charlie", "age" => 35, "score" => 78, "registered" => "2023-03-10"],
    ["name" => "David", "age" => 28, "score" => 95, "registered" => "2023-04-05"],
    ["name" => "Eve", "age" => 22, "score" => 88, "registered" => "2023-05-12"],
    ["name" => "Frank", "age" => 40, "score" => 72, "registered" => "2023-06-01"],
    ["name" => "Grace", "age" => 33, "score" => 90, "registered" => "2023-07-18"],
]);

echo "Sample data: 7 users with various ages and scores.\n\n";

// ==========================================
// FIRST
// ==========================================
echo "=== FIRST ===\n\n";

// -----------------------------------------
// 1. Get First Record
// -----------------------------------------
echo "1. Get First Record:\n";

$first = $db->first("users");
echo "   First user: " . $first['name'] . " (key: " . $first['key'] . ")\n";

// -----------------------------------------
// 2. First with Filter
// -----------------------------------------
echo "\n2. First with Filter:\n";

$firstOlder30 = $db->first("users", ["age" => 35]);
if ($firstOlder30) {
    echo "   First user age 35: " . $firstOlder30['name'] . "\n";
}

// No match returns null
$firstAge100 = $db->first("users", ["age" => 100]);
echo "   First user age 100: " . ($firstAge100 === null ? "null (not found)" : $firstAge100['name']) . "\n";

// ==========================================
// LAST
// ==========================================
echo "\n=== LAST ===\n\n";

// -----------------------------------------
// 3. Get Last Record
// -----------------------------------------
echo "3. Get Last Record:\n";

$last = $db->last("users");
echo "   Last user: " . $last['name'] . " (key: " . $last['key'] . ")\n";

// -----------------------------------------
// 4. Last with Filter
// -----------------------------------------
echo "\n4. Last with Filter:\n";

// Add another user with same age
$db->insert("users", ["name" => "Helen", "age" => 25, "score" => 80, "registered" => "2023-08-01"]);

$lastAge25 = $db->last("users", ["age" => 25]);
echo "   Last user age 25: " . $lastAge25['name'] . "\n";  // Helen (not Alice)

// ==========================================
// EXISTS
// ==========================================
echo "\n=== EXISTS ===\n\n";

// -----------------------------------------
// 5. Check if Record Exists
// -----------------------------------------
echo "5. Check if Record Exists:\n";

$aliceExists = $db->exists("users", ["name" => "Alice"]);
echo "   Alice exists: " . ($aliceExists ? "true" : "false") . "\n";

$johnExists = $db->exists("users", ["name" => "John"]);
echo "   John exists: " . ($johnExists ? "true" : "false") . "\n";

// -----------------------------------------
// 6. Exists with Multiple Conditions
// -----------------------------------------
echo "\n6. Exists with Multiple Conditions:\n";

$youngHighScore = $db->exists("users", ["age" => 22, "score" => 88]);
echo "   User with age=22 AND score=88: " . ($youngHighScore ? "true" : "false") . "\n";

$oldHighScore = $db->exists("users", ["age" => 40, "score" => 95]);
echo "   User with age=40 AND score=95: " . ($oldHighScore ? "true" : "false") . "\n";

// -----------------------------------------
// 7. Using exists() for Conditional Logic
// -----------------------------------------
echo "\n7. Using exists() for Conditional Logic:\n";

$email = "newuser@test.com";
if (!$db->exists("users", ["email" => $email])) {
    echo "   Email '$email' is available for registration.\n";
}

// ==========================================
// SORT
// ==========================================
echo "\n=== SORT ===\n\n";

// -----------------------------------------
// 8. Sort Ascending
// -----------------------------------------
echo "8. Sort Ascending (by age):\n";

$all = $db->find("users", 0);
$sortedAsc = $db->sort($all, "age", "asc");

echo "   Youngest to oldest:\n";
foreach ($sortedAsc as $user) {
    echo "   - " . $user['name'] . " (age: " . $user['age'] . ")\n";
}

// -----------------------------------------
// 9. Sort Descending
// -----------------------------------------
echo "\n9. Sort Descending (by score):\n";

$sortedDesc = $db->sort($all, "score", "desc");

echo "   Highest to lowest score:\n";
$top3 = array_slice($sortedDesc, 0, 3);
foreach ($top3 as $user) {
    echo "   - " . $user['name'] . " (score: " . $user['score'] . ")\n";
}

// -----------------------------------------
// 10. Sort by String Field
// -----------------------------------------
echo "\n10. Sort by String Field (name):\n";

$sortedByName = $db->sort($all, "name", "asc");

echo "   Alphabetically:\n";
foreach ($sortedByName as $user) {
    echo "   - " . $user['name'] . "\n";
}

// ==========================================
// LIMIT
// ==========================================
echo "\n=== LIMIT ===\n\n";

// -----------------------------------------
// 11. Basic Limit
// -----------------------------------------
echo "11. Basic Limit:\n";

$all = $db->find("users", 0);
$first3 = $db->limit($all, 3);

echo "   First 3 users:\n";
foreach ($first3 as $user) {
    echo "   - " . $user['name'] . "\n";
}

// -----------------------------------------
// 12. Limit with Sort (Top N)
// -----------------------------------------
echo "\n12. Limit with Sort (Top 3 Scores):\n";

$sorted = $db->sort($all, "score", "desc");
$top3 = $db->limit($sorted, 3);

echo "   Top 3 scorers:\n";
$rank = 1;
foreach ($top3 as $user) {
    echo "   " . $rank++ . ". " . $user['name'] . " (score: " . $user['score'] . ")\n";
}

// -----------------------------------------
// 13. Pagination Simulation
// -----------------------------------------
echo "\n13. Pagination Simulation:\n";

$pageSize = 3;
$all = $db->find("users", 0);
$totalPages = ceil(count($all) / $pageSize);

echo "   Total records: " . count($all) . "\n";
echo "   Page size: " . $pageSize . "\n";
echo "   Total pages: " . $totalPages . "\n\n";

for ($page = 1; $page <= $totalPages; $page++) {
    $offset = ($page - 1) * $pageSize;
    $pageData = array_slice($all, $offset, $pageSize);

    echo "   Page " . $page . ": ";
    foreach ($pageData as $user) {
        echo $user['name'] . " ";
    }
    echo "\n";
}

// ==========================================
// COMBINED EXAMPLES
// ==========================================
echo "\n=== COMBINED EXAMPLES ===\n\n";

// -----------------------------------------
// 14. Get Newest User
// -----------------------------------------
echo "14. Get Newest User:\n";

$all = $db->find("users", 0);
$sortedByDate = $db->sort($all, "registered", "desc");
$newest = $db->limit($sortedByDate, 1);

echo "   Newest user: " . $newest[0]['name'] . " (registered: " . $newest[0]['registered'] . ")\n";

// -----------------------------------------
// 15. Young Users with High Scores
// -----------------------------------------
echo "\n15. Top Young Users (age < 30, sorted by score):\n";

$all = $db->find("users", 0);
$young = array_filter($all, fn($u) => $u['age'] < 30);
$youngSorted = $db->sort(array_values($young), "score", "desc");
$topYoung = $db->limit($youngSorted, 3);

foreach ($topYoung as $user) {
    echo "   - " . $user['name'] . " (age: " . $user['age'] . ", score: " . $user['score'] . ")\n";
}

echo "\n=== Utility Methods Examples Complete ===\n";
