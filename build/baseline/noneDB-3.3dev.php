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

class noneDB {

    // Configuration file name
    private static $configFile = '.nonedb';
    private static $configLoaded = false;
    private static $configData = null;
    private static $configFilePath = null;    // Path of the loaded config file (null = none / dev mode)
    private static $configFileDir = null;     // Directory relative dbDir paths in the file resolve against
    private static $scriptDirs = [];          // SCRIPT_FILENAME + cwd -> resolved entry script directory

    // Placeholder keys shipped in the docs/templates; never accepted as a real secret
    private static $placeholderKeys = [
        'CHANGE_THIS_TO_A_SECURE_RANDOM_STRING',
        'YOUR_SECURE_RANDOM_STRING',
        'YOUR_SECURE_RANDOM_STRING_HERE',
        'GENERATE_UNIQUE_64_CHAR_STRING',
        'your_secure_key',
        'your_key',
    ];

    // secretKey values live outside the object so print_r/var_export/serialize can't leak them
    private static $secretKeys = [];

    private $dbDir = null;      // Set via .nonedb config file or constructor
    private $autoCreateDB = true;
    private $devMode = false;

    // Sharding configuration
    private $shardingEnabled=true;   // Enable/disable auto-sharding
    private $shardSize=10000;         // Records per shard for NEW sharded databases (existing ones keep the size stored in their meta)
    private $autoMigrate=true;        // Auto-migrate legacy DBs to sharded format

    // File locking configuration
    private $lockTimeout=5;           // Max seconds to wait for a database lock (0 = wait forever)
    private $lockRetryDelay=10000;    // Microseconds between lock attempts (10ms)

    // Write buffer configuration (off by default since v3.2.0: direct writes are faster and always visible)
    private $bufferEnabled=false;             // Enable/disable write buffering
    private $bufferSizeLimit=1048576;         // 1MB buffer size limit per buffer
    private $bufferCountLimit=10000;          // Max records per buffer (safety limit)
    private $bufferFlushOnRead=true;          // Flush buffer before read operations
    private $bufferFlushInterval=30;          // Seconds between auto-flush (0 = disabled)
    private $bufferAutoFlushOnShutdown=true;  // Register shutdown handler for flush

    // Buffer state tracking (runtime)
    private $bufferLastFlush=[];              // Track last flush time per DB/shard
    private $shutdownHandlerRegistered=false; // Track if shutdown handler is registered
    private static $bufferCounts=[];          // bufferPath => [size, count] so limits don't need a re-read per insert
    private static $bufferEnds=[];            // bufferPath => size the buffer had after this process's last append
    private static $flushingNow=[];           // database base path => true while its buffer is being flushed

    // Performance caches (runtime). All keys are full file paths (or key-fingerprint|dbname for hashes),
    // so instances with different dbDir/secretKey never see each other's entries.
    private $hashCache=[];                    // keyFingerprint|dbname -> hash (PBKDF2 is expensive)
    private $metaCache=[];                    // meta path -> meta data
    private $metaCacheTime=[];                // kept for API compatibility (unused since v3.2.0)

    // Index configuration
    private $indexEnabled=true;               // Kept for API compatibility (enableIndexing / isIndexingEnabled)
    private $indexCache=[];                   // jidx path -> JSONL index (see SegmentIndex)
    private $verifiedIndexes=[];              // jidx path -> true: the cached index covers the data file as it is
    private $shardedCache=[];                 // meta path -> bool

    // JSONL Storage Engine
    private $jsonlFormatCache=[];             // data path -> bool (JSONL vs legacy v2)
    private $jsonlGarbageThreshold=0.3;       // Trigger compaction when garbage > 30% of live records

    // Static caches for cross-instance sharing
    private static $staticIndexCache=[];
    private static $staticVerifiedIndexes=[];
    private static $pendingRecoveryEvents=[]; // lock path -> event id -> event found by a reader, logged by the next writer
    private static $uncheckpointed=[];        // lock path -> segments loaded without a checkpoint, written by the next writer
    private static $indexStats=['loads' => 0, 'replayedLines' => 0, 'checkpoints' => 0]; // tests count the work
    private static $staticShardedCache=[];
    private static $staticMetaCache=[];
    private static $staticMetaCacheTime=[];
    private static $staticHashCache=[];
    private static $staticFormatCache=[];
    private static $staticFileExistsCache=[];
    private static $staticSanitizeCache=[];
    private static $staticFieldIndexCache=[];
    private static $staticSpatialIndexCache=[];
    private static $staticGlobalSpatialIndexCache=[];
    private static $staticGenerations=[];     // lock path -> generation the caches were validated against
    private static $staticHashFilesLoaded=[]; // hash cache file -> true
    private static $staticCacheEnabled=true;  // Enable/disable static caching

    // Process-wide lock registry (shared by every instance so two instances never self-deadlock)
    private static $lockRegistry=[];          // lock path -> ['fp', 'mode' => LOCK_SH|LOCK_EX, 'depth', 'dbname', 'gen', 'flags', 'bumped', 'pending', 'scope', 'rolledBack', 'sid'] while locked
    private static $lockHandles=[];           // lock path -> ['fp' => resource, 'pid' => int]: unlocked handles kept open for the next operation
    private $generations=[];                  // per-instance view of $staticGenerations
    private static $sessionCounter=0;         // id of the latest lock session ('sid' in the registry)
    private static $shutdownCleanupRegistered=false;
    private static $segmentPairTests=0;       // edge pairs compared by ringSelfIntersects() (tests check the complexity)
    private static $geometryValidations=0;    // validateGeoJSON() calls (tests check that indexed candidates skip it)
    private static $segmentReads=0;           // readSegmentRecords() calls (tests check shard skipping)
    private static $directoryListings=0;      // listFilesWithPrefix() calls (tests check reads don't list the directory)
    private static $pointEdgeTests=0;         // point-edge tests of the polygon hole check (tests check the complexity)
    private static $sweepBudget=null;         // tests only: work the quick polygon checks may do before the sweep line (0 = sweep at once)
    private static $mbstringFn=null;          // tests only: callable() overriding "is mbstring available"

    // Durability (v3.3.0): optional fsync, what the last write did (getDurability())
    private $fsync=false;                     // sync data, meta and indexes to disk (power-loss durability where possible)
    private static $syncFn=null;              // tests only: callable($handle, 'file'|'dir'): bool, replaces fsync()
    private static $flushFn=null;             // tests only: callable($handle, $path): bool, replaces fflush() of appends
    private static $renameFn=null;            // tests only: callable($from, $to): bool, replaces rename() (and enables the retry)
    private static $syncStats=['file' => 0, 'dir' => 0, 'failed' => 0];
    private static $lastSyncError=null;
    private static $lastOperationDurability=null;
    private static $dirSyncWorks=[];          // dbDir => can its directory be synced
    private static $noWrites=0;               // > 0 while checkIntegrity() runs: no recovery, no repair, no file written
    private static $multiLock=0;              // > 0 inside withSnapshot(): no exclusive lock may be waited for

    private static $faultHook=null;           // tests only: callable($point, array $context), may throw or exit
    private $hashFilesLoaded=[];

    // Field indexing configuration
    private $fieldIndexEnabled = true;        // Enable field-based indexing
    // Static caches have a soft size limit (v3.3): estimated bytes, checked when the last lock is released
    private static $cacheMemoryLimit = null;  // bytes, 0 = no limit, null = automatic (cacheLimitBytes); the last instance's setting counts
    private static $cacheTouch = [];          // cache key -> tick of its last use
    private static $cacheTick = 0;
    private static $cacheEvictions = 0;       // entries dropped by evictCaches() (tests)
    private static $cacheGrew = false;        // something was loaded into a cache since evictCaches() last looked
    private $fieldIndexCache = [];            // field index path -> index

    // Spatial indexing configuration
    private $spatialIndexEnabled = true;      // Enable spatial R-tree indexing
    private $spatialIndexCache = [];          // spatial index path -> index
    private $globalSpatialIndexCache = [];    // global spatial index path -> index
    private $rtreeNodeSize = 32;              // Max entries per R-tree node
    private $appendMismatch = false;          // appendToFile(): the file didn't have the expected size
    private $appendSyncFailed = false;        // appendToFile(): fsync of the appended bytes failed (they were undone)
    private static $exactFloats = false;      // serialize_precision is -1 for the batch being encoded (withExactFloats)
    private $dataSizes = [];                  // data path -> size, until another process writes (global index skips)
    private $dataEpochs = [];                 // data path -> segment epoch read from its checkpoint (global index entries)
    private $convertedSegments = [];          // data path -> no v3.2 index file left for the segment
    private $shardFilesUpTo = [];             // database file prefix -> highest shard number with files (setOrphanShardsAside)
    private $globalSkip = [];                 // lock path -> global field index path -> shards a lookup checks (globalCheckShards)
    private $segWrites = [];                  // lock path -> ['seq' => n, 'paths' => [data path => seq]]: data files this process wrote
    private static $staticDataSizes = [];
    private static $staticGlobalSkip = [];
    private static $staticSegWrites = [];
    private static $staticDataEpochs = [];
    private static $staticConvertedSegments = [];
    private static $staticShardFilesUpTo = [];
    private static $secondaryChecked = [];    // data path -> "epoch:bytes" a secondary index was checked against
    private static $tailCache = [];           // data path -> [from, to, rows] of the last parsed data tail
    private static $indexTokens = [];         // field -> its index file name part (indexToken)
    private $writableIndexes = [];            // jidx path -> lock session id in which it was checked against its data file
    private $indexedCandidates = [];          // key => true: the last spatialCandidates() found it in an R-tree (valid geometry)
    private static $staticWritableIndexes = [];
    private $distanceCache = [];              // unused since v3.3.0 (kept for code that inspects it)

    private $keyFingerprint = null;           // HMAC of the secret, used to bind caches to the key
    private $dbBases = [];                    // dbname -> file name prefix (depends on dbDir and key)
    private $unsavedHashes = [];              // dbname -> hash not yet in the persistent hash cache
    private $dbBasesDir = null;               // dbDir the prefixes were built for

    private static $devModeNoticeShown = false;

    /**
     * Constructor - load config and initialize static caches
     * @param array|null $config Optional config array; merged over the .nonedb file when one exists
     * @throws \RuntimeException If no configuration can be found outside dev mode
     * @throws \InvalidArgumentException If secretKey is missing, empty, not a string or a placeholder
     */
    public function __construct($config = null){
        $this->loadConfig($config);
        $this->validateSecretKey();
        if(((int)ini_get('mbstring.func_overload')) & 2){
            // Byte offsets are computed with strlen(); an overloaded strlen() would corrupt them
            throw new \RuntimeException('noneDB: mbstring.func_overload must be disabled');
        }

        // Link instance caches to static caches for cross-instance sharing
        if(self::$staticCacheEnabled){
            $this->indexCache = &self::$staticIndexCache;
            $this->verifiedIndexes = &self::$staticVerifiedIndexes;
            $this->shardedCache = &self::$staticShardedCache;
            $this->metaCache = &self::$staticMetaCache;
            $this->metaCacheTime = &self::$staticMetaCacheTime;
            $this->hashCache = &self::$staticHashCache;
            $this->jsonlFormatCache = &self::$staticFormatCache;
            $this->fieldIndexCache = &self::$staticFieldIndexCache;
            $this->spatialIndexCache = &self::$staticSpatialIndexCache;
            $this->globalSpatialIndexCache = &self::$staticGlobalSpatialIndexCache;
            $this->generations = &self::$staticGenerations;
            $this->hashFilesLoaded = &self::$staticHashFilesLoaded;
            $this->writableIndexes = &self::$staticWritableIndexes;
            $this->dataSizes = &self::$staticDataSizes;
            $this->globalSkip = &self::$staticGlobalSkip;
            $this->segWrites = &self::$staticSegWrites;
            $this->dataEpochs = &self::$staticDataEpochs;
            $this->convertedSegments = &self::$staticConvertedSegments;
            $this->shardFilesUpTo = &self::$staticShardFilesUpTo;
        }

        if(!self::$shutdownCleanupRegistered){
            self::$shutdownCleanupRegistered = true;
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
        if(empty(self::$lockRegistry)){
            return;
        }
        foreach(self::$lockRegistry as $entry){
            @flock($entry['fp'], LOCK_UN);
            @fclose($entry['fp']);
        }
        self::$lockRegistry = [];
        self::clearStaticCache();
    }

    /**
     * Never expose the secret in debug output
     * @return array
     */
    public function __debugInfo(){
        return [
            'dbDir' => $this->dbDir,
            'secretKey' => '***',
            'autoCreateDB' => $this->autoCreateDB,
            'shardingEnabled' => $this->shardingEnabled,
            'shardSize' => $this->shardSize,
            'bufferEnabled' => $this->bufferEnabled,
            'devMode' => $this->devMode,
        ];
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
        $source = self::$secretKeys[$this->cloneSourceId] ?? null;
        $this->cloneSourceId = spl_object_id($this);
        if($source !== null){
            self::$secretKeys[$this->cloneSourceId] = $source;
        }
    }

    private $cloneSourceId = null;

    public function __destruct(){
        // Anything still dirty belongs to a lock we no longer hold; it was flushed on release.
        unset(self::$secretKeys[spl_object_id($this)]);
    }

    private function getSecretKey(){
        return self::$secretKeys[spl_object_id($this)] ?? null;
    }

    private function setSecretKey($key){
        self::$secretKeys[spl_object_id($this)] = $key;
        $this->cloneSourceId = spl_object_id($this);
        $this->keyFingerprint = null;
        $this->dbBases = [];
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
        if(!self::$configLoaded){
            self::$configData = null;
            self::$configFilePath = null;
            self::$configFileDir = null;

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
                        self::$configData = $data;
                        self::$configFilePath = $configPath;
                        self::$configFileDir = dirname($configPath);
                    }
                }
            }
            self::$configLoaded = true;
        }

        if(self::$configData !== null){
            $fileConfig = self::$configData;
            if(isset($arrayConfig['dbDir'])){
                unset($fileConfig['dbDir']); // overridden below; no need to resolve the file's one
            }
            $this->applyConfig($fileConfig, self::$configFileDir, true);
        }
        if(!empty($arrayConfig)){
            $this->applyConfig($arrayConfig, null, false);
        }
        if(self::$configData !== null || $arrayIsComplete || ($keyGiven && $this->dbDir !== null)){
            return;
        }

        if(self::isDevModeEnabled()){
            $this->devMode = true;
            $projectRoot = self::projectRoot();
            if($this->dbDir === null){
                $this->dbDir = $projectRoot . '/db/';
            }
            if(!$keyGiven){
                // Deterministic for backward compatibility with databases created in dev mode before v3.2.0.
                $this->setSecretKey('nonedb_dev_mode_key_' . md5(__DIR__));
            }
            if(PHP_SAPI !== 'cli' && !self::$devModeNoticeShown){
                self::$devModeNoticeShown = true;
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
            $paths[] = rtrim($dir, '/\\') . '/' . self::$configFile;
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
        if(isset(self::$scriptDirs[$memoKey])){
            return self::$scriptDirs[$memoKey];
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
        if(count(self::$scriptDirs) > 16){
            self::$scriptDirs = [];
        }
        return self::$scriptDirs[$memoKey] = $dir;
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
     * @param bool $fromFile True when the values come from the .nonedb file
     */
    private function applyConfig(array $config, ?string $baseDir, bool $fromFile){
        if(array_key_exists('secretKey', $config)){
            $this->setSecretKey($config['secretKey']);
        }
        if(isset($config['dbDir'])){
            $this->dbDir = $this->resolveDbDir((string)$config['dbDir'], $baseDir, $fromFile);
        }
        if(isset($config['autoCreateDB'])){
            $this->autoCreateDB = self::configBool('autoCreateDB', $config['autoCreateDB']);
        }

        // Sharding settings
        if(isset($config['shardingEnabled'])){
            $this->shardingEnabled = self::configBool('shardingEnabled', $config['shardingEnabled']);
        }
        if(isset($config['shardSize'])){
            $shardSize = self::configNumber($config['shardSize'], true) ?? 0;
            if($shardSize < 1){
                throw new \InvalidArgumentException("noneDB: 'shardSize' must be a positive integer");
            }
            $this->shardSize = $shardSize;
        }
        if(isset($config['autoMigrate'])){
            $this->autoMigrate = self::configBool('autoMigrate', $config['autoMigrate']);
        }

        // Compaction settings
        if(isset($config['autoCompactThreshold'])){
            $threshold = self::configNumber($config['autoCompactThreshold'], false) ?? 0.0;
            if($threshold <= 0){
                throw new \InvalidArgumentException("noneDB: 'autoCompactThreshold' must be greater than 0");
            }
            $this->jsonlGarbageThreshold = $threshold;
        }

        // Lock settings
        if(isset($config['lockTimeout'])){
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
        if(isset($config['lockRetryDelay'])){
            $delay = self::configNumber($config['lockRetryDelay'], true) ?? 0;
            if($delay < 1){
                throw new \InvalidArgumentException("noneDB: 'lockRetryDelay' must be a positive number of microseconds");
            }
            $this->lockRetryDelay = $delay;
        }

        // Write buffer
        if(isset($config['bufferEnabled'])){
            $this->bufferEnabled = self::configBool('bufferEnabled', $config['bufferEnabled']);
        }

        // Use indexes for queries (indexes are always kept up to date)
        if(isset($config['fieldIndexEnabled'])){
            $this->fieldIndexEnabled = self::configBool('fieldIndexEnabled', $config['fieldIndexEnabled']);
        }
        if(isset($config['spatialIndexEnabled'])){
            $this->spatialIndexEnabled = self::configBool('spatialIndexEnabled', $config['spatialIndexEnabled']);
        }
        if(isset($config['fsync'])){
            $this->fsync = self::configBool('fsync', $config['fsync']);
        }
        if(isset($config['cacheMemoryLimit'])){
            $limit = $config['cacheMemoryLimit'];
            if($limit === 'auto'){
                self::$cacheMemoryLimit = null;
            } elseif(!(is_int($limit) && $limit >= 0) && !(is_string($limit) && ctype_digit($limit))){
                throw new \InvalidArgumentException("noneDB: 'cacheMemoryLimit' must be a non-negative integer (bytes) or 'auto'");
            } else {
                self::$cacheMemoryLimit = (int)$limit;
            }
        }
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
     * A boolean setting: true/false, 1/0, "1"/"0", "true"/"false" (any case). Anything else is an error
     * (before v3.3 any non-empty string, "false" included, meant true).
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
     * Resolve a configured dbDir to an absolute path ending with a slash.
     * Relative paths (with or without a leading ./) are relative to the config file's directory
     * (file config) or to the entry script's directory (array config).
     */
    private function resolveDbDir(string $dbDir, ?string $baseDir, bool $fromFile): string {
        if(!self::isAbsolutePath($dbDir)){
            $dotted = substr($dbDir, 0, 2) === './' || substr($dbDir, 0, 3) === '../'
                || $dbDir === '.' || $dbDir === '..';
            $relative = preg_replace('#^(\./)+#', '', $dbDir);
            $baseDir = $baseDir ?? self::scriptDir();
            $resolved = rtrim($baseDir, '/\\') . '/' . $relative;

            // Before v3.2.0 "./x" in the file was resolved against the entry script and a plain "x"
            // against the working directory. Keep using such a directory (with a deprecation notice)
            // until the data is moved.
            $legacyBase = is_dir($resolved) ? null : ($dotted ? ($fromFile ? self::scriptDir() : null) : getcwd());
            if(is_string($legacyBase)){
                $legacy = rtrim($legacyBase, '/\\') . '/' . $relative;
                if(is_dir($legacy) && realpath($legacy) !== realpath($resolved)){
                    trigger_error(
                        "noneDB: dbDir '{$dbDir}' is now resolved relative to " . ($fromFile ? 'the .nonedb file' : 'the entry script')
                        . " ({$baseDir}); using the old location {$legacy} for now. Move the directory or use an absolute path.",
                        E_USER_DEPRECATED
                    );
                    $resolved = $legacy;
                }
            }
            $dbDir = $resolved;
        }

        if(substr($dbDir, -1) !== '/' && substr($dbDir, -1) !== '\\'){
            $dbDir .= '/';
        }
        return $dbDir;
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
        if(in_array($key, self::$placeholderKeys, true)){
            throw new \InvalidArgumentException(
                "noneDB: 'secretKey' is still the example placeholder. Replace it with a random secret "
                . "(php -r \"echo bin2hex(random_bytes(32));\"). If you already have data, see noneDB::migrateKey()."
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
        self::$configLoaded = false;
        self::$configData = null;
        self::$configFilePath = null;
        self::$configFileDir = null;
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

    /**
     * Re-key existing databases: rename every file created with $oldKey to the name $newKey produces.
     *
     * File names are {hash}-{dbname}... where hash depends on the secretKey, so changing the key
     * without this makes existing data invisible. Run it once, with no other process using the
     * databases, then update secretKey in your config. Each database is locked while it is renamed.
     * The old per-key hash cache and the pre-3.2 `.nonedb_hash_cache` file are removed.
     *
     * @param string $dbDir Database directory
     * @param string $oldKey Key the databases were created with (the old placeholder is accepted here)
     * @param string $newKey New secret key
     * @return array ['success' => bool, 'databases' => string[], 'files' => int, 'error' => ?string]
     */
    public static function migrateKey(string $dbDir, string $oldKey, string $newKey): array {
        $result = ['success' => false, 'databases' => [], 'files' => 0, 'error' => null];
        if(trim($oldKey) === '' || trim($newKey) === ''){
            $result['error'] = 'Both keys must be non-empty strings';
            return $result;
        }
        if(in_array($newKey, self::$placeholderKeys, true)){
            $result['error'] = 'The new key is a placeholder; generate a random one';
            return $result;
        }
        if($oldKey === $newKey){
            $result['error'] = 'The new key is the same as the old key';
            return $result;
        }
        if(!empty(self::$lockRegistry)){
            // Inside a database operation of this process: its own lock would block the renames forever
            $result['error'] = 'migrateKey() can\'t run while this process holds a database lock (inside a database operation)';
            return $result;
        }
        $dbDir = rtrim($dbDir, '/\\') . '/';
        if(!is_dir($dbDir)){
            $result['error'] = "Directory not found: {$dbDir}";
            return $result;
        }

        // Databases created with the old key: any file of theirs counts, so a run that was interrupted
        // (some files already renamed) finds them again and finishes
        $databases = [];
        $files = scandir($dbDir) ?: [];
        foreach($files as $file){
            if(preg_match("/^([0-9a-f]{20})-([A-Za-z0-9' -]+?)(?:_s\\d+)?\\.nonedb/", $file, $m)
                && !isset($databases[$m[2]]) && hash_pbkdf2('sha256', $m[2], $oldKey, 1000, 20) === $m[1]){
                $databases[$m[2]] = $m[1];
            }
        }

        // Plan every rename first, refuse on any conflict
        $plan = [];
        foreach($databases as $dbname => $oldHash){
            $newHash = hash_pbkdf2('sha256', $dbname, $newKey, 1000, 20);
            foreach($files as $file){
                // {hash}-{name}.*, {hash}-{name}_sN.*, and spatial files {hash}.nonedb.* / {hash}_sN.nonedb.*
                $named = strpos($file, $oldHash . '-' . $dbname . '.') === 0 || strpos($file, $oldHash . '-' . $dbname . '_s') === 0;
                $spatial = strpos($file, $oldHash . '.nonedb.') === 0 || preg_match('/^' . $oldHash . '_s\d+\.nonedb\./', $file) === 1;
                if(!$named && !$spatial){
                    continue;
                }
                $target = $newHash . substr($file, strlen($oldHash));
                if(file_exists($dbDir . $target)){
                    $result['error'] = "Target file already exists: {$target}";
                    return $result;
                }
                $plan[$dbname][] = [$file, $target];
            }
        }

        foreach($plan as $dbname => $renames){
            $oldHash = $databases[$dbname];
            $lockPath = $dbDir . $oldHash . '-' . $dbname . '.nonedb.lock';
            // Databases from before v3.2 have no lock file: the one opened here is removed afterwards
            clearstatcache(true, $lockPath);
            $hadLock = file_exists($lockPath);
            $fp = @fopen($lockPath, 'c+b');
            if($fp === false || !flock($fp, LOCK_EX)){
                if($fp !== false){
                    fclose($fp);
                }
                if(!$hadLock){
                    @unlink($lockPath);
                }
                $result['error'] = "Could not lock database '{$dbname}'";
                return $result;
            }
            try {
                // The files that make the database exist (data, meta, info) go last but one, so an
                // interrupted run still finds the database under the old key and can be repeated; the lock
                // file last, so writers keep blocking on it until we are done
                $rank = function($file) use ($oldHash, $dbname){
                    if(substr($file, -12) === '.nonedb.lock'){
                        return 2;
                    }
                    $base = $oldHash . '-' . $dbname;
                    return in_array($file, [$base . '.nonedb', $base . '.nonedb.meta', $base . '.nonedbinfo'], true) ? 1 : 0;
                };
                usort($renames, function($a, $b) use ($rank){
                    return $rank($a[0]) <=> $rank($b[0]) ?: strcmp($a[0], $b[0]);
                });
                foreach($renames as $pair){
                    if(!@rename($dbDir . $pair[0], $dbDir . $pair[1])){
                        $result['error'] = "Failed to rename {$pair[0]}";
                        return $result;
                    }
                    $result['files']++;
                    self::faultPoint('migrateKey:renamed', ['file' => $pair[0]]);
                }
            } finally {
                flock($fp, LOCK_UN);
                fclose($fp);
                if(!$hadLock){
                    @unlink($lockPath);
                }
            }
            $result['databases'][] = $dbname;
        }

        // Old caches can't be used with the new key (and the legacy one leaks names)
        $oldFingerprint = substr(hash_hmac('sha256', 'nonedb-key-fingerprint-v1', $oldKey), 0, 16);
        @unlink($dbDir . '.nonedb_hash_cache_' . $oldFingerprint);
        @unlink($dbDir . '.nonedb_hash_cache');
        self::clearStaticCache();

        $result['success'] = true;
        return $result;
    }

    /**
     * HMAC fingerprint of the secret; binds caches to the key without revealing it
     */
    private function getKeyFingerprint(){
        if($this->keyFingerprint === null){
            $this->keyFingerprint = substr(hash_hmac('sha256', 'nonedb-key-fingerprint-v1', (string)$this->getSecretKey()), 0, 16);
        }
        return $this->keyFingerprint;
    }

    /**
     * Path of the persistent hash cache for this dbDir + secretKey.
     * The file name is derived from the key, so different keys never share (or poison) a cache,
     * and the file can't be guessed without the key.
     */
    private function getHashCacheFile(){
        return $this->dbDir . '.nonedb_hash_cache_' . $this->getKeyFingerprint();
    }

    /**
     * Load the persistent hash cache for this dbDir + key (once per process)
     * @return void
     */
    private function loadPersistentHashCache(){
        $file = $this->getHashCacheFile();
        if(isset($this->hashFilesLoaded[$file])){
            return;
        }
        $this->hashFilesLoaded[$file] = true;

        $fp = is_file($file) ? @fopen($file, 'rb') : false; // not a directory in its place
        if($fp === false){
            return;
        }
        $content = '';
        if(flock($fp, LOCK_SH)){
            $content = @stream_get_contents($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        $loaded = json_decode((string)$content, true);
        if(!is_array($loaded)){
            return;
        }
        $prefix = $this->getKeyFingerprint() . '|';
        foreach($loaded as $dbname => $hash){
            if(is_string($dbname) && is_string($hash) && preg_match('/^[0-9a-f]{20}$/', $hash)){
                $this->hashCache[$prefix . $dbname] = $hash;
            }
        }
    }

    /**
     * Add one name -> hash entry to the persistent cache (locked read-merge-write).
     * Entries whose database no longer exists are dropped.
     * @return void
     */
    private function savePersistentHashCacheEntry($dbname, $hash){
        try {
            $this->ensureDbDir();
        } catch(\RuntimeException $e){
            return; // the cache is optional
        }
        $file = $this->getHashCacheFile();
        $isNew = !file_exists($file);
        $fp = @fopen($file, 'c+b');
        if($fp === false){
            return;
        }
        if($isNew){
            @chmod($file, 0600);
        }
        if(!flock($fp, LOCK_EX)){
            fclose($fp);
            return;
        }
        try {
            $entries = json_decode((string)stream_get_contents($fp), true);
            if(!is_array($entries)){
                $entries = [];
            }
            $entries[$dbname] = $hash;
            foreach($entries as $name => $h){
                if(!is_string($h)){
                    unset($entries[$name]);
                    continue;
                }
                $base = $this->dbDir . $h . '-' . $name;
                if($name !== $dbname && !file_exists($base . '.nonedb') && !file_exists($base . '.nonedb.meta') && !file_exists($base . '.nonedbinfo')){
                    unset($entries[$name]);
                }
            }
            $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if($json !== false){
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, $json);
                fflush($fp);
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Clear all static caches (useful for testing or memory management)
     * @return void
     */
    public static function clearStaticCache(){
        self::$staticIndexCache = [];
        self::$staticVerifiedIndexes = [];
        self::$uncheckpointed = [];
        self::$staticShardedCache = [];
        self::$staticMetaCache = [];
        self::$staticMetaCacheTime = [];
        self::$staticHashCache = [];
        self::$staticFormatCache = [];
        self::$staticFileExistsCache = [];
        self::$staticSanitizeCache = [];
        self::$staticFieldIndexCache = [];
        self::$staticSpatialIndexCache = [];
        self::$staticGlobalSpatialIndexCache = [];
        self::$staticGenerations = [];
        self::$staticHashFilesLoaded = [];
        self::$bufferCounts = [];
        self::$bufferEnds = [];
        self::$staticWritableIndexes = [];
        self::$staticDataSizes = [];
        self::$staticGlobalSkip = [];
        self::$staticSegWrites = [];
        self::$staticDataEpochs = [];
        self::$staticConvertedSegments = [];
        self::$staticShardFilesUpTo = [];
        self::$secondaryChecked = [];
        self::$cacheTouch = [];
        self::$tailCache = [];
        foreach(self::$lockHandles as $handle){
            @fclose($handle['fp']);
        }
        self::$lockHandles = [];
    }

    /**
     * Disable static caching (each instance uses its own cache)
     * @return void
     */
    public static function disableStaticCache(){
        self::$staticCacheEnabled = false;
    }

    /**
     * Enable static caching (default)
     * @return void
     */
    public static function enableStaticCache(){
        self::$staticCacheEnabled = true;
    }

    /**
     * Cached file_exists check. Entries are dropped whenever another process writes the database
     * (see withLock), so the cache can't go stale across processes.
     *
     * @param string $path File path to check
     * @return bool True if file exists
     */
    private function cachedFileExists($path){
        if(!self::$staticCacheEnabled){
            clearstatcache(true, $path);
            return file_exists($path);
        }
        if(isset(self::$staticFileExistsCache[$path])){
            return self::$staticFileExistsCache[$path];
        }
        clearstatcache(true, $path);
        $exists = file_exists($path);
        self::$staticFileExistsCache[$path] = $exists;
        return $exists;
    }

    /**
     * Mark file as existing in cache (call after creating file)
     * @param string $path File path
     */
    private function markFileExists($path){
        if(self::$staticCacheEnabled){
            self::$staticFileExistsCache[$path] = true;
        }
    }

    /**
     * Mark file as not existing in cache (call after deleting file)
     * @param string $path File path
     */
    private function markFileNotExists($path){
        if(self::$staticCacheEnabled){
            self::$staticFileExistsCache[$path] = false;
        }
    }

    /**
     * Validate a database name.
     * Allowed characters: A-Z a-z 0-9, space, apostrophe and hyphen. Anything else is rejected
     * (before v3.2.0 such characters were silently stripped, which let different names collide).
     *
     * @param mixed $dbname Database name
     * @return string The (unchanged) database name
     * @throws \InvalidArgumentException
     */
    private function normalizeDbName($dbname){
        if(is_int($dbname)){
            $dbname = (string)$dbname;
        }
        if(!is_string($dbname)){
            throw new \InvalidArgumentException('noneDB: database name must be a string');
        }
        if(isset(self::$staticSanitizeCache[$dbname])){
            return $dbname;
        }
        if($dbname === '' || preg_match("/^[A-Za-z0-9' -]+$/", $dbname) !== 1){
            throw new \InvalidArgumentException(
                "noneDB: invalid database name '{$dbname}'. Only letters A-Z/a-z, digits, space, apostrophe (') and hyphen (-) are allowed."
            );
        }
        if(self::$staticCacheEnabled){
            self::$staticSanitizeCache[$dbname] = true;
        }
        return $dbname;
    }

    /**
     * Hash a database name for its file names (PBKDF2-SHA256 keyed with secretKey).
     * Cached per key fingerprint in memory and in a per-key file, so the secret is always honoured.
     */
    private function hashDBName($dbname){
        $cacheKey = $this->getKeyFingerprint() . '|' . $dbname;
        if(isset($this->hashCache[$cacheKey])){
            return $this->hashCache[$cacheKey];
        }

        $this->loadPersistentHashCache();
        if(isset($this->hashCache[$cacheKey])){
            return $this->hashCache[$cacheKey];
        }

        $hash = hash_pbkdf2("sha256", (string)$dbname, (string)$this->getSecretKey(), 1000, 20);
        $this->hashCache[$cacheKey] = $hash;
        // Stored by the next write (noteWrite): reads never create files or directories (A-11)
        $this->unsavedHashes[(string)$dbname] = $hash;

        return $hash;
    }

    /**
     * Common file-name prefix of every file belonging to a database: {dbDir}{hash}-{dbname}
     */
    private function dbBase($dbname){
        if($this->dbBasesDir !== $this->dbDir){
            $this->dbBases = [];
            $this->dbBasesDir = $this->dbDir;
        }
        return $this->dbBases[$dbname] ?? ($this->dbBases[$dbname] = $this->dbDir . $this->hashDBName($dbname) . '-' . $dbname);
    }

    /**
     * Path of the main (non-sharded) data file
     */
    private function getDataPath($dbname){
        return $this->dbBase($dbname) . '.nonedb';
    }

    /**
     * Create the database directory (and protect it from direct web access) if needed
     */
    private function ensureDbDir(){
        if(is_dir($this->dbDir)){
            return;
        }
        if(!@mkdir($this->dbDir, 0755, true) && !is_dir($this->dbDir)){
            throw new \RuntimeException("noneDB: cannot create database directory {$this->dbDir}");
        }
        // Deny direct HTTP access on Apache; harmless elsewhere
        @file_put_contents($this->dbDir . '.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($this->dbDir . 'index.html', '');
    }


    /**
     * The cache limit in bytes, 0 = none: the cacheMemoryLimit setting, or by default half of PHP's
     * memory_limit (no limit without one, as before v3.3): the cache doesn't make a process run out of
     * memory, and doesn't slow down one that may use all the memory it needs
     */
    private static function cacheLimitBytes(){
        if(self::$cacheMemoryLimit !== null){
            return self::$cacheMemoryLimit;
        }
        $ini = strtolower(trim((string)ini_get('memory_limit')));
        $bytes = (int)$ini;
        if($bytes <= 0){
            return 0;
        }
        $unit = substr($ini, -1);
        $bytes *= $unit === 'g' ? 1073741824 : ($unit === 'm' ? 1048576 : ($unit === 'k' ? 1024 : 1));
        return intdiv($bytes, 2);
    }

    /**
     * Keep the static caches under cacheMemoryLimit (a soft limit: estimated bytes, not memory_get_usage()
     * of the caches; v3.3). Runs when the last lock of the process is released; while the process uses less
     * memory than the limit it does nothing. Least recently used entries go first, in batches of a tenth.
     * Every evicted entry is loaded again when needed.
     */
    private function evictCaches(){
        $limit = self::cacheLimitBytes();
        if(memory_get_usage() <= $limit && $limit >= 1048576){
            return;
        }
        $entries = [];
        $total = 0;
        $count = function($a){ return is_array($a) ? count($a) : 0; };
        foreach(self::$staticIndexCache as $key => $index){
            $bytes = 96 + 80 * $count($index['p'] ?? null);
            $entries[] = [self::$cacheTouch[$key] ?? 0, 'index', $key, $bytes];
            $total += $bytes;
        }
        foreach(self::$staticFieldIndexCache as $key => $index){
            if(isset($index['b'])){
                $bytes = 128 + 48 * count($index['b']) + 24 * array_sum(array_map(function($k){ return is_array($k) ? count($k) : 1; }, $index['b']));
            } elseif(isset($index['map'])){
                $bytes = 128 + 64 * count($index['map']) + 64 * count($index['sh'] ?? []);
            } elseif(isset($index['values'])){
                $bytes = 128 + 72 * count($index['values']);
            } else {
                $bytes = 256;
            }
            $entries[] = [self::$cacheTouch[$key] ?? 0, 'field', $key, $bytes];
            $total += $bytes;
        }
        foreach(self::$staticSpatialIndexCache as $key => $tree){
            $bytes = 128 + 200 * (int)($tree['cnt'] ?? $count($tree['leafOf'] ?? null));
            $entries[] = [self::$cacheTouch[$key] ?? 0, 'spatial', $key, $bytes];
            $total += $bytes;
        }
        foreach(self::$staticGlobalSpatialIndexCache as $key => $global){
            $bytes = 128 + 160 * $count($global['sh'] ?? null);
            $entries[] = [self::$cacheTouch[$key] ?? 0, 'gspatial', $key, $bytes];
            $total += $bytes;
        }
        if($total <= $limit){
            return;
        }
        usort($entries, function($a, $b){ return $a[0] <=> $b[0]; });
        $batch = max(1, intdiv(count($entries), 10));
        $dropped = 0;
        foreach($entries as [$tick, $cache, $key, $bytes]){
            if($total <= $limit && $dropped % $batch === 0){
                break;
            }
            if($cache === 'index'){
                unset(self::$staticIndexCache[$key], self::$staticVerifiedIndexes[$key], self::$staticWritableIndexes[$key]);
            } elseif($cache === 'field'){
                unset(self::$staticFieldIndexCache[$key]);
            } elseif($cache === 'spatial'){
                unset(self::$staticSpatialIndexCache[$key]);
            } else {
                unset(self::$staticGlobalSpatialIndexCache[$key]);
            }
            unset(self::$cacheTouch[$key]);
            $total -= $bytes;
            $dropped++;
            self::$cacheEvictions++;
        }
    }

    // ==========================================
    // DATABASE LOCKING AND STATE (v3.2.0)
    // ==========================================
    //
    // Every database has a lock file {base}.nonedb.lock. Besides being the flock target it holds a small
    // state record. It is read on every lock acquisition anyway, so the checks below cost no extra I/O:
    //
    //   "{generation}:{epoch}[ v][ b][ j=main,0,3 | J]"   space padded to LOCK_STATE_SIZE bytes
    //
    //   generation  bumped by the first file write of a write session (v3.3: sessions that write nothing
    //               keep it). A value other than the one this process last saw means another process
    //               changed the database: its caches are checked again (key indexes: only the new data).
    //   epoch       8 hex digits (v3.3), new when a data file is replaced or removed (compaction, migration,
    //               drop) and for every new lock file: another epoch drops every cache of the database, so a
    //               recreated lock file can't validate an old cache by reaching the same generation again.
    //               v3.2 reads "{generation}:{epoch}" as the generation and writes no epoch (then all
    //               caches are dropped, and the next exclusive lock holder writes a new epoch).
    //   v           verified: the one-time checks for pre-3.2 leftovers (old buffer files, no index list)
    //               are done, so the flags below are authoritative and nothing is re-checked per operation.
    //   b           the write buffer may hold records; they are flushed before the next read or write.
    //   j=... / J   journal: shard meta counters of these segments (J: all) may lag behind the data because a
    //               writer died (or a migration was interrupted); the next lock holder recounts them.
    //               Key, field and spatial indexes need none: they replay the data after their checkpoint.
    //
    // The record is overwritten in place with a fixed size (one small write: no truncate, no rename).
    // Lock files are never deleted (dropDB keeps them), and their handles stay open between operations.

    private const LOCK_STATE_SIZE = 128;
    private const LOCK_HANDLE_LIMIT = 64;

    /** Exception code: a write failed and undoing it failed too (outcome unknown, the next lock holder recovers) */
    public const ROLLBACK_FAILED = 3301;
    /** Exception code: the database lock could not be taken (lockTimeout) or was lost */
    public const LOCK_FAILED = 3302;
    /** Exception code (internal): an exclusive lock was needed while a snapshot holds several shared ones */
    private const SNAPSHOT_RETRY = 3303;

    private const EARTH_HALF_CIRCUMFERENCE = 20040000.0; // meters: no two points are farther apart

    // Rollback scope (v3.3.0). The outermost exclusive withLock() call of a database opens one scope; nested
    // exclusive calls on the same database join it. There are no nested scopes: cutting a file back to an
    // earlier size can't drop an earlier outer append and keep a later inner one. Every append to a data or
    // buffer file records the size the file had before the scope's first append to it (a "mark"). When an
    // exception leaves the outermost call, every marked file is cut back to its mark.
    // Work that must not depend on the caller's success (buffer flush, shard migration) runs in place only
    // while the scope has no appends yet, committing its own appends step by step (runIndependent);
    // requested later, it is deferred until the scope committed, like compaction, which replaces whole files.
    // A deferred job that fails doesn't fail the operation that committed before it: it is logged and runs
    // again on a later operation.

    private function getLockPath($dbname){
        return $this->dbBase($dbname) . '.nonedb.lock';
    }

    /**
     * Run $fn while holding the database lock: shared for reads, exclusive for writes.
     *
     * The lock is process-wide and re-entrant. A nested exclusive request upgrades a shared lock.
     *
     * @param string $dbname Database name (already normalized)
     * @param bool $exclusive
     * @param callable $fn
     * @return mixed Whatever $fn returns
     */
    private function withLock($dbname, $exclusive, callable $fn){
        $path = $this->getLockPath($dbname);
        if($exclusive && self::$multiLock > 0){
            // A snapshot holds shared locks of several databases: waiting for an exclusive one here could
            // deadlock with another snapshot. withSnapshot() does that work alone first and tries again.
            throw new \RuntimeException("noneDB: database '{$dbname}' needs maintenance before the snapshot", self::SNAPSHOT_RETRY);
        }

        if(isset(self::$lockRegistry[$path])){
            if(!empty(self::$lockRegistry[$path]['lost'])){
                throw new \RuntimeException("noneDB: the lock on database '{$dbname}' was lost (timed out while upgrading)", self::LOCK_FAILED);
            }
            if($exclusive && self::$lockRegistry[$path]['mode'] !== LOCK_EX){
                // flock can't upgrade atomically. On BSD/macOS a failed non-blocking upgrade keeps the shared
                // lock, so two processes upgrading at once would wait for each other until lockTimeout: release
                // first, then wait for the exclusive lock. Another writer may run in between, so the state is
                // read again and whatever it left half-done is recovered (A-4).
                $this->flushPendingGlobals($dbname, $path); // while what they describe still holds
                flock(self::$lockRegistry[$path]['fp'], LOCK_UN);
                $this->fault('upgrade:released', ['db' => $dbname]);
                try {
                    $this->acquireFlock(self::$lockRegistry[$path]['fp'], LOCK_EX, $dbname);
                } catch(\Throwable $e){
                    self::$lockRegistry[$path]['lost'] = true; // never continue reading without a lock
                    throw $e;
                }
                self::$lockRegistry[$path]['mode'] = LOCK_EX;
                $this->loadLockState($dbname, $path);
                $this->prepareDb($dbname, $path);
            }
            self::$lockRegistry[$path]['depth']++;
            try {
                if($exclusive && self::$lockRegistry[$path]['scope'] === null){
                    return $this->runWriteScope($dbname, $path, $fn);
                }
                return $fn();
            } finally {
                self::$lockRegistry[$path]['depth']--;
            }
        }

        $mode = $exclusive ? LOCK_EX : LOCK_SH;
        $fp = $this->lockFile($path, $mode, $dbname);
        self::$lockRegistry[$path] = [
            'fp' => $fp, 'mode' => $mode, 'depth' => 1, 'dbname' => $dbname,
            'gen' => 0, 'epoch' => null, 'flags' => [], 'bumped' => false, 'pending' => [],
            'scope' => null, 'rolledBack' => false, 'sid' => ++self::$sessionCounter, 'globals' => [],
        ];

        $ok = false;
        try {
            $this->loadLockState($dbname, $path);
            $this->prepareDb($dbname, $path);
            $result = $exclusive ? $this->runWriteScope($dbname, $path, $fn) : $fn();
            $ok = true;
            return $result;
        } finally {
            try {
                if(self::$lockRegistry[$path]['mode'] === LOCK_EX){
                    $this->finishWriteSession($dbname, $path, $ok);
                }
                $this->flushPendingGlobals($dbname, $path, $ok);
            } finally {
                $fp = self::$lockRegistry[$path]['fp'];
                unset(self::$lockRegistry[$path]);
                flock($fp, LOCK_UN);
                $this->keepLockHandle($path, $fp);
                // The cache size is looked at only after something new was cached
                if(self::$cacheGrew && empty(self::$lockRegistry)){
                    self::$cacheGrew = false;
                    if(self::$staticCacheEnabled && self::cacheLimitBytes() > 0){
                        $this->evictCaches();
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
            if(empty(self::$lockRegistry[$path]['rolledBack'])){
                $this->checkpointTouchedSegments($dbname, $path);
            }
            // After a rollback (of a deferred job) the journal stays: the next lock holder rebuilds
            if(!empty(self::$lockRegistry[$path]['flags']['j']) && empty(self::$lockRegistry[$path]['rolledBack'])){
                // Data, indexes and meta of every journaled segment are written
                unset(self::$lockRegistry[$path]['flags']['j']);
                $this->saveLockState($path);
            }
        } else {
            // Partial failure: forget in-memory state, the files are the source of truth.
            // A journal entry stays, so the next lock holder rebuilds what may be half-written.
            $this->forgetDirtySpatial($dbname);
            $this->invalidateDbCaches($dbname);
        }
        if(!self::$lockRegistry[$path]['bumped']){
            return; // nothing was written: the generation stays
        }
        if(!$this->fsync){
            self::$lastOperationDurability = ['op' => "write to '{$dbname}'", 'required' => [], 'completed' => [],
                'durable' => false, 'reason' => 'fsync is off'];
        } elseif(isset(self::$lockRegistry[$path]['durability'])){
            $this->finishDurability($path, $dbname);
        }
    }

    /**
     * Run $fn with shared locks on several databases at once (joins, v3.3.0): every read sees the same
     * state of all of them. Locks are taken in one canonical order (by lock file path), so two snapshots
     * never wait for each other in a circle, and no exclusive lock is ever waited for while several are
     * held: work that needs one (recovery, an index repair) is done for each database alone first.
     */
    private function withSnapshot(array $dbnames, callable $fn){
        $names = [];
        foreach($dbnames as $name){
            $name = $this->normalizeDbName($name);
            $lock = $this->getLockPath($name);
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
                $this->withReadLock($name, function() use ($name){
                    foreach($this->listSegments($name) as $segment){
                        $this->readJsonlIndex($name, $segment);
                    }
                });
            }
            try {
                return $this->withSharedLocks($names, $fn);
            } catch(\RuntimeException $e){
                if($e->getCode() !== self::SNAPSHOT_RETRY){
                    throw $e;
                }
                if($this->lockTimeout > 0 && microtime(true) - $started >= $this->lockTimeout){
                    throw new \RuntimeException("noneDB: timed out waiting for a consistent snapshot of " . implode(', ', $names), self::LOCK_FAILED, $e);
                }
                usleep($this->lockRetryDelay);
            }
        }
    }

    private function withSharedLocks(array $names, callable $fn, $i = 0){
        if($i >= count($names)){
            self::$multiLock++;
            try {
                if(self::$faultHook !== null){ $this->fault('snapshot:held', ['dbs' => $names]); }
                return $fn();
            } finally {
                self::$multiLock--;
            }
        }
        return $this->withReadLock($names[$i], function() use ($names, $fn, $i){
            return $this->withSharedLocks($names, $fn, $i + 1);
        });
    }

    /**
     * Run the outermost exclusive call of a database inside a rollback scope (see above), then the jobs
     * deferred until its commit, each in a scope of its own
     */
    private function runWriteScope($dbname, $path, callable $fn){
        if(!empty(self::$pendingRecoveryEvents[$path]) && self::$noWrites === 0){
            // What readers of this process found (they don't write): logged once, deduplicated by id
            $events = self::$pendingRecoveryEvents[$path];
            unset(self::$pendingRecoveryEvents[$path]);
            foreach($events as $id => $event){
                $this->logRecoveryEvent($dbname, $event, $id);
            }
        }
        self::$lockRegistry[$path]['scope'] = ['marks' => [], 'deferred' => [], 'independent' => false];
        try {
            $result = $fn();
        } catch(\Throwable $e){
            $this->rollbackScope($dbname, $path, $e); // rethrows
        }
        $deferred = self::$lockRegistry[$path]['scope']['deferred'];
        self::$lockRegistry[$path]['scope'] = null;
        if(self::$faultHook !== null){ $this->fault('scope:commit', ['db' => $dbname]); }
        foreach($deferred as $job => $work){
            try {
                $this->runWriteScope($dbname, $path, function() use ($dbname, $job, $work){
                    $this->fault('job:' . $job, ['db' => $dbname]);
                    return $this->runIndependent($dbname, $job, $work);
                });
            } catch(\Throwable $e){
                // The operation itself is committed; the job runs again on a later operation
                $this->logRecoveryEvent($dbname, ['action' => 'deferred-failed', 'job' => $job, 'error' => $e->getMessage()]);
                break;
            }
        }
        return $result;
    }

    /**
     * Undo the appends of a failed scope and rethrow (ROLLBACK_FAILED when a file can't be cut back)
     */
    private function rollbackScope($dbname, $path, \Throwable $e){
        $marks = self::$lockRegistry[$path]['scope']['marks'];
        self::$lockRegistry[$path]['scope'] = null;
        self::$lockRegistry[$path]['rolledBack'] = true;
        $failed = [];
        foreach($marks as $file => $mark){
            try {
                $this->fault('rollback:truncate', ['db' => $dbname, 'file' => $file]);
                if(!$this->truncateBack($file, $mark)){
                    $failed[] = $file;
                }
            } catch(\Throwable $t){
                $failed[] = $file;
            }
        }
        $this->forgetDirtySpatial($dbname);
        $this->invalidateDbCaches($dbname);
        if(!empty($failed)){
            self::$lockRegistry[$path]['flags']['j']['*'] = true;
            try {
                $this->saveLockState($path);
            } catch(\Throwable $ignored){
            }
            throw new \RuntimeException("noneDB: a write to database '{$dbname}' failed and could not be undone: outcome unknown, the database is recovered on its next access", self::ROLLBACK_FAILED, $e);
        }
        if(!empty($marks) && $this->isSharded($dbname)){
            try {
                $this->recountMeta($dbname); // keys reserved by the failed write stay unused
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
        $this->noteWrite($file);
        $fp = @fopen($file, 'r+b');
        if($fp === false){
            return false;
        }
        try {
            $stat = fstat($fp);
            if($stat === false || ($ino && ($stat['ino'] !== $ino || $stat['dev'] !== $dev))){
                return false;
            }
            return $stat['size'] <= $size || ftruncate($fp, $size);
        } finally {
            fclose($fp);
            clearstatcache(true, $file);
        }
    }

    /**
     * Record the size a file had before the scope's first append to it
     */
    private function markScopeFile($dbname, $file, $size, $fp){
        $lock = $this->getLockPath($dbname);
        if(isset(self::$lockRegistry[$lock]['scope']) && !isset(self::$lockRegistry[$lock]['scope']['marks'][$file])){
            $stat = fstat($fp);
            self::$lockRegistry[$lock]['scope']['marks'][$file] = [$size, $stat ? $stat['dev'] : 0, $stat ? $stat['ino'] : 0];
        }
    }

    /**
     * Run work that must not be undone by its caller's failure (buffer flush, migration): in place while the
     * scope has no appends, else deferred until the scope committed (once per $job). Returns null if deferred.
     */
    private function runIndependent($dbname, $job, callable $work){
        $path = $this->getLockPath($dbname);
        if(!isset(self::$lockRegistry[$path]['scope'])){
            return $work();
        }
        if(!empty(self::$lockRegistry[$path]['scope']['marks'])){
            if(!isset(self::$lockRegistry[$path]['scope']['deferred'][$job])){
                self::$lockRegistry[$path]['scope']['deferred'][$job] = $work;
                $this->fault('job:deferred', ['db' => $dbname, 'job' => $job]);
            }
            return null;
        }
        if(self::$faultHook !== null){ $this->fault('independent:run', ['db' => $dbname, 'job' => $job]); }
        $was = self::$lockRegistry[$path]['scope']['independent'];
        self::$lockRegistry[$path]['scope']['independent'] = true;
        try {
            $result = $work();
            $this->commitIndependentStep($dbname);
            return $result;
        } finally {
            if(isset(self::$lockRegistry[$path]['scope'])){
                self::$lockRegistry[$path]['scope']['independent'] = $was;
            }
        }
    }

    /**
     * Work that replaces or removes data files (dropDB, compact, migrate) can't be undone by a rollback:
     * inside a write of the same database that appended and hasn't committed (a nested call from the
     * caller's own code) it is refused before it touches any file
     * @throws \LogicException
     */
    private function refuseInUncommittedWrite($dbname, $operation){
        $scope = self::$lockRegistry[$this->getLockPath($dbname)]['scope'] ?? null;
        if($scope !== null && !empty($scope['marks']) && empty($scope['independent'])){
            throw new \LogicException("noneDB: {$operation}() can't run inside a write to database '{$dbname}' that hasn't committed yet (it replaces or deletes data files, which the write's rollback couldn't undo); call it after that write");
        }
    }

    /**
     * Before a file is replaced, renamed or removed: never one that holds appends a rollback may have to cut
     * back (whatever path asks for it)
     * @throws \LogicException
     */
    private function refuseUncommittedFile($file, $operation){
        foreach(self::$lockRegistry as $entry){
            if(isset($entry['scope']['marks'][$file]) && empty($entry['scope']['independent'])){
                throw new \LogicException("noneDB: {$operation} of " . basename($file) . " refused: it holds appends of database '{$entry['dbname']}' that aren't committed yet");
            }
        }
    }

    /**
     * Inside independent work: its appends so far are final (the scope had none before it started)
     */
    private function commitIndependentStep($dbname){
        $path = $this->getLockPath($dbname);
        if(!empty(self::$lockRegistry[$path]['scope']['independent'])){
            self::$lockRegistry[$path]['scope']['marks'] = [];
        }
    }

    private function getRecoveryLogPath($dbname){
        return $this->dbBase($dbname) . '.nonedb.recovery';
    }

    /**
     * Append an entry to the database's recovery log (caller holds the exclusive lock). Best effort: a
     * failure to log never fails the operation. Past 1 MiB the log moves to {log}.1 (replacing it).
     */
    private function logRecoveryEvent($dbname, array $event, $dedupId = null){
        try {
            $path = $this->getRecoveryLogPath($dbname);
            if($dedupId !== null){
                $id = '"id":' . json_encode($dedupId);
                foreach([$path . '.1', $path] as $file){
                    $content = @file_get_contents($file);
                    // the id is followed by the next member or ends the entry
                    if($content !== false && (strpos($content, $id . ',') !== false || strpos($content, $id . '}') !== false)){
                        return; // already logged
                    }
                }
            }
            clearstatcache(true, $path);
            if(@filesize($path) > 1048576){
                $this->fsRename($path, $path . '.1');
            }
            $line = $this->encodeJson(['t' => time()] + $event);
            if($line !== false){
                $this->appendToFile($path, $line . "\n");
            }
        } catch(\Throwable $ignored){
        }
    }

    /**
     * True when mbstring can be used for Unicode case folding (like(), search())
     * @internal used by noneDBQuery; not part of the public API
     */
    public static function hasMbstring(): bool {
        return self::$mbstringFn !== null ? (bool)(self::$mbstringFn)() : function_exists('mb_convert_case');
    }

    /**
     * valueKey() for the query builder
     * @return \Closure
     */
    private function valueKeyFunction(){
        return \Closure::fromCallable([self::class, 'valueKey']);
    }

    /**
     * The filter parameter of find(), count(), sum(), ...: 0, null (all records) and arrays are filters,
     * anything else is invalid (null)
     * @return array|null
     */
    private function normalizeFilter($filter){
        if($filter === null || is_int($filter)){
            return [];
        }
        return is_array($filter) ? $filter : null;
    }

    /**
     * Records matching a filter parameter (see normalizeFilter); false for an invalid filter or a missing
     * database without autoCreateDB. Caller holds the lock.
     * @return array|false
     */
    private function filteredUnlocked($dbname, $filter){
        $filters = $this->normalizeFilter($filter);
        return $filters === null ? false : $this->findUnlocked($dbname, $filters);
    }

    /**
     * Test hook (no effect unless a test installed one)
     */
    private function fault($point, array $context = []){
        if(self::$faultHook !== null){
            (self::$faultHook)($point, $context);
        }
    }

    private static function faultPoint($point, array $context = []){
        if(self::$faultHook !== null){
            (self::$faultHook)($point, $context);
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
            $kept = self::$lockHandles[$path] ?? null;
            unset(self::$lockHandles[$path]);
            if($kept !== null && $kept['pid'] === $pid){
                $fp = $kept['fp'];
            } else {
                if($kept !== null){
                    @fclose($kept['fp']);
                }
                $this->ensureDbDir();
                // "e": close-on-exec. A kept handle must not leak into programs this process starts:
                // an inherited copy would keep a lock alive after this process died holding it.
                $fp = @fopen($path, 'c+be');
                if($fp === false){
                    throw new \RuntimeException("noneDB: cannot open lock file for database '{$dbname}'", self::LOCK_FAILED);
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
                throw new \RuntimeException("noneDB: the lock file of database '{$dbname}' keeps being replaced", self::LOCK_FAILED);
            }
        }
    }

    /**
     * Keep an unlocked lock file handle open for the next operation (the oldest is closed past the limit)
     */
    private function keepLockHandle($path, $fp){
        self::$lockHandles[$path] = ['fp' => $fp, 'pid' => getmypid()];
        if(count(self::$lockHandles) > self::LOCK_HANDLE_LIMIT){
            reset(self::$lockHandles);
            $oldest = key(self::$lockHandles);
            @fclose(self::$lockHandles[$oldest]['fp']);
            unset(self::$lockHandles[$oldest]);
        }
    }

    /**
     * Read the state record (caller holds the lock). If another process wrote the database since this
     * process last looked, all cached state of the database is dropped.
     */
    private function loadLockState($dbname, $path){
        $fp = self::$lockRegistry[$path]['fp'];
        fseek($fp, 0);
        $tokens = explode(' ', trim((string)fread($fp, self::LOCK_STATE_SIZE)));
        $state = (string)array_shift($tokens); // "{generation}:{epoch}"
        $flags = [];
        foreach($tokens as $token){
            if($token === 'v' || $token === 'b'){
                $flags[$token] = true;
            } elseif($token === 'J'){
                $flags['j']['*'] = true;
            } elseif(strncmp($token, 'j=', 2) === 0){
                foreach(explode(',', substr($token, 2)) as $segment){
                    if($segment !== ''){
                        $flags['j'][$segment] = true;
                    }
                }
            }
        }
        // The caches were checked against this exact state: nothing to do (the usual case, one comparison;
        // the epoch is parsed when needed: false = the valid one of the state)
        $seen = $this->generations[$path] ?? null;
        $epoch = false;
        if($seen !== $state){
            $colon = strpos($state, ':');
            $epoch = $colon === false ? null : substr($state, $colon + 1);
            if($epoch !== null && (strlen($epoch) !== 8 || !ctype_xdigit($epoch))){
                $epoch = null;
            }
            $seenEpoch = $seen === null || strpos($seen, ':') === false ? null : substr($seen, strpos($seen, ':') + 1);
            if($seen === null || $epoch === null || $seenEpoch !== $epoch){
                $this->invalidateDbCaches($dbname);
            } else {
                $this->refreshDbCaches($dbname); // same epoch, another generation: the files only grew
            }
            $this->generations[$path] = $epoch === null ? null : $state;
        }
        self::$lockRegistry[$path]['gen'] = (int)$state;
        self::$lockRegistry[$path]['state'] = $state;
        self::$lockRegistry[$path]['epoch'] = $epoch;
        self::$lockRegistry[$path]['flags'] = $flags;
    }

    /**
     * Write the state record of a database held exclusively. $bump: a file is about to be written, so the
     * generation moves (once per session; noteWrite()): even a writer that dies half-way makes other
     * processes check their caches. A record without epoch gets one.
     */
    private function saveLockState($path, $bump = false){
        if($bump && !self::$lockRegistry[$path]['bumped']){
            self::$lockRegistry[$path]['gen']++;
            self::$lockRegistry[$path]['bumped'] = true;
        }
        $epoch = $this->registryEpoch($path);
        if($epoch === null){
            $epoch = self::$lockRegistry[$path]['epoch'] = bin2hex(random_bytes(4));
        }
        $state = self::$lockRegistry[$path]['gen'] . ':' . $epoch;
        $this->generations[$path] = $state;
        $flags = self::$lockRegistry[$path]['flags'];
        if(!empty($flags['v'])){
            $state .= ' v';
        }
        if(!empty($flags['b'])){
            $state .= ' b';
        }
        if(!empty($flags['j'])){
            $segments = ' j=' . implode(',', array_keys($flags['j']));
            $state .= (isset($flags['j']['*']) || strlen($state . $segments) > self::LOCK_STATE_SIZE) ? ' J' : $segments;
        }
        $fp = self::$lockRegistry[$path]['fp'];
        fseek($fp, 0);
        if(fwrite($fp, str_pad($state, self::LOCK_STATE_SIZE)) !== self::LOCK_STATE_SIZE || !fflush($fp)){
            throw new \RuntimeException("noneDB: cannot write the lock file of database '" . self::$lockRegistry[$path]['dbname'] . "'");
        }
        // With fsync on, setting the journal or the buffer flag must reach the disk before the data it
        // protects (clearing them doesn't matter: a power loss drops every cache anyway)
        if($this->fsync && (!empty($flags['j']) || !empty($flags['b']))){
            $synced = $this->syncHandle($fp, $path);
            if(!empty($flags['b'])){
                $this->noteSync('file', $synced);
            }
        }
    }

    /**
     * Change the state flag 'b' (write buffer may hold records); caller holds the exclusive lock
     */
    private function setBufferFlag($dbname, $on){
        $path = $this->getLockPath($dbname);
        if(!isset(self::$lockRegistry[$path]) || !empty(self::$lockRegistry[$path]['flags']['b']) === $on){
            return;
        }
        if($on){
            self::$lockRegistry[$path]['flags']['b'] = true;
        } else {
            unset(self::$lockRegistry[$path]['flags']['b']);
        }
        $this->saveLockState($path);
    }

    /**
     * True when the state record of a locked database is authoritative (see 'v' above)
     */
    private function isVerified($dbname){
        $path = $this->getLockPath($dbname);
        return !empty(self::$lockRegistry[$path]['flags']['v']);
    }

    /**
     * After taking the lock: rebuild what a crashed writer left half-updated, and once per database
     * look for pre-3.2 leftovers. Both need the exclusive lock and are rare.
     */
    private function prepareDb($dbname, $path){
        if(self::$noWrites > 0){
            return; // checkIntegrity(): reports what recovery would do instead of doing it
        }
        $flags = self::$lockRegistry[$path]['flags'];
        if(!empty($flags['v']) && empty($flags['j']) && self::$lockRegistry[$path]['epoch'] !== null){
            return;
        }
        $this->withLock($dbname, true, function() use ($dbname, $path){
            // Re-read under the exclusive lock: another process may have done it meanwhile
            $noEpoch = self::$lockRegistry[$path]['epoch'] === null;
            if(empty(self::$lockRegistry[$path]['flags']['v'])){
                $this->verifyDb($dbname, $path);
            } elseif($noEpoch){
                $this->saveLockState($path); // written by v3.2 or a new lock file: give it an epoch
            }
            if($noEpoch){
                // v3.2 may have used the database: its own index files may be back, and it can leave the
                // shard counters of a database this version wrote behind the data
                $this->forgetIndexConversion($dbname);
                if($this->isSharded($dbname)){
                    $this->recountMeta($dbname);
                }
            }
            if(!empty(self::$lockRegistry[$path]['flags']['j'])){
                $this->recoverPendingIndexes($dbname, $path);
            }
        });
    }

    /**
     * One-time check of a database whose state record isn't verified yet (created before v3.2, or its
     * lock file was deleted): pick up pre-3.2 write buffers and store the index list, so neither has to
     * be looked for again
     */
    private function verifyDb($dbname, $path){
        if($this->hasBuffer($this->getBufferPath($dbname)) || !empty($this->flushingFiles($dbname)) || !empty($this->legacyShardBufferFiles($dbname))){
            self::$lockRegistry[$path]['flags']['b'] = true;
        }
        $manifestPath = $this->getIndexManifestPath($dbname);
        clearstatcache(true, $manifestPath);
        if(!is_file($manifestPath)){
            unset($this->fieldIndexCache[$manifestPath]);
            $manifest = $this->deriveIndexManifest($dbname);
            if(!empty($manifest['fields']) || !empty($manifest['spatial'])){
                $this->saveIndexManifest($dbname, $manifest);
            }
        }
        self::$lockRegistry[$path]['flags']['v'] = true;
        $this->saveLockState($path);
    }

    /**
     * Called before a segment's data changes. Index files of v3.2 the segment still has are converted
     * first (v3.2 would not see the change in them). The journal is needed only for shard meta counters
     * (sharded) and migrations ($always): field and spatial indexes follow the data by themselves.
     */
    private function markIndexesPending($dbname, $shardId, $always = false){
        $path = $this->getLockPath($dbname);
        if(!isset(self::$lockRegistry[$path])){
            return;
        }
        $this->convertSegmentIndexes($dbname, $shardId);
        if(!$always && $shardId === null){
            return;
        }
        $segment = $shardId === null ? 'main' : (string)$shardId;
        if(isset(self::$lockRegistry[$path]['pending'][$segment])){
            return;
        }
        self::$lockRegistry[$path]['pending'][$segment] = true;
        self::$lockRegistry[$path]['flags']['j'][$segment] = true;
        $this->saveLockState($path, true); // the data write follows
    }

    /**
     * Rebuild field and spatial indexes of the segments a crashed writer left half-updated
     * (caller holds the exclusive lock)
     */
    private function recoverPendingIndexes($dbname, $path){
        $pending = self::$lockRegistry[$path]['flags']['j'] ?? [];
        $known = $this->listSegments($dbname);
        $segments = isset($pending['*']) ? $known : array_map(function($segment){
            return $segment === 'main' ? null : (int)$segment;
        }, array_keys($pending));
        $manifest = $this->getIndexManifest($dbname);
        $segments = array_values(array_filter($segments, function($shardId) use ($known){
            return in_array($shardId, $known, true);
        }));
        foreach($segments as $shardId){
            // Key, field and spatial indexes follow the data by themselves (checkpoint + replay)
            $this->readJsonlIndex($dbname, $shardId);
        }
        // A writer that died left its temp files (atomic writes, quarantine)
        $this->removeStaleTemp($this->dbBase($dbname));
        $this->removeStaleTemp($this->dbDir . $this->hashDBName($dbname));
        // A migration that died: its shards (still the old file) or its marker (already sharded)
        $this->discardInterruptedMigration($dbname);
        if($this->isSharded($dbname)){
            // A write may have died between its data and its meta update
            $this->recountMeta($dbname);
            // A migration may have died after switching to shards but before removing the old file
            $legacy = $this->getDataPath($dbname);
            clearstatcache(true, $legacy);
            if(file_exists($legacy)){
                $this->bumpEpoch($dbname);
                $this->fsUnlink($legacy . '.jidx');
                $this->fsUnlink($legacy);
                $this->markFileNotExists($legacy);
                unset($this->indexCache[$legacy . '.jidx'], $this->jsonlFormatCache[$legacy]);
                foreach($manifest['fields'] as $field){
                    $this->deleteFieldIndexFile($dbname, $field, null);
                }
                foreach($manifest['spatial'] as $field){
                    $this->fsUnlink($this->getSpatialIndexPath($dbname, $field, null));
                    $this->fsUnlink($this->getLegacySpatialIndexPath($dbname, $field, null));
                }
            }
        }
        unset(self::$lockRegistry[$path]['flags']['j']);
        $this->saveLockState($path);
    }

    /**
     * flock with timeout (lockTimeout seconds; 0 waits forever)
     * @throws \RuntimeException on timeout
     */
    private function acquireFlock($fp, $mode, $dbname){
        if($this->lockTimeout <= 0){
            if(!flock($fp, $mode)){
                throw new \RuntimeException("noneDB: could not lock database '{$dbname}'", self::LOCK_FAILED);
            }
            return;
        }
        $start = microtime(true);
        while(!flock($fp, $mode | LOCK_NB)){
            if((microtime(true) - $start) >= $this->lockTimeout){
                throw new \RuntimeException("noneDB: timed out after {$this->lockTimeout}s waiting for the lock on database '{$dbname}'", self::LOCK_FAILED);
            }
            usleep($this->lockRetryDelay);
        }
    }

    /**
     * A file is about to be written: the first write of an exclusive session bumps the generation of the
     * database the file belongs to (see saveLockState). A database file written without its database's
     * exclusive lock is a bug: LogicException. Files of no database (dbDir housekeeping) pass.
     * @throws \LogicException
     */
    private function noteWrite($file){
        $file = (string)$file;
        unset($this->dataSizes[$file]); // a size cached for global index checks
        $owner = null;
        foreach(self::$lockRegistry as $path => $entry){
            // Files of a database start with {dbDir}{hash}: the lock path up to the hash
            $prefix = substr($path, 0, strrpos($path, '/') + 21);
            if(strncmp($file, $prefix, strlen($prefix)) === 0 && strpos('-._', substr($file, strlen($prefix), 1)) !== false){
                $owner = $path;
                break;
            }
        }
        if($owner !== null){
            if(substr($file, -7) === '.nonedb'){
                // A data file: global index lookups check its shard's newer lines (globalCheckShards)
                $writes = &$this->segWrites[$owner];
                $writes['seq'] = ($writes['seq'] ?? 0) + 1;
                $writes['paths'][$file] = $writes['seq'];
                unset($writes);
            }
            if(self::$lockRegistry[$owner]['mode'] !== LOCK_EX){
                throw new \LogicException("noneDB: write to " . basename($file) . " under a shared lock of database '" . self::$lockRegistry[$owner]['dbname'] . "'");
            }
            if(!self::$lockRegistry[$owner]['bumped']){
                $this->saveLockState($owner, true);
            }
        } elseif(preg_match('/^[0-9a-f]{20}[-._]/', basename($file)) === 1){
            throw new \LogicException("noneDB: write to " . basename($file) . " without the lock of its database");
        }
        if(!empty($this->unsavedHashes)){
            $unsaved = $this->unsavedHashes;
            $this->unsavedHashes = [];
            foreach($unsaved as $dbname => $hash){
                $this->savePersistentHashCacheEntry((string)$dbname, $hash);
            }
        }
    }

    /**
     * Drop every cached entry that belongs to a database (index, meta, field/spatial indexes, file-exists)
     */
    private function invalidateDbCaches($dbname){
        $prefix = $this->dbDir . $this->hashDBName($dbname);
        $len = strlen($prefix);
        $caches = [
            &$this->indexCache, &$this->verifiedIndexes, &$this->shardedCache, &$this->metaCache, &$this->metaCacheTime,
            &$this->jsonlFormatCache, &$this->fieldIndexCache, &$this->spatialIndexCache,
            &$this->globalSpatialIndexCache, &$this->dataSizes, &$this->dataEpochs, &$this->convertedSegments, &$this->shardFilesUpTo,
            &self::$secondaryChecked, &self::$tailCache,
        ];
        foreach($caches as &$cache){
            foreach(array_keys($cache) as $key){
                if(strncmp((string)$key, $prefix, $len) === 0){
                    unset($cache[$key]);
                }
            }
        }
        unset($cache);
        foreach(array_keys(self::$staticFileExistsCache) as $key){
            if(strncmp((string)$key, $prefix, $len) === 0){
                unset(self::$staticFileExistsCache[$key]);
            }
        }
        $lockPath = $this->getLockPath($dbname);
        unset($this->globalSkip[$lockPath], $this->segWrites[$lockPath]);
        foreach(array_keys(self::$bufferCounts) as $key){
            if(strncmp((string)$key, $prefix, $len) === 0){
                unset(self::$bufferCounts[$key], self::$bufferEnds[$key]);
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
    private function refreshDbCaches($dbname){
        $prefix = $this->dbDir . $this->hashDBName($dbname);
        $len = strlen($prefix);
        // Kept (they describe data bytes that didn't change, the new lines are replayed when used): key
        // indexes, field indexes and R-trees, what was checked against the data, parsed tails, epochs
        $keep = [];
        foreach(['indexCache' => &$this->indexCache, 'fieldIndexCache' => &$this->fieldIndexCache,
                 'spatialIndexCache' => &$this->spatialIndexCache, 'dataEpochs' => &$this->dataEpochs,
                 'secondaryChecked' => &self::$secondaryChecked, 'tailCache' => &self::$tailCache] as $name => &$cache){
            foreach($cache as $key => $value){
                if(strncmp((string)$key, $prefix, $len) === 0
                    && ($name !== 'fieldIndexCache' && $name !== 'spatialIndexCache' || (is_array($value) && isset($value['kind']) && empty($value['legacy'])))){
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
            self::$secondaryChecked[$key] = $value;
        }
        foreach($keep['tailCache'] ?? [] as $key => $value){
            self::$tailCache[$key] = $value;
        }
    }

    /**
     * Epoch of a locked database's state record (null: none or invalid)
     */
    private function registryEpoch($path){
        $epoch = self::$lockRegistry[$path]['epoch'] ?? null;
        if($epoch === false){
            $state = self::$lockRegistry[$path]['state'];
            $epoch = substr($state, strpos($state, ':') + 1); // a state with a valid epoch (see loadLockState)
            self::$lockRegistry[$path]['epoch'] = $epoch;
        }
        return $epoch;
    }

    /**
     * A data file of the database is about to be replaced or removed (compaction, migration, drop): new
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
        $pending = self::$lockRegistry[$path]['globals'] ?? [];
        if(empty($pending)){
            return;
        }
        self::$lockRegistry[$path]['globals'] = [];
        $epoch = $this->registryEpoch($path);
        foreach($pending as $file => [$kind, $field, $deferredAt]){
            $global = $kind === 'gfx' ? ($this->fieldIndexCache[$file] ?? null) : ($this->globalSpatialIndexCache[$file] ?? null);
            if(!$ok || !empty(self::$lockRegistry[$path]['rolledBack']) || $deferredAt !== $epoch || $global === null){
                unset($this->fieldIndexCache[$file], $this->globalSpatialIndexCache[$file]);
                continue;
            }
            try {
                if($kind === 'gfx'){
                    $this->writeGlobalFieldIndex($dbname, $field, $global);
                } else {
                    $this->writeGlobalSpatialIndex($dbname, $field, $global);
                }
            } catch(\Throwable $e){
                unset($this->fieldIndexCache[$file], $this->globalSpatialIndexCache[$file]);
                if($e->getCode() === self::LOCK_FAILED){
                    throw $e;
                }
                // never needed for correctness: a shard without a fitting entry is never skipped
            }
        }
    }

    private function bumpEpoch($dbname){
        $path = $this->getLockPath($dbname);
        if(!isset(self::$lockRegistry[$path]) || self::$lockRegistry[$path]['mode'] !== LOCK_EX){
            throw new \LogicException("noneDB: new epoch of database '{$dbname}' without its exclusive lock");
        }
        $this->flushPendingGlobals($dbname, $path); // under the epoch their entries belong to
        self::$lockRegistry[$path]['epoch'] = bin2hex(random_bytes(4));
        $this->saveLockState($path, true);
        unset($this->globalSkip[$path], $this->segWrites[$path]);
        // What this process checked against the data files no longer holds for the one being replaced
        $prefix = $this->dbDir . $this->hashDBName($dbname);
        foreach([&$this->dataSizes, &$this->dataEpochs, &self::$secondaryChecked, &self::$tailCache] as &$cache){
            foreach(array_keys($cache) as $key){
                if(strncmp((string)$key, $prefix, strlen($prefix)) === 0){
                    unset($cache[$key]);
                }
            }
        }
        unset($cache);
    }

    // ==========================================
    // ATOMIC FILE OPERATIONS
    // ==========================================

    /**
     * Encode JSON, returning false instead of producing invalid output
     * @return string|false
     */
    private function encodeJson($data, $prettyPrint = false, $extraFlags = 0){
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $extraFlags;
        if($prettyPrint){
            $flags |= JSON_PRETTY_PRINT;
        }
        // json_encode() writes floats with serialize_precision digits: anything but -1 (the default,
        // shortest exact form) would store rounded values (0.30000000000000004 as 0.3 at 10).
        // Loops encoding many records run inside withExactFloats(): no ini_get()/finally per record.
        if(self::$exactFloats || ini_get('serialize_precision') === '-1'){
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
    private function withExactFloats(callable $fn){
        if(self::$exactFloats){
            return $fn();
        }
        $precision = ini_get('serialize_precision');
        if($precision !== '-1'){
            ini_set('serialize_precision', '-1');
        }
        self::$exactFloats = true;
        try {
            return $fn();
        } finally {
            self::$exactFloats = false;
            if($precision !== '-1'){
                ini_set('serialize_precision', (string)$precision);
            }
        }
    }

    /**
     * Encode one JSONL record line (without the trailing newline)
     * @throws \InvalidArgumentException when the record can't be represented as JSON (invalid UTF-8, NAN, INF, ...)
     */
    private function encodeRecord(array $record){
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
     * @param string|bool $mode How it must survive a power loss:
     *   'derived' (false): indexes, rebuilt from the data if lost; synced when the fsync setting is on
     *   'durable': meta, index list, info; synced when the fsync setting is on, needed by the operation
     *   'replace' (true): a data file replaced (compaction, migration); synced whenever PHP can
     * @return bool
     */
    private function writeFileAtomic($path, $content, $mode = 'derived', $note = true){
        $mode = $mode === true ? 'replace' : ($mode === false ? 'derived' : $mode);
        if($mode === 'replace'){
            $this->refuseUncommittedFile($path, 'replacement');
        }
        if($note){
            $this->noteWrite($path);
        }
        $sync = function_exists('fsync') && ($this->fsync || $mode === 'replace');
        $dir = dirname($path);
        if(!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)){
            return false;
        }
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if($sync){
            $fp = @fopen($tmp, 'xb');
            $ok = $fp !== false;
            for($done = 0, $length = strlen($content); $ok && $done < $length; $done += $n){
                $n = fwrite($fp, $done === 0 ? $content : substr($content, $done));
                $ok = $n !== false && $n > 0;
            }
            if($fp !== false){
                $synced = $ok && fflush($fp) ? $this->syncHandle($fp, $path) : false;
                fclose($fp);
                if($mode !== 'derived'){
                    $this->noteSync('file', $synced);
                }
                $ok = $ok && $synced !== false;
            }
            if(!$ok){
                @unlink($tmp);
                return false;
            }
        } else {
            $written = @file_put_contents($tmp, $content);
            if($written === false || $written !== strlen($content)){
                @unlink($tmp);
                return false;
            }
            if($mode !== 'derived'){
                $this->noteSync('file', null);
            }
        }
        if(!$this->renameWithRetry($tmp, $path)){
            @unlink($tmp);
            return false;
        }
        clearstatcache(true, $path);
        if($sync){
            $dirSynced = $this->syncDirectory($dir);
            if($mode !== 'derived'){
                $this->noteSync('dir', $dirSynced); // a failed directory sync is reported, not fatal
            }
        } elseif($mode !== 'derived'){
            $this->noteSync('dir', null);
        }
        return true;
    }

    /**
     * Write a file derived from the data (a checkpoint) atomically without moving the generation: its
     * content is the same whoever writes it, so a reader may write it under the shared lock
     */
    private function writeDerivedFile($path, $content){
        return $this->writeFileAtomic($path, $content, 'derived', false);
    }

    // File system writes (v3.3.0, S3): every write to a database file goes through writeFileAtomic(),
    // appendToFile() or the helpers below. Each calls noteWrite() first: the generation moves before the
    // file changes, so other processes drop their caches even if this one dies half-way, and a write
    // without the database's exclusive lock is a LogicException. SourceWriteGuardTest fails on a raw
    // write call anywhere else.

    private function fsUnlink($path){
        clearstatcache(true, $path);
        if(!file_exists($path) && !is_link($path)){
            return false; // nothing to remove: no write
        }
        $this->refuseUncommittedFile($path, 'removal');
        $this->noteWrite($path);
        $ok = @unlink($path);
        clearstatcache(true, $path);
        return $ok;
    }

    private function fsRename($from, $to){
        $this->refuseUncommittedFile($from, 'rename');
        $this->refuseUncommittedFile($to, 'replacement');
        $this->noteWrite($from);
        $this->noteWrite($to);
        $ok = $this->renameWithRetry($from, $to);
        clearstatcache(true, $from);
        clearstatcache(true, $to);
        return $ok;
    }

    private function fsTouch($path){
        $this->noteWrite($path);
        $ok = @touch($path);
        clearstatcache(true, $path);
        return $ok;
    }

    /** Cut a file to $size bytes */
    private function fsTruncate($path, $size){
        $this->noteWrite($path);
        $fp = @fopen($path, 'r+b');
        if($fp === false){
            return false;
        }
        $ok = ftruncate($fp, $size);
        fclose($fp);
        clearstatcache(true, $path);
        return $ok;
    }

    /** Write a whole small file in place (not atomic: for content that never matters to the data) */
    private function fsPutContents($path, $content){
        $this->noteWrite($path);
        return @file_put_contents($path, $content) === strlen($content);
    }

    /**
     * Append bytes to a file. Returns the offset the bytes start at, or false.
     * A partial write is rolled back so the file never ends in a torn line.
     * @param string|null $dbname Database whose rollback scope records the append (data and buffer files)
     * @param int|null $expected Size the caller's index says the file has: anything else writes nothing
     *                           and sets $this->appendMismatch
     * @return int|false
     */
    private function appendToFile($path, $bytes, $dbname = null, $expected = null, $segment = null){
        $this->noteWrite($path);
        $this->appendMismatch = false;
        $this->appendSyncFailed = false;
        $fp = @fopen($path, 'c+b');
        if($fp === false){
            return false;
        }
        try {
            if(fseek($fp, 0, SEEK_END) !== 0){
                return false;
            }
            $offset = ftell($fp);
            if($offset === false){
                return false;
            }
            if($expected !== null && $offset !== (int)$expected){
                // The file changed behind the cached index. An unterminated tail only (an append that
                // never completed): quarantined and cut, then this append goes where the index expects it.
                // Anything else: nothing is written, the caller drops its index (rebuilt on next access).
                if($dbname === null || $offset < (int)$expected || $this->lastNewlineIn($path, (int)$expected, $offset) >= 0){
                    $this->appendMismatch = true;
                    return false;
                }
                $this->quarantineAndCut($dbname, $segment, $path, (int)$expected, $offset);
                fseek($fp, 0, SEEK_END);
                $offset = ftell($fp);
                if($offset !== (int)$expected){
                    $this->appendMismatch = true;
                    return false;
                }
            }
            if($dbname !== null){
                $this->markScopeFile($dbname, $path, $offset, $fp);
            }
            $length = strlen($bytes);
            $done = 0;
            while($done < $length){
                $n = fwrite($fp, $done === 0 ? $bytes : substr($bytes, $done));
                if($n === false || $n === 0){
                    $this->undoAppend($fp, $offset, $path);
                    return false;
                }
                $done += $n;
            }
            // Written bytes the stream couldn't hand to the OS (disk full, I/O error): the append failed
            if(!(self::$flushFn !== null ? (self::$flushFn)($fp, $path) : fflush($fp))){
                $this->undoAppend($fp, $offset, $path);
                return false;
            }
            if($dbname !== null){
                // Data and buffer: synced with fsync on (a failed sync undoes this append), the directory
                // too when the append created the file
                if(!$this->syncForWrite($fp, $path)){
                    $this->appendSyncFailed = true;
                    $this->undoAppend($fp, $offset, $path);
                    return false;
                }
                if($offset === 0){
                    $this->syncForWrite(null, dirname($path), 'dir');
                }
                if(self::$faultHook !== null){ $this->fault('append:after', ['db' => $dbname, 'path' => $path]); }
            }
            return $offset;
        } finally {
            fclose($fp);
            clearstatcache(true, $path);
        }
    }

    /**
     * Cut a failed append back. If that fails too, the bytes may still be there: an exception, so the
     * write's rollback scope tries again (ROLLBACK_FAILED when it can't either), never a plain failure
     * that would leave them to be read as a record
     * @throws \RuntimeException
     */
    private function undoAppend($fp, $offset, $path){
        try {
            if(self::$faultHook !== null){ $this->fault('append:undo', ['path' => $path]); }
            $ok = ftruncate($fp, $offset);
        } catch(\Throwable $e){
            $ok = false;
        }
        if(!$ok){
            throw new \RuntimeException("noneDB: an append to " . basename($path) . " failed and could not be cut back");
        }
    }

    /**
     * Read and decode a JSON file
     *
     * @param string $path File path
     * @param mixed $default Value returned when the file doesn't exist or is empty
     * @return mixed Decoded data, $default, or null when the file is corrupt
     */
    private function atomicRead($path, $default = null){
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
    private function atomicWrite($path, $data, $prettyPrint = false, $mode = 'derived'){
        $json = $this->encodeJson($data, $prettyPrint);
        if($json === false){
            return false;
        }
        return $this->writeFileAtomic($path, $json, $mode);
    }

    // ==========================================
    // STORAGE CORE (v3.2.0)
    // ==========================================
    //
    // A database is one "segment" when it is not sharded ({base}.nonedb + {base}.nonedb.jidx) or one
    // segment per shard ({base}_sN.nonedb + .jidx) listed in {base}.nonedb.meta. Segment ids are null
    // (non-sharded) or the shard id. Every write runs under the database's exclusive lock (withLock),
    // reads under a shared lock, so index read-modify-write cycles are never interleaved.
    //
    // Data files are append-only JSONL. Every record line carries its "key"; deletions append a
    // tombstone line {"__nonedb_deleted":key}. The .jidx index maps key => [offset, length] and stores
    // "s", the data size it covers. The index is used only while the data file ends exactly at "s", or
    // continues after it with an unterminated tail (a crashed append, never committed: readers ignore it,
    // the next writer moves it to a quarantine file and cuts it off). Anything else (complete lines the
    // index doesn't know, a shorter file, a missing or corrupt index) rebuilds the index from the data.

    /**
     * Files of dbDir whose path starts with $prefix and whose remaining name matches $regex (if given),
     * sorted. Not glob(): a dbDir containing [ ] * ? is a pattern to glob() and matches nothing.
     * @return string[]
     */
    private function listFilesWithPrefix($prefix, $regex = null){
        self::$directoryListings++;
        $dir = $this->dbDir;
        if(strncmp($prefix, $dir, strlen($dir)) !== 0){
            return [];
        }
        $start = substr($prefix, strlen($dir));
        $length = strlen($start);
        $files = [];
        foreach(@scandir($dir) ?: [] as $file){
            if(strncmp($file, $start, $length) === 0 && ($regex === null || preg_match($regex, substr($file, $length)) === 1)){
                $files[] = $dir . $file;
            }
        }
        return $files;
    }

    /**
     * Every file of a database except its lock file: {hash}-{name}.*, {hash}-{name}_sN.*, and the files
     * named after the hash only ({hash}.nonedb.* / {hash}_sN.nonedb.*: spatial indexes, quarantine)
     * @return string[]
     */
    private function listDbFiles($dbname){
        $base = $this->dbBase($dbname);
        $hashBase = $this->dbDir . $this->hashDBName($dbname);
        $files = array_merge(
            $this->listFilesWithPrefix($base . '.nonedb'),
            $this->listFilesWithPrefix($base . '_s', '/^\d+\.nonedb/'),
            $this->listFilesWithPrefix($hashBase . '.nonedb.'),
            $this->listFilesWithPrefix($hashBase . '_s', '/^\d+\.nonedb\./')
        );
        $lock = $base . '.nonedb.lock';
        return array_values(array_filter(array_unique($files), function($file) use ($lock){
            return $file !== $lock;
        }));
    }

    /**
     * Get the file path for a specific shard
     * @param string $dbname
     * @param int $shardId
     * @return string
     */
    private function getShardPath($dbname, $shardId){
        return $this->dbBase($dbname) . "_s" . $shardId . ".nonedb";
    }

    /**
     * Get the meta file path for a database
     * @param string $dbname
     * @return string
     */
    private function getMetaPath($dbname){
        return $this->dbBase($dbname) . ".nonedb.meta";
    }

    /**
     * Data file of a segment (null = non-sharded database)
     */
    private function segDataPath($dbname, $shardId){
        return $shardId === null ? $this->getDataPath($dbname) : $this->getShardPath($dbname, $shardId);
    }

    /**
     * Check if a database is sharded
     * @param string $dbname
     * @return bool
     */
    private function isSharded($dbname){
        $metaPath = $this->getMetaPath($dbname);
        if(isset($this->shardedCache[$metaPath])){
            return $this->shardedCache[$metaPath];
        }

        clearstatcache(true, $metaPath);
        $result = file_exists($metaPath);
        $this->shardedCache[$metaPath] = $result;
        return $result;
    }

    /**
     * True when the database has data (non-sharded file or sharded meta)
     */
    private function dbExists($dbname){
        return $this->cachedFileExists($this->getDataPath($dbname)) || $this->isSharded($dbname);
    }

    /**
     * Read shard metadata
     * @param string $dbname
     * @return array|null
     */
    private function readMeta($dbname){
        $meta = $this->atomicRead($this->getMetaPath($dbname), null);
        return is_array($meta) && isset($meta['shards']) && is_array($meta['shards']) ? $meta : null;
    }

    /**
     * Get cached meta data. The cache is dropped whenever another process writes the
     * database (generation check in withLock), so no TTL is needed.
     * @param string $dbname
     * @param bool $forceRefresh Force refresh from disk
     * @return array|null
     */
    private function getCachedMeta($dbname, $forceRefresh = false){
        $metaPath = $this->getMetaPath($dbname);

        self::$cacheTouch[$metaPath] = ++self::$cacheTick;
        if(!$forceRefresh && isset($this->metaCache[$metaPath])){
            return $this->metaCache[$metaPath];
        }

        $meta = $this->readMeta($dbname);
        if($meta === null){
            clearstatcache(true, $metaPath);
            if(is_file($metaPath)){
                // There but unreadable (e.g. zero-length after a power loss): rebuilt from the shards.
                // checkIntegrity() writes nothing: it works with a meta derived in memory, not kept
                if(self::$noWrites > 0){
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
            self::$cacheGrew = true;
            return $meta;
        }
        unset($this->metaCache[$metaPath]);
        return null;
    }

    /**
     * Rebuild an unreadable meta file from the shard files (exclusive lock): the shard size is derived from
     * the keys each shard holds (the configured size when it fits), nextKey from the highest key ever used.
     * A missing meta is never "recovered": shard files without one are leftovers (see setOrphanShardsAside).
     * @return array|null
     * @throws \RuntimeException when no shard size fits the keys found
     */
    private function recoverMeta($dbname){
        return $this->withLock($dbname, true, function() use ($dbname){
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
            $this->logRecoveryEvent($dbname, ['action' => 'meta-rebuilt', 'shards' => count($meta['shards']), 'shardSize' => $meta['shardSize']]);
            return $this->getCachedMeta($dbname);
        });
    }

    /**
     * A shard meta derived from the shard files alone (reads only; counters 0, recountMeta() fills them)
     * @return array
     * @throws \RuntimeException when no shard size fits the keys found
     */
    private function deriveShardMeta($dbname){
        $base = $this->dbBase($dbname);
        $ranges = [];
        $recorded = []; // shard sizes the shard checkpoints recorded ("z", v3.3)
        foreach($this->listFilesWithPrefix($base . '_s', '/^\d+\.nonedb$/') as $file){
            $shardId = (int)substr($file, strlen($base) + 2);
            $checkpoint = $this->atomicRead($file . '.jidx', null);
            if(is_array($checkpoint) && isset($checkpoint['z']) && is_int($checkpoint['z']) && $checkpoint['z'] > 0){
                $recorded[$checkpoint['z']] = true;
            }
            $min = null;
            $max = null;
            $fp = @fopen($file, 'rb');
            while($fp !== false && ($line = fgets($fp)) !== false){
                if(substr($line, -1) === "\n" && ($entry = $this->classifyLine(substr($line, 0, -1), 0, null)) !== null){
                    $min = $min === null ? $entry[1] : min($min, $entry[1]);
                    $max = $max === null ? $entry[1] : max($max, $entry[1]);
                }
            }
            if($fp !== false){
                fclose($fp);
            }
            $ranges[$shardId] = [$min, $max];
        }
        ksort($ranges);
        // i * size <= min key of shard i, max key of shard i < (i + 1) * size
        $low = 1;
        $high = PHP_INT_MAX;
        $nextKey = 0;
        foreach($ranges as $shardId => $range){
            if($range[0] === null){
                continue;
            }
            if($shardId > 0){
                $high = min($high, intdiv($range[0], $shardId));
            }
            $low = max($low, intdiv($range[1], $shardId + 1) + 1);
            $nextKey = max($nextKey, $range[1] + 1);
        }
        if($low > $high){
            throw new \RuntimeException("noneDB: metadata of sharded database '{$dbname}' is unreadable and its shard size can't be derived; restore it, or call rebuildIndex() after writing a meta file with the right shardSize");
        }
        // The size a shard checkpoint recorded, else the configured one, if it fits every shard's keys
        $size = count($recorded) === 1 && key($recorded) >= $low && key($recorded) <= $high ? key($recorded)
            : ($this->shardSize >= $low && $this->shardSize <= $high ? $this->shardSize : $low);
        $meta = ['version' => 1, 'shardSize' => $size, 'totalRecords' => 0, 'deletedCount' => 0, 'nextKey' => $nextKey, 'shards' => []];
        foreach(array_keys($ranges) as $shardId){
            $meta['shards'][] = ['id' => $shardId, 'file' => '_s' . $shardId, 'count' => 0, 'deleted' => 0];
        }
        return $meta;
    }

    /**
     * Write shard metadata (caller holds the exclusive lock)
     * @param string $dbname
     * @param array $meta
     * @return bool
     * @throws \RuntimeException when the meta file can't be written
     */
    private function writeMeta($dbname, $meta, $sync = false){
        $path = $this->getMetaPath($dbname);
        if(!$this->atomicWrite($path, $meta, true, $sync ? 'replace' : 'durable')){
            throw new \RuntimeException("noneDB: failed to write metadata of database '{$dbname}'");
        }
        $this->metaCache[$path] = $meta;
        $this->shardedCache[$path] = true;
        $this->markFileExists($path);
        return true;
    }

    /**
     * Records per shard of a database (existing sharded databases keep the size stored in their meta)
     */
    private function shardSizeOf($dbname){
        $meta = $this->isSharded($dbname) ? $this->getCachedMeta($dbname) : null;
        if($meta !== null && isset($meta['shardSize']) && (int)$meta['shardSize'] > 0){
            return (int)$meta['shardSize'];
        }
        return $this->shardSize;
    }

    /**
     * Segment ids of a database: [null] when not sharded, the shard ids otherwise, [] if it doesn't exist
     */
    private function listSegments($dbname){
        if($this->isSharded($dbname)){
            $meta = $this->getCachedMeta($dbname);
            if($meta === null){
                throw new \RuntimeException("noneDB: metadata of sharded database '{$dbname}' is unreadable");
            }
            return array_map('intval', array_column($meta['shards'], 'id'));
        }
        return $this->cachedFileExists($this->getDataPath($dbname)) ? [null] : [];
    }

    /**
     * Segment that holds a key, or false if that shard doesn't exist
     * @return int|null|false
     */
    private function segmentForKey($dbname, $key){
        if(!$this->isSharded($dbname)){
            return null;
        }
        $shardId = intdiv($key, $this->shardSizeOf($dbname));
        return in_array($shardId, $this->listSegments($dbname), true) ? $shardId : false;
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
    private function getJsonlIndexPath($dbname, $shardId = null){
        return $this->segDataPath($dbname, $shardId) . ".jidx";
    }

    // Segment index (v3.3.0): checkpoint + replay. The data file is a log; the .jidx file is a checkpoint
    // of the index at byte offset "s" of it:
    //   {"v":4,"format":"jsonl","created":T,"updated":T,"n":next key,"d":garbage lines,"t":tombstone lines,
    //    "s":S,"h":"crc32b of the up to 256 bytes before S","e":"segment epoch","z":shard size,"sid":shard id,
    //    "p":{key:offset},"l":{key:length}}
    // Loading reads the checkpoint and replays the complete lines after S (an unterminated tail is never
    // part of it, readers never cut it). Writes append to the data and update the cached index only; a
    // write session writes a new checkpoint of a segment it changed once its tail reached 64 lines plus
    // 1/16 of the live records, or 1 MiB (checkpointTouchedSegments). A checkpoint counts only for its data
    // file: S within it, at a line end, h matching; a data file is replaced only after its checkpoint was
    // deleted. No "o" key on purpose: v3.2 then knows no index and rebuilds it from the data (downgrade).
    // A v3 index (v3.2) is read like a checkpoint and deleted before the first append (v3.2 would trust its
    // stale "n"). "e" is the segment's data epoch for the secondary indexes: new when the data file is
    // replaced or the index rebuilt without a checkpoint, assigned only under the exclusive lock.
    // Runtime keys of a cached index (never written): "ck" = S of the checkpoint on disk (-1: none),
    // "tl" = lines after it, "v3" = the file on disk is a v3 index.

    private const CHECKPOINT_LINES = 64;
    private const CHECKPOINT_BYTES = 1048576;

    private function newJsonlIndex(){
        return ['v' => 4, 'format' => 'jsonl', 'created' => time(), 'n' => 0, 'd' => 0, 't' => 0, 's' => 0,
            'e' => null, 'p' => [], 'l' => [], 'ck' => -1, 'tl' => 0];
    }

    /**
     * A segment's key index (offset and length of every live record), from the cache, or loaded from its
     * checkpoint and the data after it
     * @param string $dbname
     * @param int|null $shardId
     * @return array|null Null when the segment doesn't exist
     */
    private function readJsonlIndex($dbname, $shardId = null){
        $dataPath = $this->segDataPath($dbname, $shardId);
        if(!$this->cachedFileExists($dataPath)){
            return null;
        }
        $path = $dataPath . '.jidx';
        if(isset($this->indexCache[$path])){
            self::$cacheTouch[$path] = ++self::$cacheTick;
            if(isset($this->verifiedIndexes[$path])){
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
                return $this->indexCache[$path];
            }
        }
        return $this->loadSegmentIndex($dbname, $shardId);
    }

    /**
     * Load a segment index: its checkpoint (or a v3.2 index) when it fits the data file, then the lines
     * after it. A checkpoint that doesn't fit (the data changed behind it) rebuilds the index and the
     * segment's secondary indexes under the exclusive lock; a missing one only replays the whole file.
     * @return array|null
     */
    private function loadSegmentIndex($dbname, $shardId){
        self::$indexStats['loads']++;
        $dataPath = $this->segDataPath($dbname, $shardId);
        $path = $dataPath . '.jidx';
        clearstatcache(true, $dataPath);
        if(!is_file($dataPath)){
            if(file_exists($dataPath)){
                throw new \RuntimeException("noneDB: data file of database '{$dbname}' is not a regular file: " . basename($dataPath));
            }
            $this->markFileNotExists($dataPath);
            return null;
        }
        $size = (int)filesize($dataPath);
        $checkpoint = $this->atomicRead($path, null);
        $index = null;
        $oldMap = null;
        if(is_array($checkpoint) && isset($checkpoint['p'], $checkpoint['l'], $checkpoint['s']) && is_array($checkpoint['p']) && is_array($checkpoint['l'])){
            if(!$this->checkpointFits($dataPath, (int)$checkpoint['s'], $size, $checkpoint['h'] ?? null)){
                return $this->repairSegmentIndex($dbname, $shardId, true);
            }
            $index = $checkpoint;
            $index['ck'] = (int)$checkpoint['s'];
            $index['tl'] = 0;
            $index['e'] = isset($checkpoint['e']) && is_string($checkpoint['e']) ? $checkpoint['e'] : null;
        } elseif(is_array($checkpoint) && isset($checkpoint['o']) && is_array($checkpoint['o'])){
            if(isset($checkpoint['s'])){
                // v3.2 index: used when it ends at a line end of this file with no complete line after it.
                // A complete line after it: a v3.2 writer died between its append and its index write.
                $covered = (int)$checkpoint['s'];
                if($covered > $size || $this->segmentCrc($dataPath, $covered) === null
                    || ($covered < $size && $this->lastNewlineIn($dataPath, $covered, $size) >= 0)){
                    return $this->repairSegmentIndex($dbname, $shardId, true);
                }
                $index = $this->indexFromV3($checkpoint);
            } else {
                $oldMap = $checkpoint; // before v3.2: no "s", rebuilt from the data below
            }
        }
        if($index === null){
            if(!$this->isJsonlFormat($dataPath)){
                return $this->repairSegmentIndex($dbname, $shardId); // a pre-3.0 file: converted
            }
            $index = $this->newJsonlIndex();
            if(is_array($checkpoint)){
                $index['created'] = $checkpoint['created'] ?? $index['created'];
                if($shardId === null && isset($checkpoint['n'])){
                    $index['n'] = (int)$checkpoint['n']; // keys of deleted records are never handed out again
                }
            }
        }
        $this->replaySegment($dbname, $shardId, $index, $size, $oldMap);
        $this->indexCache[$path] = $index;
        $this->verifiedIndexes[$path] = true;
        self::$cacheTouch[$path] = ++self::$cacheTick;
        self::$cacheGrew = true;
        if($index['ck'] < 0){
            // No checkpoint to continue (missing, empty): the next write session of this process writes one
            self::$uncheckpointed[$this->getLockPath($dbname)][$shardId === null ? 'main' : $shardId] = $shardId;
        }
        $this->maybeReaderCheckpoint($dbname, $shardId);
        return $this->indexCache[$path];
    }

    /**
     * A v3 index (key => [offset, length]) as a loaded checkpoint
     */
    private function indexFromV3(array $old){
        $index = $this->newJsonlIndex();
        foreach($old['o'] as $key => $location){
            if(is_array($location) && count($location) === 2){
                $index['p'][(int)$key] = (int)$location[0];
                $index['l'][(int)$key] = (int)$location[1];
            }
        }
        $index['created'] = $old['created'] ?? $index['created'];
        $index['n'] = (int)($old['n'] ?? 0);
        $index['d'] = (int)($old['d'] ?? 0);
        $index['t'] = (int)($old['t'] ?? 0);
        $index['s'] = (int)$old['s'];
        $index['ck'] = (int)$old['s'];
        $index['v3'] = true;
        return $index;
    }

    /**
     * True when a checkpoint covering $covered bytes belongs to this data file: within it, at a line end,
     * and the bytes before $covered have the checksum it recorded
     */
    private function checkpointFits($dataPath, $covered, $size, $crc){
        if($covered < 0 || $covered > $size || !is_string($crc)){
            return false;
        }
        return $this->segmentCrc($dataPath, $covered) === $crc;
    }

    /**
     * crc32b of the up to 256 bytes before $offset of a data file; null when $offset is not a line end
     */
    private function segmentCrc($dataPath, $offset){
        if($offset === 0){
            return hash('crc32b', '');
        }
        $fp = @fopen($dataPath, 'rb');
        if($fp === false){
            return null;
        }
        $start = max(0, $offset - 256);
        fseek($fp, $start);
        $bytes = (string)fread($fp, $offset - $start);
        fclose($fp);
        return strlen($bytes) === $offset - $start && substr($bytes, -1) === "\n" ? hash('crc32b', $bytes) : null;
    }

    /**
     * Apply the complete lines of bytes [s, $size) of a segment's data file to its index: the last line of
     * a key wins, a tombstone deletes. An unterminated tail stays outside ("s" ends before it; the next
     * writer quarantines and cuts it). A line that doesn't decode (glued to a torn tail by v3.2) is
     * salvaged when a record or tombstone can be found at its end, and reported either way.
     * @param array|null $oldMap an older index of the segment (any version), used to salvage glued lines
     * @param bool $report Report salvaged and skipped lines (not for checkIntegrity())
     */
    private function replaySegment($dbname, $shardId, array &$index, $size, $oldMap = null, $report = true){
        $from = (int)$index['s'];
        if($shardId !== null){
            $index['z'] = $this->shardSizeOf($dbname);
            $index['sid'] = $shardId;
        }
        if($size <= $from){
            return;
        }
        $fp = @fopen($this->segDataPath($dbname, $shardId), 'rb');
        if($fp === false){
            return;
        }
        $base = $shardId === null ? 0 : $shardId * $this->shardSizeOf($dbname);
        $limit = $shardId === null ? null : $base + $this->shardSizeOf($dbname);
        $p = $index['p'];
        $l = $index['l'];
        unset($index['p'], $index['l']); // sole owner: changed in place, not copied
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
                        foreach(($oldMap === null ? [] : $this->indexEntries($oldMap)) as $k => $location){
                            if(is_array($location) && count($location) === 2){
                                $oldEnds[(int)$location[0] + (int)$location[1]] = [(int)$k, (int)$location[0]];
                            }
                        }
                    }
                    $entry = $this->salvageLine($json, $lineOffset, $oldEnds, $base, $limit);
                    $events[] = [
                        'action' => $entry === null ? 'skip-line' : 'salvaged',
                        'seg' => $shardId === null ? 'm' : (string)$shardId,
                        'off' => $lineOffset, 'len' => strlen($json) + 1,
                        'sha256' => hash('sha256', $json . "\n"),
                    ] + ($entry === null ? [] : ['key' => $entry[1]]);
                }
                if($entry === null){
                    $d++;
                } else {
                    $key = $entry[1];
                    if($entry[0] === 't'){
                        if(isset($p[$key])){
                            unset($p[$key], $l[$key]);
                            $d++;
                        }
                        $t++;
                    } else {
                        if(isset($p[$key])){
                            $d++;
                        }
                        $p[$key] = $lineOffset + $entry[2];
                        $l[$key] = $entry[3];
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
        $index['n'] = $n;
        $index['d'] = $d;
        $index['t'] = $t;
        $index['s'] = $offset;
        $index['tl'] = (int)$index['tl'] + $lines;
        self::$indexStats['replayedLines'] += $lines;
        foreach($report ? $events : [] as $event){
            $event['id'] = $event['seg'] . '.0.' . $event['off'] . '.' . $event['len'] . '.' . $event['sha256'];
            unset($event['sha256']);
            $this->reportRecoveryEvent($dbname, $event);
        }
    }

    /**
     * A recovery decision: logged at once under the exclusive lock, else kept in memory and logged by the
     * next exclusive session of this process (readers never write); getRecoveryLog() shows both
     */
    private function reportRecoveryEvent($dbname, array $event){
        $lock = $this->getLockPath($dbname);
        if(self::$noWrites === 0 && isset(self::$lockRegistry[$lock]) && self::$lockRegistry[$lock]['mode'] === LOCK_EX){
            $this->logRecoveryEvent($dbname, $event, $event['id']);
        } else {
            self::$pendingRecoveryEvents[$lock][$event['id']] = ['t' => time()] + $event;
        }
    }

    /**
     * A segment index changed by a write (exclusive lock): cached, and checkpointed when the session ends
     */
    private function storeSegmentIndex($dbname, $shardId, array $index){
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        $this->indexCache[$path] = $index;
        $this->verifiedIndexes[$path] = true;
        self::$lockRegistry[$this->getLockPath($dbname)]['touched'][$shardId === null ? 'main' : $shardId] = $shardId;
        if(self::$faultHook !== null){ $this->fault('index:written', ['db' => $dbname, 'shard' => $shardId]); }
    }

    /**
     * True when the lines after a segment's checkpoint are many enough to write a new one
     */
    private function needsCheckpoint(array $index){
        return (int)$index['ck'] < 0 || !empty($index['v3'])
            || (int)$index['tl'] >= self::CHECKPOINT_LINES + intdiv(count($index['p']), 16)
            || (int)$index['s'] - (int)$index['ck'] >= self::CHECKPOINT_BYTES;
    }

    /**
     * Write a segment's checkpoint (best effort: a failure only means a longer replay next time). An
     * index without epoch gets one, which only the exclusive lock holder may assign.
     * @return bool
     */
    private function writeCheckpoint($dbname, $shardId, array &$index){
        if(self::$noWrites > 0){
            return false;
        }
        $lock = $this->getLockPath($dbname);
        $exclusive = isset(self::$lockRegistry[$lock]) && self::$lockRegistry[$lock]['mode'] === LOCK_EX;
        if($index['e'] === null){
            if(!$exclusive){
                return false;
            }
            $index['e'] = bin2hex(random_bytes(4));
        }
        $dataPath = $this->segDataPath($dbname, $shardId);
        $crc = $this->segmentCrc($dataPath, (int)$index['s']);
        if($crc === null){
            return false;
        }
        $file = $index;
        unset($file['ck'], $file['tl'], $file['v3']);
        $file['v'] = 4;
        $file['format'] = 'jsonl';
        $file['updated'] = time();
        $file['h'] = $crc;
        if($shardId !== null){
            $file['z'] = $this->shardSizeOf($dbname);
            $file['sid'] = $shardId;
        }
        $json = $this->encodeJson($file);
        if($json === false || !$this->writeDerivedFile($dataPath . '.jidx', $json)){
            return false;
        }
        $index['ck'] = (int)$index['s'];
        $index['tl'] = 0;
        unset($index['v3']);
        self::$indexStats['checkpoints']++;
        $this->indexCache[$dataPath . '.jidx'] = $index;
        $this->dataEpochs[$dataPath] = $index['e'];
        if(self::$faultHook !== null){ $this->fault('checkpoint:written', ['db' => $dbname, 'shard' => $shardId]); }
        return true;
    }

    /**
     * End of a successful write session: checkpoint the segments it changed that need one
     */
    private function checkpointTouchedSegments($dbname, $lock){
        $touched = self::$lockRegistry[$lock]['touched'] ?? [];
        $segments = $touched + (self::$uncheckpointed[$lock] ?? []);
        unset(self::$uncheckpointed[$lock]);
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
        self::$lockRegistry[$lock]['touched'] = [];
        foreach($touched as $shardId){
            try {
                $this->checkpointSegmentSecondaries($dbname, $shardId);
            } catch(\Throwable $e){
                if($e->getCode() === self::LOCK_FAILED){
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
        $lock = $this->getLockPath($dbname);
        if(self::$noWrites > 0 || !isset(self::$lockRegistry[$lock]) || self::$lockRegistry[$lock]['mode'] !== LOCK_SH){
            return;
        }
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        $index = $this->indexCache[$path];
        if($index['e'] === null || (int)$index['ck'] < 0 || !empty($index['v3']) || !$this->needsCheckpoint($index)){
            return;
        }
        try {
            $this->writeCheckpoint($dbname, $shardId, $index);
        } catch(\Throwable $ignored){
        }
    }

    /**
     * Remove bytes [$from, $to) at the end of a data or buffer file, keeping them in a quarantine file
     * first (exclusive lock). Fails closed: if the bytes can't be stored and verified, nothing is cut
     * and the write that needed the cut is refused.
     * @throws \RuntimeException
     */
    private function quarantineAndCut($dbname, $shardId, $path, $from, $to, $action = 'cut-tail'){
        $quarantine = $this->quarantineBytes($dbname, $shardId, $path, $from, $to);
        if(!$this->fsTruncate($path, $from)){
            throw new \RuntimeException("noneDB: could not cut the torn end of a file of database '{$dbname}'");
        }
        $this->logRecoveryEvent($dbname, ['action' => $action, 'id' => $quarantine['id'], 'file' => basename($path),
            'quarantine' => $quarantine['file'], 'bytesVerified' => true, 'powerLossDurable' => $quarantine['durable']], $quarantine['id']);
        return $quarantine;
    }

    /**
     * Copy bytes [$from, $to) of a file into {hash}.nonedb.q.{segment}.0.{offset}.{length}.{sha256}.bin
     * (segment: m, a shard id, or b for a buffer file)
     * and verify the copy. An existing file with the same name is reused only if its bytes match.
     * @return array ['id' => string, 'file' => string, 'durable' => true|false|'unknown']
     * @throws \RuntimeException
     */
    private function quarantineBytes($dbname, $shardId, $path, $from, $to){
        $length = $to - $from;
        $seg = $shardId === null ? 'm' : (string)$shardId;
        $src = @fopen($path, 'rb');
        if($src === false){
            throw new \RuntimeException("noneDB: could not read bytes to quarantine in database '{$dbname}'");
        }
        $tmp = $this->dbDir . $this->hashDBName($dbname) . '.nonedb.q.tmp' . bin2hex(random_bytes(4));
        $this->noteWrite($tmp);
        $out = null;
        try {
            $this->fault('quarantine:write', ['db' => $dbname, 'path' => $path]);
            $out = @fopen($tmp, 'xb');
            if($out === false){
                throw new \RuntimeException("noneDB: could not create a quarantine file for database '{$dbname}'");
            }
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
            fflush($out);
            $fileSynced = $this->syncHandle($out);
            fclose($out);
            $out = null;

            $id = "{$seg}.0.{$from}.{$length}.{$sha}";
            $name = $this->hashDBName($dbname) . ".nonedb.q.{$id}.bin";
            $target = $this->dbDir . $name;
            clearstatcache(true, $target);
            if(file_exists($target)){
                @unlink($tmp); // the same event again (e.g. the cut didn't happen last time): keep the first copy
                $fileSynced = null;
            } elseif(!@rename($tmp, $target)){
                throw new \RuntimeException("noneDB: could not publish a quarantine file for database '{$dbname}'");
            }
            $dirSynced = $this->syncDirectory($this->dbDir);
            if(@filesize($target) !== $length || hash_file('sha256', $target) !== $sha){
                throw new \RuntimeException("noneDB: quarantine file {$name} doesn't match the bytes it should hold");
            }
            if($fileSynced === false || $dirSynced === false){
                $durable = false;
            } elseif($fileSynced === null && !function_exists('fsync')){
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
     * fsync() a file handle where PHP and the stream support it (PHP >= 8.1, plain files). Never emits a
     * warning: the result goes to the durability report.
     * @return bool|null Null when it can't be done here
     */
    private function syncHandle($fp, $path = null){
        if(!function_exists('fsync')){
            return null;
        }
        $meta = @stream_get_meta_data($fp);
        if(!is_array($meta) || ($meta['wrapper_type'] ?? '') !== 'plainfile'){
            return null;
        }
        $ok = self::$syncFn !== null ? (bool)(self::$syncFn)($fp, 'file') : @fsync($fp);
        $this->countSync('file', $ok, $path);
        return $ok;
    }

    /**
     * fsync() a directory so a new, renamed or deleted file in it survives a power loss (not possible on
     * Windows: NTFS journals its metadata)
     * @return bool|null Null when it can't be done here
     */
    private function syncDirectory($dir){
        if(!function_exists('fsync') || PHP_OS_FAMILY === 'Windows'){
            return null;
        }
        $fp = @fopen(rtrim($dir, '/\\') . '/', 'r');
        if($fp === false){
            $this->countSync('dir', false, $dir);
            return false;
        }
        try {
            $ok = self::$syncFn !== null ? (bool)(self::$syncFn)($fp, 'dir') : @fsync($fp);
            $this->countSync('dir', $ok, $dir);
            return $ok;
        } finally {
            fclose($fp);
        }
    }

    private function countSync($kind, $ok, $path){
        self::$syncStats[$kind]++;
        if(!$ok){
            self::$syncStats['failed']++;
            self::$lastSyncError = ['path' => $path === null ? null : basename((string)$path), 'op' => $kind, 'time' => time()];
        }
    }

    /**
     * Record a sync the current write needs for power-loss durability: true (done), false (failed),
     * null (not done: fsync off or not available)
     */
    private function noteSync($kind, $result){
        if(!$this->fsync){
            return; // nothing is synced: the report says so without tracking each write
        }
        foreach(self::$lockRegistry as $path => $entry){
            if($entry['mode'] === LOCK_EX){
                $current = self::$lockRegistry[$path]['durability'][$kind] ?? true;
                self::$lockRegistry[$path]['durability'][$kind] = $current === false || $result === false ? false
                    : ($current === null || $result === null ? null : true);
            }
        }
    }

    /**
     * Sync a data/meta write when the fsync setting is on; records what the operation needed
     * @return bool False when the sync failed
     */
    private function syncForWrite($fp, $path, $kind = 'file'){
        if(!$this->fsync){
            $this->noteSync($kind, null);
            return true;
        }
        $ok = $kind === 'dir' ? $this->syncDirectory($path) : $this->syncHandle($fp, $path);
        $this->noteSync($kind, $ok);
        return $ok !== false;
    }

    /**
     * rename() that retries where it can fail for a moment: on Windows while another process has the target
     * open (5, 10, 20, 40, 80, 160 ms, within lockTimeout). The target is never deleted first.
     */
    private function renameWithRetry($from, $to){
        $fn = self::$renameFn;
        $retry = PHP_OS_FAMILY === 'Windows' || $fn !== null;
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
            if($fn !== null ? $fn($from, $to) : @rename($from, $to)){
                return true;
            }
        }
        return false;
    }

    /**
     * Durability of this setup and of the last write (v3.3.0):
     * - capability: what PHP and the platform can do (fsync() needs PHP 8.1; directories can't be synced
     *   on Windows)
     * - effective: what is attempted with the fsync setting ('none', 'file', 'file+dir')
     * - lastOperationDurability: whether every sync the last write needed for power-loss durability
     *   succeeded (an earlier success never counts for a later write)
     * - lastSyncError, syncs (counters) and notes
     * Process crashes never need fsync: what was written survives them (see docs/CONFIGURATION.md).
     * @return array
     */
    public function getDurability(): array {
        $file = function_exists('fsync');
        $dir = $file && $this->directorySyncWorks();
        $notes = [];
        if(!$file){
            $notes[] = 'fsync() needs PHP 8.1: writes are not synced to disk, a power loss can lose recent writes';
        }
        if(PHP_OS_FAMILY === 'Darwin'){
            $notes[] = 'macOS: fsync() does not flush the drive cache (F_FULLFSYNC is not available in PHP)';
        }
        if(PHP_OS_FAMILY === 'Windows'){
            $notes[] = 'Windows: directories can not be synced; new and renamed files depend on NTFS';
        }
        return [
            'capability' => ['file' => $file, 'dir' => $dir],
            'effective' => !$this->fsync || !$file ? 'none' : ($dir ? 'file+dir' : 'file'),
            'lastOperationDurability' => self::$lastOperationDurability
                ?? ['op' => null, 'required' => [], 'completed' => [], 'durable' => false, 'reason' => 'no write yet'],
            'lastSyncError' => self::$lastSyncError,
            'syncs' => self::$syncStats,
            'notes' => $notes,
        ];
    }

    private function directorySyncWorks(){
        if(PHP_OS_FAMILY === 'Windows'){
            return false;
        }
        if(!array_key_exists($this->dbDir, self::$dirSyncWorks)){
            if(!is_dir($this->dbDir)){
                return false; // nothing to sync yet: checked again once the directory exists
            }
            $fp = @fopen($this->dbDir, 'r');
            self::$dirSyncWorks[$this->dbDir] = $fp !== false;
            if($fp !== false){
                fclose($fp);
            }
        }
        return self::$dirSyncWorks[$this->dbDir];
    }

    /**
     * Summary of the syncs a finished write session needed (getDurability())
     */
    private function finishDurability($path, $dbname){
        $done = self::$lockRegistry[$path]['durability'] ?? [];
        $required = array_values(array_intersect(['file', 'dir'], array_keys($done)));
        $completed = array_values(array_filter($required, function($kind) use ($done){ return $done[$kind] === true; }));
        if(!$this->fsync){
            $durable = false;
            $reason = 'fsync is off';
        } elseif(!function_exists('fsync')){
            $durable = false;
            $reason = 'fsync() needs PHP 8.1';
        } elseif(in_array(false, $done, true)){
            $durable = false;
            $reason = 'a ' . implode('/', array_keys(array_filter($done, function($r){ return $r === false; }))) . ' sync failed';
        } elseif(array_key_exists('dir', $done) && PHP_OS_FAMILY === 'Windows'){
            $durable = 'unknown';
            $reason = 'directories can not be synced on Windows';
        } elseif(in_array(null, $done, true)){
            $durable = false;
            $reason = 'a file could not be synced (not a plain file)';
        } else {
            $durable = true;
            $reason = null;
        }
        self::$lastOperationDurability = ['op' => "write to '{$dbname}'", 'required' => $required, 'completed' => $completed,
            'durable' => $durable, 'reason' => $reason];
    }

    /**
     * Delete temp files ("*.tmp" + 8 hex digits) starting with $prefix: what a writer that died left
     * behind (T2-12). Exclusive lock only: no other writer can be in the middle of an atomic write.
     */
    private function removeStaleTemp($prefix){
        if(self::$noWrites > 0){
            return;
        }
        foreach($this->listFilesWithPrefix($prefix, '/\.tmp[0-9a-f]{8}$/') as $file){
            @unlink($file);
        }
    }

    /**
     * Position of the last "\n" in bytes [$from, $to) of a file, -1 if there is none
     */
    private function lastNewlineIn($path, $from, $to){
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
     * that doesn't fit the data, a key the index points at that isn't there. When the rebuilt index
     * differs from the old one (or there was none), the segment's field and spatial indexes are rebuilt as
     * well: they may not know the records found in the data. Without $force (pre-3.0 file): converted,
     * then loaded normally.
     * @param bool $secondaries Also rebuild field/spatial indexes when needed (recovery does it itself)
     * @return array|null
     */
    private function repairSegmentIndex($dbname, $shardId, $force = false, $secondaries = true){
        return $this->withLock($dbname, true, function() use ($dbname, $shardId, $force, $secondaries){
            $dataPath = $this->segDataPath($dbname, $shardId);
            $path = $dataPath . '.jidx';
            unset($this->indexCache[$path], $this->verifiedIndexes[$path], $this->writableIndexes[$path]);
            clearstatcache(true, $dataPath);
            if(!file_exists($dataPath)){
                return null;
            }
            if(!is_file($dataPath)){
                throw new \RuntimeException("noneDB: data file of database '{$dbname}' is not a regular file: " . basename($dataPath));
            }
            // A pre-3.0 {"data": [...]} file (it has no index) is converted, never scanned as JSONL
            $this->ensureJsonlFormat($dbname, $shardId);
            if(!$force){
                $this->jsonlFormatCache[$dataPath] = true; // converted (or not convertible: read as JSONL)
                return $this->loadSegmentIndex($dbname, $shardId);
            }
            $old = $this->atomicRead($path, null);
            $index = $this->rebuildSegmentIndexFromData($dbname, $shardId, is_array($old) ? $old : null);
            if($shardId !== null){
                $this->recountMeta($dbname);
            }
            if($secondaries && (!is_array($old) || $this->indexEntries($old) != $this->indexEntries($index))){
                $this->rebuildSecondaryIndexes($dbname, [$shardId]);
            }
            return $index;
        });
    }

    /**
     * key => [offset, length] of an index of any version (empty if it has no map)
     */
    private function indexEntries(array $index){
        if(isset($index['p'], $index['l']) && is_array($index['p']) && is_array($index['l'])){
            $entries = [];
            foreach($index['p'] as $key => $offset){
                $entries[(int)$key] = [(int)$offset, (int)($index['l'][$key] ?? -1)];
            }
        } else {
            $entries = isset($index['o']) && is_array($index['o']) ? $index['o'] : [];
        }
        ksort($entries, SORT_NUMERIC);
        return $entries;
    }

    /**
     * Rebuild field and spatial indexes of some segments (and the global ones of a sharded database)
     * from the data. Caller holds the exclusive lock.
     */
    private function rebuildSecondaryIndexes($dbname, array $segments){
        $manifest = $this->getIndexManifest($dbname);
        if(empty($manifest['fields']) && empty($manifest['spatial'])){
            return;
        }
        $known = $this->listSegments($dbname);
        // One index that can't be built must never lock the whole database: it is dropped (queries scan,
        // the next write or query builds it again) and logged
        $guard = function(callable $build, $what, array $files) use ($dbname){
            try {
                $build();
            } catch(\Throwable $e){
                if($e->getCode() === self::LOCK_FAILED){
                    throw $e;
                }
                foreach($files as $file){
                    $this->fsUnlink($file);
                    unset($this->fieldIndexCache[$file], $this->spatialIndexCache[$file]);
                }
                $this->logRecoveryEvent($dbname, ['action' => 'index-dropped', 'index' => $what, 'error' => $e->getMessage()]);
            }
        };
        foreach($segments as $shardId){
            if(!in_array($shardId, $known, true)){
                continue;
            }
            foreach($manifest['fields'] as $field){
                $guard(function() use ($dbname, $field, $shardId){
                    $legacy = $this->getLegacyFieldIndexPath($dbname, $field, $shardId);
                    $this->fsUnlink($legacy);
                    unset($this->fieldIndexCache[$legacy]);
                    $index = $this->buildSegmentFieldIndex($dbname, $shardId, $field);
                    $this->writeFieldIndex($dbname, $field, $index, $shardId);
                }, 'field ' . $field, [$this->getFieldIndexPath($dbname, $field, $shardId)]);
            }
            foreach($manifest['spatial'] as $field){
                $guard(function() use ($dbname, $field, $shardId, $manifest){
                    $legacy = $this->getLegacySpatialIndexPath($dbname, $field, $shardId);
                    $this->fsUnlink($legacy);
                    unset($this->spatialIndexCache[$legacy]);
                    $tree = $this->buildSegmentSpatialIndex($dbname, $field, $shardId,
                        isset($manifest['spatialOptions'][$field]['nodeSize']) ? ['nodeSize' => $manifest['spatialOptions'][$field]['nodeSize']] : []);
                    $this->writeSpatialIndex($dbname, $field, $tree, $shardId);
                    if($shardId !== null){
                        $this->updateGlobalSpatialShard($dbname, $field, $shardId, $tree);
                    }
                }, 'spatial ' . $field, [$this->getSpatialIndexPath($dbname, $field, $shardId)]);
            }
            unset($this->convertedSegments[$this->segDataPath($dbname, $shardId)]);
        }
        if($this->isSharded($dbname)){
            foreach($manifest['fields'] as $field){
                $guard(function() use ($dbname, $field){
                    $this->deleteLegacyGlobalFieldIndex($dbname, $field);
                    $this->rebuildGlobalFieldIndex($dbname, $field);
                }, 'global field ' . $field, [$this->getGlobalFieldIndexPath($dbname, $field)]);
            }
        }
    }

    /**
     * Index of a segment a writer is about to change (exclusive lock), taken out of the cache: the
     * writer changes it in place and stores it back (storeSegmentIndex). Checked against the data file
     * when it was not yet known to match it: an unterminated tail (crashed append) is quarantined and cut
     * off, so the next line can't be glued to it. A v3.2 index file is deleted before the first append
     * (v3.2 would trust its stale key counter); a checkpoint replaces it when the session ends. A checked
     * index stays checked until its cache is dropped; appendToFile() compares the size it finds with the
     * index anyway (no extra system call), which catches anything else.
     * @return array|null
     */
    private function writableSegmentIndex($dbname, $shardId){
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null){
            return null;
        }
        $dataPath = $this->segDataPath($dbname, $shardId);
        $path = $dataPath . '.jidx';
        if(!isset($this->writableIndexes[$path])){
            clearstatcache(true, $dataPath);
            $size = (int)@filesize($dataPath);
            if($size < (int)$index['s']){
                $index = $this->repairSegmentIndex($dbname, $shardId, true);
                clearstatcache(true, $dataPath);
                $size = (int)@filesize($dataPath);
            }
            if($size > (int)$index['s']){
                $this->quarantineAndCut($dbname, $shardId, $dataPath, (int)$index['s'], $size);
            }
            $this->writableIndexes[$path] = true;
        }
        if(!empty($index['v3'])){
            $this->fsUnlink($path);
            unset($index['v3']);
            $index['ck'] = -1;
            $this->indexCache[$path] = $index;
        }
        unset($this->indexCache[$path]); // the caller is its only holder until it stores it back
        return $index;
    }

    /**
     * A writer's index goes back to the cache unchanged (nothing to write after all)
     */
    private function releaseSegmentIndex($dbname, $shardId, array $index){
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        $this->indexCache[$path] = $index;
        $this->verifiedIndexes[$path] = true;
    }

    /**
     * Rebuild a segment index from the whole data file and write its checkpoint (exclusive lock): new
     * segment epoch, as there is no checkpoint it continues
     * @param array|null $old The index file found (created time, key counter, salvage of glued lines)
     * @return array
     */
    private function rebuildSegmentIndexFromData($dbname, $shardId, $old = null){
        $dataPath = $this->segDataPath($dbname, $shardId);
        if($old === null){
            $old = $this->atomicRead($dataPath . '.jidx', null);
            $old = is_array($old) ? $old : null;
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
        $path = $dataPath . '.jidx';
        $this->indexCache[$path] = $index;
        $this->verifiedIndexes[$path] = true;
        $this->writeCheckpoint($dbname, $shardId, $index);
        return $this->indexCache[$path];
    }

    /**
     * Classify one complete data line: ['r', key, 0, length] (record), ['t', key] (tombstone), or null
     * when it isn't a valid line of this segment
     */
    private function classifyLine($json, $base, $limit){
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
     * Find a record or tombstone at the end of a line that doesn't decode (written by an older version
     * right after a torn tail). First where the old index says a record ended; then candidates from the
     * rightmost "{" leftwards: inside a record no "{" starts a JSON text that also ends the line, so the
     * first one that decodes is where the record starts.
     * @return array|null Like classifyLine(), with the record's offset inside the line
     */
    private function salvageLine($json, $lineOffset, array $oldEnds, $base, $limit){
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

    /**
     * Recompute per-shard and total counters in the meta file from the shard indexes
     */
    private function recountMeta($dbname){
        $meta = $this->getCachedMeta($dbname, true);
        if($meta === null){
            return;
        }
        $size = (int)$meta['shardSize'];
        $total = 0;
        $nextKey = 0;
        foreach($meta['shards'] as $i => $shard){
            $index = $this->readJsonlIndex($dbname, (int)$shard['id']);
            $count = $index === null ? 0 : count($index['p']);
            $meta['shards'][$i]['count'] = $count;
            $total += $count;
            if($index !== null){
                // n of a shard counts every key it ever had (deleted ones and the kept top tombstone too)
                $nextKey = max($nextKey, empty($index['p']) ? 0 : max(array_keys($index['p'])) + 1);
                if((int)$index['n'] > 0){
                    $nextKey = max($nextKey, (int)$shard['id'] * $size + (int)$index['n']);
                }
            }
        }
        $meta['totalRecords'] = $total;
        $meta['nextKey'] = max((int)($meta['nextKey'] ?? 0), $nextKey);
        $this->writeMeta($dbname, $meta);
    }

    /**
     * Detect if a data file is in JSONL format (vs the pre-3.0 single JSON document {"data": [...]})
     * @param string $path
     * @return bool True if JSONL format
     */
    private function isJsonlFormat($path){
        if(isset($this->jsonlFormatCache[$path])){
            return $this->jsonlFormatCache[$path];
        }
        if(!$this->cachedFileExists($path)){
            return true;
        }

        // A pre-3.0 file is one JSON document {"data": [...]} without a line end; a JSONL line ends with
        // "\n", and a record always has a "key" field, so a record whose first field is "data" is never
        // mistaken. Only the first line is read: a missing .jidx is normal since v3.3 (checkpoints).
        $isJsonl = true;
        $fp = @fopen($path, 'rb');
        if($fp !== false){
            $first = fgets($fp);
            fclose($fp);
            if($first !== false && substr($first, -1) !== "\n"){
                $decoded = json_decode($first, true);
                if(is_array($decoded) && array_keys($decoded) === ['data'] && is_array($decoded['data'])){
                    $isJsonl = false;
                }
            }
        }

        $this->jsonlFormatCache[$path] = $isJsonl;
        return $isJsonl;
    }

    /**
     * Ensure a segment is in JSONL format (auto-migrates the pre-3.0 format)
     * @param string $dbname
     * @param int|null $shardId
     * @return bool
     */
    private function ensureJsonlFormat($dbname, $shardId = null){
        $path = $this->segDataPath($dbname, $shardId);
        if($this->isJsonlFormat($path)){
            return true;
        }
        return $this->migrateToJsonl($path, $dbname, $shardId);
    }

    /**
     * Migrate a pre-3.0 {"data": [...]} file to JSONL (exclusive lock)
     * @param string $path Source file path
     * @param string $dbname Database name
     * @param int|null $shardId Shard ID or null for non-sharded
     * @return bool Success
     */
    private function migrateToJsonl($path, $dbname, $shardId = null){
        return $this->withLock($dbname, true, function() use ($path, $dbname, $shardId){
            unset($this->jsonlFormatCache[$path]);
            if($this->isJsonlFormat($path)){
                return true; // migrated by someone else meanwhile
            }

            $data = json_decode((string)@file_get_contents($path), true);
            if(!is_array($data) || !isset($data['data']) || !is_array($data['data'])){
                return false;
            }

            $index = $this->newJsonlIndex();
            $base = ($shardId !== null) ? ($shardId * $this->shardSizeOf($dbname)) : 0;
            $buffer = '';
            $offset = 0;
            $slots = 0;
            foreach($data['data'] as $localKey => $record){
                $slots = max($slots, (int)$localKey + 1);
                if(!is_array($record)){
                    continue; // deleted (null) or invalid slot: not stored, not garbage
                }
                $key = $base + (int)$localKey;
                unset($record['key']);
                $record['key'] = $key;
                $json = $this->encodeJson($record);
                if($json === false){
                    continue;
                }
                $index['p'][$key] = $offset;
                $index['l'][$key] = strlen($json);
                $buffer .= $json . "\n";
                $offset += strlen($json) + 1;
            }
            $index['n'] = $slots;
            // Keep the highest slot ever used (see compactJsonl)
            if($slots > 0 && empty($data['data'][$slots - 1]) && !is_array($data['data'][$slots - 1] ?? null)){
                $buffer .= '{"__nonedb_deleted":' . ($base + $slots - 1) . "}\n";
                $index['t'] = 1;
            }

            $this->bumpEpoch($dbname); // the data file is replaced
            $this->fsUnlink($path . '.jidx');
            if(!$this->writeFileAtomic($path, $buffer, true)){
                return false;
            }
            $this->jsonlFormatCache[$path] = true;
            $index['s'] = strlen($buffer);
            $index['e'] = bin2hex(random_bytes(4));
            $this->indexCache[$path . '.jidx'] = $index;
            $this->verifiedIndexes[$path . '.jidx'] = true;
            $this->writeCheckpoint($dbname, $shardId, $index);
            return true;
        });
    }

    // ==========================================
    // RECORD I/O
    // ==========================================

    /**
     * Batch read multiple JSONL records (one open, 64KB buffered sequential reads)
     * @param string $path File path
     * @param array $positions [key => offset, ...]
     * @param array $lengths [key => length, ...]
     * @param bool $mismatch Set to true when a line doesn't hold the expected record
     * @return array [key => record, ...]
     */
    private function readJsonlRecordsBatch($path, $positions, $lengths, &$mismatch = false){
        $mismatch = false;
        if(empty($positions)){
            return [];
        }

        $handle = @fopen($path, 'rb');
        if($handle === false){
            $mismatch = true;
            return [];
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

        foreach($positions as $key => $position){
            $offset = (int)$position;
            $length = (int)($lengths[$key] ?? 0);
            if($length <= 0){
                $mismatch = true;
                continue;
            }

            if($offset >= $bufferStart && ($offset + $length) <= $bufferEnd){
                $json = substr($buffer, $offset - $bufferStart, $length);
            } else {
                fseek($handle, $offset, SEEK_SET);
                // One record: read just its bytes (T2-11); several: 64 KB at a time
                $buffer = (string)fread($handle, count($positions) === 1 ? $length : max($length, $bufferSize));
                $bufferStart = $offset;
                $bufferEnd = $offset + strlen($buffer);
                $json = substr($buffer, 0, $length);
            }

            $record = json_decode((string)$json, true);
            if(!is_array($record) || !isset($record['key']) || $record['key'] !== (int)$key){
                $mismatch = true;
                continue;
            }
            $records[$key] = $record;
        }

        fclose($handle);
        return $records;
    }

    /**
     * Read records of one segment, verified against their keys. A stale or corrupt index is
     * rebuilt from the data file once.
     * @param array|null $keys Keys to read (null = all)
     * @return array [key => record] sorted by key
     */
    private function readSegmentRecords($dbname, $shardId, $keys = null){
        self::$segmentReads++;
        for($attempt = 0; $attempt < 2; $attempt++){
            $index = $attempt === 0
                ? $this->readJsonlIndex($dbname, $shardId)
                : $this->repairSegmentIndex($dbname, $shardId, true);
            if($index === null){
                return [];
            }
            if($keys === null){
                $positions = $index['p'];
            } else {
                $positions = [];
                foreach($keys as $key){
                    if(isset($index['p'][$key])){
                        $positions[$key] = $index['p'][$key];
                    }
                }
            }
            $mismatch = false;
            $records = $this->readJsonlRecordsBatch($this->segDataPath($dbname, $shardId), $positions, $index['l'], $mismatch);
            if(!$mismatch){
                ksort($records, SORT_NUMERIC);
                return $records;
            }
        }
        throw new \RuntimeException("noneDB: data file of database '{$dbname}' is corrupt and could not be repaired");
    }

    /**
     * Append records ([key => record]) to a segment and point the index at them.
     * Everything is encoded before anything is written, so an unencodable record writes nothing.
     * @throws \InvalidArgumentException|\RuntimeException
     */
    private function appendSegmentRecords($dbname, $shardId, array &$index, array $records){
        if(empty($records)){
            return;
        }
        $lines = $this->withExactFloats(function() use ($records){
            $lines = [];
            foreach($records as $key => $record){
                unset($record['key']);
                $record['key'] = (int)$key;
                $lines[$key] = $this->encodeRecord($record);
            }
            return $lines;
        });
        // After encoding: a record that can't be encoded leaves no journal entry behind
        $this->markIndexesPending($dbname, $shardId);
        $buffer = implode("\n", $lines) . "\n";
        $dataPath = $this->segDataPath($dbname, $shardId);
        $offset = $this->appendToFile($dataPath, $buffer, $dbname, $index['s'] ?? null, $shardId);
        if($offset === false){
            $this->failedAppend($dbname, $shardId);
        }
        $index['s'] = $offset + strlen($buffer);
        $this->markFileExists($dataPath);
        foreach($lines as $key => $json){
            if(isset($index['p'][$key])){
                $index['d']++; // the line it had before is garbage now
            }
            $index['p'][$key] = $offset;
            $index['l'][$key] = strlen($json);
            $offset += strlen($json) + 1;
        }
        $index['tl'] = (int)$index['tl'] + count($lines);
    }

    /**
     * Append tombstones for deleted keys, so an index rebuilt from the data won't resurrect them
     */
    private function appendTombstones($dbname, $shardId, array &$index, array $keys){
        if(empty($keys)){
            return;
        }
        $this->markIndexesPending($dbname, $shardId);
        $buffer = '';
        foreach($keys as $key){
            $buffer .= '{"__nonedb_deleted":' . (int)$key . "}\n";
        }
        $offset = $this->appendToFile($this->segDataPath($dbname, $shardId), $buffer, $dbname, $index['s'] ?? null, $shardId);
        if($offset === false){
            $this->failedAppend($dbname, $shardId);
        }
        $index['s'] = $offset + strlen($buffer);
        foreach($keys as $key){
            if(isset($index['p'][$key])){
                unset($index['p'][$key], $index['l'][$key]);
                $index['d']++; // the old record line
            }
        }
        $index['t'] = (int)$index['t'] + count($keys); // tombstone lines
        $index['tl'] = (int)$index['tl'] + count($keys);
    }

    /**
     * An append to a segment wrote nothing: throw. When the data file didn't have the size the index
     * covers (it changed behind this process's back), the cached index is dropped as well.
     * @throws \RuntimeException
     */
    private function failedAppend($dbname, $shardId){
        if($this->appendSyncFailed){
            throw new \RuntimeException("noneDB: fsync of the data of database '{$dbname}' failed; nothing was written");
        }
        if($this->appendMismatch){
            $path = $this->getJsonlIndexPath($dbname, $shardId);
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
    private function createJsonlDatabase($dbname, $shardId = null){
        $path = $this->segDataPath($dbname, $shardId);
        clearstatcache(true, $path);
        if(!file_exists($path) && !$this->fsTouch($path)){
            throw new \RuntimeException("noneDB: cannot create data file of database '{$dbname}'");
        }
        $this->markFileExists($path);
        $this->jsonlFormatCache[$path] = true;
        $index = $this->newJsonlIndex();
        $index['e'] = bin2hex(random_bytes(4));
        $this->indexCache[$path . '.jidx'] = $index;
        $this->verifiedIndexes[$path . '.jidx'] = true;
        // No checkpoint yet (ck -1): the session writes one when it ends, with the first records (until then
        // the new segment replays from 0, which is the same index)
        self::$lockRegistry[$this->getLockPath($dbname)]['touched'][$shardId === null ? 'main' : $shardId] = $shardId;
        return true;
    }

    // ==========================================
    // WRITE PATHS (callers hold the exclusive lock)
    // ==========================================

    /**
     * Insert validated records. Assigns keys, writes data + indexes, updates field/spatial indexes
     * and migrates to sharded format when the size threshold is reached.
     * @param array $items Records without "key"
     * @return array ['n' => int] or ['n' => 0, 'error' => string]
     */
    private function insertRecords($dbname, array $items, $startKey = null){
        if(empty($items)){
            return ["n" => 0];
        }
        if(!$this->dbExists($dbname)){
            if(!$this->autoCreateDB){
                return ["n" => 0, "error" => "Database does not exist"];
            }
            $this->createDBUnlocked($dbname);
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
            foreach(array_keys($this->selectRecords($dbname, ['key' => array_keys($keyed)])) as $existing){
                unset($keyed[$existing]);
            }
        }

        if($this->isSharded($dbname)){
            $this->insertShardedRecords($dbname, $items, $keyed, $endKey);
            return ["n" => count($items)];
        }

        $index = $this->writableSegmentIndex($dbname, null);
        if($index === null){
            $this->createJsonlDatabase($dbname, null);
            $index = $this->writableSegmentIndex($dbname, null);
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
        $this->appendSegmentRecords($dbname, null, $index, $records);
        $this->storeSegmentIndex($dbname, null, $index);
        $this->afterRecordsChanged($dbname, null, [], $this->withKeys($records));

        if($this->shardingEnabled && $this->autoMigrate && $index['n'] >= $this->shardSize){
            // Replaces the data file: after this write committed
            $this->runIndependent($dbname, 'migrate', function() use ($dbname){
                return $this->migrateToSharded($dbname);
            });
        }
        return ["n" => count($items)];
    }

    /**
     * Insert into a sharded database. Keys come from meta.nextKey; key K lives in shard intdiv(K, shardSize).
     */
    private function insertShardedRecords($dbname, array $items, $keyed = null, $endKey = null){
        $meta = $this->getCachedMeta($dbname, true);
        if($meta === null){
            throw new \RuntimeException("noneDB: metadata of sharded database '{$dbname}' is unreadable");
        }
        $size = (int)$meta['shardSize'];
        $nextKey = (int)$meta['nextKey'];
        // The meta may lag behind the data (a meta update lost in a power loss): never hand out a key the
        // last shard has already used
        if(!empty($meta['shards'])){
            $last = max(array_map('intval', array_column($meta['shards'], 'id')));
            $lastIndex = $this->readJsonlIndex($dbname, $last);
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
                $meta['shards'][] = ["id" => $shardId, "file" => "_s" . $shardId, "count" => 0, "deleted" => 0];
                $shardPos[$shardId] = count($meta['shards']) - 1;
            }
            $meta['shards'][$shardPos[$shardId]]['count'] += count($records);
        }
        // Journal first: if we die after reserving, recovery recounts the meta from the shards
        foreach(array_keys($byShard) as $shardId){
            $this->markIndexesPending($dbname, $shardId);
        }
        $meta['nextKey'] = $nextKey;
        $meta['totalRecords'] = (int)$meta['totalRecords'] + count($items);
        $this->writeMeta($dbname, $meta);

        // A failure below is undone by the rollback scope (appends of every shard cut back, counters
        // recounted); the reserved keys stay unused and the new shards stay listed (empty)
        foreach($byShard as $shardId => $records){
            $index = $this->writableSegmentIndex($dbname, $shardId);
            if($index === null){
                $this->createJsonlDatabase($dbname, $shardId);
                $index = $this->writableSegmentIndex($dbname, $shardId);
            }
            $this->appendSegmentRecords($dbname, $shardId, $index, $records);
            $index['n'] = max((int)$index['n'], max(array_keys($records)) - $shardId * $size + 1);
            $this->storeSegmentIndex($dbname, $shardId, $index);
            $this->afterRecordsChanged($dbname, $shardId, [], $this->withKeys($records));
        }
    }

    /**
     * Files of a shard the meta doesn't list (left by an interrupted dropDB of v3.2, or a meta update lost
     * in a power loss) must not be taken over by a new shard with the same number: their records were
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
        $base = $this->dbBase($dbname);
        $upTo = max(array_map('intval', $shardIds));
        if(isset($this->shardFilesUpTo[$base]) && min(array_map('intval', $shardIds)) > $this->shardFilesUpTo[$base]){
            $this->shardFilesUpTo[$base] = $upTo;
            return;
        }
        $wanted = array_flip(array_map('intval', $shardIds));
        $files = [];
        foreach([$base . '_s' => '/^\d+\.nonedb/',
            $this->dbDir . $this->hashDBName($dbname) . '_s' => '/^\d+\.nonedb\.sidx\./'] as $prefix => $regex){
            foreach($this->listFilesWithPrefix($prefix, $regex) as $file){
                $shardId = (int)substr($file, strlen($prefix));
                $upTo = max($upTo, $shardId);
                if(isset($wanted[$shardId])){
                    $files[] = $file;
                }
            }
        }
        $stamp = '.orphan.' . date('YmdHis');
        $moved = false;
        foreach($files as $file){
            if(preg_match('/\.orphan\.\d+$/', $file)){
                continue;
            }
            if(!$moved){
                $this->bumpEpoch($dbname); // a shard file of this name goes away: no cache of it is kept
                $moved = true;
            }
            if(!$this->fsRename($file, $file . $stamp)){
                throw new \RuntimeException("noneDB: could not move aside the leftover file " . basename($file) . " of database '{$dbname}'");
            }
            unset($this->indexCache[$file], $this->fieldIndexCache[$file], $this->spatialIndexCache[$file], $this->jsonlFormatCache[$file]);
            $this->markFileNotExists($file);
        }
        $this->shardFilesUpTo[$base] = $upTo; // after bumpEpoch(), which drops it
    }

    /**
     * Records with their "key" field set
     */
    private function withKeys(array $records){
        foreach($records as $key => $record){
            unset($records[$key]['key']);
            $records[$key]['key'] = (int)$key;
        }
        return $records;
    }

    /**
     * Replace records ([key => full new record]) in one segment
     * @return int Number of records written
     */
    private function updateSegmentRecords($dbname, $shardId, array $oldRecords, array $newRecords){
        if(empty($newRecords)){
            return 0;
        }
        $index = $this->writableSegmentIndex($dbname, $shardId);
        foreach(array_keys($newRecords) as $key){
            if(!isset($index['p'][$key])){
                unset($newRecords[$key], $oldRecords[$key]);
            }
        }
        if(empty($newRecords)){
            $this->releaseSegmentIndex($dbname, $shardId, $index);
            return 0;
        }
        $this->appendSegmentRecords($dbname, $shardId, $index, $newRecords); // counts the replaced lines
        $this->storeSegmentIndex($dbname, $shardId, $index);
        $this->afterRecordsChanged($dbname, $shardId, $oldRecords, $this->withKeys($newRecords));
        $this->deferCompaction($dbname, $shardId);
        return count($newRecords);
    }

    /**
     * Delete records ([key => record]) from one segment
     * @return int Number of records deleted
     */
    private function deleteSegmentRecords($dbname, $shardId, array $oldRecords){
        if(empty($oldRecords)){
            return 0;
        }
        $index = $this->writableSegmentIndex($dbname, $shardId);
        foreach(array_keys($oldRecords) as $key){
            if(!isset($index['p'][$key])){
                unset($oldRecords[$key]);
            }
        }
        if(empty($oldRecords)){
            $this->releaseSegmentIndex($dbname, $shardId, $index);
            return 0;
        }
        $keys = array_keys($oldRecords);
        $this->appendTombstones($dbname, $shardId, $index, $keys); // drops the keys, counts the lines
        $this->storeSegmentIndex($dbname, $shardId, $index);
        $this->afterRecordsChanged($dbname, $shardId, $oldRecords, []);

        if($shardId !== null){
            $meta = $this->getCachedMeta($dbname, true);
            foreach($meta['shards'] as $i => $shard){
                if((int)$shard['id'] === $shardId){
                    $meta['shards'][$i]['count'] = max(0, $meta['shards'][$i]['count'] - count($keys));
                    $meta['shards'][$i]['deleted'] += count($keys);
                }
            }
            $meta['totalRecords'] = max(0, (int)$meta['totalRecords'] - count($keys));
            $meta['deletedCount'] = (int)($meta['deletedCount'] ?? 0) + count($keys);
            $this->writeMeta($dbname, $meta);
        }

        $this->deferCompaction($dbname, $shardId);
        return count($keys);
    }

    /**
     * Records of a segment changed. Field and spatial indexes follow the data by themselves (they replay
     * the data lines after their checkpoint when used, so a value given as an object is indexed as it is
     * stored): nothing to do here.
     */
    private function afterRecordsChanged($dbname, $shardId, array $removed, array $added){
    }

    private static function containsObject(array $value){
        foreach($value as $item){
            if(is_object($item) || (is_array($item) && self::containsObject($item))){
                return true;
            }
        }
        return false;
    }

    /**
     * Compaction replaces the data file, so it runs after the write committed (see the rollback scope)
     */
    private function deferCompaction($dbname, $shardId){
        $this->runIndependent($dbname, 'compact:' . ($shardId === null ? 'main' : $shardId), function() use ($dbname, $shardId){
            $this->maybeCompact($dbname, $shardId);
        });
    }

    /**
     * Compact a segment once garbage exceeds the threshold (relative to live records)
     */
    private function maybeCompact($dbname, $shardId){
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null){
            return;
        }
        $garbage = (int)$index['d'];
        if($garbage > 0 && $garbage > (count($index['p']) + $garbage) * $this->jsonlGarbageThreshold){
            $this->compactJsonl($dbname, $shardId);
        }
    }

    /**
     * Compact a segment: rewrite live records (keys and order preserved), drop old versions and
     * tombstones. Crash-safe: the data file is replaced first; if the index rename doesn't happen,
     * the shorter data file is detected and the index rebuilt from it.
     * @param string $dbname
     * @param int|null $shardId
     * @return array ['compacted' => int, 'freed' => int]
     */
    private function compactJsonl($dbname, $shardId = null){
        return $this->withLock($dbname, true, function() use ($dbname, $shardId){
            $this->refuseUncommittedFile($this->segDataPath($dbname, $shardId), 'compaction');
            $this->removeStaleTemp($this->segDataPath($dbname, $shardId) . '.');
            $plan = null;
            for($attempt = 0; $plan === null; $attempt++){
                if($attempt > 1){
                    throw new \RuntimeException("noneDB: data file of database '{$dbname}' is corrupt and could not be repaired");
                }
                // A record the index points at must verify; if not, rebuild the index once and plan again
                $index = $attempt === 0 ? $this->writableSegmentIndex($dbname, $shardId) : $this->repairSegmentIndex($dbname, $shardId, true);
                if($index === null){
                    return ['compacted' => 0, 'freed' => 0];
                }
                $plan = $this->planCompaction($dbname, $shardId, $index);
            }
            $path = $this->segDataPath($dbname, $shardId);
            $live = $plan['live'];
            ksort($live, SORT_NUMERIC);

            $newIndex = $this->newJsonlIndex();
            $newIndex['created'] = $index['created'] ?? time();
            $newIndex['n'] = $index['n'];
            $buffer = '';
            $offset = 0;
            $maxKey = -1;
            foreach($live as $key => $json){
                $newIndex['p'][$key] = $offset;
                $newIndex['l'][$key] = strlen($json);
                $buffer .= $json . "\n";
                $offset += strlen($json) + 1;
                $maxKey = $key;
            }
            // Keep the highest key ever used: an index rebuilt from this file must never hand it out again
            $highest = ($shardId === null ? 0 : $shardId * $this->shardSizeOf($dbname)) + (int)$index['n'] - 1;
            if($maxKey < $highest){
                $buffer .= '{"__nonedb_deleted":' . $highest . "}\n";
                $newIndex['t'] = 1;
            }
            // Lines that don't decode are dropped from the data file: their bytes go to quarantine first
            $events = [];
            foreach($plan['bad'] as $region){
                $quarantine = $this->quarantineBytes($dbname, $shardId, $path, $region[0], $region[0] + $region[1]);
                $events[] = ['action' => 'drop-line', 'id' => $quarantine['id'], 'file' => basename($path),
                    'quarantine' => $quarantine['file'], 'bytesVerified' => true, 'powerLossDurable' => $quarantine['durable']];
            }

            // The checkpoint goes first: it must never be taken for the new data file
            $this->bumpEpoch($dbname);
            $this->fsUnlink($path . '.jidx');
            $this->fault('compact:replace', ['db' => $dbname, 'shard' => $shardId]);
            if(!$this->writeFileAtomic($path, $buffer, true)){
                throw new \RuntimeException("noneDB: compaction of database '{$dbname}' failed while writing data");
            }
            $this->fault('compact:replaced', ['db' => $dbname, 'shard' => $shardId]);
            $newIndex['s'] = strlen($buffer);
            $newIndex['e'] = bin2hex(random_bytes(4));
            $this->indexCache[$path . '.jidx'] = $newIndex;
            $this->verifiedIndexes[$path . '.jidx'] = true;
            $this->writeCheckpoint($dbname, $shardId, $newIndex);
            foreach($events as $event){
                $this->logRecoveryEvent($dbname, $event);
            }
            return ['compacted' => count($live), 'freed' => (int)$index['d']];
        });
    }

    /**
     * Read a segment line by line for compaction: the JSON of every live record (verified against its
     * key) and the byte ranges of lines that are neither records nor tombstones
     * @return array|null ['live' => [key => json], 'bad' => [[offset, length], ...]]; null if the index
     *                    doesn't match the data
     */
    private function planCompaction($dbname, $shardId, array $index){
        $offsets = $index['p'];
        asort($offsets, SORT_NUMERIC); // offsets are distinct: file order (a sort without a callback)
        $entries = [];
        foreach($offsets as $key => $offset){
            $entries[] = [(int)$offset, (int)$key, (int)$index['l'][$key]];
        }
        $count = count($entries);
        // Keys of this segment: a line with another shard's key is damaged, never a superseded record
        $size = $shardId === null ? null : $this->shardSizeOf($dbname);
        $base = $shardId === null ? 0 : $shardId * $size;
        $limit = $shardId === null ? null : $base + $size;
        $fp = @fopen($this->segDataPath($dbname, $shardId), 'rb');
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
                $owned = [];
                while($next < $count && $entries[$next][0] < $end){
                    $owned[] = $entries[$next++];
                }
                if(substr($line, -1) !== "\n"){
                    if(!empty($owned)){
                        return null;
                    }
                    $bad[] = [$pos, $length]; // unterminated tail
                    break;
                }
                $json = substr($line, 0, -1);
                $whole = count($owned) === 1 && $owned[0][0] === $pos && $owned[0][2] === strlen($json);
                foreach($owned as $entry){
                    $sub = $whole ? $json : substr($line, $entry[0] - $pos, $entry[2]);
                    $record = json_decode($sub, true);
                    if(!is_array($record) || !isset($record['key']) || $record['key'] !== $entry[1]){
                        return null;
                    }
                    $live[$entry[1]] = $sub;
                }
                if(!$whole){
                    if(!empty($owned)){
                        $bad[] = [$pos, $length]; // a salvaged record inside other bytes: keep the line
                    } elseif($this->classifyLine($json, $base, $limit) === null){
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

    /**
     * {db}.nonedb.migrating: written before a migration writes its first shard, removed with the old file.
     * While the database isn't sharded yet, the non-orphan files of shards 0..shards-1 are that migration's
     * own (shard files that were there before were set aside first).
     */
    private function getMigrationMarkerPath($dbname){
        return $this->dbBase($dbname) . '.nonedb.migrating';
    }

    /**
     * Remove what an interrupted migration left (exclusive lock): its shard files while the database is
     * still the old file, or only the marker once the meta says the database is sharded
     */
    private function discardInterruptedMigration($dbname){
        $marker = $this->getMigrationMarkerPath($dbname);
        clearstatcache(true, $marker);
        if(!file_exists($marker)){
            return;
        }
        $removed = false;
        if(!$this->isSharded($dbname)){
            $info = json_decode((string)@file_get_contents($marker), true);
            $shards = is_array($info) && isset($info['shards']) && is_int($info['shards']) ? $info['shards'] : 0;
            for($shardId = 0; $shardId < $shards; $shardId++){
                $files = array_merge(
                    $this->listFilesWithPrefix($this->dbBase($dbname) . '_s' . $shardId . '.nonedb'),
                    $this->listFilesWithPrefix($this->dbDir . $this->hashDBName($dbname) . '_s' . $shardId . '.nonedb.sidx.')
                );
                foreach($files as $file){
                    if(preg_match('/\.orphan\.\d+$/', $file)){
                        continue;
                    }
                    if(!$removed){
                        $this->bumpEpoch($dbname);
                        $removed = true;
                    }
                    if(!$this->fsUnlink($file) && file_exists($file)){
                        throw new \RuntimeException("noneDB: could not remove " . basename($file) . " left by an interrupted migration of database '{$dbname}'");
                    }
                    unset($this->indexCache[$file], $this->fieldIndexCache[$file], $this->spatialIndexCache[$file], $this->jsonlFormatCache[$file]);
                    $this->markFileNotExists($file);
                }
            }
        }
        $this->fsUnlink($marker);
    }

    /**
     * Convert a non-sharded database to sharded format (keys preserved, indexes rebuilt per shard)
     * @param string $dbname
     * @return bool
     */
    private function migrateToSharded($dbname){
        return $this->withLock($dbname, true, function() use ($dbname){
            if($this->isSharded($dbname)){
                return true;
            }
            $legacyPath = $this->getDataPath($dbname);
            if(!$this->cachedFileExists($legacyPath)){
                return false;
            }
            $this->refuseUncommittedFile($legacyPath, 'migration');
            $this->flushPendingBuffers($dbname);

            $index = $this->readJsonlIndex($dbname, null);
            if($index === null){
                return false;
            }
            $records = $this->readSegmentRecords($dbname, null);
            // Journal first, also without indexes: a migration that dies after the meta was written leaves
            // the old file, which recovery then removes (A-6)
            $this->markIndexesPending($dbname, null, true);
            // Shards an interrupted migration of this database wrote are copies of the old file: removed
            $this->discardInterruptedMigration($dbname);
            $size = $this->shardSize;
            $maxKey = empty($records) ? -1 : max(array_keys($records));
            $nextKey = max((int)$index['n'], $maxKey + 1);
            $numShards = max(1, intdiv(max($nextKey, 1) - 1, $size) + 1);

            // Shard files that are already there belong to an earlier sharded life of this name (a dropped
            // database, a lost meta): set aside before the marker says which shard files are this migration's
            $this->setOrphanShardsAside($dbname, range(0, $numShards - 1));
            $marker = $this->getMigrationMarkerPath($dbname);
            if(!$this->writeFileAtomic($marker, json_encode(['shards' => $numShards]), true)){
                throw new \RuntimeException("noneDB: migration of database '{$dbname}' could not start (marker file)");
            }
            $this->fault('migrate:marker-written', ['db' => $dbname]);

            $byShard = array_fill(0, $numShards, []);
            foreach($records as $key => $record){
                $byShard[intdiv($key, $size)][$key] = $record;
            }

            $meta = [
                "version" => 1,
                "shardSize" => $size,
                "totalRecords" => count($records),
                "deletedCount" => 0,
                "nextKey" => $nextKey,
                "shards" => []
            ];

            foreach($byShard as $shardId => $shardRecords){
                $slots = min($nextKey, ($shardId + 1) * $size) - $shardId * $size;
                $shardIndex = $this->newJsonlIndex();
                $shardIndex['n'] = max(0, $slots);
                $buffer = $this->withExactFloats(function() use ($shardRecords, &$shardIndex){
                    $buffer = '';
                    $offset = 0;
                    foreach($shardRecords as $key => $record){
                        $json = $this->encodeRecord($record);
                        $shardIndex['p'][$key] = $offset;
                        $shardIndex['l'][$key] = strlen($json);
                        $buffer .= $json . "\n";
                        $offset += strlen($json) + 1;
                    }
                    return $buffer;
                });
                if($shardId === $numShards - 1 && $nextKey - 1 > $maxKey){
                    // Keep the highest key ever used, as compaction does: a key index rebuilt from these
                    // files after the meta was lost must never hand it out again
                    $buffer .= '{"__nonedb_deleted":' . ($nextKey - 1) . "}\n";
                    $shardIndex['t'] = 1;
                }
                $shardPath = $this->getShardPath($dbname, $shardId);
                if(!$this->writeFileAtomic($shardPath, $buffer, true)){
                    throw new \RuntimeException("noneDB: migration of database '{$dbname}' failed while writing shard {$shardId}");
                }
                $this->markFileExists($shardPath);
                $this->jsonlFormatCache[$shardPath] = true;
                $shardIndex['s'] = strlen($buffer);
                $shardIndex['e'] = bin2hex(random_bytes(4));
                $this->indexCache[$shardPath . '.jidx'] = $shardIndex;
                $this->verifiedIndexes[$shardPath . '.jidx'] = true;
                $this->writeCheckpoint($dbname, $shardId, $shardIndex);
                $this->fault('migrate:shard-written', ['db' => $dbname, 'shard' => $shardId]);

                $meta['shards'][] = [
                    "id" => $shardId,
                    "file" => "_s" . $shardId,
                    "count" => count($shardRecords),
                    "deleted" => 0
                ];
            }

            // Flip to sharded only after every shard is safely on disk
            $this->writeMeta($dbname, $meta, true);
            $this->fault('migrate:meta-written', ['db' => $dbname]);

            // Verify, then remove the non-sharded files
            $verified = 0;
            foreach(array_keys($byShard) as $shardId){
                $verified += count($this->readSegmentRecords($dbname, $shardId));
            }
            if($verified !== count($records)){
                throw new \RuntimeException("noneDB: migration of database '{$dbname}' could not be verified");
            }
            $this->bumpEpoch($dbname); // the old data file goes away
            $this->fsUnlink($legacyPath . '.jidx');
            $this->fault('migrate:legacy-index-deleted', ['db' => $dbname]);
            $this->fsUnlink($legacyPath);
            $this->fsUnlink($legacyPath . '.backup');
            $this->markFileNotExists($legacyPath);
            unset($this->indexCache[$legacyPath . '.jidx'], $this->jsonlFormatCache[$legacyPath]);
            $this->fsUnlink($marker);

            // Indexes: rebuilt per shard from the manifest
            $this->rebuildIndexesAfterMigration($dbname);
            return true;
        });
    }

    // ==========================================
    // WRITE BUFFER
    // ==========================================
    // Optional (off by default). Buffered records have no key yet; flushing renames the buffer
    // first and then inserts its records through insertRecords(), so the normal indexes and
    // sharding logic apply. Pending buffers (also those left by pre-3.2 versions) are flushed
    // before every read and write, whether or not buffering is currently enabled.

    /**
     * Get buffer file path of a database
     * @param string $dbname
     * @return string
     */
    private function getBufferPath($dbname){
        return $this->dbBase($dbname) . ".nonedb.buffer";
    }

    /**
     * Check if buffer exists and has content
     * @param string $bufferPath
     * @return bool
     */
    private function hasBuffer($bufferPath){
        clearstatcache(true, $bufferPath);
        return file_exists($bufferPath) && filesize($bufferPath) > 0;
    }

    /**
     * Number of records in a buffer file; counted once, then tracked by size
     */
    private function getBufferRecordCount($bufferPath){
        clearstatcache(true, $bufferPath);
        if(!file_exists($bufferPath)){
            return 0;
        }
        $size = filesize($bufferPath);
        if(isset(self::$bufferCounts[$bufferPath]) && self::$bufferCounts[$bufferPath][0] === $size){
            return self::$bufferCounts[$bufferPath][1];
        }
        $count = count($this->readBufferRecords($bufferPath));
        self::$bufferCounts[$bufferPath] = [$size, $count];
        return $count;
    }

    /**
     * Read all records from a buffer file (JSONL). An unterminated last line is not a record (its
     * append never completed).
     * @param string $bufferPath
     * @param bool $clean Set to false when the file holds anything that isn't a complete record line
     * @return array
     */
    private function readBufferRecords($bufferPath, &$clean = true){
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
        if(!$size || (self::$bufferEnds[$bufferPath] ?? null) === $size){
            return;
        }
        $last = $this->lastNewlineIn($bufferPath, max(0, $size - 65536), $size);
        if($last !== $size - 1){
            if($last < 0){
                $last = $this->lastNewlineIn($bufferPath, 0, $size);
            }
            $this->quarantineAndCut($dbname, 'b', $bufferPath, $last + 1, $size);
            unset(self::$bufferCounts[$bufferPath]);
        }
    }

    /**
     * Before a flushed buffer file is deleted: anything in it that wasn't a record is kept in quarantine
     */
    private function quarantineBufferRest($dbname, $file){
        clearstatcache(true, $file);
        $size = (int)@filesize($file);
        if($size > 0){
            $quarantine = $this->quarantineBytes($dbname, 'b', $file, 0, $size);
            $this->logRecoveryEvent($dbname, ['action' => 'drop-buffer', 'id' => $quarantine['id'], 'file' => basename($file),
                'quarantine' => $quarantine['file'], 'bytesVerified' => true, 'powerLossDurable' => $quarantine['durable']]);
        }
    }

    /**
     * True if the database has buffered records waiting (current or pre-3.2 layout)
     */
    private function hasPendingBuffers($dbname){
        // Under the lock the state record says it (flag 'b', set before a buffer gets records and
        // cleared after they are flushed), so normal operations don't touch the file system for this
        if($this->isVerified($dbname)){
            return !empty(self::$lockRegistry[$this->getLockPath($dbname)]['flags']['b']);
        }
        return $this->hasBuffer($this->getBufferPath($dbname)) || !empty($this->flushingFiles($dbname)) || !empty($this->legacyShardBufferFiles($dbname));
    }

    /**
     * Buffer files whose flush was interrupted: {buffer}.flushing.{firstKey} (and the pre-3.2 {buffer}.flushing)
     */
    private function flushingFiles($dbname){
        return $this->listFilesWithPrefix($this->getBufferPath($dbname) . '.flushing', '/^(\.\d+)?$/');
    }

    /**
     * Pre-3.2 per-shard buffer files (and interrupted flushes) of a database
     */
    private function legacyShardBufferFiles($dbname){
        if(!$this->isSharded($dbname)){
            return [];
        }
        return $this->listFilesWithPrefix($this->dbBase($dbname) . '_s', '/^\d+\.nonedb\.buffer(\.flushing(\.\d+)?)?$/');
    }

    /**
     * Flush buffered records into the database (exclusive lock)
     * @return int Number of records flushed
     */
    private function flushPendingBuffers($dbname){
        $base = $this->dbBase($dbname);
        // Flushing inserts records, which can migrate to shards, which flushes again: don't re-enter
        if(isset(self::$flushingNow[$base]) || !$this->hasPendingBuffers($dbname)){
            return 0;
        }
        return (int)$this->withLock($dbname, true, function() use ($dbname, $base){
            // In place before the caller appended anything, else after the caller's write committed
            return $this->runIndependent($dbname, 'flush', function() use ($dbname, $base){
                if(isset(self::$flushingNow[$base]) || !$this->hasPendingBuffers($dbname)){
                    return 0;
                }
                self::$flushingNow[$base] = true;
                try {
                    return $this->flushPendingBuffersLocked($dbname);
                } finally {
                    unset(self::$flushingNow[$base]);
                }
            });
        });
    }

    private function flushPendingBuffersLocked($dbname){
        {
            $flushed = 0;
            foreach($this->legacyShardBufferFiles($dbname) as $file){
                $flushed += $this->flushLegacyShardBuffer($dbname, $file);
            }

            $bufferPath = $this->getBufferPath($dbname);
            // Interrupted flushes first. A flush renames the buffer to {buffer}.flushing.{firstKey}: the
            // rename records atomically which keys its records get, so replaying it is idempotent.
            foreach($this->flushingFiles($dbname) as $file){
                $flushed += $this->flushBufferFile($dbname, $file, preg_match('/\.flushing\.(\d+)$/', $file, $m) ? (int)$m[1] : null);
            }
            if($this->hasBuffer($bufferPath)){
                if(!$this->dbExists($dbname)){
                    $this->createDBUnlocked($dbname);
                }
                $startKey = $this->nextKeyOf($dbname);
                $flushingPath = $bufferPath . '.flushing.' . $startKey;
                if(!$this->renameWithRetry($bufferPath, $flushingPath)){
                    throw new \RuntimeException("noneDB: could not flush the write buffer of database '{$dbname}'");
                }
                // The rename must survive a power loss before records get keys (else replayed twice)
                $this->syncForWrite(null, $this->dbDir, 'dir');
                $flushed += $this->flushBufferFile($dbname, $flushingPath, $startKey);
            }
            unset(self::$bufferCounts[$bufferPath]);
            $this->setBufferFlag($dbname, false);
            $this->bufferLastFlush[$dbname] = time();
            return $flushed;
        }
    }

    /**
     * Insert the records of one buffer file, starting at $startKey (null: pre-3.2 file, next free keys)
     * @return int
     */
    private function flushBufferFile($dbname, $file, $startKey){
        $records = $this->readBufferRecords($file, $clean);
        if(!empty($records)){
            $result = $this->insertRecords($dbname, $records, $startKey);
            if(isset($result['error'])){
                throw new \RuntimeException("noneDB: flushing the write buffer of '{$dbname}' failed: " . $result['error']);
            }
        }
        $this->commitIndependentStep($dbname); // the records are stored for good before their buffer goes
        if(!$clean){
            $this->quarantineBufferRest($dbname, $file);
        }
        $this->fsUnlink($file);
        return count($records);
    }

    /**
     * The key the next inserted record gets
     */
    private function nextKeyOf($dbname){
        if($this->isSharded($dbname)){
            $meta = $this->getCachedMeta($dbname, true);
            return $meta === null ? 0 : (int)$meta['nextKey'];
        }
        $index = $this->writableSegmentIndex($dbname, null);
        return $index === null ? 0 : (int)$index['n'];
    }
    /**
     * Flush a pre-3.2 shard buffer. Its records were already counted in the meta (count, totalRecords
     * and nextKey) when they were buffered; they take the next free slots of their shard.
     * @return int
     */
    private function flushLegacyShardBuffer($dbname, $file){
        if(!preg_match('/_s(\d+)\.nonedb\.buffer(\.flushing(\.\d+)?)?$/', $file, $m)){
            return 0;
        }
        $shardId = (int)$m[1];
        $size = $this->shardSizeOf($dbname);
        $index = $this->writableSegmentIndex($dbname, $shardId);
        if($index === null){
            $this->createJsonlDatabase($dbname, $shardId);
            $index = $this->writableSegmentIndex($dbname, $shardId);
        }
        // Same idempotent scheme as the current buffer: the first key goes into the file name
        if(preg_match('/\.flushing\.(\d+)$/', $file, $km)){
            $startKey = (int)$km[1];
        } else {
            $startKey = $shardId * $size + (int)$index['n'];
            $renamed = preg_replace('/(\.flushing)?$/', '', $file, 1) . '.flushing.' . $startKey;
            if(!$this->fsRename($file, $renamed)){
                throw new \RuntimeException("noneDB: could not flush a write buffer of database '{$dbname}'");
            }
            $file = $renamed;
        }
        $records = array_values(array_filter($this->readBufferRecords($file, $clean), function($r) use (&$clean){
            if(array_key_exists('key', $r)){
                $clean = false;
                return false;
            }
            return true;
        }));
        if(!empty($records)){
            $keyed = [];
            foreach($records as $i => $record){
                $keyed[$startKey + $i] = $record;
            }
            foreach(array_keys($keyed) as $key){
                if(isset($index['p'][$key])){
                    unset($keyed[$key]); // stored before an interruption
                }
            }
            $this->appendSegmentRecords($dbname, $shardId, $index, $keyed);
            $index['n'] = max((int)$index['n'], $startKey + count($records) - $shardId * $size);
            $this->storeSegmentIndex($dbname, $shardId, $index);
            $this->afterRecordsChanged($dbname, $shardId, [], $this->withKeys($keyed));
            $this->recountMeta($dbname);
        } else {
            $this->releaseSegmentIndex($dbname, $shardId, $index);
        }
        $this->commitIndependentStep($dbname); // the records are stored for good before their buffer goes
        if(!$clean){
            $this->quarantineBufferRest($dbname, $file);
        }
        $this->fsUnlink($file);
        return count($records);
    }

    /**
     * Append validated records to the database's write buffer (exclusive lock)
     * @return array
     */
    private function insertBuffered($dbname, array $validItems){
        if(!$this->dbExists($dbname)){
            if(!$this->autoCreateDB){
                return ["n" => 0, "error" => "Database does not exist"];
            }
            $this->createDBUnlocked($dbname);
        }
        $this->registerShutdownHandler();

        $lines = $this->withExactFloats(function() use ($validItems){
            $lines = '';
            foreach($validItems as $item){
                $lines .= $this->encodeRecord($item) . "\n";
            }
            return $lines;
        });

        $bufferPath = $this->getBufferPath($dbname);
        clearstatcache(true, $bufferPath);
        $size = (int)@filesize($bufferPath); // one check for the flush limits, the torn tail and the count
        $known = isset(self::$bufferCounts[$bufferPath]) && self::$bufferCounts[$bufferPath][0] === $size ? self::$bufferCounts[$bufferPath][1] : null;
        if($size > 0 && $this->shouldFlushBuffer($bufferPath, $dbname, $size, $known)){
            $this->flushPendingBuffers($dbname);
            clearstatcache(true, $bufferPath);
            $size = (int)@filesize($bufferPath);
        }
        if($size > 0 && (self::$bufferEnds[$bufferPath] ?? null) !== $size){
            $this->repairBufferTail($dbname, $bufferPath);
            clearstatcache(true, $bufferPath);
            $size = (int)@filesize($bufferPath);
        }
        $count = $size === 0 ? 0 : (isset(self::$bufferCounts[$bufferPath]) && self::$bufferCounts[$bufferPath][0] === $size
            ? self::$bufferCounts[$bufferPath][1] : $this->getBufferRecordCount($bufferPath));
        $this->setBufferFlag($dbname, true);
        if($this->appendToFile($bufferPath, $lines, $dbname) === false){
            if($this->appendSyncFailed){
                throw new \RuntimeException("noneDB: fsync of the write buffer of database '{$dbname}' failed; nothing was written");
            }
            return ["n" => 0, "error" => "Failed to write buffer"];
        }
        $size += strlen($lines); // the append ended there (checked against the file next time)
        self::$bufferCounts[$bufferPath] = [$size, $count + count($validItems)];
        self::$bufferEnds[$bufferPath] = $size;
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
            $ref = \WeakReference::create($this);
            register_shutdown_function(static function() use ($ref){
                $db = $ref->get();
                if($db !== null){
                    $db->flushAllBuffers();
                }
            });
            $this->shutdownHandlerRegistered = true;
        }
    }

    // ==========================================
    // FILTER MATCHING (find / update / delete)
    // ==========================================

    /**
     * Normalize a "key" filter value to a list of int keys (invalid values match nothing)
     * @return array
     */
    private function normalizeKeyFilter($value){
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
    private function matchesFilters(array $record, array $filters){
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
    private function selectRecords($dbname, array $filters){
        // Shards the global field indexes allow (null: every segment); none: nothing to read at all
        $targets = array_key_exists('key', $filters) ? null : $this->targetShardsFromGlobalIndex($dbname, $filters);
        if($targets === []){
            return [];
        }
        $segments = $this->listSegments($dbname);
        if(empty($segments)){
            return [];
        }

        $keyFilter = null;
        if(array_key_exists('key', $filters)){
            $keyFilter = $this->normalizeKeyFilter($filters['key']);
            unset($filters['key']);
            if(empty($keyFilter)){
                return [];
            }
        }

        $result = [];
        if($keyFilter !== null){
            $bySegment = [];
            foreach($keyFilter as $key){
                $segment = $this->segmentForKey($dbname, $key);
                if($segment !== false){
                    $bySegment[$segment === null ? '' : $segment][] = $key;
                }
            }
            $found = [];
            foreach($bySegment as $segment => $keys){
                $found += $this->readSegmentRecords($dbname, $segment === '' ? null : (int)$segment, $keys);
            }
            foreach($keyFilter as $key){
                if(isset($found[$key]) && $this->matchesFilters($found[$key], $filters)){
                    $result[$key] = $found[$key];
                }
            }
            return $result;
        }

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
        foreach($segments as $segment){
            $candidates = $this->fieldIndexCandidates($dbname, $segment, $filters);
            if($candidates !== null && empty($candidates)){
                continue;
            }
            foreach($this->readSegmentRecords($dbname, $segment, $candidates) as $key => $record){
                if(empty($filters) || $this->matchesFilters($record, $filters)){
                    $result[$key] = $record;
                }
            }
        }
        return $result;
    }

    /**
     * Group [key => record] by the segment that stores each key
     * @return array [segmentId|'' => [key => record]]
     */
    private function groupBySegment($dbname, array $records){
        $groups = [];
        $sharded = $this->isSharded($dbname);
        $size = $this->shardSizeOf($dbname);
        foreach($records as $key => $record){
            $groups[$sharded ? intdiv($key, $size) : ''][$key] = $record;
        }
        return $groups;
    }

    // ==========================================
    // SECONDARY INDEX FILES (v3.3.0)
    // ==========================================
    // A field index (.fx.), an R-tree (.rx.) and the global shard summaries (.gfx., .grx.) are checkpoints
    // of their segment's data, like the key index: line 1 of the file is a header with the field, the
    // data bytes "s" it covers, the crc32b "h" of the bytes before s and the epoch "e" of the key index it
    // was made with; the body follows. An index is a function of the data alone: loading it replays the
    // data lines after s (in memory), so writes never touch it, and a crash, a rollback or another
    // process's writes can't leave it stale. Once its tail reached 64 KiB (or a quarter of the index file),
    // the process that uses it writes a new checkpoint: a reader that loaded it, or a write session that
    // has it loaded (a process that only writes never loads it). An index whose epoch or bytes don't fit
    // the segment is rebuilt from the data (exclusive lock).
    // Names: {base}[_sN].nonedb.{fx|rx}.{token} and {base}.nonedb.{gfx|grx}.{token}; the token is the field
    // when it is [a-z0-9_]{1,40}, else a lower-case prefix and 12 hex digits of its sha256: at most 40
    // bytes, and fields that differ only in case ("city", "City") never share a file.
    // Files of v3.2 (.fidx., .gfidx., .sidx., .gsidx.) are read until a writer first changes the segment
    // (then its indexes are converted) or upgradeIndexes() converts them.

    private const SECONDARY_TAIL_BYTES = 65536;

    /**
     * File name part of an index on $field (at most 40 bytes)
     */
    private function indexToken($field){
        $field = (string)$field;
        if(isset(self::$indexTokens[$field])){
            return self::$indexTokens[$field];
        }
        $token = preg_match('/^[a-z0-9_]{1,40}$/', $field) === 1 ? $field
            : substr(strtolower(preg_replace('/[^A-Za-z0-9_]/', '_', $field)), 0, 24) . '-' . substr(hash('sha256', $field), 0, 12);
        if(count(self::$indexTokens) > 4096){
            self::$indexTokens = [];
        }
        return self::$indexTokens[$field] = $token;
    }

    /**
     * Path of a secondary index file: 'fx' / 'rx' per segment, 'gfx' / 'grx' per database
     */
    private function secondaryPath($dbname, $kind, $field, $shardId = null){
        if($kind === 'gfx' || $kind === 'grx'){
            return $this->dbBase($dbname) . '.nonedb.' . $kind . '.' . $this->indexToken($field);
        }
        return $this->segDataPath($dbname, $shardId) . '.' . $kind . '.' . $this->indexToken($field);
    }

    /**
     * The key index of a segment for a secondary index check: the cached one when it is known to cover
     * the data file (no function call chain on the hot path), else readJsonlIndex()
     */
    private function currentKeyIndex($dbname, $shardId){
        $path = $this->segDataPath($dbname, $shardId) . '.jidx';
        if(isset($this->verifiedIndexes[$path], $this->indexCache[$path])){
            return $this->indexCache[$path];
        }
        return $this->readJsonlIndex($dbname, $shardId);
    }

    /**
     * [header, body] of a secondary index file; null when it is missing or its header is unreadable
     */
    private function readSecondaryFile($path){
        clearstatcache(true, $path);
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return null;
        }
        $line = fgets($fp);
        $body = $line === false ? '' : (string)stream_get_contents($fp);
        fclose($fp);
        $header = $line === false ? null : json_decode(rtrim($line, "\n"), true);
        return is_array($header) ? [$header, $body] : null;
    }

    /**
     * Only the header line of a secondary index file (null: missing or unreadable)
     */
    private function readSecondaryHeader($path){
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return null;
        }
        $line = fgets($fp);
        fclose($fp);
        $header = $line === false ? null : json_decode(rtrim($line, "\n"), true);
        return is_array($header) ? $header : null;
    }

    /**
     * Write a secondary index file (header line + body). A checkpoint: no generation bump, its content is
     * the same whoever writes it.
     * @throws \RuntimeException
     */
    private function writeSecondaryFile($dbname, $path, array $header, $body){
        $line = $this->encodeJson($header);
        if($line === false || !$this->writeDerivedFile($path, $line . "\n" . $body)){
            throw new \RuntimeException("noneDB: failed to write index " . basename($path) . " of database '{$dbname}'");
        }
        $this->markFileExists($path);
    }

    /**
     * The segment's key index, with an epoch: an index without one (rebuilt without a checkpoint) gets its
     * checkpoint written first (exclusive lock), since secondary indexes name the epoch they belong to
     * @return array|null
     */
    private function keyIndexWithEpoch($dbname, $shardId){
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null || $index['e'] !== null){
            return $index;
        }
        $lock = $this->getLockPath($dbname);
        if(!isset(self::$lockRegistry[$lock]) || self::$lockRegistry[$lock]['mode'] !== LOCK_EX){
            return $index;
        }
        $this->writeCheckpoint($dbname, $shardId, $index);
        return $this->readJsonlIndex($dbname, $shardId);
    }

    /**
     * True when a secondary index header belongs to the segment as it is: same field, the epoch of its
     * key index, and the bytes it covers unchanged (checked once per epoch and offset)
     */
    private function secondaryFits($dbname, $shardId, array $header, $kind, $field){
        if(($header['kind'] ?? null) !== $kind || ($header['field'] ?? null) !== (string)$field
            || !isset($header['s'], $header['e'], $header['h']) || !is_int($header['s']) || !is_string($header['e'])){
            return false;
        }
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null || $index['e'] === null || $header['e'] !== $index['e'] || $header['s'] > (int)$index['s']){
            return false;
        }
        $dataPath = $this->segDataPath($dbname, $shardId);
        $checked = $index['e'] . ':' . $header['s'];
        if((self::$secondaryChecked[$dataPath] ?? null) === $checked){
            return true;
        }
        if($this->segmentCrc($dataPath, $header['s']) !== $header['h']){
            return false;
        }
        self::$secondaryChecked[$dataPath] = $checked;
        return true;
    }

    /**
     * Records of the complete data lines [$from, $to) of a segment, in file order: [[key, record or null
     * for a tombstone], ...]. Lines that are neither are skipped (salvaged ones count, as in the key index).
     */
    private function segmentTail($dbname, $shardId, $from, $to){
        if($to <= $from){
            return [];
        }
        $dataPath = $this->segDataPath($dbname, $shardId);
        $cached = self::$tailCache[$dataPath] ?? null;
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
        $base = $shardId === null ? 0 : $shardId * $this->shardSizeOf($dbname);
        $limit = $shardId === null ? null : $base + $this->shardSizeOf($dbname);
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
            $entry = $this->salvageLine($json, $from + $pos - strlen($json) - 1, $empty, $base, $limit);
            if($entry !== null){
                $rows[] = $entry[0] === 't' ? [$entry[1], null] : [$entry[1], json_decode(substr($json, $entry[2], $entry[3]), true)];
            }
        }
        if(count(self::$tailCache) > 64){
            self::$tailCache = [];
        }
        self::$tailCache[$dataPath] = [$from, $to, $rows];
        return $rows;
    }

    /**
     * A global index (gfx / grx) about to be rewritten, which stamps it with the current lock epoch: when
     * it was written under another epoch (a data file may have been replaced since), only the entries that
     * still fit their shard are kept, so the new stamp vouches for no stale entry
     */
    private function freshGlobalEntries($dbname, array $global){
        $lockPath = $this->getLockPath($dbname);
        if($global['le'] !== null && isset(self::$lockRegistry[$lockPath]) && $global['le'] === $this->registryEpoch($lockPath)){
            return $global;
        }
        foreach($global['sh'] as $shardId => $entry){
            if($this->globalEntryFits($dbname, $shardId, $entry, null)){
                continue;
            }
            unset($global['sh'][$shardId]);
            if(isset($global['map'])){
                self::mapWithoutShard($global['map'], $shardId);
            }
        }
        return $global;
    }

    /**
     * True when the tail of a secondary index (data after the bytes it covers) is long enough to write a
     * new checkpoint of it
     */
    private function secondaryTailIsLong($covered, $end, $fileBytes){
        return $end - $covered >= max(self::SECONDARY_TAIL_BYTES, intdiv((int)$fileBytes, 4));
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
    private function maybeSecondaryReaderCheckpoint($dbname, $shardId, $kind, $field, array $index){
        $lock = $this->getLockPath($dbname);
        if($index['e'] === null || !empty($index['legacy'])
            || !$this->secondaryTailIsLong((int)$index['ck'], (int)$index['s'], (int)$index['bytes'])){
            return false;
        }
        if(self::$noWrites > 0 || self::$multiLock > 0){
            return true; // explain(), checkIntegrity(), a join: due, the next plain read of this process writes it
        }
        if(!isset(self::$lockRegistry[$lock]) || self::$lockRegistry[$lock]['mode'] !== LOCK_SH){
            return false; // a write session: checkpointSegmentSecondaries() at its end
        }
        try {
            if($kind === 'fx'){
                $this->writeFieldIndex($dbname, $field, $index, $shardId);
                if($shardId !== null){
                    $this->updateGlobalFieldShard($dbname, $field, $shardId, $index);
                }
            } else {
                $this->writeSpatialIndex($dbname, $field, $index, $shardId);
                if($shardId !== null){
                    $this->updateGlobalSpatialShard($dbname, $field, $shardId, $index);
                }
            }
        } catch(\Throwable $e){
            if($e->getCode() === self::LOCK_FAILED){
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
    private function checkpointSegmentSecondaries($dbname, $shardId){
        $manifest = $this->getIndexManifest($dbname);
        if(empty($manifest['fields']) && empty($manifest['spatial'])){
            return;
        }
        $key = $this->keyIndexWithEpoch($dbname, $shardId);
        if($key === null || $key['e'] === null || !$this->segmentIsConverted($dbname, $shardId)){
            return;
        }
        foreach(['fx' => $manifest['fields'], 'rx' => $manifest['spatial']] as $kind => $fields){
            foreach($fields as $field){
                $path = $this->secondaryPath($dbname, $kind, $field, $shardId);
                $cached = $kind === 'fx' ? ($this->fieldIndexCache[$path] ?? null) : ($this->spatialIndexCache[$path] ?? null);
                if($cached === null || $cached['e'] !== $key['e'] || !empty($cached['legacy'])){
                    continue; // not loaded here (or of another epoch): the reader that loads it checkpoints it
                }
                $covered = (int)$cached['ck'];
                $bytes = (int)$cached['bytes'];
                if(!$this->secondaryTailIsLong($covered, (int)$key['s'], $bytes)){
                    continue;
                }
                try {
                    if($kind === 'fx'){
                        $index = $this->ensureSegmentFieldIndex($dbname, $shardId, $field);
                        if($index !== null && empty($index['legacy'])){
                            $this->writeFieldIndex($dbname, $field, $index, $shardId);
                            if($shardId !== null){
                                $this->updateGlobalFieldShard($dbname, $field, $shardId, $index);
                            }
                        }
                    } else {
                        $index = $this->ensureSegmentSpatialIndex($dbname, $field, $shardId);
                        if(empty($index['legacy'])){
                            $this->writeSpatialIndex($dbname, $field, $index, $shardId);
                            if($shardId !== null){
                                $this->updateGlobalSpatialShard($dbname, $field, $shardId, $index);
                            }
                        }
                    }
                } catch(\Throwable $e){
                    if($e->getCode() === self::LOCK_FAILED){
                        throw $e;
                    }
                    // never needed for correctness: the next load replays a longer tail
                }
            }
        }
    }
    // ==========================================
    // FIELD INDEXES
    // ==========================================
    // Which fields are indexed is recorded in {base}.nonedb.indexes ("manifest", v2 since v3.3:
    // {"v":2,"fields","spatial","spatialOptions","fmt":3}; "fmt":3 once no index file of v3.2 is left).
    // A segment's field index {segment}.nonedb.fx.{token} (see SecondaryIndex) maps the exact identity of
    // a value (valueKey(): "n", "t", "f", "i42", "d<hex double>", "sAnkara") to the keys that hold it:
    //   {"v":3,"kind":"fx","field":"city","s":48213,"e":"9f2c41d0","h":"…","mk":812,"c":37,"n":800}
    //   {"n":[2,4],"f":10,"i-3":[1,9],"i42":7,"d4045000000000000":11,"sAnkara":3,"sIstanbul":[0,5,9]}
    // Buckets are exact (1, 1.0, "1" and true are different values, as for ===) and don't depend on the
    // precision, serialize_precision or locale settings. Values that are arrays are not indexed. The
    // global field index {base}.nonedb.gfx.{token} of a sharded database maps each value to the shards
    // that hold it as of each shard's covered bytes; a shard's newer lines are checked directly.
    // v3.2's {segment}.nonedb.fidx.{name} (string buckets) is still read for segments this version
    // hasn't written yet (see convertSegmentIndexes); its global .gfidx. is never used to skip shards.

    private function getIndexManifestPath($dbname){
        return $this->dbBase($dbname) . '.nonedb.indexes';
    }

    /**
     * Indexed fields of a database: ['fields', 'spatial', 'spatialOptions', 'fmt' => bool (every segment
     * converted), 'collides' => fields whose v3.2 files are shared with another field]
     * Databases created before v3.2 have no manifest; it is derived from their index files.
     */
    private function getIndexManifest($dbname){
        $path = $this->getIndexManifestPath($dbname);
        if(isset($this->fieldIndexCache[$path])){
            self::$cacheTouch[$path] = ++self::$cacheTick;
            return $this->fieldIndexCache[$path];
        }
        clearstatcache(true, $path);
        $content = is_file($path) ? @file_get_contents($path) : false;
        $manifest = $content === false ? false : json_decode($content, true);
        if($content === false && $this->isVerified($dbname)){
            $manifest = ['fmt' => 3]; // no index list: no indexes (pre-3.2 databases got theirs when verified)
        } elseif(!is_array($manifest)){
            // Corrupt, or zero-length after a power loss: derived from the index files that exist
            $manifest = $this->deriveIndexManifest($dbname);
        }
        $fields = array_values(array_unique(array_map('strval', $manifest['fields'] ?? [])));
        $manifest = [
            'fields' => $fields,
            'spatial' => array_values(array_unique(array_map('strval', $manifest['spatial'] ?? []))),
            'spatialOptions' => is_array($manifest['spatialOptions'] ?? null) ? $manifest['spatialOptions'] : [],
            'fmt' => ($manifest['fmt'] ?? null) === 3,
            'collides' => $this->collidingFields($fields),
        ];
        $this->fieldIndexCache[$path] = $manifest;
        self::$cacheGrew = true;
        return $manifest;
    }

    private function saveIndexManifest($dbname, array $manifest){
        $file = [
            'v' => 2,
            'fields' => array_values(array_unique($manifest['fields'] ?? [])),
            'spatial' => array_values(array_unique($manifest['spatial'] ?? [])),
        ];
        $file['spatialOptions'] = array_intersect_key($manifest['spatialOptions'] ?? [], array_flip($file['spatial']));
        if(!empty($manifest['fmt'])){
            $file['fmt'] = 3; // v3.2 ignores it, and drops it when it writes the list (its files are back then)
        }
        $path = $this->getIndexManifestPath($dbname);
        if(empty($file['fields']) && empty($file['spatial'])){
            $this->fsUnlink($path);
        } elseif(!$this->atomicWrite($path, $file, false, 'durable')){
            throw new \RuntimeException("noneDB: failed to write index list of database '{$dbname}'");
        }
        $this->fieldIndexCache[$path] = ['fields' => $file['fields'], 'spatial' => $file['spatial'],
            'spatialOptions' => $file['spatialOptions'], 'fmt' => !empty($manifest['fmt']),
            'collides' => $this->collidingFields($file['fields'])];
    }

    /**
     * Databases without a readable index list: field names from the index files that exist (headers of
     * v3.3 files, "field" of v3.2 files; v3.2 file names may have been lossy)
     */
    private function deriveIndexManifest($dbname){
        $base = $this->dbBase($dbname);
        $hashBase = $this->dbDir . $this->hashDBName($dbname);
        $fields = [];
        $spatial = [];
        $options = [];
        foreach(array_merge($this->listFilesWithPrefix($base . '.nonedb.'), $this->listFilesWithPrefix($base . '_s', '/^\d+\.nonedb\./')) as $file){
            if(preg_match('/\.nonedb\.(fx|rx)\.[^.]+$/', $file, $m)){
                $header = $this->readSecondaryHeader($file);
                if(is_array($header) && isset($header['field']) && is_string($header['field'])){
                    if($m[1] === 'fx'){
                        $fields[] = $header['field'];
                    } else {
                        $spatial[] = $header['field'];
                        if(isset($header['ns'])){
                            $options[$header['field']] = ['nodeSize' => (int)$header['ns']];
                        }
                    }
                }
            } elseif(preg_match('/\.fidx\.(.+)$/', $file, $m)){
                $index = $this->atomicRead($file, null);
                $fields[] = is_array($index) && isset($index['field']) && is_string($index['field']) ? $index['field'] : $this->decodeIndexFieldName($m[1]);
            }
        }
        foreach(array_merge($this->listFilesWithPrefix($hashBase . '.nonedb.sidx.'), $this->listFilesWithPrefix($hashBase . '_s', '/^\d+\.nonedb\.sidx\./')) as $file){
            $index = $this->atomicRead($file, null);
            if(is_string($index)){
                $index = json_decode($index, true); // pre-3.2 files were JSON-encoded twice
            }
            if(is_array($index) && isset($index['field']) && is_string($index['field'])){
                $spatial[] = $index['field'];
            } elseif(preg_match('/\.sidx\.(.+)$/', $file, $m)){
                $spatial[] = $this->decodeIndexFieldName($m[1]);
            }
        }
        return ['fields' => array_values(array_unique($fields)), 'spatial' => array_values(array_unique($spatial)), 'spatialOptions' => $options];
    }

    /**
     * File name part of a v3.2 index file: the field itself when it matches [A-Za-z0-9_]+, else "~" + hex
     */
    private function encodeIndexFieldName($field){
        $field = (string)$field;
        return preg_match('/^[A-Za-z0-9_]+$/', $field) ? $field : '~' . bin2hex($field);
    }

    private function decodeIndexFieldName($name){
        if(isset($name[0]) && $name[0] === '~' && ctype_xdigit(substr($name, 1)) && strlen($name) % 2 === 1){
            return hex2bin(substr($name, 1));
        }
        return $name;
    }

    /**
     * Fields whose v3.2 index files are shared with another indexed field: same name ignoring case (one
     * file on case-insensitive file systems). Their v3.2 files are never used.
     * @return array field => true
     */
    private function collidingFields(array $fields){
        $collides = [];
        $byLower = [];
        foreach($fields as $field){
            $byLower[strtolower($field)][] = $field;
        }
        foreach($byLower as $group){
            if(count($group) > 1){
                foreach($group as $field){
                    $collides[$field] = true;
                }
            }
        }
        return $collides;
    }

    /**
     * Error for a database whose index file names would be longer than file systems allow (255 bytes):
     * the field takes at most 40 bytes (indexToken), the database name the rest. Null when fine.
     */
    private function indexNameError($dbname, $field){
        $longest = 20 + 1 + strlen($dbname) + 2 + 10 + strlen('.nonedb.gfx.') + strlen($this->indexToken($field)) + 4 + 8;
        if($longest > 255){
            return "Index file names of database '{$dbname}' would be longer than 255 bytes (database name too long)";
        }
        return null;
    }

    /**
     * Path of a segment's field index (v3.3)
     */
    private function getFieldIndexPath($dbname, $field, $shardId = null){
        return $this->secondaryPath($dbname, 'fx', $field, $shardId);
    }

    /**
     * Path of a segment's v3.2 field index
     */
    private function getLegacyFieldIndexPath($dbname, $field, $shardId = null){
        return $this->segDataPath($dbname, $shardId) . ".fidx." . $this->encodeIndexFieldName($field);
    }

    /**
     * Exact bucket of a value (see valueKey()); arrays are not indexed
     */
    private function fieldIndexValueKey($value){
        return self::valueKey($value);
    }

    /**
     * Bucket of a v3.2 field index: values of different types share one (1, "1", 1.0), floats depend on
     * the precision setting
     */
    private function legacyFieldIndexKey($value){
        if($value === null){
            return '__null__';
        }
        if(is_bool($value)){
            return $value ? '__true__' : '__false__';
        }
        if(is_float($value) && $value == 0.0){
            return '0';
        }
        return (string)$value;
    }

    /**
     * True if a value can be stored in a field index
     */
    private function isIndexableValue($value){
        return is_scalar($value) || is_null($value);
    }

    private function newFieldIndex($field){
        return ['kind' => 'fx', 'field' => (string)$field, 's' => 0, 'ck' => -1, 'e' => null, 'b' => [], 'mk' => -1,
            'rev' => null, 'views' => null, 'bytes' => 0, 'legacy' => false];
    }

    /**
     * Build a segment's field index from its data (covers the key index's bytes)
     * @return array
     */
    private function buildSegmentFieldIndex($dbname, $shardId, $field){
        $key = $this->keyIndexWithEpoch($dbname, $shardId);
        $index = $this->newFieldIndex($field);
        // fieldIndexApply() inlined: every key occurs once, so a value is only ever added
        $field = (string)$field;
        $buckets = [];
        foreach($this->readSegmentRecords($dbname, $shardId) as $k => $record){
            $k = (int)$k;
            if($k > $index['mk']){
                $index['mk'] = $k;
            }
            if(!isset($record[$field]) && !array_key_exists($field, $record)){
                continue;
            }
            $value = $record[$field];
            if(is_string($value)){
                $buckets['s' . $value][] = $k;
            } elseif(is_scalar($value) || $value === null){
                $buckets[self::valueKey($value)][] = $k;
            }
        }
        foreach($buckets as $bucket => $keys){
            $index['b'][$bucket] = count($keys) === 1 ? $keys[0] : $keys;
        }
        $index['mk'] = $key === null ? -1 : max($index['mk'], empty($key['p']) ? -1 : max(array_keys($key['p'])));
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
            ? self::valueKey($record[$field]) : null;
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
            return; // the value didn't change: nothing to do (T2-6)
        }
        if($old !== null){
            $keys = $index['b'][$old];
            if(is_int($keys)){
                unset($index['b'][$old]);
                $index['views'] = null;
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
        if(!isset($index['b'][$bucket])){
            $index['b'][$bucket] = $key;
            $index['views'] = null;
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
    private function readFieldIndex($dbname, $field, $shardId = null){
        // The segment's key index (currentKeyIndex() inlined: this is the hot path of every lookup)
        $dataPath = $this->segDataPath($dbname, $shardId);
        $key = isset($this->verifiedIndexes[$dataPath . '.jidx']) ? ($this->indexCache[$dataPath . '.jidx'] ?? null) : null;
        if($key === null && ($key = $this->readJsonlIndex($dbname, $shardId)) === null){
            return null;
        }
        $path = $dataPath . '.fx.' . $this->indexToken($field);
        self::$cacheTouch[$path] = ++self::$cacheTick;
        if(isset($this->fieldIndexCache[$path])){
            $index = $this->fieldIndexCache[$path];
            if($index['s'] === (int)$key['s'] && $index['e'] === $key['e'] && $index['e'] !== null){
                if(isset($index['due']) && self::$noWrites === 0 && self::$multiLock === 0){
                    unset($index['due'], $this->fieldIndexCache[$path]['due']); // a checkpoint a read-only load put off
                    if($this->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'fx', $field, $index)){
                        $this->fieldIndexCache[$path]['due'] = true;
                    }
                    return $this->fieldIndexCache[$path];
                }
                return $index; // the usual case: nothing written since
            }
            if($index['e'] !== null && $index['e'] === $key['e'] && $index['s'] <= (int)$key['s']){
                if($index['s'] < (int)$key['s']){
                    unset($this->fieldIndexCache[$path], $index['due']); // sole owner while it changes
                    $this->replayFieldIndex($dbname, $shardId, $index, (int)$key['s']);
                    $this->fieldIndexCache[$path] = $index;
                    if($this->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'fx', $field, $index)){
                        $this->fieldIndexCache[$path]['due'] = true;
                    }
                }
                return $this->fieldIndexCache[$path];
            }
            unset($this->fieldIndexCache[$path]);
        }
        $file = $this->readSecondaryFile($path);
        if($file === null || !$this->secondaryFits($dbname, $shardId, $file[0], 'fx', $field)){
            return null;
        }
        $buckets = json_decode($file[1], true);
        if(!is_array($buckets)){
            return null;
        }
        $index = $this->newFieldIndex($field);
        $index['b'] = $buckets;
        $index['s'] = $file[0]['s'];
        $index['ck'] = $file[0]['s'];
        $index['e'] = $file[0]['e'];
        $index['mk'] = (int)($file[0]['mk'] ?? -1);
        $index['bytes'] = strlen($file[1]);
        $this->replayFieldIndex($dbname, $shardId, $index, (int)$key['s']);
        $this->fieldIndexCache[$path] = $index;
        self::$cacheGrew = true;
        if($this->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'fx', $field, $index)){
            $this->fieldIndexCache[$path]['due'] = true;
        }
        return $this->fieldIndexCache[$path];
    }

    private function replayFieldIndex($dbname, $shardId, array &$index, $to){
        foreach($this->segmentTail($dbname, $shardId, $index['s'], $to) as [$k, $record]){
            $this->fieldIndexApply($index, $k, $record);
        }
        $index['s'] = $to;
    }

    /**
     * Read a segment's v3.2 field index (only for a segment this version hasn't written yet)
     * @return array|null ['legacy' => true, 'values' => [legacy bucket => keys]]
     */
    private function readLegacyFieldIndex($dbname, $field, $shardId){
        if(isset($this->getIndexManifest($dbname)['collides'][(string)$field])){
            return null; // shares its file with another field
        }
        $path = $this->getLegacyFieldIndexPath($dbname, $field, $shardId);
        if(array_key_exists($path, $this->fieldIndexCache)){
            return $this->fieldIndexCache[$path];
        }
        $index = $this->atomicRead($path, null);
        if(!is_array($index) || ($index['v'] ?? 1) < 2 || !isset($index['values']) || !is_array($index['values'])
            || (isset($index['field']) && $index['field'] !== (string)$field)){
            $index = null;
        } else {
            $index = ['legacy' => true, 'field' => (string)$field, 'values' => $index['values']];
        }
        $this->fieldIndexCache[$path] = $index;
        self::$cacheGrew = true;
        return $index;
    }

    /**
     * Write a field index checkpoint (exclusive lock or a reader: the content is the data's)
     */
    private function writeFieldIndex($dbname, $field, array &$index, $shardId = null){
        if($index['e'] === null){
            return false;
        }
        $path = $this->getFieldIndexPath($dbname, $field, $shardId);
        $crc = $this->segmentCrc($this->segDataPath($dbname, $shardId), (int)$index['s']);
        if($crc === null){
            return false;
        }
        $buckets = self::canonicalBuckets($index['b']);
        $body = $this->encodeJson(empty($buckets) ? new \stdClass() : $buckets);
        if($body === false){
            return false;
        }
        $n = 0;
        foreach($buckets as $keys){
            $n += is_int($keys) ? 1 : count($keys);
        }
        $header = ['v' => 3, 'kind' => 'fx', 'field' => (string)$field, 's' => (int)$index['s'], 'e' => $index['e'], 'h' => $crc,
            'mk' => (int)$index['mk'], 'c' => count($buckets), 'n' => $n, 'created' => time()];
        $this->writeSecondaryFile($dbname, $path, $header, $body . "\n");
        $index['b'] = $buckets;
        $index['ck'] = (int)$index['s'];
        $index['bytes'] = strlen($body) + 1;
        $index['views'] = null;
        $this->fieldIndexCache[$path] = $index;
        self::$cacheGrew = true;
        return true;
    }

    /**
     * Buckets in the fixed order of an index file, so the file is the same whoever writes it: bucket ids
     * in byte order (ksort, in C), keys of a bucket ascending. Range queries sort their own views
     * (fieldIndexViews), so no order by value is needed here.
     */
    private static function canonicalBuckets(array $buckets){
        ksort($buckets, SORT_STRING);
        foreach($buckets as &$keys){
            if(is_array($keys)){
                sort($keys);
            }
        }
        unset($keys);
        return $buckets;
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
     * Delete a field's index files of a segment (v3.3 and v3.2)
     */
    private function deleteFieldIndexFile($dbname, $field, $shardId = null){
        $ok = true;
        foreach([$this->getFieldIndexPath($dbname, $field, $shardId), $this->getLegacyFieldIndexPath($dbname, $field, $shardId)] as $path){
            unset($this->fieldIndexCache[$path]);
            $this->markFileNotExists($path);
            clearstatcache(true, $path);
            if(file_exists($path) && !$this->fsUnlink($path)){
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Get list of indexed fields for a database
     * @return array
     */
    private function getIndexedFields($dbname, $shardId = null){
        return $this->getIndexManifest($dbname)['fields'];
    }

    /**
     * Check if a field has an index
     * @return bool
     */
    private function isFieldIndexed($dbname, $field, $shardId = null){
        return in_array((string)$field, $this->getIndexedFields($dbname), true);
    }

    /**
     * Invalidate field index cache for a database
     */
    private function invalidateFieldIndexCache($dbname, $field = null, $shardId = null){
        $prefix = $this->dbDir . $this->hashDBName($dbname);
        foreach(array_keys($this->fieldIndexCache) as $key){
            if(strpos($key, $prefix) === 0){
                unset($this->fieldIndexCache[$key]);
            }
        }
    }

    /**
     * A segment's field index: the current one, a v3.2 one while the segment isn't converted, or built
     * from the data (exclusive lock). Null when none can be used (the caller scans).
     * @return array|null
     */
    private function ensureSegmentFieldIndex($dbname, $shardId, $field){
        $index = $this->readFieldIndex($dbname, $field, $shardId);
        if($index !== null){
            return $index;
        }
        if(!$this->segmentIsConverted($dbname, $shardId)){
            $legacy = $this->readLegacyFieldIndex($dbname, $field, $shardId);
            if($legacy !== null){
                return $legacy;
            }
        }
        if(self::$noWrites > 0 || self::$multiLock > 0){
            return null; // checkIntegrity(), explain(), a snapshot: nothing is built, the segment is scanned
        }
        return $this->withLock($dbname, true, function() use ($dbname, $shardId, $field){
            $index = $this->readFieldIndex($dbname, $field, $shardId);
            if($index === null){
                $index = $this->buildSegmentFieldIndex($dbname, $shardId, $field);
                $this->writeFieldIndex($dbname, $field, $index, $shardId);
                if($shardId !== null){
                    $this->updateGlobalFieldShard($dbname, $field, $shardId, $index);
                }
            }
            return $index;
        });
    }

    /**
     * Keys of a field index with exactly this value (v3.2 index: candidates of the same loose bucket;
     * null for a float there, whose bucket depends on the precision setting)
     * @return array|null
     */
    private function fieldIndexKeys(array $index, $value){
        if(!empty($index['legacy'])){
            if(is_float($value)){
                return null;
            }
            return array_map('intval', (array)($index['values'][$this->legacyFieldIndexKey($value)] ?? []));
        }
        $keys = $index['b'][is_string($value) ? 's' . $value : self::valueKey($value)] ?? [];
        return is_int($keys) ? [$keys] : $keys;
    }

    /**
     * Candidate keys of a segment for equality filters, from field indexes.
     * Null means "no usable index, scan the segment".
     * @return array|null
     */
    private function fieldIndexCandidates($dbname, $shardId, array $filters){
        if(!$this->fieldIndexEnabled || empty($filters)){
            return null;
        }
        $indexed = $this->getIndexedFields($dbname);
        if(empty($indexed)){
            return null;
        }
        $candidates = null;
        foreach($filters as $field => $value){
            if($field === 'key' || !$this->isIndexableValue($value) || !in_array((string)$field, $indexed, true)){
                continue;
            }
            try {
                $index = $this->ensureSegmentFieldIndex($dbname, $shardId, $field);
            } catch(\Throwable $e){
                if($e->getCode() === self::LOCK_FAILED){
                    throw $e; // never read without the lock
                }
                $index = null; // can't be built: scan (B-1)
            }
            $keys = $index === null ? null : $this->fieldIndexKeys($index, $value);
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

    /**
     * Field indexes follow the data by themselves (replayed when used): kept for the write paths
     */
    private function applyFieldIndexChanges($dbname, $shardId, array $removed, array $added){
    }

    // ==================== RANGE VIEWS (I3) ====================

    /**
     * Candidate keys of a segment whose field value may satisfy every range condition ($gt, $gte, $lt,
     * $lte => operand): a superset, the caller checks every record. Null: no usable index (scan).
     * @param array $conditions [operator => operand]
     * @return array|null
     */
    private function fieldIndexRangeCandidates($dbname, $shardId, $field, array $conditions){
        if(!$this->fieldIndexEnabled || !$this->isFieldIndexed($dbname, $field)){
            return null;
        }
        try {
            $index = $this->ensureSegmentFieldIndex($dbname, $shardId, $field);
        } catch(\Throwable $e){
            if($e->getCode() === self::LOCK_FAILED){
                throw $e;
            }
            return null;
        }
        if($index === null || !empty($index['legacy'])){
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
     * @return array ['num' => [[float value, bucket], ...], 'str' => [[string, bucket], ...]]
     */
    /**
     * The first and last values of fieldIndexViews() (numbers: ints, floats and numeric strings by value;
     * strings by byte order) without building the views
     * @return array [num low, num high, string low, string high], nulls when there are none
     */
    private static function fieldIndexBounds(array $index){
        if(!empty($index['views'])){
            $num = $index['views']['num'];
            $str = $index['views']['str'];
            return [$num ? $num[0][0] : null, $num ? $num[count($num) - 1][0] : null,
                $str ? $str[0][0] : null, $str ? $str[count($str) - 1][0] : null];
        }
        $nlo = $nhi = $slo = $shi = null;
        $keys = array_keys($index['b']);
        $other = preg_grep('/\Ai/', $keys, PREG_GREP_INVERT);
        $ints = $other ? array_diff_key($keys, $other) : $keys;
        if($ints){
            // Without the "i": min()/max() compare integer strings as numbers, in C
            $values = substr_replace($ints, '', 0, 1);
            $nlo = (float)(int)min($values);
            $nhi = (float)(int)max($values);
        }
        foreach(preg_grep('/\Ad/', $other) as $bucket){
            $number = (float)self::bucketNumber($bucket);
            if($nlo === null || $number < $nlo){
                $nlo = $number;
            }
            if($nhi === null || $number > $nhi){
                $nhi = $number;
            }
        }
        $strings = preg_grep('/\As/', $other);
        if($strings){
            sort($strings, SORT_STRING);
            $slo = substr(reset($strings), 1);
            $shi = substr(end($strings), 1);
            // Numeric strings start with optional white space and sign, then a digit or ".digit"
            foreach(preg_grep('/\As[ \t\n\r\v\f]*[+-]?\.?[0-9]/', $strings) as $bucket){
                $trimmed = rtrim(substr($bucket, 1), " \t\n\r\v\f");
                if(!is_numeric($trimmed)){
                    continue;
                }
                $number = (float)$trimmed;
                if($nlo === null || $number < $nlo){
                    $nlo = $number;
                }
                if($nhi === null || $number > $nhi){
                    $nhi = $number;
                }
            }
        }
        return [$nlo, $nhi, $slo, $shi];
    }

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
                $trimmed = rtrim($s, " \t\n\r\v\f");
                if(is_numeric($trimmed)){
                    $numValues[] = (float)$trimmed;
                    $numIds[] = $bucket;
                }
            }
        }
        array_multisort($numValues, SORT_NUMERIC, $numIds, SORT_STRING);
        array_multisort($strValues, SORT_STRING, $strIds, SORT_STRING);
        $num = [];
        foreach($numValues as $i => $value){
            $num[] = [$value, $numIds[$i]];
        }
        $str = [];
        foreach($strValues as $i => $value){
            $str[] = [$value, $strIds[$i]];
        }
        return ['num' => $num, 'str' => $str];
    }

    /**
     * Buckets whose values may compare as $op with $operand (noneDBQuery::compareValues semantics: numbers
     * and numeric strings numerically, other strings by byte order; null, booleans and arrays compare
     * with nothing). Ties of different types are included (rechecked by the caller).
     * @return array|null bucket => true; null when the operand can't be compared
     */
    private function rangeBuckets(array $views, $op, $operand){
        if(is_float($operand) && is_nan($operand)){
            return []; // NAN matches nothing
        }
        if(is_int($operand) || is_float($operand) || (is_string($operand) && is_numeric(rtrim($operand, " \t\n\r\v\f")))){
            $x = (float)(is_string($operand) ? rtrim($operand, " \t\n\r\v\f") : $operand);
            $list = $views['num'];
            $found = [];
            // A wider band at the boundary: values above 2^53 compare as floats here and as ints there
            $slack = abs($x) >= 9007199254740992.0 ? max(1.0, abs($x) * 4.0e-16) : 0.0;
            foreach($this->rangeSlice($list, $op, $x, $slack) as $entry){
                $found[$entry[1]] = true;
            }
            if(is_string($operand)){
                // A numeric string operand compares with non-numeric strings by byte order too
                foreach($this->rangeSliceStrings($views['str'], $op, $operand) as $entry){
                    $found[$entry[1]] = true;
                }
            }
            return $found;
        }
        if(is_string($operand)){
            $found = [];
            foreach($this->rangeSliceStrings($views['str'], $op, $operand) as $entry){
                $found[$entry[1]] = true;
            }
            return $found;
        }
        return null;
    }

    /**
     * Entries of a sorted numeric view on the operator's side of $x (binary search; $slack widens it).
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
                if($strict ? $list[$mid][0] <= $bound : $list[$mid][0] < $bound){
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            return array_slice($list, $lo);
        }
        $bound = $x + $slack;
        $lo = 0;
        $hi = $n;
        while($lo < $hi){
            $mid = ($lo + $hi) >> 1;
            if($strict ? $list[$mid][0] < $bound : $list[$mid][0] <= $bound){
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return array_slice($list, 0, $lo);
    }

    /**
     * Entries of the sorted string view on the operator's side of $x (the string $x itself only for $gte
     * and $lte)
     */
    private function rangeSliceStrings(array $list, $op, $x){
        $n = count($list);
        $lo = 0;
        $hi = $n;
        $strict = $op === '$gt' || $op === '$lt';
        if($op === '$gt' || $op === '$gte'){
            while($lo < $hi){
                $mid = ($lo + $hi) >> 1;
                $cmp = strcmp($list[$mid][0], $x);
                if($strict ? $cmp <= 0 : $cmp < 0){
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            return array_slice($list, $lo);
        }
        while($lo < $hi){
            $mid = ($lo + $hi) >> 1;
            $cmp = strcmp($list[$mid][0], $x);
            if($strict ? $cmp < 0 : $cmp <= 0){
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return array_slice($list, 0, $lo);
    }

    // ==================== GLOBAL FIELD INDEX (shard skip) ====================
    // {base}.nonedb.gfx.{token}: header {"v":3,"kind":"gfx","field","le": lock epoch when written,
    // "sh": {shard: {"s","e","h","nlo","nhi","slo","shi"}}}, body {value id: shard | [shards]}. An entry says which
    // values the shard's data bytes [0, s) hold. While the lock epoch is the one recorded, no data file was
    // replaced, so every entry still describes its shard; otherwise each entry is checked against its
    // shard (epoch and checksum). A shard is skipped only when it has an entry that fits, the value isn't
    // in it, and the shard's lines after s don't have it either. No entry: never skipped. Entries are
    // updated when a shard's field index is checkpointed, never per write.

    private function getGlobalFieldIndexPath($dbname, $field){
        return $this->secondaryPath($dbname, 'gfx', $field);
    }

    private function getLegacyGlobalFieldIndexPath($dbname, $field){
        return $this->dbBase($dbname) . ".nonedb.gfidx." . $this->encodeIndexFieldName($field);
    }

    /**
     * Read the global field index: ['le', 'sh' => [shard => entry], 'map' => [value id => [shards]]]
     * @return array|null
     */
    private function readGlobalFieldIndex($dbname, $field, $path = null){
        $path = $path ?? $this->getGlobalFieldIndexPath($dbname, $field);
        self::$cacheTouch[$path] = ++self::$cacheTick;
        if(array_key_exists($path, $this->fieldIndexCache)){
            return $this->fieldIndexCache[$path];
        }
        $file = $this->readSecondaryFile($path);
        $global = null;
        if($file !== null && ($file[0]['kind'] ?? null) === 'gfx' && ($file[0]['field'] ?? null) === (string)$field && is_array($file[0]['sh'] ?? null)){
            $map = json_decode($file[1], true);
            if(is_array($map)){
                $global = ['le' => $file[0]['le'] ?? null, 'sh' => [], 'map' => $map];
                foreach($file[0]['sh'] as $shardId => $entry){
                    if(is_array($entry) && isset($entry['s'], $entry['e'], $entry['h'])){
                        $global['sh'][(int)$shardId] = $entry;
                    }
                }
            }
        }
        $this->fieldIndexCache[$path] = $global;
        unset($this->globalSkip[$this->getLockPath($dbname)][$path]);
        self::$cacheGrew = true;
        return $global;
    }

    /**
     * Remove a shard from a global field index map (value bucket => shard id, or [shard ids] when several
     * shards hold the value: a unique value costs no array), except for the buckets in $keep
     */
    private static function mapWithoutShard(array &$map, $shardId, ?array $keep = null){
        foreach($map as $bucket => $held){
            if($keep !== null && isset($keep[$bucket])){
                continue;
            }
            if($held === $shardId){
                unset($map[$bucket]);
            } elseif(is_array($held) && in_array($shardId, $held, true)){
                $held = array_values(array_diff($held, [$shardId]));
                if(count($held) === 1){
                    $map[$bucket] = $held[0];
                } else {
                    $map[$bucket] = $held;
                }
            }
        }
    }

    /**
     * Write the global field index (exclusive lock)
     */
    private function writeGlobalFieldIndex($dbname, $field, array $global){
        $path = $this->getGlobalFieldIndexPath($dbname, $field);
        $lock = self::$lockRegistry[$this->getLockPath($dbname)] ?? null;
        $global['le'] = $lock === null ? null : $this->registryEpoch($this->getLockPath($dbname));
        $map = $global['map'];
        ksort($map, SORT_STRING);
        $body = $this->encodeJson(empty($map) ? new \stdClass() : $map);
        if($body === false){
            throw new \RuntimeException("noneDB: failed to write global field index '{$field}' of database '{$dbname}'");
        }
        $sh = [];
        foreach($global['sh'] as $shardId => $entry){
            $sh[(string)$shardId] = $entry;
        }
        $this->writeSecondaryFile($dbname, $path, ['v' => 3, 'kind' => 'gfx', 'field' => (string)$field, 'le' => $global['le'],
            'sh' => empty($sh) ? new \stdClass() : $sh], $body . "\n");
        $this->fieldIndexCache[$path] = $global;
        unset($this->globalSkip[$this->getLockPath($dbname)][$path], self::$lockRegistry[$this->getLockPath($dbname)]['globals'][$path]);
    }

    /**
     * Record a shard's values (from its field index, at the bytes it covers) in the global field index
     */
    private function updateGlobalFieldShard($dbname, $field, $shardId, array $index){
        $global = $this->freshGlobalEntries($dbname, $this->readGlobalFieldIndex($dbname, $field) ?? ['le' => null, 'sh' => [], 'map' => []]);
        if($this->applyGlobalFieldShard($global, $dbname, $shardId, $index, false)){
            $this->deferGlobalWrite($dbname, 'gfx', $field, $global);
        }
    }

    /**
     * Keep a changed global field or spatial index in the cache and write it when the lock session ends
     * (flushPendingGlobals()); without a lock at once
     */
    private function deferGlobalWrite($dbname, $kind, $field, array $global){
        $lock = $this->getLockPath($dbname);
        if(!isset(self::$lockRegistry[$lock])){
            if($kind === 'gfx'){
                $this->writeGlobalFieldIndex($dbname, $field, $global);
            } else {
                $this->writeGlobalSpatialIndex($dbname, $field, $global);
            }
            return;
        }
        if($kind === 'gfx'){
            $path = $this->getGlobalFieldIndexPath($dbname, $field);
            $this->fieldIndexCache[$path] = $global;
            unset($this->globalSkip[$lock][$path]);
        } else {
            $path = $this->getGlobalSpatialIndexPath($dbname, $field);
            $this->globalSpatialIndexCache[$path] = $global;
        }
        self::$lockRegistry[$lock]['globals'][$path] = [$kind, $field, $this->registryEpoch($lock)];
    }

    /**
     * Record a shard's values in a global field index in memory. $fresh: a rebuild (the shard isn't in it
     * yet, shards come in ascending order): no shard list is searched and every bucket gets a list
     * @return bool False when the index can't be recorded (a v3.2 index, no epoch, data file gone)
     */
    private function applyGlobalFieldShard(array &$global, $dbname, $shardId, array $index, $fresh){
        if(!empty($index['legacy']) || $index['e'] === null){
            return false;
        }
        $crc = $this->segmentCrc($this->segDataPath($dbname, $shardId), (int)$index['s']);
        if($crc === null){
            return false;
        }
        if(!$fresh && isset($global['sh'][$shardId])){
            self::mapWithoutShard($global['map'], $shardId, $index['b']);
        }
        // No local copy of a shard list: appending to an array also held by a variable copies it first
        // (with a value in every shard, O(shards) per append)
        $map = &$global['map'];
        if($fresh){
            // Lists only; rebuildGlobalFieldIndex() turns the single ones into ints at the end
            foreach(array_keys($index['b']) as $bucket){
                $map[$bucket][] = $shardId;
            }
        } else {
            foreach(array_keys($index['b']) as $bucket){
                if(!isset($map[$bucket])){
                    $map[$bucket] = $shardId;
                } elseif(is_int($map[$bucket])){
                    if($map[$bucket] !== $shardId){
                        $map[$bucket] = [$map[$bucket], $shardId];
                    }
                } elseif(!in_array($shardId, $map[$bucket], true)){
                    $map[$bucket][] = $shardId;
                }
            }
        }
        unset($map);
        $entry = ['s' => (int)$index['s'], 'e' => $index['e'], 'h' => $crc];
        [$nlo, $nhi, $slo, $shi] = self::fieldIndexBounds($index);
        if($nlo !== null){
            $entry['nlo'] = bin2hex(pack('E', $nlo));
            $entry['nhi'] = bin2hex(pack('E', $nhi));
        }
        if($slo !== null){
            $entry['slo'] = $slo;
            $entry['shi'] = $shi;
        }
        $global['sh'][$shardId] = $entry;
        return true;
    }

    /**
     * Rebuild the global field index from the shard field indexes (exclusive lock): built in memory and
     * written once (once per shard was O(shards²))
     */
    private function rebuildGlobalFieldIndex($dbname, $field){
        $path = $this->getGlobalFieldIndexPath($dbname, $field);
        unset($this->fieldIndexCache[$path], $this->globalSkip[$this->getLockPath($dbname)][$path]);
        $global = ['le' => null, 'sh' => [], 'map' => []];
        $shards = $this->listSegments($dbname);
        sort($shards);
        foreach($shards as $shardId){
            $index = $this->readFieldIndex($dbname, $field, $shardId);
            if($index === null){
                $index = $this->buildSegmentFieldIndex($dbname, $shardId, $field);
                $this->writeFieldIndex($dbname, $field, $index, $shardId);
            }
            $this->applyGlobalFieldShard($global, $dbname, $shardId, $index, true);
        }
        foreach($global['map'] as &$held){
            if(count($held) === 1){
                $held = $held[0];
            }
        }
        unset($held);
        $this->writeGlobalFieldIndex($dbname, $field, $global);
        return $this->fieldIndexCache[$path] ?? $global;
    }

    /**
     * True when a global entry still describes its shard's data bytes [0, s)
     */
    private function globalEntryFits($dbname, $shardId, array $entry, $lockEpoch){
        $lock = self::$lockRegistry[$this->getLockPath($dbname)] ?? null;
        $dataPath = $this->segDataPath($dbname, $shardId);
        if($lockEpoch !== null && $lock !== null && $this->registryEpoch($this->getLockPath($dbname)) === $lockEpoch){
            return true; // no data file was replaced since the entry was written
        }
        if(($this->dataEpochs[$dataPath] ?? null) === null){
            $this->dataEpochs[$dataPath] = $this->segmentEpochOnDisk($dbname, $shardId);
        }
        if($this->dataEpochs[$dataPath] !== $entry['e']){
            return false;
        }
        $checked = $entry['e'] . ':' . $entry['s'];
        if((self::$secondaryChecked[$dataPath] ?? null) === $checked){
            return true;
        }
        if($this->segmentCrc($dataPath, (int)$entry['s']) !== $entry['h']){
            return false;
        }
        self::$secondaryChecked[$dataPath] = $checked;
        return true;
    }

    /**
     * A segment's data epoch without loading its key index: the cached index, else the checkpoint header
     */
    private function segmentEpochOnDisk($dbname, $shardId){
        $path = $this->getJsonlIndexPath($dbname, $shardId);
        if(isset($this->indexCache[$path])){
            return $this->indexCache[$path]['e'];
        }
        $fp = @fopen($path, 'rb');
        if($fp === false){
            return false;
        }
        $head = (string)fread($fp, 512);
        fclose($fp);
        return preg_match('/"e":"([0-9a-f]{8})"/', $head, $m) === 1 ? $m[1] : false;
    }

    /**
     * Current data size of a shard: its key index's when this process has it verified (its own writes
     * keep it current), else the file's (cached until a write)
     */
    private function shardDataSize($dbname, $shardId, $dataPath = null){
        $dataPath = $dataPath ?? $this->segDataPath($dbname, $shardId);
        if(isset($this->verifiedIndexes[$dataPath . '.jidx'], $this->indexCache[$dataPath . '.jidx'])){
            return (int)$this->indexCache[$dataPath . '.jidx']['s'];
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
            $fresh = $global['le'] !== null && isset(self::$lockRegistry[$lockPath]) && $global['le'] === $this->registryEpoch($lockPath);
            $check = [];
            $prefix = $this->dbBase($dbname) . '_s';
            foreach($this->listSegments($dbname) as $shardId){
                $entry = $global['sh'][$shardId] ?? null;
                if($entry === null || (!$fresh && !$this->globalEntryFits($dbname, $shardId, $entry, null))){
                    $check[$shardId] = false;
                } elseif($this->shardDataSize($dbname, $shardId, $prefix . $shardId . '.nonedb') > (int)$entry['s']){
                    $check[$shardId] = true;
                }
            }
            $state = ['seq' => $seq, 'check' => $check];
        } else {
            // This process appended to some shards since: their entries still fit (the bytes they cover
            // didn't change), their newer lines are checked; a new shard has no entry
            $prefix = $this->dbBase($dbname) . '_s';
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
    private function shardTailMayMatch($dbname, $shardId, array $entry, callable $match){
        if($this->shardDataSize($dbname, $shardId) <= (int)$entry['s']){
            return false;
        }
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null){
            return true;
        }
        foreach($this->segmentTail($dbname, $shardId, (int)$entry['s'], (int)$index['s']) as [$k, $record]){
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
    private function targetShardsFromGlobalIndex($dbname, array $filters){
        if(!$this->fieldIndexEnabled || !$this->isSharded($dbname) || empty($filters)){
            return null;
        }
        $manifest = $this->getIndexManifest($dbname);
        $targets = null;
        $base = null;
        foreach($filters as $field => $value){
            if($field === 'key' || !(is_scalar($value) || $value === null) || !in_array((string)$field, $manifest['fields'], true)){
                continue;
            }
            // Paths built here, not by the helpers: this runs for every lookup of a sharded database
            $base = $base ?? $this->dbBase($dbname);
            $lockPath = $base . '.nonedb.lock';
            $path = $base . '.nonedb.gfx.' . (self::$indexTokens[(string)$field] ?? $this->indexToken($field));
            if(isset($this->fieldIndexCache[$path])){
                $global = $this->fieldIndexCache[$path]; // readGlobalFieldIndex()'s cache hit, inlined
                self::$cacheTouch[$path] = ++self::$cacheTick;
            } else {
                $global = $this->readGlobalFieldIndex($dbname, $field, $path);
            }
            if($global === null){
                if(self::$noWrites > 0 || self::$multiLock > 0 || !$manifest['fmt']){
                    continue; // checkIntegrity(), a snapshot, or v3.2 files not all converted: build nothing, skip nothing
                }
                $global = $this->withLock($dbname, true, function() use ($dbname, $field){
                    return $this->rebuildGlobalFieldIndex($dbname, $field);
                });
                if($global === null){
                    continue;
                }
            }
            // The shards whose entry can't rule a value out (worked out once per state of the data)
            $state = $this->globalSkip[$lockPath][$path] ?? null;
            $check = $state !== null && $state['seq'] === ($this->segWrites[$lockPath]['seq'] ?? 0)
                ? $state['check'] : $this->globalCheckShards($dbname, $global, $path, $lockPath);
            // The value's holders, and those of the shards to check that may hold it
            $held = $global['map'][is_string($value) ? 's' . $value : self::valueKey($value)] ?? null;
            if(empty($check)){
                $shards = $held === null ? [] : (is_int($held) ? [$held] : $held);
                if(count($shards) > 1){
                    sort($shards);
                }
            } else {
                $shards = $held === null ? [] : (is_int($held) ? [$held => true] : array_fill_keys($held, true));
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
            }
            $targets = $targets === null ? $shards : array_values(array_intersect($targets, $shards));
        }
        return $targets;
    }

    /**
     * Delete the global field index files (v3.3 and v3.2)
     */
    private function deleteGlobalFieldIndex($dbname, $field){
        foreach([$this->getGlobalFieldIndexPath($dbname, $field), $this->getLegacyGlobalFieldIndexPath($dbname, $field)] as $path){
            clearstatcache(true, $path);
            if(file_exists($path)){
                $this->fsUnlink($path);
            }
            unset($this->fieldIndexCache[$path], $this->globalSkip[$this->getLockPath($dbname)][$path]);
        }
    }

    // ==================== CONVERSION OF v3.2 INDEX FILES ====================

    /**
     * True when no index file of v3.2 is left for the segment (or the index list says none is left)
     */
    private function segmentIsConverted($dbname, $shardId){
        $manifest = $this->getIndexManifest($dbname);
        if($manifest['fmt'] || (empty($manifest['fields']) && empty($manifest['spatial']))){
            return true;
        }
        $dataPath = $this->segDataPath($dbname, $shardId);
        if(!isset($this->convertedSegments[$dataPath])){
            $legacy = false;
            foreach($manifest['fields'] as $field){
                $legacy = $legacy || $this->cachedFileExists($this->getLegacyFieldIndexPath($dbname, $field, $shardId));
            }
            foreach($manifest['spatial'] as $field){
                $legacy = $legacy || $this->cachedFileExists($this->getLegacySpatialIndexPath($dbname, $field, $shardId));
            }
            $this->convertedSegments[$dataPath] = !$legacy;
        }
        return $this->convertedSegments[$dataPath];
    }

    /**
     * Before a writer first changes a segment that still has v3.2 index files (exclusive lock): its field
     * indexes and R-trees are built in the v3.3 formats from the data, then its v3.2 files go. The first
     * conversion of a database also removes the v3.2 global field indexes (v3.2 trusts them to skip shards,
     * but this version doesn't update them; it rebuilds missing ones), the last one the global spatial
     * indexes, and marks the index list converted ("fmt": 3).
     * @return bool True when the segment was converted now
     */
    private function convertSegmentIndexes($dbname, $shardId){
        if($this->segmentIsConverted($dbname, $shardId)){
            return false;
        }
        $manifest = $this->getIndexManifest($dbname);
        foreach($manifest['fields'] as $field){
            $this->deleteLegacyGlobalFieldIndex($dbname, $field);
        }
        $this->keyIndexWithEpoch($dbname, $shardId);
        foreach($manifest['fields'] as $field){
            $index = $this->readFieldIndex($dbname, $field, $shardId) ?? $this->buildSegmentFieldIndex($dbname, $shardId, $field);
            $this->writeFieldIndex($dbname, $field, $index, $shardId);
            if($shardId !== null){
                $this->updateGlobalFieldShard($dbname, $field, $shardId, $index);
            }
            $legacy = $this->getLegacyFieldIndexPath($dbname, $field, $shardId);
            $this->fsUnlink($legacy);
            $this->markFileNotExists($legacy);
            unset($this->fieldIndexCache[$legacy]);
        }
        foreach($manifest['spatial'] as $field){
            $index = $this->readSpatialIndex($dbname, $field, $shardId) ?? $this->buildSegmentSpatialIndex($dbname, $field, $shardId,
                isset($manifest['spatialOptions'][$field]['nodeSize']) ? ['nodeSize' => $manifest['spatialOptions'][$field]['nodeSize']] : []);
            $this->writeSpatialIndex($dbname, $field, $index, $shardId);
            if($shardId !== null){
                $this->updateGlobalSpatialShard($dbname, $field, $shardId, $index);
            }
            $legacy = $this->getLegacySpatialIndexPath($dbname, $field, $shardId);
            $this->fsUnlink($legacy);
            $this->markFileNotExists($legacy);
            unset($this->spatialIndexCache[$legacy]);
        }
        $this->convertedSegments[$this->segDataPath($dbname, $shardId)] = true;
        $this->fault('convert:segment', ['db' => $dbname, 'shard' => $shardId]);
        foreach($this->listSegments($dbname) as $segment){
            if(!$this->segmentIsConverted($dbname, $segment)){
                return true;
            }
        }
        // Every segment converted: the v3.2 global spatial indexes go last, then the index list says so
        foreach($manifest['spatial'] as $field){
            $legacy = $this->getLegacyGlobalSpatialIndexPath($dbname, $field);
            $this->fsUnlink($legacy);
            unset($this->globalSpatialIndexCache[$legacy]);
        }
        $manifest['fmt'] = true;
        $this->saveIndexManifest($dbname, $manifest);
        return true;
    }

    /**
     * A state record without an epoch (exclusive lock): v3.2 may have used the database and built its own
     * index files again, which it trusts but this version doesn't update. With v3.2 files of a segment
     * left, the index list stops saying "converted": each segment is checked again and converted before
     * it is next written to. Only v3.2 global files left: they go (v3.2 rebuilds missing ones from its
     * segment files, none of which are left).
     */
    private function forgetIndexConversion($dbname){
        $manifest = $this->getIndexManifest($dbname);
        if(!$manifest['fmt'] || (empty($manifest['fields']) && empty($manifest['spatial']))){
            return;
        }
        foreach($this->listSegments($dbname) as $segment){
            foreach($manifest['fields'] as $field){
                if(is_file($this->getLegacyFieldIndexPath($dbname, $field, $segment))){
                    $manifest['fmt'] = false;
                }
            }
            foreach($manifest['spatial'] as $field){
                if(is_file($this->getLegacySpatialIndexPath($dbname, $field, $segment))){
                    $manifest['fmt'] = false;
                }
            }
        }
        if(!$manifest['fmt']){
            $this->saveIndexManifest($dbname, $manifest);
            return;
        }
        foreach($manifest['fields'] as $field){
            $this->deleteLegacyGlobalFieldIndex($dbname, $field);
        }
        foreach($manifest['spatial'] as $field){
            $legacy = $this->getLegacyGlobalSpatialIndexPath($dbname, $field);
            if(is_file($legacy)){
                $this->fsUnlink($legacy);
            }
            unset($this->globalSpatialIndexCache[$legacy]);
        }
    }

    private function deleteLegacyGlobalFieldIndex($dbname, $field){
        $path = $this->getLegacyGlobalFieldIndexPath($dbname, $field);
        if($this->cachedFileExists($path)){
            $this->fsUnlink($path);
            $this->markFileNotExists($path);
        }
        unset($this->fieldIndexCache[$path]);
    }

    /**
     * After a migration to sharded format: the non-sharded index files go; every shard's field and
     * spatial indexes are built from the data
     */
    private function rebuildIndexesAfterMigration($dbname){
        $manifest = $this->getIndexManifest($dbname);
        foreach($manifest['fields'] as $field){
            $this->deleteFieldIndexFile($dbname, $field, null);
            $this->deleteGlobalFieldIndex($dbname, $field);
            foreach($this->listSegments($dbname) as $shardId){
                $index = $this->buildSegmentFieldIndex($dbname, $shardId, $field);
                $this->writeFieldIndex($dbname, $field, $index, $shardId);
            }
            $this->rebuildGlobalFieldIndex($dbname, $field);
        }
        foreach($manifest['spatial'] as $field){
            $this->rebuildSpatialIndexForDb($dbname, $field);
        }
        $manifest['fmt'] = true;
        $this->saveIndexManifest($dbname, $manifest);
    }

    // ==========================================
    // QUERY PLANS AND CURSORS (v3.3.0)
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
                if(!$this->fieldIndexEnabled || !$this->isIndexableValue($node[2]) || !$this->isFieldIndexed($dbname, $node[1])){
                    return null;
                }
                return $this->fieldIndexCandidates($dbname, $shardId, [$node[1] => $node[2]]);
            case 'in':
                if(!$this->fieldIndexEnabled || !$this->isFieldIndexed($dbname, $node[1])){
                    return null;
                }
                $keys = [];
                foreach($node[2] as $value){
                    if(!$this->isIndexableValue($value)){
                        return null;
                    }
                    $found = $this->fieldIndexCandidates($dbname, $shardId, [$node[1] => $value]);
                    if($found === null){
                        return null;
                    }
                    foreach($found as $k){
                        $keys[$k] = $k;
                    }
                }
                return array_values($keys);
            case 'range':
                return $this->fieldIndexRangeCandidates($dbname, $shardId, $node[1], [$node[2] => $node[3]]);
            case 'keys':
                $index = $this->readJsonlIndex($dbname, $shardId);
                $keys = [];
                foreach($node[1] as $k){
                    if($index !== null && isset($index['p'][$k])){
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
        if(!$this->isSharded($dbname)){
            return null;
        }
        switch($node[0]){
            case 'eq':
                if(!$this->isIndexableValue($node[2])){
                    return null;
                }
                $shards = $this->targetShardsFromGlobalIndex($dbname, [$node[1] => $node[2]]);
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
                $size = $this->shardSizeOf($dbname);
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
     * gets each batch in key order and returns false to stop. Caller holds the lock.
     * @param bool $whole The visitor never stops (count, top-k): each segment is read in one go
     * @return array ['segments', 'skipped', 'indexed', 'scanned', 'recordsRead', 'plan']
     */
    private function scanRecords($dbname, array $plan, callable $visit, $whole = false){
        $stats = ['segments' => 0, 'skipped' => 0, 'indexed' => 0, 'scanned' => 0, 'recordsRead' => 0, 'plan' => []];
        $segments = $this->listSegments($dbname);
        if(empty($segments)){
            return $stats;
        }
        $shards = $plan[0] === 'any' ? null : $this->planShards($dbname, $plan);
        foreach($segments as $segment){
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
                $index = $this->readJsonlIndex($dbname, $segment);
                $keys = $index === null ? [] : array_keys($index['p']);
            } else {
                $stats['indexed']++;
            }
            if(count($stats['plan']) < 50){
                $stats['plan'][] = ['segment' => $segment, 'access' => $access, 'candidates' => count($keys)];
            }
            if(empty($keys)){
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
            $n = count($keys);
            $batch = $whole ? $n : 32;
            for($i = 0; $i < $n; $i += $batch, $batch = min(4096, $batch * 4)){
                $records = $this->readSegmentRecords($dbname, $segment, $access === 'scan' && $i === 0 && $batch >= $n ? null : array_slice($keys, $i, $batch));
                $stats['recordsRead'] += count($records);
                if($visit($records) === false){
                    return $stats;
                }
            }
        }
        return $stats;
    }

    /**
     * explain(): what scanRecords() would do, without reading records or building anything (an index that
     * a read would build counts as a scan)
     */
    private function explainPlan($dbname, array $plan){
        $explain = function() use ($dbname, $plan){
            $out = ['sharded' => $this->dbExists($dbname) && $this->isSharded($dbname), 'segments' => ['total' => 0, 'skipped' => 0, 'indexed' => 0, 'scanned' => 0],
                'plan' => [], 'estimatedRead' => 0];
            if(!$this->dbExists($dbname)){
                return $out;
            }
            $shards = $plan[0] === 'any' ? null : $this->planShards($dbname, $plan);
            foreach($this->listSegments($dbname) as $segment){
                $out['segments']['total']++;
                if($shards !== null && !isset($shards[$segment])){
                    $out['segments']['skipped']++;
                    $entry = ['segment' => $segment, 'access' => 'skip', 'candidates' => 0];
                } else {
                    $keys = $plan[0] === 'any' ? null : $this->planCandidates($dbname, $segment, $plan);
                    if($keys === null){
                        $out['segments']['scanned']++;
                        $index = $this->readJsonlIndex($dbname, $segment);
                        $entry = ['segment' => $segment, 'access' => 'scan', 'candidates' => $index === null ? 0 : count($index['p'])];
                    } else {
                        $out['segments']['indexed']++;
                        $entry = ['segment' => $segment, 'access' => 'index', 'candidates' => count($keys)];
                    }
                }
                $out['estimatedRead'] += $entry['candidates'];
                if(count($out['plan']) < 50){
                    $out['plan'][] = $entry;
                }
            }
            $out['planTruncated'] = $out['segments']['total'] > 50;
            return $out;
        };
        self::$noWrites++;
        try {
            return $this->hasLockFile($dbname) ? $this->withLock($dbname, false, $explain) : $explain();
        } finally {
            self::$noWrites--;
        }
    }


    /**
     * Visit the records matching find()-style filters (field => value) in key order, stopping when
     * $visit returns false. False when it can't (a key filter keeps its own order: find() handles it;
     * a missing database without autoCreateDB): then the caller uses find(). Caller holds the lock.
     */
    private function scanMatches($dbname, array $filters, callable $visit, $whole = false){
        if(array_key_exists('key', $filters)){
            return false;
        }
        if(!$this->dbExists($dbname)){
            return !$this->autoCreateDB ? false : true;
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
        $this->scanRecords($dbname, $plan, function(array $records) use ($filters, $visit){
            foreach($records as $key => $record){
                if($this->matchesFilters($record, $filters) && $visit($key, $record) === false){
                    return false;
                }
            }
            return true;
        }, $whole);
        return true;
    }

    // ==========================================
    // PUBLIC INDEX API
    // ==========================================

    /**
     * Enable or disable indexing (kept for backward compatibility; key lookups always use the .jidx index)
     * @param bool $enable
     */
    public function enableIndexing($enable = true){
        $this->indexEnabled = (bool)$enable;
    }

    /**
     * Check if indexing is enabled
     * @return bool
     */
    public function isIndexingEnabled(){
        return $this->indexEnabled;
    }

    /**
     * Rebuild the key indexes (.jidx) of a database from its data files.
     * Normal operation never needs this; it is a recovery tool after files were copied or restored by hand.
     * @param string $dbname
     * @return array ['success' => bool, 'totalRecords' => int, 'time' => string]
     */
    public function rebuildIndex($dbname){
        $dbname = $this->normalizeDbName($dbname);
        return $this->withLock($dbname, true, function() use ($dbname){
            return $this->rebuildIndexUnlocked($dbname);
        });
    }

    private function rebuildIndexUnlocked($dbname){
        $start = microtime(true);
        $this->flushPendingBuffers($dbname);
        $segments = $this->listSegments($dbname);
        if(empty($segments)){
            return ['success' => false, 'error' => 'Database does not exist'];
        }
        $total = 0;
        foreach($segments as $segment){
            $this->ensureJsonlFormat($dbname, $segment);
            $total += count($this->rebuildSegmentIndexFromData($dbname, $segment)['p']);
        }
        if($this->isSharded($dbname)){
            $this->recountMeta($dbname);
        }
        // After a manual restore the field and spatial indexes are as stale as the key index (B-8)
        $this->rebuildSecondaryIndexes($dbname, $segments);
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname){
            return $this->getIndexInfoUnlocked($dbname);
        });
    }

    private function getIndexInfoUnlocked($dbname){
        $segments = $this->listSegments($dbname);
        if(empty($segments)){
            return null;
        }
        $total = 0;
        $created = null;
        $updated = null;
        foreach($segments as $segment){
            $index = $this->readJsonlIndex($dbname, $segment);
            if($index === null){
                continue;
            }
            $total += count($index['p']);
            $created = $created === null ? ($index['created'] ?? null) : min($created, $index['created'] ?? $created);
            $updated = max((int)$updated, (int)($index['updated'] ?? 0));
        }
        $sharded = $this->isSharded($dbname);
        return [
            'exists' => true,
            'version' => 4,
            'created' => $created,
            'updated' => $updated,
            'totalRecords' => $total,
            'sharded' => $sharded,
            'path' => $sharded ? $this->getMetaPath($dbname) : $this->getJsonlIndexPath($dbname, null)
        ];
    }

    /**
     * Recovery log of a database (v3.3.0): what crash recovery and maintenance did to its files, oldest
     * first. Every entry has "t" (time) and "action": "cut-tail" / "drop-line" (bytes removed from a data
     * file, kept in the "quarantine" file), "salvaged" / "skip-line" (a damaged line found while
     * rebuilding an index), "deferred-failed" (compaction, buffer flush or migration after a committed
     * write failed; it runs again later). What reads of this process found and no write session logged
     * yet is included with "persisted" => false (reads never write).
     * @param string $dbname
     * @param int $limit Newest entries to return
     * @return array
     */
    /**
     * Check a database against its own data, read-only (v3.3.0): every line of every data file is
     * decoded; the key index, field indexes, spatial indexes and shard meta are compared with what the
     * data says. Nothing is written, repaired, flushed or recovered (rebuildIndex() repairs).
     * @param string $dbname
     * @return array ['ok' => bool, 'segments', 'lines', 'records', 'badLines' => [[segment, offset, length]],
     *               'tornTail', 'indexMismatches', 'bufferedRecords', 'pendingRecovery', 'quarantineFiles',
     *               'recoveryLog' => last entries]
     */
    public function checkIntegrity($dbname){
        $dbname = $this->normalizeDbName($dbname);
        $check = function() use ($dbname){
            return $this->checkIntegrityLocked($dbname);
        };
        self::$noWrites++;
        try {
            // Without a lock file (pre-3.2 or missing database) nothing is locked: taking it would create one
            return $this->hasLockFile($dbname) ? $this->withLock($dbname, false, $check) : $check();
        } finally {
            self::$noWrites--;
        }
    }

    private function checkIntegrityLocked($dbname){
        $report = ['ok' => true, 'database' => $dbname, 'exists' => false, 'segments' => 0, 'lines' => 0, 'records' => 0,
            'badLines' => [], 'tornTail' => [], 'indexMismatches' => [], 'bufferedRecords' => 0, 'pendingRecovery' => false,
            'quarantineFiles' => [], 'recoveryLog' => []];
        $lock = self::$lockRegistry[$this->getLockPath($dbname)] ?? null;
        if($lock !== null && (!empty($lock['flags']['j']) || empty($lock['flags']['v']))){
            $report['pendingRecovery'] = true; // the next writer or reader recovers (journal / pre-3.2 checks)
        }
        $base = $this->dbBase($dbname);
        $metaPath = $this->getMetaPath($dbname);
        clearstatcache(true, $metaPath);
        $sharded = file_exists($metaPath);
        $meta = $sharded ? $this->readMeta($dbname) : null;
        if($sharded && $meta === null){
            $report['indexMismatches'][] = ['segment' => null, 'index' => 'meta', 'detail' => 'meta file unreadable'];
        }
        $segments = $sharded ? array_map(function($f) use ($base){ return (int)substr($f, strlen($base) + 2); },
            $this->listFilesWithPrefix($base . '_s', '/^\d+\.nonedb$/')) : (is_file($this->getDataPath($dbname)) ? [null] : []);
        sort($segments);
        $report['exists'] = !empty($segments) || $sharded;
        $size = $meta !== null ? (int)$meta['shardSize'] : $this->shardSize;
        if($sharded && $meta === null){
            try {
                $size = (int)$this->deriveShardMeta($dbname)['shardSize']; // as recovery would rebuild it
            } catch(\RuntimeException $e){
            }
        }
        $manifest = $this->deriveOrReadManifest($dbname);
        $liveTotal = 0;
        $maxKey = -1;
        $byShard = [];

        foreach($segments as $shardId){
            $report['segments']++;
            $seg = $shardId === null ? 'main' : $shardId;
            $dataPath = $this->segDataPath($dbname, $shardId);
            $live = [];
            $offset = 0;
            $fp = @fopen($dataPath, 'rb');
            while($fp !== false && ($line = fgets($fp)) !== false){
                if(substr($line, -1) !== "\n"){
                    $report['tornTail'][] = ['segment' => $seg, 'offset' => $offset, 'length' => strlen($line)];
                    break;
                }
                $report['lines']++;
                $entry = $this->classifyLine(substr($line, 0, -1), $shardId === null ? 0 : $shardId * $size,
                    $shardId === null ? null : ($shardId + 1) * $size);
                if($entry === null){
                    $report['badLines'][] = [$seg, $offset, strlen($line)];
                } elseif($entry[0] === 't'){
                    unset($live[$entry[1]]);
                    $maxKey = max($maxKey, $entry[1]);
                } else {
                    $live[$entry[1]] = [$offset, $entry[3]];
                    $maxKey = max($maxKey, $entry[1]);
                }
                $offset += strlen($line);
            }
            if($fp !== false){
                fclose($fp);
            }
            ksort($live, SORT_NUMERIC);
            $liveTotal += count($live);
            $byShard[$shardId === null ? 'main' : $shardId] = count($live);

            // Key index: its checkpoint (or v3.2 index) fits the data file, and with the complete lines after it
            // gives the same records at the same places. A missing one is rebuilt from the data on the next
            // access (normal: nothing lost), a broken one too, but that is reported.
            $file = $this->atomicRead($dataPath . '.jidx', null);
            clearstatcache(true, $dataPath);
            $fileSize = (int)@filesize($dataPath);
            $index = null;
            if($file === null && @filesize($dataPath . '.jidx') > 0){
                $report['indexMismatches'][] = ['segment' => $seg, 'index' => 'key', 'detail' => 'unreadable (rebuilt on next access)'];
            } elseif(is_array($file) && isset($file['p'], $file['l'], $file['s']) && is_array($file['p']) && is_array($file['l'])){
                if(!$this->checkpointFits($dataPath, (int)$file['s'], $fileSize, $file['h'] ?? null)){
                    $report['indexMismatches'][] = ['segment' => $seg, 'index' => 'key', 'detail' => 'checkpoint does not fit the data file (rebuilt on next access)'];
                } else {
                    $index = $file;
                }
            } elseif(is_array($file) && isset($file['o']) && is_array($file['o'])){
                if(!isset($file['s'])){
                    $index = ['p' => [], 'l' => []] + $file; // before v3.2: compared as it is
                    foreach($file['o'] as $k => $location){
                        $index['p'][(int)$k] = (int)($location[0] ?? -1);
                        $index['l'][(int)$k] = (int)($location[1] ?? -1);
                    }
                    $index['s'] = $offset;
                } elseif((int)$file['s'] !== $offset){
                    $report['indexMismatches'][] = ['segment' => $seg, 'index' => 'key', 'detail' => "covers {$file['s']} bytes, data has $offset complete bytes"];
                } else {
                    $index = $this->indexFromV3($file);
                }
            } elseif($file !== null){
                $report['indexMismatches'][] = ['segment' => $seg, 'index' => 'key', 'detail' => 'not an index (rebuilt on next access)'];
            }
            if($index !== null){
                $index += ['n' => 0, 'd' => 0, 't' => 0, 'tl' => 0];
                $this->replaySegment($dbname, $shardId, $index, $fileSize, null, false);
                $indexed = $this->indexEntries($index);
                if($indexed != $live){
                    $report['indexMismatches'][] = ['segment' => $seg, 'index' => 'key', 'detail' => count(array_diff_key($live, $indexed)) . ' records not indexed, '
                        . count(array_diff_key($indexed, $live)) . ' indexed records not in the data or elsewhere'];
                }
            }

            // Field and spatial indexes against the records (read in file order: compared in key order)
            $records = $this->readJsonlRecordsBatch($dataPath, array_map(function($location){ return $location[0]; }, $live),
                array_map(function($location){ return $location[1]; }, $live));
            ksort($records, SORT_NUMERIC);
            // Field and spatial indexes: one that fits the segment (its epoch, the bytes it covers), with the
            // lines after it replayed, must hold exactly the records; one that doesn't fit is rebuilt when
            // used (not damage). v3.2 files of a segment this version hasn't written are compared as well.
            $keyEpoch = is_array($index) && isset($index['e']) && is_string($index['e']) ? $index['e'] : null;
            foreach($manifest['fields'] as $field){
                $expected = [];
                $legacyExpected = [];
                foreach($records as $key => $record){
                    if(array_key_exists($field, $record) && $this->isIndexableValue($record[$field])){
                        $expected[self::valueKey($record[$field])][] = (int)$key;
                        $legacyExpected[$this->legacyFieldIndexKey($record[$field])][] = (int)$key;
                    }
                }
                $actual = $this->integrityFieldBuckets($dbname, $shardId, $field, $keyEpoch, $offset);
                if($actual === null){
                    $legacy = $this->atomicRead($this->getLegacyFieldIndexPath($dbname, $field, $shardId), null);
                    if(!is_array($legacy) || !isset($legacy['values']) || !is_array($legacy['values'])){
                        continue; // built on first use
                    }
                    $expected = $legacyExpected;
                    $actual = [];
                    foreach($legacy['values'] as $bucket => $keys){
                        if(!empty($keys)){
                            $actual[(string)$bucket] = array_map('intval', (array)$keys);
                        }
                    }
                }
                foreach([&$expected, &$actual] as &$buckets){
                    foreach($buckets as $bucket => $keys){
                        sort($keys);
                        $buckets[$bucket] = $keys;
                    }
                    ksort($buckets, SORT_STRING);
                }
                unset($buckets);
                if($actual !== $expected){
                    $report['indexMismatches'][] = ['segment' => $seg, 'index' => 'field ' . $field, 'detail' => 'buckets differ from the records'];
                }
            }
            foreach($manifest['spatial'] as $field){
                $expected = [];
                foreach($records as $key => $record){
                    $geometry = $record[$field] ?? null;
                    if(is_array($geometry) && $this->validateGeoJSON($geometry)['valid'] && $this->calculateMBR($geometry) !== null){
                        $expected[] = (int)$key;
                    }
                }
                $actual = $this->integritySpatialKeys($dbname, $shardId, $field, $keyEpoch, $offset);
                if($actual === null){
                    $legacy = $this->atomicRead($this->getLegacySpatialIndexPath($dbname, $field, $shardId), null);
                    if(!is_array($legacy) || !isset($legacy['leafOf'])){
                        continue;
                    }
                    $actual = array_map('intval', array_keys($legacy['leafOf']));
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

        foreach(array_merge([$this->getBufferPath($dbname)], $this->flushingFiles($dbname), $this->legacyShardBufferFiles($dbname)) as $buffer){
            if(is_file($buffer)){
                $report['bufferedRecords'] += count($this->readBufferRecords($buffer));
            }
        }
        $report['quarantineFiles'] = array_map('basename', $this->listFilesWithPrefix($this->dbDir . $this->hashDBName($dbname) . '.nonedb.q.', '/\.bin$/'));
        $report['recoveryLog'] = array_slice($this->getRecoveryLogEntries($dbname), -20);
        $report['ok'] = empty($report['badLines']) && empty($report['tornTail']) && empty($report['indexMismatches']) && !$report['pendingRecovery'];
        return $report;
    }

    /**
     * The index list without writing anything (checkIntegrity)
     */
    private function deriveOrReadManifest($dbname){
        $path = $this->getIndexManifestPath($dbname);
        $content = is_file($path) ? @file_get_contents($path) : false;
        $manifest = $content === false ? [] : json_decode($content, true);
        if(!is_array($manifest)){
            $manifest = $this->deriveIndexManifest($dbname);
        }
        return ['fields' => array_values($manifest['fields'] ?? []), 'spatial' => array_values($manifest['spatial'] ?? [])];
    }

    /**
     * checkIntegrity(): buckets of a segment's field index (v3.3) that fits the key checkpoint, with the
     * data lines up to $complete replayed; null when there is none that fits
     */
    private function integrityFieldBuckets($dbname, $shardId, $field, $keyEpoch, $complete){
        $file = $this->readSecondaryFile($this->getFieldIndexPath($dbname, $field, $shardId));
        if($file === null || !$this->integrityHeaderFits($dbname, $shardId, $file[0], 'fx', $field, $keyEpoch, $complete)){
            return null;
        }
        $buckets = json_decode($file[1], true);
        if(!is_array($buckets)){
            return null;
        }
        $index = $this->newFieldIndex($field);
        $index['b'] = $buckets;
        $index['s'] = $file[0]['s'];
        $index['e'] = $file[0]['e'];
        $index['mk'] = (int)($file[0]['mk'] ?? -1);
        $this->replayFieldIndex($dbname, $shardId, $index, $complete);
        $out = [];
        foreach($index['b'] as $bucket => $keys){
            $out[(string)$bucket] = is_int($keys) ? [$keys] : array_values($keys);
        }
        return $out;
    }

    /**
     * checkIntegrity(): keys of a segment's R-tree (v3.3) that fits the key checkpoint, replayed up to
     * $complete; null when there is none that fits
     */
    private function integritySpatialKeys($dbname, $shardId, $field, $keyEpoch, $complete){
        $file = $this->readSecondaryFile($this->getSpatialIndexPath($dbname, $field, $shardId));
        if($file === null || !$this->integrityHeaderFits($dbname, $shardId, $file[0], 'rx', $field, $keyEpoch, $complete)){
            return null;
        }
        $nodes = json_decode($file[1], true);
        if(!is_array($nodes) || !isset($file[0]['root'], $file[0]['next'])){
            return null;
        }
        $tree = $this->newRTree($field, (int)($file[0]['ns'] ?? $this->rtreeNodeSize));
        $tree['nodes'] = [];
        foreach($nodes as $id => $node){
            $tree['nodes'][(int)$id] = $node;
        }
        $tree['root'] = (int)$file[0]['root'];
        $tree['next'] = (int)$file[0]['next'];
        $tree['mk'] = (int)($file[0]['mk'] ?? -1);
        $tree['s'] = $file[0]['s'];
        $tree['e'] = $file[0]['e'];
        $this->replaySpatialIndex($dbname, $shardId, $tree, $complete);
        $this->rtreeMaps($tree);
        return array_keys($tree['leafOf']);
    }

    private function integrityHeaderFits($dbname, $shardId, array $header, $kind, $field, $keyEpoch, $complete){
        return ($header['kind'] ?? null) === $kind && ($header['field'] ?? null) === (string)$field && $keyEpoch !== null
            && ($header['e'] ?? null) === $keyEpoch && isset($header['s']) && is_int($header['s']) && $header['s'] <= $complete
            && $this->segmentCrc($this->segDataPath($dbname, $shardId), $header['s']) === ($header['h'] ?? null);
    }

    private function getRecoveryLogEntries($dbname){
        $path = $this->getRecoveryLogPath($dbname);
        $entries = [];
        foreach([$path . '.1', $path] as $file){
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
        $dbname = $this->normalizeDbName($dbname);
        $read = function() use ($dbname, $limit){
            // What readers of this process found and no write session logged yet: "persisted" => false
            $pending = [];
            foreach(self::$pendingRecoveryEvents[$this->getLockPath($dbname)] ?? [] as $event){
                $pending[] = $event + ['persisted' => false];
            }
            $limit = max(0, (int)$limit);
            return $limit === 0 ? [] : array_slice(array_merge($this->getRecoveryLogEntries($dbname), $pending), -$limit);
        };
        return $this->hasLockFile($dbname) ? $this->withLock($dbname, false, $read) : $read();
    }

    /**
     * check db
     * if auto create db is true will be create db
     * if auto create db is false and is not in db dir return false
     * @param string $dbname
     * @return bool
     * @throws \InvalidArgumentException for names with characters outside [A-Za-z0-9' -]
     */
    public function checkDB($dbname=null){
        if($dbname === null || $dbname === '' || $dbname === false){
            return false;
        }
        $dbname = $this->normalizeDbName($dbname);
        if(!$this->autoCreateDB){
            // Only a check: a database that doesn't exist creates nothing (no directory, no lock file)
            return $this->withReadLock($dbname, function() use ($dbname){
                return $this->dbExists($dbname);
            });
        }
        return $this->withLock($dbname, true, function() use ($dbname){
            return $this->checkDBUnlocked($dbname);
        });
    }

    private function checkDBUnlocked($dbname){
        $this->ensureDbDir();

        // Existing database (non-sharded data file or sharded meta)
        $fullDBPath = $this->getDataPath($dbname);
        if($this->cachedFileExists($fullDBPath) || $this->cachedFileExists($this->getMetaPath($dbname))){
            return true;
        }

        if($this->autoCreateDB){
            return $this->createDBUnlocked($dbname);
        }
        return false;
    }

    /**
     * create db function
     * @param string $dbname
     * @return bool True if created, false if it already existed
     */
    public function createDB($dbname){
        $dbname = $this->normalizeDbName($dbname);
        return $this->withLock($dbname, true, function() use ($dbname){
            return $this->createDBUnlocked($dbname);
        });
    }

    private function createDBUnlocked($dbname){
        $this->ensureDbDir();
        $fullDBPath = $this->getDataPath($dbname);
        if($this->cachedFileExists($fullDBPath) || $this->cachedFileExists($this->getMetaPath($dbname))){
            return false;
        }

        // Info file: the creation time (getDBs()) and an existence marker; its content never matters for
        // the data, so without fsync a plain write as in 3.2 (an atomic write costs a rename per new database)
        if($this->fsync){
            $this->writeFileAtomic($fullDBPath . "info", (string)time(), 'durable');
        } else {
            $this->fsPutContents($fullDBPath . "info", (string)time());
        }

        // v3.0: Create empty JSONL format database
        if(!$this->fsTouch($fullDBPath)){
            throw new \RuntimeException("noneDB: cannot create database file for '{$dbname}'");
        }
        $this->syncForWrite(null, $this->dbDir, 'dir');

        // Mark file as existing in cache
        $this->markFileExists($fullDBPath);
        $this->jsonlFormatCache[$fullDBPath] = true;

        // Empty index, checkpointed at offset 0
        $index = $this->newJsonlIndex();
        $index['e'] = bin2hex(random_bytes(4));
        $this->indexCache[$fullDBPath . '.jidx'] = $index;
        $this->verifiedIndexes[$fullDBPath . '.jidx'] = true;
        $this->writeCheckpoint($dbname, null, $index);

        return true;
    }

    /**
     * Delete a database and every file that belongs to it (data, shards, indexes, buffers, metadata)
     * @param string $dbname
     * @return array ['success' => bool, 'files' => int deleted file count]
     */
    public function dropDB($dbname){
        $dbname = $this->normalizeDbName($dbname);
        // Any file but the lock file counts: also what an interrupted dropDB left behind
        if(empty($this->listDbFiles($dbname))){
            return ['success' => false, 'files' => 0, 'error' => 'Database does not exist'];
        }
        return $this->withLock($dbname, true, function() use ($dbname){
            $this->refuseInUncommittedWrite($dbname, 'dropDB');
            $base = $this->dbBase($dbname);
            $lockPath = $this->getLockPath($dbname);
            // Other processes drop all their caches of it even if this dies half-way
            $this->bumpEpoch($dbname);
            // Buffers first, so an interrupted drop never leaves records that a later flush would bring back;
            // the files that make the database exist last, so an interrupted drop can be repeated
            $files = $this->listDbFiles($dbname);
            $last = [$base . '.nonedb.jidx', $base . '.nonedb', $base . '.nonedb.meta', $base . '.nonedbinfo'];
            $buffers = array_values(array_filter($files, function($file){
                return strpos($file, '.nonedb.buffer') !== false;
            }));
            $rest = array_values(array_diff($files, $buffers, $last));
            $deleted = 0;
            $failed = [];
            foreach([$buffers, $rest, array_values(array_intersect($last, $files))] as $step => $group){
                foreach($group as $file){
                    $this->fault('drop:unlink', ['db' => $dbname, 'file' => $file]);
                    if($this->fsUnlink($file)){
                        $deleted++;
                    } elseif(is_file($file)){
                        $failed[] = basename($file);
                    }
                }
                if($step === 0){
                    unset(self::$lockRegistry[$lockPath]['flags']['b']);
                    $this->saveLockState($lockPath);
                }
            }
            $this->invalidateDbCaches($dbname);
            $this->syncForWrite(null, $this->dbDir, 'dir');
            if(!empty($failed)){
                return ['success' => false, 'files' => $deleted, 'error' => 'Could not delete: ' . implode(', ', $failed)];
            }
            // Nothing is left: no buffer, no journal, no pre-3.2 files
            self::$lockRegistry[$lockPath]['flags'] = ['v' => true];
            $this->saveLockState($lockPath);
            return ['success' => true, 'files' => $deleted];
        });
    }

    /**
     * Name, creation time and total size of a database (all shards included)
     * @return array|false
     */
    private function getDBInfo($dbname){
        $base = $this->dbBase($dbname);
        $infoPath = $base . '.nonedbinfo';
        clearstatcache();
        if(!file_exists($infoPath)){
            return false;
        }
        $created = (int)@file_get_contents($infoPath);
        $size = 0;
        if(file_exists($base . '.nonedb')){
            $size += (int)filesize($base . '.nonedb');
        }
        foreach($this->listFilesWithPrefix($base . '_s', '/^\d+\.nonedb$/') as $shardFile){
            $size += (int)filesize($shardFile);
        }
        $info = array("name" => $dbname, "createdTime" => $created, "size" => $this->fileSizeConvert($size));
        // Shard files found without a meta entry are kept aside (see setOrphanShardsAside)
        $orphans = array_map('basename', $this->listFilesWithPrefix($base . '_s', '/\.orphan\.\d+$/'));
        if(!empty($orphans)){
            $info['orphanFiles'] = $orphans;
        }
        return $info;
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

    public function getDBs($info=false){
        // Handle three cases: false (names only), true (with metadata), string (specific db)
        $withMetadata = false;
        $specificDb = null;

        if(is_bool($info)){
            $withMetadata = $info;
        }else{
            $specificDb = $this->normalizeDbName($info); // reading information never creates the database
        }

        // If specific database requested
        if($specificDb !== null){
            return $this->getDBInfo($specificDb);
        }

        // List all databases: one entry per {hash}-{name}.nonedb (non-sharded) or {hash}-{name}.nonedb.meta (sharded)
        $dbs = [];
        if(!is_dir($this->dbDir)){
            return $dbs;
        }
        $seen = [];
        foreach(new DirectoryIterator($this->dbDir) as $item) {
            if($item->isDot() || !$item->isFile()) {
                continue;
            }
            if(!preg_match("/^([0-9a-f]{20})-([A-Za-z0-9' -]+)\\.nonedb(\\.meta)?$/", $item->getFilename(), $m)){
                continue;
            }
            $dbname = $m[2];
            // Only databases that belong to this secretKey
            if($this->hashDBName($dbname) !== $m[1] || isset($seen[$dbname])){
                continue;
            }
            $seen[$dbname] = true;

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
     * limit function
     * @param array $array Extract a slice of the array
     * @param integer $limit
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

    /**
     * Run a read operation: shared lock while the database exists, no lock (and no files created)
     * when it doesn't. Buffered records are flushed first.
     */
    private function withReadLock($dbname, callable $fn){
        if(!$this->hasLockFile($dbname) && !$this->dbFilesExist($dbname)){
            return $fn();
        }
        return $this->withLock($dbname, false, function() use ($dbname, $fn){
            $this->flushPendingBuffers($dbname);
            return $fn();
        });
    }

    /**
     * Uncached existence check for a database without a lock file, used before taking a lock. Since 3.2
     * every database has a lock file (never deleted) and an interrupted flush is {buffer}.flushing.{key};
     * older versions had no lock file and named it {buffer}.flushing. So no directory listing is needed
     * here (a read of a missing database would list the whole directory each time).
     */
    private function dbFilesExist($dbname){
        $base = $this->dbBase($dbname);
        foreach(['.nonedb', '.nonedb.meta', '.nonedb.buffer', '.nonedb.buffer.flushing'] as $suffix){
            clearstatcache(true, $base . $suffix);
            if(file_exists($base . $suffix)){
                return true;
            }
        }
        return false;
    }

    /**
     * True when the database's lock file exists (the database may have been dropped since). Taking
     * that lock creates no file, and the operation itself finds out whether the database exists.
     */
    private function hasLockFile($dbname){
        $path = $this->getLockPath($dbname);
        if(isset(self::$lockHandles[$path]) || isset(self::$lockRegistry[$path])){
            return true;
        }
        clearstatcache(true, $path);
        return file_exists($path);
    }

    /**
     * read db all data
     * @param string $dbname
     * @param mixed $filters 0/null/[] for all records, or an array of field => value (strict ===);
     *                       a "key" entry selects by record key (int or list of ints)
     * @return array|false False when the database doesn't exist and autoCreateDB is off, or for invalid filters
     */
    public function find($dbname, $filters=0){
        $dbname = $this->normalizeDbName($dbname);
        if($filters === null || is_int($filters)){
            $filters = [];
        }
        if(!is_array($filters)){
            return false;
        }
        return $this->withReadLock($dbname, function() use ($dbname, $filters){
            return $this->findUnlocked($dbname, $filters);
        });
    }

    private function findUnlocked($dbname, $filters=0){
        if($filters === null || is_int($filters)){
            $filters = [];
        }
        if(!$this->dbExists($dbname)){
            // Reads never create databases
            return $this->autoCreateDB ? [] : false;
        }
        return array_values($this->selectRecords($dbname, $filters));
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
        return $this->withExactFloats(function() use ($items){
            foreach($items as $item){
                if($this->hasReservedKeyField($item)){
                    return [[], "You cannot set key name to key"];
                }
                if($this->encodeJson($item) === false){
                    return [[], "insert data cannot be encoded as JSON (invalid UTF-8, NAN/INF or unsupported value)"];
                }
            }
            return [$items, null];
        });
    }

    /**
     * insert to db
     * @param string $dbname
     * @param array $data One record, or a list of records
     * @return array ['n' => inserted count] or ['n' => 0, 'error' => message]
     */
    public function insert($dbname, $data){
        $dbname = $this->normalizeDbName($dbname);
        list($items, $error) = $this->validateInsertData($data);
        if($error !== null){
            return array("n" => 0, "error" => $error);
        }
        return $this->withLock($dbname, true, function() use ($dbname, $items){
            if($this->bufferEnabled){
                return $this->insertBuffered($dbname, $items);
            }
            $this->flushPendingBuffers($dbname);
            return $this->insertRecords($dbname, $items);
        });
    }

    /**
     * delete function
     * @param string $dbname
     * @param array $data Filters (same rules as find); [] deletes every record
     * @return array ['n' => deleted count]
     */
    public function delete($dbname, $data){
        $dbname = $this->normalizeDbName($dbname);
        if(!is_array($data)){
            return array("n" => 0, "error" => "Please check your delete parameters");
        }
        return $this->withLock($dbname, true, function() use ($dbname, $data){
            return $this->deleteUnlocked($dbname, $data);
        });
    }

    private function deleteUnlocked($dbname, $data){
        $this->flushPendingBuffers($dbname);
        if(!$this->dbExists($dbname)){
            return array("n" => 0);
        }
        $deleted = 0;
        foreach($this->groupBySegment($dbname, $this->selectRecords($dbname, $data)) as $segment => $records){
            $deleted += $this->deleteSegmentRecords($dbname, $segment === '' ? null : (int)$segment, $records);
        }
        return array("n" => $deleted);
    }

    /**
     * update function
     * @param string $dbname
     * @param array $data [filters, ['set' => [field => value, ...]]]
     * @return array ['n' => updated count]
     */
    public function update($dbname, $data){
        $dbname = $this->normalizeDbName($dbname);
        if(!is_array($data) || !array_key_exists(0, $data) || !is_array($data[0])
            || !isset($data[1]) || !is_array($data[1]) || !isset($data[1]['set']) || !is_array($data[1]['set'])
            || array_key_exists("key", $data[1]['set'])){
            return array("n" => 0, "error" => "Please check your update parameters");
        }
        if($this->encodeJson($data[1]['set']) === false){
            return array("n" => 0, "error" => "update data cannot be encoded as JSON (invalid UTF-8, NAN/INF or unsupported value)");
        }
        return $this->withLock($dbname, true, function() use ($dbname, $data){
            return $this->updateUnlocked($dbname, $data);
        });
    }

    private function updateUnlocked($dbname, $data){
        $this->flushPendingBuffers($dbname);
        if(!$this->dbExists($dbname)){
            return array("n" => 0);
        }
        $set = $data[1]['set'];
        if(empty($set)){
            return array("n" => count($this->selectRecords($dbname, $data[0])));
        }
        $updated = 0;
        foreach($this->groupBySegment($dbname, $this->selectRecords($dbname, $data[0])) as $segment => $records){
            $new = [];
            foreach($records as $key => $record){
                foreach($set as $field => $value){
                    $record[$field] = $value;
                }
                $new[$key] = $record;
            }
            $updated += $this->updateSegmentRecords($dbname, $segment === '' ? null : (int)$segment, $records, $new);
        }
        return array("n" => $updated);
    }

    /**
     * Replace whole records by key (used by noneDBQuery::removeFields); caller holds the exclusive lock
     * @param array $records [key => new record without "key"]
     * @return int
     */
    private function replaceRecordsByKey($dbname, array $records){
        if(empty($records) || !$this->dbExists($dbname)){
            return 0;
        }
        $old = $this->selectRecords($dbname, ['key' => array_keys($records)]);
        $written = 0;
        foreach($this->groupBySegment($dbname, $old) as $segment => $oldRecords){
            $new = [];
            foreach(array_keys($oldRecords) as $key){
                $new[$key] = $records[$key];
            }
            $written += $this->updateSegmentRecords($dbname, $segment === '' ? null : (int)$segment, $oldRecords, $new);
        }
        return $written;
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field){
            return $this->distinctUnlocked($dbname, $field);
        });
    }

    private function distinctUnlocked($dbname, $field){
        $all = $this->findUnlocked($dbname, []);
        if($all === false) return false;
        return self::uniqueValues($all, $field);
    }

    /**
     * Distinct values of a field (null excluded) in first-seen order, same identity as
     * in_array($value, $values, true), in linear time (a hash set of valueKey()s)
     */
    private static function uniqueValues(array $records, $field){
        $seen = [];
        $values = [];
        foreach($records as $record){
            if(isset($record[$field])){
                $id = self::valueKey($record[$field]);
                if(!isset($seen[$id])){
                    $seen[$id] = true;
                    $values[] = $record[$field];
                }
            }
        }
        return $values;
    }

    /**
     * A string that is equal for two values exactly when they are identical (===): typed, -0.0 is 0.0,
     * arrays by their typed keys and values in order (lengths make the encoding unambiguous).
     * Floats by their bits, so neither precision nor locale settings matter.
     */
    private static function valueKey($v){
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
            $items[] = [self::sortKey(is_array($row) && array_key_exists($field, $row) ? $row[$field] : null), $i, $row];
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
     * Total order used by sort(), min() and max() (and the query builder): missing/null < booleans <
     * numbers < numeric strings (by their number) < other strings (byte order) < arrays.
     * @return int
     */
    private static function compareSortValues($a, $b){
        return self::sortKey($a) <=> self::sortKey($b);
    }

    /**
     * Sort key of a value: [kind, comparable value]. Two keys compare with <=> in the order above
     * (kind first, then the value; arrays by their JSON), so sorts compute each key once instead of
     * classifying both values in every comparison.
     * @return array
     */
    private static function sortKey($v){
        if($v === null) return [0, 0];
        if(is_bool($v)) return [1, $v];
        if(is_int($v) || is_float($v)) return [2, $v];
        if(is_string($v)){
            // One definition of "numeric string" on every PHP version ("5 " is numeric since 8.0 only).
            // Numeric strings never meet other strings, so <=> never mixes numeric and byte comparison
            // (which made "9" < "10" < "1a" < "9" a cycle before v3.3).
            $trimmed = rtrim($v, " \t\n\r\v\f");
            return is_numeric($trimmed) ? [3, $trimmed + 0] : [4, $v];
        }
        return [5, (string)json_encode($v)];
    }

    /**
     * sortKey() for the query builder
     * @return \Closure
     */
    private function sortKeyFunction(){
        return \Closure::fromCallable([self::class, 'sortKey']);
    }

    /**
     * Count records matching filter
     * @param string $dbname
     * @param mixed $filter
     * @return int
     */
    public function count($dbname, $filter = 0){
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $filter){
            return $this->countUnlocked($dbname, $filter);
        });
    }

    private function countUnlocked($dbname, $filter = 0){
        $filters = $this->normalizeFilter($filter);
        if($filters === null){
            return 0; // a filter find() rejects matches nothing (a TypeError before v3.3)
        }
        // Fast-path: no filter = use index/meta counters
        if(empty($filters)){
            return $this->countFast($dbname);
        }
        $count = 0;
        if($this->scanMatches($dbname, $filters, function() use (&$count){ $count++; return true; }, true)){
            return $count; // counted record by record, none kept (v3.3)
        }
        $result = $this->findUnlocked($dbname, $filters);
        return $result === false ? 0 : count($result);
    }

    /**
     * Count without reading records: index entries (non-sharded) or meta totalRecords (sharded)
     * @param string $dbname Already normalized
     * @return int
     */
    private function countFast($dbname){
        if(!$this->dbExists($dbname)){
            return 0;
        }
        if($this->isSharded($dbname)){
            $meta = $this->getCachedMeta($dbname);
            return $meta !== null ? (int)($meta['totalRecords'] ?? 0) : 0;
        }
        $index = $this->readJsonlIndex($dbname, null);
        return $index !== null ? count($index['p']) : 0;
    }

    /**
     * Pattern matching search (LIKE)
     * @param string $dbname
     * @param string $field
     * @param string $pattern Use ^ for starts with, $ for ends with
     * @return array|false
     */
    public function like($dbname, $field, $pattern){
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field, $pattern){
            return $this->likeUnlocked($dbname, $field, $pattern);
        });
    }

    private function likeUnlocked($dbname, $field, $pattern){
        if(!is_scalar($pattern)){
            return false; // "Array to string" before v3.3
        }
        $all = $this->findUnlocked($dbname, []);
        if($all === false) return false;

        $result = [];
        $like = noneDBQuery::compileLike((string)$pattern);
        foreach($all as $record){
            if(!isset($record[$field])) continue;
            $value = $record[$field];
            // Only strings and numbers (true used to match "1", like in the query builder it never does)
            if(!is_scalar($value) || is_bool($value)) continue;
            if(noneDBQuery::likeMatch($like, (string)$value)){
                $result[] = $record;
            }
        }
        return $result;
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field, $filter){
            return $this->sumUnlocked($dbname, $field, $filter);
        });
    }

    private function sumUnlocked($dbname, $field, $filter = 0){
        $result = $this->filteredUnlocked($dbname, $filter);
        if($result === false) return 0;
        $sum = 0;
        foreach($result as $record){
            $number = \noneDBQuery::numberOf($record[$field] ?? null);
            if($number !== null){
                $sum += $number;
            }
        }
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field, $filter){
            return $this->avgUnlocked($dbname, $field, $filter);
        });
    }

    private function avgUnlocked($dbname, $field, $filter = 0){
        $result = $this->filteredUnlocked($dbname, $filter);
        if($result === false || count($result) === 0) return 0;
        $sum = 0;
        $count = 0;
        foreach($result as $record){
            $number = \noneDBQuery::numberOf($record[$field] ?? null);
            if($number !== null){
                $sum += $number;
                $count++;
            }
        }
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field, $filter){
            return $this->minUnlocked($dbname, $field, $filter);
        });
    }

    private function minUnlocked($dbname, $field, $filter = 0){
        $result = $this->filteredUnlocked($dbname, $filter);
        if($result === false || count($result) === 0) return null;
        // The order of sort() (and of the query builder's min()); loose < depended on the insertion order
        $min = null;
        foreach($result as $record){
            if(isset($record[$field])){
                if($min === null || self::compareSortValues($record[$field], $min) < 0){
                    $min = $record[$field];
                }
            }
        }
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field, $filter){
            return $this->maxUnlocked($dbname, $field, $filter);
        });
    }

    private function maxUnlocked($dbname, $field, $filter = 0){
        $result = $this->filteredUnlocked($dbname, $filter);
        if($result === false || count($result) === 0) return null;
        $max = null;
        foreach($result as $record){
            if(isset($record[$field])){
                if($max === null || self::compareSortValues($record[$field], $max) > 0){
                    $max = $record[$field];
                }
            }
        }
        return $max;
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $filter){
            return $this->firstUnlocked($dbname, $filter);
        });
    }

    private function firstUnlocked($dbname, $filter = 0){
        $filters = $this->normalizeFilter($filter);
        $first = null;
        if($filters !== null && $this->scanMatches($dbname, $filters, function($key, $record) use (&$first){ $first = $record; return false; })){
            return $first; // stopped at the first match (v3.3)
        }
        $result = $this->filteredUnlocked($dbname, $filter);
        if($result === false || count($result) === 0) return null;
        return $result[0];
    }

    /**
     * Get last matching record
     * @param string $dbname
     * @param mixed $filter
     * @return array|null
     */
    public function last($dbname, $filter = 0){
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $filter){
            return $this->lastUnlocked($dbname, $filter);
        });
    }

    private function lastUnlocked($dbname, $filter = 0){
        $result = $this->filteredUnlocked($dbname, $filter);
        if($result === false || count($result) === 0) return null;
        return $result[count($result) - 1];
    }

    /**
     * Check if records exist matching filter
     * @param string $dbname
     * @param mixed $filter
     * @return bool
     */
    public function exists($dbname, $filter){
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $filter){
            return $this->existsUnlocked($dbname, $filter);
        });
    }

    private function existsUnlocked($dbname, $filter){
        $filters = $this->normalizeFilter($filter);
        $found = false;
        if($filters !== null && $this->scanMatches($dbname, $filters, function() use (&$found){ $found = true; return false; })){
            return $found;
        }
        $result = $this->filteredUnlocked($dbname, $filter);
        return $result !== false && count($result) > 0;
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field, $min, $max, $filter){
            return $this->betweenUnlocked($dbname, $field, $min, $max, $filter);
        });
    }

    private function betweenUnlocked($dbname, $field, $min, $max, $filter = 0){
        // An indexed field: only the records whose value the field index puts in range are read (v3.3)
        $result = $this->rangeCandidateRecords($dbname, $field, ['$gte' => $min, '$lte' => $max], $filter);
        if($result === null){
            $result = $this->filteredUnlocked($dbname, $filter);
        }
        if($result === false) return false;
        // Same comparison as query()->between(): numbers (and numeric strings) numerically, strings
        // lexically; null, booleans, arrays and number-vs-text are never in range
        $filtered = [];
        foreach($result as $record){
            if(!array_key_exists($field, $record)){
                continue;
            }
            $lo = noneDBQuery::compareValues($record[$field], $min);
            $hi = noneDBQuery::compareValues($record[$field], $max);
            if($lo !== null && $hi !== null && $lo >= 0 && $hi <= 0){
                $filtered[] = $record;
            }
        }
        return $filtered;
    }


    /**
     * Records matching a filter parameter whose field value may satisfy the range conditions, read
     * through the field index of every segment that has one (a superset: the caller checks the range).
     * Null when the field has no index or a key filter decides (the caller reads everything).
     * @return array|false|null
     */
    private function rangeCandidateRecords($dbname, $field, array $conditions, $filter){
        $filters = $this->normalizeFilter($filter);
        if($filters === null){
            return false;
        }
        if(array_key_exists('key', $filters) || !$this->dbExists($dbname) || !$this->fieldIndexEnabled || !$this->isFieldIndexed($dbname, $field)){
            return null;
        }
        $result = [];
        foreach($this->listSegments($dbname) as $segment){
            $keys = $this->fieldIndexRangeCandidates($dbname, $segment, $field, $conditions);
            if($keys !== null && empty($keys)){
                continue;
            }
            foreach($this->readSegmentRecords($dbname, $segment, $keys) as $key => $record){
                if(empty($filters) || $this->matchesFilters($record, $filters)){
                    $result[$key] = $record;
                }
            }
        }
        ksort($result, SORT_NUMERIC);
        return array_values($result);
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname){
            return $this->getShardInfoUnlocked($dbname);
        });
    }

    private function getShardInfoUnlocked($dbname){
        if(!$this->dbExists($dbname)){
            return false;
        }
        if(!$this->isSharded($dbname)){
            $index = $this->readJsonlIndex($dbname, null);
            if($index === null){
                return false;
            }
            return array(
                "sharded" => false,
                "shards" => 0,
                "totalRecords" => count($index['p']),
                "shardSize" => $this->shardSize
            );
        }

        $meta = $this->getCachedMeta($dbname);
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
    // ==========================================
    // FIELD INDEX PUBLIC API (v3.0.0)
    // ==========================================

    /**
     * Create an index on a field for faster equality lookups (find / where with field => value)
     * @param string $dbname Database name
     * @param string $field Field name (any name except "key")
     * @return array ['success' => bool, 'indexed' => int, 'values' => int, 'error' => ?string]
     */
    public function createFieldIndex($dbname, $field){
        $dbname = $this->normalizeDbName($dbname);
        return $this->withLock($dbname, true, function() use ($dbname, $field){
            return $this->createFieldIndexUnlocked($dbname, $field);
        });
    }

    private function createFieldIndexUnlocked($dbname, $field){
        if(!is_string($field) || $field === '' || $field === 'key'){
            return ['success' => false, 'indexed' => 0, 'values' => 0, 'error' => 'Invalid field name'];
        }
        $nameError = $this->indexNameError($dbname, $field);
        if($nameError !== null){
            return ['success' => false, 'indexed' => 0, 'values' => 0, 'error' => $nameError];
        }
        if($this->dbExists($dbname) && $this->isFieldIndexed($dbname, $field)){
            $this->dropFieldIndexUnlocked($dbname, $field); // creating it again rebuilds it (as in 3.2)
        }
        $this->flushPendingBuffers($dbname);
        if(!$this->dbExists($dbname)){
            if(!$this->autoCreateDB){
                return ['success' => false, 'indexed' => 0, 'values' => 0, 'error' => 'Database does not exist'];
            }
            $this->createDBUnlocked($dbname);
        }

        // Distinct values per type: 1, 1.0, "1" and true count as four (v3.3: indexes are exact)
        $indexed = 0;
        $values = [];
        $segments = $this->listSegments($dbname);
        foreach($segments as $segment){
            $this->deleteFieldIndexFile($dbname, $field, $segment); // leftovers of an earlier index of this field
            $index = $this->buildSegmentFieldIndex($dbname, $segment, $field);
            $this->writeFieldIndex($dbname, $field, $index, $segment);
            foreach($index['b'] as $bucket => $keys){
                $indexed += is_int($keys) ? 1 : count($keys);
                $values[(string)$bucket] = true;
            }
        }
        if($this->isSharded($dbname)){
            $this->deleteGlobalFieldIndex($dbname, $field);
            $this->rebuildGlobalFieldIndex($dbname, $field);
        }

        $manifest = $this->getIndexManifest($dbname);
        $manifest['fields'][] = $field;
        $this->saveIndexManifest($dbname, $manifest);

        $result = ['success' => true, 'indexed' => $indexed, 'values' => count($values), 'error' => null];
        if($this->isSharded($dbname)){
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withLock($dbname, true, function() use ($dbname, $field){
            return $this->dropFieldIndexUnlocked($dbname, $field);
        });
    }

    private function dropFieldIndexUnlocked($dbname, $field){
        $manifest = $this->getIndexManifest($dbname);
        if(!in_array((string)$field, $manifest['fields'], true)){
            return ['success' => false, 'error' => 'Index does not exist'];
        }
        $segments = $this->listSegments($dbname);
        foreach($segments as $segment){
            $this->deleteFieldIndexFile($dbname, $field, $segment);
            // Also remove a lossy pre-3.2 file name for this field, if any
            $legacy = $this->segDataPath($dbname, $segment) . '.fidx.' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$field);
            if($legacy !== $this->getLegacyFieldIndexPath($dbname, $field, $segment) && is_file($legacy)){
                $old = $this->atomicRead($legacy, null);
                if(is_array($old) && ($old['field'] ?? null) === (string)$field){
                    $this->fsUnlink($legacy);
                }
            }
        }
        $this->deleteGlobalFieldIndex($dbname, $field);
        $manifest['fields'] = array_values(array_diff($manifest['fields'], [(string)$field]));
        $this->saveIndexManifest($dbname, $manifest);
        $this->invalidateFieldIndexCache($dbname, $field);
        return ['success' => true, 'error' => null];
    }

    /**
     * Check if a field has an index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return bool
     */
    public function hasFieldIndex($dbname, $field){
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field){
            return $this->dbExists($dbname) && $this->isFieldIndexed($dbname, $field);
        });
    }

    /**
     * Get list of field indexes for a database
     * @param string $dbname Database name
     * @return array ['fields' => array, 'sharded' => bool]
     */
    public function getFieldIndexes($dbname){
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname){
            return $this->getFieldIndexesUnlocked($dbname);
        });
    }

    private function getFieldIndexesUnlocked($dbname){
        if(!$this->dbExists($dbname)){
            return ['fields' => [], 'sharded' => false];
        }
        return ['fields' => $this->getIndexedFields($dbname), 'sharded' => $this->isSharded($dbname)];
    }

    /**
     * Rebuild a field index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array Result from createFieldIndex
     */
    public function rebuildFieldIndex($dbname, $field){
        $dbname = $this->normalizeDbName($dbname);
        return $this->withLock($dbname, true, function() use ($dbname, $field){
            return $this->rebuildFieldIndexUnlocked($dbname, $field);
        });
    }

    private function rebuildFieldIndexUnlocked($dbname, $field){
        if($this->isFieldIndexed($dbname, $field)){
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field, $value, $shardId){
            return $this->getKeysFromFieldIndexUnlocked($dbname, $field, $value, $shardId);
        });
    }

    private function getKeysFromFieldIndexUnlocked($dbname, $field, $value, $shardId = null){
        if(!$this->dbExists($dbname) || !$this->isFieldIndexed($dbname, $field) || !$this->isIndexableValue($value)){
            return null;
        }
        $segments = $shardId !== null ? [(int)$shardId] : $this->listSegments($dbname);
        $keys = [];
        foreach($segments as $segment){
            if(!in_array($segment, $this->listSegments($dbname), true)){
                continue;
            }
            $index = $this->ensureSegmentFieldIndex($dbname, $segment, $field);
            $found = $index === null ? null : $this->fieldIndexKeys($index, $value);
            if($found === null || !empty($index['legacy'])){
                // No usable index (or a v3.2 one, whose buckets mix types): the records decide, exactly
                foreach($found === null ? $this->readSegmentRecords($dbname, $segment) : $this->readSegmentRecords($dbname, $segment, $found) as $key => $record){
                    if(array_key_exists($field, $record) && $record[$field] === $value){
                        $keys[] = (int)$key;
                    }
                }
                continue;
            }
            foreach($found as $key){
                $keys[] = (int)$key;
            }
        }
        sort($keys);
        return $keys;
    }


    /**
     * Convert the index files of v3.2 that are still in use (v3.3.0): one segment at a time, each in a
     * short exclusive session of its own, so other processes never wait long. Safe to run again or to stop
     * half-way: the next run continues. Without $dbname: every database of this secret key in dbDir.
     * Not needed for correctness: a segment is converted when it is first written; until then its v3.2
     * files are read (their global shard summaries are not used to skip shards).
     * @param string|null $dbname
     * @return array ['databases' => int, 'segments' => int, 'converted' => int, 'ms' => float]
     */
    public function upgradeIndexes($dbname = null){
        $start = microtime(true);
        $names = $dbname === null ? (array)$this->getDBs(false) : [$dbname];
        $report = ['databases' => 0, 'segments' => 0, 'converted' => 0, 'ms' => 0.0];
        foreach($names as $name){
            $name = $this->normalizeDbName($name);
            $segments = $this->withReadLock($name, function() use ($name){
                return $this->dbExists($name) ? $this->listSegments($name) : [];
            });
            if(empty($segments)){
                continue;
            }
            $report['databases']++;
            foreach($segments as $segment){
                $report['segments']++;
                $converted = $this->withLock($name, true, function() use ($name, $segment){
                    return in_array($segment, $this->listSegments($name), true) && $this->convertSegmentIndexes($name, $segment);
                });
                if($converted){
                    $report['converted']++;
                }
            }
            // Nothing left to convert (or nothing ever was): the index list says so, no file is looked for again
            $this->withLock($name, true, function() use ($name){
                $manifest = $this->getIndexManifest($name);
                if($manifest['fmt'] || (empty($manifest['fields']) && empty($manifest['spatial']))){
                    return;
                }
                foreach($this->listSegments($name) as $segment){
                    if(!$this->segmentIsConverted($name, $segment)){
                        return;
                    }
                }
                $manifest['fmt'] = true;
                $this->saveIndexManifest($name, $manifest);
            });
        }
        $report['ms'] = round((microtime(true) - $start) * 1000, 2);
        return $report;
    }

    // ==========================================
    // SPATIAL INDEX - GEOJSON SUPPORT (v3.1.0)
    // ==========================================

    /**
     * Validate a GeoJSON geometry object
     * Supports: Point, LineString, Polygon, MultiPoint, MultiLineString, MultiPolygon, GeometryCollection
     *
     * @param array $geometry GeoJSON geometry object
     * @return array ['valid' => bool, 'error' => ?string, 'type' => ?string]
     */
    public function validateGeoJSON($geometry): array {
        self::$geometryValidations++;
        // Never throws: a stored record that fails here is simply not indexed (v3.3.0: before, a malformed
        // geometry made every later operation on the database fail)
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
     * An array with keys 0..n-1 in order (JSON array), PHP 7.4 compatible array_is_list()
     */
    private static function isList($value): bool {
        if (!is_array($value)) {
            return false;
        }
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }
        $i = 0;
        foreach ($value as $key => $unused) {
            if ($key !== $i++) {
                return false;
            }
        }
        return true;
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
        if (!self::isList($coords) || count($coords) < 2) {
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
        if (!self::isList($coords) || count($coords) < 1) {
            return ['valid' => false, 'error' => 'Polygon must have at least one ring', 'type' => null];
        }
        // Rings in order. The holes before the first failing ring are checked against the outer ring
        // together (one sweep for all their vertices); the first error is the same as ring by ring.
        $failure = null;
        $holes = [];
        foreach ($coords as $ringIndex => $ring) {
            if (!self::isList($ring) || count($ring) < 4) {
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
     * fast for usual rings (a 2000-vertex polygon took seconds per query before v3.3). When that work
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
        $budget = self::$sweepBudget ?? 16 * $n + 256;
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
                self::$segmentPairTests++;
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
        $cmp = self::sweepOrder($xs, $ys, $lo, $hi, self::$segmentPairTests);
        $meet = function (int $a, int $b) use ($xs, $ys, $n): bool {
            if ($a < 0 || $b < 0) {
                return false;
            }
            $d = $a > $b ? $a - $b : $b - $a;
            if ($d === 1 || $d === $n - 1) {
                return false;
            }
            self::$segmentPairTests++;
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
                self::$segmentPairTests++;
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
     * to top): compared where the later one starts, then by direction; equal edges by number. $counter
     * counts the comparisons.
     */
    private static function sweepOrder(array $xs, array $ys, array $lo, array $hi, int &$counter): \Closure {
        return function (int $s, int $t) use ($xs, $ys, $lo, $hi, &$counter): int {
            $counter++;
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
        $budget = self::$sweepBudget ?? (int)(16 * ($n + $m) * log($n + $m + 2, 2));
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
        $cmp = self::sweepOrder($xs, $ys, $lo, $hi, self::$pointEdgeTests);
        $count = count($ee);
        for ($k = 0; $k < $count;) {
            if ($ee[$k] < 0) {
                // A point (never at a vertex): the edges below it
                $x = $ex[$k];
                $y = $ey[$k];
                $winding = 0;
                for ($node = $tree['root']; $node >= 0;) {
                    self::$pointEdgeTests++;
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
        self::$pointEdgeTests += $n;
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
    private function pointOnRing(float $lon, float $lat, array $ring): bool {
        $n = count($ring);
        for ($i = 0; $i < $n - 1; $i++) {
            if ($this->crossProductDirection($ring[$i], $ring[$i + 1], [$lon, $lat]) == 0
                && $this->onSegment($ring[$i], $ring[$i + 1], [$lon, $lat])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate MultiPoint coordinates [[lon,lat], [lon,lat], ...]
     */
    private function validateGeoJSONMultiPoint($coords): array {
        if (!self::isList($coords) || count($coords) < 1) {
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
        if (!self::isList($coords) || count($coords) < 1) {
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
        if (!self::isList($coords) || count($coords) < 1) {
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
        if (!isset($geometry['geometries']) || !self::isList($geometry['geometries'])) {
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

    // ==========================================
    // SPATIAL INDEX - GEOMETRY OPERATIONS
    // ==========================================

    /**
     * Calculate Minimum Bounding Rectangle (MBR) for any GeoJSON geometry
     * @param array $geometry GeoJSON geometry object
     * @return array|null [minLon, minLat, maxLon, maxLat]; null for a geometry without any position
     *                    (an empty GeometryCollection): it has no location and is never indexed or matched
     */
    private function calculateMBR(array $geometry): ?array {
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
    private function mbrFromPoints(array $points): ?array {
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
    private function mbrOverlaps(array $mbr1, array $mbr2): bool {
        // Check if one MBR is completely to the left, right, above, or below the other
        if ($mbr1[2] < $mbr2[0] || $mbr2[2] < $mbr1[0]) return false; // No horizontal overlap
        if ($mbr1[3] < $mbr2[1] || $mbr2[3] < $mbr1[1]) return false; // No vertical overlap
        return true;
    }

    /**
     * Calculate area of an MBR (in degrees squared)
     * @param array $mbr [minLon, minLat, maxLon, maxLat]
     * @return float
     */
    private function mbrArea(array $mbr): float {
        $width = $mbr[2] - $mbr[0];
        $height = $mbr[3] - $mbr[1];
        return $width * $height;
    }

    /**
     * Calculate enlargement needed if entry is added to an MBR
     * @param array $mbr Existing MBR
     * @param array $entryMBR New entry's MBR
     * @return float Area increase
     */
    private function mbrEnlargement(array $mbr, array $entryMBR): float {
        $unionMBR = $this->mbrUnion($mbr, $entryMBR);
        return $this->mbrArea($unionMBR) - $this->mbrArea($mbr);
    }

    /**
     * Calculate Haversine distance between two points (in meters)
     * (v3.3.0: no memo any more: its string keys depended on the precision setting, so two points could
     * share a key, it almost never hit, and the key cost more than the formula)
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
     * Kept for compatibility: there is no distance cache any more (v3.3.0)
     */
    public function clearDistanceCache(): void {
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
    private function pointInPolygon(float $lon, float $lat, array $polygon): bool {
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
    private function pointInRing(float $lon, float $lat, array $ring): bool {
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
    private function crossProductDirection(array $p1, array $p2, array $p3): float {
        return ($p3[0] - $p1[0]) * ($p2[1] - $p1[1]) - ($p2[0] - $p1[0]) * ($p3[1] - $p1[1]);
    }

    /**
     * Check if point p is on segment p1-p2
     */
    private function onSegment(array $p1, array $p2, array $p): bool {
        return min($p1[0], $p2[0]) <= $p[0] && $p[0] <= max($p1[0], $p2[0]) &&
               min($p1[1], $p2[1]) <= $p[1] && $p[1] <= max($p1[1], $p2[1]);
    }

    /**
     * Kept for compatibility: no centroid cache since v3.3 (the code that used it was never called)
     */
    public function clearCentroidCache(): void {
    }

    // ==========================================
    // SPATIAL INDEX - R-TREE ENGINE
    // ==========================================
    // One R-tree per segment and indexed field: {segment}.nonedb.rx.{token} (see SecondaryIndex), a
    // checkpoint like the field indexes:
    //   {"v":4,"kind":"rx","field":"loc","q":10000000,"ns":32,"s":…,"e":…,"h":…,"mk":…,"cnt":…,"root":3,"next":9}
    //   {"0":[0,[minX,minY,maxX,maxY],[[key,minX,minY,maxX,maxY],…]],"3":[1,[…],[0,2]],…}
    // A node is [level, box, payload]: level 0 holds entries [key, box], a higher level child node ids.
    // Boxes are integers: degrees * 1e7 rounded outwards (1e-7° is about 1 cm), so they don't depend on
    // serialize_precision, and every query re-checks the exact geometry. Built with sort-tile-recursive
    // bulk loading; changes after the checkpoint are replayed from the data when the index is used.
    // Sharded databases keep {base}.nonedb.grx.{token}: each shard's box (null = empty) as of the bytes
    // its entry covers; a shard is skipped only when its entry fits, its box misses the query and the
    // shard's newer lines hold no geometry in it. v3.2's .sidx./.gsidx. files are read until the segment
    // is converted (convertSegmentIndexes); .gsidx. never skips a shard.

    private const RTREE_SCALE = 10000000;

    /**
     * Path of a segment's R-tree (v3.3)
     */
    private function getSpatialIndexPath(string $dbname, string $field, ?int $shardId = null): string {
        return $this->secondaryPath($dbname, 'rx', $field, $shardId);
    }

    /**
     * Path of a segment's v3.2 R-tree
     */
    private function getLegacySpatialIndexPath(string $dbname, string $field, ?int $shardId = null): string {
        $hash = $this->hashDBName($dbname);
        $name = $this->encodeIndexFieldName($field);
        return $this->dbDir . $hash . ($shardId !== null ? '_s' . $shardId : '') . '.nonedb.sidx.' . $name;
    }

    private function getGlobalSpatialIndexPath(string $dbname, string $field): string {
        return $this->secondaryPath($dbname, 'grx', $field);
    }

    private function getLegacyGlobalSpatialIndexPath(string $dbname, string $field): string {
        return $this->dbDir . $this->hashDBName($dbname) . '.nonedb.gsidx.' . $this->encodeIndexFieldName($field);
    }

    /**
     * Integer box of a geometry (outwards), or null when it has no position
     */
    private function quantizedMBR(array $geometry): ?array {
        $mbr = $this->calculateMBR($geometry);
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

    private static function boxArea(array $a): float {
        return ((float)$a[2] - $a[0]) * ((float)$a[3] - $a[1]);
    }

    private function newRTree(string $field, int $nodeSize): array {
        return ['kind' => 'rx', 'field' => $field, 'ns' => $nodeSize, 's' => 0, 'ck' => -1, 'e' => null, 'bytes' => 0, 'legacy' => false,
            'nodes' => [0 => [0, null, []]], 'root' => 0, 'next' => 1, 'mk' => -1, 'cnt' => 0, 'leafOf' => null, 'parent' => null];
    }

    /**
     * Bulk load (sort-tile-recursive): leaves 3/4 full, ties broken by key (the same tree on every PHP)
     * @param array $entries list of [key, box]
     */
    private function rtreeBulkLoad(array &$tree, array $entries): void {
        $capacity = max(2, intdiv($tree['ns'] * 3, 4));
        $tree['nodes'] = [];
        $tree['next'] = 0;
        $tree['cnt'] = count($entries);
        $tree['leafOf'] = null;
        $tree['parent'] = null;
        if(empty($entries)){
            $tree['nodes'][0] = [0, null, []];
            $tree['root'] = 0;
            $tree['next'] = 1;
            return;
        }
        $level = 0;
        $items = [];
        foreach($entries as $entry){
            $items[] = [$entry[1], $entry];
        }
        while(true){
            $count = count($items);
            $groups = (int)ceil($count / $capacity);
            $slices = (int)ceil(sqrt($groups));
            usort($items, function($a, $b){
                return ($a[0][0] + $a[0][2]) <=> ($b[0][0] + $b[0][2]) ?: self::rtreeTie($a, $b);
            });
            $perSlice = $slices * $capacity;
            $next = [];
            for($i = 0; $i < $count; $i += $perSlice){
                $slice = array_slice($items, $i, $perSlice);
                usort($slice, function($a, $b){
                    return ($a[0][1] + $a[0][3]) <=> ($b[0][1] + $b[0][3]) ?: self::rtreeTie($a, $b);
                });
                foreach(array_chunk($slice, $capacity) as $chunk){
                    $id = $tree['next']++;
                    $box = null;
                    $payload = [];
                    foreach($chunk as $item){
                        $box = self::boxUnion($box, $item[0]);
                        $payload[] = $level === 0 ? [$item[1][0], $item[1][1][0], $item[1][1][1], $item[1][1][2], $item[1][1][3]] : $item[1];
                    }
                    $tree['nodes'][$id] = [$level, $box, $payload];
                    $next[] = [$box, $id];
                }
            }
            if(count($next) === 1){
                $tree['root'] = $next[0][1];
                return;
            }
            $items = $next;
            $level++;
        }
    }

    private static function rtreeTie(array $a, array $b): int {
        $ka = is_array($a[1]) ? $a[1][0] : $a[1];
        $kb = is_array($b[1]) ? $b[1][0] : $b[1];
        return $ka <=> $kb;
    }

    /**
     * Parent and leaf maps (built when the first change needs them)
     */
    private function rtreeMaps(array &$tree): void {
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
        $box = is_array($geometry) && $this->validateGeoJSON($geometry)['valid'] ? $this->quantizedMBR($geometry) : null;
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
    private function buildSegmentSpatialIndex(string $dbname, string $field, ?int $shardId, array $options = []): array {
        $key = $this->keyIndexWithEpoch($dbname, $shardId);
        $nodeSize = isset($options['nodeSize']) && (int)$options['nodeSize'] >= 4 ? (int)$options['nodeSize'] : $this->rtreeNodeSize;
        $tree = $this->newRTree($field, $nodeSize);
        $entries = [];
        foreach($this->readSegmentRecords($dbname, $shardId) as $k => $record){
            $geometry = $record[$field] ?? null;
            if(is_array($geometry) && $this->validateGeoJSON($geometry)['valid']){
                $box = $this->quantizedMBR($geometry);
                if($box !== null){
                    $entries[] = [(int)$k, $box];
                }
            }
        }
        $this->rtreeBulkLoad($tree, $entries);
        $tree['mk'] = $key === null || empty($key['p']) ? -1 : max(array_keys($key['p']));
        $tree['s'] = $key === null ? 0 : (int)$key['s'];
        $tree['e'] = $key === null ? null : $key['e'];
        return $tree;
    }

    /**
     * A segment's R-tree, current (the data lines after the bytes it covers are replayed); null when
     * none fits the segment (see ensureSegmentSpatialIndex)
     */
    private function readSpatialIndex(string $dbname, string $field, ?int $shardId = null): ?array {
        $key = $this->currentKeyIndex($dbname, $shardId);
        if($key === null){
            return null;
        }
        $path = $this->getSpatialIndexPath($dbname, $field, $shardId);
        self::$cacheTouch[$path] = ++self::$cacheTick;
        if(isset($this->spatialIndexCache[$path])){
            $tree = $this->spatialIndexCache[$path];
            if($tree['e'] !== null && $tree['e'] === $key['e'] && $tree['s'] <= (int)$key['s']){
                if($tree['s'] < (int)$key['s']){
                    unset($this->spatialIndexCache[$path], $tree['due']);
                    $this->replaySpatialIndex($dbname, $shardId, $tree, (int)$key['s']);
                    $this->spatialIndexCache[$path] = $tree;
                    if($this->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'rx', $field, $tree)){
                        $this->spatialIndexCache[$path]['due'] = true;
                    }
                } elseif(isset($tree['due']) && self::$noWrites === 0 && self::$multiLock === 0){
                    unset($tree['due'], $this->spatialIndexCache[$path]['due']); // a checkpoint a read-only load put off
                    if($this->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'rx', $field, $tree)){
                        $this->spatialIndexCache[$path]['due'] = true;
                    }
                }
                return $this->spatialIndexCache[$path];
            }
            unset($this->spatialIndexCache[$path]);
        }
        $file = $this->readSecondaryFile($path);
        if($file === null || !$this->secondaryFits($dbname, $shardId, $file[0], 'rx', $field)){
            return null;
        }
        $nodes = json_decode($file[1], true);
        $header = $file[0];
        if(!is_array($nodes) || !isset($header['root'], $header['next'], $nodes[$header['root']])){
            return null;
        }
        $tree = $this->newRTree($field, (int)($header['ns'] ?? $this->rtreeNodeSize));
        $tree['nodes'] = [];
        foreach($nodes as $id => $node){
            $tree['nodes'][(int)$id] = $node;
        }
        $tree['root'] = (int)$header['root'];
        $tree['next'] = (int)$header['next'];
        $tree['mk'] = (int)($header['mk'] ?? -1);
        $tree['cnt'] = (int)($header['cnt'] ?? 0);
        $tree['s'] = $header['s'];
        $tree['ck'] = $header['s'];
        $tree['e'] = $header['e'];
        $tree['bytes'] = strlen($file[1]);
        $this->replaySpatialIndex($dbname, $shardId, $tree, (int)$key['s']);
        $this->spatialIndexCache[$path] = $tree;
        self::$cacheGrew = true;
        if($this->maybeSecondaryReaderCheckpoint($dbname, $shardId, 'rx', $field, $tree)){
            $this->spatialIndexCache[$path]['due'] = true;
        }
        return $this->spatialIndexCache[$path];
    }

    private function replaySpatialIndex(string $dbname, ?int $shardId, array &$tree, int $to): void {
        foreach($this->segmentTail($dbname, $shardId, $tree['s'], $to) as [$k, $record]){
            $this->rtreeApply($tree, (int)$k, $record);
        }
        $tree['s'] = $to;
    }

    /**
     * A segment's v3.2 R-tree (only for a segment this version hasn't written yet), searched as it is
     */
    private function readLegacySpatialIndex(string $dbname, string $field, ?int $shardId): ?array {
        $path = $this->getLegacySpatialIndexPath($dbname, $field, $shardId);
        if(array_key_exists($path, $this->spatialIndexCache)){
            return $this->spatialIndexCache[$path];
        }
        $index = $this->atomicRead($path, null);
        if(!is_array($index) || ($index['v'] ?? 0) < 3 || !isset($index['nodes'], $index['rootId'], $index['leafOf'])
            || (isset($index['field']) && $index['field'] !== $field)){
            $index = null;
        } else {
            $index['legacy'] = true;
        }
        $this->spatialIndexCache[$path] = $index;
        self::$cacheGrew = true;
        return $index;
    }

    /**
     * Write an R-tree checkpoint
     */
    private function writeSpatialIndex(string $dbname, string $field, array &$tree, ?int $shardId = null): bool {
        if(!empty($tree['legacy']) || $tree['e'] === null){
            return false;
        }
        $path = $this->getSpatialIndexPath($dbname, $field, $shardId);
        $crc = $this->segmentCrc($this->segDataPath($dbname, $shardId), (int)$tree['s']);
        if($crc === null){
            return false;
        }
        $nodes = [];
        foreach($tree['nodes'] as $id => $node){
            $nodes[(string)$id] = $node;
        }
        $body = $this->encodeJson($nodes);
        if($body === false){
            return false;
        }
        $rootBox = $tree['nodes'][$tree['root']][1];
        $this->writeSecondaryFile($dbname, $path, ['v' => 4, 'kind' => 'rx', 'field' => $field, 'q' => self::RTREE_SCALE, 'ns' => $tree['ns'],
            's' => (int)$tree['s'], 'e' => $tree['e'], 'h' => $crc, 'mk' => $tree['mk'], 'cnt' => $tree['cnt'],
            'root' => $tree['root'], 'next' => $tree['next'], 'mbr' => $rootBox], $body . "\n");
        $tree['ck'] = (int)$tree['s'];
        $tree['bytes'] = strlen($body) + 1;
        $this->spatialIndexCache[$path] = $tree;
        self::$cacheGrew = true;
        return true;
    }

    /**
     * Spatial indexes are written as checkpoints when a write session ends: nothing waits here (kept
     * public for compatibility)
     * @return int 0
     */
    public function flushSpatialIndexes(): int {
        return 0;
    }

    private function forgetDirtySpatial(string $dbname): void {
    }

    /**
     * Read the global spatial index: ['le', 'sh' => [shard => ['s','e','h','b' => box or null]]]
     */
    private function readGlobalSpatialIndex(string $dbname, string $field): ?array {
        $path = $this->getGlobalSpatialIndexPath($dbname, $field);
        self::$cacheTouch[$path] = ++self::$cacheTick;
        if(array_key_exists($path, $this->globalSpatialIndexCache)){
            return $this->globalSpatialIndexCache[$path];
        }
        $header = $this->readSecondaryHeader($path);
        $global = null;
        if(is_array($header) && ($header['kind'] ?? null) === 'grx' && ($header['field'] ?? null) === $field && is_array($header['sh'] ?? null)){
            $global = ['le' => $header['le'] ?? null, 'sh' => []];
            foreach($header['sh'] as $shardId => $entry){
                if(is_array($entry) && isset($entry['s'], $entry['e'], $entry['h']) && array_key_exists('b', $entry)){
                    $global['sh'][(int)$shardId] = $entry;
                }
            }
        }
        $this->globalSpatialIndexCache[$path] = $global;
        self::$cacheGrew = true;
        return $global;
    }

    private function writeGlobalSpatialIndex(string $dbname, string $field, array $global): void {
        $path = $this->getGlobalSpatialIndexPath($dbname, $field);
        $lock = self::$lockRegistry[$this->getLockPath($dbname)] ?? null;
        $global['le'] = $lock === null ? null : $this->registryEpoch($this->getLockPath($dbname));
        $sh = [];
        foreach($global['sh'] as $shardId => $entry){
            $sh[(string)$shardId] = $entry;
        }
        $this->writeSecondaryFile($dbname, $path, ['v' => 4, 'kind' => 'grx', 'field' => $field, 'q' => self::RTREE_SCALE,
            'le' => $global['le'], 'sh' => empty($sh) ? new \stdClass() : $sh], '');
        $this->globalSpatialIndexCache[$path] = $global;
        unset(self::$lockRegistry[$this->getLockPath($dbname)]['globals'][$path]);
    }

    /**
     * Record a shard's box (at the bytes its R-tree covers) in the global spatial index
     */
    private function updateGlobalSpatialShard(string $dbname, string $field, int $shardId, array $tree): void {
        $entry = $this->globalSpatialEntry($dbname, $shardId, $tree);
        if($entry === null){
            return;
        }
        $global = $this->freshGlobalEntries($dbname, $this->readGlobalSpatialIndex($dbname, $field) ?? ['le' => null, 'sh' => []]);
        $lockPath = $this->getLockPath($dbname);
        if(($global['sh'][$shardId] ?? null) === $entry && $global['le'] === (isset(self::$lockRegistry[$lockPath]) ? $this->registryEpoch($lockPath) : null)){
            return;
        }
        $global['sh'][$shardId] = $entry;
        $this->deferGlobalWrite($dbname, 'grx', $field, $global);
    }

    /**
     * A shard's entry in the global spatial index: its R-tree's root box at the bytes it covers (null:
     * none can be recorded)
     */
    private function globalSpatialEntry(string $dbname, int $shardId, array $tree): ?array {
        if(!empty($tree['legacy']) || $tree['e'] === null){
            return null;
        }
        $crc = $this->segmentCrc($this->segDataPath($dbname, $shardId), (int)$tree['s']);
        return $crc === null ? null : ['s' => (int)$tree['s'], 'e' => $tree['e'], 'h' => $crc, 'b' => $tree['nodes'][$tree['root']][1]];
    }

    /**
     * A segment's R-tree: the current one, a v3.2 one while the segment isn't converted, or built from
     * the data (exclusive lock)
     */
    private function ensureSegmentSpatialIndex(string $dbname, string $field, ?int $shardId): array {
        $tree = $this->readSpatialIndex($dbname, $field, $shardId);
        if($tree !== null){
            return $tree;
        }
        if(!$this->segmentIsConverted($dbname, $shardId)){
            $legacy = $this->readLegacySpatialIndex($dbname, $field, $shardId);
            if($legacy !== null){
                return $legacy;
            }
        }
        if(self::$noWrites > 0){
            throw new \RuntimeException("noneDB: no spatial index to read in a read-only check");
        }
        return $this->withLock($dbname, true, function () use ($dbname, $field, $shardId) {
            $tree = $this->readSpatialIndex($dbname, $field, $shardId);
            if ($tree === null) {
                $nodeSize = $this->getIndexManifest($dbname)['spatialOptions'][$field]['nodeSize'] ?? null;
                $tree = $this->buildSegmentSpatialIndex($dbname, $field, $shardId, $nodeSize ? ['nodeSize' => $nodeSize] : []);
                $this->writeSpatialIndex($dbname, $field, $tree, $shardId);
                if ($shardId !== null) {
                    $this->updateGlobalSpatialShard($dbname, $field, $shardId, $tree);
                }
            }
            return $tree;
        });
    }

    /**
     * Keys of a segment whose geometry box may overlap the query boxes (float [minLon, minLat, maxLon,
     * maxLat], or a list of them); every caller checks the exact geometry
     */
    private function rtreeSearchMBR(string $dbname, string $field, array $searchMBR, ?int $shardId = null): array {
        $tree = $this->ensureSegmentSpatialIndex($dbname, $field, $shardId);
        $boxes = is_array($searchMBR[0] ?? null) ? $searchMBR : [$searchMBR];
        if(!empty($tree['legacy'])){
            $results = [];
            foreach($boxes as $box){
                $found = [];
                $this->legacyRtreeSearch($tree, (string)$tree['rootId'], $box, $found);
                foreach($found as $key){
                    $results[$key] = $key;
                }
            }
            return array_values($results);
        }
        return $this->rtreeSearch($tree, array_map([$this, 'quantizeBox'], $boxes));
    }

    /**
     * Search a v3.2 R-tree (float boxes, nodes with entries/children)
     */
    private function legacyRtreeSearch(array $index, string $nodeId, array $box, array &$results): void {
        $node = $index['nodes'][$nodeId] ?? null;
        if($node === null || $node['mbr'] === null || !$this->mbrOverlaps($node['mbr'], $box)){
            return;
        }
        if($node['isLeaf']){
            foreach($node['entries'] as $entry){
                if($this->mbrOverlaps($entry['mbr'], $box)){
                    $results[] = $entry['key'];
                }
            }
        } else {
            foreach($node['children'] as $childId){
                $this->legacyRtreeSearch($index, (string)$childId, $box, $results);
            }
        }
    }

    /**
     * Rebuild a spatial index for the whole database (all segments + global shard boxes)
     */
    private function rebuildSpatialIndexForDb($dbname, $field, $nodeSize = null){
        $manifest = $this->getIndexManifest($dbname);
        $nodeSize = $nodeSize ?? $manifest['spatialOptions'][$field]['nodeSize'] ?? null;
        $options = $nodeSize ? ['nodeSize' => $nodeSize] : [];
        $segments = $this->listSegments($dbname);
        if(!in_array(null, $segments, true)){
            $segments[] = null; // leftovers of the non-sharded database
        }
        foreach($segments as $segment){
            foreach([$this->getSpatialIndexPath($dbname, $field, $segment), $this->getLegacySpatialIndexPath($dbname, $field, $segment)] as $path){
                $this->fsUnlink($path);
                unset($this->spatialIndexCache[$path]);
            }
        }
        foreach([$this->getGlobalSpatialIndexPath($dbname, $field), $this->getLegacyGlobalSpatialIndexPath($dbname, $field)] as $path){
            $this->fsUnlink($path);
            unset($this->globalSpatialIndexCache[$path]);
        }
        $indexed = 0;
        $sharded = $this->isSharded($dbname);
        $global = ['le' => null, 'sh' => []]; // written once at the end (once per shard was O(shards²))
        foreach ($this->listSegments($dbname) as $segment) {
            $tree = $this->buildSegmentSpatialIndex($dbname, $field, $segment, $options);
            $this->writeSpatialIndex($dbname, $field, $tree, $segment);
            $indexed += $tree['cnt'];
            if ($sharded && ($entry = $this->globalSpatialEntry($dbname, $segment, $tree)) !== null) {
                $global['sh'][$segment] = $entry;
            }
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
    private function removeSpatialShardFiles($dbname, $shardId){
        foreach (array_merge(
            $this->listFilesWithPrefix($this->dbBase($dbname) . '_s' . (int)$shardId . '.nonedb.rx.'),
            $this->listFilesWithPrefix($this->dbDir . $this->hashDBName($dbname) . '_s' . (int)$shardId . '.nonedb.sidx.')
        ) as $file) {
            $this->fsUnlink($file);
            unset($this->spatialIndexCache[$file]);
        }
        foreach ($this->getIndexManifest($dbname)['spatial'] as $field) {
            $global = $this->readGlobalSpatialIndex($dbname, $field);
            if ($global !== null && isset($global['sh'][(int)$shardId])) {
                unset($global['sh'][(int)$shardId]);
                $this->writeGlobalSpatialIndex($dbname, $field, $global);
            }
        }
    }

    /**
     * Spatial indexes follow the data by themselves (replayed when used): kept for the write paths
     */
    private function applySpatialIndexChanges($dbname, $shardId, array $removed, array $added){
    }

    /**
     * True when a shard can be skipped for the query boxes: its global entry fits the shard, its box
     * misses every box, and the shard's newer lines hold no geometry in them
     */
    private function globalSpatialSkips(string $dbname, string $field, int $shardId, ?array $global, array $boxes): bool {
        $entry = $global['sh'][$shardId] ?? null;
        if($entry === null || !$this->globalEntryFits($dbname, $shardId, $entry, $global['le'])){
            return false;
        }
        $quantized = array_map([$this, 'quantizeBox'], $boxes);
        foreach($quantized as $box){
            if(self::boxOverlaps($entry['b'], $box)){
                return false;
            }
        }
        return !$this->shardTailMayMatch($dbname, $shardId, $entry, function($record) use ($field, $quantized){
            $geometry = $record[$field] ?? null;
            if(!is_array($geometry) || !$this->validateGeoJSON($geometry)['valid']){
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field, $shardId){
            return $this->hasSpatialIndexUnlocked($dbname, $field, $shardId);
        });
    }

    private function hasSpatialIndexUnlocked(string $dbname, string $field, ?int $shardId = null): bool{
        if (!$this->dbExists($dbname) || !in_array($field, $this->getIndexManifest($dbname)['spatial'], true)) {
            return false;
        }
        return $shardId === null || in_array($shardId, $this->listSegments($dbname), true);
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withLock($dbname, true, function() use ($dbname, $field, $options){
            return $this->createSpatialIndexUnlocked($dbname, $field, $options);
        });
    }

    private function createSpatialIndexUnlocked(string $dbname, string $field, array $options = []): array{
        if (!$this->spatialIndexEnabled) {
            return ['success' => false, 'indexed' => 0, 'error' => 'Spatial indexing is disabled'];
        }
        if ($field === '' || $field === 'key') {
            return ['success' => false, 'indexed' => 0, 'error' => 'Invalid field name'];
        }
        $this->flushPendingBuffers($dbname);
        if (!$this->dbExists($dbname)) {
            if (!$this->autoCreateDB) {
                return ['success' => false, 'indexed' => 0, 'error' => 'Database does not exist'];
            }
            $this->createDBUnlocked($dbname);
        }

        $manifest = $this->getIndexManifest($dbname);
        if (in_array($field, $manifest['spatial'], true)) {
            return ['success' => false, 'indexed' => 0, 'error' => "Spatial index already exists for field '$field'"];
        }
        $nameError = $this->indexNameError($dbname, $field);
        if ($nameError !== null) {
            return ['success' => false, 'indexed' => 0, 'error' => $nameError];
        }

        // Build first, list it last: an index that fails half-way is never listed (B-10)
        $nodeSize = isset($options['nodeSize']) && (int)$options['nodeSize'] >= 4 ? (int)$options['nodeSize'] : null;
        $indexed = $this->rebuildSpatialIndexForDb($dbname, $field, $nodeSize);
        $manifest = $this->getIndexManifest($dbname);
        $manifest['spatial'][] = $field;
        if ($nodeSize !== null) {
            $manifest['spatialOptions'][$field] = ['nodeSize' => $nodeSize];
        }
        $this->saveIndexManifest($dbname, $manifest);
        return ['success' => true, 'indexed' => $indexed, 'error' => null];
    }

    /**
     * Drop a spatial index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array ['success' => bool, 'error' => ?string]
     */
    public function dropSpatialIndex(string $dbname, string $field): array{
        $dbname = $this->normalizeDbName($dbname);
        return $this->withLock($dbname, true, function() use ($dbname, $field){
            return $this->dropSpatialIndexUnlocked($dbname, $field);
        });
    }

    private function dropSpatialIndexUnlocked(string $dbname, string $field): array{
        foreach (array_merge([null], $this->isSharded($dbname) ? $this->listSegments($dbname) : []) as $segment) {
            foreach ([$this->getSpatialIndexPath($dbname, $field, $segment), $this->getLegacySpatialIndexPath($dbname, $field, $segment)] as $path) {
                $this->fsUnlink($path);
                unset($this->spatialIndexCache[$path]);
            }
        }
        foreach ([$this->getGlobalSpatialIndexPath($dbname, $field), $this->getLegacyGlobalSpatialIndexPath($dbname, $field)] as $globalPath) {
            $this->fsUnlink($globalPath);
            unset($this->globalSpatialIndexCache[$globalPath]);
        }

        if ($this->dbExists($dbname)) {
            $manifest = $this->getIndexManifest($dbname);
            $manifest['spatial'] = array_values(array_diff($manifest['spatial'], [$field]));
            unset($manifest['spatialOptions'][$field]);
            $this->saveIndexManifest($dbname, $manifest);
        }
        return ['success' => true, 'error' => null];
    }

    /**
     * Get list of spatial indexes for a database
     * @param string $dbname Database name
     * @return array List of indexed field names
     */
    public function getSpatialIndexes(string $dbname): array{
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname){
            return $this->getSpatialIndexesUnlocked($dbname);
        });
    }

    private function getSpatialIndexesUnlocked(string $dbname): array{
        return $this->dbExists($dbname) ? $this->getIndexManifest($dbname)['spatial'] : [];
    }

    /**
     * Rebuild a spatial index
     * @param string $dbname Database name
     * @param string $field Field name
     * @return array Result from createSpatialIndex
     */
    public function rebuildSpatialIndex(string $dbname, string $field): array{
        $dbname = $this->normalizeDbName($dbname);
        return $this->withLock($dbname, true, function() use ($dbname, $field){
            return $this->rebuildSpatialIndexUnlocked($dbname, $field);
        });
    }

    private function rebuildSpatialIndexUnlocked(string $dbname, string $field): array{
        if (!$this->spatialIndexEnabled) {
            // Checked before dropping anything: the drop used to succeed and the create to fail (B-15)
            return ['success' => false, 'indexed' => 0, 'error' => 'Spatial indexing is disabled'];
        }
        $options = [];
        $nodeSize = $this->dbExists($dbname) ? ($this->getIndexManifest($dbname)['spatialOptions'][$field]['nodeSize'] ?? null) : null;
        if ($nodeSize) {
            $options['nodeSize'] = $nodeSize;
        }
        $this->dropSpatialIndexUnlocked($dbname, $field);
        return $this->createSpatialIndexUnlocked($dbname, $field, $options);
    }

    // ==========================================
    // SPATIAL QUERIES
    // ==========================================

    /**
     * Records whose geometry may overlap the search boxes: [key => record].
     * Uses the R-tree (and shard boxes) when the field is indexed; otherwise every record.
     * @param array $boxes List of MBRs
     */
    private function spatialCandidates(string $dbname, string $field, array $boxes): array {
        if (!$this->dbExists($dbname)) {
            return [];
        }
        $indexed = $this->spatialIndexEnabled && in_array($field, $this->getIndexManifest($dbname)['spatial'], true);
        $records = [];
        $this->indexedCandidates = [];
        $sharded = $this->isSharded($dbname);
        $global = ($indexed && $sharded) ? $this->readGlobalSpatialIndex($dbname, $field) : null;

        foreach ($this->listSegments($dbname) as $segment) {
            if (!$indexed) {
                $records += $this->readSegmentRecords($dbname, $segment);
                continue;
            }
            // Shard skip: only with an entry that fits the shard, misses the boxes, and no newer geometry of
            // the shard in them. A missing entry (or global file) means unknown: searched (B-9).
            if ($global !== null && $this->globalSpatialSkips($dbname, $field, $segment, $global, $boxes)) {
                continue;
            }
            try {
                $keys = $this->rtreeSearchMBR($dbname, $field, $boxes, $segment);
                if (empty($this->ensureSegmentSpatialIndex($dbname, $field, $segment)['legacy'])) {
                    foreach ($keys as $key) {
                        $this->indexedCandidates[$key] = true;
                    }
                }
            } catch (\Throwable $e) {
                if ($e->getCode() === self::LOCK_FAILED) {
                    throw $e;
                }
                $records += $this->readSegmentRecords($dbname, $segment); // index can't be built: scan (B-1)
                continue;
            }
            if (!empty($keys)) {
                $records += $this->readSegmentRecords($dbname, $segment, $keys);
            }
        }
        return $records;
    }

    /**
     * True when an MBR (null: empty) overlaps one of the boxes
     */
    private function anyBoxOverlaps(?array $mbr, array $boxes): bool {
        if ($mbr === null) {
            return false;
        }
        foreach ($boxes as $box) {
            if ($this->mbrOverlaps($mbr, $box)) {
                return true;
            }
        }
        return false;
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
    private function distanceToGeometry(float $lon, float $lat, array $geometry): float {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        switch ($type) {
            case 'Point':
                return $this->haversineDistance($lon, $lat, (float)$coords[0], (float)$coords[1]);
            case 'MultiPoint':
                $best = INF;
                foreach ($coords as $p) {
                    $best = min($best, $this->haversineDistance($lon, $lat, (float)$p[0], (float)$p[1]));
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
                if ($this->pointInPolygon($lon, $lat, $coords)) {
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field, $lon, $lat, $distanceMeters, $options){
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
            if (!isset($this->indexedCandidates[$record['key']]) && (!is_array($geometry) || !$this->validateGeoJSON($geometry)['valid'])) {
                continue; // a candidate of an R-tree (v3.3) has a valid geometry: checked when it was indexed (B-11)
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
        $this->clearDistanceCache();
        return $results;
    }

    /**
     * Find records within a bounding box (geometries whose bounding box overlaps it).
     * minLon > maxLon means the box crosses the antimeridian.
     * @return array Matching records
     */
    public function withinBBox(string $dbname, string $field, float $minLon, float $minLat, float $maxLon, float $maxLat): array{
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field, $minLon, $minLat, $maxLon, $maxLat){
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
            if (!isset($this->indexedCandidates[$record['key']]) && (!is_array($geometry) || !$this->validateGeoJSON($geometry)['valid'])) {
                continue; // a candidate of an R-tree (v3.3) has a valid geometry: checked when it was indexed (B-11)
            }
            $recordMBR = $this->calculateMBR($geometry);
            if ($recordMBR === null) {
                continue;
            }
            foreach ($boxes as $box) {
                if ($this->mbrOverlaps($recordMBR, $box)) {
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field, $lon, $lat, $k, $options){
            return $this->nearestUnlocked($dbname, $field, $lon, $lat, $k, $options);
        });
    }

    /**
     * True when spatial queries on the field use the R-tree (used by the query builder)
     */
    private function usesSpatialIndex(string $dbname, string $field): bool{
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field){
            return $this->spatialIndexEnabled && $this->dbExists($dbname)
                && in_array($field, $this->getIndexManifest($dbname)['spatial'], true);
        });
    }

    private function nearestUnlocked(string $dbname, string $field, float $lon, float $lat, int $k, array $options = []): array{
        $maxDistance = isset($options['maxDistance']) ? (float)$options['maxDistance'] : null;
        $includeDistance = $options['includeDistance'] ?? true;
        // NaN matches nothing (and NaN radii made the expanding search below loop forever)
        if ($k <= 0 || !is_finite($lon) || !is_finite($lat) || ($maxDistance !== null && is_nan($maxDistance)) || !$this->dbExists($dbname)) {
            return [];
        }

        $indexed = $this->spatialIndexEnabled && in_array($field, $this->getIndexManifest($dbname)['spatial'], true);
        // Half the earth's circumference covers everything (also an infinite maxDistance)
        $limit = min($maxDistance ?? self::EARTH_HALF_CIRCUMFERENCE, self::EARTH_HALF_CIRCUMFERENCE);
        $results = [];

        if ($indexed && $k < $this->countFast($dbname)) {
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
            foreach ($this->listSegments($dbname) as $segment) {
                foreach ($this->readSegmentRecords($dbname, $segment) as $record) {
                    $geometry = $record[$field] ?? null;
                    if (!is_array($geometry) || !$this->validateGeoJSON($geometry)['valid']) {
                        continue;
                    }
                    $distance = $this->distanceToGeometry($lon, $lat, $geometry);
                    if (is_finite($distance) && ($maxDistance === null || $distance <= $maxDistance)) {
                        $record['_distance'] = round($distance, 2);
                        $results[] = $record;
                    }
                }
            }
            usort($results, function ($a, $b) {
                return $a['_distance'] <=> $b['_distance'] ?: $a['key'] <=> $b['key'];
            });
        }

        $results = array_slice($results, 0, $k);
        if (!$includeDistance) {
            foreach ($results as &$r) {
                unset($r['_distance']);
            }
            unset($r);
        }
        $this->clearDistanceCache();
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withReadLock($dbname, function() use ($dbname, $field, $polygon){
            return $this->withinPolygonUnlocked($dbname, $field, $polygon);
        });
    }

    private function withinPolygonUnlocked(string $dbname, string $field, array $polygon): array{
        $validation = $this->validateGeoJSON($polygon);
        if (!$validation['valid'] || !in_array($validation['type'], ['Polygon', 'MultiPolygon'], true)) {
            return [];
        }
        $polygons = $validation['type'] === 'Polygon' ? [$polygon['coordinates']] : $polygon['coordinates'];
        $boxes = [];
        foreach ($polygons as $coords) {
            $boxes[] = $this->mbrFromPoints($coords[0]);
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
            if (!isset($this->indexedCandidates[$record['key']]) && (!is_array($geometry) || !$this->validateGeoJSON($geometry)['valid'])) {
                continue; // a candidate of an R-tree (v3.3) has a valid geometry: checked when it was indexed (B-11)
            }
            if ($this->geometryWithinParts($geometry, $parts)) {
                $results[] = $record;
            }
        }
        return $results;
    }

    /**
     * Within a Polygon or MultiPolygon query (its parts): a single geometry must fit one part; every
     * component of a multi-part geometry must fit some part (B-14). A component spanning two adjacent
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
                return is_array($g) && $this->calculateMBR($g) !== null;
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
                    if (is_array($g) && $this->calculateMBR($g) === null) {
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
            if ($this->pointInPolygon($x, $y, $rings)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Point inside a polygon or on its boundary (holes excluded, their boundary included)
     */
    private function pointInPolygonClosed(float $lon, float $lat, array $polygonCoords): bool {
        if (!$this->pointOnRing($lon, $lat, $polygonCoords[0]) && !$this->pointInRing($lon, $lat, $polygonCoords[0])) {
            return false;
        }
        for ($i = 1; $i < count($polygonCoords); $i++) {
            if ($this->pointInRing($lon, $lat, $polygonCoords[$i]) && !$this->pointOnRing($lon, $lat, $polygonCoords[$i])) {
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
                    if ($this->crossProductDirection($a, $b, $v) == 0 && $this->onSegment($a, $b, $v) && $a != $b) {
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
        $d1 = $this->crossProductDirection($p3, $p4, $p1);
        $d2 = $this->crossProductDirection($p3, $p4, $p2);
        $d3 = $this->crossProductDirection($p1, $p2, $p3);
        $d4 = $this->crossProductDirection($p1, $p2, $p4);
        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withLock($dbname, true, function() use ($dbname){
            return $this->flushUnlocked($dbname);
        });
    }

    private function flushUnlocked($dbname){
        $flushed = $this->flushPendingBuffers($dbname);
        return ['success' => true, 'flushed' => $flushed, 'error' => null];
    }

    /**
     * Flush the write buffers of every database (of this secretKey) in dbDir
     * @return array ['databases' => int, 'flushed' => int]
     */
    public function flushAllBuffers(){
        $names = [];
        if(!empty(self::$lockRegistry)){
            return ['databases' => 0, 'flushed' => 0]; // inside an interrupted operation (see releaseInterruptedSessions)
        }
        if(is_dir($this->dbDir)){
            foreach(scandir($this->dbDir) ?: [] as $file){
                if(preg_match("/^([0-9a-f]{20})-([A-Za-z0-9' -]+?)(?:_s\\d+)?\\.nonedb\\.buffer(?:\\.flushing(?:\\.\\d+)?)?$/", $file, $m)
                    && $this->hashDBName($m[2]) === $m[1]){
                    $names[$m[2]] = true;
                }
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
        $dbname = $this->normalizeDbName($dbname);
        $fn = function() use ($dbname){
            return $this->getBufferInfoUnlocked($dbname);
        };
        return $this->hasLockFile($dbname) || $this->dbFilesExist($dbname) ? $this->withLock($dbname, false, $fn) : $fn();
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
                'records' => $exists ? $this->getBufferRecordCount($path) : 0
            ];
        };
        $info['buffers']['main'] = $describe($this->getBufferPath($dbname));
        foreach($this->legacyShardBufferFiles($dbname) as $file){
            if(preg_match('/_s(\d+)\.nonedb\.buffer$/', $file, $m)){
                $info['buffers']['shard_' . $m[1]] = $describe($file);
            }
        }
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
        $dbname = $this->normalizeDbName($dbname);
        return $this->withLock($dbname, true, function() use ($dbname){
            $this->refuseInUncommittedWrite($dbname, 'compact');
            return $this->compactUnlocked($dbname);
        });
    }

    private function compactUnlocked($dbname){
        $result = array("success" => false, "freedSlots" => 0);
        $this->flushPendingBuffers($dbname);
        if(!$this->dbExists($dbname)){
            $result['status'] = 'database_not_found';
            return $result;
        }

        $sharded = $this->isSharded($dbname);
        $freed = 0;
        if($sharded){
            $meta = $this->getCachedMeta($dbname, true);
            $freed = (int)($meta['deletedCount'] ?? 0);
        }
        $total = 0;
        foreach($this->listSegments($dbname) as $segment){
            $compacted = $this->compactJsonl($dbname, $segment);
            if(!$sharded){
                $freed += $compacted['freed'];
            }
            $total += $compacted['compacted'];
        }
        if($sharded){
            $this->removeEmptyShards($dbname);
            $meta = $this->getCachedMeta($dbname, true);
            foreach($meta['shards'] as $i => $shard){
                $meta['shards'][$i]['deleted'] = 0;
            }
            $meta['deletedCount'] = 0;
            $meta['totalRecords'] = $total;
            $this->writeMeta($dbname, $meta);
        }

        $result['success'] = true;
        $result['freedSlots'] = $freed;
        $result['totalRecords'] = $total;
        $result['sharded'] = $sharded;
        if($sharded){
            $result['newShardCount'] = count($this->listSegments($dbname));
        }
        return $result;
    }

    /**
     * Remove shards without records (keys never move, so nothing else changes). The shard that holds
     * the newest key is kept: new records go there.
     */
    private function removeEmptyShards($dbname){
        $meta = $this->getCachedMeta($dbname, true);
        if($meta === null || count($meta['shards']) < 2){
            return;
        }
        $lastShard = intdiv(max(0, (int)$meta['nextKey'] - 1), (int)$meta['shardSize']);
        $kept = [];
        $removed = [];
        foreach($meta['shards'] as $shard){
            $shardId = (int)$shard['id'];
            $index = $this->readJsonlIndex($dbname, $shardId);
            if($shardId !== $lastShard && ($index === null || empty($index['p']))){
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
        $this->writeMeta($dbname, $meta, true);

        $manifest = $this->getIndexManifest($dbname);
        $this->bumpEpoch($dbname); // shard files go away
        foreach($removed as $shardId){
            $dataPath = $this->getShardPath($dbname, $shardId);
            foreach($manifest['fields'] as $field){
                $this->deleteFieldIndexFile($dbname, $field, $shardId);
            }
            $this->removeSpatialShardFiles($dbname, $shardId);
            $this->fsUnlink($dataPath . '.jidx');
            $this->fsUnlink($dataPath);
            $this->markFileNotExists($dataPath);
            unset($this->indexCache[$dataPath . '.jidx'], $this->jsonlFormatCache[$dataPath]);
        }
        $this->syncDirectory($this->dbDir);
        foreach($manifest['fields'] as $field){
            $this->rebuildGlobalFieldIndex($dbname, $field);
        }
    }
    /**
     * Manually trigger migration to sharded format
     * @param string $dbname
     * @return array ["success" => bool, "status" => string]
     */
    public function migrate($dbname){
        $dbname = $this->normalizeDbName($dbname);
        return $this->withLock($dbname, true, function() use ($dbname){
            $this->refuseInUncommittedWrite($dbname, 'migrate');
            return $this->migrateUnlocked($dbname);
        });
    }

    private function migrateUnlocked($dbname){
        if($this->isSharded($dbname)){
            return array("success" => true, "status" => "already_sharded");
        }
        $this->flushPendingBuffers($dbname);
        if($this->isSharded($dbname)){
            return array("success" => true, "status" => "migrated");
        }
        if(!$this->cachedFileExists($this->getDataPath($dbname))){
            return array("success" => false, "status" => "database_not_found");
        }
        if($this->migrateToSharded($dbname)){
            return array("success" => true, "status" => "migrated");
        }
        return array("success" => false, "status" => "migration_failed");
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

    // ==========================================
    // QUERY BUILDER (FLUENT INTERFACE)
    // ==========================================

    /**
     * Create a new query builder for fluent interface
     * @param string $dbname
     * @return noneDBQuery
     */
    public function query(string $dbname): noneDBQuery {
        return new noneDBQuery($this, $this->normalizeDbName($dbname));
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

    /** @var \Closure Calls noneDB internals without Reflection */
    private \Closure $internal;

    private const OPERATORS = ['$gt', '$gte', '$lt', '$lte', '$ne', '$eq', '$in', '$nin', '$exists', '$like', '$regex', '$contains'];

    /**
     * @param noneDB $db
     * @param string $dbname
     */
    public function __construct(noneDB $db, string $dbname) {
        $this->db = $db;
        $this->dbname = $dbname;
        $this->internal = \Closure::bind(function (string $method, array $args) {
            return $this->$method(...$args);
        }, $db, noneDB::class);
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
     * Call a private method of the parent noneDB instance
     * @param string $methodName
     * @param mixed ...$args
     * @return mixed
     */
    private function callPrivateMethod(string $methodName, ...$args) {
        return ($this->internal)($methodName, $args);
    }

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
                    $conditions[] = ['key', '#keys', array_flip($this->callPrivateMethod('normalizeKeyFilter', $value))];
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
     * Compare two values for ordering operators ($gt, between, ...).
     * Numbers (and numeric strings) compare numerically, other strings lexically; anything else
     * (null, booleans, arrays, number vs. non-numeric string) is not comparable.
     * @internal also used by noneDB::between(); not part of the public API
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
     * The number of an int, float or numeric string, else null. A numeric string may have leading and
     * trailing whitespace on every PHP version (7.4's is_numeric() rejects trailing whitespace; the sort
     * order and range indexes use the same rule)
     * @internal also used by noneDB's aggregates; not part of the public API
     * @return int|float|null
     */
    public static function numberOf($value) {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            $trimmed = rtrim($value, " \t\n\r\v\f");
            return is_numeric($trimmed) ? $trimmed + 0 : null;
        }
        return null;
    }

    /**
     * Compiled like()/$like pattern ("^" start anchor, trailing "$" end anchor, rest literal)
     */
    private function likeSpec($pattern): array {
        if (!is_scalar($pattern)) {
            throw new \InvalidArgumentException('noneDB: like pattern must be a string');
        }
        return self::compileLike((string)$pattern);
    }

    /**
     * Run preg_match, turning compile errors and backtrack-limit failures into exceptions
     */
    private static function regexMatch(string $regex, string $subject): bool {
        $result = @preg_match($regex, $subject);
        if ($result === false) {
            $error = preg_last_error();
            if ($error === PREG_BAD_UTF8_ERROR || $error === PREG_BAD_UTF8_OFFSET_ERROR) {
                return false; // subject isn't UTF-8: can't match a UTF-8 pattern
            }
            if ($error === PREG_NO_ERROR) {
                throw new \InvalidArgumentException('noneDB: invalid regular expression');
            }
            throw new \RuntimeException('noneDB: regular expression failed (' . (function_exists('preg_last_error_msg') ? preg_last_error_msg() : 'error ' . $error) . ')');
        }
        return $result === 1;
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
                $c = $hasField ? self::compareValues($fieldValue, $operand) : null;
                return $c !== null && $c > 0;
            case '$gte':
                $c = $hasField ? self::compareValues($fieldValue, $operand) : null;
                return $c !== null && $c >= 0;
            case '$lt':
                $c = $hasField ? self::compareValues($fieldValue, $operand) : null;
                return $c !== null && $c < 0;
            case '$lte':
                $c = $hasField ? self::compareValues($fieldValue, $operand) : null;
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
                return self::likeMatch($operand, (string)$fieldValue);
            case '$regex':
                if (!$hasField || !is_scalar($fieldValue) || is_bool($fieldValue)) {
                    return false;
                }
                return self::regexMatch($operand, (string)$fieldValue);
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
            if (!self::likeMatch($like['like'], (string)$value)) return false;
        }

        foreach ($this->notLikeFilters as $notLike) {
            $value = $record[$notLike['field']] ?? null;
            if (is_scalar($value) && !is_bool($value) && self::likeMatch($notLike['like'], (string)$value)) {
                return false;
            }
        }

        foreach ($this->betweenFilters as $between) {
            if (!array_key_exists($between['field'], $record)) return false;
            $value = $record[$between['field']];
            $lo = self::compareValues($value, $between['min']);
            $hi = self::compareValues($value, $between['max']);
            if ($lo === null || $hi === null || $lo < 0 || $hi > 0) return false;
        }

        foreach ($this->notBetweenFilters as $notBetween) {
            if (!array_key_exists($notBetween['field'], $record)) continue;
            $value = $record[$notBetween['field']];
            $lo = self::compareValues($value, $notBetween['min']);
            $hi = self::compareValues($value, $notBetween['max']);
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
        if (noneDB::hasMbstring() && preg_match('//u', $haystack) === 1 && preg_match('//u', $needle) === 1) {
            return strpos(self::foldCase($haystack), self::foldCase($needle)) !== false;
        }
        return stripos($haystack, $needle) !== false;
    }

    /**
     * Case folding for matching (Unicode full folding: "ß" and "ẞ" are "ss", "ſ" is "s", "ς" is "σ").
     * "İ" folds to "i" + combining dot (U+0307); the dot is dropped so "İzmir", "IZMIR" and "izmir" all
     * match each other. Language independent: "I" and "ı" don't match.
     */
    private static function foldCase(string $s): string {
        return str_replace("\u{0307}", '', mb_convert_case($s, MB_CASE_FOLD, 'UTF-8'));
    }

    /**
     * Compile a like() pattern: a leading "^" anchors the start, a trailing "$" the end, everything else
     * is literal and case-insensitive.
     * @internal also used by noneDB::like(); not part of the public API
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
            'folded' => $utf8 && noneDB::hasMbstring() ? self::foldCase($body) : null,
            // Otherwise a regex (PCRE /iu: simple case folding)
            // ("D": the end anchor is the end of the value, not also before a final newline)
            'regex' => '~' . ($start ? '^' : '') . preg_quote($body, '~') . ($end ? '$' : '') . '~D' . ($utf8 ? 'iu' : 'i'),
        ];
    }

    /**
     * Match a compiled like() pattern
     * @internal also used by noneDB::like(); not part of the public API
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
        $valueKey = $this->callPrivateMethod('valueKeyFunction');
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
                // nearest() orders and cuts by its own distance (B-4): its order and _distance win
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
                    $and[] = ['keys', $this->callPrivateMethod('normalizeKeyFilter', $value)];
                } elseif (!is_array($value)) {
                    $and[] = ['eq', $field, $value];
                }
                continue;
            }
            foreach ($value as $operator => $operand) {
                if ($operator === '$eq' && !is_array($operand)) {
                    $and[] = $field === 'key' ? ['keys', $this->callPrivateMethod('normalizeKeyFilter', $operand)] : ['eq', $field, $operand];
                } elseif ($operator === '$in' && is_array($operand)) {
                    $and[] = $field === 'key' ? ['keys', $this->callPrivateMethod('normalizeKeyFilter', array_values($operand))] : $this->inPlan($field, $operand);
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
        $dbname = $this->callPrivateMethod('normalizeDbName', $this->dbname);
        return $this->callPrivateMethod('withReadLock', $dbname, function () use ($earlyExit, $target, $topK) {
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
            && $this->callPrivateMethod('usesSpatialIndex', $this->dbname, $this->spatialFilters[0]['field'])) {
            $nearestRadius = 1000.0;
            $total = $this->db->count($this->dbname);
        }

        $needWhere = !empty($this->whereFilters) || !empty($this->orWhereFilters);
        $where = $needWhere ? $this->compileGroups($this->whereFilters) : [];
        $orWhere = $needWhere ? $this->compileGroups($this->orWhereFilters) : [];
        $needAdvanced = $this->hasAdvancedFilters();
        if ($target !== null && $earlyExit && $nearest === null) {
            // exists(): independent of sort
        } else {
            $target = ($earlyExit && $nearest === null && empty($this->sortKeys) && $this->limitCount !== null)
                ? $this->offsetCount + $this->limitCount
                : PHP_INT_MAX;
        }
        // The usual case, one where() group: no need to go through the AND/OR logic per record
        $single = ($needWhere && count($where) === 1 && empty($orWhere)) ? $where[0] : null;

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
                return $this->scanFiltered($single, $where, $orWhere, $needWhere, $needAdvanced, $target, $topK);
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
     * filteredRecordsLocked() without spatial filters: the plan's candidates, every filter checked
     * @param int $target Stop after this many matches
     * @param int|null $topK Keep only the best $topK by the sort keys (records arrive in key order)
     */
    private function scanFiltered(?array $single, array $where, array $orWhere, bool $needWhere, bool $needAdvanced, int $target, ?int $topK): array {
        $filtered = [];
        $prune = $topK === null ? PHP_INT_MAX : max(4 * $topK, 1024);
        $dbname = $this->callPrivateMethod('normalizeDbName', $this->dbname);
        $this->lastScan = $this->callPrivateMethod('scanRecords', $dbname, $this->buildPlan(),
            function (array $records) use (&$filtered, $single, $where, $orWhere, $needWhere, $needAdvanced, $target, $topK, $prune) {
                foreach ($records as $record) {
                    if ($single !== null) {
                        if (!$this->matchesGroup($record, $single)) continue;
                    } elseif ($needWhere && !$this->matchesWhereLogic($record, $where, $orWhere)) {
                        continue;
                    }
                    if ($needAdvanced && !$this->matchesAdvancedFilters($record)) continue;
                    $filtered[] = $record;
                    if ($topK !== null) {
                        if (count($filtered) > $prune) {
                            $filtered = array_slice($this->applySort($filtered), 0, $topK);
                        }
                    } elseif (count($filtered) >= $target) {
                        return false;
                    }
                }
                return true;
            }, $target === PHP_INT_MAX);
        return $filtered;
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
            $distance = $this->callPrivateMethod('distanceToGeometry',
                (float)$this->distanceConfig['lon'], (float)$this->distanceConfig['lat'], $geometry);
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
                // Joining on record keys: read only the keys asked for (T2-1)
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
        $valueKey = $this->callPrivateMethod('valueKeyFunction');
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
                        $number = self::numberOf($item[$aggField] ?? null);
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
        $sortKey = $this->callPrivateMethod('sortKeyFunction');
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
            // fields apply to the items of each group (C-13: select(['name']) used to empty every group)
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
     * How the query reads its records (v3.3.0): per segment whether it is skipped (a global field index or
     * the keys rule it out), read through an index (candidates) or scanned; how it sorts (top-k, full) and
     * whether it stops early. Every record read is checked against every filter ("residual": always).
     * Nothing is built or written; an index a query would build first counts as a scan. With $analyze the
     * query runs too and "actual" reports what it read and returned.
     * @return array
     */
    public function explain(bool $analyze = false): array {
        $dbname = $this->callPrivateMethod('normalizeDbName', $this->dbname);
        $plan = $this->buildPlan();
        $info = $this->callPrivateMethod('explainPlan', $dbname, $plan);
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
            return $this->callPrivateMethod('withSnapshot', $databases, function () use ($project) {
                return $this->resultsLocked($project);
            });
        }
        return $this->resultsLocked($project);
    }

    private function resultsLocked(bool $project): array {
        // Joins only add fields: without groupBy() and without sorting by a join alias they run on the
        // records left after sort, offset and limit (T2-2), which also allows stopping early
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
     * Keys of the records a write (update/delete/removeFields) applies to:
     * filters, sort, offset and limit apply; select/except/join don't matter.
     * @return int[]
     */
    private function targetKeys(): array {
        if ($this->groupByField !== null) {
            throw new \LogicException('noneDB: update(), delete() and removeFields() cannot be combined with groupBy()');
        }
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
     */
    private function withWriteLock(callable $fn) {
        $dbname = $this->callPrivateMethod('normalizeDbName', $this->dbname);
        return $this->callPrivateMethod('withLock', $dbname, true, $fn);
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
        $results = $this->get();
        return count($results) > 0 ? $results[count($results) - 1] : null;
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
        if (empty($this->spatialFilters) && empty($this->joinConfigs) && $this->offsetCount === 0 && $this->limitCount === null
            && !$this->keyOrderLookup()) {
            return $this->countMatches(); // record by record, none kept in memory
        }
        $results = $this->filteredRecords(true);
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $results = array_slice($results, $this->offsetCount, $this->limitCount);
        }
        return count($results);
    }

    private function countMatches(): int {
        $dbname = $this->callPrivateMethod('normalizeDbName', $this->dbname);
        return $this->callPrivateMethod('withReadLock', $dbname, function () use ($dbname) {
            $needWhere = !empty($this->whereFilters) || !empty($this->orWhereFilters);
            $where = $needWhere ? $this->compileGroups($this->whereFilters) : [];
            $orWhere = $needWhere ? $this->compileGroups($this->orWhereFilters) : [];
            $single = ($needWhere && count($where) === 1 && empty($orWhere)) ? $where[0] : null;
            $needAdvanced = $this->hasAdvancedFilters();
            $count = 0;
            $this->callPrivateMethod('scanRecords', $dbname, $this->buildPlan(),
                function (array $records) use (&$count, $single, $where, $orWhere, $needWhere, $needAdvanced) {
                    foreach ($records as $record) {
                        if ($single !== null) {
                            if (!$this->matchesGroup($record, $single)) continue;
                        } elseif ($needWhere && !$this->matchesWhereLogic($record, $where, $orWhere)) {
                            continue;
                        }
                        if (!$needAdvanced || $this->matchesAdvancedFilters($record)) {
                            $count++;
                        }
                    }
                    return true;
                }, true);
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
        // Sort, joins and projection don't change whether a record exists: stop at offset + 1 matches (T2-3)
        return count($this->filteredRecords(true, $this->offsetCount + 1)) > $this->offsetCount;
    }

    // ==========================================
    // AGGREGATION METHODS
    // ==========================================

    /**
     * Numeric values of a field in the results (booleans and non-numeric values are ignored)
     */
    private function numericValues(string $field): array {
        $values = [];
        foreach ($this->results(false) as $record) {
            $number = self::numberOf($record[$field] ?? null);
            if ($number !== null) {
                $values[] = $number;
            }
        }
        return $values;
    }

    /**
     * Sum of field values
     * @param string $field
     * @return float
     */
    public function sum(string $field): float {
        return (float)array_sum($this->numericValues($field));
    }

    /**
     * Average of field values
     * @param string $field
     * @return float
     */
    public function avg(string $field): float {
        $values = $this->numericValues($field);
        return count($values) > 0 ? array_sum($values) / count($values) : 0.0;
    }

    /**
     * Minimum value of field (same ordering as sort())
     * @param string $field
     * @return mixed|null
     */
    public function min(string $field) {
        $min = null;
        $found = false;
        foreach ($this->results(false) as $record) {
            if (!isset($record[$field])) continue;
            if (!$found || $this->callPrivateMethod('compareSortValues', $record[$field], $min) < 0) {
                $min = $record[$field];
                $found = true;
            }
        }
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
        foreach ($this->results(false) as $record) {
            if (!isset($record[$field])) continue;
            if (!$found || $this->callPrivateMethod('compareSortValues', $record[$field], $max) > 0) {
                $max = $record[$field];
                $found = true;
            }
        }
        return $max;
    }

    /**
     * Get unique values of field
     * @param string $field
     * @return array
     */
    public function distinct(string $field): array {
        return $this->callPrivateMethod('uniqueValues', $this->results(false), $field);
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
        return $this->withWriteLock(function () use ($set) {
            $keys = $this->targetKeys();
            if (empty($keys)) {
                return ['n' => 0];
            }
            return $this->db->update($this->dbname, [['key' => $keys], ['set' => $set]]);
        });
    }

    /**
     * Delete matching records (sort/offset/limit select which ones)
     * @return array ["n" => count]
     */
    public function delete(): array {
        return $this->withWriteLock(function () {
            $keys = $this->targetKeys();
            if (empty($keys)) {
                return ['n' => 0];
            }
            return $this->db->delete($this->dbname, ['key' => $keys]);
        });
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
            $keys = $this->targetKeys();
            if (empty($keys)) {
                return ['n' => 0, 'fields_removed' => []]; // nothing matched: nothing removed
            }
            // Fields are removed from the stored records, never from a projected/joined result
            $stored = $this->db->find($this->dbname, ['key' => $keys]);
            $replacements = [];
            $removed = [];
            foreach ($stored as $record) {
                $changed = false;
                foreach ($fieldsToRemove as $field) {
                    if (array_key_exists($field, $record)) {
                        unset($record[$field]);
                        $removed[$field] = true;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $key = $record['key'];
                    unset($record['key']);
                    $replacements[$key] = $record;
                }
            }
            $dbname = $this->callPrivateMethod('normalizeDbName', $this->dbname);
            return [
                'n' => $this->callPrivateMethod('replaceRecordsByKey', $dbname, $replacements),
                'fields_removed' => array_values(array_filter($fieldsToRemove, function ($f) use ($removed) { return isset($removed[$f]); }))
            ];
        });
    }
}
