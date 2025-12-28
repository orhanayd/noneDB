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
    private $shardSize=100000;        // Max records per shard (100K)
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

    // JSONL Storage Engine - v2.4.0
    private $jsonlEnabled=true;               // Enable JSONL format for new DBs
    private $jsonlAutoMigrate=true;           // Auto-migrate v2 to JSONL on first access
    private $jsonlFormatCache=[];             // Cache format detection per DB
    private $jsonlGarbageThreshold=0.3;       // Trigger compaction when garbage > 30%

    /**
     * hash to db name for security
     * Uses instance-level caching to avoid expensive PBKDF2 recomputation
     */
    private function hashDBName($dbname){
        if(isset($this->hashCache[$dbname])){
            return $this->hashCache[$dbname];
        }
        $hash = hash_pbkdf2("sha256", $dbname, $this->secretKey, 1000, 20);
        $this->hashCache[$dbname] = $hash;
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $hash = $this->hashDBName($dbname);
        return $this->dbDir . $hash . "-" . $dbname . "_s" . $shardId . ".nonedb";
    }

    /**
     * Get the meta file path for a database
     * @param string $dbname
     * @return string
     */
    private function getMetaPath($dbname){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $hash = $this->hashDBName($dbname);
        return $this->dbDir . $hash . "-" . $dbname . ".nonedb.meta";
    }

    /**
     * Check if a database is sharded
     * @param string $dbname
     * @return bool
     */
    private function isSharded($dbname){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        return file_exists($this->getMetaPath($dbname));
    }

    /**
     * Read shard metadata with atomic locking
     * @param string $dbname
     * @return array|null
     */
    private function readMeta($dbname){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $path = $this->getMetaPath($dbname);
        $result = $this->atomicModify($path, $modifier, null, true);
        if($result['success']){
            $this->invalidateMetaCache($dbname);
        }
        return $result;
    }

    /**
     * Get data from a specific shard with atomic locking
     * @param string $dbname
     * @param int $shardId
     * @return array
     */
    private function getShardData($dbname, $shardId){
        $path = $this->getShardPath($dbname, $shardId);
        return $this->atomicRead($path, array("data" => []));
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $hash = $this->hashDBName($dbname);
        return $this->dbDir . $hash . "-" . $dbname . ".nonedb.idx";
    }

    /**
     * Read index file with caching
     * @param string $dbname
     * @return array|null
     */
    private function readIndex($dbname){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);

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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        unset($this->indexCache[$dbname]);
    }

    /**
     * Build index from existing database data
     * Called automatically on first key-based lookup if index doesn't exist
     * @param string $dbname
     * @return array|null
     */
    private function buildIndex($dbname){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);

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
            $rawData = $this->getData($fullDBPath);

            if($rawData === false){
                return null;
            }

            $index['sharded'] = false;

            foreach($rawData['data'] as $key => $record){
                if($record !== null){
                    // Store just the position for non-sharded DBs
                    $index['entries'][(string)$key] = $key;
                    $index['totalRecords']++;
                }
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
                    // Entry is [shardId, localKey]
                    $shardId = $entry[0];
                    $localKey = $entry[1];

                    $shardData = $this->getShardData($dbname, $shardId);
                    if(isset($shardData['data'][$localKey]) && $shardData['data'][$localKey] !== null){
                        $record = $shardData['data'][$localKey];
                        $record['key'] = $globalKey;
                        $result[] = $record;
                    }
                } else {
                    // Entry is just the position
                    $hash = $this->hashDBName($dbname);
                    $fullDBPath = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
                    $rawData = $this->getData($fullDBPath);

                    if($rawData !== false && isset($rawData['data'][$entry]) && $rawData['data'][$entry] !== null){
                        $record = $rawData['data'][$entry];
                        $record['key'] = $globalKey;
                        $result[] = $record;
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
        if(!file_exists($path)){
            return false;
        }

        // Check cache first
        if(isset($this->jsonlFormatCache[$path])){
            return $this->jsonlFormatCache[$path];
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $hash = $this->hashDBName($dbname);
        if($shardId !== null){
            return $this->dbDir . $hash . "-" . $dbname . "_s" . $shardId . ".nonedb.jidx";
        }
        return $this->dbDir . $hash . "-" . $dbname . ".nonedb.jidx";
    }

    /**
     * Read JSONL index (byte offset map)
     * @param string $dbname
     * @param int|null $shardId
     * @return array|null
     */
    private function readJsonlIndex($dbname, $shardId = null){
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        $cacheKey = $path;

        if(isset($this->indexCache[$cacheKey])){
            return $this->indexCache[$cacheKey];
        }

        $index = $this->atomicRead($path, null);
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

    /**
     * Migrate v2 format to JSONL format
     * @param string $path Source file path
     * @param string $dbname Database name
     * @param int|null $shardId Shard ID or null for non-sharded
     * @return bool Success
     */
    private function migrateToJsonl($path, $dbname, $shardId = null){
        if(!file_exists($path)){
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
        $result = [];

        foreach($keys as $key){
            $key = (int)$key;
            if(!isset($index['o'][$key])){
                continue;
            }

            [$offset, $length] = $index['o'][$key];
            $record = $this->readJsonlRecord($path, $offset, $length);
            if($record !== null){
                $result[] = $record;
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
        // If index provided, use byte offsets for accurate reading
        if($index !== null && isset($index['o'])){
            $results = [];
            // Sort by key to maintain order
            $keys = array_keys($index['o']);
            sort($keys, SORT_NUMERIC);

            foreach($keys as $key){
                $location = $index['o'][$key];
                $record = $this->readJsonlRecord($path, $location[0], $location[1]);
                if($record !== null){
                    $results[] = $record;
                }
            }
            return $results;
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

        // Check if compaction needed (skip during batch operations)
        if(!$skipCompaction && $index['d'] > $index['n'] * $this->jsonlGarbageThreshold){
            $this->compactJsonl($dbname, $shardId);
        }

        return true;
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

        unset($index['o'][$key]);
        $index['d']++;

        $this->writeJsonlIndex($dbname, $index, $shardId);

        // Check if compaction needed
        if($index['d'] > $index['n'] * $this->jsonlGarbageThreshold){
            $this->compactJsonl($dbname, $shardId);
        }

        return true;
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
        if(!$this->jsonlEnabled){
            return false;
        }

        if($shardId !== null){
            $path = $this->getShardPath($dbname, $shardId);
        } else {
            $hash = $this->hashDBName($dbname);
            $path = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
        }

        if(!file_exists($path)){
            return true; // New file will be created in JSONL format
        }

        if($this->isJsonlFormat($path)){
            return true;
        }

        // Auto-migrate if enabled
        if($this->jsonlAutoMigrate){
            return $this->migrateToJsonl($path, $dbname, $shardId);
        }

        return false;
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
        if(!file_exists($path)){
            touch($path);
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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

        // JSONL FORMAT - append to JSONL file
        if($this->jsonlEnabled){
            // Ensure JSONL format exists
            if(!$this->ensureJsonlFormat($dbname)){
                $this->createJsonlDatabase($dbname);
            }

            $index = $this->readJsonlIndex($dbname);
            if($index === null){
                @rename($tempPath, $bufferPath);
                return ['success' => false, 'flushed' => 0, 'error' => 'Failed to read index'];
            }

            // Bulk append buffer records
            $this->bulkAppendJsonl($mainPath, $bufferRecords, $index);
            $this->writeJsonlIndex($dbname, $index);

            // Delete temp file
            @unlink($tempPath);
            $this->bufferLastFlush[$dbname] = time();
            return ['success' => true, 'flushed' => count($bufferRecords), 'error' => null];
        }

        // V2 FORMAT - Atomically merge buffer into main DB
        $result = $this->atomicModify($mainPath, function($data) use ($bufferRecords) {
            if($data === null){
                $data = array("data" => []);
            }
            foreach($bufferRecords as $record){
                $data['data'][] = $record;
            }
            return $data;
        }, array("data" => []));

        if($result['success']){
            // Delete temp file only after successful merge
            @unlink($tempPath);
            // Update last flush time
            $this->bufferLastFlush[$dbname] = time();
            return ['success' => true, 'flushed' => count($bufferRecords), 'error' => null];
        } else {
            // Restore buffer from temp
            @rename($tempPath, $bufferPath);
            return ['success' => false, 'flushed' => 0, 'error' => $result['error']];
        }
    }

    /**
     * Flush buffer to shard
     * @param string $dbname
     * @param int $shardId
     * @return array ['success' => bool, 'flushed' => int, 'error' => string|null]
     */
    private function flushShardBuffer($dbname, $shardId){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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

        // Atomically merge into shard
        $result = $this->modifyShardData($dbname, $shardId, function($data) use ($bufferRecords) {
            foreach($bufferRecords as $record){
                $data['data'][] = $record;
            }
            return $data;
        });

        if($result['success']){
            @unlink($tempPath);
            $flushKey = $dbname . '_s' . $shardId;
            $this->bufferLastFlush[$flushKey] = time();
            return ['success' => true, 'flushed' => count($bufferRecords), 'error' => null];
        } else {
            @rename($tempPath, $bufferPath);
            return ['success' => false, 'flushed' => 0, 'error' => $result['error']];
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $hash = $this->hashDBName($dbname);
        $legacyPath = $this->dbDir . $hash . "-" . $dbname . ".nonedb";

        if(!file_exists($legacyPath)){
            return false;
        }

        // Check if JSONL format
        $allRecords = [];
        $totalRecords = 0;
        $deletedCount = 0;

        if($this->jsonlEnabled && $this->isJsonlFormat($legacyPath)){
            // JSONL format - read using index
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
        } else {
            // V2 format - read using getData
            $legacyData = $this->getData($legacyPath);
            if($legacyData === false || !isset($legacyData['data'])){
                return false;
            }

            $allRecords = $legacyData['data'];

            // Count actual records and deleted entries
            foreach($allRecords as $record){
                if($record === null){
                    $deletedCount++;
                } else {
                    $totalRecords++;
                }
            }
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

            // Write shard file
            $this->writeShardData($dbname, $shardId, array("data" => $shardRecords));
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

        return true;
    }

    /**
     * Insert data into sharded database with atomic locking
     * @param string $dbname
     * @param array $data
     * @return array
     */
    private function insertSharded($dbname, $data){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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

        // Atomically write to each affected shard
        foreach($shardWrites as $shardId => $writeInfo){
            $this->modifyShardData($dbname, $shardId, function($shardData) use ($writeInfo) {
                if($shardData === null){
                    $shardData = array("data" => []);
                }
                foreach($writeInfo['items'] as $item){
                    $shardData['data'][] = $item;
                }
                return $shardData;
            });
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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
                    $localKey = $this->getLocalKey($globalKey);

                    // Check if shard exists
                    $shardExists = false;
                    foreach($meta['shards'] as $shard){
                        if($shard['id'] === $shardId){
                            $shardExists = true;
                            break;
                        }
                    }

                    if(!$shardExists) continue;

                    $shardData = $this->getShardData($dbname, $shardId);
                    if(isset($shardData['data'][$localKey]) && $shardData['data'][$localKey] !== null){
                        $record = $shardData['data'][$localKey];
                        $record['key'] = $globalKey;
                        $result[] = $record;
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
     * Update records in sharded database with atomic locking
     * @param string $dbname
     * @param array $data
     * @return array
     */
    private function updateSharded($dbname, $data){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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

        // Update each shard atomically
        $totalUpdated = 0;
        foreach($meta['shards'] as $shard){
            $shardId = $shard['id'];
            $baseKey = $shardId * $shardSize;
            $updatedInShard = 0;

            $this->modifyShardData($dbname, $shardId, function($shardData) use ($filters, $setValues, $baseKey, &$updatedInShard) {
                if($shardData === null || !isset($shardData['data'])){
                    return array("data" => []);
                }

                foreach($shardData['data'] as $localKey => &$record){
                    if($record === null) continue;

                    // Check if record matches filters
                    $match = true;
                    foreach($filters as $filterKey => $filterValue){
                        if($filterKey === 'key'){
                            $globalKey = $baseKey + $localKey;
                            // Support both single key and array of keys
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
                        foreach($setValues as $field => $value){
                            $record[$field] = $value;
                        }
                        $updatedInShard++;
                    }
                }
                return $shardData;
            });

            $totalUpdated += $updatedInShard;
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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

        // Delete from each shard atomically
        foreach($meta['shards'] as $shard){
            $shardId = $shard['id'];
            $baseKey = $shardId * $shardSize;
            $deletedInShard = 0;
            $shardDeletedKeys = [];

            $this->modifyShardData($dbname, $shardId, function($shardData) use ($filters, $baseKey, &$deletedInShard, &$shardDeletedKeys) {
                if($shardData === null || !isset($shardData['data'])){
                    return array("data" => []);
                }

                foreach($shardData['data'] as $localKey => &$record){
                    if($record === null) continue;

                    // Check if record matches filters
                    $match = true;
                    foreach($filters as $filterKey => $filterValue){
                        if($filterKey === 'key'){
                            $globalKey = $baseKey + $localKey;
                            // Support both single key and array of keys
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
                        $shardData['data'][$localKey] = null;
                        $shardDeletedKeys[] = $baseKey + $localKey;
                        $deletedInShard++;
                    }
                }
                return $shardData;
            });

            if($deletedInShard > 0){
                $shardDeletions[$shardId] = $deletedInShard;
                $deletedKeys = array_merge($deletedKeys, $shardDeletedKeys);
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
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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
         * check db is in db folder?
         */
        if(file_exists($fullDBPath)){
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
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";
        if(!file_exists($this->dbDir)){
            mkdir($this->dbDir, 0777);
        }
        if(!file_exists($fullDBPath)){
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
            $specificDb = preg_replace("/[^A-Za-z0-9' -]/", '', $info);
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
     * Get data from db file with atomic locking
     * @param string $fullDBPath
     * @param int $retryCount (deprecated, kept for compatibility)
     * @return array|false
     */
    private function getData($fullDBPath, $retryCount = 0){
        $result = $this->atomicRead($fullDBPath, array("data" => []));
        return $result !== null ? $result : false;
    }

    /**
     * Insert/write data to db file with atomic locking
     * @param string $fullDBPath is db path with file name
     * @param array $buffer is full data
     * @param int $retryCount (deprecated, kept for compatibility)
     * @return bool
     */
    private function insertData($fullDBPath, $buffer, $retryCount = 0){
        return $this->atomicWrite($fullDBPath, $buffer);
    }

    /**
     * Atomically modify database file: read, apply callback, write
     * This prevents race conditions in concurrent access
     * @param string $fullDBPath
     * @param callable $modifier
     * @return array ['success' => bool, 'data' => modified data, 'error' => string|null]
     */
    private function modifyData($fullDBPath, callable $modifier){
        return $this->atomicModify($fullDBPath, $modifier, array("data" => []));
    }

    /**
     * read db all data
     * @param string $dbname
     * @param mixed $filters 0 for all, array for filter
     */
    public function find($dbname, $filters=0){
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);

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

        // ============================================
        // JSONL FORMAT - O(1) key lookups
        // ============================================
        if($this->jsonlEnabled && $this->ensureJsonlFormat($dbname)){
            $jsonlIndex = $this->readJsonlIndex($dbname);

            // Key-based search - O(1) lookup
            if(is_array($filters) && count($filters) > 0){
                $filterKeys = array_keys($filters);
                if($filterKeys[0] === "key"){
                    $result = $this->findByKeyJsonl($dbname, $filters['key']);
                    return $result !== null ? $result : [];
                }
            }

            // Get all records or filter-based search
            $allRecords = $this->readAllJsonl($fullDBPath, $jsonlIndex);

            // Return all if no filter
            if(is_int($filters) || (is_array($filters) && count($filters) === 0)){
                return $allRecords;
            }

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

        // ============================================
        // LEGACY v2 FORMAT
        // ============================================
        $rawData = $this->getData($fullDBPath);
        if($rawData === false || !isset($rawData['data'])){
            return false;
        }
        $dbContents = $rawData['data'];

        // Return all records if filter is integer (0) or empty array
        if(is_int($filters) || (is_array($filters) && count($filters) === 0)){
            // Add 'key' field to each record for consistency
            $result = [];
            foreach($dbContents as $index => $record){
                if($record !== null){
                    $record['key'] = $index;
                    $result[] = $record;
                }
            }
            return $result;
        }

        if(is_array($filters)){
            $absResult=[];
            $result=[];
            $filterKeys = array_keys($filters);

            // Handle key-based search - use index if available
            if(count($filterKeys) > 0 && $filterKeys[0]==="key"){
                // Try index first for quick existence check
                $index = $this->getOrBuildIndex($dbname);
                if($index !== null){
                    $indexResult = $this->findByKeyWithIndex($dbname, $filters['key'], $index);
                    if($indexResult !== null){
                        return $indexResult;
                    }
                }

                // Fallback: direct array access (already have data loaded)
                if(is_array($filters['key'])){
                    foreach($filters['key'] as $idx=>$key){
                        if(isset($dbContents[(int)$key]) && $dbContents[(int)$key] !== null){
                            $result[$idx]=$dbContents[(int)$key];
                            $result[$idx]['key']=(int)$key;
                        }
                    }
                }else{
                    // Check if key exists and is not null before accessing
                    $keyIndex = (int)$filters['key'];
                    if(isset($dbContents[$keyIndex]) && $dbContents[$keyIndex] !== null){
                        $result[]=$dbContents[$keyIndex];
                        $result[0]['key']=$keyIndex;
                    }
                }
                return $result;
            }

            // Handle field-based search
            $count = count($dbContents);
            for ($i=0; $i<$count; $i++){
                $add=true;
                $raw=[];
                foreach($filters as $key=>$value){
                    if($dbContents[$i]===null){
                        $add=false;
                        break;
                    }
                    if(!array_key_exists($key, $dbContents[$i])){
                        $add=false;
                        break;
                    }
                    if($dbContents[$i][$key]!==$value){
                        $add=false;
                        break;
                    }
                }
                if($add){
                    $raw=$dbContents[$i];
                    $raw['key']=$i;
                    $absResult[]=$raw;
                }
            }
            $result=$absResult;
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
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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
                $this->checkDB($dbname);
                $dbnameHashed = $this->hashDBName($dbname);
                $fullDBPath = $this->dbDir.$dbnameHashed."-".$dbname.".nonedb";

                // Check record count based on format
                if($this->jsonlEnabled && $this->isJsonlFormat($fullDBPath)){
                    // JSONL format - use index count
                    $index = $this->readJsonlIndex($dbname);
                    if($index !== null && $index['n'] >= $this->shardSize){
                        $this->migrateToSharded($dbname);
                    }
                } else {
                    // V2 format - use data array count
                    $rawData = $this->getData($fullDBPath);
                    if($rawData !== false && isset($rawData['data']) && count($rawData['data']) >= $this->shardSize){
                        $this->migrateToSharded($dbname);
                    }
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

        // JSONL FORMAT - O(1) append
        if($this->jsonlEnabled){
            // Ensure JSONL format (migrate if needed)
            if(!$this->ensureJsonlFormat($dbname)){
                // DB doesn't exist yet, create as JSONL
                $this->createJsonlDatabase($dbname);
            }

            $index = $this->readJsonlIndex($dbname);
            if($index === null){
                return array("n" => 0, "error" => "Failed to read index");
            }

            // Use bulk append for multiple records
            $this->bulkAppendJsonl($fullDBPath, $validItems, $index);
            $this->writeJsonlIndex($dbname, $index);

            // Auto-migrate to sharded format if threshold reached
            if($this->shardingEnabled && $this->autoMigrate && $index['n'] >= $this->shardSize){
                $this->migrateToSharded($dbname);
            }

            return array("n" => $countData);
        }

        // V2 FORMAT - Original atomic modify
        $result = $this->modifyData($fullDBPath, function($buffer) use ($validItems) {
            if($buffer === null){
                $buffer = array("data" => []);
            }
            foreach($validItems as $item){
                $buffer['data'][] = $item;
            }
            return $buffer;
        });

        if(!$result['success']){
            return array("n" => 0, "error" => $result['error'] ?? 'Insert failed');
        }

        // Auto-migrate to sharded format if threshold reached
        if($this->shardingEnabled && $this->autoMigrate && count($result['data']['data']) >= $this->shardSize){
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
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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

        // JSONL FORMAT
        if($this->jsonlEnabled && $this->ensureJsonlFormat($dbname)){
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

            // Filter-based delete - need to scan
            $index = $this->readJsonlIndex($dbname);
            if($index === null){
                return array("n" => 0);
            }

            // First pass: collect all keys to delete
            $keysToDelete = [];
            foreach($index['o'] as $key => $location){
                $record = $this->readJsonlRecord($fullDBPath, $location[0], $location[1]);
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

            // Second pass: delete collected keys
            foreach($keysToDelete as $key){
                if($this->deleteJsonlRecord($dbname, $key)){
                    $deletedCount++;
                }
            }

            return array("n" => $deletedCount);
        }

        // V2 FORMAT - Use atomic modify to find and delete in single locked operation
        $filters = $data;
        $deletedCount = 0;
        $deletedKeys = [];  // Track deleted keys for index update

        $result = $this->modifyData($fullDBPath, function($buffer) use ($filters, &$deletedCount, &$deletedKeys) {
            if($buffer === null || !isset($buffer['data'])){
                return array("data" => []);
            }

            // Find matching records within the lock
            foreach($buffer['data'] as $key => $record){
                if($record === null) continue;

                $match = true;
                foreach($filters as $filterKey => $filterValue){
                    // Special handling for 'key' filter
                    if($filterKey === 'key'){
                        // Support both single key and array of keys
                        $targetKeys = is_array($filterValue) ? $filterValue : [$filterValue];
                        if(!in_array($key, $targetKeys)){
                            $match = false;
                            break;
                        }
                    } else if(!isset($record[$filterKey]) || $record[$filterKey] !== $filterValue){
                        $match = false;
                        break;
                    }
                }
                if($match){
                    $buffer['data'][$key] = null;
                    $deletedKeys[] = $key;
                    $deletedCount++;
                }
            }
            return $buffer;
        });

        if(!$result['success']){
            $main_response['error'] = $result['error'] ?? 'Delete failed';
            return $main_response;
        }

        // Update index with deleted keys
        if($deletedCount > 0){
            $this->updateIndexOnDelete($dbname, $deletedKeys);
        }

        $main_response['n'] = $deletedCount;
        return $main_response;
    }

    /**
     * update function
     * @param string $dbname
     * @param array $data
     */
    public function update($dbname, $data){
        $dbname =  preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
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

        // JSONL FORMAT
        if($this->jsonlEnabled && $this->ensureJsonlFormat($dbname)){
            $index = $this->readJsonlIndex($dbname);
            if($index === null){
                return array("n" => 0);
            }

            // Key-based update - O(1) lookup
            if(isset($filters['key'])){
                $targetKeys = is_array($filters['key']) ? $filters['key'] : [$filters['key']];
                foreach($targetKeys as $key){
                    if(!isset($index['o'][$key])) continue;

                    $record = $this->readJsonlRecord($fullDBPath, $index['o'][$key][0], $index['o'][$key][1]);
                    if($record === null) continue;

                    // Apply updates
                    foreach($setData as $setKey => $setValue){
                        $record[$setKey] = $setValue;
                    }

                    // Remove key field (will be re-added by updateJsonlRecord)
                    unset($record['key']);

                    // Skip compaction during batch updates (last one can trigger)
                    $isLast = ($key === end($targetKeys));
                    if($this->updateJsonlRecord($dbname, $key, $record, null, !$isLast)){
                        $updatedCount++;
                    }
                }
                return array("n" => $updatedCount);
            }

            // Filter-based update - need to scan
            $keysToUpdate = [];
            foreach($index['o'] as $key => $location){
                $record = $this->readJsonlRecord($fullDBPath, $location[0], $location[1]);
                if($record === null) continue;

                $match = true;
                foreach($filters as $filterKey => $filterValue){
                    if(!isset($record[$filterKey]) || $record[$filterKey] !== $filterValue){
                        $match = false;
                        break;
                    }
                }

                if($match){
                    $keysToUpdate[] = ['key' => $key, 'record' => $record];
                }
            }

            // Apply updates after collecting all matching keys
            $lastIdx = count($keysToUpdate) - 1;
            foreach($keysToUpdate as $idx => $item){
                $record = $item['record'];

                // Apply updates
                foreach($setData as $setKey => $setValue){
                    $record[$setKey] = $setValue;
                }

                // Remove key field (will be re-added by updateJsonlRecord)
                unset($record['key']);

                // Only allow compaction on last update
                if($this->updateJsonlRecord($dbname, $item['key'], $record, null, $idx !== $lastIdx)){
                    $updatedCount++;
                }
            }

            return array("n" => $updatedCount);
        }

        // V2 FORMAT - Use atomic modify to find and update in single locked operation
        $result = $this->modifyData($fullDBPath, function($buffer) use ($filters, $setData, &$updatedCount) {
            if($buffer === null || !isset($buffer['data'])){
                return array("data" => []);
            }

            // Find matching records within the lock
            foreach($buffer['data'] as $key => $record){
                if($record === null) continue;

                $match = true;
                foreach($filters as $filterKey => $filterValue){
                    // Special handling for 'key' filter
                    if($filterKey === 'key'){
                        // Support both single key and array of keys
                        $targetKeys = is_array($filterValue) ? $filterValue : [$filterValue];
                        if(!in_array($key, $targetKeys)){
                            $match = false;
                            break;
                        }
                    } else if(!isset($record[$filterKey]) || $record[$filterKey] !== $filterValue){
                        $match = false;
                        break;
                    }
                }
                if($match){
                    foreach($setData as $setKey => $setValue){
                        $buffer['data'][$key][$setKey] = $setValue;
                    }
                    $updatedCount++;
                }
            }
            return $buffer;
        });

        if(!$result['success']){
            $main_response['error'] = $result['error'] ?? 'Update failed';
            return $main_response;
        }

        $main_response['n'] = $updatedCount;
        return $main_response;
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
        $result = $this->find($dbname, $filter);
        if($result === false) return 0;
        return count($result);
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);

        if(!$this->isSharded($dbname)){
            // Check if legacy database exists
            $hash = $this->hashDBName($dbname);
            $legacyPath = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
            if(file_exists($legacyPath)){
                // Check if JSONL format
                if($this->jsonlEnabled && $this->isJsonlFormat($legacyPath)){
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

                // V2 format
                $data = $this->getData($legacyPath);
                if($data !== false && isset($data['data'])){
                    $count = 0;
                    foreach($data['data'] as $record){
                        if($record !== null) $count++;
                    }
                    return array(
                        "sharded" => false,
                        "shards" => 0,
                        "totalRecords" => $count,
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
    // WRITE BUFFER PUBLIC API
    // ==========================================

    /**
     * Manually flush buffer for a database
     * @param string $dbname
     * @return array ['success' => bool, 'flushed' => int, 'error' => string|null]
     */
    public function flush($dbname){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);

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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);

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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $result = array("success" => false, "freedSlots" => 0);

        // Handle non-sharded database
        if(!$this->isSharded($dbname)){
            $hash = $this->hashDBName($dbname);
            $fullDBPath = $this->dbDir . $hash . "-" . $dbname . ".nonedb";

            if(!file_exists($fullDBPath)){
                $result['status'] = 'database_not_found';
                return $result;
            }

            // Check if JSONL format
            if($this->jsonlEnabled && $this->isJsonlFormat($fullDBPath)){
                // JSONL format - use compactJsonl
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

            // V2 format
            $rawData = $this->getData($fullDBPath);
            if($rawData === false || !isset($rawData['data'])){
                $result['status'] = 'read_error';
                return $result;
            }

            $allRecords = [];
            $freedSlots = 0;

            foreach($rawData['data'] as $record){
                if($record !== null){
                    $allRecords[] = $record;
                } else {
                    $freedSlots++;
                }
            }

            // Write compacted data back
            $this->insertData($fullDBPath, array("data" => $allRecords));

            // Rebuild index after compaction (keys are reassigned)
            $this->invalidateIndexCache($dbname);
            @unlink($this->getIndexPath($dbname));
            $this->buildIndex($dbname);

            $result['success'] = true;
            $result['freedSlots'] = $freedSlots;
            $result['totalRecords'] = count($allRecords);
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
        $freedSlots = 0;

        // Collect all non-null records from all shards
        foreach($meta['shards'] as $shard){
            $shardData = $this->getShardData($dbname, $shard['id']);
            foreach($shardData['data'] as $record){
                if($record !== null){
                    $allRecords[] = $record;
                } else {
                    $freedSlots++;
                }
            }
            // Delete old shard file
            $shardPath = $this->getShardPath($dbname, $shard['id']);
            if(file_exists($shardPath)){
                unlink($shardPath);
            }
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

        for($shardId = 0; $shardId < $numShards; $shardId++){
            $start = $shardId * $this->shardSize;
            $shardRecords = array_slice($allRecords, $start, $this->shardSize);

            $newMeta['shards'][] = array(
                "id" => $shardId,
                "file" => "_s" . $shardId,
                "count" => count($shardRecords),
                "deleted" => 0
            );

            $this->writeShardData($dbname, $shardId, array("data" => $shardRecords));
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);

        if($this->isSharded($dbname)){
            return array("success" => true, "status" => "already_sharded");
        }

        // Check if legacy database exists
        $hash = $this->hashDBName($dbname);
        $legacyPath = $this->dbDir . $hash . "-" . $dbname . ".nonedb";
        if(!file_exists($legacyPath)){
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

        // 2. Apply whereNot filters
        foreach ($this->whereNotFilters as $field => $value) {
            $results = array_filter($results, function($record) use ($field, $value) {
                if (!array_key_exists($field, $record)) return true;
                return $record[$field] !== $value;
            });
            $results = array_values($results);
        }

        // 3. Apply whereIn filters
        foreach ($this->whereInFilters as $filter) {
            $results = array_filter($results, function($record) use ($filter) {
                // Use array_key_exists instead of isset to handle null values
                if (!array_key_exists($filter['field'], $record)) return false;
                return in_array($record[$filter['field']], $filter['values'], true);
            });
            $results = array_values($results);
        }

        // 4. Apply whereNotIn filters
        foreach ($this->whereNotInFilters as $filter) {
            $results = array_filter($results, function($record) use ($filter) {
                // Use array_key_exists instead of isset to handle null values
                if (!array_key_exists($filter['field'], $record)) return true;
                return !in_array($record[$filter['field']], $filter['values'], true);
            });
            $results = array_values($results);
        }

        // 5. Apply like filters
        foreach ($this->likeFilters as $like) {
            $results = array_filter($results, function($record) use ($like) {
                if (!isset($record[$like['field']])) return false;
                $value = $record[$like['field']];
                if (is_array($value) || is_object($value)) return false;
                $pattern = $like['pattern'];
                if (strpos($pattern, '^') === 0 || substr($pattern, -1) === '$') {
                    $regex = '/' . $pattern . '/i';
                } else {
                    $regex = '/' . preg_quote($pattern, '/') . '/i';
                }
                return preg_match($regex, (string)$value);
            });
            $results = array_values($results);
        }

        // 6. Apply notLike filters
        foreach ($this->notLikeFilters as $notLike) {
            $results = array_filter($results, function($record) use ($notLike) {
                if (!isset($record[$notLike['field']])) return true;
                $value = $record[$notLike['field']];
                if (is_array($value) || is_object($value)) return true;
                $pattern = $notLike['pattern'];
                if (strpos($pattern, '^') === 0 || substr($pattern, -1) === '$') {
                    $regex = '/' . $pattern . '/i';
                } else {
                    $regex = '/' . preg_quote($pattern, '/') . '/i';
                }
                return !preg_match($regex, (string)$value);
            });
            $results = array_values($results);
        }

        // 7. Apply between filters
        foreach ($this->betweenFilters as $between) {
            $results = array_filter($results, function($record) use ($between) {
                if (!isset($record[$between['field']])) return false;
                $value = $record[$between['field']];
                return $value >= $between['min'] && $value <= $between['max'];
            });
            $results = array_values($results);
        }

        // 8. Apply notBetween filters
        foreach ($this->notBetweenFilters as $notBetween) {
            $results = array_filter($results, function($record) use ($notBetween) {
                if (!isset($record[$notBetween['field']])) return true;
                $value = $record[$notBetween['field']];
                return $value < $notBetween['min'] || $value > $notBetween['max'];
            });
            $results = array_values($results);
        }

        // 9. Apply search filters
        foreach ($this->searchFilters as $search) {
            $term = strtolower($search['term']);
            if ($term === '') continue; // Skip empty search terms (PHP 7.4 strpos compatibility)
            $fields = $search['fields'];
            $results = array_filter($results, function($record) use ($term, $fields) {
                $searchFields = $fields;
                if (empty($searchFields)) {
                    $searchFields = array_keys($record);
                }
                foreach ($searchFields as $field) {
                    if (!isset($record[$field])) continue;
                    $value = $record[$field];
                    if (is_array($value) || is_object($value)) continue;
                    if (strpos(strtolower((string)$value), $term) !== false) {
                        return true;
                    }
                }
                return false;
            });
            $results = array_values($results);
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
     * @return int
     */
    public function count(): int {
        return count($this->get());
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
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $this->dbname);
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