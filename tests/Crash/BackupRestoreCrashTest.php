<?php

namespace noneDB\Tests\Crash;

use noneDB\Tests\RegressionTestCase;

/**
 * backup() and restore() killed at every step (docs/STORAGE_FORMAT.md §10.2, §10.3 with §5.2 and §8): the
 * database opens as it was or as restored, never a mix, and a second run completes; an interrupted backup
 * has no manifest and is refused.
 */
class BackupRestoreCrashTest extends RegressionTestCase
{
    /** @var string[] */
    private $outside = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('posix_kill') || !function_exists('proc_open')) {
            $this->markTestSkipped('needs POSIX signals and proc_open');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->outside as $path) {
            $this->removeDirectoryTree($path);
        }
        $this->outside = [];
        parent::tearDown();
    }

    private function backupDir(): string
    {
        $dir = sys_get_temp_dir() . '/nonedb_backup_' . bin2hex(random_bytes(6));
        $this->outside[] = $dir;
        return $dir;
    }

    /** [v, key] of every record, by key */
    private function pairs(\noneDB $db, string $name): array
    {
        $records = $db->find($name, 0);
        usort($records, function ($a, $b) { return $a['key'] <=> $b['key']; });
        return array_map(function ($r) { return [$r['v'], $r['key']]; }, $records);
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
        $out = array_map('intval', array_values(array_filter(scandir($this->dbPath($db, 'home') . 'generations') ?: [], 'ctype_digit')));
        sort($out);
        return $out;
    }

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

    /** Run $call in a child (no shell) that is killed (SIGKILL) at the $nth occurrence of fault point $point */
    private function killedAt(string $call, string $point, int $nth, array $config): string
    {
        $script = $this->childScript('
            $db = ' . $this->childDb($config) . ';
            noneDB\Tests\Support\Testing::setFaultHook(function ($p) {
                static $seen = 0;
                if ($p === ' . var_export($point, true) . ' && ++$seen === ' . $nth . ') { posix_kill(getmypid(), 9); }
            });
            ' . $call . ';
            echo "COMPLETED";
        ');
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return trim($output);
    }

    private function seed(\noneDB $db, int $from, int $to): void
    {
        $db->insert('u', array_map(function ($n) { return ['v' => $n, 'c' => 'c' . ($n % 2)]; }, range($from, $to)));
    }

    /** @test §10.3 step 5, present database: §5.2 decides */
    public function aRestoreKilledAtEveryStepOpensTheOldOrTheRestoredDatabase(): void
    {
        $config = ['shardSize' => 4];
        $points = [['restore:copied', 1], ['restore:copied', 4], ['restore:verified', 1], ['generation:prepared', 1],
            ['generation:intent', 1], ['generation:published', 1], ['generation:cleaned', 1]];
        foreach ($points as [$point, $nth]) {
            $label = "$point #$nth";
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $this->seed($db, 0, 5);
            $db->createFieldIndex('u', 'c');
            $dir = $this->backupDir();
            $db->backup('u', $dir);
            $restored = $this->pairs($db, 'u');
            $this->seed($db, 6, 9);
            $db->delete('u', ['v' => 0]);
            $old = $this->pairs($db, 'u');
            \noneDB::clearStaticCache();

            $this->assertSame('', $this->killedAt('$db->restore(' . var_export($dir, true) . ', "u")', $point, $nth, $config), $label);
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $published = in_array($point, ['generation:published', 'generation:cleaned'], true);
            $expected = $published ? $restored : $old;
            $this->assertSame($expected, $this->pairs($db, 'u'), "$label: the old or the restored database, never a mix");
            $odd = array_values(array_filter($expected, function ($p) { return $p[0] % 2 === 1; }));
            $this->assertSame(array_column($odd, 1), array_column($db->find('u', ['c' => 'c1']), 'key'), "$label: its field index");
            $this->assertSame([], array_values(array_filter($this->lockState('u')['flags'], function ($f) { return $f[0] === 'g'; })), "$label: recovered");

            $result = $db->restore($dir, 'u');
            $this->assertSame(10, $result['nextKey'], "$label: keys 0..9 were handed out");
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->assertSame($restored, $this->pairs($db, 'u'), "$label: a second run completes");
            $this->assertCount(1, $this->generations('u'), $label);
            $this->assertTrue($db->checkIntegrity('u')['ok'], $label);
        }
    }

    /** @test §10.3 step 5, absent database: §8.3 removes an interrupted creation */
    public function aRestoreIntoAnAbsentDatabaseKilledAtEveryStepLeavesItAbsentOrRestored(): void
    {
        $config = ['shardSize' => 4];
        $points = [['create:flagged', 1], ['restore:copied', 1], ['restore:verified', 1], ['create:generation', 1],
            ['create:current', 1], ['create:format', 1]];
        foreach ($points as [$point, $nth]) {
            $label = "$point #$nth";
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $this->seed($db, 0, 5);
            $dir = $this->backupDir();
            $db->backup('u', $dir);
            $restored = $this->pairs($db, 'u');
            $this->assertTrue($db->dropDB('u')['success']);
            \noneDB::clearStaticCache();

            $this->assertSame('', $this->killedAt('$db->restore(' . var_export($dir, true) . ', "u")', $point, $nth, $config), $label);
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            if ($point === 'create:format') {
                $this->assertSame($restored, $this->pairs($db, 'u'), "$label: FORMAT is the commit point");
                $this->assertSame(['u'], $db->getDBs(), $label);
            } else {
                $this->assertSame([], $db->find('u', 0), "$label: absent");
                $this->assertSame([], $db->getDBs(), $label);
                $this->assertSame(['LOCK'], $this->dbFiles('u'), "$label: the interrupted creation is removed");
            }
            $this->assertSame([], $this->lockState('u')['flags'], "$label: no c left");

            $db->restore($dir, 'u');
            $this->assertSame($restored, $this->pairs($this->db($config), 'u'), "$label: a second run completes");
        }
    }

    /** @test §10.3 step 5, damaged database: damaged until CURRENT names X */
    public function aRestoreOverADamagedDatabaseKilledAtEveryStep(): void
    {
        $config = ['shardingEnabled' => false];
        $points = [['restore:copied', 1], ['restore:verified', 1], ['restore:intent', 1], ['restore:published', 1]];
        foreach (['FORMAT valid' => false, 'FORMAT invalid' => true] as $case => $formatToo) {
            foreach ($points as [$point, $nth]) {
                $label = "$case, $point #$nth";
                $this->cleanTestDirectory();
                $db = $this->db($config);
                $db->insert('u', [['v' => 0], ['v' => 1], ['v' => 2]]);
                $dir = $this->backupDir();
                $db->backup('u', $dir);
                $restored = $this->pairs($db, 'u');
                $db->insert('u', [['v' => 3], ['v' => 4]]);
                file_put_contents($this->dbPath('u', 'current'), 'x');
                if ($formatToo) {
                    file_put_contents($this->dbPath('u', 'format'), 'x');
                }
                \noneDB::clearStaticCache();

                $this->assertSame('', $this->killedAt('$db->restore(' . var_export($dir, true) . ', "u")', $point, $nth, $config), $label);
                \noneDB::clearStaticCache();
                $db = $this->db($config);
                if ($point === 'restore:published') {
                    $this->assertSame($restored, $this->pairs($db, 'u'), "$label: CURRENT names X: the commit point");
                    if ($formatToo) {
                        $this->assertContains('format-repaired', array_column(array_map(function ($l) { return json_decode($l, true); },
                            $this->dataLines($this->dbPath('u', 'recovery'))), 'action'), "$label: FORMAT repaired from X's metadata (§8.3)");
                    }
                    $expectedTarget = 'present'; // the second run finds a present database
                } else {
                    $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () use ($db) { $db->find('u', 0); }), "$label: still damaged");
                    $this->assertContains(1, $this->generations('u'), "$label: the damaged generation is kept");
                    $expectedTarget = 'damaged';
                }
                $result = $db->restore($dir, 'u');
                $this->assertSame($expectedTarget, $result['target'], $label);
                $this->assertSame(5, $result['nextKey'], "$label: keys 0..4 were handed out");
                $this->assertSame($restored, $this->pairs($this->db($config), 'u'), "$label: a second run completes");
                $this->assertContains(1, $this->generations('u'), "$label: never removed without proof");
            }
        }
    }

    /** @test §10.2: the manifest is written last */
    public function anInterruptedBackupHasNoManifestAndIsRefused(): void
    {
        foreach ([['backup:copied', 1], ['backup:copied', 3], ['backup:verified', 1]] as [$point, $nth]) {
            $label = "$point #$nth";
            $this->cleanTestDirectory();
            $db = $this->db(['shardSize' => 4]);
            $this->seed($db, 0, 5);
            $db->find('u', 0);
            $dir = $this->backupDir();
            \noneDB::clearStaticCache();
            $before = $this->snapshot($this->testDbDir);
            $this->assertSame('', $this->killedAt('$db->backup("u", ' . var_export($dir, true) . ')', $point, $nth, ['shardSize' => 4]), $label);
            $this->assertSame($before, $this->snapshot($this->testDbDir), "$label: the database is untouched");
            $this->assertFileDoesNotExist("$dir/manifest.json", $label);
            $db = $this->db(['shardSize' => 4]);
            $this->assertSame(\noneDB::RESTORE_REFUSED, $this->code(function () use ($db, $dir) { $db->restore($dir, 'u'); }), $label);
            $this->assertSame($before, $this->snapshot($this->testDbDir), "$label: nothing restored");
            // The killed backup's locks went with it
            $db->insert('u', ['v' => 6, 'c' => 'c0']);
            $this->assertSame(7, $db->count('u'), $label);
        }
    }
}
