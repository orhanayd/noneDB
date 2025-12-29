<?php
/**
 * GeoJSON Validation Unit Tests
 * Tests all GeoJSON geometry type validations
 * @version 3.1.0
 */

namespace noneDB\Tests\Unit;

use noneDB\Tests\noneDBTestCase;

class GeoJSONValidationTest extends noneDBTestCase
{
    /**
     * Test valid Point geometry
     */
    public function testValidPoint(): void
    {
        $geometry = [
            'type' => 'Point',
            'coordinates' => [28.9803, 41.0086]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertTrue($result['valid']);
        $this->assertNull($result['error'] ?? null);
    }

    /**
     * Test Point with invalid coordinates (not array)
     */
    public function testPointInvalidCoordinatesType(): void
    {
        $geometry = [
            'type' => 'Point',
            'coordinates' => 'invalid'
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('array', $result['error']);
    }

    /**
     * Test Point with missing coordinates
     */
    public function testPointMissingCoordinates(): void
    {
        $geometry = [
            'type' => 'Point'
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertFalse($result['valid']);
    }

    /**
     * Test Point with wrong number of coordinates
     */
    public function testPointWrongCoordinateCount(): void
    {
        $geometry = [
            'type' => 'Point',
            'coordinates' => [28.9803]  // Only one coordinate
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertFalse($result['valid']);
    }

    /**
     * Test Point with invalid longitude (out of range)
     */
    public function testPointInvalidLongitude(): void
    {
        $geometry = [
            'type' => 'Point',
            'coordinates' => [200, 41.0086]  // Longitude > 180
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('longitude', strtolower($result['error']));
    }

    /**
     * Test Point with invalid latitude (out of range)
     */
    public function testPointInvalidLatitude(): void
    {
        $geometry = [
            'type' => 'Point',
            'coordinates' => [28.9803, 100]  // Latitude > 90
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('latitude', strtolower($result['error']));
    }

    /**
     * Test valid LineString geometry
     */
    public function testValidLineString(): void
    {
        $geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [28.9803, 41.0086],
                [29.0097, 41.0422]
            ]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertTrue($result['valid']);
    }

    /**
     * Test LineString with only one point (invalid)
     */
    public function testLineStringTooFewPoints(): void
    {
        $geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [28.9803, 41.0086]
            ]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('2', $result['error']);
    }

    /**
     * Test valid Polygon geometry
     */
    public function testValidPolygon(): void
    {
        $geometry = [
            'type' => 'Polygon',
            'coordinates' => [[
                [28.97, 41.00],
                [28.99, 41.00],
                [28.99, 41.02],
                [28.97, 41.02],
                [28.97, 41.00]  // Closed ring
            ]]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertTrue($result['valid']);
    }

    /**
     * Test Polygon with unclosed ring (invalid)
     */
    public function testPolygonUnclosedRing(): void
    {
        $geometry = [
            'type' => 'Polygon',
            'coordinates' => [[
                [28.97, 41.00],
                [28.99, 41.00],
                [28.99, 41.02],
                [28.97, 41.02]
                // Missing closing point
            ]]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('closed', strtolower($result['error']));
    }

    /**
     * Test Polygon with too few points
     */
    public function testPolygonTooFewPoints(): void
    {
        $geometry = [
            'type' => 'Polygon',
            'coordinates' => [[
                [28.97, 41.00],
                [28.99, 41.00],
                [28.97, 41.00]  // Only 3 points (triangle needs 4 minimum)
            ]]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertFalse($result['valid']);
    }

    /**
     * Test Polygon with hole
     */
    public function testPolygonWithHole(): void
    {
        $geometry = [
            'type' => 'Polygon',
            'coordinates' => [
                // Outer ring
                [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]],
                // Inner ring (hole)
                [[2, 2], [8, 2], [8, 8], [2, 8], [2, 2]]
            ]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertTrue($result['valid']);
    }

    /**
     * Test valid MultiPoint geometry
     */
    public function testValidMultiPoint(): void
    {
        $geometry = [
            'type' => 'MultiPoint',
            'coordinates' => [
                [28.9803, 41.0086],
                [29.0097, 41.0422],
                [28.8493, 41.0136]
            ]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertTrue($result['valid']);
    }

    /**
     * Test valid MultiLineString geometry
     */
    public function testValidMultiLineString(): void
    {
        $geometry = [
            'type' => 'MultiLineString',
            'coordinates' => [
                [[28.97, 41.00], [28.99, 41.02]],
                [[29.00, 41.03], [29.02, 41.05]]
            ]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertTrue($result['valid']);
    }

    /**
     * Test valid MultiPolygon geometry
     */
    public function testValidMultiPolygon(): void
    {
        $geometry = [
            'type' => 'MultiPolygon',
            'coordinates' => [
                [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]],
                [[[2, 2], [3, 2], [3, 3], [2, 3], [2, 2]]]
            ]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertTrue($result['valid']);
    }

    /**
     * Test valid GeometryCollection
     */
    public function testValidGeometryCollection(): void
    {
        $geometry = [
            'type' => 'GeometryCollection',
            'geometries' => [
                ['type' => 'Point', 'coordinates' => [28.9803, 41.0086]],
                ['type' => 'LineString', 'coordinates' => [[28.97, 41.00], [28.99, 41.02]]]
            ]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertTrue($result['valid']);
    }

    /**
     * Test GeometryCollection with invalid geometry
     */
    public function testGeometryCollectionWithInvalidGeometry(): void
    {
        $geometry = [
            'type' => 'GeometryCollection',
            'geometries' => [
                ['type' => 'Point', 'coordinates' => [28.9803, 41.0086]],
                ['type' => 'Point', 'coordinates' => [200, 41.0086]]  // Invalid longitude
            ]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertFalse($result['valid']);
    }

    /**
     * Test missing type field
     */
    public function testMissingType(): void
    {
        $geometry = [
            'coordinates' => [28.9803, 41.0086]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('type', strtolower($result['error']));
    }

    /**
     * Test invalid geometry type
     */
    public function testInvalidType(): void
    {
        $geometry = [
            'type' => 'InvalidType',
            'coordinates' => [28.9803, 41.0086]
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('unknown', strtolower($result['error']));
    }

    /**
     * Test non-array input
     */
    public function testNonArrayInput(): void
    {
        $result = $this->noneDB->validateGeoJSON('not an array');
        $this->assertFalse($result['valid']);
    }

    /**
     * Test Point with 3D coordinates (z-value allowed)
     */
    public function testPoint3D(): void
    {
        $geometry = [
            'type' => 'Point',
            'coordinates' => [28.9803, 41.0086, 100]  // With elevation
        ];

        $result = $this->noneDB->validateGeoJSON($geometry);
        $this->assertTrue($result['valid']);
    }
}
