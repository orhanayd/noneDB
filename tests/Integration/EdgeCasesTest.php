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
        $maliciousName = "test'; DROP TABLE users; --";

        $result = $this->noneDB->createDB($maliciousName);

        $this->assertTrue($result);

        // Verify DB was created safely
        $info = $this->noneDB->getDBs($maliciousName);
        $this->assertIsArray($info);
    }

    /**
     * @test
     */
    public function sqlInjectionAttemptInData(): void
    {
        $dbName = 'sql_injection_test';

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
        $dbName = 'xss_test';

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
        $maliciousName = '../../../etc/passwd';

        // The hash function should neutralize path traversal
        $result = $this->noneDB->createDB($maliciousName);

        $this->assertTrue($result);

        // Should NOT create file outside db directory
        $this->assertFileDoesNotExist('/etc/passwd.json');
    }

    /**
     * @test
     */
    public function nullByteInjectionAttempt(): void
    {
        $dbName = "test\x00malicious";

        // Should handle null bytes safely
        $result = $this->noneDB->createDB($dbName);

        $this->assertTrue($result);
    }

    // ==========================================
    // UNICODE AND SPECIAL CHARACTERS
    // ==========================================

    /**
     * @test
     */
    public function unicodeInDatabaseName(): void
    {
        $unicodeName = 'veritabanı_测试_データ';

        $this->noneDB->createDB($unicodeName);
        $this->noneDB->insert($unicodeName, ['data' => 'test']);

        $result = $this->noneDB->find($unicodeName, 0);

        $this->assertCount(1, $result);
    }

    /**
     * @test
     */
    public function unicodeInData(): void
    {
        $dbName = 'unicode_data_test';

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
        $dbName = 'special_fields_test';

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
        $dbName = 'long_field_test';
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
        $dbName = 'long_value_test';
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
        $dbName = 'nested_test';

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
        $result = $this->noneDB->createDB('');

        // Empty string should still work (hash will be consistent)
        $this->assertTrue($result);
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
        $dbName = 'boolean_test';

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
        $dbName = 'numeric_test';

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
        $dbName = 'float_test';

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
        $dbName = 'null_test';

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
        $dbName = 'empty_array_test';

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
        $dbName = 'json_special_test';

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
        $dbName = 'control_chars_test';

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
        $dbName = 'rapid_cycle_test';

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
        $dbName = 'find_after_delete_test';

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
        $dbName = 'update_after_delete_test';

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
     */
    public function operationsOnCorruptedDataReturnsFalse(): void
    {
        $dbName = 'corrupttest';

        // Create valid DB first
        $this->noneDB->createDB($dbName);

        // Corrupt the file
        $filePath = $this->getDbFilePath($dbName);
        file_put_contents($filePath, '{invalid json}', LOCK_EX);
        clearstatcache(true, $filePath);

        // noneDB returns false on corrupted/invalid JSON
        $findResult = $this->noneDB->find($dbName, 0);

        $this->assertFalse($findResult);
    }

    /**
     * @test
     */
    public function operationsOnEmptyFile(): void
    {
        $dbName = 'empty_file_test';

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
     */
    public function operationsOnMissingDataKeyReturnsFalse(): void
    {
        $dbName = 'missingdatakeytest';

        // Create file with valid JSON but missing 'data' key
        $this->noneDB->createDB($dbName);
        $filePath = $this->getDbFilePath($dbName);
        file_put_contents($filePath, '{"items": []}', LOCK_EX);
        clearstatcache(true, $filePath);

        // noneDB returns false when 'data' key is missing
        $findResult = $this->noneDB->find($dbName, 0);

        $this->assertFalse($findResult);
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
        $dbName = 'key_zero_test';

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
        $dbName = 'delete_key_zero_test';

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
