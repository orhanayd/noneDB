<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Operations: durability report and modes, integrity check, rename retry, and smaller storage
 * findings (getDurability, checkIntegrity, quarantine, rename retry)
 */
class OperationsToolsTest extends RegressionTestCase
{
    private function setStatic(string $name, $value): void
    {
        $property = new \noneDB\Tests\InternalValue($name);
        $property->setValue(null, $value);
    }

    protected function tearDown(): void
    {
        $this->setStatic('syncFn', null);
        $this->setStatic('renameFn', null);
        $this->setStatic('flushFn', null);
        $this->setStatic('osFamily', null);
        parent::tearDown();
    }

    /** @test DURABILITY.md §6: the report says what the last write needed and did, in each mode */
    public function durabilityReportDescribesWhatWasDone(): void
    {
        $this->needsDirectorySyncs();
        $db = $this->db();
        $report = $db->getDurability();
        $this->assertSame(['mode', 'platform', 'lastOperation', 'lastSyncError', 'syncs'], array_keys($report));
        $this->assertSame('performance', $report['mode'], 'the default');
        $db->insert('dur', ['v' => 1]);
        $op = $db->getDurability()['lastOperation'];
        $this->assertSame(['committed', [], []], [$op['outcome'], $op['required'], $op['completed']], 'performance mode requires no sync');

        $this->setStatic('osFamily', 'Linux'); // durable mode's rules, on every platform
        $db = $this->db(['durability' => 'durable']);
        $this->assertSame('durable', $db->getDurability()['mode']);

        $db->insert('dur', ['v' => 2]); // append to an existing file
        $op = $db->getDurability()['lastOperation'];
        $this->assertSame(['file'], $op['required']);
        $this->assertSame(['file'], $op['completed']);
        $this->assertSame('committed', $op['outcome']);

        $db->insert('dur2', ['v' => 1]); // creates files
        $op = $db->getDurability()['lastOperation'];
        $this->assertSame(['file', 'dir'], $op['required']);
        $this->assertSame(['file', 'dir'], $op['completed']);
        $this->assertSame('committed', $op['outcome']);
    }

    /** @test DURABILITY.md §3: a dbDir the first write creates is synced into its parent like any new directory */
    public function aDatabaseDirectoryCreatedByTheFirstWriteIsSynced(): void
    {
        $this->needsDirectorySyncs();
        // dbDir is used by its canonical path (STORAGE_FORMAT.md §3): the temp directory may be a link (macOS)
        $parent = realpath(sys_get_temp_dir()) . '/nonedb-dirsync-' . bin2hex(random_bytes(6));
        $dir = $parent . '/db/';
        mkdir($parent);
        $this->setStatic('osFamily', 'Linux');
        $dirs = [];
        $this->setStatic('syncFn', function ($handle, string $kind, $path) use (&$dirs) {
            if ($kind === 'dir') {
                $dirs[] = (string)$path;
            }
            return true;
        });
        try {
            $db = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $dir, 'durability' => 'durable']);
            $this->assertDirectoryDoesNotExist($dir);
            $db->insert('later', ['v' => 1]);
            $this->assertContains($parent, $dirs, 'the new dbDir\'s entry in its parent');
            $this->assertContains(rtrim($dir, '/'), $dirs, 'the store files and the database directory in dbDir');
            $op = $db->getDurability()['lastOperation'];
            $this->assertSame(['committed', ['file', 'dir'], ['file', 'dir']], [$op['outcome'], $op['required'], $op['completed']]);
        } finally {
            $this->setStatic('syncFn', null);
            \noneDB::clearStaticCache();
            $this->removeDirectoryTree($parent);
        }
    }

    /** @test DURABILITY.md §2: a failed sync fails the write (never success), and is never carried over */
    public function aFailedSyncIsReportedAndNeverCarriedOver(): void
    {
        $this->needsDirectorySyncs();
        $this->setStatic('osFamily', 'Linux');
        $db = $this->db(['durability' => 'durable']);
        $db->insert('sf', ['v' => 1]);
        $this->assertSame('committed', $db->getDurability()['lastOperation']['outcome']);

        // Directory syncs fail (some file systems answer EINVAL) on an operation that creates files
        $this->setStatic('syncFn', function ($handle, string $kind) {
            return $kind !== 'dir';
        });
        try {
            $db->insert('sf2', ['v' => 1]);
            $this->fail('a failed directory sync must fail the write');
        } catch (\RuntimeException $e) {
            $this->assertSame(\noneDB::DURABILITY_FAILED, $e->getCode(), $e->getMessage());
        }
        $report = $db->getDurability();
        $this->assertSame('rolled_back', $report['lastOperation']['outcome']);
        $this->assertSame('dir', $report['lastSyncError']['op']);
        $this->assertGreaterThan(0, $report['syncs']['failed']);

        // A failed file sync of an append: the write is refused and its bytes are undone
        $failed = 0;
        $this->setStatic('syncFn', function ($handle, string $kind) use (&$failed) {
            return !($kind === 'file' && ++$failed === 1);
        });
        try {
            $db->insert('sf', ['v' => 2]);
            $this->fail('a failed fsync of the data must fail the write');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('fsync', $e->getMessage());
            $this->assertSame(\noneDB::DURABILITY_FAILED, $e->getCode());
        }
        $this->setStatic('syncFn', null);
        $this->assertSame([1], array_column($this->db()->find('sf', 0), 'v'));
        $this->assertSame('rolled_back', $db->getDurability()['lastOperation']['outcome']);

        $db->insert('sf', ['v' => 3]);
        $op = $db->getDurability()['lastOperation'];
        $this->assertSame('committed', $op['outcome'], 'success again once syncs work');
        $this->assertSame($op['required'], $op['completed']);
    }

    private function nonEmptyFileSizes(string $name): array
    {
        clearstatcache();
        $sizes = [];
        $home = $this->dbPath($name, 'home');
        foreach ($this->dbFiles($name) as $file) {
            if (filesize($home . $file) > 0 && $file !== 'LOCK') {
                $sizes[$file] = filesize($home . $file);
            }
        }
        return $sizes;
    }

    /** @test */
    public function aFailedFlushFailsTheWriteAndIsUndone(): void
    {
        // fflush() of an append fails (disk full, I/O error): the write fails and its bytes are cut back,
        // with fsync off too
        foreach ([false, true] as $buffered) {
            $this->cleanTestDirectory();
            $config = ['shardingEnabled' => false, 'bufferEnabled' => $buffered];
            $db = $this->db($config);
            $db->insert('fl', ['v' => 'a']);
            if ($buffered) {
                $db->flush('fl');
            }
            $sizes = $this->nonEmptyFileSizes('fl');
            $this->assertArrayHasKey('generations/1/segments/main.jsonl', $sizes, 'set up');
            $this->setStatic('flushFn', function ($handle, string $path) { return false; });
            try {
                $db->insert('fl', ['v' => 'b']);
                $this->fail('a failed fflush() must fail the write (an I/O failure: RuntimeException)');
            } catch (\RuntimeException $e) {
                $this->assertNotSame(\noneDB::OUTCOME_UNKNOWN, $e->getCode(), $e->getMessage());
            }
            $this->assertSame('rolled_back', $db->getDurability()['lastOperation']['outcome']);
            $this->setStatic('flushFn', null);
            $this->assertSame($sizes, $this->nonEmptyFileSizes('fl'), 'nothing left behind (an append may leave an empty buffer file)');
            $this->assertSame(['a'], array_column($this->db($config)->find('fl', 0), 'v'));

            // The bytes can't be cut back (by the append, nor by the rollback): the outcome is reported as
            // unknown, never as a plain failure that leaves them to be read as a record
            $this->setStatic('flushFn', function ($handle, string $path) { return false; });
            $this->setFaultHook(function ($point) {
                if ($point === 'append:undo' || $point === 'rollback:truncate') {
                    throw new \RuntimeException('disk gone');
                }
            });
            try {
                $db->insert('fl', ['v' => 'c']);
                $this->fail('must throw');
            } catch (\RuntimeException $e) {
                $this->assertSame(\noneDB::OUTCOME_UNKNOWN, $e->getCode(), ($buffered ? 'buffered: ' : '') . $e->getMessage());
            }
            $this->setFaultHook(null);
            $this->setStatic('flushFn', null);
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            // Nothing was cut: the complete line is recovered (roll forward), the same for every reader
            $this->assertSame(['a', 'c'], array_column($db->find('fl', 0), 'v'));
            $db->insert('fl', ['v' => 'd']);
            $this->assertSame(['a', 'c', 'd'], array_column($db->find('fl', 0), 'v'));
        }
    }

    /** @test */
    public function quarantineRecordsSayWhetherTheyWouldSurviveAPowerLoss(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('pl', ['v' => 'a']);
        clearstatcache();
        $offset = filesize($this->dbPath('pl', 'data'));
        file_put_contents($this->dbPath('pl', 'data'), '{"torn', FILE_APPEND);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('pl', ['v' => 'b']);
        $log = $db->getRecoveryLog('pl');
        $this->assertCount(1, $log);
        $entry = $log[0];
        $this->assertSame('cut-tail', $entry['action']);
        $this->assertSame('{"torn', file_get_contents($this->dbPath('pl', 'quarantine') . $entry['quarantine']), 'the cut bytes are kept');
        // recovery/q.{seg}.{off}.{len}.{sha256}.bin (STORAGE_FORMAT.md §7.2)
        $this->assertSame('q.main.' . $offset . '.6.' . hash('sha256', '{"torn') . '.bin', $entry['quarantine']);
        $this->assertSame(['a', 'b'], array_column($db->find('pl', 0), 'v'));
        $this->assertTrue($entry['bytesVerified']);
        $this->assertSame(PHP_OS_FAMILY === 'Windows' ? 'unknown' : true, $entry['powerLossDurable']);
    }

    /** @test */
    public function integrityCheckReportsAndWritesNothing(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'bufferEnabled' => true]);
        $db->createFieldIndex('ic', 'c');
        $db->insert('ic', [['c' => 'x'], ['c' => 'y']]);
        $db->flush('ic');
        $clean = $db->checkIntegrity('ic');
        $this->assertTrue($clean['ok']);
        $this->assertSame(2, $clean['lines']);

        // A damaged line, an unterminated tail, a stale field index and records still buffered
        $data = $this->dbPath('ic', 'data');
        clearstatcache();
        $size = filesize($data);
        file_put_contents($data, "not json\n", FILE_APPEND);
        file_put_contents($data, '{"torn', FILE_APPEND);
        // The field index's header still fits the data, its buckets don't: "w" (no record has it), no "x"
        $fx = $this->dbPath('ic', 'fx', 'c');
        [$header, $buckets] = $this->readFieldIndexFile($fx);
        $buckets['sw'] = 1;
        unset($buckets['sx']);
        $this->writeFieldIndexFile($fx, $header, $buckets);
        $db->insert('ic', ['c' => 'z']); // buffered
        \noneDB::clearStaticCache();

        $snapshot = function () {
            clearstatcache();
            $files = [];
            $walk = function (string $dir) use (&$walk, &$files): void {
                foreach (scandir($dir) as $f) {
                    if ($f === '.' || $f === '..') {
                        continue;
                    }
                    if (is_dir($dir . $f)) {
                        $files[$dir . $f . '/'] = 'dir';
                        $walk($dir . $f . '/');
                    } elseif ($f[0] !== '.') {
                        // Docker Desktop's file sharing gives files new inode numbers by itself (seen with nothing
                        // run between two snapshots): there the content stands in for the inode
                        $files[$dir . $f] = [filesize($dir . $f), filemtime($dir . $f),
                            file_exists('/.dockerenv') ? sha1_file($dir . $f) : fileinode($dir . $f)];
                    }
                }
            };
            $walk(rtrim($this->testDbDir, '/') . '/');
            unset($files[$this->dbPath('ic', 'lock')]);
            return $files;
        };
        $before = $snapshot();
        $report = $this->db(['shardingEnabled' => false])->checkIntegrity('ic');
        $this->assertSame($before, $snapshot(), 'read-only: nothing created, changed, cut or flushed');
        $this->assertFalse($report['ok']);
        // Derived from the bytes written above: "not json\n" (9 bytes) at the old end, '{"torn' (6 bytes) after it
        $this->assertSame([['main', $size, 9]], $report['badLines']);
        $this->assertSame([['segment' => 'main', 'offset' => $size + 9, 'length' => 6]], $report['tornTail']);
        $this->assertSame([3, 2], [$report['lines'], $report['records']], 'complete lines: 2 records and the damaged one');
        $this->assertSame([['main', 'field c']], array_map(function ($m) { return [$m['segment'], $m['index']]; }, $report['indexMismatches']),
            'only the field index disagrees: the key index replays the data');
        $this->assertSame(1, $report['bufferedRecords']);
        $this->assertFalse($report['pendingRecovery']);
        $this->assertSame([], $report['quarantineFiles']);
        $this->assertSame([], $report['recoveryLog']);

        // rebuildIndex() repairs the index; the damaged line and the torn tail stay until a write / compaction
        $db = $this->db(['shardingEnabled' => false]);
        $db->rebuildIndex('ic');
        $after = $db->checkIntegrity('ic');
        $this->assertSame([], $after['indexMismatches']);
        $this->assertSame(0, $after['bufferedRecords'], 'rebuildIndex() flushed the buffer first');
        $this->assertSame(['x', 'y', 'z'], array_column($db->find('ic', 0), 'c'));
    }

    /** @test */
    public function anInterruptedSwitchToShardsRemovesTheOldFile(): void
    {
        // A-6: a switch to shards that died after its commit point left the old data forever (double size). The
        // commit point is PUBLISH: CURRENT names the new generation (STORAGE_FORMAT.md §5.2)
        $db = $this->db(['shardSize' => 4]);
        $db->insert('a6', array_map(function ($n) { return ['n' => $n]; }, range(0, 2)));
        $old = $this->dbPath('a6', 'data');
        $this->assertStringContainsString('/generations/1/', $old, 'set up');
        \noneDB::clearStaticCache();
        $this->runChild('
            $db = ' . $this->childDb(['shardSize' => 4]) . ';
            noneDB\Tests\Support\Testing::setFaultHook(function ($p) { if ($p === "generation:published") { exit(0); } });
            $db->insert("a6", ["n" => 3]);
        ');
        $this->assertFileExists($old, 'old file left by the crash');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 4]);
        $this->assertSame(range(0, 3), array_column($db->find('a6', 0), 'n'));
        $this->assertFileDoesNotExist($old, 'recovered: old file removed');
        $this->assertDirectoryDoesNotExist(dirname($old, 2), 'with its whole generation');
    }

    /** @test */
    public function flushAllBuffersFindsInterruptedFlushes(): void
    {
        // A-8: the file name pattern didn't match {buffer}.flushing.{key}
        $db = $this->db(['bufferEnabled' => true]);
        $db->insert('a8', ['v' => 1]);
        $db->flush('a8');
        $db->insert('a8', ['v' => 2]);
        $buffer = $this->dbPath('a8', 'buffer');
        rename($buffer, $this->dbPath('a8', 'flushing', 1));
        \noneDB::clearStaticCache();
        $result = $this->db()->flushAllBuffers();
        $this->assertSame(1, $result['databases']);
        $this->assertSame(1, $result['flushed']);
        $this->assertSame([], glob(dirname($buffer) . '/*'));
        $this->assertSame([[1, 0], [2, 1]], array_map(function ($r) { return [$r['v'], $r['key']]; }, $this->db()->find('a8', 0)));
    }

    /** @test */
    public function readsCreateNoDirectoryAndNoFile(): void
    {
        // A-11: find() on a database that doesn't exist must create neither dbDir nor any file in it (a store
        // is created by the first write, STORAGE_FORMAT.md §3.2)
        $dir = $this->testDbDir . 'not-yet/';
        $db = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $dir]);
        $this->assertSame([], $db->find('x', 0));
        $this->assertSame(0, $db->count('x'));
        $this->assertSame([], $db->query('x')->get());
        $this->assertFalse($db->exists('x', ['a' => 1]));
        $this->assertSame([], $db->getDBs());
        $this->assertDirectoryDoesNotExist($dir);
        $db->insert('x', ['a' => 1]);
        $this->assertDirectoryExists($dir, 'a write creates it');
        $this->assertCount(1, (new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $dir]))->find('x', 0));
        \noneDB::clearStaticCache(); // closes the kept lock handles
        $this->removeDirectoryTree($dir);
    }

    /** @test */
    public function distancesDoNotDependOnThePrecisionSetting(): void
    {
        // T2-8: the distance memo was keyed by (string) floats: other points at low precision shared a key
        $before = ini_get('precision');
        try {
            $db = $this->db();
            $db->insert('dist', [['loc' => ['type' => 'Point', 'coordinates' => [29.0, 41.0]]]]);
            ini_set('precision', '17');
            $a = $db->nearest('dist', 'loc', 29.0001, 41.0, 1)[0]['_distance'];
            ini_set('precision', '3');
            $b = $db->nearest('dist', 'loc', 29.0002, 41.0, 1)[0]['_distance'];
            ini_set('precision', '17');
            $this->assertEqualsWithDelta(8.42, $a, 0.1);
            $this->assertEqualsWithDelta(16.84, $b, 0.1, 'not the cached distance of the other point');
        } finally {
            ini_set('precision', (string)$before);
        }
    }

    /** @test */
    public function renameRetriesTransientFailures(): void
    {
        // S-6: on Windows a rename fails while another process has the target open; it is retried
        $calls = 0;
        $this->setStatic('renameFn', function ($from, $to) use (&$calls) {
            return ++$calls > 3 ? rename($from, $to) : false;
        });
        $db = $this->db();
        $db->insert('rr', ['v' => 1]);
        $this->assertGreaterThan(3, $calls);
        $this->assertSame([1], array_column($db->find('rr', 0), 'v'));

        // Gives up: an index must be written (inserts only append, the key index is a checkpoint)
        $this->setStatic('renameFn', function () { return false; });
        try {
            $db->createFieldIndex('rr', 'v');
            $this->fail('gives up');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('index', $e->getMessage());
        }
        $this->setStatic('renameFn', null);
        $temp = [];
        $walk = function (string $dir) use (&$walk, &$temp): void {
            foreach (scandir($dir) as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                if (is_dir($dir . $f)) {
                    $walk($dir . $f . '/');
                } elseif (strpos($f, '.tmp') !== false) {
                    $temp[] = $dir . $f;
                }
            }
        };
        $walk(rtrim($this->testDbDir, '/') . '/');
        $this->assertSame([], $temp, 'temp files removed');
    }
}
