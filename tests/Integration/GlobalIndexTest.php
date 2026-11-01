<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Global field and spatial indexes: checkpoints of each shard's values or box, updated
 * when the shard's own index is checkpointed, never per write; a shard is skipped only when its entry fits
 * the shard and neither the entry nor the shard's newer lines can hold a match.
 */
class GlobalIndexTest extends RegressionTestCase
{
    private function stat(string $name): int
    {
        $property = new \noneDB\Tests\InternalValue($name);
        return $property->getValue();
    }

    private function globalFile(string $name, string $kind, string $field): string
    {
        return $this->dbPath($name, $kind, $field);
    }

    private function db5(array $extra = []): \noneDB
    {
        return $this->db(['shardSize' => 50] + $extra);
    }

    private function fill(\noneDB $db, string $name): void
    {
        $db->createFieldIndex($name, 'u');
        $db->createFieldIndex($name, 'g');
        $db->createSpatialIndex($name, 'loc');
        $db->insert($name, array_map(function ($n) {
            // shard i holds longitudes 10 * i .. 10 * i + 4
            return ['u' => 'u' . $n, 'g' => 'g' . intdiv($n, 50), 'loc' => ['type' => 'Point', 'coordinates' => [10 * intdiv($n, 50) + ($n % 5), 1]]];
        }, range(0, 199)));
    }

    /** @test */
    public function uniqueInsertsDontRewriteTheGlobalIndex(): void
    {
        $db = $this->db5();
        $this->fill($db, 'gu');
        clearstatcache();
        $gfx = file_get_contents($this->globalFile('gu', 'gfx', 'u'));
        $grx = file_get_contents($this->globalFile('gu', 'grx', 'loc'));
        for ($i = 0; $i < 10; $i++) {
            $db->insert('gu', ['u' => 'new' . $i, 'g' => 'g3', 'loc' => ['type' => 'Point', 'coordinates' => [35, 1]]]);
        }
        clearstatcache();
        $this->assertSame($gfx, file_get_contents($this->globalFile('gu', 'gfx', 'u')), 'an insert never rewrites it');
        $this->assertSame($grx, file_get_contents($this->globalFile('gu', 'grx', 'loc')));
        foreach ([true, false] as $warm) {
            if (!$warm) {
                \noneDB::clearStaticCache();
                $db = $this->db5();
            }
            $this->assertSame([200], array_column($db->find('gu', ['u' => 'new0']), 'key'), 'a newer line of a shard is found');
            $this->assertSame([7], array_column($db->find('gu', ['u' => 'u7']), 'key'));
            $this->assertSame([], $db->find('gu', ['u' => 'none']));
            $this->assertCount(10, $db->withinBBox('gu', 'loc', 34.5, 0, 36, 2));
            $inBox = array_column($db->withinBBox('gu', 'loc', 34.5, 0, 36, 2), 'key');
            sort($inBox);
            $this->assertSame(range(200, 209), $inBox, 'exactly the ten new points at (35, 1)');
        }
    }

    /** @test */
    public function shardsThatCantHoldTheValueAreSkipped(): void
    {
        $db = $this->db5();
        $this->fill($db, 'gs');
        \noneDB::clearStaticCache();
        $db = $this->db5();
        $db->find('gs', ['u' => 'u0']); // warm up: indexes, entries checked once
        $before = $this->stat('segmentReads');
        $this->assertSame([123], array_column($db->find('gs', ['u' => 'u123']), 'key'));
        $this->assertSame(1, $this->stat('segmentReads') - $before, 'only the shard that holds it');
        $before = $this->stat('segmentReads');
        $this->assertSame([], $db->find('gs', ['u' => 'missing']));
        $this->assertSame(0, $this->stat('segmentReads') - $before, 'no shard holds it');
        $before = $this->stat('segmentReads');
        $this->assertCount(50, $db->withinBBox('gs', 'loc', 20, 0, 24, 2));
        $this->assertSame(1, $this->stat('segmentReads') - $before, 'one shard box overlaps');
    }

    /** @test */
    public function missingOrBrokenEntriesNeverSkip(): void
    {
        $db = $this->db5();
        $this->fill($db, 'gm');
        $file = $this->globalFile('gm', 'gfx', 'u');
        $content = file_get_contents($file);
        $newline = strpos($content, "\n");
        $header = json_decode(substr($content, 0, $newline), true);
        // An entry of shard 2 missing: shard 2 is searched
        unset($header['sh']['2']);
        file_put_contents($file, json_encode($header) . substr($content, $newline));
        // The value moved into shard 2 behind the index's back: only the data knows
        $db->update('gm', [['u' => 'u120'], ['set' => ['u' => 'u5']]]);
        \noneDB::clearStaticCache();
        $db = $this->db5();
        $this->assertSame([5, 120], array_column($db->find('gm', ['u' => 'u5']), 'key'));
        // A broken file: ignored and built again
        file_put_contents($file, 'garbage');
        \noneDB::clearStaticCache();
        $db = $this->db5();
        $this->assertSame([5, 120], array_column($db->find('gm', ['u' => 'u5']), 'key'));
        $this->assertSame([199], array_column($db->find('gm', ['u' => 'u199']), 'key'));
        // A spatial entry with a box that misses its shard's points (stale): the shard's own index decides
        $gfile = $this->globalFile('gm', 'grx', 'loc');
        $line = json_decode(trim(file_get_contents($gfile)), true);
        $line['sh']['1']['e'] = 'deadbeef';
        $line['le'] = null;
        $line['sh']['1']['b'] = [0, 0, 1, 1];
        file_put_contents($gfile, json_encode($line) . "\n");
        \noneDB::clearStaticCache();
        $this->assertCount(50, $this->db5()->withinBBox('gm', 'loc', 10, 0, 14, 2));
        $keys = array_column($this->db5()->withinBBox('gm', 'loc', 10, 0, 14, 2), 'key');
        sort($keys);
        $this->assertSame(range(50, 99), $keys, "shard 1's points (longitudes 10..14)");
    }

    /** @test */
    public function lookupsFollowWritesMadeAfterTheShardListWasWorkedOut(): void
    {
        // A lookup works out once which shards it must check besides the value's holders; later writes of
        // this process (an existing shard, a new shard) and of another process must still be seen
        $db = $this->db5();
        $db->createFieldIndex('gl', 'u');
        $db->insert('gl', array_map(function ($n) { return ['u' => 'u' . $n]; }, range(0, 149)));
        $keys = function () use (&$db) { return array_column($db->find('gl', ['u' => 'x']), 'key'); };
        $this->assertSame([], $keys());
        $db->update('gl', [['u' => 'u120'], ['set' => ['u' => 'x']]]); // shard 2: a newer line
        $this->assertSame([120], $keys());
        $db->insert('gl', ['u' => 'x']); // key 150: new shard 3
        $this->assertSame([120, 150], $keys());
        $this->assertSame('', $this->runChild('$db = ' . $this->childDb(['shardSize' => 50]) . ';
            $db->update("gl", [["u" => "u7"], ["set" => ["u" => "x"]]]);'));
        $this->assertSame([7, 120, 150], $keys(), 'another process wrote shard 0');
        $this->assertSame([], array_column($db->find('gl', ['u' => 'u7']), 'key'));
        \noneDB::disableStaticCache();
        try {
            $a = $this->db5();
            $b = $this->db5();
            $this->assertSame([7, 120, 150], array_column($a->find('gl', ['u' => 'x']), 'key'));
            $b->update('gl', [['u' => 'u60'], ['set' => ['u' => 'x']]]); // another instance, own caches
            $this->assertSame([7, 60, 120, 150], array_column($a->find('gl', ['u' => 'x']), 'key'));
        } finally {
            \noneDB::enableStaticCache();
        }
    }

    /** @test */
    public function rewritingTheGlobalIndexAfterACompactionDropsStaleEntries(): void
    {
        // Compaction replaces shards 0 and 1 (new lock epoch). Then a lookup on two fields builds only a
        // new shard's index of "g" and rewrites g's global index under the new epoch: the old entries of
        // shards 0 and 1 ("g0" / "g1"; the shards now hold "mv", same length, so no tail) must not ride
        // along as if they had been checked under it
        $db = $this->db5();
        $db->createFieldIndex('gr', 'g');
        $db->createFieldIndex('gr', 'h');
        $db->insert('gr', array_map(function ($n) {
            return ['g' => 'g' . intdiv($n, 50), 'h' => 'h' . intdiv($n, 50)];
        }, range(0, 149)));
        foreach ([0, 1] as $shard) {
            $db->update('gr', [['g' => 'g' . $shard], ['set' => ['g' => 'mv']]]); // compacted afterwards
        }
        $this->assertSame([], $db->find('gr', ['h' => 'h9']), "h's global index checked again under the new epoch");
        $db->insert('gr', ['g' => 'g3', 'h' => 'h3']); // shard 3
        $this->assertSame([150], array_column($db->find('gr', ['h' => 'h3', 'g' => 'g3']), 'key'), "only shard 3's index of g is built");
        foreach ([true, false] as $warm) {
            if (!$warm) {
                \noneDB::clearStaticCache();
                $db = $this->db5();
            }
            $this->assertCount(100, $db->find('gr', ['g' => 'mv']), 'warm ' . var_export($warm, true));
            $this->assertSame([], $db->find('gr', ['g' => 'g0']));
        }
        $this->assertTrue($db->checkIntegrity('gr')['ok']);
    }

    /** @test */
    public function aCompactedShardIsNotJudgedByItsOldEntry(): void
    {
        $db = $this->db5();
        $this->fill($db, 'gc');
        for ($n = 50; $n < 70; $n++) {
            $db->update('gc', [['u' => 'u' . $n], ['set' => ['u' => 'late' . $n]]]);
        }
        $db->compact('gc'); // new data files, new epochs: the global entries no longer describe them
        \noneDB::clearStaticCache();
        $db = $this->db5();
        $this->assertSame([], $db->find('gc', ['u' => 'u55']));
        $this->assertSame([55], array_column($db->find('gc', ['u' => 'late55']), 'key'));
        $this->assertCount(50, $db->withinBBox('gc', 'loc', 10, 0, 14, 2));
        $keys = array_column($db->withinBBox('gc', 'loc', 10, 0, 14, 2), 'key');
        sort($keys);
        $this->assertSame(range(50, 99), $keys);
        $this->assertTrue($db->checkIntegrity('gc')['ok']);
    }
}
