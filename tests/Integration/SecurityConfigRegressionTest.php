<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

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

    /** A STORE copy of a store (STORAGE_FORMAT.md §3.1), decoded, after checking its encoding and checksum (§2) */
    private function storeRecord(string $dir, string $copy = 'STORE'): array
    {
        $raw = (string)file_get_contents($dir . $copy);
        $this->assertSame($raw, $this->sealRecord(json_decode($raw)), $copy . ': encoding and checksum');
        return json_decode($raw, true);
    }

    /** Entries of a directory (without . and ..), sorted */
    private function entries(string $dir): array
    {
        return $this->sorted(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    private function sorted(array $names): array
    {
        $names = array_values($names);
        sort($names, SORT_STRING);
        return $names;
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
        // The store does not list the wrong key: KEY_NOT_REGISTERED (STORAGE_FORMAT.md §3.2), no data, no file
        $before = $this->entries($dir);
        $wrong = $this->runChild('try { echo json_encode((new \noneDB(["secretKey" => "WRONG", "dbDir" => ' . var_export($dir, true) . ', "autoCreateDB" => false]))->find("users", 0)); }
            catch (\RuntimeException $e) { echo $e->getCode() === \noneDB::KEY_NOT_REGISTERED ? "KEY_NOT_REGISTERED" : get_class($e) . $e->getCode(); }');
        $this->assertSame('KEY_NOT_REGISTERED', $wrong);
        $this->assertSame($before, $this->entries($dir), 'nothing created');
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
        $this->runChild($mk('WRONG') . '->find("users", 0);');
        $right = $this->runChild('echo json_encode(' . $mk('alpha-key') . '->find("users", 0));');
        $this->assertSame('[{"pin":"1234","key":0}]', $right);
        $this->runChild($mk('alpha-key') . '->insert("users", ["pin" => "5678"]);');
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
        // Each store holds its own databases, under its key's locators (STORAGE_FORMAT.md §1, §3)
        $this->assertFileExists("$base/B/" . $this->locatorOf('users', 'tenantB-key') . '.ndb4/FORMAT');
        $this->assertSame($this->sorted(['.htaccess', $this->locatorOf('orders', 'tenantB-key') . '.ndb4', $this->locatorOf('users', 'tenantB-key') . '.ndb4',
            'STORE', 'STORE.2', 'STORE.LOCK', 'index.html']), $this->entries("$base/B"), 'nothing of tenant A in B\'s store');
        $this->assertSame([$this->keyCheckOf('tenantB-key')], $this->storeRecord("$base/B/")['keys']);
        // No name of A's databases anywhere in B's store (file names are locators, not names)
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$base/B", \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $this->assertStringNotContainsString('payroll', $file->getPathname());
            $this->assertStringNotContainsString('payroll', (string)file_get_contents($file->getPathname()), $file->getPathname());
        }
        $this->assertDirectoryExists("$base/A/" . $this->locatorOf('payroll-2026-ceo-bonus', 'tenantA-key') . '.ndb4');
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
        $this->assertSame([$p . '/db/' . $this->locatorOf('orders', 's3cr3t-long-random') . '.ndb4'], glob("$p/db/*.ndb4"));
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
        $this->assertSame([$p . '/data/' . $this->locatorOf('orders', 's3cr3t-long-random') . '.ndb4'], glob("$p/data/*.ndb4"));
        $this->assertSame([], glob("$p/public/data") ?: []);
        $this->assertSame('ok', trim(shell_exec('cd ' . escapeshellarg($p) . " && $php bin/array.php 2>&1")));
        $this->assertSame([$p . '/bin/store/' . $this->locatorOf('a', 'other-key-123') . '.ndb4'], glob("$p/bin/store/*.ndb4"));
        $this->assertSame([], glob("$p/store") ?: []);
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

    /**
     * @test an instance is freed when its last reference goes: its engine (kernel and services) holds no cycle
     * the cycle collector would have to find, and a new instance builds only the services it uses
     */
    public function instancesAreFreedAtOnce(): void
    {
        $config = ['secretKey' => 'free-key', 'dbDir' => $this->testDbDir];
        (new \noneDB($config))->insert('fr', ['a' => 1]);
        gc_collect_cycles();
        $live = \noneDB\Tests\Support\Testing::liveInstances();
        $runs = gc_status()['runs'];
        $memory = memory_get_usage();
        for ($i = 0; $i < 300; $i++) {
            $db = new \noneDB($config);
            $db->find('fr', 0);
            $copy = clone $db;
            $copy->insert('fr', ['a' => 2]);
            unset($db, $copy);
            $this->assertSame($live, \noneDB\Tests\Support\Testing::liveInstances(), "round $i");
        }
        $this->assertSame($runs, gc_status()['runs'], 'nothing left for the cycle collector');
        $this->assertLessThan(256 * 1024, memory_get_usage() - $memory, 'no memory kept per instance');
        $this->assertCount(301, (new \noneDB($config))->find('fr', 0));
    }

    /** @test a clone is an independent instance: its own settings, the same data and shared caches */
    public function clonesHaveTheirOwnSettings(): void
    {
        $db = new \noneDB(['secretKey' => 'clone-key', 'dbDir' => $this->testDbDir, 'shardingEnabled' => false]);
        $db->insert('cs', ['a' => 1]);
        $copy = clone $db;
        $copy->enableBuffering(true);
        $copy->setBufferCountLimit(50);
        $this->assertFalse($db->isBufferingEnabled(), 'the original keeps its settings');
        $this->assertTrue($copy->isBufferingEnabled());
        $this->assertSame(10000, $db->getBufferInfo('cs')['countLimit']);
        $this->assertSame(50, $copy->getBufferInfo('cs')['countLimit']);
        $copy->insert('cs', ['a' => 2]);
        $copy->flush('cs');
        $db->insert('cs', ['a' => 3]);
        $this->assertSame([1, 2, 3], array_column($db->find('cs', 0), 'a'), 'one database');
        $this->assertSame([1, 2, 3], array_column($copy->find('cs', 0), 'a'));
        $this->assertSame(\noneDB\Tests\Support\Testing::getOf($db, 'indexCache'), \noneDB\Tests\Support\Testing::getOf($copy, 'indexCache'), 'caches stay shared');
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
        $databases = glob("$dir/db/*.ndb4");
        $this->assertCount(1, $databases, 'data next to noneDB.php, not in the working directory');
        $this->assertSame('t', json_decode((string)file_get_contents($databases[0] . '/FORMAT'), true)['name']);
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
        // Everything but LOCK goes; LOCK and the directory stay (STORAGE_FORMAT.md §8.2)
        $this->assertSame(['LOCK'], $this->dbFiles('dd'));
        $this->assertSame(['LOCK'], $this->entries($this->dbPath('dd', 'home')));
        $this->assertSame([], $db->find('dd', 0));
        $this->assertFalse($db->dropDB('dd')['success']);
        $db->insert('dd', ['fresh' => true]);
        $this->assertSame([['fresh' => true, 'key' => 0]], $db->find('dd', 0));
    }

    /** Databases of the migrateKey tests: a plain one with a field index, a spatial one, a sharded one */
    private function keyRotationStore(string $dir): void
    {
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
    }

    /**
     * Everything the rotation must leave (STORAGE_FORMAT.md §9.3): the databases under the new key's locators
     * with their contents, nothing under the old ones, STORE listing the new key instead of the old one, the
     * old key refused with KEY_NOT_REGISTERED
     */
    private function assertRotated(string $dir, array $storeBefore, array $result, string $label): void
    {
        $this->assertSame(['code', 'databases', 'error', 'storeManifest', 'success'], $this->sortedKeys($result), $label);
        $this->assertTrue($result['success'], $label . ': ' . json_encode($result));
        $this->assertNull($result['error'], $label);
        $this->assertNull($result['code'], $label);
        $databases = $result['databases'];
        sort($databases);
        $this->assertSame(['geo', 'plain', 'sharded'], $databases, $label);

        foreach (['plain', 'geo', 'sharded'] as $name) {
            $this->assertDirectoryDoesNotExist($dir . $this->locatorOf($name, 'old-key-abc') . '.ndb4', "$label: nothing of $name left under the old key");
            $this->assertFileExists($dir . $this->locatorOf($name, 'brand-new-key-0123456789abcdef') . '.ndb4/FORMAT', "$label: $name renamed");
        }
        $this->assertSame([], preg_grep('/\.ndb4$/', array_diff($this->entries($dir), array_map(function ($name) {
            return $this->locatorOf($name, 'brand-new-key-0123456789abcdef') . '.ndb4';
        }, ['plain', 'geo', 'sharded']))), "$label: no other database directory");
        $this->assertFileDoesNotExist($dir . '.nonedb-maintenance/journal.json', "$label: journal closed");

        // STORE rewritten: both copies, the new keyCheck instead of the old one, seq one higher (§3.1)
        $store = $this->storeRecord($dir, 'STORE');
        $this->assertSame($store, $this->storeRecord($dir, 'STORE.2'), "$label: both copies identical");
        $this->assertSame([$this->keyCheckOf('brand-new-key-0123456789abcdef')], $store['keys'], $label);
        $this->assertSame($storeBefore['seq'] + 1, $store['seq'], $label);
        $this->assertSame($storeBefore['storeId'], $store['storeId'], $label);
        $this->assertSame($store, $result['storeManifest'], "$label: the new STORE record is returned (§9.4)");

        \noneDB::clearStaticCache();
        $new = new \noneDB(['secretKey' => 'brand-new-key-0123456789abcdef', 'dbDir' => $dir, 'autoCreateDB' => false]);
        $this->assertSame([1], array_column($new->find('plain', ['city' => 'B']), 'key'), $label);
        $this->assertSame(['city'], $new->getFieldIndexes('plain')['fields'], $label);
        $this->assertSame(11, $new->find('sharded', ['key' => 11])[0]['i'], $label);
        $this->assertSame(range(0, 11), array_column($new->find('sharded', 0), 'i'), $label);
        $this->assertCount(1, $new->withinDistance('geo', 'loc', 29.0, 41.0, 100), $label);
        $this->assertSame([], preg_grep('/ m( |$)/', [trim((string)file_get_contents($dir . $this->locatorOf('plain', 'brand-new-key-0123456789abcdef') . '.ndb4/LOCK'))]), "$label: m cleared");
        \noneDB::clearStaticCache();
        try {
            (new \noneDB(['secretKey' => 'old-key-abc', 'dbDir' => $dir, 'autoCreateDB' => false]))->find('plain', 0);
            $this->fail("$label: the old key must be refused");
        } catch (\RuntimeException $e) {
            $this->assertSame(\noneDB::KEY_NOT_REGISTERED, $e->getCode(), "$label: " . $e->getMessage());
        }
    }

    private function sortedKeys(array $result): array
    {
        $keys = array_keys($result);
        sort($keys);
        return $keys;
    }

    /** @test S-04 migrateKey: the database directories move to the new key's locators, STORE lists the new key */
    public function migrateKeyMovesEverything(): void
    {
        $dir = $this->scratch('mk') . '/';
        $this->keyRotationStore($dir);
        $storeBefore = $this->storeRecord($dir);
        $this->assertSame([$this->keyCheckOf('old-key-abc')], $storeBefore['keys'], 'set up');
        $formats = [];
        foreach (['plain', 'geo', 'sharded'] as $name) {
            $formats[$name] = file_get_contents($dir . $this->locatorOf($name, 'old-key-abc') . '.ndb4/FORMAT');
        }

        $r = \noneDB::migrateKey($dir, 'old-key-abc', 'brand-new-key-0123456789abcdef');
        $this->assertRotated($dir, $storeBefore, $r, 'rotated');
        foreach ($formats as $name => $format) {
            // FORMAT holds no key material: the directory is renamed, its identity unchanged (§4.1, §9.3)
            $this->assertSame($format, file_get_contents($dir . $this->locatorOf($name, 'brand-new-key-0123456789abcdef') . '.ndb4/FORMAT'), $name);
        }

        // A placeholder key is refused, and the old key is not registered any more
        $placeholder = \noneDB::migrateKey($dir, 'brand-new-key-0123456789abcdef', 'CHANGE_THIS_TO_A_SECURE_RANDOM_STRING');
        $this->assertFalse($placeholder['success']);
        $again = \noneDB::migrateKey($dir, 'old-key-abc', 'yet-another-key-0123456789');
        $this->assertFalse($again['success']);
        $this->assertSame(\noneDB::KEY_NOT_REGISTERED, $again['code']);
        $this->assertSame($r['storeManifest'], $this->storeRecord($dir), 'nothing changed by the refused runs');
    }

    /**
     * @test S-04 migrateKey: a rotation interrupted at any step (process killed) keeps the store closed
     * (MAINTENANCE_IN_PROGRESS, STORAGE_FORMAT.md §9.1) and is finished by the next run (§9.2 "On re-run")
     */
    public function anInterruptedKeyRotationIsFinishedByTheNextRun(): void
    {
        foreach (['rotate:marked', 'rotate:renamed', 'rotate:committed', 'maintenance:store-written'] as $point) {
            $dir = $this->scratch('mkr') . '/';
            $this->keyRotationStore($dir);
            $storeBefore = $this->storeRecord($dir);
            $out = $this->runChild('
                noneDB\Tests\Support\Testing::setFaultHook(function ($p) { if ($p === ' . var_export($point, true) . ') { exit(0); } });
                \noneDB::migrateKey(' . var_export($dir, true) . ', "old-key-abc", "brand-new-key-0123456789abcdef");
                echo "not reached";
            ');
            $this->assertSame('', $out, $point);
            $this->assertFileExists($dir . '.nonedb-maintenance/journal.json', "$point: the journal stays open");

            // Until the rotation is finished, neither key opens the store
            foreach (['old-key-abc', 'brand-new-key-0123456789abcdef'] as $key) {
                \noneDB::clearStaticCache();
                try {
                    (new \noneDB(['secretKey' => $key, 'dbDir' => $dir, 'autoCreateDB' => false]))->find('plain', 0);
                    $this->fail("$point: the store must stay closed for $key");
                } catch (\RuntimeException $e) {
                    $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $e->getCode(), "$point, $key: " . $e->getMessage());
                }
            }

            \noneDB::clearStaticCache();
            $r = \noneDB::migrateKey($dir, 'old-key-abc', 'brand-new-key-0123456789abcdef');
            $this->assertRotated($dir, $storeBefore, $r, $point);
        }
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
        // Start the child with a known limit: the environment's own php.ini may already say -1
        // (GitHub's setup-php does), which says nothing about what including noneDB.php does
        $script = $this->childScript('echo ini_get("memory_limit");'); // noneDB.php is required first
        $out = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=123M ' . escapeshellarg($script) . ' 2>&1'));
        $this->assertSame('123M', $out);
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
