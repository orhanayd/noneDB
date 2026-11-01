<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for getDBs() method
 */
class GetDBsTest extends noneDBTestCase
{
    /** @var int[] time() before and after setUp() created the databases */
    private $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create some test databases
        $this->created[0] = time();
        $this->noneDB->createDB('testdb1');
        $this->noneDB->createDB('testdb2');
        $this->noneDB->createDB('testdb3');
        $this->created[1] = time();
    }

    private function sortedNames(array $names): array
    {
        sort($names);
        return $names;
    }

    /** Independent model of the documented size format: B/KB/MB/GB/TB, 2 decimals, comma separator ("2,5 KB") */
    private function sizeOf(int $bytes): string
    {
        foreach (['TB' => 1024 ** 4, 'GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024, 'B' => 1] as $unit => $factor) {
            if ($bytes >= $factor) {
                return str_replace('.', ',', (string)round($bytes / $factor, 2)) . ' ' . $unit;
            }
        }
        return '0 B';
    }

    /** Creation time of a database as FORMAT stores it (docs/STORAGE_FORMAT.md §4.1 createdAt) */
    private function formatCreatedAt(string $name): int
    {
        $format = json_decode((string)file_get_contents($this->dbPath($name, 'format')), true);
        $this->assertIsInt($format['createdAt']);
        return $format['createdAt'];
    }

    /** The documented entry of a database created in setUp() and still empty */
    private function assertEmptyDbInfo(string $name, $info): void
    {
        $this->assertIsArray($info);
        $this->assertSame(['name', 'createdTime', 'size'], array_keys($info));
        $this->assertSame($name, $info['name']);
        $this->assertGreaterThanOrEqual($this->created[0], $info['createdTime']);
        $this->assertLessThanOrEqual($this->created[1], $info['createdTime']);
        $this->assertSame('0 B', $info['size'], 'an empty data file');
    }

    // ==========================================
    // GET ALL DATABASES
    // ==========================================

    /**
     * @test
     */
    public function getDBsWithoutInfoReturnsArray(): void
    {
        $result = $this->noneDB->getDBs(false);

        $this->assertSame(['testdb1', 'testdb2', 'testdb3'], $this->sortedNames($result));
    }

    /**
     * @test
     */
    public function getDBsWithoutInfoReturnsNames(): void
    {
        $result = $this->noneDB->getDBs(false);

        $this->assertCount(3, $result, 'each database once');
        $this->assertSame(['testdb1', 'testdb2', 'testdb3'], $this->sortedNames($result));
        // Index files, lock files and the store files are no databases
        $this->noneDB->createFieldIndex('testdb1', 'a');
        $this->assertSame(['testdb1', 'testdb2', 'testdb3'], $this->sortedNames($this->noneDB->getDBs(false)));
    }

    /**
     * @test
     */
    public function getDBsWithInfoReturnsArray(): void
    {
        $result = $this->noneDB->getDBs(true);

        $this->assertCount(3, $result);
        usort($result, function ($a, $b) { return strcmp($a['name'], $b['name']); });
        foreach (['testdb1', 'testdb2', 'testdb3'] as $i => $name) {
            $this->assertEmptyDbInfo($name, $result[$i]);
        }
    }

    /**
     * @test
     */
    public function getDBsWithInfoReturnsMetadata(): void
    {
        $this->noneDB->insert('testdb2', ['data' => str_repeat('x', 3000)]);
        $result = $this->noneDB->getDBs(true);
        usort($result, function ($a, $b) { return strcmp($a['name'], $b['name']); });

        $this->assertSame(['testdb1', 'testdb2', 'testdb3'], array_column($result, 'name'));
        $this->assertEmptyDbInfo('testdb1', $result[0]);
        $this->assertSame($this->sizeOf(filesize($this->getDbFilePath('testdb2'))), $result[1]['size']);
        $this->assertSame($result[1], $this->noneDB->getDBs('testdb2'), 'the same entry as for the single database');
    }

    /**
     * @test
     */
    public function getDBsCreatedTimeIsInteger(): void
    {
        $result = $this->noneDB->getDBs(true);

        foreach ($result as $info) {
            $this->assertIsInt($info['createdTime']);
            // The creation time stored in FORMAT
            $this->assertSame($this->formatCreatedAt($info['name']), $info['createdTime']);
        }
    }

    /**
     * @test
     */
    public function getDBsSizeIsString(): void
    {
        $result = $this->noneDB->getDBs(true);

        $this->assertSame(['0 B', '0 B', '0 B'], array_column($result, 'size'));
    }

    // ==========================================
    // GET SINGLE DATABASE
    // ==========================================

    /**
     * @test
     */
    public function getDBsSingleDatabaseReturnsInfo(): void
    {
        $result = $this->noneDB->getDBs('testdb1');

        $this->assertEmptyDbInfo('testdb1', $result);
    }

    /**
     * @test
     */
    public function getDBsSingleDatabaseHasCreatedTime(): void
    {
        $result = $this->noneDB->getDBs('testdb1');

        $this->assertSame($this->formatCreatedAt('testdb1'), $result['createdTime']);
    }

    /**
     * @test
     */
    public function getDBsSingleDatabaseHasSize(): void
    {
        $result = $this->noneDB->getDBs('testdb1');

        $this->assertSame('0 B', $result['size']);
    }

    /**
     * @test
     */
    public function getDBsNonExistentDBReturnsFalse(): void
    {
        // docs/API.md: "false if it doesn't exist; never creates it" (also with autoCreateDB true)
        $before = glob($this->testDbDir . '*');
        $this->assertFalse($this->noneDB->getDBs('nonexistent'));
        $this->setPrivateProperty('autoCreateDB', false);
        $this->assertFalse($this->noneDB->getDBs('nonexistent'));
        $this->assertSame($before, glob($this->testDbDir . '*'), 'nothing created');
        // Invalid names are rejected like everywhere else
        $this->expectException(\InvalidArgumentException::class);
        $this->noneDB->getDBs('bad/name');
    }

    // ==========================================
    // MULTIPLE CALLS TEST
    // ==========================================

    /**
     * @test
     */
    public function getDBsMultipleCallsNoError(): void
    {
        // This tests the fix for "Cannot redeclare FileSizeConvert()"
        $result1 = $this->noneDB->getDBs(true);
        $result2 = $this->noneDB->getDBs(true);
        $result3 = $this->noneDB->getDBs(true);

        $this->assertCount(3, $result1);
        $this->assertSame($result1, $result2);
        $this->assertSame($result1, $result3);
    }

    /**
     * @test
     */
    public function getDBsWithAndWithoutInfoAlternating(): void
    {
        $withInfo = $this->noneDB->getDBs(true);
        $withoutInfo = $this->noneDB->getDBs(false);
        $withInfo2 = $this->noneDB->getDBs(true);

        $this->assertSame($withoutInfo, array_column($withInfo, 'name'), 'the same databases in the same order');
        $this->assertSame($withInfo, $withInfo2);
        $this->assertSame(['testdb1', 'testdb2', 'testdb3'], $this->sortedNames($withoutInfo));
    }

    // ==========================================
    // FILE SIZE TESTS
    // ==========================================

    /**
     * @test
     */
    public function getDBsFileSizeIncreasesWithData(): void
    {
        $before = $this->noneDB->getDBs('testdb1');
        $this->assertSame('0 B', $before['size']);

        $this->noneDB->insert('testdb1', array_fill(0, 100, ['data' => 'test value']));

        // The size of the data file, formatted as documented
        clearstatcache();
        $bytes = filesize($this->getDbFilePath('testdb1'));
        $this->assertGreaterThan(1024, $bytes);
        $this->assertSame($this->sizeOf($bytes), $this->noneDB->getDBs('testdb1')['size']);
    }

    /**
     * @test
     */
    public function getDBsEmptyDBHasMinimalSize(): void
    {
        $result = $this->noneDB->getDBs('testdb1');

        // An empty database is an empty JSONL file
        $this->assertSame('0 B', $result['size']);
    }

    // ==========================================
    // EDGE CASES
    // ==========================================

    /**
     * @test
     */
    public function getDBsWithSpecialCharsInName(): void
    {
        $this->noneDB->createDB('test-special db');

        $result = $this->noneDB->getDBs('test-special db');

        $this->assertSame('test-special db', $result['name']);
        $this->assertContains('test-special db', $this->noneDB->getDBs(false));
    }

    /**
     * @test
     */
    public function getDBsWithNumericName(): void
    {
        $this->noneDB->createDB('12345');

        $result = $this->noneDB->getDBs('12345');

        $this->assertSame('12345', $result['name']);
        $this->assertSame($result, $this->noneDB->getDBs(12345), 'an integer names the same database');
        $this->assertContains('12345', $this->noneDB->getDBs(false));
    }

    /**
     * @test
     */
    public function getDBsCreatedTimeIsRecent(): void
    {
        $beforeCreate = time();
        $this->noneDB->createDB('timetest');
        $afterCreate = time();

        $result = $this->noneDB->getDBs('timetest');

        $this->assertGreaterThanOrEqual($beforeCreate, $result['createdTime']);
        $this->assertLessThanOrEqual($afterCreate, $result['createdTime']);
    }

    /**
     * @test
     */
    public function getDBsEmptyDirectoryReturnsEmptyArray(): void
    {
        // Clean all databases
        $this->cleanTestDirectory();

        $result = $this->noneDB->getDBs(false);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }
}
