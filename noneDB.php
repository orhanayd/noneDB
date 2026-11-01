<?php
/**
 * https://github.com/orhanayd/noneDB
 * 
 * first this project start date 15.11.2018
 * noneDB - 20.08.2019 -- Orhan AYDOĞDU
 * OPEN SOURCE <3
 * please read firstly noneDB documents if your data is important ::)))
 * but every data is important for us <3 
 * created with love <3  || ORHAN AYDOGDU || www.orhanaydogdu.com.tr || info@orhanaydogdu.com.tr
 * Respect for Labour - Emeğe Saygı
 */

namespace noneDB\Internal\Cache {

/**
 * @internal
 */
final class Runtime {
    private static $instance = null;

    public static function get(): self {
        return self::$instance ?? (self::$instance = new self());
    }


    // Configuration file name
    public $configFile = '.nonedb';
    public $configLoaded = false;
    public $configData = null;
    public $configFilePath = null;    // Path of the loaded config file (null = none / dev mode)
    public $configFileDir = null;     // Directory relative dbDir paths in the file resolve against
    public $scriptDirs = [];          // SCRIPT_FILENAME + cwd -> resolved entry script directory

    // Placeholder keys shipped in the docs/templates; never accepted as a real secret
    public $placeholderKeys = [
        'CHANGE_THIS_TO_A_SECURE_RANDOM_STRING',
        'YOUR_SECURE_RANDOM_STRING',
        'YOUR_SECURE_RANDOM_STRING_HERE',
        'GENERATE_UNIQUE_64_CHAR_STRING',
        'your_secure_key',
        'your_key',
        'nonedb_123', // a default key that was published: as public as the examples
    ];

    // secretKey values live outside the object so print_r/var_export/serialize can't leak them
    public $secretKeys = [];
    public $bufferCounts=[];          // bufferPath => [size, count] so limits don't need a re-read per insert
    public $bufferEnds=[];            // bufferPath => size the buffer had after this process's last append
    public $flushingNow=[];           // database base path => true while its buffer is being flushed

    // Static caches for cross-instance sharing
    public $staticIndexCache=[];
    public $staticVerifiedIndexes=[];
    public $pendingRecoveryEvents=[]; // lock path -> event id -> event found by a reader, logged by the next writer
    public $uncheckpointed=[];        // lock path -> segments loaded without a checkpoint, written by the next writer
    public $decodeDue=[];             // lock path -> key index paths decoded at the start of the database's next operation
    public $staticShardedCache=[];
    public $staticMetaCache=[];
    public $staticHashCache=[];
    public $staticFileExistsCache=[];
    public $staticSanitizeCache=[];
    public $staticFieldIndexCache=[];
    public $staticSpatialIndexCache=[];
    public $staticGlobalSpatialIndexCache=[];
    public $staticGenerations=[];     // lock path -> generation the caches were validated against
    public $staticCacheEnabled=true;  // Enable/disable static caching

    // Process-wide lock registry (shared by every instance so two instances never self-deadlock)
    public $lockRegistry=[];          // lock path -> ['fp', 'mode' => LOCK_SH|LOCK_EX, 'depth', 'dbname', 'gen', 'flags', 'bumped', 'pending', 'scope', 'rolledBack', 'sid'] while locked
    public $lockHandles=[];           // lock path -> ['fp' => resource, 'pid' => int]: unlocked handles kept open for the next operation
    public $sessionCounter=0;         // id of the latest lock session ('sid' in the registry)
    public $sessionLimit=null;        // the cache limit in that session, null until it is needed (segments read it, memory_limit once)
    public $sessionNear=null;         // where the process's peak is near memory_limit in that session, null until it is needed
    public $shutdownCleanupRegistered=false;
    public $syncStats=['file' => 0, 'dir' => 0, 'failed' => 0];
    // getMetrics() (docs/OPERATIONS.md §1): counters of the process, each counted where its work is done
    public $metrics=['hits' => 0, 'misses' => 0, 'evictions' => 0, 'pages' => 0, 'bytes' => 0, 'checkpoints' => 0,
        'checkpointSeconds' => 0.0, 'compactions' => 0, 'compactionSeconds' => 0.0, 'locks' => 0, 'lockWaits' => 0,
        'lockWaitSeconds' => 0.0, 'lockTimeouts' => 0, 'recoveryEvents' => 0, 'recoveryActions' => []];
    public $integrityChecks=[];       // databaseId -> summary of its last checkIntegrity()
    public $lastIntegrity=null;       // summary of the process's last checkIntegrity()
    public $lastSyncError=null;
    public $lastOperation=null;       // outcome of the last write operation of the process (getDurability())
    public $noWrites=0;               // > 0 while checkIntegrity() runs: no recovery, no repair, no file written
    public $multiLock=0;              // > 0 inside withSnapshot(): no exclusive lock may be waited for

    // Static caches are dropped while the process uses more memory than this: when the last lock is released
    // and between the segments of an operation (CacheManager::evictCaches())
    public $cacheMemoryLimit = null;  // bytes, 0 = no limit, null = automatic (cacheLimitBytes); the last instance's setting counts
    public $memoryLimitSetting = null; // the memory_limit value memoryLimitHalf was worked out from
    public $memoryLimitHalf = 0;      // half of it in bytes (the automatic cache limit), 0 = no limit
    public $cacheTouch = [];          // cache key -> tick of its last use
    public $cacheTick = 0;
    public $cacheGrew = false;        // something was loaded into a cache since evictCaches() last looked
    public $exactFloats = false;      // serialize_precision is -1 for the batch being encoded (withExactFloats)
    public $staticDataSizes = [];
    public $staticGlobalSkip = [];
    public $staticSegWrites = [];
    public $staticDataEpochs = [];
    public $staticShardFilesUpTo = [];
    public $secondaryChecked = [];    // data path -> "epoch:bytes:crc" a secondary index or global entry was checked against
    public $tailCache = [];           // data path -> [from, to, rows] of the last parsed data tail
    public $indexTokens = [];         // field -> its index file name part (indexToken)
    public $staticWritableIndexes = [];

    public $devModeNoticeShown = false;

    public $storeState = [];  // dbDir => keyCheck => true: the key is registered (store opened)
    public $storeLocks = [];  // dbDir => ['fp' => resource, 'mode' => LOCK_SH|LOCK_EX, 'depth' => int]
    public $maintenanceDepth = 0; // > 0 while this process runs maintenance (its own journal is open)
    public $staticCurrentGenerations = [];
    public $staticFormatRecords = [];

    public $parsedLockStates = [];   // lock path => [raw record, state, flags, damaged] of its last read

}
}
namespace noneDB\Internal\Core {

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
}
namespace noneDB\Internal\Cache {

use function array_keys, clearstatcache, fclose, file_exists, gc_mem_caches, hash_hmac, ini_get, intdiv, is_array,
    memory_get_peak_usage, memory_get_usage, min, strlen, strncmp, strtolower, substr, trim, usort;
use const PHP_INT_MAX;

/**
 * @internal
 */
final class CacheManager {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $dataEpochs;
    private $dataSizes;
    private $fieldIndexCache;
    private $globalSkip;
    private $globalSpatialIndexCache;
    private $indexCache;
    private $keyFingerprint;
    private $metaCache;
    private $segWrites;
    private $shardFilesUpTo;
    private $shardedCache;
    private $spatialIndexCache;
    private $verifiedIndexes;
    private $writableIndexes;
    private $bufferCounts;
    private $bufferEnds;
    private $cacheGrew;
    private $cacheTouch;
    private $sessionLimit;
    private $sessionNear;
    private $lockRegistry;
    private $metrics;
    private $secondaryChecked;
    private $staticCacheEnabled;
    private $staticFieldIndexCache;
    private $staticFileExistsCache;
    private $staticGlobalSpatialIndexCache;
    private $staticIndexCache;
    private $staticSpatialIndexCache;
    private $staticVerifiedIndexes;
    private $staticWritableIndexes;
    private $tailCache;
    private $config;
    private $layout;
    private $locks;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->config, $this->layout, $this->locks);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dataEpochs = &$this->k->dataEpochs;
        $this->dataSizes = &$this->k->dataSizes;
        $this->fieldIndexCache = &$this->k->fieldIndexCache;
        $this->globalSkip = &$this->k->globalSkip;
        $this->globalSpatialIndexCache = &$this->k->globalSpatialIndexCache;
        $this->indexCache = &$this->k->indexCache;
        $this->keyFingerprint = &$this->k->keyFingerprint;
        $this->metaCache = &$this->k->metaCache;
        $this->segWrites = &$this->k->segWrites;
        $this->shardFilesUpTo = &$this->k->shardFilesUpTo;
        $this->shardedCache = &$this->k->shardedCache;
        $this->spatialIndexCache = &$this->k->spatialIndexCache;
        $this->verifiedIndexes = &$this->k->verifiedIndexes;
        $this->writableIndexes = &$this->k->writableIndexes;
        $this->bufferCounts = &$this->rt->bufferCounts;
        $this->bufferEnds = &$this->rt->bufferEnds;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->cacheTouch = &$this->rt->cacheTouch;
        $this->sessionLimit = &$this->rt->sessionLimit;
        $this->sessionNear = &$this->rt->sessionNear;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->metrics = &$this->rt->metrics;
        $this->secondaryChecked = &$this->rt->secondaryChecked;
        $this->staticCacheEnabled = &$this->rt->staticCacheEnabled;
        $this->staticFieldIndexCache = &$this->rt->staticFieldIndexCache;
        $this->staticFileExistsCache = &$this->rt->staticFileExistsCache;
        $this->staticGlobalSpatialIndexCache = &$this->rt->staticGlobalSpatialIndexCache;
        $this->staticIndexCache = &$this->rt->staticIndexCache;
        $this->staticSpatialIndexCache = &$this->rt->staticSpatialIndexCache;
        $this->staticVerifiedIndexes = &$this->rt->staticVerifiedIndexes;
        $this->staticWritableIndexes = &$this->rt->staticWritableIndexes;
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
        $this->config = null;
        $this->layout = null;
        $this->locks = null;
        $this->k = null;
        $this->rt = null;
    }

    /**
     * HMAC fingerprint of the secret; binds caches to the key without revealing it
     */
    public function getKeyFingerprint(){
        if($this->keyFingerprint === null){
            $this->keyFingerprint = substr(hash_hmac('sha256', 'nonedb4/cache-key', (string)$this->config->getSecretKey()), 0, 16);
        }
        return $this->keyFingerprint;
    }

    /**
     * Clear all static caches (useful for testing or memory management)
     * @return void
     */
    public static function clearStaticCache(){
        \noneDB\Internal\Cache\Runtime::get()->staticIndexCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticVerifiedIndexes = [];
        \noneDB\Internal\Cache\Runtime::get()->uncheckpointed = [];
        \noneDB\Internal\Cache\Runtime::get()->decodeDue = [];
        \noneDB\Internal\Cache\Runtime::get()->staticShardedCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticMetaCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticHashCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticFileExistsCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticSanitizeCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticFieldIndexCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticSpatialIndexCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticGlobalSpatialIndexCache = [];
        \noneDB\Internal\Cache\Runtime::get()->staticGenerations = [];
        \noneDB\Internal\Cache\Runtime::get()->staticCurrentGenerations = [];
        \noneDB\Internal\Cache\Runtime::get()->staticFormatRecords = [];
        \noneDB\Internal\Cache\Runtime::get()->storeState = [];
        \noneDB\Internal\Cache\Runtime::get()->parsedLockStates = [];
        \noneDB\Internal\Cache\Runtime::get()->bufferCounts = [];
        \noneDB\Internal\Cache\Runtime::get()->bufferEnds = [];
        \noneDB\Internal\Cache\Runtime::get()->staticWritableIndexes = [];
        \noneDB\Internal\Cache\Runtime::get()->staticDataSizes = [];
        \noneDB\Internal\Cache\Runtime::get()->staticGlobalSkip = [];
        \noneDB\Internal\Cache\Runtime::get()->staticSegWrites = [];
        \noneDB\Internal\Cache\Runtime::get()->staticDataEpochs = [];
        \noneDB\Internal\Cache\Runtime::get()->staticShardFilesUpTo = [];
        \noneDB\Internal\Cache\Runtime::get()->secondaryChecked = [];
        \noneDB\Internal\Cache\Runtime::get()->cacheTouch = [];
        \noneDB\Internal\Cache\Runtime::get()->tailCache = [];
        foreach(\noneDB\Internal\Cache\Runtime::get()->lockHandles as $handle){
            @fclose($handle['fp']);
        }
        \noneDB\Internal\Cache\Runtime::get()->lockHandles = [];
    }

    /**
     * Disable static caching (each instance uses its own cache)
     * @return void
     */
    public static function disableStaticCache(){
        \noneDB\Internal\Cache\Runtime::get()->staticCacheEnabled = false;
    }

    /**
     * Enable static caching (default)
     * @return void
     */
    public static function enableStaticCache(){
        \noneDB\Internal\Cache\Runtime::get()->staticCacheEnabled = true;
    }

    /**
     * Cached file_exists check. Entries are dropped whenever another process writes the database
     * (see withLock), so the cache can't go stale across processes.
     *
     * @param string $path File path to check
     * @return bool True if file exists
     */
    public function cachedFileExists($path){
        if(!$this->staticCacheEnabled){
            clearstatcache(true, $path);
            return file_exists($path);
        }
        if(isset($this->staticFileExistsCache[$path])){
            return $this->staticFileExistsCache[$path];
        }
        clearstatcache(true, $path);
        $exists = file_exists($path);
        $this->staticFileExistsCache[$path] = $exists;
        return $exists;
    }

    /**
     * Mark file as existing in cache (call after creating file)
     * @param string $path File path
     */
    public function markFileExists($path){
        if($this->staticCacheEnabled){
            $this->staticFileExistsCache[$path] = true;
        }
    }

    /**
     * Mark file as not existing in cache (call after deleting file)
     * @param string $path File path
     */
    public function markFileNotExists($path){
        if($this->staticCacheEnabled){
            $this->staticFileExistsCache[$path] = false;
        }
    }

    /**
     * The cache limit in bytes, 0 = none: the cacheMemoryLimit setting, or by default half of PHP's
     * memory_limit (no limit without one): the cache doesn't make a process run out of
     * memory, and doesn't slow down one that may use all the memory it needs
     */
    public static function cacheLimitBytes(){
        $rt = \noneDB\Internal\Cache\Runtime::get();
        return $rt->cacheMemoryLimit ?? self::memoryLimitHalf();
    }

    /**
     * Half of PHP's memory_limit in bytes, 0 without one
     */
    private static function memoryLimitHalf(){
        $rt = \noneDB\Internal\Cache\Runtime::get();
        $setting = (string)ini_get('memory_limit');
        if($setting !== $rt->memoryLimitSetting){
            // Worked out once per value of memory_limit: this runs between the segments of every operation
            $ini = strtolower(trim($setting));
            $bytes = (int)$ini;
            $unit = substr($ini, -1);
            $bytes *= $unit === 'g' ? 1073741824 : ($unit === 'm' ? 1048576 : ($unit === 'k' ? 1024 : 1));
            $rt->memoryLimitSetting = $setting;
            $rt->memoryLimitHalf = $bytes <= 0 ? 0 : intdiv($bytes, 2);
        }
        return $rt->memoryLimitHalf;
    }

    /**
     * Keep the process under cacheMemoryLimit by dropping cached indexes: the process's memory
     * (memory_get_usage()) is what counts, the caches are what can be dropped. Runs when the last lock of the
     * process is released and between the segments of an operation (evictBetweenSegments()); while the process
     * uses less memory than the limit it does nothing. Least recently used entries go first, until the process
     * is under the limit (three quarters of it between segments) or nothing is left to drop. Every evicted
     * entry is loaded again when needed. A global index a write session changed stays: it is written when the
     * session ends (FieldIndex::deferGlobalWrite()).
     * @param bool $within Between the segments of an operation (evictBetweenSegments())
     */
    public function evictCaches($within = false){
        $limit = self::cacheLimitBytes();
        $target = $within ? intdiv(3 * $limit, 4) : $limit;
        if(memory_get_usage() <= $target){
            return;
        }
        $pending = [];
        foreach($this->lockRegistry as $entry){
            $pending += $entry['globals'];
        }
        $entries = [];
        foreach(['staticIndexCache', 'staticFieldIndexCache', 'staticSpatialIndexCache', 'staticGlobalSpatialIndexCache'] as $cache => $property){
            foreach(array_keys($this->$property) as $key){
                if(!isset($pending[$key])){
                    $entries[] = [$this->cacheTouch[$key] ?? 0, $cache, $key];
                }
            }
        }
        usort($entries, function($a, $b){ return $a[0] <=> $b[0]; });
        foreach($entries as [, $cache, $key]){
            if($cache === 0){
                unset($this->staticIndexCache[$key], $this->staticVerifiedIndexes[$key], $this->staticWritableIndexes[$key]);
            } elseif($cache === 1){
                unset($this->staticFieldIndexCache[$key]);
            } elseif($cache === 2){
                unset($this->staticSpatialIndexCache[$key]);
            } else {
                unset($this->staticGlobalSpatialIndexCache[$key]);
            }
            unset($this->cacheTouch[$key]);
            $this->metrics['evictions']++;
            if(memory_get_usage() <= $target){
                break;
            }
        }
    }

    /**
     * Bytes read from an index file, and the 4 KiB pages they are (getMetrics(), docs/OPERATIONS.md §1.1)
     */
    public function noteIndexRead($bytes, $pages = null){
        $this->metrics['bytes'] += $bytes;
        $this->metrics['pages'] += $pages ?? intdiv($bytes + 4095, 4096);
    }

    /**
     * True when an index may be decoded into a larger in-memory form of about $bytes: no limit, or the
     * process stays under half of cacheMemoryLimit with it (room left before eviction starts)
     */
    public static function decodeFits($bytes){
        $limit = self::cacheLimitBytes();
        return $limit <= 0 || memory_get_usage() + $bytes <= intdiv($limit, 2);
    }

    /** Memory of the process after the last eviction between segments */
    private $betweenFloor = 0;

    /** Memory PHP held for the process after it */
    private $heldFloor = 0;

    /**
     * Between the segments of an operation over many segments (a read, an aggregation, a write by filter, an
     * index build, compaction), once a segment is done: when the process uses more memory than cacheMemoryLimit,
     * least recently used entries go until the estimate is under three quarters of the limit; the next eviction waits
     * until the process has grown by another quarter (counted from where it is now if it has freed memory since). A
     * process whose peak came within an eighth of memory_limit (what PHP held for it, what memory_limit counts: a
     * segment's batches leave more of it behind than the process uses) measures that too, and gives back what it
     * freed. Not sooner: a warm query loses what it would load again. One operation over a large database holds the
     * cache and the segment it works on (the segments it finished go first), without a scan of the cache at every
     * segment. Under the exclusive lock too: what a write session changed is in its files, except the
     * global indexes it defers (they stay); a segment index it changed that goes is checkpointed by the
     * next reader that loads it (Checkpoints::checkpointTouchedSegments()).
     */
    public function evictBetweenSegments(){
        if(!$this->staticCacheEnabled){
            return;
        }
        $limit = $this->sessionLimit ?? ($this->sessionLimit = self::cacheLimitBytes());
        if($limit <= 0){
            return;
        }
        $used = memory_get_usage();
        $this->betweenFloor = min($this->betweenFloor, $used);
        $quarter = intdiv($limit, 4);
        $over = $used > $limit && $used > $this->betweenFloor + $quarter;
        $near = memory_get_peak_usage(true) > ($this->sessionNear ?? ($this->sessionNear = self::nearMemoryLimit()));
        if($near){
            $held = memory_get_usage(true);
            $this->heldFloor = min($this->heldFloor, $held);
            $over = $over || ($held > $limit && $held > $this->heldFloor + $quarter);
        }
        if(!$over){
            return;
        }
        $this->cacheGrew = false;
        $this->evictCaches(true);
        $this->betweenFloor = memory_get_usage();
        if($near){
            gc_mem_caches();
            $this->heldFloor = memory_get_usage(true);
        }
    }

    /**
     * Where a process's peak is near memory_limit: seven eighths of it. Never without one
     */
    private static function nearMemoryLimit(){
        $half = self::memoryLimitHalf();
        return $half > 0 ? intdiv(7 * $half, 4) : PHP_INT_MAX;
    }

    /**
     * Drop every cached entry that belongs to a database (index, meta, field/spatial indexes, file-exists)
     */
    public function invalidateDbCaches($dbname){
        $prefix = $this->layout->dbHome($dbname);
        $len = strlen($prefix);
        $caches = [
            &$this->indexCache, &$this->verifiedIndexes, &$this->shardedCache, &$this->metaCache,
            &$this->fieldIndexCache, &$this->spatialIndexCache,
            &$this->globalSpatialIndexCache, &$this->dataSizes, &$this->dataEpochs, &$this->shardFilesUpTo,
            &$this->secondaryChecked, &$this->tailCache,
        ];
        foreach($caches as &$cache){
            foreach(array_keys($cache) as $key){
                if(strncmp((string)$key, $prefix, $len) === 0){
                    unset($cache[$key]);
                }
            }
        }
        unset($cache);
        foreach(array_keys($this->staticFileExistsCache) as $key){
            if(strncmp((string)$key, $prefix, $len) === 0){
                unset($this->staticFileExistsCache[$key]);
            }
        }
        $lockPath = $this->locks->getLockPath($dbname);
        unset($this->globalSkip[$lockPath], $this->segWrites[$lockPath]);
        foreach(array_keys($this->bufferCounts) as $key){
            if(strncmp((string)$key, $prefix, $len) === 0){
                unset($this->bufferCounts[$key], $this->bufferEnds[$key]);
            }
        }
        foreach(array_keys($this->writableIndexes) as $key){
            if(strncmp((string)$key, $prefix, $len) === 0){
                unset($this->writableIndexes[$key]);
            }
        }
    }

    /**
     * Caches of a database after another process wrote it without replacing a data file (same epoch):
     * everything is dropped but the key indexes, which are checked again (the data only grew: the new lines
     * are replayed on the next read)
     */
    public function refreshDbCaches($dbname){
        $prefix = $this->layout->dbHome($dbname);
        $len = strlen($prefix);
        // Kept (they describe data bytes that didn't change, the new lines are replayed when used): key
        // indexes, field indexes and R-trees, what was checked against the data, parsed tails, epochs
        $keep = [];
        foreach(['indexCache' => &$this->indexCache, 'fieldIndexCache' => &$this->fieldIndexCache,
                 'spatialIndexCache' => &$this->spatialIndexCache, 'dataEpochs' => &$this->dataEpochs,
                 'secondaryChecked' => &$this->secondaryChecked, 'tailCache' => &$this->tailCache] as $name => &$cache){
            foreach($cache as $key => $value){
                if(strncmp((string)$key, $prefix, $len) === 0
                    && ($name !== 'fieldIndexCache' && $name !== 'spatialIndexCache' || (is_array($value) && isset($value['kind'])))){
                    $keep[$name][$key] = $value;
                }
            }
        }
        unset($cache);
        $this->invalidateDbCaches($dbname);
        foreach($keep['indexCache'] ?? [] as $key => $value){
            $this->indexCache[$key] = $value;
        }
        foreach($keep['fieldIndexCache'] ?? [] as $key => $value){
            $this->fieldIndexCache[$key] = $value;
        }
        foreach($keep['spatialIndexCache'] ?? [] as $key => $value){
            $this->spatialIndexCache[$key] = $value;
        }
        foreach($keep['dataEpochs'] ?? [] as $key => $value){
            $this->dataEpochs[$key] = $value;
        }
        foreach($keep['secondaryChecked'] ?? [] as $key => $value){
            $this->secondaryChecked[$key] = $value;
        }
        foreach($keep['tailCache'] ?? [] as $key => $value){
            $this->tailCache[$key] = $value;
        }
    }
}
}
namespace noneDB\Internal\Concurrency {

use function array_keys, array_shift, basename, bin2hex, clearstatcache, count, dirname, explode, fclose,
    fflush, flock, fopen, fread, fseek, fstat, fwrite, getmypid, implode, in_array, is_dir, is_file, is_link, key, max,
    microtime, mkdir, preg_match, random_bytes, reset, stat, str_pad, strlen, strncmp, strpos, substr, trim,
    usleep;
use const LOCK_EX, LOCK_NB, LOCK_SH, LOCK_UN;

/**
 * @internal
 */
final class LockManager {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $dataEpochs;
    private $dataSizes;
    private $dbDir;
    private $fieldIndexCache;
    private $formatRecords;
    private $durable;
    private $generations;
    private $globalSkip;
    private $globalSpatialIndexCache;
    private $lockPaths;
    private $lockRetryDelay;
    private $lockTimeout;
    private $segWrites;
    private $cacheGrew;
    private $decodeDue;
    private $lockHandles;
    private $lockRegistry;
    private $maintenanceDepth;
    private $metrics;
    private $multiLock;
    private $noWrites;
    private $parsedLockStates;
    private $secondaryChecked;
    private $sessionCounter;
    private $sessionLimit;
    private $sessionNear;
    private $staticCacheEnabled;
    private $storeLocks;
    private $storeState;
    private $tailCache;
    private $buffer;
    private $cache;
    private $checkpoints;
    private $fieldIndex;
    private $io;
    private $layout;
    private $recovery;
    private $rtree;
    private $scope;
    private $store;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->cache, $this->checkpoints, $this->fieldIndex, $this->io, $this->layout, $this->recovery, $this->rtree, $this->scope, $this->store, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dataEpochs = &$this->k->dataEpochs;
        $this->dataSizes = &$this->k->dataSizes;
        $this->dbDir = &$this->k->dbDir;
        $this->fieldIndexCache = &$this->k->fieldIndexCache;
        $this->formatRecords = &$this->k->formatRecords;
        $this->durable = &$this->k->durable;
        $this->generations = &$this->k->generations;
        $this->globalSkip = &$this->k->globalSkip;
        $this->globalSpatialIndexCache = &$this->k->globalSpatialIndexCache;
        $this->lockPaths = &$this->k->lockPaths;
        $this->lockRetryDelay = &$this->k->lockRetryDelay;
        $this->lockTimeout = &$this->k->lockTimeout;
        $this->segWrites = &$this->k->segWrites;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->decodeDue = &$this->rt->decodeDue;
        $this->lockHandles = &$this->rt->lockHandles;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->maintenanceDepth = &$this->rt->maintenanceDepth;
        $this->metrics = &$this->rt->metrics;
        $this->multiLock = &$this->rt->multiLock;
        $this->noWrites = &$this->rt->noWrites;
        $this->parsedLockStates = &$this->rt->parsedLockStates;
        $this->secondaryChecked = &$this->rt->secondaryChecked;
        $this->sessionCounter = &$this->rt->sessionCounter;
        $this->sessionLimit = &$this->rt->sessionLimit;
        $this->sessionNear = &$this->rt->sessionNear;
        $this->staticCacheEnabled = &$this->rt->staticCacheEnabled;
        $this->storeLocks = &$this->rt->storeLocks;
        $this->storeState = &$this->rt->storeState;
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
        $this->buffer = null;
        $this->cache = null;
        $this->checkpoints = null;
        $this->fieldIndex = null;
        $this->io = null;
        $this->layout = null;
        $this->recovery = null;
        $this->rtree = null;
        $this->scope = null;
        $this->store = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }


    // Rollback scope. The outermost exclusive withLock() call of a database opens one scope; nested
    // exclusive calls on the same database join it. There are no nested scopes: cutting a file back to an
    // earlier size can't drop an earlier outer append and keep a later inner one. Every append to a data or
    // buffer file records the size the file had before the scope's first append to it (a "mark"). When an
    // exception leaves the outermost call, every marked file is cut back to its mark.
    // Work that must not depend on the caller's success (buffer flush, the switch to shards) runs in place only
    // while the scope has no appends yet, committing its own appends step by step (runIndependent);
    // requested later, it is deferred until the scope committed, like compaction, which replaces whole files.
    // A deferred job that fails doesn't fail the operation that committed before it: it is logged and runs
    // again on a later operation.

    public function getLockPath($dbname){
        if(!isset($this->lockPaths[$dbname])){
            $this->layout->dbHome($dbname);
        }
        return $this->lockPaths[$dbname];
    }

    /**
     * Run $fn while holding the database lock: shared for reads, exclusive for writes.
     *
     * The lock is process-wide and re-entrant. A nested exclusive request upgrades a shared lock.
     *
     * @param string $dbname Database name (already normalized)
     * @param bool $exclusive
     * @param callable $fn
     * @param string $op The operation an exclusive call is (getDurability()['lastOperation'])
     * @return mixed Whatever $fn returns
     */
    public function withLock($dbname, $exclusive, callable $fn, $op = 'write'){
        $path = $this->getLockPath($dbname);
        if($exclusive && $this->multiLock > 0){
            // A snapshot holds shared locks of several databases: waiting for an exclusive one here could
            // deadlock with another snapshot. withSnapshot() does that work alone first and tries again.
            throw new \RuntimeException("noneDB: database '{$dbname}' needs maintenance before the snapshot", self::SNAPSHOT_RETRY);
        }

        if(isset($this->lockRegistry[$path])){
            if(!empty($this->lockRegistry[$path]['lost'])){
                throw new \RuntimeException("noneDB: the lock on database '{$dbname}' was lost (timed out while upgrading)", \noneDB::LOCK_FAILED);
            }
            if($exclusive && $this->lockRegistry[$path]['mode'] !== LOCK_EX){
                // flock can't upgrade atomically. On BSD/macOS a failed non-blocking upgrade keeps the shared
                // lock, so two processes upgrading at once would wait for each other until lockTimeout: release
                // first, then wait for the exclusive lock. Another writer may run in between, so the state is
                // read again and whatever it left half-done is recovered.
                $this->flushPendingGlobals($dbname, $path); // while what they describe still holds
                flock($this->lockRegistry[$path]['fp'], LOCK_UN);
                try {
                    $this->acquireFlock($this->lockRegistry[$path]['fp'], LOCK_EX, $dbname);
                } catch(\Throwable $e){
                    $this->lockRegistry[$path]['lost'] = true; // never continue reading without a lock
                    throw $e;
                }
                $this->lockRegistry[$path]['mode'] = LOCK_EX;
                $this->loadLockState($dbname, $path);
                $this->prepareDb($dbname, $path);
            }
            $this->lockRegistry[$path]['depth']++;
            try {
                if($exclusive && $this->lockRegistry[$path]['scope'] === null){
                    return $this->scope->runWriteScope($dbname, $path, $fn, $op);
                }
                return $fn();
            } finally {
                $this->lockRegistry[$path]['depth']--;
            }
        }

        // Outermost call. A database this process doesn't know to be present takes the missing-database
        // path (STORAGE_FORMAT.md §3.3): store re-validated, dbDir listed for foreign entries, STORE.LOCK
        // held shared, so no maintenance can run while it is created or answered as missing.
        if(!isset($this->formatRecords[$path]) && !isset($this->storeLocks[$this->dbDir])){
            $this->store->openStore(false);
            clearstatcache(true, dirname($path) . '/FORMAT');
            if(!is_file(dirname($path) . '/FORMAT')){
                return $this->withMissingDatabase($dbname, $exclusive, $fn, $op);
            }
        }
        try {
            return $this->lockSession($dbname, $exclusive, $fn, !isset($this->storeLocks[$this->dbDir]), $op);
        } catch(\RuntimeException $e){
            if($e->getCode() !== self::DB_GONE && $e->getCode() !== self::DB_ABSENT){
                throw $e;
            }
            return $this->withMissingDatabase($dbname, $exclusive, $fn, $op);
        }
    }

    /**
     * The missing-database path: under STORE.LOCK (shared) after the store was re-read and dbDir checked
     * for foreign entries. A read of a database without a directory runs $fn without any lock (nothing is
     * created); a write creates the directory and locks it.
     */
    private function withMissingDatabase($dbname, $exclusive, callable $fn, $op){
        $path = $this->getLockPath($dbname);
        return $this->store->withStoreRevalidated($exclusive, function() use ($dbname, $exclusive, $fn, $path, $op){
            clearstatcache(true, dirname($path));
            if(!is_dir(dirname($path))){
                if(!$exclusive || !$this->store->storeExists()){
                    return $fn(); // absent: reads create nothing
                }
                if(!@mkdir(dirname($path), 0750) && !is_dir(dirname($path))){
                    throw new \RuntimeException("noneDB: cannot create the directory of database '{$dbname}'");
                }
                $this->io->setPermissions(dirname($path), true);
            }
            return $this->lockSession($dbname, $exclusive, $fn, false, $op);
        });
    }

    /**
     * One lock session of a database (outermost withLock call). $recheck: the session didn't come through
     * the missing-database path, so finding the database absent means going there (DB_ABSENT).
     */
    private function lockSession($dbname, $exclusive, callable $fn, $recheck, $op){
        $path = $this->getLockPath($dbname);
        $mode = $exclusive ? LOCK_EX : LOCK_SH;
        $fp = $this->lockFile($path, $mode, $dbname);
        $this->sessionLimit = null;
        $this->sessionNear = null;
        $this->lockRegistry[$path] = [
            'fp' => $fp, 'mode' => $mode, 'depth' => 1, 'dbname' => $dbname,
            'gen' => 0, 'epoch' => null, 'flags' => [], 'bumped' => false, 'pending' => [],
            'scope' => null, 'rolledBack' => false, 'sid' => ++$this->sessionCounter, 'globals' => [], 'wrote' => false,
        ];

        $ok = false;
        try {
            $this->loadLockState($dbname, $path);
            try {
                $this->prepareDb($dbname, $path);
            } catch(\RuntimeException $e){
                // restore() is the way out of a damaged database (STORAGE_FORMAT.md §10.3 step 2): what recovery
                // can't resolve is handed to it instead of failing
                if($op !== 'restore' || $e->getCode() !== \noneDB::FORMAT_DAMAGED){
                    throw $e;
                }
            }
            if(isset($this->decodeDue[$path]) && $op !== 'rebuildIndex'){
                // §6.4: loaded by an earlier operation, decoded now (not by one that makes them again)
                $this->checkpoints->decodeDueIndexes($path);
            }
            if($recheck && !isset($this->formatRecords[$path]) && !$this->layout->dbPresent($dbname)){ // a cached FORMAT: present
                throw new \RuntimeException("noneDB: database '{$dbname}' is absent", self::DB_ABSENT);
            }
            $result = $exclusive ? $this->scope->runWriteScope($dbname, $path, $fn, $op) : $fn();
            $ok = true;
            return $result;
        } finally {
            try {
                if($this->lockRegistry[$path]['mode'] === LOCK_EX){
                    $this->finishWriteSession($dbname, $path, $ok);
                }
                $this->flushPendingGlobals($dbname, $path, $ok);
            } finally {
                $fp = $this->lockRegistry[$path]['fp'];
                unset($this->lockRegistry[$path]);
                flock($fp, LOCK_UN);
                $this->keepLockHandle($path, $fp);
                // The cache size is looked at only after something new was cached
                if($this->cacheGrew && empty($this->lockRegistry)){
                    $this->cacheGrew = false;
                    if($this->staticCacheEnabled && \noneDB\Internal\Cache\CacheManager::cacheLimitBytes() > 0){
                        $this->cache->evictCaches();
                    }
                }
            }
        }
    }

    /**
     * End of an exclusive session: write pending spatial indexes, close the journal entries of this
     * session, and make sure the generation moved so other processes drop their caches
     */
    private function finishWriteSession($dbname, $path, $ok){
        if($ok){
            if(empty($this->lockRegistry[$path]['rolledBack'])){
                $this->checkpoints->checkpointTouchedSegments($dbname, $path);
            }
            // After a rollback (of a deferred job) the journal stays: the next lock holder rebuilds
            if(!empty($this->lockRegistry[$path]['flags']['j']) && empty($this->lockRegistry[$path]['rolledBack'])){
                // Data, indexes and meta of every journaled segment are written
                unset($this->lockRegistry[$path]['flags']['j']);
                $this->saveLockState($path);
            }
        } else {
            // Partial failure: forget in-memory state, the files are the source of truth.
            // A journal entry stays, so the next lock holder rebuilds what may be half-written.
            $this->cache->invalidateDbCaches($dbname);
        }
    }

    /**
     * Open (or reuse) a lock file and lock it.
     * A kept handle is only used in the process that opened it (a forked child would share the parent's
     * locks), and any handle is only trusted while the path still names the same file: if the file was
     * deleted or replaced meanwhile, the file the path names now is locked instead.
     * @return resource
     */
    private function lockFile($path, $mode, $dbname){
        $pid = getmypid();
        for($attempt = 0; ; $attempt++){
            $kept = $this->lockHandles[$path] ?? null;
            unset($this->lockHandles[$path]);
            if($kept !== null && $kept['pid'] === $pid){
                $fp = $kept['fp'];
            } else {
                if($kept !== null){
                    @fclose($kept['fp']);
                }
                // A database directory that is a link is no database: no link is followed (§3.4)
                if(is_link(dirname($path))){
                    throw new \RuntimeException("noneDB: the directory of database '{$dbname}' is a symbolic link, not a database (docs/STORAGE_FORMAT.md §3.4)", \noneDB::UNSUPPORTED_FORMAT);
                }
                // "e": close-on-exec. A kept handle must not leak into programs this process starts:
                // an inherited copy would keep a lock alive after this process died holding it.
                $created = !is_file($path);
                $fp = is_dir(dirname($path)) ? @fopen($path, 'c+be') : false;
                if($fp !== false && $created){
                    $this->io->setPermissions($path);
                }
                if($fp === false){
                    clearstatcache(true, dirname($path));
                    if(!is_dir(dirname($path))){
                        // The database directory is gone (dropped store, rotated key): the caller takes
                        // the missing-database path, which re-validates the store
                        throw new \RuntimeException("noneDB: database '{$dbname}' has no directory", self::DB_GONE);
                    }
                    throw new \RuntimeException("noneDB: cannot open lock file for database '{$dbname}'", \noneDB::LOCK_FAILED);
                }
            }
            try {
                $this->acquireFlock($fp, $mode, $dbname);
            } catch(\Throwable $e){
                fclose($fp);
                throw $e;
            }
            $own = fstat($fp);
            clearstatcache(true, $path);
            $named = @stat($path);
            if($named !== false && ($own === false || !$own['ino'] || !$named['ino']
                || ($own['ino'] === $named['ino'] && $own['dev'] === $named['dev']))){
                return $fp;
            }
            flock($fp, LOCK_UN);
            fclose($fp);
            if($attempt >= 10){
                throw new \RuntimeException("noneDB: the lock file of database '{$dbname}' keeps being replaced", \noneDB::LOCK_FAILED);
            }
        }
    }

    /**
     * Keep an unlocked lock file handle open for the next operation (the oldest is closed past the limit)
     */
    private function keepLockHandle($path, $fp){
        $this->lockHandles[$path] = ['fp' => $fp, 'pid' => getmypid()];
        if(count($this->lockHandles) > self::LOCK_HANDLE_LIMIT){
            reset($this->lockHandles);
            $oldest = key($this->lockHandles);
            @fclose($this->lockHandles[$oldest]['fp']);
            unset($this->lockHandles[$oldest]);
        }
    }

    /**
     * Parse a state record by the grammar of STORAGE_FORMAT.md §4.2: [state, flags, damaged]. An empty
     * record is a new one (state ''); anything that doesn't match the grammar is damaged and read as J.
     */
    private static function parseLockState($raw){
        $tokens = explode(' ', trim($raw, " \0"));
        $state = (string)array_shift($tokens); // "{generation}:{epoch}"
        $flags = [];
        $damaged = false;
        if($state === ''){
            $tokens = []; // a new record: written with an epoch by the next exclusive session
        } elseif(preg_match('/^[0-9]{1,18}:[0-9a-f]{8}$/', $state) !== 1){
            $damaged = true;
        }
        foreach($tokens as $token){
            if($token === ''){
                continue;
            }
            if($token === 'b' || $token === 'c' || $token === 'd' || $token === 'm'){
                $flags[$token] = true;
            } elseif($token === 'J'){
                $flags['j']['*'] = true;
            } elseif(strncmp($token, 'j=', 2) === 0 && preg_match('/^j=(main|s[0-9]{1,9})(,(main|s[0-9]{1,9}))*$/', $token) === 1){
                foreach(explode(',', substr($token, 2)) as $segment){
                    $flags['j'][$segment] = true;
                }
            } elseif(preg_match('/^g=([0-9]{1,9})>([0-9]{1,9})$/', $token, $m) === 1){
                $flags['g'] = [(int)$m[1], (int)$m[2]];
            } else {
                $damaged = true;
            }
        }
        if($damaged){
            // Not a record of this format: everything it could have said is recovered from the data (J);
            // a generation that isn't CURRENT is never removed on this path (STORAGE_FORMAT.md §4.2)
            $state = 'damaged';
            $flags = ['j' => ['*' => true], 'damaged' => true];
        }
        return [$state, $flags, $damaged];
    }

    /**
     * Read the state record (caller holds the lock). If another process wrote the database since this
     * process last looked, all cached state of the database is dropped.
     */
    private function loadLockState($dbname, $path){
        $fp = $this->lockRegistry[$path]['fp'];
        fseek($fp, 0);
        $raw = (string)fread($fp, self::LOCK_STATE_SIZE);
        // The last record parsed per lock file: an unchanged record (the usual case) is not parsed again
        $parsed = $this->parsedLockStates[$path] ?? null;
        if($parsed !== null && $parsed[0] === $raw){
            [, $state, $flags, $damaged] = $parsed;
        } else {
            [$state, $flags, $damaged] = self::parseLockState($raw);
            if($parsed === null && count($this->parsedLockStates) >= 4096){
                $this->parsedLockStates = [];
            }
            $this->parsedLockStates[$path] = [$raw, $state, $flags, $damaged];
        }
        // The caches were checked against this exact state: nothing to do (the usual case, one comparison;
        // the epoch is parsed when needed: false = the valid one of the state)
        $seen = $this->generations[$path] ?? null;
        $epoch = false;
        if($seen !== $state){
            $colon = strpos($state, ':');
            $epoch = $colon === false ? null : substr($state, $colon + 1);
            $seenEpoch = $seen === null || strpos($seen, ':') === false ? null : substr($seen, strpos($seen, ':') + 1);
            if($seen === null || $epoch === null || $seenEpoch !== $epoch){
                $this->cache->invalidateDbCaches($dbname);
                $this->layout->forgetDatabaseLayout($dbname);
                // Another process replaced files or ran maintenance: the store is read again (§3.3)
                unset($this->storeState[$this->dbDir]);
                if($seen !== null && $this->maintenanceDepth === 0){
                    $this->checkStoreAfterEpochChange();
                }
            } else {
                $this->cache->refreshDbCaches($dbname); // same epoch, another generation: the files only grew
            }
            $this->generations[$path] = $epoch === null ? null : $state;
        }
        $this->lockRegistry[$path]['gen'] = $damaged ? 0 : (int)$state;
        $this->lockRegistry[$path]['state'] = $state;
        $this->lockRegistry[$path]['epoch'] = $epoch;
        $this->lockRegistry[$path]['flags'] = $flags;
        if($this->durable){
            // Durable mode: flags read from the record are synced again with this session's first record
            // (another process may have written them unsynced); the epoch protects nothing of this session
            $current = $this->registryEpoch($path);
            $this->lockRegistry[$path]['synced'] = $current === null ? [] : ['e' . $current => true];
        }
    }

    /**
     * A database's epoch changed under its lock: re-read the store (read only; no repair, which would
     * take STORE.LOCK after a database lock) and re-check the key and the maintenance journal
     */
    private function checkStoreAfterEpochChange(){
        $this->store->checkMaintenanceJournal();
        [, $copy1] = $this->store->readStoreCopy('STORE');
        [, $copy2] = $this->store->readStoreCopy('STORE.2');
        $record = $copy1 ?? $copy2;
        if($record === null || ($copy1 !== null && $copy2 !== null && $copy1 !== $copy2)){
            throw new \RuntimeException("noneDB: the store record in {$this->dbDir} is damaged", \noneDB::FORMAT_DAMAGED);
        }
        if(!in_array($this->store->keyCheck(), $record['keys'], true)){
            throw new \RuntimeException("noneDB: the configured secretKey is not registered for the store in {$this->dbDir}", \noneDB::KEY_NOT_REGISTERED);
        }
        $this->storeState[$this->dbDir][$this->store->keyCheck()] = true;
    }

    /**
     * Write the state record of a database held exclusively. $bump: a file is about to be written, so the
     * generation moves (once per session; noteWrite()): even a writer that dies half-way makes other
     * processes check their caches. A record without epoch gets one.
     * Durable mode (DURABILITY.md §3): a flag or an epoch that protects the change that follows is synced
     * before the record returns (clearing one needs no sync); $sync forces a sync.
     * @throws \RuntimeException DURABILITY_FAILED when that sync fails
     */
    public function saveLockState($path, $bump = false, $sync = false){
        if($bump && !$this->lockRegistry[$path]['bumped']){
            $this->lockRegistry[$path]['gen']++;
            $this->lockRegistry[$path]['bumped'] = true;
        }
        $this->lockRegistry[$path]['wrote'] = true;
        $epoch = $this->registryEpoch($path);
        if($epoch === null){
            $epoch = $this->lockRegistry[$path]['epoch'] = bin2hex(random_bytes(4));
        }
        $gen = max(1, (int)$this->lockRegistry[$path]['gen']);
        $this->lockRegistry[$path]['gen'] = $gen;
        $state = $gen . ':' . $epoch;
        $this->generations[$path] = $state;
        $this->lockRegistry[$path]['state'] = $state;
        $flags = $this->lockRegistry[$path]['flags'];
        unset($this->lockRegistry[$path]['flags']['damaged']);
        foreach(['b', 'c', 'd', 'm'] as $flag){
            if(!empty($flags[$flag])){
                $state .= ' ' . $flag;
            }
        }
        if(!empty($flags['g'])){
            $state .= ' g=' . $flags['g'][0] . '>' . $flags['g'][1];
        }
        if(!empty($flags['j'])){
            $segments = ' j=' . implode(',', array_keys($flags['j']));
            $state .= (isset($flags['j']['*']) || strlen($state . $segments) > self::LOCK_STATE_SIZE) ? ' J' : $segments;
        }
        $fp = $this->lockRegistry[$path]['fp'];
        $raw = str_pad($state, self::LOCK_STATE_SIZE);
        fseek($fp, 0);
        if(fwrite($fp, $raw) !== self::LOCK_STATE_SIZE || !fflush($fp)){
            unset($this->parsedLockStates[$path]);
            throw new \RuntimeException("noneDB: cannot write the lock file of database '" . $this->lockRegistry[$path]['dbname'] . "'");
        }
        // The next session of this process reads this record: parsed already (what parseLockState() would
        // return; "J" alone stands for all segments, so such a record is parsed again)
        if(strpos($state, ' J') === false){
            unset($flags['damaged']);
            $this->parsedLockStates[$path] = [$raw, $gen . ':' . $epoch, $flags, false];
        } else {
            unset($this->parsedLockStates[$path]);
        }
        if($this->durable){
            // What this record protects: its epoch and flags; what is durable already needs no new sync
            $tokens = ['e' . $epoch => true];
            foreach(explode(' ', $state) as $i => $token){
                if($i > 0){
                    $tokens[$token] = true;
                }
            }
            $synced = $this->lockRegistry[$path]['synced'] ?? [];
            if(!$sync){
                foreach($tokens as $token => $unused){
                    if(!isset($synced[$token]) && !($token[0] === 'j' && isset($synced['J']))){
                        $sync = true;
                        break;
                    }
                }
            }
            if($sync){
                if(!$this->sync->trySync($fp, $path)){
                    throw $this->sync->failure('file', $path, false);
                }
                $this->lockRegistry[$path]['synced'] = $tokens;
            }
        }
    }

    /**
     * Change the state flag 'b' (write buffer may hold records); caller holds the exclusive lock
     */
    public function setBufferFlag($dbname, $on){
        $path = $this->getLockPath($dbname);
        if(!isset($this->lockRegistry[$path]) || !empty($this->lockRegistry[$path]['flags']['b']) === $on){
            return;
        }
        if($on){
            $this->lockRegistry[$path]['flags']['b'] = true;
        } else {
            unset($this->lockRegistry[$path]['flags']['b']);
        }
        $this->saveLockState($path);
    }

    /**
     * After taking the lock: maintenance, an interrupted generation switch, creation or drop, a damaged
     * directory and a crashed writer's journal are handled before any data is returned (STORAGE_FORMAT.md
     * §4.2, §5.2, §8.3). The usual case (a present database, no flag but b) costs one isset.
     */
    private function prepareDb($dbname, $path){
        $flags = $this->lockRegistry[$path]['flags'];
        if(!empty($flags['m']) && $this->maintenanceDepth === 0){
            throw new \RuntimeException("noneDB: database '{$dbname}' is under maintenance", \noneDB::MAINTENANCE_IN_PROGRESS);
        }
        $quiet = empty($flags['g']) && empty($flags['c']) && empty($flags['d']) && empty($flags['j'])
            && $this->lockRegistry[$path]['epoch'] !== null;
        if($quiet && isset($this->formatRecords[$path])){
            return;
        }
        if($this->noWrites > 0){
            // checkIntegrity(), explain(): nothing is recovered or written; a state that can't be read is reported
            if(!empty($flags['g'])){
                throw new \RuntimeException("noneDB: database '{$dbname}' has an interrupted generation switch (recovered by the next write)", \noneDB::FORMAT_DAMAGED);
            }
            $this->layout->resolveDatabaseState($dbname, $path);
            return;
        }
        if($quiet){
            $this->layout->resolveDatabaseState($dbname, $path);
            return;
        }
        $this->withLock($dbname, true, function() use ($dbname, $path){
            // Re-read under the exclusive lock: another process may have done it meanwhile
            if(!empty($this->lockRegistry[$path]['flags']['g'])){
                $this->layout->recoverGenerationSwitch($dbname, $path);
            }
            $this->layout->resolveDatabaseState($dbname, $path);
            if($this->lockRegistry[$path]['epoch'] === null){
                // A new lock record: whatever the lost one said is recovered from the data
                if($this->layout->dbPresent($dbname)){
                    $this->lockRegistry[$path]['flags']['j']['*'] = true;
                }
                $this->saveLockState($path, false, true);
                // 1:{new epoch} (§4.2): no cache can match a new epoch, so this session's writes need no
                // further generation
                $this->lockRegistry[$path]['bumped'] = true;
            }
            if(!empty($this->lockRegistry[$path]['flags']['j']) && $this->layout->dbPresent($dbname)){
                $this->recovery->recoverPendingIndexes($dbname, $path);
            } elseif(!empty($this->lockRegistry[$path]['flags']['j'])){
                unset($this->lockRegistry[$path]['flags']['j']);
                $this->saveLockState($path);
            }
            if(!empty($this->lockRegistry[$path]['flags']['damaged'])){
                $this->saveLockState($path, false, true);
            }
        }, 'recovery');
    }

    /**
     * Called before a segment's data changes. The journal is needed only for shard meta counters (sharded)
     * and switches to shards ($always): field and spatial indexes follow the data by themselves.
     */
    public function markIndexesPending($dbname, $shardId, $always = false){
        $path = $this->getLockPath($dbname);
        if(!isset($this->lockRegistry[$path])){
            return;
        }
        if(!$always && $shardId === null){
            return;
        }
        $segment = \noneDB\Internal\Storage\Layout::segmentName($shardId);
        if(isset($this->lockRegistry[$path]['pending'][$segment])){
            return;
        }
        $this->lockRegistry[$path]['pending'][$segment] = true;
        $this->lockRegistry[$path]['flags']['j'][$segment] = true;
        $this->saveLockState($path, true); // the data write follows
    }

    /**
     * flock with timeout (lockTimeout seconds; 0 waits forever)
     * @throws \RuntimeException on timeout
     */
    public function acquireFlock($fp, $mode, $dbname){
        $this->metrics['locks']++;
        if(flock($fp, $mode | LOCK_NB)){
            return;
        }
        // Held by another process: wait, up to lockTimeout (0: as long as it takes)
        $this->metrics['lockWaits']++;
        $start = microtime(true);
        try {
            if($this->lockTimeout <= 0){
                if(!flock($fp, $mode)){
                    throw new \RuntimeException("noneDB: could not lock database '{$dbname}'", \noneDB::LOCK_FAILED);
                }
                return;
            }
            while(!flock($fp, $mode | LOCK_NB)){
                if((microtime(true) - $start) >= $this->lockTimeout){
                    $this->metrics['lockTimeouts']++;
                    throw new \RuntimeException("noneDB: timed out after {$this->lockTimeout}s waiting for the lock on database '{$dbname}'", \noneDB::LOCK_FAILED);
                }
                usleep($this->lockRetryDelay);
            }
        } finally {
            $this->metrics['lockWaitSeconds'] += microtime(true) - $start;
        }
    }

    /**
     * A file is about to be written: the first write of an exclusive session bumps the generation of the
     * database the file belongs to (see saveLockState). A database file written without its database's
     * exclusive lock is a bug: LogicException. Files of no database (dbDir housekeeping) pass.
     * @throws \LogicException
     */
    public function noteWrite($file){
        $file = (string)$file;
        unset($this->dataSizes[$file]); // a size cached for global index checks
        $owner = null;
        foreach($this->lockRegistry as $path => $entry){
            // Files of a database are inside its directory: the lock path without "LOCK"
            $prefix = substr($path, 0, -4);
            if(strncmp($file, $prefix, strlen($prefix)) === 0){
                $owner = $path;
                break;
            }
        }
        if($owner !== null){
            if(substr($file, -6) === '.jsonl' && strpos($file, '/segments/') !== false){
                // A data file: global index lookups check its shard's newer lines (globalCheckShards)
                $writes = &$this->segWrites[$owner];
                $writes['seq'] = ($writes['seq'] ?? 0) + 1;
                $writes['paths'][$file] = $writes['seq'];
                unset($writes);
            }
            if($this->lockRegistry[$owner]['mode'] !== LOCK_EX){
                throw new \LogicException("noneDB: write to " . basename($file) . " under a shared lock of database '" . $this->lockRegistry[$owner]['dbname'] . "'");
            }
            $this->lockRegistry[$owner]['wrote'] = true;
            if(!$this->lockRegistry[$owner]['bumped']){
                $this->saveLockState($owner, true);
            }
        } elseif(preg_match('#/[0-9a-f]{32}\.ndb4/#', $file) === 1){
            throw new \LogicException("noneDB: write to " . basename($file) . " without the lock of its database");
        }
    }

    /**
     * Epoch of a locked database's state record (null: none or invalid)
     */
    public function registryEpoch($path){
        $epoch = $this->lockRegistry[$path]['epoch'] ?? null;
        if($epoch === false){
            $state = $this->lockRegistry[$path]['state'];
            $epoch = substr($state, strpos($state, ':') + 1); // a state with a valid epoch (see loadLockState)
            $this->lockRegistry[$path]['epoch'] = $epoch;
        }
        return $epoch;
    }

    /**
     * A data file of the database is about to be replaced or removed (compaction, the switch to shards, drop): new
     * epoch, so every other process drops all its caches of the database (caller holds the exclusive lock)
     */
    /**
     * Global field and spatial index files changed in memory during this lock session (a checkpoint per
     * shard updates them): written once here, at the end of the session, at an epoch change or before the
     * lock is upgraded, instead of once per shard (O(shards²) for an operation that checkpoints every
     * shard). Not written when the session failed or the epoch changed since: a missing entry only means
     * the shard is never skipped.
     */
    private function flushPendingGlobals($dbname, $path, $ok = true){
        $pending = $this->lockRegistry[$path]['globals'] ?? [];
        if(empty($pending)){
            return;
        }
        $this->lockRegistry[$path]['globals'] = [];
        $epoch = $this->registryEpoch($path);
        foreach($pending as $file => [$kind, $field, $deferredAt]){
            $global = $kind === 'gfx' ? ($this->fieldIndexCache[$file] ?? null) : ($this->globalSpatialIndexCache[$file] ?? null);
            if(!$ok || !empty($this->lockRegistry[$path]['rolledBack']) || $deferredAt !== $epoch || $global === null){
                unset($this->fieldIndexCache[$file], $this->globalSpatialIndexCache[$file]);
                continue;
            }
            try {
                if($kind === 'gfx'){
                    $this->fieldIndex->writeGlobalFieldIndex($dbname, $field, $global);
                } else {
                    $this->rtree->writeGlobalSpatialIndex($dbname, $field, $global);
                }
            } catch(\Throwable $e){
                unset($this->fieldIndexCache[$file], $this->globalSpatialIndexCache[$file]);
                if($e->getCode() === \noneDB::LOCK_FAILED){
                    throw $e;
                }
                // never needed for correctness: a shard without a fitting entry is never skipped
            }
        }
    }

    public function bumpEpoch($dbname){
        $path = $this->getLockPath($dbname);
        if(!isset($this->lockRegistry[$path]) || $this->lockRegistry[$path]['mode'] !== LOCK_EX){
            throw new \LogicException("noneDB: new epoch of database '{$dbname}' without its exclusive lock");
        }
        $this->flushPendingGlobals($dbname, $path); // under the epoch their entries belong to
        $this->lockRegistry[$path]['epoch'] = bin2hex(random_bytes(4));
        $this->saveLockState($path, true);
        unset($this->globalSkip[$path], $this->segWrites[$path]);
        // What this process checked against the data files no longer holds for the one being replaced
        $prefix = $this->layout->dbHome($dbname);
        foreach([&$this->dataSizes, &$this->dataEpochs, &$this->secondaryChecked, &$this->tailCache] as &$cache){
            foreach(array_keys($cache) as $key){
                if(strncmp((string)$key, $prefix, strlen($prefix)) === 0){
                    unset($cache[$key]);
                }
            }
        }
        unset($cache);
    }

    /**
     * Run a read operation: shared lock while the database exists, no lock (and no files created)
     * when it doesn't (withLock's missing-database path). Buffered records are flushed first.
     */
    public function withReadLock($dbname, callable $fn){
        return $this->withLock($dbname, false, function() use ($dbname, $fn){
            if(isset($this->lockRegistry[$this->getLockPath($dbname)])){
                $this->buffer->flushPendingBuffers($dbname);
            }
            return $fn();
        });
    }
    // ==========================================
    // DATABASE LOCKING AND STATE
    // ==========================================
    //
    // Every database has a lock file {locator}.ndb4/LOCK. Besides being the flock target it holds a small
    // state record (docs/STORAGE_FORMAT.md §4.2). It is read on every lock acquisition anyway, so the
    // checks below cost no extra I/O:
    //
    //   "{generation}:{epoch}[ b][ c][ d][ m][ g=N>X][ j=main,s0,s3 | J]"   space padded to LOCK_STATE_SIZE bytes
    //
    //   generation  bumped by the first file write of a write session (sessions that write nothing keep
    //               it). A value other than the one this process last saw means another process changed
    //               the database: its caches are checked again (key indexes: only the new data).
    //   epoch       8 hex digits, new before a file a process may have cached is replaced or removed
    //               (compaction, generation switch, drop, maintenance) and for a new lock record: another
    //               epoch drops every cache of the database and makes the process read the store again.
    //   b           the write buffer may hold records; they are flushed before the next read or write.
    //   c / d       creation / drop in progress (completed or undone by the next exclusive session).
    //   m           maintenance in progress: MAINTENANCE_IN_PROGRESS.
    //   g=N>X       generation switch from N to X in progress: recovered before any data is returned.
    //   j=... / J   journal: shard counters of these segments (J: all) may lag behind the data because a
    //               writer died; the next lock holder recounts them. Key, field and spatial indexes need
    //               none: they replay the data after their checkpoint.
    //
    // The record is overwritten in place with a fixed size (one small write: no truncate, no rename).
    // Lock files are never deleted (dropDB keeps them), and their handles stay open between operations.

    public const LOCK_STATE_SIZE = 128;
    public const LOCK_HANDLE_LIMIT = 64;
    /** Exception code (internal): an exclusive lock was needed while a snapshot holds several shared ones */
    public const SNAPSHOT_RETRY = 3303;
    public const DB_GONE = 3399;      // internal: the database directory vanished under a kept path
    public const DB_ABSENT = 3398;    // internal: found absent without the missing-database path
}
}
namespace noneDB\Internal\Concurrency {

use function array_values, basename, count, dirname, implode, ksort, microtime, realpath, usleep;
use const SORT_STRING;

/**
 * @internal
 */
final class Snapshot {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $lockRetryDelay;
    private $lockTimeout;
    private $multiLock;
    private $cache;
    private $checkpoints;
    private $layout;
    private $locks;
    private $recovery;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->layout, $this->locks, $this->recovery, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->lockRetryDelay = &$this->k->lockRetryDelay;
        $this->lockTimeout = &$this->k->lockTimeout;
        $this->multiLock = &$this->rt->multiLock;
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
        $this->layout = null;
        $this->locks = null;
        $this->recovery = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Run $fn with shared locks on several databases at once (joins): every read sees the same
     * state of all of them. Locks are taken in one canonical order (by lock file path), so two snapshots
     * never wait for each other in a circle, and no exclusive lock is ever waited for while several are
     * held: work that needs one (recovery, an index repair) is done for each database alone first.
     */
    public function withSnapshot(array $dbnames, callable $fn){
        $names = [];
        foreach($dbnames as $name){
            $name = $this->layout->normalizeDbName($name);
            $lock = $this->locks->getLockPath($name);
            $dir = realpath(dirname($lock));
            $names[($dir === false ? dirname($lock) : $dir) . '/' . basename($lock)] = $name;
        }
        ksort($names, SORT_STRING);
        $names = array_values($names);
        if(count($names) < 2){
            return $fn();
        }
        $started = microtime(true);
        while(true){
            foreach($names as $name){
                // Alone: recovery, buffer flush and key index repairs happen here, not under the snapshot
                $this->locks->withReadLock($name, function() use ($name){
                    $done = false; // a segment was read: the cache may drop what it loaded before the next one
                    foreach($this->segments->listSegments($name) as $segment){
                        if($done){
                            $this->cache->evictBetweenSegments();
                        }
                        $done = true;
                        $this->checkpoints->readJsonlIndex($name, $segment);
                    }
                });
            }
            try {
                return $this->withSharedLocks($names, $fn);
            } catch(\RuntimeException $e){
                if($e->getCode() !== \noneDB\Internal\Concurrency\LockManager::SNAPSHOT_RETRY){
                    throw $e;
                }
                if($this->lockTimeout > 0 && microtime(true) - $started >= $this->lockTimeout){
                    throw new \RuntimeException("noneDB: timed out waiting for a consistent snapshot of " . implode(', ', $names), \noneDB::LOCK_FAILED, $e);
                }
                usleep($this->lockRetryDelay);
            }
        }
    }

    private function withSharedLocks(array $names, callable $fn, $i = 0){
        if($i >= count($names)){
            $this->multiLock++;
            try {
                return $fn();
            } finally {
                $this->multiLock--;
            }
        }
        return $this->locks->withReadLock($names[$i], function() use ($names, $fn, $i){
            return $this->withSharedLocks($names, $fn, $i + 1);
        });
    }
}
}
namespace noneDB\Internal\Config {

use function array_key_exists, array_keys, array_reverse, array_unique, basename, count, ctype_digit, define, defined,
    dirname, fclose, file_exists, file_get_contents, flock, floor, getcwd, getenv, implode, in_array, is_array, is_bool,
    is_file, is_finite, is_float, is_int, is_link, is_numeric, is_string, json_decode, levenshtein, md5, octdec,
    preg_match, preg_replace, realpath, register_shutdown_function, rtrim, spl_object_id, str_replace, strpos,
    strtolower, substr, trigger_error, trim;
use const DIRECTORY_SEPARATOR;
use const E_USER_NOTICE, LOCK_UN, NAN, PHP_OS_FAMILY, PHP_SAPI;

/**
 * @internal
 */
final class Configuration {
    /** The settings (docs/CONFIGURATION.md "Settings"), in the order the documentation lists them */
    public const SETTINGS = ['secretKey', 'dbDir', 'autoCreateDB', 'shardingEnabled', 'shardSize', 'autoCompactThreshold', 'lockTimeout',
        'lockRetryDelay', 'bufferEnabled', 'fieldIndexEnabled', 'spatialIndexEnabled', 'durability', 'cacheMemoryLimit', 'filePermissions',
        'directoryPermissions'];

    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $autoCreateDB;
    private $bufferEnabled;
    private $cloneSourceId;
    private $dbDir;
    private $devMode;
    private $fieldIndexEnabled;
    private $directoryPermissions;
    private $durable;
    private $filePermissions;
    private $autoCompactThreshold;
    private $keyFingerprint;
    private $lockRetryDelay;
    private $lockTimeout;
    private $shardSize;
    private $shardingEnabled;
    private $spatialIndexEnabled;
    private $cacheMemoryLimit;
    private $configData;
    private $configFileDir;
    private $configFilePath;
    private $configLoaded;
    private $devModeNoticeShown;
    private $placeholderKeys;
    private $secretKeys;
    private $shutdownCleanupRegistered;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->autoCreateDB = &$this->k->autoCreateDB;
        $this->bufferEnabled = &$this->k->bufferEnabled;
        $this->cloneSourceId = &$this->k->cloneSourceId;
        $this->dbDir = &$this->k->dbDir;
        $this->devMode = &$this->k->devMode;
        $this->fieldIndexEnabled = &$this->k->fieldIndexEnabled;
        $this->directoryPermissions = &$this->k->directoryPermissions;
        $this->durable = &$this->k->durable;
        $this->filePermissions = &$this->k->filePermissions;
        $this->autoCompactThreshold = &$this->k->autoCompactThreshold;
        $this->keyFingerprint = &$this->k->keyFingerprint;
        $this->lockRetryDelay = &$this->k->lockRetryDelay;
        $this->lockTimeout = &$this->k->lockTimeout;
        $this->shardSize = &$this->k->shardSize;
        $this->shardingEnabled = &$this->k->shardingEnabled;
        $this->spatialIndexEnabled = &$this->k->spatialIndexEnabled;
        $this->cacheMemoryLimit = &$this->rt->cacheMemoryLimit;
        $this->configData = &$this->rt->configData;
        $this->configFileDir = &$this->rt->configFileDir;
        $this->configFilePath = &$this->rt->configFilePath;
        $this->configLoaded = &$this->rt->configLoaded;
        $this->devModeNoticeShown = &$this->rt->devModeNoticeShown;
        $this->placeholderKeys = &$this->rt->placeholderKeys;
        $this->secretKeys = &$this->rt->secretKeys;
        $this->shutdownCleanupRegistered = &$this->rt->shutdownCleanupRegistered;
    }

    /**
     * Drop the handles to the kernel and the other services (the instance is gone)
     */
    public function unbind(): void {
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Load the configuration of the instance (Kernel::__construct, after the caches are linked)
     * @param array|null $config Optional config array; merged over the .nonedb file when one exists
     * @throws \RuntimeException If no configuration can be found outside dev mode
     * @throws \InvalidArgumentException If secretKey is missing, empty, not a string or a placeholder
     */
    public function initialize($config = null){
        $this->loadConfig($config);
        $this->validateSecretKey();

        if(!$this->shutdownCleanupRegistered){
            $this->shutdownCleanupRegistered = true;
            register_shutdown_function(static function(){
                self::releaseInterruptedSessions();
            });
        }
    }

    /**
     * Shutdown: a fatal error (memory_limit, max_execution_time) or exit() inside an operation skips
     * its finally blocks, so its locks would stay registered and later shutdown code would continue the
     * half-done session. Release them and drop every cache instead: the next operation takes the lock
     * again and recovers from the files like after a crash.
     */
    private static function releaseInterruptedSessions(){
        if(empty(\noneDB\Internal\Cache\Runtime::get()->lockRegistry)){
            return;
        }
        foreach(\noneDB\Internal\Cache\Runtime::get()->lockRegistry as $entry){
            @flock($entry['fp'], LOCK_UN);
            @fclose($entry['fp']);
        }
        \noneDB\Internal\Cache\Runtime::get()->lockRegistry = [];
        \noneDB\Internal\Cache\CacheManager::clearStaticCache();
    }

    /**
     * Never expose the secret in debug output
     * @return array
     */
    public function debugInfo(){
        return [
            'dbDir' => $this->dbDir,
            'secretKey' => '***',
            'autoCreateDB' => $this->autoCreateDB,
            'shardingEnabled' => $this->shardingEnabled,
            'shardSize' => $this->shardSize,
            'bufferEnabled' => $this->bufferEnabled,
            'durability' => $this->durable ? 'durable' : 'performance',
            'devMode' => $this->devMode,
        ];
    }

    public function cloned(){
        $source = $this->secretKeys[$this->cloneSourceId] ?? null;
        $this->cloneSourceId = spl_object_id($this->k);
        if($source !== null){
            $this->secretKeys[$this->cloneSourceId] = $source;
        }
    }

    public function destroyed(){
        // Anything still dirty belongs to a lock we no longer hold; it was flushed on release.
        unset($this->secretKeys[spl_object_id($this->k)]);
    }

    public function getSecretKey(){
        return $this->secretKeys[spl_object_id($this->k)] ?? null;
    }

    private function setSecretKey($key){
        $this->secretKeys[spl_object_id($this->k)] = $key;
        $this->cloneSourceId = spl_object_id($this->k);
        $this->keyFingerprint = null;
    }

    /**
     * Load configuration.
     *
     * The .nonedb file is searched in: the entry script's directory, noneDB.php's directory,
     * the Composer project root (when installed under vendor/) and the current working directory.
     * A config array passed to the constructor is merged over the file (file is optional when the
     * array already contains secretKey and dbDir).
     *
     * @param array|null $config Optional config array
     * @throws \RuntimeException If config is missing and not in dev mode
     */
    private function loadConfig($config = null){
        $arrayConfig = is_array($config) ? $config : [];
        // A secretKey given in the array is used (and validated) as given, also null/false/'':
        // never replaced by the file's key or the dev mode key
        $keyGiven = array_key_exists('secretKey', $arrayConfig);
        $arrayIsComplete = $keyGiven && isset($arrayConfig['dbDir']);

        // Load (and cache) the file config once per process
        if(!$this->configLoaded){
            $this->configData = null;
            $this->configFilePath = null;
            $this->configFileDir = null;

            $configPath = self::findConfigFile();
            if($configPath !== null){
                $content = @file_get_contents($configPath);
                if($content === false){
                    if(!$arrayIsComplete){
                        throw new \RuntimeException("noneDB: Cannot read config file: {$configPath}");
                    }
                } else {
                    $data = json_decode($content, true);
                    if(!is_array($data)){
                        if(!$arrayIsComplete){
                            throw new \RuntimeException("noneDB: Invalid JSON in config file: {$configPath}");
                        }
                    } else {
                        $this->configData = $data;
                        $this->configFilePath = $configPath;
                        $this->configFileDir = dirname($configPath);
                    }
                }
            }
            $this->configLoaded = true;
        }

        if($this->configData !== null){
            self::checkNames($this->configData, self::SETTINGS, 'setting', " in {$this->configFilePath}");
            $fileConfig = $this->configData;
            if(isset($arrayConfig['dbDir'])){
                unset($fileConfig['dbDir']); // overridden below; no need to resolve the file's one
            }
            $this->applyConfig($fileConfig, $this->configFileDir);
        }
        self::checkNames($arrayConfig, self::SETTINGS, 'setting');
        if(!empty($arrayConfig)){
            $this->applyConfig($arrayConfig, null);
        }
        if($this->configData !== null || $arrayIsComplete || ($keyGiven && $this->dbDir !== null)){
            return;
        }

        if(self::isDevModeEnabled()){
            $this->devMode = true;
            $projectRoot = self::projectRoot();
            if($this->dbDir === null){
                $this->dbDir = $projectRoot . '/db/';
            }
            if(!$keyGiven){
                // Deterministic: a development database stays readable from one run to the next
                $this->setSecretKey('nonedb_dev_mode_key_' . md5(__DIR__));
            }
            if(PHP_SAPI !== 'cli' && !$this->devModeNoticeShown){
                $this->devModeNoticeShown = true;
                trigger_error('noneDB: development mode is active (predictable secretKey). Never use it in production.', E_USER_NOTICE);
            }
            return;
        }

        throw new \RuntimeException(
            "noneDB: Configuration file not found!\n" .
            "Create a '.nonedb' config file in your project root.\n" .
            "See '.nonedb.example' for reference.\n" .
            "For development, set NONEDB_DEV_MODE=true environment variable or define('NONEDB_DEV_MODE', true);"
        );
    }

    /**
     * Candidate config file locations, in priority order
     * @return array
     */
    private static function configSearchPaths(): array {
        $dirs = [self::scriptDir(), __DIR__];
        $root = self::projectRoot();
        if($root !== __DIR__){
            $dirs[] = $root;
        }
        $cwd = getcwd();
        if($cwd !== false){
            $dirs[] = $cwd;
        }
        $paths = [];
        foreach(array_unique($dirs) as $dir){
            $paths[] = rtrim($dir, '/\\') . '/' . \noneDB\Internal\Cache\Runtime::get()->configFile;
        }
        return $paths;
    }

    private static function findConfigFile(): ?string {
        foreach(self::configSearchPaths() as $path){
            if(is_file($path)){
                return $path;
            }
        }
        return null;
    }

    /**
     * Absolute directory of the entry script (falls back to the working directory)
     */
    private static function scriptDir(): string {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $cwd = getcwd();
        // A relative SCRIPT_FILENAME depends on the working directory: both are part of the memo key
        $memoKey = $script . "\0" . $cwd;
        if(isset(\noneDB\Internal\Cache\Runtime::get()->scriptDirs[$memoKey])){
            return \noneDB\Internal\Cache\Runtime::get()->scriptDirs[$memoKey];
        }
        $dir = null;
        if($script !== ''){
            $real = realpath($script);
            if($real !== false){
                $dir = dirname($real);
            } else {
                $realDir = realpath(dirname($script));
                if($realDir !== false){
                    $dir = $realDir;
                }
            }
        }
        if($dir === null){
            $dir = $cwd !== false ? $cwd : __DIR__;
        }
        if(count(\noneDB\Internal\Cache\Runtime::get()->scriptDirs) > 16){
            \noneDB\Internal\Cache\Runtime::get()->scriptDirs = [];
        }
        return \noneDB\Internal\Cache\Runtime::get()->scriptDirs[$memoKey] = $dir;
    }

    /**
     * Project root: the directory that contains vendor/ when installed with Composer, else noneDB.php's directory
     */
    private static function projectRoot(): string {
        $dir = str_replace('\\', '/', __DIR__);
        $pos = strpos($dir, '/vendor/');
        if($pos !== false){
            return substr(__DIR__, 0, $pos);
        }
        return __DIR__;
    }

    private static function isDevModeEnabled(): bool {
        return getenv('NONEDB_DEV_MODE') === 'true'
            || getenv('NONEDB_DEV_MODE') === '1'
            || (defined('NONEDB_DEV_MODE') && NONEDB_DEV_MODE === true);
    }

    private static function isAbsolutePath(string $path): bool {
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1);
    }

    /**
     * Apply configuration values to instance properties
     * @param array $config Configuration array
     * @param string|null $baseDir Directory that relative dbDir values resolve against (null: the entry script's)
     */
    private function applyConfig(array $config, ?string $baseDir){
        if(array_key_exists('secretKey', $config)){
            $this->setSecretKey($config['secretKey']);
        }
        if(array_key_exists('dbDir', $config)){
            if(!is_string($config['dbDir']) || trim($config['dbDir']) === ''){
                throw new \InvalidArgumentException("noneDB: 'dbDir' must be a directory path (a non-empty string)");
            }
            $this->dbDir = $this->resolveDbDir($config['dbDir'], $baseDir);
        }
        if(array_key_exists('autoCreateDB', $config)){
            $this->autoCreateDB = self::configBool('autoCreateDB', $config['autoCreateDB']);
        }

        // Sharding settings
        if(array_key_exists('shardingEnabled', $config)){
            $this->shardingEnabled = self::configBool('shardingEnabled', $config['shardingEnabled']);
        }
        if(array_key_exists('shardSize', $config)){
            $shardSize = self::configNumber($config['shardSize'], true) ?? 0;
            if($shardSize < 1){
                throw new \InvalidArgumentException("noneDB: 'shardSize' must be a positive integer");
            }
            $this->shardSize = $shardSize;
        }

        // Compaction settings
        if(array_key_exists('autoCompactThreshold', $config)){
            $threshold = self::configNumber($config['autoCompactThreshold'], false) ?? 0.0;
            if($threshold <= 0){
                throw new \InvalidArgumentException("noneDB: 'autoCompactThreshold' must be greater than 0");
            }
            $this->autoCompactThreshold = $threshold;
        }

        // Lock settings
        if(array_key_exists('lockTimeout', $config)){
            // Seconds, fractions allowed (0.5 used to become 0 = wait forever)
            $timeout = $config['lockTimeout'];
            if(!is_int($timeout) && !is_float($timeout) && !(is_string($timeout) && is_numeric($timeout))){
                $timeout = null;
            }
            $timeout = $timeout === null ? NAN : (float)$timeout;
            if(!is_finite($timeout) || $timeout < 0){
                throw new \InvalidArgumentException("noneDB: 'lockTimeout' must be 0 (wait forever) or a positive number of seconds");
            }
            $this->lockTimeout = $timeout;
        }
        if(array_key_exists('lockRetryDelay', $config)){
            $delay = self::configNumber($config['lockRetryDelay'], true) ?? 0;
            if($delay < 1){
                throw new \InvalidArgumentException("noneDB: 'lockRetryDelay' must be a positive number of microseconds");
            }
            $this->lockRetryDelay = $delay;
        }

        // Write buffer
        if(array_key_exists('bufferEnabled', $config)){
            $this->bufferEnabled = self::configBool('bufferEnabled', $config['bufferEnabled']);
        }

        // Use indexes for queries (indexes are always kept up to date)
        if(array_key_exists('fieldIndexEnabled', $config)){
            $this->fieldIndexEnabled = self::configBool('fieldIndexEnabled', $config['fieldIndexEnabled']);
        }
        if(array_key_exists('spatialIndexEnabled', $config)){
            $this->spatialIndexEnabled = self::configBool('spatialIndexEnabled', $config['spatialIndexEnabled']);
        }
        if(array_key_exists('durability', $config)){
            $this->durable = self::durabilityMode($config['durability']);
        }
        if(array_key_exists('filePermissions', $config)){
            $this->filePermissions = self::permissions('filePermissions', $config['filePermissions'], 0600);
        }
        if(array_key_exists('directoryPermissions', $config)){
            $this->directoryPermissions = self::permissions('directoryPermissions', $config['directoryPermissions'], 0700);
        }
        if(array_key_exists('cacheMemoryLimit', $config)){
            $limit = $config['cacheMemoryLimit'];
            if($limit === 'auto'){
                $this->cacheMemoryLimit = null;
            } elseif(!(is_int($limit) && $limit >= 0) && !(is_string($limit) && ctype_digit($limit))){
                throw new \InvalidArgumentException("noneDB: 'cacheMemoryLimit' must be a non-negative integer (bytes) or 'auto'");
            } else {
                $this->cacheMemoryLimit = (int)$limit;
            }
        }
    }

    /**
     * The 'durability' setting: 'performance' or 'durable' (true). Durable mode is never downgraded: where
     * the platform can't provide it, the instance is not created (docs/DURABILITY.md §1).
     * @throws \InvalidArgumentException a value other than the two modes
     * @throws \RuntimeException UNSUPPORTED_DURABILITY
     */
    public static function durabilityMode($value): bool {
        self::durabilityValue($value);
        if($value === 'durable' && !self::durableSupported()){
            throw new \RuntimeException("noneDB: durable mode is not supported on " . self::osFamily() . ": "
                . self::platformNote(self::osFamily()) . " Use 'durability' => 'performance' or run on Linux.", \noneDB::UNSUPPORTED_DURABILITY);
        }
        return $value === 'durable';
    }

    /**
     * A durability value: 'performance' or 'durable', whatever the platform
     * @throws \InvalidArgumentException
     */
    public static function durabilityValue($value): void {
        if($value !== 'performance' && $value !== 'durable'){
            throw new \InvalidArgumentException("noneDB: 'durability' must be 'performance' or 'durable'");
        }
    }

    /** The platform durable mode is checked against */
    public static function osFamily(): string {
        return PHP_OS_FAMILY;
    }

    /** Durable mode is supported on Linux only (docs/DURABILITY.md §1) */
    public static function durableSupported(): bool {
        return self::osFamily() === 'Linux';
    }

    /** Why durable mode is not available on a platform ('' where it is) */
    public static function platformNote(string $os): string {
        switch($os){
            case 'Linux':
                return '';
            case 'Darwin':
                return 'fsync() does not flush the drive cache on macOS, and PHP cannot call F_FULLFSYNC.';
            case 'Windows':
                return 'a directory cannot be synced on Windows, so a new file or a rename is not durable.';
        }
        return 'only Linux is supported for durable mode.';
    }

    /**
     * A numeric setting: an int, a finite float or a numeric string (an integer when $integer); anything
     * else (booleans, arrays, "10abc", NAN) is null, and the caller rejects it
     * @return int|float|null
     */
    private static function configNumber($value, bool $integer){
        if(is_string($value) && is_numeric(trim($value))){
            $value = trim($value) + 0;
        }
        if(is_int($value)){
            return $integer ? $value : (float)$value;
        }
        if(is_float($value) && is_finite($value) && (!$integer || floor($value) === $value)){
            return $integer ? (int)$value : $value;
        }
        return null;
    }

    /**
     * Every key of $given must be one of $known (settings, or a method's options): an unknown one is an error that
     * names it, suggests the known name that differs only in letter case or by up to two letters, and lists them
     * @param string $noun 'setting' or 'option'
     * @param string $where Where they come from: " in {file}", " of {method}()"
     * @throws \InvalidArgumentException
     */
    public static function checkNames(array $given, array $known, string $noun, string $where = ''): void {
        foreach(array_keys($given) as $name){
            if(in_array($name, $known, true)){
                continue;
            }
            $name = (string)$name;
            $closest = null;
            $distance = 3;
            foreach($known as $candidate){
                $d = levenshtein(strtolower($name), strtolower($candidate));
                if($d < $distance){
                    $closest = $candidate;
                    $distance = $d;
                }
            }
            throw new \InvalidArgumentException("noneDB: unknown {$noun} '{$name}'{$where}" . ($closest === null ? '' : " (did you mean '{$closest}'?)")
                . "; the {$noun}s are: " . implode(', ', $known));
        }
    }

    /**
     * An option that is true or false (an option is given in PHP: a boolean, nothing else)
     * @throws \InvalidArgumentException
     */
    public static function optionBool(string $name, $value): bool {
        if(!is_bool($value)){
            throw new \InvalidArgumentException("noneDB: '{$name}' must be true or false");
        }
        return $value;
    }

    /**
     * A boolean setting: true/false, 1/0, "1"/"0", "true"/"false" (any case). Anything else is an error,
     * so that a string like "false" or "no" never means true.
     * @throws \InvalidArgumentException
     */
    private static function configBool(string $name, $value): bool {
        if(is_bool($value)){
            return $value;
        }
        if($value === 1 || $value === 0){
            return $value === 1;
        }
        if(is_string($value)){
            $lower = strtolower(trim($value));
            if($lower === '1' || $lower === 'true'){
                return true;
            }
            if($lower === '0' || $lower === 'false'){
                return false;
            }
        }
        throw new \InvalidArgumentException("noneDB: '{$name}' must be true or false");
    }

    /**
     * Permission bits of a setting: an integer or an octal string ('0640'); the owner must have $owner (read and
     * write, and search for directories), and the world no write access
     * @throws \InvalidArgumentException
     */
    private static function permissions(string $name, $value, int $owner): int {
        $mode = is_int($value) ? $value : (is_string($value) && preg_match('/^0?[0-7]{3}$/D', $value) === 1 ? (int)octdec($value) : -1);
        if($mode < 0 || $mode > 0777 || ($mode & $owner) !== $owner || ($mode & 0002) !== 0){
            throw new \InvalidArgumentException("noneDB: '{$name}' must be permission bits like " . ($owner === 0600 ? '0640' : '0750')
                . ' (an integer or an octal string): the owner needs ' . ($owner === 0600 ? 'read and write' : 'read, write and search') . ', the world may not write');
        }
        return $mode;
    }

    /**
     * Resolve a configured dbDir to its canonical absolute path ending with a slash (STORAGE_FORMAT.md §3).
     * Relative paths (with or without a leading ./) are relative to the config file's directory
     * (file config) or to the entry script's directory (array config).
     */
    private function resolveDbDir(string $dbDir, ?string $baseDir): string {
        if(!self::isAbsolutePath($dbDir)){
            $relative = preg_replace('#^(\./)+#', '', $dbDir);
            $baseDir = $baseDir ?? self::scriptDir();
            $dbDir = rtrim($baseDir, '/\\') . '/' . $relative;
        }
        return rtrim(self::canonicalPath($dbDir), '/\\') . '/';
    }

    /**
     * A path with its links and its "." and ".." resolved: the nearest existing part by realpath(), the parts
     * after it (that don't exist yet) by their names
     */
    public static function canonicalPath(string $path): string {
        $missing = [];
        for($at = rtrim($path, '/\\'); $at !== '' && !file_exists($at) && !is_link($at); $at = dirname($at)){
            $missing[] = basename($at);
            if(dirname($at) === $at){
                break;
            }
        }
        $resolved = realpath($at === '' ? DIRECTORY_SEPARATOR : $at);
        $resolved = $resolved === false ? rtrim($at, '/\\') : rtrim($resolved, '/\\');
        foreach(array_reverse($missing) as $part){
            if($part === '..'){
                $resolved = dirname($resolved);
            } elseif($part !== '.' && $part !== ''){
                $resolved .= DIRECTORY_SEPARATOR . $part;
            }
        }
        return $resolved === '' ? DIRECTORY_SEPARATOR : $resolved;
    }

    /**
     * Reject missing, empty, non-string and placeholder secret keys
     * @throws \InvalidArgumentException
     */
    private function validateSecretKey(){
        $key = $this->getSecretKey();
        if(!is_string($key) || trim($key) === ''){
            throw new \InvalidArgumentException(
                "noneDB: 'secretKey' must be a non-empty string. Set it in your .nonedb file or constructor config "
                . "(generate one with: php -r \"echo bin2hex(random_bytes(32));\")."
            );
        }
        if(in_array($key, $this->placeholderKeys, true)){
            throw new \InvalidArgumentException(
                "noneDB: 'secretKey' is a publicly known key (an example placeholder or a published default key). "
                . "Replace it with a random secret (php -r \"echo bin2hex(random_bytes(32));\"); databases written with it "
                . "move to the new key with noneDB::migrateKey() (docs/CONFIGURATION.md)."
            );
        }
        if($this->dbDir === null){
            throw new \RuntimeException("noneDB: 'dbDir' is not configured");
        }
    }

    /**
     * Check if config file exists
     * @return bool
     */
    public static function configExists(): bool {
        return self::findConfigFile() !== null;
    }

    /**
     * Get the config file template path
     * @return string|null
     */
    public static function getConfigTemplate(): ?string {
        $templatePath = __DIR__ . '/.nonedb.example';
        return file_exists($templatePath) ? $templatePath : null;
    }

    /**
     * Clear config cache (useful for testing)
     * @return void
     */
    public static function clearConfigCache(): void {
        \noneDB\Internal\Cache\Runtime::get()->configLoaded = false;
        \noneDB\Internal\Cache\Runtime::get()->configData = null;
        \noneDB\Internal\Cache\Runtime::get()->configFilePath = null;
        \noneDB\Internal\Cache\Runtime::get()->configFileDir = null;
    }

    /**
     * True when development mode is enabled (NONEDB_DEV_MODE constant or environment variable)
     * @return bool
     */
    public static function isDevMode(): bool {
        return self::isDevModeEnabled();
    }

    /**
     * Set development mode programmatically
     * Useful for testing or when env vars are not available
     * @param bool $enabled
     * @return void
     */
    public static function setDevMode(bool $enabled): void {
        if($enabled && !defined('NONEDB_DEV_MODE')){
            define('NONEDB_DEV_MODE', true);
        }
    }
}
}
namespace noneDB\Internal\Core {

use function array_key_exists, array_keys, array_values, count, is_array, is_int;

/**
 * @internal
 */
final class Crud {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $autoCreateDB;
    private $bufferEnabled;
    private $buffer;
    private $findFilter;
    private $io;
    private $layout;
    private $locks;
    private $segments;
    private $writer;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->findFilter, $this->io, $this->layout, $this->locks, $this->segments, $this->writer);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->autoCreateDB = &$this->k->autoCreateDB;
        $this->bufferEnabled = &$this->k->bufferEnabled;
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
        $this->findFilter = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->segments = null;
        $this->writer = null;
        $this->k = null;
        $this->rt = null;
    }

    /**
     * The records matching the filters, in key order (a "key" filter: in its order)
     * @param string $dbname
     * @param mixed $filters 0/null/[] for all records, or an array of field => value (strict ===);
     *                       a "key" entry selects by record key (int or list of ints)
     * @return array|false False when the database doesn't exist and autoCreateDB is off, or for invalid filters
     */
    public function find($dbname, $filters=0){
        $dbname = $this->layout->normalizeDbName($dbname);
        if($filters === null || is_int($filters)){
            $filters = [];
        }
        if(!is_array($filters)){
            return false;
        }
        return $this->locks->withReadLock($dbname, function() use ($dbname, $filters){
            return $this->findUnlocked($dbname, $filters);
        });
    }

    public function findUnlocked($dbname, $filters=0){
        if($filters === null || is_int($filters)){
            $filters = [];
        }
        if(!$this->segments->dbExists($dbname)){
            // Reads never create databases
            return $this->autoCreateDB ? [] : false;
        }
        return array_values($this->findFilter->selectRecords($dbname, $filters));
    }

    /**
     * Check if 'key' exists at top level of data (not nested)
     * @param array $data
     * @return bool
     */
    private function hasReservedKeyField($data){
        return is_array($data) && array_key_exists("key", $data);
    }

    /**
     * Check if array is a list of records: sequential 0..n-1 keys, every element an array
     * @param array $data
     * @return bool
     */
    private function isRecordList($data){
        if(!is_array($data) || count($data) === 0){
            return false;
        }
        $i = 0;
        foreach($data as $k => $v){
            if($k !== $i++ || !is_array($v)){
                return false;
            }
        }
        return true;
    }

    /**
     * Validate insert data
     * @return array [items, error|null]
     */
    private function validateInsertData($data){
        if(!is_array($data)){
            return [[], "insert data must be array"];
        }
        if(empty($data)){
            return [[], "insert data must not be empty"];
        }
        $keys = array_keys($data);
        $looksLikeList = is_int($keys[0]) && is_array($data[$keys[0]]);
        if($looksLikeList && !$this->isRecordList($data)){
            return [[], "insert data must be either one record or a list of records"];
        }
        $items = $looksLikeList ? array_values($data) : [$data];
        return $this->io->withExactFloats(function() use ($items){
            foreach($items as $item){
                if($this->hasReservedKeyField($item)){
                    return [[], "You cannot set key name to key"];
                }
                if($this->io->encodeJson($item) === false){
                    return [[], "insert data cannot be encoded as JSON (invalid UTF-8, NAN/INF or unsupported value)"];
                }
            }
            return [$items, null];
        });
    }

    /**
     * Insert one record or a list of records: each gets the next key
     * @param string $dbname
     * @param array $data One record, or a list of records
     * @return array ['n' => inserted count] or ['n' => 0, 'error' => message]
     */
    public function insert($dbname, $data){
        $dbname = $this->layout->normalizeDbName($dbname);
        list($items, $error) = $this->validateInsertData($data);
        if($error !== null){
            return array("n" => 0, "error" => $error);
        }
        return $this->locks->withLock($dbname, true, function() use ($dbname, $items){
            if($this->bufferEnabled){
                return $this->buffer->insertBuffered($dbname, $items);
            }
            $this->buffer->flushPendingBuffers($dbname);
            return $this->writer->insertRecords($dbname, $items);
        }, 'insert');
    }

    /**
     * Delete the records matching the filters
     * @param string $dbname
     * @param array $data Filters (same rules as find); [] deletes every record
     * @return array ['n' => deleted count]
     */
    public function delete($dbname, $data){
        $dbname = $this->layout->normalizeDbName($dbname);
        if(!is_array($data)){
            return array("n" => 0, "error" => "Please check your delete parameters");
        }
        return $this->locks->withLock($dbname, true, function() use ($dbname, $data){
            return $this->deleteUnlocked($dbname, $data);
        }, 'delete');
    }

    private function deleteUnlocked($dbname, $data){
        $this->buffer->flushPendingBuffers($dbname);
        if(!$this->segments->dbExists($dbname)){
            return array("n" => 0);
        }
        $deleted = 0;
        foreach($this->findFilter->segmentMatches($dbname, $data) as $segment => $records){
            $deleted += $this->writer->deleteSegmentRecords($dbname, $segment, $records);
        }
        return array("n" => $deleted);
    }

    /**
     * Set fields of the records matching the filters
     * @param string $dbname
     * @param array $data [filters, ['set' => [field => value, ...]]]
     * @return array ['n' => updated count]
     */
    public function update($dbname, $data){
        $dbname = $this->layout->normalizeDbName($dbname);
        if(!is_array($data) || !array_key_exists(0, $data) || !is_array($data[0])
            || !isset($data[1]) || !is_array($data[1]) || !isset($data[1]['set']) || !is_array($data[1]['set'])){
            return array("n" => 0, "error" => "Please check your update parameters");
        }
        $error = $this->updateSetError($data[1]['set']);
        if($error !== null){
            return $error;
        }
        return $this->locks->withLock($dbname, true, function() use ($dbname, $data){
            return $this->updateUnlocked($dbname, $data);
        }, 'update');
    }

    private function updateUnlocked($dbname, $data){
        $this->buffer->flushPendingBuffers($dbname);
        if(!$this->segments->dbExists($dbname)){
            return array("n" => 0);
        }
        $updated = 0;
        foreach($this->findFilter->segmentMatches($dbname, $data[0]) as $segment => $records){
            $updated += $this->updateRecords($dbname, $segment, $records, $data[1]['set']);
        }
        return array("n" => $updated);
    }

    /**
     * The result of update() for fields that can't be set ("key", values JSON can't represent); null if they can
     * @return array|null
     */
    public function updateSetError(array $set){
        if(array_key_exists("key", $set)){
            return array("n" => 0, "error" => "Please check your update parameters");
        }
        if($this->io->encodeJson($set) === false){
            return array("n" => 0, "error" => "update data cannot be encoded as JSON (invalid UTF-8, NAN/INF or unsupported value)");
        }
        return null;
    }

    /**
     * Set fields of records of one segment as read ([key => record]): update() and the query builder's update().
     * An empty set changes nothing and counts the records. Caller holds the exclusive lock.
     * @return int
     */
    public function updateRecords($dbname, $segment, array $records, array $set){
        if(empty($set)){
            return count($records);
        }
        foreach($records as $key => $record){
            foreach($set as $field => $value){
                $record[$field] = $value;
            }
            $records[$key] = $record;
        }
        return $this->writer->updateSegmentRecords($dbname, $segment, $records);
    }
}
}
namespace noneDB\Internal\Core {

use function array_map, array_slice, bin2hex, count, file_get_contents, filesize, floatval, implode, is_array,
    is_bool, is_int, is_link, is_string, max, microtime, min, pow, preg_match, random_bytes, round, scandir, sort,
    str_replace, strval;
use const COUNT_RECURSIVE, SORT_STRING;

/**
 * @internal
 */
final class DatabaseOps {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $autoCreateDB;
    private $dbDir;
    private $indexCache;
    private $verifiedIndexes;
    private $buffer;
    private $cache;
    private $checkpoints;
    private $layout;
    private $locks;
    private $scope;
    private $segments;
    private $shardMeta;
    private $store;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->cache, $this->checkpoints, $this->layout, $this->locks, $this->scope, $this->segments, $this->shardMeta, $this->store);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->autoCreateDB = &$this->k->autoCreateDB;
        $this->dbDir = &$this->k->dbDir;
        $this->indexCache = &$this->k->indexCache;
        $this->verifiedIndexes = &$this->k->verifiedIndexes;
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
        $this->layout = null;
        $this->locks = null;
        $this->scope = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->store = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // PUBLIC INDEX API
    // ==========================================

    /**
     * Make the key indexes (.kix checkpoints) of a database again from its data files, then its field and
     * spatial indexes where they changed.
     * Normal operation never needs this; it is a recovery tool after files were copied or restored by hand.
     * @param string $dbname
     * @return array ['success' => bool, 'totalRecords' => int, 'time' => string]
     */
    public function rebuildIndex($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            return $this->rebuildIndexUnlocked($dbname);
        }, 'rebuildIndex');
    }

    private function rebuildIndexUnlocked($dbname){
        $start = microtime(true);
        $this->buffer->flushPendingBuffers($dbname);
        $segments = $this->segments->listSegments($dbname);
        if(empty($segments)){
            return ['success' => false, 'error' => 'Database does not exist'];
        }
        $total = 0;
        foreach($segments as $segment){
            $total += \noneDB\Internal\Storage\KeyIndex::count($this->checkpoints->rebuildSegmentIndexFromData($dbname, $segment));
            $this->cache->evictBetweenSegments();
        }
        if($this->segments->isSharded($dbname)){
            $this->shardMeta->recountMeta($dbname);
        }
        // After a manual restore the field and spatial indexes are as stale as the key index
        $this->checkpoints->rebuildSecondaryIndexes($dbname, $segments);
        return [
            'success' => true,
            'totalRecords' => $total,
            'time' => round((microtime(true) - $start) * 1000, 2) . 'ms'
        ];
    }

    /**
     * Get index information for a database
     * @param string $dbname
     * @return array|null
     */
    public function getIndexInfo($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname){
            return $this->getIndexInfoUnlocked($dbname);
        });
    }

    private function getIndexInfoUnlocked($dbname){
        $segments = $this->segments->listSegments($dbname);
        if(empty($segments)){
            return null;
        }
        $total = 0;
        $created = null;
        $updated = null;
        foreach($segments as $segment){
            $index = $this->checkpoints->readJsonlIndex($dbname, $segment);
            if($index === null){
                continue;
            }
            $total += \noneDB\Internal\Storage\KeyIndex::count($index);
            $created = $created === null ? ($index['created'] ?? null) : min($created, $index['created'] ?? $created);
            $updated = max((int)$updated, (int)($index['updated'] ?? 0));
            unset($index);
            $this->cache->evictBetweenSegments();
        }
        $sharded = $this->segments->isSharded($dbname);
        return [
            'exists' => true,
            'version' => 1, // of the key-index checkpoint (docs/STORAGE_FORMAT.md §6.4)
            'created' => $created,
            'updated' => $updated,
            'totalRecords' => $total,
            'sharded' => $sharded,
            'path' => $sharded ? $this->shardMeta->getMetaPath($dbname) : $this->checkpoints->getJsonlIndexPath($dbname, null)
        ];
    }

    /**
     * True when the database exists. With autoCreateDB a missing database is created; without it nothing is
     * created and the answer is false.
     * @param string $dbname
     * @return bool
     * @throws \InvalidArgumentException for names with characters outside [A-Za-z0-9' -]
     */
    public function checkDB($dbname=null){
        if($dbname === null || $dbname === '' || $dbname === false){
            return false;
        }
        $dbname = $this->layout->normalizeDbName($dbname);
        if(!$this->autoCreateDB){
            // Only a check: a database that doesn't exist creates nothing (no directory, no lock file)
            return $this->locks->withReadLock($dbname, function() use ($dbname){
                return $this->segments->dbExists($dbname);
            });
        }
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            return $this->checkDBUnlocked($dbname);
        }, 'checkDB');
    }

    private function checkDBUnlocked($dbname){
        if($this->segments->dbExists($dbname)){
            return true;
        }
        if($this->autoCreateDB){
            return $this->createDBUnlocked($dbname);
        }
        return false;
    }

    /**
     * Create a database (STORAGE_FORMAT.md §8.1)
     * @param string $dbname
     * @return bool True if created, false if it already existed
     */
    public function createDB($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            return $this->createDBUnlocked($dbname);
        }, 'createDB');
    }

    /**
     * Create a database (STORAGE_FORMAT.md §8.1): generation 1 with an empty main segment and its first
     * checkpoint, then CURRENT, then FORMAT. Caller holds the database lock exclusively (and STORE.LOCK
     * shared: a database that doesn't exist is only locked through the missing-database path).
     */
    public function createDBUnlocked($dbname){
        if($this->segments->dbExists($dbname)){
            return false;
        }
        $this->layout->createDatabaseFiles($dbname);
        $dataPath = $this->segments->getDataPath($dbname);
        $this->cache->markFileExists($dataPath);
        $index = $this->checkpoints->newJsonlIndex();
        $index['e'] = bin2hex(random_bytes(4));
        $this->indexCache[\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath)] = $index;
        $this->verifiedIndexes[\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath)] = true;
        $this->checkpoints->writeCheckpoint($dbname, null, $index);
        return true;
    }

    /**
     * Delete a database (STORAGE_FORMAT.md §8.2): every file but its lock file
     * @param string $dbname
     * @return array ['success' => bool, 'files' => int deleted file count]
     */
    public function dropDB($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        // Reads of a missing database create nothing: only an existing one is locked exclusively
        if(!$this->locks->withLock($dbname, false, function() use ($dbname){ return $this->segments->dbExists($dbname); })){
            return ['success' => false, 'files' => 0, 'error' => 'Database does not exist'];
        }
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            if(!$this->segments->dbExists($dbname)){
                return ['success' => false, 'files' => 0, 'error' => 'Database does not exist'];
            }
            $this->scope->refuseInUncommittedWrite($dbname, 'dropDB');
            $drop = $this->layout->dropDatabaseFiles($dbname, $this->locks->getLockPath($dbname), true);
            if(!$drop['committed']){
                return ['success' => false, 'files' => 0, 'error' => "Database '{$dbname}' could not be dropped: its files can't be deleted ("
                    . implode(', ', $drop['left']) . ')'];
            }
            return ['success' => true, 'files' => $drop['removed']];
        }, 'dropDB');
    }

    /**
     * Name, creation time and total size of a database (all shards included)
     * @return array|false
     */
    private function getDBInfo($dbname){
        return $this->locks->withLock($dbname, false, function() use ($dbname){
            $format = $this->layout->readFormatRecord($dbname);
            if($format === null){
                return false;
            }
            $size = 0;
            foreach($this->segments->listFilesWithPrefix($this->layout->genDir($dbname) . 'segments/', '/\.jsonl$/') as $file){
                $size += (int)@filesize($file);
            }
            $info = array("name" => $dbname, "createdTime" => $format['createdAt'], "size" => $this->fileSizeConvert($size));
            // Shard files found without a shards.json entry are kept aside (see setOrphanShardsAside)
            $orphans = array_map('basename', $this->segments->listFilesWithPrefix($this->layout->genDir($dbname) . 'segments/', '/\.orphan\.\d+$/'));
            if(!empty($orphans)){
                $info['orphanFiles'] = $orphans;
            }
            return $info;
        });
    }
    /**
     * Convert bytes to human readable format
     * @param float $bytes
     * @return string
     */
    private function fileSizeConvert($bytes){
        $bytes = floatval($bytes);
        $arBytes = array(
            0 => array("UNIT" => "TB", "VALUE" => pow(1024, 4)),
            1 => array("UNIT" => "GB", "VALUE" => pow(1024, 3)),
            2 => array("UNIT" => "MB", "VALUE" => pow(1024, 2)),
            3 => array("UNIT" => "KB", "VALUE" => 1024),
            4 => array("UNIT" => "B", "VALUE" => 1),
        );
        $result = "0 B";
        foreach($arBytes as $arItem){
            if($bytes >= $arItem["VALUE"]){
                $result = $bytes / $arItem["VALUE"];
                $result = str_replace(".", "," , strval(round($result, 2)))." ".$arItem["UNIT"];
                break;
            }
        }
        return $result;
    }

    /**
     * The databases of this key: names (false), names with information (true), or one database's information
     * (its name; false when it doesn't exist)
     */
    public function getDBs($info=false){
        // Handle three cases: false (names only), true (with metadata), string (specific db)
        $withMetadata = false;
        $specificDb = null;

        if(is_bool($info)){
            $withMetadata = $info;
        }else{
            $specificDb = $this->layout->normalizeDbName($info); // reading information never creates the database
        }

        // If specific database requested
        if($specificDb !== null){
            return $this->getDBInfo($specificDb);
        }

        // List all databases of this key: {locator}.ndb4 directories whose FORMAT names a database that
        // maps to that locator with this key
        $dbs = [];
        if(!$this->store->openStore(false)){
            return $dbs;
        }
        $names = [];
        foreach(@scandir($this->dbDir) ?: [] as $entry){
            if(preg_match('/^([0-9a-f]{32})\.ndb4$/', $entry, $m) !== 1 || is_link($this->dbDir . $entry)){
                continue; // a link is no database (STORAGE_FORMAT.md §3.4)
            }
            $format = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbDir . $entry . '/FORMAT'), 'database');
            $dbname = is_array($format) && is_string($format['name'] ?? null) ? $format['name'] : null;
            if($dbname === null || preg_match("/^[A-Za-z0-9' -]+$/", $dbname) !== 1 || $this->store->locator($dbname) !== $m[1]){
                continue;
            }
            $names[] = $dbname;
        }
        sort($names, SORT_STRING);
        foreach($names as $dbname){
            if($withMetadata){
                $info = $this->getDBInfo($dbname);
                if($info !== false){
                    $dbs[] = $info;
                }
            }else{
                $dbs[] = $dbname;
            }
        }
        return $dbs;
    }

    /**
     * The first $limit records of a list of records (a result of find())
     * @param array $array
     * @param int $limit At least 1
     * @return array|false False for invalid arguments
     */
    public function limit($array, $limit=0){
        if(!is_array($array) || !is_int($limit) || $limit <= 0){
            return false;
        }
        if(empty($array)){
            return [];
        }
        // Must be a list of records
        if(count($array) === count($array, COUNT_RECURSIVE)) {
            return false;
        }
        return array_slice($array, 0, $limit);
    }
}
}
namespace noneDB\Internal\Diagnostics {

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
}
namespace noneDB\Internal\Diagnostics {

use function array_sum, array_values, count, filesize, in_array, is_dir, is_file, is_link, memory_get_peak_usage,
    memory_get_usage, preg_match, scandir, strlen, strncmp, substr;

/**
 * @internal
 */
final class Metrics {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $integrityChecks;
    private $lastIntegrity;
    private $lockRegistry;
    private $metrics;
    private $noWrites;
    private $buffer;
    private $fieldIndex;
    private $layout;
    private $locks;
    private $shardInfo;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->fieldIndex, $this->layout, $this->locks, $this->shardInfo, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->integrityChecks = &$this->rt->integrityChecks;
        $this->lastIntegrity = &$this->rt->lastIntegrity;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->metrics = &$this->rt->metrics;
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
        $this->fieldIndex = null;
        $this->layout = null;
        $this->locks = null;
        $this->shardInfo = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }

    /**
     * ['process' => ...] and, for a database, ['database' => ...]: nothing is scanned or written
     */
    public function getMetrics($dbname){
        $metrics = ['process' => $this->processMetrics()];
        if($dbname !== null){
            $metrics['database'] = $this->databaseMetrics($this->layout->normalizeDbName($dbname));
        }
        return $metrics;
    }

    private function processMetrics(){
        $m = $this->metrics;
        $durability = $this->sync->getDurability();
        return [
            'indexCache' => ['hits' => $m['hits'], 'misses' => $m['misses'], 'evictions' => $m['evictions'],
                'limit' => \noneDB\Internal\Cache\CacheManager::cacheLimitBytes()],
            'indexReads' => ['pages' => $m['pages'], 'bytes' => $m['bytes']],
            'checkpoints' => ['written' => $m['checkpoints'], 'seconds' => $m['checkpointSeconds']],
            'compactions' => ['count' => $m['compactions'], 'seconds' => $m['compactionSeconds']],
            'locks' => ['acquired' => $m['locks'], 'waited' => $m['lockWaits'], 'waitSeconds' => $m['lockWaitSeconds'], 'timeouts' => $m['lockTimeouts']],
            'recovery' => ['events' => $m['recoveryEvents'], 'actions' => $m['recoveryActions']],
            'durability' => ['mode' => $durability['mode'], 'lastOperation' => $durability['lastOperation'],
                'lastSyncError' => $durability['lastSyncError'], 'syncs' => $durability['syncs']],
            'integrity' => $this->lastIntegrity,
            'memory' => ['usage' => memory_get_usage(), 'peak' => memory_get_peak_usage()],
        ];
    }

    /**
     * A database from its metadata, under its shared lock, writing nothing (as checkIntegrity() reads)
     */
    private function databaseMetrics($dbname){
        $this->noWrites++;
        try {
            return $this->locks->withLock($dbname, false, function() use ($dbname){
                if(!$this->layout->dbPresent($dbname)){
                    return ['name' => $dbname, 'exists' => false]; // a missing database is answered without a lock
                }
                $info = $this->shardInfo->getShardInfoUnlocked($dbname);
                $flags = $this->lockRegistry[$this->locks->getLockPath($dbname)]['flags'];
                $buffered = $this->buffer->getBufferRecordCount($this->buffer->getBufferPath($dbname));
                foreach($this->buffer->flushingFiles($dbname) as $file){
                    $buffered += count($this->buffer->readBufferRecords($file));
                }
                $manifest = $this->fieldIndex->getIndexManifest($dbname);
                $databaseId = $this->layout->readFormatRecord($dbname)['databaseId'];
                return [
                    'name' => $dbname,
                    'exists' => true,
                    'databaseId' => $databaseId,
                    'generation' => $this->layout->generationNumber($dbname),
                    'layout' => $info['sharded'] ? 'sharded' : 'single',
                    'shardSize' => $info['shardSize'],
                    'segments' => $info['sharded'] ? $info['shards'] : 1,
                    'records' => $info['totalRecords'],
                    'nextKey' => $info['nextKey'],
                    'bufferedRecords' => $buffered,
                    'fieldIndexes' => array_values($manifest['fields']),
                    'spatialIndexes' => array_values($manifest['spatial']),
                    'bytes' => $this->databaseBytes($dbname),
                    'state' => ['buffered' => !empty($flags['b']),
                        'recoveryPending' => !empty($flags['j']) || !empty($flags['c']) || !empty($flags['d']) || !empty($flags['g'])],
                    'integrity' => $this->integrityChecks[$databaseId] ?? null,
                ];
            });
        } finally {
            $this->noWrites--;
        }
    }

    /**
     * Sizes of the files of a database directory by their class (docs/OPERATIONS.md §1.2)
     */
    private function databaseBytes($dbname){
        $home = $this->layout->dbHome($dbname);
        $active = 'generations/' . $this->layout->generationNumber($dbname) . '/';
        $bytes = ['data' => 0, 'indexes' => 0, 'buffer' => 0, 'metadata' => 0, 'recovery' => 0, 'other' => 0];
        $walk = function($dir, $prefix) use (&$walk, &$bytes, $active){
            foreach(@scandir($dir) ?: [] as $entry){
                if($entry === '.' || $entry === '..' || is_link($dir . $entry)){
                    continue;
                }
                if(is_dir($dir . $entry)){
                    $walk($dir . $entry . '/', $prefix . $entry . '/');
                    continue;
                }
                if(!is_file($dir . $entry)){
                    continue;
                }
                $file = $prefix . $entry;
                $inActive = strncmp($file, $active, strlen($active)) === 0;
                $relative = $inActive ? substr($file, strlen($active)) : null;
                if($inActive && preg_match('#^segments/[^/]+\.jsonl$#', $relative) === 1){
                    $class = 'data';
                } elseif($inActive && preg_match('#^(?:segments/[^/]+\.kix|indexes/[^/]+\.(?:fx|gfx)|spatial/[^/]+\.(?:rx|grx))$#', $relative) === 1){
                    $class = 'indexes';
                } elseif($inActive && strncmp($relative, 'buffer/', 7) === 0){
                    $class = 'buffer';
                } elseif(in_array($inActive ? $relative : $file, $inActive ? ['metadata', 'shards.json', 'indexes.json'] : ['FORMAT', 'CURRENT', 'LOCK'], true)){
                    $class = 'metadata';
                } elseif(strncmp($file, 'recovery/', 9) === 0){
                    $class = 'recovery';
                } else {
                    $class = 'other';
                }
                $bytes[$class] += (int)@filesize($dir . $entry);
            }
        };
        $walk($home, '');
        $walk = null; // the closure refers to itself: freed now, not by the garbage collector
        $bytes['total'] = array_sum($bytes);
        return $bytes;
    }
}
}
namespace noneDB\Internal\Durability {

use function array_reverse, basename, bin2hex, chmod, clearstatcache, dirname, fclose, fflush, file_exists, file_get_contents,
    file_put_contents, fopen, fseek, ftell, ftruncate, fwrite, ini_get, ini_set, is_dir, is_file, is_link, is_string,
    json_decode, json_encode, json_last_error, json_last_error_msg, mkdir, random_bytes, rmdir, rtrim, strlen,
    microtime, substr, touch, unlink;
use const JSON_ERROR_NONE, JSON_PRESERVE_ZERO_FRACTION, JSON_PRETTY_PRINT, JSON_THROW_ON_ERROR,
    JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, SEEK_END;

/**
 * @internal
 */
final class FileIO {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $appendMismatch;
    private $directoryPermissions;
    private $durable;
    private $exactFloats;
    private $filePermissions;
    private $metrics;
    private $checkpoints;
    private $locks;
    private $recovery;
    private $scope;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->checkpoints, $this->locks, $this->recovery, $this->scope, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->appendMismatch = &$this->k->appendMismatch;
        $this->directoryPermissions = &$this->k->directoryPermissions;
        $this->durable = &$this->k->durable;
        $this->filePermissions = &$this->k->filePermissions;
        $this->exactFloats = &$this->rt->exactFloats;
        $this->metrics = &$this->rt->metrics;
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
        $this->locks = null;
        $this->recovery = null;
        $this->scope = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // ATOMIC FILE OPERATIONS
    // ==========================================

    /**
     * A derived file's header line, its crc last
     * @return string|false
     */
    public function encodeHeader(array $header){
        $json = $this->encodeJson($header);
        return $json === false ? false : \noneDB\Internal\Storage\KeyIndex::sealHeader($json);
    }

    /**
     * Encode JSON, returning false instead of producing invalid output
     * @return string|false
     */
    public function encodeJson($data, $prettyPrint = false, $extraFlags = 0){
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $extraFlags;
        if($prettyPrint){
            $flags |= JSON_PRETTY_PRINT;
        }
        // json_encode() writes floats with serialize_precision digits: anything but -1 (the default,
        // shortest exact form) would store rounded values (0.30000000000000004 as 0.3 at 10).
        // Loops encoding many records run inside withExactFloats(): no ini_get()/finally per record.
        if($this->exactFloats || ini_get('serialize_precision') === '-1'){
            try {
                return json_encode($data, $flags | JSON_THROW_ON_ERROR);
            } catch(\JsonException $e){
                return false;
            }
        }
        return $this->withExactFloats(function() use ($data, $flags){
            try {
                return json_encode($data, $flags | JSON_THROW_ON_ERROR);
            } catch(\JsonException $e){
                return false;
            }
        });
    }

    /**
     * Run $fn with serialize_precision set to -1 (checked once instead of once per encoded value)
     */
    public function withExactFloats(callable $fn){
        if($this->exactFloats){
            return $fn();
        }
        $precision = ini_get('serialize_precision');
        if($precision !== '-1'){
            ini_set('serialize_precision', '-1');
        }
        $this->exactFloats = true;
        try {
            return $fn();
        } finally {
            $this->exactFloats = false;
            if($precision !== '-1'){
                ini_set('serialize_precision', (string)$precision);
            }
        }
    }

    /**
     * Encode one JSONL record line (without the trailing newline)
     * @throws \InvalidArgumentException when the record can't be represented as JSON (invalid UTF-8, NAN, INF, ...)
     */
    public function encodeRecord(array $record){
        // Keep floats floats: 2.0 / -0.0 are stored as 2.0 / -0.0, not as the ints 2 / 0
        $json = $this->encodeJson($record, false, JSON_PRESERVE_ZERO_FRACTION);
        if($json === false){
            throw new \InvalidArgumentException('noneDB: record cannot be encoded as JSON (invalid UTF-8, NAN/INF or unsupported value): ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Write a whole file atomically: temp file in the same directory + rename.
     * Readers see either the old or the new content, never a partial file.
     * @param string|iterable $content The bytes, or a sequence of chunks (a large derived file written as it is
     *                                 made); a chunk that can't be made throws, and the temp file is removed
     * @param string|bool $mode What the file is (docs/DURABILITY.md §3):
     *   'derived' (false): checkpoints and indexes, rebuilt from the data if lost: never synced
     *   'durable': records of the database (FORMAT, CURRENT, metadata, shards.json, indexes.json): synced in
     *              durable mode: temp file, rename (the commit point), directory
     *   'replace' (true): a data file replaced (compaction, the switch to shards): synced in both modes; a failed
     *              directory sync after the rename only counts in durable mode
     * @return bool False when the file could not be written (nothing changed)
     * @throws \RuntimeException DURABILITY_FAILED (a sync before the rename failed, nothing changed),
     *                           OUTCOME_UNKNOWN (the directory sync after the rename failed)
     */
    public function writeFileAtomic($path, $content, $mode = 'derived', $note = true){
        $started = microtime(true);
        $mode = $mode === true ? 'replace' : ($mode === false ? 'derived' : $mode);
        if($mode === 'replace'){
            $this->scope->refuseUncommittedFile($path, 'replacement');
        }
        if($note){
            $this->locks->noteWrite($path);
        }
        $required = $this->durable && $mode !== 'derived';
        $sync = $required || $mode === 'replace';
        $dir = dirname($path);
        if(!is_dir($dir) && !$this->makeDirectory($dir)){
            return false;
        }
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if(!$sync && is_string($content)){
            $written = @file_put_contents($tmp, $content);
            if($written === false || $written !== strlen($content)){
                @unlink($tmp);
                return false;
            }
            $this->setPermissions($tmp);
        } else {
            $fp = @fopen($tmp, 'xb');
            if($fp === false){
                return false;
            }
            try {
                $ok = true;
                foreach(is_string($content) ? [$content] : $content as $chunk){
                    if(!$this->writeAll($fp, $chunk, $path)){
                        $ok = false;
                        break;
                    }
                }
            } catch(\Throwable $e){
                fclose($fp);
                @unlink($tmp);
                throw $e;
            }
            $ok = $ok && fflush($fp);
            $synced = $ok && $sync ? $this->sync->syncHandle($fp, $path) : null;
            fclose($fp);
            if(!$ok){
                @unlink($tmp);
                return false;
            }
            if($required){
                $this->sync->noteRequired('file', $synced === true);
            }
            if($sync && $synced !== true){
                @unlink($tmp);
                throw $this->sync->failure('file', $path, false);
            }
            $this->setPermissions($tmp);
        }
        if(!$this->sync->renameWithRetry($tmp, $path)){
            @unlink($tmp);
            return false;
        }
        clearstatcache(true, $path);
        if($sync){
            $dirSynced = $this->sync->syncDirectory($dir) === true;
            if($required){
                $this->sync->noteRequired('dir', $dirSynced);
                if(!$dirSynced){
                    throw $this->sync->failure('dir', $dir, true);
                }
            }
        }
        if($mode === 'derived'){
            // A checkpoint or an index file (getMetrics())
            $this->metrics['checkpoints']++;
            $this->metrics['checkpointSeconds'] += microtime(true) - $started;
        }
        return true;
    }

    /**
     * Write a file derived from the data (a checkpoint) atomically without moving the generation: its
     * content is the same whoever writes it, so a reader may write it under the shared lock
     */
    public function writeDerivedFile($path, $content){
        return $this->writeFileAtomic($path, $content, 'derived', false);
    }

    /**
     * fwrite() every byte (a short write is continued; no progress is a failure)
     */
    private function writeAll($fp, $bytes, $path){
        for($done = 0, $length = strlen($bytes); $done < $length; $done += $n){
            $chunk = $done === 0 ? $bytes : substr($bytes, $done);
            $n = fwrite($fp, $chunk);
            if($n === false || $n <= 0){
                return false;
            }
        }
        return true;
    }

    /**
     * Create a directory and its missing parents. In durable mode each new directory's parent is synced,
     * so the new entries survive a power loss with what is written into them.
     * @throws \RuntimeException DURABILITY_FAILED
     */
    public function makeDirectory($dir){
        $dir = rtrim($dir, '/');
        $missing = [];
        for($at = $dir; $at !== '' && $at !== '.' && $at !== '/' && !is_dir($at); $at = dirname($at)){
            $missing[] = $at;
        }
        foreach(array_reverse($missing) as $new){
            if(!@mkdir($new, $this->directoryPermissions) && !is_dir($new)){
                return false;
            }
            $this->setPermissions($new, true);
            $this->sync->requireSync(null, dirname($new), 'dir');
        }
        return true;
    }

    /**
     * A file or directory noneDB created in dbDir gets the configured permissions, whatever the umask
     * (STORAGE_FORMAT.md §3)
     */
    public function setPermissions($path, $directory = false){
        @chmod($path, $directory ? $this->directoryPermissions : $this->filePermissions);
    }

    // File system writes: every write to a database file goes through writeFileAtomic(),
    // appendToFile() or the helpers below. Each calls noteWrite() first: the generation moves before the
    // file changes, so other processes drop their caches even if this one dies half-way, and a write
    // without the database's exclusive lock is a LogicException. SourceWriteGuardTest fails on a raw
    // write call anywhere else.

    public function fsUnlink($path){
        clearstatcache(true, $path);
        if(!file_exists($path) && !is_link($path)){
            return false; // nothing to remove: no write
        }
        $this->scope->refuseUncommittedFile($path, 'removal');
        $this->locks->noteWrite($path);
        $ok = @unlink($path);
        clearstatcache(true, $path);
        return $ok;
    }

    public function fsRename($from, $to){
        $this->scope->refuseUncommittedFile($from, 'rename');
        $this->scope->refuseUncommittedFile($to, 'replacement');
        $this->locks->noteWrite($from);
        $this->locks->noteWrite($to);
        $ok = $this->sync->renameWithRetry($from, $to);
        clearstatcache(true, $from);
        clearstatcache(true, $to);
        return $ok;
    }

    public function fsTouch($path){
        $this->locks->noteWrite($path);
        $created = !file_exists($path);
        $ok = @touch($path);
        if($ok && $created){
            $this->setPermissions($path);
        }
        clearstatcache(true, $path);
        return $ok;
    }

    /** Create a directory of a database (and its parents; synced in durable mode) */
    public function fsMkdir($path){
        $path = rtrim($path, '/');
        clearstatcache(true, $path);
        if(is_dir($path)){
            return true;
        }
        $this->locks->noteWrite($path . '/');
        return $this->makeDirectory($path);
    }

    /** Remove an empty directory of a database */
    public function fsRmdir($path){
        $path = rtrim($path, '/');
        clearstatcache(true, $path);
        if(!is_dir($path)){
            return false;
        }
        $this->locks->noteWrite($path . '/');
        return @rmdir($path);
    }

    /** Cut a file to $size bytes */
    public function fsTruncate($path, $size){
        $this->locks->noteWrite($path);
        $fp = @fopen($path, 'r+b');
        if($fp === false){
            return false;
        }
        $ok = ftruncate($fp, $size);
        fclose($fp);
        clearstatcache(true, $path);
        return $ok;
    }

    /**
     * Append bytes to a file. Returns the offset the bytes start at, or false (nothing written).
     * The append commits when its bytes are written and flushed and, in durable mode, the file is synced
     * (and its directory when the append created it: an empty file before). A failure before that cuts the
     * bytes back, so the file never ends in a torn line (docs/DURABILITY.md §3, §4).
     * @param string|null $dbname Database whose rollback scope records the append (data and buffer files)
     * @param int|null $expected Size the caller's index says the file has: anything else writes nothing
     *                           and sets $this->appendMismatch
     * @return int|false
     * @throws \RuntimeException DURABILITY_FAILED (a required sync failed, the bytes were cut back),
     *                           OUTCOME_UNKNOWN (the bytes could not be cut back)
     */
    public function appendToFile($path, $bytes, $dbname = null, $expected = null, $segment = null){
        $this->locks->noteWrite($path);
        $this->appendMismatch = false;
        $fp = @fopen($path, 'c+b');
        if($fp === false){
            // The directory of a file's first append (buffer/) is created then: no check on every append
            $dir = dirname($path);
            if(is_dir($dir) || !$this->makeDirectory($dir) || ($fp = @fopen($path, 'c+b')) === false){
                return false;
            }
        }
        try {
            if(fseek($fp, 0, SEEK_END) !== 0){
                return false;
            }
            $offset = ftell($fp);
            if($offset === false){
                return false;
            }
            if($offset === 0){
                $this->setPermissions($path); // created by this append (or empty)
            }
            if($expected !== null && $offset !== (int)$expected){
                // The file changed behind the cached index. An unterminated tail only (an append that
                // never completed): quarantined and cut, then this append goes where the index expects it.
                // Anything else: nothing is written, the caller drops its index (rebuilt on next access).
                if($dbname === null || $offset < (int)$expected || $this->checkpoints->lastNewlineIn($path, (int)$expected, $offset) >= 0){
                    $this->appendMismatch = true;
                    return false;
                }
                $this->recovery->quarantineAndCut($dbname, $segment, $path, (int)$expected, $offset);
                fseek($fp, 0, SEEK_END);
                $offset = ftell($fp);
                if($offset !== (int)$expected){
                    $this->appendMismatch = true;
                    return false;
                }
            }
            if($dbname !== null){
                $this->scope->markScopeFile($dbname, $path, $offset, $fp);
            }
            // Written bytes the stream couldn't hand to the OS (disk full, I/O error): the append failed
            if(!$this->writeAll($fp, $bytes, $path) || !fflush($fp)){
                $this->undoAppend($fp, $offset, $path, null);
                return false;
            }
            if($dbname !== null){
                if(!$this->sync->trySync($fp, $path)){
                    $this->undoAppend($fp, $offset, $path, $this->sync->failure('file', $path, false));
                }
                if($offset === 0 && !$this->sync->trySync(null, dirname($path), 'dir')){
                    $this->undoAppend($fp, $offset, $path, $this->sync->failure('dir', dirname($path), false));
                }
            }
            return $offset;
        } finally {
            fclose($fp);
            clearstatcache(true, $path);
        }
    }

    /**
     * Cut a failed append back (synced in durable mode), then throw $cause if there is one. A cut that
     * fails leaves the bytes possibly there: OUTCOME_UNKNOWN, so the write's rollback scope tries again
     * and the next session recovers; never a plain failure that would leave them to be read as a record.
     * @throws \RuntimeException
     */
    private function undoAppend($fp, $offset, $path, ?\RuntimeException $cause){
        try {
            $ok = ftruncate($fp, $offset) && $this->sync->syncUndo($fp, $path);
        } catch(\Throwable $e){
            $ok = false;
        }
        if(!$ok){
            throw new \RuntimeException("noneDB: an append to " . basename($path) . " failed and could not be cut back: the outcome is unknown, read the state again", \noneDB::OUTCOME_UNKNOWN, $cause);
        }
        if($cause !== null){
            throw $cause;
        }
    }

    /**
     * Read and decode a JSON file
     *
     * @param string $path File path
     * @param mixed $default Value returned when the file doesn't exist or is empty
     * @return mixed Decoded data, $default, or null when the file is corrupt
     */
    public function atomicRead($path, $default = null){
        clearstatcache(true, $path);
        if(!is_file($path)){
            return $default;
        }
        $content = @file_get_contents($path);
        if($content === false || $content === ''){
            return $default;
        }
        $data = json_decode($content, true);
        if($data === null && json_last_error() !== JSON_ERROR_NONE){
            return null; // corrupted
        }
        return $data !== null ? $data : $default;
    }

    /**
     * Encode and write a JSON file atomically. Nothing is touched when encoding fails.
     *
     * @param string $path File path
     * @param mixed $data Data to write (will be JSON encoded)
     * @param bool $prettyPrint Use JSON_PRETTY_PRINT
     * @return bool Success
     */
    public function atomicWrite($path, $data, $prettyPrint = false, $mode = 'derived'){
        $json = $this->encodeJson($data, $prettyPrint);
        if($json === false){
            return false;
        }
        return $this->writeFileAtomic($path, $json, $mode);
    }
}
}
namespace noneDB\Internal\Durability {

use function basename, clearstatcache, fclose,
    file_exists, fopen, fsync, is_array, microtime, rename, rtrim, stream_get_meta_data, strlen, strncmp, substr,
    time, usleep;
use const LOCK_EX, PHP_OS_FAMILY;

/**
 * @internal
 */
final class Sync {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $dbDir;
    private $durable;
    private $lockTimeout;
    private $lastOperation;
    private $lastSyncError;
    private $lockRegistry;
    private $syncStats;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dbDir = &$this->k->dbDir;
        $this->durable = &$this->k->durable;
        $this->lockTimeout = &$this->k->lockTimeout;
        $this->lastOperation = &$this->rt->lastOperation;
        $this->lastSyncError = &$this->rt->lastSyncError;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->syncStats = &$this->rt->syncStats;
    }

    /**
     * Drop the handles to the kernel and the other services (the instance is gone)
     */
    public function unbind(): void {
        $this->k = null;
        $this->rt = null;
    }


    /**
     * fsync() a file handle where the stream supports it (plain files). Never emits a warning: the result
     * goes to the durability report.
     * @return bool|null Null when it can't be done here
     */
    public function syncHandle($fp, $path = null){
        $meta = @stream_get_meta_data($fp);
        if(!is_array($meta) || ($meta['wrapper_type'] ?? '') !== 'plainfile'){
            return null;
        }
        $ok = @fsync($fp);
        $this->countSync('file', $ok, $path);
        return $ok;
    }

    /**
     * fsync() a directory so a new, renamed or deleted entry in it survives a power loss (not possible on
     * Windows)
     * @return bool|null Null when it can't be done here
     */
    public function syncDirectory($dir){
        if(PHP_OS_FAMILY === 'Windows'){
            return null;
        }
        $dir = rtrim($dir, '/\\');
        $fp = @fopen($dir . '/', 'r');
        if($fp === false){
            $this->countSync('dir', false, $dir);
            return false;
        }
        try {
            $ok = @fsync($fp);
            $this->countSync('dir', $ok, $dir);
            return $ok;
        } finally {
            fclose($fp);
        }
    }

    private function countSync($kind, $ok, $path){
        $this->syncStats[$kind]++;
        if(!$ok){
            $this->syncStats['failed']++;
            $this->lastSyncError = ['path' => $path === null ? null : $this->relative((string)$path), 'op' => $kind, 'time' => time()];
        }
    }

    /**
     * A path relative to dbDir (the report and messages never show where dbDir is)
     */
    private function relative($path){
        $dir = (string)$this->dbDir;
        if($path === rtrim($dir, '/')){
            return '.';
        }
        return strncmp($path, $dir, strlen($dir)) === 0 ? substr($path, strlen($dir)) : basename($path);
    }

    /**
     * A sync durable mode requires (DURABILITY.md §3), done in durable mode only. A failure throws:
     * DURABILITY_FAILED before the write's commit point, OUTCOME_UNKNOWN after it.
     * @throws \RuntimeException
     */
    public function requireSync($fp, $path, $kind = 'file', $afterCommit = false){
        if($this->durable && !$this->trySync($fp, $path, $kind)){
            throw $this->failure($kind, $path, $afterCommit);
        }
    }

    /**
     * A sync durable mode requires, for a caller that undoes its change before it throws. Performance mode:
     * true, nothing synced.
     */
    public function trySync($fp, $path, $kind = 'file'): bool {
        if(!$this->durable){
            return true;
        }
        $ok = ($kind === 'dir' ? $this->syncDirectory($path) : $this->syncHandle($fp, $path)) === true;
        $this->noteRequired($kind, $ok);
        return $ok;
    }

    /**
     * Record a sync durable mode required for the running write operation and whether it succeeded
     * (getDurability()['lastOperation'])
     */
    public function noteRequired($kind, bool $ok){
        foreach($this->lockRegistry as $lock => $entry){
            if($entry['mode'] === LOCK_EX && isset($entry['syncs'])){
                $this->lockRegistry[$lock]['syncs'][$kind] = ($entry['syncs'][$kind] ?? true) && $ok;
            }
        }
    }

    /**
     * Sync the undo of a change in durable mode (a cut back append): true when it is durable or nothing
     * needs to be (performance mode)
     */
    public function syncUndo($fp, $path): bool {
        return !$this->durable || $this->syncHandle($fp, $path) === true;
    }

    /**
     * The exception of a required sync that failed
     */
    public function failure($kind, $path, $afterCommit, ?\Throwable $previous = null): \RuntimeException {
        $what = ($kind === 'dir' ? 'the directory ' : '') . $this->relative((string)$path);
        if($afterCommit){
            return new \RuntimeException("noneDB: fsync of {$what} failed after the commit point: the outcome is unknown, read the state again (docs/DURABILITY.md §2)", \noneDB::OUTCOME_UNKNOWN, $previous);
        }
        return new \RuntimeException("noneDB: fsync of {$what} failed; the write was rolled back (docs/DURABILITY.md §2)", \noneDB::DURABILITY_FAILED, $previous);
    }

    /**
     * rename() that retries where it can fail for a moment: on Windows while another process has the target
     * open (5, 10, 20, 40, 80, 160 ms, within lockTimeout). The target is never deleted first.
     */
    public function renameWithRetry($from, $to){
        $retry = PHP_OS_FAMILY === 'Windows';
        $started = microtime(true);
        foreach([0, 5, 10, 20, 40, 80, 160] as $i => $delay){
            if($delay > 0){
                if(!$retry || ($this->lockTimeout > 0 && microtime(true) - $started + $delay / 1000 > $this->lockTimeout)){
                    return false;
                }
                usleep($delay * 1000);
                clearstatcache(true, $from);
                if(!file_exists($from)){
                    return false;
                }
            }
            if(@rename($from, $to)){
                return true;
            }
        }
        return false;
    }

    /**
     * Record how a write operation ended (getDurability()['lastOperation'])
     * @param array $syncs kind => true (every required sync of that kind succeeded) | false
     */
    public function finishOperation($op, $dbname, array $syncs, $outcome, $reason = null){
        $required = [];
        $completed = [];
        if($syncs){ // durable mode only: every write of performance mode ends here with none
            foreach(['file', 'dir'] as $kind){
                if(isset($syncs[$kind])){
                    $required[] = $kind;
                    if($syncs[$kind]){
                        $completed[] = $kind;
                    }
                }
            }
        }
        $this->lastOperation = ['op' => $op, 'db' => (string)$dbname, 'outcome' => $outcome,
            'required' => $required, 'completed' => $completed, 'reason' => $reason];
    }

    /**
     * Durability of this instance and of the last write operation of the process (docs/DURABILITY.md §6)
     * @return array
     */
    public function getDurability(): array {
        $os = \noneDB\Internal\Config\Configuration::osFamily();
        $note = \noneDB\Internal\Config\Configuration::platformNote($os);
        return [
            'mode' => $this->durable ? 'durable' : 'performance',
            'platform' => ['os' => $os, 'durableSupported' => \noneDB\Internal\Config\Configuration::durableSupported(),
                'notes' => $note === '' ? [] : ['durable mode is not supported here: ' . $note]],
            'lastOperation' => $this->lastOperation,
            'lastSyncError' => $this->lastSyncError,
            'syncs' => $this->syncStats,
        ];
    }
}
}
namespace noneDB\Internal\Index {

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
            $this->cacheGrew = true;
        }
        return $page;
    }

    /**
     * Every bucket of a paged .fx body: [bucket => key | [keys]]; null when the file is damaged
     */
    public function decodeFieldIndexBody(array $header, $body){
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
        $sapi = PHP_SAPI;
        $records = self::EXACT_MAP_RECORDS;
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
}
namespace noneDB\Internal\Index {

use function array_pop, array_values, count, crc32, intdiv, is_array, is_int, is_string, json_decode,
    json_encode, ord, pack, strcmp, strcspn, strlen, strpos, strrpos, substr, unpack;

use const JSON_UNESCAPED_LINE_TERMINATORS, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE;

/**
 * @internal
 */
final class PagedFile {
    /** Target size of a page, crc included */
    public const PAGE = 4096;

    /** Flags of the canonical JSON of .fx data pages (§6.5.1): only `"`, `\\` and control characters escaped */
    public const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS;

    /** A directory page: its entries, their table (u32 start of each, then the u16 count), the crc of all that */
    private static function seal(array $entries): string {
        $data = '';
        $starts = [];
        foreach($entries as $entry){
            $starts[] = strlen($data);
            $data .= $entry;
        }
        $data .= (empty($starts) ? '' : pack('V*', ...$starts)) . pack('v', count($starts));
        return $data . pack('V', crc32($data));
    }

    /** True when $entry still fits a directory page of $count entries and $bytes bytes (table and crc included) */
    private static function fits(int $bytes, int $count, string $entry): bool {
        return $bytes + strlen($entry) + 4 * ($count + 1) + 2 + 4 <= self::PAGE;
    }

    /**
     * Where the members of a data page starting at $at end in the encoded members $json: after the last one that
     * fits (braces and crc included), or after the first when it alone doesn't. A member ends before a `,"` that no
     * `:` follows: inside an id a quote is escaped, so `,":` is the end of an id that ends in a comma, and every id
     * starts with a type letter.
     */
    private static function pageEnd(string $json, int $at): int {
        $n = strlen($json);
        if($n - $at <= self::PAGE - 6){
            return $n;
        }
        for($end = strrpos($json, ',"', $at + self::PAGE - 6 - $n); $end !== false && $end > $at; $end = strrpos($json, ',"', $end - 1 - $n)){
            if($json[$end + 2] !== ':'){
                return $end;
            }
        }
        for($end = strpos($json, ',"', $at + 1); $end !== false; $end = strpos($json, ',"', $end + 1)){
            if($json[$end + 2] !== ':'){
                return $end;
            }
        }
        return $n;
    }

    /** The id of the member starting at $at in the encoded members $json: up to the first quote no backslash escapes */
    private static function idAt(string $json, int $at): string {
        $close = $at + 1;
        while($json[$close += strcspn($json, '"\\', $close)] === '\\'){
            $close += 2;
        }
        return json_decode(substr($json, $at, $close + 1 - $at));
    }

    /**
     * Build the body from a segment's buckets in bucket id order (id => key | [keys], ascending), sealed as data
     * pages, then the directory levels: [body, pg]. A member is `"id":key` for a bucket of one key, else `"id":[keys]`;
     * all of them are encoded at once and cut into pages, members not split, each page as full as it gets.
     */
    public static function build(array $buckets): array {
        $json = substr((string)json_encode($buckets, self::JSON), 1, -1);
        $body = '';
        $level = [];
        for($at = 0, $n = strlen($json); $at < $n; $at = $end + 1){
            $end = self::pageEnd($json, $at);
            $page = '{' . substr($json, $at, $end - $at) . '}';
            $page .= pack('V', crc32($page));
            $level[] = [self::idAt($json, $at), strlen($body), strlen($page)];
            $body .= $page;
        }
        if(empty($level)){
            $body = '{}' . pack('V', crc32('{}'));
            $level[] = ['', 0, strlen($body)];
        }
        $data = strlen($body);
        $pages = count($level);
        $levels = 0;
        while(count($level) > 1){
            $levels++;
            $next = [];
            $current = [];
            $bytes = 0;
            $first = '';
            foreach($level as [$id, $offset, $length]){
                $entry = pack('V', strlen($id)) . $id . pack('V2', $offset, $length);
                // At least two entries per directory page (long ids): each level has fewer pages
                if(count($current) >= 2 && !self::fits($bytes, count($current), $entry)){
                    $page = self::seal($current);
                    $next[] = [$first, strlen($body), strlen($page)];
                    $body .= $page;
                    $current = [];
                    $bytes = 0;
                }
                if(empty($current)){
                    $first = $id;
                }
                $current[] = $entry;
                $bytes += strlen($entry);
            }
            $page = self::seal($current);
            $next[] = [$first, strlen($body), strlen($page)];
            $body .= $page;
            $pages += count($next);
            $level = $next;
        }
        return [$body, ['root' => [$level[0][1], $level[0][2]], 'levels' => $levels, 'pages' => $pages, 'data' => $data]];
    }

    /** True when a header's `pg` can name a tree: a root of some bytes, no more levels than two entries a page allow */
    public static function validTree($pg): bool {
        return is_array($pg) && is_int($pg['root'][0] ?? null) && is_int($pg['root'][1] ?? null) && $pg['root'][1] > 0
            && is_int($pg['levels'] ?? null) && $pg['levels'] >= 0 && $pg['levels'] < 64;
    }

    /** A page without its crc (entries and table), or null when the crc doesn't match */
    public static function open($bytes): ?string {
        if(!is_string($bytes) || strlen($bytes) < 6){
            return null;
        }
        return crc32($bytes) === \noneDB\Internal\Storage\KeyIndex::CRC_RESIDUE ? substr($bytes, 0, -4) : null;
    }

    /** Where a page's table starts (= the bytes of its entries), or null when the count doesn't fit the page */
    private static function tableAt(string $page): ?int {
        $len = strlen($page);
        $at = $len - 2 - 4 * (ord($page[$len - 2]) | (ord($page[$len - 1]) << 8));
        return $at < 0 ? null : $at;
    }

    /** The child of a decoded directory page that can hold $id (the last whose first id is <= $id): [offset, length]; [] when none */
    public static function child(array $children, string $id): array {
        $lo = 0;
        $hi = count($children) - 1;
        $found = -1;
        while($lo <= $hi){
            $mid = ($lo + $hi) >> 1;
            if(strcmp($children[$mid][0], $id) <= 0){
                $found = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        return $found < 0 ? [] : [$children[$found][1], $children[$found][2]];
    }

    /** True when a page's table names exactly the starts of the entries a walk found ($starts) */
    private static function checkedStarts(string $page, array $starts, int $table): bool {
        if(count($starts) !== intdiv(strlen($page) - 2 - $table, 4)){
            return false;
        }
        return $starts === [] || array_values(unpack('V' . count($starts), $page, $table)) === $starts;
    }

    /** A directory page: [[first id, offset, length], ...]; null when its table doesn't name its entries */
    public static function directory(string $page): ?array {
        $table = self::tableAt($page);
        if($table === null){
            return null;
        }
        $children = [];
        $starts = [];
        for($o = 0; $o < $table;){
            $starts[] = $o;
            if($o + 12 > $table || $o + 12 + ($n = unpack('V', $page, $o)[1]) > $table){
                return null; // an entry that leaves the page: its crc fits, its content doesn't
            }
            $child = unpack('V2', $page, $o + 4 + $n);
            $children[] = [substr($page, $o + 4, $n), $child[1], $child[2]];
            $o += 12 + $n;
        }
        return self::checkedStarts($page, $starts, $table) ? $children : null;
    }

    /**
     * The keys of the member named $id in an .fx data page (its JSON object, without the crc), ascending;
     * [] when the page doesn't hold it; null when its value isn't a key or a list of keys. A byte search in
     * canonical JSON (§6.5.1 lookup step 3), for the name without its opening quote: a type letter stops a
     * byte search less often than a quote, which every name starts and ends with. A match preceded by `{"` or
     * `,"` is a member's name (inside a name a quote is escaped, a value holds none), and it names exactly $id.
     */
    public static function fxMember(string $page, string $id, int $start = 0, ?int $end = null): ?array {
        // The name as the page writes it (the last one kept: a query looks one value up in segment after segment)
        static $lastId = null, $lastTail = null;
        if($id !== $lastId){
            $name = json_encode($id, self::JSON);
            if($name === false){
                return []; // not a name any member can have
            }
            $lastId = $id;
            $lastTail = substr($name, 1) . ':';
        }
        $tail = $lastTail;
        // $start / $end: the page's bytes inside a whole body, copied: a search never runs past the page
        if($start > 0 || ($end !== null && $end < strlen($page))){
            $page = substr($page, $start, ($end ?? strlen($page)) - $start);
        }
        for($at = strpos($page, $tail, 2); $at !== false; $at = strpos($page, $tail, $at + 1)){
            if($page[$at - 1] === '"' && ($page[$at - 2] === ',' || $page[$at - 2] === '{')){
                return self::fxKeysAt($page, $at + strlen($tail));
            }
        }
        return [];
    }

    /** The keys of the .fx member whose value starts at $at; null when it isn't a key or a list of keys */
    private static function fxKeysAt(string $page, int $at): ?array {
        if(($page[$at] ?? '') === '['){
            $close = strpos($page, ']', $at);
            $length = $close === false ? 0 : $close + 1 - $at;
        } else {
            $length = strcspn($page, ',}', $at);
        }
        $keys = $length > 0 ? json_decode(substr($page, $at, $length), true) : null;
        if(is_int($keys)){
            return [$keys];
        }
        return is_array($keys) ? $keys : null;
    }

    /**
     * Add the members of an .fx data page (its JSON object, without the crc) to $map in the shape of a
     * loaded index: id => key (one key) or [keys] (ascending). False when the page isn't a JSON object in
     * canonical form (encoding it again doesn't give its bytes): its byte searches couldn't be trusted.
     */
    public static function fxMembersInto(string $page, array &$map): bool {
        if($page === '{}'){
            return true;
        }
        $members = json_decode($page, true);
        if(!is_array($members) || json_encode($members, self::JSON) !== $page){
            return false;
        }
        $map += $members;
        return true;
    }

    /**
     * Every data page of a body, in order, from its directory: [[offset, length], ...]; null when a page's
     * crc or table doesn't match or the pages don't cover the data bytes
     */
    public static function dataPages(string $body, array $pg): ?array {
        if(!self::validTree($pg)){
            return null;
        }
        // Depth first, children in order, with a stack (a recursive closure would hold the body in a cycle
        // until the garbage collector runs)
        $pages = [];
        $stack = [[$pg['root'][0], $pg['root'][1], $pg['levels']]];
        while(!empty($stack)){
            [$offset, $length, $level] = array_pop($stack);
            if($level === 0){
                $pages[] = [$offset, $length];
                continue;
            }
            $data = self::open(substr($body, $offset, $length));
            $children = $data === null ? null : self::directory($data);
            if($children === null){
                return null;
            }
            for($i = count($children) - 1; $i >= 0; $i--){
                $stack[] = [$children[$i][1], $children[$i][2], $level - 1];
            }
        }
        $expected = 0;
        foreach($pages as [$offset, $length]){
            if($offset !== $expected){
                return null;
            }
            $expected += $length;
        }
        return $expected === ($pg['data'] ?? -1) ? $pages : null;
    }
}
}
namespace noneDB\Internal\Index {

use function array_diff, array_key_exists, array_values, count, in_array, is_int, is_string, sort;

/**
 * @internal
 */
final class FieldIndexApi {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $autoCreateDB;
    private $buffer;
    private $cache;
    private $db;
    private $fieldIndex;
    private $layout;
    private $locks;
    private $records;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->cache, $this->db, $this->fieldIndex, $this->layout, $this->locks, $this->records, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->autoCreateDB = &$this->k->autoCreateDB;
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
        $this->db = null;
        $this->fieldIndex = null;
        $this->layout = null;
        $this->locks = null;
        $this->records = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // FIELD INDEX PUBLIC API
    // ==========================================

    /**
     * Create an index on a field for faster equality lookups (find / where with field => value)
     * @param string $dbname Database name
     * @param string $field Field name (any name except "key")
     * @return array ['success' => bool, 'indexed' => int, 'error' => ?string] (+ 'shards' => int when sharded)
     */
    public function createFieldIndex($dbname, $field){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname, $field){
            return $this->createFieldIndexUnlocked($dbname, $field);
        }, 'createFieldIndex');
    }

    private function createFieldIndexUnlocked($dbname, $field){
        if(!is_string($field) || $field === '' || $field === 'key'){
            return ['success' => false, 'indexed' => 0, 'error' => 'Invalid field name'];
        }
        if($this->segments->dbExists($dbname) && $this->fieldIndex->isFieldIndexed($dbname, $field)){
            $this->dropFieldIndexUnlocked($dbname, $field); // creating it again rebuilds it
        }
        $this->buffer->flushPendingBuffers($dbname);
        if(!$this->segments->dbExists($dbname)){
            if(!$this->autoCreateDB){
                return ['success' => false, 'indexed' => 0, 'error' => 'Database does not exist'];
            }
            $this->db->createDBUnlocked($dbname);
        }

        $indexed = 0;
        $segments = $this->segments->listSegments($dbname);
        foreach($segments as $segment){
            $this->fieldIndex->deleteFieldIndexFile($dbname, $field, $segment); // leftovers of an earlier index of this field
            $index = $this->fieldIndex->buildSegmentFieldIndex($dbname, $segment, $field);
            $this->fieldIndex->writeFieldIndex($dbname, $field, $index, $segment);
            foreach($index['b'] as $keys){
                $indexed += is_int($keys) ? 1 : count($keys);
            }
            unset($index);
            $this->cache->evictBetweenSegments();
        }
        if($this->segments->isSharded($dbname)){
            $this->fieldIndex->deleteGlobalFieldIndex($dbname, $field);
            $this->fieldIndex->rebuildGlobalFieldIndex($dbname, $field);
        }

        $manifest = $this->fieldIndex->getIndexManifest($dbname);
        $manifest['fields'][] = $field;
        $this->fieldIndex->saveIndexManifest($dbname, $manifest);

        $result = ['success' => true, 'indexed' => $indexed, 'error' => null];
        if($this->segments->isSharded($dbname)){
            $result['shards'] = count($segments);
        }
        return $result;
    }

    /**
     * Drop a field index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function dropFieldIndex($dbname, $field){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname, $field){
            return $this->dropFieldIndexUnlocked($dbname, $field);
        }, 'dropFieldIndex');
    }

    private function dropFieldIndexUnlocked($dbname, $field){
        $manifest = $this->fieldIndex->getIndexManifest($dbname);
        if(!in_array((string)$field, $manifest['fields'], true)){
            return ['success' => false, 'error' => 'Index does not exist'];
        }
        $segments = $this->segments->listSegments($dbname);
        foreach($segments as $segment){
            $this->fieldIndex->deleteFieldIndexFile($dbname, $field, $segment);
        }
        $this->fieldIndex->deleteGlobalFieldIndex($dbname, $field);
        $manifest['fields'] = array_values(array_diff($manifest['fields'], [(string)$field]));
        $this->fieldIndex->saveIndexManifest($dbname, $manifest);
        $this->fieldIndex->invalidateFieldIndexCache($dbname);
        return ['success' => true, 'error' => null];
    }

    /**
     * Check if a field has an index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return bool
     */
    public function hasFieldIndex($dbname, $field){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field){
            return $this->segments->dbExists($dbname) && $this->fieldIndex->isFieldIndexed($dbname, $field);
        });
    }

    /**
     * Get list of field indexes for a database
     * @param string $dbname Database name
     * @return array ['fields' => array, 'sharded' => bool]
     */
    public function getFieldIndexes($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname){
            return $this->getFieldIndexesUnlocked($dbname);
        });
    }

    private function getFieldIndexesUnlocked($dbname){
        if(!$this->segments->dbExists($dbname)){
            return ['fields' => [], 'sharded' => false];
        }
        return ['fields' => $this->fieldIndex->getIndexedFields($dbname), 'sharded' => $this->segments->isSharded($dbname)];
    }

    /**
     * Rebuild a field index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array Result from createFieldIndex
     */
    public function rebuildFieldIndex($dbname, $field){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname, $field){
            return $this->rebuildFieldIndexUnlocked($dbname, $field);
        }, 'rebuildFieldIndex');
    }

    private function rebuildFieldIndexUnlocked($dbname, $field){
        if($this->fieldIndex->isFieldIndexed($dbname, $field)){
            $this->dropFieldIndexUnlocked($dbname, $field);
        }
        return $this->createFieldIndexUnlocked($dbname, $field);
    }

    /**
     * Get keys matching a field value using field index
     * Returns null if no index exists (caller should fall back to scan)
     * @param string $dbname Database name
     * @param string $field Field name
     * @param mixed $value Value to match
     * @param int|null $shardId Shard ID for sharded databases (null = all shards)
     * @return array|null Array of matching keys, or null if no index
     */
    public function getKeysFromFieldIndex($dbname, $field, $value, $shardId = null){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $value, $shardId){
            return $this->getKeysFromFieldIndexUnlocked($dbname, $field, $value, $shardId);
        });
    }

    private function getKeysFromFieldIndexUnlocked($dbname, $field, $value, $shardId = null){
        if(!$this->segments->dbExists($dbname) || !$this->fieldIndex->isFieldIndexed($dbname, $field) || !$this->fieldIndex->isIndexableValue($value)){
            return null;
        }
        $segments = $shardId !== null ? [(int)$shardId] : $this->segments->listSegments($dbname);
        $keys = [];
        foreach($segments as $segment){
            if(!in_array($segment, $this->segments->listSegments($dbname), true)){
                continue;
            }
            $index = $this->fieldIndex->ensureSegmentFieldIndex($dbname, $segment, $field);
            $found = $index === null ? null : $this->fieldIndex->fieldIndexKeys($index, $value);
            if($found === null){
                // No usable index: the records decide, exactly
                foreach($this->records->eachSegmentBatch($dbname, $segment) as $batch){
                    foreach($batch as $key => $record){
                        if(array_key_exists($field, $record) && $record[$field] === $value){
                            $keys[] = (int)$key;
                        }
                    }
                }
                $this->cache->evictBetweenSegments();
                continue;
            }
            foreach($found as $key){
                $keys[] = (int)$key;
            }
            $this->cache->evictBetweenSegments();
        }
        sort($keys);
        return $keys;
    }
}
}
namespace noneDB\Internal\Index {

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
}
namespace noneDB\Internal\Index {

use function array_chunk, array_fill, chr, chunk_split, crc32, hash, intdiv, max, min, ord, pack, str_repeat, strlen, strspn,
    strtr, substr, unpack;

/**
 * @internal
 */
final class ShardFilters {
    /** Per bit value (1 << (shard & 7)): byte b of a filter => its eight rows, one byte each ($bit or 0) */
    private static $columnBytes = [];

    /**
     * The six probe rows of a bucket id in filters of $bits bits: from its xxh3, x the first four bytes and y the
     * last four (lowest bit set), big-endian; row (x + i·y) mod 2³² mod $bits
     * @return int[]
     */
    public static function probes(string $id, int $bits): array {
        $d = unpack('N2', hash('xxh3', $id, true));
        $y = $d[2] | 1;
        $rows = [];
        for($i = 0; $i < 6; $i++){
            $rows[] = (($d[1] + $i * $y) & 0xFFFFFFFF) & ($bits - 1);
        }
        return $rows;
    }

    /**
     * The filter of a shard whose field index holds these bucket ids: $bits bits (bit r: byte r >> 3, value 1 << (r & 7)).
     * The probes of probes(), set in 64-bit words (bit r: word r >> 6, bit r & 63), packed little-endian.
     */
    public static function filter(array $ids, int $bits): string {
        $words = array_fill(0, $bits >> 6, 0);
        $mask = ($bits - 1) & 0xFFFFFFFF; // mod 2³² mod $bits (a power of two)
        foreach($ids as $id){
            $d = unpack('N2', hash('xxh3', (string)$id, true));
            $y = $d[2] | 1;
            for($i = 0, $v = $d[1]; $i < 6; $i++, $v += $y){
                $r = $v & $mask;
                $words[$r >> 6] |= 1 << ($r & 63);
            }
        }
        $filter = '';
        foreach(array_chunk($words, 4096) as $chunk){
            $filter .= pack('P*', ...$chunk);
        }
        return $filter;
    }

    /**
     * Rows [$first, $first + n) of $rowBytes bytes each (a page: n a power of two, $first a multiple of it), with
     * $shard's column taken from its filter: the shard's bit of row r (byte $shard >> 3, value 1 << ($shard & 7))
     * becomes bit $first + r of the filter; every other bit stays
     */
    public static function withColumn(string $rows, int $first, int $rowBytes, int $shard, string $filter): string {
        $n = intdiv(strlen($rows), $rowBytes);
        $bit = 1 << ($shard & 7);
        if(!isset(self::$columnBytes[$bit])){
            for($b = 0; $b < 256; $b++){
                $eight = '';
                for($k = 0; $k < 8; $k++){
                    $eight .= ($b >> $k) & 1 ? chr($bit) : "\0";
                }
                self::$columnBytes[$bit][chr($b)] = $eight;
            }
        }
        // The filter's bits of these rows, one byte per row; then each at its place in its row
        $column = substr(strtr(substr($filter, $first >> 3, ($n + 7) >> 3), self::$columnBytes[$bit]), $first & 7, $n);
        $at = $shard >> 3;
        $column = substr(str_repeat("\0", $at) . chunk_split($column, 1, str_repeat("\0", $rowBytes - 1)), 0, $n * $rowBytes);
        $keep = str_repeat(str_repeat("\xFF", $at) . chr(0xFF ^ $bit) . str_repeat("\xFF", $rowBytes - $at - 1), $n);
        return ($rows & $keep) | $column;
    }

    /** True when a filter has every probe bit (the shard may hold the bucket id) */
    public static function mayHold(string $filter, array $probes): bool {
        foreach($probes as $r){
            if((ord($filter[$r >> 3]) & (1 << ($r & 7))) === 0){
                return false;
            }
        }
        return true;
    }

    /** The smallest power of two of at least max($min, $n): a number of bits or the width */
    public static function size(int $n, int $min): int {
        $size = $min;
        while($size < $n){
            $size <<= 1;
        }
        return $size;
    }

    /** Rows per page for rows of $width bits (a page holds about 4096 bytes of rows) */
    public static function pageRows(int $bits, int $width): int {
        return min($bits, max(1, intdiv(4096, $width >> 3)));
    }

    /** A page as it is stored: its rows, then their crc32 (u32 little-endian) */
    public static function seal(string $rows): string {
        return $rows . pack('V', crc32($rows));
    }

    /** The rows of a stored page of $length bytes of rows; null when it is short or its crc doesn't match */
    public static function open(string $stored, int $length): ?string {
        if(strlen($stored) !== $length + 4){
            return null;
        }
        $rows = substr($stored, 0, $length);
        return unpack('V', $stored, $length)[1] === crc32($rows) ? $rows : null;
    }

    /**
     * The shards (bit positions) set in every row: the AND of the rows, its set bits
     * @param string[] $rows
     * @return int[] ascending
     */
    public static function holders(array $rows): array {
        $and = null;
        foreach($rows as $row){
            $and = $and === null ? $row : $and & $row;
        }
        return self::setBits($and);
    }

    /**
     * The shards that may hold a bucket id, from a file's body in memory: the rows of probes() (x + i·y, the mod 2³² of
     * a power of two is its mask) read in place, the AND given up at the first row that leaves no shard. A warm lookup.
     * @param string $body The stored pages
     * @param int $rb Bytes of a row
     * @return int[] ascending
     */
    public static function holdersIn(string $body, int $bits, int $rb, string $id): array {
        $d = unpack('N2', hash('xxh3', $id, true));
        $x = $d[1];
        $y = $d[2] | 1;
        $mask = $bits - 1;
        $per = self::pageRows($bits, $rb << 3);
        $stored = $per * $rb + 4;
        $and = null;
        for($i = 0; $i < 6; $i++, $x += $y){
            $r = $x & $mask;
            $row = substr($body, intdiv($r, $per) * $stored + ($r % $per) * $rb, $rb);
            $and = $and === null ? $row : $and & $row;
            if(strspn($and, "\0") === $rb){
                return [];
            }
        }
        return self::setBits($and);
    }

    /** @return int[] The bit positions set in a row, ascending */
    private static function setBits(string $and): array {
        $shards = [];
        $length = strlen($and);
        for($o = strspn($and, "\0"); $o < $length; $o += 1 + strspn($and, "\0", $o + 1)){
            $byte = ord($and[$o]);
            for($bit = 0; $bit < 8; $bit++){
                if($byte & (1 << $bit)){
                    $shards[] = 8 * $o + $bit;
                }
            }
        }
        return $shards;
    }
}
}
namespace noneDB\Internal\Index\Spatial {

use function array_fill, array_is_list, array_keys, array_multisort, asort, count, is_array, is_finite,
    is_numeric, is_string, ksort, log, pack;
use const SORT_NUMERIC;

/**
 * @internal
 */
final class GeoJson {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $geometry;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->geometry);
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
        $this->geometry = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // SPATIAL INDEX - GEOJSON SUPPORT
    // ==========================================

    /**
     * Validate a GeoJSON geometry object
     * Supports: Point, LineString, Polygon, MultiPoint, MultiLineString, MultiPolygon, GeometryCollection
     *
     * @param array $geometry GeoJSON geometry object
     * @return array ['valid' => bool, 'error' => ?string, 'type' => ?string]
     */
    public function validateGeoJSON($geometry): array {
        // Never throws: a stored record that fails here is simply not indexed (a malformed geometry must
        // never make later operations on the database fail)
        if (!is_array($geometry)) {
            return ['valid' => false, 'error' => 'Geometry must be an array', 'type' => null];
        }
        if (!isset($geometry['type'])) {
            return ['valid' => false, 'error' => 'Missing type property', 'type' => null];
        }

        $type = $geometry['type'];
        if (!is_string($type)) {
            return ['valid' => false, 'error' => 'Geometry type must be a string', 'type' => null];
        }

        // GeometryCollection has different structure
        if ($type === 'GeometryCollection') {
            return $this->validateGeometryCollection($geometry);
        }

        if (!isset($geometry['coordinates'])) {
            return ['valid' => false, 'error' => 'Missing coordinates property', 'type' => null];
        }

        $coords = $geometry['coordinates'];

        // Each validator checks the lists it needs (a Point needs none: values at 0 and 1)
        if (!is_array($coords)) {
            return ['valid' => false, 'error' => 'Coordinates must be an array', 'type' => null];
        }

        // Strict comparison (a switch compared loosely: 0 matched 'Point' before PHP 8)
        if ($type === 'Point') {
            return $this->validateGeoJSONPoint($coords);
        }
        if ($type === 'LineString') {
            return $this->validateGeoJSONLineString($coords);
        }
        if ($type === 'Polygon') {
            return $this->validateGeoJSONPolygon($coords);
        }
        if ($type === 'MultiPoint') {
            return $this->validateGeoJSONMultiPoint($coords);
        }
        if ($type === 'MultiLineString') {
            return $this->validateGeoJSONMultiLineString($coords);
        }
        if ($type === 'MultiPolygon') {
            return $this->validateGeoJSONMultiPolygon($coords);
        }
        return ['valid' => false, 'error' => "Unknown geometry type: $type", 'type' => null];
    }

    /**
     * An array with keys 0..n-1 in order (a JSON array)
     */
    private static function isJsonList($value): bool {
        return is_array($value) && array_is_list($value);
    }

    /**
     * Validate Point coordinates [lon, lat] or [lon, lat, altitude]
     */
    private function validateGeoJSONPoint($coords): array {
        // A position: values at 0 and 1 (checked below) are enough, no list check per position
        if (!is_array($coords) || count($coords) < 2) {
            return ['valid' => false, 'error' => 'Point must have at least 2 coordinates', 'type' => null];
        }
        if (!isset($coords[0], $coords[1]) || !is_numeric($coords[0]) || !is_numeric($coords[1])
            || is_string($coords[0]) || is_string($coords[1]) || !is_finite((float)$coords[0]) || !is_finite((float)$coords[1])) {
            return ['valid' => false, 'error' => 'Point coordinates must be finite numbers', 'type' => null];
        }
        $lon = (float)$coords[0];
        $lat = (float)$coords[1];
        if ($lon < -180 || $lon > 180) {
            return ['valid' => false, 'error' => 'Longitude must be between -180 and 180', 'type' => null];
        }
        if ($lat < -90 || $lat > 90) {
            return ['valid' => false, 'error' => 'Latitude must be between -90 and 90', 'type' => null];
        }
        return ['valid' => true, 'error' => null, 'type' => 'Point'];
    }

    /**
     * Validate LineString coordinates [[lon,lat], [lon,lat], ...]
     */
    private function validateGeoJSONLineString($coords): array {
        if (!self::isJsonList($coords) || count($coords) < 2) {
            return ['valid' => false, 'error' => 'LineString must have at least 2 positions', 'type' => null];
        }
        foreach ($coords as $i => $position) {
            $result = $this->validateGeoJSONPoint($position);
            if (!$result['valid']) {
                return ['valid' => false, 'error' => "Invalid position at index $i: " . $result['error'], 'type' => null];
            }
        }
        return ['valid' => true, 'error' => null, 'type' => 'LineString'];
    }

    /**
     * Validate Polygon coordinates [[[lon,lat], ...], ...]
     * First ring is outer boundary, subsequent rings are holes
     */
    private function validateGeoJSONPolygon($coords): array {
        if (!self::isJsonList($coords) || count($coords) < 1) {
            return ['valid' => false, 'error' => 'Polygon must have at least one ring', 'type' => null];
        }
        // Rings in order. The holes before the first failing ring are checked against the outer ring
        // together (one sweep for all their vertices); the first error is the same as ring by ring.
        $failure = null;
        $holes = [];
        foreach ($coords as $ringIndex => $ring) {
            if (!self::isJsonList($ring) || count($ring) < 4) {
                $failure = "Ring $ringIndex must have at least 4 positions";
                break;
            }
            // Validate each position
            foreach ($ring as $i => $position) {
                $result = $this->validateGeoJSONPoint($position);
                if (!$result['valid']) {
                    $failure = "Invalid position at ring $ringIndex, index $i: " . $result['error'];
                    break 2;
                }
            }
            // Check if ring is closed (first == last, compared numerically: 29 and 29.0 are the same)
            $first = $ring[0];
            $last = $ring[count($ring) - 1];
            if ((float)$first[0] != (float)$last[0] || (float)$first[1] != (float)$last[1]) {
                $failure = "Ring $ringIndex must be closed (first and last positions must be identical)";
                break;
            }
            if ($this->ringSelfIntersects($ring)) {
                $failure = "Ring $ringIndex must not intersect itself";
                break;
            }
            if ($ringIndex > 0) {
                $holes[$ringIndex] = $ring;
            }
        }
        if ($holes) {
            // Every vertex of a hole inside the outer ring or on it
            $points = [];
            $owner = [];
            foreach ($holes as $ringIndex => $ring) {
                foreach ($ring as $position) {
                    $points[] = $position;
                    $owner[] = $ringIndex;
                }
            }
            foreach ($this->locatePointsInRing($coords[0], $points) as $k => $where) {
                if ($where < 0) {
                    return ['valid' => false, 'error' => "Hole {$owner[$k]} must lie inside the outer ring", 'type' => null];
                }
            }
        }
        if ($failure !== null) {
            return ['valid' => false, 'error' => $failure, 'type' => null];
        }
        return ['valid' => true, 'error' => null, 'type' => 'Polygon'];
    }

    /**
     * True when two non-adjacent edges of a closed ring share a point (they cross, touch or overlap;
     * exact arithmetic). First a sort and sweep by x that compares the edges whose x ranges overlap:
     * fast for usual rings. When that work
     * passes 16 edge pairs per edge (rings built against it, e.g. a comb whose edges all overlap in x),
     * a sweep line over a balanced tree finishes the check: O(n log n) for every ring.
     */
    private function ringSelfIntersects(array $ring): bool {
        $n = count($ring) - 1; // edges; edge i runs from vertex i to vertex i + 1
        if ($n < 4) {
            return false; // every pair of a triangle's edges is adjacent
        }
        $xs = [];
        $ys = [];
        foreach ($ring as $position) {
            $xs[] = (float)$position[0];
            $ys[] = (float)$position[1];
        }
        $budget = 16 * $n + 256;
        if ($budget === 0) {
            return $this->ringSelfIntersectsSweep($xs, $ys, $n);
        }
        $minX = [];
        $maxX = [];
        for ($i = 0; $i < $n; $i++) {
            $a = $xs[$i];
            $b = $xs[$i + 1];
            $minX[$i] = $a < $b ? $a : $b;
            $maxX[$i] = $a < $b ? $b : $a;
        }
        asort($minX, SORT_NUMERIC);
        $active = []; // edge => max x, edges whose x range may still overlap the next ones
        $work = 0;
        foreach ($minX as $i => $from) {
            foreach ($active as $j => $to) {
                if ($to < $from) {
                    unset($active[$j]);
                    continue;
                }
                $d = $i > $j ? $i - $j : $j - $i;
                if ($d === 1 || $d === $n - 1) {
                    continue; // neighbours share a vertex (the first and the last edge share the closing one)
                }
                if (++$work > $budget) {
                    return $this->ringSelfIntersectsSweep($xs, $ys, $n);
                }
                if (self::segmentsMeet($xs[$i], $ys[$i], $xs[$i + 1], $ys[$i + 1], $xs[$j], $ys[$j], $xs[$j + 1], $ys[$j + 1])) {
                    return true;
                }
            }
            $active[$i] = $maxX[$i];
        }
        return false;
    }

    /**
     * ringSelfIntersects() as a sweep line (Shamos-Hoey) over an AVL tree of the edges that cross it,
     * ordered from bottom to top: O(n log n). Events are the vertices in (x, y) order. A ring that doesn't
     * intersect itself has at every event point exactly one vertex, so exactly its two edges end or
     * start there and no other edge passes through it; between events two edges can only cross after
     * they became neighbours in the tree, and every new pair of neighbours is tested.
     */
    private function ringSelfIntersectsSweep(array $xs, array $ys, int $n): bool {
        [$lo, $hi, $ex, $ey, $ee] = self::sweepEvents($xs, $ys, $n);
        $tree = self::avlTree(array_fill(0, $n, 0));
        $cmp = self::sweepOrder($xs, $ys, $lo, $hi);
        $meet = function (int $a, int $b) use ($xs, $ys, $n): bool {
            if ($a < 0 || $b < 0) {
                return false;
            }
            $d = $a > $b ? $a - $b : $b - $a;
            if ($d === 1 || $d === $n - 1) {
                return false;
            }
            return self::segmentsMeet($xs[$a], $ys[$a], $xs[$a + 1], $ys[$a + 1], $xs[$b], $ys[$b], $xs[$b + 1], $ys[$b + 1]);
        };
        $count = count($ee);
        for ($k = 0; $k < $count;) {
            $px = $ex[$k];
            $py = $ey[$k];
            $edges = [];
            $starts = [];
            $ends = [];
            for (; $k < $count && $ex[$k] == $px && $ey[$k] == $py; $k++) {
                $edge = $ee[$k] >> 1;
                $edges[$edge] = true;
                if ($ee[$k] & 1) {
                    $ends[] = $edge;
                } else {
                    $starts[] = $edge;
                }
            }
            // One vertex: its two edges, adjacent (a repeated vertex or a zero-length edge gives more)
            if (count($edges) !== 2) {
                return true;
            }
            [$e1, $e2] = array_keys($edges);
            $d = $e1 > $e2 ? $e1 - $e2 : $e2 - $e1;
            if ($d !== 1 && $d !== $n - 1) {
                return true;
            }
            foreach ($ends as $edge) {
                $tree['root'] = self::avlDelete($tree, $tree['root'], $edge, $cmp);
            }
            // No other edge through the vertex; the edges below and above it become neighbours
            $below = -1;
            $above = -1;
            for ($node = $tree['root']; $node >= 0;) {
                $c = self::orient($xs[$lo[$node]], $ys[$lo[$node]], $xs[$hi[$node]], $ys[$hi[$node]], $px, $py);
                if ($c === 0) {
                    return true; // the vertex lies on another edge
                }
                if ($c > 0) {
                    $below = $node;
                    $node = $tree['r'][$node];
                } else {
                    $above = $node;
                    $node = $tree['l'][$node];
                }
            }
            if (!$starts) {
                if ($meet($below, $above)) {
                    return true;
                }
                continue;
            }
            foreach ($starts as $edge) {
                $pred = -1;
                $succ = -1;
                $tree['root'] = self::avlInsert($tree, $tree['root'], $edge, $cmp, $pred, $succ);
                if ($meet($pred, $edge) || $meet($edge, $succ)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Sweep events of a ring's edges: [lo, hi, x, y, code] with lo/hi the vertex numbers of each edge's
     * smaller and larger end in (x, y) order, and the events (both ends of every edge, code edge * 2
     * for the start, edge * 2 + 1 for the end) sorted by x, then y
     */
    private static function sweepEvents(array $xs, array $ys, int $n): array {
        $lo = [];
        $hi = [];
        $ex = [];
        $ey = [];
        $ee = [];
        for ($i = 0; $i < $n; $i++) {
            $a = $i;
            $b = $i + 1;
            if ($xs[$b] < $xs[$a] || ($xs[$b] == $xs[$a] && $ys[$b] < $ys[$a])) {
                $a = $i + 1;
                $b = $i;
            }
            $lo[$i] = $a;
            $hi[$i] = $b;
            $ex[] = $xs[$a];
            $ey[] = $ys[$a];
            $ee[] = $i * 2;
            $ex[] = $xs[$b];
            $ey[] = $ys[$b];
            $ee[] = $i * 2 + 1;
        }
        array_multisort($ex, SORT_NUMERIC, $ey, SORT_NUMERIC, $ee);
        return [$lo, $hi, $ex, $ey, $ee];
    }

    /**
     * Order of two edges that cross the sweep line at the same time and don't cross each other (bottom
     * to top): compared where the later one starts, then by direction; equal edges by number (the test
     * build counts the comparisons)
     */
    private static function sweepOrder(array $xs, array $ys, array $lo, array $hi): \Closure {
        return function (int $s, int $t) use ($xs, $ys, $lo, $hi): int {
            $sx = $xs[$lo[$s]];
            $sy = $ys[$lo[$s]];
            $tx = $xs[$lo[$t]];
            $ty = $ys[$lo[$t]];
            if ($sx > $tx || ($sx == $tx && $sy > $ty)) {
                $c = self::orient($tx, $ty, $xs[$hi[$t]], $ys[$hi[$t]], $sx, $sy);
                if ($c === 0) {
                    $c = self::orient($tx, $ty, $xs[$hi[$t]], $ys[$hi[$t]], $xs[$hi[$s]], $ys[$hi[$s]]);
                }
            } elseif ($sx == $tx && $sy == $ty) {
                $c = self::orient($tx, $ty, $xs[$hi[$t]], $ys[$hi[$t]], $xs[$hi[$s]], $ys[$hi[$s]]);
            } else {
                $c = -self::orient($sx, $sy, $xs[$hi[$s]], $ys[$hi[$s]], $tx, $ty);
                if ($c === 0) {
                    $c = -self::orient($sx, $sy, $xs[$hi[$s]], $ys[$hi[$s]], $xs[$hi[$t]], $ys[$hi[$t]]);
                }
            }
            return $c !== 0 ? $c : ($s <=> $t);
        };
    }

    /**
     * Where points are against a ring that doesn't intersect itself: 1 inside, 0 on its boundary, -1
     * outside (exact). Every point against every edge when that is cheap; otherwise one sweep line over
     * the edges with the points as events, O((n + m) log n): the edges below a point, each counted +1 or
     * -1 by its direction, give its winding number (a ray straight down).
     */
    private function locatePointsInRing(array $ring, array $points): array {
        $xs = [];
        $ys = [];
        foreach ($ring as $position) {
            $xs[] = (float)$position[0];
            $ys[] = (float)$position[1];
        }
        $n = count($xs) - 1;
        $m = count($points);
        $budget = (int)(16 * ($n + $m) * log($n + $m + 2, 2));
        $out = [];
        if ($n < 4 || ($budget > 0 && $n * $m <= $budget)) {
            foreach ($points as $point) {
                $out[] = self::locatePointInRing((float)$point[0], (float)$point[1], $xs, $ys, $n);
            }
            return $out;
        }

        [$lo, $hi, $ex, $ey, $ee] = self::sweepEvents($xs, $ys, $n);
        $vertices = [];
        for ($i = 0; $i < $n; $i++) {
            $vertices[pack('dd', $xs[$i] + 0.0, $ys[$i] + 0.0)] = true;
        }
        $weights = [];
        for ($i = 0; $i < $n; $i++) {
            $weights[$i] = $lo[$i] === $i ? 1 : -1; // run from its smaller end to its larger one
        }
        foreach ($points as $k => $point) {
            $x = (float)$point[0];
            $y = (float)$point[1];
            if (isset($vertices[pack('dd', $x + 0.0, $y + 0.0)])) {
                $out[$k] = 0;
                continue;
            }
            $out[$k] = -1;
            $ex[] = $x;
            $ey[] = $y;
            $ee[] = -1 - $k; // point events: negative codes
        }
        array_multisort($ex, SORT_NUMERIC, $ey, SORT_NUMERIC, $ee);
        $tree = self::avlTree($weights);
        $cmp = self::sweepOrder($xs, $ys, $lo, $hi);
        $count = count($ee);
        for ($k = 0; $k < $count;) {
            if ($ee[$k] < 0) {
                // A point (never at a vertex): the edges below it
                $x = $ex[$k];
                $y = $ey[$k];
                $winding = 0;
                for ($node = $tree['root']; $node >= 0;) {
                    $c = self::orient($xs[$lo[$node]], $ys[$lo[$node]], $xs[$hi[$node]], $ys[$hi[$node]], $x, $y);
                    if ($c === 0) {
                        $winding = null; // on this edge
                        break;
                    }
                    if ($c > 0) {
                        $winding += $tree['s'][$tree['l'][$node]] + $weights[$node];
                        $node = $tree['r'][$node];
                    } else {
                        $node = $tree['l'][$node];
                    }
                }
                $out[-1 - $ee[$k]] = $winding === null ? 0 : ($winding !== 0 ? 1 : -1);
                $k++;
                continue;
            }
            // A vertex: its edges that end here leave the tree, then the ones that start here join it
            $px = $ex[$k];
            $py = $ey[$k];
            $starts = [];
            for (; $k < $count && $ee[$k] >= 0 && $ex[$k] == $px && $ey[$k] == $py; $k++) {
                if ($ee[$k] & 1) {
                    $tree['root'] = self::avlDelete($tree, $tree['root'], $ee[$k] >> 1, $cmp);
                } else {
                    $starts[] = $ee[$k] >> 1;
                }
            }
            foreach ($starts as $edge) {
                $pred = -1;
                $succ = -1;
                $tree['root'] = self::avlInsert($tree, $tree['root'], $edge, $cmp, $pred, $succ);
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * One point against a ring, exact: 1 inside, 0 on its boundary, -1 outside (winding number)
     */
    private static function locatePointInRing(float $x, float $y, array $xs, array $ys, int $n): int {
        $winding = 0;
        for ($i = 0; $i < $n; $i++) {
            $x1 = $xs[$i];
            $y1 = $ys[$i];
            $x2 = $xs[$i + 1];
            $y2 = $ys[$i + 1];
            $o = null;
            if (($x1 < $x2 ? $x1 <= $x && $x <= $x2 : $x2 <= $x && $x <= $x1)
                && ($y1 < $y2 ? $y1 <= $y && $y <= $y2 : $y2 <= $y && $y <= $y1)) {
                $o = self::orient($x1, $y1, $x2, $y2, $x, $y);
                if ($o === 0) {
                    return 0;
                }
            }
            if ($y1 <= $y) {
                if ($y2 > $y && ($o ?? self::orient($x1, $y1, $x2, $y2, $x, $y)) > 0) {
                    $winding++;
                }
            } elseif ($y2 <= $y && ($o ?? self::orient($x1, $y1, $x2, $y2, $x, $y)) < 0) {
                $winding--;
            }
        }
        return $winding !== 0 ? 1 : -1;
    }

    /**
     * True when two closed segments share a point (exact)
     */
    private static function segmentsMeet(float $ax, float $ay, float $bx, float $by, float $cx, float $cy, float $dx, float $dy): bool {
        if (($ax < $bx ? $ax : $bx) > ($cx > $dx ? $cx : $dx) || ($cx < $dx ? $cx : $dx) > ($ax > $bx ? $ax : $bx)
            || ($ay < $by ? $ay : $by) > ($cy > $dy ? $cy : $dy) || ($cy < $dy ? $cy : $dy) > ($ay > $by ? $ay : $by)) {
            return false; // bounding boxes apart
        }
        $o1 = self::orient($ax, $ay, $bx, $by, $cx, $cy);
        $o2 = self::orient($ax, $ay, $bx, $by, $dx, $dy);
        $o3 = self::orient($cx, $cy, $dx, $dy, $ax, $ay);
        $o4 = self::orient($cx, $cy, $dx, $dy, $bx, $by);
        if ($o1 * $o2 < 0 && $o3 * $o4 < 0) {
            return true;
        }
        // Touching or collinear: an end of one on the other (inside the boxes, which overlap)
        return ($o1 === 0 && self::inBox($ax, $ay, $bx, $by, $cx, $cy)) || ($o2 === 0 && self::inBox($ax, $ay, $bx, $by, $dx, $dy))
            || ($o3 === 0 && self::inBox($cx, $cy, $dx, $dy, $ax, $ay)) || ($o4 === 0 && self::inBox($cx, $cy, $dx, $dy, $bx, $by));
    }

    private static function inBox(float $ax, float $ay, float $bx, float $by, float $px, float $py): bool {
        return ($ax < $bx ? $ax <= $px && $px <= $bx : $bx <= $px && $px <= $ax)
            && ($ay < $by ? $ay <= $py && $py <= $by : $by <= $py && $py <= $ay);
    }

    /**
     * Sign of the orientation of c against the directed line a -> b: 1 left, -1 right, 0 on the line.
     * Exact for all finite doubles: the floating-point determinant decides when it is clearly away from
     * zero (Shewchuk's error bound), otherwise it is summed exactly.
     */
    private static function orient(float $ax, float $ay, float $bx, float $by, float $cx, float $cy): int {
        $detLeft = ($ax - $cx) * ($by - $cy);
        $detRight = ($ay - $cy) * ($bx - $cx);
        $det = $detLeft - $detRight;
        if ($detLeft > 0.0) {
            if ($detRight <= 0.0) {
                return $det > 0.0 ? 1 : ($det < 0.0 ? -1 : 0);
            }
            $detSum = $detLeft + $detRight;
        } elseif ($detLeft < 0.0) {
            if ($detRight >= 0.0) {
                return $det > 0.0 ? 1 : ($det < 0.0 ? -1 : 0);
            }
            $detSum = -$detLeft - $detRight;
        } else {
            return $det > 0.0 ? 1 : ($det < 0.0 ? -1 : 0);
        }
        $bound = 3.3306690738754716e-16 * $detSum; // (3 + 16 eps) eps, eps = 2^-53
        if ($det >= $bound || -$det >= $bound) {
            return $det > 0.0 ? 1 : -1;
        }
        return self::orientExact($ax, $ay, $bx, $by, $cx, $cy);
    }

    /**
     * Exact sign of (a - c) x (b - c): the differences and products as exact float sums (Dekker,
     * Knuth), added into a nonoverlapping expansion whose largest part has the sign
     */
    private static function orientExact(float $ax, float $ay, float $bx, float $by, float $cx, float $cy): int {
        [$acx, $acxTail] = self::twoDiff($ax, $cx);
        [$bcy, $bcyTail] = self::twoDiff($by, $cy);
        [$acy, $acyTail] = self::twoDiff($ay, $cy);
        [$bcx, $bcxTail] = self::twoDiff($bx, $cx);
        $sum = [];
        foreach ([[$acx, $bcy, 1.0], [$acx, $bcyTail, 1.0], [$acxTail, $bcy, 1.0], [$acxTail, $bcyTail, 1.0],
                  [$acy, $bcx, -1.0], [$acy, $bcxTail, -1.0], [$acyTail, $bcx, -1.0], [$acyTail, $bcxTail, -1.0]] as [$p, $q, $sign]) {
            if ($p == 0.0 || $q == 0.0) {
                continue;
            }
            [$product, $error] = self::twoProduct($p, $q);
            $sum = self::growExpansion(self::growExpansion($sum, $sign * $product), $sign * $error);
        }
        for ($i = count($sum) - 1; $i >= 0; $i--) {
            if ($sum[$i] != 0.0) {
                return $sum[$i] > 0.0 ? 1 : -1;
            }
        }
        return 0;
    }

    /** Add $b to a nonoverlapping expansion (smallest part first), zero parts dropped */
    private static function growExpansion(array $parts, float $b): array {
        if ($b == 0.0) {
            return $parts;
        }
        $out = [];
        $q = $b;
        foreach ($parts as $part) {
            [$q, $error] = self::twoSum($q, $part);
            if ($error != 0.0) {
                $out[] = $error;
            }
        }
        $out[] = $q;
        return $out;
    }

    /** a + b = x + y exactly */
    private static function twoSum(float $a, float $b): array {
        $x = $a + $b;
        $bVirtual = $x - $a;
        $aVirtual = $x - $bVirtual;
        return [$x, ($a - $aVirtual) + ($b - $bVirtual)];
    }

    /** a - b = x + y exactly */
    private static function twoDiff(float $a, float $b): array {
        $x = $a - $b;
        $bVirtual = $a - $x;
        $aVirtual = $x + $bVirtual;
        return [$x, ($a - $aVirtual) + ($bVirtual - $b)];
    }

    /** a * b = x + y exactly (Dekker's split into 26-bit halves) */
    private static function twoProduct(float $a, float $b): array {
        $x = $a * $b;
        $c = 134217729.0 * $a; // 2^27 + 1
        $aHigh = $c - ($c - $a);
        $aLow = $a - $aHigh;
        $c = 134217729.0 * $b;
        $bHigh = $c - ($c - $b);
        $bLow = $b - $bHigh;
        $error = (($x - $aHigh * $bHigh) - $aLow * $bHigh) - $aHigh * $bLow;
        return [$x, $aLow * $bLow - $error];
    }

    /**
     * An empty AVL tree for the sweeps; $weights[node] is summed per subtree ('s'). Index -1 is the
     * empty child (height 0, sum 0).
     */
    private static function avlTree(array $weights): array {
        return ['l' => [-1 => -1], 'r' => [-1 => -1], 'h' => [-1 => 0], 's' => [-1 => 0], 'w' => $weights, 'root' => -1];
    }

    /** Insert $node below $x, return the new subtree root; $pred/$succ get its neighbours */
    private static function avlInsert(array &$t, int $x, int $node, \Closure $cmp, int &$pred, int &$succ): int {
        if ($x < 0) {
            $t['l'][$node] = -1;
            $t['r'][$node] = -1;
            $t['h'][$node] = 1;
            $t['s'][$node] = $t['w'][$node];
            return $node;
        }
        if ($cmp($node, $x) < 0) {
            $succ = $x;
            $t['l'][$x] = self::avlInsert($t, $t['l'][$x], $node, $cmp, $pred, $succ);
        } else {
            $pred = $x;
            $t['r'][$x] = self::avlInsert($t, $t['r'][$x], $node, $cmp, $pred, $succ);
        }
        return self::avlBalance($t, $x);
    }

    /** Remove $node (it is in the tree) below $x, return the new subtree root */
    private static function avlDelete(array &$t, int $x, int $node, \Closure $cmp): int {
        if ($x < 0) {
            return -1;
        }
        if ($x !== $node) {
            if ($cmp($node, $x) < 0) {
                $t['l'][$x] = self::avlDelete($t, $t['l'][$x], $node, $cmp);
            } else {
                $t['r'][$x] = self::avlDelete($t, $t['r'][$x], $node, $cmp);
            }
            return self::avlBalance($t, $x);
        }
        $left = $t['l'][$x];
        $right = $t['r'][$x];
        if ($left < 0) {
            return $right;
        }
        if ($right < 0) {
            return $left;
        }
        $next = $right;
        while ($t['l'][$next] >= 0) {
            $next = $t['l'][$next];
        }
        $t['r'][$next] = self::avlDeleteMin($t, $right);
        $t['l'][$next] = $left;
        return self::avlBalance($t, $next);
    }

    private static function avlDeleteMin(array &$t, int $x): int {
        if ($t['l'][$x] < 0) {
            return $t['r'][$x];
        }
        $t['l'][$x] = self::avlDeleteMin($t, $t['l'][$x]);
        return self::avlBalance($t, $x);
    }

    private static function avlBalance(array &$t, int $x): int {
        self::avlUpdate($t, $x);
        $left = $t['l'][$x];
        $right = $t['r'][$x];
        $diff = $t['h'][$left] - $t['h'][$right];
        if ($diff > 1) {
            if ($t['h'][$t['l'][$left]] < $t['h'][$t['r'][$left]]) {
                $t['l'][$x] = self::avlRotate($t, $left, true);
            }
            return self::avlRotate($t, $x, false);
        }
        if ($diff < -1) {
            if ($t['h'][$t['r'][$right]] < $t['h'][$t['l'][$right]]) {
                $t['r'][$x] = self::avlRotate($t, $right, false);
            }
            return self::avlRotate($t, $x, true);
        }
        return $x;
    }

    /** Rotate: $left = the right child goes up, else the left child; returns the new subtree root */
    private static function avlRotate(array &$t, int $x, bool $left): int {
        if ($left) {
            $y = $t['r'][$x];
            $t['r'][$x] = $t['l'][$y];
            $t['l'][$y] = $x;
        } else {
            $y = $t['l'][$x];
            $t['l'][$x] = $t['r'][$y];
            $t['r'][$y] = $x;
        }
        self::avlUpdate($t, $x);
        self::avlUpdate($t, $y);
        return $y;
    }

    private static function avlUpdate(array &$t, int $x): void {
        $left = $t['l'][$x];
        $right = $t['r'][$x];
        $t['h'][$x] = ($t['h'][$left] > $t['h'][$right] ? $t['h'][$left] : $t['h'][$right]) + 1;
        $t['s'][$x] = $t['s'][$left] + $t['s'][$right] + $t['w'][$x];
    }

    /**
     * True when a point lies on a ring's boundary
     */
    public function pointOnRing(float $lon, float $lat, array $ring): bool {
        $n = count($ring);
        for ($i = 0; $i < $n - 1; $i++) {
            if ($this->geometry->crossProductDirection($ring[$i], $ring[$i + 1], [$lon, $lat]) == 0
                && $this->geometry->onSegment($ring[$i], $ring[$i + 1], [$lon, $lat])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate MultiPoint coordinates [[lon,lat], [lon,lat], ...]
     */
    private function validateGeoJSONMultiPoint($coords): array {
        if (!self::isJsonList($coords) || count($coords) < 1) {
            return ['valid' => false, 'error' => 'MultiPoint must have at least one point', 'type' => null];
        }
        foreach ($coords as $i => $position) {
            $result = $this->validateGeoJSONPoint($position);
            if (!$result['valid']) {
                return ['valid' => false, 'error' => "Invalid point at index $i: " . $result['error'], 'type' => null];
            }
        }
        return ['valid' => true, 'error' => null, 'type' => 'MultiPoint'];
    }

    /**
     * Validate MultiLineString coordinates [[[lon,lat], ...], ...]
     */
    private function validateGeoJSONMultiLineString($coords): array {
        if (!self::isJsonList($coords) || count($coords) < 1) {
            return ['valid' => false, 'error' => 'MultiLineString must have at least one linestring', 'type' => null];
        }
        foreach ($coords as $i => $lineString) {
            $result = $this->validateGeoJSONLineString($lineString);
            if (!$result['valid']) {
                return ['valid' => false, 'error' => "Invalid linestring at index $i: " . $result['error'], 'type' => null];
            }
        }
        return ['valid' => true, 'error' => null, 'type' => 'MultiLineString'];
    }

    /**
     * Validate MultiPolygon coordinates [[[[lon,lat], ...], ...], ...]
     */
    private function validateGeoJSONMultiPolygon($coords): array {
        if (!self::isJsonList($coords) || count($coords) < 1) {
            return ['valid' => false, 'error' => 'MultiPolygon must have at least one polygon', 'type' => null];
        }
        foreach ($coords as $i => $polygon) {
            $result = $this->validateGeoJSONPolygon($polygon);
            if (!$result['valid']) {
                return ['valid' => false, 'error' => "Invalid polygon at index $i: " . $result['error'], 'type' => null];
            }
        }
        return ['valid' => true, 'error' => null, 'type' => 'MultiPolygon'];
    }

    /**
     * Validate GeometryCollection
     */
    private function validateGeometryCollection(array $geometry): array {
        if (!isset($geometry['geometries']) || !self::isJsonList($geometry['geometries'])) {
            return ['valid' => false, 'error' => 'GeometryCollection must have geometries array', 'type' => null];
        }
        foreach ($geometry['geometries'] as $i => $geom) {
            if (!is_array($geom)) {
                return ['valid' => false, 'error' => "Invalid geometry at index $i: must be an object", 'type' => null];
            }
            $result = $this->validateGeoJSON($geom);
            if (!$result['valid']) {
                return ['valid' => false, 'error' => "Invalid geometry at index $i: " . $result['error'], 'type' => null];
            }
        }
        return ['valid' => true, 'error' => null, 'type' => 'GeometryCollection'];
    }
}
}
namespace noneDB\Internal\Index\Spatial {

use function array_merge, atan2, cos, count, deg2rad, max, min, sin, sqrt;

/**
 * @internal
 */
final class Geometry {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;


    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
    }

    /**
     * Drop the handles to the kernel and the other services (the instance is gone)
     */
    public function unbind(): void {
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // SPATIAL INDEX - GEOMETRY OPERATIONS
    // ==========================================

    /**
     * Calculate Minimum Bounding Rectangle (MBR) for any GeoJSON geometry
     * @param array $geometry GeoJSON geometry object
     * @return array|null [minLon, minLat, maxLon, maxLat]; null for a geometry without any position
     *                    (an empty GeometryCollection): it has no location and is never indexed or matched
     */
    public function calculateMBR(array $geometry): ?array {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];

        switch ($type) {
            case 'Point':
                return [$coords[0], $coords[1], $coords[0], $coords[1]];

            case 'LineString':
            case 'MultiPoint':
                return $this->mbrFromPoints($coords);

            case 'Polygon':
                // Use outer ring (first ring)
                return $this->mbrFromPoints($coords[0]);

            case 'MultiLineString':
                $allPoints = [];
                foreach ($coords as $lineString) {
                    $allPoints = array_merge($allPoints, $lineString);
                }
                return $this->mbrFromPoints($allPoints);

            case 'MultiPolygon':
                $allPoints = [];
                foreach ($coords as $polygon) {
                    $allPoints = array_merge($allPoints, $polygon[0]); // outer ring
                }
                return $this->mbrFromPoints($allPoints);

            case 'GeometryCollection':
                $mbr = null;
                foreach ($geometry['geometries'] as $geom) {
                    $geomMBR = $this->calculateMBR($geom);
                    if ($geomMBR !== null) {
                        $mbr = $mbr === null ? $geomMBR : $this->mbrUnion($mbr, $geomMBR);
                    }
                }
                return $mbr;

            default:
                return null;
        }
    }

    /**
     * Calculate MBR from array of points
     * @param array $points Array of [lon, lat] positions
     * @return array [minLon, minLat, maxLon, maxLat]
     */
    public function mbrFromPoints(array $points): ?array {
        if (empty($points)) {
            return null;
        }

        $minLon = $maxLon = $points[0][0];
        $minLat = $maxLat = $points[0][1];

        foreach ($points as $point) {
            $minLon = min($minLon, $point[0]);
            $maxLon = max($maxLon, $point[0]);
            $minLat = min($minLat, $point[1]);
            $maxLat = max($maxLat, $point[1]);
        }

        return [$minLon, $minLat, $maxLon, $maxLat];
    }

    /**
     * Union two MBRs
     * @param array $mbr1 [minLon, minLat, maxLon, maxLat]
     * @param array $mbr2 [minLon, minLat, maxLon, maxLat]
     * @return array [minLon, minLat, maxLon, maxLat]
     */
    private function mbrUnion(array $mbr1, array $mbr2): array {
        return [
            min($mbr1[0], $mbr2[0]), // minLon
            min($mbr1[1], $mbr2[1]), // minLat
            max($mbr1[2], $mbr2[2]), // maxLon
            max($mbr1[3], $mbr2[3])  // maxLat
        ];
    }

    /**
     * Check if two MBRs overlap
     * @param array $mbr1 [minLon, minLat, maxLon, maxLat]
     * @param array $mbr2 [minLon, minLat, maxLon, maxLat]
     * @return bool
     */
    public function mbrOverlaps(array $mbr1, array $mbr2): bool {
        // Check if one MBR is completely to the left, right, above, or below the other
        if ($mbr1[2] < $mbr2[0] || $mbr2[2] < $mbr1[0]) return false; // No horizontal overlap
        if ($mbr1[3] < $mbr2[1] || $mbr2[3] < $mbr1[1]) return false; // No vertical overlap
        return true;
    }

    /**
     * Calculate Haversine distance between two points (in meters). Not memoized: a key for two points costs
     * more than the formula
     * @param float $lon1 Longitude of point 1
     * @param float $lat1 Latitude of point 1
     * @param float $lon2 Longitude of point 2
     * @param float $lat2 Latitude of point 2
     * @return float Distance in meters
     */
    public function haversineDistance(float $lon1, float $lat1, float $lon2, float $lat2): float {
        $earthRadius = 6371000.0; // meters

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Point-in-Polygon test using Winding Number algorithm
     * More robust than ray casting for edge cases
     *
     * @param float $lon Point longitude
     * @param float $lat Point latitude
     * @param array $polygon GeoJSON Polygon coordinates [[[lon,lat], ...], ...]
     * @return bool
     */
    public function pointInPolygon(float $lon, float $lat, array $polygon): bool {
        // Handle GeoJSON Polygon object or raw coordinates
        $coords = isset($polygon['coordinates']) ? $polygon['coordinates'] : $polygon;

        // Check outer ring
        if (!$this->pointInRing($lon, $lat, $coords[0])) {
            return false;
        }

        // Check holes (if any)
        for ($i = 1; $i < count($coords); $i++) {
            if ($this->pointInRing($lon, $lat, $coords[$i])) {
                return false; // Inside a hole
            }
        }

        return true;
    }

    /**
     * Check if point is inside a ring using Winding Number algorithm
     */
    public function pointInRing(float $lon, float $lat, array $ring): bool {
        $windingNumber = 0;
        $n = count($ring);

        for ($i = 0; $i < $n - 1; $i++) {
            $x1 = $ring[$i][0];
            $y1 = $ring[$i][1];
            $x2 = $ring[$i + 1][0];
            $y2 = $ring[$i + 1][1];

            if ($y1 <= $lat) {
                if ($y2 > $lat) {
                    // Upward crossing
                    if ($this->isLeftOfLine($x1, $y1, $x2, $y2, $lon, $lat) > 0) {
                        $windingNumber++;
                    }
                }
            } else {
                if ($y2 <= $lat) {
                    // Downward crossing
                    if ($this->isLeftOfLine($x1, $y1, $x2, $y2, $lon, $lat) < 0) {
                        $windingNumber--;
                    }
                }
            }
        }

        return $windingNumber !== 0;
    }

    /**
     * Determine if point is left of a line (using cross product)
     * Returns: >0 if left, <0 if right, =0 if on line
     */
    private function isLeftOfLine(float $x1, float $y1, float $x2, float $y2, float $px, float $py): float {
        return (($x2 - $x1) * ($py - $y1)) - (($px - $x1) * ($y2 - $y1));
    }

    /**
     * Cross product direction calculation
     */
    public function crossProductDirection(array $p1, array $p2, array $p3): float {
        return ($p3[0] - $p1[0]) * ($p2[1] - $p1[1]) - ($p2[0] - $p1[0]) * ($p3[1] - $p1[1]);
    }

    /**
     * Check if point p is on segment p1-p2
     */
    public function onSegment(array $p1, array $p2, array $p): bool {
        return min($p1[0], $p2[0]) <= $p[0] && $p[0] <= max($p1[0], $p2[0]) &&
               min($p1[1], $p2[1]) <= $p[1] && $p[1] <= max($p1[1], $p2[1]);
    }
}
}
namespace noneDB\Internal\Index\Spatial {

use function array_chunk, array_key_exists, array_map, array_merge, array_multisort, array_pop, array_search, array_slice,
    array_values, ceil, count, crc32, floor, in_array, intdiv, is_array, is_int, json_decode, ksort, max, min, pack, sqrt,
    strlen, strpos, substr, unpack;
use const INF, SORT_NUMERIC;

/**
 * @internal
 */
final class RTree {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $globalSpatialIndexCache;
    private $rtreeNodeSize;
    private $spatialIndexCache;
    private $cacheGrew;
    private $cacheTick;
    private $cacheTouch;
    private $lockRegistry;
    private $metrics;
    private $multiLock;
    private $noWrites;
    private $cache;
    private $checkpoints;
    private $fieldIndex;
    private $geoJson;
    private $geometry;
    private $io;
    private $layout;
    private $locks;
    private $records;
    private $secondary;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->fieldIndex, $this->geoJson, $this->geometry, $this->io, $this->layout, $this->locks, $this->records, $this->secondary, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->globalSpatialIndexCache = &$this->k->globalSpatialIndexCache;
        $this->rtreeNodeSize = &$this->k->rtreeNodeSize;
        $this->spatialIndexCache = &$this->k->spatialIndexCache;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->cacheTick = &$this->rt->cacheTick;
        $this->cacheTouch = &$this->rt->cacheTouch;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->metrics = &$this->rt->metrics;
        $this->multiLock = &$this->rt->multiLock;
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
        $this->geoJson = null;
        $this->geometry = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->records = null;
        $this->secondary = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Path of a segment's R-tree
     */
    public function getSpatialIndexPath(string $dbname, string $field, ?int $shardId = null): string {
        return $this->secondary->secondaryPath($dbname, 'rx', $field, $shardId);
    }

    public function getGlobalSpatialIndexPath(string $dbname, string $field): string {
        return $this->secondary->secondaryPath($dbname, 'grx', $field);
    }

    /**
     * Integer box of a geometry (outwards), or null when it has no position
     */
    private function quantizedMBR(array $geometry): ?array {
        $mbr = $this->geometry->calculateMBR($geometry);
        return $mbr === null ? null : $this->quantizeBox($mbr);
    }

    /**
     * Integer box of [minLon, minLat, maxLon, maxLat] (clamped to the globe, rounded outwards)
     */
    private function quantizeBox(array $box): array {
        $minX = max(-180.0, min(180.0, (float)$box[0]));
        $minY = max(-90.0, min(90.0, (float)$box[1]));
        $maxX = max(-180.0, min(180.0, (float)$box[2]));
        $maxY = max(-90.0, min(90.0, (float)$box[3]));
        return [(int)floor($minX * self::RTREE_SCALE), (int)floor($minY * self::RTREE_SCALE),
            (int)ceil($maxX * self::RTREE_SCALE), (int)ceil($maxY * self::RTREE_SCALE)];
    }

    private static function boxUnion(?array $a, array $b): array {
        if($a === null){
            return $b;
        }
        return [$a[0] < $b[0] ? $a[0] : $b[0], $a[1] < $b[1] ? $a[1] : $b[1], $a[2] > $b[2] ? $a[2] : $b[2], $a[3] > $b[3] ? $a[3] : $b[3]];
    }

    private static function boxOverlaps(?array $a, array $b): bool {
        return $a !== null && $a[0] <= $b[2] && $b[0] <= $a[2] && $a[1] <= $b[3] && $b[1] <= $a[3];
    }

    private static function isBox($box): bool {
        return is_int($box[0] ?? null) && is_int($box[1] ?? null) && is_int($box[2] ?? null) && is_int($box[3] ?? null);
    }

    private static function boxArea(array $a): float {
        return ((float)$a[2] - $a[0]) * ((float)$a[3] - $a[1]);
    }

    public function newRTree(string $field, int $nodeSize): array {
        return ['kind' => 'rx', 'field' => $field, 'ns' => $nodeSize, 's' => 0, 'ck' => -1, 'e' => null, 'bytes' => 0,
            'nodes' => [0 => [0, null, []]], 'root' => 0, 'next' => 1, 'mk' => -1, 'cnt' => 0, 'leafOf' => null, 'parent' => null];
    }

    /**
     * Bulk load (sort-tile-recursive): leaves 3/4 full, ties broken by key (the same tree on every PHP). The entries
     * come as columns, sorted with array_multisort(): no array per entry until the leaves are made
     * @param int[] $ids keys (the leaves), then node ids (the levels above)
     * @param int[] $minX, $minY, $maxX, $maxY their boxes, in the same order
     */
    private function rtreeBulkLoad(array &$tree, array $ids, array $minX, array $minY, array $maxX, array $maxY): void {
        $capacity = max(2, intdiv($tree['ns'] * 3, 4));
        $tree['nodes'] = [];
        $tree['next'] = 0;
        $tree['cnt'] = count($ids);
        $tree['leafOf'] = null;
        $tree['parent'] = null;
        if(empty($ids)){
            $tree['nodes'][0] = [0, null, []];
            $tree['root'] = 0;
            $tree['next'] = 1;
            return;
        }
        for($level = 0; ; $level++){
            $count = count($ids);
            $perSlice = (int)ceil(sqrt((int)ceil($count / $capacity))) * $capacity;
            // By the x center (doubled: min + max), then the id
            $center = [];
            for($i = 0; $i < $count; $i++){
                $center[] = $minX[$i] + $maxX[$i];
            }
            array_multisort($center, SORT_NUMERIC, $ids, SORT_NUMERIC, $minX, $minY, $maxX, $maxY);
            $center = null;
            $up = [[], [], [], [], []]; // the nodes made: ids and boxes
            for($i = 0; $i < $count; $i += $perSlice){
                // A vertical slice, by the y center, then the id
                $sIds = array_slice($ids, $i, $perSlice);
                $sMinX = array_slice($minX, $i, $perSlice);
                $sMinY = array_slice($minY, $i, $perSlice);
                $sMaxX = array_slice($maxX, $i, $perSlice);
                $sMaxY = array_slice($maxY, $i, $perSlice);
                $m = count($sIds);
                $center = [];
                for($j = 0; $j < $m; $j++){
                    $center[] = $sMinY[$j] + $sMaxY[$j];
                }
                array_multisort($center, SORT_NUMERIC, $sIds, SORT_NUMERIC, $sMinX, $sMinY, $sMaxX, $sMaxY);
                for($j = 0; $j < $m; $j += $capacity){
                    $id = $tree['next']++;
                    $box = [$sMinX[$j], $sMinY[$j], $sMaxX[$j], $sMaxY[$j]];
                    $payload = [];
                    for($k = $j, $end = min($m, $j + $capacity); $k < $end; $k++){
                        $box = [min($box[0], $sMinX[$k]), min($box[1], $sMinY[$k]), max($box[2], $sMaxX[$k]), max($box[3], $sMaxY[$k])];
                        $payload[] = $level === 0 ? [$sIds[$k], $sMinX[$k], $sMinY[$k], $sMaxX[$k], $sMaxY[$k]] : $sIds[$k];
                    }
                    $tree['nodes'][$id] = [$level, $box, $payload];
                    $up[0][] = $id;
                    $up[1][] = $box[0];
                    $up[2][] = $box[1];
                    $up[3][] = $box[2];
                    $up[4][] = $box[3];
                }
            }
            if(count($up[0]) === 1){
                $tree['root'] = $up[0][0];
                return;
            }
            [$ids, $minX, $minY, $maxX, $maxY] = $up;
        }
    }

    /**
     * Parent and leaf maps (built when the first change needs them)
     */
    public function rtreeMaps(array &$tree): void {
        if($tree['parent'] !== null){
            return;
        }
        $parent = [];
        $leafOf = [];
        foreach($tree['nodes'] as $id => $node){
            if($node[0] === 0){
                foreach($node[2] as $entry){
                    $leafOf[$entry[0]] = $id;
                }
            } else {
                foreach($node[2] as $child){
                    $parent[$child] = $id;
                }
            }
        }
        $tree['parent'] = $parent;
        $tree['leafOf'] = $leafOf;
    }

    /**
     * Insert (or move) a key with its box. The way down is remembered for the box updates and splits,
     * so a key never seen before needs no parent map (built only when something is removed).
     */
    private function rtreeInsert(array &$tree, int $key, array $box): void {
        if($key <= $tree['mk']){
            $this->rtreeRemove($tree, $key);
        } else {
            $tree['mk'] = $key;
        }
        $path = [];
        $id = $tree['root'];
        while($tree['nodes'][$id][0] > 0){
            $best = null;
            $bestGrowth = INF;
            $bestArea = INF;
            foreach($tree['nodes'][$id][2] as $child){
                $childBox = $tree['nodes'][$child][1];
                if($childBox === null){
                    $best = $best ?? $child;
                    continue;
                }
                $area = self::boxArea($childBox);
                $growth = self::boxArea(self::boxUnion($childBox, $box)) - $area;
                if($growth < $bestGrowth || ($growth == $bestGrowth && $area < $bestArea)){
                    $best = $child;
                    $bestGrowth = $growth;
                    $bestArea = $area;
                }
            }
            if($best === null){
                $tree['nodes'][$id] = [0, null, []]; // an internal node without children becomes a leaf
                break;
            }
            $path[] = $id;
            $id = $best;
        }
        $tree['nodes'][$id][2][] = [$key, $box[0], $box[1], $box[2], $box[3]];
        if($tree['leafOf'] !== null){
            $tree['leafOf'][$key] = $id;
        }
        $tree['cnt']++;
        // Widen the boxes on the way up while they change
        for($node = $id, $i = count($path); ; $node = $path[--$i]){
            $old = $tree['nodes'][$node][1];
            $new = self::boxUnion($old, $box);
            if($old === $new){
                break;
            }
            $tree['nodes'][$node][1] = $new;
            if($i === 0){
                break;
            }
        }
        if(count($tree['nodes'][$id][2]) > $tree['ns']){
            $this->rtreeSplit($tree, $id, $path);
        }
    }

    private function rtreeBoxOf(array $tree, int $level, $item): ?array {
        return $level === 0 ? [$item[1], $item[2], $item[3], $item[4]] : $tree['nodes'][$item][1];
    }

    /**
     * Split an overflowing node (linear seeds, least enlargement), up to the root
     * @param array $path The node's ancestors, root first
     */
    private function rtreeSplit(array &$tree, int $id, array $path): void {
        [$level, , $items] = $tree['nodes'][$id];
        $n = count($items);
        $boxes = [];
        foreach($items as $i => $item){
            $boxes[$i] = $this->rtreeBoxOf($tree, $level, $item) ?? [0, 0, 0, 0];
        }
        // Seeds: the pair farthest apart along the axis with the greatest normalized separation
        $best = null;
        foreach([0, 1] as $axis){
            $lowHigh = 0;
            $highLow = 0;
            $min = INF;
            $max = -INF;
            foreach($boxes as $i => $b){
                if($b[$axis] > $boxes[$lowHigh][$axis]){
                    $lowHigh = $i;
                }
                if($b[$axis + 2] < $boxes[$highLow][$axis + 2]){
                    $highLow = $i;
                }
                $min = min($min, $b[$axis]);
                $max = max($max, $b[$axis + 2]);
            }
            $width = $max - $min;
            $separation = $width > 0 ? ($boxes[$lowHigh][$axis] - $boxes[$highLow][$axis + 2]) / $width : 0;
            if($best === null || $separation > $best[0]){
                $best = [$separation, $lowHigh, $highLow];
            }
        }
        [, $seed1, $seed2] = $best;
        if($seed1 === $seed2){
            $seed2 = ($seed1 + 1) % $n;
        }
        $groups = [[$items[$seed1]], [$items[$seed2]]];
        $groupBoxes = [$boxes[$seed1], $boxes[$seed2]];
        $minFill = max(1, (int)($tree['ns'] * 0.4));
        $left = $n - 2;
        foreach($items as $i => $item){
            if($i === $seed1 || $i === $seed2){
                continue;
            }
            if(count($groups[0]) + $left <= $minFill){
                $g = 0;
            } elseif(count($groups[1]) + $left <= $minFill){
                $g = 1;
            } else {
                $grow0 = self::boxArea(self::boxUnion($groupBoxes[0], $boxes[$i])) - self::boxArea($groupBoxes[0]);
                $grow1 = self::boxArea(self::boxUnion($groupBoxes[1], $boxes[$i])) - self::boxArea($groupBoxes[1]);
                $g = $grow0 < $grow1 || ($grow0 == $grow1 && count($groups[0]) <= count($groups[1])) ? 0 : 1;
            }
            $groups[$g][] = $item;
            $groupBoxes[$g] = self::boxUnion($groupBoxes[$g], $boxes[$i]);
            $left--;
        }
        $newId = $tree['next']++;
        $tree['nodes'][$id] = [$level, $groupBoxes[0], $groups[0]];
        $tree['nodes'][$newId] = [$level, $groupBoxes[1], $groups[1]];
        if($tree['parent'] !== null){
            foreach($groups[1] as $item){
                if($level === 0){
                    $tree['leafOf'][$item[0]] = $newId;
                } else {
                    $tree['parent'][$item] = $newId;
                }
            }
        }
        if(empty($path)){
            $rootId = $tree['next']++;
            $tree['nodes'][$rootId] = [$level + 1, self::boxUnion($groupBoxes[0], $groupBoxes[1]), [$id, $newId]];
            $tree['root'] = $rootId;
            if($tree['parent'] !== null){
                $tree['parent'][$id] = $rootId;
                $tree['parent'][$newId] = $rootId;
            }
            return;
        }
        $parentId = array_pop($path);
        $tree['nodes'][$parentId][2][] = $newId;
        if($tree['parent'] !== null){
            $tree['parent'][$newId] = $parentId;
        }
        if(count($tree['nodes'][$parentId][2]) > $tree['ns']){
            $this->rtreeSplit($tree, $parentId, $path);
        }
    }

    /**
     * Remove a key; emptied nodes go, boxes shrink up the tree, a root with one child is replaced by it
     * @return bool True when the key was indexed
     */
    private function rtreeRemove(array &$tree, int $key): bool {
        $this->rtreeMaps($tree);
        if(!isset($tree['leafOf'][$key])){
            return false;
        }
        $id = $tree['leafOf'][$key];
        unset($tree['leafOf'][$key]);
        $entries = $tree['nodes'][$id][2];
        foreach($entries as $i => $entry){
            if($entry[0] === $key){
                unset($entries[$i]);
                break;
            }
        }
        $tree['nodes'][$id][2] = array_values($entries);
        $tree['cnt']--;
        // Remove emptied nodes bottom-up
        while($id !== $tree['root'] && empty($tree['nodes'][$id][2])){
            $parentId = $tree['parent'][$id];
            $children = $tree['nodes'][$parentId][2];
            $at = array_search($id, $children, true);
            if($at !== false){
                unset($children[$at]);
            }
            $tree['nodes'][$parentId][2] = array_values($children);
            unset($tree['nodes'][$id], $tree['parent'][$id]);
            $id = $parentId;
        }
        // Recompute boxes up the tree while they change
        while(true){
            [$level, $old, $items] = $tree['nodes'][$id];
            $box = null;
            foreach($items as $item){
                $itemBox = $this->rtreeBoxOf($tree, $level, $item);
                if($itemBox !== null){
                    $box = self::boxUnion($box, $itemBox);
                }
            }
            if($box === $old){
                break;
            }
            $tree['nodes'][$id][1] = $box;
            if(!isset($tree['parent'][$id])){
                break;
            }
            $id = $tree['parent'][$id];
        }
        // Collapse the root
        while(true){
            [$level, , $items] = $tree['nodes'][$tree['root']];
            if($level === 0){
                break;
            }
            if(empty($items)){
                $tree['nodes'][$tree['root']] = [0, null, []];
                break;
            }
            if(count($items) > 1){
                break;
            }
            $child = $items[0];
            unset($tree['nodes'][$tree['root']]);
            $tree['root'] = $child;
            unset($tree['parent'][$child]);
        }
        return true;
    }

    /**
     * Keys whose boxes overlap one of the (integer) boxes
     */
    private function rtreeSearch(array $tree, array $boxes): array {
        $found = [];
        $stack = [$tree['root']];
        while(!empty($stack)){
            $id = array_pop($stack);
            [$level, $nodeBox, $items] = $tree['nodes'][$id];
            if($nodeBox === null){
                continue;
            }
            $hit = false;
            foreach($boxes as $box){
                if(self::boxOverlaps($nodeBox, $box)){
                    $hit = true;
                    break;
                }
            }
            if(!$hit){
                continue;
            }
            foreach($items as $item){
                if($level > 0){
                    $stack[] = $item;
                    continue;
                }
                foreach($boxes as $box){
                    if($item[1] <= $box[2] && $box[0] <= $item[3] && $item[2] <= $box[3] && $box[1] <= $item[4]){
                        $found[$item[0]] = $item[0];
                        break;
                    }
                }
            }
        }
        return array_values($found);
    }

    /**
     * Apply one data line to an R-tree: the key now holds $record (null: deleted)
     */
    private function rtreeApply(array &$tree, int $key, ?array $record): void {
        $geometry = $record === null ? null : ($record[$tree['field']] ?? null);
        $box = is_array($geometry) && $this->geoJson->validateGeoJSON($geometry)['valid'] ? $this->quantizedMBR($geometry) : null;
        if($box === null){
            if($key <= $tree['mk']){
                $this->rtreeRemove($tree, $key);
            } elseif($key > $tree['mk']){
                $tree['mk'] = $key;
            }
            return;
        }
        $this->rtreeInsert($tree, $key, $box);
    }

    /**
     * Build a segment's R-tree from its data (covers the key index's bytes)
     */
    public function buildSegmentSpatialIndex(string $dbname, string $field, ?int $shardId, array $options = []): array {
        $key = $this->secondary->keyIndexWithEpoch($dbname, $shardId);
        $nodeSize = isset($options['nodeSize']) && (int)$options['nodeSize'] >= 4 ? (int)$options['nodeSize'] : $this->rtreeNodeSize;
        $tree = $this->newRTree($field, $nodeSize);
        $columns = [[], [], [], [], []]; // keys, then their boxes' minX, minY, maxX, maxY
        foreach($this->records->eachSegmentBatch($dbname, $shardId) as $batch){
            foreach($batch as $k => $record){
                $geometry = $record[$field] ?? null;
                if(is_array($geometry) && $this->geoJson->validateGeoJSON($geometry)['valid']){
                    $box = $this->quantizedMBR($geometry);
                    if($box !== null){
                        $columns[0][] = (int)$k;
                        $columns[1][] = $box[0];
                        $columns[2][] = $box[1];
                        $columns[3][] = $box[2];
                        $columns[4][] = $box[3];
                    }
                }
            }
        }
        $this->rtreeBulkLoad($tree, ...$columns);
        $tree['mk'] = $key === null ? -1 : \noneDB\Internal\Storage\KeyIndex::maxKey($key);
        $tree['s'] = $key === null ? 0 : (int)$key['s'];
        $tree['e'] = $key === null ? null : $key['e'];
        return $tree;
    }

    /**
     * A segment's R-tree, current (the data lines after the bytes it covers are replayed); null when
     * none fits the segment (see ensureSegmentSpatialIndex)
     */
    private function readSpatialIndex(string $dbname, string $field, ?int $shardId = null): ?array {
        $key = $this->secondary->currentKeyIndex($dbname, $shardId);
        if($key === null){
            return null;
        }
        $path = $this->getSpatialIndexPath($dbname, $field, $shardId);
        $this->cacheTouch[$path] = ++$this->cacheTick;
        if(isset($this->spatialIndexCache[$path])){
            $tree = $this->spatialIndexCache[$path];
            if($tree['e'] !== null && $tree['e'] === $key['e'] && $tree['s'] <= (int)$key['s']){
                $this->metrics['hits']++;
                if($tree['s'] < (int)$key['s']){
                    unset($this->spatialIndexCache[$path], $tree['due']);
                    $this->replaySpatialIndex($dbname, $shardId, $tree, (int)$key['s']);
                    $this->spatialIndexCache[$path] = $tree;
                    if($this->secondary->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'rx', $field, $tree)){
                        $this->spatialIndexCache[$path]['due'] = true;
                    }
                } elseif(isset($tree['due']) && $this->noWrites === 0 && $this->multiLock === 0){
                    unset($tree['due'], $this->spatialIndexCache[$path]['due']); // a checkpoint a read-only load put off
                    if($this->secondary->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'rx', $field, $tree)){
                        $this->spatialIndexCache[$path]['due'] = true;
                    }
                }
                return $this->spatialIndexCache[$path];
            }
            unset($this->spatialIndexCache[$path]);
        }
        $this->metrics['misses']++;
        $file = $this->secondary->readSecondaryFile($path);
        if($file === null || !$this->secondary->secondaryFits($dbname, $shardId, $file[0], 'rx', $field)){
            return null;
        }
        $tree = $this->treeOf($field, $file[0], $file[1]);
        if($tree === null){
            return null;
        }
        $this->replaySpatialIndex($dbname, $shardId, $tree, (int)$key['s']);
        $this->spatialIndexCache[$path] = $tree;
        $this->cacheGrew = true;
        if($this->secondary->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'rx', $field, $tree)){
            $this->spatialIndexCache[$path]['due'] = true;
        }
        return $this->spatialIndexCache[$path];
    }

    public function replaySpatialIndex(string $dbname, ?int $shardId, array &$tree, int $to): void {
        foreach($this->secondary->segmentTail($dbname, $shardId, $tree['s'], $to) as [$k, $record]){
            $this->rtreeApply($tree, (int)$k, $record);
        }
        $tree['s'] = $to;
    }

    /**
     * Write an R-tree checkpoint
     */
    public function writeSpatialIndex(string $dbname, string $field, array &$tree, ?int $shardId = null): bool {
        if($tree['e'] === null){
            return false;
        }
        $path = $this->getSpatialIndexPath($dbname, $field, $shardId);
        $crc = $this->checkpoints->segmentCrc($this->segments->segDataPath($dbname, $shardId), (int)$tree['s']);
        if($crc === null){
            return false;
        }
        // One line of nodes (a leaf: its number of entries), then the entries of the leaves in that order
        $nodes = [];
        $entries = '';
        foreach($tree['nodes'] as $id => $node){
            if($node[0] === 0){
                $entries .= pack('P*', ...array_merge(...$node[2]));
                $node[2] = count($node[2]);
            }
            $nodes[(string)$id] = $node;
        }
        $body = $this->io->encodeJson($nodes);
        if($body === false){
            return false;
        }
        $body .= "\n" . $entries;
        $rootBox = $tree['nodes'][$tree['root']][1];
        $this->secondary->writeSecondaryFile($dbname, $path, ['kind' => 'rx', 'v' => 1, 'field' => $field, 'q' => self::RTREE_SCALE, 'ns' => $tree['ns'],
            's' => (int)$tree['s'], 'e' => $tree['e'], 'h' => $crc, 'mk' => $tree['mk'], 'cnt' => $tree['cnt'],
            'root' => $tree['root'], 'next' => $tree['next'], 'mbr' => $rootBox, 'bc' => crc32($body)], $body);
        $tree['ck'] = (int)$tree['s'];
        $tree['bytes'] = strlen($body);
        $this->spatialIndexCache[$path] = $tree;
        $this->cacheGrew = true;
        return true;
    }

    /**
     * The tree of an .rx file, or null when it holds none (STORAGE_FORMAT.md §6.5): the crc of the body doesn't fit, or a
     * node isn't [level, box, items], where a box is four integers (null only without items), the items of a leaf a
     * number of entries the body holds, the items above it nodes of a lower level, named once. So a walk meets every
     * node it is sent to and ends. An entry is five integers by its format: no check per entry
     */
    public function treeOf(string $field, array $header, string $body): ?array {
        [$root, $next, $size] = [$header['root'] ?? null, $header['next'] ?? null, $header['ns'] ?? null];
        if(($header['v'] ?? null) !== 1 || ($header['bc'] ?? null) !== crc32($body) || !is_int($root) || !is_int($next) || !is_int($size) || $size < 4
            || !is_int($header['mk'] ?? null) || !is_int($header['cnt'] ?? null)){
            return null;
        }
        $end = (int)strpos($body, "\n");
        $nodes = json_decode(substr($body, 0, $end), true);
        if(!is_array($nodes) || !isset($nodes[$root])){
            return null;
        }
        $entries = 0;
        $named = [];
        foreach($nodes as $id => $node){
            $level = $node[0] ?? null;
            $box = $node[1] ?? null;
            $items = $node[2] ?? null;
            if($id >= $next || !is_int($level) || $level < 0 || ($box === null ? !empty($items) : !self::isBox($box))){
                return null;
            }
            if($level === 0){
                if(!is_int($items) || $items < 0){
                    return null;
                }
                $entries += $items;
                continue;
            }
            if(!is_array($items)){
                return null;
            }
            foreach($items as $item){
                if(!is_int($item) || ($nodes[$item][0] ?? $level) >= $level || isset($named[$item])){
                    return null;
                }
                $named[$item] = true;
            }
        }
        if($entries * 40 !== strlen($body) - $end - 1){
            return null;
        }
        $at = $end + 1;
        foreach($nodes as $id => $node){
            if($node[0] === 0){
                $nodes[$id][2] = $node[2] === 0 ? [] : array_chunk(unpack('P' . 5 * $node[2], $body, $at), 5);
                $at += 40 * $node[2];
            }
        }
        $tree = $this->newRTree($field, $size);
        $tree['nodes'] = $nodes;
        $tree['root'] = $root;
        $tree['next'] = $next;
        $tree['mk'] = $header['mk'];
        $tree['cnt'] = $header['cnt'];
        $tree['s'] = $header['s'];
        $tree['ck'] = $header['s'];
        $tree['e'] = $header['e'];
        $tree['bytes'] = strlen($body);
        return $tree;
    }

    /**
     * Read the global spatial index: ['le', 'sh' => [shard => ['s','e','h','b' => box or null]]]
     */
    public function readGlobalSpatialIndex(string $dbname, string $field): ?array {
        $path = $this->getGlobalSpatialIndexPath($dbname, $field);
        $this->cacheTouch[$path] = ++$this->cacheTick;
        if(array_key_exists($path, $this->globalSpatialIndexCache)){
            $this->metrics['hits']++;
            return $this->globalSpatialIndexCache[$path];
        }
        $this->metrics['misses']++;
        $header = $this->secondary->readSecondaryHeader($path);
        $global = null;
        if(is_array($header) && ($header['kind'] ?? null) === 'grx' && ($header['v'] ?? null) === 1 && ($header['field'] ?? null) === $field && is_array($header['sh'] ?? null)){
            $global = ['le' => $header['le'] ?? null, 'sh' => []];
            foreach($header['sh'] as $shardId => $entry){
                if(\noneDB\Internal\Index\SecondaryFiles::isEntry($entry) && array_key_exists('b', $entry) && ($entry['b'] === null || self::isBox($entry['b']))){
                    $global['sh'][(int)$shardId] = $entry;
                }
            }
        }
        $this->globalSpatialIndexCache[$path] = $global;
        $this->cacheGrew = true;
        return $global;
    }

    public function writeGlobalSpatialIndex(string $dbname, string $field, array $global): void {
        $path = $this->getGlobalSpatialIndexPath($dbname, $field);
        $lock = $this->lockRegistry[$this->locks->getLockPath($dbname)] ?? null;
        $global['le'] = $lock === null ? null : $this->locks->registryEpoch($this->locks->getLockPath($dbname));
        $sh = [];
        foreach($global['sh'] as $shardId => $entry){
            $sh[(string)$shardId] = $entry;
        }
        $this->secondary->writeSecondaryFile($dbname, $path, ['kind' => 'grx', 'v' => 1, 'field' => $field, 'q' => self::RTREE_SCALE,
            'le' => $global['le'], 'sh' => empty($sh) ? new \stdClass() : $sh], '');
        $this->globalSpatialIndexCache[$path] = $global;
        unset($this->lockRegistry[$this->locks->getLockPath($dbname)]['globals'][$path]);
    }

    /**
     * Record a shard's box (at the bytes its R-tree covers) in the global spatial index
     */
    public function updateGlobalSpatialShard(string $dbname, string $field, int $shardId, array $tree): void {
        $entry = $this->globalSpatialEntry($dbname, $shardId, $tree);
        if($entry === null){
            return;
        }
        $global = $this->secondary->freshGlobalEntries($dbname, $this->readGlobalSpatialIndex($dbname, $field) ?? ['le' => null, 'sh' => []]);
        $lockPath = $this->locks->getLockPath($dbname);
        if(($global['sh'][$shardId] ?? null) === $entry && $global['le'] === (isset($this->lockRegistry[$lockPath]) ? $this->locks->registryEpoch($lockPath) : null)){
            return;
        }
        $global['sh'][$shardId] = $entry;
        $this->fieldIndex->deferGlobalWrite($dbname, 'grx', $field, $global);
    }

    /**
     * A shard's entry in the global spatial index: its R-tree's root box at the bytes it covers (null:
     * none can be recorded)
     */
    private function globalSpatialEntry(string $dbname, int $shardId, array $tree): ?array {
        if($tree['e'] === null){
            return null;
        }
        $crc = $this->checkpoints->segmentCrc($this->segments->segDataPath($dbname, $shardId), (int)$tree['s']);
        return $crc === null ? null : ['s' => (int)$tree['s'], 'e' => $tree['e'], 'h' => $crc, 'b' => $tree['nodes'][$tree['root']][1]];
    }

    /**
     * A segment's R-tree: the current one, or built from the data (exclusive lock)
     */
    public function ensureSegmentSpatialIndex(string $dbname, string $field, ?int $shardId): array {
        $tree = $this->readSpatialIndex($dbname, $field, $shardId);
        if($tree !== null){
            return $tree;
        }
        if($this->noWrites > 0){
            throw new \RuntimeException("noneDB: no spatial index to read in a read-only check");
        }
        return $this->locks->withLock($dbname, true, function () use ($dbname, $field, $shardId) {
            $tree = $this->readSpatialIndex($dbname, $field, $shardId);
            if ($tree === null) {
                $nodeSize = $this->fieldIndex->getIndexManifest($dbname)['spatialOptions'][$field]['nodeSize'] ?? null;
                $tree = $this->buildSegmentSpatialIndex($dbname, $field, $shardId, $nodeSize ? ['nodeSize' => $nodeSize] : []);
                $this->writeSpatialIndex($dbname, $field, $tree, $shardId);
                if ($shardId !== null) {
                    $this->updateGlobalSpatialShard($dbname, $field, $shardId, $tree);
                }
            }
            return $tree;
        }, 'buildSpatialIndex');
    }

    /**
     * Keys of a segment whose geometry box may overlap the query boxes (float [minLon, minLat, maxLon,
     * maxLat], or a list of them); every caller checks the exact geometry
     */
    public function rtreeSearchMBR(string $dbname, string $field, array $searchMBR, ?int $shardId = null): array {
        $tree = $this->ensureSegmentSpatialIndex($dbname, $field, $shardId);
        $boxes = is_array($searchMBR[0] ?? null) ? $searchMBR : [$searchMBR];
        return $this->rtreeSearch($tree, array_map([$this, 'quantizeBox'], $boxes));
    }

    /**
     * Rebuild a spatial index for the whole database (all segments + global shard boxes)
     */
    public function rebuildSpatialIndexForDb($dbname, $field, $nodeSize = null){
        $manifest = $this->fieldIndex->getIndexManifest($dbname);
        $nodeSize = $nodeSize ?? $manifest['spatialOptions'][$field]['nodeSize'] ?? null;
        $options = $nodeSize ? ['nodeSize' => $nodeSize] : [];
        $segments = $this->segments->listSegments($dbname);
        if(!in_array(null, $segments, true)){
            $segments[] = null; // leftovers of the non-sharded database
        }
        foreach($segments as $segment){
            $path = $this->getSpatialIndexPath($dbname, $field, $segment);
            $this->io->fsUnlink($path);
            unset($this->spatialIndexCache[$path]);
        }
        foreach([$this->getGlobalSpatialIndexPath($dbname, $field)] as $path){
            $this->io->fsUnlink($path);
            unset($this->globalSpatialIndexCache[$path]);
        }
        $indexed = 0;
        $sharded = $this->segments->isSharded($dbname);
        $global = ['le' => null, 'sh' => []]; // written once at the end (once per shard was O(shards²))
        foreach ($this->segments->listSegments($dbname) as $segment) {
            $tree = $this->buildSegmentSpatialIndex($dbname, $field, $segment, $options);
            $this->writeSpatialIndex($dbname, $field, $tree, $segment);
            $indexed += $tree['cnt'];
            if ($sharded && ($entry = $this->globalSpatialEntry($dbname, $segment, $tree)) !== null) {
                $global['sh'][$segment] = $entry;
            }
            unset($tree);
            $this->cache->evictBetweenSegments();
        }
        if ($sharded) {
            ksort($global['sh']);
            $this->writeGlobalSpatialIndex($dbname, $field, $global);
        }
        return $indexed;
    }

    /**
     * Delete the spatial index files of one shard
     */
    public function removeSpatialShardFiles($dbname, $shardId){
        foreach ($this->segments->listFilesWithPrefix($this->layout->genDir($dbname) . 'spatial/s' . (int)$shardId . '.', '/\.rx$/') as $file) {
            $this->io->fsUnlink($file);
            unset($this->spatialIndexCache[$file]);
        }
        foreach ($this->fieldIndex->getIndexManifest($dbname)['spatial'] as $field) {
            $global = $this->readGlobalSpatialIndex($dbname, $field);
            if ($global !== null && isset($global['sh'][(int)$shardId])) {
                unset($global['sh'][(int)$shardId]);
                $this->writeGlobalSpatialIndex($dbname, $field, $global);
            }
        }
    }

    /**
     * True when a shard can be skipped for the query boxes: its global entry fits the shard, its box
     * misses every box, and the shard's newer lines hold no geometry in them
     */
    public function globalSpatialSkips(string $dbname, string $field, int $shardId, ?array $global, array $boxes): bool {
        $entry = $global['sh'][$shardId] ?? null;
        if($entry === null || !$this->fieldIndex->globalEntryFits($dbname, $shardId, $entry, $global['le'])){
            return false;
        }
        $quantized = array_map([$this, 'quantizeBox'], $boxes);
        foreach($quantized as $box){
            if(self::boxOverlaps($entry['b'], $box)){
                return false;
            }
        }
        return !$this->fieldIndex->shardTailMayMatch($dbname, $shardId, $entry, function($record) use ($field, $quantized){
            $geometry = $record[$field] ?? null;
            if(!is_array($geometry) || !$this->geoJson->validateGeoJSON($geometry)['valid']){
                return false;
            }
            $box = $this->quantizedMBR($geometry);
            foreach($quantized as $q){
                if(self::boxOverlaps($box, $q)){
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Check if a spatial index exists
     * @param string $dbname Database name
     * @param string $field Field name
     * @param int|null $shardId Shard ID
     * @return bool
     */
    public function hasSpatialIndex(string $dbname, string $field, ?int $shardId = null): bool{
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $shardId){
            return $this->hasSpatialIndexUnlocked($dbname, $field, $shardId);
        });
    }

    private function hasSpatialIndexUnlocked(string $dbname, string $field, ?int $shardId = null): bool{
        if (!$this->segments->dbExists($dbname) || !in_array($field, $this->fieldIndex->getIndexManifest($dbname)['spatial'], true)) {
            return false;
        }
        return $shardId === null || in_array($shardId, $this->segments->listSegments($dbname), true);
    }
    // ==========================================
    // SPATIAL INDEX - R-TREE ENGINE
    // ==========================================
    // One R-tree per segment and indexed field: spatial/{seg}.{token}.rx (see SecondaryFiles), a
    // checkpoint like the field indexes:
    //   {"kind":"rx","v":1,"field":"loc","q":10000000,"ns":32,"s":…,"e":…,"h":…,"mk":…,"cnt":…,"root":3,"next":9}
    //   {"0":[0,[minX,minY,maxX,maxY],[[key,minX,minY,maxX,maxY],…]],"3":[1,[…],[0,2]],…}
    // A node is [level, box, payload]: level 0 holds entries [key, box], a higher level child node ids.
    // Boxes are integers: degrees * 1e7 rounded outwards (1e-7° is about 1 cm), so they don't depend on
    // serialize_precision, and every query re-checks the exact geometry. Built with sort-tile-recursive
    // bulk loading; changes after the checkpoint are replayed from the data when the index is used.
    // Sharded databases keep spatial/{token}.grx: each shard's box (null = empty) as of the bytes its
    // entry covers; a shard is skipped only when its entry fits, its box misses the query and the shard's
    // newer lines hold no geometry in it.

    public const RTREE_SCALE = 10000000;
}
}
namespace noneDB\Internal\Index\Spatial {

use function array_diff, array_key_exists, array_merge, array_values, in_array, is_int;

/**
 * @internal
 */
final class SpatialIndexApi {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $autoCreateDB;
    private $globalSpatialIndexCache;
    private $spatialIndexCache;
    private $spatialIndexEnabled;
    private $buffer;
    private $db;
    private $fieldIndex;
    private $io;
    private $layout;
    private $locks;
    private $rtree;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->db, $this->fieldIndex, $this->io, $this->layout, $this->locks, $this->rtree, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->autoCreateDB = &$this->k->autoCreateDB;
        $this->globalSpatialIndexCache = &$this->k->globalSpatialIndexCache;
        $this->spatialIndexCache = &$this->k->spatialIndexCache;
        $this->spatialIndexEnabled = &$this->k->spatialIndexEnabled;
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
        $this->db = null;
        $this->fieldIndex = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->rtree = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // SPATIAL INDEX - PUBLIC API
    // ==========================================

    /**
     * Create a spatial index on a GeoJSON field
     * @param string $dbname Database name
     * @param string $field Field name containing GeoJSON
     * @param array $options Options ['nodeSize' => 32]
     * @return array ['success' => bool, 'indexed' => int, 'error' => ?string]
     */
    public function createSpatialIndex(string $dbname, string $field, array $options = []): array{
        \noneDB\Internal\Config\Configuration::checkNames($options, ['nodeSize'], 'option', ' of createSpatialIndex()');
        if(array_key_exists('nodeSize', $options) && (!is_int($options['nodeSize']) || $options['nodeSize'] < 4)){
            throw new \InvalidArgumentException("noneDB: 'nodeSize' must be an integer of at least 4");
        }
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname, $field, $options){
            return $this->createSpatialIndexUnlocked($dbname, $field, $options);
        }, 'createSpatialIndex');
    }

    private function createSpatialIndexUnlocked(string $dbname, string $field, array $options = []): array{
        if (!$this->spatialIndexEnabled) {
            return ['success' => false, 'indexed' => 0, 'error' => 'Spatial indexing is disabled'];
        }
        if ($field === '' || $field === 'key') {
            return ['success' => false, 'indexed' => 0, 'error' => 'Invalid field name'];
        }
        $this->buffer->flushPendingBuffers($dbname);
        if (!$this->segments->dbExists($dbname)) {
            if (!$this->autoCreateDB) {
                return ['success' => false, 'indexed' => 0, 'error' => 'Database does not exist'];
            }
            $this->db->createDBUnlocked($dbname);
        }

        $manifest = $this->fieldIndex->getIndexManifest($dbname);
        if (in_array($field, $manifest['spatial'], true)) {
            return ['success' => false, 'indexed' => 0, 'error' => "Spatial index already exists for field '$field'"];
        }
        // Build first, list it last: an index that fails half-way is never listed
        $nodeSize = $options['nodeSize'] ?? null;
        $indexed = $this->rtree->rebuildSpatialIndexForDb($dbname, $field, $nodeSize);
        $manifest = $this->fieldIndex->getIndexManifest($dbname);
        $manifest['spatial'][] = $field;
        if ($nodeSize !== null) {
            $manifest['spatialOptions'][$field] = ['nodeSize' => $nodeSize];
        }
        $this->fieldIndex->saveIndexManifest($dbname, $manifest);
        return ['success' => true, 'indexed' => $indexed, 'error' => null];
    }

    /**
     * Drop a spatial index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array ['success' => bool, 'error' => ?string]
     */
    public function dropSpatialIndex(string $dbname, string $field): array{
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname, $field){
            return $this->dropSpatialIndexUnlocked($dbname, $field);
        }, 'dropSpatialIndex');
    }

    private function dropSpatialIndexUnlocked(string $dbname, string $field): array{
        foreach (array_merge([null], $this->segments->isSharded($dbname) ? $this->segments->listSegments($dbname) : []) as $segment) {
            foreach ([$this->rtree->getSpatialIndexPath($dbname, $field, $segment)] as $path) {
                $this->io->fsUnlink($path);
                unset($this->spatialIndexCache[$path]);
            }
        }
        foreach ([$this->rtree->getGlobalSpatialIndexPath($dbname, $field)] as $globalPath) {
            $this->io->fsUnlink($globalPath);
            unset($this->globalSpatialIndexCache[$globalPath]);
        }

        if ($this->segments->dbExists($dbname)) {
            $manifest = $this->fieldIndex->getIndexManifest($dbname);
            $manifest['spatial'] = array_values(array_diff($manifest['spatial'], [$field]));
            unset($manifest['spatialOptions'][$field]);
            $this->fieldIndex->saveIndexManifest($dbname, $manifest);
        }
        return ['success' => true, 'error' => null];
    }

    /**
     * Get list of spatial indexes for a database
     * @param string $dbname Database name
     * @return array List of indexed field names
     */
    public function getSpatialIndexes(string $dbname): array{
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname){
            return $this->getSpatialIndexesUnlocked($dbname);
        });
    }

    private function getSpatialIndexesUnlocked(string $dbname): array{
        return $this->segments->dbExists($dbname) ? $this->fieldIndex->getIndexManifest($dbname)['spatial'] : [];
    }

    /**
     * Rebuild a spatial index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array Result from createSpatialIndex
     */
    public function rebuildSpatialIndex(string $dbname, string $field): array{
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname, $field){
            return $this->rebuildSpatialIndexUnlocked($dbname, $field);
        }, 'rebuildSpatialIndex');
    }

    private function rebuildSpatialIndexUnlocked(string $dbname, string $field): array{
        if (!$this->spatialIndexEnabled) {
            // Checked before dropping anything: a drop must not succeed where the create then fails
            return ['success' => false, 'indexed' => 0, 'error' => 'Spatial indexing is disabled'];
        }
        $options = [];
        $nodeSize = $this->segments->dbExists($dbname) ? ($this->fieldIndex->getIndexManifest($dbname)['spatialOptions'][$field]['nodeSize'] ?? null) : null;
        if ($nodeSize) {
            $options['nodeSize'] = $nodeSize;
        }
        $this->dropSpatialIndexUnlocked($dbname, $field);
        return $this->createSpatialIndexUnlocked($dbname, $field, $options);
    }
}
}
namespace noneDB\Internal\Maintenance {

use function array_keys, array_values, asort, basename, bin2hex, count, fclose, fgets, fopen, is_array, json_decode, ksort,
    microtime, random_bytes, strlen, substr, time;
use const SORT_NUMERIC;

/**
 * @internal
 */
final class Compaction {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $indexCache;
    private $autoCompactThreshold;
    private $verifiedIndexes;
    private $metrics;
    private $checkpoints;
    private $io;
    private $locks;
    private $recovery;
    private $recoveryLog;
    private $scope;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->checkpoints, $this->io, $this->locks, $this->recovery, $this->recoveryLog, $this->scope, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->indexCache = &$this->k->indexCache;
        $this->autoCompactThreshold = &$this->k->autoCompactThreshold;
        $this->verifiedIndexes = &$this->k->verifiedIndexes;
        $this->metrics = &$this->rt->metrics;
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
        $this->io = null;
        $this->locks = null;
        $this->recovery = null;
        $this->recoveryLog = null;
        $this->scope = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * After a write that left old lines in a segment ($index: the segment's index as the write left it).
     * Compaction replaces the data file, so it runs after the write committed (see the rollback scope); whether it
     * is due is decided here, with the index in hand: a write over many segments doesn't load each evicted index
     * again only to find nothing due
     */
    public function deferCompaction($dbname, $shardId, array $index){
        if(!$this->compactionDue($index)){
            return;
        }
        $this->scope->runIndependent($dbname, 'compact:' . ($shardId === null ? 'main' : $shardId), function() use ($dbname, $shardId){
            $index = $this->checkpoints->readJsonlIndex($dbname, $shardId);
            if($index !== null && $this->compactionDue($index)){
                $this->compactJsonl($dbname, $shardId);
            }
        });
    }

    /** True once a segment's garbage (old versions, tombstones) exceeds the threshold share of its lines */
    private function compactionDue(array $index){
        $garbage = (int)$index['d'];
        return $garbage > 0 && $garbage > (\noneDB\Internal\Storage\KeyIndex::count($index) + $garbage) * $this->autoCompactThreshold;
    }

    /**
     * Compact a segment: rewrite live records (keys and order preserved), drop old versions and
     * tombstones. Crash-safe: the data file is replaced first; if the index rename doesn't happen,
     * the shorter data file is detected and the index rebuilt from it.
     * @param string $dbname
     * @param int|null $shardId
     * @return array ['compacted' => int, 'freed' => int]
     */
    public function compactJsonl($dbname, $shardId = null){
        return $this->locks->withLock($dbname, true, function() use ($dbname, $shardId){
            $started = microtime(true);
            $this->scope->refuseUncommittedFile($this->segments->segDataPath($dbname, $shardId), 'compaction');
            $this->recovery->removeStaleTemp($this->segments->segDataPath($dbname, $shardId)); // {seg}.jsonl[.kix].tmp{8 hex}
            $plan = null;
            for($attempt = 0; $plan === null; $attempt++){
                if($attempt > 1){
                    throw new \RuntimeException("noneDB: data file of database '{$dbname}' is corrupt and could not be repaired");
                }
                // A record the index points at must verify; if not, rebuild the index once and plan again
                $index = $attempt === 0 ? $this->checkpoints->writableSegmentIndex($dbname, $shardId) : $this->checkpoints->repairSegmentIndex($dbname, $shardId, true);
                if($index === null){
                    return ['compacted' => 0, 'freed' => 0];
                }
                $plan = $this->planCompaction($dbname, $shardId, $index);
            }
            $path = $this->segments->segDataPath($dbname, $shardId);
            $live = $plan['live'];
            unset($plan['live']); // held once: each record's bytes are released once they are in the buffer
            ksort($live, SORT_NUMERIC);

            $newIndex = $this->checkpoints->newJsonlIndex();
            $newIndex['created'] = $index['created'] ?? time();
            $newIndex['n'] = $index['n'];
            $buffer = '';
            $offset = 0;
            $maxKey = -1;
            // A fresh index of distinct keys: its arrays filled directly (KeyIndex::set() per key checks a body)
            foreach($live as $key => &$json){
                $newIndex['p'][$key] = $offset;
                $newIndex['l'][$key] = strlen($json);
                $buffer .= $json . "\n";
                $offset += strlen($json) + 1;
                $maxKey = $key;
                $json = null; // in the buffer now: the record's string is freed, the peak stays near one copy
            }
            unset($json);
            $newIndex['live'] = count($live);
            $live = null;
            // Keep the highest key ever used: an index rebuilt from this file must never hand it out again
            $highest = ($shardId === null ? 0 : $shardId * $this->segments->shardSizeOf($dbname)) + (int)$index['n'] - 1;
            if($maxKey < $highest){
                $buffer .= '{"__nonedb_deleted":' . $highest . "}\n";
                $newIndex['t'] = 1;
            }
            // Lines that don't decode are dropped from the data file: their bytes go to quarantine first
            $events = [];
            foreach($plan['bad'] as $region){
                $quarantine = $this->recovery->quarantineBytes($dbname, $shardId, $path, $region[0], $region[0] + $region[1]);
                $events[] = ['action' => 'drop-line', 'id' => $quarantine['id'], 'file' => basename($path),
                    'quarantine' => $quarantine['file'], 'bytesVerified' => true, 'powerLossDurable' => $quarantine['durable']];
            }

            // The checkpoint goes first: it must never be taken for the new data file
            $this->locks->bumpEpoch($dbname);
            $this->io->fsUnlink(\noneDB\Internal\Storage\Checkpoints::ckptPath($path));
            try {
                $replaced = $this->io->writeFileAtomic($path, $buffer, true);
            } catch(\RuntimeException $e){
                throw new \RuntimeException("noneDB: compaction of database '{$dbname}' failed while writing data: " . $e->getMessage(), $e->getCode(), $e);
            }
            if(!$replaced){
                throw new \RuntimeException("noneDB: compaction of database '{$dbname}' failed while writing data");
            }
            $newIndex['s'] = strlen($buffer);
            $newIndex['e'] = bin2hex(random_bytes(4));
            $this->indexCache[\noneDB\Internal\Storage\Checkpoints::ckptPath($path)] = $newIndex;
            $this->verifiedIndexes[\noneDB\Internal\Storage\Checkpoints::ckptPath($path)] = true;
            $this->checkpoints->writeCheckpoint($dbname, $shardId, $newIndex);
            foreach($events as $event){
                $this->recoveryLog->logRecoveryEvent($dbname, $event);
            }
            $this->metrics['compactions']++;
            $this->metrics['compactionSeconds'] += microtime(true) - $started;
            return ['compacted' => $newIndex['live'], 'freed' => (int)$index['d']];
        }, 'compact');
    }

    /**
     * Read a segment line by line for compaction: the JSON of every live record (verified against its
     * key) and the byte ranges of lines that are neither records nor tombstones
     * @return array|null ['live' => [key => json], 'bad' => [[offset, length], ...]]; null if the index
     *                    doesn't match the data
     */
    private function planCompaction($dbname, $shardId, array $index){
        // Every live key's offset and length (a decoded index: its arrays as they are; no array per record)
        [$offsets, $lengths] = \noneDB\Internal\Storage\KeyIndex::locate($index, null);
        asort($offsets, SORT_NUMERIC); // offsets are distinct: file order (a sort without a callback)
        // File order as two lists, no array per record: where each live line starts, and its key
        $starts = array_values($offsets);
        $keys = array_keys($offsets);
        unset($offsets);
        $count = count($keys);
        // Keys of this segment: a line with another shard's key is damaged, never a superseded record
        $size = $shardId === null ? null : $this->segments->shardSizeOf($dbname);
        $base = $shardId === null ? 0 : $shardId * $size;
        $limit = $shardId === null ? null : $base + $size;
        $fp = @fopen($this->segments->segDataPath($dbname, $shardId), 'rb');
        if($fp === false){
            return $count === 0 ? ['live' => [], 'bad' => []] : null;
        }
        $live = [];
        $bad = [];
        $next = 0;
        $pos = 0;
        try {
            while(($line = fgets($fp)) !== false){
                $length = strlen($line);
                $end = $pos + $length;
                $owned = []; // positions in the lists of the records this line holds
                while($next < $count && $starts[$next] < $end){
                    $owned[] = $next++;
                }
                if(substr($line, -1) !== "\n"){
                    if(!empty($owned)){
                        return null;
                    }
                    $bad[] = [$pos, $length]; // unterminated tail
                    break;
                }
                $json = substr($line, 0, -1);
                $whole = count($owned) === 1 && (int)$starts[$owned[0]] === $pos && (int)$lengths[$keys[$owned[0]]] === strlen($json);
                foreach($owned as $i){
                    $key = (int)$keys[$i];
                    $sub = $whole ? $json : substr($line, (int)$starts[$i] - $pos, (int)$lengths[$key]);
                    $record = json_decode($sub, true);
                    if(!is_array($record) || !isset($record['key']) || $record['key'] !== $key){
                        return null;
                    }
                    $live[$key] = $sub;
                }
                if(!$whole){
                    if(!empty($owned)){
                        $bad[] = [$pos, $length]; // a salvaged record inside other bytes: keep the line
                    } elseif($this->checkpoints->classifyLine($json, $base, $limit) === null){
                        $bad[] = [$pos, $length]; // neither a record nor a tombstone of this segment
                    }
                }
                $pos = $end;
            }
        } finally {
            fclose($fp);
        }
        return $next < $count ? null : ['live' => $live, 'bad' => $bad];
    }
}
}
namespace noneDB\Internal\Maintenance {

use function array_column, array_filter, array_flip, array_is_list, array_key_exists, array_keys, array_map,
    array_reverse, array_sum, array_unique, basename, bin2hex, chmod, count, ctype_digit, dirname, fclose, fflush,
    fgets, file_exists, file_get_contents, filesize, fopen, fread, fwrite, hash, hash_final, hash_init, hash_update,
    hash_update_stream, implode, in_array, intdiv, is_array, is_bool, is_dir, is_file, is_int, is_link, is_string,
    json_decode, json_encode, max, min, mkdir, preg_match, random_bytes, realpath, rmdir, rtrim, scandir, sort,
    str_replace, strcmp, stream_get_contents, strlen, strncmp, substr, time, trim, unlink, usort;
use const DIRECTORY_SEPARATOR, LOCK_SH, SORT_STRING;

/**
 * @internal
 */
final class BackupRestore {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    // The files of a backup (§10.1): those of a generation by §5.1, the recovery files by name
    private const SEGMENT = '(?:main|s(?:0|[1-9][0-9]*))';
    private const TOKEN = '(?:[a-z0-9_]{1,40}|[a-z0-9_]{0,24}-[0-9a-f]{12})';
    private const GENERATION_FILE = '#^(?:metadata|shards\.json|indexes\.json|segments/' . self::SEGMENT . '\.(?:jsonl|kix)'
        . '|indexes/' . self::SEGMENT . '\.' . self::TOKEN . '\.fx|indexes/' . self::TOKEN . '\.gfx'
        . '|spatial/' . self::SEGMENT . '\.' . self::TOKEN . '\.rx|spatial/' . self::TOKEN . '\.grx'
        . '|buffer/pending\.jsonl|buffer/flushing\.(?:0|[1-9][0-9]*)\.jsonl)$#';
    private const RECOVERY_FILE = '#^recovery/(?!\.\.?$)[A-Za-z0-9._-]+$#';
    private const COMPLETE_LINES = '#^generations/[0-9]+/(?:segments|buffer)/[^/]+\.jsonl$#';
    private const CHUNK = 1048576;

    private $dbDir;
    private $noWrites;
    private $buffer;
    private $cache;
    private $checkpoints;
    private $integrity;
    private $io;
    private $layout;
    private $locks;
    private $recovery;
    private $recoveryLog;
    private $scope;
    private $shardInfo;
    private $store;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->cache, $this->checkpoints, $this->integrity, $this->io, $this->layout, $this->locks, $this->recovery, $this->recoveryLog, $this->scope, $this->shardInfo, $this->store, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dbDir = &$this->k->dbDir;
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
        $this->integrity = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->recovery = null;
        $this->recoveryLog = null;
        $this->scope = null;
        $this->shardInfo = null;
        $this->store = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }

    // ---------------------------------------------------------------- backup (§10.2)

    /**
     * Copy a database, as it is at one moment, into $dir (STORAGE_FORMAT.md §10.2)
     * @return array ['database', 'dir', 'databaseId', 'generation', 'nextKey', 'records', 'bufferedRecords', 'files', 'bytes']
     * @throws \InvalidArgumentException $dir inside dbDir, or not an empty directory
     */
    public function backup($dbname, $dir){
        $dbname = $this->layout->normalizeDbName($dbname);
        $target = $this->backupTarget($dir);
        if(!$this->store->openStore(false)){
            throw new \RuntimeException("noneDB: database '{$dbname}' does not exist");
        }
        // STORE.LOCK, then the database's LOCK, both shared until the copy is complete: one moment of the
        // database and of the store record
        return $this->store->withStoreLock(LOCK_SH, function() use ($dbname, $target){
            $this->store->checkMaintenanceJournal();
            $store = $this->store->loadStoreRecord();
            if($store === null || !in_array($this->store->keyCheck(), $store['keys'], true)){
                throw new \RuntimeException("noneDB: the configured secretKey is not registered for the store in {$this->dbDir}", \noneDB::KEY_NOT_REGISTERED);
            }
            return $this->locks->withLock($dbname, false, function() use ($dbname, $target, $store){
                $this->scope->refuseInUncommittedWrite($dbname, 'backup');
                if(!$this->layout->dbPresent($dbname)){
                    throw new \RuntimeException("noneDB: database '{$dbname}' does not exist");
                }
                $this->noWrites++; // nothing of the database is written: no flush, no checkpoint
                try {
                    return $this->copyDatabase($dbname, $target, $store);
                } finally {
                    $this->noWrites--;
                }
            });
        });
    }

    /**
     * The backup directory resolved (§10.2 step 1): outside dbDir once links are resolved, absent or empty
     * @throws \InvalidArgumentException
     */
    private function backupTarget($dir){
        if(!is_string($dir) || trim($dir) === ''){
            throw new \InvalidArgumentException('noneDB: backup() needs a directory');
        }
        $resolved = \noneDB\Internal\Config\Configuration::canonicalPath($dir);
        $root = rtrim($this->dbDir, '/\\'); // canonical (Configuration::resolveDbDir())
        if($resolved === $root || strncmp($resolved . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0){
            throw new \InvalidArgumentException("noneDB: the backup directory {$dir} must lie outside dbDir");
        }
        if(file_exists($resolved) && (!is_dir($resolved) || count(scandir($resolved) ?: []) > 2)){
            throw new \InvalidArgumentException("noneDB: the backup directory {$dir} must not exist or be an empty directory");
        }
        return $resolved;
    }

    /**
     * The copy (§10.2 steps 3-5) under both locks; what it created is removed when it fails
     */
    private function copyDatabase($dbname, $target, array $store){
        $takenAt = time();
        $home = $this->layout->dbHome($dbname);
        $generation = $this->layout->generationNumber($dbname);
        $genDir = $this->layout->genDir($dbname);
        $format = $this->layout->readFormatRecord($dbname);
        $info = $this->shardInfo->getShardInfoUnlocked($dbname);
        if(!is_array($info)){
            throw new \RuntimeException("noneDB: the counters of database '{$dbname}' can't be read");
        }
        $sources = ['CURRENT' => $home . 'CURRENT', 'FORMAT' => $home . 'FORMAT'];
        foreach($this->filesUnder($genDir) as $file){
            if(preg_match(self::GENERATION_FILE, $file) === 1){
                $sources['generations/' . $generation . '/' . $file] = $genDir . $file;
            }
        }
        foreach($this->filesUnder($home . 'recovery/') as $file){
            if(preg_match(self::RECOVERY_FILE, 'recovery/' . $file) === 1 && preg_match('/tmp[0-9a-f]{8}$/', $file) !== 1){
                $sources['recovery/' . $file] = $home . 'recovery/' . $file;
            }
        }
        $buffered = 0;
        foreach($sources as $path => $source){
            if(preg_match('#^generations/[0-9]+/buffer/#', $path) === 1){
                $buffered += count($this->buffer->readBufferRecords($source));
            }
        }
        $created = [];
        try {
            $this->makeBackupDirectory($target, $created);
            $files = [];
            foreach($sources as $path => $source){
                // A data or buffer file up to its last "\n": an unterminated tail is never a record (§6.1)
                clearstatcache(true, $source);
                $length = preg_match(self::COMPLETE_LINES, $path) === 1 ? $this->checkpoints->lastNewlineIn($source, 0, (int)@filesize($source)) + 1
                    : (int)@filesize($source);
                $files[$path] = $this->writeBackupFile($target . '/' . $path, $this->chunks($source, $length, null, ''), $created);
            }
            $files['store.json'] = $this->writeBackupFile($target . '/store.json', [\noneDB\Internal\Storage\StoreRecord::sealRecord($store)], $created);
            $paths = array_keys($files);
            sort($paths, SORT_STRING);
            $entries = [];
            foreach($paths as $path){
                [$size, $sha256] = $files[$path];
                if(!$this->fileHolds($target . '/' . $path, $size, $sha256, '')){
                    throw new \RuntimeException("noneDB: the backup copy of {$path} in {$target} does not read back as it was written");
                }
                $entries[] = ['path' => $path, 'size' => $size, 'sha256' => $sha256];
            }
            foreach(array_unique(array_map('dirname', array_map(function($path) use ($target){ return $target . '/' . $path; }, $paths))) as $directory){
                $this->sync->requireSync(null, $directory, 'dir');
            }
            $manifest = ['magic' => 'NONEDB4', 'kind' => 'backup', 'storageFormat' => \noneDB::STORAGE_FORMAT,
                'databaseId' => $format['databaseId'], 'name' => $dbname, 'generation' => $generation,
                'nextKey' => (int)$info['nextKey'], 'records' => (int)$info['totalRecords'], 'bufferedRecords' => $buffered,
                'takenAt' => $takenAt, 'files' => $entries];
            $tmp = $target . '/manifest.json.tmp' . bin2hex(random_bytes(4));
            $this->writeBackupFile($tmp, [\noneDB\Internal\Storage\StoreRecord::sealRecord($manifest)], $created);
            if(!$this->sync->renameWithRetry($tmp, $target . '/manifest.json')){
                throw new \RuntimeException("noneDB: cannot write the manifest of the backup in {$target}");
            }
            $created[] = $target . '/manifest.json';
            $this->sync->requireSync(null, $target, 'dir');
        } catch(\Throwable $e){
            $this->removeBackupFiles($created);
            throw $e;
        }
        return ['database' => $dbname, 'dir' => $target, 'databaseId' => $format['databaseId'], 'generation' => $generation,
            'nextKey' => $manifest['nextKey'], 'records' => $manifest['records'], 'bufferedRecords' => $buffered,
            'files' => count($entries), 'bytes' => array_sum(array_column($entries, 'size'))];
    }

    /**
     * Regular files under a database directory, relative, sorted; a symbolic link is an error
     * @return string[]
     */
    private function filesUnder($dir){
        $files = [];
        $walk = function($at, $prefix) use (&$walk, &$files){
            foreach(@scandir($at) ?: [] as $entry){
                if($entry === '.' || $entry === '..'){
                    continue;
                }
                if(is_link($at . $entry)){
                    throw new \RuntimeException("noneDB: {$at}{$entry} is a symbolic link; a database holds none");
                }
                if(is_dir($at . $entry)){
                    $walk($at . $entry . '/', $prefix . $entry . '/');
                } elseif(is_file($at . $entry)){
                    $files[] = $prefix . $entry;
                }
            }
        };
        $walk($dir, '');
        $walk = null; // the closure refers to itself: freed now, not by the garbage collector
        sort($files, SORT_STRING);
        return $files;
    }

    // The backup directory belongs to no database: its files are written here (0700 directories, 0600
    // files), synced in durable mode; what a failed backup created is removed

    /**
     * Create a directory of a backup and its missing parents (0700), recording the ones created
     */
    private function makeBackupDirectory($dir, array &$created){
        $missing = [];
        for($at = $dir; !is_dir($at); $at = dirname($at)){
            $missing[] = $at;
        }
        foreach(array_reverse($missing) as $new){
            if(!@mkdir($new, 0700) && !is_dir($new)){
                throw new \RuntimeException("noneDB: cannot create the backup directory {$new}");
            }
            $created[] = $new;
            $this->sync->requireSync(null, dirname($new), 'dir');
        }
        @chmod($dir, 0700);
    }

    /**
     * Write a new file of a backup from $chunks
     * @return array [size, SHA-256] of the bytes written
     */
    private function writeBackupFile($path, iterable $chunks, array &$created){
        if(!is_dir(dirname($path))){
            $this->makeBackupDirectory(dirname($path), $created);
        }
        $fp = @fopen($path, 'xb');
        if($fp === false){
            throw new \RuntimeException("noneDB: cannot create the backup file {$path}");
        }
        $created[] = $path;
        @chmod($path, 0600);
        try {
            $hash = hash_init('sha256');
            $size = 0;
            foreach($chunks as $chunk){
                if(fwrite($fp, $chunk) !== strlen($chunk)){
                    throw new \RuntimeException("noneDB: cannot write the backup file {$path}");
                }
                hash_update($hash, $chunk);
                $size += strlen($chunk);
            }
            if(!fflush($fp)){
                throw new \RuntimeException("noneDB: cannot write the backup file {$path}");
            }
            $this->sync->requireSync($fp, $path, 'file');
        } finally {
            fclose($fp);
        }
        return [$size, hash_final($hash)];
    }

    private function removeBackupFiles(array $created){
        foreach(array_reverse($created) as $path){
            if(is_dir($path) && !is_link($path)){
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    // ---------------------------------------------------------------- restore (§10.3)

    /**
     * Make a backup the content of a database, with one commit (STORAGE_FORMAT.md §10.3)
     * @param array $options ['force' => bool]
     * @return array ['database', 'generation', 'nextKey', 'records', 'bufferedRecords', 'target' => 'present'|'absent'|'damaged', 'foreign' => bool]
     * @throws \RuntimeException RESTORE_REFUSED (nothing changed)
     */
    public function restore($dir, $dbname, array $options){
        $dbname = $this->layout->normalizeDbName($dbname);
        \noneDB\Internal\Config\Configuration::checkNames($options, ['force'], 'option', ' of restore()');
        $force = array_key_exists('force', $options) && \noneDB\Internal\Config\Configuration::optionBool('force', $options['force']);
        $backup = $this->readBackup($dir);
        // A refusal is decided before anything is written: first under the read lock (a missing database is
        // answered without creating anything), then again under the write lock
        $this->locks->withLock($dbname, false, function() use ($dbname, $backup, $force){
            $this->restoreIdentity($dbname, $backup, $force);
        }, 'restore');
        return $this->locks->withLock($dbname, true, function() use ($dbname, $backup, $force){
            $this->scope->refuseInUncommittedWrite($dbname, 'restore');
            [$state, $identity, $foreign] = $this->restoreIdentity($dbname, $backup, $force);
            if($state === 'present'){
                $info = $this->shardInfo->getShardInfoUnlocked($dbname);
                $nextKey = is_array($info) ? (int)$info['nextKey'] : 0;
            } else {
                $nextKey = $state === 'absent' ? 0 : $this->damagedNextKey($dbname);
            }
            $prepare = function($dir, $generation) use ($dbname, $backup, $identity, &$nextKey){
                $nextKey = $this->prepareRestored($dbname, $dir, $generation, $backup, $identity, $nextKey);
            };
            if($state === 'present'){
                $generation = $this->layout->switchGeneration($dbname, $prepare);
            } elseif($state === 'absent'){
                $this->layout->createDatabaseFiles($dbname, $identity, $prepare);
                $generation = 1;
            } else {
                $generation = $this->layout->replaceDamagedGeneration($dbname, $identity, $prepare);
            }
            $this->cache->invalidateDbCaches($dbname);
            $manifest = $backup['manifest'];
            $this->recoveryLog->logRecoveryEvent($dbname, ['action' => $foreign ? 'foreign-restore' : 'restore', 'generation' => $generation,
                'databaseId' => $manifest['databaseId'], 'takenAt' => $manifest['takenAt'], 'records' => $manifest['records']]);
            return ['database' => $dbname, 'generation' => $generation, 'nextKey' => $nextKey, 'records' => $manifest['records'],
                'bufferedRecords' => $manifest['bufferedRecords'], 'target' => $state, 'foreign' => $foreign];
        }, 'restore');
    }

    /**
     * A valid backup (§10.1): ['dir', 'manifest', 'format', 'metadata']
     * @throws \RuntimeException RESTORE_REFUSED
     */
    private function readBackup($dir){
        $root = is_string($dir) && $dir !== '' ? realpath($dir) : false;
        $manifest = $root === false ? null : \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($root . '/manifest.json'), 'backup');
        if($manifest === null || !$this->validManifest($manifest)){
            throw $this->refused($dir, 'it has no valid manifest.json (an interrupted backup has none)');
        }
        $generation = $manifest['generation'];
        $previous = null;
        foreach($manifest['files'] as $entry){
            $path = $entry['path'];
            if(!$this->backupFile($path, $generation) || ($previous !== null && strcmp($previous, $path) >= 0)){
                throw $this->refused($dir, "its manifest lists {$path}, which is not a file of a backup in its place");
            }
            $previous = $path;
            $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if(realpath($file) !== $file || !$this->fileHolds($file, $entry['size'], $entry['sha256'], '')){
                throw $this->refused($dir, "{$path} does not match its manifest");
            }
        }
        $listed = array_flip(array_column($manifest['files'], 'path'));
        foreach(['FORMAT', 'CURRENT', 'store.json', "generations/{$generation}/metadata"] as $required){
            if(!isset($listed[$required])){
                throw $this->refused($dir, "it has no {$required}");
            }
        }
        $format = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($root . '/FORMAT'), 'database');
        $current = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($root . '/CURRENT'), 'current');
        $metadata = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents("{$root}/generations/{$generation}/metadata"), 'generation');
        if($format === null || !$this->layout->validIdentity($format, $manifest['name']) || $format['databaseId'] !== $manifest['databaseId']
            || $current === null || ($current['databaseId'] ?? null) !== $format['databaseId'] || ($current['generation'] ?? null) !== $generation
            || $metadata === null || ($metadata['generation'] ?? null) !== $generation || !$this->layout->validIdentity($metadata, $manifest['name'])
            || json_encode($this->layout->identityOf($metadata)) !== json_encode($this->layout->identityOf($format))
            || !in_array($metadata['layout'] ?? null, ['single', 'sharded'], true)){
            throw $this->refused($dir, 'its FORMAT, CURRENT and metadata do not agree with its manifest');
        }
        return ['dir' => $root, 'manifest' => $manifest, 'format' => $format, 'metadata' => $metadata];
    }

    private function validManifest(array $manifest){
        foreach(['generation' => 1, 'nextKey' => 0, 'records' => 0, 'bufferedRecords' => 0, 'takenAt' => 0] as $member => $least){
            if(!is_int($manifest[$member] ?? null) || $manifest[$member] < $least){
                return false;
            }
        }
        if(($manifest['storageFormat'] ?? null) !== \noneDB::STORAGE_FORMAT || !is_string($manifest['databaseId'] ?? null)
            || !is_string($manifest['name'] ?? null) || !is_array($manifest['files'] ?? null) || !array_is_list($manifest['files'])){
            return false;
        }
        foreach($manifest['files'] as $entry){
            if(!is_array($entry) || array_keys($entry) !== ['path', 'size', 'sha256'] || !is_string($entry['path'])
                || !is_int($entry['size']) || $entry['size'] < 0 || !is_string($entry['sha256']) || preg_match('/^[0-9a-f]{64}$/', $entry['sha256']) !== 1){
                return false;
            }
        }
        return true;
    }

    /**
     * A path a backup of generation $generation may hold (§10.1)
     */
    private function backupFile($path, $generation){
        $prefix = 'generations/' . $generation . '/';
        return in_array($path, ['FORMAT', 'CURRENT', 'store.json'], true) || preg_match(self::RECOVERY_FILE, $path) === 1
            || (strncmp($path, $prefix, strlen($prefix)) === 0 && preg_match(self::GENERATION_FILE, substr($path, strlen($prefix))) === 1);
    }

    private function refused($dir, $why){
        return new \RuntimeException('noneDB: the backup in ' . (is_string($dir) ? $dir : '(no directory)') . " can't be restored: {$why}", \noneDB::RESTORE_REFUSED);
    }

    /**
     * The identity a restore gives the database (§10.3 step 3), decided before anything is written
     * @return array [target state, identity, foreign]
     * @throws \RuntimeException RESTORE_REFUSED
     */
    private function restoreIdentity($dbname, array $backup, $force){
        $state = $this->layout->restoreTargetState($dbname);
        $theirs = $backup['format'];
        $format = $state === 'absent' ? null : $this->layout->readFormatRecord($dbname);
        if($format !== null){
            $foreign = $format['databaseId'] !== $theirs['databaseId'];
            $identity = $this->layout->identityOf($format);
        } else {
            $foreign = $theirs['name'] !== $dbname;
            $identity = $this->layout->identityOf($theirs);
            if($foreign){
                $identity['databaseId'] = \noneDB\Internal\Storage\StoreRecord::uuid4();
                $identity['name'] = $dbname;
                $identity['createdAt'] = time();
            }
        }
        if($foreign && !$force){
            throw new \RuntimeException("noneDB: the backup in {$backup['dir']} is of another database ('{$theirs['name']}'); "
                . "restore it with ['force' => true] to load it as database '{$dbname}'", \noneDB::RESTORE_REFUSED);
        }
        if($backup['metadata']['layout'] === 'sharded' && $identity['shardSize'] !== $theirs['shardSize']){
            throw new \RuntimeException("noneDB: the backup in {$backup['dir']} has shards of {$theirs['shardSize']} records, database "
                . "'{$dbname}' of {$identity['shardSize']}; restore it into a database of its own", \noneDB::RESTORE_REFUSED);
        }
        return [$state, $identity, $foreign];
    }

    /**
     * Next key of a damaged database: 1 + the highest key of any complete line in any of its segment files
     * (the keys it handed out are not known otherwise, §10.3 step 4.3)
     */
    private function damagedNextKey($dbname){
        $next = 0;
        $root = $this->layout->dbHome($dbname) . 'generations/';
        foreach(@scandir($root) ?: [] as $generation){
            if(!ctype_digit((string)$generation)){
                continue;
            }
            foreach(@scandir($root . $generation . '/segments') ?: [] as $file){
                if(preg_match('/^' . self::SEGMENT . '\.jsonl$/', $file) !== 1){
                    continue;
                }
                $fp = @fopen($root . $generation . '/segments/' . $file, 'rb');
                while($fp !== false && ($line = fgets($fp)) !== false){
                    if(substr($line, -1) === "\n" && ($entry = $this->checkpoints->classifyLine(substr($line, 0, -1), 0, null)) !== null){
                        $next = max($next, $entry[1] + 1);
                    }
                }
                if($fp !== false){
                    fclose($fp);
                }
            }
        }
        return $next;
    }

    /**
     * PREPARE of a restore (§10.3 step 4): generation $generation in $dir as the backup's copy with its
     * metadata, the next key kept, then verified with checkIntegrity() over it
     * @return int the next key of the restored database
     * @throws \RuntimeException RESTORE_REFUSED when the generation does not verify
     */
    private function prepareRestored($dbname, $dir, $generation, array $backup, array $identity, $nextKey){
        $manifest = $backup['manifest'];
        $layout = $backup['metadata']['layout'];
        $prefix = 'generations/' . $manifest['generation'] . '/';
        $top = max($nextKey, $manifest['nextKey']);
        // Keys the database handed out after the backup stay used: a top tombstone of the highest one
        $tombstone = '';
        $tombstoned = null;
        if($nextKey > $manifest['nextKey']){
            $tombstone = '{"__nonedb_deleted":' . ($nextKey - 1) . "}\n";
            $tombstoned = 'segments/' . ($layout === 'single' ? 'main' : 's' . intdiv($nextKey - 1, $identity['shardSize'])) . '.jsonl';
        }
        $copied = [];
        foreach($manifest['files'] as $entry){
            if(strncmp($entry['path'], $prefix, strlen($prefix)) !== 0 || $entry['path'] === $prefix . 'metadata'){
                continue;
            }
            $file = substr($entry['path'], strlen($prefix));
            $this->copyIntoGeneration($backup['dir'] . '/' . $entry['path'], $entry['size'], $entry['sha256'], $dir . $file, $file === $tombstoned ? $tombstone : '');
            $copied[$file] = true;
        }
        if($tombstoned !== null && !isset($copied[$tombstoned])){
            $this->copyIntoGeneration(null, 0, hash('sha256', ''), $dir . $tombstoned, $tombstone); // a new shard holding it
        }
        $this->layout->writeGenerationMetadata($dbname, $generation, $identity, $layout);
        if($layout === 'sharded' && $tombstoned !== null){
            $meta = json_decode((string)@file_get_contents($dir . 'shards.json'), true);
            if(!is_array($meta) || !is_array($meta['shards'] ?? null)){
                throw new \RuntimeException("noneDB: the shard list of the backup in {$backup['dir']} is unreadable", \noneDB::RESTORE_REFUSED);
            }
            $shard = intdiv($nextKey - 1, $identity['shardSize']);
            if(!in_array($shard, array_map('intval', array_column($meta['shards'], 'id')), true)){
                $meta['shards'][] = \noneDB\Internal\Sharding\ShardMeta::newShardEntry($shard);
                usort($meta['shards'], function($a, $b){ return (int)$a['id'] <=> (int)$b['id']; });
            }
            $meta['nextKey'] = $nextKey;
            $json = $this->io->encodeJson($meta);
            if($json === false || !$this->io->writeFileAtomic($dir . 'shards.json', $json . "\n", 'durable')){
                throw new \RuntimeException("noneDB: cannot write the shard list of the restored generation of database '{$dbname}'");
            }
        }
        [$report, $info] = $this->layout->withGenerationView($dbname, $generation, $identity, function() use ($dbname){
            return [$this->integrity->integrityReport($dbname), $this->shardInfo->getShardInfoUnlocked($dbname)];
        });
        $meta = array_filter($report['indexMismatches'], function($mismatch){ return $mismatch['index'] === 'meta'; });
        $restoredKey = is_array($info) ? (int)$info['nextKey'] : -1;
        if($report['records'] !== $manifest['records'] || $report['bufferedRecords'] !== $manifest['bufferedRecords'] || !empty($meta) || $restoredKey !== $top){
            throw new \RuntimeException("noneDB: the restored generation of database '{$dbname}' does not verify: {$report['records']} records of "
                . "{$manifest['records']}, {$report['bufferedRecords']} buffered of {$manifest['bufferedRecords']}, next key {$restoredKey} of {$top}"
                . (empty($meta) ? '' : ', the shard list does not fit the data') . '; nothing was restored', \noneDB::RESTORE_REFUSED);
        }
        return $top;
    }

    /**
     * Write a file of the restored generation from the backup's bytes (and $suffix) with the durability of
     * its kind (DURABILITY.md §3), then read it back
     */
    private function copyIntoGeneration($source, $size, $sha256, $target, $suffix){
        $file = basename(dirname($target)) . '/' . basename($target);
        $mode = preg_match('#(?:^|/)(?:segments|buffer)/[^/]+\.jsonl$#', $file) === 1 ? 'replace'
            : (in_array(basename($target), ['shards.json', 'indexes.json'], true) ? 'durable' : 'derived');
        if(!$this->io->writeFileAtomic($target, $this->chunks($source, $size, $sha256, $suffix), $mode)){
            throw new \RuntimeException("noneDB: cannot write {$file} of the restored generation");
        }
        if(!$this->fileHolds($target, $size, $sha256, $suffix)){
            throw new \RuntimeException("noneDB: {$file} of the restored generation does not read back as it was copied");
        }
    }

    // ---------------------------------------------------------------- bytes

    /**
     * The first $length bytes of $path in chunks (checked against $sha256 when one is given), then $suffix
     */
    private function chunks($path, $length, $sha256, $suffix){
        if($path !== null){
            $fp = @fopen($path, 'rb');
            if($fp === false){
                throw new \RuntimeException("noneDB: cannot read {$path}");
            }
            try {
                $hash = hash_init('sha256');
                for($left = $length; $left > 0; $left -= strlen($chunk)){
                    $chunk = (string)fread($fp, min(self::CHUNK, $left));
                    if($chunk === ''){
                        throw new \RuntimeException("noneDB: {$path} changed while it was copied");
                    }
                    hash_update($hash, $chunk);
                    yield $chunk;
                }
                if($sha256 !== null && hash_final($hash) !== $sha256){
                    throw new \RuntimeException("noneDB: {$path} changed while it was copied", \noneDB::RESTORE_REFUSED);
                }
            } finally {
                fclose($fp);
            }
        }
        if($suffix !== ''){
            yield $suffix;
        }
    }

    /**
     * A regular file of $size bytes with SHA-256 $sha256, followed by $suffix exactly
     */
    private function fileHolds($path, $size, $sha256, $suffix){
        clearstatcache(true, $path);
        if(!is_file($path) || is_link($path) || filesize($path) !== $size + strlen($suffix)){
            return false;
        }
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return false;
        }
        $hash = hash_init('sha256');
        $read = $size > 0 ? hash_update_stream($hash, $fp, $size) : 0;
        $rest = (string)stream_get_contents($fp);
        fclose($fp);
        return $read === $size && hash_final($hash) === $sha256 && $rest === $suffix;
    }
}
}
namespace noneDB\Internal\Maintenance {

use function array_column, array_diff, array_filter, array_key_exists, array_keys, array_map, array_merge,
    array_reverse, array_shift, array_unique, array_values, bin2hex, clearstatcache, count, dirname, explode, fclose,
    fflush, file_exists, file_get_contents, flock, fopen, fread, fseek, fwrite, implode, in_array, is_array, is_dir,
    is_file, is_int, is_link, is_string, json_decode, json_encode, preg_match, random_bytes, rtrim, scandir, sort,
    str_pad, trim, unlink;
use const JSON_PRESERVE_ZERO_FRACTION, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, LOCK_EX, LOCK_NB,
    LOCK_UN, SORT_STRING;

/**
 * @internal
 */
final class KeyMaintenance {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $dbDir;
    private $maintenanceDepth;
    private $storeState;
    private $io;
    private $locks;
    private $recovery;
    private $store;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->io, $this->locks, $this->recovery, $this->store, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dbDir = &$this->k->dbDir;
        $this->maintenanceDepth = &$this->rt->maintenanceDepth;
        $this->storeState = &$this->rt->storeState;
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
        $this->io = null;
        $this->locks = null;
        $this->recovery = null;
        $this->store = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // KEY MAINTENANCE (docs/STORAGE_FORMAT.md §9)
    // ==========================================
    //
    // Every operation follows the maintenance order: STORE.LOCK (exclusive, non-blocking), then every
    // target database's LOCK (non-blocking; one busy lock: nothing written, MAINTENANCE_BUSY), then the
    // journal .nonedb-maintenance/journal.json, then "m" and a new epoch in every target's state record.
    // The logical commit point is the journal in state COMMITTED with the complete target STORE record;
    // the STORE copies, clearing "m" and closing the journal follow and are repeated by a re-run.

    /**
     * Rotate the secret key of every database in $dbDir that belongs to $oldKey: their directories are
     * renamed to the new key's locators (contents unchanged: FORMAT and metadata hold no key) and STORE
     * registers $newKey instead of $oldKey. Application processes must be stopped. Re-running after an
     * interruption finishes the rotation; ['abort' => true] undoes one that did not commit yet.
     * @return array ['success' => bool, 'databases' => string[], 'storeManifest' => array|null, 'error' => ?string, 'code' => ?int]
     */
    public static function migrateKey(string $dbDir, string $oldKey, string $newKey, array $options = []): array {
        self::checkOptions($options, ['abort', 'durability'], 'migrateKey()');
        $result = ['success' => false, 'databases' => [], 'storeManifest' => null, 'error' => null, 'code' => null];
        if(trim($oldKey) === '' || trim($newKey) === ''){
            $result['error'] = 'Both keys must be non-empty strings';
            return $result;
        }
        if(in_array($newKey, \noneDB\Internal\Cache\Runtime::get()->placeholderKeys, true)){
            $result['error'] = 'The new key is a placeholder; generate a random one';
            return $result;
        }
        if($oldKey === $newKey){
            $result['error'] = 'The new key is the same as the old key';
            return $result;
        }
        return self::maintenanceCall($dbDir, $oldKey, $result, $options, function(self $db) use ($oldKey, $newKey, $options){
            return $db->rotateKey($oldKey, $newKey, $options['abort'] ?? false);
        });
    }

    /**
     * Register a further key for the store in $dbDir; $registeredKey must already be registered
     * @return array ['success' => bool, 'storeManifest' => array|null, 'error' => ?string, 'code' => ?int]
     */
    public static function registerKey(string $dbDir, string $registeredKey, string $newKey, array $options = []): array {
        self::checkOptions($options, ['durability'], 'registerKey()');
        $result = ['success' => false, 'storeManifest' => null, 'error' => null, 'code' => null];
        if(trim($newKey) === '' || in_array($newKey, \noneDB\Internal\Cache\Runtime::get()->placeholderKeys, true)){
            $result['error'] = 'The new key must be a non-empty string and not a placeholder';
            return $result;
        }
        return self::maintenanceCall($dbDir, $registeredKey, $result, $options, function(self $db) use ($newKey){
            return $db->addStoreKey(\noneDB\Internal\Storage\StoreRecord::keyCheckOf($newKey));
        });
    }

    /**
     * Rebuild a store record whose two copies are both invalid (STORAGE_FORMAT.md §9.4). Every database
     * directory must belong to one of $keys. ['manifest' => path or array]: a verified STORE record (a
     * backup's store.json, a maintenance result's storeManifest) is restored (complete). Without one, only
     * ['confirmManualRebuild' => true] rebuilds the record from $keys (not complete: keys without
     * databases that were registered before are lost).
     * @return array ['success' => bool, 'mode' => 'manifest'|'manual'|null, 'complete' => bool, 'storeManifest' => array|null,
     *               'error' => ?string (lists databases no key owns), 'code' => ?int]
     */
    public static function repairStore(string $dbDir, array $keys, array $options = []): array {
        self::checkOptions($options, ['manifest', 'confirmManualRebuild', 'durability'], 'repairStore()');
        $result = ['success' => false, 'mode' => null, 'complete' => false, 'storeManifest' => null, 'error' => null, 'code' => null];
        $keys = array_values(array_filter($keys, 'is_string'));
        if(empty($keys)){
            $result['error'] = 'At least one key is needed';
            return $result;
        }
        return self::maintenanceCall($dbDir, $keys[0], $result, $options, function(self $db) use ($keys, $options){
            return $db->rebuildStoreRecord($keys, $options);
        });
    }

    /**
     * The options of a maintenance method are the ones it names, of their types (API.md "Key maintenance"), checked
     * before anything is done
     * @throws \InvalidArgumentException
     */
    private static function checkOptions(array $options, array $known, string $method): void {
        \noneDB\Internal\Config\Configuration::checkNames($options, $known, 'option', " of {$method}");
        foreach(['abort', 'confirmManualRebuild'] as $name){
            if(array_key_exists($name, $options)){
                \noneDB\Internal\Config\Configuration::optionBool($name, $options[$name]);
            }
        }
        if(array_key_exists('manifest', $options) && !is_string($options['manifest']) && !is_array($options['manifest'])){
            throw new \InvalidArgumentException("noneDB: 'manifest' must be a file path or a store record (array)");
        }
        if(array_key_exists('durability', $options)){
            \noneDB\Internal\Config\Configuration::durabilityValue($options['durability']); // the platform: as the setting's (UNSUPPORTED_DURABILITY)
        }
    }

    /**
     * Run a maintenance operation with an instance for $dbDir and $key (no config file involved).
     * $options['durability']: 'performance' (default) or 'durable', as the setting (docs/DURABILITY.md).
     * @throws \InvalidArgumentException an invalid durability mode
     */
    private static function maintenanceCall(string $dbDir, string $key, array $result, array $options, callable $fn): array {
        if(!empty(\noneDB\Internal\Cache\Runtime::get()->lockRegistry)){
            $result['error'] = 'Maintenance can\'t run while this process holds a database lock (inside a database operation)';
            return $result;
        }
        $dbDir = rtrim($dbDir, '/\\') . '/';
        if(!is_dir($dbDir)){
            $result['error'] = "Directory not found: {$dbDir}";
            return $result;
        }
        try {
            \noneDB\Internal\Cache\CacheManager::clearStaticCache();
            // The instance stays referenced while its kernel works: its destructor forgets the key
            $instance = new \noneDB(['secretKey' => $key, 'dbDir' => $dbDir, 'autoCreateDB' => false,
                'durability' => $options['durability'] ?? 'performance']);
            $out = $fn(\noneDB\Internal\Core\Kernel::of($instance)->keyMaintenance);
            unset($instance);
            \noneDB\Internal\Cache\CacheManager::clearStaticCache();
            if(is_array($out['storeManifest'] ?? null)){
                // The complete STORE record with its checksum: usable as repairStore()'s manifest as it is
                $out['storeManifest'] = json_decode(\noneDB\Internal\Storage\StoreRecord::sealRecord($out['storeManifest']), true);
            }
            return array_merge($result, $out, ['success' => true]);
        } catch(\RuntimeException $e){
            \noneDB\Internal\Cache\CacheManager::clearStaticCache();
            return array_merge($result, ['error' => $e->getMessage(), 'code' => $e->getCode()]);
        }
    }

    // ---------------------------------------------------------------- the maintenance order

    private function journalPath(){
        return $this->dbDir . '.nonedb-maintenance/journal.json';
    }

    private function readJournal(){
        $path = $this->journalPath();
        clearstatcache(true, $path);
        if(!is_file($path)){
            return null;
        }
        $journal = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($path), 'journal');
        if($journal === null){
            throw new \RuntimeException("noneDB: the maintenance journal in {$this->dbDir} is unreadable; nothing is changed", \noneDB::FORMAT_DAMAGED);
        }
        return $journal;
    }

    private function writeJournal(array $journal){
        $dir = $this->dbDir . '.nonedb-maintenance';
        if(!is_dir($dir) && !$this->io->makeDirectory($dir)){
            throw new \RuntimeException("noneDB: cannot create {$dir}");
        }
        if(!$this->store->writeStoreFile($this->journalPath(), \noneDB\Internal\Storage\StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'journal'] + $journal))){
            throw new \RuntimeException("noneDB: cannot write the maintenance journal in {$this->dbDir}");
        }
    }

    /**
     * Remove the journal: the maintenance is complete. In durable mode a removal that may not survive a power
     * loss is an unknown outcome (the journal could come back and close the store until a re-run).
     * @throws \RuntimeException OUTCOME_UNKNOWN
     */
    private function closeJournal(){
        @unlink($this->journalPath());
        $this->recovery->removeStaleTemp($this->journalPath()); // a crashed journal write's temp (an abort writes none)
        $this->sync->requireSync(null, $this->dbDir . '.nonedb-maintenance', 'dir', true);
    }

    /**
     * Lock every target database (locator order, non-blocking). All or nothing: a busy one releases the
     * others and fails with MAINTENANCE_BUSY before anything was written.
     * @param string[] $locators
     * @return array locator => handle
     */
    /**
     * Lock every target database exclusively without waiting, in locator order (§9.2 step 2); one busy:
     * all released, MAINTENANCE_BUSY, nothing written
     * @param array $names locator => database name (for the message)
     */
    private function lockMaintenanceTargets(array $names){
        $locators = array_keys($names);
        sort($locators, SORT_STRING);
        $handles = [];
        foreach($locators as $locator){
            $path = $this->dbDir . $locator . '.ndb4/LOCK';
            $fp = is_dir(dirname($path)) ? @fopen($path, 'c+be') : false;
            if($fp === false || !flock($fp, LOCK_EX | LOCK_NB)){
                if($fp !== false){
                    fclose($fp);
                }
                foreach($handles as $held){
                    flock($held, LOCK_UN);
                    fclose($held);
                }
                throw new \RuntimeException("noneDB: database '{$names[$locator]}' ({$locator}.ndb4) is in use; stop the application processes and run the maintenance again", \noneDB::MAINTENANCE_BUSY);
            }
            $handles[$locator] = $fp;
        }
        return $handles;
    }

    private static function releaseMaintenanceTargets(array $handles){
        foreach($handles as $fp){
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Set or clear "m" in a held database lock's state record, always with a new epoch (synced)
     */
    private function markMaintenance($fp, $on, $path){
        fseek($fp, 0);
        $raw = trim((string)fread($fp, \noneDB\Internal\Concurrency\LockManager::LOCK_STATE_SIZE), " \0");
        $tokens = $raw === '' ? [] : explode(' ', $raw);
        $state = (string)array_shift($tokens);
        $gen = preg_match('/^([0-9]{1,18}):[0-9a-f]{8}$/', $state, $m) === 1 ? (int)$m[1] : 0;
        $tokens = array_values(array_filter($tokens, function($t){ return $t !== '' && $t !== 'm'; }));
        if($on){
            $tokens[] = 'm';
        }
        $record = str_pad(trim(($gen + 1) . ':' . bin2hex(random_bytes(4)) . ' ' . implode(' ', $tokens)), \noneDB\Internal\Concurrency\LockManager::LOCK_STATE_SIZE);
        fseek($fp, 0);
        if(fwrite($fp, $record) !== \noneDB\Internal\Concurrency\LockManager::LOCK_STATE_SIZE || !fflush($fp)){
            throw new \RuntimeException("noneDB: cannot write a database state record in {$this->dbDir}");
        }
        // Durable mode: "m" and its epoch are synced before the work, the cleared record before the journal goes
        $this->sync->requireSync($fp, $path, 'file');
    }

    /**
     * Clear "m" of databases after the commit (their locks are taken again, waiting up to lockTimeout)
     */
    private function clearMaintenanceMarks(array $locators){
        foreach($locators as $locator){
            $path = $this->dbDir . $locator . '.ndb4/LOCK';
            if(!is_file($path)){
                continue;
            }
            $fp = @fopen($path, 'c+be');
            if($fp === false){
                throw new \RuntimeException("noneDB: cannot open the lock of database {$locator}");
            }
            try {
                $this->locks->acquireFlock($fp, LOCK_EX, $locator);
                $this->markMaintenance($fp, false, $path);
                flock($fp, LOCK_UN);
            } finally {
                fclose($fp);
            }
        }
    }

    /**
     * Apply a committed journal: STORE copies, "m" cleared, journal closed
     */
    private function applyCommittedJournal(array $journal){
        try {
            $this->store->writeStoreCopies($journal['store']);
            $this->clearMaintenanceMarks($journal['clear'] ?? []);
            $this->closeJournal();
        } catch(\RuntimeException $e){
            if($e->getCode() === \noneDB::OUTCOME_UNKNOWN){
                throw $e; // the journal is gone in this process; whether that is durable is unknown
            }
            // Committed but not completed: never reported as success; the journal stays open (the store stays
            // closed) until a re-run applies it (STORAGE_FORMAT.md §9.2)
            throw new \RuntimeException("noneDB: the maintenance of the store in {$this->dbDir} is committed but not completed ("
                . $e->getMessage() . "); run it again", \noneDB::MAINTENANCE_IN_PROGRESS, $e);
        }
        unset($this->storeState[$this->dbDir]);
        return $journal['store'];
    }

    // ---------------------------------------------------------------- operations

    private function rotateKey($oldKey, $newKey, $abort){
        $this->maintenanceDepth++;
        try {
            return $this->store->withStoreLock(LOCK_EX, function() use ($oldKey, $newKey, $abort){
                $journal = $this->readJournal();
                if($journal !== null){
                    return $this->resumeRotation($journal, $abort);
                }
                $store = $this->store->loadStoreRecord();
                $oldCheck = \noneDB\Internal\Storage\StoreRecord::keyCheckOf($oldKey);
                if($store === null || !in_array($oldCheck, $store['keys'], true)){
                    throw new \RuntimeException("noneDB: the old key is not registered for the store in {$this->dbDir}", \noneDB::KEY_NOT_REGISTERED);
                }
                // The databases of the old key and where they go
                $targets = [];
                foreach(@scandir($this->dbDir) ?: [] as $entry){
                    if(preg_match('/^([0-9a-f]{32})\.ndb4$/', $entry, $m) !== 1 || is_link($this->dbDir . $entry)){
                        continue;
                    }
                    $format = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbDir . $entry . '/FORMAT'), 'database');
                    if(!is_array($format) || !is_string($format['name'] ?? null) || \noneDB\Internal\Storage\StoreRecord::locatorOf($format['name'], $oldKey) !== $m[1]){
                        continue;
                    }
                    $to = \noneDB\Internal\Storage\StoreRecord::locatorOf($format['name'], $newKey);
                    if(file_exists($this->dbDir . $to . '.ndb4')){
                        throw new \RuntimeException("noneDB: the new key's directory for database '{$format['name']}' already exists", \noneDB::MAINTENANCE_BUSY);
                    }
                    $targets[] = ['name' => $format['name'], 'from' => $m[1], 'to' => $to, 'databaseId' => $format['databaseId'], 'done' => false];
                }
                $handles = $this->lockMaintenanceTargets(array_column($targets, 'name', 'from'));
                $target = $store;
                $target['keys'] = array_values(array_unique(array_merge(array_diff($store['keys'], [$oldCheck]), [\noneDB\Internal\Storage\StoreRecord::keyCheckOf($newKey)])));
                sort($target['keys'], SORT_STRING);
                $target['seq'] = $store['seq'] + 1;
                $journal = ['op' => 'rotate', 'id' => bin2hex(random_bytes(8)), 'state' => 'STARTED', 'targets' => $targets,
                    'store' => $target, 'clear' => array_column($targets, 'to')];
                try {
                    $this->writeJournal($journal);
                    foreach($targets as $t){
                        $this->markMaintenance($handles[$t['from']], true, $this->dbDir . $t['from'] . '.ndb4/LOCK');
                    }
                } finally {
                    self::releaseMaintenanceTargets($handles); // "m" keeps every process out from here on
                }
                return $this->resumeRotation($journal, $abort);
            }, true);
        } finally {
            $this->maintenanceDepth--;
        }
    }

    /**
     * Continue a rotation from its journal: rename what is left, verify, commit, apply
     */
    private function resumeRotation(array $journal, $abort){
        if($journal['op'] !== 'rotate'){
            throw new \RuntimeException("noneDB: another maintenance ({$journal['op']}) of the store in {$this->dbDir} is unfinished; run it again", \noneDB::MAINTENANCE_IN_PROGRESS);
        }
        if($journal['state'] === 'COMMITTED'){
            $store = $this->applyCommittedJournal($journal);
            return ['databases' => array_column($journal['targets'], 'name'), 'storeManifest' => $store];
        }
        if($abort){
            $this->undoRotationRenames($journal);
            return ['databases' => [], 'storeManifest' => $this->store->loadStoreRecord(), 'aborted' => true];
        }
        foreach($journal['targets'] as $i => $t){
            $from = $this->dbDir . $t['from'] . '.ndb4';
            $to = $this->dbDir . $t['to'] . '.ndb4';
            if(is_dir($from) && !file_exists($to)){
                if(!$this->sync->renameWithRetry($from, $to)){
                    throw new \RuntimeException("noneDB: could not rename the directory of database '{$t['name']}'; run migrateKey() again");
                }
            }
            $journal['targets'][$i]['done'] = true;
            // Verified before the commit: FORMAT, CURRENT and its generation belong to this database
            $format = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($to . '/FORMAT'), 'database');
            $current = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($to . '/CURRENT'), 'current');
            $metadata = $current === null || !is_int($current['generation'] ?? null) ? null
                : \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($to . '/generations/' . $current['generation'] . '/metadata'), 'generation');
            if($format === null || ($format['databaseId'] ?? null) !== $t['databaseId'] || ($current['databaseId'] ?? null) !== $t['databaseId']
                || ($metadata['databaseId'] ?? null) !== $t['databaseId']){
                $this->undoRotationRenames($journal);
                throw new \RuntimeException("noneDB: database '{$t['name']}' failed verification during the key rotation; nothing was changed", \noneDB::FORMAT_DAMAGED);
            }
        }
        // The renames are durable before the commit (DURABILITY.md §3)
        $this->sync->requireSync(null, $this->dbDir, 'dir');
        $journal['state'] = 'COMMITTED';
        $this->writeJournal($journal);
        $store = $this->applyCommittedJournal($journal);
        return ['databases' => array_column($journal['targets'], 'name'), 'storeManifest' => $store];
    }

    /**
     * Move every renamed directory of an uncommitted rotation back (abort, failed verification), then clear
     * "m" and close the journal. A rename that fails keeps the journal open: the store stays closed until
     * a re-run with ['abort' => true] finishes it (§9.3).
     */
    private function undoRotationRenames(array $journal){
        foreach(array_reverse($journal['targets']) as $t){
            $from = $this->dbDir . $t['from'] . '.ndb4';
            $to = $this->dbDir . $t['to'] . '.ndb4';
            clearstatcache(true, $from);
            clearstatcache(true, $to);
            if(is_dir($to) && !file_exists($from) && !$this->sync->renameWithRetry($to, $from)){
                throw new \RuntimeException("noneDB: could not move database '{$t['name']}' back; the key rotation stays open: run migrateKey() with ['abort' => true] again", \noneDB::MAINTENANCE_IN_PROGRESS);
            }
        }
        $this->sync->requireSync(null, $this->dbDir, 'dir');
        $this->clearMaintenanceMarks(array_column($journal['targets'], 'from'));
        $this->closeJournal();
    }

    private function addStoreKey($keyCheck){
        $this->maintenanceDepth++;
        try {
            return $this->store->withStoreLock(LOCK_EX, function() use ($keyCheck){
                $journal = $this->readJournal();
                if($journal !== null){
                    if($journal['op'] !== 'register' || $journal['state'] !== 'COMMITTED'){
                        throw new \RuntimeException("noneDB: another maintenance ({$journal['op']}) of the store in {$this->dbDir} is unfinished", \noneDB::MAINTENANCE_IN_PROGRESS);
                    }
                    return ['storeManifest' => $this->applyCommittedJournal($journal)];
                }
                $store = $this->store->loadStoreRecord();
                if($store === null || !in_array($this->store->keyCheck(), $store['keys'], true)){
                    throw new \RuntimeException("noneDB: the registered key is not registered for the store in {$this->dbDir}", \noneDB::KEY_NOT_REGISTERED);
                }
                if(in_array($keyCheck, $store['keys'], true)){
                    return ['storeManifest' => $store];
                }
                $target = $store;
                $target['keys'][] = $keyCheck;
                sort($target['keys'], SORT_STRING);
                $target['seq'] = $store['seq'] + 1;
                $journal = ['op' => 'register', 'id' => bin2hex(random_bytes(8)), 'state' => 'COMMITTED', 'store' => $target, 'clear' => []];
                $this->writeJournal($journal);
                return ['storeManifest' => $this->applyCommittedJournal($journal)];
            }, true);
        } finally {
            $this->maintenanceDepth--;
        }
    }

    private function rebuildStoreRecord(array $keys, array $options){
        $this->maintenanceDepth++;
        try {
            return $this->store->withStoreLock(LOCK_EX, function() use ($keys, $options){
                $journal = $this->readJournal();
                if($journal !== null){
                    if($journal['op'] !== 'repair-store' || $journal['state'] !== 'COMMITTED'){
                        throw new \RuntimeException("noneDB: another maintenance ({$journal['op']}) of the store in {$this->dbDir} is unfinished", \noneDB::MAINTENANCE_IN_PROGRESS);
                    }
                    $store = $this->applyCommittedJournal($journal);
                    return ['mode' => $journal['mode'], 'complete' => $journal['mode'] === 'manifest', 'storeManifest' => $store];
                }
                [, $copy1] = $this->store->readStoreCopy('STORE');
                [, $copy2] = $this->store->readStoreCopy('STORE.2');
                if($copy1 !== null || $copy2 !== null){
                    throw new \RuntimeException("noneDB: a valid store record exists in {$this->dbDir}; it is repaired automatically, repairStore() is only for two invalid copies", \noneDB::FORMAT_DAMAGED);
                }
                // Ownership: every database directory must belong to one of the keys
                $unattributed = [];
                foreach(@scandir($this->dbDir) ?: [] as $entry){
                    if(preg_match('/^([0-9a-f]{32})\.ndb4$/', $entry, $m) !== 1 || is_link($this->dbDir . $entry)){
                        continue;
                    }
                    $format = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbDir . $entry . '/FORMAT'), 'database');
                    $owned = false;
                    foreach($keys as $key){
                        if(is_array($format) && is_string($format['name'] ?? null) && \noneDB\Internal\Storage\StoreRecord::locatorOf($format['name'], $key) === $m[1]){
                            $owned = true;
                            break;
                        }
                    }
                    if(!$owned){
                        $unattributed[] = $entry;
                    }
                }
                if(!empty($unattributed)){
                    throw new \RuntimeException("noneDB: these databases belong to none of the given keys: " . implode(', ', $unattributed) . "; give every key of the store", \noneDB::FORMAT_DAMAGED);
                }
                $keyChecks = array_map([\noneDB\Internal\Storage\StoreRecord::class, 'keyCheckOf'], $keys);
                if(isset($options['manifest'])){
                    // A path (store.json, a saved STORE copy) or the storeManifest array of a maintenance result;
                    // either way its checksum must verify
                    $source = $options['manifest'];
                    $raw = is_array($source) ? json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n"
                        : (is_string($source) ? @file_get_contents($source) : false);
                    $manifest = \noneDB\Internal\Storage\StoreRecord::openRecord($raw, 'store');
                    if($manifest === null || ($manifest['storageFormat'] ?? null) !== \noneDB::STORAGE_FORMAT || !is_array($manifest['keys'] ?? null)
                        || !is_int($manifest['seq'] ?? null) || !is_string($manifest['storeId'] ?? null)){
                        throw new \RuntimeException('noneDB: the store manifest' . (is_string($source) ? " {$source}" : '') . ' is not a valid store record', \noneDB::FORMAT_DAMAGED);
                    }
                    $mode = 'manifest';
                    $store = $manifest;
                    $store['keys'] = array_values(array_unique(array_merge($manifest['keys'], $keyChecks)));
                    $store['seq'] = (int)$manifest['seq'] + 1;
                } elseif(!empty($options['confirmManualRebuild'])){
                    $mode = 'manual';
                    $store = ['magic' => 'NONEDB4', 'kind' => 'store', 'storageFormat' => \noneDB::STORAGE_FORMAT, 'storeId' => \noneDB\Internal\Storage\StoreRecord::uuid4(),
                        'seq' => 1, 'keys' => array_values(array_unique($keyChecks))];
                } else {
                    throw new \RuntimeException("noneDB: without a verified store manifest the record can only be rebuilt by hand: pass ['confirmManualRebuild' => true] (keys without databases that were registered are not restored)", \noneDB::FORMAT_DAMAGED);
                }
                sort($store['keys'], SORT_STRING);
                unset($store['checksum']);
                $journal = ['op' => 'repair-store', 'id' => bin2hex(random_bytes(8)), 'state' => 'COMMITTED', 'mode' => $mode, 'store' => $store, 'clear' => []];
                $this->writeJournal($journal);
                $store = $this->applyCommittedJournal($journal);
                $this->store->logStoreEvent(['action' => $mode === 'manual' ? 'manual-store-rebuild' : 'store-restored-from-manifest', 'keys' => count($store['keys'])]);
                return ['mode' => $mode, 'complete' => $mode === 'manifest', 'storeManifest' => $store];
            }, true);
        } finally {
            $this->maintenanceDepth--;
        }
    }
}
}
namespace noneDB\Internal\Maintenance {

use function array_keys, clearstatcache, count, file_exists, filesize, intdiv, max;

/**
 * @internal
 */
final class Maintenance {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $bufferCountLimit;
    private $bufferEnabled;
    private $bufferFlushInterval;
    private $bufferSizeLimit;
    private $indexCache;
    private $shardSize;
    private $shardingEnabled;
    private $lockRegistry;
    private $buffer;
    private $cache;
    private $checkpoints;
    private $compaction;
    private $db;
    private $fieldIndex;
    private $io;
    private $layout;
    private $layoutSwitch;
    private $locks;
    private $rtree;
    private $scope;
    private $segments;
    private $shardMeta;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->cache, $this->checkpoints, $this->compaction, $this->db, $this->fieldIndex, $this->io, $this->layout, $this->layoutSwitch, $this->locks, $this->rtree, $this->scope, $this->segments, $this->shardMeta, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->bufferCountLimit = &$this->k->bufferCountLimit;
        $this->bufferEnabled = &$this->k->bufferEnabled;
        $this->bufferFlushInterval = &$this->k->bufferFlushInterval;
        $this->bufferSizeLimit = &$this->k->bufferSizeLimit;
        $this->indexCache = &$this->k->indexCache;
        $this->shardSize = &$this->k->shardSize;
        $this->shardingEnabled = &$this->k->shardingEnabled;
        $this->lockRegistry = &$this->rt->lockRegistry;
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
        $this->compaction = null;
        $this->db = null;
        $this->fieldIndex = null;
        $this->io = null;
        $this->layout = null;
        $this->layoutSwitch = null;
        $this->locks = null;
        $this->rtree = null;
        $this->scope = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // WRITE BUFFER PUBLIC API
    // ==========================================

    /**
     * Manually flush buffer for a database
     * @param string $dbname
     * @return array ['success' => bool, 'flushed' => int, 'error' => string|null]
     */
    public function flush($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            return $this->flushUnlocked($dbname);
        }, 'flush');
    }

    private function flushUnlocked($dbname){
        $flushed = $this->buffer->flushPendingBuffers($dbname);
        return ['success' => true, 'flushed' => $flushed, 'error' => null];
    }

    /**
     * Flush the write buffers of every database (of this secretKey) in dbDir
     * @return array ['databases' => int, 'flushed' => int]
     */
    public function flushAllBuffers(){
        $names = [];
        if(!empty($this->lockRegistry)){
            return ['databases' => 0, 'flushed' => 0]; // inside an interrupted operation (see releaseInterruptedSessions)
        }
        foreach($this->db->getDBs(false) as $dbname){
            $buffered = $this->locks->withLock($dbname, false, function() use ($dbname){
                return $this->buffer->hasBuffer($this->buffer->getBufferPath($dbname)) || !empty($this->buffer->flushingFiles($dbname));
            });
            if($buffered){
                $names[$dbname] = true;
            }
        }
        $total = 0;
        foreach(array_keys($names) as $dbname){
            $result = $this->flush((string)$dbname);
            $total += $result['flushed'];
        }
        return ['databases' => count($names), 'flushed' => $total];
    }

    /**
     * Get buffer information for a database
     * @param string $dbname
     * @return array
     */
    public function getBufferInfo($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        $fn = function() use ($dbname){
            return $this->getBufferInfoUnlocked($dbname);
        };
        return $this->locks->withLock($dbname, false, $fn);
    }

    private function getBufferInfoUnlocked($dbname){
        $info = [
            'enabled' => $this->bufferEnabled,
            'sizeLimit' => $this->bufferSizeLimit,
            'countLimit' => $this->bufferCountLimit,
            'flushInterval' => $this->bufferFlushInterval,
            'buffers' => []
        ];
        $describe = function($path){
            clearstatcache(true, $path);
            $exists = file_exists($path) && filesize($path) > 0;
            return [
                'exists' => $exists,
                'size' => $exists ? (int)filesize($path) : 0,
                'records' => $exists ? $this->buffer->getBufferRecordCount($path) : 0
            ];
        };
        $info['buffers']['main'] = $describe($this->buffer->getBufferPath($dbname));
        return $info;
    }

    /**
     * Enable or disable write buffering
     * @param bool $enable
     */
    public function enableBuffering($enable = true){
        $this->bufferEnabled = (bool)$enable;
    }

    /**
     * Check if buffering is enabled
     * @return bool
     */
    public function isBufferingEnabled(){
        return $this->bufferEnabled;
    }

    /**
     * Set buffer size limit (in bytes)
     * @param int $bytes
     */
    public function setBufferSizeLimit($bytes){
        $this->bufferSizeLimit = max(1024, (int)$bytes); // Minimum 1KB
    }

    /**
     * Set buffer flush interval (in seconds)
     * @param int $seconds 0 to disable time-based flush
     */
    public function setBufferFlushInterval($seconds){
        $this->bufferFlushInterval = max(0, (int)$seconds);
    }

    /**
     * Set buffer count limit
     * @param int $count
     */
    public function setBufferCountLimit($count){
        $this->bufferCountLimit = max(10, (int)$count); // Minimum 10 records
    }

    /**
     * Compact a database: drop old record versions and deleted records from the data files.
     * Keys are preserved (sharded and non-sharded alike); indexes stay valid.
     * @param string $dbname
     * @return array ['success' => bool, 'freedSlots' => int, 'totalRecords' => int, 'sharded' => bool]
     */
    public function compact($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            $this->scope->refuseInUncommittedWrite($dbname, 'compact');
            return $this->compactUnlocked($dbname);
        }, 'compact');
    }

    private function compactUnlocked($dbname){
        $result = array("success" => false, "freedSlots" => 0);
        $this->buffer->flushPendingBuffers($dbname);
        if(!$this->segments->dbExists($dbname)){
            $result['status'] = 'database_not_found';
            return $result;
        }

        $sharded = $this->segments->isSharded($dbname);
        $freed = 0;
        if($sharded){
            $meta = $this->shardMeta->getCachedMeta($dbname, true);
            $freed = (int)($meta['deletedCount'] ?? 0);
        }
        $total = 0;
        foreach($this->segments->listSegments($dbname) as $segment){
            $compacted = $this->compaction->compactJsonl($dbname, $segment);
            if(!$sharded){
                $freed += $compacted['freed'];
            }
            $total += $compacted['compacted'];
            $this->cache->evictBetweenSegments();
        }
        if($sharded){
            $this->removeEmptyShards($dbname);
            $meta = $this->shardMeta->getCachedMeta($dbname, true);
            foreach($meta['shards'] as $i => $shard){
                $meta['shards'][$i]['deleted'] = 0;
            }
            $meta['deletedCount'] = 0;
            $meta['totalRecords'] = $total;
            $this->shardMeta->writeMeta($dbname, $meta);
        }

        $result['success'] = true;
        $result['freedSlots'] = $freed;
        $result['totalRecords'] = $total;
        $result['sharded'] = $sharded;
        if($sharded){
            $result['newShardCount'] = count($this->segments->listSegments($dbname));
        }
        return $result;
    }

    /**
     * Remove shards without records (keys never move, so nothing else changes). The shard that holds
     * the newest key is kept: new records go there.
     */
    private function removeEmptyShards($dbname){
        $meta = $this->shardMeta->getCachedMeta($dbname, true);
        if($meta === null || count($meta['shards']) < 2){
            return;
        }
        $lastShard = intdiv(max(0, (int)$meta['nextKey'] - 1), (int)$meta['shardSize']);
        $kept = [];
        $removed = [];
        $done = false; // a shard was read: the cache may drop what it loaded before the next one
        foreach($meta['shards'] as $shard){
            if($done){
                $this->cache->evictBetweenSegments();
            }
            $done = true;
            $shardId = (int)$shard['id'];
            $index = $this->checkpoints->readJsonlIndex($dbname, $shardId);
            if($shardId !== $lastShard && ($index === null || \noneDB\Internal\Storage\KeyIndex::count($index) === 0)){
                $removed[] = $shardId;
            } else {
                $kept[] = $shard;
            }
        }
        if(empty($removed)){
            return;
        }
        $meta['shards'] = $kept;
        // The meta must not lose this change in a power loss after the files are gone (where PHP can sync)
        $this->shardMeta->writeMeta($dbname, $meta, true);

        $manifest = $this->fieldIndex->getIndexManifest($dbname);
        $this->locks->bumpEpoch($dbname); // shard files go away
        foreach($removed as $shardId){
            $dataPath = $this->segments->getShardPath($dbname, $shardId);
            foreach($manifest['fields'] as $field){
                $this->fieldIndex->deleteFieldIndexFile($dbname, $field, $shardId);
            }
            $this->rtree->removeSpatialShardFiles($dbname, $shardId);
            $this->io->fsUnlink(\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath));
            $this->io->fsUnlink($dataPath);
            $this->cache->markFileNotExists($dataPath);
            unset($this->indexCache[\noneDB\Internal\Storage\Checkpoints::ckptPath($dataPath)]);
        }
        // The shard list no longer names them: their removal only frees space. A power loss may bring them
        // back as files no shard entry names (set aside when a shard with that number is created again).
        foreach($manifest['fields'] as $field){
            $this->fieldIndex->rebuildGlobalFieldIndex($dbname, $field);
        }
    }
    /**
     * Switch a database of the single layout to the sharded layout (STORAGE_FORMAT.md §5.2)
     * @param string $dbname
     * @return array ["success" => bool, "status" => string]
     */
    public function convertToSharded($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            $this->scope->refuseInUncommittedWrite($dbname, 'convertToSharded');
            return $this->convertToShardedUnlocked($dbname);
        }, 'convertToSharded');
    }

    private function convertToShardedUnlocked($dbname){
        if($this->segments->isSharded($dbname)){
            return array("success" => true, "status" => "already_sharded");
        }
        $this->buffer->flushPendingBuffers($dbname);
        if($this->segments->isSharded($dbname)){
            return array("success" => true, "status" => "converted");
        }
        if(!$this->cache->cachedFileExists($this->segments->getDataPath($dbname))){
            return array("success" => false, "status" => "database_not_found");
        }
        if($this->layoutSwitch->convertToSharded($dbname)){
            return array("success" => true, "status" => "converted");
        }
        return array("success" => false, "status" => "conversion_failed");
    }

    /**
     * Check if sharding is enabled
     * @return bool
     */
    public function isShardingEnabled(){
        return $this->shardingEnabled;
    }

    /**
     * Get current shard size setting
     * @return int
     */
    public function getShardSize(){
        return $this->shardSize;
    }
}
}
namespace noneDB\Internal\Query {

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
}
namespace noneDB\Internal\Query {

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
}
namespace noneDB\Internal\Query {

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
}
namespace noneDB\Internal\Query {

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
}
namespace noneDB\Internal\Query {

use function abs, array_filter, array_key_exists, array_keys, array_map, array_slice, array_unique, array_values,
    asort, atan2, cos, count, deg2rad, fmod, in_array, is_array, is_finite, is_float, is_int, is_nan, max, min, round,
    sin, sort, sqrt, usort;
use const INF;

/**
 * @internal
 */
final class SpatialQueries {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $indexedCandidates;
    private $spatialIndexEnabled;
    private $cache;
    private $fieldIndex;
    private $geoJson;
    private $geometry;
    private $layout;
    private $locks;
    private $queryMethods;
    private $records;
    private $rtree;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->fieldIndex, $this->geoJson, $this->geometry, $this->layout, $this->locks, $this->queryMethods, $this->records, $this->rtree, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->indexedCandidates = &$this->k->indexedCandidates;
        $this->spatialIndexEnabled = &$this->k->spatialIndexEnabled;
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
        $this->geoJson = null;
        $this->geometry = null;
        $this->layout = null;
        $this->locks = null;
        $this->queryMethods = null;
        $this->records = null;
        $this->rtree = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // SPATIAL QUERIES
    // ==========================================

    /**
     * Records whose geometry may overlap the search boxes, in key order, one segment at a time (the cache may
     * drop what a segment loaded once it is done): yields key => record. Uses the R-tree (and shard boxes)
     * when the field is indexed; otherwise every record.
     * @param array $boxes List of MBRs
     * @return \Generator
     */
    private function spatialCandidates(string $dbname, string $field, array $boxes) {
        if (!$this->segments->dbExists($dbname)) {
            return;
        }
        $indexed = $this->spatialIndexEnabled && in_array($field, $this->fieldIndex->getIndexManifest($dbname)['spatial'], true);
        $this->indexedCandidates = [];
        $sharded = $this->segments->isSharded($dbname);
        $global = ($indexed && $sharded) ? $this->rtree->readGlobalSpatialIndex($dbname, $field) : null;

        $done = false; // a segment was read: the cache may drop what it loaded before the next one (not after the last)
        foreach ($this->segments->listSegments($dbname) as $segment) {
            if ($done) {
                $this->cache->evictBetweenSegments();
                $done = false;
            }
            $keys = null; // null: every record of the segment
            // Shard skip: only with an entry that fits the shard, misses the boxes, and no newer geometry of
            // the shard in them. A missing entry (or global file) means unknown: searched.
            if ($indexed && $global !== null && $this->rtree->globalSpatialSkips($dbname, $field, $segment, $global, $boxes)) {
                continue;
            }
            if ($indexed) {
                try {
                    $keys = $this->rtree->rtreeSearchMBR($dbname, $field, $boxes, $segment);
                    foreach ($keys as $key) {
                        $this->indexedCandidates[$key] = true;
                    }
                } catch (\Throwable $e) {
                    if ($e->getCode() === \noneDB::LOCK_FAILED) {
                        throw $e;
                    }
                    $keys = null; // the index can't be built: scan
                }
            }
            if ($keys === null || !empty($keys)) {
                foreach ($this->records->eachSegmentBatch($dbname, $segment, $keys) as $batch) {
                    yield from $batch;
                }
            }
            $done = true;
        }
    }

    /**
     * Search boxes for a circle; splits at the antimeridian and covers all longitudes near the poles
     * @return array List of [minLon, minLat, maxLon, maxLat]
     */
    private function circleToBBoxes(float $lon, float $lat, float $radiusMeters): array {
        $latDeg = $radiusMeters / 111000.0;
        $minLat = $lat - $latDeg;
        $maxLat = $lat + $latDeg;
        if ($minLat <= -90 || $maxLat >= 90) {
            return [[-180.0, max(-90.0, $minLat), 180.0, min(90.0, $maxLat)]];
        }
        $cos = cos(deg2rad(max(abs($minLat), abs($maxLat))));
        $lonDeg = $cos > 1e-9 ? $radiusMeters / (111000.0 * $cos) : 360.0;
        if ($lonDeg >= 180) {
            return [[-180.0, $minLat, 180.0, $maxLat]];
        }
        $minLon = $lon - $lonDeg;
        $maxLon = $lon + $lonDeg;
        if ($minLon < -180) {
            return [[-180.0, $minLat, $maxLon, $maxLat], [$minLon + 360.0, $minLat, 180.0, $maxLat]];
        }
        if ($maxLon > 180) {
            return [[$minLon, $minLat, 180.0, $maxLat], [-180.0, $minLat, $maxLon - 360.0, $maxLat]];
        }
        return [[$minLon, $minLat, $maxLon, $maxLat]];
    }

    /**
     * Distance in meters from a point to a geometry: 0 inside a polygon, otherwise to the nearest
     * vertex or edge (edges use a local equirectangular approximation)
     */
    public function distanceToGeometry(float $lon, float $lat, array $geometry): float {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        switch ($type) {
            case 'Point':
                return $this->geometry->haversineDistance($lon, $lat, (float)$coords[0], (float)$coords[1]);
            case 'MultiPoint':
                $best = INF;
                foreach ($coords as $p) {
                    $best = min($best, $this->geometry->haversineDistance($lon, $lat, (float)$p[0], (float)$p[1]));
                }
                return $best;
            case 'LineString':
                return $this->distanceToPath($lon, $lat, $coords);
            case 'MultiLineString':
                $best = INF;
                foreach ($coords as $line) {
                    $best = min($best, $this->distanceToPath($lon, $lat, $line));
                }
                return $best;
            case 'Polygon':
                if ($this->geometry->pointInPolygon($lon, $lat, $coords)) {
                    return 0.0;
                }
                $best = INF;
                foreach ($coords as $ring) {
                    $best = min($best, $this->distanceToPath($lon, $lat, $ring));
                }
                return $best;
            case 'MultiPolygon':
                $best = INF;
                foreach ($coords as $polygon) {
                    $best = min($best, $this->distanceToGeometry($lon, $lat, ['type' => 'Polygon', 'coordinates' => $polygon]));
                }
                return $best;
            case 'GeometryCollection':
                $best = INF;
                foreach ($geometry['geometries'] ?? [] as $g) {
                    $best = min($best, $this->distanceToGeometry($lon, $lat, $g));
                }
                return $best;
        }
        return INF;
    }

    /**
     * Distance from a point to a polyline (meters): to its nearest point, an edge being straight in
     * longitude/latitude. Exact up to floating point: every vertex is measured, and every edge whose
     * bounding box could hold a nearer point is searched (golden section on the haversine of the point at
     * t along the edge, which wraps at the antimeridian by itself); the other edges are skipped by a lower
     * bound of the haversine over their box.
     */
    private function distanceToPath(float $lon, float $lat, array $points): float {
        $n = count($points);
        if ($n === 0) {
            return INF;
        }
        $cosLat = cos(deg2rad($lat));
        $xs = [];
        $ys = [];
        $best = INF; // the haversine "a" of the nearest point found (monotonic in the distance)
        foreach ($points as $i => $point) {
            $xs[$i] = (float)$point[0];
            $ys[$i] = (float)$point[1];
            $best = min($best, $this->haversineA($lon, $lat, $cosLat, $xs[$i], $ys[$i]));
        }
        $points = array_keys($xs);
        $bounds = [];
        for ($e = 0; $e < $n - 1; $e++) {
            $i = $points[$e];
            $j = $points[$e + 1];
            $bound = $this->edgeBoxLowerBound($lon, $lat, $cosLat, $xs[$i], $ys[$i], $xs[$j], $ys[$j]);
            if ($bound < $best) {
                $bounds[$e] = $bound;
            }
        }
        asort($bounds);
        foreach ($bounds as $e => $bound) {
            if ($bound >= $best) {
                break; // every other edge is at least this far
            }
            $i = $points[$e];
            $j = $points[$e + 1];
            $best = min($best, $this->edgeMinimumA($lon, $lat, $cosLat, $xs[$i], $ys[$i], $xs[$j], $ys[$j]));
        }
        return 6371000.0 * (2 * atan2(sqrt($best), sqrt(1 - $best)));
    }

    /**
     * The "a" of haversineDistance() (the same operations, so a vertex gives the same distance)
     */
    private function haversineA(float $lon, float $lat, float $cosLat, float $x, float $y): float {
        $latDelta = deg2rad($y - $lat);
        $lonDelta = deg2rad($x - $lon);
        return sin($latDelta / 2) * sin($latDelta / 2) + $cosLat * cos(deg2rad($y)) * sin($lonDelta / 2) * sin($lonDelta / 2);
    }

    /**
     * A lower bound of haversineA() over the bounding box of an edge: a = hav(dlat) + cos(lat) cos(lat2)
     * hav(dlon), each term bounded by the box's smallest latitude and longitude difference to the point
     * and its smallest cosine
     */
    private function edgeBoxLowerBound(float $lon, float $lat, float $cosLat, float $ax, float $ay, float $bx, float $by): float {
        $loY = min($ay, $by);
        $hiY = max($ay, $by);
        $dLat = $lat < $loY ? $loY - $lat : ($lat > $hiY ? $lat - $hiY : 0.0);
        $loX = min($ax, $bx);
        $width = max($ax, $bx) - $loX;
        $dLon = 0.0;
        if ($width < 360) {
            $offset = fmod($lon - $loX, 360.0);
            if ($offset < 0) {
                $offset += 360.0;
            }
            if ($offset > $width) {
                $dLon = min($offset - $width, 360.0 - $offset);
            }
        }
        $cosMin = max(0.0, min(cos(deg2rad($loY)), cos(deg2rad($hiY))));
        $sLat = sin(deg2rad($dLat) / 2);
        $sLon = sin(deg2rad($dLon) / 2);
        return $sLat * $sLat + $cosLat * $cosMin * $sLon * $sLon;
    }

    /**
     * The smallest haversineA() of the points of an edge: golden-section search over t (60 steps: t to
     * about 1e-13), plus the point a local flat projection picks, whichever is nearer
     */
    private function edgeMinimumA(float $lon, float $lat, float $cosLat, float $ax, float $ay, float $bx, float $by): float {
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $at = function (float $t) use ($lon, $lat, $cosLat, $ax, $ay, $dx, $dy) {
            return $this->haversineA($lon, $lat, $cosLat, $ax + $dx * $t, $ay + $dy * $t);
        };
        // Local projection around the point, the longitude of the edge's start taken on the point's side
        $px = fmod($ax - $lon + 540.0, 360.0) - 180.0;
        $qx = ($px + $dx) * $cosLat;
        $px *= $cosLat;
        $py = $ay - $lat;
        $qy = $by - $lat;
        $ex = $qx - $px;
        $ey = $qy - $py;
        $len2 = $ex * $ex + $ey * $ey;
        $best = $at($len2 > 0 ? max(0.0, min(1.0, -($px * $ex + $py * $ey) / $len2)) : 0.0);
        $ratio = 0.6180339887498949;
        $lo = 0.0;
        $hi = 1.0;
        $c = $hi - $ratio * ($hi - $lo);
        $d = $lo + $ratio * ($hi - $lo);
        $fc = $at($c);
        $fd = $at($d);
        for ($step = 0; $step < 60; $step++) {
            if ($fc < $fd) {
                $hi = $d;
                $d = $c;
                $fd = $fc;
                $c = $hi - $ratio * ($hi - $lo);
                $fc = $at($c);
            } else {
                $lo = $c;
                $c = $d;
                $fc = $fd;
                $d = $lo + $ratio * ($hi - $lo);
                $fd = $at($d);
            }
        }
        return min($best, $fc, $fd);
    }

    /**
     * Find records within a distance from a point (distance to the nearest point of the geometry)
     * @param string $dbname Database name
     * @param string $field GeoJSON field name
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @param float $distanceMeters Distance in meters
     * @param array $options ['includeDistance' => false]
     * @return array Matching records (sorted by distance, with _distance in meters if includeDistance=true)
     */
    public function withinDistance(string $dbname, string $field, float $lon, float $lat, float $distanceMeters, array $options = []): array{
        \noneDB\Internal\Config\Configuration::checkNames($options, ['includeDistance'], 'option', ' of withinDistance()');
        if(array_key_exists('includeDistance', $options)){
            \noneDB\Internal\Config\Configuration::optionBool('includeDistance', $options['includeDistance']);
        }
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $lon, $lat, $distanceMeters, $options){
            return $this->withinDistanceUnlocked($dbname, $field, $lon, $lat, $distanceMeters, $options);
        });
    }

    private function withinDistanceUnlocked(string $dbname, string $field, float $lon, float $lat, float $distanceMeters, array $options = []): array{
        $includeDistance = $options['includeDistance'] ?? false;
        if ($distanceMeters < 0 || is_nan($distanceMeters) || !is_finite($lon) || !is_finite($lat)) {
            return [];
        }
        $distanceMeters = min($distanceMeters, self::EARTH_HALF_CIRCUMFERENCE); // infinite: everything
        $results = [];
        foreach ($this->spatialCandidates($dbname, $field, $this->circleToBBoxes($lon, $lat, $distanceMeters)) as $record) {
            $geometry = $record[$field] ?? null;
            if (!isset($this->indexedCandidates[$record['key']]) && (!is_array($geometry) || !$this->geoJson->validateGeoJSON($geometry)['valid'])) {
                continue; // a candidate of an R-tree has a valid geometry: checked when it was indexed
            }
            $distance = $this->distanceToGeometry($lon, $lat, $geometry);
            if (is_finite($distance) && $distance <= $distanceMeters) {
                $record['_distance'] = round($distance, 2);
                $results[] = $record;
            }
        }
        usort($results, function ($a, $b) {
            return $a['_distance'] <=> $b['_distance'] ?: $a['key'] <=> $b['key'];
        });
        if (!$includeDistance) {
            foreach ($results as &$r) {
                unset($r['_distance']);
            }
            unset($r);
        }
        return $results;
    }

    /**
     * Find records within a bounding box (geometries whose bounding box overlaps it).
     * minLon > maxLon means the box crosses the antimeridian.
     * @return array Matching records
     */
    public function withinBBox(string $dbname, string $field, float $minLon, float $minLat, float $maxLon, float $maxLat): array{
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $minLon, $minLat, $maxLon, $maxLat){
            return $this->withinBBoxUnlocked($dbname, $field, $minLon, $minLat, $maxLon, $maxLat);
        });
    }

    private function withinBBoxUnlocked(string $dbname, string $field, float $minLon, float $minLat, float $maxLon, float $maxLat): array{
        if (is_nan($minLon) || is_nan($minLat) || is_nan($maxLon) || is_nan($maxLat)) {
            return [];
        }
        // Infinite edges reach the edge of the map
        $minLon = max(-180.0, min(180.0, $minLon));
        $maxLon = max(-180.0, min(180.0, $maxLon));
        $minLat = max(-90.0, min(90.0, $minLat));
        $maxLat = max(-90.0, min(90.0, $maxLat));
        if ($minLat > $maxLat) {
            [$minLat, $maxLat] = [$maxLat, $minLat];
        }
        $boxes = $minLon <= $maxLon
            ? [[$minLon, $minLat, $maxLon, $maxLat]]
            : [[$minLon, $minLat, 180.0, $maxLat], [-180.0, $minLat, $maxLon, $maxLat]];

        $results = [];
        foreach ($this->spatialCandidates($dbname, $field, $boxes) as $record) {
            $geometry = $record[$field] ?? null;
            if (!isset($this->indexedCandidates[$record['key']]) && (!is_array($geometry) || !$this->geoJson->validateGeoJSON($geometry)['valid'])) {
                continue; // a candidate of an R-tree has a valid geometry: checked when it was indexed
            }
            $recordMBR = $this->geometry->calculateMBR($geometry);
            if ($recordMBR === null) {
                continue;
            }
            foreach ($boxes as $box) {
                if ($this->geometry->mbrOverlaps($recordMBR, $box)) {
                    $results[] = $record;
                    break;
                }
            }
        }
        return $results;
    }

    /**
     * Find K nearest records to a point
     * @param string $dbname Database name
     * @param string $field GeoJSON field name
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @param int $k Number of nearest records
     * @param array $options ['maxDistance' => null (meters), 'includeDistance' => true]
     * @return array Up to k records sorted by distance (fewer when maxDistance cuts them off)
     */
    public function nearest(string $dbname, string $field, float $lon, float $lat, int $k, array $options = []): array{
        \noneDB\Internal\Config\Configuration::checkNames($options, ['maxDistance', 'includeDistance'], 'option', ' of nearest()');
        if(array_key_exists('maxDistance', $options) && $options['maxDistance'] !== null && !is_int($options['maxDistance']) && !is_float($options['maxDistance'])){
            throw new \InvalidArgumentException("noneDB: 'maxDistance' must be a number of meters or null");
        }
        if(array_key_exists('includeDistance', $options)){
            \noneDB\Internal\Config\Configuration::optionBool('includeDistance', $options['includeDistance']);
        }
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $lon, $lat, $k, $options){
            return $this->nearestUnlocked($dbname, $field, $lon, $lat, $k, $options);
        });
    }

    /**
     * True when spatial queries on the field use the R-tree (used by the query builder)
     */
    public function usesSpatialIndex(string $dbname, string $field): bool{
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field){
            return $this->spatialIndexEnabled && $this->segments->dbExists($dbname)
                && in_array($field, $this->fieldIndex->getIndexManifest($dbname)['spatial'], true);
        });
    }

    private function nearestUnlocked(string $dbname, string $field, float $lon, float $lat, int $k, array $options = []): array{
        $maxDistance = isset($options['maxDistance']) ? (float)$options['maxDistance'] : null; // an int or a float (nearest())
        $includeDistance = $options['includeDistance'] ?? true;
        // NaN matches nothing (and NaN radii made the expanding search below loop forever)
        if ($k <= 0 || !is_finite($lon) || !is_finite($lat) || ($maxDistance !== null && is_nan($maxDistance)) || !$this->segments->dbExists($dbname)) {
            return [];
        }

        $indexed = $this->spatialIndexEnabled && in_array($field, $this->fieldIndex->getIndexManifest($dbname)['spatial'], true);
        // Half the earth's circumference covers everything (also an infinite maxDistance)
        $limit = min($maxDistance ?? self::EARTH_HALF_CIRCUMFERENCE, self::EARTH_HALF_CIRCUMFERENCE);
        $results = [];

        if ($indexed && $k < $this->queryMethods->countFast($dbname)) {
            // Expanding search: nothing outside radius r is closer than r. Results are ordered by the
            // rounded distance, so only records rounded below round(r) are certainly in final order
            // (a record just outside r can round to the same value and has to win a key tie).
            $radius = 1000.0;
            while (true) {
                $radius = min($radius, $limit);
                $found = $this->withinDistanceUnlocked($dbname, $field, $lon, $lat, $radius, ['includeDistance' => true]);
                if ($radius >= $limit || (count($found) >= $k && $found[$k - 1]['_distance'] < round($radius, 2))) {
                    $results = $found;
                    break;
                }
                $radius *= 4;
            }
        } else {
            // Every record by its distance; only the best k are kept (pruned as they come)
            $order = function ($a, $b) {
                return $a['_distance'] <=> $b['_distance'] ?: $a['key'] <=> $b['key'];
            };
            $prune = max(4 * $k, 1024);
            foreach ($this->segments->listSegments($dbname) as $segment) {
                foreach ($this->records->eachSegmentBatch($dbname, $segment) as $batch) {
                    foreach ($batch as $record) {
                        $geometry = $record[$field] ?? null;
                        if (!is_array($geometry) || !$this->geoJson->validateGeoJSON($geometry)['valid']) {
                            continue;
                        }
                        $distance = $this->distanceToGeometry($lon, $lat, $geometry);
                        if (is_finite($distance) && ($maxDistance === null || $distance <= $maxDistance)) {
                            $record['_distance'] = round($distance, 2);
                            $results[] = $record;
                            if (count($results) > $prune) {
                                usort($results, $order);
                                $results = array_slice($results, 0, $k);
                            }
                        }
                    }
                }
                $this->cache->evictBetweenSegments();
            }
            usort($results, $order);
        }

        $results = array_slice($results, 0, $k);
        if (!$includeDistance) {
            foreach ($results as &$r) {
                unset($r['_distance']);
            }
            unset($r);
        }
        return $results;
    }

    /**
     * Find records whose geometry lies within a Polygon or MultiPolygon (holes excluded)
     * @param string $dbname Database name
     * @param string $field GeoJSON field name
     * @param array $polygon GeoJSON Polygon or MultiPolygon geometry
     * @return array Matching records
     */
    public function withinPolygon(string $dbname, string $field, array $polygon): array{
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname, $field, $polygon){
            return $this->withinPolygonUnlocked($dbname, $field, $polygon);
        });
    }

    private function withinPolygonUnlocked(string $dbname, string $field, array $polygon): array{
        $validation = $this->geoJson->validateGeoJSON($polygon);
        if (!$validation['valid'] || !in_array($validation['type'], ['Polygon', 'MultiPolygon'], true)) {
            return [];
        }
        $polygons = $validation['type'] === 'Polygon' ? [$polygon['coordinates']] : $polygon['coordinates'];
        $boxes = [];
        foreach ($polygons as $coords) {
            $boxes[] = $this->geometry->mbrFromPoints($coords[0]);
        }

        // One point inside each hole of the query: a record polygon that contains one covers part of the hole
        $parts = [];
        foreach ($polygons as $coords) {
            $holePoints = [];
            for ($h = 1; $h < count($coords); $h++) {
                $point = $this->pointInsideRing($coords[$h]);
                if ($point !== null) {
                    $holePoints[] = $point;
                }
            }
            $parts[] = ['coords' => $coords, 'holePoints' => $holePoints];
        }

        $results = [];
        foreach ($this->spatialCandidates($dbname, $field, $boxes) as $record) {
            $geometry = $record[$field] ?? null;
            if (!isset($this->indexedCandidates[$record['key']]) && (!is_array($geometry) || !$this->geoJson->validateGeoJSON($geometry)['valid'])) {
                continue; // a candidate of an R-tree has a valid geometry: checked when it was indexed
            }
            if ($this->geometryWithinParts($geometry, $parts)) {
                $results[] = $record;
            }
        }
        return $results;
    }

    /**
     * Within a Polygon or MultiPolygon query (its parts): a single geometry must fit one part; every
     * component of a multi-part geometry must fit some part. A component spanning two adjacent
     * parts doesn't fit (the union of the parts isn't computed).
     */
    private function geometryWithinParts(array $geometry, array $parts): bool {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        $components = null;
        if ($type === 'MultiPoint') {
            $components = array_map(function ($p) { return ['type' => 'Point', 'coordinates' => $p]; }, $coords);
        } elseif ($type === 'MultiLineString') {
            $components = array_map(function ($l) { return ['type' => 'LineString', 'coordinates' => $l]; }, $coords);
        } elseif ($type === 'MultiPolygon') {
            $components = array_map(function ($p) { return ['type' => 'Polygon', 'coordinates' => $p]; }, $coords);
        } elseif ($type === 'GeometryCollection') {
            // Members without a position don't count; a collection without any position is never within
            $components = array_values(array_filter($geometry['geometries'] ?? [], function ($g) {
                return is_array($g) && $this->geometry->calculateMBR($g) !== null;
            }));
        }
        if ($components !== null) {
            if (empty($components)) {
                return false;
            }
            foreach ($components as $component) {
                if (!$this->geometryWithinParts($component, $parts)) {
                    return false;
                }
            }
            return true;
        }
        foreach ($parts as $part) {
            if ($this->geometryWithinPolygon($geometry, $part['coords'], $part['holePoints'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * A point strictly inside a ring: the middle of the first span a horizontal line between the two
     * lowest distinct y values has inside the ring. Null for a degenerate ring.
     */
    private function pointInsideRing(array $ring): ?array {
        $ys = array_values(array_unique(array_map(function ($p) { return (float)$p[1]; }, $ring)));
        sort($ys);
        if (count($ys) < 2) {
            return null;
        }
        $y = ($ys[0] + $ys[1]) / 2;
        $xs = [];
        $n = count($ring);
        for ($i = 0; $i < $n - 1; $i++) {
            [$x1, $y1] = [(float)$ring[$i][0], (float)$ring[$i][1]];
            [$x2, $y2] = [(float)$ring[$i + 1][0], (float)$ring[$i + 1][1]];
            if (($y1 <= $y && $y2 > $y) || ($y2 <= $y && $y1 > $y)) {
                $xs[] = $x1 + ($y - $y1) / ($y2 - $y1) * ($x2 - $x1);
            }
        }
        sort($xs);
        return count($xs) >= 2 ? [($xs[0] + $xs[1]) / 2, $y] : null;
    }

    /**
     * Check if a geometry lies entirely within a polygon. The polygon is closed (its boundary, also a
     * hole's boundary, belongs to it, as in withinBBox). Every vertex is inside, no edge crosses a ring of
     * the polygon, and every piece of an edge between polygon vertices on it is inside; a record polygon
     * must also not contain a point inside one of the polygon's holes ($holePoints).
     */
    private function geometryWithinPolygon(array $geometry, array $polygonCoords, ?array $holePoints = null): bool {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        if ($holePoints === null) {
            $holePoints = [];
            for ($h = 1; $h < count($polygonCoords); $h++) {
                $point = $this->pointInsideRing($polygonCoords[$h]);
                if ($point !== null) {
                    $holePoints[] = $point;
                }
            }
        }

        switch ($type) {
            case 'Point':
                return $this->pointInPolygonClosed((float)$coords[0], (float)$coords[1], $polygonCoords);
            case 'MultiPoint':
                foreach ($coords as $point) {
                    if (!$this->pointInPolygonClosed((float)$point[0], (float)$point[1], $polygonCoords)) {
                        return false;
                    }
                }
                return true;
            case 'LineString':
                return $this->pathWithinPolygon($coords, $polygonCoords);
            case 'MultiLineString':
                foreach ($coords as $line) {
                    if (!$this->pathWithinPolygon($line, $polygonCoords)) {
                        return false;
                    }
                }
                return true;
            case 'Polygon':
                return $this->polygonWithinPolygon($coords, $polygonCoords, $holePoints);
            case 'MultiPolygon':
                foreach ($coords as $poly) {
                    if (!$this->polygonWithinPolygon($poly, $polygonCoords, $holePoints)) {
                        return false;
                    }
                }
                return true;
            case 'GeometryCollection':
                // Members without a position don't count; a collection without any position is never within
                $located = false;
                foreach ($geometry['geometries'] ?? [] as $g) {
                    if (is_array($g) && $this->geometry->calculateMBR($g) === null) {
                        continue;
                    }
                    if (!$this->geometryWithinPolygon($g, $polygonCoords)) {
                        return false;
                    }
                    $located = true;
                }
                return $located;
        }
        return false;
    }

    /**
     * A polygon (record) within a polygon (query): all its rings within, and no point inside one of the
     * query's holes inside it (a record around a hole has its whole boundary inside the query)
     */
    private function polygonWithinPolygon(array $rings, array $polygonCoords, array $holePoints): bool {
        foreach ($rings as $ring) {
            if (!$this->pathWithinPolygon($ring, $polygonCoords)) {
                return false;
            }
        }
        foreach ($holePoints as [$x, $y]) {
            if ($this->geometry->pointInPolygon($x, $y, $rings)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Point inside a polygon or on its boundary (holes excluded, their boundary included)
     */
    private function pointInPolygonClosed(float $lon, float $lat, array $polygonCoords): bool {
        if (!$this->geoJson->pointOnRing($lon, $lat, $polygonCoords[0]) && !$this->geometry->pointInRing($lon, $lat, $polygonCoords[0])) {
            return false;
        }
        for ($i = 1; $i < count($polygonCoords); $i++) {
            if ($this->geometry->pointInRing($lon, $lat, $polygonCoords[$i]) && !$this->geoJson->pointOnRing($lon, $lat, $polygonCoords[$i])) {
                return false;
            }
        }
        return true;
    }

    /**
     * A path within the closed polygon: every vertex inside, no segment properly crossing a ring, and
     * every piece of a segment between polygon vertices lying on it inside (a segment can run along a
     * boundary or through a hole between two of its vertices)
     */
    private function pathWithinPolygon(array $points, array $polygonCoords): bool {
        foreach ($points as $point) {
            if (!$this->pointInPolygonClosed((float)$point[0], (float)$point[1], $polygonCoords)) {
                return false;
            }
        }
        $n = count($points);
        for ($i = 0; $i < $n - 1; $i++) {
            $a = [(float)$points[$i][0], (float)$points[$i][1]];
            $b = [(float)$points[$i + 1][0], (float)$points[$i + 1][1]];
            $ts = [0.0, 1.0];
            $alongX = abs($b[0] - $a[0]) >= abs($b[1] - $a[1]);
            foreach ($polygonCoords as $ring) {
                $m = count($ring);
                for ($j = 0; $j < $m - 1; $j++) {
                    if ($this->segmentsCrossProperly($a, $b, $ring[$j], $ring[$j + 1])) {
                        return false;
                    }
                    $v = [(float)$ring[$j][0], (float)$ring[$j][1]];
                    if ($this->geometry->crossProductDirection($a, $b, $v) == 0 && $this->geometry->onSegment($a, $b, $v) && $a != $b) {
                        $ts[] = $alongX ? ($v[0] - $a[0]) / ($b[0] - $a[0]) : ($v[1] - $a[1]) / ($b[1] - $a[1]);
                    }
                }
            }
            if (count($ts) > 2) {
                sort($ts);
                for ($k = 0; $k < count($ts) - 1; $k++) {
                    if ($ts[$k + 1] - $ts[$k] <= 0) {
                        continue;
                    }
                    $t = ($ts[$k] + $ts[$k + 1]) / 2;
                    if (!$this->pointInPolygonClosed($a[0] + $t * ($b[0] - $a[0]), $a[1] + $t * ($b[1] - $a[1]), $polygonCoords)) {
                        return false;
                    }
                }
            } elseif (!$this->pointInPolygonClosed(($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2, $polygonCoords)) {
                return false; // e.g. a chord between two boundary vertices across a concave notch
            }
        }
        return true;
    }

    /**
     * True when two segments cross at a single interior point (touching endpoints don't count)
     */
    private function segmentsCrossProperly(array $p1, array $p2, array $p3, array $p4): bool {
        $d1 = $this->geometry->crossProductDirection($p3, $p4, $p1);
        $d2 = $this->geometry->crossProductDirection($p3, $p4, $p2);
        $d3 = $this->geometry->crossProductDirection($p1, $p2, $p3);
        $d4 = $this->geometry->crossProductDirection($p1, $p2, $p4);
        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
    }

    public const EARTH_HALF_CIRCUMFERENCE = 20040000.0; // meters: no two points are farther apart
}
}
namespace noneDB\Internal\Query {

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
}
namespace noneDB\Internal\Query {

use function bin2hex, count, function_exists, is_array, is_bool, is_float, is_int, is_nan, is_numeric,
    is_object, is_string, json_encode, mb_convert_case, pack, preg_last_error, preg_last_error_msg, preg_match,
    preg_quote, spl_object_id, str_replace, strcmp, strlen, strncmp, strpos, substr;
use const MB_CASE_FOLD, PREG_BAD_UTF8_ERROR, PREG_BAD_UTF8_OFFSET_ERROR, PREG_NO_ERROR;

/**
 * @internal
 */
final class Values {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;


    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
    }

    /**
     * Drop the handles to the kernel and the other services (the instance is gone)
     */
    public function unbind(): void {
        $this->k = null;
        $this->rt = null;
    }


    /**
     * valueKey() for the query builder
     * @return \Closure
     */
    public function valueKeyFunction(){
        return \Closure::fromCallable([self::class, 'valueKey']);
    }

    /**
     * Add the records' values of a field to the distinct values seen so far (null excluded), in first-seen
     * order, same identity as in_array($value, $values, true), in linear time ($seen: a hash set of their
     * valueKey()s): distinct() batch by batch, the records never held together
     */
    public static function collectUnique(array $records, $field, array &$seen, array &$values){
        foreach($records as $record){
            if(isset($record[$field])){
                $id = self::valueKey($record[$field]);
                if(!isset($seen[$id])){
                    $seen[$id] = true;
                    $values[] = $record[$field];
                }
            }
        }
    }

    /**
     * A string that is equal for two values exactly when they are identical (===): typed, -0.0 is 0.0,
     * arrays by their typed keys and values in order (lengths make the encoding unambiguous).
     * Floats by their bits, so neither precision nor locale settings matter.
     */
    public static function valueKey($v){
        if($v === null) return 'n';
        if($v === true) return 't';
        if($v === false) return 'f';
        if(is_int($v)) return 'i' . $v;
        if(is_float($v)) return 'd' . bin2hex(pack('E', $v + 0.0));
        if(is_string($v)) return 's' . $v;
        if(is_array($v)){
            $key = 'a' . count($v) . ':';
            foreach($v as $k => $item){
                $kk = (is_int($k) ? 'i' : 's') . $k;
                $id = self::valueKey($item);
                $key .= strlen($kk) . ':' . $kk . strlen($id) . ':' . $id;
            }
            return $key;
        }
        return is_object($v) ? 'o' . spl_object_id($v) : 'r' . (int)$v; // identical only to itself
    }

    /**
     * Total order used by sort(), min() and max() (and the query builder): missing/null < booleans <
     * numbers < numeric strings (by their number) < other strings (byte order) < arrays.
     * @return int
     */
    public static function compareSortValues($a, $b){
        return self::sortKey($a) <=> self::sortKey($b);
    }

    /**
     * Sort key of a value: [kind, comparable value]. Two keys compare with <=> in the order above
     * (kind first, then the value; arrays by their JSON), so sorts compute each key once instead of
     * classifying both values in every comparison.
     * @return array
     */
    public static function sortKey($v){
        if($v === null) return [0, 0];
        if(is_bool($v)) return [1, $v];
        if(is_int($v) || is_float($v)) return [2, $v];
        if(is_string($v)){
            // Numeric strings (is_numeric()) never meet other strings, so <=> never mixes numeric and byte
            // comparison (mixed, "9" < "10" < "1a" < "9" would be a cycle).
            return is_numeric($v) ? [3, $v + 0] : [4, $v];
        }
        return [5, (string)json_encode($v)];
    }

    /**
     * sortKey() for the query builder
     * @return \Closure
     */
    public function sortKeyFunction(){
        return \Closure::fromCallable([self::class, 'sortKey']);
    }
    /**
     * True when mbstring can be used for Unicode case folding (like(), search())
     * @internal used by noneDBQuery; not part of the public API
     */
    public static function hasMbstring(): bool {
        return function_exists('mb_convert_case');
    }
    /**
     * Compare two values for ordering operators ($gt, between, ...).
     * Numbers (and numeric strings) compare numerically, other strings lexically; anything else
     * (null, booleans, arrays, number vs. non-numeric string) is not comparable.
     * @return int|null
     */
    public static function compareValues($a, $b): ?int {
        if ($a === null || $b === null || is_bool($a) || is_bool($b) || is_array($a) || is_array($b) || is_object($a) || is_object($b)) {
            return null;
        }
        // NAN compares as greater than everything with <=> ($gt NAN matched every number)
        if ((is_float($a) && is_nan($a)) || (is_float($b) && is_nan($b))) {
            return null;
        }
        $numA = self::numberOf($a);
        $numB = self::numberOf($b);
        if ($numA !== null && $numB !== null) {
            return $numA <=> $numB;
        }
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }
        return null;
    }
    /**
     * The number of an int, float or numeric string (is_numeric(): leading and trailing whitespace
     * allowed), else null. The sort order and range indexes use the same rule.
     * @return int|float|null
     */
    public static function numberOf($value) {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return is_numeric($value) ? $value + 0 : null;
        }
        return null;
    }
    /**
     * Run preg_match, turning compile errors and backtrack-limit failures into exceptions
     */
    public static function regexMatch(string $regex, string $subject): bool {
        $result = @preg_match($regex, $subject);
        if ($result === false) {
            $error = preg_last_error();
            if ($error === PREG_BAD_UTF8_ERROR || $error === PREG_BAD_UTF8_OFFSET_ERROR) {
                return false; // subject isn't UTF-8: can't match a UTF-8 pattern
            }
            if ($error === PREG_NO_ERROR) {
                throw new \InvalidArgumentException('noneDB: invalid regular expression');
            }
            throw new \RuntimeException('noneDB: regular expression failed (' . preg_last_error_msg() . ')');
        }
        return $result === 1;
    }
    /**
     * Case folding for matching (Unicode full folding: "ß" and "ẞ" are "ss", "ſ" is "s", "ς" is "σ").
     * "İ" folds to "i" + combining dot (U+0307); the dot is dropped so "İzmir", "IZMIR" and "izmir" all
     * match each other. Language independent: "I" and "ı" don't match.
     */
    public static function foldCase(string $s): string {
        return str_replace("\u{0307}", '', mb_convert_case($s, MB_CASE_FOLD, 'UTF-8'));
    }
    /**
     * Compile a like() pattern: a leading "^" anchors the start, a trailing "$" the end, everything else
     * is literal and case-insensitive.
     * @return array
     */
    public static function compileLike(string $pattern): array {
        $start = $pattern !== '' && $pattern[0] === '^';
        $end = strlen($pattern) > ($start ? 1 : 0) && substr($pattern, -1) === '$';
        $body = (string)substr($pattern, $start ? 1 : 0, strlen($pattern) - ($start ? 1 : 0) - ($end ? 1 : 0));
        $utf8 = preg_match('//u', $body) === 1;
        return [
            'start' => $start, 'end' => $end,
            // Folded body for UTF-8 subjects (null: no mbstring, or the pattern isn't UTF-8)
            'folded' => $utf8 && self::hasMbstring() ? self::foldCase($body) : null,
            // Otherwise a regex (PCRE /iu: simple case folding)
            // ("D": the end anchor is the end of the value, not also before a final newline)
            'regex' => '~' . ($start ? '^' : '') . preg_quote($body, '~') . ($end ? '$' : '') . '~D' . ($utf8 ? 'iu' : 'i'),
        ];
    }
    /**
     * Match a compiled like() pattern
     */
    public static function likeMatch(array $like, string $subject): bool {
        if ($like['folded'] === null || preg_match('//u', $subject) !== 1) {
            return self::regexMatch($like['regex'], $subject);
        }
        $t = self::foldCase($subject);
        $b = $like['folded'];
        if ($like['start'] && $like['end']) {
            return $t === $b;
        }
        if ($like['start']) {
            return strncmp($t, $b, strlen($b)) === 0;
        }
        if ($like['end']) {
            return $b === '' || (strlen($t) >= strlen($b) && substr($t, -strlen($b)) === $b);
        }
        return $b === '' || strpos($t, $b) !== false;
    }
}
}
namespace noneDB\Internal\Recovery {

use function array_filter, array_keys, array_map, array_values, basename, bin2hex, clearstatcache, fclose,
    fflush, file_exists, filesize, fopen, fread, fseek, fwrite, hash_file, hash_final, hash_init, hash_update,
    in_array, is_dir, is_file, min, random_bytes, rename, rtrim, strlen, substr, unlink;

/**
 * @internal
 */
final class Recovery {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $durable;
    private $lockRegistry;
    private $noWrites;
    private $cache;
    private $checkpoints;
    private $io;
    private $layout;
    private $locks;
    private $recoveryLog;
    private $segments;
    private $shardMeta;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->io, $this->layout, $this->locks, $this->recoveryLog, $this->segments, $this->shardMeta, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->durable = &$this->k->durable;
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
        $this->cache = null;
        $this->checkpoints = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->recoveryLog = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }



    /**
     * Recovery of a database a writer left half-updated (journal j/J; caller holds the exclusive lock):
     * key indexes are checked against the data (field and spatial indexes follow by themselves), temp
     * files of interrupted atomic writes are removed and the shard counters recounted from the data
     */
    public function recoverPendingIndexes($dbname, $path){
        $pending = $this->lockRegistry[$path]['flags']['j'] ?? [];
        $known = $this->segments->listSegments($dbname);
        $segments = isset($pending['*']) ? $known : array_map(function($segment){
            return $segment === 'main' ? null : (int)substr($segment, 1);
        }, array_keys($pending));
        $segments = array_values(array_filter($segments, function($shardId) use ($known){
            return in_array($shardId, $known, true);
        }));
        foreach($segments as $shardId){
            $this->checkpoints->readJsonlIndex($dbname, $shardId);
            $this->cache->evictBetweenSegments();
        }
        $home = $this->layout->dbHome($dbname);
        $gen = $this->layout->genDir($dbname);
        foreach([$home, $home . 'recovery/', $gen, $gen . 'segments/', $gen . 'indexes/', $gen . 'spatial/', $gen . 'buffer/'] as $dir){
            $this->removeStaleTemp($dir);
        }
        if($this->segments->isSharded($dbname)){
            // A write may have died between its data and its counters
            $this->shardMeta->recountMeta($dbname);
        }
        unset($this->lockRegistry[$path]['flags']['j']);
        $this->locks->saveLockState($path);
    }

    /**
     * Remove bytes [$from, $to) at the end of a data or buffer file, keeping them in a quarantine file
     * first (exclusive lock). Fails closed: if the bytes can't be stored and verified, nothing is cut
     * and the write that needed the cut is refused.
     * @throws \RuntimeException
     */
    public function quarantineAndCut($dbname, $shardId, $path, $from, $to, $action = 'cut-tail'){
        $quarantine = $this->quarantineBytes($dbname, $shardId, $path, $from, $to);
        if(!$this->io->fsTruncate($path, $from)){
            throw new \RuntimeException("noneDB: could not cut the torn end of a file of database '{$dbname}'");
        }
        $this->recoveryLog->logRecoveryEvent($dbname, ['action' => $action, 'id' => $quarantine['id'], 'file' => basename($path),
            'quarantine' => $quarantine['file'], 'bytesVerified' => true, 'powerLossDurable' => $quarantine['durable']], $quarantine['id']);
        return $quarantine;
    }

    /**
     * Copy bytes [$from, $to) of a file into recovery/q.{segment}.{offset}.{length}.{sha256}.bin
     * (segment: main, s{N}, or buffer for a buffer file: $shardId 'b')
     * and verify the copy. An existing file with the same name is reused only if its bytes match.
     * @return array ['id' => string, 'file' => string, 'durable' => true|false|'unknown']
     * @throws \RuntimeException
     */
    public function quarantineBytes($dbname, $shardId, $path, $from, $to){
        $length = $to - $from;
        $seg = $shardId === 'b' ? 'buffer' : \noneDB\Internal\Storage\Layout::segmentName($shardId);
        $src = @fopen($path, 'rb');
        if($src === false){
            throw new \RuntimeException("noneDB: could not read bytes to quarantine in database '{$dbname}'");
        }
        $dir = $this->layout->dbHome($dbname) . 'recovery/';
        if(!is_dir($dir)){
            $this->io->fsMkdir($dir);
        }
        $tmp = $dir . 'q.tmp' . bin2hex(random_bytes(4));
        $this->locks->noteWrite($tmp);
        $out = null;
        try {
            $out = @fopen($tmp, 'xb');
            if($out === false){
                throw new \RuntimeException("noneDB: could not create a quarantine file for database '{$dbname}'");
            }
            $this->io->setPermissions($tmp);
            $hash = hash_init('sha256');
            fseek($src, $from);
            $left = $length;
            while($left > 0){
                $chunk = (string)fread($src, min(65536, $left));
                if($chunk === ''){
                    throw new \RuntimeException("noneDB: could not read bytes to quarantine in database '{$dbname}'");
                }
                hash_update($hash, $chunk);
                if(fwrite($out, $chunk) !== strlen($chunk)){
                    throw new \RuntimeException("noneDB: could not write a quarantine file for database '{$dbname}'");
                }
                $left -= strlen($chunk);
            }
            $sha = hash_final($hash);
            // Synced in both modes (DURABILITY.md §3): required in durable mode, else reported in the log
            $fileSynced = fflush($out) ? $this->sync->syncHandle($out, $tmp) : false;
            fclose($out);
            $out = null;
            if($this->durable){
                $this->sync->noteRequired('file', $fileSynced === true);
                if($fileSynced !== true){
                    throw $this->sync->failure('file', $tmp, false);
                }
            }

            $id = "{$seg}.{$from}.{$length}.{$sha}";
            $name = "q.{$id}.bin";
            $target = $dir . $name;
            clearstatcache(true, $target);
            if(file_exists($target)){
                @unlink($tmp); // the same event again (e.g. the cut didn't happen last time): keep the first copy
                $fileSynced = null;
            } elseif(!@rename($tmp, $target)){
                throw new \RuntimeException("noneDB: could not publish a quarantine file for database '{$dbname}'");
            }
            $dirSynced = $this->sync->syncDirectory(rtrim($dir, '/'));
            if($this->durable){
                // The copy must be durable before the source bytes are cut
                $this->sync->noteRequired('dir', $dirSynced === true);
                if($dirSynced !== true){
                    throw $this->sync->failure('dir', $dir, false);
                }
            }
            if(@filesize($target) !== $length || hash_file('sha256', $target) !== $sha){
                throw new \RuntimeException("noneDB: quarantine file {$name} doesn't match the bytes it should hold");
            }
            if($fileSynced === false || $dirSynced === false){
                $durable = false;
            } elseif($dirSynced === null){
                $durable = 'unknown';
            } else {
                $durable = true;
            }
            return ['id' => $id, 'file' => $name, 'durable' => $durable];
        } finally {
            fclose($src);
            if($out !== null && $out !== false){
                fclose($out);
            }
            if(is_file($tmp)){
                @unlink($tmp);
            }
        }
    }

    /**
     * Delete temp files ("*.tmp" + 8 hex digits) starting with $prefix: what a writer that died left
     * behind. Exclusive lock only: no other writer can be in the middle of an atomic write.
     */
    public function removeStaleTemp($prefix){
        if($this->noWrites > 0){
            return;
        }
        foreach($this->segments->listFilesWithPrefix($prefix, '/\.tmp[0-9a-f]{8}$/') as $file){
            @unlink($file);
        }
    }
}
}
namespace noneDB\Internal\Recovery {

use function array_merge, array_slice, clearstatcache, dirname, fclose, fgets, file_get_contents, filesize,
    fopen, is_array, is_dir, json_decode, json_encode, max, strpos, substr, time;
use const LOCK_EX;

/**
 * @internal
 */
final class RecoveryLog {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $lockRegistry;
    private $metrics;
    private $noWrites;
    private $pendingRecoveryEvents;
    private $io;
    private $layout;
    private $locks;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->io, $this->layout, $this->locks);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->metrics = &$this->rt->metrics;
        $this->noWrites = &$this->rt->noWrites;
        $this->pendingRecoveryEvents = &$this->rt->pendingRecoveryEvents;
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
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->k = null;
        $this->rt = null;
    }


    private function getRecoveryLogPath($dbname){
        return $this->layout->dbHome($dbname) . 'recovery/log.jsonl';
    }

    /**
     * Append an entry to the database's recovery log (caller holds the exclusive lock). Best effort: a
     * failure to log never fails the operation. Past 1 MiB the log moves to log.1.jsonl (replacing it).
     */
    public function logRecoveryEvent($dbname, array $event, $dedupId = null){
        try {
            $path = $this->getRecoveryLogPath($dbname);
            if($dedupId !== null){
                $id = '"id":' . json_encode($dedupId);
                foreach([substr($path, 0, -6) . '.1.jsonl', $path] as $file){
                    $content = @file_get_contents($file);
                    // the id is followed by the next member or ends the entry
                    if($content !== false && (strpos($content, $id . ',') !== false || strpos($content, $id . '}') !== false)){
                        return; // already logged
                    }
                }
            }
            clearstatcache(true, $path);
            if(!is_dir(dirname($path))){
                $this->io->fsMkdir(dirname($path));
            }
            if(@filesize($path) > 1048576){
                $this->io->fsRename($path, substr($path, 0, -6) . '.1.jsonl');
            }
            $line = $this->io->encodeJson(['t' => time()] + $event);
            if($line !== false && $this->io->appendToFile($path, $line . "\n") !== false){
                $this->metrics['recoveryEvents']++;
                $this->metrics['recoveryActions'][$event['action']] = ($this->metrics['recoveryActions'][$event['action']] ?? 0) + 1;
            }
        } catch(\Throwable $ignored){
        }
    }

    /**
     * A recovery decision: logged at once under the exclusive lock, else kept in memory and logged by the
     * next exclusive session of this process (readers never write); getRecoveryLog() shows both
     */
    public function reportRecoveryEvent($dbname, array $event){
        $lock = $this->locks->getLockPath($dbname);
        if($this->noWrites === 0 && isset($this->lockRegistry[$lock]) && $this->lockRegistry[$lock]['mode'] === LOCK_EX){
            $this->logRecoveryEvent($dbname, $event, $event['id']);
        } else {
            $this->pendingRecoveryEvents[$lock][$event['id']] = ['t' => time()] + $event;
        }
    }

    public function getRecoveryLogEntries($dbname){
        $path = $this->getRecoveryLogPath($dbname);
        $entries = [];
        foreach([substr($path, 0, -6) . '.1.jsonl', $path] as $file){
            $fp = @fopen($file, 'rb');
            if($fp === false){
                continue;
            }
            while(($line = fgets($fp)) !== false){
                $entry = substr($line, -1) === "\n" ? json_decode($line, true) : null;
                if(is_array($entry)){
                    $entries[] = $entry;
                }
            }
            fclose($fp);
        }
        return $entries;
    }

    public function getRecoveryLog($dbname, $limit = 100){
        $dbname = $this->layout->normalizeDbName($dbname);
        $read = function() use ($dbname, $limit){
            // What readers of this process found and no write session logged yet: "persisted" => false
            $pending = [];
            foreach($this->pendingRecoveryEvents[$this->locks->getLockPath($dbname)] ?? [] as $event){
                $pending[] = $event + ['persisted' => false];
            }
            $limit = max(0, (int)$limit);
            return $limit === 0 ? [] : array_slice(array_merge($this->getRecoveryLogEntries($dbname), $pending), -$limit);
        };
        return $this->locks->withLock($dbname, false, $read);
    }
}
}
namespace noneDB\Internal\Recovery {

use function basename, clearstatcache, fclose, fopen, fstat, ftruncate;

/**
 * @internal
 */
final class RollbackScope {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $durable;
    private $lockRegistry;
    private $noWrites;
    private $pendingRecoveryEvents;
    private $cache;
    private $locks;
    private $recovery;
    private $recoveryLog;
    private $segments;
    private $shardMeta;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->locks, $this->recovery, $this->recoveryLog, $this->segments, $this->shardMeta, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->durable = &$this->k->durable;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->noWrites = &$this->rt->noWrites;
        $this->pendingRecoveryEvents = &$this->rt->pendingRecoveryEvents;
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
        $this->locks = null;
        $this->recovery = null;
        $this->recoveryLog = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Run the outermost exclusive call of a database inside a rollback scope (see above), then the jobs
     * deferred until its commit, each in a scope of its own. The call is one write operation: how it ended
     * goes to the durability report (docs/DURABILITY.md §2): committed when $fn returned (every required
     * sync succeeded, or it would have thrown), unknown for OUTCOME_UNKNOWN, rolled back otherwise.
     * @param string $op Name of the operation; null for a deferred job (not an operation of its own)
     */
    public function runWriteScope($dbname, $path, callable $fn, $op = 'write'){
        if($op !== null){
            if(!empty($this->pendingRecoveryEvents[$path]) && $this->noWrites === 0){
                // What readers of this process found (they don't write): logged once, deduplicated by id
                $events = $this->pendingRecoveryEvents[$path];
                unset($this->pendingRecoveryEvents[$path]);
                foreach($events as $id => $event){
                    $this->recoveryLog->logRecoveryEvent($dbname, $event, $id);
                }
            }
            $this->lockRegistry[$path]['wrote'] = false;
            if($this->durable){
                $this->lockRegistry[$path]['syncs'] = []; // the syncs durable mode requires (Sync::noteRequired)
            }
        }
        $this->lockRegistry[$path]['scope'] = ['marks' => [], 'deferred' => [], 'independent' => false];
        try {
            $result = $fn();
        } catch(\Throwable $e){
            try {
                $this->rollbackScope($dbname, $path, $e); // rethrows
            } catch(\Throwable $thrown){
                if($op !== null){
                    $this->finishOperation($dbname, $path, $op, $thrown);
                }
                throw $thrown;
            }
        }
        $deferred = $this->lockRegistry[$path]['scope']['deferred'];
        $this->lockRegistry[$path]['scope'] = null;
        if($op !== null){
            $this->finishOperation($dbname, $path, $op, null);
        }
        foreach($deferred as $job => $work){
            try {
                $this->runWriteScope($dbname, $path, function() use ($dbname, $job, $work){
                    return $this->runIndependent($dbname, $job, $work);
                }, null);
            } catch(\Throwable $e){
                // The operation itself is committed; the job runs again on a later operation
                $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'deferred-failed', 'job' => $job, 'error' => $e->getMessage()]);
                break;
            }
            $this->cache->evictBetweenSegments(); // a job works on one segment (compaction of a large write's segments)
        }
        return $result;
    }

    /**
     * The outcome of a write operation for getDurability(); an operation that wrote nothing has none
     */
    private function finishOperation($dbname, $path, $op, ?\Throwable $e){
        $syncs = [];
        if(isset($this->lockRegistry[$path]['syncs'])){
            $syncs = $this->lockRegistry[$path]['syncs'];
            unset($this->lockRegistry[$path]['syncs']);
        }
        if(!$this->lockRegistry[$path]['wrote']){
            return;
        }
        if($e === null){
            $this->sync->finishOperation($op, $dbname, $syncs, 'committed');
        } else {
            $this->sync->finishOperation($op, $dbname, $syncs, $e->getCode() === \noneDB::OUTCOME_UNKNOWN ? 'unknown' : 'rolled_back', $e->getMessage());
        }
    }

    /**
     * Undo the appends of a failed scope and rethrow; OUTCOME_UNKNOWN when a file can't be cut back
     * (in durable mode: or its cut can't be synced), with the journal "J" so the next session recovers
     */
    private function rollbackScope($dbname, $path, \Throwable $e){
        $marks = $this->lockRegistry[$path]['scope']['marks'];
        $this->lockRegistry[$path]['scope'] = null;
        $this->lockRegistry[$path]['rolledBack'] = true;
        $failed = [];
        foreach($marks as $file => $mark){
            try {
                if(!$this->truncateBack($file, $mark)){
                    $failed[] = $file;
                }
            } catch(\Throwable $t){
                $failed[] = $file;
            }
        }
        $this->cache->invalidateDbCaches($dbname);
        if(!empty($failed) || $e->getCode() === \noneDB::OUTCOME_UNKNOWN){
            // What is on disk is uncertain: the next session recovers the whole database ("J")
            $this->lockRegistry[$path]['flags']['j']['*'] = true;
            try {
                $this->locks->saveLockState($path);
            } catch(\Throwable $ignored){
            }
            if(!empty($failed)){
                throw new \RuntimeException("noneDB: a write to database '{$dbname}' failed and could not be undone: the outcome is unknown, the database is recovered on its next access", \noneDB::OUTCOME_UNKNOWN, $e);
            }
        }
        if(!empty($marks) && $this->segments->isSharded($dbname)){
            try {
                $this->shardMeta->recountMeta($dbname); // keys reserved by the failed write stay unused
            } catch(\Throwable $ignored){
                // the journal entry stays, recovery recounts
            }
        }
        throw $e;
    }

    /**
     * Cut a file back to the size recorded in a mark, if it is still the same file
     */
    private function truncateBack($file, array $mark){
        list($size, $dev, $ino) = $mark;
        $this->locks->noteWrite($file);
        $fp = @fopen($file, 'r+b');
        if($fp === false){
            return false;
        }
        try {
            $stat = fstat($fp);
            if($stat === false || ($ino && ($stat['ino'] !== $ino || $stat['dev'] !== $dev))){
                return false;
            }
            return $stat['size'] <= $size || (ftruncate($fp, $size) && $this->sync->syncUndo($fp, $file));
        } finally {
            fclose($fp);
            clearstatcache(true, $file);
        }
    }

    /**
     * Record the size a file had before the scope's first append to it
     */
    public function markScopeFile($dbname, $file, $size, $fp){
        $lock = $this->locks->getLockPath($dbname);
        if(isset($this->lockRegistry[$lock]['scope']) && !isset($this->lockRegistry[$lock]['scope']['marks'][$file])){
            $stat = fstat($fp);
            $this->lockRegistry[$lock]['scope']['marks'][$file] = [$size, $stat ? $stat['dev'] : 0, $stat ? $stat['ino'] : 0];
        }
    }

    /**
     * Run work that must not be undone by its caller's failure (buffer flush, the switch to shards): in place while the
     * scope has no appends, else deferred until the scope committed (once per $job). Returns null if deferred.
     */
    public function runIndependent($dbname, $job, callable $work){
        $path = $this->locks->getLockPath($dbname);
        if(!isset($this->lockRegistry[$path]['scope'])){
            return $work();
        }
        if(!empty($this->lockRegistry[$path]['scope']['marks'])){
            if(!isset($this->lockRegistry[$path]['scope']['deferred'][$job])){
                $this->lockRegistry[$path]['scope']['deferred'][$job] = $work;
            }
            return null;
        }
        $was = $this->lockRegistry[$path]['scope']['independent'];
        $this->lockRegistry[$path]['scope']['independent'] = true;
        try {
            $result = $work();
            $this->commitIndependentStep($dbname);
            return $result;
        } finally {
            if(isset($this->lockRegistry[$path]['scope'])){
                $this->lockRegistry[$path]['scope']['independent'] = $was;
            }
        }
    }

    /**
     * Work that replaces or removes data files (dropDB, compact, convertToSharded) can't be undone by a rollback:
     * inside a write of the same database that hasn't committed (a nested call from the caller's own code, which
     * a value's jsonSerialize() can make before the first append) it is refused before it touches any file
     * @throws \LogicException
     */
    public function refuseInUncommittedWrite($dbname, $operation){
        $entry = $this->lockRegistry[$this->locks->getLockPath($dbname)] ?? null;
        $scope = $entry['scope'] ?? null;
        if($scope !== null && (!empty($scope['marks']) || $entry['depth'] > 1) && empty($scope['independent'])){
            throw new \LogicException("noneDB: {$operation}() can't run inside a write to database '{$dbname}' that hasn't committed yet (it replaces or deletes data files, which the write's rollback couldn't undo); call it after that write");
        }
    }

    /**
     * Before a file is replaced, renamed or removed: never one that holds appends a rollback may have to cut
     * back (whatever path asks for it)
     * @throws \LogicException
     */
    public function refuseUncommittedFile($file, $operation){
        foreach($this->lockRegistry as $entry){
            if(isset($entry['scope']['marks'][$file]) && empty($entry['scope']['independent'])){
                throw new \LogicException("noneDB: {$operation} of " . basename($file) . " refused: it holds appends of database '{$entry['dbname']}' that aren't committed yet");
            }
        }
    }

    /**
     * Inside independent work: its appends so far are final (the scope had none before it started)
     */
    public function commitIndependentStep($dbname){
        $path = $this->locks->getLockPath($dbname);
        if(!empty($this->lockRegistry[$path]['scope']['independent'])){
            $this->lockRegistry[$path]['scope']['marks'] = [];
        }
    }
}
}
namespace noneDB\Internal\Sharding {

use function array_flip, array_intersect_key, bin2hex, count, fclose, fgets, fopen, intdiv, max, min, random_bytes,
    range, strlen, substr;

/**
 * @internal
 */
final class LayoutSwitch {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $buffer;
    private $cache;
    private $checkpoints;
    private $fieldIndex;
    private $io;
    private $layout;
    private $locks;
    private $records;
    private $recovery;
    private $scope;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->cache, $this->checkpoints, $this->fieldIndex, $this->io, $this->layout, $this->locks, $this->records, $this->recovery, $this->scope, $this->segments);
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
        $this->buffer = null;
        $this->cache = null;
        $this->checkpoints = null;
        $this->fieldIndex = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->records = null;
        $this->recovery = null;
        $this->scope = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Convert a single-layout database to the sharded layout (keys preserved, indexes rebuilt per shard):
     * a generation switch (STORAGE_FORMAT.md §5.2). The new generation is built and verified completely
     * before CURRENT names it; an interruption leaves the old generation or the new one, never a mix.
     * @param string $dbname
     * @return bool
     */
    public function convertToSharded($dbname){
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            if($this->segments->isSharded($dbname)){
                return true;
            }
            $singlePath = $this->segments->getDataPath($dbname);
            if(!$this->cache->cachedFileExists($singlePath)){
                return false;
            }
            $this->scope->refuseUncommittedFile($singlePath, 'The switch to shards');
            $this->buffer->flushPendingBuffers($dbname);

            $index = $this->checkpoints->readJsonlIndex($dbname, null);
            if($index === null){
                return false;
            }
            $live = \noneDB\Internal\Storage\KeyIndex::count($index);
            $maxKey = \noneDB\Internal\Storage\KeyIndex::maxKey($index);
            $size = $this->segments->shardSizeOf($dbname);
            $nextKey = max((int)$index['n'], $maxKey + 1);
            $numShards = max(1, intdiv(max($nextKey, 1) - 1, $size) + 1);
            $manifest = $this->fieldIndex->getIndexManifest($dbname);
            $format = $this->layout->readFormatRecord($dbname);
            unset($index);

            $this->layout->switchGeneration($dbname, function($dir, $generation) use ($dbname, $numShards, $size, $nextKey, $maxKey, $live, $manifest, $format){
                $this->layout->writeGenerationMetadata($dbname, $generation, $this->layout->identityOf($format), 'sharded');
                $meta = \noneDB\Internal\Sharding\ShardMeta::newShardMeta($size, $nextKey);
                $meta['totalRecords'] = $live;
                $written = 0;
                // One shard at a time: its records read from the single segment, written with its checkpoint
                for($shardId = 0; $shardId < $numShards; $shardId++){
                    $base = $shardId * $size;
                    $limit = min($nextKey, $base + $size);
                    $count = 0;
                    $shardIndex = $this->checkpoints->newJsonlIndex();
                    $shardIndex['n'] = $limit - $base;
                    $buffer = $this->io->withExactFloats(function() use ($dbname, $base, $limit, &$shardIndex, &$count){
                        $buffer = '';
                        $offset = 0;
                        // The shard's records a batch at a time (a large shard size holds one batch, not the shard)
                        foreach($base < $limit ? $this->records->eachSegmentBatch($dbname, null, range($base, $limit - 1)) : [] as $batch){
                            foreach($batch as $key => $record){
                                $json = $this->io->encodeRecord($record);
                                \noneDB\Internal\Storage\KeyIndex::set($shardIndex, $key, $offset, strlen($json));
                                $buffer .= $json . "\n";
                                $offset += strlen($json) + 1;
                                $count++;
                            }
                        }
                        return $buffer;
                    });
                    if($shardId === $numShards - 1 && $nextKey - 1 > $maxKey){
                        // Keep the highest key ever used, as compaction does: a key index rebuilt from these
                        // files must never hand it out again
                        $buffer .= '{"__nonedb_deleted":' . ($nextKey - 1) . "}\n";
                        $shardIndex['t'] = 1;
                    }
                    $shardPath = $dir . 'segments/s' . $shardId . '.jsonl';
                    if(!$this->io->writeFileAtomic($shardPath, $buffer, 'replace')){
                        throw new \RuntimeException("noneDB: the switch of database '{$dbname}' to shards failed while writing shard {$shardId}");
                    }
                    $shardIndex['s'] = strlen($buffer);
                    $shardIndex['e'] = bin2hex(random_bytes(4));
                    $buffer = null;
                    // Verified from the file: every record of the shard is a line of it
                    $written += $this->countRecordLines($shardPath, $base, $base + $size);
                    $this->checkpoints->writeCheckpointFile($shardPath, $shardId, $size, $shardIndex); // derived: best effort
                    $shardIndex = null;
                    $entry = \noneDB\Internal\Sharding\ShardMeta::newShardEntry($shardId);
                    $entry['count'] = $count;
                    $meta['shards'][] = $entry;
                }
                if($written !== $live){
                    throw new \RuntimeException("noneDB: the switch of database '{$dbname}' to shards could not be verified");
                }
                $json = $this->io->encodeJson($meta);
                if($json === false || !$this->io->writeFileAtomic($dir . 'shards.json', $json . "\n", 'durable')){
                    throw new \RuntimeException("noneDB: the switch of database '{$dbname}' to shards could not write its shard list");
                }
                if(!empty($manifest['fields']) || !empty($manifest['spatial'])){
                    $list = ['kind' => 'indexes', 'v' => 1, 'fields' => $manifest['fields'], 'spatial' => $manifest['spatial'],
                        'spatialOptions' => array_intersect_key($manifest['spatialOptions'], array_flip($manifest['spatial']))];
                    if(!$this->io->atomicWrite($dir . 'indexes.json', $list, false, 'durable')){
                        throw new \RuntimeException("noneDB: the switch of database '{$dbname}' to shards could not write its index list");
                    }
                }
            });
            // The new generation is CURRENT: its shards' field and spatial indexes
            $this->fieldIndex->rebuildIndexesAfterSwitch($dbname);
            return true;
        }, 'convertToSharded');
    }

    /**
     * Complete lines of a data file that are records with keys in [$base, $limit)
     */
    private function countRecordLines($path, $base, $limit){
        $count = 0;
        $fp = @fopen($path, 'rb');
        while($fp !== false && ($line = fgets($fp)) !== false){
            if(substr($line, -1) === "\n" && ($entry = $this->checkpoints->classifyLine(substr($line, 0, -1), $base, $limit)) !== null && $entry[0] === 'r'){
                $count++;
            }
        }
        if($fp !== false){
            fclose($fp);
        }
        return $count;
    }
}
}
namespace noneDB\Internal\Sharding {

use function count;

/**
 * @internal
 */
final class ShardInfo {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $checkpoints;
    private $layout;
    private $locks;
    private $segments;
    private $shardMeta;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->checkpoints, $this->layout, $this->locks, $this->segments, $this->shardMeta);
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
        $this->layout = null;
        $this->locks = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // SHARDING PUBLIC METHODS
    // ==========================================

    /**
     * Get sharding information for a database
     * @param string $dbname
     * @return array|false
     */
    public function getShardInfo($dbname){
        $dbname = $this->layout->normalizeDbName($dbname);
        return $this->locks->withReadLock($dbname, function() use ($dbname){
            return $this->getShardInfoUnlocked($dbname);
        });
    }

    /**
     * getShardInfo() of a database whose lock the caller holds (backup() and restore() read the counters)
     */
    public function getShardInfoUnlocked($dbname){
        if(!$this->segments->dbExists($dbname)){
            return false;
        }
        if(!$this->segments->isSharded($dbname)){
            $index = $this->checkpoints->readJsonlIndex($dbname, null);
            if($index === null){
                return false;
            }
            return array(
                "sharded" => false,
                "shards" => 0,
                "totalRecords" => \noneDB\Internal\Storage\KeyIndex::count($index),
                "shardSize" => $this->segments->shardSizeOf($dbname), // fixed in FORMAT at creation (§4.1)
                "nextKey" => (int)$index['n']
            );
        }

        $meta = $this->shardMeta->getCachedMeta($dbname);
        if($meta === null){
            return false;
        }
        return array(
            "sharded" => true,
            "shards" => count($meta['shards']),
            "totalRecords" => (int)$meta['totalRecords'],
            "deletedCount" => (int)($meta['deletedCount'] ?? 0),
            "shardSize" => (int)$meta['shardSize'],
            "nextKey" => (int)$meta['nextKey']
        );
    }
}
}
namespace noneDB\Internal\Sharding {

use function clearstatcache, count, fclose, fgets, fopen, is_array, is_file, is_int, max, sort, strlen, substr;

/**
 * @internal
 */
final class ShardMeta {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $metaCache;
    private $shardedCache;
    private $cacheGrew;
    private $cacheTick;
    private $cacheTouch;
    private $noWrites;
    private $cache;
    private $checkpoints;
    private $io;
    private $layout;
    private $locks;
    private $recoveryLog;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->io, $this->layout, $this->locks, $this->recoveryLog, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->metaCache = &$this->k->metaCache;
        $this->shardedCache = &$this->k->shardedCache;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->cacheTick = &$this->rt->cacheTick;
        $this->cacheTouch = &$this->rt->cacheTouch;
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
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->recoveryLog = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Shard list and counters of a sharded database
     */
    public function getMetaPath($dbname){
        return $this->layout->genPath($dbname, 'shards.json');
    }

    /**
     * Read shard metadata
     * @param string $dbname
     * @return array|null
     */
    public function readMeta($dbname){
        $meta = $this->io->atomicRead($this->getMetaPath($dbname), null);
        return is_array($meta) && ($meta['kind'] ?? null) === 'shards' && ($meta['v'] ?? null) === 1
            && is_int($meta['shardSize'] ?? null) && is_int($meta['nextKey'] ?? null)
            && isset($meta['shards']) && is_array($meta['shards']) ? $meta : null;
    }

    /**
     * Get cached meta data. The cache is dropped whenever another process writes the
     * database (generation check in withLock), so no TTL is needed.
     * @param string $dbname
     * @param bool $forceRefresh Force refresh from disk
     * @return array|null
     */
    public function getCachedMeta($dbname, $forceRefresh = false){
        $metaPath = $this->getMetaPath($dbname);

        $this->cacheTouch[$metaPath] = ++$this->cacheTick;
        if(!$forceRefresh && isset($this->metaCache[$metaPath])){
            return $this->metaCache[$metaPath];
        }

        $meta = $this->readMeta($dbname);
        if($meta === null){
            clearstatcache(true, $metaPath);
            if(is_file($metaPath)){
                // There but unreadable (e.g. zero-length after a power loss): rebuilt from the shards.
                // checkIntegrity() writes nothing: it works with a meta derived in memory, not kept
                if($this->noWrites > 0){
                    try {
                        return $this->deriveShardMeta($dbname);
                    } catch(\RuntimeException $e){
                        return null;
                    }
                }
                $meta = $this->recoverMeta($dbname);
            }
        }
        if($meta !== null){
            $this->metaCache[$metaPath] = $meta;
            $this->cacheGrew = true;
            return $meta;
        }
        unset($this->metaCache[$metaPath]);
        return null;
    }

    /**
     * Rebuild an unreadable shards.json from the shard files (exclusive lock): the shard size is FORMAT's,
     * nextKey follows the highest key ever used. A missing shards.json is never "recovered": shard files
     * without one are leftovers (see setOrphanShardsAside).
     * @return array|null
     */
    private function recoverMeta($dbname){
        return $this->locks->withLock($dbname, true, function() use ($dbname){
            $metaPath = $this->getMetaPath($dbname);
            unset($this->metaCache[$metaPath]);
            $meta = $this->readMeta($dbname);
            clearstatcache(true, $metaPath);
            if($meta !== null || !is_file($metaPath)){
                return $meta;
            }
            $meta = $this->deriveShardMeta($dbname);
            $this->writeMeta($dbname, $meta, true);
            $this->recountMeta($dbname);
            $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'meta-rebuilt', 'shards' => count($meta['shards']), 'shardSize' => $meta['shardSize']]);
            return $this->getCachedMeta($dbname);
        }, 'recovery');
    }

    /**
     * A shard list derived from the shard files alone (reads only; counters 0, recountMeta() fills them).
     * The shard size is FORMAT's; the next key is above every key a shard file holds (tombstones included).
     * @return array
     */
    public function deriveShardMeta($dbname){
        $size = $this->segments->shardSizeOf($dbname);
        $prefix = $this->layout->genDir($dbname) . 'segments/s';
        $ids = [];
        $nextKey = 0;
        foreach($this->segments->listFilesWithPrefix($prefix, '/^\d+\.jsonl$/') as $file){
            $shardId = (int)substr($file, strlen($prefix));
            $ids[] = $shardId;
            $fp = @fopen($file, 'rb');
            while($fp !== false && ($line = fgets($fp)) !== false){
                if(substr($line, -1) === "\n" && ($entry = $this->checkpoints->classifyLine(substr($line, 0, -1), $shardId * $size, ($shardId + 1) * $size)) !== null){
                    $nextKey = max($nextKey, $entry[1] + 1);
                }
            }
            if($fp !== false){
                fclose($fp);
            }
        }
        sort($ids);
        $meta = self::newShardMeta($size, $nextKey);
        foreach($ids as $shardId){
            $meta['shards'][] = self::newShardEntry($shardId);
        }
        return $meta;
    }

    /**
     * An empty shards.json record (docs/STORAGE_FORMAT.md §5.1)
     */
    public static function newShardMeta($size, $nextKey){
        return ['kind' => 'shards', 'v' => 1, 'shardSize' => (int)$size, 'nextKey' => (int)$nextKey,
            'totalRecords' => 0, 'deletedCount' => 0, 'shards' => []];
    }

    public static function newShardEntry($shardId){
        return ['id' => (int)$shardId, 'count' => 0, 'deleted' => 0];
    }

    /**
     * Write shard metadata (caller holds the exclusive lock)
     * @param string $dbname
     * @param array $meta
     * @return bool
     * @throws \RuntimeException when shards.json can't be written
     */
    public function writeMeta($dbname, $meta, $sync = false){
        $path = $this->getMetaPath($dbname);
        $json = $this->io->encodeJson($meta);
        if($json === false || !$this->io->writeFileAtomic($path, $json . "\n", $sync ? 'replace' : 'durable')){
            throw new \RuntimeException("noneDB: failed to write metadata of database '{$dbname}'");
        }
        $this->metaCache[$path] = $meta;
        $this->shardedCache[$path] = true;
        $this->cache->markFileExists($path);
        return true;
    }

    /**
     * Recompute the per-shard and total counters of shards.json from the shard indexes
     */
    public function recountMeta($dbname){
        $meta = $this->getCachedMeta($dbname, true);
        if($meta === null){
            return;
        }
        $size = (int)$meta['shardSize'];
        $total = 0;
        $nextKey = 0;
        foreach($meta['shards'] as $i => $shard){
            $index = $this->checkpoints->readJsonlIndex($dbname, (int)$shard['id']);
            $count = $index === null ? 0 : \noneDB\Internal\Storage\KeyIndex::count($index);
            $meta['shards'][$i]['count'] = $count;
            $total += $count;
            if($index !== null){
                // n of a shard counts every key it ever had (deleted ones and the kept top tombstone too)
                $nextKey = max($nextKey, \noneDB\Internal\Storage\KeyIndex::maxKey($index) + 1);
                if((int)$index['n'] > 0){
                    $nextKey = max($nextKey, (int)$shard['id'] * $size + (int)$index['n']);
                }
            }
            unset($index);
            $this->cache->evictBetweenSegments();
        }
        $meta['totalRecords'] = $total;
        $meta['nextKey'] = max((int)($meta['nextKey'] ?? 0), $nextKey);
        $this->writeMeta($dbname, $meta);
    }
}
}
namespace noneDB\Internal\Sharding {

use function array_column, array_diff_key, array_flip, array_keys, array_map, basename, count, date, intdiv,
    max, min, preg_match, strlen, substr;

/**
 * @internal
 */
final class ShardRouter {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $fieldIndexCache;
    private $indexCache;
    private $shardFilesUpTo;
    private $spatialIndexCache;
    private $cache;
    private $checkpoints;
    private $io;
    private $layout;
    private $locks;
    private $records;
    private $recovery;
    private $segments;
    private $shardMeta;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->io, $this->layout, $this->locks, $this->records, $this->recovery, $this->segments, $this->shardMeta);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->fieldIndexCache = &$this->k->fieldIndexCache;
        $this->indexCache = &$this->k->indexCache;
        $this->shardFilesUpTo = &$this->k->shardFilesUpTo;
        $this->spatialIndexCache = &$this->k->spatialIndexCache;
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
        $this->recovery = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Insert into a sharded database. Keys come from meta.nextKey; key K lives in shard intdiv(K, shardSize).
     */
    public function insertShardedRecords($dbname, array $items, $keyed = null, $endKey = null){
        $meta = $this->shardMeta->getCachedMeta($dbname, true);
        if($meta === null){
            throw new \RuntimeException("noneDB: metadata of sharded database '{$dbname}' is unreadable");
        }
        $size = (int)$meta['shardSize'];
        $nextKey = (int)$meta['nextKey'];
        // The meta may lag behind the data (a meta update lost in a power loss): never hand out a key the
        // last shard has already used
        if(!empty($meta['shards'])){
            $last = max(array_map('intval', array_column($meta['shards'], 'id')));
            $lastIndex = $this->checkpoints->readJsonlIndex($dbname, $last);
            if($lastIndex !== null){
                $nextKey = max($nextKey, $last * $size + (int)$lastIndex['n']);
            }
        }

        $byShard = [];
        if($keyed !== null){
            foreach($keyed as $key => $item){
                $byShard[intdiv($key, $size)][$key] = $item;
            }
            $nextKey = max($nextKey, (int)$endKey);
            $items = $keyed;
        } else {
            foreach($items as $item){
                $byShard[intdiv($nextKey, $size)][$nextKey] = $item;
                $nextKey++;
            }
        }

        // Reserve the keys first: a crash later leaves a gap, never a reused key
        $shardPos = [];
        foreach($meta['shards'] as $i => $shard){
            $shardPos[(int)$shard['id']] = $i;
        }
        $this->setOrphanShardsAside($dbname, array_keys(array_diff_key($byShard, $shardPos)));
        foreach($byShard as $shardId => $records){
            if(!isset($shardPos[$shardId])){
                $meta['shards'][] = \noneDB\Internal\Sharding\ShardMeta::newShardEntry($shardId);
                $shardPos[$shardId] = count($meta['shards']) - 1;
            }
            $meta['shards'][$shardPos[$shardId]]['count'] += count($records);
        }
        // Journal first: if we die after reserving, recovery recounts the meta from the shards
        foreach(array_keys($byShard) as $shardId){
            $this->locks->markIndexesPending($dbname, $shardId);
        }
        $meta['nextKey'] = $nextKey;
        $meta['totalRecords'] = (int)$meta['totalRecords'] + count($items);
        $this->shardMeta->writeMeta($dbname, $meta);

        // A failure below is undone by the rollback scope (appends of every shard cut back, counters
        // recounted); the reserved keys stay unused and the new shards stay listed (empty)
        foreach($byShard as $shardId => $records){
            $index = $this->checkpoints->writableSegmentIndex($dbname, $shardId);
            if($index === null){
                $this->records->createJsonlDatabase($dbname, $shardId);
                $index = $this->checkpoints->writableSegmentIndex($dbname, $shardId);
            }
            $this->records->appendSegmentRecords($dbname, $shardId, $index, $records);
            $index['n'] = max((int)$index['n'], max(array_keys($records)) - $shardId * $size + 1);
            $this->checkpoints->storeSegmentIndex($dbname, $shardId, $index);
        }
    }

    /**
     * Files of a shard that shards.json doesn't list (left by a shards.json update lost in a power loss, or
     * put there by hand) must not be taken over by a new shard with the same number: their records were
     * deleted, or belong to keys the meta doesn't know any more. They are renamed to *.orphan.{time}
     * (reported by getDB()), never deleted.
     */
    private function setOrphanShardsAside($dbname, array $shardIds){
        if(empty($shardIds)){
            return;
        }
        // The directory is listed only for shards up to the highest one that had files at the last listing:
        // above it only this process created shard files since (another process's write drops the number,
        // invalidateDbCaches). A listing per new shard made inserts into many shards O(shards²)
        // Only data and checkpoint files: field and spatial indexes check themselves against the data
        // (segment epoch, covered bytes), so one left by an earlier shard of that number is never used
        $base = $this->layout->genDir($dbname) . 'segments/s';
        $upTo = max(array_map('intval', $shardIds));
        if(isset($this->shardFilesUpTo[$base]) && min(array_map('intval', $shardIds)) > $this->shardFilesUpTo[$base]){
            $this->shardFilesUpTo[$base] = $upTo;
            return;
        }
        $wanted = array_flip(array_map('intval', $shardIds));
        $files = [];
        foreach($this->segments->listFilesWithPrefix($base, '/^\d+\./') as $file){
            $shardId = (int)substr($file, strlen($base));
            $upTo = max($upTo, $shardId);
            if(isset($wanted[$shardId])){
                $files[] = $file;
            }
        }
        $stamp = '.orphan.' . date('YmdHis');
        $moved = false;
        foreach($files as $file){
            if(preg_match('/\.orphan\.\d+$/', $file)){
                continue;
            }
            if(!$moved){
                $this->locks->bumpEpoch($dbname); // a shard file of this name goes away: no cache of it is kept
                $moved = true;
            }
            if(!$this->io->fsRename($file, $file . $stamp)){
                throw new \RuntimeException("noneDB: could not move aside the leftover file " . basename($file) . " of database '{$dbname}'");
            }
            unset($this->indexCache[$file], $this->fieldIndexCache[$file], $this->spatialIndexCache[$file]);
            $this->cache->markFileNotExists($file);
        }
        $this->shardFilesUpTo[$base] = $upTo; // after bumpEpoch(), which drops it
    }
}
}
namespace noneDB\Internal\Storage {

use function basename, clearstatcache, count, dirname, fclose, fgets, file_exists, filesize, fopen, is_array,
    json_decode, max, preg_match, register_shutdown_function, strlen, substr, time, trim;

/**
 * @internal
 */
final class Buffer {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $autoCreateDB;
    private $bufferAutoFlushOnShutdown;
    private $bufferCountLimit;
    private $bufferFlushInterval;
    private $bufferLastFlush;
    private $bufferSizeLimit;
    private $shutdownHandlerRegistered;
    private $bufferCounts;
    private $bufferEnds;
    private $flushingNow;
    private $lockRegistry;
    private $checkpoints;
    private $db;
    private $io;
    private $layout;
    private $locks;
    private $recovery;
    private $recoveryLog;
    private $scope;
    private $segments;
    private $shardMeta;
    private $sync;
    private $writer;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->checkpoints, $this->db, $this->io, $this->layout, $this->locks, $this->recovery, $this->recoveryLog, $this->scope, $this->segments, $this->shardMeta, $this->sync, $this->writer);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->autoCreateDB = &$this->k->autoCreateDB;
        $this->bufferAutoFlushOnShutdown = &$this->k->bufferAutoFlushOnShutdown;
        $this->bufferCountLimit = &$this->k->bufferCountLimit;
        $this->bufferFlushInterval = &$this->k->bufferFlushInterval;
        $this->bufferLastFlush = &$this->k->bufferLastFlush;
        $this->bufferSizeLimit = &$this->k->bufferSizeLimit;
        $this->shutdownHandlerRegistered = &$this->k->shutdownHandlerRegistered;
        $this->bufferCounts = &$this->rt->bufferCounts;
        $this->bufferEnds = &$this->rt->bufferEnds;
        $this->flushingNow = &$this->rt->flushingNow;
        $this->lockRegistry = &$this->rt->lockRegistry;
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
        $this->db = null;
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->recovery = null;
        $this->recoveryLog = null;
        $this->scope = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->sync = null;
        $this->writer = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // WRITE BUFFER
    // ==========================================
    // Optional (off by default). Buffered records have no key yet; flushing renames the buffer
    // (buffer/pending.jsonl of the active generation) to buffer/flushing.{firstKey}.jsonl first and then
    // inserts its records through insertRecords(), so the normal indexes and sharding logic apply.
    // Pending buffers are flushed before every read and write, whether or not buffering is enabled.

    /**
     * Get buffer file path of a database
     * @param string $dbname
     * @return string
     */
    public function getBufferPath($dbname){
        return $this->layout->genPath($dbname, 'buffer/pending.jsonl');
    }

    /**
     * Check if buffer exists and has content
     * @param string $bufferPath
     * @return bool
     */
    public function hasBuffer($bufferPath){
        clearstatcache(true, $bufferPath);
        return file_exists($bufferPath) && filesize($bufferPath) > 0;
    }

    /**
     * Number of records in a buffer file; counted once, then tracked by size
     */
    public function getBufferRecordCount($bufferPath){
        clearstatcache(true, $bufferPath);
        if(!file_exists($bufferPath)){
            return 0;
        }
        $size = filesize($bufferPath);
        if(isset($this->bufferCounts[$bufferPath]) && $this->bufferCounts[$bufferPath][0] === $size){
            return $this->bufferCounts[$bufferPath][1];
        }
        $count = count($this->readBufferRecords($bufferPath));
        $this->bufferCounts[$bufferPath] = [$size, $count];
        return $count;
    }

    /**
     * Read all records from a buffer file (JSONL). An unterminated last line is not a record (its
     * append never completed).
     * @param string $bufferPath
     * @param bool $clean Set to false when the file holds anything that isn't a complete record line
     * @return array
     */
    public function readBufferRecords($bufferPath, &$clean = true){
        $clean = true;
        $records = [];
        $fp = @fopen($bufferPath, 'rb');
        if($fp === false){
            return $records;
        }
        while(($line = fgets($fp)) !== false){
            if(substr($line, -1) !== "\n"){
                $clean = false;
                break;
            }
            $line = trim($line);
            if($line === ''){
                continue;
            }
            $record = json_decode($line, true);
            if(is_array($record)){
                $records[] = $record;
            } else {
                $clean = false;
            }
        }
        fclose($fp);
        return $records;
    }

    /**
     * Before appending to a buffer: a torn last line (crashed append) goes to quarantine and is cut off,
     * so the next record isn't glued to it. Skipped when the file has the size this process wrote last.
     */
    private function repairBufferTail($dbname, $bufferPath){
        clearstatcache(true, $bufferPath);
        $size = @filesize($bufferPath);
        if(!$size || ($this->bufferEnds[$bufferPath] ?? null) === $size){
            return;
        }
        $last = $this->checkpoints->lastNewlineIn($bufferPath, max(0, $size - 65536), $size);
        if($last !== $size - 1){
            if($last < 0){
                $last = $this->checkpoints->lastNewlineIn($bufferPath, 0, $size);
            }
            $this->recovery->quarantineAndCut($dbname, 'b', $bufferPath, $last + 1, $size);
            unset($this->bufferCounts[$bufferPath]);
        }
    }

    /**
     * Before a flushed buffer file is deleted: anything in it that wasn't a record is kept in quarantine
     */
    private function quarantineBufferRest($dbname, $file){
        clearstatcache(true, $file);
        $size = (int)@filesize($file);
        if($size > 0){
            $quarantine = $this->recovery->quarantineBytes($dbname, 'b', $file, 0, $size);
            $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'drop-buffer', 'id' => $quarantine['id'], 'file' => basename($file),
                'quarantine' => $quarantine['file'], 'bytesVerified' => true, 'powerLossDurable' => $quarantine['durable']]);
        }
    }

    /**
     * True if the database has buffered records waiting
     */
    private function hasPendingBuffers($dbname){
        // Under the lock the state record says it (flag 'b', set before a buffer gets records and
        // cleared after they are flushed), so normal operations don't touch the file system for this
        $path = $this->locks->getLockPath($dbname);
        if(isset($this->lockRegistry[$path]) && $this->lockRegistry[$path]['epoch'] !== null){
            return !empty($this->lockRegistry[$path]['flags']['b']);
        }
        return $this->hasBuffer($this->getBufferPath($dbname)) || !empty($this->flushingFiles($dbname));
    }

    /**
     * Buffer files whose flush was interrupted: buffer/flushing.{firstKey}.jsonl
     */
    public function flushingFiles($dbname){
        return $this->segments->listFilesWithPrefix($this->layout->genDir($dbname) . 'buffer/flushing.', '/^\d+\.jsonl$/');
    }

    /**
     * Flush buffered records into the database (exclusive lock)
     * @return int Number of records flushed
     */
    public function flushPendingBuffers($dbname){
        $base = $this->layout->dbHome($dbname);
        // Flushing inserts records, which can switch the database to shards, which flushes again: don't re-enter
        if(isset($this->flushingNow[$base]) || !$this->hasPendingBuffers($dbname)){
            return 0;
        }
        return (int)$this->locks->withLock($dbname, true, function() use ($dbname, $base){
            // In place before the caller appended anything, else after the caller's write committed
            return $this->scope->runIndependent($dbname, 'flush', function() use ($dbname, $base){
                if(isset($this->flushingNow[$base]) || !$this->hasPendingBuffers($dbname)){
                    return 0;
                }
                $this->flushingNow[$base] = true;
                try {
                    return $this->flushPendingBuffersLocked($dbname);
                } finally {
                    unset($this->flushingNow[$base]);
                }
            });
        }, 'flush');
    }

    private function flushPendingBuffersLocked($dbname){
        {
            $flushed = 0;
            $bufferPath = $this->getBufferPath($dbname);
            // Interrupted flushes first. A flush renames the buffer to flushing.{firstKey}.jsonl: the
            // rename records atomically which keys its records get, so replaying it is idempotent.
            foreach($this->flushingFiles($dbname) as $file){
                preg_match('/flushing\.(\d+)\.jsonl$/', $file, $m);
                $flushed += $this->flushBufferFile($dbname, $file, (int)$m[1]);
            }
            if($this->hasBuffer($bufferPath)){
                if(!$this->segments->dbExists($dbname)){
                    $this->db->createDBUnlocked($dbname);
                }
                $startKey = $this->nextKeyOf($dbname);
                $flushingPath = dirname($bufferPath) . '/flushing.' . $startKey . '.jsonl';
                if(!$this->sync->renameWithRetry($bufferPath, $flushingPath)){
                    throw new \RuntimeException("noneDB: could not flush the write buffer of database '{$dbname}'");
                }
                $this->bufferEnds[$bufferPath] = 0; // the buffer is gone: the next append creates it
                // The rename must survive a power loss before records get keys (else replayed twice)
                $this->sync->requireSync(null, dirname($bufferPath), 'dir');
                $flushed += $this->flushBufferFile($dbname, $flushingPath, $startKey);
            }
            unset($this->bufferCounts[$bufferPath]);
            $this->locks->setBufferFlag($dbname, false);
            $this->bufferLastFlush[$dbname] = time();
            return $flushed;
        }
    }

    /**
     * Insert the records of one buffer file, starting at $startKey
     * @return int
     */
    private function flushBufferFile($dbname, $file, $startKey){
        $records = $this->readBufferRecords($file, $clean);
        if(!empty($records)){
            $result = $this->writer->insertRecords($dbname, $records, $startKey);
            if(isset($result['error'])){
                throw new \RuntimeException("noneDB: flushing the write buffer of '{$dbname}' failed: " . $result['error']);
            }
        }
        $this->scope->commitIndependentStep($dbname); // the records are stored for good before their buffer goes
        if(!$clean){
            $this->quarantineBufferRest($dbname, $file);
        }
        $this->io->fsUnlink($file);
        return count($records);
    }

    /**
     * The key the next inserted record gets
     */
    private function nextKeyOf($dbname){
        if($this->segments->isSharded($dbname)){
            $meta = $this->shardMeta->getCachedMeta($dbname, true);
            return $meta === null ? 0 : (int)$meta['nextKey'];
        }
        $index = $this->checkpoints->writableSegmentIndex($dbname, null);
        return $index === null ? 0 : (int)$index['n'];
    }
    /**
     * Append validated records to the database's write buffer (exclusive lock)
     * @return array
     */
    public function insertBuffered($dbname, array $validItems){
        if(!$this->segments->dbExists($dbname)){
            if(!$this->autoCreateDB){
                return ["n" => 0, "error" => "Database does not exist"];
            }
            $this->db->createDBUnlocked($dbname);
        }
        $this->registerShutdownHandler();

        $lines = $this->io->withExactFloats(function() use ($validItems){
            $lines = '';
            foreach($validItems as $item){
                $lines .= $this->io->encodeRecord($item) . "\n";
            }
            return $lines;
        });

        $bufferPath = $this->getBufferPath($dbname);
        // One size for the flush limits, the torn tail and the count. The size this process's last append
        // left holds while no other process wrote the database: a write of another process moves the lock
        // generation, which drops this cache (CacheManager::invalidateDbCaches), so no stat per insert.
        $size = $this->bufferEnds[$bufferPath] ?? null;
        if($size === null){
            clearstatcache(true, $bufferPath);
            $size = (int)@filesize($bufferPath);
        }
        $known = isset($this->bufferCounts[$bufferPath]) && $this->bufferCounts[$bufferPath][0] === $size ? $this->bufferCounts[$bufferPath][1] : null;
        if($size > 0 && $this->shouldFlushBuffer($bufferPath, $dbname, $size, $known)){
            $this->flushPendingBuffers($dbname);
            clearstatcache(true, $bufferPath);
            $size = (int)@filesize($bufferPath);
        }
        if($size > 0 && ($this->bufferEnds[$bufferPath] ?? null) !== $size){
            $this->repairBufferTail($dbname, $bufferPath);
            clearstatcache(true, $bufferPath);
            $size = (int)@filesize($bufferPath);
        }
        $count = $size === 0 ? 0 : (isset($this->bufferCounts[$bufferPath]) && $this->bufferCounts[$bufferPath][0] === $size
            ? $this->bufferCounts[$bufferPath][1] : $this->getBufferRecordCount($bufferPath));
        $this->locks->setBufferFlag($dbname, true);
        if($this->io->appendToFile($bufferPath, $lines, $dbname) === false){
            throw new \RuntimeException("noneDB: failed to write the write buffer of database '{$dbname}'; nothing was written");
        }
        $size += strlen($lines); // the append ended there (checked against the file next time)
        $this->bufferCounts[$bufferPath] = [$size, $count + count($validItems)];
        $this->bufferEnds[$bufferPath] = $size;
        if(!isset($this->bufferLastFlush[$dbname])){
            $this->bufferLastFlush[$dbname] = time();
        }

        if($this->shouldFlushBuffer($bufferPath, $dbname, $size, $count + count($validItems))){
            $this->flushPendingBuffers($dbname);
        }
        return ["n" => count($validItems)];
    }

    /**
     * Check if buffer needs flushing (size, count, or time based)
     * @param string $bufferPath
     * @param string $flushKey Key for tracking last flush time
     * @return bool
     */
    private function shouldFlushBuffer($bufferPath, $flushKey, $size = null, $count = null){
        if($size === null){
            clearstatcache(true, $bufferPath);
            $size = file_exists($bufferPath) ? (int)filesize($bufferPath) : 0;
        }
        if($size <= 0){
            return false;
        }
        if($size >= $this->bufferSizeLimit){
            return true;
        }
        if(($count ?? $this->getBufferRecordCount($bufferPath)) >= $this->bufferCountLimit){
            return true;
        }
        if($this->bufferFlushInterval > 0){
            $lastFlush = $this->bufferLastFlush[$flushKey] ?? time();
            if((time() - $lastFlush) >= $this->bufferFlushInterval){
                return true;
            }
        }
        return false;
    }

    /**
     * Register shutdown handler for flushing all buffers
     */
    private function registerShutdownHandler(){
        if($this->shutdownHandlerRegistered){
            return;
        }
        if($this->bufferAutoFlushOnShutdown){
            // Weak reference: the handler must not keep the instance alive until shutdown
            $ref = \WeakReference::create($this->k);
            register_shutdown_function(static function() use ($ref){
                $db = $ref->get();
                if($db !== null){
                    try {
                        $db->maintenance->flushAllBuffers();
                    } catch(\Throwable $e){
                        // The records stay in the buffer (committed): the next access flushes them. An
                        // exception here would end the script with a fatal error after its work was done.
                    }
                }
            });
            $this->shutdownHandlerRegistered = true;
        }
    }
}
}
namespace noneDB\Internal\Storage {

use function array_keys, basename, bin2hex, clearstatcache, count, crc32, end, fclose, file_exists, filesize, fopen,
    fread, fseek, hash, in_array, intdiv, is_array, is_file, is_int, is_string, json_decode, max, min, random_bytes,
    range, sprintf, strlen, strpos, strrpos, substr, time;
use const LOCK_EX, LOCK_SH;

/**
 * @internal
 */
final class Checkpoints {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    /** @var array<string, string> data file => its checkpoint file (a pure memo of ckptPath()) */
    private static $ckptPaths = [];

    private $dataEpochs;
    private $fieldIndexCache;
    private $indexCache;
    private $spatialIndexCache;
    private $verifiedIndexes;
    private $writableIndexes;
    private $cacheGrew;
    private $sessionCounter;
    private $cacheTick;
    private $cacheTouch;
    private $lockRegistry;
    private $metrics;
    private $noWrites;
    private $uncheckpointed;
    private $decodeDue;
    private $cache;
    private $fieldIndex;
    private $io;
    private $locks;
    private $recovery;
    private $recoveryLog;
    private $rtree;
    private $secondary;
    private $segments;
    private $shardMeta;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->fieldIndex, $this->io, $this->locks, $this->recovery, $this->recoveryLog, $this->rtree, $this->secondary, $this->segments, $this->shardMeta);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dataEpochs = &$this->k->dataEpochs;
        $this->fieldIndexCache = &$this->k->fieldIndexCache;
        $this->indexCache = &$this->k->indexCache;
        $this->spatialIndexCache = &$this->k->spatialIndexCache;
        $this->verifiedIndexes = &$this->k->verifiedIndexes;
        $this->writableIndexes = &$this->k->writableIndexes;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->sessionCounter = &$this->rt->sessionCounter;
        $this->cacheTick = &$this->rt->cacheTick;
        $this->cacheTouch = &$this->rt->cacheTouch;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->metrics = &$this->rt->metrics;
        $this->noWrites = &$this->rt->noWrites;
        $this->uncheckpointed = &$this->rt->uncheckpointed;
        $this->decodeDue = &$this->rt->decodeDue;
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
        $this->io = null;
        $this->locks = null;
        $this->recovery = null;
        $this->recoveryLog = null;
        $this->rtree = null;
        $this->secondary = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // JSONL SEGMENT INDEX
    // ==========================================

    /**
     * Get JSONL index path
     * @param string $dbname
     * @param int|null $shardId Null for non-sharded
     * @return string
     */
    public function getJsonlIndexPath($dbname, $shardId = null){
        return self::ckptPath($this->segments->segDataPath($dbname, $shardId));
    }

    /**
     * Checkpoint of a data file: segments/{seg}.jsonl -> segments/{seg}.kix
     */
    public static function ckptPath($dataPath){
        if(isset(self::$ckptPaths[$dataPath])){
            return self::$ckptPaths[$dataPath];
        }
        if(count(self::$ckptPaths) >= 65536){
            self::$ckptPaths = [];
        }
        return self::$ckptPaths[$dataPath] = substr($dataPath, 0, -6) . '.kix';
    }

    public function newJsonlIndex(){
        return KeyIndex::emptyIndex();
    }

    /**
     * A segment's key index (offset and length of every live record), from the cache, or loaded from its
     * checkpoint and the data after it
     * @param string $dbname
     * @param int|null $shardId
     * @return array|null Null when the segment doesn't exist
     */
    public function readJsonlIndex($dbname, $shardId = null){
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        $path = self::ckptPath($dataPath);
        // A cached index was checked against its data file (no file check on this hot path); else the file
        // must exist
        if(!isset($this->indexCache[$path]) && !$this->cache->cachedFileExists($dataPath)){
            return null;
        }
        if(isset($this->indexCache[$path])){
            $this->cacheTouch[$path] = ++$this->cacheTick;
            if(isset($this->verifiedIndexes[$path])){
                if(($this->indexCache[$path]['kb'] !== '' || empty($this->indexCache[$path]['few'])) && $this->indexCache[$path]['ses'] !== $this->sessionCounter){
                    // One more operation: a body is decoded once they have paid for it (a database of few segments
                    // decodes earlier: loadSegmentIndex(), decodeDueIndexes()); they count on after that for the
                    // data bytes of a segment (RecordIO::heldData()), which a database of few segments doesn't hold
                    $this->indexCache[$path]['ses'] = $this->sessionCounter;
                    if((++$this->indexCache[$path]['uses'] + $this->indexCache[$path]['looked']) * self::KEYS_PER_LOOKUP >= $this->indexCache[$path]['live']
                        && $this->indexCache[$path]['kb'] !== ''){
                        $this->decodeKeyIndex($path);
                    }
                }
                $this->metrics['hits']++;
                return $this->indexCache[$path];
            }
            // Another process wrote the database since this one cached the index, without replacing a data
            // file (same epoch): the data only grew, the new lines are replayed
            $index = $this->indexCache[$path];
            unset($this->indexCache[$path]);
            clearstatcache(true, $dataPath);
            $size = @filesize($dataPath);
            if($size !== false && $size >= (int)$index['s']){
                if($size > (int)$index['s']){
                    $this->replaySegment($dbname, $shardId, $index, $size);
                }
                $this->indexCache[$path] = $index;
                $this->verifiedIndexes[$path] = true;
                $this->maybeReaderCheckpoint($dbname, $shardId);
                $this->metrics['hits']++;
                return $this->indexCache[$path];
            }
        }
        return $this->loadSegmentIndex($dbname, $shardId);
    }

    /**
     * Load a segment index: its checkpoint when it fits the data file, then the lines after it. A
     * checkpoint that doesn't fit (the data changed behind it) rebuilds the index and the segment's
     * secondary indexes under the exclusive lock; a missing one (or one of an unknown version) only
     * replays the whole file.
     * @return array|null
     */
    private function loadSegmentIndex($dbname, $shardId){
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        $path = self::ckptPath($dataPath);
        clearstatcache(true, $dataPath);
        if(!is_file($dataPath)){
            if(file_exists($dataPath)){
                throw new \RuntimeException("noneDB: data file of database '{$dbname}' is not a regular file: " . basename($dataPath));
            }
            $this->cache->markFileNotExists($dataPath);
            return null;
        }
        $size = (int)filesize($dataPath);
        $this->metrics['misses']++;
        // A checkpoint whose header, body length or a block crc is not valid is no checkpoint (full replay)
        $checkpoint = KeyIndex::readFile($path);
        if($checkpoint !== null){
            $this->cache->noteIndexRead(KeyIndex::HEADER + strlen($checkpoint[1]));
            if(!$this->checkpointFits($dataPath, $checkpoint[0]['s'], $size, $checkpoint[0]['h'] ?? null)){
                return $this->repairSegmentIndex($dbname, $shardId);
            }
            $index = KeyIndex::fromFile($checkpoint[0], $checkpoint[1]);
            $index['ckh'] = $checkpoint[0]['h']; // checked: the crc of the bytes before ck
        } else {
            $index = $this->newJsonlIndex();
        }
        $this->replaySegment($dbname, $shardId, $index, $size);
        $index['ses'] = $this->sessionCounter; // the operation that loads it is its first
        $index['uses'] = 1;
        $this->indexCache[$path] = $index;
        $this->verifiedIndexes[$path] = true;
        $this->indexCache[$path]['few'] = $this->segments->segmentCount($dbname) <= Segments::FEW_SEGMENTS;
        $this->indexCache[$path]['dp'] = $dataPath;
        $this->cacheTouch[$path] = ++$this->cacheTick;
        $this->cacheGrew = true;
        if($index['ck'] < 0){
            // No checkpoint to continue (missing, empty): the next write session of this process writes one
            $this->uncheckpointed[$this->locks->getLockPath($dbname)][$shardId === null ? 'main' : $shardId] = $shardId;
        }
        $this->maybeReaderCheckpoint($dbname, $shardId);
        // A database of few segments (§6.4): a small body decoded at its first use, except in the process's
        // first operation (a single cold operation keeps the body); any other at the start of the database's
        // next operation (LockManager::lockSession() calls decodeDueIndexes())
        if(!empty($this->indexCache[$path]['few']) && $this->indexCache[$path]['kb'] !== ''){
            if(strlen($this->indexCache[$path]['kb']) <= self::DECODE_AT_FIRST_USE && $this->sessionCounter >= 2){
                $this->decodeKeyIndex($path);
            }
            if(isset($this->indexCache[$path]) && $this->indexCache[$path]['kb'] !== ''){
                $this->decodeDue[$this->locks->getLockPath($dbname)][$path] = true;
            }
        }
        return $this->indexCache[$path];
    }

    /**
     * True when a checkpoint covering $covered bytes belongs to this data file: within it, at a line end,
     * and the bytes before $covered have the checksum it recorded
     */
    public function checkpointFits($dataPath, $covered, $size, $crc){
        if($covered < 0 || $covered > $size || !is_string($crc)){
            return false;
        }
        return $this->segmentCrc($dataPath, $covered) === $crc;
    }

    /**
     * crc32b of the up to 256 bytes before $offset of a data file; null when $offset is not a line end
     */
    public function segmentCrc($dataPath, $offset){
        if($offset === 0){
            return sprintf('%08x', crc32(''));
        }
        $fp = @fopen($dataPath, 'rb');
        if($fp === false){
            return null;
        }
        $start = max(0, $offset - 256);
        fseek($fp, $start);
        $bytes = (string)fread($fp, $offset - $start);
        fclose($fp);
        return strlen($bytes) === $offset - $start && substr($bytes, -1) === "\n" ? sprintf('%08x', crc32($bytes)) : null;
    }

    /**
     * Apply the complete lines of bytes [s, $size) of a segment's data file to its index: the last line of
     * a key wins, a tombstone deletes. An unterminated tail stays outside ("s" ends before it; the next
     * writer quarantines and cuts it). A complete line that doesn't decode (a damaged region before it,
     * STORAGE_FORMAT.md §6.1) is salvaged when a record or tombstone can be found at its end, and reported
     * either way.
     * @param array|null $hint an earlier index of the segment (p/l), used to find records in damaged lines
     * @param bool $report Report salvaged and skipped lines (not for checkIntegrity())
     */
    public function replaySegment($dbname, $shardId, array &$index, $size, $hint = null, $report = true){
        $from = (int)$index['s'];
        if($shardId !== null){
            $index['z'] = $this->segments->shardSizeOf($dbname);
            $index['sid'] = $shardId;
        }
        if($size <= $from){
            return;
        }
        $fp = @fopen($this->segments->segDataPath($dbname, $shardId), 'rb');
        if($fp === false){
            return;
        }
        $base = $shardId === null ? 0 : $shardId * $this->segments->shardSizeOf($dbname);
        $limit = $shardId === null ? null : $base + $this->segments->shardSizeOf($dbname);
        $p = $index['p'];
        $l = $index['l'];
        $x = $index['x'];
        unset($index['p'], $index['l'], $index['x']); // sole owner: changed in place, not copied
        $hasBody = $index['kb'] !== '';
        $live = $index['live'];
        $n = (int)$index['n'];
        $d = (int)$index['d'];
        $t = (int)$index['t'];
        $lines = 0;
        $oldEnds = null;
        $events = [];
        fseek($fp, $from);
        $read = $from;
        $offset = $from;  // file offset of $buffer[0]
        $buffer = '';
        while($read < $size){
            $chunk = fread($fp, min(1048576, $size - $read));
            if($chunk === false || $chunk === ''){
                break;
            }
            $read += strlen($chunk);
            $buffer .= $chunk;
            $pos = 0;
            while(($newline = strpos($buffer, "\n", $pos)) !== false){
                $json = substr($buffer, $pos, $newline - $pos);
                $lineOffset = $offset + $pos;
                $entry = $this->classifyLine($json, $base, $limit); // a key of another shard is no line of this one
                if($entry === null){
                    if($oldEnds === null){
                        $oldEnds = [];
                        [$hintOffsets, $hintLengths] = $hint === null ? [[], []] : KeyIndex::locate($hint, null);
                        foreach($hintOffsets as $k => $at){
                            $oldEnds[(int)$at + (int)$hintLengths[$k]] = [(int)$k, (int)$at];
                        }
                        $hintOffsets = $hintLengths = null;
                    }
                    $entry = $this->salvageLine($json, $lineOffset, $oldEnds, $base, $limit);
                    $events[] = [
                        'action' => $entry === null ? 'skip-line' : 'salvaged',
                        'seg' => \noneDB\Internal\Storage\Layout::segmentName($shardId),
                        'off' => $lineOffset, 'len' => strlen($json) + 1,
                        'sha256' => hash('sha256', $json . "\n"),
                    ] + ($entry === null ? [] : ['key' => $entry[1]]);
                }
                if($entry === null){
                    $d++;
                } else {
                    $key = $entry[1];
                    // The key's record so far: written since the checkpoint (p), deleted since (x), or in the body
                    $inBody = $hasBody && !isset($x[$key]) && KeyIndex::baseGet($index, $key) !== null;
                    $existed = isset($p[$key]) || $inBody;
                    if($entry[0] === 't'){
                        if($existed){
                            unset($p[$key], $l[$key]);
                            if($inBody){
                                $x[$key] = true;
                            }
                            $live--;
                            $d++;
                        }
                        $t++;
                    } else {
                        if($existed){
                            $d++;
                        } else {
                            $live++;
                        }
                        $p[$key] = $lineOffset + $entry[2];
                        $l[$key] = $entry[3];
                        unset($x[$key]);
                    }
                    if($key >= $base && $key - $base + 1 > $n){
                        $n = $key - $base + 1;
                    }
                }
                $lines++;
                $pos = $newline + 1;
            }
            $offset += $pos;
            $buffer = (string)substr($buffer, $pos);
        }
        fclose($fp);
        $index['p'] = $p;
        $index['l'] = $l;
        $index['x'] = $x;
        $index['live'] = $live;
        $index['n'] = $n;
        $index['d'] = $d;
        $index['t'] = $t;
        $index['s'] = $offset;
        $index['tl'] = (int)$index['tl'] + $lines;
        foreach($report ? $events : [] as $event){
            $event['id'] = $event['seg'] . '.' . $event['off'] . '.' . $event['len'] . '.' . $event['sha256'];
            unset($event['sha256']);
            $this->recoveryLog->reportRecoveryEvent($dbname, $event);
        }
    }

    /**
     * A segment index changed by a write (exclusive lock): cached, and checkpointed when the session ends
     */
    public function storeSegmentIndex($dbname, $shardId, array $index){
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        $this->indexCache[$path] = $index;
        $this->verifiedIndexes[$path] = true;
        $this->lockRegistry[$this->locks->getLockPath($dbname)]['touched'][$shardId === null ? 'main' : $shardId] = $shardId;
    }

    /**
     * True when the lines after a segment's checkpoint are many enough to write a new one
     */
    private function needsCheckpoint(array $index){
        return (int)$index['ck'] < 0
            || (int)$index['tl'] >= self::CHECKPOINT_LINES + intdiv($index['live'], 16)
            || (int)$index['s'] - (int)$index['ck'] >= self::CHECKPOINT_BYTES;
    }

    /**
     * Write the checkpoint file of a data file's key index (an index with its epoch; exclusive lock, or a reader:
     * the content is the data's): [layout, slots, body, max, live, header, base], or null when its bytes don't end
     * at a line or the file can't be written
     * @return array|null
     */
    public function writeCheckpointFile($dataPath, $shardId, $shardSize, array $index){
        $crc = $this->segmentCrc($dataPath, (int)$index['s']);
        if($crc === null){
            return null;
        }
        $base = $shardId === null ? 0 : $shardId * $shardSize;
        [$layout, $slots, $body, $max, $live] = KeyIndex::encodeBody($index, $base);
        $header = ['kind' => 'kix', 'v' => 1, 'layout' => $layout, 'base' => $base, 'slots' => $slots, 'live' => $live,
            'n' => (int)$index['n'], 'd' => (int)$index['d'], 't' => (int)$index['t'], 's' => (int)$index['s'], 'h' => $crc,
            'e' => $index['e'], 'created' => (int)$index['created'], 'updated' => time()];
        if($shardId !== null){
            $header['z'] = $shardSize;
            $header['sid'] = $shardId;
        }
        if(!$this->io->writeDerivedFile(self::ckptPath($dataPath), KeyIndex::encodeFile($header, $body))){
            return null;
        }
        return [$layout, $slots, $body, $max, $live, $header, $base];
    }

    /**
     * Write a segment's checkpoint (best effort: a failure only means a longer replay next time). An
     * index without epoch gets one, which only the exclusive lock holder may assign.
     * @return bool
     */
    public function writeCheckpoint($dbname, $shardId, array &$index){
        if($this->noWrites > 0){
            return false;
        }
        $lock = $this->locks->getLockPath($dbname);
        $exclusive = isset($this->lockRegistry[$lock]) && $this->lockRegistry[$lock]['mode'] === LOCK_EX;
        if($index['e'] === null){
            if(!$exclusive){
                return false;
            }
            $index['e'] = bin2hex(random_bytes(4));
        }
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        $written = $this->writeCheckpointFile($dataPath, $shardId, $shardId === null ? null : $this->segments->shardSizeOf($dbname), $index);
        if($written === null){
            return false;
        }
        [$layout, $slots, $body, $max, $live, $header, $base] = $written;
        if($index['kb'] !== ''){
            $index = KeyIndex::rebased($index, $layout, $slots, $body, $max, $base); // compact again
        }
        // (an index held as arrays stays so: it is in use, or was just replayed whole; eviction compacts it)
        $index['live'] = $live;
        $index['updated'] = $header['updated'];
        $index['ck'] = (int)$index['s'];
        $index['ckh'] = $header['h'];
        $index['tl'] = 0;
        $this->indexCache[self::ckptPath($dataPath)] = $index;
        $this->dataEpochs[$dataPath] = $index['e'];
        return true;
    }

    /**
     * End of a successful write session: checkpoint the segments it changed that need one
     */
    public function checkpointTouchedSegments($dbname, $lock){
        $touched = $this->lockRegistry[$lock]['touched'] ?? [];
        $segments = $touched + ($this->uncheckpointed[$lock] ?? []);
        unset($this->uncheckpointed[$lock]);
        foreach($segments as $shardId){
            $path = $this->getJsonlIndexPath($dbname, $shardId);
            if(!isset($this->indexCache[$path], $this->verifiedIndexes[$path]) || !$this->needsCheckpoint($this->indexCache[$path])){
                continue;
            }
            $index = $this->indexCache[$path];
            try {
                $this->writeCheckpoint($dbname, $shardId, $index);
            } catch(\Throwable $ignored){
                // a checkpoint is never needed for correctness
            }
        }
        $this->lockRegistry[$lock]['touched'] = [];
        foreach($touched as $shardId){
            if(!isset($this->indexCache[$this->getJsonlIndexPath($dbname, $shardId)])){
                continue; // dropped by the cache limit during the session: the next reader that loads it checkpoints it
            }
            try {
                $this->secondary->checkpointSegmentSecondaries($dbname, $shardId);
            } catch(\Throwable $e){
                if($e->getCode() === \noneDB::LOCK_FAILED){
                    throw $e;
                }
            }
        }
    }

    /**
     * A reader that replayed a long tail writes the checkpoint itself (shared lock: the data can't change,
     * the content is the same as a writer's; no generation bump). Only on top of an existing checkpoint with
     * an epoch, and not inside a write session of this process (that one checkpoints at its end).
     */
    private function maybeReaderCheckpoint($dbname, $shardId){
        $lock = $this->locks->getLockPath($dbname);
        if($this->noWrites > 0 || !isset($this->lockRegistry[$lock]) || $this->lockRegistry[$lock]['mode'] !== LOCK_SH){
            return;
        }
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        $index = $this->indexCache[$path];
        if($index['e'] === null || (int)$index['ck'] < 0 || !$this->needsCheckpoint($index)){
            return;
        }
        try {
            $this->writeCheckpoint($dbname, $shardId, $index);
        } catch(\Throwable $ignored){
        }
    }

    /**
     * Position of the last "\n" in bytes [$from, $to) of a file, -1 if there is none
     */
    public function lastNewlineIn($path, $from, $to){
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return -1;
        }
        try {
            $end = $to;
            while($end > $from){
                $start = max($from, $end - 65536);
                fseek($fp, $start);
                $chunk = (string)fread($fp, $end - $start);
                $pos = strrpos($chunk, "\n");
                if($pos !== false){
                    return $start + $pos;
                }
                $end = $start;
            }
            return -1;
        } finally {
            fclose($fp);
        }
    }

    /**
     * Rebuild a segment index from its data file (exclusive lock, upgrading a shared one): a checkpoint
     * that doesn't fit the data, a key the index points at that isn't there. The rebuilt index has a new
     * epoch, so the segment's field and spatial indexes are rebuilt as well (STORAGE_FORMAT.md §6.5).
     * @param bool $secondaries Also rebuild field/spatial indexes when needed (recovery does it itself)
     * @return array|null
     */
    public function repairSegmentIndex($dbname, $shardId, $secondaries = true){
        return $this->locks->withLock($dbname, true, function() use ($dbname, $shardId, $secondaries){
            $dataPath = $this->segments->segDataPath($dbname, $shardId);
            $path = self::ckptPath($dataPath);
            unset($this->indexCache[$path], $this->verifiedIndexes[$path], $this->writableIndexes[$path]);
            clearstatcache(true, $dataPath);
            if(!file_exists($dataPath)){
                return null;
            }
            if(!is_file($dataPath)){
                throw new \RuntimeException("noneDB: data file of database '{$dbname}' is not a regular file: " . basename($dataPath));
            }
            $old = $this->checkpointOnDisk($path);
            $index = $this->rebuildSegmentIndexFromData($dbname, $shardId, $old);
            if($shardId !== null){
                $this->shardMeta->recountMeta($dbname);
            }
            // The rebuilt index has a new epoch: every field and spatial index of the segment is invalid now (§6.5),
            // also when the entries are the old ones (the data changed in place). Built again while this exclusive
            // lock is held, not by the next reader
            if($secondaries){
                $this->rebuildSecondaryIndexes($dbname, [$shardId]);
            }
            return $index;
        }, 'rebuildIndex');
    }


    /**
     * The key index a checkpoint file on disk holds (valid by the format, not checked against the data), or null
     */
    private function checkpointOnDisk($path){
        $checkpoint = KeyIndex::readFile($path);
        if($checkpoint === null){
            return null;
        }
        $this->cache->noteIndexRead(KeyIndex::HEADER + strlen($checkpoint[1]));
        return KeyIndex::fromFile($checkpoint[0], $checkpoint[1]);
    }

    /**
     * Rebuild field and spatial indexes of some segments (and the global ones of a sharded database)
     * from the data. Caller holds the exclusive lock.
     */
    public function rebuildSecondaryIndexes($dbname, array $segments){
        $manifest = $this->fieldIndex->getIndexManifest($dbname);
        if(empty($manifest['fields']) && empty($manifest['spatial'])){
            return;
        }
        $known = $this->segments->listSegments($dbname);
        // One index that can't be built must never lock the whole database: it is dropped (queries scan,
        // the next write or query builds it again) and logged
        $guard = function(callable $build, $what, array $files) use ($dbname){
            try {
                $build();
            } catch(\Throwable $e){
                if($e->getCode() === \noneDB::LOCK_FAILED){
                    throw $e;
                }
                foreach($files as $file){
                    $this->io->fsUnlink($file);
                    unset($this->fieldIndexCache[$file], $this->spatialIndexCache[$file]);
                }
                $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'index-dropped', 'index' => $what, 'error' => $e->getMessage()]);
            }
        };
        foreach($segments as $shardId){
            if(!in_array($shardId, $known, true)){
                continue;
            }
            foreach($manifest['fields'] as $field){
                $guard(function() use ($dbname, $field, $shardId){
                    $index = $this->fieldIndex->buildSegmentFieldIndex($dbname, $shardId, $field);
                    $this->fieldIndex->writeFieldIndex($dbname, $field, $index, $shardId);
                }, 'field ' . $field, [$this->fieldIndex->getFieldIndexPath($dbname, $field, $shardId)]);
            }
            foreach($manifest['spatial'] as $field){
                $guard(function() use ($dbname, $field, $shardId, $manifest){
                    $tree = $this->rtree->buildSegmentSpatialIndex($dbname, $field, $shardId,
                        isset($manifest['spatialOptions'][$field]['nodeSize']) ? ['nodeSize' => $manifest['spatialOptions'][$field]['nodeSize']] : []);
                    $this->rtree->writeSpatialIndex($dbname, $field, $tree, $shardId);
                    if($shardId !== null){
                        $this->rtree->updateGlobalSpatialShard($dbname, $field, $shardId, $tree);
                    }
                }, 'spatial ' . $field, [$this->rtree->getSpatialIndexPath($dbname, $field, $shardId)]);
            }
            $this->cache->evictBetweenSegments();
        }
        if($this->segments->isSharded($dbname)){
            foreach($manifest['fields'] as $field){
                $guard(function() use ($dbname, $field){
                    $this->fieldIndex->rebuildGlobalFieldIndex($dbname, $field);
                }, 'global field ' . $field, [$this->fieldIndex->getGlobalFieldIndexPath($dbname, $field)]);
            }
        }
    }

    /**
     * Index of a segment a writer is about to change (exclusive lock), taken out of the cache: the
     * writer changes it in place and stores it back (storeSegmentIndex). Checked against the data file
     * when it was not yet known to match it: an unterminated tail (crashed append) is quarantined and cut
     * off, so the next line can't be glued to it. A checked index stays checked until its cache is
     * dropped; appendToFile() compares the size it finds with the index anyway (no extra system call),
     * which catches anything else.
     * @return array|null
     */
    public function writableSegmentIndex($dbname, $shardId){
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null){
            return null;
        }
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        $path = self::ckptPath($dataPath);
        if(!isset($this->writableIndexes[$path])){
            clearstatcache(true, $dataPath);
            $size = (int)@filesize($dataPath);
            if($size < (int)$index['s']){
                $index = $this->repairSegmentIndex($dbname, $shardId);
                clearstatcache(true, $dataPath);
                $size = (int)@filesize($dataPath);
            }
            if($size > (int)$index['s']){
                $this->recovery->quarantineAndCut($dbname, $shardId, $dataPath, (int)$index['s'], $size);
            }
            $this->writableIndexes[$path] = true;
        }
        unset($this->indexCache[$path]); // the caller is its only holder until it stores it back
        return $index;
    }

    /**
     * A writer's index goes back to the cache unchanged (nothing to write after all)
     */
    public function releaseSegmentIndex($dbname, $shardId, array $index){
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        $this->indexCache[$path] = $index;
        $this->verifiedIndexes[$path] = true;
    }

    /**
     * Rebuild a segment index from the whole data file and write its checkpoint (exclusive lock): new
     * segment epoch, as there is no checkpoint it continues
     * @param array|null $old The checkpoint found (created time, key counter, records in damaged lines)
     * @return array
     */
    public function rebuildSegmentIndexFromData($dbname, $shardId, $old = null){
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        if($old === null){
            $old = $this->checkpointOnDisk(self::ckptPath($dataPath));
        }
        $index = $this->newJsonlIndex();
        if($old !== null){
            $index['created'] = $old['created'] ?? $index['created'];
            if($shardId === null && isset($old['n'])){
                $index['n'] = (int)$old['n'];
            }
        }
        clearstatcache(true, $dataPath);
        $this->replaySegment($dbname, $shardId, $index, (int)@filesize($dataPath), $old); // its entries only if a line is damaged
        $index['e'] = bin2hex(random_bytes(4));
        $path = self::ckptPath($dataPath);
        $this->indexCache[$path] = $index;
        $this->verifiedIndexes[$path] = true;
        $this->writeCheckpoint($dbname, $shardId, $index);
        return $this->indexCache[$path];
    }

    /**
     * Classify one complete data line: ['r', key, 0, length] (record), ['t', key] (tombstone), or null
     * when it isn't a valid line of this segment
     */
    public function classifyLine($json, $base, $limit){
        $record = json_decode($json, true);
        if(!is_array($record)){
            return null;
        }
        if(count($record) === 1 && isset($record['__nonedb_deleted']) && is_int($record['__nonedb_deleted'])){
            $key = $record['__nonedb_deleted'];
            return $key >= $base && ($limit === null || $key < $limit) ? ['t', $key] : null;
        }
        if(isset($record['key']) && is_int($record['key'])){
            $key = $record['key'];
            return $key >= $base && ($limit === null || $key < $limit) ? ['r', $key, 0, strlen($json)] : null;
        }
        return null;
    }

    /**
     * Find a record or tombstone at the end of a line that doesn't decode (a damaged region before it).
     * First where the earlier index says a record ended; then candidates from the
     * rightmost "{" leftwards: inside a record no "{" starts a JSON text that also ends the line, so the
     * first one that decodes is where the record starts.
     * @return array|null Like classifyLine(), with the record's offset inside the line
     */
    public function salvageLine($json, $lineOffset, array $oldEnds, $base, $limit){
        $end = $lineOffset + strlen($json);
        if(isset($oldEnds[$end]) && $oldEnds[$end][1] > $lineOffset){
            $start = $oldEnds[$end][1] - $lineOffset;
            $entry = $this->classifySalvaged(substr($json, $start), $base, $limit);
            if($entry !== null && $entry[0] === 'r' && $entry[1] === $oldEnds[$end][0]){
                return ['r', $entry[1], $start, strlen($json) - $start];
            }
        }
        $pos = strlen($json);
        for($tries = 0; $tries < 4096; $tries++){
            $pos = $pos > 0 ? strrpos($json, '{', $pos - strlen($json) - 1) : false;
            if($pos === false || $pos === 0){
                return null;
            }
            $entry = $this->classifySalvaged(substr($json, $pos), $base, $limit);
            if($entry !== null){
                return $entry[0] === 't' ? $entry : ['r', $entry[1], $pos, strlen($json) - $pos];
            }
        }
        return null;
    }

    /**
     * classifyLine() for a salvage candidate: also requires an object whose last member is "key"
     */
    private function classifySalvaged($candidate, $base, $limit){
        $entry = $this->classifyLine($candidate, $base, $limit);
        if($entry === null || $entry[0] === 't'){
            return $entry;
        }
        $record = json_decode($candidate, true);
        $keys = array_keys($record);
        return end($keys) === 'key' && $keys !== range(0, count($keys) - 1) ? $entry : null;
    }

    // Segment index: checkpoint + replay (docs/STORAGE_FORMAT.md §6.4). The data file is a log; the .kix
    // file is a checkpoint of the index at byte offset "s" of it: a 512-byte JSON header ("kind":"kix", "v":1,
    // layout, base, slots, live, n next key, d garbage lines, t tombstone lines, s, h = crc32b of the up to 256
    // bytes before s, e segment epoch, z shard size, sid shard id), then the slots in 4 KiB blocks with their crc
    // (KeyIndex). Loading reads the checkpoint and replays the complete lines after S (an unterminated tail is never
    // part of it, readers never cut it). Writes append to the data and update the cached index only; a
    // write session writes a new checkpoint of a segment it changed once its tail reached 64 lines plus
    // 1/16 of the live records, or 1 MiB (checkpointTouchedSegments). A checkpoint counts only for its data
    // file: S within it, at a line end, h matching; a data file is replaced only after its checkpoint was
    // deleted. "e" is the segment's data epoch for the secondary indexes: new when the data file is
    // replaced or the index rebuilt without a checkpoint, assigned only under the exclusive lock.
    // Runtime keys of a cached index (never written): "ck" = S of the checkpoint on disk (-1: none),
    // "tl" = lines after it.

    /** A key index body of at most DECODE_MAX_KEYS keys may be decoded into arrays */
    private const DECODE_MAX_KEYS = 250000;
    /** A body of at most this many bytes, in a database of few segments, is decoded at its first use (§6.4) */
    private const DECODE_AT_FIRST_USE = 65536;
    /** Estimated memory of a decoded key index per key */
    private const DECODED_KEY_BYTES = 80;
    /**
     * Keys whose decoding costs about what one key lookup costs more on a body than on arrays (measured:
     * 0.17 µs per key to decode, 1.3 µs more per lookup on a body)
     */
    private const KEYS_PER_LOOKUP = 8;

    /**
     * Decode a cached key index body into arrays (lookups at array speed) when it has at most
     * DECODE_MAX_KEYS keys and memory allows (CacheManager::decodeFits()). In a database of few segments
     * (Segments::FEW_SEGMENTS: each is in constant use): at its first use when the body is at most
     * DECODE_AT_FIRST_USE bytes (loadSegmentIndex()), else at its second operation. Otherwise once the
     * lookups through the body have paid for decoding: operations (one key each, counted in readJsonlIndex())
     * and keys looked up in bulk (noteLookups()), KEYS_PER_LOOKUP live keys each. Light use keeps the body:
     * a cold query over many segments, a few reads of each segment.
     */
    private function decodeKeyIndex($path){
        $live = $this->indexCache[$path]['live'];
        if($live <= self::DECODE_MAX_KEYS && \noneDB\Internal\Cache\CacheManager::decodeFits(self::DECODED_KEY_BYTES * $live)){
            $this->indexCache[$path] = KeyIndex::materialize($this->indexCache[$path]);
            $this->cacheGrew = true;
        }
    }

    /**
     * Start of an operation (lock session) on a database of few segments: the key index bodies an earlier
     * operation loaded are decoded now (§6.4), whether this operation reads them or not. Called once the
     * lock state was checked: an index another process made stale is gone from the cache by then.
     */
    public function decodeDueIndexes($lockPath){
        foreach($this->decodeDue[$lockPath] as $path => $unused){
            if(isset($this->indexCache[$path], $this->verifiedIndexes[$path]) && $this->indexCache[$path]['kb'] !== ''){
                $this->decodeKeyIndex($path);
            }
        }
        unset($this->decodeDue[$lockPath]);
    }

    /**
     * A segment's key index for a read of every record: a whole read decodes the body anyway, so the decoded
     * index is kept (when memory allows) and the writes that often follow such a read work on arrays
     */
    public function decodedIndex($dbname, $shardId){
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null || $index['kb'] === ''){
            return $index;
        }
        $path = self::ckptPath($this->segments->segDataPath($dbname, $shardId));
        $this->decodeKeyIndex($path);
        return $this->indexCache[$path] ?? $index;
    }

    /** Keys looked up in bulk through the key index body of a data file (see decodeKeyIndex()) */
    public function noteLookups($dataPath, $n){
        $path = self::ckptPath($dataPath);
        if(!isset($this->indexCache[$path]) || $this->indexCache[$path]['kb'] === ''){
            return;
        }
        $this->indexCache[$path]['looked'] += $n;
        if(($this->indexCache[$path]['uses'] + $this->indexCache[$path]['looked']) * self::KEYS_PER_LOOKUP >= $this->indexCache[$path]['live']){
            $this->decodeKeyIndex($path);
        }
    }
    public const CHECKPOINT_LINES = 64;
    public const CHECKPOINT_BYTES = 1048576;
}
}
namespace noneDB\Internal\Storage {

use function array_combine, array_fill, array_filter, array_intersect_key, array_key_last, array_keys,
    array_replace, array_slice, array_values, count, crc32, in_array, intdiv, is_array, is_int, is_string,
    fclose, fopen, fread, fstat, json_decode, json_encode, ksort, max, min, pack, range, rtrim, str_pad, strlen, strrpos, substr, time, unpack;
use const JSON_UNESCAPED_SLASHES, PHP_INT_MAX, PHP_INT_MIN;

/**
 * A key index is an array: the checkpoint's members (n, d, t, s, e, created, updated, z, sid), the runtime
 * members ck (bytes the checkpoint on disk covers, -1 none) and tl (lines replayed after it), and the keys:
 * - kb: the verified body of the checkpoint (blocks of 4096 bytes with their crc), kl its layout ('dense' or
 *   'sparse'), kbase its first key, kslots its slots (dense) or entries (sparse), kmax its highest live key;
 * - p / l: offset and length of every key written since the checkpoint; x: keys of the body deleted since;
 * - live: the number of live keys.
 * No per-key array of the whole segment is built: a body costs 12 bytes per slot.
 * @internal
 */
final class KeyIndex {
    public const HEADER = 512;
    public const BLOCK = 4096;
    public const DENSE_SLOTS = 341;   // 341 offsets (8 bytes) then 341 lengths (4 bytes) = 4092 bytes + the crc
    public const SPARSE_ENTRIES = 255; // 255 keys (4), offsets (8), lengths (4) = 4080 bytes, zero bytes up to 4092, the crc
    /** crc32 of bytes followed by their own crc32 (u32 little-endian): a block checks with one call */
    public const CRC_RESIDUE = 0x2144DF1C;

    /** An empty key index */
    public static function emptyIndex(): array {
        $now = time();
        return ['n' => 0, 'd' => 0, 't' => 0, 's' => 0, 'e' => null, 'created' => $now, 'updated' => $now,
            'ck' => -1, 'ckh' => null, 'tl' => 0, 'kb' => '', 'kl' => 'dense', 'kbase' => 0, 'kslots' => 0, 'kmax' => -1, 'live' => 0,
            'p' => [], 'l' => [], 'x' => [], 'looked' => 0, 'uses' => 0, 'ses' => -1];
    }

    // ------------------------------------------------------------------ lookups

    /** [offset, length] of a key in the body, or null */
    public static function baseGet(array $index, int $key): ?array {
        $kb = $index['kb'];
        if($kb === ''){
            return null;
        }
        $local = $key - $index['kbase'];
        if($local < 0){
            return null;
        }
        $slots = $index['kslots'];
        if($index['kl'] === 'dense'){
            if($local >= $slots){
                return null;
            }
            $block = intdiv($local, self::DENSE_SLOTS);
            $first = $block * self::DENSE_SLOTS;
            $m = $slots - $first < self::DENSE_SLOTS ? $slots - $first : self::DENSE_SLOTS;
            $at = $block * self::BLOCK;
            $length = unpack('V', $kb, $at + 8 * $m + 4 * ($local - $first))[1];
            return $length === 0 ? null : [unpack('P', $kb, $at + 8 * ($local - $first))[1], $length];
        }
        $lo = 0;
        $hi = $slots - 1;
        while($lo <= $hi){
            $mid = ($lo + $hi) >> 1;
            $block = intdiv($mid, self::SPARSE_ENTRIES);
            $first = $block * self::SPARSE_ENTRIES;
            $i = $mid - $first;
            $at = $block * self::BLOCK;
            $found = unpack('V', $kb, $at + 4 * $i)[1];
            if($found === $local){
                $m = $slots - $first < self::SPARSE_ENTRIES ? $slots - $first : self::SPARSE_ENTRIES;
                return [unpack('P', $kb, $at + 4 * $m + 8 * $i)[1], unpack('V', $kb, $at + 12 * $m + 4 * $i)[1]];
            }
            if($found < $local){
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        return null;
    }

    public static function has(array $index, int $key): bool {
        return isset($index['p'][$key]) || ($index['kb'] !== '' && !isset($index['x'][$key]) && self::baseGet($index, $key) !== null);
    }

    public static function count(array $index): int {
        return $index['live'];
    }

    /**
     * Point a key at a line; true when the key had a record before (its old line becomes garbage)
     */
    public static function set(array &$index, int $key, int $offset, int $length): bool {
        $existed = isset($index['p'][$key]) || (!isset($index['x'][$key]) && self::baseGet($index, $key) !== null);
        $index['p'][$key] = $offset;
        $index['l'][$key] = $length;
        unset($index['x'][$key]);
        if(!$existed){
            $index['live']++;
        }
        return $existed;
    }

    /**
     * Point the keys of appended lines at them: $lines [key => line without "\n"] written in this order from
     * byte $offset. Returns how many keys had a record before (their old lines are garbage now).
     */
    public static function setLines(array &$index, array $lines, int $offset): int {
        $replaced = 0;
        $added = 0;
        $p = &$index['p'];
        $l = &$index['l'];
        if($index['kb'] === ''){
            foreach($lines as $key => $json){
                if(isset($p[$key])){
                    $replaced++;
                } else {
                    $added++;
                }
                $p[$key] = $offset;
                $l[$key] = $length = strlen($json);
                $offset += $length + 1;
            }
        } else {
            $x = &$index['x'];
            foreach($lines as $key => $json){
                if(isset($p[$key]) || (!isset($x[$key]) && self::baseGet($index, $key) !== null)){
                    $replaced++;
                } else {
                    $added++;
                }
                $p[$key] = $offset;
                $l[$key] = $length = strlen($json);
                unset($x[$key]);
                $offset += $length + 1;
            }
            unset($x);
        }
        unset($p, $l);
        $index['live'] += $added;
        return $replaced;
    }

    /** Delete keys; returns how many had a record */
    public static function removeMany(array &$index, array $keys): int {
        $removed = 0;
        if($index['kb'] === ''){
            // A decoded index: its arrays only, no call per key
            $p = &$index['p'];
            $l = &$index['l'];
            foreach($keys as $key){
                if(isset($p[$key])){
                    unset($p[$key], $l[$key]);
                    $removed++;
                }
            }
            unset($p, $l);
            $index['live'] -= $removed;
            return $removed;
        }
        foreach($keys as $key){
            if(self::remove($index, (int)$key)){
                $removed++;
            }
        }
        return $removed;
    }

    /** Delete a key; true when it had a record */
    public static function remove(array &$index, int $key): bool {
        if(isset($index['p'][$key])){
            unset($index['p'][$key], $index['l'][$key]);
            if(self::baseGet($index, $key) !== null){
                $index['x'][$key] = true;
            }
            $index['live']--;
            return true;
        }
        if(isset($index['x'][$key]) || self::baseGet($index, $key) === null){
            return false;
        }
        $index['x'][$key] = true;
        $index['live']--;
        return true;
    }

    /** The highest live key (-1: none) */
    public static function maxKey(array $index): int {
        $max = $index['kmax'] >= 0 && !isset($index['x'][$index['kmax']]) ? $index['kmax'] : self::bodyMax($index);
        foreach($index['p'] as $key => $unused){
            if($key > $max){
                $max = $key;
            }
        }
        return $max;
    }


    /** Every live key: ascending, but in the order of its arrays for a decoded index (the caller sorts) */
    public static function keys(array $index): array {
        if($index['kb'] === ''){
            return array_keys($index['p']);
        }
        if(empty($index['p']) && empty($index['x'])){
            return self::baseKeys($index);
        }
        // The body's keys, the removed ones out, the delta's in (no array per key)
        $live = self::bodyArrays($index)[1];
        foreach($index['x'] as $key => $unused){
            unset($live[$key]);
        }
        if(!empty($index['l'])){
            foreach($index['l'] as $key => $length){
                $live[$key] = $length;
            }
            ksort($live);
        }
        return array_keys($live);
    }

    /**
     * Offsets and lengths of $keys (all live keys when null) for a batch read: [key => offset], [key => length]
     */
    public static function locate(array $index, ?array $keys): array {
        $positions = [];
        $lengths = [];
        if($keys === null){
            // Every live key: the arrays of a decoded index (the body unpacked in a few C-level calls, the
            // delta applied); in no particular order, the batch read orders by position
            $all = self::materialize($index);
            return [$all['p'], $all['l']];
        }
        $p = $index['p'];
        $l = $index['l'];
        if($index['kb'] === ''){
            // Every key in the arrays (a decoded index): the hot path, no call per key
            foreach($keys as $key){
                if(isset($p[$key])){
                    $positions[$key] = $p[$key];
                    $lengths[$key] = $l[$key];
                }
            }
            return [$positions, $lengths];
        }
        $x = $index['x'];
        foreach($keys as $key){
            if(isset($p[$key])){
                $positions[$key] = $p[$key];
                $lengths[$key] = $l[$key];
            } elseif(!isset($x[$key]) && ($e = self::baseGet($index, (int)$key)) !== null){
                $positions[$key] = $e[0];
                $lengths[$key] = $e[1];
            }
        }
        return [$positions, $lengths];
    }

    /**
     * The same index with every key in p/l (no body): lookups at array speed, about 5× the memory of a body.
     * For a segment in repeated use (plan D6: hot segments are kept decoded within the cache budget).
     */
    public static function materialize(array $index): array {
        if($index['kb'] === ''){
            return $index;
        }
        [$p, $l] = self::bodyArrays($index);
        foreach($index['x'] as $key => $unused){
            unset($p[$key], $l[$key]);
        }
        foreach($index['p'] as $key => $offset){
            $p[$key] = $offset;
            $l[$key] = $index['l'][$key];
        }
        $index['p'] = $p;
        $index['l'] = $l;
        $index['x'] = [];
        $index['kb'] = '';
        $index['kslots'] = 0;
        $index['kmax'] = -1;
        return $index;
    }

    /** The body's live keys as [key => offset], [key => length], ascending (a few C-level calls per block) */
    private static function bodyArrays(array $index): array {
        $kb = $index['kb'];
        $base = $index['kbase'];
        $slots = $index['kslots'];
        $p = [];
        $l = [];
        if($index['kl'] === 'dense'){
            if($slots === 0){
                return [[], []];
            }
            // The offset and length runs of every block joined, then decoded at once
            $offsets = '';
            $lengths = '';
            for($block = 0, $first = 0; $first < $slots; $block++, $first += self::DENSE_SLOTS){
                $m = min(self::DENSE_SLOTS, $slots - $first);
                $at = $block * self::BLOCK;
                $offsets .= substr($kb, $at, 8 * $m);
                $lengths .= substr($kb, $at + 8 * $m, 4 * $m);
            }
            // One array at a time, the runs released once used (the peak of a decode stays near its result)
            $keys = range($base, $base + $slots - 1);
            $l = array_combine($keys, unpack('V*', $lengths));
            $lengths = null;
            if(in_array(0, $l, true)){
                $l = array_filter($l); // slots without a record (length 0)
            }
            $p = array_combine($keys, unpack('P*', $offsets));
            $offsets = $keys = null;
            if(count($l) < $slots){
                $p = array_intersect_key($p, $l);
            }
            return [$p, $l];
        }
        for($block = 0, $first = 0; $first < $slots; $block++, $first += self::SPARSE_ENTRIES){
            $m = min(self::SPARSE_ENTRIES, $slots - $first);
            $at = $block * self::BLOCK;
            $keys = [];
            foreach(unpack('V' . $m, $kb, $at) as $local){
                $keys[] = $base + $local;
            }
            $p += array_combine($keys, unpack('P' . $m, $kb, $at + 4 * $m));
            $l += array_combine($keys, unpack('V' . $m, $kb, $at + 12 * $m));
        }
        return [$p, $l];
    }


    // ------------------------------------------------------------------ the body

    /** Live entries of the body: key => [offset, length], ascending */

    /** Live keys of the body, ascending */
    private static function baseKeys(array $index): array {
        return array_keys(self::bodyArrays($index)[1]);
    }

    /**
     * Encode the live keys of an index as a checkpoint body for a segment whose first key is $base:
     * [layout, slots, body, highest key, live keys]
     */
    public static function encodeBody(array $index, int $base): array {
        [$p, $l] = self::allArrays($index);
        $live = count($p);
        $max = $live === 0 ? -1 : (int)array_key_last($p);
        $slots = $max < $base ? 0 : $max - $base + 1;
        $body = '';
        if($slots <= max(1024, 4 * $live)){
            // Every slot in key order (0 where no record), built without a loop per key
            $zeros = $slots === 0 ? [] : array_fill($base, $slots, 0);
            $offsets = array_values($live === $slots ? $p : array_replace($zeros, $p));
            $lengths = array_values($live === $slots ? $l : array_replace($zeros, $l));
            unset($zeros);
            for($first = 0; $first < $slots; $first += self::DENSE_SLOTS){
                $m = min(self::DENSE_SLOTS, $slots - $first);
                $data = pack('P*', ...array_slice($offsets, $first, $m)) . pack('V*', ...array_slice($lengths, $first, $m));
                $body .= $data . pack('V', crc32($data));
            }
            return ['dense', $slots, $body, $max, $live];
        }
        $keys = [];
        $offsets = [];
        $lengths = [];
        foreach($p as $key => $offset){
            $keys[] = $key - $base;
            $offsets[] = $offset;
            $lengths[] = $l[$key];
            if(count($keys) === self::SPARSE_ENTRIES){
                $data = str_pad(pack('V*', ...$keys) . pack('P*', ...$offsets) . pack('V*', ...$lengths), self::BLOCK - 4, "\0");
                $body .= $data . pack('V', crc32($data));
                $keys = [];
                $offsets = [];
                $lengths = [];
            }
        }
        if(!empty($keys)){
            $data = str_pad(pack('V*', ...$keys) . pack('P*', ...$offsets) . pack('V*', ...$lengths), self::BLOCK - 4, "\0");
            $body .= $data . pack('V', crc32($data));
        }
        return ['sparse', $live, $body, $max, $live];
    }

    /** Every live key as [key => offset], [key => length], ascending by key (no pair per key) */
    private static function allArrays(array $index): array {
        if($index['kb'] === ''){
            $p = $index['p'];
            $l = $index['l'];
        } else {
            [$p, $l] = self::bodyArrays($index);
            foreach($index['x'] as $key => $unused){
                unset($p[$key], $l[$key]);
            }
            foreach($index['p'] as $key => $offset){
                $p[$key] = $offset;
                $l[$key] = $index['l'][$key];
            }
        }
        // Sorted only when not ascending already (sorting copies arrays the index shares), both by key
        $previous = PHP_INT_MIN;
        foreach($p as $key => $unused){
            if($key < $previous){
                ksort($p);
                ksort($l);
                break;
            }
            $previous = $key;
        }
        return [$p, $l];
    }

    /** A derived file's header: its JSON with its own crc as the last member (§6.5) */
    public static function sealHeader(string $json): string {
        return substr($json, 0, -1) . ',"crc":' . crc32($json) . '}';
    }

    /** The members of a derived file's header, or null when its crc doesn't fit */
    public static function openHeader(string $line): ?array {
        $line = rtrim($line, " \n");
        $at = strrpos($line, ',"crc":');
        if($at === false || substr($line, $at + 7, -1) !== (string)crc32(substr($line, 0, $at) . '}')){
            return null;
        }
        $header = json_decode($line, true);
        if(!is_array($header)){
            return null;
        }
        unset($header['crc']);
        return $header;
    }

    /** The bytes of a checkpoint file: header line and body */
    public static function encodeFile(array $header, string $body): string {
        return str_pad(self::sealHeader((string)json_encode($header, JSON_UNESCAPED_SLASHES)), self::HEADER - 1, ' ') . "\n" . $body;
    }

    /**
     * Read a checkpoint file's bytes: [header, body] when its header, body length and every block crc are
     * valid (§6.4), else null
     */
    public static function decodeFile($raw): ?array {
        if(!is_string($raw) || strlen($raw) < self::HEADER){
            return null;
        }
        $header = self::headerOf(substr($raw, 0, self::HEADER));
        if($header === null || strlen($raw) !== self::HEADER + self::bodyLength($header)){
            return null;
        }
        $body = substr($raw, self::HEADER);
        return self::bodyFits($body) ? [$header, $body] : null;
    }

    /**
     * The checkpoint at $path, as decodeFile() returns it, read through one handle: the header, then the
     * body it announces (nothing may follow). Only the body is held, never the file and its body at once.
     */
    public static function readFile(string $path): ?array {
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return null;
        }
        try {
            $header = self::headerOf((string)fread($fp, self::HEADER));
            if($header === null){
                return null;
            }
            $length = self::bodyLength($header);
            if((fstat($fp)['size'] ?? null) !== self::HEADER + $length){
                return null; // a body the file doesn't hold is never asked for
            }
            $body = $length === 0 ? '' : (string)fread($fp, $length);
            if(strlen($body) !== $length){
                return null;
            }
            return self::bodyFits($body) ? [$header, $body] : null;
        } finally {
            fclose($fp);
        }
    }

    /** The header of a checkpoint (its first HEADER bytes, ending with a newline), or null when it is not valid */
    private static function headerOf(string $head): ?array {
        if(strlen($head) !== self::HEADER || $head[self::HEADER - 1] !== "\n"){
            return null;
        }
        $header = self::openHeader(substr($head, 0, self::HEADER - 1));
        if($header === null || ($header['kind'] ?? null) !== 'kix' || ($header['v'] ?? null) !== 1
            || !in_array($header['layout'] ?? null, ['dense', 'sparse'], true)){
            return null;
        }
        foreach(['base', 'slots', 'live', 'n', 'd', 't', 's'] as $member){
            if(!is_int($header[$member] ?? null) || $header[$member] < 0){
                return null;
            }
        }
        // Its body's length is an integer, and so is every key: base + slot, or base + a local key of 32 bits
        return $header['slots'] > intdiv(PHP_INT_MAX, self::BLOCK) || $header['base'] > PHP_INT_MAX - $header['slots'] - 0xFFFFFFFF ? null : $header;
    }

    /** The body length a valid header announces */
    private static function bodyLength(array $header): int {
        $dense = $header['layout'] === 'dense';
        $per = $dense ? self::DENSE_SLOTS : self::SPARSE_ENTRIES;
        $slots = $header['slots'];
        $blocks = $slots === 0 ? 0 : intdiv($slots - 1, $per) + 1;
        return $blocks === 0 ? 0 : ($blocks - 1) * self::BLOCK + ($dense ? ($slots - ($blocks - 1) * $per) * 12 + 4 : self::BLOCK);
    }

    /** True when every block of a body is sealed by its crc (one block at a time, no copy of the body) */
    private static function bodyFits(string $body): bool {
        for($at = 0, $length = strlen($body); $at < $length; $at += self::BLOCK){
            if(crc32(substr($body, $at, self::BLOCK)) !== self::CRC_RESIDUE){
                return false;
            }
        }
        return true;
    }

    /**
     * A key index from a decoded checkpoint (fits checked by the caller)
     */
    public static function fromFile(array $header, string $body): array {
        $index = self::emptyIndex();
        foreach(['n', 'd', 't', 's', 'created', 'updated', 'z', 'sid'] as $member){
            if(isset($header[$member])){
                $index[$member] = $header[$member];
            }
        }
        $index['e'] = isset($header['e']) && is_string($header['e']) ? $header['e'] : null;
        $index['kb'] = $body;
        $index['kl'] = $header['layout'];
        $index['kbase'] = $header['base'];
        $index['kslots'] = $header['slots'];
        $index['live'] = $header['live'];
        $index['kmax'] = self::bodyMax($index);
        $index['ck'] = $header['s'];
        return $index;
    }

    /**
     * The highest key of the body that the delta didn't remove (-1: none), from the top down: no array of the
     * body is made (a deleted highest key of a large segment costs a few lookups, not a decode)
     */
    private static function bodyMax(array $index): int {
        if($index['kl'] === 'sparse'){
            for($i = $index['kslots'] - 1; $i >= 0; $i--){
                $block = intdiv($i, self::SPARSE_ENTRIES);
                $key = $index['kbase'] + unpack('V', $index['kb'], $block * self::BLOCK + 4 * ($i - $block * self::SPARSE_ENTRIES))[1];
                if(!isset($index['x'][$key])){
                    return $key;
                }
            }
            return -1;
        }
        for($local = $index['kslots'] - 1; $local >= 0; $local--){
            $key = $index['kbase'] + $local;
            if(!isset($index['x'][$key]) && self::baseGet($index, $key) !== null){
                return $key;
            }
        }
        return -1;
    }

    /**
     * The index after a checkpoint of it was written: the new body, no delta
     */
    public static function rebased(array $index, string $layout, int $slots, string $body, int $max, int $base): array {
        $index['kb'] = $body;
        $index['kl'] = $layout;
        $index['kbase'] = $base;
        $index['kslots'] = $slots;
        $index['kmax'] = $max;
        $index['p'] = [];
        $index['l'] = [];
        $index['x'] = [];
        return $index;
    }
}
}
namespace noneDB\Internal\Storage {

use function bin2hex, clearstatcache, count, ctype_digit, dirname, file_get_contents, in_array, is_array, is_dir,
    is_file, is_int, is_link, is_string, max, preg_match, random_bytes, rtrim, scandir, sort, strlen, substr, time;
use const LOCK_EX;

/**
 * @internal
 */
final class Layout {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $currentGenerations;
    private $dbDir;
    private $dbHomes;
    private $durable;
    private $formatRecords;
    private $genPaths;
    private $lockPaths;
    private $shardSize;
    private $lockRegistry;
    private $noWrites;
    private $staticCacheEnabled;
    private $staticFileExistsCache;
    private $staticSanitizeCache;
    private $cache;
    private $io;
    private $locks;
    private $recovery;
    private $recoveryLog;
    private $store;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->io, $this->locks, $this->recovery, $this->recoveryLog, $this->store, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->currentGenerations = &$this->k->currentGenerations;
        $this->dbDir = &$this->k->dbDir;
        $this->dbHomes = &$this->k->dbHomes;
        $this->durable = &$this->k->durable;
        $this->formatRecords = &$this->k->formatRecords;
        $this->genPaths = &$this->k->genPaths;
        $this->lockPaths = &$this->k->lockPaths;
        $this->shardSize = &$this->k->shardSize;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->noWrites = &$this->rt->noWrites;
        $this->staticCacheEnabled = &$this->rt->staticCacheEnabled;
        $this->staticFileExistsCache = &$this->rt->staticFileExistsCache;
        $this->staticSanitizeCache = &$this->rt->staticSanitizeCache;
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
        $this->io = null;
        $this->locks = null;
        $this->recovery = null;
        $this->recoveryLog = null;
        $this->store = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Validate a database name.
     * Allowed characters: A-Z a-z 0-9, space, apostrophe and hyphen. Anything else is rejected, never
     * stripped: stripping would let different names collide.
     *
     * @param mixed $dbname Database name
     * @return string The (unchanged) database name
     * @throws \InvalidArgumentException
     */
    public function normalizeDbName($dbname){
        if(is_int($dbname)){
            $dbname = (string)$dbname;
        }
        if(!is_string($dbname)){
            throw new \InvalidArgumentException('noneDB: database name must be a string');
        }
        if(isset($this->staticSanitizeCache[$dbname])){
            return $dbname;
        }
        if($dbname === '' || preg_match("/^[A-Za-z0-9' -]+$/D", $dbname) !== 1){
            throw new \InvalidArgumentException(
                "noneDB: invalid database name '{$dbname}'. Only letters A-Z/a-z, digits, space, apostrophe (') and hyphen (-) are allowed."
            );
        }
        if($this->staticCacheEnabled){
            $this->staticSanitizeCache[$dbname] = true;
        }
        return $dbname;
    }

    public function dbHome($dbname){
        if(isset($this->dbHomes[$dbname])){
            return $this->dbHomes[$dbname];
        }
        if(count($this->dbHomes) >= 4096){
            $this->dbHomes = $this->lockPaths = [];
        }
        $home = $this->dbHomes[$dbname] = $this->dbDir . $this->store->locator($dbname) . '.ndb4/';
        $this->lockPaths[$dbname] = $home . 'LOCK';
        return $home;
    }

    /**
     * Active generation of a database. A database without a valid CURRENT (absent, being created) uses
     * generation 1: the state check in prepareDb() runs before any of its files is used.
     */
    public function generationNumber($dbname){
        $lock = $this->locks->getLockPath($dbname);
        if(isset($this->currentGenerations[$lock])){
            return $this->currentGenerations[$lock];
        }
        $format = $this->readFormatRecord($dbname);
        $current = $format === null ? null : $this->readCurrentRecord($dbname, $format);
        $generation = $current === null ? 1 : $current['generation'];
        if($current !== null){
            $this->currentGenerations[$lock] = $generation;
        }
        return $generation;
    }

    public function genDir($dbname){
        $home = $this->dbHomes[$dbname] ?? $this->dbHome($dbname);
        $generation = $this->currentGenerations[$this->lockPaths[$dbname]] ?? $this->generationNumber($dbname);
        if(($this->genPaths[$dbname]['gen'] ?? null) !== $generation){
            // Paths of the active generation are built once: the same strings (their hashes computed once)
            // serve every lookup of the caches keyed by them
            if(count($this->genPaths) >= 4096){
                $this->genPaths = [];
            }
            $this->genPaths[$dbname] = ['gen' => $generation, 'dir' => $home . 'generations/' . $generation . '/'];
        }
        return $this->genPaths[$dbname]['dir'];
    }

    /**
     * A file of the active generation by its path relative to the generation directory (cached)
     */
    public function genPath($dbname, $relative){
        // Hot path: the cached path while its generation is still the active one (no call)
        $paths = $this->genPaths[$dbname] ?? null;
        if(isset($paths['f'][$relative]) && $paths['gen'] === ($this->currentGenerations[$this->lockPaths[$dbname]] ?? null)){
            return $paths['f'][$relative];
        }
        $dir = $this->genDir($dbname);
        return $this->genPaths[$dbname]['f'][$relative] ?? ($this->genPaths[$dbname]['f'][$relative] = $dir . $relative);
    }

    public static function segmentName($shardId){
        return $shardId === null ? 'main' : 's' . $shardId;
    }

    /**
     * FORMAT of a database, validated; null when missing or invalid
     */
    public function readFormatRecord($dbname){
        $lock = $this->locks->getLockPath($dbname);
        if(isset($this->formatRecords[$lock])){
            return $this->formatRecords[$lock];
        }
        $record = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbHome($dbname) . 'FORMAT'), 'database');
        if($record !== null && !$this->validIdentity($record, $dbname)){
            $record = null;
        }
        if($record !== null){
            $this->formatRecords[$lock] = $record;
        }
        return $record;
    }

    /**
     * The identity members of a FORMAT or metadata record are valid for a database named $dbname (§4.1)
     */
    public function validIdentity(array $record, $dbname){
        return ($record['storageFormat'] ?? null) === \noneDB::STORAGE_FORMAT && is_string($record['databaseId'] ?? null)
            && ($record['name'] ?? null) === (string)$dbname && is_int($record['shardSize'] ?? null) && $record['shardSize'] > 0
            && is_int($record['createdAt'] ?? null);
    }

    /**
     * CURRENT, valid by §4.3 (checksum, databaseId of FORMAT, valid metadata of its generation), or null
     */
    private function readCurrentRecord($dbname, array $format){
        $record = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbHome($dbname) . 'CURRENT'), 'current');
        if($record === null || ($record['databaseId'] ?? null) !== $format['databaseId'] || !is_int($record['generation'] ?? null)
            || $record['generation'] < 1){
            return null;
        }
        return $this->readGenerationMetadata($dbname, $record['generation'], $format['databaseId']) === null ? null : $record;
    }

    private function readGenerationMetadata($dbname, $generation, $databaseId){
        $record = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbHome($dbname) . 'generations/' . $generation . '/metadata'), 'generation');
        if($record === null || ($record['databaseId'] ?? null) !== $databaseId || ($record['generation'] ?? null) !== $generation
            || !$this->validIdentity($record, $dbname) || !in_array($record['layout'] ?? null, ['single', 'sharded'], true)){
            return null;
        }
        return $record;
    }

    public function identityOf(array $record){
        return ['storageFormat' => $record['storageFormat'], 'databaseId' => $record['databaseId'], 'name' => $record['name'],
            'shardSize' => $record['shardSize'], 'createdAt' => $record['createdAt'],
            'formatOptions' => (object)(is_array($record['formatOptions'] ?? null) ? $record['formatOptions'] : [])]; // always a JSON object
    }

    public function writeGenerationMetadata($dbname, $generation, array $identity, $layout){
        $record = ['magic' => 'NONEDB4', 'kind' => 'generation'] + $identity + ['generation' => $generation, 'layout' => $layout];
        if(!$this->io->writeFileAtomic($this->dbHome($dbname) . 'generations/' . $generation . '/metadata', \noneDB\Internal\Storage\StoreRecord::sealRecord($record), 'durable')){
            throw new \RuntimeException("noneDB: cannot write the metadata of generation {$generation} of database '{$dbname}'");
        }
    }

    private function writeCurrentRecord($dbname, $databaseId, $generation){
        $record = ['magic' => 'NONEDB4', 'kind' => 'current', 'databaseId' => $databaseId, 'generation' => $generation];
        if(!$this->io->writeFileAtomic($this->dbHome($dbname) . 'CURRENT', \noneDB\Internal\Storage\StoreRecord::sealRecord($record), 'durable')){
            throw new \RuntimeException("noneDB: cannot write CURRENT of database '{$dbname}'");
        }
        $this->currentGenerations[$this->locks->getLockPath($dbname)] = $generation;
    }

    private function writeFormatRecord($dbname, array $identity){
        $record = ['magic' => 'NONEDB4', 'kind' => 'database'] + $identity;
        if(!$this->io->writeFileAtomic($this->dbHome($dbname) . 'FORMAT', \noneDB\Internal\Storage\StoreRecord::sealRecord($record), 'durable')){
            throw new \RuntimeException("noneDB: cannot write FORMAT of database '{$dbname}'");
        }
        $this->formatRecords[$this->locks->getLockPath($dbname)] = \noneDB\Internal\Storage\StoreRecord::openRecord(\noneDB\Internal\Storage\StoreRecord::sealRecord($record), 'database');
        $this->cache->markFileExists($this->dbHome($dbname) . 'FORMAT');
    }

    /**
     * True when the database exists (FORMAT valid). Cached until the lock epoch changes.
     */
    public function dbPresent($dbname){
        return $this->readFormatRecord($dbname) !== null;
    }

    /**
     * Entries of the database directory other than LOCK (and temp files)
     * @return string[] names
     */
    private function dbHomeEntries($dbname){
        $entries = [];
        foreach(@scandir($this->dbHome($dbname)) ?: [] as $entry){
            if($entry !== '.' && $entry !== '..' && $entry !== 'LOCK' && preg_match('/\.tmp[0-9a-f]{8}$/', $entry) !== 1){
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * Resolve the state of a database directory (§8.3) under its lock; called by prepareDb().
     * Interrupted creation and drop are completed (exclusive); a damaged directory is repaired only with
     * proof, otherwise FORMAT_DAMAGED. Under $noWrites nothing is changed: the state is only reported.
     */
    public function resolveDatabaseState($dbname, $path){
        $flags = $this->lockRegistry[$path]['flags'];
        $format = $this->readFormatRecord($dbname);
        if(!empty($flags['d'])){
            $this->requireExclusiveRecovery($dbname, $path, function() use ($dbname, $path){
                $this->dropDatabaseFiles($dbname, $path);
            });
            return;
        }
        if(!empty($flags['c'])){
            $this->requireExclusiveRecovery($dbname, $path, function() use ($dbname, $path){
                if($this->readFormatRecord($dbname) === null){
                    $this->undoCreation($dbname, $path);
                    return;
                }
                unset($this->lockRegistry[$path]['flags']['c']);
                $this->locks->saveLockState($path);
            });
            return;
        }
        if($format !== null){
            if(isset($this->currentGenerations[$path])){
                return; // CURRENT was validated under this epoch
            }
            $current = $this->readCurrentRecord($dbname, $format);
            if($current === null){
                $this->forgetDatabaseLayout($dbname); // the next operation checks again, never the hot path
                throw new \RuntimeException("noneDB: database '{$dbname}' is damaged: CURRENT is missing or invalid", \noneDB::FORMAT_DAMAGED);
            }
            $this->currentGenerations[$path] = $current['generation'];
            return;
        }
        if(empty($this->dbHomeEntries($dbname))){
            return; // absent: only LOCK
        }
        // Damaged: FORMAT missing or invalid, other entries exist. Repair only with proof (§8.3).
        if($this->noWrites > 0){
            throw new \RuntimeException("noneDB: database '{$dbname}' is damaged: FORMAT is missing or invalid", \noneDB::FORMAT_DAMAGED);
        }
        $this->requireExclusiveRecovery($dbname, $path, function() use ($dbname){
            if($this->readFormatRecord($dbname) !== null){
                return;
            }
            $current = \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbHome($dbname) . 'CURRENT'), 'current');
            $metadata = $current === null || !is_int($current['generation'] ?? null) ? null
                : \noneDB\Internal\Storage\StoreRecord::openRecord(@file_get_contents($this->dbHome($dbname) . 'generations/' . $current['generation'] . '/metadata'), 'generation');
            if($metadata === null || ($metadata['databaseId'] ?? null) !== ($current['databaseId'] ?? false)
                || ($metadata['generation'] ?? null) !== $current['generation'] || !$this->validIdentity($metadata, $dbname)){
                throw new \RuntimeException("noneDB: database '{$dbname}' is damaged: FORMAT is missing or invalid and cannot be repaired with proof", \noneDB::FORMAT_DAMAGED);
            }
            $this->writeFormatRecord($dbname, $this->identityOf($metadata));
            $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'format-repaired', 'generation' => $current['generation']]);
        });
    }

    /**
     * Run a recovery step that needs the exclusive lock; under $noWrites report instead of changing
     */
    private function requireExclusiveRecovery($dbname, $path, callable $fn){
        if($this->noWrites > 0){
            return;
        }
        if($this->lockRegistry[$path]['mode'] === LOCK_EX){
            $fn();
            return;
        }
        $this->locks->withLock($dbname, true, $fn, 'recovery');
    }

    /**
     * Drop every cached view of a database's layout (FORMAT, CURRENT)
     */
    public function forgetDatabaseLayout($dbname){
        $lock = $this->locks->getLockPath($dbname);
        unset($this->currentGenerations[$lock], $this->formatRecords[$lock], $this->genPaths[$dbname]);
        unset($this->staticFileExistsCache[$this->dbHome($dbname) . 'FORMAT']);
    }

    /**
     * Create a database (§8.1); caller holds its lock exclusively and STORE.LOCK shared. Durable mode
     * (DURABILITY.md §3): "c" is synced before anything is created, every new entry with its directory,
     * and the creation commits when FORMAT is durable.
     * restore() (§10.3) passes the identity and $prepare($dir, 1), which builds and verifies generation 1 from
     * a backup; when it fails the creation is undone at once (nothing of the database was committed).
     */
    public function createDatabaseFiles($dbname, ?array $identity = null, ?callable $prepare = null){
        $path = $this->locks->getLockPath($dbname);
        $this->lockRegistry[$path]['flags']['c'] = true;
        $this->locks->saveLockState($path, true);
        // The database directory's entry in dbDir (LOCK's entry in it goes with the next sync of the directory)
        $this->sync->requireSync(null, rtrim($this->dbDir, '/'), 'dir');
        $identity = $identity ?? ['storageFormat' => \noneDB::STORAGE_FORMAT, 'databaseId' => \noneDB\Internal\Storage\StoreRecord::uuid4(), 'name' => (string)$dbname,
            'shardSize' => (int)$this->shardSize, 'createdAt' => time(), 'formatOptions' => new \stdClass()];
        $this->forgetDatabaseLayout($dbname);
        $this->removeTree($this->dbHome($dbname) . 'generations');
        if($prepare === null){
            $this->writeGenerationMetadata($dbname, 1, $identity, 'single');
            // Generation 1 is complete with its empty main segment
            $dataPath = $this->dbHome($dbname) . 'generations/1/segments/main.jsonl';
            if(!$this->io->fsMkdir(dirname($dataPath)) || (!$this->io->fsTouch($dataPath) && !is_file($dataPath))){
                throw new \RuntimeException("noneDB: cannot create database file for '{$dbname}'");
            }
            $this->sync->requireSync(null, dirname($dataPath), 'dir');
        } else {
            $dir = $this->dbHome($dbname) . 'generations/1/';
            try {
                $prepare($dir, 1);
            } catch(\Throwable $e){
                try {
                    $this->undoCreation($dbname, $path);
                } catch(\Throwable $ignored){
                    // "c" stays: the next exclusive session undoes the creation
                }
                throw $e;
            }
            if($this->bufferHoldsFile($dir)){
                // The copied buffer's records are visible once FORMAT is: "b" is synced before CURRENT
                $this->lockRegistry[$path]['flags']['b'] = true;
                $this->locks->saveLockState($path);
            }
        }
        $this->writeCurrentRecord($dbname, $identity['databaseId'], 1);
        $this->writeFormatRecord($dbname, $identity);
        unset($this->lockRegistry[$path]['flags']['c']);
        $this->locks->saveLockState($path);
    }

    /**
     * Undo an interrupted creation (§8.3): nothing of it was committed (creation writes no data, a restore's
     * copy is in its backup), so what it left goes; caller holds the lock exclusively
     */
    private function undoCreation($dbname, $path){
        foreach(['CURRENT', 'generations'] as $entry){
            $this->removeTree($this->dbHome($dbname) . $entry);
        }
        $this->forgetDatabaseLayout($dbname);
        unset($this->lockRegistry[$path]['flags']['c'], $this->lockRegistry[$path]['flags']['b']);
        $this->locks->saveLockState($path);
    }

    /**
     * Drop a database (§8.2), or finish an interrupted drop; caller holds its lock exclusively. LOCK and
     * the directory stay. $request: a dropDB() call, which reports a drop that can't remove FORMAT (its
     * commit point) as not committed; finishing an interrupted one throws instead.
     * Durable mode: "d" (with a new epoch) is synced before FORMAT goes, the drop commits when FORMAT's
     * removal is durable, and "d" is cleared only once the other removals are durable too.
     * @return array ['committed' => bool, 'removed' => int files, 'left' => string[] files not removed]
     */
    public function dropDatabaseFiles($dbname, $path, $request = false){
        if(empty($this->lockRegistry[$path]['flags']['d'])){
            $before = $this->lockRegistry[$path]['flags'];
            $this->lockRegistry[$path]['flags']['d'] = true;
            unset($this->lockRegistry[$path]['flags']['b'], $this->lockRegistry[$path]['flags']['j']);
            try {
                $this->locks->bumpEpoch($dbname); // writes "d" with the new epoch
            } catch(\RuntimeException $e){
                if($e->getCode() === \noneDB::DURABILITY_FAILED){
                    $this->withdrawDrop($dbname, $path, $before, $e);
                }
                throw $e;
            }
        }
        $home = $this->dbHome($dbname);
        clearstatcache(true, $home . 'FORMAT');
        $removed = 0;
        if(is_file($home . 'FORMAT')){
            if(!$this->io->fsUnlink($home . 'FORMAT')){
                // The commit point was not reached: the database stays as it was
                if(!$request){
                    throw new \RuntimeException("noneDB: cannot finish the interrupted drop of database '{$dbname}': its FORMAT can't be removed");
                }
                $this->withdrawDrop($dbname, $path, $before, null);
                return ['committed' => false, 'removed' => 0, 'left' => $this->dbHomeFiles($dbname)];
            }
            $removed++;
            $this->forgetDatabaseLayout($dbname);
            // The commit point: FORMAT's removal is durable
            $this->sync->requireSync(null, rtrim($home, '/'), 'dir', true);
        }
        foreach(['CURRENT', 'generations', 'recovery'] as $entry){
            $removed += $this->removeTree($home . $entry);
        }
        // "d" goes only once these removals are durable: else a power loss could leave the files without it
        $durable = !$this->durable || $this->sync->syncDirectory(rtrim($home, '/')) === true;
        $this->forgetDatabaseLayout($dbname);
        $this->cache->invalidateDbCaches($dbname);
        $left = $this->dbHomeFiles($dbname);
        if(empty($left) && $durable){
            unset($this->lockRegistry[$path]['flags']['d']);
            $this->locks->saveLockState($path);
        }
        // Otherwise "d" stays: the database is gone, the next exclusive session removes what is left (§8.2)
        return ['committed' => true, 'removed' => $removed, 'left' => $left];
    }

    /**
     * A drop that did not reach its commit point: "d" must go (with FORMAT present it would finish the drop),
     * durably in durable mode. If that can't be done either, the outcome is unknown.
     * @throws \RuntimeException OUTCOME_UNKNOWN
     */
    private function withdrawDrop($dbname, $path, array $flags, ?\Throwable $cause){
        $this->lockRegistry[$path]['flags'] = $flags;
        try {
            $this->locks->saveLockState($path, false, true);
        } catch(\RuntimeException $e){
            throw new \RuntimeException("noneDB: the drop of database '{$dbname}' failed and its drop flag could not be withdrawn durably: the outcome is unknown, read the state again", \noneDB::OUTCOME_UNKNOWN, $cause ?? $e);
        }
    }

    /**
     * Files of a database directory except LOCK, relative to it (a drop's leftovers)
     * @return string[]
     */
    private function dbHomeFiles($dbname){
        $home = $this->dbHome($dbname);
        $files = [];
        $walk = function($dir) use (&$walk, &$files, $home){
            foreach(@scandir($dir) ?: [] as $entry){
                if($entry === '.' || $entry === '..'){
                    continue;
                }
                if(is_dir($dir . $entry)){
                    $walk($dir . $entry . '/');
                } elseif($dir . $entry !== $home . 'LOCK'){
                    $files[] = substr($dir . $entry, strlen($home));
                }
            }
        };
        $walk($home);
        $walk = null; // the closure refers to itself: freed now, not by the garbage collector
        sort($files);
        return $files;
    }

    /**
     * Remove a file or a directory tree of a database (fsUnlink for files, rmdir for directories)
     * @return int files removed
     */
    private function removeTree($path){
        $path = rtrim($path, '/');
        clearstatcache(true, $path);
        if(is_file($path) || is_link($path)){
            return $this->io->fsUnlink($path) ? 1 : 0;
        }
        if(!is_dir($path)){
            return 0;
        }
        $removed = 0;
        foreach(@scandir($path) ?: [] as $entry){
            if($entry !== '.' && $entry !== '..'){
                $removed += $this->removeTree($path . '/' . $entry);
            }
        }
        $this->io->fsRmdir($path);
        return $removed;
    }

    /**
     * Generation switch (§5.2). $prepare($dir, $generation) builds the complete new generation in $dir;
     * caller holds the database lock exclusively. Returns the new generation number.
     */
    public function switchGeneration($dbname, callable $prepare){
        $path = $this->locks->getLockPath($dbname);
        $format = $this->readFormatRecord($dbname);
        if($format === null){
            throw new \RuntimeException("noneDB: database '{$dbname}' does not exist", \noneDB::FORMAT_DAMAGED);
        }
        $old = $this->generationNumber($dbname);
        $new = $old + 1;
        $newDir = $this->dbHome($dbname) . 'generations/' . $new . '/';
        $this->removeTree($newDir); // an unreferenced leftover of an earlier, interrupted PREPARE
        // PREPARE: the callback builds and verifies generation $new; CURRENT still names $old. A failed
        // PREPARE leaves nothing behind (the next switch would remove it anyway).
        try {
            $prepare($newDir, $new);
        } catch(\Throwable $e){
            try {
                $this->removeTree($newDir);
            } catch(\Throwable $ignored){
            }
            throw $e;
        }
        // INTENT: g=old>new and a new epoch, synced; from now on no process uses a cache of the old epoch.
        // "b" when the new generation holds buffered records (a restore's), kept when the old one may
        $this->lockRegistry[$path]['flags']['g'] = [$old, $new];
        if($this->bufferHoldsFile($newDir)){
            $this->lockRegistry[$path]['flags']['b'] = true;
        }
        $this->lockRegistry[$path]['epoch'] = bin2hex(random_bytes(4));
        $this->locks->saveLockState($path, true);
        // PUBLISH: the commit point (a failed directory sync after CURRENT's rename: unknown, "g" stays)
        $this->writeCurrentRecord($dbname, $format['databaseId'], $new);
        // CLEANUP
        $oldDir = $this->dbHome($dbname) . 'generations/' . $old;
        $this->removeTree($oldDir);
        clearstatcache(true, $oldDir);
        $cleaned = !is_dir($oldDir) && (!$this->durable || $this->sync->syncDirectory($this->dbHome($dbname) . 'generations') === true);
        // DONE ("g" stays while the old generation is there or its removal may not survive a power loss:
        // the next session finishes the cleanup)
        if($cleaned){
            unset($this->lockRegistry[$path]['flags']['g']);
            if(!$this->bufferHoldsFile($newDir)){
                unset($this->lockRegistry[$path]['flags']['b']);
            }
            $this->locks->saveLockState($path);
        }
        $this->cache->invalidateDbCaches($dbname);
        $this->currentGenerations[$path] = $new;
        return $new;
    }

    /**
     * restore() over a damaged database (§10.3): $prepare($dir, X) builds and verifies generation X, one above
     * the highest number under generations/; then a new epoch without "g" (and "b" when X holds buffered
     * records), CURRENT names X (the commit point), and an invalid FORMAT is rewritten with $identity. The
     * earlier generations stay: nothing proves which of them was committed. Caller holds the lock exclusively.
     * @return int X
     */
    public function replaceDamagedGeneration($dbname, array $identity, callable $prepare){
        $path = $this->locks->getLockPath($dbname);
        $highest = 0;
        foreach(@scandir($this->dbHome($dbname) . 'generations') ?: [] as $entry){
            if(ctype_digit((string)$entry)){
                $highest = max($highest, (int)$entry);
            }
        }
        $new = $highest + 1;
        $dir = $this->dbHome($dbname) . 'generations/' . $new . '/';
        try {
            $prepare($dir, $new);
        } catch(\Throwable $e){
            try {
                $this->removeTree($dir);
            } catch(\Throwable $ignored){
            }
            throw $e;
        }
        unset($this->lockRegistry[$path]['flags']['g'], $this->lockRegistry[$path]['flags']['b']);
        if($this->bufferHoldsFile($dir)){
            $this->lockRegistry[$path]['flags']['b'] = true;
        }
        $this->locks->bumpEpoch($dbname);
        $this->writeCurrentRecord($dbname, $identity['databaseId'], $new);
        if($this->readFormatRecord($dbname) === null){
            $this->writeFormatRecord($dbname, $identity);
        }
        $this->cache->invalidateDbCaches($dbname);
        $this->currentGenerations[$path] = $new;
        return $new;
    }

    /**
     * State of a database for restore() (§10.3), once its lock resolved what it could: 'present' (FORMAT and
     * CURRENT valid, no switch pending), 'absent' (no FORMAT, nothing but LOCK) or 'damaged'
     */
    public function restoreTargetState($dbname){
        $format = $this->readFormatRecord($dbname);
        if($format === null){
            return empty($this->dbHomeEntries($dbname)) ? 'absent' : 'damaged';
        }
        $flags = $this->lockRegistry[$this->locks->getLockPath($dbname)]['flags'] ?? [];
        return empty($flags['g']) && $this->readCurrentRecord($dbname, $format) !== null ? 'present' : 'damaged';
    }

    /**
     * Run $fn reading generation $generation of a database as if it were the active one, under $identity,
     * writing nothing: restore() verifies a generation before it is published (§10.3). Caller holds the lock
     * exclusively.
     */
    public function withGenerationView($dbname, $generation, array $identity, callable $fn){
        $lock = $this->locks->getLockPath($dbname);
        $saved = [$this->currentGenerations[$lock] ?? null, $this->formatRecords[$lock] ?? null];
        $this->cache->invalidateDbCaches($dbname);
        $this->currentGenerations[$lock] = $generation;
        $this->formatRecords[$lock] = ['magic' => 'NONEDB4', 'kind' => 'database'] + $identity;
        unset($this->genPaths[$dbname]);
        $this->noWrites++;
        try {
            return $fn();
        } finally {
            $this->noWrites--;
            $this->cache->invalidateDbCaches($dbname);
            unset($this->genPaths[$dbname], $this->currentGenerations[$lock], $this->formatRecords[$lock]);
            if($saved[0] !== null){
                $this->currentGenerations[$lock] = $saved[0];
            }
            if($saved[1] !== null){
                $this->formatRecords[$lock] = $saved[1];
            }
        }
    }

    /**
     * True when a generation directory's buffer/ holds a pending or flushing file (§5.2: "b")
     */
    private function bufferHoldsFile($dir){
        if(!is_dir($dir . 'buffer')){
            return false;
        }
        foreach(@scandir($dir . 'buffer') ?: [] as $entry){
            if($entry === 'pending.jsonl' || preg_match('/^flushing\.\d+\.jsonl$/', $entry) === 1){
                return true;
            }
        }
        return false;
    }

    /**
     * Recovery of an interrupted generation switch (§5.2): g=N>X found; caller holds the lock exclusively
     */
    public function recoverGenerationSwitch($dbname, $path){
        [$old, $new] = $this->lockRegistry[$path]['flags']['g'];
        $this->forgetDatabaseLayout($dbname);
        $format = $this->readFormatRecord($dbname);
        $current = $format === null ? null : $this->readCurrentRecord($dbname, $format);
        if($current === null || !in_array($current['generation'], [$old, $new], true)){
            $this->forgetDatabaseLayout($dbname);
            throw new \RuntimeException("noneDB: database '{$dbname}' is damaged: a generation switch from {$old} to {$new} was interrupted and CURRENT is invalid; neither generation is removed (restore a backup, see docs/OPERATIONS.md)", \noneDB::FORMAT_DAMAGED);
        }
        $obsolete = $current['generation'] === $new ? $old : $new;
        $obsoleteDir = $this->dbHome($dbname) . 'generations/' . $obsolete;
        $this->removeTree($obsoleteDir);
        clearstatcache(true, $obsoleteDir);
        if(is_dir($obsoleteDir)){
            // CURRENT decides, so the data is consistent; what is left is reported by checkIntegrity()
            // instead of being retried by every later operation
            $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'generation-cleanup-failed', 'committed' => $current['generation'], 'left' => $obsolete]);
        } else {
            $this->recoveryLog->logRecoveryEvent($dbname, ['action' => 'generation-recovered', 'committed' => $current['generation'], 'removed' => $obsolete]);
        }
        unset($this->lockRegistry[$path]['flags']['g']);
        $this->locks->saveLockState($path);
        $this->cache->invalidateDbCaches($dbname);
        $this->currentGenerations[$path] = $current['generation'];
    }
}
}
namespace noneDB\Internal\Storage {

use function array_chunk, array_key_first, array_key_last, array_keys, array_pop, explode, asort, bin2hex, clearstatcache, count, filesize, end, fclose, file_exists, file_get_contents, fopen, fread, fseek, implode,
    fstat, is_array, is_int, is_string, json_decode, ksort, max, random_bytes, reset, sort, strlen, substr;
use const LOCK_SH, SEEK_SET, SORT_NUMERIC;

/**
 * @internal
 */
final class RecordIO {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $appendMismatch;
    private $indexCache;
    private $fieldIndexCache;
    private $cacheTouch;
    private $sessionLimit;
    private $cacheTick;
    private $cacheGrew;
    private $verifiedIndexes;
    private $writableIndexes;
    private $lockRegistry;
    private $cache;
    private $checkpoints;
    private $io;
    private $locks;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->checkpoints, $this->io, $this->locks, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->appendMismatch = &$this->k->appendMismatch;
        $this->indexCache = &$this->k->indexCache;
        $this->fieldIndexCache = &$this->k->fieldIndexCache;
        $this->cacheTouch = &$this->rt->cacheTouch;
        $this->sessionLimit = &$this->rt->sessionLimit;
        $this->cacheTick = &$this->rt->cacheTick;
        $this->cacheGrew = &$this->rt->cacheGrew;
        $this->verifiedIndexes = &$this->k->verifiedIndexes;
        $this->writableIndexes = &$this->k->writableIndexes;
        $this->lockRegistry = &$this->rt->lockRegistry;
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
        $this->locks = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // RECORD I/O
    // ==========================================

    /** A batch whose records lie within this many bytes is read with one call */
    private const SPAN = 262144;

    /**
     * Batch read multiple JSONL records (one open, 64KB buffered sequential reads)
     * @param string $path File path
     * @param array $positions [key => offset, ...]
     * @param array $lengths [key => length, ...]
     * @param bool $mismatch Set to true when a line doesn't hold the expected record
     * @return array [key => record, ...]
     */
    public function readJsonlRecordsBatch($path, $positions, $lengths, &$mismatch = false, $held = null){
        $mismatch = false;
        if(empty($positions)){
            return [];
        }

        if(count($positions) === 1){
            // One record (a key lookup): its bytes only, in one call
            $key = array_key_first($positions);
            $length = (int)($lengths[$key] ?? 0);
            if($length > self::SPAN){
                clearstatcache(true, $path); // a length the file doesn't hold is never asked of it, as large as it says
                $length = $positions[$key] + $length > (int)@filesize($path) ? 0 : $length;
            }
            $json = $length <= 0 ? false : ($held !== null && $positions[$key] + $length <= strlen($held)
                ? substr($held, $positions[$key], $length) : @file_get_contents($path, false, null, (int)$positions[$key], $length));
            $record = is_string($json) ? json_decode($json, true) : null;
            if(!is_array($record) || !isset($record['key']) || $record['key'] !== (int)$key){
                $mismatch = true;
                return [];
            }
            return [$key => $record];
        }

        $records = [];
        $bufferSize = 65536;
        $buffer = '';
        $bufferStart = -1;
        $bufferEnd = -1;

        // Read in file order. Appends keep the index in that order; updated records moved to the end.
        $inOrder = true;
        $previous = -1;
        foreach($positions as $position){
            if($position < $previous){
                $inOrder = false;
                break;
            }
            $previous = $position;
        }
        if(!$inOrder){
            asort($positions, SORT_NUMERIC);
        }

        // Records within one span of at most SPAN bytes (the keys of a small segment, a region): the span in
        // one call. Otherwise records close together on average (a scan): 64 KB at a time; sparse ones: 64 KB
        // when the next record ends within them, else the record alone (look-ahead in read order)
        $first = (int)reset($positions);
        $lastKey = array_key_last($positions);
        $spanEnd = (int)$positions[$lastKey] + (int)($lengths[$lastKey] ?? 0);
        $handle = null;
        if($held !== null && $spanEnd <= strlen($held)){
            $buffer = $held;
            $bufferStart = 0;
            $bufferEnd = strlen($held);
        } elseif($spanEnd - $first <= self::SPAN){
            $buffer = $spanEnd > $first ? @file_get_contents($path, false, null, $first, $spanEnd - $first) : '';
            if(!is_string($buffer)){
                $mismatch = true;
                return [];
            }
            $bufferStart = $first;
            $bufferEnd = $first + strlen($buffer);
        } else {
            $handle = @fopen($path, 'rb');
            if($handle === false){
                $mismatch = true;
                return [];
            }
            $fileEnd = (int)(fstat($handle)['size'] ?? 0);
        }
        $dense = (int)end($positions) - $first <= ($bufferSize >> 2) * (count($positions) - 1);
        $keys = $dense ? null : array_keys($positions);
        $i = 0;
        foreach($positions as $key => $offset){
            $offset = (int)$offset;
            $length = (int)($lengths[$key] ?? 0);
            if($length <= 0){
                $mismatch = true;
                $i++;
                continue;
            }

            if($offset >= $bufferStart && ($offset + $length) <= $bufferEnd){
                $json = substr($buffer, $offset - $bufferStart, $length);
            } elseif($handle === null || $offset + $length > $fileEnd){
                $mismatch = true; // the record ends past what was read: the data is shorter than its index
                $i++;
                continue;
            } else {
                if($dense){
                    $near = true;
                } else {
                    $next = $keys[$i + 1] ?? null;
                    $near = $next !== null && (int)$positions[$next] + (int)($lengths[$next] ?? 0) <= $offset + $bufferSize;
                }
                fseek($handle, $offset, SEEK_SET);
                $buffer = (string)fread($handle, $near ? max($length, $bufferSize) : $length);
                $bufferStart = $offset;
                $bufferEnd = $offset + strlen($buffer);
                $json = substr($buffer, 0, $length);
            }

            if(!$dense){
                $i++;
            }
            $record = json_decode((string)$json, true);
            if(!is_array($record) || !isset($record['key']) || $record['key'] !== (int)$key){
                $mismatch = true;
                continue;
            }
            $records[$key] = $record;
        }

        if($handle !== null){
            fclose($handle);
        }
        return $records;
    }

    /** Records per batch of a read over a whole segment: the default shard size (such a segment is read at once) */
    public const BATCH = 10000;

    /**
     * The records of a segment ($keys of it; every record when null) in batches of at most BATCH records, each
     * [key => record] sorted by key, the batches in the order of $keys (every record: ascending). An operation over a
     * segment of any size holds one batch (docs/CONFIGURATION.md "Memory of operations over many segments").
     * A segment that fits one batch: an array of that batch (nothing held, a read as it always was); a larger one: a
     * generator. Iterated by reference, a caller that writes between batches sets its variable to null first, so
     * the records it doesn't keep are released, not held through the write.
     * @return iterable<int, array>
     */
    public function eachSegmentBatch($dbname, $shardId, $keys = null, $index = null){
        $index = $index ?? ($keys === null ? $this->checkpoints->decodedIndex($dbname, $shardId) : $this->checkpoints->readJsonlIndex($dbname, $shardId));
        if($index === null){
            return [];
        }
        if(($keys === null ? KeyIndex::count($index) : count($keys)) <= self::BATCH){
            $records = $this->readSegmentRecords($dbname, $shardId, $keys, $index);
            return empty($records) ? [] : [$records];
        }
        // Larger than a batch: the index's arrays once (a body unpacked in a few C-level calls, as a whole read
        // does), then the keys a batch at a time
        return $this->segmentBatches($dbname, $shardId, $keys, KeyIndex::materialize($index));
    }

    /**
     * The batches of a segment larger than one (eachSegmentBatch()), yielded by reference. The writes of a caller
     * between two batches touch only keys it was given: $index still holds the keys of the batches to come
     * @return \Generator<int, array>
     */
    private function &segmentBatches($dbname, $shardId, $keys, array $index){
        if($keys === null){
            $keys = array_keys($index['p']);
            sort($keys, SORT_NUMERIC);
        }
        foreach(array_chunk($keys, self::BATCH) as $chunk){
            $records = $this->readSegmentRecords($dbname, $shardId, $chunk, $index);
            if(!empty($records)){
                yield $records;
            }
        }
    }

    /**
     * Read records of one segment, verified against their keys. A stale or corrupt index is
     * rebuilt from the data file once.
     * @param array|null $keys Keys to read (null = all)
     * @param array|null $index The segment's key index the caller just read (null: read here)
     * @return array [key => record] sorted by key
     */
    public function readSegmentRecords($dbname, $shardId, $keys = null, $index = null){
        for($attempt = 0; $attempt < 2; $attempt++){
            $index = $attempt > 0 ? $this->checkpoints->repairSegmentIndex($dbname, $shardId, true)
                : ($index ?? ($keys === null ? $this->checkpoints->decodedIndex($dbname, $shardId) : $this->checkpoints->readJsonlIndex($dbname, $shardId)));
            if($index === null){
                return [];
            }
            if($keys !== null && $index['kb'] === ''){
                // A decoded index (the hot path): its arrays, no call per key; the lengths as they are
                $positions = [];
                foreach($keys as $key){
                    if(isset($index['p'][$key])){
                        $positions[$key] = $index['p'][$key];
                    }
                }
                $lengths = $index['l'];
            } else {
                [$positions, $lengths] = KeyIndex::locate($index, $keys);
            }
            $dataPath = $index['dp'] ?? $this->segments->segDataPath($dbname, $shardId);
            if($keys !== null && $index['kb'] !== '' && count($keys) >= 16){
                $this->checkpoints->noteLookups($dataPath, count($keys)); // bulk lookups (operations count in readJsonlIndex())
            }
            if($keys === null && (int)$index['d'] === 0 && (int)$index['t'] === 0 && ($records = $this->liveLines($dataPath, $positions, (int)$index['s'])) !== null){
                return $records;
            }
            $mismatch = false;
            $held = null;
            if(empty($index['few']) && ($this->sessionLimit ?? ($this->sessionLimit = \noneDB\Internal\Cache\CacheManager::cacheLimitBytes())) > 0){
                $held = $this->heldData($dbname, $dataPath, $index);
            }
            $records = $this->readJsonlRecordsBatch($dataPath, $positions, $lengths, $mismatch, $held);
            if(!$mismatch){
                ksort($records, SORT_NUMERIC);
                return $records;
            }
            unset($this->fieldIndexCache[$dataPath]);
        }
        throw new \RuntimeException("noneDB: data file of database '{$dbname}' is corrupt and could not be repaired");
    }

    /**
     * Every record of a segment without garbage, sorted by key: its lines in file order are its records, so they are
     * split at once and not cut out by position (about a tenth of such a read), 64 KB at a time. Each line is
     * checked against the index as a read by position is. Null when one doesn't fit: the read by position decides.
     * @param array $positions [key => offset] of every live key
     * @param int $size The bytes the index covers
     * @return array|null [key => record]
     */
    private function liveLines($path, array $positions, $size){
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return null;
        }
        $records = [];
        $carry = '';
        $at = 0;
        $last = -1;
        $sorted = true;
        for($left = $size; $left > 0; $left -= strlen($chunk)){
            $chunk = (string)fread($fp, $left < 65536 ? $left : 65536);
            if($chunk === ''){
                break;
            }
            $lines = explode("\n", $carry . $chunk);
            $carry = array_pop($lines);
            foreach($lines as $line){
                $record = json_decode($line, true);
                $key = $record['key'] ?? null;
                if(!is_int($key) || ($positions[$key] ?? -1) !== $at){
                    fclose($fp);
                    return null;
                }
                $records[$key] = $record;
                $sorted = $sorted && $key > $last;
                $last = $key;
                $at += strlen($line) + 1;
            }
        }
        fclose($fp);
        if(count($records) !== count($positions)){
            return null;
        }
        if(!$sorted){
            ksort($records, SORT_NUMERIC);
        }
        return $records;
    }

    /** A record read from a data file costs an open, a seek and a read: about what reading this many of its bytes at once does */
    private const HELD_BYTES_PER_USE = 131072;

    /**
     * The bytes of a data file in repeated use, held whole within the cache's budget: a record is then a substr(),
     * not an open (§ "Memory" of docs/ARCHITECTURE.md). The caller asks for a database of many segments, in a process
     * with a limit; the bytes are read once the operations have paid for it, under the shared lock: bytes no rollback
     * takes back. They stay while the key index keeps its epoch (the bytes it covers can't change within one), newer
     * lines come from the file, and they are dropped like any cached index.
     * @return string|null
     */
    private function heldData($dbname, $dataPath, array $index){
        $entry = $this->fieldIndexCache[$dataPath] ?? null;
        if($entry !== null){
            if($entry['e'] === $index['e']){
                $this->cacheTouch[$dataPath] = ++$this->cacheTick;
                return $entry['raw'];
            }
            unset($this->fieldIndexCache[$dataPath]); // the file was replaced
        }
        $size = (int)$index['s'];
        if($index['e'] === null || $index['uses'] < 2 || $index['uses'] * self::HELD_BYTES_PER_USE < $size
            || ($this->lockRegistry[$this->locks->getLockPath($dbname)]['mode'] ?? null) !== LOCK_SH
            || !\noneDB\Internal\Cache\CacheManager::decodeFits(2 * $size)){
            return null;
        }
        $raw = @file_get_contents($dataPath, false, null, 0, $size);
        if(!is_string($raw) || strlen($raw) !== $size){
            return null;
        }
        $this->fieldIndexCache[$dataPath] = ['kind' => 'data', 'e' => $index['e'], 'raw' => $raw];
        $this->cacheTouch[$dataPath] = ++$this->cacheTick;
        $this->cacheGrew = true;
        return $raw;
    }

    /**
     * Append records ([key => record]) to a segment and point the index at them.
     * Everything is encoded before anything is written, so an unencodable record writes nothing.
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function appendSegmentRecords($dbname, $shardId, array &$index, array $records){
        if(empty($records)){
            return;
        }
        $lines = $this->io->withExactFloats(function() use ($records){
            $lines = [];
            foreach($records as $key => $record){
                unset($record['key']);
                $record['key'] = (int)$key;
                $lines[$key] = $this->io->encodeRecord($record);
            }
            return $lines;
        });
        // After encoding: a record that can't be encoded leaves no journal entry behind
        $this->locks->markIndexesPending($dbname, $shardId);
        $buffer = implode("\n", $lines) . "\n";
        $dataPath = $this->segments->segDataPath($dbname, $shardId);
        $offset = $this->io->appendToFile($dataPath, $buffer, $dbname, $index['s'] ?? null, $shardId);
        if($offset === false){
            $this->failedAppend($dbname, $shardId);
        }
        $index['s'] = $offset + strlen($buffer);
        $this->cache->markFileExists($dataPath);
        $index['d'] += KeyIndex::setLines($index, $lines, $offset); // a replaced key's old line is garbage now
        $index['tl'] = (int)$index['tl'] + count($lines);
    }

    /**
     * Append tombstones for deleted keys, so an index rebuilt from the data won't resurrect them
     */
    public function appendTombstones($dbname, $shardId, array &$index, array $keys){
        if(empty($keys)){
            return;
        }
        $this->locks->markIndexesPending($dbname, $shardId);
        $buffer = '';
        foreach($keys as $key){
            $buffer .= '{"__nonedb_deleted":' . (int)$key . "}\n";
        }
        $offset = $this->io->appendToFile($this->segments->segDataPath($dbname, $shardId), $buffer, $dbname, $index['s'] ?? null, $shardId);
        if($offset === false){
            $this->failedAppend($dbname, $shardId);
        }
        $index['s'] = $offset + strlen($buffer);
        $index['d'] += KeyIndex::removeMany($index, $keys); // the old record lines
        $index['t'] = (int)$index['t'] + count($keys); // tombstone lines
        $index['tl'] = (int)$index['tl'] + count($keys);
    }

    /**
     * An append to a segment wrote nothing: throw. When the data file didn't have the size the index
     * covers (it changed behind this process's back), the cached index is dropped as well.
     * @throws \RuntimeException
     */
    private function failedAppend($dbname, $shardId){
        if($this->appendMismatch){
            $path = $this->checkpoints->getJsonlIndexPath($dbname, $shardId);
            unset($this->indexCache[$path], $this->verifiedIndexes[$path], $this->writableIndexes[$path]);
            throw new \RuntimeException("noneDB: data file of database '{$dbname}' changed unexpectedly; nothing was written, its index is rebuilt on the next access");
        }
        throw new \RuntimeException("noneDB: failed to write data of database '{$dbname}'");
    }

    /**
     * Create an empty segment (data file + index)
     * @param string $dbname
     * @param int|null $shardId
     * @return bool
     */
    public function createJsonlDatabase($dbname, $shardId = null){
        $path = $this->segments->segDataPath($dbname, $shardId);
        clearstatcache(true, $path);
        if(!file_exists($path) && !$this->io->fsTouch($path)){
            throw new \RuntimeException("noneDB: cannot create data file of database '{$dbname}'");
        }
        $this->cache->markFileExists($path);
        $index = $this->checkpoints->newJsonlIndex();
        $index['e'] = bin2hex(random_bytes(4));
        $this->indexCache[\noneDB\Internal\Storage\Checkpoints::ckptPath($path)] = $index;
        $this->verifiedIndexes[\noneDB\Internal\Storage\Checkpoints::ckptPath($path)] = true;
        // No checkpoint yet (ck -1): the session writes one when it ends, with the first records (until then
        // the new segment replays from 0, which is the same index)
        $this->lockRegistry[$this->locks->getLockPath($dbname)]['touched'][$shardId === null ? 'main' : $shardId] = $shardId;
        return true;
    }
}
}
namespace noneDB\Internal\Storage {

use function array_column, array_map, clearstatcache, count, file_exists, in_array, intdiv, preg_match, scandir,
    strlen, strncmp, strrpos, substr;

/**
 * @internal
 */
final class Segments {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $currentGenerations;
    private $genPaths;
    private $lockPaths;
    private $shardSize;
    private $shardedCache;
    private $layout;
    private $shardMeta;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->layout, $this->shardMeta);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->currentGenerations = &$this->k->currentGenerations;
        $this->genPaths = &$this->k->genPaths;
        $this->lockPaths = &$this->k->lockPaths;
        $this->shardSize = &$this->k->shardSize;
        $this->shardedCache = &$this->k->shardedCache;
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
        $this->shardMeta = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * Path of the main data file (single layout)
     */
    public function getDataPath($dbname){
        return $this->segDataPath($dbname, null);
    }
    // ==========================================
    // STORAGE CORE
    // ==========================================
    //
    // A database is one "segment" in the single layout (segments/main.jsonl + main.kix) or one segment per
    // shard (segments/s{N}.jsonl + s{N}.kix) listed in shards.json, inside its active generation
    // ({locator}.ndb4/generations/{N}/, docs/STORAGE_FORMAT.md §5). Segment ids are null (single) or the
    // shard id. Every write runs under the database's exclusive lock (withLock), reads under a shared
    // lock, so index read-modify-write cycles are never interleaved.
    //
    // Data files are append-only JSONL. Every record line carries its "key"; deletions append a
    // tombstone line {"__nonedb_deleted":key}. The .kix checkpoint maps key => offset/length and stores
    // "s", the data size it covers; loading replays the complete lines after it (Checkpoints, KeyIndex).

    /**
     * Files of a directory whose path starts with $prefix (a full path: directory + start of the name) and
     * whose remaining name matches $regex (if given), sorted. Not glob(): a path containing [ ] * ? is a
     * pattern to glob() and matches nothing.
     * @return string[]
     */
    public function listFilesWithPrefix($prefix, $regex = null){
        $slash = strrpos($prefix, '/');
        $dir = substr($prefix, 0, $slash + 1);
        $start = substr($prefix, $slash + 1);
        $length = strlen($start);
        $files = [];
        foreach(@scandir($dir) ?: [] as $file){
            if($file !== '.' && $file !== '..' && strncmp($file, $start, $length) === 0
                && ($regex === null || preg_match($regex, substr($file, $length)) === 1)){
                $files[] = $dir . $file;
            }
        }
        return $files;
    }

    /**
     * Data file of a shard
     */
    public function getShardPath($dbname, $shardId){
        return $this->segDataPath($dbname, (int)$shardId);
    }

    /**
     * Data file of a segment (null = single layout)
     */
    public function segDataPath($dbname, $shardId){
        $k = $shardId ?? -1;
        // Hot path: the cached path while its generation is still the active one (no call)
        $paths = $this->genPaths[$dbname] ?? null;
        if(isset($paths['seg'][$k]) && $paths['gen'] === ($this->currentGenerations[$this->lockPaths[$dbname]] ?? null)){
            return $paths['seg'][$k];
        }
        $dir = $this->layout->genDir($dbname);
        return $this->genPaths[$dbname]['seg'][$k] ?? ($this->genPaths[$dbname]['seg'][$k] = $dir . 'segments/' . \noneDB\Internal\Storage\Layout::segmentName($shardId) . '.jsonl');
    }

    /**
     * Check if a database is sharded
     * @param string $dbname
     * @return bool
     */
    public function isSharded($dbname){
        $metaPath = $this->layout->genPath($dbname, 'shards.json'); // ShardMeta::getMetaPath(), without the hop
        if(isset($this->shardedCache[$metaPath])){
            return $this->shardedCache[$metaPath];
        }

        clearstatcache(true, $metaPath);
        $result = file_exists($metaPath);
        $this->shardedCache[$metaPath] = $result;
        return $result;
    }

    /**
     * True when the database exists (STORAGE_FORMAT.md §8.3: FORMAT valid)
     */
    public function dbExists($dbname){
        return $this->layout->dbPresent($dbname);
    }

    /**
     * Records per shard of a database: fixed in FORMAT when the database was created
     */
    public function shardSizeOf($dbname){
        $format = $this->layout->readFormatRecord($dbname);
        return $format !== null ? (int)$format['shardSize'] : (int)$this->shardSize;
    }

    /**
     * Segment ids of a database: [null] when not sharded, the shard ids otherwise, [] if it doesn't exist
     */
    public function listSegments($dbname){
        if($this->isSharded($dbname)){
            $meta = $this->shardMeta->getCachedMeta($dbname);
            if($meta === null){
                throw new \RuntimeException("noneDB: metadata of sharded database '{$dbname}' is unreadable");
            }
            return array_map('intval', array_column($meta['shards'], 'id'));
        }
        return $this->layout->dbPresent($dbname) ? [null] : [];
    }

    /** Segments of a database without listing them (0: no database) */
    public function segmentCount($dbname){
        if($this->isSharded($dbname)){
            $meta = $this->shardMeta->getCachedMeta($dbname);
            return $meta === null ? 0 : count($meta['shards']);
        }
        return $this->layout->dbPresent($dbname) ? 1 : 0;
    }

    /** A database of at most this many segments decodes the indexes it uses at once (every segment is hot) */
    public const FEW_SEGMENTS = 16;

    /**
     * Segment that holds a key, or false if that shard doesn't exist
     * @return int|null|false
     */
    public function segmentForKey($dbname, $key){
        if(!$this->isSharded($dbname)){
            return null;
        }
        $shardId = intdiv($key, $this->shardSizeOf($dbname));
        return in_array($shardId, $this->listSegments($dbname), true) ? $shardId : false;
    }
}
}
namespace noneDB\Internal\Storage {

use function array_is_list, array_slice, array_unique, array_values, bin2hex, chr, clearstatcache, count,
    dirname, fclose, fflush, file_get_contents, file_put_contents, flock, fopen, fwrite, hash, hash_equals,
    hash_hmac, implode, in_array, is_array, is_dir, is_file, is_int, is_link, is_string, json_decode, json_encode,
    ord, preg_match, random_bytes, scandir, sort, strlen, substr, time, unlink;
use const FILE_APPEND, JSON_PRESERVE_ZERO_FRACTION, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, LOCK_EX,
    LOCK_NB, LOCK_SH, LOCK_UN, PREG_OFFSET_CAPTURE, SORT_STRING;

/**
 * @internal
 */
final class StoreRecord {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $dbDir;
    private $hashCache;
    private $maintenanceDepth;
    private $metrics;
    private $noWrites;
    private $storeLocks;
    private $storeState;
    private $cache;
    private $config;
    private $io;
    private $locks;
    private $recovery;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->cache, $this->config, $this->io, $this->locks, $this->recovery, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dbDir = &$this->k->dbDir;
        $this->hashCache = &$this->k->hashCache;
        $this->maintenanceDepth = &$this->rt->maintenanceDepth;
        $this->metrics = &$this->rt->metrics;
        $this->noWrites = &$this->rt->noWrites;
        $this->storeLocks = &$this->rt->storeLocks;
        $this->storeState = &$this->rt->storeState;
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
        $this->config = null;
        $this->io = null;
        $this->locks = null;
        $this->recovery = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * keyCheck of the configured key: identifies it in STORE without revealing it
     */
    public function keyCheck(){
        return substr(hash_hmac('sha256', 'nonedb4/key', (string)$this->config->getSecretKey()), 0, 32);
    }

    public static function keyCheckOf($key){
        return substr(hash_hmac('sha256', 'nonedb4/key', (string)$key), 0, 32);
    }

    /**
     * Directory name of a database for the configured key (stable, unpredictable without the key)
     */
    public function locator($dbname){
        $cacheKey = $this->cache->getKeyFingerprint() . '|' . $dbname;
        return $this->hashCache[$cacheKey] ?? ($this->hashCache[$cacheKey] = self::locatorOf($dbname, (string)$this->config->getSecretKey()));
    }

    public static function locatorOf($dbname, $key){
        return substr(hash_hmac('sha256', 'nonedb4/db/' . $dbname, (string)$key), 0, 32);
    }

    /**
     * Encode a metadata record with its checksum (§2)
     */
    public static function sealRecord(array $record){
        unset($record['checksum']);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        $json = json_encode($record, $flags);
        if($json === false){
            throw new \RuntimeException('noneDB: cannot encode a metadata record');
        }
        $record['checksum'] = hash('sha256', $json . "\n");
        return json_encode($record, $flags) . "\n";
    }

    /**
     * Decode and verify a metadata record: null when it is not a valid record of $kind. The checksum is
     * checked on the bytes as written (the record without its last member "checksum"), not on a
     * re-encoding, which could differ (an empty object decodes to an empty array).
     */
    public static function openRecord($raw, $kind){
        if(!is_string($raw) || $raw === '' || substr($raw, -1) !== "\n"
            || preg_match('/,"checksum":"([0-9a-f]{64})"\}\n\z/', $raw, $m, PREG_OFFSET_CAPTURE) !== 1){
            return null;
        }
        $body = substr($raw, 0, $m[0][1]) . "}\n";
        if(!hash_equals(hash('sha256', $body), $m[1][0])){
            return null;
        }
        $record = json_decode(substr($raw, 0, -1), true);
        if(!is_array($record) || ($record['magic'] ?? null) !== 'NONEDB4' || ($record['kind'] ?? null) !== $kind){
            return null;
        }
        unset($record['checksum']);
        return $record;
    }

    /**
     * One STORE copy: [exists, record or null, raw bytes or null]
     */
    public function readStoreCopy($name){
        $path = $this->dbDir . $name;
        $raw = @file_get_contents($path);
        if($raw === false){
            clearstatcache(true, $path);
            return [is_file($path), null, null];
        }
        return [true, $this->validStoreRecord($raw), $raw];
    }

    private function validStoreRecord($raw){
        $record = self::openRecord($raw, 'store');
        $valid = $record !== null && ($record['storageFormat'] ?? null) === \noneDB::STORAGE_FORMAT
            && is_string($record['storeId'] ?? null) && is_int($record['seq'] ?? null) && $record['seq'] >= 1
            && is_array($record['keys'] ?? null) && array_is_list($record['keys']);
        if($valid){
            $keys = $record['keys'];
            foreach($keys as $keyCheck){
                if(!is_string($keyCheck) || preg_match('/^[0-9a-f]{32}$/', $keyCheck) !== 1){
                    $valid = false;
                }
            }
            $sorted = array_values(array_unique($keys));
            sort($sorted, SORT_STRING);
            $valid = $valid && $sorted === $keys;
        }
        return $valid ? $record : null;
    }

    /**
     * The store record by §3.1, null when there is no store. One valid copy repairs the other (under
     * STORE.LOCK; not while this process holds it shared: the next open repairs it); two valid copies that
     * differ, or none valid, are FORMAT_DAMAGED.
     */
    public function loadStoreRecord(){
        [$exists1, $copy1, $raw1] = $this->readStoreCopy('STORE');
        $raw2 = $raw1 === null ? false : @file_get_contents($this->dbDir . 'STORE.2');
        if($raw1 !== null && $raw2 === $raw1){
            $copy2 = $copy1; // the same bytes: one check
            $exists2 = true;
        } else {
            [$exists2, $copy2] = $raw2 === false ? $this->readStoreCopy('STORE.2') : [true, $this->validStoreRecord($raw2)];
        }
        if(!$exists1 && !$exists2){
            return null;
        }
        if($copy1 !== null && $copy2 !== null){
            if($copy1 === $copy2){
                return $copy1;
            }
            throw new \RuntimeException("noneDB: the store record in {$this->dbDir} is damaged (its two copies differ)", \noneDB::FORMAT_DAMAGED);
        }
        $valid = $copy1 ?? $copy2;
        if($valid === null){
            throw new \RuntimeException("noneDB: the store record in {$this->dbDir} is damaged; see repairStore()", \noneDB::FORMAT_DAMAGED);
        }
        $held = $this->storeLocks[$this->dbDir]['mode'] ?? null;
        if($this->noWrites === 0 && $held !== LOCK_SH){
            // The copies are identical outside maintenance (§3.1): the valid one restores the other
            $this->withStoreLock(LOCK_EX, function() use ($valid){
                [, $again1] = $this->readStoreCopy('STORE');
                [, $again2] = $this->readStoreCopy('STORE.2');
                if($again1 === null || $again2 === null){
                    $this->writeStoreCopies($valid);
                    $this->logStoreEvent(['action' => 'store-copy-repaired', 'seq' => $valid['seq']]);
                }
            });
        }
        return $valid;
    }

    /**
     * Write both STORE copies (STORE.2 first) by atomic replacement; caller holds STORE.LOCK exclusively
     */
    public function writeStoreCopies(array $record){
        $content = self::sealRecord($record);
        foreach(['STORE.2', 'STORE'] as $name){
            if(!$this->writeStoreFile($this->dbDir . $name, $content)){
                throw new \RuntimeException("noneDB: cannot write the store record {$name} in {$this->dbDir}");
            }
        }
    }

    /**
     * Atomic replacement of a store-level file (STORE copies, journal): temp + rename, synced in durable
     * mode (temp file, then the directory after the rename: the commit point of the file).
     * Store files belong to no database: STORE.LOCK, not a database lock, protects them.
     * @return bool False when the file could not be written (nothing changed)
     * @throws \RuntimeException DURABILITY_FAILED (temp file sync, nothing changed), OUTCOME_UNKNOWN
     *                           (directory sync after the rename)
     */
    public function writeStoreFile($path, $content){
        $this->recovery->removeStaleTemp($path); // a crashed replacement's temp (§2): STORE.LOCK is held exclusively
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        $fp = @fopen($tmp, 'xb');
        if($fp === false){
            return false;
        }
        $this->io->setPermissions($tmp);
        $ok = fwrite($fp, $content) === strlen($content) && fflush($fp);
        $synced = $ok && $this->sync->trySync($fp, $tmp);
        fclose($fp);
        if(!$ok || !$synced){
            @unlink($tmp);
            if($ok){
                throw $this->sync->failure('file', $path, false);
            }
            return false;
        }
        if(!$this->sync->renameWithRetry($tmp, $path)){
            @unlink($tmp);
            return false;
        }
        clearstatcache(true, $path);
        $this->sync->requireSync(null, dirname($path), 'dir', true);
        return true;
    }

    /**
     * Store-level recovery events (STORE copy repairs, manual rebuilds): .nonedb-maintenance/log.jsonl
     */
    public function logStoreEvent(array $event){
        $dir = $this->dbDir . '.nonedb-maintenance';
        if(!is_dir($dir) && !$this->io->makeDirectory($dir)){
            return;
        }
        $created = !is_file($dir . '/log.jsonl');
        if(@file_put_contents($dir . '/log.jsonl', json_encode(['t' => time()] + $event, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) !== false){
            if($created){
                $this->io->setPermissions($dir . '/log.jsonl');
            }
            $this->metrics['recoveryEvents']++;
            $this->metrics['recoveryActions'][$event['action']] = ($this->metrics['recoveryActions'][$event['action']] ?? 0) + 1;
        }
    }

    /**
     * Run $fn holding STORE.LOCK (re-entrant within the process; a shared hold is never upgraded)
     */
    public function withStoreLock($mode, callable $fn, $nonBlocking = false){
        $dir = $this->dbDir;
        if(isset($this->storeLocks[$dir])){
            if($mode === LOCK_EX && $this->storeLocks[$dir]['mode'] !== LOCK_EX){
                throw new \LogicException('noneDB: STORE.LOCK is held shared and cannot be upgraded');
            }
            $this->storeLocks[$dir]['depth']++;
            try {
                return $fn();
            } finally {
                $this->storeLocks[$dir]['depth']--;
            }
        }
        if(!is_dir($dir) && !$this->io->makeDirectory($dir)){
            throw new \RuntimeException("noneDB: cannot create database directory {$dir}");
        }
        $created = !is_file($dir . 'STORE.LOCK');
        $fp = @fopen($dir . 'STORE.LOCK', 'c+be');
        if($fp !== false && $created){
            $this->io->setPermissions($dir . 'STORE.LOCK');
        }
        if($fp === false){
            throw new \RuntimeException("noneDB: cannot open the store lock in {$dir}", \noneDB::LOCK_FAILED);
        }
        try {
            if($nonBlocking){
                if(!flock($fp, $mode | LOCK_NB)){
                    throw new \RuntimeException("noneDB: the store in {$dir} is in use", \noneDB::MAINTENANCE_BUSY);
                }
            } else {
                $this->locks->acquireFlock($fp, $mode, 'STORE');
            }
        } catch(\Throwable $e){
            fclose($fp);
            throw $e;
        }
        $this->storeLocks[$dir] = ['fp' => $fp, 'mode' => $mode, 'depth' => 1];
        try {
            return $fn();
        } finally {
            unset($this->storeLocks[$dir]);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Entries of dbDir that are not noneDB 4 objects (§3.4). $withStore: the allow-list of a store that
     * has a STORE record; without one only the files an empty location may hold are allowed (§3.2).
     * @return string[]
     */
    private function foreignEntries($withStore){
        $foreign = [];
        foreach(@scandir($this->dbDir) ?: [] as $entry){
            if($entry === '.' || $entry === '..'){
                continue;
            }
            if(in_array($entry, ['.htaccess', 'index.html', '.gitkeep', '.gitignore', '.DS_Store', 'STORE.LOCK'], true)
                || preg_match('/^STORE(\.2)?\.tmp[0-9a-f]{8}$/', $entry) === 1){
                continue;
            }
            if($withStore && (in_array($entry, ['STORE', 'STORE.2', '.nonedb-maintenance', '.nonedb-retained'], true)
                || (preg_match('/^[0-9a-f]{32}\.ndb4$/', $entry) === 1 && is_dir($this->dbDir . $entry) && !is_link($this->dbDir . $entry)))){
                continue; // a database directory; a link or a file of that name is foreign (§3.4)
            }
            $foreign[] = $entry;
        }
        return $foreign;
    }

    /**
     * MAINTENANCE_IN_PROGRESS while a maintenance journal is open (except in the process running it)
     */
    public function checkMaintenanceJournal(){
        if($this->maintenanceDepth > 0){
            return;
        }
        $journal = $this->dbDir . '.nonedb-maintenance/journal.json';
        clearstatcache(true, $journal);
        if(is_file($journal)){
            throw new \RuntimeException("noneDB: maintenance of the store in {$this->dbDir} is in progress or was interrupted; run it again to finish", \noneDB::MAINTENANCE_IN_PROGRESS);
        }
    }

    /**
     * Open the store for this key (§3.2). $create: create the store when the location is empty.
     * @return bool false when there is no store (and $create is false): nothing exists yet
     */
    public function openStore($create){
        $dir = $this->dbDir;
        $keyCheck = $this->keyCheck();
        if(isset($this->storeState[$dir][$keyCheck])){
            return true;
        }
        $this->checkMaintenanceJournal();
        $record = $this->loadStoreRecord();
        if($record === null && !empty($this->foreignEntries(false))){
            // The entries may belong to a store another process created while this one looked: decided
            // again under STORE.LOCK, which every store creation holds
            $record = $this->storeRecordUnderExistingLock();
            if($record === null){
                throw $this->missingStoreError($this->foreignEntries(false));
            }
        }
        if($record === null){
            if(!$create || $this->noWrites > 0){
                return false;
            }
            $this->withStoreLock(LOCK_EX, function() use ($keyCheck, &$record){
                $record = $this->loadStoreRecord();
                if($record !== null){
                    return;
                }
                $foreign = $this->foreignEntries(false);
                if(!empty($foreign)){
                    throw $this->missingStoreError($foreign);
                }
                foreach(@scandir($this->dbDir) ?: [] as $entry){
                    if(preg_match('/^STORE(\.2)?\.tmp[0-9a-f]{8}$/', $entry) === 1){
                        @unlink($this->dbDir . $entry);
                    }
                }
                $record = ['magic' => 'NONEDB4', 'kind' => 'store', 'storageFormat' => \noneDB::STORAGE_FORMAT,
                    'storeId' => self::uuid4(), 'seq' => 1, 'keys' => [$keyCheck]];
                $this->writeStoreCopies($record);
                foreach(['.htaccess' => "Require all denied\nDeny from all\n", 'index.html' => ''] as $name => $content){
                    if(@file_put_contents($this->dbDir . $name, $content) !== false){
                        $this->io->setPermissions($this->dbDir . $name);
                    }
                }
            });
        }
        if(!in_array($keyCheck, $record['keys'], true)){
            throw new \RuntimeException("noneDB: the configured secretKey is not registered for the store in {$dir}", \noneDB::KEY_NOT_REGISTERED);
        }
        $this->storeState[$dir][$keyCheck] = true;
        return true;
    }

    /**
     * The store record read under STORE.LOCK held shared, when STORE.LOCK exists (it is never created
     * here: a refused location stays unchanged); null without a store
     */
    private function storeRecordUnderExistingLock(){
        $dir = $this->dbDir;
        if(isset($this->storeLocks[$dir])){
            return $this->loadStoreRecord();
        }
        $fp = @fopen($dir . 'STORE.LOCK', 'rb');
        if($fp === false){
            return null;
        }
        try {
            $this->locks->acquireFlock($fp, LOCK_SH, 'STORE');
            $this->storeLocks[$dir] = ['fp' => $fp, 'mode' => LOCK_SH, 'depth' => 1];
            try {
                return $this->loadStoreRecord();
            } finally {
                unset($this->storeLocks[$dir]);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * The missing-database path (§3.3): run $fn holding STORE.LOCK shared after the store was re-read,
     * the key re-checked and dbDir listed for foreign entries. $fn decides with that view.
     */
    public function withStoreRevalidated($create, callable $fn){
        if(!$this->storeExists() && !$this->openStore($create)){
            return $fn(); // no store and nothing in dbDir: nothing exists
        }
        return $this->withStoreLock(LOCK_SH, function() use ($fn){
            // Checked again under the lock: a maintenance may have changed the store since it was opened
            $this->checkMaintenanceJournal();
            $record = $this->loadStoreRecord();
            if($record === null || !in_array($this->keyCheck(), $record['keys'], true)){
                unset($this->storeState[$this->dbDir]);
                throw new \RuntimeException("noneDB: the configured secretKey is not registered for the store in {$this->dbDir}", \noneDB::KEY_NOT_REGISTERED);
            }
            $foreign = $this->foreignEntries(true);
            if(!empty($foreign)){
                throw $this->unsupportedFormat($foreign);
            }
            return $fn();
        });
    }

    /**
     * No STORE copy but entries: noneDB 4 objects without their store record are a damaged store,
     * anything else is not a noneDB 4 store
     */
    private function missingStoreError(array $foreign){
        foreach($foreign as $entry){
            if(preg_match('/^[0-9a-f]{32}\.ndb4$/', $entry) === 1 || $entry === '.nonedb-maintenance' || $entry === 'STORE' || $entry === 'STORE.2'){
                return new \RuntimeException("noneDB: the store record in {$this->dbDir} is missing; see repairStore()", \noneDB::FORMAT_DAMAGED);
            }
        }
        return $this->unsupportedFormat($foreign);
    }

    /**
     * True when this process opened the store for its key (it exists and the key is registered)
     */
    public function storeExists(){
        return isset($this->storeState[$this->dbDir][$this->keyCheck()]);
    }

    private function unsupportedFormat(array $foreign){
        $shown = array_slice($foreign, 0, 5);
        return new \RuntimeException("noneDB: {$this->dbDir} contains files that are not noneDB 4 objects ("
            . implode(', ', $shown) . (count($foreign) > 5 ? ', ...' : '') . "): no database is created or answered as missing while they are there (docs/STORAGE_FORMAT.md §3.4)",
            \noneDB::UNSUPPORTED_FORMAT);
    }

    public static function uuid4(){
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
}
namespace noneDB\Internal\Storage {

use function array_intersect_key, array_keys, array_values, count, max;

/**
 * @internal
 */
final class Writer {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $autoCreateDB;
    private $shardingEnabled;
    private $checkpoints;
    private $compaction;
    private $db;
    private $findFilter;
    private $layoutSwitch;
    private $records;
    private $scope;
    private $segments;
    private $shardMeta;
    private $shardRouter;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->checkpoints, $this->compaction, $this->db, $this->findFilter, $this->layoutSwitch, $this->records, $this->scope, $this->segments, $this->shardMeta, $this->shardRouter);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->autoCreateDB = &$this->k->autoCreateDB;
        $this->shardingEnabled = &$this->k->shardingEnabled;
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
        $this->compaction = null;
        $this->db = null;
        $this->findFilter = null;
        $this->layoutSwitch = null;
        $this->records = null;
        $this->scope = null;
        $this->segments = null;
        $this->shardMeta = null;
        $this->shardRouter = null;
        $this->k = null;
        $this->rt = null;
    }

    // ==========================================
    // WRITE PATHS (callers hold the exclusive lock)
    // ==========================================

    /**
     * Insert validated records. Assigns keys, writes data + indexes, updates field/spatial indexes
     * and switches the database to the sharded layout once it has assigned shardSize keys (shardingEnabled).
     * @param array $items Records without "key"
     * @return array ['n' => int] or ['n' => 0, 'error' => string]
     */
    public function insertRecords($dbname, array $items, $startKey = null){
        if(empty($items)){
            return ["n" => 0];
        }
        if(!$this->segments->dbExists($dbname)){
            if(!$this->autoCreateDB){
                return ["n" => 0, "error" => "Database does not exist"];
            }
            $this->db->createDBUnlocked($dbname);
        }

        // With a start key the records get keys startKey, startKey+1, ... and keys that are already
        // stored are skipped, so replaying the same insert after a crash never duplicates anything.
        $keyed = null;
        $endKey = null;
        if($startKey !== null){
            $keyed = [];
            foreach(array_values($items) as $i => $item){
                $keyed[(int)$startKey + $i] = $item;
            }
            $endKey = (int)$startKey + count($items);
            foreach(array_keys($this->findFilter->selectRecords($dbname, ['key' => array_keys($keyed)])) as $existing){
                unset($keyed[$existing]);
            }
        }

        if($this->segments->isSharded($dbname)){
            $this->shardRouter->insertShardedRecords($dbname, $items, $keyed, $endKey);
            return ["n" => count($items)];
        }

        $index = $this->checkpoints->writableSegmentIndex($dbname, null);
        if($index === null){
            $this->records->createJsonlDatabase($dbname, null);
            $index = $this->checkpoints->writableSegmentIndex($dbname, null);
        }

        if($keyed !== null){
            $records = $keyed;
            $index['n'] = max((int)$index['n'], $endKey);
        } else {
            $records = [];
            foreach($items as $item){
                $records[(int)$index['n']] = $item;
                $index['n']++;
            }
        }
        $this->records->appendSegmentRecords($dbname, null, $index, $records);
        $this->checkpoints->storeSegmentIndex($dbname, null, $index);

        if($this->shardingEnabled && $index['n'] >= $this->segments->shardSizeOf($dbname)){
            // Replaces the data file: after this write committed
            $this->scope->runIndependent($dbname, 'convertToSharded', function() use ($dbname){
                return $this->layoutSwitch->convertToSharded($dbname);
            });
        }
        return ["n" => count($items)];
    }

    /**
     * Replace records ([key => full new record]) in one segment
     * @return int Number of records written
     */
    public function updateSegmentRecords($dbname, $shardId, array $newRecords){
        if(empty($newRecords)){
            return 0;
        }
        $index = $this->checkpoints->writableSegmentIndex($dbname, $shardId);
        foreach(array_keys($newRecords) as $key){
            if(!KeyIndex::has($index, $key)){
                unset($newRecords[$key]);
            }
        }
        if(empty($newRecords)){
            $this->checkpoints->releaseSegmentIndex($dbname, $shardId, $index);
            return 0;
        }
        $this->records->appendSegmentRecords($dbname, $shardId, $index, $newRecords); // counts the replaced lines
        $this->checkpoints->storeSegmentIndex($dbname, $shardId, $index);
        $this->compaction->deferCompaction($dbname, $shardId, $index);
        return count($newRecords);
    }

    /**
     * Delete records ([key => record]) from one segment
     * @return int Number of records deleted
     */
    public function deleteSegmentRecords($dbname, $shardId, array $oldRecords){
        if(empty($oldRecords)){
            return 0;
        }
        $index = $this->checkpoints->writableSegmentIndex($dbname, $shardId);
        if($index['kb'] === ''){
            $oldRecords = array_intersect_key($oldRecords, $index['p']); // a decoded index: one call
        } else {
            foreach(array_keys($oldRecords) as $key){
                if(!KeyIndex::has($index, $key)){
                    unset($oldRecords[$key]);
                }
            }
        }
        if(empty($oldRecords)){
            $this->checkpoints->releaseSegmentIndex($dbname, $shardId, $index);
            return 0;
        }
        $keys = array_keys($oldRecords);
        $this->records->appendTombstones($dbname, $shardId, $index, $keys); // drops the keys, counts the lines
        $this->checkpoints->storeSegmentIndex($dbname, $shardId, $index);

        if($shardId !== null){
            $meta = $this->shardMeta->getCachedMeta($dbname, true);
            foreach($meta['shards'] as $i => $shard){
                if((int)$shard['id'] === $shardId){
                    $meta['shards'][$i]['count'] = max(0, $meta['shards'][$i]['count'] - count($keys));
                    $meta['shards'][$i]['deleted'] += count($keys);
                }
            }
            $meta['totalRecords'] = max(0, (int)$meta['totalRecords'] - count($keys));
            $meta['deletedCount'] = (int)($meta['deletedCount'] ?? 0) + count($keys);
            $this->shardMeta->writeMeta($dbname, $meta);
        }

        $this->compaction->deferCompaction($dbname, $shardId, $index);
        return count($keys);
    }
}
}
namespace {
class noneDB {
    /** This library's version */
    public const VERSION = '4.0.0';
    /** The storage format it reads and writes (docs/STORAGE_FORMAT.md) */
    public const STORAGE_FORMAT = 4;

    // Exception codes (RuntimeException::getCode(), docs/STORAGE_FORMAT.md §11, docs/DURABILITY.md §2)
    /** A write failed after its commit point, or undoing it failed: the outcome is unknown; read the state again (the next access recovers) */
    public const OUTCOME_UNKNOWN = 3301;
    /** The database lock could not be taken within lockTimeout, or was lost */
    public const LOCK_FAILED = 3302;
    /** dbDir holds entries that are not noneDB 4 objects: no database is created or answered as missing while they are there */
    public const UNSUPPORTED_FORMAT = 3304;
    /** A store record, FORMAT, CURRENT or generation is damaged and can't be repaired with proof */
    public const FORMAT_DAMAGED = 3305;
    /** 'durability' => 'durable' on a platform that can't provide it (only Linux can) */
    public const UNSUPPORTED_DURABILITY = 3306;
    /** A sync durable mode requires failed before the commit point: the write was rolled back */
    public const DURABILITY_FAILED = 3307;
    /** The configured secretKey is not registered for the store */
    public const KEY_NOT_REGISTERED = 3308;
    /** A key maintenance of the store is running or was interrupted (run it again) */
    public const MAINTENANCE_IN_PROGRESS = 3309;
    /** restore() refused the backup (incomplete or damaged, of another database without force, another shard size): nothing was changed */
    public const RESTORE_REFUSED = 3310;
    /** A key maintenance found a database in use: nothing was changed */
    public const MAINTENANCE_BUSY = 3311;

    /** @var int key of this instance's kernel in Kernel::$instances */
    private $id;

    /**
     * @param array|null $config Settings (docs/CONFIGURATION.md), merged over a .nonedb file when one is found; null:
     *                           the file alone (or dev mode)
     * @throws \InvalidArgumentException for an invalid setting or secretKey
     * @throws \RuntimeException UNSUPPORTED_DURABILITY for 'durable' where it can't be provided
     */
    public function __construct($config = null){
        $kernel = new \noneDB\Internal\Core\Kernel($config);
        $this->id = spl_object_id($this);
        \noneDB\Internal\Core\Kernel::$instances[$this->id] = $kernel;
    }

    /** The main settings for var_dump() and print_r(), the secret masked */
    public function __debugInfo(){
        $kernel = \noneDB\Internal\Core\Kernel::$instances[$this->id] ?? null;
        return $kernel === null ? [] : $kernel->config->debugInfo();
    }

    /**
     * Instances hold a secret and open resources; they can't be serialized
     * @throws \LogicException
     */
    public function __serialize(): array {
        throw new \LogicException('noneDB instances cannot be serialized');
    }

    public function __unserialize(array $data): void {
        throw new \LogicException('noneDB instances cannot be unserialized');
    }

    public function __clone(){
        $source = \noneDB\Internal\Core\Kernel::$instances[$this->id];
        $this->id = spl_object_id($this);
        \noneDB\Internal\Core\Kernel::$instances[$this->id] = $source->cloneKernel();
    }

    public function __destruct(){
        $kernel = \noneDB\Internal\Core\Kernel::$instances[$this->id] ?? null;
        if($kernel !== null){
            unset(\noneDB\Internal\Core\Kernel::$instances[$this->id]);
            $kernel->config->destroyed();
            $kernel->release();
        }
    }

    // ==========================================
    // QUERY BUILDER (FLUENT INTERFACE)
    // ==========================================

    /**
     * Create a new query builder for fluent interface
     * @param string $dbname
     * @return noneDBQuery
     */
    public function query(string $dbname): noneDBQuery {
        return new noneDBQuery($this, \noneDB\Internal\Core\Kernel::$instances[$this->id]->layout->normalizeDbName($dbname));
    }

    /**
     * Check if config file exists
     * @return bool
     */
    public static function configExists(): bool{
        return \noneDB\Internal\Config\Configuration::configExists();
    }


    /**
     * Get the config file template path
     * @return string|null
     */
    public static function getConfigTemplate(): ?string{
        return \noneDB\Internal\Config\Configuration::getConfigTemplate();
    }


    /**
     * Clear config cache (useful for testing)
     * @return void
     */
    public static function clearConfigCache(): void{
        \noneDB\Internal\Config\Configuration::clearConfigCache();
    }


    /**
     * True when development mode is enabled (NONEDB_DEV_MODE constant or environment variable)
     * @return bool
     */
    public static function isDevMode(): bool{
        return \noneDB\Internal\Config\Configuration::isDevMode();
    }


    /**
     * Set development mode programmatically
     * Useful for testing or when env vars are not available
     * @param bool $enabled
     * @return void
     */
    public static function setDevMode(bool $enabled): void{
        \noneDB\Internal\Config\Configuration::setDevMode($enabled);
    }


    /**
     * Clear all static caches (useful for testing or memory management)
     * @return void
     */
    public static function clearStaticCache(){
        return \noneDB\Internal\Cache\CacheManager::clearStaticCache();
    }


    /**
     * Disable static caching (each instance uses its own cache)
     * @return void
     */
    public static function disableStaticCache(){
        return \noneDB\Internal\Cache\CacheManager::disableStaticCache();
    }


    /**
     * Enable static caching (default)
     * @return void
     */
    public static function enableStaticCache(){
        return \noneDB\Internal\Cache\CacheManager::enableStaticCache();
    }


    /**
     * Durability of this instance and of the last write operation of the process (docs/DURABILITY.md §6):
     * - mode: 'performance' or 'durable'
     * - platform: ['os', 'durableSupported', 'notes']
     * - lastOperation: null before the first write, else ['op', 'db', 'outcome' => 'committed'|'rolled_back'|'unknown',
     *   'required' => syncs the mode needed, 'completed' => syncs that succeeded, 'reason' => ?string]
     * - lastSyncError: null or ['path' (relative to dbDir), 'op' => 'file'|'dir', 'time']
     * - syncs: ['file', 'dir', 'failed'] counters of the process
     * @return array
     */
    public function getDurability(): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->sync->getDurability();
    }

    // ==========================================
    // PUBLIC INDEX API
    // ==========================================

    /**
     * Make the key indexes (.kix checkpoints) of a database again from its data files, then its field and
     * spatial indexes where they changed.
     * Normal operation never needs this; it is a recovery tool after files were copied or restored by hand.
     * @param string $dbname
     * @return array ['success' => bool, 'totalRecords' => int, 'time' => string]
     */
    public function rebuildIndex($dbname){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->db->rebuildIndex($dbname);
    }


    /**
     * Get index information for a database
     * @param string $dbname
     * @return array|null
     */
    public function getIndexInfo($dbname){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->db->getIndexInfo($dbname);
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
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->integrity->checkIntegrity($dbname);
    }


    /**
     * Cheap counters of the process and, for a database, its metadata (docs/OPERATIONS.md §1): index cache
     * hits, misses and evictions, index pages read, checkpoints, compactions, lock waits, recovery events,
     * durability, the last checkIntegrity(); a database's counters, sizes and state. Nothing is scanned or written.
     * @return array ['process' => [...]] (+ 'database' => [...] for $dbname)
     */
    public function getMetrics(?string $dbname = null): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->metrics->getMetrics($dbname);
    }


    /**
     * Recovery log of a database: what crash recovery and maintenance did to its files, oldest first. Every
     * entry has "t" (time) and "action" (docs/API.md "getRecoveryLog()" lists them). What reads of this
     * process found and no write session logged yet is included with "persisted" => false (reads never write).
     * @param string $dbname
     * @param int $limit Newest entries to return
     * @return array
     */
    public function getRecoveryLog($dbname, $limit = 100){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->recoveryLog->getRecoveryLog($dbname, $limit);
    }


    /**
     * True when the database exists. With autoCreateDB a missing database is created; without it nothing is
     * created and the answer is false.
     * @param string $dbname
     * @return bool
     * @throws \InvalidArgumentException for names with characters outside [A-Za-z0-9' -]
     */
    public function checkDB($dbname=null){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->db->checkDB($dbname);
    }


    /**
     * Create a database (STORAGE_FORMAT.md §8.1)
     * @param string $dbname
     * @return bool True if created, false if it already existed
     */
    public function createDB($dbname){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->db->createDB($dbname);
    }


    /**
     * Delete a database (STORAGE_FORMAT.md §8.2): every file but its lock file
     * @param string $dbname
     * @return array ['success' => bool, 'files' => int deleted file count]
     */
    public function dropDB($dbname){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->db->dropDB($dbname);
    }


    /**
     * The databases of this key: their names, sorted (false); the same with each one's information (true); or
     * one database's information, false when it doesn't exist (its name)
     * @param bool|string $info
     * @return array|false
     */
    public function getDBs($info=false){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->db->getDBs($info);
    }


    /**
     * The first $limit records of a list of records (a result of find())
     * @param array $array
     * @param int $limit At least 1
     * @return array|false False for invalid arguments
     */
    public function limit($array, $limit=0){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->db->limit($array, $limit);
    }

    /**
     * The records matching the filters, in key order (a "key" filter: in its order)
     * @param string $dbname
     * @param mixed $filters 0/null/[] for all records, or an array of field => value (strict ===);
     *                       a "key" entry selects by record key (int or list of ints)
     * @return array|false False when the database doesn't exist and autoCreateDB is off, or for invalid filters
     */
    public function find($dbname, $filters=0){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->crud->find($dbname, $filters);
    }


    /**
     * Insert one record or a list of records: each gets the next key
     * @param string $dbname
     * @param array $data One record, or a list of records
     * @return array ['n' => inserted count] or ['n' => 0, 'error' => message]
     */
    public function insert($dbname, $data){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->crud->insert($dbname, $data);
    }


    /**
     * Delete the records matching the filters
     * @param string $dbname
     * @param array $data Filters (same rules as find); [] deletes every record
     * @return array ['n' => deleted count]
     */
    public function delete($dbname, $data){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->crud->delete($dbname, $data);
    }


    /**
     * Set fields of the records matching the filters
     * @param string $dbname
     * @param array $data [filters, ['set' => [field => value, ...]]]
     * @return array ['n' => updated count]
     */
    public function update($dbname, $data){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->crud->update($dbname, $data);
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
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->queryMethods->distinct($dbname, $field);
    }


    /**
     * Sort array by field
     * @param array $array Result from find()
     * @param string $field Field to sort by
     * @param string $order 'asc' or 'desc'
     * @return array|false
     */
    public function sort($array, $field, $order = 'asc'){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->queryMethods->sort($array, $field, $order);
    }


    /**
     * Count records matching filter
     * @param string $dbname
     * @param mixed $filter
     * @return int
     */
    public function count($dbname, $filter = 0){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->queryMethods->count($dbname, $filter);
    }


    /**
     * Pattern matching search (LIKE)
     * @param string $dbname
     * @param string $field
     * @param string $pattern Use ^ for starts with, $ for ends with
     * @return array|false
     */
    public function like($dbname, $field, $pattern){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->queryMethods->like($dbname, $field, $pattern);
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
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->aggregation->sum($dbname, $field, $filter);
    }


    /**
     * Average of numeric field values
     * @param string $dbname
     * @param string $field
     * @param mixed $filter
     * @return float|int
     */
    public function avg($dbname, $field, $filter = 0){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->aggregation->avg($dbname, $field, $filter);
    }


    /**
     * Get minimum value of a field
     * @param string $dbname
     * @param string $field
     * @param mixed $filter
     * @return mixed|null
     */
    public function min($dbname, $field, $filter = 0){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->aggregation->min($dbname, $field, $filter);
    }


    /**
     * Get maximum value of a field
     * @param string $dbname
     * @param string $field
     * @param mixed $filter
     * @return mixed|null
     */
    public function max($dbname, $field, $filter = 0){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->aggregation->max($dbname, $field, $filter);
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
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->utility->first($dbname, $filter);
    }


    /**
     * Get last matching record
     * @param string $dbname
     * @param mixed $filter
     * @return array|null
     */
    public function last($dbname, $filter = 0){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->utility->last($dbname, $filter);
    }


    /**
     * Check if records exist matching filter
     * @param string $dbname
     * @param mixed $filter
     * @return bool
     */
    public function exists($dbname, $filter){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->utility->exists($dbname, $filter);
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
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->utility->between($dbname, $field, $min, $max, $filter);
    }

    // ==========================================
    // SHARDING PUBLIC METHODS
    // ==========================================

    /**
     * Get sharding information for a database
     * @param string $dbname
     * @return array|false
     */
    public function getShardInfo($dbname){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->shardInfo->getShardInfo($dbname);
    }

    // ==========================================
    // FIELD INDEX PUBLIC API
    // ==========================================

    /**
     * Create an index on a field for faster equality lookups (find / where with field => value)
     * @param string $dbname Database name
     * @param string $field Field name (any name except "key")
     * @return array ['success' => bool, 'indexed' => int, 'error' => ?string] (+ 'shards' => int when sharded)
     */
    public function createFieldIndex($dbname, $field){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->fieldIndexApi->createFieldIndex($dbname, $field);
    }


    /**
     * Drop a field index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function dropFieldIndex($dbname, $field){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->fieldIndexApi->dropFieldIndex($dbname, $field);
    }


    /**
     * Check if a field has an index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return bool
     */
    public function hasFieldIndex($dbname, $field){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->fieldIndexApi->hasFieldIndex($dbname, $field);
    }


    /**
     * Get list of field indexes for a database
     * @param string $dbname Database name
     * @return array ['fields' => array, 'sharded' => bool]
     */
    public function getFieldIndexes($dbname){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->fieldIndexApi->getFieldIndexes($dbname);
    }


    /**
     * Rebuild a field index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array Result from createFieldIndex
     */
    public function rebuildFieldIndex($dbname, $field){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->fieldIndexApi->rebuildFieldIndex($dbname, $field);
    }


    /**
     * Get keys matching a field value using field index
     * Returns null if no index exists (caller should fall back to scan)
     * @param string $dbname Database name
     * @param string $field Field name
     * @param mixed $value Value to match
     * @param int|null $shardId Shard ID for sharded databases (null = all shards)
     * @return array|null Array of matching keys, or null if no index
     */
    public function getKeysFromFieldIndex($dbname, $field, $value, $shardId = null){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->fieldIndexApi->getKeysFromFieldIndex($dbname, $field, $value, $shardId);
    }

    // ==========================================
    // SPATIAL INDEX - GEOJSON SUPPORT
    // ==========================================

    /**
     * Validate a GeoJSON geometry object
     * Supports: Point, LineString, Polygon, MultiPoint, MultiLineString, MultiPolygon, GeometryCollection
     *
     * @param array $geometry GeoJSON geometry object
     * @return array ['valid' => bool, 'error' => ?string, 'type' => ?string]
     */
    public function validateGeoJSON($geometry): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->geoJson->validateGeoJSON($geometry);
    }


    /**
     * Calculate Haversine distance between two points (in meters). Not memoized: a key for two points costs
     * more than the formula
     * @param float $lon1 Longitude of point 1
     * @param float $lat1 Latitude of point 1
     * @param float $lon2 Longitude of point 2
     * @param float $lat2 Latitude of point 2
     * @return float Distance in meters
     */
    public function haversineDistance(float $lon1, float $lat1, float $lon2, float $lat2): float{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->geometry->haversineDistance($lon1, $lat1, $lon2, $lat2);
    }


    /**
     * Check if a spatial index exists
     * @param string $dbname Database name
     * @param string $field Field name
     * @param int|null $shardId Shard ID
     * @return bool
     */
    public function hasSpatialIndex(string $dbname, string $field, ?int $shardId = null): bool{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->rtree->hasSpatialIndex($dbname, $field, $shardId);
    }

    // ==========================================
    // SPATIAL INDEX - PUBLIC API
    // ==========================================

    /**
     * Create a spatial index on a GeoJSON field
     * @param string $dbname Database name
     * @param string $field Field name containing GeoJSON
     * @param array $options Options ['nodeSize' => 32]
     * @return array ['success' => bool, 'indexed' => int, 'error' => ?string]
     */
    public function createSpatialIndex(string $dbname, string $field, array $options = []): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->spatialIndexApi->createSpatialIndex($dbname, $field, $options);
    }


    /**
     * Drop a spatial index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array ['success' => bool, 'error' => ?string]
     */
    public function dropSpatialIndex(string $dbname, string $field): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->spatialIndexApi->dropSpatialIndex($dbname, $field);
    }


    /**
     * Get list of spatial indexes for a database
     * @param string $dbname Database name
     * @return array List of indexed field names
     */
    public function getSpatialIndexes(string $dbname): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->spatialIndexApi->getSpatialIndexes($dbname);
    }


    /**
     * Rebuild a spatial index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array Result from createSpatialIndex
     */
    public function rebuildSpatialIndex(string $dbname, string $field): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->spatialIndexApi->rebuildSpatialIndex($dbname, $field);
    }


    /**
     * Find records within a distance from a point (distance to the nearest point of the geometry)
     * @param string $dbname Database name
     * @param string $field GeoJSON field name
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @param float $distanceMeters Distance in meters
     * @param array $options ['includeDistance' => false]
     * @return array Matching records (sorted by distance, with _distance in meters if includeDistance=true)
     */
    public function withinDistance(string $dbname, string $field, float $lon, float $lat, float $distanceMeters, array $options = []): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->spatialQueries->withinDistance($dbname, $field, $lon, $lat, $distanceMeters, $options);
    }


    /**
     * Find records within a bounding box (geometries whose bounding box overlaps it).
     * minLon > maxLon means the box crosses the antimeridian.
     * @return array Matching records
     */
    public function withinBBox(string $dbname, string $field, float $minLon, float $minLat, float $maxLon, float $maxLat): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->spatialQueries->withinBBox($dbname, $field, $minLon, $minLat, $maxLon, $maxLat);
    }


    /**
     * Find K nearest records to a point
     * @param string $dbname Database name
     * @param string $field GeoJSON field name
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @param int $k Number of nearest records
     * @param array $options ['maxDistance' => null (meters), 'includeDistance' => true]
     * @return array Up to k records sorted by distance (fewer when maxDistance cuts them off)
     */
    public function nearest(string $dbname, string $field, float $lon, float $lat, int $k, array $options = []): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->spatialQueries->nearest($dbname, $field, $lon, $lat, $k, $options);
    }


    /**
     * Find records whose geometry lies within a Polygon or MultiPolygon (holes excluded)
     * @param string $dbname Database name
     * @param string $field GeoJSON field name
     * @param array $polygon GeoJSON Polygon or MultiPolygon geometry
     * @return array Matching records
     */
    public function withinPolygon(string $dbname, string $field, array $polygon): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->spatialQueries->withinPolygon($dbname, $field, $polygon);
    }

    // ==========================================
    // WRITE BUFFER PUBLIC API
    // ==========================================

    /**
     * Manually flush buffer for a database
     * @param string $dbname
     * @return array ['success' => bool, 'flushed' => int, 'error' => string|null]
     */
    public function flush($dbname){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->maintenance->flush($dbname);
    }


    /**
     * Flush the write buffers of every database (of this secretKey) in dbDir
     * @return array ['databases' => int, 'flushed' => int]
     */
    public function flushAllBuffers(){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->maintenance->flushAllBuffers();
    }


    /**
     * Get buffer information for a database
     * @param string $dbname
     * @return array
     */
    public function getBufferInfo($dbname){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->maintenance->getBufferInfo($dbname);
    }


    /**
     * Enable or disable write buffering
     * @param bool $enable
     */
    public function enableBuffering($enable = true){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->maintenance->enableBuffering($enable);
    }


    /**
     * Check if buffering is enabled
     * @return bool
     */
    public function isBufferingEnabled(){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->maintenance->isBufferingEnabled();
    }


    /**
     * Set buffer size limit (in bytes)
     * @param int $bytes
     */
    public function setBufferSizeLimit($bytes){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->maintenance->setBufferSizeLimit($bytes);
    }


    /**
     * Set buffer flush interval (in seconds)
     * @param int $seconds 0 to disable time-based flush
     */
    public function setBufferFlushInterval($seconds){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->maintenance->setBufferFlushInterval($seconds);
    }


    /**
     * Set buffer count limit
     * @param int $count
     */
    public function setBufferCountLimit($count){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->maintenance->setBufferCountLimit($count);
    }


    /**
     * Compact a database: drop old record versions and deleted records from the data files.
     * Keys are preserved (sharded and non-sharded alike); indexes stay valid.
     * @param string $dbname
     * @return array ['success' => bool, 'freedSlots' => int, 'totalRecords' => int, 'sharded' => bool]
     */
    public function compact($dbname){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->maintenance->compact($dbname);
    }

    /**
     * Switch a database of the single layout to the sharded layout (STORAGE_FORMAT.md §5.2). A database
     * switches by itself once it has assigned shardSize keys (shardingEnabled)
     * @param string $dbname
     * @return array ["success" => bool, "status" => string]
     */
    public function convertToSharded($dbname){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->maintenance->convertToSharded($dbname);
    }


    /**
     * Check if sharding is enabled
     * @return bool
     */
    public function isShardingEnabled(){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->maintenance->isShardingEnabled();
    }


    /**
     * Get current shard size setting
     * @return int
     */
    public function getShardSize(){
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->maintenance->getShardSize();
    }

    // ==========================================
    // BACKUP AND RESTORE (docs/STORAGE_FORMAT.md §10)
    // ==========================================

    /**
     * Copy a database, as it is at one moment, into $dir: a directory outside dbDir that doesn't exist or is
     * empty (0700, files 0600). Its manifest.json is written last; store.json is the STORE record. Writers of
     * the database wait while it runs, key maintenance fails with MAINTENANCE_BUSY; nothing of the database is
     * written (the buffer is copied, not flushed).
     * @return array ['database', 'dir', 'databaseId', 'generation', 'nextKey', 'records', 'bufferedRecords', 'files', 'bytes']
     * @throws \InvalidArgumentException $dir inside dbDir or not empty
     * @throws \RuntimeException the database does not exist; MAINTENANCE_IN_PROGRESS, KEY_NOT_REGISTERED, LOCK_FAILED
     */
    public function backup(string $dbname, string $dir): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->backupRestore->backup($dbname, $dir);
    }


    /**
     * Make the backup in $dir the content of database $dbname, with one commit: the database opens as it was
     * or as restored after any crash. A backup of another database is refused unless ['force' => true], which
     * loads it under this database's identity. Keys never go back: new records get keys above every key the
     * database handed out. Also the way out of FORMAT_DAMAGED: the damaged generations are kept.
     * @return array ['database', 'generation', 'nextKey', 'records', 'bufferedRecords', 'target' => 'present'|'absent'|'damaged', 'foreign' => bool]
     * @throws \RuntimeException RESTORE_REFUSED (nothing changed), LOCK_FAILED, MAINTENANCE_IN_PROGRESS
     */
    public function restore(string $dir, string $dbname, array $options = []): array{
        return \noneDB\Internal\Core\Kernel::$instances[$this->id]->backupRestore->restore($dir, $dbname, $options);
    }

    // ==========================================
    // KEY MAINTENANCE (docs/STORAGE_FORMAT.md §9)
    // ==========================================
    //
    // Every operation follows the maintenance order: STORE.LOCK (exclusive, non-blocking), then every
    // target database's LOCK (non-blocking; one busy lock: nothing written, MAINTENANCE_BUSY), then the
    // journal .nonedb-maintenance/journal.json, then "m" and a new epoch in every target's state record.
    // The logical commit point is the journal in state COMMITTED with the complete target STORE record;
    // the STORE copies, clearing "m" and closing the journal follow and are repeated by a re-run.

    /**
     * Rotate the secret key of every database in $dbDir that belongs to $oldKey: their directories are
     * renamed to the new key's locators (contents unchanged: FORMAT and metadata hold no key) and STORE
     * registers $newKey instead of $oldKey. Application processes must be stopped. Re-running after an
     * interruption finishes the rotation; ['abort' => true] undoes one that did not commit yet.
     * ['durability' => 'durable'] syncs every step as durable mode does (docs/DURABILITY.md).
     * @return array ['success' => bool, 'databases' => string[], 'storeManifest' => array|null, 'error' => ?string, 'code' => ?int]
     */
    public static function migrateKey(string $dbDir, string $oldKey, string $newKey, array $options = []): array{
        return \noneDB\Internal\Maintenance\KeyMaintenance::migrateKey($dbDir, $oldKey, $newKey, $options);
    }


    /**
     * Register a further key for the store in $dbDir; $registeredKey must already be registered.
     * ['durability' => 'durable'] syncs every step as durable mode does (docs/DURABILITY.md).
     * @return array ['success' => bool, 'storeManifest' => array|null, 'error' => ?string, 'code' => ?int]
     */
    public static function registerKey(string $dbDir, string $registeredKey, string $newKey, array $options = []): array{
        return \noneDB\Internal\Maintenance\KeyMaintenance::registerKey($dbDir, $registeredKey, $newKey, $options);
    }


    /**
     * Rebuild a store record whose two copies are both invalid (STORAGE_FORMAT.md §9.4). Every database
     * directory must belong to one of $keys. ['manifest' => path or array]: a verified STORE record (a
     * backup's store.json, a maintenance result's storeManifest) is restored (complete). Without one, only
     * ['confirmManualRebuild' => true] rebuilds the record from $keys (not complete: keys without
     * databases that were registered before are lost).
     * ['durability' => 'durable'] syncs every step as durable mode does (docs/DURABILITY.md).
     * @return array ['success' => bool, 'mode' => 'manifest'|'manual'|null, 'complete' => bool, 'storeManifest' => array|null,
     *               'error' => ?string (lists databases no key owns), 'code' => ?int]
     */
    public static function repairStore(string $dbDir, array $keys, array $options = []): array{
        return \noneDB\Internal\Maintenance\KeyMaintenance::repairStore($dbDir, $keys, $options);
    }
}

/**
 * Query Builder for noneDB - Fluent Interface
 *
 * Allows method chaining for queries:
 * $db->query("users")->where(["active" => true])->sort("name")->limit(10)->get();
 */
class noneDBQuery {
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

    // ==========================================
    // FILTER HELPERS
    // ==========================================

    /**
     * True for a filter value that is an operator spec: a non-empty array whose keys all start with "$"
     * @throws \InvalidArgumentException when operators and plain keys are mixed
     */
    private function isOperatorSpec($value): bool {
        if (!is_array($value) || empty($value)) {
            return false;
        }
        $operators = 0;
        foreach (array_keys($value) as $key) {
            if (is_string($key) && $key !== '' && $key[0] === '$') {
                $operators++;
            }
        }
        if ($operators > 0 && $operators !== count($value)) {
            throw new \InvalidArgumentException('noneDB: a filter value cannot mix operators ($gt, $in, ...) with plain keys');
        }
        return $operators > 0;
    }

    /**
     * Compile where()/orWhere() groups once per query run: operators are validated and patterns
     * turned into regexes here instead of for every record.
     * @return array[] per group a list of [field, operator or null for a plain value, operand]
     * @throws \InvalidArgumentException for unknown operators or invalid operands
     */
    private function compileGroups(array $groups): array {
        $compiled = [];
        foreach ($groups as $group) {
            $conditions = [];
            foreach ($group as $field => $value) {
                if ($this->isOperatorSpec($value)) {
                    foreach ($value as $operator => $operand) {
                        $conditions[] = [(string)$field, $operator, $this->compileOperand($operator, $operand)];
                    }
                } elseif ((string)$field === 'key') {
                    // As find(): key 5, "5" or a list of them (only one plain where() went through find())
                    $conditions[] = ['key', '#keys', array_flip($this->kernel()->findFilter->normalizeKeyFilter($value))];
                } else {
                    $conditions[] = [(string)$field, null, $value];
                }
            }
            $compiled[] = $conditions;
        }
        return $compiled;
    }

    private function compileOperand(string $operator, $operand) {
        switch ($operator) {
            case '$in':
            case '$nin':
                if (!is_array($operand)) {
                    throw new \InvalidArgumentException("noneDB: {$operator} needs an array");
                }
                return $this->membership($operand);
            case '$like':
                return $this->likeSpec($operand);
            case '$regex':
                return self::regexOperand($operand);
            case '$gt':
            case '$gte':
            case '$lt':
            case '$lte':
            case '$ne':
            case '$eq':
            case '$exists':
            case '$contains':
                return $operand;
            default:
                throw new \InvalidArgumentException("noneDB: unknown operator '{$operator}' (supported: " . implode(', ', self::OPERATORS) . ')');
        }
    }

    /**
     * Check if a record matches one compiled where()/orWhere() group (AND of its conditions).
     * Plain values compare with ===; operator conditions apply the operator.
     */
    private function matchesGroup(array $record, array $conditions): bool {
        foreach ($conditions as [$field, $operator, $operand]) {
            if ($operator === null) {
                if (!array_key_exists($field, $record) || $record[$field] !== $operand) {
                    return false;
                }
            } elseif ($operator === '#keys') {
                if (!isset($record['key']) || !is_int($record['key']) || !isset($operand[$record['key']])) {
                    return false;
                }
            } elseif (!$this->matchesOperator($record, $field, $operator, $operand)) {
                return false;
            }
        }
        return true;
    }

    /**
     * where() groups AND-ed, OR any orWhere() group. Without where(), only orWhere() groups decide.
     * @param array[] $where compiled where() groups
     * @param array[] $orWhere compiled orWhere() groups
     */
    private function matchesWhereLogic(array $record, array $where, array $orWhere): bool {
        if (!empty($where)) {
            $all = true;
            foreach ($where as $conditions) {
                if (!$this->matchesGroup($record, $conditions)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }
        foreach ($orWhere as $conditions) {
            if ($this->matchesGroup($record, $conditions)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compiled like()/$like pattern ("^" start anchor, trailing "$" end anchor, rest literal)
     */
    private function likeSpec($pattern): array {
        if (!is_scalar($pattern)) {
            throw new \InvalidArgumentException('noneDB: like pattern must be a string');
        }
        return \noneDB\Internal\Query\Values::compileLike((string)$pattern);
    }

    /**
     * Regex for a $regex operand: the pattern body, case-insensitive (delimiter added here)
     */
    private static function regexOperand($pattern): string {
        if (!is_string($pattern) || $pattern === '') {
            throw new \InvalidArgumentException('noneDB: $regex needs a non-empty string pattern');
        }
        // Escape the delimiter, but not where "~" is already escaped: skip every "\x" pair first (a "\\"
        // before "~" is an escaped backslash, the "~" still needs escaping)
        $body = preg_replace('/\\\\.(*SKIP)(*FAIL)|~/s', '\\~', $pattern);
        $regex = '~' . $body . '~i' . (preg_match('//u', $pattern) === 1 ? 'u' : '');
        if (@preg_match($regex, '') === false) {
            throw new \InvalidArgumentException("noneDB: invalid \$regex pattern '{$pattern}'");
        }
        return $regex;
    }

    /**
     * Apply a single comparison operator (operand compiled by compileOperand(): $like/$regex are regexes)
     */
    private function matchesOperator(array $record, string $field, string $operator, $operand): bool {
        $hasField = array_key_exists($field, $record);
        $fieldValue = $hasField ? $record[$field] : null;

        switch ($operator) {
            case '$gt':
                $c = $hasField ? \noneDB\Internal\Query\Values::compareValues($fieldValue, $operand) : null;
                return $c !== null && $c > 0;
            case '$gte':
                $c = $hasField ? \noneDB\Internal\Query\Values::compareValues($fieldValue, $operand) : null;
                return $c !== null && $c >= 0;
            case '$lt':
                $c = $hasField ? \noneDB\Internal\Query\Values::compareValues($fieldValue, $operand) : null;
                return $c !== null && $c < 0;
            case '$lte':
                $c = $hasField ? \noneDB\Internal\Query\Values::compareValues($fieldValue, $operand) : null;
                return $c !== null && $c <= 0;
            case '$ne':
                return !$hasField || $fieldValue !== $operand;
            case '$eq':
                return $hasField && $fieldValue === $operand;
            case '$in':
                return $hasField && $this->isMember($fieldValue, $operand);
            case '$nin':
                return !$hasField || !$this->isMember($fieldValue, $operand);
            case '$exists':
                return $operand ? $hasField : !$hasField;
            case '$like':
                if (!$hasField || !is_scalar($fieldValue) || is_bool($fieldValue)) {
                    return false;
                }
                return \noneDB\Internal\Query\Values::likeMatch($operand, (string)$fieldValue);
            case '$regex':
                if (!$hasField || !is_scalar($fieldValue) || is_bool($fieldValue)) {
                    return false;
                }
                return \noneDB\Internal\Query\Values::regexMatch($operand, (string)$fieldValue);
            case '$contains':
                if (!$hasField) {
                    return false;
                }
                if (is_array($fieldValue)) {
                    return in_array($operand, $fieldValue, true);
                }
                if (is_string($fieldValue) && is_scalar($operand) && !is_bool($operand)) {
                    return (string)$operand === '' || strpos($fieldValue, (string)$operand) !== false;
                }
                return false;
            default:
                throw new \InvalidArgumentException("noneDB: unknown operator '{$operator}' (supported: " . implode(', ', self::OPERATORS) . ')');
        }
    }

    /**
     * Check if a record matches all the other filters (whereNot, whereIn, whereNotIn, like, notLike,
     * between, notBetween, search)
     */
    private function matchesAdvancedFilters(array $record): bool {
        foreach ($this->whereNotFilters as $fields) {
            foreach ($fields as $conditions) {
                if ($this->matchesGroup($record, $conditions)) {
                    return false;
                }
            }
        }

        foreach ($this->whereInFilters as $filter) {
            if (!array_key_exists($filter['field'], $record)) return false;
            if (!$this->isMember($record[$filter['field']], $filter['values'])) return false;
        }

        foreach ($this->whereNotInFilters as $filter) {
            if (array_key_exists($filter['field'], $record) && $this->isMember($record[$filter['field']], $filter['values'])) {
                return false;
            }
        }

        foreach ($this->likeFilters as $like) {
            $value = $record[$like['field']] ?? null;
            if (!is_scalar($value) || is_bool($value)) return false;
            if (!\noneDB\Internal\Query\Values::likeMatch($like['like'], (string)$value)) return false;
        }

        foreach ($this->notLikeFilters as $notLike) {
            $value = $record[$notLike['field']] ?? null;
            if (is_scalar($value) && !is_bool($value) && \noneDB\Internal\Query\Values::likeMatch($notLike['like'], (string)$value)) {
                return false;
            }
        }

        foreach ($this->betweenFilters as $between) {
            if (!array_key_exists($between['field'], $record)) return false;
            $value = $record[$between['field']];
            $lo = \noneDB\Internal\Query\Values::compareValues($value, $between['min']);
            $hi = \noneDB\Internal\Query\Values::compareValues($value, $between['max']);
            if ($lo === null || $hi === null || $lo < 0 || $hi > 0) return false;
        }

        foreach ($this->notBetweenFilters as $notBetween) {
            if (!array_key_exists($notBetween['field'], $record)) continue;
            $value = $record[$notBetween['field']];
            $lo = \noneDB\Internal\Query\Values::compareValues($value, $notBetween['min']);
            $hi = \noneDB\Internal\Query\Values::compareValues($value, $notBetween['max']);
            if ($lo !== null && $hi !== null && $lo >= 0 && $hi <= 0) return false;
        }

        foreach ($this->searchFilters as $search) {
            $term = (string)$search['term'];
            if ($term === '') continue;
            $searchFields = empty($search['fields']) ? array_keys($record) : $search['fields'];
            $found = false;
            foreach ($searchFields as $field) {
                if (empty($search['fields']) && ($field === 'key' || $field === '_distance')) continue; // not content
                $value = $record[$field] ?? null;
                if (!is_scalar($value) || is_bool($value)) continue;
                if (self::containsIgnoreCase((string)$value, $term)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) return false;
        }

        return true;
    }

    /**
     * Case-insensitive substring test with Unicode case folding when mbstring is available
     */
    private static function containsIgnoreCase(string $haystack, string $needle): bool {
        if (\noneDB\Internal\Query\Values::hasMbstring() && preg_match('//u', $haystack) === 1 && preg_match('//u', $needle) === 1) {
            return strpos(\noneDB\Internal\Query\Values::foldCase($haystack), \noneDB\Internal\Query\Values::foldCase($needle)) !== false;
        }
        return stripos($haystack, $needle) !== false;
    }

    private function hasAdvancedFilters(): bool {
        return !empty($this->whereNotFilters) || !empty($this->whereInFilters) || !empty($this->whereNotInFilters)
            || !empty($this->likeFilters) || !empty($this->notLikeFilters) || !empty($this->betweenFilters)
            || !empty($this->notBetweenFilters) || !empty($this->searchFilters);
    }

    // ==========================================
    // CHAINABLE METHODS (return $this)
    // ==========================================

    /**
     * Add where filters. Each call adds a group; all groups must match (AND).
     * Values are compared with === or, for arrays like ['$gt' => 10], with operators.
     * @param array $filters
     * @return self
     */
    public function where(array $filters): self {
        if (!empty($filters)) {
            foreach ($filters as $value) {
                $this->isOperatorSpec($value); // validates early
            }
            $this->whereFilters[] = $filters;
        }
        return $this;
    }

    /**
     * Add pattern matching filter
     * @param string $field
     * @param string $pattern "^" anchors the start, a trailing "$" the end; everything else is literal
     * @return self
     */
    public function like(string $field, string $pattern): self {
        $this->likeFilters[] = ['field' => $field, 'pattern' => $pattern, 'like' => $this->likeSpec($pattern)];
        return $this;
    }

    /**
     * Add range filter (min <= value <= max)
     * @param string $field
     * @param mixed $min
     * @param mixed $max
     * @return self
     */
    public function between(string $field, $min, $max): self {
        $this->betweenFilters[] = ['field' => $field, 'min' => $min, 'max' => $max];
        return $this;
    }

    /**
     * Add a sort key. The first call is the primary key; later calls break ties.
     * @param string $field
     * @param string $order 'asc' or 'desc'
     * @return self
     */
    public function sort(string $field, string $order = 'asc'): self {
        $this->sortKeys[] = ['field' => $field, 'order' => strtolower($order) === 'desc' ? 'desc' : 'asc'];
        return $this;
    }

    /**
     * Limit results
     * @param int $count
     * @return self
     */
    public function limit(int $count): self {
        $this->limitCount = max(0, $count);
        return $this;
    }

    /**
     * Skip first N results (for pagination)
     * @param int $count
     * @return self
     */
    public function offset(int $count): self {
        $this->offsetCount = max(0, $count);
        return $this;
    }

    /**
     * Alias for offset() - skip first N results
     * @param int $count
     * @return self
     */
    public function skip(int $count): self {
        return $this->offset($count);
    }

    /**
     * Alias for sort()
     * @param string $field
     * @param string $order 'asc' or 'desc'
     * @return self
     */
    public function orderBy(string $field, string $order = 'asc'): self {
        return $this->sort($field, $order);
    }

    /**
     * Add an OR group: a record matches if all where() groups match OR any orWhere() group does
     * @param array $filters
     * @return self
     */
    public function orWhere(array $filters): self {
        foreach ($filters as $value) {
            $this->isOperatorSpec($value);
        }
        $this->orWhereFilters[] = $filters;
        return $this;
    }

    /**
     * Filter where field value is in given array (strict comparison)
     * @param string $field
     * @param array $values
     * @return self
     */
    public function whereIn(string $field, array $values): self {
        $this->whereInFilters[] = ['field' => $field, 'values' => $this->membership(array_values($values)), 'raw' => array_values($values)];
        return $this;
    }

    /**
     * Value list for strict membership tests: longer lists become a hash set of exact identities
     * (noneDB::valueKey: same result as in_array(..., true), without scanning the list per record)
     * @return array|\stdClass
     */
    private function membership(array $values) {
        if (count($values) <= 8) {
            return $values;
        }
        $valueKey = $this->kernel()->values->valueKeyFunction();
        $set = [];
        foreach ($values as $value) {
            $set[$valueKey($value)] = true;
        }
        return (object)['set' => $set, 'key' => $valueKey];
    }

    private function isMember($value, $membership): bool {
        if ($membership instanceof \stdClass) {
            return isset($membership->set[($membership->key)($value)]);
        }
        return in_array($value, $membership, true);
    }

    /**
     * Filter where field value is NOT in given array (strict comparison)
     * @param string $field
     * @param array $values
     * @return self
     */
    public function whereNotIn(string $field, array $values): self {
        $this->whereNotInFilters[] = ['field' => $field, 'values' => $this->membership(array_values($values))];
        return $this;
    }

    /**
     * Exclude records where a field equals the value (strict comparison)
     * @param array $filters
     * @return self
     */
    public function whereNot(array $filters): self {
        if (!empty($filters)) {
            // Per field: the field matches when all its conditions match (AND, like in where());
            // a record is excluded when any field matches. Operators are checked now, like where().
            $fields = [];
            foreach ($filters as $field => $value) {
                $fields[] = $this->compileGroups([[$field => $value]])[0];
            }
            $this->whereNotFilters[] = $fields;
        }
        return $this;
    }

    /**
     * Filter where field does NOT match pattern
     * @param string $field
     * @param string $pattern
     * @return self
     */
    public function notLike(string $field, string $pattern): self {
        $this->notLikeFilters[] = ['field' => $field, 'pattern' => $pattern, 'like' => $this->likeSpec($pattern)];
        return $this;
    }

    /**
     * Filter where field value is NOT between min and max
     * @param string $field
     * @param mixed $min
     * @param mixed $max
     * @return self
     */
    public function notBetween(string $field, $min, $max): self {
        $this->notBetweenFilters[] = ['field' => $field, 'min' => $min, 'max' => $max];
        return $this;
    }

    /**
     * Select only specific fields in results ("key" is always included)
     * @param array $fields
     * @return self
     */
    public function select(array $fields): self {
        $this->selectFields = array_values($fields);
        return $this;
    }

    /**
     * Exclude specific fields from results
     * @param array $fields
     * @return self
     */
    public function except(array $fields): self {
        $this->exceptFields = array_values($fields);
        return $this;
    }

    /**
     * Group results by field. get() then returns groups: ['_group' => value, '_items' => [...], '_count' => n]
     * @param string $field
     * @return self
     */
    public function groupBy(string $field): self {
        $this->groupByField = $field;
        return $this;
    }

    /**
     * Filter groups by aggregate condition. Only used together with groupBy(); without it, and for
     * unknown aggregates or operators, the condition is ignored (passes)
     * @param string $aggregate 'count', 'sum:field', 'avg:field', 'min:field', 'max:field'
     * @param string $operator '>', '<', '>=', '<=', '=', '!='
     * @param mixed $value
     * @return self
     */
    public function having(string $aggregate, string $operator, $value): self {
        $this->havingFilters[] = ['aggregate' => $aggregate, 'operator' => $operator, 'value' => $value];
        return $this;
    }

    /**
     * Case-insensitive full-text search across fields
     * @param string $term Search term
     * @param array $fields Fields to search in (empty = all fields except "key")
     * @return self
     */
    public function search(string $term, array $fields = []): self {
        $this->searchFilters[] = ['term' => $term, 'fields' => array_values($fields)];
        return $this;
    }

    /**
     * Join with another database
     * @param string $foreignDb Foreign database name
     * @param string $localKey Local key field
     * @param string $foreignKey Foreign key field
     * @param string|null $alias Field that receives the joined record (default: the foreign database name;
     *                           it replaces a field of the same name in the result, never in storage)
     * @return self
     */
    public function join(string $foreignDb, string $localKey, string $foreignKey, ?string $alias = null): self {
        $this->joinConfigs[] = [
            'foreignDb' => $foreignDb,
            'localKey' => $localKey,
            'foreignKey' => $foreignKey,
            'alias' => $alias ?? $foreignDb
        ];
        return $this;
    }

    // ==========================================
    // SPATIAL QUERY METHODS
    // ==========================================

    /**
     * Filter records within a distance from a point
     * @param string $field GeoJSON field name
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @param float $distanceMeters Distance in meters
     * @return self
     */
    public function withinDistance(string $field, float $lon, float $lat, float $distanceMeters): self {
        $this->spatialFilters[] = ['type' => 'withinDistance', 'field' => $field, 'lon' => $lon, 'lat' => $lat, 'distance' => $distanceMeters];
        return $this;
    }

    /**
     * Filter records within a bounding box
     * @param string $field GeoJSON field name
     * @param float $minLon Minimum longitude
     * @param float $minLat Minimum latitude
     * @param float $maxLon Maximum longitude
     * @param float $maxLat Maximum latitude
     * @return self
     */
    public function withinBBox(string $field, float $minLon, float $minLat, float $maxLon, float $maxLat): self {
        $this->spatialFilters[] = ['type' => 'withinBBox', 'field' => $field, 'minLon' => $minLon, 'minLat' => $minLat, 'maxLon' => $maxLon, 'maxLat' => $maxLat];
        return $this;
    }

    /**
     * The K records nearest to a point among those matching the other filters (adds _distance in meters)
     * @param string $field GeoJSON field name
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @param int $k Number of nearest records
     * @return self
     */
    public function nearest(string $field, float $lon, float $lat, int $k): self {
        $this->spatialFilters[] = ['type' => 'nearest', 'field' => $field, 'lon' => $lon, 'lat' => $lat, 'k' => max(0, $k)];
        return $this;
    }

    /**
     * Filter records within a polygon
     * @param string $field GeoJSON field name
     * @param array $polygon GeoJSON Polygon geometry
     * @return self
     */
    public function withinPolygon(string $field, array $polygon): self {
        $this->spatialFilters[] = ['type' => 'withinPolygon', 'field' => $field, 'polygon' => $polygon];
        return $this;
    }

    /**
     * Add _distance to every result: meters from the point to the geometry, measured like
     * withinDistance()/nearest() (0 inside a polygon, otherwise to the nearest point of the geometry).
     * It is computed before sorting, so sort('_distance') works.
     * @param string $field GeoJSON field name
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @return self
     */
    public function withDistance(string $field, float $lon, float $lat): self {
        $this->distanceConfig = ['field' => $field, 'lon' => $lon, 'lat' => $lat];
        return $this;
    }

    // ==========================================
    // EXECUTION
    // ==========================================

    /**
     * Records the spatial filters allow ([key => record]); nearest() returns its candidates
     * in distance order with _distance set. Null when there are no spatial filters.
     * @param float|null $nearestRadius Only the records within this many meters for a nearest() filter
     *        (null: all records in distance order)
     * @param float|null $nearestBoundary Set when the radius was used: records with a smaller _distance
     *        are exactly those of the full list (anything outside the radius is at least this far)
     */
    private function spatialCandidates(?float $nearestRadius = null, ?float &$nearestBoundary = null): ?array {
        $nearestBoundary = null;
        if (empty($this->spatialFilters)) {
            return null;
        }
        $result = null;
        foreach ($this->spatialFilters as $filter) {
            switch ($filter['type']) {
                case 'withinDistance':
                    $rows = $this->db->withinDistance($this->dbname, $filter['field'], $filter['lon'], $filter['lat'], $filter['distance'], ['includeDistance' => true]);
                    break;
                case 'withinBBox':
                    $rows = $this->db->withinBBox($this->dbname, $filter['field'], $filter['minLon'], $filter['minLat'], $filter['maxLon'], $filter['maxLat']);
                    break;
                case 'nearest':
                    // Records in distance order; the k cut happens after the other filters
                    if ($filter['k'] <= 0) {
                        $rows = [];
                    } elseif ($nearestRadius !== null) {
                        $rows = $this->db->withinDistance($this->dbname, $filter['field'], $filter['lon'], $filter['lat'], $nearestRadius, ['includeDistance' => true]);
                        $nearestBoundary = round($nearestRadius, 2);
                    } else {
                        $all = max(1, $this->db->count($this->dbname));
                        $rows = $this->db->nearest($this->dbname, $filter['field'], $filter['lon'], $filter['lat'], $all, ['includeDistance' => true]);
                    }
                    break;
                case 'withinPolygon':
                    $rows = $this->db->withinPolygon($this->dbname, $filter['field'], $filter['polygon']);
                    break;
                default:
                    $rows = [];
            }
            $keyed = [];
            foreach ($rows as $row) {
                if (isset($row['key'])) {
                    $keyed[$row['key']] = $row;
                }
            }
            if ($result === null) {
                $result = $keyed;
            } elseif ($filter['type'] === 'nearest') {
                // nearest() orders and cuts by its own distance: its order and _distance win
                $result = array_intersect_key($keyed, $result);
            } else {
                // Intersect by key, keep the order and fields of the earlier result
                $result = array_intersect_key($result, $keyed);
                foreach ($result as $key => $row) {
                    if (isset($keyed[$key]['_distance']) && !isset($row['_distance'])) {
                        $result[$key]['_distance'] = $keyed[$key]['_distance'];
                    }
                }
            }
        }
        return $result ?? [];
    }

    /**
     * The plan of the query's filters (see noneDB's Planner): what field indexes, global field indexes and
     * key lookups can narrow down. Every record read is still checked against every filter.
     * @return array
     */
    private function buildPlan(): array {
        $where = [];
        foreach ($this->whereFilters as $group) {
            $where[] = $this->groupPlan($group);
        }
        $node = count($where) === 1 ? $where[0] : (empty($where) ? null : ['and', $where]);
        if (!empty($this->orWhereFilters)) {
            // where groups (all) OR any orWhere group; an empty orWhere() group matches everything
            $branches = $node === null ? [] : [$node];
            foreach ($this->orWhereFilters as $group) {
                $branches[] = $this->groupPlan($group);
            }
            $node = ['or', $branches];
        }
        $and = $node === null ? [] : [$node];
        foreach ($this->whereInFilters as $filter) {
            $and[] = $this->inPlan($filter['field'], $filter['raw'] ?? []);
        }
        foreach ($this->betweenFilters as $between) {
            $and[] = ['range', $between['field'], '$gte', $between['min']];
            $and[] = ['range', $between['field'], '$lte', $between['max']];
        }
        return self::simplifyPlan(['and', $and]);
    }

    /**
     * Plan of one where()/orWhere() group (all its conditions)
     */
    private function groupPlan(array $group): array {
        $and = [];
        foreach ($group as $field => $value) {
            $field = (string)$field;
            if (!$this->isOperatorSpec($value)) {
                if ($field === 'key') {
                    $and[] = ['keys', $this->kernel()->findFilter->normalizeKeyFilter($value)];
                } elseif (!is_array($value)) {
                    $and[] = ['eq', $field, $value];
                }
                continue;
            }
            foreach ($value as $operator => $operand) {
                if ($operator === '$eq' && !is_array($operand)) {
                    $and[] = $field === 'key' ? ['keys', $this->kernel()->findFilter->normalizeKeyFilter($operand)] : ['eq', $field, $operand];
                } elseif ($operator === '$in' && is_array($operand)) {
                    $and[] = $field === 'key' ? ['keys', $this->kernel()->findFilter->normalizeKeyFilter(array_values($operand))] : $this->inPlan($field, $operand);
                } elseif (in_array($operator, ['$gt', '$gte', '$lt', '$lte'], true) && $field !== 'key' && !is_array($operand)) {
                    $and[] = ['range', $field, $operator, $operand];
                }
                // $ne, $nin, $exists, $like, $regex, $contains: checked on the records only
            }
        }
        return self::simplifyPlan(['and', $and]);
    }

    private function inPlan(string $field, array $values): array {
        foreach ($values as $value) {
            if (is_array($value) || is_object($value)) {
                return ['any']; // indexes hold no arrays
            }
        }
        return ['in', $field, array_values($values)];
    }

    private static function simplifyPlan(array $node): array {
        if ($node[0] !== 'and' && $node[0] !== 'or') {
            return $node;
        }
        $children = [];
        foreach ($node[1] as $child) {
            $child = self::simplifyPlan($child);
            if ($child[0] === 'any') {
                if ($node[0] === 'or') {
                    return ['any']; // a branch that matches anything
                }
                continue; // AND with "anything" is the rest
            }
            $children[] = $child;
        }
        if (empty($children)) {
            return ['any'];
        }
        return count($children) === 1 ? $children[0] : [$node[0], $children];
    }

    /**
     * True when the filters are one plain where() group with a key filter: find() keeps the order of
     * the keys asked for, which a scan in key order wouldn't
     */
    private function keyOrderLookup(): bool {
        if (count($this->whereFilters) !== 1 || !empty($this->orWhereFilters) || !array_key_exists('key', $this->whereFilters[0])) {
            return false;
        }
        foreach ($this->whereFilters[0] as $value) {
            if ($this->isOperatorSpec($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Filtered records (no join, grouping or projection)
     * @param bool $earlyExit Stop once offset+limit records are collected (only when order can't change)
     */
    private function filteredRecords(bool $earlyExit, ?int $target = null, ?int $topK = null): array {
        // One shared lock for every read the query makes (count, spatial rounds, records), so they
        // all see the same state of the database
        $dbname = $this->kernel()->layout->normalizeDbName($this->dbname);
        return $this->kernel()->locks->withReadLock($dbname, function () use ($earlyExit, $target, $topK) {
            return $this->filteredRecordsLocked($earlyExit, $target, $topK);
        });
    }

    /**
     * @param int|null $target Matches enough to stop early (exists()); default: offset + limit without sort
     */
    private function filteredRecordsLocked(bool $earlyExit, ?int $target = null, ?int $topK = null): array {
        $nearest = null;
        $nearestFilters = 0;
        foreach ($this->spatialFilters as $filter) {
            if ($filter['type'] === 'nearest') {
                $nearest = $nearest === null ? $filter['k'] : min($nearest, $filter['k']);
                $nearestFilters++;
            }
        }
        // A single nearest() as the first spatial filter (its distances are the ones kept) on an indexed
        // field doesn't need every record in distance order: search a growing radius until k records match
        $nearestRadius = null;
        $total = 0;
        if ($nearestFilters === 1 && $this->spatialFilters[0]['type'] === 'nearest' && $nearest > 0
            && $this->kernel()->spatialQueries->usesSpatialIndex($this->dbname, $this->spatialFilters[0]['field'])) {
            $nearestRadius = 1000.0;
            $total = $this->db->count($this->dbname);
        }

        [$single, $where, $orWhere, $needWhere, $needAdvanced] = $this->compiledFilters();
        if ($target !== null && $earlyExit && $nearest === null) {
            // exists(): independent of sort
        } else {
            $target = ($earlyExit && $nearest === null && empty($this->sortKeys) && $this->limitCount !== null)
                ? $this->offsetCount + $this->limitCount
                : PHP_INT_MAX;
        }

        while (true) {
            $boundary = null;
            $spatial = $this->spatialCandidates($nearestRadius, $boundary);
            if ($spatial !== null) {
                $records = array_values($spatial);
                $whereApplied = false;
            } elseif ($this->keyOrderLookup()) {
                $records = $this->db->find($this->dbname, $this->whereFilters[0]);
                $records = $records === false ? [] : $records;
                $whereApplied = true;
            } else {
                // Indexes narrow the segments and keys down; the records are read in batches and the scan
                // stops once enough match. A sort with a small limit keeps only the best ones (top-k).
                return $this->scanFiltered($target, $topK);
            }

            $filtered = [];
            foreach ($records as $record) {
                if (!$whereApplied) {
                    if ($single !== null) {
                        if (!$this->matchesGroup($record, $single)) continue;
                    } elseif ($needWhere && !$this->matchesWhereLogic($record, $where, $orWhere)) continue;
                }
                if ($needAdvanced && !$this->matchesAdvancedFilters($record)) continue;
                $filtered[] = $record;
                if (count($filtered) >= $target) break;
            }

            if ($boundary !== null) {
                // Candidates closer than the radius are exactly the full list's (at the radius itself
                // rounding may leave some out). Enough matches among them: done.
                $certain = [];
                foreach ($filtered as $record) {
                    if (($record['_distance'] ?? INF) < $boundary) {
                        $certain[] = $record;
                    }
                }
                if (count($certain) >= $nearest) {
                    $filtered = $certain;
                    break;
                }
                if (count($records) >= $total) {
                    break; // every record lies within the radius: the list is complete
                }
                // Matches grow with the area (r²): aim for k from the matches so far, at least doubling
                $factor = count($certain) > 0 ? min(16.0, max(2.0, sqrt($nearest / count($certain)) * 1.25)) : 4.0;
                // Half the earth's circumference covers everything: then take the complete list
                $nearestRadius = $nearestRadius * $factor >= 20040000.0 ? null : $nearestRadius * $factor;
                continue;
            }
            break;
        }

        if ($nearest !== null) {
            // Stable on every PHP version: equal distances keep the candidate order
            $positions = array_keys($filtered);
            $distances = array_map(function ($r) { return $r['_distance'] ?? INF; }, $filtered);
            array_multisort($distances, SORT_ASC, SORT_NUMERIC, $positions, SORT_ASC, SORT_NUMERIC);
            $sorted = [];
            foreach (array_slice($positions, 0, $nearest) as $position) {
                $sorted[] = $filtered[$position];
            }
            $filtered = $sorted;
        }
        return $filtered;
    }

    /**
     * The filters compiled once per query: [the one where() group when it is the only one (no AND/OR logic
     * per record) or null, where() groups, orWhere() groups, any where/orWhere, any other filter]
     */
    private function compiledFilters(): array {
        $needWhere = !empty($this->whereFilters) || !empty($this->orWhereFilters);
        $where = $needWhere ? $this->compileGroups($this->whereFilters) : [];
        $orWhere = $needWhere ? $this->compileGroups($this->orWhereFilters) : [];
        $single = ($needWhere && count($where) === 1 && empty($orWhere)) ? $where[0] : null;
        return [$single, $where, $orWhere, $needWhere, $this->hasAdvancedFilters()];
    }

    /**
     * Visit the records matching the filters in key order through the plan's scan, none kept: $visit($key,
     * $record) returns false to stop; the first $offset matches are skipped and at most $limit visited. One
     * segment's records are held at a time. Not for spatial filters and key lookups (filteredRecordsLocked()).
     * Caller holds the lock.
     * @param bool $whole The visitor never stops: each segment is read in one go
     * @return array The scan's statistics (explain())
     */
    private function eachMatch(callable $visit, bool $whole, int $offset = 0, ?int $limit = null): array {
        [$single, $where, $orWhere, $needWhere, $needAdvanced] = $this->compiledFilters();
        $left = $limit ?? PHP_INT_MAX;
        if ($left <= 0) {
            return [];
        }
        $dbname = $this->kernel()->layout->normalizeDbName($this->dbname);
        return $this->kernel()->planner->scanRecords($dbname, $this->buildPlan(),
            function (array $records) use ($visit, $single, $where, $orWhere, $needWhere, $needAdvanced, &$offset, &$left) {
                foreach ($records as $key => $record) {
                    if ($single !== null) {
                        if (!$this->matchesGroup($record, $single)) continue;
                    } elseif ($needWhere && !$this->matchesWhereLogic($record, $where, $orWhere)) {
                        continue;
                    }
                    if ($needAdvanced && !$this->matchesAdvancedFilters($record)) continue;
                    if ($offset > 0) {
                        $offset--;
                        continue;
                    }
                    if ($visit($key, $record) === false || --$left === 0) {
                        return false;
                    }
                }
                return true;
            }, $whole);
    }

    /**
     * filteredRecordsLocked() without spatial filters: the plan's candidates, every filter checked
     * @param int $target Stop after this many matches
     * @param int|null $topK Keep only the best $topK by the sort keys (records arrive in key order)
     */
    private function scanFiltered(int $target, ?int $topK): array {
        $filtered = [];
        $prune = $topK === null ? PHP_INT_MAX : max(4 * $topK, 1024);
        $this->lastScan = $this->eachMatch(function ($key, array $record) use (&$filtered, $target, $topK, $prune) {
            $filtered[] = $record;
            if ($topK !== null) {
                if (count($filtered) > $prune) {
                    $filtered = array_slice($this->applySort($filtered), 0, $topK);
                }
            } elseif (count($filtered) >= $target) {
                return false;
            }
            return true;
        }, $target === PHP_INT_MAX);
        return $filtered;
    }

    /**
     * True when the results are the matches in key order (no groupBy(), join, sort, spatial filter,
     * withDistance() or key lookup): aggregates and last() then read them through eachMatch(), one segment
     * held at a time, instead of collecting them
     */
    private function streamsResults(): bool {
        return $this->groupByField === null && empty($this->joinConfigs) && empty($this->sortKeys) && empty($this->spatialFilters)
            && $this->distanceConfig === null && !$this->keyOrderLookup();
    }

    /**
     * Every record of the results in order, without projection (the aggregates): $visit($record)
     */
    private function eachResult(callable $visit): void {
        if (!$this->streamsResults()) {
            foreach ($this->results(false) as $record) {
                $visit($record);
            }
            return;
        }
        $dbname = $this->kernel()->layout->normalizeDbName($this->dbname);
        $this->kernel()->locks->withReadLock($dbname, function () use ($visit) {
            $this->eachMatch(function ($key, array $record) use ($visit) {
                $visit($record);
            }, $this->limitCount === null, $this->offsetCount, $this->limitCount);
        });
    }

    /**
     * Add _distance for withDistance()
     */
    private function applyDistanceCalculation(array $records): array {
        if ($this->distanceConfig === null) {
            return $records;
        }
        $field = $this->distanceConfig['field'];
        foreach ($records as &$record) {
            $geometry = $record[$field] ?? null;
            if (!is_array($geometry) || !$this->db->validateGeoJSON($geometry)['valid']) {
                continue;
            }
            $distance = $this->kernel()->spatialQueries->distanceToGeometry((float)$this->distanceConfig['lon'], (float)$this->distanceConfig['lat'], $geometry);
            if (is_finite($distance)) { // a geometry without any position has no distance
                $record['_distance'] = round($distance, 4);
            }
        }
        unset($record);
        return $records;
    }

    private function applyJoins(array $records): array {
        foreach ($this->joinConfigs as $join) {
            if ($join['foreignKey'] === 'key') {
                // Joining on record keys: read only the keys asked for
                $keys = [];
                foreach ($records as $record) {
                    $lk = self::joinKey($record[$join['localKey']] ?? null);
                    if (is_int($lk) && $lk >= 0) {
                        $keys[$lk] = $lk;
                    }
                }
                $foreignData = empty($keys) ? [] : $this->db->find($join['foreignDb'], ['key' => array_values($keys)]);
            } else {
                // Only the foreign records whose value can join: the local raw values (a whole number also as
                // its float and canonical string, which join it too), through the foreign field index when
                // there is one. The join identity (joinKey) is only compared, never used as a filter value.
                $values = [];
                foreach ($records as $record) {
                    $value = $record[$join['localKey']] ?? null;
                    $lk = self::joinKey($value);
                    if ($lk === null) {
                        continue;
                    }
                    if (is_int($lk)) {
                        $values['i' . $lk] = $lk;
                        $values['d' . $lk] = (float)$lk;
                        $values['s' . $lk] = (string)$lk;
                    } else {
                        $values[$lk] = $value;
                    }
                }
                $foreignData = empty($values) ? [] : $this->db->query($join['foreignDb'])->whereIn($join['foreignKey'], array_values($values))->get();
            }
            if ($foreignData === false) {
                $foreignData = [];
            }
            $foreignIndexed = [];
            foreach ($foreignData as $fRecord) {
                $fk = self::joinKey($fRecord[$join['foreignKey']] ?? null);
                if ($fk !== null) {
                    $foreignIndexed[$fk] = $fRecord; // the last record with a key wins
                }
            }
            foreach ($records as &$record) {
                $lk = self::joinKey($record[$join['localKey']] ?? null);
                $record[$join['alias']] = $lk !== null ? ($foreignIndexed[$lk] ?? null) : null;
            }
            unset($record);
        }
        return $records;
    }

    /**
     * Join identity of a value (only to compare, never to query): whole numbers in the int64 range,
     * 5.0 and the canonical string "5" are the same as 5 (record keys join with user_id values);
     * other floats by their bits (a float used as an array key was truncated: 1.5 joined 1); other
     * strings as themselves ("007" is not 7, "1.5" is not 1.5); booleans only booleans (true joined 1
     * and "1"); null and arrays never join.
     * @return int|string|null
     */
    private static function joinKey($v) {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            if (is_finite($v) && floor($v) === $v && $v >= -9.2233720368547758E18 && $v < 9.2233720368547758E18) {
                return (int)$v;
            }
            return 'd' . bin2hex(pack('E', $v + 0.0));
        }
        if (is_string($v)) {
            if (preg_match('/\A(0|-?[1-9][0-9]*)\z/', $v) === 1 && (string)(int)$v === $v) {
                return (int)$v;
            }
            return 's' . $v;
        }
        if (is_bool($v)) {
            return $v ? 'b1' : 'b0';
        }
        return null;
    }

    private function applyGroupBy(array $records): array {
        $groups = [];
        // Exact identity (as distinct()): json_encode() followed serialize_precision and split -0.0 from 0.0
        $valueKey = $this->kernel()->values->valueKeyFunction();
        foreach ($records as $record) {
            $value = $record[$this->groupByField] ?? null;
            $groupKey = $valueKey(is_float($value) ? $value + 0.0 : $value);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = ['_group' => $value, '_items' => [], '_count' => 0];
            }
            $groups[$groupKey]['_items'][] = $record;
            $groups[$groupKey]['_count']++;
        }

        foreach ($this->havingFilters as $having) {
            if (!in_array($having['operator'], ['>', '<', '>=', '<=', '=', '!='], true)) {
                continue; // unknown operator: the condition is ignored (for every group)
            }
            $groups = array_filter($groups, function ($group) use ($having) {
                if ($having['aggregate'] === 'count') {
                    $aggValue = $group['_count'];
                } elseif (!preg_match('/^(sum|avg|min|max):.+$/', $having['aggregate'])) {
                    return true; // unknown aggregate: ignored
                } else {
                    [$aggType, $aggField] = explode(':', $having['aggregate'], 2);
                    $values = [];
                    foreach ($group['_items'] as $item) {
                        $number = \noneDB\Internal\Query\Values::numberOf($item[$aggField] ?? null);
                        if ($number !== null) {
                            $values[] = $number;
                        }
                    }
                    switch ($aggType) {
                        case 'sum': $aggValue = array_sum($values); break;
                        case 'avg': $aggValue = count($values) > 0 ? array_sum($values) / count($values) : 0; break;
                        case 'min': $aggValue = count($values) > 0 ? min($values) : null; break;
                        default:    $aggValue = count($values) > 0 ? max($values) : null; break;
                    }
                }
                if ($aggValue === null) {
                    return false;
                }
                switch ($having['operator']) {
                    case '>': return $aggValue > $having['value'];
                    case '<': return $aggValue < $having['value'];
                    case '>=': return $aggValue >= $having['value'];
                    case '<=': return $aggValue <= $having['value'];
                    case '=': return $aggValue == $having['value'];
                    case '!=': return $aggValue != $having['value'];
                    default: return true; // unknown operator: ignored
                }
            });
        }
        return array_values($groups);
    }

    private function applySort(array $records): array {
        if (empty($this->sortKeys) || count($records) < 2) {
            return $records;
        }
        // Every sort field becomes two columns, kind and value (noneDB::sortKey), sorted by
        // array_multisort with the same comparison as <=>. The original position is the last column,
        // so records that compare equal keep their order (a stable sort on every PHP version).
        $records = array_values($records);
        $sortKey = $this->kernel()->values->sortKeyFunction();
        $columns = [];
        foreach ($this->sortKeys as $sk) {
            $kinds = [];
            $values = [];
            foreach ($records as $record) {
                [$kinds[], $values[]] = $sortKey(is_array($record) && array_key_exists($sk['field'], $record) ? $record[$sk['field']] : null);
            }
            $order = $sk['order'] === 'desc' ? SORT_DESC : SORT_ASC;
            array_push($columns, $kinds, $order, SORT_REGULAR, $values, $order, SORT_REGULAR);
        }
        $columns[] = array_keys($records);
        array_multisort(...$columns);
        $sorted = [];
        foreach ($columns[count($columns) - 1] as $position) {
            $sorted[] = $records[$position];
        }
        return $sorted;
    }

    private function applyProjection(array $records): array {
        if (empty($this->selectFields) && empty($this->exceptFields)) {
            return $records;
        }
        if ($this->groupByField !== null) {
            // Group rows: _group, _count and _items in select()/except() apply to the group rows; other
            // fields apply to the items of each group (select(['name']) must not empty a group)
            $groupFields = ['_group', '_count', '_items'];
            $select = $this->selectFields;
            $except = $this->exceptFields;
            $this->selectFields = array_values(array_diff($select, $groupFields));
            $this->exceptFields = array_values(array_diff($except, $groupFields));
            $groupBy = $this->groupByField;
            $this->groupByField = null;
            try {
                foreach ($records as &$group) {
                    if (!empty($this->selectFields) || !empty($this->exceptFields)) {
                        $group['_items'] = $this->applyProjection($group['_items']);
                    }
                    $keep = array_values(array_intersect($select, $groupFields));
                    if (!empty($keep)) {
                        if (!empty($this->selectFields)) {
                            $keep[] = '_items';
                        }
                        $group = array_intersect_key($group, array_flip($keep));
                    }
                    foreach (array_intersect($except, $groupFields) as $field) {
                        unset($group[$field]);
                    }
                }
                unset($group);
            } finally {
                $this->groupByField = $groupBy;
                $this->selectFields = $select;
                $this->exceptFields = $except;
            }
            return $records;
        }
        return array_map(function ($record) {
            if (!empty($this->selectFields)) {
                $filtered = [];
                foreach ($this->selectFields as $field) {
                    if (array_key_exists($field, $record)) {
                        $filtered[$field] = $record[$field];
                    }
                }
                if (isset($record['key']) && !array_key_exists('key', $filtered)) {
                    $filtered['key'] = $record['key'];
                }
                if ($this->distanceConfig !== null && isset($record['_distance']) && !array_key_exists('_distance', $filtered)) {
                    $filtered['_distance'] = $record['_distance'];
                }
                return $filtered;
            }
            foreach ($this->exceptFields as $field) {
                unset($record[$field]);
            }
            return $record;
        }, $records);
    }

    /**
     * How the query reads its records: per segment whether it is skipped (a global field index or
     * the keys rule it out), read through an index (candidates) or scanned; how it sorts (top-k, full) and
     * whether it stops early. Every record read is checked against every filter ("residual": always).
     * Nothing is built or written; an index a query would build first counts as a scan. With $analyze the
     * query runs too and "actual" reports what it read and returned.
     * @return array
     */
    public function explain(bool $analyze = false): array {
        $dbname = $this->kernel()->layout->normalizeDbName($this->dbname);
        $plan = $this->buildPlan();
        $info = $this->kernel()->planner->explainPlan($dbname, $plan);
        $segments = $info['segments'];
        $lateJoins = !empty($this->joinConfigs) && $this->groupByField === null && !$this->sortsByJoinAlias();
        $topK = $this->topKSize($lateJoins);
        if (!empty($this->spatialFilters)) {
            $access = 'spatial';
        } elseif ($segments['total'] === 0 || $segments['skipped'] === $segments['total']) {
            $access = 'none';
        } elseif ($segments['scanned'] === 0) {
            $access = 'index';
        } elseif ($segments['indexed'] === 0) {
            $access = 'scan';
        } else {
            $access = 'mixed';
        }
        $joins = [];
        foreach ($this->joinConfigs as $join) {
            $joins[] = ['db' => $join['foreignDb'], 'on' => $join['localKey'] . ' = ' . $join['foreignKey'],
                'strategy' => $join['foreignKey'] === 'key' ? 'key-lookup' : 'in-lookup', 'when' => $lateJoins ? 'after-slice' : 'before-sort'];
        }
        $result = [
            'explain' => 1,
            'db' => $this->dbname,
            'sharded' => $info['sharded'],
            'access' => $access,
            'segments' => $segments,
            'plan' => $info['plan'],
            'planTruncated' => $info['planTruncated'] ?? false,
            'estimatedRead' => $info['estimatedRead'],
            'filter' => $this->keyOrderLookup() ? ['keys-in-filter-order'] : $plan,
            'residual' => true,
            'spatial' => empty($this->spatialFilters) ? null : array_column($this->spatialFilters, 'type'),
            'sort' => empty($this->sortKeys) ? 'none' : ($topK !== null ? 'top-k' : 'full'),
            'earlyExit' => empty($this->sortKeys) && $this->groupByField === null && $this->limitCount !== null
                && (empty($this->joinConfigs) || $lateJoins) && empty($this->spatialFilters),
            'joins' => $joins,
            'groupBy' => $this->groupByField,
        ];
        if ($analyze) {
            $this->lastScan = null;
            $start = microtime(true);
            $rows = $this->get();
            $result['actual'] = [
                'recordsRead' => $this->lastScan['recordsRead'] ?? null,
                'segmentsRead' => $this->lastScan === null ? null : $this->lastScan['segments'] - $this->lastScan['skipped'],
                'returned' => count($rows),
                'ms' => round((microtime(true) - $start) * 1000, 3),
            ];
        }
        return $result;
    }

    /**
     * Execute query and get all results
     * @return array
     */
    public function get(): array {
        return $this->results(true);
    }

    /**
     * get() with or without select()/except() (aggregates read the fields themselves)
     */
    private function results(bool $project): array {
        if (!empty($this->joinConfigs)) {
            // Every database of the query read in one consistent state (shared locks held together)
            $databases = array_merge([$this->dbname], array_column($this->joinConfigs, 'foreignDb'));
            return $this->kernel()->snapshot->withSnapshot($databases, function () use ($project) {
                return $this->resultsLocked($project);
            });
        }
        return $this->resultsLocked($project);
    }

    private function resultsLocked(bool $project): array {
        // Joins only add fields: without groupBy() and without sorting by a join alias they run on the
        // records left after sort, offset and limit, which also allows stopping early
        $lateJoins = !empty($this->joinConfigs) && $this->groupByField === null && !$this->sortsByJoinAlias();
        $canEarlyExit = (empty($this->joinConfigs) || $lateJoins) && $this->groupByField === null && empty($this->sortKeys);
        $results = $this->filteredRecords($canEarlyExit, null, $this->topKSize($lateJoins));
        $results = $this->applyDistanceCalculation($results);
        if (!$lateJoins) {
            $results = $this->applyJoins($results);
        }
        if ($this->groupByField !== null) {
            $results = $this->applyGroupBy($results); // having() only applies to groups
        }
        $results = $this->applySort($results);
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $results = array_slice($results, $this->offsetCount, $this->limitCount);
        }
        if ($lateJoins) {
            $results = $this->applyJoins($results);
        }
        return $project ? $this->applyProjection($results) : $results;
    }

    /**
     * Size of the top-k a sorted query with a small limit needs (offset + limit), or null: no limit, a
     * large one, grouping, joins before the sort, or a sort by withDistance()'s _distance
     */
    private function topKSize(bool $lateJoins): ?int {
        if (empty($this->sortKeys) || $this->limitCount === null || $this->groupByField !== null
            || (!empty($this->joinConfigs) && !$lateJoins) || $this->distanceConfig !== null) {
            return null;
        }
        $k = $this->offsetCount + $this->limitCount;
        return $k <= 1000 ? max(1, $k) : null;
    }

    private function sortsByJoinAlias(): bool {
        $aliases = array_column($this->joinConfigs, 'alias');
        foreach ($this->sortKeys as $sk) {
            if (in_array($sk['field'], $aliases, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The records a write (update/delete/removeFields) applies to, one segment at a time: $apply($dbname,
     * segment, [key => record]) once a segment's matches are complete. Filters, sort, offset and limit apply;
     * select/except/join don't matter. Without sort, spatial filters and a key lookup the matches stream from
     * the scan (one segment held); otherwise the target keys come first and their records are read segment by
     * segment. Caller holds the exclusive lock.
     */
    private function eachTargetSegment(callable $apply): void {
        if ($this->groupByField !== null) {
            throw new \LogicException('noneDB: update(), delete() and removeFields() cannot be combined with groupBy()');
        }
        $kernel = $this->kernel();
        $dbname = $kernel->layout->normalizeDbName($this->dbname);
        $kernel->buffer->flushPendingBuffers($dbname);
        // A missing database has no segments: nothing is written, and the filters are still checked
        if (!empty($this->sortKeys) || !empty($this->spatialFilters) || $this->keyOrderLookup()) {
            foreach ($kernel->findFilter->segmentMatches($dbname, ['key' => $this->targetKeys()]) as $segment => $records) {
                $apply($dbname, $segment, $records);
            }
            return;
        }
        $size = $kernel->segments->isSharded($dbname) ? $kernel->segments->shardSizeOf($dbname) : null;
        $segment = null;
        $held = [];
        $this->eachMatch(function ($key, array $record) use ($apply, $dbname, $size, &$segment, &$held) {
            $of = $size === null ? null : intdiv($key, $size);
            if (!empty($held) && ($of !== $segment || count($held) >= \noneDB\Internal\Storage\RecordIO::BATCH)) {
                $apply($dbname, $segment, $held); // the scan is past that segment, or holds a batch of it
                $held = [];
            }
            $segment = $of;
            $held[$key] = $record;
        }, $this->limitCount === null, $this->offsetCount, $this->limitCount);
        if (!empty($held)) {
            $apply($dbname, $segment, $held);
        }
    }

    /**
     * Keys of the records a write applies to when sort, spatial filters or a key lookup decide (see
     * eachTargetSegment())
     * @return int[]
     */
    private function targetKeys(): array {
        $records = $this->applySort($this->applyDistanceCalculation($this->filteredRecords(empty($this->sortKeys))));
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $records = array_slice($records, $this->offsetCount, $this->limitCount);
        }
        $keys = [];
        foreach ($records as $record) {
            if (isset($record['key']) && is_int($record['key'])) {
                $keys[] = $record['key'];
            }
        }
        return $keys;
    }

    /**
     * Run a write under the database's exclusive lock, so selecting and writing are one atomic step
     * ($op: the operation, for getDurability())
     */
    private function withWriteLock(callable $fn, string $op) {
        $dbname = $this->kernel()->layout->normalizeDbName($this->dbname);
        return $this->kernel()->locks->withLock($dbname, true, $fn, $op);
    }

    /**
     * Get first matching record
     * @return array|null
     */
    public function first(): ?array {
        $originalLimit = $this->limitCount;
        $this->limitCount = $originalLimit === null ? 1 : min(1, $originalLimit); // limit(0): nothing
        try {
            $results = $this->get();
        } finally {
            $this->limitCount = $originalLimit;
        }
        return $results[0] ?? null;
    }

    /**
     * Get last matching record
     * @return array|null
     */
    public function last(): ?array {
        if (!$this->streamsResults()) {
            $results = $this->get();
            return count($results) > 0 ? $results[count($results) - 1] : null;
        }
        $last = null;
        $this->eachResult(function (array $record) use (&$last) {
            $last = $record;
        });
        return $last === null ? null : $this->applyProjection([$last])[0];
    }

    /**
     * Count matching records (groups, with groupBy())
     * @return int
     */
    public function count(): int {
        if ($this->isUnfiltered()) {
            return $this->db->count($this->dbname, 0);
        }
        if ($this->groupByField !== null) {
            return count($this->get());
        }
        if (empty($this->spatialFilters) && !$this->keyOrderLookup()) {
            return $this->countMatches(); // record by record, none kept in memory
        }
        $results = $this->filteredRecords(true);
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $results = array_slice($results, $this->offsetCount, $this->limitCount);
        }
        return count($results);
    }

    private function countMatches(): int {
        $dbname = $this->kernel()->layout->normalizeDbName($this->dbname);
        return $this->kernel()->locks->withReadLock($dbname, function () {
            $count = 0;
            $this->eachMatch(function () use (&$count) {
                $count++;
            }, $this->limitCount === null, $this->offsetCount, $this->limitCount);
            return $count;
        });
    }

    /**
     * True when count() can use the stored counters
     * @return bool
     */
    private function isUnfiltered(): bool {
        return empty($this->whereFilters)
            && empty($this->orWhereFilters)
            && !$this->hasAdvancedFilters()
            && empty($this->spatialFilters)
            && $this->groupByField === null
            && empty($this->havingFilters)
            && $this->limitCount === null
            && $this->offsetCount === 0;
    }

    /**
     * Check if any records match
     * @return bool
     */
    public function exists(): bool {
        if ($this->limitCount === 0) {
            return false;
        }
        if ($this->groupByField !== null || !empty($this->havingFilters)) {
            return $this->first() !== null; // having() may drop every group: no early exit
        }
        // Sort, joins and projection don't change whether a record exists: stop at offset + 1 matches
        return count($this->filteredRecords(true, $this->offsetCount + 1)) > $this->offsetCount;
    }

    // ==========================================
    // AGGREGATION METHODS
    // ==========================================

    /**
     * Sum and number of the numeric values of a field in the results (booleans and non-numeric values are ignored)
     * @return array [sum, count]
     */
    private function numericTotal(string $field): array {
        $sum = 0;
        $count = 0;
        $this->eachResult(function (array $record) use ($field, &$sum, &$count) {
            $number = \noneDB\Internal\Query\Values::numberOf($record[$field] ?? null);
            if ($number !== null) {
                $sum += $number;
                $count++;
            }
        });
        return [$sum, $count];
    }

    /**
     * Sum of field values
     * @param string $field
     * @return float
     */
    public function sum(string $field): float {
        return (float)$this->numericTotal($field)[0];
    }

    /**
     * Average of field values
     * @param string $field
     * @return float
     */
    public function avg(string $field): float {
        [$sum, $count] = $this->numericTotal($field);
        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * Minimum value of field (same ordering as sort())
     * @param string $field
     * @return mixed|null
     */
    public function min(string $field) {
        $min = null;
        $found = false;
        $this->eachResult(function (array $record) use ($field, &$min, &$found) {
            if (isset($record[$field]) && (!$found || \noneDB\Internal\Query\Values::compareSortValues($record[$field], $min) < 0)) {
                $min = $record[$field];
                $found = true;
            }
        });
        return $min;
    }

    /**
     * Maximum value of field (same ordering as sort())
     * @param string $field
     * @return mixed|null
     */
    public function max(string $field) {
        $max = null;
        $found = false;
        $this->eachResult(function (array $record) use ($field, &$max, &$found) {
            if (isset($record[$field]) && (!$found || \noneDB\Internal\Query\Values::compareSortValues($record[$field], $max) > 0)) {
                $max = $record[$field];
                $found = true;
            }
        });
        return $max;
    }

    /**
     * Get unique values of field
     * @param string $field
     * @return array
     */
    public function distinct(string $field): array {
        $seen = [];
        $values = [];
        $this->eachResult(function (array $record) use ($field, &$seen, &$values) {
            \noneDB\Internal\Query\Values::collectUnique([$record], $field, $seen, $values);
        });
        return $values;
    }

    // ==========================================
    // WRITE METHODS
    // ==========================================

    /**
     * Update matching records (sort/offset/limit select which ones)
     * @param array $set Fields to update
     * @return array ["n" => count]
     */
    public function update(array $set): array {
        $error = $this->kernel()->crud->updateSetError($set);
        if ($error !== null) {
            return $error;
        }
        return $this->withWriteLock(function () use ($set) {
            $n = 0;
            $this->eachTargetSegment(function (string $dbname, ?int $segment, array $records) use ($set, &$n) {
                $n += $this->kernel()->crud->updateRecords($dbname, $segment, $records, $set);
            });
            return ['n' => $n];
        }, 'update');
    }

    /**
     * Delete matching records (sort/offset/limit select which ones)
     * @return array ["n" => count]
     */
    public function delete(): array {
        return $this->withWriteLock(function () {
            $n = 0;
            $this->eachTargetSegment(function (string $dbname, ?int $segment, array $records) use (&$n) {
                $n += $this->kernel()->writer->deleteSegmentRecords($dbname, $segment, $records);
            });
            return ['n' => $n];
        }, 'delete');
    }

    /**
     * Remove fields from the stored matching records (permanent)
     * @param array $fields Fields to remove ("key" can't be removed)
     * @return array ["n" => count of updated records, "fields_removed" => array of removed fields]
     */
    public function removeFields(array $fields): array {
        if (empty($fields)) {
            return ['n' => 0, 'fields_removed' => [], 'error' => 'No fields specified'];
        }
        $fieldsToRemove = array_values(array_filter($fields, function ($f) { return $f !== 'key'; }));
        if (empty($fieldsToRemove)) {
            return ['n' => 0, 'fields_removed' => [], 'error' => 'Cannot remove reserved field: key'];
        }

        return $this->withWriteLock(function () use ($fieldsToRemove) {
            $n = 0;
            $removed = [];
            // Fields are removed from the stored records, never from a projected/joined result
            $this->eachTargetSegment(function (string $dbname, ?int $segment, array $records) use ($fieldsToRemove, &$n, &$removed) {
                $replacements = [];
                foreach ($records as $key => $record) {
                    $changed = false;
                    foreach ($fieldsToRemove as $field) {
                        if (array_key_exists($field, $record)) {
                            unset($record[$field]);
                            $removed[$field] = true;
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        $replacements[$key] = $record;
                    }
                }
                $n += $this->kernel()->writer->updateSegmentRecords($dbname, $segment, $replacements);
            });
            return [
                'n' => $n,
                'fields_removed' => array_values(array_filter($fieldsToRemove, function ($f) use ($removed) { return isset($removed[$f]); }))
            ];
        }, 'removeFields');
    }
}
}
