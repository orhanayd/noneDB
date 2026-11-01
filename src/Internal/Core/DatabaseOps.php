<?php
// Databases: create, drop, list, info, key index rebuild
namespace noneDB\Internal\Core;

use function array_map, array_slice, bin2hex, count, file_get_contents, filesize, floatval, implode, is_array,
    is_bool, is_int, is_link, is_string, max, microtime, min, pow, preg_match, random_bytes, round, scandir, sort,
    str_replace, strval;
use const COUNT_RECURSIVE, SORT_STRING;

/**
 * @internal
 */
final class DatabaseOps {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $autoCreateDB;
    private $dbDir;
    private $indexCache;
    private $verifiedIndexes;
    private $directoryListings; // @test
    private $buffer;
    private $cache;
    private $checkpoints;
    private $layout;
    private $locks;
    private $scope;
    private $segments;
    private $shardMeta;
    private $store;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->cache, $this->checkpoints, $this->layout, $this->locks, $this->scope, $this->segments, $this->shardMeta, $this->store);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->autoCreateDB = &$this->k->autoCreateDB;
        $this->dbDir = &$this->k->dbDir;
        $this->indexCache = &$this->k->indexCache;
        $this->verifiedIndexes = &$this->k->verifiedIndexes;
        $this->directoryListings = &$this->rt->directoryListings; // @test
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
        $this->layout = null;
        $this->locks = null;
        $this->scope = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->store = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // PUBLIC INDEX API
    // ==========================================

    /**
     * Make the key indexes (.kix checkpoints) of a database again from its data files, then its field and
     * spatial indexes where they changed.
     * Normal operation never needs this; it is a recovery tool after files were copied or restored by hand.
     * @param string $dbname
     * @return array ['success' => bool, 'totalRecords' => int, 'time' => string]
     */
    public function rebuildIndex($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            return $this->rebuildIndexUnlocked($dbname);
        }, 'rebuildIndex');
    }

    private function rebuildIndexUnlocked($dbname){
        $start = microtime(true);
        $this->buffer->flushPendingBuffers($dbname);
        $segments = $this->segments->listSegments($dbname);
        if(empty($segments)){
            return ['success' => false, 'error' => 'Database does not exist'];
        }
        $total = 0;
        foreach($segments as $segment){
            $total += \noneDB\Internal\Storage\KeyIndex::count($this->checkpoints->rebuildSegmentIndexFromData($dbname, $segment));
            $this->cache->evictBetweenSegments();
        }
        if($this->segments->isSharded($dbname)){
            $this->shardMeta->recountMeta($dbname);
        }
        // After a manual restore the field and spatial indexes are as stale as the key index
        $this->checkpoints->rebuildSecondaryIndexes($dbname, $segments);
        return [
            'success' => true,
            'totalRecords' => $total,
            'time' => round((microtime(true) - $start) * 1000, 2) . 'ms'
        ];
    }

    /**
     * Get index information for a database
     * @param string $dbname
     * @return array|null
     */
    public function getIndexInfo($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname){
            return $this->getIndexInfoUnlocked($dbname);
        });
    }

    private function getIndexInfoUnlocked($dbname){
        $segments = $this->segments->listSegments($dbname);
        if(empty($segments)){
            return null;
        }
        $total = 0;
        $created = null;
        $updated = null;
        foreach($segments as $segment){
            $index = $this->checkpoints->readJsonlIndex($dbname, $segment);
            if($index === null){
                continue;
            }
            $total += \noneDB\Internal\Storage\KeyIndex::count($index);
            $created = $created === null ? ($index['created'] ?? null) : min($created, $index['created'] ?? $created);
            $updated = max((int)$updated, (int)($index['updated'] ?? 0));
            unset($index);
            $this->cache->evictBetweenSegments();
        }
        $sharded = $this->segments->isSharded($dbname);
        return [
            'exists' => true,
            'version' => 1, // of the key-index checkpoint (docs/STORAGE_FORMAT.md §6.4)
            'created' => $created,
            'updated' => $updated,
            'totalRecords' => $total,
            'sharded' => $sharded,
            'path' => $sharded ? $this->shardMeta->getMetaPath($dbname) : $this->checkpoints->getJsonlIndexPath($dbname, null)
        ];
    }

    /**
     * True when the database exists. With autoCreateDB a missing database is created; without it nothing is
     * created and the answer is false.
     * @param string $dbname
     * @return bool
     * @throws \InvalidArgumentException for names with characters outside [A-Za-z0-9' -]
     */
    public function checkDB($dbname=null){
        if($dbname === null || $dbname === '' || $dbname === false){
            return false;
        }
        $dbname = $this->layout->normalizeDbName($dbname);
        if(!$this->autoCreateDB){
            // Only a check: a database that doesn't exist creates nothing (no directory, no lock file)
            return $this->locks->withReadLock($dbname, function() use ($dbname){
                return $this->segments->dbExists($dbname);
            });
        }
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            return $this->checkDBUnlocked($dbname);
        }, 'checkDB');
    }

    private function checkDBUnlocked($dbname){
        if($this->segments->dbExists($dbname)){
            return true;
        }
        if($this->autoCreateDB){
            return $this->createDBUnlocked($dbname);
        }
        return false;
    }

    /**
     * Create a database (STORAGE_FORMAT.md §8.1)
     * @param string $dbname
     * @return bool True if created, false if it already existed
     */
    public function createDB($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            return $this->createDBUnlocked($dbname);
        }, 'createDB');
    }

    /**
     * Create a database (STORAGE_FORMAT.md §8.1): generation 1 with an empty main segment and its first
     * checkpoint, then CURRENT, then FORMAT. Caller holds the database lock exclusively (and STORE.LOCK
     * shared: a database that doesn't exist is only locked through the missing-database path).
     */
    public function createDBUnlocked($dbname){
        if($this->segments->dbExists($dbname)){
            return false;
        }
        $this->layout->createDatabaseFiles($dbname);
        $dataPath = $this->segments->getDataPath($dbname);
        $this->cache->markFileExists($dataPath);
        $index = $this->checkpoints->newJsonlIndex();
        $index['e'] = bin2hex(random_bytes(4));
        $this->indexCache[\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath)] = $index;
        $this->verifiedIndexes[\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath)] = true;
        $this->checkpoints->writeCheckpoint($dbname, null, $index);
        return true;
    }

    /**
     * Delete a database (STORAGE_FORMAT.md §8.2): every file but its lock file
     * @param string $dbname
     * @return array ['success' => bool, 'files' => int deleted file count]
     */
    public function dropDB($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        // Reads of a missing database create nothing: only an existing one is locked exclusively
        if(!$this->locks->withLock($dbname, false, function() use ($dbname){ return $this->segments->dbExists($dbname); })){
            return ['success' => false, 'files' => 0, 'error' => 'Database does not exist'];
        }
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            if(!$this->segments->dbExists($dbname)){
                return ['success' => false, 'files' => 0, 'error' => 'Database does not exist'];
            }
            $this->scope->refuseInUncommittedWrite($dbname, 'dropDB');
            $drop = $this->layout->dropDatabaseFiles($dbname, $this->locks->getLockPath($dbname), true);
            if(!$drop['committed']){
                return ['success' => false, 'files' => 0, 'error' => "Database '{$dbname}' could not be dropped: its files can't be deleted ("
                    . implode(', ', $drop['left']) . ')'];
            }
            return ['success' => true, 'files' => $drop['removed']];
        }, 'dropDB');
    }

    /**
     * Name, creation time and total size of a database (all shards included)
     * @return array|false
     */
    private function getDBInfo($dbname){
        return $this->locks->withLock($dbname, false, function() use ($dbname){
            $format = $this->layout->readFormatRecord($dbname);
            if($format === null){
                return false;
            }
            $size = 0;
            foreach($this->segments->listFilesWithPrefix($this->layout->genDir($dbname) . 'segments/', '/\.jsonl$/') as $file){
                $size += (int)@filesize($file);
            }
            $info = array("name" => $dbname, "createdTime" => $format['createdAt'], "size" => $this->fileSizeConvert($size));
            // Shard files found without a shards.json entry are kept aside (see setOrphanShardsAside)
            $orphans = array_map('basename', $this->segments->listFilesWithPrefix($this->layout->genDir($dbname) . 'segments/', '/\.orphan\.\d+$/'));
            if(!empty($orphans)){
                $info['orphanFiles'] = $orphans;
            }
            return $info;
        });
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

    /**
     * The databases of this key: names (false), names with information (true), or one database's information
     * (its name; false when it doesn't exist)
     */
    public function getDBs($info=false){
        // Handle three cases: false (names only), true (with metadata), string (specific db)
        $withMetadata = false;
        $specificDb = null;

        if(is_bool($info)){
            $withMetadata = $info;
        }else{
            $specificDb = $this->layout->normalizeDbName($info); // reading information never creates the database
        }

        // If specific database requested
        if($specificDb !== null){
            return $this->getDBInfo($specificDb);
        }

        // List all databases of this key: {locator}.ndb4 directories whose FORMAT names a database that
        // maps to that locator with this key
        $dbs = [];
        if(!$this->store->openStore(false)){
            return $dbs;
        }
        $names = [];
        $this->directoryListings++; // @test
        foreach(@scandir($this->dbDir) ?: [] as $entry){
            if(preg_match('/^([0-9a-f]{32})\.ndb4$/', $entry, $m) !== 1 || is_link($this->dbDir . $entry)){
                continue; // a link is no database (STORAGE_FORMAT.md §3.4)
            }
            $format = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbDir . $entry . '/FORMAT'), 'database');
            $dbname = is_array($format) && is_string($format['name'] ?? null) ? $format['name'] : null;
            if($dbname === null || preg_match("/^[A-Za-z0-9' -]+$/", $dbname) !== 1 || $this->store->locator($dbname) !== $m[1]){
                continue;
            }
            $names[] = $dbname;
        }
        sort($names, SORT_STRING);
        foreach($names as $dbname){
            if($withMetadata){
                $info = $this->getDBInfo($dbname);
                if($info !== false){
                    $dbs[] = $info;
                }
            }else{
                $dbs[] = $dbname;
            }
        }
        return $dbs;
    }

    /**
     * The first $limit records of a list of records (a result of find())
     * @param array $array
     * @param int $limit At least 1
     * @return array|false False for invalid arguments
     */
    public function limit($array, $limit=0){
        if(!is_array($array) || !is_int($limit) || $limit <= 0){
            return false;
        }
        if(empty($array)){
            return [];
        }
        // Must be a list of records
        if(count($array) === count($array, COUNT_RECURSIVE)) {
            return false;
        }
        return array_slice($array, 0, $limit);
    }
}
