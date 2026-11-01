<?php
// Key-index checkpoints of the segments: load, replay, write, repair (docs/STORAGE_FORMAT.md §6.4)
namespace noneDB\Internal\Storage;

use function array_keys, basename, bin2hex, clearstatcache, count, crc32, end, fclose, file_exists, filesize, fopen,
    fread, fseek, hash, in_array, intdiv, is_array, is_file, is_int, is_string, json_decode, max, min, random_bytes,
    range, sprintf, strlen, strpos, strrpos, substr, time;
use const LOCK_EX, LOCK_SH;

/**
 * @internal
 */
final class Checkpoints {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    /** @var array<string, string> data file => its checkpoint file (a pure memo of ckptPath()) */
    private static $ckptPaths = [];

    private $dataEpochs;
    private $fieldIndexCache;
    private $indexCache;
    private $spatialIndexCache;
    private $verifiedIndexes;
    private $writableIndexes;
    private $cacheGrew;
    private $sessionCounter;
    private $cacheTick;
    private $cacheTouch;
    private $faultHook; // @test
    private $indexStats; // @test
    private $lockRegistry;
    private $metrics;
    private $noWrites;
    private $uncheckpointed;
    private $decodeDue;
    private $cache;
    private $fieldIndex;
    private $io;
    private $locks;
    private $recovery;
    private $recoveryLog;
    private $rtree;
    private $secondary;
    private $segments;
    private $shardMeta;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->fieldIndex, $this->io, $this->locks, $this->recovery, $this->recoveryLog, $this->rtree, $this->secondary, $this->segments, $this->shardMeta);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dataEpochs = &$this->k->dataEpochs;
        $this->fieldIndexCache = &$this->k->fieldIndexCache;
        $this->indexCache = &$this->k->indexCache;
        $this->spatialIndexCache = &$this->k->spatialIndexCache;
        $this->verifiedIndexes = &$this->k->verifiedIndexes;
        $this->writableIndexes = &$this->k->writableIndexes;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->sessionCounter = &$this->rt->sessionCounter;
        $this->cacheTick = &$this->rt->cacheTick;
        $this->cacheTouch = &$this->rt->cacheTouch;
        $this->faultHook = &$this->rt->faultHook; // @test
        $this->indexStats = &$this->rt->indexStats; // @test
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->metrics = &$this->rt->metrics;
        $this->noWrites = &$this->rt->noWrites;
        $this->uncheckpointed = &$this->rt->uncheckpointed;
        $this->decodeDue = &$this->rt->decodeDue;
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
        $this->fieldIndex = null;
        $this->io = null;
        $this->locks = null;
        $this->recovery = null;
        $this->recoveryLog = null;
        $this->rtree = null;
        $this->secondary = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // JSONL SEGMENT INDEX
    // ==========================================

    /**
     * Get JSONL index path
     * @param string $dbname
     * @param int|null $shardId Null for non-sharded
     * @return string
     */
    public function getJsonlIndexPath($dbname, $shardId = null){
        return self::ckptPath($this->segments->segDataPath($dbname, $shardId));
    }

    /**
     * Checkpoint of a data file: segments/{seg}.jsonl -> segments/{seg}.kix
     */
    public static function ckptPath($dataPath){
        if(isset(self::$ckptPaths[$dataPath])){
            return self::$ckptPaths[$dataPath];
        }
        if(count(self::$ckptPaths) >= 65536){
            self::$ckptPaths = [];
        }
        return self::$ckptPaths[$dataPath] = substr($dataPath, 0, -6) . '.kix';
    }

    public function newJsonlIndex(){
        return KeyIndex::emptyIndex();
    }

    /**
     * A segment's key index (offset and length of every live record), from the cache, or loaded from its
     * checkpoint and the data after it
     * @param string $dbname
     * @param int|null $shardId
     * @return array|null Null when the segment doesn't exist
     */
    public function readJsonlIndex($dbname, $shardId = null){
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        $path = self::ckptPath($dataPath);
        // A cached index was checked against its data file (no file check on this hot path); else the file
        // must exist
        if(!isset($this->indexCache[$path]) && !$this->cache->cachedFileExists($dataPath)){
            return null;
        }
        if(isset($this->indexCache[$path])){
            $this->cacheTouch[$path] = ++$this->cacheTick;
            if(isset($this->verifiedIndexes[$path])){
                if(($this->indexCache[$path]['kb'] !== '' || empty($this->indexCache[$path]['few'])) && $this->indexCache[$path]['ses'] !== $this->sessionCounter){
                    // One more operation: a body is decoded once they have paid for it (a database of few segments
                    // decodes earlier: loadSegmentIndex(), decodeDueIndexes()); they count on after that for the
                    // data bytes of a segment (RecordIO::heldData()), which a database of few segments doesn't hold
                    $this->indexCache[$path]['ses'] = $this->sessionCounter;
                    if((++$this->indexCache[$path]['uses'] + $this->indexCache[$path]['looked']) * self::KEYS_PER_LOOKUP >= $this->indexCache[$path]['live']
                        && $this->indexCache[$path]['kb'] !== ''){
                        $this->decodeKeyIndex($path);
                    }
                }
                $this->metrics['hits']++;
                return $this->indexCache[$path];
            }
            // Another process wrote the database since this one cached the index, without replacing a data
            // file (same epoch): the data only grew, the new lines are replayed
            $index = $this->indexCache[$path];
            unset($this->indexCache[$path]);
            clearstatcache(true, $dataPath);
            $size = @filesize($dataPath);
            if($size !== false && $size >= (int)$index['s']){
                if($size > (int)$index['s']){
                    $this->replaySegment($dbname, $shardId, $index, $size);
                }
                $this->indexCache[$path] = $index;
                $this->verifiedIndexes[$path] = true;
                $this->maybeReaderCheckpoint($dbname, $shardId);
                $this->metrics['hits']++;
                return $this->indexCache[$path];
            }
        }
        return $this->loadSegmentIndex($dbname, $shardId);
    }

    /**
     * Load a segment index: its checkpoint when it fits the data file, then the lines after it. A
     * checkpoint that doesn't fit (the data changed behind it) rebuilds the index and the segment's
     * secondary indexes under the exclusive lock; a missing one (or one of an unknown version) only
     * replays the whole file.
     * @return array|null
     */
    private function loadSegmentIndex($dbname, $shardId){
        $this->indexStats['loads']++; // @test
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        $path = self::ckptPath($dataPath);
        clearstatcache(true, $dataPath);
        if(!is_file($dataPath)){
            if(file_exists($dataPath)){
                throw new \RuntimeException("noneDB: data file of database '{$dbname}' is not a regular file: " . basename($dataPath));
            }
            $this->cache->markFileNotExists($dataPath);
            return null;
        }
        $size = (int)filesize($dataPath);
        $this->metrics['misses']++;
        // A checkpoint whose header, body length or a block crc is not valid is no checkpoint (full replay)
        $checkpoint = KeyIndex::readFile($path);
        if($checkpoint !== null){
            $this->cache->noteIndexRead(KeyIndex::HEADER + strlen($checkpoint[1]));
            if(!$this->checkpointFits($dataPath, $checkpoint[0]['s'], $size, $checkpoint[0]['h'] ?? null)){
                return $this->repairSegmentIndex($dbname, $shardId);
            }
            $index = KeyIndex::fromFile($checkpoint[0], $checkpoint[1]);
            $index['ckh'] = $checkpoint[0]['h']; // checked: the crc of the bytes before ck
        } else {
            $index = $this->newJsonlIndex();
        }
        $this->replaySegment($dbname, $shardId, $index, $size);
        $index['ses'] = $this->sessionCounter; // the operation that loads it is its first
        $index['uses'] = 1;
        $this->indexCache[$path] = $index;
        $this->verifiedIndexes[$path] = true;
        $this->indexCache[$path]['few'] = $this->segments->segmentCount($dbname) <= Segments::FEW_SEGMENTS;
        $this->indexCache[$path]['dp'] = $dataPath;
        $this->cacheTouch[$path] = ++$this->cacheTick;
        $this->cacheGrew = true;
        if($index['ck'] < 0){
            // No checkpoint to continue (missing, empty): the next write session of this process writes one
            $this->uncheckpointed[$this->locks->getLockPath($dbname)][$shardId === null ? 'main' : $shardId] = $shardId;
        }
        $this->maybeReaderCheckpoint($dbname, $shardId);
        // A database of few segments (§6.4): a small body decoded at its first use, except in the process's
        // first operation (a single cold operation keeps the body); any other at the start of the database's
        // next operation (LockManager::lockSession() calls decodeDueIndexes())
        if(!empty($this->indexCache[$path]['few']) && $this->indexCache[$path]['kb'] !== ''){
            if(strlen($this->indexCache[$path]['kb']) <= self::DECODE_AT_FIRST_USE && $this->sessionCounter >= 2){
                $this->decodeKeyIndex($path);
            }
            if(isset($this->indexCache[$path]) && $this->indexCache[$path]['kb'] !== ''){
                $this->decodeDue[$this->locks->getLockPath($dbname)][$path] = true;
            }
        }
        return $this->indexCache[$path];
    }

    /**
     * True when a checkpoint covering $covered bytes belongs to this data file: within it, at a line end,
     * and the bytes before $covered have the checksum it recorded
     */
    public function checkpointFits($dataPath, $covered, $size, $crc){
        if($covered < 0 || $covered > $size || !is_string($crc)){
            return false;
        }
        return $this->segmentCrc($dataPath, $covered) === $crc;
    }

    /**
     * crc32b of the up to 256 bytes before $offset of a data file; null when $offset is not a line end
     */
    public function segmentCrc($dataPath, $offset){
        if($offset === 0){
            return sprintf('%08x', crc32(''));
        }
        $fp = @fopen($dataPath, 'rb');
        if($fp === false){
            return null;
        }
        $start = max(0, $offset - 256);
        fseek($fp, $start);
        $bytes = (string)fread($fp, $offset - $start);
        fclose($fp);
        return strlen($bytes) === $offset - $start && substr($bytes, -1) === "\n" ? sprintf('%08x', crc32($bytes)) : null;
    }

    /**
     * Apply the complete lines of bytes [s, $size) of a segment's data file to its index: the last line of
     * a key wins, a tombstone deletes. An unterminated tail stays outside ("s" ends before it; the next
     * writer quarantines and cuts it). A complete line that doesn't decode (a damaged region before it,
     * STORAGE_FORMAT.md §6.1) is salvaged when a record or tombstone can be found at its end, and reported
     * either way.
     * @param array|null $hint an earlier index of the segment (p/l), used to find records in damaged lines
     * @param bool $report Report salvaged and skipped lines (not for checkIntegrity())
     */
    public function replaySegment($dbname, $shardId, array &$index, $size, $hint = null, $report = true){
        $from = (int)$index['s'];
        if($shardId !== null){
            $index['z'] = $this->segments->shardSizeOf($dbname);
            $index['sid'] = $shardId;
        }
        if($size <= $from){
            return;
        }
        $fp = @fopen($this->segments->segDataPath($dbname, $shardId), 'rb');
        if($fp === false){
            return;
        }
        $base = $shardId === null ? 0 : $shardId * $this->segments->shardSizeOf($dbname);
        $limit = $shardId === null ? null : $base + $this->segments->shardSizeOf($dbname);
        $p = $index['p'];
        $l = $index['l'];
        $x = $index['x'];
        unset($index['p'], $index['l'], $index['x']); // sole owner: changed in place, not copied
        $hasBody = $index['kb'] !== '';
        $live = $index['live'];
        $n = (int)$index['n'];
        $d = (int)$index['d'];
        $t = (int)$index['t'];
        $lines = 0;
        $oldEnds = null;
        $events = [];
        fseek($fp, $from);
        $read = $from;
        $offset = $from;  // file offset of $buffer[0]
        $buffer = '';
        while($read < $size){
            $chunk = fread($fp, min(1048576, $size - $read));
            if($chunk === false || $chunk === ''){
                break;
            }
            $read += strlen($chunk);
            $buffer .= $chunk;
            $pos = 0;
            while(($newline = strpos($buffer, "\n", $pos)) !== false){
                $json = substr($buffer, $pos, $newline - $pos);
                $lineOffset = $offset + $pos;
                $entry = $this->classifyLine($json, $base, $limit); // a key of another shard is no line of this one
                if($entry === null){
                    if($oldEnds === null){
                        $oldEnds = [];
                        [$hintOffsets, $hintLengths] = $hint === null ? [[], []] : KeyIndex::locate($hint, null);
                        foreach($hintOffsets as $k => $at){
                            $oldEnds[(int)$at + (int)$hintLengths[$k]] = [(int)$k, (int)$at];
                        }
                        $hintOffsets = $hintLengths = null;
                    }
                    $entry = $this->salvageLine($json, $lineOffset, $oldEnds, $base, $limit);
                    $events[] = [
                        'action' => $entry === null ? 'skip-line' : 'salvaged',
                        'seg' => \noneDB\Internal\Storage\Layout::segmentName($shardId),
                        'off' => $lineOffset, 'len' => strlen($json) + 1,
                        'sha256' => hash('sha256', $json . "\n"),
                    ] + ($entry === null ? [] : ['key' => $entry[1]]);
                }
                if($entry === null){
                    $d++;
                } else {
                    $key = $entry[1];
                    // The key's record so far: written since the checkpoint (p), deleted since (x), or in the body
                    $inBody = $hasBody && !isset($x[$key]) && KeyIndex::baseGet($index, $key) !== null;
                    $existed = isset($p[$key]) || $inBody;
                    if($entry[0] === 't'){
                        if($existed){
                            unset($p[$key], $l[$key]);
                            if($inBody){
                                $x[$key] = true;
                            }
                            $live--;
                            $d++;
                        }
                        $t++;
                    } else {
                        if($existed){
                            $d++;
                        } else {
                            $live++;
                        }
                        $p[$key] = $lineOffset + $entry[2];
                        $l[$key] = $entry[3];
                        unset($x[$key]);
                    }
                    if($key >= $base && $key - $base + 1 > $n){
                        $n = $key - $base + 1;
                    }
                }
                $lines++;
                $pos = $newline + 1;
            }
            $offset += $pos;
            $buffer = (string)substr($buffer, $pos);
        }
        fclose($fp);
        $index['p'] = $p;
        $index['l'] = $l;
        $index['x'] = $x;
        $index['live'] = $live;
        $index['n'] = $n;
        $index['d'] = $d;
        $index['t'] = $t;
        $index['s'] = $offset;
        $index['tl'] = (int)$index['tl'] + $lines;
        $this->indexStats['replayedLines'] += $lines; // @test
        foreach($report ? $events : [] as $event){
            $event['id'] = $event['seg'] . '.' . $event['off'] . '.' . $event['len'] . '.' . $event['sha256'];
            unset($event['sha256']);
            $this->recoveryLog->reportRecoveryEvent($dbname, $event);
        }
    }

    /**
     * A segment index changed by a write (exclusive lock): cached, and checkpointed when the session ends
     */
    public function storeSegmentIndex($dbname, $shardId, array $index){
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        $this->indexCache[$path] = $index;
        $this->verifiedIndexes[$path] = true;
        $this->lockRegistry[$this->locks->getLockPath($dbname)]['touched'][$shardId === null ? 'main' : $shardId] = $shardId;
        if($this->faultHook !== null){ $this->recovery->fault('index:written', ['db' => $dbname, 'shard' => $shardId]); } // @test
    }

    /**
     * True when the lines after a segment's checkpoint are many enough to write a new one
     */
    private function needsCheckpoint(array $index){
        return (int)$index['ck'] < 0
            || (int)$index['tl'] >= self::CHECKPOINT_LINES + intdiv($index['live'], 16)
            || (int)$index['s'] - (int)$index['ck'] >= self::CHECKPOINT_BYTES;
    }

    /**
     * Write the checkpoint file of a data file's key index (an index with its epoch; exclusive lock, or a reader:
     * the content is the data's): [layout, slots, body, max, live, header, base], or null when its bytes don't end
     * at a line or the file can't be written
     * @return array|null
     */
    public function writeCheckpointFile($dataPath, $shardId, $shardSize, array $index){
        $crc = $this->segmentCrc($dataPath, (int)$index['s']);
        if($crc === null){
            return null;
        }
        $base = $shardId === null ? 0 : $shardId * $shardSize;
        [$layout, $slots, $body, $max, $live] = KeyIndex::encodeBody($index, $base);
        $header = ['kind' => 'kix', 'v' => 1, 'layout' => $layout, 'base' => $base, 'slots' => $slots, 'live' => $live,
            'n' => (int)$index['n'], 'd' => (int)$index['d'], 't' => (int)$index['t'], 's' => (int)$index['s'], 'h' => $crc,
            'e' => $index['e'], 'created' => (int)$index['created'], 'updated' => time()];
        if($shardId !== null){
            $header['z'] = $shardSize;
            $header['sid'] = $shardId;
        }
        if(!$this->io->writeDerivedFile(self::ckptPath($dataPath), KeyIndex::encodeFile($header, $body))){
            return null;
        }
        return [$layout, $slots, $body, $max, $live, $header, $base];
    }

    /**
     * Write a segment's checkpoint (best effort: a failure only means a longer replay next time). An
     * index without epoch gets one, which only the exclusive lock holder may assign.
     * @return bool
     */
    public function writeCheckpoint($dbname, $shardId, array &$index){
        if($this->noWrites > 0){
            return false;
        }
        $lock = $this->locks->getLockPath($dbname);
        $exclusive = isset($this->lockRegistry[$lock]) && $this->lockRegistry[$lock]['mode'] === LOCK_EX;
        if($index['e'] === null){
            if(!$exclusive){
                return false;
            }
            $index['e'] = bin2hex(random_bytes(4));
        }
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        $written = $this->writeCheckpointFile($dataPath, $shardId, $shardId === null ? null : $this->segments->shardSizeOf($dbname), $index);
        if($written === null){
            return false;
        }
        [$layout, $slots, $body, $max, $live, $header, $base] = $written;
        if($index['kb'] !== ''){
            $index = KeyIndex::rebased($index, $layout, $slots, $body, $max, $base); // compact again
        }
        // (an index held as arrays stays so: it is in use, or was just replayed whole; eviction compacts it)
        $index['live'] = $live;
        $index['updated'] = $header['updated'];
        $index['ck'] = (int)$index['s'];
        $index['ckh'] = $header['h'];
        $index['tl'] = 0;
        $this->indexStats['checkpoints']++; // @test
        $this->indexCache[self::ckptPath($dataPath)] = $index;
        $this->dataEpochs[$dataPath] = $index['e'];
        if($this->faultHook !== null){ $this->recovery->fault('checkpoint:written', ['db' => $dbname, 'shard' => $shardId]); } // @test
        return true;
    }

    /**
     * End of a successful write session: checkpoint the segments it changed that need one
     */
    public function checkpointTouchedSegments($dbname, $lock){
        $touched = $this->lockRegistry[$lock]['touched'] ?? [];
        $segments = $touched + ($this->uncheckpointed[$lock] ?? []);
        unset($this->uncheckpointed[$lock]);
        foreach($segments as $shardId){
            $path = $this->getJsonlIndexPath($dbname, $shardId);
            if(!isset($this->indexCache[$path], $this->verifiedIndexes[$path]) || !$this->needsCheckpoint($this->indexCache[$path])){
                continue;
            }
            $index = $this->indexCache[$path];
            try {
                $this->writeCheckpoint($dbname, $shardId, $index);
            } catch(\Throwable $ignored){
                // a checkpoint is never needed for correctness
            }
        }
        $this->lockRegistry[$lock]['touched'] = [];
        foreach($touched as $shardId){
            if(!isset($this->indexCache[$this->getJsonlIndexPath($dbname, $shardId)])){
                continue; // dropped by the cache limit during the session: the next reader that loads it checkpoints it
            }
            try {
                $this->secondary->checkpointSegmentSecondaries($dbname, $shardId);
            } catch(\Throwable $e){
                if($e->getCode() === \noneDB::LOCK_FAILED){
                    throw $e;
                }
            }
        }
    }

    /**
     * A reader that replayed a long tail writes the checkpoint itself (shared lock: the data can't change,
     * the content is the same as a writer's; no generation bump). Only on top of an existing checkpoint with
     * an epoch, and not inside a write session of this process (that one checkpoints at its end).
     */
    private function maybeReaderCheckpoint($dbname, $shardId){
        $lock = $this->locks->getLockPath($dbname);
        if($this->noWrites > 0 || !isset($this->lockRegistry[$lock]) || $this->lockRegistry[$lock]['mode'] !== LOCK_SH){
            return;
        }
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        $index = $this->indexCache[$path];
        if($index['e'] === null || (int)$index['ck'] < 0 || !$this->needsCheckpoint($index)){
            return;
        }
        try {
            $this->writeCheckpoint($dbname, $shardId, $index);
        } catch(\Throwable $ignored){
        }
    }

    /**
     * Position of the last "\n" in bytes [$from, $to) of a file, -1 if there is none
     */
    public function lastNewlineIn($path, $from, $to){
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return -1;
        }
        try {
            $end = $to;
            while($end > $from){
                $start = max($from, $end - 65536);
                fseek($fp, $start);
                $chunk = (string)fread($fp, $end - $start);
                $pos = strrpos($chunk, "\n");
                if($pos !== false){
                    return $start + $pos;
                }
                $end = $start;
            }
            return -1;
        } finally {
            fclose($fp);
        }
    }

    /**
     * Rebuild a segment index from its data file (exclusive lock, upgrading a shared one): a checkpoint
     * that doesn't fit the data, a key the index points at that isn't there. The rebuilt index has a new
     * epoch, so the segment's field and spatial indexes are rebuilt as well (STORAGE_FORMAT.md §6.5).
     * @param bool $secondaries Also rebuild field/spatial indexes when needed (recovery does it itself)
     * @return array|null
     */
    public function repairSegmentIndex($dbname, $shardId, $secondaries = true){
        return $this->locks->withLock($dbname, true, function() use ($dbname, $shardId, $secondaries){
            $dataPath = $this->segments->segDataPath($dbname, $shardId);
            $path = self::ckptPath($dataPath);
            unset($this->indexCache[$path], $this->verifiedIndexes[$path], $this->writableIndexes[$path]);
            clearstatcache(true, $dataPath);
            if(!file_exists($dataPath)){
                return null;
            }
            if(!is_file($dataPath)){
                throw new \RuntimeException("noneDB: data file of database '{$dbname}' is not a regular file: " . basename($dataPath));
            }
            $old = $this->checkpointOnDisk($path);
            $index = $this->rebuildSegmentIndexFromData($dbname, $shardId, $old);
            if($shardId !== null){
                $this->shardMeta->recountMeta($dbname);
            }
            // The rebuilt index has a new epoch: every field and spatial index of the segment is invalid now (§6.5),
            // also when the entries are the old ones (the data changed in place). Built again while this exclusive
            // lock is held, not by the next reader
            if($secondaries){
                $this->rebuildSecondaryIndexes($dbname, [$shardId]);
            }
            return $index;
        }, 'rebuildIndex');
    }


    /**
     * The key index a checkpoint file on disk holds (valid by the format, not checked against the data), or null
     */
    private function checkpointOnDisk($path){
        $checkpoint = KeyIndex::readFile($path);
        if($checkpoint === null){
            return null;
        }
        $this->cache->noteIndexRead(KeyIndex::HEADER + strlen($checkpoint[1]));
        return KeyIndex::fromFile($checkpoint[0], $checkpoint[1]);
    }

    /**
     * Rebuild field and spatial indexes of some segments (and the global ones of a sharded database)
     * from the data. Caller holds the exclusive lock.
     */
    public function rebuildSecondaryIndexes($dbname, array $segments){
        $manifest = $this->fieldIndex->getIndexManifest($dbname);
        if(empty($manifest['fields']) && empty($manifest['spatial'])){
            return;
        }
        $known = $this->segments->listSegments($dbname);
        // One index that can't be built must never lock the whole database: it is dropped (queries scan,
        // the next write or query builds it again) and logged
        $guard = function(callable $build, $what, array $files) use ($dbname){
            try {
                $build();
            } catch(\Throwable $e){
                if($e->getCode() === \noneDB::LOCK_FAILED){
                    throw $e;
                }
                foreach($files as $file){
                    $this->io->fsUnlink($file);
                    unset($this->fieldIndexCache[$file], $this->spatialIndexCache[$file]);
                }
                $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'index-dropped', 'index' => $what, 'error' => $e->getMessage()]);
            }
        };
        foreach($segments as $shardId){
            if(!in_array($shardId, $known, true)){
                continue;
            }
            foreach($manifest['fields'] as $field){
                $guard(function() use ($dbname, $field, $shardId){
                    $index = $this->fieldIndex->buildSegmentFieldIndex($dbname, $shardId, $field);
                    $this->fieldIndex->writeFieldIndex($dbname, $field, $index, $shardId);
                }, 'field ' . $field, [$this->fieldIndex->getFieldIndexPath($dbname, $field, $shardId)]);
            }
            foreach($manifest['spatial'] as $field){
                $guard(function() use ($dbname, $field, $shardId, $manifest){
                    $tree = $this->rtree->buildSegmentSpatialIndex($dbname, $field, $shardId,
                        isset($manifest['spatialOptions'][$field]['nodeSize']) ? ['nodeSize' => $manifest['spatialOptions'][$field]['nodeSize']] : []);
                    $this->rtree->writeSpatialIndex($dbname, $field, $tree, $shardId);
                    if($shardId !== null){
                        $this->rtree->updateGlobalSpatialShard($dbname, $field, $shardId, $tree);
                    }
                }, 'spatial ' . $field, [$this->rtree->getSpatialIndexPath($dbname, $field, $shardId)]);
            }
            $this->cache->evictBetweenSegments();
        }
        if($this->segments->isSharded($dbname)){
            foreach($manifest['fields'] as $field){
                $guard(function() use ($dbname, $field){
                    $this->fieldIndex->rebuildGlobalFieldIndex($dbname, $field);
                }, 'global field ' . $field, [$this->fieldIndex->getGlobalFieldIndexPath($dbname, $field)]);
            }
        }
    }

    /**
     * Index of a segment a writer is about to change (exclusive lock), taken out of the cache: the
     * writer changes it in place and stores it back (storeSegmentIndex). Checked against the data file
     * when it was not yet known to match it: an unterminated tail (crashed append) is quarantined and cut
     * off, so the next line can't be glued to it. A checked index stays checked until its cache is
     * dropped; appendToFile() compares the size it finds with the index anyway (no extra system call),
     * which catches anything else.
     * @return array|null
     */
    public function writableSegmentIndex($dbname, $shardId){
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null){
            return null;
        }
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        $path = self::ckptPath($dataPath);
        if(!isset($this->writableIndexes[$path])){
            clearstatcache(true, $dataPath);
            $size = (int)@filesize($dataPath);
            if($size < (int)$index['s']){
                $index = $this->repairSegmentIndex($dbname, $shardId);
                clearstatcache(true, $dataPath);
                $size = (int)@filesize($dataPath);
            }
            if($size > (int)$index['s']){
                $this->recovery->quarantineAndCut($dbname, $shardId, $dataPath, (int)$index['s'], $size);
            }
            $this->writableIndexes[$path] = true;
        }
        unset($this->indexCache[$path]); // the caller is its only holder until it stores it back
        return $index;
    }

    /**
     * A writer's index goes back to the cache unchanged (nothing to write after all)
     */
    public function releaseSegmentIndex($dbname, $shardId, array $index){
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        $this->indexCache[$path] = $index;
        $this->verifiedIndexes[$path] = true;
    }

    /**
     * Rebuild a segment index from the whole data file and write its checkpoint (exclusive lock): new
     * segment epoch, as there is no checkpoint it continues
     * @param array|null $old The checkpoint found (created time, key counter, records in damaged lines)
     * @return array
     */
    public function rebuildSegmentIndexFromData($dbname, $shardId, $old = null){
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        if($old === null){
            $old = $this->checkpointOnDisk(self::ckptPath($dataPath));
        }
        $index = $this->newJsonlIndex();
        if($old !== null){
            $index['created'] = $old['created'] ?? $index['created'];
            if($shardId === null && isset($old['n'])){
                $index['n'] = (int)$old['n'];
            }
        }
        clearstatcache(true, $dataPath);
        $this->replaySegment($dbname, $shardId, $index, (int)@filesize($dataPath), $old); // its entries only if a line is damaged
        $index['e'] = bin2hex(random_bytes(4));
        $path = self::ckptPath($dataPath);
        $this->indexCache[$path] = $index;
        $this->verifiedIndexes[$path] = true;
        $this->writeCheckpoint($dbname, $shardId, $index);
        return $this->indexCache[$path];
    }

    /**
     * Classify one complete data line: ['r', key, 0, length] (record), ['t', key] (tombstone), or null
     * when it isn't a valid line of this segment
     */
    public function classifyLine($json, $base, $limit){
        $record = json_decode($json, true);
        if(!is_array($record)){
            return null;
        }
        if(count($record) === 1 && isset($record['__nonedb_deleted']) && is_int($record['__nonedb_deleted'])){
            $key = $record['__nonedb_deleted'];
            return $key >= $base && ($limit === null || $key < $limit) ? ['t', $key] : null;
        }
        if(isset($record['key']) && is_int($record['key'])){
            $key = $record['key'];
            return $key >= $base && ($limit === null || $key < $limit) ? ['r', $key, 0, strlen($json)] : null;
        }
        return null;
    }

    /**
     * Find a record or tombstone at the end of a line that doesn't decode (a damaged region before it).
     * First where the earlier index says a record ended; then candidates from the
     * rightmost "{" leftwards: inside a record no "{" starts a JSON text that also ends the line, so the
     * first one that decodes is where the record starts.
     * @return array|null Like classifyLine(), with the record's offset inside the line
     */
    public function salvageLine($json, $lineOffset, array $oldEnds, $base, $limit){
        $end = $lineOffset + strlen($json);
        if(isset($oldEnds[$end]) && $oldEnds[$end][1] > $lineOffset){
            $start = $oldEnds[$end][1] - $lineOffset;
            $entry = $this->classifySalvaged(substr($json, $start), $base, $limit);
            if($entry !== null && $entry[0] === 'r' && $entry[1] === $oldEnds[$end][0]){
                return ['r', $entry[1], $start, strlen($json) - $start];
            }
        }
        $pos = strlen($json);
        for($tries = 0; $tries < 4096; $tries++){
            $pos = $pos > 0 ? strrpos($json, '{', $pos - strlen($json) - 1) : false;
            if($pos === false || $pos === 0){
                return null;
            }
            $entry = $this->classifySalvaged(substr($json, $pos), $base, $limit);
            if($entry !== null){
                return $entry[0] === 't' ? $entry : ['r', $entry[1], $pos, strlen($json) - $pos];
            }
        }
        return null;
    }

    /**
     * classifyLine() for a salvage candidate: also requires an object whose last member is "key"
     */
    private function classifySalvaged($candidate, $base, $limit){
        $entry = $this->classifyLine($candidate, $base, $limit);
        if($entry === null || $entry[0] === 't'){
            return $entry;
        }
        $record = json_decode($candidate, true);
        $keys = array_keys($record);
        return end($keys) === 'key' && $keys !== range(0, count($keys) - 1) ? $entry : null;
    }

    // Segment index: checkpoint + replay (docs/STORAGE_FORMAT.md §6.4). The data file is a log; the .kix
    // file is a checkpoint of the index at byte offset "s" of it: a 512-byte JSON header ("kind":"kix", "v":1,
    // layout, base, slots, live, n next key, d garbage lines, t tombstone lines, s, h = crc32b of the up to 256
    // bytes before s, e segment epoch, z shard size, sid shard id), then the slots in 4 KiB blocks with their crc
    // (KeyIndex). Loading reads the checkpoint and replays the complete lines after S (an unterminated tail is never
    // part of it, readers never cut it). Writes append to the data and update the cached index only; a
    // write session writes a new checkpoint of a segment it changed once its tail reached 64 lines plus
    // 1/16 of the live records, or 1 MiB (checkpointTouchedSegments). A checkpoint counts only for its data
    // file: S within it, at a line end, h matching; a data file is replaced only after its checkpoint was
    // deleted. "e" is the segment's data epoch for the secondary indexes: new when the data file is
    // replaced or the index rebuilt without a checkpoint, assigned only under the exclusive lock.
    // Runtime keys of a cached index (never written): "ck" = S of the checkpoint on disk (-1: none),
    // "tl" = lines after it.

    /** A key index body of at most DECODE_MAX_KEYS keys may be decoded into arrays */
    private const DECODE_MAX_KEYS = 250000;
    /** A body of at most this many bytes, in a database of few segments, is decoded at its first use (§6.4) */
    private const DECODE_AT_FIRST_USE = 65536;
    /** Estimated memory of a decoded key index per key */
    private const DECODED_KEY_BYTES = 80;
    /**
     * Keys whose decoding costs about what one key lookup costs more on a body than on arrays (measured:
     * 0.17 µs per key to decode, 1.3 µs more per lookup on a body)
     */
    private const KEYS_PER_LOOKUP = 8;

    /**
     * Decode a cached key index body into arrays (lookups at array speed) when it has at most
     * DECODE_MAX_KEYS keys and memory allows (CacheManager::decodeFits()). In a database of few segments
     * (Segments::FEW_SEGMENTS: each is in constant use): at its first use when the body is at most
     * DECODE_AT_FIRST_USE bytes (loadSegmentIndex()), else at its second operation. Otherwise once the
     * lookups through the body have paid for decoding: operations (one key each, counted in readJsonlIndex())
     * and keys looked up in bulk (noteLookups()), KEYS_PER_LOOKUP live keys each. Light use keeps the body:
     * a cold query over many segments, a few reads of each segment.
     */
    private function decodeKeyIndex($path){
        $live = $this->indexCache[$path]['live'];
        if($live <= self::DECODE_MAX_KEYS && \noneDB\Internal\Cache\CacheManager::decodeFits(self::DECODED_KEY_BYTES * $live)){
            $this->indexStats['keyDecodes']++; // @test
            $this->indexCache[$path] = KeyIndex::materialize($this->indexCache[$path]);
            $this->cacheGrew = true;
        }
    }

    /**
     * Start of an operation (lock session) on a database of few segments: the key index bodies an earlier
     * operation loaded are decoded now (§6.4), whether this operation reads them or not. Called once the
     * lock state was checked: an index another process made stale is gone from the cache by then.
     */
    public function decodeDueIndexes($lockPath){
        foreach($this->decodeDue[$lockPath] as $path => $unused){
            if(isset($this->indexCache[$path], $this->verifiedIndexes[$path]) && $this->indexCache[$path]['kb'] !== ''){
                $this->decodeKeyIndex($path);
            }
        }
        unset($this->decodeDue[$lockPath]);
    }

    /**
     * A segment's key index for a read of every record: a whole read decodes the body anyway, so the decoded
     * index is kept (when memory allows) and the writes that often follow such a read work on arrays
     */
    public function decodedIndex($dbname, $shardId){
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null || $index['kb'] === ''){
            return $index;
        }
        $path = self::ckptPath($this->segments->segDataPath($dbname, $shardId));
        $this->decodeKeyIndex($path);
        return $this->indexCache[$path] ?? $index;
    }

    /** Keys looked up in bulk through the key index body of a data file (see decodeKeyIndex()) */
    public function noteLookups($dataPath, $n){
        $path = self::ckptPath($dataPath);
        if(!isset($this->indexCache[$path]) || $this->indexCache[$path]['kb'] === ''){
            return;
        }
        $this->indexCache[$path]['looked'] += $n;
        if(($this->indexCache[$path]['uses'] + $this->indexCache[$path]['looked']) * self::KEYS_PER_LOOKUP >= $this->indexCache[$path]['live']){
            $this->decodeKeyIndex($path);
        }
    }
    public const CHECKPOINT_LINES = 64;
    public const CHECKPOINT_BYTES = 1048576;
}
