<?php
// noneDBQuery: state and constructor
// Built into noneDB.php by tools/build.php: edit here, never noneDB.php
trait noneDBQuery_Core {
    private noneDB $db;
    private string $dbname;
    /** @var array[] where() groups; all of them must match (AND) */
    private array $whereFilters = [];
    /** @var array[] orWhere() groups; a record matches if the where groups match OR any orWhere group does */
    private array $orWhereFilters = [];
    private array $whereInFilters = [];
    private array $whereNotInFilters = [];
    /** @var array[] whereNot() groups */
    private array $whereNotFilters = [];
    private array $likeFilters = [];
    private array $notLikeFilters = [];
    private array $betweenFilters = [];
    private array $notBetweenFilters = [];
    private array $selectFields = [];
    private array $exceptFields = [];
    private ?string $groupByField = null;
    private array $havingFilters = [];
    private array $searchFilters = [];
    private array $joinConfigs = [];
    /** @var array[] sort keys in priority order: [['field' => ..., 'order' => 'asc'|'desc'], ...] */
    private array $sortKeys = [];
    private ?int $limitCount = null;
    private int $offsetCount = 0;

    // Spatial query support
    private array $spatialFilters = [];
    private ?array $distanceConfig = null;
    /** @var array|null Statistics of the last scan (explain()) */
    private ?array $lastScan = null;


    private const OPERATORS = ['$gt', '$gte', '$lt', '$lte', '$ne', '$eq', '$in', '$nin', '$exists', '$like', '$regex', '$contains'];

    /**
     * @param noneDB $db
     * @param string $dbname
     */
    public function __construct(noneDB $db, string $dbname) {
        $this->db = $db;
        $this->dbname = $dbname;
    }

    /**
     * The engine of the database instance (looked up, not held: a dump of the query shows no internals)
     */
    private function kernel(): \noneDB\Internal\Core\Kernel {
        return \noneDB\Internal\Core\Kernel::of($this->db);
    }

    /**
     * Debug output without the parent instance's internals
     */
    public function __debugInfo() {
        return [
            'dbname' => $this->dbname,
            'where' => $this->whereFilters,
            'orWhere' => $this->orWhereFilters,
            'sort' => $this->sortKeys,
            'limit' => $this->limitCount,
            'offset' => $this->offsetCount,
        ];
    }

}
