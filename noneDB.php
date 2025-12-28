<?php
/**
 * https://github.com/orhanayd/noneDB
 * 
 * first this project start date 15.11.2018
 * noneDB - 20.08.2019 -- Orhan AYDOĞDU
 * OPEN SOURCE <3
 * please read firstly noneDB documents if your data is important ::)))
 * but every data is important for us <3 
 * created with love <3  || ORHAN AYDOGDU || www.orhanaydogdu.com.tr || info@orhanaydogdu.com.tr
 * Respect for Labour - Emeğe Saygı
 */

ini_set('memory_limit', '-1');

class noneDB {

    private $dbDir=__DIR__."/"."db/"; // please change this path and don't fotget end with /
    private $secretKey="nonedb_123"; // please change this secret key! and don't share anyone or anywhere!!
    private $autoCreateDB=true; // if you want to auto create your db true or false

    // Sharding configuration
    private $shardingEnabled=true;   // Enable/disable auto-sharding
    private $shardSize=10000;         // Max records per shard (10K) - optimal for filter operations
    private $autoMigrate=true;        // Auto-migrate legacy DBs to sharded format

    // File locking configuration
    private $lockTimeout=5;           // Max seconds to wait for lock
    private $lockRetryDelay=10000;    // Microseconds between lock attempts (10ms)

    // Write buffer configuration
    private $bufferEnabled=true;              // Enable/disable write buffering
    private $bufferSizeLimit=1048576;         // 1MB buffer size limit per buffer
    private $bufferCountLimit=10000;          // Max records per buffer (safety limit)
    private $bufferFlushOnRead=true;          // Flush buffer before read operations
    private $bufferFlushInterval=30;          // Seconds between auto-flush (0 = disabled)
    private $bufferAutoFlushOnShutdown=true;  // Register shutdown handler for flush

    // Buffer state tracking (runtime)
    private $bufferLastFlush=[];              // Track last flush time per DB/shard
    private $shutdownHandlerRegistered=false; // Track if shutdown handler is registered

    // Performance cache (runtime) - v2.3.0
    private $hashCache=[];                    // Cache dbname -> hash (PBKDF2 is expensive)
    private $metaCache=[];                    // Cache dbname -> meta data
    private $metaCacheTime=[];                // Cache timestamps for TTL
    private $metaCacheTTL=1;                  // Meta cache TTL in seconds (short for consistency)

    // Index configuration - v2.3.0
    private $indexEnabled=true;               // Enable/disable primary key indexing
    private $indexCache=[];                   // Runtime cache for index data
    private $shardedCache=[];                 // Cache isSharded results

    // JSONL Storage Engine - v3.0.0 (JSONL-only, v2 format removed)
    private $jsonlFormatCache=[];             // Cache format detection per DB
    private $jsonlGarbageThreshold=0.3;       // Trigger compaction when garbage > 30%

    // Static caches for cross-instance sharing - v3.0.0
    private static $staticIndexCache=[];      // Shared index cache across instances
    private static $staticShardedCache=[];    // Shared isSharded results
    private static $staticMetaCache=[];       // Shared meta data cache
    private static $staticMetaCacheTime=[];   // Shared meta cache timestamps
    private static $staticHashCache=[];       // Shared hash cache (PBKDF2 is expensive)
    private static $staticFormatCache=[];     // Shared format detection cache
    private static $staticFileExistsCache=[]; // Shared file_exists cache - v3.0.0
    private static $staticSanitizeCache=[];   // Shared dbname sanitization cache - v3.0.0
    private static $staticFieldIndexCache=[]; // Shared field index cache - v3.0.0
    private static $staticCacheEnabled=true;  // Enable/disable static caching

    // Field indexing configuration - v3.0.0
    private $fieldIndexEnabled = true;        // Enable field-based indexing
    private $fieldIndexCache = [];            // Instance-level field index cache

    // Persistent hash cache - v3.0.0 performance optimization
    private $hashCacheFile = null;            // Path to persistent hash cache file
    private $hashCacheDirty = false;          // Track if hash cache needs saving
    private $hashCacheLoaded = false;         // Track if persistent cache was loaded

    /**
     * Constructor - initialize static caches
     */
    public function __construct(){
        // Link instance caches to static caches for cross-instance sharing
        if(self::$staticCacheEnabled){
            $this->indexCache = &self::$staticIndexCache;
            $this->shardedCache = &self::$staticShardedCache;
            $this->metaCache = &self::$staticMetaCache;
            $this->metaCacheTime = &self::$staticMetaCacheTime;
            $this->hashCache = &self::$staticHashCache;
            $this->jsonlFormatCache = &self::$staticFormatCache;
            $this->fieldIndexCache = &self::$staticFieldIndexCache;
        }
    }

    /**
     * Destructor - save persistent hash cache
     * v3.0.0 performance optimization
     */
    public function __destruct(){
        $this->savePersistentHashCache();
    }

    /**
     * Load hash cache from persistent storage
     * v3.0.0 performance optimization: Eliminates PBKDF2 computation on subsequent requests
     * @return void
     */
    private function loadPersistentHashCache(){
        if($this->hashCacheLoaded){
            return;
        }
        $this->hashCacheLoaded = true;

        if($this->hashCacheFile === null){
            $this->hashCacheFile = $this->dbDir . '.nonedb_hash_cache';
        }

        if(file_exists($this->hashCacheFile)){
            $data = @file_get_contents($this->hashCacheFile);
            if($data !== false && $data !== ''){
                $loaded = @json_decode($data, true);
                if(is_array($loaded) && !empty($loaded)){
                    // Merge into hash cache
                    foreach($loaded as $dbname => $hash){
                        if(!isset($this->hashCache[$dbname])){
                            $this->hashCache[$dbname] = $hash;
                        }
                    }
                    // Also update static cache if enabled
                    if(self::$staticCacheEnabled){
                        self::$staticHashCache = $this->hashCache;
                    }
                }
            }
        }
    }

    /**
     * Save hash cache to persistent storage
     * v3.0.0 performance optimization: Persists PBKDF2 results across PHP requests
     * @return void
     */
    private function savePersistentHashCache(){
        if(!$this->hashCacheDirty || empty($this->hashCache)){
            return;
        }

        if($this->hashCacheFile === null){
            $this->hashCacheFile = $this->dbDir . '.nonedb_hash_cache';
        }

        @file_put_contents($this->hashCacheFile, json_encode($this->hashCache));
        $this->hashCacheDirty = false;
    }

    /**
     * Clear all static caches (useful for testing or memory management)
     * @return void
     */
    public static function clearStaticCache(){
        self::$staticIndexCache = [];
        self::$staticShardedCache = [];
        self::$staticMetaCache = [];
        self::$staticMetaCacheTime = [];
        self::$staticHashCache = [];
        self::$staticFormatCache = [];
        self::$staticFileExistsCache = [];
        self::$staticSanitizeCache = [];
        self::$staticFieldIndexCache = [];
    }

    /**
     * Disable static caching (each instance uses its own cache)
     * @return void
     */
    public static function disableStaticCache(){
        self::$staticCacheEnabled = false;
    }

    /**
     * Enable static caching (default)
     * @return void
     */
    public static function enableStaticCache(){
        self::$staticCacheEnabled = true;
    }

    /**
     * Cached file_exists check - v3.0.0
     * Reduces disk I/O by caching file existence checks
     *
     * @param string $path File path to check
     * @return bool True if file exists
     */
    private function cachedFileExists($path){
        if(!self::$staticCacheEnabled){
            return file_exists($path);
        }
        if(isset(self::$staticFileExistsCache[$path])){
            return self::$staticFileExistsCache[$path];
        }
        $exists = file_exists($path);
        self::$staticFileExistsCache[$path] = $exists;
        return $exists;
    }

    /**
     * Mark file as existing in cache (call after creating file)
     * @param string $path File path
     */
    private function markFileExists($path){
        if(self::$staticCacheEnabled){
            self::$staticFileExistsCache[$path] = true;
        }
    }

    /**
     * Mark file as not existing in cache (call after deleting file)
     * @param string $path File path
     */
    private function markFileNotExists($path){
        if(self::$staticCacheEnabled){
            self::$staticFileExistsCache[$path] = false;
        }
    }

    /**
     * Invalidate file exists cache for a specific path
     * @param string $path File path
     */
    private function invalidateFileExistsCache($path){
        unset(self::$staticFileExistsCache[$path]);
    }

    /**
     * Sanitize database name - removes invalid characters
     * Uses static cache to avoid redundant regex operations - v3.0.0
     *
     * @param string $dbname Database name to sanitize
     * @return string Sanitized database name
     */
    private function sanitizeDbName($dbname){
        if(!self::$staticCacheEnabled){
            return preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        }
        if(isset(self::$staticSanitizeCache[$dbname])){
            return self::$staticSanitizeCache[$dbname];
        }
        $sanitized = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        self::$staticSanitizeCache[$dbname] = $sanitized;
        return $sanitized;
    }

    /**
     * hash to db name for security
     * Uses instance-level caching + persistent cache to avoid expensive PBKDF2 recomputation
     * v3.0.0 optimization: Loads from persistent cache on first access
     */
    private function hashDBName($dbname){
        // Check memory cache first (fastest)
        if(isset($this->hashCache[$dbname])){
            return $this->hashCache[$dbname];
        }

        // Load from persistent cache if not loaded yet
        $this->loadPersistentHashCache();
        if(isset($this->hashCache[$dbname])){
            return $this->hashCache[$dbname];
        }

        // Compute PBKDF2 hash (expensive: 1000 iterations)
        $hash = hash_pbkdf2("sha256", $dbname, $this->secretKey, 1000, 20);
        $this->hashCache[$dbname] = $hash;
        $this->hashCacheDirty = true;

        return $hash;
    }

    // ==========================================
    // ATOMIC FILE OPERATIONS
    // ==========================================

    /**
     * Atomically read a file with shared lock
     * Prevents reading while another process is writing
     *
     * @param string $path File path
     * @param mixed $default Default value if file doesn't exist
     * @return mixed Decoded JSON data or default value
     */
    private function atomicRead($path, $default = null){
        clearstatcache(true, $path);

        if(!file_exists($path)){
            return $default;
        }

        $fp = fopen($path, 'rb');
        if($fp === false){
            return $default;
        }

        $startTime = microtime(true);
        $locked = false;

        // Try to acquire shared lock with timeout
        while(!$locked && (microtime(true) - $startTime) < $this->lockTimeout){
            $locked = flock($fp, LOCK_SH | LOCK_NB);
            if(!$locked){
                usleep($this->lockRetryDelay);
            }
        }

        if(!$locked){
            // Fallback: blocking lock as last resort
            $locked = flock($fp, LOCK_SH);
        }

        if(!$locked){
            fclose($fp);
            return $default;
        }

        try {
            $content = stream_get_contents($fp);
            if($content === false || $content === ''){
                return $default;
            }
            $data = json_decode($content, true);
            // Check for JSON decode errors (corrupted data)
            if($data === null && json_last_error() !== JSON_ERROR_NONE){
                return null; // Return null for corrupted JSON, not default
            }
            return $data !== null ? $data : $default;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Fast atomic read optimized for index files
     * v3.0.0 optimization: Skips clearstatcache and retry loop
     * - Safe for index files that are read more often than written
     * - Uses direct blocking lock instead of retry loop
     *
     * @param string $path File path
     * @param mixed $default Default value if file doesn't exist
     * @return mixed Decoded JSON data or default value
     */
    private function atomicReadFast($path, $default = null){
        // Skip clearstatcache - safe for cached index paths
        if(!file_exists($path)){
            return $default;
        }

        $fp = @fopen($path, 'rb');
        if($fp === false){
            return $default;
        }

        // Direct blocking LOCK_SH - faster than retry loop for read-heavy workloads
        if(!flock($fp, LOCK_SH)){
            fclose($fp);
            return $default;
        }

        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if($content === false || $content === ''){
            return $default;
        }

        $data = json_decode($content, true);
        return $data !== null ? $data : $default;
    }

    /**
     * Atomically write a file with exclusive lock
     *
     * @param string $path File path
     * @param mixed $data Data to write (will be JSON encoded)
     * @param bool $prettyPrint Use JSON_PRETTY_PRINT
     * @return bool Success
     */
    private function atomicWrite($path, $data, $prettyPrint = false){
        // Ensure directory exists
        $dir = dirname($path);
        if(!is_dir($dir)){
            mkdir($dir, 0755, true);
        }

        $fp = fopen($path, 'cb'); // Create if not exists, open for writing
        if($fp === false){
            return false;
        }

        $startTime = microtime(true);
        $locked = false;

        // Try to acquire exclusive lock with timeout
        while(!$locked && (microtime(true) - $startTime) < $this->lockTimeout){
            $locked = flock($fp, LOCK_EX | LOCK_NB);
            if(!$locked){
                usleep($this->lockRetryDelay);
            }
        }

        if(!$locked){
            // Fallback: blocking lock as last resort
            $locked = flock($fp, LOCK_EX);
        }

        if(!$locked){
            fclose($fp);
            return false;
        }

        try {
            ftruncate($fp, 0);
            rewind($fp);
            $json = $prettyPrint
                ? json_encode($data, JSON_PRETTY_PRINT)
                : json_encode($data);
            $written = fwrite($fp, $json);
            fflush($fp);
            return $written !== false;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Atomically modify a file: read, apply callback, write
     * This is the key method that prevents race conditions
     *
     * @param string $path File path
     * @param callable $modifier Function that receives current data and returns modified data
     * @param mixed $default Default value if file doesn't exist
     * @param bool $prettyPrint Use JSON_PRETTY_PRINT for output
     * @return array ['success' => bool, 'data' => modified data, 'error' => string|null]
     */
    private function atomicModify($path, callable $modifier, $default = null, $prettyPrint = false){
        // Ensure directory exists
        $dir = dirname($path);
        if(!is_dir($dir)){
            mkdir($dir, 0755, true);
        }

        // Open with c+ mode: read/write, create if not exists
        $fp = fopen($path, 'c+b');
        if($fp === false){
            return ['success' => false, 'data' => null, 'error' => 'Failed to open file'];
        }

        $startTime = microtime(true);
        $locked = false;

        // Try to acquire exclusive lock with timeout
        while(!$locked && (microtime(true) - $startTime) < $this->lockTimeout){
            $locked = flock($fp, LOCK_EX | LOCK_NB);
            if(!$locked){
                usleep($this->lockRetryDelay);
            }
        }

        if(!$locked){
            // Fallback: blocking lock as last resort
            $locked = flock($fp, LOCK_EX);
        }

        if(!$locked){
            fclose($fp);
            return ['success' => false, 'data' => null, 'error' => 'Failed to acquire lock'];
        }

        try {
            // Read current content while holding lock
            clearstatcache(true, $path);
            $size = filesize($path);

            if($size === false || $size === 0){
                $currentData = $default;
            } else {
                rewind($fp);
                $content = fread($fp, $size);
                $currentData = ($content !== false && $content !== '')
                    ? json_decode($content, true)
                    : $default;

                if($currentData === null && $content !== 'null'){
                    $currentData = $default;
                }
            }

            // Apply modification
            $newData = $modifier($currentData);

            // Write modified data
            ftruncate($fp, 0);
            rewind($fp);
            $json = $prettyPrint
                ? json_encode($newData, JSON_PRETTY_PRINT)
                : json_encode($newData);
            $written = fwrite($fp, $json);
            fflush($fp);

            if($written === false){
                return ['success' => false, 'data' => null, 'error' => 'Failed to write data'];
            }

            return ['success' => true, 'data' => $newData, 'error' => null];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // ==========================================
    // SHARDING HELPER METHODS
    // ==========================================

    /**
     * Get the file path for a specific shard
     * @param string $dbname
     * @param int $shardId
     * @return string
     */
    private function getShardPath($dbname, $shardId){
        $dbname = $this->sanitizeDbName($dbname);
        $hash = $this->hashDBName($dbname);
        return $this->dbDir . $hash . "-" . $dbname . "_s" . $shardId . ".nonedb";
    }

    /**
     * Get the meta file path for a database
     * @param string $dbname
     * @return string
     */
    private function getMetaPath($dbname){
        $dbname = $this->sanitizeDbName($dbname);
        $hash = $this->hashDBName($dbname);
        return $this->dbDir . $hash . "-" . $dbname . ".nonedb.meta";
    }

    /**
     * Check if a database is sharded
     * @param string $dbname
     * @return bool
     */
    private function isSharded($dbname){
        $dbname = $this->sanitizeDbName($dbname);

        // Check cache first
        if(isset($this->shardedCache[$dbname])){
            return $this->shardedCache[$dbname];
        }

        // Use file_exists directly - shardedCache handles caching
        $result = file_exists($this->getMetaPath($dbname));
        $this->shardedCache[$dbname] = $result;
        return $result;
    }

    private function invalidateShardedCache($dbname){
        $dbname = $this->sanitizeDbName($dbname);
        unset($this->shardedCache[$dbname]);
    }

    /**
     * Read shard metadata with atomic locking
     * @param string $dbname
     * @return array|null
     */
    private function readMeta($dbname){
        $dbname = $this->sanitizeDbName($dbname);
        $path = $this->getMetaPath($dbname);
        return $this->atomicRead($path, null);
    }

    /**
     * Get cached meta data with TTL support
     * Avoids repeated file reads for frequently accessed meta
     * @param string $dbname
     * @param bool $forceRefresh Force refresh from disk
     * @return array|null
     */
    private function getCachedMeta($dbname, $forceRefresh = false){
        $dbname = $this->sanitizeDbName($dbname);
        $now = time();

        if(!$forceRefresh && isset($this->metaCache[$dbname])){
            $cacheAge = $now - ($this->metaCacheTime[$dbname] ?? 0);
            if($cacheAge < $this->metaCacheTTL){
                return $this->metaCache[$dbname];
            }
        }

        $meta = $this->readMeta($dbname);
        if($meta !== null){
            $this->metaCache[$dbname] = $meta;
            $this->metaCacheTime[$dbname] = $now;
        }
        return $meta;
    }

    /**
     * Invalidate meta cache for a database
     * @param string $dbname
     */
    private function invalidateMetaCache($dbname){
        $dbname = $this->sanitizeDbName($dbname);
        unset($this->metaCache[$dbname]);
        unset($this->metaCacheTime[$dbname]);
    }

    /**
     * Write shard metadata with atomic locking
     * @param string $dbname
     * @param array $meta
     * @return bool
     */
    private function writeMeta($dbname, $meta){
        $dbname = $this->sanitizeDbName($dbname);
        $path = $this->getMetaPath($dbname);
        $result = $this->atomicWrite($path, $meta, true);
        if($result){
            $this->invalidateMetaCache($dbname);
        }
        return $result;
    }

    /**
     * Atomically modify shard metadata
     * @param string $dbname
     * @param callable $modifier
     * @return array ['success' => bool, 'data' => modified meta, 'error' => string|null]
     */
    private function modifyMeta($dbname, callable $modifier){
        $dbname = $this->sanitizeDbName($dbname);
        $path = $this->getMetaPath($dbname);
        $result = $this->atomicModify($path, $modifier, null, true);
        if($result['success']){
            $this->invalidateMetaCache($dbname);
        }
        return $result;
    }

    /**
     * Get data from a specific shard with atomic locking
     * Auto-migrates to JSONL format if needed (v3.0.0)
     * @param string $dbname
     * @param int $shardId
     * @return array Returns {"data": [...]} format for backward compatibility
     */
    private function getShardData($dbname, $shardId){
        $path = $this->getShardPath($dbname, $shardId);

        // Ensure JSONL format (auto-migrate v2 if needed)
        $this->ensureJsonlFormat($dbname, $shardId);

        $jsonlIndex = $this->readJsonlIndex($dbname, $shardId);
        if($jsonlIndex === null){
            return array("data" => []);
        }

        // Read all records from JSONL
        $allRecords = $this->readAllJsonl($path, $jsonlIndex);

        // Convert to {"data": [...]} format where array index is local key
        $data = [];
        foreach($allRecords as $record){
            if($record !== null && isset($record['key'])){
                $localKey = $record['key'] % $this->shardSize;
                $data[$localKey] = $record;
            }
        }
        return array("data" => $data);
    }

    /**
     * Write data to a specific shard with atomic locking
     * @param string $dbname
     * @param int $shardId
     * @param array $data
     * @return bool
     */
    private function writeShardData($dbname, $shardId, $data){
        $path = $this->getShardPath($dbname, $shardId);
        return $this->atomicWrite($path, $data);
    }

    /**
     * Atomically modify shard data
     * @param string $dbname
     * @param int $shardId
     * @param callable $modifier
     * @return array ['success' => bool, 'data' => modified data, 'error' => string|null]
     */
    private function modifyShardData($dbname, $shardId, callable $modifier){
        $path = $this->getShardPath($dbname, $shardId);
        return $this->atomicModify($path, $modifier, array("data" => []));
    }

    // ==========================================
    // PRIMARY KEY INDEX SYSTEM (v2.3.0)
    // ==========================================

    /**
     * Get path to index file for a database
     * Index provides O(1) key lookups instead of O(n) shard scans
     * @param string $dbname
     * @return string
     */
    private function getIndexPath($dbname){
        $dbname = $this->sanitizeDbName($dbname);
        $hash = $this->hashDBName($dbname);
        return $this->dbDir . $hash . "-" . $dbname . ".nonedb.idx";
    }

    /**
     * Read index file with caching
     * @param string $dbname
     * @return array|null
     */
    private function readIndex($dbname){
        $dbname = $this->sanitizeDbName($dbname);

        // Check runtime cache first
        if(isset($this->indexCache[$dbname])){
            return $this->indexCache[$dbname];
        }

        $path = $this->getIndexPath($dbname);
        $index = $this->atomicRead($path, null);

        if($index !== null){
            $this->indexCache[$dbname] = $index;
        }

        return $index;
    }

    /**
     * Write index file and update cache
     * @param string $dbname
     * @param array $index
     * @return bool
     */
    private function writeIndex($dbname, $index){
        $dbname = $this->sanitizeDbName($dbname);
        $index['updated'] = time();
        $path = $this->getIndexPath($dbname);
        $result = $this->atomicWrite($path, $index, false);

        if($result){
            $this->indexCache[$dbname] = $index;
        }

        return $result;
    }

    /**
     * Invalidate index cache
     * @param string $dbname
     */
    private function invalidateIndexCache($dbname){
        $dbname = $this->sanitizeDbName($dbname);
        unset($this->indexCache[$dbname]);
    }

    /**
     * Build index from existing database data
     * Called automatically on first key-based lookup if index doesn't exist
     * @param string $dbname
     * @return array|null
     */
    private function buildIndex($dbname){
        $dbname = $this->sanitizeDbName($dbname);

        $index = [
            'version' => 1,
            'created' => time(),
            'updated' => time(),
            'totalRecords' => 0,
            'entries' => []
        ];

        if($this->isSharded($dbname)){
            $meta = $this->getCachedMeta($dbname);
            if($meta === null){
                return null;
            }

            $index['sharded'] = true;

            foreach($meta['shards'] as $shard){
                $shardData = $this->getShardData($dbname, $shard['id']);
                $baseKey = $shard['id'] * $this->shardSize;

                foreach($shardData['data'] as $localKey => $record){
                    if($record !== null){
                        $globalKey = $baseKey + $localKey;
                        // Store as [shardId, localKey] for sharded DBs
                        $index['entries'][(string)$globalKey] = [$shard['id'], $localKey];
                        $index['totalRecords']++;
                    }
                }
            }
        } else {
            $hash = $this->hashDBName($dbname);
            $fullDBPath = $this->dbDir . $hash . "-" . $dbname . ".nonedb";

            // Ensure JSONL format (auto-migrate v2 if needed)
            $this->ensureJsonlFormat($dbname);

            $jsonlIndex = $this->readJsonlIndex($dbname);
            if($jsonlIndex === null){
                return null;
            }

            $index['sharded'] = false;

            foreach($jsonlIndex['o'] as $key => $location){
                // Store just the key for non-sharded DBs
                $index['entries'][(string)$key] = $key;
                $index['totalRecords']++;
            }
        }

        $this->writeIndex($dbname, $index);
        return $index;
    }

    /**
     * Get existing index or build it if missing
     * @param string $dbname
     * @return array|null
     */
    private function getOrBuildIndex($dbname){
        if(!$this->indexEnabled){
            return null;
        }

        $index = $this->readIndex($dbname);
        if($index === null){
            $index = $this->buildIndex($dbname);
        }
        return $index;
    }

    /**
     * Update index after insert operation
     * @param string $dbname
     * @param array $keys Array of globalKey => localKey (or [shardId, localKey] for sharded)
     * @param int|null $shardId Shard ID for sharded databases
     */
    private function updateIndexOnInsert($dbname, array $keys, $shardId = null){
        if(!$this->indexEnabled){
            return;
        }

        $index = $this->readIndex($dbname);
        if($index === null){
            return; // No index yet, will be built on first read
        }

        $isSharded = $index['sharded'] ?? false;

        foreach($keys as $globalKey => $localKey){
            if($isSharded && $shardId !== null){
                $index['entries'][(string)$globalKey] = [$shardId, $localKey];
            } else {
                $index['entries'][(string)$globalKey] = $localKey;
            }
        }

        $index['totalRecords'] = count($index['entries']);
        $this->writeIndex($dbname, $index);
    }

    /**
     * Update index after delete operation
     * @param string $dbname
     * @param array $deletedKeys Array of deleted global keys
     */
    private function updateIndexOnDelete($dbname, array $deletedKeys){
        if(!$this->indexEnabled){
            return;
        }

        $index = $this->readIndex($dbname);
        if($index === null){
            return;
        }

        foreach($deletedKeys as $key){
            unset($index['entries'][(string)$key]);
        }

        $index['totalRecords'] = count($index['entries']);
        $this->writeIndex($dbname, $index);
    }

    /**
     * Find record by key using index (O(1) lookup)
     * This is the core optimization - avoids loading entire shard
     * @param string $dbname
     * @param mixed $keyFilter Single key or array of keys
     * @param array $index The index data
     * @return array Found records with 'key' field added
     */
    private function findByKeyWithIndex($dbname, $keyFilter, $index){
        $result = [];
        $keys = is_array($keyFilter) ? $keyFilter : [$keyFilter];
        $isSharded = $index['sharded'] ?? false;

        foreach($keys as $globalKey){
            $globalKey = (int)$globalKey;
            $keyStr = (string)$globalKey;

            if(!isset($index['entries'][$keyStr])){
                continue; // Key doesn't exist
            }

            $entry = $index['entries'][$keyStr];

            try {
                if($isSharded){
                    // Entry is [shardId, localKey] - use JSONL direct lookup for O(1)
                    // Note: JSONL shards store global keys, so use globalKey not localKey
                    $shardId = $entry[0];

                    $records = $this->findByKeyJsonl($dbname, $globalKey, $shardId);
                    if($records !== null && !empty($records)){
                        $result = array_merge($result, $records);
                    }
                } else {
                    // Entry is the key - use JSONL direct lookup
                    $records = $this->findByKeyJsonl($dbname, $globalKey);
                    if($records !== null && !empty($records)){
                        $result = array_merge($result, $records);
                    }
                }
            } catch(Exception $e){
                // Index might be corrupted, invalidate it
                $this->invalidateIndexCache($dbname);
                @unlink($this->getIndexPath($dbname));
                return null; // Signal to fall back to full scan
            }
        }

        return $result;
    }

    // ==========================================
    // PUBLIC INDEX API (v2.3.0)
    // ==========================================

    /**
     * Enable or disable indexing
     * @param bool $enable
     */
    public function enableIndexing($enable = true){
        $this->indexEnabled = (bool)$enable;
    }

    /**
     * Check if indexing is enabled
     * @return bool
     */
    public function isIndexingEnabled(){
        return $this->indexEnabled;
    }

    /**
     * Manually rebuild index for a database
     * @param string $dbname
     * @return array ['success' => bool, 'totalRecords' => int, 'time' => float]
     */
    public function rebuildIndex($dbname){
        $start = microtime(true);
        $this->invalidateIndexCache($dbname);
        @unlink($this->getIndexPath($dbname));

        $index = $this->buildIndex($dbname);
        $elapsed = (microtime(true) - $start) * 1000;

        if($index === null){
            return ['success' => false, 'error' => 'Failed to build index'];
        }

        return [
            'success' => true,
            'totalRecords' => $index['totalRecords'],
            'time' => round($elapsed, 2) . 'ms'
        ];
    }

    /**
     * Get index information for a database
     * @param string $dbname
     * @return array|null
     */
    public function getIndexInfo($dbname){
        $index = $this->readIndex($dbname);
        if($index === null){
            return null;
        }

        return [
            'exists' => true,
            'version' => $index['version'] ?? 1,
            'created' => $index['created'] ?? null,
            'updated' => $index['updated'] ?? null,
            'totalRecords' => $index['totalRecords'] ?? 0,
            'sharded' => $index['sharded'] ?? false,
            'path' => $this->getIndexPath($dbname)
        ];
    }

    /**
     * Calculate shard ID from a global key
     * @param int $key
     * @return int
     */
    private function getShardIdForKey($key){
        return (int) floor($key / $this->shardSize);
    }

    /**
     * Calculate local key within a shard
     * @param int $globalKey
     * @return int
     */
    private function getLocalKey($globalKey){
        return $globalKey % $this->shardSize;
    }

    // ==========================================
    // JSONL STORAGE ENGINE (v2.4.0)
    // O(1) key lookups with byte offset indexing
    // ==========================================

    /**
     * Detect if a database file is in JSONL format
     * JSONL: Each line is a JSON object
     * v2: {"data": [...]}
     * @param string $path
     * @return bool True if JSONL format
     */
    private function isJsonlFormat($path){
        // Check cache first (includes file existence)
        if(isset($this->jsonlFormatCache[$path])){
            return $this->jsonlFormatCache[$path];
        }

        if(!$this->cachedFileExists($path)){
            return false;
        }

        $handle = fopen($path, 'rb');
        if($handle === false){
            return false;
        }

        // Read first 20 bytes to detect format
        $header = fread($handle, 20);
        fclose($handle);

        // v2 format starts with {"data":
        // JSONL starts with {"key": or just {" for record
        $isJsonl = (strpos($header, '{"data":') === false && strpos($header, '{"data" :') === false);

        $this->jsonlFormatCache[$path] = $isJsonl;
        return $isJsonl;
    }

    /**
     * Get JSONL index path
     * @param string $dbname
     * @param int|null $shardId Null for non-sharded
     * @return string
     */
    private function getJsonlIndexPath($dbname, $shardId = null){
        $dbname = $this->sanitizeDbName($dbname);
        $hash = $this->hashDBName($dbname);
        if($shardId !== null){
            return $this->dbDir . $hash . "-" . $dbname . "_s" . $shardId . ".nonedb.jidx";
        }
        return $this->dbDir . $hash . "-" . $dbname . ".nonedb.jidx";
    }

    /**
     * Read JSONL index (byte offset map)
     * v3.0.0 optimization: Uses atomicReadFast for better performance
     * @param string $dbname
     * @param int|null $shardId
     * @return array|null
     */
    private function readJsonlIndex($dbname, $shardId = null){
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        $cacheKey = $path;

        // Check cache first
        if(isset($this->indexCache[$cacheKey])){
            return $this->indexCache[$cacheKey];
        }

        // Use fast read for index files (skip clearstatcache + retry loop)
        $index = $this->atomicReadFast($path, null);
        if($index !== null){
            $this->indexCache[$cacheKey] = $index;
        }
        return $index;
    }

    /**
     * Write JSONL index
     * @param string $dbname
     * @param array $index
     * @param int|null $shardId
     * @return bool
     */
    private function writeJsonlIndex($dbname, $index, $shardId = null){
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        $index['updated'] = time();
        $this->indexCache[$path] = $index;
        return $this->atomicWrite($path, $index);
    }

    // ==================== FIELD INDEX METHODS (v3.0.0) ====================

    /**
     * Get field index file path
     * @param string $dbname Database name
     * @param string $field Field name
     * @param int|null $shardId Shard ID or null for non-sharded
     * @return string Path to field index file
     */
    private function getFieldIndexPath($dbname, $field, $shardId = null){
        $hash = $this->hashDBName($dbname);
        $safeField = preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
        if($shardId !== null){
            return $this->dbDir . $hash . "-" . $dbname . "_s" . $shardId . ".nonedb.fidx." . $safeField;
        }
        return $this->dbDir . $hash . "-" . $dbname . ".nonedb.fidx." . $safeField;
    }

    /**
     * Get cache key for field index
     * @param string $dbname Database name
     * @param string $field Field name
     * @param int|null $shardId Shard ID
     * @return string Cache key
     */
    private function getFieldIndexCacheKey($dbname, $field, $shardId = null){
        $key = $dbname . ':' . $field;
        if($shardId !== null){
            $key .= ':s' . $shardId;
        }
        return $key;
    }

    /**
     * Read field index from file
     * @param string $dbname Database name
     * @param string $field Field name
     * @param int|null $shardId Shard ID
     * @return array|null Field index or null if not exists
     */
    private function readFieldIndex($dbname, $field, $shardId = null){
        $cacheKey = $this->getFieldIndexCacheKey($dbname, $field, $shardId);

        if(isset($this->fieldIndexCache[$cacheKey])){
            return $this->fieldIndexCache[$cacheKey];
        }

        $path = $this->getFieldIndexPath($dbname, $field, $shardId);
        if(!file_exists($path)){
            return null;
        }

        $index = $this->atomicRead($path, null);
        if($index !== null){
            $this->fieldIndexCache[$cacheKey] = $index;
        }
        return $index;
    }

    /**
     * Write field index to file
     * @param string $dbname Database name
     * @param string $field Field name
     * @param array $index Field index data
     * @param int|null $shardId Shard ID
     * @return bool Success
     */
    private function writeFieldIndex($dbname, $field, $index, $shardId = null){
        $path = $this->getFieldIndexPath($dbname, $field, $shardId);
        $cacheKey = $this->getFieldIndexCacheKey($dbname, $field, $shardId);

        $index['updated'] = time();
        $this->fieldIndexCache[$cacheKey] = $index;
        $this->markFileExists($path);

        return $this->atomicWrite($path, $index);
    }

    /**
     * Delete field index file
     * @param string $dbname Database name
     * @param string $field Field name
     * @param int|null $shardId Shard ID
     * @return bool Success
     */
    private function deleteFieldIndexFile($dbname, $field, $shardId = null){
        $path = $this->getFieldIndexPath($dbname, $field, $shardId);
        $cacheKey = $this->getFieldIndexCacheKey($dbname, $field, $shardId);

        unset($this->fieldIndexCache[$cacheKey]);
        $this->markFileNotExists($path);

        if(file_exists($path)){
            return @unlink($path);
        }
        return true;
    }

    /**
     * Get list of indexed fields for a database
     * @param string $dbname Database name
     * @param int|null $shardId Shard ID
     * @return array List of field names that have indexes
     */
    private function getIndexedFields($dbname, $shardId = null){
        $hash = $this->hashDBName($dbname);
        $pattern = $this->dbDir . $hash . "-" . $dbname;
        if($shardId !== null){
            $pattern .= "_s" . $shardId;
        }
        $pattern .= ".nonedb.fidx.*";

        $files = glob($pattern);
        $fields = [];
        foreach($files as $file){
            // Extract field name from path
            if(preg_match('/\.fidx\.([^\/]+)$/', $file, $matches)){
                $fields[] = $matches[1];
            }
        }
        return $fields;
    }

    /**
     * Check if a field has an index
     * @param string $dbname Database name
     * @param string $field Field name
     * @param int|null $shardId Shard ID
     * @return bool True if index exists
     */
    private function hasFieldIndex($dbname, $field, $shardId = null){
        $path = $this->getFieldIndexPath($dbname, $field, $shardId);
        return file_exists($path);
    }

    /**
     * Invalidate field index cache for a database
     * @param string $dbname Database name
     * @param string|null $field Specific field or null for all fields
     * @param int|null $shardId Shard ID
     */
    private function invalidateFieldIndexCache($dbname, $field = null, $shardId = null){
        if($field !== null){
            $cacheKey = $this->getFieldIndexCacheKey($dbname, $field, $shardId);
            unset($this->fieldIndexCache[$cacheKey]);
        } else {
            // Invalidate all field indexes for this database
            $prefix = $dbname . ':';
            foreach(array_keys($this->fieldIndexCache) as $key){
                if(strpos($key, $prefix) === 0){
                    unset($this->fieldIndexCache[$key]);
                }
            }
        }
    }

    // ==================== GLOBAL FIELD INDEX METHODS (Shard Skip) ====================

    /**
     * Get path for global field index file
     * @param string $dbname Database name
     * @param string $field Field name
     * @return string Path to global field index file
     */
    private function getGlobalFieldIndexPath($dbname, $field){
        $hash = $this->hashDBName($dbname);
        $safeField = preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
        return $this->dbDir . $hash . "-" . $dbname . ".nonedb.gfidx." . $safeField;
    }

    /**
     * Get cache key for global field index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return string Cache key
     */
    private function getGlobalFieldIndexCacheKey($dbname, $field){
        return 'gfidx:' . $dbname . ':' . $field;
    }

    /**
     * Read global field index (with static cache)
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array|null Index data or null if not exists
     */
    private function readGlobalFieldIndex($dbname, $field){
        $cacheKey = $this->getGlobalFieldIndexCacheKey($dbname, $field);

        // Check static cache
        if(isset($this->fieldIndexCache[$cacheKey])){
            return $this->fieldIndexCache[$cacheKey];
        }

        $path = $this->getGlobalFieldIndexPath($dbname, $field);
        if(!$this->cachedFileExists($path)){
            return null;
        }

        $content = file_get_contents($path);
        if($content === false){
            return null;
        }

        $index = json_decode($content, true);
        if($index === null){
            return null;
        }

        // Cache it
        $this->fieldIndexCache[$cacheKey] = $index;
        return $index;
    }

    /**
     * Write global field index
     * @param string $dbname Database name
     * @param string $field Field name
     * @param array $metadata Index metadata
     * @return bool Success
     */
    private function writeGlobalFieldIndex($dbname, $field, $metadata){
        $path = $this->getGlobalFieldIndexPath($dbname, $field);
        $metadata['updated'] = time();

        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = file_put_contents($path, $json, LOCK_EX);

        if($result !== false){
            // Update cache
            $cacheKey = $this->getGlobalFieldIndexCacheKey($dbname, $field);
            $this->fieldIndexCache[$cacheKey] = $metadata;
            return true;
        }
        return false;
    }

    /**
     * Check if global field index exists
     * @param string $dbname Database name
     * @param string $field Field name
     * @return bool True if exists
     */
    private function hasGlobalFieldIndex($dbname, $field){
        $path = $this->getGlobalFieldIndexPath($dbname, $field);
        return $this->cachedFileExists($path);
    }

    /**
     * Get target shards from global field index
     * @param string $dbname Database name
     * @param string $field Field name
     * @param mixed $value Field value to search
     * @return array|null Array of shard IDs or null if no global index
     */
    private function getTargetShardsFromGlobalIndex($dbname, $field, $value){
        $globalMeta = $this->readGlobalFieldIndex($dbname, $field);
        if($globalMeta === null || !isset($globalMeta['shardMap'])){
            return null;
        }

        $valueKey = $this->fieldIndexValueKey($value);
        return $globalMeta['shardMap'][$valueKey] ?? [];
    }

    /**
     * Add shard to global field index for a value
     * @param string $dbname Database name
     * @param string $field Field name
     * @param mixed $value Field value
     * @param int $shardId Shard ID to add
     */
    private function addShardToGlobalIndex($dbname, $field, $value, $shardId){
        $globalMeta = $this->readGlobalFieldIndex($dbname, $field);
        if($globalMeta === null){
            return; // No global index exists
        }

        $valueKey = $this->fieldIndexValueKey($value);

        if(!isset($globalMeta['shardMap'][$valueKey])){
            $globalMeta['shardMap'][$valueKey] = [];
        }

        if(!in_array($shardId, $globalMeta['shardMap'][$valueKey])){
            $globalMeta['shardMap'][$valueKey][] = $shardId;
            $this->writeGlobalFieldIndex($dbname, $field, $globalMeta);
        }
    }

    /**
     * Remove shard from global field index for a value (if no more records)
     * @param string $dbname Database name
     * @param string $field Field name
     * @param mixed $value Field value
     * @param int $shardId Shard ID to potentially remove
     */
    private function removeShardFromGlobalIndex($dbname, $field, $value, $shardId){
        $globalMeta = $this->readGlobalFieldIndex($dbname, $field);
        if($globalMeta === null){
            return;
        }

        $valueKey = $this->fieldIndexValueKey($value);

        if(!isset($globalMeta['shardMap'][$valueKey])){
            return;
        }

        // Check if this shard still has records with this value
        $fieldIndex = $this->readFieldIndex($dbname, $field, $shardId);
        if($fieldIndex !== null && isset($fieldIndex['values'][$valueKey]) && !empty($fieldIndex['values'][$valueKey])){
            return; // Still has records, don't remove
        }

        // Remove shard from this value's shard list
        $globalMeta['shardMap'][$valueKey] = array_values(
            array_filter($globalMeta['shardMap'][$valueKey], function($id) use ($shardId){
                return $id !== $shardId;
            })
        );

        // Remove empty value entries
        if(empty($globalMeta['shardMap'][$valueKey])){
            unset($globalMeta['shardMap'][$valueKey]);
        }

        $this->writeGlobalFieldIndex($dbname, $field, $globalMeta);
    }

    /**
     * Delete global field index file
     * @param string $dbname Database name
     * @param string $field Field name
     */
    private function deleteGlobalFieldIndex($dbname, $field){
        $path = $this->getGlobalFieldIndexPath($dbname, $field);
        if(file_exists($path)){
            @unlink($path);
        }
        // Clear cache
        $cacheKey = $this->getGlobalFieldIndexCacheKey($dbname, $field);
        unset($this->fieldIndexCache[$cacheKey]);
    }

    // ==================== END FIELD INDEX METHODS ====================

    /**
     * Migrate v2 format to JSONL format
     * @param string $path Source file path
     * @param string $dbname Database name
     * @param int|null $shardId Shard ID or null for non-sharded
     * @return bool Success
     */
    private function migrateToJsonl($path, $dbname, $shardId = null){
        if(!$this->cachedFileExists($path)){
            return false;
        }

        // Read v2 format
        $content = file_get_contents($path);
        if($content === false){
            return false;
        }

        $data = json_decode($content, true);
        if(!isset($data['data']) || !is_array($data['data'])){
            return false;
        }

        // Create JSONL format with byte offset index
        $tempPath = $path . '.jsonl.tmp';
        $handle = fopen($tempPath, 'wb');
        if($handle === false){
            return false;
        }

        // Acquire exclusive lock
        if(!flock($handle, LOCK_EX)){
            fclose($handle);
            @unlink($tempPath);
            return false;
        }

        $index = [
            'v' => 3,
            'format' => 'jsonl',
            'created' => time(),
            'n' => 0,
            'd' => 0,
            'o' => []
        ];

        $offset = 0;
        $baseKey = ($shardId !== null) ? ($shardId * $this->shardSize) : 0;

        foreach($data['data'] as $localKey => $record){
            if($record === null){
                $index['d']++;
                continue;
            }

            $globalKey = $baseKey + $localKey;
            $record['key'] = $globalKey;
            $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $length = strlen($json) - 1; // Exclude newline

            fwrite($handle, $json);

            $index['o'][$globalKey] = [$offset, $length];
            $offset += strlen($json);
            $index['n']++;
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        // Atomic swap
        if(!rename($tempPath, $path)){
            @unlink($tempPath);
            return false;
        }

        // Clear format cache
        unset($this->jsonlFormatCache[$path]);
        $this->jsonlFormatCache[$path] = true;

        // Write index
        $this->writeJsonlIndex($dbname, $index, $shardId);

        return true;
    }

    /**
     * Read single record from JSONL file using byte offset
     * O(1) complexity
     * @param string $path File path
     * @param int $offset Byte offset
     * @param int $length Byte length
     * @return array|null
     */
    private function readJsonlRecord($path, $offset, $length){
        $handle = fopen($path, 'rb');
        if($handle === false){
            return null;
        }

        // Acquire shared lock
        if(!flock($handle, LOCK_SH)){
            fclose($handle);
            return null;
        }

        fseek($handle, $offset, SEEK_SET);
        $json = fread($handle, $length);

        flock($handle, LOCK_UN);
        fclose($handle);

        if($json === false){
            return null;
        }

        return json_decode($json, true);
    }

    /**
     * Batch read multiple JSONL records efficiently - v3.0.0
     * Opens file once and uses buffered reading for better performance
     * @param string $path File path
     * @param array $offsets Array of [key => [offset, length], ...]
     * @return array Array of [key => record, ...]
     */
    private function readJsonlRecordsBatch($path, $offsets){
        if(empty($offsets)){
            return [];
        }

        $handle = fopen($path, 'rb');
        if($handle === false){
            return [];
        }

        // Acquire shared lock
        if(!flock($handle, LOCK_SH)){
            fclose($handle);
            return [];
        }

        $records = [];
        $bufferSize = 65536;  // 64KB buffer
        $buffer = '';
        $bufferStart = -1;
        $bufferEnd = -1;

        // Sort offsets by position to minimize disk seeks
        $sortedOffsets = $offsets;
        uasort($sortedOffsets, function($a, $b){
            return $a[0] - $b[0];
        });

        foreach($sortedOffsets as $key => $location){
            $offset = $location[0];
            $length = $location[1];

            // Check if data is in current buffer
            if($offset >= $bufferStart && ($offset + $length) <= $bufferEnd){
                // Read from buffer
                $localOffset = $offset - $bufferStart;
                $json = substr($buffer, $localOffset, $length);
            } else {
                // Need to read from file
                fseek($handle, $offset, SEEK_SET);

                // Read enough data (at least the record, preferably more for next records)
                $readSize = max($length, $bufferSize);
                $buffer = fread($handle, $readSize);
                $bufferStart = $offset;
                $bufferEnd = $offset + strlen($buffer);

                // Extract the record
                $json = substr($buffer, 0, $length);
            }

            if($json !== false){
                $record = json_decode($json, true);
                if($record !== null){
                    $records[$key] = $record;
                }
            }
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        return $records;
    }

    /**
     * Find by key using JSONL index - O(1)
     * @param string $dbname
     * @param int|array $keys
     * @param int|null $shardId
     * @return array|null
     */
    private function findByKeyJsonl($dbname, $keys, $shardId = null){
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null || !isset($index['o'])){
            return null;
        }

        if($shardId !== null){
            $path = $this->getShardPath($dbname, $shardId);
        } else {
            $hash = $this->hashDBName($dbname);
            $path = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
        }

        $keys = is_array($keys) ? $keys : [$keys];

        // Collect offsets for requested keys
        $offsets = [];
        foreach($keys as $key){
            $key = (int)$key;
            if(isset($index['o'][$key])){
                $offsets[$key] = $index['o'][$key];
            }
        }

        if(empty($offsets)){
            return [];
        }

        // Single key: use simple read (no batch overhead)
        if(count($offsets) === 1){
            $key = array_key_first($offsets);
            [$offset, $length] = $offsets[$key];
            $record = $this->readJsonlRecord($path, $offset, $length);
            return $record !== null ? [$record] : [];
        }

        // Multiple keys: use batch read for efficiency (v3.0.0)
        $records = $this->readJsonlRecordsBatch($path, $offsets);

        // Maintain original key order
        $result = [];
        foreach($keys as $key){
            $key = (int)$key;
            if(isset($records[$key])){
                $result[] = $records[$key];
            }
        }

        return $result;
    }

    /**
     * Read all records from JSONL file (streaming)
     * Memory efficient for large files
     * @param string $path
     * @param array|null $index Optional index to filter valid records
     * @return array
     */
    private function readAllJsonl($path, $index = null){
        // If index provided, use batch read for better performance (v3.0.0)
        if($index !== null && isset($index['o'])){
            // Use batch read for efficiency (single file open, buffered reads)
            $records = $this->readJsonlRecordsBatch($path, $index['o']);

            // Sort by key and return as indexed array
            ksort($records, SORT_NUMERIC);
            return array_values($records);
        }

        // Fallback: scan all lines (no index)
        $handle = fopen($path, 'rb');
        if($handle === false){
            return [];
        }

        if(!flock($handle, LOCK_SH)){
            fclose($handle);
            return [];
        }

        $results = [];
        while(($line = fgets($handle)) !== false){
            $line = rtrim($line, "\n\r");
            if(empty($line)){
                continue;
            }
            $record = json_decode($line, true);
            if($record === null){
                continue;
            }
            $results[] = $record;
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        return $results;
    }

    /**
     * Append record to JSONL file
     * @param string $path
     * @param array $record
     * @param array &$index Reference to index for updating
     * @return int|false New key or false on failure
     */
    private function appendJsonlRecord($path, $record, &$index){
        clearstatcache(true, $path);
        $offset = file_exists($path) ? filesize($path) : 0;

        $key = $index['n'];
        $record['key'] = $key;
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $length = strlen($json) - 1;

        // Append with exclusive lock
        $result = file_put_contents($path, $json, FILE_APPEND | LOCK_EX);
        if($result === false){
            return false;
        }

        $index['o'][$key] = [$offset, $length];
        $index['n']++;

        return $key;
    }

    /**
     * Bulk append records to JSONL file
     * @param string $path
     * @param array $records
     * @param array &$index Reference to index
     * @return array Keys of inserted records
     */
    private function bulkAppendJsonl($path, $records, &$index){
        clearstatcache(true, $path);
        $offset = file_exists($path) ? filesize($path) : 0;

        $buffer = '';
        $keys = [];

        foreach($records as $record){
            $key = $index['n'];
            $record['key'] = $key;
            $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $length = strlen($json) - 1;

            $index['o'][$key] = [$offset, $length];
            $offset += strlen($json);
            $index['n']++;

            $buffer .= $json;
            $keys[] = $key;
        }

        // Single write for all records
        file_put_contents($path, $buffer, FILE_APPEND | LOCK_EX);

        return $keys;
    }

    /**
     * Update record in JSONL (append new version, mark old as garbage)
     * @param string $dbname
     * @param int $key
     * @param array $newData
     * @param int|null $shardId
     * @param bool $skipCompaction Skip auto-compaction (for batch operations)
     * @return bool
     */
    private function updateJsonlRecord($dbname, $key, $newData, $shardId = null, $skipCompaction = false){
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null || !isset($index['o'][$key])){
            return false;
        }

        if($shardId !== null){
            $path = $this->getShardPath($dbname, $shardId);
        } else {
            $hash = $this->hashDBName($dbname);
            $path = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
        }

        // Read old record for field index update
        $oldRecord = null;
        if($this->fieldIndexEnabled){
            $location = $index['o'][$key];
            $oldRecord = $this->readJsonlRecord($path, $location[0], $location[1]);
        }

        clearstatcache(true, $path);
        $offset = filesize($path);

        $newData['key'] = $key;
        $json = json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $length = strlen($json) - 1;

        $result = file_put_contents($path, $json, FILE_APPEND | LOCK_EX);
        if($result === false){
            return false;
        }

        // Old record becomes garbage
        $index['o'][$key] = [$offset, $length];
        $index['d']++;

        $this->writeJsonlIndex($dbname, $index, $shardId);

        // Update field indexes
        if($this->fieldIndexEnabled && $oldRecord !== null){
            $this->updateFieldIndexOnUpdate($dbname, $oldRecord, $newData, $key, $shardId);
        }

        // Check if compaction needed (skip during batch operations)
        if(!$skipCompaction && $index['d'] > $index['n'] * $this->jsonlGarbageThreshold){
            $this->compactJsonl($dbname, $shardId);
        }

        return true;
    }

    /**
     * Batch update multiple JSONL records - single index write for performance
     * @param string $dbname
     * @param array $updates Array of ['key' => int, 'data' => array]
     * @param int|null $shardId
     * @return int Number of updated records
     */
    private function updateJsonlRecordsBatch($dbname, array $updates, $shardId = null){
        if(empty($updates)){
            return 0;
        }

        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null){
            return 0;
        }

        if($shardId !== null){
            $path = $this->getShardPath($dbname, $shardId);
        } else {
            $hash = $this->hashDBName($dbname);
            $path = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
        }

        // Build all data to append in one buffer
        clearstatcache(true, $path);
        $offset = file_exists($path) ? filesize($path) : 0;
        $buffer = '';
        $indexUpdates = [];
        $updated = 0;

        foreach($updates as $item){
            $key = $item['key'];
            $newData = $item['data'];

            if(!isset($index['o'][$key])){
                continue;
            }

            // Read old record for field index update
            if($this->fieldIndexEnabled){
                $location = $index['o'][$key];
                $oldRecord = $this->readJsonlRecord($path, $location[0], $location[1]);
                if($oldRecord !== null){
                    $this->updateFieldIndexOnUpdate($dbname, $oldRecord, $newData, $key, $shardId);
                }
            }

            $newData['key'] = $key;
            $json = json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $length = strlen($json) - 1;

            $indexUpdates[$key] = [$offset, $length];
            $buffer .= $json;
            $offset += strlen($json);
            $index['d']++;
            $updated++;
        }

        // Single file write for all records
        if(!empty($buffer)){
            $result = file_put_contents($path, $buffer, FILE_APPEND | LOCK_EX);
            if($result === false){
                return 0;
            }
        }

        // Update index with new offsets
        foreach($indexUpdates as $key => $location){
            $index['o'][$key] = $location;
        }

        // Single index write
        $this->writeJsonlIndex($dbname, $index, $shardId);

        // Check if compaction needed
        if($index['d'] > $index['n'] * $this->jsonlGarbageThreshold){
            $this->compactJsonl($dbname, $shardId);
        }

        return $updated;
    }

    /**
     * Delete record from JSONL (just remove from index)
     * @param string $dbname
     * @param int $key
     * @param int|null $shardId
     * @return bool
     */
    private function deleteJsonlRecord($dbname, $key, $shardId = null){
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null || !isset($index['o'][$key])){
            return false;
        }

        // Read record for field index update before deletion
        $record = null;
        if($this->fieldIndexEnabled){
            if($shardId !== null){
                $path = $this->getShardPath($dbname, $shardId);
            } else {
                $hash = $this->hashDBName($dbname);
                $path = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
            }
            $location = $index['o'][$key];
            $record = $this->readJsonlRecord($path, $location[0], $location[1]);
        }

        unset($index['o'][$key]);
        $index['d']++;

        $this->writeJsonlIndex($dbname, $index, $shardId);

        // Update field indexes
        if($this->fieldIndexEnabled && $record !== null){
            $this->updateFieldIndexOnDelete($dbname, $record, $key, $shardId);
        }

        // Check if compaction needed
        if($index['d'] > $index['n'] * $this->jsonlGarbageThreshold){
            $this->compactJsonl($dbname, $shardId);
        }

        return true;
    }

    /**
     * Batch delete multiple JSONL records - single index write for performance
     * @param string $dbname
     * @param array $keys Array of keys to delete
     * @param int|null $shardId
     * @return int Number of deleted records
     */
    private function deleteJsonlRecordsBatch($dbname, array $keys, $shardId = null){
        if(empty($keys)){
            return 0;
        }

        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null){
            return 0;
        }

        // Get path for field index updates
        $path = null;
        if($this->fieldIndexEnabled){
            if($shardId !== null){
                $path = $this->getShardPath($dbname, $shardId);
            } else {
                $hash = $this->hashDBName($dbname);
                $path = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
            }
        }

        $deleted = 0;
        foreach($keys as $key){
            if(!isset($index['o'][$key])){
                continue;
            }

            // Read record for field index update before deletion
            if($this->fieldIndexEnabled && $path !== null){
                $location = $index['o'][$key];
                $record = $this->readJsonlRecord($path, $location[0], $location[1]);
                if($record !== null){
                    $this->updateFieldIndexOnDelete($dbname, $record, $key, $shardId);
                }
            }

            unset($index['o'][$key]);
            $index['d']++;
            $deleted++;
        }

        // Single index write for all deletions
        $this->writeJsonlIndex($dbname, $index, $shardId);

        // Check if compaction needed
        if($index['d'] > $index['n'] * $this->jsonlGarbageThreshold){
            $this->compactJsonl($dbname, $shardId);
        }

        return $deleted;
    }

    /**
     * Compact JSONL file (remove garbage)
     * @param string $dbname
     * @param int|null $shardId
     * @return array ['compacted' => int, 'freed' => int]
     */
    private function compactJsonl($dbname, $shardId = null){
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null){
            return ['compacted' => 0, 'freed' => 0];
        }

        if($shardId !== null){
            $path = $this->getShardPath($dbname, $shardId);
        } else {
            $hash = $this->hashDBName($dbname);
            $path = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
        }

        $tempPath = $path . '.compact.tmp';
        $handle = fopen($path, 'rb');
        $tempHandle = fopen($tempPath, 'wb');

        if($handle === false || $tempHandle === false){
            if($handle) fclose($handle);
            if($tempHandle) fclose($tempHandle);
            return ['compacted' => 0, 'freed' => 0];
        }

        flock($handle, LOCK_SH);
        flock($tempHandle, LOCK_EX);

        $newIndex = [
            'v' => 3,
            'format' => 'jsonl',
            'created' => $index['created'] ?? time(),
            'n' => $index['n'],  // Preserve next key counter (don't reset!)
            'd' => 0,
            'o' => []
        ];

        $offset = 0;
        $compacted = 0;

        // Sort keys for sequential read
        $sortedKeys = array_keys($index['o']);
        sort($sortedKeys, SORT_NUMERIC);

        foreach($sortedKeys as $key){
            [$oldOffset, $length] = $index['o'][$key];

            fseek($handle, $oldOffset);
            $json = fread($handle, $length);

            fwrite($tempHandle, $json . "\n");

            $newIndex['o'][$key] = [$offset, $length];
            $offset += $length + 1;
            $compacted++;
        }

        $freed = $index['d'];

        flock($handle, LOCK_UN);
        flock($tempHandle, LOCK_UN);
        fclose($handle);
        fclose($tempHandle);

        // Atomic swap
        rename($tempPath, $path);

        // Update index
        $this->writeJsonlIndex($dbname, $newIndex, $shardId);

        return ['compacted' => $compacted, 'freed' => $freed];
    }

    /**
     * Ensure database is in JSONL format (auto-migrate if needed)
     * @param string $dbname
     * @param int|null $shardId
     * @return bool True if JSONL format (or migrated), false otherwise
     */
    private function ensureJsonlFormat($dbname, $shardId = null){
        if($shardId !== null){
            $path = $this->getShardPath($dbname, $shardId);
        } else {
            $hash = $this->hashDBName($dbname);
            $path = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
        }

        if(!$this->cachedFileExists($path)){
            return true; // New file will be created in JSONL format
        }

        if($this->isJsonlFormat($path)){
            return true;
        }

        // Auto-migrate v2 format to JSONL
        return $this->migrateToJsonl($path, $dbname, $shardId);
    }

    /**
     * Create new JSONL database file with empty index
     * @param string $dbname
     * @param int|null $shardId
     * @return bool
     */
    private function createJsonlDatabase($dbname, $shardId = null){
        if($shardId !== null){
            $path = $this->getShardPath($dbname, $shardId);
        } else {
            $hash = $this->hashDBName($dbname);
            $path = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
        }

        // Create empty file
        if(!$this->cachedFileExists($path)){
            touch($path);
            $this->markFileExists($path);
        }

        // Create index
        $index = [
            'v' => 3,
            'format' => 'jsonl',
            'created' => time(),
            'n' => 0,
            'd' => 0,
            'o' => []
        ];

        $this->writeJsonlIndex($dbname, $index, $shardId);
        $this->jsonlFormatCache[$path] = true;

        return true;
    }

    // ==========================================
    // WRITE BUFFER METHODS
    // ==========================================

    /**
     * Get buffer file path for non-sharded database
     * @param string $dbname
     * @return string
     */
    private function getBufferPath($dbname){
        $dbname = $this->sanitizeDbName($dbname);
        $hash = $this->hashDBName($dbname);
        return $this->dbDir . $hash . "-" . $dbname . ".nonedb.buffer";
    }

    /**
     * Get buffer file path for a specific shard
     * @param string $dbname
     * @param int $shardId
     * @return string
     */
    private function getShardBufferPath($dbname, $shardId){
        $dbname = $this->sanitizeDbName($dbname);
        $hash = $this->hashDBName($dbname);
        return $this->dbDir . $hash . "-" . $dbname . "_s" . $shardId . ".nonedb.buffer";
    }

    /**
     * Check if buffer exists and has content
     * @param string $bufferPath
     * @return bool
     */
    private function hasBuffer($bufferPath){
        clearstatcache(true, $bufferPath);
        return file_exists($bufferPath) && filesize($bufferPath) > 0;
    }

    /**
     * Get buffer file size in bytes
     * @param string $bufferPath
     * @return int
     */
    private function getBufferSize($bufferPath){
        clearstatcache(true, $bufferPath);
        if(!file_exists($bufferPath)){
            return 0;
        }
        return (int) filesize($bufferPath);
    }

    /**
     * Count records in buffer file
     * @param string $bufferPath
     * @return int
     */
    private function getBufferRecordCount($bufferPath){
        if(!$this->hasBuffer($bufferPath)){
            return 0;
        }
        $count = 0;
        $fp = fopen($bufferPath, 'rb');
        if($fp === false){
            return 0;
        }
        // Lock for reading
        flock($fp, LOCK_SH);
        while(($line = fgets($fp)) !== false){
            $line = trim($line);
            if($line !== ''){
                $count++;
            }
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return $count;
    }

    /**
     * Atomically append records to buffer file (JSONL format)
     * This is fast because it doesn't read the entire file
     *
     * @param string $bufferPath
     * @param array $records Array of records to append
     * @return array ['success' => bool, 'count' => int, 'error' => string|null]
     */
    private function atomicAppendToBuffer($bufferPath, array $records){
        if(empty($records)){
            return ['success' => true, 'count' => 0, 'error' => null];
        }

        // Ensure directory exists
        $dir = dirname($bufferPath);
        if(!is_dir($dir)){
            mkdir($dir, 0755, true);
        }

        // Open in append mode
        $fp = fopen($bufferPath, 'ab');
        if($fp === false){
            return ['success' => false, 'count' => 0, 'error' => 'Failed to open buffer file'];
        }

        $startTime = microtime(true);
        $locked = false;

        // Try to acquire exclusive lock with timeout
        while(!$locked && (microtime(true) - $startTime) < $this->lockTimeout){
            $locked = flock($fp, LOCK_EX | LOCK_NB);
            if(!$locked){
                usleep($this->lockRetryDelay);
            }
        }

        if(!$locked){
            $locked = flock($fp, LOCK_EX);
        }

        if(!$locked){
            fclose($fp);
            return ['success' => false, 'count' => 0, 'error' => 'Failed to acquire lock'];
        }

        try {
            $written = 0;
            foreach($records as $record){
                $line = json_encode($record) . "\n";
                if(fwrite($fp, $line) !== false){
                    $written++;
                }
            }
            fflush($fp);
            return ['success' => true, 'count' => $written, 'error' => null];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Read all records from buffer file (JSONL format)
     * @param string $bufferPath
     * @return array Array of records
     */
    private function readBufferRecords($bufferPath){
        if(!$this->hasBuffer($bufferPath)){
            return [];
        }

        $fp = fopen($bufferPath, 'rb');
        if($fp === false){
            return [];
        }

        $startTime = microtime(true);
        $locked = false;

        while(!$locked && (microtime(true) - $startTime) < $this->lockTimeout){
            $locked = flock($fp, LOCK_SH | LOCK_NB);
            if(!$locked){
                usleep($this->lockRetryDelay);
            }
        }

        if(!$locked){
            $locked = flock($fp, LOCK_SH);
        }

        if(!$locked){
            fclose($fp);
            return [];
        }

        $records = [];
        try {
            while(($line = fgets($fp)) !== false){
                $line = trim($line);
                if($line !== ''){
                    $record = json_decode($line, true);
                    if($record !== null && json_last_error() === JSON_ERROR_NONE){
                        $records[] = $record;
                    }
                    // Skip corrupted lines silently
                }
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return $records;
    }

    /**
     * Clear buffer file (delete it)
     * @param string $bufferPath
     * @return bool
     */
    private function clearBuffer($bufferPath){
        clearstatcache(true, $bufferPath);
        if(file_exists($bufferPath)){
            return @unlink($bufferPath);
        }
        return true;
    }

    /**
     * Flush buffer to main database (non-sharded)
     * @param string $dbname
     * @return array ['success' => bool, 'flushed' => int, 'error' => string|null]
     */
    private function flushBufferToMain($dbname){
        $dbname = $this->sanitizeDbName($dbname);
        $bufferPath = $this->getBufferPath($dbname);

        if(!$this->hasBuffer($bufferPath)){
            return ['success' => true, 'flushed' => 0, 'error' => null];
        }

        // Read buffer records
        $bufferRecords = $this->readBufferRecords($bufferPath);
        if(empty($bufferRecords)){
            $this->clearBuffer($bufferPath);
            return ['success' => true, 'flushed' => 0, 'error' => null];
        }

        // Rename buffer to temp file (atomic on POSIX)
        $tempPath = $bufferPath . '.flushing';
        if(!@rename($bufferPath, $tempPath)){
            return ['success' => false, 'flushed' => 0, 'error' => 'Failed to rename buffer'];
        }

        // Get main DB path
        $hash = $this->hashDBName($dbname);
        $mainPath = $this->dbDir . $hash . "-" . $dbname . ".nonedb";

        // Ensure JSONL format exists (auto-migrate v2 if needed)
        if(!$this->ensureJsonlFormat($dbname)){
            $this->createJsonlDatabase($dbname);
        }

        $index = $this->readJsonlIndex($dbname);
        if($index === null){
            @rename($tempPath, $bufferPath);
            return ['success' => false, 'flushed' => 0, 'error' => 'Failed to read index'];
        }

        // Bulk append buffer records
        $keys = $this->bulkAppendJsonl($mainPath, $bufferRecords, $index);
        $this->writeJsonlIndex($dbname, $index);

        // Update field indexes for flushed records
        if($this->fieldIndexEnabled){
            foreach($bufferRecords as $i => $record){
                $this->updateFieldIndexOnInsert($dbname, $record, $keys[$i], null);
            }
        }

        // Delete temp file
        @unlink($tempPath);
        $this->bufferLastFlush[$dbname] = time();
        return ['success' => true, 'flushed' => count($bufferRecords), 'error' => null];
    }

    /**
     * Flush buffer to shard
     * @param string $dbname
     * @param int $shardId
     * @return array ['success' => bool, 'flushed' => int, 'error' => string|null]
     */
    private function flushShardBuffer($dbname, $shardId){
        $dbname = $this->sanitizeDbName($dbname);
        $bufferPath = $this->getShardBufferPath($dbname, $shardId);

        if(!$this->hasBuffer($bufferPath)){
            return ['success' => true, 'flushed' => 0, 'error' => null];
        }

        $bufferRecords = $this->readBufferRecords($bufferPath);
        if(empty($bufferRecords)){
            $this->clearBuffer($bufferPath);
            return ['success' => true, 'flushed' => 0, 'error' => null];
        }

        // Rename buffer to temp
        $tempPath = $bufferPath . '.flushing';
        if(!@rename($bufferPath, $tempPath)){
            return ['success' => false, 'flushed' => 0, 'error' => 'Failed to rename buffer'];
        }

        // v3.0.0: Use JSONL format for sharded writes
        $shardPath = $this->getShardPath($dbname, $shardId);

        // Ensure JSONL format exists
        if(!$this->cachedFileExists($shardPath)){
            $this->createJsonlDatabase($dbname, $shardId);
        } else if(!$this->isJsonlFormat($shardPath)){
            // Migrate existing JSON to JSONL
            $this->migrateToJsonl($shardPath, $dbname, $shardId);
        }

        // Read current JSONL index
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null){
            $index = [
                'v' => 3,
                'format' => 'jsonl',
                'created' => time(),
                'n' => 0,
                'd' => 0,
                'o' => []
            ];
        }

        // Calculate base key for this shard
        $baseKey = $shardId * $this->shardSize;

        // Bulk append records to JSONL file using global keys
        $insertedKeys = [];
        clearstatcache(true, $shardPath);
        $offset = file_exists($shardPath) ? filesize($shardPath) : 0;
        $buffer = '';

        foreach($bufferRecords as $record){
            // Use global key: baseKey + local position within shard
            $localKey = $index['n'];
            $globalKey = $baseKey + $localKey;
            $record['key'] = $globalKey;

            $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $length = strlen($json) - 1;

            $index['o'][$globalKey] = [$offset, $length];
            $offset += strlen($json);
            $index['n']++;

            $buffer .= $json;
            $insertedKeys[] = $globalKey;
        }

        // Single write for all records
        $result = file_put_contents($shardPath, $buffer, FILE_APPEND | LOCK_EX);

        if($result !== false){

            // Write updated JSONL index
            $this->writeJsonlIndex($dbname, $index, $shardId);

            // Update field indexes for flushed records (with shardId for global index)
            if($this->fieldIndexEnabled){
                foreach($bufferRecords as $i => $record){
                    $globalKey = $insertedKeys[$i];
                    $this->updateFieldIndexOnInsert($dbname, $record, $globalKey, $shardId);
                }
            }

            @unlink($tempPath);
            $flushKey = $dbname . '_s' . $shardId;
            $this->bufferLastFlush[$flushKey] = time();
            return ['success' => true, 'flushed' => count($bufferRecords), 'error' => null];
        } else {
            @rename($tempPath, $bufferPath);
            return ['success' => false, 'flushed' => 0, 'error' => 'Failed to append records'];
        }
    }

    /**
     * Check if buffer needs flushing (size, count, or time based)
     * @param string $bufferPath
     * @param string $flushKey Key for tracking last flush time
     * @return bool
     */
    private function shouldFlushBuffer($bufferPath, $flushKey){
        if(!$this->hasBuffer($bufferPath)){
            return false;
        }

        // Check size limit
        $size = $this->getBufferSize($bufferPath);
        if($size >= $this->bufferSizeLimit){
            return true;
        }

        // Check count limit
        $count = $this->getBufferRecordCount($bufferPath);
        if($count >= $this->bufferCountLimit){
            return true;
        }

        // Check time-based flush
        if($this->bufferFlushInterval > 0){
            $lastFlush = $this->bufferLastFlush[$flushKey] ?? 0;
            if((time() - $lastFlush) >= $this->bufferFlushInterval){
                return true;
            }
        }

        return false;
    }

    /**
     * Register shutdown handler for flushing all buffers
     */
    private function registerShutdownHandler(){
        if($this->shutdownHandlerRegistered){
            return;
        }
        if($this->bufferAutoFlushOnShutdown){
            register_shutdown_function([$this, 'flushAllBuffers']);
            $this->shutdownHandlerRegistered = true;
        }
    }

    /**
     * Flush all shard buffers for a database
     * @param string $dbname
     * @param array|null $meta Optional meta data (avoids re-reading)
     * @return array ['flushed' => total records flushed]
     */
    private function flushAllShardBuffers($dbname, $meta = null){
        $dbname = $this->sanitizeDbName($dbname);
        if($meta === null){
            $meta = $this->getCachedMeta($dbname);
        }
        if($meta === null || !isset($meta['shards'])){
            return ['flushed' => 0];
        }

        $totalFlushed = 0;
        foreach($meta['shards'] as $shard){
            $result = $this->flushShardBuffer($dbname, $shard['id']);
            $totalFlushed += $result['flushed'];
        }

        return ['flushed' => $totalFlushed];
    }

    /**
     * Migrate a legacy (non-sharded) database to sharded format
     * @param string $dbname
     * @return bool
     */
    private function migrateToSharded($dbname){
        $dbname = $this->sanitizeDbName($dbname);
        $hash = $this->hashDBName($dbname);
        $legacyPath = $this->dbDir . $hash . "-" . $dbname . ".nonedb";

        if(!$this->cachedFileExists($legacyPath)){
            return false;
        }

        // Ensure JSONL format (auto-migrate v2 if needed)
        $this->ensureJsonlFormat($dbname);

        $allRecords = [];
        $totalRecords = 0;
        $deletedCount = 0;

        $index = $this->readJsonlIndex($dbname);
        if($index === null){
            return false;
        }

        $allRecordsRaw = $this->readAllJsonl($legacyPath, $index);
        // Convert to indexed array with key field
        foreach($allRecordsRaw as $record){
            $key = $record['key'] ?? count($allRecords);
            unset($record['key']);
            $allRecords[$key] = $record;
            $totalRecords++;
        }
        // Fill gaps with null for deleted records
        if(!empty($allRecords)){
            $maxKey = max(array_keys($allRecords));
            for($i = 0; $i <= $maxKey; $i++){
                if(!isset($allRecords[$i])){
                    $allRecords[$i] = null;
                    $deletedCount++;
                }
            }
            ksort($allRecords);
            $allRecords = array_values($allRecords);
        }

        // Calculate number of shards needed
        $totalEntries = count($allRecords);
        $numShards = (int) ceil($totalEntries / $this->shardSize);
        if($numShards === 0) $numShards = 1;

        // Create shards
        $meta = array(
            "version" => 1,
            "shardSize" => $this->shardSize,
            "totalRecords" => $totalRecords,
            "deletedCount" => $deletedCount,
            "nextKey" => $totalEntries,
            "shards" => []
        );

        for($shardId = 0; $shardId < $numShards; $shardId++){
            $start = $shardId * $this->shardSize;
            $end = min($start + $this->shardSize, $totalEntries);
            $shardRecords = array_slice($allRecords, $start, $end - $start);

            // Count records in this shard
            $shardCount = 0;
            $shardDeleted = 0;
            foreach($shardRecords as $record){
                if($record === null){
                    $shardDeleted++;
                } else {
                    $shardCount++;
                }
            }

            $meta['shards'][] = array(
                "id" => $shardId,
                "file" => "_s" . $shardId,
                "count" => $shardCount,
                "deleted" => $shardDeleted
            );

            // v3.0.0: Write shard file in JSONL format
            $shardPath = $this->getShardPath($dbname, $shardId);
            $baseKey = $shardId * $this->shardSize;

            // Create JSONL file and index
            $index = [
                'v' => 3,
                'format' => 'jsonl',
                'created' => time(),
                'n' => 0,
                'd' => 0,
                'o' => []
            ];

            $buffer = '';
            $offset = 0;

            foreach($shardRecords as $localKey => $record){
                if($record === null){
                    $index['d']++;
                    continue;
                }

                $globalKey = $baseKey + $localKey;
                $record['key'] = $globalKey;

                $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                $length = strlen($json) - 1;

                $index['o'][$globalKey] = [$offset, $length];
                $offset += strlen($json);
                $index['n']++;

                $buffer .= $json;
            }

            // Write JSONL file
            file_put_contents($shardPath, $buffer, LOCK_EX);
            $this->markFileExists($shardPath);
            $this->jsonlFormatCache[$shardPath] = true;

            // Write JSONL index
            $this->writeJsonlIndex($dbname, $index, $shardId);
        }

        // Write meta file
        $this->writeMeta($dbname, $meta);

        // Backup and remove legacy file
        $backupPath = $legacyPath . ".backup";
        rename($legacyPath, $backupPath);

        // Clean up JSONL index file if exists
        $indexPath = $legacyPath . ".jidx";
        if(file_exists($indexPath)){
            @unlink($indexPath);
        }

        // Clear index cache for this database
        unset($this->indexCache[$indexPath]);

        // Invalidate sharded cache - database is now sharded
        $this->invalidateShardedCache($dbname);

        return true;
    }

    /**
     * Insert data into sharded database with atomic locking
     * @param string $dbname
     * @param array $data
     * @return array
     */
    private function insertSharded($dbname, $data){
        $dbname = $this->sanitizeDbName($dbname);
        $main_response = array("n" => 0);

        // Validate data first
        $validItems = [];
        if($this->isRecordList($data)){
            foreach($data as $item){
                if(!is_array($item)) continue;
                if($this->hasReservedKeyField($item)){
                    $main_response['error'] = "You cannot set key name to key";
                    return $main_response;
                }
                $validItems[] = $item;
            }
            if(empty($validItems)){
                return array("n" => 0);
            }
        } else {
            if($this->hasReservedKeyField($data)){
                $main_response['error'] = "You cannot set key name to key";
                return $main_response;
            }
            $validItems[] = $data;
        }

        // Use buffered insert if enabled
        if($this->bufferEnabled){
            return $this->insertShardedBuffered($dbname, $validItems);
        }

        // Non-buffered insert (original method)
        return $this->insertShardedDirect($dbname, $validItems);
    }

    /**
     * Buffered insert for sharded database - writes to per-shard buffers
     * @param string $dbname
     * @param array $validItems Pre-validated items
     * @return array
     */
    private function insertShardedBuffered($dbname, array $validItems){
        $this->registerShutdownHandler();

        $shardSize = $this->shardSize;
        $insertedCount = 0;
        $shardWrites = []; // Collect items per shard

        // Atomically update meta and calculate which shards to write
        $metaResult = $this->modifyMeta($dbname, function($meta) use ($validItems, $shardSize, &$insertedCount, &$shardWrites) {
            if($meta === null){
                return null;
            }

            $lastShardIdx = count($meta['shards']) - 1;
            $shardId = $meta['shards'][$lastShardIdx]['id'];
            $currentShardCount = $meta['shards'][$lastShardIdx]['count'] + $meta['shards'][$lastShardIdx]['deleted'];

            foreach($validItems as $item){
                // Check if current shard is full
                if($currentShardCount >= $shardSize){
                    $shardId++;
                    $meta['shards'][] = array(
                        "id" => $shardId,
                        "file" => "_s" . $shardId,
                        "count" => 0,
                        "deleted" => 0
                    );
                    $lastShardIdx = count($meta['shards']) - 1;
                    $currentShardCount = 0;
                }

                if(!isset($shardWrites[$shardId])){
                    $shardWrites[$shardId] = ['items' => []];
                }
                $shardWrites[$shardId]['items'][] = $item;
                $currentShardCount++;
                $insertedCount++;

                $meta['shards'][$lastShardIdx]['count']++;
                $meta['totalRecords']++;
                $meta['nextKey']++;
            }

            return $meta;
        });

        if(!$metaResult['success'] || $metaResult['data'] === null){
            return array("n" => 0, "error" => $metaResult['error'] ?? 'Meta update failed');
        }

        // Write to each affected shard's buffer
        foreach($shardWrites as $shardId => $writeInfo){
            $bufferPath = $this->getShardBufferPath($dbname, $shardId);
            $flushKey = $dbname . '_s' . $shardId;

            // Check if buffer needs flushing before write
            if($this->shouldFlushBuffer($bufferPath, $flushKey)){
                $this->flushShardBuffer($dbname, $shardId);
            }

            // Append to shard buffer (fast)
            $this->atomicAppendToBuffer($bufferPath, $writeInfo['items']);

            // Check again after write
            if($this->shouldFlushBuffer($bufferPath, $flushKey)){
                $this->flushShardBuffer($dbname, $shardId);
            }
        }

        return array("n" => $insertedCount);
    }

    /**
     * Direct insert for sharded database without buffer
     * @param string $dbname
     * @param array $validItems Pre-validated items
     * @return array
     */
    private function insertShardedDirect($dbname, array $validItems){
        $shardSize = $this->shardSize;
        $insertedCount = 0;
        $shardWrites = [];

        // Atomically update meta and calculate which shards to write
        $metaResult = $this->modifyMeta($dbname, function($meta) use ($validItems, $shardSize, &$insertedCount, &$shardWrites) {
            if($meta === null){
                return null;
            }

            $lastShardIdx = count($meta['shards']) - 1;
            $shardId = $meta['shards'][$lastShardIdx]['id'];
            $currentShardCount = $meta['shards'][$lastShardIdx]['count'] + $meta['shards'][$lastShardIdx]['deleted'];

            foreach($validItems as $item){
                if($currentShardCount >= $shardSize){
                    $shardId++;
                    $meta['shards'][] = array(
                        "id" => $shardId,
                        "file" => "_s" . $shardId,
                        "count" => 0,
                        "deleted" => 0
                    );
                    $lastShardIdx = count($meta['shards']) - 1;
                    $currentShardCount = 0;
                }

                if(!isset($shardWrites[$shardId])){
                    $shardWrites[$shardId] = ['items' => [], 'shardIdx' => $lastShardIdx];
                }
                $shardWrites[$shardId]['items'][] = $item;
                $currentShardCount++;
                $insertedCount++;

                $meta['shards'][$lastShardIdx]['count']++;
                $meta['totalRecords']++;
                $meta['nextKey']++;
            }

            return $meta;
        });

        if(!$metaResult['success'] || $metaResult['data'] === null){
            return array("n" => 0, "error" => $metaResult['error'] ?? 'Meta update failed');
        }

        // v3.0.0: Write to each affected shard using JSONL format
        foreach($shardWrites as $shardId => $writeInfo){
            $shardPath = $this->getShardPath($dbname, $shardId);

            // Ensure JSONL format exists
            if(!$this->cachedFileExists($shardPath)){
                $this->createJsonlDatabase($dbname, $shardId);
            } else if(!$this->isJsonlFormat($shardPath)){
                // Migrate existing JSON to JSONL
                $this->migrateToJsonl($shardPath, $dbname, $shardId);
            }

            // Read current JSONL index
            $index = $this->readJsonlIndex($dbname, $shardId);
            if($index === null){
                $index = [
                    'v' => 3,
                    'format' => 'jsonl',
                    'created' => time(),
                    'n' => 0,
                    'd' => 0,
                    'o' => []
                ];
            }

            // Calculate base key for this shard
            $baseKey = $shardId * $shardSize;

            // Bulk append records using global keys
            $insertedKeys = [];
            clearstatcache(true, $shardPath);
            $offset = file_exists($shardPath) ? filesize($shardPath) : 0;
            $buffer = '';

            foreach($writeInfo['items'] as $item){
                $localKey = $index['n'];
                $globalKey = $baseKey + $localKey;
                $item['key'] = $globalKey;

                $json = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                $length = strlen($json) - 1;

                $index['o'][$globalKey] = [$offset, $length];
                $offset += strlen($json);
                $index['n']++;

                $buffer .= $json;
                $insertedKeys[] = $globalKey;
            }

            // Single write for all records
            file_put_contents($shardPath, $buffer, FILE_APPEND | LOCK_EX);

            // Write updated JSONL index
            $this->writeJsonlIndex($dbname, $index, $shardId);

            // Update field indexes (with shardId for global index)
            if($this->fieldIndexEnabled){
                foreach($writeInfo['items'] as $i => $record){
                    $this->updateFieldIndexOnInsert($dbname, $record, $insertedKeys[$i], $shardId);
                }
            }
        }

        return array("n" => $insertedCount);
    }

    /**
     * Find records in sharded database
     * @param string $dbname
     * @param mixed $filters
     * @return array|false
     */
    private function findSharded($dbname, $filters){
        $dbname = $this->sanitizeDbName($dbname);
        $meta = $this->getCachedMeta($dbname);
        if($meta === null){
            return false;
        }

        // Flush all shard buffers before read (flush-before-read strategy)
        if($this->bufferEnabled && $this->bufferFlushOnRead){
            $this->flushAllShardBuffers($dbname, $meta);
        }

        // Handle key-based search - use index for O(1) lookup
        if(is_array($filters) && count($filters) > 0){
            $filterKeys = array_keys($filters);
            if($filterKeys[0] === "key"){
                // Try to use index for fast lookup
                $index = $this->getOrBuildIndex($dbname);
                if($index !== null){
                    $indexResult = $this->findByKeyWithIndex($dbname, $filters['key'], $index);
                    if($indexResult !== null){
                        return $indexResult;
                    }
                    // Index lookup failed, fall back to full scan below
                }

                // Fallback: Direct shard calculation (still fast for sharded DBs)
                $result = [];
                $keys = is_array($filters['key']) ? $filters['key'] : array($filters['key']);

                foreach($keys as $globalKey){
                    $globalKey = (int)$globalKey;
                    $shardId = $this->getShardIdForKey($globalKey);

                    // Check if shard exists
                    $shardExists = false;
                    foreach($meta['shards'] as $shard){
                        if($shard['id'] === $shardId){
                            $shardExists = true;
                            break;
                        }
                    }

                    if(!$shardExists) continue;

                    // Use JSONL direct lookup for O(1) performance
                    // Note: JSONL shards store global keys
                    $records = $this->findByKeyJsonl($dbname, $globalKey, $shardId);
                    if($records !== null && !empty($records)){
                        $result = array_merge($result, $records);
                    }
                }
                return $result;
            }
        }

        // Try to use field index for O(1) lookup in sharded database
        if($this->fieldIndexEnabled && is_array($filters) && count($filters) > 0){
            $useFieldIndex = false;
            $firstFilterField = array_keys($filters)[0];
            $firstFilterValue = $filters[$firstFilterField];

            // Check if first filter field has index in first shard
            if($this->hasFieldIndex($dbname, $firstFilterField, $meta['shards'][0]['id'])){
                $useFieldIndex = true;
            }

            if($useFieldIndex){
                $result = [];

                // Shard-skip optimization: Use global field index to find target shards
                $targetShards = null;
                if(is_scalar($firstFilterValue) || is_null($firstFilterValue)){
                    $targetShards = $this->getTargetShardsFromGlobalIndex($dbname, $firstFilterField, $firstFilterValue);
                }

                // If global index available, only scan target shards; otherwise scan all
                if($targetShards !== null){
                    // Shard-skip: Only iterate target shards
                    foreach($targetShards as $shardId){
                        $this->findShardedFieldIndexScan($dbname, $shardId, $filters, $result);
                    }
                } else {
                    // Fallback: Scan all shards
                    foreach($meta['shards'] as $shard){
                        $this->findShardedFieldIndexScan($dbname, $shard['id'], $filters, $result);
                    }
                }
                return $result;
            }
        }

        // For all other searches, scan all shards
        $result = [];
        foreach($meta['shards'] as $shard){
            $shardData = $this->getShardData($dbname, $shard['id']);
            $baseKey = $shard['id'] * $this->shardSize;

            foreach($shardData['data'] as $localKey => $record){
                if($record === null) continue;

                $globalKey = $baseKey + $localKey;
                $record['key'] = $globalKey;

                // Return all if no filter
                if(is_int($filters) || (is_array($filters) && count($filters) === 0)){
                    $result[] = $record;
                    continue;
                }

                // Apply filter
                $match = true;
                foreach($filters as $field => $value){
                    if(!array_key_exists($field, $record) || $record[$field] !== $value){
                        $match = false;
                        break;
                    }
                }
                if($match){
                    $result[] = $record;
                }
            }
        }

        return $result;
    }

    /**
     * Helper: Scan a single shard using field index
     * @param string $dbname Database name
     * @param int $shardId Shard ID
     * @param array $filters Filter conditions
     * @param array &$result Result array (passed by reference)
     */
    private function findShardedFieldIndexScan($dbname, $shardId, $filters, &$result){
        $candidateKeys = null;

        // Find intersection of keys from all indexed fields in this shard
        foreach($filters as $field => $value){
            if(!is_scalar($value) && !is_null($value)) continue;

            if($this->hasFieldIndex($dbname, $field, $shardId)){
                $fieldKeys = $this->getKeysFromFieldIndex($dbname, $field, $value, $shardId);
                if($candidateKeys === null){
                    $candidateKeys = $fieldKeys;
                } else {
                    $candidateKeys = array_intersect($candidateKeys, $fieldKeys);
                }
                if(empty($candidateKeys)){
                    return; // No matches in this shard
                }
            }
        }

        // If we found candidate keys, read the records
        if($candidateKeys !== null && !empty($candidateKeys)){
            $shardPath = $this->getShardPath($dbname, $shardId);
            $jsonlIndex = $this->readJsonlIndex($dbname, $shardId);

            if($jsonlIndex !== null){
                // JSONL format - use batch read
                $offsets = [];
                foreach($candidateKeys as $key){
                    if(isset($jsonlIndex['o'][$key])){
                        $offsets[$key] = $jsonlIndex['o'][$key];
                    }
                }

                $records = $this->readJsonlRecordsBatch($shardPath, $offsets);

                foreach($records as $record){
                    if($record === null) continue;

                    // Verify all filters match
                    $match = true;
                    foreach($filters as $field => $value){
                        if(!array_key_exists($field, $record) || $record[$field] !== $value){
                            $match = false;
                            break;
                        }
                    }
                    if($match){
                        $result[] = $record;
                    }
                }
            } else {
                // Fallback to JSON format - read from shard data
                $shardData = $this->getShardData($dbname, $shardId);
                $baseKey = $shardId * $this->shardSize;

                foreach($candidateKeys as $localKey){
                    if(!isset($shardData['data'][$localKey]) || $shardData['data'][$localKey] === null){
                        continue;
                    }

                    $record = $shardData['data'][$localKey];

                    // Verify all filters match
                    $match = true;
                    foreach($filters as $field => $value){
                        if(!array_key_exists($field, $record) || $record[$field] !== $value){
                            $match = false;
                            break;
                        }
                    }
                    if($match){
                        $record['key'] = $baseKey + $localKey;
                        $result[] = $record;
                    }
                }
            }
        }
    }

    /**
     * Update records in sharded database with atomic locking
     * @param string $dbname
     * @param array $data
     * @return array
     */
    private function updateSharded($dbname, $data){
        $dbname = $this->sanitizeDbName($dbname);
        $main_response = array("n" => 0);

        $filters = $data[0];
        $setValues = $data[1]['set'];
        $shardSize = $this->shardSize;

        $meta = $this->getCachedMeta($dbname);
        if($meta === null){
            return $main_response;
        }

        // Flush all shard buffers before update
        if($this->bufferEnabled){
            $this->flushAllShardBuffers($dbname, $meta);
        }

        // v3.0.0: Update each shard using JSONL format (batch read for performance)
        $totalUpdated = 0;
        foreach($meta['shards'] as $shard){
            $shardId = $shard['id'];
            $baseKey = $shardId * $shardSize;
            $shardPath = $this->getShardPath($dbname, $shardId);

            // Ensure JSONL format (auto-migrate if needed)
            $this->ensureJsonlFormat($dbname, $shardId);

            // Read JSONL index
            $index = $this->readJsonlIndex($dbname, $shardId);
            if($index === null || empty($index['o'])) continue;

            // Batch read all records in shard for efficient filtering
            $records = $this->readJsonlRecordsBatch($shardPath, $index['o']);

            // Collect keys to update
            $keysToUpdate = [];
            foreach($records as $globalKey => $record){
                if($record === null) continue;

                // Check if record matches filters
                $match = true;
                foreach($filters as $filterKey => $filterValue){
                    if($filterKey === 'key'){
                        $targetKeys = is_array($filterValue) ? $filterValue : [$filterValue];
                        if(!in_array($globalKey, $targetKeys)){
                            $match = false;
                            break;
                        }
                    } else if(!isset($record[$filterKey]) || $record[$filterKey] !== $filterValue){
                        $match = false;
                        break;
                    }
                }

                if($match){
                    $keysToUpdate[] = ['key' => $globalKey, 'record' => $record];
                }
            }

            // Prepare batch updates
            $batchUpdates = [];
            foreach($keysToUpdate as $item){
                $record = $item['record'];

                // Apply updates
                foreach($setValues as $field => $value){
                    $record[$field] = $value;
                }

                // Remove key field (will be re-added by updateJsonlRecordsBatch)
                unset($record['key']);

                $batchUpdates[] = ['key' => $item['key'], 'data' => $record];
            }

            // Apply updates using batch method (single index write per shard)
            $totalUpdated += $this->updateJsonlRecordsBatch($dbname, $batchUpdates, $shardId);
        }

        return array("n" => $totalUpdated);
    }

    /**
     * Delete records from sharded database with atomic locking
     * @param string $dbname
     * @param array $data
     * @return array
     */
    private function deleteSharded($dbname, $data){
        $dbname = $this->sanitizeDbName($dbname);
        $main_response = array("n" => 0);

        $filters = $data;
        $shardSize = $this->shardSize;

        $meta = $this->getCachedMeta($dbname);
        if($meta === null){
            return $main_response;
        }

        // Flush all shard buffers before delete
        if($this->bufferEnabled){
            $this->flushAllShardBuffers($dbname, $meta);
        }

        // Track deletions per shard for meta update and index
        $shardDeletions = [];
        $deletedKeys = [];  // Track deleted keys for index update
        $totalDeleted = 0;

        // v3.0.0: Delete from each shard using JSONL format (two-phase approach)
        // Phase 1: Collect all keys to delete from each shard (batch read for performance)
        $keysToDeleteByShard = [];

        foreach($meta['shards'] as $shard){
            $shardId = $shard['id'];
            $shardPath = $this->getShardPath($dbname, $shardId);

            // Ensure JSONL format (auto-migrate if needed)
            $this->ensureJsonlFormat($dbname, $shardId);

            // Read JSONL index
            $index = $this->readJsonlIndex($dbname, $shardId);
            if($index === null || empty($index['o'])) continue;

            // Batch read all records in shard for efficient filtering
            $records = $this->readJsonlRecordsBatch($shardPath, $index['o']);

            // Collect keys that match filters
            $keysToDelete = [];
            foreach($records as $globalKey => $record){
                if($record === null) continue;

                // Check if record matches filters
                $match = true;
                foreach($filters as $filterKey => $filterValue){
                    if($filterKey === 'key'){
                        $targetKeys = is_array($filterValue) ? $filterValue : [$filterValue];
                        if(!in_array($globalKey, $targetKeys)){
                            $match = false;
                            break;
                        }
                    } else if(!array_key_exists($filterKey, $record) || $record[$filterKey] !== $filterValue){
                        $match = false;
                        break;
                    }
                }

                if($match){
                    $keysToDelete[] = $globalKey;
                }
            }

            if(!empty($keysToDelete)){
                $keysToDeleteByShard[$shardId] = $keysToDelete;
            }
        }

        // Phase 2: Delete collected keys using batch delete (single index write per shard)
        foreach($keysToDeleteByShard as $shardId => $keysToDelete){
            $deletedInShard = $this->deleteJsonlRecordsBatch($dbname, $keysToDelete, $shardId);

            if($deletedInShard > 0){
                $shardDeletions[$shardId] = $deletedInShard;
                $deletedKeys = array_merge($deletedKeys, $keysToDelete);
                $totalDeleted += $deletedInShard;
            }
        }

        // Atomically update meta with deletion counts
        if($totalDeleted > 0){
            $this->modifyMeta($dbname, function($meta) use ($shardDeletions, $totalDeleted) {
                if($meta === null) return null;

                foreach($meta['shards'] as &$shard){
                    if(isset($shardDeletions[$shard['id']])){
                        $shard['count'] -= $shardDeletions[$shard['id']];
                        $shard['deleted'] += $shardDeletions[$shard['id']];
                    }
                }

                $meta['totalRecords'] -= $totalDeleted;
                $meta['deletedCount'] = ($meta['deletedCount'] ?? 0) + $totalDeleted;
                return $meta;
            });

            // Update index with deleted keys
            $this->updateIndexOnDelete($dbname, $deletedKeys);
        }

        return array("n" => $totalDeleted);
    }

    /**
     * check db
     * if auto create db is true will be create db
     * if auto create db is false and is not in db dir return false
     * @param string $dbname
     */
    function checkDB($dbname=null){
        if(!$dbname){
            return false;
        }
        $dbname = $this->sanitizeDbName($dbname);
        // Sanitize sonrası boş string kontrolü
        if($dbname === ''){
            return false;
        }
        /**
         * if db dir is not in project folder will be create.
         */
        if(!file_exists($this->dbDir)){
            mkdir($this->dbDir, 0777);
        }

        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";
        /**
         * check db is in db folder? (use cache for existing files)
         */
        if($this->cachedFileExists($fullDBPath)){
            return true;
        }

        /**
         * if auto create db is true will be create db.
         */
        if($this->autoCreateDB){
            return $this->createDB($dbname);
        }
        return false;
    }

    /**
     * create db function
     * @param string $dbname
     */
    public function createDB($dbname){
        $dbname = $this->sanitizeDbName($dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";
        if(!file_exists($this->dbDir)){
            mkdir($this->dbDir, 0777);
        }
        if(!$this->cachedFileExists($fullDBPath)){
            // Create info file
            $infoDB = fopen($fullDBPath."info", "a+");
            fwrite($infoDB, time());
            fclose($infoDB);

            // v3.0: Create empty JSONL format database
            touch($fullDBPath);

            // Create empty JSONL index
            $index = [
                'v' => 3,
                'format' => 'jsonl',
                'created' => time(),
                'n' => 0,
                'd' => 0,
                'o' => []
            ];
            $this->writeJsonlIndex($dbname, $index);

            // Mark file as existing in cache
            $this->markFileExists($fullDBPath);

            return true;
        }
        return false;
    }


    /**
     * Convert bytes to human readable format
     * @param float $bytes
     * @return string
     */
    private function fileSizeConvert($bytes){
        $bytes = floatval($bytes);
        $arBytes = array(
            0 => array("UNIT" => "TB", "VALUE" => pow(1024, 4)),
            1 => array("UNIT" => "GB", "VALUE" => pow(1024, 3)),
            2 => array("UNIT" => "MB", "VALUE" => pow(1024, 2)),
            3 => array("UNIT" => "KB", "VALUE" => 1024),
            4 => array("UNIT" => "B", "VALUE" => 1),
        );
        $result = "0 B";
        foreach($arBytes as $arItem){
            if($bytes >= $arItem["VALUE"]){
                $result = $bytes / $arItem["VALUE"];
                $result = str_replace(".", "," , strval(round($result, 2)))." ".$arItem["UNIT"];
                break;
            }
        }
        return $result;
    }

    public function getDBs($info=false){
        // Handle three cases: false (names only), true (with metadata), string (specific db)
        $withMetadata = false;
        $specificDb = null;

        if(is_bool($info)){
            $withMetadata = $info;
        }else{
            $specificDb = $this->sanitizeDbName($info);
            $this->checkDB($specificDb);
        }

        // If specific database requested
        if($specificDb !== null){
            $dbnameHashed=$this->hashDBName($specificDb);
            $fullDBPathInfo=$this->dbDir.$dbnameHashed."-".$specificDb.".nonedbinfo";
            $fullDBPath=$this->dbDir.$dbnameHashed."-".$specificDb.".nonedb";
            if(file_exists($fullDBPathInfo)){
                $dbInfo = fopen($fullDBPathInfo, "r");
                clearstatcache(true, $fullDBPath); // Clear cache before getting file size
                $db= array("name"=>$specificDb, "createdTime"=>(int)fgets($dbInfo), "size"=>$this->fileSizeConvert(filesize($fullDBPath)));
                fclose($dbInfo);
                return $db;
            }
            return false;
        }

        // List all databases
        $dbs = [];
        if(!file_exists($this->dbDir)){
            return $dbs;
        }
        foreach(new DirectoryIterator($this->dbDir) as $item) {
            if(!$item->isDot() && $item->isFile()) {
                $filename = $item->getFilename();
                $parts = explode('-', $filename, 2);
                if(count($parts) < 2) continue;

                $dbb = explode('.', $parts[1]);
                if(count($dbb) < 2 || $dbb[1] !== "nonedb") continue;

                $dbname = $dbb[0];

                if($withMetadata){
                    $dbnameHashed=$this->hashDBName($dbname);
                    $fullDBPathInfo=$this->dbDir.$dbnameHashed."-".$dbname.".nonedbinfo";
                    $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";
                    if(file_exists($fullDBPathInfo)){
                        $dbInfo = fopen($fullDBPathInfo, "r");
                        $dbs[]= array("name"=>$dbname, "createdTime"=>(int)fgets($dbInfo), "size"=>$this->fileSizeConvert(filesize($fullDBPath)));
                        fclose($dbInfo);
                    }
                }else{
                    $dbs[]= $dbname;
                }
            }
         }
        return $dbs;
    }

    
    /**
     * limit function
     * @param array $array Extract a slice of the array
     * @param integer $limit
     */
    public function limit($array, $limit=0){
        if(!is_array($array) || !is_int($limit) || $limit <= 0){
            return false;
        }
        // Multidimensional array kontrolü
        if(count($array) === count($array, COUNT_RECURSIVE)) {
            return false;
        }
        return array_slice($array, 0, $limit);
    }

    /**
     * read db all data
     * @param string $dbname
     * @param mixed $filters 0 for all, array for filter
     */
    public function find($dbname, $filters=0){
        $dbname = $this->sanitizeDbName($dbname);

        // Check for sharded database first
        if($this->isSharded($dbname)){
            return $this->findSharded($dbname, $filters);
        }

        // Flush buffer before read (flush-before-read strategy)
        if($this->bufferEnabled && $this->bufferFlushOnRead){
            $bufferPath = $this->getBufferPath($dbname);
            if($this->hasBuffer($bufferPath)){
                $this->flushBufferToMain($dbname);
            }
        }

        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";
        if(!$this->checkDB($dbname)){
            return false;
        }

        // Ensure JSONL format (auto-migrate v2 if needed)
        $this->ensureJsonlFormat($dbname);

        $jsonlIndex = $this->readJsonlIndex($dbname);
        if($jsonlIndex === null){
            return [];
        }

        // Key-based search - O(1) lookup
        if(is_array($filters) && count($filters) > 0){
            $filterKeys = array_keys($filters);
            if($filterKeys[0] === "key"){
                $result = $this->findByKeyJsonl($dbname, $filters['key']);
                return $result !== null ? $result : [];
            }
        }

        // Return all if no filter
        if(is_int($filters) || (is_array($filters) && count($filters) === 0)){
            $allRecords = $this->readAllJsonl($fullDBPath, $jsonlIndex);
            return $allRecords;
        }

        // Try to use field index for O(1) lookup
        if($this->fieldIndexEnabled && is_array($filters)){
            $candidateKeys = null;

            // Find intersection of keys from all indexed fields
            foreach($filters as $field => $value){
                if(!is_scalar($value) && !is_null($value)) continue;

                if($this->hasFieldIndex($dbname, $field, null)){
                    $fieldKeys = $this->getKeysFromFieldIndex($dbname, $field, $value, null);
                    if($candidateKeys === null){
                        $candidateKeys = $fieldKeys;
                    } else {
                        $candidateKeys = array_intersect($candidateKeys, $fieldKeys);
                    }
                    // Early exit if no matches
                    if(empty($candidateKeys)){
                        return [];
                    }
                }
            }

            // If we found candidate keys from field indexes, use batch read
            if($candidateKeys !== null){
                // Build offsets array for batch reading
                $offsets = [];
                foreach($candidateKeys as $key){
                    if(isset($jsonlIndex['o'][$key])){
                        $offsets[$key] = $jsonlIndex['o'][$key];
                    }
                }

                // Batch read all matching records at once
                $records = $this->readJsonlRecordsBatch($fullDBPath, $offsets);

                // Filter and verify matches
                $result = [];
                foreach($records as $record){
                    if($record === null) continue;

                    // Verify all filters match (some fields may not have indexes)
                    $match = true;
                    foreach($filters as $field => $value){
                        if(!array_key_exists($field, $record) || $record[$field] !== $value){
                            $match = false;
                            break;
                        }
                    }
                    if($match){
                        $result[] = $record;
                    }
                }
                return $result;
            }
        }

        // Fallback: Get all records and filter
        $allRecords = $this->readAllJsonl($fullDBPath, $jsonlIndex);

        // Apply filters
        $result = [];
        foreach($allRecords as $record){
            $match = true;
            foreach($filters as $field => $value){
                if(!array_key_exists($field, $record) || $record[$field] !== $value){
                    $match = false;
                    break;
                }
            }
            if($match){
                $result[] = $record;
            }
        }
        return $result;
    }

    /**
     * Check if 'key' exists at top level of data (not nested)
     * @param array $data
     * @return bool
     */
    private function hasReservedKeyField($data){
        return is_array($data) && array_key_exists("key", $data);
    }

    /**
     * Check if array is a list of records (numeric keys with array values)
     * @param array $data
     * @return bool
     */
    private function isRecordList($data){
        if(!is_array($data) || count($data) === 0){
            return false;
        }
        // Check if first key is numeric and value is array
        $keys = array_keys($data);
        if(!is_int($keys[0])){
            return false;
        }
        // Check if first element is an array (a record)
        return is_array($data[$keys[0]]);
    }

    /**
     * insert to db
     * @param string $dbname
     * @param array $data
     */
    public function insert($dbname, $data){
        $dbname = $this->sanitizeDbName($dbname);
        $main_response=array("n"=>0);

        if(!is_array($data)){
            $main_response['error']="insert data must be array";
            return $main_response;
        }

        // Check for sharded database first
        if($this->isSharded($dbname)){
            return $this->insertSharded($dbname, $data);
        }

        // Validate data before any operation
        $validItems = [];
        if($this->isRecordList($data)){
            foreach($data as $item){
                if(!is_array($item)){
                    continue;
                }
                if($this->hasReservedKeyField($item)){
                    $main_response['error']="You cannot set key name to key";
                    return $main_response;
                }
                $validItems[] = $item;
            }
        } else {
            if($this->hasReservedKeyField($data)){
                $main_response['error']="You cannot set key name to key";
                return $main_response;
            }
            $validItems[] = $data;
        }

        if(empty($validItems)){
            return array("n"=>0);
        }

        // Use buffered insert if enabled
        if($this->bufferEnabled){
            return $this->insertBuffered($dbname, $validItems);
        }

        // Non-buffered insert (original atomic method)
        return $this->insertDirect($dbname, $validItems);
    }

    /**
     * Buffered insert - fast append-only to buffer file
     * @param string $dbname
     * @param array $validItems Pre-validated items
     * @return array
     */
    private function insertBuffered($dbname, array $validItems){
        // Ensure database metadata exists (creates .nonedbinfo file)
        $this->checkDB($dbname);

        // Register shutdown handler for auto-flush
        $this->registerShutdownHandler();

        $bufferPath = $this->getBufferPath($dbname);

        // Check if buffer needs flushing before insert
        if($this->shouldFlushBuffer($bufferPath, $dbname)){
            $this->flushBufferToMain($dbname);
        }

        // Append to buffer (fast, no full file read)
        $result = $this->atomicAppendToBuffer($bufferPath, $validItems);

        if(!$result['success']){
            return array("n" => 0, "error" => $result['error']);
        }

        // Check again after insert if we crossed threshold
        if($this->shouldFlushBuffer($bufferPath, $dbname)){
            $flushResult = $this->flushBufferToMain($dbname);

            // After flush, check if main DB needs sharding
            if($flushResult['success'] && $this->shardingEnabled && $this->autoMigrate){
                $index = $this->readJsonlIndex($dbname);
                if($index !== null && $index['n'] >= $this->shardSize){
                    $this->migrateToSharded($dbname);
                }
            }
        }

        return array("n" => $result['count']);
    }

    /**
     * Direct insert without buffer - uses atomic modify
     * @param string $dbname
     * @param array $validItems Pre-validated items
     * @return array
     */
    private function insertDirect($dbname, array $validItems){
        $this->checkDB($dbname);
        $dbnameHashed = $this->hashDBName($dbname);
        $fullDBPath = $this->dbDir.$dbnameHashed."-".$dbname.".nonedb";

        $countData = count($validItems);

        // Ensure JSONL format (auto-migrate v2 if needed)
        if(!$this->ensureJsonlFormat($dbname)){
            // DB doesn't exist yet, create as JSONL
            $this->createJsonlDatabase($dbname);
        }

        $index = $this->readJsonlIndex($dbname);
        if($index === null){
            return array("n" => 0, "error" => "Failed to read index");
        }

        // Use bulk append for multiple records
        $keys = $this->bulkAppendJsonl($fullDBPath, $validItems, $index);
        $this->writeJsonlIndex($dbname, $index);

        // Update field indexes for inserted records
        if($this->fieldIndexEnabled){
            foreach($validItems as $i => $record){
                $this->updateFieldIndexOnInsert($dbname, $record, $keys[$i], null);
            }
        }

        // Auto-migrate to sharded format if threshold reached
        if($this->shardingEnabled && $this->autoMigrate && $index['n'] >= $this->shardSize){
            $this->migrateToSharded($dbname);
        }

        return array("n" => $countData);
    }

    /**
     * delete function
     * @param string $dbname
     * @param array $data
     */
    public function delete($dbname, $data){
        $dbname = $this->sanitizeDbName($dbname);
        $main_response=array("n"=>0);
        if(!is_array($data)){
            $main_response['error']="Please check your delete paramters";
            return $main_response;
        }

        // Check for sharded database first
        if($this->isSharded($dbname)){
            return $this->deleteSharded($dbname, $data);
        }

        // Flush buffer before delete operation
        if($this->bufferEnabled){
            $bufferPath = $this->getBufferPath($dbname);
            if($this->hasBuffer($bufferPath)){
                $this->flushBufferToMain($dbname);
            }
        }

        $this->checkDB($dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";

        // Ensure JSONL format (auto-migrate v2 if needed)
        $this->ensureJsonlFormat($dbname);

        $filters = $data;
        $deletedCount = 0;

        // Key-based delete - O(1)
        if(isset($filters['key'])){
            $targetKeys = is_array($filters['key']) ? $filters['key'] : [$filters['key']];
            foreach($targetKeys as $key){
                if($this->deleteJsonlRecord($dbname, $key)){
                    $deletedCount++;
                }
            }
            return array("n" => $deletedCount);
        }

        // Filter-based delete - need to scan (batch read for performance)
        $index = $this->readJsonlIndex($dbname);
        if($index === null || empty($index['o'])){
            return array("n" => 0);
        }

        // Batch read all records for efficient filtering
        $records = $this->readJsonlRecordsBatch($fullDBPath, $index['o']);

        // First pass: collect all keys to delete
        $keysToDelete = [];
        foreach($records as $key => $record){
            if($record === null) continue;

            $match = true;
            foreach($filters as $filterKey => $filterValue){
                if(!isset($record[$filterKey]) || $record[$filterKey] !== $filterValue){
                    $match = false;
                    break;
                }
            }

            if($match){
                $keysToDelete[] = $key;
            }
        }

        // Second pass: delete collected keys using batch delete (single index write)
        $deletedCount = $this->deleteJsonlRecordsBatch($dbname, $keysToDelete);

        return array("n" => $deletedCount);
    }

    /**
     * update function
     * @param string $dbname
     * @param array $data
     */
    public function update($dbname, $data){
        $dbname = $this->sanitizeDbName($dbname);
        $main_response=array("n"=>0);
        if(!is_array($data) || count($data) === count($data, COUNT_RECURSIVE) || !isset($data[1]['set']) || array_key_exists("key", $data[1]['set'])){
            $main_response['error']="Please check your update paramters";
            return $main_response;
        }

        // Check for sharded database first
        if($this->isSharded($dbname)){
            return $this->updateSharded($dbname, $data);
        }

        // Flush buffer before update operation
        if($this->bufferEnabled){
            $bufferPath = $this->getBufferPath($dbname);
            if($this->hasBuffer($bufferPath)){
                $this->flushBufferToMain($dbname);
            }
        }

        $this->checkDB($dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";

        $filters = $data[0];
        $setData = $data[1]['set'];
        $updatedCount = 0;

        // Ensure JSONL format (auto-migrate v2 if needed)
        $this->ensureJsonlFormat($dbname);

        $index = $this->readJsonlIndex($dbname);
        if($index === null){
            return array("n" => 0);
        }

        // Key-based update - O(1) lookup, batch update
        if(isset($filters['key'])){
            $targetKeys = is_array($filters['key']) ? $filters['key'] : [$filters['key']];
            $batchUpdates = [];

            foreach($targetKeys as $key){
                if(!isset($index['o'][$key])) continue;

                $record = $this->readJsonlRecord($fullDBPath, $index['o'][$key][0], $index['o'][$key][1]);
                if($record === null) continue;

                // Apply updates
                foreach($setData as $setKey => $setValue){
                    $record[$setKey] = $setValue;
                }

                // Remove key field (will be re-added by updateJsonlRecordsBatch)
                unset($record['key']);

                $batchUpdates[] = ['key' => $key, 'data' => $record];
            }

            $updatedCount = $this->updateJsonlRecordsBatch($dbname, $batchUpdates);
            return array("n" => $updatedCount);
        }

        // Filter-based update - need to scan (batch read for performance)
        $records = $this->readJsonlRecordsBatch($fullDBPath, $index['o']);

        $batchUpdates = [];
        foreach($records as $key => $record){
            if($record === null) continue;

            $match = true;
            foreach($filters as $filterKey => $filterValue){
                if(!isset($record[$filterKey]) || $record[$filterKey] !== $filterValue){
                    $match = false;
                    break;
                }
            }

            if($match){
                // Apply updates
                foreach($setData as $setKey => $setValue){
                    $record[$setKey] = $setValue;
                }

                // Remove key field (will be re-added by updateJsonlRecordsBatch)
                unset($record['key']);

                $batchUpdates[] = ['key' => $key, 'data' => $record];
            }
        }

        // Apply updates using batch method (single index write)
        $updatedCount = $this->updateJsonlRecordsBatch($dbname, $batchUpdates);

        return array("n" => $updatedCount);
    }

    // ==========================================
    // QUERY METHODS
    // ==========================================

    /**
     * Get unique values for a field
     * @param string $dbname
     * @param string $field
     * @return array|false
     */
    public function distinct($dbname, $field){
        $all = $this->find($dbname, 0);
        if($all === false) return false;
        $values = [];
        foreach($all as $record){
            if(isset($record[$field]) && !in_array($record[$field], $values, true)){
                $values[] = $record[$field];
            }
        }
        return $values;
    }

    /**
     * Sort array by field
     * @param array $array Result from find()
     * @param string $field Field to sort by
     * @param string $order 'asc' or 'desc'
     * @return array|false
     */
    public function sort($array, $field, $order = 'asc'){
        if(!is_array($array) || count($array) === 0) return false;
        $order = strtolower($order);
        usort($array, function($a, $b) use ($field, $order){
            if(!isset($a[$field]) || !isset($b[$field])) return 0;
            $result = $a[$field] <=> $b[$field];
            return $order === 'desc' ? -$result : $result;
        });
        return $array;
    }

    /**
     * Count records matching filter
     * @param string $dbname
     * @param mixed $filter
     * @return int
     */
    public function count($dbname, $filter = 0){
        $dbname = $this->sanitizeDbName($dbname);

        // Fast-path: No filter = use index/meta count directly (v3.0.0 optimization)
        if($filter === 0 || (is_array($filter) && empty($filter))){
            return $this->countFast($dbname);
        }

        // Filtered count still needs to scan
        $result = $this->find($dbname, $filter);
        return $result === false ? 0 : count($result);
    }

    /**
     * Fast count using index/meta data - O(1) for unfiltered count
     * v3.0.0 optimization: Avoids loading all records into memory
     * @param string $dbname Already sanitized
     * @return int
     */
    private function countFast($dbname){
        // Sharded database: use metadata
        // Note: totalRecords is already decremented after delete operations
        // deletedCount tracks garbage records for compaction, not active count
        if($this->isSharded($dbname)){
            $meta = $this->getCachedMeta($dbname);
            if($meta !== null){
                return $meta['totalRecords'] ?? 0;
            }
            return 0;
        }

        // Non-sharded: use JSONL index offset count
        $index = $this->readJsonlIndex($dbname);
        if($index !== null && isset($index['o'])){
            return count($index['o']);
        }

        return 0;
    }

    /**
     * Pattern matching search (LIKE)
     * @param string $dbname
     * @param string $field
     * @param string $pattern Use ^ for starts with, $ for ends with
     * @return array|false
     */
    public function like($dbname, $field, $pattern){
        $all = $this->find($dbname, 0);
        if($all === false) return false;

        $result = [];
        // Convert simple patterns to regex
        if(strpos($pattern, '^') === 0 || substr($pattern, -1) === '$'){
            $regex = '/' . $pattern . '/i';
        }else{
            $regex = '/' . preg_quote($pattern, '/') . '/i';
        }

        foreach($all as $record){
            if(!isset($record[$field])) continue;
            $value = $record[$field];
            // Skip arrays and objects - can't do string matching on them
            if(is_array($value) || is_object($value)) continue;
            if(preg_match($regex, (string)$value)){
                $result[] = $record;
            }
        }
        return $result;
    }

    // ==========================================
    // AGGREGATION METHODS
    // ==========================================

    /**
     * Sum numeric field values
     * @param string $dbname
     * @param string $field
     * @param mixed $filter
     * @return float|int
     */
    public function sum($dbname, $field, $filter = 0){
        $result = $this->find($dbname, $filter);
        if($result === false) return 0;
        $sum = 0;
        foreach($result as $record){
            if(isset($record[$field]) && is_numeric($record[$field])){
                $sum += $record[$field];
            }
        }
        return $sum;
    }

    /**
     * Average of numeric field values
     * @param string $dbname
     * @param string $field
     * @param mixed $filter
     * @return float|int
     */
    public function avg($dbname, $field, $filter = 0){
        $result = $this->find($dbname, $filter);
        if($result === false || count($result) === 0) return 0;
        $sum = 0;
        $count = 0;
        foreach($result as $record){
            if(isset($record[$field]) && is_numeric($record[$field])){
                $sum += $record[$field];
                $count++;
            }
        }
        return $count > 0 ? $sum / $count : 0;
    }

    /**
     * Get minimum value of a field
     * @param string $dbname
     * @param string $field
     * @param mixed $filter
     * @return mixed|null
     */
    public function min($dbname, $field, $filter = 0){
        $result = $this->find($dbname, $filter);
        if($result === false || count($result) === 0) return null;
        $min = null;
        foreach($result as $record){
            if(isset($record[$field])){
                if($min === null || $record[$field] < $min){
                    $min = $record[$field];
                }
            }
        }
        return $min;
    }

    /**
     * Get maximum value of a field
     * @param string $dbname
     * @param string $field
     * @param mixed $filter
     * @return mixed|null
     */
    public function max($dbname, $field, $filter = 0){
        $result = $this->find($dbname, $filter);
        if($result === false || count($result) === 0) return null;
        $max = null;
        foreach($result as $record){
            if(isset($record[$field])){
                if($max === null || $record[$field] > $max){
                    $max = $record[$field];
                }
            }
        }
        return $max;
    }

    // ==========================================
    // UTILITY METHODS
    // ==========================================

    /**
     * Get first matching record
     * @param string $dbname
     * @param mixed $filter
     * @return array|null
     */
    public function first($dbname, $filter = 0){
        $result = $this->find($dbname, $filter);
        if($result === false || count($result) === 0) return null;
        return $result[0];
    }

    /**
     * Get last matching record
     * @param string $dbname
     * @param mixed $filter
     * @return array|null
     */
    public function last($dbname, $filter = 0){
        $result = $this->find($dbname, $filter);
        if($result === false || count($result) === 0) return null;
        return $result[count($result) - 1];
    }

    /**
     * Check if records exist matching filter
     * @param string $dbname
     * @param mixed $filter
     * @return bool
     */
    public function exists($dbname, $filter){
        $result = $this->find($dbname, $filter);
        return $result !== false && count($result) > 0;
    }

    /**
     * Range query (min <= value <= max)
     * @param string $dbname
     * @param string $field
     * @param mixed $min
     * @param mixed $max
     * @param mixed $filter Additional filter
     * @return array|false
     */
    public function between($dbname, $field, $min, $max, $filter = 0){
        $result = $this->find($dbname, $filter);
        if($result === false) return false;
        $filtered = [];
        foreach($result as $record){
            if(isset($record[$field])){
                $value = $record[$field];
                if($value >= $min && $value <= $max){
                    $filtered[] = $record;
                }
            }
        }
        return $filtered;
    }

    // ==========================================
    // SHARDING PUBLIC METHODS
    // ==========================================

    /**
     * Get sharding information for a database
     * @param string $dbname
     * @return array|false
     */
    public function getShardInfo($dbname){
        $dbname = $this->sanitizeDbName($dbname);

        if(!$this->isSharded($dbname)){
            $hash = $this->hashDBName($dbname);
            $dbPath = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
            if($this->cachedFileExists($dbPath)){
                // Ensure JSONL format (auto-migrate v2 if needed)
                $this->ensureJsonlFormat($dbname);

                $index = $this->readJsonlIndex($dbname);
                if($index !== null){
                    return array(
                        "sharded" => false,
                        "shards" => 0,
                        "totalRecords" => count($index['o']),
                        "shardSize" => $this->shardSize
                    );
                }
            }
            return false;
        }

        $meta = $this->getCachedMeta($dbname);
        if($meta === null){
            return false;
        }

        return array(
            "sharded" => true,
            "shards" => count($meta['shards']),
            "totalRecords" => $meta['totalRecords'],
            "deletedCount" => $meta['deletedCount'],
            "shardSize" => $meta['shardSize'],
            "nextKey" => $meta['nextKey']
        );
    }

    // ==========================================
    // FIELD INDEX PUBLIC API (v3.0.0)
    // ==========================================

    /**
     * Create a field index for faster filter-based queries
     * @param string $dbname Database name
     * @param string $field Field name to index
     * @return array ['success' => bool, 'indexed' => int, 'values' => int, 'error' => string|null]
     */
    public function createFieldIndex($dbname, $field){
        $dbname = $this->sanitizeDbName($dbname);

        if(empty($field) || $field === 'key'){
            return ['success' => false, 'indexed' => 0, 'values' => 0, 'error' => 'Invalid field name'];
        }

        // Handle sharded databases
        if($this->isSharded($dbname)){
            return $this->createFieldIndexSharded($dbname, $field);
        }

        // Non-sharded: build index from all records
        $this->checkDB($dbname);
        $hash = $this->hashDBName($dbname);
        $fullPath = $this->dbDir . $hash . "-" . $dbname . ".nonedb";

        // Ensure JSONL format
        if(!$this->ensureJsonlFormat($dbname)){
            return ['success' => false, 'indexed' => 0, 'values' => 0, 'error' => 'JSONL format required'];
        }

        $jsonlIndex = $this->readJsonlIndex($dbname);
        if($jsonlIndex === null){
            return ['success' => false, 'indexed' => 0, 'values' => 0, 'error' => 'Could not read index'];
        }

        // Build field index
        $fieldIndex = [
            'v' => 1,
            'field' => $field,
            'created' => time(),
            'values' => []
        ];

        $indexedCount = 0;
        foreach($jsonlIndex['o'] as $key => $location){
            $record = $this->readJsonlRecord($fullPath, $location[0], $location[1]);
            if($record === null) continue;

            // Use array_key_exists to include null values
            if(array_key_exists($field, $record)){
                $value = $record[$field];
                // Only index scalar values
                if(is_scalar($value) || is_null($value)){
                    $valueKey = $this->fieldIndexValueKey($value);
                    if(!isset($fieldIndex['values'][$valueKey])){
                        $fieldIndex['values'][$valueKey] = [];
                    }
                    $fieldIndex['values'][$valueKey][] = (int)$key;
                    $indexedCount++;
                }
            }
        }

        // Write field index
        if($this->writeFieldIndex($dbname, $field, $fieldIndex)){
            return [
                'success' => true,
                'indexed' => $indexedCount,
                'values' => count($fieldIndex['values']),
                'error' => null
            ];
        }

        return ['success' => false, 'indexed' => 0, 'values' => 0, 'error' => 'Failed to write index'];
    }

    /**
     * Create field index for sharded database
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array Result
     */
    private function createFieldIndexSharded($dbname, $field){
        $meta = $this->getCachedMeta($dbname);
        if($meta === null){
            return ['success' => false, 'indexed' => 0, 'values' => 0, 'error' => 'Could not read meta'];
        }

        $totalIndexed = 0;
        $totalValues = 0;

        // Initialize global field index metadata for shard-skip optimization
        $globalMeta = [
            'v' => 1,
            'field' => $field,
            'shardMap' => []
        ];

        foreach($meta['shards'] as $shard){
            $shardId = $shard['id'];
            $shardPath = $this->getShardPath($dbname, $shardId);

            // Build field index for this shard
            $fieldIndex = [
                'v' => 1,
                'field' => $field,
                'shardId' => $shardId,
                'created' => time(),
                'values' => []
            ];

            // Try JSONL format first
            $jsonlIndex = $this->readJsonlIndex($dbname, $shardId);
            if($jsonlIndex !== null){
                // JSONL format - use batch read
                foreach($jsonlIndex['o'] as $key => $location){
                    $record = $this->readJsonlRecord($shardPath, $location[0], $location[1]);
                    if($record === null) continue;

                    if(isset($record[$field])){
                        $value = $record[$field];
                        if(is_scalar($value) || is_null($value)){
                            $valueKey = $this->fieldIndexValueKey($value);
                            if(!isset($fieldIndex['values'][$valueKey])){
                                $fieldIndex['values'][$valueKey] = [];
                            }
                            $fieldIndex['values'][$valueKey][] = (int)$key;
                            $totalIndexed++;
                        }
                    }
                }
            } else {
                // Fallback to JSON format
                $shardData = $this->getShardData($dbname, $shardId);
                foreach($shardData['data'] as $key => $record){
                    if($record === null) continue;

                    if(isset($record[$field])){
                        $value = $record[$field];
                        if(is_scalar($value) || is_null($value)){
                            $valueKey = $this->fieldIndexValueKey($value);
                            if(!isset($fieldIndex['values'][$valueKey])){
                                $fieldIndex['values'][$valueKey] = [];
                            }
                            $fieldIndex['values'][$valueKey][] = (int)$key;
                            $totalIndexed++;
                        }
                    }
                }
            }

            // Add this shard to global metadata for each unique value in this shard
            foreach($fieldIndex['values'] as $valueKey => $keys){
                if(!isset($globalMeta['shardMap'][$valueKey])){
                    $globalMeta['shardMap'][$valueKey] = [];
                }
                $globalMeta['shardMap'][$valueKey][] = $shardId;
            }

            $totalValues += count($fieldIndex['values']);
            $this->writeFieldIndex($dbname, $field, $fieldIndex, $shardId);
        }

        // Write global field index metadata for shard-skip optimization
        $this->writeGlobalFieldIndex($dbname, $field, $globalMeta);

        return [
            'success' => true,
            'indexed' => $totalIndexed,
            'values' => $totalValues,
            'shards' => count($meta['shards']),
            'error' => null
        ];
    }

    /**
     * Convert field value to index key (handles type conversion)
     * @param mixed $value Field value
     * @return string Index key
     */
    private function fieldIndexValueKey($value){
        if($value === null){
            return '__null__';
        }
        if(is_bool($value)){
            return $value ? '__true__' : '__false__';
        }
        return (string)$value;
    }

    /**
     * Convert index key back to original value type
     * @param string $key Index key
     * @param mixed $originalValue Sample original value for type detection
     * @return mixed Converted value
     */
    private function fieldIndexKeyToValue($key){
        if($key === '__null__'){
            return null;
        }
        if($key === '__true__'){
            return true;
        }
        if($key === '__false__'){
            return false;
        }
        return $key;
    }

    /**
     * Drop a field index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function dropFieldIndex($dbname, $field){
        $dbname = $this->sanitizeDbName($dbname);

        // Handle sharded databases
        if($this->isSharded($dbname)){
            $meta = $this->getCachedMeta($dbname);
            if($meta !== null){
                // Check if index exists in first shard
                if(!$this->hasFieldIndex($dbname, $field, $meta['shards'][0]['id'])){
                    return ['success' => false, 'error' => 'Index does not exist'];
                }
                foreach($meta['shards'] as $shard){
                    $this->deleteFieldIndexFile($dbname, $field, $shard['id']);
                }
                // Also delete global field index
                $this->deleteGlobalFieldIndex($dbname, $field);
            }
        } else {
            // Check if index exists
            if(!$this->hasFieldIndex($dbname, $field, null)){
                return ['success' => false, 'error' => 'Index does not exist'];
            }
            $this->deleteFieldIndexFile($dbname, $field);
        }

        $this->invalidateFieldIndexCache($dbname, $field);
        return ['success' => true, 'error' => null];
    }

    /**
     * Get list of field indexes for a database
     * @param string $dbname Database name
     * @return array ['fields' => array, 'sharded' => bool]
     */
    public function getFieldIndexes($dbname){
        $dbname = $this->sanitizeDbName($dbname);

        if($this->isSharded($dbname)){
            // For sharded, check shard 0
            $fields = $this->getIndexedFields($dbname, 0);
            return ['fields' => $fields, 'sharded' => true];
        }

        $fields = $this->getIndexedFields($dbname);
        return ['fields' => $fields, 'sharded' => false];
    }

    /**
     * Rebuild a field index (useful after bulk operations)
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array Result from createFieldIndex
     */
    public function rebuildFieldIndex($dbname, $field){
        $this->dropFieldIndex($dbname, $field);
        return $this->createFieldIndex($dbname, $field);
    }

    /**
     * Get keys matching a field value using field index
     * Returns null if no index exists (caller should fall back to scan)
     * @param string $dbname Database name
     * @param string $field Field name
     * @param mixed $value Value to match
     * @param int|null $shardId Shard ID for sharded databases
     * @return array|null Array of matching keys, or null if no index
     */
    public function getKeysFromFieldIndex($dbname, $field, $value, $shardId = null){
        $index = $this->readFieldIndex($dbname, $field, $shardId);
        if($index === null){
            return null;
        }

        $valueKey = $this->fieldIndexValueKey($value);
        if(!isset($index['values'][$valueKey])){
            return []; // Value not in index = no matches
        }

        return $index['values'][$valueKey];
    }

    /**
     * Update field index when a record is inserted
     * @param string $dbname Database name
     * @param array $record Record data
     * @param int $key Record key
     * @param int|null $shardId Shard ID
     */
    private function updateFieldIndexOnInsert($dbname, $record, $key, $shardId = null){
        if(!$this->fieldIndexEnabled) return;

        // For sharded databases, get indexed fields from any existing shard or global index
        $indexedFields = $this->getIndexedFields($dbname, $shardId);

        // If new shard has no indexes, check if other shards have indexes
        if(empty($indexedFields) && $shardId !== null){
            // Check shard 0 for indexed fields (if it exists)
            $indexedFields = $this->getIndexedFields($dbname, 0);
        }

        foreach($indexedFields as $field){
            // Use array_key_exists to include null values
            if(!array_key_exists($field, $record)) continue;

            $value = $record[$field];
            if(!is_scalar($value) && !is_null($value)) continue;

            $index = $this->readFieldIndex($dbname, $field, $shardId);
            $isNewValue = true;

            if($index === null){
                // Create new field index for this shard
                $index = [
                    'v' => 1,
                    'field' => $field,
                    'shardId' => $shardId,
                    'created' => time(),
                    'values' => []
                ];
            } else {
                $valueKey = $this->fieldIndexValueKey($value);
                $isNewValue = !isset($index['values'][$valueKey]) || empty($index['values'][$valueKey]);
            }

            $valueKey = $this->fieldIndexValueKey($value);
            if(!isset($index['values'][$valueKey])){
                $index['values'][$valueKey] = [];
            }

            // Add key if not already present
            if(!in_array($key, $index['values'][$valueKey])){
                $index['values'][$valueKey][] = $key;
                $this->writeFieldIndex($dbname, $field, $index, $shardId);

                // Update global field index for sharded databases
                if($shardId !== null && $isNewValue){
                    $this->addShardToGlobalIndex($dbname, $field, $value, $shardId);
                }
            }
        }
    }

    /**
     * Update field index when a record is deleted
     * @param string $dbname Database name
     * @param array $record Record data (before deletion)
     * @param int $key Record key
     * @param int|null $shardId Shard ID
     */
    private function updateFieldIndexOnDelete($dbname, $record, $key, $shardId = null){
        if(!$this->fieldIndexEnabled) return;

        $indexedFields = $this->getIndexedFields($dbname, $shardId);
        foreach($indexedFields as $field){
            if(!isset($record[$field])) continue;

            $value = $record[$field];
            if(!is_scalar($value) && !is_null($value)) continue;

            $index = $this->readFieldIndex($dbname, $field, $shardId);
            if($index === null) continue;

            $valueKey = $this->fieldIndexValueKey($value);
            if(isset($index['values'][$valueKey])){
                $index['values'][$valueKey] = array_values(
                    array_filter($index['values'][$valueKey], function($k) use ($key) {
                        return $k != $key;
                    })
                );
                // Remove empty value entries and update global index
                $shouldUpdateGlobalIndex = false;
                if(empty($index['values'][$valueKey])){
                    unset($index['values'][$valueKey]);
                    $shouldUpdateGlobalIndex = true;
                }

                // Write field index FIRST (so global index update sees the new state)
                $this->writeFieldIndex($dbname, $field, $index, $shardId);

                // Then update global field index for sharded databases
                if($shouldUpdateGlobalIndex && $shardId !== null){
                    $this->removeShardFromGlobalIndex($dbname, $field, $value, $shardId);
                }
            }
        }
    }

    /**
     * Update field index when a record is updated
     * @param string $dbname Database name
     * @param array $oldRecord Old record data
     * @param array $newRecord New record data
     * @param int $key Record key
     * @param int|null $shardId Shard ID
     */
    private function updateFieldIndexOnUpdate($dbname, $oldRecord, $newRecord, $key, $shardId = null){
        if(!$this->fieldIndexEnabled) return;

        $indexedFields = $this->getIndexedFields($dbname, $shardId);
        foreach($indexedFields as $field){
            $oldValue = isset($oldRecord[$field]) ? $oldRecord[$field] : null;
            $newValue = isset($newRecord[$field]) ? $newRecord[$field] : null;

            // Skip if value unchanged
            if($oldValue === $newValue) continue;

            // Skip non-scalar values
            if((!is_scalar($oldValue) && !is_null($oldValue)) ||
               (!is_scalar($newValue) && !is_null($newValue))) continue;

            $index = $this->readFieldIndex($dbname, $field, $shardId);
            if($index === null) continue;

            // Remove from old value
            $oldKey = $this->fieldIndexValueKey($oldValue);
            $oldValueBecomesEmpty = false;
            if(isset($index['values'][$oldKey])){
                $index['values'][$oldKey] = array_values(
                    array_filter($index['values'][$oldKey], function($k) use ($key) {
                        return $k != $key;
                    })
                );
                if(empty($index['values'][$oldKey])){
                    unset($index['values'][$oldKey]);
                    $oldValueBecomesEmpty = true;
                }
            }

            // Add to new value
            $newValueKey = $this->fieldIndexValueKey($newValue);
            $newValueWasEmpty = !isset($index['values'][$newValueKey]) || empty($index['values'][$newValueKey]);
            if(!isset($index['values'][$newValueKey])){
                $index['values'][$newValueKey] = [];
            }
            if(!in_array($key, $index['values'][$newValueKey])){
                $index['values'][$newValueKey][] = $key;
            }

            $this->writeFieldIndex($dbname, $field, $index, $shardId);

            // Update global field index for sharded databases
            if($shardId !== null){
                // Remove shard from old value's global index if shard no longer has old value
                if($oldValueBecomesEmpty){
                    $this->removeShardFromGlobalIndex($dbname, $field, $oldValue, $shardId);
                }
                // Add shard to new value's global index if this is first record with new value
                if($newValueWasEmpty){
                    $this->addShardToGlobalIndex($dbname, $field, $newValue, $shardId);
                }
            }
        }
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
        $dbname = $this->sanitizeDbName($dbname);

        if($this->isSharded($dbname)){
            $result = $this->flushAllShardBuffers($dbname);
            return ['success' => true, 'flushed' => $result['flushed'], 'error' => null];
        } else {
            return $this->flushBufferToMain($dbname);
        }
    }

    /**
     * Flush all buffers for all known databases
     * Called automatically on shutdown if bufferAutoFlushOnShutdown is true
     * @return array ['databases' => int, 'flushed' => int]
     */
    public function flushAllBuffers(){
        $dbDir = $this->dbDir;
        $totalFlushed = 0;
        $dbCount = 0;

        // Find all buffer files
        $bufferFiles = glob($dbDir . '*.buffer');
        if($bufferFiles === false){
            $bufferFiles = [];
        }

        // Track which databases we've processed
        $processedDbs = [];

        foreach($bufferFiles as $bufferFile){
            $basename = basename($bufferFile);

            // Extract database name from buffer file name
            // Format: hash-dbname.nonedb.buffer or hash-dbname_s0.nonedb.buffer
            if(preg_match('/^[a-f0-9]+-(.+?)(?:_s\d+)?\.nonedb\.buffer$/', $basename, $matches)){
                $dbname = $matches[1];

                // Avoid processing same DB multiple times
                if(isset($processedDbs[$dbname])){
                    continue;
                }
                $processedDbs[$dbname] = true;

                // Check if sharded or non-sharded
                if($this->isSharded($dbname)){
                    $result = $this->flushAllShardBuffers($dbname);
                    $totalFlushed += $result['flushed'];
                } else {
                    $result = $this->flushBufferToMain($dbname);
                    if($result['success']){
                        $totalFlushed += $result['flushed'];
                    }
                }
                $dbCount++;
            }
        }

        return ['databases' => $dbCount, 'flushed' => $totalFlushed];
    }

    /**
     * Get buffer information for a database
     * @param string $dbname
     * @return array Buffer statistics
     */
    public function getBufferInfo($dbname){
        $dbname = $this->sanitizeDbName($dbname);

        $info = [
            'enabled' => $this->bufferEnabled,
            'sizeLimit' => $this->bufferSizeLimit,
            'countLimit' => $this->bufferCountLimit,
            'flushInterval' => $this->bufferFlushInterval,
            'buffers' => []
        ];

        if($this->isSharded($dbname)){
            $meta = $this->getCachedMeta($dbname);
            if($meta !== null && isset($meta['shards'])){
                foreach($meta['shards'] as $shard){
                    $bufferPath = $this->getShardBufferPath($dbname, $shard['id']);
                    $info['buffers']['shard_' . $shard['id']] = [
                        'exists' => $this->hasBuffer($bufferPath),
                        'size' => $this->getBufferSize($bufferPath),
                        'records' => $this->hasBuffer($bufferPath) ? $this->getBufferRecordCount($bufferPath) : 0
                    ];
                }
            }
        } else {
            $bufferPath = $this->getBufferPath($dbname);
            $info['buffers']['main'] = [
                'exists' => $this->hasBuffer($bufferPath),
                'size' => $this->getBufferSize($bufferPath),
                'records' => $this->hasBuffer($bufferPath) ? $this->getBufferRecordCount($bufferPath) : 0
            ];
        }

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
     * Compact a database by removing null entries
     * Works for both sharded and non-sharded databases
     * @param string $dbname
     * @return array
     */
    public function compact($dbname){
        $dbname = $this->sanitizeDbName($dbname);
        $result = array("success" => false, "freedSlots" => 0);

        // Handle non-sharded database
        if(!$this->isSharded($dbname)){
            $hash = $this->hashDBName($dbname);
            $fullDBPath = $this->dbDir . $hash . "-" . $dbname . ".nonedb";

            if(!$this->cachedFileExists($fullDBPath)){
                $result['status'] = 'database_not_found';
                return $result;
            }

            // Ensure JSONL format (auto-migrate v2 if needed)
            $this->ensureJsonlFormat($dbname);

            $index = $this->readJsonlIndex($dbname);
            if($index === null){
                $result['status'] = 'read_error';
                return $result;
            }

            $freedSlots = $index['d'];  // Dirty count = freed slots
            $totalRecords = count($index['o']);  // Active records in index

            $compactResult = $this->compactJsonl($dbname);

            $result['success'] = true;
            $result['freedSlots'] = $freedSlots;
            $result['totalRecords'] = $totalRecords;
            $result['sharded'] = false;
            return $result;
        }

        // Handle sharded database
        $meta = $this->getCachedMeta($dbname);
        if($meta === null){
            $result['status'] = 'meta_read_error';
            return $result;
        }

        $allRecords = [];
        // Use meta's deletedCount for freedSlots (JSONL index 'd' may be 0 after auto-compaction)
        $freedSlots = $meta['deletedCount'] ?? 0;

        // v3.0.0: Collect all non-null records from all shards (JSONL format)
        foreach($meta['shards'] as $shard){
            $shardId = $shard['id'];
            $shardPath = $this->getShardPath($dbname, $shardId);

            // Ensure JSONL format (auto-migrate if needed)
            $this->ensureJsonlFormat($dbname, $shardId);

            // Read from JSONL
            $jsonlIndex = $this->readJsonlIndex($dbname, $shardId);
            if($jsonlIndex !== null){
                foreach($jsonlIndex['o'] as $globalKey => $location){
                    $record = $this->readJsonlRecord($shardPath, $location[0], $location[1]);
                    if($record !== null){
                        unset($record['key']); // Remove key as it will be reassigned
                        $allRecords[] = $record;
                    }
                }
            }

            // Delete old shard file and index
            if(file_exists($shardPath)){
                unlink($shardPath);
            }
            $indexPath = $this->getJsonlIndexPath($dbname, $shardId);
            if(file_exists($indexPath)){
                unlink($indexPath);
            }
            // Clear cache
            unset($this->indexCache[$indexPath]);
            unset($this->jsonlFormatCache[$shardPath]);
        }

        // Recalculate and rebuild shards
        $totalRecords = count($allRecords);
        $numShards = (int) ceil($totalRecords / $this->shardSize);
        if($numShards === 0) $numShards = 1;

        $newMeta = array(
            "version" => 1,
            "shardSize" => $this->shardSize,
            "totalRecords" => $totalRecords,
            "deletedCount" => 0,
            "nextKey" => $totalRecords,
            "shards" => []
        );

        // v3.0.0: Write shards in JSONL format
        for($shardId = 0; $shardId < $numShards; $shardId++){
            $start = $shardId * $this->shardSize;
            $shardRecords = array_slice($allRecords, $start, $this->shardSize);

            $newMeta['shards'][] = array(
                "id" => $shardId,
                "file" => "_s" . $shardId,
                "count" => count($shardRecords),
                "deleted" => 0
            );

            // Write shard in JSONL format
            $shardPath = $this->getShardPath($dbname, $shardId);
            $baseKey = $shardId * $this->shardSize;

            $index = [
                'v' => 3,
                'format' => 'jsonl',
                'created' => time(),
                'n' => 0,
                'd' => 0,
                'o' => []
            ];

            $buffer = '';
            $offset = 0;

            foreach($shardRecords as $localKey => $record){
                $globalKey = $baseKey + $localKey;
                $record['key'] = $globalKey;

                $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                $length = strlen($json) - 1;

                $index['o'][$globalKey] = [$offset, $length];
                $offset += strlen($json);
                $index['n']++;

                $buffer .= $json;
            }

            // Write JSONL file
            file_put_contents($shardPath, $buffer, LOCK_EX);
            $this->markFileExists($shardPath);
            $this->jsonlFormatCache[$shardPath] = true;

            // Write JSONL index
            $this->writeJsonlIndex($dbname, $index, $shardId);
        }

        $this->writeMeta($dbname, $newMeta);

        // Rebuild index after compaction (keys are reassigned)
        $this->invalidateIndexCache($dbname);
        @unlink($this->getIndexPath($dbname));
        $this->buildIndex($dbname);

        $result['success'] = true;
        $result['freedSlots'] = $freedSlots;
        $result['newShardCount'] = $numShards;
        $result['sharded'] = true;
        return $result;
    }

    /**
     * Manually trigger migration to sharded format
     * @param string $dbname
     * @return array ["success" => bool, "status" => string]
     */
    public function migrate($dbname){
        $dbname = $this->sanitizeDbName($dbname);

        if($this->isSharded($dbname)){
            return array("success" => true, "status" => "already_sharded");
        }

        // Check if legacy database exists
        $hash = $this->hashDBName($dbname);
        $legacyPath = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
        if(!$this->cachedFileExists($legacyPath)){
            return array("success" => false, "status" => "database_not_found");
        }

        $result = $this->migrateToSharded($dbname);
        if($result){
            return array("success" => true, "status" => "migrated");
        }
        return array("success" => false, "status" => "migration_failed");
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

    // ==========================================
    // QUERY BUILDER (FLUENT INTERFACE)
    // ==========================================

    /**
     * Create a new query builder for fluent interface
     * @param string $dbname
     * @return noneDBQuery
     */
    public function query(string $dbname): noneDBQuery {
        return new noneDBQuery($this, $dbname);
    }
}

/**
 * Query Builder for noneDB - Fluent Interface
 *
 * Allows method chaining for queries:
 * $db->query("users")->where(["active" => true])->sort("name")->limit(10)->get();
 */
class noneDBQuery {
    private noneDB $db;
    private string $dbname;
    private array $whereFilters = [];
    private array $orWhereFilters = [];
    private array $whereInFilters = [];
    private array $whereNotInFilters = [];
    private array $whereNotFilters = [];
    private array $likeFilters = [];
    private array $notLikeFilters = [];
    private array $betweenFilters = [];
    private array $notBetweenFilters = [];
    private array $selectFields = [];
    private array $exceptFields = [];
    private ?string $groupByField = null;
    private array $havingFilters = [];
    private array $searchFilters = [];
    private array $joinConfigs = [];
    private ?string $sortField = null;
    private string $sortOrder = 'asc';
    private ?int $limitCount = null;
    private int $offsetCount = 0;

    /**
     * @param noneDB $db
     * @param string $dbname
     */
    public function __construct(noneDB $db, string $dbname) {
        $this->db = $db;
        $this->dbname = $dbname;
    }

    // ==========================================
    // FILTER HELPER METHODS (v3.0.0)
    // ==========================================

    /**
     * Check if a record matches all advanced filters (single-pass optimization)
     * Consolidates whereNot, whereIn, whereNotIn, like, notLike, between, notBetween, search
     * @param array $record
     * @return bool
     */
    private function matchesAdvancedFilters(array $record): bool {
        // whereNot filters
        foreach ($this->whereNotFilters as $field => $value) {
            if (array_key_exists($field, $record) && $record[$field] === $value) {
                return false;
            }
        }

        // whereIn filters
        foreach ($this->whereInFilters as $filter) {
            if (!array_key_exists($filter['field'], $record)) return false;
            if (!in_array($record[$filter['field']], $filter['values'], true)) return false;
        }

        // whereNotIn filters
        foreach ($this->whereNotInFilters as $filter) {
            if (array_key_exists($filter['field'], $record)) {
                if (in_array($record[$filter['field']], $filter['values'], true)) return false;
            }
        }

        // like filters
        foreach ($this->likeFilters as $like) {
            if (!isset($record[$like['field']])) return false;
            $value = $record[$like['field']];
            if (is_array($value) || is_object($value)) return false;
            $pattern = $like['pattern'];
            if (strpos($pattern, '^') === 0 || substr($pattern, -1) === '$') {
                $regex = '/' . $pattern . '/i';
            } else {
                $regex = '/' . preg_quote($pattern, '/') . '/i';
            }
            if (!preg_match($regex, (string)$value)) return false;
        }

        // notLike filters
        foreach ($this->notLikeFilters as $notLike) {
            if (isset($record[$notLike['field']])) {
                $value = $record[$notLike['field']];
                if (!is_array($value) && !is_object($value)) {
                    $pattern = $notLike['pattern'];
                    if (strpos($pattern, '^') === 0 || substr($pattern, -1) === '$') {
                        $regex = '/' . $pattern . '/i';
                    } else {
                        $regex = '/' . preg_quote($pattern, '/') . '/i';
                    }
                    if (preg_match($regex, (string)$value)) return false;
                }
            }
        }

        // between filters
        foreach ($this->betweenFilters as $between) {
            if (!isset($record[$between['field']])) return false;
            $value = $record[$between['field']];
            if ($value < $between['min'] || $value > $between['max']) return false;
        }

        // notBetween filters
        foreach ($this->notBetweenFilters as $notBetween) {
            if (isset($record[$notBetween['field']])) {
                $value = $record[$notBetween['field']];
                if ($value >= $notBetween['min'] && $value <= $notBetween['max']) return false;
            }
        }

        // search filters
        foreach ($this->searchFilters as $search) {
            $term = strtolower($search['term']);
            if ($term === '') continue;
            $fields = $search['fields'];
            $found = false;
            $searchFields = empty($fields) ? array_keys($record) : $fields;
            foreach ($searchFields as $field) {
                if (!isset($record[$field])) continue;
                $value = $record[$field];
                if (is_array($value) || is_object($value)) continue;
                if (strpos(strtolower((string)$value), $term) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) return false;
        }

        return true;
    }

    /**
     * Check if we have any advanced filters that need single-pass processing
     * @return bool
     */
    private function hasAdvancedFilters(): bool {
        return count($this->whereNotFilters) > 0 ||
               count($this->whereInFilters) > 0 ||
               count($this->whereNotInFilters) > 0 ||
               count($this->likeFilters) > 0 ||
               count($this->notLikeFilters) > 0 ||
               count($this->betweenFilters) > 0 ||
               count($this->notBetweenFilters) > 0 ||
               count($this->searchFilters) > 0;
    }

    // ==========================================
    // CHAINABLE METHODS (return $this)
    // ==========================================

    /**
     * Add where filter (AND condition)
     * @param array $filters
     * @return self
     */
    public function where(array $filters): self {
        $this->whereFilters = array_merge($this->whereFilters, $filters);
        return $this;
    }

    /**
     * Add pattern matching filter
     * @param string $field
     * @param string $pattern Use ^ for starts with, $ for ends with
     * @return self
     */
    public function like(string $field, string $pattern): self {
        $this->likeFilters[] = ['field' => $field, 'pattern' => $pattern];
        return $this;
    }

    /**
     * Add range filter (min <= value <= max)
     * @param string $field
     * @param mixed $min
     * @param mixed $max
     * @return self
     */
    public function between(string $field, $min, $max): self {
        $this->betweenFilters[] = ['field' => $field, 'min' => $min, 'max' => $max];
        return $this;
    }

    /**
     * Set sort order
     * @param string $field
     * @param string $order 'asc' or 'desc'
     * @return self
     */
    public function sort(string $field, string $order = 'asc'): self {
        $this->sortField = $field;
        $this->sortOrder = $order;
        return $this;
    }

    /**
     * Limit results
     * @param int $count
     * @return self
     */
    public function limit(int $count): self {
        $this->limitCount = $count;
        return $this;
    }

    /**
     * Skip first N results (for pagination)
     * @param int $count
     * @return self
     */
    public function offset(int $count): self {
        $this->offsetCount = $count;
        return $this;
    }

    /**
     * Alias for offset() - skip first N results
     * @param int $count
     * @return self
     */
    public function skip(int $count): self {
        return $this->offset($count);
    }

    /**
     * Alias for sort() - set sort order
     * @param string $field
     * @param string $order 'asc' or 'desc'
     * @return self
     */
    public function orderBy(string $field, string $order = 'asc'): self {
        return $this->sort($field, $order);
    }

    /**
     * Add OR where filter
     * @param array $filters
     * @return self
     */
    public function orWhere(array $filters): self {
        $this->orWhereFilters[] = $filters;
        return $this;
    }

    /**
     * Filter where field value is in given array
     * @param string $field
     * @param array $values
     * @return self
     */
    public function whereIn(string $field, array $values): self {
        $this->whereInFilters[] = ['field' => $field, 'values' => $values];
        return $this;
    }

    /**
     * Filter where field value is NOT in given array
     * @param string $field
     * @param array $values
     * @return self
     */
    public function whereNotIn(string $field, array $values): self {
        $this->whereNotInFilters[] = ['field' => $field, 'values' => $values];
        return $this;
    }

    /**
     * Filter where field value does NOT match
     * @param array $filters
     * @return self
     */
    public function whereNot(array $filters): self {
        $this->whereNotFilters = array_merge($this->whereNotFilters, $filters);
        return $this;
    }

    /**
     * Filter where field does NOT match pattern
     * @param string $field
     * @param string $pattern
     * @return self
     */
    public function notLike(string $field, string $pattern): self {
        $this->notLikeFilters[] = ['field' => $field, 'pattern' => $pattern];
        return $this;
    }

    /**
     * Filter where field value is NOT between min and max
     * @param string $field
     * @param mixed $min
     * @param mixed $max
     * @return self
     */
    public function notBetween(string $field, $min, $max): self {
        $this->notBetweenFilters[] = ['field' => $field, 'min' => $min, 'max' => $max];
        return $this;
    }

    /**
     * Select only specific fields in results
     * @param array $fields
     * @return self
     */
    public function select(array $fields): self {
        $this->selectFields = $fields;
        return $this;
    }

    /**
     * Exclude specific fields from results
     * @param array $fields
     * @return self
     */
    public function except(array $fields): self {
        $this->exceptFields = $fields;
        return $this;
    }

    /**
     * Group results by field
     * @param string $field
     * @return self
     */
    public function groupBy(string $field): self {
        $this->groupByField = $field;
        return $this;
    }

    /**
     * Filter groups by aggregate condition
     * @param string $aggregate 'count', 'sum:field', 'avg:field', 'min:field', 'max:field'
     * @param string $operator '>', '<', '>=', '<=', '=', '!='
     * @param mixed $value
     * @return self
     */
    public function having(string $aggregate, string $operator, $value): self {
        $this->havingFilters[] = [
            'aggregate' => $aggregate,
            'operator' => $operator,
            'value' => $value
        ];
        return $this;
    }

    /**
     * Full-text search across multiple fields
     * @param string $term Search term
     * @param array $fields Fields to search in (empty = all string fields)
     * @return self
     */
    public function search(string $term, array $fields = []): self {
        $this->searchFilters[] = ['term' => $term, 'fields' => $fields];
        return $this;
    }

    /**
     * Join with another database
     * @param string $foreignDb Foreign database name
     * @param string $localKey Local key field
     * @param string $foreignKey Foreign key field
     * @param string|null $alias Alias for joined data (default: foreignDb name)
     * @return self
     */
    public function join(string $foreignDb, string $localKey, string $foreignKey, ?string $alias = null): self {
        $this->joinConfigs[] = [
            'foreignDb' => $foreignDb,
            'localKey' => $localKey,
            'foreignKey' => $foreignKey,
            'alias' => $alias ?? $foreignDb
        ];
        return $this;
    }

    // ==========================================
    // TERMINAL METHODS (execute and return result)
    // ==========================================

    /**
     * Execute query and get all results
     * @return array
     */
    public function get(): array {
        // 1. Base query - get all records first if we have OR conditions
        if (count($this->orWhereFilters) > 0) {
            // With OR conditions, we need all records first
            $results = $this->db->find($this->dbname, 0);
            if ($results === false) return [];

            // Apply WHERE + OR WHERE logic
            $results = array_filter($results, function($record) {
                // Check main WHERE filters (AND logic)
                $matchesWhere = true;
                if (count($this->whereFilters) > 0) {
                    foreach ($this->whereFilters as $field => $value) {
                        if (!array_key_exists($field, $record) || $record[$field] !== $value) {
                            $matchesWhere = false;
                            break;
                        }
                    }
                }

                // Check OR WHERE filters
                $matchesOrWhere = false;
                foreach ($this->orWhereFilters as $orFilter) {
                    $orMatch = true;
                    foreach ($orFilter as $field => $value) {
                        if (!array_key_exists($field, $record) || $record[$field] !== $value) {
                            $orMatch = false;
                            break;
                        }
                    }
                    if ($orMatch) {
                        $matchesOrWhere = true;
                        break;
                    }
                }

                // Record matches if (WHERE conditions match) OR (any OR WHERE matches)
                return $matchesWhere || $matchesOrWhere;
            });
            $results = array_values($results);
        } else {
            // No OR conditions, use standard WHERE
            $filters = count($this->whereFilters) > 0 ? $this->whereFilters : 0;
            $results = $this->db->find($this->dbname, $filters);
            if ($results === false) return [];
        }

        // 2-9. Apply all advanced filters in single pass (v3.0.0 optimization)
        // Replaces multiple array_filter calls with one pass for better performance
        if ($this->hasAdvancedFilters()) {
            $filtered = [];
            // Early exit optimization: when no join/groupBy/sort, we can stop at limit+offset
            $canEarlyExit = empty($this->joinConfigs) &&
                            $this->groupByField === null &&
                            $this->sortField === null &&
                            $this->limitCount !== null;
            $earlyExitTarget = $canEarlyExit ? ($this->limitCount + $this->offsetCount) : PHP_INT_MAX;

            foreach ($results as $record) {
                if ($this->matchesAdvancedFilters($record)) {
                    $filtered[] = $record;
                    // Early exit when we have enough records
                    if (count($filtered) >= $earlyExitTarget) {
                        break;
                    }
                }
            }
            $results = $filtered;
        }

        // 10. Apply joins
        foreach ($this->joinConfigs as $join) {
            $foreignData = $this->db->find($join['foreignDb'], 0);
            if ($foreignData === false) continue;

            $foreignIndexed = [];
            foreach ($foreignData as $fRecord) {
                if (isset($fRecord[$join['foreignKey']])) {
                    $key = $fRecord[$join['foreignKey']];
                    // Skip array/object keys - they can't be used as array indices
                    if (is_array($key) || is_object($key)) continue;
                    $foreignIndexed[$key] = $fRecord;
                }
            }

            foreach ($results as &$record) {
                if (isset($record[$join['localKey']])) {
                    $localValue = $record[$join['localKey']];
                    // Skip array/object values - they can't be used as array indices
                    if (is_array($localValue) || is_object($localValue)) {
                        $record[$join['alias']] = null;
                    } else {
                        $record[$join['alias']] = $foreignIndexed[$localValue] ?? null;
                    }
                } else {
                    $record[$join['alias']] = null;
                }
            }
            unset($record);
        }

        // 11. Apply groupBy
        if ($this->groupByField !== null) {
            $groups = [];
            foreach ($results as $record) {
                $groupKey = isset($record[$this->groupByField]) ? $record[$this->groupByField] : '__null__';
                if (!isset($groups[$groupKey])) {
                    $groups[$groupKey] = [
                        '_group' => $groupKey === '__null__' ? null : $groupKey,
                        '_items' => [],
                        '_count' => 0
                    ];
                }
                $groups[$groupKey]['_items'][] = $record;
                $groups[$groupKey]['_count']++;
            }

            // Apply having filters
            foreach ($this->havingFilters as $having) {
                $groups = array_filter($groups, function($group) use ($having) {
                    $aggregate = $having['aggregate'];
                    $operator = $having['operator'];
                    $compareValue = $having['value'];

                    // Calculate aggregate value
                    if ($aggregate === 'count') {
                        $aggValue = $group['_count'];
                    } elseif (strpos($aggregate, ':') !== false) {
                        [$aggType, $aggField] = explode(':', $aggregate, 2);
                        $values = array_filter(
                            array_column($group['_items'], $aggField),
                            'is_numeric'
                        );
                        switch ($aggType) {
                            case 'sum':
                                $aggValue = array_sum($values);
                                break;
                            case 'avg':
                                $aggValue = count($values) > 0 ? array_sum($values) / count($values) : 0;
                                break;
                            case 'min':
                                $aggValue = count($values) > 0 ? min($values) : null;
                                break;
                            case 'max':
                                $aggValue = count($values) > 0 ? max($values) : null;
                                break;
                            default:
                                return true;
                        }
                    } else {
                        return true;
                    }

                    // Compare
                    switch ($operator) {
                        case '>': return $aggValue > $compareValue;
                        case '<': return $aggValue < $compareValue;
                        case '>=': return $aggValue >= $compareValue;
                        case '<=': return $aggValue <= $compareValue;
                        case '=': return $aggValue == $compareValue;
                        case '!=': return $aggValue != $compareValue;
                        default: return true;
                    }
                });
            }

            $results = array_values($groups);
        }

        // 12. Sort
        if ($this->sortField !== null && count($results) > 0) {
            $sorted = $this->db->sort($results, $this->sortField, $this->sortOrder);
            if ($sorted !== false) {
                $results = $sorted;
            }
        }

        // 13. Offset + Limit
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $results = array_slice($results, $this->offsetCount, $this->limitCount);
        }

        // 14. Apply select/except fields
        if (count($this->selectFields) > 0 || count($this->exceptFields) > 0) {
            $results = array_map(function($record) {
                if (count($this->selectFields) > 0) {
                    // Include only selected fields (always include 'key')
                    $filtered = [];
                    foreach ($this->selectFields as $field) {
                        if (array_key_exists($field, $record)) {
                            $filtered[$field] = $record[$field];
                        }
                    }
                    if (isset($record['key']) && !in_array('key', $this->selectFields)) {
                        $filtered['key'] = $record['key'];
                    }
                    return $filtered;
                } else {
                    // Exclude specified fields
                    foreach ($this->exceptFields as $field) {
                        unset($record[$field]);
                    }
                    return $record;
                }
            }, $results);
        }

        return $results;
    }

    /**
     * Get first matching record
     * @return array|null
     */
    public function first(): ?array {
        $originalLimit = $this->limitCount;
        $this->limitCount = 1;
        $results = $this->get();
        $this->limitCount = $originalLimit;
        return $results[0] ?? null;
    }

    /**
     * Get last matching record
     * @return array|null
     */
    public function last(): ?array {
        $results = $this->get();
        return count($results) > 0 ? $results[count($results) - 1] : null;
    }

    /**
     * Count matching records
     * v3.0.0 optimization: Uses fast-path for unfiltered count
     * @return int
     */
    public function count(): int {
        // Fast-path: No filters = use direct count from index/meta
        if($this->isUnfiltered()){
            return $this->db->count($this->dbname, 0);
        }
        return count($this->get());
    }

    /**
     * Check if query has no filters applied
     * Used for fast-path count optimization
     * @return bool
     */
    private function isUnfiltered(): bool {
        return empty($this->whereFilters)
            && empty($this->orWhereFilters)
            && empty($this->whereInFilters)
            && empty($this->whereNotInFilters)
            && empty($this->whereNotFilters)
            && empty($this->likeFilters)
            && empty($this->notLikeFilters)
            && empty($this->betweenFilters)
            && empty($this->notBetweenFilters)
            && empty($this->searchFilters);
    }

    /**
     * Check if any records match
     * @return bool
     */
    public function exists(): bool {
        $originalLimit = $this->limitCount;
        $this->limitCount = 1;
        $count = count($this->get());
        $this->limitCount = $originalLimit;
        return $count > 0;
    }

    // ==========================================
    // AGGREGATION METHODS
    // ==========================================

    /**
     * Sum of field values
     * @param string $field
     * @return float
     */
    public function sum(string $field): float {
        $results = $this->get();
        $sum = 0;
        foreach ($results as $record) {
            if (isset($record[$field]) && is_numeric($record[$field])) {
                $sum += $record[$field];
            }
        }
        return $sum;
    }

    /**
     * Average of field values
     * @param string $field
     * @return float
     */
    public function avg(string $field): float {
        $results = $this->get();
        $sum = 0;
        $count = 0;
        foreach ($results as $record) {
            if (isset($record[$field]) && is_numeric($record[$field])) {
                $sum += $record[$field];
                $count++;
            }
        }
        return $count > 0 ? $sum / $count : 0;
    }

    /**
     * Minimum value of field
     * @param string $field
     * @return mixed|null
     */
    public function min(string $field) {
        $results = $this->get();
        $min = null;
        foreach ($results as $record) {
            if (isset($record[$field])) {
                if ($min === null || $record[$field] < $min) {
                    $min = $record[$field];
                }
            }
        }
        return $min;
    }

    /**
     * Maximum value of field
     * @param string $field
     * @return mixed|null
     */
    public function max(string $field) {
        $results = $this->get();
        $max = null;
        foreach ($results as $record) {
            if (isset($record[$field])) {
                if ($max === null || $record[$field] > $max) {
                    $max = $record[$field];
                }
            }
        }
        return $max;
    }

    /**
     * Get unique values of field
     * @param string $field
     * @return array
     */
    public function distinct(string $field): array {
        $results = $this->get();
        $values = [];
        foreach ($results as $record) {
            if (isset($record[$field]) && !in_array($record[$field], $values, true)) {
                $values[] = $record[$field];
            }
        }
        return $values;
    }

    // ==========================================
    // WRITE METHODS
    // ==========================================

    /**
     * Update matching records
     * @param array $set Fields to update
     * @return array ["n" => count]
     */
    public function update(array $set): array {
        $results = $this->get();
        if (count($results) === 0) {
            return ['n' => 0];
        }

        $keys = array_map(fn($r) => $r['key'], $results);
        return $this->db->update($this->dbname, [
            ['key' => $keys],
            ['set' => $set]
        ]);
    }

    /**
     * Delete matching records
     * @return array ["n" => count]
     */
    public function delete(): array {
        $results = $this->get();
        if (count($results) === 0) {
            return ['n' => 0];
        }

        $keys = array_map(fn($r) => $r['key'], $results);
        return $this->db->delete($this->dbname, ['key' => $keys]);
    }

    /**
     * Remove specified fields from matching records
     * This permanently removes fields from the database
     * @param array $fields Fields to remove
     * @return array ["n" => count of updated records, "fields_removed" => array of removed fields]
     */
    public function removeFields(array $fields): array {
        if (empty($fields)) {
            return ['n' => 0, 'fields_removed' => [], 'error' => 'No fields specified'];
        }

        // Filter out reserved 'key' field - cannot be removed
        $fieldsToRemove = array_values(array_filter($fields, fn($f) => $f !== 'key'));
        if (empty($fieldsToRemove)) {
            return ['n' => 0, 'fields_removed' => [], 'error' => 'Cannot remove reserved field: key'];
        }

        $results = $this->get();
        if (count($results) === 0) {
            return ['n' => 0, 'fields_removed' => $fieldsToRemove];
        }

        $updatedCount = 0;
        $actuallyRemoved = [];

        foreach ($results as $record) {
            $key = $record['key'];
            $fieldsRemovedFromRecord = [];

            // Check which fields exist in this record
            foreach ($fieldsToRemove as $field) {
                if (array_key_exists($field, $record)) {
                    $fieldsRemovedFromRecord[] = $field;
                    if (!in_array($field, $actuallyRemoved)) {
                        $actuallyRemoved[] = $field;
                    }
                }
            }

            if (!empty($fieldsRemovedFromRecord)) {
                // Create new record without the removed fields
                $newRecord = [];
                foreach ($record as $k => $v) {
                    if ($k !== 'key' && !in_array($k, $fieldsRemovedFromRecord)) {
                        $newRecord[$k] = $v;
                    }
                }

                // Directly update the record at its position
                $this->updateRecordAtPosition($key, $newRecord);
                $updatedCount++;
            }
        }

        return [
            'n' => $updatedCount,
            'fields_removed' => $actuallyRemoved
        ];
    }

    /**
     * Helper method to update a record at a specific key position
     * v3.0: Uses JSONL format via parent's updateJsonlRecord method
     * @param int $key The record key
     * @param array $newData The new record data
     */
    private function updateRecordAtPosition(int $key, array $newData): void {
        $dbname = $this->callPrivateMethod('sanitizeDbName', $this->dbname);
        $hash = $this->callPrivateMethod('hashDBName', $dbname);
        $dbDir = $this->getDbDir();
        $fullPath = $dbDir . $hash . "-" . $dbname . ".nonedb";

        // Check if sharded
        $metaPath = $fullPath . ".meta";
        if (file_exists($metaPath)) {
            // Sharded database - find correct shard
            $metaContent = file_get_contents($metaPath);
            $meta = json_decode($metaContent, true);
            if ($meta) {
                $shardSize = $meta['shardSize'];
                $shardId = (int)floor($key / $shardSize);
                $localKey = $key % $shardSize;

                // Use JSONL update method for sharded data
                $this->callPrivateMethod('updateJsonlRecord', $dbname, $localKey, $newData, $shardId);
            }
        } else {
            // Non-sharded database - use JSONL update
            $this->callPrivateMethod('updateJsonlRecord', $dbname, $key, $newData, null);
        }
    }

    /**
     * Get the database directory from parent noneDB instance
     * @return string
     */
    private function getDbDir(): string {
        $reflector = new \ReflectionClass($this->db);
        $property = $reflector->getProperty('dbDir');
        $property->setAccessible(true);
        return $property->getValue($this->db);
    }

    /**
     * Call a private method on the parent noneDB instance
     * @param string $methodName
     * @param mixed ...$args
     * @return mixed
     */
    private function callPrivateMethod(string $methodName, ...$args) {
        $reflector = new \ReflectionClass($this->db);
        $method = $reflector->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invoke($this->db, ...$args);
    }
}
?>