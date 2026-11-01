<?php
// Key maintenance of a store: migrateKey(), registerKey(), repairStore() (docs/STORAGE_FORMAT.md §9)
namespace noneDB\Internal\Maintenance;

use function array_column, array_diff, array_filter, array_key_exists, array_keys, array_map, array_merge,
    array_reverse, array_shift, array_unique, array_values, bin2hex, clearstatcache, count, dirname, explode, fclose,
    fflush, file_exists, file_get_contents, flock, fopen, fread, fseek, fwrite, implode, in_array, is_array, is_dir,
    is_file, is_int, is_link, is_string, json_decode, json_encode, preg_match, random_bytes, rtrim, scandir, sort,
    str_pad, trim, unlink;
use const JSON_PRESERVE_ZERO_FRACTION, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, LOCK_EX, LOCK_NB,
    LOCK_UN, SORT_STRING;

/**
 * @internal
 */
final class KeyMaintenance {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $dbDir;
    private $maintenanceDepth;
    private $storeState;
    private $io;
    private $locks;
    private $recovery;
    private $store;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->io, $this->locks, $this->recovery, $this->store, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dbDir = &$this->k->dbDir;
        $this->maintenanceDepth = &$this->rt->maintenanceDepth;
        $this->storeState = &$this->rt->storeState;
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
        $this->io = null;
        $this->locks = null;
        $this->recovery = null;
        $this->store = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // KEY MAINTENANCE (docs/STORAGE_FORMAT.md §9)
    // ==========================================
    //
    // Every operation follows the maintenance order: STORE.LOCK (exclusive, non-blocking), then every
    // target database's LOCK (non-blocking; one busy lock: nothing written, MAINTENANCE_BUSY), then the
    // journal .nonedb-maintenance/journal.json, then "m" and a new epoch in every target's state record.
    // The logical commit point is the journal in state COMMITTED with the complete target STORE record;
    // the STORE copies, clearing "m" and closing the journal follow and are repeated by a re-run.

    /**
     * Rotate the secret key of every database in $dbDir that belongs to $oldKey: their directories are
     * renamed to the new key's locators (contents unchanged: FORMAT and metadata hold no key) and STORE
     * registers $newKey instead of $oldKey. Application processes must be stopped. Re-running after an
     * interruption finishes the rotation; ['abort' => true] undoes one that did not commit yet.
     * @return array ['success' => bool, 'databases' => string[], 'storeManifest' => array|null, 'error' => ?string, 'code' => ?int]
     */
    public static function migrateKey(string $dbDir, string $oldKey, string $newKey, array $options = []): array {
        self::checkOptions($options, ['abort', 'durability'], 'migrateKey()');
        $result = ['success' => false, 'databases' => [], 'storeManifest' => null, 'error' => null, 'code' => null];
        if(trim($oldKey) === '' || trim($newKey) === ''){
            $result['error'] = 'Both keys must be non-empty strings';
            return $result;
        }
        if(in_array($newKey, \noneDB\Internal\Cache\Runtime::get()->placeholderKeys, true)){
            $result['error'] = 'The new key is a placeholder; generate a random one';
            return $result;
        }
        if($oldKey === $newKey){
            $result['error'] = 'The new key is the same as the old key';
            return $result;
        }
        return self::maintenanceCall($dbDir, $oldKey, $result, $options, function(self $db) use ($oldKey, $newKey, $options){
            return $db->rotateKey($oldKey, $newKey, $options['abort'] ?? false);
        });
    }

    /**
     * Register a further key for the store in $dbDir; $registeredKey must already be registered
     * @return array ['success' => bool, 'storeManifest' => array|null, 'error' => ?string, 'code' => ?int]
     */
    public static function registerKey(string $dbDir, string $registeredKey, string $newKey, array $options = []): array {
        self::checkOptions($options, ['durability'], 'registerKey()');
        $result = ['success' => false, 'storeManifest' => null, 'error' => null, 'code' => null];
        if(trim($newKey) === '' || in_array($newKey, \noneDB\Internal\Cache\Runtime::get()->placeholderKeys, true)){
            $result['error'] = 'The new key must be a non-empty string and not a placeholder';
            return $result;
        }
        return self::maintenanceCall($dbDir, $registeredKey, $result, $options, function(self $db) use ($newKey){
            return $db->addStoreKey(\noneDB\Internal\Storage\StoreRecord::keyCheckOf($newKey));
        });
    }

    /**
     * Rebuild a store record whose two copies are both invalid (STORAGE_FORMAT.md §9.4). Every database
     * directory must belong to one of $keys. ['manifest' => path or array]: a verified STORE record (a
     * backup's store.json, a maintenance result's storeManifest) is restored (complete). Without one, only
     * ['confirmManualRebuild' => true] rebuilds the record from $keys (not complete: keys without
     * databases that were registered before are lost).
     * @return array ['success' => bool, 'mode' => 'manifest'|'manual'|null, 'complete' => bool, 'storeManifest' => array|null,
     *               'error' => ?string (lists databases no key owns), 'code' => ?int]
     */
    public static function repairStore(string $dbDir, array $keys, array $options = []): array {
        self::checkOptions($options, ['manifest', 'confirmManualRebuild', 'durability'], 'repairStore()');
        $result = ['success' => false, 'mode' => null, 'complete' => false, 'storeManifest' => null, 'error' => null, 'code' => null];
        $keys = array_values(array_filter($keys, 'is_string'));
        if(empty($keys)){
            $result['error'] = 'At least one key is needed';
            return $result;
        }
        return self::maintenanceCall($dbDir, $keys[0], $result, $options, function(self $db) use ($keys, $options){
            return $db->rebuildStoreRecord($keys, $options);
        });
    }

    /**
     * The options of a maintenance method are the ones it names, of their types (API.md "Key maintenance"), checked
     * before anything is done
     * @throws \InvalidArgumentException
     */
    private static function checkOptions(array $options, array $known, string $method): void {
        \noneDB\Internal\Config\Configuration::checkNames($options, $known, 'option', " of {$method}");
        foreach(['abort', 'confirmManualRebuild'] as $name){
            if(array_key_exists($name, $options)){
                \noneDB\Internal\Config\Configuration::optionBool($name, $options[$name]);
            }
        }
        if(array_key_exists('manifest', $options) && !is_string($options['manifest']) && !is_array($options['manifest'])){
            throw new \InvalidArgumentException("noneDB: 'manifest' must be a file path or a store record (array)");
        }
        if(array_key_exists('durability', $options)){
            \noneDB\Internal\Config\Configuration::durabilityValue($options['durability']); // the platform: as the setting's (UNSUPPORTED_DURABILITY)
        }
    }

    /**
     * Run a maintenance operation with an instance for $dbDir and $key (no config file involved).
     * $options['durability']: 'performance' (default) or 'durable', as the setting (docs/DURABILITY.md).
     * @throws \InvalidArgumentException an invalid durability mode
     */
    private static function maintenanceCall(string $dbDir, string $key, array $result, array $options, callable $fn): array {
        if(!empty(\noneDB\Internal\Cache\Runtime::get()->lockRegistry)){
            $result['error'] = 'Maintenance can\'t run while this process holds a database lock (inside a database operation)';
            return $result;
        }
        $dbDir = rtrim($dbDir, '/\\') . '/';
        if(!is_dir($dbDir)){
            $result['error'] = "Directory not found: {$dbDir}";
            return $result;
        }
        try {
            \noneDB\Internal\Cache\CacheManager::clearStaticCache();
            // The instance stays referenced while its kernel works: its destructor forgets the key
            $instance = new \noneDB(['secretKey' => $key, 'dbDir' => $dbDir, 'autoCreateDB' => false,
                'durability' => $options['durability'] ?? 'performance']);
            $out = $fn(\noneDB\Internal\Core\Kernel::of($instance)->keyMaintenance);
            unset($instance);
            \noneDB\Internal\Cache\CacheManager::clearStaticCache();
            if(is_array($out['storeManifest'] ?? null)){
                // The complete STORE record with its checksum: usable as repairStore()'s manifest as it is
                $out['storeManifest'] = json_decode(\noneDB\Internal\Storage\StoreRecord::sealRecord($out['storeManifest']), true);
            }
            return array_merge($result, $out, ['success' => true]);
        } catch(\RuntimeException $e){
            \noneDB\Internal\Cache\CacheManager::clearStaticCache();
            return array_merge($result, ['error' => $e->getMessage(), 'code' => $e->getCode()]);
        }
    }

    // ---------------------------------------------------------------- the maintenance order

    private function journalPath(){
        return $this->dbDir . '.nonedb-maintenance/journal.json';
    }

    private function readJournal(){
        $path = $this->journalPath();
        clearstatcache(true, $path);
        if(!is_file($path)){
            return null;
        }
        $journal = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($path), 'journal');
        if($journal === null){
            throw new \RuntimeException("noneDB: the maintenance journal in {$this->dbDir} is unreadable; nothing is changed", \noneDB::FORMAT_DAMAGED);
        }
        return $journal;
    }

    private function writeJournal(array $journal){
        $dir = $this->dbDir . '.nonedb-maintenance';
        if(!is_dir($dir) && !$this->io->makeDirectory($dir)){
            throw new \RuntimeException("noneDB: cannot create {$dir}");
        }
        $this->recovery->fault('maintenance:journal', ['state' => $journal['state']]); // @test
        if(!$this->store->writeStoreFile($this->journalPath(), \noneDB\Internal\Storage\StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'journal'] + $journal))){
            throw new \RuntimeException("noneDB: cannot write the maintenance journal in {$this->dbDir}");
        }
    }

    /**
     * Remove the journal: the maintenance is complete. In durable mode a removal that may not survive a power
     * loss is an unknown outcome (the journal could come back and close the store until a re-run).
     * @throws \RuntimeException OUTCOME_UNKNOWN
     */
    private function closeJournal(){
        $this->recovery->fault('maintenance:close', []); // @test
        @unlink($this->journalPath());
        $this->recovery->removeStaleTemp($this->journalPath()); // a crashed journal write's temp (an abort writes none)
        $this->sync->requireSync(null, $this->dbDir . '.nonedb-maintenance', 'dir', true);
    }

    /**
     * Lock every target database (locator order, non-blocking). All or nothing: a busy one releases the
     * others and fails with MAINTENANCE_BUSY before anything was written.
     * @param string[] $locators
     * @return array locator => handle
     */
    /**
     * Lock every target database exclusively without waiting, in locator order (§9.2 step 2); one busy:
     * all released, MAINTENANCE_BUSY, nothing written
     * @param array $names locator => database name (for the message)
     */
    private function lockMaintenanceTargets(array $names){
        $locators = array_keys($names);
        sort($locators, SORT_STRING);
        $handles = [];
        foreach($locators as $locator){
            $path = $this->dbDir . $locator . '.ndb4/LOCK';
            $fp = is_dir(dirname($path)) ? @fopen($path, 'c+be') : false;
            if($fp === false || !flock($fp, LOCK_EX | LOCK_NB)){
                if($fp !== false){
                    fclose($fp);
                }
                foreach($handles as $held){
                    flock($held, LOCK_UN);
                    fclose($held);
                }
                throw new \RuntimeException("noneDB: database '{$names[$locator]}' ({$locator}.ndb4) is in use; stop the application processes and run the maintenance again", \noneDB::MAINTENANCE_BUSY);
            }
            $handles[$locator] = $fp;
        }
        return $handles;
    }

    private static function releaseMaintenanceTargets(array $handles){
        foreach($handles as $fp){
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Set or clear "m" in a held database lock's state record, always with a new epoch (synced)
     */
    private function markMaintenance($fp, $on, $path){
        fseek($fp, 0);
        $raw = trim((string)fread($fp, \noneDB\Internal\Concurrency\LockManager::LOCK_STATE_SIZE), " \0");
        $tokens = $raw === '' ? [] : explode(' ', $raw);
        $state = (string)array_shift($tokens);
        $gen = preg_match('/^([0-9]{1,18}):[0-9a-f]{8}$/', $state, $m) === 1 ? (int)$m[1] : 0;
        $tokens = array_values(array_filter($tokens, function($t){ return $t !== '' && $t !== 'm'; }));
        if($on){
            $tokens[] = 'm';
        }
        $record = str_pad(trim(($gen + 1) . ':' . bin2hex(random_bytes(4)) . ' ' . implode(' ', $tokens)), \noneDB\Internal\Concurrency\LockManager::LOCK_STATE_SIZE);
        fseek($fp, 0);
        if(fwrite($fp, $record) !== \noneDB\Internal\Concurrency\LockManager::LOCK_STATE_SIZE || !fflush($fp)){
            throw new \RuntimeException("noneDB: cannot write a database state record in {$this->dbDir}");
        }
        // Durable mode: "m" and its epoch are synced before the work, the cleared record before the journal goes
        $this->sync->requireSync($fp, $path, 'file');
    }

    /**
     * Clear "m" of databases after the commit (their locks are taken again, waiting up to lockTimeout)
     */
    private function clearMaintenanceMarks(array $locators){
        foreach($locators as $locator){
            $path = $this->dbDir . $locator . '.ndb4/LOCK';
            if(!is_file($path)){
                continue;
            }
            $fp = @fopen($path, 'c+be');
            if($fp === false){
                throw new \RuntimeException("noneDB: cannot open the lock of database {$locator}");
            }
            try {
                $this->locks->acquireFlock($fp, LOCK_EX, $locator);
                $this->markMaintenance($fp, false, $path);
                flock($fp, LOCK_UN);
            } finally {
                fclose($fp);
            }
        }
    }

    /**
     * Apply a committed journal: STORE copies, "m" cleared, journal closed
     */
    private function applyCommittedJournal(array $journal){
        try {
            $this->store->writeStoreCopies($journal['store']);
            $this->recovery->fault('maintenance:store-written', []); // @test
            $this->clearMaintenanceMarks($journal['clear'] ?? []);
            $this->closeJournal();
        } catch(\RuntimeException $e){
            if($e->getCode() === \noneDB::OUTCOME_UNKNOWN){
                throw $e; // the journal is gone in this process; whether that is durable is unknown
            }
            // Committed but not completed: never reported as success; the journal stays open (the store stays
            // closed) until a re-run applies it (STORAGE_FORMAT.md §9.2)
            throw new \RuntimeException("noneDB: the maintenance of the store in {$this->dbDir} is committed but not completed ("
                . $e->getMessage() . "); run it again", \noneDB::MAINTENANCE_IN_PROGRESS, $e);
        }
        unset($this->storeState[$this->dbDir]);
        return $journal['store'];
    }

    // ---------------------------------------------------------------- operations

    private function rotateKey($oldKey, $newKey, $abort){
        $this->maintenanceDepth++;
        try {
            return $this->store->withStoreLock(LOCK_EX, function() use ($oldKey, $newKey, $abort){
                $journal = $this->readJournal();
                if($journal !== null){
                    return $this->resumeRotation($journal, $abort);
                }
                $store = $this->store->loadStoreRecord();
                $oldCheck = \noneDB\Internal\Storage\StoreRecord::keyCheckOf($oldKey);
                if($store === null || !in_array($oldCheck, $store['keys'], true)){
                    throw new \RuntimeException("noneDB: the old key is not registered for the store in {$this->dbDir}", \noneDB::KEY_NOT_REGISTERED);
                }
                // The databases of the old key and where they go
                $targets = [];
                foreach(@scandir($this->dbDir) ?: [] as $entry){
                    if(preg_match('/^([0-9a-f]{32})\.ndb4$/', $entry, $m) !== 1 || is_link($this->dbDir . $entry)){
                        continue;
                    }
                    $format = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbDir . $entry . '/FORMAT'), 'database');
                    if(!is_array($format) || !is_string($format['name'] ?? null) || \noneDB\Internal\Storage\StoreRecord::locatorOf($format['name'], $oldKey) !== $m[1]){
                        continue;
                    }
                    $to = \noneDB\Internal\Storage\StoreRecord::locatorOf($format['name'], $newKey);
                    if(file_exists($this->dbDir . $to . '.ndb4')){
                        throw new \RuntimeException("noneDB: the new key's directory for database '{$format['name']}' already exists", \noneDB::MAINTENANCE_BUSY);
                    }
                    $targets[] = ['name' => $format['name'], 'from' => $m[1], 'to' => $to, 'databaseId' => $format['databaseId'], 'done' => false];
                }
                $handles = $this->lockMaintenanceTargets(array_column($targets, 'name', 'from'));
                $target = $store;
                $target['keys'] = array_values(array_unique(array_merge(array_diff($store['keys'], [$oldCheck]), [\noneDB\Internal\Storage\StoreRecord::keyCheckOf($newKey)])));
                sort($target['keys'], SORT_STRING);
                $target['seq'] = $store['seq'] + 1;
                $journal = ['op' => 'rotate', 'id' => bin2hex(random_bytes(8)), 'state' => 'STARTED', 'targets' => $targets,
                    'store' => $target, 'clear' => array_column($targets, 'to')];
                try {
                    $this->writeJournal($journal);
                    foreach($targets as $t){
                        $this->markMaintenance($handles[$t['from']], true, $this->dbDir . $t['from'] . '.ndb4/LOCK');
                    }
                    $this->recovery->fault('rotate:marked', []); // @test
                } finally {
                    self::releaseMaintenanceTargets($handles); // "m" keeps every process out from here on
                }
                return $this->resumeRotation($journal, $abort);
            }, true);
        } finally {
            $this->maintenanceDepth--;
        }
    }

    /**
     * Continue a rotation from its journal: rename what is left, verify, commit, apply
     */
    private function resumeRotation(array $journal, $abort){
        if($journal['op'] !== 'rotate'){
            throw new \RuntimeException("noneDB: another maintenance ({$journal['op']}) of the store in {$this->dbDir} is unfinished; run it again", \noneDB::MAINTENANCE_IN_PROGRESS);
        }
        if($journal['state'] === 'COMMITTED'){
            $store = $this->applyCommittedJournal($journal);
            return ['databases' => array_column($journal['targets'], 'name'), 'storeManifest' => $store];
        }
        if($abort){
            $this->undoRotationRenames($journal);
            return ['databases' => [], 'storeManifest' => $this->store->loadStoreRecord(), 'aborted' => true];
        }
        foreach($journal['targets'] as $i => $t){
            $from = $this->dbDir . $t['from'] . '.ndb4';
            $to = $this->dbDir . $t['to'] . '.ndb4';
            if(is_dir($from) && !file_exists($to)){
                if(!$this->sync->renameWithRetry($from, $to)){
                    throw new \RuntimeException("noneDB: could not rename the directory of database '{$t['name']}'; run migrateKey() again");
                }
                $this->recovery->fault('rotate:renamed', ['db' => $t['name']]); // @test
            }
            $journal['targets'][$i]['done'] = true;
            // Verified before the commit: FORMAT, CURRENT and its generation belong to this database
            $format = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($to . '/FORMAT'), 'database');
            $current = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($to . '/CURRENT'), 'current');
            $metadata = $current === null || !is_int($current['generation'] ?? null) ? null
                : \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($to . '/generations/' . $current['generation'] . '/metadata'), 'generation');
            if($format === null || ($format['databaseId'] ?? null) !== $t['databaseId'] || ($current['databaseId'] ?? null) !== $t['databaseId']
                || ($metadata['databaseId'] ?? null) !== $t['databaseId']){
                $this->undoRotationRenames($journal);
                throw new \RuntimeException("noneDB: database '{$t['name']}' failed verification during the key rotation; nothing was changed", \noneDB::FORMAT_DAMAGED);
            }
        }
        // The renames are durable before the commit (DURABILITY.md §3)
        $this->sync->requireSync(null, $this->dbDir, 'dir');
        $journal['state'] = 'COMMITTED';
        $this->writeJournal($journal);
        $this->recovery->fault('rotate:committed', []); // @test
        $store = $this->applyCommittedJournal($journal);
        return ['databases' => array_column($journal['targets'], 'name'), 'storeManifest' => $store];
    }

    /**
     * Move every renamed directory of an uncommitted rotation back (abort, failed verification), then clear
     * "m" and close the journal. A rename that fails keeps the journal open: the store stays closed until
     * a re-run with ['abort' => true] finishes it (§9.3).
     */
    private function undoRotationRenames(array $journal){
        foreach(array_reverse($journal['targets']) as $t){
            $from = $this->dbDir . $t['from'] . '.ndb4';
            $to = $this->dbDir . $t['to'] . '.ndb4';
            clearstatcache(true, $from);
            clearstatcache(true, $to);
            if(is_dir($to) && !file_exists($from) && !$this->sync->renameWithRetry($to, $from)){
                throw new \RuntimeException("noneDB: could not move database '{$t['name']}' back; the key rotation stays open: run migrateKey() with ['abort' => true] again", \noneDB::MAINTENANCE_IN_PROGRESS);
            }
        }
        $this->sync->requireSync(null, $this->dbDir, 'dir');
        $this->clearMaintenanceMarks(array_column($journal['targets'], 'from'));
        $this->closeJournal();
    }

    private function addStoreKey($keyCheck){
        $this->maintenanceDepth++;
        try {
            return $this->store->withStoreLock(LOCK_EX, function() use ($keyCheck){
                $journal = $this->readJournal();
                if($journal !== null){
                    if($journal['op'] !== 'register' || $journal['state'] !== 'COMMITTED'){
                        throw new \RuntimeException("noneDB: another maintenance ({$journal['op']}) of the store in {$this->dbDir} is unfinished", \noneDB::MAINTENANCE_IN_PROGRESS);
                    }
                    return ['storeManifest' => $this->applyCommittedJournal($journal)];
                }
                $store = $this->store->loadStoreRecord();
                if($store === null || !in_array($this->store->keyCheck(), $store['keys'], true)){
                    throw new \RuntimeException("noneDB: the registered key is not registered for the store in {$this->dbDir}", \noneDB::KEY_NOT_REGISTERED);
                }
                if(in_array($keyCheck, $store['keys'], true)){
                    return ['storeManifest' => $store];
                }
                $target = $store;
                $target['keys'][] = $keyCheck;
                sort($target['keys'], SORT_STRING);
                $target['seq'] = $store['seq'] + 1;
                $journal = ['op' => 'register', 'id' => bin2hex(random_bytes(8)), 'state' => 'COMMITTED', 'store' => $target, 'clear' => []];
                $this->writeJournal($journal);
                $this->recovery->fault('register:committed', []); // @test
                return ['storeManifest' => $this->applyCommittedJournal($journal)];
            }, true);
        } finally {
            $this->maintenanceDepth--;
        }
    }

    private function rebuildStoreRecord(array $keys, array $options){
        $this->maintenanceDepth++;
        try {
            return $this->store->withStoreLock(LOCK_EX, function() use ($keys, $options){
                $journal = $this->readJournal();
                if($journal !== null){
                    if($journal['op'] !== 'repair-store' || $journal['state'] !== 'COMMITTED'){
                        throw new \RuntimeException("noneDB: another maintenance ({$journal['op']}) of the store in {$this->dbDir} is unfinished", \noneDB::MAINTENANCE_IN_PROGRESS);
                    }
                    $store = $this->applyCommittedJournal($journal);
                    return ['mode' => $journal['mode'], 'complete' => $journal['mode'] === 'manifest', 'storeManifest' => $store];
                }
                [, $copy1] = $this->store->readStoreCopy('STORE');
                [, $copy2] = $this->store->readStoreCopy('STORE.2');
                if($copy1 !== null || $copy2 !== null){
                    throw new \RuntimeException("noneDB: a valid store record exists in {$this->dbDir}; it is repaired automatically, repairStore() is only for two invalid copies", \noneDB::FORMAT_DAMAGED);
                }
                // Ownership: every database directory must belong to one of the keys
                $unattributed = [];
                foreach(@scandir($this->dbDir) ?: [] as $entry){
                    if(preg_match('/^([0-9a-f]{32})\.ndb4$/', $entry, $m) !== 1 || is_link($this->dbDir . $entry)){
                        continue;
                    }
                    $format = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbDir . $entry . '/FORMAT'), 'database');
                    $owned = false;
                    foreach($keys as $key){
                        if(is_array($format) && is_string($format['name'] ?? null) && \noneDB\Internal\Storage\StoreRecord::locatorOf($format['name'], $key) === $m[1]){
                            $owned = true;
                            break;
                        }
                    }
                    if(!$owned){
                        $unattributed[] = $entry;
                    }
                }
                if(!empty($unattributed)){
                    throw new \RuntimeException("noneDB: these databases belong to none of the given keys: " . implode(', ', $unattributed) . "; give every key of the store", \noneDB::FORMAT_DAMAGED);
                }
                $keyChecks = array_map([\noneDB\Internal\Storage\StoreRecord::class, 'keyCheckOf'], $keys);
                if(isset($options['manifest'])){
                    // A path (store.json, a saved STORE copy) or the storeManifest array of a maintenance result;
                    // either way its checksum must verify
                    $source = $options['manifest'];
                    $raw = is_array($source) ? json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n"
                        : (is_string($source) ? @file_get_contents($source) : false);
                    $manifest = \noneDB\Internal\Storage\StoreRecord::openRecord($raw, 'store');
                    if($manifest === null || ($manifest['storageFormat'] ?? null) !== \noneDB::STORAGE_FORMAT || !is_array($manifest['keys'] ?? null)
                        || !is_int($manifest['seq'] ?? null) || !is_string($manifest['storeId'] ?? null)){
                        throw new \RuntimeException('noneDB: the store manifest' . (is_string($source) ? " {$source}" : '') . ' is not a valid store record', \noneDB::FORMAT_DAMAGED);
                    }
                    $mode = 'manifest';
                    $store = $manifest;
                    $store['keys'] = array_values(array_unique(array_merge($manifest['keys'], $keyChecks)));
                    $store['seq'] = (int)$manifest['seq'] + 1;
                } elseif(!empty($options['confirmManualRebuild'])){
                    $mode = 'manual';
                    $store = ['magic' => 'NONEDB4', 'kind' => 'store', 'storageFormat' => \noneDB::STORAGE_FORMAT, 'storeId' => \noneDB\Internal\Storage\StoreRecord::uuid4(),
                        'seq' => 1, 'keys' => array_values(array_unique($keyChecks))];
                } else {
                    throw new \RuntimeException("noneDB: without a verified store manifest the record can only be rebuilt by hand: pass ['confirmManualRebuild' => true] (keys without databases that were registered are not restored)", \noneDB::FORMAT_DAMAGED);
                }
                sort($store['keys'], SORT_STRING);
                unset($store['checksum']);
                $journal = ['op' => 'repair-store', 'id' => bin2hex(random_bytes(8)), 'state' => 'COMMITTED', 'mode' => $mode, 'store' => $store, 'clear' => []];
                $this->writeJournal($journal);
                $this->recovery->fault('repair:committed', []); // @test
                $store = $this->applyCommittedJournal($journal);
                $this->store->logStoreEvent(['action' => $mode === 'manual' ? 'manual-store-rebuild' : 'store-restored-from-manifest', 'keys' => count($store['keys'])]);
                return ['mode' => $mode, 'complete' => $mode === 'manifest', 'storeManifest' => $store];
            }, true);
        } finally {
            $this->maintenanceDepth--;
        }
    }
}
