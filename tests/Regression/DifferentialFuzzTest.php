<?php

namespace noneDB\Tests\Regression;

/**
 * Model-based differential testing.
 *
 * Thousands of random operations run against noneDB and against an independent in-memory reference
 * model written here with plain PHP arrays (it never calls noneDB code). After every operation the
 * complete contents, and random find()/query() results, must be identical. Runs in several storage
 * configurations: non-sharded, sharded with tiny shards (auto-migration happens mid-run), field
 * indexes, write buffer, and two instances with separate caches used alternately.
 */
class DifferentialFuzzTest extends RegressionTestCase
{
    /** @var array<int, array> reference model: key => record (without "key") */
    private $model = [];
    /** @var int */
    private $nextKey = 0;

    // ------------------------------------------------------------------
    // Reference model
    // ------------------------------------------------------------------

    private static function keyList($value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [$value] as $v) {
            if (is_int($v) && $v >= 0) {
                $k = $v;
            } elseif (is_string($v) && preg_match('/^(0|[1-9][0-9]*)$/', $v)) {
                $k = (int)$v;
            } else {
                continue;
            }
            if (!in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /** find()/update()/delete() filter semantics */
    private function modelSelect(array $filters): array
    {
        $keys = null;
        if (array_key_exists('key', $filters)) {
            $keys = self::keyList($filters['key']);
            unset($filters['key']);
        }
        $result = [];
        $candidates = $keys === null ? array_keys($this->model) : $keys;
        if ($keys === null) {
            sort($candidates);
        }
        foreach ($candidates as $k) {
            if (!array_key_exists($k, $this->model)) {
                continue;
            }
            $rec = $this->model[$k];
            $ok = true;
            foreach ($filters as $f => $v) {
                if (!array_key_exists($f, $rec) || $rec[$f] !== $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $result[$k] = $rec;
            }
        }
        return $result;
    }

    private static function withKeys(array $records): array
    {
        $out = [];
        foreach ($records as $k => $r) {
            $r['key'] = $k;
            $out[] = $r;
        }
        return $out;
    }

    /** ordering comparison of the query builder: numbers (and numeric strings) numerically, strings lexically */
    private static function cmp($a, $b): ?int
    {
        foreach ([$a, $b] as $v) {
            if ($v === null || is_bool($v) || is_array($v)) {
                return null;
            }
        }
        $na = is_int($a) || is_float($a) || (is_string($a) && is_numeric($a));
        $nb = is_int($b) || is_float($b) || (is_string($b) && is_numeric($b));
        if ($na && $nb) {
            return ($a + 0) <=> ($b + 0);
        }
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }
        return null;
    }

    private static function opMatch(array $rec, string $field, string $op, $operand): bool
    {
        $has = array_key_exists($field, $rec);
        $v = $has ? $rec[$field] : null;
        switch ($op) {
            case '$gt':  $c = $has ? self::cmp($v, $operand) : null; return $c !== null && $c > 0;
            case '$gte': $c = $has ? self::cmp($v, $operand) : null; return $c !== null && $c >= 0;
            case '$lt':  $c = $has ? self::cmp($v, $operand) : null; return $c !== null && $c < 0;
            case '$lte': $c = $has ? self::cmp($v, $operand) : null; return $c !== null && $c <= 0;
            case '$ne':  return !$has || $v !== $operand;
            case '$eq':  return $has && $v === $operand;
            case '$in':  return $has && in_array($v, $operand, true);
            case '$nin': return !$has || !in_array($v, $operand, true);
            case '$exists': return $operand ? $has : !$has;
        }
        throw new \LogicException($op);
    }

    private static function groupMatch(array $rec, array $group): bool
    {
        foreach ($group as $field => $value) {
            if (is_array($value) && !empty($value) && is_string(array_key_first($value)) && array_key_first($value)[0] === '$') {
                foreach ($value as $op => $operand) {
                    if (!self::opMatch($rec, (string)$field, $op, $operand)) {
                        return false;
                    }
                }
            } elseif (!array_key_exists($field, $rec) || $rec[$field] !== $value) {
                return false;
            }
        }
        return true;
    }

    private static function sortRank($v): int
    {
        if ($v === null) return 0;
        if (is_bool($v)) return 1;
        if (is_int($v) || is_float($v)) return 2;
        if (is_string($v)) return 3;
        return 4;
    }

    private function modelQuery(array $q): array
    {
        $rows = self::withKeys($this->modelSelect([]));
        $rows = array_values(array_filter($rows, function ($r) use ($q) {
            $hasWhere = !empty($q['where']);
            $hasOr = !empty($q['or']);
            $match = !$hasWhere && !$hasOr;
            if ($hasWhere) {
                $all = true;
                foreach ($q['where'] as $g) {
                    if (!self::groupMatch($r, $g)) { $all = false; break; }
                }
                $match = $all;
            }
            if (!$match && $hasOr) {
                foreach ($q['or'] as $g) {
                    if (self::groupMatch($r, $g)) { $match = true; break; }
                }
            }
            if (!$match) return false;
            foreach ($q['whereIn'] as [$f, $vals]) {
                if (!array_key_exists($f, $r) || !in_array($r[$f], $vals, true)) return false;
            }
            foreach ($q['whereNotIn'] as [$f, $vals]) {
                if (array_key_exists($f, $r) && in_array($r[$f], $vals, true)) return false;
            }
            foreach ($q['whereNot'] as $g) {
                foreach ($g as $f => $v) {
                    if (array_key_exists($f, $r) && $r[$f] === $v) return false;
                }
            }
            foreach ($q['between'] as [$f, $min, $max]) {
                if (!array_key_exists($f, $r)) return false;
                $lo = self::cmp($r[$f], $min);
                $hi = self::cmp($r[$f], $max);
                if ($lo === null || $hi === null || $lo < 0 || $hi > 0) return false;
            }
            return true;
        }));
        if ($q['sort'] !== null) {
            [$field, $order] = $q['sort'];
            $decorated = [];
            foreach ($rows as $i => $r) {
                $decorated[] = [$i, $r];
            }
            usort($decorated, function ($x, $y) use ($field, $order) {
                $a = $x[1][$field] ?? null;
                $b = $y[1][$field] ?? null;
                $ra = self::sortRank($a);
                $rb = self::sortRank($b);
                $c = $ra !== $rb ? $ra <=> $rb : ($ra === 4 ? strcmp(json_encode($a), json_encode($b)) : $a <=> $b);
                if ($order === 'desc') $c = -$c;
                return $c !== 0 ? $c : $x[0] <=> $y[0];
            });
            $rows = array_column($decorated, 1);
        }
        return array_slice($rows, $q['offset'], $q['limit']);
    }

    // ------------------------------------------------------------------
    // Random generators
    // ------------------------------------------------------------------

    private function randValue(string $field)
    {
        switch ($field) {
            case 'a': return [0, 1, 2, 3, 5, 8, '3', '8', 2.5, null, -0.0][mt_rand(0, 10)];
            case 'b': return ['x', 'y', 'z', 'Z', '', true, false, null][mt_rand(0, 7)];
            case 'c': return mt_rand(0, 1) ? mt_rand(-5, 5) / 2 : 'w' . mt_rand(0, 3);
            default:  return [['t' => mt_rand(0, 1)], [mt_rand(0, 2)], []][mt_rand(0, 2)];
        }
    }

    private function randRecord(): array
    {
        $r = [];
        foreach (['a', 'b', 'c', 'd'] as $f) {
            if (mt_rand(0, 4) > 0) {
                $r[$f] = $this->randValue($f);
            }
        }
        if (empty($r)) {
            $r['a'] = 0;
        }
        return $r;
    }

    private function randFilter(): array
    {
        $filter = [];
        $n = mt_rand(0, 2);
        for ($i = 0; $i < $n; $i++) {
            $f = ['a', 'b', 'c', 'd'][mt_rand(0, 3)];
            $filter[$f] = $this->randValue($f);
        }
        if (mt_rand(0, 5) === 0) {
            $keys = array_keys($this->model);
            $pick = empty($keys) ? 0 : $keys[array_rand($keys)];
            $filter['key'] = [$pick, (string)$pick, mt_rand(0, $this->nextKey + 2), [$pick, $pick + 1]][mt_rand(0, 3)];
        }
        return $filter;
    }

    private function randOperatorGroup(): array
    {
        $ops = [
            ['a', '$gt', mt_rand(0, 6)], ['a', '$lte', '3'], ['a', '$ne', 3], ['b', '$in', ['x', 'Z', true]],
            ['b', '$nin', ['y', null]], ['c', '$gte', 0], ['c', '$lt', 'w2'], ['d', '$exists', (bool)mt_rand(0, 1)],
            ['a', '$eq', 8], ['c', '$gt', -1.5],
        ];
        $group = [];
        for ($i = mt_rand(1, 2); $i > 0; $i--) {
            [$f, $op, $v] = $ops[mt_rand(0, count($ops) - 1)];
            $group[$f][$op] = $v;
        }
        if (mt_rand(0, 2) === 0) {
            $f = ['a', 'b'][mt_rand(0, 1)];
            if (!isset($group[$f])) {
                $group[$f] = $this->randValue($f);
            }
        }
        return $group;
    }

    private function randQuery(): array
    {
        $q = ['where' => [], 'or' => [], 'whereIn' => [], 'whereNotIn' => [], 'whereNot' => [], 'between' => [], 'sort' => null, 'limit' => null, 'offset' => 0];
        for ($i = mt_rand(0, 2); $i > 0; $i--) $q['where'][] = $this->randOperatorGroup();
        for ($i = mt_rand(0, 2) === 0 ? 1 : 0; $i > 0; $i--) $q['or'][] = $this->randOperatorGroup();
        if (mt_rand(0, 3) === 0) $q['whereIn'][] = ['b', ['x', 'y', false]];
        if (mt_rand(0, 3) === 0) $q['whereNotIn'][] = ['a', [0, '3']];
        if (mt_rand(0, 3) === 0) $q['whereNot'][] = ['b' => 'z'];
        if (mt_rand(0, 3) === 0) $q['between'][] = ['a', 1, '5'];
        if (mt_rand(0, 1)) $q['sort'] = [['a', 'b', 'c', 'd'][mt_rand(0, 3)], mt_rand(0, 1) ? 'asc' : 'desc'];
        if (mt_rand(0, 2) === 0) $q['limit'] = mt_rand(0, 5);
        if (mt_rand(0, 3) === 0) $q['offset'] = mt_rand(0, 3);
        return $q;
    }

    private function runQuery(\noneDB $db, string $name, array $q): array
    {
        $b = $db->query($name);
        foreach ($q['where'] as $g) $b->where($g);
        foreach ($q['or'] as $g) $b->orWhere($g);
        foreach ($q['whereIn'] as [$f, $v]) $b->whereIn($f, $v);
        foreach ($q['whereNotIn'] as [$f, $v]) $b->whereNotIn($f, $v);
        foreach ($q['whereNot'] as $g) $b->whereNot($g);
        foreach ($q['between'] as [$f, $min, $max]) $b->between($f, $min, $max);
        if ($q['sort'] !== null) $b->sort($q['sort'][0], $q['sort'][1]);
        if ($q['limit'] !== null) $b->limit($q['limit']);
        if ($q['offset'] > 0) $b->offset($q['offset']);
        return $b->get();
    }

    // ------------------------------------------------------------------
    // Driver
    // ------------------------------------------------------------------

    private function fuzz(string $name, array $config, int $ops, int $seed, bool $alternateInstances = false, array $indexes = []): void
    {
        mt_srand($seed);
        $this->model = [];
        $this->nextKey = 0;
        if ($alternateInstances) {
            \noneDB::disableStaticCache();
        }
        $instances = [$this->db($config)];
        if ($alternateInstances) {
            $instances[] = $this->db($config);
        }
        $instances[0]->insert($name, ['seed' => 1]);
        $this->model[$this->nextKey++] = ['seed' => 1];
        foreach ($indexes as $field) {
            $instances[0]->createFieldIndex($name, $field);
        }

        for ($op = 0; $op < $ops; $op++) {
            $db = $instances[$op % count($instances)];
            $kind = mt_rand(0, 9);
            $ctx = "seed=$seed op=$op kind=$kind";
            if ($kind <= 3) {
                $batch = mt_rand(0, 3) === 0 ? mt_rand(2, 6) : 1;
                $records = [];
                for ($i = 0; $i < $batch; $i++) {
                    $records[] = $this->randRecord();
                }
                $r = $db->insert($name, $batch === 1 ? $records[0] : $records);
                $this->assertSame(['n' => $batch], $r, $ctx);
                foreach ($records as $rec) {
                    $this->model[$this->nextKey++] = $rec;
                }
            } elseif ($kind <= 5) {
                $filter = $this->randFilter();
                $set = [];
                foreach (['a', 'b', 'c', 'd', 'e'] as $f) {
                    if (mt_rand(0, 2) === 0) $set[$f] = $f === 'e' ? $op : $this->randValue($f);
                }
                if (empty($set)) $set['e'] = $op;
                $expected = $this->modelSelect($filter);
                $r = $db->update($name, [$filter, ['set' => $set]]);
                $this->assertSame(['n' => count($expected)], $r, "$ctx update " . json_encode($filter));
                foreach (array_keys($expected) as $k) {
                    $this->model[$k] = array_merge($this->model[$k], $set);
                }
            } elseif ($kind <= 6) {
                $filter = $this->randFilter();
                if (empty($filter)) $filter = ['b' => 'z'];
                $expected = $this->modelSelect($filter);
                $r = $db->delete($name, $filter);
                $this->assertSame(['n' => count($expected)], $r, "$ctx delete " . json_encode($filter));
                foreach (array_keys($expected) as $k) {
                    unset($this->model[$k]);
                }
            } elseif ($kind <= 8) {
                $filter = $this->randFilter();
                $this->assertSame(self::withKeys($this->modelSelect($filter)), $db->find($name, $filter), "$ctx find " . json_encode($filter));
                $q = $this->randQuery();
                $this->assertSame($this->modelQuery($q), $this->runQuery($db, $name, $q), "$ctx query " . json_encode($q));
            } else {
                if (mt_rand(0, 3) === 0) {
                    $db->compact($name);
                }
                $this->assertSame(count($this->model), $db->count($name), "$ctx count");
            }

            if ($op % 25 === 0 || $op === $ops - 1) {
                $this->assertSame(self::withKeys($this->modelSelect([])), $db->find($name, 0), "$ctx full contents");
            }
        }

        // Independent fresh instance sees exactly the model
        \noneDB::enableStaticCache();
        \noneDB::clearStaticCache();
        $this->assertSame(self::withKeys($this->modelSelect([])), $this->db($config)->find($name, 0), "seed=$seed final");
    }

    /** @test */
    public function nonSharded(): void
    {
        $this->fuzz('fz', [], 600, 101);
    }

    /** @test */
    public function shardedWithMigrationMidRun(): void
    {
        $this->fuzz('fzs', ['shardSize' => 7], 600, 202);
    }

    /** @test */
    public function withFieldIndexes(): void
    {
        $this->fuzz('fzi', [], 500, 303, false, ['a', 'b', 'd']);
        $this->fuzz('fzis', ['shardSize' => 9], 500, 304, false, ['a', 'b']);
    }

    /** @test */
    public function withWriteBuffer(): void
    {
        $this->fuzz('fzb', ['bufferEnabled' => true], 500, 404);
        $this->fuzz('fzbs', ['bufferEnabled' => true, 'shardSize' => 8], 500, 405);
    }

    /** @test */
    public function twoInstancesWithSeparateCaches(): void
    {
        $this->fuzz('fzt', [], 500, 505, true);
        $this->fuzz('fzts', ['shardSize' => 6], 500, 506, true, ['a']);
    }

    /** @test */
    public function manySeeds(): void
    {
        for ($seed = 1000; $seed < 1012; $seed++) {
            $config = $seed % 3 === 0 ? ['shardSize' => 5 + $seed % 4] : [];
            $indexes = $seed % 2 === 0 ? ['a', 'b'] : [];
            $this->fuzz('fzm' . $seed, $config, 150, $seed, $seed % 4 === 1, $indexes);
        }
    }
}
