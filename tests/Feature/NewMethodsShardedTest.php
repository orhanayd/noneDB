<?php

namespace noneDB\Tests\Feature;

use noneDB\Tests\noneDBTestCase;

/**
 * Tests for new chaining methods on sharded databases
 * Ensures all new methods work correctly with auto-sharding enabled
 */
class NewMethodsShardedTest extends noneDBTestCase
{
    private string $shardedDbName = 'sharded_test_db';

    protected function setUp(): void
    {
        parent::setUp();

        // Configure for small shards to test sharding behavior
        $this->setPrivateProperty('shardSize', 5);
        $this->setPrivateProperty('shardingEnabled', true);
        $this->setPrivateProperty('autoMigrate', true);

        // Insert enough data to trigger sharding (more than shardSize)
        $data = [];
        $cities = ['Istanbul', 'Ankara', 'Izmir', 'Bursa'];
        $departments = ['IT', 'HR', 'Sales', 'Marketing'];

        for ($i = 0; $i < 12; $i++) {
            $data[] = [
                'name' => 'User' . $i,
                'age' => 20 + $i,
                'city' => $cities[$i % 4],
                'department' => $departments[$i % 4],
                'score' => 70 + ($i * 2),
                'active' => ($i % 3 !== 0)
            ];
        }

        $this->noneDB->insert($this->shardedDbName, $data);
    }

    /**
     * @test
     */
    public function databaseIsSharded(): void
    {
        // Verify data was inserted correctly
        $count = $this->noneDB->count($this->shardedDbName);
        $this->assertEquals(12, $count);

        // Check if sharding is active
        $shardInfo = $this->noneDB->getShardInfo($this->shardedDbName);

        // getShardInfo returns array with shard info
        $this->assertIsArray($shardInfo);
        $this->assertArrayHasKey('shards', $shardInfo);
        $this->assertArrayHasKey('totalRecords', $shardInfo);
        $this->assertEquals(12, $shardInfo['totalRecords']);
    }

    /**
     * @test
     */
    public function whereInOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->get();

        // Istanbul and Ankara each appear 3 times (12/4)
        $this->assertCount(6, $results);
    }

    /**
     * @test
     */
    public function whereNotInOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->whereNotIn('city', ['Istanbul', 'Ankara'])
            ->get();

        // Izmir and Bursa each appear 3 times
        $this->assertCount(6, $results);
    }

    /**
     * @test
     */
    public function orWhereOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->where(['city' => 'Istanbul'])
            ->orWhere(['city' => 'Izmir'])
            ->get();

        $this->assertCount(6, $results);
    }

    /**
     * @test
     */
    public function whereNotOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->whereNot(['active' => false])
            ->get();

        // 8 active users (indices 1,2,4,5,7,8,10,11)
        $this->assertCount(8, $results);
    }

    /**
     * @test
     */
    public function notLikeOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->notLike('name', 'User1')
            ->get();

        // Excludes User1, User10, User11 = 9 remaining
        $this->assertCount(9, $results);
    }

    /**
     * @test
     */
    public function notBetweenOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->notBetween('age', 22, 28)
            ->get();

        // Ages: 20,21 (below) + 29,30,31 (above) = 5
        $this->assertCount(5, $results);
    }

    /**
     * @test
     */
    public function selectOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->select(['name', 'city'])
            ->get();

        $this->assertCount(12, $results);
        foreach ($results as $record) {
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('city', $record);
            $this->assertArrayHasKey('key', $record);
            $this->assertArrayNotHasKey('age', $record);
        }
    }

    /**
     * @test
     */
    public function exceptOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->except(['score', 'active'])
            ->get();

        $this->assertCount(12, $results);
        foreach ($results as $record) {
            $this->assertArrayNotHasKey('score', $record);
            $this->assertArrayNotHasKey('active', $record);
        }
    }

    /**
     * @test
     */
    public function groupByOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->groupBy('city')
            ->get();

        $this->assertCount(4, $results);

        foreach ($results as $group) {
            $this->assertArrayHasKey('_group', $group);
            $this->assertArrayHasKey('_items', $group);
            $this->assertArrayHasKey('_count', $group);
            $this->assertEquals(3, $group['_count']); // Each city has 3 users
        }
    }

    /**
     * @test
     */
    public function havingOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->groupBy('department')
            ->having('avg:score', '>', 80)
            ->get();

        $this->assertGreaterThanOrEqual(1, count($results));
    }

    /**
     * @test
     */
    public function searchOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->search('istanbul')
            ->get();

        $this->assertCount(3, $results);
    }

    /**
     * @test
     */
    public function joinOnShardedDb(): void
    {
        // Create a small non-sharded lookup table
        $this->noneDB->insert('city_info', [
            ['name' => 'Istanbul', 'country' => 'Turkey', 'population' => 15000000],
            ['name' => 'Ankara', 'country' => 'Turkey', 'population' => 5500000],
        ]);

        $results = $this->noneDB->query($this->shardedDbName)
            ->join('city_info', 'city', 'name')
            ->get();

        $this->assertCount(12, $results);

        // Check Istanbul and Ankara have joined data
        $istanbulRecords = array_filter($results, fn($r) => $r['city'] === 'Istanbul');
        foreach ($istanbulRecords as $record) {
            $this->assertNotNull($record['city_info']);
            $this->assertEquals('Turkey', $record['city_info']['country']);
        }
    }

    /**
     * @test
     */
    public function complexQueryOnShardedDb(): void
    {
        $results = $this->noneDB->query($this->shardedDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->whereNot(['active' => false])
            ->between('age', 21, 28)
            ->sort('score', 'desc')
            ->limit(3)
            ->select(['name', 'city', 'score'])
            ->get();

        $this->assertLessThanOrEqual(3, count($results));

        // Verify all results match criteria
        foreach ($results as $record) {
            $this->assertContains($record['city'], ['Istanbul', 'Ankara']);
        }
    }

    /**
     * @test
     */
    public function updateOnShardedDbWithNewFilters(): void
    {
        $result = $this->noneDB->query($this->shardedDbName)
            ->whereIn('city', ['Istanbul'])
            ->update(['region' => 'Marmara']);

        $this->assertEquals(3, $result['n']);

        // Verify update
        $updated = $this->noneDB->find($this->shardedDbName, ['region' => 'Marmara']);
        $this->assertCount(3, $updated);
    }

    /**
     * @test
     */
    public function deleteOnShardedDbWithNewFilters(): void
    {
        $result = $this->noneDB->query($this->shardedDbName)
            ->whereNot(['active' => true])
            ->delete();

        $this->assertEquals(4, $result['n']); // Users 0,3,6,9 are inactive

        // Verify remaining count
        $remaining = $this->noneDB->count($this->shardedDbName);
        $this->assertEquals(8, $remaining);
    }

    /**
     * @test
     */
    public function terminalMethodsOnShardedDb(): void
    {
        // first
        $first = $this->noneDB->query($this->shardedDbName)
            ->whereIn('city', ['Izmir'])
            ->sort('age')
            ->first();
        $this->assertNotNull($first);
        $this->assertEquals('Izmir', $first['city']);

        // count
        $count = $this->noneDB->query($this->shardedDbName)
            ->whereNotIn('department', ['IT'])
            ->count();
        $this->assertEquals(9, $count);

        // exists
        $exists = $this->noneDB->query($this->shardedDbName)
            ->search('User5')
            ->exists();
        $this->assertTrue($exists);

        // sum
        $sum = $this->noneDB->query($this->shardedDbName)
            ->whereIn('city', ['Istanbul'])
            ->sum('score');
        $this->assertGreaterThan(0, $sum);

        // distinct
        $cities = $this->noneDB->query($this->shardedDbName)
            ->distinct('city');
        $this->assertCount(4, $cities);
    }

    /**
     * @test
     */
    public function paginationOnShardedDb(): void
    {
        $pageSize = 4;

        $page1 = $this->noneDB->query($this->shardedDbName)
            ->sort('name')
            ->limit($pageSize)
            ->skip(0)
            ->get();

        $page2 = $this->noneDB->query($this->shardedDbName)
            ->sort('name')
            ->limit($pageSize)
            ->skip($pageSize)
            ->get();

        $page3 = $this->noneDB->query($this->shardedDbName)
            ->sort('name')
            ->limit($pageSize)
            ->skip($pageSize * 2)
            ->get();

        $this->assertCount(4, $page1);
        $this->assertCount(4, $page2);
        $this->assertCount(4, $page3);

        // Ensure no overlap
        $page1Names = array_column($page1, 'name');
        $page2Names = array_column($page2, 'name');
        $this->assertEmpty(array_intersect($page1Names, $page2Names));
    }

    protected function tearDown(): void
    {
        // Clean up sharded database files
        $dbDir = $this->getPrivateProperty('dbDir');
        $hashMethod = $this->getPrivateMethod('hashDBName');

        foreach ([$this->shardedDbName, 'city_info'] as $db) {
            $hash = $hashMethod->invoke($this->noneDB, $db);
            $files = glob($dbDir . $hash . '-' . $db . '*');
            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        parent::tearDown();
    }
}
