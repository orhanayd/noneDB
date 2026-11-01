<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\Support\Testing;
use noneDB\Tests\RegressionTestCase;

/**
 * The durability setting, the platform rule and the report (docs/DURABILITY.md §1, §2, §6).
 * Expected values come from the document, never from the engine's output.
 */
class DurabilityConfigTest extends RegressionTestCase
{
    protected function tearDown(): void
    {
        foreach (['osFamily', 'syncFn', 'flushFn', 'writeFn', 'renameFn'] as $hook) {
            Testing::set($hook, null);
        }
        parent::tearDown();
    }

    private function onPlatform(string $os): void
    {
        Testing::set('osFamily', $os);
    }

    private function code(callable $fn): ?int
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            return $e->getCode();
        }
        return null;
    }

    /** @test DURABILITY.md §2, the error codes of STORAGE_FORMAT §11 */
    public function theOutcomeCodesAreTheDocumentedOnes(): void
    {
        $this->assertSame(3301, \noneDB::OUTCOME_UNKNOWN);
        $this->assertFalse(defined('noneDB::ROLLBACK_FAILED'), 'one name per code (API.md "Errors"): a failed undo is OUTCOME_UNKNOWN');
        $this->assertSame(3306, \noneDB::UNSUPPORTED_DURABILITY);
        $this->assertSame(3307, \noneDB::DURABILITY_FAILED);
    }

    /** @test §1: performance is the default; the two modes are the only values */
    public function theModeIsPerformanceUnlessDurableIsAsked(): void
    {
        $this->assertSame('performance', $this->db()->getDurability()['mode']);
        $this->assertSame('performance', $this->db(['durability' => 'performance'])->getDurability()['mode']);
        $this->onPlatform('Linux');
        $this->assertSame('durable', $this->db(['durability' => 'durable'])->getDurability()['mode']);

        foreach ([true, false, 1, 0, 'Durable', 'DURABLE', ' durable', 'fsync', 'full', 'none', '', [], 'on'] as $value) {
            try {
                $this->db(['durability' => $value]);
                $this->fail('accepted durability ' . var_export($value, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("'durability'", $e->getMessage());
            }
        }
    }

    /** @test §1: durable mode is refused where it can't be provided, never downgraded */
    public function durableModeIsRefusedOffLinux(): void
    {
        foreach (['Darwin', 'Windows', 'BSD', 'Solaris', 'Unknown'] as $os) {
            $this->onPlatform($os);
            try {
                $this->db(['durability' => 'durable']);
                $this->fail("durable mode accepted on $os");
            } catch (\RuntimeException $e) {
                $this->assertSame(\noneDB::UNSUPPORTED_DURABILITY, $e->getCode(), $os);
                $this->assertStringContainsString($os, $e->getMessage());
            }
            // Performance mode works everywhere
            $this->assertSame('performance', $this->db(['durability' => 'performance'])->getDurability()['mode']);
        }
        // Nothing was created by a refused instance
        $this->assertSame([], array_values(array_diff(scandir($this->testDbDir) ?: [], ['.', '..'])));

        $this->onPlatform('Linux');
        $this->assertSame('durable', $this->db(['durability' => 'durable'])->getDurability()['mode']);
    }

    /** @test §1 on the platform the tests run on (no override) */
    public function theRealPlatformDecides(): void
    {
        $code = $this->code(function () { $this->db(['durability' => 'durable']); });
        if (PHP_OS_FAMILY === 'Linux') {
            $this->assertNull($code);
        } else {
            $this->assertSame(\noneDB::UNSUPPORTED_DURABILITY, $code);
        }
        $platform = $this->db()->getDurability()['platform'];
        $this->assertSame(PHP_OS_FAMILY, $platform['os']);
        $this->assertSame(PHP_OS_FAMILY === 'Linux', $platform['durableSupported']);
    }

    /** @test §6: the fields of the report */
    public function theReportHasTheDocumentedShape(): void
    {
        $this->onPlatform('Darwin');
        $report = $this->db()->getDurability();
        $this->assertSame(['mode', 'platform', 'lastOperation', 'lastSyncError', 'syncs'], array_keys($report));
        $this->assertSame(['os', 'durableSupported', 'notes'], array_keys($report['platform']));
        $this->assertSame('Darwin', $report['platform']['os']);
        $this->assertFalse($report['platform']['durableSupported']);
        $this->assertNotEmpty($report['platform']['notes'], 'why durable mode is not available');
        $this->assertStringContainsString('F_FULLFSYNC', implode(' ', $report['platform']['notes']));
        $this->assertSame(['file', 'dir', 'failed'], array_keys($report['syncs']));

        $this->onPlatform('Windows');
        $notes = implode(' ', $this->db()->getDurability()['platform']['notes']);
        $this->assertStringContainsString('directory', $notes);

        $this->onPlatform('Linux');
        $report = $this->db()->getDurability();
        $this->assertTrue($report['platform']['durableSupported']);
        $this->assertSame([], $report['platform']['notes']);
    }

    /** @test §6: lastOperation of a committed write, in both modes */
    public function aCommittedWriteIsReported(): void
    {
        $db = $this->db();
        $db->insert('rep', ['v' => 1]);
        $last = $db->getDurability()['lastOperation'];
        $this->assertSame(['op', 'db', 'outcome', 'required', 'completed', 'reason'], array_keys($last));
        $this->assertSame('insert', $last['op']);
        $this->assertSame('rep', $last['db']);
        $this->assertSame('committed', $last['outcome']);
        $this->assertSame([], $last['required'], 'performance mode requires no sync');
        $this->assertSame([], $last['completed']);
        $this->assertNull($last['reason']);

        $this->onPlatform('Linux');
        $durable = $this->db(['durability' => 'durable']);
        $durable->insert('rep', ['v' => 2]); // append to an existing file
        $last = $durable->getDurability()['lastOperation'];
        $this->assertSame('committed', $last['outcome']);
        $this->assertSame(['file'], $last['required']);
        $this->assertSame(['file'], $last['completed']);

        $durable->insert('rep2', ['v' => 1]); // creates the database and its files
        $last = $durable->getDurability()['lastOperation'];
        $this->assertSame(['insert', 'rep2', 'committed'], [$last['op'], $last['db'], $last['outcome']]);
        $this->assertSame(['file', 'dir'], $last['required']);
        $this->assertSame(['file', 'dir'], $last['completed']);
    }

    /** @test §6: each public write names its operation; reads and writes that change nothing keep the report */
    public function operationsAreNamed(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $op = function () use ($db) { $last = $db->getDurability()['lastOperation']; return $last === null ? null : [$last['op'], $last['db'], $last['outcome']]; };
        $db->createDB('ops');
        $this->assertSame(['createDB', 'ops', 'committed'], $op());
        $db->insert('ops', [['v' => 1], ['v' => 2], ['v' => 3]]);
        $this->assertSame(['insert', 'ops', 'committed'], $op());
        $db->update('ops', [['v' => 1], ['set' => ['w' => 1]]]);
        $this->assertSame(['update', 'ops', 'committed'], $op());
        $db->delete('ops', ['v' => 2]);
        $this->assertSame(['delete', 'ops', 'committed'], $op());
        $db->find('ops', 0);
        $db->count('ops');
        $this->assertSame(['delete', 'ops', 'committed'], $op(), 'reads are not write operations');
        $db->update('ops', [['v' => 99], ['set' => ['w' => 2]]]);
        $this->assertSame(['delete', 'ops', 'committed'], $op(), 'a write that matched nothing wrote nothing');
        $db->createFieldIndex('ops', 'v');
        $this->assertSame(['createFieldIndex', 'ops', 'committed'], $op());
        $db->compact('ops');
        $this->assertSame(['compact', 'ops', 'committed'], $op());
        $db->convertToSharded('ops');
        $this->assertSame(['convertToSharded', 'ops', 'committed'], $op());
        $db->query('ops')->where(['v' => 1])->update(['x' => 1]);
        $this->assertSame(['update', 'ops', 'committed'], $op());
        $db->query('ops')->where(['v' => 1])->removeFields(['x']);
        $this->assertSame(['removeFields', 'ops', 'committed'], $op());
        $db->query('ops')->where(['v' => 3])->delete();
        $this->assertSame(['delete', 'ops', 'committed'], $op());
        $db->dropDB('ops');
        $this->assertSame(['dropDB', 'ops', 'committed'], $op());
    }

    /** @test §6: "Paths are relative to dbDir. No key material appears." */
    public function theLastSyncErrorHasNoAbsolutePathAndNoKey(): void
    {
        $this->onPlatform('Linux');
        $db = $this->db(['durability' => 'durable']);
        $db->insert('err', ['v' => 1]);
        Testing::set('syncFn', function ($handle, string $kind, $path) {
            return !($kind === 'file' && substr((string)$path, -11) === '/main.jsonl');
        });
        try {
            $db->insert('err', ['v' => 2]);
            $this->fail('a failed sync must fail the write');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString($this->testDbDir, $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
        Testing::set('syncFn', null);
        $error = $db->getDurability()['lastSyncError'];
        $this->assertSame(['path', 'op', 'time'], array_keys($error));
        $this->assertSame('file', $error['op']);
        $this->assertSame($this->locatorOf('err') . '.ndb4/generations/1/segments/main.jsonl', $error['path']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($db->getDurability()));
    }

    /** @test the mode belongs to the instance: clones keep it, other instances have their own */
    public function theModeBelongsToTheInstance(): void
    {
        $this->onPlatform('Linux');
        $durable = $this->db(['durability' => 'durable']);
        $clone = clone $durable;
        $this->assertSame('durable', $clone->getDurability()['mode']);
        $this->assertSame('performance', $this->db()->getDurability()['mode']);
        $this->assertSame('durable', $durable->getDurability()['mode']);
        $this->assertSame('durable', $durable->__debugInfo()['durability']);
        $this->assertSame('performance', $this->db()->__debugInfo()['durability']);
    }

    /** @test maintenance takes the same durability option (docs/DURABILITY.md §1) */
    public function maintenanceTakesTheDurabilityOption(): void
    {
        $this->db()->insert('m', ['v' => 1]);
        $other = 'second_registered_key_0123';
        $this->onPlatform('Darwin');
        $result = \noneDB::registerKey($this->testDbDir, self::SECRET, $other, ['durability' => 'durable']);
        $this->assertFalse($result['success']);
        $this->assertSame(\noneDB::UNSUPPORTED_DURABILITY, $result['code']);
        try {
            \noneDB::registerKey($this->testDbDir, self::SECRET, $other, ['durability' => 'yes']);
            $this->fail('invalid durability accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("'durability'", $e->getMessage());
        }
        $this->onPlatform('Linux');
        $result = \noneDB::registerKey($this->testDbDir, self::SECRET, $other, ['durability' => 'durable']);
        $this->assertTrue($result['success'], (string)$result['error']);
        // The new key is registered (its databases are its own: none yet); the old key keeps its data
        $this->assertSame([], (new \noneDB(['secretKey' => $other, 'dbDir' => $this->testDbDir]))->find('m', 0));
        $this->assertSame([['v' => 1]], $this->plain($this->db()->find('m', 0)));
    }
}
