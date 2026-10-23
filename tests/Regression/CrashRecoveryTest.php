<?php

namespace noneDB\Tests\Regression;

/**
 * Real crashes: a writer process is killed with SIGKILL in the middle of its work. Afterwards every
 * acknowledged write must be there, nothing may be duplicated, field and spatial indexes must give
 * exactly what a full scan gives, and the database must keep working.
 * (K-01, K-03, K-08, Y-14, O-18, O-21 and the index journal)
 */
class CrashRecoveryTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        if (!function_exists('proc_open') || !defined('SIGKILL') && !function_exists('posix_kill') && stripos(PHP_OS, 'WIN') === 0) {
            $this->markTestSkipped('needs proc_open and POSIX signals');
        }
        parent::setUp();
    }

    private function crashOnce(string $name, array $config, int $killAfterAcks, int $seed): void
    {
        $db = $this->db($config);
        if (!$db->checkDB($name) || $db->count($name) === 0) {
            $db->insert($name, ['n' => -1, 'c' => 'seed', 'loc' => ['type' => 'Point', 'coordinates' => [0, 0]]]);
            $db->createFieldIndex($name, 'c');
            $db->createSpatialIndex($name, 'loc');
        }
        $before = [];
        foreach ($db->find($name, 0) as $r) {
            $before[$r['n']] = $r['c'];
        }

        // The writer announces every operation ("try ...") and acknowledges it once the call returned.
        // Only the single operation in flight at the moment of the kill may or may not have happened.
        $writer = $this->childScript('
            mt_srand(' . $seed . ');
            $db = ' . $this->childDb($config) . ';
            $own = [];
            for ($i = 0; ; $i++) {
                $n = ' . ($seed * 100000) . ' + $i;
                echo "try ins $n\n";
                $r = $db->insert(' . var_export($name, true) . ', ["n" => $n, "c" => "c" . ($n % 5), "loc" => ["type" => "Point", "coordinates" => [($n % 360) - 180 + 0.5, (($n * 7) % 170) - 85 + 0.5]]]);
                if ($r["n"] !== 1) { echo "ERR ", json_encode($r), "\n"; exit(1); }
                echo "ins $n\n";
                $own[] = $n;
                if ($i % 7 === 3) {
                    $target = $own[array_rand($own)];
                    echo "try upd $target\n";
                    $db->update(' . var_export($name, true) . ', [["n" => $target], ["set" => ["c" => "upd"]]]);
                    echo "upd $target\n";
                }
                if ($i % 11 === 5) {
                    $target = array_shift($own);
                    echo "try del $target\n";
                    $db->delete(' . var_export($name, true) . ', ["n" => $target]);
                    echo "del $target\n";
                }
            }');
        // Command as an array: no shell in between, so SIGKILL reaches the writer itself
        // (a "sh -c" that doesn't exec, like dash, would be killed and leave the writer running)
        $proc = proc_open([PHP_BINARY, $writer], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $state = [];      // n => 'inserted' | 'deleted'
        $updated = [];    // n => true (acknowledged update)
        $inFlight = null; // [op, n] announced but not acknowledged
        $lines = 0;
        $apply = function (string $line) use (&$state, &$updated, &$inFlight) {
            $line = trim($line);
            if ($line === '') {
                return;
            }
            $parts = explode(' ', $line);
            if ($parts[0] === 'ERR') {
                $this->fail('writer failed: ' . $line);
            }
            if ($parts[0] === 'try') {
                $inFlight = [$parts[1], (int)$parts[2]];
                return;
            }
            [$op, $n] = [$parts[0], (int)$parts[1]];
            if ($op === 'ins') { $state[$n] = 'inserted'; }
            if ($op === 'upd') { $updated[$n] = true; }
            if ($op === 'del') { $state[$n] = 'deleted'; }
            $inFlight = null;
        };
        while ($lines < $killAfterAcks && ($line = fgets($pipes[1])) !== false) {
            $apply($line);
            $lines++;
        }
        usleep(mt_rand(0, 3000));
        proc_terminate($proc, 9); // SIGKILL: no shutdown handlers, no destructors
        while (($line = fgets($pipes[1])) !== false) {
            if (substr($line, -1) === "\n") {
                $apply($line);
            }
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $this->assertSame('', trim((string)$stderr), 'writer printed no warnings or errors');

        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $all = $db->find($name, 0);
        $byN = [];
        foreach ($all as $r) {
            $this->assertArrayNotHasKey($r['n'], $byN, "record {$r['n']} duplicated");
            $byN[$r['n']] = $r;
        }
        $this->assertSame(count($all), count(array_unique(array_column($all, 'key'))), 'keys unique');

        foreach ($state as $n => $st) {
            $uncertain = $inFlight !== null && $inFlight[1] === $n;
            if ($st === 'inserted' && !($uncertain && $inFlight[0] === 'del')) {
                $this->assertArrayHasKey($n, $byN, "acknowledged insert $n survived the crash");
            }
            if ($st === 'deleted') {
                $this->assertArrayNotHasKey($n, $byN, "acknowledged delete of $n survived the crash");
            }
            if (isset($updated[$n]) && isset($byN[$n])) {
                $this->assertSame('upd', $byN[$n]['c'], "acknowledged update of $n survived the crash");
            }
        }
        foreach ($byN as $n => $r) {
            $known = isset($before[$n]) || isset($state[$n]) || ($inFlight !== null && $inFlight[0] === 'ins' && $inFlight[1] === $n);
            $this->assertTrue($known, "record $n appeared from nowhere");
        }

        // Field index == scan
        foreach (['c0', 'c1', 'c2', 'c3', 'c4', 'upd', 'seed'] as $c) {
            $scan = array_values(array_filter($all, function ($r) use ($c) { return $r['c'] === $c; }));
            $this->assertSame(array_column($scan, 'key'), array_column($db->find($name, ['c' => $c]), 'key'), "field index for c=$c");
        }
        // Spatial index == brute force
        foreach ([[-180, -90, 180, 90], [-10, -10, 40, 50], [100, -80, 179, 0]] as $box) {
            $expected = [];
            foreach ($all as $r) {
                [$x, $y] = $r['loc']['coordinates'];
                if ($x >= $box[0] && $x <= $box[2] && $y >= $box[1] && $y <= $box[3]) {
                    $expected[] = $r['key'];
                }
            }
            $got = array_column($db->withinBBox($name, 'loc', ...$box), 'key');
            sort($got);
            $this->assertSame($expected, $got, 'spatial index for box ' . json_encode($box));
        }
        $this->assertSame(count($all), $db->count($name), 'count() agrees with the records');
        $this->assertSame([], preg_grep('/^(j=|J$)/', $this->lockState($name)['flags']), 'journal cleared after recovery');
        $this->assertNotContains('b', $this->lockState($name)['flags'], 'buffer flag cleared after the replay');
        $this->assertSame([], glob($this->dbBasePath($name) . '*.flushing*'), 'interrupted flushes replayed');

        // Still writable
        $db->insert($name, ['n' => -2 - $seed, 'c' => 'after', 'loc' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        $this->assertCount(1, $db->find($name, ['c' => 'after', 'n' => -2 - $seed]));
    }
    /** @test */
    public function killedWriterNonSharded(): void
    {
        mt_srand(11);
        foreach ([5, 40, 120, 300, 77, 9, 450] as $i => $acks) {
            $this->crashOnce('crash', [], $acks, 10 + $i);
        }
    }

    /** @test */
    public function killedWriterShardedIncludingMigration(): void
    {
        mt_srand(12);
        foreach ([20, 60, 150, 250, 99, 33, 400] as $i => $acks) {
            $this->crashOnce('crashs', ['shardSize' => 40], $acks, 20 + $i);
        }
    }

    /** @test */
    public function killedWriterWithBuffer(): void
    {
        mt_srand(13);
        foreach ([15, 80, 200, 41, 333, 7] as $i => $acks) {
            $this->crashOnce('crashb', ['bufferEnabled' => true], $acks, 30 + $i);
        }
    }
}
