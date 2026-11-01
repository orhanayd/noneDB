<?php
// Inserts, updates and deletes of records in a segment
namespace noneDB\Internal\Storage;

use function array_intersect_key, array_keys, array_values, count, max;

/**
 * @internal
 */
final class Writer {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $autoCreateDB;
    private $shardingEnabled;
    private $checkpoints;
    private $compaction;
    private $db;
    private $findFilter;
    private $layoutSwitch;
    private $records;
    private $scope;
    private $segments;
    private $shardMeta;
    private $shardRouter;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->checkpoints, $this->compaction, $this->db, $this->findFilter, $this->layoutSwitch, $this->records, $this->scope, $this->segments, $this->shardMeta, $this->shardRouter);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->autoCreateDB = &$this->k->autoCreateDB;
        $this->shardingEnabled = &$this->k->shardingEnabled;
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
        $this->checkpoints = null;
        $this->compaction = null;
        $this->db = null;
        $this->findFilter = null;
        $this->layoutSwitch = null;
        $this->records = null;
        $this->scope = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->shardRouter = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // WRITE PATHS (callers hold the exclusive lock)
    // ==========================================

    /**
     * Insert validated records. Assigns keys, writes data + indexes, updates field/spatial indexes
     * and switches the database to the sharded layout once it has assigned shardSize keys (shardingEnabled).
     * @param array $items Records without "key"
     * @return array ['n' => int] or ['n' => 0, 'error' => string]
     */
    public function insertRecords($dbname, array $items, $startKey = null){
        if(empty($items)){
            return ["n" => 0];
        }
        if(!$this->segments->dbExists($dbname)){
            if(!$this->autoCreateDB){
                return ["n" => 0, "error" => "Database does not exist"];
            }
            $this->db->createDBUnlocked($dbname);
        }

        // With a start key the records get keys startKey, startKey+1, ... and keys that are already
        // stored are skipped, so replaying the same insert after a crash never duplicates anything.
        $keyed = null;
        $endKey = null;
        if($startKey !== null){
            $keyed = [];
            foreach(array_values($items) as $i => $item){
                $keyed[(int)$startKey + $i] = $item;
            }
            $endKey = (int)$startKey + count($items);
            foreach(array_keys($this->findFilter->selectRecords($dbname, ['key' => array_keys($keyed)])) as $existing){
                unset($keyed[$existing]);
            }
        }

        if($this->segments->isSharded($dbname)){
            $this->shardRouter->insertShardedRecords($dbname, $items, $keyed, $endKey);
            return ["n" => count($items)];
        }

        $index = $this->checkpoints->writableSegmentIndex($dbname, null);
        if($index === null){
            $this->records->createJsonlDatabase($dbname, null);
            $index = $this->checkpoints->writableSegmentIndex($dbname, null);
        }

        if($keyed !== null){
            $records = $keyed;
            $index['n'] = max((int)$index['n'], $endKey);
        } else {
            $records = [];
            foreach($items as $item){
                $records[(int)$index['n']] = $item;
                $index['n']++;
            }
        }
        $this->records->appendSegmentRecords($dbname, null, $index, $records);
        $this->checkpoints->storeSegmentIndex($dbname, null, $index);

        if($this->shardingEnabled && $index['n'] >= $this->segments->shardSizeOf($dbname)){
            // Replaces the data file: after this write committed
            $this->scope->runIndependent($dbname, 'convertToSharded', function() use ($dbname){
                return $this->layoutSwitch->convertToSharded($dbname);
            });
        }
        return ["n" => count($items)];
    }

    /**
     * Replace records ([key => full new record]) in one segment
     * @return int Number of records written
     */
    public function updateSegmentRecords($dbname, $shardId, array $newRecords){
        if(empty($newRecords)){
            return 0;
        }
        $index = $this->checkpoints->writableSegmentIndex($dbname, $shardId);
        foreach(array_keys($newRecords) as $key){
            if(!KeyIndex::has($index, $key)){
                unset($newRecords[$key]);
            }
        }
        if(empty($newRecords)){
            $this->checkpoints->releaseSegmentIndex($dbname, $shardId, $index);
            return 0;
        }
        $this->records->appendSegmentRecords($dbname, $shardId, $index, $newRecords); // counts the replaced lines
        $this->checkpoints->storeSegmentIndex($dbname, $shardId, $index);
        $this->compaction->deferCompaction($dbname, $shardId, $index);
        return count($newRecords);
    }

    /**
     * Delete records ([key => record]) from one segment
     * @return int Number of records deleted
     */
    public function deleteSegmentRecords($dbname, $shardId, array $oldRecords){
        if(empty($oldRecords)){
            return 0;
        }
        $index = $this->checkpoints->writableSegmentIndex($dbname, $shardId);
        if($index['kb'] === ''){
            $oldRecords = array_intersect_key($oldRecords, $index['p']); // a decoded index: one call
        } else {
            foreach(array_keys($oldRecords) as $key){
                if(!KeyIndex::has($index, $key)){
                    unset($oldRecords[$key]);
                }
            }
        }
        if(empty($oldRecords)){
            $this->checkpoints->releaseSegmentIndex($dbname, $shardId, $index);
            return 0;
        }
        $keys = array_keys($oldRecords);
        $this->records->appendTombstones($dbname, $shardId, $index, $keys); // drops the keys, counts the lines
        $this->checkpoints->storeSegmentIndex($dbname, $shardId, $index);

        if($shardId !== null){
            $meta = $this->shardMeta->getCachedMeta($dbname, true);
            foreach($meta['shards'] as $i => $shard){
                if((int)$shard['id'] === $shardId){
                    $meta['shards'][$i]['count'] = max(0, $meta['shards'][$i]['count'] - count($keys));
                    $meta['shards'][$i]['deleted'] += count($keys);
                }
            }
            $meta['totalRecords'] = max(0, (int)$meta['totalRecords'] - count($keys));
            $meta['deletedCount'] = (int)($meta['deletedCount'] ?? 0) + count($keys);
            $this->shardMeta->writeMeta($dbname, $meta);
        }

        $this->compaction->deferCompaction($dbname, $shardId, $index);
        return count($keys);
    }
}
