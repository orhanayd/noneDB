<?php
// Database locks, lock sessions, the state record and epochs (docs/STORAGE_FORMAT.md §4.2)
namespace noneDB\Internal\Concurrency;

use function array_keys, array_shift, basename, bin2hex, clearstatcache, count, dirname, explode, fclose,
    fflush, flock, fopen, fread, fseek, fstat, fwrite, getmypid, implode, in_array, is_dir, is_file, is_link, key, max,
    microtime, mkdir, preg_match, random_bytes, reset, stat, str_pad, strlen, strncmp, strpos, substr, trim,
    usleep;
use const LOCK_EX, LOCK_NB, LOCK_SH, LOCK_UN;

/**
 * @internal
 */
final class LockManager {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $dataEpochs;
    private $dataSizes;
    private $dbDir;
    private $fieldIndexCache;
    private $formatRecords;
    private $durable;
    private $generations;
    private $globalSkip;
    private $globalSpatialIndexCache;
    private $lockPaths;
    private $lockRetryDelay;
    private $lockTimeout;
    private $segWrites;
    private $cacheGrew;
    private $decodeDue;
    private $lockHandles;
    private $lockRegistry;
    private $maintenanceDepth;
    private $metrics;
    private $multiLock;
    private $noWrites;
    private $parsedLockStates;
    private $secondaryChecked;
    private $sessionCounter;
    private $sessionLimit;
    private $sessionNear;
    private $staticCacheEnabled;
    private $storeLocks;
    private $storeState;
    private $tailCache;
    private $buffer;
    private $cache;
    private $checkpoints;
    private $fieldIndex;
    private $io;
    private $layout;
    private $recovery;
    private $rtree;
    private $scope;
    private $store;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->cache, $this->checkpoints, $this->fieldIndex, $this->io, $this->layout, $this->recovery, $this->rtree, $this->scope, $this->store, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dataEpochs = &$this->k->dataEpochs;
        $this->dataSizes = &$this->k->dataSizes;
        $this->dbDir = &$this->k->dbDir;
        $this->fieldIndexCache = &$this->k->fieldIndexCache;
        $this->formatRecords = &$this->k->formatRecords;
        $this->durable = &$this->k->durable;
        $this->generations = &$this->k->generations;
        $this->globalSkip = &$this->k->globalSkip;
        $this->globalSpatialIndexCache = &$this->k->globalSpatialIndexCache;
        $this->lockPaths = &$this->k->lockPaths;
        $this->lockRetryDelay = &$this->k->lockRetryDelay;
        $this->lockTimeout = &$this->k->lockTimeout;
        $this->segWrites = &$this->k->segWrites;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->decodeDue = &$this->rt->decodeDue;
        $this->lockHandles = &$this->rt->lockHandles;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->maintenanceDepth = &$this->rt->maintenanceDepth;
        $this->metrics = &$this->rt->metrics;
        $this->multiLock = &$this->rt->multiLock;
        $this->noWrites = &$this->rt->noWrites;
        $this->parsedLockStates = &$this->rt->parsedLockStates;
        $this->secondaryChecked = &$this->rt->secondaryChecked;
        $this->sessionCounter = &$this->rt->sessionCounter;
        $this->sessionLimit = &$this->rt->sessionLimit;
        $this->sessionNear = &$this->rt->sessionNear;
        $this->staticCacheEnabled = &$this->rt->staticCacheEnabled;
        $this->storeLocks = &$this->rt->storeLocks;
        $this->storeState = &$this->rt->storeState;
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
        $this->buffer = null;
        $this->cache = null;
        $this->checkpoints = null;
        $this->fieldIndex = null;
        $this->io = null;
        $this->layout = null;
        $this->recovery = null;
        $this->rtree = null;
        $this->scope = null;
        $this->store = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }


    // Rollback scope. The outermost exclusive withLock() call of a database opens one scope; nested
    // exclusive calls on the same database join it. There are no nested scopes: cutting a file back to an
    // earlier size can't drop an earlier outer append and keep a later inner one. Every append to a data or
    // buffer file records the size the file had before the scope's first append to it (a "mark"). When an
    // exception leaves the outermost call, every marked file is cut back to its mark.
    // Work that must not depend on the caller's success (buffer flush, the switch to shards) runs in place only
    // while the scope has no appends yet, committing its own appends step by step (runIndependent);
    // requested later, it is deferred until the scope committed, like compaction, which replaces whole files.
    // A deferred job that fails doesn't fail the operation that committed before it: it is logged and runs
    // again on a later operation.

    public function getLockPath($dbname){
        if(!isset($this->lockPaths[$dbname])){
            $this->layout->dbHome($dbname);
        }
        return $this->lockPaths[$dbname];
    }

    /**
     * Run $fn while holding the database lock: shared for reads, exclusive for writes.
     *
     * The lock is process-wide and re-entrant. A nested exclusive request upgrades a shared lock.
     *
     * @param string $dbname Database name (already normalized)
     * @param bool $exclusive
     * @param callable $fn
     * @param string $op The operation an exclusive call is (getDurability()['lastOperation'])
     * @return mixed Whatever $fn returns
     */
    public function withLock($dbname, $exclusive, callable $fn, $op = 'write'){
        $path = $this->getLockPath($dbname);
        if($exclusive && $this->multiLock > 0){
            // A snapshot holds shared locks of several databases: waiting for an exclusive one here could
            // deadlock with another snapshot. withSnapshot() does that work alone first and tries again.
            throw new \RuntimeException("noneDB: database '{$dbname}' needs maintenance before the snapshot", self::SNAPSHOT_RETRY);
        }

        if(isset($this->lockRegistry[$path])){
            if(!empty($this->lockRegistry[$path]['lost'])){
                throw new \RuntimeException("noneDB: the lock on database '{$dbname}' was lost (timed out while upgrading)", \noneDB::LOCK_FAILED);
            }
            if($exclusive && $this->lockRegistry[$path]['mode'] !== LOCK_EX){
                // flock can't upgrade atomically. On BSD/macOS a failed non-blocking upgrade keeps the shared
                // lock, so two processes upgrading at once would wait for each other until lockTimeout: release
                // first, then wait for the exclusive lock. Another writer may run in between, so the state is
                // read again and whatever it left half-done is recovered.
                $this->flushPendingGlobals($dbname, $path); // while what they describe still holds
                flock($this->lockRegistry[$path]['fp'], LOCK_UN);
                $this->recovery->fault('upgrade:released', ['db' => $dbname]); // @test
                try {
                    $this->acquireFlock($this->lockRegistry[$path]['fp'], LOCK_EX, $dbname);
                } catch(\Throwable $e){
                    $this->lockRegistry[$path]['lost'] = true; // never continue reading without a lock
                    throw $e;
                }
                $this->lockRegistry[$path]['mode'] = LOCK_EX;
                $this->loadLockState($dbname, $path);
                $this->prepareDb($dbname, $path);
            }
            $this->lockRegistry[$path]['depth']++;
            try {
                if($exclusive && $this->lockRegistry[$path]['scope'] === null){
                    return $this->scope->runWriteScope($dbname, $path, $fn, $op);
                }
                return $fn();
            } finally {
                $this->lockRegistry[$path]['depth']--;
            }
        }

        // Outermost call. A database this process doesn't know to be present takes the missing-database
        // path (STORAGE_FORMAT.md §3.3): store re-validated, dbDir listed for foreign entries, STORE.LOCK
        // held shared, so no maintenance can run while it is created or answered as missing.
        if(!isset($this->formatRecords[$path]) && !isset($this->storeLocks[$this->dbDir])){
            $this->store->openStore(false);
            clearstatcache(true, dirname($path) . '/FORMAT');
            if(!is_file(dirname($path) . '/FORMAT')){
                return $this->withMissingDatabase($dbname, $exclusive, $fn, $op);
            }
        }
        try {
            return $this->lockSession($dbname, $exclusive, $fn, !isset($this->storeLocks[$this->dbDir]), $op);
        } catch(\RuntimeException $e){
            if($e->getCode() !== self::DB_GONE && $e->getCode() !== self::DB_ABSENT){
                throw $e;
            }
            return $this->withMissingDatabase($dbname, $exclusive, $fn, $op);
        }
    }

    /**
     * The missing-database path: under STORE.LOCK (shared) after the store was re-read and dbDir checked
     * for foreign entries. A read of a database without a directory runs $fn without any lock (nothing is
     * created); a write creates the directory and locks it.
     */
    private function withMissingDatabase($dbname, $exclusive, callable $fn, $op){
        $path = $this->getLockPath($dbname);
        return $this->store->withStoreRevalidated($exclusive, function() use ($dbname, $exclusive, $fn, $path, $op){
            clearstatcache(true, dirname($path));
            if(!is_dir(dirname($path))){
                if(!$exclusive || !$this->store->storeExists()){
                    return $fn(); // absent: reads create nothing
                }
                if(!@mkdir(dirname($path), 0750) && !is_dir(dirname($path))){
                    throw new \RuntimeException("noneDB: cannot create the directory of database '{$dbname}'");
                }
                $this->io->setPermissions(dirname($path), true);
            }
            return $this->lockSession($dbname, $exclusive, $fn, false, $op);
        });
    }

    /**
     * One lock session of a database (outermost withLock call). $recheck: the session didn't come through
     * the missing-database path, so finding the database absent means going there (DB_ABSENT).
     */
    private function lockSession($dbname, $exclusive, callable $fn, $recheck, $op){
        $path = $this->getLockPath($dbname);
        $mode = $exclusive ? LOCK_EX : LOCK_SH;
        $fp = $this->lockFile($path, $mode, $dbname);
        $this->sessionLimit = null;
        $this->sessionNear = null;
        $this->lockRegistry[$path] = [
            'fp' => $fp, 'mode' => $mode, 'depth' => 1, 'dbname' => $dbname,
            'gen' => 0, 'epoch' => null, 'flags' => [], 'bumped' => false, 'pending' => [],
            'scope' => null, 'rolledBack' => false, 'sid' => ++$this->sessionCounter, 'globals' => [], 'wrote' => false,
        ];

        $ok = false;
        try {
            $this->loadLockState($dbname, $path);
            try {
                $this->prepareDb($dbname, $path);
            } catch(\RuntimeException $e){
                // restore() is the way out of a damaged database (STORAGE_FORMAT.md §10.3 step 2): what recovery
                // can't resolve is handed to it instead of failing
                if($op !== 'restore' || $e->getCode() !== \noneDB::FORMAT_DAMAGED){
                    throw $e;
                }
            }
            if(isset($this->decodeDue[$path]) && $op !== 'rebuildIndex'){
                // §6.4: loaded by an earlier operation, decoded now (not by one that makes them again)
                $this->checkpoints->decodeDueIndexes($path);
            }
            if($recheck && !isset($this->formatRecords[$path]) && !$this->layout->dbPresent($dbname)){ // a cached FORMAT: present
                throw new \RuntimeException("noneDB: database '{$dbname}' is absent", self::DB_ABSENT);
            }
            $result = $exclusive ? $this->scope->runWriteScope($dbname, $path, $fn, $op) : $fn();
            $ok = true;
            return $result;
        } finally {
            try {
                if($this->lockRegistry[$path]['mode'] === LOCK_EX){
                    $this->finishWriteSession($dbname, $path, $ok);
                }
                $this->flushPendingGlobals($dbname, $path, $ok);
            } finally {
                $fp = $this->lockRegistry[$path]['fp'];
                unset($this->lockRegistry[$path]);
                flock($fp, LOCK_UN);
                $this->keepLockHandle($path, $fp);
                // The cache size is looked at only after something new was cached
                if($this->cacheGrew && empty($this->lockRegistry)){
                    $this->cacheGrew = false;
                    if($this->staticCacheEnabled && \noneDB\Internal\Cache\CacheManager::cacheLimitBytes() > 0){
                        $this->cache->evictCaches();
                    }
                }
            }
        }
    }

    /**
     * End of an exclusive session: write pending spatial indexes, close the journal entries of this
     * session, and make sure the generation moved so other processes drop their caches
     */
    private function finishWriteSession($dbname, $path, $ok){
        if($ok){
            if(empty($this->lockRegistry[$path]['rolledBack'])){
                $this->checkpoints->checkpointTouchedSegments($dbname, $path);
            }
            // After a rollback (of a deferred job) the journal stays: the next lock holder rebuilds
            if(!empty($this->lockRegistry[$path]['flags']['j']) && empty($this->lockRegistry[$path]['rolledBack'])){
                // Data, indexes and meta of every journaled segment are written
                unset($this->lockRegistry[$path]['flags']['j']);
                $this->saveLockState($path);
            }
        } else {
            // Partial failure: forget in-memory state, the files are the source of truth.
            // A journal entry stays, so the next lock holder rebuilds what may be half-written.
            $this->cache->invalidateDbCaches($dbname);
        }
    }

    /**
     * Open (or reuse) a lock file and lock it.
     * A kept handle is only used in the process that opened it (a forked child would share the parent's
     * locks), and any handle is only trusted while the path still names the same file: if the file was
     * deleted or replaced meanwhile, the file the path names now is locked instead.
     * @return resource
     */
    private function lockFile($path, $mode, $dbname){
        $pid = getmypid();
        for($attempt = 0; ; $attempt++){
            $kept = $this->lockHandles[$path] ?? null;
            unset($this->lockHandles[$path]);
            if($kept !== null && $kept['pid'] === $pid){
                $fp = $kept['fp'];
            } else {
                if($kept !== null){
                    @fclose($kept['fp']);
                }
                // A database directory that is a link is no database: no link is followed (§3.4)
                if(is_link(dirname($path))){
                    throw new \RuntimeException("noneDB: the directory of database '{$dbname}' is a symbolic link, not a database (docs/STORAGE_FORMAT.md §3.4)", \noneDB::UNSUPPORTED_FORMAT);
                }
                // "e": close-on-exec. A kept handle must not leak into programs this process starts:
                // an inherited copy would keep a lock alive after this process died holding it.
                $created = !is_file($path);
                $fp = is_dir(dirname($path)) ? @fopen($path, 'c+be') : false;
                if($fp !== false && $created){
                    $this->io->setPermissions($path);
                }
                if($fp === false){
                    clearstatcache(true, dirname($path));
                    if(!is_dir(dirname($path))){
                        // The database directory is gone (dropped store, rotated key): the caller takes
                        // the missing-database path, which re-validates the store
                        throw new \RuntimeException("noneDB: database '{$dbname}' has no directory", self::DB_GONE);
                    }
                    throw new \RuntimeException("noneDB: cannot open lock file for database '{$dbname}'", \noneDB::LOCK_FAILED);
                }
            }
            try {
                $this->acquireFlock($fp, $mode, $dbname);
            } catch(\Throwable $e){
                fclose($fp);
                throw $e;
            }
            $own = fstat($fp);
            clearstatcache(true, $path);
            $named = @stat($path);
            if($named !== false && ($own === false || !$own['ino'] || !$named['ino']
                || ($own['ino'] === $named['ino'] && $own['dev'] === $named['dev']))){
                return $fp;
            }
            flock($fp, LOCK_UN);
            fclose($fp);
            if($attempt >= 10){
                throw new \RuntimeException("noneDB: the lock file of database '{$dbname}' keeps being replaced", \noneDB::LOCK_FAILED);
            }
        }
    }

    /**
     * Keep an unlocked lock file handle open for the next operation (the oldest is closed past the limit)
     */
    private function keepLockHandle($path, $fp){
        $this->lockHandles[$path] = ['fp' => $fp, 'pid' => getmypid()];
        if(count($this->lockHandles) > self::LOCK_HANDLE_LIMIT){
            reset($this->lockHandles);
            $oldest = key($this->lockHandles);
            @fclose($this->lockHandles[$oldest]['fp']);
            unset($this->lockHandles[$oldest]);
        }
    }

    /**
     * Parse a state record by the grammar of STORAGE_FORMAT.md §4.2: [state, flags, damaged]. An empty
     * record is a new one (state ''); anything that doesn't match the grammar is damaged and read as J.
     */
    private static function parseLockState($raw){
        $tokens = explode(' ', trim($raw, " \0"));
        $state = (string)array_shift($tokens); // "{generation}:{epoch}"
        $flags = [];
        $damaged = false;
        if($state === ''){
            $tokens = []; // a new record: written with an epoch by the next exclusive session
        } elseif(preg_match('/^[0-9]{1,18}:[0-9a-f]{8}$/', $state) !== 1){
            $damaged = true;
        }
        foreach($tokens as $token){
            if($token === ''){
                continue;
            }
            if($token === 'b' || $token === 'c' || $token === 'd' || $token === 'm'){
                $flags[$token] = true;
            } elseif($token === 'J'){
                $flags['j']['*'] = true;
            } elseif(strncmp($token, 'j=', 2) === 0 && preg_match('/^j=(main|s[0-9]{1,9})(,(main|s[0-9]{1,9}))*$/', $token) === 1){
                foreach(explode(',', substr($token, 2)) as $segment){
                    $flags['j'][$segment] = true;
                }
            } elseif(preg_match('/^g=([0-9]{1,9})>([0-9]{1,9})$/', $token, $m) === 1){
                $flags['g'] = [(int)$m[1], (int)$m[2]];
            } else {
                $damaged = true;
            }
        }
        if($damaged){
            // Not a record of this format: everything it could have said is recovered from the data (J);
            // a generation that isn't CURRENT is never removed on this path (STORAGE_FORMAT.md §4.2)
            $state = 'damaged';
            $flags = ['j' => ['*' => true], 'damaged' => true];
        }
        return [$state, $flags, $damaged];
    }

    /**
     * Read the state record (caller holds the lock). If another process wrote the database since this
     * process last looked, all cached state of the database is dropped.
     */
    private function loadLockState($dbname, $path){
        $fp = $this->lockRegistry[$path]['fp'];
        fseek($fp, 0);
        $raw = (string)fread($fp, self::LOCK_STATE_SIZE);
        // The last record parsed per lock file: an unchanged record (the usual case) is not parsed again
        $parsed = $this->parsedLockStates[$path] ?? null;
        if($parsed !== null && $parsed[0] === $raw){
            [, $state, $flags, $damaged] = $parsed;
        } else {
            [$state, $flags, $damaged] = self::parseLockState($raw);
            if($parsed === null && count($this->parsedLockStates) >= 4096){
                $this->parsedLockStates = [];
            }
            $this->parsedLockStates[$path] = [$raw, $state, $flags, $damaged];
        }
        // The caches were checked against this exact state: nothing to do (the usual case, one comparison;
        // the epoch is parsed when needed: false = the valid one of the state)
        $seen = $this->generations[$path] ?? null;
        $epoch = false;
        if($seen !== $state){
            $colon = strpos($state, ':');
            $epoch = $colon === false ? null : substr($state, $colon + 1);
            $seenEpoch = $seen === null || strpos($seen, ':') === false ? null : substr($seen, strpos($seen, ':') + 1);
            if($seen === null || $epoch === null || $seenEpoch !== $epoch){
                $this->cache->invalidateDbCaches($dbname);
                $this->layout->forgetDatabaseLayout($dbname);
                // Another process replaced files or ran maintenance: the store is read again (§3.3)
                unset($this->storeState[$this->dbDir]);
                if($seen !== null && $this->maintenanceDepth === 0){
                    $this->checkStoreAfterEpochChange();
                }
            } else {
                $this->cache->refreshDbCaches($dbname); // same epoch, another generation: the files only grew
            }
            $this->generations[$path] = $epoch === null ? null : $state;
        }
        $this->lockRegistry[$path]['gen'] = $damaged ? 0 : (int)$state;
        $this->lockRegistry[$path]['state'] = $state;
        $this->lockRegistry[$path]['epoch'] = $epoch;
        $this->lockRegistry[$path]['flags'] = $flags;
        if($this->durable){
            // Durable mode: flags read from the record are synced again with this session's first record
            // (another process may have written them unsynced); the epoch protects nothing of this session
            $current = $this->registryEpoch($path);
            $this->lockRegistry[$path]['synced'] = $current === null ? [] : ['e' . $current => true];
        }
    }

    /**
     * A database's epoch changed under its lock: re-read the store (read only; no repair, which would
     * take STORE.LOCK after a database lock) and re-check the key and the maintenance journal
     */
    private function checkStoreAfterEpochChange(){
        $this->store->checkMaintenanceJournal();
        [, $copy1] = $this->store->readStoreCopy('STORE');
        [, $copy2] = $this->store->readStoreCopy('STORE.2');
        $record = $copy1 ?? $copy2;
        if($record === null || ($copy1 !== null && $copy2 !== null && $copy1 !== $copy2)){
            throw new \RuntimeException("noneDB: the store record in {$this->dbDir} is damaged", \noneDB::FORMAT_DAMAGED);
        }
        if(!in_array($this->store->keyCheck(), $record['keys'], true)){
            throw new \RuntimeException("noneDB: the configured secretKey is not registered for the store in {$this->dbDir}", \noneDB::KEY_NOT_REGISTERED);
        }
        $this->storeState[$this->dbDir][$this->store->keyCheck()] = true;
    }

    /**
     * Write the state record of a database held exclusively. $bump: a file is about to be written, so the
     * generation moves (once per session; noteWrite()): even a writer that dies half-way makes other
     * processes check their caches. A record without epoch gets one.
     * Durable mode (DURABILITY.md §3): a flag or an epoch that protects the change that follows is synced
     * before the record returns (clearing one needs no sync); $sync forces a sync.
     * @throws \RuntimeException DURABILITY_FAILED when that sync fails
     */
    public function saveLockState($path, $bump = false, $sync = false){
        if($bump && !$this->lockRegistry[$path]['bumped']){
            $this->lockRegistry[$path]['gen']++;
            $this->lockRegistry[$path]['bumped'] = true;
        }
        $this->lockRegistry[$path]['wrote'] = true;
        $epoch = $this->registryEpoch($path);
        if($epoch === null){
            $epoch = $this->lockRegistry[$path]['epoch'] = bin2hex(random_bytes(4));
        }
        $gen = max(1, (int)$this->lockRegistry[$path]['gen']);
        $this->lockRegistry[$path]['gen'] = $gen;
        $state = $gen . ':' . $epoch;
        $this->generations[$path] = $state;
        $this->lockRegistry[$path]['state'] = $state;
        $flags = $this->lockRegistry[$path]['flags'];
        unset($this->lockRegistry[$path]['flags']['damaged']);
        foreach(['b', 'c', 'd', 'm'] as $flag){
            if(!empty($flags[$flag])){
                $state .= ' ' . $flag;
            }
        }
        if(!empty($flags['g'])){
            $state .= ' g=' . $flags['g'][0] . '>' . $flags['g'][1];
        }
        if(!empty($flags['j'])){
            $segments = ' j=' . implode(',', array_keys($flags['j']));
            $state .= (isset($flags['j']['*']) || strlen($state . $segments) > self::LOCK_STATE_SIZE) ? ' J' : $segments;
        }
        $fp = $this->lockRegistry[$path]['fp'];
        $raw = str_pad($state, self::LOCK_STATE_SIZE);
        fseek($fp, 0);
        if(fwrite($fp, $raw) !== self::LOCK_STATE_SIZE || !fflush($fp)){
            unset($this->parsedLockStates[$path]);
            throw new \RuntimeException("noneDB: cannot write the lock file of database '" . $this->lockRegistry[$path]['dbname'] . "'");
        }
        // The next session of this process reads this record: parsed already (what parseLockState() would
        // return; "J" alone stands for all segments, so such a record is parsed again)
        if(strpos($state, ' J') === false){
            unset($flags['damaged']);
            $this->parsedLockStates[$path] = [$raw, $gen . ':' . $epoch, $flags, false];
        } else {
            unset($this->parsedLockStates[$path]);
        }
        if($this->durable){
            // What this record protects: its epoch and flags; what is durable already needs no new sync
            $tokens = ['e' . $epoch => true];
            foreach(explode(' ', $state) as $i => $token){
                if($i > 0){
                    $tokens[$token] = true;
                }
            }
            $synced = $this->lockRegistry[$path]['synced'] ?? [];
            if(!$sync){
                foreach($tokens as $token => $unused){
                    if(!isset($synced[$token]) && !($token[0] === 'j' && isset($synced['J']))){
                        $sync = true;
                        break;
                    }
                }
            }
            if($sync){
                if(!$this->sync->trySync($fp, $path)){
                    throw $this->sync->failure('file', $path, false);
                }
                $this->lockRegistry[$path]['synced'] = $tokens;
            }
        }
    }

    /**
     * Change the state flag 'b' (write buffer may hold records); caller holds the exclusive lock
     */
    public function setBufferFlag($dbname, $on){
        $path = $this->getLockPath($dbname);
        if(!isset($this->lockRegistry[$path]) || !empty($this->lockRegistry[$path]['flags']['b']) === $on){
            return;
        }
        if($on){
            $this->lockRegistry[$path]['flags']['b'] = true;
        } else {
            unset($this->lockRegistry[$path]['flags']['b']);
        }
        $this->saveLockState($path);
    }

    /**
     * After taking the lock: maintenance, an interrupted generation switch, creation or drop, a damaged
     * directory and a crashed writer's journal are handled before any data is returned (STORAGE_FORMAT.md
     * §4.2, §5.2, §8.3). The usual case (a present database, no flag but b) costs one isset.
     */
    private function prepareDb($dbname, $path){
        $flags = $this->lockRegistry[$path]['flags'];
        if(!empty($flags['m']) && $this->maintenanceDepth === 0){
            throw new \RuntimeException("noneDB: database '{$dbname}' is under maintenance", \noneDB::MAINTENANCE_IN_PROGRESS);
        }
        $quiet = empty($flags['g']) && empty($flags['c']) && empty($flags['d']) && empty($flags['j'])
            && $this->lockRegistry[$path]['epoch'] !== null;
        if($quiet && isset($this->formatRecords[$path])){
            return;
        }
        if($this->noWrites > 0){
            // checkIntegrity(), explain(): nothing is recovered or written; a state that can't be read is reported
            if(!empty($flags['g'])){
                throw new \RuntimeException("noneDB: database '{$dbname}' has an interrupted generation switch (recovered by the next write)", \noneDB::FORMAT_DAMAGED);
            }
            $this->layout->resolveDatabaseState($dbname, $path);
            return;
        }
        if($quiet){
            $this->layout->resolveDatabaseState($dbname, $path);
            return;
        }
        $this->withLock($dbname, true, function() use ($dbname, $path){
            // Re-read under the exclusive lock: another process may have done it meanwhile
            if(!empty($this->lockRegistry[$path]['flags']['g'])){
                $this->layout->recoverGenerationSwitch($dbname, $path);
            }
            $this->layout->resolveDatabaseState($dbname, $path);
            if($this->lockRegistry[$path]['epoch'] === null){
                // A new lock record: whatever the lost one said is recovered from the data
                if($this->layout->dbPresent($dbname)){
                    $this->lockRegistry[$path]['flags']['j']['*'] = true;
                }
                $this->saveLockState($path, false, true);
                // 1:{new epoch} (§4.2): no cache can match a new epoch, so this session's writes need no
                // further generation
                $this->lockRegistry[$path]['bumped'] = true;
            }
            if(!empty($this->lockRegistry[$path]['flags']['j']) && $this->layout->dbPresent($dbname)){
                $this->recovery->recoverPendingIndexes($dbname, $path);
            } elseif(!empty($this->lockRegistry[$path]['flags']['j'])){
                unset($this->lockRegistry[$path]['flags']['j']);
                $this->saveLockState($path);
            }
            if(!empty($this->lockRegistry[$path]['flags']['damaged'])){
                $this->saveLockState($path, false, true);
            }
        }, 'recovery');
    }

    /**
     * Called before a segment's data changes. The journal is needed only for shard meta counters (sharded)
     * and switches to shards ($always): field and spatial indexes follow the data by themselves.
     */
    public function markIndexesPending($dbname, $shardId, $always = false){
        $path = $this->getLockPath($dbname);
        if(!isset($this->lockRegistry[$path])){
            return;
        }
        if(!$always && $shardId === null){
            return;
        }
        $segment = \noneDB\Internal\Storage\Layout::segmentName($shardId);
        if(isset($this->lockRegistry[$path]['pending'][$segment])){
            return;
        }
        $this->lockRegistry[$path]['pending'][$segment] = true;
        $this->lockRegistry[$path]['flags']['j'][$segment] = true;
        $this->saveLockState($path, true); // the data write follows
    }

    /**
     * flock with timeout (lockTimeout seconds; 0 waits forever)
     * @throws \RuntimeException on timeout
     */
    public function acquireFlock($fp, $mode, $dbname){
        $this->metrics['locks']++;
        if(flock($fp, $mode | LOCK_NB)){
            return;
        }
        // Held by another process: wait, up to lockTimeout (0: as long as it takes)
        $this->metrics['lockWaits']++;
        $start = microtime(true);
        try {
            if($this->lockTimeout <= 0){
                if(!flock($fp, $mode)){
                    throw new \RuntimeException("noneDB: could not lock database '{$dbname}'", \noneDB::LOCK_FAILED);
                }
                return;
            }
            while(!flock($fp, $mode | LOCK_NB)){
                if((microtime(true) - $start) >= $this->lockTimeout){
                    $this->metrics['lockTimeouts']++;
                    throw new \RuntimeException("noneDB: timed out after {$this->lockTimeout}s waiting for the lock on database '{$dbname}'", \noneDB::LOCK_FAILED);
                }
                usleep($this->lockRetryDelay);
            }
        } finally {
            $this->metrics['lockWaitSeconds'] += microtime(true) - $start;
        }
    }

    /**
     * A file is about to be written: the first write of an exclusive session bumps the generation of the
     * database the file belongs to (see saveLockState). A database file written without its database's
     * exclusive lock is a bug: LogicException. Files of no database (dbDir housekeeping) pass.
     * @throws \LogicException
     */
    public function noteWrite($file){
        $file = (string)$file;
        unset($this->dataSizes[$file]); // a size cached for global index checks
        $owner = null;
        foreach($this->lockRegistry as $path => $entry){
            // Files of a database are inside its directory: the lock path without "LOCK"
            $prefix = substr($path, 0, -4);
            if(strncmp($file, $prefix, strlen($prefix)) === 0){
                $owner = $path;
                break;
            }
        }
        if($owner !== null){
            if(substr($file, -6) === '.jsonl' && strpos($file, '/segments/') !== false){
                // A data file: global index lookups check its shard's newer lines (globalCheckShards)
                $writes = &$this->segWrites[$owner];
                $writes['seq'] = ($writes['seq'] ?? 0) + 1;
                $writes['paths'][$file] = $writes['seq'];
                unset($writes);
            }
            if($this->lockRegistry[$owner]['mode'] !== LOCK_EX){
                throw new \LogicException("noneDB: write to " . basename($file) . " under a shared lock of database '" . $this->lockRegistry[$owner]['dbname'] . "'");
            }
            $this->lockRegistry[$owner]['wrote'] = true;
            if(!$this->lockRegistry[$owner]['bumped']){
                $this->saveLockState($owner, true);
            }
        } elseif(preg_match('#/[0-9a-f]{32}\.ndb4/#', $file) === 1){
            throw new \LogicException("noneDB: write to " . basename($file) . " without the lock of its database");
        }
    }

    /**
     * Epoch of a locked database's state record (null: none or invalid)
     */
    public function registryEpoch($path){
        $epoch = $this->lockRegistry[$path]['epoch'] ?? null;
        if($epoch === false){
            $state = $this->lockRegistry[$path]['state'];
            $epoch = substr($state, strpos($state, ':') + 1); // a state with a valid epoch (see loadLockState)
            $this->lockRegistry[$path]['epoch'] = $epoch;
        }
        return $epoch;
    }

    /**
     * A data file of the database is about to be replaced or removed (compaction, the switch to shards, drop): new
     * epoch, so every other process drops all its caches of the database (caller holds the exclusive lock)
     */
    /**
     * Global field and spatial index files changed in memory during this lock session (a checkpoint per
     * shard updates them): written once here, at the end of the session, at an epoch change or before the
     * lock is upgraded, instead of once per shard (O(shards²) for an operation that checkpoints every
     * shard). Not written when the session failed or the epoch changed since: a missing entry only means
     * the shard is never skipped.
     */
    private function flushPendingGlobals($dbname, $path, $ok = true){
        $pending = $this->lockRegistry[$path]['globals'] ?? [];
        if(empty($pending)){
            return;
        }
        $this->lockRegistry[$path]['globals'] = [];
        $epoch = $this->registryEpoch($path);
        foreach($pending as $file => [$kind, $field, $deferredAt]){
            $global = $kind === 'gfx' ? ($this->fieldIndexCache[$file] ?? null) : ($this->globalSpatialIndexCache[$file] ?? null);
            if(!$ok || !empty($this->lockRegistry[$path]['rolledBack']) || $deferredAt !== $epoch || $global === null){
                unset($this->fieldIndexCache[$file], $this->globalSpatialIndexCache[$file]);
                continue;
            }
            try {
                if($kind === 'gfx'){
                    $this->fieldIndex->writeGlobalFieldIndex($dbname, $field, $global);
                } else {
                    $this->rtree->writeGlobalSpatialIndex($dbname, $field, $global);
                }
            } catch(\Throwable $e){
                unset($this->fieldIndexCache[$file], $this->globalSpatialIndexCache[$file]);
                if($e->getCode() === \noneDB::LOCK_FAILED){
                    throw $e;
                }
                // never needed for correctness: a shard without a fitting entry is never skipped
            }
        }
    }

    public function bumpEpoch($dbname){
        $path = $this->getLockPath($dbname);
        if(!isset($this->lockRegistry[$path]) || $this->lockRegistry[$path]['mode'] !== LOCK_EX){
            throw new \LogicException("noneDB: new epoch of database '{$dbname}' without its exclusive lock");
        }
        $this->flushPendingGlobals($dbname, $path); // under the epoch their entries belong to
        $this->lockRegistry[$path]['epoch'] = bin2hex(random_bytes(4));
        $this->saveLockState($path, true);
        unset($this->globalSkip[$path], $this->segWrites[$path]);
        // What this process checked against the data files no longer holds for the one being replaced
        $prefix = $this->layout->dbHome($dbname);
        foreach([&$this->dataSizes, &$this->dataEpochs, &$this->secondaryChecked, &$this->tailCache] as &$cache){
            foreach(array_keys($cache) as $key){
                if(strncmp((string)$key, $prefix, strlen($prefix)) === 0){
                    unset($cache[$key]);
                }
            }
        }
        unset($cache);
    }

    /**
     * Run a read operation: shared lock while the database exists, no lock (and no files created)
     * when it doesn't (withLock's missing-database path). Buffered records are flushed first.
     */
    public function withReadLock($dbname, callable $fn){
        return $this->withLock($dbname, false, function() use ($dbname, $fn){
            if(isset($this->lockRegistry[$this->getLockPath($dbname)])){
                $this->buffer->flushPendingBuffers($dbname);
            }
            return $fn();
        });
    }
    // ==========================================
    // DATABASE LOCKING AND STATE
    // ==========================================
    //
    // Every database has a lock file {locator}.ndb4/LOCK. Besides being the flock target it holds a small
    // state record (docs/STORAGE_FORMAT.md §4.2). It is read on every lock acquisition anyway, so the
    // checks below cost no extra I/O:
    //
    //   "{generation}:{epoch}[ b][ c][ d][ m][ g=N>X][ j=main,s0,s3 | J]"   space padded to LOCK_STATE_SIZE bytes
    //
    //   generation  bumped by the first file write of a write session (sessions that write nothing keep
    //               it). A value other than the one this process last saw means another process changed
    //               the database: its caches are checked again (key indexes: only the new data).
    //   epoch       8 hex digits, new before a file a process may have cached is replaced or removed
    //               (compaction, generation switch, drop, maintenance) and for a new lock record: another
    //               epoch drops every cache of the database and makes the process read the store again.
    //   b           the write buffer may hold records; they are flushed before the next read or write.
    //   c / d       creation / drop in progress (completed or undone by the next exclusive session).
    //   m           maintenance in progress: MAINTENANCE_IN_PROGRESS.
    //   g=N>X       generation switch from N to X in progress: recovered before any data is returned.
    //   j=... / J   journal: shard counters of these segments (J: all) may lag behind the data because a
    //               writer died; the next lock holder recounts them. Key, field and spatial indexes need
    //               none: they replay the data after their checkpoint.
    //
    // The record is overwritten in place with a fixed size (one small write: no truncate, no rename).
    // Lock files are never deleted (dropDB keeps them), and their handles stay open between operations.

    public const LOCK_STATE_SIZE = 128;
    public const LOCK_HANDLE_LIMIT = 64;
    /** Exception code (internal): an exclusive lock was needed while a snapshot holds several shared ones */
    public const SNAPSHOT_RETRY = 3303;
    public const DB_GONE = 3399;      // internal: the database directory vanished under a kept path
    public const DB_ABSENT = 3398;    // internal: found absent without the missing-database path
}
