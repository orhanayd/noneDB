<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for method chaining (fluent interface)
 */
class ChainingTest extends noneDBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Insert test data
        $this->noneDB->insert($this->testDbName, [
            ['name' => 'Alice', 'age' => 25, 'score' => 85, 'city' => 'Istanbul', 'email' => 'alice@gmail.com', 'active' => true],
            ['name' => 'Bob', 'age' => 30, 'score' => 90, 'city' => 'Ankara', 'email' => 'bob@yahoo.com', 'active' => true],
            ['name' => 'Charlie', 'age' => 35, 'score' => 75, 'city' => 'Istanbul', 'email' => 'charlie@gmail.com', 'active' => false],
            ['name' => 'David', 'age' => 28, 'score' => 95, 'city' => 'Izmir', 'email' => 'david@hotmail.com', 'active' => true],
            ['name' => 'Eve', 'age' => 22, 'score' => 80, 'city' => 'Istanbul', 'email' => 'eve@gmail.com', 'active' => true],
        ]);
    }

    // ==========================================
    // QUERY BUILDER CREATION
    // ==========================================

    /**
     * @test
     */
    public function queryReturnsQueryBuilder(): void
    {
        $query = $this->noneDB->query($this->testDbName);

        $this->assertInstanceOf(\noneDBQuery::class, $query);
    }

    // ==========================================
    // CHAINABLE METHODS
    // ==========================================

    /**
     * @test
     */
    public function whereFiltersResults(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->get();

        $this->assertCount(3, $results);
        foreach ($results as $record) {
            $this->assertEquals('Istanbul', $record['city']);
        }
    }

    /**
     * @test
     */
    public function whereWithMultipleFilters(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul', 'active' => true])
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function likeFiltersWithContains(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('email', 'gmail')
            ->get();

        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function likeFiltersWithStartsWith(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('name', '^A')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Alice', $results[0]['name']);
    }

    /**
     * @test
     */
    public function likeFiltersWithEndsWith(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->like('email', 'gmail.com$')
            ->get();

        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function betweenFiltersRange(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->between('age', 25, 30)
            ->get();

        $this->assertCount(3, $results);
        foreach ($results as $record) {
            $this->assertGreaterThanOrEqual(25, $record['age']);
            $this->assertLessThanOrEqual(30, $record['age']);
        }
    }

    /**
     * @test
     */
    public function sortAscending(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('age', 'asc')
            ->get();

        $this->assertEquals(22, $results[0]['age']);
        $this->assertEquals(35, $results[4]['age']);
    }

    /**
     * @test
     */
    public function sortDescending(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->sort('score', 'desc')
            ->get();

        $this->assertEquals(95, $results[0]['score']);
        $this->assertEquals(75, $results[4]['score']);
    }

    /**
     * @test
     */
    public function limitResults(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->limit(3)
            ->get();

        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function offsetResults(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->offset(2)
            ->get();

        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function limitWithOffset(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->offset(1)
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function multipleChaining(): void
    {
        $results = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->between('age', 20, 30)
            ->sort('score', 'desc')
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('David', $results[0]['name']); // highest score
    }

    // ==========================================
    // TERMINAL METHODS
    // ==========================================

    /**
     * @test
     */
    public function getReturnsArray(): void
    {
        $results = $this->noneDB->query($this->testDbName)->get();

        $this->assertIsArray($results);
        $this->assertCount(5, $results);
    }

    /**
     * @test
     */
    public function firstReturnsFirstRecord(): void
    {
        $result = $this->noneDB->query($this->testDbName)->first();

        $this->assertIsArray($result);
        $this->assertEquals('Alice', $result['name']);
    }

    /**
     * @test
     */
    public function firstWithFilterReturnsFirstMatch(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->first();

        $this->assertEquals('Alice', $result['name']);
    }

    /**
     * @test
     */
    public function firstReturnsNullForNoMatch(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'NonExistent'])
            ->first();

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function lastReturnsLastRecord(): void
    {
        $result = $this->noneDB->query($this->testDbName)->last();

        $this->assertIsArray($result);
        $this->assertEquals('Eve', $result['name']);
    }

    /**
     * @test
     */
    public function lastWithSortReturnsCorrectRecord(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->sort('age', 'asc')
            ->last();

        $this->assertEquals('Charlie', $result['name']); // oldest
    }

    /**
     * @test
     */
    public function countReturnsInteger(): void
    {
        $count = $this->noneDB->query($this->testDbName)->count();

        $this->assertIsInt($count);
        $this->assertEquals(5, $count);
    }

    /**
     * @test
     */
    public function countWithFilter(): void
    {
        $count = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->count();

        $this->assertEquals(4, $count);
    }

    /**
     * @test
     */
    public function existsReturnsTrueWhenFound(): void
    {
        $exists = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Alice'])
            ->exists();

        $this->assertTrue($exists);
    }

    /**
     * @test
     */
    public function existsReturnsFalseWhenNotFound(): void
    {
        $exists = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'NonExistent'])
            ->exists();

        $this->assertFalse($exists);
    }

    // ==========================================
    // AGGREGATION METHODS
    // ==========================================

    /**
     * @test
     */
    public function sumCalculatesTotal(): void
    {
        $sum = $this->noneDB->query($this->testDbName)->sum('score');

        $this->assertEquals(425, $sum); // 85+90+75+95+80
    }

    /**
     * @test
     */
    public function sumWithFilter(): void
    {
        $sum = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->sum('score');

        $this->assertEquals(240, $sum); // 85+75+80
    }

    /**
     * @test
     */
    public function avgCalculatesAverage(): void
    {
        $avg = $this->noneDB->query($this->testDbName)->avg('score');

        $this->assertEquals(85, $avg); // 425/5
    }

    /**
     * @test
     */
    public function avgWithFilter(): void
    {
        $avg = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->avg('age');

        $this->assertEquals(26.25, $avg); // (25+30+28+22)/4
    }

    /**
     * @test
     */
    public function minReturnsMinimum(): void
    {
        $min = $this->noneDB->query($this->testDbName)->min('age');

        $this->assertEquals(22, $min);
    }

    /**
     * @test
     */
    public function maxReturnsMaximum(): void
    {
        $max = $this->noneDB->query($this->testDbName)->max('score');

        $this->assertEquals(95, $max);
    }

    /**
     * @test
     */
    public function distinctReturnsUniqueValues(): void
    {
        $cities = $this->noneDB->query($this->testDbName)->distinct('city');

        $this->assertCount(3, $cities);
        $this->assertContains('Istanbul', $cities);
        $this->assertContains('Ankara', $cities);
        $this->assertContains('Izmir', $cities);
    }

    /**
     * @test
     */
    public function distinctWithFilter(): void
    {
        $cities = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->distinct('city');

        $this->assertCount(3, $cities);
    }

    // ==========================================
    // WRITE METHODS
    // ==========================================

    /**
     * @test
     */
    public function updateWithChaining(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->update(['verified' => true]);

        $this->assertEquals(3, $result['n']);

        // Verify update
        $istanbul = $this->noneDB->find($this->testDbName, ['city' => 'Istanbul']);
        foreach ($istanbul as $record) {
            $this->assertTrue($record['verified']);
        }
    }

    /**
     * @test
     */
    public function updateWithMultipleFilters(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['active' => true])
            ->between('age', 25, 30)
            ->update(['tier' => 'gold']);

        $this->assertEquals(3, $result['n']);
    }

    /**
     * @test
     */
    public function updateReturnsZeroForNoMatch(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'NonExistent'])
            ->update(['verified' => true]);

        $this->assertEquals(0, $result['n']);
    }

    /**
     * @test
     */
    public function deleteWithChaining(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['active' => false])
            ->delete();

        $this->assertEquals(1, $result['n']);
        $this->assertEquals(4, $this->noneDB->count($this->testDbName));
    }

    /**
     * @test
     */
    public function deleteWithLikeFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->like('email', 'yahoo')
            ->delete();

        $this->assertEquals(1, $result['n']);
        $this->assertEquals(4, $this->noneDB->count($this->testDbName));
    }

    /**
     * @test
     */
    public function deleteReturnsZeroForNoMatch(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'NonExistent'])
            ->delete();

        $this->assertEquals(0, $result['n']);
        $this->assertEquals(5, $this->noneDB->count($this->testDbName));
    }

    // ==========================================
    // EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function emptyDatabaseReturnsEmptyArray(): void
    {
        $this->noneDB->createDB('emptydb');
        $results = $this->noneDB->query('emptydb')->get();

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /**
     * @test
     */
    public function chainMethodsReturnSelf(): void
    {
        $query = $this->noneDB->query($this->testDbName);

        $this->assertSame($query, $query->where(['active' => true]));
        $this->assertSame($query, $query->like('name', 'A'));
        $this->assertSame($query, $query->between('age', 20, 40));
        $this->assertSame($query, $query->sort('name'));
        $this->assertSame($query, $query->limit(10));
        $this->assertSame($query, $query->offset(5));
    }

    /**
     * @test
     */
    public function paginationExample(): void
    {
        $pageSize = 2;

        // Page 1
        $page1 = $this->noneDB->query($this->testDbName)
            ->sort('name')
            ->limit($pageSize)
            ->offset(0)
            ->get();

        // Page 2
        $page2 = $this->noneDB->query($this->testDbName)
            ->sort('name')
            ->limit($pageSize)
            ->offset(2)
            ->get();

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $this->assertNotEquals($page1[0]['name'], $page2[0]['name']);
    }
}
