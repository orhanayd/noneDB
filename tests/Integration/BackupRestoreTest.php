<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * backup() and restore() (docs/STORAGE_FORMAT.md §10): the backup directory and its manifest, what a backup
 * leaves alone, the identity rules, the next key, the buffer, refusals, damaged databases. Expected values
 * come from the format document: files are read with the test's own codecs, never with the engine.
 */
class BackupRestoreTest extends RegressionTestCase
{
    /** @var string[] directories made outside dbDir by the test */
    private $outside = [];

    protected function tearDown(): void
    {
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

    // ------------------------------------------------------------------ helpers

    /** A path outside dbDir for a backup directory (not created) */
    private function backupDir(): string
    {
        $dir = sys_get_temp_dir() . '/nonedb_backup_' . bin2hex(random_bytes(6));
        $this->outside[] = $dir;
        return $dir;
    }

    /** path => sha256 (files) or 'dir', for a directory tree */
    private function snapshot(string $dir): array
    {
        $out = [];
        $walk = function (string $path, string $rel) use (&$walk, &$out): void {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $path . '/' . $entry;
                if (is_dir($full) && !is_link($full)) {
                    $out[$rel . $entry] = 'dir';
                    $walk($full, $rel . $entry . '/');
                } else {
                    $out[$rel . $entry] = hash_file('sha256', $full);
                }
            }
        };
        if (is_dir($dir)) {
            $walk(rtrim($dir, '/'), '');
        }
        ksort($out);
        return $out;
    }

    /** Files of a directory tree, relative, sorted by byte order */
    private function filesOf(string $dir): array
    {
        $files = array_keys(array_filter($this->snapshot($dir), function ($v) { return $v !== 'dir'; }));
        sort($files, SORT_STRING);
        return $files;
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

    private function records(\noneDB $db, string $name): array
    {
        $records = $db->find($name, 0);
        usort($records, function ($a, $b) { return $a['key'] <=> $b['key']; });
        return $records;
    }

    private function manifest(string $dir): array
    {
        $manifest = $this->openSealedRecord((string)@file_get_contents($dir . '/manifest.json'), 'backup');
        $this->assertNotNull($manifest, 'manifest.json is a valid sealed record (§2, §10.1)');
        return $manifest;
    }

    private function generation(string $db): int
    {
        return json_decode((string)file_get_contents($this->dbPath($db, 'current')), true)['generation'];
    }

    private function generations(string $db): array
    {
        $out = array_map('intval', array_values(array_filter(scandir($this->dbPath($db, 'home') . 'generations') ?: [], 'ctype_digit')));
        sort($out);
        return $out;
    }

    private function recoveryActions(string $db): array
    {
        $raw = @file_get_contents($this->dbPath($db, 'recovery'));
        return $raw === false ? [] : array_map(function ($line) { return json_decode($line, true); }, array_values(array_filter(explode("\n", $raw), 'strlen')));
    }

    private function copyTree(string $from, string $to): void
    {
        mkdir($to, 0700, true);
        $this->outside[] = $to;
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir("$from/$entry")) {
                $this->copyTree("$from/$entry", "$to/$entry");
            } else {
                copy("$from/$entry", "$to/$entry");
            }
        }
    }

    /** A copy of a backup with its manifest changed by $change (re-sealed) */
    private function alteredBackup(string $dir, callable $change): string
    {
        $copy = $this->backupDir();
        $this->copyTree($dir, $copy);
        $manifest = $this->manifest($copy);
        $manifest = $change($manifest, $copy);
        if ($manifest !== null) {
            file_put_contents("$copy/manifest.json", $this->sealRecord($manifest));
        }
        return $copy;
    }

    /** The manifest entry of a file, rewritten for the bytes the file has now */
    private function entryFor(array $manifest, string $dir, string $path): array
    {
        foreach ($manifest['files'] as $i => $entry) {
            if ($entry['path'] === $path) {
                $manifest['files'][$i] = ['path' => $path, 'size' => filesize("$dir/$path"), 'sha256' => hash_file('sha256', "$dir/$path")];
            }
        }
        return $manifest;
    }

    private function point(int $n): array
    {
        return ['type' => 'Point', 'coordinates' => [(float)$n, (float)$n]];
    }

    /** 10 records (keys 0..9), a field index on c and a spatial index on loc, key 3 deleted, n=5 updated */
    private function seed(\noneDB $db, string $name): void
    {
        $db->insert($name, array_map(function ($n) { return ['n' => $n, 'c' => 'c' . ($n % 3), 'loc' => $this->point($n)]; }, range(0, 9)));
        $db->createFieldIndex($name, 'c');
        $db->createSpatialIndex($name, 'loc');
        $db->delete($name, ['key' => 3]);
        $db->update($name, [['n' => 5], ['set' => ['c' => 'z']]]);
    }

    // ------------------------------------------------------------------ backup()

    /** @test §10.1, §10.2 */
    public function aBackupIsTheDatabaseDirectoryOfOneMomentWithItsManifestWrittenLast(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $this->seed($db, 'u');
        $db->find('u', 0); // a read may write reader checkpoints: before the snapshot
        $before = $this->snapshot($this->testDbDir);
        $dir = $this->backupDir();
        $t0 = time();
        $result = $db->backup('u', $dir);
        $t1 = time();
        $this->assertSame($before, $this->snapshot($this->testDbDir), 'nothing of the database is written (§10.2 step 2)');

        $format = $this->openSealedRecord(file_get_contents($this->dbPath('u', 'format')), 'database');
        $generation = $this->generation('u');
        $manifest = $this->manifest($dir);
        $this->assertSame(['magic', 'kind', 'storageFormat', 'databaseId', 'name', 'generation', 'nextKey', 'records',
            'bufferedRecords', 'takenAt', 'files', 'checksum'], array_keys($manifest), 'members in the order of §10.1');
        $this->assertSame(4, $manifest['storageFormat']);
        $this->assertSame($format['databaseId'], $manifest['databaseId']);
        $this->assertSame('u', $manifest['name']);
        $this->assertSame($generation, $manifest['generation']);
        $this->assertSame(10, $manifest['nextKey'], 'keys 0..9 were handed out');
        $this->assertSame(9, $manifest['records']);
        $this->assertSame(0, $manifest['bufferedRecords']);
        $this->assertGreaterThanOrEqual($t0, $manifest['takenAt']);
        $this->assertLessThanOrEqual($t1, $manifest['takenAt']);

        // Every other file, sorted by path, with its size and SHA-256
        $files = array_values(array_diff($this->filesOf($dir), ['manifest.json']));
        $this->assertSame($files, array_column($manifest['files'], 'path'));
        foreach ($manifest['files'] as $entry) {
            $this->assertSame(['path', 'size', 'sha256'], array_keys($entry));
            $this->assertSame(filesize("$dir/{$entry['path']}"), $entry['size'], $entry['path']);
            $this->assertSame(hash_file('sha256', "$dir/{$entry['path']}"), $entry['sha256'], $entry['path']);
        }
        // The layout of a database directory: FORMAT, CURRENT, the active generation, recovery/, store.json
        $expected = ['CURRENT', 'FORMAT', 'store.json'];
        foreach ($this->filesOf($this->dbPath('u', 'gen')) as $file) {
            $expected[] = "generations/$generation/$file";
        }
        foreach ($this->filesOf($this->dbPath('u', 'quarantine')) as $file) {
            $expected[] = "recovery/$file";
        }
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $files);
        $this->assertContains("generations/$generation/segments/s2.jsonl", $files);
        $this->assertContains("generations/$generation/indexes/c.gfx", $files, 'derived files are copied too');
        foreach ($files as $file) {
            $source = strpos($file, 'generations/') === 0 ? $this->dbPath('u', 'gen') . substr($file, strlen("generations/$generation/"))
                : ($file === 'store.json' ? $this->testDbDir . 'STORE' : $this->dbPath('u', 'home') . $file);
            $this->assertSame(file_get_contents($source), file_get_contents("$dir/$file"), "$file byte for byte");
        }
        $this->assertNotNull($this->openSealedRecord(file_get_contents("$dir/store.json"), 'store'));

        $this->assertSame(['database', 'dir', 'databaseId', 'generation', 'nextKey', 'records', 'bufferedRecords', 'files', 'bytes'], array_keys($result));
        $this->assertSame(['u', realpath($dir), $manifest['databaseId'], $generation, 10, 9, 0, count($files), array_sum(array_column($manifest['files'], 'size'))],
            array_values($result));

        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0700, fileperms($dir) & 0777, 'the backup directory is 0700');
            foreach ($this->snapshot($dir) as $path => $kind) {
                $this->assertSame($kind === 'dir' ? 0700 : 0600, fileperms("$dir/$path") & 0777, $path);
            }
        }
    }

    /** @test §10.1: the buffer is copied, not flushed; data and buffer files only up to their last "\n" */
    public function aBackupCopiesTheBufferAndCompleteLinesOnly(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'bufferEnabled' => true]);
        $db->insert('b', [['v' => 'a'], ['v' => 'b'], ['v' => 'c']]);
        $db->flush('b');
        $db->insert('b', [['v' => 'd'], ['v' => 'e']]);
        $this->assertContains('b', $this->lockState('b')['flags']);
        // Crashed appends: unterminated last lines (never records, §6.1)
        file_put_contents($this->dbPath('b', 'data'), '{"v":"torn","key', FILE_APPEND);
        file_put_contents($this->dbPath('b', 'buffer'), '{"v":"to', FILE_APPEND);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false, 'bufferEnabled' => true]);
        $before = $this->snapshot($this->testDbDir);
        $dir = $this->backupDir();
        $result = $db->backup('b', $dir);
        $this->assertSame($before, $this->snapshot($this->testDbDir), 'the buffer is not flushed, the torn tails are not cut');
        $this->assertContains('b', $this->lockState('b')['flags']);

        $gen = 'generations/' . $this->generation('b') . '/';
        foreach (['segments/main.jsonl' => 'data', 'buffer/pending.jsonl' => 'buffer'] as $file => $role) {
            $raw = file_get_contents($this->dbPath('b', $role));
            $this->assertSame(substr($raw, 0, strrpos($raw, "\n") + 1), file_get_contents($dir . '/' . $gen . $file), "$file: complete lines only");
        }
        $manifest = $this->manifest($dir);
        $this->assertSame(3, $manifest['records']);
        $this->assertSame(2, $manifest['bufferedRecords'], 'the records of the buffer files');
        $this->assertSame(3, $manifest['nextKey'], 'buffered records get keys when flushed');
        $this->assertSame([3, 2], [$result['records'], $result['bufferedRecords']]);
    }

    /** @test §10.2 step 4: a copy that does not read back as it was written fails the backup, which is removed */
    public function aCopyThatDoesNotReadBackFailsTheBackup(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $db->insert('u', array_map(function ($n) { return ['n' => $n]; }, range(0, 5)));
        $dir = $this->backupDir();
        $this->setFaultHook(function ($point, $context) use ($dir) {
            if ($point === 'backup:copied' && substr($context['path'], -8) === 's1.jsonl') {
                file_put_contents($dir . '/' . $context['path'], 'x', FILE_APPEND); // the disk gave back other bytes
            }
        });
        $before = $this->snapshot($this->testDbDir);
        try {
            $db->backup('u', $dir);
            $this->fail('the copy does not read back');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('s1.jsonl', $e->getMessage());
        }
        $this->setFaultHook(null);
        $this->assertFileDoesNotExist($dir, 'what the backup created is removed');
        $this->assertSame($before, $this->snapshot($this->testDbDir));
    }

    /** @test §10.2 step 1 */
    public function backupRefusesADirectoryThatIsNotEmptyOrInsideDbDirAndCreatesNothing(): void
    {
        $db = $this->db();
        $db->insert('u', [['v' => 1]]);
        $store = $this->snapshot($this->testDbDir);

        $notEmpty = $this->backupDir();
        mkdir($notEmpty, 0755);
        file_put_contents("$notEmpty/keep.txt", 'x');
        $aFile = $this->backupDir();
        file_put_contents($aFile, 'x');
        $link = $this->backupDir();
        symlink(rtrim($this->testDbDir, '/'), $link);
        $cases = [
            'not empty' => $notEmpty,
            'a file' => $aFile,
            'inside dbDir' => $this->testDbDir . 'backups/u',
            'dbDir itself' => $this->testDbDir,
            'a link into dbDir' => "$link/backups",
        ];
        foreach ($cases as $case => $dir) {
            try {
                $db->backup('u', $dir);
                $this->fail("$case: refused");
            } catch (\InvalidArgumentException $e) {
            }
            $this->assertSame($store, $this->snapshot($this->testDbDir), "$case: nothing created");
        }
        $this->assertSame(['keep.txt'], array_values(array_diff(scandir($notEmpty), ['.', '..'])));
        $this->assertSame('x', file_get_contents($aFile));

        // An empty directory is used, and made 0700
        $empty = $this->backupDir();
        mkdir($empty, 0755);
        $db->backup('u', $empty);
        $this->assertSame(1, $this->manifest($empty)['records']);
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0700, fileperms($empty) & 0777);
        }
    }

    /** @test §10.2 step 2 */
    public function aBackupOfAMissingDatabaseCreatesNothing(): void
    {
        $db = $this->db();
        $db->insert('other', [['v' => 1]]);
        $before = $this->snapshot($this->testDbDir);
        $dir = $this->backupDir();
        try {
            $db->backup('missing', $dir);
            $this->fail('no database to copy');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('missing', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot($this->testDbDir));
        $this->assertFileDoesNotExist($dir);

        // A location without a store: nothing is created there either
        $location = $this->backupDir();
        $other = new \noneDB(['secretKey' => self::SECRET, 'dbDir' => $location, 'autoCreateDB' => true]);
        try {
            $other->backup('x', $dir);
            $this->fail('no database to copy');
        } catch (\RuntimeException $e) {
        }
        $this->assertFileDoesNotExist($location);
        $this->assertFileDoesNotExist($dir);
    }

    /** @test §10.2 step 2: maintenance in progress */
    public function aBackupFailsWhileMaintenanceIsInProgress(): void
    {
        $db = $this->db();
        $db->insert('u', [['v' => 1]]);
        $state = $this->lockState('u');
        $this->writeLockState('u', $state['gen'] . ':' . $state['epoch'] . ' m');
        $dir = $this->backupDir();
        $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $this->code(function () use ($dir) { $this->db()->backup('u', $dir); }), 'm');
        $this->assertFileDoesNotExist($dir);

        $this->writeLockState('u', $state['gen'] . ':' . $state['epoch']);
        mkdir($this->testDbDir . '.nonedb-maintenance');
        file_put_contents($this->testDbDir . '.nonedb-maintenance/journal.json', '{}');
        \noneDB::clearStaticCache();
        $this->assertSame(\noneDB::MAINTENANCE_IN_PROGRESS, $this->code(function () use ($dir) { $this->db()->backup('u', $dir); }), 'journal');
        $this->assertFileDoesNotExist($dir);
    }

    // ------------------------------------------------------------------ restore()

    public function layouts(): array
    {
        return ['single' => [['shardingEnabled' => false]], 'sharded' => [['shardSize' => 4]]];
    }

    /**
     * @test §10.3: present database, one commit; the next key never goes back
     * @dataProvider layouts
     */
    public function aRestoreBringsTheBackupBackWithOneCommit(array $config): void
    {
        $db = $this->db($config);
        $this->seed($db, 'u');
        $expected = $this->records($db, 'u');
        $dir = $this->backupDir();
        $db->backup('u', $dir);
        $manifest = $this->manifest($dir);
        $formatBytes = file_get_contents($this->dbPath('u', 'format'));

        // Changed after the backup: keys up to 14 handed out
        $db->insert('u', array_map(function ($n) { return ['n' => $n, 'c' => 'c1', 'loc' => $this->point($n)]; }, range(10, 14)));
        $db->delete('u', ['n' => 0]);
        $db->update('u', [['n' => 1], ['set' => ['c' => 'changed']]]);
        $generation = $this->generation('u');

        $result = $db->restore($dir, 'u');
        $this->assertSame(['database', 'generation', 'nextKey', 'records', 'bufferedRecords', 'target', 'foreign'], array_keys($result));
        $this->assertSame(['u', $generation + 1, 15, 9, 0, 'present', false], array_values($result));
        $operation = $db->getDurability()['lastOperation'];
        $this->assertSame(['restore', 'u', 'committed'], [$operation['op'], $operation['db'], $operation['outcome']]);

        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame($expected, $this->records($db, 'u'), 'the records of the backup, with their keys');
        $c1 = array_values(array_filter($expected, function ($r) { return $r['c'] === 'c1'; }));
        $this->assertSame(array_column($c1, 'key'), array_column($db->find('u', ['c' => 'c1']), 'key'), 'field index');
        $inBox = array_values(array_filter($expected, function ($r) { return $r['n'] >= 2 && $r['n'] <= 6; }));
        $box = array_column($db->withinBBox('u', 'loc', 1.5, 1.5, 6.5, 6.5), 'key');
        sort($box);
        $this->assertSame(array_column($inBox, 'key'), $box, 'spatial index');
        $this->assertSame([$generation + 1], $this->generations('u'), 'generation N removed after X was published');
        $this->assertSame($formatBytes, file_get_contents($this->dbPath('u', 'format')), 'FORMAT unchanged');
        $metadata = $this->openSealedRecord(file_get_contents($this->dbPath('u', 'gen') . 'metadata'), 'generation');
        $format = $this->openSealedRecord($formatBytes, 'database');
        foreach (['databaseId', 'name', 'shardSize', 'createdAt'] as $member) {
            $this->assertSame($format[$member], $metadata[$member], "metadata $member");
        }
        $this->assertSame($generation + 1, $metadata['generation']);
        $this->assertSame(isset($config['shardSize']) ? 'sharded' : 'single', $metadata['layout']);
        $flags = $this->lockState('u')['flags'];
        $this->assertSame([], array_values(array_filter($flags, function ($f) { return $f === 'b' || $f[0] === 'g'; })), 'no g, no b');
        $this->assertTrue($db->checkIntegrity('u')['ok']);

        // The top tombstone keeps key 14 (§10.3 step 4.3): single layout main, sharded s3 (keys 12..15, new)
        $tombstoned = $this->dbPath('u', 'data', isset($config['shardSize']) ? 3 : null);
        $this->assertSame('{"__nonedb_deleted":14}', array_slice($this->dataLines($tombstoned), -1)[0]);
        if (isset($config['shardSize'])) {
            $this->assertSame(["{\"__nonedb_deleted\":14}"], $this->dataLines($tombstoned), 'a new shard holding the tombstone');
            $meta = json_decode(file_get_contents($this->dbPath('u', 'shards')), true);
            $this->assertSame(15, $meta['nextKey']);
            $this->assertSame([0, 1, 2, 3], array_column($meta['shards'], 'id'));
        }
        $db->insert('u', ['n' => 15, 'c' => 'c1', 'loc' => $this->point(15)]);
        $this->assertSame([15], array_column($db->find('u', ['n' => 15]), 'key'), 'the next key');
        $this->assertContains(15, array_column($db->find('u', ['c' => 'c1']), 'key'));

        $event = array_slice($this->recoveryActions('u'), -1)[0];
        $this->assertSame('restore', $event['action']);
        $this->assertSame([$generation + 1, $manifest['databaseId'], $manifest['takenAt'], 9],
            [$event['generation'], $event['databaseId'], $event['takenAt'], $event['records']]);
    }

    /** @test §10.3 step 4.3: the tombstone survives a lost counter file */
    public function theNextKeyComesBackFromTheDataAfterARestore(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('s', [['v' => 0], ['v' => 1], ['v' => 2]]);
        $dir = $this->backupDir();
        $db->backup('s', $dir);
        $db->insert('s', array_fill(0, 7, ['v' => 'later']));
        $db->delete('s', ['v' => 'later']);
        $this->assertSame(3, $this->manifest($dir)['nextKey']);
        $db->restore($dir, 's');
        unlink($this->dbPath('s', 'kix'));
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('s', ['v' => 'next']);
        $this->assertSame([10], array_column($db->find('s', ['v' => 'next']), 'key'), 'n rebuilt from the data, tombstone 9 included');

        $db = $this->db(['shardSize' => 4]);
        $db->insert('t', array_fill(0, 5, ['v' => 'kept']));
        $this->assertTrue($db->getShardInfo('t')['sharded']);
        $dir = $this->backupDir();
        $db->backup('t', $dir);
        $db->insert('t', array_fill(0, 9, ['v' => 'later']));
        $db->restore($dir, 't');
        file_put_contents($this->dbPath('t', 'shards'), ''); // a power-loss artefact (§5.1)
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 4]);
        $this->assertSame(14, $db->getShardInfo('t')['nextKey'], 'shards.json rebuilt from the data: the tombstone of key 13');
        $this->assertSame(5, $db->count('t'));
    }

    /** @test §5.2 INTENT/DONE and §10.3: b exactly when the restored buffer holds a file */
    public function bufferedRecordsComeBackWithTheBufferFlag(): void
    {
        $config = ['shardingEnabled' => false, 'bufferEnabled' => true];
        $db = $this->db($config);
        $db->insert('b', [['v' => 'a'], ['v' => 'b'], ['v' => 'c']]);
        $db->flush('b');
        $db->insert('b', [['v' => 'd'], ['v' => 'e']]);
        $dir = $this->backupDir();
        $db->backup('b', $dir);
        $this->assertContains('generations/' . $this->generation('b') . '/buffer/pending.jsonl', array_column($this->manifest($dir)['files'], 'path'));
        $db->insert('b', [['v' => 'f'], ['v' => 'g']]);
        $db->flush('b'); // keys 3..6
        $this->assertNotContains('b', $this->lockState('b')['flags']);

        $result = $db->restore($dir, 'b');
        $this->assertSame([3, 2, 7], [$result['records'], $result['bufferedRecords'], $result['nextKey']]);
        $this->assertContains('b', $this->lockState('b')['flags'], 'the restored buffer holds a file');
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        // The buffered records are flushed by the read with the next key, max(7, 3) = 7
        $this->assertSame([['a', 0], ['b', 1], ['c', 2], ['d', 7], ['e', 8]],
            array_map(function ($r) { return [$r['v'], $r['key']]; }, $this->records($db, 'b')));
        $this->assertNotContains('b', $this->lockState('b')['flags']);

        // A backup without a buffer clears b of a database whose buffer held records
        $db->insert('n', [['v' => 'x']]);
        $db->flush('n');
        $plain = $this->backupDir();
        $db->backup('n', $plain);
        $db->insert('n', [['v' => 'y']]);
        $this->assertContains('b', $this->lockState('n')['flags']);
        $db->restore($plain, 'n');
        $this->assertNotContains('b', $this->lockState('n')['flags']);
        $this->assertSame(['x'], array_column($this->records($this->db($config), 'n'), 'v'), 'the buffer of the replaced generation is gone with it');
    }

    /** @test §10.3 step 4.3: the records of a flushing.{K} file keep the keys K.. */
    public function aFlushingFileKeepsTheKeysItsNameGave(): void
    {
        $config = ['shardingEnabled' => false, 'bufferEnabled' => true];
        $db = $this->db($config);
        $db->insert('f', [['v' => 'a']]);
        $db->flush('f');
        // An interrupted flush: the buffer renamed to flushing.1 (its records get keys 1, 2), b set (§5.1)
        $flushing = $this->dbPath('f', 'flushing', 1);
        @mkdir(dirname($flushing), 0750, true);
        file_put_contents($flushing, "{\"v\":\"p\"}\n{\"v\":\"q\"}\n");
        $state = $this->lockState('f');
        $this->writeLockState('f', $state['gen'] . ':' . $state['epoch'] . ' b');
        $db = $this->db($config);
        $dir = $this->backupDir();
        $this->assertSame(2, $db->backup('f', $dir)['bufferedRecords']);
        $db->insert('f', array_fill(0, 3, ['v' => 'later']));
        $db->flush('f'); // p, q at 1, 2, then 3..5: next key 6

        $db->restore($dir, 'f');
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame([['a', 0], ['p', 1], ['q', 2]], array_map(function ($r) { return [$r['v'], $r['key']]; }, $this->records($db, 'f')));
        $db->insert('f', ['v' => 'next']);
        $db->flush('f');
        $this->assertSame([6], array_column($db->find('f', ['v' => 'next']), 'key'));
    }

    /** @test §10.3 step 3: a refused restore changes nothing */
    public function aRestoreOfAnotherDatabaseIsRefusedWithoutForce(): void
    {
        $db = $this->db();
        $db->insert('a', [['v' => 'a0'], ['v' => 'a1']]);
        $db->insert('b', [['v' => 'b0']]);
        $dir = $this->backupDir();
        $db->backup('a', $dir);
        $before = $this->snapshot($this->testDbDir);
        foreach (['present, another databaseId' => 'b', 'absent, another name' => 'c'] as $case => $target) {
            try {
                $db->restore($dir, $target);
                $this->fail("$case: refused");
            } catch (\RuntimeException $e) {
                $this->assertSame(\noneDB::RESTORE_REFUSED, $e->getCode(), $case);
            }
            $this->assertSame($before, $this->snapshot($this->testDbDir), "$case: the store byte for byte as it was");
        }
        $this->assertSame(['a', 'b'], $this->sortedDbs($db));

        // A sharded backup does not fit another shard size, with force either
        $four = $this->db(['shardSize' => 4]);
        $four->insert('s4', array_fill(0, 6, ['v' => 1]));
        $sharded = $this->backupDir();
        $four->backup('s4', $sharded);
        $eight = $this->db(['shardSize' => 8]);
        $eight->insert('s8', array_fill(0, 3, ['v' => 1]));
        $before = $this->snapshot($this->testDbDir);
        $this->assertSame(\noneDB::RESTORE_REFUSED, $this->code(function () use ($eight, $sharded) { $eight->restore($sharded, 's8', ['force' => true]); }));
        $this->assertSame($before, $this->snapshot($this->testDbDir));
    }

    private function sortedDbs(\noneDB $db): array
    {
        $names = $db->getDBs();
        sort($names);
        return $names;
    }

    /** @test §10.1: an incomplete or damaged backup is refused, and nothing changes */
    public function anIncompleteOrDamagedBackupIsRefused(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $db->insert('a', array_map(function ($n) { return ['n' => $n]; }, range(0, 5)));
        $dir = $this->backupDir();
        $db->backup('a', $dir);
        $db->insert('a', ['n' => 6]);
        $g = $this->manifest($dir)['generation'];
        $data = "generations/$g/segments/s0.jsonl";
        $outsideFile = $this->backupDir();
        file_put_contents($outsideFile, 'x');
        $cases = [
            'no manifest' => function ($m, $d) { unlink("$d/manifest.json"); return null; },
            'manifest checksum' => function ($m, $d) { file_put_contents("$d/manifest.json", str_replace('"records":6', '"records":7', file_get_contents("$d/manifest.json"))); return null; },
            'a byte of a data file' => function ($m, $d) use ($data) { $raw = file_get_contents("$d/$data"); $raw[2] = $raw[2] === 'n' ? 'm' : 'n'; file_put_contents("$d/$data", $raw); return null; },
            'a cut data file' => function ($m, $d) use ($data) { $raw = file_get_contents("$d/$data"); file_put_contents("$d/$data", substr($raw, 0, -5)); return null; },
            'a missing file' => function ($m, $d) use ($data) { unlink("$d/$data"); return null; },
            'a directory for a file' => function ($m, $d) use ($data) { unlink("$d/$data"); mkdir("$d/$data"); return null; },
            'a link for a file' => function ($m, $d) use ($data) { rename("$d/$data", "$d/elsewhere"); symlink("$d/elsewhere", "$d/$data"); return null; },
            'a path outside the backup' => function ($m, $d) use ($outsideFile) {
                $m['files'][] = ['path' => '../' . basename($outsideFile), 'size' => 1, 'sha256' => hash('sha256', 'x')];
                usort($m['files'], function ($a, $b) { return strcmp($a['path'], $b['path']); });
                return $m;
            },
            'a file the format does not name' => function ($m, $d) use ($g) {
                file_put_contents("$d/generations/$g/extra.txt", 'x');
                $m['files'][] = ['path' => "generations/$g/extra.txt", 'size' => 1, 'sha256' => hash('sha256', 'x')];
                usort($m['files'], function ($a, $b) { return strcmp($a['path'], $b['path']); });
                return $m;
            },
            'without store.json' => function ($m, $d) {
                unlink("$d/store.json");
                $m['files'] = array_values(array_filter($m['files'], function ($e) { return $e['path'] !== 'store.json'; }));
                return $m;
            },
            'another kind' => function ($m, $d) { $m['kind'] = 'migration-backup'; return $m; },
            'storage format 3' => function ($m, $d) { $m['storageFormat'] = 3; return $m; },
            'FORMAT of another database' => function ($m, $d) { $m['databaseId'] = '00000000-0000-4000-8000-000000000000'; return $m; },
            'CURRENT names another generation' => function ($m, $d) use ($g) {
                $current = json_decode(file_get_contents("$d/CURRENT"), true);
                $current['generation'] = $g + 1;
                file_put_contents("$d/CURRENT", $this->sealRecord($current));
                return $this->entryFor($m, $d, 'CURRENT');
            },
            'metadata of another name' => function ($m, $d) use ($g) {
                $metadata = json_decode(file_get_contents("$d/generations/$g/metadata"));
                $metadata->name = 'other';
                file_put_contents("$d/generations/$g/metadata", $this->sealRecord($metadata));
                return $this->entryFor($m, $d, "generations/$g/metadata");
            },
        ];
        $before = $this->snapshot($this->testDbDir);
        foreach ($cases as $case => $change) {
            $damaged = $this->alteredBackup($dir, $change);
            $this->assertSame(\noneDB::RESTORE_REFUSED, $this->code(function () use ($db, $damaged) { $db->restore($damaged, 'a'); }), $case);
            $this->assertSame($before, $this->snapshot($this->testDbDir), "$case: nothing written");
        }
        // The original is fine
        $this->assertSame(6, $db->restore($dir, 'a')['records']);
    }

    /** @test §10.3 step 4.1: a copy that does not read back as it was copied fails the restore; X is removed */
    public function aRestoredCopyThatDoesNotReadBackFailsTheRestore(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $db->insert('a', array_map(function ($n) { return ['n' => $n]; }, range(0, 5)));
        $dir = $this->backupDir();
        $db->backup('a', $dir);
        $db->insert('a', ['n' => 6]);
        $expected = $this->records($db, 'a');
        $x = $this->generation('a') + 1;
        // The disk keeps other bytes than it was given for the copy of s1.jsonl
        \noneDB\Tests\Support\Testing::set('writeFn', function ($fp, $bytes, $path) use ($x) {
            if (strpos($path, "/generations/$x/segments/s1.jsonl") !== false) {
                $bytes[0] = $bytes[0] === '{' ? '[' : '{';
            }
            return fwrite($fp, $bytes);
        });
        try {
            $db->restore($dir, 'a');
            $this->fail('the copy does not read back');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('s1.jsonl', $e->getMessage());
        } finally {
            \noneDB\Tests\Support\Testing::set('writeFn', null);
        }
        $this->assertSame([$x - 1], $this->generations('a'), 'X is removed');
        $this->assertSame($expected, $this->records($this->db(['shardSize' => 4]), 'a'));
    }

    /** @test §10.3 step 4.4: a generation that does not read as its manifest says is not published */
    public function aRestoredGenerationThatDoesNotVerifyIsNotPublished(): void
    {
        foreach (['single' => ['shardingEnabled' => false], 'sharded' => ['shardSize' => 4]] as $layout => $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->insert('a', array_map(function ($n) { return ['n' => $n]; }, range(0, 5)));
            $dir = $this->backupDir();
            $db->backup('a', $dir);
            $db->insert('a', ['n' => 6]);
            $expected = $this->records($db, 'a');
            $generations = $this->generations('a');
            // [manifest change, target]: a next key below the data's only shows where nothing raises it (absent)
            $cases = [
                'one record more' => [function ($m) { $m['records']++; return $m; }, 'a'],
                'a buffered record' => [function ($m) { $m['bufferedRecords'] = 1; return $m; }, 'a'],
                'a next key below the data\'s' => [function ($m) { $m['nextKey'] = 3; return $m; }, 'b'],
            ];
            foreach ($cases as $case => [$change, $target]) {
                $label = "$layout: $case";
                $altered = $this->alteredBackup($dir, function ($m) use ($change) { return $change($m); });
                $lock = [$this->locatorOf($target) . '.ndb4/LOCK' => true, $this->locatorOf($target) . '.ndb4' => true];
                $before = array_diff_key($this->snapshot($this->testDbDir), $lock);
                $this->assertSame(\noneDB::RESTORE_REFUSED, $this->code(function () use ($db, $altered, $target) { $db->restore($altered, $target, ['force' => true]); }), $label);
                $this->assertSame($before, array_diff_key($this->snapshot($this->testDbDir), $lock), "$label: X removed, the database as it was");
                $this->assertSame($generations, $this->generations('a'), $label);
                $this->assertSame($expected, $this->records($this->db($config), 'a'), $label);
                $this->assertSame(['a'], $this->db($config)->getDBs(), $label);
            }
        }
    }

    /** @test §10.3 step 3: force restores a backup of another database under the target's identity */
    public function forceRestoresAForeignBackupUnderTheTargetIdentity(): void
    {
        $db = $this->db();
        $db->insert('a', [['v' => 'a0'], ['v' => 'a1'], ['v' => 'a2']]);
        $db->insert('b', [['v' => 'b0']]);
        $expected = $this->records($db, 'a');
        $dir = $this->backupDir();
        $db->backup('a', $dir);
        $formatB = file_get_contents($this->dbPath('b', 'format'));
        $aBefore = $this->snapshot($this->dbPath('a', 'home'));

        $result = $db->restore($dir, 'b', ['force' => true]);
        $this->assertSame(['present', true], [$result['target'], $result['foreign']]);
        $this->assertSame($expected, $this->records($this->db(), 'b'));
        $this->assertSame($formatB, file_get_contents($this->dbPath('b', 'format')), 'FORMAT stays as it is');
        $metadata = $this->openSealedRecord(file_get_contents($this->dbPath('b', 'gen') . 'metadata'), 'generation');
        $this->assertSame([$this->openSealedRecord($formatB, 'database')['databaseId'], 'b'], [$metadata['databaseId'], $metadata['name']]);
        $event = array_slice($this->recoveryActions('b'), -1)[0];
        $this->assertSame(['foreign-restore', $this->manifest($dir)['databaseId']], [$event['action'], $event['databaseId']]);
        $this->assertTrue($this->db()->checkIntegrity('b')['ok']);
        $this->assertSame($aBefore, $this->snapshot($this->dbPath('a', 'home')), 'the source is untouched');

        foreach ([['forced' => true], ['force' => 'yes'], ['force' => 1]] as $options) {
            try {
                $db->restore($dir, 'b', $options);
                $this->fail(json_encode($options));
            } catch (\InvalidArgumentException $e) {
            }
        }
    }

    /** @test §10.3: into an absent database (also without autoCreateDB) */
    public function aRestoreIntoAnAbsentDatabaseCreatesIt(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $db->insert('u', array_map(function ($n) { return ['n' => $n]; }, range(0, 5)));
        $expected = $this->records($db, 'u');
        $dir = $this->backupDir();
        $db->backup('u', $dir);
        $backupFormat = file_get_contents("$dir/FORMAT");
        $this->assertTrue($db->dropDB('u')['success']);

        $plain = $this->db(['shardSize' => 4, 'autoCreateDB' => false]);
        $result = $plain->restore($dir, 'u');
        $this->assertSame(['absent', false, 1], [$result['target'], $result['foreign'], $result['generation']]);
        $this->assertSame($backupFormat, file_get_contents($this->dbPath('u', 'format')), 'the backup\'s identity: same name');
        $this->assertSame($expected, $this->records($plain, 'u'));
        $this->assertSame([], $this->lockState('u')['flags'], 'creation finished: no c');
        $this->assertSame('restore', array_slice($this->recoveryActions('u'), -1)[0]['action']);
        $this->assertTrue($plain->checkIntegrity('u')['ok']);

        // Another name with force: a new identity with the backup's shard size
        $t0 = time();
        $other = $this->db(['shardSize' => 100]);
        $result = $other->restore($dir, 'v', ['force' => true]);
        $this->assertSame(['absent', true], [$result['target'], $result['foreign']]);
        $format = $this->openSealedRecord(file_get_contents($this->dbPath('v', 'format')), 'database');
        $backup = $this->openSealedRecord($backupFormat, 'database');
        $this->assertNotSame($backup['databaseId'], $format['databaseId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $format['databaseId']);
        $this->assertSame(['v', 4], [$format['name'], $format['shardSize']]);
        $this->assertGreaterThanOrEqual($t0, $format['createdAt']);
        $this->assertSame($expected, $this->records($other, 'v'));
        $this->assertSame('foreign-restore', array_slice($this->recoveryActions('v'), -1)[0]['action']);
        $this->assertTrue($other->checkIntegrity('v')['ok']);
    }

    /** @test §10.3: the documented way out of FORMAT_DAMAGED; the damaged generations are kept */
    public function aRestoreReplacesADamagedDatabaseAndKeepsItsGenerations(): void
    {
        $config = ['shardingEnabled' => false];
        $db = $this->db($config);
        $db->insert('u', [['v' => 0], ['v' => 1], ['v' => 2]]);
        $expected = $this->records($db, 'u');
        $dir = $this->backupDir();
        $db->backup('u', $dir);
        $db->insert('u', array_fill(0, 5, ['v' => 'later'])); // keys 3..7
        $formatBytes = file_get_contents($this->dbPath('u', 'format'));
        file_put_contents($this->dbPath('u', 'current'), 'x');
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () use ($db) { $db->find('u', 0); }));

        $result = $db->restore($dir, 'u');
        $this->assertSame(['damaged', false, 2], [$result['target'], $result['foreign'], $result['generation']]);
        $this->assertSame(8, $result['nextKey'], '1 + the highest key of any line in the damaged generations');
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame($expected, $this->records($db, 'u'));
        $this->assertSame([1, 2], $this->generations('u'), 'the damaged generation is kept');
        $this->assertSame($formatBytes, file_get_contents($this->dbPath('u', 'format')));
        $report = $db->checkIntegrity('u');
        $this->assertSame(['generation'], array_values(array_unique(array_column($report['indexMismatches'], 'index'))), 'reported as unreferenced');
        $this->assertSame([[], []], [$report['badLines'], $report['tornTail']]);
        $db->insert('u', ['v' => 'next']);
        $this->assertSame([8], array_column($db->find('u', ['v' => 'next']), 'key'));

        // An interrupted switch (g=1>2) whose CURRENT is invalid: both candidates are kept, g goes
        $db = $this->db(['shardSize' => 4]);
        $db->insert('m', [['v' => 0], ['v' => 1], ['v' => 2]]);
        $mdir = $this->backupDir();
        $db->backup('m', $mdir);
        \noneDB::clearStaticCache();
        $this->assertSame('', $this->runChild('
            $db = ' . $this->childDb(['shardSize' => 4]) . ';
            noneDB\Tests\Support\Testing::setFaultHook(function ($p) { if ($p === "generation:published") { exit(0); } });
            $db->insert("m", [["v" => 3], ["v" => 4]]);
            echo "not reached";
        '));
        $this->assertContains('g=1>2', $this->lockState('m')['flags']);
        file_put_contents($this->dbPath('m', 'current'), 'x');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 4]);
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () use ($db) { $db->find('m', 0); }));
        $result = $db->restore($mdir, 'm');
        $this->assertSame(['damaged', 3, 5], [$result['target'], $result['generation'], $result['nextKey']]);
        $this->assertSame([1, 2, 3], $this->generations('m'), 'neither candidate is removed');
        $this->assertSame([], array_values(array_filter($this->lockState('m')['flags'], function ($f) { return $f[0] === 'g'; })));
        $this->assertSame([0, 1, 2], array_column($this->records($this->db(['shardSize' => 4]), 'm'), 'v'));

        // g=1>2 with a valid CURRENT that names a third generation: which one was committed is not proven either
        $this->cleanTestDirectory();
        $db = $this->db(['shardSize' => 4]);
        $db->insert('m', [['v' => 0], ['v' => 1], ['v' => 2]]);
        $mdir = $this->backupDir();
        $db->backup('m', $mdir);
        \noneDB::clearStaticCache();
        $this->assertSame('', $this->runChild('
            $db = ' . $this->childDb(['shardSize' => 4]) . ';
            noneDB\Tests\Support\Testing::setFaultHook(function ($p) { if ($p === "generation:published") { exit(0); } });
            $db->insert("m", [["v" => 3], ["v" => 4]]);
        '));
        $generations = $this->dbPath('m', 'home') . 'generations/';
        $this->copyTree($generations . '2', $generations . '3');
        $metadata = json_decode(file_get_contents($generations . '3/metadata'));
        $metadata->generation = 3;
        file_put_contents($generations . '3/metadata', $this->sealRecord($metadata));
        $current = json_decode(file_get_contents($this->dbPath('m', 'current')), true);
        $current['generation'] = 3;
        file_put_contents($this->dbPath('m', 'current'), $this->sealRecord($current));
        $this->assertContains('g=1>2', $this->lockState('m')['flags']);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 4]);
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () use ($db) { $db->find('m', 0); }));
        $result = $db->restore($mdir, 'm');
        $this->assertSame(['damaged', 4], [$result['target'], $result['generation']]);
        $this->assertSame([1, 2, 3, 4], $this->generations('m'), 'no candidate is removed');
        $this->assertSame([0, 1, 2], array_column($this->records($this->db(['shardSize' => 4]), 'm'), 'v'));

        // FORMAT and CURRENT invalid: no proof of an identity; the backup's name is the target's
        $db->insert('w', [['v' => 'w0']]);
        $wdir = $this->backupDir();
        $db->backup('w', $wdir);
        file_put_contents($this->dbPath('w', 'format'), 'garbage');
        file_put_contents($this->dbPath('w', 'current'), 'garbage');
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(\noneDB::FORMAT_DAMAGED, $this->code(function () use ($db) { $db->count('w'); }));
        $before = $this->snapshot($this->testDbDir);
        $this->assertSame(\noneDB::RESTORE_REFUSED, $this->code(function () use ($db, $dir) { $db->restore($dir, 'w'); }), 'another name without force');
        $this->assertSame($before, $this->snapshot($this->testDbDir));
        $result = $db->restore($wdir, 'w');
        $this->assertSame(['damaged', false], [$result['target'], $result['foreign']]);
        $this->assertSame(file_get_contents("$wdir/FORMAT"), file_get_contents($this->dbPath('w', 'format')), 'FORMAT rewritten with the backup\'s identity');
        $this->assertSame(['w0'], array_column($this->records($this->db($config), 'w'), 'v'));
    }

    /** @test §10.3 step 2 */
    public function aRestoreInsideAnUncommittedWriteIsALogicError(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('d', [['v' => 'original']]);
        $dir = $this->backupDir();
        $db->backup('d', $dir);
        $method = new \noneDB\Tests\InternalMethod('withLock');
        try {
            $method->invoke($db, 'd', true, function () use ($db, $dir) {
                $db->insert('d', ['v' => 'uncommitted']);
                $db->restore($dir, 'd');
            });
            $this->fail('refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('restore()', $e->getMessage());
        }
        $this->assertSame(['original'], array_column($this->records($this->db(['shardingEnabled' => false]), 'd'), 'v'));
        $this->assertSame([1], $this->generations('d'));
    }

    /** @test no key material or record value in the manifest, the log or an error message */
    public function noKeyOrRecordValueLeavesTheData(): void
    {
        $secretValue = 'VALUE-SECRET-7f3a';
        $db = $this->db();
        $db->insert('a', [['v' => $secretValue]]);
        $db->insert('b', [['v' => 'b']]);
        $dir = $this->backupDir();
        $db->backup('a', $dir);
        $db->restore($dir, 'b', ['force' => true]);
        $messages = [];
        foreach ([function () use ($db, $dir) { $db->restore($dir, 'b'); },
                  function () use ($db, $dir) { $db->restore($dir, 'zz'); },
                  function () use ($db, $dir) { $db->backup('a', $dir); }] as $fn) {
            try {
                $fn();
            } catch (\Exception $e) {
                $messages[] = $e->getMessage();
            }
        }
        $this->assertCount(3, $messages);
        foreach ([file_get_contents("$dir/manifest.json"), file_get_contents("$dir/store.json"),
                  (string)file_get_contents($this->dbPath('b', 'recovery')), implode("\n", $messages)] as $i => $text) {
            $this->assertStringNotContainsString(self::SECRET, $text, "text $i");
            $this->assertStringNotContainsString($secretValue, $text, "text $i");
        }
    }

    /** @test §9.4 mode A with a backup's store.json */
    public function theStoreJsonOfABackupRepairsTheStore(): void
    {
        $db = $this->db();
        $db->insert('u', [['v' => 1]]);
        $dir = $this->backupDir();
        $db->backup('u', $dir);
        \noneDB::clearStaticCache();
        file_put_contents($this->testDbDir . 'STORE', 'x');
        file_put_contents($this->testDbDir . 'STORE.2', 'x');
        $result = \noneDB::repairStore($this->testDbDir, [self::SECRET], ['manifest' => "$dir/store.json"]);
        $this->assertTrue($result['success'], (string)$result['error']);
        $this->assertSame(['manifest', true], [$result['mode'], $result['complete']]);
        $this->assertSame([1], array_column($this->db()->find('u', 0), 'v'));
    }

    /** @test the layout follows the backup */
    public function aRestoreBringsBackTheLayoutOfTheBackup(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $db->insert('l', [['v' => 0], ['v' => 1], ['v' => 2]]);
        $dir = $this->backupDir();
        $db->backup('l', $dir);
        $db->insert('l', array_fill(0, 5, ['v' => 'later']));
        $this->assertTrue($db->getShardInfo('l')['sharded']);
        $db->restore($dir, 'l');
        $info = $this->db(['shardSize' => 4])->getShardInfo('l');
        $this->assertSame([false, 3, 8], [$info['sharded'], $info['totalRecords'], $info['nextKey']]);
        $this->assertSame([0, 1, 2], array_column($this->records($this->db(['shardSize' => 4]), 'l'), 'v'));
    }
}
