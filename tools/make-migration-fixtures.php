<?php
/**
 * Migration fixtures (tests/Migration/fixtures/): databases written by the old releases themselves, and what each
 * release returns for them, the oracle of the migration tests (docs/MIGRATION.md).
 *
 *   php tools/make-migration-fixtures.php [--only=REGEX] [--target=DIR]
 *
 * --target writes the fixtures into DIR instead of tests/Migration/fixtures (a reproducibility check compares them).
 * Each release's noneDB.php comes from git (3.3-dev from build/baseline/) and runs in a child process with a PHP it
 * supports: NONEDB_PHP74 (releases before 3.2, default "php7.4") or NONEDB_PHP8 (default: this PHP). Releases before
 * 3.0 have no constructor: their settings (dbDir, shard size, buffering) are edited in the copied file, as their users
 * did. One key serves the whole fixture: nonedb_123 (the key releases before 3.0 shipped with) when the first release
 * is older than 3.0, else a 3.x key. A step that ends with kill() stops its process with SIGKILL, so nothing runs at
 * shutdown (buffers stay).
 *
 * A fixture is tests/Migration/fixtures/{name}/db/ (the files, byte for byte) and fixture.json: the steps, the files
 * changed by hand afterwards (crafted crash states), and for every release asked, in a new process on a copy of the
 * files: each database's records as [key, record] pairs, its count and the key a new insert gets.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

const V3_KEY = 'migration-fixture-key-7c1e9a';
const V3_OTHER_KEY = 'migration-fixture-other-key-4b2d';
const OLD_KEY = 'nonedb_123'; // releases before 3.0: the key in the file

$root = dirname(__DIR__);
$php74 = getenv('NONEDB_PHP74') ?: 'php7.4';
$php8 = getenv('NONEDB_PHP8') ?: PHP_BINARY;
$releases = [
    '1.4' => ['git:620ca05', $php74, 'v1'],
    '2.0' => ['git:v2.0.0', $php74, 'v2'],
    '2.1' => ['git:v2.1.0', $php74, 'v2'],
    '2.2' => ['git:v2.2.0', $php74, 'v2'],
    '2.3' => ['git:20dab4b', $php74, 'v2'],
    '3.0' => ['git:v3.0.0', $php74, 'v3'],
    '3.1' => ['git:v3.1.0', $php74, 'v3'],
    '3.2' => ['git:v3.2.0', $php8, 'v3'],
    '3.3-dev' => ['file:build/baseline/noneDB-3.3dev.php', $php8, 'v3'],
];

// ---------------------------------------------------------------- scenarios (adapter $a, the release's $db)

$basic = <<<'PHP'
$a->insert('users', [
    ['n' => 'a', 'i' => 1, 'f' => 2.5, 'z' => 2.0, 'b' => true, 'u' => 'çğş/€'],
    ['n' => 'b', 'i' => 2, 'nested' => ['x' => 1, 'y' => [1, 2]], 'nil' => null],
    ['n' => 'c', 'i' => 3],
    ['n' => 'd', 'i' => 4, 'list' => [3, 2, 1]],
    ['n' => 'e', 'i' => 5, 'b' => false],
    ['n' => 'f', 'i' => 6],
]);
$a->update('users', ['key' => 1], ['n' => 'b2', 'extra' => 'x']);
$a->delete('users', ['key' => 2]);
$a->delete('users', ['key' => 5]);
PHP;
$sharded = <<<'PHP'
$rows = [];
for ($i = 0; $i < 23; $i++) {
    $rows[] = ['n' => $i, 'c' => 'c' . ($i % 3)];
}
$a->insert('users', $rows);
$a->update('users', ['key' => 3], ['c' => 'c1']);
$a->delete('users', ['key' => 7]);
$a->delete('users', ['key' => 22]);
PHP;
$compacted = <<<'PHP'
$rows = [];
for ($i = 0; $i < 12; $i++) {
    $rows[] = ['n' => $i];
}
$a->insert('users', $rows);
foreach ([2, 3, 8] as $k) {
    $a->delete('users', ['key' => $k]);
}
$a->compact('users');
PHP;
$deletes = <<<'PHP'
$rows = [];
for ($i = 0; $i < 30; $i++) {
    $rows[] = ['n' => $i];
}
$a->insert('users', $rows);
foreach ([0, 4, 29] as $k) {
    $a->delete('users', ['key' => $k]);
}
$a->update('users', ['key' => 5], ['n' => 50]);
$a->update('users', ['key' => 5], ['n' => 500]);
PHP;
$buffered = <<<'PHP'
$a->buffer(true);
$a->insert('users', [['n' => 'a']]);
$a->insert('users', [['n' => 'b']]);
$a->insert('users', [['n' => 'c'], ['n' => 'd']]);
$a->kill();
PHP;
$shardedBuffered = $sharded . "\n" . <<<'PHP'
$a->buffer(true);
$a->insert('users', [['n' => 'x']]);
$a->insert('users', [['n' => 'y'], ['n' => 'z']]);
$a->kill();
PHP;
$fieldIndex = <<<'PHP'
$rows = [];
foreach (['ist', 'ank', 'izm', 'ist', 'bur', 'ist', 'ank', 'izm'] as $i => $c) {
    $rows[] = ['n' => $i, 'city' => $c];
}
$a->insert('users', $rows);
$a->createFieldIndex('users', 'city');
$a->insert('users', [['n' => 8, 'city' => 'ist'], ['n' => 9, 'city' => 'van']]);
$a->delete('users', ['key' => 2]);
PHP;
$spatial = <<<'PHP'
$rows = [];
foreach ([[28.97, 41.01], [32.85, 39.93], [27.14, 38.42], [29.06, 40.19], [30.71, 36.89], [43.38, 38.5]] as $i => $p) {
    $rows[] = ['n' => $i, 'loc' => ['type' => 'Point', 'coordinates' => $p]];
}
$a->insert('users', $rows);
$a->createSpatialIndex('users', 'loc');
$a->delete('users', ['key' => 1]);
PHP;
$v1Quirks = <<<'PHP'
$a->insert('users', [['n' => 'a'], ['n' => 'b']]);
clearstatcache();
$db->insert('users', ['x', 'y']);
$a->update('users', ['key' => 0], ['extra' => 1]);
$a->delete('users', ['key' => 9]);
PHP;
$twoKeys = <<<'PHP'
$a->insert('users', [['n' => 'mine']]);
$other = $a->open(['secretKey' => V3_OTHER_KEY]);
$other->insert('orders', [['n' => 'theirs']]);
PHP;

$v2Releases = ['2.0', '2.1', '2.2', '2.3'];
$allReleases = array_keys($releases);
$fixtures = [];
foreach ($allReleases as $r) {
    $fixtures["$r-basic"] = ['steps' => [[$r, [], $basic]]];
    $fixtures["$r-deletes"] = ['steps' => [[$r, [], $deletes]]];
}
foreach (array_merge($v2Releases, ['3.0', '3.1', '3.2', '3.3-dev']) as $r) {
    $fixtures["$r-sharded"] = ['steps' => [[$r, ['shardSize' => 5], $sharded]]];
    $fixtures["$r-compacted"] = ['steps' => [[$r, ['shardSize' => 5], $compacted]]];
    $fixtures["$r-single-compacted"] = ['steps' => [[$r, [], $compacted]]];
}
foreach (['2.3', '3.0', '3.1', '3.2', '3.3-dev'] as $r) {
    $fixtures["$r-buffered"] = ['steps' => [[$r, [], $buffered]]];
    $fixtures["$r-sharded-buffered"] = ['steps' => [[$r, ['shardSize' => 5], $shardedBuffered]]];
}
foreach (['3.0', '3.1', '3.2', '3.3-dev'] as $r) {
    $fixtures["$r-field-index"] = ['steps' => [[$r, [], $fieldIndex]]];
    $fixtures["$r-sharded-field-index"] = ['steps' => [[$r, ['shardSize' => 5], $fieldIndex]]];
}
foreach (['3.1', '3.2', '3.3-dev'] as $r) {
    $fixtures["$r-spatial"] = ['steps' => [[$r, [], $spatial]]];
}
$fixtures['1.4-quirks'] = ['steps' => [['1.4', [], $v1Quirks]]];
$fixtures['2.2-empty-name'] = ['steps' => [['2.2', [], "\$a->insert('!!', [['n' => 1]]);\n\$a->insert('users', [['n' => 1]]);"]]];
$fixtures['3.2-two-keys'] = ['steps' => [['3.2', [], $twoKeys]]];
$fixtures['3.2-two-databases'] = ['steps' => [['3.2', ['shardSize' => 5], $sharded . "\n" . str_replace("'users'", "'orders'", $fieldIndex)]]];
// One database written by several releases, each opening what the one before left
$fixtures['2.2-3.1-3.2-converted'] = ['steps' => [
    ['2.2', [], "\$a->insert('users', [['n' => 0], ['n' => 1], ['n' => 2], ['n' => 3], ['n' => 4]]);\n\$a->delete('users', ['key' => 1]);\n\$a->delete('users', ['key' => 4]);"],
    ['3.1', [], "\$a->buffer(false);\n\$a->insert('users', [['n' => 'v31']]);\n\$a->delete('users', ['key' => 0]);"],
    ['3.2', [], "\$a->insert('users', [['n' => 'v32']]);\n\$a->delete('users', ['key' => 2]);"],
]];
$fixtures['3.1-3.3-dev-read'] = ['steps' => [['3.1', [], "\$a->buffer(false);\n" . $deletes], ['3.3-dev', [], "\$db->find('users', 0);"]]];
$fixtures['3.1-3.3-dev-written'] = ['steps' => [['3.1', [], "\$a->buffer(false);\n" . $deletes], ['3.3-dev', [], "\$a->insert('users', [['n' => 'v33']]);"]]];
// Crafted crash states: what an interrupted process leaves, made by hand on a release's own files
$flushing = function (string $dir) {
    foreach (glob("$dir/*.nonedb.buffer") as $buffer) {
        rename($buffer, "$buffer.flushing");
    }
};
foreach (['2.3', '3.0', '3.1'] as $r) {
    $fixtures["$r-flushing"] = ['steps' => [[$r, [], $buffered]], 'craft' => ['rename *.nonedb.buffer to *.nonedb.buffer.flushing (a flush that died after its rename)', $flushing],
        'oracles' => [$r, '3.2']];
}
$fixtures['3.2-flushing-stored'] = ['steps' => [['3.2', [], $buffered]], 'craft' => ['a flush that died after storing its first record: that record appended and indexed under the flush\'s first key, the buffer renamed to .flushing.{that key}', function (string $dir) {
    $data = glob("$dir/*-users.nonedb")[0];
    $buffer = "$data.buffer";
    $index = json_decode((string)file_get_contents("$data.jidx"), true);
    $first = (int)$index['n'];
    $lines = explode("\n", trim((string)file_get_contents($buffer)));
    $record = json_decode($lines[0], true) + ['key' => $first];
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $offset = filesize($data);
    file_put_contents($data, $line . "\n", FILE_APPEND);
    $index['o'][(string)$first] = [$offset, strlen($line)];
    $index['n'] = $first + 1;
    $index['s'] = $offset + strlen($line) + 1;
    file_put_contents("$data.jidx", json_encode($index));
    rename($buffer, "$buffer.flushing.$first");
}]];
$fixtures['3.2-lines-after-s'] = ['steps' => [['3.2', [], $deletes]], 'craft' => ['append a complete record line after the index\'s s (a write that died before its index)', function (string $dir) {
    $data = glob("$dir/*-users.nonedb")[0];
    file_put_contents($data, "{\"n\":\"ghost\",\"key\":30}\n", FILE_APPEND);
}]];
foreach (['3.0', '3.1', '3.2'] as $r) {
    $fixtures["$r-index-lost"] = ['steps' => [[$r, [], ($r === '3.2' ? '' : "\$a->buffer(false);\n") . $deletes]], 'craft' => ['remove the .jidx of the data file', function (string $dir) {
        unlink(glob("$dir/*-users.nonedb.jidx")[0]);
    }], 'oracles' => [$r, '3.2']];
}
// 3.3-dev wrote its checkpoints now and then and read the data file itself without one (MIGRATION.md §4.1 J33)
$fixtures['3.3-dev-index-lost'] = ['steps' => [['3.3-dev', [], $deletes]], 'craft' => ['remove the .jidx of the data file (a checkpoint never written or lost)', function (string $dir) {
    unlink(glob("$dir/*-users.nonedb.jidx")[0]);
}]];
$fixtures['3.1-meta-shard-size'] = ['steps' => [['3.1', ['shardSize' => 5], "\$a->buffer(false);\n" . $sharded]], 'craft' => ['set the meta\'s shardSize to 7', function (string $dir) {
    $meta = glob("$dir/*-users.nonedb.meta")[0];
    $m = json_decode((string)file_get_contents($meta), true);
    $m['shardSize'] = 7;
    file_put_contents($meta, json_encode($m, JSON_PRETTY_PRINT));
}]];
$fixtures['3.2-meta-lost'] = ['steps' => [['3.2', ['shardSize' => 5], $sharded]], 'craft' => ['remove the meta of a sharded database', function (string $dir) {
    unlink(glob("$dir/*-users.nonedb.meta")[0]);
}]];
$fixtures['3.2-leftovers'] = ['steps' => [['3.2', [], "\$a->insert('users', [['n' => 1]]);\n\$a->insert('gone', [['n' => 1]]);\n\$db->dropDB('gone');"]]];

// ---------------------------------------------------------------- running a release

/** The release's noneDB.php for these settings: releases before 3.0 get them written into the file */
function releaseLibrary(string $root, array $release, array $settings, string $dbDir): string
{
    [$source, , $family] = $release;
    $code = strncmp($source, 'git:', 4) === 0
        ? (string)shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg(substr($source, 4) . ':noneDB.php'))
        : (string)file_get_contents($root . '/' . substr($source, 5));
    if ($code === '') {
        throw new RuntimeException("cannot read $source");
    }
    if ($family !== 'v3') {
        $set = ['dbDir' => var_export($dbDir . '/', true), 'secretKey' => var_export($settings['key'], true)];
        if (isset($settings['shardSize'])) {
            $set['shardSize'] = (string)(int)$settings['shardSize'];
        }
        foreach ($set as $property => $value) {
            $code = preg_replace('/(private \$' . $property . '\s*=\s*)[^;]+;/', '${1}' . $value . ';', $code, 1, $count);
            if ($count !== 1 && $property !== 'shardSize') {
                throw new RuntimeException("$source: no \$$property property");
            }
        }
    }
    return $code;
}

/** Run $body with the release in a new process; the adapter $a hides the differences of the releases' APIs */
function runRelease(string $root, array $release, array $settings, string $work, string $dbDir, string $key, string $body): void
{
    [, $php, $family] = $release;
    $lib = "$work/lib-" . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($lib, releaseLibrary($root, $release, ['key' => $key] + $settings, $dbDir));
    $config = $family === 'v3' ? var_export(['secretKey' => $key, 'dbDir' => $dbDir . '/', 'autoCreateDB' => true]
        + (isset($settings['shardSize']) ? ['shardSize' => $settings['shardSize']] : []), true) : 'null';
    $script = "$work/run-" . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($script, '<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
const V3_OTHER_KEY = ' . var_export(V3_OTHER_KEY, true) . ';
require ' . var_export($lib, true) . ';
final class Adapter {
    public $db;
    private $family;
    private $config;
    public function __construct(string $family, $config) {
        $this->family = $family;
        $this->config = $config;
        $this->db = $this->open([]);
    }
    public function open(array $over) {
        return $this->family === "v3" ? new noneDB($over + $this->config) : new noneDB();
    }
    // 1.4 reads a file with the size stat() cached for the process, so a second write in one process loses the
    // first: each operation runs as in its own request
    private function request() { if ($this->family === "v1") { clearstatcache(); } }
    public function insert($db, array $rows) { $this->request(); $r = $this->db->insert($db, count($rows) === 1 ? $rows[0] : $rows); if (isset($r["error"])) { throw new RuntimeException("insert: " . $r["error"]); } }
    public function update($db, array $filter, array $set) { $this->request(); $this->db->update($db, [$filter, ["set" => $set]]); }
    public function delete($db, array $filter) { $this->request(); $this->db->delete($db, $filter); }
    public function compact($db) { $this->db->compact($db); }
    public function createFieldIndex($db, $field) { $this->db->createFieldIndex($db, $field); }
    public function createSpatialIndex($db, $field) { $this->db->createSpatialIndex($db, $field); }
    public function buffer(bool $on) {
        if (method_exists($this->db, "enableBuffering")) {
            $this->db->enableBuffering($on);
        } elseif ($this->family === "v3") {
            $this->db = $this->open(["bufferEnabled" => $on]);
        }
    }
    public function kill() { posix_kill(getmypid(), 9); }
}
$a = new Adapter(' . var_export($family, true) . ', ' . $config . ');
$db = $a->db;
' . $body . "\n");
    // Without notices (1.4 gives one on a key that isn't there), and what is left comes here whatever php.ini says
    $process = proc_open([$php, '-d', 'display_errors=1', '-d', 'log_errors=0', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $work);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    unlink($script);
    unlink($lib);
    $killed = strpos($body, '$a->kill()') !== false;
    if ((!$killed && $status !== 0) || trim($out) !== '') {
        throw new RuntimeException("release run failed ($status): " . trim($out) . "\n--- body:\n$body");
    }
}

/**
 * What a release returns for the databases of a directory, read in a new process on a copy: records by key (as find()
 * returns them, a record of 1.4 gets its position as "key"), count(), and the key a new record gets (inserted, flushed,
 * found again)
 */
function oracle(string $root, array $release, array $settings, string $work, string $dir, array $names, string $key): array
{
    $copy = "$work/oracle-" . bin2hex(random_bytes(4));
    copyTree($dir, $copy);
    $out = "$work/oracle-" . bin2hex(random_bytes(4)) . '.json';
    $body = '$result = [];
foreach (' . var_export($names, true) . ' as $name) {
    clearstatcache();
    $rows = $db->find($name, 0);
    $records = [];
    foreach (is_array($rows) ? $rows : [] as $i => $row) {
        if ($row === null) {
            continue;
        }
        if (!array_key_exists("key", $row)) {
            $row["key"] = $i; // 1.4: the raw data array, the position is the key
        }
        $records[$row["key"]] = $row;
    }
    ksort($records);
    $pairs = [];
    foreach ($records as $k => $record) {
        $pairs[] = [$k, $record]; // [key, record]: a JSON object keyed 0, 1, … would come back as a list
    }
    $count = method_exists($db, "count") ? $db->count($name) : count($records);
    $a->insert($name, [["__probe" => 1]]);
    if (method_exists($db, "flush")) {
        $db->flush($name);
    }
    $next = null;
    clearstatcache();
    foreach ($db->find($name, 0) as $i => $row) {
        if (is_array($row) && isset($row["__probe"])) {
            $next = $row["key"] ?? $i;
        }
    }
    $result[$name] = ["records" => $pairs, "count" => $count, "nextKey" => $next];
}
file_put_contents(' . var_export($out, true) . ', json_encode($result, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));';
    runRelease($root, $release, $settings, $work, $copy, $key, $body);
    $result = json_decode((string)file_get_contents($out), true);
    unlink($out);
    removeTree($copy);
    return $result;
}

function copyTree(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    foreach (scandir($from) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        is_dir("$from/$entry") ? copyTree("$from/$entry", "$to/$entry") : copy("$from/$entry", "$to/$entry");
    }
}

function removeTree(string $dir): void
{
    foreach (is_dir($dir) ? scandir($dir) : [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            is_dir("$dir/$entry") ? removeTree("$dir/$entry") : unlink("$dir/$entry");
        }
    }
    @rmdir($dir);
}

/** Database names of a directory: the names in the old file names (the key decides which are whose) */
function databaseNames(string $dir): array
{
    $names = [];
    foreach (scandir($dir) as $entry) {
        if (preg_match('/^[0-9a-f]{20}-(.*?)(?:_s\d+)?\.nonedb(?:\.meta)?$/', $entry, $m) && $m[1] !== '') {
            $names[$m[1]] = true;
        }
    }
    ksort($names);
    return array_keys($names);
}

// ---------------------------------------------------------------- main

$only = null;
$target = "$root/tests/Migration/fixtures";
foreach (array_slice($argv, 1) as $arg) {
    if (strncmp($arg, '--only=', 7) === 0) {
        $only = substr($arg, 7);
    } elseif (strncmp($arg, '--target=', 9) === 0) {
        $target = rtrim(substr($arg, 9), '/');
    } else {
        fwrite(STDERR, "usage: php tools/make-migration-fixtures.php [--only=REGEX] [--target=DIR]\n");
        exit(64);
    }
}
$versions = [];
foreach ([$php74, $php8] as $php) {
    $versions[$php] = trim((string)shell_exec(escapeshellarg($php) . ' -r "echo PHP_VERSION;"'));
    if ($versions[$php] === '') {
        fwrite(STDERR, "no PHP at $php (set NONEDB_PHP74 / NONEDB_PHP8)\n");
        exit(1);
    }
}
$work = sys_get_temp_dir() . '/nonedb-fixtures-' . getmypid();
foreach ($fixtures as $name => $fixture) {
    if ($only !== null && !preg_match('/' . $only . '/', $name)) {
        continue;
    }
    removeTree($work);
    mkdir("$work/db", 0777, true);
    // One key for the whole fixture: the default of the first release (later releases of a chain open its files)
    $key = $releases[$fixture['steps'][0][0]][2] === 'v3' ? V3_KEY : OLD_KEY;
    $steps = [];
    foreach ($fixture['steps'] as [$label, $settings, $body]) {
        runRelease($root, $releases[$label], $settings, $work, "$work/db", $key, $body);
        $steps[] = ['release' => $label, 'php' => $versions[$releases[$label][1]], 'settings' => $settings, 'code' => $body];
    }
    $crafted = null;
    if (isset($fixture['craft'])) {
        [$crafted, $craft] = $fixture['craft'];
        $craft("$work/db");
    }
    // Read with the settings of the last writer: before 3.2 a release maps keys with its own shard size
    [$last, $lastSettings] = end($fixture['steps']);
    $names = databaseNames("$work/db");
    $oracles = [];
    foreach ($fixture['oracles'] ?? [$last] as $label) {
        $oracles[$label] = oracle($root, $releases[$label], $lastSettings, $work, "$work/db", $names, $key);
    }
    removeTree("$target/$name");
    copyTree("$work/db", "$target/$name/db");
    file_put_contents("$target/$name/fixture.json", json_encode([
        'fixture' => $name,
        'sourceKey' => $key,
        'steps' => $steps,
        'crafted' => $crafted,
        'oracles' => $oracles,
    ], JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo "$name\n";
}
removeTree($work);
