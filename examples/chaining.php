<?php
/**
 * noneDB - Method Chaining (Fluent Interface) Example
 *
 * Demonstrates the query builder pattern for fluent method chaining.
 */

include("../noneDB.php");
$db = new noneDB();

echo "=== noneDB Method Chaining Example ===\n\n";

// Setup: Insert sample data
$db->insert("users", [
    ["name" => "Alice", "age" => 25, "score" => 85, "city" => "Istanbul", "email" => "alice@gmail.com", "active" => true],
    ["name" => "Bob", "age" => 30, "score" => 90, "city" => "Ankara", "email" => "bob@yahoo.com", "active" => true],
    ["name" => "Charlie", "age" => 35, "score" => 75, "city" => "Istanbul", "email" => "charlie@gmail.com", "active" => false],
    ["name" => "David", "age" => 28, "score" => 95, "city" => "Izmir", "email" => "david@hotmail.com", "active" => true],
    ["name" => "Eve", "age" => 22, "score" => 80, "city" => "Istanbul", "email" => "eve@gmail.com", "active" => true],
    ["name" => "Frank", "age" => 40, "score" => 70, "city" => "Ankara", "email" => "frank@yahoo.com", "active" => true],
    ["name" => "Grace", "age" => 33, "score" => 88, "city" => "Bursa", "email" => "grace@company.com", "active" => true],
]);

echo "Sample data: 7 users\n\n";

// ==========================================
// BASIC QUERIES
// ==========================================
echo "=== BASIC QUERIES ===\n\n";

// 1. Get all records
echo "1. Get all records:\n";
$all = $db->query("users")->get();
echo "   Count: " . count($all) . "\n";

// 2. Simple filter
echo "\n2. Simple filter (Istanbul users):\n";
$istanbul = $db->query("users")
    ->where(["city" => "Istanbul"])
    ->get();
echo "   Found: " . count($istanbul) . " users\n";

// ==========================================
// CHAINED QUERIES
// ==========================================
echo "\n=== CHAINED QUERIES ===\n\n";

// 3. Multiple conditions
echo "3. Multiple conditions (active + Istanbul):\n";
$activeIstanbul = $db->query("users")
    ->where(["city" => "Istanbul", "active" => true])
    ->get();
foreach ($activeIstanbul as $user) {
    echo "   - " . $user['name'] . "\n";
}

// 4. Where + Sort + Limit
echo "\n4. Top 3 scorers:\n";
$topScorers = $db->query("users")
    ->where(["active" => true])
    ->sort("score", "desc")
    ->limit(3)
    ->get();
$rank = 1;
foreach ($topScorers as $user) {
    echo "   " . $rank++ . ". " . $user['name'] . " (score: " . $user['score'] . ")\n";
}

// 5. Like pattern matching
echo "\n5. Gmail users:\n";
$gmail = $db->query("users")
    ->like("email", "gmail")
    ->get();
foreach ($gmail as $user) {
    echo "   - " . $user['name'] . " (" . $user['email'] . ")\n";
}

// 6. Between range
echo "\n6. Users aged 25-35:\n";
$midAge = $db->query("users")
    ->between("age", 25, 35)
    ->sort("age")
    ->get();
foreach ($midAge as $user) {
    echo "   - " . $user['name'] . " (age: " . $user['age'] . ")\n";
}

// 7. Complex chain
echo "\n7. Complex query (active, age 20-35, high score, top 3):\n";
$complex = $db->query("users")
    ->where(["active" => true])
    ->between("age", 20, 35)
    ->sort("score", "desc")
    ->limit(3)
    ->get();
foreach ($complex as $user) {
    echo "   - " . $user['name'] . " (age: " . $user['age'] . ", score: " . $user['score'] . ")\n";
}

// ==========================================
// TERMINAL METHODS
// ==========================================
echo "\n=== TERMINAL METHODS ===\n\n";

// 8. first() and last()
echo "8. First and Last:\n";
$first = $db->query("users")->sort("age")->first();
$last = $db->query("users")->sort("age")->last();
echo "   Youngest: " . $first['name'] . " (age: " . $first['age'] . ")\n";
echo "   Oldest: " . $last['name'] . " (age: " . $last['age'] . ")\n";

// 9. count()
echo "\n9. Count:\n";
$total = $db->query("users")->count();
$active = $db->query("users")->where(["active" => true])->count();
echo "   Total users: " . $total . "\n";
echo "   Active users: " . $active . "\n";

// 10. exists()
echo "\n10. Exists check:\n";
$aliceExists = $db->query("users")->where(["name" => "Alice"])->exists();
$johnExists = $db->query("users")->where(["name" => "John"])->exists();
echo "   Alice exists: " . ($aliceExists ? "true" : "false") . "\n";
echo "   John exists: " . ($johnExists ? "true" : "false") . "\n";

// ==========================================
// AGGREGATION
// ==========================================
echo "\n=== AGGREGATION ===\n\n";

// 11. sum()
echo "11. Sum:\n";
$totalScore = $db->query("users")->sum("score");
echo "   Total score: " . $totalScore . "\n";

// 12. avg()
echo "\n12. Average:\n";
$avgAge = $db->query("users")->avg("age");
$avgScore = $db->query("users")->where(["active" => true])->avg("score");
echo "   Average age: " . round($avgAge, 1) . "\n";
echo "   Average score (active): " . round($avgScore, 1) . "\n";

// 13. min() / max()
echo "\n13. Min/Max:\n";
$minAge = $db->query("users")->min("age");
$maxScore = $db->query("users")->max("score");
echo "   Min age: " . $minAge . "\n";
echo "   Max score: " . $maxScore . "\n";

// 14. distinct()
echo "\n14. Distinct:\n";
$cities = $db->query("users")->distinct("city");
echo "   Cities: " . implode(", ", $cities) . "\n";

// ==========================================
// PAGINATION
// ==========================================
echo "\n=== PAGINATION ===\n\n";

$pageSize = 3;
$totalUsers = $db->query("users")->count();
$totalPages = ceil($totalUsers / $pageSize);

echo "15. Pagination (page size: $pageSize):\n";
for ($page = 1; $page <= $totalPages; $page++) {
    $offset = ($page - 1) * $pageSize;
    $users = $db->query("users")
        ->sort("name")
        ->limit($pageSize)
        ->offset($offset)
        ->get();

    echo "   Page $page: ";
    $names = array_map(fn($u) => $u['name'], $users);
    echo implode(", ", $names) . "\n";
}

// ==========================================
// WRITE OPERATIONS
// ==========================================
echo "\n=== WRITE OPERATIONS ===\n\n";

// 16. Update with chaining
echo "16. Update with chaining:\n";
$result = $db->query("users")
    ->where(["city" => "Istanbul"])
    ->update(["verified" => true]);
echo "   Updated " . $result['n'] . " Istanbul users with verified=true\n";

// 17. Conditional update
echo "\n17. Conditional update:\n";
$result = $db->query("users")
    ->where(["active" => true])
    ->between("score", 85, 100)
    ->update(["tier" => "gold"]);
echo "   Updated " . $result['n'] . " high scorers with tier=gold\n";

// 18. Delete with chaining
echo "\n18. Delete with chaining:\n";
$before = $db->query("users")->count();
$result = $db->query("users")
    ->where(["active" => false])
    ->delete();
$after = $db->query("users")->count();
echo "   Deleted " . $result['n'] . " inactive users\n";
echo "   Users before: $before, after: $after\n";

// ==========================================
// COMPARISON: OLD vs NEW API
// ==========================================
echo "\n=== OLD vs NEW API ===\n\n";

echo "OLD API (still works):\n";
echo '   $results = $db->find("users", ["active" => true]);' . "\n";
echo '   $sorted = $db->sort($results, "score", "desc");' . "\n";
echo '   $limited = $db->limit($sorted, 5);' . "\n";

echo "\nNEW API (fluent chaining):\n";
echo '   $results = $db->query("users")' . "\n";
echo '       ->where(["active" => true])' . "\n";
echo '       ->sort("score", "desc")' . "\n";
echo '       ->limit(5)' . "\n";
echo '       ->get();' . "\n";

echo "\n=== Method Chaining Examples Complete ===\n";
