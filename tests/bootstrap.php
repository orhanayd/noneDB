<?php
/**
 * PHPUnit Bootstrap File
 *
 * This file is loaded before running any tests.
 */

// Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load noneDB class
require_once __DIR__ . '/../noneDB.php';

// Define test database directory constant
define('TEST_DB_DIR', __DIR__ . '/test_db/');

// Create test db directory if not exists
if (!file_exists(TEST_DB_DIR)) {
    mkdir(TEST_DB_DIR, 0777, true);
}
