<?php
// A staged database checked with the engine against what was read from the old files (docs/MIGRATION.md §7.5)
namespace noneDB\Migrate\Validator;

use noneDB\Migrate\Readers\Source;
use noneDB\Migrate\Stop;

/**
 * @internal
 */
final class Verifier {
    /** Keys read per find() */
    private const BATCH = 1000;
    /** Values of each indexed field compared with a scan */
    private const SAMPLES = 50;

    /**
     * records(), then totals(); $scan reads without indexes
     * @throws Stop
     */
    public static function verify(\noneDB $db, \noneDB $scan, Source $source): void {
        self::totals($db, $scan, $source, self::records($db, $source));
    }

    /**
     * Every record (===), read in batches of keys: one operation per batch, so the engine reads one segment at a time
     * (and frees memory between operations). On the way, the keys that hold each of up to SAMPLES values of every
     * indexed field: what a scan answers for them, for totals().
     * @throws Stop
     */
    public static function records(\noneDB $db, Source $source): array {
        $samples = [];
        foreach($source->segmentIds() as $id){
            $batch = [];
            foreach($source->lines($id) as $key => $line){
                $batch[$key] = json_decode($line, true);
                if(count($batch) === self::BATCH){
                    self::compare($db, $source->name, $batch);
                    self::sample($batch, $source, $samples);
                    $batch = [];
                }
            }
            self::compare($db, $source->name, $batch);
            self::sample($batch, $source, $samples);
        }
        return $samples;
    }

    /**
     * checkIntegrity(), the count, the next key, the indexed lookups of the sampled values against the keys records()
     * found, every spatial index against a scan of the whole world, and an empty recovery log
     * @throws Stop
     */
    public static function totals(\noneDB $db, \noneDB $scan, Source $source, array $samples): void {
        $name = $source->name;
        $integrity = $db->checkIntegrity($name);
        if(($integrity['ok'] ?? false) !== true){
            throw new Stop('FAILED', "$name: checkIntegrity() reports a problem: " . json_encode($integrity));
        }
        if($db->count($name) !== $source->count){
            throw new Stop('FAILED', "$name: count() is " . $db->count($name) . ", {$source->count} records were migrated");
        }
        $next = ($db->getShardInfo($name) ?: [])['nextKey'] ?? null;
        if($next !== $source->nextKey){
            throw new Stop('FAILED', "$name: the next key is " . var_export($next, true) . ", expected {$source->nextKey}");
        }
        foreach($samples as $field => $byValue){
            foreach($byValue as [$value, $keys]){
                if(self::keys($db->find($name, [$field => $value])) !== $keys){
                    throw new Stop('FAILED', "$name: the index of '$field' answers " . json_encode($value) . ' unlike the records');
                }
            }
        }
        foreach(array_keys($source->spatial) as $field){
            if(self::keys($db->withinBBox($name, $field, -180, -90, 180, 90)) !== self::keys($scan->withinBBox($name, $field, -180, -90, 180, 90))){
                throw new Stop('FAILED', "$name: the spatial index of '$field' answers unlike a scan");
            }
        }
        if($db->getRecoveryLog($name) !== []){
            throw new Stop('FAILED', "$name: the engine logged a recovery event while reading it: " . json_encode($db->getRecoveryLog($name)));
        }
    }

    /** Each expected record (key => record) must be what find() returns for its key */
    private static function compare(\noneDB $db, string $name, array $expected): void {
        if($expected === []){
            return;
        }
        $found = [];
        foreach($db->find($name, ['key' => array_keys($expected)]) as $record){
            $found[$record['key']] = $record;
        }
        foreach($expected as $key => $record){
            if(!array_key_exists($key, $found) || $found[$key] !== $record){
                throw new Stop('FAILED', "$name: record $key differs: expected " . json_encode($record) . ', found '
                    . json_encode($found[$key] ?? null));
            }
        }
        if(count($found) !== count($expected)){
            throw new Stop('FAILED', "$name: find() returns keys that were not migrated");
        }
    }

    /**
     * field => [serialized value => [value, keys holding it (ascending)]]: the first SAMPLES values of each indexed field
     * met, and every key that holds one of them
     */
    private static function sample(array $records, Source $source, array &$samples): void {
        foreach($records as $key => $record){
            foreach($source->fields as $field){
                if(!array_key_exists($field, $record) || (!is_scalar($record[$field]) && $record[$field] !== null)){
                    continue;
                }
                $id = serialize($record[$field]);
                if(isset($samples[$field][$id])){
                    $samples[$field][$id][1][] = $key;
                } elseif(count($samples[$field] ?? []) < self::SAMPLES){
                    $samples[$field][$id] = [$record[$field], [$key]];
                }
            }
        }
    }

    private static function keys(array $records): array {
        $keys = array_column($records, 'key');
        sort($keys);
        return $keys;
    }
}
