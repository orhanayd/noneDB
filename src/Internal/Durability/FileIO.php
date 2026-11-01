<?php
// Writes to database files: atomic replacement, appends with undo, the write helpers (docs/DURABILITY.md)
namespace noneDB\Internal\Durability;

use function array_reverse, basename, bin2hex, chmod, clearstatcache, dirname, fclose, fflush, file_exists, file_get_contents,
    file_put_contents, fopen, fseek, ftell, ftruncate, fwrite, ini_get, ini_set, is_dir, is_file, is_link, is_string,
    json_decode, json_encode, json_last_error, json_last_error_msg, mkdir, random_bytes, rmdir, rtrim, strlen,
    microtime, substr, touch, unlink;
use const JSON_ERROR_NONE, JSON_PRESERVE_ZERO_FRACTION, JSON_PRETTY_PRINT, JSON_THROW_ON_ERROR,
    JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, SEEK_END;

/**
 * @internal
 */
final class FileIO {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $appendMismatch;
    private $directoryPermissions;
    private $durable;
    private $exactFloats;
    private $filePermissions;
    private $metrics;
    private $faultHook; // @test
    private $flushFn; // @test
    private $writeFn; // @test
    private $checkpoints;
    private $locks;
    private $recovery;
    private $scope;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->checkpoints, $this->locks, $this->recovery, $this->scope, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->appendMismatch = &$this->k->appendMismatch;
        $this->directoryPermissions = &$this->k->directoryPermissions;
        $this->durable = &$this->k->durable;
        $this->filePermissions = &$this->k->filePermissions;
        $this->exactFloats = &$this->rt->exactFloats;
        $this->metrics = &$this->rt->metrics;
        $this->faultHook = &$this->rt->faultHook; // @test
        $this->flushFn = &$this->rt->flushFn; // @test
        $this->writeFn = &$this->rt->writeFn; // @test
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
        $this->locks = null;
        $this->recovery = null;
        $this->scope = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // ATOMIC FILE OPERATIONS
    // ==========================================

    /**
     * A derived file's header line, its crc last
     * @return string|false
     */
    public function encodeHeader(array $header){
        $json = $this->encodeJson($header);
        return $json === false ? false : \noneDB\Internal\Storage\KeyIndex::sealHeader($json);
    }

    /**
     * Encode JSON, returning false instead of producing invalid output
     * @return string|false
     */
    public function encodeJson($data, $prettyPrint = false, $extraFlags = 0){
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $extraFlags;
        if($prettyPrint){
            $flags |= JSON_PRETTY_PRINT;
        }
        // json_encode() writes floats with serialize_precision digits: anything but -1 (the default,
        // shortest exact form) would store rounded values (0.30000000000000004 as 0.3 at 10).
        // Loops encoding many records run inside withExactFloats(): no ini_get()/finally per record.
        if($this->exactFloats || ini_get('serialize_precision') === '-1'){
            try {
                return json_encode($data, $flags | JSON_THROW_ON_ERROR);
            } catch(\JsonException $e){
                return false;
            }
        }
        return $this->withExactFloats(function() use ($data, $flags){
            try {
                return json_encode($data, $flags | JSON_THROW_ON_ERROR);
            } catch(\JsonException $e){
                return false;
            }
        });
    }

    /**
     * Run $fn with serialize_precision set to -1 (checked once instead of once per encoded value)
     */
    public function withExactFloats(callable $fn){
        if($this->exactFloats){
            return $fn();
        }
        $precision = ini_get('serialize_precision');
        if($precision !== '-1'){
            ini_set('serialize_precision', '-1');
        }
        $this->exactFloats = true;
        try {
            return $fn();
        } finally {
            $this->exactFloats = false;
            if($precision !== '-1'){
                ini_set('serialize_precision', (string)$precision);
            }
        }
    }

    /**
     * Encode one JSONL record line (without the trailing newline)
     * @throws \InvalidArgumentException when the record can't be represented as JSON (invalid UTF-8, NAN, INF, ...)
     */
    public function encodeRecord(array $record){
        // Keep floats floats: 2.0 / -0.0 are stored as 2.0 / -0.0, not as the ints 2 / 0
        $json = $this->encodeJson($record, false, JSON_PRESERVE_ZERO_FRACTION);
        if($json === false){
            throw new \InvalidArgumentException('noneDB: record cannot be encoded as JSON (invalid UTF-8, NAN/INF or unsupported value): ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Write a whole file atomically: temp file in the same directory + rename.
     * Readers see either the old or the new content, never a partial file.
     * @param string|iterable $content The bytes, or a sequence of chunks (a large derived file written as it is
     *                                 made); a chunk that can't be made throws, and the temp file is removed
     * @param string|bool $mode What the file is (docs/DURABILITY.md §3):
     *   'derived' (false): checkpoints and indexes, rebuilt from the data if lost: never synced
     *   'durable': records of the database (FORMAT, CURRENT, metadata, shards.json, indexes.json): synced in
     *              durable mode: temp file, rename (the commit point), directory
     *   'replace' (true): a data file replaced (compaction, the switch to shards): synced in both modes; a failed
     *              directory sync after the rename only counts in durable mode
     * @return bool False when the file could not be written (nothing changed)
     * @throws \RuntimeException DURABILITY_FAILED (a sync before the rename failed, nothing changed),
     *                           OUTCOME_UNKNOWN (the directory sync after the rename failed)
     */
    public function writeFileAtomic($path, $content, $mode = 'derived', $note = true){
        $started = microtime(true);
        $mode = $mode === true ? 'replace' : ($mode === false ? 'derived' : $mode);
        if($mode === 'replace'){
            $this->scope->refuseUncommittedFile($path, 'replacement');
        }
        if($note){
            $this->locks->noteWrite($path);
        }
        $required = $this->durable && $mode !== 'derived';
        $sync = $required || $mode === 'replace';
        $dir = dirname($path);
        if(!is_dir($dir) && !$this->makeDirectory($dir)){
            return false;
        }
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if(!$sync && is_string($content)){ // @prod
        if(!$sync && is_string($content) && $this->writeFn === null && $this->flushFn === null){ // @test
            $written = @file_put_contents($tmp, $content);
            if($written === false || $written !== strlen($content)){
                @unlink($tmp);
                return false;
            }
            $this->setPermissions($tmp);
        } else {
            $fp = @fopen($tmp, 'xb');
            if($fp === false){
                return false;
            }
            try {
                $ok = true;
                foreach(is_string($content) ? [$content] : $content as $chunk){
                    if(!$this->writeAll($fp, $chunk, $path)){
                        $ok = false;
                        break;
                    }
                }
            } catch(\Throwable $e){
                fclose($fp);
                @unlink($tmp);
                throw $e;
            }
            $ok = $ok && fflush($fp); // @prod
            $ok = $ok && ($this->flushFn !== null ? ($this->flushFn)($fp, $path) : fflush($fp)); // @test
            $synced = $ok && $sync ? $this->sync->syncHandle($fp, $path) : null;
            fclose($fp);
            if(!$ok){
                @unlink($tmp);
                return false;
            }
            if($required){
                $this->sync->noteRequired('file', $synced === true);
            }
            if($sync && $synced !== true){
                @unlink($tmp);
                throw $this->sync->failure('file', $path, false);
            }
            $this->setPermissions($tmp);
        }
        if($this->faultHook !== null){ $this->recovery->fault('atomic:renaming', ['path' => $path, 'mode' => $mode]); } // @test
        if(!$this->sync->renameWithRetry($tmp, $path)){
            @unlink($tmp);
            return false;
        }
        clearstatcache(true, $path);
        if($sync){
            $dirSynced = $this->sync->syncDirectory($dir) === true;
            if($required){
                $this->sync->noteRequired('dir', $dirSynced);
                if(!$dirSynced){
                    throw $this->sync->failure('dir', $dir, true);
                }
            }
        }
        if($mode === 'derived'){
            // A checkpoint or an index file (getMetrics())
            $this->metrics['checkpoints']++;
            $this->metrics['checkpointSeconds'] += microtime(true) - $started;
        }
        return true;
    }

    /**
     * Write a file derived from the data (a checkpoint) atomically without moving the generation: its
     * content is the same whoever writes it, so a reader may write it under the shared lock
     */
    public function writeDerivedFile($path, $content){
        return $this->writeFileAtomic($path, $content, 'derived', false);
    }

    /**
     * fwrite() every byte (a short write is continued; no progress is a failure)
     */
    private function writeAll($fp, $bytes, $path){
        for($done = 0, $length = strlen($bytes); $done < $length; $done += $n){
            $chunk = $done === 0 ? $bytes : substr($bytes, $done);
            $n = fwrite($fp, $chunk); // @prod
            $n = $this->writeFn !== null ? ($this->writeFn)($fp, $chunk, $path) : fwrite($fp, $chunk); // @test
            if($n === false || $n <= 0){
                return false;
            }
        }
        return true;
    }

    /**
     * Create a directory and its missing parents. In durable mode each new directory's parent is synced,
     * so the new entries survive a power loss with what is written into them.
     * @throws \RuntimeException DURABILITY_FAILED
     */
    public function makeDirectory($dir){
        $dir = rtrim($dir, '/');
        $missing = [];
        for($at = $dir; $at !== '' && $at !== '.' && $at !== '/' && !is_dir($at); $at = dirname($at)){
            $missing[] = $at;
        }
        foreach(array_reverse($missing) as $new){
            if(!@mkdir($new, $this->directoryPermissions) && !is_dir($new)){
                return false;
            }
            $this->setPermissions($new, true);
            $this->sync->requireSync(null, dirname($new), 'dir');
        }
        return true;
    }

    /**
     * A file or directory noneDB created in dbDir gets the configured permissions, whatever the umask
     * (STORAGE_FORMAT.md §3)
     */
    public function setPermissions($path, $directory = false){
        @chmod($path, $directory ? $this->directoryPermissions : $this->filePermissions);
    }

    // File system writes: every write to a database file goes through writeFileAtomic(),
    // appendToFile() or the helpers below. Each calls noteWrite() first: the generation moves before the
    // file changes, so other processes drop their caches even if this one dies half-way, and a write
    // without the database's exclusive lock is a LogicException. SourceWriteGuardTest fails on a raw
    // write call anywhere else.

    public function fsUnlink($path){
        clearstatcache(true, $path);
        if(!file_exists($path) && !is_link($path)){
            return false; // nothing to remove: no write
        }
        $this->scope->refuseUncommittedFile($path, 'removal');
        $this->locks->noteWrite($path);
        $ok = @unlink($path);
        clearstatcache(true, $path);
        return $ok;
    }

    public function fsRename($from, $to){
        $this->scope->refuseUncommittedFile($from, 'rename');
        $this->scope->refuseUncommittedFile($to, 'replacement');
        $this->locks->noteWrite($from);
        $this->locks->noteWrite($to);
        $ok = $this->sync->renameWithRetry($from, $to);
        clearstatcache(true, $from);
        clearstatcache(true, $to);
        return $ok;
    }

    public function fsTouch($path){
        $this->locks->noteWrite($path);
        $created = !file_exists($path);
        $ok = @touch($path);
        if($ok && $created){
            $this->setPermissions($path);
        }
        clearstatcache(true, $path);
        return $ok;
    }

    /** Create a directory of a database (and its parents; synced in durable mode) */
    public function fsMkdir($path){
        $path = rtrim($path, '/');
        clearstatcache(true, $path);
        if(is_dir($path)){
            return true;
        }
        $this->locks->noteWrite($path . '/');
        return $this->makeDirectory($path);
    }

    /** Remove an empty directory of a database */
    public function fsRmdir($path){
        $path = rtrim($path, '/');
        clearstatcache(true, $path);
        if(!is_dir($path)){
            return false;
        }
        $this->locks->noteWrite($path . '/');
        return @rmdir($path);
    }

    /** Cut a file to $size bytes */
    public function fsTruncate($path, $size){
        $this->locks->noteWrite($path);
        $fp = @fopen($path, 'r+b');
        if($fp === false){
            return false;
        }
        $ok = ftruncate($fp, $size);
        fclose($fp);
        clearstatcache(true, $path);
        return $ok;
    }

    /**
     * Append bytes to a file. Returns the offset the bytes start at, or false (nothing written).
     * The append commits when its bytes are written and flushed and, in durable mode, the file is synced
     * (and its directory when the append created it: an empty file before). A failure before that cuts the
     * bytes back, so the file never ends in a torn line (docs/DURABILITY.md §3, §4).
     * @param string|null $dbname Database whose rollback scope records the append (data and buffer files)
     * @param int|null $expected Size the caller's index says the file has: anything else writes nothing
     *                           and sets $this->appendMismatch
     * @return int|false
     * @throws \RuntimeException DURABILITY_FAILED (a required sync failed, the bytes were cut back),
     *                           OUTCOME_UNKNOWN (the bytes could not be cut back)
     */
    public function appendToFile($path, $bytes, $dbname = null, $expected = null, $segment = null){
        $this->locks->noteWrite($path);
        $this->appendMismatch = false;
        $fp = @fopen($path, 'c+b');
        if($fp === false){
            // The directory of a file's first append (buffer/) is created then: no check on every append
            $dir = dirname($path);
            if(is_dir($dir) || !$this->makeDirectory($dir) || ($fp = @fopen($path, 'c+b')) === false){
                return false;
            }
        }
        try {
            if(fseek($fp, 0, SEEK_END) !== 0){
                return false;
            }
            $offset = ftell($fp);
            if($offset === false){
                return false;
            }
            if($offset === 0){
                $this->setPermissions($path); // created by this append (or empty)
            }
            if($expected !== null && $offset !== (int)$expected){
                // The file changed behind the cached index. An unterminated tail only (an append that
                // never completed): quarantined and cut, then this append goes where the index expects it.
                // Anything else: nothing is written, the caller drops its index (rebuilt on next access).
                if($dbname === null || $offset < (int)$expected || $this->checkpoints->lastNewlineIn($path, (int)$expected, $offset) >= 0){
                    $this->appendMismatch = true;
                    return false;
                }
                $this->recovery->quarantineAndCut($dbname, $segment, $path, (int)$expected, $offset);
                fseek($fp, 0, SEEK_END);
                $offset = ftell($fp);
                if($offset !== (int)$expected){
                    $this->appendMismatch = true;
                    return false;
                }
            }
            if($dbname !== null){
                $this->scope->markScopeFile($dbname, $path, $offset, $fp);
            }
            // Written bytes the stream couldn't hand to the OS (disk full, I/O error): the append failed
            if(!$this->writeAll($fp, $bytes, $path) || !fflush($fp)){ // @prod
            if(!$this->writeAll($fp, $bytes, $path) || !($this->flushFn !== null ? ($this->flushFn)($fp, $path) : fflush($fp))){ // @test
                $this->undoAppend($fp, $offset, $path, null);
                return false;
            }
            if($dbname !== null){
                if(!$this->sync->trySync($fp, $path)){
                    $this->undoAppend($fp, $offset, $path, $this->sync->failure('file', $path, false));
                }
                if($offset === 0 && !$this->sync->trySync(null, dirname($path), 'dir')){
                    $this->undoAppend($fp, $offset, $path, $this->sync->failure('dir', dirname($path), false));
                }
                if($this->faultHook !== null){ $this->recovery->fault('append:after', ['db' => $dbname, 'path' => $path]); } // @test
            }
            return $offset;
        } finally {
            fclose($fp);
            clearstatcache(true, $path);
        }
    }

    /**
     * Cut a failed append back (synced in durable mode), then throw $cause if there is one. A cut that
     * fails leaves the bytes possibly there: OUTCOME_UNKNOWN, so the write's rollback scope tries again
     * and the next session recovers; never a plain failure that would leave them to be read as a record.
     * @throws \RuntimeException
     */
    private function undoAppend($fp, $offset, $path, ?\RuntimeException $cause){
        try {
            if($this->faultHook !== null){ $this->recovery->fault('append:undo', ['path' => $path]); } // @test
            $ok = ftruncate($fp, $offset) && $this->sync->syncUndo($fp, $path);
        } catch(\Throwable $e){
            $ok = false;
        }
        if(!$ok){
            throw new \RuntimeException("noneDB: an append to " . basename($path) . " failed and could not be cut back: the outcome is unknown, read the state again", \noneDB::OUTCOME_UNKNOWN, $cause);
        }
        if($cause !== null){
            throw $cause;
        }
    }

    /**
     * Read and decode a JSON file
     *
     * @param string $path File path
     * @param mixed $default Value returned when the file doesn't exist or is empty
     * @return mixed Decoded data, $default, or null when the file is corrupt
     */
    public function atomicRead($path, $default = null){
        clearstatcache(true, $path);
        if(!is_file($path)){
            return $default;
        }
        $content = @file_get_contents($path);
        if($content === false || $content === ''){
            return $default;
        }
        $data = json_decode($content, true);
        if($data === null && json_last_error() !== JSON_ERROR_NONE){
            return null; // corrupted
        }
        return $data !== null ? $data : $default;
    }

    /**
     * Encode and write a JSON file atomically. Nothing is touched when encoding fails.
     *
     * @param string $path File path
     * @param mixed $data Data to write (will be JSON encoded)
     * @param bool $prettyPrint Use JSON_PRETTY_PRINT
     * @return bool Success
     */
    public function atomicWrite($path, $data, $prettyPrint = false, $mode = 'derived'){
        $json = $this->encodeJson($data, $prettyPrint);
        if($json === false){
            return false;
        }
        return $this->writeFileAtomic($path, $json, $mode);
    }
}
