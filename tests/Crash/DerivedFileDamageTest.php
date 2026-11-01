<?php

namespace noneDB\Tests\Crash;

use noneDB\Tests\RegressionTestCase;

/**
 * Damaged derived files (STORAGE_FORMAT.md §6.5): key-index checkpoints and indexes can always be made again from the
 * data, so damage in one never changes an answer and never reaches the caller as an error of PHP. A number in a file
 * never decides how much is read.
 */
class DerivedFileDamageTest extends RegressionTestCase
{
    /** A sharded database with every kind of derived file, written by a process that has ended */
    private function store(array $config = []): void
    {
        $db = $this->db(['shardSize' => 500] + $config);
        $rows = [];
        for ($i = 0; $i < 1500; $i++) {
            $rows[] = ['g' => 'g' . ($i % 37), 'u' => 'value-' . $i . str_repeat('-', 30),
                'p' => ['type' => 'Point', 'coordinates' => [20 + ($i % 90) / 10, 30 + ($i % 70) / 10]]];
        }
        $db->insert('dm', $rows);
        $db->createFieldIndex('dm', 'g');
        $db->createFieldIndex('dm', 'u');
        $db->createSpatialIndex('dm', 'p');
        $db->find('dm', ['g' => 'g5']);
        \noneDB::clearStaticCache();
    }

    /** The derived files of the database */
    private function derived(): array
    {
        return array_values(array_filter(array_map(function ($file) {
            return $this->dbPath('dm', 'home') . $file;
        }, $this->dbFiles('dm')), function ($file) {
            return preg_match('/\.(kix|fx|gfx|rx|grx)$/', $file) === 1;
        }));
    }

    /** Everything asked of the database, as a new process asks it */
    private function answers(): string
    {
        return $this->runChild('$db = ' . $this->childDb(['shardSize' => 500]) . '; $out = [];'
            . ' $out[] = $db->checkIntegrity("dm")["records"];' // before a read that replaces a damaged file
            . ' $sorted = function ($r) { $k = array_column($r, "key"); sort($k); return $k; };'
            . ' $out[] = $db->find("dm", ["key" => 777])[0]["u"];'
            . ' $out[] = $sorted($db->find("dm", ["g" => "g5"]));'
            . ' $out[] = $sorted($db->find("dm", ["u" => "value-777" . str_repeat("-", 30)]));'
            . ' $out[] = $sorted($db->query("dm")->where(["u" => ["\$gte" => "value-90", "\$lte" => "value-91"]])->get());'
            . ' $out[] = $sorted($db->query("dm")->withinDistance("p", 21.0, 31.0, 30000)->get());'
            . ' $out[] = $db->count("dm");'
            . ' echo json_encode($out);');
    }

    /** @test §6.5: one digit of any header changed, its crc doesn't fit: the file is invalid, the answers and the next key stay */
    public function aHeaderThatDoesNotCheckMakesItsFileInvalid(): void
    {
        $this->store();
        $expected = $this->answers();
        $this->assertStringStartsWith('[1500,"value-777', $expected);
        $files = $this->derived();
        $kinds = array_unique(array_map(function ($file) { return pathinfo($file, PATHINFO_EXTENSION); }, $files));
        sort($kinds);
        $this->assertSame(['fx', 'gfx', 'grx', 'kix', 'rx'], $kinds, 'precondition: every kind');
        foreach ($files as $file) {
            $intact = (string)file_get_contents($file);
            $end = strpos($intact, "\n");
            preg_match_all('/":(\d+)[,}]/', substr($intact, 0, $end), $numbers, PREG_OFFSET_CAPTURE);
            foreach ($numbers[1] as $n => [$digits, $at]) {
                if ($n % 3 === 0) {
                    $changed = $intact;
                    $changed[$at] = $digits[0] === '1' ? '2' : '1';
                    file_put_contents($file, $changed);
                    \noneDB::clearStaticCache();
                    $this->assertSame($expected, $this->answers(), basename($file) . ' at ' . $at);
                }
            }
            file_put_contents($file, $intact);
        }
        \noneDB::clearStaticCache();
        $db = $this->db(['shardSize' => 500]);
        $db->insert('dm', ['g' => 'new']);
        $this->assertSame([1500], array_column($db->find('dm', ['g' => 'new']), 'key'), 'the next key');
    }

    /** @test A record the data file doesn't hold is not asked of it: an index that says 4 GB is repaired, the process stays */
    public function aRecordLongerThanItsFileIsNotRead(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('dm', array_map(function ($i) { return ['n' => $i]; }, range(0, 199)));
        $kix = $this->dbPath('dm', 'kix');
        $checkpoint = $this->readKeyIndexFile($kix);
        \noneDB::clearStaticCache();
        $entries = $checkpoint['entries'];
        $entries[120][1] = 4000000000;
        $this->writeKeyIndexFile($kix, $checkpoint['header'], $entries);
        $out = $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false]) . '; echo count($db->find("dm", [])), " ", $db->find("dm", ["key" => 120])[0]["n"];');
        $this->assertSame('200 120', $out);
        $this->writeKeyIndexFile($kix, $checkpoint['header'], $entries);
        \noneDB::clearStaticCache();
        $out = $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false]) . '; echo json_encode(array_column($db->find("dm", ["key" => [118, 120]]), "n"));');
        $this->assertSame('[118,120]', $out, 'many keys');
    }

    /**
     * @test §6.5.1 An .fx whose header or directory points outside the file: the file is invalid, however the crcs fit
     * @runInSeparateProcess
     */
    public function aPageOutsideTheFileIsNotRead(): void
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('dm', array_map(function ($i) { return ['u' => sprintf('value-%06d', $i)]; }, range(0, 29999)));
        $this->assertTrue($db->createFieldIndex('dm', 'u')['success']);
        $path = $this->dbPath('dm', 'fx', 'u');
        $intact = (string)file_get_contents($path);
        $line = strpos($intact, "\n");
        $header = $this->openHeaderLine($path, substr($intact, 0, $line));
        $body = substr($intact, $line + 1);
        [$offset, $length] = $header['pg']['root'];
        $sealed = function (array $changed) use ($path, $body): string {
            return $this->sealHeaderLine(json_encode($changed)) . "\n" . $body;
        };
        $reseal = function (string $page): string { return $page . pack('V', crc32($page)); };
        $root = substr($body, $offset, $length - 4);
        $n = unpack('V', $root)[1];
        $cases = [
            'a root past the file' => $sealed(['pg' => ['root' => [$offset, 3000000000]] + $header['pg']] + $header),
            'a root of no bytes' => $sealed(['pg' => ['root' => [$offset + $length, 0]] + $header['pg']] + $header),
            'a root that is not a pair' => $sealed(['pg' => ['root' => [$offset]] + $header['pg']] + $header),
            'more levels than a tree can have' => $sealed(['pg' => ['levels' => 64] + $header['pg']] + $header),
            'levels below none' => $sealed(['pg' => ['levels' => -1] + $header['pg']] + $header),
            'a header whose h is no string' => $sealed(['h' => []] + $header),
            'a header that covers less than nothing' => $sealed(['s' => -1] + $header),
            'a directory that names itself' => $this->sealHeaderLine(json_encode(['pg' => ['levels' => 1 << 40] + $header['pg']] + $header)) . "\n" . substr_replace($body, $reseal(substr_replace($root, pack('V2', $offset, $length), 4 + $n, 8)), $offset, $length),
            'a child past the file' => substr($intact, 0, $line + 1) . substr_replace($body, $reseal(substr_replace($root, pack('V', 3000000000), 4 + $n + 4, 4)), $offset, $length),
            'a child of no bytes' => substr($intact, 0, $line + 1) . substr_replace($body, $reseal(substr_replace($root, pack('V', 0), 4 + $n + 4, 4)), $offset, $length),
        ];
        foreach ($cases as $case => $bytes) {
            file_put_contents($path, $bytes);
            \noneDB::clearStaticCache();
            $out = $this->runChild('$db = ' . $this->childDb(['shardingEnabled' => false]) . '; echo json_encode(array_column($db->find("dm", ["u" => "value-000001"]), "key"));');
            $this->assertSame('[1]', $out, $case);
        }
    }

    /**
     * @test §6.5 version rule: a .grx of a version the engine doesn't know is not used. Its entries fit their shards and
     * their boxes lie elsewhere: read, they would skip every shard
     */
    public function aGlobalSpatialFileOfAnotherVersionIsNotUsed(): void
    {
        $this->store();
        $expected = $this->answers();
        $path = $this->dbPath('dm', 'grx', 'p');
        $raw = (string)file_get_contents($path);
        $end = (int)strpos($raw, "\n");
        $header = $this->openHeaderLine($path, substr($raw, 0, $end));
        foreach (array_keys($header['sh']) as $shard) {
            $header['sh'][$shard]['b'] = [-1500000000, -800000000, -1400000000, -700000000];
        }
        foreach ([1 => false, 2 => true] as $version => $same) {
            $header['v'] = $version;
            file_put_contents($path, $this->sealHeaderLine(json_encode($header)) . substr($raw, $end));
            \noneDB::clearStaticCache();
            $same ? $this->assertSame($expected, $this->answers()) : $this->assertNotSame($expected, $this->answers(), 'precondition: read, the file skips the shards');
        }
    }

    /**
     * @test §6.5: numbers of a header that its crc fits and no file can hold: keys past the integers, bytes before the
     * file. The file is invalid, the answers stay
     */
    public function aHeaderWithImpossibleNumbersIsInvalid(): void
    {
        $this->store();
        $expected = $this->answers();
        foreach ([
            ['kix', [1], 'base', PHP_INT_MAX],
            ['kix', [1], 'base', PHP_INT_MAX - 500],
            ['fx', ['g', 1], 's', -1],
            ['rx', ['p', 1], 's', -1],
        ] as [$role, $args, $member, $value]) {
            $path = $this->dbPath('dm', $role, ...$args);
            $raw = (string)file_get_contents($path);
            $end = $role === 'kix' ? 511 : (int)strpos($raw, "\n");
            $header = $this->openHeaderLine($path, rtrim(substr($raw, 0, $end), ' '));
            $header[$member] = $value;
            $line = $this->sealHeaderLine(json_encode($header));
            file_put_contents($path, ($role === 'kix' ? str_pad($line, 511) : $line) . substr($raw, $end));
            \noneDB::clearStaticCache();
            $this->assertSame($expected, $this->answers(), "$role $member $value");
            file_put_contents($path, $raw);
        }
    }

    /**
     * @test §6.5: a member of a global index's entry that has another type, or a place before the file, makes it no entry:
     * that shard is read, no error of PHP comes out. The crc fits: the file is hand-made. Its lock epoch is not the lock's,
     * so the entries are read
     */
    public function aGlobalEntryOfOtherTypesIsNoEntry(): void
    {
        $this->store();
        $expected = $this->answers();
        $values = [[1], 7, -1, 'x', 1.5, true, 'absent'];
        foreach (['gfx' => $this->dbPath('dm', 'gfx', 'g'), 'grx' => $this->dbPath('dm', 'grx', 'p')] as $kind => $path) {
            $raw = (string)file_get_contents($path);
            $end = (int)strpos($raw, "\n");
            $header = $this->openHeaderLine($path, substr($raw, 0, $end));
            $this->assertCount(3, $header['sh'], 'precondition: an entry per shard');
            foreach ($kind === 'grx' ? ['s', 'e', 'h', 'b'] : ['s', 'e', 'h'] as $member) {
                foreach ($values as $value) {
                    $changed = $header;
                    $changed['le'] = null;
                    foreach (array_keys($changed['sh']) as $shard) {
                        if ($value === 'absent') {
                            unset($changed['sh'][$shard][$member]);
                        } else {
                            $changed['sh'][$shard][$member] = $value;
                        }
                    }
                    file_put_contents($path, $this->sealHeaderLine(json_encode($changed)) . substr($raw, $end));
                    \noneDB::clearStaticCache();
                    $this->assertSame($expected, $this->answers(), "$kind $member " . json_encode($value));
                }
            }
            file_put_contents($path, $raw);
        }
    }

    /** One segment of 300 points on a grid with its R-tree, written by a process that has ended: [path, header, nodes, entries] */
    private function tree(): array
    {
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('rt', array_map(function ($i) {
            return ['p' => ['type' => 'Point', 'coordinates' => [20 + ($i % 30) / 10, 30 + intdiv($i, 30) / 10]]];
        }, range(0, 299)));
        $this->assertTrue($db->createSpatialIndex('rt', 'p')['success']);
        \noneDB::clearStaticCache();
        $path = $this->dbPath('rt', 'rx', 'p');
        [$line, $nodes, $entries] = explode("\n", (string)file_get_contents($path), 3);
        return [$path, $this->openHeaderLine($path, $line), json_decode($nodes, true), $entries];
    }

    /** The keys of the grid's first five columns of its first five rows, as a new process reads them */
    private function pointsInTheBox(): array
    {
        \noneDB::clearStaticCache();
        $keys = array_column($this->db(['shardingEnabled' => false])->query('rt')->withinBBox('p', 19.95, 29.95, 20.45, 30.45)->get(), 'key');
        sort($keys);
        return $keys;
    }

    /** The box's keys by the model of the grid */
    private function modelOfTheBox(): array
    {
        $keys = [];
        for ($row = 0; $row < 5; $row++) {
            for ($column = 0; $column < 5; $column++) {
                $keys[] = 30 * $row + $column;
            }
        }
        return $keys;
    }

    /** @test §6.5: the first coordinate of leaf entries changed in an .rx body: the body's crc doesn't fit, no record goes missing */
    public function aDamagedRTreeBodyChangesNoAnswer(): void
    {
        [$path, , , $entries] = $this->tree();
        $this->assertSame(0, strlen($entries) % 40, 'precondition: entries of five 64-bit integers');
        $raw = (string)file_get_contents($path);
        $damaged = $entries;
        for ($at = 0; $at < strlen($entries); $at += 40) {
            if (unpack('P', $entries, $at + 8)[1] === 200000000) {
                $damaged = substr_replace($damaged, pack('P', 900000000), $at + 8, 8);
            }
        }
        $this->assertNotSame($entries, $damaged, 'precondition: the entries of the box\'s first column changed');
        file_put_contents($path, substr($raw, 0, strlen($raw) - strlen($entries)) . $damaged);

        $this->assertSame($this->modelOfTheBox(), $this->pointsInTheBox());
        $this->assertTrue($this->db(['shardingEnabled' => false])->checkIntegrity('rt')['ok']);
    }

    /**
     * @test §6.5: an .rx whose crcs fit but which is no tree the code can walk is invalid: rebuilt from the data, no
     * error of PHP, no walk without an end. The read, a write of more records than a node holds (a split) and their delete
     * @dataProvider notTrees
     */
    public function aFileThatIsNoTreeIsRefused(callable $change): void
    {
        [$path, $header, $nodes, $entries] = $this->tree();
        $root = $header['root'];
        $this->assertGreaterThan(0, $nodes[$root][0], 'precondition: the root is above the leaves');
        $change($header, $nodes, $root, $nodes[$root][2][0]);
        $body = json_encode($nodes) . "\n" . $entries;
        $header['bc'] = crc32($body);
        $made = $this->sealHeaderLine(json_encode($header)) . "\n" . $body;
        file_put_contents($path, $made);

        $model = $this->modelOfTheBox();
        $this->assertSame($model, $this->pointsInTheBox());
        $this->assertNotSame($made, (string)file_get_contents($path), 'the file was refused and made again');

        \noneDB::clearStaticCache();
        $db = $this->db(['shardingEnabled' => false]);
        $db->insert('rt', array_fill(0, 40, ['new' => true, 'p' => ['type' => 'Point', 'coordinates' => [20.1, 30.1]]]));
        $this->assertSame(array_merge($model, range(300, 339)), $this->pointsInTheBox());
        $this->assertSame(40, $this->db(['shardingEnabled' => false])->delete('rt', ['new' => true])['n']);
        $this->assertSame($model, $this->pointsInTheBox());
    }

    public function notTrees(): array
    {
        $members = [];
        foreach ([0, 1, 2, 3] as $i) {
            $members["box member $i"] = [function (&$h, &$n, $root, $leaf) use ($i) { $n[$leaf][1][$i] = 'x'; }];
        }
        return $members + [
            'nodes that are a string' => [function (&$h, &$n, $root, $leaf) { $n = str_repeat('x', $root + 1); }],
            'a node that is no array' => [function (&$h, &$n, $root, $leaf) { $n[$leaf] = 5; }],
            'a leaf without a number of entries' => [function (&$h, &$n, $root, $leaf) { unset($n[$leaf][2]); }],
            'a leaf of more entries than the body holds' => [function (&$h, &$n, $root, $leaf) { $n[$leaf][2]++; }],
            'a leaf of fewer' => [function (&$h, &$n, $root, $leaf) { $n[$leaf][2]--; }],
            'a number of entries that is no integer' => [function (&$h, &$n, $root, $leaf) { $n[$leaf][2] = 1.5; }],
            'a number of entries that is an array' => [function (&$h, &$n, $root, $leaf) { $n[$leaf][2] = [1]; }],
            'a number of entries below zero' => [function (&$h, &$n, $root, $leaf) { $other = $n[$root][2][1]; $n[$other][2] += $n[$leaf][2] + 1; $n[$leaf][2] = -1; }],
            'a level that is no integer' => [function (&$h, &$n, $root, $leaf) { $n[$root][0] = 'x'; }],
            'a root below the leaves' => [function (&$h, &$n, $root, $leaf) { $n[$root] = [-1, $n[$root][1], []]; }],
            'children that are no array' => [function (&$h, &$n, $root, $leaf) { $n[$root][2] = 7; }],
            'a box of three' => [function (&$h, &$n, $root, $leaf) { array_pop($n[$leaf][1]); }],
            'no box on a node with entries' => [function (&$h, &$n, $root, $leaf) { $n[$leaf][1] = null; }],
            'a child that is missing' => [function (&$h, &$n, $root, $leaf) { $n[$root][2][0] = $h['next'] + 7; }],
            'a child that is no integer' => [function (&$h, &$n, $root, $leaf) { $n[$root][2][0] = 'x'; }],
            'a child that is an array' => [function (&$h, &$n, $root, $leaf) { $n[$root][2][0] = [1]; }],
            'a child at its parent\'s level' => [function (&$h, &$n, $root, $leaf) { $n[$leaf] = [$n[$root][0], $n[$leaf][1], [$root]]; }],
            'a child of itself' => [function (&$h, &$n, $root, $leaf) { $n[$root][2][0] = $root; }],
            'a child named twice' => [function (&$h, &$n, $root, $leaf) { $n[$root][2][1] = $leaf; }],
            'a node id at the next id' => [function (&$h, &$n, $root, $leaf) { $n[$h['next']] = [0, null, []]; }],
            'a root that is no node' => [function (&$h, &$n, $root, $leaf) { $h['root'] = $h['next'] + 5; }],
            'a root that is no integer' => [function (&$h, &$n, $root, $leaf) { $h['root'] = [$root]; }],
            'a next id that is no integer' => [function (&$h, &$n, $root, $leaf) { $h['next'] = 'x'; }],
            'a node size of no integer' => [function (&$h, &$n, $root, $leaf) { $h['ns'] = 'x'; }],
            'a node size of nothing' => [function (&$h, &$n, $root, $leaf) { $h['ns'] = 0; }],
            'a next id among the nodes' => [function (&$h, &$n, $root, $leaf) { $h['next'] = $leaf; }],
            'a version the engine does not know' => [function (&$h, &$n, $root, $leaf) { $h['v'] = 2; }],
            'a highest key that is no integer' => [function (&$h, &$n, $root, $leaf) { $h['mk'] = []; }],
            'a count that is no integer' => [function (&$h, &$n, $root, $leaf) { $h['cnt'] = []; }],
        ];
    }

    /**
     * @test Bytes of derived files changed at random, in the header (its crc left, or made to fit, as a hand-made file)
     * and in pages sealed again: every answer stays while a crc stands in the way, and in no case does more than a
     * noneDB error come out. Seeds: NONEDB_FUZZ_SEEDS.
     */
    public function damagedDerivedFilesNeverChangeAnAnswer(): void
    {
        $this->store();
        $expected = $this->answers();
        $source = rtrim($this->testDbDir, '/');
        $copy = sys_get_temp_dir() . '/nonedb_damage_' . bin2hex(random_bytes(6));
        exec('cp -R ' . escapeshellarg($source) . ' ' . escapeshellarg($copy));
        $junk = [null, -1, 0, 1, PHP_INT_MAX, 'x', '', [], [1, 2], 1.5, true, ['a' => 1]];
        $change = function (array &$header) use (&$change, $junk): void {
            $key = array_rand($header);
            if (is_array($header[$key]) && $header[$key] !== [] && mt_rand(0, 1)) {
                $change($header[$key]);
            } else {
                $header[$key] = $junk[array_rand($junk)];
            }
        };
        try {
            foreach (explode(',', getenv('NONEDB_FUZZ_SEEDS') ?: '1,2') as $seed) {
                mt_srand((int)$seed);
                for ($round = 0; $round < 30; $round++) {
                    \noneDB::clearStaticCache();
                    exec('rm -rf ' . escapeshellarg($source) . ' && cp -R ' . escapeshellarg($copy) . ' ' . escapeshellarg($source));
                    $files = $this->derived();
                    $file = $files[array_rand($files)];
                    $raw = (string)file_get_contents($file);
                    $kix = substr($file, -4) === '.kix';
                    $end = $kix ? 511 : strpos($raw, "\n");
                    $handMade = (bool)mt_rand(0, 1);
                    $header = $this->openHeaderLine($file, rtrim(substr($raw, 0, $end), ' '));
                    $change($header);
                    $line = json_encode($header);
                    $line = $handMade ? $this->sealHeaderLine($line) : substr($line, 0, -1) . ',"crc":1}';
                    file_put_contents($file, ($kix ? str_pad($line, 511) : $line) . substr($raw, $end));
                    $label = "seed $seed round $round " . basename($file) . ($handMade ? ' hand-made' : ' damaged');
                    $answers = $this->answers();
                    $this->assertStringNotContainsString('Fatal', $answers, $label);
                    $this->assertStringNotContainsString('Warning', $answers, $label);
                    if (!$handMade) {
                        $this->assertSame($expected, $answers, $label);
                    }
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($copy));
        }
    }
}
