<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for insert() method
 *
 * Comprehensive tests for inserting single and multiple records.
 */
class InsertTest extends noneDBTestCase
{
    // ==========================================
    // SUCCESSFUL INSERT TESTS
    // ==========================================

    /**
     * @test
     */
    public function insertSingleRecordReturnsN1(): void
    {
        $result = $this->noneDB->insert($this->testDbName, ['username' => 'john']);

        $this->assertSame(['n' => 1], $result, 'docs/API.md: ["n" => 1], no error');
    }

    /**
     * @test
     */
    public function insertSingleRecordStoresData(): void
    {
        $this->noneDB->insert($this->testDbName, ['username' => 'john', 'email' => 'john@test.com']);

        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertSame([['username' => 'john', 'email' => 'john@test.com']], $contents['data']);
        $this->assertSame([['username' => 'john', 'email' => 'john@test.com', 'key' => 0]], $this->noneDB->find($this->testDbName, 0));
    }

    /**
     * @test
     */
    public function insertMultipleRecordsReturnsCorrectCount(): void
    {
        $data = [
            ['username' => 'user1'],
            ['username' => 'user2'],
            ['username' => 'user3'],
        ];

        $result = $this->noneDB->insert($this->testDbName, $data);

        $this->assertSame(['n' => 3], $result);
    }

    /**
     * @test
     */
    public function insertMultipleRecordsStoresAllData(): void
    {
        $data = [
            ['username' => 'user1'],
            ['username' => 'user2'],
        ];

        $this->noneDB->insert($this->testDbName, $data);
        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertSame($data, $contents['data']);
        $this->assertSame([0, 1], array_column($this->noneDB->find($this->testDbName, 0), 'key'));
    }

    /**
     * @test
     */
    public function insertPreservesDataTypes(): void
    {
        $data = [
            'string' => 'text',
            'integer' => 42,
            'float' => 3.14,
            'boolean' => true,
            'null' => null,
        ];

        $this->noneDB->insert($this->testDbName, $data);
        $contents = $this->getDatabaseContents($this->testDbName);

        // Types are kept exactly (assertSame): int stays int, float stays float, null is stored
        $this->assertSame([$data], $contents['data']);
        $this->assertSame([$data + ['key' => 0]], $this->noneDB->find($this->testDbName, 0));
    }

    /**
     * @test
     */
    public function insertWithNestedArrays(): void
    {
        $data = [
            'username' => 'john',
            'address' => [
                'street' => '123 Main St',
                'city' => 'New York',
            ],
        ];

        $this->noneDB->insert($this->testDbName, $data);
        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertSame([$data], $contents['data']);
    }

    /**
     * @test
     */
    public function insertWithNumericKeys(): void
    {
        $data = [
            0 => 'value1',
            1 => 'value2',
        ];

        $result = $this->noneDB->insert($this->testDbName, $data);

        // Not a list of records (the elements aren't arrays): one record with the fields "0" and "1"
        $this->assertSame(['n' => 1], $result);
        $this->assertSame([[0 => 'value1', 1 => 'value2', 'key' => 0]], $this->noneDB->find($this->testDbName, 0));
    }

    /**
     * @test
     */
    public function insertAppendsToExistingData(): void
    {
        $this->noneDB->insert($this->testDbName, ['username' => 'first']);
        $this->noneDB->insert($this->testDbName, ['username' => 'second']);

        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertSame([['username' => 'first'], ['username' => 'second']], $contents['data']);
        $this->assertSame([0, 1], array_column($this->noneDB->find($this->testDbName, 0), 'key'), 'keys in insertion order');
    }

    /**
     * @test
     */
    public function insertCreatesDBIfNotExists(): void
    {
        $this->assertDatabaseNotExists('newdb');
        $result = $this->noneDB->insert('newdb', ['data' => 'test']);

        $this->assertSame(['n' => 1], $result);
        $this->assertDatabaseExists('newdb');
        $this->assertSame([['data' => 'test', 'key' => 0]], $this->noneDB->find('newdb', 0));
    }

    /**
     * @test
     */
    public function insertToExistingDB(): void
    {
        $this->noneDB->createDB('existingdb');

        $result = $this->noneDB->insert('existingdb', ['data' => 'test']);

        $this->assertSame(['n' => 1], $result);
        $this->assertSame([['data' => 'test', 'key' => 0]], $this->noneDB->find('existingdb', 0), 'the first key of a created database is 0');
    }

    /**
     * @test
     */
    public function insertEmptyArray(): void
    {
        $result = $this->noneDB->insert($this->testDbName, []);

        // An empty insert is rejected, no empty record is stored (docs/API.md)
        $this->assertSame(['n' => 0, 'error' => 'insert data must not be empty'], $result);
        $this->assertSame([], $this->noneDB->find($this->testDbName, 0));
        $this->assertDatabaseNotExists($this->testDbName);
    }

    /**
     * @test
     */
    public function insertWithSpecialCharsInDBName(): void
    {
        // Invalid names are rejected, nothing is written
        try {
            $this->noneDB->insert('test<>db', ['data' => 'test']);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid database name', $e->getMessage());
        }
        $this->assertDatabaseNotExists('testdb');
    }

    /**
     * @test
     */
    public function insertPreservesInsertionOrder(): void
    {
        $data = [
            ['order' => 1],
            ['order' => 2],
            ['order' => 3],
        ];

        $this->noneDB->insert($this->testDbName, $data);
        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertSame($data, $contents['data']);
        $this->assertSame([[1, 0], [2, 1], [3, 2]], array_map(function ($r) { return [$r['order'], $r['key']]; }, $this->noneDB->find($this->testDbName, 0)));
    }

    /**
     * @test
     */
    public function insertLargeDataset(): void
    {
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $data[] = ['index' => $i, 'value' => 'item' . $i];
        }

        $result = $this->noneDB->insert($this->testDbName, $data);

        $this->assertSame(['n' => 100], $result);

        $contents = $this->getDatabaseContents($this->testDbName);
        $this->assertSame($data, $contents['data']);
        $found = $this->noneDB->find($this->testDbName, 0);
        $this->assertSame(range(0, 99), array_column($found, 'key'));
        $this->assertSame(range(0, 99), array_column($found, 'index'), 'record i got key i');
    }

    // ==========================================
    // ERROR CASES
    // ==========================================

    /**
     * @test
     */
    public function insertNonArrayReturnsError(): void
    {
        $result = $this->noneDB->insert($this->testDbName, 'not an array');

        $this->assertSame(['n' => 0, 'error' => 'insert data must be array'], $result);
        $this->assertDatabaseNotExists($this->testDbName);
    }

    /**
     * @test
     */
    public function insertStringReturnsError(): void
    {
        $result = $this->noneDB->insert($this->testDbName, 'string data');

        $this->assertSame(['n' => 0, 'error' => 'insert data must be array'], $result);
    }

    /**
     * @test
     */
    public function insertNullReturnsError(): void
    {
        $result = $this->noneDB->insert($this->testDbName, null);

        $this->assertSame(['n' => 0, 'error' => 'insert data must be array'], $result);
    }

    /**
     * @test
     */
    public function insertIntegerReturnsError(): void
    {
        $result = $this->noneDB->insert($this->testDbName, 123);

        $this->assertSame(['n' => 0, 'error' => 'insert data must be array'], $result);
    }

    /**
     * @test
     */
    public function insertBooleanReturnsError(): void
    {
        $result = $this->noneDB->insert($this->testDbName, true);

        $this->assertSame(['n' => 0, 'error' => 'insert data must be array'], $result);
    }

    /**
     * @test
     */
    public function insertWithReservedKeyFieldReturnsError(): void
    {
        $data = ['key' => 'value', 'username' => 'john'];

        $result = $this->noneDB->insert($this->testDbName, $data);

        $this->assertSame(['n' => 0, 'error' => 'You cannot set key name to key'], $result, 'docs/API.md');
        $this->assertDatabaseNotExists($this->testDbName);
    }

    /**
     * @test
     */
    public function insertMultipleWithOneKeyFieldReturnsError(): void
    {
        $data = [
            ['username' => 'valid'],
            ['key' => 'invalid', 'username' => 'test'],
        ];

        $result = $this->noneDB->insert($this->testDbName, $data);

        $this->assertSame(['n' => 0, 'error' => 'You cannot set key name to key'], $result);
        $this->assertSame([], $this->noneDB->find($this->testDbName, 0), 'the valid first record is not stored either');
    }

    // ==========================================
    // EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function insertWithUnicodeData(): void
    {
        $data = [
            'name' => 'Türkçe Karakter: şüğıöç',
            'emoji' => 'Test 🎉 Data',
        ];

        $this->noneDB->insert($this->testDbName, $data);
        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertSame([$data], $contents['data']);
        $this->assertSame([$data + ['key' => 0]], $this->noneDB->find($this->testDbName, 0));
    }

    /**
     * @test
     */
    public function insertWithVeryLongString(): void
    {
        $longString = str_repeat('a', 10000);
        $data = ['content' => $longString];

        $result = $this->noneDB->insert($this->testDbName, $data);

        $this->assertSame(['n' => 1], $result);

        $contents = $this->getDatabaseContents($this->testDbName);
        $this->assertSame($longString, $contents['data'][0]['content']);
    }

    /**
     * @test
     */
    public function insertWithSpecialCharsInValues(): void
    {
        $data = [
            'html' => '<script>alert("xss")</script>',
            'sql' => "'; DROP TABLE users; --",
            'json' => '{"key": "value"}',
        ];

        $this->noneDB->insert($this->testDbName, $data);
        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertSame([$data], $contents['data'], 'stored as given, not escaped or changed');
    }

    /**
     * @test
     */
    public function insertDeepNestedArray(): void
    {
        $dbName = 'deepnested';
        $data = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => 'deep value'
                    ]
                ]
            ]
        ];

        $this->noneDB->insert($dbName, $data);
        $contents = $this->getDatabaseContents($dbName);

        $this->assertSame(['data' => [$data]], $contents);
    }

    /**
     * @test
     */
    public function insertWithEmptyStringValues(): void
    {
        $data = [
            'empty' => '',
            'name' => 'test',
        ];

        $this->noneDB->insert($this->testDbName, $data);
        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertSame([$data], $contents['data']);
    }

    /**
     * @test
     */
    public function insertMixedArrayTypes(): void
    {
        // Array with mixed: some elements are arrays, some are not
        $data = [
            ['username' => 'user1'],
            'not an array item',
            ['username' => 'user2'],
        ];

        // A list (key 0 is a record) with an element that isn't one: rejected, nothing stored (docs/API.md)
        $result = $this->noneDB->insert($this->testDbName, $data);

        $this->assertSame(['n' => 0, 'error' => 'insert data must be either one record or a list of records'], $result);
        $this->assertSame([], $this->noneDB->find($this->testDbName, 0));
    }

    /**
     * @test
     */
    public function insertWithZeroValues(): void
    {
        $data = [
            'zero_int' => 0,
            'zero_float' => 0.0,
            'zero_string' => '0',
        ];

        $this->noneDB->insert($this->testDbName, $data);
        $contents = $this->getDatabaseContents($this->testDbName);

        // docs/API.md: "Floats keep their type" - 0.0 is stored as 0.0 and read back as a float
        $this->assertSame(0, $contents['data'][0]['zero_int']);
        $this->assertSame(0.0, $contents['data'][0]['zero_float']);
        $this->assertIsFloat($contents['data'][0]['zero_float']);
        $this->assertSame('0', $contents['data'][0]['zero_string']);
        $this->assertSame([$data + ['key' => 0]], $this->noneDB->find($this->testDbName, 0));
    }

    /**
     * @test
     */
    public function insertWithBooleanFalse(): void
    {
        $data = ['active' => false];

        $this->noneDB->insert($this->testDbName, $data);
        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertSame([['active' => false]], $contents['data']);
    }

    /**
     * @test
     */
    public function multipleInsertsInSequence(): void
    {
        $this->noneDB->insert($this->testDbName, ['id' => 1]);
        $this->noneDB->insert($this->testDbName, ['id' => 2]);
        $this->noneDB->insert($this->testDbName, ['id' => 3]);

        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $contents['data']);
        $this->assertSame([0, 1, 2], array_column($this->noneDB->find($this->testDbName, 0), 'key'));
    }

    /**
     * @test
     */
    public function insertObjectAsDataReturnsError(): void
    {
        // Objects should return an error - convert to array before inserting
        $object = new \stdClass();
        $object->username = 'test';
        $object->email = 'test@test.com';

        $result = $this->noneDB->insert($this->testDbName, $object);

        $this->assertSame(['n' => 0, 'error' => 'insert data must be array'], $result);
        $this->assertDatabaseNotExists($this->testDbName);
    }
}
