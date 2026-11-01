<?php
/**
 * noneDB regression smoke tests (standalone, no PHPUnit dependency).
 *
 * Run from the repository root:
 *     php tests/Smoke/noneDB_regression_smoke.php
 * Set NONEDB_PATH to test another copy of noneDB.php; the child process inherits it.
 *
 * Uses isolated temporary databases. Does not touch an existing application DB.
 * It checks a selected subset of A1, A10, A12, C1, C3, C6 and C7.
 */
declare(strict_types=1);

$library = getenv('NONEDB_PATH') ?: __DIR__ . '/../../noneDB.php';
if (!is_file($library)) {
    fwrite(STDERR, "noneDB.php not found: $library\n");
    exit(2);
}
require_once $library;
require_once __DIR__ . '/../Support/Testing.php'; // the test interface (internal values, methods)

function configFor(string $dir, string $key): array {
    return [
        'secretKey' => $key,
        'dbDir' => $dir,
        'shardingEnabled' => false,
        'bufferEnabled' => false,
        'autoCreateDB' => true,
        'lockTimeout' => 2,
    ];
}

// Run the read in a fresh process to rule out any in-process index cache.
if (isset($argv[1]) && $argv[1] === '--child-find') {
    if (count($argv) !== 5) { exit(3); }
    [, , $dir, $key, $dbname] = $argv;
    $db = new noneDB(configFor($dir, $key));
    echo json_encode($db->find($dbname), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    exit(0);
}

$dir = sys_get_temp_dir() . '/nonedb-smoke-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException("Cannot create temporary directory: $dir");
}
$key = bin2hex(random_bytes(32));

function cleanup(string $path): void {
    if (!is_dir($path)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) { @rmdir($f->getPathname()); }
        else { @unlink($f->getPathname()); }
    }
    @rmdir($path);
}
register_shutdown_function(static function() use ($dir): void { cleanup($dir); });

$passed = 0;
$failed = 0;
function check(string $name, callable $test): void {
    global $passed, $failed;
    try {
        $test();
        ++$passed;
        echo "PASS: $name\n";
    } catch (Throwable $t) {
        ++$failed;
        echo "FAIL: $name :: " . get_class($t) . ': ' . $t->getMessage() . "\n";
    }
}
function ensure(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
// Data file of a single-layout database (docs/STORAGE_FORMAT.md §1, §4.3, §5.1):
// {dbDir}/{locator}.ndb4/generations/{generation named by CURRENT}/segments/main.jsonl
function dbFileFor(string $dir, string $key, string $name): string {
    $home = rtrim($dir, '/') . '/' . substr(hash_hmac('sha256', 'nonedb4/db/' . $name, $key), 0, 32) . '.ndb4/';
    $current = json_decode((string)@file_get_contents($home . 'CURRENT'), true);
    ensure(is_array($current) && is_int($current['generation'] ?? null), 'Expected a CURRENT record of ' . $name);
    $file = $home . 'generations/' . $current['generation'] . '/segments/main.jsonl';
    ensure(is_file($file), 'Expected the segment file of ' . $name);
    return $file;
}
// Key-index checkpoint of a segment: {seg}.kix next to {seg}.jsonl (§6.4)
function checkpointFor(string $dataFile): string {
    return substr($dataFile, 0, -strlen('.jsonl')) . '.kix';
}
function childRead(string $dir, string $key, string $dbname): array {
    $cmd = [PHP_BINARY, __FILE__, '--child-find', $dir, $key, $dbname];
    $pipes = [];
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    ensure(is_resource($process), 'proc_open unavailable');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    ensure($status === 0, 'child failed: ' . $stderr);
    $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    ensure(is_array($result), 'child returned non-array JSON');
    return $result;
}

check('A1: complete unindexed JSONL line is recovered', static function() use ($dir, $key): void {
    $db = new noneDB(configFor($dir, $key));
    ensure(($db->insert('stale', ['v' => 'initial'])['n'] ?? 0) === 1, 'first insert failed');
    $file = dbFileFor($dir, $key, 'stale');
    $bytes = file_put_contents($file, "{\"v\":\"after-crash\",\"key\":1}\n", FILE_APPEND);
    ensure($bytes !== false, 'could not emulate crash window');
    $rows = childRead($dir, $key, 'stale');
    ensure(count($rows) === 2, 'stale index hid a complete JSONL record; count=' . count($rows));
    ensure($rows[1]['v'] === 'after-crash', 'replayed record content was not preserved');
});

check('A1: incomplete tail never swallows the next committed insert', static function() use ($dir, $key): void {
    $db = new noneDB(configFor($dir, $key));
    ensure(($db->insert('torn', ['v' => 'first'])['n'] ?? 0) === 1, 'initial insert failed');
    $file = dbFileFor($dir, $key, 'torn');
    ensure(file_put_contents($file, '{"uncommitted":"fragment"', FILE_APPEND) !== false, 'tail injection failed');
    ensure(($db->insert('torn', ['v' => 'second'])['n'] ?? 0) === 1, 'subsequent insert failed');
    $index = checkpointFor($file);
    ensure(is_file($index), 'index not found');
    ensure(unlink($index), 'cannot force index reconstruction');
    $rows = childRead($dir, $key, 'torn');
    ensure(count($rows) === 2, 'committed record lost after index rebuild');
    ensure($rows[0]['v'] === 'first' && $rows[1]['v'] === 'second', 'record order/content changed');
});

check('A1: complete JSON without newline is not committed', static function() use ($dir, $key): void {
    $db = new noneDB(configFor($dir, $key));
    ensure(($db->insert('tornfull', ['v' => 'first'])['n'] ?? 0) === 1, 'first insert failed');
    $file = dbFileFor($dir, $key, 'tornfull');
    ensure(file_put_contents($file, '{"v":"should-disappear","key":99}', FILE_APPEND) !== false, 'tail injection failed');
    ensure(($db->insert('tornfull', ['v' => 'second'])['n'] ?? 0) === 1, 'subsequent insert failed');
    ensure(unlink(checkpointFor($file)), 'could not delete checkpoint');
    $rows = childRead($dir, $key, 'tornfull');
    ensure(count($rows) === 2, 'uncommitted JSON tail was accepted or valid record was lost');
    ensure($rows[1]['v'] === 'second', 'committed record incorrect');
});

check('A10: fractional lockTimeout is preserved as a float', static function() use ($dir, $key): void {
    $config = configFor($dir, $key);
    $config['lockTimeout'] = 0.5;
    $db = new noneDB($config);
    ensure(abs((float)noneDB\Tests\Support\Testing::getOf($db, 'lockTimeout') - 0.5) < 1e-9, '0.5 seconds was truncated');
});

check('A12: JSON serialization preserves float precision', static function() use ($dir, $key): void {
    $before = ini_get('serialize_precision');
    try {
        ini_set('serialize_precision', '5');
        $db = new noneDB(configFor($dir, $key));
        ensure(($db->insert('precision', ['p' => 28.980345])['n'] ?? 0) === 1, 'insert failed');
        $rows = $db->find('precision');
        ensure($rows[0]['p'] === 28.980345, 'precision lost: ' . var_export($rows[0]['p'], true));
    } finally {
        if ($before !== false) { ini_set('serialize_precision', $before); }
    }
});

check('C1: distinct keeps type-sensitive identities and original order', static function() use ($dir, $key): void {
    $db = new noneDB(configFor($dir, $key));
    $values = [1, '1', 1.0, 0, -0.0, 0.0, true, false, [1, 'x'], [1, 'x'], null];
    $items = array_map(static function($v): array { return ['v' => $v]; }, $values);
    ensure(($db->insert('distinct', $items)['n'] ?? 0) === count($items), 'insert failed');
    $expected = [];
    foreach ($values as $v) {
        if ($v !== null && !in_array($v, $expected, true)) { $expected[] = $v; }
    }
    ensure($db->distinct('distinct', 'v') === $expected, 'distinct semantics differ from strict reference');
    ensure($db->query('distinct')->distinct('v') === $expected, 'builder distinct differs');
});

check('C3: mixed numeric/non-numeric string sorting is deterministic', static function() use ($dir, $key): void {
    $db = new noneDB(configFor($dir, $key));
    $values = ['9', '10', '1a', '2', '20', 'alpha'];
    for ($shift = 0; $shift < count($values); ++$shift) {
        $permutation = array_merge(array_slice($values, $shift), array_slice($values, 0, $shift));
        $rows = array_map(static function($v): array { return ['v' => $v]; }, $permutation);
        $sorted = array_column($db->sort($rows, 'v'), 'v');
        ensure($sorted === ['2', '9', '10', '20', '1a', 'alpha'], 'non-total or unexpected sort order: ' . json_encode($sorted));
    }
});

check('C6: builder key filter consistent in multiple clauses', static function() use ($dir, $key): void {
    $db = new noneDB(configFor($dir, $key));
    ensure(($db->insert('keyfilter', ['v' => 'match'])['n'] ?? 0) === 1, 'insert failed');
    $rows = $db->query('keyfilter')->where(['key' => '0'])->where(['v' => 'match'])->get();
    ensure(count($rows) === 1 && $rows[0]['key'] === 0, 'builder changed key semantics');
});

check('C7: whereNot accepts and negates compound operators', static function() use ($dir, $key): void {
    $db = new noneDB(configFor($dir, $key));
    ensure(($db->insert('notrange', [['age' => 10], ['age' => 20], ['age' => 70]])['n'] ?? 0) === 3, 'insert failed');
    $rows = $db->query('notrange')->whereNot(['age' => ['$gte' => 18, '$lt' => 65]])->get();
    ensure(array_column($rows, 'age') === [10, 70], 'whereNot operator did not exclude age 20');
});

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
