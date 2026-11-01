<?php
// Field indexes and global field indexes
namespace noneDB\Internal\Index;

use noneDB\Internal\Storage\Segments;

use function abs, array_fill_keys, array_flip, array_intersect, array_intersect_key, array_key_exists, array_keys,
    array_map, array_merge, array_multisort, array_search, array_slice, array_sum, array_unique, array_values,
    basename, bin2hex, chr, clearstatcache, count, end, fclose, fgets, file_exists, file_get_contents, filesize, fopen,
    fread, fseek, fstat, hash, hex2bin, in_array, intdiv, is_array, is_file, is_float, is_int, is_nan, is_null, is_numeric,
    is_resource, is_scalar, is_string, json_decode, ksort, max, memory_get_usage, min, preg_match, random_bytes, sort,
    str_repeat, strcmp, strlen, strncmp, strpos, substr, time, unpack;
use const PHP_SAPI, SORT_NUMERIC, SORT_STRING;

/**
 * @internal
 */
final class FieldIndex {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $dataEpochs;
    private $dataSizes;
    private $fieldIndexCache;
    private $fieldIndexEnabled;
    private $globalSkip;
    private $globalSpatialIndexCache;
    private $indexCache;
    private $segWrites;
    private $verifiedIndexes;
    private $cacheGrew;
    private $cacheTick;
    private $cacheTouch;
    private $indexStats; // @test
    private $indexTokens;
    private $lockRegistry;
    private $metrics;
    private $multiLock;
    private $noWrites;
    private $secondaryChecked;
    private $sessionCounter;
    private $cache;
    private $checkpoints;
    private $io;
    private $layout;
    private $locks;
    private $records;
    private $rtree;
    private $secondary;
    private $segments;
    private $shardMeta;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->io, $this->layout, $this->locks, $this->records, $this->rtree, $this->secondary, $this->segments, $this->shardMeta);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dataEpochs = &$this->k->dataEpochs;
        $this->dataSizes = &$this->k->dataSizes;
        $this->fieldIndexCache = &$this->k->fieldIndexCache;
        $this->fieldIndexEnabled = &$this->k->fieldIndexEnabled;
        $this->globalSkip = &$this->k->globalSkip;
        $this->globalSpatialIndexCache = &$this->k->globalSpatialIndexCache;
        $this->indexCache = &$this->k->indexCache;
        $this->segWrites = &$this->k->segWrites;
        $this->verifiedIndexes = &$this->k->verifiedIndexes;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->sessionCounter = &$this->rt->sessionCounter;
        $this->cacheTick = &$this->rt->cacheTick;
        $this->cacheTouch = &$this->rt->cacheTouch;
        $this->indexStats = &$this->rt->indexStats; // @test
        $this->indexTokens = &$this->rt->indexTokens;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->metrics = &$this->rt->metrics;
        $this->multiLock = &$this->rt->multiLock;
        $this->noWrites = &$this->rt->noWrites;
        $this->secondaryChecked = &$this->rt->secondaryChecked;
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
        $this->records = null;
        $this->rtree = null;
        $this->secondary = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // FIELD INDEXES
    // ==========================================
    // Which fields are indexed is recorded in indexes.json of the active generation
    // ({"kind":"indexes","v":1,"fields","spatial","spatialOptions"}). A segment's field index
    // indexes/{seg}.{token}.fx (paged, docs/STORAGE_FORMAT.md §6.5.1) maps the exact identity of a value
    // (valueKey(): "n", "t", "f", "i42", "d<hex double>", "sAnkara") to the keys that hold it; in memory:
    //   ['b' => ['n' => [2, 4], 'f' => 10, 'i-3' => [1, 9], 'i42' => 7, 'sAnkara' => 3, 'sIstanbul' => [0, 5, 9]], 's', 'e', ...]
    // Buckets are exact (1, 1.0, "1" and true are different values, as for ===) and don't depend on the
    // precision, serialize_precision or locale settings. Values that are arrays are not indexed. The
    // global field index indexes/{token}.gfx of a sharded database holds a filter of each shard's values as
    // of its covered bytes (§6.5.2); a shard's newer lines are checked directly.

    public function getIndexManifestPath($dbname){
        return $this->layout->genPath($dbname, 'indexes.json');
    }

    /**
     * Indexed fields of a database: ['fields', 'spatial', 'spatialOptions']. No file: no indexes; a file
     * that doesn't decode (zero-length after a power loss) is derived from the index files' headers.
     */
    public function getIndexManifest($dbname){
        $path = $this->getIndexManifestPath($dbname);
        if(isset($this->fieldIndexCache[$path])){
            $this->cacheTouch[$path] = ++$this->cacheTick;
            return $this->fieldIndexCache[$path];
        }
        clearstatcache(true, $path);
        $content = is_file($path) ? @file_get_contents($path) : false;
        $manifest = $content === false ? [] : json_decode($content, true);
        if(!is_array($manifest) || ($content !== false && ($manifest['kind'] ?? null) !== 'indexes')){
            $manifest = $this->deriveIndexManifest($dbname);
        }
        $manifest = [
            'fields' => array_values(array_unique(array_map('strval', $manifest['fields'] ?? []))),
            'spatial' => array_values(array_unique(array_map('strval', $manifest['spatial'] ?? []))),
            'spatialOptions' => is_array($manifest['spatialOptions'] ?? null) ? $manifest['spatialOptions'] : [],
        ];
        $this->fieldIndexCache[$path] = $manifest;
        $this->cacheGrew = true;
        return $manifest;
    }

    public function saveIndexManifest($dbname, array $manifest){
        $file = [
            'kind' => 'indexes',
            'v' => 1,
            'fields' => array_values(array_unique($manifest['fields'] ?? [])),
            'spatial' => array_values(array_unique($manifest['spatial'] ?? [])),
        ];
        $file['spatialOptions'] = array_intersect_key($manifest['spatialOptions'] ?? [], array_flip($file['spatial']));
        $path = $this->getIndexManifestPath($dbname);
        if(empty($file['fields']) && empty($file['spatial'])){
            $this->io->fsUnlink($path);
        } elseif(!$this->io->atomicWrite($path, $file, false, 'durable')){
            throw new \RuntimeException("noneDB: failed to write index list of database '{$dbname}'");
        }
        $this->fieldIndexCache[$path] = ['fields' => $file['fields'], 'spatial' => $file['spatial'],
            'spatialOptions' => $file['spatialOptions']];
    }

    /**
     * An index list that doesn't decode: the fields named in the headers of the generation's .fx/.rx files
     */
    public function deriveIndexManifest($dbname){
        $fields = [];
        $spatial = [];
        $options = [];
        $gen = $this->layout->genDir($dbname);
        foreach(array_merge($this->segments->listFilesWithPrefix($gen . 'indexes/', '/\.fx$/'), $this->segments->listFilesWithPrefix($gen . 'spatial/', '/\.rx$/')) as $file){
            $header = $this->secondary->readSecondaryHeader($file);
            if(!is_array($header) || !isset($header['field']) || !is_string($header['field'])){
                continue;
            }
            if(substr($file, -3) === '.fx'){
                $fields[] = $header['field'];
            } else {
                $spatial[] = $header['field'];
                if(isset($header['ns'])){
                    $options[$header['field']] = ['nodeSize' => (int)$header['ns']];
                }
            }
        }
        return ['fields' => array_values(array_unique($fields)), 'spatial' => array_values(array_unique($spatial)), 'spatialOptions' => $options];
    }

    /**
     * Path of a segment's field index
     */
    public function getFieldIndexPath($dbname, $field, $shardId = null){
        return $this->secondary->secondaryPath($dbname, 'fx', $field, $shardId);
    }

    /**
     * True if a value can be stored in a field index
     */
    public function isIndexableValue($value){
        return is_scalar($value) || is_null($value);
    }

    /**
     * An empty field index. Besides its buckets 'b' (covering the data bytes [0, s)): 'file', the write token of
     * the .fx file whose body is exactly these buckets (null: none); 'idsAt', the data bytes since which the set
     * of bucket ids is this one (null: unknown)
     */
    public function newFieldIndex($field){
        return ['kind' => 'fx', 'field' => (string)$field, 's' => 0, 'ck' => -1, 'e' => null, 'b' => [], 'mk' => -1,
            'rev' => null, 'views' => null, 'bytes' => 0, 'file' => null, 'idsAt' => null];
    }

    /**
     * Build a segment's field index from its data (covers the key index's bytes)
     * @return array
     */
    public function buildSegmentFieldIndex($dbname, $shardId, $field){
        $key = $this->secondary->keyIndexWithEpoch($dbname, $shardId);
        $index = $this->newFieldIndex($field);
        // fieldIndexApply() inlined: every key occurs once, so a value is only ever added. A value of one key costs
        // no array: when every value differs, those would be most of the memory
        $field = (string)$field;
        $buckets = [];
        $lists = [];
        foreach($this->records->eachSegmentBatch($dbname, $shardId) as $batch){
            foreach($batch as $k => $record){
                $k = (int)$k;
                if($k > $index['mk']){
                    $index['mk'] = $k;
                }
                if(!isset($record[$field]) && !array_key_exists($field, $record)){
                    continue;
                }
                $value = $record[$field];
                if(is_string($value)){
                    $bucket = 's' . $value;
                } elseif(is_scalar($value) || $value === null){
                    $bucket = \noneDB\Internal\Query\Values::valueKey($value);
                } else {
                    continue;
                }
                if(isset($lists[$bucket])){
                    $lists[$bucket][] = $k;
                } elseif(isset($buckets[$bucket])){
                    $lists[$bucket] = [$buckets[$bucket], $k];
                } else {
                    $buckets[$bucket] = $k;
                }
            }
        }
        foreach($lists as $bucket => $keys){
            $buckets[$bucket] = $keys;
        }
        $index['b'] = $buckets;
        $index['mk'] = $key === null ? -1 : max($index['mk'], \noneDB\Internal\Storage\KeyIndex::maxKey($key));
        $index['s'] = $key === null ? 0 : (int)$key['s'];
        $index['e'] = $key === null ? null : $key['e'];
        $index['rev'] = null;
        return $index;
    }

    /**
     * Apply one data line to a field index: the key now holds $record (null: deleted)
     */
    private function fieldIndexApply(array &$index, $key, $record){
        $field = $index['field'];
        $new = $record !== null && array_key_exists($field, $record) && $this->isIndexableValue($record[$field])
            ? \noneDB\Internal\Query\Values::valueKey($record[$field]) : null;
        if($key > $index['mk']){
            // A key never seen: nothing to remove
            $index['mk'] = $key;
            if($new !== null){
                $this->fieldIndexAdd($index, $new, $key);
                if($index['rev'] !== null){
                    $index['rev'][$key] = $new;
                }
            }
            return;
        }
        if($index['rev'] === null){
            $rev = [];
            foreach($index['b'] as $bucket => $keys){
                foreach((array)$keys as $k){
                    $rev[$k] = (string)$bucket;
                }
            }
            $index['rev'] = $rev;
        }
        $old = $index['rev'][$key] ?? null;
        if($old === $new){
            return; // the value didn't change: nothing to do
        }
        if($old !== null){
            $index['file'] = null;
            $keys = $index['b'][$old];
            if(is_int($keys)){
                unset($index['b'][$old]);
                $index['views'] = null;
                $index['idsAt'] = null;
            } else {
                $at = array_search($key, $keys, true);
                if($at !== false){
                    unset($keys[$at]);
                }
                $keys = array_values($keys);
                if(count($keys) === 1){
                    $index['b'][$old] = $keys[0];
                } elseif(empty($keys)){
                    unset($index['b'][$old]);
                    $index['views'] = null;
                    $index['idsAt'] = null;
                } else {
                    $index['b'][$old] = $keys;
                }
            }
            unset($index['rev'][$key]);
        }
        if($new !== null){
            $this->fieldIndexAdd($index, $new, $key);
            $index['rev'][$key] = $new;
        }
    }

    private function fieldIndexAdd(array &$index, $bucket, $key){
        $index['file'] = null;
        if(!isset($index['b'][$bucket])){
            $index['b'][$bucket] = $key;
            $index['views'] = null;
            $index['idsAt'] = null;
        } elseif(is_int($index['b'][$bucket])){
            $index['b'][$bucket] = [$index['b'][$bucket], $key];
        } else {
            $index['b'][$bucket][] = $key;
        }
    }

    /**
     * A segment's field index, current (the data lines after the bytes it covers are replayed). Null when
     * there is none that fits the segment (missing, of another epoch, damaged): see ensureSegmentFieldIndex.
     * @return array|null
     */
    private function readFieldIndex($dbname, $field, $shardId = null, $path = null, $key = null){
        // The segment's key index (currentKeyIndex() inlined: this is the hot path of every lookup)
        if($key === null){
            $dataPath = $this->segments->segDataPath($dbname, $shardId);
            $key = isset($this->verifiedIndexes[\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath)]) ? ($this->indexCache[\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath)] ?? null) : null;
            if($key !== null){
                $this->metrics['hits']++;
            } elseif(($key = $this->checkpoints->readJsonlIndex($dbname, $shardId)) === null){
                return null;
            }
        }
        $path = $path ?? $this->getFieldIndexPath($dbname, $field, $shardId);
        $this->cacheTouch[$path] = ++$this->cacheTick;
        if(isset($this->fieldIndexCache[$path])){
            $index = $this->fieldIndexCache[$path];
            if($index['s'] === (int)$key['s'] && $index['e'] === $key['e'] && $index['e'] !== null){
                $this->metrics['hits']++;
                if(isset($index['due']) && $this->noWrites === 0 && $this->multiLock === 0){
                    unset($index['due'], $this->fieldIndexCache[$path]['due']); // a checkpoint a read-only load put off
                    if($this->secondary->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'fx', $field, $index)){
                        $this->fieldIndexCache[$path]['due'] = true;
                    }
                    return $this->fieldIndexCache[$path];
                }
                return $index; // the usual case: nothing written since
            }
            if($index['e'] !== null && $index['e'] === $key['e'] && $index['s'] <= (int)$key['s']){
                $this->metrics['hits']++;
                if($index['s'] < (int)$key['s']){
                    unset($this->fieldIndexCache[$path], $index['due']); // sole owner while it changes
                    $this->replayFieldIndex($dbname, $shardId, $index, (int)$key['s']);
                    $this->fieldIndexCache[$path] = $index;
                    if($this->secondary->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'fx', $field, $index)){
                        $this->fieldIndexCache[$path]['due'] = true;
                    }
                }
                return $this->fieldIndexCache[$path];
            }
            unset($this->fieldIndexCache[$path]);
        }
        $this->metrics['misses']++;
        $file = $this->secondary->readSecondaryFile($path);
        if($file === null || ($file[0]['v'] ?? null) !== 1 || !$this->secondary->secondaryFits($dbname, $shardId, $file[0], 'fx', $field)){
            return null;
        }
        $buckets = $this->decodeFieldIndexBody($file[0], $file[1]);
        if($buckets === null){
            return null;
        }
        unset($this->fieldIndexCache[$path . '#pg']); // the whole index replaces its pages
        $index = $this->newFieldIndex($field);
        $index['b'] = $buckets;
        $index['s'] = $file[0]['s'];
        $index['ck'] = $file[0]['s'];
        $index['e'] = $file[0]['e'];
        $index['mk'] = (int)($file[0]['mk'] ?? -1);
        $index['bytes'] = strlen($file[1]);
        $index['file'] = $file[0]['w'];
        $index['idsAt'] = $file[0]['s'];
        $this->replayFieldIndex($dbname, $shardId, $index, (int)$key['s']);
        $this->fieldIndexCache[$path] = $index;
        $this->cacheGrew = true;
        if($this->secondary->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'fx', $field, $index)){
            $this->fieldIndexCache[$path]['due'] = true;
        }
        return $this->fieldIndexCache[$path];
    }

    public function replayFieldIndex($dbname, $shardId, array &$index, $to){
        foreach($this->secondary->segmentTail($dbname, $shardId, $index['s'], $to) as [$k, $record]){
            $this->fieldIndexApply($index, $k, $record);
        }
        $index['s'] = $to;
    }

    /**
     * Write a field index checkpoint (exclusive lock or a reader: the content is the data's)
     */
    public function writeFieldIndex($dbname, $field, array &$index, $shardId = null){
        if($index['e'] === null){
            return false;
        }
        $path = $this->getFieldIndexPath($dbname, $field, $shardId);
        $crc = $this->checkpoints->segmentCrc($this->segments->segDataPath($dbname, $shardId), (int)$index['s']);
        if($crc === null){
            return false;
        }
        // Buckets that are still those of the file: its body (canonical, checked when it was read) is theirs
        $old = $index['file'] === null ? null : $this->secondary->readSecondaryFile($path);
        if($old !== null && ($old[0]['w'] ?? null) === $index['file']){
            $body = $old[1];
            [$pg, $c, $n] = [$old[0]['pg'], $old[0]['c'], $old[0]['n']];
        } else {
            $this->indexStats['bodiesBuilt']++; // @test
            self::canonicalBuckets($index['b']);
            $c = count($index['b']);
            $n = 0;
            foreach($index['b'] as $keys){
                $n += is_int($keys) ? 1 : count($keys);
            }
            [$body, $pg] = PagedFile::build($index['b']);
        }
        $header = ['kind' => 'fx', 'v' => 1, 'field' => (string)$field, 'w' => bin2hex(random_bytes(8)), 's' => (int)$index['s'], 'e' => $index['e'], 'h' => $crc,
            'mk' => (int)$index['mk'], 'c' => $c, 'n' => $n, 'created' => time(), 'pg' => $pg];
        $this->secondary->writeSecondaryFile($dbname, $path, $header, $body);
        unset($this->fieldIndexCache[$path . '#pg']);
        $index['file'] = $header['w'];
        $index['idsAt'] = $index['idsAt'] ?? (int)$index['s'];
        $index['ck'] = (int)$index['s'];
        $index['bytes'] = strlen($body);
        $index['views'] = null;
        $this->fieldIndexCache[$path] = $index;
        $this->cacheGrew = true;
        return true;
    }

    /**
     * Buckets in the fixed order of an index file, so the file is the same whoever writes it: bucket ids
     * in byte order (ksort, in C), keys of a bucket ascending. Range queries sort their own views
     * (fieldIndexViews), so no order by value is needed here.
     */
    public static function canonicalBuckets(array &$buckets){
        ksort($buckets, SORT_STRING);
        $lists = [];
        foreach($buckets as $bucket => $keys){
            if(is_array($keys)){
                $lists[] = $bucket;
            }
        }
        foreach($lists as $bucket){
            sort($buckets[$bucket]);
        }
    }

    /**
     * The number of an "i…" or "d…" bucket
     */
    private static function bucketNumber($bucket){
        if($bucket[0] === 'i'){
            return (int)substr($bucket, 1);
        }
        $unpacked = unpack('E', (string)hex2bin(substr($bucket, 1)));
        return $unpacked === false ? 0.0 : $unpacked[1];
    }

    /**
     * Delete a field's index file of a segment
     */
    public function deleteFieldIndexFile($dbname, $field, $shardId = null){
        $ok = true;
        foreach([$this->getFieldIndexPath($dbname, $field, $shardId)] as $path){
            unset($this->fieldIndexCache[$path]);
            $this->cache->markFileNotExists($path);
            clearstatcache(true, $path);
            if(file_exists($path) && !$this->io->fsUnlink($path)){
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Get list of indexed fields for a database
     * @return array
     */
    public function getIndexedFields($dbname){
        return $this->getIndexManifest($dbname)['fields'];
    }

    /**
     * Check if a field has an index
     * @return bool
     */
    public function isFieldIndexed($dbname, $field){
        return in_array((string)$field, $this->getIndexedFields($dbname), true);
    }

    /**
     * Invalidate field index cache for a database
     */
    public function invalidateFieldIndexCache($dbname){
        $prefix = $this->layout->dbHome($dbname);
        foreach(array_keys($this->fieldIndexCache) as $key){
            if(strpos($key, $prefix) === 0){
                unset($this->fieldIndexCache[$key]);
            }
        }
    }

    /**
     * A segment's field index: the current one, or built from the data (exclusive lock). Null when none
     * can be used (the caller scans).
     * @return array|null
     */
    public function ensureSegmentFieldIndex($dbname, $shardId, $field, $path = null, $key = null){
        $index = $this->readFieldIndex($dbname, $field, $shardId, $path, $key);
        if($index !== null){
            return $index;
        }
        if($this->noWrites > 0 || $this->multiLock > 0){
            return null; // checkIntegrity(), explain(), a snapshot: nothing is built, the segment is scanned
        }
        return $this->locks->withLock($dbname, true, function() use ($dbname, $shardId, $field){
            $index = $this->readFieldIndex($dbname, $field, $shardId);
            if($index === null){
                $index = $this->buildSegmentFieldIndex($dbname, $shardId, $field);
                $this->writeFieldIndex($dbname, $field, $index, $shardId);
                if($shardId !== null){
                    $this->updateGlobalFieldShard($dbname, $field, $shardId, $index);
                }
            }
            return $index;
        }, 'buildFieldIndex');
    }

    /**
     * Keys of a field index with exactly this value
     * @return array
     */
    public function fieldIndexKeys(array $index, $value){
        $keys = $index['b'][is_string($value) ? 's' . $value : \noneDB\Internal\Query\Values::valueKey($value)] ?? [];
        return is_int($keys) ? [$keys] : $keys;
    }

    /**
     * Keys of a segment with this value through a point lookup in its paged file (§6.5.1): the pages on the
     * path to the value and the data lines after the bytes the file covers, without loading the index.
     * Null when the index is loaded, its tail is long (loading it writes a new checkpoint), or there is no
     * file that fits.
     * @return array|null
     */
    private function pointKeys($dbname, $shardId, $field, $value, $path, $key = null){
        $key = $key ?? $this->checkpoints->readJsonlIndex($dbname, $shardId);
        if($key === null){
            return null;
        }
        $pk = $path . '#pg';
        $paged = $this->fieldIndexCache[$pk] ?? null;
        if($paged !== null && $key['e'] !== null && ($paged['fit'] ?? null) === $key['e']){
            $this->cacheTouch[$pk] = ++$this->cacheTick; // kept, and checked against this epoch: openPagedIndex() in short
            $this->metrics['hits']++;
        } elseif(($paged = $this->openPagedIndex($dbname, $shardId, $path, $field, $key)) === null){
            return null;
        }
        // A long tail is replayed by loading the index, which writes a new checkpoint of it (§6.5): point
        // lookups don't leave the same lines to every new process. An index in repeated use is loaded too
        // (decided once per operation).
        $end = (int)$key['s'];
        if($end < $paged['h']['s']
            || ($end !== $paged['h']['s'] && $this->secondary->secondaryTailIsLong($paged['h']['s'], $end, $paged['size']))){
            return null;
        }
        if($paged['ses'] !== $this->sessionCounter){
            // One more operation on the pages, counted in place (the entry isn't copied); loadWholeNow() only
            // once the operations may have paid for the load
            $entries = (int)($paged['h']['c'] ?? intdiv($paged['size'], 16));
            $paged = null;
            $this->fieldIndexCache[$pk]['ses'] = $this->sessionCounter;
            $uses = ++$this->fieldIndexCache[$pk]['uses'];
            if($uses >= 2 && $uses * self::ENTRIES_PER_LOOKUP >= $entries && $this->loadWholeNow($pk)){
                unset($this->fieldIndexCache[$pk]);
                return null;
            }
        }
        $bucket = is_string($value) ? 's' . $value : \noneDB\Internal\Query\Values::valueKey($value);
        $found = $this->pagedLookup($path, $bucket);
        if($found === false){
            // Another reader replaced the file since its header was read here: once more from the new one
            $paged = $this->openPagedIndex($dbname, $shardId, $path, $field, $key);
            $found = $paged === null ? null : $this->pagedLookup($path, $bucket);
        }
        if(!is_array($found)){
            unset($this->fieldIndexCache[$pk]); // a damaged page: the whole file is read (and rebuilt)
            return null;
        }
        $this->indexStats['pointLookups']++; // @test
        $covered = $this->fieldIndexCache[$pk]['h']['s']; // the file the pages came from (a new one after a retry)
        if($end < $covered){
            return null;
        }
        if($end === $covered){
            return $found;
        }
        // The lines after the file, worked out once per end of the data: the newest bucket of each key in
        // them (null: tombstone or no indexable value). A key in them leaves the file's answer; the keys
        // whose newest line holds this value join it.
        $tail = $this->fieldIndexCache[$pk]['tail'] ?? null;
        if($tail === null || $tail['to'] !== $end){
            $last = [];
            foreach($this->secondary->segmentTail($dbname, $shardId, $covered, $end) as [$k, $record]){
                $last[$k] = $record !== null && array_key_exists($field, $record) && $this->isIndexableValue($record[$field])
                    ? \noneDB\Internal\Query\Values::valueKey($record[$field]) : null;
            }
            $by = [];
            foreach($last as $k => $b){
                if($b !== null){
                    $by[$b][] = $k;
                }
            }
            $this->fieldIndexCache[$pk]['bytes'] += 96 * (count($last) - ($tail === null ? 0 : count($tail['last'])));
            $tail = ['to' => $end, 'last' => $last, 'by' => $by];
            $this->fieldIndexCache[$pk]['tail'] = $tail;
            $this->cacheGrew = true;
        }
        $keys = [];
        foreach($found as $k){
            if(!array_key_exists($k, $tail['last'])){
                $keys[] = $k;
            }
        }
        foreach($tail['by'][$bucket] ?? [] as $k){
            $keys[] = $k;
        }
        sort($keys);
        return $keys;
    }

    /** Entries of an .fx whose whole load costs about one paged lookup more than an array lookup (§6.5.1) */
    private const ENTRIES_PER_LOOKUP = 20;

    /** The body of an .fx in use (from its second operation) of at most this many bytes is read whole on a page miss (§6.5.1) */
    private const RAW_BODY = 262144;

    /** An .fx file of at most this many bytes is loaded whole at its first use (§6.5.1) */
    public const WHOLE_AT_FIRST_USE = 65536;

    /** The largest database (records) an exact map is made for: making it reads every shard's `.fx` whole (§6.5.2) */
    private const EXACT_MAP_RECORDS = 1000000;

    /**
     * True when an .fx file not loaded whole is small enough to be loaded at this use, and memory allows (its
     * decoded form estimated at 16 bytes per byte of the file). Not in the process's first operation (lock
     * session): a single cold operation reads the pages it needs, decoding pays off only when more follow.
     * The size: the pages kept of it (header and body), else the file's.
     */
    private function wholeAtFirstUse($path){
        if($this->sessionCounter < 2){
            return false;
        }
        $kept = $this->fieldIndexCache[$path . '#pg'] ?? null;
        $size = $kept === null ? @filesize($path) : $kept['at'] + $kept['size'];
        return $size !== false && $size <= self::WHOLE_AT_FIRST_USE && \noneDB\Internal\Cache\CacheManager::decodeFits(16 * $size);
    }

    /**
     * True when an .fx in repeated use should be loaded whole now, while memory allows (plan D6: hot segments
     * decoded within the budget): once its operations have paid for the load (at least two, one per
     * ENTRIES_PER_LOOKUP entries). A query that runs once, an index used now and then and a process near its
     * cache limit keep the pages.
     */
    private function loadWholeNow($key){
        $entry = &$this->fieldIndexCache[$key];
        if($entry['ses'] !== $this->sessionCounter){
            $entry['ses'] = $this->sessionCounter;
            $entry['uses']++;
        }
        $uses = $entry['uses'];
        $size = $entry['size'];
        $header = $entry['h'];
        unset($entry);
        $entries = (int)($header['c'] ?? intdiv($size, 16));
        if($uses < 2 || $uses * self::ENTRIES_PER_LOOKUP < $entries){
            return false;
        }
        $held = (int)($header['n'] ?? 4 * $size);
        return \noneDB\Internal\Cache\CacheManager::decodeFits(128 + 48 * $entries + 24 * $held);
    }

    /**
     * The header of an .fx file that fits its segment, kept with its pages in fieldIndexCache[path#pg]; null
     * when there is none
     */
    private function openPagedIndex($dbname, $shardId, $path, $field, $key = null){
        $entry = $this->fieldIndexCache[$path . '#pg'] ?? null;
        if($entry !== null){
            $this->metrics['hits']++;
        } else {
            $this->metrics['misses']++;
            $fp = @fopen($path, 'rb');
            if($fp === false){
                return null;
            }
            $line = fgets($fp);
            $this->cache->noteIndexRead($line === false ? 0 : strlen($line));
            $header = $line === false ? null : \noneDB\Internal\Storage\KeyIndex::openHeader($line);
            if(!is_array($header) || ($header['kind'] ?? null) !== 'fx' || ($header['v'] ?? null) !== 1 || ($header['field'] ?? null) !== (string)$field
                || !PagedFile::validTree($header['pg'] ?? null)
                || !is_string($header['w'] ?? null) || ($w = strpos($line, '"w":"' . $header['w'] . '"')) === false){
                fclose($fp);
                return null;
            }
            // 'id': the header up to the end of its write token, read again before each page (§6.5.1)
            // 'size': the body's bytes (the root is its last page)
            $entry = ['h' => $header, 'id' => substr($line, 0, $w + 6 + strlen($header['w'])), 'at' => strlen($line), 'pages' => [], 'dirs' => [],
                'size' => (int)$header['pg']['root'][0] + (int)$header['pg']['root'][1], 'bytes' => 2 * strlen($line), 'uses' => 0, 'ses' => -1];
            if((fstat($fp)['size'] ?? null) !== $entry['at'] + $entry['size']){
                fclose($fp);
                return null; // the root is the file's last page: a body the file doesn't hold is never asked for
            }
            // Every lookup starts at the root: read with the header, from the same file
            [$offset, $length] = $header['pg']['root'];
            fseek($fp, $entry['at'] + (int)$offset);
            $root = PagedFile::open((string)fread($fp, (int)$length));
            $this->cache->noteIndexRead((int)$length, 1);
            $children = $root === null || $header['pg']['levels'] === 0 ? null : PagedFile::directory($root);
            if($root !== null && ($header['pg']['levels'] === 0 || $children !== null)){
                $this->indexStats['pagesRead']++; // @test
                if($children === null){
                    $entry['pages'][(int)$offset] = $root;
                } else {
                    $entry['dirs'][(int)$offset] = $children;
                }
                $entry['bytes'] += 2 * (int)$length;
            }
            fclose($fp);
            $this->fieldIndexCache[$path . '#pg'] = $entry;
            $this->cacheGrew = true;
        }
        $this->cacheTouch[$path . '#pg'] = ++$this->cacheTick;
        // A segment index fits while its key index keeps the epoch it was checked against (the bytes it
        // covers can't change within an epoch): checked once, not at every lookup
        if($key === null || $key['e'] === null || ($entry['fit'] ?? null) !== $key['e']){
            if(!$this->secondary->secondaryFits($dbname, $shardId, $entry['h'], 'fx', $field)){
                unset($this->fieldIndexCache[$path . '#pg']);
                return null;
            }
            if($key !== null && $key['e'] !== null){
                $this->fieldIndexCache[$path . '#pg']['fit'] = $entry['fit'] = $key['e'];
            }
        }
        return $entry;
    }

    /**
     * The keys of a bucket in an opened .fx ([] when the value isn't in it); null when a page on the path is
     * damaged; false when another process replaced the file since its header was read (a reader's checkpoint):
     * its pages are dropped, they don't fit the new file. Pages are kept as they are; a data page is searched
     * where it is (§6.5.1 lookup step 3).
     */
    private function pagedLookup($path, $bucket){
        // Every page on the path kept: answered from them, no handle and no header read
        $kept = $this->fieldIndexCache[$path . '#pg'];
        $pg = $kept['h']['pg'];
        [$offset, $length] = $pg['root'];
        for($level = $pg['levels']; $level > 0 && isset($kept['dirs'][$offset]); $level--){
            $child = PagedFile::child($kept['dirs'][$offset], $bucket);
            if($child === []){
                return [];
            }
            [$offset, $length] = $child;
        }
        if($level === 0 && isset($kept['pages'][$offset])){
            return PagedFile::fxMember($kept['pages'][$offset], $bucket);
        }
        if($level === 0 && isset($kept['ok'][$offset])){
            // The page inside the body read whole, its crc checked: searched where it is
            return PagedFile::fxMember($kept['raw'], $bucket, $offset, $offset + $length - 4);
        }
        unset($kept);
        $entry = &$this->fieldIndexCache[$path . '#pg'];
        [$offset, $length] = $pg['root'];
        $fp = null; // one handle for the pages of this lookup, opened when a page isn't kept
        try {
            for($level = (int)$pg['levels']; $level > 0; $level--){
                // Directory pages are kept decoded (few, reused by every lookup)
                $children = $entry['dirs'][$offset] ?? null;
                if($children === null){
                    $page = $this->readPage($path, $entry, $fp, $offset, $length);
                    if(!is_string($page)){
                        return $page;
                    }
                    $children = PagedFile::directory($page);
                    if($children === null){
                        return null;
                    }
                    $entry['dirs'][$offset] = $children;
                    $entry['bytes'] += 3 * $length;
                }
                $child = PagedFile::child($children, $bucket);
                if($child === []){
                    return [];
                }
                [$offset, $length] = $child;
            }
            // A data page is kept as it is, and searched where it is
            $page = $entry['pages'][$offset] ?? null;
            if($page === null){
                $page = $this->readPage($path, $entry, $fp, $offset, $length);
                if(!is_string($page)){
                    return $page;
                }
                if(isset($entry['raw'])){
                    $entry['ok'][$offset] = true; // checked once; searched inside the body from now on
                    return PagedFile::fxMember($entry['raw'], $bucket, $offset, $offset + $length - 4);
                }
                $entry['pages'][$offset] = $page;
                $entry['bytes'] += $length;
            }
            return PagedFile::fxMember($page, $bucket);
        } finally {
            unset($entry);
            if($fp !== null && $fp !== false){
                fclose($fp);
            }
        }
    }

    /**
     * Read one page of an opened paged file through the lookup's handle (opened on first use, its header
     * checked against the one the entry holds): the page without its crc; null when it is damaged or the
     * file can't be opened; false when the file was replaced (the entry is dropped)
     */
    private function readPage($path, array &$entry, &$fp, $offset, $length){
        if($length < 1 || $offset + $length > $entry['size']){
            return null; // a child outside the body
        }
        if(isset($entry['raw'])){
            return PagedFile::open(substr($entry['raw'], $offset, $length));
        }
        if($fp === null){
            $fp = @fopen($path, 'rb');
            if($fp === false){
                return null;
            }
            $this->cache->noteIndexRead(strlen($entry['id']), 0);
            if(fread($fp, strlen($entry['id'])) !== $entry['id']){
                unset($this->fieldIndexCache[$path . '#pg']);
                return false;
            }
        }
        if($entry['uses'] >= 2 && $entry['size'] <= self::RAW_BODY
            && \noneDB\Internal\Cache\CacheManager::decodeFits(2 * $entry['size'])){
            // An .fx in use in a later operation: its body read whole from this handle (header checked), and
            // every page of it answered from those bytes; the pages kept so far are in them
            fseek($fp, $entry['at']);
            $raw = (string)fread($fp, $entry['size']);
            $this->cache->noteIndexRead(strlen($raw));
            if(strlen($raw) === $entry['size']){
                $this->indexStats['bodiesRead']++; // @test
                $entry['raw'] = $raw;
                $entry['bytes'] += $entry['size'] - array_sum(array_map('strlen', $entry['pages']));
                $entry['pages'] = [];
                $entry['ok'] = [];
                $this->cacheGrew = true;
                return PagedFile::open(substr($raw, $offset, $length));
            }
        }
        fseek($fp, $entry['at'] + $offset);
        $page = PagedFile::open((string)fread($fp, $length));
        $this->cache->noteIndexRead($length, 1);
        if($page !== null){
            $this->indexStats['pagesRead']++; // @test
            $this->cacheGrew = true;
        }
        return $page;
    }

    /**
     * Every bucket of a paged .fx body: [bucket => key | [keys]]; null when the file is damaged
     */
    public function decodeFieldIndexBody(array $header, $body){
        $this->indexStats['bodiesDecoded']++; // @test
        $pg = $header['pg'] ?? null;
        if(!is_array($pg)){
            return null;
        }
        $pages = PagedFile::dataPages((string)$body, $pg);
        if($pages === null){
            return null;
        }
        $buckets = [];
        foreach($pages as [$offset, $length]){
            $data = PagedFile::open(substr((string)$body, $offset, $length));
            if($data === null || !PagedFile::fxMembersInto($data, $buckets)){
                return null;
            }
        }
        return $buckets;
    }

    /**
     * The equality filters field indexes can answer: [field => value], empty when there are none. A query works
     * this out once, not per segment.
     * @return array
     */
    public function indexedFilters($dbname, array $filters){
        $usable = [];
        if(!$this->fieldIndexEnabled || empty($filters)){
            return $usable;
        }
        $indexed = $this->getIndexedFields($dbname);
        foreach($filters as $field => $value){
            if($this->isIndexableValue($value) && in_array((string)$field, $indexed, true)){
                $usable[$field] = $value;
            }
        }
        return $usable;
    }

    /**
     * Candidate keys of a segment for equality filters, from field indexes.
     * Null means "no usable index, scan the segment".
     * @return array|null
     */
    public function fieldIndexCandidates($dbname, $shardId, array $filters){
        $usable = $this->indexedFilters($dbname, $filters);
        return empty($usable) ? null : $this->segmentCandidates($dbname, $shardId, $usable);
    }

    /**
     * Candidate keys of a segment for filters of indexedFilters()
     * @param array|null $key The segment's key index, when the caller just read it
     * @return array
     */
    public function segmentCandidates($dbname, $shardId, array $usable, $key = null){
        $candidates = null;
        foreach($usable as $field => $value){
            // Loaded whole (or small enough to be at its first use): answered from memory, readFieldIndex()
            // replays newer lines; else a point lookup
            $path = $this->getFieldIndexPath($dbname, (string)$field, $shardId);
            $keys = isset($this->fieldIndexCache[$path]) || $this->wholeAtFirstUse($path)
                ? null : $this->pointKeys($dbname, $shardId, (string)$field, $value, $path, $key);
            if($keys === null){
                try {
                    $index = $this->ensureSegmentFieldIndex($dbname, $shardId, $field, $path, $key);
                } catch(\Throwable $e){
                    if($e->getCode() === \noneDB::LOCK_FAILED){
                        throw $e; // never read without the lock
                    }
                    $index = null; // can't be built: scan
                }
                $keys = $index === null ? null : $this->fieldIndexKeys($index, $value);
            }
            if($keys === null){
                continue;
            }
            $candidates = $candidates === null ? $keys : array_values(array_intersect($candidates, $keys));
            if(empty($candidates)){
                return [];
            }
        }
        return $candidates;
    }

    // ==================== RANGE VIEWS (I3) ====================

    /**
     * Candidate keys of a segment whose field value may satisfy every range condition ($gt, $gte, $lt,
     * $lte => operand): a superset, the caller checks every record. Null: no usable index (scan).
     * @param array $conditions [operator => operand]
     * @return array|null
     */
    public function fieldIndexRangeCandidates($dbname, $shardId, $field, array $conditions){
        if(!$this->fieldIndexEnabled || !$this->isFieldIndexed($dbname, $field)){
            return null;
        }
        try {
            $index = $this->ensureSegmentFieldIndex($dbname, $shardId, $field);
        } catch(\Throwable $e){
            if($e->getCode() === \noneDB::LOCK_FAILED){
                throw $e;
            }
            return null;
        }
        if($index === null){
            return null;
        }
        $path = $this->getFieldIndexPath($dbname, $field, $shardId);
        $views = $this->fieldIndexViews($index);
        if(isset($this->fieldIndexCache[$path])){
            $this->fieldIndexCache[$path]['views'] = $views;
        }
        $buckets = null; // bucket => true
        foreach($conditions as $op => $operand){
            if(!in_array($op, ['$gt', '$gte', '$lt', '$lte'], true)){
                return null;
            }
            $found = $this->rangeBuckets($views, $op, $operand);
            if($found === null){
                return null; // an operand nothing compares with: no help from the index
            }
            $buckets = $buckets === null ? $found : array_intersect_key($buckets, $found);
            if(empty($buckets)){
                return [];
            }
        }
        if($buckets === null){
            return null;
        }
        $keys = [];
        foreach(array_keys($buckets) as $bucket){
            $bucketKeys = $index['b'][$bucket] ?? [];
            if(is_int($bucketKeys)){
                $keys[] = $bucketKeys;
            } else {
                foreach($bucketKeys as $k){
                    $keys[] = $k;
                }
            }
        }
        return $keys;
    }

    /**
     * Sorted views of a field index's buckets: numbers (ints, floats and numeric strings, by value) and
     * strings (byte order)
     * @return array ['num' => [float values], 'numIds' => [their buckets], 'str' => [strings], 'strIds' => [their buckets]]
     */
    private function fieldIndexViews(array $index){
        if($index['views'] !== null){
            return $index['views'];
        }
        // Sort keys first, then one array_multisort per view (a comparator per pair is far slower)
        $numValues = [];
        $numIds = [];
        $strValues = [];
        $strIds = [];
        foreach(array_keys($index['b']) as $bucket){
            $bucket = (string)$bucket;
            $type = $bucket[0];
            if($type === 'i' || $type === 'd'){
                $numValues[] = (float)self::bucketNumber($bucket);
                $numIds[] = $bucket;
            } elseif($type === 's'){
                $s = substr($bucket, 1);
                $strValues[] = $s;
                $strIds[] = $bucket;
                if(is_numeric($s)){
                    $numValues[] = (float)$s;
                    $numIds[] = $bucket;
                }
            }
        }
        array_multisort($numValues, SORT_NUMERIC, $numIds, SORT_STRING);
        array_multisort($strValues, SORT_STRING, $strIds, SORT_STRING);
        return ['num' => $numValues, 'numIds' => $numIds, 'str' => $strValues, 'strIds' => $strIds];
    }

    /**
     * Buckets whose values may compare as $op with $operand (\noneDBQuery::compareValues semantics: numbers
     * and numeric strings numerically, other strings by byte order; null, booleans and arrays compare
     * with nothing). Ties of different types are included (rechecked by the caller).
     * @return array|null bucket => true; null when the operand can't be compared
     */
    private function rangeBuckets(array $views, $op, $operand){
        if(is_float($operand) && is_nan($operand)){
            return []; // NAN matches nothing
        }
        if(is_int($operand) || is_float($operand) || (is_string($operand) && is_numeric($operand))){
            $x = (float)$operand;
            // A wider band at the boundary: values above 2^53 compare as floats here and as ints there
            $slack = abs($x) >= 9007199254740992.0 ? max(1.0, abs($x) * 4.0e-16) : 0.0;
            [$from, $length] = $this->rangeSlice($views['num'], $op, $x, $slack);
            $found = array_fill_keys(array_slice($views['numIds'], $from, $length), true);
            if(is_string($operand)){
                // A numeric string operand compares with non-numeric strings by byte order too
                [$from, $length] = $this->rangeSliceStrings($views['str'], $op, $operand);
                $found += array_fill_keys(array_slice($views['strIds'], $from, $length), true);
            }
            return $found;
        }
        if(is_string($operand)){
            [$from, $length] = $this->rangeSliceStrings($views['str'], $op, $operand);
            return array_fill_keys(array_slice($views['strIds'], $from, $length), true);
        }
        return null;
    }

    /**
     * Where the entries of a sorted numeric view on the operator's side of $x are, [from, length] (binary search; $slack widens it).
     * Without slack ($x below 2^53, where these floats are exact) a strict operator leaves out the values
     * equal to $x: compareValues() finds them equal too, so they never match
     */
    private function rangeSlice(array $list, $op, $x, $slack){
        $n = count($list);
        $strict = $slack == 0.0 && ($op === '$gt' || $op === '$lt');
        if($op === '$gt' || $op === '$gte'){
            $bound = $x - $slack;
            $lo = 0;
            $hi = $n;
            while($lo < $hi){
                $mid = ($lo + $hi) >> 1;
                if($strict ? $list[$mid] <= $bound : $list[$mid] < $bound){
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            return [$lo, $n - $lo];
        }
        $bound = $x + $slack;
        $lo = 0;
        $hi = $n;
        while($lo < $hi){
            $mid = ($lo + $hi) >> 1;
            if($strict ? $list[$mid] < $bound : $list[$mid] <= $bound){
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return [0, $lo];
    }

    /**
     * Where the entries of the sorted string view on the operator's side of $x are, [from, length] (the string
     * $x itself only for $gte and $lte)
     */
    private function rangeSliceStrings(array $list, $op, $x){
        $n = count($list);
        $lo = 0;
        $hi = $n;
        $strict = $op === '$gt' || $op === '$lt';
        if($op === '$gt' || $op === '$gte'){
            while($lo < $hi){
                $mid = ($lo + $hi) >> 1;
                $cmp = strcmp($list[$mid], $x);
                if($strict ? $cmp <= 0 : $cmp < 0){
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            return [$lo, $n - $lo];
        }
        while($lo < $hi){
            $mid = ($lo + $hi) >> 1;
            $cmp = strcmp($list[$mid], $x);
            if($strict ? $cmp < 0 : $cmp <= 0){
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return [0, $lo];
    }

    // ==================== GLOBAL FIELD INDEX (shard skip) ====================
    // indexes/{token}.gfx (docs/STORAGE_FORMAT.md §6.5.2): per shard, a filter of the bucket ids of its field
    // index at the bytes [0, s) its entry {"s","e","h"} names, bit-sliced: row r holds bit r of every shard's
    // filter, one bit per shard, rows in pages with a crc. While the lock epoch is the one recorded (le), no data
    // file was replaced, so every entry still describes its shard; otherwise each entry is checked against its
    // shard (epoch and checksum). A shard is skipped only when it has an entry that fits, its filter rules the
    // value out, and its lines after s don't have it either. No entry: never skipped. A shard's filter is
    // replaced when its field index is checkpointed, never per write. In memory: the header ('le', 'sh', 'bits',
    // 'width'; 'id' and 'at': the header bytes through w and where the pages start, of the file it was read
    // from; 'uses', 'ses': its operations; 'body': the pages, once loaded whole) and 'cols', the filters this
    // session changed and hasn't written yet ('grow': one of them needs larger sizes, the file is made again).

    public function getGlobalFieldIndexPath($dbname, $field){
        return $this->secondary->secondaryPath($dbname, 'gfx', $field);
    }

    /**
     * The global field index of a field: its header, checked (§6.5.2); null when there is no valid file
     * @return array|null
     */
    private function readGlobalFieldIndex($dbname, $field, $path = null){
        $path = $path ?? $this->getGlobalFieldIndexPath($dbname, $field);
        $this->cacheTouch[$path] = ++$this->cacheTick;
        if(array_key_exists($path, $this->fieldIndexCache)){
            $this->metrics['hits']++;
            return $this->fieldIndexCache[$path]; // the header, or null (no valid file)
        }
        $this->metrics['misses']++;
        clearstatcache(true, $path);
        $fp = @fopen($path, 'rb');
        $line = $fp === false ? false : fgets($fp);
        $this->cache->noteIndexRead($line === false ? 0 : strlen($line));
        $size = $fp === false ? 0 : (int)(fstat($fp)['size'] ?? 0);
        if($fp !== false){
            fclose($fp);
        }
        $header = $line === false ? null : \noneDB\Internal\Storage\KeyIndex::openHeader($line);
        $global = null;
        if(is_array($header) && ($header['kind'] ?? null) === 'gfx' && ($header['v'] ?? null) === 1 && ($header['field'] ?? null) === (string)$field
            && is_string($header['w'] ?? null) && ($w = strpos($line, '"w":"' . $header['w'] . '"')) !== false
            && self::isSize($header['bits'] ?? null, 64) && self::isSize($header['width'] ?? null, 8) && is_array($header['sh'] ?? null)
            && $size === strlen($line) + self::globalBodyBytes($header['bits'], $header['width'])){
            $global = ['le' => $header['le'] ?? null, 'sh' => [], 'bits' => $header['bits'], 'width' => $header['width'],
                'id' => substr($line, 0, $w + 6 + strlen($header['w'])), 'at' => strlen($line), 'cols' => [], 'uses' => 0, 'ses' => -1];
            foreach($header['sh'] as $shardId => $entry){
                if(SecondaryFiles::isEntry($entry) && (int)$shardId < $header['width']){
                    $global['sh'][(int)$shardId] = $entry;
                }
            }
        }
        $this->fieldIndexCache[$path] = $global;
        unset($this->globalSkip[$this->locks->getLockPath($dbname)][$path]);
        $this->cacheGrew = true;
        return $global;
    }

    /** A size of a global field index: a power of two of at least $min */
    private static function isSize($n, $min){
        return is_int($n) && $n >= $min && ($n & ($n - 1)) === 0;
    }

    /** Bytes of the pages of a global field index of these sizes */
    private static function globalBodyBytes($bits, $width){
        $per = ShardFilters::pageRows($bits, $width);
        return intdiv($bits, $per) * ($per * ($width >> 3) + 4);
    }

    /**
     * The shards whose filter may hold a bucket id (§6.5.2), with the filters this session changed and hasn't
     * written yet: the AND of its six probe rows. The rows come from the body when it is loaded; else from the
     * pages that hold them, read from the file after its header (read again from the same handle). The body is
     * loaded whole at the file's second operation, when memory allows (every page's crc checked). Null when the
     * file is damaged or gone; false when another process replaced it since its header was read.
     * @return int[]|null|false ascending
     */
    private function globalHolders($path, $bucket){
        $global = $this->fieldIndexCache[$path];
        $probes = ShardFilters::probes($bucket, $global['bits']);
        $held = [];
        if($global['id'] !== null){
            $rb = $global['width'] >> 3;
            $per = ShardFilters::pageRows($global['bits'], $global['width']);
            $stored = $per * $rb + 4;
            $rows = [];
            if(!isset($global['body'])){
                $pages = intdiv($global['bits'], $per);
                $whole = $global['uses'] >= 2 && \noneDB\Internal\Cache\CacheManager::decodeFits($pages * $stored);
                $fp = @fopen($path, 'rb');
                if($fp === false){
                    return null;
                }
                try {
                    $this->cache->noteIndexRead(strlen($global['id']), 0);
                    if(fread($fp, strlen($global['id'])) !== $global['id']){
                        return false;
                    }
                    if($whole){
                        fseek($fp, $global['at']);
                        $body = (string)fread($fp, $pages * $stored);
                        $this->cache->noteIndexRead(strlen($body), $pages);
                        for($p = 0; $p < $pages; $p++){
                            if(ShardFilters::open(substr($body, $p * $stored, $stored), $stored - 4) === null){
                                return null;
                            }
                        }
                        $global['body'] = $this->fieldIndexCache[$path]['body'] = $body;
                        $this->cacheGrew = true;
                    } else {
                        $read = [];
                        foreach($probes as $r){
                            $p = intdiv($r, $per);
                            if(!isset($read[$p])){
                                fseek($fp, $global['at'] + $p * $stored);
                                $read[$p] = ShardFilters::open((string)fread($fp, $stored), $stored - 4);
                                $this->cache->noteIndexRead($stored, 1);
                                if($read[$p] === null){
                                    return null;
                                }
                            }
                            $rows[] = substr($read[$p], ($r % $per) * $rb, $rb);
                        }
                    }
                } finally {
                    fclose($fp);
                }
            }
            $this->indexStats['pointLookups']++; // @test
            $held = isset($global['body']) ? ShardFilters::holdersIn($global['body'], $global['bits'], $rb, $bucket) : ShardFilters::holders($rows);
        }
        if(empty($global['cols'])){
            return $held;
        }
        $held = array_fill_keys($held, true);
        foreach($global['cols'] as $shardId => $filter){
            if(ShardFilters::mayHold($filter, $probes)){
                $held[$shardId] = true;
            } else {
                unset($held[$shardId]);
            }
        }
        $held = array_keys($held);
        sort($held);
        return $held;
    }

    /**
     * Write the global field index (exclusive lock, or a reader: the content is a function of the shards' field
     * indexes): its pages copied from the file it was read from, with the bits of the filters of 'cols' in place
     * of the old ones. Made again from every shard's field index when the sizes must grow, there was no valid
     * file, or the old one can't be copied (replaced or gone since, a damaged page).
     */
    public function writeGlobalFieldIndex($dbname, $field, array $global){
        $path = $this->getGlobalFieldIndexPath($dbname, $field);
        $source = empty($global['grow']) ? @fopen($path, 'rb') : false;
        if($source !== false){
            $this->cache->noteIndexRead(strlen($global['id']), 0);
        }
        if($source === false || fread($source, strlen($global['id'])) !== $global['id']){
            if($source !== false){
                fclose($source);
            }
            $this->rebuildGlobalFieldIndex($dbname, $field);
            return;
        }
        fseek($source, $global['at']);
        $pages = function() use ($source, $global){
            $rb = $global['width'] >> 3;
            $per = ShardFilters::pageRows($global['bits'], $global['width']);
            for($p = 0, $n = intdiv($global['bits'], $per); $p < $n; $p++){
                $rows = ShardFilters::open((string)fread($source, $per * $rb + 4), $per * $rb);
                $this->cache->noteIndexRead($per * $rb + 4, 1);
                if($rows === null){
                    throw new \UnexpectedValueException('damaged page');
                }
                foreach($global['cols'] as $shardId => $filter){
                    $rows = ShardFilters::withColumn($rows, $p * $per, $rb, $shardId, $filter);
                }
                yield ShardFilters::seal($rows);
            }
            fclose($source); // before the rename: Windows doesn't replace a file that is open
        };
        $damaged = false;
        try {
            $this->writeGlobalFile($dbname, $field, $path, $global['sh'], $global['bits'], $global['width'], $pages());
        } catch(\UnexpectedValueException $e){
            $damaged = true;
        } finally {
            if(is_resource($source)){
                fclose($source);
            }
        }
        if($damaged){
            $this->rebuildGlobalFieldIndex($dbname, $field);
        }
    }

    /**
     * Write a global field index file ($pages: its sealed pages, in order) and keep its header as the cached one
     */
    private function writeGlobalFile($dbname, $field, $path, array $sh, $bits, $width, iterable $pages){
        $lockPath = $this->locks->getLockPath($dbname);
        $le = isset($this->lockRegistry[$lockPath]) ? $this->locks->registryEpoch($lockPath) : null;
        ksort($sh);
        $header = ['kind' => 'gfx', 'v' => 1, 'field' => (string)$field, 'w' => bin2hex(random_bytes(8)), 'le' => $le,
            'bits' => $bits, 'width' => $width, 'sh' => (object)$sh];
        $line = $this->io->encodeHeader($header);
        $content = (function() use ($line, $pages){
            yield $line . "\n";
            yield from $pages;
        })();
        if($line === false || !$this->io->writeDerivedFile($path, $content)){
            throw new \RuntimeException("noneDB: failed to write index " . basename($path) . " of database '{$dbname}'");
        }
        $this->cache->markFileExists($path);
        $w = strpos($line, '"w":"' . $header['w'] . '"');
        $this->fieldIndexCache[$path] = ['le' => $le, 'sh' => $sh, 'bits' => $bits, 'width' => $width,
            'id' => substr($line, 0, $w + 6 + strlen($header['w'])), 'at' => strlen($line) + 1, 'cols' => [], 'uses' => 0, 'ses' => -1];
        unset($this->globalSkip[$lockPath][$path], $this->lockRegistry[$lockPath]['globals'][$path]);
    }

    /**
     * A shard's field index checkpointed: its filter and entry replace the old ones in the global field index
     * (written when the lock session ends)
     */
    public function updateGlobalFieldShard($dbname, $field, $shardId, array $index){
        $entry = $this->globalEntry($dbname, $shardId, $index);
        $hotKey = $this->getGlobalFieldIndexPath($dbname, $field) . '#hot';
        if(isset($this->fieldIndexCache[$hotKey])){
            // This process's exact map follows: the shard's entry replaced, its values added (a value it no longer
            // holds stays listed: the shard is read for it)
            $hot = &$this->fieldIndexCache[$hotKey];
            if($entry === null){
                unset($hot['sh'][$shardId]);
            } else {
                if(!self::sameIdsSince($index, $hot['sh'][$shardId] ?? null)){
                    foreach(array_keys($index['b']) as $bucket){
                        if(!isset($hot['map'][$bucket])){
                            $hot['map'][$bucket] = $shardId;
                        } elseif(is_int($hot['map'][$bucket])){
                            if($hot['map'][$bucket] !== $shardId){
                                $hot['map'][$bucket] = [min($hot['map'][$bucket], $shardId), max($hot['map'][$bucket], $shardId)];
                            }
                        } elseif(!in_array($shardId, $hot['map'][$bucket], true)){
                            $hot['map'][$bucket][] = $shardId;
                            sort($hot['map'][$bucket]); // holders in shard order: the order of the records read
                        }
                    }
                }
                $hot['sh'][$shardId] = $entry;
            }
            unset($hot, $this->globalSkip[$this->locks->getLockPath($dbname)][$hotKey]);
        }
        $global = $this->readGlobalFieldIndex($dbname, $field);
        if($global === null){
            // No valid file: made from every shard when the session ends, as a lookup would
            $this->deferGlobalWrite($dbname, 'gfx', $field, ['le' => null, 'sh' => [], 'bits' => 64, 'width' => 8, 'id' => null, 'cols' => [], 'grow' => true]);
            return;
        }
        $global = $this->secondary->freshGlobalEntries($dbname, $global);
        if($entry === null){
            unset($global['sh'][$shardId], $global['cols'][$shardId]); // never skipped
        } elseif(self::sameIdsSince($index, $global['sh'][$shardId] ?? null)){
            $global['sh'][$shardId] = $entry; // the bucket ids its bits were made from: they stay
        } elseif(32 * count($index['b']) > $global['bits'] || $shardId >= $global['width']){
            $global['grow'] = true;
            $global['sh'][$shardId] = $entry;
        } else {
            $this->indexStats['filtersBuilt']++; // @test
            $global['cols'][$shardId] = ShardFilters::filter(array_keys($index['b']), $global['bits']);
            $global['sh'][$shardId] = $entry;
        }
        $this->deferGlobalWrite($dbname, 'gfx', $field, $global);
    }

    /**
     * True when a shard's entry in the global index (or in the exact map) is of a checkpoint of its field index
     * since which the index's bucket ids haven't changed: the filter and the values listed with it are the index's.
     * Of the same epoch: offsets of a replaced data file (this process's compaction keeps its map) say nothing.
     */
    private static function sameIdsSince(array $index, ?array $entry){
        return $entry !== null && $index['idsAt'] !== null && $entry['e'] === $index['e'] && $entry['s'] >= $index['idsAt'];
    }

    /**
     * Keep a changed global field or spatial index in the cache and write it when the lock session ends
     * (flushPendingGlobals()); without a lock at once
     */
    public function deferGlobalWrite($dbname, $kind, $field, array $global){
        $lock = $this->locks->getLockPath($dbname);
        if(!isset($this->lockRegistry[$lock])){
            if($kind === 'gfx'){
                $this->writeGlobalFieldIndex($dbname, $field, $global);
            } else {
                $this->rtree->writeGlobalSpatialIndex($dbname, $field, $global);
            }
            return;
        }
        if($kind === 'gfx'){
            $path = $this->getGlobalFieldIndexPath($dbname, $field);
            $this->fieldIndexCache[$path] = $global;
            unset($this->globalSkip[$lock][$path]);
        } else {
            $path = $this->rtree->getGlobalSpatialIndexPath($dbname, $field);
            $this->globalSpatialIndexCache[$path] = $global;
        }
        $this->lockRegistry[$lock]['globals'][$path] = [$kind, $field, $this->locks->registryEpoch($lock)];
    }

    /**
     * The global entry of a shard's field index (the bytes it covers, its epoch and their checksum); null when
     * it can't have one (no epoch, data file gone)
     * @return array|null
     */
    private function globalEntry($dbname, $shardId, array $index){
        if($index['e'] === null){
            return null;
        }
        $crc = $this->checkpoints->segmentCrc($this->segments->segDataPath($dbname, $shardId), (int)$index['s']);
        return $crc === null ? null : ['s' => (int)$index['s'], 'e' => $index['e'], 'h' => $crc];
    }

    /** A shard's field index for the global one: as it is, or built from the data (and written) when there is none */
    private function shardFieldIndex($dbname, $shardId, $field){
        $index = $this->readFieldIndex($dbname, $field, $shardId);
        if($index === null){
            $index = $this->buildSegmentFieldIndex($dbname, $shardId, $field);
            $this->writeFieldIndex($dbname, $field, $index, $shardId);
        }
        return $index;
    }

    /**
     * Make the global field index again from every shard's field index (exclusive lock, or a reader): the sizes and
     * the shards' entries from a first pass, then the rows in parts of whole pages, each part one more pass over the
     * shards that sets the probe bits falling into it. A part holds at most a quarter of the cache limit (all rows
     * at once without a limit); the cache evicts between the shards: the process holds a part and one shard's index.
     * @return array|null The header, as readGlobalFieldIndex() returns it
     */
    public function rebuildGlobalFieldIndex($dbname, $field){
        $path = $this->getGlobalFieldIndexPath($dbname, $field);
        $lockPath = $this->locks->getLockPath($dbname);
        unset($this->fieldIndexCache[$path], $this->fieldIndexCache[$path . '#hot'], $this->globalSkip[$lockPath][$path],
            $this->globalSkip[$lockPath][$path . '#hot'], $this->lockRegistry[$lockPath]['globals'][$path]);
        $shards = $this->segments->listSegments($dbname);
        sort($shards);
        $largest = 0;
        $sh = [];
        foreach($shards as $shardId){
            // The sizes need each shard's count of values and its entry: a file that fits its data gives them by its
            // header (its bucket ids are read once, below); a missing or stale one by the index built for it
            $header = $this->secondary->readSecondaryHeader($this->getFieldIndexPath($dbname, $field, $shardId));
            if($header !== null && ($header['v'] ?? null) === 1 && $this->secondary->secondaryFits($dbname, $shardId, $header, 'fx', $field)){
                $count = (int)$header['c'];
                $entry = $this->globalEntry($dbname, $shardId, ['e' => $header['e'], 's' => (int)$header['s']]);
            } else {
                $index = $this->shardFieldIndex($dbname, $shardId, $field);
                $count = count($index['b']);
                $entry = $this->globalEntry($dbname, $shardId, $index);
                unset($index);
            }
            $largest = max($largest, $count);
            if($entry !== null){
                $sh[$shardId] = $entry; // a shard without one is never skipped: no bits
            }
            $this->cache->evictBetweenSegments();
        }
        $bits = ShardFilters::size(32 * $largest, 64);
        $width = ShardFilters::size(empty($shards) ? 1 : end($shards) + 1, 8);
        $rb = $width >> 3;
        $per = ShardFilters::pageRows($bits, $width);
        $pages = intdiv($bits, $per);
        $limit = \noneDB\Internal\Cache\CacheManager::cacheLimitBytes();
        $part = $limit > 0 ? max(1, intdiv(intdiv($limit, 4), $per * $rb)) : $pages; // pages per part
        // ShardFilters::probes() inline, per part the places of the first bucket ids kept (about 256 bytes each): at
        // most 16 384, and within a quarter of the cache limit. A value that many shards hold is hashed once
        $keep = $limit > 0 ? min(16384, intdiv($limit, 1024)) : 16384;
        $this->writeGlobalFile($dbname, $field, $path, $sh, $bits, $width, (function() use ($dbname, $field, $sh, $bits, $rb, $per, $pages, $part, $keep){
            $mask = ($bits - 1) & 0xFFFFFFFF;
            // A byte with one bit set, per bit: an array lookup where chr(ord() | bit) makes two calls per probe
            $withBit = [];
            for($bit = 0; $bit < 8; $bit++){
                for($byte = 0; $byte < 256; $byte++){
                    $withBit[$bit][chr($byte)] = chr($byte | (1 << $bit));
                }
            }
            for($first = 0; $first < $pages; $first += $part){
                $lo = $first * $per;
                $hi = min($pages, $first + $part) * $per;
                $rows = str_repeat("\0", ($hi - $lo) * $rb); // rows [lo, hi), one after the other
                $seen = [];
                $kept = 0;
                foreach(array_keys($sh) as $shardId){
                    // The shard's filter written straight into its column: bit r of the filter is its bit in row r
                    $index = $this->shardFieldIndex($dbname, $shardId, $field);
                    $byte = $shardId >> 3;
                    $set = $withBit[$shardId & 7];
                    foreach(array_keys($index['b']) as $id){
                        $id = (string)$id;
                        $places = $seen[$id] ?? null;
                        if($places === null){
                            $d = unpack('N2', hash('xxh3', $id, true));
                            $y = $d[2] | 1;
                            $places = [];
                            foreach([$d[1] & $mask, ($d[1] + $y) & $mask, ($d[1] + 2 * $y) & $mask,
                                ($d[1] + 3 * $y) & $mask, ($d[1] + 4 * $y) & $mask, ($d[1] + 5 * $y) & $mask] as $r){
                                if($r >= $lo && $r < $hi){
                                    $places[] = ($r - $lo) * $rb;
                                }
                            }
                            if($kept < $keep){
                                $seen[$id] = $places;
                                $kept++;
                            }
                        }
                        foreach($places as $at){
                            $at += $byte;
                            $rows[$at] = $set[$rows[$at]];
                        }
                    }
                    unset($index);
                    $this->cache->evictBetweenSegments();
                }
                for($p = 0, $n = intdiv($hi - $lo, $per); $p < $n; $p++){
                    yield ShardFilters::seal(substr($rows, $p * $per * $rb, $per * $rb));
                }
                unset($rows);
            }
        })());
        return $this->fieldIndexCache[$path];
    }

    /**
     * True when a global entry still describes its shard's data bytes [0, s)
     */
    public function globalEntryFits($dbname, $shardId, array $entry, $lockEpoch){
        $lock = $this->lockRegistry[$this->locks->getLockPath($dbname)] ?? null;
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        if($lockEpoch !== null && $lock !== null && $this->locks->registryEpoch($this->locks->getLockPath($dbname)) === $lockEpoch){
            return true; // no data file was replaced since the entry was written
        }
        if(($this->dataEpochs[$dataPath] ?? null) === null){
            $this->dataEpochs[$dataPath] = $this->segmentEpochOnDisk($dbname, $shardId);
        }
        if($this->dataEpochs[$dataPath] !== $entry['e']){
            return false;
        }
        $checked = $entry['e'] . ':' . $entry['s'] . ':' . $entry['h']; // the checksum too: another file may cover the same bytes
        if(($this->secondaryChecked[$dataPath] ?? null) === $checked){
            return true;
        }
        if($this->checkpoints->segmentCrc($dataPath, (int)$entry['s']) !== $entry['h']){
            return false;
        }
        $this->secondaryChecked[$dataPath] = $checked;
        return true;
    }

    /**
     * A segment's data epoch without loading its key index: the cached index, else the checkpoint header
     */
    private function segmentEpochOnDisk($dbname, $shardId){
        $path = $this->checkpoints->getJsonlIndexPath($dbname, $shardId);
        if(isset($this->indexCache[$path])){
            return $this->indexCache[$path]['e'];
        }
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return false;
        }
        $head = (string)fread($fp, 512);
        fclose($fp);
        $this->cache->noteIndexRead(strlen($head));
        return preg_match('/"e":"([0-9a-f]{8})"/', $head, $m) === 1 ? $m[1] : false;
    }

    /**
     * Current data size of a shard: its key index's when this process has it verified (its own writes
     * keep it current), else the file's (cached until a write)
     */
    private function shardDataSize($dbname, $shardId, $dataPath = null){
        $dataPath = $dataPath ?? $this->segments->segDataPath($dbname, $shardId);
        if(isset($this->verifiedIndexes[\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath)], $this->indexCache[\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath)])){
            return (int)$this->indexCache[\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath)]['s'];
        }
        if(!isset($this->dataSizes[$dataPath])){
            clearstatcache(true, $dataPath);
            $this->dataSizes[$dataPath] = (int)@filesize($dataPath);
        }
        return $this->dataSizes[$dataPath];
    }

    /**
     * The shards a lookup in a global field index looks at besides the value's holders: [shard => false]
     * has no entry that fits its data (always read), [shard => true] has lines after its entry (read when
     * they may hold the value). Worked out over all shards once per state of the data (another process's
     * write or a new epoch starts over: invalidateDbCaches(), bumpEpoch()) and kept current with this
     * process's own writes (noteWrite()), so a lookup costs O(holders + such shards), not O(shards).
     * @return array shard id => bool
     */
    private function globalCheckShards($dbname, array $global, $path, $lockPath){
        $seq = $this->segWrites[$lockPath]['seq'] ?? 0;
        $state = $this->globalSkip[$lockPath][$path] ?? null;
        if($state !== null && $state['seq'] === $seq){
            return $state['check'];
        }
        if($state === null){
            // While the lock epoch is the recorded one no data file was replaced: every entry fits
            $fresh = $global['le'] !== null && isset($this->lockRegistry[$lockPath]) && $global['le'] === $this->locks->registryEpoch($lockPath);
            $check = [];
            $prefix = $this->layout->genDir($dbname) . 'segments/s';
            foreach($this->segments->listSegments($dbname) as $shardId){
                $entry = $global['sh'][$shardId] ?? null;
                if($entry === null || (!$fresh && !$this->globalEntryFits($dbname, $shardId, $entry, null))){
                    $check[$shardId] = false;
                } elseif($this->shardDataSize($dbname, $shardId, $prefix . $shardId . '.jsonl') > (int)$entry['s']){
                    $check[$shardId] = true;
                }
            }
            $state = ['seq' => $seq, 'check' => $check];
        } else {
            // This process appended to some shards since: their entries still fit (the bytes they cover
            // didn't change), their newer lines are checked; a new shard has no entry
            $prefix = $this->layout->genDir($dbname) . 'segments/s';
            $len = strlen($prefix);
            foreach($this->segWrites[$lockPath]['paths'] as $dataPath => $at){
                if($at > $state['seq'] && strncmp($dataPath, $prefix, $len) === 0){
                    $shardId = (int)substr($dataPath, $len);
                    if(!isset($state['check'][$shardId])){
                        $state['check'][$shardId] = isset($global['sh'][$shardId]);
                    }
                }
            }
            $state['seq'] = $seq;
        }
        $this->globalSkip[$lockPath][$path] = $state;
        return $state['check'];
    }

    /**
     * True when a shard's data lines after a global entry (whose bytes are covered) may hold a value
     * matching $match (callable(record) => bool)
     */
    public function shardTailMayMatch($dbname, $shardId, array $entry, callable $match){
        if($this->shardDataSize($dbname, $shardId) <= (int)$entry['s']){
            return false;
        }
        $index = $this->checkpoints->readJsonlIndex($dbname, $shardId);
        if($index === null){
            return true;
        }
        foreach($this->secondary->segmentTail($dbname, $shardId, (int)$entry['s'], (int)$index['s']) as [$k, $record]){
            if($record !== null && $match($record)){
                return true;
            }
        }
        return false;
    }

    /**
     * Shards that can contain matches for the equality filters (null = all shards / not sharded)
     * @return array|null
     */
    public function targetShardsFromGlobalIndex($dbname, array $filters){
        if(!$this->fieldIndexEnabled || !$this->segments->isSharded($dbname) || empty($filters)){
            return null;
        }
        $manifest = $this->getIndexManifest($dbname);
        $targets = null;
        $lockPath = null;
        foreach($filters as $field => $value){
            if($field === 'key' || !(is_scalar($value) || $value === null) || !in_array((string)$field, $manifest['fields'], true)){
                continue;
            }
            // Paths built here, not by the helpers: this runs for every lookup of a sharded database
            $lockPath = $lockPath ?? $this->locks->getLockPath($dbname);
            $path = $this->layout->genDir($dbname) . 'indexes/' . ($this->indexTokens[(string)$field] ?? $this->secondary->indexToken($field)) . '.gfx';
            $bucket = is_string($value) ? 's' . $value : \noneDB\Internal\Query\Values::valueKey($value);
            // The value's holders: by the exact map when this process has one, else by the filters
            $hotKey = $path . '#hot';
            $hot = $this->fieldIndexCache[$hotKey] ?? null;
            $held = null;
            if($hot === null){
                $global = $this->fieldIndexCache[$path] ?? null;
                if(isset($global['body']) && empty($global['cols']) && array_key_exists($hotKey, $this->fieldIndexCache)){
                    // No map will come and the body is held: this is the warm lookup of such a process
                    $this->cacheTouch[$path] = ++$this->cacheTick;
                    $this->metrics['hits']++;
                    $this->indexStats['bodyLookups']++; // @test
                    $held = ShardFilters::holdersIn($global['body'], $global['bits'], $global['width'] >> 3, $bucket);
                } else {
                    $global = $this->readGlobalFieldIndex($dbname, $field, $path);
                    if($global !== null && isset($global['ses']) && $global['ses'] !== $this->sessionCounter){
                        $this->fieldIndexCache[$path]['ses'] = $this->sessionCounter; // an operation of the file
                        $global['uses'] = ++$this->fieldIndexCache[$path]['uses'];
                    }
                    $hot = $global === null ? null : $this->globalHotMap($dbname, $field, $hotKey, $global);
                }
            } else {
                $this->cacheTouch[$hotKey] = ++$this->cacheTick;
                $this->metrics['hits']++;
            }
            if($hot !== null){
                $held = $hot['map'][$bucket] ?? [];
                $held = is_int($held) ? [$held] : $held;
                $state = $this->globalSkip[$lockPath][$hotKey] ?? null;
                $check = $state !== null && $state['seq'] === ($this->segWrites[$lockPath]['seq'] ?? 0)
                    ? $state['check'] : $this->globalCheckShards($dbname, $hot, $hotKey, $lockPath);
                $shards = empty($check) ? $held : $this->withTailHolders($dbname, $field, $value, $hot, $held, $check);
                $targets = $targets === null ? $shards : array_values(array_intersect($targets, $shards));
                continue;
            }
            $held = $held ?? ($global === null ? null : $this->globalHolders($path, $bucket));
            if($held === false){
                // Another process replaced the file since its header was read here: read again
                unset($this->fieldIndexCache[$path]);
                $global = $this->readGlobalFieldIndex($dbname, $field, $path);
                $held = $global === null ? null : $this->globalHolders($path, $bucket);
            }
            if(!is_array($held)){
                // None, damaged (or replaced again): made again from the shards
                if($this->noWrites > 0 || $this->multiLock > 0){
                    continue; // checkIntegrity(), a snapshot: build nothing, skip nothing
                }
                $global = $this->locks->withLock($dbname, true, function() use ($dbname, $field){
                    return $this->rebuildGlobalFieldIndex($dbname, $field);
                }, 'buildFieldIndex');
                $held = $this->globalHolders($path, $bucket);
                if(!is_array($held)){
                    continue;
                }
            }
            // The shards whose entry can't rule a value out (worked out once per state of the data)
            $state = $this->globalSkip[$lockPath][$path] ?? null;
            $check = $state !== null && $state['seq'] === ($this->segWrites[$lockPath]['seq'] ?? 0)
                ? $state['check'] : $this->globalCheckShards($dbname, $global, $path, $lockPath);
            $shards = empty($check) ? $held : $this->withTailHolders($dbname, $field, $value, $global, $held, $check);
            $targets = $targets === null ? $shards : array_values(array_intersect($targets, $shards));
        }
        return $targets;
    }

    /**
     * The shards a lookup reads: the holders by the global index, the shards its entries can't rule the value out
     * for ($check: shard => false, no fitting entry: read; true: its newer lines may hold it) and of those with
     * newer lines the ones whose lines hold it
     * @return int[] ascending
     */
    private function withTailHolders($dbname, $field, $value, array $global, array $held, array $check){
        $shards = array_fill_keys($held, true);
        $match = null;
        foreach($check as $shardId => $tail){
            if(isset($shards[$shardId])){
                continue;
            }
            $match = $match ?? function($record) use ($field, $value){
                return array_key_exists($field, $record) && $record[$field] === $value;
            };
            if(!$tail || $this->shardTailMayMatch($dbname, $shardId, $global['sh'][$shardId], $match)){
                $shards[$shardId] = true;
            }
        }
        $shards = array_keys($shards);
        sort($shards);
        return $shards;
    }

    /**
     * The exact map of the values of a global field index to the shards that hold them, built from every shard's
     * field index file at the global index's second operation (lock session) when memory allows: warm lookups
     * without the filters (docs/STORAGE_FORMAT.md §6.5.2 "Lookup"). The files are read, not loaded into the cache;
     * a shard whose file doesn't fit its data has no entry (it is read). The map describes each shard at the bytes
     * its file covers, as the global file's entries do: newer lines are checked, a shard whose data was replaced is
     * read. It stays until the cache drops it. ['le', 'sh' => [shard => entry], 'map' => [bucket => shard |
     * [shards]]]; null when there is none: before the second operation, in checkIntegrity() or a snapshot, or
     * when it doesn't fit (cached as null, the filters answer)
     * @param string $key Its cache key (the global file's path and "#hot"); the caller found nothing under it
     * @return array|null
     */
    private function globalHotMap($dbname, $field, $key, array $global){
        if(array_key_exists($key, $this->fieldIndexCache)){
            return null; // didn't fit (an exact map would have been found by the caller)
        }
        if(!isset($global['uses']) || $global['uses'] < 2 || $this->noWrites > 0 || $this->multiLock > 0){
            return null;
        }
        // Made where it can pay: a web server's request ends with its statics (§6.5.2), and over a million records the
        // build (every shard's file read whole) costs seconds. Elsewhere the filters answer
        $sapi = PHP_SAPI; // @prod
        $sapi = $this->rt->sapi ?? PHP_SAPI; // @test
        $records = self::EXACT_MAP_RECORDS; // @prod
        $records = $this->rt->exactMapRecords ?? self::EXACT_MAP_RECORDS; // @test
        if($sapi !== 'cli' || (int)($this->shardMeta->getCachedMeta($dbname)['totalRecords'] ?? 0) > $records){
            $this->fieldIndexCache[$key] = null;
            return null;
        }
        $limit = \noneDB\Internal\Cache\CacheManager::cacheLimitBytes();
        // The file's sizes bound the map: at most bits / 32 values per shard entry (bits ≥ 32 · the largest c), about
        // 32 bytes a pair. A map whose bound is over the room left under half of the limit is not built, and nothing
        // is read for it (§6.5.2)
        if($limit > 0 && memory_get_usage() + count($global['sh']) * $global['bits'] > intdiv($limit, 2)){
            $this->fieldIndexCache[$key] = null;
            return null;
        }
        $lock = $this->locks->getLockPath($dbname);
        $hot = ['le' => isset($this->lockRegistry[$lock]) ? $this->locks->registryEpoch($lock) : null, 'sh' => [], 'map' => []];
        foreach($this->segments->listSegments($dbname) as $shardId){
            $path = $this->getFieldIndexPath($dbname, $field, $shardId);
            $header = $this->secondary->readSecondaryHeader($path);
            $buckets = null;
            if($header !== null && ($header['v'] ?? null) === 1 && $this->secondary->secondaryFits($dbname, $shardId, $header, 'fx', $field)){
                $held = $this->fieldIndexCache[$path] ?? null;
                if($held !== null && $held['file'] === $header['w']){
                    $buckets = $held['b']; // this process holds the file's buckets as they are: no second decode
                } else {
                    $file = $this->secondary->readSecondaryFile($path);
                    // (the file read whole must be the one whose header was checked)
                    $buckets = $file === null || ($file[0]['w'] ?? null) !== $header['w'] ? null : $this->decodeFieldIndexBody($file[0], $file[1]);
                }
            }
            if($buckets !== null){
                $map = &$hot['map'];
                foreach(array_keys($buckets) as $bucket){
                    if(!isset($map[$bucket])){
                        $map[$bucket] = $shardId;
                    } elseif(is_int($map[$bucket])){
                        $map[$bucket] = [$map[$bucket], $shardId];
                    } else {
                        $map[$bucket][] = $shardId;
                    }
                }
                unset($map);
                $hot['sh'][$shardId] = ['s' => (int)$header['s'], 'e' => $header['e'], 'h' => $header['h']];
            }
            unset($file, $buckets, $held);
            $this->cache->evictBetweenSegments();
            if($limit > 0 && memory_get_usage() > intdiv($limit, 2)){
                $this->fieldIndexCache[$key] = null; // the map would take more than the cache leaves it
                return null;
            }
        }
        $this->fieldIndexCache[$key] = $hot;
        $this->cacheGrew = true;
        return $hot;
    }

    /**
     * Delete the global field index file
     */
    public function deleteGlobalFieldIndex($dbname, $field){
        $path = $this->getGlobalFieldIndexPath($dbname, $field);
        clearstatcache(true, $path);
        if(file_exists($path)){
            $this->io->fsUnlink($path);
        }
        $lock = $this->locks->getLockPath($dbname);
        unset($this->fieldIndexCache[$path], $this->fieldIndexCache[$path . '#hot'], $this->globalSkip[$lock][$path], $this->globalSkip[$lock][$path . '#hot']);
    }

    /**
     * After the switch to the sharded layout: the single layout's index files go; every shard's field and
     * spatial indexes are built from the data
     */
    public function rebuildIndexesAfterSwitch($dbname){
        $manifest = $this->getIndexManifest($dbname);
        foreach($manifest['fields'] as $field){
            $this->deleteFieldIndexFile($dbname, $field, null);
            $this->deleteGlobalFieldIndex($dbname, $field);
            foreach($this->segments->listSegments($dbname) as $shardId){
                $index = $this->buildSegmentFieldIndex($dbname, $shardId, $field);
                $this->writeFieldIndex($dbname, $field, $index, $shardId);
                unset($index);
                $this->cache->evictBetweenSegments();
            }
            $this->rebuildGlobalFieldIndex($dbname, $field);
        }
        foreach($manifest['spatial'] as $field){
            $this->rtree->rebuildSpatialIndexForDb($dbname, $field);
        }
    }
}
