<?php

namespace noneDB\Tests\Crash;

use noneDB\Tests\RegressionTestCase;

/**
 * The crash and fault-injection matrix. Every write operation is
 * - killed (SIGKILL in a child process) at each step it has: after an append, before an atomic rename,
 *   after a checkpoint, at a new shard, at each step of compaction and of a generation switch, at the commit;
 * - hit by an I/O error: a short write, a full disk, a failed fflush(), a failed fsync() (durable mode), a
 *   second I/O error while the rollback cuts the appends back, a file it may not write, a lock it can't get.
 * Afterwards the database is opened in a new process state and its actual record set must be one of the
 * states the operation may leave (docs/DURABILITY.md §2, §5): before it; after it; for a call that changes
 * several records, before plus a prefix of its changes in key order. An exception other than OUTCOME_UNKNOWN
 * must leave exactly the state before. A write after the fault must work, never reuse a key, and leave
 * checkIntegrity() clean. The candidate states come from a model of the records the test wrote, never
 * from noneDB's output. (Lock loss during a lock upgrade: SpecOperationsTest::anUpgradeThatTimesOutLosesTheLock.)
 */
class FaultMatrixTest extends RegressionTestCase
{
    private const DB = 'fm';

    /** @var string[] fault points at which some operation was actually killed */
    private $killedAt = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('posix_kill') || !function_exists('proc_open')) {
            $this->markTestSkipped('needs POSIX signals and proc_open');
        }
    }

    // ------------------------------------------------------------------ the model

    private static function seed(int $n): array
    {
        $records = [];
        for ($i = 0; $i < $n; $i++) {
            $records[] = ['n' => $i, 'g' => $i % 3];
        }
        return $records;
    }

    /**
     * Operations: config, the calls that build the state before ([op, args]...), the faulted call, and its
     * kill points ([point, nth occurrence])
     */
    private function operations(): array
    {
        $batch = [['n' => 100, 'g' => 9], ['n' => 101, 'g' => 9], ['n' => 102, 'g' => 9], ['n' => 103, 'g' => 9], ['n' => 104, 'g' => 9]];
        return [
            'insert' => [
                'config' => ['shardingEnabled' => false],
                'before' => [['insert', self::seed(40)]],
                'call' => ['insert', [['n' => 100, 'g' => 9]]],
                'kill' => [['append:after', 1], ['index:written', 1], ['scope:commit', 1], ['checkpoint:written', 1], ['atomic:renaming', 1]],
            ],
            'insert-new-shards' => [
                'config' => ['shardSize' => 4],
                'before' => [['insert', self::seed(6)]],
                'call' => ['insert', $batch], // keys 6..10: the end of shard 1, shard 2 (new)
                'kill' => [['shard:created', 1], ['append:after', 1], ['append:after', 2], ['index:written', 2], ['atomic:renaming', 1], ['scope:commit', 1], ['checkpoint:written', 1]],
            ],
            'update-across-shards' => [
                'config' => ['shardSize' => 4, 'autoCompactThreshold' => 1000],
                'before' => [['insert', self::seed(12)]],
                'call' => ['update', ['g' => 1], ['u' => 1]], // keys 1, 4, 7, 10 in shards 0, 1, 1, 2
                'kill' => [['append:after', 1], ['append:after', 2], ['append:after', 3], ['scope:commit', 1], ['checkpoint:written', 1]],
            ],
            'delete-across-shards' => [
                'config' => ['shardSize' => 4, 'autoCompactThreshold' => 1000],
                'before' => [['insert', self::seed(12)]],
                'call' => ['delete', ['g' => 2]], // keys 2, 5, 8, 11
                'kill' => [['append:after', 1], ['append:after', 2], ['scope:commit', 1]],
            ],
            'buffered-insert-and-flush' => [
                'config' => ['shardingEnabled' => false, 'bufferEnabled' => true],
                'before' => [['insert', self::seed(5)], ['flush']],
                'call' => ['insert-flush', [['n' => 200, 'g' => 9], ['n' => 201, 'g' => 9]]],
                'kill' => [['append:after', 1], ['append:after', 2], ['scope:commit', 1], ['scope:commit', 2], ['checkpoint:written', 1]],
            ],
            'compact' => [
                'config' => ['shardingEnabled' => false, 'autoCompactThreshold' => 1000],
                'before' => [['insert', self::seed(20)], ['update', ['g' => 0], ['w' => 1]], ['delete', ['g' => 1]]],
                'call' => ['compact'],
                'kill' => [['compact:replace', 1], ['atomic:renaming', 1], ['compact:replaced', 1], ['checkpoint:written', 1]],
            ],
            'compact-sharded' => [
                'config' => ['shardSize' => 5, 'autoCompactThreshold' => 1000],
                'before' => [['insert', self::seed(15)], ['delete', ['g' => 1]]],
                'call' => ['compact'],
                'kill' => [['compact:replace', 1], ['compact:replaced', 1], ['compact:replace', 2], ['compact:replaced', 3]],
            ],
            'convertToSharded' => [
                'config' => ['shardingEnabled' => false, 'shardSize' => 4],
                'before' => [['insert', self::seed(10)], ['delete', ['n' => 9]]],
                'call' => ['convertToSharded'],
                'kill' => [['switch:shard-written', 1], ['switch:shard-written', 3], ['atomic:renaming', 2], ['generation:prepared', 1],
                    ['generation:intent', 1], ['generation:published', 1], ['generation:cleaned', 1], ['checkpoint:written', 1]],
            ],
            'create-field-index' => [
                'config' => ['shardSize' => 5],
                'before' => [['insert', self::seed(12)]],
                'call' => ['createFieldIndex', 'g'],
                'kill' => [['atomic:renaming', 1], ['atomic:renaming', 3]],
            ],
        ];
    }

    /** Apply a call to the model: [key => record], keys given in order from $next */
    private static function apply(array $model, int &$next, array $call): array
    {
        switch ($call[0]) {
            case 'insert':
            case 'insert-flush':
                foreach ($call[1] as $record) {
                    $model[$next++] = $record;
                }
                return $model;
            case 'update':
                foreach ($model as $key => $record) {
                    if (array_intersect_assoc($call[1], $record) == $call[1]) {
                        $model[$key] = array_merge($record, $call[2]);
                    }
                }
                return $model;
            case 'delete':
                foreach ($model as $key => $record) {
                    if (array_intersect_assoc($call[1], $record) == $call[1]) {
                        unset($model[$key]);
                    }
                }
                return $model;
        }
        return $model; // flush, compact, convertToSharded, createFieldIndex: the records stay as they are
    }

    /** The states an interrupted call may leave: before plus each prefix of its changes in key order */
    private static function candidates(array $before, int $next, array $call): array
    {
        $after = self::apply($before, $next, $call);
        $changed = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed[] = $key;
            }
        }
        sort($changed);
        $states = [];
        for ($k = 0; $k <= count($changed); $k++) {
            $state = $before;
            foreach (array_slice($changed, 0, $k) as $key) {
                if (array_key_exists($key, $after)) {
                    $state[$key] = $after[$key];
                } else {
                    unset($state[$key]);
                }
            }
            ksort($state);
            $states[] = array_values($state);
        }
        return $states;
    }

    private static function code(array $call): string
    {
        $db = var_export(self::DB, true);
        switch ($call[0]) {
            case 'insert':
                return '$db->insert(' . $db . ', ' . var_export($call[1], true) . ');';
            case 'insert-flush':
                return '$db->insert(' . $db . ', ' . var_export($call[1], true) . '); $db->flush(' . $db . ');';
            case 'update':
                return '$db->update(' . $db . ', [' . var_export($call[1], true) . ', ["set" => ' . var_export($call[2], true) . ']]);';
            case 'delete':
                return '$db->delete(' . $db . ', ' . var_export($call[1], true) . ');';
            case 'flush':
                return '$db->flush(' . $db . ');';
            case 'compact':
                return '$db->compact(' . $db . ');';
            case 'convertToSharded':
                return '$db->convertToSharded(' . $db . ');';
            case 'createFieldIndex':
                return '$db->createFieldIndex(' . $db . ', ' . var_export($call[1], true) . ');';
        }
        throw new \LogicException($call[0]);
    }

    /** Run a call through the API */
    private static function perform(\noneDB $db, array $call): void
    {
        switch ($call[0]) {
            case 'insert':
                $db->insert(self::DB, $call[1]);
                return;
            case 'update':
                $db->update(self::DB, [$call[1], ['set' => $call[2]]]);
                return;
            case 'delete':
                $db->delete(self::DB, $call[1]);
                return;
            case 'flush':
                $db->flush(self::DB);
                return;
        }
        throw new \LogicException($call[0]);
    }

    /** Build the state before the call (through the API) and return [model, next key] */
    private function prepare(array $op): array
    {
        $this->cleanTestDirectory();
        $db = $this->db($op['config']);
        $model = [];
        $next = 0;
        foreach ($op['before'] as $call) {
            self::perform($db, $call);
            $model = self::apply($model, $next, $call);
        }
        unset($db);
        \noneDB::clearStaticCache();
        return [$model, $next];
    }

    /**
     * Run $body in a child process after $hooks, with the operation's config plus $config. Started without a
     * shell (CLAUDE.md): a child that kills itself is reported by nobody, its output is what it printed.
     */
    private function child(array $op, string $hooks, string $body, array $config = []): string
    {
        return $this->runDirect('
            ' . $hooks . '
            try {
                $db = ' . $this->childDb($op['config'] + $config) . ';
                ' . $body . '
                echo "COMPLETED";
            } catch (\Throwable $e) {
                echo "CODE:" . $e->getCode() . ":" . get_class($e) . ":" . $e->getMessage();
            }
        ');
    }

    private function runDirect(string $body): string
    {
        $script = $this->childScript($body);
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return trim($out);
    }

    /**
     * The state after the fault, opened again: one of $allowed; then a write works, takes a new key and
     * leaves the database clean
     */
    private function verify(string $label, array $op, array $allowed, int $next, string $output, bool $leftover = false): void
    {
        \noneDB::clearStaticCache();
        $db = $this->db($op['config']);
        $records = $db->find(self::DB, 0);
        $actual = $this->plain($records);
        $this->assertContains($actual, $allowed, "$label: the records are a state the operation may leave\n$output");
        $keys = array_column($records, 'key');
        $db->insert(self::DB, ['probe' => $label]);
        $probe = $db->find(self::DB, ['probe' => $label]);
        $this->assertCount(1, $probe, $label);
        $this->assertGreaterThanOrEqual($next, $probe[0]['key'], "$label: no key handed out before is used again");
        $this->assertNotContains($probe[0]['key'], $keys, $label);
        $this->assertSame(count($actual) + 1, $db->count(self::DB), $label);
        $report = $db->checkIntegrity(self::DB);
        if (!$report['ok'] && $leftover) {
            // STORAGE_FORMAT §5.2: a generation prepared before INTENT is reported, never removed without
            // proof (the next switch removes it first); nothing else may be wrong
            $this->assertSame([['segment' => null, 'index' => 'generation', 'detail' => 'generation 2 is not the current one (1)']],
                $report['indexMismatches'], "$label: " . json_encode($report));
            $report['indexMismatches'] = [];
            $this->assertSame([], array_filter([$report['badLines'], $report['tornTail'], $report['pendingRecovery']]), "$label: " . json_encode($report));
            return;
        }
        $this->assertTrue($report['ok'], "$label: " . json_encode($report));
    }

    // ------------------------------------------------------------------ SIGKILL at every step

    /** @test SIGKILL at every step of every write operation */
    public function killedAtEveryStep(): void
    {
        $points = [];
        foreach ($this->operations() as $name => $op) {
            foreach ($op['kill'] as [$point, $nth]) {
                [$before, $next] = $this->prepare($op);
                $allowed = self::candidates($before, $next, $op['call']);
                $hook = 'noneDB\Tests\Support\Testing::setFaultHook(function ($p) {
                    static $seen = 0;
                    if ($p === ' . var_export($point, true) . ' && ++$seen === ' . $nth . ') { posix_kill(getmypid(), 9); }
                });';
                $output = $this->child($op, $hook, self::code($op['call']));
                $label = "$name killed at $point #$nth";
                if (strpos($output, 'COMPLETED') !== false) {
                    // The step doesn't occur in this operation: it ran to the end
                    $allowed = [end($allowed)];
                } else {
                    $this->assertSame('', $output, "$label: killed, nothing printed");
                    $points[$point] = true;
                }
                // Killed while generation 2 was prepared (before INTENT): it is left for the next switch
                $prepare = $op['call'][0] === 'convertToSharded' && in_array($point, ['switch:shard-written', 'atomic:renaming', 'generation:prepared'], true);
                $this->verify($label, $op, $allowed, $next, $output, $prepare);
            }
        }
        // Every step of the matrix was reached by some operation
        foreach (['append:after', 'index:written', 'scope:commit', 'checkpoint:written', 'atomic:renaming', 'shard:created',
                  'compact:replace', 'compact:replaced', 'switch:shard-written', 'generation:prepared', 'generation:intent',
                  'generation:published', 'generation:cleaned'] as $point) {
            $this->assertArrayHasKey($point, $points, "no operation was killed at $point");
        }
    }

    /** The temp files of atomic replacements in the generation's segments directory */
    private function segmentTemps(): array
    {
        return array_values(preg_grep('/\.tmp[0-9a-f]{8}$/', scandir($this->dbPath(self::DB, 'gen') . 'segments') ?: []));
    }

    /**
     * @test STORAGE_FORMAT.md §2, §5.3: a compaction killed between writing the new data file's temp and renaming it
     * leaves that temp (never read: the records are the old ones); the next compaction of the segment removes it
     */
    public function aCompactionKilledBeforeItsRenameLeavesATempTheNextCompactionRemoves(): void
    {
        foreach (['compact', 'compact-sharded'] as $name) {
            $op = $this->operations()[$name];
            [$before, $next] = $this->prepare($op);
            $hook = 'noneDB\Tests\Support\Testing::setFaultHook(function ($p, $c) {
                if ($p === "atomic:renaming" && substr((string)$c["path"], -6) === ".jsonl") { posix_kill(getmypid(), 9); }
            });';
            $this->assertSame('', $this->child($op, $hook, self::code($op['call'])), "$name: killed");
            $temps = $this->segmentTemps();
            $this->assertCount(1, $temps, "$name: the temp of the data file being replaced");
            $this->assertMatchesRegularExpression('/^(main|s[0-9]+)\.jsonl\.tmp[0-9a-f]{8}$/', $temps[0], $name);
            \noneDB::clearStaticCache();
            $db = $this->db($op['config']);
            $this->assertSame(array_values($before), $this->plain($db->find(self::DB, 0)), "$name: the old records, the temp never read");
            $db->compact(self::DB);
            $this->assertSame([], $this->segmentTemps(), "$name: removed by the next compaction");
            $this->assertSame(array_values($before), $this->plain($db->find(self::DB, 0)), "$name: compacted");
            $this->assertTrue($db->checkIntegrity(self::DB)['ok'], $name);
        }
    }

    // ------------------------------------------------------------------ I/O errors

    /** @return array fault => [hooks (child code), config added, code expected or null for any non-unknown failure, appends only] */
    private function faults(): array
    {
        $dataOnly = 'substr((string)$path, -6) === ".jsonl"';
        return [
            'short write' => ['noneDB\Tests\Support\Testing::set("writeFn", function ($fp, $bytes, $path) {
                    static $calls = 0;
                    if (!(' . $dataOnly . ')) { return fwrite($fp, $bytes); }
                    return ++$calls === 1 ? fwrite($fp, substr($bytes, 0, max(1, intdiv(strlen($bytes), 2)))) : false;
                });', [], null],
            'disk full' => ['noneDB\Tests\Support\Testing::set("writeFn", function ($fp, $bytes, $path) {
                    return ' . $dataOnly . ' ? false : fwrite($fp, $bytes);
                });', [], null],
            'fflush fails' => ['noneDB\Tests\Support\Testing::set("flushFn", function ($fp, $path) { return false; });', [], null],
            'fsync fails' => ['noneDB\Tests\Support\Testing::set("osFamily", "Linux");
                noneDB\Tests\Support\Testing::set("syncFn", function ($fp, $kind, $path) {
                    static $failed = false;
                    if ($kind === "file" && ' . $dataOnly . ' && !$failed) { $failed = true; return false; }
                    return true;
                });', ['durability' => 'durable'], \noneDB::DURABILITY_FAILED],
        ];
    }

    /** @test an I/O error rolls the operation back: exactly the state before, the original error */
    public function anIoErrorRollsTheOperationBack(): void
    {
        foreach ($this->operations() as $name => $op) {
            foreach ($this->faults() as $fault => [$hooks, $config, $code]) {
                [$before, $next] = $this->prepare($op);
                $output = $this->child($op, $hooks, self::code($op['call']), $config);
                $label = "$name / $fault";
                $allowed = self::candidates($before, $next, $op['call']);
                if (strpos($output, 'COMPLETED') !== false) {
                    // Only an operation that writes no data file goes through without the fault
                    $this->assertSame('createFieldIndex', $op['call'][0], "$label: the fault must hit\n$output");
                    $this->verify($label, $op, [end($allowed)], $next, $output);
                    continue;
                }
                $this->assertMatchesRegularExpression('/^CODE:(\d+):RuntimeException:/', $output, $label);
                $thrown = (int)explode(':', $output)[1];
                $this->assertNotSame(\noneDB::OUTCOME_UNKNOWN, $thrown, "$label: the undo worked, the outcome is known\n$output");
                if ($code !== null) {
                    $this->assertSame($code, $thrown, $label);
                }
                $this->verify($label, $op, [$allowed[0]], $next, $output);
            }
        }
    }

    /** @test a second I/O error while the rollback cuts back: OUTCOME_UNKNOWN, and every reader sees one consistent state */
    public function aSecondErrorDuringTheRollbackIsUnknown(): void
    {
        foreach ($this->operations() as $name => $op) {
            if (!in_array($op['call'][0], ['insert', 'update', 'delete', 'insert-flush'], true)) {
                continue; // no appends to cut back
            }
            [$before, $next] = $this->prepare($op);
            $hooks = 'noneDB\Tests\Support\Testing::set("flushFn", function ($fp, $path) { return substr((string)$path, -6) !== ".jsonl"; });
                noneDB\Tests\Support\Testing::setFaultHook(function ($p) {
                    if ($p === "append:undo" || $p === "rollback:truncate") { throw new \RuntimeException("I/O error"); }
                });';
            $output = $this->child($op, $hooks, self::code($op['call']));
            $label = "$name / error during the rollback";
            $this->assertStringStartsWith('CODE:' . \noneDB::OUTCOME_UNKNOWN . ':', $output, $label);
            $this->assertContains('J', $this->lockState(self::DB)['flags'], "$label: the next session recovers");
            $this->verify($label, $op, self::candidates($before, $next, $op['call']), $next, $output);
        }
    }

    /** @test a data file or directory the process may not write: the operation fails and changes nothing */
    public function aPermissionErrorChangesNothing(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
        foreach ($this->operations() as $name => $op) {
            [$before, $next] = $this->prepare($op);
            $gen = $this->dbPath(self::DB, 'gen');
            $targets = array_merge(glob($gen . 'segments/*.jsonl') ?: [], glob($gen . 'buffer/*.jsonl') ?: []);
            $dirs = array_values(array_filter([$gen . 'segments', $gen . 'buffer', $gen], 'is_dir'));
            foreach ($targets as $file) {
                chmod($file, 0444);
            }
            foreach ($dirs as $dir) {
                chmod($dir, 0555);
            }
            try {
                $output = $this->child($op, '', self::code($op['call']));
            } finally {
                foreach ($dirs as $dir) {
                    chmod($dir, 0750);
                }
                foreach ($targets as $file) {
                    chmod($file, 0640);
                }
            }
            $label = "$name / permission denied";
            $allowed = self::candidates($before, $next, $op['call']);
            if (strpos($output, 'COMPLETED') !== false) {
                // Work outside the protected directories (a new generation, index files) may succeed:
                // only operations that change no record
                $this->assertCount(1, $allowed, "$label: the records can't have changed\n$output");
                $this->verify($label, $op, $allowed, $next, $output);
                continue;
            }
            $this->assertMatchesRegularExpression('/^CODE:\d+:RuntimeException:/', $output, $label);
            $this->assertNotSame((string)\noneDB::OUTCOME_UNKNOWN, explode(':', $output)[1], "$label\n$output");
            $this->verify($label, $op, [$allowed[0]], $next, $output);
        }
    }

    /** @test a lock held by another process beyond lockTimeout: LOCK_FAILED, nothing changed */
    public function aLockTimeoutChangesNothing(): void
    {
        foreach ($this->operations() as $name => $op) {
            [$before, $next] = $this->prepare($op);
            $holder = proc_open([PHP_BINARY, '-r', '$fp = fopen($argv[1], "c+b"); flock($fp, LOCK_EX); echo "held\n"; fflush(STDOUT); sleep(3);',
                $this->dbPath(self::DB, 'lock')], [1 => ['pipe', 'w']], $pipes);
            $this->assertSame("held\n", fgets($pipes[1]));
            $op['config']['lockTimeout'] = 0.2;
            $output = $this->child($op, '', self::code($op['call']));
            proc_terminate($holder, 9);
            fclose($pipes[1]);
            proc_close($holder);
            $label = "$name / lock timeout";
            $this->assertStringStartsWith('CODE:' . \noneDB::LOCK_FAILED . ':', $output, $label);
            $this->verify($label, $op, [self::candidates($before, $next, $op['call'])[0]], $next, $output);
        }
    }
}
