<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Comprehensive tests for removeFields() method
 * This method permanently removes specified fields from matching records
 *
 * Expected values come from docs/QUERY.md ("removeFields(): Removes fields from the stored matching
 * records (never affected by select(), except(), join() or withDistance())", the example
 * `['n' => 12, 'fields_removed' => ['old_password']]` for removeFields(['old_password', 'tmp']):
 * "n" counts the records that changed, "fields_removed" lists the requested fields that were really
 * removed somewhere, in the order given; sort/offset/limit choose the records; groupBy() throws
 * LogicException) and from an independent model: the setUp() records with the fields unset by hand.
 * The stored records are always re-read from disk by a fresh instance.
 */
class RemoveFieldsTest extends noneDBTestCase
{
    /** The records of setUp(), keys 0-4 */
    private const DATA = [
        ['name' => 'Alice', 'age' => 25, 'city' => 'Istanbul', 'email' => 'alice@gmail.com', 'score' => 85, 'department' => 'IT', 'active' => true, 'notes' => 'Some notes'],
        ['name' => 'Bob', 'age' => 30, 'city' => 'Ankara', 'email' => 'bob@yahoo.com', 'score' => 90, 'department' => 'HR', 'active' => true, 'notes' => 'Other notes'],
        ['name' => 'Charlie', 'age' => 35, 'city' => 'Istanbul', 'email' => 'charlie@gmail.com', 'score' => 75, 'department' => 'IT', 'active' => false, 'notes' => null],
        ['name' => 'David', 'age' => 28, 'city' => 'Izmir', 'email' => 'david@hotmail.com', 'score' => 95, 'department' => 'Sales', 'active' => true],
        ['name' => 'Eve', 'age' => 22, 'city' => 'Istanbul', 'email' => 'eve@gmail.com', 'score' => 80, 'department' => 'IT', 'active' => true, 'notes' => 'Eve notes'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->noneDB->insert($this->testDbName, self::DATA);
    }

    /**
     * The stored database as the model expects it: setUp() data with $fields removed from the records
     * whose key is in $keys (all keys when null); [key => record with "key"], fields sorted
     */
    private function model(array $fields, ?array $keys = null, array $data = self::DATA): array
    {
        $out = [];
        foreach ($data as $key => $record) {
            if ($keys === null || in_array($key, $keys, true)) {
                foreach ($fields as $field) {
                    unset($record[$field]);
                }
            }
            $record['key'] = $key;
            ksort($record);
            $out[$key] = $record;
        }
        return $out;
    }

    /**
     * The stored records as a fresh instance reads them from disk: [key => record] (fields sorted)
     */
    private function stored(?string $dbName = null, ?\noneDB $db = null): array
    {
        \noneDB::clearStaticCache();
        $db = $db ?? $this->createTestInstance();
        $out = [];
        foreach ($db->find($dbName ?? $this->testDbName, 0) as $record) {
            ksort($record);
            $out[$record['key']] = $record;
        }
        return $out;
    }

    // ==========================================
    // BASIC FUNCTIONALITY
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsBasicUsage(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        // 4 records have a 'notes' field (Charlie's is null but present); David has none
        $this->assertSame(['n' => 4, 'fields_removed' => ['notes']], $result);
        $this->assertSame($this->model(['notes']), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsMultipleFields(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes', 'score']);

        // Every record has 'score', so all 5 change
        $this->assertSame(['n' => 5, 'fields_removed' => ['notes', 'score']], $result);
        $this->assertSame($this->model(['notes', 'score']), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsListsRemovedFieldsInTheOrderGiven(): void
    {
        $result = $this->noneDB->query($this->testDbName)->removeFields(['score', 'missing', 'notes']);

        $this->assertSame(['n' => 5, 'fields_removed' => ['score', 'notes']], $result);
        $this->assertSame($this->model(['score', 'notes']), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsPreservesOtherFields(): void
    {
        $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        // Every other field keeps its value and type
        $this->assertSame($this->model(['notes']), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsReturnsCorrectCount(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Istanbul'])
            ->removeFields(['notes']);

        // Istanbul has 3 records: Alice, Charlie, Eve. All three have the field (Charlie's is null)
        $this->assertSame(['n' => 3, 'fields_removed' => ['notes']], $result);
        $this->assertSame($this->model(['notes'], [0, 2, 4]), $this->stored());
    }

    // ==========================================
    // EDGE CASES - EMPTY AND INVALID INPUT
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsWithEmptyArray(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields([]);

        $this->assertSame(0, $result['n']);
        $this->assertSame([], $result['fields_removed']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('No fields specified', $result['error']);
        $this->assertSame($this->model([]), $this->stored()); // nothing changed
    }

    /**
     * @test
     */
    public function removeFieldsWithNonExistentField(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['nonexistent_field']);

        // No records have this field, so no updates and nothing removed
        $this->assertSame(['n' => 0, 'fields_removed' => []], $result);
        $this->assertSame($this->model([]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithMixedExistentNonExistent(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes', 'nonexistent']);

        // Only 'notes' should be in fields_removed
        $this->assertSame(['n' => 4, 'fields_removed' => ['notes']], $result);
        $this->assertSame($this->model(['notes']), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsCannotRemoveKeyField(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['key']);

        $this->assertSame(0, $result['n']);
        $this->assertSame([], $result['fields_removed']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('key', $result['error']);

        // Every record, with its key, is unchanged
        $this->assertSame($this->model([]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsKeyWithOtherFields(): void
    {
        // Try to remove key along with valid fields
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['key', 'notes']);

        // key is ignored, notes is removed
        $this->assertSame(['n' => 4, 'fields_removed' => ['notes']], $result);

        // Keys unchanged, notes removed
        $this->assertSame($this->model(['notes']), $this->stored());
    }

    // ==========================================
    // WITH FILTERS
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsWithWhereFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->removeFields(['notes']);

        // IT: Alice, Charlie, Eve; Bob (HR) keeps his notes
        $this->assertSame(['n' => 3, 'fields_removed' => ['notes']], $result);
        $this->assertSame($this->model(['notes'], [0, 2, 4]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithWhereInFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->removeFields(['score']);

        // Istanbul (3) + Ankara (1) = 4 records affected; Izmir keeps its score
        $this->assertSame(['n' => 4, 'fields_removed' => ['score']], $result);
        $this->assertSame($this->model(['score'], [0, 1, 2, 4]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithBetweenFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->between('age', 25, 30)
            ->removeFields(['email']);

        // Alice(25), Bob(30), David(28) = 3 records (inclusive); Charlie(35) and Eve(22) keep email
        $this->assertSame(['n' => 3, 'fields_removed' => ['email']], $result);
        $this->assertSame($this->model(['email'], [0, 1, 3]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithOrWhereFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'Izmir'])
            ->orWhere(['city' => 'Ankara'])
            ->removeFields(['department']);

        // Izmir (1) + Ankara (1) = 2 records; Istanbul records keep their department
        $this->assertSame(['n' => 2, 'fields_removed' => ['department']], $result);
        $this->assertSame($this->model(['department'], [1, 3]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithSearchFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->search('gmail')
            ->removeFields(['active']);

        // Alice, Charlie, Eve have gmail = 3 records
        $this->assertSame(['n' => 3, 'fields_removed' => ['active']], $result);
        $this->assertSame($this->model(['active'], [0, 2, 4]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithNoMatchingRecords(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->where(['city' => 'NonExistent'])
            ->removeFields(['notes']);

        // No record matched: nothing changed and nothing was removed ("fields_removed" lists removed
        // fields, QUERY.md example), the same answer as when matching records lack the field
        $this->assertSame(['n' => 0, 'fields_removed' => []], $result);
        $this->assertSame($this->model([]), $this->stored());
    }

    // ==========================================
    // SORT / OFFSET / LIMIT CHOOSE THE RECORDS
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsWithSortAndLimitChoosesTheRecords(): void
    {
        // QUERY.md: sort(), offset() and limit() choose which records are written. Two oldest: Charlie, Bob
        $result = $this->noneDB->query($this->testDbName)->sort('age', 'desc')->limit(2)->removeFields(['notes']);

        $this->assertSame(['n' => 2, 'fields_removed' => ['notes']], $result);
        $this->assertSame($this->model(['notes'], [1, 2]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithSortOffsetAndLimitChoosesTheRecords(): void
    {
        // Ages ascending: Eve 22, Alice 25, David 28, Bob 30, Charlie 35 -> offset 1, limit 2: Alice, David.
        // David has no notes: one record changes
        $result = $this->noneDB->query($this->testDbName)->sort('age')->offset(1)->limit(2)->removeFields(['notes']);

        $this->assertSame(['n' => 1, 'fields_removed' => ['notes']], $result);
        $this->assertSame($this->model(['notes'], [0, 3]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithLimitWithoutSortUsesKeyOrder(): void
    {
        $result = $this->noneDB->query($this->testDbName)->limit(2)->removeFields(['email']);

        $this->assertSame(['n' => 2, 'fields_removed' => ['email']], $result);
        $this->assertSame($this->model(['email'], [0, 1]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithOffsetPastTheEndRemovesNothing(): void
    {
        $result = $this->noneDB->query($this->testDbName)->offset(5)->removeFields(['notes']);

        $this->assertSame(0, $result['n']);
        $this->assertSame($this->model([]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithGroupByThrowsLogicException(): void
    {
        // API.md: update(), delete() and removeFields() throw LogicException together with groupBy()
        try {
            $this->noneDB->query($this->testDbName)->groupBy('city')->removeFields(['notes']);
            $this->fail('removeFields() with groupBy() must throw');
        } catch (\LogicException $e) {
            $this->assertSame(\LogicException::class, get_class($e));
            $this->assertStringContainsString('cannot be combined with groupBy()', $e->getMessage());
        }
        $this->assertSame($this->model([]), $this->stored()); // nothing written
    }

    // ==========================================
    // PROJECTION, JOINS AND DISTANCES NEVER REACH STORAGE
    // ==========================================

    /**
     * @test
     */
    public function selectAndExceptDoNotChangeWhatIsStored(): void
    {
        // select() keeps only "name" in the result: the other fields must not be dropped from storage
        $result = $this->noneDB->query($this->testDbName)->select(['name'])->where(['name' => 'Alice'])->removeFields(['notes']);
        $this->assertSame(['n' => 1, 'fields_removed' => ['notes']], $result);

        // except() drops "email" from the result only
        $result = $this->noneDB->query($this->testDbName)->except(['email'])->where(['name' => 'Bob'])->removeFields(['notes']);
        $this->assertSame(['n' => 1, 'fields_removed' => ['notes']], $result);

        $this->assertSame($this->model(['notes'], [0, 1]), $this->stored());
    }

    /**
     * @test
     */
    public function joinedRecordsAreNeverStored(): void
    {
        $this->noneDB->insert('departments', [
            ['department' => 'IT', 'floor' => 3],
            ['department' => 'HR', 'floor' => 1],
        ]);

        $result = $this->noneDB->query($this->testDbName)
            ->join('departments', 'department', 'department')
            ->join('departments', 'department', 'department', 'dept')
            ->where(['department' => 'IT'])
            ->removeFields(['notes']);

        $this->assertSame(['n' => 3, 'fields_removed' => ['notes']], $result);
        // Neither "departments" nor "dept" reached the stored records
        $this->assertSame($this->model(['notes'], [0, 2, 4]), $this->stored());
        // The foreign database is unchanged too
        $this->assertSame([
            0 => ['department' => 'IT', 'floor' => 3, 'key' => 0],
            1 => ['department' => 'HR', 'floor' => 1, 'key' => 1],
        ], $this->stored('departments'));
    }

    /**
     * @test
     */
    public function distancesAreNeverStored(): void
    {
        $places = [
            ['name' => 'A', 'tag' => 'x', 'loc' => ['type' => 'Point', 'coordinates' => [28.98, 41.01]]],
            ['name' => 'B', 'tag' => 'y', 'loc' => ['type' => 'Point', 'coordinates' => [29.50, 41.50]]],
        ];
        $this->noneDB->insert('places', $places);

        // withDistance() adds _distance to the result; withinDistance() adds one to its candidates
        $result = $this->noneDB->query('places')->withDistance('loc', 28.97, 41.0)->removeFields(['tag']);
        $this->assertSame(['n' => 2, 'fields_removed' => ['tag']], $result);

        $this->noneDB->update('places', [[], ['set' => ['tag' => 'z']]]);
        $result = $this->noneDB->query('places')->withinDistance('loc', 28.97, 41.0, 5000)->removeFields(['tag']);
        $this->assertSame(['n' => 1, 'fields_removed' => ['tag']], $result); // only A is within 5 km

        $stored = $this->stored('places');
        $this->assertSame(['key' => 0, 'loc' => $places[0]['loc'], 'name' => 'A'], $stored[0]);
        $this->assertSame(['key' => 1, 'loc' => $places[1]['loc'], 'name' => 'B', 'tag' => 'z'], $stored[1]);
    }

    // ==========================================
    // SPECIAL FIELD VALUES
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsWithNullValue(): void
    {
        // Charlie has notes = null
        $result = $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Charlie'])
            ->removeFields(['notes']);

        // The field is removed even though its value is null
        $this->assertSame(['n' => 1, 'fields_removed' => ['notes']], $result);
        $this->assertSame($this->model(['notes'], [2]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithBooleanValue(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['active']);

        $this->assertSame(['n' => 5, 'fields_removed' => ['active']], $result);
        $this->assertSame($this->model(['active']), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithNumericValue(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['age']);

        $this->assertSame(['n' => 5, 'fields_removed' => ['age']], $result);
        $this->assertSame($this->model(['age']), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsWithNestedArrayValue(): void
    {
        $this->noneDB->update($this->testDbName, [['name' => 'Eve'], ['set' => ['address' => ['city' => 'Istanbul', 'zip' => '34000']]]]);

        $result = $this->noneDB->query($this->testDbName)->removeFields(['address']);

        $this->assertSame(['n' => 1, 'fields_removed' => ['address']], $result);
        $this->assertSame($this->model([]), $this->stored());
    }

    // ==========================================
    // PARTIAL FIELD EXISTENCE
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsWhenOnlySomeRecordsHaveField(): void
    {
        // David doesn't have 'notes' field
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        // Only 4 records have notes (Alice, Bob, Charlie, Eve)
        $this->assertSame(4, $result['n']);

        // David is unchanged, field by field
        $this->assertSame($this->model([])[3], $this->stored()[3]);
    }

    // ==========================================
    // MULTIPLE OPERATIONS
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsMultipleTimes(): void
    {
        $result1 = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);
        $result2 = $this->noneDB->query($this->testDbName)
            ->removeFields(['score']);
        // Third removal: the records match, but none has notes any more
        $result3 = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        $this->assertSame(['n' => 4, 'fields_removed' => ['notes']], $result1);
        $this->assertSame(['n' => 5, 'fields_removed' => ['score']], $result2);
        $this->assertSame(['n' => 0, 'fields_removed' => []], $result3);

        $this->assertSame($this->model(['notes', 'score']), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsThenUpdate(): void
    {
        $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Alice'])
            ->removeFields(['notes']);

        $this->assertSame(['n' => 1], $this->noneDB->update($this->testDbName, [
            ['name' => 'Alice'],
            ['set' => ['status' => 'updated']]
        ]));

        $expected = $this->model(['notes'], [0]);
        $expected[0]['status'] = 'updated';
        ksort($expected[0]);
        $this->assertSame($expected, $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsThenDelete(): void
    {
        $this->assertSame(5, $this->noneDB->count($this->testDbName));

        $this->noneDB->query($this->testDbName)
            ->where(['department' => 'IT'])
            ->removeFields(['notes']);

        $this->assertSame(['n' => 1], $this->noneDB->query($this->testDbName)
            ->where(['department' => 'HR'])
            ->delete());

        $this->assertSame(4, $this->noneDB->count($this->testDbName));
        $expected = $this->model(['notes'], [0, 2, 4]);
        unset($expected[1]); // Bob (HR) deleted
        $this->assertSame($expected, $this->stored());
    }

    // ==========================================
    // DATA INTEGRITY
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsPreservesRecordCount(): void
    {
        $this->noneDB->query($this->testDbName)
            ->removeFields(['notes', 'score']);

        $this->assertSame(5, $this->noneDB->count($this->testDbName));
        $this->assertSame(5, $this->createTestInstance()->count($this->testDbName));
    }

    /**
     * @test
     */
    public function removeFieldsPreservesKeyValues(): void
    {
        $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        $this->assertSame([0, 1, 2, 3, 4], array_column($this->noneDB->find($this->testDbName, 0), 'key'));
        $this->assertSame([0, 1, 2, 3, 4], array_keys($this->stored()));
        // A new record still gets the next key (keys are never reused or changed, API.md)
        $this->noneDB->insert($this->testDbName, ['name' => 'Frank']);
        $this->assertSame(5, $this->noneDB->first($this->testDbName, ['name' => 'Frank'])['key']);
    }

    /**
     * @test
     */
    public function removeFieldsPreservesFieldValues(): void
    {
        $this->noneDB->query($this->testDbName)
            ->where(['name' => 'Alice'])
            ->removeFields(['notes']);

        $aliceAfter = $this->noneDB->first($this->testDbName, ['name' => 'Alice']);
        ksort($aliceAfter);

        $this->assertSame($this->model(['notes'], [0])[0], $aliceAfter);
    }

    /**
     * @test
     */
    public function removeFieldsKeepsFieldIndexConsistent(): void
    {
        $this->noneDB->createFieldIndex($this->testDbName, 'department');

        $result = $this->noneDB->query($this->testDbName)->where(['department' => 'IT'])->limit(2)->removeFields(['department']);
        $this->assertSame(['n' => 2, 'fields_removed' => ['department']], $result); // Alice, Charlie

        // API.md: indexes always give exactly the results of a full scan
        $this->assertSame([4], array_column($this->noneDB->find($this->testDbName, ['department' => 'IT']), 'key'));
        $this->assertSame([4], array_column($this->noneDB->query($this->testDbName)->where(['department' => 'IT'])->get(), 'key'));
        $this->assertSame([0, 2], array_column($this->noneDB->query($this->testDbName)->where(['department' => ['$exists' => false]])->get(), 'key'));
        $this->assertSame($this->model(['department'], [0, 2]), $this->stored());
    }

    // ==========================================
    // SHARDED DATABASE
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsOnShardedDatabase(): void
    {
        $config = ['secretKey' => 'test_secret_key_for_unit_tests', 'dbDir' => $this->testDbDir, 'autoCreateDB' => true, 'shardSize' => 2];
        $db = new \noneDB($config);
        $data = [];
        for ($i = 0; $i < 9; $i++) {
            $data[] = ['n' => $i, 'even' => $i % 2 === 0, 'tmp' => 't' . $i];
        }
        $db->insert('shardedrf', $data);
        $this->assertTrue($db->getShardInfo('shardedrf')['sharded']);
        $this->assertSame(5, $db->getShardInfo('shardedrf')['shards']);

        // Even records across every shard; sort desc + limit 3 picks n = 8, 6, 4
        $result = $db->query('shardedrf')->where(['even' => true])->sort('n', 'desc')->limit(3)->removeFields(['tmp']);
        $this->assertSame(['n' => 3, 'fields_removed' => ['tmp']], $result);

        $expected = [];
        foreach ($data as $key => $record) {
            if (in_array($key, [4, 6, 8], true)) {
                unset($record['tmp']);
            }
            $record['key'] = $key;
            ksort($record);
            $expected[$key] = $record;
        }
        $this->assertSame($expected, $this->stored('shardedrf', new \noneDB($config)));
    }

    // ==========================================
    // COMPLEX SCENARIOS
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsComplexFilter(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->whereIn('city', ['Istanbul', 'Ankara'])
            ->between('age', 20, 30)
            ->where(['active' => true])
            ->removeFields(['notes', 'score']);

        // Istanbul: Alice(25,active), Eve(22,active); Ankara: Bob(30,active) = 3 records
        $this->assertSame(['n' => 3, 'fields_removed' => ['notes', 'score']], $result);
        $this->assertSame($this->model(['notes', 'score'], [0, 1, 4]), $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsAllFieldsExceptRequired(): void
    {
        $fields = ['age', 'city', 'email', 'score', 'department', 'active', 'notes'];
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields($fields);

        $this->assertSame(['n' => 5, 'fields_removed' => $fields], $result);

        // All records now only have 'name' and 'key'
        $expected = [];
        foreach (self::DATA as $key => $record) {
            $expected[$key] = ['key' => $key, 'name' => $record['name']];
        }
        $this->assertSame($expected, $this->stored());
    }

    /**
     * @test
     */
    public function removeFieldsReturnValueStructure(): void
    {
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        // Exactly "n" (int) and "fields_removed" (list); no "error" on success
        $this->assertSame(['n', 'fields_removed'], array_keys($result));
        $this->assertSame(4, $result['n']);
        $this->assertSame(['notes'], $result['fields_removed']);
    }

    // ==========================================
    // CHAINABLE METHOD CHECK
    // ==========================================

    /**
     * @test
     */
    public function removeFieldsIsTerminalMethod(): void
    {
        // removeFields returns the result array, not the builder
        $result = $this->noneDB->query($this->testDbName)
            ->removeFields(['notes']);

        $this->assertIsArray($result);
        $this->assertSame(4, $result['n']);
    }
}
