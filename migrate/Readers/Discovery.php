<?php
// The files of a directory, grouped by database for one source key (docs/MIGRATION.md §3)
namespace noneDB\Migrate\Readers;

/**
 * @internal
 */
final class Discovery {
    /** Entries of a noneDB 4 store (STORAGE_FORMAT.md §3.4): never sources */
    private const STORE_ENTRY = '/^(STORE|STORE\.2|STORE\.LOCK|STORE(\.2)?\.tmp.*|[0-9a-f]{32}\.ndb4|\.nonedb-maintenance|\.nonedb-retained|\.htaccess|index\.html|\.gitkeep|\.gitignore|\.DS_Store)$/';

    /**
     * The entries of $dir for the source key $key:
     * - databases: name => its files (a data file, a meta, or a write buffer holding records);
     * - leftovers: name => files of the key that belong to no database;
     * - keyFiles: files of the directory that only this key uses (its 3.2+ hash cache);
     * - sharedFiles: the 3.0/3.1 hash cache, which no key owns;
     * - otherKeys: files of the old formats that belong to another key;
     * - foreign: anything else that is neither a source nor part of a store;
     * - store: entries of a noneDB 4 store.
     * @return array<string, array>
     */
    public static function scan(string $dir, string $key): array {
        $out = ['databases' => [], 'leftovers' => [], 'keyFiles' => [], 'sharedFiles' => [], 'otherKeys' => [], 'foreign' => [], 'store' => []];
        $named = [];
        $hashOnly = [];
        $fingerprint = substr(hash_hmac('sha256', 'nonedb-key-fingerprint-v1', $key), 0, 16);
        foreach(scandir($dir) ?: [] as $entry){
            if($entry === '.' || $entry === '..'){
                continue;
            }
            if(preg_match(self::STORE_ENTRY, $entry)){
                $out['store'][] = $entry;
            } elseif(preg_match('/^([0-9a-f]{20})-([^_.]*)(?:_s\d+)?\.nonedb/', $entry, $m)){
                $named[$m[2]][$m[1]][] = $entry;
            } elseif(preg_match('/^([0-9a-f]{20})(?:_s\d+)?\.nonedb\./', $entry, $m)){
                $hashOnly[$m[1]][] = $entry;
            } elseif($entry === '.nonedb_hash_cache'){
                $out['sharedFiles'][] = $entry;
            } elseif(preg_match('/^\.nonedb_hash_cache_([0-9a-f]{16})$/', $entry, $m)){
                $out[$m[1] === $fingerprint ? 'keyFiles' : 'otherKeys'][] = $entry;
            } else {
                $out['foreign'][] = $entry;
            }
        }
        $ownHashes = [];
        foreach($named as $name => $byHash){
            $hash = self::hashOf((string)$name, $key);
            foreach($byHash as $h => $files){
                if($h === $hash){
                    $ownHashes[$h] = (string)$name;
                    $out['databases'][(string)$name] = $files;
                } else {
                    array_push($out['otherKeys'], ...$files);
                }
            }
        }
        foreach($hashOnly as $h => $files){
            if(isset($ownHashes[$h])){
                array_push($out['databases'][$ownHashes[$h]], ...$files);
            } else {
                array_push($out['otherKeys'], ...$files);
            }
        }
        foreach($out['databases'] as $name => $files){
            sort($files);
            if(!self::isDatabase($dir, (string)$name, self::hashOf((string)$name, $key), $files)){
                unset($out['databases'][$name]);
                $out['leftovers'][$name] = $files;
            } else {
                $out['databases'][$name] = $files;
            }
        }
        ksort($out['databases'], SORT_STRING);
        ksort($out['leftovers'], SORT_STRING);
        foreach(['keyFiles', 'sharedFiles', 'otherKeys', 'foreign', 'store'] as $list){
            sort($out[$list]);
        }
        return $out;
    }

    /** The name hash of every release: PBKDF2-SHA256, the name as password, the key as salt, 20 hex digits */
    public static function hashOf(string $name, string $key): string {
        return hash_pbkdf2('sha256', $name, $key, 1000, 20);
    }

    /**
     * A database has a data file (its own or a shard's), a meta, or a write buffer holding a record
     */
    private static function isDatabase(string $dir, string $name, string $hash, array $files): bool {
        $base = $hash . '-' . $name;
        foreach($files as $file){
            if($file === "$base.nonedb" || $file === "$base.nonedb.meta" || preg_match('/^' . preg_quote($base, '/') . '_s\d+\.nonedb$/', $file)){
                return true;
            }
            if(preg_match('/\.nonedb\.buffer(\.flushing(\.\d+)?)?$/', $file) && trim((string)@file_get_contents($dir . '/' . $file)) !== ''){
                return true;
            }
        }
        return false;
    }
}
