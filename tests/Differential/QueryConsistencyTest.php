<?php

namespace noneDB\Tests\Differential;

use noneDB\Tests\RegressionTestCase;

/**
 * Query layer, second part (plan C2, C4, C5, C6, C8, C9, C11, C12, C13, T2-5): the same question
 * gets the same answer through every API, on every PHP version.
 */
class QueryConsistencyTest extends RegressionTestCase
{
    /** @test */
    public function joinKeysDoNotTruncateFloatsAndMatchBooleansOnlyWithBooleans(): void
    {
        // C-2: array keys truncated 1.5 to 1 (and PHP 8.1+ reports a deprecation), true matched 1 and "1"
        $db = $this->db();
        $db->insert('fk', [
            ['id' => 1, 'v' => 'int 1'], ['id' => 1.5, 'v' => 'float 1.5'], ['id' => 1.2, 'v' => 'float 1.2'],
            ['id' => true, 'v' => 'true'], ['id' => '007', 'v' => 'string 007'], ['id' => 'Ada', 'v' => 'Ada'],
            ['id' => 100000000000000000, 'v' => 'int 1e17'], ['id' => '9223372036854775808', 'v' => 'big string'],
            ['id' => -0.0, 'v' => 'minus zero'],
        ]);
        $db->insert('lk', [
            ['ref' => 1], ['ref' => '1'], ['ref' => 1.0], ['ref' => 1.5], ['ref' => 1.2], ['ref' => true], ['ref' => '1.5'],
            ['ref' => 7], ['ref' => '007'], ['ref' => 'Ada'], ['ref' => 1e17], ['ref' => '9223372036854775808'], ['ref' => 0],
            ['ref' => null], ['ref' => [1]],
        ]);
        $joined = array_map(function ($r) { return $r['f']['v'] ?? null; }, $db->query('lk')->join('fk', 'ref', 'id', 'f')->get());
        $this->assertSame([
            'int 1', 'int 1', 'int 1',     // 1, "1" and 1.0 are the same key
            'float 1.5', 'float 1.2',      // not truncated to 1
            'true',                         // true only with true
            null,                           // "1.5" is not the float 1.5
            null, 'string 007',             // "007" is not 7
            'Ada', 'int 1e17', 'big string', 'minus zero',
            null, null,                     // null and arrays never join
        ], $joined);
        $this->assertSame($joined, array_map(function ($r) { return $r['f']['v'] ?? null; },
            $db->query('lk')->join('fk', 'ref', 'id', 'f')->limit(100)->get()), 'same through every path');
    }

    /** @test */
    public function noneDbMinAndMaxFollowTheSortOrder(): void
    {
        // C-4: loose < and > made the result depend on insertion order and PHP version
        $db = $this->db();
        foreach ([[5, true], [true, 5], [5, 'abc'], ['abc', 5], [50, '10'], ['10', 50], [null, 3, 'x', [1]]] as $i => $values) {
            $name = 'mm' . $i;
            $db->insert($name, array_map(function ($v) { return ['v' => $v]; }, $values));
            $this->assertSame($db->query($name)->min('v'), $db->min($name, 'v'), json_encode($values));
            $this->assertSame($db->query($name)->max('v'), $db->max($name, 'v'), json_encode($values));
            $sorted = array_column($db->sort($db->find($name, 0), 'v'), 'v');
            $present = array_values(array_filter($sorted, function ($v) { return $v !== null; }));
            $this->assertSame($present[0], $db->min($name, 'v'));
            $this->assertSame(end($present), $db->max($name, 'v'));
        }
        $this->assertSame('10', $db->max('mm4', 'v'), 'numeric strings sort after numbers');
    }

    /** @test */
    public function likeAndSearchFoldUnicodeCase(): void
    {
        // C-5/C-11: PCRE /iu missed İ↔i and SS↔ß, mb_strtolower missed ſ/s, ς/σ, SS/ß
        $db = $this->db();
        $db->insert('uc', [['n' => 'İzmir'], ['n' => 'STRASSE'], ['n' => 'ſtar'], ['n' => 'ὈΔΥΣΣΕΎΣ'], ['n' => 'Ğüneş'], ['n' => 'plain']]);
        $cases = [['izmir', 'İzmir'], ['IZMIR', 'İzmir'], ['straße', 'STRASSE'], ['star', 'ſtar'], ['ὀδυσσεύς', 'ὈΔΥΣΣΕΎΣ'], ['ğÜNEŞ', 'Ğüneş']];
        foreach ($cases as [$pattern, $expected]) {
            $this->assertSame([$expected], array_column($db->like('uc', 'n', $pattern), 'n'), "like $pattern");
            $this->assertSame([$expected], array_column($db->query('uc')->like('n', $pattern)->get(), 'n'), "builder like $pattern");
            $this->assertSame([$expected], array_column($db->query('uc')->where(['n' => ['$like' => $pattern]])->get(), 'n'), "\$like $pattern");
            $this->assertSame([$expected], array_column($db->query('uc')->search($pattern)->get(), 'n'), "search $pattern");
        }
        $this->assertSame(['İzmir'], array_column($db->like('uc', 'n', '^izm'), 'n'), 'anchors keep their meaning');
        $this->assertSame([], $db->like('uc', 'n', 'Izmirx'));
        $this->assertSame(['plain'], array_column($db->query('uc')->notLike('n', 'x')->where(['n' => ['$like' => 'pl']])->get(), 'n'));
        $this->assertSame([], $db->query('uc')->notLike('n', 'A')->where(['n' => ['$like' => 'pl']])->get());
    }

    /**
     * @test
     * @group no-mbstring
     */
    public function likeWithoutMbstringStillMatchesCaseInsensitively(): void
    {
        // C-5: without mbstring like() keeps the PCRE /iu path (better than stripos for Ğ/ğ)
        $hook = new \noneDB\Tests\InternalValue('mbstringFn');
        $hook->setValue(null, function () { return false; });
        try {
            $db = $this->db();
            $db->insert('nomb', [['n' => 'Ğüneş'], ['n' => 'ABC']]);
            $this->assertSame(['Ğüneş'], array_column($db->like('nomb', 'n', 'ğüneş'), 'n'));
            $this->assertSame(['Ğüneş'], array_column($db->query('nomb')->like('n', 'ĞÜNEŞ')->get(), 'n'));
            $this->assertSame(['ABC'], array_column($db->query('nomb')->search('abc')->get(), 'n'));
            // QUERY.md: without mbstring like() misses ß/ss and İ/i, search() folds only ASCII letters
            $db->insert('nomb', [['n' => 'STRASSE'], ['n' => 'İzmir']]);
            $this->assertSame([], $db->like('nomb', 'n', 'straße'));
            $this->assertSame([], $db->like('nomb', 'n', 'izmir'));
            $this->assertSame([], $db->query('nomb')->search('ğüneş')->get());
            $this->assertSame(['STRASSE'], array_column($db->query('nomb')->search('strasse')->get(), 'n'));
        } finally {
            $hook->setValue(null, null);
        }
    }

    /** @test */
    public function builderKeyFilterIsTheSameInEveryClause(): void
    {
        // C-6: only a single plain where() went through find(); elsewhere "1" didn't match key 1
        $db = $this->db();
        $db->insert('kf', [['n' => 'a'], ['n' => 'b'], ['n' => 'c']]);
        $this->assertSame([1], array_column($db->query('kf')->where(['key' => '1'])->get(), 'key'));
        $this->assertSame([1], array_column($db->query('kf')->where(['key' => '1'])->where(['n' => 'b'])->get(), 'key'));
        $this->assertSame([0, 2], array_column($db->query('kf')->where(['n' => 'a'])->orWhere(['key' => ['2']])->get(), 'key'));
        $this->assertSame([0, 1], array_column($db->query('kf')->where(['key' => [0, '1']])->where(['n' => ['$ne' => 'z']])->get(), 'key'));
        $this->assertSame([0, 2], array_column($db->query('kf')->whereNot(['key' => '1'])->get(), 'key'));
        $this->assertSame([], $db->query('kf')->where(['key' => "1\n"])->get(), 'C-12: "1\n" is not key 1');
        $this->assertSame([], $db->find('kf', ['key' => "1\n"]), 'C-12');
    }

    /** @test */
    public function smallInconsistencies(): void
    {
        // C-8
        $db = $this->db();
        $db->insert('sm', [['p' => 2, 'n' => 'a', 'flag' => true], ['p' => 3, 'n' => 'b', 'flag' => false], ['p' => 4, 'n' => 'a1']]);

        // count() with filters it can't use: 0 (TypeError before)
        $this->assertSame(0, $db->count('sm', 'x'));
        $this->assertSame(0, $db->count('sm', 1.5));
        $this->assertSame(0, $db->count('sm', false));
        $this->assertSame(3, $db->count('sm', null));
        // noneDB::like() ignores booleans (true matched "1") and refuses non-string patterns
        $this->assertSame([], $db->like('sm', 'flag', '1'));
        $this->assertFalse($db->like('sm', 'n', ['a']));
        // limit(0) means nothing
        $this->assertNull($db->query('sm')->limit(0)->first());
        $this->assertFalse($db->query('sm')->limit(0)->exists());
        // $regex: an escaped backslash before "~" stays a backslash
        $db->insert('rx', [['s' => 'a\\~b'], ['s' => 'a~b']]);
        $this->assertSame(['a\\~b'], array_column($db->query('rx')->where(['s' => ['$regex' => 'a\\\\~b']])->get(), 's'));
        $this->assertSame(['a~b'], array_column($db->query('rx')->where(['s' => ['$regex' => '^a~b$']])->get(), 's'));
        // Aggregates read the fields, not the projection
        $this->assertSame(9.0, $db->query('sm')->select(['n'])->sum('p'));
        $this->assertSame(3.0, $db->query('sm')->select(['n'])->avg('p'));
        $this->assertSame(2, $db->query('sm')->select(['n'])->min('p'));
        $this->assertSame(4, $db->query('sm')->except(['p'])->max('p'));
        $this->assertSame([2, 3, 4], $db->query('sm')->select(['n'])->distinct('p'));
    }

    /** @test */
    public function searchDoesNotLookIntoTheAddedDistance(): void
    {
        // C-8: without a field list search() also searched _distance added by a spatial filter
        $db = $this->db();
        $db->insert('sd', [['name' => 'one', 'loc' => ['type' => 'Point', 'coordinates' => [0, 0]]],
                           ['name' => '1234', 'loc' => ['type' => 'Point', 'coordinates' => [10, 10]]]]);
        $rows = $db->query('sd')->withinDistance('loc', 0.0, 0.0, 100000)->withDistance('loc', 0.0, 0.0)->search('0')->get();
        $this->assertSame([], $rows, '"0" is only in the computed _distance (0.0)');
    }

    /** @test */
    public function groupKeysAreExactValues(): void
    {
        // C-9: json_encode() keys depend on serialize_precision; -0.0 and 0.0 were two groups
        $before = ini_get('serialize_precision');
        try {
            $db = $this->db();
            $db->insert('gk', [['v' => 28.980345], ['v' => 28.98], ['v' => -0.0], ['v' => 0.0], ['v' => 0], ['v' => '0']]);
            ini_set('serialize_precision', '5');
            $groups = $db->query('gk')->groupBy('v')->get();
            $this->assertSame([28.980345, 28.98, -0.0, 0, '0'], array_column($groups, '_group'));
            $this->assertSame([1, 1, 2, 1, 1], array_column($groups, '_count'));
        } finally {
            ini_set('serialize_precision', (string)$before);
        }
    }

    /** @test */
    public function groupByWithSelectProjectsTheItems(): void
    {
        // C-13: the projection was applied to the group rows: every group became []
        $db = $this->db();
        $db->insert('gs', [['c' => 'x', 'name' => 'a', 'p' => 1], ['c' => 'y', 'name' => 'b', 'p' => 2], ['c' => 'x', 'name' => 'c', 'p' => 3]]);
        $groups = $db->query('gs')->groupBy('c')->select(['name'])->get();
        $this->assertSame(['x', 'y'], array_column($groups, '_group'));
        $this->assertSame([2, 1], array_column($groups, '_count'));
        $this->assertSame([['name' => 'a', 'key' => 0], ['name' => 'c', 'key' => 2]], $groups[0]['_items']);
    }

    /** @test */
    public function aggregateMethodsTreatFiltersLikeFind(): void
    {
        // T2-5: one rule for the filter parameter of every method that has one
        $db = $this->db();
        $db->insert('ag', [['c' => 'x', 'p' => 1], ['c' => 'y', 'p' => 2], ['c' => 'x', 'p' => 3]]);
        foreach ([0, null, [], ['c' => 'x']] as $filter) {
            $records = $db->find('ag', $filter);
            $values = array_column($records, 'p');
            $this->assertSame((float)array_sum($values), (float)$db->sum('ag', 'p', $filter), json_encode($filter));
            $this->assertSame(min($values), $db->min('ag', 'p', $filter), json_encode($filter));
            $this->assertSame(max($values), $db->max('ag', 'p', $filter), json_encode($filter));
            $this->assertSame($records[0], $db->first('ag', $filter), json_encode($filter));
            $this->assertSame(end($records), $db->last('ag', $filter), json_encode($filter));
            $this->assertTrue($db->exists('ag', $filter === 0 || $filter === null ? [] : $filter), json_encode($filter));
        }
        foreach (['x', 1.5, false] as $invalid) {
            $this->assertFalse($db->find('ag', $invalid));
            $this->assertSame(0, $db->count('ag', $invalid));
            foreach (['sum' => $db->sum('ag', 'p', $invalid), 'avg' => $db->avg('ag', 'p', $invalid)] as $method => $zero) {
                $this->assertTrue($zero === 0 || $zero === 0.0, "$method with an invalid filter: the number 0, got " . var_export($zero, true));
            }
            $this->assertFalse($db->exists('ag', $invalid));
            $this->assertFalse($db->between('ag', 'p', 0, 5, $invalid));
            $this->assertNull($db->min('ag', 'p', $invalid));
            $this->assertNull($db->max('ag', 'p', $invalid));
            $this->assertNull($db->first('ag', $invalid));
            $this->assertNull($db->last('ag', $invalid));
        }
    }
}
