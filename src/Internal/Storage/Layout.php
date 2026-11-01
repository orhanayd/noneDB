<?php
// Database names, directories, FORMAT, CURRENT and generations (docs/STORAGE_FORMAT.md §4, §5, §8)
namespace noneDB\Internal\Storage;

use function bin2hex, clearstatcache, count, ctype_digit, dirname, file_get_contents, in_array, is_array, is_dir,
    is_file, is_int, is_link, is_string, max, preg_match, random_bytes, rtrim, scandir, sort, strlen, substr, time;
use const LOCK_EX;

/**
 * @internal
 */
final class Layout {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $currentGenerations;
    private $dbDir;
    private $dbHomes;
    private $durable;
    private $formatRecords;
    private $genPaths;
    private $lockPaths;
    private $shardSize;
    private $directoryListings; // @test
    private $lockRegistry;
    private $noWrites;
    private $staticCacheEnabled;
    private $staticFileExistsCache;
    private $staticSanitizeCache;
    private $cache;
    private $io;
    private $locks;
    private $recovery;
    private $recoveryLog;
    private $store;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->io, $this->locks, $this->recovery, $this->recoveryLog, $this->store, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->currentGenerations = &$this->k->currentGenerations;
        $this->dbDir = &$this->k->dbDir;
        $this->dbHomes = &$this->k->dbHomes;
        $this->durable = &$this->k->durable;
        $this->formatRecords = &$this->k->formatRecords;
        $this->genPaths = &$this->k->genPaths;
        $this->lockPaths = &$this->k->lockPaths;
        $this->shardSize = &$this->k->shardSize;
        $this->directoryListings = &$this->rt->directoryListings; // @test
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->noWrites = &$this->rt->noWrites;
        $this->staticCacheEnabled = &$this->rt->staticCacheEnabled;
        $this->staticFileExistsCache = &$this->rt->staticFileExistsCache;
        $this->staticSanitizeCache = &$this->rt->staticSanitizeCache;
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
        $this->io = null;
        $this->locks = null;
        $this->recovery = null;
        $this->recoveryLog = null;
        $this->store = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Validate a database name.
     * Allowed characters: A-Z a-z 0-9, space, apostrophe and hyphen. Anything else is rejected, never
     * stripped: stripping would let different names collide.
     *
     * @param mixed $dbname Database name
     * @return string The (unchanged) database name
     * @throws \InvalidArgumentException
     */
    public function normalizeDbName($dbname){
        if(is_int($dbname)){
            $dbname = (string)$dbname;
        }
        if(!is_string($dbname)){
            throw new \InvalidArgumentException('noneDB: database name must be a string');
        }
        if(isset($this->staticSanitizeCache[$dbname])){
            return $dbname;
        }
        if($dbname === '' || preg_match("/^[A-Za-z0-9' -]+$/D", $dbname) !== 1){
            throw new \InvalidArgumentException(
                "noneDB: invalid database name '{$dbname}'. Only letters A-Z/a-z, digits, space, apostrophe (') and hyphen (-) are allowed."
            );
        }
        if($this->staticCacheEnabled){
            $this->staticSanitizeCache[$dbname] = true;
        }
        return $dbname;
    }

    public function dbHome($dbname){
        if(isset($this->dbHomes[$dbname])){
            return $this->dbHomes[$dbname];
        }
        if(count($this->dbHomes) >= 4096){
            $this->dbHomes = $this->lockPaths = [];
        }
        $home = $this->dbHomes[$dbname] = $this->dbDir . $this->store->locator($dbname) . '.ndb4/';
        $this->lockPaths[$dbname] = $home . 'LOCK';
        return $home;
    }

    /**
     * Active generation of a database. A database without a valid CURRENT (absent, being created) uses
     * generation 1: the state check in prepareDb() runs before any of its files is used.
     */
    public function generationNumber($dbname){
        $lock = $this->locks->getLockPath($dbname);
        if(isset($this->currentGenerations[$lock])){
            return $this->currentGenerations[$lock];
        }
        $format = $this->readFormatRecord($dbname);
        $current = $format === null ? null : $this->readCurrentRecord($dbname, $format);
        $generation = $current === null ? 1 : $current['generation'];
        if($current !== null){
            $this->currentGenerations[$lock] = $generation;
        }
        return $generation;
    }

    public function genDir($dbname){
        $home = $this->dbHomes[$dbname] ?? $this->dbHome($dbname);
        $generation = $this->currentGenerations[$this->lockPaths[$dbname]] ?? $this->generationNumber($dbname);
        if(($this->genPaths[$dbname]['gen'] ?? null) !== $generation){
            // Paths of the active generation are built once: the same strings (their hashes computed once)
            // serve every lookup of the caches keyed by them
            if(count($this->genPaths) >= 4096){
                $this->genPaths = [];
            }
            $this->genPaths[$dbname] = ['gen' => $generation, 'dir' => $home . 'generations/' . $generation . '/'];
        }
        return $this->genPaths[$dbname]['dir'];
    }

    /**
     * A file of the active generation by its path relative to the generation directory (cached)
     */
    public function genPath($dbname, $relative){
        // Hot path: the cached path while its generation is still the active one (no call)
        $paths = $this->genPaths[$dbname] ?? null;
        if(isset($paths['f'][$relative]) && $paths['gen'] === ($this->currentGenerations[$this->lockPaths[$dbname]] ?? null)){
            return $paths['f'][$relative];
        }
        $dir = $this->genDir($dbname);
        return $this->genPaths[$dbname]['f'][$relative] ?? ($this->genPaths[$dbname]['f'][$relative] = $dir . $relative);
    }

    public static function segmentName($shardId){
        return $shardId === null ? 'main' : 's' . $shardId;
    }

    /**
     * FORMAT of a database, validated; null when missing or invalid
     */
    public function readFormatRecord($dbname){
        $lock = $this->locks->getLockPath($dbname);
        if(isset($this->formatRecords[$lock])){
            return $this->formatRecords[$lock];
        }
        $record = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbHome($dbname) . 'FORMAT'), 'database');
        if($record !== null && !$this->validIdentity($record, $dbname)){
            $record = null;
        }
        if($record !== null){
            $this->formatRecords[$lock] = $record;
        }
        return $record;
    }

    /**
     * The identity members of a FORMAT or metadata record are valid for a database named $dbname (§4.1)
     */
    public function validIdentity(array $record, $dbname){
        return ($record['storageFormat'] ?? null) === \noneDB::STORAGE_FORMAT && is_string($record['databaseId'] ?? null)
            && ($record['name'] ?? null) === (string)$dbname && is_int($record['shardSize'] ?? null) && $record['shardSize'] > 0
            && is_int($record['createdAt'] ?? null);
    }

    /**
     * CURRENT, valid by §4.3 (checksum, databaseId of FORMAT, valid metadata of its generation), or null
     */
    private function readCurrentRecord($dbname, array $format){
        $record = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbHome($dbname) . 'CURRENT'), 'current');
        if($record === null || ($record['databaseId'] ?? null) !== $format['databaseId'] || !is_int($record['generation'] ?? null)
            || $record['generation'] < 1){
            return null;
        }
        return $this->readGenerationMetadata($dbname, $record['generation'], $format['databaseId']) === null ? null : $record;
    }

    private function readGenerationMetadata($dbname, $generation, $databaseId){
        $record = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbHome($dbname) . 'generations/' . $generation . '/metadata'), 'generation');
        if($record === null || ($record['databaseId'] ?? null) !== $databaseId || ($record['generation'] ?? null) !== $generation
            || !$this->validIdentity($record, $dbname) || !in_array($record['layout'] ?? null, ['single', 'sharded'], true)){
            return null;
        }
        return $record;
    }

    public function identityOf(array $record){
        return ['storageFormat' => $record['storageFormat'], 'databaseId' => $record['databaseId'], 'name' => $record['name'],
            'shardSize' => $record['shardSize'], 'createdAt' => $record['createdAt'],
            'formatOptions' => (object)(is_array($record['formatOptions'] ?? null) ? $record['formatOptions'] : [])]; // always a JSON object
    }

    public function writeGenerationMetadata($dbname, $generation, array $identity, $layout){
        $record = ['magic' => 'NONEDB4', 'kind' => 'generation'] + $identity + ['generation' => $generation, 'layout' => $layout];
        if(!$this->io->writeFileAtomic($this->dbHome($dbname) . 'generations/' . $generation . '/metadata', \noneDB\Internal\Storage\StoreRecord::sealRecord($record), 'durable')){
            throw new \RuntimeException("noneDB: cannot write the metadata of generation {$generation} of database '{$dbname}'");
        }
    }

    private function writeCurrentRecord($dbname, $databaseId, $generation){
        $record = ['magic' => 'NONEDB4', 'kind' => 'current', 'databaseId' => $databaseId, 'generation' => $generation];
        if(!$this->io->writeFileAtomic($this->dbHome($dbname) . 'CURRENT', \noneDB\Internal\Storage\StoreRecord::sealRecord($record), 'durable')){
            throw new \RuntimeException("noneDB: cannot write CURRENT of database '{$dbname}'");
        }
        $this->currentGenerations[$this->locks->getLockPath($dbname)] = $generation;
    }

    private function writeFormatRecord($dbname, array $identity){
        $record = ['magic' => 'NONEDB4', 'kind' => 'database'] + $identity;
        if(!$this->io->writeFileAtomic($this->dbHome($dbname) . 'FORMAT', \noneDB\Internal\Storage\StoreRecord::sealRecord($record), 'durable')){
            throw new \RuntimeException("noneDB: cannot write FORMAT of database '{$dbname}'");
        }
        $this->formatRecords[$this->locks->getLockPath($dbname)] = \noneDB\Internal\Storage\StoreRecord::openRecord(\noneDB\Internal\Storage\StoreRecord::sealRecord($record), 'database');
        $this->cache->markFileExists($this->dbHome($dbname) . 'FORMAT');
    }

    /**
     * True when the database exists (FORMAT valid). Cached until the lock epoch changes.
     */
    public function dbPresent($dbname){
        return $this->readFormatRecord($dbname) !== null;
    }

    /**
     * Entries of the database directory other than LOCK (and temp files)
     * @return string[] names
     */
    private function dbHomeEntries($dbname){
        $this->directoryListings++; // @test
        $entries = [];
        foreach(@scandir($this->dbHome($dbname)) ?: [] as $entry){
            if($entry !== '.' && $entry !== '..' && $entry !== 'LOCK' && preg_match('/\.tmp[0-9a-f]{8}$/', $entry) !== 1){
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * Resolve the state of a database directory (§8.3) under its lock; called by prepareDb().
     * Interrupted creation and drop are completed (exclusive); a damaged directory is repaired only with
     * proof, otherwise FORMAT_DAMAGED. Under $noWrites nothing is changed: the state is only reported.
     */
    public function resolveDatabaseState($dbname, $path){
        $flags = $this->lockRegistry[$path]['flags'];
        $format = $this->readFormatRecord($dbname);
        if(!empty($flags['d'])){
            $this->requireExclusiveRecovery($dbname, $path, function() use ($dbname, $path){
                $this->dropDatabaseFiles($dbname, $path);
            });
            return;
        }
        if(!empty($flags['c'])){
            $this->requireExclusiveRecovery($dbname, $path, function() use ($dbname, $path){
                if($this->readFormatRecord($dbname) === null){
                    $this->undoCreation($dbname, $path);
                    return;
                }
                unset($this->lockRegistry[$path]['flags']['c']);
                $this->locks->saveLockState($path);
            });
            return;
        }
        if($format !== null){
            if(isset($this->currentGenerations[$path])){
                return; // CURRENT was validated under this epoch
            }
            $current = $this->readCurrentRecord($dbname, $format);
            if($current === null){
                $this->forgetDatabaseLayout($dbname); // the next operation checks again, never the hot path
                throw new \RuntimeException("noneDB: database '{$dbname}' is damaged: CURRENT is missing or invalid", \noneDB::FORMAT_DAMAGED);
            }
            $this->currentGenerations[$path] = $current['generation'];
            return;
        }
        if(empty($this->dbHomeEntries($dbname))){
            return; // absent: only LOCK
        }
        // Damaged: FORMAT missing or invalid, other entries exist. Repair only with proof (§8.3).
        if($this->noWrites > 0){
            throw new \RuntimeException("noneDB: database '{$dbname}' is damaged: FORMAT is missing or invalid", \noneDB::FORMAT_DAMAGED);
        }
        $this->requireExclusiveRecovery($dbname, $path, function() use ($dbname){
            if($this->readFormatRecord($dbname) !== null){
                return;
            }
            $current = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbHome($dbname) . 'CURRENT'), 'current');
            $metadata = $current === null || !is_int($current['generation'] ?? null) ? null
                : \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbHome($dbname) . 'generations/' . $current['generation'] . '/metadata'), 'generation');
            if($metadata === null || ($metadata['databaseId'] ?? null) !== ($current['databaseId'] ?? false)
                || ($metadata['generation'] ?? null) !== $current['generation'] || !$this->validIdentity($metadata, $dbname)){
                throw new \RuntimeException("noneDB: database '{$dbname}' is damaged: FORMAT is missing or invalid and cannot be repaired with proof", \noneDB::FORMAT_DAMAGED);
            }
            $this->writeFormatRecord($dbname, $this->identityOf($metadata));
            $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'format-repaired', 'generation' => $current['generation']]);
        });
    }

    /**
     * Run a recovery step that needs the exclusive lock; under $noWrites report instead of changing
     */
    private function requireExclusiveRecovery($dbname, $path, callable $fn){
        if($this->noWrites > 0){
            return;
        }
        if($this->lockRegistry[$path]['mode'] === LOCK_EX){
            $fn();
            return;
        }
        $this->locks->withLock($dbname, true, $fn, 'recovery');
    }

    /**
     * Drop every cached view of a database's layout (FORMAT, CURRENT)
     */
    public function forgetDatabaseLayout($dbname){
        $lock = $this->locks->getLockPath($dbname);
        unset($this->currentGenerations[$lock], $this->formatRecords[$lock], $this->genPaths[$dbname]);
        unset($this->staticFileExistsCache[$this->dbHome($dbname) . 'FORMAT']);
    }

    /**
     * Create a database (§8.1); caller holds its lock exclusively and STORE.LOCK shared. Durable mode
     * (DURABILITY.md §3): "c" is synced before anything is created, every new entry with its directory,
     * and the creation commits when FORMAT is durable.
     * restore() (§10.3) passes the identity and $prepare($dir, 1), which builds and verifies generation 1 from
     * a backup; when it fails the creation is undone at once (nothing of the database was committed).
     */
    public function createDatabaseFiles($dbname, ?array $identity = null, ?callable $prepare = null){
        $path = $this->locks->getLockPath($dbname);
        $this->lockRegistry[$path]['flags']['c'] = true;
        $this->locks->saveLockState($path, true);
        $this->recovery->fault('create:flagged', ['db' => $dbname]); // @test
        // The database directory's entry in dbDir (LOCK's entry in it goes with the next sync of the directory)
        $this->sync->requireSync(null, rtrim($this->dbDir, '/'), 'dir');
        $identity = $identity ?? ['storageFormat' => \noneDB::STORAGE_FORMAT, 'databaseId' => \noneDB\Internal\Storage\StoreRecord::uuid4(), 'name' => (string)$dbname,
            'shardSize' => (int)$this->shardSize, 'createdAt' => time(), 'formatOptions' => new \stdClass()];
        $this->forgetDatabaseLayout($dbname);
        $this->removeTree($this->dbHome($dbname) . 'generations');
        if($prepare === null){
            $this->writeGenerationMetadata($dbname, 1, $identity, 'single');
            // Generation 1 is complete with its empty main segment
            $dataPath = $this->dbHome($dbname) . 'generations/1/segments/main.jsonl';
            if(!$this->io->fsMkdir(dirname($dataPath)) || (!$this->io->fsTouch($dataPath) && !is_file($dataPath))){
                throw new \RuntimeException("noneDB: cannot create database file for '{$dbname}'");
            }
            $this->sync->requireSync(null, dirname($dataPath), 'dir');
        } else {
            $dir = $this->dbHome($dbname) . 'generations/1/';
            try {
                $prepare($dir, 1);
            } catch(\Throwable $e){
                try {
                    $this->undoCreation($dbname, $path);
                } catch(\Throwable $ignored){
                    // "c" stays: the next exclusive session undoes the creation
                }
                throw $e;
            }
            if($this->bufferHoldsFile($dir)){
                // The copied buffer's records are visible once FORMAT is: "b" is synced before CURRENT
                $this->lockRegistry[$path]['flags']['b'] = true;
                $this->locks->saveLockState($path);
            }
        }
        $this->recovery->fault('create:generation', ['db' => $dbname]); // @test
        $this->writeCurrentRecord($dbname, $identity['databaseId'], 1);
        $this->recovery->fault('create:current', ['db' => $dbname]); // @test
        $this->writeFormatRecord($dbname, $identity);
        $this->recovery->fault('create:format', ['db' => $dbname]); // @test
        unset($this->lockRegistry[$path]['flags']['c']);
        $this->locks->saveLockState($path);
    }

    /**
     * Undo an interrupted creation (§8.3): nothing of it was committed (creation writes no data, a restore's
     * copy is in its backup), so what it left goes; caller holds the lock exclusively
     */
    private function undoCreation($dbname, $path){
        foreach(['CURRENT', 'generations'] as $entry){
            $this->removeTree($this->dbHome($dbname) . $entry);
        }
        $this->forgetDatabaseLayout($dbname);
        unset($this->lockRegistry[$path]['flags']['c'], $this->lockRegistry[$path]['flags']['b']);
        $this->locks->saveLockState($path);
    }

    /**
     * Drop a database (§8.2), or finish an interrupted drop; caller holds its lock exclusively. LOCK and
     * the directory stay. $request: a dropDB() call, which reports a drop that can't remove FORMAT (its
     * commit point) as not committed; finishing an interrupted one throws instead.
     * Durable mode: "d" (with a new epoch) is synced before FORMAT goes, the drop commits when FORMAT's
     * removal is durable, and "d" is cleared only once the other removals are durable too.
     * @return array ['committed' => bool, 'removed' => int files, 'left' => string[] files not removed]
     */
    public function dropDatabaseFiles($dbname, $path, $request = false){
        if(empty($this->lockRegistry[$path]['flags']['d'])){
            $before = $this->lockRegistry[$path]['flags'];
            $this->lockRegistry[$path]['flags']['d'] = true;
            unset($this->lockRegistry[$path]['flags']['b'], $this->lockRegistry[$path]['flags']['j']);
            try {
                $this->locks->bumpEpoch($dbname); // writes "d" with the new epoch
            } catch(\RuntimeException $e){
                if($e->getCode() === \noneDB::DURABILITY_FAILED){
                    $this->withdrawDrop($dbname, $path, $before, $e);
                }
                throw $e;
            }
        }
        $this->recovery->fault('drop:flagged', ['db' => $dbname]); // @test
        $home = $this->dbHome($dbname);
        clearstatcache(true, $home . 'FORMAT');
        $removed = 0;
        if(is_file($home . 'FORMAT')){
            if(!$this->io->fsUnlink($home . 'FORMAT')){
                // The commit point was not reached: the database stays as it was
                if(!$request){
                    throw new \RuntimeException("noneDB: cannot finish the interrupted drop of database '{$dbname}': its FORMAT can't be removed");
                }
                $this->withdrawDrop($dbname, $path, $before, null);
                return ['committed' => false, 'removed' => 0, 'left' => $this->dbHomeFiles($dbname)];
            }
            $removed++;
            $this->forgetDatabaseLayout($dbname);
            // The commit point: FORMAT's removal is durable
            $this->sync->requireSync(null, rtrim($home, '/'), 'dir', true);
        }
        $this->recovery->fault('drop:format', ['db' => $dbname]); // @test
        foreach(['CURRENT', 'generations', 'recovery'] as $entry){
            $removed += $this->removeTree($home . $entry);
        }
        // "d" goes only once these removals are durable: else a power loss could leave the files without it
        $durable = !$this->durable || $this->sync->syncDirectory(rtrim($home, '/')) === true;
        $this->forgetDatabaseLayout($dbname);
        $this->cache->invalidateDbCaches($dbname);
        $this->recovery->fault('drop:cleaned', ['db' => $dbname]); // @test
        $left = $this->dbHomeFiles($dbname);
        if(empty($left) && $durable){
            unset($this->lockRegistry[$path]['flags']['d']);
            $this->locks->saveLockState($path);
        }
        // Otherwise "d" stays: the database is gone, the next exclusive session removes what is left (§8.2)
        return ['committed' => true, 'removed' => $removed, 'left' => $left];
    }

    /**
     * A drop that did not reach its commit point: "d" must go (with FORMAT present it would finish the drop),
     * durably in durable mode. If that can't be done either, the outcome is unknown.
     * @throws \RuntimeException OUTCOME_UNKNOWN
     */
    private function withdrawDrop($dbname, $path, array $flags, ?\Throwable $cause){
        $this->lockRegistry[$path]['flags'] = $flags;
        try {
            $this->locks->saveLockState($path, false, true);
        } catch(\RuntimeException $e){
            throw new \RuntimeException("noneDB: the drop of database '{$dbname}' failed and its drop flag could not be withdrawn durably: the outcome is unknown, read the state again", \noneDB::OUTCOME_UNKNOWN, $cause ?? $e);
        }
    }

    /**
     * Files of a database directory except LOCK, relative to it (a drop's leftovers)
     * @return string[]
     */
    private function dbHomeFiles($dbname){
        $this->directoryListings++; // @test
        $home = $this->dbHome($dbname);
        $files = [];
        $walk = function($dir) use (&$walk, &$files, $home){
            foreach(@scandir($dir) ?: [] as $entry){
                if($entry === '.' || $entry === '..'){
                    continue;
                }
                if(is_dir($dir . $entry)){
                    $walk($dir . $entry . '/');
                } elseif($dir . $entry !== $home . 'LOCK'){
                    $files[] = substr($dir . $entry, strlen($home));
                }
            }
        };
        $walk($home);
        $walk = null; // the closure refers to itself: freed now, not by the garbage collector
        sort($files);
        return $files;
    }

    /**
     * Remove a file or a directory tree of a database (fsUnlink for files, rmdir for directories)
     * @return int files removed
     */
    private function removeTree($path){
        $path = rtrim($path, '/');
        clearstatcache(true, $path);
        if(is_file($path) || is_link($path)){
            return $this->io->fsUnlink($path) ? 1 : 0;
        }
        if(!is_dir($path)){
            return 0;
        }
        $removed = 0;
        foreach(@scandir($path) ?: [] as $entry){
            if($entry !== '.' && $entry !== '..'){
                $removed += $this->removeTree($path . '/' . $entry);
            }
        }
        $this->io->fsRmdir($path);
        return $removed;
    }

    /**
     * Generation switch (§5.2). $prepare($dir, $generation) builds the complete new generation in $dir;
     * caller holds the database lock exclusively. Returns the new generation number.
     */
    public function switchGeneration($dbname, callable $prepare){
        $path = $this->locks->getLockPath($dbname);
        $format = $this->readFormatRecord($dbname);
        if($format === null){
            throw new \RuntimeException("noneDB: database '{$dbname}' does not exist", \noneDB::FORMAT_DAMAGED);
        }
        $old = $this->generationNumber($dbname);
        $new = $old + 1;
        $newDir = $this->dbHome($dbname) . 'generations/' . $new . '/';
        $this->removeTree($newDir); // an unreferenced leftover of an earlier, interrupted PREPARE
        // PREPARE: the callback builds and verifies generation $new; CURRENT still names $old. A failed
        // PREPARE leaves nothing behind (the next switch would remove it anyway).
        try {
            $prepare($newDir, $new);
        } catch(\Throwable $e){
            try {
                $this->removeTree($newDir);
            } catch(\Throwable $ignored){
            }
            throw $e;
        }
        $this->recovery->fault('generation:prepared', ['db' => $dbname, 'from' => $old, 'to' => $new]); // @test
        // INTENT: g=old>new and a new epoch, synced; from now on no process uses a cache of the old epoch.
        // "b" when the new generation holds buffered records (a restore's), kept when the old one may
        $this->lockRegistry[$path]['flags']['g'] = [$old, $new];
        if($this->bufferHoldsFile($newDir)){
            $this->lockRegistry[$path]['flags']['b'] = true;
        }
        $this->lockRegistry[$path]['epoch'] = bin2hex(random_bytes(4));
        $this->locks->saveLockState($path, true);
        $this->recovery->fault('generation:intent', ['db' => $dbname, 'from' => $old, 'to' => $new]); // @test
        // PUBLISH: the commit point (a failed directory sync after CURRENT's rename: unknown, "g" stays)
        $this->writeCurrentRecord($dbname, $format['databaseId'], $new);
        $this->recovery->fault('generation:published', ['db' => $dbname, 'from' => $old, 'to' => $new]); // @test
        // CLEANUP
        $oldDir = $this->dbHome($dbname) . 'generations/' . $old;
        $this->removeTree($oldDir);
        clearstatcache(true, $oldDir);
        $cleaned = !is_dir($oldDir) && (!$this->durable || $this->sync->syncDirectory($this->dbHome($dbname) . 'generations') === true);
        $this->recovery->fault('generation:cleaned', ['db' => $dbname, 'from' => $old, 'to' => $new]); // @test
        // DONE ("g" stays while the old generation is there or its removal may not survive a power loss:
        // the next session finishes the cleanup)
        if($cleaned){
            unset($this->lockRegistry[$path]['flags']['g']);
            if(!$this->bufferHoldsFile($newDir)){
                unset($this->lockRegistry[$path]['flags']['b']);
            }
            $this->locks->saveLockState($path);
        }
        $this->cache->invalidateDbCaches($dbname);
        $this->currentGenerations[$path] = $new;
        return $new;
    }

    /**
     * restore() over a damaged database (§10.3): $prepare($dir, X) builds and verifies generation X, one above
     * the highest number under generations/; then a new epoch without "g" (and "b" when X holds buffered
     * records), CURRENT names X (the commit point), and an invalid FORMAT is rewritten with $identity. The
     * earlier generations stay: nothing proves which of them was committed. Caller holds the lock exclusively.
     * @return int X
     */
    public function replaceDamagedGeneration($dbname, array $identity, callable $prepare){
        $path = $this->locks->getLockPath($dbname);
        $highest = 0;
        foreach(@scandir($this->dbHome($dbname) . 'generations') ?: [] as $entry){
            if(ctype_digit((string)$entry)){
                $highest = max($highest, (int)$entry);
            }
        }
        $new = $highest + 1;
        $dir = $this->dbHome($dbname) . 'generations/' . $new . '/';
        try {
            $prepare($dir, $new);
        } catch(\Throwable $e){
            try {
                $this->removeTree($dir);
            } catch(\Throwable $ignored){
            }
            throw $e;
        }
        unset($this->lockRegistry[$path]['flags']['g'], $this->lockRegistry[$path]['flags']['b']);
        if($this->bufferHoldsFile($dir)){
            $this->lockRegistry[$path]['flags']['b'] = true;
        }
        $this->locks->bumpEpoch($dbname);
        $this->recovery->fault('restore:intent', ['db' => $dbname, 'to' => $new]); // @test
        $this->writeCurrentRecord($dbname, $identity['databaseId'], $new);
        $this->recovery->fault('restore:published', ['db' => $dbname, 'to' => $new]); // @test
        if($this->readFormatRecord($dbname) === null){
            $this->writeFormatRecord($dbname, $identity);
        }
        $this->cache->invalidateDbCaches($dbname);
        $this->currentGenerations[$path] = $new;
        return $new;
    }

    /**
     * State of a database for restore() (§10.3), once its lock resolved what it could: 'present' (FORMAT and
     * CURRENT valid, no switch pending), 'absent' (no FORMAT, nothing but LOCK) or 'damaged'
     */
    public function restoreTargetState($dbname){
        $format = $this->readFormatRecord($dbname);
        if($format === null){
            return empty($this->dbHomeEntries($dbname)) ? 'absent' : 'damaged';
        }
        $flags = $this->lockRegistry[$this->locks->getLockPath($dbname)]['flags'] ?? [];
        return empty($flags['g']) && $this->readCurrentRecord($dbname, $format) !== null ? 'present' : 'damaged';
    }

    /**
     * Run $fn reading generation $generation of a database as if it were the active one, under $identity,
     * writing nothing: restore() verifies a generation before it is published (§10.3). Caller holds the lock
     * exclusively.
     */
    public function withGenerationView($dbname, $generation, array $identity, callable $fn){
        $lock = $this->locks->getLockPath($dbname);
        $saved = [$this->currentGenerations[$lock] ?? null, $this->formatRecords[$lock] ?? null];
        $this->cache->invalidateDbCaches($dbname);
        $this->currentGenerations[$lock] = $generation;
        $this->formatRecords[$lock] = ['magic' => 'NONEDB4', 'kind' => 'database'] + $identity;
        unset($this->genPaths[$dbname]);
        $this->noWrites++;
        try {
            return $fn();
        } finally {
            $this->noWrites--;
            $this->cache->invalidateDbCaches($dbname);
            unset($this->genPaths[$dbname], $this->currentGenerations[$lock], $this->formatRecords[$lock]);
            if($saved[0] !== null){
                $this->currentGenerations[$lock] = $saved[0];
            }
            if($saved[1] !== null){
                $this->formatRecords[$lock] = $saved[1];
            }
        }
    }

    /**
     * True when a generation directory's buffer/ holds a pending or flushing file (§5.2: "b")
     */
    private function bufferHoldsFile($dir){
        if(!is_dir($dir . 'buffer')){
            return false;
        }
        $this->directoryListings++; // @test
        foreach(@scandir($dir . 'buffer') ?: [] as $entry){
            if($entry === 'pending.jsonl' || preg_match('/^flushing\.\d+\.jsonl$/', $entry) === 1){
                return true;
            }
        }
        return false;
    }

    /**
     * Recovery of an interrupted generation switch (§5.2): g=N>X found; caller holds the lock exclusively
     */
    public function recoverGenerationSwitch($dbname, $path){
        [$old, $new] = $this->lockRegistry[$path]['flags']['g'];
        $this->forgetDatabaseLayout($dbname);
        $format = $this->readFormatRecord($dbname);
        $current = $format === null ? null : $this->readCurrentRecord($dbname, $format);
        if($current === null || !in_array($current['generation'], [$old, $new], true)){
            $this->forgetDatabaseLayout($dbname);
            throw new \RuntimeException("noneDB: database '{$dbname}' is damaged: a generation switch from {$old} to {$new} was interrupted and CURRENT is invalid; neither generation is removed (restore a backup, see docs/OPERATIONS.md)", \noneDB::FORMAT_DAMAGED);
        }
        $obsolete = $current['generation'] === $new ? $old : $new;
        $obsoleteDir = $this->dbHome($dbname) . 'generations/' . $obsolete;
        $this->removeTree($obsoleteDir);
        clearstatcache(true, $obsoleteDir);
        if(is_dir($obsoleteDir)){
            // CURRENT decides, so the data is consistent; what is left is reported by checkIntegrity()
            // instead of being retried by every later operation
            $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'generation-cleanup-failed', 'committed' => $current['generation'], 'left' => $obsolete]);
        } else {
            $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'generation-recovered', 'committed' => $current['generation'], 'removed' => $obsolete]);
        }
        unset($this->lockRegistry[$path]['flags']['g']);
        $this->locks->saveLockState($path);
        $this->cache->invalidateDbCaches($dbname);
        $this->currentGenerations[$path] = $current['generation'];
    }
}
