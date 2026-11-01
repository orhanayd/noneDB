<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * The store: STORE / STORE.2, STORE.LOCK, opening a store, foreign entries, registered keys
 * (docs/STORAGE_FORMAT.md §3). Expected values come from the specification, not from noneDB's output.
 */
class StoreFormatTest extends RegressionTestCase
{
    private const OTHER = 'another_secret_key_for_store_tests';

    /** Every entry below a directory: relative path => sha256 of a file, "dir" for a directory */
    private function snapshot(string $dir): array
    {
        $out = [];
        $walk = function (string $path, string $rel) use (&$walk, &$out): void {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $path . '/' . $entry;
                if (is_dir($full)) {
                    $out[$rel . $entry] = 'dir';
                    $walk($full, $rel . $entry . '/');
                } else {
                    $out[$rel . $entry] = hash_file('sha256', $full);
                }
            }
        };
        if (is_dir($dir)) {
            $walk(rtrim($dir, '/'), '');
        }
        ksort($out);
        return $out;
    }

    private function storeRecord(string $file): ?array
    {
        $raw = @file_get_contents($this->testDbDir . $file);
        if ($raw === false || substr($raw, -1) !== "\n" || !preg_match('/,"checksum":"([0-9a-f]{64})"\}\n\z/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        // §2: the checksum covers the record as written without its last member
        if (hash('sha256', substr($raw, 0, $m[0][1]) . "}\n") !== $m[1][0]) {
            return null;
        }
        return json_decode($raw, true);
    }

    private function keyCheck(string $key): string
    {
        return substr(hash_hmac('sha256', 'nonedb4/key', $key), 0, 32);
    }

    private function code(callable $fn): ?int
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            return $e->getCode();
        }
        return null;
    }

    /** @test */
    public function aReadOfAnEmptyDirectoryCreatesNothing(): void
    {
        $before = $this->snapshot($this->testDbDir);
        $db = $this->db();
        $this->assertSame([], $db->find('nothing', 0));
        $this->assertSame(0, $db->count('nothing'));
        $this->assertSame([], $db->getDBs());
        $this->assertSame($before, $this->snapshot($this->testDbDir), 'reads create no file (§3.2 step 3 only for writes)');
    }

    /** @test */
    public function theFirstWriteCreatesTheStoreWithTwoIdenticalCopies(): void
    {
        $db = $this->db();
        $db->insert('first', ['a' => 1]);
        $one = $this->storeRecord('STORE');
        $two = $this->storeRecord('STORE.2');
        $this->assertNotNull($one, 'STORE is a valid record (§2, §3.1)');
        $this->assertSame($one, $two, 'both copies identical (§3.1 invariant)');
        $this->assertSame('NONEDB4', $one['magic']);
        $this->assertSame('store', $one['kind']);
        $this->assertSame(4, $one['storageFormat']);
        $this->assertSame(1, $one['seq']);
        $this->assertSame([$this->keyCheck(self::SECRET)], $one['keys']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $one['storeId']);
        $this->assertFileExists($this->testDbDir . 'STORE.LOCK');
        $this->assertFileExists($this->testDbDir . '.htaccess');
        $this->assertFileExists($this->testDbDir . 'index.html');
        // The database directory is named by the locator (§1)
        $locator = substr(hash_hmac('sha256', 'nonedb4/db/first', self::SECRET), 0, 32);
        $this->assertDirectoryExists($this->testDbDir . $locator . '.ndb4');
    }

    /** @test */
    public function anEmptyDirectoryWithAllowedFilesBecomesAStore(): void
    {
        foreach (['.gitkeep', '.gitignore', '.DS_Store', '.htaccess', 'index.html', 'STORE.LOCK', 'STORE.tmp0123abcd'] as $allowed) {
            file_put_contents($this->testDbDir . $allowed, '');
        }
        $this->db()->insert('ok', ['a' => 1]);
        $this->assertNotNull($this->storeRecord('STORE'), '§3.2: these entries keep a directory empty');
        $this->assertFileDoesNotExist($this->testDbDir . 'STORE.tmp0123abcd', 'step 3.5: stale temp files removed');
        $this->assertSame([['a' => 1, 'key' => 0]], $this->db()->find('ok', 0));
    }

    /** @test */
    public function aForeignDirectoryIsRefusedAndNothingIsCreated(): void
    {
        foreach (['hash-users.nonedb' => '{"a":1,"key":0}' . "\n", 'notes.txt' => 'x', 'sub/file' => 'y'] as $name => $content) {
            @mkdir(dirname($this->testDbDir . $name), 0777, true);
            file_put_contents($this->testDbDir . $name, $content);
        }
        $before = $this->snapshot($this->testDbDir);
        $db = $this->db();
        foreach ([
            'insert' => function () use ($db) { $db->insert('users', ['a' => 1]); },
            'find' => function () use ($db) { $db->find('users', 0); },
            'count' => function () use ($db) { $db->count('users'); },
            'createDB' => function () use ($db) { $db->createDB('users'); },
            'getDBs' => function () use ($db) { $db->getDBs(); },
            'dropDB' => function () use ($db) { $db->dropDB('users'); },
            'checkIntegrity' => function () use ($db) { $db->checkIntegrity('users'); },
        ] as $op => $fn) {
            $this->assertSame(\noneDB::UNSUPPORTED_FORMAT, $this->code($fn), "$op: §3.2 step 2");
            $this->assertSame($before, $this->snapshot($this->testDbDir), "$op: no file or directory created (§3.2)");
        }
    }

    /** @test */
    public function databaseDirectoriesWithoutStoreRecordAreADamagedStore(): void
    {
        $this->db()->insert('d', ['a' => 1]);
        unlink($this->testDbDir . 'STORE');
        unlink($this->testDbDir . 'STORE.2');
        \noneDB::clearStaticCache();
        $before = $this->snapshot($this->testDbDir);
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () { $this->db()->find('d', 0); }), '§3.2: missing store record');
        $this->assertSame($before, $this->snapshot($this->testDbDir));
    }

    /** @test */
    public function anUnregisteredKeyNeverSeesAnEmptyDatabase(): void
    {
        $this->db()->insert('mine', ['a' => 1]);
        \noneDB::clearStaticCache();
        $before = $this->snapshot($this->testDbDir);
        $other = new \noneDB(['secretKey' => self::OTHER, 'dbDir' => $this->testDbDir]);
        foreach ([
            function () use ($other) { $other->find('mine', 0); },
            function () use ($other) { $other->insert('mine', ['b' => 2]); },
            function () use ($other) { $other->getDBs(); },
        ] as $i => $fn) {
            $this->assertSame(\noneDB::KEY_NOT_REGISTERED, $this->code($fn), "call $i: §3.2 step 1");
        }
        $this->assertSame($before, $this->snapshot($this->testDbDir), 'the wrong key created nothing');
    }

    /** @test */
    public function oneInvalidCopyIsRepairedFromTheOther(): void
    {
        $this->db()->insert('r', ['a' => 1]);
        $expected = $this->storeRecord('STORE');
        foreach (['STORE', 'STORE.2'] as $damaged) {
            foreach (['missing', 'garbage', 'checksum'] as $how) {
                \noneDB::clearStaticCache();
                if ($how === 'missing') {
                    unlink($this->testDbDir . $damaged);
                } elseif ($how === 'garbage') {
                    file_put_contents($this->testDbDir . $damaged, "\0\0\0");
                } else {
                    $raw = file_get_contents($this->testDbDir . $damaged);
                    file_put_contents($this->testDbDir . $damaged, str_replace('"seq":1', '"seq":2', $raw));
                }
                $this->assertSame([['a' => 1, 'key' => 0]], $this->db()->find('r', 0), "$damaged $how: readable");
                $this->assertSame($expected, $this->storeRecord('STORE'), "$damaged $how: STORE");
                $this->assertSame($expected, $this->storeRecord('STORE.2'), "$damaged $how: STORE.2 (§3.1 repair)");
            }
        }
        $log = file_get_contents($this->testDbDir . '.nonedb-maintenance/log.jsonl');
        $this->assertSame(6, substr_count($log, '"store-copy-repaired"'), 'every repair is logged');
    }

    /** @test */
    public function aCopyDamagedWhileTheStoreIsOpenNeverBreaksTheMissingDatabasePath(): void
    {
        $db = $this->db();
        $db->insert('r', ['a' => 1]);
        $expected = $this->storeRecord('STORE');
        foreach (['STORE', 'STORE.2'] as $damaged) {
            file_put_contents($this->testDbDir . $damaged, 'garbage');
            // The store is open in this process: the missing-database path checks it again under STORE.LOCK
            // shared, where the copy cannot be repaired; the answer still comes from the valid copy
            $this->assertSame([], $db->find('missing', 0), "$damaged: missing database read");
            $db->insert('created' . $damaged[strlen($damaged) - 1], ['b' => 2]);
            $this->assertSame([['b' => 2, 'key' => 0]], $db->find('created' . $damaged[strlen($damaged) - 1], 0), "$damaged: creation");
            \noneDB::clearStaticCache();
            $this->assertSame([['a' => 1, 'key' => 0]], $this->db()->find('r', 0), "$damaged: the next open");
            $this->assertSame($expected, $this->storeRecord('STORE'), "$damaged: repaired by the next open");
            $this->assertSame($expected, $this->storeRecord('STORE.2'), $damaged);
            $db = $this->db();
        }
    }

    /** @test */
    public function twoValidCopiesThatDifferAreDamaged(): void
    {
        $this->db()->insert('x', ['a' => 1]);
        $record = $this->storeRecord('STORE');
        unset($record['checksum']);
        $record['seq'] = 2;
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $record['checksum'] = hash('sha256', $json . "\n");
        file_put_contents($this->testDbDir . 'STORE.2', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
        $this->assertNotNull($this->storeRecord('STORE.2'), 'a valid record');
        \noneDB::clearStaticCache();
        $before = $this->snapshot($this->testDbDir);
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () { $this->db()->find('x', 0); }), '§3.1: nothing is guessed');
        $this->assertSame($before, $this->snapshot($this->testDbDir));
    }

    /** @test */
    public function bothCopiesInvalidIsDamaged(): void
    {
        $this->db()->insert('y', ['a' => 1]);
        file_put_contents($this->testDbDir . 'STORE', 'x');
        file_put_contents($this->testDbDir . 'STORE.2', '');
        \noneDB::clearStaticCache();
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () { $this->db()->find('y', 0); }));
    }

    /** @test */
    public function foreignEntriesStopMissingDatabasesButNotExistingOnes(): void
    {
        $db = $this->db();
        $db->insert('exists', ['a' => 1]);
        file_put_contents($this->testDbDir . 'abcdef-old.nonedb', '{"a":1,"key":0}' . "\n");
        \noneDB::clearStaticCache();
        $db = $this->db();
        // §3.4: an existing database is found without a listing and works
        $this->assertSame([['a' => 1, 'key' => 0]], $db->find('exists', 0));
        $db->insert('exists', ['a' => 2]);
        $this->assertSame(2, $db->count('exists'));
        // ... a missing one is neither "empty" nor created
        $before = $this->snapshot($this->testDbDir);
        foreach ([
            function () use ($db) { return $db->find('old', 0); },
            function () use ($db) { return $db->count('old'); },
            function () use ($db) { return $db->insert('old', ['b' => 1]); },
            function () use ($db) { return $db->createDB('old'); },
        ] as $i => $fn) {
            $this->assertSame(\noneDB::UNSUPPORTED_FORMAT, $this->code($fn), "call $i");
        }
        $this->assertSame($before, $this->snapshot($this->testDbDir));
    }

    /** @test */
    public function aForeignFileAddedInTheSameSecondIsSeen(): void
    {
        // §3.4: listed directly every time, no cache keyed on the directory's mtime (one-second resolution)
        $db = $this->db();
        $db->insert('base', ['a' => 1]);
        $this->assertSame([], $db->find('missing', 0), 'no foreign entry yet: missing means empty');
        $mtime = filemtime($this->testDbDir);
        file_put_contents($this->testDbDir . 'legacy-file.nonedb', 'x');
        touch($this->testDbDir, $mtime); // same mtime as before: a cache keyed on it would not notice
        clearstatcache();
        $this->assertSame(\noneDB::UNSUPPORTED_FORMAT, $this->code(function () use ($db) { $db->find('missing', 0); }));
        $this->assertSame(\noneDB::UNSUPPORTED_FORMAT, $this->code(function () use ($db) { $db->insert('missing', ['x' => 1]); }));
        $this->assertDirectoryDoesNotExist($this->dbPath('missing', 'home'));
    }

    /** @test */
    public function aForeignFileCreatedByAnotherProcessStopsEveryLaterMissingAnswer(): void
    {
        $db = $this->db();
        $db->insert('base', ['a' => 1]);
        $flag = $this->testDbDir . '../store-foreign-created';
        @unlink($flag);
        $proc = proc_open([PHP_BINARY, $this->childScript('
            usleep(200000);
            file_put_contents(' . var_export($this->testDbDir . 'old-data.nonedb', true) . ', "x");
            file_put_contents(' . var_export($flag, true) . ', "1");
        ')], [], $pipes);
        $created = false;
        $after = 0;
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && $after < 20) {
            $seenBefore = is_file($flag);
            $code = $this->code(function () use ($db) { $db->find('missing', 0); });
            if ($seenBefore) {
                $created = true;
                $after++;
                $this->assertSame(\noneDB::UNSUPPORTED_FORMAT, $code, 'every read after the file exists refuses');
            } else {
                $this->assertContains($code, [null, \noneDB::UNSUPPORTED_FORMAT]);
            }
        }
        proc_close($proc);
        @unlink($flag);
        $this->assertTrue($created);
        $this->assertDirectoryDoesNotExist($this->dbPath('missing', 'home'), 'never created');
    }

    /** @test */
    public function concurrentFirstOpensCreateOneStore(): void
    {
        $outputs = $this->runParallel('
            $db = ' . $this->childDb() . ';
            $db->insert("p" . $argv[1], ["n" => (int)$argv[1]]);
            echo "ok";
        ', 6);
        $this->assertSame(array_fill(0, 6, 'ok'), array_values($outputs));
        $record = $this->storeRecord('STORE');
        $this->assertNotNull($record);
        $this->assertSame($record, $this->storeRecord('STORE.2'));
        $this->assertSame(1, $record['seq'], 'created once');
        \noneDB::clearStaticCache();
        $names = $this->db()->getDBs();
        $this->assertSame(['p1', 'p2', 'p3', 'p4', 'p5', 'p6'], $names); // runParallel numbers processes from 1
    }

    /** @test */
    public function aStoreCreationInterruptedAtEveryStepIsFinishedByTheNextOpen(): void
    {
        foreach (['store:create', 'store:write'] as $point) {
            foreach ([1, 2] as $hit) {
                $this->cleanTestDirectory();
                $out = $this->runChild('
                    $db = ' . $this->childDb() . ';
                    $n = 0;
                    noneDB\Tests\Support\Testing::setFaultHook(function ($p) use (&$n) {
                        if ($p === ' . var_export($point, true) . ' && ++$n === ' . $hit . ') { exit(0); }
                    });
                    $db->insert("s", ["a" => 1]);
                    echo "not reached";
                ');
                if ($point === 'store:create' && $hit === 2) {
                    $this->assertSame('not reached', $out, 'store:create happens once');
                    continue;
                }
                $this->assertSame('', $out, "$point #$hit");
                \noneDB::clearStaticCache();
                $db = $this->db();
                $db->insert('s', ['a' => 2]);
                $this->assertSame([2], array_column($db->find('s', 0), 'a'), "$point #$hit: the interrupted insert never happened");
                $this->assertSame($this->storeRecord('STORE'), $this->storeRecord('STORE.2'), "$point #$hit: copies identical");
                $this->assertNotNull($this->storeRecord('STORE'));
            }
        }
    }
}
