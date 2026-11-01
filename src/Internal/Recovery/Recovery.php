<?php
// Crash recovery: journal recovery, quarantine of removed bytes, stale temp files, fault points
namespace noneDB\Internal\Recovery;

use function array_filter, array_keys, array_map, array_values, basename, bin2hex, clearstatcache, fclose,
    fflush, file_exists, filesize, fopen, fread, fseek, fwrite, hash_file, hash_final, hash_init, hash_update,
    in_array, is_dir, is_file, min, random_bytes, rename, rtrim, strlen, substr, unlink;

/**
 * @internal
 */
final class Recovery {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $durable;
    private $faultHook; // @test
    private $lockRegistry;
    private $noWrites;
    private $cache;
    private $checkpoints;
    private $io;
    private $layout;
    private $locks;
    private $recoveryLog;
    private $segments;
    private $shardMeta;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->io, $this->layout, $this->locks, $this->recoveryLog, $this->segments, $this->shardMeta, $this->sync);
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
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->recoveryLog = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }


    // @test-begin
    /**
     * Fault point of the test build: calls the hook a test installed (it may throw or exit)
     */
    public function fault($point, array $context = []){
        if($this->faultHook !== null){
            ($this->faultHook)($point, $context);
        }
    }
    // @test-end

    /**
     * Recovery of a database a writer left half-updated (journal j/J; caller holds the exclusive lock):
     * key indexes are checked against the data (field and spatial indexes follow by themselves), temp
     * files of interrupted atomic writes are removed and the shard counters recounted from the data
     */
    public function recoverPendingIndexes($dbname, $path){
        $pending = $this->lockRegistry[$path]['flags']['j'] ?? [];
        $known = $this->segments->listSegments($dbname);
        $segments = isset($pending['*']) ? $known : array_map(function($segment){
            return $segment === 'main' ? null : (int)substr($segment, 1);
        }, array_keys($pending));
        $segments = array_values(array_filter($segments, function($shardId) use ($known){
            return in_array($shardId, $known, true);
        }));
        foreach($segments as $shardId){
            $this->checkpoints->readJsonlIndex($dbname, $shardId);
            $this->cache->evictBetweenSegments();
        }
        $home = $this->layout->dbHome($dbname);
        $gen = $this->layout->genDir($dbname);
        foreach([$home, $home . 'recovery/', $gen, $gen . 'segments/', $gen . 'indexes/', $gen . 'spatial/', $gen . 'buffer/'] as $dir){
            $this->removeStaleTemp($dir);
        }
        if($this->segments->isSharded($dbname)){
            // A write may have died between its data and its counters
            $this->shardMeta->recountMeta($dbname);
        }
        unset($this->lockRegistry[$path]['flags']['j']);
        $this->locks->saveLockState($path);
    }

    /**
     * Remove bytes [$from, $to) at the end of a data or buffer file, keeping them in a quarantine file
     * first (exclusive lock). Fails closed: if the bytes can't be stored and verified, nothing is cut
     * and the write that needed the cut is refused.
     * @throws \RuntimeException
     */
    public function quarantineAndCut($dbname, $shardId, $path, $from, $to, $action = 'cut-tail'){
        $quarantine = $this->quarantineBytes($dbname, $shardId, $path, $from, $to);
        if(!$this->io->fsTruncate($path, $from)){
            throw new \RuntimeException("noneDB: could not cut the torn end of a file of database '{$dbname}'");
        }
        $this->recoveryLog->logRecoveryEvent($dbname, ['action' => $action, 'id' => $quarantine['id'], 'file' => basename($path),
            'quarantine' => $quarantine['file'], 'bytesVerified' => true, 'powerLossDurable' => $quarantine['durable']], $quarantine['id']);
        return $quarantine;
    }

    /**
     * Copy bytes [$from, $to) of a file into recovery/q.{segment}.{offset}.{length}.{sha256}.bin
     * (segment: main, s{N}, or buffer for a buffer file: $shardId 'b')
     * and verify the copy. An existing file with the same name is reused only if its bytes match.
     * @return array ['id' => string, 'file' => string, 'durable' => true|false|'unknown']
     * @throws \RuntimeException
     */
    public function quarantineBytes($dbname, $shardId, $path, $from, $to){
        $length = $to - $from;
        $seg = $shardId === 'b' ? 'buffer' : \noneDB\Internal\Storage\Layout::segmentName($shardId);
        $src = @fopen($path, 'rb');
        if($src === false){
            throw new \RuntimeException("noneDB: could not read bytes to quarantine in database '{$dbname}'");
        }
        $dir = $this->layout->dbHome($dbname) . 'recovery/';
        if(!is_dir($dir)){
            $this->io->fsMkdir($dir);
        }
        $tmp = $dir . 'q.tmp' . bin2hex(random_bytes(4));
        $this->locks->noteWrite($tmp);
        $out = null;
        try {
            $this->fault('quarantine:write', ['db' => $dbname, 'path' => $path]); // @test
            $out = @fopen($tmp, 'xb');
            if($out === false){
                throw new \RuntimeException("noneDB: could not create a quarantine file for database '{$dbname}'");
            }
            $this->io->setPermissions($tmp);
            $hash = hash_init('sha256');
            fseek($src, $from);
            $left = $length;
            while($left > 0){
                $chunk = (string)fread($src, min(65536, $left));
                if($chunk === ''){
                    throw new \RuntimeException("noneDB: could not read bytes to quarantine in database '{$dbname}'");
                }
                hash_update($hash, $chunk);
                if(fwrite($out, $chunk) !== strlen($chunk)){
                    throw new \RuntimeException("noneDB: could not write a quarantine file for database '{$dbname}'");
                }
                $left -= strlen($chunk);
            }
            $sha = hash_final($hash);
            // Synced in both modes (DURABILITY.md §3): required in durable mode, else reported in the log
            $fileSynced = fflush($out) ? $this->sync->syncHandle($out, $tmp) : false;
            fclose($out);
            $out = null;
            if($this->durable){
                $this->sync->noteRequired('file', $fileSynced === true);
                if($fileSynced !== true){
                    throw $this->sync->failure('file', $tmp, false);
                }
            }

            $id = "{$seg}.{$from}.{$length}.{$sha}";
            $name = "q.{$id}.bin";
            $target = $dir . $name;
            clearstatcache(true, $target);
            if(file_exists($target)){
                @unlink($tmp); // the same event again (e.g. the cut didn't happen last time): keep the first copy
                $fileSynced = null;
            } elseif(!@rename($tmp, $target)){
                throw new \RuntimeException("noneDB: could not publish a quarantine file for database '{$dbname}'");
            }
            $dirSynced = $this->sync->syncDirectory(rtrim($dir, '/'));
            if($this->durable){
                // The copy must be durable before the source bytes are cut
                $this->sync->noteRequired('dir', $dirSynced === true);
                if($dirSynced !== true){
                    throw $this->sync->failure('dir', $dir, false);
                }
            }
            if(@filesize($target) !== $length || hash_file('sha256', $target) !== $sha){
                throw new \RuntimeException("noneDB: quarantine file {$name} doesn't match the bytes it should hold");
            }
            if($fileSynced === false || $dirSynced === false){
                $durable = false;
            } elseif($dirSynced === null){
                $durable = 'unknown';
            } else {
                $durable = true;
            }
            return ['id' => $id, 'file' => $name, 'durable' => $durable];
        } finally {
            fclose($src);
            if($out !== null && $out !== false){
                fclose($out);
            }
            if(is_file($tmp)){
                @unlink($tmp);
            }
        }
    }

    /**
     * Delete temp files ("*.tmp" + 8 hex digits) starting with $prefix: what a writer that died left
     * behind. Exclusive lock only: no other writer can be in the middle of an atomic write.
     */
    public function removeStaleTemp($prefix){
        if($this->noWrites > 0){
            return;
        }
        foreach($this->segments->listFilesWithPrefix($prefix, '/\.tmp[0-9a-f]{8}$/') as $file){
            @unlink($file);
        }
    }
}
