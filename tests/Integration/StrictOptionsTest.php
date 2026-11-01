<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * API.md: a method's options are the ones it documents. An unknown option (a misspelled one, too: an option that
 * differs only in letter case or by up to two letters is suggested) and an option of the wrong type are an
 * InvalidArgumentException before anything is done. So a typo can't make migrateKey() go on instead of aborting,
 * or a spatial query quietly drop the distance it was asked for.
 */
class StrictOptionsTest extends RegressionTestCase
{
    private const OTHER = 'another_secret_key_for_option_tests';

    private function rejects(callable $call, string $message, string $label): void
    {
        try {
            $call();
            $this->fail("$label: accepted");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage(), $label);
        }
    }

    /** Every file and directory under $dir with its SHA-256 */
    private function snapshot(string $dir): array
    {
        $out = [];
        $walk = function (string $path, string $rel) use (&$walk, &$out): void {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                $full = $path . '/' . $entry;
                $out[$rel . $entry] = is_dir($full) ? 'dir' : hash_file('sha256', $full);
                if (is_dir($full)) {
                    $walk($full, $rel . $entry . '/');
                }
            }
        };
        $walk(rtrim($dir, '/'), '');
        ksort($out);
        return $out;
    }

    /** @test API.md "Spatial indexes": nodeSize; withinDistance() includeDistance; nearest() maxDistance, includeDistance */
    public function spatialOptionsAreTheDocumentedOnes(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('sp', [['loc' => ['type' => 'Point', 'coordinates' => [1, 1]]], ['loc' => ['type' => 'Point', 'coordinates' => [1.001, 1]]]]);

        $this->rejects(function () use ($db) { $db->createSpatialIndex('sp', 'loc', ['nodesize' => 16]); },
            "unknown option 'nodesize' of createSpatialIndex() (did you mean 'nodeSize'?); the options are: nodeSize", 'createSpatialIndex');
        foreach ([3, 0, -8, '16', 16.0, true, null] as $bad) {
            $this->rejects(function () use ($db, $bad) { $db->createSpatialIndex('sp', 'loc', ['nodeSize' => $bad]); },
                "'nodeSize' must be an integer of at least 4", 'nodeSize ' . var_export($bad, true));
        }
        $this->assertSame([], $db->getSpatialIndexes('sp'), 'no index made by a refused call');
        $this->assertTrue($db->createSpatialIndex('sp', 'loc', ['nodeSize' => 4])['success']);

        $this->rejects(function () use ($db) { $db->withinDistance('sp', 'loc', 1, 1, 1000, ['includeDistances' => true]); },
            "unknown option 'includeDistances' of withinDistance() (did you mean 'includeDistance'?); the options are: includeDistance", 'withinDistance');
        foreach ([1, 'true', null] as $bad) {
            $this->rejects(function () use ($db, $bad) { $db->withinDistance('sp', 'loc', 1, 1, 1000, ['includeDistance' => $bad]); },
                "'includeDistance' must be true or false", 'withinDistance includeDistance ' . var_export($bad, true));
        }
        // Haversine, earth radius 6371 km (SPATIAL.md): 0.001° of longitude at latitude 1° is 111.18 m
        $this->assertSame([0.0, 111.18], array_column($db->withinDistance('sp', 'loc', 1, 1, 1000, ['includeDistance' => true]), '_distance'));

        $this->rejects(function () use ($db) { $db->nearest('sp', 'loc', 1, 1, 1, ['maxdistance' => 10]); },
            "unknown option 'maxdistance' of nearest() (did you mean 'maxDistance'?); the options are: maxDistance, includeDistance", 'nearest');
        $this->rejects(function () use ($db) { $db->nearest('sp', 'loc', 1, 1, 1, ['radius' => 10]); },
            "unknown option 'radius' of nearest(); the options are: maxDistance, includeDistance", 'nearest radius');
        foreach (['far', '10', true, []] as $bad) {
            $this->rejects(function () use ($db, $bad) { $db->nearest('sp', 'loc', 1, 1, 1, ['maxDistance' => $bad]); },
                "'maxDistance' must be a number of meters or null", 'maxDistance ' . var_export($bad, true));
        }
        $this->rejects(function () use ($db) { $db->nearest('sp', 'loc', 1, 1, 1, ['includeDistance' => 'no']); },
            "'includeDistance' must be true or false", 'nearest includeDistance');
        $near = $db->nearest('sp', 'loc', 1, 1, 5, ['maxDistance' => 50, 'includeDistance' => false]);
        $this->assertSame([0], array_column($near, 'key'), 'within 50 m: the first point only');
        $this->assertArrayNotHasKey('_distance', $near[0], 'includeDistance false');
        $this->assertSame([0, 1], array_column($db->nearest('sp', 'loc', 1, 1, 5, ['maxDistance' => null]), 'key'), 'null: no maximum');
    }

    /** @test API.md "Backup and restore": restore() takes 'force', true or false */
    public function restoreTakesForce(): void
    {
        $db = $this->db();
        $db->insert('r', ['a' => 1]);
        $dir = sys_get_temp_dir() . '/nonedb_options_backup_' . bin2hex(random_bytes(4));
        $db->backup('r', $dir);
        $before = $this->snapshot($this->testDbDir);
        try {
            $this->rejects(function () use ($db, $dir) { $db->restore($dir, 'r', ['forse' => true]); },
                "unknown option 'forse' of restore() (did you mean 'force'?); the options are: force", 'restore');
            foreach (['yes', 1, null] as $bad) {
                $this->rejects(function () use ($db, $dir, $bad) { $db->restore($dir, 'r', ['force' => $bad]); },
                    "'force' must be true or false", 'force ' . var_export($bad, true));
            }
            $this->assertSame($before, $this->snapshot($this->testDbDir), 'nothing changed');
            $this->assertSame('present', $db->restore($dir, 'r', ['force' => false])['target']);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /**
     * @test API.md "Key maintenance": migrateKey() takes abort and durability, registerKey() durability, repairStore()
     * manifest, confirmManualRebuild and durability; a refused call writes nothing
     */
    public function keyMaintenanceOptionsAreTheDocumentedOnes(): void
    {
        $key = 'test_secret_key_for_unit_tests';
        $this->db()->insert('k', ['a' => 1]);
        \noneDB::clearStaticCache();
        $before = $this->snapshot($this->testDbDir);
        $dir = $this->testDbDir;
        $cases = [
            'migrateKey' => [function (array $o) use ($dir, $key) { return \noneDB::migrateKey($dir, $key, self::OTHER, $o); },
                ['abrot' => true], "unknown option 'abrot' of migrateKey() (did you mean 'abort'?); the options are: abort, durability",
                [[['abort' => 'yes'], "'abort' must be true or false"], [['abort' => 1], "'abort' must be true or false"],
                 [['durability' => 'durabel'], "'durability' must be 'performance' or 'durable'"]]],
            'registerKey' => [function (array $o) use ($dir, $key) { return \noneDB::registerKey($dir, $key, self::OTHER, $o); },
                ['durabilty' => 'performance'], "unknown option 'durabilty' of registerKey() (did you mean 'durability'?); the options are: durability",
                [[['durability' => null], "'durability' must be 'performance' or 'durable'"]]],
            'repairStore' => [function (array $o) use ($dir, $key) { return \noneDB::repairStore($dir, [$key], $o); },
                ['confirmManualRebuilt' => true], "unknown option 'confirmManualRebuilt' of repairStore() (did you mean 'confirmManualRebuild'?); "
                    . "the options are: manifest, confirmManualRebuild, durability",
                [[['confirmManualRebuild' => 'yes'], "'confirmManualRebuild' must be true or false"],
                 [['manifest' => 5], "'manifest' must be a file path or a store record (array)"],
                 [['manifest' => null], "'manifest' must be a file path or a store record (array)"]]],
        ];
        foreach ($cases as $method => [$call, $unknown, $message, $wrong]) {
            $this->rejects(function () use ($call, $unknown) { $call($unknown); }, $message, $method);
            foreach ($wrong as [$options, $wrongMessage]) {
                $this->rejects(function () use ($call, $options) { $call($options); }, $wrongMessage, $method . ' ' . json_encode($options));
            }
            $this->assertSame($before, $this->snapshot($this->testDbDir), "$method: nothing written");
        }
        $this->assertTrue(\noneDB::registerKey($dir, $key, self::OTHER, ['durability' => 'performance'])['success'], 'the documented options work');
    }
}
