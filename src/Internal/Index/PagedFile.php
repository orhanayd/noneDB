<?php
// Paged field index files .fx: a static B+tree of entries sorted by bucket id (docs/STORAGE_FORMAT.md §6.5.1)
namespace noneDB\Internal\Index;

use function array_pop, array_values, count, crc32, intdiv, is_array, is_int, is_string, json_decode,
    json_encode, ord, pack, strcmp, strcspn, strlen, strpos, strrpos, substr, unpack;

use const JSON_UNESCAPED_LINE_TERMINATORS, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE;

/**
 * @internal
 */
final class PagedFile {
    /** Target size of a page, crc included */
    public const PAGE = 4096;

    /** Flags of the canonical JSON of .fx data pages (§6.5.1): only `"`, `\\` and control characters escaped */
    public const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS;

    /** A directory page: its entries, their table (u32 start of each, then the u16 count), the crc of all that */
    private static function seal(array $entries): string {
        $data = '';
        $starts = [];
        foreach($entries as $entry){
            $starts[] = strlen($data);
            $data .= $entry;
        }
        $data .= (empty($starts) ? '' : pack('V*', ...$starts)) . pack('v', count($starts));
        return $data . pack('V', crc32($data));
    }

    /** True when $entry still fits a directory page of $count entries and $bytes bytes (table and crc included) */
    private static function fits(int $bytes, int $count, string $entry): bool {
        return $bytes + strlen($entry) + 4 * ($count + 1) + 2 + 4 <= self::PAGE;
    }

    /**
     * Where the members of a data page starting at $at end in the encoded members $json: after the last one that
     * fits (braces and crc included), or after the first when it alone doesn't. A member ends before a `,"` that no
     * `:` follows: inside an id a quote is escaped, so `,":` is the end of an id that ends in a comma, and every id
     * starts with a type letter.
     */
    private static function pageEnd(string $json, int $at): int {
        $n = strlen($json);
        if($n - $at <= self::PAGE - 6){
            return $n;
        }
        for($end = strrpos($json, ',"', $at + self::PAGE - 6 - $n); $end !== false && $end > $at; $end = strrpos($json, ',"', $end - 1 - $n)){
            if($json[$end + 2] !== ':'){
                return $end;
            }
        }
        for($end = strpos($json, ',"', $at + 1); $end !== false; $end = strpos($json, ',"', $end + 1)){
            if($json[$end + 2] !== ':'){
                return $end;
            }
        }
        return $n;
    }

    /** The id of the member starting at $at in the encoded members $json: up to the first quote no backslash escapes */
    private static function idAt(string $json, int $at): string {
        $close = $at + 1;
        while($json[$close += strcspn($json, '"\\', $close)] === '\\'){
            $close += 2;
        }
        return json_decode(substr($json, $at, $close + 1 - $at));
    }

    /**
     * Build the body from a segment's buckets in bucket id order (id => key | [keys], ascending), sealed as data
     * pages, then the directory levels: [body, pg]. A member is `"id":key` for a bucket of one key, else `"id":[keys]`;
     * all of them are encoded at once and cut into pages, members not split, each page as full as it gets.
     */
    public static function build(array $buckets): array {
        $json = substr((string)json_encode($buckets, self::JSON), 1, -1);
        $body = '';
        $level = [];
        for($at = 0, $n = strlen($json); $at < $n; $at = $end + 1){
            $end = self::pageEnd($json, $at);
            $page = '{' . substr($json, $at, $end - $at) . '}';
            $page .= pack('V', crc32($page));
            $level[] = [self::idAt($json, $at), strlen($body), strlen($page)];
            $body .= $page;
        }
        if(empty($level)){
            $body = '{}' . pack('V', crc32('{}'));
            $level[] = ['', 0, strlen($body)];
        }
        $data = strlen($body);
        $pages = count($level);
        $levels = 0;
        while(count($level) > 1){
            $levels++;
            $next = [];
            $current = [];
            $bytes = 0;
            $first = '';
            foreach($level as [$id, $offset, $length]){
                $entry = pack('V', strlen($id)) . $id . pack('V2', $offset, $length);
                // At least two entries per directory page (long ids): each level has fewer pages
                if(count($current) >= 2 && !self::fits($bytes, count($current), $entry)){
                    $page = self::seal($current);
                    $next[] = [$first, strlen($body), strlen($page)];
                    $body .= $page;
                    $current = [];
                    $bytes = 0;
                }
                if(empty($current)){
                    $first = $id;
                }
                $current[] = $entry;
                $bytes += strlen($entry);
            }
            $page = self::seal($current);
            $next[] = [$first, strlen($body), strlen($page)];
            $body .= $page;
            $pages += count($next);
            $level = $next;
        }
        return [$body, ['root' => [$level[0][1], $level[0][2]], 'levels' => $levels, 'pages' => $pages, 'data' => $data]];
    }

    /** True when a header's `pg` can name a tree: a root of some bytes, no more levels than two entries a page allow */
    public static function validTree($pg): bool {
        return is_array($pg) && is_int($pg['root'][0] ?? null) && is_int($pg['root'][1] ?? null) && $pg['root'][1] > 0
            && is_int($pg['levels'] ?? null) && $pg['levels'] >= 0 && $pg['levels'] < 64;
    }

    /** A page without its crc (entries and table), or null when the crc doesn't match */
    public static function open($bytes): ?string {
        if(!is_string($bytes) || strlen($bytes) < 6){
            return null;
        }
        return crc32($bytes) === \noneDB\Internal\Storage\KeyIndex::CRC_RESIDUE ? substr($bytes, 0, -4) : null;
    }

    /** Where a page's table starts (= the bytes of its entries), or null when the count doesn't fit the page */
    private static function tableAt(string $page): ?int {
        $len = strlen($page);
        $at = $len - 2 - 4 * (ord($page[$len - 2]) | (ord($page[$len - 1]) << 8));
        return $at < 0 ? null : $at;
    }

    /** The child of a decoded directory page that can hold $id (the last whose first id is <= $id): [offset, length]; [] when none */
    public static function child(array $children, string $id): array {
        $lo = 0;
        $hi = count($children) - 1;
        $found = -1;
        while($lo <= $hi){
            $mid = ($lo + $hi) >> 1;
            if(strcmp($children[$mid][0], $id) <= 0){
                $found = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        return $found < 0 ? [] : [$children[$found][1], $children[$found][2]];
    }

    /** True when a page's table names exactly the starts of the entries a walk found ($starts) */
    private static function checkedStarts(string $page, array $starts, int $table): bool {
        if(count($starts) !== intdiv(strlen($page) - 2 - $table, 4)){
            return false;
        }
        return $starts === [] || array_values(unpack('V' . count($starts), $page, $table)) === $starts;
    }

    /** A directory page: [[first id, offset, length], ...]; null when its table doesn't name its entries */
    public static function directory(string $page): ?array {
        $table = self::tableAt($page);
        if($table === null){
            return null;
        }
        $children = [];
        $starts = [];
        for($o = 0; $o < $table;){
            $starts[] = $o;
            if($o + 12 > $table || $o + 12 + ($n = unpack('V', $page, $o)[1]) > $table){
                return null; // an entry that leaves the page: its crc fits, its content doesn't
            }
            $child = unpack('V2', $page, $o + 4 + $n);
            $children[] = [substr($page, $o + 4, $n), $child[1], $child[2]];
            $o += 12 + $n;
        }
        return self::checkedStarts($page, $starts, $table) ? $children : null;
    }

    /**
     * The keys of the member named $id in an .fx data page (its JSON object, without the crc), ascending;
     * [] when the page doesn't hold it; null when its value isn't a key or a list of keys. A byte search in
     * canonical JSON (§6.5.1 lookup step 3), for the name without its opening quote: a type letter stops a
     * byte search less often than a quote, which every name starts and ends with. A match preceded by `{"` or
     * `,"` is a member's name (inside a name a quote is escaped, a value holds none), and it names exactly $id.
     */
    public static function fxMember(string $page, string $id, int $start = 0, ?int $end = null): ?array {
        // The name as the page writes it (the last one kept: a query looks one value up in segment after segment)
        static $lastId = null, $lastTail = null;
        if($id !== $lastId){
            $name = json_encode($id, self::JSON);
            if($name === false){
                return []; // not a name any member can have
            }
            $lastId = $id;
            $lastTail = substr($name, 1) . ':';
        }
        $tail = $lastTail;
        // $start / $end: the page's bytes inside a whole body, copied: a search never runs past the page
        if($start > 0 || ($end !== null && $end < strlen($page))){
            $page = substr($page, $start, ($end ?? strlen($page)) - $start);
        }
        for($at = strpos($page, $tail, 2); $at !== false; $at = strpos($page, $tail, $at + 1)){
            if($page[$at - 1] === '"' && ($page[$at - 2] === ',' || $page[$at - 2] === '{')){
                return self::fxKeysAt($page, $at + strlen($tail));
            }
        }
        return [];
    }

    /** The keys of the .fx member whose value starts at $at; null when it isn't a key or a list of keys */
    private static function fxKeysAt(string $page, int $at): ?array {
        if(($page[$at] ?? '') === '['){
            $close = strpos($page, ']', $at);
            $length = $close === false ? 0 : $close + 1 - $at;
        } else {
            $length = strcspn($page, ',}', $at);
        }
        $keys = $length > 0 ? json_decode(substr($page, $at, $length), true) : null;
        if(is_int($keys)){
            return [$keys];
        }
        return is_array($keys) ? $keys : null;
    }

    /**
     * Add the members of an .fx data page (its JSON object, without the crc) to $map in the shape of a
     * loaded index: id => key (one key) or [keys] (ascending). False when the page isn't a JSON object in
     * canonical form (encoding it again doesn't give its bytes): its byte searches couldn't be trusted.
     */
    public static function fxMembersInto(string $page, array &$map): bool {
        if($page === '{}'){
            return true;
        }
        $members = json_decode($page, true);
        if(!is_array($members) || json_encode($members, self::JSON) !== $page){
            return false;
        }
        $map += $members;
        return true;
    }

    /**
     * Every data page of a body, in order, from its directory: [[offset, length], ...]; null when a page's
     * crc or table doesn't match or the pages don't cover the data bytes
     */
    public static function dataPages(string $body, array $pg): ?array {
        if(!self::validTree($pg)){
            return null;
        }
        // Depth first, children in order, with a stack (a recursive closure would hold the body in a cycle
        // until the garbage collector runs)
        $pages = [];
        $stack = [[$pg['root'][0], $pg['root'][1], $pg['levels']]];
        while(!empty($stack)){
            [$offset, $length, $level] = array_pop($stack);
            if($level === 0){
                $pages[] = [$offset, $length];
                continue;
            }
            $data = self::open(substr($body, $offset, $length));
            $children = $data === null ? null : self::directory($data);
            if($children === null){
                return null;
            }
            for($i = count($children) - 1; $i >= 0; $i--){
                $stack[] = [$children[$i][1], $children[$i][2], $level - 1];
            }
        }
        $expected = 0;
        foreach($pages as [$offset, $length]){
            if($offset !== $expected){
                return null;
            }
            $expected += $length;
        }
        return $expected === ($pg['data'] ?? -1) ? $pages : null;
    }
}
