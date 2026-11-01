<?php
// distinct(), sort(), count(), like()
namespace noneDB\Internal\Query;

use function array_column, array_key_exists, array_values, count, is_array, is_bool, is_scalar, strtolower,
    usort;

/**
 * @internal
 */
final class QueryMethods {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $checkpoints;
    private $findFilter;
    private $layout;
    private $locks;
    private $planner;
    private $segments;
    private $shardMeta;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->checkpoints, $this->findFilter, $this->layout, $this->locks, $this->planner, $this->segments, $this->shardMeta);
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
        $this->checkpoints = null;
        $this->findFilter = null;
        $this->layout = null;
        $this->locks = null;
        $this->planner = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // QUERY METHODS
    // ==========================================

    /**
     * Get unique values for a field
     * @param string $dbname
     * @param string $field
     * @return array|false
     */
    public function distinct($dbname, $field){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field){
            return $this->distinctUnlocked($dbname, $field);
        });
    }

    private function distinctUnlocked($dbname, $field){
        $seen = [];
        $values = [];
        $found = $this->planner->eachMatch($dbname, [], function(array $records) use ($field, &$seen, &$values){
            \noneDB\Internal\Query\Values::collectUnique($records, $field, $seen, $values);
        }, true);
        return $found ? $values : false;
    }

    /**
     * Sort array by field
     * @param array $array Result from find()
     * @param string $field Field to sort by
     * @param string $order 'asc' or 'desc'
     * @return array|false
     */
    public function sort($array, $field, $order = 'asc'){
        if(!is_array($array)) return false;
        if(count($array) === 0) return [];
        $desc = strtolower((string)$order) === 'desc';
        $items = [];
        foreach(array_values($array) as $i => $row){
            $items[] = [\noneDB\Internal\Query\Values::sortKey(is_array($row) && array_key_exists($field, $row) ? $row[$field] : null), $i, $row];
        }
        usort($items, function($a, $b) use ($desc){
            $result = $a[0] <=> $b[0];
            if($desc){
                $result = -$result;
            }
            return $result !== 0 ? $result : $a[1] <=> $b[1]; // stable
        });
        return array_column($items, 2);
    }

    /**
     * Count records matching filter
     * @param string $dbname
     * @param mixed $filter
     * @return int
     */
    public function count($dbname, $filter = 0){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $filter){
            return $this->countUnlocked($dbname, $filter);
        });
    }

    private function countUnlocked($dbname, $filter = 0){
        $filters = $this->findFilter->normalizeFilter($filter);
        if($filters === null){
            return 0; // a filter find() rejects matches nothing
        }
        // Fast-path: no filter = use index/meta counters
        if(empty($filters)){
            return $this->countFast($dbname);
        }
        $count = 0;
        $this->planner->eachMatch($dbname, $filters, function(array $records) use (&$count){ $count += count($records); }, true);
        return $count;
    }

    /**
     * Count without reading records: index entries (non-sharded) or meta totalRecords (sharded)
     * @param string $dbname Already normalized
     * @return int
     */
    public function countFast($dbname){
        if(!$this->segments->dbExists($dbname)){
            return 0;
        }
        if($this->segments->isSharded($dbname)){
            $meta = $this->shardMeta->getCachedMeta($dbname);
            return $meta !== null ? (int)($meta['totalRecords'] ?? 0) : 0;
        }
        $index = $this->checkpoints->readJsonlIndex($dbname, null);
        return $index !== null ? \noneDB\Internal\Storage\KeyIndex::count($index) : 0;
    }

    /**
     * Pattern matching search (LIKE)
     * @param string $dbname
     * @param string $field
     * @param string $pattern Use ^ for starts with, $ for ends with
     * @return array|false
     */
    public function like($dbname, $field, $pattern){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $pattern){
            return $this->likeUnlocked($dbname, $field, $pattern);
        });
    }

    private function likeUnlocked($dbname, $field, $pattern){
        if(!is_scalar($pattern)){
            return false; // an array is never a pattern subject
        }
        $result = [];
        $like = \noneDB\Internal\Query\Values::compileLike((string)$pattern);
        $found = $this->planner->eachMatch($dbname, [], function(array $records) use ($field, $like, &$result){
            foreach($records as $record){
                $value = $record[$field] ?? null;
                // Only strings and numbers (true used to match "1", like in the query builder it never does)
                if(is_scalar($value) && !is_bool($value) && \noneDB\Internal\Query\Values::likeMatch($like, (string)$value)){
                    $result[] = $record;
                }
            }
        }, true);
        return $found ? $result : false;
    }
}
