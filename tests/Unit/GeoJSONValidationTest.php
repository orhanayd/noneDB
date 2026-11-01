<?php
/**
 * GeoJSON Validation Unit Tests
 * Tests all GeoJSON geometry type validations
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
        $this->assertSame(['valid' => true, 'error' => null, 'type' => 'Point'], $result);
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
        $this->assertSame(['valid' => false, 'error' => 'Coordinates must be an array', 'type' => null], $result); // docs/SPATIAL.md error table
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
        $this->assertIsString($result['error']);
        $this->assertNotSame('', $result['error']);
        $this->assertNull($result['type']);
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
        $this->assertIsString($result['error']);
        $this->assertNull($result['type']);
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
        $this->assertSame('Longitude must be between -180 and 180', $result['error']); // docs/SPATIAL.md error table
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
        $this->assertSame('Latitude must be between -90 and 90', $result['error']); // docs/SPATIAL.md error table
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
        $this->assertSame(['valid' => true, 'error' => null, 'type' => 'LineString'], $result);
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
        $this->assertStringContainsString('at least 2', $result['error']);
        $this->assertNull($result['type']);
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
        $this->assertSame(['valid' => true, 'error' => null, 'type' => 'Polygon'], $result);
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
        $this->assertSame('Ring 0 must be closed (first and last positions must be identical)', $result['error']); // docs
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
        $this->assertSame('Ring 0 must have at least 4 positions', $result['error']); // docs/SPATIAL.md error table
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
        $this->assertSame(['valid' => true, 'error' => null, 'type' => 'Polygon'], $result);
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
        $this->assertSame(['valid' => true, 'error' => null, 'type' => 'MultiPoint'], $result);
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
        $this->assertSame(['valid' => true, 'error' => null, 'type' => 'MultiLineString'], $result);
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
        $this->assertSame(['valid' => true, 'error' => null, 'type' => 'MultiPolygon'], $result);
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
        $this->assertSame(['valid' => true, 'error' => null, 'type' => 'GeometryCollection'], $result);
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
        $this->assertStringContainsString('Longitude must be between -180 and 180', $result['error']);
        $this->assertNull($result['type']);
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
        $this->assertSame('Missing type property', $result['error']); // docs/SPATIAL.md error table
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
        $this->assertSame('Unknown geometry type: InvalidType', $result['error']); // docs: "Unknown geometry type: X"
    }

    /**
     * Test non-array input
     */
    public function testNonArrayInput(): void
    {
        $result = $this->noneDB->validateGeoJSON('not an array');
        $this->assertFalse($result['valid']);
        $this->assertIsString($result['error']);
        $this->assertNull($result['type']);
        foreach ([null, 42, 1.5, true, new \stdClass()] as $value) {
            $this->assertFalse($this->noneDB->validateGeoJSON($value)['valid'], gettype($value));
        }
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
        $this->assertSame(['valid' => true, 'error' => null, 'type' => 'Point'], $result);
    }

    // ========== Validation rules (docs/SPATIAL.md "GeoJSON Data Format" and "Error Handling") ==========

    private function assertInvalid(array $geometry, ?string $error = null, string $contains = ''): void
    {
        $result = $this->noneDB->validateGeoJSON($geometry);
        $label = json_encode($geometry);
        $this->assertFalse($result['valid'], $label);
        $this->assertNull($result['type'], $label);
        $this->assertIsString($result['error'], $label);
        if ($error !== null) {
            $this->assertSame($error, $result['error'], $label);
        }
        if ($contains !== '') {
            $this->assertStringContainsString($contains, $result['error'], $label);
        }
    }

    private function assertValid(array $geometry, string $type): void
    {
        $this->assertSame(['valid' => true, 'error' => null, 'type' => $type], $this->noneDB->validateGeoJSON($geometry), json_encode($geometry));
    }

    /**
     * Longitude -180..180 and latitude -90..90, both ends included
     */
    public function testCoordinateRangesIncludeTheirEnds(): void
    {
        foreach ([[180, 90], [-180, -90], [180, -90], [-180, 90], [0, 0], [-0.0, -0.0]] as $p) {
            $this->assertValid(['type' => 'Point', 'coordinates' => $p], 'Point');
        }
        foreach ([[180.0000001, 0], [-180.0000001, 0], [360, 0]] as $p) {
            $this->assertInvalid(['type' => 'Point', 'coordinates' => $p], 'Longitude must be between -180 and 180');
        }
        foreach ([[0, 90.0000001], [0, -90.0000001], [0, 180]] as $p) {
            $this->assertInvalid(['type' => 'Point', 'coordinates' => $p], 'Latitude must be between -90 and 90');
        }
        $this->assertInvalid(['type' => 'LineString', 'coordinates' => [[0, 0], [181, 0]]], null, 'Longitude must be between -180 and 180');
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 91], [0, 0]]]], null, 'Latitude must be between -90 and 90');
    }

    /**
     * docs: "Point coordinates must be finite numbers" for NaN, INF or strings
     */
    public function testCoordinatesMustBeFiniteNumbers(): void
    {
        foreach ([[NAN, 0], [0, NAN], [INF, 0], [0, -INF], ['1', '2'], ['28.9', 41.0], [true, 1], [1, null], [[1], [2]], ['a', 'b']] as $p) {
            $this->assertInvalid(['type' => 'Point', 'coordinates' => $p], 'Point coordinates must be finite numbers');
        }
        $this->assertInvalid(['type' => 'LineString', 'coordinates' => [[0, 0], [NAN, 1]]], null, 'Point coordinates must be finite numbers');
        $this->assertInvalid(['type' => 'MultiPoint', 'coordinates' => [[0, 0], [1, INF]]], null, 'Point coordinates must be finite numbers');
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], ['1', 1], [0, 0]]]], null, 'Point coordinates must be finite numbers');
        $this->assertValid(['type' => 'Point', 'coordinates' => [1, 2.5]], 'Point');
    }

    /**
     * docs: flat coordinates, string-keyed coordinates and a non-string type are invalid (an invalid result,
     * never accepted and never an exception)
     */
    public function testMalformedStructuresAreInvalidNotErrors(): void
    {
        // flat coordinates
        $this->assertInvalid(['type' => 'LineString', 'coordinates' => [1, 2]]);
        $this->assertInvalid(['type' => 'LineString', 'coordinates' => [1, 2, 3, 4]]);
        $this->assertInvalid(['type' => 'MultiPoint', 'coordinates' => [1, 2]]);
        $this->assertInvalid(['type' => 'MultiLineString', 'coordinates' => [[1, 2], [3, 4]]]);
        $this->assertInvalid(['type' => 'MultiLineString', 'coordinates' => [5, 6]]);
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [[1, 2], [3, 4], [5, 6], [1, 2]]]);
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [1, 2, 3, 4]]);
        $this->assertInvalid(['type' => 'MultiPolygon', 'coordinates' => [[[1, 2], [3, 4], [5, 6], [1, 2]]]]);
        $this->assertInvalid(['type' => 'MultiPolygon', 'coordinates' => [5]]);
        // string keys (JSON objects instead of arrays)
        $this->assertInvalid(['type' => 'Point', 'coordinates' => ['lon' => 1, 'lat' => 2]], 'Point coordinates must be finite numbers');
        $this->assertInvalid(['type' => 'LineString', 'coordinates' => ['a' => [0, 0], 'b' => [1, 1]]]);
        $this->assertInvalid(['type' => 'MultiPoint', 'coordinates' => ['a' => [0, 0]]]);
        $this->assertInvalid(['type' => 'MultiLineString', 'coordinates' => ['a' => [[0, 0], [1, 1]]]]);
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => ['outer' => [[0, 0], [1, 0], [1, 1], [0, 0]]]]);
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [['a' => [0, 0], 'b' => [1, 0], 'c' => [1, 1], 'd' => [0, 0]]]]);
        $this->assertInvalid(['type' => 'MultiPolygon', 'coordinates' => ['p' => [[[0, 0], [1, 0], [1, 1], [0, 0]]]]]);
        $this->assertInvalid(['type' => 'GeometryCollection', 'geometries' => ['a' => ['type' => 'Point', 'coordinates' => [0, 0]]]]);
        // a list in another order is not a list
        $this->assertInvalid(['type' => 'LineString', 'coordinates' => [1 => [1, 1], 0 => [0, 0]]]);
        // the type must be a string, and a known one (GeoJSON names are case-sensitive)
        foreach ([true, false, 0, 1, 1.5, ['Point']] as $type) {
            $this->assertInvalid(['type' => $type, 'coordinates' => [0, 0]]);
        }
        $this->assertInvalid(['type' => null, 'coordinates' => [0, 0]], 'Missing type property');
        $this->assertInvalid(['type' => 'point', 'coordinates' => [0, 0]], 'Unknown geometry type: point');
        $this->assertInvalid(['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [0, 0]]]);
        // coordinates missing or not an array
        foreach (['Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon'] as $type) {
            $this->assertInvalid(['type' => $type]);
            $this->assertInvalid(['type' => $type, 'coordinates' => 'x'], 'Coordinates must be an array');
            $this->assertInvalid(['type' => $type, 'coordinates' => null]);
        }
    }

    /**
     * docs: "A third value (altitude) is accepted and ignored"
     */
    public function testAltitudeIsAcceptedInEveryType(): void
    {
        $this->assertValid(['type' => 'Point', 'coordinates' => [1, 2, 3]], 'Point');
        $this->assertValid(['type' => 'LineString', 'coordinates' => [[0, 0, 10], [1, 1, 20]]], 'LineString');
        $this->assertValid(['type' => 'MultiPoint', 'coordinates' => [[0, 0, 5]]], 'MultiPoint');
        $this->assertValid(['type' => 'Polygon', 'coordinates' => [[[0, 0, 1], [1, 0, 1], [1, 1, 1], [0, 0, 1]]]], 'Polygon');
        $this->assertValid(['type' => 'Polygon', 'coordinates' => [[[0, 0, 1], [1, 0], [1, 1, 7], [0, 0, 99]]]], 'Polygon'); // closed: lon/lat compared
    }

    /**
     * docs: rings need 4+ positions, first == last (numerically), no self-intersection; holes inside or on the
     * outer ring; the errors name the ring
     */
    public function testPolygonRingRules(): void
    {
        $outer = [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]];
        $this->assertValid(['type' => 'Polygon', 'coordinates' => [[[0, 0], [4, 0], [0, 3], [0, 0]]]], 'Polygon'); // a triangle: 4 positions
        $this->assertValid(['type' => 'Polygon', 'coordinates' => [[[29, 41], [30, 41], [30, 42], [29.0, 41.0]]]], 'Polygon'); // 29 == 29.0
        $this->assertValid(['type' => 'Polygon', 'coordinates' => [$outer, [[0, 0], [5, 0], [0, 5], [0, 0]]]], 'Polygon'); // hole vertices on the outer ring
        $this->assertValid(['type' => 'Polygon', 'coordinates' => [$outer, [[1, 1], [2, 1], [2, 2], [1, 1]], [[5, 5], [6, 5], [6, 6], [5, 5]]]], 'Polygon');

        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => []], null);
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [0, 0]]]], 'Ring 0 must have at least 4 positions');
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [[]]], 'Ring 0 must have at least 4 positions');
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 1]]]], 'Ring 0 must be closed (first and last positions must be identical)');
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0.0000001]]]], 'Ring 0 must be closed (first and last positions must be identical)');
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [[[0, 0], [10, 10], [10, 0], [0, 10], [0, 0]]]], 'Ring 0 must not intersect itself'); // bow-tie
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [[[0, 0], [2, 0], [1, 1], [2, 2], [0, 2], [1, 1], [0, 0]]]], 'Ring 0 must not intersect itself'); // touches itself
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [$outer, [[1, 1], [2, 1], [2, 2]]]], 'Ring 1 must have at least 4 positions');
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [$outer, [[1, 1], [2, 1], [2, 2], [1, 2]]]], 'Ring 1 must be closed (first and last positions must be identical)');
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [$outer, [[1, 1], [5, 5], [5, 1], [1, 5], [1, 1]]]], 'Ring 1 must not intersect itself');
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [$outer, [[20, 20], [21, 20], [21, 21], [20, 20]]]], 'Hole 1 must lie inside the outer ring');
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [$outer, [[5, 5], [11, 5], [5, 6], [5, 5]]]], 'Hole 1 must lie inside the outer ring'); // one vertex out
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [$outer, [[1, 1], [2, 1], [2, 2], [1, 1]], [[5, 5], [10.5, 5], [5, 6], [5, 5]]]], 'Hole 2 must lie inside the outer ring');
        $this->assertInvalid(['type' => 'Polygon', 'coordinates' => [$outer, [[1, 1], [2, 1], [1, NAN], [1, 1]]]], null, 'Invalid position at ring 1, index 2');
    }

    /**
     * Multi-part types need at least one valid member; GeometryCollection may be empty (docs)
     */
    public function testMultiPartAndCollectionRules(): void
    {
        $this->assertInvalid(['type' => 'MultiPoint', 'coordinates' => []]);
        $this->assertInvalid(['type' => 'MultiLineString', 'coordinates' => []]);
        $this->assertInvalid(['type' => 'MultiPolygon', 'coordinates' => []]);
        $this->assertInvalid(['type' => 'MultiPoint', 'coordinates' => [[0, 0], [200, 0]]], null, 'Longitude must be between -180 and 180');
        $this->assertInvalid(['type' => 'MultiLineString', 'coordinates' => [[[0, 0], [1, 1]], [[0, 0]]]], null);
        $this->assertInvalid(['type' => 'MultiLineString', 'coordinates' => [[[0, 0], [1, 1]], [[0, 0], [1, 95]]]], null, 'Latitude must be between -90 and 90');
        $this->assertInvalid(['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [1, 0], [1, 1], [0, 0]]], [[[0, 0], [1, 0], [1, 1], [0, 1]]]]], null,
            'Ring 0 must be closed (first and last positions must be identical)');
        $this->assertValid(['type' => 'MultiLineString', 'coordinates' => [[[0, 0], [1, 1]]]], 'MultiLineString');

        // "An empty collection is valid but has no location"
        $this->assertValid(['type' => 'GeometryCollection', 'geometries' => []], 'GeometryCollection');
        $this->assertValid(['type' => 'GeometryCollection', 'geometries' => [['type' => 'GeometryCollection', 'geometries' => []]]], 'GeometryCollection');
        $this->assertValid(['type' => 'GeometryCollection', 'geometries' => [['type' => 'GeometryCollection', 'geometries' => [['type' => 'Point', 'coordinates' => [1, 1]]]]]], 'GeometryCollection');
        $this->assertInvalid(['type' => 'GeometryCollection']);
        $this->assertInvalid(['type' => 'GeometryCollection', 'geometries' => 'x']);
        $this->assertInvalid(['type' => 'GeometryCollection', 'geometries' => [5]]);
        $this->assertInvalid(['type' => 'GeometryCollection', 'geometries' => ['Point']]);
        $this->assertInvalid(['type' => 'GeometryCollection', 'geometries' => [['type' => 'GeometryCollection', 'geometries' => [['type' => 'Point', 'coordinates' => [0, 91]]]]]], null,
            'Latitude must be between -90 and 90');
    }
}
