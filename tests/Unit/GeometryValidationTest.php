<?php

namespace noneDB\Tests\Unit;

use noneDB\Tests\RegressionTestCase;

/**
 * Polygon validation cost and exactness: rings built to defeat the sort-and-sweep check and
 * polygons with many holes cost O(n log n), not O(n^2), and the result equals the all-pairs definition:
 * a ring is valid when no two non-adjacent edges share a point, a hole when each of its vertices is
 * inside or on the outer ring (exact arithmetic).
 */
class GeometryValidationTest extends RegressionTestCase
{
    private function counter(string $name): \noneDB\Tests\InternalValue
    {
        $property = new \noneDB\Tests\InternalValue($name);
        return $property;
    }

    /** Budget of the quick checks before the sweep takes over (null = default, 0 = always the sweep) */
    private function setSweepBudget(?int $budget): void
    {
        $this->counter('sweepBudget')->setValue(null, $budget);
    }

    protected function tearDown(): void
    {
        if (property_exists(\noneDB::class, 'sweepBudget')) {
            $this->setSweepBudget(null);
        }
        parent::tearDown();
    }

    private function call(string $method, ...$args)
    {
        return \noneDB\Tests\Support\Testing::call($this->db(), $method, ...$args);
    }

    private const STEP = 1 / 64;

    /**
     * A comb: $teeth edges zigzag between x = 0 and x = 100, STEP apart in y (every edge overlaps every
     * other in x, the sort-and-sweep compared all pairs), closed around the right side
     */
    private static function comb(int $teeth): array
    {
        $ring = [];
        for ($i = 0; $i < $teeth; $i++) {
            $ring[] = [$i % 2 === 0 ? 0 : 100, $i * self::STEP];
        }
        $last = ($teeth - 1) * self::STEP;
        $ring[] = [150, $last];
        $ring[] = [150, -1];
        $ring[] = [0, -1];
        $ring[] = $ring[0];
        return $ring;
    }

    private static function log2(int $n): float
    {
        return log(max(2, $n), 2);
    }

    /** @test */
    public function ringBuiltAgainstTheSweepIsCheckedInNearLinearWork(): void
    {
        $pairs = $this->counter('segmentPairTests');
        $db = $this->db();
        $n = 3000;
        $ring = $this->comb($n);
        $edges = count($ring) - 1;

        $pairs->setValue(null, 0);
        $this->assertTrue($db->validateGeoJSON(['type' => 'Polygon', 'coordinates' => [$ring]])['valid']);
        $this->assertLessThan(40 * $edges * self::log2($edges), $pairs->getValue(),
            'edge pairs compared (all pairs: about 4,500,000)');

        // The same comb with one tooth reaching into the next one: found, also at the far end
        foreach ([3, (int)($n / 2), $n - 3] as $tooth) {
            $bad = $ring;
            $bad[$tooth][1] += 2.5 * self::STEP; // above the vertex two steps up: crosses the edge leaving it
            $pairs->setValue(null, 0);
            $this->assertFalse($db->validateGeoJSON(['type' => 'Polygon', 'coordinates' => [$bad]])['valid'], "tooth $tooth");
            $this->assertLessThan(40 * $edges * self::log2($edges), $pairs->getValue());
        }
    }

    /** @test */
    public function manyHolesAreCheckedInNearLinearWork(): void
    {
        $points = $this->counter('pointEdgeTests');
        $db = $this->db();
        $outer = [];
        $n = 4000;
        for ($i = 0; $i < $n; $i++) {
            $outer[] = [cos(2 * M_PI * $i / $n) * 50, sin(2 * M_PI * $i / $n) * 50];
        }
        $outer[] = $outer[0];
        $holes = [];
        for ($i = 0; $i < 30; $i++) {
            for ($j = 0; $j < 30; $j++) {
                $x = -30 + 2 * $i;
                $y = -30 + 2 * $j;
                $holes[] = [[$x, $y], [$x + 1, $y], [$x, $y + 1], [$x, $y]];
            }
        }
        $vertices = $n + 4 * count($holes);

        $points->setValue(null, 0);
        $this->assertTrue($db->validateGeoJSON(['type' => 'Polygon', 'coordinates' => array_merge([$outer], $holes)])['valid']);
        $this->assertGreaterThan(0, $points->getValue());
        $this->assertLessThan(40 * $vertices * self::log2($vertices), $points->getValue(),
            'point-edge tests (each hole vertex against every edge: about 28,800,000)');

        $holes[450] = [[60, 0], [61, 0], [60, 1], [60, 0]];
        $points->setValue(null, 0);
        $result = $db->validateGeoJSON(['type' => 'Polygon', 'coordinates' => array_merge([$outer], $holes)]);
        $this->assertFalse($result['valid']);
        $this->assertSame('Hole 451 must lie inside the outer ring', $result['error']);
        $this->assertLessThan(40 * $vertices * self::log2($vertices), $points->getValue());
    }

    /** All-pairs reference (exact for the small integers and dyadic values used here) */
    private static function refRingIntersects(array $ring): bool
    {
        $n = count($ring) - 1;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $d = $j - $i;
                if ($d === 1 || $d === $n - 1) {
                    continue;
                }
                if (self::refMeet($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function refOrient(array $a, array $b, array $c): int
    {
        $v = ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
        return $v <=> 0;
    }

    private static function refOnBox(array $a, array $b, array $p): bool
    {
        return min($a[0], $b[0]) <= $p[0] && $p[0] <= max($a[0], $b[0]) && min($a[1], $b[1]) <= $p[1] && $p[1] <= max($a[1], $b[1]);
    }

    private static function refMeet(array $p, array $q, array $r, array $s): bool
    {
        $o1 = self::refOrient($p, $q, $r);
        $o2 = self::refOrient($p, $q, $s);
        $o3 = self::refOrient($r, $s, $p);
        $o4 = self::refOrient($r, $s, $q);
        if ($o1 * $o2 < 0 && $o3 * $o4 < 0) {
            return true;
        }
        return ($o1 === 0 && self::refOnBox($p, $q, $r)) || ($o2 === 0 && self::refOnBox($p, $q, $s))
            || ($o3 === 0 && self::refOnBox($r, $s, $p)) || ($o4 === 0 && self::refOnBox($r, $s, $q));
    }

    /** 1 inside, 0 on the boundary, -1 outside (winding number, horizontal ray) */
    private static function refLocate(array $p, array $ring): int
    {
        $n = count($ring) - 1;
        $winding = 0;
        for ($i = 0; $i < $n; $i++) {
            $a = $ring[$i];
            $b = $ring[$i + 1];
            if (self::refOrient($a, $b, $p) === 0 && self::refOnBox($a, $b, $p)) {
                return 0;
            }
            if ($a[1] <= $p[1]) {
                if ($b[1] > $p[1] && self::refOrient($a, $b, $p) > 0) {
                    $winding++;
                }
            } elseif ($b[1] <= $p[1] && self::refOrient($a, $b, $p) < 0) {
                $winding--;
            }
        }
        return $winding !== 0 ? 1 : -1;
    }

    private static function randomRing(int $size, int $grid): array
    {
        $ring = [];
        for ($i = 0; $i < $size; $i++) {
            $ring[] = [mt_rand(0, $grid), mt_rand(0, $grid)];
        }
        $ring[] = $ring[0];
        return $ring;
    }

    /** A star-shaped (simple) ring around ($cx, $cy) */
    private static function starRing(int $size, float $cx, float $cy, float $radius, bool $integer, bool $dyadic = false): array
    {
        $angles = [];
        for ($i = 0; $i < $size; $i++) {
            $angles[] = mt_rand(0, 1000000) / 1000000 * 2 * M_PI;
        }
        sort($angles);
        $ring = [];
        foreach ($angles as $a) {
            $r = $radius * (0.3 + 0.7 * mt_rand(0, 1000) / 1000);
            $x = $cx + $r * cos($a);
            $y = $cy + $r * sin($a);
            if ($integer) {
                $ring[] = [(int)round($x), (int)round($y)];
            } else {
                $ring[] = $dyadic ? [round($x * 4096) / 4096, round($y * 4096) / 4096] : [$x, $y]; // dyadic: exact midpoints
            }
        }
        $ring[] = $ring[0];
        return $ring;
    }

    /** @test */
    public function sweepEqualsAllPairsOnRandomRings(): void
    {
        $checked = ['valid' => 0, 'invalid' => 0];
        foreach ([null, 0] as $budget) {
            $this->setSweepBudget($budget);
            mt_srand(20261026);
            for ($round = 0; $round < 3000; $round++) {
                $kind = $round % 4;
                if ($kind === 0) {
                    $ring = self::randomRing(mt_rand(3, 12), mt_rand(2, 7));      // many collinear, touching, repeated
                } elseif ($kind === 1) {
                    $ring = self::starRing(mt_rand(3, 40), 20, 20, 15, true);      // mostly valid, some degenerate
                } elseif ($kind === 2) {
                    $ring = self::starRing(mt_rand(3, 60), 0.5, 0.25, 0.2, false); // floats
                } else {
                    $ring = self::comb(mt_rand(2, 30));
                    $k = mt_rand(0, count($ring) - 2);
                    $ring[$k] = [$ring[$k][0] + mt_rand(-2, 2) * 25, $ring[$k][1] + mt_rand(-2, 2) * self::STEP / 2];
                    if ($k === 0) {
                        $ring[count($ring) - 1] = $ring[0];
                    }
                }
                $expected = self::refRingIntersects($ring);
                $checked[$expected ? 'invalid' : 'valid']++;
                $this->assertSame($expected, $this->call('ringSelfIntersects', $ring),
                    'budget ' . var_export($budget, true) . ': ' . json_encode($ring));
            }
        }
        $this->assertGreaterThan(500, $checked['valid']);
        $this->assertGreaterThan(500, $checked['invalid']);
    }

    /** @test */
    public function holeCheckEqualsTheDefinitionOnRandomPolygons(): void
    {
        foreach ([null, 0] as $budget) {
            $this->setSweepBudget($budget);
            mt_srand(7);
            $seen = [1 => 0, 0 => 0, -1 => 0];
            for ($round = 0; $round < 400; $round++) {
                $outer = self::starRing(mt_rand(4, 30), 10, 10, 9, $round % 2 === 0, true);
                if (self::refRingIntersects($outer)) {
                    continue;
                }
                $points = [];
                for ($i = 0; $i < 40; $i++) {
                    if ($i % 5 === 0) {
                        $points[] = $outer[mt_rand(0, count($outer) - 2)];                   // a vertex
                    } elseif ($i % 5 === 1) {
                        $e = mt_rand(0, count($outer) - 2);                                  // an edge midpoint
                        $points[] = [($outer[$e][0] + $outer[$e + 1][0]) / 2, ($outer[$e][1] + $outer[$e + 1][1]) / 2];
                    } else {
                        $points[] = [mt_rand(0, 40) / 2, mt_rand(0, 40) / 2];
                    }
                }
                $expected = array_map(function ($p) use ($outer) { return self::refLocate($p, $outer); }, $points);
                foreach ($expected as $e) {
                    $seen[$e]++;
                }
                $actual = $this->call('locatePointsInRing', $outer, $points);
                $this->assertSame($expected, $actual, 'budget ' . var_export($budget, true) . ': ' . json_encode([$outer, $points]));

                // Through validateGeoJSON: holes made of these points are valid exactly when every vertex is in or on
                $hole = [$points[2], $points[3], $points[4], $points[2]];
                $valid = min($expected[2], $expected[3], $expected[4]) >= 0 && !self::refRingIntersects($hole);
                $this->assertSame($valid, $this->db()->validateGeoJSON(['type' => 'Polygon', 'coordinates' => [$outer, $hole]])['valid']);
            }
            $this->assertGreaterThan(100, min($seen));
        }
    }

    /** @test */
    public function orientationIsExact(): void
    {
        // Points near the line y = x (Shewchuk's example): naive floating-point arithmetic gets many wrong
        $eps = 2 ** -53;
        $wrongNaive = 0;
        foreach ([[0.5, 12, 24], [0.5, 17.300000000000001, 24.700000000000003], [1.0, 3, 9]] as [$base, $q, $r]) {
            for ($i = 0; $i < 64; $i++) {
                for ($j = 0; $j < 64; $j++) {
                    $px = $base + $i * $eps * ($base >= 1 ? 2 : 1);
                    $py = $base + $j * $eps * ($base >= 1 ? 2 : 1);
                    $expected = self::exactOrientSign([$q, $q], [$r, $r], [$px, $py]);
                    $this->assertSame($expected, $this->call('orient', (float)$q, (float)$q, (float)$r, (float)$r, $px, $py), "$i $j");
                    if (self::refOrient([$q, $q], [$r, $r], [$px, $py]) !== $expected) {
                        $wrongNaive++;
                    }
                }
            }
        }
        $this->assertGreaterThan(100, $wrongNaive, 'the cases are hard for plain floating point');

        mt_srand(99);
        for ($k = 0; $k < 2000; $k++) {
            $p = [];
            for ($m = 0; $m < 6; $m++) {
                $p[] = mt_rand(-(2 ** 30), 2 ** 30) * 2 ** -22 + (mt_rand(0, 1) ? 0.1 : 0);
            }
            if ($k % 2) {
                $p[4] = $p[0] + ($p[2] - $p[0]) * 0.5; // near the line
                $p[5] = $p[1] + ($p[3] - $p[1]) * 0.5;
            }
            $this->assertSame(self::exactOrientSign([$p[0], $p[1]], [$p[2], $p[3]], [$p[4], $p[5]]),
                $this->call('orient', $p[0], $p[1], $p[2], $p[3], $p[4], $p[5]), json_encode($p));
        }
    }

    /**
     * Exact sign of (b - a) x (c - a) for finite doubles, with integer arithmetic: every double is
     * m * 2^e; all values are scaled to one exponent and multiplied as base-2^24 digit lists
     */
    private static function exactOrientSign(array $a, array $b, array $c): int
    {
        $values = [$a[0], $a[1], $b[0], $b[1], $c[0], $c[1]];
        $parts = [];
        $minExp = PHP_INT_MAX;
        foreach ($values as $v) {
            [$m, $e] = self::decompose((float)$v);
            $parts[] = [$m, $e];
            if ($m !== 0) {
                $minExp = min($minExp, $e);
            }
        }
        if ($minExp === PHP_INT_MAX) {
            return 0;
        }
        $big = [];
        foreach ($parts as [$m, $e]) {
            $big[] = $m === 0 ? [0] : self::bigShift(self::bigFromInt($m), $e - $minExp);
        }
        $bx = self::bigSub($big[2], $big[0]);
        $by = self::bigSub($big[3], $big[1]);
        $cx = self::bigSub($big[4], $big[0]);
        $cy = self::bigSub($big[5], $big[1]);
        $det = self::bigSub(self::bigMul($bx, $cy), self::bigMul($by, $cx));
        return self::bigSign($det);
    }

    /** [integer mantissa, exponent] with v = m * 2^e exactly */
    private static function decompose(float $v): array
    {
        if ($v == 0.0) {
            return [0, 0];
        }
        $e = 0;
        $m = $v;
        while (floor($m) != $m) {
            $m *= 2;
            $e--;
        }
        while (abs($m) >= 2 ** 53) {
            $m /= 2;
            $e++;
        }
        return [(int)$m, $e];
    }

    // Signed big integers: little-endian digit lists in base 2^24 (digits may be negative until normalized)
    private static function bigFromInt(int $v): array
    {
        $digits = [];
        $neg = $v < 0;
        $v = abs($v);
        do {
            $digits[] = ($neg ? -1 : 1) * ($v & 0xFFFFFF);
            $v >>= 24;
        } while ($v > 0);
        return $digits;
    }

    private static function bigNormalize(array $d): array
    {
        // Every digit but the top one into [0, 2^24); the top one keeps the sign (|top| < 2^24)
        $base = 0x1000000;
        $carry = 0;
        $count = count($d);
        for ($i = 0; $i < $count; $i++) {
            $x = $d[$i] + $carry;
            $r = (($x % $base) + $base) % $base;
            $carry = intdiv($x - $r, $base);
            $d[$i] = $r;
        }
        while ($carry >= $base || $carry <= -$base) {
            $r = (($carry % $base) + $base) % $base;
            $d[] = $r;
            $carry = intdiv($carry - $r, $base);
        }
        if ($carry !== 0) {
            $d[] = $carry;
        }
        while (count($d) > 1 && end($d) === 0) {
            array_pop($d);
        }
        return $d;
    }

    private static function bigShift(array $d, int $bits): array
    {
        $whole = intdiv($bits, 24);
        $rest = $bits % 24;
        $out = array_merge(array_fill(0, $whole, 0), array_map(function ($x) use ($rest) { return $x * (1 << $rest); }, $d));
        return self::bigNormalize($out);
    }

    private static function bigSub(array $a, array $b): array
    {
        $n = max(count($a), count($b));
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[$i] = ($a[$i] ?? 0) - ($b[$i] ?? 0);
        }
        return self::bigNormalize($out);
    }

    private static function bigMul(array $a, array $b): array
    {
        $out = array_fill(0, count($a) + count($b), 0);
        foreach ($a as $i => $x) {
            foreach ($b as $j => $y) {
                $out[$i + $j] += $x * $y; // |digit| < 2^24: a few products per position stay below 2^53
            }
        }
        return self::bigNormalize($out);
    }

    private static function bigSign(array $d): int
    {
        // After normalization every digit but the top one is in [0, 2^24); the top one has the sign
        $top = end($d);
        if ($top !== 0) {
            return $top <=> 0;
        }
        return 0;
    }

    /** @test */
    public function knownShapesKeepTheirResult(): void
    {
        $db = $this->db();
        foreach ([null, 0] as $budget) {
            $this->setSweepBudget($budget);
            $cases = [
                'square' => [[[0, 0], [4, 0], [4, 4], [0, 4], [0, 0]], true],
                'bowtie' => [[[0, 0], [2, 2], [2, 0], [0, 2], [0, 0]], false],
                'vertex on edge' => [[[0, 0], [4, 0], [4, 4], [2, 0], [0, 4], [0, 0]], false],
                'repeated vertex' => [[[0, 0], [2, 0], [2, 2], [1, 1], [2, 0], [3, -1], [0, -1], [0, 0]], false],
                'duplicate point' => [[[0, 0], [4, 0], [4, 0], [4, 4], [0, 4], [0, 0]], false],
                'spike' => [[[0, 0], [4, 0], [2, 0], [2, 3], [0, 3], [0, 0]], false],
                'triangle' => [[[0, 0], [4, 0], [0, 3], [0, 0]], true],
                'flat triangle' => [[[0, 0], [1, 0], [2, 0], [0, 0]], true],
                'vertical edges' => [[[0, 0], [0, 4], [1, 4], [1, 1], [2, 1], [2, 4], [3, 4], [3, 0], [0, 0]], true],
                'vertex on a vertical edge' => [[[0, 0], [2, 0], [2, 4], [0, 4], [2, 2], [0, 0]], false],
                'collinear vertex' => [[[0, 0], [2, 0], [4, 0], [4, 4], [0, 4], [0, 0]], true],
                'edges meeting at a vertical' => [[[0, 0], [2, 1], [2, 3], [0, 4], [1, 2], [0, 0]], true],
                // The only crossing (x = 10.5) is between two edges that become neighbours in the sweep
                // when the wedge between them ends at x = 5
                'crossing after a wedge' => [[[1, 6], [5, 5], [1, 4], [1, -3], [2, -3], [2, 0], [20, 10], [22, 10], [22, -5], [20, 0], [0, 10], [1, 6]], false],
            ];
            foreach ($cases as $name => [$ring, $valid]) {
                $result = $db->validateGeoJSON(['type' => 'Polygon', 'coordinates' => [$ring]]);
                $this->assertSame($valid, $result['valid'], "$name, budget " . var_export($budget, true));
                $this->assertSame($valid ? null : 'Ring 0 must not intersect itself', $result['error'], "$name: the documented message");
                $this->assertSame($valid, self::refRingIntersects($ring) === false, "$name: the all-pairs definition agrees");
            }
        }
    }
}
