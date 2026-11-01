<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\Support\Testing;
use noneDB\Tests\RegressionTestCase;

/**
 * The key-index checkpoint {seg}.kix (docs/STORAGE_FORMAT.md §6.4): every file the engine writes is decoded
 * by the test base's own reader of the documented format and compared with the key index the data file
 * implies (keyIndexOfData: the last line of a key wins, a tombstone deletes). Damaged files are never
 * trusted.
 */
class KeyIndexFormatTest extends RegressionTestCase
{
    private function rows(int $from, int $to): array
    {
        $rows = [];
        for ($i = $from; $i < $to; $i++) {
            $rows[] = ['n' => $i, 'pad' => str_repeat('x', $i % 17)];
        }
        return $rows;
    }

    private function crcBefore(string $file, int $s): string
    {
        $start = max(0, $s - 256);
        return hash('crc32b', substr((string)file_get_contents($file), $start, $s - $start));
    }

    /** Write sessions until the segment's checkpoint covers the whole data file (the documented threshold) */
    private function checkpointNow(\noneDB $db, string $name, ?int $shard = null): array
    {
        for ($i = 0; $i < 400; $i++) {
            clearstatcache();
            $kix = $this->dbPath($name, 'kix', $shard);
            if (is_file($kix)) {
                $file = $this->readKeyIndexFile($kix);
                if ($file['header']['s'] === filesize($this->dbPath($name, 'data', $shard))) {
                    return $file;
                }
            }
            $db->insert($name, ['n' => -100 - $i]);
            $db->delete($name, ['n' => -100 - $i]);
        }
        $this->fail('no checkpoint covering the data file');
    }

    /** @test §6.4: header, dense layout in 4096-byte blocks of 341 slots, entries = the data file's index */
    public function aDenseCheckpointDescribesTheDataFile(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('kd', $this->rows(0, 1000)); // the first write session writes a checkpoint (none existed)
        $data = $this->dbPath('kd', 'data');
        $kix = $this->dbPath('kd', 'kix');
        $file = $this->readKeyIndexFile($kix);
        $h = $file['header'];
        $this->assertSame(['dense', 0, 1000, 1000, 1000], [$h['layout'], $h['base'], $h['slots'], $h['live'], $h['n']]);
        $this->assertSame(filesize($data), $h['s']);
        $this->assertSame($this->crcBefore($data, $h['s']), $h['h']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $h['e']);
        $this->assertSame(0, $h['d']);
        $this->assertSame(0, $h['t']);
        // 1000 slots: blocks of 341, 341 and 318 slots, each with its crc
        $this->assertSame(512 + 2 * 4096 + 318 * 12 + 4, filesize($kix));
        $this->assertSame($this->keyIndexOfData($data), $file['entries']);
        $this->assertArrayNotHasKey('z', $h, 'single layout');
    }

    /** @test §6.4: updates and deletes; garbage and tombstone counters; the checkpoint follows the data */
    public function aCheckpointAfterUpdatesAndDeletesMatchesTheData(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 1000]);
        $db->insert('ku', $this->rows(0, 600));
        $db->update('ku', [['n' => 5], ['set' => ['u' => 1]]]);
        $db->delete('ku', ['n' => 7]);
        $db->delete('ku', ['n' => 599]);
        $file = $this->checkpointNow($db, 'ku');
        $data = $this->dbPath('ku', 'data');
        $model = $this->keyIndexOfData($data);
        $this->assertSame($model, $file['entries']);
        $this->assertSame(count($model), $file['header']['live']);
        $this->assertArrayNotHasKey(7, $file['entries']);
        $this->assertArrayNotHasKey(599, $file['entries']);
        // Every line that is not the last line of a live key is garbage or a tombstone
        $lines = count(array_filter(explode("\n", (string)file_get_contents($data)), 'strlen'));
        $tombstones = substr_count((string)file_get_contents($data), '{"__nonedb_deleted":');
        $this->assertSame($tombstones, $file['header']['t']);
        $this->assertSame($lines - $tombstones - count($model), $file['header']['d']);
        $this->assertGreaterThan(599, $file['header']['n'] - 1, 'keys are never reused');
    }

    /** @test §6.4: more than max(1024, 4 × live) slots: sparse entries, ascending, 255 per block */
    public function aDeletionHeavySegmentIsSparse(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 1000]);
        $db->insert('ks', $this->rows(0, 3000));
        $db->query('ks')->where(['n' => ['$lt' => 2700]])->delete(); // 300 live of 3000 slots: 3000 > max(1024, 1200)
        $file = $this->checkpointNow($db, 'ks');
        $this->assertSame('sparse', $file['header']['layout']);
        $this->assertSame($file['header']['live'], $file['header']['slots']);
        $this->assertSame($this->keyIndexOfData($this->dbPath('ks', 'data')), $file['entries']);
        $this->assertSame(512 + 2 * 4096, filesize($this->dbPath('ks', 'kix')), 'ceil(300 / 255) = 2 full-size blocks');
        $this->assertSame(range(2700, 2999), array_column($db->find('ks', 0), 'n'));

        // Few deletions stay dense
        $db->insert('kn', $this->rows(0, 3000));
        $db->query('kn')->where(['n' => ['$lt' => 2000]])->delete(); // 1000 live: 3000 <= max(1024, 4000)
        $this->assertSame('dense', $this->checkpointNow($db, 'kn')['header']['layout']);
    }

    /** @test §6.4: a shard's checkpoint: base = sid × z, z and sid in the header */
    public function aShardCheckpointHasItsBase(): void
    {
        $db = $this->db(['shardSize' => 500]);
        $db->insert('kh', $this->rows(0, 1200)); // shards 0, 1, 2
        foreach ([0, 1, 2] as $shard) {
            $file = $this->readKeyIndexFile($this->dbPath('kh', 'kix', $shard));
            $h = $file['header'];
            $this->assertSame([500 * $shard, 500, $shard], [$h['base'], $h['z'], $h['sid']], "shard $shard");
            $this->assertSame($shard < 2 ? 500 : 200, $h['live'], "shard $shard");
            $this->assertSame($this->keyIndexOfData($this->dbPath('kh', 'data', $shard)), $file['entries'], "shard $shard");
            $this->assertSame(array_keys($file['entries']), range(500 * $shard, 500 * $shard + $h['live'] - 1));
        }
    }

    /** @test §6.4: a block crc, the body length, kind or v that don't fit: the file is not trusted */
    public function aDamagedCheckpointIsNeverTrusted(): void
    {
        $cases = [
            'block crc' => function (string $kix) { $raw = file_get_contents($kix); $raw[512 + 4096 + 100] = chr(ord($raw[512 + 4096 + 100]) ^ 0x01); file_put_contents($kix, $raw); },
            'body length' => function (string $kix) { $raw = file_get_contents($kix); file_put_contents($kix, substr($raw, 0, -12)); },
            'unknown v' => function (string $kix) { $raw = file_get_contents($kix); file_put_contents($kix, str_replace('"v":1', '"v":9', $raw)); },
            'unknown kind' => function (string $kix) { $raw = file_get_contents($kix); file_put_contents($kix, str_replace('"kind":"kix"', '"kind":"kiy"', $raw)); },
            'unknown layout' => function (string $kix) { $raw = file_get_contents($kix); file_put_contents($kix, str_replace('"layout":"dense"', '"layout":"other"', $raw)); },
        ];
        foreach ($cases as $case => $damage) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardingEnabled' => false]);
            $db->insert('kc', $this->rows(0, 1000));
            $model = $this->plain($db->find('kc', 0));
            \noneDB::clearStaticCache();
            $damage($this->dbPath('kc', 'kix'));
            $db = $this->db(['shardingEnabled' => false]);
            $this->assertSame($model, $this->plain($db->find('kc', 0)), "$case: every record");
            $this->assertSame(['n' => 400, 'pad' => str_repeat('x', 400 % 17)], $this->plain($db->find('kc', ['key' => 400]))[0] ?? null, "$case: key in block 1");
            $this->assertSame(1000, $db->count('kc'), $case);
            $db->insert('kc', ['n' => 1000]);
            $file = $this->readKeyIndexFile($this->dbPath('kc', 'kix')); // a valid checkpoint again
            $this->assertSame($this->keyIndexOfData($this->dbPath('kc', 'data')), $file['entries'], $case);
            $this->assertTrue($db->checkIntegrity('kc')['ok'], $case);
        }
    }

    /** The JSON header of a field or spatial index file (line 1, STORAGE_FORMAT.md §6.5) */
    private function indexHeader(string $path): array
    {
        $raw = (string)file_get_contents($path);
        return json_decode(substr($raw, 0, (int)strpos($raw, "\n")), true);
    }

    /**
     * @test §6.4, §6.5: a key index rebuilt from the data (its checkpoint no longer fits) has a new epoch, so every
     * field and spatial index of the segment is invalid; the same exclusive session builds them again with that
     * epoch, also when the rebuilt index has the entries of the old one (the data changed in place)
     */
    public function aRebuiltKeyIndexBuildsTheSegmentsIndexesAgain(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = ['n' => $i, 'city' => $i % 2 ? 'odd' : 'eve', 'loc' => ['type' => 'Point', 'coordinates' => [$i / 10, 1]]];
        }
        $db->insert('t', $rows);
        $this->assertTrue($db->createFieldIndex('t', 'city')['success']);
        $this->assertTrue($db->createSpatialIndex('t', 'loc')['success']);
        $data = $this->dbPath('t', 'data');
        $kix = $this->readKeyIndexFile($this->dbPath('t', 'kix'));
        $this->assertSame(filesize($data), $kix['header']['s'], 'the checkpoint covers the data file');
        $epoch = $kix['header']['e'];
        foreach ([$this->dbPath('t', 'fx', 'city'), $this->dbPath('t', 'rx', 'loc')] as $file) {
            $this->assertSame($epoch, $this->indexHeader($file)['e'], basename($file) . ': of the segment epoch');
        }
        // Key 999's city changed in place (same length): in the 256 bytes the checkpoint's h covers, the entries the same
        $raw = (string)file_get_contents($data);
        $at = strrpos($raw, '"city":"odd"');
        $this->assertGreaterThan(strlen($raw) - 256, $at);
        file_put_contents($data, substr_replace($raw, '"city":"ddo"', $at, 12));
        \noneDB::clearStaticCache();

        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame(0, $db->find('t', ['key' => 0])[0]['n'], 'the read that rebuilds the key index');
        $rebuilt = $this->readKeyIndexFile($this->dbPath('t', 'kix'));
        $this->assertNotSame($epoch, $rebuilt['header']['e'], 'a new segment epoch');
        $this->assertSame($kix['entries'], $rebuilt['entries'], 'the same entries');
        foreach ([$this->dbPath('t', 'fx', 'city'), $this->dbPath('t', 'rx', 'loc')] as $file) {
            $this->assertSame($rebuilt['header']['e'], $this->indexHeader($file)['e'], basename($file) . ': built again in that session');
        }
        $this->assertSame([999], array_column($db->find('t', ['city' => 'ddo']), 'key'));
        $this->assertSame(499, $db->count('t', ['city' => 'odd']));
        $this->assertTrue($db->checkIntegrity('t')['ok']);
    }

    /** @test §6.4: a checkpoint written by these rules for data the engine didn't write is read as it is */
    public function aCheckpointWrittenFromTheSpecificationIsRead(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('kw', $this->rows(0, 50));
        \noneDB::clearStaticCache();
        $data = $this->dbPath('kw', 'data');
        $entries = $this->keyIndexOfData($data);
        // A checkpoint (written from the documented format) that leaves out key 10: it must be trusted as it is
        // (fits: s, h, e), so key 10 is not found through it; the data still has the line, but the checkpoint
        // covers it. That is what "loading reads the checkpoint" means; the engine's own files never do this.
        unset($entries[10]);
        $old = $this->readKeyIndexFile($this->dbPath('kw', 'kix'))['header'];
        $this->writeKeyIndexFile($this->dbPath('kw', 'kix'), ['n' => 50, 'd' => 0, 't' => 0, 's' => filesize($data),
            'h' => $this->crcBefore($data, filesize($data)), 'e' => $old['e'], 'created' => 1, 'updated' => 1], $entries);
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame(49, $db->count('kw'), 'the checkpoint is the index of the bytes it covers');
        $this->assertSame([], $db->find('kw', ['key' => 10]));
        $this->assertSame([11], array_column($db->find('kw', ['key' => 11]), 'key'));
    }

    /**
     * @test §6.4: in a database of at most 16 segments a key index is decoded into arrays at its first use
     * when its body is at most 64 KiB, except in the process's first operation, else at the start of the
     * database's next operation;
     * in a larger database it is used as its body until its use paid for decoding - its operations plus the
     * keys looked up in bulk reach an eighth of its live keys. Never without room in memory; answers the
     * same either way.
     */
    public function aKeyIndexIsDecodedOnceItsUsePaysForIt(): void
    {
        $bodyOf = function ($db, string $suffix) {
            foreach (Testing::getOf($db, 'indexCache') as $path => $index) {
                if (substr($path, -strlen($suffix)) === $suffix) {
                    return $index['kb'];
                }
            }
            $this->fail("the key index $suffix is not loaded");
        };

        // One segment, in a process past its first operation: a body of at most 64 KiB (1000 keys, 12 KB) is
        // decoded by the operation that loads it, a larger one (6000 keys, 72 KB) at its second (with room)
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('one', array_map(function ($i) { return ['n' => $i]; }, range(0, 999)));
        $db->insert('big', array_map(function ($i) { return ['n' => $i]; }, range(0, 5999)));
        foreach (['one' => true, 'big' => false] as $name => $small) {
            $suffix = $this->locatorOf($name) . '.ndb4/' . substr($this->dbPath($name, 'kix'), strlen($this->dbPath($name, 'home')));
            $this->assertSame($small, filesize($this->dbPath($name, 'kix')) <= 65536 + 512, "precondition: $name");
            foreach ([true, false] as $room) {
                \noneDB::clearStaticCache();
                $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => $room ? 0 : memory_get_usage() + 4 * 1048576]);
                $label = $name . ', ' . ($room ? 'with room' : 'no room');
                $this->assertSame([['n' => 5]], $this->plain($db->find($name, ['key' => 5])));
                $this->assertSame($room && $small, $bodyOf($db, $suffix) === '', "$label: decoded by the loading operation");
                $this->assertSame([['n' => 6]], $this->plain($db->find($name, ['key' => 6])));
                $this->assertSame($room, $bodyOf($db, $suffix) === '', "$label: decoded by the second operation");
            }
        }

        // A larger body is decoded at the start of the database's next operation, even one that doesn't read
        // it (a delete of a key that can't exist); never without room
        $suffix = $this->locatorOf('big') . '.ndb4/' . substr($this->dbPath('big', 'kix'), strlen($this->dbPath('big', 'home')));
        foreach ([true, false] as $room) {
            \noneDB::clearStaticCache();
            $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => $room ? 0 : memory_get_usage() + 4 * 1048576]);
            $label = 'big, ' . ($room ? 'with room' : 'no room');
            $this->assertSame([['n' => 5]], $this->plain($db->find('big', ['key' => 5])));
            $this->assertNotSame('', $bodyOf($db, $suffix), "$label: the loading operation keeps the body");
            $this->assertSame(['n' => 0], $db->delete('big', ['key' => -1]));
            $this->assertSame($room, $bodyOf($db, $suffix) === '', "$label: decoded by the next operation, which doesn't read it");
        }

        // A process's first operation keeps even a small body (a single cold operation); its second decodes it
        $out = $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false, 'cacheMemoryLimit' => 0]) . ';
            $decoded = function () use ($db) {
                foreach (\noneDB\Tests\Support\Testing::getOf($db, "indexCache") as $path => $index) {
                    if (substr($path, -9) === "/main.kix" && strpos($path, ' . var_export($this->locatorOf('one'), true) . ') !== false) {
                        return $index["kb"] === "";
                    }
                }
                return null;
            };
            echo json_encode($db->find("one", ["key" => 5])[0]["n"]), " ", json_encode($decoded()), "\n";
            echo json_encode($db->find("one", ["key" => 6])[0]["n"]), " ", json_encode($decoded()), "\n";');
        $this->assertSame("5 false\n6 true", $out, 'first operation: the body; second: decoded');

        // 20 shards of 1000 keys: shard 3 (keys 3000-3999) is used as a body until its use paid
        $config = ['shardSize' => 1000];
        $db = $this->db($config + ['cacheMemoryLimit' => 0]);
        for ($start = 0; $start < 20000; $start += 2000) { // in batches: the suite's memory
            $db->insert('kd', array_map(function ($i) { return ['n' => $i]; }, range($start, $start + 1999)));
        }
        foreach ([true, false] as $room) {
            // The setting is process-wide: each instance sets it
            $config = ['shardSize' => 1000, 'cacheMemoryLimit' => $room ? 0 : memory_get_usage() + 4 * 1048576];
            $label = $room ? 'with room' : 'no room';

            // Operations of one key: 1000 live keys, due at the 125th (125 × 8 >= 1000)
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            for ($op = 1; $op <= 126; $op++) {
                $key = 3000 + $op * 7 % 1000;
                $this->assertSame([['n' => $key]], $this->plain($db->find('kd', ['key' => $key])));
                $this->assertSame($room && $op >= 125, $bodyOf($db, '/s3.kix') === '', "$label: decoded after operation $op");
            }

            // Keys looked up in bulk: 100 keys in the loading operation are not enough; 124 more in a 2nd
            // operation make (2 + 224) × 8 >= 1000
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->assertSame(range(3000, 3099), array_column($db->find('kd', ['key' => range(3000, 3099)]), 'n'));
            $this->assertNotSame('', $bodyOf($db, '/s3.kix'), "$label: 100 keys looked up (and the loading operation), the body");
            $this->assertSame(range(3100, 3223), array_column($db->find('kd', ['key' => range(3100, 3223)]), 'n'));
            $this->assertSame($room, $bodyOf($db, '/s3.kix') === '', "$label: 224 keys looked up in 2 operations");
        }
    }

    /**
     * @test §6.4: the start of an operation that makes the key indexes again (rebuildIndex()) decodes none of the old
     * ones the process loaded; another operation does
     */
    public function anOperationThatMakesTheKeyIndexesAgainDecodesNoneOfTheOldOnes(): void
    {
        $config = ['shardingEnabled' => false, 'cacheMemoryLimit' => 0];
        // 6000 keys: a body above 64 KiB, decoded at the start of the database's next operation
        $this->db($config)->insert('rk', array_map(function ($i) { return ['n' => $i]; }, range(0, 5999)));
        foreach (['another operation' => 1, 'rebuildIndex()' => 0] as $label => $decodes) {
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->assertSame([['n' => 5]], $this->plain($db->find('rk', ['key' => 5])), $label);
            $before = Testing::get('indexStats')['keyDecodes'];
            if ($decodes === 1) {
                $this->assertSame(['n' => 0], $db->delete('rk', ['key' => -1]), $label);
            } else {
                $this->assertSame(6000, $db->rebuildIndex('rk')['totalRecords'], $label);
            }
            $this->assertSame($decodes, Testing::get('indexStats')['keyDecodes'] - $before, "$label: key indexes decoded");
            $this->assertSame([['n' => 5999]], $this->plain($db->find('rk', ['key' => 5999])), $label);
        }
    }

    /**
     * @test §6.4: in memory a key index costs about 12 bytes per key (no per-key array). In a process of its
     * own: a measurement of memory, not disturbed by what earlier tests left in the allocator
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function aLoadedKeyIndexIsCompact(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $batch = [];
        for ($i = 0; $i < 100000; $i++) {
            $batch[] = ['n' => $i];
        }
        $db->insert('km', $batch);
        unset($batch);
        \noneDB::clearStaticCache();
        // A single segment with room is decoded at once (§6.4); the body is what light use and a process
        // without room keep: a limit a little above what the process uses
        $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => memory_get_usage() + 4 * 1048576]);
        gc_collect_cycles();
        $before = memory_get_usage();
        $this->assertSame([['n' => 77777]], $this->plain($db->find('km', ['key' => 77777])));
        $grown = memory_get_usage() - $before;
        $this->assertLessThan(100000 * 16 + 256 * 1024, $grown, 'about 12 bytes per key (1.2 MB), never arrays (4+ MB)');
    }
}
