<?php
// GeoJSON validation (exact orientation tests)
namespace noneDB\Internal\Index\Spatial;

use function array_fill, array_is_list, array_keys, array_multisort, asort, count, is_array, is_finite,
    is_numeric, is_string, ksort, log, pack;
use const SORT_NUMERIC;

/**
 * @internal
 */
final class GeoJson {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $geometryValidations; // @test
    private $pointEdgeTests; // @test
    private $segmentPairTests; // @test
    private $sweepBudget; // @test
    private $geometry;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->geometry);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->geometryValidations = &$this->rt->geometryValidations; // @test
        $this->pointEdgeTests = &$this->rt->pointEdgeTests; // @test
        $this->segmentPairTests = &$this->rt->segmentPairTests; // @test
        $this->sweepBudget = &$this->rt->sweepBudget; // @test
    }

    /**
     * A handle to another service, bound on first use
     */
    public function __get(string $name) {
        return $this->$name = $this->k->$name;
    }

    /**
     * Drop the handles to the kernel and the other services (the instance is gone)
     */
    public function unbind(): void {
        $this->geometry = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // SPATIAL INDEX - GEOJSON SUPPORT
    // ==========================================

    /**
     * Validate a GeoJSON geometry object
     * Supports: Point, LineString, Polygon, MultiPoint, MultiLineString, MultiPolygon, GeometryCollection
     *
     * @param array $geometry GeoJSON geometry object
     * @return array ['valid' => bool, 'error' => ?string, 'type' => ?string]
     */
    public function validateGeoJSON($geometry): array {
        $this->geometryValidations++; // @test
        // Never throws: a stored record that fails here is simply not indexed (a malformed geometry must
        // never make later operations on the database fail)
        if (!is_array($geometry)) {
            return ['valid' => false, 'error' => 'Geometry must be an array', 'type' => null];
        }
        if (!isset($geometry['type'])) {
            return ['valid' => false, 'error' => 'Missing type property', 'type' => null];
        }

        $type = $geometry['type'];
        if (!is_string($type)) {
            return ['valid' => false, 'error' => 'Geometry type must be a string', 'type' => null];
        }

        // GeometryCollection has different structure
        if ($type === 'GeometryCollection') {
            return $this->validateGeometryCollection($geometry);
        }

        if (!isset($geometry['coordinates'])) {
            return ['valid' => false, 'error' => 'Missing coordinates property', 'type' => null];
        }

        $coords = $geometry['coordinates'];

        // Each validator checks the lists it needs (a Point needs none: values at 0 and 1)
        if (!is_array($coords)) {
            return ['valid' => false, 'error' => 'Coordinates must be an array', 'type' => null];
        }

        // Strict comparison (a switch compared loosely: 0 matched 'Point' before PHP 8)
        if ($type === 'Point') {
            return $this->validateGeoJSONPoint($coords);
        }
        if ($type === 'LineString') {
            return $this->validateGeoJSONLineString($coords);
        }
        if ($type === 'Polygon') {
            return $this->validateGeoJSONPolygon($coords);
        }
        if ($type === 'MultiPoint') {
            return $this->validateGeoJSONMultiPoint($coords);
        }
        if ($type === 'MultiLineString') {
            return $this->validateGeoJSONMultiLineString($coords);
        }
        if ($type === 'MultiPolygon') {
            return $this->validateGeoJSONMultiPolygon($coords);
        }
        return ['valid' => false, 'error' => "Unknown geometry type: $type", 'type' => null];
    }

    /**
     * An array with keys 0..n-1 in order (a JSON array)
     */
    private static function isJsonList($value): bool {
        return is_array($value) && array_is_list($value);
    }

    /**
     * Validate Point coordinates [lon, lat] or [lon, lat, altitude]
     */
    private function validateGeoJSONPoint($coords): array {
        // A position: values at 0 and 1 (checked below) are enough, no list check per position
        if (!is_array($coords) || count($coords) < 2) {
            return ['valid' => false, 'error' => 'Point must have at least 2 coordinates', 'type' => null];
        }
        if (!isset($coords[0], $coords[1]) || !is_numeric($coords[0]) || !is_numeric($coords[1])
            || is_string($coords[0]) || is_string($coords[1]) || !is_finite((float)$coords[0]) || !is_finite((float)$coords[1])) {
            return ['valid' => false, 'error' => 'Point coordinates must be finite numbers', 'type' => null];
        }
        $lon = (float)$coords[0];
        $lat = (float)$coords[1];
        if ($lon < -180 || $lon > 180) {
            return ['valid' => false, 'error' => 'Longitude must be between -180 and 180', 'type' => null];
        }
        if ($lat < -90 || $lat > 90) {
            return ['valid' => false, 'error' => 'Latitude must be between -90 and 90', 'type' => null];
        }
        return ['valid' => true, 'error' => null, 'type' => 'Point'];
    }

    /**
     * Validate LineString coordinates [[lon,lat], [lon,lat], ...]
     */
    private function validateGeoJSONLineString($coords): array {
        if (!self::isJsonList($coords) || count($coords) < 2) {
            return ['valid' => false, 'error' => 'LineString must have at least 2 positions', 'type' => null];
        }
        foreach ($coords as $i => $position) {
            $result = $this->validateGeoJSONPoint($position);
            if (!$result['valid']) {
                return ['valid' => false, 'error' => "Invalid position at index $i: " . $result['error'], 'type' => null];
            }
        }
        return ['valid' => true, 'error' => null, 'type' => 'LineString'];
    }

    /**
     * Validate Polygon coordinates [[[lon,lat], ...], ...]
     * First ring is outer boundary, subsequent rings are holes
     */
    private function validateGeoJSONPolygon($coords): array {
        if (!self::isJsonList($coords) || count($coords) < 1) {
            return ['valid' => false, 'error' => 'Polygon must have at least one ring', 'type' => null];
        }
        // Rings in order. The holes before the first failing ring are checked against the outer ring
        // together (one sweep for all their vertices); the first error is the same as ring by ring.
        $failure = null;
        $holes = [];
        foreach ($coords as $ringIndex => $ring) {
            if (!self::isJsonList($ring) || count($ring) < 4) {
                $failure = "Ring $ringIndex must have at least 4 positions";
                break;
            }
            // Validate each position
            foreach ($ring as $i => $position) {
                $result = $this->validateGeoJSONPoint($position);
                if (!$result['valid']) {
                    $failure = "Invalid position at ring $ringIndex, index $i: " . $result['error'];
                    break 2;
                }
            }
            // Check if ring is closed (first == last, compared numerically: 29 and 29.0 are the same)
            $first = $ring[0];
            $last = $ring[count($ring) - 1];
            if ((float)$first[0] != (float)$last[0] || (float)$first[1] != (float)$last[1]) {
                $failure = "Ring $ringIndex must be closed (first and last positions must be identical)";
                break;
            }
            if ($this->ringSelfIntersects($ring)) {
                $failure = "Ring $ringIndex must not intersect itself";
                break;
            }
            if ($ringIndex > 0) {
                $holes[$ringIndex] = $ring;
            }
        }
        if ($holes) {
            // Every vertex of a hole inside the outer ring or on it
            $points = [];
            $owner = [];
            foreach ($holes as $ringIndex => $ring) {
                foreach ($ring as $position) {
                    $points[] = $position;
                    $owner[] = $ringIndex;
                }
            }
            foreach ($this->locatePointsInRing($coords[0], $points) as $k => $where) {
                if ($where < 0) {
                    return ['valid' => false, 'error' => "Hole {$owner[$k]} must lie inside the outer ring", 'type' => null];
                }
            }
        }
        if ($failure !== null) {
            return ['valid' => false, 'error' => $failure, 'type' => null];
        }
        return ['valid' => true, 'error' => null, 'type' => 'Polygon'];
    }

    /**
     * True when two non-adjacent edges of a closed ring share a point (they cross, touch or overlap;
     * exact arithmetic). First a sort and sweep by x that compares the edges whose x ranges overlap:
     * fast for usual rings. When that work
     * passes 16 edge pairs per edge (rings built against it, e.g. a comb whose edges all overlap in x),
     * a sweep line over a balanced tree finishes the check: O(n log n) for every ring.
     */
    private function ringSelfIntersects(array $ring): bool {
        $n = count($ring) - 1; // edges; edge i runs from vertex i to vertex i + 1
        if ($n < 4) {
            return false; // every pair of a triangle's edges is adjacent
        }
        $xs = [];
        $ys = [];
        foreach ($ring as $position) {
            $xs[] = (float)$position[0];
            $ys[] = (float)$position[1];
        }
        $budget = 16 * $n + 256; // @prod
        $budget = $this->sweepBudget ?? 16 * $n + 256; // @test
        if ($budget === 0) {
            return $this->ringSelfIntersectsSweep($xs, $ys, $n);
        }
        $minX = [];
        $maxX = [];
        for ($i = 0; $i < $n; $i++) {
            $a = $xs[$i];
            $b = $xs[$i + 1];
            $minX[$i] = $a < $b ? $a : $b;
            $maxX[$i] = $a < $b ? $b : $a;
        }
        asort($minX, SORT_NUMERIC);
        $active = []; // edge => max x, edges whose x range may still overlap the next ones
        $work = 0;
        foreach ($minX as $i => $from) {
            foreach ($active as $j => $to) {
                if ($to < $from) {
                    unset($active[$j]);
                    continue;
                }
                $d = $i > $j ? $i - $j : $j - $i;
                if ($d === 1 || $d === $n - 1) {
                    continue; // neighbours share a vertex (the first and the last edge share the closing one)
                }
                if (++$work > $budget) {
                    return $this->ringSelfIntersectsSweep($xs, $ys, $n);
                }
                $this->segmentPairTests++; // @test
                if (self::segmentsMeet($xs[$i], $ys[$i], $xs[$i + 1], $ys[$i + 1], $xs[$j], $ys[$j], $xs[$j + 1], $ys[$j + 1])) {
                    return true;
                }
            }
            $active[$i] = $maxX[$i];
        }
        return false;
    }

    /**
     * ringSelfIntersects() as a sweep line (Shamos-Hoey) over an AVL tree of the edges that cross it,
     * ordered from bottom to top: O(n log n). Events are the vertices in (x, y) order. A ring that doesn't
     * intersect itself has at every event point exactly one vertex, so exactly its two edges end or
     * start there and no other edge passes through it; between events two edges can only cross after
     * they became neighbours in the tree, and every new pair of neighbours is tested.
     */
    private function ringSelfIntersectsSweep(array $xs, array $ys, int $n): bool {
        [$lo, $hi, $ex, $ey, $ee] = self::sweepEvents($xs, $ys, $n);
        $tree = self::avlTree(array_fill(0, $n, 0));
        $cmp = self::sweepOrder($xs, $ys, $lo, $hi); // @prod
        $cmp = self::sweepOrder($xs, $ys, $lo, $hi, $this->segmentPairTests); // @test
        $meet = function (int $a, int $b) use ($xs, $ys, $n): bool {
            if ($a < 0 || $b < 0) {
                return false;
            }
            $d = $a > $b ? $a - $b : $b - $a;
            if ($d === 1 || $d === $n - 1) {
                return false;
            }
            $this->segmentPairTests++; // @test
            return self::segmentsMeet($xs[$a], $ys[$a], $xs[$a + 1], $ys[$a + 1], $xs[$b], $ys[$b], $xs[$b + 1], $ys[$b + 1]);
        };
        $count = count($ee);
        for ($k = 0; $k < $count;) {
            $px = $ex[$k];
            $py = $ey[$k];
            $edges = [];
            $starts = [];
            $ends = [];
            for (; $k < $count && $ex[$k] == $px && $ey[$k] == $py; $k++) {
                $edge = $ee[$k] >> 1;
                $edges[$edge] = true;
                if ($ee[$k] & 1) {
                    $ends[] = $edge;
                } else {
                    $starts[] = $edge;
                }
            }
            // One vertex: its two edges, adjacent (a repeated vertex or a zero-length edge gives more)
            if (count($edges) !== 2) {
                return true;
            }
            [$e1, $e2] = array_keys($edges);
            $d = $e1 > $e2 ? $e1 - $e2 : $e2 - $e1;
            if ($d !== 1 && $d !== $n - 1) {
                return true;
            }
            foreach ($ends as $edge) {
                $tree['root'] = self::avlDelete($tree, $tree['root'], $edge, $cmp);
            }
            // No other edge through the vertex; the edges below and above it become neighbours
            $below = -1;
            $above = -1;
            for ($node = $tree['root']; $node >= 0;) {
                $this->segmentPairTests++; // @test
                $c = self::orient($xs[$lo[$node]], $ys[$lo[$node]], $xs[$hi[$node]], $ys[$hi[$node]], $px, $py);
                if ($c === 0) {
                    return true; // the vertex lies on another edge
                }
                if ($c > 0) {
                    $below = $node;
                    $node = $tree['r'][$node];
                } else {
                    $above = $node;
                    $node = $tree['l'][$node];
                }
            }
            if (!$starts) {
                if ($meet($below, $above)) {
                    return true;
                }
                continue;
            }
            foreach ($starts as $edge) {
                $pred = -1;
                $succ = -1;
                $tree['root'] = self::avlInsert($tree, $tree['root'], $edge, $cmp, $pred, $succ);
                if ($meet($pred, $edge) || $meet($edge, $succ)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Sweep events of a ring's edges: [lo, hi, x, y, code] with lo/hi the vertex numbers of each edge's
     * smaller and larger end in (x, y) order, and the events (both ends of every edge, code edge * 2
     * for the start, edge * 2 + 1 for the end) sorted by x, then y
     */
    private static function sweepEvents(array $xs, array $ys, int $n): array {
        $lo = [];
        $hi = [];
        $ex = [];
        $ey = [];
        $ee = [];
        for ($i = 0; $i < $n; $i++) {
            $a = $i;
            $b = $i + 1;
            if ($xs[$b] < $xs[$a] || ($xs[$b] == $xs[$a] && $ys[$b] < $ys[$a])) {
                $a = $i + 1;
                $b = $i;
            }
            $lo[$i] = $a;
            $hi[$i] = $b;
            $ex[] = $xs[$a];
            $ey[] = $ys[$a];
            $ee[] = $i * 2;
            $ex[] = $xs[$b];
            $ey[] = $ys[$b];
            $ee[] = $i * 2 + 1;
        }
        array_multisort($ex, SORT_NUMERIC, $ey, SORT_NUMERIC, $ee);
        return [$lo, $hi, $ex, $ey, $ee];
    }

    /**
     * Order of two edges that cross the sweep line at the same time and don't cross each other (bottom
     * to top): compared where the later one starts, then by direction; equal edges by number (the test
     * build counts the comparisons)
     */
    private static function sweepOrder(array $xs, array $ys, array $lo, array $hi): \Closure { // @prod
    private static function sweepOrder(array $xs, array $ys, array $lo, array $hi, int &$counter): \Closure { // @test
        return function (int $s, int $t) use ($xs, $ys, $lo, $hi): int { // @prod
        return function (int $s, int $t) use ($xs, $ys, $lo, $hi, &$counter): int { // @test
            $counter++; // @test
            $sx = $xs[$lo[$s]];
            $sy = $ys[$lo[$s]];
            $tx = $xs[$lo[$t]];
            $ty = $ys[$lo[$t]];
            if ($sx > $tx || ($sx == $tx && $sy > $ty)) {
                $c = self::orient($tx, $ty, $xs[$hi[$t]], $ys[$hi[$t]], $sx, $sy);
                if ($c === 0) {
                    $c = self::orient($tx, $ty, $xs[$hi[$t]], $ys[$hi[$t]], $xs[$hi[$s]], $ys[$hi[$s]]);
                }
            } elseif ($sx == $tx && $sy == $ty) {
                $c = self::orient($tx, $ty, $xs[$hi[$t]], $ys[$hi[$t]], $xs[$hi[$s]], $ys[$hi[$s]]);
            } else {
                $c = -self::orient($sx, $sy, $xs[$hi[$s]], $ys[$hi[$s]], $tx, $ty);
                if ($c === 0) {
                    $c = -self::orient($sx, $sy, $xs[$hi[$s]], $ys[$hi[$s]], $xs[$hi[$t]], $ys[$hi[$t]]);
                }
            }
            return $c !== 0 ? $c : ($s <=> $t);
        };
    }

    /**
     * Where points are against a ring that doesn't intersect itself: 1 inside, 0 on its boundary, -1
     * outside (exact). Every point against every edge when that is cheap; otherwise one sweep line over
     * the edges with the points as events, O((n + m) log n): the edges below a point, each counted +1 or
     * -1 by its direction, give its winding number (a ray straight down).
     */
    private function locatePointsInRing(array $ring, array $points): array {
        $xs = [];
        $ys = [];
        foreach ($ring as $position) {
            $xs[] = (float)$position[0];
            $ys[] = (float)$position[1];
        }
        $n = count($xs) - 1;
        $m = count($points);
        $budget = (int)(16 * ($n + $m) * log($n + $m + 2, 2)); // @prod
        $budget = $this->sweepBudget ?? (int)(16 * ($n + $m) * log($n + $m + 2, 2)); // @test
        $out = [];
        if ($n < 4 || ($budget > 0 && $n * $m <= $budget)) {
            foreach ($points as $point) {
                $out[] = self::locatePointInRing((float)$point[0], (float)$point[1], $xs, $ys, $n);
            }
            return $out;
        }

        [$lo, $hi, $ex, $ey, $ee] = self::sweepEvents($xs, $ys, $n);
        $vertices = [];
        for ($i = 0; $i < $n; $i++) {
            $vertices[pack('dd', $xs[$i] + 0.0, $ys[$i] + 0.0)] = true;
        }
        $weights = [];
        for ($i = 0; $i < $n; $i++) {
            $weights[$i] = $lo[$i] === $i ? 1 : -1; // run from its smaller end to its larger one
        }
        foreach ($points as $k => $point) {
            $x = (float)$point[0];
            $y = (float)$point[1];
            if (isset($vertices[pack('dd', $x + 0.0, $y + 0.0)])) {
                $out[$k] = 0;
                continue;
            }
            $out[$k] = -1;
            $ex[] = $x;
            $ey[] = $y;
            $ee[] = -1 - $k; // point events: negative codes
        }
        array_multisort($ex, SORT_NUMERIC, $ey, SORT_NUMERIC, $ee);
        $tree = self::avlTree($weights);
        $cmp = self::sweepOrder($xs, $ys, $lo, $hi); // @prod
        $cmp = self::sweepOrder($xs, $ys, $lo, $hi, $this->pointEdgeTests); // @test
        $count = count($ee);
        for ($k = 0; $k < $count;) {
            if ($ee[$k] < 0) {
                // A point (never at a vertex): the edges below it
                $x = $ex[$k];
                $y = $ey[$k];
                $winding = 0;
                for ($node = $tree['root']; $node >= 0;) {
                    $this->pointEdgeTests++; // @test
                    $c = self::orient($xs[$lo[$node]], $ys[$lo[$node]], $xs[$hi[$node]], $ys[$hi[$node]], $x, $y);
                    if ($c === 0) {
                        $winding = null; // on this edge
                        break;
                    }
                    if ($c > 0) {
                        $winding += $tree['s'][$tree['l'][$node]] + $weights[$node];
                        $node = $tree['r'][$node];
                    } else {
                        $node = $tree['l'][$node];
                    }
                }
                $out[-1 - $ee[$k]] = $winding === null ? 0 : ($winding !== 0 ? 1 : -1);
                $k++;
                continue;
            }
            // A vertex: its edges that end here leave the tree, then the ones that start here join it
            $px = $ex[$k];
            $py = $ey[$k];
            $starts = [];
            for (; $k < $count && $ee[$k] >= 0 && $ex[$k] == $px && $ey[$k] == $py; $k++) {
                if ($ee[$k] & 1) {
                    $tree['root'] = self::avlDelete($tree, $tree['root'], $ee[$k] >> 1, $cmp);
                } else {
                    $starts[] = $ee[$k] >> 1;
                }
            }
            foreach ($starts as $edge) {
                $pred = -1;
                $succ = -1;
                $tree['root'] = self::avlInsert($tree, $tree['root'], $edge, $cmp, $pred, $succ);
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * One point against a ring, exact: 1 inside, 0 on its boundary, -1 outside (winding number)
     */
    private static function locatePointInRing(float $x, float $y, array $xs, array $ys, int $n): int {
        \noneDB\Internal\Cache\Runtime::get()->pointEdgeTests += $n; // @test
        $winding = 0;
        for ($i = 0; $i < $n; $i++) {
            $x1 = $xs[$i];
            $y1 = $ys[$i];
            $x2 = $xs[$i + 1];
            $y2 = $ys[$i + 1];
            $o = null;
            if (($x1 < $x2 ? $x1 <= $x && $x <= $x2 : $x2 <= $x && $x <= $x1)
                && ($y1 < $y2 ? $y1 <= $y && $y <= $y2 : $y2 <= $y && $y <= $y1)) {
                $o = self::orient($x1, $y1, $x2, $y2, $x, $y);
                if ($o === 0) {
                    return 0;
                }
            }
            if ($y1 <= $y) {
                if ($y2 > $y && ($o ?? self::orient($x1, $y1, $x2, $y2, $x, $y)) > 0) {
                    $winding++;
                }
            } elseif ($y2 <= $y && ($o ?? self::orient($x1, $y1, $x2, $y2, $x, $y)) < 0) {
                $winding--;
            }
        }
        return $winding !== 0 ? 1 : -1;
    }

    /**
     * True when two closed segments share a point (exact)
     */
    private static function segmentsMeet(float $ax, float $ay, float $bx, float $by, float $cx, float $cy, float $dx, float $dy): bool {
        if (($ax < $bx ? $ax : $bx) > ($cx > $dx ? $cx : $dx) || ($cx < $dx ? $cx : $dx) > ($ax > $bx ? $ax : $bx)
            || ($ay < $by ? $ay : $by) > ($cy > $dy ? $cy : $dy) || ($cy < $dy ? $cy : $dy) > ($ay > $by ? $ay : $by)) {
            return false; // bounding boxes apart
        }
        $o1 = self::orient($ax, $ay, $bx, $by, $cx, $cy);
        $o2 = self::orient($ax, $ay, $bx, $by, $dx, $dy);
        $o3 = self::orient($cx, $cy, $dx, $dy, $ax, $ay);
        $o4 = self::orient($cx, $cy, $dx, $dy, $bx, $by);
        if ($o1 * $o2 < 0 && $o3 * $o4 < 0) {
            return true;
        }
        // Touching or collinear: an end of one on the other (inside the boxes, which overlap)
        return ($o1 === 0 && self::inBox($ax, $ay, $bx, $by, $cx, $cy)) || ($o2 === 0 && self::inBox($ax, $ay, $bx, $by, $dx, $dy))
            || ($o3 === 0 && self::inBox($cx, $cy, $dx, $dy, $ax, $ay)) || ($o4 === 0 && self::inBox($cx, $cy, $dx, $dy, $bx, $by));
    }

    private static function inBox(float $ax, float $ay, float $bx, float $by, float $px, float $py): bool {
        return ($ax < $bx ? $ax <= $px && $px <= $bx : $bx <= $px && $px <= $ax)
            && ($ay < $by ? $ay <= $py && $py <= $by : $by <= $py && $py <= $ay);
    }

    /**
     * Sign of the orientation of c against the directed line a -> b: 1 left, -1 right, 0 on the line.
     * Exact for all finite doubles: the floating-point determinant decides when it is clearly away from
     * zero (Shewchuk's error bound), otherwise it is summed exactly.
     */
    private static function orient(float $ax, float $ay, float $bx, float $by, float $cx, float $cy): int {
        $detLeft = ($ax - $cx) * ($by - $cy);
        $detRight = ($ay - $cy) * ($bx - $cx);
        $det = $detLeft - $detRight;
        if ($detLeft > 0.0) {
            if ($detRight <= 0.0) {
                return $det > 0.0 ? 1 : ($det < 0.0 ? -1 : 0);
            }
            $detSum = $detLeft + $detRight;
        } elseif ($detLeft < 0.0) {
            if ($detRight >= 0.0) {
                return $det > 0.0 ? 1 : ($det < 0.0 ? -1 : 0);
            }
            $detSum = -$detLeft - $detRight;
        } else {
            return $det > 0.0 ? 1 : ($det < 0.0 ? -1 : 0);
        }
        $bound = 3.3306690738754716e-16 * $detSum; // (3 + 16 eps) eps, eps = 2^-53
        if ($det >= $bound || -$det >= $bound) {
            return $det > 0.0 ? 1 : -1;
        }
        return self::orientExact($ax, $ay, $bx, $by, $cx, $cy);
    }

    /**
     * Exact sign of (a - c) x (b - c): the differences and products as exact float sums (Dekker,
     * Knuth), added into a nonoverlapping expansion whose largest part has the sign
     */
    private static function orientExact(float $ax, float $ay, float $bx, float $by, float $cx, float $cy): int {
        [$acx, $acxTail] = self::twoDiff($ax, $cx);
        [$bcy, $bcyTail] = self::twoDiff($by, $cy);
        [$acy, $acyTail] = self::twoDiff($ay, $cy);
        [$bcx, $bcxTail] = self::twoDiff($bx, $cx);
        $sum = [];
        foreach ([[$acx, $bcy, 1.0], [$acx, $bcyTail, 1.0], [$acxTail, $bcy, 1.0], [$acxTail, $bcyTail, 1.0],
                  [$acy, $bcx, -1.0], [$acy, $bcxTail, -1.0], [$acyTail, $bcx, -1.0], [$acyTail, $bcxTail, -1.0]] as [$p, $q, $sign]) {
            if ($p == 0.0 || $q == 0.0) {
                continue;
            }
            [$product, $error] = self::twoProduct($p, $q);
            $sum = self::growExpansion(self::growExpansion($sum, $sign * $product), $sign * $error);
        }
        for ($i = count($sum) - 1; $i >= 0; $i--) {
            if ($sum[$i] != 0.0) {
                return $sum[$i] > 0.0 ? 1 : -1;
            }
        }
        return 0;
    }

    /** Add $b to a nonoverlapping expansion (smallest part first), zero parts dropped */
    private static function growExpansion(array $parts, float $b): array {
        if ($b == 0.0) {
            return $parts;
        }
        $out = [];
        $q = $b;
        foreach ($parts as $part) {
            [$q, $error] = self::twoSum($q, $part);
            if ($error != 0.0) {
                $out[] = $error;
            }
        }
        $out[] = $q;
        return $out;
    }

    /** a + b = x + y exactly */
    private static function twoSum(float $a, float $b): array {
        $x = $a + $b;
        $bVirtual = $x - $a;
        $aVirtual = $x - $bVirtual;
        return [$x, ($a - $aVirtual) + ($b - $bVirtual)];
    }

    /** a - b = x + y exactly */
    private static function twoDiff(float $a, float $b): array {
        $x = $a - $b;
        $bVirtual = $a - $x;
        $aVirtual = $x + $bVirtual;
        return [$x, ($a - $aVirtual) + ($bVirtual - $b)];
    }

    /** a * b = x + y exactly (Dekker's split into 26-bit halves) */
    private static function twoProduct(float $a, float $b): array {
        $x = $a * $b;
        $c = 134217729.0 * $a; // 2^27 + 1
        $aHigh = $c - ($c - $a);
        $aLow = $a - $aHigh;
        $c = 134217729.0 * $b;
        $bHigh = $c - ($c - $b);
        $bLow = $b - $bHigh;
        $error = (($x - $aHigh * $bHigh) - $aLow * $bHigh) - $aHigh * $bLow;
        return [$x, $aLow * $bLow - $error];
    }

    /**
     * An empty AVL tree for the sweeps; $weights[node] is summed per subtree ('s'). Index -1 is the
     * empty child (height 0, sum 0).
     */
    private static function avlTree(array $weights): array {
        return ['l' => [-1 => -1], 'r' => [-1 => -1], 'h' => [-1 => 0], 's' => [-1 => 0], 'w' => $weights, 'root' => -1];
    }

    /** Insert $node below $x, return the new subtree root; $pred/$succ get its neighbours */
    private static function avlInsert(array &$t, int $x, int $node, \Closure $cmp, int &$pred, int &$succ): int {
        if ($x < 0) {
            $t['l'][$node] = -1;
            $t['r'][$node] = -1;
            $t['h'][$node] = 1;
            $t['s'][$node] = $t['w'][$node];
            return $node;
        }
        if ($cmp($node, $x) < 0) {
            $succ = $x;
            $t['l'][$x] = self::avlInsert($t, $t['l'][$x], $node, $cmp, $pred, $succ);
        } else {
            $pred = $x;
            $t['r'][$x] = self::avlInsert($t, $t['r'][$x], $node, $cmp, $pred, $succ);
        }
        return self::avlBalance($t, $x);
    }

    /** Remove $node (it is in the tree) below $x, return the new subtree root */
    private static function avlDelete(array &$t, int $x, int $node, \Closure $cmp): int {
        if ($x < 0) {
            return -1;
        }
        if ($x !== $node) {
            if ($cmp($node, $x) < 0) {
                $t['l'][$x] = self::avlDelete($t, $t['l'][$x], $node, $cmp);
            } else {
                $t['r'][$x] = self::avlDelete($t, $t['r'][$x], $node, $cmp);
            }
            return self::avlBalance($t, $x);
        }
        $left = $t['l'][$x];
        $right = $t['r'][$x];
        if ($left < 0) {
            return $right;
        }
        if ($right < 0) {
            return $left;
        }
        $next = $right;
        while ($t['l'][$next] >= 0) {
            $next = $t['l'][$next];
        }
        $t['r'][$next] = self::avlDeleteMin($t, $right);
        $t['l'][$next] = $left;
        return self::avlBalance($t, $next);
    }

    private static function avlDeleteMin(array &$t, int $x): int {
        if ($t['l'][$x] < 0) {
            return $t['r'][$x];
        }
        $t['l'][$x] = self::avlDeleteMin($t, $t['l'][$x]);
        return self::avlBalance($t, $x);
    }

    private static function avlBalance(array &$t, int $x): int {
        self::avlUpdate($t, $x);
        $left = $t['l'][$x];
        $right = $t['r'][$x];
        $diff = $t['h'][$left] - $t['h'][$right];
        if ($diff > 1) {
            if ($t['h'][$t['l'][$left]] < $t['h'][$t['r'][$left]]) {
                $t['l'][$x] = self::avlRotate($t, $left, true);
            }
            return self::avlRotate($t, $x, false);
        }
        if ($diff < -1) {
            if ($t['h'][$t['r'][$right]] < $t['h'][$t['l'][$right]]) {
                $t['r'][$x] = self::avlRotate($t, $right, false);
            }
            return self::avlRotate($t, $x, true);
        }
        return $x;
    }

    /** Rotate: $left = the right child goes up, else the left child; returns the new subtree root */
    private static function avlRotate(array &$t, int $x, bool $left): int {
        if ($left) {
            $y = $t['r'][$x];
            $t['r'][$x] = $t['l'][$y];
            $t['l'][$y] = $x;
        } else {
            $y = $t['l'][$x];
            $t['l'][$x] = $t['r'][$y];
            $t['r'][$y] = $x;
        }
        self::avlUpdate($t, $x);
        self::avlUpdate($t, $y);
        return $y;
    }

    private static function avlUpdate(array &$t, int $x): void {
        $left = $t['l'][$x];
        $right = $t['r'][$x];
        $t['h'][$x] = ($t['h'][$left] > $t['h'][$right] ? $t['h'][$left] : $t['h'][$right]) + 1;
        $t['s'][$x] = $t['s'][$left] + $t['s'][$right] + $t['w'][$x];
    }

    /**
     * True when a point lies on a ring's boundary
     */
    public function pointOnRing(float $lon, float $lat, array $ring): bool {
        $n = count($ring);
        for ($i = 0; $i < $n - 1; $i++) {
            if ($this->geometry->crossProductDirection($ring[$i], $ring[$i + 1], [$lon, $lat]) == 0
                && $this->geometry->onSegment($ring[$i], $ring[$i + 1], [$lon, $lat])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate MultiPoint coordinates [[lon,lat], [lon,lat], ...]
     */
    private function validateGeoJSONMultiPoint($coords): array {
        if (!self::isJsonList($coords) || count($coords) < 1) {
            return ['valid' => false, 'error' => 'MultiPoint must have at least one point', 'type' => null];
        }
        foreach ($coords as $i => $position) {
            $result = $this->validateGeoJSONPoint($position);
            if (!$result['valid']) {
                return ['valid' => false, 'error' => "Invalid point at index $i: " . $result['error'], 'type' => null];
            }
        }
        return ['valid' => true, 'error' => null, 'type' => 'MultiPoint'];
    }

    /**
     * Validate MultiLineString coordinates [[[lon,lat], ...], ...]
     */
    private function validateGeoJSONMultiLineString($coords): array {
        if (!self::isJsonList($coords) || count($coords) < 1) {
            return ['valid' => false, 'error' => 'MultiLineString must have at least one linestring', 'type' => null];
        }
        foreach ($coords as $i => $lineString) {
            $result = $this->validateGeoJSONLineString($lineString);
            if (!$result['valid']) {
                return ['valid' => false, 'error' => "Invalid linestring at index $i: " . $result['error'], 'type' => null];
            }
        }
        return ['valid' => true, 'error' => null, 'type' => 'MultiLineString'];
    }

    /**
     * Validate MultiPolygon coordinates [[[[lon,lat], ...], ...], ...]
     */
    private function validateGeoJSONMultiPolygon($coords): array {
        if (!self::isJsonList($coords) || count($coords) < 1) {
            return ['valid' => false, 'error' => 'MultiPolygon must have at least one polygon', 'type' => null];
        }
        foreach ($coords as $i => $polygon) {
            $result = $this->validateGeoJSONPolygon($polygon);
            if (!$result['valid']) {
                return ['valid' => false, 'error' => "Invalid polygon at index $i: " . $result['error'], 'type' => null];
            }
        }
        return ['valid' => true, 'error' => null, 'type' => 'MultiPolygon'];
    }

    /**
     * Validate GeometryCollection
     */
    private function validateGeometryCollection(array $geometry): array {
        if (!isset($geometry['geometries']) || !self::isJsonList($geometry['geometries'])) {
            return ['valid' => false, 'error' => 'GeometryCollection must have geometries array', 'type' => null];
        }
        foreach ($geometry['geometries'] as $i => $geom) {
            if (!is_array($geom)) {
                return ['valid' => false, 'error' => "Invalid geometry at index $i: must be an object", 'type' => null];
            }
            $result = $this->validateGeoJSON($geom);
            if (!$result['valid']) {
                return ['valid' => false, 'error' => "Invalid geometry at index $i: " . $result['error'], 'type' => null];
            }
        }
        return ['valid' => true, 'error' => null, 'type' => 'GeometryCollection'];
    }
}
