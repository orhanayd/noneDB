<?php

namespace noneDB\Tests\Migration;

use noneDB\Tests\RegressionTestCase;

/**
 * Base of the tests of noneDB-migrate.php (docs/MIGRATION.md). The fixtures (tests/Migration/fixtures/, made by
 * tools/make-migration-fixtures.php) are databases the earlier releases wrote themselves, with what each release
 * returns for them: the oracle. The tool runs as a child process on a copy of a fixture; the engine then opens the
 * result. Expected values come from the oracle and from MIGRATION.md, never from the tool.
 */
abstract class MigrationTestCase extends RegressionTestCase
{
    /** The key noneDB 4 stores use in these tests (releases before 3.0 used nonedb_123, which noneDB 4 refuses) */
    protected const TARGET = 'migration-test-target-key-5d8e1f';

    /** The crash points of the tool's test build stop it with SIGKILL (posix) */
    protected function requireCrashPoints(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('the tool\'s crash points need the posix extension (SIGKILL)');
        }
    }

    protected static function fixturesDir(): string
    {
        $dir = __DIR__ . '/fixtures';
        if (!is_dir($dir)) {
            self::fail('no fixtures, they are not in git: run php tools/make-migration-fixtures.php');
        }
        return $dir;
    }

    /** fixture.json of a fixture */
    protected function fixture(string $name): array
    {
        return json_decode((string)file_get_contents(self::fixturesDir() . "/$name/fixture.json"), true);
    }

    /** A copy of the fixture's files in the test directory: the --dir of a run */
    protected function stage(string $fixture): string
    {
        $dir = $this->testDbDir . 'm-' . preg_replace('/[^a-z0-9.-]/', '_', $fixture) . '/';
        $this->copyTree(self::fixturesDir() . "/$fixture/db", $dir);
        return $dir;
    }

    protected function copyTree(string $from, string $to): void
    {
        @mkdir($to, 0777, true);
        foreach (scandir($from) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                is_dir("$from/$entry") ? $this->copyTree("$from/$entry", "$to/$entry") : copy("$from/$entry", "$to/$entry");
            }
        }
    }

    /**
     * Run noneDB-migrate.php (the test build) with $args and --json; keys from the environment
     * @return array [exit status, decoded output (null when it isn't JSON), stdout, stderr]
     */
    protected function tool(array $args, string $sourceKey, ?string $targetKey = self::TARGET, ?string $cwd = null): array
    {
        $env = getenv();
        unset($env['NONEDB_SECRET'], $env['NONEDB_SOURCE_SECRET']);
        if ($targetKey !== null) {
            $env['NONEDB_SECRET'] = $targetKey;
        }
        $env['NONEDB_SOURCE_SECRET'] = $sourceKey;
        $process = proc_open(array_merge([PHP_BINARY, NONEDB_TEST_TOOL], $args, ['--json']), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        return [$status, json_decode($out, true), $out, $err];
    }

    /** Every file under $dir: relative path => SHA-256 */
    protected function snapshot(string $dir): array
    {
        $files = [];
        $walk = function (string $path, string $rel) use (&$walk, &$files) {
            foreach (scandir($path) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                is_dir("$path/$entry") ? $walk("$path/$entry", "$rel$entry/") : $files["$rel$entry"] = hash_file('sha256', "$path/$entry");
            }
        };
        $walk(rtrim($dir, '/'), '');
        ksort($files);
        $walk = null;
        return $files;
    }

    /** The records a release returned for a database: key => record */
    protected function oracleRecords(array $fixture, string $release, string $name): array
    {
        $records = [];
        foreach ($fixture['oracles'][$release][$name]['records'] as [$key, $record]) {
            $records[$key] = $record;
        }
        return $records;
    }

    /** The records the engine returns: key => record */
    protected function v4Records(\noneDB $db, string $name): array
    {
        $records = [];
        foreach ($db->find($name, 0) as $record) {
            $records[$record['key']] = $record;
        }
        ksort($records);
        return $records;
    }

    protected function v4(string $dir, string $key = self::TARGET, array $config = []): \noneDB
    {
        \noneDB::clearStaticCache();
        return new \noneDB($config + ['secretKey' => $key, 'dbDir' => $dir, 'autoCreateDB' => false]);
    }

    /**
     * MIGRATION.md §5, from the files of the fixture (its own reading, not the tool's): the largest of the keys the
     * records hold + 1, whole-JSON data counts (with the shard's base), every key of every JSONL line + 1, the key
     * indexes' n (with the shard's base) and the meta's nextKey
     */
    protected function expectedNextKey(string $fixtureDir, string $sourceKey, string $name, array $records): int
    {
        $next = $records === [] ? 0 : max(array_keys($records)) + 1;
        $base = hash_pbkdf2('sha256', $name, $sourceKey, 1000, 20) . '-' . $name;
        $meta = json_decode((string)@file_get_contents("$fixtureDir/$base.nonedb.meta"), true);
        $size = is_array($meta) ? $meta['shardSize'] : null;
        if (is_array($meta)) {
            $next = max($next, (int)($meta['nextKey'] ?? 0));
            $segments = array_map(function ($shard) { return (int)$shard['id']; }, $meta['shards']);
        } else {
            $segments = [null];
        }
        foreach ($segments as $id) {
            $offset = $id === null ? 0 : $id * $size;
            $data = "$fixtureDir/$base" . ($id === null ? '' : "_s$id") . '.nonedb';
            $raw = (string)@file_get_contents($data);
            $whole = json_decode($raw, true);
            if (is_array($whole) && array_keys($whole) === ['data']) {
                $keys = array_keys($whole['data']);
                $next = max($next, $keys === [] ? 0 : $offset + max($keys) + 1);
            } else {
                foreach (explode("\n", $raw) as $line) {
                    $value = json_decode($line, true);
                    $key = $value['key'] ?? $value['__nonedb_deleted'] ?? null;
                    if (is_int($key)) {
                        $next = max($next, $key + 1);
                    }
                }
            }
            $index = json_decode((string)@file_get_contents("$data.jidx"), true);
            if (is_array($index) && is_int($index['n'] ?? null)) {
                $next = max($next, $offset + $index['n']);
            }
        }
        return $next;
    }
}
