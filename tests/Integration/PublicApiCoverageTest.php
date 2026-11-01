<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * T-03: public methods that had no tests
 */
class PublicApiCoverageTest extends RegressionTestCase
{
    /** @test */
    public function bufferingApi(): void
    {
        $db = $this->db();
        $this->assertFalse($db->isBufferingEnabled(), 'off by default');
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
        $waiting = $db->getBufferInfo('bf')['buffers']['main']['records'];
        $this->assertLessThan(10, $waiting);
        $this->assertGreaterThan(0, $waiting, '12 records, a flush at 10: some wait again');
        $this->assertSame(12 - $waiting, count($this->dataLines($this->dbPath('bf', 'data'))), 'the rest is in the data file');
        $db->insert('other', ['x' => 1]);
        $r = $db->flushAllBuffers();
        $this->assertSame(2, $r['databases']);
        $this->assertSame($waiting + 1, $r['flushed'], 'every buffered record of both databases');
        $this->assertFalse($db->getBufferInfo('bf')['buffers']['main']['exists']);
        $this->assertSame(range(0, 11), array_column($db->find('bf', 0), 'i'));
        $this->assertSame(['success' => true, 'flushed' => 0, 'error' => null], $db->flush('bf'));
    }

    /** @test */
    public function indexApi(): void
    {
        $db = $this->db();
        $this->assertNull($db->getIndexInfo('ix'));
        $this->assertSame(['success' => false, 'error' => 'Database does not exist'], $db->rebuildIndex('ix'));
        // No database created: no FORMAT, at most an empty location (only LOCK, STORAGE_FORMAT.md §8.3)
        $this->assertSame([], array_values(array_diff($this->dbFiles('ix'), ['LOCK'])), 'no database created');
        $this->assertSame([], $db->getDBs());
        $db->insert('ix', [['c' => 'a'], ['c' => 'b'], ['c' => 'a']]);
        $db->delete('ix', ['key' => 1]);
        $info = $db->getIndexInfo('ix');
        $this->assertTrue($info['exists']);
        $this->assertSame(1, $info['version'], 'the key-index checkpoint version (STORAGE_FORMAT.md §6.4: {"kind":"kix","v":1})');
        $this->assertSame(2, $info['totalRecords']);
        $this->assertFalse($info['sharded']);
        $this->assertSame($this->dbPath('ix', 'kix'), $info['path']);

        unlink($this->dbPath('ix', 'kix'));
        $r = $db->rebuildIndex('ix');
        $this->assertTrue($r['success']);
        $this->assertSame(2, $r['totalRecords']);
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?ms$/', $r['time']);
        $this->assertFileExists($this->dbPath('ix', 'kix'), 'rebuilt as a checkpoint');
        $this->assertSame([0, 2], array_column($db->find('ix', 0), 'key'));

        $this->assertNull($db->getKeysFromFieldIndex('ix', 'c', 'a'), 'no index yet');
        $db->createFieldIndex('ix', 'c');
        $this->assertSame([0, 2], $db->getKeysFromFieldIndex('ix', 'c', 'a'));
        $this->assertSame(['success' => true, 'indexed' => 2, 'error' => null], $db->rebuildFieldIndex('ix', 'c'));
    }

    /** @test */
    public function spatialHelpers(): void
    {
        $db = $this->db();
        $this->assertEqualsWithDelta(0, $db->haversineDistance(29, 41, 29, 41), 0.0001);
        $this->assertEqualsWithDelta(111194.9, $db->haversineDistance(0, 0, 1, 0), 1);
        // Removed in noneDB 4 (they had no effect): the API no longer has them
        foreach (['clearDistanceCache', 'clearCentroidCache', 'flushSpatialIndexes', 'upgradeIndexes', 'enableIndexing', 'isIndexingEnabled'] as $removed) {
            $this->assertFalse(method_exists(\noneDB::class, $removed), $removed);
        }

        $db->insert('sp', ['loc' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        $db->createSpatialIndex('sp', 'loc');
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
