<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for createDB() method
 */
class CreateDBTest extends noneDBTestCase
{
    /**
     * @test
     */
    public function createDBReturnsTrueOnSuccess(): void
    {
        $result = $this->noneDB->createDB('new-database');

        $this->assertTrue($result);
        $this->assertSame(['new-database'], $this->noneDB->getDBs(false));
    }

    /**
     * @test
     */
    public function createDBCreatesNonedbFile(): void
    {
        $this->noneDB->createDB('testcreate');

        $this->assertDatabaseExists('testcreate');
        $this->assertSame(0, filesize($this->getDbFilePath('testcreate')), 'an empty JSONL file (no records yet)');
    }

    /**
     * @test createDB() writes an empty key index checkpoint at offset 0 (docs/STORAGE_FORMAT.md §6.4: kind "kix",
     * v 1, layout, base, slots, live, n next key, d garbage, t tombstones, s bytes covered, h crc32b of the
     * bytes before s, e epoch of 8 hex digits; z/sid only in the sharded layout; no slots, so no body)
     */
    public function createDBWritesAnEmptyCheckpoint(): void
    {
        $this->noneDB->createDB('ck');
        $file = $this->readKeyIndexFile($this->dbPath('ck', 'kix'));
        $index = $file['header'];
        $this->assertSame('kix', $index['kind']);
        $this->assertSame(1, $index['v']);
        $this->assertSame([0, 0, 0, 0], [$index['n'], $index['d'], $index['t'], $index['s']]);
        $this->assertSame(['dense', 0, 0, 0], [$index['layout'], $index['base'], $index['slots'], $index['live']]);
        $this->assertSame([], $file['entries']);
        $this->assertSame(512, filesize($this->dbPath('ck', 'kix')), 'the header only');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $index['e']);
        $this->assertSame(hash('crc32b', ''), $index['h'], 'nothing before s = 0');
        $members = array_keys($index);
        sort($members);
        $this->assertSame(['base', 'created', 'd', 'e', 'h', 'kind', 'layout', 'live', 'n', 's', 'slots', 't', 'updated', 'v'], $members, 'the members of §6.4, nothing else');
    }

    /**
     * @test createDB() writes the database's identity record FORMAT (docs/STORAGE_FORMAT.md §4.1, §8.1)
     */
    public function createDBCreatesFormatFile(): void
    {
        $this->noneDB->createDB('testcreate');

        $filePath = $this->dbPath('testcreate', 'format');
        $this->assertFileExists($filePath, 'FORMAT should be created');
        $format = json_decode((string)file_get_contents($filePath), true);
        $this->assertSame(['NONEDB4', 'database', 4, 'testcreate'], [$format['magic'], $format['kind'], $format['storageFormat'], $format['name']]);
    }

    /**
     * @test
     */
    public function createDBInitializesEmptyDataArray(): void
    {
        $this->noneDB->createDB('testcreate');

        $contents = $this->getDatabaseContents('testcreate');

        $this->assertIsArray($contents);
        $this->assertArrayHasKey('data', $contents);
        $this->assertEmpty($contents['data']);
    }

    /**
     * @test
     */
    public function createDBReturnsFalseIfExists(): void
    {
        $this->noneDB->createDB('existing');
        $this->noneDB->insert('existing', ['a' => 1]);
        clearstatcache();
        $data = file_get_contents($this->getDbFilePath('existing'));
        $format = file_get_contents($this->dbPath('existing', 'format'));
        $result = $this->noneDB->createDB('existing');

        $this->assertFalse($result, 'Should return false if DB already exists');
        $this->assertSame($data, file_get_contents($this->getDbFilePath('existing')), 'data untouched');
        $this->assertSame($format, file_get_contents($this->dbPath('existing', 'format')), 'creation time untouched');
        $this->assertSame([['a' => 1, 'key' => 0]], $this->noneDB->find('existing', 0));
    }

    /**
     * @test a sharded database (shards.json, no main data file) exists too: createDB() returns false and adds no main file
     */
    public function createDBReturnsFalseForAShardedDatabase(): void
    {
        $this->setPrivateProperty('shardSize', 3);
        $this->noneDB->insert('shards', [['n' => 1], ['n' => 2], ['n' => 3], ['n' => 4]]);
        $this->assertTrue($this->noneDB->getShardInfo('shards')['sharded']);
        $this->assertFileDoesNotExist($this->getDbFilePath('shards'));
        $this->assertFalse($this->noneDB->createDB('shards'));
        $this->assertFileDoesNotExist($this->getDbFilePath('shards'));
        $this->assertSame([1, 2, 3, 4], array_column($this->noneDB->find('shards', 0), 'n'));
    }

    /**
     * @test
     */
    public function createDBRejectsInvalidNames(): void
    {
        // Invalid names are rejected, never sanitized
        try {
            $this->noneDB->createDB('test<script>db');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid database name', $e->getMessage());
        }
        $this->assertDatabaseNotExists('testscriptdb');
    }

    /**
     * @test
     */
    public function createDBAllowsSpacesInName(): void
    {
        $this->noneDB->createDB('my database');

        $this->assertDatabaseExists('my database');
    }

    /**
     * @test
     */
    public function createDBAllowsHyphensInName(): void
    {
        $this->noneDB->createDB('my-database');

        $this->assertDatabaseExists('my-database');
    }

    /**
     * @test
     */
    public function createDBAllowsApostropheInName(): void
    {
        $this->noneDB->createDB("user's-db");

        $this->assertDatabaseExists("user's-db");
    }

    /**
     * @test
     */
    public function createDBFormatRecordContainsTimestamp(): void
    {
        $beforeTime = time();
        $this->noneDB->createDB('testcreate');
        $afterTime = time();

        // FORMAT.createdAt: the creation time (docs/STORAGE_FORMAT.md §4.1)
        $timestamp = json_decode((string)file_get_contents($this->dbPath('testcreate', 'format')), true)['createdAt'];
        $this->assertIsInt($timestamp);

        $this->assertGreaterThanOrEqual($beforeTime, $timestamp);
        $this->assertLessThanOrEqual($afterTime, $timestamp);
    }

    /**
     * @test
     */
    public function createDBWithNumericName(): void
    {
        $this->noneDB->createDB('123456');

        $this->assertDatabaseExists('123456');
    }

    /**
     * @test
     */
    public function createDBWithMixedCase(): void
    {
        $this->noneDB->createDB('MyDatabase');

        $this->assertDatabaseExists('MyDatabase');
    }

    /**
     * @test
     */
    public function createDBCreatesDirectoryIfMissing(): void
    {
        $dir = $this->testDbDir . 'missing/nested/';
        $db = new \noneDB(['secretKey' => 'test_secret_key_for_unit_tests', 'dbDir' => $dir]);
        try {
            $this->assertDirectoryDoesNotExist($this->testDbDir . 'missing');
            $this->assertTrue($db->createDB('testdb'));
            $this->assertFileExists($dir . $this->locatorOf('testdb') . '.ndb4/FORMAT');
            // docs/CONFIGURATION.md: created "with an .htaccess that denies web access and an empty index.html"
            $this->assertSame("Require all denied\nDeny from all\n", file_get_contents($dir . '.htaccess'));
            $this->assertSame('', file_get_contents($dir . 'index.html'));
        } finally {
            \noneDB::clearStaticCache();
            $this->removeDirectoryTree($this->testDbDir . 'missing');
        }
    }

    /** @test A line feed is not one of the characters of a name, at the end either (`$` of a pattern matches before it) */
    public function aNameEndingInALineFeedIsRefused(): void
    {
        foreach (['createDB' => function () { $this->noneDB->createDB("a\n"); },
                  'insert' => function () { $this->noneDB->insert("b\n", ['x' => 1]); },
                  'find' => function () { $this->noneDB->find("c\n", []); }] as $call => $run) {
            try {
                $run();
                $this->fail("$call took it");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('invalid database name', $e->getMessage(), $call);
            }
        }
        $this->assertSame([], $this->noneDB->getDBs(false));
    }
}
