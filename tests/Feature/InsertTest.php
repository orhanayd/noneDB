<?php

namespace noneDB\Tests\Feature;

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

        $this->assertArrayHasKey('n', $result);
        $this->assertEquals(1, $result['n']);
    }

    /**
     * @test
     */
    public function insertSingleRecordStoresData(): void
    {
        $this->noneDB->insert($this->testDbName, ['username' => 'john', 'email' => 'john@test.com']);

        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertCount(1, $contents['data']);
        $this->assertEquals('john', $contents['data'][0]['username']);
        $this->assertEquals('john@test.com', $contents['data'][0]['email']);
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

        $this->assertEquals(3, $result['n']);
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

        $this->assertCount(2, $contents['data']);
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

        $this->assertEquals('text', $contents['data'][0]['string']);
        $this->assertEquals(42, $contents['data'][0]['integer']);
        $this->assertEquals(3.14, $contents['data'][0]['float']);
        $this->assertTrue($contents['data'][0]['boolean']);
        $this->assertNull($contents['data'][0]['null']);
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

        $this->assertArrayHasKey('address', $contents['data'][0]);
        $this->assertEquals('123 Main St', $contents['data'][0]['address']['street']);
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

        $this->assertEquals(1, $result['n']);
    }

    /**
     * @test
     */
    public function insertAppendsToExistingData(): void
    {
        $this->noneDB->insert($this->testDbName, ['username' => 'first']);
        $this->noneDB->insert($this->testDbName, ['username' => 'second']);

        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertCount(2, $contents['data']);
        $this->assertEquals('first', $contents['data'][0]['username']);
        $this->assertEquals('second', $contents['data'][1]['username']);
    }

    /**
     * @test
     */
    public function insertCreatesDBIfNotExists(): void
    {
        $result = $this->noneDB->insert('newdb', ['data' => 'test']);

        $this->assertEquals(1, $result['n']);
        $this->assertDatabaseExists('newdb');
    }

    /**
     * @test
     */
    public function insertToExistingDB(): void
    {
        $this->noneDB->createDB('existingdb');

        $result = $this->noneDB->insert('existingdb', ['data' => 'test']);

        $this->assertEquals(1, $result['n']);
    }

    /**
     * @test
     */
    public function insertEmptyArray(): void
    {
        $result = $this->noneDB->insert($this->testDbName, []);

        // Empty array is not multidimensional, so treated as single empty record
        $this->assertEquals(1, $result['n']);
    }

    /**
     * @test
     */
    public function insertWithSpecialCharsInDBName(): void
    {
        $result = $this->noneDB->insert('test<>db', ['data' => 'test']);

        $this->assertEquals(1, $result['n']);
        $this->assertDatabaseExists('testdb');
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

        $this->assertEquals(1, $contents['data'][0]['order']);
        $this->assertEquals(2, $contents['data'][1]['order']);
        $this->assertEquals(3, $contents['data'][2]['order']);
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

        $this->assertEquals(100, $result['n']);

        $contents = $this->getDatabaseContents($this->testDbName);
        $this->assertCount(100, $contents['data']);
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

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(0, $result['n']);
    }

    /**
     * @test
     */
    public function insertStringReturnsError(): void
    {
        $result = $this->noneDB->insert($this->testDbName, 'string data');

        $this->assertArrayHasKey('error', $result);
    }

    /**
     * @test
     */
    public function insertNullReturnsError(): void
    {
        $result = $this->noneDB->insert($this->testDbName, null);

        $this->assertArrayHasKey('error', $result);
    }

    /**
     * @test
     */
    public function insertIntegerReturnsError(): void
    {
        $result = $this->noneDB->insert($this->testDbName, 123);

        $this->assertArrayHasKey('error', $result);
    }

    /**
     * @test
     */
    public function insertBooleanReturnsError(): void
    {
        $result = $this->noneDB->insert($this->testDbName, true);

        $this->assertArrayHasKey('error', $result);
    }

    /**
     * @test
     */
    public function insertWithReservedKeyFieldReturnsError(): void
    {
        $data = ['key' => 'value', 'username' => 'john'];

        $result = $this->noneDB->insert($this->testDbName, $data);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('key', strtolower($result['error']));
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

        $this->assertArrayHasKey('error', $result);
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

        $this->assertEquals('Türkçe Karakter: şüğıöç', $contents['data'][0]['name']);
    }

    /**
     * @test
     */
    public function insertWithVeryLongString(): void
    {
        $longString = str_repeat('a', 10000);
        $data = ['content' => $longString];

        $result = $this->noneDB->insert($this->testDbName, $data);

        $this->assertEquals(1, $result['n']);

        $contents = $this->getDatabaseContents($this->testDbName);
        $this->assertEquals(10000, strlen($contents['data'][0]['content']));
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

        $this->assertEquals('<script>alert("xss")</script>', $contents['data'][0]['html']);
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

        $this->assertNotNull($contents);
        $this->assertArrayHasKey('data', $contents);
        $this->assertCount(1, $contents['data']);
        $this->assertEquals('deep value', $contents['data'][0]['level1']['level2']['level3']['level4']);
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

        $this->assertEquals('', $contents['data'][0]['empty']);
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

        // When foreach encounters non-array, directInsert becomes true
        $result = $this->noneDB->insert($this->testDbName, $data);

        // Should handle gracefully
        $this->assertArrayHasKey('n', $result);
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

        // JSON decode may convert 0.0 to 0 (int)
        $this->assertEquals(0, $contents['data'][0]['zero_int']);
        $this->assertEquals(0, $contents['data'][0]['zero_float']); // JSON doesn't preserve float type for 0.0
        $this->assertSame('0', $contents['data'][0]['zero_string']);
    }

    /**
     * @test
     */
    public function insertWithBooleanFalse(): void
    {
        $data = ['active' => false];

        $this->noneDB->insert($this->testDbName, $data);
        $contents = $this->getDatabaseContents($this->testDbName);

        $this->assertFalse($contents['data'][0]['active']);
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

        $this->assertCount(3, $contents['data']);
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

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(0, $result['n']);
    }
}
