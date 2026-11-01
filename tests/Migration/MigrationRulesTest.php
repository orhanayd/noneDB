<?php

namespace noneDB\Tests\Migration;

/**
 * The rules of docs/MIGRATION.md §3-§6 on files written by hand: the states that releases leave only after a crash or
 * an edit (an index entry of length 0, a glued line, a lost index, an orphaned buffer, …). Every expectation is the
 * rule's own result, worked out from the bytes written here.
 */
class MigrationRulesTest extends MigrationTestCase
{
    private const KEY = 'migration-rules-source-key';

    private function base(string $name, string $key = self::KEY): string
    {
        return hash_pbkdf2('sha256', $name, $key, 1000, 20) . '-' . $name;
    }

    /** Write files of an old database: suffix => content */
    private function old(string $dir, string $name, array $files, string $key = self::KEY): void
    {
        @mkdir($dir, 0777, true);
        foreach ($files as $suffix => $content) {
            file_put_contents($dir . $this->base($name, $key) . $suffix, $content);
        }
    }

    /** JSONL lines and the o map of a 3.0/3.1 index for them: [data, o] */
    private function jsonl(array $records): array
    {
        $data = '';
        $o = [];
        foreach ($records as $record) {
            $line = json_encode($record);
            $o[$record['key']] = [strlen($data), strlen($line)];
            $data .= $line . "\n";
        }
        return [$data, $o];
    }

    private function dryRun(string $dir, array $options = []): array
    {
        [$status, $out, $stdout] = $this->tool(array_merge(["--dir=$dir", '--dry-run'], $options), self::KEY);
        $this->assertIsArray($out, $stdout);
        return [$status, $out['report']];
    }

    private function migrate(string $dir, array $options = []): array
    {
        [$status, $out, $stdout, $stderr] = $this->tool(array_merge(["--dir=$dir", '--backup-dir=' . $this->testDbDir . 'backup/', '--confirm-stopped'], $options), self::KEY);
        $this->assertSame(0, $status, $stdout . $stderr);
        return $out['migrated'];
    }

    /** @test §4.1 J30: an index entry of length 0 is not live; lines the map doesn't point at are not live */
    public function aJ30EntryOfLengthZeroIsNotLive(): void
    {
        $dir = $this->testDbDir . 'r/';
        [$data, $o] = $this->jsonl([['n' => 'a', 'key' => 0], ['n' => 'b', 'key' => 1], ['n' => 'c', 'key' => 2], ['n' => 'gone', 'key' => 3]]);
        $o[1][1] = 0;   // a failed write of 3.0/3.1
        unset($o[3]);   // deleted by 3.0/3.1: no tombstone, only the map lost it
        $this->old($dir, 'users', ['.nonedb' => $data, '.nonedb.jidx' => json_encode(['v' => 3, 'format' => 'jsonl', 'n' => 4, 'd' => 1, 'o' => $o])]);
        [$status, $report] = $this->dryRun($dir);
        $this->assertSame([0, 'READY'], [$status, $report['databases']['users']['status']]);
        $this->assertSame(['main' => 'J30'], $report['databases']['users']['segments']);
        $this->assertSame(2, $report['databases']['users']['records']);
        $this->assertSame(4, $report['databases']['users']['nextKey']);
        $this->assertSame(1, $report['databases']['users']['notCarried']['a key index entry of length 0']);
        $this->migrate($dir);
        $this->assertSame([0 => ['n' => 'a', 'key' => 0], 2 => ['n' => 'c', 'key' => 2]], $this->v4Records($this->v4($dir), 'users'));
    }

    /** @test §6.2: an index entry that points at another key's line stops; --rebuild-from-data replays the data */
    public function anEntryAtTheWrongLineStopsUnlessRebuilt(): void
    {
        $dir = $this->testDbDir . 'r/';
        [$data, $o] = $this->jsonl([['n' => 'a', 'key' => 0], ['n' => 'b', 'key' => 1]]);
        $o[0] = $o[1];
        $this->old($dir, 'users', ['.nonedb' => $data, '.nonedb.jidx' => json_encode(['v' => 3, 'format' => 'jsonl', 'n' => 2, 'd' => 0, 'o' => $o])]);
        [$status, $report] = $this->dryRun($dir);
        $this->assertSame([2, 'AMBIGUOUS_SOURCE'], [$status, $report['result']]);
        $this->assertStringContainsString('points key 0', $report['databases']['users']['reasons'][0]);
        $this->migrate($dir, ['--rebuild-from-data']);
        $this->assertSame([0 => ['n' => 'a', 'key' => 0], 1 => ['n' => 'b', 'key' => 1]], $this->v4Records($this->v4($dir), 'users'));
    }

    /** @test §4.1 J32: s beyond the data file stops; complete lines after s are not live */
    public function aJ32IndexMustFitItsData(): void
    {
        $dir = $this->testDbDir . 'r/';
        [$data, $o] = $this->jsonl([['n' => 'a', 'key' => 0]]);
        $this->old($dir, 'users', ['.nonedb' => $data, '.nonedb.jidx' => json_encode(['v' => 3, 'format' => 'jsonl', 'n' => 1, 'd' => 0, 't' => 0,
            's' => strlen($data) + 10, 'o' => $o]), '.nonedb.lock' => str_pad('3 v', 128)]);
        $this->assertSame('AMBIGUOUS_SOURCE', $this->dryRun($dir)[1]['result']);
        $this->cleanTestDirectory();
        $after = "{\"n\":\"after\",\"key\":1}\n";
        $this->old($dir, 'users', ['.nonedb' => $data . $after, '.nonedb.jidx' => json_encode(['v' => 3, 'format' => 'jsonl', 'n' => 1, 'd' => 0, 't' => 0,
            's' => strlen($data), 'o' => $o]), '.nonedb.lock' => str_pad('3 v', 128)]);
        [, $report] = $this->dryRun($dir);
        $this->assertSame(1, $report['databases']['users']['records']);
        $this->assertSame(2, $report['databases']['users']['nextKey'], 'key 1 was used by the line after s');
        $this->assertSame(1, $report['databases']['users']['notCarried']['complete lines after the key index (3.2 never showed them)']);
        $this->migrate($dir);
        $this->assertSame([0 => ['n' => 'a', 'key' => 0]], $this->v4Records($this->v4($dir), 'users'));
        $this->assertSame(2, $this->v4($dir)->getShardInfo('users')['nextKey']);
    }

    /**
     * @test §4.1 J33 (3.3-dev, data authoritative): a line glued after a torn append is salvaged; a J30 entry that
     * points inside such a line reads its record (M1 scenario 5)
     */
    public function gluedLinesAreSalvaged(): void
    {
        $dir = $this->testDbDir . 'r/';
        $data = "{\"n\":\"a\",\"key\":0}\n{\"n\":\"torn{\"n\":\"b\",\"key\":1}\n{\"__nonedb_deleted\":0}\n";
        $this->old($dir, 'users', ['.nonedb' => $data, '.nonedb.lock' => str_pad('4:0a1b2c3d v', 128)]);
        [, $report] = $this->dryRun($dir);
        $this->assertSame(['main' => 'J33'], $report['databases']['users']['segments']);
        $this->assertSame(1, $report['databases']['users']['records']);
        $this->assertSame(1, $report['databases']['users']['notCarried']['bytes glued before a record in its line (the record is salvaged)']);
        $this->migrate($dir);
        $this->assertSame([1 => ['n' => 'b', 'key' => 1]], $this->v4Records($this->v4($dir), 'users'));
        $this->cleanTestDirectory();
        $at = strpos($data, '{"n":"b"');
        $this->old($dir, 'users', ['.nonedb' => "{\"n\":\"a\",\"key\":0}\n{\"n\":\"torn{\"n\":\"b\",\"key\":1}\n",
            '.nonedb.jidx' => json_encode(['v' => 3, 'format' => 'jsonl', 'n' => 2, 'd' => 0, 'o' => ['0' => [0, 17], '1' => [$at, strlen('{"n":"b","key":1}')]]])]);
        $this->migrate($dir);
        $this->assertSame([0 => ['n' => 'a', 'key' => 0], 1 => ['n' => 'b', 'key' => 1]], $this->v4Records($this->v4($dir), 'users'));
    }

    /**
     * @test §4.1 W: entries that are arrays are records (lists included), others only count for the next key; object
     * shaped data keeps its positions (M1 scenario 3)
     */
    public function wholeJsonEntries(): void
    {
        $dir = $this->testDbDir . 'r/';
        $this->old($dir, 'users', ['.nonedb' => '{"data":[null,null,{"n":"a"},"not-a-record",["x","y"],{}]}']);
        $this->old($dir, 'gaps', ['.nonedb' => '{"data":{"0":{"n":"a"},"5":{"n":"b"}}}']);
        [, $report] = $this->dryRun($dir);
        $this->assertSame(6, $report['databases']['users']['nextKey']);
        $this->assertSame(1, $report['databases']['users']['notCarried']['an entry that is not a record']);
        $this->assertSame(6, $report['databases']['gaps']['nextKey']);
        $this->migrate($dir);
        $db = $this->v4($dir);
        $this->assertSame([2 => ['n' => 'a', 'key' => 2], 4 => [0 => 'x', 1 => 'y', 'key' => 4], 5 => ['key' => 5]], $this->v4Records($db, 'users'));
        $this->assertSame([0 => ['n' => 'a', 'key' => 0], 5 => ['n' => 'b', 'key' => 5]], $this->v4Records($db, 'gaps'));
        $this->assertSame(6, $db->getShardInfo('users')['nextKey']);
    }

    /** @test §4.3: buffers a release never flushed stop the run; buffer lines that are not records are reported */
    public function buffersTheReleaseNeverFlushedStop(): void
    {
        $dir = $this->testDbDir . 'r/';
        $meta = json_encode(['version' => 1, 'shardSize' => 5, 'totalRecords' => 1, 'deletedCount' => 0, 'nextKey' => 1,
            'shards' => [['id' => 0, 'file' => '_s0', 'count' => 1, 'deleted' => 0]]]);
        $base = ['.nonedb.meta' => $meta, '_s0.nonedb' => '{"data":[{"n":"a"}]}'];
        $this->old($dir, 'users', $base + ['_s3.nonedb.buffer' => "{\"n\":\"x\"}\n"]);
        $this->assertStringContainsString('shard 3', $this->dryRun($dir)[1]['databases']['users']['reasons'][0]);
        $this->cleanTestDirectory();
        $this->old($dir, 'users', $base + ['.nonedb.buffer' => "{\"n\":\"x\"}\n"]);
        $this->assertStringContainsString('next to a meta', $this->dryRun($dir)[1]['databases']['users']['reasons'][0]);
        // With a 3.2 lock file the database's one buffer is flushed from the meta's next key
        $this->cleanTestDirectory();
        $this->old($dir, 'users', $base + ['.nonedb.buffer' => "{\"n\":\"x\",\"key\":99}\n\n\"scalar\"\nnot json\n{\"n\":\"y\"}\n", '.nonedb.lock' => str_pad('2 v b', 128)]);
        [, $report] = $this->dryRun($dir);
        $this->assertSame(2, $report['databases']['users']['buffered']);
        $this->assertSame(2, $report['databases']['users']['notCarried']['buffer lines that are not a record']);
        $this->migrate($dir);
        $this->assertSame([0 => ['n' => 'a', 'key' => 0], 1 => ['n' => 'x', 'key' => 1], 2 => ['n' => 'y', 'key' => 2]],
            $this->v4Records($this->v4($dir), 'users'), 'a "key" member of a buffered record is replaced by the assigned key');
    }

    /** @test §4.2: a shard with more entries than the meta's shard size, or a meta with a repeated shard id, stops */
    public function shardsMustFitTheMeta(): void
    {
        $dir = $this->testDbDir . 'r/';
        $meta = function (array $ids) {
            return json_encode(['version' => 1, 'shardSize' => 2, 'totalRecords' => 3, 'deletedCount' => 0, 'nextKey' => 3,
                'shards' => array_map(function ($id) { return ['id' => $id, 'file' => "_s$id", 'count' => 1, 'deleted' => 0]; }, $ids)]);
        };
        $this->old($dir, 'users', ['.nonedb.meta' => $meta([0]), '_s0.nonedb' => '{"data":[{"n":1},{"n":2},{"n":3}]}']);
        $this->assertStringContainsString('more entries', $this->dryRun($dir)[1]['databases']['users']['reasons'][0]);
        $this->cleanTestDirectory();
        $this->old($dir, 'users', ['.nonedb.meta' => $meta([0, 0]), '_s0.nonedb' => '{"data":[{"n":1}]}']);
        $this->assertStringContainsString('distinct', $this->dryRun($dir)[1]['databases']['users']['reasons'][0]);
        $this->cleanTestDirectory();
        $this->old($dir, 'users', ['.nonedb.meta' => '{"version":1,', '_s0.nonedb' => '{"data":[{"n":1}]}']);
        $this->assertStringContainsString('unreadable', $this->dryRun($dir)[1]['databases']['users']['reasons'][0]);
    }

    /** @test §6.1 TARGET_EXISTS: nothing is merged into, or written over, a noneDB 4 database of the same name */
    public function anExistingTargetIsNeverOverwritten(): void
    {
        $dir = $this->testDbDir . 'r/';
        $this->v4($dir, self::TARGET, ['autoCreateDB' => true])->insert('users', ['n' => 'new']);
        $this->old($dir, 'users', ['.nonedb' => '{"data":[{"n":"old"}]}']);
        $before = $this->snapshot($dir);
        [$status, $out] = $this->tool(["--dir=$dir", '--backup-dir=' . $this->testDbDir . 'backup/', '--confirm-stopped'], self::KEY);
        $this->assertSame([2, 'AMBIGUOUS_SOURCE'], [$status, $out['result']]);
        $this->assertStringContainsString('TARGET_EXISTS', $out['message']);
        $this->assertSame($before, $this->snapshot($dir));
    }

    /**
     * @test §2, §3: --shard-size sets the shard size of a database that was not sharded; files of other keys and other
     * entries are reported and untouched; the 3.0/3.1 hash cache leaves only with the last old file of any key
     */
    public function shardSizeOtherKeysAndForeignEntries(): void
    {
        $dir = $this->testDbDir . 'r/';
        $this->old($dir, 'users', ['.nonedb' => '{"data":[{"n":1}]}', '.nonedbinfo' => '1700000000']);
        $this->old($dir, 'theirs', ['.nonedb' => '{"data":[{"n":2}]}'], 'another-key-entirely');
        file_put_contents($dir . 'notes.txt', 'mine');
        file_put_contents($dir . '.nonedb_hash_cache', '{}');
        [, $report] = $this->dryRun($dir, ['--shard-size=7']);
        $this->assertSame(['users'], array_keys($report['databases']));
        $this->assertSame([$this->base('theirs', 'another-key-entirely') . '.nonedb'], $report['otherKeys']);
        $this->assertSame(['notes.txt'], $report['foreign']);
        $migrated = $this->migrate($dir, ['--shard-size=7']);
        $this->assertSame(7, $this->v4($dir)->getShardInfo('users')['shardSize']);
        $this->assertFileExists($dir . $this->base('theirs', 'another-key-entirely') . '.nonedb');
        $this->assertFileExists($dir . 'notes.txt');
        $this->assertFileExists($dir . '.nonedb_hash_cache', 'another key still has old files: the shared hash cache stays');
        $this->assertFileExists($dir . '.nonedb-retained/' . $migrated['run'] . '/files/' . $this->base('users') . '.nonedbinfo');
    }

    /**
     * @test §6: a version no release wrote is not read by its shape. A key index with `o` of another `v` is no usable
     * index (§6.2, --rebuild-from-data replays the data file); a meta of another `version` is not supported (§6.1).
     */
    public function aVersionNoReleaseWroteStops(): void
    {
        $dir = $this->testDbDir . 'r/';
        [$data, $o] = $this->jsonl([['n' => 'a', 'key' => 0], ['n' => 'b', 'key' => 1]]);
        $this->old($dir, 'users', ['.nonedb' => $data, '.nonedb.jidx' => json_encode(['v' => 99, 'format' => 'jsonl', 'n' => 2, 'o' => $o])]);
        [$status, $report] = $this->dryRun($dir);
        $this->assertSame([2, 'AMBIGUOUS_SOURCE'], [$status, $report['result']]);
        $this->assertSame('READY', $this->dryRun($dir, ['--rebuild-from-data'])[1]['result']);

        $this->cleanTestDirectory();
        $this->old($dir, 'users', ['.nonedb.meta' => json_encode(['version' => 99, 'shardSize' => 10, 'shards' => [], 'nextKey' => 0])]);
        [$status, $report] = $this->dryRun($dir);
        $this->assertSame([2, 'UNSUPPORTED_SOURCE'], [$status, $report['result']]);
    }

    /** @test A name ending in a line feed can't be a noneDB 4 name: §6.1, the `$` of a pattern matches before it */
    public function aNameEndingInALineFeedIsNotSupported(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('no line feed in a file name');
        }
        $dir = $this->testDbDir . 'r/';
        [$data, $o] = $this->jsonl([['n' => 'a', 'key' => 0]]);
        $this->old($dir, "users\n", ['.nonedb' => $data, '.nonedb.jidx' => json_encode(['v' => 3, 'format' => 'jsonl', 'n' => 1, 'o' => $o])]);
        [$status, $report] = $this->dryRun($dir);
        $this->assertSame([2, 'UNSUPPORTED_SOURCE'], [$status, $report['result']]);
    }
}
