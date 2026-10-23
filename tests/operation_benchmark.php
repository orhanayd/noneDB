<?php
/**
 * noneDB single-operation latency benchmark
 *
 * Measures the cost of one call (ms per operation) on a 5,000-record database, including the
 * database lock every operation takes. Compare two versions of noneDB.php:
 *
 *   php tests/operation_benchmark.php                       # this checkout
 *   php tests/operation_benchmark.php /path/to/noneDB.php   # another version
 *   php tests/operation_benchmark.php noneDB.php 3          # best of 3 runs
 */

$lib = $argv[1] ?? __DIR__ . '/../noneDB.php';
$runs = max(1, (int)($argv[2] ?? 3));
$records = 5000;
require $lib;

// Own directory and key: never touches your databases
$dir = sys_get_temp_dir() . '/nonedb_operation_benchmark/';
$cfg = ['secretKey' => 'nonedb-benchmark-key-not-for-production', 'dbDir' => $dir];

function wipe($dir) {
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
        if (is_file($dir . $file)) {
            unlink($dir . $file);
        }
    }
}

function runOnce($cfg, $dir, $records) {
    wipe($dir);
    noneDB::clearStaticCache();
    $db = new noneDB($cfg);
    if (method_exists($db, 'enableBuffering')) {
        $db->enableBuffering(false); // direct writes (the default since 3.2)
    }
    $rows = [];
    for ($i = 0; $i < $records; $i++) {
        $rows[] = ['name' => 'user' . $i, 'city' => ['Istanbul', 'Ankara', 'Izmir', 'Bursa'][$i % 4], 'age' => 18 + $i % 50, 'active' => $i % 3 === 0];
    }
    $results = [];
    $t = function ($name, $ops, callable $fn) use (&$results) {
        $start = microtime(true);
        $fn();
        $results[$name] = (microtime(true) - $start) * 1000 / $ops;
    };
    $t('insert (5,000 records, one call)', 1, function () use ($db, $rows) { $db->insert('bench', $rows); });
    $t('insert (one record)', 500, function () use ($db) { for ($i = 0; $i < 500; $i++) { $db->insert('single', ['i' => $i, 'v' => 'x']); } });
    $t('find (all records)', 20, function () use ($db) { for ($i = 0; $i < 20; $i++) { $db->find('bench', 0); } });
    $t('find (by key)', 2000, function () use ($db, $records) { for ($i = 0; $i < 2000; $i++) { $db->find('bench', ['key' => ($i * 7) % $records]); } });
    $t('find (filter)', 50, function () use ($db) { for ($i = 0; $i < 50; $i++) { $db->find('bench', ['city' => 'Ankara']); } });
    $t('count', 2000, function () use ($db) { for ($i = 0; $i < 2000; $i++) { $db->count('bench'); } });
    $t('query (where range + sort + limit)', 20, function () use ($db) { for ($i = 0; $i < 20; $i++) { $db->query('bench')->where(['age' => ['$gte' => 30, '$lt' => 40]])->sort('age', 'desc')->limit(10)->get(); } });
    $t('update (by key)', 300, function () use ($db, $records) { for ($i = 0; $i < 300; $i++) { $db->update('bench', [['key' => ($i * 13) % $records], ['set' => ['touched' => $i]]]); } });
    $t('update (filter, 1,250 records)', 5, function () use ($db) { for ($i = 0; $i < 5; $i++) { $db->update('bench', [['city' => 'Bursa'], ['set' => ['flag' => $i]]]); } });
    $t('delete (by key)', 300, function () use ($db, $records) { for ($i = 0; $i < 300; $i++) { $db->delete('bench', ['key' => ($i * 17) % $records]); } });
    $db->createFieldIndex('bench', 'city');
    $t('find (filter on indexed field)', 200, function () use ($db) { for ($i = 0; $i < 200; $i++) { $db->find('bench', ['city' => 'Izmir']); } });
    $t('insert (one record, indexed field)', 300, function () use ($db) { for ($i = 0; $i < 300; $i++) { $db->insert('bench', ['name' => 'n' . $i, 'city' => 'Izmir', 'age' => 30]); } });
    $t('new instance + find (by key)', 500, function () use ($cfg, $records) { for ($i = 0; $i < 500; $i++) { $d = new noneDB($cfg); $d->find('bench', ['key' => $i % $records]); } });
    return $results;
}

$best = [];
for ($run = 0; $run < $runs; $run++) {
    foreach (runOnce($cfg, $dir, $records) as $name => $ms) {
        $best[$name] = isset($best[$name]) ? min($best[$name], $ms) : $ms;
    }
}
wipe($dir);

echo "noneDB single-operation benchmark ({$records} records, best of {$runs}, ms per operation)\n";
echo "Library: " . realpath($lib) . "\nPHP " . PHP_VERSION . "\n\n";
echo "| Operation | ms/op |\n|-----------|-------|\n";
foreach ($best as $name => $ms) {
    printf("| %s | %s |\n", $name, $ms < 1 ? number_format($ms, 3) : number_format($ms, 1));
}
