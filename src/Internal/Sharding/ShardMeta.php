<?php
// shards.json: the shard list and counters (docs/STORAGE_FORMAT.md §5.1)
namespace noneDB\Internal\Sharding;

use function clearstatcache, count, fclose, fgets, fopen, is_array, is_file, is_int, max, sort, strlen, substr;

/**
 * @internal
 */
final class ShardMeta {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $metaCache;
    private $shardedCache;
    private $cacheGrew;
    private $cacheTick;
    private $cacheTouch;
    private $noWrites;
    private $cache;
    private $checkpoints;
    private $io;
    private $layout;
    private $locks;
    private $recoveryLog;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->io, $this->layout, $this->locks, $this->recoveryLog, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->metaCache = &$this->k->metaCache;
        $this->shardedCache = &$this->k->shardedCache;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->cacheTick = &$this->rt->cacheTick;
        $this->cacheTouch = &$this->rt->cacheTouch;
        $this->noWrites = &$this->rt->noWrites;
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
        $this->recoveryLog = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Shard list and counters of a sharded database
     */
    public function getMetaPath($dbname){
        return $this->layout->genPath($dbname, 'shards.json');
    }

    /**
     * Read shard metadata
     * @param string $dbname
     * @return array|null
     */
    public function readMeta($dbname){
        $meta = $this->io->atomicRead($this->getMetaPath($dbname), null);
        return is_array($meta) && ($meta['kind'] ?? null) === 'shards' && ($meta['v'] ?? null) === 1
            && is_int($meta['shardSize'] ?? null) && is_int($meta['nextKey'] ?? null)
            && isset($meta['shards']) && is_array($meta['shards']) ? $meta : null;
    }

    /**
     * Get cached meta data. The cache is dropped whenever another process writes the
     * database (generation check in withLock), so no TTL is needed.
     * @param string $dbname
     * @param bool $forceRefresh Force refresh from disk
     * @return array|null
     */
    public function getCachedMeta($dbname, $forceRefresh = false){
        $metaPath = $this->getMetaPath($dbname);

        $this->cacheTouch[$metaPath] = ++$this->cacheTick;
        if(!$forceRefresh && isset($this->metaCache[$metaPath])){
            return $this->metaCache[$metaPath];
        }

        $meta = $this->readMeta($dbname);
        if($meta === null){
            clearstatcache(true, $metaPath);
            if(is_file($metaPath)){
                // There but unreadable (e.g. zero-length after a power loss): rebuilt from the shards.
                // checkIntegrity() writes nothing: it works with a meta derived in memory, not kept
                if($this->noWrites > 0){
                    try {
                        return $this->deriveShardMeta($dbname);
                    } catch(\RuntimeException $e){
                        return null;
                    }
                }
                $meta = $this->recoverMeta($dbname);
            }
        }
        if($meta !== null){
            $this->metaCache[$metaPath] = $meta;
            $this->cacheGrew = true;
            return $meta;
        }
        unset($this->metaCache[$metaPath]);
        return null;
    }

    /**
     * Rebuild an unreadable shards.json from the shard files (exclusive lock): the shard size is FORMAT's,
     * nextKey follows the highest key ever used. A missing shards.json is never "recovered": shard files
     * without one are leftovers (see setOrphanShardsAside).
     * @return array|null
     */
    private function recoverMeta($dbname){
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            $metaPath = $this->getMetaPath($dbname);
            unset($this->metaCache[$metaPath]);
            $meta = $this->readMeta($dbname);
            clearstatcache(true, $metaPath);
            if($meta !== null || !is_file($metaPath)){
                return $meta;
            }
            $meta = $this->deriveShardMeta($dbname);
            $this->writeMeta($dbname, $meta, true);
            $this->recountMeta($dbname);
            $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'meta-rebuilt', 'shards' => count($meta['shards']), 'shardSize' => $meta['shardSize']]);
            return $this->getCachedMeta($dbname);
        }, 'recovery');
    }

    /**
     * A shard list derived from the shard files alone (reads only; counters 0, recountMeta() fills them).
     * The shard size is FORMAT's; the next key is above every key a shard file holds (tombstones included).
     * @return array
     */
    public function deriveShardMeta($dbname){
        $size = $this->segments->shardSizeOf($dbname);
        $prefix = $this->layout->genDir($dbname) . 'segments/s';
        $ids = [];
        $nextKey = 0;
        foreach($this->segments->listFilesWithPrefix($prefix, '/^\d+\.jsonl$/') as $file){
            $shardId = (int)substr($file, strlen($prefix));
            $ids[] = $shardId;
            $fp = @fopen($file, 'rb');
            while($fp !== false && ($line = fgets($fp)) !== false){
                if(substr($line, -1) === "\n" && ($entry = $this->checkpoints->classifyLine(substr($line, 0, -1), $shardId * $size, ($shardId + 1) * $size)) !== null){
                    $nextKey = max($nextKey, $entry[1] + 1);
                }
            }
            if($fp !== false){
                fclose($fp);
            }
        }
        sort($ids);
        $meta = self::newShardMeta($size, $nextKey);
        foreach($ids as $shardId){
            $meta['shards'][] = self::newShardEntry($shardId);
        }
        return $meta;
    }

    /**
     * An empty shards.json record (docs/STORAGE_FORMAT.md §5.1)
     */
    public static function newShardMeta($size, $nextKey){
        return ['kind' => 'shards', 'v' => 1, 'shardSize' => (int)$size, 'nextKey' => (int)$nextKey,
            'totalRecords' => 0, 'deletedCount' => 0, 'shards' => []];
    }

    public static function newShardEntry($shardId){
        return ['id' => (int)$shardId, 'count' => 0, 'deleted' => 0];
    }

    /**
     * Write shard metadata (caller holds the exclusive lock)
     * @param string $dbname
     * @param array $meta
     * @return bool
     * @throws \RuntimeException when shards.json can't be written
     */
    public function writeMeta($dbname, $meta, $sync = false){
        $path = $this->getMetaPath($dbname);
        $json = $this->io->encodeJson($meta);
        if($json === false || !$this->io->writeFileAtomic($path, $json . "\n", $sync ? 'replace' : 'durable')){
            throw new \RuntimeException("noneDB: failed to write metadata of database '{$dbname}'");
        }
        $this->metaCache[$path] = $meta;
        $this->shardedCache[$path] = true;
        $this->cache->markFileExists($path);
        return true;
    }

    /**
     * Recompute the per-shard and total counters of shards.json from the shard indexes
     */
    public function recountMeta($dbname){
        $meta = $this->getCachedMeta($dbname, true);
        if($meta === null){
            return;
        }
        $size = (int)$meta['shardSize'];
        $total = 0;
        $nextKey = 0;
        foreach($meta['shards'] as $i => $shard){
            $index = $this->checkpoints->readJsonlIndex($dbname, (int)$shard['id']);
            $count = $index === null ? 0 : \noneDB\Internal\Storage\KeyIndex::count($index);
            $meta['shards'][$i]['count'] = $count;
            $total += $count;
            if($index !== null){
                // n of a shard counts every key it ever had (deleted ones and the kept top tombstone too)
                $nextKey = max($nextKey, \noneDB\Internal\Storage\KeyIndex::maxKey($index) + 1);
                if((int)$index['n'] > 0){
                    $nextKey = max($nextKey, (int)$shard['id'] * $size + (int)$index['n']);
                }
            }
            unset($index);
            $this->cache->evictBetweenSegments();
        }
        $meta['totalRecords'] = $total;
        $meta['nextKey'] = max((int)($meta['nextKey'] ?? 0), $nextKey);
        $this->writeMeta($dbname, $meta);
    }
}
