<?php
/**
 * noneDB soak test: hours of work by several processes, verified by a process of its own.
 *
 *   php tools/soak.php run --out=DIR [--hours=24] [--writers=4] [--cap=2500] [--readers=2] [--big=2000000] [--verify-minutes=10]
 *                          [--kill-seconds=10,90] [--memory=128M] [--cache=16777216] [--lib=noneDB.php]
 *                          [--tool=noneDB-migrate.php] [--seed=N]
 *   php tools/soak.php self-check --out=DIR    the verifier finds a lost write, a changed value, a phantom record; a writer
 *                                              keeps off the ids of its unfinished operations
 *
 * What runs (each in a PHP process of its own, `memory_limit` --memory, `cacheMemoryLimit` --cache):
 * - writers: CRUD on the database "crud" (shards of 1000 keys), each on records of its own (`w`, `id`; at most --cap). Every
 *   operation is journaled before it starts (B) and after it returned (A) or threw (F), so the expected state is
 *   known whatever happens. Each writer also reads its own records back and compares them;
 * - readers: random key and field-index reads over the database "big" (--big records, shards of 100 000), every
 *   record checked against the function it was made from;
 * - a big writer: updates of random keys of "big" (the field `u` only), with compaction now and then: repeated
 *   checkpoints and compactions on large data;
 * - the supervisor kills a random process with SIGKILL every --kill-seconds (a random interval) and starts it
 *   again. Every --verify-minutes it pauses the workers (they finish their operation and wait) and starts the
 *   verifier, a separate process:
 *     - checkIntegrity() of both databases;
 *     - "crud" equal to the state the journals prove; an operation a kill interrupted must have been applied
 *       completely or not at all, and the verifier records which;
 *     - "big" still holds every record, unchanged except `u` (a sample, the whole database every 6th time);
 *     - a backup of "crud" restored into a store of its own holds the same records;
 *   and every 3rd time a migration of a noneDB 3.x/2.x/1.x fixture, killed at a random moment, run again to its
 *   end, compared with what the old release itself returned, then restored byte for byte.
 * Any difference is an error; the report (soak.md, soak.json in --out) says FAILED when there is one.
 */

const CRUD = 'crud';
const BIG = 'big';
const FIXTURES = ['1.4-basic', '2.0-sharded', '2.3-sharded-buffered', '3.0-sharded-field-index', '3.1-field-index', '3.2-two-databases'];

$args = array_slice($argv, 1);
$mode = array_shift($args) ?? '';
$options = [];
foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z-]+)$/', $arg, $m)) {
        $options[$m[1]] = true;
    }
}

function root(): string
{
    return dirname(__DIR__);
}

function fail(string $message): void
{
    fwrite(STDERR, "soak: $message\n");
    exit(2);
}

/** Append one JSON line (flushed: a killed process loses at most the line it was writing, which no reader trusts) */
function appendLine(string $file, array $data): void
{
    $fp = fopen($file, 'ab');
    fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");
    fflush($fp);
    fclose($fp);
}

/** Complete JSON lines of a file */
function readLines(string $file): array
{
    $lines = [];
    if (!is_file($file)) {
        return $lines;
    }
    foreach (explode("\n", (string)file_get_contents($file)) as $line) {
        if ($line !== '' && ($data = json_decode($line, true)) !== null) {
            $lines[] = $data;
        }
    }
    return $lines;
}

function removeTree(string $dir): void
{
    if (is_dir($dir)) {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

function copyTree(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    foreach (scandir($from) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            is_dir("$from/$entry") ? copyTree("$from/$entry", "$to/$entry") : copy("$from/$entry", "$to/$entry");
        }
    }
}

/** Every file under $dir: relative path => sha256 */
function snapshot(string $dir): array
{
    $files = [];
    $walk = function (string $path, string $rel) use (&$walk, &$files): void {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir("$path/$entry") ? $walk("$path/$entry", "$rel$entry/") : $files["$rel$entry"] = hash_file('sha256', "$path/$entry");
        }
    };
    $walk(rtrim($dir, '/'), '');
    ksort($files);
    return $files;
}

/** The record "big" holds for key $i (`u` excepted: the big writer changes it) */
function bigRecord(int $i): array
{
    return ['i' => $i, 'h' => crc32("big$i"), 'g' => 'g' . ($i % 1000), 'u' => 0];
}

/** Settings of every process of a run (env: SOAK_WORK, SOAK_SECRET, SOAK_CACHE) */
function engine(array $more = []): noneDB
{
    return new noneDB($more + ['secretKey' => getenv('SOAK_SECRET'), 'dbDir' => getenv('SOAK_WORK') . '/store/',
        'cacheMemoryLimit' => (int)getenv('SOAK_CACHE'), 'lockTimeout' => 30]);
}

/** Wait while the supervisor pauses the workers (between two operations: no operation is under way) */
function paused(string $name): void
{
    $work = getenv('SOAK_WORK');
    while (is_file("$work/pause")) {
        touch("$work/paused.$name");
        usleep(50000);
    }
    @unlink("$work/paused.$name");
}

/** A read or a compaction of a worker: a lock timeout under contention is counted, any other failure reported */
function attempt(string $name, array &$ops, callable $fn): void
{
    try {
        $fn();
    } catch (\RuntimeException $e) {
        if ($e->getCode() === noneDB::LOCK_FAILED) {
            $ops['lockTimeouts'] = ($ops['lockTimeouts'] ?? 0) + 1;
            return;
        }
        workerError($name, get_class($e) . ' ' . $e->getCode() . ': ' . $e->getMessage());
    }
}

function workerStats(string $name, array $ops, noneDB $db, float &$lastStats): void
{
    if (microtime(true) - $lastStats < 10) {
        return;
    }
    $lastStats = microtime(true);
    $metrics = $db->getMetrics()['process'];
    // Counters of this process since it started (a restarted worker starts again at 0): the report sums the processes
    appendLine(getenv('SOAK_WORK') . "/stats.$name.jsonl", ['t' => time(), 'pid' => getmypid(), 'ops' => $ops, 'peak' => memory_get_peak_usage(),
        'evictions' => $metrics['indexCache']['evictions'], 'misses' => $metrics['indexCache']['misses'], 'hits' => $metrics['indexCache']['hits'],
        'compactions' => $metrics['compactions']['count'], 'checkpoints' => $metrics['checkpoints']['written']]);
}

/** An error a worker found (at most 100 lines per process: a failure that repeats on every operation stays readable) */
function workerError(string $name, string $message): void
{
    static $written = 0;
    if (++$written <= 100) {
        appendLine(getenv('SOAK_WORK') . '/errors.jsonl', ['t' => time(), 'who' => $name, 'error' => $message]);
    }
}

// ---------------------------------------------------------------------------------------------- the journal

/**
 * A writer's journal replayed: [records => id => value (proven), unknown => id => true (an operation not proven
 * applied or not), nextId, nextSeq, pending => seq => op]. Resolutions (R lines, written by the verifier while the
 * writer waits) settle pending operations.
 */
function replayJournal(string $file): array
{
    $ops = [];
    $done = [];
    $records = [];
    $nextId = 0;
    $nextSeq = 0;
    foreach (readLines($file) as $line) {
        if ($line['t'] === 'S') {
            // the state the verifier proved and wrote in place of the lines before it (compactJournal())
            $records = $line['records'];
            $nextId = $line['nextId'];
            $nextSeq = $line['nextSeq'];
        } elseif ($line['t'] === 'B') {
            $ops[$line['seq']] = $line;
        } elseif ($line['t'] === 'A') {
            $done[$line['seq']] = 'applied';
        } elseif ($line['t'] === 'F') {
            // a failure before the commit point leaves nothing; OUTCOME_UNKNOWN (3301) may have committed
            $done[$line['seq']] = $line['code'] === 3301 ? null : 'not';
        } elseif ($line['t'] === 'R') {
            $done[$line['seq']] = $line['applied'] ? 'applied' : 'not';
        }
    }
    ksort($ops);
    $unknown = [];
    $pending = [];
    foreach ($ops as $seq => $op) {
        $nextSeq = $seq + 1;
        foreach ($op['ids'] as $id) {
            $nextId = max($nextId, $id + 1);
        }
        $state = $done[$seq] ?? null;
        if ($state === null) {
            $pending[$seq] = $op;
            foreach ($op['ids'] as $id) {
                $unknown[$id] = true;
                unset($records[$id]);
            }
            continue;
        }
        if ($state === 'not') {
            continue;
        }
        foreach ($op['ids'] as $n => $id) {
            if ($op['op'] === 'delete') {
                unset($records[$id]);
            } elseif ($op['op'] === 'update') {
                if (array_key_exists($id, $records)) {
                    $records[$id] = $op['values'][$n];
                }
            } else {
                $records[$id] = $op['values'][$n];
            }
        }
    }
    return ['records' => $records, 'unknown' => $unknown, 'nextId' => $nextId, 'nextSeq' => $nextSeq, 'pending' => $pending];
}

/**
 * Replace a journal whose operations are all settled by one S line holding its state (the writer is paused): the
 * journals stay small and quick to replay over a day. Written to a temp file and renamed: a kill leaves either one.
 */
function compactJournal(string $file): void
{
    $state = replayJournal($file);
    if (!empty($state['pending'])) {
        return;
    }
    $line = json_encode(['t' => 'S', 'records' => (object)$state['records'], 'nextId' => $state['nextId'], 'nextSeq' => $state['nextSeq']], JSON_UNESCAPED_SLASHES);
    file_put_contents("$file.new", $line . "\n");
    rename("$file.new", $file);
}

// ---------------------------------------------------------------------------------------------- the workers

/**
 * A writer of "crud": inserts (1 to 20 records, while it holds fewer than $cap), updates, deletes, reads its own records
 * back, counts them, compacts now and then; every write journaled before it starts and after it returned
 */
function writerMain(string $name, int $w, int $cap): void
{
    $work = getenv('SOAK_WORK');
    $journal = "$work/journal.$name.jsonl";
    $state = replayJournal($journal);
    $records = $state['records'];
    $unknown = $state['unknown'];
    $nextId = $state['nextId'];
    $seq = $state['nextSeq'];
    $db = engine(['shardSize' => 1000]);
    $ops = ['insert' => 0, 'update' => 0, 'delete' => 0, 'read' => 0, 'count' => 0, 'compact' => 0, 'failed' => 0];
    $lastStats = 0.0;
    while (true) {
        if (is_file("$work/pause")) {
            paused($name);
            // what the verifier settled while this writer waited
            $state = replayJournal($journal);
            $records = $state['records'];
            $unknown = $state['unknown'];
        }
        $known = array_keys($records);
        $roll = mt_rand(1, 100);
        $op = null;
        if (($roll <= 40 && count($known) < $cap) || count($known) < 20) {
            $n = mt_rand(1, 20);
            $ids = range($nextId, $nextId + $n - 1);
            $nextId += $n;
            $values = array_map(function () { return bin2hex(random_bytes(mt_rand(4, 40))); }, $ids);
            $op = ['op' => 'insert', 'ids' => $ids, 'values' => $values];
        } elseif ($roll <= 40 || ($roll > 65 && $roll <= 80)) {
            $id = $known[array_rand($known)];
            $op = ['op' => 'delete', 'ids' => [$id], 'values' => [null]];
        } elseif ($roll <= 65) {
            $id = $known[array_rand($known)];
            $op = ['op' => 'update', 'ids' => [$id], 'values' => [bin2hex(random_bytes(mt_rand(4, 40)))]];
        } elseif ($roll <= 95) {
            $id = $known[array_rand($known)];
            attempt($name, $ops, function () use ($db, $w, $id, $records, $name, &$ops) {
                $found = $db->find(CRUD, ['w' => $w, 'id' => $id]);
                $ops['read']++;
                if (count($found) !== 1 || $found[0]['v'] !== $records[$id]) {
                    workerError($name, "read-your-writes: id $id is " . json_encode($found) . ', expected ' . json_encode($records[$id]));
                }
            });
        } elseif ($roll <= 99) {
            if (empty($unknown)) {
                attempt($name, $ops, function () use ($db, $w, $records, $name, &$ops) {
                    $count = $db->count(CRUD, ['w' => $w]);
                    $ops['count']++;
                    if ($count !== count($records)) {
                        workerError($name, "count of its records: $count, expected " . count($records));
                    }
                });
            }
        } else {
            attempt($name, $ops, function () use ($db, &$ops) {
                $db->compact(CRUD);
                $ops['compact']++;
            });
        }
        if ($op !== null) {
            appendLine($journal, ['t' => 'B', 'seq' => $seq] + $op);
            try {
                if ($op['op'] === 'insert') {
                    $rows = [];
                    foreach ($op['ids'] as $n => $id) {
                        $rows[] = ['w' => $w, 'id' => $id, 'v' => $op['values'][$n]];
                    }
                    $result = $db->insert(CRUD, count($rows) === 1 ? $rows[0] : $rows);
                    if (($result['n'] ?? 0) !== count($rows)) {
                        workerError($name, 'insert returned ' . json_encode($result));
                    }
                    foreach ($op['ids'] as $n => $id) {
                        $records[$id] = $op['values'][$n];
                    }
                } elseif ($op['op'] === 'update') {
                    $result = $db->update(CRUD, [['w' => $w, 'id' => $op['ids'][0]], ['set' => ['v' => $op['values'][0]]]]);
                    if (($result['n'] ?? 0) !== 1) {
                        workerError($name, "update of id {$op['ids'][0]} returned " . json_encode($result));
                    }
                    $records[$op['ids'][0]] = $op['values'][0];
                } else {
                    $result = $db->delete(CRUD, ['w' => $w, 'id' => $op['ids'][0]]);
                    if (($result['n'] ?? 0) !== 1) {
                        workerError($name, "delete of id {$op['ids'][0]} returned " . json_encode($result));
                    }
                    unset($records[$op['ids'][0]]);
                }
                appendLine($journal, ['t' => 'A', 'seq' => $seq]);
                $ops[$op['op']]++;
            } catch (\RuntimeException $e) {
                appendLine($journal, ['t' => 'F', 'seq' => $seq, 'code' => $e->getCode(), 'message' => $e->getMessage()]);
                $ops['failed']++;
                if ($e->getCode() === noneDB::OUTCOME_UNKNOWN) {
                    foreach ($op['ids'] as $id) {
                        $unknown[$id] = true;
                        unset($records[$id]);
                    }
                }
                if ($e->getCode() !== noneDB::LOCK_FAILED) {
                    workerError($name, get_class($e) . ' ' . $e->getCode() . ': ' . $e->getMessage());
                }
            }
            $seq++;
        }
        workerStats($name, $ops, $db, $lastStats);
    }
}

/** A reader of "big": random keys, now and then a group by the field index and a count; every record checked */
function readerMain(string $name, int $big): void
{
    $db = engine();
    $ops = ['key' => 0, 'group' => 0, 'count' => 0];
    $lastStats = 0.0;
    while (true) {
        paused($name);
        $roll = mt_rand(1, 1000);
        attempt($name, $ops, function () use ($db, $big, $roll, $name, &$ops) {
            if ($roll <= 990) {
                $i = mt_rand(0, $big - 1);
                $found = $db->find(BIG, ['key' => $i]);
                $ops['key']++;
                if (count($found) !== 1 || $found[0]['key'] !== $i || array_diff_key($found[0], ['u' => 0, 'key' => 0]) !== array_diff_key(bigRecord($i), ['u' => 0])) {
                    workerError($name, "big key $i: " . json_encode($found));
                }
            } elseif ($roll <= 999) {
                $g = mt_rand(0, 999);
                $found = $db->find(BIG, ['g' => "g$g"]);
                $ops['group']++;
                if (count($found) !== intdiv($big - 1 - $g, 1000) + 1) {
                    workerError($name, "big group g$g: " . count($found) . ' records');
                }
                foreach ($found as $record) {
                    if ($record['i'] % 1000 !== $g || $record['h'] !== crc32('big' . $record['i'])) {
                        workerError($name, "big group g$g holds " . json_encode($record));
                        break;
                    }
                }
            } else {
                $ops['count']++;
                if (($count = $db->count(BIG)) !== $big) {
                    workerError($name, "count of big: $count");
                }
            }
        });
        workerStats($name, $ops, $db, $lastStats);
    }
}

/** The writer of "big": updates of the field `u` of random keys, a compaction by chance (1 in 50 000 per update) */
function bigWriterMain(string $name, int $big): void
{
    $db = engine();
    $ops = ['update' => 0, 'compact' => 0];
    $lastStats = 0.0;
    while (true) {
        paused($name);
        attempt($name, $ops, function () use ($db, $big, $name, &$ops) {
            $i = mt_rand(0, $big - 1);
            $result = $db->update(BIG, [['key' => $i], ['set' => ['u' => mt_rand(1, PHP_INT_MAX)]]]);
            $ops['update']++;
            if (($result['n'] ?? 0) !== 1) {
                workerError($name, "update of big key $i returned " . json_encode($result));
            }
            // by chance, not by this process's count: a kill starts that again, and at 128M a process seldom reaches it
            if (mt_rand(1, 50000) === 1) {
                $db->compact(BIG);
                $ops['compact']++;
            }
        });
        workerStats($name, $ops, $db, $lastStats);
    }
}


// ---------------------------------------------------------------------------------------------- the verifier

/**
 * Check the store against the journals; settle every pending operation (R lines appended to its journal).
 * @return array ['ok' => bool, 'errors' => string[], 'settled' => int, 'records' => int, 'checked' => int]
 */
function verifyMain(int $big, bool $whole, array $writers): array
{
    $work = getenv('SOAK_WORK');
    $errors = [];
    noneDB::clearStaticCache();
    $db = engine();
    foreach ([CRUD, BIG] as $name) {
        $report = $db->checkIntegrity($name);
        if (!$report['ok']) {
            $errors[] = "checkIntegrity($name): " . json_encode(array_intersect_key($report, array_flip(['badLines', 'tornTail', 'indexMismatches', 'pendingRecovery'])));
        }
    }
    // crud: the records of every writer, by id
    $actual = [];
    $all = $db->find(CRUD, 0);
    foreach ($all as $record) {
        $w = $record['w'];
        if (isset($actual[$w][$record['id']])) {
            $errors[] = "crud: writer $w id {$record['id']} twice";
        }
        $actual[$w][$record['id']] = $record['v'];
    }
    $settled = 0;
    foreach ($writers as $w => $journal) {
        $state = replayJournal($journal);
        $expected = $state['records'];
        $have = $actual[$w] ?? [];
        // pending operations: applied completely or not at all, decided by the store (nothing touched their ids since)
        foreach ($state['pending'] as $seq => $op) {
            $applied = [];
            foreach ($op['ids'] as $n => $id) {
                if ($op['op'] === 'delete') {
                    $applied[] = !array_key_exists($id, $have);
                } else {
                    $applied[] = array_key_exists($id, $have) && $have[$id] === $op['values'][$n];
                }
            }
            $yes = count(array_filter($applied));
            if ($yes !== 0 && $yes !== count($applied)) {
                $errors[] = "crud: writer $w operation $seq ({$op['op']}) applied to $yes of " . count($applied) . ' records';
            }
            appendLine($journal, ['t' => 'R', 'seq' => $seq, 'applied' => $yes > 0]);
            $settled++;
        }
        $expected = replayJournal($journal)['records'];
        foreach ($expected as $id => $value) {
            if (!array_key_exists($id, $have)) {
                $errors[] = "crud: writer $w id $id lost (expected " . json_encode($value) . ')';
            } elseif ($have[$id] !== $value) {
                $errors[] = "crud: writer $w id $id is " . json_encode($have[$id]) . ', expected ' . json_encode($value);
            }
        }
        foreach (array_diff_key($have, $expected) as $id => $value) {
            $errors[] = "crud: writer $w id $id exists, not written by it (" . json_encode($value) . ')';
        }
        unset($actual[$w]);
    }
    foreach (array_keys($actual) as $w) {
        $errors[] = "crud: records of an unknown writer $w";
    }
    // big: every record, or a sample
    $checked = 0;
    if (($count = $db->count(BIG)) !== $big) {
        $errors[] = "big: $count records, expected $big";
    }
    $chunks = $whole ? intdiv($big + 4999, 5000) : 4;
    for ($c = 0; $c < $chunks; $c++) {
        // the whole database 5000 keys at a time, or 4 × 5000 random keys
        $chunk = $whole ? range($c * 5000, min($big, ($c + 1) * 5000) - 1) : array_map(function () use ($big) { return mt_rand(0, $big - 1); }, range(1, 5000));
        $found = [];
        foreach ($db->find(BIG, ['key' => $chunk]) as $record) {
            $found[$record['key']] = $record;
        }
        foreach ($chunk as $i) {
            $checked++;
            if (!isset($found[$i]) || array_diff_key($found[$i], ['u' => 0, 'key' => 0]) !== array_diff_key(bigRecord($i), ['u' => 0])) {
                $errors[] = "big: key $i is " . json_encode($found[$i] ?? null);
                break 2;
            }
        }
        unset($found);
    }
    // a backup of crud restored into a store of its own holds the same records
    $backup = "$work/verify-backup";
    $scratch = "$work/verify-store";
    removeTree($backup);
    removeTree($scratch);
    try {
        $db->backup(CRUD, $backup);
        noneDB::clearStaticCache();
        $other = new noneDB(['secretKey' => getenv('SOAK_SECRET') . '-scratch', 'dbDir' => "$scratch/", 'cacheMemoryLimit' => (int)getenv('SOAK_CACHE')]);
        $other->restore($backup, CRUD);
        $restored = $other->find(CRUD, 0);
        $byKey = function (array $records) {
            $out = [];
            foreach ($records as $record) {
                $out[$record['key']] = $record;
            }
            ksort($out);
            return $out;
        };
        if ($byKey($restored) !== $byKey($all)) {
            $errors[] = 'backup and restore: ' . count($restored) . ' records, the store holds ' . count($all);
        }
        if (!$other->checkIntegrity(CRUD)['ok']) {
            $errors[] = 'backup and restore: the restored database fails checkIntegrity()';
        }
    } catch (\Throwable $e) {
        $errors[] = 'backup and restore: ' . get_class($e) . ': ' . $e->getMessage();
    }
    removeTree($backup);
    removeTree($scratch);
    foreach ($writers as $journal) {
        compactJournal($journal);
    }
    return ['ok' => empty($errors), 'errors' => array_slice($errors, 0, 50), 'errorCount' => count($errors), 'settled' => $settled,
        'records' => count($all), 'checked' => $checked, 'whole' => $whole, 'peak' => memory_get_peak_usage()];
}

// ---------------------------------------------------------------------------------------------- the migration cycle

/**
 * A fixture written by an old release, migrated by noneDB-migrate.php killed at a random moment (always when $kill,
 * else two times in three; a run of these fixtures takes about 100 ms), run again until it finishes, compared with the
 * old release's own view, then restored byte for byte
 */
function migrationCycle(string $tool, string $fixture, bool $kill): array
{
    $work = getenv('SOAK_WORK');
    $base = "$work/migration";
    removeTree($base);
    $src = "$base/db/";
    $backup = "$base/backup/";
    copyTree(root() . "/tests/Migration/fixtures/$fixture/db", $src);
    $meta = json_decode((string)file_get_contents(root() . "/tests/Migration/fixtures/$fixture/fixture.json"), true);
    $before = snapshot($src);
    $target = 'soak-migration-target-' . bin2hex(random_bytes(8));
    $env = getenv();
    unset($env['NONEDB_DEV_MODE']);
    $env['NONEDB_SECRET'] = $target;
    $env['NONEDB_SOURCE_SECRET'] = $meta['sourceKey'];
    $run = function (array $args, ?int $killAfterMs) use ($tool, $env, $base) {
        $process = proc_open(array_merge([PHP_BINARY, $tool], $args, ['--json']), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $base, $env);
        if ($killAfterMs !== null) {
            usleep($killAfterMs * 1000);
            proc_terminate($process, 9);
        }
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        return [$status, json_decode((string)$out, true)];
    };
    $args = ["--dir=$src", "--backup-dir=$backup", '--confirm-stopped'];
    $killed = $kill || mt_rand(0, 2) > 0 ? mt_rand(0, 150) : null;
    $attempts = 0;
    [$status, $out] = $run($args, $killed);
    $interrupted = $status !== 0;
    $result = function (?string $error) use ($fixture, $killed, &$interrupted, &$attempts) {
        return ['ok' => $error === null, 'fixture' => $fixture, 'killedAfterMs' => $killed, 'interrupted' => $interrupted, 'reruns' => $attempts]
            + ($error === null ? [] : ['error' => $error]);
    };
    while ($status !== 0 && $attempts < 5) {
        $attempts++;
        [$status, $out] = $run($args, null);
    }
    // the run of the backup (a kill after it had finished leaves the next run nothing to do: it reports no run), with
    // its final verification recorded and its report written (MIGRATION.md §7.8)
    $runs = glob($backup . '*/journal.done.json') ?: [];
    $runId = count($runs) === 1 ? basename(dirname($runs[0])) : null;
    $done = $runId === null ? null : json_decode(trim((string)file_get_contents($runs[0])), true);
    if ($status !== 0 || $runId === null || !in_array($out['migrated']['run'] ?? null, [null, $runId], true)
        || ($done['state'] ?? null) !== 'FINAL_VERIFIED' || !is_file($backup . "$runId/report.json")) {
        return $result("the run did not finish (status $status, "
            . count($runs) . ' closed runs, state ' . ($done['state'] ?? 'none') . '): ' . json_encode($out));
    }
    $releases = array_keys($meta['oracles']);
    $release = end($releases);
    noneDB::clearStaticCache();
    $db = new noneDB(['secretKey' => $target, 'dbDir' => $src, 'autoCreateDB' => false]);
    foreach ($meta['oracles'][$release] as $name => $oracle) {
        $expected = [];
        foreach ($oracle['records'] as [$key, $record]) {
            $expected[$key] = $record;
        }
        $got = [];
        foreach ($db->find($name, 0) ?: [] as $record) {
            $got[$record['key']] = $record;
        }
        ksort($expected);
        ksort($got);
        if ($got !== $expected) {
            return $result("$name: " . count($got) . ' records, the old release returned ' . count($expected));
        }
    }
    unset($db);
    noneDB::clearStaticCache();
    [$status, $restored] = $run(["--dir=$src", "--backup-dir=$backup", '--restore=' . $runId], null);
    $after = snapshot($src);
    if ($status !== 0 || $after !== $before) {
        $differ = array_keys(array_diff_assoc($before, $after) + array_diff_assoc($after, $before));
        return $result("the restore exited with $status and left "
            . count($differ) . ' files different: ' . json_encode(array_slice($differ, 0, 10)) . ' ' . json_encode($restored));
    }
    removeTree($base);
    return $result(null);
}

// ---------------------------------------------------------------------------------------------- the supervisor

function childCommand(array $settings, string $role, array $roleArgs): array
{
    return [PHP_BINARY, '-d', 'memory_limit=' . $settings['memory'], __FILE__, 'child', $role, json_encode($roleArgs)];
}

/** Start a child process (no shell: a SIGKILL reaches PHP itself); its output goes to out.$name.log */
function spawn(array $settings, string $name, string $role, array $roleArgs)
{
    $env = getenv();
    $env['SOAK_WORK'] = $settings['work'];
    $env['SOAK_SECRET'] = $settings['secret'];
    $env['SOAK_CACHE'] = (string)$settings['cache'];
    $env['SOAK_LIB'] = $settings['lib'];
    @unlink($settings['work'] . "/paused.$name"); // a killed worker's mark is not the new one's
    $log = fopen($settings['work'] . "/out.$name.log", 'ab');
    $process = proc_open(childCommand($settings, $role, $roleArgs + ['name' => $name]), [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, null, $env);
    fclose($log);
    return $process;
}

/** Run a child to its end; its result is the file it wrote (none: it failed) */
function runChildSync(array $settings, string $role, array $roleArgs): array
{
    $file = $settings['work'] . "/result.$role.json";
    @unlink($file);
    $status = proc_close(spawn($settings, $role, $role, $roleArgs));
    $result = json_decode((string)@file_get_contents($file), true);
    return is_array($result) ? $result : ['ok' => false, 'errors' => ["$role exited with $status and no result (out.$role.log)"], 'errorCount' => 1];
}

/** Pause the workers: each finishes its operation and waits (paused.$name) */
function pauseWorkers(array $settings, array $workers): bool
{
    foreach (array_keys($workers) as $name) {
        @unlink($settings['work'] . "/paused.$name");
    }
    touch($settings['work'] . '/pause');
    $deadline = microtime(true) + 180;
    while (microtime(true) < $deadline) {
        $waiting = 0;
        foreach (array_keys($workers) as $name) {
            if (!is_file($settings['work'] . "/paused.$name")) {
                $waiting++;
            }
        }
        if ($waiting === 0) {
            return true;
        }
        usleep(100000);
    }
    return false;
}


function supervise(array $settings, array $options): int
{
    $work = $settings['work'];
    $out = $settings['out'];
    $hours = (float)($options['hours'] ?? 24);
    $writers = (int)($options['writers'] ?? 4);
    $readers = (int)($options['readers'] ?? 2);
    $big = (int)($options['big'] ?? 2000000);
    $cap = (int)($options['cap'] ?? 2500);
    $verifyMinutes = (float)($options['verify-minutes'] ?? 10);
    [$killMin, $killMax] = array_map('intval', explode(',', (string)($options['kill-seconds'] ?? '10,90')));
    $tool = (string)($options['tool'] ?? root() . '/noneDB-migrate.php');
    $started = time();
    $deadline = $started + (int)($hours * 3600);
    $events = "$out/events.jsonl";
    appendLine($events, ['t' => $started, 'event' => 'start', 'hours' => $hours, 'writers' => $writers, 'cap' => $cap, 'readers' => $readers, 'big' => $big,
        'memory' => $settings['memory'], 'cache' => $settings['cache'], 'lib' => $settings['lib'], 'sha256' => hash_file('sha256', $settings['lib'])]);

    $setup = runChildSync($settings, 'setup', ['big' => $big]);
    if (empty($setup['ok'])) {
        fail('setup failed: ' . json_encode($setup));
    }
    appendLine($events, ['t' => time(), 'event' => 'setup', 'seconds' => $setup['seconds']]);

    $roles = [];
    for ($w = 1; $w <= $writers; $w++) {
        $roles["writer$w"] = ['writer', ['w' => $w, 'cap' => $cap]];
    }
    for ($r = 1; $r <= $readers; $r++) {
        $roles["reader$r"] = ['reader', ['big' => $big]];
    }
    $roles['bigwriter'] = ['bigwriter', ['big' => $big]];
    $journals = [];
    for ($w = 1; $w <= $writers; $w++) {
        $journals[$w] = "$work/journal.writer$w.jsonl";
    }
    $processes = [];
    foreach ($roles as $name => [$role, $roleArgs]) {
        $processes[$name] = spawn($settings, $name, $role, $roleArgs);
    }

    $kills = 0;
    $verifications = 0;
    $failedChecks = 0;
    $migrations = 0;
    $nextKill = time() + mt_rand($killMin, $killMax);
    $nextVerify = time() + (int)($verifyMinutes * 60);
    $final = false;
    while (true) {
        $now = time();
        foreach ($processes as $name => $process) {
            if (!proc_get_status($process)['running']) {
                // a worker never stops by itself: one that did crashed
                proc_close($process);
                appendLine($events, ['t' => $now, 'event' => 'exited', 'who' => $name]);
                workerErrorFile($work, $name, 'exited by itself (see out.' . $name . '.log)');
                $processes[$name] = spawn($settings, $name, $roles[$name][0], $roles[$name][1]);
            }
        }
        if ($now >= $deadline) {
            $final = true;
        }
        if ($final || $now >= $nextVerify) {
            if (!pauseWorkers($settings, $processes)) {
                appendLine($events, ['t' => time(), 'event' => 'pause-timeout']);
                workerErrorFile($work, 'supervisor', 'the workers did not pause within 180 s');
            }
            $verifications++;
            $result = runChildSync($settings, 'verify', ['big' => $big, 'whole' => $final || $verifications % 6 === 0, 'writers' => $journals]);
            if (empty($result['ok'])) {
                $failedChecks++;
            }
            appendLine($events, ['t' => time(), 'event' => 'verify', 'n' => $verifications] + $result);
            if ($verifications % 3 === 0 || $final) {
                $migrations++;
                $fixture = FIXTURES[mt_rand(0, count(FIXTURES) - 1)];
                $migration = runChildSync($settings, 'migration', ['tool' => $tool, 'fixture' => $fixture]);
                if (empty($migration['ok'])) {
                    $failedChecks++;
                }
                appendLine($events, ['t' => time(), 'event' => 'migration', 'n' => $migrations] + $migration);
            }
            appendLine($events, ['t' => time(), 'event' => 'disk', 'bytes' => (int)trim((string)shell_exec('du -sk ' . escapeshellarg("$work/store") . ' | cut -f1')) * 1024]);
            if ($final) {
                break;
            }
            @unlink("$work/pause");
            $nextVerify = time() + (int)($verifyMinutes * 60);
        }
        if (time() >= $nextKill) {
            $names = array_keys($processes);
            $name = $names[mt_rand(0, count($names) - 1)];
            proc_terminate($processes[$name], 9);
            proc_close($processes[$name]);
            $kills++;
            appendLine($events, ['t' => time(), 'event' => 'kill', 'who' => $name]);
            // the journal of a killed writer may end in an operation it never finished: the next verification settles it
            $processes[$name] = spawn($settings, $name, $roles[$name][0], $roles[$name][1]);
            $nextKill = time() + mt_rand($killMin, $killMax);
        }
        usleep(200000);
    }
    foreach ($processes as $process) {
        proc_terminate($process, 9);
        proc_close($process);
    }
    @unlink("$work/pause");
    return report($settings, $started);
}

function workerErrorFile(string $work, string $who, string $message): void
{
    appendLine("$work/errors.jsonl", ['t' => time(), 'who' => $who, 'error' => $message]);
}

function report(array $settings, int $started): int
{
    $work = $settings['work'];
    $out = $settings['out'];
    $events = readLines("$out/events.jsonl");
    $errors = readLines("$work/errors.jsonl");
    $start = $events[0];
    $verifies = array_values(array_filter($events, function ($e) { return $e['event'] === 'verify'; }));
    $migrations = array_values(array_filter($events, function ($e) { return $e['event'] === 'migration'; }));
    $kills = array_count_values(array_column(array_filter($events, function ($e) { return $e['event'] === 'kill'; }), 'who'));
    $disk = array_values(array_filter($events, function ($e) { return $e['event'] === 'disk'; }));
    $stats = [];
    foreach (glob("$work/stats.*.jsonl") as $file) {
        $name = substr(basename($file, '.jsonl'), 6);
        $lines = readLines($file);
        $last = [];
        foreach ($lines as $line) {
            $last[$line['pid']] = $line['ops']; // the last report of each process
        }
        $ops = [];
        foreach ($last as $counters) {
            foreach ($counters as $op => $count) {
                $ops[$op] = ($ops[$op] ?? 0) + $count;
            }
        }
        $stats[$name] = ['processes' => count($last), 'peak' => max(array_column($lines, 'peak') ?: [0]), 'ops' => $ops];
    }
    ksort($stats);
    $failedVerifies = count(array_filter($verifies, function ($v) { return empty($v['ok']); }));
    $failedMigrations = count(array_filter($migrations, function ($m) { return empty($m['ok']); }));
    $ok = $failedVerifies === 0 && $failedMigrations === 0 && empty($errors) && !empty($verifies);
    $summary = ['ok' => $ok, 'started' => date('c', $started), 'ended' => date('c'), 'hours' => round((time() - $started) / 3600, 2),
        'library' => $start['lib'], 'sha256' => $start['sha256'], 'php' => PHP_VERSION, 'os' => php_uname('s') . ' ' . php_uname('r'),
        'writers' => $start['writers'], 'readers' => $start['readers'], 'big' => $start['big'], 'memory' => $start['memory'], 'cache' => $start['cache'],
        'kills' => $kills, 'verifications' => count($verifies), 'failedVerifications' => $failedVerifies, 'settled' => array_sum(array_column($verifies, 'settled')),
        'migrations' => count($migrations), 'failedMigrations' => $failedMigrations,
        'migrationsInterrupted' => count(array_filter($migrations, function ($m) { return !empty($m['interrupted']); })),
        'migrationFixtures' => array_count_values(array_column($migrations, 'fixture')),
        'errors' => count($errors), 'firstErrors' => array_slice($errors, 0, 20), 'workers' => $stats,
        'diskFirst' => $disk[0]['bytes'] ?? null, 'diskLast' => end($disk)['bytes'] ?? null,
        'crudRecordsLast' => end($verifies)['records'] ?? null];
    file_put_contents("$out/soak.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $md = "# noneDB soak test\n\n"
        . '- **Result:** ' . ($ok ? 'OK' : '**FAILED**') . "\n"
        . "- **Run:** {$summary['started']} → {$summary['ended']} ({$summary['hours']} h), PHP {$summary['php']}, {$summary['os']}\n"
        . "- **Library:** `{$summary['library']}` (sha256 `" . substr((string)$summary['sha256'], 0, 12) . "…`)\n"
        . "- **Processes:** {$summary['writers']} writers on \"crud\", {$summary['readers']} readers and 1 writer on \"big\" ({$summary['big']} records); "
        . "`memory_limit` {$summary['memory']}, `cacheMemoryLimit` {$summary['cache']} bytes\n"
        . '- **Kills (SIGKILL) and restarts:** ' . array_sum($kills) . ' (' . implode(', ', array_map(function ($k, $v) { return "$k $v"; }, array_keys($kills), $kills)) . ")\n"
        . "- **Verifications:** {$summary['verifications']} by a separate process, {$summary['failedVerifications']} failed; "
        . "{$summary['settled']} interrupted operations settled (applied or not, never in part)\n"
        . "- **Migrations** killed at a random moment, run again, compared with the old release, restored: {$summary['migrations']} "
        . "({$summary['migrationsInterrupted']} interrupted by the kill), {$summary['failedMigrations']} failed\n"
        . "- **Errors reported by the workers:** {$summary['errors']}\n"
        . '- **Disk of the store:** first verification ' . round(($summary['diskFirst'] ?? 0) / 1048576, 1) . ' MiB, last ' . round(($summary['diskLast'] ?? 0) / 1048576, 1) . " MiB\n\n"
        . "| Process | Started | Peak memory | Operations (all its processes, as last reported every 10 s) |\n|---|---|---|---|\n";
    foreach ($stats as $name => $s) {
        $md .= "| $name | {$s['processes']} | " . round($s['peak'] / 1048576, 1) . ' MiB | ' . json_encode($s['ops']) . " |\n";
    }
    if (!empty($errors)) {
        $md .= "\n## First errors\n\n";
        foreach (array_slice($errors, 0, 20) as $e) {
            $md .= "- {$e['who']}: {$e['error']}\n";
        }
    }
    file_put_contents("$out/soak.md", $md);
    echo $md;
    return $ok ? 0 : 1;
}

// ---------------------------------------------------------------------------------------------- the self-check

/**
 * The verifier finds what it must. A writer works a few seconds, then, behind its back:
 * - lost: a record of it deleted; changed: one changed; phantom: one it never wrote;
 * - partial: an insert of two records the journal began, one of them in the store (applied in part);
 * - interrupted: an insert the journal began and never ended, in the store completely: settled as applied, no error.
 * Each case must give its own error (or none); then every fixture goes through the migration cycle.
 */
function selfCheck(array $settings): int
{
    $work = $settings['work'];
    $journal = "$work/journal.writer1.jsonl";
    $setup = runChildSync($settings, 'setup', ['big' => 5000]);
    if (empty($setup['ok'])) {
        fail('setup failed');
    }
    $writer = spawn($settings, 'writer1', 'writer', ['w' => 1, 'cap' => 2500]);
    sleep(5);
    pauseWorkers($settings, ['writer1' => true]);
    proc_terminate($writer, 9);
    proc_close($writer);
    @unlink("$work/pause");
    putenv("SOAK_WORK=$work");
    putenv('SOAK_SECRET=' . $settings['secret']);
    putenv('SOAK_CACHE=' . $settings['cache']);
    $verify = function () use ($settings, $journal) {
        noneDB::clearStaticCache();
        return runChildSync($settings, 'verify', ['big' => 5000, 'whole' => false, 'writers' => [1 => $journal]]);
    };
    $db = function () {
        noneDB::clearStaticCache();
        return engine();
    };
    $begin = function (array $op) use ($journal) {
        $seq = replayJournal($journal)['nextSeq'];
        appendLine($journal, ['t' => 'B', 'seq' => $seq] + $op);
        return $seq;
    };
    $results = [];
    $clean = $verify();
    $results['clean'] = ['pass' => !empty($clean['ok']) && $clean['records'] > 0, 'records' => $clean['records'], 'errors' => $clean['errors']];
    $victim = $db()->find(CRUD, ['w' => 1])[0];
    $cases = [
        'lost' => [function () use ($db, $victim) { $db()->delete(CRUD, ['key' => $victim['key']]); },
            ["crud: writer 1 id {$victim['id']} lost (expected " . json_encode($victim['v']) . ')'],
            function () use ($db, $victim) { $db()->insert(CRUD, ['w' => 1, 'id' => $victim['id'], 'v' => $victim['v']]); }],
        'changed' => [function () use ($db, $victim) { $db()->update(CRUD, [['w' => 1, 'id' => $victim['id']], ['set' => ['v' => 'changed']]]); },
            ["crud: writer 1 id {$victim['id']} is \"changed\", expected " . json_encode($victim['v'])],
            function () use ($db, $victim) { $db()->update(CRUD, [['w' => 1, 'id' => $victim['id']], ['set' => ['v' => $victim['v']]]]); }],
        'phantom' => [function () use ($db) { $db()->insert(CRUD, ['w' => 1, 'id' => 900000, 'v' => 'phantom']); },
            ['crud: writer 1 id 900000 exists, not written by it ("phantom")'],
            function () use ($db) { $db()->delete(CRUD, ['w' => 1, 'id' => 900000]); }],
        'partial' => [function () use ($db, $begin, &$partialSeq) {
                $partialSeq = $begin(['op' => 'insert', 'ids' => [900001, 900002], 'values' => ['p1', 'p2']]);
                $db()->insert(CRUD, ['w' => 1, 'id' => 900001, 'v' => 'p1']);
            },
            // settled as applied (it was, in part): the record it did not write is then missing too
            ['crud: writer 1 operation %d (insert) applied to 1 of 2 records', 'crud: writer 1 id 900002 lost (expected "p2")'],
            // the verifier settled it as applied: the store gets the record it lacks
            function () use ($db) { $db()->insert(CRUD, ['w' => 1, 'id' => 900002, 'v' => 'p2']); }],
        'interrupted' => [function () use ($db, $begin) {
                $begin(['op' => 'insert', 'ids' => [900003, 900004], 'values' => ['i3', 'i4']]);
                $db()->insert(CRUD, [['w' => 1, 'id' => 900003, 'v' => 'i3'], ['w' => 1, 'id' => 900004, 'v' => 'i4']]);
            }, null, null],
    ];
    $partialSeq = null;
    foreach ($cases as $case => [$break, $expected, $repair]) {
        $break();
        $found = $verify();
        if ($expected === null) {
            $model = replayJournal($journal)['records'];
            $pass = !empty($found['ok']) && $found['settled'] === 1 && ($model[900003] ?? null) === 'i3' && ($model[900004] ?? null) === 'i4';
        } else {
            $expected = array_map(function ($error) use ($partialSeq) { return sprintf($error, (int)$partialSeq); }, $expected);
            $pass = empty($found['ok']) && $found['errors'] === $expected && $found['errorCount'] === count($expected);
        }
        $results[$case] = ['pass' => $pass, 'expected' => $expected, 'errors' => $found['errors'], 'settled' => $found['settled']];
        if ($repair !== null) {
            $repair();
            $after = $verify();
            $results[$case]['repaired'] = !empty($after['ok']);
            $results[$case]['pass'] = $results[$case]['pass'] && $results[$case]['repaired'];
        }
    }
    // a writer started again after a kill picks its records from the replay: an unfinished operation's id is not among
    // them, whether the operation reached the store or not, until the verifier has settled it
    [$applied, $notApplied] = array_slice(array_keys(replayJournal($journal)['records']), 0, 2);
    $old = replayJournal($journal)['records'][$notApplied];
    $begin(['op' => 'delete', 'ids' => [$applied], 'values' => [null]]);
    $db()->delete(CRUD, ['w' => 1, 'id' => $applied]);
    $begin(['op' => 'update', 'ids' => [$notApplied], 'values' => ['never written']]);
    $pending = replayJournal($journal);
    $found = $verify();
    $model = replayJournal($journal)['records'];
    $results['unfinished'] = ['pass' => !array_key_exists($applied, $pending['records']) && !array_key_exists($notApplied, $pending['records'])
        && isset($pending['unknown'][$applied], $pending['unknown'][$notApplied]) && !empty($found['ok']) && $found['settled'] === 2
        && !array_key_exists($applied, $model) && ($model[$notApplied] ?? null) === $old, 'errors' => $found['errors'], 'settled' => $found['settled']];
    foreach (FIXTURES as $fixture) {
        $migration = runChildSync($settings, 'migration', ['tool' => root() . '/noneDB-migrate.php', 'fixture' => $fixture, 'kill' => true]);
        $results["migration $fixture"] = ['pass' => !empty($migration['ok'])] + $migration;
    }
    file_put_contents($settings['out'] . '/self-check.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $failed = 0;
    foreach ($results as $case => $result) {
        echo ($result['pass'] ? 'ok    ' : 'FAILED'), " $case", isset($result['killedAfterMs']) ? " (killed after {$result['killedAfterMs']} ms: " . (!empty($result['interrupted']) ? 'interrupted' : 'already done') . ", {$result['reruns']} reruns)" : '', "\n";
        $failed += $result['pass'] ? 0 : 1;
    }
    return $failed === 0 ? 0 : 1;
}

// ---------------------------------------------------------------------------------------------- main

if ($mode === 'child') {
    [$role, $json] = [$args[0], $args[1]];
    $roleArgs = json_decode($json, true);
    require getenv('SOAK_LIB');
    mt_srand(random_int(0, PHP_INT_MAX));
    $work = getenv('SOAK_WORK');
    switch ($role) {
        case 'setup':
            $t = microtime(true);
            $db = engine(['shardSize' => 100000]);
            $big = $roleArgs['big'];
            if ($db->count(BIG) === 0) {
                for ($from = 0; $from < $big; $from += 20000) {
                    $db->insert(BIG, array_map('bigRecord', range($from, min($big, $from + 20000) - 1)));
                }
                $db->createFieldIndex(BIG, 'g');
            }
            noneDB::clearStaticCache();
            $crud = engine(['shardSize' => 1000]);
            $crud->createFieldIndex(CRUD, 'w');
            file_put_contents("$work/result.setup.json", json_encode(['ok' => true, 'seconds' => round(microtime(true) - $t, 1)]));
            break;
        case 'writer':
            writerMain($roleArgs['name'], $roleArgs['w'], $roleArgs['cap']);
            break;
        case 'reader':
            readerMain($roleArgs['name'], $roleArgs['big']);
            break;
        case 'bigwriter':
            bigWriterMain($roleArgs['name'], $roleArgs['big']);
            break;
        case 'verify':
            $writers = [];
            foreach ($roleArgs['writers'] as $w => $journal) {
                $writers[(int)$w] = $journal;
            }
            file_put_contents("$work/result.verify.json", json_encode(verifyMain($roleArgs['big'], $roleArgs['whole'], $writers)));
            break;
        case 'migration':
            file_put_contents("$work/result.migration.json", json_encode(migrationCycle($roleArgs['tool'], $roleArgs['fixture'], !empty($roleArgs['kill']))));
            break;
    }
    exit(0);
}

if ($mode !== 'run' && $mode !== 'self-check') {
    fwrite(STDERR, "usage: php tools/soak.php run --out=DIR [--hours=24] ... | self-check --out=DIR\n");
    exit(2);
}
$out = $options['out'] ?? fail('--out=DIR is required');
@mkdir($out, 0777, true);
$out = realpath($out);
$work = $out . '/work';
removeTree($work);
mkdir($work, 0700, true);
$settings = ['out' => $out, 'work' => $work, 'secret' => bin2hex(random_bytes(32)), 'memory' => (string)($options['memory'] ?? '128M'),
    'cache' => (int)($options['cache'] ?? 16777216), 'lib' => realpath((string)($options['lib'] ?? root() . '/noneDB.php'))];
// the run's own throwaway key, kept for opening the store of a failed run (never a key of a real store)
file_put_contents("$work/secret", $settings['secret']);
chmod("$work/secret", 0600);
if (isset($options['seed'])) {
    mt_srand((int)$options['seed']);
}
if ($mode === 'self-check') {
    require $settings['lib'];
    exit(selfCheck($settings));
}
@unlink("$out/events.jsonl");
exit(supervise($settings, $options));
