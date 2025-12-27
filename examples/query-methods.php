<?php
/**
 * noneDB - Query Methods Example
 *
 * Demonstrates distinct(), like(), and between() methods.
 */

include("../noneDB.php");
$db = new noneDB();

echo "=== noneDB Query Methods Example ===\n\n";

// Setup: Insert sample data
$db->insert("users", [
    ["name" => "Alice Johnson", "email" => "alice@gmail.com", "age" => 25, "city" => "Istanbul", "salary" => 5000],
    ["name" => "Bob Smith", "email" => "bob@yahoo.com", "age" => 30, "city" => "Ankara", "salary" => 6500],
    ["name" => "Charlie Brown", "email" => "charlie@gmail.com", "age" => 35, "city" => "Istanbul", "salary" => 7500],
    ["name" => "David Wilson", "email" => "david@hotmail.com", "age" => 28, "city" => "Izmir", "salary" => 5500],
    ["name" => "Eve Johnson", "email" => "eve@gmail.com", "age" => 22, "city" => "Istanbul", "salary" => 4500],
    ["name" => "Frank Miller", "email" => "frank@yahoo.com", "age" => 40, "city" => "Ankara", "salary" => 8000],
    ["name" => "Grace Lee", "email" => "grace@company.com", "age" => 32, "city" => "Bursa", "salary" => 6000],
]);

echo "Sample data: 7 users with various cities, emails, and salaries.\n\n";

// ==========================================
// DISTINCT
// ==========================================
echo "=== DISTINCT ===\n\n";

// -----------------------------------------
// 1. Get Unique Cities
// -----------------------------------------
echo "1. Get Unique Cities:\n";

$cities = $db->distinct("users", "city");
echo "   Cities: " . json_encode($cities) . "\n";
echo "   Count: " . count($cities) . " unique cities\n";

// -----------------------------------------
// 2. Get Unique Email Domains
// -----------------------------------------
echo "\n2. Distinct on Nested Processing:\n";

$emails = $db->distinct("users", "email");
echo "   Emails: " . json_encode($emails) . "\n";

// Extract domains
$domains = [];
foreach ($emails as $email) {
    $domain = explode("@", $email)[1];
    if (!in_array($domain, $domains)) {
        $domains[] = $domain;
    }
}
echo "   Domains: " . json_encode($domains) . "\n";

// ==========================================
// LIKE (Pattern Matching)
// ==========================================
echo "\n=== LIKE (Pattern Matching) ===\n\n";

// -----------------------------------------
// 3. Contains Search
// -----------------------------------------
echo "3. Contains Search:\n";

$gmail = $db->like("users", "email", "gmail");
echo "   Gmail users: " . count($gmail) . "\n";
foreach ($gmail as $user) {
    echo "   - " . $user['name'] . " (" . $user['email'] . ")\n";
}

// -----------------------------------------
// 4. Starts With (^pattern)
// -----------------------------------------
echo "\n4. Starts With (^pattern):\n";

$startsWithA = $db->like("users", "name", "^A");
echo "   Names starting with 'A':\n";
foreach ($startsWithA as $user) {
    echo "   - " . $user['name'] . "\n";
}

$startsWithD = $db->like("users", "name", "^D");
echo "   Names starting with 'D':\n";
foreach ($startsWithD as $user) {
    echo "   - " . $user['name'] . "\n";
}

// -----------------------------------------
// 5. Ends With (pattern$)
// -----------------------------------------
echo "\n5. Ends With (pattern\$):\n";

$endsWithSon = $db->like("users", "name", "son$");
echo "   Names ending with 'son':\n";
foreach ($endsWithSon as $user) {
    echo "   - " . $user['name'] . "\n";
}

$yahooUsers = $db->like("users", "email", "yahoo.com$");
echo "   Yahoo email users:\n";
foreach ($yahooUsers as $user) {
    echo "   - " . $user['name'] . " (" . $user['email'] . ")\n";
}

// -----------------------------------------
// 6. Case Insensitive Search
// -----------------------------------------
echo "\n6. Case Insensitive Search:\n";

$brown = $db->like("users", "name", "brown");  // lowercase
echo "   Search 'brown' (case insensitive): ";
foreach ($brown as $user) {
    echo $user['name'] . " ";
}
echo "\n";

// ==========================================
// BETWEEN (Range Query)
// ==========================================
echo "\n=== BETWEEN (Range Query) ===\n\n";

// -----------------------------------------
// 7. Basic Range Query
// -----------------------------------------
echo "7. Basic Range Query (Age 25-35):\n";

$midAge = $db->between("users", "age", 25, 35);
echo "   Users aged 25-35:\n";
foreach ($midAge as $user) {
    echo "   - " . $user['name'] . " (age: " . $user['age'] . ")\n";
}

// -----------------------------------------
// 8. Salary Range
// -----------------------------------------
echo "\n8. Salary Range (5000-6500):\n";

$midSalary = $db->between("users", "salary", 5000, 6500);
echo "   Users with salary 5000-6500:\n";
foreach ($midSalary as $user) {
    echo "   - " . $user['name'] . " (salary: " . $user['salary'] . ")\n";
}

// -----------------------------------------
// 9. Between with Additional Filter
// -----------------------------------------
echo "\n9. Between with Additional Filter:\n";

// Istanbul users with salary 4000-6000
$istanbulMidSalary = $db->between("users", "salary", 4000, 6000, ["city" => "Istanbul"]);
echo "   Istanbul users with salary 4000-6000:\n";
foreach ($istanbulMidSalary as $user) {
    echo "   - " . $user['name'] . " (" . $user['city'] . ", " . $user['salary'] . ")\n";
}

// -----------------------------------------
// 10. Combining Query Methods
// -----------------------------------------
echo "\n10. Combining Query Methods:\n";

// Get all Gmail users, then filter by age range
$gmailUsers = $db->like("users", "email", "gmail");
$youngGmail = [];
foreach ($gmailUsers as $user) {
    if ($user['age'] >= 20 && $user['age'] <= 30) {
        $youngGmail[] = $user;
    }
}
echo "   Young (20-30) Gmail users:\n";
foreach ($youngGmail as $user) {
    echo "   - " . $user['name'] . " (age: " . $user['age'] . ")\n";
}

echo "\n=== Query Methods Examples Complete ===\n";
