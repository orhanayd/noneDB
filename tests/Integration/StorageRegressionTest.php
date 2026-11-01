<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Storage engine: K-02, K-03, K-05, K-06, Y-09, Y-10, Y-14, Y-16, O-21, O-22, O-23, O-35, D-10
 */
class StorageRegressionTest extends RegressionTestCase
{
    /** @test K-02 */
    public function recordWhoseFirstFieldIsDataSurvivesNewProcess(): void
    {
        $this->db()->insert('p', ['data' => [['title' => 'A'], ['title' => 'B']], 'owner' => 'alice']);
        $this->db()->insert('p', ['data' => 'v1', 'x' => 1]);
        $out = $this->runChild('echo json_encode((' . $this->childDb() . ')->find("p", 0));');
        $this->assertSame(
            '[{"data":[{"title":"A"},{"title":"B"}],"owner":"alice","key":0},{"data":"v1","x":1,"key":1}]',
            $out
        );
    }

    /** @test K-03 */
    public function unencodableDataIsRejectedWithoutTouchingFiles(): void
    {
        $db = $this->db();
        $db->insert('c', ['city' => 'Istanbul']);
        $db->createFieldIndex('c', 'city');
        $home = $this->dbPath('c', 'home');
        $files = $this->dbFiles('c');
        $md5 = function (array $files) use ($home) {
            return array_map(function ($f) use ($home) { return md5_file($home . $f); }, $files);
        };
        $before = $md5($files);

        $r1 = $db->insert('c', ['city' => "Bad\xB1Latin1"]);
        $r2 = $db->insert('c', ['v' => NAN]);
        $r3 = $db->update('c', [['city' => 'Istanbul'], ['set' => ['city' => "x\xB1"]]]);
        // docs/API.md: "insert data cannot be encoded as JSON ..." / "update data cannot be encoded as JSON ..."
        foreach (['insert' => $r1, 'insert NAN' => $r2, 'update' => $r3] as $what => $r) {
            $this->assertSame(['n', 'error'], array_keys($r), $what);
            $this->assertSame(0, $r['n'], $what);
            $this->assertStringStartsWith(strtok($what, ' ') . ' data cannot be encoded as JSON', $r['error'], $what);
        }
        clearstatcache();
        $this->assertSame($before, $md5($files), 'no file changed');
        $this->assertSame($files, $this->dbFiles('c'), 'no file created');

        \noneDB::clearStaticCache();
        $this->assertSame([['city' => 'Istanbul', 'key' => 0]], $this->db()->find('c', ['city' => 'Istanbul']));
        $this->assertSame([['city' => 'Istanbul', 'key' => 0]], $this->db()->find('c', ['key' => 0]));
        $this->assertTrue($this->db()->compact('c')['success']);
    }

    /** @test Y-14 */
    public function missingOrCorruptIndexIsRebuiltFromData(): void
    {
        $db = $this->db();
        $db->insert('r', [['n' => 1], ['n' => 2], ['n' => 3], ['n' => 4]]);
        $db->delete('r', ['n' => 2]);
        $db->update('r', [['n' => 3], ['set' => ['n' => 33]]]);
        $expected = $this->plain($db->find('r', 0));

        $original = (string)file_get_contents($this->dbPath('r', 'kix'));
        foreach (['missing', 'corrupt', 'truncated'] as $mode) {
            $idx = $this->dbPath('r', 'kix');
            if ($mode === 'missing') {
                unlink($idx);
            } elseif ($mode === 'corrupt') {
                file_put_contents($idx, substr($original, 0, 520)); // its header, then a cut body
            } else {
                file_put_contents($idx, '');
            }
            \noneDB::clearStaticCache();
            $this->assertSame($expected, $this->plain($this->db()->find('r', 0)), "$mode index: deleted record stays deleted, update kept");
            $this->assertSame(3, $this->db()->count('r'));
        }
        $this->db()->insert('r', ['n' => 5]);
        $this->assertSame(4, $this->db()->find('r', ['n' => 5])[0]['key'], 'key counter survives a rebuild');
    }

    /** @test Y-14 */
    public function tornLastLineIsCutOff(): void
    {
        $db = $this->db();
        $db->insert('t', [['n' => 1], ['n' => 2]]);
        $data = $this->dbPath('t', 'data');
        clearstatcache();
        $size = filesize($data);
        file_put_contents($data, '{"n":3,"ke', FILE_APPEND); // crash in the middle of an append
        unlink($this->dbPath('t', 'kix'));
        \noneDB::clearStaticCache();
        $this->assertSame([['n' => 1], ['n' => 2]], $this->plain($this->db()->find('t', 0)));
        $this->db()->insert('t', ['n' => 3]);
        $this->assertSame([['n' => 1], ['n' => 2], ['n' => 3]], $this->plain($this->db()->find('t', 0)));
        $this->assertSame([0, 1, 2], array_column($this->db()->find('t', 0), 'key'), 'the torn record never got a key');
        // The cut bytes are kept in recovery/q.main.{offset}.{length}.{sha256}.bin (STORAGE_FORMAT.md 7.2)
        $quarantine = $this->dbPath('t', 'quarantine') . 'q.main.' . $size . '.10.' . hash('sha256', '{"n":3,"ke') . '.bin';
        $this->assertFileExists($quarantine);
        $this->assertSame('{"n":3,"ke', file_get_contents($quarantine));
    }

    /** @test Y-14 O-21 */
    public function crashBetweenCompactionRenamesIsDetected(): void
    {
        $db = $this->db();
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['n' => $i];
        }
        $db->insert('k', $rows);
        $db->delete('k', ['n' => 3]);
        $oldIndex = file_get_contents($this->dbPath('k', 'kix'));
        $db->compact('k');
        // Simulate: new data file renamed into place, old checkpoint still on disk
        file_put_contents($this->dbPath('k', 'kix'), $oldIndex);
        \noneDB::clearStaticCache();
        $all = $this->db()->find('k', 0);
        $expected = array_values(array_diff(range(0, 19), [3]));
        $this->assertSame($expected, array_column($all, 'n'));
        $this->assertSame($expected, array_column($all, 'key'), 'keys unchanged');
        $this->assertSame(19, $this->db()->find('k', ['n' => 19])[0]['n']);
        $this->assertSame([], $this->db()->find('k', ['n' => 3]));
    }

    /** @test K-05 O-35 */
    public function shardedCompactKeepsKeysAndIndexes(): void
    {
        $db = $this->db(['shardSize' => 5]);
        $rows = [];
        for ($i = 0; $i < 15; $i++) {
            $rows[] = ['i' => $i, 'city' => $i % 2 ? 'A' : 'B'];
        }
        $db->insert('s', $rows);
        $db->createFieldIndex('s', 'city');
        for ($i = 0; $i < 10; $i++) {
            $db->delete('s', ['i' => $i]);
        }
        $r = $db->compact('s');
        $this->assertTrue($r['success']);
        $this->assertSame(5, $r['totalRecords']);
        $this->assertSame(1, $db->getShardInfo('s')['shards'], 'empty shards are dropped');
        $this->assertSame(12, $db->find('s', ['key' => 12])[0]['i'], 'keys did not move');
        $this->assertSame([10, 12, 14], array_column($db->find('s', ['city' => 'B']), 'i'));
        $db->insert('s', ['i' => 15, 'city' => 'B']);
        $this->assertSame(15, $db->find('s', ['i' => 15])[0]['key']);
    }

    /** @test K-05 */
    public function shardedCompactFlushesBuffersFirst(): void
    {
        $db = $this->db(['shardSize' => 5, 'bufferEnabled' => true]);
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['i' => $i];
        }
        $db->insert('sb', $rows);
        $db->flush('sb');
        $db->insert('sb', ['i' => 12]);
        $db->insert('sb', ['i' => 13]);
        $db->compact('sb');
        $this->assertSame(14, $db->count('sb'));
        $this->assertSame([], glob($this->dbPath('sb', 'gen') . 'buffer/*'));
        $this->assertSame(range(0, 13), array_column($db->find('sb', 0), 'i'));
        $this->assertSame(range(0, 13), array_column($db->find('sb', 0), 'key'), 'buffered records got the next keys, in order');
    }

    /** @test O-15 a buffered database switches to shards when its flush crosses shardSize */
    public function bufferedDatabaseShardsWhenFlushed(): void
    {
        $db = $this->db(['shardSize' => 10, 'bufferEnabled' => true]);
        for ($i = 0; $i < 25; $i++) {
            $db->insert('bs', ['i' => $i]);
        }
        $this->assertFileDoesNotExist($this->dbPath('bs', 'shards'), 'still buffered');
        $this->assertSame(range(0, 24), array_column($db->find('bs', 0), 'i'), 'the read flushes');
        $this->assertFileExists($this->dbPath('bs', 'shards'), 'and the flush switched it to shards');
        $this->assertSame(range(0, 24), array_column($db->find('bs', 0), 'key'));
        $this->assertSame(25, $db->count('bs'));
        $this->assertSame(3, $db->getShardInfo('bs')['shards']);
    }

    /** @test O-16 convertToSharded() takes buffered records along */
    public function convertToShardedFlushesTheBufferFirst(): void
    {
        $db = $this->db(['bufferEnabled' => true, 'shardingEnabled' => false]);
        $db->insert('mb', ['i' => 0]);
        $db->flush('mb');
        $db->insert('mb', [['i' => 1], ['i' => 2]]);
        $this->assertSame('converted', $db->convertToSharded('mb')['status']);
        $this->assertSame([], glob($this->dbPath('mb', 'gen') . 'buffer/*'));
        $this->assertSame([0, 1, 2], array_column($this->db()->find('mb', 0), 'i'));
        $this->assertSame(3, $this->db()->count('mb'));
    }

    /** @test K-06 D-10 */
    public function theSwitchToShardsNeverReusesKeys(): void
    {
        $db = $this->db(['shardSize' => 10, 'shardingEnabled' => false]);
        $rows = [];
        for ($i = 0; $i < 15; $i++) {
            $rows[] = ['i' => $i];
        }
        $db->insert('mg', $rows);
        $db->delete('mg', ['i' => 3]);
        $db->delete('mg', ['i' => 13]);
        $db->delete('mg', ['i' => 14]); // trailing keys deleted too
        $this->assertSame('converted', $db->convertToSharded('mg')['status']);
        // The single layout's generation is removed by the switch (STORAGE_FORMAT.md 5.2 CLEANUP), no copy stays
        $this->assertFalse(file_exists($this->dbPath('mg', 'data')), 'non-sharded file removed');
        $this->assertSame([rtrim($this->dbPath('mg', 'gen'), '/')], glob($this->dbPath('mg', 'home') . 'generations/*'), 'only the active generation is left');

        $db->insert('mg', ['i' => 'NEW']);
        $new = $db->find('mg', ['i' => 'NEW'])[0];
        $this->assertSame(15, $new['key'], 'next key continues after the highest ever used');
        $this->assertSame(12, $db->find('mg', ['key' => 12])[0]['i'], 'existing record untouched');
        $this->assertSame(13, $db->count('mg'));
    }

    /** @test Y-09 */
    public function shardedKeyLookupSeesNewRecords(): void
    {
        $db = $this->db(['shardSize' => 5]);
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['i' => $i];
        }
        $db->insert('sk', $rows);
        $this->assertSame(3, $db->find('sk', ['key' => 3])[0]['i']);
        $db->insert('sk', ['i' => 12]);
        $db->insert('sk', ['i' => 13]);
        $this->assertSame(13, $db->find('sk', ['key' => 13])[0]['i']);
        $this->assertSame(13, $db->query('sk')->where(['key' => 13])->first()['i']);
        \noneDB::clearStaticCache();
        $this->assertSame(12, $this->db()->find('sk', ['key' => 12])[0]['i']);
    }

    /** @test Y-10 */
    public function reopeningWithAnotherShardSizeKeepsKeys(): void
    {
        $a = $this->db(['shardSize' => 5]);
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['i' => $i];
        }
        $a->insert('rs', $rows);
        \noneDB::clearStaticCache();
        $b = $this->db(['shardSize' => 10000]);
        $this->assertSame(range(0, 11), array_column($b->find('rs', 0), 'key'));
        $this->assertSame(7, $b->find('rs', ['key' => 7])[0]['i']);
        $b->insert('rs', ['i' => 12]);
        $this->assertSame(12, $b->find('rs', ['i' => 12])[0]['key']);
        $this->assertSame(5, $b->getShardInfo('rs')['shardSize']);
    }

    /** @test Y-16 */
    public function keyFiltersAreNotCoerced(): void
    {
        $db = $this->db();
        $db->insert('kc', [['a' => 'zero'], ['a' => 'one']]);
        // docs/API.md: an integer, a digit string or a list of them; anything else matches nothing.
        // "5\n" is not key 5.
        foreach ([null, 'abc', true, 1.9, 1.0, -1, '1.0', "1\n", ' 1', [], ['x']] as $bad) {
            $this->assertSame([], $db->find('kc', ['key' => $bad]), 'key ' . var_export($bad, true));
        }
        $this->assertSame('one', $db->find('kc', ['key' => '1'])[0]['a'], 'digit strings are keys');
        $db->update('kc', [['key' => '1'], ['set' => ['b' => 2]]]);
        $this->assertSame(1, $db->find('kc', ['a' => 'one', 'key' => 1])[0]['key']);
        $this->assertIsInt($db->find('kc', ['key' => 1])[0]['key']);
    }

    /** @test O-22 */
    public function compactionTriggersOnGarbageRatio(): void
    {
        $db = $this->db();
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['i' => $i];
        }
        $db->insert('g', $rows);
        $db->delete('g', ['i' => 0]);
        $db->delete('g', ['i' => 1]);
        $db->delete('g', ['i' => 2]);
        // The index in memory (the .kix file is a checkpoint, written when the tail is long)
        $read = function () {
            return $this->readJsonlIndex('g', null);
        };
        $this->assertSame(3, $read->call(\noneDB\Tests\Support\Testing::engine($db))['d'], '3 of 10 deleted: not above 30% yet');
        $db->delete('g', ['i' => 3]);
        $this->assertSame(0, $read->call(\noneDB\Tests\Support\Testing::engine($db))['d'], '4 of 10: compacted');
        $this->assertSame(6, $db->count('g'));
    }
}
