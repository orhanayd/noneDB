<?php
/**
 * Large-database benchmark (the 10M-record targets of docs/PERFORMANCE.md §3), one library per run.
 *
 *   php tests/Performance/large.php run --lib=noneDB.php --out=DIR [options]
 *
 * Options: --total=10000000  --shard-sizes=100000,10000  --cold-samples=40  --warm-samples=200
 *          --work=<dir for the databases (default: system temp)>  --purge (run `sudo -n purge` before each
 *          OS-cold sample; without it "cold" means a new PHP process with the OS file cache warm)
 *
 * After the build every file of the database is read once, so each measurement starts with the OS file cache
 * warm. Right after a build the new files are not all in the cache yet, and a library that reads indexes
 * on demand would pay for that in its "warm" queries, while one that loads everything at its first query
 * would not.
 *
 * Data (deterministic): record j has group = j % (total / 1000) (every group value has exactly 1000
 * records, spread over every shard), metric = j % 100000, tag "row-j" and a fixed payload; field index on
 * group. Every measurement runs in its own child process (proc_open, no shell). Results: one JSON line per
 * measurement in <out>.jsonl and a Markdown summary in <out>.md.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

const SECRET = 'large-bench-0123456789abcdef0123456789abcdef';
const BATCH = 20000;

if (($argv[1] ?? '') === 'child') {
    exit(childMain(json_decode($argv[2], true)));
}
exit(runMain(array_slice($argv, 1)));

/** Parent: build every variant, then measure it in child processes */
function runMain(array $args): int
{
    if (($args[0] ?? '') !== 'run') {
        fwrite(STDERR, "usage: php tests/Performance/large.php run --lib=... --out=... [options]\n");
        return 2;
    }
    $opt = [];
    foreach (array_slice($args, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
            $opt[$m[1]] = $m[2] ?? true;
        }
    }
    $lib = realpath((string)($opt['lib'] ?? ''));
    if ($lib === false || !isset($opt['out'])) {
        fwrite(STDERR, "--lib (an existing noneDB.php) and --out are required\n");
        return 2;
    }
    $total = (int)($opt['total'] ?? 10000000);
    $sizes = array_map('intval', explode(',', (string)($opt['shard-sizes'] ?? '100000,10000')));
    $coldSamples = (int)($opt['cold-samples'] ?? 40);
    $warmSamples = (int)($opt['warm-samples'] ?? 200);
    $purge = !empty($opt['purge']);
    $work = rtrim((string)($opt['work'] ?? sys_get_temp_dir()), '/') . '/nonedb-large-' . getmypid();
    $out = (string)$opt['out'];
    @mkdir(dirname($out), 0777, true);
    $jsonl = $out . '.jsonl';
    file_put_contents($jsonl, '');
    $meta = ['lib' => $lib, 'libSha256' => hash_file('sha256', $lib), 'php' => PHP_VERSION, 'os' => php_uname(),
        'total' => $total, 'shardSizes' => $sizes, 'coldSamples' => $coldSamples, 'warmSamples' => $warmSamples,
        'osCold' => $purge, 'started' => date('c')];
    emit($jsonl, ['meta' => $meta]);
    $results = [];
    foreach ($sizes as $size) {
        $dir = $work . '-' . $size . '/';
        removeTree($dir);
        mkdir($dir, 0777, true);
        $base = ['lib' => $lib, 'dir' => $dir, 'size' => $size, 'total' => $total];
        $r = [];
        $r['build'] = child($base + ['phase' => 'build'], '6000M');
        emit($jsonl, ['shardSize' => $size, 'build' => $r['build']]);
        $r['disk'] = diskUsage($dir);
        emit($jsonl, ['shardSize' => $size, 'disk' => $r['disk']]);
        $r['cacheWarm'] = warmFileCache($dir);
        emit($jsonl, ['shardSize' => $size, 'cacheWarm' => $r['cacheWarm']]);
        $r['warm'] = child($base + ['phase' => 'warm', 'samples' => $warmSamples, 'limit' => 'auto'], '6000M');
        emit($jsonl, ['shardSize' => $size, 'warm' => $r['warm']]);
        foreach (['cold-key', 'cold-group'] as $phase) {
            $samples = [];
            for ($i = 0; $i < $coldSamples; $i++) {
                if ($purge) {
                    $p = proc_open(['sudo', '-n', 'purge'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                    if (!is_resource($p) || proc_close($p) !== 0) {
                        throw new RuntimeException('--purge needs `sudo -n purge` to work (passwordless sudo for purge)');
                    }
                }
                $samples[] = child($base + ['phase' => $phase, 'i' => $i, 'limit' => 'auto'], '6000M');
            }
            $r[$phase] = summarize($samples);
            emit($jsonl, ['shardSize' => $size, $phase => $r[$phase]]);
        }
        $r['limited-384'] = child($base + ['phase' => 'limited', 'samples' => 40, 'limit' => 384 * 1048576], '6000M');
        emit($jsonl, ['shardSize' => $size, 'limited-384' => $r['limited-384']]);
        $r['budget-512'] = child($base + ['phase' => 'budget', 'limit' => 'auto'], '512M', true);
        emit($jsonl, ['shardSize' => $size, 'budget-512' => $r['budget-512']]);
        $r['budget-128'] = child($base + ['phase' => 'budget', 'limit' => 'auto'], '128M', true);
        emit($jsonl, ['shardSize' => $size, 'budget-128' => $r['budget-128']]);
        $results[$size] = $r;
        removeTree($dir);
    }
    emit($jsonl, ['finished' => date('c')]);
    file_put_contents($out . '.md', markdown($meta, $results));
    echo file_get_contents($out . '.md');
    return 0;
}

/** Run one measurement in a child process; $tolerateFailure: report a failed child instead of throwing */
function child(array $job, string $memoryLimit, bool $tolerateFailure = false): array
{
    $cmd = [PHP_BINARY, '-d', 'memory_limit=' . $memoryLimit, __FILE__, 'child', json_encode($job)];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    $line = trim((string)strrchr("\n" . trim($stdout), "\n"));
    $data = json_decode($line, true);
    if ($code !== 0 || !is_array($data)) {
        if ($tolerateFailure) {
            return ['ok' => false, 'exit' => $code, 'error' => substr(trim($stderr . "\n" . $stdout), -500)];
        }
        throw new RuntimeException("child {$job['phase']} failed (exit $code): " . substr($stderr . $stdout, -2000));
    }
    return $data;
}

/** Child: one phase against an existing database */
function childMain(array $job): int
{
    set_time_limit(0);
    $t0 = hrtime(true);
    require $job['lib'];
    $config = ['secretKey' => SECRET, 'dbDir' => $job['dir'], 'shardSize' => $job['size'],
        'bufferEnabled' => false, 'lockTimeout' => 120];
    if (isset($job['limit'])) {
        $config['cacheMemoryLimit'] = $job['limit'];
    }
    $total = $job['total'];
    $groups = intdiv($total, 1000);
    $db = new noneDB($config);
    $ms = function (callable $fn) { $t = hrtime(true); $r = $fn(); return [(hrtime(true) - $t) / 1e6, $r]; };
    $out = [];
    switch ($job['phase']) {
        case 'build':
            [$t] = $ms(function () use ($db, $total, $groups) {
                for ($i = 0; $i < $total; $i += BATCH) {
                    $rows = [];
                    for ($j = $i, $u = min($total, $i + BATCH); $j < $u; $j++) {
                        $rows[] = ['group' => $j % $groups, 'metric' => $j % 100000, 'tag' => 'row-' . $j, 'payload' => 'noneDB-performance-2026'];
                    }
                    $r = $db->insert('large', $rows);
                    if (($r['n'] ?? -1) !== count($rows)) {
                        throw new RuntimeException('insert failed at ' . $i . ': ' . json_encode($r));
                    }
                }
            });
            $out['insert_ms'] = round($t);
            $out['insert_rows_per_s'] = round($total / ($t / 1000));
            [$t, $r] = $ms(function () use ($db) { return $db->createFieldIndex('large', 'group'); });
            if (empty($r['success'])) {
                throw new RuntimeException('createFieldIndex failed: ' . json_encode($r));
            }
            $out['create_index_ms'] = round($t);
            $out['count'] = $db->count('large');
            if ($out['count'] !== $total) {
                throw new RuntimeException("count {$out['count']} != $total");
            }
            break;
        case 'warm':
            // First call (loads indexes) and the following calls of the same process
            $ops = [
                'key_lookup' => function ($i) use ($db, $total) { return count($db->find('large', ['key' => ($i * 15485863) % $total])); },
                'indexed_query_1000' => function ($i) use ($db, $groups) { return count($db->find('large', ['group' => ($i * 97) % $groups])); },
                'indexed_query_miss' => function ($i) use ($db, $groups) { return count($db->find('large', ['group' => $groups + $i])); },
                'update_by_key' => function ($i) use ($db, $total) { return $db->update('large', [['key' => ($i * 99991) % $total], ['set' => ['metric' => -1]]])['n']; },
            ];
            foreach ($ops as $name => $fn) {
                $times = [];
                $first = null;
                $n = $name === 'indexed_query_1000' ? max(20, intdiv($job['samples'], 5)) : $job['samples'];
                for ($i = 0; $i <= $n; $i++) {
                    [$t, $r] = $ms(function () use ($fn, $i) { return $fn($i); });
                    $expect = ['key_lookup' => 1, 'indexed_query_1000' => 1000, 'indexed_query_miss' => 0, 'update_by_key' => 1][$name];
                    if ($r !== $expect) {
                        throw new RuntimeException("$name returned $r, expected $expect");
                    }
                    if ($i === 0) {
                        $first = $t;
                    } else {
                        $times[] = $t;
                    }
                }
                $out[$name] = ['first_ms' => round($first, 3)] + stats($times);
            }
            break;
        case 'cold-key':
            [$t, $r] = $ms(function () use ($db, $total, $job) { return count($db->find('large', ['key' => (7654321 + $job['i'] * 15485863) % $total])); });
            if ($r !== 1) {
                throw new RuntimeException("cold key returned $r");
            }
            $out['ms'] = $t;
            break;
        case 'cold-group':
            [$t, $r] = $ms(function () use ($db, $groups, $job) { return count($db->find('large', ['group' => (4321 + $job['i'] * 97) % $groups])); });
            if ($r !== 1000) {
                throw new RuntimeException("cold indexed query returned $r");
            }
            $out['ms'] = $t;
            break;
        case 'limited':
            // One process, cacheMemoryLimit = job limit, indexed queries over different values
            $times = [];
            for ($i = 0; $i < $job['samples']; $i++) {
                [$t, $r] = $ms(function () use ($db, $groups, $i) { return count($db->find('large', ['group' => ($i * 7919) % $groups])); });
                if ($r !== 1000) {
                    throw new RuntimeException("limited query returned $r");
                }
                $times[] = $t;
            }
            $out = stats($times);
            break;
        case 'budget':
            // The query mix of an application process under its memory_limit (512M, and PHP's default 128M)
            [$t1] = $ms(function () use ($db, $total) { return $db->find('large', ['key' => 1234567 % $total]); });
            $times = [];
            for ($i = 0; $i < 20; $i++) {
                [$t, $r] = $ms(function () use ($db, $groups, $i) { return count($db->find('large', ['group' => ($i * 131) % $groups])); });
                if ($r !== 1000) {
                    throw new RuntimeException("budget query returned $r");
                }
                $times[] = $t;
            }
            $out = ['ok' => true, 'first_key_ms' => round($t1, 3), 'indexed_query_1000' => stats($times)];
            break;
        default:
            throw new RuntimeException('unknown phase ' . $job['phase']);
    }
    $out['peak_mib'] = round(memory_get_peak_usage(true) / 1048576, 1);
    $out['process_ms'] = round((hrtime(true) - $t0) / 1e6, 1);
    echo json_encode($out), "\n";
    return 0;
}

/** Percentiles (nearest rank) of a list of milliseconds */
function stats(array $times): array
{
    sort($times);
    $n = count($times);
    $pick = function ($p) use ($times, $n) { return round($times[max(0, (int)ceil($p * $n) - 1)], 3); };
    return ['n' => $n, 'p50_ms' => $pick(0.50), 'p95_ms' => $pick(0.95), 'p99_ms' => $pick(0.99),
        'max_ms' => round($times[$n - 1], 3), 'mean_ms' => round(array_sum($times) / $n, 3)];
}

function summarize(array $samples): array
{
    return stats(array_column($samples, 'ms')) + ['peak_mib_max' => max(array_column($samples, 'peak_mib')),
        'process_ms_p50' => stats(array_column($samples, 'process_ms'))['p50_ms']];
}

function emit(string $file, array $row): void
{
    file_put_contents($file, json_encode($row) . "\n", FILE_APPEND);
}

function diskUsage(string $dir): array
{
    $bytes = 0;
    $files = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $bytes += $f->getSize();
        $files++;
    }
    return ['bytes' => $bytes, 'mib' => round($bytes / 1048576, 1), 'files' => $files];
}

/** Read every file of the database once (the OS file cache warm before the measurements) */
function warmFileCache(string $dir): array
{
    $t = hrtime(true);
    $bytes = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $fp = fopen($f->getPathname(), 'rb');
        while (!feof($fp)) {
            $bytes += strlen((string)fread($fp, 1048576));
        }
        fclose($fp);
    }
    return ['bytes' => $bytes, 'ms' => round((hrtime(true) - $t) / 1e6, 1)];
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function markdown(array $meta, array $results): string
{
    $md = "# Large-database benchmark\n\n";
    $md .= "- Library: `{$meta['lib']}` (sha256 `" . substr($meta['libSha256'], 0, 12) . "…`), PHP {$meta['php']}\n";
    $md .= "- Records: " . number_format($meta['total']) . "; cold samples: {$meta['coldSamples']} new processes; OS cache "
        . ($meta['osCold'] ? 'purged before every cold sample' : 'warm (no purge)') . "\n";
    $md .= "- After each build every database file is read once (the OS file cache warm before the measurements)\n\n";
    $md .= "| | " . implode(' | ', array_map(function ($s) use ($meta) { return number_format($s) . ' per shard (' . (int)ceil($meta['total'] / $s) . ' shards)'; }, array_keys($results))) . " |\n";
    $md .= "|---|" . str_repeat('---|', count($results)) . "\n";
    $row = function ($label, callable $fn) use ($results) {
        return "| $label | " . implode(' | ', array_map($fn, $results)) . " |\n";
    };
    $md .= $row('Bulk insert', function ($r) { return number_format($r['build']['insert_ms'] / 1000, 1) . ' s (' . number_format($r['build']['insert_rows_per_s']) . ' rows/s)'; });
    $md .= $row('createFieldIndex', function ($r) { return number_format($r['build']['create_index_ms'] / 1000, 1) . ' s'; });
    $md .= $row('Build peak memory', function ($r) { return $r['build']['peak_mib'] . ' MiB'; });
    $md .= $row('Disk', function ($r) { return $r['disk']['mib'] . ' MiB, ' . $r['disk']['files'] . ' files'; });
    foreach (['key_lookup' => 'Warm key lookup', 'indexed_query_1000' => 'Warm indexed query (1000 results)', 'indexed_query_miss' => 'Warm indexed miss', 'update_by_key' => 'Warm update by key'] as $k => $label) {
        $md .= $row($label . ' p50 / p95 / p99', function ($r) use ($k) { $s = $r['warm'][$k]; return "{$s['p50_ms']} / {$s['p95_ms']} / {$s['p99_ms']} ms (first {$s['first_ms']} ms)"; });
    }
    $md .= $row('Warm process peak', function ($r) { return $r['warm']['peak_mib'] . ' MiB'; });
    foreach (['cold-key' => 'Cold key lookup', 'cold-group' => 'Cold indexed query (1000 results)'] as $k => $label) {
        $md .= $row($label . ' p50 / p95 / p99', function ($r) use ($k) { $s = $r[$k]; return "{$s['p50_ms']} / {$s['p95_ms']} / {$s['p99_ms']} ms (peak {$s['peak_mib_max']} MiB)"; });
    }
    $md .= $row('384 MiB cache, indexed query p50 / p95 / p99', function ($r) { $s = $r['limited-384']; return "{$s['p50_ms']} / {$s['p95_ms']} / {$s['p99_ms']} ms (peak {$s['peak_mib']} MiB)"; });
    foreach (['budget-512' => 'memory_limit 512M query process', 'budget-128' => 'memory_limit 128M (PHP default) query process'] as $k => $label) {
        $md .= $row($label, function ($r) use ($k) {
            $s = $r[$k];
            return empty($s['ok']) ? 'FAILED (exit ' . $s['exit'] . '): ' . str_replace(["\n", '|'], [' ', '/'], substr($s['error'], -160))
                : "ok, peak {$s['peak_mib']} MiB, indexed p95 {$s['indexed_query_1000']['p95_ms']} ms";
        });
    }
    return $md;
}
