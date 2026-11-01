<?php

namespace noneDB\Tests\Concurrency;

use noneDB\Tests\RegressionTestCase;

/**
 * The lock file's state record (generation, epoch, buffer/journal flags; STORAGE_FORMAT.md §4.2) and the
 * kept lock handles. The state lets operations skip per-operation checks for buffers and the index
 * journal; these tests make sure skipping them never costs correctness.
 */
class LockStateRegressionTest extends RegressionTestCase
{
    /** @test */
    public function stateFlagsFollowTheDatabase(): void
    {
        $db = $this->db();
        $db->insert('st', ['a' => 1]);
        $state = $this->lockState('st');
        $this->assertSame([], $state['flags'], 'a new database: nothing pending');
        $this->assertSame(128, filesize($this->dbPath('st', 'lock')), 'fixed size record');

        // Buffered records: flag set while they wait, cleared once they are flushed (STORAGE_FORMAT.md §4.2:
        // "{gen}:{epoch}[ b][ c][ d][ m][ g=N>X][ j=...|J]")
        $buffered = $this->db(['bufferEnabled' => true]);
        $buffered->insert('st', ['a' => 2]);
        $this->assertSame(['b'], $this->lockState('st')['flags']);
        $this->assertMatchesRegularExpression('/^\d+:[0-9a-f]{8} b +$/', $this->rawRecord('st'));
        $this->assertSame([1, 2], array_column($this->db()->find('st', 0), 'a'), 'a reader without buffering flushes them');
        $this->assertSame([], $this->lockState('st')['flags']);

        // Field index: the journal entry exists only during the write
        $db->createFieldIndex('st', 'a');
        $db->insert('st', ['a' => 3]);
        $this->assertSame([], $this->lockState('st')['flags']);

        $gen = $this->lockState('st')['gen'];
        $this->assertTrue($db->dropDB('st')['success']);
        $this->assertFileExists($this->dbPath('st', 'lock'), 'lock file survives a drop (§8.2)');
        $this->assertSame([], $this->lockState('st')['flags'], 'd cleared once the drop finished (§8.2 step 4)');
        $this->assertGreaterThan($gen, $this->lockState('st')['gen']);
        // Dropped: nothing to read (autoCreateDB: [] rather than false), a check creates it again, empty
        $this->assertSame([], $this->db()->find('st', 0));
        $this->assertSame(0, $db->count('st'));
        $this->assertTrue($db->checkDB('st'));
        $this->assertSame([], $db->find('st', 0));
    }

    /** The raw 128-byte record of a database's lock file */
    private function rawRecord(string $dbName): string
    {
        clearstatcache();
        return (string)file_get_contents($this->dbPath($dbName, 'lock'));
    }

    /** Name of the database (of $names) a file belongs to */
    private function dbOfFile(string $file, array $names): ?string
    {
        foreach ($names as $name) {
            if (strpos($file, $this->dbPath($name, 'home')) === 0) {
                return $name;
            }
        }
        return null;
    }

    /**
     * @test STORAGE_FORMAT.md §4.2: "{gen}:{epoch}[ flags]" space padded to 128 bytes, overwritten in place;
     * an empty record is a new record ("the next exclusive session writes 1:{new epoch}"); the generation is
     * bumped by the first file write of an exclusive session
     */
    public function stateRecordIsAFixedSizeRecordOfGenerationEpochAndFlags(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertTrue($db->createDB('fmt'));
        $record = $this->rawRecord('fmt');
        $this->assertSame(128, strlen($record));
        // A new lock file starts empty (a new record); createDB() is one write session: generation 1, and the
        // creation flag "c" is cleared at its end (§8.1 step 6)
        $this->assertMatchesRegularExpression('/^1:[0-9a-f]{8} {' . (128 - strlen('1:00000000')) . '}$/', $record);
        $epoch = substr($record, 2, 8);
        clearstatcache();
        $inode = fileinode($this->dbPath('fmt', 'lock'));

        $db->insert('fmt', ['a' => 1]);
        $db->insert('fmt', [['a' => 2], ['a' => 3]]);
        $this->assertSame(str_pad('3:' . $epoch, 128), $this->rawRecord('fmt'), 'two more sessions, same epoch');
        clearstatcache();
        $this->assertSame($inode, fileinode($this->dbPath('fmt', 'lock')), 'overwritten in place, never replaced');

        // Writes that fail validation write nothing: no bump
        $this->assertSame(0, $db->insert('fmt', 'not an array')['n']);
        $this->assertSame(0, $db->insert('fmt', ['key' => 5])['n']);
        $this->assertSame(str_pad('3:' . $epoch, 128), $this->rawRecord('fmt'));
    }

    /**
     * @test the journal is set before a sharded segment's data changes and cleared at the end of the session;
     * non-sharded writes need none (STORAGE_FORMAT.md §7.3: "j (or J for all segments) is set before a sharded
     * segment's data changes. It is cleared at the end of the session"; secondary indexes check themselves
     * against the data, §6.5)
     */
    public function journalIsSetDuringShardedWritesOnly(): void
    {
        $seen = [];
        $this->setFaultHook(function ($point, $context) use (&$seen) {
            if ($point === 'append:after' && preg_match('#/segments/(main|s\d+)\.jsonl$#', $context['path'])
                && ($name = $this->dbOfFile($context['path'], ['plain', 'shard'])) !== null) {
                $seen[] = trim($this->rawRecord($name));
            }
        });
        $plain = $this->db(['shardingEnabled' => false]);
        $plain->createFieldIndex('plain', 'a');
        $plain->insert('plain', ['a' => 1]);
        $plain->update('plain', [['a' => 1], ['set' => ['a' => 2]]]);
        $this->assertNotEmpty($seen);
        foreach ($seen as $state) {
            $this->assertMatchesRegularExpression('/^\d+:[0-9a-f]{8}$/', $state, 'no journal for a non-sharded write');
        }

        $sharded = $this->db(['shardSize' => 3]);
        $sharded->insert('shard', array_map(function ($n) { return ['a' => $n]; }, range(0, 3))); // switched: shards 0, 1
        $seen = [];
        $sharded->insert('shard', ['a' => 4]); // key 4: shard 1
        $this->assertSame(1, count($seen));
        $this->assertMatchesRegularExpression('/^\d+:[0-9a-f]{8} j=s1$/', $seen[0]);
        $seen = [];
        $sharded->insert('shard', [['a' => 5], ['a' => 6], ['a' => 7]]); // keys 5 (shard 1), 6 and 7 (shard 2)
        $this->assertMatchesRegularExpression('/ j=s1,s2$/', end($seen));
        $this->setFaultHook(null);
        $this->assertSame([], $this->lockState('shard')['flags'], 'cleared once the session ended');
        $this->assertSame(range(0, 7), array_column($sharded->find('shard', 0), 'a'));
    }

    /** @test a journal that doesn't fit into the 128-byte record becomes "J" (all segments) */
    public function aJournalTooLongForTheRecordBecomesJ(): void
    {
        $db = $this->db(['shardSize' => 2]);
        $db->insert('many', [['n' => 0], ['n' => 1]]); // switched: shard 0
        $states = [];
        $this->setFaultHook(function ($point) use (&$states) {
            if ($point === 'append:after') {
                clearstatcache();
                $states[] = (string)file_get_contents($this->dbPath('many', 'lock'));
            }
        });
        // keys 2-121: shards 1-60; "j=s1,s2,...,s60" alone is 232 bytes
        $db->insert('many', array_map(function ($n) { return ['n' => $n]; }, range(2, 121)));
        $this->setFaultHook(null);
        $this->assertNotEmpty($states);
        foreach ($states as $state) {
            $this->assertSame(128, strlen($state), 'never longer than the record');
            $this->assertMatchesRegularExpression('/^\d+:[0-9a-f]{8} (j=s[0-9]+(,s[0-9]+)*|J) *$/', $state);
        }
        $this->assertSame('J', substr(trim(end($states)), -1));
        $this->assertSame([], $this->lockState('many')['flags']);
        $this->assertSame(range(0, 121), array_column($db->find('many', 0), 'key'));
        $this->assertSame(122, $db->count('many'));
    }

    /**
     * @test STORAGE_FORMAT.md §7.3: a journal found at lock time makes the next exclusive session recount the
     * shard counters (shards.json) from the data; "J" means every segment
     */
    public function aPendingJournalIsRecoveredAtLockTime(): void
    {
        foreach (['J', 'j=s1'] as $journal) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardSize' => 3]);
            $db->insert('jr', array_map(function ($n) { return ['n' => $n]; }, range(0, 6))); // shards 0, 1, 2
            $metaPath = $this->dbPath('jr', 'shards');
            $meta = json_decode((string)file_get_contents($metaPath), true);
            $this->assertSame([3, 3, 1], array_map('intval', array_column($meta['shards'], 'count')), 'set up');
            // A writer died between its data append and its meta update
            $meta['totalRecords'] = 50;
            foreach ($meta['shards'] as $i => $shard) {
                if ((int)$shard['id'] === 1) {
                    $meta['shards'][$i]['count'] = 40;
                }
            }
            file_put_contents($metaPath, json_encode($meta));
            \noneDB::clearStaticCache();
            [$state] = explode(' ', trim($this->rawRecord('jr')));
            $this->writeLockState('jr', $state . ' ' . $journal);

            $db = $this->db(['shardSize' => 3]);
            $this->assertSame(7, $db->count('jr'), $journal);
            $this->assertSame([], $this->lockState('jr')['flags'], $journal . ': recovered, journal cleared');
            $meta = json_decode((string)file_get_contents($metaPath), true);
            $this->assertSame(7, (int)$meta['totalRecords'], $journal);
            $this->assertSame([3, 3, 1], array_map('intval', array_column($meta['shards'], 'count')), $journal);
            $this->assertSame(range(0, 6), array_column($db->find('jr', 0), 'n'), $journal);
        }
    }

    /**
     * @test STORAGE_FORMAT.md §4.2: "A record that is empty, all NUL bytes, or all spaces is a new record. The
     * next exclusive session writes 1:{new epoch}" (whatever the lost record said is recovered from the data)
     */
    public function aNewRecordIsWrittenAsGenerationOneWithANewEpoch(): void
    {
        $records = ['empty file' => '', 'NUL bytes' => str_repeat("\0", 128), 'spaces' => str_repeat(' ', 128)];
        foreach (['single' => ['shardingEnabled' => false], 'sharded' => ['shardSize' => 3]] as $layout => $config) {
            foreach ($records as $label => $record) {
                $this->cleanTestDirectory();
                $db = $this->db($config);
                $db->insert('nr', array_map(function ($n) { return ['n' => $n]; }, range(0, 6)));
                $db->insert('nr', ['n' => 7]);
                $epoch = $this->lockState('nr')['epoch'];
                $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string)$epoch, 'set up');
                \noneDB::clearStaticCache(); // closes the kept lock handles
                file_put_contents($this->dbPath('nr', 'lock'), $record);

                $this->assertSame(range(0, 7), array_column($this->db($config)->find('nr', 0), 'n'), "$layout, $label");
                $raw = $this->rawRecord('nr');
                $this->assertSame(128, strlen($raw), "$layout, $label");
                $this->assertMatchesRegularExpression('/^1:[0-9a-f]{8} {118}$/', $raw, "$layout, $label: 1:{new epoch}, nothing pending");
                $this->assertNotSame($epoch, $this->lockState('nr')['epoch'], "$layout, $label: a new epoch");
                $this->assertSame(8, $this->db($config)->count('nr'), "$layout, $label");
            }
        }
    }

    /** @test */
    public function generationMovesOncePerWriteAndNeverOnReads(): void
    {
        $db = $this->db();
        $db->insert('g', [['a' => 1], ['a' => 2]]);
        $db->createFieldIndex('g', 'a');
        $gen = $this->lockState('g')['gen'];

        $db->find('g', 0);
        $db->find('g', ['a' => 1]);
        $db->count('g');
        $db->query('g')->where(['a' => ['$gt' => 0]])->get();
        $this->assertSame($gen, $this->lockState('g')['gen'], 'reads leave the generation alone');

        $db->insert('g', ['a' => 3]);
        $this->assertSame($gen + 1, $this->lockState('g')['gen'], 'one write session, one bump');
        $db->update('g', [['a' => 3], ['set' => ['b' => 1]]]);
        $this->assertSame($gen + 2, $this->lockState('g')['gen']);
        $db->delete('g', ['a' => 3]);
        $this->assertSame($gen + 3, $this->lockState('g')['gen']);

        // Another process sees each change (its caches are dropped on the generation change)
        $out = $this->runChild('$d = ' . $this->childDb() . '; echo json_encode(array_column($d->find("g", 0), "a"));');
        $this->assertSame('[1,2]', $out);
    }

    /** @test the path is locked, not a deleted file a kept handle still points to */
    public function replacedLockFileIsNotTrusted(): void
    {
        $db = $this->db(['lockTimeout' => 1]);
        $db->insert('rl', ['a' => 1]);           // the lock handle stays open in this process
        $lock = $this->dbPath('rl', 'lock');
        $this->assertTrue(unlink($lock));        // e.g. a cleanup script

        $holder = $this->childScript('$fp = fopen(' . var_export($lock, true) . ', "c+"); flock($fp, LOCK_EX); echo "locked\n"; fflush(STDOUT); sleep(3);');
        $proc = proc_open([PHP_BINARY, $holder], [1 => ['pipe', 'w']], $pipes); // no shell: proc_terminate() reaches PHP
        $this->assertSame('locked', trim(fgets($pipes[1])));
        try {
            $db->insert('rl', ['a' => 2]);
            $this->fail('the insert must wait for the process holding the new lock file');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
            $this->assertSame(\noneDB::LOCK_FAILED, $e->getCode());
        } finally {
            fclose($pipes[1]);
            proc_terminate($proc);
            proc_close($proc);
        }
        $db->insert('rl', ['a' => 3]);
        $this->assertSame([1, 3], array_column($db->find('rl', 0), 'a'));
        $this->assertSame([], $this->lockState('rl')['flags'], 'state record rewritten in the new file');
        $this->assertMatchesRegularExpression('/^\d+:[0-9a-f]{8} *$/', $this->rawRecord('rl'));
    }

    /** @test a forked child must not share the parent's lock handle (flock locks belong to the open file) */
    public function forkedProcessesStillExcludeEachOther(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs pcntl');
        }
        $out = $this->runChild('
            $cfg = ' . var_export(['secretKey' => self::SECRET, 'dbDir' => $this->testDbDir], true) . ';
            $db = new \noneDB($cfg);
            $db->insert("fk", ["w" => "init"]);   // lock handle opened before the fork
            $pid = pcntl_fork();
            $who = $pid === 0 ? "c" : "p";
            for ($i = 0; $i < 300; $i++) {
                $db->insert("fk", ["w" => $who, "i" => $i]);
                $db->update("fk", [["w" => "init"], ["set" => ["n" => $i]]]);
            }
            if ($pid === 0) { exit(0); }
            pcntl_waitpid($pid, $status);
            \noneDB::clearStaticCache();
            $all = (new \noneDB($cfg))->find("fk", 0);
            $c = count(array_filter($all, function ($r) { return $r["w"] === "c"; }));
            echo count($all), " ", count(array_unique(array_column($all, "key"))), " ", $c;
        ');
        $this->assertSame('601 601 300', $out);
    }

    /**
     * @test kept lock handles are close-on-exec: a program this process starts must not inherit one
     * (an inherited copy would keep a lock held after this process died in the middle of a write)
     */
    public function keptLockHandlesAreNotInherited(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX file descriptors');
        }
        $db = $this->db();
        $db->insert('ih', ['a' => 1]); // the lock handle stays open in this process
        $lock = $this->dbPath('ih', 'lock');
        $out = $this->runChild('$ino = stat(' . var_export($lock, true) . ')["ino"]; $n = 0;
            for ($i = 3; $i < 256; $i++) {
                $f = @fopen("php://fd/$i", "r");
                if ($f) { $s = @fstat($f); if ($s && $s["ino"] === $ino) { $n++; } fclose($f); }
            }
            echo "inherited:", $n;');
        $this->assertSame('inherited:0', $out);
    }

    /** @test */
    public function keptLockHandlesAreLimited(): void
    {
        $db = $this->db();
        for ($i = 0; $i < 100; $i++) {
            $db->insert('h' . $i, ['i' => $i]);
        }
        $handles = new \noneDB\Tests\InternalValue('lockHandles');
        $this->assertLessThanOrEqual(64, count($handles->getValue()));
        for ($i = 0; $i < 100; $i++) {
            $this->assertSame([$i], array_column($db->find('h' . $i, 0), 'i'));
        }
        \noneDB::clearStaticCache();
        $this->assertSame([], $handles->getValue(), 'clearStaticCache closes them');
    }

    /** @test */
    public function readingAMissingDatabaseCreatesNothing(): void
    {
        $db = $this->db();
        $before = glob($this->testDbDir . '*');
        $this->assertSame([], $db->find('nothere', 0));
        $this->assertSame(0, $db->count('nothere'));
        $this->assertSame([], $db->query('nothere')->get());
        $this->assertSame($before, glob($this->testDbDir . '*'));
    }
}
