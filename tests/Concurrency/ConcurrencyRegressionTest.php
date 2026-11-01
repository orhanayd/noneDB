<?php

namespace noneDB\Tests\Concurrency;

use noneDB\Tests\RegressionTestCase;

/**
 * K-01, K-07, K-08, Y-13, O-26, T-02: real multi-process scenarios
 */
class ConcurrencyRegressionTest extends RegressionTestCase
{
    private function assertNoLostInserts(array $config, string $label): void
    {
        $db = $this->childDb($config);
        $writer = "\$db = $db; for (\$i = 0; \$i < 60; \$i++) { \$db->insert('c', ['w' => (int)\$argv[1], 'i' => \$i]); }";
        $this->runChild(str_replace('(int)$argv[1]', '0', $writer));
        $this->runParallel($writer, 5);

        $check = $this->db($config);
        $check->flush('c');
        $all = $check->find('c', 0);
        $keys = array_column($all, 'key');
        $pairs = array_unique(array_map(function ($r) { return $r['w'] . '-' . $r['i']; }, $all));

        $this->assertCount(360, $all, "$label: every insert is visible");
        $this->assertCount(360, array_unique($keys), "$label: keys are unique");
        $this->assertCount(360, $pairs, "$label: no record lost or duplicated");
        $this->assertSame(360, $check->count('c'), "$label: count() agrees");
        // Keys are assigned in order and never skipped when nothing failed: exactly 0..359
        $sorted = $keys;
        sort($sorted);
        $this->assertSame(range(0, 359), $sorted, "$label: keys 0..359");
        // Each writer inserts one record after the other: its records got increasing keys
        $byWriter = [];
        foreach ($all as $r) {
            $byWriter[$r['w']][$r['i']] = $r['key'];
        }
        ksort($byWriter);
        $this->assertSame(range(0, 5), array_keys($byWriter), "$label: writer 0 and the 5 parallel ones");
        foreach ($byWriter as $w => $keysOfWriter) {
            ksort($keysOfWriter);
            $this->assertSame(range(0, 59), array_keys($keysOfWriter), "$label: writer $w");
            $inOrder = array_values($keysOfWriter);
            $sortedKeys = $inOrder;
            sort($sortedKeys);
            $this->assertSame($sortedKeys, $inOrder, "$label: writer $w got increasing keys");
        }
    }

    /** @test K-01 */
    public function concurrentInsertsLoseNothingNonSharded(): void
    {
        $this->assertNoLostInserts([], 'plain');
    }

    /** @test K-01 */
    public function concurrentInsertsLoseNothingSharded(): void
    {
        $this->assertNoLostInserts(['shardSize' => 40], 'sharded');
    }

    /** @test K-01 K-08 */
    public function concurrentBufferedInsertsLoseNothing(): void
    {
        $this->assertNoLostInserts(['bufferEnabled' => true], 'buffered');
    }

    /** @test K-01 */
    public function concurrentMixedWritesStayConsistent(): void
    {
        $db = $this->db();
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = ['n' => $i, 'v' => 0];
        }
        $db->insert('mix', $rows);

        // 4 processes: each increments v of its own 25 records 5 times (read-modify-write through update)
        $child = $this->childDb();
        $this->runParallel("\$db = $child; \$p = (int)\$argv[1];
            for (\$round = 0; \$round < 5; \$round++) {
                for (\$n = (\$p - 1) * 25; \$n < \$p * 25; \$n++) {
                    \$r = \$db->find('mix', ['n' => \$n])[0];
                    \$db->update('mix', [['key' => \$r['key']], ['set' => ['v' => \$r['v'] + 1]]]);
                }
                \$db->delete('mix', ['n' => 1000 + \$p]); // no-op deletes interleaved
            }", 4);

        \noneDB::clearStaticCache();
        $all = $this->db()->find('mix', 0);
        $this->assertCount(100, $all);
        foreach ($all as $r) {
            $this->assertSame(5, $r['v'], 'record ' . $r['n'] . ' got every increment');
        }
    }

    /** @test K-07 */
    public function staleInstanceSeesCompactionByAnotherProcess(): void
    {
        $db = $this->db();
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = ['name' => 'user' . $i];
        }
        $db->insert('u', $rows);
        $this->assertSame('user10', $db->find('u', ['key' => 10])[0]['name']); // index now cached here

        // Another process deletes records and compacts: every byte offset changes
        $this->runChild('$db = ' . $this->childDb() . '; for ($i = 0; $i < 10; $i++) { $db->delete("u", ["key" => $i]); } $db->compact("u");');

        $this->assertSame('user10', $db->find('u', ['key' => 10])[0]['name']);
        $this->assertSame('user17', $db->find('u', ['key' => 17])[0]['name']);
        $db->update('u', [['key' => 10], ['set' => ['seen' => true]]]);

        \noneDB::clearStaticCache();
        $fresh = $this->db()->find('u', 0);
        $this->assertCount(20, $fresh);
        $names = array_column($fresh, 'name');
        $this->assertSame(count($names), count(array_unique($names)), 'no record duplicated or overwritten');
        $this->assertTrue($this->db()->find('u', ['key' => 10])[0]['seen']);
    }

    /** @test K-01 */
    public function longLivedInstanceSeesWritesOfOtherProcesses(): void
    {
        $worker = $this->db();
        $worker->insert('w', ['from' => 'worker-1']);
        $this->runChild('$db = ' . $this->childDb() . '; $db->insert("w", ["from" => "web"]);');
        $worker->insert('w', ['from' => 'worker-2']);

        \noneDB::clearStaticCache();
        $all = $this->db()->find('w', 0);
        $this->assertSame(['worker-1', 'web', 'worker-2'], array_column($all, 'from'));
        $this->assertSame([0, 1, 2], array_column($all, 'key'));
    }

    /** @test Y-13 */
    public function aSwitchToShardsByAnotherProcessIsNotSplitBrain(): void
    {
        $a = $this->db(['shardSize' => 20]);
        $a->insert('m', ['i' => -1]);
        $this->assertSame(1, count($a->find('m', 0))); // A has "not sharded" cached

        // B pushes the database over the threshold: it switches to shards
        $this->runChild('$db = ' . $this->childDb(['shardSize' => 20]) . '; $r = []; for ($i = 0; $i < 30; $i++) { $r[] = ["i" => $i]; } $db->insert("m", $r);');
        $this->assertTrue(file_exists($this->dbPath('m', 'shards')));
        $this->assertSame([], preg_grep('#/segments/main\.jsonl$#', $this->dbFiles('m')), 'set up: no non-sharded file left');

        $a->insert('m', ['i' => 999]);
        $this->assertSame([], preg_grep('#/segments/main\.jsonl$#', $this->dbFiles('m')), 'A must not recreate the non-sharded file');
        \noneDB::clearStaticCache();
        $all = $this->db()->find('m', 0);
        $this->assertCount(32, $all);
        // A's first record got key 0, B's 30 records 1..30, A's second insert key 31
        $this->assertSame(range(0, 31), array_column($all, 'key'));
        $this->assertSame(array_merge([-1], range(0, 29), [999]), array_column($all, 'i'));
    }

    /** @test O-26 */
    public function lockTimeoutIsHonoured(): void
    {
        $db = $this->db();
        $db->insert('lk', ['a' => 1]);
        $lock = $this->dbPath('lk', 'lock');
        $holder = $this->childScript('$fp = fopen(' . var_export($lock, true) . ', "c+"); flock($fp, LOCK_EX); echo "locked\n"; fflush(STDOUT); sleep(4);');
        $proc = proc_open([PHP_BINARY, $holder], [1 => ['pipe', 'w']], $pipes); // no shell: proc_terminate() reaches PHP
        $this->assertSame("locked", trim(fgets($pipes[1])));

        $start = microtime(true);
        try {
            $this->db(['lockTimeout' => 1])->insert('lk', ['a' => 2]);
            $this->fail('expected a lock timeout');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
            // docs/API.md: "Waiting longer than lockTimeout seconds throws RuntimeException with code noneDB::LOCK_FAILED"
            $this->assertSame(\noneDB::LOCK_FAILED, $e->getCode());
        }
        $elapsed = microtime(true) - $start;
        $this->assertGreaterThanOrEqual(0.95, $elapsed, 'it waited for the lock timeout');
        $this->assertLessThan(3.5, $elapsed);
        fclose($pipes[1]);
        proc_terminate($proc);
        proc_close($proc);
        \noneDB::clearStaticCache();
        $this->assertSame([['a' => 1, 'key' => 0]], $this->db()->find('lk', 0), 'nothing written without the lock');
    }
}
