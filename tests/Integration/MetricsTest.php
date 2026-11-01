<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;
use noneDB\Tests\Support\Testing;

/**
 * getMetrics() (docs/OPERATIONS.md §1): the counters of the process count what they name, a database is
 * described from its metadata, and nothing is scanned or written. Process counters are shared by every test
 * of the run, so each test compares the counters before and after what it does.
 */
class MetricsTest extends RegressionTestCase
{
    private function process(\noneDB $db): array
    {
        return $db->getMetrics()['process'];
    }

    /** $after - $before for the numeric members of a group */
    private function delta(array $before, array $after, string $group): array
    {
        $out = [];
        foreach ($after[$group] as $name => $value) {
            if (is_int($value) || is_float($value)) {
                $out[$name] = $value - $before[$group][$name];
            }
        }
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

    private function code(callable $fn): ?int
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            return $e->getCode();
        }
        return null;
    }

    /** @test the shape of the result (§1.1, §1.2) */
    public function theResultHasTheDocumentedMembers(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $db->insert('u', [['v' => 1]]);
        $metrics = $db->getMetrics();
        $this->assertSame(['process'], array_keys($metrics));
        $process = $metrics['process'];
        $this->assertSame(['indexCache', 'indexReads', 'checkpoints', 'compactions', 'locks', 'recovery', 'durability', 'integrity', 'memory'], array_keys($process));
        $this->assertSame(['hits', 'misses', 'evictions', 'limit'], array_keys($process['indexCache']));
        $this->assertSame(['pages', 'bytes'], array_keys($process['indexReads']));
        $this->assertSame(['written', 'seconds'], array_keys($process['checkpoints']));
        $this->assertSame(['count', 'seconds'], array_keys($process['compactions']));
        $this->assertSame(['acquired', 'waited', 'waitSeconds', 'timeouts'], array_keys($process['locks']));
        $this->assertSame(['events', 'actions'], array_keys($process['recovery']));
        $this->assertSame(['mode', 'lastOperation', 'lastSyncError', 'syncs'], array_keys($process['durability']));
        $this->assertSame(['usage', 'peak'], array_keys($process['memory']));
        foreach (['hits', 'misses', 'evictions', 'limit'] as $name) {
            $this->assertIsInt($process['indexCache'][$name]);
        }
        $this->assertIsFloat($process['checkpoints']['seconds']);
        $this->assertIsFloat($process['locks']['waitSeconds']);
        $this->assertGreaterThan(0, $process['memory']['usage']);
        $this->assertGreaterThanOrEqual($process['memory']['usage'], $process['memory']['peak']);

        $database = $db->getMetrics('u')['database'];
        $this->assertSame(['name', 'exists', 'databaseId', 'generation', 'layout', 'shardSize', 'segments', 'records', 'nextKey',
            'bufferedRecords', 'fieldIndexes', 'spatialIndexes', 'bytes', 'state', 'integrity'], array_keys($database));
        $this->assertSame(['data', 'indexes', 'buffer', 'metadata', 'recovery', 'other', 'total'], array_keys($database['bytes']));
        $this->assertSame(['buffered', 'recoveryPending'], array_keys($database['state']));
    }

    /** @test §1.1 indexCache and indexReads */
    public function indexesReadFromDiskAreMissesAndThoseInMemoryHits(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('u', array_map(function ($n) { return ['n' => $n]; }, range(0, 499)));
        $this->assertTrue($db->rebuildIndex('u')['success']); // a checkpoint on disk
        $kix = filesize($this->dbPath('u', 'kix'));
        \noneDB::clearStaticCache(); // nothing cached
        $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => 0]);
        $before = $this->process($db);
        $this->assertSame([3], array_column($db->find('u', ['key' => 3]), 'n'));
        $after = $this->process($db);
        $this->assertSame(1, $this->delta($before, $after, 'indexCache')['misses'], 'the key index read from disk');
        $this->assertSame(['pages' => intdiv($kix + 4095, 4096), 'bytes' => $kix], $this->delta($before, $after, 'indexReads'), 'the checkpoint file');

        $before = $after;
        $db->find('u', ['key' => 4]);
        $after = $this->process($db);
        $delta = $this->delta($before, $after, 'indexCache');
        $this->assertSame(0, $delta['misses'], 'in memory now');
        $this->assertGreaterThanOrEqual(1, $delta['hits']);
        $this->assertSame(['pages' => 0, 'bytes' => 0], $this->delta($before, $after, 'indexReads'));
        $this->assertSame(0, $delta['evictions'], 'no limit');
        $this->assertSame(0, $after['indexCache']['limit']);

        // A limit below what the process uses: what an operation loaded is dropped after it, and read again
        Testing::set('cacheMemoryLimit', 1);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $before = $this->process($db);
        $db->find('u', ['key' => 5]);
        $db->find('u', ['key' => 6]);
        $after = $this->process($db);
        $delta = $this->delta($before, $after, 'indexCache');
        $this->assertGreaterThanOrEqual(2, $delta['evictions']);
        $this->assertGreaterThanOrEqual(1, $delta['misses'], 'evicted after the first find, read again by the second');
        $this->assertSame(1, $after['indexCache']['limit']);
    }

    /** @test §1.1 checkpoints and compactions */
    public function checkpointsAndCompactionsAreCountedAndTimed(): void
    {
        $db = $this->db(['shardSize' => 4, 'autoCompactThreshold' => 1000]);
        $db->insert('u', array_map(function ($n) { return ['n' => $n, 'c' => 'c' . ($n % 2)]; }, range(0, 11))); // shards 0..2
        $db->delete('u', ['c' => 'c0']);
        $before = $this->process($db);
        $this->assertTrue($db->compact('u')['success']);
        $after = $this->process($db);
        $compactions = $this->delta($before, $after, 'compactions');
        $this->assertSame(3, $compactions['count'], 'one per segment');
        $this->assertGreaterThan(0.0, $compactions['seconds']);
        $this->assertGreaterThanOrEqual(3, $this->delta($before, $after, 'checkpoints')['written'], 'a fresh checkpoint per compacted segment');

        $before = $after;
        $db->createFieldIndex('u', 'c');
        $after = $this->process($db);
        $checkpoints = $this->delta($before, $after, 'checkpoints');
        $this->assertGreaterThanOrEqual(4, $checkpoints['written'], 'three shard field indexes and the global one');
        $this->assertGreaterThan(0.0, $checkpoints['seconds']);
        $this->assertSame(0, $this->delta($before, $after, 'compactions')['count']);
    }

    /** @test §1.1 locks: waits and timeouts against another process */
    public function lockWaitsAndTimeoutsAreCounted(): void
    {
        $db = $this->db();
        $db->insert('u', [['v' => 1]]);
        $before = $this->process($db);
        $db->find('u', 0);
        $after = $this->process($db);
        $this->assertGreaterThanOrEqual(1, $this->delta($before, $after, 'locks')['acquired']);
        $this->assertSame(0, $this->delta($before, $after, 'locks')['waited']);

        foreach (['a wait' => [0.6, 5, null], 'a timeout' => [1.5, 0.2, \noneDB::LOCK_FAILED]] as $case => [$hold, $timeout, $code]) {
            $marker = sys_get_temp_dir() . '/nonedb_held_' . bin2hex(random_bytes(6));
            $proc = proc_open([PHP_BINARY, $this->childScript('
                $db = ' . $this->childDb() . ';
                noneDB\Tests\Support\Testing::call($db, "withLock", "u", true, function () {
                    touch(' . var_export($marker, true) . ');
                    usleep(' . (int)($hold * 1000000) . ');
                });
            ')], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $deadline = microtime(true) + 20;
            while (!is_file($marker) && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($marker, $case);
            \noneDB::clearStaticCache();
            $writer = $this->db(['lockTimeout' => $timeout]);
            $before = $this->process($writer);
            $this->assertSame($code, $this->code(function () use ($writer) { $writer->insert('u', ['v' => 2]); }), $case);
            $after = $this->process($writer);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            @unlink($marker);
            $locks = $this->delta($before, $after, 'locks');
            $this->assertSame(1, $locks['waited'], $case);
            $this->assertGreaterThanOrEqual($code === null ? 0.2 : $timeout, $locks['waitSeconds'], $case);
            $this->assertSame($code === null ? 0 : 1, $locks['timeouts'], $case);
        }
    }

    /** @test §1.1 recovery events by action */
    public function recoveryEventsAreCountedByAction(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('u', [['v' => 1]]);
        file_put_contents($this->dbPath('u', 'data'), '{"v":"torn","ke', FILE_APPEND); // a crashed append
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $before = $this->process($db);
        $db->insert('u', [['v' => 2]]); // cuts the torn tail into quarantine first
        $after = $this->process($db);
        $this->assertSame(1, $after['recovery']['events'] - $before['recovery']['events']);
        $this->assertSame(1, ($after['recovery']['actions']['cut-tail'] ?? 0) - ($before['recovery']['actions']['cut-tail'] ?? 0));
        $logged = array_column(array_map(function ($l) { return json_decode($l, true); }, $this->dataLines($this->dbPath('u', 'recovery'))), 'action');
        $this->assertSame(['cut-tail'], $logged, 'the event counted is the one logged');
    }

    /** @test §1.1 durability: getDurability()'s report */
    public function durabilityIsWhatGetDurabilityReports(): void
    {
        $db = $this->db();
        $db->insert('u', [['v' => 1]]);
        $durability = $db->getDurability();
        $this->assertSame(['mode' => $durability['mode'], 'lastOperation' => $durability['lastOperation'],
            'lastSyncError' => $durability['lastSyncError'], 'syncs' => $durability['syncs']], $this->process($db)['durability']);
    }

    /** @test §1.1, §1.2: the last checkIntegrity() of the process and of the database */
    public function theLastIntegrityCheckIsKept(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('u', [['v' => 1], ['v' => 2]]);
        $db->insert('w', [['v' => 1]]);
        $this->assertNull($db->getMetrics('w')['database']['integrity'], 'never checked');
        $t0 = time();
        $this->assertTrue($db->checkIntegrity('u')['ok']);
        $summary = $this->process($db)['integrity'];
        $this->assertSame(['database', 'ok', 'time', 'records', 'issues'], array_keys($summary));
        $this->assertSame(['u', true, 2, 0], [$summary['database'], $summary['ok'], $summary['records'], $summary['issues']]);
        $this->assertGreaterThanOrEqual($t0, $summary['time']);
        $this->assertSame($summary, $db->getMetrics('u')['database']['integrity']);
        $this->assertNull($db->getMetrics('w')['database']['integrity']);

        file_put_contents($this->dbPath('u', 'data'), "{not json}\n", FILE_APPEND); // a damaged line
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $report = $db->checkIntegrity('u');
        $this->assertFalse($report['ok']);
        $summary = $this->process($db)['integrity'];
        $this->assertSame(['u', false, 2], [$summary['database'], $summary['ok'], $summary['records']]);
        $this->assertSame(count($report['badLines']) + count($report['tornTail']) + count($report['indexMismatches']), $summary['issues']);
        $this->assertGreaterThanOrEqual(1, $summary['issues']);

        // A restore verifies its generation with the same check: that is no checkIntegrity() of the application
        $dir = sys_get_temp_dir() . '/nonedb_metrics_backup_' . bin2hex(random_bytes(6));
        try {
            $db->backup('w', $dir);
            $db->restore($dir, 'w');
            $this->assertNull($db->getMetrics('w')['database']['integrity'], 'w was never checked');
            $this->assertSame($summary, $this->process($db)['integrity'], 'the last check is still the one of u');
        } finally {
            $this->removeDirectoryTree($dir);
        }
    }

    /** @test §1.2: from the metadata, without writing anything */
    public function aDatabaseIsDescribedFromItsMetadata(): void
    {
        $config = ['shardSize' => 4, 'bufferEnabled' => true];
        $db = $this->db($config);
        $db->insert('u', array_map(function ($n) { return ['n' => $n, 'c' => 'c' . ($n % 2), 'loc' => ['type' => 'Point', 'coordinates' => [$n, $n]]]; }, range(0, 9)));
        $db->flush('u');
        $db->createFieldIndex('u', 'c');
        $db->createSpatialIndex('u', 'loc');
        $db->insert('u', [['n' => 10], ['n' => 11]]); // buffered
        $before = $this->snapshot($this->testDbDir);
        $database = $db->getMetrics('u')['database'];
        $this->assertSame($before, $this->snapshot($this->testDbDir), 'nothing written: the buffer is not flushed');

        $format = $this->openSealedRecord(file_get_contents($this->dbPath('u', 'format')), 'database');
        $current = json_decode(file_get_contents($this->dbPath('u', 'current')), true);
        $meta = json_decode(file_get_contents($this->dbPath('u', 'shards')), true);
        $this->assertSame(['u', true, $format['databaseId'], $current['generation'], 'sharded', 4],
            [$database['name'], $database['exists'], $database['databaseId'], $database['generation'], $database['layout'], $database['shardSize']]);
        $this->assertSame([count($meta['shards']), $meta['totalRecords'], $meta['nextKey'], 2],
            [$database['segments'], $database['records'], $database['nextKey'], $database['bufferedRecords']]);
        $this->assertSame([['c'], ['loc']], [$database['fieldIndexes'], $database['spatialIndexes']]);
        $this->assertSame(['buffered' => true, 'recoveryPending' => false], $database['state']);

        // The sizes, by the classes of §1.2, from an independent walk of the directory
        $home = $this->dbPath('u', 'home');
        $gen = 'generations/' . $current['generation'] . '/';
        $bytes = ['data' => 0, 'indexes' => 0, 'buffer' => 0, 'metadata' => 0, 'recovery' => 0, 'other' => 0];
        foreach (array_keys(array_filter($this->snapshot($home), function ($v) { return $v !== 'dir'; })) as $file) {
            if (strpos($file, $gen . 'segments/') === 0 && substr($file, -6) === '.jsonl') {
                $class = 'data';
            } elseif (preg_match('#^' . preg_quote($gen, '#') . '(segments/.*\.kix|indexes/.*\.(fx|gfx)|spatial/.*\.(rx|grx))$#', $file)) {
                $class = 'indexes';
            } elseif (strpos($file, $gen . 'buffer/') === 0) {
                $class = 'buffer';
            } elseif (in_array($file, ['FORMAT', 'CURRENT', 'LOCK', $gen . 'metadata', $gen . 'shards.json', $gen . 'indexes.json'], true)) {
                $class = 'metadata';
            } elseif (strpos($file, 'recovery/') === 0) {
                $class = 'recovery';
            } else {
                $class = 'other';
            }
            $bytes[$class] += filesize($home . $file);
        }
        $bytes['total'] = array_sum($bytes);
        $this->assertSame($bytes, $database['bytes']);
        $this->assertGreaterThan(0, $bytes['data']);
        $this->assertGreaterThan(0, $bytes['indexes']);
        $this->assertGreaterThan(0, $bytes['buffer']);

        // A leftover counts as other; a crashed writer's journal as pending recovery
        mkdir($home . 'generations/99');
        file_put_contents($home . 'generations/99/left.jsonl', 'xyz');
        $state = $this->lockState('u');
        $this->writeLockState('u', $state['gen'] . ':' . $state['epoch'] . ' b j=s0');
        $database = $this->db($config)->getMetrics('u')['database'];
        $this->assertSame($bytes['other'] + 3, $database['bytes']['other']);
        $this->assertSame(['buffered' => true, 'recoveryPending' => true], $database['state']);

        // The single layout
        $single = $this->db(['shardingEnabled' => false]);
        $single->insert('s', [['v' => 1], ['v' => 2], ['v' => 3]]);
        $single->delete('s', ['v' => 2]);
        unlink($this->dbPath('s', 'kix')); // a reader would write it again: getMetrics() does not
        \noneDB::clearStaticCache();
        $single = $this->db(['shardingEnabled' => false]);
        $before = $this->snapshot($this->testDbDir);
        $database = $single->getMetrics('s')['database'];
        $this->assertSame($before, $this->snapshot($this->testDbDir), 'nothing written: no checkpoint either');
        $this->assertSame(['single', 1, 2, 3, 0, [], []], [$database['layout'], $database['segments'], $database['records'],
            $database['nextKey'], $database['bufferedRecords'], $database['fieldIndexes'], $database['spatialIndexes']]);
    }

    /** @test §1.2: a missing database is reported, and nothing is created */
    public function aMissingDatabaseIsReportedAndNotCreated(): void
    {
        $db = $this->db();
        $db->insert('u', [['v' => 1]]);
        $before = $this->snapshot($this->testDbDir);
        $this->assertSame(['name' => 'missing', 'exists' => false], $db->getMetrics('missing')['database']);
        $this->assertSame($before, $this->snapshot($this->testDbDir));

        $location = sys_get_temp_dir() . '/nonedb_nostore_' . bin2hex(random_bytes(6));
        $other = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $location]);
        $this->assertSame(['name' => 'x', 'exists' => false], $other->getMetrics('x')['database']);
        $this->assertFileDoesNotExist($location);
    }

    /** @test §1.2: what checkIntegrity() can't read, getMetrics() can't either */
    public function aDamagedDatabaseFailsAsCheckIntegrityDoes(): void
    {
        $db = $this->db();
        $db->insert('u', [['v' => 1]]);
        file_put_contents($this->dbPath('u', 'current'), 'x');
        \noneDB::clearStaticCache();
        $db = $this->db();
        $before = $this->snapshot($this->testDbDir);
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () use ($db) { $db->checkIntegrity('u'); }));
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () use ($db) { $db->getMetrics('u'); }));
        $this->assertSame($before, $this->snapshot($this->testDbDir));
    }
}
