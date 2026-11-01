<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Query layer (plan C1, C3, C7, C10)
 */
class QueryHardeningTest extends RegressionTestCase
{
    /** @test */
    public function distinctKeepsStrictIdentityAndFirstSeenOrder(): void
    {
        // C-1: same result as the in_array(strict) loop it replaces
        $db = $this->db();
        $values = [1, '1', 1.0, 0, -0.0, 0.0, true, false, [1, 'x'], [1, 'x'], ['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1], [1.0], [1], null, '', 'x', 'x'];
        $db->insert('dist', array_map(function ($v) { return ['v' => $v]; }, $values));
        $db->insert('dist', ['other' => 1]);
        $expected = [];
        foreach ($values as $v) {
            if ($v !== null && !in_array($v, $expected, true)) {
                $expected[] = $v;
            }
        }
        $this->assertSame($expected, $db->distinct('dist', 'v'));
        $this->assertSame($expected, $db->query('dist')->distinct('v'));

        // Encoding must not collide (length-prefixed keys)
        $collide = [
            ['x1' => 'ab', 'y' => 'AAAAsz12:sBBBBBBBBBBB'],
            ['x' => 'absy22:sAAAA', 'z' => 'BBBBBBBBBBB'],
        ];
        $db->insert('coll', array_map(function ($v) { return ['v' => $v]; }, $collide));
        $this->assertSame($collide, $db->distinct('coll', 'v'));
        $this->assertSame($collide, $db->query('coll')->distinct('v'));
        $this->assertCount(2, $db->query('coll')->groupBy('v')->get(), 'groups use the same identity');
    }

    /**
     * @test
     * A timing over 100k records in a process of its own: neither its time nor its memory depends on what the
     * tests before it left in the process
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function distinctIsLinear(): void
    {
        // C-1: in_array over the values seen so far made 100k distinct values take minutes. Measured through
        // distinct(), which reads the records segment by segment and keeps a hash set of the values seen
        $db = $this->db(['shardSize' => 10000]);
        $time = function (int $n) use ($db) {
            $name = 'distinct' . $n;
            for ($from = 0; $from < $n; $from += 10000) {
                $db->insert($name, array_map(function ($i) { return ['v' => 'value-' . $i]; }, range($from, $from + 9999)));
            }
            $best = INF;
            for ($run = 0; $run < 3; $run++) {
                $t = hrtime(true);
                $this->assertCount($n, $db->distinct($name, 'v'));
                $best = min($best, hrtime(true) - $t);
            }
            return $best;
        };
        $ratio = $time(100000) / max(1, $time(10000));
        $this->assertLessThan(30, $ratio, 'linear: ~10, quadratic: ~100 (relative to itself, no absolute time)');
    }

    /** @test */
    public function sortOrderIsTotalForMixedStrings(): void
    {
        // C-3: "9" < "10" < "1a" < "9" was a cycle: the result depended on the insertion order
        $db = $this->db();
        $values = ['9', '10', '1a', '2', '20', 'alpha', 5, 9.5, ' 7', true, null, ['x']];
        $expected = null;
        mt_srand(5);
        for ($i = 0; $i < 60; $i++) {
            $shuffled = $values;
            shuffle($shuffled);
            $rows = array_map(function ($v) { return ['v' => $v]; }, $shuffled);
            $sorted = array_column($db->sort($rows, 'v'), 'v');
            $expected = $expected ?? $sorted;
            $this->assertSame($expected, $sorted, 'independent of the input order');
        }
        // null < bool < numbers < numeric strings by number < other strings < arrays
        $this->assertSame([null, true, 5, 9.5, '2', ' 7', '9', '10', '20', '1a', 'alpha', ['x']], $expected);
        // Equal sort keys ("7 " and " 7" are both 7) keep their input order (stable)
        $this->assertSame(['7 ', ' 7'], array_column($db->sort([['v' => '7 '], ['v' => ' 7']], 'v'), 'v'));
        $this->assertSame([' 7', '7 '], array_column($db->sort([['v' => ' 7'], ['v' => '7 ']], 'v'), 'v'));

        $db->insert('mx', array_map(function ($v) { return ['v' => $v]; }, ['9', '10', '1a', 'alpha', '2']));
        $this->assertSame('alpha', $db->query('mx')->max('v'));
        $this->assertSame('2', $db->query('mx')->min('v'));
        $this->assertSame(['2', '9', '10', '1a', 'alpha'], array_column($db->query('mx')->sort('v')->get(), 'v'));
        // sort + limit + update hits exactly the records the sort shows
        $db->query('mx')->sort('v', 'desc')->limit(2)->update(['top' => true]);
        $this->assertSame(['1a', 'alpha'], array_column($db->find('mx', ['top' => true]), 'v'));
    }

    /** @test */
    public function whereNotAppliesOperators(): void
    {
        // C-7: operator specs were compared with ===, so nothing was excluded
        $db = $this->db();
        $db->insert('wn', [['age' => 10, 'r' => 'a'], ['age' => 20, 'r' => 'b'], ['age' => 70, 'r' => 'a'], ['r' => 'c']]);
        $this->assertSame([10, 70, null], array_map(function ($r) { return $r['age'] ?? null; },
            $db->query('wn')->whereNot(['age' => ['$gte' => 18, '$lt' => 65]])->get()), 'AND within the field');
        $this->assertSame(['c'], array_column($db->query('wn')->whereNot(['r' => 'a', 'age' => ['$gt' => 15]])->get(), 'r'),
            'any matching field excludes');
        try {
            $db->query('wn')->whereNot(['age' => ['$between' => 1]]);
            $this->fail('unknown operator');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith("noneDB: unknown operator '\$between'", $e->getMessage());
        }
        // A builder delete removes exactly what the query shows (the record without "age" is not "age < 65")
        $shown = array_column($db->query('wn')->whereNot(['age' => ['$lt' => 65]])->get(), 'key');
        $db->query('wn')->whereNot(['age' => ['$lt' => 65]])->delete();
        $this->assertSame([2, 3], $shown);
        $this->assertSame([0, 1], array_column($db->find('wn', 0), 'key'));
    }

    /** @test */
    public function nanMatchesNothing(): void
    {
        // C-10: NAN <=> x is 1 both ways, so $gt NAN matched every number
        $db = $this->db();
        $db->insert('nan', [['p' => 1], ['p' => 5.5], ['p' => '7'], ['p' => 'x']]);
        foreach (['$gt', '$gte', '$lt', '$lte'] as $op) {
            $this->assertSame([], $db->query('nan')->where(['p' => [$op => NAN]])->get(), $op);
        }
        $this->assertSame([], $db->query('nan')->between('p', NAN, 10)->get());
        $this->assertSame([], $db->between('nan', 'p', NAN, 10));
        $db->query('nan')->where(['p' => ['$gt' => NAN]])->delete();
        $this->assertCount(4, $db->find('nan', 0), 'a delete with a NAN bound deletes nothing');
    }
}
