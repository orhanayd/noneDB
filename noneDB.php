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
    private $shardSize=10000;         // Max records per shard
    private $autoMigrate=true;        // Auto-migrate legacy DBs to sharded format

    // File locking configuration
    private $lockTimeout=5;           // Max seconds to wait for lock
    private $lockRetryDelay=10000;    // Microseconds between lock attempts (10ms)

    /**
     * hash to db name for security
     */
    private function hashDBName($dbname){
        return hash_pbkdf2("sha256", $dbname, $this->secretKey, 1000, 20);
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
     * Write shard metadata with atomic locking
     * @param string $dbname
     * @param array $meta
     * @return bool
     */
    private function writeMeta($dbname, $meta){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $path = $this->getMetaPath($dbname);
        return $this->atomicWrite($path, $meta, true);
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
        return $this->atomicModify($path, $modifier, null, true);
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

        // Read all data from legacy file
        $legacyData = $this->getData($legacyPath);
        if($legacyData === false || !isset($legacyData['data'])){
            return false;
        }

        $allRecords = $legacyData['data'];
        $totalRecords = 0;
        $deletedCount = 0;

        // Count actual records and deleted entries
        foreach($allRecords as $record){
            if($record === null){
                $deletedCount++;
            } else {
                $totalRecords++;
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

        // Atomic insert using meta-level locking
        $shardSize = $this->shardSize;
        $insertedCount = 0;
        $shardWrites = []; // Collect shard modifications

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
                    // Create new shard
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

                // Track which items go to which shard
                if(!isset($shardWrites[$shardId])){
                    $shardWrites[$shardId] = ['items' => [], 'shardIdx' => $lastShardIdx];
                }
                $shardWrites[$shardId]['items'][] = $item;
                $currentShardCount++;
                $insertedCount++;

                // Update meta counts
                $meta['shards'][$lastShardIdx]['count']++;
                $meta['totalRecords']++;
                $meta['nextKey']++;
            }

            return $meta;
        });

        if(!$metaResult['success'] || $metaResult['data'] === null){
            $main_response['error'] = $metaResult['error'] ?? 'Meta update failed';
            return $main_response;
        }

        // Now atomically write to each affected shard
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
        $meta = $this->readMeta($dbname);
        if($meta === null){
            return false;
        }

        // Handle key-based search
        if(is_array($filters) && count($filters) > 0){
            $filterKeys = array_keys($filters);
            if($filterKeys[0] === "key"){
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

        $meta = $this->readMeta($dbname);
        if($meta === null){
            return $main_response;
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

        $meta = $this->readMeta($dbname);
        if($meta === null){
            return $main_response;
        }

        // Track deletions per shard for meta update
        $shardDeletions = [];
        $totalDeleted = 0;

        // Delete from each shard atomically
        foreach($meta['shards'] as $shard){
            $shardId = $shard['id'];
            $baseKey = $shardId * $shardSize;
            $deletedInShard = 0;

            $this->modifyShardData($dbname, $shardId, function($shardData) use ($filters, $baseKey, &$deletedInShard) {
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
                        $deletedInShard++;
                    }
                }
                return $shardData;
            });

            if($deletedInShard > 0){
                $shardDeletions[$shardId] = $deletedInShard;
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
            $infoDB = fopen($fullDBPath."info", "a+");
            fwrite($infoDB, time());
            fclose($infoDB);
            $dbFile=fopen($fullDBPath, 'a+');
            fwrite($dbFile, json_encode(array("data"=>[])));
            fclose($dbFile);
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

        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";
        if(!$this->checkDB($dbname)){
            return false;
        }
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

            // Handle key-based search
            if(count($filterKeys) > 0 && $filterKeys[0]==="key"){
                if(is_array($filters['key'])){
                    foreach($filters['key'] as $index=>$key){
                        if(isset($dbContents[(int)$key]) && $dbContents[(int)$key] !== null){
                            $result[$index]=$dbContents[(int)$key];
                            $result[$index]['key']=(int)$key;
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

        $this->checkDB($dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";

        // Validate data before atomic operation
        if($this->isRecordList($data)){
            // Validate all items first
            $validItems = [];
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

            if(empty($validItems)){
                return array("n"=>0);
            }

            // Atomic insert - read, modify, write in single locked operation
            $countData = count($validItems);
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
                $main_response['error'] = $result['error'] ?? 'Insert failed';
                return $main_response;
            }

            // Auto-migrate to sharded format if threshold reached
            if($this->shardingEnabled && $this->autoMigrate && count($result['data']['data']) >= $this->shardSize){
                $this->migrateToSharded($dbname);
            }

            return array("n"=>$countData);
        }else{
            // Single record validation
            if($this->hasReservedKeyField($data)){
                $main_response['error']="You cannot set key name to key";
                return $main_response;
            }

            // Atomic insert - read, modify, write in single locked operation
            $result = $this->modifyData($fullDBPath, function($buffer) use ($data) {
                if($buffer === null){
                    $buffer = array("data" => []);
                }
                $buffer['data'][] = $data;
                return $buffer;
            });

            if(!$result['success']){
                $main_response['error'] = $result['error'] ?? 'Insert failed';
                return $main_response;
            }

            // Auto-migrate to sharded format if threshold reached
            if($this->shardingEnabled && $this->autoMigrate && count($result['data']['data']) >= $this->shardSize){
                $this->migrateToSharded($dbname);
            }

            return array("n"=>1);
        }
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

        $this->checkDB($dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";

        // Use atomic modify to find and delete in single locked operation
        $filters = $data;
        $deletedCount = 0;

        $result = $this->modifyData($fullDBPath, function($buffer) use ($filters, &$deletedCount) {
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
                    $deletedCount++;
                }
            }
            return $buffer;
        });

        if(!$result['success']){
            $main_response['error'] = $result['error'] ?? 'Delete failed';
            return $main_response;
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

        $this->checkDB($dbname);
        $dbnameHashed=$this->hashDBName($dbname);
        $fullDBPath=$this->dbDir.$dbnameHashed."-".$dbname.".nonedb";

        // Use atomic modify to find and update in single locked operation
        $filters = $data[0];
        $setData = $data[1]['set'];
        $updatedCount = 0;

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

        $meta = $this->readMeta($dbname);
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

            $result['success'] = true;
            $result['freedSlots'] = $freedSlots;
            $result['totalRecords'] = count($allRecords);
            $result['sharded'] = false;
            return $result;
        }

        // Handle sharded database
        $meta = $this->readMeta($dbname);
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
                $shardPath = $dbDir . $hash . "-" . $dbname . "_s" . $shardId . ".nonedb";

                if (file_exists($shardPath)) {
                    $shardContent = file_get_contents($shardPath);
                    $shardData = json_decode($shardContent, true);
                    if ($shardData && isset($shardData['data'])) {
                        $shardData['data'][$localKey] = $newData;
                        file_put_contents($shardPath, json_encode($shardData), LOCK_EX);
                        clearstatcache(true, $shardPath);
                    }
                }
            }
        } else {
            // Non-sharded database
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                $data = json_decode($content, true);
                if ($data && isset($data['data'])) {
                    $data['data'][$key] = $newData;
                    file_put_contents($fullPath, json_encode($data), LOCK_EX);
                    clearstatcache(true, $fullPath);
                }
            }
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