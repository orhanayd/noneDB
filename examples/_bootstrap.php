<?php
/**
 * Shared setup for the examples.
 *
 * The examples use their own database directory (examples/db/) and a fixed demo key, so they run
 * from any working directory, need no .nonedb file and never touch your real data.
 * In an application, put secretKey/dbDir in a .nonedb file instead (see docs/CONFIGURATION.md).
 */

require_once __DIR__ . '/../noneDB.php';

function exampleDb(array $config = []): noneDB
{
    return new noneDB(array_merge([
        'secretKey' => 'nonedb-examples-demo-key-not-for-production',
        'dbDir' => __DIR__ . '/db/',
    ], $config));
}

/**
 * Drop the given databases so every run starts from the same state
 */
function freshDatabases(noneDB $db, string ...$names): void
{
    foreach ($names as $name) {
        $db->dropDB($name);
    }
}
