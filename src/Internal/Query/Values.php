<?php
// Value semantics of queries: typed identity (valueKey), the total sort order (sortKey), comparison of
// ordering operators, numbers of values, like() patterns and Unicode case folding
namespace noneDB\Internal\Query;

use function bin2hex, count, function_exists, is_array, is_bool, is_float, is_int, is_nan, is_numeric,
    is_object, is_string, json_encode, mb_convert_case, pack, preg_last_error, preg_last_error_msg, preg_match,
    preg_quote, spl_object_id, str_replace, strcmp, strlen, strncmp, strpos, substr;
use const MB_CASE_FOLD, PREG_BAD_UTF8_ERROR, PREG_BAD_UTF8_OFFSET_ERROR, PREG_NO_ERROR;

/**
 * @internal
 */
final class Values {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;


    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
    }

    /**
     * Drop the handles to the kernel and the other services (the instance is gone)
     */
    public function unbind(): void {
        $this->k = null;
        $this->rt = null;
    }


    /**
     * valueKey() for the query builder
     * @return \Closure
     */
    public function valueKeyFunction(){
        return \Closure::fromCallable([self::class, 'valueKey']);
    }

    /**
     * Add the records' values of a field to the distinct values seen so far (null excluded), in first-seen
     * order, same identity as in_array($value, $values, true), in linear time ($seen: a hash set of their
     * valueKey()s): distinct() batch by batch, the records never held together
     */
    public static function collectUnique(array $records, $field, array &$seen, array &$values){
        foreach($records as $record){
            if(isset($record[$field])){
                $id = self::valueKey($record[$field]);
                if(!isset($seen[$id])){
                    $seen[$id] = true;
                    $values[] = $record[$field];
                }
            }
        }
    }

    /**
     * A string that is equal for two values exactly when they are identical (===): typed, -0.0 is 0.0,
     * arrays by their typed keys and values in order (lengths make the encoding unambiguous).
     * Floats by their bits, so neither precision nor locale settings matter.
     */
    public static function valueKey($v){
        if($v === null) return 'n';
        if($v === true) return 't';
        if($v === false) return 'f';
        if(is_int($v)) return 'i' . $v;
        if(is_float($v)) return 'd' . bin2hex(pack('E', $v + 0.0));
        if(is_string($v)) return 's' . $v;
        if(is_array($v)){
            $key = 'a' . count($v) . ':';
            foreach($v as $k => $item){
                $kk = (is_int($k) ? 'i' : 's') . $k;
                $id = self::valueKey($item);
                $key .= strlen($kk) . ':' . $kk . strlen($id) . ':' . $id;
            }
            return $key;
        }
        return is_object($v) ? 'o' . spl_object_id($v) : 'r' . (int)$v; // identical only to itself
    }

    /**
     * Total order used by sort(), min() and max() (and the query builder): missing/null < booleans <
     * numbers < numeric strings (by their number) < other strings (byte order) < arrays.
     * @return int
     */
    public static function compareSortValues($a, $b){
        return self::sortKey($a) <=> self::sortKey($b);
    }

    /**
     * Sort key of a value: [kind, comparable value]. Two keys compare with <=> in the order above
     * (kind first, then the value; arrays by their JSON), so sorts compute each key once instead of
     * classifying both values in every comparison.
     * @return array
     */
    public static function sortKey($v){
        if($v === null) return [0, 0];
        if(is_bool($v)) return [1, $v];
        if(is_int($v) || is_float($v)) return [2, $v];
        if(is_string($v)){
            // Numeric strings (is_numeric()) never meet other strings, so <=> never mixes numeric and byte
            // comparison (mixed, "9" < "10" < "1a" < "9" would be a cycle).
            return is_numeric($v) ? [3, $v + 0] : [4, $v];
        }
        return [5, (string)json_encode($v)];
    }

    /**
     * sortKey() for the query builder
     * @return \Closure
     */
    public function sortKeyFunction(){
        return \Closure::fromCallable([self::class, 'sortKey']);
    }
    /**
     * True when mbstring can be used for Unicode case folding (like(), search())
     * @internal used by noneDBQuery; not part of the public API
     */
    public static function hasMbstring(): bool {
        return function_exists('mb_convert_case'); // @prod
        return \noneDB\Internal\Cache\Runtime::get()->mbstringFn !== null ? (bool)(\noneDB\Internal\Cache\Runtime::get()->mbstringFn)() : function_exists('mb_convert_case'); // @test
    }
    /**
     * Compare two values for ordering operators ($gt, between, ...).
     * Numbers (and numeric strings) compare numerically, other strings lexically; anything else
     * (null, booleans, arrays, number vs. non-numeric string) is not comparable.
     * @return int|null
     */
    public static function compareValues($a, $b): ?int {
        if ($a === null || $b === null || is_bool($a) || is_bool($b) || is_array($a) || is_array($b) || is_object($a) || is_object($b)) {
            return null;
        }
        // NAN compares as greater than everything with <=> ($gt NAN matched every number)
        if ((is_float($a) && is_nan($a)) || (is_float($b) && is_nan($b))) {
            return null;
        }
        $numA = self::numberOf($a);
        $numB = self::numberOf($b);
        if ($numA !== null && $numB !== null) {
            return $numA <=> $numB;
        }
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }
        return null;
    }
    /**
     * The number of an int, float or numeric string (is_numeric(): leading and trailing whitespace
     * allowed), else null. The sort order and range indexes use the same rule.
     * @return int|float|null
     */
    public static function numberOf($value) {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return is_numeric($value) ? $value + 0 : null;
        }
        return null;
    }
    /**
     * Run preg_match, turning compile errors and backtrack-limit failures into exceptions
     */
    public static function regexMatch(string $regex, string $subject): bool {
        $result = @preg_match($regex, $subject);
        if ($result === false) {
            $error = preg_last_error();
            if ($error === PREG_BAD_UTF8_ERROR || $error === PREG_BAD_UTF8_OFFSET_ERROR) {
                return false; // subject isn't UTF-8: can't match a UTF-8 pattern
            }
            if ($error === PREG_NO_ERROR) {
                throw new \InvalidArgumentException('noneDB: invalid regular expression');
            }
            throw new \RuntimeException('noneDB: regular expression failed (' . preg_last_error_msg() . ')');
        }
        return $result === 1;
    }
    /**
     * Case folding for matching (Unicode full folding: "ß" and "ẞ" are "ss", "ſ" is "s", "ς" is "σ").
     * "İ" folds to "i" + combining dot (U+0307); the dot is dropped so "İzmir", "IZMIR" and "izmir" all
     * match each other. Language independent: "I" and "ı" don't match.
     */
    public static function foldCase(string $s): string {
        return str_replace("\u{0307}", '', mb_convert_case($s, MB_CASE_FOLD, 'UTF-8'));
    }
    /**
     * Compile a like() pattern: a leading "^" anchors the start, a trailing "$" the end, everything else
     * is literal and case-insensitive.
     * @return array
     */
    public static function compileLike(string $pattern): array {
        $start = $pattern !== '' && $pattern[0] === '^';
        $end = strlen($pattern) > ($start ? 1 : 0) && substr($pattern, -1) === '$';
        $body = (string)substr($pattern, $start ? 1 : 0, strlen($pattern) - ($start ? 1 : 0) - ($end ? 1 : 0));
        $utf8 = preg_match('//u', $body) === 1;
        return [
            'start' => $start, 'end' => $end,
            // Folded body for UTF-8 subjects (null: no mbstring, or the pattern isn't UTF-8)
            'folded' => $utf8 && self::hasMbstring() ? self::foldCase($body) : null,
            // Otherwise a regex (PCRE /iu: simple case folding)
            // ("D": the end anchor is the end of the value, not also before a final newline)
            'regex' => '~' . ($start ? '^' : '') . preg_quote($body, '~') . ($end ? '$' : '') . '~D' . ($utf8 ? 'iu' : 'i'),
        ];
    }
    /**
     * Match a compiled like() pattern
     */
    public static function likeMatch(array $like, string $subject): bool {
        if ($like['folded'] === null || preg_match('//u', $subject) !== 1) {
            return self::regexMatch($like['regex'], $subject);
        }
        $t = self::foldCase($subject);
        $b = $like['folded'];
        if ($like['start'] && $like['end']) {
            return $t === $b;
        }
        if ($like['start']) {
            return strncmp($t, $b, strlen($b)) === 0;
        }
        if ($like['end']) {
            return $b === '' || (strlen($t) >= strlen($b) && substr($t, -strlen($b)) === $b);
        }
        return $b === '' || strpos($t, $b) !== false;
    }
}
