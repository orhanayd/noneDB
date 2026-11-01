<?php
// noneDBQuery: chainable spatial methods
// Built into noneDB.php by tools/build.php: edit here, never noneDB.php
trait noneDBQuery_SpatialChain {
    // ==========================================
    // SPATIAL QUERY METHODS
    // ==========================================

    /**
     * Filter records within a distance from a point
     * @param string $field GeoJSON field name
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @param float $distanceMeters Distance in meters
     * @return self
     */
    public function withinDistance(string $field, float $lon, float $lat, float $distanceMeters): self {
        $this->spatialFilters[] = ['type' => 'withinDistance', 'field' => $field, 'lon' => $lon, 'lat' => $lat, 'distance' => $distanceMeters];
        return $this;
    }

    /**
     * Filter records within a bounding box
     * @param string $field GeoJSON field name
     * @param float $minLon Minimum longitude
     * @param float $minLat Minimum latitude
     * @param float $maxLon Maximum longitude
     * @param float $maxLat Maximum latitude
     * @return self
     */
    public function withinBBox(string $field, float $minLon, float $minLat, float $maxLon, float $maxLat): self {
        $this->spatialFilters[] = ['type' => 'withinBBox', 'field' => $field, 'minLon' => $minLon, 'minLat' => $minLat, 'maxLon' => $maxLon, 'maxLat' => $maxLat];
        return $this;
    }

    /**
     * The K records nearest to a point among those matching the other filters (adds _distance in meters)
     * @param string $field GeoJSON field name
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @param int $k Number of nearest records
     * @return self
     */
    public function nearest(string $field, float $lon, float $lat, int $k): self {
        $this->spatialFilters[] = ['type' => 'nearest', 'field' => $field, 'lon' => $lon, 'lat' => $lat, 'k' => max(0, $k)];
        return $this;
    }

    /**
     * Filter records within a polygon
     * @param string $field GeoJSON field name
     * @param array $polygon GeoJSON Polygon geometry
     * @return self
     */
    public function withinPolygon(string $field, array $polygon): self {
        $this->spatialFilters[] = ['type' => 'withinPolygon', 'field' => $field, 'polygon' => $polygon];
        return $this;
    }

    /**
     * Add _distance to every result: meters from the point to the geometry, measured like
     * withinDistance()/nearest() (0 inside a polygon, otherwise to the nearest point of the geometry).
     * It is computed before sorting, so sort('_distance') works.
     * @param string $field GeoJSON field name
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @return self
     */
    public function withDistance(string $field, float $lon, float $lat): self {
        $this->distanceConfig = ['field' => $field, 'lon' => $lon, 'lat' => $lat];
        return $this;
    }

}
