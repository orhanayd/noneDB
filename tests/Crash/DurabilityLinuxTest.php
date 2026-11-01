<?php

namespace noneDB\Tests\Crash;

use noneDB\Tests\RegressionTestCase;

/**
 * Durable mode on Linux with the real fsync() of files and directories (no hook, no platform override):
 * every write operation commits with every sync it needs done (docs/DURABILITY.md §1, §3, §6).
 * The failure paths of each sync are in DurabilityContractTest.
 */
class DurabilityLinuxTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('durable mode is supported on Linux only (UNSUPPORTED_DURABILITY elsewhere: DurabilityConfigTest)');
        }
    }

    /** The last operation committed with every required sync done ($required: the kinds §3 gives it, null: any) */
    private function committed(\noneDB $db, string $op, ?array $required): void
    {
        $last = $db->getDurability()['lastOperation'];
        $this->assertSame([$op, 'committed'], [$last['op'], $last['outcome']], json_encode($last));
        $this->assertSame($last['required'], $last['completed'], $op);
        if ($required !== null) {
            $this->assertSame($required, $last['required'], $op);
        }
    }

    /** @test every write operation commits with its syncs done, on the real file system */
    public function everyOperationCommitsWithItsSyncs(): void
    {
        $db = $this->db(['durability' => 'durable', 'shardSize' => 4, 'autoCompactThreshold' => 1000]);
        $this->assertSame(['os' => 'Linux', 'durableSupported' => true, 'notes' => []], $db->getDurability()['platform']);
        $before = $db->getDurability()['syncs'];
        $errorBefore = $db->getDurability()['lastSyncError']; // process-wide: an earlier test may have left one

        $db->createDB('lx');
        $this->committed($db, 'createDB', ['file', 'dir']);   // records and new directories
        $db->insert('lx', [['v' => 1], ['v' => 2], ['v' => 3]]);
        $this->committed($db, 'insert', ['file', 'dir']);     // the first append to the empty data file
        $db->insert('lx', ['v' => 4]);
        $this->committed($db, 'insert', ['file']);            // an append to an existing file
        $db->insert('lx', [['v' => 5], ['v' => 6]]);          // past shardSize: the switch to shards follows it
        $this->committed($db, 'insert', null);
        $db->insert('lx', [['v' => 7], ['v' => 8]]);          // sharded: j, the key reservation (a replacement)
        $this->committed($db, 'insert', ['file', 'dir']);
        $db->update('lx', [['v' => 1], ['set' => ['w' => 1]]]);
        $this->committed($db, 'update', null);
        $db->delete('lx', ['v' => 2]);
        $this->committed($db, 'delete', null);
        $db->compact('lx');
        $this->committed($db, 'compact', ['file', 'dir']);    // replaced data files
        $db->createFieldIndex('lx', 'v');
        $this->committed($db, 'createFieldIndex', ['file', 'dir']); // indexes.json

        $single = $this->db(['durability' => 'durable', 'shardingEnabled' => false, 'shardSize' => 2]);
        $single->insert('ls', [['v' => 1], ['v' => 2], ['v' => 3]]);
        $single->insert('ls', ['v' => 4]);
        $this->committed($single, 'insert', ['file']);
        $single->convertToSharded('ls');
        $this->committed($single, 'convertToSharded', ['file', 'dir']);

        $buffered = $this->db(['durability' => 'durable', 'shardingEnabled' => false, 'bufferEnabled' => true]);
        $buffered->insert('lb', ['v' => 1]);
        $buffered->insert('lb', ['v' => 2]);
        $this->committed($buffered, 'insert', null);
        $buffered->flush('lb');
        $this->committed($buffered, 'flush', null);

        $db->dropDB('lb');
        $this->committed($db, 'dropDB', ['file', 'dir']);    // "d" and FORMAT's removal

        $backup = realpath(sys_get_temp_dir()) . '/nonedb_backup_' . bin2hex(random_bytes(6));
        try {
            $syncs = $db->getDurability()['syncs'];
            $db->backup('lx', $backup);
            $this->assertGreaterThan($syncs['file'], $db->getDurability()['syncs']['file'], 'backup: every copy synced');
            $db->insert('lx', ['v' => 9]);
            $db->restore($backup, 'lx');
            $this->committed($db, 'restore', ['file', 'dir']);   // the copies, INTENT, CURRENT
        } finally {
            $this->removeDirectoryTree($backup);
        }

        $after = $db->getDurability()['syncs'];
        $this->assertGreaterThan($before['file'], $after['file']);
        $this->assertGreaterThan($before['dir'], $after['dir']);
        $this->assertSame($before['failed'], $after['failed']);
        $this->assertSame($errorBefore, $db->getDurability()['lastSyncError'], 'no sync failed');

        \noneDB::clearStaticCache();
        $check = $this->db(['shardSize' => 4]);
        $this->assertSame([['v' => 1, 'w' => 1], ['v' => 3], ['v' => 4], ['v' => 5], ['v' => 6], ['v' => 7], ['v' => 8]], $this->plain($check->find('lx', 0)));
        // (restored from the backup taken before v 9)
        $this->assertTrue($check->getShardInfo('lx')['sharded']);
        $this->assertSame([['v' => 1], ['v' => 2], ['v' => 3], ['v' => 4]], $this->plain($check->find('ls', 0)));
        $this->assertTrue($check->getShardInfo('ls')['sharded']);
        $this->assertSame([], $check->find('lb', 0));
        $this->assertTrue($check->checkIntegrity('lx')['ok']);
        $this->assertTrue($check->checkIntegrity('ls')['ok']);
    }

    /** @test key maintenance in durable mode on the real file system */
    public function maintenanceCommitsDurably(): void
    {
        $this->db(['durability' => 'durable'])->insert('lm', ['v' => 1]);
        $new = 'rotated_linux_key_0123456789';
        $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, $new, ['durability' => 'durable']);
        $this->assertTrue($result['success'], (string)$result['error']);
        \noneDB::clearStaticCache();
        $this->assertSame([['v' => 1]], $this->plain((new \noneDB(['secretKey' => $new, 'dbDir' => $this->testDbDir]))->find('lm', 0)));
    }
}
