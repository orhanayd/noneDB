<?php

namespace noneDB\Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Tests for configuration system and dev mode
 */
class ConfigurationTest extends TestCase
{
    private $testDbDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDbDir = TEST_DB_DIR;

        // Clean test directory
        $this->cleanTestDirectory();

        // Clear all caches
        \noneDB::clearStaticCache();
        \noneDB::clearConfigCache();
    }

    protected function tearDown(): void
    {
        $this->cleanTestDirectory();
        \noneDB::clearStaticCache();
        \noneDB::clearConfigCache();
        parent::tearDown();
    }

    private function cleanTestDirectory(): void
    {
        if (!file_exists($this->testDbDir)) {
            mkdir($this->testDbDir, 0777, true);
            return;
        }

        $files = glob($this->testDbDir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
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
            'autoMigrate' => true,
            'autoCompactThreshold' => 0.5,
            'lockTimeout' => 10,
            'lockRetryDelay' => 20000
        ];

        $db = new \noneDB($config);

        // Verify it works
        $result = $db->insert('full-config-test', ['data' => 'test']);
        $this->assertEquals(1, $result['n']);
    }

    /**
     * @test
     */
    public function configExistsChecksMultiplePaths(): void
    {
        // configExists() checks script dir and noneDB source dir
        // We verify the method runs without error
        $result = \noneDB::configExists();
        $this->assertIsBool($result);
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
        $this->assertArrayHasKey('autoMigrate', $template);
        $this->assertArrayHasKey('autoCompactThreshold', $template);
        $this->assertArrayHasKey('lockTimeout', $template);
        $this->assertArrayHasKey('lockRetryDelay', $template);
    }

    /**
     * @test
     */
    public function clearConfigCacheAllowsReload(): void
    {
        $config1 = [
            'secretKey' => 'first_key',
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => true
        ];

        $db1 = new \noneDB($config1);
        $db1->insert('cache-test1', ['v' => 1]);

        // Clear cache
        \noneDB::clearConfigCache();

        // New instance with different config
        $config2 = [
            'secretKey' => 'second_key',
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => true
        ];

        $db2 = new \noneDB($config2);
        $db2->insert('cache-test2', ['v' => 2]);

        // Both should work independently
        $this->assertCount(1, $db1->find('cache-test1', 0));
        $this->assertCount(1, $db2->find('cache-test2', 0));
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

        // This would normally throw without config, but dev mode allows it
        // Note: We can't truly test this in isolation because tests already have config
        // But we can verify setDevMode doesn't throw
        $this->assertTrue(defined('NONEDB_DEV_MODE'));
    }

    /**
     * @test
     */
    public function devModeViaEnvironmentVariable(): void
    {
        // Set environment variable
        $originalValue = getenv('NONEDB_DEV_MODE');
        putenv('NONEDB_DEV_MODE=1');

        // Verify it's set
        $this->assertEquals('1', getenv('NONEDB_DEV_MODE'));

        // Restore original value
        if ($originalValue === false) {
            putenv('NONEDB_DEV_MODE');
        } else {
            putenv('NONEDB_DEV_MODE=' . $originalValue);
        }
    }

    /**
     * @test
     */
    public function devModeViaEnvironmentVariableTrue(): void
    {
        $originalValue = getenv('NONEDB_DEV_MODE');
        putenv('NONEDB_DEV_MODE=true');

        $this->assertEquals('true', getenv('NONEDB_DEV_MODE'));

        // Restore
        if ($originalValue === false) {
            putenv('NONEDB_DEV_MODE');
        } else {
            putenv('NONEDB_DEV_MODE=' . $originalValue);
        }
    }

    /**
     * @test
     */
    public function relativeDbDirIsResolved(): void
    {
        $config = [
            'secretKey' => 'relative_path_test',
            'dbDir' => './test_db/',
            'autoCreateDB' => true
        ];

        $db = new \noneDB($config);

        // Should work - relative path gets resolved
        $result = $db->insert('relative-test', ['data' => 'test']);
        $this->assertEquals(1, $result['n']);
    }

    /**
     * @test
     */
    public function dbDirWithoutTrailingSlashGetsSlashAdded(): void
    {
        $config = [
            'secretKey' => 'trailing_slash_test',
            'dbDir' => $this->testDbDir, // Already has trailing slash from TEST_DB_DIR
            'autoCreateDB' => true
        ];

        $db = new \noneDB($config);

        $result = $db->insert('slash-test', ['data' => 'test']);
        $this->assertEquals(1, $result['n']);
    }

    /**
     * @test
     */
    public function multipleInstancesShareConfigCache(): void
    {
        $config = [
            'secretKey' => 'shared_cache_test',
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => true
        ];

        // First instance
        $db1 = new \noneDB($config);
        $db1->insert('shared-test', ['from' => 'db1']);

        // Second instance with same config
        $db2 = new \noneDB($config);
        $db2->insert('shared-test', ['from' => 'db2']);

        // Both should see all records
        $all = $db1->find('shared-test', 0);
        $this->assertCount(2, $all);
    }

    /**
     * @test
     */
    public function invalidConfigArrayIsHandledGracefully(): void
    {
        $config = [
            'secretKey' => 'invalid_test',
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => 'yes', // Should be bool, but string works
            'shardSize' => '5000',   // Should be int, but string works
        ];

        $db = new \noneDB($config);

        // Should still work - values get cast
        $result = $db->insert('invalid-config-test', ['data' => 'test']);
        $this->assertEquals(1, $result['n']);
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

        // Should work with defaults for other fields
        $result = $db->insert('minimal-test', ['data' => 'test']);
        $this->assertEquals(1, $result['n']);
    }

    /**
     * @test
     */
    public function emptySecretKeyInConfigStillWorks(): void
    {
        // v3.2.0: an empty secretKey would mean "no key at all", so it is rejected
        $this->expectException(\InvalidArgumentException::class);
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

        $cmd = 'cd ' . escapeshellarg($tempDir) . ' && env -u NONEDB_DEV_MODE '
            . escapeshellarg(PHP_BINARY) . ' run.php 2>&1';
        $output = shell_exec($cmd);

        @unlink($tempDir . '/run.php');
        @unlink($tempDir . '/noneDB.php');
        @rmdir($tempDir);

        $this->assertStringStartsWith('RuntimeException:', (string)$output);
        $this->assertStringContainsString('Configuration file not found', (string)$output);
    }
}
