<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Field indexes, global field indexes, secondary index files and the query planner, tested against what
 * the documentation promises (docs/API.md "Field Indexing", docs/QUERY.md "Query Planning & explain()",
 * CHANGES.md, docs/STORAGE_FORMAT.md §5.1 and §6.5).
 *
 * Expected values never come from noneDB itself: they are derived by hand or computed by the small
 * independent model in this class (strict === equality, the documented ordering of $gt/$gte/$lt/$lte and
 * between(), the documented batch sizes 32 / 128 / ...). An index may only decide what is read, never
 * what matches: every indexed result must equal the model.
 */
class SpecIndexTest extends RegressionTestCase
{
    // ------------------------------------------------------------------ model

    /** Deterministic pseudo-random numbers (same on every PHP version) */
    private static function rnd(int &$seed, int $n): int
    {
        $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
        return intdiv($seed, 65536) % $n;
    }

    /**
     * The documented order of $gt/$gte/$lt/$lte/between() (QUERY.md, CHANGES.md): numbers and numeric
     * strings numerically, other strings by byte order; null, booleans, arrays, NAN and a number against a
     * non-numeric string are not comparable (null)
     */
    private static function cmp($a, $b): ?int
    {
        foreach ([$a, $b] as $v) {
            if ($v === null || is_bool($v) || is_array($v) || is_object($v) || (is_float($v) && is_nan($v))) {
                return null;
            }
        }
        $numA = is_int($a) || is_float($a) || (is_string($a) && is_numeric($a));
        $numB = is_int($b) || is_float($b) || (is_string($b) && is_numeric($b));
        if ($numA && $numB) {
            $x = (float)$a;
            $y = (float)$b;
            return $x < $y ? -1 : ($x > $y ? 1 : 0);
        }
        if (is_string($a) && is_string($b)) {
            $c = strcmp($a, $b);
            return $c < 0 ? -1 : ($c > 0 ? 1 : 0);
        }
        return null;
    }

    /** True when a record's field satisfies one range operator (missing field: never) */
    private static function holds(array $record, string $field, string $op, $operand): bool
    {
        if (!array_key_exists($field, $record)) {
            return false;
        }
        $c = self::cmp($record[$field], $operand);
        if ($c === null) {
            return false;
        }
        switch ($op) {
            case '$gt': return $c > 0;
            case '$gte': return $c >= 0;
            case '$lt': return $c < 0;
            case '$lte': return $c <= 0;
        }
        throw new \LogicException($op);
    }

    /** Keys (ascending) of the model rows [key => record] that satisfy $match */
    private static function select(array $rows, callable $match): array
    {
        $keys = [];
        foreach ($rows as $key => $record) {
            if ($match($record + ['key' => $key])) {
                $keys[] = $key;
            }
        }
        sort($keys);
        return $keys;
    }

    private static function eq(string $field, $value): callable
    {
        return function (array $r) use ($field, $value) { return array_key_exists($field, $r) && $r[$field] === $value; };
    }

    private function keys($records): array
    {
        $this->assertIsArray($records);
        return array_column($records, 'key');
    }

    private static function show($value): string
    {
        if (is_float($value)) {
            return is_nan($value) ? 'NAN' : (is_infinite($value) ? ($value > 0 ? 'INF' : '-INF') : var_export($value, true));
        }
        return str_replace("\n", '', var_export($value, true));
    }

    /** Values of the equality tests: every type an index bucket can hold, and arrays (never indexed) */
    private static function eqPool(): array
    {
        return [0, 1, -1, 1.0, -0.0, 0.0, 2.5, '1', '0', '', 'a', 'A', '2.5', '01', true, false, null, [1], ['k' => 'v']];
    }

    // ------------------------------------------------------------------ files and internals

    /** indexes/{segment}.{token}.fx of the active generation (STORAGE_FORMAT.md §5.1) */
    private function fxPath(string $db, string $field, ?int $shard = null): string
    {
        return $this->dbPath($db, 'gen') . 'indexes/' . ($shard === null ? 'main' : 's' . $shard) . '.' . $this->token($field) . '.fx';
    }

    /** indexes/{token}.gfx of the active generation */
    private function gfxPath(string $db, string $field): string
    {
        return $this->dbPath($db, 'gen') . 'indexes/' . $this->token($field) . '.gfx';
    }

    private function dataPath(string $db, ?int $shard = null): string
    {
        return $this->dbPath($db, 'data', $shard);
    }

    /**
     * The token of a field in index file names (STORAGE_FORMAT.md §5.1): the field when [a-z0-9_]{1,40},
     * else a lower-case prefix (other characters as "_", at most 24 bytes), "-" and 12 hex digits of its
     * sha256
     */
    private function token(string $field): string
    {
        if (preg_match('/^[a-z0-9_]{1,40}$/', $field)) {
            return $field;
        }
        return substr(strtolower(preg_replace('/[^A-Za-z0-9_]/', '_', $field)), 0, 24) . '-' . substr(hash('sha256', $field), 0, 12);
    }

    /**
     * [header, body, content] of a secondary index file, decoded by the test's own readers: an .fx (§6.5.1): body =
     * bucket id => key when there is one, => [keys] (ascending) when there are several; a .gfx (§6.5.2): body =
     * shard => its filter
     */
    private function secondary(string $path): array
    {
        clearstatcache(true, $path);
        $content = (string)file_get_contents($path);
        if (substr($path, -4) === '.gfx') {
            [$header, $filters] = $this->readGlobalFieldFile($path);
            return [$header, $filters, $content];
        }
        if (substr($path, -3) === '.fx') {
            [$header, $map] = $this->readFieldIndexFile($path);
            return [$header, $map, $content];
        }
        $newline = strpos($content, "\n");
        return [json_decode(substr($content, 0, $newline), true), json_decode(substr($content, $newline + 1), true), $content];
    }

    /** Write a field index file with the test's own writers (.fx: the paged format; .gfx: shard filters) */
    private function writeSecondary(string $path, array $header, array $body): void
    {
        if (substr($path, -4) === '.gfx') {
            $this->writeGlobalFieldFile($path, $header, $body);
            return;
        }
        unset($header['pg']);
        $this->writeFieldIndexFile($path, $header, $body);
    }

    private function size(string $path): int
    {
        clearstatcache(true, $path);
        return (int)filesize($path);
    }

    private function setStatic(string $name, $value): void
    {
        $property = new \noneDB\Tests\InternalValue($name);
        $property->setValue(null, $value);
    }

    private function getStatic(string $name)
    {
        $property = new \noneDB\Tests\InternalValue($name);
        return $property->getValue();
    }

    private function callPrivate(\noneDB $db, string $method, array $args = [])
    {
        $m = new \noneDB\Tests\InternalMethod($method);
        return $m->invokeArgs($db, $args);
    }

    /** Files of the test directory (paths relative to it, every level) whose path contains $part */
    private function filesWith(string $part): array
    {
        clearstatcache();
        $root = rtrim($this->testDbDir, '/') . '/';
        $files = [];
        $walk = function (string $dir) use (&$walk, &$files, $root): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_dir($dir . $entry)) {
                    $walk($dir . $entry . '/');
                } else {
                    $files[] = substr($dir . $entry, strlen($root));
                }
            }
        };
        $walk($root);
        sort($files);
        return array_values(array_filter($files, function ($f) use ($part) {
            return strpos($f, $part) !== false;
        }));
    }

    protected function tearDown(): void
    {
        $this->setStatic('renameFn', null);
        parent::tearDown();
    }

    // ================================================================== equality

    /**
     * find(), getKeysFromFieldIndex(), where() (plain / $in), whereIn(), orWhere(), count(), exists(),
     * first() on an indexed field give exactly the model's keys, in key order, for every type: int, float,
     * -0.0, numeric strings, bools, null, a missing field and arrays; through inserts, updates (also by a
     * process with fieldIndexEnabled off), removed fields, deletes, compaction, a cold cache and a
     * the switch to shards; unsharded, sharded, buffered.
     */
    public function testEqualityLookupsEqualAnIndependentModelThroughTheWholeLifecycle(): void
    {
        $configs = [
            'unsharded' => ['shardingEnabled' => false],
            'sharded' => ['shardSize' => 7],
            'converted' => ['shardSize' => 16, 'shardingEnabled' => false],
            'buffered' => ['shardingEnabled' => false, 'bufferEnabled' => true],
        ];
        $pool = self::eqPool();
        foreach ($configs as $label => $config) {
            $this->cleanTestDirectory();
            $name = 'life';
            $seed = 7;
            $rows = [];
            $next = 0;
            $db = $this->db($config);
            $row = function () use (&$seed, $pool) {
                $record = ['n' => $seed];
                foreach (['v', 'w'] as $field) {
                    $i = self::rnd($seed, count($pool) + 1);
                    if ($i < count($pool)) {
                        $record[$field] = $pool[$i];
                    }
                }
                return $record;
            };
            $insert = function (\noneDB $on, int $count) use (&$rows, &$next, $row, $name) {
                $batch = [];
                for ($i = 0; $i < $count; $i++) {
                    $batch[] = $rows[$next++] = $row();
                }
                $this->assertSame($count, $on->insert($name, $batch)['n']);
            };

            $insert($db, 40);
            $this->assertTrue($db->createFieldIndex($name, 'v')['success']);
            $this->assertTrue($db->createFieldIndex($name, 'w')['success']);
            $insert($db, 20);
            $this->checkEquality($db, $name, $rows, "$label: inserts");

            // Updates, some through a process that doesn't use field indexes (indexes follow the data anyway)
            $off = $this->db($config + ['fieldIndexEnabled' => false]);
            for ($i = 0; $i < 16; $i++) {
                $keys = array_keys($rows);
                $key = $keys[self::rnd($seed, count($keys))];
                $writer = $i % 2 ? $off : $db;
                $choice = self::rnd($seed, count($pool) + 1);
                if ($choice === count($pool)) {
                    $result = $writer->query($name)->where(['key' => $key])->removeFields(['v']);
                    $this->assertSame(array_key_exists('v', $rows[$key]) ? 1 : 0, $result['n']);
                    unset($rows[$key]['v']);
                } else {
                    $this->assertSame(1, $writer->update($name, [['key' => $key], ['set' => ['v' => $pool[$choice]]]])['n']);
                    $rows[$key]['v'] = $pool[$choice];
                }
            }
            $this->checkEquality($db, $name, $rows, "$label: updates, warm");
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->checkEquality($db, $name, $rows, "$label: updates, cold");

            for ($i = 0; $i < 10; $i++) {
                $keys = array_keys($rows);
                $key = $keys[self::rnd($seed, count($keys))];
                $this->assertSame(1, $db->delete($name, ['key' => $key])['n']);
                unset($rows[$key]);
            }
            $this->checkEquality($db, $name, $rows, "$label: deletes");

            $this->assertTrue($db->compact($name)['success']);
            $this->checkEquality($db, $name, $rows, "$label: compacted, warm");
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->checkEquality($db, $name, $rows, "$label: compacted, cold");

            if ($label === 'converted') {
                $this->assertFalse($db->getShardInfo($name)['sharded'] ?? false, 'shardingEnabled off: still one file');
                $this->assertSame(['success' => true, 'status' => 'converted'], $db->convertToSharded($name));
                $this->assertTrue($db->getShardInfo($name)['sharded']);
                $this->assertSame(['v', 'w'], $db->getFieldIndexes($name)['fields']);
                $this->checkEquality($db, $name, $rows, "$label: after the switch");
                $insert($db, 10);
                \noneDB::clearStaticCache();
                $this->checkEquality($this->db($config), $name, $rows, "$label: switched, more inserts, cold");
            }
            $this->assertTrue($db->checkIntegrity($name)['ok'], $label);
        }
    }

    private function checkEquality(\noneDB $db, string $name, array $rows, string $label): void
    {
        if (is_file($this->gfxPath($name, 'v'))) {
            $this->assertGlobalEntriesFit($name, 'v', "$label (before lookups)");
        }
        $values = array_merge(self::eqPool(), ['zzz', 3, 1.5]);
        $count = count($values);
        foreach ($values as $i => $x) {
            $y = $values[($i * 7 + 3) % $count];
            $msg = "$label, v = " . self::show($x) . ', other = ' . self::show($y);
            $expected = self::select($rows, self::eq('v', $x));
            $this->assertSame($expected, $this->keys($db->find($name, ['v' => $x])), "find: $msg");
            $this->assertSame(is_array($x) ? null : $expected, $db->getKeysFromFieldIndex($name, 'v', $x), "getKeysFromFieldIndex: $msg");
            $this->assertSame($expected, $this->keys($db->query($name)->where(['v' => $x])->get()), "where: $msg");
            $this->assertSame(count($expected), $db->query($name)->where(['v' => $x])->count(), "query count: $msg");
            $this->assertSame(count($expected), $db->count($name, ['v' => $x]), "count: $msg");
            $this->assertSame($expected !== [], $db->query($name)->where(['v' => $x])->exists(), "exists: $msg");
            $this->assertSame($expected !== [], $db->exists($name, ['v' => $x]), "noneDB exists: $msg");
            $this->assertSame($expected[0] ?? null, $db->query($name)->where(['v' => $x])->first()['key'] ?? null, "first: $msg");

            $in = self::select($rows, function ($r) use ($x, $y) { return array_key_exists('v', $r) && in_array($r['v'], [$x, $y], true); });
            $this->assertSame($in, $this->keys($db->query($name)->where(['v' => ['$in' => [$x, $y]]])->get()), "\$in: $msg");
            $this->assertSame($in, $this->keys($db->query($name)->whereIn('v', [$x, $y])->get()), "whereIn: $msg");
            $or = self::select($rows, function ($r) use ($x, $y) {
                return (array_key_exists('v', $r) && $r['v'] === $x) || (array_key_exists('w', $r) && $r['w'] === $y);
            });
            $this->assertSame($or, $this->keys($db->query($name)->where(['v' => $x])->orWhere(['w' => $y])->get()), "orWhere: $msg");
            $both = self::select($rows, function ($r) use ($x, $y) {
                return array_key_exists('v', $r) && $r['v'] === $x && array_key_exists('w', $r) && $r['w'] === $y;
            });
            $this->assertSame($both, $this->keys($db->find($name, ['v' => $x, 'w' => $y])), "find v and w: $msg");

            if ($i === $count - 1 && is_file($this->gfxPath($name, 'v'))) {
                $this->assertGlobalEntriesFit($name, 'v', "$label (after lookups)");
            }
            if (!is_array($x)) {
                // An equality on an indexed field reads index candidates: no segment is scanned
                $plan = $db->query($name)->where(['v' => $x])->explain();
                $this->assertSame(0, $plan['segments']['scanned'], "explain: $msg " . json_encode($plan['plan']));
                $this->assertSame($plan['segments']['total'], $plan['segments']['indexed'] + $plan['segments']['skipped'], "explain: $msg");
                $this->assertGreaterThanOrEqual(count($expected), $plan['estimatedRead'], "explain: never fewer candidates than matches: $msg");
            }
        }
    }

    // ================================================================== ranges

    /**
     * $gt / $gte / $lt / $lte, two-sided ranges, query()->between() and noneDB::between() on an indexed
     * field give exactly the documented comparison over every value type (numbers, numeric strings with
     * exponent / leading space / sign, other strings by byte order, null, bools, arrays, missing), for
     * operands of every type including NAN and INF; and they use the index (no segment scanned).
     */
    public function testRangeLookupsEqualTheDocumentedComparison(): void
    {
        $pool = [0, 1, -1, 2.5, -2.5, 10, 1.0, -0.0, '5', '10', '9', '2.5', '-1', ' 7', '1e1', '.5', 'abc', 'Abc', 'Z', '', 'a5', '5a',
            null, true, false, [3]];
        $operands = [0, 1, 5, -1, 2.5, 9.5, 10, -0.0, '5', '10', '9', ' 7', '1e1', 'abc', 'Z', '', 'a', 'B', null, true, false, NAN, INF, -INF];
        $pairs = [[0, 10], [-1, '9'], ['1', 'abc'], ['', 'Z'], ['a', 'b'], [2.5, 2.5], [10, 0], [-INF, INF], ['abc', 'abc'], [null, 5]];
        foreach (['unsharded' => ['shardingEnabled' => false], 'sharded' => ['shardSize' => 6]] as $label => $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $seed = 99;
            $rows = [];
            $batch = [];
            for ($k = 0; $k < 90; $k++) {
                $i = self::rnd($seed, count($pool) + 1);
                $rows[$k] = $i < count($pool) ? ['v' => $pool[$i]] : ['other' => $k];
                $batch[] = $rows[$k];
                if ($k === 69) {
                    $db->insert('rg', $batch);
                    $batch = [];
                    $this->assertTrue($db->createFieldIndex('rg', 'v')['success']);
                }
            }
            $db->insert('rg', $batch); // a tail after the checkpoint
            $phases = ['inserts' => null, 'updates' => function () use (&$rows, &$seed, $pool, $db) {
                for ($i = 0; $i < 20; $i++) {
                    $key = self::rnd($seed, 90);
                    $value = $pool[self::rnd($seed, count($pool))];
                    $this->assertSame(1, $db->update('rg', [['key' => $key], ['set' => ['v' => $value]]])['n']);
                    $rows[$key]['v'] = $value;
                }
            }, 'cold' => function () { \noneDB::clearStaticCache(); }];
            foreach ($phases as $phase => $change) {
                if ($change !== null) {
                    $change();
                }
                $db = $this->db($config);
                foreach ($operands as $operand) {
                    foreach (['$gt', '$gte', '$lt', '$lte'] as $op) {
                        $msg = "$label $phase: v $op " . self::show($operand);
                        $expected = self::select($rows, function ($r) use ($op, $operand) { return self::holds($r, 'v', $op, $operand); });
                        $query = $db->query('rg')->where(['v' => [$op => $operand]]);
                        $this->assertSame($expected, $this->keys($query->get()), $msg);
                        $this->assertSame(count($expected), $db->query('rg')->where(['v' => [$op => $operand]])->count(), $msg);
                        if (is_int($operand) || is_float($operand) || is_string($operand)) {
                            $this->assertSame(0, $db->query('rg')->where(['v' => [$op => $operand]])->explain()['segments']['scanned'], "range index used: $msg");
                        }
                    }
                }
                foreach ($pairs as [$lo, $hi]) {
                    $msg = "$label $phase: " . self::show($lo) . ' .. ' . self::show($hi);
                    $inclusive = self::select($rows, function ($r) use ($lo, $hi) { return self::holds($r, 'v', '$gte', $lo) && self::holds($r, 'v', '$lte', $hi); });
                    $halfOpen = self::select($rows, function ($r) use ($lo, $hi) { return self::holds($r, 'v', '$gt', $lo) && self::holds($r, 'v', '$lt', $hi); });
                    $this->assertSame($inclusive, $this->keys($db->query('rg')->between('v', $lo, $hi)->get()), "between: $msg");
                    $this->assertSame($inclusive, $this->keys($db->between('rg', 'v', $lo, $hi)), "noneDB::between: $msg");
                    $this->assertSame($halfOpen, $this->keys($db->query('rg')->where(['v' => ['$gt' => $lo, '$lt' => $hi]])->get()), "\$gt/\$lt: $msg");
                }
            }
        }
    }

    // ================================================================== planner

    /**
     * Random combinations of where() groups (plain values, $eq, $in, ranges, $ne, key filters), orWhere()
     * (also an empty group), whereIn(), between(), sort + offset + limit: get(), count(), exists(),
     * first() and explain(true) agree with the model on sharded and unsharded databases with two indexed
     * fields, before and after updates and deletes. The planner may only narrow down what is read.
     */
    public function testRandomQueriesNeverDropAMatch(): void
    {
        $values = [0, 1, 2, 3, '1', 1.0, 2.5, null, true, 'x', 'y', 'b3'];
        foreach (['unsharded' => ['shardingEnabled' => false], 'sharded' => ['shardSize' => 30]] as $label => $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->createFieldIndex('rq', 'a');
            $db->createFieldIndex('rq', 'b');
            $seed = 2026;
            $rows = [];
            for ($k = 0; $k < 200; $k++) {
                $rows[$k] = ['b' => $values[self::rnd($seed, count($values))], 'n' => $k];
                if (self::rnd($seed, 10) > 0) {
                    $rows[$k]['a'] = $values[self::rnd($seed, count($values))];
                }
            }
            $db->insert('rq', array_values($rows));
            foreach (['inserted', 'changed'] as $phase) {
                if ($phase === 'changed') {
                    for ($i = 0; $i < 30; $i++) {
                        $key = array_keys($rows)[self::rnd($seed, count($rows))];
                        if ($i % 3 === 0) {
                            $db->delete('rq', ['key' => $key]);
                            unset($rows[$key]);
                        } else {
                            $value = $values[self::rnd($seed, count($values))];
                            $db->update('rq', [['key' => $key], ['set' => ['a' => $value]]]);
                            $rows[$key]['a'] = $value;
                        }
                    }
                }
                for ($round = 0; $round < 150; $round++) {
                    $spec = $this->randomSpec($seed, $values);
                    $msg = "$label $phase round $round: " . json_encode($spec);
                    $expected = $this->modelQuery($rows, $spec);
                    $this->assertSame($expected, $this->keys($this->buildQuery($db, $spec)->get()), $msg);
                    $this->assertSame(count($expected), $this->buildQuery($db, $spec)->count(), "count: $msg");
                    $this->assertSame($expected !== [], $this->buildQuery($db, $spec)->exists(), "exists: $msg");
                    $this->assertSame($expected[0] ?? null, $this->buildQuery($db, $spec)->first()['key'] ?? null, "first: $msg");
                    $explain = $this->buildQuery($db, $spec)->explain(true);
                    $this->assertSame(count($expected), $explain['actual']['returned'], "explain: $msg");
                    if ($explain['actual']['recordsRead'] !== null) {
                        $this->assertLessThanOrEqual($explain['estimatedRead'], $explain['actual']['recordsRead'], "never more than the plan's candidates: $msg");
                        if (!isset($spec['limit']) || isset($spec['sort'])) {
                            $this->assertSame($explain['estimatedRead'], $explain['actual']['recordsRead'], "a query that doesn't stop early reads every candidate: $msg");
                        }
                    }
                    $this->assertGreaterThanOrEqual(count($expected), $explain['estimatedRead'], "candidates are a superset: $msg");
                }
            }
        }
    }

    /** A random query: ['where' => [group], 'or' => [group], 'in' => [field, values], 'between' => [lo, hi], 'sort', 'offset', 'limit'] */
    private function randomSpec(int &$seed, array $values): array
    {
        $leaf = function () use (&$seed, $values) {
            $field = ['a', 'b', 'n', 'key'][self::rnd($seed, 4)];
            $v = $values[self::rnd($seed, count($values))];
            $w = $values[self::rnd($seed, count($values))];
            if ($field === 'key') {
                $k = self::rnd($seed, 230);
                switch (self::rnd($seed, 4)) {
                    case 0: return [$field, 'plain', self::rnd($seed, 2) ? $k : (string)$k];
                    case 1: return [$field, '$in', [$k, self::rnd($seed, 230), (string)self::rnd($seed, 230)]];
                    case 2: return [$field, '$gte', $k];
                    default: return [$field, '$ne', $k];
                }
            }
            switch (self::rnd($seed, 7)) {
                case 0: return [$field, 'plain', $v];
                case 1: return [$field, '$eq', $v];
                case 2: return [$field, '$in', [$v, $w]];
                case 3: return [$field, '$gte', $v];
                case 4: return [$field, 'range', ['$gt' => $v, '$lt' => $w]];
                case 5: return [$field, '$lte', $v];
                default: return [$field, '$ne', $v];
            }
        };
        $group = function () use ($leaf, &$seed) {
            $group = [];
            for ($i = self::rnd($seed, 2) + 1; $i > 0; $i--) {
                $l = $leaf();
                $group[$l[0]] = $l; // one condition per field (as a PHP filter array has)
            }
            return array_values($group);
        };
        $spec = ['where' => [], 'or' => []];
        for ($i = self::rnd($seed, 3); $i > 0; $i--) {
            $spec['where'][] = $group();
        }
        for ($i = self::rnd($seed, 3) === 0 ? self::rnd($seed, 2) + 1 : 0; $i > 0; $i--) {
            $spec['or'][] = self::rnd($seed, 8) === 0 ? [] : $group();
        }
        if (self::rnd($seed, 4) === 0) {
            $spec['in'] = [self::rnd($seed, 2) ? 'a' : 'b', [1, 'x', 2.5, $values[self::rnd($seed, count($values))]]];
        }
        if (self::rnd($seed, 4) === 0) {
            $spec['between'] = [0, self::rnd($seed, 4)];
        }
        if (self::rnd($seed, 3) === 0) {
            $spec['sort'] = self::rnd($seed, 2) ? 'asc' : 'desc';
        }
        if (self::rnd($seed, 3) === 0) {
            $spec['limit'] = self::rnd($seed, 20) + 1;
            if (self::rnd($seed, 2) === 0) {
                $spec['offset'] = self::rnd($seed, 5);
            }
        }
        return $spec;
    }

    private function buildQuery(\noneDB $db, array $spec): \noneDBQuery
    {
        $filters = function (array $group) {
            $filter = [];
            foreach ($group as [$field, $op, $value]) {
                $filter[$field] = $op === 'plain' ? $value : ($op === 'range' ? $value : [$op => $value]);
            }
            return $filter;
        };
        $q = $db->query('rq');
        foreach ($spec['where'] as $group) {
            $q->where($filters($group));
        }
        foreach ($spec['or'] as $group) {
            $q->orWhere($filters($group));
        }
        if (isset($spec['in'])) {
            $q->whereIn($spec['in'][0], $spec['in'][1]);
        }
        if (isset($spec['between'])) {
            $q->between('a', $spec['between'][0], $spec['between'][1]);
        }
        if (isset($spec['sort'])) {
            $q->sort('n', $spec['sort']);
        }
        if (isset($spec['offset'])) {
            $q->offset($spec['offset']);
        }
        if (isset($spec['limit'])) {
            $q->limit($spec['limit']);
        }
        return $q;
    }

    /** The model of a random query: keys in result order */
    private function modelQuery(array $rows, array $spec): array
    {
        $leaf = function (array $r, array $leaf) {
            [$field, $op, $value] = $leaf;
            $has = array_key_exists($field, $r);
            switch ($op) {
                case 'plain':
                    if ($field === 'key') {
                        // find() semantics (API.md): a key filter is an int or a canonical digit string
                        return (is_int($value) || preg_match('/^(0|[1-9][0-9]*)$/', $value)) && $r['key'] === (int)$value;
                    }
                    return $has && $r[$field] === $value;
                case '$eq': return $has && $r[$field] === $value;
                case '$in': return $has && in_array($r[$field], $value, true);
                case '$ne': return !$has || $r[$field] !== $value;
                case 'range':
                    foreach ($value as $o => $operand) {
                        if (!self::holds($r, $field, $o, $operand)) {
                            return false;
                        }
                    }
                    return true;
                default: return self::holds($r, $field, $op, $value);
            }
        };
        $group = function (array $r, array $group) use ($leaf) {
            foreach ($group as $l) {
                if (!$leaf($r, $l)) {
                    return false;
                }
            }
            return true;
        };
        $keys = self::select($rows, function ($r) use ($spec, $group) {
            if (!empty($spec['where']) || !empty($spec['or'])) {
                $match = !empty($spec['where']);
                foreach ($spec['where'] as $g) {
                    $match = $match && $group($r, $g);
                }
                if (!$match) {
                    foreach ($spec['or'] as $g) {
                        $match = $match || $group($r, $g);
                    }
                }
                if (!$match) {
                    return false;
                }
            }
            if (isset($spec['in']) && !(array_key_exists($spec['in'][0], $r) && in_array($r[$spec['in'][0]], $spec['in'][1], true))) {
                return false;
            }
            if (isset($spec['between']) && !(self::holds($r, 'a', '$gte', $spec['between'][0]) && self::holds($r, 'a', '$lte', $spec['between'][1]))) {
                return false;
            }
            return true;
        });
        // "n" equals the key here: sorting by n is sorting by key
        if (($spec['sort'] ?? 'asc') === 'desc') {
            $keys = array_reverse($keys);
        }
        return array_slice($keys, $spec['offset'] ?? 0, $spec['limit'] ?? null);
    }

    /**
     * explain() of an unsharded database, every field derived by hand: 100 records, city cycles through
     * Ankara, Bursa, Izmir, Konya (25 each), age = 20 + n % 10, only city is indexed
     */
    public function testExplainOfAnUnshardedDatabase(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $cities = ['Ankara', 'Bursa', 'Izmir', 'Konya'];
        $db->insert('ex', array_map(function ($n) use ($cities) { return ['city' => $cities[$n % 4], 'age' => 20 + $n % 10, 'n' => $n]; }, range(0, 99)));
        $db->createFieldIndex('ex', 'city');
        $q = function () use ($db) { return $db->query('ex'); };

        $explain = $q()->where(['city' => 'Bursa'])->explain(true);
        $ms = $explain['actual']['ms'];
        $this->assertIsFloat($ms);
        $this->assertGreaterThanOrEqual(0.0, $ms);
        unset($explain['actual']['ms']);
        $this->assertSame([
            'explain' => 1, 'db' => 'ex', 'sharded' => false, 'access' => 'index',
            'segments' => ['total' => 1, 'skipped' => 0, 'indexed' => 1, 'scanned' => 0],
            'plan' => [['segment' => null, 'access' => 'index', 'candidates' => 25]], 'planTruncated' => false,
            'estimatedRead' => 25, 'filter' => ['eq', 'city', 'Bursa'], 'residual' => true, 'spatial' => null,
            'sort' => 'none', 'earlyExit' => false, 'joins' => [], 'groupBy' => null,
            'actual' => ['recordsRead' => 25, 'segmentsRead' => 1, 'returned' => 25],
        ], $explain);

        // An unindexed field next to an indexed one: the index's candidates, every filter checked on them
        $explain = $q()->where(['city' => 'Bursa', 'age' => 21])->explain(true);
        $this->assertSame(['and', [['eq', 'city', 'Bursa'], ['eq', 'age', 21]]], $explain['filter']);
        $this->assertSame([25, 'index'], [$explain['estimatedRead'], $explain['access']]);
        $this->assertSame(['recordsRead' => 25, 'segmentsRead' => 1, 'returned' => 5], array_diff_key($explain['actual'], ['ms' => 0]), 'n = 1, 21, 41, 61, 81');

        // Unindexed only: the segment is scanned
        $explain = $q()->where(['age' => 21])->explain(true);
        $this->assertSame(['scan', 100, [['segment' => null, 'access' => 'scan', 'candidates' => 100]]], [$explain['access'], $explain['estimatedRead'], $explain['plan']]);
        $this->assertSame(['total' => 1, 'skipped' => 0, 'indexed' => 0, 'scanned' => 1], $explain['segments']);
        $this->assertSame([100, 10], [$explain['actual']['recordsRead'], $explain['actual']['returned']]);

        // limit() without sort stops early: batches of 32, then 128 (QUERY.md)
        $explain = $q()->where(['age' => 21])->limit(1)->explain(true);
        $this->assertTrue($explain['earlyExit']);
        $this->assertSame([32, 1], [$explain['actual']['recordsRead'], $explain['actual']['returned']], 'n = 1 is in the first batch');
        $explain = $q()->where(['age' => 29])->limit(4)->explain(true);
        $this->assertSame([100, 4], [$explain['actual']['recordsRead'], $explain['actual']['returned']], 'the 4th match (n = 39) is in the second batch (32 + 68)');
        $explain = $q()->where(['age' => 29])->limit(3)->explain(true);
        $this->assertSame([32, 3], [$explain['actual']['recordsRead'], $explain['actual']['returned']], 'n = 9, 19, 29');

        // orWhere uses the index when every branch can, otherwise the segment is scanned
        $explain = $q()->where(['city' => 'Bursa'])->orWhere(['city' => 'Izmir'])->explain(true);
        $this->assertSame(['or', [['eq', 'city', 'Bursa'], ['eq', 'city', 'Izmir']]], $explain['filter']);
        $this->assertSame(['index', 50, 50, 50], [$explain['access'], $explain['estimatedRead'], $explain['actual']['recordsRead'], $explain['actual']['returned']]);
        $explain = $q()->where(['city' => 'Bursa'])->orWhere(['age' => 21])->explain(true);
        $this->assertSame(['scan', 100, 30], [$explain['access'], $explain['estimatedRead'], $explain['actual']['returned']], '25 Bursa + 5 more with age 21');

        // $in, whereIn(), ranges and between() on the indexed field
        $explain = $q()->where(['city' => ['$in' => ['Bursa', 'Konya', 'nowhere']]])->explain(true);
        $this->assertSame([['in', 'city', ['Bursa', 'Konya', 'nowhere']], 'index', 50, 50], [$explain['filter'], $explain['access'], $explain['estimatedRead'], $explain['actual']['returned']]);
        $explain = $q()->whereIn('city', ['Ankara'])->explain();
        $this->assertSame([['in', 'city', ['Ankara']], 'index', 25], [$explain['filter'], $explain['access'], $explain['estimatedRead']]);
        $explain = $q()->where(['city' => ['$gte' => 'Bursa', '$lte' => 'Izmir']])->explain(true);
        $this->assertSame([['and', [['range', 'city', '$gte', 'Bursa'], ['range', 'city', '$lte', 'Izmir']]], 'index', 50, 50],
            [$explain['filter'], $explain['access'], $explain['estimatedRead'], $explain['actual']['returned']], 'Bursa and Izmir');
        // (strict bounds: testStrictRangeBoundsReadOnlyTheBucketsInRange)
        $explain = $q()->between('city', 'B', 'J')->explain(true);
        $this->assertSame([['and', [['range', 'city', '$gte', 'B'], ['range', 'city', '$lte', 'J']]], 'index', 50, 50],
            [$explain['filter'], $explain['access'], $explain['estimatedRead'], $explain['actual']['returned']]);
        $this->assertSame('scan', $q()->where(['age' => ['$gt' => 25]])->explain()['access'], 'a range on an unindexed field');

        // Negations read every record
        $explain = $q()->where(['city' => ['$ne' => 'Bursa']])->explain(true);
        $this->assertSame([['any'], 'scan', 100, 75], [$explain['filter'], $explain['access'], $explain['estimatedRead'], $explain['actual']['returned']]);
        $this->assertSame('scan', $q()->whereNot(['city' => 'Bursa'])->explain()['access']);
        $this->assertSame('scan', $q()->whereNotIn('city', ['Bursa'])->explain()['access']);

        // Key filters read only those keys
        $explain = $q()->where(['key' => 5])->explain(true);
        $this->assertSame([['keys-in-filter-order'], 'index', 1], [$explain['filter'], $explain['access'], $explain['estimatedRead']]);
        $this->assertSame(['recordsRead' => null, 'segmentsRead' => null, 'returned' => 1], array_diff_key($explain['actual'], ['ms' => 0]), 'find() reads it');
        $explain = $q()->where(['key' => ['$in' => [1, 2, 300]]])->explain(true);
        $this->assertSame([['keys', [1, 2, 300]], 'index', 2, 2, 2], [$explain['filter'], $explain['access'], $explain['estimatedRead'], $explain['actual']['recordsRead'], $explain['actual']['returned']]);

        // After deletes the candidates follow the data
        foreach (range(1, 37, 4) as $key) {
            $db->delete('ex', ['key' => $key]); // the first ten Bursa records
        }
        $this->assertSame(15, $q()->where(['city' => 'Bursa'])->explain()['estimatedRead']);
        $this->assertSame(90, $q()->where(['age' => 1])->explain()['estimatedRead']);
        $this->assertSame([], $this->filesWith('.age.fx'), 'explain() builds nothing');
    }

    /**
     * CHANGES.md: "$gt, $gte, $lt, $lte and between() read only the buckets in range". A strict
     * bound is not in range: `$lt "Konya"` must not read the 25 records of "Konya", `$gt 25` not the ten
     * records of age 25 (same data as testExplainOfAnUnshardedDatabase, age indexed too)
     */
    public function testStrictRangeBoundsReadOnlyTheBucketsInRange(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $cities = ['Ankara', 'Bursa', 'Izmir', 'Konya'];
        $db->insert('sr', array_map(function ($n) use ($cities) { return ['city' => $cities[$n % 4], 'age' => 20 + $n % 10]; }, range(0, 99)));
        $db->createFieldIndex('sr', 'city');
        $db->createFieldIndex('sr', 'age');
        $cases = [
            [['city' => ['$gt' => 'Bursa']], 50, 'Izmir, Konya'],
            [['city' => ['$lt' => 'Konya']], 75, 'Ankara, Bursa, Izmir'],
            [['city' => ['$gte' => 'Bursa', '$lt' => 'Konya']], 50, 'Bursa, Izmir'],
            [['city' => ['$gt' => 'Ankara', '$lt' => 'Konya']], 50, 'Bursa, Izmir'],
            [['age' => ['$gt' => 25]], 40, 'ages 26..29, ten records each'],
            [['age' => ['$lt' => 25]], 50, 'ages 20..24'],
            [['age' => ['$gt' => 21, '$lt' => 24]], 20, 'ages 22, 23'],
        ];
        $report = [];
        $exact = [];
        foreach ($cases as [$filter, $inRange, $what]) {
            $explain = $db->query('sr')->where($filter)->explain(true);
            $this->assertSame($inRange, $explain['actual']['returned'], $what);
            $report[] = json_encode($filter) . ": estimatedRead {$explain['estimatedRead']}, recordsRead {$explain['actual']['recordsRead']}, in range $inRange ($what)";
            $exact[] = [$explain['estimatedRead'], $explain['actual']['recordsRead']] === [$inRange, $inRange];
        }
        $this->assertSame(array_fill(0, count($cases), true), $exact, "candidates beyond the range:\n" . implode("\n", $report));
    }

    /**
     * explain() of a sharded database (4 shards of 50, g = "g" + shard number, u unique; both indexed):
     * the shards the global field index rules out are skipped; an index that a query would build first
     * counts as a scan ("mixed" next to indexed shards) and explain() builds nothing; more than 50
     * segments truncate the plan list
     */
    public function testExplainOfAShardedDatabase(): void
    {
        $db = $this->db(['shardSize' => 50]);
        $db->insert('es', array_map(function ($n) { return ['g' => 'g' . intdiv($n, 50), 'u' => 'u' . $n, 'v' => $n % 3]; }, range(0, 199)));
        $db->createFieldIndex('es', 'g');
        $db->createFieldIndex('es', 'u');
        $q = function () use ($db) { return $db->query('es'); };
        $skip = function ($segment) { return ['segment' => $segment, 'access' => 'skip', 'candidates' => 0]; };
        $index = function ($segment, $n) { return ['segment' => $segment, 'access' => 'index', 'candidates' => $n]; };

        $explain = $q()->where(['g' => 'g2'])->explain(true);
        $this->assertSame([true, 'index', ['total' => 4, 'skipped' => 3, 'indexed' => 1, 'scanned' => 0], 50],
            [$explain['sharded'], $explain['access'], $explain['segments'], $explain['estimatedRead']]);
        $this->assertSame([$skip(0), $skip(1), $index(2, 50), $skip(3)], $explain['plan']);
        $this->assertSame(['recordsRead' => 50, 'segmentsRead' => 1, 'returned' => 50], array_diff_key($explain['actual'], ['ms' => 0]));

        $explain = $q()->whereIn('g', ['g0', 'g3'])->explain();
        $this->assertSame([$index(0, 50), $skip(1), $skip(2), $index(3, 50)], $explain['plan']);
        $this->assertSame(100, $explain['estimatedRead']);

        $explain = $q()->where(['g' => 'g9'])->explain(true);
        $this->assertSame(['none', ['total' => 4, 'skipped' => 4, 'indexed' => 0, 'scanned' => 0], 0], [$explain['access'], $explain['segments'], $explain['estimatedRead']]);
        $this->assertSame(['recordsRead' => 0, 'segmentsRead' => 0, 'returned' => 0], array_diff_key($explain['actual'], ['ms' => 0]));

        $explain = $q()->where(['g' => 'g1'])->orWhere(['u' => 'u170'])->explain(true);
        $this->assertSame([$skip(0), $index(1, 50), $skip(2), $index(3, 1)], $explain['plan']);
        $this->assertSame([51, 51], [$explain['estimatedRead'], $explain['actual']['returned']]);
        $this->assertSame('none', $q()->where(['g' => 'g1', 'u' => 'u170'])->explain()['access'], 'no shard holds both');
        $this->assertSame([], $q()->where(['g' => 'g1', 'u' => 'u170'])->get());

        $explain = $q()->where(['v' => 1])->explain();
        $this->assertSame(['scan', ['total' => 4, 'skipped' => 0, 'indexed' => 0, 'scanned' => 4], 200], [$explain['access'], $explain['segments'], $explain['estimatedRead']]);

        // Key 200 opens shard 4, which has no field index yet and no global entry: never skipped,
        // and explain() counts its index as a scan (one live record) without building it
        $db->insert('es', ['g' => 'g2', 'u' => 'new', 'v' => 0]);
        $explain = $q()->where(['g' => 'g2'])->explain();
        $this->assertSame(['mixed', ['total' => 5, 'skipped' => 3, 'indexed' => 1, 'scanned' => 1], 51], [$explain['access'], $explain['segments'], $explain['estimatedRead']]);
        $this->assertSame(['segment' => 4, 'access' => 'scan', 'candidates' => 1], $explain['plan'][4]);
        $this->assertSame('scan', $q()->where(['g' => 'g9'])->explain()['access'], 'shard 4 must be read');
        $this->assertFileDoesNotExist($this->fxPath('es', 'g', 4));
        // The query builds it (and shard 4's global entry): then shard 4 is indexed, and skipped for g9
        $this->assertSame(array_merge(range(100, 149), [200]), $this->keys($q()->where(['g' => 'g2'])->get()));
        $this->assertFileExists($this->fxPath('es', 'g', 4));
        $explain = $q()->where(['g' => 'g2'])->explain();
        $this->assertSame(['index', ['total' => 5, 'skipped' => 3, 'indexed' => 2, 'scanned' => 0]], [$explain['access'], $explain['segments']]);
        $this->assertSame(['none', 5], [$q()->where(['g' => 'g9'])->explain()['access'], $q()->where(['g' => 'g9'])->explain()['segments']['skipped']]);

        // More than 50 segments: the plan lists the first 50
        $many = $this->db(['shardSize' => 1]);
        $many->insert('many', array_map(function ($n) { return ['x' => $n]; }, range(0, 54)));
        $explain = $many->query('many')->where(['x' => 3])->explain(true);
        $this->assertSame([true, 50, ['total' => 55, 'skipped' => 0, 'indexed' => 0, 'scanned' => 55], 55],
            [$explain['planTruncated'], count($explain['plan']), $explain['segments'], $explain['estimatedRead']]);
        $this->assertSame(['segment' => 49, 'access' => 'scan', 'candidates' => 1], $explain['plan'][49]);
        $this->assertSame(['recordsRead' => 55, 'segmentsRead' => 55, 'returned' => 1], array_diff_key($explain['actual'], ['ms' => 0]));
    }

    /** explain(): "sort" (none / top-k up to offset + limit = 1000 / full), "earlyExit", joins, spatial, groupBy */
    public function testExplainOfSortsEarlyExitJoinsAndSpatialQueries(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->createSpatialIndex('se', 'loc');
        $db->insert('se', array_map(function ($n) {
            return ['city' => 'c' . ($n % 3), 'age' => $n, 'owner' => $n % 4, 'loc' => ['type' => 'Point', 'coordinates' => [$n % 10, 1]]];
        }, range(0, 29)));
        $db->createFieldIndex('se', 'city');
        $db->insert('owners', [['name' => 'c0'], ['name' => 'c1']]);
        $q = function () use ($db) { return $db->query('se')->where(['city' => 'c1']); };
        $pick = function (array $e) { return [$e['sort'], $e['earlyExit'], $e['groupBy']]; };

        $this->assertSame(['none', true, null], $pick($q()->limit(10)->explain()));
        $this->assertSame(['none', false, null], $pick($q()->explain()), 'no limit: nothing to stop at');
        $this->assertSame(['top-k', false, null], $pick($q()->sort('age')->limit(10)->explain()));
        $this->assertSame(['top-k', false, null], $pick($q()->sort('age')->offset(990)->limit(10)->explain()), 'offset + limit = 1000');
        $this->assertSame(['full', false, null], $pick($q()->sort('age')->offset(991)->limit(10)->explain()), 'offset + limit = 1001');
        $this->assertSame(['full', false, null], $pick($q()->sort('age')->explain()), 'no limit');
        $this->assertSame(['full', false, 'city'], $pick($db->query('se')->groupBy('city')->sort('city')->limit(2)->explain()));
        $this->assertSame(['full', false, null], $pick($q()->withDistance('loc', 0, 1)->sort('_distance')->limit(3)->explain()), 'withDistance()');

        // Joins: in-lookup / key-lookup; after the slice unless they must run before the sort
        $joined = $q()->join('owners', 'city', 'name', 'o')->limit(5)->explain();
        $this->assertSame([['db' => 'owners', 'on' => 'city = name', 'strategy' => 'in-lookup', 'when' => 'after-slice']], $joined['joins']);
        $this->assertSame(['none', true], [$joined['sort'], $joined['earlyExit']]);
        $this->assertSame('key-lookup', $q()->join('owners', 'owner', 'key', 'o')->explain()['joins'][0]['strategy']);
        $bySort = $q()->join('owners', 'city', 'name', 'o')->sort('o')->limit(5)->explain();
        $this->assertSame(['before-sort', 'full', false], [$bySort['joins'][0]['when'], $bySort['sort'], $bySort['earlyExit']], 'sorted by the join alias');
        $this->assertSame('before-sort', $db->query('se')->join('owners', 'city', 'name', 'o')->groupBy('city')->explain()['joins'][0]['when']);
        $late = $q()->join('owners', 'city', 'name', 'o')->sort('age', 'desc')->limit(3)->explain();
        $this->assertSame(['after-slice', 'top-k', false], [$late['joins'][0]['when'], $late['sort'], $late['earlyExit']]);
        // and what the explained join returns: the three oldest c1 records with their owner
        $rows = $q()->join('owners', 'city', 'name', 'o')->sort('age', 'desc')->limit(3)->get();
        $this->assertSame([28, 25, 22], array_column($rows, 'age'));
        $this->assertSame([1, 1, 1], array_map(function ($r) { return $r['o']['key']; }, $rows));

        // Spatial filters: access "spatial", no early exit
        $spatial = $db->query('se')->withinBBox('loc', 0, 0, 3, 2)->limit(2)->explain();
        $this->assertSame(['spatial', ['withinBBox'], false], [$spatial['access'], $spatial['spatial'], $spatial['earlyExit']]);
        $this->assertSame(['withinDistance', 'nearest'], $db->query('se')->withinDistance('loc', 0, 1, 1000)->nearest('loc', 0, 1, 2)->explain()['spatial']);
    }

    /** explain() and the other reads of a database that doesn't exist create nothing */
    public function testExplainOfAMissingDatabaseCreatesNothing(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $explain = $db->query('nothing')->where(['a' => 1])->explain(true);
        $this->assertSame(['sharded' => false, 'access' => 'none', 'segments' => ['total' => 0, 'skipped' => 0, 'indexed' => 0, 'scanned' => 0],
            'plan' => [], 'planTruncated' => false, 'estimatedRead' => 0],
            array_intersect_key($explain, array_flip(['sharded', 'access', 'segments', 'plan', 'planTruncated', 'estimatedRead'])));
        $this->assertSame(0, $explain['actual']['returned']);
        $this->assertFalse($db->hasFieldIndex('nothing', 'a'));
        $this->assertNull($db->getKeysFromFieldIndex('nothing', 'a', 1));
        $this->assertSame(['fields' => [], 'sharded' => false], $db->getFieldIndexes('nothing'));
        $this->assertSame([], $this->dbFiles('nothing'), 'no file of the database');
        $this->assertDirectoryDoesNotExist($this->dbPath('nothing', 'home'));
    }

    // ================================================================== global field index: shard skipping

    /**
     * A shard is skipped only when its global entry fits its data (epoch, bytes covered, checksum of them),
     * the value isn't in the entry and the shard's newer lines don't hold it (STORAGE_FORMAT.md §6.5
     * "Global indexes"). Each case below removes g2 from the global map AND breaks the fit of shard 2's entry
     * (under a stale lock epoch, so entries are checked): shard 2 must still be read.
     */
    public function testAShardIsSkippedOnlyWhenItsGlobalEntryFits(): void
    {
        $config = ['shardSize' => 10];
        $db = $this->db($config);
        $db->insert('sk', array_map(function ($n) { return ['g' => 'g' . intdiv($n, 10), 'n' => $n]; }, range(0, 39)));
        $db->createFieldIndex('sk', 'g');
        $gfx = $this->gfxPath('sk', 'g');
        [$header, $filters, $pristine] = $this->secondary($gfx);
        $this->assertSame([64, 8], [$header['bits'], $header['width']]);
        $this->assertSame([0 => $this->shardFilter(['g0'], 64), 1 => $this->shardFilter(['g1'], 64), 2 => $this->shardFilter(['g2'], 64), 3 => $this->shardFilter(['g3'], 64)], $filters);
        $this->assertSame(['0', '1', '2', '3'], array_map('strval', array_keys($header['sh'])));
        foreach ($header['sh'] as $shard => $entry) {
            $this->assertSame($this->size($this->dataPath('sk', (int)$shard)), $entry['s'], "entry $shard covers its whole shard");
        }
        $plan = function () use (&$db) { return array_column($db->query('sk')->where(['g' => 'g2'])->explain()['plan'], 'access', 'segment'); };
        $this->assertSame([0 => 'skip', 1 => 'skip', 2 => 'index', 3 => 'skip'], $plan(), 'a fitting global index rules shards out');

        $cases = [
            'checksum differs' => function (array &$h) { $h['sh']['2']['h'] = $h['sh']['2']['h'] === '00000000' ? '11111111' : '00000000'; },
            'another epoch' => function (array &$h) { $h['sh']['2']['e'] = 'deadbeef'; },
            'covers more than the data' => function (array &$h) { $h['sh']['2']['s'] += 100000; },
            'no entry' => function (array &$h) { unset($h['sh']['2']); },
            'key index file missing' => function (array &$h) { unlink($this->dbPath('sk', 'kix', 2)); },
        ];
        foreach ($cases as $case => $break) {
            file_put_contents($gfx, $pristine);
            [$h, $m] = $this->secondary($gfx);
            $m[2] = $this->shardFilter([], 64); // shard 2's filter without g2
            $h['le'] = null; // written under an older lock epoch: every entry is checked
            $break($h);
            $this->writeSecondary($gfx, $h, $m);
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->assertNotSame('skip', $plan()[2], $case);
            $this->assertSame(range(20, 29), $this->keys($db->find('sk', ['g' => 'g2'])), $case);
            $this->assertSame(range(20, 29), $this->keys($db->query('sk')->where(['g' => 'g2'])->get()), $case);
            $this->assertSame(range(20, 29), $db->getKeysFromFieldIndex('sk', 'g', 'g2'), $case);
            $this->assertSame([10], $this->keys($db->find('sk', ['g' => 'g1', 'n' => 10])), $case);
        }

        // An entry that fits, without the value: skipped (that's what the global index is for)
        $db->rebuildFieldIndex('sk', 'g');
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        [$h, $m] = $this->secondary($gfx);
        $m[2] = $this->shardFilter([], 64);
        $h['le'] = null;
        $this->writeSecondary($gfx, $h, $m);
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame('skip', $plan()[2], 'every entry fits: the filters are trusted');

        // Newer lines of a shard are checked: shard 1 is read for g2 once it holds g2, not for g3
        $db->rebuildFieldIndex('sk', 'g');
        $this->assertSame(1, $db->update('sk', [['key' => 15], ['set' => ['g' => 'g2']]])['n']);
        $this->assertSame(1, $db->update('sk', [['key' => 16], ['set' => ['g' => 'other']]])['n']);
        foreach ([true, false] as $warm) {
            if (!$warm) {
                \noneDB::clearStaticCache();
                $db = $this->db($config);
            }
            $this->assertSame([0 => 'skip', 1 => 'index', 2 => 'index', 3 => 'skip'], $plan(), 'g2: shard 1 has it in its newer lines');
            $this->assertSame(array_merge([15], range(20, 29)), $this->keys($db->find('sk', ['g' => 'g2'])));
            $this->assertSame([0 => 'skip', 1 => 'skip', 2 => 'skip', 3 => 'index'], array_column($db->query('sk')->where(['g' => 'g3'])->explain()['plan'], 'access', 'segment'),
                "g3: shard 1's newer lines don't hold it");
            $this->assertSame([16], $this->keys($db->find('sk', ['g' => 'other'])), 'a value only in newer lines');
            $this->assertSame([10, 11, 12, 13, 14, 17, 18, 19], $this->keys($db->find('sk', ['g' => 'g1'])), 'holders whose newer lines removed some');
        }
    }

    /**
     * Shard tails in more than 64 shards at once (the parsed tails are cached for 64 data files): every
     * lookup stays exact, and only the shard whose newer lines hold the value is read. Four records per
     * shard: one update leaves 20% garbage, under the 30% that would compact the shard (and drop its tail).
     */
    public function testManyShardsWithNewerLinesStayExact(): void
    {
        $config = ['shardSize' => 4];
        $db = $this->db($config);
        $db->insert('mt', array_map(function ($n) { return ['c' => 'v' . $n]; }, range(0, 279))); // 70 shards
        $db->createFieldIndex('mt', 'c');
        for ($shard = 0; $shard < 70; $shard++) {
            $this->assertSame(1, $db->update('mt', [['key' => 4 * $shard], ['set' => ['c' => 'w' . $shard]]])['n']);
        }
        $this->assertSame(70, $db->getShardInfo('mt')['shards']);
        $this->assertSame($this->secondary($this->fxPath('mt', 'c', 9))[0]['s'] + 0, $this->secondary($this->gfxPath('mt', 'c'))[0]['sh']['9']['s'],
            'precondition: the global entries are those of the checkpoints');
        $this->assertGreaterThan($this->secondary($this->gfxPath('mt', 'c'))[0]['sh']['9']['s'], $this->size($this->dataPath('mt', 9)), 'precondition: shard 9 has newer lines');
        // The shards whose filter may hold zz (§6.5.2: a shard that doesn't hold a value may answer "may"; a process
        // with the exact map reads none of them)
        $filters = $this->secondary($this->gfxPath('mt', 'c'))[1];
        $may = array_keys(array_filter($filters, function ($filter) { return $this->filterMayHold($filter, 'zz'); }));
        $notSkipped = function (array $explain) {
            return array_keys(array_filter(array_column($explain['plan'], 'access', 'segment'), function ($a) { return $a !== 'skip'; }));
        };
        foreach ([true, false] as $warm) {
            if (!$warm) {
                \noneDB::clearStaticCache();
                $db = $this->db($config);
            }
            // explain() lists the first 50 segments of its plan
            $plan = $db->query('mt')->where(['c' => 'zz'])->explain();
            $this->assertSame([], array_diff($notSkipped($plan), $may), 'no newer line holds it: only shards whose filter may hold it are read');
            $this->assertGreaterThanOrEqual(70 - count($may), $plan['segments']['skipped']);
            foreach ([0, 5, 66, 69] as $shard) {
                $this->assertSame([4 * $shard], $this->keys($db->find('mt', ['c' => 'w' . $shard])));
                $this->assertSame([], $db->find('mt', ['c' => 'v' . (4 * $shard)]), 'replaced by a newer line');
                $this->assertSame([4 * $shard + 1], $this->keys($db->find('mt', ['c' => 'v' . (4 * $shard + 1)])));
                // Read: the shard whose newer lines hold it, and at most the shards whose filter may hold it
                $may = array_keys(array_filter($filters, function ($filter) use ($shard) { return $this->filterMayHold($filter, 'w' . $shard); }));
                $explain = $db->query('mt')->where(['c' => 'w' . $shard])->explain();
                $this->assertSame(['total' => 70, 'scanned' => 0], array_intersect_key($explain['segments'], ['total' => 0, 'scanned' => 0]), "w$shard");
                $this->assertSame($explain['segments']['total'] - $explain['segments']['skipped'], $explain['segments']['indexed'], "w$shard");
                $this->assertSame([], array_diff($notSkipped($explain), array_merge([$shard], $may)), "w$shard: no other shard read");
                $this->assertLessThanOrEqual(1 + count($may), $explain['segments']['indexed'], "w$shard");
                $this->assertGreaterThanOrEqual(1, $explain['segments']['indexed'], "w$shard: its shard read");
                if ($shard < 50) {
                    $this->assertContains($shard, $notSkipped($explain), "w$shard: the shard with the newer line is read");
                }
            }
        }
    }

    // ================================================================== public API

    /**
     * getKeysFromFieldIndex() (API.md): the keys whose value is === the value, sorted; null without a
     * database, without an index, for an array or object value; with a shard id only that shard's keys,
     * [] for a shard that doesn't exist
     */
    public function testGetKeysFromFieldIndexIsStrict(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $values = [1, '1', 1.0, true, null, 0, -0.0, '', false, 'a', [1], 1, 'a', '1'];
        $db->insert('gk', array_map(function ($v) { return ['v' => $v, 'u' => 'x']; }, $values));
        $db->insert('gk', ['u' => 'no v']); // key 14: no field
        $this->assertNull($db->getKeysFromFieldIndex('gk', 'v', 1), 'no index yet');
        $db->createFieldIndex('gk', 'v');
        $expected = [[1, [0, 11]], ['1', [1, 13]], [1.0, [2]], [true, [3]], [null, [4]], [0, [5]], [-0.0, [6]], [0.0, [6]], ['', [7]],
            [false, [8]], ['a', [9, 12]], ['b', []], [2, []], ['0', []]];
        foreach ([true, false] as $warm) {
            if (!$warm) {
                \noneDB::clearStaticCache();
                $db = $this->db(['shardSize' => 4]);
            }
            foreach ($expected as [$value, $keys]) {
                $this->assertSame($keys, $db->getKeysFromFieldIndex('gk', 'v', $value), self::show($value));
            }
            $this->assertNull($db->getKeysFromFieldIndex('gk', 'v', [1]), 'arrays are not indexed');
            $this->assertNull($db->getKeysFromFieldIndex('gk', 'v', new \stdClass()));
            $this->assertNull($db->getKeysFromFieldIndex('gk', 'u', 'x'), 'u has no index');
            $this->assertNull($db->getKeysFromFieldIndex('missing', 'v', 1));
            // Shards: 0..3, 4..7, 8..11, 12..14
            $this->assertSame([0], $db->getKeysFromFieldIndex('gk', 'v', 1, 0));
            $this->assertSame([], $db->getKeysFromFieldIndex('gk', 'v', 1, 1));
            $this->assertSame([11], $db->getKeysFromFieldIndex('gk', 'v', 1, 2));
            $this->assertSame([12], $db->getKeysFromFieldIndex('gk', 'v', 'a', 3));
            $this->assertSame([13], $db->getKeysFromFieldIndex('gk', 'v', '1', '3'), 'a numeric string shard id');
            $this->assertSame([], $db->getKeysFromFieldIndex('gk', 'v', 1, 7), 'no shard 7');
        }
        $db->update('gk', [['key' => 0], ['set' => ['v' => 'a']]]);
        $db->delete('gk', ['key' => 9]);
        $this->assertSame([11], $db->getKeysFromFieldIndex('gk', 'v', 1));
        $this->assertSame([0, 12], $db->getKeysFromFieldIndex('gk', 'v', 'a'));
        $this->assertSame([], $this->dbFiles('missing'));
    }

    /** hasFieldIndex() (API.md): true exactly for the indexed fields; case-sensitive; false after a drop; nothing created for a missing database */
    public function testHasFieldIndexReportsExactlyTheIndexedFields(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertFalse($db->hasFieldIndex('hf', 'city'));
        $this->assertSame([], $this->dbFiles('hf'), 'asking creates nothing');
        $db->insert('hf', ['city' => 'A', 'City' => 'B', '7' => 'seven']);
        $this->assertFalse($db->hasFieldIndex('hf', 'city'));
        $db->createFieldIndex('hf', 'city');
        $db->createFieldIndex('hf', '7');
        $this->assertTrue($db->hasFieldIndex('hf', 'city'));
        $this->assertFalse($db->hasFieldIndex('hf', 'City'), 'names differing in case are different fields');
        $this->assertTrue($db->hasFieldIndex('hf', '7'));
        $this->assertTrue($db->hasFieldIndex('hf', 7), 'a numeric field name');
        $this->assertFalse($db->hasFieldIndex('hf', 'key'));
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertTrue($db->hasFieldIndex('hf', 'city'));
        $db->dropFieldIndex('hf', 'city');
        $this->assertFalse($db->hasFieldIndex('hf', 'city'));
        $this->assertTrue($db->hasFieldIndex('hf', '7'));
        $this->assertSame([0], $this->keys($db->find('hf', ['7' => 'seven'])), 'a numeric field name is looked up through its index');
        $this->assertSame([0], $db->getKeysFromFieldIndex('hf', '7', 'seven'));
        $this->assertSame('index', $db->query('hf')->where(['7' => 'seven'])->explain()['access']);
    }

    /**
     * createFieldIndex() (API.md): "indexed" = records with an indexable value (missing fields and arrays
     * are not indexed, null is), values kept by type (-0.0 is 0.0), "shards" when sharded; creating it again
     * rebuilds it from the data
     */
    public function testCreateFieldIndexCountsEntriesAndKeepsValuesByType(): void
    {
        $data = [1, 1.0, '1', true, null, 1, 'x', [1], 'MISSING', -0.0, 0.0, false, 'x', ['a' => 1]];
        // indexable: 1, 1.0, '1', true, null, 1, 'x', -0.0, 0.0, false, 'x' = 11 entries
        foreach ([['shardingEnabled' => false], ['shardSize' => 3]] as $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->insert('cf', array_map(function ($v) { return $v === 'MISSING' ? ['other' => 1] : ['v' => $v]; }, $data));
            $expected = ['success' => true, 'indexed' => 11, 'error' => null];
            if (isset($config['shardSize'])) {
                $expected['shards'] = 5; // 14 records in shards of 3
            }
            $this->assertSame($expected, $db->createFieldIndex('cf', 'v'), json_encode($config));
            $this->assertSame(['v'], $db->getFieldIndexes('cf')['fields']);

            // A damaged index file (wrong buckets under a header that still fits) is replaced by a second call
            $fx = $this->fxPath('cf', 'v', isset($config['shardSize']) ? 0 : null);
            [$header, $buckets] = $this->secondary($fx);
            $this->writeSecondary($fx, $header, ['sx' => [0, 1, 2]]);
            $db->insert('cf', ['v' => 'y']);
            $expected['indexed'] = 12;
            if (isset($config['shardSize'])) {
                $expected['shards'] = 5;
            }
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->assertSame($expected, $db->createFieldIndex('cf', 'v'), 'again: rebuilt, counted from the data');
            $this->assertSame(['v'], $db->getFieldIndexes('cf')['fields'], 'listed once');
            [, $rebuilt] = $this->secondary($fx);
            if (!isset($config['shardSize'])) {
                $buckets['sy'] = 14; // the record added since (sharded: key 14 is in shard 4)
            }
            ksort($buckets);
            ksort($rebuilt);
            $this->assertSame($buckets, $rebuilt, 'the buckets of the data, the damage is gone');
            $this->assertSame([6, 12], $this->keys($db->find('cf', ['v' => 'x'])));
            $this->assertSame([0, 5], $this->keys($db->find('cf', ['v' => 1])));
            $this->assertSame([[1], [2], [3], [4], [9, 10], [11]], array_map(function ($v) use ($db) { return $db->getKeysFromFieldIndex('cf', 'v', $v); }, [1.0, '1', true, null, 0.0, false]),
                'by type: 1.0, "1", true, null, 0.0 (and -0.0), false');
            $this->assertSame([14], $this->keys($db->find('cf', ['v' => 'y'])));
            $this->assertSame($expected, $db->rebuildFieldIndex('cf', 'v'), 'rebuildFieldIndex(): same result as createFieldIndex()');
        }
        // On a field without an index, rebuildFieldIndex() creates it
        $this->assertSame(['success' => true, 'indexed' => 1, 'error' => null, 'shards' => 5], $db->rebuildFieldIndex('cf', 'other'));
        $this->assertSame(['v', 'other'], $db->getFieldIndexes('cf')['fields']);
    }

    /** createFieldIndex() errors (API.md): "", "key" or a non-string field; a missing database without autoCreateDB */
    public function testCreateFieldIndexRejectsInvalidFieldsAndMissingDatabases(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('iv', ['a' => 1]);
        $invalid = ['success' => false, 'indexed' => 0, 'error' => 'Invalid field name'];
        foreach (['', 'key', 5, null, ['a'], 1.5, true] as $field) {
            $this->assertSame($invalid, $db->createFieldIndex('iv', $field), self::show($field));
        }
        $this->assertSame(['fields' => [], 'sharded' => false], $db->getFieldIndexes('iv'));
        $this->assertSame([], $this->filesWith('.fx'));
        $this->assertSame([], $this->filesWith('indexes.json'));

        $strict = $this->db(['shardingEnabled' => false, 'autoCreateDB' => false]);
        $this->assertSame(['success' => false, 'indexed' => 0, 'error' => 'Database does not exist'], $strict->createFieldIndex('absent', 'a'));
        // A write takes the exclusive lock (its LOCK file may stay: an empty location, STORAGE_FORMAT.md §8.3);
        // no database, index or list file
        $this->assertSame([], array_values(array_diff($this->dbFiles('absent'), ['LOCK'])));
        $this->assertSame(['success' => true, 'indexed' => 0, 'error' => null], $db->createFieldIndex('fresh', 'a'), 'autoCreateDB: created, empty');
        $this->assertTrue($db->hasFieldIndex('fresh', 'a'));
        $db->insert('fresh', [['a' => 1], ['a' => 2]]);
        $this->assertSame([1], $this->keys($db->find('fresh', ['a' => 2])));

        $this->expectException(\InvalidArgumentException::class);
        $db->createFieldIndex('bad/name', 'a');
    }

    /**
     * Index file names hold no database name (STORAGE_FORMAT.md §4: the database directory is its 32 hex
     * locator; §5.1: indexes/{segment}.{token}.fx, indexes/{token}.gfx): a database name of any length can
     * index any field, and every file name stays within 255 bytes
     */
    public function testLongDatabaseNamesCanIndexAnyField(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $field40 = str_repeat('f', 40);
        $field39 = str_repeat('f', 39);
        $hashed = str_repeat('F', 60); // token: 24 + 1 + 12 = 37 bytes
        $cases = [[158, $field40], [159, $field40], [159, $field39], [161, $hashed], [162, $hashed], [197, 'a'], [198, 'a'], [200, 'a']];
        foreach ($cases as [$length, $field]) {
            $name = str_repeat('n', $length);
            $this->assertSame(1, $db->insert($name, [$field => 'x'])['n']);
            $result = $db->createFieldIndex($name, $field);
            $label = "name of $length bytes, field token of " . strlen($this->token($field)) . ' bytes';
            $this->assertSame(['success' => true, 'indexed' => 1, 'error' => null], $result, $label);
            $this->assertTrue($db->hasFieldIndex($name, $field), $label);
            $this->assertFileExists($this->fxPath($name, $field), $label);
            $this->assertSame([0], $this->keys($db->find($name, [$field => 'x'])), $label);
            $this->assertSame(1, $db->insert($name, [$field => 'y'])['n'], "$label: the database keeps working");
            $this->assertSame([1], $db->getKeysFromFieldIndex($name, $field, 'y'), $label);
            foreach ($this->dbFiles($name) as $file) {
                $this->assertLessThanOrEqual(255, strlen(basename($file)), "$label: $file");
            }
            $db->dropDB($name);
        }
    }

    /**
     * Index file names (STORAGE_FORMAT.md §5.1): indexes/{segment}.{token}.fx and indexes/{token}.gfx; the token is
     * the field when it is [a-z0-9_]{1,40}, else a lower-case prefix, "-" and 12 hex digits of its sha256,
     * at most 40 bytes; names that differ only in case never share a file
     */
    public function testIndexFileTokensFollowTheNamingRule(): void
    {
        $db = $this->db(['shardSize' => 2]);
        $fields = ['city', 'City', 'CITY', 'e-mail', 'e_mail', 'first.name', 'şehir', str_repeat('a', 40), str_repeat('a', 41), str_repeat('ü', 110), '_9', '0'];
        $db->insert('tk', [array_fill_keys($fields, 'x'), array_fill_keys($fields, 'y'), array_fill_keys($fields, 'z')]);
        $tokens = [];
        foreach ($fields as $field) {
            $this->assertTrue($db->createFieldIndex('tk', $field)['success'], $field);
            $token = $this->token($field);
            $this->assertLessThanOrEqual(40, strlen($token));
            if (preg_match('/^[a-z0-9_]{1,40}$/', $field)) {
                $this->assertSame($field, $token);
            } else {
                $this->assertMatchesRegularExpression('/^[a-z0-9_]{0,24}-' . substr(hash('sha256', $field), 0, 12) . '$/', $token);
            }
            $tokens[] = $token;
            foreach ([0, 1] as $shard) {
                $this->assertFileExists($this->fxPath('tk', $field, $shard), $field);
                $this->assertSame((string)$field, $this->secondary($this->fxPath('tk', $field, $shard))[0]['field'], $field);
            }
            $this->assertFileExists($this->gfxPath('tk', $field), $field);
            $this->assertSame((string)$field, $this->secondary($this->gfxPath('tk', $field))[0]['field'], $field);
        }
        $this->assertSame(count($fields), count(array_unique(array_map('strtolower', $tokens))), 'no two tokens equal, even ignoring case');
        $this->assertCount(count($fields) * 2, $this->filesWith('.fx'));
        $this->assertCount(count($fields), $this->filesWith('.gfx'));
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 2]);
        foreach ($fields as $field) {
            $this->assertSame([2], $this->keys($db->find('tk', [$field => 'z'])), $field);
            $this->assertSame([1], $db->getKeysFromFieldIndex('tk', $field, 'y'), $field);
        }
    }

    /**
     * dropFieldIndex() (API.md): removes the index files of every shard and the global one, the index list
     * entry (the list file when no index is left); queries stay exact (scan); a second drop and an unknown
     * field: "Index does not exist".
     */
    public function testDropFieldIndexRemovesEveryFileAndKeepsQueriesExact(): void
    {
        $db = $this->db(['shardSize' => 5]);
        $db->insert('dr', array_map(function ($n) { return ['a' => $n % 3, 'b' => 'b' . ($n % 2), 'e-mail' => "m$n"]; }, range(0, 11)));
        $db->createFieldIndex('dr', 'a');
        $db->createFieldIndex('dr', 'b');
        $db->createFieldIndex('dr', 'e-mail');
        $this->assertCount(3, $this->filesWith('.a.fx'));

        $this->assertSame(['success' => true, 'error' => null], $db->dropFieldIndex('dr', 'a'));
        $this->assertSame([], $this->filesWith('.a.fx'));
        $this->assertSame([], $this->filesWith('/a.gfx'));
        $this->assertCount(3, $this->filesWith('.b.fx'), 'the other indexes stay');
        $this->assertFileExists($this->gfxPath('dr', 'b'));
        $this->assertSame(['b', 'e-mail'], $db->getFieldIndexes('dr')['fields']);
        $this->assertSame(['b', 'e-mail'], json_decode(file_get_contents($this->dbPath('dr', 'indexes')), true)['fields']);
        $this->assertFalse($db->hasFieldIndex('dr', 'a'));
        $this->assertNull($db->getKeysFromFieldIndex('dr', 'a', 1));
        $this->assertSame([1, 4, 7, 10], $this->keys($db->find('dr', ['a' => 1])));
        $explain = $db->query('dr')->where(['a' => 1])->explain();
        $this->assertSame(['scan', 0], [$explain['access'], $explain['segments']['skipped']], 'no index, no shard skipped');
        $this->assertSame(['success' => false, 'error' => 'Index does not exist'], $db->dropFieldIndex('dr', 'a'));
        $this->assertSame(['success' => false, 'error' => 'Index does not exist'], $db->dropFieldIndex('dr', 'nothing'));

        $this->assertSame(['success' => true, 'error' => null], $db->dropFieldIndex('dr', 'e-mail'));
        $this->assertSame(['success' => true, 'error' => null], $db->dropFieldIndex('dr', 'b'));
        $this->assertFileDoesNotExist($this->dbPath('dr', 'indexes'), 'no index left: no list');
        $this->assertSame([], $this->filesWith('.fx'));
        $this->assertSame([], $this->filesWith('.gfx'));
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 5]);
        $this->assertSame(['fields' => [], 'sharded' => true], $db->getFieldIndexes('dr'));
        $this->assertSame([0, 2, 4, 6, 8, 10], $this->keys($db->find('dr', ['b' => 'b0'])));
        $this->assertSame([], $this->filesWith('.fx'), 'a lookup builds no dropped index');
    }

    // ================================================================== checkpoints

    /**
     * Pads a pure writer's records so that the data after $covered is exactly $tail bytes long. Each line
     * is the same record shape: its length is measured once (an input, not an expected value).
     */
    private function growTail(string $name, array $config, int $covered, int $tail, array $shape): void
    {
        \noneDB::clearStaticCache();
        $writer = $this->db($config); // loads no field index: never writes a checkpoint of one
        $data = $this->dataPath($name);
        $start = $this->size($data);
        $writer->insert($name, $shape + ['p' => str_repeat('x', 100)]);
        $line = $this->size($data) - $start;
        $overhead = $line - 100;
        $need = $tail - ($this->size($data) - $covered);
        $this->assertGreaterThanOrEqual($overhead, $need, 'precondition: room for one more line');
        $writer->insert($name, $shape + ['p' => str_repeat('x', $need - $overhead)]);
        $this->assertSame($tail, $this->size($data) - $covered, 'precondition: the tail is exactly ' . $tail . ' bytes');
        \noneDB::clearStaticCache();
    }

    /**
     * CHANGES.md: "A new checkpoint is written once at least 64 KiB of data ... is new, by a process
     * that uses the index: a reader that loaded it". 65535 new bytes: no checkpoint; 65536: the reader
     * writes one, without moving the lock generation (CLAUDE.md: derived write); explain(),
     * checkIntegrity() and a join (snapshot) never write one.
     */
    public function testReaderCheckpointsStartAt64KiBOfNewData(): void
    {
        $config = ['shardingEnabled' => false];
        foreach ([65535 => false, 65536 => true] as $tail => $written) {
            $name = 'th' . $tail;
            $db = $this->db($config);
            $db->insert($name, ['c' => 'a']);
            $db->createFieldIndex($name, 'c');
            $fx = $this->fxPath($name, 'c');
            $covered = $this->secondary($fx)[0]['s'];
            $this->assertSame($this->size($this->dataPath($name)), $covered);
            $this->growTail($name, $config, $covered, $tail, ['c' => 'b']);
            $before = file_get_contents($fx);
            $gen = $this->lockState($name)['gen'];

            $reader = $this->db($config);
            $reader->insert('other' . $tail, ['ref' => 'b']);
            $this->assertSame(2, $reader->query($name)->where(['c' => 'b'])->explain()['estimatedRead']);
            $this->assertTrue($reader->checkIntegrity($name)['ok']);
            $joined = $reader->query('other' . $tail)->join($name, 'ref', 'c', 'o')->get();
            $this->assertSame(2, $joined[0]['o']['key'], 'the last record with the value joins');
            clearstatcache();
            $this->assertSame($before, file_get_contents($fx), "explain(), checkIntegrity() and a join write no checkpoint (tail $tail)");

            \noneDB::clearStaticCache();
            $reader = $this->db($config);
            $this->assertSame([1, 2], $this->keys($reader->find($name, ['c' => 'b'])));
            $this->assertSame([0], $this->keys($reader->find($name, ['c' => 'a'])));
            [$header, $buckets, $content] = $this->secondary($fx);
            if ($written) {
                $this->assertSame($this->size($this->dataPath($name)), $header['s'], 'tail 65536: the reader wrote a checkpoint');
                $this->assertSame(['sa' => 0, 'sb' => [1, 2]], $buckets);
                $this->assertSame([1, 'fx', 'c', 2, 3, 2], [$header['v'], $header['kind'], $header['field'], $header['mk'], $header['n'], $header['c']]); // STORAGE_FORMAT.md §6.5.1
            } else {
                $this->assertSame($before, $content, 'tail 65535: no checkpoint yet');
            }
            $this->assertSame($gen, $this->lockState($name)['gen'], 'a reader never moves the generation');
            \noneDB::clearStaticCache();
            $this->assertSame([1, 2], $this->keys($this->db($config)->find($name, ['c' => 'b'])), 'the next process uses it');
        }
    }

    /**
     * The other half of the rule: "and at least a quarter of the index file's size". An index file of
     * about 320 KB waits for its quarter (about 80 KB) of new data, not 64 KiB (margins of 1000 bytes
     * either way, so the header line doesn't matter)
     */
    public function testReaderCheckpointsOfALargeIndexWaitForAQuarterOfItsSize(): void
    {
        $config = ['shardingEnabled' => false];
        $db = $this->db($config);
        $db->insert('big', array_map(function ($n) { return ['c' => str_repeat('v', 200) . $n]; }, range(0, 1499)));
        $db->createFieldIndex('big', 'c');
        $fx = $this->fxPath('big', 'c');
        $covered = $this->secondary($fx)[0]['s'];
        $fileSize = $this->size($fx);
        $quarter = intdiv($fileSize, 4);
        $this->assertGreaterThan(65536 + 3000, $quarter, 'precondition: the quarter rule decides');

        $this->growTail('big', $config, $covered, $quarter - 1000, ['c' => 'new']);
        $before = file_get_contents($fx);
        $this->assertSame([1500, 1501], $this->keys($this->db($config)->find('big', ['c' => 'new'])));
        clearstatcache();
        $this->assertSame($before, file_get_contents($fx), 'more than 64 KiB, less than a quarter: no checkpoint');

        $this->growTail('big', $config, $covered, $quarter + 1000, ['c' => 'new']);
        $gen = $this->lockState('big')['gen'];
        $this->assertSame([1500, 1501, 1502, 1503], $this->keys($this->db($config)->find('big', ['c' => 'new'])));
        $header = $this->secondary($fx)[0];
        $this->assertSame($this->size($this->dataPath('big')), $header['s'], 'beyond a quarter: checkpoint');
        $this->assertSame([1501, 1504, 1503], [$header['c'], $header['n'], $header['mk']], '1500 values and "new" (4 keys), highest key 1503');
        $this->assertSame($gen, $this->lockState('big')['gen']);
    }

    /**
     * "or a writer that has it loaded": a write session that appended 64 KiB+ to a shard whose field index
     * this process has loaded writes the shard's checkpoint and its global entry at its end; the next
     * process then rules the shard out with the new entry
     */
    public function testAWriterThatHasTheIndexLoadedCheckpointsItAndItsGlobalEntry(): void
    {
        $config = ['shardSize' => 100];
        $db = $this->db($config);
        $db->insert('wl', array_map(function ($n) { return ['c' => 'c' . ($n % 5)]; }, range(0, 149)));
        $db->createFieldIndex('wl', 'c');
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(range(3, 148, 5), $this->keys($db->find('wl', ['c' => 'c3'])), 'loads both shard indexes');
        $fx0 = file_get_contents($this->fxPath('wl', 'c', 0));
        $db->insert('wl', array_map(function ($n) { return ['c' => 'new' . $n, 'p' => str_repeat('x', 2000)]; }, range(0, 39))); // keys 150..189, shard 1
        $size1 = $this->size($this->dataPath('wl', 1));
        [$header1, $buckets1] = $this->secondary($this->fxPath('wl', 'c', 1));
        $this->assertSame($size1, $header1['s'], 'the shard index covers the session');
        $this->assertSame(150, $buckets1['snew0']);
        [$global, $filters] = $this->secondary($this->gfxPath('wl', 'c'));
        $this->assertSame($size1, $global['sh']['1']['s'], 'and its global entry');
        // Shard 1 now holds 45 values: more than the 256 bits its five made allow (32 per value), so the file was
        // made again with 2048 bits (the smallest power of two of at least 1440)
        $this->assertSame(2048, $global['bits']);
        $this->assertSame([0 => $this->shardFilter(['c0', 'c1', 'c2', 'c3', 'c4'], 2048),
            1 => $this->shardFilter(array_merge(['c0', 'c1', 'c2', 'c3', 'c4'], array_map(function ($n) { return 'new' . $n; }, range(0, 39))), 2048)], $filters);
        $this->assertSame($fx0, file_get_contents($this->fxPath('wl', 'c', 0)), 'shard 0 got nothing new');
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame([0 => 'skip', 1 => 'index'], array_column($db->query('wl')->where(['c' => 'new7'])->explain()['plan'], 'access', 'segment'));
        $this->assertSame([157], $this->keys($db->find('wl', ['c' => 'new7'])));
    }

    /**
     * Index files are never needed for correctness: a checkpoint a reader can't write, and an index that
     * can't be built (its file can't be written), leave the results exact (the segment is scanned) and
     * no partial file behind; once writing works again the files appear
     */
    public function testAnIndexFileThatCantBeWrittenFallsBackToAScan(): void
    {
        $config = ['shardingEnabled' => false, 'lockTimeout' => 0.05];
        $db = $this->db($config);
        $rows = [['c' => 'a', 'n' => 1], ['c' => 'b', 'n' => 5]];
        $db->insert('ff', $rows);
        $db->createFieldIndex('ff', 'c');
        $db->createFieldIndex('ff', 'n');
        \noneDB::clearStaticCache();
        $more = array_map(function ($i) { return ['c' => 'c' . ($i % 3), 'n' => $i, 'p' => str_repeat('x', 1000)]; }, range(0, 69));
        $this->db($config)->insert('ff', $more); // a pure writer: 70 KB the index doesn't cover
        $rows = array_merge($rows, $more);
        $fx = $this->fxPath('ff', 'c');
        $before = file_get_contents($fx);
        $this->setStatic('renameFn', function ($from, $to) { return substr($to, -3) !== '.fx' && rename($from, $to); });

        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(self::select($rows, self::eq('c', 'c1')), $this->keys($db->find('ff', ['c' => 'c1'])));
        clearstatcache();
        $this->assertSame($before, file_get_contents($fx), 'the checkpoint failed: the file is unchanged');

        unlink($this->fxPath('ff', 'n'));
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(self::select($rows, self::eq('n', 5)), $this->keys($db->find('ff', ['n' => 5])), 'equality: scan');
        $this->assertSame([1, 7], $this->keys($db->find('ff', ['n' => 5])));
        $range = self::select($rows, function ($r) { return self::holds($r, 'n', '$gte', 10) && self::holds($r, 'n', '$lt', 20); });
        $this->assertSame($range, $this->keys($db->query('ff')->where(['n' => ['$gte' => 10, '$lt' => 20]])->get()), 'range: scan');
        $this->assertSame($range, $this->keys($db->between('ff', 'n', 10, 19)));
        $this->assertSame([1, 7], $this->keys($db->query('ff')->whereIn('n', [5])->get()));
        $this->assertFileDoesNotExist($this->fxPath('ff', 'n'));
        $this->assertSame([], $this->filesWith('.tmp'), 'no temp file left');

        // A writer that has the index loaded can't write its checkpoint at the end of the session: the
        // write succeeds anyway (checkpoints are best effort)
        $this->assertSame(self::select($rows, self::eq('c', 'c2')), $this->keys($db->find('ff', ['c' => 'c2'])), 'loads the index of c');
        $before = file_get_contents($fx);
        $batch = array_map(function ($i) { return ['c' => 'late', 'n' => 1000 + $i, 'p' => str_repeat('y', 1000)]; }, range(0, 69));
        $this->assertSame(['n' => 70], $db->insert('ff', $batch));
        $rows = array_merge($rows, $batch);
        clearstatcache();
        $this->assertSame($before, file_get_contents($fx));
        $this->assertSame(self::select($rows, self::eq('c', 'late')), $this->keys($db->find('ff', ['c' => 'late'])));

        $this->setStatic('renameFn', null);
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame([1, 7], $this->keys($db->find('ff', ['n' => 5])));
        $this->assertFileExists($this->fxPath('ff', 'n'));
        $this->assertSame(self::select($rows, self::eq('c', 'c1')), $this->keys($db->find('ff', ['c' => 'c1'])));
        $this->assertSame($this->size($this->dataPath('ff')), $this->secondary($fx)[0]['s'], 'now the checkpoint is written');
    }

    /**
     * API.md: "Waiting longer than lockTimeout seconds throws RuntimeException with code
     * noneDB::LOCK_FAILED". A read that must build a missing index needs the exclusive lock; while another
     * process holds a shared one, the read fails with LOCK_FAILED (it never continues without a lock)
     */
    public function testBuildingAnIndexUnderAnotherReadersLockFailsWithLockFailed(): void
    {
        $config = ['shardingEnabled' => false, 'lockTimeout' => 0.3];
        $db = $this->db($config);
        $db->insert('lc', [['c' => 'a'], ['c' => 'b']]);
        $db->createFieldIndex('lc', 'c');
        unlink($this->fxPath('lc', 'c'));
        \noneDB::clearStaticCache();
        $lock = $this->dbPath('lc', 'lock');
        $script = $this->childScript('$fp = fopen(' . var_export($lock, true) . ', "rb"); flock($fp, LOCK_SH); echo "held\n"; fflush(STDOUT); fgets(STDIN);');
        $proc = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        try {
            $this->assertSame("held\n", fgets($pipes[1]));
            foreach (['equality' => function (\noneDB $db) { return $db->find('lc', ['c' => 'b']); },
                      'range' => function (\noneDB $db) { return $db->query('lc')->where(['c' => ['$gte' => 'b']])->get(); }] as $what => $read) {
                try {
                    $read($this->db($config));
                    $this->fail("$what: no exception");
                } catch (\RuntimeException $e) {
                    $this->assertSame(\noneDB::LOCK_FAILED, $e->getCode(), "$what: " . $e->getMessage());
                }
            }
        } finally {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_close($proc);
        }
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame([1], $this->keys($db->find('lc', ['c' => 'b'])));
        $this->assertFileExists($this->fxPath('lc', 'c'));
    }

    /**
     * An index file that doesn't describe its data is rebuilt from the data: a body that isn't JSON, a
     * checksum that doesn't match (even with plausible buckets), another kind or a header without "s".
     * A file whose bucket is a one-element list (not what this version writes, still a valid index) is
     * followed through updates.
     */
    public function testDamagedIndexFilesAreRebuiltFromTheData(): void
    {
        $config = ['shardingEnabled' => false];
        $db = $this->db($config);
        $db->insert('dm', [['c' => 'a'], ['c' => 'b'], ['c' => 'b'], ['c' => 'c']]);
        $db->createFieldIndex('dm', 'c');
        $fx = $this->fxPath('dm', 'c');
        [$header, $buckets, $pristine] = $this->secondary($fx);
        $this->assertSame(['sa' => 0, 'sb' => [1, 2], 'sc' => 3], $buckets);
        // Damages in the paged format (§6.5.1); a valid body with other entries where the header is the broken part
        $at = strpos($pristine, "\n") + 1; // the body's first byte
        $wrong = ['sa' => [1, 2, 3]];
        $damages = [
            'body cut' => function () use ($fx, $pristine, $at) { file_put_contents($fx, substr($pristine, 0, $at + intdiv(strlen($pristine) - $at, 2))); },
            'page crc differs' => function () use ($fx, $pristine, $at) { $c = $pristine; $c[$at + 2] = chr(ord($c[$at + 2]) ^ 1); file_put_contents($fx, $c); },
            'checksum differs' => function () use ($fx, $header, $wrong) { $this->writeSecondary($fx, ['h' => $header['h'] === '00000000' ? '11111111' : '00000000'] + $header, $wrong); },
            'another kind' => function () use ($fx, $header, $wrong) { $this->writeSecondary($fx, ['kind' => 'rx'] + $header, $wrong); },
            'no covered bytes' => function () use ($fx, $header, $wrong) { $this->writeSecondary($fx, array_diff_key($header, ['s' => 0]), $wrong); },
            'unknown version (a JSON body)' => function () use ($fx, $header, $wrong) { file_put_contents($fx, json_encode(['v' => 1] + array_diff_key($header, ['pg' => 0, 'w' => 0])) . "\n" . json_encode($wrong) . "\n"); },
            'header not JSON' => function () use ($fx, $pristine, $at) { file_put_contents($fx, "garbage\n" . substr($pristine, $at)); },
        ];
        foreach ($damages as $damage => $write) {
            $write();
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->assertSame([0], $this->keys($db->find('dm', ['c' => 'a'])), $damage);
            $this->assertSame([1, 2], $db->getKeysFromFieldIndex('dm', 'c', 'b'), $damage);
            $this->assertSame([1, 2, 3], $this->keys($db->query('dm')->where(['c' => ['$gte' => 'b']])->get()), $damage);
            [$h, $b] = $this->secondary($fx);
            $this->assertSame($buckets, $b, "$damage: rebuilt");
            $this->assertSame([$header['s'], $header['h']], [$h['s'], $h['h']], $damage);
        }

        // A file written by the test (its own write token): a bucket of one key, the key leaves it, the bucket goes
        file_put_contents($fx, $pristine);
        $this->writeSecondary($fx, $header, ['sa' => [0], 'sb' => [1, 2], 'sc' => [3]]);
        $written = $this->secondary($fx)[2];
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame([0], $db->getKeysFromFieldIndex('dm', 'c', 'a'));
        $this->assertSame($written, $this->secondary($fx)[2], 'precondition: the file fits and is used, not rebuilt');
        $db->update('dm', [['key' => 0], ['set' => ['c' => 'z']]]);
        // Replayed onto the loaded file (the next update compacts: garbage over 30%, a new data file)
        $this->assertSame([], $db->getKeysFromFieldIndex('dm', 'c', 'a'), 'the one-element bucket is gone');
        $this->assertSame([0], $db->getKeysFromFieldIndex('dm', 'c', 'z'));
        $this->assertSame([], $this->keys($db->query('dm')->where(['c' => ['$lt' => 'b']])->get()));
        $db->update('dm', [['key' => 3], ['set' => ['c' => 'b']]]);
        foreach ([true, false] as $warm) {
            if (!$warm) {
                \noneDB::clearStaticCache();
                $db = $this->db($config);
            }
            $this->assertSame([], $db->getKeysFromFieldIndex('dm', 'c', 'a'));
            $this->assertSame([], $db->getKeysFromFieldIndex('dm', 'c', 'c'));
            $this->assertSame([0], $db->getKeysFromFieldIndex('dm', 'c', 'z'));
            $this->assertSame([1, 2, 3], $db->getKeysFromFieldIndex('dm', 'c', 'b'));
            $this->assertSame([0, 1, 2, 3], $this->keys($db->query('dm')->where(['c' => ['$gte' => 'a']])->get()));
            $this->assertSame([1, 2, 3], $this->keys($db->query('dm')->where(['c' => ['$lt' => 'z']])->get()));
        }
        $this->assertTrue($db->checkIntegrity('dm')['ok']);
    }

    // ================================================================== index list

    /**
     * An index list that is empty is derived from the index files (STORAGE_FORMAT.md §5.1 indexes.json: "An
     * empty or invalid file is derived again from the headers of the generation's .fx/.rx files"): the
     * header names the field, also one whose token is hashed ("e-mail")
     */
    public function testAnEmptyIndexListIsDerivedFromTheIndexFiles(): void
    {
        $config = ['shardingEnabled' => false];
        $db = $this->db($config);
        $db->insert('dv', [['city' => 'A', 'e-mail' => 'a@x', 'loc' => ['type' => 'Point', 'coordinates' => [1, 1]]],
            ['city' => 'B', 'e-mail' => 'b@x', 'loc' => ['type' => 'Point', 'coordinates' => [2, 2]]]]);
        $db->createFieldIndex('dv', 'city');
        $db->createFieldIndex('dv', 'e-mail');
        $db->createSpatialIndex('dv', 'loc');
        file_put_contents($this->dbPath('dv', 'indexes'), ''); // zero-length after a power loss
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $fields = $db->getFieldIndexes('dv')['fields'];
        sort($fields);
        $this->assertSame(['city', 'e-mail'], $fields);
        $this->assertSame(['loc'], $db->getSpatialIndexes('dv'));
        $this->assertSame([1], $this->keys($db->find('dv', ['e-mail' => 'b@x'])));
        $this->assertSame([0], $this->keys($db->find('dv', ['city' => 'A'])));
        $this->assertSame([1], $this->keys($db->withinBBox('dv', 'loc', 1.5, 1.5, 2.5, 2.5)));
    }

    // ================================================================== other filters

    /**
     * first(), exists() and count() of noneDB with a key filter (find() semantics: a key, a digit string,
     * a list in the order asked for), also next to an indexed field
     */
    public function testKeyFiltersInFirstExistsAndCount(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $db->insert('kf', array_map(function ($n) { return ['c' => 'c' . ($n % 2)]; }, range(0, 9)));
        $db->createFieldIndex('kf', 'c');
        $this->assertTrue($db->exists('kf', ['key' => 3]));
        $this->assertTrue($db->exists('kf', ['key' => '3']));
        $this->assertFalse($db->exists('kf', ['key' => 99]));
        $this->assertTrue($db->exists('kf', ['key' => 3, 'c' => 'c1']));
        $this->assertFalse($db->exists('kf', ['key' => 3, 'c' => 'c0']));
        $this->assertSame(7, $db->first('kf', ['key' => [7, 3]])['key'], 'the order of the keys asked for');
        $this->assertSame(3, $db->first('kf', ['key' => [8, 3], 'c' => 'c1'])['key']);
        $this->assertNull($db->first('kf', ['key' => [8, 6], 'c' => 'c1']));
        $this->assertSame(2, $db->count('kf', ['key' => [1, 2, 999]]));
        $this->assertSame(1, $db->count('kf', ['key' => [1, 2, 999], 'c' => 'c0']));
    }

    /** An object as a filter value is identical to no stored value (records hold arrays): no match, no error */
    public function testObjectFilterValuesMatchNothing(): void
    {
        foreach ([['shardingEnabled' => false], ['shardSize' => 2]] as $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->insert('ob', [['c' => ['a' => 1]], ['c' => []], ['c' => 'x']]);
            $db->createFieldIndex('ob', 'c');
            $this->assertSame([], $db->find('ob', ['c' => (object)['a' => 1]]));
            $this->assertSame([], $db->query('ob')->where(['c' => (object)['a' => 1]])->get());
            $this->assertSame([], $db->query('ob')->where(['c' => ['$eq' => new \stdClass()]])->get());
            $this->assertSame(0, $db->query('ob')->where(['c' => new \stdClass()])->count());
            $this->assertFalse($db->exists('ob', ['c' => new \stdClass()]));
            $this->assertSame([0], $this->keys($db->find('ob', ['c' => ['a' => 1]])), 'the array itself matches');
        }
    }

    /** Index file tokens are cached per process; the cache stays bounded and tokens stay the same */
    public function testTheTokenCacheStaysBounded(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        for ($i = 0; $i < 5000; $i++) {
            $field = 'Field ' . $i;
            $this->assertSame($this->token($field), $this->callPrivate($db, 'indexToken', [$field]));
        }
        $this->assertLessThanOrEqual(4097, count($this->getStatic('indexTokens')));
        $this->assertSame($this->token('Field 7'), $this->callPrivate($db, 'indexToken', ['Field 7']), 'the same token after the cache was cleared');
    }

    /**
     * CHANGES.md: "createFieldIndex() builds the index before the index list names it, so a creation
     * that fails ... leaves no broken entry"; API.md: creating an index is not undone, run it again
     */
    public function testAnIndexListThatCantBeWrittenLeavesNoBrokenEntry(): void
    {
        $config = ['shardingEnabled' => false, 'lockTimeout' => 0.05];
        $db = $this->db($config);
        $db->insert('il', [['c' => 'a'], ['c' => 'b']]);
        $db->createFieldIndex('il', 'c');
        $list = $this->dbPath('il', 'indexes');
        $before = file_get_contents($list);
        $this->setStatic('renameFn', function ($from, $to) { return basename($to) !== 'indexes.json' && rename($from, $to); });
        try {
            $db->createFieldIndex('il', 'd');
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed to write index list', $e->getMessage());
        }
        $this->setStatic('renameFn', null);
        clearstatcache();
        $this->assertSame($before, file_get_contents($list), 'the list is unchanged');
        $this->assertSame([], $this->filesWith('.tmp'));
        foreach ([true, false] as $warm) {
            if (!$warm) {
                \noneDB::clearStaticCache();
                $db = $this->db($config);
            }
            $this->assertSame(['c'], $db->getFieldIndexes('il')['fields']);
            $this->assertFalse($db->hasFieldIndex('il', 'd'));
            $this->assertSame([1], $this->keys($db->find('il', ['c' => 'b'])));
        }
        $this->assertSame(['success' => true, 'indexed' => 0, 'error' => null], $db->createFieldIndex('il', 'd'), 'run again: works');
        $this->assertSame(['c', 'd'], $db->getFieldIndexes('il')['fields']);
    }

    /** The documented bucket id of a value (CLAUDE.md "typed buckets"): n, t, f, i<int>, d<hex of the double>, s<string>; null for arrays */
    private static function typedId($v): ?string
    {
        if ($v === null) {
            return 'n';
        }
        if (is_bool($v)) {
            return $v ? 't' : 'f';
        }
        if (is_int($v)) {
            return 'i' . $v;
        }
        if (is_float($v)) {
            return 'd' . bin2hex(pack('E', $v + 0.0));
        }
        return is_string($v) ? 's' . $v : null;
    }

    /**
     * STORAGE_FORMAT.md §6.5: a global index records the lock epoch it was written under ("le"); while it
     * is current every entry fits (no data file replaced). Checked against the files: each entry's epoch is its
     * shard's, it covers at most the shard's bytes and "h" is the crc32b of the up to 256 bytes before "s";
     * an entry that covers its whole shard lists exactly the shard's values in the map.
     * @return bool false when the recorded epoch isn't current (then nothing is promised)
     */
    private function assertGlobalEntriesFit(string $name, string $field, string $label): bool
    {
        [$header, $filters] = $this->secondary($this->gfxPath($name, $field));
        $epoch = $this->lockState($name)['epoch'];
        if ($header['le'] !== $epoch) {
            return false;
        }
        foreach ($header['sh'] as $shard => $entry) {
            $data = $this->dataPath($name, (int)$shard);
            $this->assertFileExists($data, "$label: shard $shard exists");
            $ckpt = json_decode(strtok((string)file_get_contents($this->dbPath($name, 'kix', (int)$shard)), "\n"), true);
            $this->assertSame($ckpt['e'], $entry['e'], "$label: shard $shard epoch");
            $size = $this->size($data);
            $this->assertLessThanOrEqual($size, $entry['s'], "$label: shard $shard bytes");
            $fp = fopen($data, 'rb');
            fseek($fp, max(0, $entry['s'] - 256));
            $bytes = $entry['s'] === 0 ? '' : (string)fread($fp, $entry['s'] - max(0, $entry['s'] - 256));
            fclose($fp);
            $this->assertSame(hash('crc32b', $bytes), $entry['h'], "$label: shard $shard checksum");
            if ($entry['s'] === $size) {
                $values = [];
                foreach (array_filter(explode("\n", (string)file_get_contents($data)), 'strlen') as $line) {
                    $record = json_decode($line, true);
                    if (isset($record['__nonedb_deleted'])) {
                        unset($values[$record['__nonedb_deleted']]);
                        continue;
                    }
                    $id = array_key_exists($field, $record) ? self::typedId($record[$field]) : null;
                    if ($id === null) {
                        unset($values[$record['key']]);
                    } else {
                        $values[$record['key']] = $id;
                    }
                }
                $this->assertSame($this->shardFilterOfIds(array_unique($values), $header['bits']), $filters[$shard], "$label: shard $shard values");
            }
        }
        return true;
    }

    /**
     * compact() replaces the data files and drops shards that became empty: lookups stay exact, the global
     * index is written again for the shards that are left, every entry fits, shards are skipped again
     */
    public function testCompactionThatDropsShardsKeepsTheGlobalIndexTrue(): void
    {
        $config = ['shardSize' => 10];
        $db = $this->db($config);
        $db->insert('ce', array_map(function ($n) { return ['g' => 'g' . intdiv($n, 10), 'n' => $n]; }, range(0, 39)));
        $db->createFieldIndex('ce', 'g');
        $this->assertTrue($this->assertGlobalEntriesFit('ce', 'g', 'created'), 'just written: under the current epoch');
        $this->assertSame(10, $db->delete('ce', ['g' => 'g1'])['n']);
        $this->assertSame(2, $db->update('ce', [['key' => [20, 21]], ['set' => ['g' => 'moved']]])['n']);
        $result = $db->compact('ce');
        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['newShardCount'], 'shard 1 is empty: dropped');
        $this->assertFileDoesNotExist($this->dataPath('ce', 1));
        $this->assertFileDoesNotExist($this->fxPath('ce', 'g', 1));
        $this->assertGlobalEntriesFit('ce', 'g', 'compacted');
        foreach ([true, false] as $warm) {
            if (!$warm) {
                \noneDB::clearStaticCache();
                $db = $this->db($config);
            }
            $this->assertSame([], $db->find('ce', ['g' => 'g1']));
            $this->assertSame(range(22, 29), $this->keys($db->find('ce', ['g' => 'g2'])));
            $this->assertSame([20, 21], $this->keys($db->find('ce', ['g' => 'moved'])));
            $this->assertSame(range(30, 39), $this->keys($db->find('ce', ['g' => 'g3'])));
            $this->assertSame([0 => 'skip', 2 => 'index', 3 => 'skip'], array_column($db->query('ce')->where(['g' => 'g2'])->explain()['plan'], 'access', 'segment'));
        }
        $this->assertGlobalEntriesFit('ce', 'g', 'after lookups');
        $this->assertTrue($db->checkIntegrity('ce')['ok']);
    }

    /**
     * @test A checkpoint that a read-only load put off (explain(), checkIntegrity(), a join: they never
     * write) is written by the next plain read of the same process (CHANGES.md "Index formats": a new
     * checkpoint is written by a process that uses the index: a reader that loaded it)
     */
    public function testACheckpointPutOffByAReadOnlyLoadIsWrittenByTheNextRead(): void
    {
        $loads = [
            'explain' => function (\noneDB $db) { $db->query('po')->where(['c' => 'c1'])->explain(); },
            'checkIntegrity' => function (\noneDB $db) { $db->checkIntegrity('po'); },
            'join' => function (\noneDB $db) { $db->query('pj')->join('po', 'c', 'c')->get(); },
        ];
        foreach ($loads as $label => $load) {
            $this->cleanTestDirectory();
            $config = ['shardingEnabled' => false];
            $db = $this->db($config);
            $db->insert('po', [['c' => 'c0']]);
            $db->insert('pj', [['c' => 'c1']]);
            $this->assertTrue($db->createFieldIndex('po', 'c')['success']);
            $before = $this->secondary($this->fxPath('po', 'c'))[0]['s'];
            // A writer that never reads the index: more than 64 KiB of data after the checkpoint
            \noneDB::clearStaticCache();
            $writer = $this->db($config);
            for ($i = 0; $i < 4; $i++) {
                $writer->insert('po', array_map(function ($n) { return ['c' => 'c' . ($n % 3), 'pad' => str_repeat('x', 40)]; }, range(0, 499)));
            }
            $size = $this->size($this->dataPath('po'));
            $this->assertGreaterThan($before + 65536, $size, 'precondition: a long tail');
            $this->assertSame($before, $this->secondary($this->fxPath('po', 'c'))[0]['s'], 'the writer wrote no checkpoint');

            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $load($db);
            $this->assertSame($before, $this->secondary($this->fxPath('po', 'c'))[0]['s'], $label . ' writes nothing');
            // the same process, a plain read: the checkpoint now covers the data
            $this->assertCount(668, $db->find('po', ['c' => 'c1']), $label); // 4 inserts x 167 (n = 1, 4, ..., 499)
            $this->assertSame($size, $this->secondary($this->fxPath('po', 'c'))[0]['s'], $label . ': written by the next read');
        }
    }

    /**
     * @test Building a field or spatial index of a sharded database writes its global file once, not once
     * per shard: per shard it was O(shards²) (10M records in 1000 shards: 242 s for createFieldIndex()).
     * Counted through the rename hook every atomic write goes through.
     */
    public function testIndexBuildsWriteTheGlobalFileOnce(): void
    {
        $db = $this->db(['shardSize' => 10]);
        $rows = [];
        for ($n = 0; $n < 200; $n++) { // 20 shards, every value of "c" in every shard
            $rows[] = ['c' => 'c' . ($n % 5), 'g' => ['type' => 'Point', 'coordinates' => [$n % 20, intdiv($n, 20)]]];
        }
        $db->insert('gw', $rows);
        $this->assertSame(20, $db->getShardInfo('gw')['shards']);
        $writes = [];
        $this->setStatic('renameFn', function ($from, $to) use (&$writes) {
            $writes[] = basename($to);
            return rename($from, $to);
        });
        try {
            $count = function (string $kind) use (&$writes) {
                return count(array_filter($writes, function ($f) use ($kind) { return substr($f, -strlen($kind) - 1) === '.' . $kind; }));
            };
            $this->assertTrue($db->createFieldIndex('gw', 'c')['success']);
            $this->assertSame([20, 1], [$count('fx'), $count('gfx')], 'one file per shard, one global file');
            $writes = [];
            $this->assertTrue($db->rebuildFieldIndex('gw', 'c')['success']);
            $this->assertSame([20, 1], [$count('fx'), $count('gfx')]);
            $writes = [];
            $this->assertTrue($db->createSpatialIndex('gw', 'g')['success']);
            $this->assertSame([20, 1], [$count('rx'), $count('grx')]);
            $writes = [];
            $this->assertTrue($db->rebuildSpatialIndex('gw', 'g')['success']);
            $this->assertSame([20, 1], [$count('rx'), $count('grx')]);
        } finally {
            $this->setStatic('renameFn', null);
        }
        // the global files describe every shard: lookups and boxes are right, nothing is skipped wrongly
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 10]);
        $this->assertSame(range(3, 198, 5), array_column($db->find('gw', ['c' => 'c3']), 'key'));
        $this->assertSame([], $db->find('gw', ['c' => 'c9']));
        $this->assertSame([0, 1, 20, 21], array_column($db->withinBBox('gw', 'g', 0, 0, 1, 1), 'key'));
        $header = $this->secondary($this->gfxPath('gw', 'c'))[0];
        $this->assertSame(array_map('strval', range(0, 19)), array_map('strval', array_keys($header['sh'])));
    }

    /**
     * @test Checkpoints of many shards in one operation (a reader that replays long tails, the end of a
     * write session) write each global file once, when the operation ends, not once per shard
     */
    public function testCheckpointsOfManyShardsWriteTheGlobalFileOnce(): void
    {
        // no automatic compaction: it would replace the data files (and invalidate the indexes) instead
        $config = ['shardSize' => 10, 'autoCompactThreshold' => 5];
        $db = $this->db($config);
        $rows = [];
        for ($n = 0; $n < 120; $n++) { // 12 shards
            $rows[] = ['c' => 'c' . ($n % 3)];
        }
        $db->insert('gc', $rows);
        $this->assertSame(12, $db->getShardInfo('gc')['shards']);
        $this->assertTrue($db->createFieldIndex('gc', 'c')['success']);
        // A writer that never loads the index (no checkpoint): more than 64 KiB of new lines in every shard
        \noneDB::clearStaticCache();
        $pad = str_repeat('x', 7000);
        $this->assertSame(['n' => 120], $this->db($config)->update('gc', [[], ['set' => ['pad' => $pad]]]));
        $writes = [];
        $this->setStatic('renameFn', function ($from, $to) use (&$writes) {
            $writes[] = basename($to);
            return rename($from, $to);
        });
        $count = function (string $kind) use (&$writes) {
            return count(array_filter($writes, function ($f) use ($kind) { return substr($f, -strlen($kind) - 1) === '.' . $kind; }));
        };
        try {
            // A reader in a new process state loads every shard's index and replays its long tail
            \noneDB::clearStaticCache();
            $reader = $this->db($config);
            $this->assertSame(range(1, 118, 3), array_column($reader->find('gc', ['c' => 'c1']), 'key'));
            $this->assertSame([12, 1], [$count('fx'), $count('gfx')], 'reader: a checkpoint per shard, the global file once');

            // A write session that touches every shard of an index this process has loaded
            $writes = [];
            $this->assertSame(['n' => 120], $reader->update('gc', [[], ['set' => ['pad' => $pad . 'y']]]));
            $this->assertSame([12, 1], [$count('fx'), $count('gfx')], 'writer: a checkpoint per shard, the global file once');
        } finally {
            $this->setStatic('renameFn', null);
        }
        // Every shard has its entry, at the bytes its checkpoint covers
        $header = $this->secondary($this->gfxPath('gc', 'c'))[0];
        $this->assertCount(12, $header['sh']);
        foreach ($header['sh'] as $shard => $entry) {
            $this->assertSame($this->secondary($this->fxPath('gc', 'c', (int)$shard))[0]['s'], $entry['s'], 'shard ' . $shard);
        }
        \noneDB::clearStaticCache();
        $this->assertSame(range(2, 119, 3), array_column($this->db($config)->find('gc', ['c' => 'c2']), 'key'));
        $this->assertSame([], $this->db($config)->find('gc', ['c' => 'c9']));
    }

    /** first(), exists() and count() of a database that doesn't exist: nothing, and no file */
    public function testFirstExistsAndCountOfAMissingDatabase(): void
    {
        foreach ([true, false] as $autoCreate) {
            $db = $this->db(['shardingEnabled' => false, 'autoCreateDB' => $autoCreate]);
            $this->assertNull($db->first('void', ['c' => 1]));
            $this->assertFalse($db->exists('void', ['c' => 1]));
            $this->assertSame(0, $db->count('void', ['c' => 1]));
            $this->assertSame($autoCreate ? [] : false, $db->find('void', ['c' => 1]), 'API.md: false without autoCreateDB');
            $this->assertSame([], $this->dbFiles('void'));
        }
    }
}
