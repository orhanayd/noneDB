<?php

namespace noneDB\Tests\Unit;

use noneDB\Tests\noneDBTestCase;

/**
 * Database directory naming (the private locator() method) and key separation
 *
 * Expected values come from the specification (docs/STORAGE_FORMAT.md §1, §3, §4):
 * locator = first 32 hex digits of hash_hmac('sha256', 'nonedb4/db/' . name, key), the directory of a database
 * is {dbDir}{locator}.ndb4/, and STORE lists the keyCheck = first 32 hex digits of
 * hash_hmac('sha256', 'nonedb4/key', key) of every key allowed to open the store.
 */
class DatabaseLocatorTest extends noneDBTestCase
{
    private const KEY = 'test_secret_key_for_unit_tests'; // the key noneDBTestCase configures

    private function spec(string $name, string $key = self::KEY): string
    {
        return substr(hash_hmac('sha256', 'nonedb4/db/' . $name, $key), 0, 32);
    }

    private function specKeyCheck(string $key): string
    {
        return substr(hash_hmac('sha256', 'nonedb4/key', $key), 0, 32);
    }

    private function locator(string $name, ?\noneDB $db = null): string
    {
        return $this->getPrivateMethod('locator')->invoke($db ?? $this->noneDB, $name);
    }

    /**
     * @test
     */
    public function locatorIs32Characters(): void
    {
        $locator = $this->locator('testdb');

        $this->assertSame(32, strlen($locator), 'Locator should be exactly 32 characters');
        $this->assertSame($this->spec('testdb'), $locator);
    }

    /**
     * @test
     */
    public function locatorIsConsistentForSameInput(): void
    {
        $first = $this->locator('mydb');
        $this->assertSame($first, $this->locator('mydb'), 'cached');
        // A new process state (no in-memory cache) computes the same value
        \noneDB::clearStaticCache();
        $this->assertSame($first, $this->locator('mydb', $this->createTestInstance()));
        $this->assertSame($this->spec('mydb'), $first);
    }

    /**
     * @test
     */
    public function differentInputsProduceDifferentLocators(): void
    {
        $this->assertSame($this->spec('database1'), $this->locator('database1'));
        $this->assertSame($this->spec('database2'), $this->locator('database2'));
        $this->assertNotSame($this->locator('database1'), $this->locator('database2'));
    }

    /**
     * @test
     */
    public function locatorWorksWithEmptyString(): void
    {
        $this->assertSame($this->spec(''), $this->locator(''));
    }

    /**
     * @test
     */
    public function locatorWorksWithSpecialCharacters(): void
    {
        $this->assertSame($this->spec("test'db-name 123"), $this->locator("test'db-name 123"));
    }

    /**
     * @test
     */
    public function locatorWorksWithUnicodeCharacters(): void
    {
        $this->assertSame($this->spec('veritabanı'), $this->locator('veritabanı'));
    }

    /**
     * @test
     */
    public function locatorWorksWithVeryLongString(): void
    {
        $longString = str_repeat('a', 10000);
        $this->assertSame($this->spec($longString), $this->locator($longString));
    }

    /**
     * @test
     */
    public function locatorOnlyContainsHexCharacters(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->locator('testdb'), 'exactly 32 lower-case hex digits');
    }

    /**
     * @test
     */
    public function locatorIsCaseSensitive(): void
    {
        $this->assertSame($this->spec('TestDB'), $this->locator('TestDB'));
        $this->assertNotSame($this->locator('TestDB'), $this->locator('testdb'), 'Locator should be case sensitive');
    }

    /**
     * @test
     */
    public function locatorWithWhitespace(): void
    {
        $this->assertSame($this->spec('test db'), $this->locator('test db'));
        $this->assertNotSame($this->locator('test db'), $this->locator('testdb'), 'Whitespace should affect the locator');
    }

    /**
     * @test the key binds the locator: the same name under another key has another directory (STORAGE_FORMAT.md §1
     * "unpredictable without the key, and changes when the key is rotated"), also within one process (caches are per key)
     */
    public function locatorDependsOnTheSecretKey(): void
    {
        $other = new \noneDB(['secretKey' => 'a-completely-different-key', 'dbDir' => $this->testDbDir]);
        $this->assertSame($this->spec('users'), $this->locator('users'));
        $this->assertSame($this->spec('users', 'a-completely-different-key'), $this->locator('users', $other));
        $this->assertNotSame($this->locator('users'), $this->locator('users', $other));
        $this->assertSame($this->spec('users'), $this->locator('users'), 'the other key cached nothing for this one');
    }

    /**
     * @test a database is the directory {locator}.ndb4/ in dbDir with FORMAT, LOCK, CURRENT and its generation
     * (STORAGE_FORMAT.md §3, §4); no entry of dbDir names the database in clear text
     */
    public function databaseDirectoryIsNamedAfterTheLocator(): void
    {
        $this->noneDB->insert('named', ['a' => 1]);
        $home = $this->testDbDir . $this->spec('named') . '.ndb4/';
        $this->assertDirectoryExists($home);
        $this->assertSame($this->dbPath('named', 'home'), $home);
        $this->assertFileExists($home . 'FORMAT');
        $this->assertFileExists($home . 'LOCK');
        $this->assertFileExists($home . 'CURRENT');
        $this->assertFileExists($home . 'generations/1/metadata');
        $this->assertFileExists($home . 'generations/1/segments/main.jsonl');

        $entries = array_values(array_diff(scandir($this->testDbDir), ['.', '..']));
        sort($entries);
        $expected = ['.htaccess', 'STORE', 'STORE.2', 'STORE.LOCK', $this->spec('named') . '.ndb4', 'index.html'];
        sort($expected);
        $this->assertSame($expected, $entries);
        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('named', $entry, 'the name is not readable from dbDir');
        }

        // FORMAT carries the name but no key material (§4.1)
        $format = json_decode((string)file_get_contents($home . 'FORMAT'), true);
        $this->assertSame('named', $format['name']);
        $raw = (string)file_get_contents($home . 'FORMAT');
        $this->assertStringNotContainsString(self::KEY, $raw);
        $this->assertStringNotContainsString($this->specKeyCheck(self::KEY), $raw);
    }

    /**
     * @test STORE lists the keyCheck of the key that created the store, never the key itself (STORAGE_FORMAT.md §1, §3.1)
     */
    public function storeListsTheKeyCheckOfItsKey(): void
    {
        $this->noneDB->insert('named', ['a' => 1]);
        foreach (['STORE', 'STORE.2'] as $copy) {
            $raw = (string)file_get_contents($this->testDbDir . $copy);
            $store = json_decode($raw, true);
            $this->assertSame([$this->specKeyCheck(self::KEY)], $store['keys'], $copy);
            $this->assertStringNotContainsString(self::KEY, $raw, $copy);
        }
    }

    /**
     * @test key separation: a key that is not registered in STORE can't use the store (KEY_NOT_REGISTERED, nothing
     * created, §3.2); once registered, the same name under each key is its own database in its own directory
     */
    public function keysAreSeparated(): void
    {
        $otherKey = 'another-registered-key-0123';
        $this->noneDB->insert('users', ['owner' => 'first']);
        $before = scandir($this->testDbDir);

        $other = new \noneDB(['secretKey' => $otherKey, 'dbDir' => $this->testDbDir]);
        foreach (['find' => function () use ($other) { return $other->find('users', 0); },
                  'insert' => function () use ($other) { return $other->insert('users', ['owner' => 'second']); }] as $op => $call) {
            try {
                $call();
                $this->fail("$op with an unregistered key must fail");
            } catch (\RuntimeException $e) {
                $this->assertSame(\noneDB::KEY_NOT_REGISTERED, $e->getCode(), $op);
            }
        }
        clearstatcache();
        $this->assertSame($before, scandir($this->testDbDir), 'nothing was created');
        $this->assertDirectoryDoesNotExist($this->testDbDir . $this->spec('users', $otherKey) . '.ndb4');

        \noneDB::clearStaticCache();
        $result = \noneDB::registerKey($this->testDbDir, self::KEY, $otherKey);
        $this->assertTrue($result['success'], (string)$result['error']);
        $expectedKeys = [$this->specKeyCheck(self::KEY), $this->specKeyCheck($otherKey)];
        sort($expectedKeys);
        $this->assertSame($expectedKeys, $result['storeManifest']['keys']);

        $other = new \noneDB(['secretKey' => $otherKey, 'dbDir' => $this->testDbDir]);
        $this->assertSame([], $other->find('users', 0), 'the other key does not see the first key\'s database');
        $this->assertSame(1, $other->insert('users', ['owner' => 'second'])['n']);
        $this->assertDirectoryExists($this->testDbDir . $this->spec('users', $otherKey) . '.ndb4');
        $this->assertDirectoryExists($this->testDbDir . $this->spec('users') . '.ndb4');

        $this->assertSame([['owner' => 'second', 'key' => 0]], $other->find('users', 0));
        $first = $this->createTestInstance();
        $this->assertSame([['owner' => 'first', 'key' => 0]], $first->find('users', 0));
        $this->assertSame(['users'], $first->getDBs(false));
        $this->assertSame(['users'], $other->getDBs(false));
    }
}
