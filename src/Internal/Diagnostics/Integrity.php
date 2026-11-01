<?php
// checkIntegrity(): a read-only check of a database against its data
namespace noneDB\Internal\Diagnostics;

use function array_chunk, array_column, array_diff_key, array_fill_keys, array_intersect_key, array_key_exists, array_keys, array_map, array_merge, array_slice,
    array_values, clearstatcache, count, ctype_digit, fclose, fgets, file_exists, file_get_contents, filesize,
    fopen, is_array, is_file, is_int, is_string, json_decode, ksort, max, scandir, sort, strlen, substr, time;
use const SORT_NUMERIC, SORT_STRING;

/**
 * @internal
 */
final class Integrity {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $integrityChecks;
    private $lastIntegrity;
    private $lockRegistry;
    private $noWrites;
    private $buffer;
    private $cache;
    private $checkpoints;
    private $fieldIndex;
    private $geoJson;
    private $geometry;
    private $io;
    private $layout;
    private $locks;
    private $records;
    private $recoveryLog;
    private $rtree;
    private $secondary;
    private $segments;
    private $shardMeta;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->cache, $this->checkpoints, $this->fieldIndex, $this->geoJson, $this->geometry, $this->io, $this->layout, $this->locks, $this->records, $this->recoveryLog, $this->rtree, $this->secondary, $this->segments, $this->shardMeta);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->integrityChecks = &$this->rt->integrityChecks;
        $this->lastIntegrity = &$this->rt->lastIntegrity;
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
        $this->buffer = null;
        $this->cache = null;
        $this->checkpoints = null;
        $this->fieldIndex = null;
        $this->geoJson = null;
        $this->geometry = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->records = null;
        $this->recoveryLog = null;
        $this->rtree = null;
        $this->secondary = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Check a database against its own data, read-only: every line of every data file is
     * decoded; the key index, field indexes, spatial indexes and shard meta are compared with what the
     * data says. Nothing is written, repaired, flushed or recovered (rebuildIndex() repairs).
     * @param string $dbname
     * @return array ['ok' => bool, 'segments', 'lines', 'records', 'badLines' => [[segment, offset, length]],
     *               'tornTail', 'indexMismatches', 'bufferedRecords', 'pendingRecovery', 'quarantineFiles',
     *               'recoveryLog' => last entries]
     */
    public function checkIntegrity($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        $report = $this->integrityReport($dbname);
        // The result getMetrics() reports (docs/OPERATIONS.md §1), by database instance: a database dropped and
        // created again is another one
        $this->lastIntegrity = ['database' => $dbname, 'ok' => $report['ok'], 'time' => time(), 'records' => $report['records'],
            'issues' => count($report['badLines']) + count($report['tornTail']) + count($report['indexMismatches'])];
        $format = $this->layout->readFormatRecord($dbname);
        if($format !== null){
            $this->integrityChecks[$format['databaseId']] = $this->lastIntegrity;
        }
        return $report;
    }

    /**
     * The report of checkIntegrity(), not kept for getMetrics() (restore() verifies a generation with it)
     */
    public function integrityReport($dbname){
        $check = function() use ($dbname){
            return $this->checkIntegrityLocked($dbname);
        };
        $this->noWrites++;
        try {
            // A missing database is answered without a lock: nothing is created
            return $this->locks->withLock($dbname, false, $check);
        } finally {
            $this->noWrites--;
        }
    }

    private function checkIntegrityLocked($dbname){
        $report = ['ok' => true, 'database' => $dbname, 'exists' => false, 'segments' => 0, 'lines' => 0, 'records' => 0,
            'badLines' => [], 'tornTail' => [], 'indexMismatches' => [], 'bufferedRecords' => 0, 'pendingRecovery' => false,
            'quarantineFiles' => [], 'recoveryLog' => []];
        $lock = $this->lockRegistry[$this->locks->getLockPath($dbname)] ?? null;
        if($lock !== null && (!empty($lock['flags']['j']) || !empty($lock['flags']['c']) || !empty($lock['flags']['d'])
            || !empty($lock['flags']['g']) || $lock['epoch'] === null)){
            $report['pendingRecovery'] = true; // the next writer or reader recovers (journal, creation, drop, generation)
        }
        if(!$this->layout->dbPresent($dbname)){
            $report['ok'] = !$report['pendingRecovery'];
            return $report;
        }
        // Generations other than CURRENT's: left by an interrupted switch, never removed without proof
        $current = $this->layout->generationNumber($dbname);
        foreach(@scandir($this->layout->dbHome($dbname) . 'generations') ?: [] as $generation){
            if(ctype_digit((string)$generation) && (int)$generation !== $current){
                $report['indexMismatches'][] = ['segment' => null, 'index' => 'generation', 'detail' => "generation $generation is not the current one ($current)"];
            }
        }
        $segPrefix = $this->layout->genDir($dbname) . 'segments/s';
        $metaPath = $this->shardMeta->getMetaPath($dbname);
        clearstatcache(true, $metaPath);
        $sharded = file_exists($metaPath);
        $meta = $sharded ? $this->shardMeta->readMeta($dbname) : null;
        if($sharded && $meta === null){
            $report['indexMismatches'][] = ['segment' => null, 'index' => 'meta', 'detail' => 'meta file unreadable'];
        }
        $segments = $sharded ? array_map(function($f) use ($segPrefix){ return (int)substr($f, strlen($segPrefix)); },
            $this->segments->listFilesWithPrefix($segPrefix, '/^\d+\.jsonl$/')) : (is_file($this->segments->getDataPath($dbname)) ? [null] : []);
        sort($segments);
        $report['exists'] = !empty($segments) || $sharded;
        $size = $meta !== null ? (int)$meta['shardSize'] : $this->segments->shardSizeOf($dbname);
        if($sharded && $meta === null){
            try {
                $size = (int)$this->shardMeta->deriveShardMeta($dbname)['shardSize']; // as recovery would rebuild it
            } catch(\RuntimeException $e){
            }
        }
        $manifest = $this->deriveOrReadManifest($dbname);
        $liveTotal = 0;
        $maxKey = -1;
        $byShard = [];

        $done = false; // a segment was checked: the cache may drop what it loaded before the next one
        foreach($segments as $shardId){
            if($done){
                $this->cache->evictBetweenSegments();
            }
            $done = true;
            $report['segments']++;
            $seg = $shardId === null ? 'main' : $shardId;
            $dataPath = $this->segments->segDataPath($dbname, $shardId);
            // The live records of the data: key => offset, key => length (as a key index holds them)
            $livePos = [];
            $liveLen = [];
            $offset = 0;
            $fp = @fopen($dataPath, 'rb');
            while($fp !== false && ($line = fgets($fp)) !== false){
                if(substr($line, -1) !== "\n"){
                    $report['tornTail'][] = ['segment' => $seg, 'offset' => $offset, 'length' => strlen($line)];
                    break;
                }
                $report['lines']++;
                $entry = $this->checkpoints->classifyLine(substr($line, 0, -1), $shardId === null ? 0 : $shardId * $size,
                    $shardId === null ? null : ($shardId + 1) * $size);
                if($entry === null){
                    $report['badLines'][] = [$seg, $offset, strlen($line)];
                } elseif($entry[0] === 't'){
                    unset($livePos[$entry[1]], $liveLen[$entry[1]]);
                    $maxKey = max($maxKey, $entry[1]);
                } else {
                    $livePos[$entry[1]] = $offset;
                    $liveLen[$entry[1]] = $entry[3];
                    $maxKey = max($maxKey, $entry[1]);
                }
                $offset += strlen($line);
            }
            if($fp !== false){
                fclose($fp);
            }
            ksort($livePos, SORT_NUMERIC);
            $liveTotal += count($livePos);
            $byShard[$shardId === null ? 'main' : $shardId] = count($livePos);

            // Key index: its checkpoint fits the data file, and with the complete lines after it
            // gives the same records at the same places. A missing one is rebuilt from the data on the next
            // access (normal: nothing lost), a broken one too, but that is reported.
            $kixPath = \noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath);
            clearstatcache(true, $kixPath);
            $raw = is_file($kixPath) && filesize($kixPath) > 0 ? @file_get_contents($kixPath) : null; // empty: missing
            $file = $raw === null ? null : \noneDB\Internal\Storage\KeyIndex::decodeFile($raw);
            clearstatcache(true, $dataPath);
            $fileSize = (int)@filesize($dataPath);
            $index = null;
            if($raw !== null && $file === null){
                $report['indexMismatches'][] = ['segment' => $seg, 'index' => 'key', 'detail' => 'not a valid checkpoint (rebuilt on next access)'];
            } elseif($file !== null){
                if(!$this->checkpoints->checkpointFits($dataPath, $file[0]['s'], $fileSize, $file[0]['h'] ?? null)){
                    $report['indexMismatches'][] = ['segment' => $seg, 'index' => 'key', 'detail' => 'checkpoint does not fit the data file (rebuilt on next access)'];
                } else {
                    $index = \noneDB\Internal\Storage\KeyIndex::fromFile($file[0], $file[1]);
                }
            }
            if($index !== null){
                $this->checkpoints->replaySegment($dbname, $shardId, $index, $fileSize, null, false);
                [$indexPos, $indexLen] = \noneDB\Internal\Storage\KeyIndex::locate($index, null);
                if($indexPos != $livePos || $indexLen != $liveLen){
                    $report['indexMismatches'][] = ['segment' => $seg, 'index' => 'key', 'detail' => count(array_diff_key($livePos, $indexPos)) . ' records not indexed, '
                        . count(array_diff_key($indexPos, $livePos)) . ' indexed records not in the data or elsewhere'];
                }
                $indexPos = $indexLen = null;
            }

            // Field and spatial indexes against the records: what they must hold, from the records read a batch
            // at a time (RecordIO::BATCH, in key order)
            $expectedFields = array_fill_keys($manifest['fields'], []);
            $expectedSpatial = array_fill_keys($manifest['spatial'], []);
            foreach(empty($manifest['fields']) && empty($manifest['spatial']) ? [] : array_chunk($livePos, \noneDB\Internal\Storage\RecordIO::BATCH, true) as $chunk){
                $records = $this->records->readJsonlRecordsBatch($dataPath, $chunk, array_intersect_key($liveLen, $chunk));
                ksort($records, SORT_NUMERIC);
                foreach($manifest['fields'] as $field){
                    foreach($records as $key => $record){
                        if(array_key_exists($field, $record) && $this->fieldIndex->isIndexableValue($record[$field])){
                            $bucket = \noneDB\Internal\Query\Values::valueKey($record[$field]);
                            $held = $expectedFields[$field][$bucket] ?? null;
                            if($held === null){
                                $expectedFields[$field][$bucket] = (int)$key;
                            } elseif(is_int($held)){
                                $expectedFields[$field][$bucket] = [$held, (int)$key];
                            } else {
                                $expectedFields[$field][$bucket][] = (int)$key;
                            }
                        }
                    }
                }
                foreach($manifest['spatial'] as $field){
                    foreach($records as $key => $record){
                        $geometry = $record[$field] ?? null;
                        if(is_array($geometry) && $this->geoJson->validateGeoJSON($geometry)['valid'] && $this->geometry->calculateMBR($geometry) !== null){
                            $expectedSpatial[$field][] = (int)$key;
                        }
                    }
                }
                $records = null;
            }
            // Field and spatial indexes: one that fits the segment (its epoch, the bytes it covers), with the
            // lines after it replayed, must hold exactly the records; one that doesn't fit is rebuilt when
            // used (not damage).
            $keyEpoch = is_array($index) && isset($index['e']) && is_string($index['e']) ? $index['e'] : null;
            foreach($manifest['fields'] as $field){
                $actual = $this->integrityFieldBuckets($dbname, $shardId, $field, $keyEpoch, $offset);
                if($actual === null){
                    continue; // built on first use
                }
                \noneDB\Internal\Index\FieldIndex::canonicalBuckets($expectedFields[$field]);
                \noneDB\Internal\Index\FieldIndex::canonicalBuckets($actual);
                if($actual !== $expectedFields[$field]){
                    $report['indexMismatches'][] = ['segment' => $seg, 'index' => 'field ' . $field, 'detail' => 'buckets differ from the records'];
                }
            }
            foreach($manifest['spatial'] as $field){
                $expected = $expectedSpatial[$field];
                $actual = $this->integritySpatialKeys($dbname, $shardId, $field, $keyEpoch, $offset);
                if($actual === null){
                    continue;
                }
                sort($actual);
                if($actual !== $expected){
                    $report['indexMismatches'][] = ['segment' => $seg, 'index' => 'spatial ' . $field, 'detail' => 'entries differ from the records'];
                }
            }
        }
        $report['records'] = $liveTotal;

        if($meta !== null){
            $listed = array_map('intval', array_column($meta['shards'], 'id'));
            sort($listed);
            if($listed !== $segments){
                $report['indexMismatches'][] = ['segment' => null, 'index' => 'meta', 'detail' => 'shards listed in the meta differ from the shard files'];
            }
            if((int)($meta['totalRecords'] ?? -1) !== $liveTotal){
                $report['indexMismatches'][] = ['segment' => null, 'index' => 'meta', 'detail' => "totalRecords {$meta['totalRecords']}, data has $liveTotal"];
            }
            if((int)$meta['nextKey'] <= $maxKey){
                $report['indexMismatches'][] = ['segment' => null, 'index' => 'meta', 'detail' => "nextKey {$meta['nextKey']} would reuse key $maxKey"];
            }
        }

        foreach(array_merge([$this->buffer->getBufferPath($dbname)], $this->buffer->flushingFiles($dbname)) as $buffer){
            if(is_file($buffer)){
                $report['bufferedRecords'] += count($this->buffer->readBufferRecords($buffer));
            }
        }
        $report['quarantineFiles'] = array_map('basename', $this->segments->listFilesWithPrefix($this->layout->dbHome($dbname) . 'recovery/q.', '/\.bin$/'));
        $report['recoveryLog'] = array_slice($this->recoveryLog->getRecoveryLogEntries($dbname), -20);
        $report['ok'] = empty($report['badLines']) && empty($report['tornTail']) && empty($report['indexMismatches']) && !$report['pendingRecovery'];
        return $report;
    }

    /**
     * The index list without writing anything (checkIntegrity)
     */
    private function deriveOrReadManifest($dbname){
        $path = $this->fieldIndex->getIndexManifestPath($dbname);
        $content = is_file($path) ? @file_get_contents($path) : false;
        $manifest = $content === false ? [] : json_decode($content, true);
        if(!is_array($manifest) || ($content !== false && ($manifest['kind'] ?? null) !== 'indexes')){
            $manifest = $this->fieldIndex->deriveIndexManifest($dbname);
        }
        return ['fields' => array_values($manifest['fields'] ?? []), 'spatial' => array_values($manifest['spatial'] ?? [])];
    }

    /**
     * checkIntegrity(): buckets of a segment's field index that fits the key checkpoint, with the
     * data lines up to $complete replayed; null when there is none that fits
     */
    private function integrityFieldBuckets($dbname, $shardId, $field, $keyEpoch, $complete){
        $file = $this->secondary->readSecondaryFile($this->fieldIndex->getFieldIndexPath($dbname, $field, $shardId));
        if($file === null || !$this->integrityHeaderFits($dbname, $shardId, $file[0], 'fx', $field, $keyEpoch, $complete)){
            return null;
        }
        $buckets = $this->fieldIndex->decodeFieldIndexBody($file[0], $file[1]);
        if($buckets === null){
            return []; // pages that can't be read hold none of the records
        }
        $index = $this->fieldIndex->newFieldIndex($field);
        $index['b'] = $buckets;
        $index['s'] = $file[0]['s'];
        $index['e'] = $file[0]['e'];
        $index['mk'] = (int)($file[0]['mk'] ?? -1);
        $this->fieldIndex->replayFieldIndex($dbname, $shardId, $index, $complete);
        return $index['b'];
    }

    /**
     * checkIntegrity(): keys of a segment's R-tree that fits the key checkpoint, replayed up to
     * $complete; null when there is none that fits
     */
    private function integritySpatialKeys($dbname, $shardId, $field, $keyEpoch, $complete){
        $file = $this->secondary->readSecondaryFile($this->rtree->getSpatialIndexPath($dbname, $field, $shardId));
        if($file === null || !$this->integrityHeaderFits($dbname, $shardId, $file[0], 'rx', $field, $keyEpoch, $complete)){
            return null;
        }
        $tree = $this->rtree->treeOf($field, $file[0], $file[1]);
        if($tree === null){
            return []; // a body that can't be read holds none of the records
        }
        $this->rtree->replaySpatialIndex($dbname, $shardId, $tree, $complete);
        $this->rtree->rtreeMaps($tree);
        return array_keys($tree['leafOf']);
    }

    private function integrityHeaderFits($dbname, $shardId, array $header, $kind, $field, $keyEpoch, $complete){
        return ($header['kind'] ?? null) === $kind && ($header['field'] ?? null) === (string)$field && $keyEpoch !== null
            && ($header['e'] ?? null) === $keyEpoch && is_int($header['s'] ?? null) && $header['s'] >= 0 && $header['s'] <= $complete
            && $this->checkpoints->segmentCrc($this->segments->segDataPath($dbname, $shardId), $header['s']) === ($header['h'] ?? null);
    }
}
