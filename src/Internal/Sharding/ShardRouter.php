<?php
// Writes to the shards of a sharded database and new shards
namespace noneDB\Internal\Sharding;

use function array_column, array_diff_key, array_flip, array_keys, array_map, basename, count, date, intdiv,
    max, min, preg_match, strlen, substr;

/**
 * @internal
 */
final class ShardRouter {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $faultHook; // @test
    private $fieldIndexCache;
    private $indexCache;
    private $shardFilesUpTo;
    private $spatialIndexCache;
    private $cache;
    private $checkpoints;
    private $io;
    private $layout;
    private $locks;
    private $records;
    private $recovery;
    private $segments;
    private $shardMeta;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->io, $this->layout, $this->locks, $this->records, $this->recovery, $this->segments, $this->shardMeta);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->faultHook = &$this->rt->faultHook; // @test
        $this->fieldIndexCache = &$this->k->fieldIndexCache;
        $this->indexCache = &$this->k->indexCache;
        $this->shardFilesUpTo = &$this->k->shardFilesUpTo;
        $this->spatialIndexCache = &$this->k->spatialIndexCache;
    }

    /**
     * A handle to another service, bound on first use
     */
    public function __get(string $name) {
        return $this->$name = $this->k->$name;
    }

    /**
     * Drop the handles to the kernel and the other services (the instance is gone)
     */
    public function unbind(): void {
        $this->cache = null;
        $this->checkpoints = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->records = null;
        $this->recovery = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Insert into a sharded database. Keys come from meta.nextKey; key K lives in shard intdiv(K, shardSize).
     */
    public function insertShardedRecords($dbname, array $items, $keyed = null, $endKey = null){
        $meta = $this->shardMeta->getCachedMeta($dbname, true);
        if($meta === null){
            throw new \RuntimeException("noneDB: metadata of sharded database '{$dbname}' is unreadable");
        }
        $size = (int)$meta['shardSize'];
        $nextKey = (int)$meta['nextKey'];
        // The meta may lag behind the data (a meta update lost in a power loss): never hand out a key the
        // last shard has already used
        if(!empty($meta['shards'])){
            $last = max(array_map('intval', array_column($meta['shards'], 'id')));
            $lastIndex = $this->checkpoints->readJsonlIndex($dbname, $last);
            if($lastIndex !== null){
                $nextKey = max($nextKey, $last * $size + (int)$lastIndex['n']);
            }
        }

        $byShard = [];
        if($keyed !== null){
            foreach($keyed as $key => $item){
                $byShard[intdiv($key, $size)][$key] = $item;
            }
            $nextKey = max($nextKey, (int)$endKey);
            $items = $keyed;
        } else {
            foreach($items as $item){
                $byShard[intdiv($nextKey, $size)][$nextKey] = $item;
                $nextKey++;
            }
        }

        // Reserve the keys first: a crash later leaves a gap, never a reused key
        $shardPos = [];
        foreach($meta['shards'] as $i => $shard){
            $shardPos[(int)$shard['id']] = $i;
        }
        $this->setOrphanShardsAside($dbname, array_keys(array_diff_key($byShard, $shardPos)));
        foreach($byShard as $shardId => $records){
            if(!isset($shardPos[$shardId])){
                $meta['shards'][] = \noneDB\Internal\Sharding\ShardMeta::newShardEntry($shardId);
                $shardPos[$shardId] = count($meta['shards']) - 1;
            }
            $meta['shards'][$shardPos[$shardId]]['count'] += count($records);
        }
        // Journal first: if we die after reserving, recovery recounts the meta from the shards
        foreach(array_keys($byShard) as $shardId){
            $this->locks->markIndexesPending($dbname, $shardId);
        }
        $meta['nextKey'] = $nextKey;
        $meta['totalRecords'] = (int)$meta['totalRecords'] + count($items);
        $this->shardMeta->writeMeta($dbname, $meta);

        // A failure below is undone by the rollback scope (appends of every shard cut back, counters
        // recounted); the reserved keys stay unused and the new shards stay listed (empty)
        foreach($byShard as $shardId => $records){
            $index = $this->checkpoints->writableSegmentIndex($dbname, $shardId);
            if($index === null){
                $this->records->createJsonlDatabase($dbname, $shardId);
                if($this->faultHook !== null){ $this->recovery->fault('shard:created', ['db' => $dbname, 'shard' => $shardId]); } // @test
                $index = $this->checkpoints->writableSegmentIndex($dbname, $shardId);
            }
            $this->records->appendSegmentRecords($dbname, $shardId, $index, $records);
            $index['n'] = max((int)$index['n'], max(array_keys($records)) - $shardId * $size + 1);
            $this->checkpoints->storeSegmentIndex($dbname, $shardId, $index);
        }
    }

    /**
     * Files of a shard that shards.json doesn't list (left by a shards.json update lost in a power loss, or
     * put there by hand) must not be taken over by a new shard with the same number: their records were
     * deleted, or belong to keys the meta doesn't know any more. They are renamed to *.orphan.{time}
     * (reported by getDB()), never deleted.
     */
    private function setOrphanShardsAside($dbname, array $shardIds){
        if(empty($shardIds)){
            return;
        }
        // The directory is listed only for shards up to the highest one that had files at the last listing:
        // above it only this process created shard files since (another process's write drops the number,
        // invalidateDbCaches). A listing per new shard made inserts into many shards O(shards²)
        // Only data and checkpoint files: field and spatial indexes check themselves against the data
        // (segment epoch, covered bytes), so one left by an earlier shard of that number is never used
        $base = $this->layout->genDir($dbname) . 'segments/s';
        $upTo = max(array_map('intval', $shardIds));
        if(isset($this->shardFilesUpTo[$base]) && min(array_map('intval', $shardIds)) > $this->shardFilesUpTo[$base]){
            $this->shardFilesUpTo[$base] = $upTo;
            return;
        }
        $wanted = array_flip(array_map('intval', $shardIds));
        $files = [];
        foreach($this->segments->listFilesWithPrefix($base, '/^\d+\./') as $file){
            $shardId = (int)substr($file, strlen($base));
            $upTo = max($upTo, $shardId);
            if(isset($wanted[$shardId])){
                $files[] = $file;
            }
        }
        $stamp = '.orphan.' . date('YmdHis');
        $moved = false;
        foreach($files as $file){
            if(preg_match('/\.orphan\.\d+$/', $file)){
                continue;
            }
            if(!$moved){
                $this->locks->bumpEpoch($dbname); // a shard file of this name goes away: no cache of it is kept
                $moved = true;
            }
            if(!$this->io->fsRename($file, $file . $stamp)){
                throw new \RuntimeException("noneDB: could not move aside the leftover file " . basename($file) . " of database '{$dbname}'");
            }
            unset($this->indexCache[$file], $this->fieldIndexCache[$file], $this->spatialIndexCache[$file]);
            $this->cache->markFileNotExists($file);
        }
        $this->shardFilesUpTo[$base] = $upTo; // after bumpEpoch(), which drops it
    }
}
