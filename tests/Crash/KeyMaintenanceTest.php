<?php

namespace noneDB\Tests\Crash;

use noneDB\Tests\RegressionTestCase;

/**
 * Key maintenance (docs/STORAGE_FORMAT.md §9): migrateKey(), registerKey(), repairStore(), the
 * maintenance order, the journal as commit point, the two STORE copies, the barrier for running processes.
 */
class KeyMaintenanceTest extends RegressionTestCase
{
    private const NEW = 'new_secret_key_for_maintenance_tests';
    private const THIRD = 'third_secret_key_for_maintenance_tests';

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

    private function record(string $file): ?array
    {
        $raw = @file_get_contents($this->testDbDir . $file);
        if ($raw === false || !preg_match('/,"checksum":"([0-9a-f]{64})"\}\n\z/', $raw, $m, PREG_OFFSET_CAPTURE)
            || hash('sha256', substr($raw, 0, $m[0][1]) . "}\n") !== $m[1][0]) {
            return null;
        }
        return json_decode($raw, true);
    }

    private function keyCheck(string $key): string
    {
        return substr(hash_hmac('sha256', 'nonedb4/key', $key), 0, 32);
    }

    private function locator(string $name, string $key): string
    {
        return substr(hash_hmac('sha256', 'nonedb4/db/' . $name, $key), 0, 32);
    }

    private function with(string $key): \noneDB
    {
        return new \noneDB(['secretKey' => $key, 'dbDir' => $this->testDbDir, 'shardSize' => 3]);
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

    private function seed(): void
    {
        $db = $this->with(self::SECRET);
        $db->createFieldIndex('users', 'city');
        $db->insert('users', [['n' => 1, 'city' => 'A'], ['n' => 2, 'city' => 'B'], ['n' => 3, 'city' => 'A'], ['n' => 4, 'city' => 'C']]);
        $db->insert('orders', [['o' => 'x'], ['o' => 'y']]);
        \noneDB::clearStaticCache();
    }

    /** Expected end state of a rotation from SECRET to NEW */
    private function assertRotated(string $context): void
    {
        \noneDB::clearStaticCache();
        $store = $this->record('STORE');
        $this->assertNotNull($store, $context);
        $this->assertSame($store, $this->record('STORE.2'), "$context: copies identical");
        $this->assertSame([$this->keyCheck(self::NEW)], $store['keys'], "$context: old key out, new key in");
        $this->assertFileDoesNotExist($this->testDbDir . '.nonedb-maintenance/journal.json', "$context: journal closed");
        foreach (['users', 'orders'] as $name) {
            $this->assertDirectoryDoesNotExist($this->testDbDir . $this->locator($name, self::SECRET) . '.ndb4', "$context: $name old dir");
            $this->assertDirectoryExists($this->testDbDir . $this->locator($name, self::NEW) . '.ndb4', "$context: $name new dir");
        }
        $new = $this->with(self::NEW);
        $this->assertSame([1, 2, 3, 4], array_column($new->find('users', 0), 'n'), $context);
        $this->assertSame([1, 3], array_column($new->find('users', ['city' => 'A']), 'n'), "$context: indexes moved with the data");
        $this->assertSame(['x', 'y'], array_column($new->find('orders', 0), 'o'), $context);
        $this->assertSame(['orders', 'users'], $new->getDBs(), $context);
        foreach (['users', 'orders'] as $name) {
            $this->assertNotContains('m', $this->lockStateOfDir($this->locator($name, self::NEW))['flags'], "$context: $name m cleared");
        }
        $this->assertSame(\noneDB::KEY_NOT_REGISTERED, $this->code(function () { $this->with(self::SECRET)->find('users', 0); }), "$context: old key refused");
        $this->assertDirectoryDoesNotExist($this->testDbDir . $this->locator('users', self::SECRET) . '.ndb4', "$context: the old key created nothing");
    }

    private function lockStateOfDir(string $locator): array
    {
        $tokens = explode(' ', trim((string)file_get_contents($this->testDbDir . $locator . '.ndb4/LOCK'), " \0"));
        $state = (string)array_shift($tokens);
        return ['state' => $state, 'flags' => array_values(array_filter($tokens, 'strlen'))];
    }

    /** @test */
    public function rotationMovesEveryDatabaseToTheNewKey(): void
    {
        $this->seed();
        $insideBefore = $this->snapshot($this->testDbDir . $this->locator('users', self::SECRET) . '.ndb4');
        $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, self::NEW);
        $this->assertTrue($result['success'], (string)$result['error']);
        $this->assertSame(['orders', 'users'], (function ($d) { sort($d); return $d; })($result['databases']));
        $this->assertSame($this->record('STORE'), $result['storeManifest'], 'storeManifest = the STORE record, checksum included (§9.4)');
        // Right after the rotation, before any read (readers may refresh derived files for the new epoch)
        $insideAfter = $this->snapshot($this->testDbDir . $this->locator('users', self::NEW) . '.ndb4');
        unset($insideBefore['LOCK'], $insideAfter['LOCK']);
        $this->assertSame($insideBefore, $insideAfter, 'contents unchanged: FORMAT and metadata hold no key (§4.1)');
        $this->assertRotated('rotation');
        $this->assertSame(2, $this->record('STORE')['seq']);
    }

    /** @test */
    public function rotationInterruptedAtEveryStepIsFinishedByARerun(): void
    {
        $points = [['maintenance:journal', 1], ['rotate:marked', 1], ['rotate:renamed', 1], ['rotate:renamed', 2],
            ['maintenance:journal', 2], ['rotate:committed', 1], ['store:write', 1], ['store:write', 2],
            ['maintenance:store-written', 1], ['maintenance:close', 1]];
        foreach ($points as [$point, $hit]) {
            $context = "$point #$hit";
            $this->cleanTestDirectory();
            $this->seed();
            $out = $this->runChild('
                $n = 0;
                noneDB\Tests\Support\Testing::setFaultHook(function ($p) use (&$n) { if ($p === ' . var_export($point, true) . ' && ++$n === ' . $hit . ') { exit(0); } });
                $r = noneDB::migrateKey(' . var_export($this->testDbDir, true) . ', ' . var_export(self::SECRET, true) . ', ' . var_export(self::NEW, true) . ');
                echo "not reached ", json_encode($r);
            ');
            $this->assertSame('', $out, $context);
            \noneDB::clearStaticCache();
            $journalOpen = is_file($this->testDbDir . '.nonedb-maintenance/journal.json');
            if ($journalOpen) {
                // §9.1: while the journal exists no store is opened, whatever the key
                foreach ([self::SECRET, self::NEW] as $key) {
                    $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $this->code(function () use ($key) { $this->with($key)->find('users', 0); }), "$context: open refused");
                    \noneDB::clearStaticCache();
                }
            }
            $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, self::NEW);
            $this->assertTrue($result['success'], "$context: rerun " . $result['error']);
            $this->assertRotated($context);
        }
    }

    /** @test */
    public function anUncommittedRotationCanBeAborted(): void
    {
        $this->seed();
        $before = $this->snapshot($this->testDbDir);
        $out = $this->runChild('
            noneDB\Tests\Support\Testing::setFaultHook(function ($p) { if ($p === "rotate:renamed") { exit(0); } });
            noneDB::migrateKey(' . var_export($this->testDbDir, true) . ', ' . var_export(self::SECRET, true) . ', ' . var_export(self::NEW, true) . ');
            echo "not reached";
        ');
        $this->assertSame('', $out);
        $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, self::NEW, ['abort' => true]);
        $this->assertTrue($result['success'], (string)$result['error']);
        \noneDB::clearStaticCache();
        $this->assertSame([1, 2, 3, 4], array_column($this->with(self::SECRET)->find('users', 0), 'n'), 'old key works again');
        $this->assertSame(\noneDB::KEY_NOT_REGISTERED, $this->code(function () { $this->with(self::NEW)->find('users', 0); }));
        $after = $this->snapshot($this->testDbDir);
        // The same files with the same content, except the state records (new epochs) and the store log dir
        $strip = function (array $snap) {
            return array_filter($snap, function ($k) { return substr($k, -4) !== 'LOCK' && strpos($k, '.nonedb-maintenance') !== 0; }, ARRAY_FILTER_USE_KEY);
        };
        $this->assertSame($strip($before), $strip($after));
    }

    private function setRenameFn(?callable $fn): void
    {
        $property = new \noneDB\Tests\InternalValue('renameFn');
        $property->setValue(null, $fn);
    }

    /** A rotation whose first renamed database fails verification (its CURRENT damaged right after the rename) */
    private function rotateWithAFailingVerification(): array
    {
        $damaged = null;
        $this->setFaultHook(function ($point, $context) use (&$damaged) {
            if ($point === 'rotate:renamed' && $damaged === null) {
                $damaged = $context['db'];
                file_put_contents($this->testDbDir . $this->locator($damaged, self::NEW) . '.ndb4/CURRENT', 'damaged');
            }
        });
        try {
            return [\noneDB::migrateKey($this->testDbDir, self::SECRET, self::NEW), $damaged];
        } finally {
            $this->setFaultHook(null);
        }
    }

    /** @test */
    public function aRotationThatFailsVerificationIsUndone(): void
    {
        $this->seed();
        $store = $this->record('STORE');
        [$result, $damaged] = $this->rotateWithAFailingVerification();
        $this->assertNotNull($damaged);
        $this->assertFalse($result['success']);
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $result['code'], (string)$result['error']);
        $this->assertStringContainsString("'$damaged'", (string)$result['error']);
        $this->assertSame($store, $this->record('STORE'), 'nothing committed');
        $this->assertSame($store, $this->record('STORE.2'));
        $this->assertFileDoesNotExist($this->testDbDir . '.nonedb-maintenance/journal.json', 'undone and closed');
        foreach (['users', 'orders'] as $name) {
            $this->assertDirectoryExists($this->testDbDir . $this->locator($name, self::SECRET) . '.ndb4', "$name moved back");
            $this->assertDirectoryDoesNotExist($this->testDbDir . $this->locator($name, self::NEW) . '.ndb4', $name);
            $this->assertNotContains('m', $this->lockStateOfDir($this->locator($name, self::SECRET))['flags'], "$name: m cleared");
        }
        \noneDB::clearStaticCache();
        $db = $this->with(self::SECRET);
        $other = $damaged === 'users' ? 'orders' : 'users';
        $this->assertSame($other === 'users' ? 4 : 2, $db->count($other), 'the other database works under the old key');
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () use ($db, $damaged) { $db->find($damaged, 0); }), 'the damage stays visible, never an empty database');
        $this->assertSame(\noneDB::KEY_NOT_REGISTERED, $this->code(function () { $this->with(self::NEW)->find('users', 0); }));
    }

    /** @test */
    public function aFailedUndoKeepsTheStoreClosedUntilAnAbortFinishesIt(): void
    {
        $this->seed();
        $store = $this->record('STORE');
        $old = [$this->locator('users', self::SECRET), $this->locator('orders', self::SECRET)];
        $this->setRenameFn(function ($from, $to) use ($old) {
            return in_array(basename($to, '.ndb4'), $old, true) ? false : rename($from, $to); // moving back fails
        });
        try {
            [$result, $damaged] = $this->rotateWithAFailingVerification();
        } finally {
            $this->setRenameFn(null);
        }
        $this->assertFalse($result['success'], 'never reported as success or as "nothing changed"');
        $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $result['code'], (string)$result['error']);
        $this->assertFileExists($this->testDbDir . '.nonedb-maintenance/journal.json', 'the rotation stays open');
        $this->assertSame($store, $this->record('STORE'));
        \noneDB::clearStaticCache();
        foreach ([self::SECRET, self::NEW] as $key) {
            $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $this->code(function () use ($key, $damaged) { $this->with($key)->find($damaged, 0); }), 'the store stays closed');
            \noneDB::clearStaticCache();
        }
        $abort = \noneDB::migrateKey($this->testDbDir, self::SECRET, self::NEW, ['abort' => true]);
        $this->assertTrue($abort['success'], (string)$abort['error']);
        $this->assertFileDoesNotExist($this->testDbDir . '.nonedb-maintenance/journal.json');
        \noneDB::clearStaticCache();
        $other = $damaged === 'users' ? 'orders' : 'users';
        $this->assertSame($other === 'users' ? 4 : 2, $this->with(self::SECRET)->count($other));
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () use ($damaged) { $this->with(self::SECRET)->find($damaged, 0); }));
    }

    /** @test */
    public function aBusyDatabaseStopsMaintenanceBeforeAnythingIsWritten(): void
    {
        $this->seed();
        $lock = $this->testDbDir . $this->locator('orders', self::SECRET) . '.ndb4/LOCK';
        $fp = fopen($lock, 'c+');
        $this->assertTrue(flock($fp, LOCK_SH));
        \noneDB::clearStaticCache();
        $before = $this->snapshot($this->testDbDir);
        $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, self::NEW);
        flock($fp, LOCK_UN);
        fclose($fp);
        $this->assertFalse($result['success']);
        $this->assertSame(\noneDB::MAINTENANCE_BUSY, $result['code']);
        $this->assertSame($before, $this->snapshot($this->testDbDir), '§9.2 step 2: no journal, no epoch, no m');
        $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, self::NEW);
        $this->assertTrue($result['success'], (string)$result['error']);
        $this->assertRotated('after the lock was released');
    }

    /**
     * @test docs/OPERATIONS.md: on Windows no directory with an open file in it can move, and a process keeps the handle of
     * the LOCK it used. The rotation stops before its commit and says so, the old key stays out, and it finishes once that
     * process has ended
     */
    public function aRotationWaitsForAProcessThatKeepsItsLockHandle(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('a directory with an open file in it moves everywhere else');
        }
        $this->seed();
        $go = $this->testDbDir . '../rot-go';
        $ready = $this->testDbDir . '../rot-ready';
        @unlink($go);
        @unlink($ready);
        $proc = proc_open([PHP_BINARY, $this->childScript('
            $db = new noneDB(["secretKey" => ' . var_export(self::SECRET, true) . ', "dbDir" => ' . var_export($this->testDbDir, true) . ']);
            $db->find("users", 0);
            file_put_contents(' . var_export($ready, true) . ', "1");
            $deadline = microtime(true) + 30;
            while (!is_file(' . var_export($go, true) . ') && microtime(true) < $deadline) {
                usleep(5000);
            }
        ')], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        try {
            $deadline = microtime(true) + 10;
            while (!is_file($ready) && microtime(true) < $deadline) {
                usleep(5000);
            }
            $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, self::NEW);
            $this->assertFalse($result['success'], 'the directory can not move under the process');
            $this->assertStringContainsString('run migrateKey() again', (string)$result['error']);
        } finally {
            file_put_contents($go, '1');
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            proc_close($proc);
            @unlink($go);
            @unlink($ready);
        }
        \noneDB::clearStaticCache();
        $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, self::NEW);
        $this->assertTrue($result['success'], 'the process has ended: ' . (string)($result['error'] ?? ''));
        $this->assertCount(4, $this->with(self::NEW)->find('users', 0));
    }

    /** @test */
    public function aRunningProcessNeverSeesAnEmptyDatabaseDuringARotation(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('a running process keeps its lock handle: aRotationWaitsForAProcessThatKeepsItsLockHandle');
        }
        $this->seed();
        $go = $this->testDbDir . '../rot-go';
        $ready = $this->testDbDir . '../rot-ready';
        @unlink($go);
        @unlink($ready);
        $proc = proc_open([PHP_BINARY, $this->childScript('
            $db = new noneDB(["secretKey" => ' . var_export(self::SECRET, true) . ', "dbDir" => ' . var_export($this->testDbDir, true) . ', "shardSize" => 3, "lockTimeout" => 0.2]);
            $seen = [];
            $db->find("users", 0);                                  // the store is open in this process
            file_put_contents(' . var_export($ready, true) . ', "1");
            $deadline = microtime(true) + 15;
            $after = 0;
            while (microtime(true) < $deadline && $after < 30) {
                $done = is_file(' . var_export($go, true) . ');
                foreach (["users" => function () use ($db) { return count($db->find("users", 0)); },
                          "missing" => function () use ($db) { return count($db->find("missing", 0)); },
                          "write" => function () use ($db) { return $db->insert("users", ["n" => 99])["n"] ?? -1; },
                          "create" => function () use ($db) { return $db->insert("newdb", ["z" => 1])["n"] ?? -1; }] as $op => $fn) {
                    try { $r = $fn(); $seen[] = [$op, "ok", $r, $done]; } catch (RuntimeException $e) { $seen[] = [$op, $e->getCode(), null, $done]; }
                }
                if ($done) { $after++; }
                usleep(5000);
            }
            echo json_encode($seen);
        ')], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $deadline = microtime(true) + 10;
        while (!is_file($ready) && microtime(true) < $deadline) {
            usleep(5000);
        }
        // The child keeps working on its locks while the rotation runs: retry while it is busy
        $result = ['success' => false];
        for ($i = 0; $i < 200 && !$result['success']; $i++) {
            $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, self::NEW);
            if (!$result['success']) {
                $this->assertSame(\noneDB::MAINTENANCE_BUSY, $result['code'], (string)$result['error']);
                usleep(2000);
            }
        }
        $this->assertTrue($result['success'], 'the rotation got its locks');
        file_put_contents($go, '1');
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        @unlink($go);
        @unlink($ready);
        $seen = json_decode($out, true);
        $this->assertIsArray($seen, $out . $err);
        $users = 4;
        $statuses = array_map(function ($row) { return $row[3] ? 'after' : 'before'; }, array_filter($seen, function ($row) { return $row[1] === 'ok'; }));
        $this->assertContains('before', $statuses, 'the child worked before the rotation');
        $this->assertNotEmpty(array_filter($seen, function ($row) { return $row[3] && $row[1] === \noneDB::KEY_NOT_REGISTERED; }), 'and was refused after it');
        foreach ($seen as [$op, $status, $value, $done]) {
            if ($status === 'ok') {
                $this->assertFalse($done, "$op succeeded after the rotation committed: the old key must be refused");
                if ($op === 'users') {
                    $this->assertGreaterThanOrEqual($users, $value, 'never an empty or shrunken database');
                }
                if ($op === 'write' && $value === 1) {
                    $users++;
                }
            } else {
                $this->assertContains($status, [\noneDB::MAINTENANCE_IN_PROGRESS, \noneDB::KEY_NOT_REGISTERED, \noneDB::LOCK_FAILED], "$op: only these errors");
            }
        }
        $this->assertDirectoryDoesNotExist($this->testDbDir . $this->locator('users', self::SECRET) . '.ndb4', 'no old-key directory created afterwards');
        $this->assertDirectoryDoesNotExist($this->testDbDir . $this->locator('missing', self::SECRET) . '.ndb4');
        \noneDB::clearStaticCache();
        $this->assertSame($users, count($this->with(self::NEW)->find('users', 0)), 'every acknowledged write is there');
    }

    /** @test */
    public function registerKeyAddsAKeyThatOnlyARegisteredKeyCanAdd(): void
    {
        $this->seed();
        $result = \noneDB::registerKey($this->testDbDir, self::SECRET, self::NEW);
        $this->assertTrue($result['success'], (string)$result['error']);
        $keys = [$this->keyCheck(self::SECRET), $this->keyCheck(self::NEW)];
        sort($keys);
        $this->assertSame($keys, $this->record('STORE')['keys']);
        $this->assertSame($this->record('STORE'), $this->record('STORE.2'));
        \noneDB::clearStaticCache();
        $this->with(self::NEW)->insert('theirs', ['t' => 1]);
        $this->assertSame([['t' => 1, 'key' => 0]], $this->with(self::NEW)->find('theirs', 0));
        $this->assertSame([1, 2, 3, 4], array_column($this->with(self::SECRET)->find('users', 0), 'n'));
        $this->assertSame(['orders', 'users'], $this->with(self::SECRET)->getDBs(), 'every key sees only its databases');
        $this->assertSame(['theirs'], $this->with(self::NEW)->getDBs());
        $before = $this->snapshot($this->testDbDir);
        $refused = \noneDB::registerKey($this->testDbDir, self::THIRD, 'fourth_secret_key_for_maintenance');
        $this->assertFalse($refused['success']);
        $this->assertSame(\noneDB::KEY_NOT_REGISTERED, $refused['code'], 'a key that is not registered adds nothing');
        $this->assertSame($before, $this->snapshot($this->testDbDir));
    }

    /** @test */
    public function everyStoreChangeKilledBetweenItsCopiesIsCompletedByARerun(): void
    {
        $ops = [
            'register' => ['noneDB::registerKey(%s, %s, %s)', [self::SECRET, self::NEW]],
            'rotate' => ['noneDB::migrateKey(%s, %s, %s)', [self::SECRET, self::NEW]],
        ];
        foreach ($ops as $name => [$call, $args]) {
            foreach ([1, 2] as $copy) {
                $context = "$name, killed before copy $copy";
                $this->cleanTestDirectory();
                $this->seed();
                $out = $this->runChild('
                    $n = 0;
                    noneDB\Tests\Support\Testing::setFaultHook(function ($p) use (&$n) { if ($p === "store:write" && ++$n === ' . $copy . ') { exit(0); } });
                    ' . sprintf($call, var_export($this->testDbDir, true), var_export($args[0], true), var_export($args[1], true)) . ';
                    echo "not reached";
                ');
                $this->assertSame('', $out, $context);
                \noneDB::clearStaticCache();
                $this->assertFileExists($this->testDbDir . '.nonedb-maintenance/journal.json', "$context: COMMITTED journal open");
                $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $this->code(function () { $this->with(self::SECRET)->find('users', 0); }), $context);
                \noneDB::clearStaticCache();
                $rerun = $name === 'register' ? \noneDB::registerKey($this->testDbDir, self::SECRET, self::NEW)
                    : \noneDB::migrateKey($this->testDbDir, self::SECRET, self::NEW);
                $this->assertTrue($rerun['success'], "$context: " . $rerun['error']);
                $this->assertSame($this->record('STORE'), $this->record('STORE.2'), "$context: identical copies");
                $this->assertContains($this->keyCheck(self::NEW), $this->record('STORE')['keys'], $context);
                $this->assertFileDoesNotExist($this->testDbDir . '.nonedb-maintenance/journal.json', $context);
            }
        }
    }

    /** @test */
    public function aRepairKilledAtEveryStepIsCompletedByARerun(): void
    {
        foreach ([['repair:committed', 1], ['store:write', 1], ['store:write', 2], ['maintenance:store-written', 1], ['maintenance:close', 1]] as [$point, $hit]) {
            $context = "$point #$hit";
            $this->cleanTestDirectory();
            $this->seed();
            $manifest = \noneDB::registerKey($this->testDbDir, self::SECRET, self::THIRD)['storeManifest'];
            file_put_contents($this->testDbDir . 'STORE', 'x');
            file_put_contents($this->testDbDir . 'STORE.2', 'y');
            \noneDB::clearStaticCache();
            $out = $this->runChild('
                $n = 0;
                noneDB\Tests\Support\Testing::setFaultHook(function ($p) use (&$n) { if ($p === ' . var_export($point, true) . ' && ++$n === ' . $hit . ') { exit(0); } });
                noneDB::repairStore(' . var_export($this->testDbDir, true) . ', [' . var_export(self::SECRET, true) . '], ["manifest" => ' . var_export($manifest, true) . ']);
                echo "not reached";
            ');
            $this->assertSame('', $out, $context);
            \noneDB::clearStaticCache();
            $this->assertFileExists($this->testDbDir . '.nonedb-maintenance/journal.json', "$context: committed, not closed");
            $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $this->code(function () { $this->with(self::SECRET)->find('users', 0); }), $context);
            \noneDB::clearStaticCache();
            // The re-run applies the committed journal; the copies on disk do not change its outcome
            $result = \noneDB::repairStore($this->testDbDir, [self::SECRET], ['confirmManualRebuild' => true]);
            $this->assertTrue($result['success'], "$context: " . $result['error']);
            $this->assertSame('manifest', $result['mode'], "$context: the committed mode, not the re-run's options");
            $this->assertTrue($result['complete'], $context);
            $this->assertSame($manifest['keys'], $this->record('STORE')['keys'], $context);
            $this->assertSame($this->record('STORE'), $this->record('STORE.2'), $context);
            $this->assertFileDoesNotExist($this->testDbDir . '.nonedb-maintenance/journal.json', $context);
            \noneDB::clearStaticCache();
            $this->assertSame([1, 2, 3, 4], array_column($this->with(self::SECRET)->find('users', 0), 'n'), $context);
        }
    }

    /** The temp files of atomic replacements in the store directory and .nonedb-maintenance (STORAGE_FORMAT.md §2) */
    private function storeTemps(): array
    {
        return array_values(array_filter(array_keys($this->snapshot($this->testDbDir)), function ($path) {
            return strpos($path, '.ndb4/') === false && preg_match('/\.tmp[0-9a-f]{8}$/', $path) === 1;
        }));
    }

    /**
     * @test STORAGE_FORMAT.md §2: "A temp file left by a crash is never read. It is removed by the next exclusive
     * session that owns the directory." A maintenance killed between writing a store file's temp and renaming it
     * (the journal, STORE.2, STORE) leaves that temp; the re-run (or an abort) removes it
     */
    public function aTempFileACrashedMaintenanceLeftIsRemovedByTheNextOne(): void
    {
        $cases = [
            'register, journal' => ['noneDB::registerKey(%s, %s, %s)', 1, '.nonedb-maintenance/journal.json'],
            'register, STORE.2' => ['noneDB::registerKey(%s, %s, %s)', 2, 'STORE.2'],
            'register, STORE' => ['noneDB::registerKey(%s, %s, %s)', 3, 'STORE'],
            'rotate, its second journal state, then aborted' => ['noneDB::migrateKey(%s, %s, %s)', 2, '.nonedb-maintenance/journal.json'],
        ];
        foreach ($cases as $context => [$call, $hit, $file]) {
            $this->cleanTestDirectory();
            $this->seed();
            $this->assertSame([], $this->storeTemps(), "$context: none before");
            $out = $this->runChild('
                $n = 0;
                noneDB\Tests\Support\Testing::setFaultHook(function ($p) use (&$n) { if ($p === "store:renaming" && ++$n === ' . $hit . ') { exit(0); } });
                ' . sprintf($call, var_export($this->testDbDir, true), var_export(self::SECRET, true), var_export(self::NEW, true)) . ';
                echo "not reached";
            ');
            $this->assertSame('', $out, $context);
            $temps = $this->storeTemps();
            $this->assertCount(1, $temps, "$context: the crash left the temp of the file it was writing");
            $this->assertMatchesRegularExpression('/^' . preg_quote($file, '/') . '\.tmp[0-9a-f]{8}$/', $temps[0], $context);
            \noneDB::clearStaticCache();
            $rerun = strpos($context, 'aborted') !== false ? \noneDB::migrateKey($this->testDbDir, self::SECRET, self::NEW, ['abort' => true])
                : \noneDB::registerKey($this->testDbDir, self::SECRET, self::NEW);
            $this->assertTrue($rerun['success'], "$context: " . $rerun['error']);
            $this->assertSame([], $this->storeTemps(), "$context: removed by the next maintenance");
            $this->assertFileDoesNotExist($this->testDbDir . '.nonedb-maintenance/journal.json', $context);
            \noneDB::clearStaticCache();
            $this->assertSame([1, 2, 3, 4], array_column($this->with(self::SECRET)->find('users', 0), 'n'), $context);
        }
    }

    /** @test */
    public function aFailingSecondCopyIsNeverReportedAsSuccess(): void
    {
        $this->seed();
        $n = 0;
        $this->setFaultHook(function ($p) use (&$n) {
            if ($p === 'store:write' && ++$n === 2) {
                throw new \RuntimeException('injected: disk full');
            }
        });
        $result = \noneDB::registerKey($this->testDbDir, self::SECRET, self::NEW);
        $this->setFaultHook(null);
        $this->assertFalse($result['success'], 'committed but not completed is not success');
        $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $result['code']);
        \noneDB::clearStaticCache();
        $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $this->code(function () { $this->with(self::SECRET)->find('users', 0); }), 'the store stays closed');
        \noneDB::clearStaticCache();
        $this->assertTrue(\noneDB::registerKey($this->testDbDir, self::SECRET, self::NEW)['success'], 'the rerun completes it');
        $this->assertSame($this->record('STORE'), $this->record('STORE.2'));
        \noneDB::clearStaticCache();
        $this->assertSame([1, 2, 3, 4], array_column($this->with(self::SECRET)->find('users', 0), 'n'));
    }

    /** @test */
    public function repairStoreFromAVerifiedManifestIsComplete(): void
    {
        foreach (['the result array' => 'array', 'a saved STORE copy' => 'file'] as $case => $form) {
            $this->cleanTestDirectory();
            $this->seed();
            $registered = \noneDB::registerKey($this->testDbDir, self::SECRET, self::THIRD); // a key without databases
            $this->assertSame($this->record('STORE'), $registered['storeManifest'], $case);
            $manifest = $registered['storeManifest'];
            if ($form === 'file') {
                $manifest = $this->testDbDir . '../store-manifest.json';
                copy($this->testDbDir . 'STORE', $manifest);
            }
            file_put_contents($this->testDbDir . 'STORE', 'x');
            file_put_contents($this->testDbDir . 'STORE.2', 'y');
            \noneDB::clearStaticCache();
            $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () { $this->with(self::SECRET)->find('users', 0); }), $case);
            \noneDB::clearStaticCache();
            $result = \noneDB::repairStore($this->testDbDir, [self::SECRET], ['manifest' => $manifest]);
            if ($form === 'file') {
                @unlink($manifest);
            }
            $this->assertTrue($result['success'], "$case: " . $result['error']);
            $this->assertSame('manifest', $result['mode'], $case);
            $this->assertTrue($result['complete'], $case);
            $store = $this->record('STORE');
            $this->assertSame($registered['storeManifest']['keys'], $store['keys'], "$case: the key without databases is back");
            $this->assertSame($registered['storeManifest']['storeId'], $store['storeId'], "$case: the same store");
            $this->assertSame($registered['storeManifest']['seq'] + 1, $store['seq'], $case);
            $this->assertSame($store, $this->record('STORE.2'), $case);
            $this->assertSame($store, $result['storeManifest'], $case);
            $this->assertStringContainsString('"store-restored-from-manifest"', file_get_contents($this->testDbDir . '.nonedb-maintenance/log.jsonl'), $case);
            \noneDB::clearStaticCache();
            $this->assertSame([1, 2, 3, 4], array_column($this->with(self::SECRET)->find('users', 0), 'n'), $case);
            $this->with(self::THIRD)->insert('t', ['x' => 1]); // still registered
            $this->assertSame(1, $this->with(self::THIRD)->count('t'), $case);
        }
    }

    /** @test */
    public function repairStoreWithoutManifestNeedsAConfirmedManualRebuild(): void
    {
        $this->seed();
        \noneDB::registerKey($this->testDbDir, self::SECRET, self::THIRD);
        file_put_contents($this->testDbDir . 'STORE', 'x');
        file_put_contents($this->testDbDir . 'STORE.2', 'y');
        \noneDB::clearStaticCache();
        $before = $this->snapshot($this->testDbDir);
        $refused = \noneDB::repairStore($this->testDbDir, [self::SECRET]);
        $this->assertFalse($refused['success']);
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $refused['code']);
        $this->assertSame($before, $this->snapshot($this->testDbDir), 'nothing rebuilt without confirmation');
        $result = \noneDB::repairStore($this->testDbDir, [self::SECRET], ['confirmManualRebuild' => true]);
        $this->assertTrue($result['success'], (string)$result['error']);
        $this->assertSame('manual', $result['mode']);
        $this->assertFalse($result['complete'], 'never reported as a guaranteed restore');
        $this->assertSame([$this->keyCheck(self::SECRET)], $this->record('STORE')['keys'], 'the key without databases is lost, as stated');
        $this->assertStringContainsString('"manual-store-rebuild"', file_get_contents($this->testDbDir . '.nonedb-maintenance/log.jsonl'));
        \noneDB::clearStaticCache();
        $this->assertSame([1, 2, 3, 4], array_column($this->with(self::SECRET)->find('users', 0), 'n'));
    }

    /** @test */
    public function repairStoreRefusesWhenADatabaseBelongsToNoGivenKey(): void
    {
        $this->seed();
        \noneDB::registerKey($this->testDbDir, self::SECRET, self::NEW);
        \noneDB::clearStaticCache();
        $this->with(self::NEW)->insert('theirs', ['t' => 1]);
        file_put_contents($this->testDbDir . 'STORE', 'x');
        file_put_contents($this->testDbDir . 'STORE.2', 'y');
        \noneDB::clearStaticCache();
        $before = $this->snapshot($this->testDbDir);
        foreach ([[], ['confirmManualRebuild' => true]] as $options) {
            $result = \noneDB::repairStore($this->testDbDir, [self::SECRET], $options);
            $this->assertFalse($result['success']);
            $this->assertSame(\noneDB::FORMAT_DAMAGED, $result['code']);
            $this->assertStringContainsString($this->locator('theirs', self::NEW) . '.ndb4', (string)$result['error'], 'the unattributed database is named');
            $this->assertSame($before, $this->snapshot($this->testDbDir));
        }
        $result = \noneDB::repairStore($this->testDbDir, [self::SECRET, self::NEW], ['confirmManualRebuild' => true]);
        $this->assertTrue($result['success'], (string)$result['error']);
        $keys = [$this->keyCheck(self::SECRET), $this->keyCheck(self::NEW)];
        sort($keys);
        $this->assertSame($keys, $this->record('STORE')['keys']);
    }

    /** @test */
    public function repairStoreIsOnlyForTwoInvalidCopiesAndRejectsAForgedManifest(): void
    {
        $this->seed();
        $manifest = \noneDB::registerKey($this->testDbDir, self::SECRET, self::THIRD)['storeManifest'];
        file_put_contents($this->testDbDir . 'STORE', 'x'); // STORE.2 still valid: repaired automatically
        $before = $this->snapshot($this->testDbDir);
        $result = \noneDB::repairStore($this->testDbDir, [self::SECRET], ['confirmManualRebuild' => true]);
        $this->assertFalse($result['success']);
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $result['code']);
        $this->assertSame($before, $this->snapshot($this->testDbDir), 'refused before anything is written');
        file_put_contents($this->testDbDir . 'STORE.2', 'y');
        $tampered = $manifest;
        $tampered['keys'][] = $this->keyCheck('evil_key_that_was_never_registered');
        sort($tampered['keys']);
        $reordered = $manifest;
        unset($reordered['checksum']);
        $reordered = ['checksum' => $manifest['checksum']] + $reordered;
        $forgedFile = $this->testDbDir . '../forged.json';
        file_put_contents($forgedFile, '{"magic":"NONEDB4","kind":"store","storageFormat":4,"storeId":"x","seq":1,"keys":["' . $this->keyCheck('evil') . '"],"checksum":"' . str_repeat('0', 64) . '"}' . "\n");
        $before = $this->snapshot($this->testDbDir);
        foreach (['a key added, old checksum' => $tampered, 'checksum not last' => $reordered, 'a file with a wrong checksum' => $forgedFile,
                  'a missing file' => $this->testDbDir . '../nothing.json', 'not a record' => ['keys' => []]] as $case => $source) {
            $result = \noneDB::repairStore($this->testDbDir, [self::SECRET], ['manifest' => $source]);
            $this->assertFalse($result['success'], $case);
            $this->assertSame(\noneDB::FORMAT_DAMAGED, $result['code'], $case);
            $this->assertSame($before, $this->snapshot($this->testDbDir), "$case: nothing written");
        }
        @unlink($forgedFile);
        $this->assertTrue(\noneDB::repairStore($this->testDbDir, [self::SECRET], ['manifest' => $manifest])['success'], 'the genuine manifest works');
    }
}
