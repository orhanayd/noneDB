<?php

namespace noneDB\Tests\Regression;

/**
 * The lock file's state record (generation, verified/buffer/journal flags) and the kept lock handles.
 * The state lets operations skip per-operation checks for old-version leftovers, buffers and the index
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
        $this->assertSame(['v'], $state['flags'], 'a new database is verified, nothing pending');
        $this->assertSame(128, filesize($this->dbBasePath('st') . '.nonedb.lock'), 'fixed size record');

        // Buffered records: flag set while they wait, cleared once they are flushed
        $buffered = $this->db(['bufferEnabled' => true]);
        $buffered->insert('st', ['a' => 2]);
        $this->assertContains('b', $this->lockState('st')['flags']);
        $this->assertSame([1, 2], array_column($this->db()->find('st', 0), 'a'), 'a reader without buffering flushes them');
        $this->assertSame(['v'], $this->lockState('st')['flags']);

        // Field index: the journal entry exists only during the write
        $db->createFieldIndex('st', 'a');
        $db->insert('st', ['a' => 3]);
        $this->assertSame(['v'], $this->lockState('st')['flags']);

        $gen = $this->lockState('st')['gen'];
        $this->assertTrue($db->dropDB('st')['success']);
        $this->assertFileExists($this->dbBasePath('st') . '.nonedb.lock', 'lock file survives a drop');
        $this->assertSame(['v'], $this->lockState('st')['flags']);
        $this->assertGreaterThan($gen, $this->lockState('st')['gen']);
        $this->assertFalse($db->checkDB('st') && $db->count('st') > 0);
        $this->assertSame([], $this->db()->find('st', 0) ?: []);
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
        $lock = $this->dbBasePath('rl') . '.nonedb.lock';
        $this->assertTrue(unlink($lock));        // e.g. a cleanup script

        $holder = $this->childScript('$fp = fopen(' . var_export($lock, true) . ', "c+"); flock($fp, LOCK_EX); echo "locked\n"; fflush(STDOUT); sleep(3);');
        $proc = proc_open([PHP_BINARY, $holder], [1 => ['pipe', 'w']], $pipes); // no shell: proc_terminate() reaches PHP
        $this->assertSame('locked', trim(fgets($pipes[1])));
        try {
            $db->insert('rl', ['a' => 2]);
            $this->fail('the insert must wait for the process holding the new lock file');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        } finally {
            fclose($pipes[1]);
            proc_terminate($proc);
            proc_close($proc);
        }
        $db->insert('rl', ['a' => 3]);
        $this->assertSame([1, 3], array_column($db->find('rl', 0), 'a'));
        $this->assertSame(['v'], $this->lockState('rl')['flags'], 'state record rewritten in the new file');
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
        $lock = $this->dbBasePath('ih') . '.nonedb.lock';
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
        $handles = new \ReflectionProperty(\noneDB::class, 'lockHandles');
        if (PHP_VERSION_ID < 80100) {
            $handles->setAccessible(true); // no effect since 8.1, deprecated in 8.5
        }
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
        $this->assertSame([], $db->find('nothere', 0) ?: []);
        $this->assertSame(0, $db->count('nothere'));
        $this->assertSame([], $db->query('nothere')->get());
        $this->assertSame($before, glob($this->testDbDir . '*'));
    }

    /**
     * @test a pre-3.2 database is checked once (when it has no state record yet), not per operation;
     * deleting the lock files makes the check run again
     */
    public function oldVersionLeftoversAreCheckedOnce(): void
    {
        $db = $this->db();
        $db->insert('ov', [['a' => 1]]);
        file_put_contents($this->dbBasePath('ov') . '.nonedb.buffer', json_encode(['a' => 2]) . "\n");
        $this->removeLockFile('ov');

        $db = $this->db();
        $this->assertSame([1, 2], array_column($db->find('ov', 0), 'a'), 'found by the one-time check');
        $this->assertSame(['v'], $this->lockState('ov')['flags']);

        // Verified: an old-version file appearing now is not looked for on every operation...
        file_put_contents($this->dbBasePath('ov') . '.nonedb.buffer.flushing', json_encode(['a' => 3]) . "\n");
        $this->assertSame([1, 2], array_column($db->find('ov', 0), 'a'));
        // ...until the state record is removed (documented for going back and forth between versions)
        $this->removeLockFile('ov');
        $this->assertSame([1, 2, 3], array_column($this->db()->find('ov', 0), 'a'));
    }
}
