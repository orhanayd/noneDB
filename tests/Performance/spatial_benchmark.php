<?php
/**
 * Spatial Index Performance Benchmark
 * Insert, index build and spatial queries on random points (Istanbul area), 100 to 1,000 records
 */

require_once __DIR__ . '/../../noneDB.php';

echo "=== noneDB Spatial Index Performance Benchmark ===\n\n";

// Own directory and key: the benchmark wipes its directory and removes it at the end, never your databases
$testDir = sys_get_temp_dir() . '/nonedb_spatial_benchmark/';
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
    $db->insert('benchmark-locations', $data);
    $insertTime = (microtime(true) - $start) * 1000;
    echo "Insert $count records: " . round($insertTime, 2) . " ms\n";

    // 2. Create Spatial Index
    $start = microtime(true);
    $db->createSpatialIndex('benchmark-locations', 'location');
    $indexTime = (microtime(true) - $start) * 1000;
    echo "Create spatial index: " . round($indexTime, 2) . " ms\n";

    // 3. withinDistance query
    $start = microtime(true);
    $results = $db->withinDistance('benchmark-locations', 'location', 28.9, 41.0, 5000);
    $queryTime = (microtime(true) - $start) * 1000;
    echo "withinDistance (5km): " . round($queryTime, 2) . " ms (" . count($results) . " results)\n";

    // 4. nearest() query
    $start = microtime(true);
    $results = $db->nearest('benchmark-locations', 'location', 28.9, 41.0, 10);
    $nearestTime = (microtime(true) - $start) * 1000;
    echo "nearest(10): " . round($nearestTime, 2) . " ms\n";

    // 5. withinBBox query
    $start = microtime(true);
    $results = $db->withinBBox('benchmark-locations', 'location', 28.85, 40.95, 28.95, 41.05);
    $bboxTime = (microtime(true) - $start) * 1000;
    echo "withinBBox: " . round($bboxTime, 2) . " ms (" . count($results) . " results)\n";

    // 6. Query Builder with spatial + where filter
    $start = microtime(true);
    $results = $db->query('benchmark-locations')
        ->withinDistance('location', 28.9, 41.0, 10000)
        ->where(['name' => ['$like' => '^Location 1']]) // names starting with "Location 1" ($like is literal except ^ and $)
        ->get();
    $combinedTime = (microtime(true) - $start) * 1000;
    echo "Combined query (spatial+where): " . round($combinedTime, 2) . " ms (" . count($results) . " results)\n";

    echo "\n";
}

// Cleanup (including dot files such as .htaccess and the hash cache)
foreach (array_diff(scandir($testDir) ?: [], ['.', '..']) as $file) {
    if (is_file($testDir . $file)) unlink($testDir . $file);
}
rmdir($testDir);

echo "Benchmark completed!\n";
