<?php

namespace noneDB\Tests;


/**
 * Base class for the CODE_REVIEW.md regression tests.
 *
 * Every test names the finding it covers (K-xx, Y-xx, O-xx, S-xx, ...). Scenarios that depend on
 * separate processes (locking, cross-process caches) run real child PHP processes.
 */
abstract class RegressionTestCase extends noneDBTestCase
{
    protected const SECRET = 'test_secret_key_for_unit_tests';

    /** @var string[] */
    private $tempFiles = [];

    protected function tearDown(): void
    {
        $this->setFaultHook(null);
        // Process-wide setting a test may have changed (the last instance's value counts)
        \noneDB\Tests\Support\Testing::set('cacheMemoryLimit', null); // automatic
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
        \noneDB::enableStaticCache();
        parent::tearDown();
    }

    /**
     * Install (or remove) noneDB's test hook: callable($point, array $context), called at named points
     * of the write path ("append:after", "index:written", "rollback:truncate", "job:deferred", ...)
     */
    protected function setFaultHook(?callable $hook): void
    {
        \noneDB\Tests\Support\Testing::setFaultHook($hook);
    }

    /**
     * Set a private property of a given noneDB instance
     */
    protected function setInstanceProperty(\noneDB $db, string $name, $value): void
    {
        \noneDB\Tests\Support\Testing::setOf($db, $name, $value);
    }

    /**
     * Lines of a data file (complete lines only)
     */
    protected function dataLines(string $file): array
    {
        clearstatcache(true, $file);
        if (!is_file($file)) {
            return [];
        }
        return array_values(array_filter(explode("\n", (string)file_get_contents($file)), 'strlen'));
    }

    /**
     * New instance on the test directory with extra config
     */
    protected function db(array $config = []): \noneDB
    {
        return new \noneDB(array_merge([
            'secretKey' => self::SECRET,
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => true,
        ], $config));
    }

    /**
     * PHP expression creating a noneDB instance in a child process
     */
    protected function childDb(array $config = []): string
    {
        $config = array_merge(['secretKey' => self::SECRET, 'dbDir' => $this->testDbDir], $config);
        return 'new \noneDB(' . var_export($config, true) . ')';
    }

    /**
     * Write a child script (body runs after the test build of noneDB.php and the test interface are loaded) and
     * return its path
     */
    protected function childScript(string $body): string
    {
        $path = sys_get_temp_dir() . '/nonedb_regression_' . bin2hex(random_bytes(6)) . '.php';
        // The test build (test seams included) and the test interface, as in this process
        file_put_contents($path, "<?php\nerror_reporting(E_ALL);\nini_set('display_errors', 'stderr');\nrequire " . var_export(NONEDB_TEST_LIBRARY, true)
            . ";\nrequire " . var_export(realpath(__DIR__ . '/Support/Testing.php'), true) . ";\n" . $body);
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * Run PHP code in a separate process and return its stdout (stderr appended)
     */
    protected function runChild(string $body, array $args = []): string
    {
        $script = $this->childScript($body);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string)$arg);
        }
        return trim((string)shell_exec($cmd . ' 2>&1'));
    }

    /**
     * Run the same script in $count processes at once (argv[1] = process number) and wait for all
     * @return string[] outputs
     */
    protected function runParallel(string $body, int $count): array
    {
        $script = $this->childScript($body);
        $procs = [];
        $pipesList = [];
        for ($i = 1; $i <= $count; $i++) {
            $cmd = [PHP_BINARY, $script, (string)$i];
            $pipes = [];
            $proc = proc_open(
                implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1',
                [1 => ['pipe', 'w']],
                $pipes
            );
            $procs[$i] = $proc;
            $pipesList[$i] = $pipes;
        }
        $outputs = [];
        foreach ($procs as $i => $proc) {
            $outputs[$i] = trim((string)stream_get_contents($pipesList[$i][1]));
            fclose($pipesList[$i][1]);
            proc_close($proc);
        }
        return $outputs;
    }

    /**
     * State record of a database's lock file: ['gen' => int, 'epoch' => ?string, 'flags' => string[]]
     */
    protected function lockState(string $dbName): array
    {
        $tokens = explode(' ', trim((string)file_get_contents($this->dbPath($dbName, 'lock')), " \0"));
        $state = (string)array_shift($tokens);
        $epoch = strpos($state, ':') !== false ? substr($state, strpos($state, ':') + 1) : null;
        return ['gen' => (int)$state, 'epoch' => $epoch, 'flags' => array_values(array_filter($tokens, 'strlen'))];
    }

    /**
     * Write a database's state record as it is (space padded to 128 bytes)
     */
    protected function writeLockState(string $dbName, string $record): void
    {
        \noneDB::clearStaticCache(); // closes the kept lock handles
        file_put_contents($this->dbPath($dbName, 'lock'), str_pad($record, 128));
    }

    /**
     * Delete a database's lock file (a new, empty lock record is created on the next access)
     */
    protected function removeLockFile(string $dbName): void
    {
        \noneDB::clearStaticCache(); // closes the kept lock handles
        $this->assertTrue(unlink($this->dbPath($dbName, 'lock')));
    }

    /**
     * Records sorted by key, without "key"
     */
    protected function plain(array $records): array
    {
        usort($records, function ($a, $b) { return $a['key'] <=> $b['key']; });
        return array_map(function ($r) { unset($r['key']); return $r; }, $records);
    }
}
