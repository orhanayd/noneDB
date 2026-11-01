<?php
// Reading and appending data lines of a segment
namespace noneDB\Internal\Storage;

use function array_chunk, array_key_first, array_key_last, array_keys, array_pop, explode, asort, bin2hex, clearstatcache, count, filesize, end, fclose, file_exists, file_get_contents, fopen, fread, fseek, implode,
    fstat, is_array, is_int, is_string, json_decode, ksort, max, random_bytes, reset, sort, strlen, substr;
use const LOCK_SH, SEEK_SET, SORT_NUMERIC;

/**
 * @internal
 */
final class RecordIO {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $appendMismatch;
    private $indexCache;
    private $fieldIndexCache;
    private $cacheTouch;
    private $sessionLimit;
    private $cacheTick;
    private $cacheGrew;
    private $verifiedIndexes;
    private $writableIndexes;
    private $lockRegistry;
    private $segmentReads; // @test
    private $indexStats; // @test
    private $cache;
    private $checkpoints;
    private $io;
    private $locks;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->io, $this->locks, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->appendMismatch = &$this->k->appendMismatch;
        $this->indexCache = &$this->k->indexCache;
        $this->fieldIndexCache = &$this->k->fieldIndexCache;
        $this->cacheTouch = &$this->rt->cacheTouch;
        $this->sessionLimit = &$this->rt->sessionLimit;
        $this->cacheTick = &$this->rt->cacheTick;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->verifiedIndexes = &$this->k->verifiedIndexes;
        $this->writableIndexes = &$this->k->writableIndexes;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->segmentReads = &$this->rt->segmentReads; // @test
        $this->indexStats = &$this->rt->indexStats; // @test
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
        $this->locks = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // RECORD I/O
    // ==========================================

    /** A batch whose records lie within this many bytes is read with one call */
    private const SPAN = 262144;

    /**
     * Batch read multiple JSONL records (one open, 64KB buffered sequential reads)
     * @param string $path File path
     * @param array $positions [key => offset, ...]
     * @param array $lengths [key => length, ...]
     * @param bool $mismatch Set to true when a line doesn't hold the expected record
     * @return array [key => record, ...]
     */
    public function readJsonlRecordsBatch($path, $positions, $lengths, &$mismatch = false, $held = null){
        $mismatch = false;
        if(empty($positions)){
            return [];
        }

        if(count($positions) === 1){
            // One record (a key lookup): its bytes only, in one call
            $key = array_key_first($positions);
            $length = (int)($lengths[$key] ?? 0);
            if($length > self::SPAN){
                clearstatcache(true, $path); // a length the file doesn't hold is never asked of it, as large as it says
                $length = $positions[$key] + $length > (int)@filesize($path) ? 0 : $length;
            }
            $json = $length <= 0 ? false : ($held !== null && $positions[$key] + $length <= strlen($held)
                ? substr($held, $positions[$key], $length) : @file_get_contents($path, false, null, (int)$positions[$key], $length));
            $record = is_string($json) ? json_decode($json, true) : null;
            if(!is_array($record) || !isset($record['key']) || $record['key'] !== (int)$key){
                $mismatch = true;
                return [];
            }
            return [$key => $record];
        }

        $records = [];
        $bufferSize = 65536;
        $buffer = '';
        $bufferStart = -1;
        $bufferEnd = -1;

        // Read in file order. Appends keep the index in that order; updated records moved to the end.
        $inOrder = true;
        $previous = -1;
        foreach($positions as $position){
            if($position < $previous){
                $inOrder = false;
                break;
            }
            $previous = $position;
        }
        if(!$inOrder){
            asort($positions, SORT_NUMERIC);
        }

        // Records within one span of at most SPAN bytes (the keys of a small segment, a region): the span in
        // one call. Otherwise records close together on average (a scan): 64 KB at a time; sparse ones: 64 KB
        // when the next record ends within them, else the record alone (look-ahead in read order)
        $first = (int)reset($positions);
        $lastKey = array_key_last($positions);
        $spanEnd = (int)$positions[$lastKey] + (int)($lengths[$lastKey] ?? 0);
        $handle = null;
        if($held !== null && $spanEnd <= strlen($held)){
            $buffer = $held;
            $bufferStart = 0;
            $bufferEnd = strlen($held);
        } elseif($spanEnd - $first <= self::SPAN){
            $buffer = $spanEnd > $first ? @file_get_contents($path, false, null, $first, $spanEnd - $first) : '';
            if(!is_string($buffer)){
                $mismatch = true;
                return [];
            }
            $bufferStart = $first;
            $bufferEnd = $first + strlen($buffer);
        } else {
            $handle = @fopen($path, 'rb');
            if($handle === false){
                $mismatch = true;
                return [];
            }
            $fileEnd = (int)(fstat($handle)['size'] ?? 0);
        }
        $dense = (int)end($positions) - $first <= ($bufferSize >> 2) * (count($positions) - 1);
        $keys = $dense ? null : array_keys($positions);
        $i = 0;
        foreach($positions as $key => $offset){
            $offset = (int)$offset;
            $length = (int)($lengths[$key] ?? 0);
            if($length <= 0){
                $mismatch = true;
                $i++;
                continue;
            }

            if($offset >= $bufferStart && ($offset + $length) <= $bufferEnd){
                $json = substr($buffer, $offset - $bufferStart, $length);
            } elseif($handle === null || $offset + $length > $fileEnd){
                $mismatch = true; // the record ends past what was read: the data is shorter than its index
                $i++;
                continue;
            } else {
                if($dense){
                    $near = true;
                } else {
                    $next = $keys[$i + 1] ?? null;
                    $near = $next !== null && (int)$positions[$next] + (int)($lengths[$next] ?? 0) <= $offset + $bufferSize;
                }
                fseek($handle, $offset, SEEK_SET);
                $buffer = (string)fread($handle, $near ? max($length, $bufferSize) : $length);
                $bufferStart = $offset;
                $bufferEnd = $offset + strlen($buffer);
                $json = substr($buffer, 0, $length);
            }

            if(!$dense){
                $i++;
            }
            $record = json_decode((string)$json, true);
            if(!is_array($record) || !isset($record['key']) || $record['key'] !== (int)$key){
                $mismatch = true;
                continue;
            }
            $records[$key] = $record;
        }

        if($handle !== null){
            fclose($handle);
        }
        return $records;
    }

    /** Records per batch of a read over a whole segment: the default shard size (such a segment is read at once) */
    public const BATCH = 10000;

    /**
     * The records of a segment ($keys of it; every record when null) in batches of at most BATCH records, each
     * [key => record] sorted by key, the batches in the order of $keys (every record: ascending). An operation over a
     * segment of any size holds one batch (docs/CONFIGURATION.md "Memory of operations over many segments").
     * A segment that fits one batch: an array of that batch (nothing held, a read as it always was); a larger one: a
     * generator. Iterated by reference, a caller that writes between batches sets its variable to null first, so
     * the records it doesn't keep are released, not held through the write.
     * @return iterable<int, array>
     */
    public function eachSegmentBatch($dbname, $shardId, $keys = null, $index = null){
        $index = $index ?? ($keys === null ? $this->checkpoints->decodedIndex($dbname, $shardId) : $this->checkpoints->readJsonlIndex($dbname, $shardId));
        if($index === null){
            return [];
        }
        if(($keys === null ? KeyIndex::count($index) : count($keys)) <= self::BATCH){
            $records = $this->readSegmentRecords($dbname, $shardId, $keys, $index);
            return empty($records) ? [] : [$records];
        }
        // Larger than a batch: the index's arrays once (a body unpacked in a few C-level calls, as a whole read
        // does), then the keys a batch at a time
        return $this->segmentBatches($dbname, $shardId, $keys, KeyIndex::materialize($index));
    }

    /**
     * The batches of a segment larger than one (eachSegmentBatch()), yielded by reference. The writes of a caller
     * between two batches touch only keys it was given: $index still holds the keys of the batches to come
     * @return \Generator<int, array>
     */
    private function &segmentBatches($dbname, $shardId, $keys, array $index){
        if($keys === null){
            $keys = array_keys($index['p']);
            sort($keys, SORT_NUMERIC);
        }
        foreach(array_chunk($keys, self::BATCH) as $chunk){
            $records = $this->readSegmentRecords($dbname, $shardId, $chunk, $index);
            if(!empty($records)){
                yield $records;
            }
        }
    }

    /**
     * Read records of one segment, verified against their keys. A stale or corrupt index is
     * rebuilt from the data file once.
     * @param array|null $keys Keys to read (null = all)
     * @param array|null $index The segment's key index the caller just read (null: read here)
     * @return array [key => record] sorted by key
     */
    public function readSegmentRecords($dbname, $shardId, $keys = null, $index = null){
        $this->segmentReads++; // @test
        for($attempt = 0; $attempt < 2; $attempt++){
            $index = $attempt > 0 ? $this->checkpoints->repairSegmentIndex($dbname, $shardId, true)
                : ($index ?? ($keys === null ? $this->checkpoints->decodedIndex($dbname, $shardId) : $this->checkpoints->readJsonlIndex($dbname, $shardId)));
            if($index === null){
                return [];
            }
            if($keys !== null && $index['kb'] === ''){
                // A decoded index (the hot path): its arrays, no call per key; the lengths as they are
                $positions = [];
                foreach($keys as $key){
                    if(isset($index['p'][$key])){
                        $positions[$key] = $index['p'][$key];
                    }
                }
                $lengths = $index['l'];
            } else {
                [$positions, $lengths] = KeyIndex::locate($index, $keys);
            }
            $dataPath = $index['dp'] ?? $this->segments->segDataPath($dbname, $shardId);
            if($keys !== null && $index['kb'] !== '' && count($keys) >= 16){
                $this->checkpoints->noteLookups($dataPath, count($keys)); // bulk lookups (operations count in readJsonlIndex())
            }
            if($keys === null && (int)$index['d'] === 0 && (int)$index['t'] === 0 && ($records = $this->liveLines($dataPath, $positions, (int)$index['s'])) !== null){
                $this->indexStats['liveReads']++; // @test
                return $records;
            }
            $mismatch = false;
            $held = null;
            if(empty($index['few']) && ($this->sessionLimit ?? ($this->sessionLimit = \noneDB\Internal\Cache\CacheManager::cacheLimitBytes())) > 0){
                $held = $this->heldData($dbname, $dataPath, $index);
            }
            $records = $this->readJsonlRecordsBatch($dataPath, $positions, $lengths, $mismatch, $held);
            if(!$mismatch){
                ksort($records, SORT_NUMERIC);
                return $records;
            }
            unset($this->fieldIndexCache[$dataPath]);
        }
        throw new \RuntimeException("noneDB: data file of database '{$dbname}' is corrupt and could not be repaired");
    }

    /**
     * Every record of a segment without garbage, sorted by key: its lines in file order are its records, so they are
     * split at once and not cut out by position (about a tenth of such a read), 64 KB at a time. Each line is
     * checked against the index as a read by position is. Null when one doesn't fit: the read by position decides.
     * @param array $positions [key => offset] of every live key
     * @param int $size The bytes the index covers
     * @return array|null [key => record]
     */
    private function liveLines($path, array $positions, $size){
        $this->indexStats['liveTries']++; // @test
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return null;
        }
        $records = [];
        $carry = '';
        $at = 0;
        $last = -1;
        $sorted = true;
        for($left = $size; $left > 0; $left -= strlen($chunk)){
            $chunk = (string)fread($fp, $left < 65536 ? $left : 65536);
            if($chunk === ''){
                break;
            }
            $lines = explode("\n", $carry . $chunk);
            $carry = array_pop($lines);
            foreach($lines as $line){
                $record = json_decode($line, true);
                $key = $record['key'] ?? null;
                if(!is_int($key) || ($positions[$key] ?? -1) !== $at){
                    fclose($fp);
                    return null;
                }
                $records[$key] = $record;
                $sorted = $sorted && $key > $last;
                $last = $key;
                $at += strlen($line) + 1;
            }
        }
        fclose($fp);
        if(count($records) !== count($positions)){
            return null;
        }
        if(!$sorted){
            ksort($records, SORT_NUMERIC);
        }
        return $records;
    }

    /** A record read from a data file costs an open, a seek and a read: about what reading this many of its bytes at once does */
    private const HELD_BYTES_PER_USE = 131072;

    /**
     * The bytes of a data file in repeated use, held whole within the cache's budget: a record is then a substr(),
     * not an open (§ "Memory" of docs/ARCHITECTURE.md). The caller asks for a database of many segments, in a process
     * with a limit; the bytes are read once the operations have paid for it, under the shared lock: bytes no rollback
     * takes back. They stay while the key index keeps its epoch (the bytes it covers can't change within one), newer
     * lines come from the file, and they are dropped like any cached index.
     * @return string|null
     */
    private function heldData($dbname, $dataPath, array $index){
        $entry = $this->fieldIndexCache[$dataPath] ?? null;
        if($entry !== null){
            if($entry['e'] === $index['e']){
                $this->cacheTouch[$dataPath] = ++$this->cacheTick;
                $this->indexStats['heldReads']++; // @test
                return $entry['raw'];
            }
            unset($this->fieldIndexCache[$dataPath]); // the file was replaced
        }
        $size = (int)$index['s'];
        if($index['e'] === null || $index['uses'] < 2 || $index['uses'] * self::HELD_BYTES_PER_USE < $size
            || ($this->lockRegistry[$this->locks->getLockPath($dbname)]['mode'] ?? null) !== LOCK_SH
            || !\noneDB\Internal\Cache\CacheManager::decodeFits(2 * $size)){
            return null;
        }
        $raw = @file_get_contents($dataPath, false, null, 0, $size);
        if(!is_string($raw) || strlen($raw) !== $size){
            return null;
        }
        $this->indexStats['dataHeld']++; // @test
        $this->fieldIndexCache[$dataPath] = ['kind' => 'data', 'e' => $index['e'], 'raw' => $raw];
        $this->cacheTouch[$dataPath] = ++$this->cacheTick;
        $this->cacheGrew = true;
        return $raw;
    }

    /**
     * Append records ([key => record]) to a segment and point the index at them.
     * Everything is encoded before anything is written, so an unencodable record writes nothing.
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function appendSegmentRecords($dbname, $shardId, array &$index, array $records){
        if(empty($records)){
            return;
        }
        $lines = $this->io->withExactFloats(function() use ($records){
            $lines = [];
            foreach($records as $key => $record){
                unset($record['key']);
                $record['key'] = (int)$key;
                $lines[$key] = $this->io->encodeRecord($record);
            }
            return $lines;
        });
        // After encoding: a record that can't be encoded leaves no journal entry behind
        $this->locks->markIndexesPending($dbname, $shardId);
        $buffer = implode("\n", $lines) . "\n";
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        $offset = $this->io->appendToFile($dataPath, $buffer, $dbname, $index['s'] ?? null, $shardId);
        if($offset === false){
            $this->failedAppend($dbname, $shardId);
        }
        $index['s'] = $offset + strlen($buffer);
        $this->cache->markFileExists($dataPath);
        $index['d'] += KeyIndex::setLines($index, $lines, $offset); // a replaced key's old line is garbage now
        $index['tl'] = (int)$index['tl'] + count($lines);
    }

    /**
     * Append tombstones for deleted keys, so an index rebuilt from the data won't resurrect them
     */
    public function appendTombstones($dbname, $shardId, array &$index, array $keys){
        if(empty($keys)){
            return;
        }
        $this->locks->markIndexesPending($dbname, $shardId);
        $buffer = '';
        foreach($keys as $key){
            $buffer .= '{"__nonedb_deleted":' . (int)$key . "}\n";
        }
        $offset = $this->io->appendToFile($this->segments->segDataPath($dbname, $shardId), $buffer, $dbname, $index['s'] ?? null, $shardId);
        if($offset === false){
            $this->failedAppend($dbname, $shardId);
        }
        $index['s'] = $offset + strlen($buffer);
        $index['d'] += KeyIndex::removeMany($index, $keys); // the old record lines
        $index['t'] = (int)$index['t'] + count($keys); // tombstone lines
        $index['tl'] = (int)$index['tl'] + count($keys);
    }

    /**
     * An append to a segment wrote nothing: throw. When the data file didn't have the size the index
     * covers (it changed behind this process's back), the cached index is dropped as well.
     * @throws \RuntimeException
     */
    private function failedAppend($dbname, $shardId){
        if($this->appendMismatch){
            $path = $this->checkpoints->getJsonlIndexPath($dbname, $shardId);
            unset($this->indexCache[$path], $this->verifiedIndexes[$path], $this->writableIndexes[$path]);
            throw new \RuntimeException("noneDB: data file of database '{$dbname}' changed unexpectedly; nothing was written, its index is rebuilt on the next access");
        }
        throw new \RuntimeException("noneDB: failed to write data of database '{$dbname}'");
    }

    /**
     * Create an empty segment (data file + index)
     * @param string $dbname
     * @param int|null $shardId
     * @return bool
     */
    public function createJsonlDatabase($dbname, $shardId = null){
        $path = $this->segments->segDataPath($dbname, $shardId);
        clearstatcache(true, $path);
        if(!file_exists($path) && !$this->io->fsTouch($path)){
            throw new \RuntimeException("noneDB: cannot create data file of database '{$dbname}'");
        }
        $this->cache->markFileExists($path);
        $index = $this->checkpoints->newJsonlIndex();
        $index['e'] = bin2hex(random_bytes(4));
        $this->indexCache[\noneDB\Internal\Storage\Checkpoints::ckptPath($path)] = $index;
        $this->verifiedIndexes[\noneDB\Internal\Storage\Checkpoints::ckptPath($path)] = true;
        // No checkpoint yet (ck -1): the session writes one when it ends, with the first records (until then
        // the new segment replays from 0, which is the same index)
        $this->lockRegistry[$this->locks->getLockPath($dbname)]['touched'][$shardId === null ? 'main' : $shardId] = $shardId;
        return true;
    }
}
