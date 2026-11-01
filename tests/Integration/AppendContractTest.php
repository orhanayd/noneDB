<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\Support\Testing;
use noneDB\Tests\RegressionTestCase;

/**
 * The append contract, the steps without a test of their own elsewhere:
 * 1. a database file is written only under the database's exclusive lock;
 * 7. a write that fails after its append returns no success and leaves nothing applied.
 */
class AppendContractTest extends RegressionTestCase
{
    private function bytes(string $file): string
    {
        clearstatcache(true, $file);
        return (string)file_get_contents($file);
    }

    /** @test step 1: every write helper refuses a database file without the database's exclusive lock */
    public function aDatabaseFileIsWrittenOnlyUnderItsExclusiveLock(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('ap', [['v' => 1], ['v' => 2]]);
        $data = $this->dbPath('ap', 'data');
        $before = $this->bytes($data);
        $writes = [
            'append' => function () use ($db, $data) { Testing::call($db, 'appendToFile', $data, "{\"v\":3,\"key\":2}\n", 'ap'); },
            'replace' => function () use ($db, $data) { Testing::call($db, 'writeFileAtomic', $data, '', 'replace'); },
            'remove' => function () use ($db, $data) { Testing::call($db, 'fsUnlink', $data); },
            'truncate' => function () use ($db, $data) { Testing::call($db, 'fsTruncate', $data, 0); },
            'record' => function () use ($db) { Testing::call($db, 'writeFileAtomic', $this->dbPath('ap', 'current'), '{}', 'durable'); },
        ];
        foreach ($writes as $what => $write) {
            // No lock at all
            try {
                $write();
                $this->fail("$what without a lock was allowed");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('without the lock', $e->getMessage(), $what);
            }
            // The shared lock of a reader
            try {
                Testing::call($db, 'withLock', 'ap', false, $write);
                $this->fail("$what under a shared lock was allowed");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('shared lock', $e->getMessage(), $what);
            }
            // Another database's exclusive lock
            $db->insert('other', ['v' => 1]);
            try {
                Testing::call($db, 'withLock', 'other', true, $write);
                $this->fail("$what under another database's lock was allowed");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('without the lock', $e->getMessage(), $what);
            }
            $this->assertSame($before, $this->bytes($data), "$what: nothing written");
        }
        \noneDB::clearStaticCache();
        $this->assertSame([['v' => 1], ['v' => 2]], $this->plain($this->db()->find('ap', 0)));
        $this->assertTrue($this->db()->checkIntegrity('ap')['ok']);
    }

    /** @test step 7: a failure after the bytes were appended (before the commit) returns no success and applies nothing */
    public function aFailureAfterTheAppendAppliesNothing(): void
    {
        foreach (['single' => ['shardingEnabled' => false], 'sharded' => ['shardSize' => 2], 'buffered' => ['shardingEnabled' => false, 'bufferEnabled' => true]] as $case => $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->insert('ac', [['v' => 1], ['v' => 2], ['v' => 3]]);
            foreach (['append:after', 'index:written'] as $point) {
                if ($case === 'buffered' && $point === 'index:written') {
                    continue; // a buffered insert appends to the buffer only: no key index changes in it
                }
                $this->setFaultHook(function ($p) use ($point) {
                    if ($p === $point) {
                        throw new \RuntimeException("fault at $point");
                    }
                });
                try {
                    $result = $db->insert('ac', [['v' => 4], ['v' => 5]]);
                    $this->fail("$case/$point: the insert returned " . json_encode($result));
                } catch (\RuntimeException $e) {
                    $this->assertSame("fault at $point", $e->getMessage(), "$case/$point");
                } finally {
                    $this->setFaultHook(null);
                }
                $this->assertSame('rolled_back', $db->getDurability()['lastOperation']['outcome'], "$case/$point");
                $this->assertSame([['v' => 1], ['v' => 2], ['v' => 3]], $this->plain($db->find('ac', 0)), "$case/$point: in this process");
                \noneDB::clearStaticCache();
                $this->assertSame([['v' => 1], ['v' => 2], ['v' => 3]], $this->plain($this->db($config)->find('ac', 0)), "$case/$point: after a restart");
                $db = $this->db($config);
            }
        }
    }
}
