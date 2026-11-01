<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * R-trees: integer boxes (degrees * 1e7, rounded outwards), sort-tile-recursive bulk
 * load, checkpoints with replayed tails. Every query result equals a scan.
 */
class RTreeTest extends RegressionTestCase
{
    private function stat(string $name): int
    {
        $property = new \noneDB\Tests\InternalValue($name);
        return $property->getValue();
    }

    private function randomGeometry(): array
    {
        $x = mt_rand(-1800, 1790) / 10;
        $y = mt_rand(-890, 880) / 10;
        switch (mt_rand(0, 3)) {
            case 0:
                return ['type' => 'Point', 'coordinates' => [$x, $y]];
            case 1:
                return ['type' => 'LineString', 'coordinates' => [[$x, $y], [$x + mt_rand(1, 50) / 10, $y + mt_rand(1, 50) / 10]]];
            case 2:
                $d = mt_rand(1, 30) / 10;
                return ['type' => 'Polygon', 'coordinates' => [[[$x, $y], [$x + $d, $y], [$x + $d, $y + $d], [$x, $y + $d], [$x, $y]]]];
            default:
                return ['type' => 'Point', 'coordinates' => [round($x, 1), round($y, 1)]]; // on box edges
        }
    }

    /** Valid per docs/SPATIAL.md for what randomGeometry() makes: positions in range, no flat coordinates */
    private static function modelValid($g): bool
    {
        if (!is_array($g) || ($g['type'] !== 'Point' && !is_array($g['coordinates'][0] ?? null))) {
            return false;
        }
        foreach (SpecSpatialTest::positions($g) as [$x, $y]) {
            if ($x < -180 || $x > 180 || $y < -90 || $y > 90) {
                return false;
            }
        }
        return true;
    }

    /** @test */
    public function queriesEqualAScanThroughChanges(): void
    {
        mt_srand(77);
        foreach ([['shardingEnabled' => false], ['shardSize' => 40]] as $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->createSpatialIndex('sp', 'g');
            for ($round = 0; $round < 6; $round++) {
                $rows = [];
                for ($i = 0; $i < 60; $i++) {
                    $rows[] = mt_rand(0, 12) === 0 ? ['g' => ['type' => 'LineString', 'coordinates' => [1, 2]]] : ['g' => $this->randomGeometry()];
                }
                $db->insert('sp', $rows);
                $all = $db->find('sp', 0);
                for ($i = 0; $i < 10; $i++) {
                    $pick = $all[mt_rand(0, count($all) - 1)]['key'];
                    if (mt_rand(0, 1)) {
                        $db->delete('sp', ['key' => $pick]);
                    } else {
                        $db->update('sp', [['key' => $pick], ['set' => ['g' => $this->randomGeometry()]]]);
                    }
                }
                $plain = $this->db($config + ['spatialIndexEnabled' => false]);
                foreach ([true, false] as $warm) {
                    if (!$warm) {
                        \noneDB::clearStaticCache();
                        $db = $this->db($config);
                    }
                    for ($q = 0; $q < 8; $q++) {
                        $x = mt_rand(-1800, 1700) / 10;
                        $y = mt_rand(-900, 800) / 10;
                        $w = mt_rand(1, 400) / 10;
                        $expected = array_column($plain->withinBBox('sp', 'g', $x, $y, $x + $w, $y + $w), 'key');
                        $actual = array_column($db->withinBBox('sp', 'g', $x, $y, $x + $w, $y + $w), 'key');
                        sort($expected);
                        sort($actual);
                        $this->assertSame($expected, $actual, json_encode([$config, $round, $x, $y, $w]));
                        // and the scan equals the documented rule: bounding boxes overlap or touch
                        $model = [];
                        foreach ($plain->find('sp', 0) as $record) {
                            if (self::modelValid($record['g']) && SpecSpatialTest::inBBoxQuery($record['g'], SpecSpatialTest::bboxQueryBoxes($x, $y, $x + $w, $y + $w))) {
                                $model[] = $record['key'];
                            }
                        }
                        sort($model);
                        $this->assertSame($model, $expected, 'model ' . json_encode([$config, $round, $x, $y, $w]));
                        $radius = (float)mt_rand(0, 2000000);
                        $this->assertSame(
                            array_column($plain->withinDistance('sp', 'g', $x, $y, $radius, ['includeDistance' => true]), '_distance', 'key'),
                            array_column($db->withinDistance('sp', 'g', $x, $y, $radius, ['includeDistance' => true]), '_distance', 'key'),
                            'withinDistance ' . json_encode([$config, $round, $x, $y, $radius]));
                        $this->assertSame(array_column($plain->nearest('sp', 'g', $x, $y, 3), 'key'), array_column($db->nearest('sp', 'g', $x, $y, 3), 'key'));
                    }
                }
            }
            $this->assertTrue($db->checkIntegrity('sp')['ok']);
        }
    }

    /** @test */
    public function treeStaysConsistentThroughInsertsAndRemovals(): void
    {
        mt_srand(5);
        $db = $this->db(['shardingEnabled' => false]);
        $tree = (function () { return $this->newRTree('g', 4); })->call(\noneDB\Tests\Support\Testing::service($db, 'newRTree'));
        $boxes = [];
        $op = function (string $method, ...$args) use ($db, &$tree) {
            return (function () use ($method, $args, &$tree) {
                return $this->$method($tree, ...$args);
            })->call(\noneDB\Tests\Support\Testing::service($db, 'newRTree'));
        };
        for ($i = 0; $i < 400; $i++) {
            $key = mt_rand(0, 150);
            if (mt_rand(0, 2) === 0) {
                $op('rtreeRemove', $key);
                unset($boxes[$key]);
            } else {
                $x = mt_rand(-1000, 1000);
                $y = mt_rand(-1000, 1000);
                $box = [$x, $y, $x + mt_rand(0, 50), $y + mt_rand(0, 50)];
                $op('rtreeInsert', $key, $box);
                $boxes[$key] = $box;
            }
        }
        // Every node's box is the union of its items; every key is in exactly one leaf
        $seen = [];
        foreach ($tree['nodes'] as $id => [$level, $box, $items]) {
            $union = null;
            foreach ($items as $item) {
                $itemBox = $level === 0 ? array_slice($item, 1) : $tree['nodes'][$item][1];
                if ($itemBox === null) {
                    continue;
                }
                $union = $union === null ? $itemBox : [min($union[0], $itemBox[0]), min($union[1], $itemBox[1]), max($union[2], $itemBox[2]), max($union[3], $itemBox[3])];
                if ($level === 0) {
                    $this->assertArrayNotHasKey($item[0], $seen);
                    $seen[$item[0]] = array_slice($item, 1);
                }
            }
            $this->assertSame($union, $box, "node $id");
        }
        ksort($seen);
        ksort($boxes);
        $this->assertSame($boxes, $seen);
        $this->assertSame(count($boxes), $tree['cnt']);
        // Bulk load gives the same answers as the tree built one by one
        $bulk = (function () { return $this->newRTree('g', 4); })->call(\noneDB\Tests\Support\Testing::service($db, 'newRTree'));
        (function () use (&$bulk, $boxes) {
            // The entries as columns: keys, then the boxes' minX, minY, maxX, maxY
            $columns = [[], [], [], [], []];
            foreach ($boxes as $key => $box) {
                $columns[0][] = $key;
                for ($c = 0; $c < 4; $c++) {
                    $columns[$c + 1][] = $box[$c];
                }
            }
            $this->rtreeBulkLoad($bulk, ...$columns);
        })->call(\noneDB\Tests\Support\Testing::service($db, 'newRTree'));
        for ($q = 0; $q < 50; $q++) {
            $x = mt_rand(-1000, 1000);
            $y = mt_rand(-1000, 1000);
            $query = [[$x, $y, $x + 200, $y + 200]];
            $a = $op('rtreeSearch', $query);
            $b = (function () use ($bulk, $query) { return $this->rtreeSearch($bulk, $query); })->call(\noneDB\Tests\Support\Testing::service($db, 'newRTree'));
            sort($a);
            sort($b);
            $expected = [];
            foreach ($boxes as $key => $box) {
                if ($box[0] <= $query[0][2] && $query[0][0] <= $box[2] && $box[1] <= $query[0][3] && $query[0][1] <= $box[3]) {
                    $expected[] = $key;
                }
            }
            $this->assertSame($expected, $a);
            $this->assertSame($expected, $b);
        }
    }

    /** @test */
    public function boxEdgesAndIniSettingsDontLosePoints(): void
    {
        $before = ini_get('serialize_precision');
        ini_set('serialize_precision', '5');
        try {
            $db = $this->db(['shardingEnabled' => false]);
            $db->createSpatialIndex('qe', 'g');
            $db->insert('qe', [
                ['g' => ['type' => 'Point', 'coordinates' => [28.980345, 41.0086]]],
                ['g' => ['type' => 'Point', 'coordinates' => [29.0, 41.0]]],
                ['g' => ['type' => 'Point', 'coordinates' => [29.00000001, 41.0]]],
                ['g' => ['type' => 'Point', 'coordinates' => [-180, -90]]],
                ['g' => ['type' => 'Point', 'coordinates' => [180, 90]]],
            ]);
            \noneDB::clearStaticCache();
            $db = $this->db(['shardingEnabled' => false]);
            $this->assertSame([0], array_column($db->withinBBox('qe', 'g', 28.980345, 41.0086, 28.980345, 41.0086), 'key'));
            $this->assertSame([1], array_column($db->withinBBox('qe', 'g', 28.99, 40.99, 29.0, 41.0), 'key'), 'the box edge belongs to the box');
            $this->assertSame([2], array_column($db->withinBBox('qe', 'g', 29.000000005, 40.99, 29.1, 41.01), 'key'), 'closer than the 1e-7 grid');
            $this->assertSame([0, 1, 2, 3, 4], array_column($db->withinBBox('qe', 'g', -INF, -INF, INF, INF), 'key'), 'the corners of the globe too');
        } finally {
            ini_set('serialize_precision', $before);
        }
    }

    /** @test */
    public function indexedCandidatesAreNotValidatedAgain(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->createSpatialIndex('nv', 'g');
        $ring = [];
        for ($i = 0; $i < 200; $i++) {
            $ring[] = [cos($i / 100 * M_PI), sin($i / 100 * M_PI)];
        }
        $ring[] = $ring[0];
        $db->insert('nv', array_fill(0, 20, ['g' => ['type' => 'Polygon', 'coordinates' => [$ring]]]));
        $db->withinBBox('nv', 'g', -2, -2, 2, 2); // loads the index
        $before = $this->stat('geometryValidations');
        $this->assertCount(20, $db->withinBBox('nv', 'g', -2, -2, 2, 2));
        $this->assertCount(20, $db->withinDistance('nv', 'g', 0, 0, 500000));
        $this->assertSame(0, $this->stat('geometryValidations') - $before, 'R-tree entries were validated when indexed');
        $plain = $this->db(['shardingEnabled' => false, 'spatialIndexEnabled' => false]);
        $before = $this->stat('geometryValidations');
        $this->assertCount(20, $plain->withinBBox('nv', 'g', -2, -2, 2, 2));
        $this->assertSame(20, $this->stat('geometryValidations') - $before, 'a scan validates each');
    }

    /** @test */
    public function writesDontRewriteTheTree(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->createSpatialIndex('wt', 'g');
        $db->insert('wt', array_map(function ($n) { return ['g' => ['type' => 'Point', 'coordinates' => [$n / 10, 1]]]; }, range(0, 99)));
        $file = $this->dbPath('wt', 'rx', 'g');
        $bytes = file_get_contents($file);
        for ($i = 0; $i < 10; $i++) {
            $db->insert('wt', ['g' => ['type' => 'Point', 'coordinates' => [50 + $i, 1]]]);
            $db->update('wt', [['key' => $i], ['set' => ['g' => ['type' => 'Point', 'coordinates' => [-50, -$i]]]]]);
        }
        clearstatcache();
        $this->assertSame($bytes, file_get_contents($file));
        // the in-memory tree answers the new state before any checkpoint
        $this->assertCount(10, $db->withinBBox('wt', 'g', 49, 0, 60, 2));
        $this->assertSame($bytes, file_get_contents($file), 'reads below the checkpoint threshold write nothing either');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertCount(10, $db->withinBBox('wt', 'g', 49, 0, 60, 2));
        $this->assertCount(10, $db->withinBBox('wt', 'g', -51, -10, -49, 0));
        $this->assertCount(0, $db->withinBBox('wt', 'g', 0, 0.5, 0.95, 1.5), 'keys 0-9 moved');
        $header = json_decode(strtok(file_get_contents($file), "\n"), true);
        $this->assertSame(['rx', 1], [$header['kind'], $header['v']]); // STORAGE_FORMAT.md §6.5
        $this->assertSame(10000000, $header['q']);
    }
}
