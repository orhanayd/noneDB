<?php
// Spatial queries: withinDistance(), withinBBox(), nearest(), withinPolygon()
namespace noneDB\Internal\Query;

use function abs, array_filter, array_key_exists, array_keys, array_map, array_slice, array_unique, array_values,
    asort, atan2, cos, count, deg2rad, fmod, in_array, is_array, is_finite, is_float, is_int, is_nan, max, min, round,
    sin, sort, sqrt, usort;
use const INF;

/**
 * @internal
 */
final class SpatialQueries {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $indexedCandidates;
    private $spatialIndexEnabled;
    private $cache;
    private $fieldIndex;
    private $geoJson;
    private $geometry;
    private $layout;
    private $locks;
    private $queryMethods;
    private $records;
    private $rtree;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->fieldIndex, $this->geoJson, $this->geometry, $this->layout, $this->locks, $this->queryMethods, $this->records, $this->rtree, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->indexedCandidates = &$this->k->indexedCandidates;
        $this->spatialIndexEnabled = &$this->k->spatialIndexEnabled;
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
        $this->cache = null;
        $this->fieldIndex = null;
        $this->geoJson = null;
        $this->geometry = null;
        $this->layout = null;
        $this->locks = null;
        $this->queryMethods = null;
        $this->records = null;
        $this->rtree = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // SPATIAL QUERIES
    // ==========================================

    /**
     * Records whose geometry may overlap the search boxes, in key order, one segment at a time (the cache may
     * drop what a segment loaded once it is done): yields key => record. Uses the R-tree (and shard boxes)
     * when the field is indexed; otherwise every record.
     * @param array $boxes List of MBRs
     * @return \Generator
     */
    private function spatialCandidates(string $dbname, string $field, array $boxes) {
        if (!$this->segments->dbExists($dbname)) {
            return;
        }
        $indexed = $this->spatialIndexEnabled && in_array($field, $this->fieldIndex->getIndexManifest($dbname)['spatial'], true);
        $this->indexedCandidates = [];
        $sharded = $this->segments->isSharded($dbname);
        $global = ($indexed && $sharded) ? $this->rtree->readGlobalSpatialIndex($dbname, $field) : null;

        $done = false; // a segment was read: the cache may drop what it loaded before the next one (not after the last)
        foreach ($this->segments->listSegments($dbname) as $segment) {
            if ($done) {
                $this->cache->evictBetweenSegments();
                $done = false;
            }
            $keys = null; // null: every record of the segment
            // Shard skip: only with an entry that fits the shard, misses the boxes, and no newer geometry of
            // the shard in them. A missing entry (or global file) means unknown: searched.
            if ($indexed && $global !== null && $this->rtree->globalSpatialSkips($dbname, $field, $segment, $global, $boxes)) {
                continue;
            }
            if ($indexed) {
                try {
                    $keys = $this->rtree->rtreeSearchMBR($dbname, $field, $boxes, $segment);
                    foreach ($keys as $key) {
                        $this->indexedCandidates[$key] = true;
                    }
                } catch (\Throwable $e) {
                    if ($e->getCode() === \noneDB::LOCK_FAILED) {
                        throw $e;
                    }
                    $keys = null; // the index can't be built: scan
                }
            }
            if ($keys === null || !empty($keys)) {
                foreach ($this->records->eachSegmentBatch($dbname, $segment, $keys) as $batch) {
                    yield from $batch;
                }
            }
            $done = true;
        }
    }

    /**
     * Search boxes for a circle; splits at the antimeridian and covers all longitudes near the poles
     * @return array List of [minLon, minLat, maxLon, maxLat]
     */
    private function circleToBBoxes(float $lon, float $lat, float $radiusMeters): array {
        $latDeg = $radiusMeters / 111000.0;
        $minLat = $lat - $latDeg;
        $maxLat = $lat + $latDeg;
        if ($minLat <= -90 || $maxLat >= 90) {
            return [[-180.0, max(-90.0, $minLat), 180.0, min(90.0, $maxLat)]];
        }
        $cos = cos(deg2rad(max(abs($minLat), abs($maxLat))));
        $lonDeg = $cos > 1e-9 ? $radiusMeters / (111000.0 * $cos) : 360.0;
        if ($lonDeg >= 180) {
            return [[-180.0, $minLat, 180.0, $maxLat]];
        }
        $minLon = $lon - $lonDeg;
        $maxLon = $lon + $lonDeg;
        if ($minLon < -180) {
            return [[-180.0, $minLat, $maxLon, $maxLat], [$minLon + 360.0, $minLat, 180.0, $maxLat]];
        }
        if ($maxLon > 180) {
            return [[$minLon, $minLat, 180.0, $maxLat], [-180.0, $minLat, $maxLon - 360.0, $maxLat]];
        }
        return [[$minLon, $minLat, $maxLon, $maxLat]];
    }

    /**
     * Distance in meters from a point to a geometry: 0 inside a polygon, otherwise to the nearest
     * vertex or edge (edges use a local equirectangular approximation)
     */
    public function distanceToGeometry(float $lon, float $lat, array $geometry): float {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        switch ($type) {
            case 'Point':
                return $this->geometry->haversineDistance($lon, $lat, (float)$coords[0], (float)$coords[1]);
            case 'MultiPoint':
                $best = INF;
                foreach ($coords as $p) {
                    $best = min($best, $this->geometry->haversineDistance($lon, $lat, (float)$p[0], (float)$p[1]));
                }
                return $best;
            case 'LineString':
                return $this->distanceToPath($lon, $lat, $coords);
            case 'MultiLineString':
                $best = INF;
                foreach ($coords as $line) {
                    $best = min($best, $this->distanceToPath($lon, $lat, $line));
                }
                return $best;
            case 'Polygon':
                if ($this->geometry->pointInPolygon($lon, $lat, $coords)) {
                    return 0.0;
                }
                $best = INF;
                foreach ($coords as $ring) {
                    $best = min($best, $this->distanceToPath($lon, $lat, $ring));
                }
                return $best;
            case 'MultiPolygon':
                $best = INF;
                foreach ($coords as $polygon) {
                    $best = min($best, $this->distanceToGeometry($lon, $lat, ['type' => 'Polygon', 'coordinates' => $polygon]));
                }
                return $best;
            case 'GeometryCollection':
                $best = INF;
                foreach ($geometry['geometries'] ?? [] as $g) {
                    $best = min($best, $this->distanceToGeometry($lon, $lat, $g));
                }
                return $best;
        }
        return INF;
    }

    /**
     * Distance from a point to a polyline (meters): to its nearest point, an edge being straight in
     * longitude/latitude. Exact up to floating point: every vertex is measured, and every edge whose
     * bounding box could hold a nearer point is searched (golden section on the haversine of the point at
     * t along the edge, which wraps at the antimeridian by itself); the other edges are skipped by a lower
     * bound of the haversine over their box.
     */
    private function distanceToPath(float $lon, float $lat, array $points): float {
        $n = count($points);
        if ($n === 0) {
            return INF;
        }
        $cosLat = cos(deg2rad($lat));
        $xs = [];
        $ys = [];
        $best = INF; // the haversine "a" of the nearest point found (monotonic in the distance)
        foreach ($points as $i => $point) {
            $xs[$i] = (float)$point[0];
            $ys[$i] = (float)$point[1];
            $best = min($best, $this->haversineA($lon, $lat, $cosLat, $xs[$i], $ys[$i]));
        }
        $points = array_keys($xs);
        $bounds = [];
        for ($e = 0; $e < $n - 1; $e++) {
            $i = $points[$e];
            $j = $points[$e + 1];
            $bound = $this->edgeBoxLowerBound($lon, $lat, $cosLat, $xs[$i], $ys[$i], $xs[$j], $ys[$j]);
            if ($bound < $best) {
                $bounds[$e] = $bound;
            }
        }
        asort($bounds);
        foreach ($bounds as $e => $bound) {
            if ($bound >= $best) {
                break; // every other edge is at least this far
            }
            $i = $points[$e];
            $j = $points[$e + 1];
            $best = min($best, $this->edgeMinimumA($lon, $lat, $cosLat, $xs[$i], $ys[$i], $xs[$j], $ys[$j]));
        }
        return 6371000.0 * (2 * atan2(sqrt($best), sqrt(1 - $best)));
    }

    /**
     * The "a" of haversineDistance() (the same operations, so a vertex gives the same distance)
     */
    private function haversineA(float $lon, float $lat, float $cosLat, float $x, float $y): float {
        $latDelta = deg2rad($y - $lat);
        $lonDelta = deg2rad($x - $lon);
        return sin($latDelta / 2) * sin($latDelta / 2) + $cosLat * cos(deg2rad($y)) * sin($lonDelta / 2) * sin($lonDelta / 2);
    }

    /**
     * A lower bound of haversineA() over the bounding box of an edge: a = hav(dlat) + cos(lat) cos(lat2)
     * hav(dlon), each term bounded by the box's smallest latitude and longitude difference to the point
     * and its smallest cosine
     */
    private function edgeBoxLowerBound(float $lon, float $lat, float $cosLat, float $ax, float $ay, float $bx, float $by): float {
        $loY = min($ay, $by);
        $hiY = max($ay, $by);
        $dLat = $lat < $loY ? $loY - $lat : ($lat > $hiY ? $lat - $hiY : 0.0);
        $loX = min($ax, $bx);
        $width = max($ax, $bx) - $loX;
        $dLon = 0.0;
        if ($width < 360) {
            $offset = fmod($lon - $loX, 360.0);
            if ($offset < 0) {
                $offset += 360.0;
            }
            if ($offset > $width) {
                $dLon = min($offset - $width, 360.0 - $offset);
            }
        }
        $cosMin = max(0.0, min(cos(deg2rad($loY)), cos(deg2rad($hiY))));
        $sLat = sin(deg2rad($dLat) / 2);
        $sLon = sin(deg2rad($dLon) / 2);
        return $sLat * $sLat + $cosLat * $cosMin * $sLon * $sLon;
    }

    /**
     * The smallest haversineA() of the points of an edge: golden-section search over t (60 steps: t to
     * about 1e-13), plus the point a local flat projection picks, whichever is nearer
     */
    private function edgeMinimumA(float $lon, float $lat, float $cosLat, float $ax, float $ay, float $bx, float $by): float {
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $at = function (float $t) use ($lon, $lat, $cosLat, $ax, $ay, $dx, $dy) {
            return $this->haversineA($lon, $lat, $cosLat, $ax + $dx * $t, $ay + $dy * $t);
        };
        // Local projection around the point, the longitude of the edge's start taken on the point's side
        $px = fmod($ax - $lon + 540.0, 360.0) - 180.0;
        $qx = ($px + $dx) * $cosLat;
        $px *= $cosLat;
        $py = $ay - $lat;
        $qy = $by - $lat;
        $ex = $qx - $px;
        $ey = $qy - $py;
        $len2 = $ex * $ex + $ey * $ey;
        $best = $at($len2 > 0 ? max(0.0, min(1.0, -($px * $ex + $py * $ey) / $len2)) : 0.0);
        $ratio = 0.6180339887498949;
        $lo = 0.0;
        $hi = 1.0;
        $c = $hi - $ratio * ($hi - $lo);
        $d = $lo + $ratio * ($hi - $lo);
        $fc = $at($c);
        $fd = $at($d);
        for ($step = 0; $step < 60; $step++) {
            if ($fc < $fd) {
                $hi = $d;
                $d = $c;
                $fd = $fc;
                $c = $hi - $ratio * ($hi - $lo);
                $fc = $at($c);
            } else {
                $lo = $c;
                $c = $d;
                $fc = $fd;
                $d = $lo + $ratio * ($hi - $lo);
                $fd = $at($d);
            }
        }
        return min($best, $fc, $fd);
    }

    /**
     * Find records within a distance from a point (distance to the nearest point of the geometry)
     * @param string $dbname Database name
     * @param string $field GeoJSON field name
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @param float $distanceMeters Distance in meters
     * @param array $options ['includeDistance' => false]
     * @return array Matching records (sorted by distance, with _distance in meters if includeDistance=true)
     */
    public function withinDistance(string $dbname, string $field, float $lon, float $lat, float $distanceMeters, array $options = []): array{
        \noneDB\Internal\Config\Configuration::checkNames($options, ['includeDistance'], 'option', ' of withinDistance()');
        if(array_key_exists('includeDistance', $options)){
            \noneDB\Internal\Config\Configuration::optionBool('includeDistance', $options['includeDistance']);
        }
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $lon, $lat, $distanceMeters, $options){
            return $this->withinDistanceUnlocked($dbname, $field, $lon, $lat, $distanceMeters, $options);
        });
    }

    private function withinDistanceUnlocked(string $dbname, string $field, float $lon, float $lat, float $distanceMeters, array $options = []): array{
        $includeDistance = $options['includeDistance'] ?? false;
        if ($distanceMeters < 0 || is_nan($distanceMeters) || !is_finite($lon) || !is_finite($lat)) {
            return [];
        }
        $distanceMeters = min($distanceMeters, self::EARTH_HALF_CIRCUMFERENCE); // infinite: everything
        $results = [];
        foreach ($this->spatialCandidates($dbname, $field, $this->circleToBBoxes($lon, $lat, $distanceMeters)) as $record) {
            $geometry = $record[$field] ?? null;
            if (!isset($this->indexedCandidates[$record['key']]) && (!is_array($geometry) || !$this->geoJson->validateGeoJSON($geometry)['valid'])) {
                continue; // a candidate of an R-tree has a valid geometry: checked when it was indexed
            }
            $distance = $this->distanceToGeometry($lon, $lat, $geometry);
            if (is_finite($distance) && $distance <= $distanceMeters) {
                $record['_distance'] = round($distance, 2);
                $results[] = $record;
            }
        }
        usort($results, function ($a, $b) {
            return $a['_distance'] <=> $b['_distance'] ?: $a['key'] <=> $b['key'];
        });
        if (!$includeDistance) {
            foreach ($results as &$r) {
                unset($r['_distance']);
            }
            unset($r);
        }
        return $results;
    }

    /**
     * Find records within a bounding box (geometries whose bounding box overlaps it).
     * minLon > maxLon means the box crosses the antimeridian.
     * @return array Matching records
     */
    public function withinBBox(string $dbname, string $field, float $minLon, float $minLat, float $maxLon, float $maxLat): array{
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $minLon, $minLat, $maxLon, $maxLat){
            return $this->withinBBoxUnlocked($dbname, $field, $minLon, $minLat, $maxLon, $maxLat);
        });
    }

    private function withinBBoxUnlocked(string $dbname, string $field, float $minLon, float $minLat, float $maxLon, float $maxLat): array{
        if (is_nan($minLon) || is_nan($minLat) || is_nan($maxLon) || is_nan($maxLat)) {
            return [];
        }
        // Infinite edges reach the edge of the map
        $minLon = max(-180.0, min(180.0, $minLon));
        $maxLon = max(-180.0, min(180.0, $maxLon));
        $minLat = max(-90.0, min(90.0, $minLat));
        $maxLat = max(-90.0, min(90.0, $maxLat));
        if ($minLat > $maxLat) {
            [$minLat, $maxLat] = [$maxLat, $minLat];
        }
        $boxes = $minLon <= $maxLon
            ? [[$minLon, $minLat, $maxLon, $maxLat]]
            : [[$minLon, $minLat, 180.0, $maxLat], [-180.0, $minLat, $maxLon, $maxLat]];

        $results = [];
        foreach ($this->spatialCandidates($dbname, $field, $boxes) as $record) {
            $geometry = $record[$field] ?? null;
            if (!isset($this->indexedCandidates[$record['key']]) && (!is_array($geometry) || !$this->geoJson->validateGeoJSON($geometry)['valid'])) {
                continue; // a candidate of an R-tree has a valid geometry: checked when it was indexed
            }
            $recordMBR = $this->geometry->calculateMBR($geometry);
            if ($recordMBR === null) {
                continue;
            }
            foreach ($boxes as $box) {
                if ($this->geometry->mbrOverlaps($recordMBR, $box)) {
                    $results[] = $record;
                    break;
                }
            }
        }
        return $results;
    }

    /**
     * Find K nearest records to a point
     * @param string $dbname Database name
     * @param string $field GeoJSON field name
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @param int $k Number of nearest records
     * @param array $options ['maxDistance' => null (meters), 'includeDistance' => true]
     * @return array Up to k records sorted by distance (fewer when maxDistance cuts them off)
     */
    public function nearest(string $dbname, string $field, float $lon, float $lat, int $k, array $options = []): array{
        \noneDB\Internal\Config\Configuration::checkNames($options, ['maxDistance', 'includeDistance'], 'option', ' of nearest()');
        if(array_key_exists('maxDistance', $options) && $options['maxDistance'] !== null && !is_int($options['maxDistance']) && !is_float($options['maxDistance'])){
            throw new \InvalidArgumentException("noneDB: 'maxDistance' must be a number of meters or null");
        }
        if(array_key_exists('includeDistance', $options)){
            \noneDB\Internal\Config\Configuration::optionBool('includeDistance', $options['includeDistance']);
        }
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $lon, $lat, $k, $options){
            return $this->nearestUnlocked($dbname, $field, $lon, $lat, $k, $options);
        });
    }

    /**
     * True when spatial queries on the field use the R-tree (used by the query builder)
     */
    public function usesSpatialIndex(string $dbname, string $field): bool{
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field){
            return $this->spatialIndexEnabled && $this->segments->dbExists($dbname)
                && in_array($field, $this->fieldIndex->getIndexManifest($dbname)['spatial'], true);
        });
    }

    private function nearestUnlocked(string $dbname, string $field, float $lon, float $lat, int $k, array $options = []): array{
        $maxDistance = isset($options['maxDistance']) ? (float)$options['maxDistance'] : null; // an int or a float (nearest())
        $includeDistance = $options['includeDistance'] ?? true;
        // NaN matches nothing (and NaN radii made the expanding search below loop forever)
        if ($k <= 0 || !is_finite($lon) || !is_finite($lat) || ($maxDistance !== null && is_nan($maxDistance)) || !$this->segments->dbExists($dbname)) {
            return [];
        }

        $indexed = $this->spatialIndexEnabled && in_array($field, $this->fieldIndex->getIndexManifest($dbname)['spatial'], true);
        // Half the earth's circumference covers everything (also an infinite maxDistance)
        $limit = min($maxDistance ?? self::EARTH_HALF_CIRCUMFERENCE, self::EARTH_HALF_CIRCUMFERENCE);
        $results = [];

        if ($indexed && $k < $this->queryMethods->countFast($dbname)) {
            // Expanding search: nothing outside radius r is closer than r. Results are ordered by the
            // rounded distance, so only records rounded below round(r) are certainly in final order
            // (a record just outside r can round to the same value and has to win a key tie).
            $radius = 1000.0;
            while (true) {
                $radius = min($radius, $limit);
                $found = $this->withinDistanceUnlocked($dbname, $field, $lon, $lat, $radius, ['includeDistance' => true]);
                if ($radius >= $limit || (count($found) >= $k && $found[$k - 1]['_distance'] < round($radius, 2))) {
                    $results = $found;
                    break;
                }
                $radius *= 4;
            }
        } else {
            // Every record by its distance; only the best k are kept (pruned as they come)
            $order = function ($a, $b) {
                return $a['_distance'] <=> $b['_distance'] ?: $a['key'] <=> $b['key'];
            };
            $prune = max(4 * $k, 1024);
            foreach ($this->segments->listSegments($dbname) as $segment) {
                foreach ($this->records->eachSegmentBatch($dbname, $segment) as $batch) {
                    foreach ($batch as $record) {
                        $geometry = $record[$field] ?? null;
                        if (!is_array($geometry) || !$this->geoJson->validateGeoJSON($geometry)['valid']) {
                            continue;
                        }
                        $distance = $this->distanceToGeometry($lon, $lat, $geometry);
                        if (is_finite($distance) && ($maxDistance === null || $distance <= $maxDistance)) {
                            $record['_distance'] = round($distance, 2);
                            $results[] = $record;
                            if (count($results) > $prune) {
                                usort($results, $order);
                                $results = array_slice($results, 0, $k);
                            }
                        }
                    }
                }
                $this->cache->evictBetweenSegments();
            }
            usort($results, $order);
        }

        $results = array_slice($results, 0, $k);
        if (!$includeDistance) {
            foreach ($results as &$r) {
                unset($r['_distance']);
            }
            unset($r);
        }
        return $results;
    }

    /**
     * Find records whose geometry lies within a Polygon or MultiPolygon (holes excluded)
     * @param string $dbname Database name
     * @param string $field GeoJSON field name
     * @param array $polygon GeoJSON Polygon or MultiPolygon geometry
     * @return array Matching records
     */
    public function withinPolygon(string $dbname, string $field, array $polygon): array{
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $polygon){
            return $this->withinPolygonUnlocked($dbname, $field, $polygon);
        });
    }

    private function withinPolygonUnlocked(string $dbname, string $field, array $polygon): array{
        $validation = $this->geoJson->validateGeoJSON($polygon);
        if (!$validation['valid'] || !in_array($validation['type'], ['Polygon', 'MultiPolygon'], true)) {
            return [];
        }
        $polygons = $validation['type'] === 'Polygon' ? [$polygon['coordinates']] : $polygon['coordinates'];
        $boxes = [];
        foreach ($polygons as $coords) {
            $boxes[] = $this->geometry->mbrFromPoints($coords[0]);
        }

        // One point inside each hole of the query: a record polygon that contains one covers part of the hole
        $parts = [];
        foreach ($polygons as $coords) {
            $holePoints = [];
            for ($h = 1; $h < count($coords); $h++) {
                $point = $this->pointInsideRing($coords[$h]);
                if ($point !== null) {
                    $holePoints[] = $point;
                }
            }
            $parts[] = ['coords' => $coords, 'holePoints' => $holePoints];
        }

        $results = [];
        foreach ($this->spatialCandidates($dbname, $field, $boxes) as $record) {
            $geometry = $record[$field] ?? null;
            if (!isset($this->indexedCandidates[$record['key']]) && (!is_array($geometry) || !$this->geoJson->validateGeoJSON($geometry)['valid'])) {
                continue; // a candidate of an R-tree has a valid geometry: checked when it was indexed
            }
            if ($this->geometryWithinParts($geometry, $parts)) {
                $results[] = $record;
            }
        }
        return $results;
    }

    /**
     * Within a Polygon or MultiPolygon query (its parts): a single geometry must fit one part; every
     * component of a multi-part geometry must fit some part. A component spanning two adjacent
     * parts doesn't fit (the union of the parts isn't computed).
     */
    private function geometryWithinParts(array $geometry, array $parts): bool {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        $components = null;
        if ($type === 'MultiPoint') {
            $components = array_map(function ($p) { return ['type' => 'Point', 'coordinates' => $p]; }, $coords);
        } elseif ($type === 'MultiLineString') {
            $components = array_map(function ($l) { return ['type' => 'LineString', 'coordinates' => $l]; }, $coords);
        } elseif ($type === 'MultiPolygon') {
            $components = array_map(function ($p) { return ['type' => 'Polygon', 'coordinates' => $p]; }, $coords);
        } elseif ($type === 'GeometryCollection') {
            // Members without a position don't count; a collection without any position is never within
            $components = array_values(array_filter($geometry['geometries'] ?? [], function ($g) {
                return is_array($g) && $this->geometry->calculateMBR($g) !== null;
            }));
        }
        if ($components !== null) {
            if (empty($components)) {
                return false;
            }
            foreach ($components as $component) {
                if (!$this->geometryWithinParts($component, $parts)) {
                    return false;
                }
            }
            return true;
        }
        foreach ($parts as $part) {
            if ($this->geometryWithinPolygon($geometry, $part['coords'], $part['holePoints'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * A point strictly inside a ring: the middle of the first span a horizontal line between the two
     * lowest distinct y values has inside the ring. Null for a degenerate ring.
     */
    private function pointInsideRing(array $ring): ?array {
        $ys = array_values(array_unique(array_map(function ($p) { return (float)$p[1]; }, $ring)));
        sort($ys);
        if (count($ys) < 2) {
            return null;
        }
        $y = ($ys[0] + $ys[1]) / 2;
        $xs = [];
        $n = count($ring);
        for ($i = 0; $i < $n - 1; $i++) {
            [$x1, $y1] = [(float)$ring[$i][0], (float)$ring[$i][1]];
            [$x2, $y2] = [(float)$ring[$i + 1][0], (float)$ring[$i + 1][1]];
            if (($y1 <= $y && $y2 > $y) || ($y2 <= $y && $y1 > $y)) {
                $xs[] = $x1 + ($y - $y1) / ($y2 - $y1) * ($x2 - $x1);
            }
        }
        sort($xs);
        return count($xs) >= 2 ? [($xs[0] + $xs[1]) / 2, $y] : null;
    }

    /**
     * Check if a geometry lies entirely within a polygon. The polygon is closed (its boundary, also a
     * hole's boundary, belongs to it, as in withinBBox). Every vertex is inside, no edge crosses a ring of
     * the polygon, and every piece of an edge between polygon vertices on it is inside; a record polygon
     * must also not contain a point inside one of the polygon's holes ($holePoints).
     */
    private function geometryWithinPolygon(array $geometry, array $polygonCoords, ?array $holePoints = null): bool {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        if ($holePoints === null) {
            $holePoints = [];
            for ($h = 1; $h < count($polygonCoords); $h++) {
                $point = $this->pointInsideRing($polygonCoords[$h]);
                if ($point !== null) {
                    $holePoints[] = $point;
                }
            }
        }

        switch ($type) {
            case 'Point':
                return $this->pointInPolygonClosed((float)$coords[0], (float)$coords[1], $polygonCoords);
            case 'MultiPoint':
                foreach ($coords as $point) {
                    if (!$this->pointInPolygonClosed((float)$point[0], (float)$point[1], $polygonCoords)) {
                        return false;
                    }
                }
                return true;
            case 'LineString':
                return $this->pathWithinPolygon($coords, $polygonCoords);
            case 'MultiLineString':
                foreach ($coords as $line) {
                    if (!$this->pathWithinPolygon($line, $polygonCoords)) {
                        return false;
                    }
                }
                return true;
            case 'Polygon':
                return $this->polygonWithinPolygon($coords, $polygonCoords, $holePoints);
            case 'MultiPolygon':
                foreach ($coords as $poly) {
                    if (!$this->polygonWithinPolygon($poly, $polygonCoords, $holePoints)) {
                        return false;
                    }
                }
                return true;
            case 'GeometryCollection':
                // Members without a position don't count; a collection without any position is never within
                $located = false;
                foreach ($geometry['geometries'] ?? [] as $g) {
                    if (is_array($g) && $this->geometry->calculateMBR($g) === null) {
                        continue;
                    }
                    if (!$this->geometryWithinPolygon($g, $polygonCoords)) {
                        return false;
                    }
                    $located = true;
                }
                return $located;
        }
        return false;
    }

    /**
     * A polygon (record) within a polygon (query): all its rings within, and no point inside one of the
     * query's holes inside it (a record around a hole has its whole boundary inside the query)
     */
    private function polygonWithinPolygon(array $rings, array $polygonCoords, array $holePoints): bool {
        foreach ($rings as $ring) {
            if (!$this->pathWithinPolygon($ring, $polygonCoords)) {
                return false;
            }
        }
        foreach ($holePoints as [$x, $y]) {
            if ($this->geometry->pointInPolygon($x, $y, $rings)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Point inside a polygon or on its boundary (holes excluded, their boundary included)
     */
    private function pointInPolygonClosed(float $lon, float $lat, array $polygonCoords): bool {
        if (!$this->geoJson->pointOnRing($lon, $lat, $polygonCoords[0]) && !$this->geometry->pointInRing($lon, $lat, $polygonCoords[0])) {
            return false;
        }
        for ($i = 1; $i < count($polygonCoords); $i++) {
            if ($this->geometry->pointInRing($lon, $lat, $polygonCoords[$i]) && !$this->geoJson->pointOnRing($lon, $lat, $polygonCoords[$i])) {
                return false;
            }
        }
        return true;
    }

    /**
     * A path within the closed polygon: every vertex inside, no segment properly crossing a ring, and
     * every piece of a segment between polygon vertices lying on it inside (a segment can run along a
     * boundary or through a hole between two of its vertices)
     */
    private function pathWithinPolygon(array $points, array $polygonCoords): bool {
        foreach ($points as $point) {
            if (!$this->pointInPolygonClosed((float)$point[0], (float)$point[1], $polygonCoords)) {
                return false;
            }
        }
        $n = count($points);
        for ($i = 0; $i < $n - 1; $i++) {
            $a = [(float)$points[$i][0], (float)$points[$i][1]];
            $b = [(float)$points[$i + 1][0], (float)$points[$i + 1][1]];
            $ts = [0.0, 1.0];
            $alongX = abs($b[0] - $a[0]) >= abs($b[1] - $a[1]);
            foreach ($polygonCoords as $ring) {
                $m = count($ring);
                for ($j = 0; $j < $m - 1; $j++) {
                    if ($this->segmentsCrossProperly($a, $b, $ring[$j], $ring[$j + 1])) {
                        return false;
                    }
                    $v = [(float)$ring[$j][0], (float)$ring[$j][1]];
                    if ($this->geometry->crossProductDirection($a, $b, $v) == 0 && $this->geometry->onSegment($a, $b, $v) && $a != $b) {
                        $ts[] = $alongX ? ($v[0] - $a[0]) / ($b[0] - $a[0]) : ($v[1] - $a[1]) / ($b[1] - $a[1]);
                    }
                }
            }
            if (count($ts) > 2) {
                sort($ts);
                for ($k = 0; $k < count($ts) - 1; $k++) {
                    if ($ts[$k + 1] - $ts[$k] <= 0) {
                        continue;
                    }
                    $t = ($ts[$k] + $ts[$k + 1]) / 2;
                    if (!$this->pointInPolygonClosed($a[0] + $t * ($b[0] - $a[0]), $a[1] + $t * ($b[1] - $a[1]), $polygonCoords)) {
                        return false;
                    }
                }
            } elseif (!$this->pointInPolygonClosed(($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2, $polygonCoords)) {
                return false; // e.g. a chord between two boundary vertices across a concave notch
            }
        }
        return true;
    }

    /**
     * True when two segments cross at a single interior point (touching endpoints don't count)
     */
    private function segmentsCrossProperly(array $p1, array $p2, array $p3, array $p4): bool {
        $d1 = $this->geometry->crossProductDirection($p3, $p4, $p1);
        $d2 = $this->geometry->crossProductDirection($p3, $p4, $p2);
        $d3 = $this->geometry->crossProductDirection($p1, $p2, $p3);
        $d4 = $this->geometry->crossProductDirection($p1, $p2, $p4);
        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
    }

    public const EARTH_HALF_CIRCUMFERENCE = 20040000.0; // meters: no two points are farther apart
}
