<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Integration tests for edge cases and security
 *
 * Tests unusual inputs, potential security issues, and boundary conditions.
 */
class EdgeCasesTest extends noneDBTestCase
{
    // ==========================================
    // SECURITY TESTS
    // ==========================================

    /**
     * @test
     */
    public function sqlInjectionAttemptInDBName(): void
    {
        // Apostrophes, spaces and hyphens are allowed; ';' is not, so the name is rejected
        $this->expectException(\InvalidArgumentException::class);
        $this->noneDB->createDB("test'; DROP TABLE users; --");
    }

    /**
     * @test
     */
    public function sqlInjectionAttemptInData(): void
    {
        $dbName = 'sql-injection-test';

        $maliciousData = [
            'name' => "'; DROP TABLE users; --",
            'query' => "SELECT * FROM users WHERE 1=1",
        ];

        $this->noneDB->insert($dbName, $maliciousData);
        $result = $this->noneDB->find($dbName, 0);

        // Data should be stored as-is (JSON, not SQL)
        $this->assertEquals("'; DROP TABLE users; --", $result[0]['name']);
    }

    /**
     * @test
     */
    public function xssAttemptInData(): void
    {
        $dbName = 'xss-test';

        $xssData = [
            'content' => '<script>alert("XSS")</script>',
            'html' => '<img src="x" onerror="alert(1)">',
        ];

        $this->noneDB->insert($dbName, $xssData);
        $result = $this->noneDB->find($dbName, 0);

        // Data stored as-is (output encoding is app responsibility)
        $this->assertEquals('<script>alert("XSS")</script>', $result[0]['content']);
    }

    /**
     * @test
     */
    public function pathTraversalAttemptInDBName(): void
    {
        try {
            $this->noneDB->createDB('../../../etc/passwd');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid database name', $e->getMessage());
        }

        // Nothing created outside (or inside) the db directory
        $this->assertFileDoesNotExist('/etc/passwd.json');
        $this->assertSame([], glob($this->testDbDir . '*passwd*'));
    }

    /**
     * @test
     */
    public function nullByteInjectionAttempt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->noneDB->createDB("test\x00malicious");
    }

    // ==========================================
    // UNICODE AND SPECIAL CHARACTERS
    // ==========================================

    /**
     * @test
     */
    public function unicodeInDatabaseName(): void
    {
        // v3.2.0: non-ASCII names are rejected instead of being stripped (stripping let names collide)
        try {
            $this->noneDB->createDB('veritabanı_测试_データ');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid database name', $e->getMessage());
        }

        // 'çalışan' used to be stripped to 'alan' and write into that database; now it is rejected
        $this->noneDB->insert('alan', ['tip' => 'alan']);
        try {
            $this->noneDB->insert('çalışan', ['tip' => 'çalışan']);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid database name', $e->getMessage());
        }
        $this->assertSame(['alan'], array_column($this->noneDB->find('alan', 0), 'tip'), "nothing leaked into 'alan'");
    }

    /**
     * @test
     */
    public function unicodeInData(): void
    {
        $dbName = 'unicode-data-test';

        $unicodeData = [
            'turkish' => 'Türkçe karakterler: ğüşıöç',
            'chinese' => '中文字符',
            'japanese' => '日本語文字',
            'arabic' => 'النص العربي',
            'emoji' => '😀🎉🚀💻',
            'mixed' => 'Hello 世界 مرحبا 🌍',
        ];

        $this->noneDB->insert($dbName, $unicodeData);
        $result = $this->noneDB->find($dbName, 0);

        $this->assertEquals('Türkçe karakterler: ğüşıöç', $result[0]['turkish']);
        $this->assertEquals('中文字符', $result[0]['chinese']);
        $this->assertEquals('😀🎉🚀💻', $result[0]['emoji']);
    }

    /**
     * @test
     */
    public function specialCharactersInFieldNames(): void
    {
        $dbName = 'special-fields-test';

        $data = [
            'field-with-dash' => 'value1',
            'field.with.dot' => 'value2',
            'field_with_underscore' => 'value3',
            'field with space' => 'value4',
            'field@special#chars!' => 'value5',
        ];

        $this->noneDB->insert($dbName, $data);
        $result = $this->noneDB->find($dbName, 0);

        $this->assertEquals('value1', $result[0]['field-with-dash']);
        $this->assertEquals('value4', $result[0]['field with space']);
    }

    // ==========================================
    // BOUNDARY CONDITIONS
    // ==========================================

    /**
     * @test
     */
    public function reasonablyLongDatabaseNameWorks(): void
    {
        // Test with a reasonably long name that won't exceed file system limits
        // 50 chars + hash (20) + extension (~15) = ~85 chars, well under 255 limit
        $longName = str_repeat('a', 50);

        $result = $this->noneDB->createDB($longName);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function veryLongFieldName(): void
    {
        $dbName = 'long-field-test';
        $longFieldName = str_repeat('field', 100);

        $data = [$longFieldName => 'value'];

        $this->noneDB->insert($dbName, $data);
        $result = $this->noneDB->find($dbName, 0);

        $this->assertEquals('value', $result[0][$longFieldName]);
    }

    /**
     * @test
     */
    public function veryLongStringValue(): void
    {
        $dbName = 'long-value-test';
        $longValue = str_repeat('x', 1000000); // 1MB string

        $data = ['content' => $longValue];

        $this->noneDB->insert($dbName, $data);
        $result = $this->noneDB->find($dbName, 0);

        $this->assertEquals(1000000, strlen($result[0]['content']));
    }

    /**
     * @test
     */
    public function deeplyNestedData(): void
    {
        $dbName = 'nested-test';

        // Create 10-level deep nested array
        $nested = ['level' => 10, 'data' => 'deepest'];
        for ($i = 9; $i >= 1; $i--) {
            $nested = ['level' => $i, 'child' => $nested];
        }

        $this->noneDB->insert($dbName, $nested);
        $result = $this->noneDB->find($dbName, 0);

        $this->assertEquals(1, $result[0]['level']);
        $this->assertEquals(2, $result[0]['child']['level']);
    }

    /**
     * @test
     */
    public function emptyStringDatabaseName(): void
    {
        // v3.2.0: an empty name is invalid; checkDB() reports false, other operations throw
        $this->assertFalse($this->noneDB->checkDB(''));
        $this->expectException(\InvalidArgumentException::class);
        $this->noneDB->createDB('');
    }

    /**
     * @test
     */
    public function numericDatabaseName(): void
    {
        $this->noneDB->createDB('12345');
        $this->noneDB->insert('12345', ['data' => 'test']);

        $result = $this->noneDB->find('12345', 0);

        $this->assertCount(1, $result);
    }

    // ==========================================
    // DATA TYPE EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function booleanValues(): void
    {
        $dbName = 'boolean-test';

        $data = [
            ['flag' => true],
            ['flag' => false],
        ];

        $this->noneDB->insert($dbName, $data);

        $trueResult = $this->noneDB->find($dbName, ['flag' => true]);
        $falseResult = $this->noneDB->find($dbName, ['flag' => false]);

        $this->assertCount(1, $trueResult);
        $this->assertCount(1, $falseResult);
    }

    /**
     * @test
     */
    public function numericStringVsInteger(): void
    {
        $dbName = 'numeric-test';

        $data = [
            ['value' => 123],
            ['value' => '123'],
        ];

        $this->noneDB->insert($dbName, $data);

        // Integer search
        $intResult = $this->noneDB->find($dbName, ['value' => 123]);

        // String search
        $strResult = $this->noneDB->find($dbName, ['value' => '123']);

        // Results depend on type comparison behavior
        $this->assertIsArray($intResult);
        $this->assertIsArray($strResult);
    }

    /**
     * @test
     */
    public function floatValues(): void
    {
        $dbName = 'float-test';

        $data = [
            'price' => 19.99,
            'quantity' => 3.14159,
            'negative' => -123.456,
        ];

        $this->noneDB->insert($dbName, $data);
        $result = $this->noneDB->find($dbName, 0);

        $this->assertEquals(19.99, $result[0]['price']);
        $this->assertEquals(3.14159, $result[0]['quantity']);
    }

    /**
     * @test
     */
    public function nullValues(): void
    {
        $dbName = 'null-test';

        $data = [
            'name' => 'test',
            'description' => null,
            'value' => null,
        ];

        $this->noneDB->insert($dbName, $data);
        $result = $this->noneDB->find($dbName, 0);

        $this->assertNull($result[0]['description']);
    }

    /**
     * @test
     */
    public function emptyArrayValue(): void
    {
        $dbName = 'empty-array-test';

        $data = [
            'items' => [],
            'tags' => [],
        ];

        $this->noneDB->insert($dbName, $data);
        $result = $this->noneDB->find($dbName, 0);

        $this->assertIsArray($result[0]['items']);
        $this->assertCount(0, $result[0]['items']);
    }

    /**
     * @test
     */
    public function mixedArrayValue(): void
    {
        $dbName = 'mixedarraytest';

        $data = [
            'mixed' => [1, 'two', 3.0, true, null, ['nested' => 'value']],
        ];

        $this->noneDB->insert($dbName, $data);
        $result = $this->noneDB->find($dbName, 0);

        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('mixed', $result[0]);
        $this->assertEquals(1, $result[0]['mixed'][0]);
        $this->assertEquals('two', $result[0]['mixed'][1]);
        $this->assertTrue($result[0]['mixed'][3]);
        $this->assertNull($result[0]['mixed'][4]);
    }

    // ==========================================
    // JSON EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function jsonSpecialCharacters(): void
    {
        $dbName = 'json-special-test';

        $data = [
            'quotes' => 'He said "Hello"',
            'backslash' => 'path\\to\\file',
            'newline' => "line1\nline2",
            'tab' => "col1\tcol2",
            'unicode_escape' => '\u0048\u0065\u006c\u006c\u006f',
        ];

        $this->noneDB->insert($dbName, $data);
        $result = $this->noneDB->find($dbName, 0);

        $this->assertEquals('He said "Hello"', $result[0]['quotes']);
        $this->assertEquals("line1\nline2", $result[0]['newline']);
    }

    /**
     * @test
     */
    public function jsonControlCharacters(): void
    {
        $dbName = 'control-chars-test';

        $data = [
            'content' => "text\r\nwith\tcontrol\x00chars",
        ];

        $this->noneDB->insert($dbName, $data);
        $result = $this->noneDB->find($dbName, 0);

        $this->assertIsString($result[0]['content']);
    }

    // ==========================================
    // CONCURRENT-LIKE SCENARIOS
    // ==========================================

    /**
     * @test
     */
    public function rapidCreateDeleteCycle(): void
    {
        $dbName = 'rapid-cycle-test';

        for ($i = 0; $i < 10; $i++) {
            $this->noneDB->insert($dbName, ['iteration' => $i]);
            $this->noneDB->delete($dbName, ['iteration' => $i]);
        }

        $result = $this->noneDB->find($dbName, 0);

        // All records deleted (but nulled in place)
        $nonNullCount = count(array_filter($result, fn($r) => $r !== null));
        $this->assertEquals(0, $nonNullCount);
    }

    /**
     * @test
     */
    public function findAfterMultipleDeletes(): void
    {
        $dbName = 'find-after-delete-test';

        // Insert multiple records
        for ($i = 0; $i < 5; $i++) {
            $this->noneDB->insert($dbName, ['index' => $i, 'type' => 'test']);
        }

        // Delete some
        $this->noneDB->delete($dbName, ['index' => 1]);
        $this->noneDB->delete($dbName, ['index' => 3]);

        // Find remaining
        $remaining = $this->noneDB->find($dbName, ['type' => 'test']);

        $this->assertCount(3, $remaining);
    }

    /**
     * @test
     */
    public function updateAfterPartialDelete(): void
    {
        $dbName = 'update-after-delete-test';

        $this->noneDB->insert($dbName, [
            ['id' => 1, 'status' => 'active'],
            ['id' => 2, 'status' => 'active'],
            ['id' => 3, 'status' => 'active'],
        ]);

        // Delete middle record
        $this->noneDB->delete($dbName, ['id' => 2]);

        // Update remaining
        $result = $this->noneDB->update($dbName, [
            ['status' => 'active'],
            ['set' => ['status' => 'inactive']]
        ]);

        $this->assertEquals(2, $result['n']);
    }

    // ==========================================
    // ERROR RECOVERY
    // ==========================================

    /**
     * @test
     * v3.0: JSONL format gracefully handles corrupted data (returns empty array)
     */
    public function operationsOnCorruptedDataReturnsEmptyArray(): void
    {
        $dbName = 'corrupttest';

        // Create valid DB first
        $this->noneDB->createDB($dbName);

        // Corrupt the file
        $filePath = $this->getDbFilePath($dbName);
        file_put_contents($filePath, '{invalid json}', LOCK_EX);
        clearstatcache(true, $filePath);

        // JSONL gracefully returns empty array on corrupted data
        $findResult = $this->noneDB->find($dbName, 0);

        $this->assertIsArray($findResult);
        $this->assertEmpty($findResult);
    }

    /**
     * @test
     */
    public function operationsOnEmptyFile(): void
    {
        $dbName = 'empty-file-test';

        // Create empty file
        $this->noneDB->createDB($dbName);
        $filePath = $this->getDbFilePath($dbName);
        file_put_contents($filePath, '', LOCK_EX);

        // Operations should handle gracefully
        $findResult = $this->noneDB->find($dbName, 0);

        $this->assertIsArray($findResult);
    }

    /**
     * @test
     * v3.0: JSONL format uses index file, non-JSONL data is treated as empty
     */
    public function operationsOnMissingDataKeyReturnsEmptyArray(): void
    {
        $dbName = 'missingdatakeytest';

        // Create file with valid JSON but missing 'data' key (non-JSONL format)
        $this->noneDB->createDB($dbName);
        $filePath = $this->getDbFilePath($dbName);
        file_put_contents($filePath, '{"items": []}', LOCK_EX);
        clearstatcache(true, $filePath);

        // JSONL uses index file for records, returns empty for non-JSONL data
        $findResult = $this->noneDB->find($dbName, 0);

        $this->assertIsArray($findResult);
        $this->assertEmpty($findResult);
    }

    // ==========================================
    // RESERVED FIELD EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function keyFieldInNestedData(): void
    {
        $dbName = 'nestedkeytest';

        // 'key' in nested structure should be allowed
        // Only top-level 'key' is reserved
        $data = [
            'metadata' => [
                'key' => 'api_key_12345',
                'secret' => 'secret_value'
            ]
        ];

        $result = $this->noneDB->insert($dbName, $data);

        // Should succeed - 'key' is only reserved at top level
        $this->assertEquals(1, $result['n']);

        $found = $this->noneDB->find($dbName, 0);
        $this->assertEquals('api_key_12345', $found[0]['metadata']['key']);
    }

    /**
     * @test
     */
    public function findByKeyZero(): void
    {
        $dbName = 'key-zero-test';

        $this->noneDB->insert($dbName, [
            ['name' => 'first'],
            ['name' => 'second'],
        ]);

        // Find by key 0 (first record)
        $result = $this->noneDB->find($dbName, ['key' => 0]);

        $this->assertCount(1, $result);
        $this->assertEquals('first', $result[0]['name']);
    }

    /**
     * @test
     */
    public function deleteByKeyZero(): void
    {
        $dbName = 'delete-key-zero-test';

        $this->noneDB->insert($dbName, [
            ['name' => 'first'],
            ['name' => 'second'],
        ]);

        // Delete by key 0
        $result = $this->noneDB->delete($dbName, ['key' => [0]]);

        $this->assertEquals(1, $result['n']);

        // First record should be null
        $remaining = $this->noneDB->find($dbName, ['name' => 'first']);
        $this->assertCount(0, $remaining);
    }
}
