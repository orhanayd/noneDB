<?php

namespace noneDB\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base test case class for noneDB tests
 *
 * Provides common setup, teardown, and utility methods for all tests.
 */
abstract class noneDBTestCase extends TestCase
{
    /**
     * @var \noneDB
     */
    protected $noneDB;

    /**
     * @var string Test database directory
     */
    protected $testDbDir;

    /**
     * @var string Test database name
     */
    protected $testDbName = 'phpunit-test-db';

    /**
     * Set up test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->testDbDir = TEST_DB_DIR;

        // Clean test directory before each test
        $this->cleanTestDirectory();

        // Clear config cache to ensure fresh config loading
        \noneDB::clearConfigCache();

        // Create fresh noneDB instance with test config
        $this->noneDB = new \noneDB([
            'secretKey' => 'test_secret_key_for_unit_tests',
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => true,
            'shardingEnabled' => true,
            'shardSize' => 10000
        ]);

        // getDatabaseContents() flushes the write buffer for consistency
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void
    {
        // PHPUnit keeps every test object until the end of the run: drop the instance (and its engine)
        $this->noneDB = null;
        // Process-wide test hooks never outlive the test that set them
        foreach (['syncFn', 'flushFn', 'writeFn', 'renameFn', 'osFamily'] as $hook) {
            \noneDB\Tests\Support\Testing::set($hook, null);
        }
        // Clean test directory after each test
        $this->cleanTestDirectory();

        parent::tearDown();
    }

    /**
     * Remove all files from test database directory
     */
    protected function cleanTestDirectory(): void
    {
        // Clear PHP's file stat cache
        clearstatcache(true);

        // Clear noneDB's static cache to prevent cross-test pollution
        \noneDB::clearStaticCache();

        // Clear config cache for fresh config on each test
        \noneDB::clearConfigCache();

        if (!file_exists($this->testDbDir)) {
            mkdir($this->testDbDir, 0777, true);
            return;
        }

        // Everything in the directory goes: store files, database directories, maintenance leftovers
        foreach (scandir($this->testDbDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeDirectoryTree(rtrim($this->testDbDir, '/') . '/' . $entry);
            }
        }

        // Clear stat cache again after cleanup
        clearstatcache(true);
    }

    /**
     * Remove a file or a directory with everything in it (no error when it doesn't exist).
     * Close noneDB's kept lock handles first (\noneDB::clearStaticCache()) when the tree holds a store.
     */
    protected function removeDirectoryTree(string $path): void
    {
        $path = rtrim($path, '/');
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeDirectoryTree($path . '/' . $entry);
                }
            }
            @chmod($path, 0777);
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
        clearstatcache(true, $path);
    }

    /**
     * Locator of a database: the name of its directory {locator}.ndb4 in dbDir (docs/STORAGE_FORMAT.md §1),
     * computed independently of noneDB; $key defaults to the key the test instances use
     */
    protected function locatorOf(string $dbName, string $key = 'test_secret_key_for_unit_tests'): string
    {
        return substr(hash_hmac('sha256', 'nonedb4/db/' . $dbName, $key), 0, 32);
    }

    /**
     * keyCheck of a key: how STORE lists it (docs/STORAGE_FORMAT.md §1), computed independently of noneDB
     */
    protected function keyCheckOf(string $key = 'test_secret_key_for_unit_tests'): string
    {
        return substr(hash_hmac('sha256', 'nonedb4/key', $key), 0, 32);
    }

    /**
     * Encode a metadata record (STORE, FORMAT, CURRENT, metadata, ...) as docs/STORAGE_FORMAT.md §2 specifies:
     * one line of JSON (unescaped slashes and Unicode, zero fractions kept, serialize_precision -1), members in
     * the given order, "checksum" last = SHA-256 of the encoding without it followed by "\n".
     * An array formatOptions member that is empty is written as {} (§4.1); other members that must be
     * objects are passed as objects (decode with json_decode($raw) to keep them).
     *
     * @param array|object $record
     */
    /**
     * A sealed record of STORAGE_FORMAT.md §2 read as the format says: the checksum over the bytes before
     * ',"checksum"' plus "}\n", magic and kind; null when it doesn't verify
     */
    protected function openSealedRecord(string $raw, string $kind): ?array
    {
        if (!preg_match('/\A(.*),"checksum":"([0-9a-f]{64})"\}\n\z/s', $raw, $m) || hash('sha256', $m[1] . "}\n") !== $m[2]) {
            return null;
        }
        $record = json_decode($raw, true);
        return is_array($record) && ($record['magic'] ?? null) === 'NONEDB4' && ($record['kind'] ?? null) === $kind ? $record : null;
    }

    protected function sealRecord($record): string
    {
        $record = (array)$record;
        unset($record['checksum']);
        if (array_key_exists('formatOptions', $record) && $record['formatOptions'] === []) {
            $record['formatOptions'] = new \stdClass();
        }
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $body = json_encode($record, $flags);
            $record['checksum'] = hash('sha256', $body . "\n");
            return json_encode($record, $flags) . "\n";
        } finally {
            ini_set('serialize_precision', (string)$precision);
        }
    }

    /**
     * An internal method of the engine, called through noneDB\Tests\Support\Testing:
     * ->invoke($db, ...$args) calls it for that instance
     */
    protected function getPrivateMethod(string $methodName): InternalMethod
    {
        return new InternalMethod($methodName);
    }

    /**
     * A value of $this->noneDB's engine (a setting or a cache)
     * @return mixed
     */
    protected function getPrivateProperty(string $propertyName)
    {
        return \noneDB\Tests\Support\Testing::getOf($this->noneDB, $propertyName);
    }

    /**
     * Set a value of $this->noneDB's engine (a setting or a cache)
     */
    protected function setPrivateProperty(string $propertyName, $value): void
    {
        \noneDB\Tests\Support\Testing::setOf($this->noneDB, $propertyName, $value);
    }

    /**
     * Insert sample data for testing
     *
     * @param string $dbName Database name
     * @param int $count Number of records to insert
     * @return array The inserted data
     */
    protected function insertSampleData(string $dbName, int $count = 3): array
    {
        $data = [];
        for ($i = 1; $i <= $count; $i++) {
            $data[] = [
                'username' => 'user' . $i,
                'email' => 'user' . $i . '@example.com',
                'age' => 20 + $i,
                'active' => ($i % 2 === 0)
            ];
        }

        $this->noneDB->insert($dbName, $data);

        return $data;
    }

    /**
     * Main data file of a (single-layout) database
     *
     * @param string $dbName Database name
     * @return string Full path to its data file
     */
    protected function getDbFilePath(string $dbName): string
    {
        return $this->dbPath($dbName, 'data');
    }

    /**
     * Path of a file of a database in the storage layout (docs/STORAGE_FORMAT.md), by its role:
     *   home, lock, format, current, gen                 the directory, LOCK, FORMAT, CURRENT, active generation
     *   data / kix [shard]                                segments/{main|sN}.jsonl / .kix
     *   shards, indexes                                   shards.json, indexes.json
     *   buffer, flushing <firstKey>                       buffer/pending.jsonl, buffer/flushing.{K}.jsonl
     *   fx / rx <field> [shard], gfx / grx <field>        secondary indexes
     *   recovery, recovery1, quarantine                   recovery/log.jsonl, log.1.jsonl, recovery/
     * The active generation is read from CURRENT (generation 1 when there is none).
     */
    protected function dbPath(string $dbName, string $role = 'data', ...$args): string
    {
        $home = rtrim($this->testDbDir, '/') . '/' . $this->locatorOf($dbName) . '.ndb4/';
        $current = @file_get_contents($home . 'CURRENT');
        $record = $current === false ? null : json_decode($current, true);
        $gen = $home . 'generations/' . (is_array($record) && is_int($record['generation'] ?? null) ? $record['generation'] : 1) . '/';
        $seg = function ($shard) { return $shard === null ? 'main' : 's' . (int)$shard; };
        $token = function ($field) {
            $field = (string)$field;
            return preg_match('/^[a-z0-9_]{1,40}$/', $field) === 1 ? $field
                : substr(strtolower(preg_replace('/[^A-Za-z0-9_]/', '_', $field)), 0, 24) . '-' . substr(hash('sha256', $field), 0, 12);
        };
        switch ($role) {
            case 'home': return $home;
            case 'lock': return $home . 'LOCK';
            case 'format': return $home . 'FORMAT';
            case 'current': return $home . 'CURRENT';
            case 'gen': return $gen;
            case 'data': return $gen . 'segments/' . $seg($args[0] ?? null) . '.jsonl';
            case 'kix': return $gen . 'segments/' . $seg($args[0] ?? null) . '.kix';
            case 'shards': return $gen . 'shards.json';
            case 'indexes': return $gen . 'indexes.json';
            case 'buffer': return $gen . 'buffer/pending.jsonl';
            case 'flushing': return $gen . 'buffer/flushing.' . (int)$args[0] . '.jsonl';
            case 'fx': return $gen . 'indexes/' . $seg($args[1] ?? null) . '.' . $token($args[0]) . '.fx';
            case 'gfx': return $gen . 'indexes/' . $token($args[0]) . '.gfx';
            case 'rx': return $gen . 'spatial/' . $seg($args[1] ?? null) . '.' . $token($args[0]) . '.rx';
            case 'grx': return $gen . 'spatial/' . $token($args[0]) . '.grx';
            case 'recovery': return $home . 'recovery/log.jsonl';
            case 'recovery1': return $home . 'recovery/log.1.jsonl';
            case 'quarantine': return $home . 'recovery/';
        }
        throw new \InvalidArgumentException("unknown database file role $role");
    }

    /**
     * Every file of a database (recursively), relative to its directory, sorted
     * @return string[]
     */
    protected function dbFiles(string $dbName): array
    {
        $home = $this->dbPath($dbName, 'home');
        $files = [];
        $walk = function (string $dir) use (&$walk, &$files, $home): void {
            foreach (@scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_dir($dir . $entry)) {
                    $walk($dir . $entry . '/');
                } else {
                    $files[] = substr($dir . $entry, strlen($home));
                }
            }
        };
        $walk($home);
        sort($files);
        return $files;
    }

    /**
     * Assert that database file exists
     *
     * @param string $dbName Database name
     */
    protected function assertDatabaseExists(string $dbName): void
    {
        $filePath = $this->getDbFilePath($dbName);
        $this->assertFileExists($filePath, "Database file should exist: {$dbName}");
    }

    /**
     * Assert that database file does not exist
     *
     * @param string $dbName Database name
     */
    protected function assertDatabaseNotExists(string $dbName): void
    {
        $filePath = $this->getDbFilePath($dbName);
        $this->assertFileDoesNotExist($filePath, "Database file should not exist: {$dbName}");
    }

    /**
     * Get database contents directly from file
     * Flushes any buffered data first to ensure consistency
     * Computed from the data file alone, like an index rebuilt from it: the last line of a key wins,
     * a tombstone {"__nonedb_deleted":key} deletes (the .kix file is a checkpoint that may lag behind)
     *
     * @param string $dbName Database name
     * @return array|null Returns normalized format: ['data' => [...records...]]
     */
    protected function getDatabaseContents(string $dbName): ?array
    {
        // Flush buffer first to ensure all data is written to file
        $this->noneDB->flush($dbName);

        $filePath = $this->getDbFilePath($dbName);

        if (!file_exists($filePath)) {
            return null;
        }

        $records = [];
        foreach (explode("\n", (string)file_get_contents($filePath)) as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            if (count($record) === 1 && isset($record['__nonedb_deleted'])) {
                unset($records[$record['__nonedb_deleted']]);
            } elseif (isset($record['key'])) {
                $key = $record['key'];
                unset($record['key']);
                $records[$key] = $record;
            }
        }

        // Sort by key to maintain order
        ksort($records);
        return ['data' => array_values($records)];
    }

    // ------------------------------------------------------------------
    // Key-index checkpoints (docs/STORAGE_FORMAT.md §6.4), read and written here independently of the
    // engine: tests check the files against the documented format, and craft files to set up states
    // ------------------------------------------------------------------

    /**
     * Decode a .kix file: ['header' => array, 'entries' => [key => [offset, length]]] (live keys, ascending).
     * Throws when the file breaks the documented format (header, block size, block crc, body length).
     */
    /** A derived file's header with its crc as the last member (STORAGE_FORMAT.md §6.5) */
    protected function sealHeaderLine(string $json): string
    {
        return substr($json, 0, -1) . ',"crc":' . (int)hexdec(hash('crc32b', $json)) . '}';
    }

    /** [header, nodes] of an .rx file (STORAGE_FORMAT.md §6.5): id => [level, box, items], a leaf's items as entries of five integers */
    protected function readRTreeFile(string $path): array
    {
        [$line, $body] = explode("\n", (string)file_get_contents($path), 2);
        $end = strpos($body, "\n");
        $nodes = $end === false ? null : json_decode(substr($body, 0, $end), true);
        if (is_array($nodes)) {
            $at = $end + 1;
            foreach ($nodes as $id => $node) {
                if ($node[0] === 0) {
                    $entries = [];
                    for ($i = 0; $i < $node[2]; $i++, $at += 40) {
                        $entries[] = array_values(unpack('P5', $body, $at));
                    }
                    $nodes[$id][2] = $entries;
                }
            }
        }
        return [$this->openHeaderLine($path, $line), $nodes];
    }

    /** Write an .rx file (STORAGE_FORMAT.md §6.5) of readRTreeFile()'s header and nodes, with the crcs that fit */
    protected function writeRTreeFile(string $path, array $header, array $nodes): void
    {
        $entries = '';
        foreach ($nodes as $id => $node) {
            if ($node[0] === 0) {
                foreach ($node[2] as $entry) {
                    $entries .= pack('P5', ...$entry);
                }
                $nodes[$id][2] = count($node[2]);
            }
        }
        $body = json_encode($nodes) . "\n" . $entries;
        $header['bc'] = crc32($body);
        file_put_contents($path, $this->sealHeaderLine(json_encode($header)) . "\n" . $body);
    }

    /** The members of a derived file's header; throws when its crc doesn't fit (§6.5) */
    protected function openHeaderLine(string $path, string $line): ?array
    {
        $header = json_decode($line, true);
        if (!is_array($header)) {
            return null;
        }
        if (array_key_last($header) !== 'crc') {
            throw new \UnexpectedValueException("$path: the header's last member is not crc");
        }
        $crc = $header['crc'];
        unset($header['crc']);
        $bare = substr($line, 0, (int)strrpos($line, ',"crc":')) . '}';
        if ($crc !== (int)hexdec(hash('crc32b', $bare))) {
            throw new \UnexpectedValueException("$path: the header's crc doesn't fit");
        }
        return $header;
    }

    protected function readKeyIndexFile(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) < 512 || $raw[511] !== "\n") {
            throw new \UnexpectedValueException("$path: no 512-byte header");
        }
        $header = $this->openHeaderLine($path, rtrim(substr($raw, 0, 511), ' '));
        if (!is_array($header) || ($header['kind'] ?? null) !== 'kix' || ($header['v'] ?? null) !== 1) {
            throw new \UnexpectedValueException("$path: not a kix v1 header");
        }
        $body = substr($raw, 512);
        $dense = $header['layout'] === 'dense';
        $per = $dense ? 341 : 255;
        $width = $dense ? 12 : 16;
        $blocks = $header['slots'] === 0 ? 0 : intdiv($header['slots'] - 1, $per) + 1;
        $expected = $blocks === 0 ? 0 : ($blocks - 1) * 4096 + ($dense ? ($header['slots'] - ($blocks - 1) * $per) * 12 + 4 : 4096);
        if (strlen($body) !== $expected) {
            throw new \UnexpectedValueException("$path: body is " . strlen($body) . " bytes, the header says $expected");
        }
        $entries = [];
        for ($b = 0; $b < $blocks; $b++) {
            $block = substr($body, $b * 4096, 4096);
            $data = substr($block, 0, -4);
            if (unpack('V', substr($block, -4))[1] !== (int)hexdec(hash('crc32b', $data))) {
                throw new \UnexpectedValueException("$path: block $b crc mismatch");
            }
            $m = min($per, $header['slots'] - $b * $per);
            for ($i = 0; $i < $m; $i++) {
                if ($dense) {
                    $offset = unpack('P', $data, 8 * $i)[1];
                    $length = unpack('V', $data, 8 * $m + 4 * $i)[1];
                    $key = $header['base'] + $b * $per + $i;
                } else {
                    $key = $header['base'] + unpack('V', $data, 4 * $i)[1];
                    $offset = unpack('P', $data, 4 * $m + 8 * $i)[1];
                    $length = unpack('V', $data, 12 * $m + 4 * $i)[1];
                }
                if ($length > 0) {
                    $entries[$key] = [$offset, $length];
                }
            }
        }
        ksort($entries);
        return ['header' => $header, 'entries' => $entries];
    }

    /**
     * Write a .kix file as the documented format describes it ($header: every member but layout, base and
     * slots, which follow from $entries and $base; layout chosen by the documented rule unless given)
     */
    protected function writeKeyIndexFile(string $path, array $header, array $entries, int $base = 0, ?string $layout = null): void
    {
        ksort($entries);
        $live = count($entries);
        $maxLocal = $live === 0 ? -1 : max(array_keys($entries)) - $base;
        $slots = $maxLocal + 1;
        $layout = $layout ?? ($slots <= max(1024, 4 * $live) ? 'dense' : 'sparse');
        $body = '';
        if ($layout === 'dense') {
            for ($first = 0; $first < $slots; $first += 341) {
                $m = min(341, $slots - $first);
                $offsets = '';
                $lengths = '';
                for ($i = 0; $i < $m; $i++) {
                    [$offset, $length] = $entries[$base + $first + $i] ?? [0, 0];
                    $offsets .= pack('P', $offset);
                    $lengths .= pack('V', $length);
                }
                $chunk = $offsets . $lengths;
                $body .= $chunk . pack('V', (int)hexdec(hash('crc32b', $chunk)));
            }
        } else {
            $slots = $live;
            foreach (array_chunk($entries, 255, true) as $group) {
                $keys = '';
                $offsets = '';
                $lengths = '';
                foreach ($group as $key => [$offset, $length]) {
                    $keys .= pack('V', $key - $base);
                    $offsets .= pack('P', $offset);
                    $lengths .= pack('V', $length);
                }
                $chunk = str_pad($keys . $offsets . $lengths, 4092, "\0");
                $body .= $chunk . pack('V', (int)hexdec(hash('crc32b', $chunk)));
            }
        }
        $header = array_merge(['kind' => 'kix', 'v' => 1], $header, ['layout' => $layout, 'base' => $base, 'slots' => $slots, 'live' => $live]);
        file_put_contents($path, str_pad($this->sealHeaderLine(json_encode($header)), 511, ' ') . "\n" . $body);
    }

    // ------------------------------------------------------------------
    // Paged field indexes .fx (docs/STORAGE_FORMAT.md §6.5.1), read and written independently
    // ------------------------------------------------------------------

    /** Canonical JSON of .fx data pages (STORAGE_FORMAT.md §6.5.1) */
    private const FX_JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS;

    /**
     * Decode an .fx file: [header, map]. The map has the shape of the values: bucket id => key when there is
     * one, => [keys] (ascending) when there are several. Throws when the file breaks the documented format:
     * header, page crc, directory order and coverage, entry encoding.
     */
    protected function readFieldIndexFile(string $path): array
    {
        $raw = (string)file_get_contents($path);
        $newline = strpos($raw, "\n");
        if ($newline === false) {
            throw new \UnexpectedValueException("$path: no header line");
        }
        $header = $this->openHeaderLine($path, substr($raw, 0, $newline));
        if (!is_array($header) || ($header['v'] ?? null) !== 1 || ($header['kind'] ?? null) !== 'fx' || !is_array($header['pg'] ?? null)) {
            throw new \UnexpectedValueException("$path: not a paged field index header");
        }
        if (array_slice(array_keys($header), 0, 4) !== ['kind', 'v', 'field', 'w'] || !is_string($header['w']) || !preg_match('/^[0-9a-f]{16}$/', $header['w'])) {
            throw new \UnexpectedValueException("$path: the header doesn't start with kind, v, field and a 16-hex-digit write token w");
        }
        $pg = $header['pg'];
        $members = array_keys($pg);
        sort($members);
        if ($members !== ['data', 'levels', 'pages', 'root']) {
            throw new \UnexpectedValueException("$path: pg holds root, levels, pages and data, nothing else");
        }
        $body = substr($raw, $newline + 1);
        // A page without its crc (checked)
        $page = function (int $offset, int $length) use ($body, $path): string {
            $bytes = substr($body, $offset, $length);
            if (strlen($bytes) !== $length || $length < 6) {
                throw new \UnexpectedValueException("$path: page at $offset is outside the body");
            }
            $data = substr($bytes, 0, -4);
            if (unpack('V', substr($bytes, -4))[1] !== (int)hexdec(hash('crc32b', $data))) {
                throw new \UnexpectedValueException("$path: page at $offset crc mismatch");
            }
            return $data;
        };
        // A page with a table (directory): [entries, table offsets]; the walk below checks that the table names
        // exactly the entries it finds
        $tabled = function (string $data, int $offset) use ($path): array {
            $count = unpack('v', $data, strlen($data) - 2)[1];
            $tableAt = strlen($data) - 2 - 4 * $count;
            if ($tableAt < 0) {
                throw new \UnexpectedValueException("$path: page at $offset has a table larger than the page");
            }
            $table = $count === 0 ? [] : array_values(unpack('V' . $count, $data, $tableAt));
            return [substr($data, 0, $tableAt), $table];
        };
        $checkTable = function (array $starts, array $table, int $offset) use ($path) {
            if ($starts !== $table) {
                throw new \UnexpectedValueException("$path: the table of the page at $offset doesn't name its entries");
            }
        };
        // Walk the directory from the root: every data page once, in order, first ids matching
        $dataPages = [];
        $walk = function (int $offset, int $length, int $level, ?string $first) use (&$walk, &$dataPages, $page, $tabled, $checkTable, $path) {
            if ($level === 0) {
                $dataPages[] = [$offset, $length, $first];
                return;
            }
            [$data, $table] = $tabled($page($offset, $length), $offset);
            $starts = [];
            for ($o = 0, $previous = null; $o < strlen($data);) {
                $starts[] = $o;
                $n = unpack('V', $data, $o)[1];
                $id = substr($data, $o + 4, $n);
                $child = unpack('V2', $data, $o + 4 + $n);
                if ($previous !== null && strcmp($previous, $id) >= 0) {
                    throw new \UnexpectedValueException("$path: directory not ascending");
                }
                $walk($child[1], $child[2], $level - 1, $id);
                $previous = $id;
                $o += 4 + $n + 8;
            }
            $checkTable($starts, $table, $offset);
        };
        $walk($pg['root'][0], $pg['root'][1], $pg['levels'], null);
        $map = [];
        $expected = 0;
        foreach ($dataPages as [$offset, $length, $first]) {
            if ($offset !== $expected) {
                throw new \UnexpectedValueException("$path: data pages not contiguous");
            }
            $expected = $offset + $length;
            $data = $page($offset, $length);
            // [id, keys] of the page's entries, in page order: one JSON object, "id":key, or "id":[keys] for
            // several keys (ascending); canonical JSON
            $entries = [];
            $object = json_decode($data, true);
            if (!is_array($object) || ($object === [] ? $data !== '{}' : json_encode($object, self::FX_JSON) !== $data)) {
                throw new \UnexpectedValueException("$path: the data page at $offset is not a JSON object in canonical form");
            }
            foreach ($object as $id => $keys) {
                $ok = is_int($keys) || (is_array($keys) && count($keys) !== 1 && $keys === array_values($keys));
                foreach (is_array($keys) ? $keys : [] as $i => $key) {
                    $ok = $ok && is_int($key) && ($i === 0 || $keys[$i - 1] < $key);
                }
                if (!$ok) {
                    throw new \UnexpectedValueException("$path: entry $id of the data page at $offset is not a key or a list of keys");
                }
                $entries[] = [(string)$id, is_int($keys) ? [$keys] : $keys];
            }
            foreach ($entries as $i => [$id, $values]) {
                if ($i === 0 && $first !== null && $first !== $id) {
                    throw new \UnexpectedValueException("$path: directory entry doesn't name the first id of its page");
                }
                if ($map !== [] && strcmp((string)array_key_last($map), $id) >= 0) {
                    throw new \UnexpectedValueException("$path: entries not ascending");
                }
                $map[$id] = count($values) === 1 ? $values[0] : $values;
            }
        }
        if ($expected !== $pg['data']) {
            throw new \UnexpectedValueException("$path: data pages end at $expected, the header says {$pg['data']}");
        }
        return [$header, $map];
    }

    /**
     * Write an .fx file as §6.5.1 describes it ($header: kind, field, s, e, h, ...; pg is computed; $map in the shape
     * readFieldIndexFile() returns)
     */
    protected function writeFieldIndexFile(string $path, array $header, array $map): void
    {
        ksort($map, SORT_STRING);
        // A page holds about 4096 bytes in all; an entry larger than that is a page of its own. A data page: its
        // entries as one JSON object, then the crc. A directory page: entries, their table (u32 offset each,
        // then the u16 count), the crc of all that.
        $crc = function (string $data): string {
            return $data . pack('V', (int)hexdec(hash('crc32b', $data)));
        };
        $sealPage = function (array $entries) use ($crc): string {
            $data = implode('', $entries);
            $offsets = [];
            $at = 0;
            foreach ($entries as $entry) {
                $offsets[] = $at;
                $at += strlen($entry);
            }
            return $crc($data . (empty($offsets) ? '' : pack('V*', ...$offsets)) . pack('v', count($offsets)));
        };
        $sealJson = function (array $members) use ($crc): string {
            return $crc('{' . implode(',', $members) . '}');
        };
        $fits = function (array $entries, string $entry): bool {
            return strlen(implode('', $entries)) + strlen($entry) + 4 * (count($entries) + 1) + 2 + 4 <= 4096;
        };
        $fitsJson = function (array $members, string $member): bool {
            return strlen('{' . implode(',', array_merge($members, [$member])) . '}') + 4 <= 4096;
        };
        $body = '';
        $level = [];
        $current = [];
        $first = null;
        foreach ($map as $id => $values) {
            $id = (string)$id;
            $values = (array)$values;
            sort($values);
            $entry = json_encode($id, self::FX_JSON) . ':' . (count($values) === 1 ? (string)$values[0] : json_encode($values));
            if (!empty($current) && !$fitsJson($current, $entry)) {
                $sealed = $sealJson($current);
                $level[] = [$first, strlen($body), strlen($sealed)];
                $body .= $sealed;
                $current = [];
            }
            if (empty($current)) {
                $first = $id;
            }
            $current[] = $entry;
        }
        if (!empty($current) || empty($level)) {
            $sealed = $sealJson($current);
            $level[] = [$first ?? '', strlen($body), strlen($sealed)];
            $body .= $sealed;
        }
        $dataBytes = strlen($body);
        $pages = count($level);
        $levels = 0;
        while (count($level) > 1) {
            $levels++;
            $next = [];
            $current = [];
            $first = null;
            foreach ($level as [$id, $offset, $length]) {
                $entry = pack('V', strlen($id)) . $id . pack('V2', $offset, $length);
                // A directory page holds at least two entries, even above 4096 bytes
                if (count($current) >= 2 && !$fits($current, $entry)) {
                    $sealed = $sealPage($current);
                    $next[] = [$first, strlen($body), strlen($sealed)];
                    $body .= $sealed;
                    $current = [];
                }
                if (empty($current)) {
                    $first = $id;
                }
                $current[] = $entry;
            }
            $sealed = $sealPage($current);
            $next[] = [$first, strlen($body), strlen($sealed)];
            $body .= $sealed;
            $pages += count($next);
            $level = $next;
        }
        // The header starts with kind, v, field and the write token w, in this order; every write has a new
        // token (16 random hex digits), as the engine's
        $header = ['kind' => $header['kind'], 'v' => 1, 'field' => $header['field'], 'w' => bin2hex(random_bytes(8))] + $header;
        $header['pg'] = ['root' => [$level[0][1], $level[0][2]], 'levels' => $levels, 'pages' => $pages, 'data' => $dataBytes];
        file_put_contents($path, $this->sealHeaderLine(json_encode($header)) . "\n" . $body);
    }

    // ------------------------------------------------------------------
    // Global field index .gfx (docs/STORAGE_FORMAT.md §6.5.2), read and written independently
    // ------------------------------------------------------------------

    /** The bucket id of an indexable value (§6.5.1): its type letter, then its value */
    protected function bucketIdOf($value): string
    {
        if ($value === null) {
            return 'n';
        }
        if (is_bool($value)) {
            return $value ? 't' : 'f';
        }
        if (is_int($value)) {
            return 'i' . $value;
        }
        if (is_float($value)) {
            return 'd' . bin2hex(pack('E', $value + 0.0));
        }
        return 's' . $value;
    }

    /** The six probe rows of a bucket id in filters of $bits bits */
    protected function filterProbes(string $id, int $bits): array
    {
        $d = hash('xxh3', $id, true);
        $x = unpack('N', substr($d, 0, 4))[1];
        $y = unpack('N', substr($d, 4, 4))[1] | 1;
        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = (($x + $i * $y) % 4294967296) % $bits;
        }
        return $rows;
    }

    /** The filter of a shard holding these values: $bits bits (bit r: byte ⌊r / 8⌋, value 1 << (r mod 8)) */
    protected function shardFilter(array $values, int $bits): string
    {
        return $this->shardFilterOfIds(array_map([$this, 'bucketIdOf'], $values), $bits);
    }

    /** The filter of a shard whose field index holds these bucket ids */
    protected function shardFilterOfIds(array $ids, int $bits): string
    {
        $filter = str_repeat("\0", intdiv($bits, 8));
        foreach ($ids as $id) {
            foreach ($this->filterProbes((string)$id, $bits) as $r) {
                $filter[intdiv($r, 8)] = chr(ord($filter[intdiv($r, 8)]) | (1 << ($r % 8)));
            }
        }
        return $filter;
    }

    /** True when a filter may hold the value (its six probe bits are set) */
    protected function filterMayHold(string $filter, $value): bool
    {
        foreach ($this->filterProbes($this->bucketIdOf($value), 8 * strlen($filter)) as $r) {
            if ((ord($filter[intdiv($r, 8)]) & (1 << ($r % 8))) === 0) {
                return false;
            }
        }
        return true;
    }

    /** Rows per page of a .gfx (§6.5.2): P = min(M, max(1, 4096 / (W / 8))) */
    protected function globalPageRows(int $bits, int $width): int
    {
        return min($bits, max(1, intdiv(4096, intdiv($width, 8))));
    }

    /** Where shard $shard's bit of row $row is in a .gfx file: [byte offset in the file, bit value] */
    protected function globalBitAt(string $path, array $header, int $row, int $shard): array
    {
        $rb = intdiv($header['width'], 8);
        $per = $this->globalPageRows($header['bits'], $header['width']);
        $at = strpos((string)file_get_contents($path), "\n") + 1 + intdiv($row, $per) * ($per * $rb + 4) + ($row % $per) * $rb + intdiv($shard, 8);
        return [$at, 1 << ($shard % 8)];
    }

    /**
     * Decode a .gfx file: [header, filters] (shard id => its filter, $bits bits, for every shard of sh). Throws when
     * the file breaks §6.5.2: header members and their order, sizes, shard ids, body length, page crcs.
     */
    protected function readGlobalFieldFile(string $path): array
    {
        $raw = (string)file_get_contents($path);
        $newline = strpos($raw, "\n");
        if ($newline === false) {
            throw new \UnexpectedValueException("$path: no header line");
        }
        $header = $this->openHeaderLine($path, substr($raw, 0, $newline));
        if (!is_array($header) || ($header['kind'] ?? null) !== 'gfx' || ($header['v'] ?? null) !== 1) {
            throw new \UnexpectedValueException("$path: not a global field index header");
        }
        if (array_slice(array_keys($header), 0, 4) !== ['kind', 'v', 'field', 'w'] || !is_string($header['w']) || !preg_match('/^[0-9a-f]{16}$/', $header['w'])) {
            throw new \UnexpectedValueException("$path: the header doesn't start with kind, v, field and a 16-hex-digit write token w");
        }
        $bits = $header['bits'] ?? null;
        $width = $header['width'] ?? null;
        foreach ([[$bits, 64], [$width, 8]] as [$size, $min]) {
            if (!is_int($size) || $size < $min || ($size & ($size - 1)) !== 0) {
                throw new \UnexpectedValueException("$path: bits (at least 64) and width (at least 8) are powers of two");
            }
        }
        if (!is_array($header['sh'] ?? null)) {
            throw new \UnexpectedValueException("$path: no shard entries");
        }
        foreach ($header['sh'] as $shard => $entry) {
            if (!is_int($shard) || $shard < 0 || $shard >= $width || !is_array($entry) || !isset($entry['s'], $entry['e'], $entry['h'])) {
                throw new \UnexpectedValueException("$path: shard entry $shard is not a shard below the width with s, e and h");
            }
        }
        $rb = intdiv($width, 8);
        $per = $this->globalPageRows($bits, $width);
        $body = substr($raw, $newline + 1);
        if (strlen($body) !== intdiv($bits, $per) * ($per * $rb + 4)) {
            throw new \UnexpectedValueException("$path: the body is not bits / P pages of P rows of width / 8 bytes and a crc");
        }
        $rows = '';
        for ($p = 0; $p < intdiv($bits, $per); $p++) {
            $page = substr($body, $p * ($per * $rb + 4), $per * $rb);
            if (unpack('V', substr($body, $p * ($per * $rb + 4) + $per * $rb, 4))[1] !== (int)hexdec(hash('crc32b', $page))) {
                throw new \UnexpectedValueException("$path: page $p crc mismatch");
            }
            $rows .= $page;
        }
        $filters = [];
        foreach (array_keys($header['sh']) as $shard) {
            $filter = str_repeat("\0", intdiv($bits, 8));
            for ($r = 0; $r < $bits; $r++) {
                if (ord($rows[$r * $rb + intdiv($shard, 8)]) & (1 << ($shard % 8))) {
                    $filter[intdiv($r, 8)] = chr(ord($filter[intdiv($r, 8)]) | (1 << ($r % 8)));
                }
            }
            $filters[$shard] = $filter;
        }
        return [$header, $filters];
    }

    /**
     * Write a .gfx file as §6.5.2 describes it ($header: field, le, bits, width, sh; $filters: shard => its filter;
     * every other bit zero)
     */
    protected function writeGlobalFieldFile(string $path, array $header, array $filters): void
    {
        $bits = $header['bits'];
        $rb = intdiv($header['width'], 8);
        $rows = str_repeat("\0", $bits * $rb);
        foreach ($filters as $shard => $filter) {
            for ($r = 0; $r < $bits; $r++) {
                if (ord($filter[intdiv($r, 8)]) & (1 << ($r % 8))) {
                    $at = $r * $rb + intdiv($shard, 8);
                    $rows[$at] = chr(ord($rows[$at]) | (1 << ($shard % 8)));
                }
            }
        }
        $per = $this->globalPageRows($bits, $header['width']);
        $body = '';
        for ($p = 0; $p < intdiv($bits, $per); $p++) {
            $page = substr($rows, $p * $per * $rb, $per * $rb);
            $body .= $page . pack('V', (int)hexdec(hash('crc32b', $page)));
        }
        $header = ['kind' => 'gfx', 'v' => 1, 'field' => $header['field'], 'w' => bin2hex(random_bytes(8))] + $header;
        $header['sh'] = (object)$header['sh']; // an object, also for shards 0 … n
        file_put_contents($path, $this->sealHeaderLine(json_encode($header)) . "\n" . $body);
    }

    /**
     * The key index a data file implies (independent model): live key => [offset, length] of its last line
     */
    protected function keyIndexOfData(string $dataFile): array
    {
        $entries = [];
        $offset = 0;
        foreach (explode("\n", (string)file_get_contents($dataFile)) as $i => $line) {
            $record = json_decode($line, true);
            if (is_array($record) && count($record) === 1 && isset($record['__nonedb_deleted'])) {
                unset($entries[$record['__nonedb_deleted']]);
            } elseif (is_array($record) && isset($record['key'])) {
                $entries[$record['key']] = [$offset, strlen($line)];
            }
            $offset += strlen($line) + 1;
        }
        ksort($entries);
        return $entries;
    }

    /**
     * Create a new noneDB instance configured for test directory
     *
     * @return \noneDB
     */
    protected function createTestInstance(): \noneDB
    {
        return new \noneDB([
            'secretKey' => 'test_secret_key_for_unit_tests',
            'dbDir' => $this->testDbDir,
            'autoCreateDB' => true,
            'shardingEnabled' => true,
            'shardSize' => 10000
        ]);
    }
}

