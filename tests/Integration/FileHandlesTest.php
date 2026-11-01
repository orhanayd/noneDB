<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;
use noneDB\Tests\Support\Testing;

/**
 * Windows replaces no file that is open and moves no directory with an open file in it; Linux and macOS do both, so their
 * runs never see a handle left open. Here every rename of the engine looks for one in this process
 */
class FileHandlesTest extends RegressionTestCase
{
    /** @var string[] what was open at a rename */
    private $open = [];

    protected function setUp(): void
    {
        parent::setUp();
        Testing::set('renameFn', function ($from, $to) {
            foreach (get_resources('stream') as $stream) {
                $uri = stream_get_meta_data($stream)['uri'] ?? '';
                if ($uri === $to || strpos($uri, rtrim($from, '/') . '/') === 0) {
                    $this->open[] = basename((string)$uri);
                }
            }
            return rename($from, $to);
        });
    }

    private function records(int $n): array
    {
        $records = [];
        for ($i = 0; $i < $n; $i++) {
            $records[] = ['g' => 'g' . ($i % 7)];
        }
        return $records;
    }

    /** The keys of a value in a shard of 100, as records() makes them */
    private static function keysOf(string $value, int $shard): array
    {
        return array_values(array_filter(range(100 * $shard, 100 * $shard + 99), function ($key) use ($value) { return 'g' . ($key % 7) === $value; }));
    }

    /** @test a shard's filter goes into the global file: the old file, which its rows are copied from, is closed by then */
    public function aGlobalFileIsNotOpenWhenItIsReplaced(): void
    {
        $config = ['shardSize' => 100, 'autoCompactThreshold' => 1000]; // no compaction: it would replace the shard's data
        $db = $this->db($config);
        $db->insert('gf', $this->records(300));
        $this->assertTrue($db->createFieldIndex('gf', 'g')['success']);
        $gfx = $this->dbPath('gf', 'gfx', 'g');
        $before = file_get_contents($gfx);
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(self::keysOf('g3', 1), $db->getKeysFromFieldIndex('gf', 'g', 'g3', 1));
        $this->assertSame(100, $db->update('gf', [['key' => range(100, 199)], ['set' => ['g' => 'new', 'pad' => str_repeat('y', 1000)]]])['n']);
        $this->assertNotSame($before, file_get_contents($gfx), 'written again');
        $this->assertSame([], $this->open);
    }

    /** @test the same when a row of the old file is damaged and the file is made again */
    public function aGlobalFileWithADamagedRowIsNotOpenWhenItIsMadeAgain(): void
    {
        $config = ['shardSize' => 100, 'autoCompactThreshold' => 1000];
        $db = $this->db($config);
        $db->insert('gd', $this->records(300));
        $this->assertTrue($db->createFieldIndex('gd', 'g')['success']);
        $gfx = $this->dbPath('gd', 'gfx', 'g');
        $raw = (string)file_get_contents($gfx);
        $end = strlen($raw) - 2;
        $raw[$end] = chr(ord($raw[$end]) ^ 1);
        file_put_contents($gfx, $raw);
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(self::keysOf('g3', 1), $db->getKeysFromFieldIndex('gd', 'g', 'g3', 1));
        $this->assertSame(100, $db->update('gd', [['key' => range(100, 199)], ['set' => ['pad' => str_repeat('y', 1000)]]])['n']);
        $this->assertNotSame($raw, file_get_contents($gfx), 'made again');
        $this->assertSame([], $this->open);
    }

    /** @test the writes that replace files or move directories: compaction, the switch to shards, a rotation, a restore */
    public function nothingIsOpenWhenMaintenanceReplacesAFile(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('mt', $this->records(200));
        $this->assertTrue($db->createFieldIndex('mt', 'g')['success']);
        $this->assertSame(10, $db->delete('mt', ['key' => range(0, 9)])['n']);
        $db->compact('mt');
        $this->assertTrue($db->rebuildIndex('mt')['success']);
        $db->convertToSharded('mt');
        $backup = $this->testDbDir . '../handles-backup';
        $this->removeDirectoryTree($backup);
        try {
            $db->backup('mt', $backup);
            $db->restore($backup, 'mt');
        } finally {
            $this->removeDirectoryTree($backup);
        }
        $this->assertCount(190, $db->find('mt', 0));
        unset($db);
        $this->noneDB = null;
        $this->assertTrue(\noneDB::migrateKey($this->testDbDir, 'test_secret_key_for_unit_tests', 'another_secret_key_for_handles')['success']);
        $this->assertSame([], $this->open);
    }
}
