<?php
// sum(), avg(), min(), max()
namespace noneDB\Internal\Query;

/**
 * @internal
 */
final class Aggregation {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $layout;
    private $locks;
    private $planner;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->layout, $this->locks, $this->planner);
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
        $this->layout = null;
        $this->locks = null;
        $this->planner = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // AGGREGATION METHODS
    // ==========================================

    /**
     * Sum numeric field values
     * @param string $dbname
     * @param string $field
     * @param mixed $filter
     * @return float|int
     */
    public function sum($dbname, $field, $filter = 0){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $filter){
            return $this->sumUnlocked($dbname, $field, $filter);
        });
    }

    private function sumUnlocked($dbname, $field, $filter = 0){
        $sum = 0;
        $this->planner->eachMatch($dbname, $filter, function(array $records) use ($field, &$sum){
            foreach($records as $record){
                $number = \noneDB\Internal\Query\Values::numberOf($record[$field] ?? null);
                if($number !== null){
                    $sum += $number;
                }
            }
        }, true);
        return $sum;
    }

    /**
     * Average of numeric field values
     * @param string $dbname
     * @param string $field
     * @param mixed $filter
     * @return float|int
     */
    public function avg($dbname, $field, $filter = 0){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $filter){
            return $this->avgUnlocked($dbname, $field, $filter);
        });
    }

    private function avgUnlocked($dbname, $field, $filter = 0){
        $sum = 0;
        $count = 0;
        $this->planner->eachMatch($dbname, $filter, function(array $records) use ($field, &$sum, &$count){
            foreach($records as $record){
                $number = \noneDB\Internal\Query\Values::numberOf($record[$field] ?? null);
                if($number !== null){
                    $sum += $number;
                    $count++;
                }
            }
        }, true);
        return $count > 0 ? $sum / $count : 0;
    }

    /**
     * Get minimum value of a field
     * @param string $dbname
     * @param string $field
     * @param mixed $filter
     * @return mixed|null
     */
    public function min($dbname, $field, $filter = 0){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $filter){
            return $this->minUnlocked($dbname, $field, $filter);
        });
    }

    private function minUnlocked($dbname, $field, $filter = 0){
        // The order of sort() (and of the query builder's min()); loose < depended on the insertion order
        $min = null;
        $this->planner->eachMatch($dbname, $filter, function(array $records) use ($field, &$min){
            foreach($records as $record){
                if(isset($record[$field]) && ($min === null || \noneDB\Internal\Query\Values::compareSortValues($record[$field], $min) < 0)){
                    $min = $record[$field];
                }
            }
        }, true);
        return $min;
    }

    /**
     * Get maximum value of a field
     * @param string $dbname
     * @param string $field
     * @param mixed $filter
     * @return mixed|null
     */
    public function max($dbname, $field, $filter = 0){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $filter){
            return $this->maxUnlocked($dbname, $field, $filter);
        });
    }

    private function maxUnlocked($dbname, $field, $filter = 0){
        $max = null;
        $this->planner->eachMatch($dbname, $filter, function(array $records) use ($field, &$max){
            foreach($records as $record){
                if(isset($record[$field]) && ($max === null || \noneDB\Internal\Query\Values::compareSortValues($record[$field], $max) > 0)){
                    $max = $record[$field];
                }
            }
        }, true);
        return $max;
    }
}
