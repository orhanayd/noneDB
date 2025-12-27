<?php
/**
 * noneDB Performance Benchmark
 * Tests various operations with different dataset sizes
 */

require_once __DIR__ . '/../noneDB.php';

$db = new noneDB();
$testDb = 'perf_test_' . time();

function formatTime($ms) {
    if ($ms < 1) return '<1 ms';
    if ($ms >= 1000) return round($ms / 1000, 1) . ' s';
    return round($ms) . ' ms';
}

function formatMemory($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1024, 1) . ' KB';
}

function benchmark($callback) {
    gc_collect_cycles();
    $start = microtime(true);
    $result = $callback();
    $time = (microtime(true) - $start) * 1000;
    return ['time' => $time, 'result' => $result];
}

function getFileSize($db, $dbName) {
    $reflection = new ReflectionClass($db);
    $prop = $reflection->getProperty('dbDir');
    $prop->setAccessible(true);
    $dbDir = $prop->getValue($db);

    $method = $reflection->getMethod('hashDBName');
    $method->setAccessible(true);
    $hash = $method->invoke($db, $dbName);

    $file = $dbDir . $hash . '-' . $dbName . '.nonedb';
    return file_exists($file) ? filesize($file) : 0;
}

$sizes = [100, 1000, 10000, 50000, 100000];
$results = [];

echo "=== noneDB Performance Benchmark ===\n\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Platform: " . PHP_OS . "\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($sizes as $size) {
    echo "--- Testing with " . number_format($size) . " records ---\n";

    $results[$size] = [];
    $dbName = $testDb . '_' . $size;

    // Generate test data
    $data = [];
    $cities = ['Istanbul', 'Ankara', 'Izmir', 'Bursa', 'Antalya'];
    $departments = ['IT', 'HR', 'Sales', 'Marketing', 'Finance'];

    for ($i = 0; $i < $size; $i++) {
        $data[] = [
            'name' => 'User' . $i,
            'email' => 'user' . $i . '@test.com',
            'age' => rand(18, 65),
            'salary' => rand(3000, 15000),
            'city' => $cities[array_rand($cities)],
            'department' => $departments[array_rand($departments)],
            'active' => rand(0, 1) === 1
        ];
    }

    // ========== WRITE OPERATIONS ==========

    // INSERT
    $r = benchmark(function() use ($db, $dbName, $data) {
        return $db->insert($dbName, $data);
    });
    $results[$size]['insert'] = $r['time'];
    echo "insert(): " . formatTime($r['time']) . "\n";

    // Get file size after insert
    $results[$size]['filesize'] = getFileSize($db, $dbName);

    // UPDATE
    $r = benchmark(function() use ($db, $dbName) {
        return $db->update($dbName, [
            ['city' => 'Istanbul'],
            ['set' => ['region' => 'Marmara']]
        ]);
    });
    $results[$size]['update'] = $r['time'];
    echo "update(): " . formatTime($r['time']) . "\n";

    // ========== READ OPERATIONS ==========

    // FIND ALL
    $r = benchmark(function() use ($db, $dbName) {
        return $db->find($dbName, 0);
    });
    $results[$size]['find_all'] = $r['time'];
    echo "find(all): " . formatTime($r['time']) . "\n";

    // FIND BY KEY
    $r = benchmark(function() use ($db, $dbName, $size) {
        return $db->find($dbName, ['key' => intval($size / 2)]);
    });
    $results[$size]['find_key'] = $r['time'];
    echo "find(key): " . formatTime($r['time']) . "\n";

    // FIND BY FILTER
    $r = benchmark(function() use ($db, $dbName) {
        return $db->find($dbName, ['city' => 'Istanbul']);
    });
    $results[$size]['find_filter'] = $r['time'];
    echo "find(filter): " . formatTime($r['time']) . "\n";

    // ========== QUERY & AGGREGATION ==========

    // COUNT
    $r = benchmark(function() use ($db, $dbName) {
        return $db->count($dbName, 0);
    });
    $results[$size]['count'] = $r['time'];
    echo "count(): " . formatTime($r['time']) . "\n";

    // DISTINCT
    $r = benchmark(function() use ($db, $dbName) {
        return $db->distinct($dbName, 'city');
    });
    $results[$size]['distinct'] = $r['time'];
    echo "distinct(): " . formatTime($r['time']) . "\n";

    // SUM
    $r = benchmark(function() use ($db, $dbName) {
        return $db->sum($dbName, 'salary');
    });
    $results[$size]['sum'] = $r['time'];
    echo "sum(): " . formatTime($r['time']) . "\n";

    // LIKE
    $r = benchmark(function() use ($db, $dbName) {
        return $db->like($dbName, 'email', 'test.com');
    });
    $results[$size]['like'] = $r['time'];
    echo "like(): " . formatTime($r['time']) . "\n";

    // BETWEEN
    $r = benchmark(function() use ($db, $dbName) {
        return $db->between($dbName, 'age', 25, 45);
    });
    $results[$size]['between'] = $r['time'];
    echo "between(): " . formatTime($r['time']) . "\n";

    // SORT
    $allData = $db->find($dbName, 0);
    $r = benchmark(function() use ($db, $allData) {
        return $db->sort($allData, 'salary', 'desc');
    });
    $results[$size]['sort'] = $r['time'];
    echo "sort(): " . formatTime($r['time']) . "\n";
    unset($allData);

    // FIRST
    $r = benchmark(function() use ($db, $dbName) {
        return $db->first($dbName, ['active' => true]);
    });
    $results[$size]['first'] = $r['time'];
    echo "first(): " . formatTime($r['time']) . "\n";

    // EXISTS
    $r = benchmark(function() use ($db, $dbName) {
        return $db->exists($dbName, ['city' => 'Istanbul']);
    });
    $results[$size]['exists'] = $r['time'];
    echo "exists(): " . formatTime($r['time']) . "\n";

    // ========== NEW v2.1 METHODS ==========

    // whereIn
    $r = benchmark(function() use ($db, $dbName) {
        return $db->query($dbName)
            ->whereIn('city', ['Istanbul', 'Ankara', 'Izmir'])
            ->get();
    });
    $results[$size]['whereIn'] = $r['time'];
    echo "whereIn(): " . formatTime($r['time']) . "\n";

    // orWhere
    $r = benchmark(function() use ($db, $dbName) {
        return $db->query($dbName)
            ->where(['city' => 'Istanbul'])
            ->orWhere(['city' => 'Ankara'])
            ->get();
    });
    $results[$size]['orWhere'] = $r['time'];
    echo "orWhere(): " . formatTime($r['time']) . "\n";

    // search
    $r = benchmark(function() use ($db, $dbName) {
        return $db->query($dbName)
            ->search('User1')
            ->get();
    });
    $results[$size]['search'] = $r['time'];
    echo "search(): " . formatTime($r['time']) . "\n";

    // groupBy
    $r = benchmark(function() use ($db, $dbName) {
        return $db->query($dbName)
            ->groupBy('department')
            ->get();
    });
    $results[$size]['groupBy'] = $r['time'];
    echo "groupBy(): " . formatTime($r['time']) . "\n";

    // select
    $r = benchmark(function() use ($db, $dbName) {
        return $db->query($dbName)
            ->select(['name', 'email', 'city'])
            ->get();
    });
    $results[$size]['select'] = $r['time'];
    echo "select(): " . formatTime($r['time']) . "\n";

    // Complex query chain
    $r = benchmark(function() use ($db, $dbName) {
        return $db->query($dbName)
            ->where(['active' => true])
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->between('age', 25, 45)
            ->notLike('email', 'spam')
            ->sort('salary', 'desc')
            ->limit(100)
            ->select(['name', 'salary', 'city'])
            ->get();
    });
    $results[$size]['complex_chain'] = $r['time'];
    echo "complex chain: " . formatTime($r['time']) . "\n";

    // DELETE (at the end)
    $r = benchmark(function() use ($db, $dbName) {
        return $db->delete($dbName, ['key' => [0, 1, 2, 3, 4]]);
    });
    $results[$size]['delete'] = $r['time'];
    echo "delete(): " . formatTime($r['time']) . "\n";

    // Memory
    $results[$size]['memory'] = memory_get_peak_usage(true);
    echo "Peak Memory: " . formatMemory($results[$size]['memory']) . "\n";
    echo "File Size: " . formatMemory($results[$size]['filesize']) . "\n";

    echo "\n";

    // Cleanup this db
    $db->delete($dbName, []);
    gc_collect_cycles();
}

// ========== PRINT MARKDOWN TABLES ==========

echo "\n=== MARKDOWN TABLES FOR README ===\n\n";

echo "### Write Operations\n";
echo "| Operation | " . implode(' | ', array_map(function($s) { return $s >= 1000 ? ($s/1000).'K' : $s; }, $sizes)) . " |\n";
echo "|-----------|" . str_repeat("------|", count($sizes)) . "\n";
foreach (['insert', 'update', 'delete'] as $op) {
    echo "| {$op}() | ";
    foreach ($sizes as $size) {
        echo formatTime($results[$size][$op]) . " | ";
    }
    echo "\n";
}

echo "\n### Read Operations\n";
echo "| Operation | " . implode(' | ', array_map(function($s) { return $s >= 1000 ? ($s/1000).'K' : $s; }, $sizes)) . " |\n";
echo "|-----------|" . str_repeat("------|", count($sizes)) . "\n";
foreach (['find_all' => 'find(all)', 'find_key' => 'find(key)', 'find_filter' => 'find(filter)'] as $key => $label) {
    echo "| {$label} | ";
    foreach ($sizes as $size) {
        echo formatTime($results[$size][$key]) . " | ";
    }
    echo "\n";
}

echo "\n### Query & Aggregation\n";
echo "| Operation | " . implode(' | ', array_map(function($s) { return $s >= 1000 ? ($s/1000).'K' : $s; }, $sizes)) . " |\n";
echo "|-----------|" . str_repeat("------|", count($sizes)) . "\n";
foreach (['count', 'distinct', 'sum', 'like', 'between', 'sort', 'first', 'exists'] as $op) {
    echo "| {$op}() | ";
    foreach ($sizes as $size) {
        echo formatTime($results[$size][$op]) . " | ";
    }
    echo "\n";
}

echo "\n### Method Chaining (v2.1)\n";
echo "| Operation | " . implode(' | ', array_map(function($s) { return $s >= 1000 ? ($s/1000).'K' : $s; }, $sizes)) . " |\n";
echo "|-----------|" . str_repeat("------|", count($sizes)) . "\n";
foreach (['whereIn', 'orWhere', 'search', 'groupBy', 'select', 'complex_chain' => 'complex chain'] as $key => $label) {
    if (is_int($key)) { $key = $label; }
    echo "| {$label}() | ";
    foreach ($sizes as $size) {
        echo formatTime($results[$size][$key]) . " | ";
    }
    echo "\n";
}

echo "\n### Storage\n";
echo "| Records | File Size | Peak Memory |\n";
echo "|---------|-----------|-------------|\n";
foreach ($sizes as $size) {
    $sizeLabel = $size >= 1000 ? number_format($size) : $size;
    echo "| {$sizeLabel} | " . formatMemory($results[$size]['filesize']) . " | " . formatMemory($results[$size]['memory']) . " |\n";
}

echo "\nBenchmark complete!\n";
