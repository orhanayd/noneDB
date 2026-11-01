<?php

namespace noneDB\Tests\Differential;

use noneDB\Tests\Integration\SpecSpatialTest;

use noneDB\Tests\RegressionTestCase;

/**
 * Spatial semantics (plan B3, B4, B13, B14, B15)
 */
class GeometryConsistencyTest extends RegressionTestCase
{
    private function square(float $a, float $b, array $holes = []): array
    {
        return array_merge([[[$a, $a], [$b, $a], [$b, $b], [$a, $b], [$a, $a]]], $holes);
    }

    /** Is $record within $query? Asked through withinPolygon() with and without the spatial index */
    private function within(array $record, array $query): bool
    {
        static $n = 0;
        $name = 'w' . (++$n);
        $results = [];
        foreach ([true, false] as $indexed) {
            $db = $this->db(['spatialIndexEnabled' => $indexed]);
            if ($indexed) {
                $db->createSpatialIndex($name, 'g');
                $db->insert($name, ['g' => $record]);
            }
            $results[] = count($db->withinPolygon($name, 'g', $query)) === 1;
        }
        $this->assertSame($results[0], $results[1], 'index == scan');
        return $results[0];
    }

    /** @test */
    public function polygonHolesAreRespected(): void
    {
        // B-3: the cases the plan verified by hand
        $hole = [[[4, 4], [6, 4], [6, 6], [4, 6], [4, 4]]];
        $query = ['type' => 'Polygon', 'coordinates' => $this->square(0, 10, $hole)];

        // A record around the query's hole is not within
        $this->assertFalse($this->within(['type' => 'Polygon', 'coordinates' => $this->square(2, 8)], $query));
        // Same hole in the record, the query's hole ring starting elsewhere: within
        $holeFrom66 = ['type' => 'Polygon', 'coordinates' => $this->square(0, 10, [[[6, 6], [4, 6], [4, 4], [6, 4], [6, 6]]])];
        $this->assertTrue($this->within(['type' => 'Polygon', 'coordinates' => $this->square(2, 8, $hole)], $holeFrom66));
        // A record hole that contains the query's hole: within
        $this->assertTrue($this->within(['type' => 'Polygon', 'coordinates' => $this->square(1, 9, [[[3, 3], [7, 3], [7, 7], [3, 7], [3, 3]]])], $query));

        // Diamond hole; the record's bottom edge runs through two hole vertices: not within
        $diamond = ['type' => 'Polygon', 'coordinates' => $this->square(0, 10, [[[5, 3], [6, 5], [5, 7], [4, 5], [5, 3]]])];
        $this->assertFalse($this->within(['type' => 'Polygon', 'coordinates' => [[[3, 5], [9.5, 5], [9.5, 9], [3, 9], [3, 5]]]], $diamond));
        $this->assertFalse($this->within(['type' => 'LineString', 'coordinates' => [[3, 5], [9.5, 5]]], $diamond), 'a line through the hole');
        $this->assertTrue($this->within(['type' => 'LineString', 'coordinates' => [[1, 1], [9, 1]]], $diamond));

        // Every hole vertex on the record's outer ring: the hole is inside the record, not within
        $kite = ['type' => 'Polygon', 'coordinates' => $this->square(0, 10, [[[4, 2], [8, 4], [6, 8], [2, 6], [4, 2]]])];
        $this->assertFalse($this->within(['type' => 'Polygon', 'coordinates' => $this->square(2, 8)], $kite));
    }

    /** @test */
    public function polygonBoundaryIsClosedLikeTheBoundingBox(): void
    {
        // B-13: points on the east and north edges were outside, on the west and south edges inside
        $rect = ['type' => 'Polygon', 'coordinates' => $this->square(0, 10)];
        foreach ([[10, 5], [5, 10], [10, 10], [0, 5], [5, 0], [0, 0], [3, 3]] as $point) {
            $this->assertTrue($this->within(['type' => 'Point', 'coordinates' => $point], $rect), json_encode($point));
        }
        $this->assertFalse($this->within(['type' => 'Point', 'coordinates' => [10.0001, 5]], $rect));
        $this->assertTrue($this->within(['type' => 'Polygon', 'coordinates' => $this->square(5, 10)], $rect), 'touching the east/north edges');

        $db = $this->db();
        $db->createSpatialIndex('edges', 'g');
        foreach ([[10, 5], [5, 10], [0, 0], [10, 10], [11, 5], [4, 4]] as $point) {
            $db->insert('edges', ['g' => ['type' => 'Point', 'coordinates' => $point]]);
        }
        $this->assertSame(
            array_column($db->withinBBox('edges', 'g', 0, 0, 10, 10), 'key'),
            array_column($db->withinPolygon('edges', 'g', $rect), 'key')
        );
    }

    /** @test */
    public function multiPartRecordsMayUseDifferentQueryParts(): void
    {
        // B-14: each part of a multi-part record had to fit one and the same query part
        $twoSquares = ['type' => 'MultiPolygon', 'coordinates' => [
            [[[0, 0], [5, 0], [5, 5], [0, 5], [0, 0]]],
            [[[5, 0], [10, 0], [10, 5], [5, 5], [5, 0]]],
        ]];
        $this->assertTrue($this->within(['type' => 'MultiPoint', 'coordinates' => [[2, 2], [7, 2]]], $twoSquares));
        $this->assertTrue($this->within(['type' => 'MultiLineString', 'coordinates' => [[[1, 1], [4, 1]], [[6, 1], [9, 1]]]], $twoSquares));
        // Documented limit: one part spanning two query parts is not within (the union isn't computed)
        $this->assertFalse($this->within(['type' => 'LineString', 'coordinates' => [[2, 2], [7, 2]]], $twoSquares));
    }

    /** @test */
    public function nearestAfterAnotherSpatialFilterUsesItsOwnDistance(): void
    {
        // B-4: the intersection kept the _distance of the first filter, so nearest() sorted by it
        $db = $this->db();
        $db->createSpatialIndex('nd', 'loc');
        $db->insert('nd', [['n' => 'P0', 'loc' => ['type' => 'Point', 'coordinates' => [29.00, 41.00]]],
                           ['n' => 'P1', 'loc' => ['type' => 'Point', 'coordinates' => [29.05, 41.00]]]]);
        $rows = $db->query('nd')->withinDistance('loc', 29.0, 41.0, 10000)->nearest('loc', 29.05, 41.0, 1)->get();
        $this->assertSame(['P1'], array_column($rows, 'n'));
        $this->assertSame(0.0, $rows[0]['_distance'], "nearest()'s distance, not withinDistance()'s 4.2 km");
        $both = $db->query('nd')->withinDistance('loc', 29.0, 41.0, 10000)->nearest('loc', 29.05, 41.0, 2)->get();
        $this->assertSame(['P1', 'P0'], array_column($both, 'n'));
        $this->assertEqualsWithDelta(SpecSpatialTest::hav(29.05, 41.0, 29.0, 41.0), $both[1]['_distance'], 0.006);
    }

    /** @test */
    public function rebuildSpatialIndexWhileDisabledKeepsTheIndex(): void
    {
        // B-15: it dropped the index first, then creating it failed with "disabled"
        $db = $this->db();
        $db->createSpatialIndex('rbd', 'loc');
        $db->insert('rbd', ['loc' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        $disabled = $this->db(['spatialIndexEnabled' => false]);
        $result = $disabled->rebuildSpatialIndex('rbd', 'loc');
        $this->assertFalse($result['success']);
        $this->assertSame(['success' => false, 'indexed' => 0, 'error' => 'Spatial indexing is disabled'], $result);
        $this->assertSame(['loc'], $db->getSpatialIndexes('rbd'), 'still listed');
        $this->assertCount(1, $db->withinBBox('rbd', 'loc', 0, 0, 2, 2));
        $this->assertFileExists($this->dbPath('rbd', 'rx', 'loc'), 'the index file is kept');
    }

    /** @test docs: "points on its boundary are inside, on every edge": diagonal edges too (exact coordinates) */
    public function diagonalEdgesAreClosedToo(): void
    {
        $triangle = ['type' => 'Polygon', 'coordinates' => [[[0, 0], [8, 0], [0, 8], [0, 0]]]];
        foreach ([[4, 4], [2, 6], [0.5, 7.5], [7.75, 0.25], [8, 0], [0, 8], [3, 0], [0, 3], [1, 1]] as $point) {
            $this->assertTrue($this->within(['type' => 'Point', 'coordinates' => $point], $triangle), json_encode($point));
        }
        foreach ([[4.0000001, 4], [4, 4.0000001], [8.0000001, 0], [-0.0000001, 3]] as $point) {
            $this->assertFalse($this->within(['type' => 'Point', 'coordinates' => $point], $triangle), json_encode($point));
        }
        $this->assertTrue($this->within(['type' => 'LineString', 'coordinates' => [[8, 0], [0, 8]]], $triangle), 'along the diagonal');
        $this->assertTrue($this->within(['type' => 'LineString', 'coordinates' => [[0, 0], [4, 4]]], $triangle), 'a chord to the diagonal');
        $this->assertFalse($this->within(['type' => 'LineString', 'coordinates' => [[0, 0], [5, 5]]], $triangle), 'through the diagonal');
        $this->assertTrue($this->within(['type' => 'Polygon', 'coordinates' => [[[0, 0], [8, 0], [0, 8], [0, 0]]]], $triangle), 'itself');
    }
}
