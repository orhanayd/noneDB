<?php
// Buffer settings, flush, compact(), convertToSharded()
namespace noneDB\Internal\Maintenance;

use function array_keys, clearstatcache, count, file_exists, filesize, intdiv, max;

/**
 * @internal
 */
final class Maintenance {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $bufferCountLimit;
    private $bufferEnabled;
    private $bufferFlushInterval;
    private $bufferSizeLimit;
    private $indexCache;
    private $shardSize;
    private $shardingEnabled;
    private $lockRegistry;
    private $buffer;
    private $cache;
    private $checkpoints;
    private $compaction;
    private $db;
    private $fieldIndex;
    private $io;
    private $layout;
    private $layoutSwitch;
    private $locks;
    private $rtree;
    private $scope;
    private $segments;
    private $shardMeta;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->cache, $this->checkpoints, $this->compaction, $this->db, $this->fieldIndex, $this->io, $this->layout, $this->layoutSwitch, $this->locks, $this->rtree, $this->scope, $this->segments, $this->shardMeta, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->bufferCountLimit = &$this->k->bufferCountLimit;
        $this->bufferEnabled = &$this->k->bufferEnabled;
        $this->bufferFlushInterval = &$this->k->bufferFlushInterval;
        $this->bufferSizeLimit = &$this->k->bufferSizeLimit;
        $this->indexCache = &$this->k->indexCache;
        $this->shardSize = &$this->k->shardSize;
        $this->shardingEnabled = &$this->k->shardingEnabled;
        $this->lockRegistry = &$this->rt->lockRegistry;
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
        $this->buffer = null;
        $this->cache = null;
        $this->checkpoints = null;
        $this->compaction = null;
        $this->db = null;
        $this->fieldIndex = null;
        $this->io = null;
        $this->layout = null;
        $this->layoutSwitch = null;
        $this->locks = null;
        $this->rtree = null;
        $this->scope = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // WRITE BUFFER PUBLIC API
    // ==========================================

    /**
     * Manually flush buffer for a database
     * @param string $dbname
     * @return array ['success' => bool, 'flushed' => int, 'error' => string|null]
     */
    public function flush($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            return $this->flushUnlocked($dbname);
        }, 'flush');
    }

    private function flushUnlocked($dbname){
        $flushed = $this->buffer->flushPendingBuffers($dbname);
        return ['success' => true, 'flushed' => $flushed, 'error' => null];
    }

    /**
     * Flush the write buffers of every database (of this secretKey) in dbDir
     * @return array ['databases' => int, 'flushed' => int]
     */
    public function flushAllBuffers(){
        $names = [];
        if(!empty($this->lockRegistry)){
            return ['databases' => 0, 'flushed' => 0]; // inside an interrupted operation (see releaseInterruptedSessions)
        }
        foreach($this->db->getDBs(false) as $dbname){
            $buffered = $this->locks->withLock($dbname, false, function() use ($dbname){
                return $this->buffer->hasBuffer($this->buffer->getBufferPath($dbname)) || !empty($this->buffer->flushingFiles($dbname));
            });
            if($buffered){
                $names[$dbname] = true;
            }
        }
        $total = 0;
        foreach(array_keys($names) as $dbname){
            $result = $this->flush((string)$dbname);
            $total += $result['flushed'];
        }
        return ['databases' => count($names), 'flushed' => $total];
    }

    /**
     * Get buffer information for a database
     * @param string $dbname
     * @return array
     */
    public function getBufferInfo($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        $fn = function() use ($dbname){
            return $this->getBufferInfoUnlocked($dbname);
        };
        return $this->locks->withLock($dbname, false, $fn);
    }

    private function getBufferInfoUnlocked($dbname){
        $info = [
            'enabled' => $this->bufferEnabled,
            'sizeLimit' => $this->bufferSizeLimit,
            'countLimit' => $this->bufferCountLimit,
            'flushInterval' => $this->bufferFlushInterval,
            'buffers' => []
        ];
        $describe = function($path){
            clearstatcache(true, $path);
            $exists = file_exists($path) && filesize($path) > 0;
            return [
                'exists' => $exists,
                'size' => $exists ? (int)filesize($path) : 0,
                'records' => $exists ? $this->buffer->getBufferRecordCount($path) : 0
            ];
        };
        $info['buffers']['main'] = $describe($this->buffer->getBufferPath($dbname));
        return $info;
    }

    /**
     * Enable or disable write buffering
     * @param bool $enable
     */
    public function enableBuffering($enable = true){
        $this->bufferEnabled = (bool)$enable;
    }

    /**
     * Check if buffering is enabled
     * @return bool
     */
    public function isBufferingEnabled(){
        return $this->bufferEnabled;
    }

    /**
     * Set buffer size limit (in bytes)
     * @param int $bytes
     */
    public function setBufferSizeLimit($bytes){
        $this->bufferSizeLimit = max(1024, (int)$bytes); // Minimum 1KB
    }

    /**
     * Set buffer flush interval (in seconds)
     * @param int $seconds 0 to disable time-based flush
     */
    public function setBufferFlushInterval($seconds){
        $this->bufferFlushInterval = max(0, (int)$seconds);
    }

    /**
     * Set buffer count limit
     * @param int $count
     */
    public function setBufferCountLimit($count){
        $this->bufferCountLimit = max(10, (int)$count); // Minimum 10 records
    }

    /**
     * Compact a database: drop old record versions and deleted records from the data files.
     * Keys are preserved (sharded and non-sharded alike); indexes stay valid.
     * @param string $dbname
     * @return array ['success' => bool, 'freedSlots' => int, 'totalRecords' => int, 'sharded' => bool]
     */
    public function compact($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            $this->scope->refuseInUncommittedWrite($dbname, 'compact');
            return $this->compactUnlocked($dbname);
        }, 'compact');
    }

    private function compactUnlocked($dbname){
        $result = array("success" => false, "freedSlots" => 0);
        $this->buffer->flushPendingBuffers($dbname);
        if(!$this->segments->dbExists($dbname)){
            $result['status'] = 'database_not_found';
            return $result;
        }

        $sharded = $this->segments->isSharded($dbname);
        $freed = 0;
        if($sharded){
            $meta = $this->shardMeta->getCachedMeta($dbname, true);
            $freed = (int)($meta['deletedCount'] ?? 0);
        }
        $total = 0;
        foreach($this->segments->listSegments($dbname) as $segment){
            $compacted = $this->compaction->compactJsonl($dbname, $segment);
            if(!$sharded){
                $freed += $compacted['freed'];
            }
            $total += $compacted['compacted'];
            $this->cache->evictBetweenSegments();
        }
        if($sharded){
            $this->removeEmptyShards($dbname);
            $meta = $this->shardMeta->getCachedMeta($dbname, true);
            foreach($meta['shards'] as $i => $shard){
                $meta['shards'][$i]['deleted'] = 0;
            }
            $meta['deletedCount'] = 0;
            $meta['totalRecords'] = $total;
            $this->shardMeta->writeMeta($dbname, $meta);
        }

        $result['success'] = true;
        $result['freedSlots'] = $freed;
        $result['totalRecords'] = $total;
        $result['sharded'] = $sharded;
        if($sharded){
            $result['newShardCount'] = count($this->segments->listSegments($dbname));
        }
        return $result;
    }

    /**
     * Remove shards without records (keys never move, so nothing else changes). The shard that holds
     * the newest key is kept: new records go there.
     */
    private function removeEmptyShards($dbname){
        $meta = $this->shardMeta->getCachedMeta($dbname, true);
        if($meta === null || count($meta['shards']) < 2){
            return;
        }
        $lastShard = intdiv(max(0, (int)$meta['nextKey'] - 1), (int)$meta['shardSize']);
        $kept = [];
        $removed = [];
        $done = false; // a shard was read: the cache may drop what it loaded before the next one
        foreach($meta['shards'] as $shard){
            if($done){
                $this->cache->evictBetweenSegments();
            }
            $done = true;
            $shardId = (int)$shard['id'];
            $index = $this->checkpoints->readJsonlIndex($dbname, $shardId);
            if($shardId !== $lastShard && ($index === null || \noneDB\Internal\Storage\KeyIndex::count($index) === 0)){
                $removed[] = $shardId;
            } else {
                $kept[] = $shard;
            }
        }
        if(empty($removed)){
            return;
        }
        $meta['shards'] = $kept;
        // The meta must not lose this change in a power loss after the files are gone (where PHP can sync)
        $this->shardMeta->writeMeta($dbname, $meta, true);

        $manifest = $this->fieldIndex->getIndexManifest($dbname);
        $this->locks->bumpEpoch($dbname); // shard files go away
        foreach($removed as $shardId){
            $dataPath = $this->segments->getShardPath($dbname, $shardId);
            foreach($manifest['fields'] as $field){
                $this->fieldIndex->deleteFieldIndexFile($dbname, $field, $shardId);
            }
            $this->rtree->removeSpatialShardFiles($dbname, $shardId);
            $this->io->fsUnlink(\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath));
            $this->io->fsUnlink($dataPath);
            $this->cache->markFileNotExists($dataPath);
            unset($this->indexCache[\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath)]);
        }
        // The shard list no longer names them: their removal only frees space. A power loss may bring them
        // back as files no shard entry names (set aside when a shard with that number is created again).
        foreach($manifest['fields'] as $field){
            $this->fieldIndex->rebuildGlobalFieldIndex($dbname, $field);
        }
    }
    /**
     * Switch a database of the single layout to the sharded layout (STORAGE_FORMAT.md §5.2)
     * @param string $dbname
     * @return array ["success" => bool, "status" => string]
     */
    public function convertToSharded($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            $this->scope->refuseInUncommittedWrite($dbname, 'convertToSharded');
            return $this->convertToShardedUnlocked($dbname);
        }, 'convertToSharded');
    }

    private function convertToShardedUnlocked($dbname){
        if($this->segments->isSharded($dbname)){
            return array("success" => true, "status" => "already_sharded");
        }
        $this->buffer->flushPendingBuffers($dbname);
        if($this->segments->isSharded($dbname)){
            return array("success" => true, "status" => "converted");
        }
        if(!$this->cache->cachedFileExists($this->segments->getDataPath($dbname))){
            return array("success" => false, "status" => "database_not_found");
        }
        if($this->layoutSwitch->convertToSharded($dbname)){
            return array("success" => true, "status" => "converted");
        }
        return array("success" => false, "status" => "conversion_failed");
    }

    /**
     * Check if sharding is enabled
     * @return bool
     */
    public function isShardingEnabled(){
        return $this->shardingEnabled;
    }

    /**
     * Get current shard size setting
     * @return int
     */
    public function getShardSize(){
        return $this->shardSize;
    }
}
