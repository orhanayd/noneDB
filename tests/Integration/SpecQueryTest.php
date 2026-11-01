<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * The query API checked against its documentation (docs/QUERY.md, docs/API.md, CHANGES.md).
 *
 * Expected values come from the reference model below, written from the documented rules (how values
 * compare, the sort order, like/search, key filters, grouping, joins), or are derived by hand in the
 * test. The data-driven tests run on a plain database, a sharded one, and both with a field index: the
 * planner reads different records in each case (index candidates, shard skipping, batches), while the
 * documented result must be the same.
 */
class SpecQueryTest extends RegressionTestCase
{
    /** Marks a record without the field */
    private const MISSING = "\0missing";
    private const WS = " \t\n\r\v\f";

    // ======================================================================
    // Reference model (docs/QUERY.md "How values compare", "sort()", like, key filters)
    // ======================================================================

    /** A numeric string as the sort rules define it: trailing whitespace allowed on every PHP version */
    private static function refNumericString($v): bool
    {
        return is_string($v) && is_numeric(rtrim($v, self::WS));
    }

    private static function refIsNumber($v): bool
    {
        return is_int($v) || is_float($v) || self::refNumericString($v);
    }

    private static function refToNumber($v)
    {
        return is_string($v) ? rtrim($v, self::WS) + 0 : $v;
    }

    /**
     * $gt/$gte/$lt/$lte/between(): numbers and numeric strings numerically, other strings lexically;
     * null, booleans, arrays, NAN and a number against a non-numeric string are not comparable (null)
     */
    private static function refCompare($a, $b): ?int
    {
        foreach ([$a, $b] as $v) {
            if ($v === null || is_bool($v) || is_array($v) || (is_float($v) && is_nan($v))) {
                return null;
            }
        }
        if (self::refIsNumber($a) && self::refIsNumber($b)) {
            return self::refToNumber($a) <=> self::refToNumber($b);
        }
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }
        return null;
    }

    /** Sort kinds: missing/null < booleans < numbers < numeric strings < other strings < arrays */
    private static function refSortKind($v): int
    {
        if ($v === null) return 0;
        if (is_bool($v)) return 1;
        if (is_int($v) || is_float($v)) return 2;
        if (self::refNumericString($v)) return 3;
        if (is_string($v)) return 4;
        return 5;
    }

    private static function refSortCompare($a, $b): int
    {
        $ka = self::refSortKind($a);
        $kb = self::refSortKind($b);
        if ($ka !== $kb) return $ka <=> $kb;
        switch ($ka) {
            case 0: return 0;
            case 1: return (int)$a <=> (int)$b;               // false < true
            case 2: return $a <=> $b;                         // int and float together
            case 3: return self::refToNumber($a) <=> self::refToNumber($b);
            case 4: return strcmp($a, $b) <=> 0;              // byte order
            default: return strcmp(json_encode($a), json_encode($b)) <=> 0;   // arrays by their JSON
        }
    }

    /**
     * Stable sort of records by [[field, 'asc'|'desc'], ...]; ties keep the input (key) order
     */
    private static function refSort(array $records, array $keys): array
    {
        $indexed = [];
        foreach (array_values($records) as $pos => $r) {
            $indexed[] = [$pos, $r];
        }
        usort($indexed, function ($x, $y) use ($keys) {
            foreach ($keys as [$field, $order]) {
                $c = self::refSortCompare(array_key_exists($field, $x[1]) ? $x[1][$field] : null,
                    array_key_exists($field, $y[1]) ? $y[1][$field] : null);
                if ($c !== 0) {
                    return $order === 'desc' ? -$c : $c;
                }
            }
            return $x[0] <=> $y[0];
        });
        return array_column($indexed, 1);
    }

    /** like(): literal, case-insensitive; a leading ^ anchors the start, a trailing $ the end (ASCII data) */
    private static function refLike(string $subject, string $pattern): bool
    {
        $start = $pattern !== '' && $pattern[0] === '^';
        $rest = $start ? (string)substr($pattern, 1) : $pattern;
        $end = $rest !== '' && substr($rest, -1) === '$';
        $body = strtolower($end ? (string)substr($rest, 0, -1) : $rest);
        $s = strtolower($subject);
        if ($start && $end) return $s === $body;
        if ($start) return strncmp($s, $body, strlen($body)) === 0;
        if ($end) return $body === '' || (strlen($s) >= strlen($body) && substr($s, -strlen($body)) === $body);
        return $body === '' || strpos($s, $body) !== false;
    }

    /** like/$like/$regex only look at strings and numbers */
    private static function refLikeable($v): bool
    {
        return is_string($v) || is_int($v) || is_float($v);
    }

    private static function refOperator(array $record, string $field, string $op, $operand): bool
    {
        $has = array_key_exists($field, $record);
        $v = $has ? $record[$field] : null;
        switch ($op) {
            case '$gt':  return $has && ($c = self::refCompare($v, $operand)) !== null && $c > 0;
            case '$gte': return $has && ($c = self::refCompare($v, $operand)) !== null && $c >= 0;
            case '$lt':  return $has && ($c = self::refCompare($v, $operand)) !== null && $c < 0;
            case '$lte': return $has && ($c = self::refCompare($v, $operand)) !== null && $c <= 0;
            case '$eq':  return $has && $v === $operand;
            case '$ne':  return !$has || $v !== $operand;
            case '$in':  return $has && in_array($v, $operand, true);
            case '$nin': return !$has || !in_array($v, $operand, true);
            case '$exists': return $operand ? $has : !$has;
            case '$like': return $has && self::refLikeable($v) && self::refLike((string)$v, (string)$operand);
            case '$regex': // the pattern body as given (no delimiters: "~" is a literal character)
                return $has && self::refLikeable($v) && preg_match("\x01" . $operand . "\x01iu", (string)$v) === 1;
            case '$contains':
                if (!$has) return false;
                if (is_array($v)) return in_array($operand, $v, true);
                if (is_string($v) && is_scalar($operand) && !is_bool($operand)) {
                    return (string)$operand === '' || strpos($v, (string)$operand) !== false;
                }
                return false;
        }
        throw new \LogicException("reference model: $op");
    }

    private static function refIsOperatorSpec($value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }
        foreach (array_keys($value) as $k) {
            if (!is_string($k) || $k === '' || $k[0] !== '$') {
                return false;
            }
        }
        return true;
    }

    /** Key filter: integers and canonical digit strings, a single one or a list (docs/API.md find()) */
    private static function refKeyList($value): array
    {
        $keys = [];
        foreach (is_array($value) ? $value : [$value] as $v) {
            if (is_int($v) && $v >= 0) {
                $keys[$v] = $v;
            } elseif (is_string($v) && preg_match('/\A(0|[1-9][0-9]*)\z/', $v) === 1) {
                $keys[(int)$v] = (int)$v;
            }
        }
        return array_values($keys);
    }

    /** One field condition of where()/orWhere()/whereNot() */
    private static function refCondition(array $record, string $field, $value): bool
    {
        if (self::refIsOperatorSpec($value)) {
            foreach ($value as $op => $operand) {
                if (!self::refOperator($record, $field, $op, $operand)) {
                    return false;
                }
            }
            return true;
        }
        if ($field === 'key') {
            return in_array($record['key'], self::refKeyList($value), true);
        }
        return array_key_exists($field, $record) && $record[$field] === $value;
    }

    private static function refGroup(array $record, array $group): bool
    {
        foreach ($group as $field => $value) {
            if (!self::refCondition($record, (string)$field, $value)) {
                return false;
            }
        }
        return true;
    }

    /** where() groups all match OR any orWhere() group matches; without where() only orWhere() decides */
    private static function refWhere(array $records, array $where, array $orWhere = []): array
    {
        $out = [];
        foreach ($records as $r) {
            $all = !empty($where);
            foreach ($where as $g) {
                if (!self::refGroup($r, $g)) {
                    $all = false;
                    break;
                }
            }
            $any = false;
            foreach ($orWhere as $g) {
                if (self::refGroup($r, $g)) {
                    $any = true;
                    break;
                }
            }
            if ($all || $any || (empty($where) && empty($orWhere))) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** Records as stored and read back: the given rows with "key" added (keys 0..n-1 in insert order) */
    private static function withKeys(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $k => $row) {
            $out[] = $row + ['key' => $k];
        }
        return $out;
    }

    /** noneDB::sum()/avg() return an int or a float (docblock): a number equal to $expected, never a string or null */
    private function assertNumber($expected, $actual, string $message = ''): void
    {
        $this->assertTrue((is_int($actual) || is_float($actual)) && (float)$actual === (float)$expected,
            $message . ': ' . var_export($actual, true) . ' is not the number ' . var_export($expected, true));
    }

    private static function keysOf(array $records): array
    {
        return array_map(function ($r) { return $r['key']; }, array_values($records));
    }

    // ======================================================================
    // Fixtures
    // ======================================================================

    /** Values of every kind the rules distinguish (trailing-whitespace strings: see their own test) */
    private static function mixedValues(): array
    {
        return [
            5, 30, 0, -1, 5.5, 5.0, 0.5,
            '5', '30', ' 7', '1e1', '.5', '-0',
            'abc', 'ABC', 'B', 'b', '', '1a', '0x1A', 'a~b', 'x.y',
            null, true, false,
            [1], ['a', 'abc'], ['a' => 1], [],
            self::MISSING,
        ];
    }

    private static function mixedRows(): array
    {
        $rows = [];
        foreach (self::mixedValues() as $i => $v) {
            $row = ['i' => $i, 'g' => $i % 3];
            if ($v !== self::MISSING) {
                $row['v'] = $v;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The same rows in four databases: plain, plain with a field index on $indexField, sharded (shards of
     * 4 records), sharded with the index
     * @return array name => \noneDB
     */
    private function setups(array $rows, string $indexField = 'v', string $prefix = 'm'): array
    {
        $setups = [];
        foreach (['plain' => false, 'plainix' => true, 'shard' => false, 'shardix' => true] as $variant => $indexed) {
            $sharded = strpos($variant, 'shard') === 0;
            $db = $this->db($sharded ? ['shardSize' => 4] : ['shardingEnabled' => false]);
            $name = $prefix . $variant;
            $this->assertSame(['n' => count($rows)], $db->insert($name, $rows));
            if ($indexed) {
                $this->assertTrue($db->createFieldIndex($name, $indexField)['success']);
            }
            $this->assertSame($sharded, $db->getShardInfo($name)['sharded'], "$name sharded");
            $setups[$name] = $db;
        }
        return $setups;
    }

    /** Operands for every operator: every kind of value, both sides of each rule */
    private static function operatorCases(): array
    {
        $cases = [];
        foreach (['$gt', '$gte', '$lt', '$lte'] as $op) {
            foreach ([5, 5.5, '5', '30', '1e1', 'abc', 'B', '', 0, -1, null, true, false, [1], NAN, '0x1A', ' 7', '-0'] as $operand) {
                $cases[] = [$op, $operand];
            }
        }
        foreach (['$eq', '$ne'] as $op) {
            foreach ([5, '5', 5.0, 0, null, true, false, [1], [], 'abc', 'ABC', ['a' => 1], ['a', 'abc']] as $operand) {
                $cases[] = [$op, $operand];
            }
        }
        $lists = [[5, 'abc'], [null], [true, false], [[1]], [], ['5', 5.0], [0.5, '.5'],
            [1, 2, 3, 4, 5, 6, 7, 8, 'abc', null],                       // more than 8 values: a hash set
            [5.0, 30, '30', 'b', [], -1, 0.5, ' 7', 'x', 'y', ['a' => 1]]];
        foreach (['$in', '$nin'] as $op) {
            foreach ($lists as $operand) {
                $cases[] = [$op, $operand];
            }
        }
        $cases[] = ['$exists', true];
        $cases[] = ['$exists', false];
        foreach (['a', 'A', '^a', 'c$', '^abc$', '5', '^-', '', '^', '$', '^$', 'b', '.', '1e', '~', 'x.y', '^x.y$', '0X', 5, 0.5] as $operand) {
            $cases[] = ['$like', $operand];
        }
        foreach (['^a', 'B$', '[0-9]', '^$', 'a|5', '\d+', '^-?\d+(\.\d+)?$', 'a~b', '\.', 'A~'] as $operand) {
            $cases[] = ['$regex', $operand];
        }
        foreach (['a', 'b', '', 1, '1', true, [1], 5, 'abc', 'A', 'y', null] as $operand) {
            $cases[] = ['$contains', $operand];
        }
        return $cases;
    }

    // ======================================================================
    // Operators and filter methods (QUERY.md "Comparison Operators", "Filter Methods")
    // ======================================================================

    /** @test every operator against every kind of value, through where(), orWhere(), whereNot(), count(), exists(), first() */
    public function everyOperatorMatchesTheReferenceModelOnEveryStorageLayout(): void
    {
        $rows = self::mixedRows();
        $records = self::withKeys($rows);
        foreach ($this->setups($rows) as $name => $db) {
            foreach (self::operatorCases() as [$op, $operand]) {
                $spec = ['v' => [$op => $operand]];
                $label = $name . ' ' . $op . ' ' . var_export($operand, true);
                $expected = self::keysOf(self::refWhere($records, [$spec]));
                $this->assertSame($expected, self::keysOf($db->query($name)->where($spec)->get()), "where $label");
                $this->assertSame($expected, self::keysOf($db->query($name)->orWhere($spec)->get()), "orWhere $label");
                $this->assertSame(count($expected), $db->query($name)->where($spec)->count(), "count $label");
                $this->assertSame($expected !== [], $db->query($name)->where($spec)->exists(), "exists $label");
                $first = $db->query($name)->where($spec)->first();
                $this->assertSame($expected === [] ? null : $expected[0], $first === null ? null : $first['key'], "first $label");
                // whereNot(): a record is excluded when the field's operators all match: the complement
                $complement = array_values(array_diff(self::keysOf($records), $expected));
                $this->assertSame($complement, self::keysOf($db->query($name)->whereNot($spec)->get()), "whereNot $label");
            }
        }
    }

    /** @test plain values compare with ===; arrays as a whole; find() and where() agree */
    public function plainValuesMatchStrictlyInFindAndWhere(): void
    {
        $rows = self::mixedRows();
        $records = self::withKeys($rows);
        foreach ($this->setups($rows) as $name => $db) {
            foreach ([5, '5', 5.0, 0, '', null, true, false, [1], [], ['a' => 1], ['a', 'abc'], 'abc', 'ABC', 0.5, '.5', 'nothing'] as $value) {
                $label = $name . ' ' . var_export($value, true);
                $expected = self::keysOf(self::refWhere($records, [['v' => $value]]));
                $this->assertSame($expected, self::keysOf($db->query($name)->where(['v' => $value])->get()), "where $label");
                $this->assertSame($expected, self::keysOf($db->find($name, ['v' => $value])), "find $label");
                $this->assertSame(count($expected), $db->count($name, ['v' => $value]), "noneDB::count $label");
                $this->assertSame($expected !== [], $db->exists($name, ['v' => $value]), "noneDB::exists $label");
                $complement = array_values(array_diff(self::keysOf($records), $expected));
                $this->assertSame($complement, self::keysOf($db->query($name)->whereNot(['v' => $value])->get()), "whereNot $label");
            }
            // The documented examples: '25' is not 25, null matches only an existing null
            $this->assertSame([22], self::keysOf($db->find($name, ['v' => null])), "$name: a missing field is not null");
        }
    }

    /** @test whereIn()/whereNotIn() are strict membership: whereIn drops a missing field, whereNotIn keeps it (like $in/$nin) */
    public function whereInAndWhereNotInAreStrictMembership(): void
    {
        $rows = self::mixedRows();
        $records = self::withKeys($rows);
        $lists = [[5, 'abc'], [null], [true], [[1]], [], ['5', 5.0], [1, 2, 3, 4, 5, 6, 7, 8, 'abc', null],
            [5.0, 30, '30', 'b', [], -1, 0.5, ' 7', 'x', 'y', ['a' => 1]], [5, 5, 5]];
        foreach ($this->setups($rows) as $name => $db) {
            foreach ($lists as $list) {
                $label = $name . ' ' . json_encode($list);
                $in = self::keysOf(self::refWhere($records, [['v' => ['$in' => $list]]]));
                $nin = self::keysOf(self::refWhere($records, [['v' => ['$nin' => $list]]]));
                $this->assertSame($in, self::keysOf($db->query($name)->whereIn('v', $list)->get()), "whereIn $label");
                $this->assertSame($nin, self::keysOf($db->query($name)->whereNotIn('v', $list)->get()), "whereNotIn $label");
                $this->assertSame(count($in), $db->query($name)->whereIn('v', $list)->count(), "count whereIn $label");
            }
            // The record without "v" is in every whereNotIn() result and in no whereIn() result
            $this->assertContains(29, self::keysOf($db->query($name)->whereNotIn('v', [5])->get()));
            $this->assertNotContains(29, self::keysOf($db->query($name)->whereIn('v', [null])->get()));
        }
    }

    /** @test like(), notLike(), $like and noneDB::like() agree; notLike() is the exact complement of like() */
    public function likeMethodsAgreeAndNotLikeIsTheComplement(): void
    {
        $rows = self::mixedRows();
        $records = self::withKeys($rows);
        foreach ($this->setups($rows) as $name => $db) {
            foreach (['a', 'A', '^a', 'c$', '^abc$', '5', '^-', '', '^', '$', '^$', 'b', '.', '1e', '~', 'x.y', '^.', '0X', '^1a$'] as $pattern) {
                $label = "$name like " . var_export($pattern, true);
                $expected = self::keysOf(self::refWhere($records, [['v' => ['$like' => $pattern]]]));
                $this->assertSame($expected, self::keysOf($db->query($name)->like('v', $pattern)->get()), $label);
                $this->assertSame($expected, self::keysOf($db->like($name, 'v', $pattern)), "noneDB::$label");
                $this->assertSame(array_values(array_diff(self::keysOf($records), $expected)),
                    self::keysOf($db->query($name)->notLike('v', $pattern)->get()), "notLike: $label");
            }
            // Booleans, null, arrays and missing fields never match, whatever the pattern ("" matches every string and number)
            $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21],
                self::keysOf($db->query($name)->like('v', '')->get()), "$name: like('') matches strings and numbers only");
            $this->assertSame([22, 23, 24, 25, 26, 27, 28, 29], self::keysOf($db->query($name)->notLike('v', '')->get()));
        }
    }

    /** @test between() is inclusive and type-aware; notBetween() returns every other record; noneDB::between() agrees */
    public function betweenAndNotBetweenFollowTheComparisonRules(): void
    {
        $rows = self::mixedRows();
        $records = self::withKeys($rows);
        $ranges = [[1, 10], ['1', '10'], [5, 5], ['5', 5.0], ['a', 'c'], ['A', 'b'], [0, 'z'], [null, 10], [-1, 5.5],
            [10, 1], [NAN, 10], [0, INF], ['', 'zzz'], [true, 100], [-1e9, 1e9], ['0', '9']];
        foreach ($this->setups($rows) as $name => $db) {
            foreach ($ranges as [$min, $max]) {
                $label = $name . ' ' . var_export($min, true) . '..' . var_export($max, true);
                $expected = [];
                foreach ($records as $r) {
                    if (!array_key_exists('v', $r)) continue;
                    $lo = self::refCompare($r['v'], $min);
                    $hi = self::refCompare($r['v'], $max);
                    if ($lo !== null && $hi !== null && $lo >= 0 && $hi <= 0) {
                        $expected[] = $r['key'];
                    }
                }
                $this->assertSame($expected, self::keysOf($db->query($name)->between('v', $min, $max)->get()), "between $label");
                $this->assertSame($expected, self::keysOf($db->between($name, 'v', $min, $max)), "noneDB::between $label");
                $this->assertSame($expected, self::keysOf($db->query($name)->where(['v' => ['$gte' => $min, '$lte' => $max]])->get()), "\$gte+\$lte $label");
                $this->assertSame(array_values(array_diff(self::keysOf($records), $expected)),
                    self::keysOf($db->query($name)->notBetween('v', $min, $max)->get()), "notBetween $label");
            }
            // The extra filter of noneDB::between() is a find() filter; an invalid one returns false (API.md)
            // g = 0: keys 0 (5), 3 (-1), 6 (0.5), 9 ('1e1' = 10), 12 ('-0'), 15, 18, 21, 24, 27 (no numbers)
            $this->assertSame([0, 9], self::keysOf($db->between($name, 'v', 1, 10, ['g' => 0])), "$name between + filter");
            $this->assertFalse($db->between($name, 'v', 1, 10, 'not a filter'), "$name between with an invalid filter");
        }
    }

    // ======================================================================
    // Sorting (QUERY.md "sort()", "Top-k"; API.md sort())
    // ======================================================================

    /** @test sort() follows the documented total order on every kind of value; ties keep key order, also with desc */
    public function sortFollowsTheTotalOrderWithStableTies(): void
    {
        $rows = self::mixedRows();
        $records = self::withKeys($rows);
        $orders = [[['v', 'asc']], [['v', 'desc']], [['g', 'asc'], ['v', 'desc']], [['g', 'desc'], ['v', 'asc']],
            [['nothere', 'asc']], [['nothere', 'desc']], [['g', 'desc']]];
        foreach ($this->setups($rows) as $name => $db) {
            foreach ($orders as $keys) {
                $q = $db->query($name);
                foreach ($keys as [$field, $order]) {
                    $q->sort($field, $order);
                }
                $this->assertSame(self::keysOf(self::refSort($records, $keys)), self::keysOf($q->get()), $name . ' ' . json_encode($keys));
            }
            $q = $db->query($name)->orderBy('v', 'DESC');
            $this->assertSame(self::keysOf(self::refSort($records, [['v', 'desc']])), self::keysOf($q->get()), "$name orderBy() alias, DESC in capitals");
            // noneDB::sort() on find() results
            $this->assertSame(self::keysOf(self::refSort($records, [['v', 'desc']])), self::keysOf($db->sort($db->find($name, 0), 'v', 'desc')), "$name noneDB::sort desc");
            $this->assertSame(self::keysOf(self::refSort($records, [['v', 'asc']])), self::keysOf($db->sort($db->find($name, 0), 'v')), "$name noneDB::sort default asc");
        }
    }

    /** @test the documented examples of the order, derived by hand (QUERY.md: "9" < "10" < "1e3"; "1a" < "Z" < "a") */
    public function sortOrderExamplesFromTheDocumentation(): void
    {
        $db = $this->db();
        // Inserted in a scrambled order: keys 0..13
        $values = ['a', [1], '1e3', true, 2.5, null, 'Z', '10', false, -1, '9', '1a', 0, self::MISSING, '9 '];
        $db->insert('ex', array_map(function ($v) { return $v === self::MISSING ? ['x' => 1] : ['v' => $v]; }, $values));
        $asc = [5, 13, 8, 3, 9, 12, 4, 10, 14, 7, 2, 11, 6, 0, 1];   // null, missing, false, true, -1, 0, 2.5, "9", "9 ", "10", "1e3", "1a", "Z", "a", [1]
        $this->assertSame($asc, self::keysOf($db->query('ex')->sort('v')->get()));
        // desc reverses the order; equal values ("9" and "9 " are both 9; null and missing) keep key order
        $desc = [1, 0, 6, 11, 2, 7, 10, 14, 4, 12, 9, 3, 8, 5, 13];
        $this->assertSame($desc, self::keysOf($db->query('ex')->sort('v', 'desc')->get()));
        $this->assertSame($desc, self::keysOf($db->sort($db->find('ex', 0), 'v', 'desc')));
        $this->assertSame($asc, self::keysOf($db->sort($db->find('ex', 0), 'v', 'asc')));
        // min()/max() use the same order (null and missing fields are not values)
        $this->assertFalse($db->query('ex')->min('v'));
        $this->assertSame([1], $db->query('ex')->max('v'));
        $this->assertFalse($db->min('ex', 'v'));
        $this->assertSame([1], $db->max('ex', 'v'));
        // API.md: max([50, "10"]) is "10" because numeric strings sort after numbers
        $db->insert('mx', [['v' => 50], ['v' => '10']]);
        $this->assertSame('10', $db->max('mx', 'v'));
        $this->assertSame(50, $db->min('mx', 'v'));
        $this->assertSame('10', $db->query('mx')->max('v'));
        // noneDB::sort(): [] for an empty array, false for a non-array (API.md)
        $this->assertSame([], $db->sort([], 'v'));
        $this->assertFalse($db->sort('x', 'v'));
        $this->assertFalse($db->sort(null, 'v'));
    }

    /** @test top-k (sort with offset + limit <= 1000) returns exactly the slice of the full sort, ties in key order */
    public function topKReturnsTheSameRowsAsAFullSort(): void
    {
        mt_srand(4242);
        $pool = [null, true, false, 0, 1, -1, 2.5, 10, '10', '9', '1e1', 'abc', 'ABC', 'b', '', [1], ['a' => 1], 3.0, '1a', ' 7'];
        $rows = [];
        for ($i = 0; $i < 1500; $i++) {
            $row = ['i' => $i, 'h' => mt_rand(0, 4)];
            if (mt_rand(0, 9) > 0) {
                $row['v'] = $pool[mt_rand(0, count($pool) - 1)];
            }
            $rows[] = $row;
        }
        $records = self::withKeys($rows);
        $slices = [[0, 1], [0, 10], [7, 13], [990, 10], [0, 1000], [1, 999], [500, 500], [1, 1000], [0, 1001], [1490, 20], [3000, 5]];
        foreach ([['shardingEnabled' => false], ['shardSize' => 400]] as $n => $config) {
            $db = $this->db($config);
            $name = 'topk' . $n;
            $db->insert($name, $rows);
            foreach ([[['v', 'asc']], [['v', 'desc']], [['h', 'desc'], ['v', 'asc']]] as $keys) {
                $full = self::keysOf(self::refSort($records, $keys));
                $filtered = self::keysOf(self::refSort(self::refWhere($records, [['h' => ['$gte' => 2]]]), $keys));
                foreach ($slices as [$offset, $limit]) {
                    $label = "$name " . json_encode($keys) . " offset $offset limit $limit";
                    $q = $db->query($name);
                    $w = $db->query($name)->where(['h' => ['$gte' => 2]]);
                    foreach ($keys as [$field, $order]) {
                        $q->sort($field, $order);
                        $w->sort($field, $order);
                    }
                    $q->offset($offset)->limit($limit);
                    $w->offset($offset)->limit($limit);
                    $this->assertSame($offset + $limit <= 1000 ? 'top-k' : 'full', $q->explain()['sort'], $label);
                    $this->assertSame(array_slice($full, $offset, $limit), self::keysOf($q->get()), $label);
                    $this->assertSame(array_slice($filtered, $offset, $limit), self::keysOf($w->get()), "filtered $label");
                }
            }
        }
    }

    // ======================================================================
    // Aggregates (QUERY.md "Aggregation"; API.md "Aggregation")
    // ======================================================================

    /** @test sum/avg/min/max/distinct/count of the builder and of noneDB, on every kind of value */
    public function aggregatesFollowTheDocumentedRules(): void
    {
        $rows = self::mixedRows();
        $records = self::withKeys($rows);
        // Reference: numbers and numeric strings are numbers; booleans, other strings, null, arrays are not
        $numbers = function (array $records) {
            $out = [];
            foreach ($records as $r) {
                if (array_key_exists('v', $r) && self::refIsNumber($r['v'])) {
                    $out[] = self::refToNumber($r['v']);
                }
            }
            return $out;
        };
        $present = function (array $records) {
            return array_values(array_filter($records, function ($r) { return isset($r['v']); }));
        };
        $distinct = function (array $records) {
            $out = [];
            foreach ($records as $r) {
                if (isset($r['v']) && !in_array($r['v'], $out, true)) {
                    $out[] = $r['v'];
                }
            }
            return $out;
        };
        // The reference itself, by hand: 5+30+0-1+5.5+5.0+0.5+5+30+7+10+0.5+0 = 97.5 over 13 numbers
        $this->assertSame(97.5, (float)array_sum($numbers($records)));
        $this->assertCount(13, $numbers($records));

        foreach ($this->setups($rows) as $name => $db) {
            $this->assertSame(97.5, $db->query($name)->sum('v'), "$name sum");
            $this->assertSame(7.5, $db->query($name)->avg('v'), "$name avg");
            $this->assertFalse($db->query($name)->min('v'), "$name min: booleans are the smallest values (null is no value)");
            $this->assertSame(['a' => 1], $db->query($name)->max('v'), "$name max: arrays are the largest, by their JSON");
            $this->assertSame($distinct($records), $db->query($name)->distinct('v'), "$name distinct");
            $this->assertSame(30, $db->query($name)->count(), "$name count");
            $this->assertSame(97.5, $db->sum($name, 'v'), "$name noneDB::sum");
            $this->assertSame(7.5, $db->avg($name, 'v'), "$name noneDB::avg");
            $this->assertFalse($db->min($name, 'v'), "$name noneDB::min");
            $this->assertSame(['a' => 1], $db->max($name, 'v'), "$name noneDB::max");
            $this->assertSame($distinct($records), $db->distinct($name, 'v'), "$name noneDB::distinct");
            $this->assertSame(30, $db->count($name), "$name noneDB::count");

            foreach ([0, 1, 2] as $g) {
                $subset = self::refWhere($records, [['g' => $g]]);
                $nums = $numbers($subset);
                $sum = (float)array_sum($nums);
                $avg = count($nums) > 0 ? array_sum($nums) / count($nums) : 0.0;
                $asc = self::refSort($present($subset), [['v', 'asc']]);
                $desc = self::refSort($present($subset), [['v', 'desc']]);
                $q = function () use ($db, $name, $g) { return $db->query($name)->where(['g' => $g]); };
                $label = "$name g=$g";
                $this->assertSame($sum, $q()->sum('v'), "sum $label");
                $this->assertSame((float)$avg, $q()->avg('v'), "avg $label");
                $this->assertSame($asc[0]['v'], $q()->min('v'), "min $label");
                $this->assertSame($desc[0]['v'], $q()->max('v'), "max $label");
                $this->assertSame($distinct($subset), $q()->distinct('v'), "distinct $label");
                $this->assertSame(count($subset), $q()->count(), "count $label");
                $this->assertNumber($sum, $db->sum($name, 'v', ['g' => $g]), "noneDB::sum $label");
                $this->assertNumber($avg, $db->avg($name, 'v', ['g' => $g]), "noneDB::avg $label");
                $this->assertSame($asc[0]['v'], $db->min($name, 'v', ['g' => $g]), "noneDB::min $label");
                $this->assertSame($desc[0]['v'], $db->max($name, 'v', ['g' => $g]), "noneDB::max $label");
                $this->assertSame(count($subset), $db->count($name, ['g' => $g]), "noneDB::count $label");
            }

            // Aggregates read the matching records before select()/except()
            $this->assertSame(97.5, $db->query($name)->select(['i'])->sum('v'), "$name select + sum");
            $this->assertSame(7.5, $db->query($name)->except(['v'])->avg('v'), "$name except + avg");
            $this->assertFalse($db->query($name)->select(['g'])->min('v'), "$name select + min");
            $this->assertSame(['a' => 1], $db->query($name)->except(['v', 'g'])->max('v'), "$name except + max");
            $this->assertSame($distinct($records), $db->query($name)->select(['i'])->distinct('v'), "$name select + distinct");
            $this->assertSame(30, $db->query($name)->select(['i'])->count(), "$name select + count");

            // No values at all: sum and avg 0.0, min/max null, distinct [] (no record has "nothere")
            $this->assertSame(0.0, $db->query($name)->sum('nothere'));
            $this->assertSame(0.0, $db->query($name)->avg('nothere'));
            $this->assertNull($db->query($name)->min('nothere'));
            $this->assertNull($db->query($name)->max('nothere'));
            $this->assertSame([], $db->query($name)->distinct('nothere'));
            $this->assertSame([], $db->distinct($name, 'nothere'));
            // Only non-numeric values: nothing to add up
            $this->assertSame(0.0, $db->query($name)->whereIn('v', ['abc', true, null, [1]])->sum('v'));
            $this->assertSame(0.0, $db->query($name)->whereIn('v', ['abc', true, null, [1]])->avg('v'));
            $this->assertNumber(0, $db->sum($name, 'v', ['v' => 'abc']));
            $this->assertNumber(0, $db->avg($name, 'v', ['v' => true]));
        }
    }

    /** @test distinct() keeps first appearance, types (1, 1.0, "1" are three values) and merges -0.0 with 0.0 */
    public function distinctIsTypedAndOrderedByFirstAppearance(): void
    {
        $db = $this->db(['shardSize' => 3]);
        $db->insert('d', [['v' => '1'], ['v' => 1.0], ['v' => 1], ['v' => -0.0], ['v' => 0.0], ['v' => 0], ['v' => null],
            ['x' => 1], ['v' => '1'], ['v' => [1, 2]], ['v' => [2, 1]], ['v' => [1, 2]], ['v' => ['a' => 1, 'b' => 2]], ['v' => ['b' => 2, 'a' => 1]]]);
        $expected = ['1', 1.0, 1, -0.0, 0, [1, 2], [2, 1], ['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]];
        $this->assertSame($expected, $db->distinct('d', 'v'));
        $this->assertSame($expected, $db->query('d')->distinct('v'));
        // whereIn is strict too: 1.0 (key 1), -0.0 (3), 0.0 (4, === -0.0) and 0 (5); "1" and 1 are not 1.0
        $this->assertSame([1, 3, 4, 5], self::keysOf($db->query('d')->whereIn('v', [1.0, -0.0, 0])->get()));
        $this->assertSame([1.0, -0.0, 0], $db->query('d')->whereIn('v', [1.0, -0.0, 0])->distinct('v'));
        $minusZero = $db->query('d')->distinct('v')[3];
        $this->assertTrue(is_float($minusZero) && $minusZero == 0.0, '-0.0 and 0.0 are one value: the first one seen is kept');
        // Missing database: [] (autoCreateDB) / false (off), nothing is created
        $this->assertSame([], $db->distinct('nodb', 'v'));
        $this->assertFalse($this->db(['autoCreateDB' => false])->distinct('nodb', 'v'));
        $this->assertSame([], glob($this->testDbDir . '*-nodb*'));
    }

    // ======================================================================
    // count(), first(), last(), exists(), limit(0) (QUERY.md "count()", "limit() / offset()", Terminal Methods)
    // ======================================================================

    /** @test count() applies limit() and offset(), and counts the groups with groupBy() */
    public function countAppliesLimitOffsetAndGroupBy(): void
    {
        foreach ([['shardingEnabled' => false], ['shardSize' => 3]] as $n => $config) {
            $db = $this->db($config);
            $name = 'cnt' . $n;
            $rows = [];
            for ($i = 0; $i < 10; $i++) {
                $rows[] = ['i' => $i, 'm' => $i < 6, 'c' => ['x', 'y', 'z'][$i % 3]];
            }
            $db->insert($name, $rows);
            $q = function () use ($db, $name) { return $db->query($name)->where(['m' => true]); }; // 6 matches
            $this->assertSame(6, $q()->count());
            $this->assertSame(4, $q()->offset(2)->count(), 'QUERY.md: offset(2)->count() on 6 matches is 4');
            $this->assertSame(3, $q()->limit(3)->count());
            $this->assertSame(1, $q()->offset(5)->limit(3)->count());
            $this->assertSame(0, $q()->offset(6)->count());
            $this->assertSame(0, $q()->limit(0)->count());
            $this->assertSame(6, $q()->limit(100)->count());
            $this->assertSame(10, $db->query($name)->count());
            $this->assertSame(8, $db->query($name)->skip(2)->count(), 'skip() is offset()');
            $this->assertSame(3, $db->query($name)->limit(3)->count(), 'limit() without filters');
            $this->assertSame(3, $db->query($name)->groupBy('c')->count(), 'three groups');
            $this->assertSame(3, $q()->groupBy('c')->count(), 'matches 0..5: x, y, z');
            $this->assertSame(2, $q()->groupBy('c')->limit(2)->count(), 'limit applies to the groups');
            $this->assertSame(1, $db->query($name)->groupBy('c')->having('count', '>', 3)->count(), 'x has 4 records');
            $this->assertSame(10, $db->query($name)->having('count', '>', 100)->count(), 'having() without groupBy() is ignored');
            $this->assertSame(6, $q()->sort('i', 'desc')->count(), 'sort() changes nothing');
            $this->assertSame(6, $q()->select(['i'])->except(['m'])->count());
        }
    }

    /** @test first(), last() and exists() of the builder follow sort(), offset() and limit(); limit(0) returns nothing */
    public function firstLastExistsFollowTheResultWindow(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $rows = [];
        foreach ([30, 10, 50, 20, 40, 10, 60, 25, 35] as $i => $score) {
            $rows[] = ['i' => $i, 'score' => $score, 'odd' => $i % 2 === 1];
        }
        $db->insert('fl', $rows);
        $q = function () use ($db) { return $db->query('fl'); };
        $this->assertSame(0, $q()->first()['key'], 'key order without sort()');
        $this->assertSame(8, $q()->last()['key']);
        $this->assertSame(1, $q()->sort('score')->first()['key'], 'lowest score, ties (1 and 5) in key order');
        $this->assertSame(5, $q()->sort('score')->offset(1)->first()['key']);
        $this->assertSame(6, $q()->sort('score')->last()['key']);
        $this->assertSame(6, $q()->sort('score', 'desc')->first()['key']);
        $this->assertSame(5, $q()->sort('score', 'desc')->last()['key'], 'desc: the tie 1, 5 keeps key order, so 5 is last');
        $this->assertSame(3, $q()->sort('score')->limit(3)->last()['key'], '10, 10, 20: the third is key 3');
        // odd keys 1 (10), 3 (20), 5 (10), 7 (25): by score 1, 5, 3, 7
        $this->assertSame(['i' => 5, 'score' => 10, 'odd' => true, 'key' => 5], $q()->where(['odd' => true])->sort('score')->offset(1)->first());
        $this->assertSame(['i' => 3, 'score' => 20, 'odd' => true, 'key' => 3], $q()->where(['odd' => true])->sort('score')->limit(3)->last());
        $this->assertNull($q()->where(['score' => 999])->first());
        $this->assertNull($q()->where(['score' => 999])->last());
        $this->assertFalse($q()->where(['score' => 999])->exists());
        $this->assertTrue($q()->where(['score' => 10])->exists());
        $this->assertTrue($q()->where(['score' => 10])->sort('i')->select(['i'])->exists());

        // limit(0) returns nothing (QUERY.md)
        $this->assertSame([], $q()->limit(0)->get());
        $this->assertNull($q()->limit(0)->first());
        $this->assertNull($q()->limit(0)->last());
        $this->assertFalse($q()->limit(0)->exists());
        $this->assertSame(0, $q()->limit(0)->count());
        $this->assertNull($q()->sort('score')->limit(0)->first());

        // first() doesn't change the builder's own limit
        $builder = $q()->limit(3);
        $this->assertSame(0, $builder->first()['key']);
        $this->assertSame([0, 1, 2], self::keysOf($builder->get()));
        // offset past the end: nothing; exists() is about the same window as get()
        $this->assertSame([], $q()->offset(9)->get());
        $this->assertNull($q()->offset(9)->first());
        $this->assertFalse($q()->offset(9)->exists());
        $this->assertTrue($q()->offset(8)->exists());
        $this->assertSame([8], self::keysOf($q()->offset(8)->get()));
    }

    /** @test noneDB::first/last/exists/count with every kind of filter, key filters included (key-list order) */
    public function noneDbFirstLastExistsCountWithFilters(): void
    {
        foreach ([['shardingEnabled' => false], ['shardSize' => 2]] as $n => $config) {
            $db = $this->db($config);
            $name = 'fe' . $n;
            $db->insert($name, [['n' => 'a', 't' => 1], ['n' => 'b', 't' => 2], ['n' => 'c', 't' => 1], ['n' => 'd', 't' => 2], ['n' => 'e', 't' => 1]]);
            $this->assertSame(['n' => 'a', 't' => 1, 'key' => 0], $db->first($name));
            $this->assertSame(['n' => 'e', 't' => 1, 'key' => 4], $db->last($name));
            $this->assertSame(['n' => 'b', 't' => 2, 'key' => 1], $db->first($name, ['t' => 2]));
            $this->assertSame(['n' => 'd', 't' => 2, 'key' => 3], $db->last($name, ['t' => 2]));
            // A key list keeps its order (API.md find(): "in the order of a key list")
            $this->assertSame(['n' => 'd', 't' => 2, 'key' => 3], $db->first($name, ['key' => [3, '1']]));
            $this->assertSame(['n' => 'b', 't' => 2, 'key' => 1], $db->last($name, ['key' => [3, '1']]));
            $this->assertSame(2, $db->count($name, ['key' => [3, '1', 3, 99]]));
            $this->assertSame(1, $db->count($name, ['key' => '4', 't' => 1]));
            $this->assertSame(0, $db->count($name, ['key' => '4', 't' => 2]));
            $this->assertTrue($db->exists($name, ['key' => 2]));
            $this->assertTrue($db->exists($name, ['key' => '2', 'n' => 'c']));
            $this->assertFalse($db->exists($name, ['key' => 2, 'n' => 'x']));
            $this->assertFalse($db->exists($name, ['key' => 99]));
            $this->assertNull($db->first($name, ['key' => 99]));
            $this->assertNull($db->first($name, ['t' => 3]));
            $this->assertNull($db->last($name, ['t' => 3]));
            $this->assertFalse($db->exists($name, ['t' => 3]));
            $this->assertTrue($db->exists($name, []));
            $this->assertTrue($db->exists($name, 0));
            $this->assertSame(5, $db->count($name, 0));
            $this->assertSame(5, $db->count($name, null));
            $this->assertSame(5, $db->count($name, []));
            // Invalid filters (API.md: count() counts 0 for a filter find() rejects)
            foreach (['x', false, 1.5, true] as $invalid) {
                $label = $name . ' ' . var_export($invalid, true);
                $this->assertFalse($db->find($name, $invalid), $label);
                $this->assertSame(0, $db->count($name, $invalid), $label);
                $this->assertNull($db->first($name, $invalid), $label);
                $this->assertNull($db->last($name, $invalid), $label);
                $this->assertFalse($db->exists($name, $invalid), $label);
                $this->assertNull($db->min($name, 't', $invalid), $label);
                $this->assertNull($db->max($name, 't', $invalid), $label);
                $this->assertNumber(0, $db->sum($name, 't', $invalid), $label);
                $this->assertNumber(0, $db->avg($name, 't', $invalid), $label);
                $this->assertFalse($db->between($name, 't', 0, 5, $invalid), $label);
            }
        }
    }

    /** @test reads of a missing database return the documented empty values and create nothing */
    public function readsOfAMissingDatabaseCreateNothing(): void
    {
        foreach ([true, false] as $autoCreate) {
            $db = $this->db(['autoCreateDB' => $autoCreate]);
            $before = glob($this->testDbDir . '*');
            $this->assertSame($autoCreate ? [] : false, $db->find('ghost', 0));
            $this->assertSame($autoCreate ? [] : false, $db->find('ghost', ['a' => 1]));
            $this->assertSame($autoCreate ? [] : false, $db->find('ghost', ['key' => 1]));
            $this->assertSame(0, $db->count('ghost'));
            $this->assertSame(0, $db->count('ghost', ['a' => 1]));
            $this->assertSame(0, $db->count('ghost', ['key' => [1, 2]]));
            $this->assertNull($db->first('ghost'));
            $this->assertNull($db->first('ghost', ['key' => 0]));
            $this->assertNull($db->last('ghost'));
            $this->assertFalse($db->exists('ghost', ['a' => 1]));
            $this->assertFalse($db->exists('ghost', ['key' => 0]));
            $this->assertNumber(0, $db->sum('ghost', 'a'));
            $this->assertNumber(0, $db->avg('ghost', 'a'));
            $this->assertNull($db->min('ghost', 'a'));
            $this->assertNull($db->max('ghost', 'a'));
            $this->assertSame([], $db->query('ghost')->get());
            $this->assertSame(0, $db->query('ghost')->count());
            $this->assertSame(0, $db->query('ghost')->where(['a' => 1])->count());
            $this->assertNull($db->query('ghost')->first());
            $this->assertFalse($db->query('ghost')->exists());
            $this->assertSame(0.0, $db->query('ghost')->sum('a'));
            $this->assertSame([], $db->query('ghost')->distinct('a'));
            $this->assertSame([], $db->query('ghost')->groupBy('a')->get());
            $this->assertSame($before, glob($this->testDbDir . '*'), 'autoCreateDB ' . var_export($autoCreate, true) . ': no file was created');
            // Writes that find nothing to write report 0 and don't create the database
            $this->assertSame(['n' => 0], $db->query('ghost')->update(['a' => 1]));
            $this->assertSame(['n' => 0], $db->query('ghost')->delete());
            $this->assertSame(['n' => 0], $db->update('ghost', [['a' => 1], ['set' => ['b' => 2]]]));
            $this->assertSame(['n' => 0], $db->delete('ghost', ['a' => 1]));
            $this->assertFalse($db->getDBs('ghost'));
            $this->assertNotContains('ghost', $db->getDBs(false));
            // Not created: no FORMAT, no data (an empty location holding only LOCK is absent, STORAGE_FORMAT.md §8.3)
            $this->assertFileDoesNotExist($this->dbPath('ghost', 'format'));
            $this->assertFileDoesNotExist($this->dbPath('ghost', 'data'));
        }
    }

    // ======================================================================
    // groupBy() / having() (QUERY.md "Grouping & Having")
    // ======================================================================

    /** Field order inside a record is not part of the contract: compare with string keys sorted */
    private static function canon($v)
    {
        if (!is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = self::canon($x);
        }
        if ($v !== [] && array_keys($v) !== range(0, count($v) - 1)) {
            ksort($out, SORT_STRING);
        }
        return $out;
    }

    private static function groupRows(): array
    {
        return [
            ['c' => 1, 'p' => 10],        // 0
            ['c' => '1', 'p' => 20],      // 1
            ['c' => 1.5, 'p' => 'x'],     // 2
            ['c' => 1.0, 'p' => 5],       // 3
            ['c' => null, 'p' => 1],      // 4
            ['p' => 2],                   // 5  no "c": the null group
            ['c' => -0.0, 'p' => 3],      // 6
            ['c' => 0.0, 'p' => 4],       // 7  same group as -0.0
            ['c' => 0, 'p' => 6],         // 8  the integer 0 is another value
            ['c' => true, 'p' => 7],      // 9
            ['c' => 1, 'p' => 30],        // 10
            ['c' => [1], 'p' => '8'],     // 11
            ['c' => 'x', 'p' => true],    // 12
            ['c' => [1], 'p' => 9.5],     // 13
        ];
    }

    /** @test groupBy(): values keep their type, missing = null, -0.0 = 0.0; _items/_count; having() with every aggregate and operator */
    public function groupByAndHavingFollowTheDocumentedRules(): void
    {
        $rows = self::groupRows();
        $records = self::withKeys($rows);
        // By hand: group value => member keys
        $groups = [[1, [0, 10]], ['1', [1]], [1.5, [2]], [1.0, [3]], [null, [4, 5]], [-0.0, [6, 7]], [0, [8]], [true, [9]], [[1], [11, 13]], ['x', [12]]];
        $expectedRows = function (array $which) use ($groups, $records) {
            $out = [];
            foreach ($which as $i) {
                [$value, $keys] = $groups[$i];
                $out[] = ['_group' => $value, '_items' => array_map(function ($k) use ($records) { return $records[$k]; }, $keys), '_count' => count($keys)];
            }
            return $out;
        };
        foreach ([['shardingEnabled' => false], ['shardSize' => 4]] as $n => $config) {
            $db = $this->db($config);
            $name = 'grp' . $n;
            $db->insert($name, $rows);
            $q = function () use ($db, $name) { return $db->query($name)->groupBy('c'); };
            $all = $q()->get();
            $this->assertSame(self::canon($expectedRows(range(0, 9))), self::canon($all), "$name groups in order of first appearance");
            $this->assertSame([1, '1', 1.5, 1.0, null, -0.0, 0, true, [1], 'x'], array_column($all, '_group'));
            $this->assertTrue(is_float($all[5]['_group']), '-0.0 and 0.0 are one float group');

            // having(): count, sum/avg/min/max of the numeric values of the group's items
            // sums 40, 20, 0, 5, 3, 7, 6, 7, 17.5, 0; mins 10, 20, -, 5, 1, 3, 6, 7, 8, 9.5... (see each case)
            $cases = [
                [['count', '>', 1], [0, 4, 5, 8]],
                [['count', '!=', 1], [0, 4, 5, 8]],
                [['count', '<', 2], [1, 2, 3, 6, 7, 9]],
                [['count', '=', 2], [0, 4, 5, 8]],
                [['count', '<=', 1], [1, 2, 3, 6, 7, 9]],
                [['count', '>=', 3], []],
                [['sum:p', '>=', 30], [0]],
                [['sum:p', '>', 0], [0, 1, 3, 4, 5, 6, 7, 8]],
                [['sum:p', '=', 0], [2, 9]],
                [['avg:p', '=', 20], [0, 1]],
                [['avg:p', '<', 4], [2, 4, 5, 9]],
                [['min:p', '<', 5], [4, 5]],
                [['min:p', '>=', 8], [0, 1, 8]],
                [['max:p', '<=', 6], [3, 4, 5, 6]],
                [['max:p', '=', 9.5], [8]],
            ];
            foreach ($cases as [[$aggregate, $operator, $value], $which]) {
                $label = "$name having($aggregate $operator " . var_export($value, true) . ')';
                $this->assertSame(array_map(function ($i) use ($groups) { return $groups[$i][0]; }, $which),
                    array_column($q()->having($aggregate, $operator, $value)->get(), '_group'), $label);
                $this->assertSame(count($which), $q()->having($aggregate, $operator, $value)->count(), "count $label");
                $this->assertSame($which !== [], $q()->having($aggregate, $operator, $value)->exists(), "exists $label");
            }
            // Several having() calls all apply
            $this->assertSame([1, -0.0, [1]], array_column($q()->having('count', '>', 1)->having('sum:p', '>', 5)->get(), '_group'));
            // Unknown aggregates and operators are ignored
            foreach ([['median:p', '>', 0], ['sum', '>', 1000], ['count', '<>', 1], ['count', '==', 1], ['sum:p', 'gt', 1000]] as [$aggregate, $operator, $value]) {
                $this->assertSame(10, count($q()->having($aggregate, $operator, $value)->get()), "$name ignored: $aggregate $operator");
            }
            // Without groupBy() having() is ignored: records, not groups
            $this->assertSame(self::keysOf($records), self::keysOf($db->query($name)->having('count', '>', 100)->get()));
            $this->assertTrue($db->query($name)->having('count', '>', 100)->exists());
            // Groups are rows: sort() sorts them (stable), limit()/offset() cut them, first()/last() pick them
            $this->assertSame([1, null, -0.0, [1], '1', 1.5, 1.0, 0, true, 'x'], array_column($q()->sort('_count', 'desc')->get(), '_group'));
            $this->assertSame([1.5, 1.0], array_column($q()->offset(2)->limit(2)->get(), '_group'));
            $this->assertSame('x', $q()->last()['_group']);
            $this->assertSame(1, $q()->first()['_group']);
            $this->assertSame(10, $q()->count());
            // Grouping happens after the filters
            $this->assertSame([1, 1.0], array_column($q()->where(['p' => ['$gte' => 5, '$lte' => 10]])->whereIn('c', [1, 1.0])->get(), '_group'));
            $this->assertSame([], $q()->where(['p' => 'nothing'])->get());
            $this->assertFalse($q()->where(['p' => 'nothing'])->exists());
            $this->assertFalse($q()->having('count', '>', 5)->exists(), 'having() can drop every group');
        }
    }

    /** @test having() with an unknown operator is ignored for every group, also groups whose min/max has no value */
    public function havingWithAnUnknownOperatorIsIgnoredForEveryGroup(): void
    {
        $db = $this->db();
        $db->insert('hu', self::groupRows());
        // Groups 1.5 and "x" have no numeric "p": their min/max is no value, but the condition is ignored as a whole
        foreach (['gt', '<>', '=='] as $operator) {
            $this->assertSame(10, count($db->query('hu')->groupBy('c')->having('max:p', $operator, 100)->get()), "max:p $operator");
            $this->assertSame(10, count($db->query('hu')->groupBy('c')->having('min:p', $operator, 0)->get()), "min:p $operator");
        }
    }

    /** @test select()/except() with groupBy(): _group/_count/_items shape the rows, other fields the items (key kept) */
    public function groupByWithSelectAndExcept(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $db->insert('gp', [['s' => 'pending', 'total' => 12.5, 'x' => 1], ['s' => 'done', 'total' => 3, 'x' => 2], ['s' => 'pending', 'total' => 7, 'x' => 3]]);
        $q = function () use ($db) { return $db->query('gp')->groupBy('s'); };
        // The documented example: select(['_group', 'total'])
        $this->assertSame(self::canon([
            ['_group' => 'pending', '_items' => [['total' => 12.5, 'key' => 0], ['total' => 7, 'key' => 2]]],
            ['_group' => 'done', '_items' => [['total' => 3, 'key' => 1]]],
        ]), self::canon($q()->select(['_group', 'total'])->get()));
        // Only item fields: the rows keep _group, _count and _items
        $this->assertSame(self::canon([
            ['_group' => 'pending', '_count' => 2, '_items' => [['x' => 1, 'key' => 0], ['x' => 3, 'key' => 2]]],
            ['_group' => 'done', '_count' => 1, '_items' => [['x' => 2, 'key' => 1]]],
        ]), self::canon($q()->select(['x'])->get()));
        $this->assertSame([['_count' => 2], ['_count' => 1]], $q()->select(['_count'])->get());
        $this->assertSame(self::canon([['_group' => 'pending', '_count' => 2], ['_group' => 'done', '_count' => 1]]),
            self::canon($q()->except(['_items'])->get()));
        $this->assertSame(self::canon([
            ['_group' => 'pending', '_items' => [['s' => 'pending', 'x' => 1, 'key' => 0], ['s' => 'pending', 'x' => 3, 'key' => 2]]],
            ['_group' => 'done', '_items' => [['s' => 'done', 'x' => 2, 'key' => 1]]],
        ]), self::canon($q()->except(['_count', 'total'])->get()));
        $this->assertSame(self::canon([
            ['_group' => 'pending', '_count' => 2, '_items' => [['s' => 'pending', 'total' => 12.5, 'key' => 0], ['s' => 'pending', 'total' => 7, 'key' => 2]]],
            ['_group' => 'done', '_count' => 1, '_items' => [['s' => 'done', 'total' => 3, 'key' => 1]]],
        ]), self::canon($q()->except(['x'])->get()));
        // The projection doesn't change what having() and the aggregates see
        $this->assertSame(['pending'], array_column($q()->select(['_group'])->having('sum:total', '>', 15)->get(), '_group'));
        // Stored records are untouched
        $this->assertSame([['s' => 'pending', 'total' => 12.5, 'x' => 1, 'key' => 0]], $db->find('gp', ['key' => 0]));
    }

    /** @test the groupBy() identity doesn't depend on serialize_precision or precision */
    public function groupIdentityIgnoresPrecisionSettings(): void
    {
        $db = $this->db();
        $db->insert('pr', [['v' => 0.1 + 0.2], ['v' => 0.3], ['v' => 1 / 3], ['v' => 0.3333333333333333], ['v' => 1e-20], ['v' => 1.00000000000000001e-20]]);
        $saved = [ini_get('serialize_precision'), ini_get('precision')];
        try {
            foreach ([['3', '3'], ['17', '17'], ['-1', '14']] as [$serialize, $precision]) {
                ini_set('serialize_precision', $serialize);
                ini_set('precision', $precision);
                $groups = $db->query('pr')->groupBy('v')->get();
                // 0.1 + 0.2 is not 0.3; 1/3 and 0.3333333333333333 are the same double; the two 1e-20 literals are one double
                $this->assertSame([[0], [1], [2, 3], [4, 5]], array_map(function ($g) { return self::keysOf($g['_items']); }, $groups), "precision $serialize/$precision");
                $this->assertSame([0.1 + 0.2, 0.3, 1 / 3], $db->query('pr')->whereIn('v', [0.1 + 0.2, 0.3, 1 / 3])->distinct('v'));
            }
        } finally {
            ini_set('serialize_precision', (string)$saved[0]);
            ini_set('precision', (string)$saved[1]);
        }
    }

    // ======================================================================
    // select() / except() (QUERY.md "select() / except()")
    // ======================================================================

    /** @test select() keeps only the given fields plus key; except() drops fields; neither affects filters, sort or aggregates */
    public function selectAndExceptShapeOnlyTheResult(): void
    {
        $rows = self::mixedRows();
        $records = self::withKeys($rows);
        foreach ($this->setups($rows) as $name => $db) {
            $expected = array_map(function ($r) {
                $out = [];
                if (array_key_exists('v', $r)) {
                    $out['v'] = $r['v'];
                }
                return $out + ['key' => $r['key']];
            }, $records);
            $this->assertSame(self::canon($expected), self::canon($db->query($name)->select(['v'])->get()), "$name select v");
            $this->assertSame(self::canon(array_map(function ($r) { return ['i' => $r['i'], 'key' => $r['key']]; }, $records)),
                self::canon($db->query($name)->select(['i', 'nothere'])->get()), "$name select: a missing field is left out");
            $this->assertSame(array_map(function ($r) { return ['key' => $r['key']]; }, $records),
                $db->query($name)->select(['key'])->get(), "$name select key");
            $this->assertSame(self::canon(array_map(function ($r) { return ['i' => $r['i'], 'key' => $r['key']]; }, $records)),
                self::canon($db->query($name)->except(['v', 'g', 'nothere'])->get()), "$name except");
            // Filters and sort see every field
            $subset = self::refSort(self::refWhere($records, [['g' => 1]]), [['v', 'desc']]);
            $this->assertSame(array_map(function ($r) { return ['i' => $r['i'], 'key' => $r['key']]; }, $subset),
                array_map(function ($r) { ksort($r); return $r; }, $db->query($name)->select(['i'])->where(['g' => 1])->sort('v', 'desc')->get()), "$name select + where + sort");
            $this->assertSame(self::keysOf($subset), self::keysOf($db->query($name)->except(['v', 'g'])->where(['g' => 1])->sort('v', 'desc')->get()));
        }
    }

    /** @test except(['key']) keeps key: "key is always kept" (QUERY.md select() / except()) */
    public function exceptRemovesKeyOnlyWhenListed(): void
    {
        // QUERY.md select() / except(): select() always keeps key; except() removes key only when it is
        // listed
        $db = $this->db();
        $db->insert('ek', [['a' => 1, 'b' => 2], ['a' => 3, 'b' => 4]]);
        $this->assertSame([['b' => 2, 'key' => 0], ['b' => 4, 'key' => 1]], $db->query('ek')->except(['a'])->get());
        $this->assertSame([['b' => 2], ['b' => 4]], $db->query('ek')->except(['key', 'a'])->get());
        $this->assertSame([[], []], $db->query('ek')->except(['a', 'b', 'key'])->get());
        $this->assertSame([['a' => 1, 'key' => 0], ['a' => 3, 'key' => 1]], $db->query('ek')->select(['a'])->get());
        $this->assertSame([['key' => 0], ['key' => 1]], $db->query('ek')->select(['key'])->get());
    }

    /** @test _distance: withDistance()'s is kept by select(), withinDistance()/nearest()'s only when selected; except() drops it */
    public function distanceFieldAndProjection(): void
    {
        $db = $this->db();
        $db->insert('pd', [
            ['n' => 'a', 'loc' => ['type' => 'Point', 'coordinates' => [0, 0]]],
            ['n' => 'b', 'loc' => ['type' => 'Point', 'coordinates' => [0.01, 0]]],
            ['n' => 'c', 'loc' => ['type' => 'Point', 'coordinates' => [0.02, 0]]],
            ['n' => 'd', 'loc' => ['type' => 'Point', 'coordinates' => [1, 0]]],
        ]);
        // On the equator the distance is R * longitude difference (radians), R = 6371000 m (API.md: 0.01 degrees ~ 839.2 m at 41 N)
        $meters = function (float $deg) { return 6371000.0 * deg2rad($deg); };
        $within = function () use ($db) { return $db->query('pd')->withinDistance('loc', 0, 0, 5000); };
        $this->assertSame(self::canon([['n' => 'a', 'key' => 0], ['n' => 'b', 'key' => 1], ['n' => 'c', 'key' => 2]]),
            self::canon($within()->select(['n'])->get()), 'select() drops the _distance of withinDistance()');
        $selected = $within()->select(['n', '_distance'])->get();
        $this->assertSame(['a', 'b', 'c'], array_column($selected, 'n'));
        $this->assertEqualsWithDelta([0.0, $meters(0.01), $meters(0.02)], array_column($selected, '_distance'), 0.011, 'meters, 2 decimals');
        foreach ($within()->get() as $row) {
            $this->assertArrayHasKey('_distance', $row);
        }
        foreach ($within()->except(['_distance'])->get() as $row) {
            $this->assertArrayNotHasKey('_distance', $row);
            $this->assertArrayHasKey('loc', $row);
        }
        $withDistance = $db->query('pd')->withDistance('loc', 0.02, 0)->select(['n'])->get();
        $this->assertSame(['a', 'b', 'c', 'd'], array_column($withDistance, 'n'));
        $this->assertEqualsWithDelta([$meters(0.02), $meters(0.01), 0.0, $meters(0.98)], array_column($withDistance, '_distance'), 0.0002,
            'withDistance(): kept by select(), 4 decimals, measured from its own point');
        foreach ($db->query('pd')->nearest('loc', 0, 0, 2)->select(['n'])->get() as $row) {
            $this->assertArrayNotHasKey('_distance', $row, 'nearest(): dropped unless selected');
        }
        $this->assertSame(['a', 'b'], array_column($db->query('pd')->nearest('loc', 0, 0, 2)->select(['n', '_distance'])->get(), 'n'));
        // search() without a field list doesn't look into _distance
        $this->assertSame([], $within()->search('1111')->get());
    }

    // ======================================================================
    // join() (QUERY.md "Joins")
    // ======================================================================

    /** @test join on key: whole numbers (5, 5.0, "5") match a record key; "00", 1.5, booleans, null, arrays don't */
    public function joinOnKeyMatchesWholeNumbersOnly(): void
    {
        $refs = [0, '0', 0.0, -0.0, '00', 1.0, '1', 1.5, true, false, null, [0], 2, 3, '-0', ' 1', '1 ', 'u0', 999.0, self::MISSING];
        // By hand (QUERY.md: integers, integral floats and canonical integer strings match; others don't)
        $expected = [0, 0, 0, 0, null, 1, 1, null, null, null, null, null, 2, null, null, null, null, null, null, null];
        foreach ([['shardingEnabled' => false], ['shardSize' => 2]] as $n => $config) {
            $db = $this->db($config);
            $users = 'users' . $n;
            $orders = 'orders' . $n;
            $db->insert($users, [['name' => 'u0'], ['name' => 'u1'], ['name' => 'u2']]);
            $db->insert($orders, array_map(function ($r) { return $r === self::MISSING ? ['x' => 1] : ['user_id' => $r]; }, $refs));
            $userRecords = $db->find($users, 0);
            $want = array_map(function ($k) use ($userRecords) { return $k === null ? null : $userRecords[$k]; }, $expected);
            $joined = $db->query($orders)->join($users, 'user_id', 'key')->get();
            $this->assertSame($want, array_column($joined, $users), "$users: attached under the database name");
            $this->assertSame($want, array_column($db->query($orders)->join($users, 'user_id', 'key', 'buyer')->get(), 'buyer'), 'alias');
            $this->assertSame(array_slice($want, 3, 4), array_column($db->query($orders)->join($users, 'user_id', 'key')->offset(3)->limit(4)->get(), $users), 'joined after the slice');
        }
    }

    private static function joinForeignRows(): array
    {
        return [
            ['id' => 5, 'v' => 'A'], ['id' => '5', 'v' => 'B'], ['id' => 5.0, 'v' => 'C'], ['id' => 1.5, 'v' => 'D'],
            ['id' => '1.5', 'v' => 'E'], ['id' => true, 'v' => 'F'], ['id' => 1, 'v' => 'G'], ['id' => '007', 'v' => 'H'],
            ['id' => 7, 'v' => 'I'], ['id' => null, 'v' => 'J'], ['id' => [5], 'v' => 'K'], ['id' => false, 'v' => 'L'],
            ['id' => 0, 'v' => 'M'], ['id' => '', 'v' => 'N'], ['v' => 'O'],
        ];
    }

    /** @test join on a field: 5/"5"/5.0 match each other (last duplicate wins), floats, booleans, "007", "", null, arrays per the rules */
    public function joinOnAFieldFollowsTheMatchingRules(): void
    {
        $refs = [5, '5', 5.0, 1.5, '1.5', true, 1, '1', 1.0, '007', 7, '7', null, [5], false, 0, '0', -0.0, '', 'nope', self::MISSING, '-0', ' 5'];
        // By hand: the foreign key (index in joinForeignRows) each ref joins, null for none
        $expected = [2, 2, 2, 3, 4, 5, 6, 6, 6, 7, 8, 8, null, null, 11, 12, 12, 12, 13, null, null, null, null];
        $local = array_map(function ($r) { return $r === self::MISSING ? ['f' => 'stored'] : ['ref' => $r, 'f' => 'stored']; }, $refs);
        $variants = [['plain', ['shardingEnabled' => false], false], ['indexed', ['shardingEnabled' => false], true], ['sharded', ['shardSize' => 4], true]];
        foreach ($variants as [$variant, $config, $indexed]) {
            $db = $this->db($config);
            $foreign = 'fj' . $variant;
            $db->insert($foreign, self::joinForeignRows());
            if ($indexed) {
                $this->assertTrue($db->createFieldIndex($foreign, 'id')['success']);
            }
            $db->insert('lj', $local);
            $foreignRecords = $db->find($foreign, 0);
            $want = array_map(function ($k) use ($foreignRecords) { return $k === null ? null : $foreignRecords[$k]; }, $expected);
            $this->assertSame($want, array_column($db->query('lj')->join($foreign, 'ref', 'id', 'f')->get(), 'f'), $variant);
            // The attached record replaces the field in the result only
            $this->assertSame(array_fill(0, count($refs), 'stored'), array_column($db->find('lj', 0), 'f'), "$variant: storage unchanged");
            // Sorting by the join alias: joins run first, the attached records sort as arrays (by their JSON), null first
            $rows = $db->query('lj')->join($foreign, 'ref', 'id', 'f')->get();
            $q = $db->query('lj')->join($foreign, 'ref', 'id', 'f')->sort('f', 'desc')->limit(5);
            $plan = $q->explain();
            $this->assertSame('full', $plan['sort']);
            $this->assertSame('before-sort', $plan['joins'][0]['when']);
            $this->assertSame('in-lookup', $plan['joins'][0]['strategy']);
            $this->assertSame(self::keysOf(array_slice(self::refSort($rows, [['f', 'desc']]), 0, 5)), self::keysOf($q->get()), "$variant sort by alias");
            $late = $db->query('lj')->join($foreign, 'ref', 'id', 'f')->sort('ref')->limit(5)->explain();
            $this->assertSame('after-slice', $late['joins'][0]['when']);
            $this->assertSame('top-k', $late['sort']);
            $db->dropDB('lj');
        }
    }

    /** @test joining a database that doesn't exist attaches null and creates nothing, with and without autoCreateDB */
    public function joinWithAMissingDatabaseAttachesNull(): void
    {
        foreach ([true, false] as $autoCreate) {
            $db = $this->db(['autoCreateDB' => $autoCreate]);
            $db->createDB('jl');
            $this->assertSame(['n' => 3], $db->insert('jl', [['ref' => 0], ['ref' => '1'], ['ref' => 'x']]));
            $before = glob($this->testDbDir . '*');
            $this->assertSame([null, null, null], array_column($db->query('jl')->join('nowhere', 'ref', 'key')->get(), 'nowhere'));
            $this->assertSame([null, null, null], array_column($db->query('jl')->join('nowhere', 'ref', 'id', 'w')->get(), 'w'));
            $this->assertSame(3, $db->query('jl')->join('nowhere', 'ref', 'key')->count());
            $this->assertSame($before, glob($this->testDbDir . '*'), 'nothing created');
            $this->assertFalse($db->getDBs('nowhere'));
            $db->dropDB('jl');
        }
    }

    /** @test several joins, a join after where()/sort()/limit(), and count()/exists() with joins */
    public function multipleJoinsAndJoinsWithOtherClauses(): void
    {
        $db = $this->db(['shardSize' => 3]);
        $db->insert('cust', [['name' => 'Ann'], ['name' => 'Bob']]);
        $db->insert('prod', [['sku' => 'p1', 'title' => 'Pen'], ['sku' => 'p2', 'title' => 'Ink']]);
        $db->insert('items', [
            ['c' => 0, 'p' => 'p2', 'qty' => 3], ['c' => 1, 'p' => 'p1', 'qty' => 1], ['c' => 0, 'p' => 'p1', 'qty' => 5],
            ['c' => 2, 'p' => 'p3', 'qty' => 2], ['c' => 1, 'p' => 'p2', 'qty' => 4],
        ]);
        $rows = $db->query('items')->join('cust', 'c', 'key')->join('prod', 'p', 'sku')->where(['qty' => ['$gte' => 2]])->sort('qty', 'desc')->limit(3)->get();
        $this->assertSame([2, 4, 0], self::keysOf($rows));
        $this->assertSame(['Ann', 'Bob', 'Ann'], array_map(function ($r) { return $r['cust']['name']; }, $rows));
        $this->assertSame(['Pen', 'Ink', 'Ink'], array_map(function ($r) { return $r['prod']['title']; }, $rows));
        $all = $db->query('items')->join('cust', 'c', 'key')->join('prod', 'p', 'sku')->get();
        $this->assertNull($all[3]['cust'], 'no customer 2');
        $this->assertNull($all[3]['prod'], 'no product p3');
        $this->assertSame(5, $db->query('items')->join('cust', 'c', 'key')->count(), 'joins never filter');
        $this->assertSame(1, $db->query('items')->join('cust', 'c', 'key')->offset(4)->count());
        $this->assertTrue($db->query('items')->join('cust', 'c', 'key')->where(['c' => 2])->exists());
        // With groupBy() the joins run before grouping: the items carry the joined record
        $groups = $db->query('items')->join('cust', 'c', 'key')->groupBy('c')->get();
        $this->assertSame([0, 1, 2], array_column($groups, '_group'));
        $this->assertSame([['Ann', 'Ann'], ['Bob', 'Bob'], [null]], array_map(function ($g) {
            return array_map(function ($item) { return $item['cust']['name'] ?? null; }, $g['_items']);
        }, $groups));
    }

    // ======================================================================
    // Writes of the builder (QUERY.md update()/delete()/removeFields())
    // ======================================================================

    /** @test sort(), offset() and limit() choose the records written; select(), except() and join() don't matter */
    public function builderWritesChooseRecordsBySortOffsetAndLimit(): void
    {
        $rows = [['n' => 'a', 's' => 30, 'grp' => 'x'], ['n' => 'b', 's' => 10, 'grp' => 'y'], ['n' => 'c', 's' => 50, 'grp' => 'x'],
            ['n' => 'd', 's' => 20, 'grp' => 'y'], ['n' => 'e', 's' => 50, 'grp' => 'x'], ['n' => 'f', 's' => 40, 'grp' => 'y'],
            ['n' => 'g', 's' => 10, 'grp' => 'x'], ['n' => 'h', 's' => 60, 'grp' => 'y']];
        foreach ([['shardingEnabled' => false], ['shardSize' => 3]] as $i => $config) {
            $db = $this->db($config);
            $name = 'wr' . $i;
            $db->insert($name, $rows);
            $db->insert('other', [['n' => 'h', 'extra' => 1]]);
            // desc by s: h(7) 60, c(2) 50, e(4) 50, f(5) 40, ...: the first two are 7 and 2
            $this->assertSame(['n' => 2], $db->query($name)->sort('s', 'desc')->limit(2)->select(['n'])->update(['top' => true]));
            $this->assertSame([2, 7], self::keysOf($db->find($name, ['top' => true])));
            // grp x by s: g(6) 10, a(0) 30, c(2) 50, e(4) 50; offset 1, limit 2: 0 and 2
            $this->assertSame(['n' => 2], $db->query($name)->where(['grp' => 'x'])->sort('s')->offset(1)->limit(2)->except(['s'])->delete());
            $this->assertSame([1, 3, 4, 5, 6, 7], self::keysOf($db->find($name, 0)));
            // n desc among b, d, e, f, g, h: h, g, f
            $this->assertSame(['n' => 3, 'fields_removed' => ['s']],
                $db->query($name)->sort('n', 'desc')->limit(3)->select(['n'])->join('other', 'n', 'n')->removeFields(['s', 'nothere']));
            $this->assertSame([1, 3, 4], self::keysOf($db->query($name)->where(['s' => ['$exists' => true]])->get()));
            $this->assertSame(self::canon([['n' => 'h', 'grp' => 'y', 'top' => true, 'key' => 7]]), self::canon($db->find($name, ['key' => 7])));
            // Nothing selected: nothing written
            $this->assertSame(['n' => 0], $db->query($name)->offset(10)->update(['z' => 1]));
            $this->assertSame(['n' => 0], $db->query($name)->limit(0)->delete());
            $this->assertSame(['n' => 0], $db->query($name)->where(['n' => 'zz'])->update(['z' => 1]));
            $this->assertSame([], $db->find($name, ['z' => 1]));
            // An empty set changes nothing and reports the matches (API.md update())
            $this->assertSame(['n' => 3], $db->query($name)->where(['grp' => 'y'])->limit(3)->update([]));
            // "key" can't be set
            $this->assertSame(['n' => 0, 'error' => 'Please check your update parameters'], $db->query($name)->update(['key' => 5]));
            $this->assertSame([1, 3, 4, 5, 6, 7], self::keysOf($db->find($name, 0)));
            $db->dropDB('other');
        }
    }

    /** @test update(), delete() and removeFields() with groupBy() throw LogicException and write nothing */
    public function builderWritesRejectGroupBy(): void
    {
        $db = $this->db();
        $db->insert('lg', [['g' => 1, 'a' => 1], ['g' => 2, 'a' => 2]]);
        $before = $db->find('lg', 0);
        $writes = [
            'update' => function ($q) { return $q->update(['a' => 9]); },
            'delete' => function ($q) { return $q->delete(); },
            'removeFields' => function ($q) { return $q->removeFields(['a']); },
        ];
        foreach ($writes as $label => $write) {
            try {
                $write($db->query('lg')->groupBy('g'));
                $this->fail("$label with groupBy() must throw");
            } catch (\LogicException $e) {
                $this->assertSame(\LogicException::class, get_class($e), $label);
                $this->assertSame('noneDB: update(), delete() and removeFields() cannot be combined with groupBy()', $e->getMessage());
            }
        }
        $this->assertSame($before, $db->find('lg', 0));
    }

    // ======================================================================
    // Error results and exceptions (API.md insert/update/delete/find; QUERY.md "How values compare")
    // ======================================================================

    /** @test insert(): the documented error results; nothing of a rejected call is written */
    public function insertErrorResults(): void
    {
        $db = $this->db();
        $db->insert('ins', ['a' => 1]);
        $json = 'insert data cannot be encoded as JSON (invalid UTF-8, NAN/INF or unsupported value)';
        $cases = [
            ['not an array', 'insert data must be array'],
            [5, 'insert data must be array'],
            [null, 'insert data must be array'],
            [[], 'insert data must not be empty'],
            [[0 => ['a' => 1], 'name' => 'x'], 'insert data must be either one record or a list of records'],
            [['key' => 'value'], 'You cannot set key name to key'],
            [[['a' => 2], ['key' => 3]], 'You cannot set key name to key'],
            [['v' => NAN], $json],
            [['v' => INF], $json],
            [['v' => "\xff\xfe"], $json],
            [[['a' => 2], ['nested' => ['deep' => -INF]]], $json],
        ];
        foreach ($cases as [$data, $error]) {
            $this->assertSame(['n' => 0, 'error' => $error], $db->insert('ins', $data), var_export($data, true));
        }
        $this->assertSame([['a' => 1, 'key' => 0]], $db->find('ins', 0), 'nothing written');
        // A nested "key" is fine, floats keep their type, keys continue
        $this->assertSame(['n' => 2], $db->insert('ins', [['data' => ['key' => 'v']], ['f' => 2.0]]));
        $this->assertSame(self::canon([['a' => 1, 'key' => 0], ['data' => ['key' => 'v'], 'key' => 1], ['f' => 2.0, 'key' => 2]]), self::canon($db->find('ins', 0)));
    }

    /** @test update()/delete(): the documented error results; nothing is written */
    public function updateAndDeleteErrorResults(): void
    {
        $db = $this->db();
        $db->insert('ud', [['n' => 'a'], ['n' => 'b']]);
        $bad = 'Please check your update parameters';
        foreach ([
            'x', 5, null, [], [1 => ['set' => ['x' => 1]]], [['n' => 'a']], [['n' => 'a'], 'set'], [['n' => 'a'], ['set' => 'str']],
            [['n' => 'a'], ['set' => ['key' => 5]]], ['n', ['set' => ['x' => 1]]], [['n' => 'a'], ['put' => ['x' => 1]]], [['n' => 'a'], ['set' => null]],
        ] as $data) {
            $this->assertSame(['n' => 0, 'error' => $bad], $db->update('ud', $data), var_export($data, true));
        }
        $result = $db->update('ud', [['n' => 'a'], ['set' => ['x' => NAN]]]);
        $this->assertSame(0, $result['n']);
        $this->assertStringStartsWith('update data cannot be encoded as JSON', $result['error']);
        $this->assertSame(['n' => 1], $db->update('ud', [['n' => 'a'], ['set' => []]]), 'empty set: the number of matches');
        foreach (['bad', 0, null, 1.5, true] as $data) {
            $this->assertSame(['n' => 0, 'error' => 'Please check your delete parameters'], $db->delete('ud', $data), var_export($data, true));
        }
        $this->assertSame([['n' => 'a', 'key' => 0], ['n' => 'b', 'key' => 1]], $db->find('ud', 0), 'nothing written');
        $this->assertSame(['n' => 2], $db->update('ud', [[], ['set' => ['all' => 1]]]));
        $this->assertSame(['n' => 2], $db->delete('ud', []), 'an empty filter matches everything');
        $this->assertSame([], $db->find('ud', 0));
    }

    /** @test exceptions: mixed operator specs at where()/orWhere()/whereNot() call, the rest when the query runs (also on an empty or missing database) */
    public function filterExceptionsAreThrownAtTheDocumentedTime(): void
    {
        $db = $this->db();
        $db->insert('full', [['a' => 1], ['a' => 2]]);
        $db->insert('empty', ['a' => 1]);
        $db->delete('empty', []);
        $mix = 'noneDB: a filter value cannot mix operators ($gt, $in, ...) with plain keys';
        foreach (['where', 'orWhere', 'whereNot'] as $method) {
            try {
                $db->query('full')->$method(['a' => ['$gt' => 1, 'b' => 2]]);
                $this->fail("$method must reject a mixed spec when it is called");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($mix, $e->getMessage(), $method);
            }
        }
        $runners = [
            'get' => function ($q) { return $q->get(); }, 'count' => function ($q) { return $q->count(); },
            'first' => function ($q) { return $q->first(); }, 'last' => function ($q) { return $q->last(); },
            'exists' => function ($q) { return $q->exists(); }, 'sum' => function ($q) { return $q->sum('a'); },
            'avg' => function ($q) { return $q->avg('a'); }, 'min' => function ($q) { return $q->min('a'); },
            'max' => function ($q) { return $q->max('a'); }, 'distinct' => function ($q) { return $q->distinct('a'); },
            'update' => function ($q) { return $q->update(['z' => 1]); }, 'delete' => function ($q) { return $q->delete(); },
            'removeFields' => function ($q) { return $q->removeFields(['a']); },
        ];
        $specs = [
            [['$gtee' => 1], "noneDB: unknown operator '\$gtee' (supported: \$gt, \$gte, \$lt, \$lte, \$ne, \$eq, \$in, \$nin, \$exists, \$like, \$regex, \$contains)"],
            [['$in' => 'notarray'], 'noneDB: $in needs an array'],
            [['$nin' => 5], 'noneDB: $nin needs an array'],
            [['$regex' => '(unclosed'], "noneDB: invalid \$regex pattern '(unclosed'"],
            [['$regex' => ''], 'noneDB: $regex needs a non-empty string pattern'],
            [['$regex' => 5], 'noneDB: $regex needs a non-empty string pattern'],
            [['$like' => ['x']], 'noneDB: like pattern must be a string'],
        ];
        foreach (['full', 'empty', 'missing'] as $name) {
            foreach ($specs as [$spec, $message]) {
                foreach ($runners as $runner => $run) {
                    foreach (['where', 'orWhere'] as $method) {
                        $label = "$name $method " . json_encode($spec) . " $runner";
                        $query = $db->query($name)->$method(['a' => $spec]);   // no exception yet
                        try {
                            $run($query);
                            $this->fail("$label must throw");
                        } catch (\InvalidArgumentException $e) {
                            $this->assertSame($message, $e->getMessage(), $label);
                        }
                    }
                }
                // whereNot() checks its operators when it is called
                try {
                    $db->query($name)->whereNot(['a' => $spec]);
                    $this->fail("$name whereNot " . json_encode($spec));
                } catch (\InvalidArgumentException $e) {
                    $this->assertSame($message, $e->getMessage());
                }
            }
        }
        $this->assertSame([['a' => 1, 'key' => 0], ['a' => 2, 'key' => 1]], $db->find('full', 0), 'failed writes wrote nothing');
        $this->assertFalse($db->getDBs('missing'));
        // Invalid database names
        foreach (['bad!', 'a/b', '', 'x_y'] as $bad) {
            try {
                $db->query($bad);
                $this->fail("query('$bad')");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('invalid database name', $e->getMessage());
            }
        }
    }

    /** @test a $regex that exceeds PCRE's backtracking limit is reported, never taken as "no match" */
    public function regexRuntimeFailureIsReported(): void
    {
        $db = $this->db();
        $db->insert('rx', [['s' => str_repeat('a', 40) . 'b'], ['s' => 'aab']]);
        $saved = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.backtrack_limit', '100');
        ini_set('pcre.jit', '0');
        try {
            try {
                $db->query('rx')->where(['s' => ['$regex' => '^(a+)+$']])->get();
                $this->fail('a failed regex match must throw');
            } catch (\RuntimeException $e) {
                $this->assertNotInstanceOf(\InvalidArgumentException::class, $e);
                $this->assertStringStartsWith('noneDB: regular expression failed', $e->getMessage());
            }
        } finally {
            ini_set('pcre.backtrack_limit', (string)$saved);
            ini_set('pcre.jit', (string)$jit);
        }
        $this->assertSame([1], self::keysOf($db->query('rx')->where(['s' => ['$regex' => '^A{2}B$']])->get()), 'case-insensitive');
    }

    // ======================================================================
    // Key filters (QUERY.md: "A key filter ... means the same in every clause"; API.md find())
    // ======================================================================

    /** @test key filters: integers and canonical digit strings only, in find(), where(), orWhere(), whereNot() and with spatial filters */
    public function keyFiltersMeanTheSameEverywhere(): void
    {
        $values = [5, '5', [5, '1'], [3, 3, '3'], [4, '0', 2], '05', ' 5', '5 ', "5\n", -1, '-1', 5.0, true, false, null, '', [], ['x', 2], 99, '+5', '5.0', '1e0'];
        foreach ([['shardingEnabled' => false], ['shardSize' => 2]] as $i => $config) {
            $db = $this->db($config);
            $name = 'kf' . $i;
            $rows = [];
            foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $j => $n) {
                $rows[] = ['n' => $n, 'loc' => ['type' => 'Point', 'coordinates' => [$j * 0.001, 0]]];
            }
            $db->insert($name, $rows);
            $all = [0, 1, 2, 3, 4, 5];
            foreach ($values as $value) {
                $label = "$name key " . var_export($value, true);
                $listed = array_values(array_filter(self::refKeyList($value), function ($k) { return $k <= 5; }));
                $sorted = $listed;
                sort($sorted);
                $this->assertSame($listed, self::keysOf($db->find($name, ['key' => $value])), "find $label (key-list order)");
                $this->assertSame($listed, self::keysOf($db->query($name)->where(['key' => $value])->get()), "where $label");
                $this->assertSame(count($listed), $db->count($name, ['key' => $value]), "count $label");
                $this->assertSame(count($listed), $db->query($name)->where(['key' => $value])->count(), "builder count $label");
                $this->assertSame($sorted, self::keysOf($db->query($name)->where(['key' => $value])->where(['n' => ['$ne' => 'zz']])->get()), "two where() $label");
                $this->assertSame($sorted, self::keysOf($db->query($name)->orWhere(['key' => $value])->get()), "orWhere $label");
                $this->assertSame($sorted, self::keysOf($db->query($name)->where(['n' => 'zz'])->orWhere(['key' => $value])->get()), "where OR key $label");
                $this->assertSame(array_values(array_diff($all, $sorted)), self::keysOf($db->query($name)->whereNot(['key' => $value])->get()), "whereNot $label");
                $spatial = self::keysOf($db->query($name)->withinBBox('loc', -1, -1, 1, 1)->where(['key' => $value])->get());
                sort($spatial);
                $this->assertSame($sorted, $spatial, "spatial + where $label");
            }
        }
    }

    /** @test $eq and $in on key mean what the plain key filter means ("$eq: explicit equality, same as simple value") */
    public function keyOperatorsCompareStrictlyThePlainKeyFilterNormalizes(): void
    {
        // QUERY.md "How values compare": a plain key filter means what it means in find() ("1" is key 1);
        // $eq and $in compare strictly on key as on any field ("1" is not the integer key 1)
        $db = $this->db();
        $db->insert('ko', [['n' => 'a'], ['n' => 'b'], ['n' => 'c']]);
        $this->assertSame([1], self::keysOf($db->query('ko')->where(['key' => ['$eq' => 1]])->get()));
        $this->assertSame([0, 2], self::keysOf($db->query('ko')->where(['key' => ['$in' => [2, 0]]])->get()));
        $this->assertSame([1], self::keysOf($db->query('ko')->where(['key' => '1'])->get()), 'plain "1" is key 1');
        $this->assertSame([], self::keysOf($db->query('ko')->where(['key' => ['$eq' => '1']])->get()), '$eq is strict');
        $this->assertSame([2], self::keysOf($db->query('ko')->where(['key' => ['$in' => ['1', 2]]])->get()), '$in is strict');
        $this->assertSame([0, 2], self::keysOf($db->query('ko')->where(['key' => ['$ne' => 1]])->get()), '$ne is strict');
        $this->assertSame([0, 1, 2], self::keysOf($db->query('ko')->where(['key' => ['$ne' => '1']])->get()));
    }

    // ======================================================================
    // like() / search() folding (QUERY.md "Pattern Matching", "search()")
    // ======================================================================

    private function setMbstringHook(?callable $hook): void
    {
        $property = new \noneDB\Tests\InternalValue('mbstringFn');
        $property->setValue(null, $hook);
    }

    private function seedFolding(\noneDB $db, string $name): void
    {
        $db->insert($name, [
            ['t' => 'PHP Tutorial', 'body' => 'learn php', 'num' => 30, 'tags' => ['php', 'web']],   // 0
            ['t' => 'tutorial on php', 'body' => 'x'],                                               // 1
            ['t' => 'İzmir guide', 'body' => 'STRASSE'],                                             // 2
            ['t' => 'Ankara', 'flag' => true, 'num' => 1],                                           // 3
            ['t' => 'IZMIR', 'body' => 'straße'],                                                    // 4
            ['t' => 'izmir'],                                                                        // 5
            ['t' => 'nothing', 'meta' => ['note' => 'php inside']],                                  // 6
            ['t' => 'KIRMIZI', 'body' => 'Ğüneş'],                                                   // 7
        ]);
    }

    /** @test search(): whole term, scalar values only, key only when listed, Unicode case folding (mbstring) */
    public function searchMatchesTheWholeTermWithUnicodeFolding(): void
    {
        if (!function_exists('mb_convert_case')) {
            $this->markTestSkipped('needs mbstring (the no-mbstring rules are tested with the hook below)');
        }
        $db = $this->db(['shardSize' => 3]);
        $this->seedFolding($db, 'se');
        $s = function (string $term, array $fields = []) use ($db) { return self::keysOf($db->query('se')->search($term, $fields)->get()); };
        $this->assertSame([0], $s('php tutorial'), 'no word splitting: "tutorial on php" doesn\'t match');
        $this->assertSame([0, 1], $s('PHP'), 'nested arrays (tags, meta) are not searched');
        $this->assertSame([0], $s('php', ['body']));
        $this->assertSame([], $s('php', ['tags']), 'array values are not scalar values');
        $this->assertSame([0], $s('30'), 'numbers are searched');
        $this->assertSame([3], $s('1'), 'key 1 is not searched without a field list');
        $this->assertSame([2], $s('2', ['key']), 'key is searched when listed');
        $this->assertSame([2, 4, 5], $s('izmir'));
        $this->assertSame([2, 4, 5], $s('İZMİR'));
        $this->assertSame([2, 4], $s('strasse'));
        $this->assertSame([2, 4], $s('STRAßE'));
        $this->assertSame([7], $s('ğÜNEŞ'));
        $this->assertSame([], $s('kırmızı'), 'dotless ı and I don\'t match each other');
        $this->assertSame([7], $s('kirmizi'));
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7], $s(''), 'the empty term is in every value');
        $this->assertSame([5], self::keysOf($db->query('se')->search('izmir')->where(['body' => ['$exists' => false]])->get()));
        // 2: "İzmir guide"/"STRASSE", 4: "IZMIR"/"straße"; 5 has no body
        $this->assertSame([2, 4], self::keysOf($db->query('se')->search('izmir')->search('strasse')->get()), 'several search() calls all apply');
        $this->assertSame([2, 4], self::keysOf($db->query('se')->search('ß', ['body'])->get()), 'ß folds to ss: "STRASSE" contains it');
        $this->assertSame([2], self::keysOf($db->query('se')->search('izmir')->search('strasse')->search('GUIDE')->get()));
    }

    /** @test like()/$like/noneDB::like(): Unicode folding with mbstring; ß = ss, İ = i, ı != I */
    public function likeFoldsUnicodeCase(): void
    {
        if (!function_exists('mb_convert_case')) {
            $this->markTestSkipped('needs mbstring (the no-mbstring rules are tested with the hook below)');
        }
        $db = $this->db();
        $this->seedFolding($db, 'lf');
        $like = function (string $field, string $pattern) use ($db) {
            $a = self::keysOf($db->query('lf')->like($field, $pattern)->get());
            $this->assertSame($a, self::keysOf($db->query('lf')->where([$field => ['$like' => $pattern]])->get()), "\$like $pattern");
            $this->assertSame($a, self::keysOf($db->like('lf', $field, $pattern)), "noneDB::like $pattern");
            return $a;
        };
        $this->assertSame([2, 4, 5], $like('t', 'izmir'));
        $this->assertSame([2, 4, 5], $like('t', '^İZM'));
        $this->assertSame([4, 5], $like('t', 'İzmir$'), '"İzmir guide" doesn\'t end with it');
        $this->assertSame([2, 4], $like('body', 'strasse'));
        $this->assertSame([2, 4], $like('body', '^STRAßE$'));
        $this->assertSame([7], $like('body', 'ğüneş'));
        $this->assertSame([], $like('t', 'kırmızı'));
        $this->assertSame([7], $like('t', 'KİRMİZİ'), 'İ folds to i');
    }

    /** @test without mbstring: like() uses PCRE /iu (misses ß/ss and İ/i), search() folds only ASCII letters */
    public function foldingWithoutMbstring(): void
    {
        $this->setMbstringHook(function () { return false; });
        try {
            $db = $this->db();
            $this->seedFolding($db, 'nm');
            $like = function (string $field, string $pattern) use ($db) {
                $a = self::keysOf($db->query('nm')->like($field, $pattern)->get());
                $this->assertSame($a, self::keysOf($db->like('nm', $field, $pattern)), "noneDB::like $pattern");
                return $a;
            };
            $this->assertSame([4, 5], $like('t', 'izmir'), 'İ is not i without mbstring');
            $this->assertSame([2], $like('body', 'strasse'), 'ß is not ss without mbstring');
            $this->assertSame([7], $like('body', 'ğüneş'), 'PCRE /iu folds Ğ/ğ');
            $this->assertSame([0, 1], $like('t', 'php'));
            $s = function (string $term) use ($db) { return self::keysOf($db->query('nm')->search($term)->get()); };
            $this->assertSame([4, 5], $s('izmir'));
            $this->assertSame([2], $s('strasse'));
            $this->assertSame([], $s('ğüneş'), 'only ASCII letters are folded');
            $this->assertSame([7], $s('Ğüneş'));
            $this->assertSame([0, 1], $s('pHp'));
        } finally {
            $this->setMbstringHook(null);
        }
    }

    /** @test like()'s end anchor means "ends with": a trailing newline is not the end */
    public function likeEndAnchorMeansEndsWith(): void
    {
        $db = $this->db();
        $db->insert('nl', [['n' => 'Jackson'], ['n' => "Jackson\n"], ['n' => 'abc'], ['n' => "abc\n"]]);
        $this->assertSame([0], self::keysOf($db->query('nl')->like('n', 'son$')->get()), 'QUERY.md: names ending with "son"');
        $this->assertSame([0], self::keysOf($db->like('nl', 'n', 'son$')));
        $this->assertSame([2], self::keysOf($db->query('nl')->where(['n' => ['$like' => '^abc$']])->get()));
        $this->assertSame([1, 3], self::keysOf($db->query('nl')->notLike('n', 'son$')->notLike('n', '^abc$')->get()));
    }

    /** @test noneDB::like(): booleans never match; a null or array pattern returns false; numbers are matched as text */
    public function noneDbLikeArguments(): void
    {
        $db = $this->db();
        $db->insert('la', [['f' => true], ['f' => 1], ['f' => false], ['f' => ''], ['f' => '1'], ['f' => [1]], ['f' => null], ['f' => 2.5], ['g' => 1]]);
        $this->assertSame([1, 4], self::keysOf($db->like('la', 'f', '1')));
        $this->assertSame([1, 4], self::keysOf($db->like('la', 'f', 1)), 'a number pattern is its text');
        $this->assertSame([7], self::keysOf($db->like('la', 'f', '.5')));
        $this->assertSame([1, 3, 4, 7], self::keysOf($db->like('la', 'f', '')));
        $this->assertFalse($db->like('la', 'f', null));
        $this->assertFalse($db->like('la', 'f', ['1']));
        $this->assertSame([], $db->like('la', 'nothere', ''));
        $this->assertSame([], $db->like('ghost', 'f', 'x'));
        $this->assertFalse($this->db(['autoCreateDB' => false])->like('ghost', 'f', 'x'));
        // The builder: a non-string $like operand is rejected
        try {
            $db->query('la')->where(['f' => ['$like' => null]])->get();
            $this->fail('null pattern');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('noneDB: like pattern must be a string', $e->getMessage());
        }
    }

    // ======================================================================
    // The same answer on every PHP version (query results that don't depend on the PHP version)
    // ======================================================================

    /** @test numeric strings with trailing whitespace are numbers for comparisons, between() and sums, as they are for sort() */
    public function trailingWhitespaceNumericStringsAreNumbersOnEveryPhpVersion(): void
    {
        $db = $this->db();
        $db->insert('ws', [['v' => '7 '], ['v' => 7], ['v' => ' 7'], ['v' => '7'], ['v' => "7\n"], ['v' => 'abc '], ['v' => "8\t"]]);
        // sort(): the number 7, then the numeric strings 7 (ties in key order) and 8, then "abc "
        $this->assertSame([1, 0, 2, 3, 4, 6, 5], self::keysOf($db->query('ws')->sort('v')->get()), 'sort(): documented on every PHP version');
        $this->assertSame([0, 1, 2, 3, 4, 6], self::keysOf($db->query('ws')->where(['v' => ['$gt' => 6]])->get()), '$gt');
        $this->assertSame([0, 1, 2, 3, 4], self::keysOf($db->query('ws')->where(['v' => ['$lt' => 8]])->get()), '$lt');
        $this->assertSame([0, 1, 2, 3, 4], self::keysOf($db->query('ws')->between('v', 7, 7)->get()), 'between()');
        $this->assertSame([0, 1, 2, 3, 4], self::keysOf($db->between('ws', 'v', 7, 7)), 'noneDB::between()');
        $this->assertSame(43.0, $db->query('ws')->sum('v'), 'sum: 7 * 5 + 8');
        $this->assertNumber(43, $db->sum('ws', 'v'));
        $this->assertSame(43 / 6, $db->query('ws')->avg('v'));
        $this->assertSame(7, $db->query('ws')->min('v'));
        $this->assertSame("8\t", $db->query('ws')->where(['v' => ['$ne' => 'abc ']])->max('v'));
    }

    // ======================================================================
    // Spatial filters in the builder, explain(), unusual membership values
    // ======================================================================

    /** @test nearest(k <= 0) returns nothing; several spatial filters must all match; _distance comes from withinDistance() */
    public function spatialFiltersCombineInTheBuilder(): void
    {
        foreach ([false, true] as $indexed) {
            $db = $this->db();
            $name = $indexed ? 'spi' : 'sp';
            $rows = [];
            for ($i = 0; $i < 10; $i++) {
                $rows[] = ['i' => $i, 'loc' => ['type' => 'Point', 'coordinates' => [$i * 0.01, 0]]];
            }
            $db->insert($name, $rows);
            if ($indexed) {
                $this->assertTrue($db->createSpatialIndex($name, 'loc')['success']);
            }
            $this->assertSame([], $db->query($name)->nearest('loc', 0, 0, 0)->get());
            $this->assertSame([], $db->query($name)->nearest('loc', 0, 0, -3)->get());
            $this->assertSame(0, $db->query($name)->nearest('loc', 0, 0, 0)->count());
            $this->assertFalse($db->query($name)->nearest('loc', 0, 0, 0)->exists());
            // Box: longitudes 0.015..0.075 = i 2..7; within 5000 m of (0, 0): i 0..4 (0.01 degree on the equator = 1111.95 m)
            $meters = function (int $i) { return 6371000.0 * deg2rad($i * 0.01); };
            foreach ([
                $db->query($name)->withinBBox('loc', 0.015, -1, 0.075, 1)->withinDistance('loc', 0, 0, 5000),
                $db->query($name)->withinDistance('loc', 0, 0, 5000)->withinBBox('loc', 0.015, -1, 0.075, 1),
            ] as $n => $query) {
                $rowsFound = $query->get();
                $keys = self::keysOf($rowsFound);
                sort($keys);
                $this->assertSame([2, 3, 4], $keys, "$name order $n");
                foreach ($rowsFound as $row) {
                    $this->assertEqualsWithDelta($meters($row['i']), $row['_distance'], 0.011, "$name order $n: meters to withinDistance()'s center");
                }
                $plan = $query->explain();
                $this->assertSame('spatial', $plan['access']);
                $this->assertFalse($plan['earlyExit']);
            }
            $this->assertSame(['withinBBox', 'withinDistance'],
                $db->query($name)->withinBBox('loc', 0, 0, 1, 1)->withinDistance('loc', 0, 0, 1)->explain()['spatial']);
            // nearest() among other filters: its k after the where() filter, nearest first
            $this->assertSame([5, 7], self::keysOf($db->query($name)->nearest('loc', 0.051, 0, 2)->where(['i' => ['$in' => [1, 5, 7, 9]]])->get()));
        }
    }

    /** @test explain(): "access" is index, scan, mixed (one shard's index file missing), none (nothing to read) or spatial */
    public function explainReportsHowTheQueryReads(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['v' => $i % 3, 'w' => $i];
        }
        $db->insert('ex', $rows);
        $this->assertTrue($db->createFieldIndex('ex', 'v')['success']);
        $plan = $db->query('ex')->where(['v' => 1])->explain();
        $this->assertSame('index', $plan['access']);
        $this->assertSame(['total' => 3, 'skipped' => 0, 'indexed' => 3, 'scanned' => 0], $plan['segments']);
        $this->assertSame(4, $plan['estimatedRead'], 'keys 1, 4, 7, 10');
        $this->assertTrue($plan['sharded']);
        $this->assertSame('scan', $db->query('ex')->where(['w' => 5])->explain()['access']);
        $this->assertSame('none', $db->query('ex')->where(['v' => 5])->explain()['access'], 'the global index rules every shard out');
        $this->assertSame('none', $db->query('nodb')->where(['v' => 1])->explain()['access']);
        $keys = $db->query('ex')->where(['key' => [1, 9]])->explain();
        $this->assertSame('index', $keys['access']);
        $this->assertSame(['total' => 3, 'skipped' => 1, 'indexed' => 2, 'scanned' => 0], $keys['segments']);
        $this->assertSame(['keys-in-filter-order'], $keys['filter']);
        $this->assertSame('spatial', $db->query('ex')->withinBBox('w', 0, 0, 1, 1)->explain()['access']);
        $this->assertDirectoryDoesNotExist($this->dbPath('nodb', 'home'), 'explain() builds and creates nothing');

        // Shard 1 loses its field index file: that shard is scanned until the index is built again
        $file = $this->dbPath('ex', 'fx', 'v', 1);
        $this->assertFileExists($file);
        \noneDB::clearStaticCache();
        $this->assertTrue(unlink($file));
        $db = $this->db(['shardSize' => 4]);
        $plan = $db->query('ex')->where(['v' => 1])->explain();
        $this->assertSame('mixed', $plan['access']);
        $this->assertSame(['total' => 3, 'skipped' => 0, 'indexed' => 2, 'scanned' => 1], $plan['segments']);
        $this->assertFileDoesNotExist($file, 'explain() builds nothing');
        $analyzed = $db->query('ex')->where(['v' => 1])->explain(true);
        $this->assertSame(4, $analyzed['actual']['returned']);
        $this->assertSame([1, 4, 7, 10], self::keysOf($db->query('ex')->where(['v' => 1])->get()), 'the result never depends on the indexes');
    }

    /** @test long membership lists (hash set) with objects and arrays: strict, an object equals no stored value */
    public function longMembershipListsWithUnusualValues(): void
    {
        $db = $this->db();
        $db->insert('ob', [['v' => 1], ['v' => []], ['v' => ['a' => 1]], ['v' => '1'], ['v' => null], ['w' => 1]]);
        $list = [new \stdClass(), 1, 2, 3, 4, 5, 6, 7, 8];
        $this->assertSame([0], self::keysOf($db->query('ob')->whereIn('v', $list)->get()));
        $this->assertSame([1, 2, 3, 4, 5], self::keysOf($db->query('ob')->whereNotIn('v', $list)->get()));
        $this->assertSame([0], self::keysOf($db->query('ob')->where(['v' => ['$in' => $list]])->get()));
        $this->assertSame([1, 2, 3, 4, 5], self::keysOf($db->query('ob')->where(['v' => ['$nin' => $list]])->get()));
        $arrays = [1, [], ['a' => 1], 'x', 'y', 'z', 'w', 'q', 'r'];
        $this->assertSame([0, 1, 2], self::keysOf($db->query('ob')->whereIn('v', $arrays)->get()), 'arrays by value, in a long list');
        $this->assertSame([0, 1, 2], self::keysOf($db->query('ob')->whereIn('v', array_slice($arrays, 0, 3))->get()), 'and in a short one');
        $this->assertSame([], self::keysOf($db->query('ob')->whereIn('v', [['a' => '1'], [1 => 'a'], 1.0, true, 'A', 0, -1, 9, 10])->get()));
    }
}
