<?php

namespace noneDB\Tests\Migration;

/**
 * Every fixture migrated (docs/MIGRATION.md): the live data each release returns (its oracle) is what noneDB 4 returns
 * afterwards, record for record (===), with the next key of §5, the layout and shard size of the source, its indexes,
 * and the old files kept byte for byte in the backup and in .nonedb-retained. A fixture the files don't decide stops
 * (§6) and leaves the directory as it was; the options that decide it give the view of the release that defines it.
 */
class MigrationFixtureTest extends MigrationTestCase
{
    public function fixtures(): array
    {
        $names = [];
        foreach (scandir(self::fixturesDir()) as $entry) {
            if (is_file(self::fixturesDir() . "/$entry/fixture.json")) {
                $names[$entry] = [$entry];
            }
        }
        return $names;
    }

    /**
     * The runs of a fixture: [extra options, expected exit status, release whose view the result must equal (null: a
     * stop), databases expected to be registered]. Default: a plain run gives the view of the last release.
     */
    private function runs(string $fixture, array $data): array
    {
        $steps = $data['steps'];
        $last = end($steps)['release'];
        if (preg_match('/^(2\.3|3\.0|3\.1)-flushing$/', $fixture, $m)) {
            // §6.3: 2.3-3.1 never read .flushing (skip = their view); 3.2 imported it with fresh keys (import)
            return [[[], 2, null, true], [['--flushing=skip'], 0, $m[1], true], [['--flushing=import'], 0, '3.2', true]];
        }
        if (preg_match('/^(3\.0|3\.1|3\.2)-index-lost$/', $fixture)) {
            // §6.2: without its index the segment stops; --rebuild-from-data replays the data, as 3.2 rebuilt it
            return [[[], 2, null, true], [['--rebuild-from-data'], 0, '3.2', true]];
        }
        if ($fixture === '3.1-meta-shard-size' || $fixture === '3.2-meta-lost') {
            return [[[], 2, null, true]]; // §4.2, §3: the files don't place the keys
        }
        if ($fixture === '2.2-empty-name') {
            // An empty name has no noneDB 4 form (§6.1): the run stops; --db migrates the rest, the key stays unregistered (§9)
            return [[[], 2, null, true], [['--db=users'], 0, '2.2', false]];
        }
        return [[[], 0, $last, true]];
    }

    /**
     * @test
     * @dataProvider fixtures
     */
    public function theMigrationKeepsWhatTheReleaseReturned(string $name): void
    {
        $data = $this->fixture($name);
        $key = $data['sourceKey'];
        $fixtureDir = self::fixturesDir() . "/$name/db";
        foreach ($this->runs($name, $data) as [$options, $status, $oracle, $registered]) {
            $label = "$name " . json_encode($options);
            $this->cleanTestDirectory();
            $dir = $this->stage($name);
            $backup = $this->testDbDir . 'backup/';
            $before = $this->snapshot($dir);
            [$exit, $out, $stdout, $stderr] = $this->tool(array_merge(["--dir=$dir", "--backup-dir=$backup", '--confirm-stopped', '--verify'], $options), $key);
            $this->assertSame($status, $exit, "$label: exit status\n$stdout$stderr");
            if ($oracle === null) {
                $this->assertContains($out['result'] ?? null, ['AMBIGUOUS_SOURCE', 'UNSUPPORTED_SOURCE'], "$label\n$stdout");
                $this->assertSame($before, $this->snapshot($dir), "$label: a stop writes nothing");
                $this->assertDirectoryDoesNotExist($backup, "$label: no backup either");
                continue;
            }
            $this->assertSame($registered, $out['migrated']['keyRegistered'], $label);
            $run = $out['migrated']['run'];
            $migrated = array_keys($out['migrated']['databases']);
            $this->assertNotEmpty($migrated, $label);
            if ($registered) {
                $db = $this->v4($dir);
                // The tool writes every segment's key-index checkpoint (STORAGE_FORMAT.md §6.4): the engine replays no line
                $replayed = \noneDB\Tests\Support\Testing::get('indexStats')['replayedLines'];
                foreach ($migrated as $db_name) {
                    $db->find($db_name, 0);
                }
                $this->assertSame($replayed, \noneDB\Tests\Support\Testing::get('indexStats')['replayedLines'], "$label: checkpoints used as written");
                $scan = $this->v4($dir, self::TARGET, ['fieldIndexEnabled' => false, 'spatialIndexEnabled' => false]);
                foreach ($migrated as $db_name) {
                    $this->assertDatabase($label, $db, $scan, $db_name, $this->oracleRecords($data, $oracle, $db_name), $fixtureDir, $key, $name);
                }
            } else {
                try {
                    $this->v4($dir)->find('users', 0);
                    $this->fail("$label: the key is not registered by a partial run");
                } catch (\RuntimeException $e) {
                    $this->assertSame(\noneDB::KEY_NOT_REGISTERED, $e->getCode(), $label);
                }
            }
            // The old files of the migrated databases: in the backup and retained, byte for byte; none left in the directory
            $after = $this->snapshot($dir);
            $fixtureFiles = $this->snapshot($fixtureDir);
            foreach ($fixtureFiles as $file => $sha) {
                $owned = $this->ownedBy($file, $key, $migrated);
                if ($owned) {
                    $this->assertArrayNotHasKey($file, $after, "$label: $file left in the directory");
                    $this->assertSame($sha, $after[".nonedb-retained/$run/files/$file"] ?? null, "$label: $file retained");
                    $this->assertSame($sha, hash_file('sha256', "$backup$run/files/$file"), "$label: $file in the backup");
                } elseif (!$this->isKeyLevel($file, $key, $registered)) {
                    $this->assertSame($sha, $after[$file] ?? null, "$label: $file is not this run's and stays as it was");
                }
            }
        }
    }

    /** A migrated database: its records (===), count, next key, layout, indexes, integrity */
    private function assertDatabase(string $label, \noneDB $db, \noneDB $scan, string $name, array $expected, string $fixtureDir, string $key, string $fixture): void
    {
        $label .= " $name";
        $this->assertSame($expected, $this->v4Records($db, $name), "$label: records");
        $this->assertSame(count($expected), $db->count($name), "$label: count");
        $this->assertTrue($db->checkIntegrity($name)['ok'], "$label: checkIntegrity");
        $info = $db->getShardInfo($name);
        $this->assertSame($this->expectedNextKey($fixtureDir, $key, $name, $expected), $info['nextKey'], "$label: next key (§5)");
        $meta = json_decode((string)@file_get_contents($fixtureDir . '/' . hash_pbkdf2('sha256', $name, $key, 1000, 20) . "-$name.nonedb.meta"), true);
        $this->assertSame(is_array($meta), $info['sharded'], "$label: layout");
        $this->assertSame(is_array($meta) ? $meta['shardSize'] : 10000, $info['shardSize'], "$label: shard size");
        // Indexes as the scenario created them (tools/make-migration-fixtures.php), answering as the oracle's records
        $fields = strpos($fixture, 'field-index') !== false || ($fixture === '3.2-two-databases' && $name === 'orders') ? ['city'] : [];
        $this->assertSame($fields, $db->getFieldIndexes($name)['fields'], "$label: field indexes");
        foreach ($fields === [] ? [] : ['ist', 'ank', 'izm', 'bur', 'van', 'none'] as $city) {
            $keys = array_keys(array_filter($expected, function ($r) use ($city) { return ($r['city'] ?? null) === $city; }));
            $this->assertSame($keys, $this->keysOf($db->find($name, ['city' => $city])), "$label: city $city");
        }
        $spatial = strpos($fixture, 'spatial') !== false;
        $this->assertSame($spatial ? ['loc'] : [], $db->getSpatialIndexes($name), "$label: spatial indexes (docs/SPATIAL.md: field names)");
        if ($spatial) {
            $this->assertSame(array_keys($expected), $this->keysOf($db->withinBBox($name, 'loc', -180, -90, 180, 90)), "$label: spatial");
        }
        $this->assertSame($this->keysOf($scan->find($name, 0)), array_keys($expected));
    }

    private function keysOf(array $records): array
    {
        $keys = array_column($records, 'key');
        sort($keys);
        return $keys;
    }

    /** A file of the fixture that belongs to one of the migrated databases of the key */
    private function ownedBy(string $file, string $key, array $names): bool
    {
        foreach ($names as $name) {
            $hash = hash_pbkdf2('sha256', $name, $key, 1000, 20);
            if (strpos($file, "$hash-$name.") === 0 || strpos($file, "$hash-{$name}_s") === 0 || preg_match('/^' . $hash . '(_s\d+)?\.nonedb\./', $file)) {
                return true;
            }
        }
        return false;
    }

    /** Files of the key or the directory the run moves when it completes the key (leftovers, hash caches) */
    private function isKeyLevel(string $file, string $key, bool $registered): bool
    {
        if (!$registered) {
            return false;
        }
        $fingerprint = substr(hash_hmac('sha256', 'nonedb-key-fingerprint-v1', $key), 0, 16);
        return $file === ".nonedb_hash_cache_$fingerprint" || $file === '.nonedb_hash_cache'
            || (preg_match('/^([0-9a-f]{20})-([^_.]*)/', $file, $m) && hash_pbkdf2('sha256', $m[2], $key, 1000, 20) === $m[1]);
    }
}
