<?php

namespace noneDB\Tests\Crash;

use noneDB\Tests\RegressionTestCase;

/**
 * What a power loss without fsync can leave behind (plan "Güvence modeli" L3: best effort, no guarantee):
 * renamed files that are empty, a lock record of zero bytes, a data file extended with zero bytes, a
 * buffer that lost its content. The database must open without an exception, return what survived, and
 * keep working; checkIntegrity() tells what it found.
 */
class PowerLossSimulationTest extends RegressionTestCase
{
    private function build(string $name, array $config): \noneDB
    {
        $db = $this->db($config);
        $db->createFieldIndex($name, 'c');
        $db->createSpatialIndex($name, 'loc');
        $db->insert($name, array_map(function ($n) {
            return ['n' => $n, 'c' => 'c' . ($n % 3), 'loc' => ['type' => 'Point', 'coordinates' => [$n, $n / 2]]];
        }, range(0, 13)));
        $db->delete($name, ['n' => 4]);
        $db->update($name, [['n' => 5], ['set' => ['c' => 'upd']]]);
        return $db;
    }

    /** Expected state: n => c */
    private function expected(): array
    {
        $rows = [];
        foreach (range(0, 13) as $n) {
            if ($n !== 4) {
                $rows[$n] = $n === 5 ? 'upd' : 'c' . ($n % 3);
            }
        }
        return $rows;
    }

    private function assertState(string $name, array $config, string $case): void
    {
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $all = $db->find($name, 0);
        $this->assertSame($this->expected(), array_column($all, 'c', 'n'), "$case: records");
        $this->assertSame(count($all), $db->count($name), "$case: count");
        foreach (['c0', 'c1', 'c2', 'upd'] as $c) {
            $scan = array_values(array_filter($all, function ($r) use ($c) { return $r['c'] === $c; }));
            $this->assertSame(array_column($scan, 'key'), array_column($db->find($name, ['c' => $c]), 'key'), "$case: field index $c");
        }
        $box = array_column($db->withinBBox($name, 'loc', 0, 0, 6, 3), 'key');
        sort($box);
        $expectedBox = [];
        foreach ($all as $r) {
            if ($r['loc']['coordinates'][0] <= 6 && $r['loc']['coordinates'][1] <= 3) {
                $expectedBox[] = $r['key'];
            }
        }
        sort($expectedBox);
        $this->assertSame($expectedBox, $box, "$case: spatial index");
        $db->insert($name, ['n' => 100, 'c' => 'new', 'loc' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        $new = $db->find($name, ['c' => 'new'])[0]['key'];
        // build() assigned keys 0..13 (key 4 deleted): nothing was lost, so the next key is 14
        $this->assertSame(14, $new, "$case: no key reused, none skipped");
        $this->assertTrue($db->checkIntegrity($name)['ok'], "$case: consistent afterwards");
    }

    /** Files of a database whose path relative to its directory matches $pattern */
    private function filesOf(string $name, string $pattern): array
    {
        $home = $this->dbPath($name, 'home');
        return array_values(array_map(function ($f) use ($home) { return $home . $f; },
            array_filter($this->dbFiles($name), function ($f) use ($pattern) {
                return preg_match($pattern, $f) === 1;
            })));
    }

    /** @test */
    public function emptyOrGarbledDerivedFiles(): void
    {
        // Derived index files of the active generation (docs/STORAGE_FORMAT.md 5.1): segments/*.kix,
        // indexes/*.fx, indexes/*.gfx, spatial/*.rx, spatial/*.grx; the index list indexes.json; the identity
        // record FORMAT (holds the creation time; repaired from the generation's metadata, 8.3)
        $cases = [
            'empty key indexes' => '#^generations/[0-9]+/segments/[^/]+\.kix$#',
            'empty field indexes' => '#^generations/[0-9]+/indexes/[^/]+\.fx$#',
            'empty global field index' => '#^generations/[0-9]+/indexes/[^/]+\.gfx$#',
            'empty spatial indexes' => '#^generations/[0-9]+/spatial/[^/]+\.rx$#',
            'empty global spatial index' => '#^generations/[0-9]+/spatial/[^/]+\.grx$#',
            'empty index list' => '#^generations/[0-9]+/indexes\.json$#',
            'empty FORMAT' => '#^FORMAT$#',
        ];
        foreach ([['shardingEnabled' => false], ['shardSize' => 5]] as $config) {
            foreach ($cases as $case => $pattern) {
                $sharded = isset($config['shardSize']);
                if (!$sharded && strpos($case, 'global') !== false) {
                    continue; // global indexes exist only for sharded databases
                }
                $this->cleanTestDirectory();
                $name = 'pl';
                $this->build($name, $config);
                $files = $this->filesOf($name, $pattern);
                // one per segment (3 shards), or one per database
                $this->assertCount($sharded && in_array($case, ['empty key indexes', 'empty field indexes', 'empty spatial indexes'], true) ? 3 : 1,
                    $files, "$case: the files this case empties exist");
                foreach ($files as $file) {
                    file_put_contents($file, '');
                }
                $this->assertState($name, $config, $case . ' ' . json_encode($config));
            }
        }
    }

    /** @test */
    public function unreadableMetaKeepsTheRecordedShardSize(): void
    {
        // Shard 1 holds only tombstone 14 after compaction: the keys fit every shard size from 10 to 14, the
        // configured 12 too; the size the database was created with decides
        $db = $this->db(['shardSize' => 10]);
        $db->insert('pz', array_map(function ($n) { return ['n' => $n]; }, range(0, 14)));
        $db->query('pz')->where(['n' => ['$gte' => 10]])->delete();
        $db->compact('pz');
        file_put_contents($this->dbPath('pz', 'shards'), '');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 12]);
        $this->assertSame(range(0, 9), array_column($db->find('pz', 0), 'n'));
        $meta = json_decode(file_get_contents($this->dbPath('pz', 'shards')), true);
        $this->assertSame(10, $meta['shardSize']);
        $db->insert('pz', ['n' => 'next']);
        $this->assertSame(15, $db->find('pz', ['n' => 'next'])[0]['key']);
        $this->assertTrue($db->checkIntegrity('pz')['ok']);
    }

    /** @test */
    public function emptyOrGarbledMeta(): void
    {
        foreach (['', '{"kind":"shards","v":1,"shard', "\0\0\0\0"] as $content) {
            $this->cleanTestDirectory();
            $this->build('plm', ['shardSize' => 5]);
            file_put_contents($this->dbPath('plm', 'shards'), $content);
            $this->assertState('plm', ['shardSize' => 5], 'meta ' . json_encode($content));
        }
    }

    /** @test */
    public function zeroedLockRecord(): void
    {
        foreach ([['shardingEnabled' => false], ['shardSize' => 5]] as $config) {
            $this->cleanTestDirectory();
            $this->build('pll', $config);
            \noneDB::clearStaticCache();
            file_put_contents($this->dbPath('pll', 'lock'), str_repeat("\0", 128));
            $this->assertState('pll', $config, 'zeroed lock ' . json_encode($config));
        }
    }

    /** @test */
    public function dataFileExtendedWithZeroBytes(): void
    {
        // The file size reached the disk, the appended bytes didn't: a tail of NUL bytes
        foreach ([['shardingEnabled' => false], ['shardSize' => 5]] as $config) {
            $this->cleanTestDirectory();
            $this->build('plz', $config);
            $data = $config === ['shardSize' => 5] ? $this->dbPath('plz', 'data', 2) : $this->dbPath('plz', 'data');
            clearstatcache();
            $offset = filesize($data);
            file_put_contents($data, str_repeat("\0", 4096), FILE_APPEND);
            $this->assertState('plz', $config, 'NUL tail ' . json_encode($config));
            // The insert of assertState() cut the NUL bytes: kept in recovery/q.{main|s2}.{offset}.4096.{sha256}.bin
            $cuts = array_values(array_filter($this->db()->getRecoveryLog('plz'), function ($e) { return $e['action'] === 'cut-tail'; }));
            $this->assertCount(1, $cuts, 'the cut tail was quarantined');
            $expected = 'q.' . ($config === ['shardSize' => 5] ? 's2' : 'main') . '.' . $offset . '.4096.' . hash('sha256', str_repeat("\0", 4096)) . '.bin';
            $this->assertSame($expected, $cuts[0]['quarantine']);
            $this->assertSame(str_repeat("\0", 4096), file_get_contents($this->dbPath('plz', 'quarantine') . $expected));
        }
    }

    /** @test */
    public function zeroBytesFollowedByACompleteLine(): void
    {
        // A NUL region in front of a line that did reach the disk (the line is recovered)
        $this->build('pln', ['shardingEnabled' => false]);
        $data = $this->dbPath('pln', 'data');
        file_put_contents($data, str_repeat("\0", 300) . '{"n":50,"c":"late","loc":{"type":"Point","coordinates":[1,1]},"key":14}' . "\n", FILE_APPEND);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame(['late'], array_column($db->find('pln', ['key' => 14]), 'c'));
        $this->assertSame('salvaged', $db->getRecoveryLog('pln')[0]['action']);
        $db->insert('pln', ['n' => 51, 'c' => 'after']);
        $this->assertSame(15, $db->find('pln', ['c' => 'after'])[0]['key']);
    }

    /** @test */
    public function emptyBufferAndInterruptedFlush(): void
    {
        $db = $this->db(['bufferEnabled' => true]);
        $db->insert('plb', ['n' => 1]);
        $db->flush('plb');
        $buffer = $this->dbPath('plb', 'buffer');
        file_put_contents($buffer, '');
        file_put_contents($this->dbPath('plb', 'flushing', 1), '');
        \noneDB::clearStaticCache();
        $db = $this->db(['bufferEnabled' => true]);
        $this->assertSame([1], array_column($db->find('plb', 0), 'n'));
        $db->insert('plb', ['n' => 2]);
        $db->flush('plb');
        $this->assertSame([1, 2], array_column($db->find('plb', 0), 'n'));
        $this->assertSame([], glob($this->dbPath('plb', 'gen') . 'buffer/flushing.*'));
    }
}
