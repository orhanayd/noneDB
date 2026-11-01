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
        $this->assertSame([$maliciousData + ['key' => 0]], $result);
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
        $this->assertSame([$xssData + ['key' => 0]], $result);
    }

    /**
     * @test
     */
    public function pathTraversalAttemptInDBName(): void
    {
        $before = scandir($this->testDbDir);
        try {
            $this->noneDB->createDB('../../../etc/passwd');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid database name', $e->getMessage());
        }

        // Nothing created outside (or inside) the db directory
        $this->assertFileDoesNotExist('/etc/passwd.json');
        clearstatcache();
        $this->assertSame($before, scandir($this->testDbDir));
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
        // Non-ASCII names are rejected, never stripped (stripping would let names collide)
        try {
            $this->noneDB->createDB('veritabanı_测试_データ');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid database name', $e->getMessage());
        }

        // Stripped, 'çalışan' would become 'alan' and write into that database: it is rejected
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

        $this->assertSame([$unicodeData + ['key' => 0]], $result, 'every string byte for byte');
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

        $this->assertSame([$data + ['key' => 0]], $result);
        $this->assertSame([0], array_column($this->noneDB->find($dbName, ['field.with.dot' => 'value2']), 'key'), 'a dot is part of the name, not a path');
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
        $this->assertFalse($this->noneDB->createDB($longName), 'docs/API.md: false when it already exists');
        $this->assertSame(['n' => 1], $this->noneDB->insert($longName, ['v' => 1]));
        $this->assertSame([['v' => 1, 'key' => 0]], $this->noneDB->find($longName, 0));
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

        $this->assertSame([[$longFieldName => 'value', 'key' => 0]], $result);
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

        $this->assertSame($longValue, $result[0]['content']);
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

        $this->assertSame([$nested + ['key' => 0]], $result, 'all ten levels');
    }

    /**
     * @test
     */
    public function emptyStringDatabaseName(): void
    {
        // An empty name is invalid; checkDB() reports false, other operations throw
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

        $this->assertSame([['data' => 'test', 'key' => 0]], $result);
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

        $this->assertSame([['flag' => true, 'key' => 0]], $trueResult);
        $this->assertSame([['flag' => false, 'key' => 1]], $falseResult);
        $this->assertSame([], $this->noneDB->find($dbName, ['flag' => 1]), 'identical (===) comparison');
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

        // docs/API.md: filters compare with === ("25" does not match 25)
        $this->assertSame([['value' => 123, 'key' => 0]], $intResult);
        $this->assertSame([['value' => '123', 'key' => 1]], $strResult);
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

        $this->assertSame([$data + ['key' => 0]], $result, 'floats are stored exactly');
        $this->assertSame([0], array_column($this->noneDB->find($dbName, ['negative' => -123.456]), 'key'));
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

        $this->assertSame([$data + ['key' => 0]], $result, 'null fields are kept');
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

        $this->assertSame([['items' => [], 'tags' => [], 'key' => 0]], $result);
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

        // Every element with its type: 3.0 stays a float (docs/API.md "Floats keep their type")
        $this->assertSame([['mixed' => [1, 'two', 3.0, true, null, ['nested' => 'value']], 'key' => 0]], $result);
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

        $this->assertSame([$data + ['key' => 0]], $result);
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

        $this->assertSame("text\r\nwith\tcontrol\x00chars", $result[0]['content'], 'byte for byte, NUL included');
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

        // All records deleted
        $this->assertSame([], $result);
        // Keys 0..9 were used: the next record gets 10 (keys are never reused)
        $this->noneDB->insert($dbName, ['iteration' => 'after']);
        $this->assertSame([['iteration' => 'after', 'key' => 10]], $this->noneDB->find($dbName, 0));
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

        $this->assertSame([0, 2, 4], array_column($remaining, 'index'));
        $this->assertSame([0, 2, 4], array_column($remaining, 'key'));
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

        $this->assertSame(['n' => 2], $result);
        $this->assertSame([[1, 'inactive', 0], [3, 'inactive', 2]], array_map(function ($r) {
            return [$r['id'], $r['status'], $r['key']];
        }, $this->noneDB->find($dbName, 0)));
    }

    // ==========================================
    // ERROR RECOVERY
    // ==========================================

    /**
     * @test
     * Corrupted data is handled gracefully (returns empty array)
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

        // The bytes have no line end: an unterminated tail, never a record (docs/STORAGE_FORMAT.md §6.1)
        $findResult = $this->noneDB->find($dbName, 0);

        $this->assertSame([], $findResult);
        clearstatcache();
        $this->assertSame('{invalid json}', file_get_contents($filePath), 'readers never cut');

        // The next write keeps the bytes in a quarantine file recovery/q.{seg}.{off}.{len}.{sha256}.bin (§7.2),
        // cuts them and starts the data at offset 0
        $this->assertSame(['n' => 1], $this->noneDB->insert($dbName, ['v' => 'after']));
        $this->assertSame([['v' => 'after', 'key' => 0]], $this->noneDB->find($dbName, 0));
        $quarantine = glob($this->dbPath($dbName, 'quarantine') . 'q.*');
        $this->assertSame([$this->dbPath($dbName, 'quarantine') . 'q.main.0.14.' . hash('sha256', '{invalid json}') . '.bin'], $quarantine);
        $this->assertSame('{invalid json}', file_get_contents($quarantine[0]));
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

        $findResult = $this->noneDB->find($dbName, 0);

        $this->assertSame([], $findResult);
        $this->assertSame(['n' => 1], $this->noneDB->insert($dbName, ['v' => 1]));
        $this->assertSame([['v' => 1, 'key' => 0]], $this->noneDB->find($dbName, 0));
    }

    /**
     * @test
     * Bytes that are not a complete JSONL line are no records: the database reads as empty
     */
    public function operationsOnMissingDataKeyReturnsEmptyArray(): void
    {
        $dbName = 'missingdatakeytest';

        // Valid JSON without a line end and without a key
        $this->noneDB->createDB($dbName);
        $filePath = $this->getDbFilePath($dbName);
        file_put_contents($filePath, '{"items": []}', LOCK_EX);
        clearstatcache(true, $filePath);

        // No complete line: nothing is a record (docs/STORAGE_FORMAT.md §6.1)
        $findResult = $this->noneDB->find($dbName, 0);

        $this->assertSame([], $findResult);
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
        $this->assertSame(['n' => 1], $result);

        $found = $this->noneDB->find($dbName, 0);
        $this->assertSame([$data + ['key' => 0]], $found);
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

        $this->assertSame([['name' => 'first', 'key' => 0]], $result);
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

        $this->assertSame(['n' => 1], $result);

        // The first record is gone, the second keeps key 1
        $remaining = $this->noneDB->find($dbName, ['name' => 'first']);
        $this->assertSame([], $remaining);
        $this->assertSame([['name' => 'second', 'key' => 1]], $this->noneDB->find($dbName, 0));
    }
}
