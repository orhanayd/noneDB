<?php

namespace noneDB\Tests\Support;

/**
 * What a path or a shell command looks like on the system the tests run on
 */
trait Platform
{
    /** A directory as the engine resolves it (realpath(), then one slash): the separators of the system */
    protected static function resolvedDir(string $path): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, rtrim($path, '/')) . '/';
    }

    /** The start of a shell command that runs in $dir (on Windows cd changes the drive only with /d) */
    protected static function cd(string $dir): string
    {
        return 'cd ' . (PHP_OS_FAMILY === 'Windows' ? '/d ' : '') . escapeshellarg($dir) . ' && ';
    }
}

