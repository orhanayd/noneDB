<?php
// Shared locks of several databases at once: one consistent view for joins
namespace noneDB\Internal\Concurrency;

use function array_values, basename, count, dirname, implode, ksort, microtime, realpath, usleep;
use const SORT_STRING;

/**
 * @internal
 */
final class Snapshot {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $lockRetryDelay;
    private $lockTimeout;
    private $faultHook; // @test
    private $multiLock;
    private $cache;
    private $checkpoints;
    private $layout;
    private $locks;
    private $recovery;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->layout, $this->locks, $this->recovery, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->lockRetryDelay = &$this->k->lockRetryDelay;
        $this->lockTimeout = &$this->k->lockTimeout;
        $this->faultHook = &$this->rt->faultHook; // @test
        $this->multiLock = &$this->rt->multiLock;
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
        $this->layout = null;
        $this->locks = null;
        $this->recovery = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Run $fn with shared locks on several databases at once (joins): every read sees the same
     * state of all of them. Locks are taken in one canonical order (by lock file path), so two snapshots
     * never wait for each other in a circle, and no exclusive lock is ever waited for while several are
     * held: work that needs one (recovery, an index repair) is done for each database alone first.
     */
    public function withSnapshot(array $dbnames, callable $fn){
        $names = [];
        foreach($dbnames as $name){
            $name = $this->layout->normalizeDbName($name);
            $lock = $this->locks->getLockPath($name);
            $dir = realpath(dirname($lock));
            $names[($dir === false ? dirname($lock) : $dir) . '/' . basename($lock)] = $name;
        }
        ksort($names, SORT_STRING);
        $names = array_values($names);
        if(count($names) < 2){
            return $fn();
        }
        $started = microtime(true);
        while(true){
            foreach($names as $name){
                // Alone: recovery, buffer flush and key index repairs happen here, not under the snapshot
                $this->locks->withReadLock($name, function() use ($name){
                    $done = false; // a segment was read: the cache may drop what it loaded before the next one
                    foreach($this->segments->listSegments($name) as $segment){
                        if($done){
                            $this->cache->evictBetweenSegments();
                        }
                        $done = true;
                        $this->checkpoints->readJsonlIndex($name, $segment);
                    }
                });
            }
            try {
                return $this->withSharedLocks($names, $fn);
            } catch(\RuntimeException $e){
                if($e->getCode() !== \noneDB\Internal\Concurrency\LockManager::SNAPSHOT_RETRY){
                    throw $e;
                }
                if($this->lockTimeout > 0 && microtime(true) - $started >= $this->lockTimeout){
                    throw new \RuntimeException("noneDB: timed out waiting for a consistent snapshot of " . implode(', ', $names), \noneDB::LOCK_FAILED, $e);
                }
                usleep($this->lockRetryDelay);
            }
        }
    }

    private function withSharedLocks(array $names, callable $fn, $i = 0){
        if($i >= count($names)){
            $this->multiLock++;
            try {
                if($this->faultHook !== null){ $this->recovery->fault('snapshot:held', ['dbs' => $names]); } // @test
                return $fn();
            } finally {
                $this->multiLock--;
            }
        }
        return $this->locks->withReadLock($names[$i], function() use ($names, $fn, $i){
            return $this->withSharedLocks($names, $fn, $i + 1);
        });
    }
}
