<?php
// Secondary index files: names, headers, checkpoints, data tails (docs/STORAGE_FORMAT.md §6.5)
namespace noneDB\Internal\Index;

use function basename, clearstatcache, count, fclose, fgets, fopen, fread, fseek, hash, intdiv, is_array,
    is_int, is_string, json_decode, max, min, preg_match, preg_replace, rtrim, stream_get_contents, strlen,
    strpos, strtolower, substr;
use const LOCK_EX, LOCK_SH;

/**
 * @internal
 */
final class SecondaryFiles {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $currentGenerations;
    private $fieldIndexCache;
    private $genPaths;
    private $indexCache;
    private $lockPaths;
    private $spatialIndexCache;
    private $verifiedIndexes;
    private $indexTokens;
    private $lockRegistry;
    private $metrics;
    private $multiLock;
    private $noWrites;
    private $secondaryChecked;
    private $tailCache;
    private $cache;
    private $checkpoints;
    private $fieldIndex;
    private $io;
    private $layout;
    private $locks;
    private $rtree;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->fieldIndex, $this->io, $this->layout, $this->locks, $this->rtree, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->currentGenerations = &$this->k->currentGenerations;
        $this->fieldIndexCache = &$this->k->fieldIndexCache;
        $this->genPaths = &$this->k->genPaths;
        $this->indexCache = &$this->k->indexCache;
        $this->lockPaths = &$this->k->lockPaths;
        $this->spatialIndexCache = &$this->k->spatialIndexCache;
        $this->verifiedIndexes = &$this->k->verifiedIndexes;
        $this->indexTokens = &$this->rt->indexTokens;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->metrics = &$this->rt->metrics;
        $this->multiLock = &$this->rt->multiLock;
        $this->noWrites = &$this->rt->noWrites;
        $this->secondaryChecked = &$this->rt->secondaryChecked;
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
        $this->cache = null;
        $this->checkpoints = null;
        $this->fieldIndex = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->rtree = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * File name part of an index on $field (at most 40 bytes)
     */
    public function indexToken($field){
        $field = (string)$field;
        if(isset($this->indexTokens[$field])){
            return $this->indexTokens[$field];
        }
        $token = preg_match('/^[a-z0-9_]{1,40}$/D', $field) === 1 ? $field
            : substr(strtolower(preg_replace('/[^A-Za-z0-9_]/', '_', $field)), 0, 24) . '-' . substr(hash('sha256', $field), 0, 12);
        if(count($this->indexTokens) > 4096){
            $this->indexTokens = [];
        }
        return $this->indexTokens[$field] = $token;
    }

    /**
     * Path of a secondary index file: 'fx' / 'rx' per segment, 'gfx' / 'grx' per database
     */
    public function secondaryPath($dbname, $kind, $field, $shardId = null){
        $k = $shardId ?? -1;
        $paths = $this->genPaths[$dbname] ?? null;
        if(isset($paths['x'][$kind][$field][$k]) && $paths['gen'] === ($this->currentGenerations[$this->lockPaths[$dbname]] ?? null)){
            return $paths['x'][$kind][$field][$k];
        }
        $dir = $this->layout->genDir($dbname);
        if(isset($this->genPaths[$dbname]['x'][$kind][$field][$k])){
            return $this->genPaths[$dbname]['x'][$kind][$field][$k];
        }
        $dir .= ($kind === 'fx' || $kind === 'gfx' ? 'indexes/' : 'spatial/');
        $path = $kind === 'gfx' || $kind === 'grx' ? $dir . $this->indexToken($field) . '.' . $kind
            : $dir . \noneDB\Internal\Storage\Layout::segmentName($shardId) . '.' . $this->indexToken($field) . '.' . $kind;
        return $this->genPaths[$dbname]['x'][$kind][$field][$k] = $path;
    }

    /**
     * The key index of a segment for a secondary index check: the cached one when it is known to cover
     * the data file (no function call chain on the hot path), else readJsonlIndex()
     */
    public function currentKeyIndex($dbname, $shardId){
        $path = $this->checkpoints->getJsonlIndexPath($dbname, $shardId);
        if(isset($this->verifiedIndexes[$path], $this->indexCache[$path])){
            $this->metrics['hits']++;
            return $this->indexCache[$path];
        }
        return $this->checkpoints->readJsonlIndex($dbname, $shardId);
    }

    /**
     * [header, body] of a secondary index file; null when it is missing or its header is unreadable
     */
    public function readSecondaryFile($path){
        clearstatcache(true, $path);
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return null;
        }
        $line = fgets($fp);
        $body = $line === false ? '' : (string)stream_get_contents($fp);
        fclose($fp);
        $this->cache->noteIndexRead(($line === false ? 0 : strlen($line)) + strlen($body));
        $header = $line === false ? null : \noneDB\Internal\Storage\KeyIndex::openHeader($line);
        return is_array($header) ? [$header, $body] : null;
    }

    /**
     * Only the header line of a secondary index file (null: missing or unreadable)
     */
    public function readSecondaryHeader($path){
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return null;
        }
        $line = fgets($fp);
        fclose($fp);
        $this->cache->noteIndexRead($line === false ? 0 : strlen($line));
        return $line === false ? null : \noneDB\Internal\Storage\KeyIndex::openHeader($line);
    }

    /**
     * Write a secondary index file (header line + body). A checkpoint: no generation bump, its content is
     * the same whoever writes it.
     * @throws \RuntimeException
     */
    public function writeSecondaryFile($dbname, $path, array $header, $body){
        $line = $this->io->encodeHeader($header);
        if($line === false || !$this->io->writeDerivedFile($path, $line . "\n" . $body)){
            throw new \RuntimeException("noneDB: failed to write index " . basename($path) . " of database '{$dbname}'");
        }
        $this->cache->markFileExists($path);
    }

    /**
     * The segment's key index, with an epoch: an index without one (rebuilt without a checkpoint) gets its
     * checkpoint written first (exclusive lock), since secondary indexes name the epoch they belong to
     * @return array|null
     */
    public function keyIndexWithEpoch($dbname, $shardId){
        $index = $this->checkpoints->readJsonlIndex($dbname, $shardId);
        if($index === null || $index['e'] !== null){
            return $index;
        }
        $lock = $this->locks->getLockPath($dbname);
        if(!isset($this->lockRegistry[$lock]) || $this->lockRegistry[$lock]['mode'] !== LOCK_EX){
            return $index;
        }
        $this->checkpoints->writeCheckpoint($dbname, $shardId, $index);
        return $this->checkpoints->readJsonlIndex($dbname, $shardId);
    }

    /**
     * True when a secondary index header belongs to the segment as it is: same field, the epoch of its
     * key index, and the bytes it covers unchanged (checked once per epoch and offset)
     */
    public function secondaryFits($dbname, $shardId, array $header, $kind, $field){
        if(($header['kind'] ?? null) !== $kind || ($header['field'] ?? null) !== (string)$field
            || !isset($header['s'], $header['e'], $header['h']) || !is_int($header['s']) || $header['s'] < 0 || !is_string($header['e']) || !is_string($header['h'])){
            return false;
        }
        $index = $this->checkpoints->readJsonlIndex($dbname, $shardId);
        if($index === null || $index['e'] === null || $header['e'] !== $index['e'] || $header['s'] > (int)$index['s']){
            return false;
        }
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        $checked = $index['e'] . ':' . $header['s'] . ':' . $header['h']; // the checksum too: another file may cover the same bytes
        if(($this->secondaryChecked[$dataPath] ?? null) === $checked){
            return true;
        }
        // The bytes the key index checkpoint covers had their crc checked when it was loaded or written
        $known = $header['s'] === (int)$index['ck'] ? ($index['ckh'] ?? null) : null;
        if(($known ?? $this->checkpoints->segmentCrc($dataPath, $header['s'])) !== $header['h']){
            return false;
        }
        $this->secondaryChecked[$dataPath] = $checked;
        return true;
    }

    /**
     * Records of the complete data lines [$from, $to) of a segment, in file order: [[key, record or null
     * for a tombstone], ...]. Lines that are neither are skipped (salvaged ones count, as in the key index).
     */
    public function segmentTail($dbname, $shardId, $from, $to){
        if($to <= $from){
            return [];
        }
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        $cached = $this->tailCache[$dataPath] ?? null;
        if($cached !== null && $cached[0] === $from && $cached[1] === $to){
            return $cached[2];
        }
        $fp = @fopen($dataPath, 'rb');
        if($fp === false){
            return [];
        }
        fseek($fp, $from);
        $bytes = '';
        while(strlen($bytes) < $to - $from){
            $chunk = fread($fp, min(1048576, $to - $from - strlen($bytes)));
            if($chunk === false || $chunk === ''){
                break;
            }
            $bytes .= $chunk;
        }
        fclose($fp);
        $base = $shardId === null ? 0 : $shardId * $this->segments->shardSizeOf($dbname);
        $limit = $shardId === null ? null : $base + $this->segments->shardSizeOf($dbname);
        $rows = [];
        $pos = 0;
        $empty = [];
        while(($newline = strpos($bytes, "\n", $pos)) !== false){
            $json = substr($bytes, $pos, $newline - $pos);
            $pos = $newline + 1;
            $record = json_decode($json, true);
            if(is_array($record) && count($record) === 1 && isset($record['__nonedb_deleted']) && is_int($record['__nonedb_deleted'])){
                $rows[] = [$record['__nonedb_deleted'], null];
                continue;
            }
            if(is_array($record) && isset($record['key']) && is_int($record['key'])){
                $rows[] = [$record['key'], $record];
                continue;
            }
            $entry = $this->checkpoints->salvageLine($json, $from + $pos - strlen($json) - 1, $empty, $base, $limit);
            if($entry !== null){
                $rows[] = $entry[0] === 't' ? [$entry[1], null] : [$entry[1], json_decode(substr($json, $entry[2], $entry[3]), true)];
            }
        }
        if(count($this->tailCache) > 64){
            $this->tailCache = [];
        }
        $this->tailCache[$dataPath] = [$from, $to, $rows];
        return $rows;
    }

    /**
     * A global index (gfx / grx) about to be rewritten, which stamps it with the current lock epoch: when
     * it was written under another epoch (a data file may have been replaced since), only the entries that
     * still fit their shard are kept, so the new stamp vouches for no stale entry
     */
    public function freshGlobalEntries($dbname, array $global){
        $lockPath = $this->locks->getLockPath($dbname);
        if($global['le'] !== null && isset($this->lockRegistry[$lockPath]) && $global['le'] === $this->locks->registryEpoch($lockPath)){
            return $global;
        }
        foreach($global['sh'] as $shardId => $entry){
            if($this->fieldIndex->globalEntryFits($dbname, $shardId, $entry, null)){
                continue;
            }
            unset($global['sh'][$shardId], $global['cols'][$shardId]);
        }
        return $global;
    }

    /**
     * True when the tail of a secondary index (data after the bytes it covers) is long enough to write a
     * new checkpoint of it
     */
    public function secondaryTailIsLong($covered, $end, $fileBytes){
        return $end - $covered >= max(self::SECONDARY_TAIL_BYTES, intdiv((int)$fileBytes, 4));
    }

    /**
     * True for an entry of a global index: it names the bytes of a shard's segment index that it describes (§6.5)
     */
    public static function isEntry($entry){
        return is_int($entry['s'] ?? null) && $entry['s'] >= 0 && is_string($entry['e'] ?? null) && is_string($entry['h'] ?? null);
    }

    /**
     * A reader that loaded a secondary index and replayed a long tail writes a new checkpoint of it (and of
     * its shard's global entry), so the next process doesn't replay the same lines again. Under the shared
     * lock only: never inside a write session (whose appends a rollback may still cut), not for
     * checkIntegrity(), explain() or a snapshot. Best effort, no generation bump: the content is a function
     * of the data.
     * @return bool True when one is due but was put off (checkIntegrity(), explain(), a join): the caller
     *              marks the cached index ("due") and the next plain read of this process writes it
     */
    public function maybeSecondaryReaderCheckpoint($dbname, $shardId, $kind, $field, array $index){
        $lock = $this->locks->getLockPath($dbname);
        if($index['e'] === null
            || !$this->secondaryTailIsLong((int)$index['ck'], (int)$index['s'], (int)$index['bytes'])){
            return false;
        }
        if($this->noWrites > 0 || $this->multiLock > 0){
            return true; // explain(), checkIntegrity(), a join: due, the next plain read of this process writes it
        }
        if(!isset($this->lockRegistry[$lock]) || $this->lockRegistry[$lock]['mode'] !== LOCK_SH){
            return false; // a write session: checkpointSegmentSecondaries() at its end
        }
        try {
            if($kind === 'fx'){
                $this->fieldIndex->writeFieldIndex($dbname, $field, $index, $shardId);
                if($shardId !== null){
                    $this->fieldIndex->updateGlobalFieldShard($dbname, $field, $shardId, $index);
                }
            } else {
                $this->rtree->writeSpatialIndex($dbname, $field, $index, $shardId);
                if($shardId !== null){
                    $this->rtree->updateGlobalSpatialShard($dbname, $field, $shardId, $index);
                }
            }
        } catch(\Throwable $e){
            if($e->getCode() === \noneDB::LOCK_FAILED){
                throw $e;
            }
            // never needed for correctness: the next load replays the lines again
        }
        return false;
    }

    /**
     * End of a write session: write new checkpoints of the field and spatial indexes of a changed segment
     * that this process has loaded and whose tail is long, and their global shard summaries (exclusive
     * lock, best effort). An index nobody here uses isn't loaded for it: a writer that only writes keeps
     * no secondary index in memory, and the next reader that loads it writes the checkpoint.
     */
    public function checkpointSegmentSecondaries($dbname, $shardId){
        $manifest = $this->fieldIndex->getIndexManifest($dbname);
        if(empty($manifest['fields']) && empty($manifest['spatial'])){
            return;
        }
        $key = $this->keyIndexWithEpoch($dbname, $shardId);
        if($key === null || $key['e'] === null){
            return;
        }
        foreach(['fx' => $manifest['fields'], 'rx' => $manifest['spatial']] as $kind => $fields){
            foreach($fields as $field){
                $path = $this->secondaryPath($dbname, $kind, $field, $shardId);
                $cached = $kind === 'fx' ? ($this->fieldIndexCache[$path] ?? null) : ($this->spatialIndexCache[$path] ?? null);
                if($cached === null && $kind === 'fx' && isset($this->fieldIndexCache[$path . '#pg'])){
                    // used here through point lookups (pages of its file): in use like a loaded one
                    $paged = $this->fieldIndexCache[$path . '#pg'];
                    $cached = ['e' => $paged['h']['e'] ?? null, 'ck' => (int)$paged['h']['s'], 'bytes' => $paged['size']];
                }
                if($cached === null || $cached['e'] !== $key['e']){
                    continue; // not used here (or of another epoch): the reader that uses it checkpoints it
                }
                $covered = (int)$cached['ck'];
                $bytes = (int)$cached['bytes'];
                if(!$this->secondaryTailIsLong($covered, (int)$key['s'], $bytes)){
                    continue;
                }
                try {
                    if($kind === 'fx'){
                        $index = $this->fieldIndex->ensureSegmentFieldIndex($dbname, $shardId, $field);
                        if($index !== null){
                            $this->fieldIndex->writeFieldIndex($dbname, $field, $index, $shardId);
                            if($shardId !== null){
                                $this->fieldIndex->updateGlobalFieldShard($dbname, $field, $shardId, $index);
                            }
                        }
                    } else {
                        $index = $this->rtree->ensureSegmentSpatialIndex($dbname, $field, $shardId);
                        $this->rtree->writeSpatialIndex($dbname, $field, $index, $shardId);
                        if($shardId !== null){
                            $this->rtree->updateGlobalSpatialShard($dbname, $field, $shardId, $index);
                        }
                    }
                } catch(\Throwable $e){
                    if($e->getCode() === \noneDB::LOCK_FAILED){
                        throw $e;
                    }
                    // never needed for correctness: the next load replays a longer tail
                }
            }
        }
    }
    // ==========================================
    // SECONDARY INDEX FILES
    // ==========================================
    // A field index (.fx), an R-tree (.rx) and the global shard summaries (.gfx, .grx) are checkpoints
    // of their segment's data, like the key index: line 1 of the file is a header with the field, the
    // data bytes "s" it covers, the crc32b "h" of the bytes before s and the epoch "e" of the key index it
    // was made with; the body follows. An index is a function of the data alone: loading it replays the
    // data lines after s (in memory), so writes never touch it, and a crash, a rollback or another
    // process's writes can't leave it stale. Once its tail reached 64 KiB (or a quarter of the index file),
    // the process that uses it writes a new checkpoint: a reader that loaded it, or a write session that
    // has it loaded (a process that only writes never loads it). An index whose epoch or bytes don't fit
    // the segment is rebuilt from the data (exclusive lock).
    // Names (in the active generation): indexes/{seg}.{token}.fx, indexes/{token}.gfx,
    // spatial/{seg}.{token}.rx, spatial/{token}.grx; the token is the field when it is [a-z0-9_]{1,40},
    // else a lower-case prefix and 12 hex digits of its sha256: at most 40 bytes, and fields that differ
    // only in case ("city", "City") never share a file.

    public const SECONDARY_TAIL_BYTES = 65536;
}
