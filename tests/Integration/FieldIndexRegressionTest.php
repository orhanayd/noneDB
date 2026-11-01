<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Field indexes: Y-08, Y-12, O-28, O-30, P-02 and crash recovery of secondary indexes
 */
class FieldIndexRegressionTest extends RegressionTestCase
{
    /** @test Y-08 */
    public function fieldNamesWithAnyCharactersAreMaintained(): void
    {
        $db = $this->db();
        $db->insert('fx', [['e-mail' => 'a@x', 'e_mail' => 'under', 'şehir' => 'İzmir', 'first.name' => 'Ada']]);
        foreach (['e-mail', 'e_mail', 'şehir', 'first.name'] as $field) {
            $this->assertTrue($db->createFieldIndex('fx', $field)['success'], $field);
        }
        $db->insert('fx', ['e-mail' => 'b@x', 'e_mail' => 'u2', 'şehir' => 'Bursa', 'first.name' => 'Bob']);

        \noneDB::clearStaticCache();
        $db = $this->db();
        $this->assertSame('Bob', $db->find('fx', ['e-mail' => 'b@x'])[0]['first.name']);
        $this->assertSame('u2', $db->find('fx', ['e_mail' => 'u2'])[0]['e_mail']);
        $this->assertSame('b@x', $db->find('fx', ['şehir' => 'Bursa'])[0]['e-mail']);
        $this->assertSame('Bob', $db->find('fx', ['first.name' => 'Bob'])[0]['first.name']);
        $this->assertSame([1], array_column($db->find('fx', ['şehir' => 'Bursa']), 'key'));
        $this->assertSame([0], array_column($db->find('fx', ['e-mail' => 'a@x']), 'key'));
        $this->assertEqualsCanonicalizing(['e-mail', 'e_mail', 'şehir', 'first.name'], $db->getFieldIndexes('fx')['fields']);
        $this->assertCount(4, glob($this->dbPath('fx', 'gen') . 'indexes/main.*.fx'), 'one file per field, no collisions');
        // Tokens (STORAGE_FORMAT.md §5.1): "e_mail" is itself; the others get a lower-case prefix and 12 hex of
        // their sha256; the file is indexes/{segment}.{token}.fx
        $base = $this->dbPath('fx', 'gen') . 'indexes/main.';
        $this->assertFileExists($base . 'e_mail.fx');
        foreach (['e-mail' => 'e_mail', 'şehir' => '__ehir', 'first.name' => 'first_name'] as $field => $prefix) {
            $this->assertFileExists($base . $prefix . '-' . substr(hash('sha256', $field), 0, 12) . '.fx', $field);
        }
    }

    /** @test Y-12 */
    public function nullAndTypeTransitionsAreIndexed(): void
    {
        $db = $this->db();
        $db->insert('nt', [['x' => 'a'], ['y' => 1], ['x' => ['arr']], ['x' => null]]);
        $db->createFieldIndex('nt', 'x');
        $db->update('nt', [['y' => 1], ['set' => ['x' => null]]]);          // missing -> null
        $db->update('nt', [['key' => 2], ['set' => ['x' => 'b']]]);         // array -> scalar
        $db->update('nt', [['key' => 0], ['set' => ['x' => ['now-array']]]]); // scalar -> array

        $this->assertSame([1, 3], array_column($db->find('nt', ['x' => null]), 'key'));
        $this->assertSame([1, 3], $db->getKeysFromFieldIndex('nt', 'x', null));
        $this->assertSame([2], array_column($db->find('nt', ['x' => 'b']), 'key'));
        $this->assertSame([0], array_column($db->find('nt', ['x' => ['now-array']]), 'key'), 'an array value: found by a scan');
        $this->assertNull($db->getKeysFromFieldIndex('nt', 'x', ['now-array']), 'arrays are not indexed');
        $this->assertSame([], $db->find('nt', ['x' => 'a']));
        $db->delete('nt', ['x' => null]);
        $this->assertSame([], $db->find('nt', ['x' => null]));
        $this->assertSame([], $db->getKeysFromFieldIndex('nt', 'x', null));
    }

    /** @test Y-12 O-28 */
    public function shardedIndexesCoverNullsAndNewShards(): void
    {
        $db = $this->db(['shardSize' => 4]);
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['i' => $i, 'v' => $i % 2 ? null : 'x'];
        }
        $db->insert('sn', $rows);
        $db->createFieldIndex('sn', 'v');
        $this->assertCount(5, $db->find('sn', ['v' => null]));
        $this->assertSame([1, 3, 5, 7, 9], array_column($db->find('sn', ['v' => null]), 'key'));
        $this->assertSame([0, 2, 4, 6, 8], array_column($db->find('sn', ['v' => 'x']), 'key'));

        // New shards created after the index
        $more = [];
        for ($i = 10; $i < 20; $i++) {
            $more[] = ['i' => $i, 'v' => 'late'];
        }
        $db->insert('sn', $more);
        $db->update('sn', [['i' => 19], ['set' => ['v' => 'Z']]]);
        $this->assertSame([19], array_column($db->find('sn', ['v' => 'Z']), 'i'));
        $this->assertCount(9, $db->find('sn', ['v' => 'late']));
        $this->assertSame(range(10, 18), array_column($db->find('sn', ['v' => 'late']), 'key'));
        $this->assertSame([1, 3, 5, 7, 9], array_column($db->find('sn', ['v' => null]), 'key'), 'the new shards hold no null');
        $this->assertSame(['v'], $db->getFieldIndexes('sn')['fields']);
        $this->assertTrue($db->getFieldIndexes('sn')['sharded']);

        $this->assertTrue($db->dropFieldIndex('sn', 'v')['success']);
        // the files of every shard and the global one are gone
        $this->assertSame([], glob($this->dbPath('sn', 'gen') . 'indexes/*.v.fx'));
        $this->assertFileDoesNotExist($this->dbPath('sn', 'gfx', 'v'));
        $this->assertFalse($db->dropFieldIndex('sn', 'v')['success']);
    }

    /** @test O-30 */
    public function negativeZeroAndMixedTypesMatchLikeAScan(): void
    {
        $db = $this->db();
        $db->insert('mz', [['v' => -0.0], ['v' => 0], ['v' => '0'], ['v' => 1.0], ['v' => 1], ['v' => '1'], ['v' => true], ['v' => '__null__'], ['v' => null]]);
        $values = [0.0, -0.0, 0, '0', 1, 1.0, '1', true, false, null, '__null__'];
        $scan = [];
        foreach ($values as $v) {
            $scan[] = array_column($db->find('mz', ['v' => $v]), 'key');
        }
        // By hand (=== semantics): -0.0 is 0.0; 0, "0", 1.0, 1, "1", true and "__null__" are each only themselves
        $byHand = [[0], [0], [1], [2], [4], [3], [5], [6], [], [8], [7]];
        $this->assertSame($byHand, $scan, 'the scan agrees with ===');
        $db->createFieldIndex('mz', 'v');
        foreach ($values as $i => $v) {
            $this->assertSame($scan[$i], array_column($db->find('mz', ['v' => $v]), 'key'), 'value ' . var_export($v, true));
            $this->assertSame($byHand[$i], $db->getKeysFromFieldIndex('mz', 'v', $v), 'value ' . var_export($v, true));
        }
    }

    /** @test P-02 property: index never changes results */
    public function randomizedIndexedResultsEqualScan(): void
    {
        mt_srand(42);
        foreach ([[], ['shardSize' => 25]] as $config) {
            $name = empty($config) ? 'rp' : 'rps';
            $db = $this->db($config);
            $rows = [];
            for ($i = 0; $i < 120; $i++) {
                $rows[] = ['c' => mt_rand(0, 5), 's' => ['a', 'b', null][mt_rand(0, 2)], 'n' => $i];
            }
            $db->insert($name, $rows);
            $db->createFieldIndex($name, 'c');
            $db->createFieldIndex($name, 's');
            for ($op = 0; $op < 60; $op++) {
                $r = mt_rand(0, 2);
                if ($r === 0) {
                    $db->insert($name, ['c' => mt_rand(0, 5), 's' => ['a', 'b', null][mt_rand(0, 2)], 'n' => 1000 + $op]);
                } elseif ($r === 1) {
                    $db->update($name, [['c' => mt_rand(0, 5)], ['set' => ['s' => ['a', 'b', null, 'q'][mt_rand(0, 3)]]]]);
                } else {
                    $db->delete($name, ['c' => mt_rand(0, 5), 's' => ['a', 'b', null][mt_rand(0, 2)]]);
                }
            }
            $all = $db->find($name, 0);
            foreach ([0, 1, 2, 3, 4, 5] as $c) {
                foreach (['a', 'b', null, 'q'] as $s) {
                    $expected = array_values(array_filter($all, function ($r) use ($c, $s) {
                        return $r['c'] === $c && array_key_exists('s', $r) && $r['s'] === $s;
                    }));
                    $this->assertSame(array_column($expected, 'key'), array_column($db->find($name, ['c' => $c, 's' => $s]), 'key'), "$name c=$c s=" . var_export($s, true));
                }
            }
        }
    }

    /** @test crash recovery of secondary indexes */
    public function leftoverJournalRebuildsIndexes(): void
    {
        $db = $this->db();
        $db->insert('j', [['city' => 'A']]);
        $db->createFieldIndex('j', 'city');
        $fx = $this->dbPath('j', 'fx', 'city');
        $checkpoint = file_get_contents($fx); // covers record 0 only
        $db->insert('j', [['city' => 'B']]);
        // Simulate a writer that appended data and died: it had bumped the generation and journaled the
        // segment in the lock file's state record (STORAGE_FORMAT.md §4.2: "{gen}:{epoch} j=main"). (Writers
        // never touch the field index; its checkpoint covers the bytes before B, the lines after it are
        // replayed when it is loaded.)
        $state = $this->lockState('j');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string)$state['epoch']);
        $this->assertSame([], preg_grep('/^(j=|J$)/', $state['flags']), 'no journal entry after a completed write');
        $this->assertSame($checkpoint, file_get_contents($fx));
        $this->writeLockState('j', ($state['gen'] + 1) . ':' . $state['epoch'] . ' j=main');

        \noneDB::clearStaticCache();
        $db = $this->db();
        $this->assertSame([1], array_column($db->find('j', ['city' => 'B']), 'key'));
        $this->assertSame([0], array_column($db->find('j', ['city' => 'A']), 'key'));
        $this->assertSame([], preg_grep('/^(j=|J$)/', $this->lockState('j')['flags']), 'journal entry cleared after recovery');
        // "J" (too many segments to list) rebuilds every segment
        file_put_contents($fx, $checkpoint);
        $state = $this->lockState('j');
        $this->writeLockState('j', ($state['gen'] + 1) . ':' . $state['epoch'] . ' J');
        \noneDB::clearStaticCache();
        $this->assertSame([1], array_column($this->db()->find('j', ['city' => 'B']), 'key'));
        $this->assertSame([], preg_grep('/^(j=|J$)/', $this->lockState('j')['flags']));
    }


    /** @test P-02 a batch writes each index file once */
    public function batchInsertWritesIndexOnce(): void
    {
        $db = $this->db();
        $db->insert('bt', ['c' => 0]);
        $db->createFieldIndex('bt', 'c');
        $rows = [];
        for ($i = 0; $i < 3000; $i++) {
            $rows[] = ['c' => $i % 7];
        }
        $fx = $this->dbPath('bt', 'fx', 'c');
        $file = file_get_contents($fx);
        $start = microtime(true);
        $db->insert('bt', $rows);
        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertCount(428, $db->find('bt', ['c' => 6])); // i % 7 === 6 for i < 3000
        $this->assertSame(range(7, 3000, 7), array_column($db->find('bt', ['c' => 6]), 'key'), 'key = i + 1');
        // At most one index write for the whole batch: none while the new data is under 64 KiB, else one
        // checkpoint at the end of the session that covers all of it
        clearstatcache();
        $data = $this->dbPath('bt', 'data');
        $covered = json_decode(strtok($file, "\n"), true)['s'];
        $header = json_decode(strtok(file_get_contents($fx), "\n"), true);
        if (filesize($data) - $covered < 65536) {
            $this->assertSame($file, file_get_contents($fx));
        } else {
            $this->assertSame(filesize($data), $header['s']);
        }
    }
}
