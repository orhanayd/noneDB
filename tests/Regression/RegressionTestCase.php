<?php

namespace noneDB\Tests\Regression;

use noneDB\Tests\noneDBTestCase;

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
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
        \noneDB::enableStaticCache();
        parent::tearDown();
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
     * Write a child script (body runs after noneDB.php is loaded) and return its path
     */
    protected function childScript(string $body): string
    {
        $path = sys_get_temp_dir() . '/nonedb_regression_' . bin2hex(random_bytes(6)) . '.php';
        $repo = realpath(__DIR__ . '/../../noneDB.php');
        file_put_contents($path, "<?php\nerror_reporting(E_ALL);\nini_set('display_errors', 'stderr');\nrequire " . var_export($repo, true) . ";\n" . $body);
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
     * Base path {dbDir}{hash}-{name} of a database for the test secret
     */
    protected function dbBasePath(string $dbName): string
    {
        $hash = hash_pbkdf2('sha256', $dbName, self::SECRET, 1000, 20);
        return $this->testDbDir . $hash . '-' . $dbName;
    }

    /**
     * State record of a database's lock file: ['gen' => int, 'flags' => string[]]
     */
    protected function lockState(string $dbName): array
    {
        $tokens = explode(' ', trim((string)file_get_contents($this->dbBasePath($dbName) . '.nonedb.lock')));
        return ['gen' => (int)array_shift($tokens), 'flags' => array_values(array_filter($tokens, 'strlen'))];
    }

    /**
     * Turn a database into what a pre-3.2 version leaves behind as far as the lock is concerned:
     * those versions had no lock file (and no state record)
     */
    protected function removeLockFile(string $dbName): void
    {
        \noneDB::clearStaticCache(); // closes the kept lock handles
        $this->assertTrue(unlink($this->dbBasePath($dbName) . '.nonedb.lock'));
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
