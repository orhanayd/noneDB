<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Specification tests of the operations area: configuration, dev mode, locks, rollback scope, database
 * operations, checkIntegrity(), getRecoveryLog(), getDurability() and migrateKey().
 *
 * Every expected value comes from docs/STORAGE_FORMAT.md, docs/DURABILITY.md, docs/CONFIGURATION.md,
 * docs/API.md, CHANGES.md or CLAUDE.md, or is derived by hand / by an independent model inside the test;
 * the source is named next to it.
 */
class SpecOperationsTest extends RegressionTestCase
{
    /** @var string[] */
    private $scratchDirs = [];
    /** @var array[] [process, pipes] of children that hold a lock */
    private $holders = [];
    private $savedScript = null;
    private $hadScript = false;
    private $savedCwd = null;
    private $savedDevEnv = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadScript = array_key_exists('SCRIPT_FILENAME', $_SERVER);
        $this->savedScript = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $this->savedCwd = getcwd();
        $this->savedDevEnv = getenv('NONEDB_DEV_MODE');
    }

    protected function tearDown(): void
    {
        foreach ($this->holders as [$proc, $pipes]) {
            foreach ($pipes as $pipe) {
                @fclose($pipe);
            }
            @proc_terminate($proc);
            @proc_close($proc);
        }
        $this->holders = [];
        if ($this->hadScript) {
            $_SERVER['SCRIPT_FILENAME'] = $this->savedScript;
        } else {
            unset($_SERVER['SCRIPT_FILENAME']);
        }
        if ($this->savedCwd !== false && $this->savedCwd !== null) {
            chdir($this->savedCwd);
        }
        putenv($this->savedDevEnv === false ? 'NONEDB_DEV_MODE' : 'NONEDB_DEV_MODE=' . $this->savedDevEnv);
        foreach (['syncFn', 'flushFn', 'renameFn'] as $name) {
            $this->setStatic($name, null);
        }
        \noneDB::clearConfigCache();
        parent::tearDown();
        foreach ($this->scratchDirs as $dir) {
            $this->removeDirectoryTree($dir);
        }
        $this->scratchDirs = [];
    }

    // ==========================================
    // helpers
    // ==========================================

    private function staticValue(string $name)
    {
        $property = new \noneDB\Tests\InternalValue($name);
        return $property->getValue();
    }

    private function setStatic(string $name, $value): void
    {
        $property = new \noneDB\Tests\InternalValue($name);
        $property->setValue(null, $value);
    }

    private function prop(\noneDB $db, string $name)
    {
        $property = new \noneDB\Tests\InternalValue($name);
        return $property->getValue($db);
    }

    private function call(?\noneDB $db, string $method, array $args = [])
    {
        $reflection = new \noneDB\Tests\InternalMethod($method);
        return $reflection->invokeArgs($db, $args);
    }

    /** Run $fn inside withLock() of $db (exclusive or shared) */
    private function locked(\noneDB $db, string $name, bool $exclusive, callable $fn)
    {
        return $this->call($db, 'withLock', [$name, $exclusive, $fn]);
    }

    private function scratch(string $name): string
    {
        $dir = sys_get_temp_dir() . '/nonedb_spec_' . $name . '_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $dir = realpath($dir);
        $this->scratchDirs[] = $dir;
        return $dir;
    }

    /** Make $dir the entry script's directory (first place a .nonedb file is looked for) */
    private function entryScriptIn(string $dir, string $file = 'entry.php', bool $create = true): void
    {
        if ($create) {
            file_put_contents($dir . '/' . $file, "<?php\n");
        }
        $_SERVER['SCRIPT_FILENAME'] = $dir . '/' . $file;
        \noneDB::clearConfigCache();
    }

    /** Lock state record of a database: the raw 128 bytes */
    private function lockRecord(string $name): string
    {
        clearstatcache();
        return (string)file_get_contents($this->dbPath($name, 'lock'));
    }

    private function epochOf(string $name): ?string
    {
        $first = explode(' ', trim($this->lockRecord($name)))[0];
        $parts = explode(':', $first, 2);
        return $parts[1] ?? null;
    }

    /**
     * Start a child process that takes flock($mode) on $file and holds it for $seconds; returns once it
     * holds the lock
     */
    private function holdLock(string $file, int $mode, float $seconds): void
    {
        $script = $this->childScript('$fp = fopen(' . var_export($file, true) . ', "c+"); flock($fp, ' . $mode . ');'
            . ' echo "locked\n"; fflush(STDOUT); usleep(' . (int)($seconds * 1000000) . '); flock($fp, LOCK_UN); echo "released\n";');
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w']], $pipes); // no shell: proc_terminate() reaches PHP
        $this->holders[] = [$proc, $pipes];
        $this->assertSame('locked', trim((string)fgets($pipes[1])), 'the child holds the lock');
    }

    /** Directory of a database in a dbDir under a key: {dbDir}{locator}.ndb4/ (STORAGE_FORMAT.md 1, 4) */
    private function homeOf(string $name, ?string $dir = null, string $key = self::SECRET): string
    {
        return rtrim($dir ?? $this->testDbDir, '/') . '/' . $this->locatorOf($name, $key) . '.ndb4/';
    }

    /** Data file of a single-layout database in a dbDir under a key (generation from CURRENT) */
    private function mainDataFile(string $name, ?string $dir = null, string $key = self::SECRET): string
    {
        $home = $this->homeOf($name, $dir, $key);
        $current = json_decode((string)@file_get_contents($home . 'CURRENT'), true);
        return $home . 'generations/' . (is_int($current['generation'] ?? null) ? $current['generation'] : 1) . '/segments/main.jsonl';
    }

    /** Files of a database (paths relative to its directory), LOCK excluded unless asked for */
    private function filesOfDb(string $name, ?string $dir = null, string $key = self::SECRET, bool $withLock = false): array
    {
        $home = $this->homeOf($name, $dir, $key);
        $files = [];
        $walk = function (string $path) use (&$walk, &$files, $home, $withLock) {
            foreach (@scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_dir($path . $entry)) {
                    $walk($path . $entry . '/');
                } elseif ($withLock || $path . $entry !== $home . 'LOCK') {
                    $files[] = substr($path . $entry, strlen($home));
                }
            }
        };
        $walk($home);
        sort($files);
        return $files;
    }

    /**
     * path => sha1 of every file in the test directory, recursively (store files and LOCK files included: their
     * records count too); directories are listed as path/ => 'dir'
     */
    private function directorySnapshot(?string $dir = null): array
    {
        $root = rtrim($dir ?? $this->testDbDir, '/') . '/';
        clearstatcache();
        $files = [];
        $walk = function (string $path) use (&$walk, &$files, $root) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_dir($path . $entry)) {
                    $files[substr($path . $entry, strlen($root)) . '/'] = 'dir';
                    $walk($path . $entry . '/');
                } else {
                    // On Windows a lock keeps every other handle from reading the file
                    $files[substr($path . $entry, strlen($root))] = PHP_OS_FAMILY === 'Windows' && $entry === 'LOCK' ? filesize($path . $entry) : sha1_file($path . $entry);
                }
            }
        };
        $walk($root);
        ksort($files);
        return $files;
    }

    /** chmod every directory of a tree (files keep their mode) */
    private function chmodDirectories(string $dir, int $mode): void
    {
        $dir = rtrim($dir, '/');
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($dir . '/' . $entry) && !is_link($dir . '/' . $entry)) {
                $this->chmodDirectories($dir . '/' . $entry, $mode);
            }
        }
        chmod($dir, $mode);
    }

    /** True when the current user can't write to a directory made read-only (not root) */
    private function permissionsAreEnforced(string $dir): bool
    {
        chmod($dir, 0555);
        $probe = $dir . '/probe-' . bin2hex(random_bytes(3));
        $writable = @file_put_contents($probe, 'x') !== false;
        if ($writable) {
            @unlink($probe);
        }
        chmod($dir, 0777);
        return !$writable;
    }

    // ==========================================
    // CONFIGURATION: every key, valid and invalid values
    // ==========================================

    /**
     * @test docs/CONFIGURATION.md "Configuration Options": boolean settings accept true/false, 1/0, "1"/"0"
     * and "true"/"false" in any case; anything else throws InvalidArgumentException
     */
    public function booleanSettingsAcceptExactlyTheDocumentedValues(): void
    {
        $settings = ['autoCreateDB', 'shardingEnabled', 'bufferEnabled', 'fieldIndexEnabled', 'spatialIndexEnabled'];
        $valid = [[true, true], [false, false], [1, true], [0, false], ['1', true], ['0', false],
            ['true', true], ['false', false], ['TRUE', true], ['False', false], ['tRuE', true], ['FALSE', false]];
        // "anything else ("yes", "off", 2) is an error"
        $invalid = ['yes', 'no', 'on', 'off', 2, -1, 1.0, 0.0, '', 'truth', '2', [], [true]];
        foreach ($settings as $setting) {
            foreach ($valid as [$value, $expected]) {
                $db = $this->db([$setting => $value]);
                $this->assertSame($expected, $this->prop($db, $setting), $setting . ' = ' . var_export($value, true));
            }
            foreach ($invalid as $value) {
                try {
                    $this->db([$setting => $value]);
                    $this->fail($setting . ' = ' . var_export($value, true) . ' must be rejected');
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString("'{$setting}'", $e->getMessage());
                    $this->assertStringContainsString('true or false', $e->getMessage());
                }
            }
        }
    }

    /**
     * @test the documented defaults (docs/CONFIGURATION.md tables), read in a process that has no .nonedb
     * file anywhere (a developer's local one would otherwise be merged in)
     */
    public function defaultsAreTheDocumentedOnes(): void
    {
        $dir = $this->scratch('defaults');
        copy(__DIR__ . '/../../noneDB.php', $dir . '/noneDB.php');
        file_put_contents($dir . '/run.php', '<?php require __DIR__ . "/noneDB.php"; require ' . var_export(realpath(__DIR__ . '/../Support/Testing.php'), true) . ';
            $db = new noneDB(["secretKey" => "defaults-key-0123456789", "dbDir" => __DIR__ . "/db/"]);
            $out = [];
            foreach (["autoCreateDB", "shardingEnabled", "shardSize", "autoCompactThreshold", "lockTimeout",
                      "lockRetryDelay", "bufferEnabled", "fieldIndexEnabled", "spatialIndexEnabled"] as $name) {
                $out[$name] = noneDB\Tests\Support\Testing::getOf($db, $name);
            }
            $out["durability"] = $db->getDurability()["mode"];
            $out["cacheMemoryLimit"] = noneDB\Tests\Support\Testing::get("cacheMemoryLimit");
            echo json_encode($out);');
        $out = trim((string)shell_exec(self::cd($dir) . 'env -u NONEDB_DEV_MODE ' . escapeshellarg(PHP_BINARY) . ' run.php 2>&1'));
        $this->assertSame([
            'autoCreateDB' => true,          // "autoCreateDB | bool | true"
            'shardingEnabled' => true,       // "shardingEnabled | bool | true"
            'shardSize' => 10000,            // "shardSize | int | 10000"
            'autoCompactThreshold' => 0.3,   // "autoCompactThreshold | float | 0.3"
            'lockTimeout' => 5,              // "lockTimeout | float | 5"
            'lockRetryDelay' => 10000,       // "lockRetryDelay | int | 10000"
            'bufferEnabled' => false,        // "bufferEnabled | bool | false"
            'fieldIndexEnabled' => true,     // "fieldIndexEnabled | bool | true"
            'spatialIndexEnabled' => true,   // "spatialIndexEnabled | bool | true"
            'durability' => 'performance',   // "durability | string | 'performance'"
            'cacheMemoryLimit' => null,      // "'auto'" (automatic: null internally, see cacheLimitBytes)
        ], json_decode($out, true), $out);
    }

    /**
     * @test numeric settings: valid values are taken (numeric strings too), out-of-range values throw
     * (docs/CONFIGURATION.md: shardSize >= 1, autoCompactThreshold > 0, lockTimeout 0 or positive with
     * fractions, NAN/INF/negative throw, lockRetryDelay > 0, cacheMemoryLimit non-negative int or 'auto')
     */
    public function numericSettingsAreValidated(): void
    {
        $cases = [
            // setting => [property, [[value, stored]], [invalid values]]
            'shardSize' => ['shardSize', [[1, 1], [5000, 5000], ['5000', 5000]], [0, -1, '0', 'abc', '']],
            'lockRetryDelay' => ['lockRetryDelay', [[1, 1], [20000, 20000], ['20000', 20000]], [0, -5, 'abc', '']],
            'autoCompactThreshold' => ['autoCompactThreshold', [[0.5, 0.5], ['0.25', 0.25], [1, 1.0], [2.5, 2.5]], [0, 0.0, -0.1, '0', 'abc', '']],
            'lockTimeout' => ['lockTimeout', [[0, 0.0], [0.5, 0.5], ['1.5', 1.5], [10, 10.0]],
                [-1, -0.001, NAN, INF, -INF, 'abc', '', [1], true, false]],
        ];
        foreach ($cases as $setting => [$property, $valid, $invalid]) {
            foreach ($valid as [$value, $stored]) {
                $this->assertSame($stored, $this->prop($this->db([$setting => $value]), $property), $setting . ' = ' . var_export($value, true));
            }
            foreach ($invalid as $value) {
                try {
                    $this->db([$setting => $value]);
                    $this->fail($setting . ' = ' . var_export($value, true) . ' must be rejected');
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString("'{$setting}'", $e->getMessage());
                }
            }
        }

        // cacheMemoryLimit: process-wide, read from the static setting (null = 'auto')
        foreach ([[0, 0], [1048576, 1048576], ['2048', 2048], ['auto', null]] as [$value, $stored]) {
            $this->db(['cacheMemoryLimit' => $value]);
            $this->assertSame($stored, $this->staticValue('cacheMemoryLimit'), var_export($value, true));
        }
        foreach ([-1, 1.5, '1.5', 'abc', '', 'AUTO', '-5', true, [1]] as $value) {
            try {
                $this->db(['cacheMemoryLimit' => $value]);
                $this->fail('cacheMemoryLimit = ' . var_export($value, true) . ' must be rejected');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("'cacheMemoryLimit'", $e->getMessage());
            }
        }
    }

    /**
     * @test docs/CONFIGURATION.md: "Values are validated when the instance is created: an invalid value throws
     * InvalidArgumentException." Non-numbers (arrays, booleans, strings with trailing garbage) and NAN are no
     * valid shard size, retry delay or compaction threshold; they must not be cast silently (an array or true
     * would mean one record per shard)
     */
    public function numericSettingsRejectNonNumericValues(): void
    {
        $accepted = [];
        foreach ([
            ['shardSize', [5]], ['shardSize', true], ['shardSize', '10abc'],
            ['lockRetryDelay', [1]], ['lockRetryDelay', true], ['lockRetryDelay', '5abc'],
            ['autoCompactThreshold', [0.5]], ['autoCompactThreshold', true], ['autoCompactThreshold', '0.5abc'], ['autoCompactThreshold', NAN],
        ] as [$setting, $value]) {
            try {
                $this->db([$setting => $value]);
                $accepted[] = $setting . ' = ' . str_replace("\n", '', var_export($value, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("'{$setting}'", $e->getMessage());
            }
        }
        $this->assertSame([], $accepted, 'invalid values were accepted');
    }

    /**
     * @test the key releases before 3.0 shipped with is as public as a placeholder: refused (docs/MIGRATION.md §2:
     * their databases are migrated with NONEDB_SOURCE_SECRET=nonedb_123 and a key of one's own)
     */
    public function theKeyReleasesBefore30ShippedWithIsRejected(): void
    {
        try {
            new \noneDB(['secretKey' => 'nonedb_123', 'dbDir' => $this->testDbDir]);
            $this->fail('nonedb_123 accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('publicly known', $e->getMessage());
        }
    }

    /**
     * @test every placeholder key shown in the documentation is rejected (CLAUDE.md "placeholders like
     * 'your_key' are rejected"; .nonedb.example, README.md, docs/CONFIGURATION.md lines 33 and 681)
     */
    public function documentedPlaceholderKeysAreRejected(): void
    {
        foreach (['CHANGE_THIS_TO_A_SECURE_RANDOM_STRING', 'YOUR_SECURE_RANDOM_STRING', 'YOUR_SECURE_RANDOM_STRING_HERE',
                  'GENERATE_UNIQUE_64_CHAR_STRING', 'your_key'] as $placeholder) {
            try {
                new \noneDB(['secretKey' => $placeholder, 'dbDir' => $this->testDbDir]);
                $this->fail($placeholder . ' accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('placeholder', $e->getMessage(), $placeholder);
            }
            // migrateKey() never moves data to a placeholder key either (docs/CONFIGURATION.md "migrateKey")
            $result = \noneDB::migrateKey($this->testDbDir, 'some-old-key', $placeholder);
            $this->assertFalse($result['success'], $placeholder);
            $this->assertNotEmpty($result['error'], $placeholder);
        }
        // The template's key is one of them: "replace the placeholder secretKey (it is rejected)"
        $template = json_decode((string)file_get_contents((string)\noneDB::getConfigTemplate()), true);
        $this->expectException(\InvalidArgumentException::class);
        new \noneDB(['dbDir' => $this->testDbDir] + $template);
    }

    // ==========================================
    // CONFIGURATION FILE (.nonedb) in this process: the entry script's directory is looked at first
    // ==========================================

    /**
     * @test docs/CONFIGURATION.md "Where .nonedb is looked for" (1: the running script's directory), "Relative
     * dbDir" (relative to the .nonedb file), "Mixed Configuration" (array merged over the file) and "The first
     * file found is used for the whole process"
     */
    public function configFileNextToTheEntryScriptIsUsedOncePerProcess(): void
    {
        $app = $this->scratch('cfg');
        file_put_contents($app . '/.nonedb', json_encode(['secretKey' => 'file-key-0123456789', 'dbDir' => 'data',
            'shardSize' => 7, 'bufferEnabled' => 'true', 'lockTimeout' => 2]));
        $this->entryScriptIn($app);
        $this->assertTrue(\noneDB::configExists());

        $db = new \noneDB();
        $this->assertSame(self::resolvedDir($app . '/data/'), $db->__debugInfo()['dbDir'], 'relative to the .nonedb file');
        $this->assertSame('***', $db->__debugInfo()['secretKey']);
        $this->assertFalse($db->__debugInfo()['devMode']);
        $this->assertSame(7, $db->getShardSize());
        $this->assertTrue($db->isBufferingEnabled());
        $this->assertSame(2.0, $this->prop($db, 'lockTimeout'));
        $db->insert('fromfile', ['v' => 1]);
        // directory = {locator(name, secretKey of the file)}.ndb4 (STORAGE_FORMAT.md 1)
        $this->assertFileExists($this->mainDataFile('fromfile', $app . '/data/', 'file-key-0123456789'));

        // The array is merged over the file: given keys win, the rest comes from the file
        $merged = new \noneDB(['shardSize' => 9, 'bufferEnabled' => false]);
        $this->assertSame(9, $merged->getShardSize());
        $this->assertFalse($merged->isBufferingEnabled());
        $this->assertSame(self::resolvedDir($app . '/data/'), $merged->__debugInfo()['dbDir']);
        $this->assertSame(2.0, $this->prop($merged, 'lockTimeout'));
        $this->assertSame([1], array_column($merged->find('fromfile', 0), 'v'), 'same key and directory as the file');

        // Loaded once per process: a changed file is seen only after clearConfigCache()
        file_put_contents($app . '/.nonedb', json_encode(['secretKey' => 'file-key-0123456789', 'dbDir' => 'other', 'shardSize' => 11]));
        $this->assertSame(7, (new \noneDB())->getShardSize());
        \noneDB::clearConfigCache();
        $reloaded = new \noneDB();
        $this->assertSame(11, $reloaded->getShardSize());
        $this->assertSame(self::resolvedDir($app . '/other/'), $reloaded->__debugInfo()['dbDir']);
    }

    /** @test a .nonedb that isn't a JSON object can't configure anything (unless the array gives secretKey and dbDir) */
    public function invalidConfigFileIsReportedUnlessTheArrayIsComplete(): void
    {
        foreach (['{"secretKey": "abc", ', '"just a string"', '42', 'null'] as $content) {
            $app = $this->scratch('badjson');
            file_put_contents($app . '/.nonedb', $content);
            $this->entryScriptIn($app);
            try {
                new \noneDB(['shardSize' => 3]);
                $this->fail('accepted ' . $content);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Invalid JSON in config file', $e->getMessage(), $content);
                $this->assertStringContainsString($app . '/.nonedb', $e->getMessage(), 'names the file');
            }
            // Complete array: the broken file is ignored entirely (its values can't be merged)
            \noneDB::clearConfigCache();
            $db = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $this->testDbDir]);
            $this->assertSame(10000, $db->getShardSize());
            $this->assertSame($this->testDbDir, $db->__debugInfo()['dbDir']);
        }
    }

    /** @test an unreadable .nonedb is an error naming the file, unless the array is complete */
    public function unreadableConfigFileIsReportedUnlessTheArrayIsComplete(): void
    {
        $app = $this->scratch('unreadable');
        file_put_contents($app . '/.nonedb', json_encode(['secretKey' => 'unreadable-key-123', 'dbDir' => 'data']));
        chmod($app . '/.nonedb', 0000);
        if (is_readable($app . '/.nonedb')) {
            chmod($app . '/.nonedb', 0600);
            $this->markTestSkipped('this user (root) can read a file with mode 0000');
        }
        $this->entryScriptIn($app);
        try {
            new \noneDB();
            $this->fail('an unreadable config file must not be ignored');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot read config file', $e->getMessage());
            $this->assertStringContainsString($app . '/.nonedb', $e->getMessage());
        }
        \noneDB::clearConfigCache();
        $db = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $this->testDbDir]);
        $this->assertSame($this->testDbDir, $db->__debugInfo()['dbDir']);
        chmod($app . '/.nonedb', 0600);
    }

    /** @test a config file without dbDir (and no dbDir in the array) can't be used: "dbDir | string | required" */
    public function configFileWithoutDbDirIsRejected(): void
    {
        $app = $this->scratch('nodir');
        file_put_contents($app . '/.nonedb', json_encode(['secretKey' => 'key-without-dir-0123']));
        $this->entryScriptIn($app);
        try {
            new \noneDB();
            $this->fail('no dbDir configured');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("'dbDir' is not configured", $e->getMessage());
        }
        // The array can add it
        $db = new \noneDB(['dbDir' => $this->testDbDir]);
        $this->assertSame($this->testDbDir, $db->__debugInfo()['dbDir']);
        $db->insert('nodir', ['v' => 1]);
        $this->assertFileExists($this->mainDataFile('nodir', null, 'key-without-dir-0123'));

        // A secretKey given in the array is used as given, never replaced by the file's (S-04): null is rejected
        try {
            new \noneDB(['secretKey' => null, 'dbDir' => $this->testDbDir]);
            $this->fail('a null secretKey in the array must not fall back to the file');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("'secretKey' must be a non-empty string", $e->getMessage());
        }
    }

    /**
     * @test docs/CONFIGURATION.md "Relative dbDir": in a config array, a relative dbDir (with or without ./,
     * also ../) is relative to the entry script's directory, used by its canonical path (STORAGE_FORMAT.md §3);
     * a trailing slash is added
     */
    public function relativeDbDirOfAnArrayFollowsTheEntryScript(): void
    {
        $root = $this->scratch('rel');
        mkdir($root . '/app');
        mkdir($root . '/cwd');
        $this->entryScriptIn($root . '/app');
        chdir($root . '/cwd');
        foreach (['store' => $root . '/app/store/', './store/' => $root . '/app/store/', '../shared' => $root . '/shared/'] as $dbDir => $expected) {
            $db = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $dbDir]);
            $this->assertSame(self::resolvedDir($expected), $db->__debugInfo()['dbDir'], $dbDir);
        }
        $db = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => '../shared']);
        $db->insert('rel', ['v' => 1]);
        $this->assertFileExists($this->mainDataFile('rel', $root . '/shared/'));
        $this->assertDirectoryDoesNotExist($root . '/cwd/shared', 'not relative to the working directory');

        // An absolute directory without trailing slash gets one
        $db = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => rtrim($this->testDbDir, '/')]);
        $this->assertSame($this->testDbDir, $db->__debugInfo()['dbDir']);

        // Entry script that doesn't exist (any more): its directory still counts; no entry script: the working directory
        $this->entryScriptIn($root . '/app', 'gone.php', false);
        $this->assertSame(self::resolvedDir($root . '/app/x/'), (new \noneDB(['secretKey' => self::SECRET, 'dbDir' => 'x']))->__debugInfo()['dbDir']);
        $_SERVER['SCRIPT_FILENAME'] = '';
        $this->assertSame(self::resolvedDir(getcwd() . '/x/'), (new \noneDB(['secretKey' => self::SECRET, 'dbDir' => 'x']))->__debugInfo()['dbDir']);

        // Many different entry scripts in one process (a long-running worker): each resolves on its own
        for ($i = 0; $i < 20; $i++) {
            mkdir($root . "/w$i");
            $this->entryScriptIn($root . "/w$i");
            $this->assertSame(self::resolvedDir($root . "/w$i/db/"), (new \noneDB(['secretKey' => self::SECRET, 'dbDir' => 'db']))->__debugInfo()['dbDir']);
        }
    }

    /**
     * @test docs/CONFIGURATION.md "Check Dev Mode Status": isDevMode() is true when NONEDB_DEV_MODE is set
     * ("=1", "=true"), "even if a .nonedb file is found and dev mode is therefore not used"
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function isDevModeFollowsTheEnvironmentVariable(): void
    {
        $this->assertFalse(defined('NONEDB_DEV_MODE'), 'a fresh process');
        putenv('NONEDB_DEV_MODE');
        $this->assertFalse(\noneDB::isDevMode());
        foreach (['1' => true, 'true' => true, 'yes' => false, '0' => false, 'false' => false, '' => false] as $value => $expected) {
            putenv('NONEDB_DEV_MODE=' . $value);
            $this->assertSame($expected, \noneDB::isDevMode(), 'NONEDB_DEV_MODE=' . $value);
        }
        // A complete array never uses dev mode, whatever the environment says
        putenv('NONEDB_DEV_MODE=1');
        $db = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $this->testDbDir]);
        $this->assertFalse($db->__debugInfo()['devMode']);
        putenv('NONEDB_DEV_MODE');
        \noneDB::setDevMode(false);
        $this->assertFalse(defined('NONEDB_DEV_MODE'), 'setDevMode(false) defines nothing');
        \noneDB::setDevMode(true);
        $this->assertTrue(NONEDB_DEV_MODE);
        $this->assertTrue(\noneDB::isDevMode());
    }

    /**
     * @test docs/CONFIGURATION.md "Development Mode": used only when no .nonedb is found and the array doesn't
     * give both secretKey and dbDir; it fills in what is missing: secretKey "nonedb_dev_mode_key_" .
     * md5(<directory of noneDB.php>), dbDir "db/" next to noneDB.php (no Composer). Enabled by
     * NONEDB_DEV_MODE=1/true or the constant (which "must be the boolean true").
     */
    public function devModeFillsInWhatIsMissing(): void
    {
        $lib = $this->scratch('devlib');
        $cwd = $this->scratch('devcwd');
        copy(__DIR__ . '/../../noneDB.php', $lib . '/noneDB.php');
        $own = $this->scratch('devown');
        $script = '<?php PREFIX require __DIR__ . "/noneDB.php";
            $out = [];
            foreach (["none" => null, "dir" => ["dbDir" => OWNDIR], "key" => ["secretKey" => "own-dev-key-123"],
                      "both" => ["secretKey" => "own-dev-key-123", "dbDir" => OWNDIR]] as $label => $config) {
                if ($label === "key") {
                    // The stores "none" and "dir" created belong to the dev key: the own key is registered as a
                    // second key of both (STORAGE_FORMAT.md 3.2, 9.3)
                    foreach ([__DIR__ . "/db/", OWNDIR] as $store) {
                        $r = noneDB::registerKey($store, DEVKEY, "own-dev-key-123");
                        if (!$r["success"]) { echo "registerKey: ", $r["error"], "\n"; }
                    }
                }
                try {
                    $db = new noneDB($config);
                    $db->insert("t" . $label, ["v" => 1]);
                    $out[$label] = $db->__debugInfo()["devMode"] ? "dev" : "nodev";
                    $out[$label] .= " " . $db->__debugInfo()["dbDir"];
                } catch (Throwable $e) {
                    $out[$label] = get_class($e);
                }
            }
            echo json_encode($out);';
        $devKey = 'nonedb_dev_mode_key_' . md5($lib);
        $script = strtr($script, ['DEVKEY' => var_export($devKey, true)]);
        $runs = [
            'constant true' => ['define("NONEDB_DEV_MODE", true);', ''],
            'env 1' => ['', 'NONEDB_DEV_MODE=1'],
            'env true' => ['', 'NONEDB_DEV_MODE=true'],
        ];
        foreach ($runs as $label => [$prefix, $env]) {
            $this->removeDirectoryTree($lib . '/db');
            $this->removeDirectoryTree($own);
            file_put_contents($lib . '/run.php', strtr($script, ['PREFIX' => $prefix, 'OWNDIR' => var_export($own . '/', true)]));
            $out = trim((string)shell_exec(self::cd($cwd) . 'env -u NONEDB_DEV_MODE ' . $env . ' '
                . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lib . '/run.php') . ' 2>&1'));
            $this->assertSame([
                'none' => 'dev ' . $lib . '/db/',
                'dir' => 'dev ' . $own . '/',
                'key' => 'dev ' . $lib . '/db/',
                'both' => 'nodev ' . $own . '/',
            ], json_decode($out, true), $label . ': ' . $out);
            // The keys: the predictable dev key where none was given, the given one otherwise
            $this->assertFileExists($this->mainDataFile('tnone', $lib . '/db/', $devKey), $label);
            $this->assertFileExists($this->mainDataFile('tdir', $own . '/', $devKey), $label);
            $this->assertFileExists($this->mainDataFile('tkey', $lib . '/db/', 'own-dev-key-123'), $label);
            $this->assertFileExists($this->mainDataFile('tboth', $own . '/', 'own-dev-key-123'), $label);
            $this->assertDirectoryDoesNotExist($cwd . '/db', 'not in the working directory');
        }

        // Not dev mode: NONEDB_DEV_MODE=yes, the constant 1 (not the boolean true), setDevMode(false)
        foreach (['env yes' => ['', 'NONEDB_DEV_MODE=yes'], 'constant 1' => ['define("NONEDB_DEV_MODE", 1);', ''],
                  'setDevMode(false)' => ['', ''], ] as $label => [$prefix, $env]) {
            $body = $label === 'setDevMode(false)' ? 'noneDB::setDevMode(false); ' : '';
            file_put_contents($lib . '/run.php', '<?php ' . $prefix . ' require __DIR__ . "/noneDB.php"; ' . $body
                . 'try { new noneDB(); echo "dev"; } catch (Throwable $e) { echo get_class($e), ": ", strtok($e->getMessage(), "\n"); }');
            $out = trim((string)shell_exec(self::cd($cwd) . 'env -u NONEDB_DEV_MODE ' . $env . ' '
                . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lib . '/run.php') . ' 2>&1'));
            $this->assertSame('RuntimeException: noneDB: Configuration file not found!', $out, $label);
        }
    }

    /**
     * @test docs/CONFIGURATION.md "Where .nonedb is looked for": the script's directory, noneDB.php's directory,
     * the Composer project root (installed under vendor/), the working directory; "The first file found is
     * used". Dev mode's dbDir is "db/ in the project root (next to vendor/ when installed with Composer)".
     */
    public function configSearchOrderAndComposerProjectRoot(): void
    {
        $proj = $this->scratch('composer');
        $vendor = $proj . '/vendor/orhanayd/nonedb';
        mkdir($vendor, 0777, true);
        mkdir($proj . '/public');
        $cwd = $this->scratch('composercwd');
        copy(__DIR__ . '/../../noneDB.php', $vendor . '/noneDB.php');
        file_put_contents($proj . '/public/index.php', '<?php require ' . var_export($vendor . '/noneDB.php', true) . ';
            if (getenv("DEV")) { define("NONEDB_DEV_MODE", true); }
            try { $db = new noneDB(); echo $db->__debugInfo()["dbDir"], " ", $db->__debugInfo()["devMode"] ? "dev" : "file"; }
            catch (Throwable $e) { echo get_class($e); }');
        $run = function (string $env = '') use ($proj, $cwd) {
            return trim((string)shell_exec(self::cd($cwd) . 'env -u NONEDB_DEV_MODE ' . $env . ' '
                . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($proj . '/public/index.php') . ' 2>&1'));
        };
        $write = function (string $dir, string $dbDir) {
            file_put_contents($dir . '/.nonedb', json_encode(['secretKey' => 'composer-key-0123456789', 'dbDir' => $dbDir]));
        };

        $this->assertSame('RuntimeException', $run(), 'no file anywhere');
        $this->assertSame($proj . '/db/ dev', $run('DEV=1'), 'dev mode: db/ next to vendor/');

        $write($cwd, 'from-cwd');                  // 4. the working directory
        $this->assertSame(self::resolvedDir($cwd . '/from-cwd/') . ' file', $run());
        $write($proj, 'from-root');                // 3. the Composer project root
        $this->assertSame(self::resolvedDir($proj . '/from-root/') . ' file', $run());
        $write($vendor, 'from-lib');               // 2. noneDB.php's directory
        $this->assertSame($vendor . '/from-lib/ file', $run());
        $write($proj . '/public', 'from-script');  // 1. the entry script's directory
        $this->assertSame($proj . '/public/from-script/ file', $run());
        $this->assertSame($proj . '/public/from-script/ file', $run('DEV=1'), 'a file found: dev mode is not used');
    }

    /** @test the template is the .nonedb.example next to noneDB.php, null when it isn't there (docs "Configuration Helpers") */
    public function configTemplateIsTheExampleNextToNoneDb(): void
    {
        $this->assertSame(realpath(dirname(NONEDB_TEST_LIBRARY) . '/.nonedb.example'), realpath(\noneDB::getConfigTemplate())); // next to the library that runs
        $lib = $this->scratch('notemplate');
        copy(__DIR__ . '/../../noneDB.php', $lib . '/noneDB.php');
        $out = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($lib . '/noneDB.php', true)
            . '; var_export(noneDB::getConfigTemplate());') . ' 2>&1'));
        $this->assertSame('NULL', $out);
    }

    /** @test noneDB instances can't be unserialized either (they hold a secret; S-09) */
    public function instancesCannotBeUnserialized(): void
    {
        $this->expectException(\LogicException::class);
        unserialize('O:6:"noneDB":0:{}');
    }

    // ==========================================
    // LOCKS: lockTimeout, LOCK_FAILED, lost locks, snapshots
    // ==========================================

    /** @test docs/CONFIGURATION.md "lockTimeout": "0 waits forever" */
    public function lockTimeoutZeroWaitsUntilTheLockIsFree(): void
    {
        $db = $this->db(['lockTimeout' => 0, 'shardingEnabled' => false]);
        $db->insert('forever', ['v' => 1]);
        $this->holdLock($this->dbPath('forever', 'lock'), LOCK_EX, 0.6);
        $start = microtime(true);
        $this->assertSame([1], array_column($db->find('forever', 0), 'v'));
        $this->assertGreaterThan(0.3, microtime(true) - $start, 'it waited for the other process instead of failing');
        $db->insert('forever', ['v' => 2]);
        $this->assertSame([1, 2], array_column($db->find('forever', 0), 'v'));
    }

    /**
     * @test docs/CONFIGURATION.md "lockTimeout": "Seconds to wait for a database lock before throwing
     * RuntimeException (code noneDB::LOCK_FAILED)"; "noneDB never continues an operation without its lock"
     */
    public function lockTimeoutThrowsLockFailedForReadsAndWrites(): void
    {
        $db = $this->db(['lockTimeout' => 0.3, 'shardingEnabled' => false]);
        $db->insert('busy', ['v' => 1]);
        $before = $this->directorySnapshot();
        $this->holdLock($this->dbPath('busy', 'lock'), LOCK_EX, 5);
        foreach (['find' => function () use ($db) { $db->find('busy', 0); },
                  'insert' => function () use ($db) { $db->insert('busy', ['v' => 2]); },
                  'count' => function () use ($db) { $db->count('busy'); }] as $label => $operation) {
            $start = microtime(true);
            try {
                $operation();
                $this->fail($label . ' ran without the lock');
            } catch (\RuntimeException $e) {
                $elapsed = microtime(true) - $start;
                $this->assertSame(\noneDB::LOCK_FAILED, $e->getCode(), $label);
                $this->assertStringContainsString('timed out', $e->getMessage(), $label);
                $this->assertGreaterThanOrEqual(0.25, $elapsed, $label . ': waited lockTimeout');
                $this->assertLessThan(3.0, $elapsed, $label . ': and not much longer');
            }
        }
        $this->assertSame($before, $this->directorySnapshot(), 'nothing was written');
    }

    /**
     * @test a shared lock is upgraded by releasing it first (CHANGES "Locking"); when the exclusive lock can't
     * be taken within lockTimeout the lock is lost: LOCK_FAILED, and every later step of the same operation
     * fails too instead of reading without a lock
     */
    public function anUpgradeThatTimesOutLosesTheLock(): void
    {
        $db = $this->db(['lockTimeout' => 0.3, 'shardingEnabled' => false]);
        $db->insert('up', ['v' => 1]);
        $lock = $this->dbPath('up', 'lock');
        $steps = $this->locked($db, 'up', false, function () use ($db, $lock) {
            $this->holdLock($lock, LOCK_SH, 5); // another reader
            $steps = [];
            foreach (['insert' => function () use ($db) { $db->insert('up', ['v' => 2]); },
                      'find' => function () use ($db) { $db->find('up', 0); }] as $label => $operation) {
                try {
                    $operation();
                    $steps[] = $label . ' ran';
                } catch (\RuntimeException $e) {
                    $steps[] = $label . ' ' . $e->getCode() . (strpos($e->getMessage(), 'lost') !== false ? ' lost' : '');
                }
            }
            return $steps;
        });
        $this->assertSame(['insert ' . \noneDB::LOCK_FAILED, 'find ' . \noneDB::LOCK_FAILED . ' lost'], $steps);
        foreach ($this->holders as [$proc, $pipes]) {
            proc_terminate($proc);
        }
        // The operation ended: the database works again, the failed insert wrote nothing
        $this->assertSame([1], array_column($db->find('up', 0), 'v'));
        $db->insert('up', ['v' => 3]);
        $this->assertSame([1, 3], array_column($db->find('up', 0), 'v'));
    }

    /**
     * @test CHANGES "Locking": "A lock that couldn't be taken throws RuntimeException with code
     * noneDB::LOCK_FAILED. Fallback paths never continue without the lock." Here the lock file can't be opened.
     */
    public function aLockFileThatCannotBeOpenedFailsWithLockFailed(): void
    {
        $db = $this->db();
        $db->insert('first', ['v' => 1]); // the store exists (STORAGE_FORMAT.md 3.2)
        mkdir($this->dbPath('nolock', 'lock'), 0777, true); // a directory where the lock file should be
        try {
            $db->insert('nolock', ['v' => 1]);
            $this->fail('no lock, no write');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("'nolock'", $e->getMessage());
            $this->assertSame([], $this->filesOfDb('nolock'), 'nothing written');
            $this->assertSame(\noneDB::LOCK_FAILED, $e->getCode(), $e->getMessage());
        } finally {
            rmdir($this->dbPath('nolock', 'lock'));
        }
    }

    /**
     * @test CLAUDE.md "Joins: withSnapshot() (shared locks of all databases ...), SNAPSHOT_RETRY instead of
     * waiting for an exclusive lock": work that needs an exclusive lock while the snapshot is held makes the
     * snapshot start again; the join result is the consistent one; the retries end at lockTimeout (LOCK_FAILED)
     */
    public function joinSnapshotsRetryInsteadOfWaitingForAnExclusiveLock(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'lockTimeout' => 0.5]);
        $db->insert('orders', [['user' => 0, 'item' => 'a'], ['user' => 1, 'item' => 'b'], ['user' => 7, 'item' => 'c']]);
        $db->insert('users', [['name' => 'ann'], ['name' => 'bob']]);
        // Expected: each order with the user whose key is its "user", null when there is none (docs/QUERY.md join())
        $users = [];
        foreach ($db->find('users', 0) as $user) {
            $users[$user['key']] = $user;
        }
        $expected = array_map(function ($order) use ($users) {
            return $order + ['users' => $users[$order['user']] ?? null];
        }, $db->find('orders', 0));

        $held = 0;
        $this->setFaultHook(function ($point) use ($db, &$held) {
            if ($point === 'snapshot:held' && ++$held === 1) {
                $db->insert('users', ['name' => 'carl']); // needs the exclusive lock: refused while the snapshot holds locks
            }
        });
        $this->assertSame($expected, $db->query('orders')->join('users', 'user', 'key')->get());
        $this->assertSame(2, $held, 'started once more');
        $this->setFaultHook(null);
        $this->assertSame(['ann', 'bob'], array_column($db->find('users', 0), 'name'), 'the refused write wrote nothing');

        // Other exceptions are not retried
        $held = 0;
        $this->setFaultHook(function ($point) use (&$held) {
            if ($point === 'snapshot:held') {
                $held++;
                throw new \RuntimeException('boom', 42);
            }
        });
        try {
            $db->query('orders')->join('users', 'user', 'key')->get();
            $this->fail('must throw');
        } catch (\RuntimeException $e) {
            $this->assertSame(['boom', 42, 1], [$e->getMessage(), $e->getCode(), $held]);
        }

        // Always refused: retried until lockTimeout, then LOCK_FAILED
        $this->setFaultHook(function ($point) use ($db) {
            if ($point === 'snapshot:held') {
                $db->insert('users', ['name' => 'dan']);
            }
        });
        $start = microtime(true);
        try {
            $db->query('orders')->join('users', 'user', 'key')->get();
            $this->fail('must give up');
        } catch (\RuntimeException $e) {
            $this->assertSame(\noneDB::LOCK_FAILED, $e->getCode());
            $this->assertGreaterThanOrEqual(0.45, microtime(true) - $start);
            $this->assertNotNull($e->getPrevious());
        }
        $this->setFaultHook(null);
        $this->assertSame(['ann', 'bob'], array_column($db->find('users', 0), 'name'));
    }

    /** @test a join of a database with itself (one lock) */
    public function selfJoinWorks(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('emp', [['name' => 'boss', 'mgr' => null], ['name' => 'ann', 'mgr' => 0], ['name' => 'bob', 'mgr' => 0]]);
        $boss = $db->find('emp', ['key' => 0])[0];
        $rows = $db->query('emp')->join('emp', 'mgr', 'key', 'manager')->get();
        $this->assertSame([null, $boss, $boss], array_column($rows, 'manager'));
        $this->assertSame(['boss', 'ann', 'bob'], array_column($rows, 'name'));
    }

    // ==========================================
    // DATABASE NAMES, DIRECTORIES
    // ==========================================

    /**
     * @test docs/API.md: "Database names may contain A-Z a-z 0-9, space, ' and -; every method throws
     * InvalidArgumentException for anything else"; integers are names ("0" is valid)
     */
    public function databaseNamesMustBeStringsOrIntegers(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $calls = [
            'createDB(null)' => function () use ($db) { $db->createDB(null); },
            'createDB(1.5)' => function () use ($db) { $db->createDB(1.5); },
            'insert([])' => function () use ($db) { $db->insert(['users'], ['v' => 1]); },
            'find(true)' => function () use ($db) { $db->find(true, 0); },
            'dropDB(object)' => function () use ($db) { $db->dropDB(new \stdClass()); },
            'getDBs([])' => function () use ($db) { $db->getDBs(['users']); },
            'getDBs(null)' => function () use ($db) { $db->getDBs(null); },
            'checkIntegrity(2.0)' => function () use ($db) { $db->checkIntegrity(2.0); },
        ];
        foreach ($calls as $label => $call) {
            try {
                $call();
                $this->fail($label . ' accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('database name must be a string', $e->getMessage(), $label);
            }
        }
        $this->assertSame([], glob($this->testDbDir . '*'), 'nothing created');
        $this->assertTrue($db->createDB(7));
        $this->assertSame(['7'], $db->getDBs(false));
        $this->assertSame(1, $db->insert(7, ['v' => 1])['n']);
        $this->assertSame([['v' => 1, 'key' => 0]], $db->find('7', 0));
    }

    /** @test a dbDir that can't be created fails the write with a RuntimeException naming it; reads still create nothing */
    public function uncreatableDbDirFailsWrites(): void
    {
        $blocker = $this->scratch('blocker') . '/file';
        file_put_contents($blocker, 'a file, not a directory');
        $dir = $blocker . '/db/';
        $db = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $dir]);
        $this->assertSame([], $db->find('x', 0), 'a read of a missing database');
        try {
            $db->insert('x', ['v' => 1]);
            $this->fail('the directory can not exist');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot create database directory', $e->getMessage());
            $this->assertStringContainsString(self::resolvedDir($dir), $e->getMessage());
        }
        $this->assertSame('a file, not a directory', file_get_contents($blocker));
    }

    // ==========================================
    // DATABASE OPERATIONS: dropDB, createDB, getDBs, getShardInfo, convertToSharded, compact, buffers
    // ==========================================

    /**
     * @test docs/API.md dropDB(): "Delete a database and all of its files (data, shards, indexes, buffers,
     * metadata, recovery log, quarantine and orphan files). Only LOCK is kept" (STORAGE_FORMAT.md 8.2).
     * Returns the number of deleted files.
     */
    public function dropDbRemovesEveryFileButTheLock(): void
    {
        $config = ['shardSize' => 3, 'bufferEnabled' => false];
        $db = $this->db($config);
        $db->createFieldIndex('all', 'c');
        $db->createSpatialIndex('all', 'loc');
        $db->insert('all', array_map(function ($n) {
            return ['c' => 'c' . ($n % 2), 'loc' => ['type' => 'Point', 'coordinates' => [$n, $n]]];
        }, range(0, 7)));
        // A torn tail: the next write quarantines it (quarantine file + recovery log)
        file_put_contents($this->dbPath('all', 'data', 2), '{"torn', FILE_APPEND);
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $db->insert('all', ['c' => 'c0']);
        // Buffered records, a rotated recovery log, an orphan shard file
        $buffered = $this->db(['shardSize' => 3, 'bufferEnabled' => true]);
        $buffered->insert('all', ['c' => 'buffered']);
        file_put_contents($this->dbPath('all', 'recovery1'), json_encode(['t' => 1, 'action' => 'old']) . "\n");
        $gen = substr($this->dbPath('all', 'gen'), strlen($this->dbPath('all', 'home'))); // generations/{N}/
        file_put_contents($this->dbPath('all', 'gen') . 'segments/s9.jsonl.orphan.20260101000000', "{}\n");

        $files = $this->filesOfDb('all');
        foreach (['FORMAT', 'CURRENT', $gen . 'metadata', $gen . 'shards.json', $gen . 'indexes.json', $gen . 'buffer/pending.jsonl',
                  'recovery/log.jsonl', 'recovery/log.1.jsonl', $gen . 'indexes/c.gfx', $gen . 'spatial/loc.grx', $gen . 'segments/s0.jsonl',
                  $gen . 'segments/s0.kix', $gen . 'indexes/s0.c.fx', $gen . 'spatial/s0.loc.rx',
                  $gen . 'segments/s9.jsonl.orphan.20260101000000'] as $file) {
            $this->assertContains($file, $files, 'set up: ' . $file);
        }
        $this->assertCount(1, preg_grep('#^recovery/q\.s2\.\d+\.6\.[0-9a-f]{64}\.bin$#', $files), 'a quarantine file');

        $this->assertSame(['success' => true, 'files' => count($files)], $db->dropDB('all'));
        $this->assertSame([], $this->filesOfDb('all'), 'every file of the database is gone');
        $this->assertSame(['LOCK'], $this->filesOfDb('all', null, self::SECRET, true), 'but LOCK');
        $this->assertSame([], $this->lockState('all')['flags'], 'nothing pending');
        $this->assertSame([], $db->getDBs(false));
        $this->assertSame([], $db->find('all', 0));
        $this->assertSame(['success' => false, 'files' => 0, 'error' => 'Database does not exist'], $db->dropDB('all'));
    }

    /** @test docs/API.md dropDB() of a database that doesn't exist; it creates nothing (no LOCK either) */
    public function dropDbOfAMissingDatabase(): void
    {
        $db = $this->db();
        $this->assertSame(['success' => false, 'files' => 0, 'error' => 'Database does not exist'], $db->dropDB('never'));
        $this->assertSame([], glob($this->testDbDir . '*'));
    }

    /** @test files that can't be deleted are reported (success false), the database stays usable and the drop can be repeated */
    public function dropDbThatCannotDeleteReportsIt(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('ro', [['v' => 1], ['v' => 2]]);
        if (!$this->permissionsAreEnforced(rtrim($this->testDbDir, '/'))) {
            $this->markTestSkipped('this user (root) can write to read-only directories');
        }
        $files = $this->filesOfDb('ro');
        // Every directory of the database read-only: none of its files can be deleted
        $this->chmodDirectories($this->dbPath('ro', 'home'), 0555);
        try {
            $result = $db->dropDB('ro');
        } finally {
            $this->chmodDirectories($this->dbPath('ro', 'home'), 0777);
        }
        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['files']);
        foreach ($files as $file) {
            $this->assertStringContainsString($file, $result['error']);
        }
        $this->assertSame($files, $this->filesOfDb('ro'), 'nothing deleted');
        $this->assertSame([1, 2], array_column($db->find('ro', 0), 'v'));
        $this->assertSame(['success' => true, 'files' => count($files)], $db->dropDB('ro'));
        $this->assertSame([], $this->filesOfDb('ro'));
    }

    /** @test createDB() that can't create the data file throws and leaves nothing behind */
    public function createDbThatCannotWriteThrows(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->createDB('rw');
        $db->dropDB('rw'); // LOCK and the database directory stay (STORAGE_FORMAT.md 8.2)
        if (!$this->permissionsAreEnforced(rtrim($this->testDbDir, '/'))) {
            $this->markTestSkipped('this user (root) can write to read-only directories');
        }
        $before = $this->filesOfDb('rw');
        chmod($this->dbPath('rw', 'home'), 0555);
        try {
            $db->createDB('rw');
            $this->fail('the directory is read-only');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("'rw'", $e->getMessage());
        } finally {
            chmod($this->dbPath('rw', 'home'), 0777);
        }
        $this->assertSame($before, $this->filesOfDb('rw'));
        $this->assertSame([], $db->getDBs(false));
        $this->assertTrue($db->createDB('rw'));
        $this->assertSame(['rw'], $db->getDBs(false));
    }

    /**
     * @test docs/API.md getDBs(): "Only databases of the current secretKey are listed; sharded databases appear
     * once, with the total size of their shards. Sizes use a comma as decimal separator"
     */
    public function getDbsListsOnlyThisKeysDatabasesOnce(): void
    {
        $mine = $this->db(['shardSize' => 4]);
        $other = new \noneDB(['secretKey' => 'another-key-0123456789', 'dbDir' => $this->testDbDir, 'shardSize' => 4]);
        $mine->insert('plain', ['v' => str_repeat('x', 2000)]);
        $mine->insert('sharded', array_map(function ($n) { return ['n' => $n, 'pad' => str_repeat('y', 300)]; }, range(0, 9)));
        // A second key of the same store is registered first (STORAGE_FORMAT.md 3.2, 9.3)
        $this->assertTrue(\noneDB::registerKey($this->testDbDir, self::SECRET, 'another-key-0123456789')['success']);
        $other->insert('theirs', ['v' => 1]);
        $other->insert('plain', ['v' => 2]); // same name, other key

        $names = $mine->getDBs(false);
        sort($names);
        $this->assertSame(['plain', 'sharded'], $names);
        $this->assertSame(['plain', 'theirs'], (function () use ($other) { $n = $other->getDBs(false); sort($n); return $n; })());

        // Size of the sharded database: every shard file, converted like docs/API.md says ("2,5 KB")
        $bytes = 0;
        foreach (glob($this->dbPath('sharded', 'gen') . 'segments/s*.jsonl') as $shard) {
            $bytes += filesize($shard);
        }
        $this->assertGreaterThan(1024, $bytes);
        $expectedSize = str_replace('.', ',', (string)round($bytes / 1024, 2)) . ' KB';
        $info = $mine->getDBs('sharded');
        $this->assertSame(['name', 'createdTime', 'size'], array_keys($info));
        $this->assertSame($expectedSize, $info['size']);
        $withInfo = $mine->getDBs(true);
        usort($withInfo, function ($a, $b) { return strcmp($a['name'], $b['name']); });
        $this->assertSame(['plain', 'sharded'], array_column($withInfo, 'name'));
        $this->assertSame($expectedSize, $withInfo[1]['size']);
        $this->assertSame(str_replace('.', ',', (string)round(filesize($this->dbPath('plain', 'data')) / 1024, 2)) . ' KB', $withInfo[0]['size']);

        // A directory that doesn't exist lists nothing and is not created
        $missing = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $this->testDbDir . 'nothing-here/']);
        $this->assertSame([], $missing->getDBs(false));
        $this->assertSame([], $missing->getDBs(true));
        $this->assertDirectoryDoesNotExist($this->testDbDir . 'nothing-here');
    }

    /** @test docs/API.md getShardInfo(): exact fields for both forms, false for a missing database */
    public function getShardInfoHasTheDocumentedFields(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $this->assertFalse($db->getShardInfo('none'));
        $db->insert('small', [['n' => 1], ['n' => 2], ['n' => 3]]);
        $db->delete('small', ['n' => 2]);
        // Not sharded: ["sharded" => false, "shards" => 0, "totalRecords" => n, "shardSize" => the size fixed at creation,
        // "nextKey" => the key the next insert gets]; keys are never handed out again, a deleted highest key included
        $this->assertSame(['sharded' => false, 'shards' => 0, 'totalRecords' => 2, 'shardSize' => 4, 'nextKey' => 3], $db->getShardInfo('small'));
        $db->insert('top', [['n' => 1], ['n' => 2], ['n' => 3]]);
        $db->delete('top', ['key' => 2]);
        $this->assertSame(['sharded' => false, 'shards' => 0, 'totalRecords' => 2, 'shardSize' => 4, 'nextKey' => 3], $db->getShardInfo('top'));

        $db->insert('big', array_map(function ($n) { return ['n' => $n]; }, range(0, 9))); // keys 0-9, shards 0-2
        $db->delete('big', ['n' => 5]);
        $db->delete('big', ['n' => 6]);
        $this->assertSame(['sharded' => true, 'shards' => 3, 'totalRecords' => 8, 'deletedCount' => 2, 'shardSize' => 4, 'nextKey' => 10],
            $db->getShardInfo('big'));
        // Every database keeps the size it was created with when the setting changes (STORAGE_FORMAT.md §4.1)
        $this->assertSame(4, $this->db(['shardSize' => 100])->getShardInfo('big')['shardSize']);
        $this->assertSame(4, $this->db(['shardSize' => 100])->getShardInfo('small')['shardSize']);
        $this->db(['shardSize' => 100])->insert('small', [['n' => 4], ['n' => 5]]); // key 4: the fifth key reaches 4
        $this->assertSame(['sharded' => true, 'shards' => 2, 'totalRecords' => 4, 'deletedCount' => 0, 'shardSize' => 4, 'nextKey' => 5],
            $this->db(['shardSize' => 100])->getShardInfo('small'), 'sharded at its own size, not the setting');
        $this->db(['shardSize' => 100])->insert('fresh', ['n' => 1]);
        $this->assertSame(100, $this->db(['shardSize' => 4])->getShardInfo('fresh')['shardSize'], 'a new database takes the setting of its creation');
    }

    /** @test docs/API.md convertToSharded(): "converted", "already_sharded" (success true), "database_not_found" */
    public function convertToShardedReportsEachStatus(): void
    {
        $db = $this->db(['shardSize' => 3, 'shardingEnabled' => false]);
        $this->assertSame(['success' => false, 'status' => 'database_not_found'], $db->convertToSharded('none'));
        $db->insert('mg', array_map(function ($n) { return ['n' => $n]; }, range(0, 6)));
        $this->assertFalse($db->getShardInfo('mg')['sharded'], 'shardingEnabled off');
        $this->assertSame(['success' => true, 'status' => 'converted'], $db->convertToSharded('mg'));
        $this->assertSame(['sharded' => true, 'shards' => 3, 'totalRecords' => 7, 'deletedCount' => 0, 'shardSize' => 3, 'nextKey' => 7],
            $db->getShardInfo('mg'));
        $this->assertSame(range(0, 6), array_column($db->find('mg', 0), 'n'));
        $this->assertSame(range(0, 6), array_column($db->find('mg', 0), 'key'), 'keys preserved');
        $this->assertSame(['success' => true, 'status' => 'already_sharded'], $db->convertToSharded('mg'));

        // Records waiting in the buffer are flushed first and switched with the rest
        $buffered = $this->db(['shardSize' => 3, 'bufferEnabled' => true]); // the flush itself reaches shardSize
        $buffered->insert('mb', array_map(function ($n) { return ['n' => $n]; }, range(0, 4)));
        $this->assertSame(['success' => true, 'status' => 'converted'], $buffered->convertToSharded('mb'));
        $this->assertSame(range(0, 4), array_column($buffered->find('mb', 0), 'n'));
        $this->assertSame(2, $buffered->getShardInfo('mb')['shards']);
    }

    /**
     * @test docs/API.md compact(): exact result fields; "Keys never change; shards that became empty are removed,
     * except the shard of the newest key"
     */
    public function compactResultAndShardRemoval(): void
    {
        $db = $this->db(['shardSize' => 3]);
        $this->assertSame(['success' => false, 'freedSlots' => 0, 'status' => 'database_not_found'], $db->compact('none'));

        // Non-sharded (automatic compaction off): 4 records, one updated twice and one deleted. CLAUDE.md "d":
        // garbage lines = superseded and deleted record lines = 2 + 1, which compaction removes
        $plain = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 1000]);
        $plain->insert('cp', [['n' => 1], ['n' => 2], ['n' => 3], ['n' => 4]]);
        $plain->update('cp', [['n' => 1], ['set' => ['m' => 1]]]);
        $plain->update('cp', [['n' => 1], ['set' => ['m' => 2]]]);
        $plain->delete('cp', ['n' => 4]);
        $this->assertCount(7, $this->dataLines($this->dbPath('cp', 'data')), '4 inserted lines + 2 updated versions + 1 tombstone');
        $this->assertSame(['success' => true, 'freedSlots' => 3, 'totalRecords' => 3, 'sharded' => false], $plain->compact('cp'));
        // The live records, then a tombstone for the highest key ever used (STORAGE_FORMAT.md 6.2: "It writes a
        // top tombstone when the highest live key is below the segment's highest assigned key")
        $lines = $this->dataLines($this->dbPath('cp', 'data'));
        $this->assertCount(4, $lines);
        $this->assertSame('{"__nonedb_deleted":3}', $lines[3]);
        $this->assertSame([0, 1, 2], array_column($plain->find('cp', 0), 'key'));
        $this->assertEquals([['n' => 1, 'm' => 2, 'key' => 0], ['n' => 2, 'key' => 1], ['n' => 3, 'key' => 2]], $plain->find('cp', 0));

        // Sharded (deletes only: freedSlots = deleted records, docs/API.md example with getShardInfo's deletedCount)
        $db = $this->db(['shardSize' => 3, 'autoCompactThreshold' => 1000]);
        // One shard only: nothing to remove
        $db->insert('one', [['n' => 0], ['n' => 1], ['n' => 2]]);
        $db->delete('one', ['n' => 1]);
        $this->assertTrue($db->getShardInfo('one')['sharded']);
        $this->assertSame(['success' => true, 'freedSlots' => 1, 'totalRecords' => 2, 'sharded' => true, 'newShardCount' => 1], $db->compact('one'));
        $this->assertSame([0, 2], array_column($db->find('one', 0), 'key'));

        // Sharded: shard 1 (keys 3-5) emptied and removed, shard 2 (newest key) kept although empty too
        $db->insert('many', array_map(function ($n) { return ['n' => $n]; }, range(0, 8)));
        foreach (range(3, 8) as $n) {
            $db->delete('many', ['n' => $n]);
        }
        $result = $db->compact('many');
        $this->assertSame(['success' => true, 'freedSlots' => 6, 'totalRecords' => 3, 'sharded' => true, 'newShardCount' => 2], $result);
        $this->assertFileDoesNotExist($this->dbPath('many', 'data', 1));
        $this->assertFileExists($this->dbPath('many', 'data', 2));
        $db->insert('many', ['n' => 'new']);
        $this->assertSame(9, $db->find('many', ['n' => 'new'])[0]['key'], 'keys are never reused');
        $this->assertSame([0, 1, 2, 9], array_column($db->find('many', 0), 'key'));
    }

    /** @test docs/API.md getBufferInfo(): limits and the buffer file */
    public function bufferInfoListsEveryBuffer(): void
    {
        $db = $this->db(['shardSize' => 4, 'bufferEnabled' => true]);
        $this->assertSame(['enabled' => true, 'sizeLimit' => 1048576, 'countLimit' => 10000, 'flushInterval' => 30,
            'buffers' => ['main' => ['exists' => false, 'size' => 0, 'records' => 0]]], $db->getBufferInfo('none'));
        $this->assertSame([], glob($this->testDbDir . '*'), 'reading buffer info of a missing database creates nothing');

        $db->insert('bi', array_map(function ($n) { return ['n' => $n]; }, range(0, 5)));
        $db->flush('bi');
        $db->insert('bi', [['n' => 6], ['n' => 7]]);
        clearstatcache();
        $info = $db->getBufferInfo('bi');
        $this->assertSame(['exists' => true, 'size' => filesize($this->dbPath('bi', 'buffer')), 'records' => 2], $info['buffers']['main']);
        $this->assertSame(['main'], array_keys($info['buffers']));
        $this->assertFileExists($this->dbPath('bi', 'buffer'), 'getBufferInfo() flushes nothing');
    }

    /**
     * @test flushAllBuffers() called from inside a database operation (e.g. a shutdown function after a fatal
     * error) does nothing instead of waiting for a lock this process holds
     */
    public function flushAllBuffersInsideAnOperationDoesNothing(): void
    {
        $db = $this->db(['bufferEnabled' => true]);
        $db->insert('fa', ['v' => 1]);
        $result = $this->locked($db, 'fa', false, function () use ($db) {
            return $db->flushAllBuffers();
        });
        $this->assertSame(['databases' => 0, 'flushed' => 0], $result);
        $this->assertSame(1, $db->getBufferInfo('fa')['buffers']['main']['records'], 'still buffered');
        $this->assertSame(['databases' => 1, 'flushed' => 1], $db->flushAllBuffers());
        $this->assertSame(0, $db->getBufferInfo('fa')['buffers']['main']['records']);
    }

    // ==========================================
    // checkIntegrity(): read-only, one report entry per kind of problem
    // ==========================================

    /** docs/API.md checkIntegrity(): the report of a database without problems */
    private function cleanReport(string $name, int $segments, int $lines, int $records): array
    {
        return ['ok' => true, 'database' => $name, 'exists' => true, 'segments' => $segments, 'lines' => $lines, 'records' => $records,
            'badLines' => [], 'tornTail' => [], 'indexMismatches' => [], 'bufferedRecords' => 0, 'pendingRecovery' => false,
            'quarantineFiles' => [], 'recoveryLog' => []];
    }

    /** checkIntegrity() through a fresh instance with no caches, asserting that no file changed (lock files included) */
    private function integrity(string $name, array $config = ['shardingEnabled' => false]): array
    {
        \noneDB::clearStaticCache();
        $before = $this->directorySnapshot();
        $report = $this->db($config)->checkIntegrity($name);
        $this->assertSame($before, $this->directorySnapshot(), 'checkIntegrity() writes nothing, not even the lock state');
        return $report;
    }

    /** Complete lines of a data file with their offsets: [[offset, length without "\n", json], ...] */
    private function lineOffsets(string $file): array
    {
        $lines = [];
        $offset = 0;
        foreach (explode("\n", (string)file_get_contents($file)) as $i => $json) {
            if ($json === '' && $offset >= filesize($file)) {
                break;
            }
            $lines[] = [$offset, strlen($json), $json];
            $offset += strlen($json) + 1;
        }
        return $lines;
    }

    /** @test the report of a sound database and of one that doesn't exist (which isn't created) */
    public function integrityReportOfASoundAndAMissingDatabase(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 1000]); // no automatic compaction
        $db->insert('ic', [['c' => 'x'], ['c' => 'y'], ['c' => 'z']]);
        $db->update('ic', [['c' => 'y'], ['set' => ['d' => 1]]]);
        $db->delete('ic', ['c' => 'z']);
        // 3 records + 1 updated version + 1 tombstone = 5 lines, 2 live records
        $this->assertSame($this->cleanReport('ic', 1, 5, 2), $this->integrity('ic'));

        $before = glob($this->testDbDir . '*');
        $this->assertSame(['ok' => true, 'database' => 'missing', 'exists' => false, 'segments' => 0, 'lines' => 0, 'records' => 0,
            'badLines' => [], 'tornTail' => [], 'indexMismatches' => [], 'bufferedRecords' => 0, 'pendingRecovery' => false,
            'quarantineFiles' => [], 'recoveryLog' => []], $db->checkIntegrity('missing'));
        $this->assertSame($before, glob($this->testDbDir . '*'), 'no lock file, nothing');
    }

    /**
     * @test each kind of key index problem is reported as a "key" mismatch of its segment, read-only, and
     * rebuildIndex() fixes it (docs/CONFIGURATION.md "Recovery log and quarantine"; docs/API.md)
     */
    public function integrityReportsKeyIndexProblems(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('ki', [['c' => 'x'], ['c' => 'y'], ['c' => 'z']]);
        $db->rebuildIndex('ki'); // a checkpoint that covers the whole data file
        $data = $this->dbPath('ki', 'data');
        $ckpt = $this->dbPath('ki', 'kix');
        $checkpoint = $this->readKeyIndexFile($ckpt);
        $this->assertSame(filesize($data), $checkpoint['header']['s'], 'set up: the checkpoint covers the file');
        $size = filesize($data);
        $lines = $this->lineOffsets($data);
        $this->assertCount(3, $lines);
        $edited = $checkpoint['entries'];
        unset($edited[1]);
        $edited[9] = [0, $lines[0][1]];
        $cases = [
            'unreadable' => [function () use ($ckpt) { file_put_contents($ckpt, '{"kind":"kix","v":1,"lay'); }, null],
            'beyond the data' => [function () use ($ckpt, $checkpoint, $size) {
                $this->writeKeyIndexFile($ckpt, ['s' => $size + 10] + $checkpoint['header'], $checkpoint['entries']);
            }, null],
            'not an index' => [function () use ($ckpt) { file_put_contents($ckpt, '[1,2]'); }, null],
            'other entries' => [function () use ($ckpt, $checkpoint, $edited) {
                $this->writeKeyIndexFile($ckpt, $checkpoint['header'], $edited);
            }, ['1 record', '1 indexed record']],
        ];
        foreach ($cases as $label => [$write, $numbers]) {
            $write();
            $report = $this->integrity('ki');
            $this->assertFalse($report['ok'], $label);
            $this->assertCount(1, $report['indexMismatches'], $label . ': ' . json_encode($report['indexMismatches']));
            $mismatch = $report['indexMismatches'][0];
            $this->assertSame(['main', 'key'], [$mismatch['segment'], $mismatch['index']], $label);
            $this->assertNotSame('', (string)$mismatch['detail'], $label);
            foreach ($numbers ?? [] as $number) {
                $this->assertStringContainsString($number, $mismatch['detail'], $label);
            }
            $this->assertSame(3, $report['records'], $label . ': records come from the data');
            $this->assertSame(3, $report['lines'], $label);

            $this->assertTrue($this->db(['shardingEnabled' => false])->rebuildIndex('ki')['success'], $label);
            $this->assertSame($this->cleanReport('ki', 1, 3, 3), $this->integrity('ki'), $label . ': repaired');
        }
    }

    /** @test a spatial index whose entries differ from the records is reported; rebuildIndex() fixes it */
    public function integrityReportsSpatialIndexProblems(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('isp', [['loc' => ['type' => 'Point', 'coordinates' => [1, 1]]], ['loc' => ['type' => 'Point', 'coordinates' => [2, 2]]]]);
        $db->createSpatialIndex('isp', 'loc');
        $this->assertSame($this->cleanReport('isp', 1, 2, 2), $this->integrity('isp'));
        $rx = $this->dbPath('isp', 'rx', 'loc');
        [$header, $nodes] = $this->readRTreeFile($rx);
        $removed = 0;
        foreach ($nodes as &$node) {
            foreach ($node as $i => $part) {
                if (is_array($part) && isset($part[0]) && is_array($part[0]) && count($part[0]) === 5) {
                    $before = count($part);
                    $node[$i] = array_values(array_filter($part, function ($entry) { return $entry[0] !== 1; }));
                    $removed += $before - count($node[$i]);
                }
            }
        }
        unset($node);
        $this->assertSame(1, $removed, 'set up: the leaf entry of key 1 removed');
        $this->writeRTreeFile($rx, $header, $nodes);
        $report = $this->integrity('isp');
        $this->assertFalse($report['ok']);
        $this->assertSame([['main', 'spatial loc']], array_map(function ($m) { return [$m['segment'], $m['index']]; }, $report['indexMismatches']));
        $this->db(['shardingEnabled' => false])->rebuildIndex('isp');
        $this->assertSame($this->cleanReport('isp', 1, 2, 2), $this->integrity('isp'));
        $this->assertSame([0, 1], array_column($this->db(['shardingEnabled' => false])->withinBBox('isp', 'loc', 0, 0, 3, 3), 'key'));
    }

    /** @test shard meta disagreeing with the shard files: each case is one "meta" mismatch (segment null) */
    public function integrityReportsShardMetaProblems(): void
    {
        $config = ['shardSize' => 3];
        $setup = function () use ($config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->insert('ish', array_map(function ($n) { return ['n' => $n]; }, range(0, 7))); // keys 0-7: shards 0, 1, 2
            $this->assertTrue($db->getShardInfo('ish')['sharded']);
            return $this->dbPath('ish', 'shards');
        };
        $lineCount = function () {
            $n = 0;
            foreach (glob($this->dbPath('ish', 'gen') . 'segments/s*.jsonl') as $shard) {
                $n += count($this->dataLines($shard));
            }
            return $n;
        };
        $metaPath = $setup();
        $this->assertSame($this->cleanReport('ish', 3, $lineCount(), 8), $this->integrity('ish', $config));

        $cases = [
            'a shard the files lack' => [function (array $m) { $m['shards'][] = ['id' => 5, 'count' => 0, 'deleted' => 0]; return json_encode($m); }, []],
            'wrong totalRecords' => [function (array $m) { $m['totalRecords'] = 99; return json_encode($m); }, ['99', '8']],
            'nextKey behind the data' => [function (array $m) { $m['nextKey'] = 3; return json_encode($m); }, ['3', '7']],
        ];
        foreach ($cases as $label => [$edit, $numbers]) {
            $metaPath = $setup();
            $lines = $lineCount();
            file_put_contents($metaPath, $edit(json_decode((string)file_get_contents($metaPath), true)));
            $report = $this->integrity('ish', $config);
            $this->assertFalse($report['ok'], $label);
            $this->assertSame([[null, 'meta']], array_map(function ($m) { return [$m['segment'], $m['index']]; }, $report['indexMismatches']),
                $label . ': ' . json_encode($report['indexMismatches']));
            foreach ($numbers as $number) {
                $this->assertStringContainsString($number, $report['indexMismatches'][0]['detail'], $label);
            }
            $this->assertSame([3, $lines, 8], [$report['segments'], $report['lines'], $report['records']], $label . ': counted from the shard files');
        }

        // A shard file that is gone: the meta lists it, its records are missing
        $setup();
        unlink($this->dbPath('ish', 'data', 1));
        @unlink($this->dbPath('ish', 'kix', 1));
        $report = $this->integrity('ish', $config);
        $this->assertSame([[null, 'meta'], [null, 'meta']], array_map(function ($m) { return [$m['segment'], $m['index']]; }, $report['indexMismatches']));
        $this->assertSame([2, 5], [$report['segments'], $report['records']]);
        \noneDB::clearStaticCache();
        $this->assertSame(5, $this->db($config)->getIndexInfo('ish')['totalRecords'], 'the key indexes that exist');
    }

    /**
     * @test an unreadable shard meta is reported ("meta file unreadable") and, like everything else, left as it
     * is: docs/API.md "Read-only: it creates, changes, cuts and flushes nothing"; CHANGES "writes nothing, not
     * even a lock-state change"
     */
    public function integrityOfAnUnreadableShardMetaWritesNothing(): void
    {
        $config = ['shardSize' => 3];
        $db = $this->db($config);
        $db->insert('um', array_map(function ($n) { return ['n' => $n]; }, range(0, 7)));
        $metaPath = $this->dbPath('um', 'shards');
        file_put_contents($metaPath, 'garbage{');
        $report = $this->integrity('um', $config); // asserts that no file changed
        $this->assertSame([[null, 'meta']], array_map(function ($m) { return [$m['segment'], $m['index']]; }, $report['indexMismatches']));
        $this->assertSame([3, 8], [$report['segments'], $report['records']]);
        $this->assertSame('garbage{', file_get_contents($metaPath));
    }

    /**
     * @test pendingRecovery: "the lock file says a recovery is due" (journal j/J, STORAGE_FORMAT.md 7.3);
     * checkIntegrity() reports it without doing it; the next access does it
     */
    public function integrityReportsPendingRecoveryWithoutRecovering(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('pr', [['v' => 1], ['v' => 2]]);
        $lock = $this->dbPath('pr', 'lock');
        $record = trim($this->lockRecord('pr'));
        $this->assertMatchesRegularExpression('/^\d+:[0-9a-f]{8}$/', $record);
        [$state] = explode(' ', $record);
        foreach (['journal' => $state . ' J', 'segment journal' => $state . ' j=main'] as $label => $content) {
            \noneDB::clearStaticCache();
            file_put_contents($lock, str_pad($content, 128));
            $report = $this->integrity('pr');
            $expected = $this->cleanReport('pr', 1, 2, 2);
            $expected['ok'] = false;
            $expected['pendingRecovery'] = true;
            $this->assertSame($expected, $report, $label);
            $this->assertSame(str_pad($content, 128), file_get_contents($lock), $label . ': still pending');
            // A normal access recovers
            $this->assertSame([1, 2], array_column($this->db(['shardingEnabled' => false])->find('pr', 0), 'v'), $label);
            $this->assertSame([], $this->lockState('pr')['flags'], $label);
            $this->assertSame($this->cleanReport('pr', 1, 2, 2), $this->integrity('pr'), $label);
        }
    }

    /** @test buffered records (buffer and interrupted flush files), quarantine files and the recovery log are listed */
    public function integrityListsBuffersQuarantineAndRecoveryLog(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'bufferEnabled' => true]);
        $db->insert('bq', ['v' => 1]);
        $db->flush('bq');
        $db->insert('bq', [['v' => 2], ['v' => 3]]);
        file_put_contents($this->dbPath('bq', 'flushing', 7), json_encode(['v' => 4]) . "\n");
        $report = $this->integrity('bq', ['shardingEnabled' => false, 'bufferEnabled' => true]);
        $this->assertSame(3, $report['bufferedRecords'], 'two in the buffer, one in an interrupted flush');
        $this->assertTrue($report['ok'], 'buffered records are no problem');
        unlink($this->dbPath('bq', 'flushing', 7));

        // A torn tail cut by the next writer: quarantined under the documented name, logged
        $data = $this->dbPath('bq', 'data');
        $db->flush('bq');
        clearstatcache();
        $offset = filesize($data);
        file_put_contents($data, '{"torn', FILE_APPEND);
        \noneDB::clearStaticCache();
        $torn = $this->integrity('bq');
        $this->assertSame([['segment' => 'main', 'offset' => $offset, 'length' => 6]], $torn['tornTail']);
        $this->assertFalse($torn['ok']);
        $this->db(['shardingEnabled' => false])->insert('bq', ['v' => 5]);
        // STORAGE_FORMAT.md 7.2: recovery/q.{segment}.{offset}.{length}.{sha256}.bin, segment "main" for the single layout
        $name = 'q.main.' . $offset . '.6.' . hash('sha256', '{"torn') . '.bin';
        $this->assertSame('{"torn', file_get_contents($this->dbPath('bq', 'quarantine') . $name));
        $report = $this->integrity('bq');
        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['tornTail']);
        $this->assertSame([$name], $report['quarantineFiles']);
        $this->assertCount(1, $report['recoveryLog']);
        $this->assertSame(['cut-tail', $name, true], [$report['recoveryLog'][0]['action'], $report['recoveryLog'][0]['quarantine'], $report['recoveryLog'][0]['bytesVerified']]);
        $this->assertSame([1, 2, 3, 5], array_column($this->db(['shardingEnabled' => false])->find('bq', 0), 'v'));
    }

    // ==========================================
    // getRecoveryLog(): actions, pending entries, rotation, limit
    // ==========================================

    /**
     * @test docs/API.md getRecoveryLog(): "Reads never write the log. A damaged line a read found is listed with
     * "persisted" => false until a write session of the same process logs it (once: entries carry an id);
     * after the process ends it is found again by the next read"
     */
    public function damagedLinesFoundByReadsArePendingUntilAWriteLogsThemOnce(): void
    {
        $config = ['shardingEnabled' => false];
        $db = $this->db($config);
        $db->insert('pend', [['v' => 1], ['v' => 2]]);
        $data = $this->dbPath('pend', 'data');
        $log = $this->dbPath('pend', 'recovery');
        clearstatcache();
        $offset = filesize($data);
        file_put_contents($data, "not json\n", FILE_APPEND);
        // Every pending entry describes the same damaged line: off/len are its bytes, id names them
        // ({segment}.{offset}.{length}.{sha256}, STORAGE_FORMAT.md 7.2)
        $id = 'main.' . $offset . '.9.' . hash('sha256', "not json\n");

        for ($round = 1; $round <= 2; $round++) {
            \noneDB::clearStaticCache(); // a new process
            $db = $this->db($config);
            $this->assertSame(range(1, $round + 1), array_column($db->find('pend', 0), 'v'), "round $round: the damaged line is skipped");
            $entries = $db->getRecoveryLog('pend');
            $pending = end($entries);
            $this->assertSame(['skip-line', 'main', $offset, 9, $id, false],
                [$pending['action'], $pending['seg'], $pending['off'], $pending['len'], $pending['id'], $pending['persisted']], "round $round");
            $this->assertCount($round, $entries, "round $round: the persisted entries of earlier rounds and this pending one");
            if ($round === 1) {
                $this->assertFileDoesNotExist($log, 'reads never write the log');
            }

            $db->insert('pend', ['v' => 2 + $round]); // a write session of this process logs it (once)
            $entries = $db->getRecoveryLog('pend');
            $this->assertCount(1, $entries, "round $round: logged once, deduplicated by id");
            $this->assertSame([$id, 'skip-line'], [$entries[0]['id'], $entries[0]['action']]);
            $this->assertArrayNotHasKey('persisted', $entries[0]);
            $this->assertCount(1, $this->dataLines($log));
        }
        $this->assertSame([1, 2, 3, 4], array_column($db->find('pend', 0), 'v'));
    }

    /**
     * @test STORAGE_FORMAT.md 7.1: the log is recovery/log.jsonl; past 1 MiB it moves to recovery/log.1.jsonl,
     * replacing the older one; getRecoveryLog() returns the newest $limit entries, oldest first
     */
    public function recoveryLogRotatesPastOneMebibyte(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 0.1]);
        $db->insert('rot', array_map(function ($n) { return ['n' => $n]; }, range(1, 10)));
        $log = $this->dbPath('rot', 'recovery');
        $log1 = $this->dbPath('rot', 'recovery1');
        @mkdir(dirname($log), 0777, true);
        file_put_contents($log1, json_encode(['t' => 1, 'action' => 'oldest']) . "\n");
        $old = '';
        for ($i = 0; strlen($old) <= 1048576; $i++) {
            $old .= json_encode(['t' => 2, 'action' => 'old', 'i' => $i, 'pad' => str_repeat('p', 200)]) . "\n";
        }
        file_put_contents($log, $old);
        $oldCount = $i;

        // An event to log: a deferred compaction that fails
        $this->setFaultHook(function ($point) {
            if ($point === 'job:compact:main') {
                throw new \RuntimeException('compaction failed');
            }
        });
        $this->assertSame(10, $db->update('rot', [[], ['set' => ['m' => 1]]])['n']);
        $this->setFaultHook(null);

        $this->assertSame($old, file_get_contents($log1), 'the full log moved, replacing the older one');
        $current = $this->dataLines($log);
        $this->assertCount(1, $current);
        $this->assertSame(['deferred-failed', 'compact:main', 'compaction failed'],
            array_values(array_intersect_key(json_decode($current[0], true), ['action' => 1, 'job' => 1, 'error' => 1])));

        $entries = $db->getRecoveryLog('rot', 3);
        $this->assertSame([['old', $oldCount - 2], ['old', $oldCount - 1], ['deferred-failed', null]],
            array_map(function ($e) { return [$e['action'], $e['i'] ?? null]; }, $entries));
        $this->assertCount(100, $db->getRecoveryLog('rot'), 'default limit 100');
        $this->assertSame('deferred-failed', $db->getRecoveryLog('rot', 1)[0]['action']);
        $this->assertCount($oldCount + 1, $db->getRecoveryLog('rot', 1000000));
    }

    /** @test getRecoveryLog($db, 0): "$limit Newest entries to return" -> none */
    public function recoveryLogLimitZeroReturnsNothing(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('lim', ['v' => 1]);
        @mkdir(dirname($this->dbPath('lim', 'recovery')), 0777, true);
        file_put_contents($this->dbPath('lim', 'recovery'), json_encode(['t' => 1, 'action' => 'cut-tail']) . "\n"
            . json_encode(['t' => 2, 'action' => 'drop-line']) . "\n");
        $this->assertSame(['drop-line'], array_column($db->getRecoveryLog('lim', 1), 'action'));
        $this->assertSame(['cut-tail', 'drop-line'], array_column($db->getRecoveryLog('lim', 2), 'action'));
        $this->assertSame([], $db->getRecoveryLog('lim', 0));
    }

    // ==========================================
    // getDurability()
    // ==========================================

    /** @test docs/DURABILITY.md §6 getDurability(): the documented fields, and what the report says in performance mode */
    public function durabilityReportFieldsInPerformanceMode(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $report = $db->getDurability();
        $this->assertSame(['mode', 'platform', 'lastOperation', 'lastSyncError', 'syncs'], array_keys($report));
        $this->assertSame('performance', $report['mode'], 'the default mode');
        $this->assertSame(['os' => PHP_OS_FAMILY, 'durableSupported' => PHP_OS_FAMILY === 'Linux'],
            array_intersect_key($report['platform'], ['os' => 0, 'durableSupported' => 0]));
        $this->assertCount(PHP_OS_FAMILY === 'Linux' ? 0 : 1, $report['platform']['notes'], json_encode($report['platform']['notes']));
        $this->assertSame(['file', 'dir', 'failed'], array_keys($report['syncs']));

        $syncs = $report['syncs'];
        $db->insert('dura', ['v' => 1]);
        $last = $db->getDurability()['lastOperation'];
        $this->assertSame(['op', 'db', 'outcome', 'required', 'completed', 'reason'], array_keys($last));
        $this->assertSame(['insert', 'dura', 'committed', [], [], null], array_values($last), 'performance mode requires no sync');
        $this->assertSame($syncs, $db->getDurability()['syncs'], 'nothing synced');

        // "the last write operation": reads and writes that change nothing don't replace it
        $db->insert('other', ['v' => 1]);
        $db->find('dura', 0);
        $db->update('other', [['v' => 99], ['set' => ['w' => 1]]]);
        $this->assertSame(['insert', 'other'], [$db->getDurability()['lastOperation']['op'], $db->getDurability()['lastOperation']['db']]);
    }

    /**
     * @test docs/DURABILITY.md §3: in durable mode "Set a state-record flag (b, ..., j) ... sync file. This always
     * happens before the data change it protects"; a write that sets no flag doesn't sync the record
     */
    public function lockStateRecordIsSyncedWhenItProtectsData(): void
    {
        $this->needsDirectorySyncs();
        $this->setStatic('osFamily', 'Linux'); // durable mode's rules, on every platform
        $synced = [];
        $this->setStatic('syncFn', function ($handle, string $kind) use (&$synced) {
            $stat = fstat($handle);
            $synced[] = $kind . ':' . $stat['ino'];
            return true;
        });
        $lockSynced = function (string $name) use (&$synced) {
            clearstatcache();
            return in_array('file:' . fileinode($this->dbPath($name, 'lock')), $synced, true);
        };
        $plain = $this->db(['durability' => 'durable', 'shardingEnabled' => false]);
        $plain->insert('plain', ['v' => 1]);
        $synced = [];
        $plain->insert('plain', ['v' => 2]);
        $this->assertContains('file:' . fileinode($this->dbPath('plain', 'data')), $synced, 'the data');
        $this->assertFalse($lockSynced('plain'), 'no flag set: the state record is not synced');

        $buffered = $this->db(['durability' => 'durable', 'shardingEnabled' => false, 'bufferEnabled' => true]);
        $buffered->insert('buf', ['v' => 1]);
        $synced = [];
        $buffered->insert('buf', ['v' => 2]);
        $this->assertContains('b', $this->lockState('buf')['flags']);
        $this->assertTrue($lockSynced('buf'), 'buffer flag set: synced');

        $sharded = $this->db(['durability' => 'durable', 'shardSize' => 2]);
        $sharded->insert('sh', [['v' => 1], ['v' => 2]]);
        $synced = [];
        $sharded->insert('sh', ['v' => 3]); // a sharded write sets the journal
        $this->assertTrue($lockSynced('sh'), 'journal set: synced');
    }

    // ==========================================
    // migrateKey(): refusals, interruption, caches
    // ==========================================

    /** @test every refusal returns success false with an error and renames nothing */
    public function migrateKeyRefusals(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('mk', ['v' => 1]);
        $new = 'new-secret-key-0123456789abcdef';
        // A second key of the store (STORAGE_FORMAT.md 9.3) with a database "mk": the target of mk under the new key
        $this->assertTrue(\noneDB::registerKey($this->testDbDir, self::SECRET, $new)['success']);
        $other = new \noneDB(['secretKey' => $new, 'dbDir' => $this->testDbDir]);
        $other->insert('mk', ['v' => 'already there']);
        \noneDB::clearStaticCache();
        $before = $this->directorySnapshot();
        $cases = [
            'empty old key' => [$this->testDbDir, '  ', $new, null],
            'empty new key' => [$this->testDbDir, self::SECRET, '', null],
            'same key' => [$this->testDbDir, self::SECRET, self::SECRET, null],
            'missing directory' => [$this->testDbDir . 'nope/', self::SECRET, $new, $this->testDbDir . 'nope/'],
            'target exists' => [$this->testDbDir, self::SECRET, $new, "'mk'"],
        ];
        foreach ($cases as $label => [$dir, $old, $newKey, $mentioned]) {
            $result = \noneDB::migrateKey($dir, $old, $newKey);
            $this->assertSame(['success' => false, 'databases' => [], 'storeManifest' => null], array_slice($result, 0, 3, true), $label);
            $this->assertIsString($result['error'], $label);
            $this->assertNotSame('', $result['error'], $label);
            if ($mentioned !== null) {
                $this->assertStringContainsString($mentioned, $result['error'], $label);
            }
            $this->assertSame($before, $this->directorySnapshot(), $label . ': nothing renamed');
        }

        // A database whose lock can't be taken (its LOCK can't be opened) is not renamed
        $other->dropDB('mk');
        \noneDB::clearStaticCache();
        // dropDB() keeps LOCK and the directory (STORAGE_FORMAT.md 8.2): removed, so the target is free
        $this->assertTrue(unlink($this->homeOf('mk', null, $new) . 'LOCK'));
        $this->assertTrue(rmdir($this->homeOf('mk', null, $new)));
        $lock = $this->dbPath('mk', 'lock');
        $content = file_get_contents($lock);
        unlink($lock);
        mkdir($lock);
        try {
            $before = $this->directorySnapshot();
            $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, $new);
            $this->assertFalse($result['success']);
            $this->assertSame(\noneDB::MAINTENANCE_BUSY, $result['code'], 'STORAGE_FORMAT.md 9.2: a target lock that fails');
            $this->assertStringContainsString("'mk'", $result['error']);
            $this->assertSame($before, $this->directorySnapshot());
        } finally {
            rmdir($lock);
            file_put_contents($lock, $content);
        }
        \noneDB::clearStaticCache();
        $this->assertSame([1], array_column($this->db(['shardingEnabled' => false])->find('mk', 0), 'v'));
    }

    /**
     * @test docs/API.md migrateKey(): "If it is interrupted, run it again with the same arguments: it finishes
     * the job". STORAGE_FORMAT.md 9.1-9.3: interrupted after marking its targets, between and after the renames
     * of the database directories, after its commit or after the STORE copies, the rotation leaves its journal
     * open: the store answers MAINTENANCE_IN_PROGRESS for every key until the next run finishes it
     */
    public function migrateKeyInterruptedInThisProcessFinishesOnTheNextRun(): void
    {
        $setup = function () {
            $this->cleanTestDirectory();
            $db = $this->db(['shardSize' => 3]);
            $db->createFieldIndex('k1', 'c');
            $db->insert('k1', array_map(function ($n) { return ['n' => $n, 'c' => 'c' . ($n % 2)]; }, range(0, 6)));
            $db->insert('k2', [['v' => 'a'], ['v' => 'b']]);
            \noneDB::clearStaticCache();
            return ['k1' => $this->filesOfDb('k1', null, self::SECRET, true), 'k2' => $this->filesOfDb('k2', null, self::SECRET, true)];
        };
        $new = 'brand-new-key-for-migration-0123456789';
        foreach ([['rotate:marked', 1], ['rotate:renamed', 1], ['rotate:renamed', 2], ['rotate:committed', 1],
                  ['maintenance:store-written', 1]] as [$point, $count]) {
            $label = "stop at $point #$count";
            $files = $setup();
            $seen = 0;
            $this->setFaultHook(function ($p) use (&$seen, $point, $count) {
                if ($p === $point && ++$seen === $count) {
                    throw new \RuntimeException('interrupted');
                }
            });
            $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, $new);
            $this->setFaultHook(null);
            $this->assertFalse($result['success'], $label);
            $this->assertStringContainsString('interrupted', (string)$result['error'], $label);
            foreach ([self::SECRET, $new] as $key) {
                try {
                    (new \noneDB(['secretKey' => $key, 'dbDir' => $this->testDbDir, 'autoCreateDB' => false]))->find('k2', 0);
                    $this->fail("$label: the store is closed while the journal is open");
                } catch (\RuntimeException $e) {
                    $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $e->getCode(), "$label: " . $e->getMessage());
                }
            }

            $result = \noneDB::migrateKey($this->testDbDir, self::SECRET, $new);
            $this->assertTrue($result['success'], "$label: " . json_encode($result));
            $databases = $result['databases'];
            sort($databases);
            $this->assertSame(['k1', 'k2'], $databases, $label);
            $this->assertSame([], array_merge($this->filesOfDb('k1', null, self::SECRET, true), $this->filesOfDb('k2', null, self::SECRET, true)),
                "$label: nothing left under the old key");
            $this->assertSame($files, ['k1' => $this->filesOfDb('k1', null, $new, true), 'k2' => $this->filesOfDb('k2', null, $new, true)],
                "$label: the directories moved with all their files");
            $db = new \noneDB(['secretKey' => $new, 'dbDir' => $this->testDbDir, 'shardSize' => 3, 'autoCreateDB' => false]);
            $this->assertSame(range(0, 6), array_column($db->find('k1', 0), 'n'), $label);
            $this->assertSame([1, 3, 5], array_column($db->find('k1', ['c' => 'c1']), 'n'), $label);
            $this->assertSame(['a', 'b'], array_column($db->find('k2', 0), 'v'), $label);
        }
    }

    /**
     * @test a rename that fails stops migrateKey() with an error; the started rotation keeps the store closed
     * (STORAGE_FORMAT.md 9.1: MAINTENANCE_IN_PROGRESS); fixed, the next run finishes
     */
    public function migrateKeyThatCannotRenameReportsItAndCanBeRepeated(): void
    {
        $dir = $this->scratch('mkro') . '/';
        $other = 'other-key-0123456789';
        $db = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $dir]);
        $db->insert('ro', [['v' => 1], ['v' => 2]]);
        \noneDB::clearStaticCache();
        $files = $this->filesOfDb('ro', $dir, self::SECRET, true);
        // The rename of the database directory fails
        $this->setStatic('renameFn', function ($from, $to) {
            return substr(rtrim($from, '/'), -5) === '.ndb4' ? false : rename($from, $to);
        });
        try {
            $result = \noneDB::migrateKey($dir, self::SECRET, $other);
        } finally {
            $this->setStatic('renameFn', null);
        }
        $this->assertFalse($result['success']);
        $this->assertSame([], $result['databases']);
        $this->assertStringContainsString('rename', $result['error']);
        $this->assertStringContainsString("'ro'", $result['error']);
        $this->assertSame($files, $this->filesOfDb('ro', $dir, self::SECRET, true), 'nothing renamed');
        $this->assertDirectoryDoesNotExist($this->homeOf('ro', $dir, $other));
        foreach ([self::SECRET, $other] as $key) {
            try {
                (new \noneDB(['secretKey' => $key, 'dbDir' => $dir]))->find('ro', 0);
                $this->fail('the store is closed while the rotation is unfinished');
            } catch (\RuntimeException $e) {
                $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $e->getCode(), $e->getMessage());
            }
        }
        $this->assertTrue(\noneDB::migrateKey($dir, self::SECRET, $other)['success']);
        $this->assertSame([1, 2], array_column((new \noneDB(['secretKey' => $other, 'dbDir' => $dir]))->find('ro', 0), 'v'));
    }

    // ==========================================
    // Interrupted sessions (fatal error, exit())
    // ==========================================

    /**
     * @test CHANGES "Crash safety": "A fatal error in the middle of a write: noneDB's shutdown handler releases
     * the interrupted session, so later writes in shutdown functions start a fresh session with recovery"
     */
    public function shutdownFunctionsAfterAnInterruptedWriteStartAFreshSession(): void
    {
        $out = $this->runChild('
            $db = ' . $this->childDb(['shardingEnabled' => false]) . ';
            $db->insert("sd", ["v" => "first"]);
            register_shutdown_function(function () use ($db) {
                noneDB\Tests\Support\Testing::setFaultHook(null);
                echo "locks held:", count(noneDB\Tests\Support\Testing::get("lockRegistry")), "\n";
                echo "insert:", $db->insert("sd", ["v" => "shutdown"])["n"], "\n";
            });
            noneDB\Tests\Support\Testing::setFaultHook(function ($point) {
                if ($point === "append:after") { exit(3); } // like a fatal error: finally blocks are skipped
            });
            $db->insert("sd", ["v" => "interrupted"]);
            echo "not reached\n";
        ');
        $this->assertSame("locks held:0\ninsert:1", $out);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        // The interrupted line was complete: kept (roll forward); the shutdown write got the next key
        $this->assertSame([['v' => 'first', 'key' => 0], ['v' => 'interrupted', 'key' => 1], ['v' => 'shutdown', 'key' => 2]], $db->find('sd', 0));
        $this->assertSame([], $this->lockState('sd')['flags']);
    }

    // ==========================================
    // Recovery details
    // ==========================================

    /**
     * @test STORAGE_FORMAT.md 5.2: a switch to shards interrupted after its commit point (PUBLISH: CURRENT names
     * the new generation) leaves no second copy of the data: the recovery of g=N>X removes the old generation,
     * with its index files, spatial ones included
     */
    public function aSwitchToShardsInterruptedAfterItsCommitPointWithASpatialIndexIsCleanedUp(): void
    {
        $config = ['shardSize' => 4];
        $db = $this->db($config);
        $db->createSpatialIndex('mgs', 'loc');
        $db->createFieldIndex('mgs', 'c');
        $db->insert('mgs', array_map(function ($n) {
            return ['n' => $n, 'c' => 'c' . ($n % 2), 'loc' => ['type' => 'Point', 'coordinates' => [$n, $n]]];
        }, range(0, 2)));
        $old = $this->dbPath('mgs', 'gen'); // generation 1 of the new database (STORAGE_FORMAT.md 8.1)
        $this->assertFileExists($this->dbPath('mgs', 'rx', 'loc'));
        \noneDB::clearStaticCache();
        $out = $this->runChild('
            $db = ' . $this->childDb($config) . ';
            noneDB\Tests\Support\Testing::setFaultHook(function ($p) { if ($p === "generation:published") { exit(0); } });
            $db->insert("mgs", ["n" => 3, "c" => "c1", "loc" => ["type" => "Point", "coordinates" => [3, 3]]]);
            echo "not reached";
        ');
        $this->assertSame('', $out);
        $this->assertFileExists($old . 'segments/main.jsonl', 'the old file is still there');
        $this->assertContains('g=1>2', $this->lockState('mgs')['flags'], 'the state record says so');

        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(range(0, 3), array_column($db->find('mgs', 0), 'n'));
        $this->assertFileDoesNotExist($old . 'segments/main.jsonl');
        $this->assertFileDoesNotExist($old . 'spatial/main.loc.rx', 'the old segment\'s spatial index went with it');
        $this->assertFileDoesNotExist($old . 'indexes/main.c.fx');
        $got = array_column($db->withinBBox('mgs', 'loc', -1, -1, 10, 10), 'n');
        sort($got);
        $this->assertSame(range(0, 3), $got);
        $this->assertSame([1, 3], array_column($db->find('mgs', ['c' => 'c1']), 'n'));
        $this->assertTrue($db->checkIntegrity('mgs')['ok']);
    }

    /** @test the recovery log is best effort: failing to write it never fails the operation */
    public function aRecoveryLogThatCannotBeWrittenNeverFailsTheOperation(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 0.1]);
        $db->insert('nolog', array_map(function ($n) { return ['n' => $n]; }, range(1, 10)));
        $log = $this->dbPath('nolog', 'recovery');
        $this->setStatic('flushFn', function ($handle, string $path) use ($log) {
            return $path === $log ? false : fflush($handle);
        });
        $this->setFaultHook(function ($point, $context) use ($log) {
            if ($point === 'job:compact:main') {
                throw new \RuntimeException('compaction broke'); // to be logged as "deferred-failed"
            }
            if ($point === 'append:undo' && $context['path'] === $log) {
                throw new \RuntimeException('disk gone');
            }
        });
        $this->assertSame(['n' => 10], $db->update('nolog', [[], ['set' => ['m' => 1]]]));
        $this->setFaultHook(null);
        $this->setStatic('flushFn', null);
        $this->assertSame(array_fill(0, 10, 1), array_column($db->find('nolog', 0), 'm'));
    }

    /**
     * @test field and spatial index files whose body is damaged (header intact) never change a result: checkIntegrity()
     * stays read-only, queries rebuild them from the data
     */
    public function damagedSecondaryIndexBodiesNeverChangeResults(): void
    {
        $config = ['shardingEnabled' => false];
        $db = $this->db($config);
        $db->insert('sec', [['c' => 'x', 'loc' => ['type' => 'Point', 'coordinates' => [1, 1]]], ['c' => 'y', 'loc' => ['type' => 'Point', 'coordinates' => [2, 2]]]]);
        $db->createFieldIndex('sec', 'c');
        $db->createSpatialIndex('sec', 'loc');
        foreach ([$this->dbPath('sec', 'fx', 'c'), $this->dbPath('sec', 'rx', 'loc')] as $index) {
            [$header] = explode("\n", (string)file_get_contents($index), 2);
            file_put_contents($index, $header . "\n" . '{"not": json');
        }
        $report = $this->integrity('sec', $config);
        $this->assertSame([], $report['badLines']);
        $this->assertSame(2, $report['records']);
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(['x'], array_column($db->find('sec', ['c' => 'x']), 'c'));
        $this->assertSame([0, 1], array_column($db->withinBBox('sec', 'loc', 0, 0, 3, 3), 'key'));
        $this->assertSame([1], array_column($db->query('sec')->where(['c' => 'y'])->get(), 'key'));
    }

}
