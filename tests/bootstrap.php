<?php
/**
 * PHPUnit Bootstrap File
 *
 * This file is loaded before running any tests.
 */

// The tests run against the test builds: noneDB.php of src/ and noneDB-migrate.php of migrate/, each with its test
// seams (fault points, test hooks, counters: tools/build.php). Written to tests/build/ only when they change; the
// engine is loaded before Composer's autoloader could reach the production noneDB.php. The tool next to it loads it.
require_once __DIR__ . '/../tools/build.php';
(static function (): void {
    foreach (['noneDB.php' => nonedb_build(dirname(__DIR__), true)['code'], 'noneDB-migrate.php' => nonedb_build_tool(dirname(__DIR__), true)['code']] as $name => $code) {
        $file = __DIR__ . '/build/' . $name;
        if (!is_file($file) || file_get_contents($file) !== $code) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            $tmp = $file . '.tmp' . getmypid();
            file_put_contents($tmp, $code);
            rename($tmp, $file);
        }
    }
})();
define('NONEDB_TEST_LIBRARY', __DIR__ . '/build/noneDB.php');
define('NONEDB_TEST_TOOL', __DIR__ . '/build/noneDB-migrate.php');
// The configuration template ships next to noneDB.php: next to the test build too (getConfigTemplate())
if (!is_file(__DIR__ . '/build/.nonedb.example') || file_get_contents(__DIR__ . '/build/.nonedb.example') !== file_get_contents(__DIR__ . '/../.nonedb.example')) {
    copy(__DIR__ . '/../.nonedb.example', __DIR__ . '/build/.nonedb.example');
}
require_once NONEDB_TEST_LIBRARY;

// Composer autoloader (the test classes, tests/Support/Testing.php)
require_once __DIR__ . '/../vendor/autoload.php';

// Define test database directory constant (NONEDB_TEST_DB_DIR: another directory, so test runs don't share one)
$testDbDir = getenv('NONEDB_TEST_DB_DIR');
$testDbDir = is_string($testDbDir) && $testDbDir !== '' ? rtrim($testDbDir, '/') : __DIR__ . '/test_db';

// Create test db directory if not exists
if (!file_exists($testDbDir)) {
    mkdir($testDbDir, 0777, true);
}
// As the engine resolves it, so that a path of a test is the engine's on every system
define('TEST_DB_DIR', realpath($testDbDir) . '/');
