<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * Key index = checkpoint + replay of the data file (S1, STORAGE_FORMAT.md §6.4): writes append to the data
 * only, the {seg}.kix file is a checkpoint written when the tail after it gets long; loading replays the
 * tail; a checkpoint counts only for the data file it was made from.
 */
class DeltaLogTest extends RegressionTestCase
{
    private function stats(): array
    {
        $property = new \noneDB\Tests\InternalValue('indexStats');
        return $property->getValue();
    }

    private function ckpt(string $name): string
    {
        return $this->dbPath($name, 'kix');
    }

    private function data(string $name): string
    {
        return $this->dbPath($name, 'data');
    }

    /**
     * The in-memory index of a database (non-sharded): every live key with its offset and length, and the
     * counters (n next key, d garbage, t tombstones, s bytes covered). Not the members that differ between two
     * loads of the same data (times, segment epoch, how much of it a checkpoint holds)
     */
    private function index(\noneDB $db, string $name): array
    {
        $index = (function () use ($name) {
            return $this->readJsonlIndex($name, null);
        })->call(\noneDB\Tests\Support\Testing::engine($db));
        // key => [offset, length], ascending, from the index's offsets and lengths of every live key
        [$offsets, $lengths] = \noneDB\Tests\Support\Testing::callStatic('KeyIndex::locate', $index, null);
        ksort($offsets);
        $entries = [];
        foreach ($offsets as $key => $offset) {
            $entries[$key] = [$offset, $lengths[$key]];
        }
        return ['entries' => $entries,
            'n' => $index['n'], 'd' => $index['d'], 't' => $index['t'], 's' => $index['s']];
    }

    /** @test */
    public function writesDoNotRewriteTheIndexUntilTheTailIsLong(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('dl', ['n' => 0]);
        clearstatcache();
        $checkpoint = file_get_contents($this->ckpt('dl'));
        $before = $this->stats()['checkpoints'];
        for ($i = 1; $i <= 40; $i++) {
            $db->insert('dl', ['n' => $i]);
        }
        $db->update('dl', [['n' => 3], ['set' => ['u' => 1]]]);
        $db->delete('dl', ['n' => 4]);
        $this->assertSame($checkpoint, file_get_contents($this->ckpt('dl')), 'single writes only append');
        $this->assertSame($before, $this->stats()['checkpoints']);

        // The checkpoint of the new database is at s = 0, before record 0. After it, 40 inserts, the update and
        // the delete the tail has 43 lines and 40 records; insert k more: 43 + k >= 64 + intdiv(40 + k, 16)
        // first holds for k = 25 (insert 65). The next needs 72 more lines (insert 137): one checkpoint here.
        $sizes = [];
        for ($i = 41; $i < 120; $i++) {
            $db->insert('dl', ['n' => $i]);
            clearstatcache();
            $sizes[$i] = filesize($this->data('dl'));
        }
        $this->assertSame($before + 1, $this->stats()['checkpoints'], 'a long tail is checkpointed, once');
        $file = $this->readKeyIndexFile($this->ckpt('dl'))['header'];
        $this->assertSame(['kind' => 'kix', 'v' => 1], ['kind' => $file['kind'], 'v' => $file['v']], 'STORAGE_FORMAT.md §6.4');
        $members = array_keys($file);
        sort($members);
        $this->assertSame(['base', 'created', 'd', 'e', 'h', 'kind', 'layout', 'live', 'n', 's', 'slots', 't', 'updated', 'v'], $members, '§6.4, single layout: no z, sid');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $file['e']);
        $this->assertSame($sizes[65], $file['s'], 'written by the session of insert 65');
        $this->assertSame(66, $file['n']);
        $this->assertCount(119, $db->find('dl', 0));
    }

    /** @test */
    public function checkpointPlusTailEqualsAFullReplay(): void
    {
        mt_srand(4242);
        $db = $this->db(['shardingEnabled' => false]);
        for ($round = 0; $round < 6; $round++) {
            for ($i = 0; $i < 60; $i++) {
                $op = mt_rand(0, 9);
                if ($op < 5) {
                    $db->insert('fr', ['v' => mt_rand(0, 20)]);
                } elseif ($op < 8) {
                    $db->update('fr', [['v' => mt_rand(0, 20)], ['set' => ['w' => $i]]]);
                } else {
                    $db->delete('fr', ['v' => mt_rand(0, 20)]);
                }
            }
            \noneDB::clearStaticCache();
            $warm = $this->index($this->db(['shardingEnabled' => false]), 'fr');
            $records = $this->db(['shardingEnabled' => false])->find('fr', 0);
            copy($this->ckpt('fr'), $this->ckpt('fr') . '.keep');
            unlink($this->ckpt('fr'));
            \noneDB::clearStaticCache();
            $cold = $this->db(['shardingEnabled' => false]);
            $this->assertSame($warm, $this->index($cold, 'fr'), "round $round: offsets, counters, next key");
            $this->assertSame($records, $cold->find('fr', 0));
            rename($this->ckpt('fr') . '.keep', $this->ckpt('fr'));
            \noneDB::clearStaticCache();
            $db = $this->db(['shardingEnabled' => false]);
        }
    }

    /** @test */
    public function anotherProcessesAppendsAreReplayedNotReloaded(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('xp', [['n' => 1], ['n' => 2]]);
        $this->assertCount(2, $db->find('xp', 0));
        $before = $this->stats();
        $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false]) . ';
            for ($i = 0; $i < 5; $i++) { $db->insert("xp", ["n" => 10 + $i]); }
            $db->update("xp", [["n" => 1], ["set" => ["u" => true]]]);');
        $all = $db->find('xp', 0);
        $this->assertSame([1, 2, 10, 11, 12, 13, 14], array_column($all, 'n'));
        $this->assertTrue($all[0]['u']);
        $after = $this->stats();
        $this->assertSame($before['loads'], $after['loads'], 'the cached index is kept');
        $this->assertSame(6, $after['replayedLines'] - $before['replayedLines'], 'only the new lines are read');

        // A data file replaced by another process (new epoch): everything is loaded again
        $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false]) . '; $db->compact("xp");');
        $this->assertCount(7, $db->find('xp', 0));
        $this->assertSame($after['loads'] + 1, $this->stats()['loads']);
    }

    /** @test */
    public function aCheckpointOfAnotherDataFileIsNeverUsed(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('st', array_map(function ($n) { return ['n' => $n, 'pad' => str_repeat('x', 50)]; }, range(0, 199)));
        $db->update('st', [['n' => 5], ['set' => ['pad' => 'short']]]);
        $old = file_get_contents($this->ckpt('st'));
        $db->compact('st');
        // A stale checkpoint next to the compacted file (s beyond the end): rejected
        file_put_contents($this->ckpt('st'), $old);
        \noneDB::clearStaticCache();
        $this->assertSame(range(0, 199), array_column($this->db(['shardingEnabled' => false])->find('st', 0), 'n'));

        // Same size, different bytes before s (h): rejected too. The last record the checkpoint covers
        // becomes a tombstone of the same length: a stale index would still count it.
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('st', ['n' => 500]);
        \noneDB::clearStaticCache();
        $checkpoint = $this->readKeyIndexFile($this->ckpt('st'))['header'];
        $content = file_get_contents($this->data('st'));
        $s = $checkpoint['s'];
        $start = strrpos(substr($content, 0, $s - 1), "\n") + 1;
        $record = json_decode(substr($content, $start, $s - 1 - $start), true);
        $tombstone = '{"__nonedb_deleted":' . $record['key'];
        $tombstone .= str_repeat(' ', $s - 1 - $start - strlen($tombstone) - 1) . '}';
        $swapped = substr($content, 0, $start) . $tombstone . substr($content, $s - 1);
        $this->assertSame(strlen($content), strlen($swapped));
        file_put_contents($this->data('st'), $swapped);
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame(200, $db->count('st'), 'the data, not the stale checkpoint');
        $this->assertSame([], $db->find('st', ['key' => $record['key']]));
        $this->assertTrue($db->checkIntegrity('st')['ok']);
    }

    /** @test */
    public function aReaderCheckpointsALongTailWithoutMovingTheGeneration(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('rc', ['n' => 0]);
        $checkpoint = file_get_contents($this->ckpt('rc'));
        // A writer that dies before its session ends appends 150 records and writes no checkpoint
        $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false]) . ';
            noneDB\Tests\Support\Testing::setFaultHook(function ($point) { if ($point === "scope:commit") { exit(0); } });
            $db->insert("rc", array_map(function ($n) { return ["n" => $n]; }, range(1, 150)));');
        $this->assertSame($checkpoint, file_get_contents($this->ckpt('rc')));
        $gen = $this->lockState('rc')['gen'];
        \noneDB::clearStaticCache();
        $this->assertCount(151, $this->db(['shardingEnabled' => false])->find('rc', 0));
        $file = $this->readKeyIndexFile($this->ckpt('rc'))['header'];
        $this->assertSame(filesize($this->data('rc')), $file['s'], 'the reader wrote the checkpoint');
        $this->assertSame($gen, $this->lockState('rc')['gen'], 'no generation bump for a checkpoint');
    }

    /** @test */
    public function compactionCrashesLeaveAConsistentDatabase(): void
    {
        foreach (['compact:replace', 'compact:replaced', 'checkpoint:written'] as $point) {
            $this->cleanTestDirectory();
            $db = $this->db(['shardingEnabled' => false]);
            $db->insert('cc', array_map(function ($n) { return ['n' => $n]; }, range(0, 49)));
            $db->query('cc')->where(['n' => ['$lt' => 30]])->delete();
            $this->assertSame(20, $db->count('cc'));
            $out = $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false]) . ';
                noneDB\Tests\Support\Testing::setFaultHook(function ($p) { if ($p === ' . var_export($point, true) . ') { exit(0); } });
                $db->compact("cc"); echo "not reached";');
            $this->assertSame('', $out, $point);
            \noneDB::clearStaticCache();
            $db = $this->db(['shardingEnabled' => false]);
            $this->assertTrue($db->checkIntegrity('cc')['ok'], "$point: consistent");
            $this->assertSame(range(30, 49), array_column($db->find('cc', 0), 'n'), $point);
            $db->insert('cc', ['n' => 'next']);
            $this->assertSame(50, $db->find('cc', ['n' => 'next'])[0]['key'], "$point: no key reused");
        }
    }

    /** @test */
    public function aRolledBackWriteLeavesTheCheckpoint(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('rb', ['n' => 0]);
        $checkpoint = file_get_contents($this->ckpt('rb'));
        $this->setFaultHook(function ($point) {
            if ($point === 'index:written') {
                throw new \RuntimeException('injected');
            }
        });
        try {
            $db->insert('rb', array_map(function ($n) { return ['n' => $n]; }, range(1, 300)));
            $this->fail('injected');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected', $e->getMessage());
        }
        $this->setFaultHook(null);
        $this->assertSame($checkpoint, file_get_contents($this->ckpt('rb')), 'nothing past the rollback is checkpointed');
        $this->assertSame([0], array_column($db->find('rb', 0), 'n'));
        \noneDB::clearStaticCache();
        $this->assertSame([0], array_column($this->db(['shardingEnabled' => false])->find('rb', 0), 'n'));
    }

    /** @test */
    public function journaledSecondaryIndexesFollowAWriterThatDied(): void
    {
        // A writer that dies right after its append, before any index is written: the field index follows the
        // data by itself (it replays the lines after the bytes it covers, STORAGE_FORMAT.md §6.5)
        $db = $this->db(['shardingEnabled' => false]);
        $db->createFieldIndex('jr', 'c');
        $db->insert('jr', ['c' => 'x']);
        $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false]) . ';
            noneDB\Tests\Support\Testing::setFaultHook(function ($p) { if ($p === "append:after") { exit(0); } });
            $db->insert("jr", ["c" => "x"]);');
        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $this->assertSame([0, 1], array_column($db->find('jr', ['c' => 'x']), 'key'));
        $this->assertTrue($db->checkIntegrity('jr')['ok']);
    }
}
