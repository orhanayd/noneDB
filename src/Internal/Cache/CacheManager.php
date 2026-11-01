<?php
// Ownership of the caches: the static cache switch, memory limit and eviction, invalidation by lock state
namespace noneDB\Internal\Cache;

use function array_keys, clearstatcache, fclose, file_exists, gc_mem_caches, hash_hmac, ini_get, intdiv, is_array,
    memory_get_peak_usage, memory_get_usage, min, strlen, strncmp, strtolower, substr, trim, usort;
use const PHP_INT_MAX;

/**
 * @internal
 */
final class CacheManager {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $dataEpochs;
    private $dataSizes;
    private $fieldIndexCache;
    private $globalSkip;
    private $globalSpatialIndexCache;
    private $indexCache;
    private $keyFingerprint;
    private $metaCache;
    private $segWrites;
    private $shardFilesUpTo;
    private $shardedCache;
    private $spatialIndexCache;
    private $verifiedIndexes;
    private $writableIndexes;
    private $bufferCounts;
    private $bufferEnds;
    private $cacheGrew;
    private $cacheTouch;
    private $sessionLimit;
    private $sessionNear;
    private $indexStats; // @test
    private $lockRegistry;
    private $metrics;
    private $secondaryChecked;
    private $staticCacheEnabled;
    private $staticFieldIndexCache;
    private $staticFileExistsCache;
    private $staticGlobalSpatialIndexCache;
    private $staticIndexCache;
    private $staticSpatialIndexCache;
    private $staticVerifiedIndexes;
    private $staticWritableIndexes;
    private $tailCache;
    private $config;
    private $layout;
    private $locks;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->config, $this->layout, $this->locks);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dataEpochs = &$this->k->dataEpochs;
        $this->dataSizes = &$this->k->dataSizes;
        $this->fieldIndexCache = &$this->k->fieldIndexCache;
        $this->globalSkip = &$this->k->globalSkip;
        $this->globalSpatialIndexCache = &$this->k->globalSpatialIndexCache;
        $this->indexCache = &$this->k->indexCache;
        $this->keyFingerprint = &$this->k->keyFingerprint;
        $this->metaCache = &$this->k->metaCache;
        $this->segWrites = &$this->k->segWrites;
        $this->shardFilesUpTo = &$this->k->shardFilesUpTo;
        $this->shardedCache = &$this->k->shardedCache;
        $this->spatialIndexCache = &$this->k->spatialIndexCache;
        $this->verifiedIndexes = &$this->k->verifiedIndexes;
        $this->writableIndexes = &$this->k->writableIndexes;
        $this->bufferCounts = &$this->rt->bufferCounts;
        $this->bufferEnds = &$this->rt->bufferEnds;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->cacheTouch = &$this->rt->cacheTouch;
        $this->sessionLimit = &$this->rt->sessionLimit;
        $this->sessionNear = &$this->rt->sessionNear;
        $this->indexStats = &$this->rt->indexStats; // @test
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->metrics = &$this->rt->metrics;
        $this->secondaryChecked = &$this->rt->secondaryChecked;
        $this->staticCacheEnabled = &$this->rt->staticCacheEnabled;
        $this->staticFieldIndexCache = &$this->rt->staticFieldIndexCache;
        $this->staticFileExistsCache = &$this->rt->staticFileExistsCache;
        $this->staticGlobalSpatialIndexCache = &$this->rt->staticGlobalSpatialIndexCache;
        $this->staticIndexCache = &$this->rt->staticIndexCache;
        $this->staticSpatialIndexCache = &$this->rt->staticSpatialIndexCache;
        $this->staticVerifiedIndexes = &$this->rt->staticVerifiedIndexes;
        $this->staticWritableIndexes = &$this->rt->staticWritableIndexes;
        $this->tailCache = &$this->rt->tailCache;
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
        $this->config = null;
        $this->layout = null;
        $this->locks = null;
        $this->k = null;
        $this->rt = null;
    }

    /**
     * HMAC fingerprint of the secret; binds caches to the key without revealing it
     */
    public function getKeyFingerprint(){
        if($this->keyFingerprint === null){
            $this->keyFingerprint = substr(hash_hmac('sha256', 'nonedb4/cache-key', (string)$this->config->getSecretKey()), 0, 16);
        }
        return $this->keyFingerprint;
    }

    /**
     * Clear all static caches (useful for testing or memory management)
     * @return void
     */
    public static function clearStaticCache(){
        \noneDB\Internal\Cache\Runtime::get()->staticIndexCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticVerifiedIndexes = [];
        \noneDB\Internal\Cache\Runtime::get()->uncheckpointed = [];
        \noneDB\Internal\Cache\Runtime::get()->decodeDue = [];
        \noneDB\Internal\Cache\Runtime::get()->staticShardedCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticMetaCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticHashCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticFileExistsCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticSanitizeCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticFieldIndexCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticSpatialIndexCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticGlobalSpatialIndexCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticGenerations = [];
        \noneDB\Internal\Cache\Runtime::get()->staticCurrentGenerations = [];
        \noneDB\Internal\Cache\Runtime::get()->staticFormatRecords = [];
        \noneDB\Internal\Cache\Runtime::get()->storeState = [];
        \noneDB\Internal\Cache\Runtime::get()->parsedLockStates = [];
        \noneDB\Internal\Cache\Runtime::get()->bufferCounts = [];
        \noneDB\Internal\Cache\Runtime::get()->bufferEnds = [];
        \noneDB\Internal\Cache\Runtime::get()->staticWritableIndexes = [];
        \noneDB\Internal\Cache\Runtime::get()->staticDataSizes = [];
        \noneDB\Internal\Cache\Runtime::get()->staticGlobalSkip = [];
        \noneDB\Internal\Cache\Runtime::get()->staticSegWrites = [];
        \noneDB\Internal\Cache\Runtime::get()->staticDataEpochs = [];
        \noneDB\Internal\Cache\Runtime::get()->staticShardFilesUpTo = [];
        \noneDB\Internal\Cache\Runtime::get()->secondaryChecked = [];
        \noneDB\Internal\Cache\Runtime::get()->cacheTouch = [];
        \noneDB\Internal\Cache\Runtime::get()->tailCache = [];
        foreach(\noneDB\Internal\Cache\Runtime::get()->lockHandles as $handle){
            @fclose($handle['fp']);
        }
        \noneDB\Internal\Cache\Runtime::get()->lockHandles = [];
    }

    /**
     * Disable static caching (each instance uses its own cache)
     * @return void
     */
    public static function disableStaticCache(){
        \noneDB\Internal\Cache\Runtime::get()->staticCacheEnabled = false;
    }

    /**
     * Enable static caching (default)
     * @return void
     */
    public static function enableStaticCache(){
        \noneDB\Internal\Cache\Runtime::get()->staticCacheEnabled = true;
    }

    /**
     * Cached file_exists check. Entries are dropped whenever another process writes the database
     * (see withLock), so the cache can't go stale across processes.
     *
     * @param string $path File path to check
     * @return bool True if file exists
     */
    public function cachedFileExists($path){
        if(!$this->staticCacheEnabled){
            clearstatcache(true, $path);
            return file_exists($path);
        }
        if(isset($this->staticFileExistsCache[$path])){
            return $this->staticFileExistsCache[$path];
        }
        clearstatcache(true, $path);
        $exists = file_exists($path);
        $this->staticFileExistsCache[$path] = $exists;
        return $exists;
    }

    /**
     * Mark file as existing in cache (call after creating file)
     * @param string $path File path
     */
    public function markFileExists($path){
        if($this->staticCacheEnabled){
            $this->staticFileExistsCache[$path] = true;
        }
    }

    /**
     * Mark file as not existing in cache (call after deleting file)
     * @param string $path File path
     */
    public function markFileNotExists($path){
        if($this->staticCacheEnabled){
            $this->staticFileExistsCache[$path] = false;
        }
    }

    /**
     * The cache limit in bytes, 0 = none: the cacheMemoryLimit setting, or by default half of PHP's
     * memory_limit (no limit without one): the cache doesn't make a process run out of
     * memory, and doesn't slow down one that may use all the memory it needs
     */
    public static function cacheLimitBytes(){
        $rt = \noneDB\Internal\Cache\Runtime::get();
        return $rt->cacheMemoryLimit ?? self::memoryLimitHalf();
    }

    /**
     * Half of PHP's memory_limit in bytes, 0 without one
     */
    private static function memoryLimitHalf(){
        $rt = \noneDB\Internal\Cache\Runtime::get();
        $setting = (string)ini_get('memory_limit');
        if($setting !== $rt->memoryLimitSetting){
            // Worked out once per value of memory_limit: this runs between the segments of every operation
            $ini = strtolower(trim($setting));
            $bytes = (int)$ini;
            $unit = substr($ini, -1);
            $bytes *= $unit === 'g' ? 1073741824 : ($unit === 'm' ? 1048576 : ($unit === 'k' ? 1024 : 1));
            $rt->memoryLimitSetting = $setting;
            $rt->memoryLimitHalf = $bytes <= 0 ? 0 : intdiv($bytes, 2);
        }
        return $rt->memoryLimitHalf;
    }

    /**
     * Keep the process under cacheMemoryLimit by dropping cached indexes: the process's memory
     * (memory_get_usage()) is what counts, the caches are what can be dropped. Runs when the last lock of the
     * process is released and between the segments of an operation (evictBetweenSegments()); while the process
     * uses less memory than the limit it does nothing. Least recently used entries go first, until the process
     * is under the limit (three quarters of it between segments) or nothing is left to drop. Every evicted
     * entry is loaded again when needed. A global index a write session changed stays: it is written when the
     * session ends (FieldIndex::deferGlobalWrite()).
     * @param bool $within Between the segments of an operation (evictBetweenSegments())
     */
    public function evictCaches($within = false){
        $limit = self::cacheLimitBytes();
        $target = $within ? intdiv(3 * $limit, 4) : $limit;
        if(memory_get_usage() <= $target){
            return;
        }
        $pending = [];
        foreach($this->lockRegistry as $entry){
            $pending += $entry['globals'];
        }
        $entries = [];
        foreach(['staticIndexCache', 'staticFieldIndexCache', 'staticSpatialIndexCache', 'staticGlobalSpatialIndexCache'] as $cache => $property){
            foreach(array_keys($this->$property) as $key){
                if(!isset($pending[$key])){
                    $entries[] = [$this->cacheTouch[$key] ?? 0, $cache, $key];
                }
            }
        }
        usort($entries, function($a, $b){ return $a[0] <=> $b[0]; });
        foreach($entries as [, $cache, $key]){
            if($cache === 0){
                unset($this->staticIndexCache[$key], $this->staticVerifiedIndexes[$key], $this->staticWritableIndexes[$key]);
            } elseif($cache === 1){
                unset($this->staticFieldIndexCache[$key]);
            } elseif($cache === 2){
                unset($this->staticSpatialIndexCache[$key]);
            } else {
                unset($this->staticGlobalSpatialIndexCache[$key]);
            }
            unset($this->cacheTouch[$key]);
            $this->metrics['evictions']++;
            if(memory_get_usage() <= $target){
                break;
            }
        }
    }

    /**
     * Bytes read from an index file, and the 4 KiB pages they are (getMetrics(), docs/OPERATIONS.md §1.1)
     */
    public function noteIndexRead($bytes, $pages = null){
        $this->metrics['bytes'] += $bytes;
        $this->metrics['pages'] += $pages ?? intdiv($bytes + 4095, 4096);
    }

    /**
     * True when an index may be decoded into a larger in-memory form of about $bytes: no limit, or the
     * process stays under half of cacheMemoryLimit with it (room left before eviction starts)
     */
    public static function decodeFits($bytes){
        $limit = self::cacheLimitBytes();
        return $limit <= 0 || memory_get_usage() + $bytes <= intdiv($limit, 2);
    }

    /** Memory of the process after the last eviction between segments */
    private $betweenFloor = 0;

    /** Memory PHP held for the process after it */
    private $heldFloor = 0;

    /**
     * Between the segments of an operation over many segments (a read, an aggregation, a write by filter, an
     * index build, compaction), once a segment is done: when the process uses more memory than cacheMemoryLimit,
     * least recently used entries go until the estimate is under three quarters of the limit; the next eviction waits
     * until the process has grown by another quarter (counted from where it is now if it has freed memory since). A
     * process whose peak came within an eighth of memory_limit (what PHP held for it, what memory_limit counts: a
     * segment's batches leave more of it behind than the process uses) measures that too, and gives back what it
     * freed. Not sooner: a warm query loses what it would load again. One operation over a large database holds the
     * cache and the segment it works on (the segments it finished go first), without a scan of the cache at every
     * segment. Under the exclusive lock too: what a write session changed is in its files, except the
     * global indexes it defers (they stay); a segment index it changed that goes is checkpointed by the
     * next reader that loads it (Checkpoints::checkpointTouchedSegments()).
     */
    public function evictBetweenSegments(){
        if(!$this->staticCacheEnabled){
            return;
        }
        $limit = $this->sessionLimit ?? ($this->sessionLimit = self::cacheLimitBytes());
        if($limit <= 0){
            return;
        }
        $used = memory_get_usage();
        $this->betweenFloor = min($this->betweenFloor, $used);
        $quarter = intdiv($limit, 4);
        $over = $used > $limit && $used > $this->betweenFloor + $quarter;
        $near = memory_get_peak_usage(true) > ($this->sessionNear ?? ($this->sessionNear = self::nearMemoryLimit()));
        if($near){
            $held = memory_get_usage(true);
            $this->heldFloor = min($this->heldFloor, $held);
            $over = $over || ($held > $limit && $held > $this->heldFloor + $quarter);
        }
        if(!$over){
            return;
        }
        $this->cacheGrew = false;
        $this->indexStats['evictionsBetween']++; // @test
        $this->evictCaches(true);
        $this->betweenFloor = memory_get_usage();
        if($near){
            gc_mem_caches();
            $this->heldFloor = memory_get_usage(true);
        }
    }

    /**
     * Where a process's peak is near memory_limit: seven eighths of it. Never without one
     */
    private static function nearMemoryLimit(){
        $half = self::memoryLimitHalf();
        return $half > 0 ? intdiv(7 * $half, 4) : PHP_INT_MAX;
    }

    /**
     * Drop every cached entry that belongs to a database (index, meta, field/spatial indexes, file-exists)
     */
    public function invalidateDbCaches($dbname){
        $prefix = $this->layout->dbHome($dbname);
        $len = strlen($prefix);
        $caches = [
            &$this->indexCache, &$this->verifiedIndexes, &$this->shardedCache, &$this->metaCache,
            &$this->fieldIndexCache, &$this->spatialIndexCache,
            &$this->globalSpatialIndexCache, &$this->dataSizes, &$this->dataEpochs, &$this->shardFilesUpTo,
            &$this->secondaryChecked, &$this->tailCache,
        ];
        foreach($caches as &$cache){
            foreach(array_keys($cache) as $key){
                if(strncmp((string)$key, $prefix, $len) === 0){
                    unset($cache[$key]);
                }
            }
        }
        unset($cache);
        foreach(array_keys($this->staticFileExistsCache) as $key){
            if(strncmp((string)$key, $prefix, $len) === 0){
                unset($this->staticFileExistsCache[$key]);
            }
        }
        $lockPath = $this->locks->getLockPath($dbname);
        unset($this->globalSkip[$lockPath], $this->segWrites[$lockPath]);
        foreach(array_keys($this->bufferCounts) as $key){
            if(strncmp((string)$key, $prefix, $len) === 0){
                unset($this->bufferCounts[$key], $this->bufferEnds[$key]);
            }
        }
        foreach(array_keys($this->writableIndexes) as $key){
            if(strncmp((string)$key, $prefix, $len) === 0){
                unset($this->writableIndexes[$key]);
            }
        }
    }

    /**
     * Caches of a database after another process wrote it without replacing a data file (same epoch):
     * everything is dropped but the key indexes, which are checked again (the data only grew: the new lines
     * are replayed on the next read)
     */
    public function refreshDbCaches($dbname){
        $prefix = $this->layout->dbHome($dbname);
        $len = strlen($prefix);
        // Kept (they describe data bytes that didn't change, the new lines are replayed when used): key
        // indexes, field indexes and R-trees, what was checked against the data, parsed tails, epochs
        $keep = [];
        foreach(['indexCache' => &$this->indexCache, 'fieldIndexCache' => &$this->fieldIndexCache,
                 'spatialIndexCache' => &$this->spatialIndexCache, 'dataEpochs' => &$this->dataEpochs,
                 'secondaryChecked' => &$this->secondaryChecked, 'tailCache' => &$this->tailCache] as $name => &$cache){
            foreach($cache as $key => $value){
                if(strncmp((string)$key, $prefix, $len) === 0
                    && ($name !== 'fieldIndexCache' && $name !== 'spatialIndexCache' || (is_array($value) && isset($value['kind'])))){
                    $keep[$name][$key] = $value;
                }
            }
        }
        unset($cache);
        $this->invalidateDbCaches($dbname);
        foreach($keep['indexCache'] ?? [] as $key => $value){
            $this->indexCache[$key] = $value;
        }
        foreach($keep['fieldIndexCache'] ?? [] as $key => $value){
            $this->fieldIndexCache[$key] = $value;
        }
        foreach($keep['spatialIndexCache'] ?? [] as $key => $value){
            $this->spatialIndexCache[$key] = $value;
        }
        foreach($keep['dataEpochs'] ?? [] as $key => $value){
            $this->dataEpochs[$key] = $value;
        }
        foreach($keep['secondaryChecked'] ?? [] as $key => $value){
            $this->secondaryChecked[$key] = $value;
        }
        foreach($keep['tailCache'] ?? [] as $key => $value){
            $this->tailCache[$key] = $value;
        }
    }
}
