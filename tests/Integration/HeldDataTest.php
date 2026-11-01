<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;
use noneDB\Tests\Support\Testing;

/**
 * Reads that don't go through a record's position: the bytes of a data file in repeated use held within the cache's
 * budget (RecordIO::heldData()), the lines of a segment without garbage split at once (RecordIO::liveLines()), and a
 * segment whose index can't answer. Every one must give the answers the disk gives.
 */
class HeldDataTest extends RegressionTestCase
{
    /** A limit that leaves room whatever this process already holds: bytes are held while it stays under half of the limit */
    private function limit(): int
    {
        return 4 * memory_get_usage() + 67108864;
    }

    /** 200 records in 20 shards of 10, field u indexed, read by a new process */
    private function shards(array $config = [], int $records = 200): \noneDB
    {
        $config += ['shardSize' => 10, 'cacheMemoryLimit' => $this->limit()];
        $db = $this->db($config);
        $db->insert('hd', array_map(function ($n) { return ['u' => 'u' . $n, 'n' => $n]; }, range(0, $records - 1)));
        $db->createFieldIndex('hd', 'u');
        \noneDB::clearStaticCache();
        Testing::set('cacheMemoryLimit', $config['cacheMemoryLimit'] === 'auto' ? null : $config['cacheMemoryLimit']);
        return $this->db($config);
    }

    private function stat(string $name): int
    {
        return Testing::get('indexStats')[$name];
    }

    private function dataFile(int $shard): string
    {
        return $this->dbPath('hd', 'data', $shard);
    }

    private function held(int $shard): ?array
    {
        return Testing::get('staticFieldIndexCache')[$this->dataFile($shard)] ?? null;
    }

    /** @test */
    public function aShardInRepeatedUseAnswersFromItsHeldBytesAsTheDiskDoes(): void
    {
        $db = $this->shards();
        $before = $this->stat('dataHeld');
        $this->assertSame(['u' => 'u35', 'n' => 35, 'key' => 35], $db->find('hd', ['u' => 'u35'])[0]);
        $this->assertSame($before, $this->stat('dataHeld'), 'the first operation on a shard reads its record only');
        $this->assertNull($this->held(3));
        $this->assertSame(['u' => 'u36', 'n' => 36, 'key' => 36], $db->find('hd', ['u' => 'u36'])[0]);
        $this->assertSame($before + 1, $this->stat('dataHeld'), 'its second operation holds the file');
        $this->assertSame((string)file_get_contents($this->dataFile(3)), $this->held(3)['raw'], 'the bytes are the file\'s');
        // Nothing of the file is needed now: the record comes from what is held
        $file = $this->dataFile(3);
        rename($file, $file . '.away');
        $reads = $this->stat('heldReads');
        try {
            $this->assertSame(['u' => 'u37', 'n' => 37, 'key' => 37], $db->find('hd', ['u' => 'u37'])[0]);
            $this->assertSame([['u' => 'u30', 'n' => 30, 'key' => 30], ['u' => 'u31', 'n' => 31, 'key' => 31]],
                array_values($db->find('hd', ['key' => [30, 31]])), 'several keys of the shard too');
        } finally {
            rename($file . '.away', $file);
        }
        $this->assertSame($reads + 2, $this->stat('heldReads'));
        $this->assertSame($before + 1, $this->stat('dataHeld'), 'held once');
    }

    /** @test */
    public function fewSegmentsHoldNothing(): void
    {
        $db = $this->shards(['shardSize' => 10], 160); // 16 shards: the limit of "few" (Segments::FEW_SEGMENTS)
        $before = $this->stat('dataHeld');
        for ($i = 0; $i < 12; $i++) {
            $this->assertCount(1, $db->find('hd', ['u' => 'u' . (30 + $i % 10)]));
        }
        $this->assertSame($before, $this->stat('dataHeld'));
    }

    /** @test */
    public function aFewSegmentIndexThatStaysABodyHoldsNothing(): void
    {
        // Two shards. Lines of about 20 bytes: the arrays of a shard (80 bytes a key) don't fit between what the process
        // uses and half of the limit, twice the file does. The body stays, its operations count on, and still nothing is held
        $config = ['shardSize' => 8000, 'cacheMemoryLimit' => $this->limit()];
        $db = $this->db($config);
        foreach (array_chunk(range(0, 15999), 4000) as $part) {
            $db->insert('hd', array_map(function ($n) { return ['u' => $n % 7]; }, $part));
        }
        \noneDB::clearStaticCache();
        $config['cacheMemoryLimit'] = 2 * (memory_get_usage() + 640000);
        Testing::set('cacheMemoryLimit', $config['cacheMemoryLimit']);
        $db = $this->db($config);
        $size = filesize($this->dataFile(0));
        $this->assertLessThan(240000, $size, 'precondition: short lines');
        $before = $this->stat('dataHeld');
        $fits = [];
        for ($i = 0; $i < 8; $i++) {
            $this->assertSame([$i, $i + 7], array_column($db->find('hd', ['key' => [$i, $i + 7]]), 'key'));
            $fits[] = Testing::callStatic('CacheManager::decodeFits', 2 * $size); // here: PHPUnit's own classes load below
        }
        $this->assertSame($before, $this->stat('dataHeld'), 'nothing held');
        $this->assertSame(array_fill(0, 8, true), $fits, 'precondition: the bytes would fit at every operation');
        $index = Testing::get('staticIndexCache')[$this->dbPath('hd', 'kix', 0)];
        $this->assertNotSame('', $index['kb'], 'precondition: the index stays a body');
        $this->assertGreaterThanOrEqual($size, $index['uses'] * 131072, 'precondition: its operations paid for the file');
    }

    /** @test */
    public function aProcessWithoutALimitHoldsNothing(): void
    {
        $db = $this->shards(['cacheMemoryLimit' => 0]);
        $before = $this->stat('dataHeld');
        for ($i = 0; $i < 12; $i++) {
            $this->assertCount(1, $db->find('hd', ['u' => 'u' . (30 + $i % 10)]));
        }
        $this->assertSame($before, $this->stat('dataHeld'));
    }

    /** @test */
    public function bytesThatDontFitTheBudgetAreNotHeld(): void
    {
        // Under the limit, so nothing is evicted and the shard's uses count; over half of it, where nothing more is loaded
        $db = $this->shards(['cacheMemoryLimit' => (int)(1.5 * memory_get_usage())]);
        $this->assertSame('', Testing::get('staticIndexCache') === [] ? '' : 'precondition: a new process');
        $before = $this->stat('dataHeld');
        for ($i = 0; $i < 12; $i++) {
            $this->assertCount(1, $db->find('hd', ['u' => 'u' . (30 + $i % 10)]));
        }
        $this->assertSame($before, $this->stat('dataHeld'));
    }

    /** @test */
    public function aLargerFileWaitsForMoreUses(): void
    {
        // Lines of about 35 KB: a shard of 10 is about 350 KB, more than two uses pay for (131072 bytes each), less than three
        $db = $this->db(['shardSize' => 10, 'cacheMemoryLimit' => $this->limit()]);
        $pad = str_repeat('x', 35000);
        $db->insert('hd', array_map(function ($n) use ($pad) { return ['u' => 'u' . $n, 'p' => $pad]; }, range(0, 199)));
        $db->createFieldIndex('hd', 'u');
        \noneDB::clearStaticCache();
        Testing::set('cacheMemoryLimit', $this->limit());
        $db = $this->db(['shardSize' => 10, 'cacheMemoryLimit' => $this->limit()]);
        $size = filesize($this->dataFile(3));
        $this->assertTrue($size > 2 * 131072 && $size <= 3 * 131072, 'precondition: the size of the file');
        $before = $this->stat('dataHeld');
        $db->find('hd', ['u' => 'u35']);
        $db->find('hd', ['u' => 'u36']);
        $this->assertSame($before, $this->stat('dataHeld'), 'two uses');
        $db->find('hd', ['u' => 'u37']);
        $this->assertSame($before + 1, $this->stat('dataHeld'), 'three uses');
    }

    /** @test */
    public function aWriterHoldsNothingAndUsesWhatIsCurrent(): void
    {
        $db = $this->shards();
        $before = $this->stat('dataHeld');
        $db->find('hd', ['u' => 'u35']);
        // A write by filter reads records under the exclusive lock: bytes a rollback may take back aren't held
        $this->assertSame(1, $db->update('hd', [['n' => 36], ['set' => ['m' => 1]]])['n']);
        $this->assertSame(1, $db->update('hd', [['n' => 37], ['set' => ['m' => 1]]])['n']);
        $this->assertSame($before, $this->stat('dataHeld'));
    }

    /** @test */
    public function newerLinesComeFromTheFile(): void
    {
        $config = ['shardSize' => 10, 'cacheMemoryLimit' => $this->limit()];
        $db = $this->shards();
        $db->find('hd', ['u' => 'u35']);
        $db->find('hd', ['u' => 'u36']);
        $this->assertNotNull($this->held(3));
        // This process, then another one: same epoch, lines after the bytes held
        $kix = $this->dbPath('hd', 'kix', 3);
        $epoch = Testing::get('staticIndexCache')[$kix]['e'];
        $this->assertSame(1, $db->update('hd', [['key' => 35], ['set' => ['m' => 'mine']]])['n']);
        $this->assertSame('mine', $db->find('hd', ['key' => 35])[0]['m']);
        $both = array_values($db->find('hd', ['key' => [30, 35]]));
        $this->assertSame([30, 35], array_column($both, 'key'), 'a line held and a newer one in one read');
        $this->assertSame('mine', $both[1]['m']);
        $this->assertSame('{"n":1}', $this->runChild('$db = ' . $this->childDb($config) . '; echo json_encode(["n" => $db->update("hd", [["key" => 36], ["set" => ["m" => "theirs"]]])["n"]]);'));
        $this->assertSame('theirs', $db->find('hd', ['key' => 36])[0]['m']);
        $this->assertSame('mine', $db->find('hd', ['u' => 'u35'])[0]['m']);
        $this->assertSame(37, $db->find('hd', ['key' => 37])[0]['n'], 'the lines held still answer');
        $this->assertSame($epoch, Testing::get('staticIndexCache')[$kix]['e'], 'no repair: nothing looked wrong');
    }

    /** @test */
    public function aDataFileReplacedByThisProcessDropsTheBytesHeld(): void
    {
        $db = $this->shards();
        $db->find('hd', ['u' => 'u35']);
        $db->find('hd', ['u' => 'u36']);
        $stale = $this->held(3)['raw'];
        $this->assertSame(1, $db->update('hd', [['key' => 30], ['set' => ['m' => str_repeat('y', 40)]]])['n']);
        $db->compact('hd');
        $this->assertNotSame($stale, (string)file_get_contents($this->dataFile(3)), 'precondition: the file is another');
        $epoch = $this->readKeyIndexFile($this->dbPath('hd', 'kix', 3))['header']['e'];
        $this->assertSame(39, $db->find('hd', ['key' => 39])[0]['n']);
        $this->assertSame(['u' => 'u38', 'n' => 38, 'key' => 38], $db->find('hd', ['u' => 'u38'])[0]);
        $held = $this->held(3);
        if ($held !== null) {
            $this->assertSame((string)file_get_contents($this->dataFile(3)), $held['raw'], 'what is held is the new file');
        }
        $this->assertSame($epoch, $this->readKeyIndexFile($this->dbPath('hd', 'kix', 3))['header']['e'], 'no repair: nothing looked wrong');
    }

    /**
     * @test CONFIGURATION.md: after disableStaticCache() each new instance keeps its own cache. Bytes held are its
     * own too, and what another instance writes reaches it through the lock state as it reaches another process.
     */
    public function anInstanceWithItsOwnCacheHoldsItsOwnBytesAndAnswersWhatIsCurrent(): void
    {
        $config = ['shardSize' => 10, 'cacheMemoryLimit' => $this->limit()];
        $this->shards();
        \noneDB::clearStaticCache();
        \noneDB::disableStaticCache();
        try {
            $db = $this->db($config);
            $other = $this->db($config);
            $before = $this->stat('dataHeld');
            $db->find('hd', ['u' => 'u35']);
            $db->find('hd', ['u' => 'u36']);
            $this->assertSame($before + 1, $this->stat('dataHeld'));
            $file = $this->dataFile(3);
            $this->assertSame((string)file_get_contents($file), Testing::getOf($db, 'fieldIndexCache')[$file]['raw']);
            $this->assertArrayNotHasKey($file, Testing::getOf($other, 'fieldIndexCache'));
            $this->assertArrayNotHasKey($file, Testing::get('staticFieldIndexCache'));
            $this->assertSame(1, $other->update('hd', [['key' => 35], ['set' => ['n' => -35]]])['n']);
            $other->compact('hd');
            $this->assertSame(['u' => 'u35', 'n' => -35, 'key' => 35], $db->find('hd', ['u' => 'u35'])[0]);
            $this->assertSame(['u' => 'u36', 'n' => 36, 'key' => 36], $db->find('hd', ['u' => 'u36'])[0]);
        } finally {
            \noneDB::enableStaticCache();
        }
    }

    /** @test */
    public function aDataFileReplacedByAnotherProcessDropsTheBytesHeld(): void
    {
        $config = ['shardSize' => 10, 'cacheMemoryLimit' => $this->limit()];
        $db = $this->shards();
        $db->find('hd', ['u' => 'u35']);
        $db->find('hd', ['u' => 'u36']);
        $this->assertNotNull($this->held(3));
        $this->assertSame('1', $this->runChild('$db = ' . $this->childDb($config) . '; $db->update("hd", [["key" => 30], ["set" => ["m" => "' . str_repeat('y', 40) . '"]]]); echo (int)$db->compact("hd")["success"];'));
        $epoch = $this->readKeyIndexFile($this->dbPath('hd', 'kix', 3))['header']['e'];
        $this->assertSame(['u' => 'u38', 'n' => 38, 'key' => 38], $db->find('hd', ['u' => 'u38'])[0]);
        $this->assertSame(str_repeat('y', 40), $db->find('hd', ['key' => 30])[0]['m']);
        $this->assertSame($epoch, $this->readKeyIndexFile($this->dbPath('hd', 'kix', 3))['header']['e'], 'no repair: nothing looked wrong');
    }

    /** @test */
    public function checkIntegrityReadsTheDiskNotTheBytesHeld(): void
    {
        $db = $this->shards();
        $db->find('hd', ['u' => 'u35']);
        $db->find('hd', ['u' => 'u36']);
        $this->assertNotNull($this->held(3));
        $file = $this->dataFile(3);
        $bytes = (string)file_get_contents($file);
        $at = strpos($bytes, '"u37"');
        $bytes[$at + 1] = 'z';
        $bytes[$at + 2] = 'z';
        $bytes = substr_replace($bytes, '{', strpos($bytes, '"u38"') - 5, 1); // a line that doesn't decode
        file_put_contents($file, $bytes);
        $this->assertFalse($db->checkIntegrity('hd')['ok'], 'the damage on disk is reported');
    }

    /** @test */
    public function evictedBytesAreReadAgainAndAnswersStayTheSame(): void
    {
        $db = $this->shards();
        $db->find('hd', ['u' => 'u35']);
        $db->find('hd', ['u' => 'u36']);
        $this->assertNotNull($this->held(3));
        Testing::set('cacheMemoryLimit', 1);
        $db->find('hd', ['u' => 'u105']); // loads something: the cache is looked at when the lock is released
        $this->assertNull($this->held(3), 'dropped like any cached index');
        Testing::set('cacheMemoryLimit', $this->limit());
        $this->assertSame(['u' => 'u38', 'n' => 38, 'key' => 38], $db->find('hd', ['u' => 'u38'])[0]);
    }

    /** @test */
    public function aWholeReadOfASegmentWithoutGarbageSplitsItsLines(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('hd', array_map(function ($n) { return ['n' => $n, 'p' => str_repeat('x', $n % 300)]; }, range(0, 4999)));
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $before = $this->stat('liveReads');
        $records = $db->find('hd', 0);
        $this->assertSame(1, $this->stat('liveReads') - $before);
        $this->assertSame(range(0, 4999), array_column($records, 'key'));
        foreach ([0, 1, 299, 300, 4999] as $n) {
            $this->assertSame(['n' => $n, 'p' => str_repeat('x', $n % 300), 'key' => $n], $records[$n]);
        }
    }

    /** @test */
    public function aReadOfSomeKeysLeavesTheOtherLinesOfTheFileAlone(): void
    {
        $db = $this->shards();
        $before = $this->stat('liveTries');
        $this->assertSame(['u' => 'u35', 'n' => 35, 'key' => 35], $db->find('hd', ['u' => 'u35'])[0]);
        $this->assertSame([30, 31], array_column($db->find('hd', ['key' => [30, 31]]), 'key'));
        $this->assertSame($before, $this->stat('liveTries'));
    }

    /** @test */
    public function anIndexIsDecodedOnceHoweverOftenItIsUsed(): void
    {
        $db = $this->shards(); // more segments than "few": its operations go on counting for the data bytes
        $before = $this->stat('keyDecodes');
        for ($i = 0; $i < 12; $i++) {
            $this->assertCount(1, $db->find('hd', ['u' => 'u' . (30 + $i % 10)]));
        }
        $this->assertSame($before + 1, $this->stat('keyDecodes'), 'shard 3, the only one read');
    }

    /** @test */
    public function aSegmentWithGarbageIsReadByPosition(): void
    {
        foreach ([
            'an update' => function ($db) { $db->update('hd', [['key' => 3], ['set' => ['m' => 1]]]); },
            'a delete' => function ($db) { $db->delete('hd', ['key' => 3]); },
        ] as $case => $change) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardingEnabled' => false]);
            $db->insert('hd', array_map(function ($n) { return ['n' => $n]; }, range(0, 9)));
            $change($db);
            $expected = $case === 'an update' ? range(0, 9) : array_values(array_diff(range(0, 9), [3]));
            \noneDB::clearStaticCache();
            $db = $this->db(['shardingEnabled' => false]);
            $before = $this->stat('liveTries');
            $this->assertSame($expected, array_column($db->find('hd', 0), 'key'), $case);
            $this->assertSame(0, $this->stat('liveTries') - $before, $case . ': the index says so, no line is looked at');
        }
    }

    /** @test */
    public function aTombstoneAloneIsAReasonNotToSplit(): void
    {
        // Compaction keeps the highest key by a tombstone: no garbage line, one that is no record
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('hd', array_map(function ($n) { return ['n' => $n]; }, range(0, 99)));
        $db->delete('hd', ['key' => 99]);
        $db->compact('hd');
        $header = $this->readKeyIndexFile($this->dbPath('hd', 'kix'))['header'];
        $this->assertSame([0, 1], [$header['d'], $header['t']], 'precondition: a tombstone, no garbage');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $before = $this->stat('liveTries');
        $this->assertSame(range(0, 98), array_column($db->find('hd', 0), 'key'));
        $this->assertSame(0, $this->stat('liveTries') - $before);
    }

    /** @test */
    public function aKeyTheLinesDontHoldIsLeftToTheReadByPosition(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('hd', array_map(function ($n) { return ['n' => $n]; }, range(0, 99)));
        $kix = $this->dbPath('hd', 'kix');
        $checkpoint = $this->readKeyIndexFile($kix);
        \noneDB::clearStaticCache();
        // One key more than there are lines, the bytes of the file unchanged: every line is where the index says
        $entries = $checkpoint['entries'];
        $entries[100] = $entries[99];
        $this->writeKeyIndexFile($kix, $checkpoint['header'], $entries);
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame(range(0, 99), array_column($db->find('hd', 0), 'key'));
        $this->assertArrayNotHasKey(100, $this->readKeyIndexFile($kix)['entries'], 'the read by position repaired the index');
    }

    /** @test */
    public function aShardWithoutCandidatesIsNotRead(): void
    {
        $db = $this->db(['shardSize' => 10]);
        $db->insert('hd', array_map(function ($n) { return ['a' => $n % 2, 'b' => ($n + 1) % 2]; }, range(0, 39)));
        $db->createFieldIndex('hd', 'a');
        $db->createFieldIndex('hd', 'b');
        // Both fields are in every shard, the two values never in one record: the filters let every shard through, its candidates are none
        $reads = Testing::get('segmentReads');
        $this->assertSame([], $db->find('hd', ['a' => 0, 'b' => 0]));
        $this->assertSame($reads, Testing::get('segmentReads'));
    }

    /** @test */
    public function aFieldWithoutAnIndexGetsNone(): void
    {
        $db = $this->db(['shardSize' => 10]);
        $db->insert('hd', array_map(function ($n) { return ['a' => $n % 2, 'plain' => 'p' . $n]; }, range(0, 39)));
        $db->createFieldIndex('hd', 'a');
        $this->assertSame([3], array_column($db->find('hd', ['a' => 1, 'plain' => 'p3']), 'key'));
        $this->assertSame([3], array_column($db->find('hd', ['plain' => 'p3']), 'key'));
        $this->assertSame([], glob(dirname($this->dbPath('hd', 'fx', 'a', 0)) . '/*plain*'), 'a filter builds no index of its own');
    }

    /** @test */
    public function linesOutOfKeyOrderAreSorted(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('hd', array_map(function ($n) { return ['n' => 100 + $n]; }, range(0, 99))); // past the checkpoint threshold
        $data = $this->dbPath('hd', 'data');
        $kix = $this->dbPath('hd', 'kix');
        $checkpoint = $this->readKeyIndexFile($kix);
        \noneDB::clearStaticCache();
        // Keys 1 and 2 swapped, each line where the index now says: every line live and in its place, out of key order
        $lines = explode("\n", rtrim((string)file_get_contents($data), "\n"));
        [$lines[1], $lines[2]] = [$lines[2], $lines[1]];
        file_put_contents($data, implode("\n", $lines) . "\n");
        $entries = $checkpoint['entries'];
        [$entries[1], $entries[2]] = [$entries[2], $entries[1]];
        $this->writeKeyIndexFile($kix, $checkpoint['header'], $entries);
        $db = $this->db(['shardingEnabled' => false]);
        $before = $this->stat('liveReads');
        $records = $db->find('hd', 0);
        $this->assertSame(1, $this->stat('liveReads') - $before);
        $this->assertSame(range(0, 99), array_column($records, 'key'));
        $this->assertSame(range(100, 199), array_column($records, 'n'));
    }

    /** @test */
    public function aLineThatIsNotWhereTheIndexSaysIsLeftToTheReadByPosition(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('hd', array_map(function ($n) { return ['n' => 100 + $n]; }, range(0, 99)));
        $data = $this->dbPath('hd', 'data');
        $this->assertFileExists($this->dbPath('hd', 'kix'), 'precondition: an index on disk the swap goes behind');
        \noneDB::clearStaticCache();
        // Two lines of the same length swapped behind the index: each is a record, neither where the index says
        $lines = explode("\n", rtrim((string)file_get_contents($data), "\n"));
        [$lines[1], $lines[2]] = [$lines[2], $lines[1]];
        file_put_contents($data, implode("\n", $lines) . "\n");
        $before = strlen($lines[0]) + 1;
        $db = $this->db(['shardingEnabled' => false]);
        $records = $db->find('hd', 0);
        $this->assertSame(range(0, 99), array_column($records, 'key'));
        $this->assertSame(range(100, 199), array_column($records, 'n'));
        $index = $this->readKeyIndexFile($this->dbPath('hd', 'kix'));
        $this->assertSame($before + strlen($lines[2]) + 1, $index['entries'][1][0], 'the read by position found the index stale and repaired it: key 1 is one line later now');
        $this->assertSame($before, $index['entries'][2][0]);
    }

    /** @test */
    public function anUnterminatedLastLineIsNoRecordOfAWholeRead(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('hd', [['n' => 0], ['n' => 1]]);
        \noneDB::clearStaticCache();
        file_put_contents($this->dbPath('hd', 'data'), '{"n":2,"key":2', FILE_APPEND);
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame([0, 1], array_column($db->find('hd', 0), 'key'));
    }

    /** @test */
    public function aShardWhoseIndexCantAnswerIsScanned(): void
    {
        $db = $this->shards();
        unlink($this->dbPath('hd', 'fx', 'u', 3));
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 10]);
        // Nothing may be built here (as in a snapshot or checkIntegrity()): the shard has no index, its records are read
        Testing::set('noWrites', 1);
        try {
            $this->assertSame(['u' => 'u35', 'n' => 35, 'key' => 35], $db->find('hd', ['u' => 'u35'])[0] ?? null);
        } finally {
            Testing::set('noWrites', 0);
        }
    }

    /**
     * @test The read of lines compares offsets, not lengths: each line's end is the next one's offset. A wrong length in
     * an index gives no wrong record, whichever read comes first
     */
    public function aWrongLengthInTheIndexGivesNoWrongRecord(): void
    {
        foreach (['all records' => function ($db) { return $db->find('hd', 0); },
                  'by key' => function ($db) { return array_merge($db->find('hd', ['key' => 50]), $db->find('hd', ['key' => 99])); }] as $read => $run) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardingEnabled' => false]);
            $db->insert('hd', array_map(function ($n) { return ['n' => 100 + $n]; }, range(0, 99)));
            $kix = $this->dbPath('hd', 'kix');
            $checkpoint = $this->readKeyIndexFile($kix);
            \noneDB::clearStaticCache();
            $entries = $checkpoint['entries'];
            $entries[50][1] += 7;
            $entries[99][1] -= 3;
            $this->writeKeyIndexFile($kix, $checkpoint['header'], $entries);
            $records = $run($this->db(['shardingEnabled' => false]));
            foreach ($records as $record) {
                $this->assertSame(100 + $record['key'], $record['n'], $read);
            }
            $this->assertSame(range(0, 99), array_column($this->db(['shardingEnabled' => false])->find('hd', 0), 'key'), $read);
        }
    }
}
