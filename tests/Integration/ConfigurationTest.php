<?php

namespace noneDB\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Tests for configuration system and dev mode
 */
class ConfigurationTest extends TestCase
{
    use \noneDB\Tests\Support\Platform;

    private $testDbDir;
    private $hadScript = false;
    private $savedScript = null;
    /** @var string[] */
    private $scratchDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDbDir = TEST_DB_DIR;
        $this->hadScript = array_key_exists('SCRIPT_FILENAME', $_SERVER);
        $this->savedScript = $_SERVER['SCRIPT_FILENAME'] ?? null;

        // Clean test directory
        $this->cleanTestDirectory();

        // Clear all caches
        \noneDB::clearStaticCache();
        \noneDB::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->hadScript) {
            $_SERVER['SCRIPT_FILENAME'] = $this->savedScript;
        } else {
            unset($_SERVER['SCRIPT_FILENAME']);
        }
        $this->cleanTestDirectory();
        \noneDB::clearStaticCache();
        \noneDB::clearConfigCache();
        foreach ($this->scratchDirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        parent::tearDown();
    }

    /** A private value of an instance (the settings have no getters) */
    private function prop(\noneDB $db, string $name)
    {
        $property = new \noneDB\Tests\InternalValue($name);
        return $property->getValue($db);
    }

    private function scratch(string $name): string
    {
        $dir = sys_get_temp_dir() . '/nonedb_cfgtest_' . $name . '_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $dir = realpath($dir);
        $this->scratchDirs[] = $dir;
        return $dir;
    }

    /** Make $dir the entry script's directory: the first place .nonedb is looked for (docs/CONFIGURATION.md) */
    private function entryScriptIn(string $dir): void
    {
        file_put_contents($dir . '/entry.php', "<?php\n");
        $_SERVER['SCRIPT_FILENAME'] = $dir . '/entry.php';
        \noneDB::clearConfigCache();
    }

    /** Run PHP code in a clean child process with a private copy of noneDB.php and no config file anywhere */
    private function runIsolated(string $code, string $env = ''): string
    {
        $dir = $this->scratch('iso');
        copy(__DIR__ . '/../../noneDB.php', $dir . '/noneDB.php');
        file_put_contents($dir . '/run.php', "<?php\n" . $code);
        return trim((string)shell_exec(self::cd($dir) . 'env -u NONEDB_DEV_MODE ' . $env . ' '
            . escapeshellarg(PHP_BINARY) . ' run.php 2>&1'));
    }

    private function cleanTestDirectory(): void
    {
        \noneDB::clearStaticCache(); // closes the kept lock handles
        if (!file_exists($this->testDbDir)) {
            mkdir($this->testDbDir, 0777, true);
            return;
        }

        // Everything goes: the store files and the database directories
        foreach (array_diff(scandir($this->testDbDir) ?: [], ['.', '..']) as $entry) {
            exec('rm -rf ' . escapeshellarg(rtrim($this->testDbDir, '/') . '/' . $entry));
        }
        clearstatcache(true);
    }

    /** Data file of a single-layout database (docs/STORAGE_FORMAT.md §1, §4, §5.1), computed independently */
    private function dataFile(string $dbDir, string $name, string $key): string
    {
        return $dbDir . substr(hash_hmac('sha256', 'nonedb4/db/' . $name, $key), 0, 32) . '.ndb4/generations/1/segments/main.jsonl';
    }

    /**
     * @test
     */
    public function programmaticConfigWorks(): void
    {
        $config = [
            'secretKey' => 'test_key_123',
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => true
        ];

        $db = new \noneDB($config);

        // Should work without errors
        $result = $db->insert('config-test', ['name' => 'test']);
        $this->assertEquals(1, $result['n']);

        $found = $db->find('config-test', 0);
        $this->assertCount(1, $found);
    }

    /**
     * @test
     */
    public function programmaticConfigWithAllOptions(): void
    {
        $config = [
            'secretKey' => 'full-config-test',
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => true,
            'shardingEnabled' => false,
            'shardSize' => 5000,
            'autoCompactThreshold' => 0.5,
            'lockTimeout' => 10,
            'lockRetryDelay' => 20000
        ];

        $db = new \noneDB($config);

        // Every option took effect
        $this->assertTrue($this->prop($db, 'autoCreateDB'));
        $this->assertFalse($db->isShardingEnabled());
        $this->assertSame(5000, $db->getShardSize());
        $this->assertSame(0.5, $this->prop($db, 'autoCompactThreshold'));
        $this->assertSame(10.0, $this->prop($db, 'lockTimeout'));
        $this->assertSame(20000, $this->prop($db, 'lockRetryDelay'));
        $this->assertSame($this->testDbDir, $db->__debugInfo()['dbDir']);

        $result = $db->insert('full-config-test', ['data' => 'test']);
        $this->assertSame(['n' => 1], $result);
        // shardingEnabled false: never sharded, whatever the size
        $this->assertFalse($db->getShardInfo('full-config-test')['sharded']);
    }

    /**
     * @test
     */
    public function configExistsChecksMultiplePaths(): void
    {
        // docs/CONFIGURATION.md "Where .nonedb is looked for": 1. the running script's directory, 2. noneDB.php's
        // directory, 3. the Composer project root, 4. the working directory
        $script = $this->scratch('script');
        $this->entryScriptIn($script);
        file_put_contents($script . '/.nonedb', json_encode(['secretKey' => 'script-dir-key-0123', 'dbDir' => 'here']));
        $this->assertTrue(\noneDB::configExists());
        $this->assertSame(self::resolvedDir($script . '/here/'), (new \noneDB())->__debugInfo()['dbDir'], 'the file next to the entry script is used');

        // In a process where no file exists anywhere: false; then one in noneDB.php's directory, then in the cwd
        $out = $this->runIsolated('require __DIR__ . "/noneDB.php";
            $cwd = __DIR__ . "/cwd"; mkdir($cwd); chdir($cwd);
            $r = [noneDB::configExists()];
            file_put_contents($cwd . "/.nonedb", "{}"); noneDB::clearConfigCache(); $r[] = noneDB::configExists();
            unlink($cwd . "/.nonedb");
            file_put_contents(__DIR__ . "/.nonedb", "{}"); noneDB::clearConfigCache(); $r[] = noneDB::configExists();
            echo json_encode($r);');
        $this->assertSame('[false,true,true]', $out);
    }

    /**
     * @test
     */
    public function getConfigTemplateReturnsPathToExampleFile(): void
    {
        $templatePath = \noneDB::getConfigTemplate();

        // Should return path to .nonedb.example
        $this->assertIsString($templatePath);
        $this->assertStringEndsWith('.nonedb.example', $templatePath);
        $this->assertFileExists($templatePath);

        // Verify the template content is valid JSON with expected keys
        $content = file_get_contents($templatePath);
        $template = json_decode($content, true);

        $this->assertIsArray($template);
        $this->assertArrayHasKey('secretKey', $template);
        $this->assertArrayHasKey('dbDir', $template);
        $this->assertArrayHasKey('autoCreateDB', $template);
        $this->assertArrayHasKey('shardingEnabled', $template);
        $this->assertArrayHasKey('shardSize', $template);
        $this->assertArrayHasKey('autoCompactThreshold', $template);
        $this->assertArrayHasKey('lockTimeout', $template);
        $this->assertArrayHasKey('lockRetryDelay', $template);

        // "the .nonedb.example next to noneDB.php" (the library that runs: the test build)
        $this->assertSame(realpath(dirname(NONEDB_TEST_LIBRARY) . '/.nonedb.example'), realpath($templatePath));
        // Its key is a placeholder: "replace the placeholder secretKey (it is rejected)"
        try {
            new \noneDB(['dbDir' => $this->testDbDir] + $template);
            $this->fail('the template key must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('placeholder', $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function clearConfigCacheAllowsReload(): void
    {
        // The .nonedb file is read once per process; clearConfigCache() makes the next instance read it again
        $dir = $this->scratch('reload');
        $this->entryScriptIn($dir);
        file_put_contents($dir . '/.nonedb', json_encode(['secretKey' => 'first_key_0123', 'dbDir' => $this->testDbDir, 'shardSize' => 111]));
        $db1 = new \noneDB();
        $this->assertSame(111, $db1->getShardSize());
        $db1->insert('cache-test1', ['v' => 1]);

        file_put_contents($dir . '/.nonedb', json_encode(['secretKey' => 'second_key_0123', 'dbDir' => $this->testDbDir, 'shardSize' => 222]));
        $this->assertSame(111, (new \noneDB())->getShardSize(), 'still the cached file');
        // A second key uses the store once it is registered (docs/STORAGE_FORMAT.md §3.2, §9.3)
        \noneDB::clearStaticCache();
        $this->assertTrue(\noneDB::registerKey($this->testDbDir, 'first_key_0123', 'second_key_0123')['success']);

        \noneDB::clearConfigCache();
        $db2 = new \noneDB();
        $this->assertSame(222, $db2->getShardSize(), 'read again');
        $db2->insert('cache-test2', ['v' => 2]);

        // Two keys: each instance sees only its own databases
        $this->assertSame([['v' => 1, 'key' => 0]], $db1->find('cache-test1', 0));
        $this->assertSame([['v' => 2, 'key' => 0]], $db2->find('cache-test2', 0));
        $this->assertSame(['cache-test1'], $db1->getDBs(false));
        $this->assertSame(['cache-test2'], $db2->getDBs(false));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function devModeViaSetDevModeWorks(): void
    {
        // Separate process: setDevMode() defines a constant that can't be undefined, so it would
        // switch every later test of the run into dev mode
        // Clear any existing config
        \noneDB::clearConfigCache();

        // Enable dev mode via static method
        \noneDB::setDevMode(true);

        $this->assertTrue(defined('NONEDB_DEV_MODE'));
        $this->assertTrue(NONEDB_DEV_MODE, 'the boolean true');
        $this->assertTrue(\noneDB::isDevMode());
        // A second call is harmless (the constant can't be defined twice)
        \noneDB::setDevMode(true);
        $this->assertTrue(\noneDB::isDevMode());

        // Without any config file, setDevMode(true) lets new noneDB() work with the dev mode defaults
        $out = $this->runIsolated('require __DIR__ . "/noneDB.php"; noneDB::setDevMode(true); $db = new noneDB();
            $db->insert("t", ["a" => 1]); echo json_encode([$db->__debugInfo()["devMode"], $db->__debugInfo()["dbDir"] === __DIR__ . "/db/", count($db->find("t", 0))]);');
        $this->assertSame('[true,true,1]', $out);
    }

    /**
     * @test
     */
    public function devModeViaEnvironmentVariable(): void
    {
        $originalValue = getenv('NONEDB_DEV_MODE');
        putenv('NONEDB_DEV_MODE=1');
        try {
            $this->assertTrue(\noneDB::isDevMode());
        } finally {
            putenv($originalValue === false ? 'NONEDB_DEV_MODE' : 'NONEDB_DEV_MODE=' . $originalValue);
        }

        // Without a config file the variable enables dev mode for new noneDB(); without it there is none
        $code = 'require __DIR__ . "/noneDB.php"; try { $db = new noneDB(); echo $db->__debugInfo()["devMode"] ? "dev" : "nodev"; }'
            . ' catch (RuntimeException $e) { echo "no config"; }';
        $this->assertSame('dev', $this->runIsolated($code, 'NONEDB_DEV_MODE=1'));
        $this->assertSame('no config', $this->runIsolated($code));
    }

    /**
     * @test
     */
    public function devModeViaEnvironmentVariableTrue(): void
    {
        $originalValue = getenv('NONEDB_DEV_MODE');
        putenv('NONEDB_DEV_MODE=true');
        try {
            $this->assertTrue(\noneDB::isDevMode());
        } finally {
            putenv($originalValue === false ? 'NONEDB_DEV_MODE' : 'NONEDB_DEV_MODE=' . $originalValue);
        }
        $code = 'require __DIR__ . "/noneDB.php"; try { $db = new noneDB(); echo $db->__debugInfo()["devMode"] ? "dev" : "nodev"; }'
            . ' catch (RuntimeException $e) { echo "no config"; }';
        $this->assertSame('dev', $this->runIsolated($code, 'NONEDB_DEV_MODE=true'));
        // Only "1" and "true" enable it
        $this->assertSame('no config', $this->runIsolated($code, 'NONEDB_DEV_MODE=yes'));
    }

    /**
     * @test
     */
    public function relativeDbDirIsResolved(): void
    {
        // docs/CONFIGURATION.md: "In a config array, a relative dbDir is relative to the entry script's directory"
        $dir = $this->scratch('relative');
        $this->entryScriptIn($dir);
        $config = [
            'secretKey' => 'relative_path_test',
            'dbDir' => './test_db/',
            'autoCreateDB' => true
        ];

        $db = new \noneDB($config);
        $this->assertSame(self::resolvedDir($dir . '/test_db/'), $db->__debugInfo()['dbDir']);

        $result = $db->insert('relative-test', ['data' => 'test']);
        $this->assertSame(['n' => 1], $result);
        $this->assertFileExists($this->dataFile($dir . '/test_db/', 'relative-test', 'relative_path_test'));
    }

    /**
     * @test
     */
    public function dbDirWithoutTrailingSlashGetsSlashAdded(): void
    {
        $config = [
            'secretKey' => 'trailing_slash_test',
            'dbDir' => rtrim($this->testDbDir, '/'), // no trailing slash
            'autoCreateDB' => true
        ];

        $db = new \noneDB($config);
        $this->assertSame($this->testDbDir, $db->__debugInfo()['dbDir'], 'slash added');

        $result = $db->insert('slash-test', ['data' => 'test']);
        $this->assertSame(['n' => 1], $result);
        // Inside the directory, not next to it ("test_db" + "{locator}.ndb4" would be a sibling)
        $this->assertFileExists($this->dataFile($this->testDbDir, 'slash-test', 'trailing_slash_test'));
    }

    /**
     * @test
     */
    public function multipleInstancesShareConfigCache(): void
    {
        // The config file is read once per process: every later instance gets the same configuration
        $dir = $this->scratch('shared');
        $this->entryScriptIn($dir);
        file_put_contents($dir . '/.nonedb', json_encode(['secretKey' => 'shared_cache_test', 'dbDir' => $this->testDbDir, 'shardSize' => 333]));
        $db1 = new \noneDB();
        unlink($dir . '/.nonedb'); // gone: the second instance still has the configuration
        $db2 = new \noneDB();
        $this->assertSame(333, $db2->getShardSize());
        $this->assertSame($this->testDbDir, $db2->__debugInfo()['dbDir']);

        $db1->insert('shared-test', ['from' => 'db1']);
        $db2->insert('shared-test', ['from' => 'db2']);

        // Same key and directory: both see all records, in insertion order with keys 0 and 1
        $expected = [['from' => 'db1', 'key' => 0], ['from' => 'db2', 'key' => 1]];
        $this->assertSame($expected, $db1->find('shared-test', 0));
        $this->assertSame($expected, $db2->find('shared-test', 0));
    }

    /**
     * @test CONFIGURATION.md "Settings": a key that is not a setting is an InvalidArgumentException that names it, in
     * the array and in a .nonedb file (a setting that differs only in letter case or by up to two letters is
     * suggested), so a misspelled `durability` can never mean performance mode; nothing is created
     */
    public function aKeyThatIsNotASettingIsRejected(): void
    {
        $base = ['secretKey' => 'strict-config-test', 'dbDir' => $this->testDbDir];
        $cases = [
            'durabilty' => ['durable', "unknown setting 'durabilty' (did you mean 'durability'?)"],
            'Durability' => ['durable', "unknown setting 'Durability' (did you mean 'durability'?)"],
            'DURABILITY' => ['durable', "unknown setting 'DURABILITY' (did you mean 'durability'?)"],
            'shardsize' => [100, "unknown setting 'shardsize' (did you mean 'shardSize'?)"],
            'lockTimout' => [1, "unknown setting 'lockTimout' (did you mean 'lockTimeout'?)"],
            'indexing' => [true, "unknown setting 'indexing';"],
            'foo' => [1, "unknown setting 'foo';"],
            'sync' => [false, "unknown setting 'sync';"],
            'autoShard' => [true, "unknown setting 'autoShard';"],
            0 => ['x', "unknown setting '0';"],
        ];
        foreach ($cases as $key => [$value, $message]) {
            try {
                new \noneDB($base + [$key => $value]);
                $this->fail("accepted '$key'");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), (string)$key);
                $this->assertStringContainsString('the settings are: secretKey, dbDir, autoCreateDB, shardingEnabled, shardSize, '
                    . 'autoCompactThreshold, lockTimeout, lockRetryDelay, bufferEnabled, fieldIndexEnabled, spatialIndexEnabled, '
                    . 'durability, cacheMemoryLimit, filePermissions, directoryPermissions', $e->getMessage(), (string)$key);
            }
        }
        $this->assertSame([], array_values(array_diff(scandir($this->testDbDir), ['.', '..'])), 'nothing created');

        // The same in a .nonedb file, which the message names
        $dir = $this->scratch('strict');
        file_put_contents($dir . '/.nonedb', json_encode(['secretKey' => 'strict-config-file', 'dbDir' => './db', 'durabilty' => 'durable']));
        $this->entryScriptIn($dir);
        try {
            new \noneDB();
            $this->fail('accepted the file');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("unknown setting 'durabilty' in $dir/.nonedb (did you mean 'durability'?)", $e->getMessage());
        }
        $this->assertFileDoesNotExist($dir . '/db');
    }

    /**
     * @test CONFIGURATION.md "Settings": a setting given as null has no valid value (it is not "the default"); every
     * documented setting is accepted with a valid value
     */
    public function aSettingGivenAsNullIsRejected(): void
    {
        $base = ['secretKey' => 'strict-null-test', 'dbDir' => $this->testDbDir];
        $settings = ['dbDir', 'autoCreateDB', 'shardingEnabled', 'shardSize', 'autoCompactThreshold', 'lockTimeout', 'lockRetryDelay',
            'bufferEnabled', 'fieldIndexEnabled', 'spatialIndexEnabled', 'durability', 'cacheMemoryLimit', 'filePermissions', 'directoryPermissions'];
        foreach ($settings as $setting) {
            try {
                new \noneDB([$setting => null] + $base);
                $this->fail("accepted $setting null");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("'$setting'", $e->getMessage(), $setting);
            }
        }
        foreach (['', '   '] as $dbDir) {
            try {
                new \noneDB(['dbDir' => $dbDir] + $base);
                $this->fail('accepted dbDir ' . var_export($dbDir, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("'dbDir' must be a directory path", $e->getMessage());
            }
        }

        $db = new \noneDB($base + ['autoCreateDB' => true, 'shardingEnabled' => true, 'shardSize' => 100, 'autoCompactThreshold' => 0.5,
            'lockTimeout' => 2.5, 'lockRetryDelay' => 5000, 'bufferEnabled' => false, 'fieldIndexEnabled' => true, 'spatialIndexEnabled' => true,
            'durability' => 'performance', 'cacheMemoryLimit' => 'auto', 'filePermissions' => '0640', 'directoryPermissions' => 0750]);
        $this->assertSame(['n' => 1], $db->insert('all-settings', ['a' => 1]));
    }

    /**
     * @test
     */
    public function invalidConfigArrayIsHandledGracefully(): void
    {
        $config = [
            'secretKey' => 'invalid_test',
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => 'true', // strings "true"/"false"/"1"/"0" are accepted for booleans
            'shardSize' => '5000',    // numeric strings work for numbers
        ];

        $db = new \noneDB($config);
        $this->assertTrue($this->prop($db, 'autoCreateDB'));
        $this->assertSame(5000, $db->getShardSize());
        $this->assertFalse($this->prop(new \noneDB(['autoCreateDB' => 'false'] + $config), 'autoCreateDB'), '"false" means false');

        $result = $db->insert('invalid-config-test', ['data' => 'test']);
        $this->assertSame(['n' => 1], $result);

        // Anything else is an error, never a silent cast (A-10)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'autoCreateDB' must be true or false");
        new \noneDB(['autoCreateDB' => 'yes'] + $config);
    }

    /**
     * @test
     */
    public function configWithOnlyRequiredFields(): void
    {
        $config = [
            'secretKey' => 'minimal_config',
            'dbDir' => $this->testDbDir
        ];

        $db = new \noneDB($config);
        $this->assertSame($this->testDbDir, $db->__debugInfo()['dbDir']);
        $this->assertFalse($db->__debugInfo()['devMode']);

        $result = $db->insert('minimal-test', ['data' => 'test']);
        $this->assertSame(['n' => 1], $result);
        $this->assertFileExists($this->dataFile($this->testDbDir, 'minimal-test', 'minimal_config'));

        // The defaults of everything else, in a process without any .nonedb file (docs/CONFIGURATION.md tables)
        $out = $this->runIsolated('require __DIR__ . "/noneDB.php";
            $db = new noneDB(["secretKey" => "minimal_config", "dbDir" => __DIR__ . "/db/"]);
            echo json_encode([$db->isShardingEnabled(), $db->getShardSize(), $db->isBufferingEnabled(), $db->__debugInfo()["autoCreateDB"]]);');
        $this->assertSame('[true,10000,false,true]', $out);
    }

    /**
     * @test
     */
    public function emptySecretKeyIsRejected(): void
    {
        // An empty secretKey would mean "no key at all", so it is rejected
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'secretKey' must be a non-empty string");
        new \noneDB([
            'secretKey' => '',
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => true
        ]);
    }

    /**
     * @test
     */
    public function missingConfigInProductionThrowsException(): void
    {
        // Run in a clean child process with a private copy of noneDB.php, so no .nonedb file
        // (project root, cwd, entry-script dir) and no NONEDB_DEV_MODE constant can be found.
        $tempDir = sys_get_temp_dir() . '/nonedb_noconfig_' . uniqid();
        mkdir($tempDir);
        copy(__DIR__ . '/../../noneDB.php', $tempDir . '/noneDB.php');
        file_put_contents($tempDir . '/run.php', '<?php
require __DIR__ . "/noneDB.php";
try { new noneDB(); echo "NO_EXCEPTION"; }
catch (RuntimeException $e) { echo get_class($e), ":", $e->getMessage(); }');

        $cmd = self::cd($tempDir) . 'env -u NONEDB_DEV_MODE '
            . escapeshellarg(PHP_BINARY) . ' run.php 2>&1';
        $output = shell_exec($cmd);

        @unlink($tempDir . '/run.php');
        @unlink($tempDir . '/noneDB.php');
        @rmdir($tempDir);

        $this->assertStringStartsWith('RuntimeException:', (string)$output);
        $this->assertStringContainsString('Configuration file not found', (string)$output);
        $this->assertStringContainsString('NONEDB_DEV_MODE', (string)$output, 'tells how to use dev mode');
    }
}
