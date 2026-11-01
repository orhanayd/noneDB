<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Storage and durability checked against the specification: docs/STORAGE_FORMAT.md (data files 6.1,
 * compaction 6.2, checkpoints 6.4, recovery files 7), docs/DURABILITY.md, CLAUDE.md ("Rollback scope"),
 * docs/API.md (CRUD, compact(), convertToSharded(), getRecoveryLog()) and docs/CONFIGURATION.md ("Durability",
 * "Recovery log and quarantine", "Compaction Settings", "Write Buffer").
 *
 * Expected values are derived by hand from those documents (the comments say how) or computed by
 * modelIndex(), an independent reading of the documented data file format written here. None of them
 * was taken from noneDB's output.
 */
class SpecStorageTest extends RegressionTestCase
{
    /** @var string|false serialize_precision to restore */
    private $precision = false;

    protected function tearDown(): void
    {
        foreach (['syncFn', 'renameFn', 'flushFn', 'osFamily'] as $hook) {
            $this->setStatic($hook, null);
        }
        if ($this->precision !== false) {
            ini_set('serialize_precision', (string)$this->precision);
            $this->precision = false;
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function setStatic(string $name, $value): void
    {
        $property = new \noneDB\Tests\InternalValue($name);
        $property->setValue(null, $value);
    }

    private function dataFile(string $name, ?int $shard = null): string
    {
        return $this->dbPath($name, 'data', $shard);
    }

    /** Quarantine files of a database (STORAGE_FORMAT.md 7.2: recovery/q.{segment}.{offset}.{length}.{sha256}.bin) */
    private function quarantineFiles(string $name): array
    {
        $files = array_map('basename', glob($this->dbPath($name, 'quarantine') . 'q.*') ?: []);
        sort($files);
        return $files;
    }

    /** Name of a quarantine file in the database's recovery/ directory */
    private function quarantineName(string $name, string $segment, int $offset, string $bytes): string
    {
        return 'q.' . $segment . '.' . $offset . '.' . strlen($bytes) . '.' . hash('sha256', $bytes) . '.bin';
    }

    /** Removes the key-index checkpoints of every database */
    private function deleteCheckpoints(): void
    {
        foreach (glob(rtrim($this->testDbDir, '/') . '/*.ndb4/generations/*/segments/*.kix') as $index) {
            unlink($index);
        }
    }

    /** noneDB's in-memory key index of a segment: its counters and p/l (offset/length per live key) */
    private function memIndex(\noneDB $db, string $name, ?int $shard = null): ?array
    {
        $index = (function () use ($name, $shard) {
            return $this->readJsonlIndex($name, $shard);
        })->call(\noneDB\Tests\Support\Testing::engine($db));
        if ($index === null) {
            return null;
        }
        // Every live key's offset and length, ascending by key
        [$offsets, $lengths] = \noneDB\Tests\Support\Testing::callStatic('KeyIndex::locate', $index, null);
        ksort($offsets);
        $index['p'] = $offsets;
        $index['l'] = [];
        foreach ($offsets as $key => $offset) {
            $index['l'][$key] = $lengths[$key];
        }
        return $index;
    }

    /** A key-index checkpoint file (STORAGE_FORMAT.md 6.4) read by the test's own decoder: its header and p/l */
    private function diskIndex(string $path): array
    {
        $file = $this->readKeyIndexFile($path);
        $index = $file['header'] + ['p' => [], 'l' => []];
        foreach ($file['entries'] as $key => [$offset, $length]) {
            $index['p'][$key] = $offset;
            $index['l'][$key] = $length;
        }
        return $index;
    }

    /** The parts of an index the format defines (STORAGE_FORMAT.md 6.4), keys sorted */
    private static function core(?array $index, bool $withN = true): array
    {
        $p = array_map('intval', $index['p'] ?? []);
        $l = array_map('intval', $index['l'] ?? []);
        ksort($p);
        ksort($l);
        $core = ['p' => $p, 'l' => $l, 'd' => (int)($index['d'] ?? -1), 't' => (int)($index['t'] ?? -1), 's' => (int)($index['s'] ?? -1)];
        if ($withN) {
            $core['n'] = (int)($index['n'] ?? -1);
        }
        return $core;
    }

    /**
     * Independent model of a segment index, from the documented data format only: every complete line is a
     * record (a JSON object with an integer "key" of this segment) or a tombstone {"__nonedb_deleted":K};
     * the last line of a key wins, a tombstone deletes. d = record lines that are no longer current (replaced
     * or deleted) + lines that are neither; t = tombstone lines; n = highest key seen + 1 (local to the
     * shard); s = bytes up to the end of the last complete line. An unterminated tail is no line.
     */
    private function modelIndex(string $file, int $base = 0, ?int $limit = null): array
    {
        clearstatcache(true, $file);
        $bytes = is_file($file) ? (string)file_get_contents($file) : '';
        $p = [];
        $l = [];
        $d = 0;
        $t = 0;
        $n = 0;
        $pos = 0;
        while (($nl = strpos($bytes, "\n", $pos)) !== false) {
            $json = substr($bytes, $pos, $nl - $pos);
            $rec = json_decode($json, true);
            $key = null;
            $tomb = false;
            if (is_array($rec) && array_keys($rec) === ['__nonedb_deleted'] && is_int($rec['__nonedb_deleted'])) {
                $key = $rec['__nonedb_deleted'];
                $tomb = true;
            } elseif (is_array($rec) && isset($rec['key']) && is_int($rec['key'])) {
                $key = $rec['key'];
            }
            if ($key !== null && ($key < $base || ($limit !== null && $key >= $limit))) {
                $key = null; // a key of another segment is not a line of this one
            }
            if ($key === null) {
                $d++;
            } elseif ($tomb) {
                if (isset($p[$key])) {
                    unset($p[$key], $l[$key]);
                    $d++;
                }
                $t++;
            } else {
                if (isset($p[$key])) {
                    $d++;
                }
                $p[$key] = $pos;
                $l[$key] = strlen($json);
            }
            if ($key !== null) {
                $n = max($n, $key - $base + 1);
            }
            $pos = $nl + 1;
        }
        ksort($p);
        ksort($l);
        return ['p' => $p, 'l' => $l, 'd' => $d, 't' => $t, 's' => $pos, 'n' => $n];
    }

    /** Complete lines of a file (without "\n"), and what follows the last one */
    private function linesOf(string $file): array
    {
        clearstatcache(true, $file);
        $bytes = is_file($file) ? (string)file_get_contents($file) : '';
        $parts = explode("\n", $bytes);
        $tail = array_pop($parts);
        return [$parts, $tail];
    }

    private static function sorted(array $record): array
    {
        ksort($record);
        return $record;
    }

    /** Every file of a database (LOCK included): path relative to its directory => sha1 */
    private function snapshot(string $name): array
    {
        clearstatcache();
        $out = [];
        foreach ($this->dbFiles($name) as $file) {
            $out[$file] = sha1_file($this->dbPath($name, 'home') . $file);
        }
        ksort($out);
        return $out;
    }

    private function generation(string $name): int
    {
        return $this->lockState($name)['gen'];
    }

    /** Temp files anywhere in the store (atomic writes: {target}.tmp{8 hex}; quarantine: q.tmp{8 hex}) */
    private function tempFiles(): array
    {
        $found = [];
        $walk = function (string $dir) use (&$walk, &$found) {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_dir($dir . $entry)) {
                    $walk($dir . $entry . '/');
                } elseif (preg_match('/\.tmp[0-9a-f]{8}$|^q\.tmp/', $entry) === 1) {
                    $found[] = $entry;
                }
            }
        };
        $walk(rtrim($this->testDbDir, '/') . '/');
        return $found;
    }

    // ------------------------------------------------------------------
    // Data file format: an append-only log of records and tombstones
    // ------------------------------------------------------------------

    /** @test STORAGE_FORMAT.md 6.1: updates append a line, deletes append {"__nonedb_deleted":K} */
    public function dataFileIsAnAppendOnlyLogOfRecordsAndTombstones(): void
    {
        // autoCompactThreshold 1: garbage can never exceed 100% of the record versions, so no compaction
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 1]);
        $file = $this->dataFile('log');

        $this->assertSame(['n' => 3], $db->insert('log', [['v' => 'a'], ['v' => 'b'], ['v' => 'c']]));
        [$lines, $tail] = $this->linesOf($file);
        $this->assertSame('', $tail, 'every line ends with "\n"');
        $this->assertSame([['key' => 0, 'v' => 'a'], ['key' => 1, 'v' => 'b'], ['key' => 2, 'v' => 'c']],
            array_map(function ($l) { return self::sorted(json_decode($l, true)); }, $lines), 'one line per record, carrying its key');
        $inserted = $lines;

        $this->assertSame(['n' => 1], $db->update('log', [['key' => 1], ['set' => ['v' => 'B']]]));
        [$lines] = $this->linesOf($file);
        $this->assertCount(4, $lines);
        $this->assertSame($inserted, array_slice($lines, 0, 3), 'the old line stays until compaction');
        $this->assertSame(['key' => 1, 'v' => 'B'], self::sorted(json_decode($lines[3], true)), 'the new version is appended');

        $this->assertSame(['n' => 1], $db->delete('log', ['key' => 2]));
        [$lines] = $this->linesOf($file);
        $this->assertCount(5, $lines);
        $this->assertSame('{"__nonedb_deleted":2}', $lines[4], 'tombstone format');

        clearstatcache();
        $bytes = file_get_contents($file);
        $this->assertSame(['n' => 0], $db->delete('log', ['key' => 2]), 'already deleted');
        $this->assertSame(['n' => 0], $db->update('log', [['v' => 'nothing'], ['set' => ['v' => 'x']]]));
        $this->assertSame(['n' => 0], $db->delete('log', ['v' => 'nothing']));
        clearstatcache();
        $this->assertSame($bytes, file_get_contents($file), 'writes that match nothing append nothing');

        // In memory: 2 garbage record lines (key 1 replaced, key 2 deleted), 1 tombstone, next key 3
        $this->assertSame($this->modelIndex($file), self::core($this->memIndex($db, 'log')));
        $this->assertSame(['d' => 2, 't' => 1, 'n' => 3], array_intersect_key(self::core($this->memIndex($db, 'log')), ['d' => 0, 't' => 0, 'n' => 0]));
        $this->assertSame([['key' => 0, 'v' => 'a'], ['key' => 1, 'v' => 'B']], array_map([self::class, 'sorted'], $db->find('log', 0)));

        // The data alone gives the same (an index rebuilt from it never resurrects key 2)
        \noneDB::clearStaticCache();
        unlink($this->dbPath('log', 'kix'));
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 1]);
        $this->assertSame([['key' => 0, 'v' => 'a'], ['key' => 1, 'v' => 'B']], array_map([self::class, 'sorted'], $db->find('log', 0)));
        $this->assertSame($this->modelIndex($file), self::core($this->memIndex($db, 'log')));
        $db->insert('log', ['v' => 'd']);
        $this->assertSame(3, $db->find('log', ['v' => 'd'])[0]['key'], 'key 2 is not handed out again');
    }

    /**
     * @test STORAGE_FORMAT.md 6.4: the in-memory index, a checkpoint plus replay and a rebuild from the data
     * all equal an independent model of the data file (offsets, lengths, d, t, s, n)
     */
    public function keyIndexAlwaysEqualsAnIndependentModelOfTheDataFile(): void
    {
        foreach ([
            'plain, no compaction' => ['shardingEnabled' => false, 'autoCompactThreshold' => 1],
            'plain, auto-compaction' => ['shardingEnabled' => false],
            'sharded' => ['shardSize' => 6],
        ] as $case => $config) {
            $this->cleanTestDirectory();
            mt_srand(77);
            $db = $this->db($config);
            $live = 0;
            for ($op = 0; $op < 220; $op++) {
                $kind = mt_rand(0, 9);
                if ($kind < 5 || $live < 3) {
                    $batch = mt_rand(1, 3);
                    $rows = [];
                    for ($i = 0; $i < $batch; $i++) {
                        $rows[] = ['g' => mt_rand(0, 6), 'pad' => str_repeat('p', mt_rand(0, 20))];
                    }
                    $live += $db->insert('mx', $rows)['n'];
                } elseif ($kind < 8) {
                    $db->update('mx', [['g' => mt_rand(0, 6)], ['set' => ['u' => $op]]]);
                } else {
                    $live -= $db->delete('mx', ['g' => mt_rand(0, 6)])['n'];
                }
            }
            $this->assertSame($live, $db->count('mx'), $case);
            $check = function (\noneDB $db, string $when) use ($case, $config) {
                if (!isset($config['shardSize'])) {
                    $this->assertSame($this->modelIndex($this->dataFile('mx')), self::core($this->memIndex($db, 'mx')), "$case: $when");
                    return;
                }
                $meta = json_decode(file_get_contents($this->dbPath('mx', 'shards')), true);
                $ids = array_column($meta['shards'], 'id');
                $this->assertFileDoesNotExist($this->dataFile('mx'), "$case: switched");
                foreach ($ids as $id) {
                    // n of a shard other than the last never hands out keys: compared for the last one only
                    $withN = $id === max($ids);
                    $model = $this->modelIndex($this->dataFile('mx', $id), $id * 6, ($id + 1) * 6);
                    if (!$withN) {
                        unset($model['n']);
                    }
                    $this->assertSame($model, self::core($this->memIndex($db, 'mx', $id), $withN), "$case: $when, shard $id");
                }
            };
            $check($db, 'in memory');
            $records = $db->find('mx', 0);
            \noneDB::clearStaticCache();
            $check($this->db($config), 'checkpoint + replay');
            $this->deleteCheckpoints();
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $check($db, 'rebuilt from the data');
            $this->assertSame($records, $db->find('mx', 0), "$case: same records without checkpoints");
        }
    }

    // ------------------------------------------------------------------
    // Checkpoints
    // ------------------------------------------------------------------

    /**
     * @test STORAGE_FORMAT.md 6.4: a write session writes a checkpoint once the tail reached 64 lines + live/16.
     * Single inserts into a new database (checkpoint at s = 0): after k inserts the tail has k lines and k
     * records, k >= 64 + intdiv(k, 16) first holds for k = 68; then m more lines need
     * m >= 64 + intdiv(68 + m, 16), first true for m = 72 (insert 140).
     */
    public function checkpointIsWrittenWhenTheTailReaches64LinesPlusASixteenthOfTheRecords(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $ckpt = $this->dbPath('cp', 'kix');
        $changedAt = [];
        $previous = null;
        $epochs = [];
        for ($i = 1; $i <= 150; $i++) {
            $db->insert('cp', ['i' => $i]);
            clearstatcache();
            $content = file_get_contents($ckpt);
            if ($i === 1) {
                $first = $this->diskIndex($ckpt);
                $this->assertSame(0, $first['s'], 'the checkpoint of the new database covers nothing yet');
                $this->assertSame(0, $first['n']);
                $epochs[] = $first['e'];
            } elseif ($content !== $previous) {
                $changedAt[] = $i;
                $file = $this->diskIndex($ckpt);
                $epochs[] = $file['e'];
                // Contents of a checkpoint (STORAGE_FORMAT.md 6.4)
                $data = (string)file_get_contents($this->dataFile('cp'));
                $s = strlen($data);
                $this->assertSame($s, $file['s'], "insert $i: covers the whole data file");
                $this->assertSame(hash('crc32b', substr($data, max(0, $s - 256), min(256, $s))), $file['h'], "insert $i: crc32b of the 256 bytes before s");
                $this->assertSame('kix', $file['kind']);
                $this->assertSame(1, $file['v']);
                $model = $this->modelIndex($this->dataFile('cp'));
                $this->assertSame($model, self::core($file), "insert $i: offsets, lengths and counters");
                $this->assertSame($i, $file['n']);
                // Only the documented header members (z and sid belong to shards); no runtime member (ck, tl) on disk
                $members = array_keys($this->readKeyIndexFile($ckpt)['header']);
                sort($members);
                $this->assertSame(['base', 'created', 'd', 'e', 'h', 'kind', 'layout', 'live', 'n', 's', 'slots', 't', 'updated', 'v'], $members, "insert $i: members on disk");
                $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $file['e']);
            }
            $previous = $content;
        }
        $this->assertSame([68, 140], $changedAt, 'checkpoints exactly when the tail reached 64 + live/16 lines');
        $this->assertCount(1, array_unique($epochs), 'the epoch stays while the data file is the same');
    }

    /** @test STORAGE_FORMAT.md 6.4: ... or 1 MiB of tail */
    public function checkpointIsWrittenWhenTheTailReachesOneMebibyte(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $ckpt = $this->dbPath('mib', 'kix');
        $db->insert('mib', ['blob' => str_repeat('x', 1000000)]); // one line of ~1,000,020 bytes < 1,048,576
        $this->assertSame(0, $this->diskIndex($ckpt)['s'], '1 line, below 1 MiB: no checkpoint');
        $db->insert('mib', ['blob' => str_repeat('y', 60000)]);   // tail now ~1,060,040 bytes >= 1 MiB
        clearstatcache();
        $file = $this->diskIndex($ckpt);
        $this->assertSame(filesize($this->dataFile('mib')), $file['s'], 'two lines, above 1 MiB: checkpointed');
        $this->assertSame(2, $file['n']);
    }

    /** @test DURABILITY.md 3: checkpoints are derived files (best effort): a failing checkpoint never fails a write */
    public function aFailingCheckpointNeverFailsTheWriteOrTheRead(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        for ($i = 0; $i < 67; $i++) {
            $db->insert('cpf', ['i' => $i]);
        }
        $armed = true;
        $this->setFaultHook(function ($point) use (&$armed) {
            if ($armed && $point === 'checkpoint:written') {
                throw new \RuntimeException('checkpoint failed');
            }
        });
        $this->assertSame(['n' => 1], $db->insert('cpf', ['i' => 67]), 'the 68th insert ends with a checkpoint (see above), which fails');
        $armed = false;
        \noneDB::clearStaticCache();
        $this->assertSame(range(0, 67), array_column($this->db(['shardingEnabled' => false])->find('cpf', 0), 'i'));

        // A reader that replays a long tail checkpoints it; that failing doesn't fail the read
        $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false]) . ';
            noneDB\Tests\Support\Testing::setFaultHook(function ($point) { if ($point === "scope:commit") { exit(0); } });
            $db->insert("cpf", array_map(function ($n) { return ["i" => $n]; }, range(68, 199)));');
        \noneDB::clearStaticCache();
        $armed = true;
        $this->assertSame(range(0, 199), array_column($this->db(['shardingEnabled' => false])->find('cpf', 0), 'i'));
    }

    /**
     * @test Records are verified against their key when read; a mismatch rebuilds the index once.
     * A checkpoint that fits its data file (s, h) but points key 1 at the line of key 2.
     */
    public function anIndexPointingAtTheWrongLineIsRebuiltByReadsAndByCompaction(): void
    {
        foreach (['read', 'compact'] as $case) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardingEnabled' => false]);
            $db->insert('wl', [['v' => 'a'], ['v' => 'b'], ['v' => 'c']]);
            $file = $this->dataFile('wl');
            $data = file_get_contents($file);
            $model = $this->modelIndex($file);
            $bad = $model;
            $bad['p'][1] = $model['p'][2];
            $bad['l'][1] = $model['l'][2];
            $s = strlen($data);
            $entries = [];
            foreach ($bad['p'] as $key => $offset) {
                $entries[$key] = [$offset, $bad['l'][$key]];
            }
            $this->writeKeyIndexFile($this->dbPath('wl', 'kix'), ['created' => 1, 'updated' => 1, 'n' => 3, 'd' => 0, 't' => 0,
                's' => $s, 'h' => hash('crc32b', substr($data, max(0, $s - 256))), 'e' => 'abcdef01'], $entries);
            \noneDB::clearStaticCache();
            $db = $this->db(['shardingEnabled' => false]);
            if ($case === 'compact') {
                $this->assertTrue($db->compact('wl')['success']);
                $this->assertSame($data, file_get_contents($file), 'compaction of a file without garbage keeps every line');
            }
            $this->assertSame([['v' => 'b', 'key' => 1]], $db->find('wl', ['key' => 1]), $case);
            $this->assertSame(['a', 'b', 'c'], array_column($db->find('wl', 0), 'v'), $case);
            $this->assertSame(self::core($this->modelIndex($file)), self::core($this->memIndex($db, 'wl')), $case);
            $disk = $this->diskIndex($this->dbPath('wl', 'kix'));
            $this->assertSame($this->modelIndex($file)['p'], self::core($disk)['p'], "$case: the rebuilt checkpoint is on disk");
            $this->assertNotSame('abcdef01', $disk['e'], "$case: an index rebuilt without a checkpoint gets a new epoch");
        }
    }

    // ------------------------------------------------------------------
    // Compaction
    // ------------------------------------------------------------------

    /**
     * @test CONFIGURATION.md: compact "when deleted/superseded records exceed this share of its records"
     * (garbage d > threshold * (live + d); exceed = strictly more)
     */
    public function compactionStartsWhenGarbageExceedsTheThresholdShareOfTheRecords(): void
    {
        // threshold 0.5, 10 records, k deletes: d = k, live = 10 - k: k > 0.5 * 10 first for k = 6
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 0.5]);
        $db->insert('ct', array_map(function ($n) { return ['n' => $n]; }, range(0, 9)));
        [$original] = $this->linesOf($this->dataFile('ct'));
        for ($k = 1; $k <= 5; $k++) {
            $db->delete('ct', ['key' => $k - 1]);
            $this->assertSame($k, $this->memIndex($db, 'ct')['d'], "$k deletes: $k of 10 records is not more than half");
            $this->assertCount(10 + $k, $this->linesOf($this->dataFile('ct'))[0]);
        }
        $db->delete('ct', ['key' => 5]);
        $this->assertSame(0, $this->memIndex($db, 'ct')['d'], '6 of 10: compacted');
        clearstatcache();
        $this->assertSame(implode("\n", array_slice($original, 6)) . "\n", file_get_contents($this->dataFile('ct')),
            'only the live records, in key order, as they were written (the highest key 9 is live: no tombstone)');

        // threshold 0.3 (default), 10 records, k updates of different keys: d = k, live = 10:
        // k > 0.3 * (10 + k) first for k = 5 (4 > 4.2 is false, 5 > 4.5 is true)
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('cu', array_map(function ($n) { return ['n' => $n]; }, range(0, 9)));
        for ($k = 1; $k <= 4; $k++) {
            $db->update('cu', [['key' => $k - 1], ['set' => ['u' => true]]]);
            $this->assertSame($k, $this->memIndex($db, 'cu')['d'], "$k updates");
        }
        $db->update('cu', [['key' => 4], ['set' => ['u' => true]]]);
        $this->assertSame(0, $this->memIndex($db, 'cu')['d'], '5 updates: compacted');
        [$lines] = $this->linesOf($this->dataFile('cu'));
        $expected = [];
        foreach (range(0, 9) as $n) {
            $expected[] = $n < 5 ? ['key' => $n, 'n' => $n, 'u' => true] : ['key' => $n, 'n' => $n];
        }
        $this->assertSame($expected, array_map(function ($l) { return self::sorted(json_decode($l, true)); }, $lines),
            'one line per record, the current version, in key order');
    }

    /** @test docs/API.md compact(): keys never change; STORAGE_FORMAT.md 6.2: compaction keeps a tombstone for the highest key */
    public function compactionKeepsTheHighestKeySoKeysAreNeverReusedAfterAnIndexLoss(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 1]);
        $db->insert('hk', [['v' => 'a'], ['v' => 'b'], ['v' => 'c']]);
        $db->update('hk', [['key' => 0], ['set' => ['v' => 'A']]]);
        $db->delete('hk', ['key' => 2]);
        [$lines] = $this->linesOf($this->dataFile('hk'));
        // Lines: a, b, c, A, tombstone 2. Garbage: a (replaced) and c (deleted)
        $this->assertSame(['success' => true, 'freedSlots' => 2, 'totalRecords' => 2, 'sharded' => false], $db->compact('hk'));
        $this->assertSame($lines[3] . "\n" . $lines[1] . "\n" . '{"__nonedb_deleted":2}' . "\n", file_get_contents($this->dataFile('hk')),
            'live records in key order (key 0 is the line of A), then the tombstone of the highest key ever used');
        $this->assertSame(['d' => 0, 't' => 1, 'n' => 3], array_intersect_key(self::core($this->memIndex($db, 'hk')), ['d' => 0, 't' => 0, 'n' => 0]));

        $this->deleteCheckpoints();
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('hk', ['v' => 'd']);
        $this->assertSame([0, 1, 3], array_column($db->find('hk', 0), 'key'), 'key 2 is not reused after compaction and index loss');

        // Sharded: shard 1 (keys 5..9) loses 7, 8, 9; its auto-compaction keeps tombstone 9
        $db = $this->db(['shardSize' => 5]);
        $db->insert('hks', array_map(function ($n) { return ['n' => $n]; }, range(0, 9)));
        [$shard1] = $this->linesOf($this->dataFile('hks', 1));
        $this->assertSame(['n' => 3], $db->delete('hks', ['key' => [7, 8, 9]]));
        // shard 1: d = 3 > 0.3 * (2 + 3): compacted
        $this->assertSame($shard1[0] . "\n" . $shard1[1] . "\n" . '{"__nonedb_deleted":9}' . "\n", file_get_contents($this->dataFile('hks', 1)));
        foreach (['indexes lost' => false, 'indexes and meta lost' => true] as $case => $loseMeta) {
            $this->deleteCheckpoints();
            if ($loseMeta) {
                file_put_contents($this->dbPath('hks', 'shards'), '');
            }
            \noneDB::clearStaticCache();
            $db = $this->db(['shardSize' => 5]);
            $db->insert('hks', ['n' => $case]);
            $key = $db->find('hks', ['n' => $case])[0]['key'];
            $this->assertSame($loseMeta ? 11 : 10, $key, "$case: no key used before is handed out again");
        }
    }

    /**
     * @test CONFIGURATION.md "Recovery log and quarantine": damaged lines are quarantined by compaction, a torn
     * tail by the next write (here: compaction itself); nothing is dropped without a verified copy
     */
    public function compactionQuarantinesDamagedLinesAndTheTornTail(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('cq', [['v' => 'a'], ['v' => 'b'], ['v' => 'c']]);
        $file = $this->dataFile('cq');
        $clean = file_get_contents($file);
        $garbage = "garbage line\n";
        $torn = '{"torn';
        file_put_contents($file, $garbage . $torn, FILE_APPEND);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertTrue($db->compact('cq')['success']);

        $this->assertSame($clean, file_get_contents($file), 'only the three records are left');
        $expected = [
            $this->quarantineName('cq', 'main', strlen($clean), $garbage),
            $this->quarantineName('cq', 'main', strlen($clean) + strlen($garbage), $torn),
        ];
        sort($expected);
        $this->assertSame($expected, $this->quarantineFiles('cq'));
        $this->assertSame($garbage, file_get_contents($this->dbPath('cq', 'quarantine') . $this->quarantineName('cq', 'main', strlen($clean), $garbage)));
        $this->assertSame($torn, file_get_contents($this->dbPath('cq', 'quarantine') . $this->quarantineName('cq', 'main', strlen($clean) + strlen($garbage), $torn)));
        $log = $db->getRecoveryLog('cq');
        $actions = array_column($log, 'action');
        sort($actions);
        $this->assertSame(['cut-tail', 'drop-line', 'skip-line'], $actions);
        foreach ($log as $entry) {
            if ($entry['action'] !== 'skip-line') {
                $this->assertTrue($entry['bytesVerified'], $entry['action']);
                $this->assertContains($entry['quarantine'], $expected, $entry['action']);
            }
        }
        $this->assertSame(['a', 'b', 'c'], array_column($db->find('cq', 0), 'v'));
    }

    /**
     * @test compaction never keeps an unterminated tail, also one that appeared after this process checked its
     * index against the file: the bytes are removed into quarantine (cut-tail or drop-line, both documented as
     * "bytes removed from a data or buffer file, kept in the quarantine file")
     */
    public function compactionNeverKeepsATornTailItFinds(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('ct2', [['v' => 'a'], ['v' => 'b']]);
        $file = $this->dataFile('ct2');
        $clean = file_get_contents($file);
        file_put_contents($file, '{"torn', FILE_APPEND);
        $this->assertTrue($db->compact('ct2')['success']);
        clearstatcache();
        $this->assertSame($clean, file_get_contents($file));
        $name = $this->quarantineName('ct2', 'main', strlen($clean), '{"torn');
        $this->assertSame([$name], $this->quarantineFiles('ct2'));
        $this->assertSame('{"torn', file_get_contents($this->dbPath('ct2', 'quarantine') . $name));
        $log = $db->getRecoveryLog('ct2');
        $this->assertCount(1, $log);
        $this->assertContains($log[0]['action'], ['cut-tail', 'drop-line']);
        $this->assertSame($name, $log[0]['quarantine']);
        $db->insert('ct2', ['v' => 'c']);
        $this->assertSame([0, 1, 2], array_column($db->find('ct2', 0), 'key'));
    }

    // ------------------------------------------------------------------
    // Torn tails and damaged lines
    // ------------------------------------------------------------------

    /**
     * @test STORAGE_FORMAT.md 6.1, 7.2 (recovery never drops bytes silently): the next writer copies an
     * unterminated tail to recovery/q.{segment}.{offset}.{length}.{sha256}.bin (main, s{N}, buffer), verifies it,
     * cuts it, logs cut-tail, and appends where the tail started
     */
    public function tornTailIsQuarantinedExactlyAndTheNextLineStartsWhereItStarted(): void
    {
        $fragment = '{"v":"half' . "\xE2\x82"; // cut inside a UTF-8 sequence, like a torn write
        $cases = [
            'main file' => [['shardingEnabled' => false], null, 'main'],
            'shard 1' => [['shardSize' => 5], 1, 's1'],
        ];
        foreach ($cases as $case => [$config, $shard, $segment]) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->insert('tq', array_map(function ($n) { return ['v' => $n]; }, range(0, 6)));
            $file = $this->dataFile('tq', $shard);
            $before = file_get_contents($file);
            file_put_contents($file, $fragment, FILE_APPEND);

            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->assertSame(range(0, 6), array_column($db->find('tq', 0), 'v'), "$case: the tail is not a record");
            clearstatcache();
            $this->assertSame(strlen($before) + strlen($fragment), filesize($file), "$case: readers never cut");

            $db->insert('tq', ['v' => 'next']);
            $name = $this->quarantineName('tq', $segment, strlen($before), $fragment);
            $this->assertSame([$name], $this->quarantineFiles('tq'), $case);
            $this->assertSame($fragment, file_get_contents($this->dbPath('tq', 'quarantine') . $name), "$case: the exact bytes");
            $after = file_get_contents($file);
            $this->assertSame($before, substr($after, 0, strlen($before)), "$case: nothing before the tail changed");
            $rest = substr($after, strlen($before));
            $this->assertSame("\n", substr($rest, -1));
            $this->assertSame(1, substr_count($rest, "\n"), "$case: exactly one new line where the tail was");
            $this->assertSame(['key' => 7, 'v' => 'next'], self::sorted(json_decode($rest, true)));
            $log = $db->getRecoveryLog('tq');
            $this->assertSame(['cut-tail'], array_column($log, 'action'), $case);
            $this->assertSame($name, $log[0]['quarantine'], $case);
            $this->assertTrue($log[0]['bytesVerified'], $case);
        }

        // Buffer (segment buffer): the next buffered insert cuts the tail where the last complete line ends
        $this->cleanTestDirectory();
        $config = ['bufferEnabled' => true, 'shardingEnabled' => false];
        $db = $this->db($config);
        $db->insert('tb', [['v' => 0], ['v' => 1]]);
        $buffer = $this->dbPath('tb', 'buffer');
        $before = file_get_contents($buffer);
        file_put_contents($buffer, $fragment, FILE_APPEND);
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame(['n' => 1], $db->insert('tb', ['v' => 2]));
        $name = $this->quarantineName('tb', 'buffer', strlen($before), $fragment);
        $this->assertSame([$name], $this->quarantineFiles('tb'), 'buffer');
        $this->assertSame($fragment, file_get_contents($this->dbPath('tb', 'quarantine') . $name));
        $this->assertSame($before . '{"v":2}' . "\n", file_get_contents($buffer), 'buffered records carry no key yet');
        $this->assertSame(['cut-tail'], array_column($db->getRecoveryLog('tb'), 'action'));
        $this->assertSame([['v' => 0, 'key' => 0], ['v' => 1, 'key' => 1], ['v' => 2, 'key' => 2]], $db->find('tb', 0));

        // A read flushes a buffer whose tail is torn: its records are stored, the whole buffer file is kept in
        // quarantine before it is deleted (drop-buffer)
        $db->insert('tb2', [['v' => 0], ['v' => 1]]);
        $buffer = $this->dbPath('tb2', 'buffer');
        file_put_contents($buffer, $fragment, FILE_APPEND);
        $whole = file_get_contents($buffer);
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $this->assertSame([['v' => 0, 'key' => 0], ['v' => 1, 'key' => 1]], $db->find('tb2', 0));
        $this->assertSame([], glob($this->dbPath('tb2', 'gen') . 'buffer/*'));
        $this->assertSame([$this->quarantineName('tb2', 'buffer', 0, $whole)], $this->quarantineFiles('tb2'));
        $this->assertSame($whole, file_get_contents($this->dbPath('tb2', 'quarantine') . $this->quarantineName('tb2', 'buffer', 0, $whole)));
        $this->assertSame(['drop-buffer'], array_column($db->getRecoveryLog('tb2'), 'action'));
    }

    /** @test the next buffered insert cuts a torn buffer tail at its last complete line, also beyond 64 KiB */
    public function aTornBufferTailLongerThan64KiBIsCutAtTheLastCompleteLine(): void
    {
        $db = $this->db(['bufferEnabled' => true, 'shardingEnabled' => false]);
        $db->insert('bt', ['v' => 'one']);
        $buffer = $this->dbPath('bt', 'buffer');
        $before = file_get_contents($buffer);
        $fragment = '{"v":"' . str_repeat('z', 70000); // no "\n" in the last 64 KiB of the file
        file_put_contents($buffer, $fragment, FILE_APPEND);
        \noneDB::clearStaticCache();
        $db = $this->db(['bufferEnabled' => true, 'shardingEnabled' => false]);
        $db->insert('bt', ['v' => 'two']);
        $this->assertSame([$this->quarantineName('bt', 'buffer', strlen($before), $fragment)], $this->quarantineFiles('bt'));
        [$lines, $tail] = $this->linesOf($buffer);
        $this->assertSame('', $tail);
        $this->assertSame([['v' => 'one'], ['v' => 'two']], array_map(function ($l) { return json_decode($l, true); }, $lines));
        $this->assertSame(['one', 'two'], array_column($db->find('bt', 0), 'v'));
    }

    /**
     * @test STORAGE_FORMAT.md 6.1: a record line is a JSON object with an integer key of the segment; a tombstone is exactly
     * {"__nonedb_deleted":K}. Anything else is a damaged line: never a record, never a deletion.
     */
    public function linesThatAreNeitherRecordsNorTombstonesAreNeverApplied(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('nl', [['v' => 'zero'], ['v' => 'one']]);
        $bad = [
            '{"v":"no key"}',
            '{"key":"1","v":"string key"}',
            '{"key":1.0,"v":"float key"}',
            '{"key":-1,"v":"negative key"}',
            '{"__nonedb_deleted":"1"}',
            '{"__nonedb_deleted":1,"x":2}',
            '[1,2,3]',
        ];
        file_put_contents($this->dataFile('nl'), implode("\n", $bad) . "\n", FILE_APPEND);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame([['v' => 'zero', 'key' => 0], ['v' => 'one', 'key' => 1]], $db->find('nl', 0));
        $index = $this->memIndex($db, 'nl');
        $this->assertSame(count($bad), $index['d'], 'each damaged line is garbage');
        $this->assertSame(0, $index['t'], 'no tombstone among them');
        $this->assertSame(2, $index['n']);
        $db->insert('nl', ['v' => 'two']);
        $this->assertSame([0, 1, 2], array_column($db->find('nl', 0), 'key'));
        $this->assertSame(array_fill(0, count($bad), 'skip-line'), array_column($db->getRecoveryLog('nl'), 'action'),
            'each one reported, logged by the write session');

        // Shards: a line whose key belongs to another shard (key K lives in shard intdiv(K, shardSize))
        $db = $this->db(['shardSize' => 5]);
        $db->insert('nls', array_map(function ($n) { return ['n' => $n]; }, range(0, 6)));
        file_put_contents($this->dataFile('nls', 1), '{"n":"intruder","key":2}' . "\n", FILE_APPEND);
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 5]);
        $this->assertSame([['n' => 2, 'key' => 2]], $db->find('nls', ['key' => 2]));
        $this->assertSame(range(0, 6), array_column($db->find('nls', 0), 'n'));
        $this->assertSame(1, $this->memIndex($db, 'nls', 1)['d']);
        $this->assertSame(['n' => 1], $db->delete('nls', ['key' => 2]));
        $this->assertSame([0, 1, 3, 4, 5, 6], array_column($db->find('nls', 0), 'key'), 'a deleted key never comes back');
        // checkIntegrity() reports it as a damaged line of shard 1: after {"n":5,"key":5} and {"n":6,"key":6}
        // (16 bytes each with "\n") at offset 32, 24 bytes + "\n"
        $report = $db->checkIntegrity('nls');
        $this->assertFalse($report['ok']);
        $this->assertSame([[1, 32, 25]], $report['badLines']);
        // compaction quarantines it (drop-line); then nothing is left to report
        $this->assertTrue($db->compact('nls')['success']);
        $this->assertSame([0, 1, 3, 4, 5, 6], array_column($db->find('nls', 0), 'key'));
        $this->assertTrue($db->checkIntegrity('nls')['ok']);
        $this->assertContains('drop-line', array_column($db->getRecoveryLog('nls'), 'action'));
    }

    /**
     * @test CONFIGURATION.md: "An existing file with the same name is reused only if its bytes match"; if the
     * copy can't be verified, nothing is cut and the write fails
     */
    public function aQuarantineFileWithTheSameNameButOtherBytesRefusesTheCut(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('qm', ['v' => 'first']);
        $file = $this->dataFile('qm');
        $before = file_get_contents($file);
        $fragment = '{"half';
        file_put_contents($file, $fragment, FILE_APPEND);
        $planted = $this->dbPath('qm', 'quarantine') . $this->quarantineName('qm', 'main', strlen($before), $fragment);
        @mkdir(dirname($planted), 0777, true);
        file_put_contents($planted, 'XXXXXX'); // same length, other bytes
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        try {
            $db->insert('qm', ['v' => 'second']);
            $this->fail('the write must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('quarantine', $e->getMessage());
        }
        clearstatcache();
        $this->assertSame($before . $fragment, file_get_contents($file), 'nothing cut, nothing appended');
        $this->assertSame('XXXXXX', file_get_contents($planted), 'the existing file is left alone');
        $this->assertSame([], $this->tempFiles(), 'no temp file left');
        $this->assertSame(['first'], array_column($db->find('qm', 0), 'v'));

        unlink($planted);
        $db->insert('qm', ['v' => 'second']);
        $this->assertSame($fragment, file_get_contents($planted));
        $this->assertSame(['first', 'second'], array_column($db->find('qm', 0), 'v'));
    }

    // ------------------------------------------------------------------
    // Write buffer
    // ------------------------------------------------------------------

    private function bufferLines(string $name): array
    {
        return $this->linesOf($this->dbPath($name, 'buffer'))[0];
    }

    private function dataRecords(string $name): array
    {
        return array_map(function ($l) { return json_decode($l, true); }, $this->linesOf($this->dataFile($name))[0]);
    }

    /**
     * @test buffer flush triggers (docs/API.md buffer limits): the count
     * limit, the size limit, a buffer already over a limit before an append, and the flush interval
     */
    public function theBufferIsFlushedAtItsCountSizeAndTimeLimits(): void
    {
        $config = ['bufferEnabled' => true, 'shardingEnabled' => false];

        // Count limit 10: nine inserts stay buffered, the tenth flushes all of them in order
        $db = $this->db($config);
        $db->setBufferCountLimit(10);
        for ($i = 0; $i < 9; $i++) {
            $this->assertSame(['n' => 1], $db->insert('bc', ['i' => $i]));
        }
        $this->assertCount(9, $this->bufferLines('bc'));
        $this->assertSame([], $this->dataRecords('bc'), 'nothing in the data file yet');
        $db->insert('bc', ['i' => 9]);
        $this->assertSame([], $this->bufferLines('bc'));
        $expected = [];
        foreach (range(0, 9) as $i) {
            $expected[] = ['i' => $i, 'key' => $i];
        }
        $this->assertSame($expected, array_map([self::class, 'sorted'], $this->dataRecords('bc')), 'buffer order = key order');

        // Size limit 1024 bytes: lines {"s":"<191 x>"}\n are 200 bytes; 5 = 1000 < 1024, 6 = 1200 >= 1024
        $db = $this->db($config);
        $db->setBufferSizeLimit(1024);
        for ($i = 0; $i < 5; $i++) {
            $db->insert('bs', ['s' => str_repeat('x', 191)]);
        }
        clearstatcache();
        $this->assertSame(1000, filesize($this->dbPath('bs', 'buffer')));
        $this->assertSame([], $this->dataRecords('bs'));
        $db->insert('bs', ['s' => str_repeat('x', 191)]);
        $this->assertSame([], $this->bufferLines('bs'));
        $this->assertSame(range(0, 5), array_column($this->dataRecords('bs'), 'key'));

        // Already over the limit before the append (limit lowered): flushed first, the new record starts a buffer
        $db = $this->db($config);
        for ($i = 0; $i < 4; $i++) {
            $db->insert('bo', ['s' => str_repeat('y', 291), 'i' => $i]); // 4 lines of 306 bytes (1224), default 1 MiB limit
        }
        $this->assertCount(4, $this->bufferLines('bo'));
        $db->setBufferSizeLimit(1024);
        $db->insert('bo', ['i' => 4]);
        $this->assertSame([0, 1, 2, 3], array_column($this->dataRecords('bo'), 'i'));
        $this->assertSame([['i' => 4]], array_map(function ($l) { return json_decode($l, true); }, $this->bufferLines('bo')));

        // Flush interval 30 s: a buffer whose last flush is 31 s old is flushed by the next insert (before its
        // append, which then starts a new buffer)
        $db = $this->db($config);
        $db->setBufferFlushInterval(30);
        $db->insert('bi', ['i' => 0]);
        $this->setInstanceProperty($db, 'bufferLastFlush', ['bi' => time() - 31]);
        $db->insert('bi', ['i' => 1]);
        $this->assertSame([0], array_column($this->dataRecords('bi'), 'i'));
        $this->assertCount(1, $this->bufferLines('bi'));

        // flush(): documented result, then nothing left to flush
        $this->assertSame(['success' => true, 'flushed' => 1, 'error' => null], $db->flush('bi'));
        $this->assertSame(['success' => true, 'flushed' => 0, 'error' => null], $db->flush('bi'));
        $this->assertSame([0, 1], array_column($db->find('bi', 0), 'i'));
        $this->assertSame([0, 1], array_column($db->find('bi', 0), 'key'));
    }

    /** @test buffered records are flushed when the script ends normally (bufferAutoFlushOnShutdown) */
    public function bufferedRecordsAreFlushedWhenTheProcessEnds(): void
    {
        $out = $this->runChild('$db = ' . $this->childDb(['bufferEnabled' => true, 'shardingEnabled' => false]) . ';
            foreach (["a", "b", "c"] as $v) { $db->insert("sd", ["v" => $v]); }
            clearstatcache();
            echo is_file(' . var_export($this->dbPath('sd', 'buffer'), true) . ') ? "buffered" : "not buffered";');
        $this->assertSame('buffered', $out, 'the records were in the buffer when the script ended');
        $this->assertFileDoesNotExist($this->dbPath('sd', 'buffer'));
        $this->assertSame([['key' => 0, 'v' => 'a'], ['key' => 1, 'v' => 'b'], ['key' => 2, 'v' => 'c']],
            array_map([self::class, 'sorted'], $this->dataRecords('sd')), 'flushed at shutdown, in order');
    }

    /**
     * @test STORAGE_FORMAT.md 5.1: a flush renames the buffer to buffer/flushing.{startKey}.jsonl; replaying it
     * after a crash inserts each record exactly once (keys startKey, startKey+1, ...; stored keys are skipped)
     */
    public function anInterruptedBufferFlushIsReplayedExactlyOnce(): void
    {
        $hook = '';

        // Non-sharded: the writer dies right after appending every buffered record to the data file
        $config = ['bufferEnabled' => true, 'shardingEnabled' => false];
        $this->runChild('$db = ' . $this->childDb($config) . ';
            foreach (["a", "b", "c"] as $v) { $db->insert("fx", ["v" => $v]); }
            ' . $hook . '
            noneDB\Tests\Support\Testing::setFaultHook(function ($point, $ctx) { if ($point === "append:after" && substr($ctx["path"] ?? "", -20) === "/segments/main.jsonl") { exit(0); } });
            $db->flush("fx");');
        $this->assertFileExists($this->dbPath('fx', 'flushing', 0), 'precondition: the flush was interrupted after its rename');
        $this->assertCount(3, $this->dataRecords('fx'), 'precondition: the records were appended');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame([['v' => 'a', 'key' => 0], ['v' => 'b', 'key' => 1], ['v' => 'c', 'key' => 2]], $db->find('fx', 0));
        $this->assertCount(3, $this->dataRecords('fx'), 'nothing appended twice');
        $this->assertSame([], glob($this->dbPath('fx', 'gen') . 'buffer/*'));
        $this->assertNotContains('b', $this->lockState('fx')['flags'], 'buffer flag cleared');

        // Sharded: keys 7, 8, 9 go to shard 1 and 10 to shard 2; the writer dies after the shard 1 append
        $config = ['bufferEnabled' => true, 'shardSize' => 5];
        $this->db(['shardSize' => 5])->insert('fxs', array_map(function ($n) { return ['v' => $n]; }, range(0, 6)));
        \noneDB::clearStaticCache();
        $this->runChild('$db = ' . $this->childDb($config) . ';
            foreach ([7, 8, 9, 10] as $v) { $db->insert("fxs", ["v" => $v]); }
            ' . $hook . '
            noneDB\Tests\Support\Testing::setFaultHook(function ($point, $ctx) { if ($point === "append:after" && preg_match("#/segments/s\\\\d+\\\\.jsonl$#", $ctx["path"] ?? "")) { exit(0); } });
            $db->flush("fxs");');
        $this->assertFileExists($this->dbPath('fxs', 'flushing', 7), 'precondition: interrupted flush starting at key 7');
        $this->assertSame([5, 6, 7, 8, 9], array_column(array_map(function ($l) { return json_decode($l, true); },
            $this->linesOf($this->dataFile('fxs', 1))[0]), 'key'), 'precondition: only the shard 1 append happened');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 5]);
        $all = $db->find('fxs', 0);
        $this->assertSame(range(0, 10), array_column($all, 'key'));
        $this->assertSame(range(0, 10), array_column($all, 'v'), 'every buffered record exactly once, in buffer order');
        $this->assertSame(11, $db->count('fxs'));
        $this->assertSame(11, $db->getShardInfo('fxs')['nextKey']);
        $this->assertSame([], glob($this->dbPath('fxs', 'gen') . 'buffer/*'));
    }

    /**
     * @test docs/DURABILITY.md §3, §4: in durable mode appended buffer lines are synced; a failed sync of an
     * append refuses the write and its bytes are cut back (DURABILITY_FAILED, rolled back)
     */
    public function aBufferedInsertWhoseSyncFailsIsRefusedAndUndone(): void
    {
        $this->needsDirectorySyncs();
        $this->setStatic('osFamily', 'Linux'); // durable mode's rules, on every platform
        $db = $this->db(['bufferEnabled' => true, 'shardingEnabled' => false, 'durability' => 'durable']);
        $db->insert('bf', ['v' => 'a']);
        $buffer = $this->dbPath('bf', 'buffer');
        $bytes = file_get_contents($buffer);
        $failed = 0;
        $this->setStatic('syncFn', function ($handle, string $kind, $path) use (&$failed) {
            // the append's sync of the buffer file fails; the sync of its cut succeeds
            return !($kind === 'file' && substr((string)$path, -21) === '/buffer/pending.jsonl' && ++$failed === 1);
        });
        try {
            $db->insert('bf', ['v' => 'b']);
            $this->fail('the insert must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('fsync', $e->getMessage());
            $this->assertSame(\noneDB::DURABILITY_FAILED, $e->getCode());
        }
        $this->assertSame(2, $failed, 'the append and its cut were synced');
        $this->setStatic('syncFn', null);
        clearstatcache();
        $this->assertSame($bytes, file_get_contents($buffer), 'the appended line was undone');
        $this->assertSame([['v' => 'a', 'key' => 0]], $db->find('bf', 0));
    }

    /** @test buffered records survive a flush that can't rename the buffer (the flush fails, nothing is lost) */
    public function aBufferThatCannotBeRenamedForItsFlushKeepsItsRecords(): void
    {
        $db = $this->db(['bufferEnabled' => true, 'shardingEnabled' => false]);
        $db->insert('br', [['v' => 'a'], ['v' => 'b']]);
        $buffer = $this->dbPath('br', 'buffer');
        $bytes = file_get_contents($buffer);
        $this->setStatic('renameFn', function ($from, $to) {
            return strpos($from, '/buffer/pending.jsonl') !== false ? false : rename($from, $to);
        });
        try {
            $db->flush('br');
            $this->fail('the flush must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('buffer', $e->getMessage());
        }
        $this->setStatic('renameFn', null);
        clearstatcache();
        $this->assertSame($bytes, file_get_contents($buffer), 'the buffer is untouched');
        $this->assertSame([], $this->dataRecords('br'));
        $this->assertSame([['v' => 'a', 'key' => 0], ['v' => 'b', 'key' => 1]], $db->find('br', 0));
    }

    // ------------------------------------------------------------------
    // JSON encoding
    // ------------------------------------------------------------------

    /**
     * @test docs/API.md "Floats keep their type"; serialize_precision never truncates stored floats
     * (STORAGE_FORMAT.md 2: serialize_precision = -1); "Values JSON can't represent (invalid UTF-8, NAN, INF): nothing is written"
     */
    public function everyValueIsStoredExactlyWhateverTheSerializePrecision(): void
    {
        $this->precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '5');
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 1]); // keep both lines
        $values = [
            'f' => 28.980345,
            'sum' => 0.1 + 0.2,
            'huge' => 1e100,
            'max' => 1.7976931348623157e308,
            'tiny' => 5e-324,
            'two' => 2.0,
            'negzero' => -0.0,
            'imax' => PHP_INT_MAX,
            'imin' => PHP_INT_MIN,
            'tr' => 'şğüöçıİ',
            'emoji' => "😀 👩\u{200D}💻",
            'cjk' => '中文字符',
            'ctrl' => "a\nb\r\nc\td\0e\u{2028}f\"g\\h/i",
            "field\nwith\nnewlines" => 'ok',
            'ключ' => 'значение',
            'nested' => ['a' => [1, 2.5, ['b' => -0.5]]],
            'empty' => [],
            'emptyObject' => new \stdClass(),
        ];
        $this->assertSame(['n' => 1], $db->insert('fl', $values));
        $this->assertSame(['n' => 1], $db->update('fl', [['key' => 0], ['set' => ['upd' => 1.0000000000000002, 'f2' => 0.30000000000000004]]]));
        ini_set('serialize_precision', (string)$this->precision);

        [$lines, $tail] = $this->linesOf($this->dataFile('fl'));
        $this->assertCount(2, $lines, 'strings with line breaks never break a line');
        $this->assertSame('', $tail);
        $stored = json_decode($lines[1], true);
        \noneDB::clearStaticCache();
        $found = $this->db(['shardingEnabled' => false])->find('fl', 0)[0];
        foreach ([$stored, $found] as $which => $record) {
            $label = $which === 0 ? 'data file' : 'find()';
            foreach (['f', 'sum', 'huge', 'max', 'tiny', 'two', 'imax', 'imin', 'tr', 'emoji', 'cjk', 'ctrl', "field\nwith\nnewlines", 'ключ', 'nested'] as $field) {
                $this->assertSame($values[$field], $record[$field], "$label: $field");
            }
            $this->assertIsFloat($record['two'], "$label: 2.0 stays a float");
            $this->assertIsFloat($record['negzero'], "$label: -0.0 stays a float");
            $this->assertSame('-0.0', json_encode($record['negzero'], JSON_PRESERVE_ZERO_FRACTION), "$label: and keeps its sign");
            $this->assertSame([], $record['empty']);
            $this->assertSame([], $record['emptyObject'], "$label: an object is stored as JSON");
            $this->assertSame(1.0000000000000002, $record['upd'], $label);
            $this->assertSame(0.30000000000000004, $record['f2'], $label);
            $this->assertSame(0, $record['key']);
        }
    }

    /** @test docs/API.md insert()/update() errors: values JSON can't represent are rejected, nothing is written */
    public function unencodableValuesAreRejectedAndNothingIsWritten(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('ue', ['v' => 'ok']);
        $db->find('ue', 0);
        $before = $this->snapshot('ue');
        $this->precision = ini_get('serialize_precision');
        foreach (['-1', '5'] as $precision) {
            ini_set('serialize_precision', $precision);
            foreach (['nan' => NAN, 'inf' => INF, '-inf' => -INF, 'utf8' => "bad\xB1", 'nested' => ['a' => ['b' => NAN]]] as $case => $value) {
                foreach ([['v' => $value], [['v' => 'fine'], ['v' => $value]]] as $i => $data) {
                    $r = $db->insert('ue', $data);
                    $this->assertSame([0, 'n'], [$r['n'], array_key_first($r)], "precision $precision, $case, insert $i");
                    $this->assertStringStartsWith('insert data cannot be encoded as JSON', $r['error'], "precision $precision, $case, insert $i");
                }
                $r = $db->update('ue', [['v' => 'ok'], ['set' => ['w' => $value]]]);
                $this->assertSame(0, $r['n'], "precision $precision, $case, update");
                $this->assertStringStartsWith('update data cannot be encoded as JSON', $r['error'], "precision $precision, $case, update");
            }
        }
        ini_set('serialize_precision', (string)$this->precision);
        $this->assertSame($before, $this->snapshot('ue'), 'no file written, not even the lock state');
        $this->assertSame([['v' => 'ok', 'key' => 0]], $db->find('ue', 0));
    }

    // ------------------------------------------------------------------
    // CRUD contract
    // ------------------------------------------------------------------

    /** @test docs/API.md: the documented error results of insert(), update() and delete(), and no file is touched */
    public function invalidArgumentsReturnTheDocumentedErrorsAndWriteNothing(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('ia', [['v' => 1], ['v' => 2]]);
        $db->find('ia', 0);
        $before = $this->snapshot('ia');
        $gen = $this->generation('ia');

        $insert = [
            'insert data must be array' => ['string', null, 42, 1.5, true, new \stdClass()],
            'insert data must not be empty' => [[]],
            'insert data must be either one record or a list of records' => [
                [0 => ['a' => 1], 'name' => 'x'],  // documented mix
                [0 => ['a' => 1], 2 => ['b' => 2]], // a gap: not 0..n-1
                [1 => ['a' => 1]],                  // does not start at 0
                [['a' => 1], 'scalar'],             // an element that isn't a record
            ],
            'You cannot set key name to key' => [['key' => 'value'], ['key' => null], [['a' => 1], ['key' => 2, 'b' => 3]]],
        ];
        foreach ($insert as $error => $cases) {
            foreach ($cases as $i => $data) {
                $this->assertSame(['n' => 0, 'error' => $error], $db->insert('ia', $data), "insert: $error #$i");
            }
        }
        $updateError = ['n' => 0, 'error' => 'Please check your update parameters'];
        foreach ([
            'string', null, 5, [], [['v' => 1]], ['v', ['set' => ['a' => 1]]], [1 => ['set' => ['a' => 1]]],
            [['v' => 1], 'set'], [['v' => 1], []], [['v' => 1], ['values' => ['a' => 1]]], [['v' => 1], ['set' => 'a']],
            [['v' => 1], ['set' => ['key' => 5]]], [[], ['set' => ['a' => 1, 'key' => 5]]],
        ] as $i => $data) {
            $this->assertSame($updateError, $db->update('ia', $data), "update #$i");
        }
        foreach (['string', 0, null, 1.5, true] as $i => $filter) {
            $this->assertSame(['n' => 0, 'error' => 'Please check your delete parameters'], $db->delete('ia', $filter), "delete #$i");
        }
        $this->assertSame($before, $this->snapshot('ia'), 'no file written');
        $this->assertSame($gen, $this->generation('ia'));
        $this->assertSame([1, 2], array_column($db->find('ia', 0), 'v'));

        // A record with a nested "key" and a single record whose first field is numeric are valid
        $this->assertSame(['n' => 1], $db->insert('ia', ['data' => ['key' => 'nested']]));
        $this->assertSame(['n' => 1], $db->insert('ia', ['a' => 1, 0 => ['x']]));
        $this->assertSame([['data' => ['key' => 'nested'], 'key' => 2]], $db->find('ia', ['key' => 2]));
        $this->assertSame([['a' => 1, 0 => ['x'], 'key' => 3]], $db->find('ia', ['key' => 3]));
    }

    /**
     * @test The generation moves only when a file is written: sessions that write nothing (an update that matches
     * nothing, checkDB of an existing database, an empty flush) keep the other processes' caches
     */
    public function writesThatChangeNothingWriteNoFileAndKeepTheGeneration(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('nw', [['v' => 1], ['v' => 2]]);
        $db->delete('nw', ['v' => 2]);
        $db->find('nw', 0);
        $before = $this->snapshot('nw');
        $gen = $this->generation('nw');

        $this->assertSame(['n' => 0], $db->update('nw', [['v' => 99], ['set' => ['x' => 1]]]));
        $this->assertSame(['n' => 0], $db->delete('nw', ['v' => 99]));
        $this->assertSame(['n' => 0], $db->delete('nw', ['key' => 1]), 'already deleted');
        $this->assertSame(['n' => 1], $db->update('nw', [['v' => 1], ['set' => []]]), 'empty set: the number of matches');
        $this->assertSame(['success' => true, 'flushed' => 0, 'error' => null], $db->flush('nw'));
        $this->assertTrue($db->checkDB('nw'));
        $this->assertFalse($db->createDB('nw'), 'already exists');

        $this->assertSame($before, $this->snapshot('nw'), 'no file written');
        $this->assertSame($gen, $this->generation('nw'));
    }

    /** @test docs/CONFIGURATION.md autoCreateDB false: writes don't create a database, reads return false */
    public function withoutAutoCreateNothingCreatesADatabase(): void
    {
        foreach ([false, true] as $buffered) {
            $this->cleanTestDirectory();
            $db = $this->db(['autoCreateDB' => false, 'bufferEnabled' => $buffered]);
            $this->assertFalse($db->find('ac', 0));
            // Same wording as the documented dropDB() error
            $this->assertSame(['n' => 0, 'error' => 'Database does not exist'], $db->insert('ac', ['v' => 1]), $buffered ? 'buffered' : 'direct');
            $this->assertSame(['n' => 0], $db->update('ac', [[], ['set' => ['v' => 2]]]));
            $this->assertSame(['n' => 0], $db->delete('ac', []));
            $this->assertFalse($db->find('ac', 0));
            $this->assertFileDoesNotExist($this->dataFile('ac'));
            $this->assertFileDoesNotExist($this->dbPath('ac', 'format'));
            $this->assertFileDoesNotExist($this->dbPath('ac', 'buffer'));
        }
    }

    // ------------------------------------------------------------------
    // Sharding
    // ------------------------------------------------------------------

    /** @test docs/API.md convertToSharded(); STORAGE_FORMAT.md 5.1: key K lives in shard intdiv(K, shardSize), keys never change */
    public function theSwitchToShardsKeepsEveryKeyAndRecordAndSplitsTheDataByKeyRange(): void
    {
        foreach (['indexes lost' => 'kix', 'meta lost' => 'meta'] as $case => $lost) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardSize' => 4, 'shardingEnabled' => false, 'autoCompactThreshold' => 1]);
            $model = [];
            foreach (range(0, 10) as $k) {
                $model[$k] = ['n' => $k, 'tag' => 't' . ($k % 3)];
            }
            $db->insert('mg', array_values($model));
            $db->update('mg', [['key' => [1, 9]], ['set' => ['tag' => 'upd']]]);
            $model[1]['tag'] = $model[9]['tag'] = 'upd';
            $db->delete('mg', ['key' => [2, 5, 10]]); // 10: the highest key
            unset($model[2], $model[5], $model[10]);
            $this->assertFalse($db->getShardInfo('mg')['sharded'], 'shardingEnabled off');

            $this->assertSame(['success' => true, 'status' => 'converted'], $db->convertToSharded('mg'));
            // The single layout's segment (generation 1) is gone (STORAGE_FORMAT.md 5.2 CLEANUP)
            $this->assertFileDoesNotExist($this->dbPath('mg', 'home') . 'generations/1/segments/main.jsonl');
            $this->assertFileDoesNotExist($this->dbPath('mg', 'home') . 'generations/1/segments/main.kix');
            $this->assertFileDoesNotExist($this->dataFile('mg'));
            // nextKey 11: shards intdiv(10, 4) + 1 = 3, holding [0,4), [4,8), [8,12)
            foreach ([0 => [0, 1, 3], 1 => [4, 6, 7], 2 => [8, 9]] as $shard => $keys) {
                $records = [];
                foreach ($this->linesOf($this->dataFile('mg', $shard))[0] as $line) {
                    $decoded = json_decode($line, true);
                    if (array_keys($decoded) === ['__nonedb_deleted']) {
                        // a tombstone may only keep a deleted key of this shard
                        $this->assertContains($decoded['__nonedb_deleted'], [8, 9, 10, 11], "shard $shard");
                        $this->assertArrayNotHasKey($decoded['__nonedb_deleted'], $model);
                        continue;
                    }
                    $records[] = self::sorted($decoded);
                }
                $expected = [];
                foreach ($keys as $k) {
                    $expected[] = self::sorted($model[$k] + ['key' => $k]);
                }
                $this->assertSame($expected, $records, "shard $shard: its key range, in key order, current versions only");
            }
            $this->assertSame(['sharded' => true, 'shards' => 3, 'totalRecords' => 8, 'deletedCount' => 0, 'shardSize' => 4, 'nextKey' => 11],
                $db->getShardInfo('mg'));
            $expectedAll = [];
            foreach ($model as $k => $r) {
                $expectedAll[] = $r + ['key' => $k];
            }
            $this->assertSame($expectedAll, $db->find('mg', 0));
            $this->assertSame(['success' => true, 'status' => 'already_sharded'], $db->convertToSharded('mg'));

            // Keys aren't reused after an index loss, nor with a lost shards.json
            if ($lost === 'kix') {
                $this->deleteCheckpoints();
            } else {
                file_put_contents($this->dbPath('mg', 'shards'), '');
            }
            \noneDB::clearStaticCache();
            $db = $this->db(['shardSize' => 4]);
            $this->assertSame($expectedAll, $db->find('mg', 0), $case);
            $db->insert('mg', ['n' => 'new']);
            $this->assertSame(11, $db->find('mg', ['n' => 'new'])[0]['key'], "$case: the deleted highest key 10 is not reused");
        }
    }

    /**
     * @test Compaction and the switch to shards keep a tombstone for the highest key, so keys aren't reused after an index
     * loss either (STORAGE_FORMAT.md 6.2). After a switch whose highest keys (9, 10) were deleted, the shard
     * files alone (shards.json and checkpoints lost, as a power loss can leave them) must still keep key 10: the
     * next key is 11. compactionKeepsTheHighestKeySoKeysAreNeverReusedAfterAnIndexLoss checks
     * the same for compaction.
     */
    public function theSwitchToShardsKeepsATombstoneForTheHighestKey(): void
    {
        $db = $this->db(['shardSize' => 4, 'shardingEnabled' => false, 'autoCompactThreshold' => 1]);
        $db->insert('mt', array_map(function ($n) { return ['n' => $n]; }, range(0, 10)));
        $db->delete('mt', ['key' => [9, 10]]);
        $this->assertSame(['success' => true, 'status' => 'converted'], $db->convertToSharded('mt'));
        $this->deleteCheckpoints();
        file_put_contents($this->dbPath('mt', 'shards'), '');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 4]);
        $this->assertSame(range(0, 8), array_column($db->find('mt', 0), 'key'));
        $db->insert('mt', ['n' => 'new']);
        $this->assertSame(11, $db->find('mt', ['n' => 'new'])[0]['key'], 'keys 9 and 10 were used before');
    }

    /**
     * @test The same rule when the key index is the body of its checkpoint (STORAGE_FORMAT.md §6.4: a process that
     * can't afford the arrays keeps the body) and the highest keys were deleted after it, in a dense and in a sparse
     * body: the highest live key is found below the deleted ones, and the switch keeps the highest key used.
     */
    public function theSwitchToShardsKeepsTheHighestKeyWhenTheIndexIsABody(): void
    {
        // [records, keys deleted before the checkpoint]: 100 of 100 slots live (dense); 200 of 3000 (sparse)
        foreach (['dense' => [100, []], 'sparse' => [3000, range(0, 2799)]] as $label => [$n, $early]) {
            $this->cleanTestDirectory();
            $config = ['shardSize' => 1000, 'shardingEnabled' => false, 'autoCompactThreshold' => 1000];
            $db = $this->db($config);
            $db->insert('mb', array_map(function ($i) { return ['i' => $i]; }, range(0, $n - 1)));
            if ($early !== []) {
                $this->assertSame(count($early), $db->delete('mb', ['key' => $early])['n']);
            }
            $this->assertSame($label, $this->readKeyIndexFile($this->dbPath('mb', 'kix'))['header']['layout'], "precondition: a $label checkpoint");
            \noneDB::clearStaticCache();
            $db = $this->db($config + ['cacheMemoryLimit' => 1]); // the body is never decoded
            $this->assertSame(3, $db->delete('mb', ['key' => [$n - 3, $n - 2, $n - 1]])['n'], $label);
            $this->assertSame(['success' => true, 'status' => 'converted'], $db->convertToSharded('mb'), $label);
            $live = array_values(array_diff(range(0, $n - 4), $early));
            $this->deleteCheckpoints();
            file_put_contents($this->dbPath('mb', 'shards'), '');
            \noneDB::clearStaticCache();
            $db = $this->db($config);
            $this->assertSame($live, array_column($db->find('mb', 0), 'key'), $label);
            $db->insert('mb', ['i' => 'new']);
            $this->assertSame($n, $db->find('mb', ['i' => 'new'])[0]['key'], "$label: keys up to " . ($n - 1) . ' were used before');
        }
    }

    /**
     * @test STORAGE_FORMAT.md §5.2: the switch writes every shard with its key-index checkpoint (§6.4), covering the
     * whole file: a database without field or spatial indexes (nothing loads its shards after the switch) has them too
     */
    public function aSwitchWritesEveryShardWithItsCheckpoint(): void
    {
        $db = $this->db(['shardSize' => 10, 'shardingEnabled' => false]);
        $db->insert('sc', array_map(function ($i) { return ['i' => $i]; }, range(0, 34)));
        $db->delete('sc', ['key' => [3, 12, 34]]);
        $this->assertSame(['success' => true, 'status' => 'converted'], $db->convertToSharded('sc'));
        foreach (range(0, 3) as $shard) {
            $data = $this->dbPath('sc', 'data', $shard);
            $kix = $this->readKeyIndexFile($this->dbPath('sc', 'kix', $shard));
            clearstatcache();
            $this->assertSame([filesize($data), 10 * $shard, $shard, 10, $this->keyIndexOfData($data)],
                [$kix['header']['s'], $kix['header']['base'], $kix['header']['sid'], $kix['header']['z'], $kix['entries']], "shard $shard");
        }
    }

    /**
     * @test STORAGE_FORMAT.md §5.2 PREPARE: the new generation is verified before it is published. A shard file that
     * doesn't hold every record of its range (here a disk that lost its last line) stops the switch: the database
     * stays in the single layout with all its records, the new generation is removed, and the switch works later.
     */
    public function aSwitchWhoseShardLacksARecordIsNotPublished(): void
    {
        $config = ['shardSize' => 10, 'shardingEnabled' => false];
        $db = $this->db($config);
        $db->insert('sv', array_map(function ($i) { return ['i' => $i]; }, range(0, 29)));
        $home = $this->dbPath('sv', 'home');
        $this->setFaultHook(function ($point, $context) use ($home) {
            if ($point === 'switch:shard-written' && $context['shard'] === 1) {
                [$file] = glob($home . 'generations/*/segments/s1.jsonl');
                $lines = file($file);
                array_pop($lines);
                file_put_contents($file, implode('', $lines));
            }
        });
        try {
            $db->convertToSharded('sv');
            $this->fail('the switch was published');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be verified', $e->getMessage());
        } finally {
            $this->setFaultHook(null);
        }
        $this->assertFalse($db->getShardInfo('sv')['sharded']);
        $this->assertSame(range(0, 29), array_column($db->find('sv', 0), 'key'));
        $this->assertSame([basename($this->dbPath('sv', 'gen'))], array_map('basename', glob($home . 'generations/*')), 'only the active generation');
        $this->assertTrue($db->checkIntegrity('sv')['ok']);
        $this->assertSame(['success' => true, 'status' => 'converted'], $db->convertToSharded('sv'));
        $this->assertSame(range(0, 29), array_column($this->db($config)->find('sv', 0), 'key'));
    }

    /** @test docs/CONFIGURATION.md shardingEnabled: "once it has assigned shardSize keys" (assigned, not live) */
    public function theSwitchToShardsStartsOnceShardSizeKeysWereAssigned(): void
    {
        $db = $this->db(['shardSize' => 5]);
        $db->insert('am', array_map(function ($n) { return ['n' => $n]; }, range(0, 3)));
        $db->delete('am', ['key' => [0, 1]]);
        $this->assertFalse($db->getShardInfo('am')['sharded'], '4 keys assigned');
        $db->insert('am', ['n' => 4]);
        $this->assertSame(['sharded' => true, 'shards' => 1, 'totalRecords' => 3, 'deletedCount' => 0, 'shardSize' => 5, 'nextKey' => 5],
            $db->getShardInfo('am'), '5 keys assigned, 3 live');
        $this->assertSame([2, 3, 4], array_column($db->find('am', 0), 'key'));
    }

    /** @test the same operations give the same records on a sharded and a non-sharded database */
    public function shardedAndNonShardedDatabasesGiveTheSameResults(): void
    {
        $plain = $this->db(['shardingEnabled' => false]);
        $sharded = $this->db(['shardSize' => 3]);
        $model = [];
        $next = 0;
        // Shards of 3: [0,3) [3,6) [6,9) [9,12) [12,15); step 5 empties shard 2, which compact() removes
        $script = [
            ['insert', array_map(function ($n) { return ['n' => $n, 'g' => $n % 4]; }, range(0, 9))],
            ['update', ['g' => 1], ['u' => 'one']],
            ['delete', ['key' => [0, 6, 8]]],
            ['insert', [['n' => 10, 'g' => 2], ['n' => 11, 'g' => 1]]],
            ['update', ['key' => [2, 3, 7, 11, 99]], ['g' => 9]],
            ['delete', ['g' => 9]],
            ['compact'],
            ['insert', [['n' => 12, 'g' => 0]]],
            ['update', [], ['all' => true]],
            ['delete', ['n' => 5]],
        ];
        $matches = function (array $record, array $filter) {
            foreach ($filter as $field => $value) {
                if (!array_key_exists($field, $record) || $record[$field] !== $value) {
                    return false;
                }
            }
            return true;
        };
        foreach ($script as $step => $op) {
            if ($op[0] === 'insert') {
                foreach ([$plain, $sharded] as $db) {
                    $this->assertSame(['n' => count($op[1])], $db->insert($db === $plain ? 'eqp' : 'eqs', $op[1]));
                }
                foreach ($op[1] as $r) {
                    $model[$next++] = $r;
                }
            } elseif ($op[0] === 'update' || $op[0] === 'delete') {
                $filter = $op[1];
                $keys = isset($filter['key']) ? array_values(array_intersect($filter['key'], array_keys($model))) : array_keys($model);
                unset($filter['key']);
                $hit = [];
                foreach ($keys as $k) {
                    if ($matches($model[$k], $filter)) {
                        $hit[] = $k;
                    }
                }
                foreach ([$plain, $sharded] as $db) {
                    $name = $db === $plain ? 'eqp' : 'eqs';
                    $r = $op[0] === 'update' ? $db->update($name, [$op[1], ['set' => $op[2]]]) : $db->delete($name, $op[1]);
                    $this->assertSame(['n' => count($hit)], $r, "step $step");
                }
                foreach ($hit as $k) {
                    if ($op[0] === 'update') {
                        $model[$k] = array_merge($model[$k], $op[2]);
                    } else {
                        unset($model[$k]);
                    }
                }
            } else {
                $this->assertTrue($plain->compact('eqp')['success']);
                $result = $sharded->compact('eqs');
                $this->assertTrue($result['success']);
                // shard 2 (keys 6..8) is empty and not the shard of the newest key (11: shard 3): removed
                $this->assertSame(3, $result['newShardCount']);
            }
            ksort($model);
            $expected = [];
            foreach ($model as $k => $r) {
                $expected[] = $r + ['key' => $k];
            }
            $this->assertSame($expected, $plain->find('eqp', 0), "step $step: non-sharded");
            $this->assertSame($expected, $sharded->find('eqs', 0), "step $step: sharded");
            foreach ([['g' => 1], ['key' => [12, 1, 9, 40]], ['g' => 0, 'all' => true]] as $filter) {
                $this->assertSame($plain->find('eqp', $filter), $sharded->find('eqs', $filter), "step $step: " . json_encode($filter));
            }
            $this->assertSame(count($model), $plain->count('eqp'));
            $this->assertSame(count($model), $sharded->count('eqs'));
        }
        $this->assertTrue($sharded->getShardInfo('eqs')['sharded']);
        // live keys at the end: 1, 4, 9, 10, 12; find() by a key list returns them in the order of the list
        $this->assertSame([12, 1, 9], array_column($plain->find('eqp', ['key' => [12, 1, 9, 40]]), 'key'));
        $this->assertSame([12, 1, 9], array_column($sharded->find('eqs', ['key' => [12, 1, 9, 40]]), 'key'));
        $this->assertSame([1, 4, 9, 10, 12], array_column($sharded->find('eqs', 0), 'key'));
    }

    /**
     * @test An unreadable shards.json is rebuilt from the shards. Without checkpoints and with a configured size that
     * doesn't fit, the size is the database's: shard i holds keys in [i*size, (i+1)*size); keys 0..4, 5..9,
     * 10..11 in shards 0, 1, 2 allow only size 5 (5/1 >= size, 9 < 2*size, 11 < 3*size), the size the database
     * was created with (STORAGE_FORMAT.md 4.1)
     */
    public function anUnreadableMetaIsRebuiltWithTheShardSizeTheKeysAllow(): void
    {
        $db = $this->db(['shardSize' => 5]);
        $db->insert('rm', array_map(function ($n) { return ['n' => $n]; }, range(0, 11)));
        $this->deleteCheckpoints();
        file_put_contents($this->dbPath('rm', 'shards'), '');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 10000]);
        $this->assertSame(range(0, 11), array_column($db->find('rm', 0), 'n'));
        $this->assertSame(range(0, 11), array_column($db->find('rm', 0), 'key'));
        $meta = json_decode(file_get_contents($this->dbPath('rm', 'shards')), true);
        $this->assertSame(5, $meta['shardSize']);
        $this->assertSame(12, $meta['totalRecords']);
        $this->assertSame([5, 5, 2], array_column($meta['shards'], 'count'));
        $this->assertSame(['meta-rebuilt'], array_column($db->getRecoveryLog('rm'), 'action'));
        $db->insert('rm', ['n' => 12]);
        $this->assertSame(12, $db->find('rm', ['n' => 12])[0]['key']);
    }

    /** @test STORAGE_FORMAT.md 6.4: a checkpoint that doesn't fit is rebuilt from the data, together with the shard counters */
    public function aShardCheckpointThatDoesNotFitIsRebuiltAndTheCountersRecounted(): void
    {
        $db = $this->db(['shardSize' => 5]);
        $db->insert('sc', array_map(function ($n) { return ['n' => $n]; }, range(0, 11)));
        $ckpt = $this->dbPath('sc', 'kix', 1);
        $file = $this->readKeyIndexFile($ckpt);
        $checkpoint = $file['header'];
        $checkpoint['h'] = $checkpoint['h'] === '00000000' ? '00000001' : '00000000';
        $this->writeKeyIndexFile($ckpt, $checkpoint, $file['entries'], $checkpoint['base'], $checkpoint['layout']);
        $metaPath = $this->dbPath('sc', 'shards');
        $meta = json_decode(file_get_contents($metaPath), true);
        $meta['totalRecords'] = 999;
        $meta['shards'][1]['count'] = 999;
        file_put_contents($metaPath, json_encode($meta));
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 5]);
        $this->assertSame(range(0, 11), array_column($db->find('sc', 0), 'n'));
        $meta = json_decode(file_get_contents($metaPath), true);
        $this->assertSame([5, 5, 2], array_column($meta['shards'], 'count'));
        $this->assertSame(12, $meta['totalRecords']);
        $this->assertSame(12, $db->getShardInfo('sc')['totalRecords']);
        $this->assertNotSame($checkpoint['h'], $this->readKeyIndexFile($ckpt)['header']['h'], 'a checkpoint that fits replaced it');
    }

    // ------------------------------------------------------------------
    // Failures
    // ------------------------------------------------------------------

    /**
     * @test DURABILITY.md 4: an append whose fflush() fails (disk full, I/O error) fails like a short write;
     * an exception undoes the write: the tombstone is cut back, the record stays, counters unchanged
     */
    public function aDeleteWhoseAppendFailsThrowsAndKeepsTheRecord(): void
    {
        foreach (['plain' => ['shardingEnabled' => false], 'sharded' => ['shardSize' => 3]] as $case => $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->insert('fd', array_map(function ($n) { return ['n' => $n]; }, range(0, 6)));
            $db->find('fd', 0);
            $before = $this->snapshot('fd');
            unset($before['LOCK']);
            $info = $db->getShardInfo('fd');
            $this->setStatic('flushFn', function () { return false; });
            try {
                $db->delete('fd', ['n' => 4]);
                $this->fail("$case: the delete must fail");
            } catch (\RuntimeException $e) {
                $this->assertNotSame(\noneDB::OUTCOME_UNKNOWN, $e->getCode(), $case);
            }
            $this->setStatic('flushFn', null);
            $after = $this->snapshot('fd');
            unset($after['LOCK']);
            $this->assertSame($before, $after, "$case: no data, index or meta change");
            $this->assertSame(range(0, 6), array_column($db->find('fd', 0), 'n'), $case);
            $this->assertSame($info, $db->getShardInfo('fd'), $case);
            $this->assertSame(['n' => 1], $db->delete('fd', ['n' => 4]), $case);
            $this->assertSame(6, $db->count('fd'), $case);
        }
    }

    /**
     * @test docs/CONFIGURATION.md: replacing a data file (compaction) is synced;
     * if that fails, compact() fails (it is not undone: "run them again") and the data is unchanged
     */
    public function aCompactionThatCannotSyncItsNewFileFailsAndLosesNothing(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 1]);
        $db->insert('cs', array_map(function ($n) { return ['n' => $n]; }, range(0, 9)));
        $db->delete('cs', ['key' => [1, 2, 3]]);
        $bytes = file_get_contents($this->dataFile('cs'));
        $this->setStatic('syncFn', function ($handle, string $kind) {
            return $kind !== 'file';
        });
        try {
            $db->compact('cs');
            $this->fail('the compaction must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('compaction', $e->getMessage());
        }
        $this->setStatic('syncFn', null);
        clearstatcache();
        $this->assertSame($bytes, file_get_contents($this->dataFile('cs')), 'the data file was not replaced');
        $this->assertSame([], $this->tempFiles(), 'its temp file was removed');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false, 'autoCompactThreshold' => 1]);
        $this->assertSame([0, 4, 5, 6, 7, 8, 9], array_column($db->find('cs', 0), 'n'));
        $this->assertSame(3, $db->compact('cs')['freedSlots'], 'run again: works');
        $this->assertSame(7, count($this->linesOf($this->dataFile('cs'))[0]));
    }

    /**
     * @test docs/CONFIGURATION.md troubleshooting: a data file changed behind a cached index (a backup restore):
     * the write is refused with "changed unexpectedly" and writes nothing, the next operation rebuilds the index
     * from the data and works; a process that only read it so far rebuilds at once
     */
    public function aDataFileThatShrankBehindACachedIndexIsDetected(): void
    {
        foreach (['after a write of this process' => true, 'after a read only' => false] as $case => $wrote) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardingEnabled' => false]);
            $db->insert('sh', [['v' => 'a'], ['v' => 'b'], ['v' => 'c']]);
            if (!$wrote) {
                \noneDB::clearStaticCache();
                $db = $this->db(['shardingEnabled' => false]);
                $this->assertCount(3, $db->find('sh', 0));
            }
            [$lines] = $this->linesOf($this->dataFile('sh'));
            file_put_contents($this->dataFile('sh'), $lines[0] . "\n" . $lines[1] . "\n"); // an older copy restored
            $restored = file_get_contents($this->dataFile('sh'));
            if ($wrote) {
                try {
                    $db->insert('sh', ['v' => 'd']);
                    $this->fail("$case: the write must be refused");
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('changed unexpectedly', $e->getMessage(), $case);
                }
                clearstatcache();
                $this->assertSame($restored, file_get_contents($this->dataFile('sh')), "$case: nothing written");
            }
            $this->assertSame(['n' => 1], $db->insert('sh', ['v' => 'd']), $case);
            $all = $db->find('sh', 0);
            $this->assertSame(['a', 'b', 'd'], array_column($all, 'v'), $case);
            $this->assertSame(count($all), count(array_unique(array_column($all, 'key'))), "$case: keys unique");
            \noneDB::clearStaticCache();
            $this->assertSame($all, $this->db(['shardingEnabled' => false])->find('sh', 0), "$case: the same for a new process");
        }
    }
}
