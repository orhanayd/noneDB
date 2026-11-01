<?php
// The store record (STORE, STORE.2, STORE.LOCK) and its rules (docs/STORAGE_FORMAT.md §3)
namespace noneDB\Internal\Storage;

use function array_is_list, array_slice, array_unique, array_values, bin2hex, chr, clearstatcache, count,
    dirname, fclose, fflush, file_get_contents, file_put_contents, flock, fopen, fwrite, hash, hash_equals,
    hash_hmac, implode, in_array, is_array, is_dir, is_file, is_int, is_link, is_string, json_decode, json_encode,
    ord, preg_match, random_bytes, scandir, sort, strlen, substr, time, unlink;
use const FILE_APPEND, JSON_PRESERVE_ZERO_FRACTION, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, LOCK_EX,
    LOCK_NB, LOCK_SH, LOCK_UN, PREG_OFFSET_CAPTURE, SORT_STRING;

/**
 * @internal
 */
final class StoreRecord {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $dbDir;
    private $hashCache;
    private $directoryListings; // @test
    private $maintenanceDepth;
    private $metrics;
    private $noWrites;
    private $storeLocks;
    private $storeState;
    private $cache;
    private $config;
    private $io;
    private $locks;
    private $recovery;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->config, $this->io, $this->locks, $this->recovery, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dbDir = &$this->k->dbDir;
        $this->hashCache = &$this->k->hashCache;
        $this->directoryListings = &$this->rt->directoryListings; // @test
        $this->maintenanceDepth = &$this->rt->maintenanceDepth;
        $this->metrics = &$this->rt->metrics;
        $this->noWrites = &$this->rt->noWrites;
        $this->storeLocks = &$this->rt->storeLocks;
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
        $this->cache = null;
        $this->config = null;
        $this->io = null;
        $this->locks = null;
        $this->recovery = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * keyCheck of the configured key: identifies it in STORE without revealing it
     */
    public function keyCheck(){
        return substr(hash_hmac('sha256', 'nonedb4/key', (string)$this->config->getSecretKey()), 0, 32);
    }

    public static function keyCheckOf($key){
        return substr(hash_hmac('sha256', 'nonedb4/key', (string)$key), 0, 32);
    }

    /**
     * Directory name of a database for the configured key (stable, unpredictable without the key)
     */
    public function locator($dbname){
        $cacheKey = $this->cache->getKeyFingerprint() . '|' . $dbname;
        return $this->hashCache[$cacheKey] ?? ($this->hashCache[$cacheKey] = self::locatorOf($dbname, (string)$this->config->getSecretKey()));
    }

    public static function locatorOf($dbname, $key){
        return substr(hash_hmac('sha256', 'nonedb4/db/' . $dbname, (string)$key), 0, 32);
    }

    /**
     * Encode a metadata record with its checksum (§2)
     */
    public static function sealRecord(array $record){
        unset($record['checksum']);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        $json = json_encode($record, $flags);
        if($json === false){
            throw new \RuntimeException('noneDB: cannot encode a metadata record');
        }
        $record['checksum'] = hash('sha256', $json . "\n");
        return json_encode($record, $flags) . "\n";
    }

    /**
     * Decode and verify a metadata record: null when it is not a valid record of $kind. The checksum is
     * checked on the bytes as written (the record without its last member "checksum"), not on a
     * re-encoding, which could differ (an empty object decodes to an empty array).
     */
    public static function openRecord($raw, $kind){
        if(!is_string($raw) || $raw === '' || substr($raw, -1) !== "\n"
            || preg_match('/,"checksum":"([0-9a-f]{64})"\}\n\z/', $raw, $m, PREG_OFFSET_CAPTURE) !== 1){
            return null;
        }
        $body = substr($raw, 0, $m[0][1]) . "}\n";
        if(!hash_equals(hash('sha256', $body), $m[1][0])){
            return null;
        }
        $record = json_decode(substr($raw, 0, -1), true);
        if(!is_array($record) || ($record['magic'] ?? null) !== 'NONEDB4' || ($record['kind'] ?? null) !== $kind){
            return null;
        }
        unset($record['checksum']);
        return $record;
    }

    /**
     * One STORE copy: [exists, record or null, raw bytes or null]
     */
    public function readStoreCopy($name){
        $path = $this->dbDir . $name;
        $raw = @file_get_contents($path);
        if($raw === false){
            clearstatcache(true, $path);
            return [is_file($path), null, null];
        }
        return [true, $this->validStoreRecord($raw), $raw];
    }

    private function validStoreRecord($raw){
        $record = self::openRecord($raw, 'store');
        $valid = $record !== null && ($record['storageFormat'] ?? null) === \noneDB::STORAGE_FORMAT
            && is_string($record['storeId'] ?? null) && is_int($record['seq'] ?? null) && $record['seq'] >= 1
            && is_array($record['keys'] ?? null) && array_is_list($record['keys']);
        if($valid){
            $keys = $record['keys'];
            foreach($keys as $keyCheck){
                if(!is_string($keyCheck) || preg_match('/^[0-9a-f]{32}$/', $keyCheck) !== 1){
                    $valid = false;
                }
            }
            $sorted = array_values(array_unique($keys));
            sort($sorted, SORT_STRING);
            $valid = $valid && $sorted === $keys;
        }
        return $valid ? $record : null;
    }

    /**
     * The store record by §3.1, null when there is no store. One valid copy repairs the other (under
     * STORE.LOCK; not while this process holds it shared: the next open repairs it); two valid copies that
     * differ, or none valid, are FORMAT_DAMAGED.
     */
    public function loadStoreRecord(){
        [$exists1, $copy1, $raw1] = $this->readStoreCopy('STORE');
        $raw2 = $raw1 === null ? false : @file_get_contents($this->dbDir . 'STORE.2');
        if($raw1 !== null && $raw2 === $raw1){
            $copy2 = $copy1; // the same bytes: one check
            $exists2 = true;
        } else {
            [$exists2, $copy2] = $raw2 === false ? $this->readStoreCopy('STORE.2') : [true, $this->validStoreRecord($raw2)];
        }
        if(!$exists1 && !$exists2){
            return null;
        }
        if($copy1 !== null && $copy2 !== null){
            if($copy1 === $copy2){
                return $copy1;
            }
            throw new \RuntimeException("noneDB: the store record in {$this->dbDir} is damaged (its two copies differ)", \noneDB::FORMAT_DAMAGED);
        }
        $valid = $copy1 ?? $copy2;
        if($valid === null){
            throw new \RuntimeException("noneDB: the store record in {$this->dbDir} is damaged; see repairStore()", \noneDB::FORMAT_DAMAGED);
        }
        $held = $this->storeLocks[$this->dbDir]['mode'] ?? null;
        if($this->noWrites === 0 && $held !== LOCK_SH){
            // The copies are identical outside maintenance (§3.1): the valid one restores the other
            $this->withStoreLock(LOCK_EX, function() use ($valid){
                [, $again1] = $this->readStoreCopy('STORE');
                [, $again2] = $this->readStoreCopy('STORE.2');
                if($again1 === null || $again2 === null){
                    $this->writeStoreCopies($valid);
                    $this->logStoreEvent(['action' => 'store-copy-repaired', 'seq' => $valid['seq']]);
                }
            });
        }
        return $valid;
    }

    /**
     * Write both STORE copies (STORE.2 first) by atomic replacement; caller holds STORE.LOCK exclusively
     */
    public function writeStoreCopies(array $record){
        $content = self::sealRecord($record);
        foreach(['STORE.2', 'STORE'] as $name){
            $this->recovery->fault('store:write', ['file' => $name]); // @test
            if(!$this->writeStoreFile($this->dbDir . $name, $content)){
                throw new \RuntimeException("noneDB: cannot write the store record {$name} in {$this->dbDir}");
            }
        }
    }

    /**
     * Atomic replacement of a store-level file (STORE copies, journal): temp + rename, synced in durable
     * mode (temp file, then the directory after the rename: the commit point of the file).
     * Store files belong to no database: STORE.LOCK, not a database lock, protects them.
     * @return bool False when the file could not be written (nothing changed)
     * @throws \RuntimeException DURABILITY_FAILED (temp file sync, nothing changed), OUTCOME_UNKNOWN
     *                           (directory sync after the rename)
     */
    public function writeStoreFile($path, $content){
        $this->recovery->removeStaleTemp($path); // a crashed replacement's temp (§2): STORE.LOCK is held exclusively
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        $fp = @fopen($tmp, 'xb');
        if($fp === false){
            return false;
        }
        $this->io->setPermissions($tmp);
        $ok = fwrite($fp, $content) === strlen($content) && fflush($fp);
        $synced = $ok && $this->sync->trySync($fp, $tmp);
        fclose($fp);
        if(!$ok || !$synced){
            @unlink($tmp);
            if($ok){
                throw $this->sync->failure('file', $path, false);
            }
            return false;
        }
        $this->recovery->fault('store:renaming', ['file' => basename($path)]); // @test
        if(!$this->sync->renameWithRetry($tmp, $path)){
            @unlink($tmp);
            return false;
        }
        clearstatcache(true, $path);
        $this->sync->requireSync(null, dirname($path), 'dir', true);
        return true;
    }

    /**
     * Store-level recovery events (STORE copy repairs, manual rebuilds): .nonedb-maintenance/log.jsonl
     */
    public function logStoreEvent(array $event){
        $dir = $this->dbDir . '.nonedb-maintenance';
        if(!is_dir($dir) && !$this->io->makeDirectory($dir)){
            return;
        }
        $created = !is_file($dir . '/log.jsonl');
        if(@file_put_contents($dir . '/log.jsonl', json_encode(['t' => time()] + $event, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) !== false){
            if($created){
                $this->io->setPermissions($dir . '/log.jsonl');
            }
            $this->metrics['recoveryEvents']++;
            $this->metrics['recoveryActions'][$event['action']] = ($this->metrics['recoveryActions'][$event['action']] ?? 0) + 1;
        }
    }

    /**
     * Run $fn holding STORE.LOCK (re-entrant within the process; a shared hold is never upgraded)
     */
    public function withStoreLock($mode, callable $fn, $nonBlocking = false){
        $dir = $this->dbDir;
        if(isset($this->storeLocks[$dir])){
            if($mode === LOCK_EX && $this->storeLocks[$dir]['mode'] !== LOCK_EX){
                throw new \LogicException('noneDB: STORE.LOCK is held shared and cannot be upgraded');
            }
            $this->storeLocks[$dir]['depth']++;
            try {
                return $fn();
            } finally {
                $this->storeLocks[$dir]['depth']--;
            }
        }
        if(!is_dir($dir) && !$this->io->makeDirectory($dir)){
            throw new \RuntimeException("noneDB: cannot create database directory {$dir}");
        }
        $created = !is_file($dir . 'STORE.LOCK');
        $fp = @fopen($dir . 'STORE.LOCK', 'c+be');
        if($fp !== false && $created){
            $this->io->setPermissions($dir . 'STORE.LOCK');
        }
        if($fp === false){
            throw new \RuntimeException("noneDB: cannot open the store lock in {$dir}", \noneDB::LOCK_FAILED);
        }
        try {
            if($nonBlocking){
                if(!flock($fp, $mode | LOCK_NB)){
                    throw new \RuntimeException("noneDB: the store in {$dir} is in use", \noneDB::MAINTENANCE_BUSY);
                }
            } else {
                $this->locks->acquireFlock($fp, $mode, 'STORE');
            }
        } catch(\Throwable $e){
            fclose($fp);
            throw $e;
        }
        $this->storeLocks[$dir] = ['fp' => $fp, 'mode' => $mode, 'depth' => 1];
        try {
            return $fn();
        } finally {
            unset($this->storeLocks[$dir]);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Entries of dbDir that are not noneDB 4 objects (§3.4). $withStore: the allow-list of a store that
     * has a STORE record; without one only the files an empty location may hold are allowed (§3.2).
     * @return string[]
     */
    private function foreignEntries($withStore){
        $this->directoryListings++; // @test
        $foreign = [];
        foreach(@scandir($this->dbDir) ?: [] as $entry){
            if($entry === '.' || $entry === '..'){
                continue;
            }
            if(in_array($entry, ['.htaccess', 'index.html', '.gitkeep', '.gitignore', '.DS_Store', 'STORE.LOCK'], true)
                || preg_match('/^STORE(\.2)?\.tmp[0-9a-f]{8}$/', $entry) === 1){
                continue;
            }
            if($withStore && (in_array($entry, ['STORE', 'STORE.2', '.nonedb-maintenance', '.nonedb-retained'], true)
                || (preg_match('/^[0-9a-f]{32}\.ndb4$/', $entry) === 1 && is_dir($this->dbDir . $entry) && !is_link($this->dbDir . $entry)))){
                continue; // a database directory; a link or a file of that name is foreign (§3.4)
            }
            $foreign[] = $entry;
        }
        return $foreign;
    }

    /**
     * MAINTENANCE_IN_PROGRESS while a maintenance journal is open (except in the process running it)
     */
    public function checkMaintenanceJournal(){
        if($this->maintenanceDepth > 0){
            return;
        }
        $journal = $this->dbDir . '.nonedb-maintenance/journal.json';
        clearstatcache(true, $journal);
        if(is_file($journal)){
            throw new \RuntimeException("noneDB: maintenance of the store in {$this->dbDir} is in progress or was interrupted; run it again to finish", \noneDB::MAINTENANCE_IN_PROGRESS);
        }
    }

    /**
     * Open the store for this key (§3.2). $create: create the store when the location is empty.
     * @return bool false when there is no store (and $create is false): nothing exists yet
     */
    public function openStore($create){
        $dir = $this->dbDir;
        $keyCheck = $this->keyCheck();
        if(isset($this->storeState[$dir][$keyCheck])){
            return true;
        }
        $this->checkMaintenanceJournal();
        $record = $this->loadStoreRecord();
        if($record === null && !empty($this->foreignEntries(false))){
            // The entries may belong to a store another process created while this one looked: decided
            // again under STORE.LOCK, which every store creation holds
            $record = $this->storeRecordUnderExistingLock();
            if($record === null){
                throw $this->missingStoreError($this->foreignEntries(false));
            }
        }
        if($record === null){
            if(!$create || $this->noWrites > 0){
                return false;
            }
            $this->withStoreLock(LOCK_EX, function() use ($keyCheck, &$record){
                $record = $this->loadStoreRecord();
                if($record !== null){
                    return;
                }
                $foreign = $this->foreignEntries(false);
                if(!empty($foreign)){
                    throw $this->missingStoreError($foreign);
                }
                foreach(@scandir($this->dbDir) ?: [] as $entry){
                    if(preg_match('/^STORE(\.2)?\.tmp[0-9a-f]{8}$/', $entry) === 1){
                        @unlink($this->dbDir . $entry);
                    }
                }
                $record = ['magic' => 'NONEDB4', 'kind' => 'store', 'storageFormat' => \noneDB::STORAGE_FORMAT,
                    'storeId' => self::uuid4(), 'seq' => 1, 'keys' => [$keyCheck]];
                $this->recovery->fault('store:create', []); // @test
                $this->writeStoreCopies($record);
                foreach(['.htaccess' => "Require all denied\nDeny from all\n", 'index.html' => ''] as $name => $content){
                    if(@file_put_contents($this->dbDir . $name, $content) !== false){
                        $this->io->setPermissions($this->dbDir . $name);
                    }
                }
            });
        }
        if(!in_array($keyCheck, $record['keys'], true)){
            throw new \RuntimeException("noneDB: the configured secretKey is not registered for the store in {$dir}", \noneDB::KEY_NOT_REGISTERED);
        }
        $this->storeState[$dir][$keyCheck] = true;
        return true;
    }

    /**
     * The store record read under STORE.LOCK held shared, when STORE.LOCK exists (it is never created
     * here: a refused location stays unchanged); null without a store
     */
    private function storeRecordUnderExistingLock(){
        $dir = $this->dbDir;
        if(isset($this->storeLocks[$dir])){
            return $this->loadStoreRecord();
        }
        $fp = @fopen($dir . 'STORE.LOCK', 'rb');
        if($fp === false){
            return null;
        }
        try {
            $this->locks->acquireFlock($fp, LOCK_SH, 'STORE');
            $this->storeLocks[$dir] = ['fp' => $fp, 'mode' => LOCK_SH, 'depth' => 1];
            try {
                return $this->loadStoreRecord();
            } finally {
                unset($this->storeLocks[$dir]);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * The missing-database path (§3.3): run $fn holding STORE.LOCK shared after the store was re-read,
     * the key re-checked and dbDir listed for foreign entries. $fn decides with that view.
     */
    public function withStoreRevalidated($create, callable $fn){
        if(!$this->storeExists() && !$this->openStore($create)){
            return $fn(); // no store and nothing in dbDir: nothing exists
        }
        return $this->withStoreLock(LOCK_SH, function() use ($fn){
            // Checked again under the lock: a maintenance may have changed the store since it was opened
            $this->checkMaintenanceJournal();
            $record = $this->loadStoreRecord();
            if($record === null || !in_array($this->keyCheck(), $record['keys'], true)){
                unset($this->storeState[$this->dbDir]);
                throw new \RuntimeException("noneDB: the configured secretKey is not registered for the store in {$this->dbDir}", \noneDB::KEY_NOT_REGISTERED);
            }
            $foreign = $this->foreignEntries(true);
            if(!empty($foreign)){
                throw $this->unsupportedFormat($foreign);
            }
            return $fn();
        });
    }

    /**
     * No STORE copy but entries: noneDB 4 objects without their store record are a damaged store,
     * anything else is not a noneDB 4 store
     */
    private function missingStoreError(array $foreign){
        foreach($foreign as $entry){
            if(preg_match('/^[0-9a-f]{32}\.ndb4$/', $entry) === 1 || $entry === '.nonedb-maintenance' || $entry === 'STORE' || $entry === 'STORE.2'){
                return new \RuntimeException("noneDB: the store record in {$this->dbDir} is missing; see repairStore()", \noneDB::FORMAT_DAMAGED);
            }
        }
        return $this->unsupportedFormat($foreign);
    }

    /**
     * True when this process opened the store for its key (it exists and the key is registered)
     */
    public function storeExists(){
        return isset($this->storeState[$this->dbDir][$this->keyCheck()]);
    }

    private function unsupportedFormat(array $foreign){
        $shown = array_slice($foreign, 0, 5);
        return new \RuntimeException("noneDB: {$this->dbDir} contains files that are not noneDB 4 objects ("
            . implode(', ', $shown) . (count($foreign) > 5 ? ', ...' : '') . "): no database is created or answered as missing while they are there (docs/STORAGE_FORMAT.md §3.4)",
            \noneDB::UNSUPPORTED_FORMAT);
    }

    public static function uuid4(){
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
