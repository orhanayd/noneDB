<?php
// getShardInfo()
namespace noneDB\Internal\Sharding;

use function count;

/**
 * @internal
 */
final class ShardInfo {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $checkpoints;
    private $layout;
    private $locks;
    private $segments;
    private $shardMeta;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->checkpoints, $this->layout, $this->locks, $this->segments, $this->shardMeta);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
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
        $this->layout = null;
        $this->locks = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->k = null;
        $this->rt = null;
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
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname){
            return $this->getShardInfoUnlocked($dbname);
        });
    }

    /**
     * getShardInfo() of a database whose lock the caller holds (backup() and restore() read the counters)
     */
    public function getShardInfoUnlocked($dbname){
        if(!$this->segments->dbExists($dbname)){
            return false;
        }
        if(!$this->segments->isSharded($dbname)){
            $index = $this->checkpoints->readJsonlIndex($dbname, null);
            if($index === null){
                return false;
            }
            return array(
                "sharded" => false,
                "shards" => 0,
                "totalRecords" => \noneDB\Internal\Storage\KeyIndex::count($index),
                "shardSize" => $this->segments->shardSizeOf($dbname), // fixed in FORMAT at creation (§4.1)
                "nextKey" => (int)$index['n']
            );
        }

        $meta = $this->shardMeta->getCachedMeta($dbname);
        if($meta === null){
            return false;
        }
        return array(
            "sharded" => true,
            "shards" => count($meta['shards']),
            "totalRecords" => (int)$meta['totalRecords'],
            "deletedCount" => (int)($meta['deletedCount'] ?? 0),
            "shardSize" => (int)$meta['shardSize'],
            "nextKey" => (int)$meta['nextKey']
        );
    }
}
