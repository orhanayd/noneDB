<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;
use noneDB\Tests\Support\Testing;

/**
 * Soft size limit of the static caches (S4): least recently used entries are dropped when the last
 * lock of the process is released; dropped entries are loaded again, results never change.
 */
class CacheLimitTest extends RegressionTestCase
{
    private function stat(string $name)
    {
        $property = new \noneDB\Tests\InternalValue($name);
        return $property->getValue();
    }

    private function setLimit(?int $bytes): void
    {
        $property = new \noneDB\Tests\InternalValue('cacheMemoryLimit');
        $property->setValue(null, $bytes);
    }

    protected function tearDown(): void
    {
        $this->setLimit(null);
        parent::tearDown();
    }

    /** @test */
    public function aTinyLimitEvictsAndResultsStayTheSame(): void
    {
        foreach ([['shardingEnabled' => false], ['shardSize' => 20]] as $config) {
            $this->cleanTestDirectory();
            $unlimited = $this->db($config);
            $unlimited->createFieldIndex('lru', 'c');
            $unlimited->createSpatialIndex('lru', 'g');
            $unlimited->insert('lru', array_map(function ($n) {
                return ['c' => 'c' . ($n % 4), 'g' => ['type' => 'Point', 'coordinates' => [$n % 10, intdiv($n, 10)]]];
            }, range(0, 79)));
            $expected = [
                array_column($unlimited->find('lru', ['c' => 'c2']), 'key'),
                array_column($unlimited->withinBBox('lru', 'g', 2, 2, 5, 5), 'key'),
                $unlimited->count('lru'),
            ];
            \noneDB::clearStaticCache();
            $db = $this->db($config + ['cacheMemoryLimit' => 1]);
            $before = Testing::get('metrics')['evictions'];
            for ($i = 0; $i < 3; $i++) {
                $db->insert('lru', ['c' => 'x' . $i]);
                $db->delete('lru', ['c' => 'x' . $i]);
                $this->assertSame($expected, [
                    array_column($db->find('lru', ['c' => 'c2']), 'key'),
                    array_column($db->withinBBox('lru', 'g', 2, 2, 5, 5), 'key'),
                    $db->count('lru'),
                ], json_encode($config) . " round $i");
            }
            $this->assertGreaterThan($before, Testing::get('metrics')['evictions'], 'entries were dropped');
        }
    }

    /**
     * @test A write session evicts between segments too (docs/CONFIGURATION.md "When entries are dropped"): one
     * operation over many segments holds the cache, not the database. What the session hasn't written stays: a
     * global index it changed is kept until the session ends and writes it. Answers stay exact.
     */
    public function aWriteSessionEvictsBetweenSegmentsAndKeepsItsUnwrittenGlobalIndex(): void
    {
        $config = ['shardSize' => 10];
        $db = $this->db($config);
        $db->insert('held', array_map(function ($n) { return ['g' => 'g' . intdiv($n, 10)]; }, range(0, 99)));
        $db->createFieldIndex('held', 'g');
        $gfx = $this->dbPath('held', 'gfx', 'g');
        [$before, $filters] = $this->readGlobalFieldFile($gfx);
        // Shard 3 gets a value its filter lacks (a writer without indexes) and loses its field index file: the read
        // of g3 inside the write session builds the index again, and the session changes shard 3's filter
        $this->assertSame(1, $this->db($config + ['fieldIndexEnabled' => false])->update('held', [['key' => 35], ['set' => ['g' => 'g3x']]])['n']);
        unlink($this->dbPath('held', 'fx', 'g', 3));
        \noneDB::clearStaticCache();
        $db = $this->db($config + ['cacheMemoryLimit' => 1]);
        $seen = [];
        (function () use (&$seen, $gfx) {
            $this->withLock('held', true, function () use (&$seen, $gfx) {
                $seen['g3'] = array_column($this->findUnlocked('held', ['g' => 'g3']), 'key');
                $seen['unwritten'] = isset(Testing::get('staticFieldIndexCache')[$gfx]['cols'][3]);
                $evictions = Testing::get('metrics')['evictions'];
                $seen['all'] = count($this->findUnlocked('held', []));
                $seen['evicted'] = Testing::get('metrics')['evictions'] - $evictions;
                $seen['kept'] = isset(Testing::get('staticFieldIndexCache')[$gfx]['cols'][3]);
            });
        })->call(Testing::engine($db));
        $this->assertSame(['g3' => [30, 31, 32, 33, 34, 36, 37, 38, 39], 'unwritten' => true, 'all' => 100], array_intersect_key($seen, ['g3' => 0, 'unwritten' => 0, 'all' => 0]),
            'precondition: shard 3\'s index built in the session, its filter not yet written');
        $this->assertGreaterThan(0, $seen['evicted'], 'evicted between the segments of the read');
        $this->assertTrue($seen['kept'], 'the unwritten global index stays');
        [$after, $written] = $this->readGlobalFieldFile($gfx);
        $this->assertNotSame($before['w'], $after['w'], 'written when the session ended');
        $filters[3] = $this->shardFilter(['g3', 'g3x'], $before['bits']);
        $this->assertSame($filters, $written);
        $this->assertSame(range(0, 9), array_keys($after['sh']));
    }

    /**
     * @test The end of a write session leaves a touched segment whose key index the cache dropped to the next
     * reader that loads it (docs/STORAGE_FORMAT.md §6.4, CLAUDE.md "Memory"): one write over many segments loads
     * each segment's key index once, the session's end loads none again. Answers stay exact.
     */
    public function aWriteSessionLeavesTheSegmentsTheCacheDroppedToTheirNextReader(): void
    {
        // Updating every record leaves half of each segment as old lines: 0.9 keeps the deferred compactions
        // (each one loads its segment again) out of the operation
        $config = ['shardSize' => 10, 'autoCompactThreshold' => 0.9];
        $db = $this->db($config);
        $db->insert('drop', array_map(function ($n) { return ['g' => 'g' . ($n % 7)]; }, range(0, 99)));
        $db->createFieldIndex('drop', 'g');
        \noneDB::clearStaticCache();
        $db = $this->db($config + ['cacheMemoryLimit' => 1]);
        $loads = Testing::get('indexStats')['loads'];
        $evictions = Testing::get('metrics')['evictions'];
        $this->assertSame(['n' => 100], $db->update('drop', [[], ['set' => ['seen' => true]]]));
        $this->assertGreaterThan(0, Testing::get('metrics')['evictions'] - $evictions, 'precondition: evicted between the segments');
        $this->assertSame(10, Testing::get('indexStats')['loads'] - $loads, 'each of the 10 key indexes loaded once, none again at the end');
        $model = array_map(function ($n) { return ['g' => 'g' . ($n % 7), 'seen' => true, 'key' => $n]; }, range(0, 99));
        $this->assertSame($model, $this->db($config + ['cacheMemoryLimit' => 0])->find('drop', 0));
        foreach (['g0', 'g3', 'g6'] as $value) {
            $this->assertSame(array_values(array_filter($model, function ($r) use ($value) { return $r['g'] === $value; })),
                $db->find('drop', ['g' => $value]), $value);
        }
    }

    /**
     * @test Recounting the shards' counters (rebuildIndex(), a shards.json made again, recovery) evicts between
     * the shards like every operation over many segments (docs/CONFIGURATION.md "Memory of large databases"):
     * over a limit it holds one shard's key index at a time
     */
    public function recountingTheShardsHoldsOneKeyIndexAtATime(): void
    {
        $config = ['shardSize' => 10];
        $this->db($config)->insert('rc', array_map(function ($n) { return ['n' => $n]; }, range(0, 99)));
        \noneDB::clearStaticCache();
        $db = $this->db($config + ['cacheMemoryLimit' => 1]);
        $held = null;
        (function () use (&$held) {
            $this->withLock('rc', true, function () use (&$held) {
                $this->recountMeta('rc');
                $held = count(array_filter(array_keys(Testing::get('staticIndexCache')), function ($key) {
                    return substr($key, -4) === '.kix';
                }));
            });
        })->call(Testing::engine($db));
        $this->assertLessThanOrEqual(1, $held, 'key indexes cached when the recount of 10 shards ends');
        $this->assertSame([100, 100], [$db->getShardInfo('rc')['totalRecords'], $db->getShardInfo('rc')['nextKey']]);
    }

    /**
     * @test checkIntegrity() and the first pass of a join's snapshot (each database's key indexes, outside the
     * snapshot) are operations over many segments: over the limit they evict between them
     * (docs/CONFIGURATION.md "Memory of large databases"); their answers are unchanged
     */
    public function integrityChecksAndJoinSnapshotsEvictBetweenSegments(): void
    {
        $config = ['shardSize' => 10];
        $this->db($config)->insert('ia', array_map(function ($n) { return ['n' => $n, 'g' => 'g' . ($n % 3)]; }, range(0, 99)));
        $this->db($config)->insert('ib', array_map(function ($n) { return ['n' => $n]; }, range(0, 99)));
        $this->db($config)->createFieldIndex('ia', 'g');
        \noneDB::clearStaticCache();
        $db = $this->db($config + ['cacheMemoryLimit' => 1]);
        $between = function () { return Testing::get('indexStats')['evictionsBetween']; };
        $before = $between();
        $this->assertTrue($db->checkIntegrity('ia')['ok']);
        $this->assertGreaterThan($before, $between(), 'checkIntegrity() over 10 shards');
        \noneDB::clearStaticCache();
        $db = $this->db($config + ['cacheMemoryLimit' => 1]);
        $before = $between();
        $this->assertSame('in', (function () { return $this->withSnapshot(['ia', 'ib'], function () { return 'in'; }); })->call(Testing::engine($db)));
        $this->assertGreaterThan($before, $between(), 'the snapshot\'s first pass over 2 x 10 shards');
    }

    /**
     * @test Removing the empty shards after a compaction reads every shard's key index: over the limit it holds one
     * at a time (docs/CONFIGURATION.md "Memory of large databases")
     */
    public function removingEmptyShardsHoldsOneKeyIndexAtATime(): void
    {
        $config = ['shardSize' => 10];
        $db = $this->db($config);
        $db->insert('es', array_map(function ($n) { return ['n' => $n]; }, range(0, 99)));
        $this->assertSame(['n' => 30], $db->delete('es', ['key' => range(20, 49)])); // shards 2-4 empty
        \noneDB::clearStaticCache();
        $db = $this->db($config + ['cacheMemoryLimit' => 1]);
        $held = null;
        (function () use (&$held) {
            $this->withLock('es', true, function () use (&$held) {
                $this->removeEmptyShards('es');
                $held = count(array_filter(array_keys(Testing::get('staticIndexCache')), function ($key) {
                    return substr($key, -4) === '.kix';
                }));
            });
        })->call(Testing::engine($db));
        $this->assertLessThanOrEqual(1, $held, 'key indexes cached when the pass over 10 shards ends');
        $this->assertSame(7, $db->getShardInfo('es')['shards'], 'shards 2-4 removed');
        $this->assertSame(range(0, 19) + [20 => 50], array_slice(array_column($db->find('es', 0), 'key'), 0, 21), 'the records stay');
    }

    /** @test */
    public function theDefaultIsHalfOfPhpsMemoryLimit(): void
    {
        // A soft limit that keeps the cache inside the process's memory_limit; no limit without one (a
        // process that may use all the memory it needs is never slowed down by evictions)
        $bytes = function () {
            $method = new \noneDB\Tests\InternalMethod('cacheLimitBytes');
            return $method->invoke(null);
        };
        $before = ini_get('memory_limit');
        try {
            $this->setLimit(null);
            // docs/CONFIGURATION.md: "half of PHP's memory_limit (64 MiB with the usual 128M), and no limit when
            // memory_limit is -1"; the other values: half of the ini value converted by hand
            foreach (['-1' => 0, '128M' => 67108864, '268435456' => 134217728, '4096M' => 2147483648, '2G' => 1073741824,
                      '1048576K' => 536870912] as $ini => $expected) {
                if ($expected > 0 && 2 * $expected <= memory_get_usage(true)) {
                    continue; // PHP refuses a memory_limit below what the process uses (e.g. with coverage on)
                }
                $this->assertNotFalse(ini_set('memory_limit', (string)$ini));
                $this->assertSame($expected, $bytes(), (string)$ini);
            }
            $this->db(['cacheMemoryLimit' => 1000]);
            $this->assertSame(1000, $bytes(), 'a setting wins');
            $this->db(['cacheMemoryLimit' => 'auto']);
            $this->assertSame(536870912, $bytes(), "'auto' goes back to the default");
        } finally {
            ini_set('memory_limit', $before);
        }
    }

    /** @test */
    public function theLimitIsValidated(): void
    {
        // "int|'auto'": a non-negative number of bytes or the string 'auto'
        foreach ([-1, 'abc', 1.5, true, '', 'AUTO', '1.5', '-5', [1]] as $bad) {
            try {
                $this->db(['cacheMemoryLimit' => $bad]);
                $this->fail(var_export($bad, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('cacheMemoryLimit', $e->getMessage());
            }
        }
        $this->assertNull($this->stat('cacheMemoryLimit'), 'a rejected value leaves the setting alone');
    }

    /** Names of the databases (db0 .. db29) whose key index is cached, in cache order */
    private function cachedIndexes(): array
    {
        $names = [];
        for ($i = 0; $i < 30; $i++) {
            $names[basename($this->dbPath('db' . $i, 'home'))] = 'db' . $i;
        }
        return array_values(array_map(function ($path) use ($names) {
            // {dbDir}{locator}.ndb4/generations/{N}/segments/main.kix
            return preg_match('~/([0-9a-f]{32}\.ndb4)/generations/\d+/segments/main\.kix$~', $path, $m) === 1
                ? ($names[$m[1]] ?? $path) : $path;
        }, array_keys($this->stat('staticIndexCache'))));
    }

    /**
     * @test docs/CONFIGURATION.md "Cache memory": "the least recently used entries are dropped until the process
     * is under the limit; a dropped index is loaded again when needed". Twenty databases of 5000 records (a key
     * index of 60 KB, more once decoded), a limit 600 KB above what the process uses: some fit, not all.
     */
    public function theLeastRecentlyUsedEntriesAreDroppedFirst(): void
    {
        $writer = $this->db(['shardingEnabled' => false]);
        for ($i = 0; $i < 20; $i++) {
            $writer->insert('db' . $i, array_map(function ($n) use ($i) { return ['i' => $i, 'n' => $n]; }, range(0, 4999)));
        }
        \noneDB::clearStaticCache();
        gc_collect_cycles();
        $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => memory_get_usage() + 600 * 1024]);
        // Lookups by key: each loads its database's key index, the answer is small
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame([$i], array_column($db->find('db' . $i, ['key' => 7]), 'i'));
        }
        $kept = $this->cachedIndexes();
        $count = count($kept);
        $this->assertGreaterThan(0, $count);
        $this->assertLessThan(20, $count, 'some were dropped');
        // The kept ones are the most recently used: the last $count of the access order
        $order = array_map(function ($i) { return 'db' . $i; }, range(0, 19));
        $this->assertSame(array_slice($order, -$count), $kept);

        // Using the oldest again: it is loaded again and becomes the newest; the least recently used go
        $this->assertSame([0], array_column($db->find('db0', ['key' => 7]), 'i'));
        $now = $this->cachedIndexes();
        $this->assertSame('db0', end($now));
        $this->assertSame(array_slice($order, -(count($now) - 1)), array_slice($now, 0, -1), 'the others: still the most recent');
        // Results never depend on it
        for ($i = 19; $i >= 0; $i--) {
            $this->assertSame([$i], array_column($db->find('db' . $i, ['key' => 4999]), 'i'));
        }
    }

    /** @test while the process is under the limit nothing is dropped */
    public function underTheLimitNothingIsDropped(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('db1', ['i' => 1]);
        $db->insert('db2', ['i' => 2]);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => memory_get_usage() + 64 * 1048576]);
        $before = Testing::get('metrics')['evictions'];
        $db->find('db1', 0);
        $db->find('db2', 0);
        $this->assertSame(['db1', 'db2'], $this->cachedIndexes());
        $this->assertSame($before, Testing::get('metrics')['evictions']);
    }

    /**
     * @test "0 = no limit"; "'auto': ... no limit when memory_limit is -1"; "It applies only to the shared (static)
     * cache"
     */
    public function zeroAutoWithoutMemoryLimitAndPrivateCachesMeanNoEviction(): void
    {
        $writer = $this->db(['shardingEnabled' => false]);
        for ($i = 0; $i < 30; $i++) {
            $writer->insert('db' . $i, ['i' => $i]);
        }
        $before = ini_get('memory_limit');
        try {
            foreach (['0' => [0, '2G'], "'auto' with memory_limit -1" => ['auto', '-1']] as $label => [$limit, $ini]) {
                $this->assertNotFalse(ini_set('memory_limit', $ini));
                \noneDB::clearStaticCache();
                $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => $limit]);
                $evictions = Testing::get('metrics')['evictions'];
                for ($i = 0; $i < 30; $i++) {
                    $db->find('db' . $i, 0);
                }
                $this->assertSame($evictions, Testing::get('metrics')['evictions'], $label);
                $this->assertCount(30, $this->cachedIndexes(), $label);
            }
        } finally {
            ini_set('memory_limit', $before);
        }

        // A private cache (static caching disabled) has no limit, even a tiny one set for the process
        \noneDB::clearStaticCache();
        \noneDB::disableStaticCache();
        $db = $this->db(['shardingEnabled' => false, 'cacheMemoryLimit' => 1]);
        $evictions = Testing::get('metrics')['evictions'];
        for ($i = 0; $i < 30; $i++) {
            $this->assertSame([$i], array_column($db->find('db' . $i, 0), 'i'));
        }
        $this->assertSame($evictions, Testing::get('metrics')['evictions']);
        \noneDB::enableStaticCache();
    }

    /**
     * @test "The setting is process-wide: the last instance created with cacheMemoryLimit in its configuration sets
     * it. Instances without the setting and clearStaticCache() keep the current value."
     */
    public function theSettingIsProcessWide(): void
    {
        $this->db(['cacheMemoryLimit' => 5000]);
        $this->assertSame(5000, $this->stat('cacheMemoryLimit'));
        $this->db();
        $this->assertSame(5000, $this->stat('cacheMemoryLimit'), 'an instance without the setting keeps it');
        \noneDB::clearStaticCache();
        $this->assertSame(5000, $this->stat('cacheMemoryLimit'), 'clearStaticCache() keeps it');
        $this->db(['cacheMemoryLimit' => 7000]);
        $this->assertSame(7000, $this->stat('cacheMemoryLimit'), 'the last one with the setting wins');
        $this->db(['cacheMemoryLimit' => 'auto']);
        $this->assertNull($this->stat('cacheMemoryLimit'));
    }
    /**
     * @test An operation over more segments than the cache holds evicts as it goes (M5a decision §3, for writes
     * too since M6): the segments it finished leave the cache, so one operation on a large database stays
     * within the limit, and the answers stay exact
     */
    public function anOperationOverManySegmentsEvictsBetweenThem(): void
    {
        $config = ['shardSize' => 100];
        $db = $this->db($config);
        $rows = [];
        for ($i = 0; $i < 3000; $i++) {
            $rows[] = ['g' => 'g' . ($i % 40)];
        }
        $db->insert('ev', $rows);
        $db->createFieldIndex('ev', 'g');
        $expected = array_values(array_filter(range(0, 2999), function ($k) { return $k % 40 === 7; }));
        \noneDB::clearStaticCache();
        $db = $this->db($config + ['cacheMemoryLimit' => 1]);
        $within = function () { return $this->stat('indexStats')['evictionsBetween']; };

        $before = $within();
        $this->assertSame($expected, array_column($db->find('ev', ['g' => 'g7']), 'key'));
        $this->assertGreaterThan($before, $within(), 'find() evicted within the read');
        $before = $within();
        $this->assertSame($expected, array_column($db->query('ev')->where(['g' => 'g7'])->get(), 'key'));
        $this->assertGreaterThan($before, $within(), 'a query evicted within the read');
        $this->assertSame(3000, $db->count('ev'));

        // A write session that reads every shard (exclusive lock): it evicts between them too
        $before = $within();
        $this->assertSame(75, $db->update('ev', [['g' => 'g7'], ['set' => ['h' => 1]]])['n']);
        $this->assertGreaterThan($before, $within(), 'evicted inside the write session');
        $this->assertSame($expected, array_column($db->find('ev', ['h' => 1]), 'key'));
        $this->assertSame($expected, array_column($db->find('ev', ['g' => 'g7']), 'key'));
    }

    /**
     * @test docs/CONFIGURATION.md "The cache": a process that has come within an eighth of `memory_limit` counts what PHP
     * holds for it and gives back what it freed; a process further from it keeps its cache. A child allocates small
     * strings and frees them: PHP keeps that memory for its next allocations, so memory_get_usage() no longer counts it
     * while memory_limit still does. One read over ten segments brings the process under the cache limit. A read while
     * it holds a large string can bring it under nothing: it drops once, not at every segment. The same again after it
     * let the string go: the next drop counts from where the process is now, not from where it was
     */
    public function nearMemoryLimitTheFreedMemoryGoesBackBetweenSegments(): void
    {
        $limit = 16 * 1048576;
        $config = ['shardSize' => 10, 'cacheMemoryLimit' => $limit];
        $this->db($config)->insert('freed', array_map(function ($n) { return ['n' => $n]; }, range(0, 99)));
        \noneDB::clearStaticCache();
        $helpers = <<<'PHP'
            $allocateAndFree = function (int $bytes) {
                $blocks = [];
                while (memory_get_usage(true) < $bytes) {
                    $block = [];
                    for ($i = 0; $i < 1000; $i++) {
                        $block[] = str_repeat('x', 100) . $i;
                    }
                    $blocks[] = $block;
                }
            };
            $read = function () use ($db) {
                return array_column($db->find('freed', ['n' => 7]), 'key');
            };
            $dropped = function () {
                return \noneDB\Tests\Support\Testing::get('indexStats')['evictionsBetween'];
            };

            PHP;
        $child = '$db = ' . $this->childDb($config) . ";\n" . $helpers;

        // 48 MiB: the peak of 44 MiB is within an eighth of it
        $output = $this->runChild($child . <<<'PHP'
            ini_set('memory_limit', '48M');
            $rounds = [];
            $allocateAndFree(44 * 1048576);
            $before = [memory_get_usage(true), memory_get_usage()];
            $rounds[] = [$before, $read(), memory_get_usage(true)];
            $large = str_repeat('x', 30 * 1048576);
            $held = $dropped();
            $read();
            $held = $dropped() - $held;
            unset($large);
            $read();
            $allocateAndFree(44 * 1048576);
            $before = [memory_get_usage(true), memory_get_usage()];
            $rounds[] = [$before, $read(), memory_get_usage(true)];
            echo json_encode([$rounds, $held]);
            PHP);
        [$rounds, $held] = json_decode($output, true) ?? [null, null];

        $this->assertIsArray($rounds, $output);
        $this->assertCount(2, $rounds);
        $this->assertSame(1, $held, 'a read that can bring the process under nothing drops once');
        foreach ($rounds as $round => [$before, $found, $after]) {
            $this->assertGreaterThan($limit, $before[0], "round $round, the state of the test: PHP keeps the freed memory");
            $this->assertLessThan($limit, $before[1], "round $round, the state of the test: the process no longer uses it");
            $this->assertSame([7], $found);
            $this->assertLessThanOrEqual($limit, $after, "round $round: the read gave the freed memory back");
        }

        // A peak of 50 MiB of 64 MiB is further than an eighth from it, of 1 GiB far from it, and without a memory_limit
        // there is nothing to be near: nothing is dropped
        foreach (['64M', '1G', '-1'] as $memoryLimit) {
            $output = $this->runChild($child . <<<PHP
                ini_set('memory_limit', '$memoryLimit');
                \$allocateAndFree(50 * 1048576);
                \$before = memory_get_usage(true);
                \$found = \$read();
                echo json_encode([\$before, \$found, memory_get_usage(true), \$dropped()]);
                PHP);
            [$before, $found, $after, $drops] = json_decode($output, true) ?? [null, null, null, null];
            $this->assertIsInt($before, $output);
            $this->assertGreaterThan($limit, $before, "$memoryLimit, the state of the test");
            $this->assertSame([7], $found);
            $this->assertSame(0, $drops, "$memoryLimit: under the limit in use, nothing to drop");
            $this->assertGreaterThan($limit, $after, "$memoryLimit: PHP keeps what the process freed");
        }

        // Near memory_limit, with PHP holding less than the cache limit: nothing is dropped
        $roomy = 20 * 1048576;
        $output = $this->runChild('$db = ' . $this->childDb(['cacheMemoryLimit' => $roomy] + $config) . ";\n" . $helpers . <<<'PHP'
            ini_set('memory_limit', '48M');
            $allocateAndFree(44 * 1048576);
            gc_mem_caches();
            $before = memory_get_usage(true);
            $read();
            echo json_encode([$before, $dropped()]);
            PHP);
        [$before, $drops] = json_decode($output, true) ?? [null, null];
        $this->assertIsInt($before, $output);
        $this->assertLessThan($roomy, $before, 'the state of the test');
        $this->assertSame(0, $drops, 'under the cache limit');

        // memory_limit lowered between two operations: the next one counts from the new value
        $output = $this->runChild($child . <<<'PHP'
            ini_set('memory_limit', '64M');
            $allocateAndFree(50 * 1048576);
            $read();
            $before = $dropped();
            ini_set('memory_limit', '54M');
            $read();
            echo json_encode([$before, $dropped(), memory_get_usage(true)]);
            PHP);
        [$before, $drops, $after] = json_decode($output, true) ?? [null, null, null];
        $this->assertIsInt($before, $output);
        $this->assertSame([0, 1], [$before, $drops], 'near the lower limit');
        $this->assertLessThanOrEqual($limit, $after);
    }
}
