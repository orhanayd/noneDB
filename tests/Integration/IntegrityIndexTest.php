<?php

namespace noneDB\Tests\Integration;

use noneDB\Tests\RegressionTestCase;

/**
 * docs/OPERATIONS.md §2: checkIntegrity() compares the indexes with the data. A field index or an R-tree that fits its
 * segment is read through its own format, with the lines after it replayed; what it holds must be what the records say.
 * The files are written by the tests' own codecs (noneDBTestCase), with every crc right: nothing but the comparison can
 * find them.
 */
class IntegrityIndexTest extends RegressionTestCase
{
    /** One value per record but for "many", which keys 7, 17 ... 67 share: buckets of one key and a bucket of a list */
    private function records(int $n): array
    {
        $records = [];
        for ($i = 0; $i < $n; $i++) {
            $records[] = ['c' => $i % 10 === 7 && $i < 70 ? 'many' : 'v' . $i];
        }
        return $records;
    }

    private function mismatches(array $report): array
    {
        return array_map(function ($m) { return [$m['segment'], $m['index']]; }, $report['indexMismatches']);
    }

    public function sizes(): array
    {
        return ['a page' => [20], 'many pages' => [2000]];
    }

    public function editedBuckets(): array
    {
        return [
            'a key under the value of another' => [function (array $map) { unset($map['sv3']); $map['sv4'] = [3, 4]; return $map; }],
            'a key missing' => [function (array $map) { unset($map['sv3']); return $map; }],
            'a value no record has' => [function (array $map) { $map['sghost'] = 3; return $map; }],
            'a list a key short' => [function (array $map) { $map['smany'] = array_slice($map['smany'], 1); return $map; }],
            'another key in a list' => [function (array $map) { $map['smany'][] = 1000000; return $map; }],
        ];
    }

    /**
     * @test
     * @dataProvider editedBuckets
     */
    public function aFieldIndexThatHoldsOtherKeysIsReported(callable $edit): void
    {
        foreach ([20, 2000] as $n) {
            $name = 'fi' . $n;
            $config = ['shardingEnabled' => false];
            $db = $this->db($config);
            $db->insert($name, $this->records($n));
            $db->createFieldIndex($name, 'c');
            $this->assertSame([], $db->checkIntegrity($name)['indexMismatches'], 'the index as the engine wrote it');

            $fx = $this->dbPath($name, 'fx', 'c');
            [$header, $map] = $this->readFieldIndexFile($fx);
            $this->assertSame($n === 20 ? 0 : 1, min(1, $header['pg']['levels']), 'precondition: pages');
            $this->writeFieldIndexFile($fx, $header, $edit($map));
            \noneDB::clearStaticCache();

            $report = $this->db($config)->checkIntegrity($name);
            $this->assertFalse($report['ok'], "$n records");
            $this->assertSame([['main', 'field c']], $this->mismatches($report), "$n records");
            $this->assertSame($n, $report['records'], 'records come from the data');

            $this->db($config)->rebuildIndex($name);
            $this->assertSame([], $this->db($config)->checkIntegrity($name)['indexMismatches'], "$n records: repaired");
        }
    }

    /** @test a shard's field index is compared too, and named by its shard */
    public function aShardsFieldIndexIsComparedWithItsRecords(): void
    {
        $config = ['shardSize' => 500];
        $db = $this->db($config);
        $db->insert('fs', $this->records(1200));
        $db->createFieldIndex('fs', 'c');
        $this->assertSame([], $db->checkIntegrity('fs')['indexMismatches']);

        $fx = $this->dbPath('fs', 'fx', 'c', 1);
        [$header, $map] = $this->readFieldIndexFile($fx);
        unset($map['sv600']);
        $this->writeFieldIndexFile($fx, $header, $map);
        \noneDB::clearStaticCache();

        $report = $this->db($config)->checkIntegrity('fs');
        $this->assertFalse($report['ok']);
        $this->assertSame([[1, 'field c']], $this->mismatches($report));
    }

    /**
     * @test the lines after the bytes a file covers are replayed: a clean database reports nothing, with every type of
     * value, a key that came into a list below the keys in it, a value that moved and a record gone
     * @dataProvider sizes
     */
    public function aCurrentFieldIndexReportsNothing(int $n): void
    {
        $config = ['shardingEnabled' => false];
        $db = $this->db($config);
        $db->insert('cl', $this->records($n));
        $db->insert('cl', [['c' => 12], ['c' => 1.5], ['c' => true], ['c' => null], ['c' => '12'], ['c' => 12], ['n' => 'no field'], ['c' => [1]]]);
        $db->createFieldIndex('cl', 'c');
        $this->assertSame(1, $db->update('cl', [['key' => [$n - 1]], ['set' => ['c' => 'many']]])['n'], 'a higher key joins the list');
        $this->assertSame(1, $db->update('cl', [['key' => [17]], ['set' => ['c' => 'elsewhere']]])['n']);
        $this->assertSame(1, $db->update('cl', [['key' => [8]], ['set' => ['c' => 'many']]])['n'], 'a key below some of the list');
        $this->assertSame(1, $db->delete('cl', ['key' => [9]])['n']);
        $db->insert('cl', ['c' => 'many']);

        $report = $db->checkIntegrity('cl');
        $this->assertSame([], $report['indexMismatches']);
        $this->assertTrue($report['ok']);

        \noneDB::clearStaticCache();
        $report = $this->db($config)->checkIntegrity('cl');
        $this->assertSame([], $report['indexMismatches'], 'the same from the files alone');
        $this->assertTrue($report['ok']);
    }

    public function damages(): array
    {
        return [
            'a byte of the first page' => [function (string $body) { $body[20] = chr(ord($body[20]) ^ 1); return $body; }],
            'a byte of the last page' => [function (string $body) { $at = strlen($body) - 2; $body[$at] = chr(ord($body[$at]) ^ 1); return $body; }],
            'cut short' => [function (string $body) { return substr($body, 0, -100); }],
            'no body' => [function (string $body) { return ''; }],
        ];
    }

    public function treeDamages(): array
    {
        return $this->damages() + ['more bytes' => [function (string $body) { return $body . 'x'; }]];
    }

    /**
     * @test a file that fits its segment and can't be read holds none of the records; the answers stay, rebuildIndex() repairs it
     * @dataProvider damages
     */
    public function aFieldIndexOfDamagedPagesIsReported(callable $damage): void
    {
        $config = ['shardingEnabled' => false];
        $db = $this->db($config);
        $db->insert('dp', $this->records(2000));
        $db->createFieldIndex('dp', 'c');
        $fx = $this->dbPath('dp', 'fx', 'c');
        [$line, $body] = explode("\n", (string)file_get_contents($fx), 2);
        file_put_contents($fx, $line . "\n" . $damage($body));
        \noneDB::clearStaticCache();

        $report = $this->db($config)->checkIntegrity('dp');
        $this->assertFalse($report['ok']);
        $this->assertSame([['main', 'field c']], $this->mismatches($report));
        $this->assertSame([1999], array_column($this->db($config)->find('dp', ['c' => 'v1999']), 'key'));

        $this->db($config)->rebuildIndex('dp');
        $this->assertSame([], $this->db($config)->checkIntegrity('dp')['indexMismatches'], 'repaired');
    }

    /**
     * @test the same for the body of an R-tree, which `bc` covers whole: a byte more is damage there
     * @dataProvider treeDamages
     */
    public function anRTreeOfADamagedBodyIsReported(callable $damage): void
    {
        $config = ['shardingEnabled' => false];
        $db = $this->db($config);
        $records = [];
        for ($i = 0; $i < 300; $i++) {
            $records[] = ['loc' => ['type' => 'Point', 'coordinates' => [$i % 50, $i % 40]]];
        }
        $db->insert('dr', $records);
        $db->createSpatialIndex('dr', 'loc');
        $rx = $this->dbPath('dr', 'rx', 'loc');
        [$line, $body] = explode("\n", (string)file_get_contents($rx), 2);
        file_put_contents($rx, $line . "\n" . $damage($body));
        \noneDB::clearStaticCache();

        $report = $this->db($config)->checkIntegrity('dr');
        $this->assertFalse($report['ok']);
        $this->assertSame([['main', 'spatial loc']], $this->mismatches($report));

        $this->db($config)->rebuildIndex('dr');
        $this->assertSame([], $this->db($config)->checkIntegrity('dr')['indexMismatches'], 'repaired');
    }
}
