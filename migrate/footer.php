<?php
// The command line entry: when this file is the script PHP runs, or Composer's bin proxy (vendor/bin) includes it
namespace {
if (PHP_SAPI === 'cli' && isset($argv[0]) && (realpath($argv[0]) === __FILE__ || isset($GLOBALS['_composer_bin_dir']))) {
    if (!class_exists('noneDB', false)) {
        require __DIR__ . '/noneDB.php';
    }
    exit(\noneDB\Migrate\Cli::main(array_slice($argv, 1)));
}
}
