<?php
// A database read by Readers\Source, written as a noneDB 4 database (STORAGE_FORMAT.md §4-§6)
namespace noneDB\Migrate\Converter;

use noneDB\Internal\Storage\KeyIndex;
use noneDB\Internal\Storage\StoreRecord;
use noneDB\Migrate\Files;
use noneDB\Migrate\Readers\Source;
use noneDB\Migrate\Stop;

/**
 * @internal
 */
final class Writer {
    /**
     * Write $source into the store directory $storeDir under $key: the state record, generation 1 (segments with
     * their key-index checkpoints, shards.json for the sharded layout, metadata), CURRENT, and FORMAT last
     * (STORAGE_FORMAT.md §8.1). The segments hold the live records in key order; the segment of the highest key ever
     * used ends with its tombstone when that key is not live (§6.2). The checkpoints are written as compaction writes
     * them (§6.2 step 4, §6.4), so the engine never replays a whole migrated segment. Everything is synced. Returns the
     * database's directory.
     */
    public static function write(string $storeDir, string $key, Source $source): string {
        $home = rtrim($storeDir, '/') . '/' . StoreRecord::locatorOf($source->name, $key) . '.ndb4/';
        $generation = $home . 'generations/1/';
        Files::makeDir($generation . 'segments');
        Files::writeNew($home . 'LOCK', str_pad('1:' . bin2hex(random_bytes(4)), 128));
        $sharded = $source->layout === 'sharded';
        $last = $source->nextKey - 1;
        $lastSegment = $last < 0 ? null : ($sharded ? intdiv($last, $source->shardSize) : 'main');
        $ids = $source->segmentIds();
        if(!$sharded && !in_array('main', $ids, true)){
            $ids[] = 'main'; // a single-layout generation always has its main segment
        }
        if($lastSegment !== null && !in_array($lastSegment, $ids, true)){
            $ids[] = $lastSegment;
            sort($ids);
        }
        $shards = [];
        foreach($ids as $id){
            $path = $generation . 'segments/' . ($id === 'main' ? 'main' : 's' . $id) . '.jsonl';
            $fp = @fopen($path, 'xb');
            if($fp === false){
                throw new Stop('FAILED', "cannot create $path");
            }
            @chmod($path, Files::FILE_MODE);
            $index = ['kb' => '', 'p' => [], 'l' => []]; // key => offset, key => length of its line
            $offset = 0;
            $tail = '';
            $max = -1;
            $chunk = '';
            foreach($source->lines($id) as $k => $line){
                $index['p'][$k] = $offset;
                $index['l'][$k] = strlen($line);
                $offset += strlen($line) + 1;
                $chunk .= $line . "\n";
                $max = $k;
                if(strlen($chunk) >= 1048576){
                    self::put($fp, $chunk, $path);
                    $tail = substr($chunk, -256);
                    $chunk = '';
                }
            }
            $tombstones = 0;
            if($id === $lastSegment && $max < $last){
                $chunk .= '{"__nonedb_deleted":' . $last . "}\n";
                $offset += strlen('{"__nonedb_deleted":' . $last . "}\n");
                $max = $last;
                $tombstones = 1;
            }
            self::put($fp, $chunk, $path);
            if(!fflush($fp) || !Files::syncHandle($fp)){
                fclose($fp);
                throw new Stop('FAILED', "cannot sync $path");
            }
            fclose($fp);
            $tail = substr($tail . $chunk, -256);
            $base = $id === 'main' ? 0 : $id * $source->shardSize;
            [$layout, $slots, $body, , $live] = KeyIndex::encodeBody($index, $base);
            $header = ['kind' => 'kix', 'v' => 1, 'layout' => $layout, 'base' => $base, 'slots' => $slots, 'live' => $live,
                'n' => $max < $base ? 0 : $max - $base + 1, 'd' => 0, 't' => $tombstones, 's' => $offset,
                'h' => sprintf('%08x', crc32($tail)), 'e' => bin2hex(random_bytes(4)), 'created' => time(), 'updated' => time()];
            if($id !== 'main'){
                $header['z'] = $source->shardSize;
                $header['sid'] = $id;
            }
            Files::writeNew(substr($path, 0, -strlen('.jsonl')) . '.kix', KeyIndex::encodeFile($header, $body));
            if($sharded){
                $shards[] = ['id' => $id, 'count' => count($index['p']), 'deleted' => 0];
            }
        }
        Files::syncDir($generation . 'segments');
        $identity = ['storageFormat' => \noneDB::STORAGE_FORMAT, 'databaseId' => StoreRecord::uuid4(), 'name' => $source->name,
            'shardSize' => $source->shardSize, 'createdAt' => time(), 'formatOptions' => new \stdClass()];
        if($sharded){
            Files::writeNew($generation . 'shards.json', json_encode(['kind' => 'shards', 'v' => 1, 'shardSize' => $source->shardSize,
                'nextKey' => $source->nextKey, 'totalRecords' => array_sum(array_column($shards, 'count')), 'deletedCount' => 0,
                'shards' => $shards], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
        Files::writeNew($generation . 'metadata', StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'generation'] + $identity
            + ['generation' => 1, 'layout' => $source->layout]));
        Files::syncDir($generation);
        Files::syncDir($home . 'generations');
        Files::writeNew($home . 'CURRENT', StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'current',
            'databaseId' => $identity['databaseId'], 'generation' => 1]));
        Files::writeNew($home . 'FORMAT', StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'database'] + $identity));
        Files::syncDir($home);
        Files::syncDir($storeDir);
        return $home;
    }

    private static function put($fp, string $bytes, string $path): void {
        if($bytes !== '' && fwrite($fp, $bytes) !== strlen($bytes)){
            fclose($fp);
            throw new Stop('FAILED', "cannot write $path");
        }
    }
}
