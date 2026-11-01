<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Memory of an operation over many segments (docs/CONFIGURATION.md "Memory of large databases"): an operation
 * whose answer is small holds the cache (cacheMemoryLimit) and one segment's records, never the whole database.
 * Reads, aggregations, writes by filter, the query builder's writes, index builds, compaction and spatial
 * queries run in one child process with a 1 MiB cache limit over a database of 1000 segments; the peak
 * memory over the process's memory before the first operation must stay under a fixed bound, and every
 * answer equals the test's own model of the data.
 */
class BoundedMemoryTest extends RegressionTestCase
{
    private const N = 100000;
    private const SHARD = 100;
    private const CACHE = 1048576;
    /**
     * The cache limit, one segment's records, and what the engine keeps per segment (sizes, epochs, lock
     * session state: a few KiB each, about 7 MiB for the 1000 segments here). Holding the database instead:
     * 20 MiB (compactions after a write over every segment) to hundreds of MiB (a write by filter).
     */
    private const BOUND = 10 * 1048576;
    /** Records of the one segment (and of the one shard) of the large cases: six batches */
    private const LARGE = 60000;
    /** Records per batch of a read over a segment (RecordIO::BATCH, docs/CONFIGURATION.md) */
    private const BATCH = 10000;
    /** What an operation may keep per key of the segment it works on */
    private const PER_KEY = 256;
    /** Two maps of a field whose every value differs: its hash slots and ids, 160 bytes per value each. An array per value is 190 more */
    private const TWO_MAPS = 320;

    private static function lon(int $i): float
    {
        return round(-179.95 + ($i % 3600) * 0.1, 2);
    }

    private static function lat(int $i): float
    {
        return -60.0 + intdiv($i, 3600) * 4.0;
    }

    private static function record(int $i): array
    {
        return ['n' => $i, 'g' => 'g' . ($i % 50), 'v' => $i % 1000, 'name' => 'user' . $i,
            'loc' => ['type' => 'Point', 'coordinates' => [self::lon($i), self::lat($i)]]];
    }

    /** Great-circle distance in meters (the test's own formula, earth radius 6371 km) */
    private static function distance(float $lon1, float $lat1, float $lon2, float $lat2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 6371000.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * The operations, as the child runs them (PHP source of a closure per name), and the answer of each from
     * the model, in order: reads, writes (each changes the model), maintenance, the final delete
     */
    private function operations(int $n): array
    {
        // The model: which keys are live and which fields the writes set; every other field follows from the key
        $live = array_fill(0, $n, true);
        $x = [];
        $z = [];
        $v = function (int $i): int { return $i % 1000; };
        $keysWhere = function (callable $match) use (&$live): array {
            $keys = [];
            foreach ($live as $key => $ignored) {
                if ($match($key)) {
                    $keys[] = $key;
                }
            }
            return $keys;
        };
        $sumV = function (callable $match) use (&$live, $v): int {
            $sum = 0;
            foreach ($live as $key => $ignored) {
                if ($match($key)) {
                    $sum += $v($key);
                }
            }
            return $sum;
        };
        $all = function (): bool { return true; };
        $upper = function (int $i) use ($v): bool { return $v($i) >= 500; };

        // Spatial answers by the test's own box check and distance
        $inBox = $keysWhere(function (int $i): bool {
            return self::lon($i) >= 10 && self::lon($i) <= 11 && self::lat($i) >= -1 && self::lat($i) <= 5;
        });
        $near = [];
        foreach ($live as $key => $ignored) {
            $near[$key] = self::distance(10.03, 0.1, self::lon($key), self::lat($key));
        }
        asort($near);
        $nearest = array_slice(array_keys($near), 0, 3);
        $this->assertLessThan(array_values($near)[3], array_values($near)[2], 'the model: no tie at the third nearest');
        unset($near);
        $within = $keysWhere(function (int $i): bool {
            return self::distance(10.05, 0.0, self::lon($i), self::lat($i)) <= 20000;
        });
        $groups = array_map(function ($i) { return 'g' . $i; }, range(0, 49));

        $ops = [
            // Facade reads
            ['count by a field without index', '$db->count("t", ["name" => "user12345"])', 1],
            ['count by an indexed field', '$db->count("t", ["g" => "g7"])', intdiv($n, 50)],
            ['sum', '$db->sum("t", "v")', $sumV($all)],
            ['avg', '$db->avg("t", "v")', $sumV($all) / $n],
            ['min', '$db->min("t", "v")', 0],
            ['max', '$db->max("t", "v")', 999],
            ['distinct', '$db->distinct("t", "g")', $groups],
            ['first', '$db->first("t", ["g" => "g49"])["key"]', 49],
            ['last', '$db->last("t", ["g" => "g0"])["key"]', $n - 50],
            ['exists', '$db->exists("t", ["name" => "user' . ($n - 1) . '"])', true],
            ['exists (no match)', '$db->exists("t", ["name" => "nobody"])', false],
            ['like', 'array_column($db->like("t", "name", "^user9999\$"), "key")', [9999]],
            ['between (field without index)', 'array_column($db->between("t", "v", 5, 6, ["g" => "g5"]), "key")',
                $keysWhere(function (int $i) use ($v): bool { return $v($i) === 5; })],
            ['between (indexed field)', 'array_column($db->between("t", "g", "g10", "g10", ["v" => 10]), "key")',
                $keysWhere(function (int $i) use ($v): bool { return $v($i) === 10; })],
            ['getShardInfo', '$db->getShardInfo("t")["totalRecords"]', $n],
            ['getIndexInfo', '$db->getIndexInfo("t")["totalRecords"]', $n],
            ['checkIntegrity', '$db->checkIntegrity("t")["ok"]', true],
            // Query builder reads
            ['query count', '$db->query("t")->where(["v" => ["\$gte" => 500]])->count()', intdiv($n, 2)],
            ['query sum', '$db->query("t")->where(["v" => ["\$gte" => 500]])->sum("v")', (float)$sumV($upper)],
            ['query avg', '$db->query("t")->where(["v" => ["\$gte" => 500]])->avg("v")', $sumV($upper) / ($n / 2)],
            ['query min', '$db->query("t")->where(["v" => ["\$gte" => 500]])->min("v")', 500],
            ['query max', '$db->query("t")->where(["v" => ["\$gte" => 500]])->max("v")', 999],
            ['query distinct', '$db->query("t")->where(["v" => ["\$gte" => 0]])->distinct("g")', $groups],
            ['query exists', '$db->query("t")->where(["name" => "user' . ($n - 1) . '"])->exists()', true],
            ['query first', '$db->query("t")->where(["v" => ["\$gte" => 999]])->first()["key"]', 999],
            ['query last', '$db->query("t")->where(["v" => 999])->last()["key"]', $n - 1],
            // Spatial queries with small answers
            ['withinBBox', '(function($r){ $k = array_column($r, "key"); sort($k); return $k; })($db->withinBBox("t", "loc", 10, -1, 11, 5))', $inBox],
            ['nearest', 'array_column($db->nearest("t", "loc", 10.03, 0.1, 3), "key")', $nearest],
            ['withinDistance', '(function($r){ $k = array_column($r, "key"); sort($k); return $k; })($db->withinDistance("t", "loc", 10.05, 0.0, 20000))', $within],
        ];

        // Writes: each answer from the model, then the model changes
        $mark = function (array &$set, callable $match) use (&$live): int {
            $count = 0;
            foreach ($live as $key => $ignored) {
                if ($match($key)) {
                    $set[$key] = true;
                    $count++;
                }
            }
            return $count;
        };
        $remove = function (callable $match) use (&$live, &$x, &$z): int {
            $count = 0;
            foreach ($live as $key => $ignored) {
                if ($match($key)) {
                    unset($live[$key], $x[$key], $z[$key]);
                    $count++;
                }
            }
            return $count;
        };
        $ops[] = ['update by filter', '$db->update("t", [["g" => "g7"], ["set" => ["x" => 1]]])["n"]', $mark($x, function (int $i): bool { return $i % 50 === 7; })];
        $ops[] = ['update all', '$db->update("t", [[], ["set" => ["y" => 2]]])["n"]', count($live)];
        // Every other key, none deleted yet: a key list longer than a batch in one segment
        $ops[] = ['update by a key list', '$db->update("t", [["key" => range(0, ' . ($n - 1) . ', 2)], ["set" => ["k" => 1]]])["n"]', intdiv($n + 1, 2)];
        $ops[] = ['query update', '$db->query("t")->where(["v" => ["\$gte" => 500]])->update(["z" => 3])["n"]', $mark($z, $upper)];
        $ops[] = ['query removeFields', '$db->query("t")->where(["v" => ["\$lt" => 100]])->removeFields(["name"])',
            ['n' => count($keysWhere(function (int $i) use ($v): bool { return $v($i) < 100; })), 'fields_removed' => ['name']]];
        $ops[] = ['delete by filter', '$db->delete("t", ["g" => "g8"])["n"]', $remove(function (int $i): bool { return $i % 50 === 8; })];
        $ops[] = ['query delete', '$db->query("t")->where(["v" => ["\$gte" => 990]])->delete()["n"]', $remove(function (int $i) use ($v): bool { return $v($i) >= 990; })];
        $ops[] = ['count after the writes', '[$db->count("t", ["x" => 1]), $db->count("t", ["y" => 2]), $db->count("t", ["z" => 3]), $db->count("t")]',
            [count($x), count($live), count($z), count($live)]];

        // Maintenance over every segment
        $ops[] = ['createFieldIndex', '(function($r){ return [$r["success"], $r["indexed"]]; })($db->createFieldIndex("t", "v"))', [true, count($live)]];
        // Many index candidates (v >= 100: nine records in ten), one match: what is held is the reading, not the answer
        $one = function (int $i) use ($v): bool { return $i === 12345 && $v($i) >= 100; };
        $ops[] = ['query count by an indexed range', '$db->query("t")->where(["v" => ["\$gte" => 100], "name" => "user12345"])->count()', count($keysWhere($one))];
        $ops[] = ['between by an indexed range', 'array_column($db->between("t", "v", 100, 999, ["name" => "user12345"]), "key")', $keysWhere($one)];
        $ops[] = ['rebuildFieldIndex', '$db->rebuildFieldIndex("t", "g")["success"]', true];
        $ops[] = ['createSpatialIndex', '(function($db){ $db->dropSpatialIndex("t", "loc"); $r = $db->createSpatialIndex("t", "loc"); return [$r["success"], $r["indexed"]]; })($db)', [true, count($live)]];
        $ops[] = ['rebuildSpatialIndex', '$db->rebuildSpatialIndex("t", "loc")["success"]', true];
        $ops[] = ['rebuildIndex', '(function($r){ return [$r["success"], $r["totalRecords"]]; })($db->rebuildIndex("t"))', [true, count($live)]];
        $ops[] = ['compact', '(function($r){ return [$r["success"], $r["totalRecords"]]; })($db->compact("t"))', [true, count($live)]];
        $ops[] = ['checkIntegrity after maintenance', '$db->checkIntegrity("t")["ok"]', true];
        $ops[] = ['sum after maintenance', '$db->sum("t", "v")', $sumV($all)];
        // A unique field: one value per record (the global index's size follows the largest shard, not the values)
        $ops[] = ['createFieldIndex (unique field)', '(function($r){ return [$r["success"], $r["indexed"]]; })($db->createFieldIndex("t", "n"))', [true, count($live)]];
        $some = array_keys($live)[intdiv(count($live), 3)];
        $ops[] = ['find by the unique field', '[array_column($db->find("t", ["n" => ' . $some . ']), "key"), $db->find("t", ["n" => -1])]', [[$some], []]];
        // Inserts with the unique index in place: ten new shards, the global index made wider
        $ops[] = ['insert with a unique index', '$db->insert("t", array_map(function($i){ return ["n" => $i, "g" => "g" . ($i % 50), "v" => $i % 1000]; }, range(' . $n . ', ' . ($n + 999) . ')))["n"]', 1000];
        for ($i = $n; $i < $n + 1000; $i++) {
            $live[$i] = true;
        }
        $ops[] = ['find a new record by the unique field', 'array_column($db->find("t", ["n" => ' . ($n + 500) . ']), "key")', [$n + 500]];
        $ops[] = ['delete all', '$db->delete("t", [])["n"]', $remove($all)];
        $ops[] = ['count after delete all', '$db->count("t")', 0];
        return $ops;
    }

    /**
     * @test Every operation of the list holds the cache and one segment's records, not the database, and
     * answers as the model does. In a process of its own: its model of 100 000 records does not depend on what the
     * tests before it left in the process
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function operationsOverManySegmentsHoldOneSegmentAtATime(): void
    {
        $config = ['shardSize' => self::SHARD];
        $db = $this->db($config);
        $batch = [];
        for ($i = 0; $i < self::N; $i++) {
            $batch[] = self::record($i);
            if (count($batch) === 10000) {
                $db->insert('t', $batch);
                $batch = [];
            }
        }
        $this->assertTrue($db->createFieldIndex('t', 'g')['success']);
        $this->assertTrue($db->createSpatialIndex('t', 'loc')['success']);
        $this->assertSame(self::N / self::SHARD, $db->getShardInfo('t')['shards']);
        unset($db);
        \noneDB::clearStaticCache();

        foreach ($this->runOperations($config, $this->operations(self::N)) as [$name, $peak]) {
            $this->assertLessThanOrEqual(self::BOUND, $peak, sprintf('%s: peak %.1f MiB over the start', $name, $peak / 1048576));
        }
    }

    /**
     * @test docs/CONFIGURATION.md "Memory of operations over many segments": a segment is read 10 000 records at a
     * time, so the operations over one segment of 60 000 records (a database that is not sharded, six batches) hold
     * batches, not the segment. The bound: BOUND, three batches (a write by the query builder holds its scan batch,
     * the matches it writes and their new versions) and what an operation keeps per key of the segment (at most 256
     * bytes: its key index's arrays, offsets and lengths, key lists, the buckets of a field index it builds); as
     * documented, compact() adds its records' lines, a spatial index build an entry per record (512 bytes). Holding the
     * segment's records is six batches: above the bound (asserted). Each operation's own peak where PHP resets it
     * (8.2+), else the peak so far against the largest allowance so far. In a process of its own, as the test above
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function operationsOverOneLargeSegmentHoldBatchesNotTheSegment(): void
    {
        $config = ['shardingEnabled' => false];
        $db = $this->db($config);
        for ($from = 0; $from < self::LARGE; $from += 10000) {
            $db->insert('t', array_map(function ($i) { return self::record($i); }, range($from, $from + 9999)));
        }
        $this->assertTrue($db->createFieldIndex('t', 'g')['success']);
        $this->assertTrue($db->createSpatialIndex('t', 'loc')['success']);
        $this->assertFalse($db->getShardInfo('t')['sharded'], 'one segment');
        unset($db);
        \noneDB::clearStaticCache();
        $batch = self::batchBytes();
        $bound = self::BOUND + 3 * $batch + self::LARGE * self::PER_KEY;
        $this->assertGreaterThan($bound, self::LARGE / self::BATCH * $batch, 'holding the segment is above the bound');
        $line = strlen(json_encode(self::record(self::LARGE - 1) + ['key' => self::LARGE - 1])) + 1;
        $more = ['compact' => self::LARGE * ($line + 64), 'createSpatialIndex' => self::LARGE * 512,
            'rebuildSpatialIndex' => self::LARGE * 512, 'rebuildIndex' => self::LARGE * 512]; // rebuildIndex() builds the spatial index too
        $allowed = 0;
        foreach ($this->runOperations($config, $this->operations(self::LARGE)) as [$name, $peak, $own]) {
            $allowed = $own ? $bound + ($more[$name] ?? 0) : max($allowed, $bound + ($more[$name] ?? 0));
            $this->assertLessThanOrEqual($allowed, $peak, sprintf('%s: peak %.1f MiB over the start', $name, $peak / 1048576));
        }
    }

    /**
     * @test docs/CONFIGURATION.md "Memory of operations over many segments": a field whose every value differs, in one
     * segment of 60 000 records. A build holds two batches and its buckets, a range the decoded map and its sorted views,
     * a check a batch and the two maps it compares; none holds an array per value. Each runs in a process of its own
     */
    public function aFieldOfDistinctValuesCostsNoArrayPerValue(): void
    {
        $config = ['shardingEnabled' => false];
        $db = $this->db($config);
        for ($from = 0; $from < self::LARGE; $from += 10000) {
            $db->insert('t', array_map(function ($i) { return self::record($i); }, range($from, $from + 9999)));
        }
        unset($db);
        \noneDB::clearStaticCache();
        $batch = self::batchBytes();
        $maps = self::LARGE * self::TWO_MAPS;
        // A flat list costs 32 bytes per entry on PHP 8.1, 16 later (60 000 entries: 2.1 and 1.05 MB): a range's ids, strings and bucket ids
        $lists = PHP_VERSION_ID < 80200 ? 3 * 16 * self::LARGE : 0;
        $steps = [
            'createFieldIndex' => ['$db->createFieldIndex("t", "name")["success"]', true, 2 * $batch + $maps],
            'a range of ten' => ['count($db->query("t")->where(["name" => ["\$gte" => "user59990", "\$lte" => "user59999"]])->get())', 10, $maps + $lists],
            'checkIntegrity' => ['$db->checkIntegrity("t")["ok"]', true, $batch + $maps],
        ];
        foreach ($steps as $name => [$code, $answer, $bound]) {
            $body = '$db = ' . $this->childDb($config + ['cacheMemoryLimit' => self::CACHE]) . ";\n"
                . "gc_collect_cycles();\n\$base = memory_get_usage();\n"
                . "\$r = $code;\n"
                . 'echo json_encode([$r, memory_get_peak_usage() - $base]), "\n";';
            $line = json_decode($this->runChild($body), true);
            $this->assertIsArray($line, $name);
            $this->assertSame($answer, $line[0], $name);
            $this->assertLessThanOrEqual($bound, $line[1], sprintf('%s: peak %.1f MiB over the start', $name, $line[1] / 1048576));
        }
    }

    /**
     * Memory of RecordIO::BATCH (10 000) of the records decoded, measured here by json_decode (PHP's size of them, not
     * the engine's)
     */
    private static function batchBytes(): int
    {
        $json = array_map(function ($i) { return json_encode(self::record($i)); }, range(0, self::BATCH - 1));
        gc_collect_cycles();
        $before = memory_get_usage();
        $decoded = array_map(function ($line) { return json_decode($line, true); }, $json);
        $bytes = memory_get_usage() - $before;
        unset($decoded);
        return $bytes;
    }

    /**
     * @test The switch to shards larger than a batch writes each shard from one batch at a time: 60 000 records of one
     * segment switched to one shard of 60 000. The bound: BOUND, three batches, the shard's data file (written whole)
     * and 256 bytes per key; holding the shard's records is six batches, above it (asserted). The records, the field
     * index, a key lookup and the integrity check come through
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function theSwitchToALargeShardHoldsBatchesNotTheShard(): void
    {
        $n = self::LARGE;
        $config = ['shardSize' => $n, 'shardingEnabled' => false];
        $db = $this->db($config);
        for ($from = 0; $from < $n; $from += 10000) {
            $db->insert('t', array_map(function ($i) { return self::record($i); }, range($from, $from + 9999)));
        }
        $this->assertTrue($db->createFieldIndex('t', 'g')['success']);
        unset($db);
        \noneDB::clearStaticCache();
        $batch = self::batchBytes();
        $bytes = 0;
        for ($i = 0; $i < $n; $i++) {
            $bytes += strlen(json_encode(self::record($i) + ['key' => $i])) + 1;
        }
        $bound = self::BOUND + 3 * $batch + $bytes + $n * self::PER_KEY;
        $this->assertGreaterThan($bound, $n / self::BATCH * $batch + $bytes + $n * self::PER_KEY, 'holding the shard is above the bound');

        $body = '$db = ' . $this->childDb($config + ['cacheMemoryLimit' => self::CACHE]) . ";\n"
            . "gc_collect_cycles();\n\$base = memory_get_usage();\n"
            . '$r = $db->convertToSharded("t");' . "\n"
            . 'echo json_encode([$r, memory_get_peak_usage() - $base, $db->getShardInfo("t")]), "\n";';
        $line = json_decode($this->runChild($body), true);
        $this->assertIsArray($line);
        [$result, $peak, $info] = $line;
        $this->assertSame(['success' => true, 'status' => 'converted'], $result);
        $this->assertLessThanOrEqual($bound, $peak, sprintf('peak %.1f MiB over the start', $peak / 1048576));
        $this->assertSame([true, 1, $n, $n], [$info['sharded'], $info['shards'], $info['totalRecords'], $info['nextKey']]);
        $db = $this->db($config);
        $this->assertSame(range(7, $n - 1, 50), array_column($db->find('t', ['g' => 'g7']), 'key'), 'the field index');
        $this->assertSame(self::record(31234)['name'], $db->find('t', ['key' => 31234])[0]['name']);
        $this->assertTrue($db->checkIntegrity('t')['ok']);
    }


    /**
     * Run the operations in one child process: each answers as the model does. Returns [name, peak over the start,
     * whether the peak is the operation's own (PHP 8.2+ resets it) or the peak so far] in order
     */
    private function runOperations(array $config, array $ops): array
    {
        $body = '$db = ' . $this->childDb($config + ['cacheMemoryLimit' => self::CACHE]) . ";\n"
            . "gc_collect_cycles();\n\$base = memory_get_usage();\n";
        foreach ($ops as [$name, $code]) {
            $body .= '$reset = function_exists("memory_reset_peak_usage"); if ($reset) { memory_reset_peak_usage(); }' . "\n"
                . '$r = (function($db){ return ' . $code . '; })($db); echo json_encode([' . var_export($name, true)
                . ", memory_get_peak_usage() - \$base, \$r, \$reset], JSON_PRESERVE_ZERO_FRACTION), \"\\n\";\n";
        }
        $lines = explode("\n", $this->runChild($body));
        $peaks = [];
        foreach ($ops as $i => [$name, , $expected]) {
            $line = json_decode($lines[$i] ?? 'null', true);
            $this->assertIsArray($line, "$name: the child stopped: " . implode("\n", array_slice($lines, $i, 5)));
            $this->assertSame($name, $line[0]);
            $this->assertSame($expected, $line[2], "$name: the answer");
            $peaks[] = [$name, $line[1], $line[3]];
        }
        return $peaks;
    }


    /**
     * @test The switch to the sharded layout (a generation switch, STORAGE_FORMAT.md §5.2) reads and writes one
     * shard's records at a time: 60 000 records imported into one segment switch to 60 shards within the bound.
     * Each shard comes with its key-index checkpoint, covering the whole file. The records, the field index and the
     * next key come through.
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function theSwitchToShardsHoldsOneShardAtATime(): void
    {
        $n = 60000;
        $config = ['shardSize' => 1000, 'shardingEnabled' => false];
        $db = $this->db($config);
        for ($from = 0; $from < $n; $from += 10000) {
            $db->insert('t', array_map(function ($i) { return self::record($i); }, range($from, $from + 9999)));
        }
        $db->delete('t', ['key' => [7, $n - 1]]); // the highest key deleted: the switch must keep it used
        $this->assertTrue($db->createFieldIndex('t', 'g')['success']);
        $this->assertFalse($db->getShardInfo('t')['sharded']);
        unset($db);
        \noneDB::clearStaticCache();

        $body = '$db = ' . $this->childDb($config + ['cacheMemoryLimit' => self::CACHE]) . ";\n"
            . "gc_collect_cycles();\n\$base = memory_get_usage();\n"
            . '$r = $db->convertToSharded("t");' . "\n"
            . 'echo json_encode([$r, memory_get_peak_usage() - $base, $db->getShardInfo("t")]), "\n";';
        $output = $this->runChild($body);
        $line = json_decode($output, true);
        $this->assertIsArray($line, $output);
        [$result, $peak, $info] = $line;
        $this->assertSame(['success' => true, 'status' => 'converted'], $result);
        $this->assertLessThanOrEqual(self::BOUND, $peak, sprintf('peak %.1f MiB over the start', $peak / 1048576));
        $this->assertSame([true, $n / 1000, $n - 2, $n], [$info['sharded'], $info['shards'], $info['totalRecords'], $info['nextKey']]);
        foreach ([0, 1, 30, $n / 1000 - 1] as $shard) {
            $data = $this->dbPath('t', 'data', $shard);
            $kix = $this->readKeyIndexFile($this->dbPath('t', 'kix', $shard));
            $this->assertSame([filesize($data), $this->keyIndexOfData($data)], [$kix['header']['s'], $kix['entries']], "shard $shard's checkpoint");
        }

        $db = $this->db($config);
        $expected = array_values(array_filter(range(7, $n - 1, 50), function ($k) use ($n) { return $k !== 7 && $k !== $n - 1; }));
        $this->assertSame($expected, array_column($db->find('t', ['g' => 'g7']), 'key'), 'the field index, per shard');
        $this->assertSame(self::record(12345)['name'], $db->find('t', ['key' => 12345])[0]['name']);
        $this->assertTrue($db->checkIntegrity('t')['ok']);
        $db->insert('t', ['n' => -1]);
        $this->assertSame([$n], array_column($db->find('t', ['n' => -1]), 'key'), 'the deleted highest key is not handed out again');
    }
}
