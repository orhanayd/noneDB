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

    /**
     * hash to db name for security
     */
    private function hashDBName($dbname){
        return hash_pbkdf2("sha256", $dbname, $this->secretKey, 1000, 20);
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
     * Read shard metadata
     * @param string $dbname
     * @return array|null
     */
    private function readMeta($dbname){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $path = $this->getMetaPath($dbname);
        if(!file_exists($path)) return null;
        $content = file_get_contents($path);
        return json_decode($content, true);
    }

    /**
     * Write shard metadata
     * @param string $dbname
     * @param array $meta
     */
    private function writeMeta($dbname, $meta){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $path = $this->getMetaPath($dbname);
        file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Get data from a specific shard
     * @param string $dbname
     * @param int $shardId
     * @return array
     */
    private function getShardData($dbname, $shardId){
        $path = $this->getShardPath($dbname, $shardId);
        if(!file_exists($path)) return array("data" => []);
        $content = file_get_contents($path);
        return json_decode($content, true);
    }

    /**
     * Write data to a specific shard
     * @param string $dbname
     * @param int $shardId
     * @param array $data
     */
    private function writeShardData($dbname, $shardId, $data){
        $path = $this->getShardPath($dbname, $shardId);
        file_put_contents($path, json_encode($data), LOCK_EX);
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
     * Insert data into sharded database
     * @param string $dbname
     * @param array $data
     * @return array
     */
    private function insertSharded($dbname, $data){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $main_response = array("n" => 0);

        $meta = $this->readMeta($dbname);
        if($meta === null){
            return $main_response;
        }

        // Get the last shard
        $lastShardIdx = count($meta['shards']) - 1;
        $lastShard = $meta['shards'][$lastShardIdx];
        $shardId = $lastShard['id'];

        // Calculate current count in last shard
        $currentShardCount = $lastShard['count'] + $lastShard['deleted'];

        // Check if we need a new shard
        if($currentShardCount >= $this->shardSize){
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

        // Get shard data
        $shardData = $this->getShardData($dbname, $shardId);
        $insertedCount = 0;

        // Check if data is a list of records
        if($this->isRecordList($data)){
            foreach($data as $item){
                if(!is_array($item)) continue;
                if($this->hasReservedKeyField($item)){
                    $main_response['error'] = "You cannot set key name to key";
                    return $main_response;
                }

                // Check if current shard is full, create new one
                if($currentShardCount >= $this->shardSize){
                    // Write current shard
                    $this->writeShardData($dbname, $shardId, $shardData);
                    $meta['shards'][$lastShardIdx]['count'] += $insertedCount;

                    // Create new shard
                    $shardId++;
                    $meta['shards'][] = array(
                        "id" => $shardId,
                        "file" => "_s" . $shardId,
                        "count" => 0,
                        "deleted" => 0
                    );
                    $lastShardIdx = count($meta['shards']) - 1;
                    $shardData = array("data" => []);
                    $currentShardCount = 0;
                    $insertedCount = 0;
                }

                $shardData['data'][] = $item;
                $currentShardCount++;
                $insertedCount++;
            }
        } else {
            // Single record
            if($this->hasReservedKeyField($data)){
                $main_response['error'] = "You cannot set key name to key";
                return $main_response;
            }
            $shardData['data'][] = $data;
            $insertedCount = 1;
        }

        // Write final shard data
        $this->writeShardData($dbname, $shardId, $shardData);

        // Update meta
        $meta['shards'][$lastShardIdx]['count'] += $insertedCount;
        $meta['totalRecords'] += $insertedCount;
        $meta['nextKey'] += $insertedCount;
        $this->writeMeta($dbname, $meta);

        return array("n" => ($this->isRecordList($data) ? $insertedCount : 1));
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
     * Update records in sharded database
     * @param string $dbname
     * @param array $data
     * @return array
     */
    private function updateSharded($dbname, $data){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $main_response = array("n" => 0);

        // Find records to update
        $find = $this->findSharded($dbname, $data[0]);
        if($find === false || count($find) === 0){
            return $main_response;
        }

        $meta = $this->readMeta($dbname);
        if($meta === null){
            return $main_response;
        }

        // Group updates by shard
        $shardUpdates = [];
        foreach($find as $record){
            $globalKey = $record['key'];
            $shardId = $this->getShardIdForKey($globalKey);
            $localKey = $this->getLocalKey($globalKey);

            if(!isset($shardUpdates[$shardId])){
                $shardUpdates[$shardId] = [];
            }
            $shardUpdates[$shardId][$localKey] = $data[1]['set'];
        }

        // Apply updates to each shard
        $updatedCount = 0;
        foreach($shardUpdates as $shardId => $updates){
            $shardData = $this->getShardData($dbname, $shardId);

            foreach($updates as $localKey => $setValues){
                if(isset($shardData['data'][$localKey]) && $shardData['data'][$localKey] !== null){
                    foreach($setValues as $field => $value){
                        $shardData['data'][$localKey][$field] = $value;
                    }
                    $updatedCount++;
                }
            }

            $this->writeShardData($dbname, $shardId, $shardData);
        }

        return array("n" => $updatedCount);
    }

    /**
     * Delete records from sharded database
     * @param string $dbname
     * @param array $data
     * @return array
     */
    private function deleteSharded($dbname, $data){
        $dbname = preg_replace("/[^A-Za-z0-9' -]/", '', $dbname);
        $main_response = array("n" => 0);

        // Find records to delete
        $find = $this->findSharded($dbname, $data);
        if($find === false || count($find) === 0){
            return $main_response;
        }

        $meta = $this->readMeta($dbname);
        if($meta === null){
            return $main_response;
        }

        // Group deletes by shard
        $shardDeletes = [];
        foreach($find as $record){
            $globalKey = $record['key'];
            $shardId = $this->getShardIdForKey($globalKey);
            $localKey = $this->getLocalKey($globalKey);

            if(!isset($shardDeletes[$shardId])){
                $shardDeletes[$shardId] = [];
            }
            $shardDeletes[$shardId][] = $localKey;
        }

        // Apply deletes to each shard
        $deletedCount = 0;
        foreach($shardDeletes as $shardId => $localKeys){
            $shardData = $this->getShardData($dbname, $shardId);

            foreach($localKeys as $localKey){
                if(isset($shardData['data'][$localKey]) && $shardData['data'][$localKey] !== null){
                    $shardData['data'][$localKey] = null;
                    $deletedCount++;

                    // Update shard meta
                    foreach($meta['shards'] as &$shard){
                        if($shard['id'] === $shardId){
                            $shard['count']--;
                            $shard['deleted']++;
                            break;
                        }
                    }
                }
            }

            $this->writeShardData($dbname, $shardId, $shardData);
        }

        // Update meta
        $meta['totalRecords'] -= $deletedCount;
        $meta['deletedCount'] += $deletedCount;
        $this->writeMeta($dbname, $meta);

        return array("n" => $deletedCount);
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
     * get data from db file
     * @param string $fullDBPath
     * @param int $retryCount
     */
    private function getData($fullDBPath, $retryCount = 0){
        clearstatcache(true, $fullDBPath);
        if (is_readable($fullDBPath)) {
            $size = filesize($fullDBPath);
            if($size === 0){
                return array("data" => []);
            }
            $dosya = fopen( $fullDBPath, "rb" );
            if( $dosya === false ) {
                    return false;
            }
            $dbContents = json_decode(fread( $dosya, $size), true);
            fclose($dosya);
            return $dbContents;
        }else{
            if($retryCount >= 5){
                return false;
            }
            usleep(10000); // 10ms bekle
            return $this->getData($fullDBPath, $retryCount + 1);
        }
    }

    /**
     * insert data to db file
     * @param string $fullDBPath is db path with file name
     * @param array $buffer is full data
     * @param int $retryCount
     */
    private function insertData($fullDBPath, $buffer, $retryCount = 0){
        clearstatcache(true, $fullDBPath);
        if (is_writable($fullDBPath)) {
            file_put_contents($fullDBPath, json_encode($buffer), LOCK_EX);
            return true;
        }else{
            if($retryCount >= 5){
                return false;
            }
            usleep(10000); // 10ms bekle
            return $this->insertData($fullDBPath, $buffer, $retryCount + 1);
        }
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

        $buffer = $this->getData($fullDBPath);
        if($buffer === false){
            $buffer = array("data" => []);
        }

        // Check if data is a list of records or a single record
        if($this->isRecordList($data)){
            // Multiple records to insert
            $countData = 0;
            foreach($data as $item){
                if(!is_array($item)){
                    continue;
                }
                if($this->hasReservedKeyField($item)){
                    $main_response['error']="You cannot set key name to key";
                    return $main_response;
                }
                $buffer['data'][]=$item;
                $countData++;
            }
            $this->insertData($fullDBPath, $buffer);

            // Auto-migrate to sharded format if threshold reached
            if($this->shardingEnabled && $this->autoMigrate && count($buffer['data']) >= $this->shardSize){
                $this->migrateToSharded($dbname);
            }

            return array("n"=>$countData);
        }else{
            // Single record to insert
            if($this->hasReservedKeyField($data)){
                $main_response['error']="You cannot set key name to key";
                return $main_response;
            }
            $buffer['data'][]=$data;
            $this->insertData($fullDBPath, $buffer);

            // Auto-migrate to sharded format if threshold reached
            if($this->shardingEnabled && $this->autoMigrate && count($buffer['data']) >= $this->shardSize){
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

        $find=$this->find($dbname, $data);
        if($find === false){
            return $main_response;
        }
        if(count($find)>0){
            $buffer = $this->getData($fullDBPath);
            if($buffer === false){
                return $main_response;
            }
            foreach($find as $row){
               $buffer['data'][$row['key']]=null;
            }
            $this->insertData($fullDBPath, $buffer);
            $main_response['n']=count($find);
        }
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

        $find=$this->find($dbname, $data[0]);
        if($find === false){
            return $main_response;
        }
        if(count($find)>0){
            $buffer = $this->getData($fullDBPath);
            if($buffer === false){
                return $main_response;
            }
            foreach($find as $row){
                foreach($data[1]['set'] as $key=>$set){
                    $buffer['data'][$row['key']][$key]=$set;
                }
            }
            $this->insertData($fullDBPath, $buffer);
            $main_response['n']=count($find);
        }
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
            if(isset($record[$field]) && preg_match($regex, (string)$record[$field])){
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
    private array $likeFilters = [];
    private array $betweenFilters = [];
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

    // ==========================================
    // TERMINAL METHODS (execute and return result)
    // ==========================================

    /**
     * Execute query and get all results
     * @return array
     */
    public function get(): array {
        // 1. Base query with where filters
        $filters = count($this->whereFilters) > 0 ? $this->whereFilters : 0;
        $results = $this->db->find($this->dbname, $filters);
        if ($results === false) return [];

        // 2. Apply like filters
        foreach ($this->likeFilters as $like) {
            $results = array_filter($results, function($record) use ($like) {
                if (!isset($record[$like['field']])) return false;
                $pattern = $like['pattern'];
                if (strpos($pattern, '^') === 0 || substr($pattern, -1) === '$') {
                    $regex = '/' . $pattern . '/i';
                } else {
                    $regex = '/' . preg_quote($pattern, '/') . '/i';
                }
                return preg_match($regex, (string)$record[$like['field']]);
            });
            $results = array_values($results);
        }

        // 3. Apply between filters
        foreach ($this->betweenFilters as $between) {
            $results = array_filter($results, function($record) use ($between) {
                if (!isset($record[$between['field']])) return false;
                $value = $record[$between['field']];
                return $value >= $between['min'] && $value <= $between['max'];
            });
            $results = array_values($results);
        }

        // 4. Sort
        if ($this->sortField !== null && count($results) > 0) {
            $sorted = $this->db->sort($results, $this->sortField, $this->sortOrder);
            if ($sorted !== false) {
                $results = $sorted;
            }
        }

        // 5. Offset + Limit
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $results = array_slice($results, $this->offsetCount, $this->limitCount);
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
}
?>