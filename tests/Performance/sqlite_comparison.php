<?php
/**
 * noneDB vs SQLite
 *
 *   php tests/Performance/sqlite_comparison.php [sizes] [--runs=5] [--sync=off|normal|full] [--markdown]
 *   sizes: comma separated, default 1000,10000,100000
 *
 * Engines: noneDB with field indexes, noneDB with the same indexes and fieldIndexEnabled false (maintained,
 * not used by queries), SQLite storing JSON documents with expression indexes (json_extract), SQLite with
 * typed columns and B-tree indexes (an upper bound: not a document store). Every engine and size runs in
 * its own child process, N times; reported: median, min and p95 of each workload in ms, warm: the calls a
 * workload measures run once unmeasured first (find_key, find_unique, first and exists are averaged over 20
 * calls, insert_500 and update_300 time all their calls), so the indexes they use are loaded. Result counts and
 * first keys must agree between engines, else the row says "semantics differ". SQLite runs in WAL mode; its
 * synchronous mode follows --sync (off = like noneDB's default durability 'performance'; SQLite's own default
 * is FULL). Needs pdo_sqlite;
 * without it the script exits 0 with a message. No timing is asserted anywhere (CI runs it as a smoke
 * test: php tests/Performance/sqlite_comparison.php 100 --runs=1).
 */

if (!extension_loaded('pdo_sqlite')) {
    echo "pdo_sqlite is not available: nothing to compare\n";
    exit(0);
}

$args = array_slice($argv, 1);
$options = ['runs' => 5, 'sync' => 'off', 'markdown' => false, 'child' => null];
$sizes = [1000, 10000, 100000];
foreach ($args as $arg) {
    if (preg_match('/^--runs=(\d+)$/', $arg, $m)) {
        $options['runs'] = max(1, (int)$m[1]);
    } elseif (preg_match('/^--sync=(off|normal|full)$/', $arg, $m)) {
        $options['sync'] = $m[1];
    } elseif ($arg === '--markdown') {
        $options['markdown'] = true;
    } elseif (preg_match('/^--child=(.+)$/', $arg, $m)) {
        $options['child'] = $m[1];
    } elseif (preg_match('/^[\d,]+$/', $arg)) {
        $sizes = array_map('intval', explode(',', $arg));
    }
}

function record(int $i): array
{
    $cities = ['Istanbul', 'Ankara', 'Izmir', 'Bursa', 'Antalya'];
    return ['name' => 'User' . $i, 'email' => "user{$i}@test.com", 'age' => 20 + ($i % 50), 'salary' => 5000 + ($i % 10000),
        'city' => $cities[$i % 5], 'active' => $i % 3 !== 0, 'uid' => $i % 977];
}

/** Workloads: name => [kind, callable per engine] */
interface Engine
{
    public function setUp(int $size): void;
    public function run(string $workload, int $size, int $i): array; // [count, first key]
}

final class NoneDBEngine implements Engine
{
    private $db;
    private $indexed;

    public function __construct(string $dir, bool $indexed)
    {
        require_once __DIR__ . '/../../noneDB.php';
        $this->indexed = $indexed;
        $this->db = new noneDB(['secretKey' => 'sqlite_comparison_' . bin2hex(random_bytes(4)), 'dbDir' => $dir, 'fieldIndexEnabled' => $indexed]);
    }

    public function setUp(int $size): void
    {
        $this->db->createFieldIndex('bench', 'city');
        $this->db->createFieldIndex('bench', 'age');
        $this->db->createFieldIndex('bench', 'email');
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            $rows[] = record($i);
        }
        $this->db->insert('bench', $rows);
        $this->db->insert('owners', array_map(function ($u) { return ['uid' => $u, 'owner' => 'O' . $u]; }, range(0, 976)));
        $this->db->createFieldIndex('owners', 'uid');
    }

    private static function out(array $rows): array
    {
        return [count($rows), $rows[0]['key'] ?? null];
    }

    public function run(string $workload, int $size, int $i): array
    {
        $db = $this->db;
        switch ($workload) {
            case 'find_key':
                return self::out($db->find('bench', ['key' => ($i * 7919) % $size]));
            case 'find_eq':
                return self::out($db->find('bench', ['city' => 'Izmir']));
            case 'find_unique':
                return self::out($db->find('bench', ['email' => 'user' . (($i * 31) % $size) . '@test.com']));
            case 'range':
                return self::out($db->between('bench', 'age', 30, 33));
            case 'count_filter':
                return [$db->count('bench', ['city' => 'Ankara']), null];
            case 'first':
                $r = $db->query('bench')->where(['city' => 'Bursa'])->first();
                return [$r === null ? 0 : 1, $r['key'] ?? null];
            case 'exists':
                return [(int)$db->query('bench')->where(['email' => 'user5@test.com'])->exists(), null];
            case 'sort_limit':
                return self::out($db->query('bench')->where(['city' => 'Izmir'])->sort('salary', 'desc')->limit(10)->get());
            case 'distinct':
                return [count($db->distinct('bench', 'uid')), null];
            case 'sum_filter':
                return [(int)$db->sum('bench', 'salary', ['city' => 'Antalya']), null];
            case 'join_limit':
                return self::out($db->query('bench')->where(['city' => 'Izmir'])->join('owners', 'uid', 'uid', 'o')->limit(50)->get());
            case 'insert_500':
                for ($k = 0; $k < 500; $k++) {
                    $db->insert('bench', record($size + $i * 500 + $k));
                }
                return [500, null];
            case 'update_300':
                for ($k = 0; $k < 300; $k++) {
                    $db->update('bench', [['key' => ($k * 7919 + $i) % $size], ['set' => ['salary' => $k]]]);
                }
                return [300, null];
        }
        throw new RuntimeException($workload);
    }
}

final class SQLiteEngine implements Engine
{
    private $pdo;
    private $columns;

    public function __construct(string $file, bool $columns, string $sync)
    {
        $this->columns = $columns;
        $this->pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = ' . strtoupper($sync));
    }

    public function setUp(int $size): void
    {
        if ($this->columns) {
            $this->pdo->exec('CREATE TABLE bench (id INTEGER PRIMARY KEY, name TEXT, email TEXT, age INT, salary INT, city TEXT, active INT, uid INT)');
            $this->pdo->exec('CREATE INDEX b_city ON bench(city)');
            $this->pdo->exec('CREATE INDEX b_age ON bench(age)');
            $this->pdo->exec('CREATE INDEX b_email ON bench(email)');
            $insert = $this->pdo->prepare('INSERT INTO bench VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        } else {
            $this->pdo->exec('CREATE TABLE bench (id INTEGER PRIMARY KEY, body TEXT)');
            $this->pdo->exec("CREATE INDEX b_city ON bench(json_extract(body, '$.city'))");
            $this->pdo->exec("CREATE INDEX b_age ON bench(json_extract(body, '$.age'))");
            $this->pdo->exec("CREATE INDEX b_email ON bench(json_extract(body, '$.email'))");
            $insert = $this->pdo->prepare('INSERT INTO bench VALUES (?, ?)');
        }
        $this->pdo->exec('CREATE TABLE owners (uid INT, owner TEXT)');
        $this->pdo->exec('CREATE INDEX o_uid ON owners(uid)');
        $this->pdo->beginTransaction();
        for ($i = 0; $i < $size; $i++) {
            $r = record($i);
            $insert->execute($this->columns ? [$i, $r['name'], $r['email'], $r['age'], $r['salary'], $r['city'], (int)$r['active'], $r['uid']]
                : [$i, json_encode($r)]);
        }
        $owner = $this->pdo->prepare('INSERT INTO owners VALUES (?, ?)');
        for ($u = 0; $u < 977; $u++) {
            $owner->execute([$u, 'O' . $u]);
        }
        $this->pdo->commit();
    }

    private function col(string $field): string
    {
        // "b.uid": a column of table alias b
        $alias = '';
        if (strpos($field, '.') !== false) {
            [$alias, $field] = explode('.', $field, 2);
            $alias .= '.';
        }
        return $this->columns ? $alias . $field : "json_extract({$alias}body, '$.{$field}')";
    }

    private function query(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_NUM);
        return [count($rows), isset($rows[0][0]) ? (int)$rows[0][0] : null];
    }

    public function run(string $workload, int $size, int $i): array
    {
        $all = $this->columns ? '*' : 'id, body';
        switch ($workload) {
            case 'find_key':
                return $this->query("SELECT $all FROM bench WHERE id = ?", [($i * 7919) % $size]);
            case 'find_eq':
                return $this->query("SELECT $all FROM bench WHERE {$this->col('city')} = ? ORDER BY id", ['Izmir']);
            case 'find_unique':
                return $this->query("SELECT $all FROM bench WHERE {$this->col('email')} = ?", ['user' . (($i * 31) % $size) . '@test.com']);
            case 'range':
                return $this->query("SELECT $all FROM bench WHERE {$this->col('age')} BETWEEN 30 AND 33 ORDER BY id");
            case 'count_filter':
                $r = $this->pdo->query("SELECT COUNT(*) FROM bench WHERE {$this->col('city')} = 'Ankara'")->fetchColumn();
                return [(int)$r, null];
            case 'first':
                return $this->query("SELECT $all FROM bench WHERE {$this->col('city')} = 'Bursa' ORDER BY id LIMIT 1");
            case 'exists':
                $r = $this->pdo->query("SELECT EXISTS(SELECT 1 FROM bench WHERE {$this->col('email')} = 'user5@test.com')")->fetchColumn();
                return [(int)$r, null];
            case 'sort_limit':
                return $this->query("SELECT $all FROM bench WHERE {$this->col('city')} = 'Izmir' ORDER BY {$this->col('salary')} DESC, id LIMIT 10");
            case 'distinct':
                return [(int)$this->pdo->query("SELECT COUNT(DISTINCT {$this->col('uid')}) FROM bench")->fetchColumn(), null];
            case 'sum_filter':
                return [(int)$this->pdo->query("SELECT SUM({$this->col('salary')}) FROM bench WHERE {$this->col('city')} = 'Antalya'")->fetchColumn(), null];
            case 'join_limit':
                return $this->query("SELECT b.id, o.owner FROM bench b LEFT JOIN owners o ON o.uid = {$this->col('b.uid')} WHERE {$this->col('b.city')} = 'Izmir' ORDER BY b.id LIMIT 50");
            case 'insert_500':
                $insert = $this->columns ? $this->pdo->prepare('INSERT INTO bench VALUES (?, ?, ?, ?, ?, ?, ?, ?)') : $this->pdo->prepare('INSERT INTO bench VALUES (?, ?)');
                for ($k = 0; $k < 500; $k++) {
                    $id = $size + $i * 500 + $k;
                    $r = record($id);
                    $insert->execute($this->columns ? [$id, $r['name'], $r['email'], $r['age'], $r['salary'], $r['city'], (int)$r['active'], $r['uid']] : [$id, json_encode($r)]);
                }
                return [500, null];
            case 'update_300':
                $update = $this->columns ? $this->pdo->prepare('UPDATE bench SET salary = ? WHERE id = ?')
                    : $this->pdo->prepare("UPDATE bench SET body = json_set(body, '$.salary', ?) WHERE id = ?");
                for ($k = 0; $k < 300; $k++) {
                    $update->execute([$k, ($k * 7919 + $i) % $size]);
                }
                return [300, null];
        }
        throw new RuntimeException($workload);
    }
}

const WORKLOADS = ['find_key', 'find_eq', 'find_unique', 'range', 'count_filter', 'first', 'exists', 'sort_limit', 'distinct',
    'sum_filter', 'join_limit', 'insert_500', 'update_300'];
const ENGINES = ['noneDB-indexed', 'noneDB-scan', 'SQLite-doc', 'SQLite-columns'];

/** Child process: one engine, one size, one run. Prints JSON [workload => [ms, count, firstKey]] */
function child(string $spec, string $sync): void
{
    [$engineName, $size, $dir] = explode('|', $spec);
    $size = (int)$size;
    $engine = strpos($engineName, 'noneDB') === 0
        ? new NoneDBEngine($dir, $engineName === 'noneDB-indexed')
        : new SQLiteEngine($dir . '/bench.sqlite', $engineName === 'SQLite-columns', $sync);
    $engine->setUp($size);
    $results = [];
    foreach (WORKLOADS as $workload) {
        $repeat = in_array($workload, ['find_key', 'find_unique', 'exists', 'first'], true) ? 20 : 1;
        foreach ($repeat === 1 ? [0] : range(1, $repeat) as $i) {
            $engine->run($workload, $size, $i); // warm: the calls measured below (a write workload: a call of its own)
        }
        $start = hrtime(true);
        $out = [0, null];
        for ($i = 1; $i <= $repeat; $i++) {
            $out = $engine->run($workload, $size, $i);
        }
        $results[$workload] = [(hrtime(true) - $start) / 1e6 / $repeat, $out[0], $out[1]];
    }
    echo json_encode($results);
}

if ($options['child'] !== null) {
    child($options['child'], $options['sync']);
    exit(0);
}

function percentile(array $values, float $p): float
{
    sort($values);
    $index = ($p / 100) * (count($values) - 1);
    $lo = (int)floor($index);
    $hi = (int)ceil($index);
    return $values[$lo] + ($values[$hi] - $values[$lo]) * ($index - $lo);
}

function removeDir(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
        if (basename($file) === '.' || basename($file) === '..') {
            continue;
        }
        is_dir($file) ? removeDir($file) : unlink($file);
    }
    @rmdir($dir);
}

echo "noneDB vs SQLite " . SQLite3::version()['versionString'] . " — PHP " . PHP_VERSION . ", " . PHP_OS_FAMILY
    . ", runs {$options['runs']}, SQLite synchronous {$options['sync']}, noneDB durability performance\n";
foreach ($sizes as $size) {
    $samples = [];
    foreach (ENGINES as $engineName) {
        for ($run = 0; $run < $options['runs']; $run++) {
            $dir = sys_get_temp_dir() . '/nonedb_sqlite_' . bin2hex(random_bytes(4));
            mkdir($dir);
            // Children get this process's memory_limit (php -d ... isn't inherited)
            $command = [PHP_BINARY, '-d', 'memory_limit=' . ini_get('memory_limit'), __FILE__, "--child={$engineName}|{$size}|{$dir}", "--sync={$options['sync']}"];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            removeDir($dir);
            $data = json_decode((string)$output, true);
            if (!is_array($data)) {
                fwrite(STDERR, "$engineName failed: " . trim($errors . "\n" . $output) . "\n");
                continue 2;
            }
            foreach ($data as $workload => $row) {
                $samples[$workload][$engineName][] = $row;
            }
        }
    }
    echo $options['markdown'] ? "\n### {$size} records (ms per operation: median / min / p95)\n\n| Workload | " . implode(' | ', ENGINES) . " | Same result |\n|---|" . str_repeat('---|', count(ENGINES) + 1) . "\n"
        : "\n{$size} records (ms per operation: median / min / p95)\n";
    foreach (WORKLOADS as $workload) {
        $cells = [];
        $results = [];
        foreach (ENGINES as $engineName) {
            $rows = $samples[$workload][$engineName] ?? [];
            if (empty($rows)) {
                $cells[] = '-';
                continue;
            }
            $times = array_column($rows, 0);
            $cells[] = sprintf('%.3f / %.3f / %.3f', percentile($times, 50), min($times), percentile($times, 95));
            $results[$engineName] = [$rows[0][1], $rows[0][2]];
        }
        $same = count(array_unique(array_map('json_encode', $results))) <= 1 ? 'yes' : 'semantics differ: ' . json_encode($results);
        echo $options['markdown'] ? "| {$workload} | " . implode(' | ', $cells) . " | {$same} |\n"
            : sprintf("  %-13s %s  (%s)\n", $workload, implode('   ', array_map(function ($e, $c) { return "$e $c"; }, ENGINES, $cells)), $same);
    }
}
