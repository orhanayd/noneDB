<?php
// Shard filters of the global field index .gfx: probes, a shard's filter, rows and pages (docs/STORAGE_FORMAT.md §6.5.2)
namespace noneDB\Internal\Index;

use function array_chunk, array_fill, chr, chunk_split, crc32, hash, intdiv, max, min, ord, pack, str_repeat, strlen, strspn,
    strtr, substr, unpack;

/**
 * @internal
 */
final class ShardFilters {
    /** Per bit value (1 << (shard & 7)): byte b of a filter => its eight rows, one byte each ($bit or 0) */
    private static $columnBytes = [];

    /**
     * The six probe rows of a bucket id in filters of $bits bits: from its xxh3, x the first four bytes and y the
     * last four (lowest bit set), big-endian; row (x + i·y) mod 2³² mod $bits
     * @return int[]
     */
    public static function probes(string $id, int $bits): array {
        $d = unpack('N2', hash('xxh3', $id, true));
        $y = $d[2] | 1;
        $rows = [];
        for($i = 0; $i < 6; $i++){
            $rows[] = (($d[1] + $i * $y) & 0xFFFFFFFF) & ($bits - 1);
        }
        return $rows;
    }

    /**
     * The filter of a shard whose field index holds these bucket ids: $bits bits (bit r: byte r >> 3, value 1 << (r & 7)).
     * The probes of probes(), set in 64-bit words (bit r: word r >> 6, bit r & 63), packed little-endian.
     */
    public static function filter(array $ids, int $bits): string {
        $words = array_fill(0, $bits >> 6, 0);
        $mask = ($bits - 1) & 0xFFFFFFFF; // mod 2³² mod $bits (a power of two)
        foreach($ids as $id){
            $d = unpack('N2', hash('xxh3', (string)$id, true));
            $y = $d[2] | 1;
            for($i = 0, $v = $d[1]; $i < 6; $i++, $v += $y){
                $r = $v & $mask;
                $words[$r >> 6] |= 1 << ($r & 63);
            }
        }
        $filter = '';
        foreach(array_chunk($words, 4096) as $chunk){
            $filter .= pack('P*', ...$chunk);
        }
        return $filter;
    }

    /**
     * Rows [$first, $first + n) of $rowBytes bytes each (a page: n a power of two, $first a multiple of it), with
     * $shard's column taken from its filter: the shard's bit of row r (byte $shard >> 3, value 1 << ($shard & 7))
     * becomes bit $first + r of the filter; every other bit stays
     */
    public static function withColumn(string $rows, int $first, int $rowBytes, int $shard, string $filter): string {
        $n = intdiv(strlen($rows), $rowBytes);
        $bit = 1 << ($shard & 7);
        if(!isset(self::$columnBytes[$bit])){
            for($b = 0; $b < 256; $b++){
                $eight = '';
                for($k = 0; $k < 8; $k++){
                    $eight .= ($b >> $k) & 1 ? chr($bit) : "\0";
                }
                self::$columnBytes[$bit][chr($b)] = $eight;
            }
        }
        // The filter's bits of these rows, one byte per row; then each at its place in its row
        $column = substr(strtr(substr($filter, $first >> 3, ($n + 7) >> 3), self::$columnBytes[$bit]), $first & 7, $n);
        $at = $shard >> 3;
        $column = substr(str_repeat("\0", $at) . chunk_split($column, 1, str_repeat("\0", $rowBytes - 1)), 0, $n * $rowBytes);
        $keep = str_repeat(str_repeat("\xFF", $at) . chr(0xFF ^ $bit) . str_repeat("\xFF", $rowBytes - $at - 1), $n);
        return ($rows & $keep) | $column;
    }

    /** True when a filter has every probe bit (the shard may hold the bucket id) */
    public static function mayHold(string $filter, array $probes): bool {
        foreach($probes as $r){
            if((ord($filter[$r >> 3]) & (1 << ($r & 7))) === 0){
                return false;
            }
        }
        return true;
    }

    /** The smallest power of two of at least max($min, $n): a number of bits or the width */
    public static function size(int $n, int $min): int {
        $size = $min;
        while($size < $n){
            $size <<= 1;
        }
        return $size;
    }

    /** Rows per page for rows of $width bits (a page holds about 4096 bytes of rows) */
    public static function pageRows(int $bits, int $width): int {
        return min($bits, max(1, intdiv(4096, $width >> 3)));
    }

    /** A page as it is stored: its rows, then their crc32 (u32 little-endian) */
    public static function seal(string $rows): string {
        return $rows . pack('V', crc32($rows));
    }

    /** The rows of a stored page of $length bytes of rows; null when it is short or its crc doesn't match */
    public static function open(string $stored, int $length): ?string {
        if(strlen($stored) !== $length + 4){
            return null;
        }
        $rows = substr($stored, 0, $length);
        return unpack('V', $stored, $length)[1] === crc32($rows) ? $rows : null;
    }

    /**
     * The shards (bit positions) set in every row: the AND of the rows, its set bits
     * @param string[] $rows
     * @return int[] ascending
     */
    public static function holders(array $rows): array {
        $and = null;
        foreach($rows as $row){
            $and = $and === null ? $row : $and & $row;
        }
        return self::setBits($and);
    }

    /**
     * The shards that may hold a bucket id, from a file's body in memory: the rows of probes() (x + i·y, the mod 2³² of
     * a power of two is its mask) read in place, the AND given up at the first row that leaves no shard. A warm lookup.
     * @param string $body The stored pages
     * @param int $rb Bytes of a row
     * @return int[] ascending
     */
    public static function holdersIn(string $body, int $bits, int $rb, string $id): array {
        $d = unpack('N2', hash('xxh3', $id, true));
        $x = $d[1];
        $y = $d[2] | 1;
        $mask = $bits - 1;
        $per = self::pageRows($bits, $rb << 3);
        $stored = $per * $rb + 4;
        $and = null;
        for($i = 0; $i < 6; $i++, $x += $y){
            $r = $x & $mask;
            $row = substr($body, intdiv($r, $per) * $stored + ($r % $per) * $rb, $rb);
            $and = $and === null ? $row : $and & $row;
            if(strspn($and, "\0") === $rb){
                return [];
            }
        }
        return self::setBits($and);
    }

    /** @return int[] The bit positions set in a row, ascending */
    private static function setBits(string $and): array {
        $shards = [];
        $length = strlen($and);
        for($o = strspn($and, "\0"); $o < $length; $o += 1 + strspn($and, "\0", $o + 1)){
            $byte = ord($and[$o]);
            for($bit = 0; $bit < 8; $bit++){
                if($byte & (1 << $bit)){
                    $shards[] = 8 * $o + $bit;
                }
            }
        }
        return $shards;
    }
}
