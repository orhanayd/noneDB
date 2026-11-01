<?php
// first(), last(), exists(), between()
namespace noneDB\Internal\Query;

use function array_key_exists;

/**
 * @internal
 */
final class Utility {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $fieldIndexEnabled;
    private $cache;
    private $fieldIndex;
    private $findFilter;
    private $layout;
    private $locks;
    private $planner;
    private $records;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->fieldIndex, $this->findFilter, $this->layout, $this->locks, $this->planner, $this->records, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->fieldIndexEnabled = &$this->k->fieldIndexEnabled;
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
        $this->findFilter = null;
        $this->layout = null;
        $this->locks = null;
        $this->planner = null;
        $this->records = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // UTILITY METHODS
    // ==========================================

    /**
     * Get first matching record
     * @param string $dbname
     * @param mixed $filter
     * @return array|null
     */
    public function first($dbname, $filter = 0){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $filter){
            return $this->firstUnlocked($dbname, $filter);
        });
    }

    private function firstUnlocked($dbname, $filter = 0){
        $first = null;
        $this->planner->eachMatch($dbname, $filter, function(array $records) use (&$first){ $first = reset($records); return false; });
        return $first;
    }

    /**
     * Get last matching record
     * @param string $dbname
     * @param mixed $filter
     * @return array|null
     */
    public function last($dbname, $filter = 0){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $filter){
            return $this->lastUnlocked($dbname, $filter);
        });
    }

    private function lastUnlocked($dbname, $filter = 0){
        $last = null;
        $this->planner->eachMatch($dbname, $filter, function(array $records) use (&$last){ $last = end($records); }, true);
        return $last;
    }

    /**
     * Check if records exist matching filter
     * @param string $dbname
     * @param mixed $filter
     * @return bool
     */
    public function exists($dbname, $filter){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $filter){
            return $this->existsUnlocked($dbname, $filter);
        });
    }

    private function existsUnlocked($dbname, $filter){
        $found = false;
        $this->planner->eachMatch($dbname, $filter, function() use (&$found){ $found = true; return false; });
        return $found;
    }

    /**
     * Range query (min <= value <= max)
     * @param string $dbname
     * @param string $field
     * @param mixed $min
     * @param mixed $max
     * @param mixed $filter Additional filter
     * @return array|false
     */
    public function between($dbname, $field, $min, $max, $filter = 0){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $min, $max, $filter){
            return $this->betweenUnlocked($dbname, $field, $min, $max, $filter);
        });
    }

    private function betweenUnlocked($dbname, $field, $min, $max, $filter = 0){
        // Same comparison as query()->between(): numbers (and numeric strings) numerically, strings
        // lexically; null, booleans, arrays and number-vs-text are never in range
        $result = [];
        $collect = function(array $records) use ($field, $min, $max, &$result){
            foreach($records as $record){
                if(!array_key_exists($field, $record)){
                    continue;
                }
                $lo = \noneDB\Internal\Query\Values::compareValues($record[$field], $min);
                $hi = \noneDB\Internal\Query\Values::compareValues($record[$field], $max);
                if($lo !== null && $hi !== null && $lo >= 0 && $hi <= 0){
                    $result[] = $record;
                }
            }
        };
        // An indexed field: only the records whose value the field index puts in range are read
        if(!$this->eachRangeCandidate($dbname, $field, ['$gte' => $min, '$lte' => $max], $filter, $collect)
            && !$this->planner->eachMatch($dbname, $filter, $collect, true)){
            return false;
        }
        return $result;
    }

    /**
     * Visit the records matching a filter parameter whose field value may satisfy the range conditions, read
     * through the field index of every segment (a superset: the visitor checks the range), in key order, one
     * segment at a time: $visit([key => record]) gets a segment's (never none). False when it can't: no index
     * of the field, a key filter decides, an invalid filter or no database (the caller scans).
     * @return bool
     */
    private function eachRangeCandidate($dbname, $field, array $conditions, $filter, callable $visit){
        $filters = $this->findFilter->normalizeFilter($filter);
        if($filters === null || array_key_exists('key', $filters) || !$this->segments->dbExists($dbname) || !$this->fieldIndexEnabled
            || !$this->fieldIndex->isFieldIndexed($dbname, $field)){
            return false;
        }
        $done = false; // a segment was read: the cache may drop what it loaded before the next one (not after the last)
        foreach($this->segments->listSegments($dbname) as $segment){
            if($done){
                $this->cache->evictBetweenSegments();
            }
            $done = true;
            $keys = $this->fieldIndex->fieldIndexRangeCandidates($dbname, $segment, $field, $conditions);
            if($keys === null || !empty($keys)){
                foreach($this->records->eachSegmentBatch($dbname, $segment, $keys) as $batch){
                    $matches = [];
                    foreach($batch as $key => $record){
                        if($this->findFilter->matchesFilters($record, $filters)){
                            $matches[$key] = $record;
                        }
                    }
                    if(!empty($matches)){
                        $visit($matches);
                    }
                }
            }
        }
        return true;
    }
}
