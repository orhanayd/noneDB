<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\Support\Testing;
use noneDB\Tests\RegressionTestCase;

/**
 * Paged field indexes .fx (docs/STORAGE_FORMAT.md §6.5.1) and the global field index of shard filters .gfx
 * (§6.5.2). Every file is decoded by the test base's own reader of the documented format and compared with a
 * model of the data files (typed bucket ids, the keys that hold each value, each shard's filter). Point
 * lookups read a few pages, not the whole index, and follow the data lines written after the index.
 */
class FieldIndexFormatTest extends RegressionTestCase
{
    private static function typedId($v): ?string
    {
        if ($v === null) {
            return 'n';
        }
        if (is_bool($v)) {
            return $v ? 't' : 'f';
        }
        if (is_int($v)) {
            return 'i' . $v;
        }
        if (is_float($v)) {
            return 'd' . bin2hex(pack('E', $v + 0.0));
        }
        return is_string($v) ? 's' . $v : null;
    }

    /** bucket id => key | [keys] of a field over data files (last line wins, tombstones delete) */
    private function modelMap(array $dataFiles, string $field): array
    {
        $records = [];
        foreach ($dataFiles as $file) {
            foreach (array_filter(explode("\n", (string)file_get_contents($file)), 'strlen') as $line) {
                $record = json_decode($line, true);
                if (isset($record['__nonedb_deleted'])) {
                    unset($records[$record['__nonedb_deleted']]);
                } elseif (isset($record['key'])) {
                    $records[$record['key']] = $record;
                }
            }
        }
        ksort($records);
        $map = [];
        foreach ($records as $key => $record) {
            if (array_key_exists($field, $record) && ($id = self::typedId($record[$field])) !== null) {
                $map[$id][] = $key;
            }
        }
        ksort($map, SORT_STRING);
        return array_map(function ($keys) { return count($keys) === 1 ? $keys[0] : $keys; }, $map);
    }

    private function crcBefore(string $file, int $s): string
    {
        $start = max(0, $s - 256);
        return hash('crc32b', substr((string)file_get_contents($file), $start, $s - $start));
    }

    private function stats(): array
    {
        return Testing::get('indexStats');
    }

    /** @test §6.5.1: a segment's field index is a paged file of the values of its data */
    public function aSegmentIndexIsAPagedFileOfItsData(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $rows = [];
        for ($i = 0; $i < 3000; $i++) {
            $rows[] = ['g' => $i % 7 === 0 ? $i % 50 : ($i % 11 === 0 ? null : 'g' . ($i % 100)), 'pad' => 'x'];
        }
        $db->insert('fi', $rows);
        $db->createFieldIndex('fi', 'g');
        $data = $this->dbPath('fi', 'data');
        [$header, $map] = $this->readFieldIndexFile($this->dbPath('fi', 'fx', 'g'));
        $kix = $this->readKeyIndexFile($this->dbPath('fi', 'kix'))['header'];
        $this->assertSame(['fx', 1, 'g'], [$header['kind'], $header['v'], $header['field']]);
        $this->assertSame([$kix['s'], $kix['e']], [$header['s'], $header['e']], 'covers the bytes of the key index, its epoch');
        $this->assertSame($this->crcBefore($data, $header['s']), $header['h']);
        $model = $this->modelMap([$data], 'g');
        $this->assertSame($model, $map);
        $this->assertSame(count($model), $header['c']);
        $this->assertSame(3000, $header['n'], 'every record has the field (null included)');
        $this->assertSame(2999, $header['mk']);
        $this->assertGreaterThanOrEqual(1, $header['pg']['levels'], 'more than one data page: a directory');
        $this->assertGreaterThan(1, $header['pg']['pages']);
    }

    /**
     * @test §6.5.1 "Directory pages": bucket ids of more than 2 KiB fit one per page, yet a directory page
     * holds at least two entries, so every level is smaller than the one below and the file is written
     * (a segment's index; the global index of the sharded layout hashes them into its filters). Its own process:
     * a builder that doesn't shrink runs out of memory.
     * @runInSeparateProcess
     */
    public function aDirectoryOfLongBucketIdsStillShrinks(): void
    {
        foreach (['one segment' => ['shardingEnabled' => false], 'sharded' => ['shardSize' => 100]] as $layout => $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $rows = [];
            for ($i = 0; $i < 400; $i++) {
                $rows[] = ['c' => 'c' . ($i % 40) . str_repeat('-', 2500)];
            }
            $db->insert('fd', $rows);
            $db->createFieldIndex('fd', 'c');
            [$header, $map] = $this->readFieldIndexFile($this->dbPath('fd', 'fx', 'c', $layout === 'sharded' ? 0 : null));
            $this->assertCount(40, $map, $layout);
            $this->assertGreaterThanOrEqual(2, $header['pg']['levels'], "$layout: directory levels over 40 one-entry data pages");
            if ($layout === 'sharded') {
                // Every shard holds the 40 values: 2048 bits (the smallest power of two of at least 1280)
                [$global, $filters] = $this->readGlobalFieldFile($this->dbPath('fd', 'gfx', 'c'));
                $this->assertSame(2048, $global['bits']);
                $filter = $this->shardFilter(array_map(function ($v) { return 'c' . $v . str_repeat('-', 2500); }, range(0, 39)), 2048);
                $this->assertSame(array_fill(0, 4, $filter), $filters);
            }
            foreach ([0, 17, 39] as $v) {
                $this->assertSame(range($v, 399, 40), array_column($db->find('fd', ['c' => 'c' . $v . str_repeat('-', 2500)]), 'key'), "$layout: c$v");
            }
        }
    }

    /**
     * @test Reading a paged file whole, and dropping a database, leave no garbage cycles: nothing waits for
     * PHP's cycle collector (a recursive closure over the file's bytes kept them allocated until it ran)
     */
    public function wholeReadsAndDropsLeaveNoGarbageCycles(): void
    {
        $db = $this->db(['shardSize' => 500, 'cacheMemoryLimit' => 0]);
        $rows = [];
        for ($i = 0; $i < 3000; $i++) {
            $rows[] = ['u' => 'u' . $i, 'g' => 'g' . ($i % 40)];
        }
        $db->insert('fg', $rows);
        $db->createFieldIndex('fg', 'u');
        $db->createFieldIndex('fg', 'g');
        $db->insert('fd', [['a' => 1]]);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 500, 'cacheMemoryLimit' => 0]);
        $db->find('fg', ['key' => 0]); // the process's first operation
        gc_collect_cycles();
        $this->assertSame([2500], array_column($db->find('fg', ['u' => 'u2500']), 'key')); // .fx loaded whole
        $this->assertCount(75, $db->find('fg', ['g' => 'g7']));
        $this->assertSame(0, gc_collect_cycles(), 'whole reads of paged files');
        $this->assertTrue($db->dropDB('fd')['success']);
        $this->assertSame(0, gc_collect_cycles(), 'a drop');
    }

    /** @test §6.5.1: a large index gets more directory levels; a lookup still reads one page per level */
    public function aLargeIndexHasMoreLevels(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        // Values of 200 bytes: entries of 211 bytes, 19 per page; 1000 values = 53 data pages, 3 directory
        // pages, a root
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = ['u' => str_pad((string)$i, 200, '-', STR_PAD_LEFT)];
        }
        $db->insert('fl', $rows);
        $db->createFieldIndex('fl', 'u');
        [$header, $map] = $this->readFieldIndexFile($this->dbPath('fl', 'fx', 'u'));
        $this->assertGreaterThanOrEqual(2, $header['pg']['levels'], 'directory pages under a root');
        $this->assertSame($this->modelMap([$this->dbPath('fl', 'data')], 'u'), $map);

        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $before = $this->stats();
        $value = str_pad('123', 200, '-', STR_PAD_LEFT);
        $this->assertSame([123], array_column($db->find('fl', ['u' => $value]), 'key'));
        $after = $this->stats();
        $this->assertSame(1, $after['pointLookups'] - $before['pointLookups'], 'answered by a point lookup');
        $this->assertSame($header['pg']['levels'] + 1, $after['pagesRead'] - $before['pagesRead'], 'one page per level');
        $this->assertNull(Testing::getOf($db, 'fieldIndexCache')[$this->dbPath('fl', 'fx', 'u')] ?? null, 'the whole index was not loaded');
    }

    /**
     * @test §6.5.1 lookup step 4: lines written after the index are applied to a point lookup (an index file
     * above 64 KiB: a smaller one is loaded whole at its first use)
     */
    public function pointLookupsFollowTheNewerLines(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        for ($start = 0; $start < 14000; $start += 2000) { // in batches: the suite's memory
            $db->insert('fn', array_map(function ($i) { return ['c' => 'c' . ($i % 10)]; }, range($start, $start + 1999)));
        }
        $db->createFieldIndex('fn', 'c');
        clearstatcache();
        $this->assertGreaterThan(65536, filesize($this->dbPath('fn', 'fx', 'c')), 'precondition: read by pages');
        $covered = $this->readFieldIndexFile($this->dbPath('fn', 'fx', 'c'))[0]['s'];
        // Written after the index: moved away from c3, deleted from c3, a new c3, moved into c3
        $db->update('fn', [['key' => 3], ['set' => ['c' => 'c4']]]);
        $db->delete('fn', ['key' => 13]);
        $db->insert('fn', ['c' => 'c3']);
        $db->update('fn', [['key' => 4], ['set' => ['c' => 'c3']]]);
        $this->assertSame($covered, $this->readFieldIndexFile($this->dbPath('fn', 'fx', 'c'))[0]['s'], 'writers never rewrite the index');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $before = $this->stats();
        $expected = $this->modelMap([$this->dbPath('fn', 'data')], 'c')['sc3'];
        $this->assertSame($expected, array_column($db->find('fn', ['c' => 'c3']), 'key'));
        $this->assertNotContains(3, $expected);
        $this->assertNotContains(13, $expected);
        $this->assertContains(4, $expected);
        $this->assertContains(14000, $expected);
        $this->assertSame(1, $this->stats()['pointLookups'] - $before['pointLookups']);
    }

    /**
     * @test §6.5.1 "Whole read": an .fx file of at most 64 KiB is loaded whole at its first use in an operation
     * other than the process's first, a larger one is read by pages (point lookups); a process without room
     * under its cache limit keeps the pages
     */
    public function aSmallIndexIsLoadedWholeAtItsFirstUse(): void
    {
        // 30 values of 10 keys each; a value of 3000 bytes puts the file above 64 KiB
        foreach (['small' => 20, 'large' => 3000] as $size => $pad) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => 0]);
            $rows = [];
            for ($i = 0; $i < 300; $i++) {
                $rows[] = ['c' => 'c' . ($i % 30) . str_repeat('-', $pad)];
            }
            $db->insert('fs', $rows);
            $db->createFieldIndex('fs', 'c');
            $fx = $this->dbPath('fs', 'fx', 'c');
            clearstatcache();
            $small = filesize($fx) <= 65536;
            $this->assertSame($size === 'small', $small, "precondition: $size file");
            $value = 'c7' . str_repeat('-', $pad);
            foreach ([true, false] as $room) {
                \noneDB::clearStaticCache();
                $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => $room ? 0 : memory_get_usage() + 8 * 1048576]);
                $label = "$size file, " . ($room ? 'with room' : 'no room');
                $before = $this->stats();
                $this->assertSame(range(7, 299, 30), array_column($db->find('fs', ['c' => $value]), 'key'), $label);
                $whole = $small && $room;
                $this->assertSame($whole, is_array(Testing::getOf($db, 'fieldIndexCache')[$fx] ?? null), "$label: loaded whole");
                $this->assertSame($whole ? 0 : 1, $this->stats()['pointLookups'] - $before['pointLookups'], "$label: point lookups");
            }
        }
        // A process's first operation (a single cold one) reads the pages; its next use loads the file. 1000
        // entries (a file below 64 KiB): the rent-or-buy rule of larger files would wait for 50 operations
        $this->cleanTestDirectory();
        $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => 0]);
        $db->insert('fc', array_map(function ($i) { return ['c' => 'v' . $i]; }, range(0, 999)));
        $db->createFieldIndex('fc', 'c');
        $fx = $this->dbPath('fc', 'fx', 'c');
        clearstatcache();
        $this->assertLessThanOrEqual(65536, filesize($fx), 'precondition: a small file');
        $this->assertSame(1000, $this->readFieldIndexFile($fx)[0]['c']);
        $out = $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false, 'cacheMemoryLimit' => 0]) . ';
            $fx = ' . var_export($fx, true) . ';
            foreach ([7, 8] as $v) {
                $before = \noneDB\Tests\Support\Testing::get("indexStats")["pointLookups"];
                $keys = array_column($db->find("fc", ["c" => "v" . $v]), "key");
                echo json_encode([$keys === [$v], \noneDB\Tests\Support\Testing::get("indexStats")["pointLookups"] - $before,
                    is_array(\noneDB\Tests\Support\Testing::getOf($db, "fieldIndexCache")[$fx] ?? null)]), "\n";
            }');
        $this->assertSame("[true,1,false]\n[true,0,true]", $out, 'first operation: a point lookup; second: loaded whole');
    }

    /**
     * @test §6.5.2: the global index of a sharded database holds a filter of each shard's values, bit-sliced into
     * rows of one byte per shard; its size follows the largest shard
     */
    public function aShardedDatabaseHasAGlobalIndexOfShardFilters(): void
    {
        $db = $this->db(['shardSize' => 500]);
        $rows = [];
        for ($i = 0; $i < 1500; $i++) {
            $rows[] = ['g' => $i < 500 ? 'only0' : ($i % 3 === 0 ? 'every' : 'v' . ($i % 40))];
        }
        $db->insert('fg', $rows);
        $db->createFieldIndex('fg', 'g');
        [$header, $filters] = $this->readGlobalFieldFile($this->dbPath('fg', 'gfx', 'g'));
        $this->assertSame(['gfx', 1, 'g'], [$header['kind'], $header['v'], $header['field']]);
        $this->assertSame([0, 1, 2], array_keys($header['sh']));
        $ids = [];
        foreach ([0, 1, 2] as $shard) {
            $kix = $this->readKeyIndexFile($this->dbPath('fg', 'kix', $shard))['header'];
            $entry = $header['sh'][$shard];
            $this->assertSame([$kix['s'], $kix['e'], $this->crcBefore($this->dbPath('fg', 'data', $shard), $kix['s'])], [$entry['s'], $entry['e'], $entry['h']], "shard $shard");
            // Each shard's own file is local to it
            $ids[$shard] = array_keys($this->modelMap([$this->dbPath('fg', 'data', $shard)], 'g'));
            [, $local] = $this->readFieldIndexFile($this->dbPath('fg', 'fx', 'g', $shard));
            $this->assertSame($this->modelMap([$this->dbPath('fg', 'data', $shard)], 'g'), $local, "shard $shard");
        }
        // The largest shard holds 41 values: 2048 bits (the smallest power of two of at least 1312); 3 shards: 8 wide
        $this->assertSame([1, 41, 41], array_map('count', $ids));
        $this->assertSame([2048, 8], [$header['bits'], $header['width']]);
        $this->assertSame(array_map(function ($shardIds) { return $this->shardFilterOfIds($shardIds, 2048); }, $ids), $filters);

        // A lookup skips the shards whose filter rules the value out
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 500]);
        $this->assertSame(500, count($db->find('fg', ['g' => 'only0'])));
        $expected = array_map(function ($filter) { return $this->filterMayHold($filter, 'only0') ? 'index' : 'skip'; }, $filters);
        $this->assertSame(['index', 'skip', 'skip'], $expected, 'precondition: no other filter answers "may" for it');
        $this->assertSame($expected, array_column($db->query('fg')->where(['g' => 'only0'])->explain()['plan'], 'access'));
    }

    /** @test §6.5: a page whose crc doesn't match, or a file without pages, is never trusted */
    public function aDamagedFileOrOneWithoutPagesIsNeverTrusted(): void
    {
        $cases = [
            'page crc' => function (string $fx) {
                $raw = file_get_contents($fx);
                $at = strpos($raw, "\n") + 20;
                $raw[$at] = chr(ord($raw[$at]) ^ 0x10);
                file_put_contents($fx, $raw);
            },
            'cut body' => function (string $fx) { file_put_contents($fx, substr(file_get_contents($fx), 0, -100)); },
            'a body of JSON, no pages' => function (string $fx) {
                [$header, $map] = $this->readFieldIndexFile($fx);
                unset($header['pg']);
                file_put_contents($fx, $this->sealHeaderLine(json_encode($header)) . "\n" . json_encode($map) . "\n");
            },
        ];
        foreach ($cases as $case => $damage) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardingEnabled' => false]);
            $rows = [];
            for ($i = 0; $i < 2000; $i++) {
                $rows[] = ['c' => 'c' . ($i % 20)];
            }
            $db->insert('fd', $rows);
            $db->createFieldIndex('fd', 'c');
            $model = $this->modelMap([$this->dbPath('fd', 'data')], 'c');
            \noneDB::clearStaticCache();
            $damage($this->dbPath('fd', 'fx', 'c'));
            $db = $this->db(['shardingEnabled' => false]);
            foreach (['c0', 'c7', 'c19'] as $value) {
                $this->assertSame($model['s' . $value], array_column($db->find('fd', ['c' => $value]), 'key'), "$case: $value");
            }
            [$header, $map] = $this->readFieldIndexFile($this->dbPath('fd', 'fx', 'c')); // rebuilt as a valid file
            $this->assertSame($model, $map, $case);
            $this->assertTrue($db->checkIntegrity('fd')['ok'], $case);
        }
    }

    /**
     * @test §6.5.2 "Damage": a row whose crc doesn't match makes the global index invalid, even when its header
     * is valid. The damage below clears one of value-0's probe bits in shard 0's filter: trusted, the row would
     * rule shard 0 out and value-0's keys there would be lost. Lookups stay exact and the file is written again.
     */
    public function aDamagedRowOfTheGlobalIndexIsRebuilt(): void
    {
        $config = ['shardSize' => 500];
        $db = $this->db($config);
        $rows = [];
        for ($i = 0; $i < 1500; $i++) {
            $rows[] = ['g' => 'value-' . ($i % 1000)];
        }
        $db->insert('gd', $rows);
        $db->createFieldIndex('gd', 'g');
        $gfx = $this->dbPath('gd', 'gfx', 'g');
        [$header, $model] = $this->readGlobalFieldFile($gfx);
        [$at, $bit] = $this->globalBitAt($gfx, $header, $this->filterProbes($this->bucketIdOf('value-0'), $header['bits'])[0], 0);
        $raw = (string)file_get_contents($gfx);
        $this->assertNotSame(0, ord($raw[$at]) & $bit, 'precondition: shard 0 holds value-0');
        $raw[$at] = chr(ord($raw[$at]) & ~$bit);
        file_put_contents($gfx, $raw);

        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $scan = $this->db($config + ['fieldIndexEnabled' => false]);
        foreach (['value-0', 'value-499', 'value-500', 'value-998', 'value-999', 'none'] as $value) {
            $this->assertSame(array_column($scan->find('gd', ['g' => $value]), 'key'), array_column($db->find('gd', ['g' => $value]), 'key'), $value);
        }
        $this->assertSame([0, 1000], array_column($db->find('gd', ['g' => 'value-0']), 'key'));
        [, $filters] = $this->readGlobalFieldFile($gfx); // a valid file again
        $this->assertSame($model, $filters);
    }

    /**
     * @test §6.5.2 "Update": a shard's filter changed in a session is written when the session ends; a lookup in
     * the same session before that uses the changed filter, not the file's. Here shard 2 got a new value behind
     * the index (a writer without indexes) and lost its field index file: a read in the session builds it again
     * from the data, so the session's filter of shard 2 holds the new value and its entry covers the new line.
     */
    public function aLookupInTheSessionSeesAFilterTheSessionChanged(): void
    {
        $config = ['shardSize' => 10];
        $db = $this->db($config);
        $db->insert('sf', array_map(function ($n) { return ['g' => 'g' . intdiv($n, 10)]; }, range(0, 39)));
        $db->createFieldIndex('sf', 'g');
        $this->assertSame(1, $this->db($config + ['fieldIndexEnabled' => false])->update('sf', [['key' => 25], ['set' => ['g' => 'new']]])['n']);
        unlink($this->dbPath('sf', 'fx', 'g', 2));
        [, $filters] = $this->readGlobalFieldFile($this->dbPath('sf', 'gfx', 'g'));
        $this->assertFalse($this->filterMayHold($filters[2], 'new'), 'precondition: the file\'s filter of shard 2 doesn\'t hold it');
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $found = [];
        (function () use (&$found) {
            $this->withLock('sf', true, function () use (&$found) {
                $found['g2'] = array_column($this->findUnlocked('sf', ['g' => 'g2']), 'key'); // builds shard 2's index
                $found['new'] = array_column($this->findUnlocked('sf', ['g' => 'new']), 'key');
            });
        })->call(Testing::engine($db));
        $this->assertSame(['g2' => [20, 21, 22, 23, 24, 26, 27, 28, 29], 'new' => [25]], $found);
        [, $filters] = $this->readGlobalFieldFile($this->dbPath('sf', 'gfx', 'g'));
        $this->assertSame($this->shardFilter(['g2', 'new'], 64), $filters[2], 'written when the session ended');
    }

    /**
     * @test §6.5.2 "Damage" and "Update": an update that copies the rows of a damaged file (a row whose crc doesn't
     * match) makes the file again from the shards instead, and leaves no temporary file behind. The damage is in a
     * row only the copy reads.
     */
    public function anUpdateThatMeetsADamagedRowMakesTheFileAgain(): void
    {
        $config = ['shardSize' => 100, 'autoCompactThreshold' => 1000]; // no compaction: it would replace shard 1's data
        $db = $this->db($config);
        $db->insert('ud', array_map(function ($n) { return ['g' => 'g' . ($n % 7)]; }, range(0, 299)));
        $db->createFieldIndex('ud', 'g');
        $gfx = $this->dbPath('ud', 'gfx', 'g');
        [$header] = $this->readGlobalFieldFile($gfx);
        $this->assertSame(256, $header['bits'], 'precondition: seven values per shard');
        [$at, $bit] = $this->globalBitAt($gfx, $header, 5, 1); // shard 1's bit of row 5
        $raw = (string)file_get_contents($gfx);
        $raw[$at] = chr(ord($raw[$at]) ^ $bit);
        file_put_contents($gfx, $raw);
        // This process uses shard 1's field index (read directly, no global lookup), then writes more than 64 KiB
        // of new lines into shard 1: the session checkpoints the index, and its filter goes into the global file,
        // whose other rows are copied
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(range(102, 199, 7), $db->getKeysFromFieldIndex('ud', 'g', 'g4', 1));
        $this->assertSame(100, $db->update('ud', [['key' => range(100, 199)], ['set' => ['pad' => str_repeat('x', 1000)]]])['n']);
        [$after, $filters] = $this->readGlobalFieldFile($gfx);
        $this->assertNotSame($header['w'], $after['w'], 'written again');
        $this->assertSame([0, 1, 2], array_keys($after['sh']));
        $this->assertSame(array_fill(0, 3, $this->shardFilter(['g0', 'g1', 'g2', 'g3', 'g4', 'g5', 'g6'], 256)), $filters, 'made from the shards');
        $this->assertSame([], glob(dirname($gfx) . '/*.tmp*'), 'no temporary file left');
        $this->assertSame(range(4, 299, 7), array_column($this->db($config)->find('ud', ['g' => 'g4']), 'key'));
    }

    /**
     * @test §6.5.2 "Update": the filters of the shards a session changed replaced by copying the file's pages: their
     * columns hold their new filters and every other bit of every row is the old file's, in rows of 1, 2 and 4 bytes. A bit that only a copy
     * keeps (another shard's, set by hand, its page crc made again) shows the file was copied, not made again.
     */
    public function anUpdateReplacesTheShardsColumnAndCopiesEveryOtherBit(): void
    {
        foreach (['1-byte rows' => [3, 8], '2-byte rows' => [12, 16], '4-byte rows' => [20, 32]] as $label => [$shards, $width]) {
            $this->cleanTestDirectory();
            $config = ['shardSize' => 100, 'autoCompactThreshold' => 1000]; // no compaction: it would replace shard 1's data
            $db = $this->db($config);
            $db->insert('uc', array_map(function ($n) { return ['g' => 'g' . ($n % 7) . '-' . intdiv($n, 100)]; }, range(0, 100 * $shards - 1)));
            $db->createFieldIndex('uc', 'g');
            $gfx = $this->dbPath('uc', 'gfx', 'g');
            [$header, $before] = $this->readGlobalFieldFile($gfx);
            $this->assertSame([256, $width, range(0, $shards - 1)], [$header['bits'], $header['width'], array_keys($header['sh'])], "precondition, $label");
            // The last shard's bit of a row its filter leaves clear, set by hand: the page stays valid
            $other = $shards - 1;
            for ($row = 0; (ord($before[$other][intdiv($row, 8)]) >> ($row % 8)) & 1; $row++);
            [$at, $bit] = $this->globalBitAt($gfx, $header, $row, $other);
            $raw = (string)file_get_contents($gfx);
            $raw[$at] = chr(ord($raw[$at]) | $bit);
            $rb = intdiv($width, 8);
            $per = $this->globalPageRows(256, $width);
            $page = strpos($raw, "\n") + 1 + intdiv($row, $per) * ($per * $rb + 4);
            $raw = substr_replace($raw, pack('V', (int)hexdec(hash('crc32b', substr($raw, $page, $per * $rb)))), $page + $per * $rb, 4);
            file_put_contents($gfx, $raw);
            $expected = $before;
            $expected[$other][intdiv($row, 8)] = chr(ord($expected[$other][intdiv($row, 8)]) | (1 << ($row % 8)));
            $this->assertSame($expected, $this->readGlobalFieldFile($gfx)[1], "precondition, $label: a valid file");
            // This process uses the field indexes of shards 1 and 2, then writes more than 64 KiB of new lines into
            // each: the session checkpoints both, and their filters go into the global file, whose rows are copied
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->assertSame(range(104, 199, 7), $db->getKeysFromFieldIndex('uc', 'g', 'g6-1', 1), $label);
            $this->assertSame(range(206, 299, 7), $db->getKeysFromFieldIndex('uc', 'g', 'g3-2', 2), $label);
            $this->assertSame(200, $db->update('uc', [['key' => range(100, 299)], ['set' => ['g' => 'new', 'pad' => str_repeat('x', 1000)]]])['n']);
            [$after, $filters] = $this->readGlobalFieldFile($gfx);
            $this->assertNotSame($header['w'], $after['w'], "$label: written again");
            $expected[1] = $expected[2] = $this->shardFilter(['new'], 256);
            $this->assertSame($expected, $filters, $label);
            $this->assertSame(range(100, 299), array_column($this->db($config)->find('uc', ['g' => 'new']), 'key'), $label);
        }
    }

    /**
     * @test STORAGE_FORMAT.md §6.5.1, §6.5.2 "Update": a checkpoint is the index of the data at `s`, whatever led
     * to it. When the lines since the file moved no key to another value, the new file is the old body under a new
     * header (no body is built); when they made or emptied no bucket, the shard's filter is the one the global
     * index holds (no filter is built, its bits stay). Each file equals the test's own model of the data.
     */
    public function aCheckpointBuildsOnlyWhatTheDataChanged(): void
    {
        foreach (['single layout' => ['shardingEnabled' => false], 'sharded' => ['shardSize' => 500]] as $label => $config) {
            $this->cleanTestDirectory();
            $config += ['autoCompactThreshold' => 1000]; // no compaction: it would make every index again
            $sharded = isset($config['shardSize']);
            $db = $this->db($config);
            $db->insert('cb', array_map(function ($n) { return ['g' => 'g' . ($n % 7), 'pad' => '']; }, range(0, 999)));
            $db->createFieldIndex('cb', 'g');
            $shard = $sharded ? 0 : null;
            $fx = $this->dbPath('cb', 'fx', 'g', $shard);
            $data = $this->dbPath('cb', 'data', $shard);
            $gfx = $sharded ? $this->dbPath('cb', 'gfx', 'g') : null;
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->assertSame(range(3, 999, 7), $db->getKeysFromFieldIndex('cb', 'g', 'g3'), "$label: this process uses the index");
            $update = function (array $keys, array $set) use ($db) { return $db->update('cb', [['key' => $keys], ['set' => $set]])['n']; };
            $every = function (int $from, int $to, int $value) { return array_values(array_filter(range($from, $to), function ($k) use ($value) { return $k % 7 === $value; })); };
            $last = $sharded ? 499 : 999; // the segment's keys: shard 0, or the whole database
            // Each phase writes more than 64 KiB into the segment in one session: [write, records written, bodies built, filters built]
            $phases = [
                // every line keeps its key's value: the same buckets, the same keys
                'another field' => [function () use ($update) { return $update(range(0, 99), ['pad' => str_repeat('x', 1000)]); }, 100, 0, 0],
                // keys of g1 to g2, g1 keeps keys: the same buckets, other keys
                'keys moved' => [function () use ($update, $every) { return $update($every(100, 299, 1), ['g' => 'g2', 'pad' => str_repeat('y', 3000)]); }, 28, 1, 0],
                // a value no record had: a new bucket
                'a new value' => [function () use ($update) { return $update(range(400, 419), ['g' => 'new', 'pad' => str_repeat('z', 4000)]); }, 20, 1, 1],
                // keys leave their buckets, every bucket keeps others: no key added
                'a field removed' => [function () use ($db) { return $db->query('cb')->where(['key' => range(0, 69)])->removeFields(['g'])['n']; }, 70, 1, 0],
                // the same buckets as the file the last phase wrote
                'another field again' => [function () use ($update) { return $update(range(300, 399), ['pad' => str_repeat('w', 1000)]); }, 100, 0, 0],
                // another valid file put in its place (with a value no record has): its body is not the process's
                'a file replaced behind it' => [function () use ($update, $fx) {
                    [$header, $map] = $this->readFieldIndexFile($fx);
                    $this->writeFieldIndexFile($fx, $header, $map + ['snone' => 1]);
                    return $update(range(0, 99), ['pad' => str_repeat('u', 1000)]);
                }, 100, 1, 0],
                // the last keys of g5 to g6: one bucket less, none new
                'a value emptied' => [function () use ($update, $every, $last) { return $update($every(70, $last, 5), ['g' => 'g6', 'pad' => str_repeat('v', 1200)]); },
                    count($every(70, $last, 5)), 1, 1],
            ];
            foreach ($phases as $phase => [$write, $written, $bodies, $filters]) {
                clearstatcache();
                [$before, $raw] = [$this->readFieldIndexFile($fx)[0], (string)file_get_contents($fx)];
                $globalBefore = $sharded ? $this->readGlobalFieldFile($gfx) : null;
                $stats = Testing::get('indexStats');
                $this->assertSame($written, $write(), "$label, $phase");
                clearstatcache();
                $after = $this->readFieldIndexFile($fx)[0];
                $this->assertSame(filesize($data), $after['s'], "$label, $phase: checkpointed at the end of the session");
                $this->assertNotSame($before['w'], $after['w'], "$label, $phase: a new file");
                [, $map] = $this->readFieldIndexFile($fx);
                $this->assertSame($this->modelMap([$data], 'g'), $map, "$label, $phase: the index of the data");
                $built = Testing::get('indexStats');
                $this->assertSame($bodies, $built['bodiesBuilt'] - $stats['bodiesBuilt'], "$label, $phase: bodies built");
                if ($bodies === 0) {
                    $body = function (string $file) { return substr($file, strpos($file, "\n") + 1); };
                    $this->assertSame($body($raw), $body((string)file_get_contents($fx)), "$label, $phase: the old body");
                    $this->assertSame([$before['pg'], $before['c'], $before['n']], [$after['pg'], $after['c'], $after['n']], "$label, $phase");
                }
                if ($sharded) {
                    $this->assertSame($filters, $built['filtersBuilt'] - $stats['filtersBuilt'], "$label, $phase: filters built");
                    [$header, $columns] = $this->readGlobalFieldFile($gfx);
                    $this->assertSame(['s' => $after['s'], 'e' => $after['e'], 'h' => $after['h']], $header['sh'][0], "$label, $phase: the shard's new entry");
                    $this->assertSame($globalBefore[0]['sh'][1], $header['sh'][1], "$label, $phase: the other shard's entry");
                    $this->assertSame([$this->shardFilterOfIds(array_keys($map), $header['bits']), $globalBefore[1][1]], [$columns[0], $columns[1]], "$label, $phase: the filters");
                }
            }
            $this->assertArrayNotHasKey('sg5', $map, "$label: g5 is gone from the shard");
            $plain = $this->db($config + ['fieldIndexEnabled' => false]);
            foreach (['g1', 'g2', 'new', 'g5', 'g6'] as $value) {
                $this->assertSame(array_column($plain->find('cb', ['g' => $value]), 'key'), array_column($db->find('cb', ['g' => $value]), 'key'), "$label: $value");
            }
        }
    }

    /**
     * @test §6.5.2 "Lookup": a reader in another process replaces the global index (a checkpoint under the shared
     * lock, no new generation) while this process holds the old header. The new file has eight times the rows:
     * probes worked out with the old size would read rows of the new file that belong to other bytes of the
     * filters, and a shard could be ruled out wrongly. The lookup reads the header again and follows the new one.
     */
    public function rowsOfAReplacedFileAreNeverReadWithTheOldHeader(): void
    {
        $config = ['shardSize' => 1000];
        $db = $this->db($config);
        $rows = [];
        for ($i = 0; $i < 4000; $i++) {
            $rows[] = ['u' => sprintf('s%d-%d', intdiv($i, 1000), $i % 10)]; // shards 0-3, ten values each
        }
        $db->insert('rp', $rows);
        $line = function ($prefix, $i) { return ['u' => sprintf('%s%04d', $prefix, $i), 'pad' => str_repeat('x', 120)]; };
        $db->insert('rp', array_map(function ($i) use ($line) { return $line('b', $i); }, range(0, 7))); // shard 4
        $db->createFieldIndex('rp', 'u');
        $gfx = $this->dbPath('rp', 'gfx', 'u');
        [$old] = $this->readGlobalFieldFile($gfx);
        $this->assertSame(512, $old['bits'], 'precondition: ten values in the largest shard');
        // A writer that never reads (no index loaded) appends more than 64 KiB to shard 4
        \noneDB::clearStaticCache();
        $this->db($config)->insert('rp', array_map(function ($i) use ($line) { return $line('a', $i); }, range(0, 499)));
        $this->assertSame($old['w'], $this->readGlobalFieldFile($gfx)[0]['w'], 'precondition: the writer left the global file');

        // This process reads the global header. Its cache limit leaves no room for the exact map or the whole body:
        // its lookups read rows from the file
        \noneDB::clearStaticCache();
        $a = $this->db($config + ['cacheMemoryLimit' => memory_get_usage() + 1048576]);
        $this->assertSame(range(5, 995, 10), array_column($a->find('rp', ['u' => 's0-5']), 'key'));
        $state = $this->lockState('rp');

        // Another process reads shard 4: its long tail makes it write the shard's checkpoint and the global file
        $out = $this->runChild('$db = ' . $this->childDb($config) . '; echo json_encode(array_column($db->find("rp", ["u" => "a0003"]), "key"));');
        $this->assertSame('[4011]', $out);
        [$new, $filters] = $this->readGlobalFieldFile($gfx);
        $this->assertNotSame($old['w'], $new['w'], 'precondition: the global file was replaced');
        $this->assertSame(16384, $new['bits'], 'precondition: 508 values in shard 4, more than 512 bits allow: made again with 16384');
        $this->assertTrue($this->filterMayHold($filters[4], 'a0003'), 'precondition: with the new values');
        $this->assertSame($state, $this->lockState('rp'), 'precondition: no new generation or epoch');

        $scan = $this->db($config + ['fieldIndexEnabled' => false, 'cacheMemoryLimit' => memory_get_usage() + 1048576]);
        foreach (['s3-5', 's2-0', 's0-5', 'a0100', 'a0499', 'b0005', 'none'] as $value) {
            $this->assertSame(array_column($scan->find('rp', ['u' => $value]), 'key'), array_column($a->find('rp', ['u' => $value]), 'key'), $value);
        }
        $this->assertArrayNotHasKey($gfx . '#hot', array_filter(Testing::get('staticFieldIndexCache')), 'precondition: no exact map in this process');
        // The new file is valid: followed, not taken for damage (which would make it again)
        $this->assertSame($new['w'], $this->readGlobalFieldFile($gfx)[0]['w'], 'the file the other reader wrote is used as it is');
    }

    /**
     * @test §6.5.2 "Lookup": from the global index's second operation, a process with room for it answers from an
     * exact map of the shards' values (built from their field indexes) and one without room from the filters; both
     * answer as a scan, also for values only newer lines hold and for a shard another process compacted
     */
    public function warmLookupsUseAnExactMapWhenMemoryAllowsAndTheFiltersElse(): void
    {
        $config = ['shardSize' => 100];
        $db = $this->db($config);
        $db->insert('hm', array_map(function ($n) { return ['u' => 'u' . $n, 'g' => 'g' . ($n % 9)]; }, range(0, 799)));
        $db->createFieldIndex('hm', 'u');
        $gfx = $this->dbPath('hm', 'gfx', 'u');
        $values = ['u0', 'u99', 'u100', 'u450', 'u799', 'none', 'u-1'];
        foreach (['an exact map' => 0, 'the filters' => memory_get_usage() + 1048576] as $label => $limit) {
            \noneDB::clearStaticCache();
            $a = $this->db($config + ['cacheMemoryLimit' => $limit]);
            $scan = $this->db($config + ['fieldIndexEnabled' => false, 'cacheMemoryLimit' => $limit]);
            $this->assertSame([7], array_column($a->find('hm', ['u' => 'u7']), 'key'), "$label: first operation");
            $this->assertArrayNotHasKey($gfx . '#hot', Testing::get('staticFieldIndexCache'), "$label: no map at the first operation");
            foreach ($values as $value) {
                $this->assertSame(array_column($scan->find('hm', ['u' => $value]), 'key'), array_column($a->find('hm', ['u' => $value]), 'key'), "$label: $value");
            }
            $this->assertSame($label === 'an exact map', is_array(Testing::get('staticFieldIndexCache')[$gfx . '#hot'] ?? null), "$label: the map");
            // A value only newer lines hold (another process wrote it, no checkpoint), and a shard compacted by another process
            $this->assertSame('{"n":1}', $this->runChild('$db = ' . $this->childDb($config) . '; echo json_encode($db->update("hm", [["key" => 5], ["set" => ["u" => "moved"]]]));'));
            $this->assertSame([5], array_column($a->find('hm', ['u' => 'moved']), 'key'), "$label: a newer line");
            $this->assertSame([], $a->find('hm', ['u' => 'u5']), "$label: replaced by the newer line");
            $this->assertSame('true', $this->runChild('$db = ' . $this->childDb($config) . '; echo json_encode($db->compact("hm")["success"]);'));
            foreach (array_merge($values, ['moved', 'u5']) as $value) {
                $this->assertSame(array_column($scan->find('hm', ['u' => $value]), 'key'), array_column($a->find('hm', ['u' => $value]), 'key'), "$label, compacted: $value");
            }
            $this->assertSame(1, $this->db($config)->update('hm', [['key' => 5], ['set' => ['u' => 'u5']]])['n']);
        }
    }

    /**
     * @test §6.5.2: a global index is made from the shards in parts of whole pages, each within a quarter of the
     * cache limit (docs/CONFIGURATION.md "Memory of large databases"). The file made in four parts is the file made
     * at once, byte for byte after the write token; lookups answer as a scan.
     */
    public function aGlobalIndexIsMadeInPartsUnderACacheLimit(): void
    {
        // 20 shards of 100 unique values: 4096 bits, width 32 (4-byte rows), pages of 1024 rows (4 KiB)
        $config = ['shardSize' => 100];
        $this->db($config)->insert('gp', array_map(function ($n) { return ['u' => 'u' . $n]; }, range(0, 1999)));
        $this->db($config)->createFieldIndex('gp', 'u');
        $gfx = $this->dbPath('gp', 'gfx', 'u');
        $made = [];
        foreach (['at once' => 0, 'in parts' => 16384] as $label => $limit) { // 16 KiB: a part of one page
            unlink($gfx);
            \noneDB::clearStaticCache();
            $db = $this->db($config + ['cacheMemoryLimit' => $limit]);
            $this->assertSame([1234], array_column($db->find('gp', ['u' => 'u1234']), 'key'), "$label: the lookup that makes it");
            [$header] = $this->readGlobalFieldFile($gfx);
            $this->assertSame([4096, 32], [$header['bits'], $header['width']], "precondition, $label");
            $raw = (string)file_get_contents($gfx);
            $made[$label] = [array_diff_key($header, ['w' => 0]), substr($raw, strpos($raw, "\n"))];
        }
        $this->assertSame($made['at once'], $made['in parts']);
        $scan = $this->db($config + ['fieldIndexEnabled' => false]);
        foreach (['u0', 'u99', 'u100', 'u1999', 'none'] as $value) {
            $this->assertSame(array_column($scan->find('gp', ['u' => $value]), 'key'), array_column($db->find('gp', ['u' => $value]), 'key'), $value);
        }
    }

    /**
     * @test §6.5.1, §6.5.2 "Exact map": a shard's field index file that doesn't fit its data (its h is not the crc of
     * the bytes it says it covers) is never used; the exact map gives that shard no entry and the shard is read.
     * Within one lock epoch the map's entries are trusted as they are, so a file taken in would rule the shard
     * out for a value the file lacks.
     */
    public function anExactMapLeavesOutAShardFileThatDoesNotFitItsData(): void
    {
        $config = ['shardSize' => 100];
        $db = $this->db($config);
        $db->insert('nf', array_map(function ($n) { return ['u' => 'u' . $n]; }, range(0, 399))); // 4 shards
        $db->createFieldIndex('nf', 'u');
        // Shard 2's file without u250 (counters to match) and with another h: it doesn't fit the data
        $fx = $this->dbPath('nf', 'fx', 'u', 2);
        [$header, $map] = $this->readFieldIndexFile($fx);
        unset($map[$this->bucketIdOf('u250')]);
        $header['h'] = substr($header['h'], 0, -1) . ($header['h'][7] === '0' ? '1' : '0');
        $header['c']--;
        $header['n']--;
        $this->writeFieldIndexFile($fx, $header, $map);
        $gfx = $this->dbPath('nf', 'gfx', 'u');
        \noneDB::clearStaticCache();
        $a = $this->db($config + ['cacheMemoryLimit' => 0]);
        $this->assertSame([7], array_column($a->find('nf', ['u' => 'u7']), 'key'), 'first operation: the filters');
        // The map is built without shard 2, so the lookup reads shard 2, whose file is made again from the data
        $this->assertSame([150], array_column($a->find('nf', ['u' => 'u150']), 'key'), 'second operation: the map is built');
        $hot = Testing::get('staticFieldIndexCache')[$gfx . '#hot'] ?? null;
        $this->assertIsArray($hot, 'precondition: an exact map');
        [$made] = $this->readFieldIndexFile($fx);
        $this->assertNotSame($header['h'], $made['h'], 'shard 2\'s file was made again');
        $this->assertSame(['s' => $made['s'], 'e' => $made['e'], 'h' => $made['h']], $hot['sh'][2], 'the map\'s entry for shard 2 is the new file\'s');
        $this->assertSame([250], array_column($a->find('nf', ['u' => 'u250']), 'key'), 'a value the old file lacked');
    }

    /**
     * @test §6.5.2 "Exact map": the map is of the bucket ids of the shards' files. A file whose buckets this process
     * holds exactly as they are in it (read whole, nothing changed since) is not decoded again; the others are, a
     * held index that changed since its file too. The map and its entries are the same in every case.
     */
    public function anExactMapTakesTheBucketsThisProcessHoldsOfAFile(): void
    {
        $config = ['shardSize' => 100, 'cacheMemoryLimit' => 0]; // room for the map whatever the process holds
        $this->db($config)->insert('mh', array_map(function ($n) { return ['g' => 'g' . ($n % 7) . '-' . intdiv($n, 100)]; }, range(0, 299)));
        $this->db($config)->createFieldIndex('mh', 'g');
        $gfx = $this->dbPath('mh', 'gfx', 'g');
        $model = [];
        $entries = [];
        foreach ([0, 1, 2] as $shard) {
            [$header, $map] = $this->readFieldIndexFile($this->dbPath('mh', 'fx', 'g', $shard));
            foreach (array_keys($map) as $id) {
                $model[$id] = $shard;
            }
            $entries[$shard] = ['s' => $header['s'], 'e' => $header['e'], 'h' => $header['h']];
        }
        foreach (['none held' => [[], false], 'shard 1 held' => [[1], false], 'shard 1 held, changed since' => [[1], true]] as $label => [$held, $changed]) {
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->assertSame(300, $db->count('mh'), $label); // the process's first operation
            foreach ($held as $shard) {
                // a file of at most 64 KiB is read whole at its first use after the first operation (§6.5.1)
                $this->assertCount(14, $db->getKeysFromFieldIndex('mh', 'g', 'g0-' . $shard, $shard), "$label: shard $shard's index");
            }
            if ($changed) {
                // a new value in shard 1 (too little for a new checkpoint), which the held index takes at its next read
                $this->assertSame(1, $db->update('mh', [['key' => 150], ['set' => ['g' => 'extra']]])['n'], $label);
                $this->assertSame([150], $db->getKeysFromFieldIndex('mh', 'g', 'extra', 1), $label);
            }
            $decoded = Testing::get('indexStats')['bodiesDecoded'];
            // A value no shard holds: the filters, then the map, answer without reading any shard
            $this->assertSame([], $db->find('mh', ['g' => 'none']), $label);
            $this->assertSame([], $db->find('mh', ['g' => 'none']), "$label: the map is built");
            $hot = Testing::get('staticFieldIndexCache')[$gfx . '#hot'] ?? null;
            $this->assertIsArray($hot, "$label: the map");
            $this->assertSame([$model, $entries], [$hot['map'], $hot['sh']], $label);
            $this->assertSame($changed ? 3 : 3 - count($held), Testing::get('indexStats')['bodiesDecoded'] - $decoded, "$label: bodies decoded for it");
        }
    }

    /**
     * @test §6.5.2 "Exact map": the file's sizes bound the map (at most `bits / 32` values per shard entry, 32 bytes a
     * pair). A map whose bound is over the room left under half of `cacheMemoryLimit` is not built, and no shard's
     * `.fx` is read for it: a process never spends seconds reading files for a map it then drops. Answers stay a scan's.
     */
    public function anExactMapThatCannotFitIsNotBuiltAndReadsNoShard(): void
    {
        $config = ['shardSize' => 100, 'cacheMemoryLimit' => 0];
        // 8 shards, every value in every shard: 100 per shard (bits 4096: at most 8 × 128 pairs, 32 KiB)
        $this->db($config)->insert('nf', array_map(function ($n) { return ['g' => 'g' . ($n % 100)]; }, range(0, 799)));
        $this->db($config)->createFieldIndex('nf', 'g');
        $gfx = $this->dbPath('nf', 'gfx', 'g');
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(800, $db->count('nf')); // the process's first operation
        try {
            // Room for 16 KiB at the global index's second operation: half the limit is this process's memory and 16 KiB
            Testing::set('cacheMemoryLimit', 2 * (memory_get_usage() + 16384));
            $decoded = Testing::get('indexStats')['bodiesDecoded'];
            $this->assertSame([], $db->find('nf', ['g' => 'none']));
            $this->assertSame([], $db->find('nf', ['g' => 'none']), 'the second operation of the global index');
            $this->assertSame(0, Testing::get('indexStats')['bodiesDecoded'] - $decoded, 'no shard read for a map that cannot fit');
            $this->assertNull(Testing::get('staticFieldIndexCache')[$gfx . '#hot'] ?? null, 'no map');
            $this->assertSame(range(7, 799, 100), array_column($db->find('nf', ['g' => 'g7']), 'key'), 'the answer');
        } finally {
            Testing::set('cacheMemoryLimit', null);
        }
    }

    /**
     * @test §6.5.2 "Exact map": a process keeps the map only with room for it once it is built. The file's sizes bound
     * it at about 32 bytes a pair, which long values pass by far, so the bound alone doesn't keep that promise: the
     * build stops at the shard that fills the room, reads no further shard and leaves no map. Answers stay a scan's.
     */
    public function anExactMapThatOutgrowsItsRoomIsDroppedWhileItIsBuilt(): void
    {
        $config = ['shardSize' => 100, 'cacheMemoryLimit' => 0];
        $value = function (int $n): string { return str_pad('v' . $n, 2048, '.'); };
        // 8 shards of 100 values of 2 KiB: the bound is 8 × 4096 bytes, one shard's values alone are 200 KiB
        $this->db($config)->insert('ov', array_map(function ($n) use ($value) { return ['v' => $value($n)]; }, range(0, 799)));
        $this->db($config)->createFieldIndex('ov', 'v');
        $gfx = $this->dbPath('ov', 'gfx', 'v');
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(800, $db->count('ov')); // the process's first operation
        try {
            // Room for 64 KiB at the global index's second operation: the bound of 32 KiB fits
            Testing::set('cacheMemoryLimit', 2 * (memory_get_usage() + 65536));
            $decoded = Testing::get('indexStats')['bodiesDecoded'];
            $this->assertSame([], $db->find('ov', ['v' => 'none']));
            $this->assertSame([], $db->find('ov', ['v' => 'none']), 'the second operation of the global index');
            $this->assertSame(1, Testing::get('indexStats')['bodiesDecoded'] - $decoded, 'the first shard fills the room, no other is read');
            $cache = Testing::get('staticFieldIndexCache');
            $this->assertArrayHasKey($gfx . '#hot', $cache, 'the build ran');
            $this->assertNull($cache[$gfx . '#hot'], 'no map');
            foreach ([0, 99, 100, 450, 799] as $n) {
                $this->assertSame([$n], array_column($db->find('ov', ['v' => $value($n)]), 'key'), "the answer for $n");
            }
        } finally {
            Testing::set('cacheMemoryLimit', null);
        }
    }

    /**
     * @test §6.5.2 "Exact map": made only for a database of at most a million records. Making it reads every shard's
     * `.fx` whole, which takes seconds at ten million (in a process's second operation: a web request with two queries).
     * Above the limit the filters answer and nothing is read for a map. Shown with the limit lowered (a test seam): 800
     * records over a limit of 799, then at one of 800
     */
    public function anExactMapIsMadeOnlyForADatabaseOfAtMostAMillionRecords(): void
    {
        $config = ['shardSize' => 100, 'cacheMemoryLimit' => 0];
        $this->db($config)->insert('mr', array_map(function ($n) { return ['g' => 'g' . ($n % 7) . '-' . intdiv($n, 100)]; }, range(0, 799)));
        $this->db($config)->createFieldIndex('mr', 'g');
        $gfx = $this->dbPath('mr', 'gfx', 'g');
        try {
            foreach ([799 => false, 800 => true] as $limit => $made) {
                \noneDB::clearStaticCache();
                Testing::set('exactMapRecords', $limit);
                $db = $this->db($config);
                $this->assertSame(800, $db->count('mr'), "limit $limit");
                $decoded = Testing::get('indexStats')['bodiesDecoded'];
                $this->assertSame([], $db->find('mr', ['g' => 'none']));
                $this->assertSame([], $db->find('mr', ['g' => 'none']), "limit $limit: the second operation of the global index");
                $this->assertSame($made, is_array(Testing::get('staticFieldIndexCache')[$gfx . '#hot'] ?? null), "limit $limit: the map");
                if (!$made) {
                    $this->assertSame(0, Testing::get('indexStats')['bodiesDecoded'] - $decoded, "limit $limit: nothing read for a map");
                }
                $this->assertSame(range(0, 99, 7), array_column($db->find('mr', ['g' => 'g0-0']), 'key'), "limit $limit: the answer");
            }
        } finally {
            Testing::set('exactMapRecords', null);
        }
    }

    /**
     * @test §6.5.2 "Exact map": made only in PHP's command line. A web server's request ends with its statics, so a map
     * made for it dies before it can pay: the second indexed query of a request reads no shard's `.fx` for one. Every other
     * SAPI behaves the same; 'cli' makes it. The answers don't depend on it. Shown with the SAPI set by a test seam
     */
    public function aProcessOfAWebServerMakesNoExactMap(): void
    {
        $config = ['shardSize' => 100, 'cacheMemoryLimit' => 0];
        $this->db($config)->insert('sa', array_map(function ($n) { return ['g' => 'g' . ($n % 7) . '-' . intdiv($n, 100)]; }, range(0, 799)));
        $this->db($config)->createFieldIndex('sa', 'g');
        $gfx = $this->dbPath('sa', 'gfx', 'g');
        try {
            foreach (['fpm-fcgi' => false, 'apache2handler' => false, 'cgi-fcgi' => false, 'cli-server' => false, 'frankenphp' => false, 'cli' => true] as $sapi => $made) {
                \noneDB::clearStaticCache();
                Testing::set('sapi', $sapi);
                $db = $this->db($config);
                $this->assertSame(800, $db->count('sa'), $sapi);
                $decoded = Testing::get('indexStats')['bodiesDecoded'];
                $this->assertSame([], $db->find('sa', ['g' => 'none']));
                $this->assertSame([], $db->find('sa', ['g' => 'none']), "$sapi: the second operation of the global index");
                $this->assertSame($made, is_array(Testing::get('staticFieldIndexCache')[$gfx . '#hot'] ?? null), "$sapi: the map");
                if (!$made) {
                    $this->assertSame(0, Testing::get('indexStats')['bodiesDecoded'] - $decoded, "$sapi: nothing read for a map");
                }
                $this->assertSame(range(0, 99, 7), array_column($db->find('sa', ['g' => 'g0-0']), 'key'), "$sapi: the answer");
            }
        } finally {
            Testing::set('sapi', null);
        }
    }

    /**
     * @test §6.5.2 "Lookup": a process with no map to come, holding the file's body, answers lookups from the body, in
     * every case as a scan: values of one shard, of many, of none, written later by this process and by another one. A
     * filter this session replaced (`cols`) goes before the body's bits. A process whose map is still possible (its
     * second operation was a join's) doesn't take this way: the body held is no answer to "no map"
     */
    public function aHeldBodyAnswersLookupsAsAScanWhenNoMapWillCome(): void
    {
        $config = ['shardSize' => 100, 'cacheMemoryLimit' => 0];
        $db = $this->db($config);
        $db->insert('hb', array_map(function ($n) { return ['u' => 'u' . $n, 'g' => 'g' . ($n % 300)]; }, range(0, 799)));
        $db->createFieldIndex('hb', 'u');
        $db->createFieldIndex('hb', 'g');
        $gfx = $this->dbPath('hb', 'gfx', 'u');
        $values = [['u', 'u0'], ['u', 'u450'], ['u', 'none'], ['g', 'g7'], ['g', 'g299'], ['g', 'none']];
        try {
            \noneDB::clearStaticCache();
            Testing::set('sapi', 'fpm-fcgi');
            $a = $this->db($config);
            $scan = $this->db($config + ['fieldIndexEnabled' => false]);
            $a->find('hb', ['u' => 'u7']);
            $a->find('hb', ['u' => 'u8']);
            $held = Testing::get('staticFieldIndexCache')[$gfx] ?? null;
            $this->assertArrayHasKey('body', (array)$held, 'precondition: the body is held from the second operation');
            $before = Testing::get('indexStats')['bodyLookups'];
            $this->assertSame([9], array_column($a->find('hb', ['u' => 'u9']), 'key'));
            $this->assertSame(1, Testing::get('indexStats')['bodyLookups'] - $before, 'the lookup was answered from the body');
            $points = Testing::get('indexStats')['pointLookups'];
            $this->assertSame([], $a->find('hb', ['u' => 'none']));
            $this->assertSame($points, Testing::get('indexStats')['pointLookups'], 'a miss answered from the body reads no index');
            foreach ($values as [$field, $value]) {
                $this->assertSame(array_column($scan->find('hb', [$field => $value]), 'key'), array_column($a->find('hb', [$field => $value]), 'key'), "$field $value");
            }
            // Written by another process, then by this one: newer lines, no checkpoint
            $this->assertSame('{"n":1}', $this->runChild('$db = ' . $this->childDb($config) . '; echo json_encode($db->update("hb", [["key" => 5], ["set" => ["u" => "theirs"]]]));'));
            $this->assertSame([5], array_column($a->find('hb', ['u' => 'theirs']), 'key'), 'a line of another process');
            $this->assertSame(1, $a->update('hb', [['key' => 6], ['set' => ['u' => 'mine']]])['n']);
            $this->assertSame([6], array_column($a->find('hb', ['u' => 'mine']), 'key'), 'a line of this process');
            // A filter of this session: the value is in shard 3 by `cols` alone
            \noneDB::clearStaticCache();
            $a = $this->db($config);
            $a->find('hb', ['u' => 'u7']);
            $a->find('hb', ['u' => 'u8']);
            $this->assertSame([0], Testing::call($a, 'targetShardsFromGlobalIndex', 'hb', ['u' => 'u8']));
            $cache = Testing::get('staticFieldIndexCache');
            $this->assertArrayHasKey('body', $cache[$gfx], 'precondition: the body is held');
            $cache[$gfx]['cols'][3] = $this->shardFilterOfIds([$this->bucketIdOf('u8')], $cache[$gfx]['bits']);
            Testing::set('staticFieldIndexCache', $cache);
            $this->assertSame([0, 3], Testing::call($a, 'targetShardsFromGlobalIndex', 'hb', ['u' => 'u8']), 'the filter of this session');
        } finally {
            Testing::set('sapi', null);
        }
        // A second operation that builds nothing (as checkIntegrity() and a snapshot run) loads the body and decides
        // nothing about the map: the next operation makes it
        \noneDB::clearStaticCache();
        $a = $this->db($config);
        $a->find('hb', ['u' => 'u7']);
        Testing::set('noWrites', 1);
        try {
            $this->assertSame([8], array_column($a->find('hb', ['u' => 'u8']), 'key'));
        } finally {
            Testing::set('noWrites', 0);
        }
        $cache = Testing::get('staticFieldIndexCache');
        $this->assertArrayHasKey('body', $cache[$gfx], 'precondition: the body is held');
        $this->assertArrayNotHasKey($gfx . '#hot', $cache, 'precondition: nothing decided about the map');
        $this->assertSame([9], array_column($a->find('hb', ['u' => 'u9']), 'key'));
        $this->assertIsArray(Testing::get('staticFieldIndexCache')[$gfx . '#hot'] ?? null, 'a body held does not stop the map');
    }

    /**
     * @test §6.5.2 "Sizes": `bits` follows the largest `c` of the shards' `.fx`, which their headers hold. Building the
     * global index reads those headers for it and decodes each shard's file once (for its bucket ids), also when the
     * cache keeps none of them (a limit of 1 byte); before, each was decoded twice
     */
    public function theGlobalIndexDecodesEachShardOnce(): void
    {
        $db = $this->db(['shardSize' => 100, 'cacheMemoryLimit' => 1]);
        $db->insert('go', array_map(function ($n) { return ['g' => 'g' . ($n % 50)]; }, range(0, 399)));
        $decoded = Testing::get('indexStats')['bodiesDecoded'];
        $this->assertTrue($db->createFieldIndex('go', 'g')['success']);
        $this->assertSame(4, Testing::get('indexStats')['bodiesDecoded'] - $decoded, 'four shards, one decode each');
        [$header, $filters] = $this->readGlobalFieldFile($this->dbPath('go', 'gfx', 'g'));
        // 50 values in every shard: the smallest power of two of at least 32 × 50
        $this->assertSame([2048, 8], [$header['bits'], $header['width']]);
        $ids = array_map(function ($n) { return $this->bucketIdOf('g' . $n); }, range(0, 49));
        foreach ([0, 1, 2, 3] as $shard) {
            $this->assertSame($this->shardFilterOfIds($ids, 2048), $filters[$shard], "shard $shard's filter");
            // Its entry describes the shard's .fx: that file's s, e and h
            [$fx] = $this->readFieldIndexFile($this->dbPath('go', 'fx', 'g', $shard));
            $this->assertSame(['s' => $fx['s'], 'e' => $fx['e'], 'h' => $fx['h']], $header['sh'][$shard], "shard $shard's entry");
        }
        foreach (['g7', 'g49', 'none'] as $value) {
            $this->assertSame($value === 'none' ? [] : range((int)substr($value, 1), 399, 50), array_column($db->find('go', ['g' => $value]), 'key'), $value);
        }
    }

    /**
     * @test §6.5.2 "Exact map": when this process replaces a shard's filter, its map takes the shard's new entry
     * and values; a value the shard no longer holds may stay listed. Answers stay those of a scan, in key order.
     */
    public function anExactMapFollowsTheShardsThisProcessUpdates(): void
    {
        $config = ['shardSize' => 100, 'cacheMemoryLimit' => 0];
        $db = $this->db($config);
        $db->insert('hm', array_map(function ($n) { return ['u' => 'u' . $n]; }, range(0, 799)));
        $db->createFieldIndex('hm', 'u');
        $gfx = $this->dbPath('hm', 'gfx', 'u');
        \noneDB::clearStaticCache();
        $a = $this->db($config);
        $scan = $this->db($config + ['fieldIndexEnabled' => false]);
        $a->find('hm', ['u' => 'u7']);
        $a->find('hm', ['u' => 'u8']);
        $map = function () use ($gfx) { return Testing::get('staticFieldIndexCache')[$gfx . '#hot'] ?? null; };
        $this->assertIsArray($map(), 'precondition: the map exists from the second operation');
        // u450 (shard 4) gains shards 1 and 0, u700 (shard 7) gains shard 2; the old values leave shards 0-2
        foreach ([150 => 'u450', 5 => 'u450', 250 => 'u700'] as $key => $value) {
            $this->assertSame(1, $a->update('hm', [['key' => $key], ['set' => ['u' => $value]]])['n']);
        }
        $this->assertTrue($a->compact('hm')['success']);
        $values = ['u450', 'u700', 'u5', 'u150', 'u250', 'u0', 'u799', 'none'];
        foreach ([1, 2] as $round) {
            foreach ($values as $value) {
                $this->assertSame(array_column($scan->find('hm', ['u' => $value]), 'key'), array_column($a->find('hm', ['u' => $value]), 'key'), "round $round: $value");
            }
        }
        $hot = $map();
        $this->assertIsArray($hot, 'the map stays');
        foreach ([0, 1, 2] as $shard) {
            $header = $this->readFieldIndexFile($this->dbPath('hm', 'fx', 'u', $shard))[0];
            $this->assertSame(['s' => $header['s'], 'e' => $header['e'], 'h' => $header['h']], $hot['sh'][$shard], "shard $shard: the new entry");
        }
        $this->assertSame([0, 1, 4], $hot['map'][$this->bucketIdOf('u450')], 'u450: its holders, in shard order');
        $this->assertSame([2, 7], $hot['map'][$this->bucketIdOf('u700')], 'u700: its holders, in shard order');
        $this->assertSame(0, $hot['map'][$this->bucketIdOf('u5')], 'u5: still listed for shard 0 (read for it, answers nothing)');
    }

    /**
     * @test §6.5.1 "Whole read": a segment index file above 64 KiB in repeated use is loaded whole once its
     * operations paid for the load (at least two, one per 20 entries), never before, in one segment or in a
     * shard of many; a process without room under its cache limit keeps the pages
     */
    public function aPagedIndexIsLoadedWholeOnceItsLookupsPayForIt(): void
    {
        foreach (['one segment' => ['shardingEnabled' => false], '20 shards' => ['shardSize' => 400]] as $layout => $base) {
            $this->cleanTestDirectory();
            $sharded = isset($base['shardSize']);
            $db = $this->db($base + ['cacheMemoryLimit' => 0]);
            // 8000 records, 400 entries: due at the 20th operation (20 × 20 >= 400). One segment: 400 values
            // of 20 keys each. 20 shards: every value its own, so shard 3 (keys 1200-1599) holds 400 entries.
            // Values of about 200 bytes put each file above 64 KiB (a smaller one is loaded at its first use).
            $pad = str_repeat('-', 200);
            for ($start = 0; $start < 8000; $start += 2000) { // in batches: the suite's memory
                $db->insert('fw', array_map(function ($i) use ($sharded, $pad) {
                    return ['c' => ($sharded ? 'c' . $i : 'c' . ($i % 400)) . $pad];
                }, range($start, $start + 1999)));
            }
            $db->createFieldIndex('fw', 'c');
            $fx = $sharded ? $this->dbPath('fw', 'fx', 'c', 3) : $this->dbPath('fw', 'fx', 'c');
            $this->assertSame(400, $this->readFieldIndexFile($fx)[0]['c']);
            clearstatcache();
            $this->assertGreaterThan(65536, filesize($fx), "precondition, $layout: read by pages");
            $due = 20;
            foreach ([true, false] as $room) {
                \noneDB::clearStaticCache();
                // The setting is process-wide: each instance sets it (0: no limit)
                $db = $this->db($base + ['cacheMemoryLimit' => $room ? 0 : memory_get_usage() + 8 * 1048576]);
                $label = ($room ? 'with room' : 'no room') . ", $layout";
                $loaded = function () use (&$db, $fx) { return is_array(Testing::getOf($db, 'fieldIndexCache')[$fx] ?? null); };
                for ($op = 1; $op <= $due + 1; $op++) {
                    if ($sharded) {
                        $value = 'c' . (1200 + $op) . $pad;
                        $expected = [1200 + $op];
                    } else {
                        $value = 'c' . $op . $pad;
                        $expected = range($op, 7999, 400);
                    }
                    $this->assertSame($expected, array_column($db->find('fw', ['c' => $value]), 'key'), "$label: operation $op");
                    $this->assertSame($room && $op >= $due, $loaded(), "$label: loaded whole after operation $op");
                }
            }
        }
    }

    /**
     * @test §6.5.1 "Body kept as bytes": an .fx above 64 KiB whose body is at most 256 KiB is read whole at a
     * page miss from its second operation (after the header check), and every later page comes from those
     * bytes, crc checked at its first use. The process's first operation, a larger body and a process without
     * room read pages; a replaced file is never read at the old offsets. None of it loads the index whole.
     */
    public function aBodyInUseIsReadWholeAsBytes(): void
    {
        $pad = str_repeat('-', 40);
        $value = function (int $i) use ($pad): string { return 'v' . $i . $pad; };
        // One key per value (2000 entries: the rent-or-buy load waits for 100 operations)
        $build = function (int $count) use ($value): string {
            $this->cleanTestDirectory();
            $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => 0]);
            for ($start = 0; $start < $count; $start += 2000) { // in batches: the suite's memory
                $db->insert('fb', array_map(function ($i) use ($value) { return ['c' => $value($i)]; }, range($start, min($count, $start + 2000) - 1)));
            }
            $db->createFieldIndex('fb', 'c');
            return $this->dbPath('fb', 'fx', 'c');
        };
        $bodyOf = function (string $fx): string {
            $raw = (string)file_get_contents($fx);
            return substr($raw, strpos($raw, "\n") + 1);
        };
        $member = function (int $i) use ($value): string { return json_encode('s' . $value($i)) . ':' . $i; };
        // One find per operation, in a process that starts now: [keys, pages read, bodies read] of each. Each
        // must leave the index unloaded, or with $whole, whether it did goes there.
        $run = function (array $values, $limit, ?array &$whole = null) use ($value): array {
            \noneDB::clearStaticCache();
            $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => $limit === 'room' ? 0 : memory_get_usage() + 8 * 1048576]);
            $fx = $this->dbPath('fb', 'fx', 'c');
            $steps = [];
            foreach ($values as $step => $i) {
                if (is_callable($i)) {
                    $i(); // between two operations
                    continue;
                }
                $before = $this->stats();
                $keys = array_column($db->find('fb', ['c' => $value($i)]), 'key');
                $after = $this->stats();
                $steps[] = [$keys, $after['pagesRead'] - $before['pagesRead'], $after['bodiesRead'] - $before['bodiesRead']];
                $loaded = is_array(Testing::getOf($db, 'fieldIndexCache')[$fx] ?? null);
                if ($whole === null) {
                    $this->assertFalse($loaded, "operation $step: not loaded whole");
                } else {
                    $whole[] = $loaded;
                }
            }
            return $steps;
        };

        $fx = $build(2000);
        [$header] = $this->readFieldIndexFile($fx);
        $body = $bodyOf($fx);
        $this->assertGreaterThan(65536, filesize($fx), 'precondition: read by pages');
        $this->assertLessThanOrEqual(262144, strlen($body), 'precondition: a body of at most 256 KiB');
        $this->assertSame(1, $header['pg']['levels'], 'precondition: data pages under the root');
        $at = array_map(function ($i) use ($body, $member) { return strpos($body, $member($i)); }, [7, 1500, 1999, 1000]);
        sort($at);
        for ($n = 1; $n < count($at); $n++) {
            $this->assertGreaterThan(8192, $at[$n] - $at[$n - 1], 'precondition: the values lie on four different data pages');
        }

        // The first operation: the root and a data page. The second: the body, once. Then nothing is read: a
        // page of the body, the page kept before it was read, a value that isn't there.
        $this->assertSame([[[7], 2, 0], [[1500], 0, 1], [[1999], 0, 0], [[1000], 0, 0], [[7], 0, 0], [[], 0, 0]],
            $run([7, 1500, 1999, 1000, 7, 2500], 'room'), 'with room');
        // Without room: a page per miss, never the body
        $this->assertSame([[[7], 2, 0], [[1500], 1, 0], [[1999], 1, 0], [[7], 0, 0]],
            $run([7, 1500, 1999, 7], 'no room'), 'no room');

        // A damaged page inside the body: its crc is checked at its first use, so the index is never trusted
        // (the data answers, the file is written again)
        $damaged = function () use ($fx, $member): void {
            $raw = (string)file_get_contents($fx);
            $at = strpos($raw, $member(1999));
            $this->assertNotFalse($at);
            $raw = substr_replace($raw, substr($member(1999), 0, -1) . '8', $at, strlen($member(1999))); // :1999 → :1998
            file_put_contents($fx, $raw);
        };
        $whole = [];
        $steps = $run([7, $damaged, 1500, 1999, 1000], 'room', $whole);
        $this->assertSame([false, false], array_slice($whole, 0, 2), 'damaged: pages until the damage is found');
        $this->assertSame([[7], 0], [$steps[0][0], $steps[0][2]], 'damaged: first operation');
        $this->assertSame([[1500], 1], [$steps[1][0], $steps[1][2]], 'damaged: the body read, its page of 1500 sound');
        $this->assertSame([1999], $steps[2][0], 'damaged: the page of 1999 is never trusted');
        $this->assertSame([1000], $steps[3][0]);
        $this->assertSame($this->modelMap([$this->dbPath('fb', 'data')], 'c'), $this->readFieldIndexFile($fx)[1], 'damaged: written again');

        // A file replaced between two operations (another write token, the same offsets): its header doesn't
        // match the one held, so nothing of it is read at the old offsets
        $fx = $build(2000);
        $replaced = function () use ($fx): void {
            $raw = (string)file_get_contents($fx);
            $line = (string)strstr($raw, "\n", true);
            $header = $this->openHeaderLine($fx, $line);
            do { // the same length: the same offsets
                $header['w'] = bin2hex(random_bytes(8));
                $new = $this->sealHeaderLine(json_encode($header));
            } while (strlen($new) !== strlen($line));
            file_put_contents($fx, $new . substr($raw, strlen($line)));
        };
        $steps = $run([7, $replaced, 1500, 1999, 1000, 7], 'room');
        $this->assertSame([[7], [1500], [1999], [1000], [7]], array_column($steps, 0), 'replaced: every answer');
        $this->assertSame(0, $steps[1][2], 'replaced: the old offsets are never read from the new file');

        // A body above 256 KiB: pages, one per level at the first lookup, the missing ones of each later path
        $fx = $build(6000);
        $this->assertGreaterThan(262144, strlen($bodyOf($fx)), 'precondition: a body above 256 KiB');
        $levels = $this->readFieldIndexFile($fx)[0]['pg']['levels'];
        $steps = $run([7, 1500, 5999, 7], 'room');
        $this->assertSame([[7], [1500], [5999], [7]], array_column($steps, 0), 'larger body: every answer');
        $this->assertSame([0, 0, 0, 0], array_column($steps, 2), 'larger body: never the body');
        $this->assertSame($levels + 1, $steps[0][1], 'larger body: one page per level');
        $this->assertGreaterThanOrEqual(1, min($steps[1][1], $steps[2][1]), 'larger body: a page per miss');
        $this->assertSame(0, $steps[3][1], 'larger body: the kept pages');
    }

    /**
     * @test §6.5.1 lookup step 3: a name is found only where a member starts (after `{"` or `,"`), never inside
     * another name or a value that holds its bytes: names with quotes, backslashes, commas, braces, colons,
     * control characters, line terminators and other names' text, in the pages of a process with room (the
     * body kept as bytes from the second operation) and without (pages)
     */
    public function namesThatLookLikeOtherMembersAreNeverMistaken(): void
    {
        $special = ['a', 'sa', 'ssa', 'a":1,"sa', 'x,"sa":', '{"sa":', 'sa":', '"', '""', '"a', '\\', '\\"', 'a\\', 'a\\"',
            '",', '","sa":', "\n", "a\nb", "\0", "\x1f", "\u{2028}", 'ş', '/', 'a/b', '[1,2]', '1', ':', '"sa":[1]',
            'i1', 'n', "\\u0000", 'sa":[3,4],"sb',
            // Right before "b" in bucket id order, on its page: its name's bytes after an escaped quote, after a
            // comma and a letter
            'b', 'a"sb', 'a,ysb'];
        $values = array_merge($special, $special, [1, -1, true, null]); // two keys each: lists of keys
        // 5000 more entries: two rounds of probes (two operations each) stay below the whole load (c / 20)
        for ($i = 0; $i < 5000; $i++) {
            $values[] = sprintf('filler-%04d-', $i) . str_repeat('-', 10);
        }
        $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => 0]);
        $db->insert('fe', array_map(function ($v) { return ['v' => $v]; }, $values));
        $db->createFieldIndex('fe', 'v');
        $fx = $this->dbPath('fe', 'fx', 'v');
        clearstatcache();
        $this->assertGreaterThan(65536, filesize($fx), 'precondition: read by pages');
        $this->assertLessThanOrEqual(262144, filesize($fx), 'precondition: a body kept as bytes from the second operation');
        $this->assertSame($this->modelMap([$this->dbPath('fe', 'data')], 'v'), $this->readFieldIndexFile($fx)[1]);
        $raw = (string)file_get_contents($fx);
        $body = substr($raw, strpos($raw, "\n") + 1);
        $firstPage = strpos($body, '}'); // no name here holds a brace: the end of the first page's object
        foreach (['"sa\\"sb":', '"sa,ysb":', '"sb":'] as $member) {
            $at = strpos($body, $member);
            $this->assertTrue($at !== false && $at < $firstPage, "precondition: $member on the first page");
        }
        $this->assertLessThan(strpos($body, '"sb":'), strpos($body, '"sa\\"sb":'), 'precondition: the decoys come first');
        // The model: the keys of each value (===), in insert order
        $expected = function ($value) use ($values): array { return array_keys(array_filter($values, function ($v) use ($value) { return $v === $value; })); };
        $probes = array_merge($special, [1, -1, true, null, 'filler-0007-' . str_repeat('-', 10), '"sa', 'zz"', '\\\\', 'sa":1', ',"sa":', "\x1e"]);
        foreach (['room' => 0, 'pages' => null] as $mode => $limit) {
            \noneDB::clearStaticCache();
            $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => $limit ?? memory_get_usage() + 8 * 1048576]);
            $before = $this->stats()['bodiesRead'];
            foreach ([1, 2] as $round) {
                foreach ($probes as $value) {
                    $label = "$mode, round $round: " . json_encode($value);
                    $this->assertSame($expected($value), array_column($db->find('fe', ['v' => $value]), 'key'), $label);
                    $candidates = Testing::call($db, 'withLock', 'fe', false, function () use ($db, $value) {
                        return Testing::call($db, 'fieldIndexCandidates', 'fe', null, ['v' => $value]);
                    }, 'read');
                    $this->assertSame($expected($value), $candidates, "$label (index candidates)");
                    $this->assertFalse(is_array(Testing::getOf($db, 'fieldIndexCache')[$fx] ?? null), "$label: answered by pages");
                }
            }
            $this->assertSame($mode === 'room' ? 1 : 0, $this->stats()['bodiesRead'] - $before, "$mode: the body kept as bytes");
        }
    }

    /** @test §6.5.1: an index written from the specification by the test is read as it is */
    public function anIndexWrittenFromTheSpecificationIsRead(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('fw', [['c' => 'a'], ['c' => 'b'], ['c' => 'a']]);
        $db->createFieldIndex('fw', 'c');
        [$header] = $this->readFieldIndexFile($this->dbPath('fw', 'fx', 'c'));
        // Fits the data (s, e, h) but says key 2 holds "b": the index is the index of the bytes it covers
        $this->writeFieldIndexFile($this->dbPath('fw', 'fx', 'c'), $header, ['sa' => 0, 'sb' => [1, 2]]);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame([1], array_column($db->find('fw', ['c' => 'b']), 'key'), 'candidates are checked against the records');
        $this->assertSame([0], array_column($db->find('fw', ['c' => 'a']), 'key'), 'key 2 is not a candidate of "a" any more');
    }

    /**
     * The pages of a body, cut after the members are encoded at once, are those §6.5.1 describes, for every id an
     * encoder can make hard to cut: ids that end in a comma or a backslash, hold quotes and colons, and a bucket
     * larger than a page (under an id that ends in a comma too)
     * @test
     */
    public function pagesAreCutBetweenMembersWhateverTheIds(): void
    {
        $values = [];
        for ($i = 0; $i < 700; $i++) {
            foreach (["$i,", "a,\"b$i", "c\":d$i", "e$i\\", "f$i\\,", "g,\":$i", "ü$i,", "\"$i", ",\"$i\",\"", "h$i\":", "x\ny$i", "k/$i\u{2028}"] as $value) {
                $values[] = $value;
            }
        }
        foreach ([7, -3, 1.5, true, false, null, ''] as $value) {
            $values[] = $value;
        }
        $rows = array_map(function ($value) { return ['v' => $value]; }, $values);
        for ($i = 0; $i < 1500; $i++) {
            $rows[] = ['v' => 'big,'];
            $rows[] = ['v' => 'large'];
        }
        $header = $this->assertPagesAsTheFormatSays('pc', $rows, ['0,', '699,', 'a,"b5', 'c":d5', "e5\\", "f5\\,", 'g,":5', 'ü5,', '"5', ',"5","', 'h5":', "x\ny5", "k/5\u{2028}", 7, -3, 1.5, true, false, null, '', 'big,', 'large', 'none', 'none,']);
        $this->assertGreaterThanOrEqual(1, $header['pg']['levels'], 'precondition: a directory');
    }

    /**
     * Index one database of $rows and compare its .fx body with the same map written by the format's description,
     * page after page; lookups answer as a scan does. Returns the header.
     */
    private function assertPagesAsTheFormatSays(string $dbname, array $rows, array $values): array
    {
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame(count($rows), $db->insert($dbname, $rows)['n']);
        $db->createFieldIndex($dbname, 'v');
        $fx = $this->dbPath($dbname, 'fx', 'v');
        [$header, $map] = $this->readFieldIndexFile($fx);
        $model = $fx . '.model';
        $this->writeFieldIndexFile($model, $header, $map);
        $this->assertSame($this->fxBody($model), $this->fxBody($fx));
        unlink($model);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $scan = $this->db(['shardingEnabled' => false, 'fieldIndexEnabled' => false]);
        foreach ($values as $value) {
            $this->assertSame(array_column($scan->find($dbname, ['v' => $value]), 'key'), array_column($db->find($dbname, ['v' => $value]), 'key'), json_encode($value));
        }
        return $header;
    }

    private function fxBody(string $path): string
    {
        $bytes = (string)file_get_contents($path);
        return substr($bytes, strpos($bytes, "\n") + 1);
    }

    /**
     * Records of one distinct string each, ascending in id order and in key order from $key, whose .fx members
     * `"s{value}":{key}` take exactly $bytes between a page's braces
     */
    private function rowsOfBytes(int $bytes, string $prefix, int $key = 0): array
    {
        $rows = [];
        $left = $bytes + 1; // no comma before the first
        for (;; $key++) {
            $name = $prefix . sprintf('%05d', $key);
            $left -= strlen(',"s' . $name . '":' . $key);
            $last = $left <= 100;
            $pad = $last ? $left : 40;
            $rows[] = ['v' => $name . str_repeat('.', $pad)];
            $left -= $pad;
            if ($last) {
                return $rows;
            }
        }
    }

    /**
     * Space in a data page: `{`, members, `}`, the crc: 4090 bytes of members fill it to its last byte
     * @test
     * @testWith [4090, 0, 4096]
     *           [4091, 1, 4102]
     */
    public function theOnlyMembersFillAPageToItsLastByte(int $bytes, int $levels, int $data): void
    {
        $rows = $this->rowsOfBytes($bytes, 'a');
        $header = $this->assertPagesAsTheFormatSays('pf', $rows, array_column($rows, 'v'));
        $this->assertSame($levels, $header['pg']['levels'], 'one data page needs no directory');
        $this->assertSame($data, $header['pg']['data']);
    }

    /**
     * With more members behind them: the page takes what fits to its last byte, and a byte too many leaves the
     * member for the next page
     * @test
     * @testWith [4090]
     *           [4091]
     */
    public function aPageIsCutAtTheLastMemberThatFits(int $bytes): void
    {
        $rows = $this->rowsOfBytes($bytes, 'a');
        $key = count($rows) - 1;
        $lastMember = strlen(',"s' . $rows[$key]['v'] . '":' . $key);
        $first = strlen('{') + ($bytes > 4090 ? $bytes - $lastMember : $bytes) + strlen('}') + 4;
        $more = $this->rowsOfBytes(500, 'b', count($rows));
        $this->assertPagesAsTheFormatSays('pn', array_merge($rows, $more), array_column(array_merge($rows, $more), 'v'));
        $body = $this->fxBody($this->dbPath('pn', 'fx', 'v'));
        $this->assertSame('}', $body[$first - 5], 'the first page ends here');
        $this->assertSame('{', $body[$first], 'the next page starts here');
        $this->assertSame(pack('V', crc32(substr($body, 0, $first - 4))), substr($body, $first - 4, 4));
    }

    /**
     * A member over a page as the last one, alone or behind others, is a page of its own and is not lost
     * @test
     * @testWith [0, 0]
     *           [40, 1]
     */
    public function aLastMemberOverAPageIsAPageOfItsOwn(int $before, int $levels): void
    {
        $rows = [];
        for ($i = 0; $i < $before; $i++) {
            $rows[] = ['v' => 'a' . $i];
        }
        for ($i = 0; $i < 1500; $i++) {
            $rows[] = ['v' => 'zz'];
        }
        $header = $this->assertPagesAsTheFormatSays('pl', $rows, ['a0', 'a39', 'zz', 'none']);
        $this->assertSame($levels, $header['pg']['levels'], 'one data page needs no directory');
        $this->assertGreaterThan(4090, strlen('"szz":' . json_encode(range($before, $before + 1499))), 'precondition: the member is over a page');
    }

    /**
     * Lookups of a new process, through the file (the process that built an index holds it): the keys of each value
     * @param array<int,string> $values key => value
     */
    private function assertANewProcessFinds(string $db, string $field, array $values): void
    {
        $out = $this->runChild('$db = ' . $this->childDb() . '; $seen = [];'
            . ' foreach (' . var_export($values, true) . ' as $key => $value) {'
            . ' $seen[$key] = array_column($db->find(' . var_export($db, true) . ', [' . var_export($field, true) . ' => $value]), "key"); }'
            . ' echo json_encode($seen, JSON_FORCE_OBJECT), "\n", \noneDB\Tests\Support\Testing::get("indexStats")["pagesRead"];');
        $expected = [];
        foreach ($values as $key => $value) {
            $expected[$key] = [$key];
        }
        [$seen, $pages] = array_pad(explode("\n", $out), 2, 0);
        $this->assertSame($expected, json_decode($seen, true), substr($out, 0, 300));
        $this->assertGreaterThan(0, (int)$pages, 'answered by the file: a scan would give the same keys');
    }

    /**
     * @test A string value has no length limit, so neither has a bucket id: ids over 64 KiB, side by side, one
     * sharing 70 000 bytes with the next, are found through directory pages that pass 64 KiB themselves
     * (§6.5.1: u32 lengths and starts; u16 ones made every lookup of the field fail in every new process)
     * @runInSeparateProcess
     */
    public function idsLongerThanSixtyFourKiBAreFoundThroughTheDirectory(): void
    {
        $values = [];
        for ($i = 0; $i < 1500; $i++) {
            $values[] = sprintf('short-%04d', $i);
        }
        $shared = str_repeat('x', 70000);
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $letter) {
            $values[] = $letter . $shared . 'one';
            $values[] = $letter . $shared . 'two';
            $values[] = 'z' . $letter . str_repeat('y', 66000);
            $values[] = str_pad($letter, 65535, '-');
            $values[] = str_pad($letter, 65536, '+');
        }
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('lg', array_map(function ($value) { return ['v' => $value]; }, $values));
        $this->assertTrue($db->createFieldIndex('lg', 'v')['success']);

        [$header, $map] = $this->readFieldIndexFile($this->dbPath('lg', 'fx', 'v'));
        $this->assertGreaterThan(1, $header['pg']['levels'], 'precondition: a directory above the data pages');
        $this->assertCount(count($values), $map);

        // Every directory page reads as what it holds: a lookup that can't read one answers by a scan, as rightly
        $raw = (string)file_get_contents($this->dbPath('lg', 'fx', 'v'));
        $body = substr($raw, strpos($raw, "\n") + 1);
        $walk = function (int $offset, int $length, int $level) use (&$walk, $body): void {
            if ($level > 0) {
                $children = Testing::callStatic('PagedFile::directory', substr($body, $offset, $length - 4));
                $this->assertIsArray($children, "the directory page at $offset");
                foreach ($children as [, $childOffset, $childLength]) {
                    $walk($childOffset, $childLength, $level - 1);
                }
            }
        };
        $walk($header['pg']['root'][0], $header['pg']['root'][1], $header['pg']['levels']);

        $probe = array_slice($values, 1490, 10, true) + array_slice($values, 1500, null, true);
        $this->assertANewProcessFinds('lg', 'v', $probe);
        $this->assertTrue($db->checkIntegrity('lg')['ok']);
    }

    /**
     * @test An index is built in time linear in its ids, whatever they hold. Two values with many `":`, a text of
     * JSON among them: the first id of a page, found by decoding ever longer prefixes, took 20 s for the first
     */
    public function anIdOfManyQuotesIsBuiltInLinearTime(): void
    {
        $json = [];
        for ($i = 0; strlen(json_encode($json)) < 160000; $i++) {
            $json["field$i"] = $i;
        }
        $values = ['quotes' => str_repeat('":', 80000), 'json' => json_encode($json)];
        foreach ($values as $name => $value) {
            $out = $this->runChild('$db = ' . $this->childDb() . '; $value = ' . var_export($value, true) . ';'
                . ' $db->insert("q' . $name . '", array_merge([["v" => $value]], array_map(function ($i) { return ["v" => "s$i"]; }, range(1, 300))));'
                . ' $start = microtime(true); $db->createFieldIndex("q' . $name . '", "v");'
                . ' echo json_encode([microtime(true) - $start, count($db->find("q' . $name . '", ["v" => $value]))]);');
            $result = json_decode($out, true);
            $this->assertIsArray($result, substr($out, 0, 300));
            $this->assertSame(1, $result[1], "$name: found");
            $this->assertLessThan(2.0, $result[0], "$name: seconds of the build");
        }
    }

    /**
     * @test The first id of every data page is that id, whatever it ends in: a quote or a backslash, escaped in the
     * page, a `":` or a `,"` inside it (the test's codec reads the directory and compares it with the pages)
     */
    public function theDirectoryNamesEveryPageWhateverItsFirstIdHolds(): void
    {
        $tails = ['"', '\\', '\\"', '"\\', '":', '\\":', '",', ',"', ',":', '":1,"', '\\\\', "\n", "\t", '/', 'ş', '€', '😀'];
        $values = [];
        foreach ($tails as $i => $tail) {
            foreach ([700, 1500, 3000, 4090, 4200] as $length) {
                $values[] = sprintf('%02d', $i) . str_repeat('p', $length) . $tail;
            }
        }
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('tl', array_map(function ($value) { return ['v' => $value]; }, $values));
        $this->assertTrue($db->createFieldIndex('tl', 'v')['success']);

        [$header, $map] = $this->readFieldIndexFile($this->dbPath('tl', 'fx', 'v'));
        $this->assertGreaterThan(0, $header['pg']['levels'], 'precondition: more than one data page');
        $this->assertCount(count($values), $map);
        $this->assertANewProcessFinds('tl', 'v', $values);
    }

    /** @test A field name ending in a line feed is no plain name: its file token is the hash, not a name with a line feed */
    public function aFieldNameWithALineFeedGivesNoFileNameWithOne(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('nl', [['abc' => 1, "abc\n" => 2], ['abc' => 3, "abc\n" => 4]]);
        $this->assertTrue($db->createFieldIndex('nl', "abc\n")['success']);
        $this->assertTrue($db->createFieldIndex('nl', 'abc')['success']);
        foreach ($this->dbFiles('nl') as $file) {
            $this->assertStringNotContainsString("\n", $file);
        }
        $this->assertSame(1, $db->find('nl', ["abc\n" => 4])[0]['key']);
        $this->assertSame(1, $db->find('nl', ['abc' => 3])[0]['key']);
    }

    /**
     * @test §6.5.1 fixes the bytes: a writer made from the format alone gives the same body, directory levels included,
     * every page as full as a page of about 4096 bytes lets it be
     * @runInSeparateProcess
     */
    public function aWriterOfTheFormatGivesTheSameBytes(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('by', array_map(function ($i) { return ['v' => sprintf('value-%06d', $i), 'w' => $i % 3]; }, range(0, 59999)));
        $this->assertTrue($db->createFieldIndex('by', 'v')['success']);
        $path = $this->dbPath('by', 'fx', 'v');
        [$header, $map] = $this->readFieldIndexFile($path);
        $this->assertGreaterThan(1, $header['pg']['pages'] - intdiv($header['pg']['data'], 4096), 'precondition: more than one directory page');
        $copy = sys_get_temp_dir() . '/nonedb_fx_' . bin2hex(random_bytes(6));
        $this->writeFieldIndexFile($copy, $header, $map);
        $body = function (string $file): string {
            $raw = (string)file_get_contents($file);
            return substr($raw, strpos($raw, "\n") + 1);
        };
        $same = $body($path) === $body($copy);
        unlink($copy);
        $this->assertTrue($same);
    }

    /**
     * @test §6.5.1: a file of another version is not read, by a lookup or as a whole; it answers as no file does.
     * The same file with its own version is read, in the same process state
     * @runInSeparateProcess
     */
    public function aFileOfAnotherVersionIsNotRead(): void
    {
        $count = function (): int {
            $stats = $this->stats();
            return $stats['pagesRead'] + $stats['bodiesRead'] + $stats['bodiesDecoded'];
        };
        $reads = ['lookup' => function ($db) { return $db->find('vr', ['v' => 'b7777']); },
            'whole read' => function ($db) { return $db->query('vr')->where(['v' => ['$gte' => 'b7777', '$lte' => 'b7777']])->get(); }];
        foreach ($reads as $read => $run) {
            foreach ([false, true] as $relabel) {
                $this->cleanTestDirectory();
                $db = $this->db(['shardingEnabled' => false]);
                $db->insert('vr', array_map(function ($i) { return ['v' => 'b' . $i]; }, range(0, 19999)));
                $this->assertTrue($db->createFieldIndex('vr', 'v')['success']);
                if ($relabel) {
                    $path = $this->dbPath('vr', 'fx', 'v');
                    $raw = (string)file_get_contents($path);
                    $line = (string)strstr($raw, "\n", true);
                    $header = $this->openHeaderLine($path, $line);
                    $this->assertSame(1, $header['v'], 'precondition');
                    $header['v'] = 2;
                    file_put_contents($path, $this->sealHeaderLine(json_encode($header)) . substr($raw, strlen($line)));
                }
                \noneDB::clearStaticCache();
                $db = $this->db(['shardingEnabled' => false]);
                $db->find('vr', ['key' => 0]); // past the process's first lock session
                $before = $count();
                $this->assertSame([7777], array_column($run($db), 'key'), $read);
                if ($relabel) {
                    $this->assertSame($before, $count(), "$read: nothing of the file was read");
                } else {
                    $this->assertGreaterThan($before, $count(), "$read: precondition, the file of its version is read");
                }
            }
        }
    }

    /**
     * @test §6.5.1 a page whose crc fits but whose entry leaves it makes the file invalid: never an error of PHP, the
     * query is answered as without the file. Lengths past the table, past the page, at the end of the page.
     * @runInSeparateProcess
     */
    public function aDirectoryEntryThatLeavesItsPageMakesTheFileInvalid(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('dp', array_map(function ($i) { return ['u' => sprintf('value-%06d', $i)]; }, range(0, 29999)));
        $this->assertTrue($db->createFieldIndex('dp', 'u')['success']);
        $path = $this->dbPath('dp', 'fx', 'u');
        $intact = (string)file_get_contents($path);
        $line = strpos($intact, "\n") + 1;
        $header = json_decode(substr($intact, 0, $line), true);
        $this->assertGreaterThan(0, $header['pg']['levels'], 'precondition: a directory');
        [$offset, $length] = $header['pg']['root'];
        $table = $length - 6 - 4 * unpack('v', $intact, $line + $offset + $length - 6)[1];
        foreach ([$table - 12 + 1, $table - 3, $table, $length, 70000, 4294967295] as $bad) {
            $page = substr_replace(substr($intact, $line + $offset, $length - 4), pack('V', $bad), 0, 4);
            file_put_contents($path, substr_replace($intact, $page . pack('V', crc32($page)), $line + $offset, $length));
            $out = $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false]) . ';'
                . ' echo json_encode(array_column($db->find("dp", ["u" => "value-012345"]), "key"));');
            $this->assertSame('[12345]', $out, "entry length $bad");
        }
    }
}
