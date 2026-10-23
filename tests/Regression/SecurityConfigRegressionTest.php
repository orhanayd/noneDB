<?php

namespace noneDB\Tests\Regression;

/**
 * secretKey, configuration and naming: S-01..S-16, Y-23, Y-25, O-19, O-20, D-01..D-03, T-06
 */
class SecurityConfigRegressionTest extends RegressionTestCase
{
    private function scratch(string $name): string
    {
        $dir = sys_get_temp_dir() . '/nonedb_sec_' . $name . '_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        return $dir;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/nonedb_sec_*') ?: [] as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        parent::tearDown();
    }

    /** @test S-01 T-06 */
    public function wrongKeyCannotReadData(): void
    {
        $dir = $this->scratch('s01') . '/';
        $this->runChild('(new \noneDB(["secretKey" => "alpha-key", "dbDir" => ' . var_export($dir, true) . ']))->insert("users", ["pin" => "1234"]);');
        $wrong = $this->runChild('echo json_encode((new \noneDB(["secretKey" => "WRONG", "dbDir" => ' . var_export($dir, true) . ', "autoCreateDB" => false]))->find("users", 0));');
        $this->assertSame('false', $wrong);
        foreach (['null', "''", 'false'] as $bad) {
            $out = $this->runChild('try { new \noneDB(["secretKey" => ' . $bad . ', "dbDir" => ' . var_export($dir, true) . ']); echo "ACCEPTED"; } catch (\InvalidArgumentException $e) { echo "REJECTED"; }');
            $this->assertSame('REJECTED', $out, "key $bad");
        }
    }

    /** @test S-02 */
    public function wrongKeyRunDoesNotPoisonTheCache(): void
    {
        $dir = $this->scratch('s02') . '/';
        $mk = function ($key) use ($dir) { return '(new \noneDB(["secretKey" => "' . $key . '", "dbDir" => ' . var_export($dir, true) . ']))'; };
        $this->runChild($mk('alpha-key') . '->insert("users", ["pin" => "1234"]);');
        foreach (glob($dir . '.nonedb_hash_cache*') as $f) {
            unlink($f);
        }
        $this->runChild($mk('WRONG') . '->find("users", 0);');
        $right = $this->runChild('echo json_encode(' . $mk('alpha-key') . '->find("users", 0));');
        $this->assertSame('[{"pin":"1234","key":0}]', $right);
        $this->runChild($mk('alpha-key') . '->insert("users", ["pin" => "5678"]);');
        foreach (glob($dir . '.nonedb_hash_cache*') as $f) {
            unlink($f);
        }
        $this->assertSame('[{"pin":"1234","key":0},{"pin":"5678","key":1}]', $this->runChild('echo json_encode(' . $mk('alpha-key') . '->find("users", 0));'));
    }

    /** @test S-03 S-14 */
    public function tenantsInOneProcessStayIsolated(): void
    {
        $base = $this->scratch('s03');
        $out = $this->runChild('
            $B = new \noneDB(["secretKey" => "tenantB-key", "dbDir" => ' . var_export("$base/B/", true) . ']);
            $B->insert("orders", ["o" => 1]);
            $A = new \noneDB(["secretKey" => "tenantA-key", "dbDir" => ' . var_export("$base/A/", true) . ']);
            $A->insert("payroll-2026-ceo-bonus", ["x" => 1]);
            $A->insert("users", ["u" => "a"]);
            $B->insert("users", ["u" => "b"]);
            echo json_encode($B->find("users", 0)), json_encode($A->find("users", 0));');
        $this->assertSame('[{"u":"b","key":0}][{"u":"a","key":0}]', $out);
        $expectedB = hash_pbkdf2('sha256', 'users', 'tenantB-key', 1000, 20);
        $this->assertFileExists("$base/B/$expectedB-users.nonedb");
        $cache = '';
        foreach (glob("$base/B/.nonedb_hash_cache_*") as $f) {
            $cache .= file_get_contents($f);
            $this->assertSame('0600', substr(sprintf('%o', fileperms($f)), -4));
        }
        $this->assertStringNotContainsString('payroll', $cache);
        $this->assertFileDoesNotExist("$base/B/.nonedb_hash_cache");
    }

    /** @test S-04 */
    public function invalidKeysAreRejected(): void
    {
        foreach ([null, false, '', '   ', 12345, ['a'], 'CHANGE_THIS_TO_A_SECURE_RANDOM_STRING'] as $key) {
            try {
                new \noneDB(['secretKey' => $key, 'dbDir' => $this->testDbDir]);
                $this->fail('accepted ' . var_export($key, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('secretKey', $e->getMessage());
            }
        }
        $this->assertInstanceOf(\noneDB::class, new \noneDB(['secretKey' => 'short', 'dbDir' => $this->testDbDir]));
    }

    /** @test S-05 S-06 S-16 Y-23 D-02 D-03 */
    public function configFileResolution(): void
    {
        $p = $this->scratch('proj');
        mkdir("$p/public");
        mkdir("$p/bin");
        copy(__DIR__ . '/../../noneDB.php', "$p/noneDB.php");
        file_put_contents("$p/.nonedb", json_encode(['secretKey' => 's3cr3t-long-random', 'dbDir' => './db/']));
        file_put_contents("$p/public/index.php", '<?php require __DIR__."/../noneDB.php"; $db = new noneDB(); $db->insert("orders", ["from" => "web"]); echo json_encode($db->find("orders", 0));');
        file_put_contents("$p/bin/cron.php", '<?php require __DIR__."/../noneDB.php"; echo json_encode((new noneDB())->find("orders", 0));');
        file_put_contents("$p/bin/mixed.php", '<?php require __DIR__."/../noneDB.php"; echo json_encode((new noneDB(["dbDir" => __DIR__."/../db/"]))->find("orders", 0));');
        $php = escapeshellarg(PHP_BINARY);
        $web = trim(shell_exec("$php " . escapeshellarg("$p/public/index.php") . ' 2>&1'));
        $cron = trim(shell_exec("$php " . escapeshellarg("$p/bin/cron.php") . ' 2>&1'));
        $fromParent = trim(shell_exec('cd ' . escapeshellarg($p) . " && $php bin/cron.php 2>&1"));
        $mixed = trim(shell_exec("$php " . escapeshellarg("$p/bin/mixed.php") . ' 2>&1'));
        $this->assertSame('[{"from":"web","key":0}]', $web);
        $this->assertSame($web, $cron, 'web and cron share one database');
        $this->assertSame($web, $fromParent, 'relative script path, other cwd');
        $this->assertSame($web, $mixed, 'array config merges over the file (secretKey from file)');
        $this->assertCount(1, glob("$p/db/*-orders.nonedb"));
        $this->assertFileExists("$p/db/.htaccess");
        $this->assertFileExists("$p/db/index.html");
        $this->assertSame([], glob("$p/public/db") ?: []);
    }

    /** @test S-05 S-16: a relative dbDir without "./" follows the same rule */
    public function plainRelativeDbDirFollowsTheConfigFile(): void
    {
        $p = $this->scratch('plain');
        mkdir("$p/public");
        mkdir("$p/bin");
        copy(__DIR__ . '/../../noneDB.php', "$p/noneDB.php");
        file_put_contents("$p/.nonedb", json_encode(['secretKey' => 's3cr3t-long-random', 'dbDir' => 'data']));
        file_put_contents("$p/public/index.php", '<?php require __DIR__."/../noneDB.php"; $db = new noneDB(); $db->insert("orders", ["from" => "web"]); echo json_encode($db->find("orders", 0));');
        file_put_contents("$p/bin/cron.php", '<?php require __DIR__."/../noneDB.php"; echo json_encode((new noneDB())->find("orders", 0));');
        // Array config: relative to the entry script (bin/), not to the working directory
        file_put_contents("$p/bin/array.php", '<?php require __DIR__."/../noneDB.php"; $db = new noneDB(["secretKey" => "other-key-123", "dbDir" => "store/"]); $db->insert("a", ["x" => 1]); echo "ok";');
        $php = escapeshellarg(PHP_BINARY);
        $web = trim(shell_exec('cd ' . escapeshellarg("$p/public") . " && $php index.php 2>&1"));
        $cron = trim(shell_exec('cd ' . escapeshellarg(sys_get_temp_dir()) . " && $php " . escapeshellarg("$p/bin/cron.php") . ' 2>&1'));
        $this->assertSame('[{"from":"web","key":0}]', $web);
        $this->assertSame($web, $cron, 'a cwd elsewhere does not matter');
        $this->assertCount(1, glob("$p/data/*-orders.nonedb"));
        $this->assertSame([], glob("$p/public/data") ?: []);
        $this->assertSame('ok', trim(shell_exec('cd ' . escapeshellarg($p) . " && $php bin/array.php 2>&1")));
        $this->assertCount(1, glob("$p/bin/store/*-a.nonedb"));
        $this->assertSame([], glob("$p/store") ?: []);
    }

    /** @test S-05: data in a pre-3.2 location keeps being used (with a deprecation notice) */
    public function oldDbDirLocationsKeepWorking(): void
    {
        foreach (['./old/' => 'script', 'old/' => 'cwd'] as $dbDir => $oldBase) {
            $p = realpath($this->scratch('legacy'));
            mkdir("$p/app/web", 0777, true);
            mkdir("$p/cwd");
            copy(__DIR__ . '/../../noneDB.php', "$p/app/noneDB.php");
            file_put_contents("$p/app/.nonedb", json_encode(['secretKey' => 's3cr3t-long-random', 'dbDir' => $dbDir]));
            $script = '<?php require __DIR__."/../noneDB.php"; set_error_handler(function($n, $m){ echo "NOTICE:", $m, "\n"; return true; }, E_USER_DEPRECATED);'
                . ' $db = new noneDB(); $db->insert("t", ["v" => 1]); echo json_encode(array_column($db->find("t", 0), "v"));';
            file_put_contents("$p/app/web/run.php", $script);
            // Where v3.1 put the data: next to the entry script ("./old/") or in the working directory ("old/")
            $old = $oldBase === 'script' ? "$p/app/web/old" : "$p/cwd/old";
            mkdir($old);
            $out = trim(shell_exec('cd ' . escapeshellarg("$p/cwd") . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$p/app/web/run.php") . ' 2>&1'));
            $this->assertStringContainsString('NOTICE:noneDB: dbDir', $out, $dbDir);
            $this->assertStringContainsString("using the old location $old", $out, $dbDir);
            $this->assertStringEndsWith('[1]', $out, $dbDir);
            $this->assertCount(1, glob("$old/*-t.nonedb"), $dbDir);
            $this->assertDirectoryDoesNotExist("$p/app/old", $dbDir);
        }
    }

    /** @test S-04 S-11: an explicitly given invalid secretKey is rejected whatever the environment */
    public function givenInvalidKeyIsRejectedWithoutConfigFileAndInDevMode(): void
    {
        $dir = $this->scratch('nokey');
        copy(__DIR__ . '/../../noneDB.php', "$dir/noneDB.php"); // no .nonedb anywhere near it
        $script = '<?php %s require __DIR__ . "/noneDB.php";
            foreach ([null, false, ""] as $k) {
                try { new noneDB(["secretKey" => $k, "dbDir" => __DIR__ . "/db/"]); echo "ACCEPTED "; }
                catch (InvalidArgumentException $e) { echo "REJECTED "; }
                catch (Throwable $e) { echo get_class($e), " "; }
            }
            try { new noneDB(["dbDir" => __DIR__ . "/db/"]); echo "NOKEY:", noneDB::isDevMode() ? "dev" : "file"; }
            catch (Throwable $e) { echo "NOKEY:", get_class($e); }';
        // Without config file: an invalid key is reported as such (not as a missing config file).
        // Dev mode: an invalid key is not silently replaced by the predictable dev mode key.
        foreach (['' => 'NOKEY:RuntimeException', 'define("NONEDB_DEV_MODE", true);' => 'NOKEY:dev'] as $prefix => $noKey) {
            file_put_contents("$dir/run.php", sprintf($script, $prefix));
            $out = trim((string)shell_exec('cd ' . escapeshellarg($dir) . ' && env -u NONEDB_DEV_MODE ' . escapeshellarg(PHP_BINARY) . ' run.php 2>&1'));
            $this->assertSame("REJECTED REJECTED REJECTED $noKey", $out, $prefix === '' ? 'no config file' : 'dev mode');
        }
    }

    /** @test S-07 */
    public function invalidDatabaseNamesAreRejected(): void
    {
        $db = $this->db();
        $db->insert('alan', ['tip' => 'alan']);
        foreach (['çalışan', 'my_db', '../../x', "a\0b", 'a/b', ''] as $name) {
            try {
                $db->insert($name, ['x' => 1]);
                $this->fail('accepted ' . $name);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('invalid database name', $e->getMessage());
            }
        }
        $this->assertCount(1, $db->find('alan', 0));
        $this->assertSame(["Bob's list", 'a b-c', '0'], array_map(function ($n) use ($db) { $db->insert($n, ['ok' => 1]); return $db->find($n, 0) ? $n : null; }, ["Bob's list", 'a b-c', '0']));
    }

    /** @test S-09 */
    public function secretNeverAppearsInDebugOutput(): void
    {
        $db = new \noneDB(['secretKey' => 'TOP-SECRET-123', 'dbDir' => $this->testDbDir]);
        $q = $db->query('x');
        ob_start();
        var_dump($db, $q);
        $dump = ob_get_clean() . print_r($db, true) . print_r($q, true) . var_export($db, true) . var_export($q, true);
        $this->assertStringNotContainsString('TOP-SECRET-123', $dump);
        $this->expectException(\LogicException::class);
        serialize($db);
    }

    /** @test S-09 */
    public function clonesKeepTheirKey(): void
    {
        $db = new \noneDB(['secretKey' => 'clone-key', 'dbDir' => $this->testDbDir]);
        $db->insert('cl', ['a' => 1]);
        $copy = clone $db;
        unset($db);
        gc_collect_cycles();
        $this->assertCount(1, $copy->find('cl', 0));
    }

    /** @test S-11 Y-25 */
    public function devModeInstancesAreConfigured(): void
    {
        $dir = $this->scratch('dev');
        copy(__DIR__ . '/../../noneDB.php', "$dir/noneDB.php");
        file_put_contents("$dir/run.php", '<?php define("NONEDB_DEV_MODE", true); require __DIR__."/noneDB.php";
            $a = new noneDB(); $b = new noneDB(); $b->insert("t", ["a" => 1]); echo count($a->find("t", 0));');
        $out = trim(shell_exec('cd ' . escapeshellarg(sys_get_temp_dir()) . ' && env -u NONEDB_DEV_MODE ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/run.php") . ' 2>&1'));
        $this->assertSame('1', $out);
        $this->assertCount(1, glob("$dir/db/*-t.nonedb"), 'data next to noneDB.php, not in the working directory');
    }

    /** @test S-12 */
    public function readsNeverCreateFiles(): void
    {
        $db = $this->db();
        $before = glob($this->testDbDir . '*');
        $this->assertSame([], $db->find('ghost', 0));
        $this->assertSame(0, $db->count('ghost'));
        $this->assertSame([], $db->query('ghost')->where(['a' => 1])->get());
        $this->assertSame([], $db->withinDistance('ghost', 'loc', 0, 0, 10));
        $this->assertFalse($this->db(['autoCreateDB' => false])->find('ghost', 0));
        $this->assertFalse($db->getDBs('ghost'));
        $this->assertFalse($db->getShardInfo('ghost'));
        $this->assertSame(['fields' => [], 'sharded' => false], $db->getFieldIndexes('ghost'));
        $this->assertSame($before, glob($this->testDbDir . '*'));
    }

    /** @test S-15 */
    public function dropDbRemovesEveryFile(): void
    {
        $db = $this->db(['shardSize' => 3]);
        $db->insert('dd', [['i' => 1, 'loc' => ['type' => 'Point', 'coordinates' => [1, 1]]], ['i' => 2], ['i' => 3], ['i' => 4]]);
        $db->createFieldIndex('dd', 'i');
        $db->createSpatialIndex('dd', 'loc');
        $r = $db->dropDB('dd');
        $this->assertTrue($r['success']);
        $hash = hash_pbkdf2('sha256', 'dd', self::SECRET, 1000, 20);
        $left = array_filter(glob($this->testDbDir . $hash . '*'), function ($f) { return substr($f, -5) !== '.lock'; });
        $this->assertSame([], array_values($left));
        $this->assertSame([], $db->find('dd', 0));
        $this->assertFalse($db->dropDB('dd')['success']);
        $db->insert('dd', ['fresh' => true]);
        $this->assertSame([['fresh' => true, 'key' => 0]], $db->find('dd', 0));
    }

    /** @test S-04 migrateKey */
    public function migrateKeyMovesEverything(): void
    {
        $dir = $this->scratch('mk') . '/';
        $old = new \noneDB(['secretKey' => 'old-key-abc', 'dbDir' => $dir, 'shardSize' => 5]);
        $old->insert('plain', [['city' => 'A'], ['city' => 'B']]);
        $old->createFieldIndex('plain', 'city');
        $old->insert('geo', ['loc' => ['type' => 'Point', 'coordinates' => [29.0, 41.0]]]);
        $old->createSpatialIndex('geo', 'loc');
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['i' => $i];
        }
        $old->insert('sharded', $rows);
        unset($old);
        \noneDB::clearStaticCache();

        $r = \noneDB::migrateKey($dir, 'old-key-abc', 'brand-new-key-0123456789abcdef');
        sort($r['databases']);
        $this->assertTrue($r['success']);
        $this->assertSame(['geo', 'plain', 'sharded'], $r['databases']);

        $new = new \noneDB(['secretKey' => 'brand-new-key-0123456789abcdef', 'dbDir' => $dir, 'autoCreateDB' => false]);
        $this->assertSame([1], array_column($new->find('plain', ['city' => 'B']), 'key'));
        $this->assertSame(['city'], $new->getFieldIndexes('plain')['fields']);
        $this->assertSame(11, $new->find('sharded', ['key' => 11])[0]['i']);
        $this->assertCount(1, $new->withinDistance('geo', 'loc', 29.0, 41.0, 100));
        \noneDB::clearStaticCache();
        $this->assertFalse((new \noneDB(['secretKey' => 'old-key-abc', 'dbDir' => $dir, 'autoCreateDB' => false]))->find('plain', 0));
        $this->assertFalse(\noneDB::migrateKey($dir, 'old-key-abc', 'CHANGE_THIS_TO_A_SECURE_RANDOM_STRING')['success']);
        foreach (['plain', 'geo', 'sharded'] as $name) {
            $this->assertSame([], glob($dir . hash_pbkdf2('sha256', $name, 'old-key-abc', 1000, 20) . '*'), "nothing of $name left under the old key");
        }
    }

    /** @test S-04: databases from before v3.2 (no lock files) leave nothing behind under the old key */
    public function migrateKeyOfPreLockDatabases(): void
    {
        $dir = $this->scratch('mk31') . '/';
        $old = new \noneDB(['secretKey' => 'old-key-abc', 'dbDir' => $dir, 'shardSize' => 5]);
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['i' => $i];
        }
        $old->insert('users', $rows);
        unset($old);
        \noneDB::clearStaticCache(); // closes kept lock handles
        foreach (glob($dir . '*.nonedb.lock') as $lock) {
            $this->assertTrue(unlink($lock)); // pre-3.2 versions had no lock files
        }
        $oldHash = hash_pbkdf2('sha256', 'users', 'old-key-abc', 1000, 20);
        $before = count(glob($dir . $oldHash . '*'));

        $r = \noneDB::migrateKey($dir, 'old-key-abc', 'brand-new-key-0123456789abcdef');
        $this->assertTrue($r['success']);
        $this->assertSame($before, $r['files']);
        $this->assertSame([], glob($dir . $oldHash . '*'), 'no lock file (or anything else) left under the old key');
        $new = new \noneDB(['secretKey' => 'brand-new-key-0123456789abcdef', 'dbDir' => $dir, 'autoCreateDB' => false]);
        $this->assertSame(range(0, 11), array_column($new->find('users', 0), 'i'));
    }

    /** @test O-19 */
    public function instancesAreReleased(): void
    {
        $out = $this->runChild('$m = memory_get_usage(); for ($i = 0; $i < 20000; $i++) { $x = ' . $this->childDb() . '; } unset($x); gc_collect_cycles(); echo memory_get_usage() - $m;');
        $this->assertLessThan(1024 * 1024, (int)$out);
    }

    /** @test O-20 */
    public function includingDoesNotChangeMemoryLimit(): void
    {
        $this->assertNotSame('-1', $this->runChild('echo ini_get("memory_limit");'));
    }

    /** @test D-01 */
    public function invalidConfigValuesAreRejected(): void
    {
        foreach (['shardSize' => 0, 'lockTimeout' => -1, 'lockRetryDelay' => 0, 'autoCompactThreshold' => 0] as $key => $value) {
            try {
                $this->db([$key => $value]);
                $this->fail("accepted $key");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    /** @test S-10 */
    public function noFilePathsInWarnings(): void
    {
        $out = $this->runChild('ini_set("display_errors", "1"); $db = ' . $this->childDb() . '; $db->insert("w", ["a" => 1]); $db->find("w", ["key" => 99]); $db->getDBs("w"); $db->delete("w", ["a" => 2]); echo "done";');
        $this->assertSame('done', $out);
    }
}
