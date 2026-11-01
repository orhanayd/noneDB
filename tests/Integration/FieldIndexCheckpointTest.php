<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Field indexes: exact typed buckets, checkpoints that writes don't rewrite (the
 * data lines after them are replayed), file names that never collide, range candidates.
 */
class FieldIndexCheckpointTest extends RegressionTestCase
{
    private function fx(string $name, string $field, ?int $shard = null): string
    {
        return $this->dbPath($name, 'fx', $field, $shard);
    }

    private function header(string $file): array
    {
        $fp = fopen($file, 'rb');
        $line = fgets($fp);
        fclose($fp);
        return json_decode($line, true);
    }

    /** @test */
    public function bucketsAreExactValues(): void
    {
        $values = [1, 1.0, '1', true, -0.0, 0, '0', 0.0, null, false, '', 'a', 2.5, '2.5'];
        foreach ([['shardingEnabled' => false], ['shardSize' => 4]] as $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->createFieldIndex('ty', 'v');
            foreach ($values as $v) {
                $db->insert('ty', ['v' => $v]);
            }
            $plain = $this->db($config + ['fieldIndexEnabled' => false]);
            foreach ($values as $v) {
                $expected = array_column($plain->find('ty', ['v' => $v]), 'key');
                $this->assertSame($expected, array_column($db->find('ty', ['v' => $v]), 'key'), json_encode($v));
                $this->assertSame($expected, $db->getKeysFromFieldIndex('ty', 'v', $v), 'strict: ' . json_encode($v));
            }
            $this->assertSame([0], $db->getKeysFromFieldIndex('ty', 'v', 1), '1 is not "1", 1.0 or true');
            $this->assertSame([4, 7], $db->getKeysFromFieldIndex('ty', 'v', -0.0), '-0.0 === 0.0');
            $created = $this->db($config)->rebuildFieldIndex('ty', 'v');
            $this->assertSame(14, $created['indexed'], 'every record holds an indexable value');
            $this->assertSame([4, 7], $this->db($config)->getKeysFromFieldIndex('ty', 'v', 0.0), 'rebuilt: -0.0 and 0.0 are one value');
        }
    }

    /** @test */
    public function creatingAnIndexAgainRebuildsIt(): void
    {
        // Documented: a second createFieldIndex() rebuilds the index, no duplicate entry
        foreach ([['shardingEnabled' => false], ['shardSize' => 4]] as $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->insert('again', [['c' => 'a'], ['c' => 'b'], ['c' => 'a']]);
            $this->assertTrue($db->createFieldIndex('again', 'c')['success']);
            $db->insert('again', [['c' => 'a'], ['c' => 'c'], ['c' => 'a']]);
            $result = $db->createFieldIndex('again', 'c');
            $this->assertTrue($result['success'], json_encode($result));
            $this->assertSame([6, null], [$result['indexed'], $result['error']]);
            $this->assertSame(isset($config['shardSize']) ? 2 : null, $result['shards'] ?? null, '6 records in shards of 4');
            $this->assertSame(['c'], $db->getFieldIndexes('again')['fields']);
            $this->assertSame([0, 2, 3, 5], $this->db($config)->getKeysFromFieldIndex('again', 'c', 'a'));
        }
    }

    /** @test */
    public function floatBucketsDontDependOnIniSettings(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->createFieldIndex('fl', 'p');
        $db->insert('fl', [['p' => 0.1], ['p' => 0.30000000000000004], ['p' => 28.980345]]);
        $before = [ini_get('precision'), ini_get('serialize_precision')];
        try {
            foreach ([['17', '-1'], ['5', '5'], ['14', '10']] as [$precision, $serialize]) {
                ini_set('precision', $precision);
                ini_set('serialize_precision', $serialize);
                \noneDB::clearStaticCache();
                $db = $this->db(['shardingEnabled' => false]);
                $this->assertSame([0], $db->getKeysFromFieldIndex('fl', 'p', 0.1));
                $this->assertSame([1], $db->getKeysFromFieldIndex('fl', 'p', 0.30000000000000004));
                $this->assertSame([2], array_column($db->find('fl', ['p' => 28.980345]), 'key'));
                $db->createFieldIndex('fl2', 'p');
                $db->insert('fl2', ['p' => 0.30000000000000004]);
                $this->assertSame([], $db->getKeysFromFieldIndex('fl2', 'p', 0.3));
                $db->dropDB('fl2');
            }
        } finally {
            ini_set('precision', $before[0]);
            ini_set('serialize_precision', $before[1]);
        }
    }

    /** @test */
    public function singleWritesLeaveTheIndexFileAlone(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->createFieldIndex('sw', 'c');
        $db->insert('sw', array_map(function ($n) { return ['c' => 'c' . ($n % 5), 'n' => $n]; }, range(0, 99)));
        $file = $this->fx('sw', 'c');
        clearstatcache();
        $bytes = file_get_contents($file);
        for ($i = 0; $i < 10; $i++) {
            $db->insert('sw', ['c' => 'new' . $i]);
            $db->update('sw', [['n' => $i], ['set' => ['c' => 'moved']]]);
            $db->delete('sw', ['n' => 50 + $i]);
        }
        clearstatcache();
        $this->assertSame($bytes, file_get_contents($file), 'writes replay later, they never rewrite the index');
        $plain = $this->db(['shardingEnabled' => false, 'fieldIndexEnabled' => false]);
        foreach (['c0', 'c3', 'moved', 'new4', 'nothing'] as $c) {
            $expected = array_column($plain->find('sw', ['c' => $c]), 'key');
            $this->assertSame($expected, array_column($db->find('sw', ['c' => $c]), 'key'), "warm $c");
            \noneDB::clearStaticCache();
            $this->assertSame($expected, array_column($this->db(['shardingEnabled' => false])->find('sw', ['c' => $c]), 'key'), "cold $c");
        }
    }

    /** @test */
    public function aLongTailIsCheckpointed(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->createFieldIndex('lt', 'c');
        $db->insert('lt', ['c' => 'first']);
        $before = $this->header($this->fx('lt', 'c'))['s'];
        // The documented rule (this writer has the index loaded): at the end of a write session, a new
        // checkpoint once at least 64 KiB of data is new (the index file is far smaller than 256 KiB)
        $data = $this->dbPath('lt', 'data');
        $covered = $before;
        $checkpoints = 0;
        for ($i = 0; $i < 30; $i++) {
            $db->insert('lt', array_map(function ($n) use ($i) { return ['c' => 'c' . ($n % 7), 'pad' => str_repeat('x', 100), 'i' => $i]; }, range(0, 19)));
            clearstatcache();
            if (filesize($data) - $covered >= 65536) {
                $covered = filesize($data);
                $checkpoints++;
            }
        }
        clearstatcache();
        $after = $this->header($this->fx('lt', 'c'));
        $this->assertSame(1, $checkpoints, 'precondition: about 79 KiB written');
        $this->assertGreaterThan($before, $after['s'], 'checkpointed after about 64 KiB of data');
        $this->assertSame($covered, $after['s'], 'at the end of the first session that reached 64 KiB');
        $this->assertSame(1, $after['v']); // STORAGE_FORMAT.md §6.5.1
        $this->assertSame('fx', $after['kind']);
        $this->assertCount(90, $db->find('lt', ['c' => 'c3']));
    }

    /** @test */
    public function aWriterThatNeverReadsLeavesTheCheckpointsToItsReaders(): void
    {
        // A process that only writes never loads field or spatial indexes (no memory, no index I/O); the
        // first reader that replays the long tail writes the new checkpoints, global entries included
        foreach ([['shardingEnabled' => false], ['shardSize' => 2000]] as $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $sharded = isset($config['shardSize']);
            // Sharded: shard 0 full, shard 1 holds one record and gets the writer's lines
            $db->insert('wr', array_fill(0, $sharded ? 2001 : 1, ['c' => 'first', 'loc' => ['type' => 'Point', 'coordinates' => [1, 1]]]));
            $db->createFieldIndex('wr', 'c');
            $db->createSpatialIndex('wr', 'loc');
            $shard = $sharded ? 1 : null;
            $fx = $this->fx('wr', 'c', $shard);
            $rx = $this->dbPath('wr', 'rx', 'loc', $shard);
            $files = function () use ($fx, $rx) { clearstatcache(); return [file_get_contents($fx), file_get_contents($rx)]; };
            \noneDB::clearStaticCache();
            $writer = $this->db($config);
            $before = $files();
            for ($i = 0; $i < 30; $i++) {
                $writer->insert('wr', array_map(function ($n) use ($i) {
                    return ['c' => 'c' . ($n % 7), 'pad' => str_repeat('x', 100), 'loc' => ['type' => 'Point', 'coordinates' => [$n % 10, $i % 10]]];
                }, range(0, 19)));
            }
            $this->assertSame($before, $files(), 'the writer wrote no index file');
            $loaded = array_filter(array_keys($this->getStatic('staticFieldIndexCache') + $this->getStatic('staticSpatialIndexCache')),
                function ($key) { return preg_match('~(^|[./])(fx|rx)($|\.)~', basename($key)) === 1; }); // a segment's .fx / .rx
            $this->assertSame([], array_values($loaded), 'and loaded none');
            $this->assertTrue($writer->checkIntegrity('wr')['ok']);
            $this->assertSame($before, $files(), 'checkIntegrity() writes nothing');

            \noneDB::clearStaticCache();
            $reader = $this->db($config);
            $this->assertCount(90, $reader->find('wr', ['c' => 'c3']));
            $this->assertCount(60, $reader->withinBBox('wr', 'loc', 2.5, -1, 3.5, 10));
            clearstatcache();
            $data = $this->dbPath('wr', 'data', $shard);
            $this->assertSame(filesize($data), $this->header($fx)['s'], 'the reader checkpointed the field index');
            $this->assertSame(filesize($data), $this->header($rx)['s'], 'and the R-tree');
            if ($shard !== null) {
                $global = $this->header($this->dbPath('wr', 'gfx', 'c'));
                $this->assertSame(filesize($data), $global['sh']['1']['s'], 'and its global entry');
            }
            \noneDB::clearStaticCache();
            $this->assertCount(90, $this->db($config + ['fieldIndexEnabled' => false])->find('wr', ['c' => 'c3']));
        }
    }

    private function getStatic(string $name): array
    {
        $property = new \noneDB\Tests\InternalValue($name);
        return $property->getValue();
    }

    /** @test */
    public function anOldOrForeignIndexFileIsReplayedOrRebuilt(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->createFieldIndex('old', 'c');
        $db->createFieldIndex('old', 'd');
        $db->insert('old', array_map(function ($n) { return ['c' => 'c' . ($n % 3), 'd' => $n % 2]; }, range(0, 9)));
        $stale = file_get_contents($this->fx('old', 'c'));
        $db->insert('old', array_map(function ($n) { return ['c' => 'c' . ($n % 3), 'd' => $n % 2, 'pad' => str_repeat('y', 300)]; }, range(0, 299)));
        $db->update('old', [['c' => 'c1'], ['set' => ['c' => 'c4']]]);
        $plain = $this->db(['shardingEnabled' => false, 'fieldIndexEnabled' => false]);
        $expect = function ($field, $value) use ($plain) { return array_column($plain->find('old', [$field => $value]), 'key'); };

        // An older checkpoint of the same data file: its tail is replayed
        file_put_contents($this->fx('old', 'c'), $stale);
        \noneDB::clearStaticCache();
        $this->assertSame($expect('c', 'c4'), array_column($this->db(['shardingEnabled' => false])->find('old', ['c' => 'c4']), 'key'));
        // The file of another field at this field's place: not used
        copy($this->fx('old', 'd'), $this->fx('old', 'c'));
        \noneDB::clearStaticCache();
        $this->assertSame($expect('c', 'c0'), array_column($this->db(['shardingEnabled' => false])->find('old', ['c' => 'c0']), 'key'));
        // A header covering more than the data, or another epoch: rebuilt
        $file = $this->fx('old', 'c');
        $header = $this->header($file);
        $body = substr(file_get_contents($file), strpos(file_get_contents($file), "\n") + 1);
        foreach ([['s' => $header['s'] + 1000000], ['e' => 'abcdef01']] as $change) {
            file_put_contents($file, json_encode(array_merge($header, $change)) . "\n" . $body);
            \noneDB::clearStaticCache();
            $this->assertSame($expect('c', 'c2'), array_column($this->db(['shardingEnabled' => false])->find('old', ['c' => 'c2']), 'key'), json_encode($change));
        }
        // A new epoch of the key index (rebuildIndex): every field index is rebuilt for it
        $this->db(['shardingEnabled' => false])->rebuildIndex('old');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame($expect('d', 1), array_column($db->find('old', ['d' => 1]), 'key'));
        $this->assertTrue($db->checkIntegrity('old')['ok']);
    }

    /** @test */
    public function caseVariantsAndLongNamesGetTheirOwnFiles(): void
    {
        // "City" and "city" have different index files, and a long field name is fine (file names stay
        // within 255 bytes)
        $db = $this->db(['shardSize' => 5]);
        $long = str_repeat('ü', 110);
        foreach (['city', 'City', $long] as $field) {
            $this->assertTrue($db->createFieldIndex('cv', $field)['success'], $field);
        }
        $db->insert('cv', array_map(function ($n) use ($long) { return ['city' => 'a' . ($n % 2), 'City' => 'b' . ($n % 3), $long => $n % 4]; }, range(0, 19)));
        $plain = $this->db(['shardSize' => 5, 'fieldIndexEnabled' => false]);
        foreach ([['city', 'a1'], ['City', 'b2'], [$long, 3]] as [$field, $value]) {
            $this->assertSame(array_column($plain->find('cv', [$field => $value]), 'key'), array_column($db->find('cv', [$field => $value]), 'key'));
        }
        foreach ($this->dbFiles('cv') as $file) {
            $this->assertLessThanOrEqual(255, strlen(basename($file)), $file);
        }
        $this->assertNotSame($this->fx('cv', 'city', 0), $this->fx('cv', 'City', 0));
        $this->assertFileExists($this->fx('cv', 'City', 0));
        // Index file names hold no database name (STORAGE_FORMAT.md §5.1): a long database name is fine too
        $longDb = str_repeat('n', 220);
        $this->assertTrue($db->createFieldIndex($longDb, 'v')['success'], 'a long database name');
        $db->insert($longDb, [['v' => 1], ['v' => 2]]);
        $this->assertSame([1], array_column($db->find($longDb, ['v' => 2]), 'key'));
        $this->assertSame([1], $db->getKeysFromFieldIndex($longDb, 'v', 2));
        $this->assertFileExists($this->fx($longDb, 'v'));
        foreach ($this->dbFiles($longDb) as $file) {
            $this->assertLessThanOrEqual(255, strlen(basename($file)), $file);
        }
    }

    /** @test */
    public function rangeCandidatesEqualAScan(): void
    {
        mt_srand(31);
        $pool = [0, 1, -1, 2.5, -2.5, 9007199254740993, 9007199254740992.0, 1e300, -0.0, '5', ' 5', '5 ', '1e3', '.5', '+5', '007',
            '0x1A', 'abc', 'Abc', '', 'z', 'ü', null, true, false, [1], '9', '10', '1a'];
        foreach ([['shardingEnabled' => false], ['shardSize' => 7]] as $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->createFieldIndex('rg', 'v');
            $rows = [];
            for ($i = 0; $i < 60; $i++) {
                $rows[] = mt_rand(0, 9) === 0 ? ['w' => 1] : ['v' => $pool[mt_rand(0, count($pool) - 1)]];
            }
            $db->insert('rg', $rows);
            $plain = $this->db($config + ['fieldIndexEnabled' => false]);
            foreach ($pool as $lo) {
                foreach ([$pool[mt_rand(0, count($pool) - 1)], $pool[mt_rand(0, count($pool) - 1)], NAN, INF, -INF] as $hi) {
                    $label = json_encode([$lo, is_float($hi) && !is_finite($hi) ? (is_nan($hi) ? 'NAN' : ($hi > 0 ? 'INF' : '-INF')) : $hi]);
                    $expected = array_column($plain->between('rg', 'v', $lo, $hi), 'key');
                    $this->assertSame($expected, array_column($db->between('rg', 'v', $lo, $hi), 'key'), $label);
                    $this->assertSame($expected, array_column($db->query('rg')->between('v', $lo, $hi)->get(), 'key'), "query builder: $label");
                    if (is_int($lo) || is_float($lo) || is_string($lo)) {
                        // A comparable bound: the field index is the range index, no segment is scanned
                        $this->assertSame(0, $db->query('rg')->between('v', $lo, $hi)->explain()['segments']['scanned'], $label);
                    }
                }
            }
        }
    }
}
