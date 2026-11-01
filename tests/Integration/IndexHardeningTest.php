<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Field and spatial indexes give exactly what a full scan gives, and never take the database down
 * (plan B1, B2, B5-B12)
 */
class IndexHardeningTest extends RegressionTestCase
{
    private function keysOf(array $records): array
    {
        $keys = array_column($records, 'key');
        sort($keys);
        return $keys;
    }

    /** Every spatial query: indexed result == result with spatialIndexEnabled=false */
    private function assertSpatialIndexEqualsScan(string $name, array $config = []): void
    {
        $indexed = $this->db($config);
        $scan = $this->db($config + ['spatialIndexEnabled' => false]);
        $polygon = ['type' => 'Polygon', 'coordinates' => [[[-10, -10], [10, -10], [10, 10], [-10, 10], [-10, -10]]]];
        foreach ([$indexed, $scan] as $i => $db) {
            $results[$i] = [
                'bbox' => $this->keysOf($db->withinBBox($name, 'loc', -10, -10, 10, 10)),
                'world' => $this->keysOf($db->withinBBox($name, 'loc', -180, -90, 180, 90)),
                'distance' => $this->keysOf($db->withinDistance($name, 'loc', 0, 0, 2000000)),
                'polygon' => $this->keysOf($db->withinPolygon($name, 'loc', $polygon)),
                'nearest' => array_column($db->nearest($name, 'loc', 1, 1, 3), 'key'),
                'withDistance' => array_column($db->query($name)->withDistance('loc', 0, 0)->sort('key')->get(), '_distance', 'key'),
            ];
        }
        $this->assertSame($results[1], $results[0]);
    }

    /** @test */
    public function malformedGeometryIsNotAPoisonPill(): void
    {
        // B-1: a TypeError in the validator during index maintenance left the journal set; every later
        // lock ran the same failing recovery: find, delete and even dropDB failed
        $db = $this->db(['shardingEnabled' => false]);
        $db->createSpatialIndex('poison', 'loc');
        $db->insert('poison', ['loc' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        $bad = [
            ['type' => 'LineString', 'coordinates' => [1, 2]],
            ['type' => 'LineString', 'coordinates' => ['a' => [1, 2], 'b' => [3, 4]]],
            ['type' => 'Polygon', 'coordinates' => ['a' => [[0, 0], [1, 0], [1, 1], [0, 0]]]],
            ['type' => true, 'coordinates' => [1, 1]],
            ['type' => 0, 'coordinates' => [1, 1]],
            ['type' => ['Point'], 'coordinates' => [1, 1]],
            ['type' => 'GeometryCollection', 'geometries' => ['x' => ['type' => 'Point', 'coordinates' => [1, 1]]]],
            ['type' => 'MultiPolygon', 'coordinates' => [[[1, 2]]]],
        ];
        foreach ($bad as $geometry) {
            $this->assertFalse($db->validateGeoJSON($geometry)['valid'], json_encode($geometry));
            $this->assertSame(1, $db->insert('poison', ['loc' => $geometry])['n'], json_encode($geometry));
        }
        $this->assertCount(1 + count($bad), $db->find('poison', 0));
        $this->assertSame([0], $this->keysOf($db->withinBBox('poison', 'loc', 0, 0, 2, 2)));
        $this->assertSpatialIndexEqualsScan('poison', ['shardingEnabled' => false]);
        $this->assertSame([], preg_grep('/^(j=|J$)/', $this->lockState('poison')['flags']));
        $this->assertTrue($db->dropDB('poison')['success']);
    }

    /** @test */
    public function leftoverJournalWithMalformedGeometryRecovers(): void
    {
        // B-1: records with malformed geometry in the data and the journal still set (a writer that died:
        // its generation bumped, "j=main" in the state record, STORAGE_FORMAT.md §4.2, §7.3)
        $db = $this->db(['shardingEnabled' => false]);
        $db->createSpatialIndex('hurt', 'loc');
        $db->insert('hurt', [['loc' => ['type' => 'Point', 'coordinates' => [1, 1]]], ['loc' => ['type' => 'LineString', 'coordinates' => [1, 2]]]]);
        $state = $this->lockState('hurt');
        $this->writeLockState('hurt', ($state['gen'] + 1) . ':' . $state['epoch'] . ' j=main');
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertCount(2, $db->find('hurt', 0));
        $this->assertSame([0], $this->keysOf($db->withinBBox('hurt', 'loc', 0, 0, 2, 2)));
        $this->assertSame([], preg_grep('/^(j=|J$)/', $this->lockState('hurt')['flags']));
    }

    /** @test */
    public function emptyGeometryCollectionHasNoLocation(): void
    {
        // B-2: it got the box [0,0,0,0], was "within" every polygon, and nearest() put INF into _distance
        foreach ([['shardingEnabled' => false], ['shardSize' => 2]] as $config) {
            $name = isset($config['shardSize']) ? 'gc2' : 'gc';
            $db = $this->db($config);
            $db->createSpatialIndex($name, 'loc');
            $db->insert($name, [
                ['loc' => ['type' => 'Point', 'coordinates' => [1, 1]]],
                ['loc' => ['type' => 'GeometryCollection', 'geometries' => []]],
                ['loc' => ['type' => 'GeometryCollection', 'geometries' => [['type' => 'GeometryCollection', 'geometries' => []]]]],
                ['loc' => ['type' => 'GeometryCollection', 'geometries' => [['type' => 'Point', 'coordinates' => [2, 2]], ['type' => 'GeometryCollection', 'geometries' => []]]]],
                ['loc' => ['type' => 'Point', 'coordinates' => [50, 50]]],
            ]);
            $this->assertSame([0, 3], $this->keysOf($db->withinPolygon($name, 'loc', ['type' => 'Polygon', 'coordinates' => [[[-10, -10], [10, -10], [10, 10], [-10, 10], [-10, -10]]]])));
            $nearest = $db->nearest($name, 'loc', 0, 0, 10);
            $this->assertSame([0, 3, 4], array_column($nearest, 'key'));
            $this->assertNotFalse(json_encode($nearest), 'no INF distances');
            $this->assertNotFalse(json_encode($db->query($name)->withDistance('loc', 0, 0)->get()));
            $this->assertSpatialIndexEqualsScan($name, $config);
        }
    }

    /** @test */
    public function nanAndInfiniteSpatialArgumentsTerminate(): void
    {
        // B-5: maxDistance NAN with fewer geometries than k (and k < count) looped forever
        $db = $this->db(['shardingEnabled' => false]);
        $db->createSpatialIndex('nan', 'loc');
        $db->insert('nan', [['loc' => ['type' => 'Point', 'coordinates' => [1, 1]]], ['x' => 1], ['x' => 2]]);
        \noneDB::clearStaticCache();
        $out = $this->runChild('
            set_time_limit(20);
            $db = ' . $this->childDb(['shardingEnabled' => false]) . ';
            echo count($db->nearest("nan", "loc", 1, 1, 2, ["maxDistance" => NAN])), ",";
            echo count($db->nearest("nan", "loc", NAN, 1, 2)), ",";
            echo count($db->withinDistance("nan", "loc", 1, 1, NAN)), ",";
            echo count($db->withinBBox("nan", "loc", NAN, 0, 2, 2)), ",";
            echo count($db->nearest("nan", "loc", 1, 1, 2, ["maxDistance" => INF])), ",";
            echo count($db->withinDistance("nan", "loc", 179, -89, INF)), ",";
            echo count($db->withinBBox("nan", "loc", -INF, -INF, INF, INF));
        ');
        $this->assertSame('0,0,0,0,1,1,1', $out);
    }

    /** @test */
    public function floatLookupsDoNotDependOnPrecision(): void
    {
        // B-6: the bucket key was (string)$float, which depends on the precision setting
        $before = ini_get('precision');
        try {
            $db = $this->db(['shardSize' => 3]);
            $db->createFieldIndex('fl', 'p');
            $db->insert('fl', [['p' => 0.1], ['p' => 0.2], ['p' => 1.0], ['p' => 1], ['p' => '1'], ['p' => 0.1]]);
            ini_set('precision', '17');
            \noneDB::clearStaticCache();
            $db = $this->db(['shardSize' => 3]);
            $this->assertSame([0, 5], array_column($db->find('fl', ['p' => 0.1]), 'key'));
            $this->assertSame([2], array_column($db->find('fl', ['p' => 1.0]), 'key'));
            $this->assertSame([0, 5], $db->getKeysFromFieldIndex('fl', 'p', 0.1));
        } finally {
            ini_set('precision', (string)$before);
        }
    }

    /** @test */
    public function fieldsDifferingOnlyInCaseGetTheirOwnIndexFiles(): void
    {
        // B-7: on case-insensitive file systems, files named after the raw field names "city" and "City"
        // would be one file (I1): names other than [a-z0-9_] get a hash, so "city" and "City" have their own
        // files on any file system
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('case', [['city' => 'A', 'City' => 'B'], ['city' => 'B', 'City' => 'A']]);
        $this->assertTrue($db->createFieldIndex('case', 'city')['success']);
        $this->assertTrue($db->createFieldIndex('case', 'City')['success']);
        $this->assertCount(2, glob($this->dbPath('case', 'gen') . 'indexes/main.*.fx'));
        // "city" is its own token; "City" is not [a-z0-9_]: lower-case prefix + "-" + 12 hex of its sha256
        // (STORAGE_FORMAT.md §5.1; the file is indexes/{segment}.{token}.fx)
        $this->assertFileExists($this->dbPath('case', 'gen') . 'indexes/main.city.fx');
        $this->assertFileExists($this->dbPath('case', 'gen') . 'indexes/main.city-' . substr(hash('sha256', 'City'), 0, 12) . '.fx');
        $this->assertSame([1], array_column($db->find('case', ['City' => 'A']), 'key'));
        $this->assertSame([0], array_column($db->find('case', ['city' => 'A']), 'key'));
        $db->insert('case', ['city' => 'A', 'City' => 'C']);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame([0, 2], array_column($db->find('case', ['city' => 'A']), 'key'));
        $this->assertSame([2], array_column($db->find('case', ['City' => 'C']), 'key'));
    }

    /** @test */
    public function rebuildIndexAlsoRebuildsFieldAndSpatialIndexes(): void
    {
        // B-8: after a manual restore of the data, rebuildIndex() left the other indexes stale
        $db = $this->db(['shardingEnabled' => false]);
        $db->createFieldIndex('rb', 'c');
        $db->createSpatialIndex('rb', 'loc');
        $db->insert('rb', ['c' => 'x', 'loc' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        $data = $this->dbPath('rb', 'data');
        file_put_contents($data, '{"c":"y","loc":{"type":"Point","coordinates":[5,5]},"key":0}' . "\n");
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $db->rebuildIndex('rb');
        $this->assertSame([0], array_column($db->find('rb', ['c' => 'y']), 'key'));
        $this->assertSame([], $db->find('rb', ['c' => 'x']));
        $this->assertSame([0], $this->keysOf($db->withinBBox('rb', 'loc', 4, 4, 6, 6)));
        $this->assertSame([], $db->withinBBox('rb', 'loc', 0, 0, 2, 2));
    }

    /** @test */
    public function missingGlobalSpatialEntryNeverSkipsAShard(): void
    {
        // B-9: a shard without an entry in the global spatial index was skipped
        $db = $this->db(['shardSize' => 3]);
        $db->createSpatialIndex('gs', 'loc');
        $db->insert('gs', array_map(function ($i) {
            return ['loc' => ['type' => 'Point', 'coordinates' => [$i * 10 - 80, $i * 5 - 40]]];
        }, range(0, 11)));
        $global = $this->dbPath('gs', 'grx', 'loc');
        $g = json_decode(trim(file_get_contents($global)), true);
        $this->assertArrayHasKey('1', $g['sh']);
        unset($g['sh']['1']);
        file_put_contents($global, json_encode($g) . "\n");
        \noneDB::clearStaticCache();
        $this->assertCount(12, $this->db(['shardSize' => 3])->withinBBox('gs', 'loc', -180, -90, 180, 90));
        unlink($global);
        \noneDB::clearStaticCache();
        $this->assertCount(12, $this->db(['shardSize' => 3])->withinBBox('gs', 'loc', -180, -90, 180, 90));
        $this->assertSpatialIndexEqualsScan('gs', ['shardSize' => 3]);
    }

    /** @test */
    public function indexFileNamesNeverExceedTheFileSystemLimit(): void
    {
        // B-10: the manifest listed the index before it was built; a too long name failed after that.
        // (I1): a field takes at most 40 bytes of a file name, so any field name works
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('long', ['v' => 1]);
        $field = str_repeat('ü', 110); // 220 bytes
        $this->assertTrue($db->createFieldIndex('long', $field)['success']);
        $this->assertTrue($db->createSpatialIndex('long', $field)['success']);
        $this->assertSame([$field], $db->getFieldIndexes('long')['fields']);
        $this->assertSame([$field], $db->getSpatialIndexes('long'));
        $this->assertSame(1, $db->insert('long', ['v' => 2, $field => 'x'])['n']);
        $this->assertSame([1], array_column($db->find('long', [$field => 'x']), 'key'));
        foreach ($this->dbFiles('long') as $file) {
            $this->assertLessThanOrEqual(255, strlen(basename($file)), $file);
        }

        // Index file names hold no database name (STORAGE_FORMAT.md §4, §5.1: {locator}.ndb4/.../indexes/
        // {segment}.{token}.fx): a long database name gets its indexes like any other
        $longName = str_repeat('a', 200);
        $db->insert($longName, ['v' => 1]);
        $this->assertSame(['success' => true, 'indexed' => 1, 'error' => null], $db->createFieldIndex($longName, 'v'));
        $this->assertTrue($db->createSpatialIndex($longName, 'loc')['success']);
        $this->assertSame(['v'], $db->getFieldIndexes($longName)['fields']);
        $this->assertSame(['loc'], $db->getSpatialIndexes($longName));
        $this->assertSame(1, $db->insert($longName, ['v' => 2, 'loc' => ['type' => 'Point', 'coordinates' => [1, 1]]])['n']);
        $this->assertSame([1], array_column($db->find($longName, ['v' => 2]), 'key'));
        $this->assertSame([1], $db->getKeysFromFieldIndex($longName, 'v', 2));
        $this->assertSame([1], array_column($db->withinBBox($longName, 'loc', 0, 0, 2, 2), 'key'));
        foreach ($this->dbFiles($longName) as $file) {
            $this->assertLessThanOrEqual(255, strlen(basename($file)), $file);
        }
        $this->assertTrue($db->createFieldIndex(str_repeat('a', 150), 'v')['success']);
    }

    /** @test */
    public function selfIntersectionCheckIsNotQuadratic(): void
    {
        // B-11: every query validated every candidate polygon with a test of all edge pairs
        $counter = new \noneDB\Tests\InternalValue('segmentPairTests');
        $ring = [];
        $n = 2000;
        for ($i = 0; $i < $n; $i++) {
            $ring[] = [cos(2 * M_PI * $i / $n) * 10, sin(2 * M_PI * $i / $n) * 10];
        }
        $ring[] = $ring[0];
        $polygon = ['type' => 'Polygon', 'coordinates' => [$ring]];
        $db = $this->db();
        $counter->setValue(null, 0);
        $this->assertTrue($db->validateGeoJSON($polygon)['valid']);
        $this->assertLessThan(20 * $n, $counter->getValue(), 'pairs compared (all pairs: about 2,000,000)');

        $bowtie = ['type' => 'Polygon', 'coordinates' => [[[0, 0], [2, 2], [2, 0], [0, 2], [0, 0]]]];
        $this->assertFalse($db->validateGeoJSON($bowtie)['valid']);
        $touching = ['type' => 'Polygon', 'coordinates' => [[[0, 0], [4, 0], [4, 4], [2, 0], [0, 4], [0, 0]]]];
        $this->assertFalse($db->validateGeoJSON($touching)['valid'], 'a vertex on another edge');
    }

    /** @test */
    public function objectValuesAreIndexedLikeTheirStoredForm(): void
    {
        // B-12: a stdClass/JsonSerializable value was stored as JSON but not indexed
        foreach ([['shardingEnabled' => false], ['shardSize' => 2]] as $config) {
            $name = isset($config['shardSize']) ? 'obj2' : 'obj';
            $db = $this->db($config);
            $db->createFieldIndex($name, 'c');
            $db->createSpatialIndex($name, 'loc');
            $db->insert($name, [
                ['c' => 'x', 'loc' => json_decode('{"type":"Point","coordinates":[1,1]}')],
                ['c' => 'y', 'loc' => ['type' => 'Point', 'coordinates' => [5, 5]]],
                ['c' => 'z', 'loc' => json_decode('{"type":"Point","coordinates":[3,3]}')],
            ]);
            $db->update($name, [['c' => 'y'], ['set' => ['loc' => json_decode('{"type":"Point","coordinates":[2,2]}')]]]);
            $this->assertSame([0, 1, 2], $this->keysOf($db->withinBBox($name, 'loc', 0, 0, 4, 4)), json_encode($config));
            $this->assertSpatialIndexEqualsScan($name, $config);
        }
    }

    /** @test */
    public function highCardinalityFieldKeepsItsGlobalIndexWithoutRewrites(): void
    {
        // T2-7: a unique field rewrote the whole global index (all values) on every insert. (I4): the
        // global index is a checkpoint of the shards' values, written when a shard's index is, never per
        // write (the shards' newer lines are checked instead).
        $db = $this->db(['shardSize' => 100]);
        $db->createFieldIndex('hc', 'u');
        $db->createFieldIndex('hc', 'g');
        $db->insert('hc', array_map(function ($n) { return ['u' => 'u' . $n, 'g' => 'g' . ($n % 5)]; }, range(0, 149)));
        $file = $this->dbPath('hc', 'gfx', 'u');
        $this->assertFileExists($file);
        $this->assertFileExists($this->dbPath('hc', 'gfx', 'g'));
        $before = file_get_contents($file);
        $off = $this->db(['shardSize' => 100]);
        foreach ([$db, $off, $db] as $i => $writer) {
            $writer->insert('hc', ['u' => 'new' . $i, 'g' => 'g1']);
        }
        $this->assertSame($before, file_get_contents($file), 'not rewritten by an insert');
        $scan = $this->db(['shardSize' => 100, 'fieldIndexEnabled' => false]);
        foreach ([['u' => 'u7'], ['u' => 'u120'], ['u' => 'new1'], ['u' => 'none'], ['g' => 'g1'], ['u' => 'u77', 'g' => 'g2']] as $filter) {
            $this->assertSame($scan->find('hc', $filter), $db->find('hc', $filter), json_encode($filter));
        }
        // rebuildIndex() writes it again from the data: the three new values (keys 150..152) are in shard 1.
        // The filters (STORAGE_FORMAT.md §6.5.2) hold the 150 + 3 unique values: shard 0 its 100, shard 1 its 53;
        // 4096 bits (the smallest power of two of at least 3200)
        $off->rebuildIndex('hc');
        $this->assertNotSame($before, file_get_contents($file));
        [$header, $filters] = $this->readGlobalFieldFile($file);
        $this->assertSame(4096, $header['bits']);
        $u = function (int $from, int $to) { return array_map(function ($n) { return 'u' . $n; }, range($from, $to)); };
        $this->assertSame([0 => $this->shardFilter($u(0, 99), 4096), 1 => $this->shardFilter(array_merge($u(100, 149), ['new0', 'new1', 'new2']), 4096)], $filters);
        $this->assertSame($scan->find('hc', ['u' => 'new2']), $off->find('hc', ['u' => 'new2']));
    }
}
