<?php
// One noneDB instance: its values and its services (the composition root)
namespace noneDB\Internal\Core;

use function array_fill_keys, get_object_vars, is_object;

use noneDB\Internal\Cache\Runtime;

/**
 * @internal
 */
final class Kernel {
    /** @var Runtime */
    public $rt;
    /** @var array<string, true> values linked to the process-wide caches (shared by every instance) */
    public $linked = [];
    /** @var \noneDB\Internal\Config\Configuration */
    public $config;
    /** @var \noneDB\Internal\Cache\CacheManager */
    public $cache;
    /** @var \noneDB\Internal\Storage\StoreRecord */
    public $store;
    /** @var \noneDB\Internal\Storage\Layout */
    public $layout;
    /** @var \noneDB\Internal\Storage\Segments */
    public $segments;
    /** @var \noneDB\Internal\Storage\Checkpoints */
    public $checkpoints;
    /** @var \noneDB\Internal\Storage\RecordIO */
    public $records;
    /** @var \noneDB\Internal\Storage\Writer */
    public $writer;
    /** @var \noneDB\Internal\Storage\Buffer */
    public $buffer;
    /** @var \noneDB\Internal\Concurrency\LockManager */
    public $locks;
    /** @var \noneDB\Internal\Concurrency\Snapshot */
    public $snapshot;
    /** @var \noneDB\Internal\Recovery\RollbackScope */
    public $scope;
    /** @var \noneDB\Internal\Recovery\RecoveryLog */
    public $recoveryLog;
    /** @var \noneDB\Internal\Recovery\Recovery */
    public $recovery;
    /** @var \noneDB\Internal\Durability\FileIO */
    public $io;
    /** @var \noneDB\Internal\Durability\Sync */
    public $sync;
    /** @var \noneDB\Internal\Sharding\ShardMeta */
    public $shardMeta;
    /** @var \noneDB\Internal\Sharding\ShardRouter */
    public $shardRouter;
    /** @var \noneDB\Internal\Sharding\LayoutSwitch */
    public $layoutSwitch;
    /** @var \noneDB\Internal\Sharding\ShardInfo */
    public $shardInfo;
    /** @var \noneDB\Internal\Index\SecondaryFiles */
    public $secondary;
    /** @var \noneDB\Internal\Index\FieldIndex */
    public $fieldIndex;
    /** @var \noneDB\Internal\Index\FieldIndexApi */
    public $fieldIndexApi;
    /** @var \noneDB\Internal\Index\Spatial\GeoJson */
    public $geoJson;
    /** @var \noneDB\Internal\Index\Spatial\Geometry */
    public $geometry;
    /** @var \noneDB\Internal\Index\Spatial\RTree */
    public $rtree;
    /** @var \noneDB\Internal\Index\Spatial\SpatialIndexApi */
    public $spatialIndexApi;
    /** @var \noneDB\Internal\Query\FindFilter */
    public $findFilter;
    /** @var \noneDB\Internal\Query\Values */
    public $values;
    /** @var \noneDB\Internal\Query\Planner */
    public $planner;
    /** @var \noneDB\Internal\Query\QueryMethods */
    public $queryMethods;
    /** @var \noneDB\Internal\Query\Aggregation */
    public $aggregation;
    /** @var \noneDB\Internal\Query\Utility */
    public $utility;
    /** @var \noneDB\Internal\Query\SpatialQueries */
    public $spatialQueries;
    /** @var \noneDB\Internal\Core\Crud */
    public $crud;
    /** @var \noneDB\Internal\Core\DatabaseOps */
    public $db;
    /** @var \noneDB\Internal\Diagnostics\Integrity */
    public $integrity;
    /** @var \noneDB\Internal\Diagnostics\Metrics */
    public $metrics;
    /** @var \noneDB\Internal\Maintenance\Maintenance */
    public $maintenance;
    /** @var \noneDB\Internal\Maintenance\Compaction */
    public $compaction;
    /** @var \noneDB\Internal\Maintenance\KeyMaintenance */
    public $keyMaintenance;
    /** @var \noneDB\Internal\Maintenance\BackupRestore */
    public $backupRestore;


    public $dbDir = null;      // Set via .nonedb config file or constructor
    public $autoCreateDB = true;
    public $devMode = false;

    // Sharding configuration
    public $shardingEnabled=true;   // A database switches to the sharded layout once it has assigned shardSize keys
    public $shardSize=10000;         // Records per shard of databases created from now on (each keeps the size in its FORMAT)

    // File locking configuration
    public $lockTimeout=5;           // Max seconds to wait for a database lock (0 = wait forever)
    public $lockRetryDelay=10000;    // Microseconds between lock attempts (10ms)

    // Write buffer configuration (off by default: direct writes are faster and always visible)
    public $bufferEnabled=false;             // Enable/disable write buffering
    public $bufferSizeLimit=1048576;         // 1MB buffer size limit per buffer
    public $bufferCountLimit=10000;          // Max records per buffer (safety limit)
    public $bufferFlushInterval=30;          // Seconds between auto-flush (0 = disabled)
    public $bufferAutoFlushOnShutdown=true;  // Register shutdown handler for flush

    // Buffer state tracking (runtime)
    public $bufferLastFlush=[];              // Track last flush time per DB/shard
    public $shutdownHandlerRegistered=false; // Track if shutdown handler is registered

    // Performance caches (runtime). All keys are full file paths (or key-fingerprint|dbname for hashes),
    // so instances with different dbDir/secretKey never see each other's entries.
    public $hashCache=[];                    // keyFingerprint|dbname -> locator
    public $metaCache=[];                    // meta path -> meta data

    // Index configuration
    public $indexCache=[];                   // checkpoint path -> key index of the segment (Checkpoints, KeyIndex)
    public $verifiedIndexes=[];              // checkpoint path -> true: the cached index covers the data file as it is
    public $shardedCache=[];                 // meta path -> bool

    // JSONL Storage Engine
    public $autoCompactThreshold=0.3;       // Trigger compaction when garbage > 30% of live records
    public $generations=[];                  // per-instance view of $staticGenerations

    // Durability mode (docs/DURABILITY.md): true = 'durable', every sync of §3 is done and required
    public $durable=false;

    // Permissions of what noneDB creates in dbDir, whatever the umask (docs/STORAGE_FORMAT.md §3)
    public $filePermissions = 0640;
    public $directoryPermissions = 0750;

    // Field indexing configuration
    public $fieldIndexEnabled = true;        // Enable field-based indexing
    public $fieldIndexCache = [];            // field index path -> index

    // Spatial indexing configuration
    public $spatialIndexEnabled = true;      // Enable spatial R-tree indexing
    public $spatialIndexCache = [];          // spatial index path -> index
    public $globalSpatialIndexCache = [];    // global spatial index path -> index
    public $rtreeNodeSize = 32;              // Max entries per R-tree node
    public $appendMismatch = false;          // appendToFile(): the file didn't have the expected size
    public $dataSizes = [];                  // data path -> size, until another process writes (global index skips)
    public $dataEpochs = [];                 // data path -> segment epoch read from its checkpoint (global index entries)
    public $shardFilesUpTo = [];             // database file prefix -> highest shard number with files (setOrphanShardsAside)
    public $globalSkip = [];                 // lock path -> global field index path -> shards a lookup checks (globalCheckShards)
    public $segWrites = [];                  // lock path -> ['seq' => n, 'paths' => [data path => seq]]: data files this process wrote
    public $writableIndexes = [];            // checkpoint path -> lock session id in which it was checked against its data file
    public $indexedCandidates = [];          // key => true: the last spatialCandidates() found it in an R-tree (valid geometry)

    public $keyFingerprint = null;           // HMAC of the secret, used to bind caches to the key

    public $cloneSourceId = null;
    // ==========================================
    // DATABASE DIRECTORY (docs/STORAGE_FORMAT.md §4, §5, §8)
    // ==========================================
    //
    // {dbDir}{locator}.ndb4/ holds FORMAT (identity), LOCK (lock + state record), CURRENT (active
    // generation) and generations/{N}/ (metadata, shards.json, indexes.json, segments/, indexes/,
    // spatial/, buffer/). The generation number of a database is read from CURRENT once per lock epoch.

    public $currentGenerations = [];         // lock path => active generation number
    public $formatRecords = [];              // lock path => FORMAT record of a present database

    public $dbHomes = [];                    // database name => its directory (dbDir and key are fixed per instance)
    public $lockPaths = [];                  // database name => its LOCK file
    public $genPaths = [];                   // database name => paths of its active generation (genDir())

    /**
     * Kernels of the live noneDB instances by the instance's id. The instance holds only that id: a dump of
     * it (var_export, json_encode) shows nothing internal, and no reference cycle is reachable from it.
     * @var array<int, Kernel>
     */
    public static $instances = [];

    /** The services: kernel property => class (created on first use) */
    public const SERVICES = [
        'config' => \noneDB\Internal\Config\Configuration::class,
        'cache' => \noneDB\Internal\Cache\CacheManager::class,
        'store' => \noneDB\Internal\Storage\StoreRecord::class,
        'layout' => \noneDB\Internal\Storage\Layout::class,
        'segments' => \noneDB\Internal\Storage\Segments::class,
        'checkpoints' => \noneDB\Internal\Storage\Checkpoints::class,
        'records' => \noneDB\Internal\Storage\RecordIO::class,
        'writer' => \noneDB\Internal\Storage\Writer::class,
        'buffer' => \noneDB\Internal\Storage\Buffer::class,
        'locks' => \noneDB\Internal\Concurrency\LockManager::class,
        'snapshot' => \noneDB\Internal\Concurrency\Snapshot::class,
        'scope' => \noneDB\Internal\Recovery\RollbackScope::class,
        'recoveryLog' => \noneDB\Internal\Recovery\RecoveryLog::class,
        'recovery' => \noneDB\Internal\Recovery\Recovery::class,
        'io' => \noneDB\Internal\Durability\FileIO::class,
        'sync' => \noneDB\Internal\Durability\Sync::class,
        'shardMeta' => \noneDB\Internal\Sharding\ShardMeta::class,
        'shardRouter' => \noneDB\Internal\Sharding\ShardRouter::class,
        'layoutSwitch' => \noneDB\Internal\Sharding\LayoutSwitch::class,
        'shardInfo' => \noneDB\Internal\Sharding\ShardInfo::class,
        'secondary' => \noneDB\Internal\Index\SecondaryFiles::class,
        'fieldIndex' => \noneDB\Internal\Index\FieldIndex::class,
        'fieldIndexApi' => \noneDB\Internal\Index\FieldIndexApi::class,
        'geoJson' => \noneDB\Internal\Index\Spatial\GeoJson::class,
        'geometry' => \noneDB\Internal\Index\Spatial\Geometry::class,
        'rtree' => \noneDB\Internal\Index\Spatial\RTree::class,
        'spatialIndexApi' => \noneDB\Internal\Index\Spatial\SpatialIndexApi::class,
        'findFilter' => \noneDB\Internal\Query\FindFilter::class,
        'values' => \noneDB\Internal\Query\Values::class,
        'planner' => \noneDB\Internal\Query\Planner::class,
        'queryMethods' => \noneDB\Internal\Query\QueryMethods::class,
        'aggregation' => \noneDB\Internal\Query\Aggregation::class,
        'utility' => \noneDB\Internal\Query\Utility::class,
        'spatialQueries' => \noneDB\Internal\Query\SpatialQueries::class,
        'crud' => \noneDB\Internal\Core\Crud::class,
        'db' => \noneDB\Internal\Core\DatabaseOps::class,
        'integrity' => \noneDB\Internal\Diagnostics\Integrity::class,
        'metrics' => \noneDB\Internal\Diagnostics\Metrics::class,
        'maintenance' => \noneDB\Internal\Maintenance\Maintenance::class,
        'compaction' => \noneDB\Internal\Maintenance\Compaction::class,
        'keyMaintenance' => \noneDB\Internal\Maintenance\KeyMaintenance::class,
        'backupRestore' => \noneDB\Internal\Maintenance\BackupRestore::class,
    ];

    public function __construct($config = null){
        $this->rt = Runtime::get();
        unset($this->config, $this->cache, $this->store, $this->layout, $this->segments, $this->checkpoints, $this->records, $this->writer, $this->buffer, $this->locks, $this->snapshot, $this->scope, $this->recoveryLog, $this->recovery, $this->io, $this->sync, $this->shardMeta, $this->shardRouter, $this->layoutSwitch, $this->shardInfo, $this->secondary, $this->fieldIndex, $this->fieldIndexApi, $this->geoJson, $this->geometry, $this->rtree, $this->spatialIndexApi, $this->findFilter, $this->values, $this->planner, $this->queryMethods, $this->aggregation, $this->utility, $this->spatialQueries, $this->crud, $this->db, $this->integrity, $this->metrics, $this->maintenance, $this->compaction, $this->keyMaintenance, $this->backupRestore);
        $this->linkCaches();
        $this->config->initialize($config);
    }

    /**
     * A service, created on first use: it binds the kernel's values by reference (after linkCaches(), so
     * its references follow the links)
     */
    public function __get(string $name) {
        $class = self::SERVICES[$name] ?? null;
        if($class === null){
            throw new \LogicException('noneDB: no service ' . $name);
        }
        $service = new $class($this);
        $this->$name = $service;
        $service->bind();
        return $service;
    }

    /**
     * Link the instance's caches to the process-wide ones (static cache sharing): before any service exists
     */
    private function linkCaches(): void {
        // Link instance caches to static caches for cross-instance sharing
        if($this->rt->staticCacheEnabled){
            $this->indexCache = &$this->rt->staticIndexCache;
            $this->verifiedIndexes = &$this->rt->staticVerifiedIndexes;
            $this->shardedCache = &$this->rt->staticShardedCache;
            $this->metaCache = &$this->rt->staticMetaCache;
            $this->hashCache = &$this->rt->staticHashCache;
            $this->fieldIndexCache = &$this->rt->staticFieldIndexCache;
            $this->spatialIndexCache = &$this->rt->staticSpatialIndexCache;
            $this->globalSpatialIndexCache = &$this->rt->staticGlobalSpatialIndexCache;
            $this->generations = &$this->rt->staticGenerations;
            $this->writableIndexes = &$this->rt->staticWritableIndexes;
            $this->dataSizes = &$this->rt->staticDataSizes;
            $this->globalSkip = &$this->rt->staticGlobalSkip;
            $this->segWrites = &$this->rt->staticSegWrites;
            $this->dataEpochs = &$this->rt->staticDataEpochs;
            $this->shardFilesUpTo = &$this->rt->staticShardFilesUpTo;
            $this->currentGenerations = &$this->rt->staticCurrentGenerations;
            $this->formatRecords = &$this->rt->staticFormatRecords;
            $this->linked = array_fill_keys(['indexCache', 'verifiedIndexes', 'shardedCache', 'metaCache', 'hashCache', 'fieldIndexCache', 'spatialIndexCache', 'globalSpatialIndexCache', 'generations', 'writableIndexes', 'dataSizes', 'globalSkip', 'segWrites', 'dataEpochs', 'shardFilesUpTo', 'currentGenerations', 'formatRecords'], true);
        }
    }

    private function unsetServices(): void {
        unset($this->config, $this->cache, $this->store, $this->layout, $this->segments, $this->checkpoints, $this->records, $this->writer, $this->buffer, $this->locks, $this->snapshot, $this->scope, $this->recoveryLog, $this->recovery, $this->io, $this->sync, $this->shardMeta, $this->shardRouter, $this->layoutSwitch, $this->shardInfo, $this->secondary, $this->fieldIndex, $this->fieldIndexApi, $this->geoJson, $this->geometry, $this->rtree, $this->spatialIndexApi, $this->findFilter, $this->values, $this->planner, $this->queryMethods, $this->aggregation, $this->utility, $this->spatialQueries, $this->crud, $this->db, $this->integrity, $this->metrics, $this->maintenance, $this->compaction, $this->keyMaintenance, $this->backupRestore);
    }

    /**
     * A copy for a cloned noneDB instance: its own values (settings, per-instance caches), the same links to
     * the process-wide caches, its own services
     */
    public function cloneKernel(): self {
        $copy = clone $this;
        // The services hold the kernel's values by reference, so a plain clone would share every value with
        // this kernel. Only the caches linked to the process-wide ones are shared; everything else is copied.
        foreach (get_object_vars($this) as $name => $value) {
            if ($name === 'rt' || is_object($value) || isset($this->linked[$name])) {
                continue;
            }
            unset($copy->$name);
            $copy->$name = $value;
        }
        $copy->unsetServices();
        $copy->config->cloned();
        return $copy;
    }

    /**
     * Drop the handles between the services and this kernel (the noneDB instance is gone): without the
     * cycles the memory is freed at once, not by the cycle collector later. Values bound by reference
     * (settings, caches, hooks) are not touched; only handles to engine objects are cleared.
     */
    public function release(): void {
        foreach (self::SERVICES as $name => $class) {
            if (isset($this->$name)) {
                $this->$name->unbind();
                $this->$name = null;
            }
        }
    }

    /**
     * The kernel of a noneDB instance
     */
    public static function of(\noneDB $db): self {
        static $id = null;
        $id = $id ?? \Closure::bind(function (\noneDB $db) { return $db->id; }, null, \noneDB::class);
        return self::$instances[$id($db)];
    }
}
