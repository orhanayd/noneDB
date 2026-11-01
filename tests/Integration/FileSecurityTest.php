<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;
use noneDB\Tests\Support\Testing;

/**
 * File security (docs/STORAGE_FORMAT.md §3, §3.4): the permissions of what noneDB creates, dbDir by
 * its canonical path, database directories that are links, and no key or record value in what noneDB reports.
 */
class FileSecurityTest extends RegressionTestCase
{
    /** @var int|null */
    private $umask;

    /** @var string[] */
    private $outside = [];

    protected function tearDown(): void
    {
        if ($this->umask !== null) {
            umask($this->umask);
            $this->umask = null;
        }
        \noneDB::clearStaticCache();
        foreach ($this->outside as $path) {
            if (is_link($path)) {
                @unlink($path);
            } else {
                $this->removeDirectoryTree($path);
            }
        }
        $this->outside = [];
        parent::tearDown();
    }

    private function outsidePath(string $prefix): string
    {
        $path = realpath(sys_get_temp_dir()) . '/nonedb_' . $prefix . '_' . bin2hex(random_bytes(6));
        $this->outside[] = $path;
        return $path;
    }

    private function posixOnly(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX permissions and links');
        }
    }

    /** relative path => permission bits, of a directory tree (the root itself under '.') */
    private function modes(string $dir): array
    {
        $out = ['.' => fileperms($dir) & 0777];
        $walk = function (string $path, string $rel) use (&$walk, &$out): void {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $path . '/' . $entry;
                $out[$rel . $entry] = fileperms($full) & 0777;
                if (is_dir($full)) {
                    $walk($full, $rel . $entry . '/');
                }
            }
        };
        $walk(rtrim($dir, '/'), '');
        return $out;
    }

    private function code(callable $fn): ?int
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            return $e->getCode();
        }
        return null;
    }

    /** Everything noneDB writes in a store: records, indexes, buffer, recovery log and quarantine, store log */
    private function exercise(\noneDB $db): void
    {
        $db->insert('u', array_map(function ($n) { return ['n' => $n, 'c' => 'c' . ($n % 2), 'loc' => ['type' => 'Point', 'coordinates' => [$n, $n]]]; }, range(0, 9)));
        $db->createFieldIndex('u', 'c');
        $db->createSpatialIndex('u', 'loc');
        $db->delete('u', ['n' => 3]);
        $db->compact('u');
        $db->enableBuffering(true);
        $db->insert('b', [['v' => 1]]);
        $db->enableBuffering(false);
        file_put_contents($this->dbPath('u', 'data', 2), '{"torn', FILE_APPEND); // the shard the next insert appends to
        \noneDB::clearStaticCache();
        $db->insert('u', ['n' => 10]); // the torn tail goes to quarantine, the event to the recovery log
        file_put_contents($this->testDbDir . 'STORE.2', 'x');
        \noneDB::clearStaticCache();
        $db->find('u', 0); // the store copy is repaired, logged in .nonedb-maintenance/log.jsonl
    }

    /** @test STORAGE_FORMAT.md §3: files filePermissions, directories directoryPermissions, whatever the umask */
    public function everythingNoneDBCreatesHasTheConfiguredPermissions(): void
    {
        $this->posixOnly();
        foreach ([[0022, [], 0640, 0750], [0077, ['filePermissions' => 0660, 'directoryPermissions' => '0770'], 0660, 0770]] as [$umask, $config, $file, $dir]) {
            $this->cleanTestDirectory();
            $this->umask = $this->umask ?? umask();
            umask($umask);
            $location = $this->outsidePath('store');
            $this->testDbDir = $location . '/'; // created by noneDB
            $db = $this->db(['shardSize' => 4] + $config);
            $this->exercise($db);
            $modes = $this->modes($location);
            $this->assertArrayHasKey('.nonedb-maintenance/log.jsonl', $modes, 'the store log was written');
            $this->assertNotEmpty(glob($this->dbPath('u', 'quarantine') . 'q.*.bin'), 'a quarantine file was written');
            $this->assertFileExists($this->dbPath('b', 'buffer'));
            foreach ($modes as $path => $mode) {
                $isDir = $path === '.' || is_dir($location . '/' . $path);
                $this->assertSame(sprintf('%o', $isDir ? $dir : $file), sprintf('%o', $mode), "$path (umask " . sprintf('%o', $umask) . ')');
            }
            $this->testDbDir = TEST_DB_DIR;
        }
    }

    /** @test CONFIGURATION.md: owner access needed, nothing world-writable */
    public function invalidPermissionsAreRefused(): void
    {
        foreach ([['filePermissions', 0666], ['filePermissions', 0400], ['filePermissions', 01640], ['filePermissions', 'rw-r-----'],
                  ['filePermissions', '0648'], ['filePermissions', "0640\n"], ['filePermissions', -1], ['filePermissions', 0640.0], ['directoryPermissions', 0640],
                  ['directoryPermissions', 0777], ['directoryPermissions', 0600], ['directoryPermissions', true]] as [$key, $value]) {
            try {
                $this->db([$key => $value]);
                $this->fail("$key " . var_export($value, true) . ': refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
        foreach ([[0600, 0700], [0644, 0755], ['0640', '0750'], ['640', '750'], [0660, 0770]] as [$file, $dir]) {
            $db = $this->db(['filePermissions' => $file, 'directoryPermissions' => $dir]);
            $this->assertSame([is_string($file) ? octdec($file) : $file, is_string($dir) ? octdec($dir) : $dir],
                [Testing::getOf($db, 'filePermissions'), Testing::getOf($db, 'directoryPermissions')]);
        }
    }

    /** @test STORAGE_FORMAT.md §3: two spellings of dbDir are one store, with one lock per file */
    public function dbDirIsUsedByItsCanonicalPath(): void
    {
        $this->posixOnly();
        $real = $this->outsidePath('real');
        mkdir($real);
        $link = $this->outsidePath('link');
        symlink($real, $link);
        $spellings = [$real, $link, $real . '/./', dirname($real) . '/../' . basename(dirname($real)) . '/' . basename($real), $link . '/not-yet/..'];
        foreach ($spellings as $spelling) {
            $db = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $spelling]);
            $this->assertSame($real . '/', Testing::getOf($db, 'dbDir'), $spelling);
        }
        $missing = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $link . '/sub/dir']);
        $this->assertSame($real . '/sub/dir/', Testing::getOf($missing, 'dbDir'), 'the nearest existing parent resolved');

        // One lock per file: a write through one spelling inside a write through the other (one process)
        $a = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $real]);
        $b = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $link, 'lockTimeout' => 0.5]);
        $a->insert('u', ['v' => 'a']);
        Testing::call($a, 'withLock', 'u', true, function () use ($b) {
            $b->insert('u', ['v' => 'b']); // the same lock: re-entered, not waited for
        });
        $this->assertSame(['a', 'b'], array_column($a->find('u', 0), 'v'));
    }

    /** @test STORAGE_FORMAT.md §3.4: a database directory that is a link is not a database */
    public function aDatabaseDirectoryThatIsALinkIsNotADatabase(): void
    {
        $this->posixOnly();
        $db = $this->db();
        $db->insert('u', [['v' => 1]]);
        $db->insert('w', [['v' => 2]]);
        $home = rtrim($this->dbPath('u', 'home'), '/');
        $elsewhere = $this->outsidePath('moved');
        rename($home, $elsewhere);
        symlink($elsewhere, $home);
        \noneDB::clearStaticCache();
        $before = $this->modes($elsewhere) + ['bytes' => md5(serialize(array_map('md5_file', glob($elsewhere . '/generations/*/segments/*'))))];
        $db = $this->db();
        foreach (['find' => function () use ($db) { $db->find('u', 0); },
                  'insert' => function () use ($db) { $db->insert('u', ['v' => 3]); },
                  'count' => function () use ($db) { $db->count('u'); },
                  'a missing database' => function () use ($db) { $db->find('missing', 0); },
                  'a new database' => function () use ($db) { $db->insert('new', ['v' => 1]); }] as $case => $fn) {
            $this->assertSame(\noneDB::UNSUPPORTED_FORMAT, $this->code($fn), $case);
        }
        $after = $this->modes($elsewhere) + ['bytes' => md5(serialize(array_map('md5_file', glob($elsewhere . '/generations/*/segments/*'))))];
        $this->assertSame($before, $after, 'no link followed: nothing written there');
        $this->assertSame(['w'], $db->getDBs(), 'skipped');
        $this->assertSame([2], array_column($db->find('w', 0), 'v'), 'the other databases work');

        // An entry named like a database directory that is a file
        unlink($home);
        file_put_contents($home, 'x');
        \noneDB::clearStaticCache();
        $db = $this->db();
        $this->assertSame(\noneDB::UNSUPPORTED_FORMAT, $this->code(function () use ($db) { $db->find('u', 0); }));
        $this->assertSame(['w'], $db->getDBs());
    }

    /** @test errors, logs and reports hold no key and no record value */
    public function noKeyOrRecordValueIsReported(): void
    {
        $value = 'SENSITIVE-VALUE-91d2';
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('u', [['v' => $value], ['v' => 'other']]);
        // A damaged line holding the value: found, logged and quarantined by the next writer
        file_put_contents($this->dbPath('u', 'data'), '{"v":"' . $value . '","key":7' . "\n" . '{"v":"' . $value . '",', FILE_APPEND);
        unlink($this->dbPath('u', 'kix'));
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $db->rebuildIndex('u');
        $db->insert('u', ['v' => 'after']);
        $texts = [file_get_contents($this->dbPath('u', 'recovery')), json_encode($db->getRecoveryLog('u')), json_encode($db->getMetrics('u')),
            json_encode($db->getDurability()), json_encode($db->checkIntegrity('u'))];
        $messages = [];
        $failing = [
            function () { (new \noneDB(['secretKey' => 'another-secret-key-77', 'dbDir' => $this->testDbDir]))->find('u', 0); },
            function () { $this->db(['secretKey' => 'your_key']); },
            function () { file_put_contents($this->dbPath('u', 'current'), 'x'); \noneDB::clearStaticCache(); $this->db()->find('u', 0); },
            function () { $this->db()->restore($this->testDbDir, 'u'); },
        ];
        foreach ($failing as $fn) {
            try {
                $fn();
            } catch (\Exception $e) {
                $messages[] = get_class($e) . ': ' . $e->getMessage();
            }
        }
        $this->assertCount(4, $messages);
        foreach (array_merge($texts, $messages) as $i => $text) {
            $this->assertStringNotContainsString(self::SECRET, (string)$text, "text $i");
            $this->assertStringNotContainsString('another-secret-key-77', (string)$text, "text $i");
            $this->assertStringNotContainsString($value, (string)$text, "text $i");
        }
        $this->assertNotEmpty(array_filter(array_map(function ($l) { return json_decode($l, true)['action'] ?? null; },
            explode("\n", trim($texts[0])))), 'events were logged');
    }
}
