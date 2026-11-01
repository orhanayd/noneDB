<?php
// Process-wide state shared by every noneDB instance: caches, lock registry, counters, test hooks
namespace noneDB\Internal\Cache;

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
    public $indexStats=['loads' => 0, 'replayedLines' => 0, 'checkpoints' => 0, 'pointLookups' => 0, 'pagesRead' => 0, 'bodiesRead' => 0, 'evictionsBetween' => 0, 'bodiesBuilt' => 0, 'filtersBuilt' => 0, 'bodiesDecoded' => 0, 'keyDecodes' => 0, 'bodyLookups' => 0, 'dataHeld' => 0, 'heldReads' => 0, 'liveReads' => 0, 'liveTries' => 0]; // tests count the work // @test
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
    public $segmentPairTests=0;       // edge pairs compared by ringSelfIntersects() (tests check the complexity) // @test
    public $geometryValidations=0;    // validateGeoJSON() calls (tests check that indexed candidates skip it) // @test
    public $segmentReads=0;           // readSegmentRecords() calls (tests check shard skipping) // @test
    public $directoryListings=0;      // listFilesWithPrefix() calls (tests check reads don't list the directory) // @test
    public $pointEdgeTests=0;         // point-edge tests of the polygon hole check (tests check the complexity) // @test
    public $sweepBudget=null;         // tests only: work the quick polygon checks may do before the sweep line (0 = sweep at once) // @test
    public $mbstringFn=null;          // tests only: callable() overriding "is mbstring available" // @test
    public $exactMapRecords=null;     // tests only: the record limit of an exact map (null: FieldIndex::EXACT_MAP_RECORDS) // @test
    public $sapi=null;                // tests only: the SAPI an exact map is decided by (null: PHP_SAPI) // @test
    public $syncFn=null;              // tests only: callable($handle, 'file'|'dir', $path): bool, replaces fsync() // @test
    public $flushFn=null;             // tests only: callable($handle, $path): bool, replaces fflush() of appends and replacements // @test
    public $writeFn=null;             // tests only: callable($handle, $bytes, $path): int|false, replaces fwrite() of appends and replacements // @test
    public $renameFn=null;            // tests only: callable($from, $to): bool, replaces rename() (and enables the retry) // @test
    public $osFamily=null;            // tests only: the platform durable mode is checked against (null: PHP_OS_FAMILY) // @test
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

    public $faultHook=null;           // tests only: callable($point, array $context), may throw or exit // @test
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
