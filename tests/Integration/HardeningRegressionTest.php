<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Production hardening: locking, configuration and encoding findings (plan A2, A4, A10, A12)
 */
class HardeningRegressionTest extends RegressionTestCase
{
    /** @test */
    public function twoReadersUpgradingAtOnceDoNotWaitForEachOther(): void
    {
        // A-2: both hold the shared lock and need the exclusive one to flush the buffer. On BSD/macOS a
        // failed non-blocking upgrade keeps the shared lock: both waited until lockTimeout.
        $db = $this->db(['bufferEnabled' => true]);
        $db->insert('up', ['v' => 1]);
        $this->assertContains('b', $this->lockState('up')['flags']);
        \noneDB::clearStaticCache();
        $start = microtime(true) + 1.0;
        $outputs = $this->runParallel('
            $db = ' . $this->childDb(['lockTimeout' => 2]) . ';
            // Both hold the shared lock before either upgrades
            noneDB\Tests\Support\Testing::setFaultHook(function ($point) {
                if ($point === "upgrade:released") { usleep(300000); }
            });
            while (microtime(true) < ' . $start . ') { usleep(1000); }
            try {
                echo count($db->find("up", 0)), " ok";
            } catch (Throwable $e) {
                echo get_class($e), ": ", $e->getMessage();
            }
        ', 2);
        $this->assertSame(['1 ok', '1 ok'], array_values($outputs));
        $this->assertNotContains('b', $this->lockState('up')['flags']);
    }

    /** @test */
    public function writerBetweenUpgradeStepsIsRecovered(): void
    {
        // A-4: while a reader upgrades, a writer that dies leaves a journal entry; the reader must
        // recover it instead of clearing it as its own
        $db = $this->db(['shardingEnabled' => false, 'bufferEnabled' => true]);
        $db->createFieldIndex('a4', 'c');
        $db->insert('a4', ['c' => 'x']);
        $db->flush('a4');
        $db->insert('a4', ['c' => 'y']); // buffered: the next read upgrades to flush it
        $signal = $this->testDbDir . 'a4.signal';
        @unlink($signal);
        \noneDB::clearStaticCache();

        $script = $this->childScript('
            $db = ' . $this->childDb(['shardingEnabled' => false, 'lockTimeout' => 10]) . ';
            noneDB\Tests\Support\Testing::setFaultHook(function ($point) {
                static $done = false;
                if ($point === "upgrade:released" && !$done) {
                    $done = true;
                    touch(' . var_export($signal, true) . ');
                    usleep(500000); // the "other writer" takes the lock now
                }
            });
            echo json_encode(array_column($db->find("a4", ["c" => "x"]), "c"));
        ');
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $deadline = microtime(true) + 10;
        while (!file_exists($signal) && microtime(true) < $deadline) {
            usleep(1000);
        }
        $this->assertFileExists($signal);
        // A writer that died: field index damaged, journal entry left behind
        $lock = fopen($this->dbPath('a4', 'lock'), 'c+b');
        $this->assertTrue(flock($lock, LOCK_EX));
        $fxPath = $this->dbPath('a4', 'fx', 'c');
        $this->assertFileExists($fxPath);
        file_put_contents($fxPath, '{"kind":"fx","v":1,"field":"c"}' . "\n{}");
        // "{generation}:{epoch} b j=main": the writer bumped the generation, the epoch stays
        $state = explode(' ', trim(fread($lock, 128)));
        [$generation, $epoch] = explode(':', $state[0]);
        fseek($lock, 0);
        fwrite($lock, str_pad(((int)$generation + 1) . ':' . $epoch . ' b j=main', 128));
        fflush($lock);
        flock($lock, LOCK_UN);
        fclose($lock);

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $this->assertSame('', trim($err));
        $this->assertSame('["x"]', trim($out), 'the reader rebuilt the damaged field index');
        $this->assertSame([], preg_grep('/^(j=|J$)/', $this->lockState('a4')['flags']));
        \noneDB::clearStaticCache();
        $this->assertSame(['x'], array_column($this->db(['shardingEnabled' => false])->find('a4', ['c' => 'x']), 'c'));
    }

    /** @test */
    public function fractionalLockTimeoutIsHonoured(): void
    {
        // A-10: 0.2 used to become 0 = wait forever
        $db = $this->db(['lockTimeout' => 0.2]);
        $db->insert('lt', ['v' => 1]);
        $lock = fopen($this->dbPath('lt', 'lock'), 'c+b');
        $this->assertTrue(flock($lock, LOCK_EX));
        $out = $this->runChild('
            $db = ' . $this->childDb(['lockTimeout' => 0.2]) . ';
            $t = microtime(true);
            try { $db->find("lt", 0); echo "no timeout"; } catch (RuntimeException $e) { printf("%.3f %d", microtime(true) - $t, $e->getCode()); }
        ');
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertMatchesRegularExpression('/^\d+\.\d+ \d+$/', $out, 'times out');
        [$elapsed, $code] = explode(' ', $out);
        $this->assertSame(\noneDB::LOCK_FAILED, (int)$code, 'docs/CONFIGURATION.md: RuntimeException with code noneDB::LOCK_FAILED');
        $this->assertGreaterThanOrEqual(0.19, (float)$elapsed, 'it waited the 0.2 s');
        $this->assertLessThan(2.5, (float)$elapsed, 'after a fraction of a second, not after the 5 s default or never');
    }

    /** @test */
    public function invalidSettingsAreRejected(): void
    {
        foreach ([['lockTimeout', NAN], ['lockTimeout', INF], ['lockTimeout', -1], ['lockTimeout', 'abc'], ['lockTimeout', [1]],
                  ['bufferEnabled', 'yes'], ['autoCreateDB', 2], ['fieldIndexEnabled', 'on'], ['shardingEnabled', 'off'],
                  ['spatialIndexEnabled', 'enabled'], ['shardSize', -1], ['durability', 'sometimes']] as $case) {
            try {
                $this->db([$case[0] => $case[1]]);
                $this->fail("{$case[0]} = " . var_export($case[1], true) . ' must be rejected');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($case[0], $e->getMessage());
            }
        }
        foreach (['false', 'FALSE', '0', 0, false] as $off) {
            $db = $this->db(['bufferEnabled' => $off]);
            $this->assertFalse($db->isBufferingEnabled(), var_export($off, true) . ' means off');
        }
        foreach (['true', 'True', '1', 1, true] as $on) {
            $this->assertTrue($this->db(['bufferEnabled' => $on])->isBufferingEnabled(), var_export($on, true) . ' means on');
        }
        $timeout = new \noneDB\Tests\InternalValue('lockTimeout');
        $this->assertSame(1.5, $timeout->getValue($this->db(['lockTimeout' => '1.5'])), 'a numeric string, fractions kept');
        $this->assertSame(0.0, $timeout->getValue($this->db(['lockTimeout' => 0])), '0 = wait forever');
        $this->assertSame(0.2, $timeout->getValue($this->db(['lockTimeout' => 0.2])));
    }

    /** @test */
    public function serializePrecisionDoesNotChangeStoredFloats(): void
    {
        // A-12: json_encode() rounds to serialize_precision digits
        $before = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '5');
            $db = $this->db();
            $db->insert('prec', ['p' => 28.980345, 'q' => 0.30000000000000004, 'loc' => ['type' => 'Point', 'coordinates' => [28.980345, 41.008611]]]);
            $db->update('prec', [['p' => 28.980345], ['set' => ['r' => 1.0000001]]]);
            $this->assertSame('5', ini_get('serialize_precision'), 'the setting is restored');
            \noneDB::clearStaticCache();
            $row = $this->db()->find('prec', 0)[0];
            $this->assertSame(28.980345, $row['p']);
            $this->assertSame(0.30000000000000004, $row['q']);
            $this->assertSame(1.0000001, $row['r']);
            $this->assertSame([28.980345, 41.008611], $row['loc']['coordinates']);
        } finally {
            ini_set('serialize_precision', (string)$before);
        }
    }

    /** Files of a database (relative to its directory) except its LOCK; $dir: another dbDir */
    private function filesOf(string $name, ?string $dir = null): array
    {
        $home = $dir === null ? $this->dbPath($name, 'home')
            : rtrim($dir, '/') . '/' . substr(hash_hmac('sha256', 'nonedb4/db/' . $name, self::SECRET), 0, 32) . '.ndb4/';
        $files = [];
        $walk = function (string $path) use (&$walk, &$files, $home) {
            foreach (@scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_dir($path . $entry)) {
                    $walk($path . $entry . '/');
                } elseif ($path . $entry !== $home . 'LOCK') {
                    $files[] = substr($path . $entry, strlen($home));
                }
            }
        };
        $walk($home);
        sort($files);
        return $files;
    }

    /** @test */
    public function orphanShardFilesAreNeverTakenOver(): void
    {
        // A-5: a new database growing into shard 1 must not load an old shard 1 (deleted records back).
        // Shard files that shards.json does not list are set aside as {name}.orphan.{YmdHis} before a shard of
        // that number is created (STORAGE_FORMAT.md 6.3)
        $db = $this->db(['shardSize' => 10]);
        $db->insert('orph', array_map(function ($n) { return ['n' => $n, 'old' => true]; }, range(1, 30)));
        $segments = $this->dbPath('orph', 'gen') . 'segments/';
        $leftovers = [];
        foreach (scandir($segments) as $file) {
            if (preg_match('/^s[12]\./', $file)) {
                $leftovers[$file] = file_get_contents($segments . $file);
            }
        }
        $this->assertArrayHasKey('s1.jsonl', $leftovers);
        $this->assertTrue($db->dropDB('orph')['success']);
        // The new database: sharded, shard 0 only; the old files of shards 1 and 2 are put back
        $db = $this->db(['shardSize' => 10]);
        $db->insert('orph', array_map(function ($n) { return ['n' => $n, 'old' => false]; }, range(1, 5)));
        $this->assertSame('converted', $db->convertToSharded('orph')['status']);
        $segments = $this->dbPath('orph', 'gen') . 'segments/';
        foreach ($leftovers as $file => $content) {
            file_put_contents($segments . $file, $content);
        }
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 10]);
        $db->insert('orph', array_map(function ($n) { return ['n' => $n, 'old' => false]; }, range(6, 15)));
        $all = $db->find('orph', 0);
        $this->assertCount(15, $all);
        $this->assertSame([], array_values(array_filter(array_column($all, 'old'))), 'no old record is visible');
        $info = $db->getDBs('orph');
        $this->assertNotEmpty($info['orphanFiles'] ?? [], 'the set-aside files are reported');
        foreach ($info['orphanFiles'] as $file) {
            $this->assertMatchesRegularExpression('/^s1\..*\.orphan\.\d+$/', $file);
        }
        $this->assertTrue($db->dropDB('orph')['success']);
        $this->assertSame([], $this->filesOf('orph'), 'dropDB removes the orphans too');
    }

    /** @test */
    public function newShardsListTheDirectoryOncePerOperation(): void
    {
        // The leftover check of a new shard listed the whole directory for every new shard (a bulk insert
        // into 2000 shards took 80% longer): the number of listings must not depend on the number of new
        // shards, of an insert or of the switch to shards
        $listings = function () {
            $property = new \noneDB\Tests\InternalValue('directoryListings');
            return $property->getValue();
        };
        $rows = function ($from, $count) {
            return array_map(function ($n) { return ['n' => $n]; }, range($from, $from + $count - 1));
        };
        $db = $this->db(['shardSize' => 10]);
        // The first operation creates the store, which lists the directory (STORAGE_FORMAT.md 3.2): not
        // part of what is measured
        $db->insert('grow', $rows(0, 10));
        $counts = [];
        foreach (['mig3' => 25, 'mig10' => 95] as $name => $count) {
            $before = $listings();
            $db->insert($name, $rows(0, $count)); // switches into 3 / 10 shards
            $counts[] = $listings() - $before;
            $this->assertSame((int)ceil($count / 10), $db->getShardInfo($name)['shards']);
        }
        $this->assertSame($counts[0], $counts[1], 'the switch into 3 and into 10 shards');

        // Another process leaves files of shards 3 and 5 that shards.json does not list (STORAGE_FORMAT.md
        // 6.3) and writes: both new shards must set them aside
        $base = $this->dbPath('grow', 'gen') . 'segments/';
        $leftovers = function (array $files, string $base) {
            $this->assertSame('ok', $this->runChild('
                $d = ' . $this->childDb(['shardSize' => 10]) . ';
                foreach (' . var_export($files, true) . ' as $suffix => $content) {
                    file_put_contents(' . var_export($base, true) . ' . $suffix, $content);
                }
                $d->update("grow", [["n" => 0], ["set" => ["seen" => true]]]);
                echo "ok";
            '));
        };
        $leftovers(['s3.jsonl' => '{"n":"old","key":30}' . "\n", 's5.kix' => '{}'], $base);
        $counts = [];
        foreach ([20, 200] as $count) { // 2 and 20 new shards
            $before = $listings();
            $this->assertSame($count, $db->insert('grow', $rows(1000 + $count, $count))['n']);
            $counts[] = $listings() - $before;
        }
        $this->assertSame($counts[0], $counts[1], 'an insert into 2 and into 20 new shards');
        // Above every shard that had files, a new shard needs no listing at all
        $before = $listings();
        $this->assertSame(100, $db->insert('grow', $rows(5000, 100))['n']);
        $this->assertSame($before, $listings(), 'new shards above every known shard file');
        // A leftover above them, left by another process: found again
        $leftovers(['s40.jsonl' => '{"n":"old","key":400}' . "\n"], $base);
        $this->assertSame(200, $db->insert('grow', $rows(6000, 200))['n']); // shards 33 to 52
        $this->assertSame(530, $db->count('grow'));
        $this->assertSame([], $db->find('grow', ['n' => 'old']), 'no leftover record is visible');
        $orphans = $db->getDBs('grow')['orphanFiles'] ?? [];
        sort($orphans);
        $this->assertCount(3, $orphans);
        $this->assertMatchesRegularExpression('/^s3\.jsonl\.orphan\.\d+$/', $orphans[0]);
        $this->assertMatchesRegularExpression('/^s40\.jsonl\.orphan\.\d+$/', $orphans[1]);
        $this->assertMatchesRegularExpression('/^s5\.kix\.orphan\.\d+$/', $orphans[2]);
    }

    /** @test */
    public function interruptedDropCanBeRepeatedAndNothingComesBack(): void
    {
        // A-5: the drop dies part-way (after its "d" record, after removing FORMAT; STORAGE_FORMAT.md 8.2):
        // the buffered records must not reappear
        foreach (['drop:flagged', 'drop:format'] as $stop) {
            $this->cleanTestDirectory();
            $db = $this->db(['bufferEnabled' => true]);
            $db->insert('rep', ['v' => 'stored']);
            $db->flush('rep');
            $db->insert('rep', ['v' => 'buffered']);
            \noneDB::clearStaticCache();
            $out = $this->runChild('
                $db = ' . $this->childDb(['bufferEnabled' => true]) . ';
                noneDB\Tests\Support\Testing::setFaultHook(function ($point) {
                    if ($point === ' . var_export($stop, true) . ') { exit(0); }
                });
                $db->dropDB("rep");
                echo "not reached";
            ');
            $this->assertSame('', $out, $stop);
            $this->assertNotEmpty($this->filesOf('rep'), "$stop: the drop was interrupted");
            \noneDB::clearStaticCache();
            $db = $this->db(['bufferEnabled' => true]);
            // The next access finishes the interrupted drop, the database is absent (STORAGE_FORMAT.md 8.3)
            $this->assertSame(['success' => false, 'files' => 0, 'error' => 'Database does not exist'], $db->dropDB('rep'), "$stop: can be repeated");
            $this->assertSame([], $this->filesOf('rep'), $stop);
            $db->insert('rep', ['v' => 'new']);
            $this->assertSame(['new'], array_column($db->find('rep', 0), 'v'), $stop);
        }
    }

    /** @test */
    public function dbDirWithGlobCharactersWorks(): void
    {
        // A-7: glob() read [ab] as a pattern: interrupted flushes were never replayed, dropDB deleted nothing
        $dir = $this->testDbDir . (PHP_OS_FAMILY === 'Windows' ? '[ab]/' : '[ab]*?/'); // no * or ? in a name on Windows
        @mkdir($dir, 0777, true);
        try {
            $config = ['secretKey' => self::SECRET, 'dbDir' => $dir, 'bufferEnabled' => true, 'shardSize' => 4];
            $db = new \noneDB($config);
            $db->insert('g', ['v' => 1]);
            $db->insert('g', ['v' => 2]);
            // An interrupted flush: the buffer renamed, nothing inserted yet
            $buffer = $dir . substr(hash_hmac('sha256', 'nonedb4/db/g', self::SECRET), 0, 32) . '.ndb4/generations/1/buffer/';
            $this->assertTrue(rename($buffer . 'pending.jsonl', $buffer . 'flushing.0.jsonl'));
            \noneDB::clearStaticCache();
            $db = new \noneDB($config);
            $this->assertSame([1, 2], array_column($db->find('g', 0), 'v'));
            $db->insert('g', array_map(function ($n) { return ['v' => $n]; }, range(3, 10)));
            $db->flush('g');
            $db->createFieldIndex('g', 'v');
            $this->assertSame([7], array_column($db->find('g', ['v' => 7]), 'v'));
            $this->assertTrue($db->dropDB('g')['success']);
            $this->assertSame([], $this->filesOf('g', $dir));
        } finally {
            \noneDB::clearStaticCache(); // its lock handles: Windows removes no directory with an open file in it
            $remove = function (string $path) use (&$remove) {
                if (is_dir($path) && !is_link($path)) {
                    foreach (scandir($path) as $entry) {
                        if ($entry !== '.' && $entry !== '..') {
                            $remove($path . '/' . $entry);
                        }
                    }
                    rmdir($path);
                } else {
                    unlink($path);
                }
            };
            $remove(rtrim($dir, '/'));
        }
    }

    /** @test */
    public function compactionKeepsTheHighestUsedKey(): void
    {
        // A-9: after compaction and a lost index, deleted top keys were handed out again
        foreach ([['shardingEnabled' => false], ['shardSize' => 5]] as $config) {
            $name = isset($config['shardSize']) ? 'hk2' : 'hk';
            $db = $this->db($config);
            $count = isset($config['shardSize']) ? 8 : 5;
            $db->insert($name, array_map(function ($n) { return ['n' => $n]; }, range(0, $count - 1)));
            $db->delete($name, ['n' => $count - 1]);
            $db->delete($name, ['n' => $count - 2]);
            $db->compact($name);
            \noneDB::clearStaticCache();
            foreach (glob(rtrim($this->testDbDir, '/') . '/*.ndb4/generations/*/segments/*.kix') as $index) {
                unlink($index);
            }
            if (isset($config['shardSize'])) {
                // the shard checkpoint's n is what the shards.json recount falls back to
                $meta = $this->dbPath($name, 'shards');
                $m = json_decode(file_get_contents($meta), true);
                $m['nextKey'] = 0;
                file_put_contents($meta, json_encode($m));
            }
            $db = $this->db($config);
            $db->rebuildIndex($name);
            $db->insert($name, ['n' => 'new']);
            $this->assertSame($count, $db->find($name, ['n' => 'new'])[0]['key'], json_encode($config));
        }
    }

    /** @test */
    public function nestedWriteThroughAnotherInstanceKeepsItsSpatialChanges(): void
    {
        // A-15: dirty spatial indexes were tracked per instance; the outer instance cleared the journal
        $a = $this->db(['shardingEnabled' => false]);
        $b = $this->db(['shardingEnabled' => false]);
        $a->createSpatialIndex('sp', 'loc');
        $nested = false;
        $this->setFaultHook(function ($point) use ($b, &$nested) {
            if ($point === 'index:written' && !$nested) {
                $nested = true;
                $b->insert('sp', ['loc' => ['type' => 'Point', 'coordinates' => [2, 2]]]);
            }
        });
        $a->insert('sp', ['loc' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        $this->setFaultHook(null);
        \noneDB::clearStaticCache();
        $got = array_column($this->db(['shardingEnabled' => false])->withinBBox('sp', 'loc', 0, 0, 3, 3), 'key');
        sort($got);
        $this->assertSame([0, 1], $got);
    }

    /** @test */
    public function unreadableMetaIsRebuiltFromTheShards(): void
    {
        // A-16: a zero-length meta (power loss after the rename) made find() throw and count() return 0
        $db = $this->db(['shardSize' => 7]);
        $db->insert('meta', array_map(function ($n) { return ['n' => $n]; }, range(0, 19)));
        $db->delete('meta', ['n' => 19]);
        $meta = $this->dbPath('meta', 'shards');
        foreach (['', 'garbage{'] as $content) {
            file_put_contents($meta, $content);
            \noneDB::clearStaticCache();
            $db = $this->db(['shardSize' => 10]); // configured size differs from the database's 7
            $this->assertSame(range(0, 18), array_column($db->find('meta', 0), 'n'));
            $this->assertSame(19, $db->count('meta'));
            $this->assertSame(7, json_decode(file_get_contents($meta), true)['shardSize']);
            $this->assertSame('meta-rebuilt', array_column($db->getRecoveryLog('meta'), 'action')[0]);
        }
        $db->insert('meta', ['n' => 'new']);
        $this->assertSame(20, $db->find('meta', ['n' => 'new'])[0]['key'], 'key 19 (deleted) is not reused');
    }

    /** @test */
    public function staleMetaNextKeyDoesNotReuseKeys(): void
    {
        // A-16: the meta rename may not reach the disk before the appended data
        $db = $this->db(['shardSize' => 5]);
        $db->insert('stalekey', array_map(function ($n) { return ['n' => $n]; }, range(0, 7)));
        $meta = $this->dbPath('stalekey', 'shards');
        $m = json_decode(file_get_contents($meta), true);
        $m['nextKey'] = 6; // keys 6 and 7 are stored already
        file_put_contents($meta, json_encode($m));
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 5]);
        $db->insert('stalekey', ['n' => 'new']);
        $this->assertSame(8, $db->find('stalekey', ['n' => 'new'])[0]['key']);
        $this->assertCount(9, $db->find('stalekey', 0));
    }

    /** @test */
    public function zeroLengthIndexManifestKeepsTheIndexes(): void
    {
        // A-16: an empty manifest meant "no indexes": they silently stopped being maintained
        $db = $this->db(['shardingEnabled' => false]);
        $db->createFieldIndex('man', 'c');
        $db->createSpatialIndex('man', 'loc');
        $db->insert('man', ['c' => 'x', 'loc' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        file_put_contents($this->dbPath('man', 'indexes'), '');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('man', ['c' => 'x', 'loc' => ['type' => 'Point', 'coordinates' => [2, 2]]]);
        $this->assertContains('c', $db->getFieldIndexes('man')['fields']);
        $this->assertContains('loc', $db->getSpatialIndexes('man'));
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame([0, 1], array_column($db->find('man', ['c' => 'x']), 'key'), 'the index followed the insert');
        $got = array_column($db->withinBBox('man', 'loc', 0, 0, 3, 3), 'key');
        sort($got);
        $this->assertSame([0, 1], $got);
    }

    /** @test */
    public function updatingAnotherFieldLeavesTheFieldIndexAlone(): void
    {
        // T2-6: every update rewrote every field index file of the segment
        $db = $this->db(['shardingEnabled' => false]);
        $db->createFieldIndex('t26', 'c');
        $db->insert('t26', [['c' => 'x', 'v' => 1], ['c' => 'y', 'v' => 2]]);
        $fxPath = $this->dbPath('t26', 'fx', 'c');
        clearstatcache();
        // (Docker Desktop's file sharing renumbers inodes by itself: there the mtime stands in)
        $identity = function ($f) { return file_exists('/.dockerenv') ? filemtime($f) : fileinode($f); };
        $before = [$identity($fxPath), file_get_contents($fxPath)];
        $db->update('t26', [['c' => 'x'], ['set' => ['v' => 10]]]);
        clearstatcache();
        $this->assertSame($before, [$identity($fxPath), file_get_contents($fxPath)], 'not rewritten');
        $db->update('t26', [['c' => 'x'], ['set' => ['c' => 'z']]]);
        $this->assertSame([0], array_column($db->find('t26', ['c' => 'z']), 'key'));
        $this->assertSame([], $db->find('t26', ['c' => 'x']));
    }

    /** @test */
    public function interruptedMigrateKeyCanBeRepeated(): void
    {
        // T2-13: a migrateKey that died half-way left databases nobody could open or migrate again. The
        // rotation (STORAGE_FORMAT.md 9.2, 9.3) dies after marking its targets, between and after the
        // renames of the two database directories, after its commit and after the STORE copies: the next
        // run finishes it
        $newKey = 'a-new-secret-key-for-the-test-0123456789';
        $setup = function () {
            $this->cleanTestDirectory();
            $db = $this->db(['shardSize' => 3]);
            $db->createFieldIndex('mk', 'c');
            $db->createSpatialIndex('mk', 'loc');
            $db->insert('mk', array_map(function ($n) {
                return ['n' => $n, 'c' => 'c' . ($n % 2), 'loc' => ['type' => 'Point', 'coordinates' => [$n, $n]]];
            }, range(0, 7)));
            $db->insert('mk2', [['v' => 'a'], ['v' => 'b']]);
            \noneDB::clearStaticCache();
        };
        foreach ([['rotate:marked', 1], ['rotate:renamed', 1], ['rotate:renamed', 2], ['rotate:committed', 1],
                  ['maintenance:store-written', 1]] as [$stopAt, $count]) {
            $stopAfter = "$stopAt #$count";
            $setup();
            $out = $this->runChild('
                $n = 0;
                noneDB\Tests\Support\Testing::setFaultHook(function ($point) use (&$n) {
                    if ($point === ' . var_export($stopAt, true) . ' && ++$n === ' . $count . ') { exit(0); }
                });
                noneDB::migrateKey(' . var_export($this->testDbDir, true) . ', ' . var_export(self::SECRET, true) . ', ' . var_export($newKey, true) . ');
                echo "completed";
            ');
            $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, $newKey);
            $this->assertSame('', $out, "stopped at $stopAfter");
            $this->assertTrue($result['success'], "stopped at $stopAfter: " . json_encode($result) . $out);
            $db = new \noneDB(['secretKey' => $newKey, 'dbDir' => $this->testDbDir, 'shardSize' => 3]);
            $this->assertSame(range(0, 7), array_column($db->find('mk', 0), 'n'), "stop $stopAfter");
            $this->assertSame([1, 3, 5, 7], array_column($db->find('mk', ['c' => 'c1']), 'n'), "stop $stopAfter: field index");
            $this->assertCount(3, $db->withinBBox('mk', 'loc', 0, 0, 2.5, 2.5), "stop $stopAfter: spatial index");
            $this->assertSame(['a', 'b'], array_column($db->find('mk2', 0), 'v'), "stop $stopAfter");
            $this->assertSame([], $this->filesOf('mk'), 'nothing left under the old key');
        }
    }
}
