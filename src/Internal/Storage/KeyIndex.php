<?php
// A segment's key index in memory and in its checkpoint {seg}.kix (docs/STORAGE_FORMAT.md §6.4)
namespace noneDB\Internal\Storage;

use function array_combine, array_fill, array_filter, array_intersect_key, array_key_last, array_keys,
    array_replace, array_slice, array_values, count, crc32, in_array, intdiv, is_array, is_int, is_string,
    fclose, fopen, fread, fstat, json_decode, json_encode, ksort, max, min, pack, range, rtrim, str_pad, strlen, strrpos, substr, time, unpack;
use const JSON_UNESCAPED_SLASHES, PHP_INT_MAX, PHP_INT_MIN;

/**
 * A key index is an array: the checkpoint's members (n, d, t, s, e, created, updated, z, sid), the runtime
 * members ck (bytes the checkpoint on disk covers, -1 none) and tl (lines replayed after it), and the keys:
 * - kb: the verified body of the checkpoint (blocks of 4096 bytes with their crc), kl its layout ('dense' or
 *   'sparse'), kbase its first key, kslots its slots (dense) or entries (sparse), kmax its highest live key;
 * - p / l: offset and length of every key written since the checkpoint; x: keys of the body deleted since;
 * - live: the number of live keys.
 * No per-key array of the whole segment is built: a body costs 12 bytes per slot.
 * @internal
 */
final class KeyIndex {
    public const HEADER = 512;
    public const BLOCK = 4096;
    public const DENSE_SLOTS = 341;   // 341 offsets (8 bytes) then 341 lengths (4 bytes) = 4092 bytes + the crc
    public const SPARSE_ENTRIES = 255; // 255 keys (4), offsets (8), lengths (4) = 4080 bytes, zero bytes up to 4092, the crc
    /** crc32 of bytes followed by their own crc32 (u32 little-endian): a block checks with one call */
    public const CRC_RESIDUE = 0x2144DF1C;

    /** An empty key index */
    public static function emptyIndex(): array {
        $now = time();
        return ['n' => 0, 'd' => 0, 't' => 0, 's' => 0, 'e' => null, 'created' => $now, 'updated' => $now,
            'ck' => -1, 'ckh' => null, 'tl' => 0, 'kb' => '', 'kl' => 'dense', 'kbase' => 0, 'kslots' => 0, 'kmax' => -1, 'live' => 0,
            'p' => [], 'l' => [], 'x' => [], 'looked' => 0, 'uses' => 0, 'ses' => -1];
    }

    // ------------------------------------------------------------------ lookups

    /** [offset, length] of a key in the body, or null */
    public static function baseGet(array $index, int $key): ?array {
        $kb = $index['kb'];
        if($kb === ''){
            return null;
        }
        $local = $key - $index['kbase'];
        if($local < 0){
            return null;
        }
        $slots = $index['kslots'];
        if($index['kl'] === 'dense'){
            if($local >= $slots){
                return null;
            }
            $block = intdiv($local, self::DENSE_SLOTS);
            $first = $block * self::DENSE_SLOTS;
            $m = $slots - $first < self::DENSE_SLOTS ? $slots - $first : self::DENSE_SLOTS;
            $at = $block * self::BLOCK;
            $length = unpack('V', $kb, $at + 8 * $m + 4 * ($local - $first))[1];
            return $length === 0 ? null : [unpack('P', $kb, $at + 8 * ($local - $first))[1], $length];
        }
        $lo = 0;
        $hi = $slots - 1;
        while($lo <= $hi){
            $mid = ($lo + $hi) >> 1;
            $block = intdiv($mid, self::SPARSE_ENTRIES);
            $first = $block * self::SPARSE_ENTRIES;
            $i = $mid - $first;
            $at = $block * self::BLOCK;
            $found = unpack('V', $kb, $at + 4 * $i)[1];
            if($found === $local){
                $m = $slots - $first < self::SPARSE_ENTRIES ? $slots - $first : self::SPARSE_ENTRIES;
                return [unpack('P', $kb, $at + 4 * $m + 8 * $i)[1], unpack('V', $kb, $at + 12 * $m + 4 * $i)[1]];
            }
            if($found < $local){
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        return null;
    }

    public static function has(array $index, int $key): bool {
        return isset($index['p'][$key]) || ($index['kb'] !== '' && !isset($index['x'][$key]) && self::baseGet($index, $key) !== null);
    }

    public static function count(array $index): int {
        return $index['live'];
    }

    /**
     * Point a key at a line; true when the key had a record before (its old line becomes garbage)
     */
    public static function set(array &$index, int $key, int $offset, int $length): bool {
        $existed = isset($index['p'][$key]) || (!isset($index['x'][$key]) && self::baseGet($index, $key) !== null);
        $index['p'][$key] = $offset;
        $index['l'][$key] = $length;
        unset($index['x'][$key]);
        if(!$existed){
            $index['live']++;
        }
        return $existed;
    }

    /**
     * Point the keys of appended lines at them: $lines [key => line without "\n"] written in this order from
     * byte $offset. Returns how many keys had a record before (their old lines are garbage now).
     */
    public static function setLines(array &$index, array $lines, int $offset): int {
        $replaced = 0;
        $added = 0;
        $p = &$index['p'];
        $l = &$index['l'];
        if($index['kb'] === ''){
            foreach($lines as $key => $json){
                if(isset($p[$key])){
                    $replaced++;
                } else {
                    $added++;
                }
                $p[$key] = $offset;
                $l[$key] = $length = strlen($json);
                $offset += $length + 1;
            }
        } else {
            $x = &$index['x'];
            foreach($lines as $key => $json){
                if(isset($p[$key]) || (!isset($x[$key]) && self::baseGet($index, $key) !== null)){
                    $replaced++;
                } else {
                    $added++;
                }
                $p[$key] = $offset;
                $l[$key] = $length = strlen($json);
                unset($x[$key]);
                $offset += $length + 1;
            }
            unset($x);
        }
        unset($p, $l);
        $index['live'] += $added;
        return $replaced;
    }

    /** Delete keys; returns how many had a record */
    public static function removeMany(array &$index, array $keys): int {
        $removed = 0;
        if($index['kb'] === ''){
            // A decoded index: its arrays only, no call per key
            $p = &$index['p'];
            $l = &$index['l'];
            foreach($keys as $key){
                if(isset($p[$key])){
                    unset($p[$key], $l[$key]);
                    $removed++;
                }
            }
            unset($p, $l);
            $index['live'] -= $removed;
            return $removed;
        }
        foreach($keys as $key){
            if(self::remove($index, (int)$key)){
                $removed++;
            }
        }
        return $removed;
    }

    /** Delete a key; true when it had a record */
    public static function remove(array &$index, int $key): bool {
        if(isset($index['p'][$key])){
            unset($index['p'][$key], $index['l'][$key]);
            if(self::baseGet($index, $key) !== null){
                $index['x'][$key] = true;
            }
            $index['live']--;
            return true;
        }
        if(isset($index['x'][$key]) || self::baseGet($index, $key) === null){
            return false;
        }
        $index['x'][$key] = true;
        $index['live']--;
        return true;
    }

    /** The highest live key (-1: none) */
    public static function maxKey(array $index): int {
        $max = $index['kmax'] >= 0 && !isset($index['x'][$index['kmax']]) ? $index['kmax'] : self::bodyMax($index);
        foreach($index['p'] as $key => $unused){
            if($key > $max){
                $max = $key;
            }
        }
        return $max;
    }


    /** Every live key: ascending, but in the order of its arrays for a decoded index (the caller sorts) */
    public static function keys(array $index): array {
        if($index['kb'] === ''){
            return array_keys($index['p']);
        }
        if(empty($index['p']) && empty($index['x'])){
            return self::baseKeys($index);
        }
        // The body's keys, the removed ones out, the delta's in (no array per key)
        $live = self::bodyArrays($index)[1];
        foreach($index['x'] as $key => $unused){
            unset($live[$key]);
        }
        if(!empty($index['l'])){
            foreach($index['l'] as $key => $length){
                $live[$key] = $length;
            }
            ksort($live);
        }
        return array_keys($live);
    }

    /**
     * Offsets and lengths of $keys (all live keys when null) for a batch read: [key => offset], [key => length]
     */
    public static function locate(array $index, ?array $keys): array {
        $positions = [];
        $lengths = [];
        if($keys === null){
            // Every live key: the arrays of a decoded index (the body unpacked in a few C-level calls, the
            // delta applied); in no particular order, the batch read orders by position
            $all = self::materialize($index);
            return [$all['p'], $all['l']];
        }
        $p = $index['p'];
        $l = $index['l'];
        if($index['kb'] === ''){
            // Every key in the arrays (a decoded index): the hot path, no call per key
            foreach($keys as $key){
                if(isset($p[$key])){
                    $positions[$key] = $p[$key];
                    $lengths[$key] = $l[$key];
                }
            }
            return [$positions, $lengths];
        }
        $x = $index['x'];
        foreach($keys as $key){
            if(isset($p[$key])){
                $positions[$key] = $p[$key];
                $lengths[$key] = $l[$key];
            } elseif(!isset($x[$key]) && ($e = self::baseGet($index, (int)$key)) !== null){
                $positions[$key] = $e[0];
                $lengths[$key] = $e[1];
            }
        }
        return [$positions, $lengths];
    }

    /**
     * The same index with every key in p/l (no body): lookups at array speed, about 5× the memory of a body.
     * For a segment in repeated use (plan D6: hot segments are kept decoded within the cache budget).
     */
    public static function materialize(array $index): array {
        if($index['kb'] === ''){
            return $index;
        }
        [$p, $l] = self::bodyArrays($index);
        foreach($index['x'] as $key => $unused){
            unset($p[$key], $l[$key]);
        }
        foreach($index['p'] as $key => $offset){
            $p[$key] = $offset;
            $l[$key] = $index['l'][$key];
        }
        $index['p'] = $p;
        $index['l'] = $l;
        $index['x'] = [];
        $index['kb'] = '';
        $index['kslots'] = 0;
        $index['kmax'] = -1;
        return $index;
    }

    /** The body's live keys as [key => offset], [key => length], ascending (a few C-level calls per block) */
    private static function bodyArrays(array $index): array {
        $kb = $index['kb'];
        $base = $index['kbase'];
        $slots = $index['kslots'];
        $p = [];
        $l = [];
        if($index['kl'] === 'dense'){
            if($slots === 0){
                return [[], []];
            }
            // The offset and length runs of every block joined, then decoded at once
            $offsets = '';
            $lengths = '';
            for($block = 0, $first = 0; $first < $slots; $block++, $first += self::DENSE_SLOTS){
                $m = min(self::DENSE_SLOTS, $slots - $first);
                $at = $block * self::BLOCK;
                $offsets .= substr($kb, $at, 8 * $m);
                $lengths .= substr($kb, $at + 8 * $m, 4 * $m);
            }
            // One array at a time, the runs released once used (the peak of a decode stays near its result)
            $keys = range($base, $base + $slots - 1);
            $l = array_combine($keys, unpack('V*', $lengths));
            $lengths = null;
            if(in_array(0, $l, true)){
                $l = array_filter($l); // slots without a record (length 0)
            }
            $p = array_combine($keys, unpack('P*', $offsets));
            $offsets = $keys = null;
            if(count($l) < $slots){
                $p = array_intersect_key($p, $l);
            }
            return [$p, $l];
        }
        for($block = 0, $first = 0; $first < $slots; $block++, $first += self::SPARSE_ENTRIES){
            $m = min(self::SPARSE_ENTRIES, $slots - $first);
            $at = $block * self::BLOCK;
            $keys = [];
            foreach(unpack('V' . $m, $kb, $at) as $local){
                $keys[] = $base + $local;
            }
            $p += array_combine($keys, unpack('P' . $m, $kb, $at + 4 * $m));
            $l += array_combine($keys, unpack('V' . $m, $kb, $at + 12 * $m));
        }
        return [$p, $l];
    }


    // ------------------------------------------------------------------ the body

    /** Live entries of the body: key => [offset, length], ascending */

    /** Live keys of the body, ascending */
    private static function baseKeys(array $index): array {
        return array_keys(self::bodyArrays($index)[1]);
    }

    /**
     * Encode the live keys of an index as a checkpoint body for a segment whose first key is $base:
     * [layout, slots, body, highest key, live keys]
     */
    public static function encodeBody(array $index, int $base): array {
        [$p, $l] = self::allArrays($index);
        $live = count($p);
        $max = $live === 0 ? -1 : (int)array_key_last($p);
        $slots = $max < $base ? 0 : $max - $base + 1;
        $body = '';
        if($slots <= max(1024, 4 * $live)){
            // Every slot in key order (0 where no record), built without a loop per key
            $zeros = $slots === 0 ? [] : array_fill($base, $slots, 0);
            $offsets = array_values($live === $slots ? $p : array_replace($zeros, $p));
            $lengths = array_values($live === $slots ? $l : array_replace($zeros, $l));
            unset($zeros);
            for($first = 0; $first < $slots; $first += self::DENSE_SLOTS){
                $m = min(self::DENSE_SLOTS, $slots - $first);
                $data = pack('P*', ...array_slice($offsets, $first, $m)) . pack('V*', ...array_slice($lengths, $first, $m));
                $body .= $data . pack('V', crc32($data));
            }
            return ['dense', $slots, $body, $max, $live];
        }
        $keys = [];
        $offsets = [];
        $lengths = [];
        foreach($p as $key => $offset){
            $keys[] = $key - $base;
            $offsets[] = $offset;
            $lengths[] = $l[$key];
            if(count($keys) === self::SPARSE_ENTRIES){
                $data = str_pad(pack('V*', ...$keys) . pack('P*', ...$offsets) . pack('V*', ...$lengths), self::BLOCK - 4, "\0");
                $body .= $data . pack('V', crc32($data));
                $keys = [];
                $offsets = [];
                $lengths = [];
            }
        }
        if(!empty($keys)){
            $data = str_pad(pack('V*', ...$keys) . pack('P*', ...$offsets) . pack('V*', ...$lengths), self::BLOCK - 4, "\0");
            $body .= $data . pack('V', crc32($data));
        }
        return ['sparse', $live, $body, $max, $live];
    }

    /** Every live key as [key => offset], [key => length], ascending by key (no pair per key) */
    private static function allArrays(array $index): array {
        if($index['kb'] === ''){
            $p = $index['p'];
            $l = $index['l'];
        } else {
            [$p, $l] = self::bodyArrays($index);
            foreach($index['x'] as $key => $unused){
                unset($p[$key], $l[$key]);
            }
            foreach($index['p'] as $key => $offset){
                $p[$key] = $offset;
                $l[$key] = $index['l'][$key];
            }
        }
        // Sorted only when not ascending already (sorting copies arrays the index shares), both by key
        $previous = PHP_INT_MIN;
        foreach($p as $key => $unused){
            if($key < $previous){
                ksort($p);
                ksort($l);
                break;
            }
            $previous = $key;
        }
        return [$p, $l];
    }

    /** A derived file's header: its JSON with its own crc as the last member (§6.5) */
    public static function sealHeader(string $json): string {
        return substr($json, 0, -1) . ',"crc":' . crc32($json) . '}';
    }

    /** The members of a derived file's header, or null when its crc doesn't fit */
    public static function openHeader(string $line): ?array {
        $line = rtrim($line, " \n");
        $at = strrpos($line, ',"crc":');
        if($at === false || substr($line, $at + 7, -1) !== (string)crc32(substr($line, 0, $at) . '}')){
            return null;
        }
        $header = json_decode($line, true);
        if(!is_array($header)){
            return null;
        }
        unset($header['crc']);
        return $header;
    }

    /** The bytes of a checkpoint file: header line and body */
    public static function encodeFile(array $header, string $body): string {
        return str_pad(self::sealHeader((string)json_encode($header, JSON_UNESCAPED_SLASHES)), self::HEADER - 1, ' ') . "\n" . $body;
    }

    /**
     * Read a checkpoint file's bytes: [header, body] when its header, body length and every block crc are
     * valid (§6.4), else null
     */
    public static function decodeFile($raw): ?array {
        if(!is_string($raw) || strlen($raw) < self::HEADER){
            return null;
        }
        $header = self::headerOf(substr($raw, 0, self::HEADER));
        if($header === null || strlen($raw) !== self::HEADER + self::bodyLength($header)){
            return null;
        }
        $body = substr($raw, self::HEADER);
        return self::bodyFits($body) ? [$header, $body] : null;
    }

    /**
     * The checkpoint at $path, as decodeFile() returns it, read through one handle: the header, then the
     * body it announces (nothing may follow). Only the body is held, never the file and its body at once.
     */
    public static function readFile(string $path): ?array {
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return null;
        }
        try {
            $header = self::headerOf((string)fread($fp, self::HEADER));
            if($header === null){
                return null;
            }
            $length = self::bodyLength($header);
            if((fstat($fp)['size'] ?? null) !== self::HEADER + $length){
                return null; // a body the file doesn't hold is never asked for
            }
            $body = $length === 0 ? '' : (string)fread($fp, $length);
            if(strlen($body) !== $length){
                return null;
            }
            return self::bodyFits($body) ? [$header, $body] : null;
        } finally {
            fclose($fp);
        }
    }

    /** The header of a checkpoint (its first HEADER bytes, ending with a newline), or null when it is not valid */
    private static function headerOf(string $head): ?array {
        if(strlen($head) !== self::HEADER || $head[self::HEADER - 1] !== "\n"){
            return null;
        }
        $header = self::openHeader(substr($head, 0, self::HEADER - 1));
        if($header === null || ($header['kind'] ?? null) !== 'kix' || ($header['v'] ?? null) !== 1
            || !in_array($header['layout'] ?? null, ['dense', 'sparse'], true)){
            return null;
        }
        foreach(['base', 'slots', 'live', 'n', 'd', 't', 's'] as $member){
            if(!is_int($header[$member] ?? null) || $header[$member] < 0){
                return null;
            }
        }
        // Its body's length is an integer, and so is every key: base + slot, or base + a local key of 32 bits
        return $header['slots'] > intdiv(PHP_INT_MAX, self::BLOCK) || $header['base'] > PHP_INT_MAX - $header['slots'] - 0xFFFFFFFF ? null : $header;
    }

    /** The body length a valid header announces */
    private static function bodyLength(array $header): int {
        $dense = $header['layout'] === 'dense';
        $per = $dense ? self::DENSE_SLOTS : self::SPARSE_ENTRIES;
        $slots = $header['slots'];
        $blocks = $slots === 0 ? 0 : intdiv($slots - 1, $per) + 1;
        return $blocks === 0 ? 0 : ($blocks - 1) * self::BLOCK + ($dense ? ($slots - ($blocks - 1) * $per) * 12 + 4 : self::BLOCK);
    }

    /** True when every block of a body is sealed by its crc (one block at a time, no copy of the body) */
    private static function bodyFits(string $body): bool {
        for($at = 0, $length = strlen($body); $at < $length; $at += self::BLOCK){
            if(crc32(substr($body, $at, self::BLOCK)) !== self::CRC_RESIDUE){
                return false;
            }
        }
        return true;
    }

    /**
     * A key index from a decoded checkpoint (fits checked by the caller)
     */
    public static function fromFile(array $header, string $body): array {
        $index = self::emptyIndex();
        foreach(['n', 'd', 't', 's', 'created', 'updated', 'z', 'sid'] as $member){
            if(isset($header[$member])){
                $index[$member] = $header[$member];
            }
        }
        $index['e'] = isset($header['e']) && is_string($header['e']) ? $header['e'] : null;
        $index['kb'] = $body;
        $index['kl'] = $header['layout'];
        $index['kbase'] = $header['base'];
        $index['kslots'] = $header['slots'];
        $index['live'] = $header['live'];
        $index['kmax'] = self::bodyMax($index);
        $index['ck'] = $header['s'];
        return $index;
    }

    /**
     * The highest key of the body that the delta didn't remove (-1: none), from the top down: no array of the
     * body is made (a deleted highest key of a large segment costs a few lookups, not a decode)
     */
    private static function bodyMax(array $index): int {
        if($index['kl'] === 'sparse'){
            for($i = $index['kslots'] - 1; $i >= 0; $i--){
                $block = intdiv($i, self::SPARSE_ENTRIES);
                $key = $index['kbase'] + unpack('V', $index['kb'], $block * self::BLOCK + 4 * ($i - $block * self::SPARSE_ENTRIES))[1];
                if(!isset($index['x'][$key])){
                    return $key;
                }
            }
            return -1;
        }
        for($local = $index['kslots'] - 1; $local >= 0; $local--){
            $key = $index['kbase'] + $local;
            if(!isset($index['x'][$key]) && self::baseGet($index, $key) !== null){
                return $key;
            }
        }
        return -1;
    }

    /**
     * The index after a checkpoint of it was written: the new body, no delta
     */
    public static function rebased(array $index, string $layout, int $slots, string $body, int $max, int $base): array {
        $index['kb'] = $body;
        $index['kl'] = $layout;
        $index['kbase'] = $base;
        $index['kslots'] = $slots;
        $index['kmax'] = $max;
        $index['p'] = [];
        $index['l'] = [];
        $index['x'] = [];
        return $index;
    }
}
