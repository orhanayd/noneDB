<?php
// The write buffer: buffered inserts and their flush
namespace noneDB\Internal\Storage;

use function basename, clearstatcache, count, dirname, fclose, fgets, file_exists, filesize, fopen, is_array,
    json_decode, max, preg_match, register_shutdown_function, strlen, substr, time, trim;

/**
 * @internal
 */
final class Buffer {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $autoCreateDB;
    private $bufferAutoFlushOnShutdown;
    private $bufferCountLimit;
    private $bufferFlushInterval;
    private $bufferLastFlush;
    private $bufferSizeLimit;
    private $shutdownHandlerRegistered;
    private $bufferCounts;
    private $bufferEnds;
    private $flushingNow;
    private $lockRegistry;
    private $checkpoints;
    private $db;
    private $io;
    private $layout;
    private $locks;
    private $recovery;
    private $recoveryLog;
    private $scope;
    private $segments;
    private $shardMeta;
    private $sync;
    private $writer;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->checkpoints, $this->db, $this->io, $this->layout, $this->locks, $this->recovery, $this->recoveryLog, $this->scope, $this->segments, $this->shardMeta, $this->sync, $this->writer);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->autoCreateDB = &$this->k->autoCreateDB;
        $this->bufferAutoFlushOnShutdown = &$this->k->bufferAutoFlushOnShutdown;
        $this->bufferCountLimit = &$this->k->bufferCountLimit;
        $this->bufferFlushInterval = &$this->k->bufferFlushInterval;
        $this->bufferLastFlush = &$this->k->bufferLastFlush;
        $this->bufferSizeLimit = &$this->k->bufferSizeLimit;
        $this->shutdownHandlerRegistered = &$this->k->shutdownHandlerRegistered;
        $this->bufferCounts = &$this->rt->bufferCounts;
        $this->bufferEnds = &$this->rt->bufferEnds;
        $this->flushingNow = &$this->rt->flushingNow;
        $this->lockRegistry = &$this->rt->lockRegistry;
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
        $this->db = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->recovery = null;
        $this->recoveryLog = null;
        $this->scope = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->sync = null;
        $this->writer = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // WRITE BUFFER
    // ==========================================
    // Optional (off by default). Buffered records have no key yet; flushing renames the buffer
    // (buffer/pending.jsonl of the active generation) to buffer/flushing.{firstKey}.jsonl first and then
    // inserts its records through insertRecords(), so the normal indexes and sharding logic apply.
    // Pending buffers are flushed before every read and write, whether or not buffering is enabled.

    /**
     * Get buffer file path of a database
     * @param string $dbname
     * @return string
     */
    public function getBufferPath($dbname){
        return $this->layout->genPath($dbname, 'buffer/pending.jsonl');
    }

    /**
     * Check if buffer exists and has content
     * @param string $bufferPath
     * @return bool
     */
    public function hasBuffer($bufferPath){
        clearstatcache(true, $bufferPath);
        return file_exists($bufferPath) && filesize($bufferPath) > 0;
    }

    /**
     * Number of records in a buffer file; counted once, then tracked by size
     */
    public function getBufferRecordCount($bufferPath){
        clearstatcache(true, $bufferPath);
        if(!file_exists($bufferPath)){
            return 0;
        }
        $size = filesize($bufferPath);
        if(isset($this->bufferCounts[$bufferPath]) && $this->bufferCounts[$bufferPath][0] === $size){
            return $this->bufferCounts[$bufferPath][1];
        }
        $count = count($this->readBufferRecords($bufferPath));
        $this->bufferCounts[$bufferPath] = [$size, $count];
        return $count;
    }

    /**
     * Read all records from a buffer file (JSONL). An unterminated last line is not a record (its
     * append never completed).
     * @param string $bufferPath
     * @param bool $clean Set to false when the file holds anything that isn't a complete record line
     * @return array
     */
    public function readBufferRecords($bufferPath, &$clean = true){
        $clean = true;
        $records = [];
        $fp = @fopen($bufferPath, 'rb');
        if($fp === false){
            return $records;
        }
        while(($line = fgets($fp)) !== false){
            if(substr($line, -1) !== "\n"){
                $clean = false;
                break;
            }
            $line = trim($line);
            if($line === ''){
                continue;
            }
            $record = json_decode($line, true);
            if(is_array($record)){
                $records[] = $record;
            } else {
                $clean = false;
            }
        }
        fclose($fp);
        return $records;
    }

    /**
     * Before appending to a buffer: a torn last line (crashed append) goes to quarantine and is cut off,
     * so the next record isn't glued to it. Skipped when the file has the size this process wrote last.
     */
    private function repairBufferTail($dbname, $bufferPath){
        clearstatcache(true, $bufferPath);
        $size = @filesize($bufferPath);
        if(!$size || ($this->bufferEnds[$bufferPath] ?? null) === $size){
            return;
        }
        $last = $this->checkpoints->lastNewlineIn($bufferPath, max(0, $size - 65536), $size);
        if($last !== $size - 1){
            if($last < 0){
                $last = $this->checkpoints->lastNewlineIn($bufferPath, 0, $size);
            }
            $this->recovery->quarantineAndCut($dbname, 'b', $bufferPath, $last + 1, $size);
            unset($this->bufferCounts[$bufferPath]);
        }
    }

    /**
     * Before a flushed buffer file is deleted: anything in it that wasn't a record is kept in quarantine
     */
    private function quarantineBufferRest($dbname, $file){
        clearstatcache(true, $file);
        $size = (int)@filesize($file);
        if($size > 0){
            $quarantine = $this->recovery->quarantineBytes($dbname, 'b', $file, 0, $size);
            $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'drop-buffer', 'id' => $quarantine['id'], 'file' => basename($file),
                'quarantine' => $quarantine['file'], 'bytesVerified' => true, 'powerLossDurable' => $quarantine['durable']]);
        }
    }

    /**
     * True if the database has buffered records waiting
     */
    private function hasPendingBuffers($dbname){
        // Under the lock the state record says it (flag 'b', set before a buffer gets records and
        // cleared after they are flushed), so normal operations don't touch the file system for this
        $path = $this->locks->getLockPath($dbname);
        if(isset($this->lockRegistry[$path]) && $this->lockRegistry[$path]['epoch'] !== null){
            return !empty($this->lockRegistry[$path]['flags']['b']);
        }
        return $this->hasBuffer($this->getBufferPath($dbname)) || !empty($this->flushingFiles($dbname));
    }

    /**
     * Buffer files whose flush was interrupted: buffer/flushing.{firstKey}.jsonl
     */
    public function flushingFiles($dbname){
        return $this->segments->listFilesWithPrefix($this->layout->genDir($dbname) . 'buffer/flushing.', '/^\d+\.jsonl$/');
    }

    /**
     * Flush buffered records into the database (exclusive lock)
     * @return int Number of records flushed
     */
    public function flushPendingBuffers($dbname){
        $base = $this->layout->dbHome($dbname);
        // Flushing inserts records, which can switch the database to shards, which flushes again: don't re-enter
        if(isset($this->flushingNow[$base]) || !$this->hasPendingBuffers($dbname)){
            return 0;
        }
        return (int)$this->locks->withLock($dbname, true, function() use ($dbname, $base){
            // In place before the caller appended anything, else after the caller's write committed
            return $this->scope->runIndependent($dbname, 'flush', function() use ($dbname, $base){
                if(isset($this->flushingNow[$base]) || !$this->hasPendingBuffers($dbname)){
                    return 0;
                }
                $this->flushingNow[$base] = true;
                try {
                    return $this->flushPendingBuffersLocked($dbname);
                } finally {
                    unset($this->flushingNow[$base]);
                }
            });
        }, 'flush');
    }

    private function flushPendingBuffersLocked($dbname){
        {
            $flushed = 0;
            $bufferPath = $this->getBufferPath($dbname);
            // Interrupted flushes first. A flush renames the buffer to flushing.{firstKey}.jsonl: the
            // rename records atomically which keys its records get, so replaying it is idempotent.
            foreach($this->flushingFiles($dbname) as $file){
                preg_match('/flushing\.(\d+)\.jsonl$/', $file, $m);
                $flushed += $this->flushBufferFile($dbname, $file, (int)$m[1]);
            }
            if($this->hasBuffer($bufferPath)){
                if(!$this->segments->dbExists($dbname)){
                    $this->db->createDBUnlocked($dbname);
                }
                $startKey = $this->nextKeyOf($dbname);
                $flushingPath = dirname($bufferPath) . '/flushing.' . $startKey . '.jsonl';
                if(!$this->sync->renameWithRetry($bufferPath, $flushingPath)){
                    throw new \RuntimeException("noneDB: could not flush the write buffer of database '{$dbname}'");
                }
                $this->bufferEnds[$bufferPath] = 0; // the buffer is gone: the next append creates it
                // The rename must survive a power loss before records get keys (else replayed twice)
                $this->sync->requireSync(null, dirname($bufferPath), 'dir');
                $flushed += $this->flushBufferFile($dbname, $flushingPath, $startKey);
            }
            unset($this->bufferCounts[$bufferPath]);
            $this->locks->setBufferFlag($dbname, false);
            $this->bufferLastFlush[$dbname] = time();
            return $flushed;
        }
    }

    /**
     * Insert the records of one buffer file, starting at $startKey
     * @return int
     */
    private function flushBufferFile($dbname, $file, $startKey){
        $records = $this->readBufferRecords($file, $clean);
        if(!empty($records)){
            $result = $this->writer->insertRecords($dbname, $records, $startKey);
            if(isset($result['error'])){
                throw new \RuntimeException("noneDB: flushing the write buffer of '{$dbname}' failed: " . $result['error']);
            }
        }
        $this->scope->commitIndependentStep($dbname); // the records are stored for good before their buffer goes
        if(!$clean){
            $this->quarantineBufferRest($dbname, $file);
        }
        $this->io->fsUnlink($file);
        return count($records);
    }

    /**
     * The key the next inserted record gets
     */
    private function nextKeyOf($dbname){
        if($this->segments->isSharded($dbname)){
            $meta = $this->shardMeta->getCachedMeta($dbname, true);
            return $meta === null ? 0 : (int)$meta['nextKey'];
        }
        $index = $this->checkpoints->writableSegmentIndex($dbname, null);
        return $index === null ? 0 : (int)$index['n'];
    }
    /**
     * Append validated records to the database's write buffer (exclusive lock)
     * @return array
     */
    public function insertBuffered($dbname, array $validItems){
        if(!$this->segments->dbExists($dbname)){
            if(!$this->autoCreateDB){
                return ["n" => 0, "error" => "Database does not exist"];
            }
            $this->db->createDBUnlocked($dbname);
        }
        $this->registerShutdownHandler();

        $lines = $this->io->withExactFloats(function() use ($validItems){
            $lines = '';
            foreach($validItems as $item){
                $lines .= $this->io->encodeRecord($item) . "\n";
            }
            return $lines;
        });

        $bufferPath = $this->getBufferPath($dbname);
        // One size for the flush limits, the torn tail and the count. The size this process's last append
        // left holds while no other process wrote the database: a write of another process moves the lock
        // generation, which drops this cache (CacheManager::invalidateDbCaches), so no stat per insert.
        $size = $this->bufferEnds[$bufferPath] ?? null;
        if($size === null){
            clearstatcache(true, $bufferPath);
            $size = (int)@filesize($bufferPath);
        }
        $known = isset($this->bufferCounts[$bufferPath]) && $this->bufferCounts[$bufferPath][0] === $size ? $this->bufferCounts[$bufferPath][1] : null;
        if($size > 0 && $this->shouldFlushBuffer($bufferPath, $dbname, $size, $known)){
            $this->flushPendingBuffers($dbname);
            clearstatcache(true, $bufferPath);
            $size = (int)@filesize($bufferPath);
        }
        if($size > 0 && ($this->bufferEnds[$bufferPath] ?? null) !== $size){
            $this->repairBufferTail($dbname, $bufferPath);
            clearstatcache(true, $bufferPath);
            $size = (int)@filesize($bufferPath);
        }
        $count = $size === 0 ? 0 : (isset($this->bufferCounts[$bufferPath]) && $this->bufferCounts[$bufferPath][0] === $size
            ? $this->bufferCounts[$bufferPath][1] : $this->getBufferRecordCount($bufferPath));
        $this->locks->setBufferFlag($dbname, true);
        if($this->io->appendToFile($bufferPath, $lines, $dbname) === false){
            throw new \RuntimeException("noneDB: failed to write the write buffer of database '{$dbname}'; nothing was written");
        }
        $size += strlen($lines); // the append ended there (checked against the file next time)
        $this->bufferCounts[$bufferPath] = [$size, $count + count($validItems)];
        $this->bufferEnds[$bufferPath] = $size;
        if(!isset($this->bufferLastFlush[$dbname])){
            $this->bufferLastFlush[$dbname] = time();
        }

        if($this->shouldFlushBuffer($bufferPath, $dbname, $size, $count + count($validItems))){
            $this->flushPendingBuffers($dbname);
        }
        return ["n" => count($validItems)];
    }

    /**
     * Check if buffer needs flushing (size, count, or time based)
     * @param string $bufferPath
     * @param string $flushKey Key for tracking last flush time
     * @return bool
     */
    private function shouldFlushBuffer($bufferPath, $flushKey, $size = null, $count = null){
        if($size === null){
            clearstatcache(true, $bufferPath);
            $size = file_exists($bufferPath) ? (int)filesize($bufferPath) : 0;
        }
        if($size <= 0){
            return false;
        }
        if($size >= $this->bufferSizeLimit){
            return true;
        }
        if(($count ?? $this->getBufferRecordCount($bufferPath)) >= $this->bufferCountLimit){
            return true;
        }
        if($this->bufferFlushInterval > 0){
            $lastFlush = $this->bufferLastFlush[$flushKey] ?? time();
            if((time() - $lastFlush) >= $this->bufferFlushInterval){
                return true;
            }
        }
        return false;
    }

    /**
     * Register shutdown handler for flushing all buffers
     */
    private function registerShutdownHandler(){
        if($this->shutdownHandlerRegistered){
            return;
        }
        if($this->bufferAutoFlushOnShutdown){
            // Weak reference: the handler must not keep the instance alive until shutdown
            $ref = \WeakReference::create($this->k);
            register_shutdown_function(static function() use ($ref){
                $db = $ref->get();
                if($db !== null){
                    try {
                        $db->maintenance->flushAllBuffers();
                    } catch(\Throwable $e){
                        // The records stay in the buffer (committed): the next access flushes them. An
                        // exception here would end the script with a fatal error after its work was done.
                    }
                }
            });
            $this->shutdownHandlerRegistered = true;
        }
    }
}
