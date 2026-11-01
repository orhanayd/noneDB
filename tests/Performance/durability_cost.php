<?php
/**
 * The cost of durability 'durable' against 'performance' (docs/DURABILITY.md): the same operations in both modes, each
 * mode in a process of its own, median and p95 per operation and the syncs each made (getDurability()['syncs']).
 * Durable mode is Linux only (UNSUPPORTED_DURABILITY elsewhere).
 *
 *   php tests/Performance/durability_cost.php [--lib=noneDB.php] [--runs=300] [--dir=/tmp/...] [--md=FILE]
 */
$options = ['lib' => dirname(__DIR__, 2) . '/noneDB.php', 'runs' => 300, 'dir' => sys_get_temp_dir() . '/nonedb_durability_cost', 'md' => null, 'mode' => null];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $options)) {
        $options[$m[1]] = $m[2];
    }
}
$runs = (int)$options['runs'];

if ($options['mode'] !== null) {
    // One mode, in this process: [operation => [median ms, p95 ms, file syncs per op, directory syncs per op]]
    require $options['lib'];
    $dir = rtrim($options['dir'], '/') . '/' . $options['mode'] . '/';
    exec('rm -rf ' . escapeshellarg($dir));
    $config = ['secretKey' => 'durability-cost-key-0123456789abcdef', 'dbDir' => $dir, 'durability' => $options['mode'], 'shardingEnabled' => false];
    $db = new noneDB($config);
    $db->insert('d', array_map(function ($i) { return ['n' => $i, 'v' => str_repeat('x', 40)]; }, range(0, 9999)));
    $measure = function (callable $op, int $count) use ($db) {
        $times = [];
        $before = $db->getDurability()['syncs'];
        for ($i = 0; $i < $count; $i++) {
            $t = hrtime(true);
            $op($i);
            $times[] = (hrtime(true) - $t) / 1e6;
        }
        $after = $db->getDurability()['syncs'];
        sort($times);
        return [round($times[intdiv($count, 2)], 3), round($times[(int)floor(0.95 * ($count - 1))], 3),
            round(($after['file'] - $before['file']) / $count, 2), round(($after['dir'] - $before['dir']) / $count, 2)];
    };
    $next = 10000;
    $result = [
        'insert one record' => $measure(function () use ($db, &$next) { $db->insert('d', ['n' => $next++, 'v' => 'y']); }, $runs),
        'insert 100 records' => $measure(function () use ($db, &$next) {
            $db->insert('d', array_map(function ($i) { return ['n' => $i, 'v' => 'z']; }, range($next, $next + 99)));
            $next += 100;
        }, max(10, intdiv($runs, 10))),
        'update one record by key' => $measure(function ($i) use ($db) { $db->update('d', [['key' => $i], ['set' => ['v' => 'u' . $i]]]); }, $runs),
        'delete one record by key' => $measure(function ($i) use ($db) { $db->delete('d', ['key' => 5000 + $i]); }, $runs),
        'create a database' => $measure(function ($i) use ($db) { $db->createDB('c' . $i); }, max(10, intdiv($runs, 10))),
        'compact (10 000 records, after the writes above)' => $measure(function () use ($db) { $db->compact('d'); }, 1),
    ];
    exec('rm -rf ' . escapeshellarg($dir));
    echo json_encode($result);
    exit;
}

if (PHP_OS_FAMILY !== 'Linux') {
    fwrite(STDERR, "durable mode is supported on Linux only (docs/DURABILITY.md §1)\n");
    exit(2);
}
$results = [];
foreach (['performance', 'durable'] as $mode) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --mode=' . $mode . ' --runs=' . $runs
        . ' --lib=' . escapeshellarg($options['lib']) . ' --dir=' . escapeshellarg($options['dir']);
    $results[$mode] = json_decode((string)shell_exec($cmd), true);
    if (!is_array($results[$mode])) {
        fwrite(STDERR, "the $mode run failed\n");
        exit(1);
    }
}
$md = "# Durability cost: 'durable' against 'performance'\n\n"
    . '- PHP ' . PHP_VERSION . ', ' . php_uname('s') . ' ' . php_uname('r') . ', library `' . basename($options['lib']) . '` (sha256 `'
    . substr(hash_file('sha256', $options['lib']), 0, 12) . "…`)\n"
    . "- One database of 10 000 records, not sharded; $runs runs of each single operation. Times in ms; syncs per operation.\n\n"
    . "| Operation | performance median / p95 | durable median / p95 | durable ÷ performance (median) | durable syncs (file + directory) |\n|---|---|---|---|---|\n";
foreach ($results['performance'] as $op => [$median, $p95]) {
    [$dm, $dp, $df, $dd] = $results['durable'][$op];
    $md .= "| $op | $median / $p95 | $dm / $dp | " . ($median > 0 ? round($dm / $median, 1) . '×' : '-') . " | $df + $dd |\n";
}
echo $md;
if ($options['md'] !== null) {
    file_put_contents($options['md'], $md);
}
