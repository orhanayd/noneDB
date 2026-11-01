<?php
// Query plans: index candidates, shard skipping, batched scans, explain()
namespace noneDB\Internal\Query;

use function array_fill_keys, array_filter, array_flip, array_intersect_key, array_key_exists, array_keys,
    array_slice, array_values, count, intdiv, is_array, min, sort;
use const PHP_INT_MIN, SORT_NUMERIC;

/**
 * @internal
 */
final class Planner {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $autoCreateDB;
    private $fieldIndexEnabled;
    private $noWrites;
    private $cache;
    private $checkpoints;
    private $fieldIndex;
    private $findFilter;
    private $locks;
    private $records;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->fieldIndex, $this->findFilter, $this->locks, $this->records, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->autoCreateDB = &$this->k->autoCreateDB;
        $this->fieldIndexEnabled = &$this->k->fieldIndexEnabled;
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
        $this->fieldIndex = null;
        $this->findFilter = null;
        $this->locks = null;
        $this->records = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // QUERY PLANS AND CURSORS
    // ==========================================
    // A plan is a tree of what a query's filters ask for that indexes can answer:
    //   ['eq', field, value]  ['in', field, values]  ['range', field, operator, operand]
    //   ['keys', [key, ...]]  ['any']  ['and', [nodes]]  ['or', [nodes]]
    // For each segment it gives candidate keys (a superset of the matches: the caller checks every record
    // with the whole filter) or null (scan the segment); shards it can rule out (global field indexes,
    // key ranges) are skipped. Negations (whereNot, $ne, $nin, notLike, ...) never use indexes.
    // scanRecords() reads the candidates of every segment in key order, in batches (32, growing to 4096),
    // and stops as soon as the visitor says so: first(), exists() and limit() read what they need.

    /**
     * Candidate keys of a segment for a plan node; null when the node can't narrow the segment down
     * @return int[]|null sorted
     */
    private function planCandidates($dbname, $shardId, array $node){
        switch($node[0]){
            case 'eq':
                if(!$this->fieldIndexEnabled || !$this->fieldIndex->isIndexableValue($node[2]) || !$this->fieldIndex->isFieldIndexed($dbname, $node[1])){
                    return null;
                }
                return $this->fieldIndex->fieldIndexCandidates($dbname, $shardId, [$node[1] => $node[2]]);
            case 'in':
                if(!$this->fieldIndexEnabled || !$this->fieldIndex->isFieldIndexed($dbname, $node[1])){
                    return null;
                }
                $keys = [];
                foreach($node[2] as $value){
                    if(!$this->fieldIndex->isIndexableValue($value)){
                        return null;
                    }
                    $found = $this->fieldIndex->fieldIndexCandidates($dbname, $shardId, [$node[1] => $value]);
                    if($found === null){
                        return null;
                    }
                    foreach($found as $k){
                        $keys[$k] = $k;
                    }
                }
                return array_values($keys);
            case 'range':
                return $this->fieldIndex->fieldIndexRangeCandidates($dbname, $shardId, $node[1], [$node[2] => $node[3]]);
            case 'keys':
                $index = $this->checkpoints->readJsonlIndex($dbname, $shardId);
                $keys = [];
                foreach($node[1] as $k){
                    if($index !== null && \noneDB\Internal\Storage\KeyIndex::has($index, (int)$k)){
                        $keys[$k] = $k;
                    }
                }
                return array_values($keys);
            case 'and':
                $result = null;
                foreach($node[1] as $child){
                    $found = $this->planCandidates($dbname, $shardId, $child);
                    if($found === null){
                        continue;
                    }
                    $result = $result === null ? $found : array_keys(array_intersect_key(array_flip($result), array_flip($found)));
                    if(empty($result)){
                        return [];
                    }
                }
                return $result;
            case 'or':
                $keys = [];
                foreach($node[1] as $child){
                    $found = $this->planCandidates($dbname, $shardId, $child);
                    if($found === null){
                        return null; // one branch needs a scan: the whole segment
                    }
                    foreach($found as $k){
                        $keys[$k] = $k;
                    }
                }
                return array_values($keys);
        }
        return null;
    }

    /**
     * Shards a plan node can match in (null: all). Only skips a shard when it can't hold a match.
     * @return array|null shard id => true
     */
    private function planShards($dbname, array $node){
        if(!$this->segments->isSharded($dbname)){
            return null;
        }
        switch($node[0]){
            case 'eq':
                if(!$this->fieldIndex->isIndexableValue($node[2])){
                    return null;
                }
                $shards = $this->fieldIndex->targetShardsFromGlobalIndex($dbname, [$node[1] => $node[2]]);
                return $shards === null ? null : array_fill_keys($shards, true);
            case 'in':
                $all = [];
                foreach($node[2] as $value){
                    $found = $this->planShards($dbname, ['eq', $node[1], $value]);
                    if($found === null){
                        return null;
                    }
                    $all += $found;
                }
                return $all;
            case 'keys':
                $size = $this->segments->shardSizeOf($dbname);
                $shards = [];
                foreach($node[1] as $k){
                    $shards[intdiv($k, $size)] = true;
                }
                return $shards;
            case 'and':
                $result = null;
                foreach($node[1] as $child){
                    $found = $this->planShards($dbname, $child);
                    if($found !== null){
                        $result = $result === null ? $found : array_intersect_key($result, $found);
                    }
                }
                return $result;
            case 'or':
                $all = [];
                foreach($node[1] as $child){
                    $found = $this->planShards($dbname, $child);
                    if($found === null){
                        return null;
                    }
                    $all += $found;
                }
                return $all;
        }
        return null;
    }

    /**
     * Read the records a plan allows, segment by segment in key order, in batches: $visit([key => record])
     * gets each batch (never an empty one) in key order and returns false to stop. Caller holds the lock.
     * @param bool $whole The visitor never stops (count, top-k): each segment is read in one go (RecordIO::BATCH records at a time when it is larger)
     * @return array ['segments', 'skipped', 'indexed', 'scanned', 'recordsRead', 'plan']
     */
    public function scanRecords($dbname, array $plan, callable $visit, $whole = false){
        $stats = ['segments' => 0, 'skipped' => 0, 'indexed' => 0, 'scanned' => 0, 'recordsRead' => 0, 'plan' => []];
        $segments = $this->segments->listSegments($dbname);
        if(empty($segments)){
            return $stats;
        }
        $shards = $plan[0] === 'any' ? null : $this->planShards($dbname, $plan);
        $done = false; // a segment was read: the cache may drop what it loaded before the next one (not after the last)
        foreach($segments as $segment){
            if($done){
                $this->cache->evictBetweenSegments();
                $done = false;
            }
            $stats['segments']++;
            if($shards !== null && !isset($shards[$segment])){
                $stats['skipped']++;
                if(count($stats['plan']) < 50){
                    $stats['plan'][] = ['segment' => $segment, 'access' => 'skip', 'candidates' => 0];
                }
                continue;
            }
            $keys = $plan[0] === 'any' ? null : $this->planCandidates($dbname, $segment, $plan);
            $access = $keys === null ? 'scan' : 'index';
            if($keys === null){
                $stats['scanned']++;
                $index = $this->checkpoints->readJsonlIndex($dbname, $segment);
                $count = $index === null ? 0 : \noneDB\Internal\Storage\KeyIndex::count($index);
                // A scan that can't stop early reads the segment as a whole (RecordIO::eachSegmentBatch()): no key list
                $keys = $whole || $count === 0 ? null : \noneDB\Internal\Storage\KeyIndex::keys($index);
            } else {
                $stats['indexed']++;
                $count = count($keys);
            }
            if(count($stats['plan']) < 50){
                $stats['plan'][] = ['segment' => $segment, 'access' => $access, 'candidates' => $count];
            }
            if($count === 0){
                continue;
            }
            if($keys === null){
                foreach($this->records->eachSegmentBatch($dbname, $segment) as $records){
                    $stats['recordsRead'] += count($records);
                    if($visit($records) === false){
                        return $stats;
                    }
                }
                $done = true;
                continue;
            }
            // Keys come in ascending order almost always (they are assigned in order, an update keeps a
            // key's place in the index): sorted only when they aren't
            $previous = PHP_INT_MIN;
            foreach($keys as $k){
                if($k < $previous){
                    sort($keys, SORT_NUMERIC);
                    break;
                }
                $previous = $k;
            }
            if($whole){
                // Index candidates for a visitor that never stops: a batch at a time (RecordIO::BATCH)
                foreach($this->records->eachSegmentBatch($dbname, $segment, $keys) as $records){
                    $stats['recordsRead'] += count($records);
                    if($visit($records) === false){
                        return $stats;
                    }
                }
                $done = true;
                continue;
            }
            // Batches that grow from 32: a visitor that stops early reads little
            $n = count($keys);
            for($i = 0, $batch = 32; $i < $n; $i += $batch, $batch = min(4096, $batch * 4)){
                $records = $this->records->readSegmentRecords($dbname, $segment, $access === 'scan' && $i === 0 && $batch >= $n ? null : array_slice($keys, $i, $batch));
                $stats['recordsRead'] += count($records);
                if(!empty($records) && $visit($records) === false){
                    return $stats;
                }
            }
            $done = true;
        }
        return $stats;
    }

    /**
     * explain(): what scanRecords() would do, without reading records or building anything (an index that
     * a read would build counts as a scan)
     */
    public function explainPlan($dbname, array $plan){
        $explain = function() use ($dbname, $plan){
            $out = ['sharded' => $this->segments->dbExists($dbname) && $this->segments->isSharded($dbname), 'segments' => ['total' => 0, 'skipped' => 0, 'indexed' => 0, 'scanned' => 0],
                'plan' => [], 'estimatedRead' => 0];
            if(!$this->segments->dbExists($dbname)){
                return $out;
            }
            $shards = $plan[0] === 'any' ? null : $this->planShards($dbname, $plan);
            foreach($this->segments->listSegments($dbname) as $segment){
                $out['segments']['total']++;
                if($shards !== null && !isset($shards[$segment])){
                    $out['segments']['skipped']++;
                    $entry = ['segment' => $segment, 'access' => 'skip', 'candidates' => 0];
                } else {
                    $keys = $plan[0] === 'any' ? null : $this->planCandidates($dbname, $segment, $plan);
                    if($keys === null){
                        $out['segments']['scanned']++;
                        $index = $this->checkpoints->readJsonlIndex($dbname, $segment);
                        $entry = ['segment' => $segment, 'access' => 'scan', 'candidates' => $index === null ? 0 : \noneDB\Internal\Storage\KeyIndex::count($index)];
                    } else {
                        $out['segments']['indexed']++;
                        $entry = ['segment' => $segment, 'access' => 'index', 'candidates' => count($keys)];
                    }
                }
                $out['estimatedRead'] += $entry['candidates'];
                if(count($out['plan']) < 50){
                    $out['plan'][] = $entry;
                }
                unset($index, $keys);
                $this->cache->evictBetweenSegments();
            }
            $out['planTruncated'] = $out['segments']['total'] > 50;
            return $out;
        };
        $this->noWrites++;
        try {
            return $this->locks->withLock($dbname, false, $explain);
        } finally {
            $this->noWrites--;
        }
    }


    /**
     * Visit the records matching the filter parameter of find(), count(), sum(), ... (FindFilter::normalizeFilter)
     * in find()'s order, none kept: $visit([key => record]) gets the matching records of each batch read and
     * returns false to stop. One segment's records are held at a time (a "key" filter: the records of its keys,
     * in its order, in one batch); indexes plan the scan. False for an invalid filter or a missing database
     * without autoCreateDB (where find() returns false). Caller holds the lock.
     * @param bool $whole The visitor never stops (count, aggregations): each segment is read in one go (RecordIO::BATCH records at a time when it is larger)
     * @return bool
     */
    public function eachMatch($dbname, $filter, callable $visit, $whole = false){
        $filters = $this->findFilter->normalizeFilter($filter);
        if($filters === null){
            return false;
        }
        if(!$this->segments->dbExists($dbname)){
            return (bool)$this->autoCreateDB;
        }
        if(array_key_exists('key', $filters)){
            $records = $this->findFilter->selectRecords($dbname, $filters);
            if(!empty($records)){
                $visit($records);
            }
            return true;
        }
        $and = [];
        foreach($filters as $field => $value){
            $and[] = is_array($value) ? ['any'] : ['eq', (string)$field, $value];
        }
        $plan = count($and) === 1 ? $and[0] : ['and', $and];
        if($plan[0] === 'and'){
            $plan[1] = array_values(array_filter($plan[1], function($node){ return $node[0] !== 'any'; }));
            $plan = empty($plan[1]) ? ['any'] : (count($plan[1]) === 1 ? $plan[1][0] : $plan);
        }
        $this->scanRecords($dbname, $plan, empty($filters) ? $visit : function(array $records) use ($filters, $visit){
            $matches = [];
            foreach($records as $key => $record){
                if($this->findFilter->matchesFilters($record, $filters)){
                    $matches[$key] = $record;
                }
            }
            return empty($matches) || $visit($matches) !== false;
        }, $whole);
        return true;
    }
}
