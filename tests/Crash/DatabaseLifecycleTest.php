<?php

namespace noneDB\Tests\Crash;

use noneDB\Tests\RegressionTestCase;

/**
 * Database directories: creation, drop, FORMAT, damaged directories (docs/STORAGE_FORMAT.md §4, §8)
 */
class DatabaseLifecycleTest extends RegressionTestCase
{
    private function snapshot(string $dir): array
    {
        $out = [];
        $walk = function (string $path, string $rel) use (&$walk, &$out): void {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $path . '/' . $entry;
                if (is_dir($full)) {
                    $out[$rel . $entry] = 'dir';
                    $walk($full, $rel . $entry . '/');
                } else {
                    $out[$rel . $entry] = hash_file('sha256', $full);
                }
            }
        };
        if (is_dir($dir)) {
            $walk(rtrim($dir, '/'), '');
        }
        ksort($out);
        return $out;
    }

    private function record(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || !preg_match('/,"checksum":"([0-9a-f]{64})"\}\n\z/', $raw, $m, PREG_OFFSET_CAPTURE)
            || hash('sha256', substr($raw, 0, $m[0][1]) . "}\n") !== $m[1][0]) {
            return null;
        }
        return json_decode($raw, true);
    }

    private function code(callable $fn): ?int
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            return $e->getCode();
        }
        return null;
    }

    private function killAt(string $point, string $body, array $config = []): string
    {
        return $this->runChild('
            $db = ' . $this->childDb($config) . ';
            noneDB\Tests\Support\Testing::setFaultHook(function ($p) { if ($p === ' . var_export($point, true) . ') { exit(0); } });
            ' . $body . '
            echo "not reached";
        ');
    }

    /** @test */
    public function aNewDatabaseHasFormatCurrentAndGenerationOne(): void
    {
        $this->db(['shardSize' => 7])->insert('fresh', ['a' => 1]);
        $format = $this->record($this->dbPath('fresh', 'format'));
        $this->assertNotNull($format, 'FORMAT is a valid record (§4.1)');
        $this->assertSame(['magic', 'kind', 'storageFormat', 'databaseId', 'name', 'shardSize', 'createdAt', 'formatOptions', 'checksum'], array_keys($format));
        $this->assertSame('database', $format['kind']);
        $this->assertSame(4, $format['storageFormat']);
        $this->assertSame('fresh', $format['name']);
        $this->assertSame(7, $format['shardSize'], 'fixed at creation');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $format['databaseId']);
        $current = $this->record($this->dbPath('fresh', 'current'));
        $this->assertSame(['magic' => 'NONEDB4', 'kind' => 'current', 'databaseId' => $format['databaseId'], 'generation' => 1, 'checksum' => $current['checksum']], $current);
        $metadata = $this->record($this->dbPath('fresh', 'gen') . 'metadata');
        $this->assertSame('generation', $metadata['kind']);
        $this->assertSame(1, $metadata['generation']);
        $this->assertSame('single', $metadata['layout']);
        foreach (['storageFormat', 'databaseId', 'name', 'shardSize', 'createdAt'] as $member) {
            $this->assertSame($format[$member], $metadata[$member], "identity copy: $member (§5.1)");
        }
        $this->assertSame([], $this->lockState('fresh')['flags'], 'creation finished: no c flag');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $this->lockState('fresh')['epoch']);
    }

    /** @test */
    public function creationInterruptedAtEveryStepLeavesNoDatabaseOrACompleteOne(): void
    {
        foreach (['create:flagged' => false, 'create:generation' => false, 'create:current' => false, 'create:format' => true] as $point => $complete) {
            $this->cleanTestDirectory();
            $this->db()->insert('other', ['x' => 1]); // the store exists
            \noneDB::clearStaticCache();
            $out = $this->killAt($point, '$db->insert("c", ["a" => 1]);');
            $this->assertSame('', $out, $point);
            $this->assertContains('c', $this->lockState('c')['flags'], "$point: c set before anything else (§8.1)");
            \noneDB::clearStaticCache();
            $db = $this->db();
            // No data was written during creation: the interrupted insert is not there, the database is
            // either absent (cleaned up) or complete and empty
            $this->assertSame([], $db->find('c', 0), $point);
            $this->assertSame($complete ? ['c', 'other'] : ['other'], $db->getDBs(), $point);
            $db->insert('c', ['a' => 2]);
            $this->assertSame([['a' => 2, 'key' => 0]], $db->find('c', 0), "$point: usable, key 0");
            $this->assertNotContains('c', $this->lockState('c')['flags'], "$point: c cleared");
            $this->assertTrue($db->checkIntegrity('c')['ok'], $point);
        }
    }

    /**
     * @test a creation killed before its state record carries "c" (right after mkdir, or after LOCK was
     * created): the directory holds nothing or an empty LOCK, an absent location (§8.1 steps 1-3, §8.3)
     */
    public function aDirectoryLeftBeforeTheCreationFlagIsAnAbsentDatabase(): void
    {
        foreach (['empty directory' => null, 'empty LOCK' => '', 'NUL LOCK' => str_repeat("\0", 128)] as $case => $lock) {
            $this->cleanTestDirectory();
            $this->db()->insert('other', ['x' => 1]); // the store exists
            \noneDB::clearStaticCache();
            mkdir($this->dbPath('half', 'home'));
            if ($lock !== null) {
                file_put_contents($this->dbPath('half', 'lock'), $lock);
            }
            $db = $this->db();
            $this->assertSame([], $db->find('half', 0), "$case: read as absent, never FORMAT_DAMAGED");
            $this->assertSame(0, $db->count('half'), $case);
            $this->assertSame(['other'], $db->getDBs(), $case);
            $this->assertFileDoesNotExist($this->dbPath('half', 'format'), "$case: the read created nothing");
            $db->insert('half', ['a' => 1]);
            $this->assertSame([['a' => 1, 'key' => 0]], $db->find('half', 0), "$case: created from key 0");
            $this->assertSame(['half', 'other'], $db->getDBs(), $case);
            $this->assertTrue($db->checkIntegrity('half')['ok'], $case);
        }
    }

    /** @test */
    public function dropKeepsOnlyTheLockAndAllowsRecreation(): void
    {
        $db = $this->db(['shardSize' => 3]);
        $db->createFieldIndex('dr', 'g');
        $db->insert('dr', array_map(function ($n) { return ['n' => $n, 'g' => $n % 2]; }, range(0, 7)));
        $result = $db->dropDB('dr');
        $this->assertTrue($result['success']);
        $this->assertSame(['LOCK'], $this->dbFiles('dr'), '§8.2: only LOCK stays');
        $this->assertSame([], $db->find('dr', 0));
        $this->assertSame([], $db->getDBs());
        $this->assertSame(['success' => false, 'files' => 0, 'error' => 'Database does not exist'], $db->dropDB('dr'));
        $db->insert('dr', ['n' => 'new']);
        $this->assertSame([['n' => 'new', 'key' => 0]], $db->find('dr', 0), 'recreated from key 0');
    }

    /** @test */
    public function dropInterruptedAtEveryStepIsFinished(): void
    {
        foreach (['drop:flagged', 'drop:format', 'drop:cleaned'] as $point) {
            $this->cleanTestDirectory();
            $this->db()->insert('d', ['a' => 1]);
            \noneDB::clearStaticCache();
            $this->assertSame('', $this->killAt($point, '$db->dropDB("d");'), $point);
            $this->assertContains('d', $this->lockState('d')['flags'], "$point: d set first");
            \noneDB::clearStaticCache();
            $db = $this->db();
            $this->assertSame([], $db->find('d', 0), "$point: the drop is finished, never half a database");
            $this->assertSame(['LOCK'], $this->dbFiles('d'), $point);
            $this->assertNotContains('d', $this->lockState('d')['flags'], $point);
        }
    }

    /** @test */
    public function aMissingFormatIsRepairedOnlyWithProof(): void
    {
        $db = $this->db();
        $db->insert('f', [['a' => 1], ['a' => 2]]);
        $format = file_get_contents($this->dbPath('f', 'format'));
        unlink($this->dbPath('f', 'format'));
        \noneDB::clearStaticCache();
        // CURRENT and its generation's metadata are valid and agree: FORMAT comes back from the metadata
        $this->assertSame([1, 2], array_column($this->db()->find('f', 0), 'a'), '§8.3 repair with proof');
        $this->assertSame($format, file_get_contents($this->dbPath('f', 'format')), 'the same identity, byte for byte');
        $log = file_get_contents($this->dbPath('f', 'recovery'));
        $this->assertStringContainsString('"format-repaired"', $log);
    }

    /** @test */
    public function aDamagedDirectoryWithoutProofIsNeverEmpty(): void
    {
        $db = $this->db();
        $db->insert('g', [['a' => 1]]);
        foreach (['no FORMAT, CURRENT invalid' => function () {
                     unlink($this->dbPath('g', 'format'));
                     file_put_contents($this->dbPath('g', 'current'), 'garbage');
                 },
                 'FORMAT invalid, metadata invalid' => function () {
                     file_put_contents($this->dbPath('g', 'format'), "{}\n");
                     file_put_contents($this->dbPath('g', 'gen') . 'metadata', "{}\n");
                 }] as $case => $damage) {
            $this->cleanTestDirectory();
            $this->db()->insert('g', [['a' => 1]]);
            $damage();
            \noneDB::clearStaticCache();
            $before = $this->snapshot($this->testDbDir);
            $db = $this->db();
            foreach (['find' => function () use ($db) { $db->find('g', 0); },
                      'count' => function () use ($db) { $db->count('g'); },
                      'insert' => function () use ($db) { $db->insert('g', ['a' => 9]); },
                      'createDB' => function () use ($db) { $db->createDB('g'); }] as $op => $fn) {
                $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code($fn), "$case: $op is never answered as an empty database");
            }
            $this->assertSame($before, $this->snapshot($this->testDbDir), "$case: data kept, nothing changed");
            $this->assertNotContains('g', $db->getDBs(), $case);
        }
    }

    /** @test */
    public function aLostLockRecordIsANewRecordAndTheDatabaseIsRecovered(): void
    {
        $db = $this->db(['shardSize' => 2]);
        $db->insert('l', array_map(function ($n) { return ['n' => $n]; }, range(0, 4)));
        $this->removeLockFile('l');
        $db = $this->db(['shardSize' => 2]);
        $this->assertSame(range(0, 4), array_column($db->find('l', 0), 'n'));
        $state = $this->lockState('l');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string)$state['epoch'], '§4.2: an epoch is written');
        $this->assertSame([], $state['flags'], 'the recovery (J) is done');
        $db->insert('l', ['n' => 5]);
        $this->assertSame(range(0, 5), array_column($db->find('l', 0), 'n'));
    }

    /** @test */
    public function aDamagedLockRecordIsRecoveredAsJ(): void
    {
        $db = $this->db(['shardSize' => 2]);
        $db->insert('dmg', array_map(function ($n) { return ['n' => $n]; }, range(0, 4)));
        $this->writeLockState('dmg', '17 v');   // not this format (no epoch): damaged
        $db = $this->db(['shardSize' => 2]);
        $this->assertSame(range(0, 4), array_column($db->find('dmg', 0), 'n'));
        $state = $this->lockState('dmg');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string)$state['epoch']);
        $this->assertSame([], $state['flags'], 'no flag survives, J was recovered');
    }

    private function listings(): int
    {
        $property = new \noneDB\Tests\InternalValue('directoryListings');
        return $property->getValue();
    }

    /**
     * @test Only maintenance steps list a directory: compaction (stale temp files) and creating a shard
     * (orphan files); both are kept out of this test (no compaction threshold reached, room in the last shard)
     */
    public function crudOnExistingDatabasesNeverListsADirectory(): void
    {
        $config = ['shardSize' => 50, 'autoCompactThreshold' => 5];
        $db = $this->db($config);
        $db->createFieldIndex('sh', 'g');
        $db->insert('sh', array_map(function ($n) { return ['n' => $n, 'g' => $n % 7]; }, range(0, 139))); // 3 shards, room in s2
        $db->insert('one', array_map(function ($n) { return ['n' => $n]; }, range(0, 9)));
        $this->assertTrue($db->getShardInfo('sh')['sharded']);
        $crud = function (\noneDB $db, int $round) {
            foreach (['sh', 'one'] as $name) {
                $db->find($name, ['key' => 3]);
                $db->find($name, ['n' => 4]);
                $db->count($name);
                $db->insert($name, ['n' => 1000 + $round, 'g' => 1]);   // shard 2 has room: no new shard
                $db->update($name, [['key' => 5], ['set' => ['u' => $round]]]);
                $db->delete($name, ['key' => 6 + $round]);
                $db->query($name)->where(['g' => 3])->sort('n', 'desc')->limit(2)->get();
            }
            $db->find('sh', ['g' => 2]);
        };
        $crud($db, 0); // warm the caches
        $before = $this->listings();
        $crud($db, 1);
        $this->assertSame($before, $this->listings(), 'warm CRUD lists no directory (§5)');
        \noneDB::clearStaticCache();
        $before = $this->listings();
        $crud($this->db($config), 2);
        $this->assertSame($before, $this->listings(), 'a new process: store, FORMAT, CURRENT and shards.json are read, no directory is listed');
        $this->writeLockState('sh', $this->lockState('sh')['gen'] . ':' . substr(md5('other'), 0, 8)); // another process replaced a file
        $before = $this->listings();
        $crud($db, 3);
        $this->assertSame($before, $this->listings(), 'the first operations after an epoch change list nothing either');
        $this->assertSame(140 + 4 - 4, $db->count('sh'), 'every operation worked');
        $this->assertSame(3, $db->getShardInfo('sh')['shards'], 'no shard was created (that checks for orphan files once)');
    }

    /**
     * @test A drop that can't remove FORMAT did not happen (§8.2): the flags stay as they were, so the buffered records
     * of a state record with `b` stay records. Without `b` a new process read none of them.
     */
    public function aDropThatFailsKeepsTheBufferedRecords(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('a directory that cannot be written is not made with chmod here');
        }
        $db = $this->db(['bufferEnabled' => true]);
        $db->insert('fd', ['x' => 1]);
        $db->insert('fd', ['x' => 2]);
        $db->insert('fd', ['x' => 3]);
        $this->assertContains('b', $this->lockState('fd')['flags'], 'precondition: records in the buffer');
        $home = $this->dbPath('fd', 'home');
        $epoch = $this->lockState('fd')['epoch'];
        chmod($home, 0555);
        try {
            $result = $db->dropDB('fd');
        } finally {
            chmod($home, 0755);
        }
        $this->assertFalse($result['success']);
        $state = $this->lockState('fd');
        $this->assertContains('b', $state['flags'], 'the state record still says the buffer may hold records');
        $this->assertNotContains('d', $state['flags'], 'the drop was withdrawn');
        $this->assertNotSame($epoch, $state['epoch'], 'the flag was written with a new epoch: the epoch stays new');
        $out = $this->runChild('echo json_encode((' . $this->childDb(['bufferEnabled' => true]) . ')->find("fd", []));');
        $this->assertSame([['x' => 1, 'key' => 0], ['x' => 2, 'key' => 1], ['x' => 3, 'key' => 2]], json_decode($out, true), $out);
    }

    /**
     * @test A damaged directory is never absent (§8.3), for the calls that write nothing too: they say FORMAT_DAMAGED
     * as every other call does and change nothing, with or without the proof a repair needs. A read then repairs what
     * it can prove, and fails on the rest. checkIntegrity() answered `ok`, `exists` false.
     */
    public function theCallsThatWriteNothingSayADatabaseIsDamaged(): void
    {
        foreach (['with proof' => false, 'without proof' => true] as $case => $breakCurrent) {
            $this->cleanTestDirectory();
            $this->db()->insert('dm', [['x' => 1], ['x' => 2]]);
            unlink($this->dbPath('dm', 'format'));
            if ($breakCurrent) {
                file_put_contents($this->dbPath('dm', 'current'), 'junk');
            }
            $before = $this->snapshot($this->testDbDir);
            $out = $this->runChild('$db = ' . $this->childDb() . '; $codes = [];'
                . ' foreach (["checkIntegrity" => function () use ($db) { return $db->checkIntegrity("dm"); },'
                . ' "explain" => function () use ($db) { return $db->query("dm")->explain(); },'
                . ' "getMetrics" => function () use ($db) { return $db->getMetrics("dm"); }] as $call => $run) {'
                . ' try { $run(); $codes[$call] = "answered"; } catch (\RuntimeException $e) { $codes[$call] = $e->getCode(); } }'
                . ' echo json_encode($codes);');
            $this->assertSame(['checkIntegrity' => 3305, 'explain' => 3305, 'getMetrics' => 3305], json_decode($out, true), "$case: $out");
            $this->assertSame($before, $this->snapshot($this->testDbDir), "$case: nothing changed");

            $read = $this->runChild('try { echo json_encode((' . $this->childDb() . ')->find("dm", [])); } catch (\RuntimeException $e) { echo $e->getCode(); }');
            $this->assertSame($breakCurrent ? '3305' : '[{"x":1,"key":0},{"x":2,"key":1}]', $read, $case);
        }
    }
}
