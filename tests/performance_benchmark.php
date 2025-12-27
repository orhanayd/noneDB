<?php
/**
 * noneDB Performance Benchmark
 * Tests all operations from 100 to 500K records
 */

require_once __DIR__ . '/../noneDB.php';

ini_set('memory_limit', '-1');
set_time_limit(0);

$db = new noneDB();

// Test sizes
$sizes = [100, 1000, 10000, 50000, 100000, 500000];

// Colors
function green($t) { return "\033[32m{$t}\033[0m"; }
function yellow($t) { return "\033[33m{$t}\033[0m"; }
function blue($t) { return "\033[34m{$t}\033[0m"; }
function cyan($t) { return "\033[36m{$t}\033[0m"; }

// Format time
function formatTime($ms) {
    if ($ms < 1) return "<1 ms";
    if ($ms >= 1000) return round($ms / 1000, 1) . " s";
    return round($ms) . " ms";
}

// Generate test record
function generateRecord($i) {
    $cities = ['Istanbul', 'Ankara', 'Izmir', 'Bursa', 'Antalya'];
    $depts = ['IT', 'HR', 'Sales', 'Marketing', 'Finance'];
    return [
        "name" => "User" . $i,
        "email" => "user{$i}@test.com",
        "age" => 20 + ($i % 50),
        "salary" => 5000 + ($i % 10000),
        "city" => $cities[$i % 5],
        "department" => $depts[$i % 5],
        "active" => ($i % 3 !== 0)
    ];
}

echo blue("╔════════════════════════════════════════════════════════════════════╗\n");
echo blue("║              noneDB Performance Benchmark v2.3                     ║\n");
echo blue("║       Write Buffer + Atomic Locking - Thread-Safe Operations       ║\n");
echo blue("╚════════════════════════════════════════════════════════════════════╝\n\n");

echo "PHP Version: " . PHP_VERSION . "\n";
echo "OS: " . PHP_OS . "\n\n";

$results = [
    'write' => ['insert' => [], 'update' => [], 'delete' => []],
    'read' => ['find_all' => [], 'find_key' => [], 'find_filter' => []],
    'query' => ['count' => [], 'distinct' => [], 'sum' => [], 'like' => [], 'between' => [], 'sort' => [], 'first' => [], 'exists' => []],
    'chain' => ['whereIn' => [], 'orWhere' => [], 'search' => [], 'groupBy' => [], 'select' => [], 'complex' => []]
];

foreach ($sizes as $size) {
    $dbName = "perftest_" . $size;

    echo yellow("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");
    echo yellow("  Testing with " . number_format($size) . " records\n");
    echo yellow("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");

    // Clean up
    $files = glob(__DIR__ . '/../db/*' . $dbName . '*');
    foreach ($files as $f) @unlink($f);

    // ===== WRITE OPERATIONS =====
    echo "\n" . cyan("  Write Operations:\n");

    // INSERT
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        $data[] = generateRecord($i);
    }

    $start = microtime(true);
    $db->insert($dbName, $data);
    $insertTime = (microtime(true) - $start) * 1000;
    $results['write']['insert'][$size] = $insertTime;
    echo "    insert():     " . green(formatTime($insertTime)) . "\n";

    // UPDATE
    $start = microtime(true);
    $db->update($dbName, [
        ['city' => 'Istanbul'],
        ['set' => ['region' => 'Marmara']]
    ]);
    $updateTime = (microtime(true) - $start) * 1000;
    $results['write']['update'][$size] = $updateTime;
    echo "    update():     " . green(formatTime($updateTime)) . "\n";

    // DELETE (small portion)
    $start = microtime(true);
    $db->delete($dbName, ['department' => 'HR']);
    $deleteTime = (microtime(true) - $start) * 1000;
    $results['write']['delete'][$size] = $deleteTime;
    echo "    delete():     " . green(formatTime($deleteTime)) . "\n";

    // Re-insert for read tests
    $files = glob(__DIR__ . '/../db/*' . $dbName . '*');
    foreach ($files as $f) @unlink($f);
    $db->insert($dbName, $data);

    // ===== READ OPERATIONS =====
    echo "\n" . cyan("  Read Operations:\n");

    // FIND ALL
    $start = microtime(true);
    $db->find($dbName, []);
    $findAllTime = (microtime(true) - $start) * 1000;
    $results['read']['find_all'][$size] = $findAllTime;
    echo "    find(all):    " . green(formatTime($findAllTime)) . "\n";

    // FIND BY KEY
    $testKey = (int)($size / 2);
    $start = microtime(true);
    $db->find($dbName, ['key' => $testKey]);
    $findKeyTime = (microtime(true) - $start) * 1000;
    $results['read']['find_key'][$size] = $findKeyTime;
    echo "    find(key):    " . green(formatTime($findKeyTime)) . "\n";

    // FIND WITH FILTER
    $start = microtime(true);
    $db->find($dbName, ['city' => 'Ankara']);
    $findFilterTime = (microtime(true) - $start) * 1000;
    $results['read']['find_filter'][$size] = $findFilterTime;
    echo "    find(filter): " . green(formatTime($findFilterTime)) . "\n";

    // ===== QUERY & AGGREGATION =====
    echo "\n" . cyan("  Query & Aggregation:\n");

    // COUNT
    $start = microtime(true);
    $db->count($dbName);
    $countTime = (microtime(true) - $start) * 1000;
    $results['query']['count'][$size] = $countTime;
    echo "    count():      " . green(formatTime($countTime)) . "\n";

    // DISTINCT
    $start = microtime(true);
    $db->distinct($dbName, 'city');
    $distinctTime = (microtime(true) - $start) * 1000;
    $results['query']['distinct'][$size] = $distinctTime;
    echo "    distinct():   " . green(formatTime($distinctTime)) . "\n";

    // SUM
    $start = microtime(true);
    $db->sum($dbName, 'salary');
    $sumTime = (microtime(true) - $start) * 1000;
    $results['query']['sum'][$size] = $sumTime;
    echo "    sum():        " . green(formatTime($sumTime)) . "\n";

    // LIKE
    $start = microtime(true);
    $db->query($dbName)->like('name', '^User1')->get();
    $likeTime = (microtime(true) - $start) * 1000;
    $results['query']['like'][$size] = $likeTime;
    echo "    like():       " . green(formatTime($likeTime)) . "\n";

    // BETWEEN
    $start = microtime(true);
    $db->query($dbName)->between('age', 25, 35)->get();
    $betweenTime = (microtime(true) - $start) * 1000;
    $results['query']['between'][$size] = $betweenTime;
    echo "    between():    " . green(formatTime($betweenTime)) . "\n";

    // SORT
    $start = microtime(true);
    $db->query($dbName)->sort('salary', 'desc')->limit(100)->get();
    $sortTime = (microtime(true) - $start) * 1000;
    $results['query']['sort'][$size] = $sortTime;
    echo "    sort():       " . green(formatTime($sortTime)) . "\n";

    // FIRST
    $start = microtime(true);
    $db->query($dbName)->where(['city' => 'Izmir'])->first();
    $firstTime = (microtime(true) - $start) * 1000;
    $results['query']['first'][$size] = $firstTime;
    echo "    first():      " . green(formatTime($firstTime)) . "\n";

    // EXISTS
    $start = microtime(true);
    $db->query($dbName)->where(['city' => 'Bursa'])->exists();
    $existsTime = (microtime(true) - $start) * 1000;
    $results['query']['exists'][$size] = $existsTime;
    echo "    exists():     " . green(formatTime($existsTime)) . "\n";

    // ===== METHOD CHAINING =====
    echo "\n" . cyan("  Method Chaining:\n");

    // WHEREIN
    $start = microtime(true);
    $db->query($dbName)->whereIn('city', ['Istanbul', 'Ankara'])->get();
    $whereInTime = (microtime(true) - $start) * 1000;
    $results['chain']['whereIn'][$size] = $whereInTime;
    echo "    whereIn():    " . green(formatTime($whereInTime)) . "\n";

    // ORWHERE
    $start = microtime(true);
    $db->query($dbName)->where(['city' => 'Istanbul'])->orWhere(['city' => 'Ankara'])->get();
    $orWhereTime = (microtime(true) - $start) * 1000;
    $results['chain']['orWhere'][$size] = $orWhereTime;
    echo "    orWhere():    " . green(formatTime($orWhereTime)) . "\n";

    // SEARCH
    $start = microtime(true);
    $db->query($dbName)->search('User1', ['name', 'email'])->get();
    $searchTime = (microtime(true) - $start) * 1000;
    $results['chain']['search'][$size] = $searchTime;
    echo "    search():     " . green(formatTime($searchTime)) . "\n";

    // GROUPBY
    $start = microtime(true);
    $db->query($dbName)->groupBy('department')->get();
    $groupByTime = (microtime(true) - $start) * 1000;
    $results['chain']['groupBy'][$size] = $groupByTime;
    echo "    groupBy():    " . green(formatTime($groupByTime)) . "\n";

    // SELECT
    $start = microtime(true);
    $db->query($dbName)->select(['name', 'email', 'city'])->get();
    $selectTime = (microtime(true) - $start) * 1000;
    $results['chain']['select'][$size] = $selectTime;
    echo "    select():     " . green(formatTime($selectTime)) . "\n";

    // COMPLEX CHAIN
    $start = microtime(true);
    $db->query($dbName)
        ->where(['active' => true])
        ->whereIn('city', ['Istanbul', 'Ankara'])
        ->between('age', 25, 40)
        ->select(['name', 'city', 'salary'])
        ->sort('salary', 'desc')
        ->limit(50)
        ->get();
    $complexTime = (microtime(true) - $start) * 1000;
    $results['chain']['complex'][$size] = $complexTime;
    echo "    complex:      " . green(formatTime($complexTime)) . "\n";

    // Cleanup
    $files = glob(__DIR__ . '/../db/*' . $dbName . '*');
    foreach ($files as $f) @unlink($f);

    echo "\n";
}

// ===== PRINT MARKDOWN TABLES =====
echo blue("\n╔════════════════════════════════════════════════════════════════════╗\n");
echo blue("║                    MARKDOWN TABLES FOR README                       ║\n");
echo blue("╚════════════════════════════════════════════════════════════════════╝\n\n");

function printTable($title, $data, $sizes) {
    echo "### {$title}\n";
    echo "| Operation |";
    foreach ($sizes as $s) {
        $label = $s >= 1000 ? ($s / 1000) . "K" : $s;
        echo " {$label} |";
    }
    echo "\n|-----------|";
    foreach ($sizes as $s) echo "-----|";
    echo "\n";

    foreach ($data as $op => $times) {
        echo "| {$op} |";
        foreach ($sizes as $s) {
            $t = isset($times[$s]) ? formatTime($times[$s]) : "-";
            echo " {$t} |";
        }
        echo "\n";
    }
    echo "\n";
}

printTable("Write Operations", $results['write'], $sizes);
printTable("Read Operations", $results['read'], $sizes);
printTable("Query & Aggregation", $results['query'], $sizes);
printTable("Method Chaining (v2.1+)", $results['chain'], $sizes);

echo green("\nBenchmark completed!\n");
