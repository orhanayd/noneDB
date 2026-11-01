<?php
// The rollback scope of a write session: marks, rollback, independent jobs (docs/DURABILITY.md)
namespace noneDB\Internal\Recovery;

use function basename, clearstatcache, fclose, fopen, fstat, ftruncate;

/**
 * @internal
 */
final class RollbackScope {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $durable;
    private $faultHook; // @test
    private $lockRegistry;
    private $noWrites;
    private $pendingRecoveryEvents;
    private $cache;
    private $locks;
    private $recovery;
    private $recoveryLog;
    private $segments;
    private $shardMeta;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->locks, $this->recovery, $this->recoveryLog, $this->segments, $this->shardMeta, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->durable = &$this->k->durable;
        $this->faultHook = &$this->rt->faultHook; // @test
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->noWrites = &$this->rt->noWrites;
        $this->pendingRecoveryEvents = &$this->rt->pendingRecoveryEvents;
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
        $this->locks = null;
        $this->recovery = null;
        $this->recoveryLog = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Run the outermost exclusive call of a database inside a rollback scope (see above), then the jobs
     * deferred until its commit, each in a scope of its own. The call is one write operation: how it ended
     * goes to the durability report (docs/DURABILITY.md §2): committed when $fn returned (every required
     * sync succeeded, or it would have thrown), unknown for OUTCOME_UNKNOWN, rolled back otherwise.
     * @param string $op Name of the operation; null for a deferred job (not an operation of its own)
     */
    public function runWriteScope($dbname, $path, callable $fn, $op = 'write'){
        if($op !== null){
            if(!empty($this->pendingRecoveryEvents[$path]) && $this->noWrites === 0){
                // What readers of this process found (they don't write): logged once, deduplicated by id
                $events = $this->pendingRecoveryEvents[$path];
                unset($this->pendingRecoveryEvents[$path]);
                foreach($events as $id => $event){
                    $this->recoveryLog->logRecoveryEvent($dbname, $event, $id);
                }
            }
            $this->lockRegistry[$path]['wrote'] = false;
            if($this->durable){
                $this->lockRegistry[$path]['syncs'] = []; // the syncs durable mode requires (Sync::noteRequired)
            }
        }
        $this->lockRegistry[$path]['scope'] = ['marks' => [], 'deferred' => [], 'independent' => false];
        try {
            $result = $fn();
        } catch(\Throwable $e){
            try {
                $this->rollbackScope($dbname, $path, $e); // rethrows
            } catch(\Throwable $thrown){
                if($op !== null){
                    $this->finishOperation($dbname, $path, $op, $thrown);
                }
                throw $thrown;
            }
        }
        $deferred = $this->lockRegistry[$path]['scope']['deferred'];
        $this->lockRegistry[$path]['scope'] = null;
        if($op !== null){
            $this->finishOperation($dbname, $path, $op, null);
        }
        if($this->faultHook !== null){ $this->recovery->fault('scope:commit', ['db' => $dbname]); } // @test
        foreach($deferred as $job => $work){
            try {
                $this->runWriteScope($dbname, $path, function() use ($dbname, $job, $work){
                    $this->recovery->fault('job:' . $job, ['db' => $dbname]); // @test
                    return $this->runIndependent($dbname, $job, $work);
                }, null);
            } catch(\Throwable $e){
                // The operation itself is committed; the job runs again on a later operation
                $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'deferred-failed', 'job' => $job, 'error' => $e->getMessage()]);
                break;
            }
            $this->cache->evictBetweenSegments(); // a job works on one segment (compaction of a large write's segments)
        }
        return $result;
    }

    /**
     * The outcome of a write operation for getDurability(); an operation that wrote nothing has none
     */
    private function finishOperation($dbname, $path, $op, ?\Throwable $e){
        $syncs = [];
        if(isset($this->lockRegistry[$path]['syncs'])){
            $syncs = $this->lockRegistry[$path]['syncs'];
            unset($this->lockRegistry[$path]['syncs']);
        }
        if(!$this->lockRegistry[$path]['wrote']){
            return;
        }
        if($e === null){
            $this->sync->finishOperation($op, $dbname, $syncs, 'committed');
        } else {
            $this->sync->finishOperation($op, $dbname, $syncs, $e->getCode() === \noneDB::OUTCOME_UNKNOWN ? 'unknown' : 'rolled_back', $e->getMessage());
        }
    }

    /**
     * Undo the appends of a failed scope and rethrow; OUTCOME_UNKNOWN when a file can't be cut back
     * (in durable mode: or its cut can't be synced), with the journal "J" so the next session recovers
     */
    private function rollbackScope($dbname, $path, \Throwable $e){
        $marks = $this->lockRegistry[$path]['scope']['marks'];
        $this->lockRegistry[$path]['scope'] = null;
        $this->lockRegistry[$path]['rolledBack'] = true;
        $failed = [];
        foreach($marks as $file => $mark){
            try {
                $this->recovery->fault('rollback:truncate', ['db' => $dbname, 'file' => $file]); // @test
                if(!$this->truncateBack($file, $mark)){
                    $failed[] = $file;
                }
            } catch(\Throwable $t){
                $failed[] = $file;
            }
        }
        $this->cache->invalidateDbCaches($dbname);
        if(!empty($failed) || $e->getCode() === \noneDB::OUTCOME_UNKNOWN){
            // What is on disk is uncertain: the next session recovers the whole database ("J")
            $this->lockRegistry[$path]['flags']['j']['*'] = true;
            try {
                $this->locks->saveLockState($path);
            } catch(\Throwable $ignored){
            }
            if(!empty($failed)){
                throw new \RuntimeException("noneDB: a write to database '{$dbname}' failed and could not be undone: the outcome is unknown, the database is recovered on its next access", \noneDB::OUTCOME_UNKNOWN, $e);
            }
        }
        if(!empty($marks) && $this->segments->isSharded($dbname)){
            try {
                $this->shardMeta->recountMeta($dbname); // keys reserved by the failed write stay unused
            } catch(\Throwable $ignored){
                // the journal entry stays, recovery recounts
            }
        }
        throw $e;
    }

    /**
     * Cut a file back to the size recorded in a mark, if it is still the same file
     */
    private function truncateBack($file, array $mark){
        list($size, $dev, $ino) = $mark;
        $this->locks->noteWrite($file);
        $fp = @fopen($file, 'r+b');
        if($fp === false){
            return false;
        }
        try {
            $stat = fstat($fp);
            if($stat === false || ($ino && ($stat['ino'] !== $ino || $stat['dev'] !== $dev))){
                return false;
            }
            return $stat['size'] <= $size || (ftruncate($fp, $size) && $this->sync->syncUndo($fp, $file));
        } finally {
            fclose($fp);
            clearstatcache(true, $file);
        }
    }

    /**
     * Record the size a file had before the scope's first append to it
     */
    public function markScopeFile($dbname, $file, $size, $fp){
        $lock = $this->locks->getLockPath($dbname);
        if(isset($this->lockRegistry[$lock]['scope']) && !isset($this->lockRegistry[$lock]['scope']['marks'][$file])){
            $stat = fstat($fp);
            $this->lockRegistry[$lock]['scope']['marks'][$file] = [$size, $stat ? $stat['dev'] : 0, $stat ? $stat['ino'] : 0];
        }
    }

    /**
     * Run work that must not be undone by its caller's failure (buffer flush, the switch to shards): in place while the
     * scope has no appends, else deferred until the scope committed (once per $job). Returns null if deferred.
     */
    public function runIndependent($dbname, $job, callable $work){
        $path = $this->locks->getLockPath($dbname);
        if(!isset($this->lockRegistry[$path]['scope'])){
            return $work();
        }
        if(!empty($this->lockRegistry[$path]['scope']['marks'])){
            if(!isset($this->lockRegistry[$path]['scope']['deferred'][$job])){
                $this->lockRegistry[$path]['scope']['deferred'][$job] = $work;
                $this->recovery->fault('job:deferred', ['db' => $dbname, 'job' => $job]); // @test
            }
            return null;
        }
        if($this->faultHook !== null){ $this->recovery->fault('independent:run', ['db' => $dbname, 'job' => $job]); } // @test
        $was = $this->lockRegistry[$path]['scope']['independent'];
        $this->lockRegistry[$path]['scope']['independent'] = true;
        try {
            $result = $work();
            $this->commitIndependentStep($dbname);
            return $result;
        } finally {
            if(isset($this->lockRegistry[$path]['scope'])){
                $this->lockRegistry[$path]['scope']['independent'] = $was;
            }
        }
    }

    /**
     * Work that replaces or removes data files (dropDB, compact, convertToSharded) can't be undone by a rollback:
     * inside a write of the same database that hasn't committed (a nested call from the caller's own code, which
     * a value's jsonSerialize() can make before the first append) it is refused before it touches any file
     * @throws \LogicException
     */
    public function refuseInUncommittedWrite($dbname, $operation){
        $entry = $this->lockRegistry[$this->locks->getLockPath($dbname)] ?? null;
        $scope = $entry['scope'] ?? null;
        if($scope !== null && (!empty($scope['marks']) || $entry['depth'] > 1) && empty($scope['independent'])){
            throw new \LogicException("noneDB: {$operation}() can't run inside a write to database '{$dbname}' that hasn't committed yet (it replaces or deletes data files, which the write's rollback couldn't undo); call it after that write");
        }
    }

    /**
     * Before a file is replaced, renamed or removed: never one that holds appends a rollback may have to cut
     * back (whatever path asks for it)
     * @throws \LogicException
     */
    public function refuseUncommittedFile($file, $operation){
        foreach($this->lockRegistry as $entry){
            if(isset($entry['scope']['marks'][$file]) && empty($entry['scope']['independent'])){
                throw new \LogicException("noneDB: {$operation} of " . basename($file) . " refused: it holds appends of database '{$entry['dbname']}' that aren't committed yet");
            }
        }
    }

    /**
     * Inside independent work: its appends so far are final (the scope had none before it started)
     */
    public function commitIndependentStep($dbname){
        $path = $this->locks->getLockPath($dbname);
        if(!empty($this->lockRegistry[$path]['scope']['independent'])){
            $this->lockRegistry[$path]['scope']['marks'] = [];
        }
    }
}
