<?php

namespace noneDB\Tests\Crash;

use noneDB\Tests\RegressionTestCase;

/**
 * Generation switch and CURRENT (docs/STORAGE_FORMAT.md §4.3, §5.2): the single -> sharded layout
 * change interrupted at every step, recovery, invalid CURRENT, another process's stale caches.
 */
class GenerationSwitchTest extends RegressionTestCase
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

    private function code(callable $fn): ?int
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            return $e->getCode();
        }
        return null;
    }

    private function generations(string $db): array
    {
        $out = array_values(array_filter(scandir($this->dbPath($db, 'home') . 'generations') ?: [], 'ctype_digit'));
        sort($out);
        return array_map('intval', $out);
    }

    private function sealed(array $record): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        $json = json_encode($record, $flags);
        $record['checksum'] = hash('sha256', $json . "\n");
        return json_encode($record, $flags) . "\n";
    }

    /** Child: 3 records in the single layout, then 2 more reach shardSize 4: the switch runs after the commit */
    private function interruptSwitchAt(string $point): string
    {
        $db = $this->db(['shardSize' => 4]);
        $db->createFieldIndex('m', 'c');
        $db->insert('m', array_map(function ($n) { return ['n' => $n, 'c' => 'c' . ($n % 2)]; }, range(0, 2)));
        \noneDB::clearStaticCache();
        return $this->runChild('
            $db = ' . $this->childDb(['shardSize' => 4]) . ';
            noneDB\Tests\Support\Testing::setFaultHook(function ($p) { if ($p === ' . var_export($point, true) . ') { exit(0); } });
            $db->insert("m", [["n" => 3, "c" => "c1"], ["n" => 4, "c" => "c0"]]);
            echo "not reached";
        ');
    }

    /** @test */
    public function theSwitchInterruptedAtEveryStepOpensOneConsistentGeneration(): void
    {
        $expected = ['generation:prepared' => [1, false], 'generation:intent' => [1, false], 'generation:published' => [2, true], 'generation:cleaned' => [2, true]];
        foreach ($expected as $point => [$generation, $sharded]) {
            $this->cleanTestDirectory();
            $this->assertSame('', $this->interruptSwitchAt($point), $point);
            $state = $this->lockState('m');
            if ($point === 'generation:prepared') {
                $this->assertNotContains('g=1>2', $state['flags'], "$point: INTENT not written yet");
            } else {
                $this->assertContains('g=1>2', $state['flags'], "$point: INTENT written before PUBLISH (§5.2)");
            }
            \noneDB::clearStaticCache();
            $db = $this->db(['shardSize' => 4]);
            // The insert committed before the switch started: every record, once, in either layout
            $this->assertSame(range(0, 4), array_column($db->find('m', 0), 'n'), $point);
            $this->assertSame([1, 3], array_column($db->find('m', ['c' => 'c1']), 'n'), "$point: field index");
            $info = $db->getShardInfo('m');
            $this->assertSame($sharded, (bool)($info['sharded'] ?? false), "$point: layout of the committed generation");
            $this->assertNotContains('g=1>2', $this->lockState('m')['flags'], "$point: recovered, g cleared");
            if ($point !== 'generation:prepared') {
                $this->assertSame([$generation], $this->generations('m'), "$point: the other generation is removed");
            }
            // Still writable; a further switch (if still single) cleans an unreferenced leftover first
            $db->insert('m', ['n' => 5, 'c' => 'c1']);
            $this->assertSame(range(0, 5), array_column($db->find('m', 0), 'n'), $point);
            $this->assertSame([1, 3, 5], array_column($db->find('m', ['c' => 'c1']), 'n'), $point);
            $this->assertCount(1, $this->generations('m'), "$point: one generation in the end");
            $this->assertTrue($db->checkIntegrity('m')['ok'], $point);
        }
    }

    /**
     * @test STORAGE_FORMAT §5.2 recovery: an obsolete generation that can't be removed is logged as
     * generation-cleanup-failed; g is cleared (CURRENT decides) and checkIntegrity() reports what is left
     */
    public function anObsoleteGenerationThatCannotBeRemovedIsLoggedAndReported(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('no permissions that keep an entry from being removed');
        }
        $this->assertSame('', $this->interruptSwitchAt('generation:published'));
        $this->assertContains('g=1>2', $this->lockState('m')['flags']);
        $parent = $this->dbPath('m', 'home') . 'generations';
        chmod($parent, 0555); // generation 1's entry can't be removed
        try {
            \noneDB::clearStaticCache();
            $db = $this->db(['shardSize' => 4]);
            $this->assertSame(range(0, 4), array_column($db->find('m', 0), 'n'), 'generation 2 is committed');
        } finally {
            chmod($parent, 0750);
        }
        $this->assertNotContains('g=1>2', $this->lockState('m')['flags'], 'cleared: not retried by every operation');
        $this->assertSame([1, 2], $this->generations('m'));
        $actions = array_column($db->getRecoveryLog('m'), 'action');
        $this->assertContains('generation-cleanup-failed', $actions);
        $this->assertNotContains('generation-recovered', $actions);
        $report = $db->checkIntegrity('m');
        $this->assertFalse($report['ok']);
        $this->assertContains('generation', array_column($report['indexMismatches'], 'index'));
    }

    /** @test */
    public function anUnreferencedPreparedGenerationIsReportedNotRemoved(): void
    {
        $this->assertSame('', $this->interruptSwitchAt('generation:prepared'));
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 400]); // no switch on the next writes
        $this->assertSame([1, 2], $this->generations('m'), 'never removed without proof (§4.2/§5.2)');
        $report = $db->checkIntegrity('m');
        $this->assertFalse($report['ok']);
        $this->assertContains('generation', array_column($report['indexMismatches'], 'index'));
        $this->assertSame(range(0, 4), array_column($db->find('m', 0), 'n'));
    }

    /** @test */
    public function anInvalidCurrentDuringASwitchRemovesNothing(): void
    {
        $cases = [
            'empty' => function (array $current) { return ''; },
            'garbage' => function (array $current) { return "\0\0garbage"; },
            'checksum' => function (array $current) { return str_replace('"generation":2', '"generation":1', $this->sealed($current)) ; },
            'other database' => function (array $current) { $current['databaseId'] = '00000000-0000-4000-8000-000000000000'; return $this->sealed($current); },
            'other generation' => function (array $current) { $current['generation'] = 9; return $this->sealed($current); },
        ];
        foreach ($cases as $case => $make) {
            $this->cleanTestDirectory();
            $this->assertSame('', $this->interruptSwitchAt('generation:published'), $case);
            $raw = file_get_contents($this->dbPath('m', 'current'));
            $current = json_decode($raw, true);
            unset($current['checksum']);
            file_put_contents($this->dbPath('m', 'current'), $make($current));
            \noneDB::clearStaticCache();
            $before = $this->snapshot($this->testDbDir);
            $db = $this->db(['shardSize' => 4]);
            foreach (['find' => function () use ($db) { $db->find('m', 0); },
                      'insert' => function () use ($db) { $db->insert('m', ['n' => 9]); },
                      'count' => function () use ($db) { $db->count('m'); }] as $op => $fn) {
                $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code($fn), "$case: $op (§5.2 recovery step 4)");
            }
            $this->assertSame($before, $this->snapshot($this->testDbDir), "$case: both generations kept, nothing changed");
            $this->assertSame([1, 2], $this->generations('m'), $case);
        }
    }

    /** @test */
    public function invalidMetadataOfThePublishedGenerationIsDamage(): void
    {
        $this->assertSame('', $this->interruptSwitchAt('generation:published'));
        file_put_contents($this->dbPath('m', 'gen') . 'metadata', "{}\n"); // CURRENT names 2, its metadata is invalid
        \noneDB::clearStaticCache();
        $before = $this->snapshot($this->testDbDir);
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () { $this->db(['shardSize' => 4])->find('m', 0); }));
        $this->assertSame($before, $this->snapshot($this->testDbDir));
    }

    /** @test */
    public function anInvalidCurrentWithoutSwitchIsDamage(): void
    {
        $this->db()->insert('c', [['a' => 1]]);
        file_put_contents($this->dbPath('c', 'current'), 'x');
        \noneDB::clearStaticCache();
        $before = $this->snapshot($this->testDbDir);
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () { $this->db()->find('c', 0); }));
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () { $this->db()->insert('c', ['a' => 2]); }));
        $this->assertSame($before, $this->snapshot($this->testDbDir), 'fail closed, nothing removed');
    }

    /** @test */
    public function aProcessWithCachesOfTheOldGenerationReadsTheNewOne(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $db->insert('st', array_map(function ($n) { return ['n' => $n]; }, range(0, 2)));
        $go = $this->testDbDir . '../gen-go';
        $done = $this->testDbDir . '../gen-done';
        @unlink($go);
        @unlink($done);
        $proc = proc_open([PHP_BINARY, $this->childScript('
            $db = ' . $this->childDb(['shardSize' => 4]) . ';
            $first = array_column($db->find("st", 0), "n");          // caches of generation 1
            file_put_contents(' . var_export($done, true) . ', "1");
            $deadline = microtime(true) + 20;
            while (!is_file(' . var_export($go, true) . ') && microtime(true) < $deadline) { usleep(10000); }
            $second = array_column($db->find("st", 0), "n");         // after the switch to generation 2
            echo json_encode([$first, $second, $db->getShardInfo("st")["sharded"] ?? false]);
        ')], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $deadline = microtime(true) + 20;
        while (!is_file($done) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $db->insert('st', [['n' => 3], ['n' => 4]]); // switch to the sharded layout
        $this->assertSame([2], $this->generations('st'));
        file_put_contents($go, '1');
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($proc);
        @unlink($go);
        @unlink($done);
        $this->assertSame([[0, 1, 2], [0, 1, 2, 3, 4], true], json_decode($out, true), $out);
    }
}
