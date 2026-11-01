<?php

namespace noneDB\Tests\Unit;

use noneDB\Tests\noneDBTestCase;
use noneDB\Tests\Support\Testing;

/**
 * Shard filters of the global field index (docs/STORAGE_FORMAT.md §6.5.2) against the test's own model: a shard's
 * filter holds the six probe bits of each of its bucket ids; a shard's column of a page of rows (bit i of row r is
 * bit r of shard i's filter) is replaced bit for bit, the other shards' bits untouched.
 */
class ShardFiltersTest extends noneDBTestCase
{
    /** @test a shard's filter: bit r set for every probe row r of every bucket id, none else */
    public function aShardFilterHoldsTheProbeBitsOfItsBucketIds(): void
    {
        $sets = [
            'none' => [],
            'one' => ['sx'],
            'integers' => ['i1', 'i2', 'i-7', 'i9223372036854775807'],
            'many' => array_map(function ($n) { return 's' . "user{$n}@test.com"; }, range(0, 9999)),
        ];
        foreach ([64, 2048, 1 << 19] as $bits) {
            foreach ($sets as $label => $ids) {
                $this->assertSame($this->shardFilterOfIds($ids, $bits), Testing::callStatic('ShardFilters::filter', $ids, $bits), "$label, $bits bits");
            }
        }
    }

    /**
     * @test §6.5.2 "Lookup": from a body held in memory, the shards a value may be in are those whose filter has all six of
     * its probe bits, ascending. Filters and pages from the test's own model; one page, many, and pages of a single row;
     * values present, and values of no shard (a saturated filter holds them too, by the model)
     */
    public function aHeldBodyAnswersWithTheShardsWhoseFiltersHoldTheValue(): void
    {
        foreach ([[64, 8, 4], [2048, 8, 8], [2048, 16, 11], [2048, 32768, 9], [65536, 1024, 40], [1 << 19, 128, 100]] as [$bits, $width, $shards]) {
            $rb = intdiv($width, 8);
            $per = $this->globalPageRows($bits, $width);
            $stored = $per * $rb + 4;
            $body = str_repeat("\0", intdiv($bits, $per) * $stored);
            $filters = [];
            $present = [];
            for ($shard = 0; $shard < $shards; $shard++) {
                $ids = array_map(function ($n) use ($shard) { return "s$shard-$n"; }, range(0, 19));
                $present = array_merge($present, array_slice($ids, 0, 2));
                $filters[$shard] = $this->shardFilterOfIds($ids, $bits);
                foreach ($ids as $id) {
                    foreach ($this->filterProbes($id, $bits) as $r) {
                        $at = intdiv($r, $per) * $stored + ($r % $per) * $rb + intdiv($shard, 8);
                        $body[$at] = chr(ord($body[$at]) | (1 << ($shard % 8)));
                    }
                }
            }
            foreach (array_merge($present, array_map(function ($n) { return "none$n"; }, range(0, 199))) as $id) {
                $expected = [];
                foreach ($filters as $shard => $filter) {
                    $all = true;
                    foreach ($this->filterProbes($id, $bits) as $r) {
                        $all = $all && (ord($filter[intdiv($r, 8)]) & (1 << ($r % 8))) !== 0;
                    }
                    if ($all) {
                        $expected[] = $shard;
                    }
                }
                $this->assertSame($expected, Testing::callStatic('ShardFilters::holdersIn', $body, $bits, $rb, $id), "$bits bits, $width wide: $id");
            }
        }
    }

    /**
     * @test a shard's column in rows [first, first + n) of a page takes bits first … first + n - 1 of its filter;
     * every other bit of every row stays. Rows of 1 to 4096 bytes: pages of 4096 rows down to pages of 1 row.
     */
    public function aColumnTakesItsFilterBitsAndKeepsEveryOtherBit(): void
    {
        $bits = 2048;
        $filter = random_bytes(intdiv($bits, 8));
        $cases = [[8, 0], [8, 7], [16, 8], [16, 13], [32, 31], [64, 40], [8192, 0], [8192, 4095], [16384, 9000], [32768, 32767]];
        foreach ($cases as [$width, $shard]) {
            $rb = intdiv($width, 8);
            $per = $this->globalPageRows($bits, $width);
            $pages = intdiv($bits, $per);
            foreach (array_unique([0, min(1, $pages - 1), min(5, $pages - 1), $pages - 1]) as $page) {
                $first = $page * $per;
                $rows = random_bytes($per * $rb);
                $expected = $rows;
                for ($r = 0; $r < $per; $r++) {
                    $at = $r * $rb + intdiv($shard, 8);
                    $bit = 1 << ($shard % 8);
                    $set = (ord($filter[intdiv($first + $r, 8)]) >> (($first + $r) % 8)) & 1;
                    $expected[$at] = chr($set === 1 ? ord($expected[$at]) | $bit : ord($expected[$at]) & (0xFF ^ $bit));
                }
                $label = "width $width ($per rows per page), shard $shard, page $page";
                $this->assertSame($expected, Testing::callStatic('ShardFilters::withColumn', $rows, $first, $rb, $shard, $filter), $label);
                $this->assertSame(str_repeat("\0", $per * $rb) | ($expected & $this->columnOnly($per, $rb, $shard)),
                    Testing::callStatic('ShardFilters::withColumn', str_repeat("\0", $per * $rb), $first, $rb, $shard, $filter), "$label, from empty rows");
            }
        }
    }

    /** Rows with only $shard's bit set in each */
    private function columnOnly(int $per, int $rb, int $shard): string
    {
        $row = str_repeat("\0", $rb);
        $row[intdiv($shard, 8)] = chr(1 << ($shard % 8));
        return str_repeat($row, $per);
    }
}
