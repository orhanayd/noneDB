<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\noneDBTestCase;

/**
 * Feature tests for checkDB() method
 */
class CheckDBTest extends noneDBTestCase
{
    /**
     * @test
     */
    public function checkDBReturnsFalseForNull(): void
    {
        $result = $this->noneDB->checkDB(null);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function checkDBReturnsFalseForEmptyString(): void
    {
        $result = $this->noneDB->checkDB('');

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function checkDBReturnsFalseForOnlySpecialChars(): void
    {
        // Invalid names are rejected, never silently stripped
        $this->expectException(\InvalidArgumentException::class);
        $this->noneDB->checkDB('@#$%^&*()');
    }

    /**
     * @test
     */
    public function checkDBReturnsTrueForExistingDB(): void
    {
        $this->noneDB->createDB('existing');
        $this->noneDB->insert('existing', ['a' => 1]);
        $before = file_get_contents($this->getDbFilePath('existing'));

        $this->assertTrue($this->noneDB->checkDB('existing'));
        // Also without autoCreateDB, and the data is left as it is
        $this->setPrivateProperty('autoCreateDB', false);
        $this->assertTrue($this->noneDB->checkDB('existing'));
        $this->assertSame($before, file_get_contents($this->getDbFilePath('existing')));
        $this->assertSame([['a' => 1, 'key' => 0]], $this->noneDB->find('existing', 0));
    }

    /**
     * @test
     */
    public function checkDBAutoCreatesWhenEnabled(): void
    {
        // autoCreateDB is true by default
        $result = $this->noneDB->checkDB('autotest');

        $this->assertTrue($result);
        $this->assertDatabaseExists('autotest');
        // An empty database, listed like any other
        $this->assertSame(0, filesize($this->getDbFilePath('autotest')));
        $this->assertSame([], $this->noneDB->find('autotest', 0));
        $this->assertSame(['autotest'], $this->noneDB->getDBs(false));
    }

    /**
     * @test
     */
    public function checkDBReturnsFalseWhenAutoCreateDisabled(): void
    {
        $this->setPrivateProperty('autoCreateDB', false);

        $result = $this->noneDB->checkDB('nonexistent');

        $this->assertFalse($result);
        $this->assertDatabaseNotExists('nonexistent');
        $this->assertDirectoryDoesNotExist($this->dbPath('nonexistent', 'home'), 'no file of the database is created');
        $this->assertSame([], $this->noneDB->getDBs(false));
    }

    /**
     * @test with autoCreateDB false, checkDB() only checks (docs/API.md: "Check if database exists. Creates it if
     * autoCreateDB is true"): like every read of a missing database it creates nothing, no directory, no lock
     * file (docs/STORAGE_FORMAT.md §8.3: an absent database's "reads return empty results and create nothing")
     */
    public function checkDBWithoutAutoCreateCreatesNothing(): void
    {
        $dir = $this->testDbDir . 'not-created-yet/';
        $db = new \noneDB(['secretKey' => 'test_secret_key_for_unit_tests', 'dbDir' => $dir, 'autoCreateDB' => false]);
        try {
            $this->assertFalse($db->checkDB('nope'));
            $this->assertDirectoryDoesNotExist($dir);
        } finally {
            \noneDB::clearStaticCache();
            $this->removeDirectoryTree($dir);
        }
    }

    /**
     * @test
     */
    public function checkDBRejectsInvalidNames(): void
    {
        // Names with characters outside [A-Za-z0-9' -] are rejected, nothing is created
        try {
            $this->noneDB->checkDB('test<script>db');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid database name', $e->getMessage());
        }
        $this->assertDatabaseNotExists('testscriptdb');
    }

    /**
     * @test
     */
    public function checkDBAllowsAlphanumeric(): void
    {
        $this->assertCreatedUnchanged('TestDB123');
    }

    /** The name is used as it is: the directory is named after it and getDBs() lists it unchanged */
    private function assertCreatedUnchanged(string $name): void
    {
        $this->assertTrue($this->noneDB->checkDB($name));
        $this->assertDatabaseExists($name);
        $this->assertSame([$name], $this->noneDB->getDBs(false));
    }

    /**
     * @test
     */
    public function checkDBAllowsSpaces(): void
    {
        $this->assertCreatedUnchanged('my test db');
    }

    /**
     * @test
     */
    public function checkDBAllowsHyphens(): void
    {
        $this->assertCreatedUnchanged('my-test-db');
    }

    /**
     * @test
     */
    public function checkDBAllowsApostrophes(): void
    {
        $this->assertCreatedUnchanged("user's-db");
    }

    /**
     * @test
     */
    public function checkDBRejectsNonAsciiNames(): void
    {
        // Non-ASCII characters are rejected, never stripped (stripping would let 'çalışan' collide with 'alan')
        try {
            $this->noneDB->checkDB('veritabanı');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('invalid database name', $e->getMessage());
        }
        $this->assertDatabaseNotExists('veritaban');
    }

    /**
     * @test
     */
    public function checkDBCreatesDbDirectory(): void
    {
        // docs/CONFIGURATION.md: dbDir is "Created automatically (with an .htaccess that denies web access and an
        // empty index.html)"
        $dir = $this->testDbDir . 'fresh-dir/';
        $db = new \noneDB(['secretKey' => 'test_secret_key_for_unit_tests', 'dbDir' => $dir]);
        try {
            $this->assertDirectoryDoesNotExist($dir);
            $this->assertTrue($db->checkDB('made'));
            $this->assertDirectoryExists($dir);
            $this->assertSame("Require all denied\nDeny from all\n", file_get_contents($dir . '.htaccess'));
            $this->assertSame('', file_get_contents($dir . 'index.html'));
            $this->assertFileExists($dir . $this->locatorOf('made') . '.ndb4/FORMAT');
        } finally {
            \noneDB::clearStaticCache();
            $this->removeDirectoryTree($dir);
        }
    }

    /**
     * @test
     */
    public function checkDBWithVeryLongName(): void
    {
        $longName = str_repeat('a', 100);
        $this->assertCreatedUnchanged($longName);
    }

    /**
     * @test
     */
    public function checkDBReturnsFalseForFalseValue(): void
    {
        // Testing with boolean false
        $result = @$this->noneDB->checkDB(false);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function checkDBAcceptsZeroAsAName(): void
    {
        // "0" is a valid database name (not treated as empty); 0 and "0" are the same name
        $this->assertTrue($this->noneDB->checkDB(0));
        $this->assertDatabaseExists('0');
        $this->noneDB->insert('0', ['v' => 1]);
        $this->assertTrue($this->noneDB->checkDB('0'));
        $this->assertSame([['v' => 1, 'key' => 0]], $this->noneDB->find(0, 0));
        $this->assertSame(['0'], $this->noneDB->getDBs(false));
    }
}
