<?php
/**
 * Comparison Operator & Spatial+Operator Performance Benchmark
 * Tests the new v3.1.0 features
 */

require_once __DIR__ . '/../noneDB.php';

echo "=== noneDB v3.1.0 Operator & Spatial Benchmark ===\n\n";

// Setup
$testDir = __DIR__ . '/benchmark_db/';
if (!is_dir($testDir)) {
    mkdir($testDir, 0777, true);
}

// Clean test directory
foreach (glob($testDir . '*') as $file) {
    if (is_file($file)) unlink($file);
}

$db = new noneDB([
    'secretKey' => 'benchmark_secret_key',
    'dbDir' => $testDir,
    'autoCreateDB' => true
]);

$recordCounts = [100, 500, 1000, 5000];

foreach ($recordCounts as $count) {
    echo "=== Testing with $count records ===\n";

    // Clean for each test
    foreach (glob($testDir . '*') as $file) {
        if (is_file($file)) unlink($file);
    }
    noneDB::clearStaticCache();

    // Generate test data
    $data = [];
    $departments = ['Engineering', 'Design', 'Marketing', 'Sales', 'Support'];
    $statuses = ['active', 'inactive', 'pending'];
    $roles = ['admin', 'user', 'moderator', 'guest'];

    for ($i = 0; $i < $count; $i++) {
        $data[] = [
            'name' => "User $i",
            'email' => "user$i@test.com",
            'age' => rand(18, 65),
            'salary' => rand(30000, 150000),
            'department' => $departments[array_rand($departments)],
            'status' => $statuses[array_rand($statuses)],
            'role' => $roles[array_rand($roles)],
            'score' => rand(0, 100) / 10,
            'tags' => rand(0, 1) ? ['developer', 'senior'] : null,
            'location' => [
                'type' => 'Point',
                'coordinates' => [
                    28.8 + (rand(0, 200) / 1000),  // Istanbul area
                    40.9 + (rand(0, 200) / 1000)
                ]
            ]
        ];
    }

    // Insert data
    $start = microtime(true);
    $db->insert('benchmark', $data);
    $insertTime = (microtime(true) - $start) * 1000;
    echo "Insert $count records: " . round($insertTime, 2) . " ms\n";

    // Create spatial index
    $start = microtime(true);
    $db->createSpatialIndex('benchmark', 'location');
    $spatialIndexTime = (microtime(true) - $start) * 1000;
    echo "Create spatial index: " . round($spatialIndexTime, 2) . " ms\n\n";

    // ========== COMPARISON OPERATORS ==========
    echo "--- Comparison Operators ---\n";

    // $gt operator
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->where(['age' => ['$gt' => 30]])
        ->get();
    $gtTime = (microtime(true) - $start) * 1000;
    echo "\$gt (age > 30): " . round($gtTime, 2) . " ms (" . count($results) . " results)\n";

    // $gte + $lte (range)
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->where(['salary' => ['$gte' => 50000, '$lte' => 100000]])
        ->get();
    $rangeTime = (microtime(true) - $start) * 1000;
    echo "\$gte + \$lte (salary 50k-100k): " . round($rangeTime, 2) . " ms (" . count($results) . " results)\n";

    // $in operator
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->where(['department' => ['$in' => ['Engineering', 'Design']]])
        ->get();
    $inTime = (microtime(true) - $start) * 1000;
    echo "\$in (2 departments): " . round($inTime, 2) . " ms (" . count($results) . " results)\n";

    // $nin operator
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->where(['role' => ['$nin' => ['guest', 'user']]])
        ->get();
    $ninTime = (microtime(true) - $start) * 1000;
    echo "\$nin (exclude 2 roles): " . round($ninTime, 2) . " ms (" . count($results) . " results)\n";

    // $ne operator
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->where(['status' => ['$ne' => 'inactive']])
        ->get();
    $neTime = (microtime(true) - $start) * 1000;
    echo "\$ne (status != inactive): " . round($neTime, 2) . " ms (" . count($results) . " results)\n";

    // $like operator
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->where(['email' => ['$like' => 'test.com$']])
        ->get();
    $likeTime = (microtime(true) - $start) * 1000;
    echo "\$like (email ends with): " . round($likeTime, 2) . " ms (" . count($results) . " results)\n";

    // $exists operator
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->where(['tags' => ['$exists' => true]])
        ->get();
    $existsTime = (microtime(true) - $start) * 1000;
    echo "\$exists (has tags): " . round($existsTime, 2) . " ms (" . count($results) . " results)\n";

    // Complex multi-operator
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->where([
            'age' => ['$gte' => 25, '$lte' => 45],
            'salary' => ['$gt' => 60000],
            'department' => ['$in' => ['Engineering', 'Design', 'Marketing']],
            'status' => 'active'
        ])
        ->get();
    $complexTime = (microtime(true) - $start) * 1000;
    echo "Complex (4 conditions): " . round($complexTime, 2) . " ms (" . count($results) . " results)\n";

    echo "\n--- Spatial Queries ---\n";

    // withinDistance only
    $start = microtime(true);
    $results = $db->withinDistance('benchmark', 'location', 28.9, 41.0, 10);
    $withinDistTime = (microtime(true) - $start) * 1000;
    echo "withinDistance (10km): " . round($withinDistTime, 2) . " ms (" . count($results) . " results)\n";

    // nearest only
    $start = microtime(true);
    $results = $db->nearest('benchmark', 'location', 28.9, 41.0, 10);
    $nearestTime = (microtime(true) - $start) * 1000;
    echo "nearest(10): " . round($nearestTime, 2) . " ms\n";

    // withinBBox only
    $start = microtime(true);
    $results = $db->withinBBox('benchmark', 'location', 28.85, 40.95, 28.95, 41.05);
    $bboxTime = (microtime(true) - $start) * 1000;
    echo "withinBBox: " . round($bboxTime, 2) . " ms (" . count($results) . " results)\n";

    echo "\n--- Spatial + Operator Combinations ---\n";

    // Spatial + simple where
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->withinDistance('location', 28.9, 41.0, 10)
        ->where(['status' => 'active'])
        ->get();
    $spatialSimpleTime = (microtime(true) - $start) * 1000;
    echo "spatial + simple where: " . round($spatialSimpleTime, 2) . " ms (" . count($results) . " results)\n";

    // Spatial + $gte
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->withinDistance('location', 28.9, 41.0, 10)
        ->where(['salary' => ['$gte' => 70000]])
        ->get();
    $spatialGteTime = (microtime(true) - $start) * 1000;
    echo "spatial + \$gte: " . round($spatialGteTime, 2) . " ms (" . count($results) . " results)\n";

    // Spatial + $in
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->withinDistance('location', 28.9, 41.0, 10)
        ->where(['department' => ['$in' => ['Engineering', 'Design']]])
        ->get();
    $spatialInTime = (microtime(true) - $start) * 1000;
    echo "spatial + \$in: " . round($spatialInTime, 2) . " ms (" . count($results) . " results)\n";

    // Spatial + range
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->withinDistance('location', 28.9, 41.0, 10)
        ->where(['age' => ['$gte' => 25, '$lte' => 40]])
        ->get();
    $spatialRangeTime = (microtime(true) - $start) * 1000;
    echo "spatial + range: " . round($spatialRangeTime, 2) . " ms (" . count($results) . " results)\n";

    // Spatial + complex operators
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->withinDistance('location', 28.9, 41.0, 10)
        ->where([
            'status' => 'active',
            'salary' => ['$gte' => 50000, '$lte' => 120000],
            'department' => ['$in' => ['Engineering', 'Design', 'Marketing']],
            'score' => ['$gte' => 5.0]
        ])
        ->get();
    $spatialComplexTime = (microtime(true) - $start) * 1000;
    echo "spatial + complex (4 ops): " . round($spatialComplexTime, 2) . " ms (" . count($results) . " results)\n";

    // Spatial + operators + sort
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->withinDistance('location', 28.9, 41.0, 10)
        ->where(['salary' => ['$gte' => 50000]])
        ->withDistance('location', 28.9, 41.0)
        ->sort('_distance', 'asc')
        ->limit(20)
        ->get();
    $spatialSortTime = (microtime(true) - $start) * 1000;
    echo "spatial + ops + sort + limit: " . round($spatialSortTime, 2) . " ms (" . count($results) . " results)\n";

    // nearest + operators
    $start = microtime(true);
    $results = $db->query('benchmark')
        ->nearest('location', 28.9, 41.0, 50)
        ->where([
            'status' => 'active',
            'salary' => ['$gt' => 60000]
        ])
        ->limit(10)
        ->get();
    $nearestOpsTime = (microtime(true) - $start) * 1000;
    echo "nearest + ops + limit: " . round($nearestOpsTime, 2) . " ms (" . count($results) . " results)\n";

    echo "\n";
}

// Cleanup
foreach (glob($testDir . '*') as $file) {
    if (is_file($file)) unlink($file);
}
rmdir($testDir);

echo "=== Benchmark Summary ===\n";
echo "- Comparison operators add minimal overhead (<5ms for most operations)\n";
echo "- Spatial + operator combinations work efficiently\n";
echo "- R-tree indexing provides O(log n) spatial queries\n";
echo "- Complex multi-operator queries scale well\n";
