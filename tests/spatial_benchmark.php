<?php
/**
 * Spatial Index Performance Benchmark
 * Tests the optimized spatial operations
 */

require_once __DIR__ . '/../noneDB.php';

echo "=== noneDB Spatial Index Performance Benchmark ===\n\n";

// Setup
$testDir = __DIR__ . '/test_db/';
if (!is_dir($testDir)) {
    mkdir($testDir, 0777, true);
}

// Clean test directory
foreach (glob($testDir . '*') as $file) {
    if (is_file($file)) unlink($file);
}

$db = new noneDB([
    'secretKey' => 'benchmark_secret',
    'dbDir' => $testDir,
    'autoCreateDB' => true
]);

// Generate test data
$recordCounts = [100, 500, 1000];

foreach ($recordCounts as $count) {
    echo "--- Testing with $count records ---\n";

    // Clean for each test
    foreach (glob($testDir . '*') as $file) {
        if (is_file($file)) unlink($file);
    }
    noneDB::clearStaticCache();

    // Generate random Istanbul area locations
    $data = [];
    for ($i = 0; $i < $count; $i++) {
        $data[] = [
            'name' => "Location $i",
            'location' => [
                'type' => 'Point',
                'coordinates' => [
                    28.8 + (mt_rand(0, 100) / 500), // Lon: 28.8-29.0
                    40.9 + (mt_rand(0, 100) / 500)  // Lat: 40.9-41.1
                ]
            ]
        ];
    }

    // 1. Batch Insert (without spatial index)
    $start = microtime(true);
    $db->insert('benchmark_locations', $data);
    $insertTime = (microtime(true) - $start) * 1000;
    echo "Insert $count records: " . round($insertTime, 2) . " ms\n";

    // 2. Create Spatial Index
    $start = microtime(true);
    $db->createSpatialIndex('benchmark_locations', 'location');
    $indexTime = (microtime(true) - $start) * 1000;
    echo "Create spatial index: " . round($indexTime, 2) . " ms\n";

    // 3. withinDistance query
    $start = microtime(true);
    $results = $db->withinDistance('benchmark_locations', 'location', 28.9, 41.0, 5000);
    $queryTime = (microtime(true) - $start) * 1000;
    echo "withinDistance (5km): " . round($queryTime, 2) . " ms (" . count($results) . " results)\n";

    // 4. nearest() query
    $start = microtime(true);
    $results = $db->nearest('benchmark_locations', 'location', 28.9, 41.0, 10);
    $nearestTime = (microtime(true) - $start) * 1000;
    echo "nearest(10): " . round($nearestTime, 2) . " ms\n";

    // 5. withinBBox query
    $start = microtime(true);
    $results = $db->withinBBox('benchmark_locations', 'location', 28.85, 40.95, 28.95, 41.05);
    $bboxTime = (microtime(true) - $start) * 1000;
    echo "withinBBox: " . round($bboxTime, 2) . " ms (" . count($results) . " results)\n";

    // 6. Query Builder with spatial + where filter
    $start = microtime(true);
    $results = $db->query('benchmark_locations')
        ->withinDistance('location', 28.9, 41.0, 10000)
        ->where(['name' => ['$like' => 'Location 1%']])
        ->get();
    $combinedTime = (microtime(true) - $start) * 1000;
    echo "Combined query (spatial+where): " . round($combinedTime, 2) . " ms (" . count($results) . " results)\n";

    echo "\n";
}

// Cleanup
foreach (glob($testDir . '*') as $file) {
    if (is_file($file)) unlink($file);
}

echo "Benchmark completed!\n";
echo "\n=== Optimization Summary ===\n";
echo "- Parent pointer map: O(1) parent lookup (was O(n))\n";
echo "- Linear split: O(n) seed selection (was O(n²))\n";
echo "- Dirty flag pattern: Single disk write per batch (was n writes)\n";
echo "- Distance memoization: Cached haversine calculations\n";
echo "- Centroid caching: Cached geometry centroids\n";
echo "- Node size 32: Fewer tree levels and splits\n";
echo "- Adaptive nearest(): Exponential radius expansion\n";
