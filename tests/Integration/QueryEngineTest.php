<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Query engine: plans (index candidates, shard skipping), batched scans that stop early,
 * top-k sorting, explain(), joins through the foreign index with raw values, a consistent snapshot of
 * every database a join reads. Every result equals a scan without indexes.
 */
class QueryEngineTest extends RegressionTestCase
{
    private function filled(array $config, int $n = 2000): \noneDB
    {
        $db = $this->db($config);
        $db->createFieldIndex('qe', 'a');
        $db->createFieldIndex('qe', 'b');
        $db->insert('qe', array_map(function ($i) {
            return ['a' => $i % 10, 'b' => 'b' . ($i % 7), 'c' => $i % 3 === 0 ? null : ($i % 2 ? 'x' : 2.5), 'n' => $i];
        }, range(0, $n - 1)));
        return $db;
    }

    /** @test */
    public function firstExistsAndLimitReadOnlyWhatTheyNeed(): void
    {
        foreach ([['shardingEnabled' => false], ['shardSize' => 500]] as $config) {
            $this->cleanTestDirectory();
            $db = $this->filled($config);
            $q = function () use ($db) { return $db->query('qe'); };
            foreach ([$q()->where(['c' => 'x'])->limit(1), $q()->where(['n' => ['$gte' => 0]])->limit(5)] as $query) {
                $read = $query->explain(true)['actual']['recordsRead'];
                $this->assertIsInt($read);
                $this->assertGreaterThan(0, $read);
                $this->assertLessThanOrEqual(32, $read, 'first batch only');
                // Both are scans (c and n have no index) whose matches (n = 1; n = 0..4) are in the first
                // batch of the first segment: exactly 32 records (QUERY.md: batches of 32, then 128, ...)
                $this->assertSame(32, $read);
            }
            $this->assertSame(['a' => 1, 'b' => 'b1', 'c' => 'x', 'n' => 1, 'key' => 1], $q()->where(['c' => 'x'])->first());
            $this->assertTrue($q()->where(['n' => 1999])->exists());
            $this->assertFalse($q()->where(['n' => 5000])->exists());
            // Indexed: only the candidates are read
            $explain = $q()->where(['a' => 3, 'b' => 'b3'])->explain(true);
            $this->assertSame('index', $explain['access']);
            $this->assertSame(count(array_filter(range(0, 1999), function ($i) { return $i % 10 === 3 && $i % 7 === 3; })), $explain['actual']['recordsRead']);
            $this->assertSame($explain['actual']['returned'], $explain['actual']['recordsRead']);
            // noneDB methods stop early too, and agree with find()
            $this->assertSame($db->find('qe', ['c' => 'x'])[0], $db->first('qe', ['c' => 'x']));
            $this->assertSame(count($db->find('qe', ['b' => 'b2', 'c' => 2.5])), $db->count('qe', ['b' => 'b2', 'c' => 2.5]));
            $this->assertTrue($db->exists('qe', ['a' => 9, 'n' => 1999]));
        }
    }

    /** @test */
    public function batchedScansNeverReadARecordTwice(): void
    {
        // Batches grow (32, 128, ...): a later batch that covers the rest of a segment must not read it all
        foreach ([['shardingEnabled' => false], ['shardSize' => 150]] as $config) {
            foreach ([33, 100, 160, 700] as $size) {
                $this->cleanTestDirectory();
                $db = $this->db($config);
                $db->insert('bt', array_map(function ($i) { return ['n' => $i]; }, range(0, $size - 1)));
                foreach ([40, $size - 1, $size + 5] as $limit) {
                    $keys = array_column($db->query('bt')->where(['n' => ['$gte' => 0]])->limit($limit)->get(), 'key');
                    $this->assertSame(range(0, min($limit, $size) - 1), $keys, json_encode([$config, $size, $limit]));
                }
                $this->assertSame($size, $db->query('bt')->where(['n' => ['$gte' => 0]])->count());
            }
        }
    }

    /** @test */
    public function readsDontListTheDirectory(): void
    {
        // Reads of an existing database find its files without listing a directory (a listing per read is
        // O(files in dbDir)); only the missing-database path lists dbDir (docs/STORAGE_FORMAT.md §3.4, §12)
        $db = $this->db(['shardSize' => 5, 'bufferEnabled' => true]);
        $db->insert('dl', array_map(function ($i) { return ['n' => $i]; }, range(0, 29)));
        $db->insert('dl', ['n' => 'buffered']);
        $db->find('dl', ['n' => 1]); // flushes
        $listings = function () {
            $property = new \noneDB\Tests\InternalValue('directoryListings');
            return $property->getValue();
        };
        $before = $listings();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame([3], array_column($db->find('dl', ['n' => 3]), 'key'));
            $this->assertSame(31, $db->count('dl'));
            $this->assertSame(1, $db->query('dl')->where(['n' => ['$gt' => 28]])->count());
        }
        $this->assertSame($before, $listings(), 'reads of an existing database list nothing');
        // A missing database: "it lists dbDir directly, every time" (§3.4, foreign entries), once per read
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame([], $db->find('missing', ['n' => 1]));
            $this->assertSame(0, $db->count('missing'));
        }
        $this->assertSame($before + 10, $listings(), 'one listing per read of a missing database');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame([3], array_column($db->find('dl', ['n' => 3]), 'key'));
            $this->assertSame(31, $db->count('dl'));
        }
        $this->assertSame($before + 10, $listings(), 'still none for the existing database');
    }

    /** @test */
    public function topKEqualsTheFullSort(): void
    {
        mt_srand(12);
        $db = $this->db(['shardingEnabled' => false]);
        $pool = [null, true, false, 0, 1, -1, 2.5, '2', '10', '9', '1a', 'abc', '', [1], 'Z'];
        $db->insert('tk', array_map(function ($i) use ($pool) {
            return ['s' => $pool[mt_rand(0, count($pool) - 1)], 't' => $pool[mt_rand(0, count($pool) - 1)], 'g' => $i % 4];
        }, range(0, 2999)));
        for ($round = 0; $round < 40; $round++) {
            $field = mt_rand(0, 1) ? 's' : 't';
            $order = mt_rand(0, 1) ? 'asc' : 'desc';
            $offset = mt_rand(0, 20);
            $limit = mt_rand(1, 60);
            $all = $db->query('tk')->where(['g' => ['$lte' => 2]])->sort($field, $order)->sort('g', 'desc')->get();
            $top = $db->query('tk')->where(['g' => ['$lte' => 2]])->sort($field, $order)->sort('g', 'desc')->offset($offset)->limit($limit);
            $this->assertSame('top-k', $top->explain()['sort']);
            $this->assertSame(array_slice($all, $offset, $limit), $top->get(), "$field $order $offset $limit");
        }
    }

    /** @test */
    public function plansEqualAFullScan(): void
    {
        mt_srand(2026);
        $values = [0, 1, 2, 3, '1', 1.0, 2.5, null, true, 'x', 'y', 'b3'];
        foreach ([['shardingEnabled' => false], ['shardSize' => 40]] as $config) {
            $this->cleanTestDirectory();
            $db = $this->db($config);
            $db->createFieldIndex('pl', 'a');
            $db->createFieldIndex('pl', 'b');
            $rows = [];
            for ($i = 0; $i < 300; $i++) {
                $row = ['a' => $values[mt_rand(0, count($values) - 1)], 'b' => $values[mt_rand(0, count($values) - 1)], 'n' => $i];
                if (mt_rand(0, 9) === 0) {
                    unset($row['a']);
                }
                $rows[] = $row;
            }
            $db->insert('pl', $rows);
            $db->delete('pl', ['b' => 'y']);
            $plain = $this->db($config + ['fieldIndexEnabled' => false]);
            $leaf = function () use ($values) {
                $field = ['a', 'b', 'n', 'key'][mt_rand(0, 3)];
                $v = $values[mt_rand(0, count($values) - 1)];
                switch (mt_rand(0, 5)) {
                    case 0: return [$field => $field === 'key' ? mt_rand(0, 320) : $v];
                    case 1: return [$field => ['$in' => [$v, $values[mt_rand(0, count($values) - 1)]]]];
                    case 2: return [$field => ['$gte' => $v]];
                    case 3: return [$field => ['$lt' => $v, '$gt' => $values[mt_rand(0, count($values) - 1)]]];
                    case 4: return [$field => ['$ne' => $v]];
                    default: return [$field => ['$eq' => $v]];
                }
            };
            for ($round = 0; $round < 120; $round++) {
                $build = function (\noneDB $on) use ($leaf, $round) {
                    mt_srand(9000 + $round);
                    $q = $on->query('pl');
                    for ($w = mt_rand(0, 2); $w > 0; $w--) {
                        $q->where($leaf() + (mt_rand(0, 2) === 0 ? $leaf() : []));
                    }
                    for ($o = mt_rand(0, 2) === 0 ? mt_rand(1, 2) : 0; $o > 0; $o--) {
                        $q->orWhere(mt_rand(0, 9) === 0 ? [] : $leaf());
                    }
                    if (mt_rand(0, 3) === 0) {
                        $q->whereIn(mt_rand(0, 1) ? 'a' : 'b', [1, 'x', 2.5]);
                    }
                    if (mt_rand(0, 3) === 0) {
                        $q->between('a', 0, mt_rand(1, 3));
                    }
                    if (mt_rand(0, 4) === 0) {
                        $q->whereNot(['b' => 'x']);
                    }
                    if (mt_rand(0, 3) === 0) {
                        $q->sort('n', mt_rand(0, 1) ? 'asc' : 'desc')->limit(mt_rand(1, 20));
                    }
                    return $q;
                };
                $expected = $build($plain)->get();
                $this->assertSame($expected, $build($db)->get(), json_encode([$config, $round]));
                $this->assertSame(count($expected), $build($db)->count());
                $this->assertSame($expected[0] ?? null, $build($db)->first());
                $this->assertSame(!empty($expected), $build($db)->exists());
                $explain = $build($db)->explain(true);
                $this->assertSame(count($expected), $explain['actual']['returned']);
            }
        }
    }

    /** @test */
    public function explainBuildsNothingAndShowsShardSkips(): void
    {
        $db = $this->db(['shardSize' => 500]);
        $db->createFieldIndex('ex', 'u');
        $db->insert('ex', array_map(function ($i) { return ['u' => 'u' . $i, 'v' => $i % 5]; }, range(0, 1999)));
        $explain = $db->query('ex')->where(['u' => 'u1234'])->explain();
        $this->assertSame(['total' => 4, 'skipped' => 3, 'indexed' => 1, 'scanned' => 0], $explain['segments']);
        $this->assertSame([['segment' => 0, 'access' => 'skip', 'candidates' => 0], ['segment' => 1, 'access' => 'skip', 'candidates' => 0],
            ['segment' => 2, 'access' => 'index', 'candidates' => 1], ['segment' => 3, 'access' => 'skip', 'candidates' => 0]], $explain['plan'], 'key 1234 is in shard 2');
        $this->assertSame([true, false, ['eq', 'u', 'u1234']], [$explain['sharded'], $explain['planTruncated'], $explain['filter']]);
        $this->assertSame('index', $explain['access']);
        $this->assertSame(1, $explain['estimatedRead']);
        $this->assertTrue($explain['residual']);
        $this->assertSame('scan', $db->query('ex')->where(['v' => 3])->explain()['access']);
        $this->assertSame(2000, $db->query('ex')->where(['v' => 3])->explain()['estimatedRead'], 'every record of the 4 shards');
        $this->assertSame('none', $db->query('ex')->where(['u' => 'nothing'])->explain()['access']);
        $this->assertSame(['total' => 4, 'skipped' => 4, 'indexed' => 0, 'scanned' => 0], $db->query('ex')->where(['u' => 'nothing'])->explain()['segments']);
        // An index a query would build first: explain() counts it as a scan and builds nothing
        $db->createFieldIndex('ex', 'v');
        foreach (glob($this->dbPath('ex', 'gen') . 'indexes/s*.v.fx') as $file) {
            unlink($file);
        }
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 500]);
        $this->assertSame('scan', $db->query('ex')->where(['v' => 3])->explain()['access']);
        $this->assertSame([], glob($this->dbPath('ex', 'gen') . 'indexes/s*.v.fx'));
        $this->assertCount(400, $db->query('ex')->where(['v' => 3])->get());
        $this->assertSame(range(3, 1998, 5), array_column($db->query('ex')->where(['v' => 3])->get(), 'key'));
        // The query built the shard indexes: now explain() shows them as indexed
        $this->assertCount(4, glob($this->dbPath('ex', 'gen') . 'indexes/s*.v.fx'));
        $this->assertSame(['index', 400], [$db->query('ex')->where(['v' => 3])->explain()['access'], $db->query('ex')->where(['v' => 3])->explain()['estimatedRead']]);
    }

    /** @test */
    public function joinsMatchByIdentityThroughTheForeignIndex(): void
    {
        foreach ([true, false] as $indexed) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardingEnabled' => false]);
            if ($indexed) {
                $db->createFieldIndex('owners', 'ref');
            }
            $db->insert('owners', [
                ['ref' => 'Ada', 'o' => 'string'], ['ref' => true, 'o' => 'bool'], ['ref' => 1, 'o' => 'int1'],
                ['ref' => 1.5, 'o' => 'float'], ['ref' => 5, 'o' => 'five'], ['ref' => '007', 'o' => 'zeros'],
                ['ref' => 7, 'o' => 'seven'], ['ref' => '5', 'o' => 'five again'],
            ]);
            $db->insert('items', [
                ['ref' => 'Ada'], ['ref' => true], ['ref' => '1'], ['ref' => 1.5], ['ref' => 5.0], ['ref' => '5'],
                ['ref' => '007'], ['ref' => 7], ['ref' => '1.5'], ['ref' => false], ['ref' => null],
            ]);
            $joined = $db->query('items')->join('owners', 'ref', 'ref', 'owner')->get();
            $names = array_map(function ($r) { return $r['owner']['o'] ?? null; }, $joined);
            $this->assertSame(['string', 'bool', 'int1', 'float', 'five again', 'five again', 'zeros', 'seven', null, null, null], $names,
                'indexed ' . var_export($indexed, true) . ': "5", 5.0 and 5 are one key (the last record wins); true only true; "007" not 7');
            $this->assertSame('in-lookup', $db->query('items')->join('owners', 'ref', 'ref', 'owner')->explain()['joins'][0]['strategy']);
            $this->assertSame([['db' => 'owners', 'on' => 'ref = ref', 'strategy' => 'in-lookup', 'when' => 'after-slice']],
                $db->query('items')->join('owners', 'ref', 'ref', 'owner')->explain()['joins']);
        }
    }

    /** @test */
    public function aJoinReadsBothDatabasesInOneState(): void
    {
        $db = $this->db(['shardingEnabled' => false, 'lockTimeout' => 0.5]);
        $db->insert('users', [['name' => 'a'], ['name' => 'b']]);
        $db->insert('orders', [['user' => 0], ['user' => 1]]);
        $blocked = null;
        // While the join runs, a writer of the foreign database waits until the join is done
        $this->setFaultHook(function ($point) use (&$blocked) {
            static $done = false;
            if ($point === 'snapshot:held' && !$done) {
                $done = true;
                $blocked = $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false, 'lockTimeout' => 0.3]) . ';
                    try { $db->insert("users", ["name" => "late"]); echo "wrote"; } catch (RuntimeException $e) { echo "waited"; }');
            }
        });
        $rows = $db->query('orders')->join('users', 'user', 'key', 'u')->get();
        $this->setFaultHook(null);
        $this->assertSame('waited', $blocked);
        $this->assertSame(['a', 'b'], array_map(function ($r) { return $r['u']['name']; }, $rows));
    }
}
