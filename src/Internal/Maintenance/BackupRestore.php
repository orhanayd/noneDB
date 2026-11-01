<?php
// backup() and restore() of a database (docs/STORAGE_FORMAT.md §10)
namespace noneDB\Internal\Maintenance;

use function array_column, array_filter, array_flip, array_is_list, array_key_exists, array_keys, array_map,
    array_reverse, array_sum, array_unique, basename, bin2hex, chmod, count, ctype_digit, dirname, fclose, fflush,
    fgets, file_exists, file_get_contents, filesize, fopen, fread, fwrite, hash, hash_final, hash_init, hash_update,
    hash_update_stream, implode, in_array, intdiv, is_array, is_bool, is_dir, is_file, is_int, is_link, is_string,
    json_decode, json_encode, max, min, mkdir, preg_match, random_bytes, realpath, rmdir, rtrim, scandir, sort,
    str_replace, strcmp, stream_get_contents, strlen, strncmp, substr, time, trim, unlink, usort;
use const DIRECTORY_SEPARATOR, LOCK_SH, SORT_STRING;

/**
 * @internal
 */
final class BackupRestore {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    // The files of a backup (§10.1): those of a generation by §5.1, the recovery files by name
    private const SEGMENT = '(?:main|s(?:0|[1-9][0-9]*))';
    private const TOKEN = '(?:[a-z0-9_]{1,40}|[a-z0-9_]{0,24}-[0-9a-f]{12})';
    private const GENERATION_FILE = '#^(?:metadata|shards\.json|indexes\.json|segments/' . self::SEGMENT . '\.(?:jsonl|kix)'
        . '|indexes/' . self::SEGMENT . '\.' . self::TOKEN . '\.fx|indexes/' . self::TOKEN . '\.gfx'
        . '|spatial/' . self::SEGMENT . '\.' . self::TOKEN . '\.rx|spatial/' . self::TOKEN . '\.grx'
        . '|buffer/pending\.jsonl|buffer/flushing\.(?:0|[1-9][0-9]*)\.jsonl)$#';
    private const RECOVERY_FILE = '#^recovery/(?!\.\.?$)[A-Za-z0-9._-]+$#';
    private const COMPLETE_LINES = '#^generations/[0-9]+/(?:segments|buffer)/[^/]+\.jsonl$#';
    private const CHUNK = 1048576;

    private $dbDir;
    private $noWrites;
    private $buffer;
    private $cache;
    private $checkpoints;
    private $integrity;
    private $io;
    private $layout;
    private $locks;
    private $recovery;
    private $recoveryLog;
    private $scope;
    private $shardInfo;
    private $store;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->cache, $this->checkpoints, $this->integrity, $this->io, $this->layout, $this->locks, $this->recovery, $this->recoveryLog, $this->scope, $this->shardInfo, $this->store, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dbDir = &$this->k->dbDir;
        $this->noWrites = &$this->rt->noWrites;
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
        $this->integrity = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->recovery = null;
        $this->recoveryLog = null;
        $this->scope = null;
        $this->shardInfo = null;
        $this->store = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }

    // ---------------------------------------------------------------- backup (§10.2)

    /**
     * Copy a database, as it is at one moment, into $dir (STORAGE_FORMAT.md §10.2)
     * @return array ['database', 'dir', 'databaseId', 'generation', 'nextKey', 'records', 'bufferedRecords', 'files', 'bytes']
     * @throws \InvalidArgumentException $dir inside dbDir, or not an empty directory
     */
    public function backup($dbname, $dir){
        $dbname = $this->layout->normalizeDbName($dbname);
        $target = $this->backupTarget($dir);
        if(!$this->store->openStore(false)){
            throw new \RuntimeException("noneDB: database '{$dbname}' does not exist");
        }
        // STORE.LOCK, then the database's LOCK, both shared until the copy is complete: one moment of the
        // database and of the store record
        return $this->store->withStoreLock(LOCK_SH, function() use ($dbname, $target){
            $this->store->checkMaintenanceJournal();
            $store = $this->store->loadStoreRecord();
            if($store === null || !in_array($this->store->keyCheck(), $store['keys'], true)){
                throw new \RuntimeException("noneDB: the configured secretKey is not registered for the store in {$this->dbDir}", \noneDB::KEY_NOT_REGISTERED);
            }
            return $this->locks->withLock($dbname, false, function() use ($dbname, $target, $store){
                $this->scope->refuseInUncommittedWrite($dbname, 'backup');
                if(!$this->layout->dbPresent($dbname)){
                    throw new \RuntimeException("noneDB: database '{$dbname}' does not exist");
                }
                $this->noWrites++; // nothing of the database is written: no flush, no checkpoint
                try {
                    return $this->copyDatabase($dbname, $target, $store);
                } finally {
                    $this->noWrites--;
                }
            });
        });
    }

    /**
     * The backup directory resolved (§10.2 step 1): outside dbDir once links are resolved, absent or empty
     * @throws \InvalidArgumentException
     */
    private function backupTarget($dir){
        if(!is_string($dir) || trim($dir) === ''){
            throw new \InvalidArgumentException('noneDB: backup() needs a directory');
        }
        $resolved = \noneDB\Internal\Config\Configuration::canonicalPath($dir);
        $root = rtrim($this->dbDir, '/\\'); // canonical (Configuration::resolveDbDir())
        if($resolved === $root || strncmp($resolved . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0){
            throw new \InvalidArgumentException("noneDB: the backup directory {$dir} must lie outside dbDir");
        }
        if(file_exists($resolved) && (!is_dir($resolved) || count(scandir($resolved) ?: []) > 2)){
            throw new \InvalidArgumentException("noneDB: the backup directory {$dir} must not exist or be an empty directory");
        }
        return $resolved;
    }

    /**
     * The copy (§10.2 steps 3-5) under both locks; what it created is removed when it fails
     */
    private function copyDatabase($dbname, $target, array $store){
        $takenAt = time();
        $home = $this->layout->dbHome($dbname);
        $generation = $this->layout->generationNumber($dbname);
        $genDir = $this->layout->genDir($dbname);
        $format = $this->layout->readFormatRecord($dbname);
        $info = $this->shardInfo->getShardInfoUnlocked($dbname);
        if(!is_array($info)){
            throw new \RuntimeException("noneDB: the counters of database '{$dbname}' can't be read");
        }
        $sources = ['CURRENT' => $home . 'CURRENT', 'FORMAT' => $home . 'FORMAT'];
        foreach($this->filesUnder($genDir) as $file){
            if(preg_match(self::GENERATION_FILE, $file) === 1){
                $sources['generations/' . $generation . '/' . $file] = $genDir . $file;
            }
        }
        foreach($this->filesUnder($home . 'recovery/') as $file){
            if(preg_match(self::RECOVERY_FILE, 'recovery/' . $file) === 1 && preg_match('/tmp[0-9a-f]{8}$/', $file) !== 1){
                $sources['recovery/' . $file] = $home . 'recovery/' . $file;
            }
        }
        $buffered = 0;
        foreach($sources as $path => $source){
            if(preg_match('#^generations/[0-9]+/buffer/#', $path) === 1){
                $buffered += count($this->buffer->readBufferRecords($source));
            }
        }
        $created = [];
        try {
            $this->makeBackupDirectory($target, $created);
            $files = [];
            foreach($sources as $path => $source){
                // A data or buffer file up to its last "\n": an unterminated tail is never a record (§6.1)
                clearstatcache(true, $source);
                $length = preg_match(self::COMPLETE_LINES, $path) === 1 ? $this->checkpoints->lastNewlineIn($source, 0, (int)@filesize($source)) + 1
                    : (int)@filesize($source);
                $files[$path] = $this->writeBackupFile($target . '/' . $path, $this->chunks($source, $length, null, ''), $created);
                $this->recovery->fault('backup:copied', ['path' => $path]); // @test
            }
            $files['store.json'] = $this->writeBackupFile($target . '/store.json', [\noneDB\Internal\Storage\StoreRecord::sealRecord($store)], $created);
            $paths = array_keys($files);
            sort($paths, SORT_STRING);
            $entries = [];
            foreach($paths as $path){
                [$size, $sha256] = $files[$path];
                if(!$this->fileHolds($target . '/' . $path, $size, $sha256, '')){
                    throw new \RuntimeException("noneDB: the backup copy of {$path} in {$target} does not read back as it was written");
                }
                $entries[] = ['path' => $path, 'size' => $size, 'sha256' => $sha256];
            }
            $this->recovery->fault('backup:verified', []); // @test
            foreach(array_unique(array_map('dirname', array_map(function($path) use ($target){ return $target . '/' . $path; }, $paths))) as $directory){
                $this->sync->requireSync(null, $directory, 'dir');
            }
            $manifest = ['magic' => 'NONEDB4', 'kind' => 'backup', 'storageFormat' => \noneDB::STORAGE_FORMAT,
                'databaseId' => $format['databaseId'], 'name' => $dbname, 'generation' => $generation,
                'nextKey' => (int)$info['nextKey'], 'records' => (int)$info['totalRecords'], 'bufferedRecords' => $buffered,
                'takenAt' => $takenAt, 'files' => $entries];
            $tmp = $target . '/manifest.json.tmp' . bin2hex(random_bytes(4));
            $this->writeBackupFile($tmp, [\noneDB\Internal\Storage\StoreRecord::sealRecord($manifest)], $created);
            if(!$this->sync->renameWithRetry($tmp, $target . '/manifest.json')){
                throw new \RuntimeException("noneDB: cannot write the manifest of the backup in {$target}");
            }
            $created[] = $target . '/manifest.json';
            $this->sync->requireSync(null, $target, 'dir');
        } catch(\Throwable $e){
            $this->removeBackupFiles($created);
            throw $e;
        }
        return ['database' => $dbname, 'dir' => $target, 'databaseId' => $format['databaseId'], 'generation' => $generation,
            'nextKey' => $manifest['nextKey'], 'records' => $manifest['records'], 'bufferedRecords' => $buffered,
            'files' => count($entries), 'bytes' => array_sum(array_column($entries, 'size'))];
    }

    /**
     * Regular files under a database directory, relative, sorted; a symbolic link is an error
     * @return string[]
     */
    private function filesUnder($dir){
        $files = [];
        $walk = function($at, $prefix) use (&$walk, &$files){
            foreach(@scandir($at) ?: [] as $entry){
                if($entry === '.' || $entry === '..'){
                    continue;
                }
                if(is_link($at . $entry)){
                    throw new \RuntimeException("noneDB: {$at}{$entry} is a symbolic link; a database holds none");
                }
                if(is_dir($at . $entry)){
                    $walk($at . $entry . '/', $prefix . $entry . '/');
                } elseif(is_file($at . $entry)){
                    $files[] = $prefix . $entry;
                }
            }
        };
        $walk($dir, '');
        $walk = null; // the closure refers to itself: freed now, not by the garbage collector
        sort($files, SORT_STRING);
        return $files;
    }

    // The backup directory belongs to no database: its files are written here (0700 directories, 0600
    // files), synced in durable mode; what a failed backup created is removed

    /**
     * Create a directory of a backup and its missing parents (0700), recording the ones created
     */
    private function makeBackupDirectory($dir, array &$created){
        $missing = [];
        for($at = $dir; !is_dir($at); $at = dirname($at)){
            $missing[] = $at;
        }
        foreach(array_reverse($missing) as $new){
            if(!@mkdir($new, 0700) && !is_dir($new)){
                throw new \RuntimeException("noneDB: cannot create the backup directory {$new}");
            }
            $created[] = $new;
            $this->sync->requireSync(null, dirname($new), 'dir');
        }
        @chmod($dir, 0700);
    }

    /**
     * Write a new file of a backup from $chunks
     * @return array [size, SHA-256] of the bytes written
     */
    private function writeBackupFile($path, iterable $chunks, array &$created){
        if(!is_dir(dirname($path))){
            $this->makeBackupDirectory(dirname($path), $created);
        }
        $fp = @fopen($path, 'xb');
        if($fp === false){
            throw new \RuntimeException("noneDB: cannot create the backup file {$path}");
        }
        $created[] = $path;
        @chmod($path, 0600);
        try {
            $hash = hash_init('sha256');
            $size = 0;
            foreach($chunks as $chunk){
                if(fwrite($fp, $chunk) !== strlen($chunk)){
                    throw new \RuntimeException("noneDB: cannot write the backup file {$path}");
                }
                hash_update($hash, $chunk);
                $size += strlen($chunk);
            }
            if(!fflush($fp)){
                throw new \RuntimeException("noneDB: cannot write the backup file {$path}");
            }
            $this->sync->requireSync($fp, $path, 'file');
        } finally {
            fclose($fp);
        }
        return [$size, hash_final($hash)];
    }

    private function removeBackupFiles(array $created){
        foreach(array_reverse($created) as $path){
            if(is_dir($path) && !is_link($path)){
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    // ---------------------------------------------------------------- restore (§10.3)

    /**
     * Make a backup the content of a database, with one commit (STORAGE_FORMAT.md §10.3)
     * @param array $options ['force' => bool]
     * @return array ['database', 'generation', 'nextKey', 'records', 'bufferedRecords', 'target' => 'present'|'absent'|'damaged', 'foreign' => bool]
     * @throws \RuntimeException RESTORE_REFUSED (nothing changed)
     */
    public function restore($dir, $dbname, array $options){
        $dbname = $this->layout->normalizeDbName($dbname);
        \noneDB\Internal\Config\Configuration::checkNames($options, ['force'], 'option', ' of restore()');
        $force = array_key_exists('force', $options) && \noneDB\Internal\Config\Configuration::optionBool('force', $options['force']);
        $backup = $this->readBackup($dir);
        // A refusal is decided before anything is written: first under the read lock (a missing database is
        // answered without creating anything), then again under the write lock
        $this->locks->withLock($dbname, false, function() use ($dbname, $backup, $force){
            $this->restoreIdentity($dbname, $backup, $force);
        }, 'restore');
        return $this->locks->withLock($dbname, true, function() use ($dbname, $backup, $force){
            $this->scope->refuseInUncommittedWrite($dbname, 'restore');
            [$state, $identity, $foreign] = $this->restoreIdentity($dbname, $backup, $force);
            if($state === 'present'){
                $info = $this->shardInfo->getShardInfoUnlocked($dbname);
                $nextKey = is_array($info) ? (int)$info['nextKey'] : 0;
            } else {
                $nextKey = $state === 'absent' ? 0 : $this->damagedNextKey($dbname);
            }
            $prepare = function($dir, $generation) use ($dbname, $backup, $identity, &$nextKey){
                $nextKey = $this->prepareRestored($dbname, $dir, $generation, $backup, $identity, $nextKey);
            };
            if($state === 'present'){
                $generation = $this->layout->switchGeneration($dbname, $prepare);
            } elseif($state === 'absent'){
                $this->layout->createDatabaseFiles($dbname, $identity, $prepare);
                $generation = 1;
            } else {
                $generation = $this->layout->replaceDamagedGeneration($dbname, $identity, $prepare);
            }
            $this->cache->invalidateDbCaches($dbname);
            $manifest = $backup['manifest'];
            $this->recoveryLog->logRecoveryEvent($dbname, ['action' => $foreign ? 'foreign-restore' : 'restore', 'generation' => $generation,
                'databaseId' => $manifest['databaseId'], 'takenAt' => $manifest['takenAt'], 'records' => $manifest['records']]);
            return ['database' => $dbname, 'generation' => $generation, 'nextKey' => $nextKey, 'records' => $manifest['records'],
                'bufferedRecords' => $manifest['bufferedRecords'], 'target' => $state, 'foreign' => $foreign];
        }, 'restore');
    }

    /**
     * A valid backup (§10.1): ['dir', 'manifest', 'format', 'metadata']
     * @throws \RuntimeException RESTORE_REFUSED
     */
    private function readBackup($dir){
        $root = is_string($dir) && $dir !== '' ? realpath($dir) : false;
        $manifest = $root === false ? null : \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($root . '/manifest.json'), 'backup');
        if($manifest === null || !$this->validManifest($manifest)){
            throw $this->refused($dir, 'it has no valid manifest.json (an interrupted backup has none)');
        }
        $generation = $manifest['generation'];
        $previous = null;
        foreach($manifest['files'] as $entry){
            $path = $entry['path'];
            if(!$this->backupFile($path, $generation) || ($previous !== null && strcmp($previous, $path) >= 0)){
                throw $this->refused($dir, "its manifest lists {$path}, which is not a file of a backup in its place");
            }
            $previous = $path;
            $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if(realpath($file) !== $file || !$this->fileHolds($file, $entry['size'], $entry['sha256'], '')){
                throw $this->refused($dir, "{$path} does not match its manifest");
            }
        }
        $listed = array_flip(array_column($manifest['files'], 'path'));
        foreach(['FORMAT', 'CURRENT', 'store.json', "generations/{$generation}/metadata"] as $required){
            if(!isset($listed[$required])){
                throw $this->refused($dir, "it has no {$required}");
            }
        }
        $format = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($root . '/FORMAT'), 'database');
        $current = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($root . '/CURRENT'), 'current');
        $metadata = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents("{$root}/generations/{$generation}/metadata"), 'generation');
        if($format === null || !$this->layout->validIdentity($format, $manifest['name']) || $format['databaseId'] !== $manifest['databaseId']
            || $current === null || ($current['databaseId'] ?? null) !== $format['databaseId'] || ($current['generation'] ?? null) !== $generation
            || $metadata === null || ($metadata['generation'] ?? null) !== $generation || !$this->layout->validIdentity($metadata, $manifest['name'])
            || json_encode($this->layout->identityOf($metadata)) !== json_encode($this->layout->identityOf($format))
            || !in_array($metadata['layout'] ?? null, ['single', 'sharded'], true)){
            throw $this->refused($dir, 'its FORMAT, CURRENT and metadata do not agree with its manifest');
        }
        return ['dir' => $root, 'manifest' => $manifest, 'format' => $format, 'metadata' => $metadata];
    }

    private function validManifest(array $manifest){
        foreach(['generation' => 1, 'nextKey' => 0, 'records' => 0, 'bufferedRecords' => 0, 'takenAt' => 0] as $member => $least){
            if(!is_int($manifest[$member] ?? null) || $manifest[$member] < $least){
                return false;
            }
        }
        if(($manifest['storageFormat'] ?? null) !== \noneDB::STORAGE_FORMAT || !is_string($manifest['databaseId'] ?? null)
            || !is_string($manifest['name'] ?? null) || !is_array($manifest['files'] ?? null) || !array_is_list($manifest['files'])){
            return false;
        }
        foreach($manifest['files'] as $entry){
            if(!is_array($entry) || array_keys($entry) !== ['path', 'size', 'sha256'] || !is_string($entry['path'])
                || !is_int($entry['size']) || $entry['size'] < 0 || !is_string($entry['sha256']) || preg_match('/^[0-9a-f]{64}$/', $entry['sha256']) !== 1){
                return false;
            }
        }
        return true;
    }

    /**
     * A path a backup of generation $generation may hold (§10.1)
     */
    private function backupFile($path, $generation){
        $prefix = 'generations/' . $generation . '/';
        return in_array($path, ['FORMAT', 'CURRENT', 'store.json'], true) || preg_match(self::RECOVERY_FILE, $path) === 1
            || (strncmp($path, $prefix, strlen($prefix)) === 0 && preg_match(self::GENERATION_FILE, substr($path, strlen($prefix))) === 1);
    }

    private function refused($dir, $why){
        return new \RuntimeException('noneDB: the backup in ' . (is_string($dir) ? $dir : '(no directory)') . " can't be restored: {$why}", \noneDB::RESTORE_REFUSED);
    }

    /**
     * The identity a restore gives the database (§10.3 step 3), decided before anything is written
     * @return array [target state, identity, foreign]
     * @throws \RuntimeException RESTORE_REFUSED
     */
    private function restoreIdentity($dbname, array $backup, $force){
        $state = $this->layout->restoreTargetState($dbname);
        $theirs = $backup['format'];
        $format = $state === 'absent' ? null : $this->layout->readFormatRecord($dbname);
        if($format !== null){
            $foreign = $format['databaseId'] !== $theirs['databaseId'];
            $identity = $this->layout->identityOf($format);
        } else {
            $foreign = $theirs['name'] !== $dbname;
            $identity = $this->layout->identityOf($theirs);
            if($foreign){
                $identity['databaseId'] = \noneDB\Internal\Storage\StoreRecord::uuid4();
                $identity['name'] = $dbname;
                $identity['createdAt'] = time();
            }
        }
        if($foreign && !$force){
            throw new \RuntimeException("noneDB: the backup in {$backup['dir']} is of another database ('{$theirs['name']}'); "
                . "restore it with ['force' => true] to load it as database '{$dbname}'", \noneDB::RESTORE_REFUSED);
        }
        if($backup['metadata']['layout'] === 'sharded' && $identity['shardSize'] !== $theirs['shardSize']){
            throw new \RuntimeException("noneDB: the backup in {$backup['dir']} has shards of {$theirs['shardSize']} records, database "
                . "'{$dbname}' of {$identity['shardSize']}; restore it into a database of its own", \noneDB::RESTORE_REFUSED);
        }
        return [$state, $identity, $foreign];
    }

    /**
     * Next key of a damaged database: 1 + the highest key of any complete line in any of its segment files
     * (the keys it handed out are not known otherwise, §10.3 step 4.3)
     */
    private function damagedNextKey($dbname){
        $next = 0;
        $root = $this->layout->dbHome($dbname) . 'generations/';
        foreach(@scandir($root) ?: [] as $generation){
            if(!ctype_digit((string)$generation)){
                continue;
            }
            foreach(@scandir($root . $generation . '/segments') ?: [] as $file){
                if(preg_match('/^' . self::SEGMENT . '\.jsonl$/', $file) !== 1){
                    continue;
                }
                $fp = @fopen($root . $generation . '/segments/' . $file, 'rb');
                while($fp !== false && ($line = fgets($fp)) !== false){
                    if(substr($line, -1) === "\n" && ($entry = $this->checkpoints->classifyLine(substr($line, 0, -1), 0, null)) !== null){
                        $next = max($next, $entry[1] + 1);
                    }
                }
                if($fp !== false){
                    fclose($fp);
                }
            }
        }
        return $next;
    }

    /**
     * PREPARE of a restore (§10.3 step 4): generation $generation in $dir as the backup's copy with its
     * metadata, the next key kept, then verified with checkIntegrity() over it
     * @return int the next key of the restored database
     * @throws \RuntimeException RESTORE_REFUSED when the generation does not verify
     */
    private function prepareRestored($dbname, $dir, $generation, array $backup, array $identity, $nextKey){
        $manifest = $backup['manifest'];
        $layout = $backup['metadata']['layout'];
        $prefix = 'generations/' . $manifest['generation'] . '/';
        $top = max($nextKey, $manifest['nextKey']);
        // Keys the database handed out after the backup stay used: a top tombstone of the highest one
        $tombstone = '';
        $tombstoned = null;
        if($nextKey > $manifest['nextKey']){
            $tombstone = '{"__nonedb_deleted":' . ($nextKey - 1) . "}\n";
            $tombstoned = 'segments/' . ($layout === 'single' ? 'main' : 's' . intdiv($nextKey - 1, $identity['shardSize'])) . '.jsonl';
        }
        $copied = [];
        foreach($manifest['files'] as $entry){
            if(strncmp($entry['path'], $prefix, strlen($prefix)) !== 0 || $entry['path'] === $prefix . 'metadata'){
                continue;
            }
            $file = substr($entry['path'], strlen($prefix));
            $this->copyIntoGeneration($backup['dir'] . '/' . $entry['path'], $entry['size'], $entry['sha256'], $dir . $file, $file === $tombstoned ? $tombstone : '');
            $copied[$file] = true;
            $this->recovery->fault('restore:copied', ['path' => $file]); // @test
        }
        if($tombstoned !== null && !isset($copied[$tombstoned])){
            $this->copyIntoGeneration(null, 0, hash('sha256', ''), $dir . $tombstoned, $tombstone); // a new shard holding it
        }
        $this->layout->writeGenerationMetadata($dbname, $generation, $identity, $layout);
        if($layout === 'sharded' && $tombstoned !== null){
            $meta = json_decode((string)@file_get_contents($dir . 'shards.json'), true);
            if(!is_array($meta) || !is_array($meta['shards'] ?? null)){
                throw new \RuntimeException("noneDB: the shard list of the backup in {$backup['dir']} is unreadable", \noneDB::RESTORE_REFUSED);
            }
            $shard = intdiv($nextKey - 1, $identity['shardSize']);
            if(!in_array($shard, array_map('intval', array_column($meta['shards'], 'id')), true)){
                $meta['shards'][] = \noneDB\Internal\Sharding\ShardMeta::newShardEntry($shard);
                usort($meta['shards'], function($a, $b){ return (int)$a['id'] <=> (int)$b['id']; });
            }
            $meta['nextKey'] = $nextKey;
            $json = $this->io->encodeJson($meta);
            if($json === false || !$this->io->writeFileAtomic($dir . 'shards.json', $json . "\n", 'durable')){
                throw new \RuntimeException("noneDB: cannot write the shard list of the restored generation of database '{$dbname}'");
            }
        }
        [$report, $info] = $this->layout->withGenerationView($dbname, $generation, $identity, function() use ($dbname){
            return [$this->integrity->integrityReport($dbname), $this->shardInfo->getShardInfoUnlocked($dbname)];
        });
        $meta = array_filter($report['indexMismatches'], function($mismatch){ return $mismatch['index'] === 'meta'; });
        $restoredKey = is_array($info) ? (int)$info['nextKey'] : -1;
        if($report['records'] !== $manifest['records'] || $report['bufferedRecords'] !== $manifest['bufferedRecords'] || !empty($meta) || $restoredKey !== $top){
            throw new \RuntimeException("noneDB: the restored generation of database '{$dbname}' does not verify: {$report['records']} records of "
                . "{$manifest['records']}, {$report['bufferedRecords']} buffered of {$manifest['bufferedRecords']}, next key {$restoredKey} of {$top}"
                . (empty($meta) ? '' : ', the shard list does not fit the data') . '; nothing was restored', \noneDB::RESTORE_REFUSED);
        }
        $this->recovery->fault('restore:verified', ['db' => $dbname]); // @test
        return $top;
    }

    /**
     * Write a file of the restored generation from the backup's bytes (and $suffix) with the durability of
     * its kind (DURABILITY.md §3), then read it back
     */
    private function copyIntoGeneration($source, $size, $sha256, $target, $suffix){
        $file = basename(dirname($target)) . '/' . basename($target);
        $mode = preg_match('#(?:^|/)(?:segments|buffer)/[^/]+\.jsonl$#', $file) === 1 ? 'replace'
            : (in_array(basename($target), ['shards.json', 'indexes.json'], true) ? 'durable' : 'derived');
        if(!$this->io->writeFileAtomic($target, $this->chunks($source, $size, $sha256, $suffix), $mode)){
            throw new \RuntimeException("noneDB: cannot write {$file} of the restored generation");
        }
        if(!$this->fileHolds($target, $size, $sha256, $suffix)){
            throw new \RuntimeException("noneDB: {$file} of the restored generation does not read back as it was copied");
        }
    }

    // ---------------------------------------------------------------- bytes

    /**
     * The first $length bytes of $path in chunks (checked against $sha256 when one is given), then $suffix
     */
    private function chunks($path, $length, $sha256, $suffix){
        if($path !== null){
            $fp = @fopen($path, 'rb');
            if($fp === false){
                throw new \RuntimeException("noneDB: cannot read {$path}");
            }
            try {
                $hash = hash_init('sha256');
                for($left = $length; $left > 0; $left -= strlen($chunk)){
                    $chunk = (string)fread($fp, min(self::CHUNK, $left));
                    if($chunk === ''){
                        throw new \RuntimeException("noneDB: {$path} changed while it was copied");
                    }
                    hash_update($hash, $chunk);
                    yield $chunk;
                }
                if($sha256 !== null && hash_final($hash) !== $sha256){
                    throw new \RuntimeException("noneDB: {$path} changed while it was copied", \noneDB::RESTORE_REFUSED);
                }
            } finally {
                fclose($fp);
            }
        }
        if($suffix !== ''){
            yield $suffix;
        }
    }

    /**
     * A regular file of $size bytes with SHA-256 $sha256, followed by $suffix exactly
     */
    private function fileHolds($path, $size, $sha256, $suffix){
        clearstatcache(true, $path);
        if(!is_file($path) || is_link($path) || filesize($path) !== $size + strlen($suffix)){
            return false;
        }
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return false;
        }
        $hash = hash_init('sha256');
        $read = $size > 0 ? hash_update_stream($hash, $fp, $size) : 0;
        $rest = (string)stream_get_contents($fp);
        fclose($fp);
        return $read === $size && hash_final($hash) === $sha256 && $rest === $suffix;
    }
}
