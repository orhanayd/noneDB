<?php
// Geometry operations: bounding boxes, distances, containment
namespace noneDB\Internal\Index\Spatial;

use function array_merge, atan2, cos, count, deg2rad, max, min, sin, sqrt;

/**
 * @internal
 */
final class Geometry {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;


    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
    }

    /**
     * Drop the handles to the kernel and the other services (the instance is gone)
     */
    public function unbind(): void {
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // SPATIAL INDEX - GEOMETRY OPERATIONS
    // ==========================================

    /**
     * Calculate Minimum Bounding Rectangle (MBR) for any GeoJSON geometry
     * @param array $geometry GeoJSON geometry object
     * @return array|null [minLon, minLat, maxLon, maxLat]; null for a geometry without any position
     *                    (an empty GeometryCollection): it has no location and is never indexed or matched
     */
    public function calculateMBR(array $geometry): ?array {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];

        switch ($type) {
            case 'Point':
                return [$coords[0], $coords[1], $coords[0], $coords[1]];

            case 'LineString':
            case 'MultiPoint':
                return $this->mbrFromPoints($coords);

            case 'Polygon':
                // Use outer ring (first ring)
                return $this->mbrFromPoints($coords[0]);

            case 'MultiLineString':
                $allPoints = [];
                foreach ($coords as $lineString) {
                    $allPoints = array_merge($allPoints, $lineString);
                }
                return $this->mbrFromPoints($allPoints);

            case 'MultiPolygon':
                $allPoints = [];
                foreach ($coords as $polygon) {
                    $allPoints = array_merge($allPoints, $polygon[0]); // outer ring
                }
                return $this->mbrFromPoints($allPoints);

            case 'GeometryCollection':
                $mbr = null;
                foreach ($geometry['geometries'] as $geom) {
                    $geomMBR = $this->calculateMBR($geom);
                    if ($geomMBR !== null) {
                        $mbr = $mbr === null ? $geomMBR : $this->mbrUnion($mbr, $geomMBR);
                    }
                }
                return $mbr;

            default:
                return null;
        }
    }

    /**
     * Calculate MBR from array of points
     * @param array $points Array of [lon, lat] positions
     * @return array [minLon, minLat, maxLon, maxLat]
     */
    public function mbrFromPoints(array $points): ?array {
        if (empty($points)) {
            return null;
        }

        $minLon = $maxLon = $points[0][0];
        $minLat = $maxLat = $points[0][1];

        foreach ($points as $point) {
            $minLon = min($minLon, $point[0]);
            $maxLon = max($maxLon, $point[0]);
            $minLat = min($minLat, $point[1]);
            $maxLat = max($maxLat, $point[1]);
        }

        return [$minLon, $minLat, $maxLon, $maxLat];
    }

    /**
     * Union two MBRs
     * @param array $mbr1 [minLon, minLat, maxLon, maxLat]
     * @param array $mbr2 [minLon, minLat, maxLon, maxLat]
     * @return array [minLon, minLat, maxLon, maxLat]
     */
    private function mbrUnion(array $mbr1, array $mbr2): array {
        return [
            min($mbr1[0], $mbr2[0]), // minLon
            min($mbr1[1], $mbr2[1]), // minLat
            max($mbr1[2], $mbr2[2]), // maxLon
            max($mbr1[3], $mbr2[3])  // maxLat
        ];
    }

    /**
     * Check if two MBRs overlap
     * @param array $mbr1 [minLon, minLat, maxLon, maxLat]
     * @param array $mbr2 [minLon, minLat, maxLon, maxLat]
     * @return bool
     */
    public function mbrOverlaps(array $mbr1, array $mbr2): bool {
        // Check if one MBR is completely to the left, right, above, or below the other
        if ($mbr1[2] < $mbr2[0] || $mbr2[2] < $mbr1[0]) return false; // No horizontal overlap
        if ($mbr1[3] < $mbr2[1] || $mbr2[3] < $mbr1[1]) return false; // No vertical overlap
        return true;
    }

    /**
     * Calculate Haversine distance between two points (in meters). Not memoized: a key for two points costs
     * more than the formula
     * @param float $lon1 Longitude of point 1
     * @param float $lat1 Latitude of point 1
     * @param float $lon2 Longitude of point 2
     * @param float $lat2 Latitude of point 2
     * @return float Distance in meters
     */
    public function haversineDistance(float $lon1, float $lat1, float $lon2, float $lat2): float {
        $earthRadius = 6371000.0; // meters

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Point-in-Polygon test using Winding Number algorithm
     * More robust than ray casting for edge cases
     *
     * @param float $lon Point longitude
     * @param float $lat Point latitude
     * @param array $polygon GeoJSON Polygon coordinates [[[lon,lat], ...], ...]
     * @return bool
     */
    public function pointInPolygon(float $lon, float $lat, array $polygon): bool {
        // Handle GeoJSON Polygon object or raw coordinates
        $coords = isset($polygon['coordinates']) ? $polygon['coordinates'] : $polygon;

        // Check outer ring
        if (!$this->pointInRing($lon, $lat, $coords[0])) {
            return false;
        }

        // Check holes (if any)
        for ($i = 1; $i < count($coords); $i++) {
            if ($this->pointInRing($lon, $lat, $coords[$i])) {
                return false; // Inside a hole
            }
        }

        return true;
    }

    /**
     * Check if point is inside a ring using Winding Number algorithm
     */
    public function pointInRing(float $lon, float $lat, array $ring): bool {
        $windingNumber = 0;
        $n = count($ring);

        for ($i = 0; $i < $n - 1; $i++) {
            $x1 = $ring[$i][0];
            $y1 = $ring[$i][1];
            $x2 = $ring[$i + 1][0];
            $y2 = $ring[$i + 1][1];

            if ($y1 <= $lat) {
                if ($y2 > $lat) {
                    // Upward crossing
                    if ($this->isLeftOfLine($x1, $y1, $x2, $y2, $lon, $lat) > 0) {
                        $windingNumber++;
                    }
                }
            } else {
                if ($y2 <= $lat) {
                    // Downward crossing
                    if ($this->isLeftOfLine($x1, $y1, $x2, $y2, $lon, $lat) < 0) {
                        $windingNumber--;
                    }
                }
            }
        }

        return $windingNumber !== 0;
    }

    /**
     * Determine if point is left of a line (using cross product)
     * Returns: >0 if left, <0 if right, =0 if on line
     */
    private function isLeftOfLine(float $x1, float $y1, float $x2, float $y2, float $px, float $py): float {
        return (($x2 - $x1) * ($py - $y1)) - (($px - $x1) * ($y2 - $y1));
    }

    /**
     * Cross product direction calculation
     */
    public function crossProductDirection(array $p1, array $p2, array $p3): float {
        return ($p3[0] - $p1[0]) * ($p2[1] - $p1[1]) - ($p2[0] - $p1[0]) * ($p3[1] - $p1[1]);
    }

    /**
     * Check if point p is on segment p1-p2
     */
    public function onSegment(array $p1, array $p2, array $p): bool {
        return min($p1[0], $p2[0]) <= $p[0] && $p[0] <= max($p1[0], $p2[0]) &&
               min($p1[1], $p2[1]) <= $p[1] && $p[1] <= max($p1[1], $p2[1]);
    }
}
