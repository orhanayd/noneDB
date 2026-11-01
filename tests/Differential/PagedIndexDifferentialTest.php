<?php

namespace noneDB\Tests\Differential;

use noneDB\Tests\Support\Testing;
use noneDB\Tests\RegressionTestCase;

/**
 * Paged field indexes (docs/STORAGE_FORMAT.md §6.5.1) against an independent model of the data: every value
 * of a field, over many pages and an entry larger than a page, typed values next to each other ("7", 7,
 * 7.5, true, null), the lines written after the index, a reader's checkpoint, a tiny cache; single and
 * sharded layouts. Every value is asked twice per process: the first lookup of a page walks it, the next
 * one uses its map.
 */
class PagedIndexDifferentialTest extends RegressionTestCase
{
    /** Half the records hold "shared" (an entry larger than a page), the rest one of eight kinds of values */
    private static function value(int $i)
    {
        if ($i % 2 === 0) {
            return 'shared';
        }
        $j = $i >> 1;
        switch ($j % 8) {
            case 0: return 'v' . $j;
            case 1: return str_repeat(chr(97 + $j % 26), 1 + $j % 300); // up to 300 bytes
            case 2: return $j;
            case 3: return (string)$j;                                   // "7" next to 7
            case 4: return $j + 0.5;
            case 5: return $j % 4 === 1;
            case 6: return null;
            default: return -$j;
        }
    }

    /** The model grouped by value: serialize(value) => keys holding a value identical (===) to it, ascending */
    private static function modelGroups(array $model): array
    {
        $groups = [];
        foreach ($model as $key => $v) {
            $groups[serialize($v)][] = $key;
        }
        return $groups;
    }

    /** Every value in the model and a few that are not there */
    private static function probes(array $model): array
    {
        $probes = [];
        foreach ($model as $v) {
            $probes[serialize($v)] = $v;
        }
        foreach (['absent', '', 999999, -999999, 1.25, 'v-1', str_repeat('z', 301)] as $v) {
            $probes[serialize($v)] = $v;
        }
        return array_values($probes);
    }

    /**
     * Every value through find() and through the index itself: the candidates of every segment (point
     * lookups, or the loaded index) must be exactly the model's keys. find() checks its candidates against
     * the records, so only the candidates show an index that holds too many keys.
     */
    private function assertLookups(array $config, array $model, string $phase): void
    {
        // Twice: with room in the cache (an index used by a second operation is loaded whole), and near the
        // cache limit (pages only, every lookup through them)
        $this->assertLookupsWith($config + ['cacheMemoryLimit' => 0], $model, "$phase (cache with room)"); // process-wide: set each time
        \noneDB::clearStaticCache();
        $this->assertLookupsWith($config + ['cacheMemoryLimit' => memory_get_usage() + 8 * 1048576], $model, "$phase (pages only)");
    }

    private function assertLookupsWith(array $config, array $model, string $phase): void
    {
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        $probes = self::probes($model);
        $groups = self::modelGroups($model);
        $segments = Testing::call($db, 'listSegments', 'pd');
        foreach ([1, 2] as $round) {
            foreach ($probes as $value) {
                $label = "$phase, round $round: " . var_export($value, true);
                $expected = $groups[serialize($value)] ?? [];
                $this->assertSame($expected, array_column($db->find('pd', ['v' => $value]), 'key'), $label);
                $candidates = Testing::call($db, 'withLock', 'pd', false, function () use ($db, $segments, $value) {
                    $keys = [];
                    foreach ($segments as $segment) {
                        $found = Testing::call($db, 'fieldIndexCandidates', 'pd', $segment, ['v' => $value]);
                        $this->assertIsArray($found, 'the index answers');
                        $keys = array_merge($keys, $found);
                    }
                    sort($keys);
                    return $keys;
                }, 'read');
                $this->assertSame($expected, $candidates, "$label (index candidates)");
            }
        }
    }

    /**
     * Writes after the index, the model kept in step: values moved (into and out of "shared"), keys
     * deleted, new keys. $pad: bytes added to each line (a long tail passes 64 KiB). $next: the key the
     * next insert gets (keys are never used again, a deleted highest key included)
     */
    private function write(array $config, array &$model, int &$next, int $seed, int $moves, int $pad): void
    {
        \noneDB::clearStaticCache();
        $db = $this->db($config);
        mt_srand($seed);
        $extra = $pad > 0 ? ['pad' => str_repeat('p', $pad)] : [];
        for ($n = 0; $n < $moves; $n++) {
            $key = mt_rand(0, $next - 1);
            $value = $n % 3 === 0 ? 'shared' : self::value(mt_rand(0, 5000) | 1);
            $db->update('pd', [['key' => $key], ['set' => ['v' => $value] + $extra]]);
            if (array_key_exists($key, $model)) {
                $model[$key] = $value;
            }
        }
        for ($n = 0; $n < intdiv($moves, 3); $n++) {
            $key = mt_rand(0, $next - 1);
            $db->delete('pd', ['key' => $key]);
            unset($model[$key]);
        }
        $new = [];
        for ($i = $next; $i < $next + intdiv($moves, 2); $i++) {
            $new[] = ['v' => self::value($i * 7)] + $extra;
            $model[$i] = self::value($i * 7);
        }
        $this->assertSame(count($new), $db->insert('pd', $new)['n']);
        $next += count($new);
        ksort($model);
    }

    /** @test */
    public function everyValueEqualsTheModelThroughWritesACheckpointAndATinyCache(): void
    {
        foreach ([['shardingEnabled' => false], ['shardSize' => 1000]] as $config) {
            $this->cleanTestDirectory();
            $label = isset($config['shardSize']) ? 'sharded' : 'single';
            $db = $this->db($config);
            $model = [];
            $rows = [];
            for ($i = 0; $i < 2700; $i++) {
                $model[$i] = self::value($i);
                $rows[] = ['v' => $model[$i]];
            }
            $db->insert('pd', $rows);
            $next = 2700;
            $db->createFieldIndex('pd', 'v');
            $fx = $this->dbPath('pd', 'fx', 'v', isset($config['shardSize']) ? 0 : null);
            if (!isset($config['shardSize'])) {
                [$header, $map] = $this->readFieldIndexFile($fx);
                $this->assertGreaterThan(1, $header['pg']['pages'], 'precondition: several pages');
                $this->assertGreaterThan(4096, 4 * count($map['sshared']), 'precondition: an entry larger than a page');
            }
            $this->assertLookups($config, $model, "$label: after createFieldIndex");

            // A short tail (well under 64 KiB): answered by point lookups, the pages and the newer lines
            $covered = $this->readFieldIndexFile($fx)[0]['s'];
            $this->write($config, $model, $next, 7, 120, 0);
            $this->assertLookups($config, $model, "$label: a short tail");
            $this->assertSame($covered, $this->readFieldIndexFile($fx)[0]['s'], 'precondition: no checkpoint for a short tail');

            // A long tail: the first reader loads the index and writes a checkpoint
            $this->write($config, $model, $next, 8, 300, 200);
            $this->assertLookups($config, $model, "$label: a long tail");
            if (!isset($config['shardSize'])) {
                $this->assertGreaterThan($covered, $this->readFieldIndexFile($fx)[0]['s'], 'precondition: the reader wrote a checkpoint');
            }
            $this->assertLookups($config, $model, "$label: after the checkpoint");

            // A short tail after the new file
            $this->write($config, $model, $next, 9, 120, 0);
            $this->assertLookups($config, $model, "$label: a short tail after the checkpoint");
            $this->assertLookupsWith($config + ['cacheMemoryLimit' => 1], $model, "$label: a tiny cache");
        }
    }
}
