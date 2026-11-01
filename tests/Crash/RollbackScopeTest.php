<?php

namespace noneDB\Tests\Crash;

use noneDB\Tests\RegressionTestCase;

/**
 * P0.4: exception contract of writes. The outermost write call of a database is one rollback
 * scope; nested writes on the same database join it. Buffer flush and the switch to shards are independent of the
 * caller only when they run before the caller appended anything; asked for later they are deferred until
 * the caller committed, like compaction. A deferred job that fails doesn't fail the committed operation.
 */
class RollbackScopeTest extends RegressionTestCase
{
    private function dataFile(string $name): string
    {
        return $this->dbPath($name, 'data');
    }

    /**
     * Every file under the test directory (store and database directories), relative path => absolute path
     * @return string[]
     */
    private function allFiles(): array
    {
        clearstatcache();
        $files = [];
        $root = rtrim($this->testDbDir, '/') . '/';
        $walk = function (string $dir) use (&$walk, &$files, $root): void {
            foreach (@scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_dir($dir . $entry)) {
                    $walk($dir . $entry . '/');
                } else {
                    $files[substr($dir . $entry, strlen($root))] = $dir . $entry;
                }
            }
        };
        $walk($root);
        ksort($files);
        return $files;
    }

    private function values(\noneDB $db, string $name, string $field = 'v'): array
    {
        return array_column($db->find($name, 0), $field);
    }

    /** Same records after every index file is deleted and rebuilt from the data in a fresh process state */
    private function assertRebuildSame(string $name, array $expected, array $config = [], string $field = 'v'): void
    {
        \noneDB::clearStaticCache();
        foreach ($this->allFiles() as $file) {
            if (substr($file, -4) === '.kix') {
                unlink($file); // key-index checkpoints (STORAGE_FORMAT.md §6.4)
            }
        }
        $this->assertSame($expected, $this->values($this->db($config), $name, $field), 'rebuilt from the data');
    }

    /** @test */
    public function nestedSameDbWriteJoinsOuterScope(): void
    {
        $db = $this->db();
        $db->insert('nest', ['v' => 'base']);
        clearstatcache();
        $before = filesize($this->dataFile('nest'));

        $nested = false;
        $this->setFaultHook(function ($point) use ($db, &$nested) {
            if ($point === 'index:written' && !$nested) {
                $nested = true;
                $db->insert('nest', ['v' => 'inner']); // e.g. an error handler writing to the same database
                throw new \RuntimeException('injected');
            }
        });
        try {
            $db->insert('nest', ['v' => 'outer']);
            $this->fail('the injected exception must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected', $e->getMessage());
        }
        $this->setFaultHook(null);

        clearstatcache();
        $this->assertSame($before, filesize($this->dataFile('nest')), 'outer and nested appends are cut back');
        $this->assertSame(['base'], $this->values($db, 'nest'));
        $this->assertRebuildSame('nest', ['base']);
    }

    /** @test */
    public function nestedSameDbWriteCommitsWithOuter(): void
    {
        $db = $this->db();
        $db->insert('nest', ['v' => 'base']);
        $nested = false;
        $this->setFaultHook(function ($point) use ($db, &$nested) {
            if ($point === 'index:written' && !$nested) {
                $nested = true;
                $db->insert('nest', ['v' => 'inner']);
            }
        });
        $this->assertSame(1, $db->insert('nest', ['v' => 'outer'])['n']);
        $this->setFaultHook(null);

        $this->assertSame(['base', 'outer', 'inner'], $this->values($db, 'nest'));
        $this->assertRebuildSame('nest', ['base', 'outer', 'inner']);
    }

    /** @test */
    public function flushBeforeOuterAppendsSurvivesOuterFailure(): void
    {
        $db = $this->db(['bufferEnabled' => true]);
        $db->insert('buf', ['v' => 'stored', 'n' => 0]);
        $db->flush('buf');
        foreach ([1, 2, 3] as $n) {
            $db->insert('buf', ['v' => 'buffered', 'n' => $n]);
        }
        $this->assertSame(1, count($this->dataLines($this->dataFile('buf'))), 'three records wait in the buffer');

        $appends = 0;
        $data = $this->dataFile('buf');
        $this->setFaultHook(function ($point, $context) use (&$appends, $data) {
            // first append to the data file: the flush at the start of update(); second: update() itself
            if ($point === 'append:after' && $context['path'] === $data && ++$appends === 2) {
                throw new \RuntimeException('injected');
            }
        });
        try {
            $db->update('buf', [['n' => 0], ['set' => ['v' => 'changed']]]);
            $this->fail('the injected exception must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected', $e->getMessage());
        }
        $this->setFaultHook(null);

        $this->assertSame(['stored', 'buffered', 'buffered', 'buffered'], $this->values($db, 'buf'), 'flushed records stay, the update is undone');
        $this->assertSame([], glob($this->dbPath('buf', 'gen') . 'buffer/flushing.*'));
        $this->assertNotContains('b', $this->lockState('buf')['flags']);
        $this->assertRebuildSame('buf', ['stored', 'buffered', 'buffered', 'buffered'], ['bufferEnabled' => true]);
    }

    /** @test */
    public function flushRequestedAfterOuterAppendsIsDeferred(): void
    {
        // Failure after the flush was requested: the insert is undone, the flush never runs
        $db = $this->db(['bufferEnabled' => true]);
        $this->setInstanceProperty($db, 'bufferCountLimit', 3);
        $db->insert('defer', ['n' => 1]);
        $db->insert('defer', ['n' => 2]);
        $this->setFaultHook(function ($point, $context) {
            if ($point === 'job:deferred' && $context['job'] === 'flush') {
                throw new \RuntimeException('injected');
            }
        });
        try {
            $db->insert('defer', ['n' => 3]);
            $this->fail('the injected exception must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected', $e->getMessage());
        }
        $this->setFaultHook(null);
        $this->assertSame([], $this->dataLines($this->dataFile('defer')), 'nothing flushed');
        $this->assertCount(2, $this->dataLines($this->dbPath('defer', 'buffer')), 'third record cut from the buffer');
        $this->assertContains('b', $this->lockState('defer')['flags']);
        $this->assertSame([1, 2], $this->values($db, 'defer', 'n'), 'the next operation flushes what is buffered');

        // Without a failure the flush runs once the insert committed
        $events = [];
        $this->setFaultHook(function ($point, $context) use (&$events) {
            if ($point === 'scope:commit' || strncmp($point, 'job:', 4) === 0) {
                $events[] = $point . (isset($context['job']) ? ':' . $context['job'] : '');
            }
        });
        $db->insert('defer', ['n' => 3]);
        $db->insert('defer', ['n' => 4]);
        $db->insert('defer', ['n' => 5]);
        $this->setFaultHook(null);
        $this->assertSame([1, 2, 3, 4, 5], array_column(array_map('json_decode', $this->dataLines($this->dataFile('defer')), array_fill(0, 5, true)), 'n'));
        $this->assertSame(['job:deferred:flush', 'scope:commit', 'job:flush', 'scope:commit'], array_slice($events, -4), 'flush after the commit');
    }

    /** @test */
    public function theSwitchToShardsAndCompactionRunAfterCommit(): void
    {
        // The switch to shards: requested by the insert that reaches shardSize
        $db = $this->db(['shardSize' => 5]);
        $db->insert('mig', [['n' => 1], ['n' => 2], ['n' => 3], ['n' => 4]]);
        $this->setFaultHook(function ($point, $context) {
            if ($point === 'job:deferred' && $context['job'] === 'convertToSharded') {
                throw new \RuntimeException('injected');
            }
        });
        try {
            $db->insert('mig', ['n' => 5]);
            $this->fail('the injected exception must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected', $e->getMessage());
        }
        $this->setFaultHook(null);
        $this->assertFileDoesNotExist($this->dbPath('mig', 'shards'));
        $this->assertSame([1, 2, 3, 4], $this->values($db, 'mig', 'n'));

        $events = [];
        $this->setFaultHook(function ($point) use (&$events) {
            $events[] = $point;
        });
        $db->insert('mig', ['n' => 5]);
        $this->setFaultHook(null);
        $this->assertFileExists($this->dbPath('mig', 'shards'));
        $this->assertLessThan(array_search('job:convertToSharded', $events, true), array_search('scope:commit', $events, true));
        $this->assertSame([1, 2, 3, 4, 5], $this->values($db, 'mig', 'n'));

        // Compaction: requested by an update that leaves too much garbage
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 0.1]);
        $db->insert('cmp', array_map(function ($n) { return ['n' => $n, 'v' => 'a']; }, range(1, 10)));
        $this->setFaultHook(function ($point, $context) {
            if ($point === 'job:deferred' && $context['job'] === 'compact:main') {
                throw new \RuntimeException('injected');
            }
        });
        try {
            $db->update('cmp', [['v' => 'a'], ['set' => ['v' => 'b']]]);
            $this->fail('the injected exception must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected', $e->getMessage());
        }
        $this->setFaultHook(null);
        $this->assertCount(10, $this->dataLines($this->dataFile('cmp')), 'update undone, nothing compacted');
        $this->assertSame(array_fill(0, 10, 'a'), $this->values($db, 'cmp'));

        $events = [];
        $this->setFaultHook(function ($point) use (&$events) {
            $events[] = $point;
        });
        $this->assertSame(10, $db->update('cmp', [['v' => 'a'], ['set' => ['v' => 'b']]])['n']);
        $this->setFaultHook(null);
        $this->assertCount(10, $this->dataLines($this->dataFile('cmp')), 'compacted after the commit');
        $this->assertLessThan(array_search('job:compact:main', $events, true), array_search('scope:commit', $events, true));
        $this->assertSame(array_fill(0, 10, 'b'), $this->values($db, 'cmp'));
    }

    /** @test */
    public function deferredJobFailureKeepsCommittedResult(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 0.1]);
        $db->insert('dj', array_map(function ($n) { return ['n' => $n, 'g' => $n <= 5 ? 'low' : 'high', 'v' => 'a']; }, range(1, 10)));
        $this->setFaultHook(function ($point) {
            if ($point === 'job:compact:main') {
                throw new \RuntimeException('compaction broke');
            }
        });
        $this->assertSame(5, $db->update('dj', [['g' => 'low'], ['set' => ['v' => 'b']]])['n'] ?? null,
            'the update reports its committed result');
        $this->setFaultHook(null);
        $this->assertSame(['b', 'b', 'b', 'b', 'b', 'a', 'a', 'a', 'a', 'a'], $this->values($db, 'dj'));
        $this->assertCount(15, $this->dataLines($this->dataFile('dj')), 'not compacted');
        $log = $db->getRecoveryLog('dj');
        $this->assertSame('deferred-failed', end($log)['action']);
        $this->assertSame('compact:main', end($log)['job']);
        $this->assertSame('compaction broke', end($log)['error']);

        // The next write that leaves garbage compacts
        $db->update('dj', [['n' => 6], ['set' => ['v' => 'c']]]);
        $this->assertCount(10, $this->dataLines($this->dataFile('dj')));
        $this->assertSame(['b', 'b', 'b', 'b', 'b', 'c', 'a', 'a', 'a', 'a'], $this->values($db, 'dj'));
    }

    /** @test */
    public function independentWorkNeverRunsInDirtyScope(): void
    {
        $registry = new \noneDB\Tests\InternalValue('lockRegistry');
        $runs = 0;
        $dirty = [];
        $this->setFaultHook(function ($point, $context) use ($registry, &$runs, &$dirty) {
            if ($point !== 'independent:run') {
                return;
            }
            $runs++;
            foreach ($registry->getValue() as $entry) {
                if ($entry['dbname'] === $context['db'] && !empty($entry['scope']['marks'])) {
                    $dirty[] = $context['job'];
                }
            }
        });
        $db = $this->db(['bufferEnabled' => true, 'shardSize' => 8, 'autoCompactThreshold' => 0.2]);
        $this->setInstanceProperty($db, 'bufferCountLimit', 3);
        $direct = $this->db(['shardSize' => 8, 'autoCompactThreshold' => 0.2]);
        for ($i = 0; $i < 30; $i++) {
            $db->insert('mix', ['n' => $i, 'v' => 'x']);
            if ($i % 4 === 3) {
                $direct->update('mix', [['n' => $i - 1], ['set' => ['v' => 'y']]]);
            }
            if ($i % 7 === 6) {
                $direct->delete('mix', ['n' => $i - 3]);
            }
        }
        $this->setFaultHook(null);
        $this->assertGreaterThan(5, $runs);
        $this->assertSame([], $dirty, 'flush/switch to shards/compaction never run on top of uncommitted appends');
        $expected = [];
        for ($i = 0; $i < 30; $i++) {
            if ($i % 7 !== 3 || $i + 3 >= 30) {
                $expected[] = $i;
            }
        }
        $this->assertSame($expected, array_column($db->find('mix', 0), 'n'));
    }

    /**
     * Run $nested inside a write of $name that appended a record and then fails (user code re-entering
     * noneDB from a write, e.g. an error handler)
     */
    private function failingWriteWith(\noneDB $db, string $name, callable $nested): \Throwable
    {
        $method = new \noneDB\Tests\InternalMethod('withLock');
        try {
            $method->invoke($db, $name, true, function () use ($db, $name, $nested) {
                $db->insert($name, ['v' => 'uncommitted']);
                $nested();
                throw new \RuntimeException('outer failed');
            });
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('the outer write must fail');
    }

    private function fileSnapshot(): array
    {
        $files = [];
        foreach ($this->allFiles() as $name => $file) {
            if (basename($file) !== 'LOCK' && basename($file) !== 'STORE.LOCK') {
                // shards.json keeps the keys a failed write reserved (never reused): its content may change
                $files[$name] = basename($file) === 'shards.json' ? 'meta' : hash_file('sha256', $file);
            }
        }
        return $files;
    }

    /** @test */
    public function dataFileReplacementIsRefusedInsideAnUncommittedWrite(): void
    {
        // dropDB(), compact() and convertToSharded() replace or remove data files: a rollback can't undo that, so
        // inside a write that appended and isn't committed they must refuse before touching any file
        $cases = [
            // [operation, config, sharded database]
            ['dropDB', ['shardingEnabled' => false], false],
            ['dropDB', ['shardSize' => 5], true],
            ['compact', ['shardingEnabled' => false], false],
            ['compact', ['shardSize' => 5], true],
            ['convertToSharded', ['shardSize' => 5, 'shardingEnabled' => false], false],
            ['convertToSharded', ['shardSize' => 5], true], // already sharded: refused all the same, before looking
        ];
        foreach ($cases as [$operation, $config, $sharded]) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            if ($sharded) {
                // keys 0-4 (shard 0) deleted, the records below go to shard 1: a sharded database
                $db->insert('dirty', array_fill(0, 5, ['v' => 'filler']));
                $db->delete('dirty', ['v' => 'filler']);
            }
            $db->insert('dirty', [['v' => 'original'], ['v' => 'second']]);
            $db->delete('dirty', ['v' => 'second']);
            $db->insert('dirty', ['v' => 'second']); // something for compaction to drop
            $this->assertSame($sharded, $db->getShardInfo('dirty')['sharded'], 'set up');
            $before = $this->fileSnapshot();
            $label = $operation . ' ' . json_encode($config);

            $e = $this->failingWriteWith($db, 'dirty', function () use ($db, $operation) {
                $db->$operation('dirty');
            });
            $this->assertInstanceOf(\LogicException::class, $e, $label . ': ' . get_class($e) . ' ' . $e->getMessage());
            $this->assertStringContainsString($operation . '()', $e->getMessage());
            $this->assertSame($before, $this->fileSnapshot(), $label . ': no file changed');
            $this->assertSame(['original', 'second'], $this->values($this->db($config), 'dirty'), $label);

            // Caught by the caller's code: the outer write commits, still nothing replaced
            $method = new \noneDB\Tests\InternalMethod('withLock');
            $refused = $method->invoke($db, 'dirty', true, function () use ($db, $operation) {
                $db->insert('dirty', ['v' => 'third']);
                try {
                    $db->$operation('dirty');
                } catch (\LogicException $e) {
                    return true;
                }
                return false;
            });
            $this->assertTrue($refused, $label);
            $this->assertSame(['original', 'second', 'third'], $this->values($db, 'dirty'), $label);

            // Inside a write that has appended nothing yet it is refused too (workThatReplacesFilesIsRefusedBefore...)
            $refused = $method->invoke($db, 'dirty', true, function () use ($db, $operation) {
                try {
                    $db->$operation('dirty');
                } catch (\LogicException $e) {
                    return true;
                }
                return false;
            });
            $this->assertTrue($refused, $label);

            // Outside a write it runs
            $result = $db->$operation('dirty');
            $this->assertTrue($result['success'] ?? null, $label . ': ' . json_encode($result));
            if ($operation === 'convertToSharded') {
                $this->assertSame($sharded ? 'already_sharded' : 'converted', $result['status'], $label);
            }
            $this->assertSame($operation === 'dropDB' ? [] : ['original', 'second', 'third'], $this->values($this->db($config), 'dirty'), $label);
            $this->assertRebuildSame('dirty', $operation === 'dropDB' ? [] : ['original', 'second', 'third'], $config);
        }
    }

    /** @test */
    public function aFileWithUncommittedAppendsIsNeverReplaced(): void
    {
        // Internal paths too (not only the public methods): replacing or removing a file the scope
        // appended to is refused, so the rollback can always cut it back
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('mark', [['v' => 'original'], ['v' => 'second']]);
        $compact = new \noneDB\Tests\InternalMethod('compactJsonl');
        $before = $this->fileSnapshot();
        $e = $this->failingWriteWith($db, 'mark', function () use ($db, $compact) {
            $compact->invoke($db, 'mark', null);
        });
        $this->assertInstanceOf(\LogicException::class, $e, get_class($e) . ' ' . $e->getMessage());
        $this->assertNotSame(\noneDB::OUTCOME_UNKNOWN, $e->getCode());
        $this->assertSame(['original', 'second'], $this->values($this->db(['shardingEnabled' => false]), 'mark'));
        $this->assertSame(array_keys($before), array_keys($this->fileSnapshot()));
        $this->assertRebuildSame('mark', ['original', 'second'], ['shardingEnabled' => false]);

        // Every file operation that replaces, renames or removes a file refuses one the scope appended to
        $data = $this->dbPath('mark', 'data');
        $attempts = [
            'removal' => function () use ($data) { return $this->fsUnlink($data); },
            'rename' => function () use ($data) { return $this->fsRename($data, $data . '.moved'); },
            'replacement by rename' => function () use ($data) { return $this->fsRename($data . '.other', $data); },
            'replacement' => function () use ($data) { return $this->writeFileAtomic($data, "{}\n", 'replace'); },
        ];
        foreach ($attempts as $label => $attempt) {
            \noneDB::clearStaticCache();
            $db = $this->db(['shardingEnabled' => false]);
            file_put_contents($data . '.other', "{\"v\":\"other\",\"key\":0}\n");
            $before = $this->fileSnapshot();
            $e = $this->failingWriteWith($db, 'mark', function () use ($attempt, $db) {
                $attempt->call(\noneDB\Tests\Support\Testing::engine($db));
            });
            $this->assertInstanceOf(\LogicException::class, $e, $label . ': ' . get_class($e) . ' ' . $e->getMessage());
            $this->assertStringContainsString('refused', $e->getMessage(), $label);
            $this->assertSame($before, $this->fileSnapshot(), $label . ': the appends were cut back, nothing else changed');
            $this->assertFileDoesNotExist($data . '.moved', $label);
            $this->assertSame(['original', 'second'], $this->values($this->db(['shardingEnabled' => false]), 'mark'), $label);
            unlink($data . '.other');
        }
    }

    /** @test the scope belongs to one database: a nested write to another database commits on its own */
    public function nestedWriteToAnotherDatabaseCommitsOnItsOwn(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('outer', ['v' => 'base']);
        $db->insert('inner', ['v' => 'base']);
        $e = $this->failingWriteWith($db, 'outer', function () use ($db) {
            $db->insert('inner', ['v' => 'nested']);
        });
        $this->assertSame('outer failed', $e->getMessage());
        $this->assertSame(['base'], $this->values($db, 'outer'), 'the failed write is undone');
        $this->assertSame(['base', 'nested'], $this->values($db, 'inner'), 'the other database committed its write');
        $this->assertRebuildSame('outer', ['base']);
        $this->assertRebuildSame('inner', ['base', 'nested']);
    }

    /** @test the refusal is per database: dropDB()/compact() of another database run inside a dirty write */
    public function replacingAnotherDatabaseInsideAnUncommittedWriteIsAllowed(): void
    {
        $config = ['shardingEnabled' => false, 'autoCompactThreshold' => 1000];
        $db = $this->db($config);
        $db->insert('own', ['v' => 'base']);
        $db->insert('other', [['v' => 'keep'], ['v' => 'gone']]);
        $db->delete('other', ['v' => 'gone']);
        $db->insert('third', ['v' => 'x']);
        $results = [];
        $e = $this->failingWriteWith($db, 'own', function () use ($db, &$results) {
            $results[] = $db->compact('other');
            $results[] = $db->dropDB('third');
        });
        $this->assertSame('outer failed', $e->getMessage(), 'not refused');
        $this->assertTrue($results[0]['success']);
        $this->assertSame(1, $results[0]['freedSlots']);
        $this->assertTrue($results[1]['success']);
        $this->assertSame(['base'], $this->values($db, 'own'));
        $this->assertSame(['keep'], $this->values($db, 'other'));
        $this->assertSame(['{"v":"keep","key":0}', '{"__nonedb_deleted":1}'], $this->dataLines($this->dataFile('other')), 'compacted');
        $this->assertSame([], $db->getDBs('third') ?: []);
    }

    /**
     * @test documented behaviour: "compaction, the switch to shards and a flush the call itself triggers run
     * after the call committed. If that later work fails, the call still reports success; the failure goes to
     * getRecoveryLog() and the work is retried later."
     */
    public function failedDeferredFlushAndSwitchToShardsAreLoggedAndRetried(): void
    {
        // A flush the insert triggered (count limit reached)
        $db = $this->db(['bufferEnabled' => true]);
        $this->setInstanceProperty($db, 'bufferCountLimit', 3);
        $db->insert('df', ['n' => 1]);
        $db->insert('df', ['n' => 2]);
        $this->setFaultHook(function ($point) {
            if ($point === 'job:flush') {
                throw new \RuntimeException('flush failed');
            }
        });
        $this->assertSame(['n' => 1], $db->insert('df', ['n' => 3]), 'the insert committed');
        $this->setFaultHook(null);
        $log = $db->getRecoveryLog('df');
        $this->assertSame(['deferred-failed', 'flush', 'flush failed'], [end($log)['action'], end($log)['job'], end($log)['error']]);
        $this->assertSame(3, $db->getBufferInfo('df')['buffers']['main']['records'], 'still buffered');
        $this->assertSame([1, 2, 3], $this->values($db, 'df', 'n'), 'the next operation flushes');
        $this->assertSame(0, $db->getBufferInfo('df')['buffers']['main']['records']);
        $this->assertCount(3, $this->dataLines($this->dataFile('df')));

        // A switch to shards the insert triggered (shardSize reached)
        $db = $this->db(['shardSize' => 5]);
        $db->insert('dm', array_map(function ($n) { return ['n' => $n]; }, range(1, 4)));
        $this->setFaultHook(function ($point) {
            if ($point === 'job:convertToSharded') {
                throw new \RuntimeException('switch failed');
            }
        });
        $this->assertSame(['n' => 1], $db->insert('dm', ['n' => 5]));
        $this->setFaultHook(null);
        $log = $db->getRecoveryLog('dm');
        $this->assertSame(['deferred-failed', 'convertToSharded', 'switch failed'], [end($log)['action'], end($log)['job'], end($log)['error']]);
        $this->assertFalse($db->getShardInfo('dm')['sharded']);
        $this->assertSame(range(1, 5), $this->values($db, 'dm', 'n'));
        $db->insert('dm', ['n' => 6]); // retried
        $this->assertTrue($db->getShardInfo('dm')['sharded']);
        $this->assertSame(range(1, 6), $this->values($db, 'dm', 'n'));
        $this->assertSame(range(0, 5), $this->values($db, 'dm', 'key'));
    }

    /**
     * @test a file that was replaced or removed behind the scope's back can't be cut back to its mark: the outcome
     * is unknown (OUTCOME_UNKNOWN, journal "J"), and the next access recovers
     */
    public function rollbackOfAFileReplacedOrRemovedMeanwhileFails(): void
    {
        foreach (['replaced', 'removed'] as $what) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardingEnabled' => false]);
            $db->insert('gone', ['v' => 'base']);
            $data = $this->dataFile('gone');
            $this->setFaultHook(function ($point, $context) use ($data, $what) {
                if ($point === 'append:after' && $context['path'] === $data) {
                    if ($what === 'replaced') {
                        copy($data, $data . '.copy');
                        rename($data . '.copy', $data); // same bytes, another file
                    } else {
                        unlink($data);
                    }
                    throw new \RuntimeException('injected');
                }
            });
            try {
                $db->insert('gone', ['v' => 'second']);
                $this->fail($what . ': must throw');
            } catch (\RuntimeException $e) {
                $this->assertSame(\noneDB::OUTCOME_UNKNOWN, $e->getCode(), $what . ': ' . $e->getMessage());
                $this->assertSame('injected', $e->getPrevious()->getMessage(), $what);
            }
            $this->setFaultHook(null);
            $this->assertContains('J', $this->lockState('gone')['flags'], $what);
            \noneDB::clearStaticCache();
            $db = $this->db(['shardingEnabled' => false]);
            // replaced: the copy holds the complete line (roll forward); removed: the data is gone
            $this->assertSame($what === 'replaced' ? ['base', 'second'] : [], $this->values($db, 'gone'), $what);
            $this->assertSame([], preg_grep('/^(j=|J$)/', $this->lockState('gone')['flags']), $what . ': recovered');
        }
    }

    /** @test */
    public function migrateKeyIsRefusedWhileThisProcessHoldsALock(): void
    {
        // It would wait forever for the lock this process holds (flock() of another handle)
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('mk', ['v' => 'a']);
        $method = new \noneDB\Tests\InternalMethod('withLock');
        $before = $this->fileSnapshot();
        foreach ([false, true] as $exclusive) {
            $result = $method->invoke($db, 'mk', $exclusive, function () {
                return \noneDB::migrateKey($this->testDbDir, self::SECRET, 'another-secret-key-1234567890');
            });
            $this->assertSame(['code', 'databases', 'error', 'storeManifest', 'success'], $this->sortedKeys($result));
            $this->assertFalse($result['success']);
            $this->assertSame([], $result['databases']);
            $this->assertNull($result['storeManifest'], 'no new store record');
            $this->assertStringContainsString('lock', (string)$result['error']);
            $this->assertSame($before, $this->fileSnapshot(), 'nothing renamed');
        }
        $this->assertSame(['a'], $this->values($db, 'mk'));
        // Outside every operation it runs
        $this->assertTrue(\noneDB::migrateKey($this->testDbDir, self::SECRET, 'another-secret-key-1234567890')['success']);
        $this->assertSame(['a'], $this->values(new \noneDB(['secretKey' => 'another-secret-key-1234567890', 'dbDir' => $this->testDbDir]), 'mk'));
    }

    /** @test */
    public function multiShardInsertIsUndoneOnEveryShard(): void
    {
        // A-14: the append to shard 2 fails after shard 1's (and after the meta reserved both)
        $db = $this->db(['shardSize' => 5]);
        $db->insert('ms', array_map(function ($n) { return ['n' => $n]; }, range(0, 4)));
        $this->assertFileExists($this->dbPath('ms', 'shards'));
        $this->setFaultHook(function ($point, $context) {
            if ($point === 'append:after' && substr($context['path'], -strlen('/segments/s2.jsonl')) === '/segments/s2.jsonl') {
                throw new \RuntimeException('shard 2 failed');
            }
        });
        try {
            $db->insert('ms', array_map(function ($n) { return ['n' => $n]; }, range(5, 12)));
            $this->fail('the append to shard 2 must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('shard 2 failed', $e->getMessage());
        }
        $this->setFaultHook(null);
        clearstatcache();
        $this->assertSame(0, filesize($this->dbPath('ms', 'data', 1)), 'shard 1 cut back too');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 5]);
        $this->assertSame(range(0, 4), $this->values($db, 'ms', 'n'), 'no record of the failed insert on any shard');
        $this->assertSame(5, $db->count('ms'), 'meta counters follow the data');
        $db->insert('ms', ['n' => 99]);
        $keys = array_column($db->find('ms', 0), 'key');
        $this->assertGreaterThan(12, end($keys), 'reserved keys are never reused');
        $this->assertRebuildSame('ms', [0, 1, 2, 3, 4, 99], ['shardSize' => 5], 'n');
    }

    /** @test */
    public function failedRollbackReportsUnknownOutcomeAndRecovers(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->createFieldIndex('rf', 'v');
        $db->insert('rf', ['v' => 'base']);
        $this->setFaultHook(function ($point) {
            if ($point === 'append:after') {
                throw new \RuntimeException('injected');
            }
            if ($point === 'rollback:truncate') {
                throw new \RuntimeException('disk gone');
            }
        });
        try {
            $db->insert('rf', ['v' => 'second']);
            $this->fail('must throw');
        } catch (\RuntimeException $e) {
            $this->assertSame(\noneDB::OUTCOME_UNKNOWN, $e->getCode());
            $this->assertNotNull($e->getPrevious());
            $this->assertSame('injected', $e->getPrevious()->getMessage());
        }
        $this->setFaultHook(null);
        $this->assertContains('J', $this->lockState('rf')['flags'], 'the next lock holder recovers everything');

        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        // The line could not be cut: it is a complete record, so recovery keeps it (roll forward)
        $this->assertSame(['base', 'second'], $this->values($db, 'rf'));
        $this->assertSame(['second'], array_column($db->find('rf', ['v' => 'second']), 'v'), 'field index rebuilt');
        $this->assertSame([], preg_grep('/^(j=|J$)/', $this->lockState('rf')['flags']));
    }

    /** @test */
    public function theSwitchToShardsInterruptedAtEveryStepStaysConsistent(): void
    {
        // P0.4 (10): a process killed during the switch to shards (exit() skips finally blocks, like a fatal):
        // while the new generation is built, and after each step of the generation switch (STORAGE_FORMAT.md
        // §5.2: PREPARE, INTENT, PUBLISH, CLEANUP)
        foreach (['switch:shard-written', 'generation:prepared', 'generation:intent', 'generation:published', 'generation:cleaned'] as $point) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardSize' => 4]);
            $db->createFieldIndex('mig', 'c');
            $db->insert('mig', array_map(function ($n) { return ['n' => $n, 'c' => 'c' . ($n % 2)]; }, range(0, 2)));
            \noneDB::clearStaticCache();
            $out = $this->runChild('
                $db = ' . $this->childDb(['shardSize' => 4]) . ';
                noneDB\Tests\Support\Testing::setFaultHook(function ($p) {
                    if ($p === ' . var_export($point, true) . ') { exit(0); }
                });
                $db->insert("mig", [["n" => 3, "c" => "c1"], ["n" => 4, "c" => "c0"]]); // reaches shardSize: the switch to shards after the commit
                echo "not reached";
            ');
            $this->assertSame('', $out, $point);
            \noneDB::clearStaticCache();
            $db = $this->db(['shardSize' => 4]);
            $this->assertSame(range(0, 4), array_column($db->find('mig', 0), 'n'), "$point: every record once");
            $this->assertSame([1, 3], array_column($db->find('mig', ['c' => 'c1']), 'n'), "$point: field index");
            $db->insert('mig', ['n' => 5, 'c' => 'c1']);
            $this->assertSame(range(0, 5), array_column($db->find('mig', 0), 'n'), "$point: still writable");
            $this->assertSame([1, 3, 5], array_column($db->find('mig', ['c' => 'c1']), 'n'), $point);
            $this->assertRebuildSame('mig', range(0, 5), ['shardSize' => 4], 'n');
            // The shards the interrupted switch wrote are its own: removed or reused, never kept as a
            // second copy of the data under *.orphan.*
            $this->assertSame([], preg_grep('/\.orphan\./', $this->dbFiles('mig')), "$point: no orphan copies");
            $this->assertArrayNotHasKey('orphanFiles', $db->getDBs('mig'), $point);
            // Nothing of the interrupted switch is left: one generation, the one CURRENT names (§5.2)
            $current = json_decode((string)file_get_contents($this->dbPath('mig', 'current')), true)['generation'];
            $this->assertSame([(string)$current], array_values(array_diff(scandir($this->dbPath('mig', 'home') . 'generations'), ['.', '..'])), "$point: one generation left");
            $this->assertSame([], $this->lockState('mig')['flags'], "$point: no switch pending (§5.2 DONE)");
        }
    }

    private function sortedKeys(array $result): array
    {
        $keys = array_keys($result);
        sort($keys);
        return $keys;
    }

    /**
     * @test The calls that replace or remove files are refused inside a write of the same database from its first step,
     * not from its first append: a value's jsonSerialize() runs before it. A drop there left the write on a database
     * that was gone, and every later call on FORMAT_DAMAGED.
     */
    public function workThatReplacesFilesIsRefusedBeforeTheWriteHasAppended(): void
    {
        $calls = [
            'dropDB' => function ($db, $name) { return $db->dropDB($name); },
            'compact' => function ($db, $name) { return $db->compact($name); },
            'convertToSharded' => function ($db, $name) { return $db->convertToSharded($name); },
        ];
        foreach ($calls as $label => $call) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardingEnabled' => false]);
            $db->insert('ne', [['x' => 0]]);
            $value = new class($db, $call) implements \JsonSerializable {
                public $thrown = null;
                private $calls = 0;
                private $db;
                private $call;
                public function __construct($db, $call) { $this->db = $db; $this->call = $call; }
                public function jsonSerialize(): mixed
                {
                    // The first call checks the input, before the lock; the second encodes the line inside the write
                    if (++$this->calls === 2) {
                        try {
                            ($this->call)($this->db, 'ne');
                            $this->thrown = false;
                        } catch (\Throwable $e) {
                            $this->thrown = $e;
                        }
                    }
                    return 1;
                }
            };
            $db->insert('ne', ['x' => $value]);
            $this->assertInstanceOf(\LogicException::class, $value->thrown, $label);
            $this->assertSame([['x' => 0, 'key' => 0], ['x' => 1, 'key' => 1]], $db->find('ne', []), $label);
            $this->assertTrue($db->checkIntegrity('ne')['ok'], $label);
        }
    }
}
