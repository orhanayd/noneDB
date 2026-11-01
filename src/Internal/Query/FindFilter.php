<?php
// Filters of find(), update() and delete(): normalization and matching
namespace noneDB\Internal\Query;

use function array_chunk, array_flip, array_key_exists, array_values, count, in_array, is_array, is_int, is_string, preg_match;

/**
 * @internal
 */
final class FindFilter {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $cache;
    private $checkpoints;
    private $fieldIndex;
    private $records;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->fieldIndex, $this->records, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
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
        $this->records = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * The filter parameter of find(), count(), sum(), ...: 0, null (all records) and arrays are filters,
     * anything else is invalid (null)
     * @return array|null
     */
    public function normalizeFilter($filter){
        if($filter === null || is_int($filter)){
            return [];
        }
        return is_array($filter) ? $filter : null;
    }

    // ==========================================
    // FILTER MATCHING (find / update / delete)
    // ==========================================

    /**
     * Normalize a "key" filter value to a list of int keys (invalid values match nothing)
     * @return array
     */
    public function normalizeKeyFilter($value){
        $values = is_array($value) ? $value : [$value];
        $keys = [];
        foreach($values as $v){
            if(is_int($v)){
                $k = $v;
            } elseif(is_string($v) && preg_match('/\A(0|[1-9][0-9]*)\z/', $v)){ // \z: "5\n" is not 5
                $k = (int)$v;
            } else {
                continue;
            }
            if($k >= 0 && !isset($keys[$k])){
                $keys[$k] = $k;
            }
        }
        return array_values($keys);
    }

    /**
     * Strict equality filter used by find(), update() and delete():
     * every field must exist in the record and be identical (===) to the filter value.
     * "key" filters compare against the record key.
     */
    public function matchesFilters(array $record, array $filters){
        foreach($filters as $field => $value){
            if($field === 'key'){
                if(!isset($record['key']) || !in_array($record['key'], $this->normalizeKeyFilter($value), true)){
                    return false;
                }
                continue;
            }
            if(!array_key_exists($field, $record) || $record[$field] !== $value){
                return false;
            }
        }
        return true;
    }

    /**
     * Records matching $filters, in key order (or in the order of a "key" filter)
     * @return array [key => record]
     */
    public function selectRecords($dbname, array $filters){
        $result = [];
        foreach($this->segmentMatches($dbname, $filters) as $records){
            $result += $records;
        }
        if(count($result) > 1 && array_key_exists('key', $filters)){
            $ordered = [];
            foreach($this->normalizeKeyFilter($filters['key']) as $key){
                if(isset($result[$key])){
                    $ordered[$key] = $result[$key];
                }
            }
            return $ordered;
        }
        return $result;
    }

    /**
     * The records matching $filters one segment at a time: yields segment id (null: a single-layout database)
     * => [key => record], in key order (a "key" filter: in its order within the segment). Once the consumer is
     * done with a segment the cache may drop what it loaded (evictBetweenSegments()), so a write by filter
     * over a large database holds one segment's records. Caller holds the lock.
     * @return \Generator
     */
    public function segmentMatches($dbname, array $filters){
        if(array_key_exists('key', $filters)){
            $bySegment = [];
            foreach($this->normalizeKeyFilter($filters['key']) as $key){
                $segment = $this->segments->segmentForKey($dbname, $key);
                if($segment !== false){
                    $bySegment[$segment === null ? '' : $segment][] = $key;
                }
            }
            unset($filters['key']);
            $done = false; // a segment was read: the cache may drop what it loaded before the next one (not after the last)
            foreach($bySegment as $segment => $keys){
                if($done){
                    $this->cache->evictBetweenSegments();
                }
                $done = true;
                $segment = $segment === '' ? null : (int)$segment;
                foreach(array_chunk($keys, \noneDB\Internal\Storage\RecordIO::BATCH) as $chunk){
                    // In the order of the filter's keys
                    $found = $this->records->readSegmentRecords($dbname, $segment, $chunk);
                    $matches = [];
                    foreach($chunk as $key){
                        if(isset($found[$key]) && $this->matchesFilters($found[$key], $filters)){
                            $matches[$key] = $found[$key];
                        }
                    }
                    unset($found);
                    if(!empty($matches)){
                        yield $segment => $matches;
                    }
                }
            }
            return;
        }

        // Shards the global field indexes allow (null: every segment); none: nothing to read at all
        $targets = $this->fieldIndex->targetShardsFromGlobalIndex($dbname, $filters);
        if($targets === []){
            return;
        }
        $segments = $this->segments->listSegments($dbname);
        if($targets !== null){
            // The targets that are segments (a global index may still name a removed shard), in order
            $present = array_flip($segments);
            $segments = [];
            foreach($targets as $target){
                if(isset($present[$target])){
                    $segments[] = $target;
                }
            }
        }
        $usable = $this->fieldIndex->indexedFilters($dbname, $filters);
        $done = false;
        foreach($segments as $segment){
            if($done){
                $this->cache->evictBetweenSegments();
                $done = false;
            }
            $keyIndex = null;
            $candidates = null;
            if(!empty($usable)){
                $keyIndex = $this->checkpoints->readJsonlIndex($dbname, $segment);
                $candidates = $this->fieldIndex->segmentCandidates($dbname, $segment, $usable, $keyIndex);
                if($candidates === []){
                    continue;
                }
            }
            foreach($this->records->eachSegmentBatch($dbname, $segment, $candidates, $keyIndex) as &$batch){
                $matches = [];
                foreach($batch as $key => $record){
                    if(empty($filters) || $this->matchesFilters($record, $filters)){
                        $matches[$key] = $record;
                    }
                }
                $batch = null; // the records that don't match go before the caller writes the matches
                if(!empty($matches)){
                    yield $segment => $matches;
                }
            }
            unset($batch);
            $done = true;
        }
    }
}
