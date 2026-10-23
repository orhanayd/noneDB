<?php

namespace noneDB\Tests\Regression;

/**
 * T-03: public methods that had no tests, plus buffers left by pre-3.2 versions (K-08)
 */
class PublicApiCoverageTest extends RegressionTestCase
{
    /** @test */
    public function bufferingApi(): void
    {
        $db = $this->db();
        $this->assertFalse($db->isBufferingEnabled(), 'off by default since 3.2');
        $db->enableBuffering(true);
        $this->assertTrue($db->isBufferingEnabled());
        $db->setBufferSizeLimit(10);       // clamped to 1KB
        $db->setBufferCountLimit(3);       // clamped to 10
        $db->setBufferFlushInterval(0);

        for ($i = 0; $i < 5; $i++) {
            $db->insert('bf', ['i' => $i]);
        }
        $info = $db->getBufferInfo('bf');
        $this->assertTrue($info['enabled']);
        $this->assertSame(1024, $info['sizeLimit']);
        $this->assertSame(10, $info['countLimit']);
        $this->assertSame(5, $info['buffers']['main']['records']);
        $this->assertTrue($info['buffers']['main']['exists']);

        for ($i = 5; $i < 12; $i++) {
            $db->insert('bf', ['i' => $i]); // the 10th record triggers a flush
        }
        $this->assertLessThan(10, $db->getBufferInfo('bf')['buffers']['main']['records']);
        $db->insert('other', ['x' => 1]);
        $r = $db->flushAllBuffers();
        $this->assertSame(2, $r['databases']);
        $this->assertFalse($db->getBufferInfo('bf')['buffers']['main']['exists']);
        $this->assertSame(range(0, 11), array_column($db->find('bf', 0), 'i'));
        $this->assertSame(['success' => true, 'flushed' => 0, 'error' => null], $db->flush('bf'));
    }

    /** @test K-08 */
    public function interruptedFlushIsRecovered(): void
    {
        $db = $this->db();
        $db->insert('fl', ['i' => 0]);
        $buffer = $this->dbBasePath('fl') . '.nonedb.buffer';
        // Left by a pre-3.2 version (".flushing" without start key; those versions had no lock file)
        file_put_contents($buffer . '.flushing', json_encode(['i' => 1]) . "\n" . json_encode(['i' => 2]) . "\n");
        file_put_contents($buffer, json_encode(['i' => 3]) . "\n");
        $this->removeLockFile('fl');
        $this->assertSame([0, 1, 2, 3], array_column($this->db()->find('fl', 0), 'i'));
        $this->assertNotContains('b', $this->lockState('fl')['flags'], 'buffer flag cleared after the flush');
        $this->assertFileDoesNotExist($buffer);
        $this->assertFileDoesNotExist($buffer . '.flushing');
    }

    /** @test K-08 pre-3.2 shard buffers (already counted in the meta) */
    public function legacyShardBuffersAreFlushed(): void
    {
        $db = $this->db(['shardSize' => 5]);
        $rows = [];
        for ($i = 0; $i < 7; $i++) {
            $rows[] = ['i' => $i];
        }
        $db->insert('ls', $rows);
        // An old version had buffered two records for shard 1 and counted them in the meta
        $metaPath = $this->dbBasePath('ls') . '.nonedb.meta';
        $meta = json_decode(file_get_contents($metaPath), true);
        $meta['nextKey'] += 2;
        $meta['totalRecords'] += 2;
        $meta['shards'][1]['count'] += 2;
        file_put_contents($metaPath, json_encode($meta));
        file_put_contents($this->dbBasePath('ls') . '_s1.nonedb.buffer', json_encode(['i' => 7]) . "\n" . json_encode(['i' => 8]) . "\n");

        $this->removeLockFile('ls'); // pre-3.2 versions had no lock file
        $db = $this->db();
        $this->assertSame(range(0, 8), array_column($db->find('ls', 0), 'i'));
        $this->assertSame(range(0, 8), array_column($db->find('ls', 0), 'key'));
        $this->assertSame(9, $db->count('ls'));
        $db->insert('ls', ['i' => 9]);
        $this->assertSame(9, $db->find('ls', ['i' => 9])[0]['key']);
    }

    /** @test */
    public function indexApi(): void
    {
        $db = $this->db();
        $db->enableIndexing(false);
        $this->assertFalse($db->isIndexingEnabled());
        $db->enableIndexing(true);
        $this->assertTrue($db->isIndexingEnabled());

        $this->assertNull($db->getIndexInfo('ix'));
        $this->assertFalse($db->rebuildIndex('ix')['success']);
        $db->insert('ix', [['c' => 'a'], ['c' => 'b'], ['c' => 'a']]);
        $db->delete('ix', ['key' => 1]);
        $info = $db->getIndexInfo('ix');
        $this->assertTrue($info['exists']);
        $this->assertSame(2, $info['totalRecords']);
        $this->assertFalse($info['sharded']);

        unlink($this->dbBasePath('ix') . '.nonedb.jidx');
        $r = $db->rebuildIndex('ix');
        $this->assertTrue($r['success']);
        $this->assertSame(2, $r['totalRecords']);
        $this->assertSame([0, 2], array_column($db->find('ix', 0), 'key'));

        $this->assertNull($db->getKeysFromFieldIndex('ix', 'c', 'a'), 'no index yet');
        $db->createFieldIndex('ix', 'c');
        $this->assertSame([0, 2], $db->getKeysFromFieldIndex('ix', 'c', 'a'));
        $this->assertSame(['success' => true, 'indexed' => 2, 'values' => 1, 'error' => null], $db->rebuildFieldIndex('ix', 'c'));
    }

    /** @test */
    public function spatialHelpers(): void
    {
        $db = $this->db();
        $this->assertEqualsWithDelta(0, $db->haversineDistance(29, 41, 29, 41), 0.0001);
        $this->assertEqualsWithDelta(111194.9, $db->haversineDistance(0, 0, 1, 0), 1);
        $db->clearDistanceCache();
        $db->clearCentroidCache();

        $db->insert('sp', ['loc' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        $db->createSpatialIndex('sp', 'loc');
        $this->assertSame(0, $db->flushSpatialIndexes(), 'nothing pending outside a write');
        $r = $db->rebuildSpatialIndex('sp', 'loc');
        $this->assertTrue($r['success']);
        $this->assertSame(1, $r['indexed']);
        $this->assertTrue($db->hasSpatialIndex('sp', 'loc'));
        $this->assertTrue($db->dropSpatialIndex('sp', 'loc')['success']);
        $this->assertFalse($db->hasSpatialIndex('sp', 'loc'));
        $this->assertCount(1, $db->withinDistance('sp', 'loc', 1, 1, 10), 'queries still work without an index');
    }

    /** @test */
    public function staticCacheSwitch(): void
    {
        \noneDB::disableStaticCache();
        $a = $this->db();
        $b = $this->db();
        $a->insert('sc', ['v' => 1]);
        $this->assertCount(1, $b->find('sc', 0));
        $b->insert('sc', ['v' => 2]);
        $this->assertCount(2, $a->find('sc', 0), 'separate caches still see each other\'s writes');
        \noneDB::enableStaticCache();
        $this->assertCount(2, $this->db()->find('sc', 0));
    }
}
