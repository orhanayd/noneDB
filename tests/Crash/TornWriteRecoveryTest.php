<?php

namespace noneDB\Tests\Crash;

use noneDB\Tests\RegressionTestCase;

/**
 * K-NEW-01 and the crash windows around an append (plan A1, A3, A13, A17; STORAGE_FORMAT.md 6.1, 7.1, 7.2):
 * - complete lines the index doesn't know are applied (roll forward), on the very next access
 * - an unterminated tail is never committed; the next writer moves it to a quarantine file and cuts it
 *   off before appending, so no committed record is ever glued to it
 * - a complete line that does not decode (glued lines) is salvaged when an index is rebuilt
 * - bytes are never dropped silently: cut tails and damaged lines end up in verified quarantine files
 *   and in the recovery log
 */
class TornWriteRecoveryTest extends RegressionTestCase
{
    private function dataFile(string $name, ?int $shard = null): string
    {
        return $this->dbPath($name, 'data', $shard);
    }

    private function values(\noneDB $db, string $name, string $field = 'v'): array
    {
        return array_column($db->find($name, 0), $field);
    }

    private function freshDb(array $config = []): \noneDB
    {
        \noneDB::clearStaticCache();
        return $this->db($config);
    }

    /** recovery/q.{segment}.{offset}.{length}.{sha256}.bin (STORAGE_FORMAT.md 7.2) */
    private function quarantineFiles(string $name): array
    {
        return glob($this->dbPath($name, 'quarantine') . 'q.*.bin') ?: [];
    }

    /** Removes the key-index checkpoints of every database */
    private function deleteIndexes(): void
    {
        foreach (glob(rtrim($this->testDbDir, '/') . '/*.ndb4/generations/*/segments/*.kix') as $file) {
            unlink($file);
        }
    }

    /** @test */
    public function completeUnindexedLineIsVisibleAndItsKeyNotReused(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('roll', ['v' => 'initial']);
        file_put_contents($this->dataFile('roll'), "{\"v\":\"after-crash\",\"key\":1}\n", FILE_APPEND);

        $db = $this->freshDb(['shardingEnabled' => false]);
        $this->assertSame(['initial', 'after-crash'], $this->values($db, 'roll'));
        $db->insert('roll', ['v' => 'next']);
        $this->assertSame([0, 1, 2], array_column($db->find('roll', 0), 'key'), 'key 1 is not handed out again');
    }

    /** @test */
    public function unindexedTombstoneDeletesImmediately(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('tomb', [['v' => 'a'], ['v' => 'b']]);
        file_put_contents($this->dataFile('tomb'), "{\"__nonedb_deleted\":0}\n", FILE_APPEND);
        $this->assertSame(['b'], $this->values($this->freshDb(['shardingEnabled' => false]), 'tomb'));
    }

    /** @test */
    public function sameProcessWriterNoticesFileChangedBehindItsCache(): void
    {
        // Something that isn't noneDB appended a line: the generation didn't change, the cached index is
        // stale. The writer notices (the size its append finds), writes nothing and drops the index; the
        // next operation rebuilds it from the data (a torn tail alone is cut and the write goes on).
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('stale', ['v' => 'a']);
        file_put_contents($this->dataFile('stale'), "{\"v\":\"b\",\"key\":1}\n", FILE_APPEND);
        try {
            $db->insert('stale', ['v' => 'c']);
            $this->fail('the write must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('changed unexpectedly', $e->getMessage());
        }
        $db->insert('stale', ['v' => 'c']);
        $this->assertSame(['a', 'b', 'c'], $this->values($db, 'stale'));
        $this->assertSame([0, 1, 2], array_column($db->find('stale', 0), 'key'));
        $this->deleteIndexes();
        $this->assertSame(['a', 'b', 'c'], $this->values($this->freshDb(['shardingEnabled' => false]), 'stale'));
    }

    /** @test */
    public function tornTailNeverSwallowsTheNextInsert(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('torn', ['v' => 'first']);
        $fragment = '{"uncommitted":"fragment"';
        clearstatcache();
        $offset = filesize($this->dataFile('torn'));
        file_put_contents($this->dataFile('torn'), $fragment, FILE_APPEND);
        $db->insert('torn', ['v' => 'second']);

        $this->deleteIndexes();
        $this->assertSame(['first', 'second'], $this->values($this->freshDb(['shardingEnabled' => false]), 'torn'));

        // The cut bytes are kept, verified, and logged
        $files = $this->quarantineFiles('torn');
        $this->assertCount(1, $files);
        $this->assertSame($fragment, file_get_contents($files[0]));
        // recovery/q.{segment}.{offset}.{length}.{sha256}.bin, segment main = the single layout's data file
        $this->assertSame('q.main.' . $offset . '.' . strlen($fragment) . '.' . hash('sha256', $fragment) . '.bin', basename($files[0]));
        $log = $this->db()->getRecoveryLog('torn');
        $this->assertSame('cut-tail', $log[0]['action']);
        $this->assertTrue($log[0]['bytesVerified']);
        $this->assertSame(basename($files[0]), $log[0]['quarantine']);
        $this->assertSame(PHP_OS_FAMILY !== 'Windows' ? true : 'unknown', $log[0]['powerLossDurable']);
    }

    /** @test */
    public function completeJsonWithoutNewlineIsNotCommitted(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('tornfull', ['v' => 'first']);
        file_put_contents($this->dataFile('tornfull'), '{"v":"should-disappear","key":99}', FILE_APPEND);
        // A reader in a fresh process ignores the tail and leaves the file alone
        clearstatcache();
        $size = filesize($this->dataFile('tornfull'));
        $this->assertSame(['first'], $this->values($this->freshDb(['shardingEnabled' => false]), 'tornfull'));
        clearstatcache();
        $this->assertSame($size, filesize($this->dataFile('tornfull')), 'readers never cut');
        $this->assertSame([], $this->quarantineFiles('tornfull'));

        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('tornfull', ['v' => 'second']);
        $this->deleteIndexes();
        $db = $this->freshDb(['shardingEnabled' => false]);
        $this->assertSame(['first', 'second'], $this->values($db, 'tornfull'));
        $this->assertSame([], $db->find('tornfull', ['key' => 99]));
    }

    /** @test */
    public function tornTailOnAShardIsCutBeforeTheNextAppend(): void
    {
        $db = $this->db(['shardSize' => 5]);
        $db->insert('tsh', array_map(function ($n) { return ['v' => $n]; }, range(0, 6)));
        clearstatcache();
        $offset = filesize($this->dataFile('tsh', 1));
        file_put_contents($this->dataFile('tsh', 1), '{"v":"half', FILE_APPEND);
        $db = $this->freshDb(['shardSize' => 5]);
        $db->insert('tsh', ['v' => 7]);
        $this->deleteIndexes();
        $this->assertSame(range(0, 7), $this->values($this->freshDb(['shardSize' => 5]), 'tsh'));
        $this->assertSame(range(0, 7), array_column($this->freshDb(['shardSize' => 5])->find('tsh', 0), 'key'));
        $files = $this->quarantineFiles('tsh');
        $this->assertCount(1, $files);
        // the segment of shard N is sN
        $this->assertSame('q.s1.' . $offset . '.10.' . hash('sha256', '{"v":"half') . '.bin', basename($files[0]));
        $this->assertSame('{"v":"half', file_get_contents($files[0]));
    }

    /** @test */
    public function gluedLineIsSalvaged(): void
    {
        $name = 'glued';
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert($name, ['v' => 'first']);
        // A torn append followed by a committed one in the same line: the line does not decode as a whole,
        // the salvage rule finds the record at its end (STORAGE_FORMAT.md 6.1); no checkpoint covers it
        $data = $this->dataFile($name);
        $fragment = '{"frag":"' . str_repeat('{', 300) . 'x';
        $record = '{"v":"second","n":{"key":5},"key":1}';
        file_put_contents($data, $fragment . $record . "\n", FILE_APPEND);
        unlink($this->dbPath($name, 'kix'));
        \noneDB::clearStaticCache();
        $this->db(['shardingEnabled' => false])->rebuildIndex($name);

        $db = $this->freshDb(['shardingEnabled' => false]);
        $this->assertSame(['first', 'second'], $this->values($db, $name));
        $this->assertSame(['key' => 5], $db->find($name, ['key' => 1])[0]['n']);
        $log = $db->getRecoveryLog($name);
        $this->assertSame('salvaged', $log[0]['action']);
        $this->assertSame(1, $log[0]['key']);

        // Compaction keeps the record and moves the whole damaged line to quarantine
        $db->compact($name);
        $this->assertSame(['first', 'second'], $this->values($this->freshDb(['shardingEnabled' => false]), $name));
        $files = $this->quarantineFiles($name);
        $this->assertCount(1, $files);
        $this->assertSame($fragment . $record . "\n", file_get_contents($files[0]));
        $this->assertSame('drop-line', $this->db()->getRecoveryLog($name)[1]['action']);
    }

    /** @test */
    public function damagedLineEndingInAKeyDoesNotMakeTheDatabaseUnreadable(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('bad', ['v' => 'ok']);
        $data = $this->dataFile('bad');
        clearstatcache();
        $at = filesize($data);
        $broken = '{"a":[1,2,"key":5}';
        file_put_contents($data, $broken . "\n", FILE_APPEND);
        // A checkpoint that points at the damaged line (as if it had been written there); its crc "h" is the
        // one of the old end, so it doesn't fit the data and is repaired
        $ckpt = $this->dbPath('bad', 'kix');
        $file = $this->readKeyIndexFile($ckpt);
        $index = $file['header'];
        $entries = $file['entries'];
        $entries[5] = [$at, strlen($broken)];
        $index['n'] = 6;
        $index['s'] = $at + strlen($broken) + 1;
        $this->writeKeyIndexFile($ckpt, $index, $entries);

        $db = $this->freshDb(['shardingEnabled' => false]);
        $this->assertSame(['ok'], $this->values($db, 'bad'), 'forced repair classifies strictly, the read succeeds');
        $this->assertSame('skip-line', $db->getRecoveryLog('bad')[0]['action']);
        $db->insert('bad', ['v' => 'next']);
        // The repair keeps the key counter of the checkpoint it replaces (n = 6): the next key is 6
        $this->assertSame(6, $db->find('bad', ['v' => 'next'])[0]['key'], 'the index n is kept');
    }

    /** @test */
    public function checkpointBehindTheDataIsReplayedUnderTheJournal(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->createFieldIndex('leg', 'c');
        $db->insert('leg', ['c' => 'x', 'v' => 'a']);
        clearstatcache();
        $covered = filesize($this->dataFile('leg'));
        file_put_contents($this->dataFile('leg'), "{\"c\":\"x\",\"v\":\"b\",\"key\":1}\n", FILE_APPEND);
        // The key checkpoint covers at most the data before the new line
        $this->assertLessThanOrEqual($covered, $this->readKeyIndexFile($this->dbPath('leg', 'kix'))['header']['s']);
        // Journal entry as a crashed writer leaves it
        $state = $this->lockState('leg');
        $this->writeLockState('leg', ($state['gen'] + 1) . ':' . $state['epoch'] . ' j=main');

        $db = $this->freshDb(['shardingEnabled' => false]);
        $this->assertSame(['a', 'b'], $this->values($db, 'leg'));
        $this->assertSame(['a', 'b'], array_column($db->find('leg', ['c' => 'x']), 'v'), 'field index follows');
    }

    /** @test */
    public function secondaryIndexesFollowACheckpointBehindTheData(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->createFieldIndex('sec', 'c');
        $db->createSpatialIndex('sec', 'loc');
        $db->insert('sec', ['c' => 'x', 'loc' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        // A writer died between its data append and the end of its session: the key checkpoint and the
        // secondary indexes cover at most the data before the line
        clearstatcache();
        $this->assertLessThanOrEqual(filesize($this->dataFile('sec')), $this->readKeyIndexFile($this->dbPath('sec', 'kix'))['header']['s']);
        file_put_contents($this->dataFile('sec'),
            '{"c":"x","loc":{"type":"Point","coordinates":[2,2]},"key":1}' . "\n", FILE_APPEND);

        $db = $this->freshDb(['shardingEnabled' => false]);
        $this->assertSame([0, 1], array_column($db->find('sec', ['c' => 'x']), 'key'));
        $got = array_column($db->withinBBox('sec', 'loc', 0, 0, 3, 3), 'key');
        sort($got);
        $this->assertSame([0, 1], $got);
    }

    /** @test */
    public function unencodableRecordLeavesNoJournal(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->createFieldIndex('enc', 'c');
        $db->insert('enc', ['c' => 'x']);
        $gen = $this->lockState('enc')['gen'];
        // docs/API.md: rejected with an error result (no exception), nothing written
        $result = $db->insert('enc', ['c' => "\xB1\x31"]);
        $this->assertSame(0, $result['n']);
        $this->assertStringStartsWith('insert data cannot be encoded as JSON', $result['error']);
        $this->assertSame([], preg_grep('/^(j=|J$)/', $this->lockState('enc')['flags']));
        $this->assertSame($gen, $this->lockState('enc')['gen'], 'no file written');
        $this->assertSame([['c' => 'x', 'key' => 0]], $db->find('enc', 0));
    }

    /** @test */
    public function bufferTornTailDoesNotLoseRecords(): void
    {
        $db = $this->db(['bufferEnabled' => true]);
        $db->insert('btorn', ['v' => 'one']);
        $buffer = $this->dbPath('btorn', 'buffer');
        clearstatcache();
        $offset = filesize($buffer);
        file_put_contents($buffer, '{"v":"half', FILE_APPEND);
        $db = $this->freshDb(['bufferEnabled' => true]);
        $db->insert('btorn', ['v' => 'two']);
        $db->flush('btorn');
        $this->assertSame(['one', 'two'], $this->values($db, 'btorn'));
        $this->assertSame([0, 1], array_column($db->find('btorn', 0), 'key'));
        $this->assertCount(1, $this->quarantineFiles('btorn'));
        $this->assertSame('{"v":"half', file_get_contents($this->quarantineFiles('btorn')[0]));
        // segment buffer = a buffer file
        $this->assertSame('q.buffer.' . $offset . '.10.' . hash('sha256', '{"v":"half') . '.bin', basename($this->quarantineFiles('btorn')[0]));
        $this->assertSame(['cut-tail'], array_column($db->getRecoveryLog('btorn'), 'action'));
    }

    /** @test */
    public function flushedBufferWithDamagedLinesIsQuarantinedBeforeDeletion(): void
    {
        $db = $this->db(['bufferEnabled' => true]);
        $db->insert('bdmg', ['v' => 'one']);
        $buffer = $this->dbPath('bdmg', 'buffer');
        file_put_contents($buffer, "not json\n", FILE_APPEND);
        $content = file_get_contents($buffer);
        $db = $this->freshDb(['bufferEnabled' => true]);
        $this->assertSame(['success' => true, 'flushed' => 1, 'error' => null], $db->flush('bdmg'));
        $this->assertSame(['one'], $this->values($db, 'bdmg'));
        $files = $this->quarantineFiles('bdmg');
        $this->assertCount(1, $files);
        $this->assertSame($content, file_get_contents($files[0]));
        $this->assertSame([], glob($this->dbPath('bdmg', 'gen') . 'buffer/*'), 'the flushed buffer is deleted once its bytes are kept');
        $this->assertSame(['drop-buffer'], array_column($db->getRecoveryLog('bdmg'), 'action'));
    }

    /** @test */
    public function quarantineFailureRefusesTheWriteAndCutsNothing(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('qfail', ['v' => 'first']);
        file_put_contents($this->dataFile('qfail'), '{"half', FILE_APPEND);
        clearstatcache();
        $size = filesize($this->dataFile('qfail'));
        $this->setFaultHook(function ($point) {
            if ($point === 'quarantine:write') {
                throw new \RuntimeException('disk full');
            }
        });
        $db = $this->freshDb(['shardingEnabled' => false]);
        try {
            $db->insert('qfail', ['v' => 'second']);
            $this->fail('the write must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }
        $this->setFaultHook(null);
        clearstatcache();
        $this->assertSame($size, filesize($this->dataFile('qfail')), 'nothing cut, nothing appended');
        $this->assertSame(['first'], $this->values($db, 'qfail'), 'still readable');
        $db->insert('qfail', ['v' => 'second']);
        $this->assertSame(['first', 'second'], $this->values($this->freshDb(['shardingEnabled' => false]), 'qfail'));
    }

    /** @test */
    public function twoDistinctTornWritesAtSameOffsetAndLengthPreserveBothQuarantines(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('twice', ['v' => 'first']);
        $data = $this->dataFile('twice');
        clearstatcache();
        $size = filesize($data);
        file_put_contents($data, '{"aaaa', FILE_APPEND);
        $this->freshDb(['shardingEnabled' => false])->delete('twice', ['v' => 'nothing']); // no append: no cut
        // The next write cuts the tail, then fails: its own append is undone, the file is back at $size
        $this->setFaultHook(function ($point) {
            if ($point === 'append:after') {
                throw new \RuntimeException('injected');
            }
        });
        try {
            $this->freshDb(['shardingEnabled' => false])->insert('twice', ['v' => 'x']);
            $this->fail('must throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected', $e->getMessage());
        }
        $this->setFaultHook(null);
        clearstatcache();
        $this->assertSame($size, filesize($data));
        file_put_contents($data, '{"bbbb', FILE_APPEND);
        $db = $this->freshDb(['shardingEnabled' => false]);
        $db->insert('twice', ['v' => 'y']);
        $this->assertSame(['first', 'y'], $this->values($db, 'twice'));

        $files = $this->quarantineFiles('twice');
        $this->assertCount(2, $files, 'same segment, offset and length, different content');
        $contents = array_map('file_get_contents', $files);
        sort($contents);
        $this->assertSame(['{"aaaa', '{"bbbb'], $contents);
        $ids = array_column(array_filter($db->getRecoveryLog('twice'), function ($e) {
            return $e['action'] === 'cut-tail';
        }), 'quarantine');
        sort($ids);
        $names = array_map('basename', $files);
        sort($names);
        $this->assertSame($names, $ids);
    }

    /** @test */
    public function repeatingARecoveryReusesItsQuarantineFile(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('again', ['v' => 'first']);
        file_put_contents($this->dataFile('again'), '{"half', FILE_APPEND);
        // The quarantine file is written, then the process fails before cutting
        $this->setFaultHook(function ($point) {
            static $published = false;
            if ($point === 'append:after' && !$published) {
                $published = true;
                throw new \RuntimeException('crash after the cut');
            }
        });
        $db = $this->freshDb(['shardingEnabled' => false]);
        try {
            $db->insert('again', ['v' => 'second']);
        } catch (\RuntimeException $e) {
            $this->assertSame('crash after the cut', $e->getMessage());
        }
        $this->setFaultHook(null);
        // Put the same torn tail back and recover again
        file_put_contents($this->dataFile('again'), '{"half', FILE_APPEND);
        $this->freshDb(['shardingEnabled' => false])->insert('again', ['v' => 'second']);
        $this->assertCount(1, $this->quarantineFiles('again'));
        $this->assertCount(1, $this->db()->getRecoveryLog('again'), 'the same event is logged once');
        $this->assertSame(['first', 'second'], $this->values($this->freshDb(['shardingEnabled' => false]), 'again'));
    }

    /** @test */
    public function readerOfADamagedFileWritesNothing(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('ro', [['v' => 'a'], ['v' => 'b']]);
        // A damaged line after the checkpoint (replayed by readers) and an unterminated tail
        file_put_contents($this->dataFile('ro'), "garbage line\n", FILE_APPEND);
        file_put_contents($this->dataFile('ro'), '{"torn', FILE_APPEND);
        \noneDB::clearStaticCache();
        $snapshot = function () {
            clearstatcache();
            $files = [];
            $root = rtrim($this->testDbDir, '/') . '/';
            $walk = function (string $dir) use (&$walk, &$files, $root) {
                foreach (scandir($dir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $f = $dir . $entry;
                    if (is_dir($f)) {
                        $walk($f . '/');
                        continue;
                    }
                    // (Docker Desktop's file sharing renumbers inodes by itself: there the content stands in)
                    $files[substr($f, strlen($root))] = [filesize($f), filemtime($f), file_exists('/.dockerenv') ? sha1_file($f) : fileinode($f)];
                }
            };
            $walk($root);
            unset($files[substr($this->dbPath('ro', 'lock'), strlen($root))]);
            return $files;
        };
        $before = $snapshot();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame(['a', 'b'], $this->values($db, 'ro'));
        $this->assertSame(2, $db->count('ro'));
        $this->assertSame($before, $snapshot(), 'no file created, changed or cut by reads');
        // The damaged line is a diagnostic until a write session of this process logs it
        $log = $db->getRecoveryLog('ro');
        $this->assertSame(['skip-line'], array_column($log, 'action'));
        $this->assertFalse($log[0]['persisted']);
        $db->insert('ro', ['v' => 'c']);
        $log = $db->getRecoveryLog('ro');
        $this->assertSame(['skip-line', 'cut-tail'], array_column($log, 'action'));
        $this->assertArrayNotHasKey('persisted', $log[0]);
    }

    /** @test */
    public function fatalInsideADeleteThenShutdownWriteStaysConsistent(): void
    {
        // A-13: exit() skips finally blocks but runs shutdown functions, like a fatal error
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('fatal', [['v' => 'keep'], ['v' => 'gone'], ['v' => 'keep2']]);
        \noneDB::clearStaticCache();
        $out = $this->runChild('
            $db = ' . $this->childDb(['shardingEnabled' => false]) . ';
            $db->find("fatal", 0); // caches loaded, as in a long-running process
            register_shutdown_function(function () use ($db) {
                $r = $db->insert("fatal", ["v" => "from-shutdown"]);
                echo "shutdown insert n=", $r["n"], "\n";
            });
            noneDB\Tests\Support\Testing::setFaultHook(function ($point) {
                static $done = false;
                if ($point === "append:after" && !$done) { $done = true; exit(0); } // after the tombstone, before its index
            });
            $db->delete("fatal", ["v" => "gone"]);
            echo "not reached\n";
        ');
        $this->assertSame('shutdown insert n=1', $out);
        $db = $this->freshDb(['shardingEnabled' => false]);
        $this->assertSame(['keep', 'keep2', 'from-shutdown'], $this->values($db, 'fatal'));
        $this->deleteIndexes();
        $this->assertSame(['keep', 'keep2', 'from-shutdown'], $this->values($this->freshDb(['shardingEnabled' => false]), 'fatal'));
    }

    /**
     * @test §6.4 a header that announces more than its file holds is no checkpoint: it is not read, however large the
     * number. An integer overflow gave a TypeError, a body of terabytes would have been asked of fread()
     */
    public function aCheckpointThatAnnouncesAnImpossibleBodyIsNotRead(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('sz', array_map(function ($i) { return ['n' => $i]; }, range(0, 199)));
        $path = $this->dbPath('sz', 'kix');
        $intact = (string)file_get_contents($path);
        foreach ([PHP_INT_MAX, 1 << 62, 1 << 45, 1 << 40, 5000000000] as $slots) {
            $header = $this->openHeaderLine($path, rtrim(substr($intact, 0, 511)));
            $header['slots'] = $slots;
            file_put_contents($path, str_pad($this->sealHeaderLine(json_encode($header)), 511) . "\n" . substr($intact, 512));
            $out = $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false]) . ';'
                . ' echo count($db->find("sz", [])), " ", $db->find("sz", ["key" => 7])[0]["n"];');
            $this->assertSame('200 7', $out, "slots $slots");
        }
    }
}
