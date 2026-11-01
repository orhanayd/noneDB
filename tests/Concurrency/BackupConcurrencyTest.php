<?php

namespace noneDB\Tests\Concurrency;

use noneDB\Tests\RegressionTestCase;

/**
 * backup() with other processes (docs/STORAGE_FORMAT.md §10.2): the copy is the state of one moment while
 * writers run; maintenance can't run during it; a writer waits; a killed backup releases its locks.
 */
class BackupConcurrencyTest extends RegressionTestCase
{
    /** @var string[] */
    private $outside = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('proc_open') || !function_exists('posix_kill')) {
            $this->markTestSkipped('needs proc_open and POSIX signals');
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

    private function outsidePath(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/nonedb_' . $prefix . '_' . bin2hex(random_bytes(6));
        $this->outside[] = $path;
        return $path;
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

    /** Start a child without a shell (its pid is PHP's); [process, stdout pipe] */
    private function start(string $body): array
    {
        $proc = proc_open([PHP_BINARY, $this->childScript($body)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        return [$proc, $pipes[1], $pipes[2]];
    }

    private function finish(array $child): string
    {
        [$proc, $out, $err] = $child;
        $output = stream_get_contents($out) . stream_get_contents($err);
        fclose($out);
        fclose($err);
        proc_close($proc);
        return trim($output);
    }

    private function waitFor(string $file): void
    {
        $deadline = microtime(true) + 20;
        while (!is_file($file)) {
            $this->assertLessThan($deadline, microtime(true), "waiting for $file");
            usleep(10000);
        }
    }

    /** A child that backs up 'u' and waits at the first copied file until $release exists */
    private function pausedBackup(string $dir, string $paused, string $release, string $key = self::SECRET): array
    {
        $child = $this->start('
            $db = new \noneDB(["secretKey" => ' . var_export($key, true) . ', "dbDir" => ' . var_export($this->testDbDir, true) . ']);
            noneDB\Tests\Support\Testing::setFaultHook(function ($p) {
                static $done = false;
                if ($p === "backup:copied" && !$done) {
                    $done = true;
                    touch(' . var_export($paused, true) . ');
                    while (!is_file(' . var_export($release, true) . ')) { usleep(10000); }
                }
            });
            $db->backup("u", ' . var_export($dir, true) . ');
            echo "COMPLETED";
        ');
        $this->waitFor($paused);
        return $child;
    }

    /** @test the copy is the state of one moment: whole insert calls, a prefix of each writer's calls */
    public function aBackupTakenWhileWritersRunIsTheStateOfOneMoment(): void
    {
        $config = ['shardSize' => 50, 'lockTimeout' => 30];
        $db = $this->db($config);
        $db->insert('w', ['writer' => 0, 'batch' => 0, 'i' => 0]);
        $stop = $this->outsidePath('stop');
        $writers = [];
        for ($w = 1; $w <= 3; $w++) {
            $writers[] = $this->start('
                $db = ' . $this->childDb($config) . ';
                for ($b = 1; $b <= 400 && !is_file(' . var_export($stop, true) . '); $b++) {
                    $db->insert("w", array_map(function ($i) use ($b) { return ["writer" => ' . $w . ', "batch" => $b, "i" => $i]; }, range(0, 9)));
                }
                echo "DONE";
            ');
        }
        $backups = [];
        for ($n = 0; $n < 3; $n++) {
            usleep(150000);
            $dir = $this->outsidePath('backup');
            $db->backup('w', $dir);
            $backups[] = $dir;
        }
        touch($stop);
        foreach ($writers as $writer) {
            $this->assertSame('DONE', $this->finish($writer));
        }
        foreach ($backups as $n => $dir) {
            $copy = 'copy' . $n;
            $result = $db->restore($dir, $copy, ['force' => true]);
            $records = $db->find($copy, 0);
            $manifest = json_decode(file_get_contents("$dir/manifest.json"), true);
            $this->assertSame($manifest['records'], count($records), "backup $n: the counters of the same moment");
            $this->assertSame($result['records'], count($records));
            $groups = [];
            foreach ($records as $record) {
                $groups[$record['writer']][$record['batch']][] = $record['i'];
            }
            unset($groups[0]);
            foreach ($groups as $writer => $batches) {
                ksort($batches);
                $this->assertSame(range(1, count($batches)), array_keys($batches), "backup $n, writer $writer: a prefix of its inserts");
                foreach ($batches as $batch => $is) {
                    sort($is);
                    $this->assertSame(range(0, 9), $is, "backup $n, writer $writer, batch $batch: a whole insert");
                }
            }
            $this->assertTrue($db->checkIntegrity($copy)['ok'], "backup $n");
        }
        $this->assertGreaterThan(0, count($db->find('copy2', 0)) - 1, 'the writers ran while the backups were taken');
    }

    /** @test §10.2 step 2: maintenance fails with MAINTENANCE_BUSY while a backup holds STORE.LOCK */
    public function maintenanceIsBusyWhileABackupRuns(): void
    {
        $db = $this->db();
        $db->insert('u', array_map(function ($n) { return ['v' => $n]; }, range(0, 4)));
        $store = file_get_contents($this->testDbDir . 'STORE');
        $dir = $this->outsidePath('backup');
        $paused = $this->outsidePath('paused');
        $release = $this->outsidePath('release');
        $child = $this->pausedBackup($dir, $paused, $release);

        $register = \noneDB::registerKey($this->testDbDir, self::SECRET, 'another-registered-key-1');
        $this->assertSame([false, \noneDB::MAINTENANCE_BUSY], [$register['success'], $register['code']]);
        $rotate = \noneDB::migrateKey($this->testDbDir, self::SECRET, 'a-rotated-secret-key-22');
        $this->assertSame([false, \noneDB::MAINTENANCE_BUSY], [$rotate['success'], $rotate['code']]);
        touch($release);
        $this->assertSame('COMPLETED', $this->finish($child));
        $this->assertSame($store, file_get_contents($this->testDbDir . 'STORE'), 'nothing written by the busy maintenance');
        $this->assertSame($store, file_get_contents("$dir/store.json"), 'the STORE record of the locked moment');

        // A key registered before a backup is in its store.json
        $register = \noneDB::registerKey($this->testDbDir, self::SECRET, 'another-registered-key-1');
        $this->assertTrue($register['success'], (string)$register['error']);
        $second = $this->outsidePath('backup');
        $this->db()->backup('u', $second);
        $keys = json_decode(file_get_contents("$second/store.json"), true)['keys'];
        $this->assertContains($this->keyCheckOf('another-registered-key-1'), $keys);
        $this->assertContains($this->keyCheckOf(), $keys);

        // After a rotation the old key backs up nothing
        $rotate = \noneDB::migrateKey($this->testDbDir, self::SECRET, 'a-rotated-secret-key-22');
        $this->assertTrue($rotate['success'], (string)$rotate['error']);
        $third = $this->outsidePath('backup');
        $this->assertSame(\noneDB::KEY_NOT_REGISTERED, $this->code(function () use ($third) { $this->db()->backup('u', $third); }));
        $this->assertFileDoesNotExist($third);
        $rotated = new \noneDB(['secretKey' => 'a-rotated-secret-key-22', 'dbDir' => $this->testDbDir]);
        $this->assertSame(5, $rotated->backup('u', $third)['records']);
    }

    /** @test §10.2 step 2: writers of the database wait; readers and other databases are not affected */
    public function aWriterWaitsForABackup(): void
    {
        $db = $this->db();
        $db->insert('u', array_map(function ($n) { return ['v' => $n]; }, range(0, 4)));
        $db->insert('other', ['v' => 'o']);
        $dir = $this->outsidePath('backup');
        $paused = $this->outsidePath('paused');
        $release = $this->outsidePath('release');
        $child = $this->pausedBackup($dir, $paused, $release);

        \noneDB::clearStaticCache();
        $writer = $this->db(['lockTimeout' => 0.3]);
        $this->assertSame(\noneDB::LOCK_FAILED, $this->code(function () use ($writer) { $writer->insert('u', ['v' => 'late']); }));
        $this->assertSame(5, $writer->count('u'), 'a reader shares the lock');
        $writer->insert('other', ['v' => 'p']);
        $this->assertSame(2, $writer->count('other'), 'another database is not affected');
        touch($release);
        $this->assertSame('COMPLETED', $this->finish($child));
        $writer->insert('u', ['v' => 'late']);
        $this->assertSame(6, $writer->count('u'));
        $this->assertSame(5, json_decode(file_get_contents("$dir/manifest.json"), true)['records']);
    }

    /** @test a backup killed while it holds its locks releases them, and its copy is refused */
    public function aKilledBackupReleasesItsLocksAndIsRefused(): void
    {
        $db = $this->db();
        $db->insert('u', array_map(function ($n) { return ['v' => $n]; }, range(0, 4)));
        $dir = $this->outsidePath('backup');
        $paused = $this->outsidePath('paused');
        $release = $this->outsidePath('release');
        $child = $this->pausedBackup($dir, $paused, $release);
        $this->assertSame(\noneDB::MAINTENANCE_BUSY, \noneDB::registerKey($this->testDbDir, self::SECRET, 'another-registered-key-1')['code']);
        posix_kill(proc_get_status($child[0])['pid'], 9);
        $this->finish($child);

        $register = \noneDB::registerKey($this->testDbDir, self::SECRET, 'another-registered-key-1');
        $this->assertTrue($register['success'], (string)$register['error']);
        $this->assertFileDoesNotExist("$dir/manifest.json");
        $this->assertSame(\noneDB::RESTORE_REFUSED, $this->code(function () use ($dir) { $this->db()->restore($dir, 'u'); }));
        $this->assertSame(5, $this->db()->count('u'));
    }
}
