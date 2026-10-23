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
    private static $flushingNow=[];           // database base path => true while its buffer is being flushed

    // Performance caches (runtime). All keys are full file paths (or key-fingerprint|dbname for hashes),
    // so instances with different dbDir/secretKey never see each other's entries.
    private $hashCache=[];                    // keyFingerprint|dbname -> hash (PBKDF2 is expensive)
    private $metaCache=[];                    // meta path -> meta data
    private $metaCacheTime=[];                // kept for API compatibility (unused since v3.2.0)

    // Index configuration
    private $indexEnabled=true;               // Kept for API compatibility (enableIndexing / isIndexingEnabled)
    private $indexCache=[];                   // jidx path -> JSONL index
    private $shardedCache=[];                 // meta path -> bool

    // JSONL Storage Engine
    private $jsonlFormatCache=[];             // data path -> bool (JSONL vs legacy v2)
    private $jsonlGarbageThreshold=0.3;       // Trigger compaction when garbage > 30% of live records

    // Static caches for cross-instance sharing
    private static $staticIndexCache=[];
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
    private static $lockRegistry=[];          // lock path -> ['fp', 'mode' => LOCK_SH|LOCK_EX, 'depth', 'dbname', 'gen', 'flags', 'bumped', 'pending'] while locked
    private static $lockHandles=[];           // lock path -> ['fp' => resource, 'pid' => int]: unlocked handles kept open for the next operation
    private $generations=[];                  // per-instance view of $staticGenerations
    private $hashFilesLoaded=[];

    // Field indexing configuration
    private $fieldIndexEnabled = true;        // Enable field-based indexing
    private $fieldIndexCache = [];            // field index path -> index

    // Spatial indexing configuration
    private $spatialIndexEnabled = true;      // Enable spatial R-tree indexing
    private $spatialIndexCache = [];          // spatial index path -> index
    private $globalSpatialIndexCache = [];    // global spatial index path -> index
    private $rtreeNodeSize = 32;              // Max entries per R-tree node
    private $dirtySpatialIndexes = [];        // Dirty flags for batch spatial writes (flushed before a write lock is released)
    private $distanceCache = [];              // Haversine distance memoization cache
    private $centroidCache = [];              // Geometry centroid cache

    private $keyFingerprint = null;           // HMAC of the secret, used to bind caches to the key
    private $dbBases = [];                    // dbname -> file name prefix (depends on dbDir and key)
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
        }
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
            $this->autoCreateDB = (bool)$config['autoCreateDB'];
        }

        // Sharding settings
        if(isset($config['shardingEnabled'])){
            $this->shardingEnabled = (bool)$config['shardingEnabled'];
        }
        if(isset($config['shardSize'])){
            $shardSize = (int)$config['shardSize'];
            if($shardSize < 1){
                throw new \InvalidArgumentException("noneDB: 'shardSize' must be a positive integer");
            }
            $this->shardSize = $shardSize;
        }
        if(isset($config['autoMigrate'])){
            $this->autoMigrate = (bool)$config['autoMigrate'];
        }

        // Compaction settings
        if(isset($config['autoCompactThreshold'])){
            $threshold = (float)$config['autoCompactThreshold'];
            if($threshold <= 0){
                throw new \InvalidArgumentException("noneDB: 'autoCompactThreshold' must be greater than 0");
            }
            $this->jsonlGarbageThreshold = $threshold;
        }

        // Lock settings
        if(isset($config['lockTimeout'])){
            $timeout = (int)$config['lockTimeout'];
            if($timeout < 0){
                throw new \InvalidArgumentException("noneDB: 'lockTimeout' must be 0 (wait forever) or a positive number of seconds");
            }
            $this->lockTimeout = $timeout;
        }
        if(isset($config['lockRetryDelay'])){
            $delay = (int)$config['lockRetryDelay'];
            if($delay < 1){
                throw new \InvalidArgumentException("noneDB: 'lockRetryDelay' must be a positive number of microseconds");
            }
            $this->lockRetryDelay = $delay;
        }

        // Write buffer
        if(isset($config['bufferEnabled'])){
            $this->bufferEnabled = (bool)$config['bufferEnabled'];
        }

        // Use indexes for queries (indexes are always kept up to date)
        if(isset($config['fieldIndexEnabled'])){
            $this->fieldIndexEnabled = (bool)$config['fieldIndexEnabled'];
        }
        if(isset($config['spatialIndexEnabled'])){
            $this->spatialIndexEnabled = (bool)$config['spatialIndexEnabled'];
        }
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
        $dbDir = rtrim($dbDir, '/\\') . '/';
        if(!is_dir($dbDir)){
            $result['error'] = "Directory not found: {$dbDir}";
            return $result;
        }

        // Databases created with the old key
        $databases = [];
        foreach(scandir($dbDir) ?: [] as $file){
            if(preg_match("/^([0-9a-f]{20})-([A-Za-z0-9' -]+)\\.nonedb(info|\\.meta)?$/", $file, $m)
                && hash_pbkdf2('sha256', $m[2], $oldKey, 1000, 20) === $m[1]){
                $databases[$m[2]] = $m[1];
            }
        }

        // Plan every rename first, refuse on any conflict
        $plan = [];
        $files = scandir($dbDir) ?: [];
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
                // Rename the lock file last so writers keep blocking on it until we are done
                usort($renames, function($a, $b){
                    return (substr($a[0], -12) === '.nonedb.lock') <=> (substr($b[0], -12) === '.nonedb.lock');
                });
                foreach($renames as $pair){
                    if(!@rename($dbDir . $pair[0], $dbDir . $pair[1])){
                        $result['error'] = "Failed to rename {$pair[0]}";
                        return $result;
                    }
                    $result['files']++;
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

        $fp = @fopen($file, 'rb');
        if($fp === false){
            return;
        }
        $content = '';
        if(flock($fp, LOCK_SH)){
            $content = stream_get_contents($fp);
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
        $this->savePersistentHashCacheEntry((string)$dbname, $hash);

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

    // ==========================================
    // DATABASE LOCKING AND STATE (v3.2.0)
    // ==========================================
    //
    // Every database has a lock file {base}.nonedb.lock. Besides being the flock target it holds a small
    // state record. It is read on every lock acquisition anyway, so the checks below cost no extra I/O:
    //
    //   "{generation}[ v][ b][ j=main,0,3 | J]"   space padded to LOCK_STATE_SIZE bytes
    //
    //   generation  bumped by the first write of every write session. A value other than the one this
    //               process last saw means another process changed the database: its cached state is dropped.
    //   v           verified: the one-time checks for pre-3.2 leftovers (old buffer files, no index list)
    //               are done, so the flags below are authoritative and nothing is re-checked per operation.
    //   b           the write buffer may hold records; they are flushed before the next read or write.
    //   j=... / J   index journal: field/spatial indexes (and shard meta counters) of these segments (J: all)
    //               may lag behind the data because a writer died; the next lock holder rebuilds them.
    //
    // The record is overwritten in place with a fixed size (one small write: no truncate, no rename).
    // Lock files are never deleted (dropDB keeps them), and their handles stay open between operations.

    private const LOCK_STATE_SIZE = 128;
    private const LOCK_HANDLE_LIMIT = 64;

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

        if(isset(self::$lockRegistry[$path])){
            if($exclusive && self::$lockRegistry[$path]['mode'] !== LOCK_EX){
                // flock upgrades are not atomic: another writer may run in between, so re-read the state
                $this->acquireFlock(self::$lockRegistry[$path]['fp'], LOCK_EX, $dbname);
                self::$lockRegistry[$path]['mode'] = LOCK_EX;
                $this->loadLockState($dbname, $path);
            }
            self::$lockRegistry[$path]['depth']++;
            try {
                return $fn();
            } finally {
                self::$lockRegistry[$path]['depth']--;
            }
        }

        $mode = $exclusive ? LOCK_EX : LOCK_SH;
        $fp = $this->lockFile($path, $mode, $dbname);
        self::$lockRegistry[$path] = [
            'fp' => $fp, 'mode' => $mode, 'depth' => 1, 'dbname' => $dbname,
            'gen' => 0, 'flags' => [], 'bumped' => false, 'pending' => [],
        ];

        $ok = false;
        try {
            $this->loadLockState($dbname, $path);
            $this->prepareDb($dbname, $path);
            $result = $fn();
            $ok = true;
            return $result;
        } finally {
            try {
                if(self::$lockRegistry[$path]['mode'] === LOCK_EX){
                    $this->finishWriteSession($dbname, $path, $ok);
                }
            } finally {
                $fp = self::$lockRegistry[$path]['fp'];
                unset(self::$lockRegistry[$path]);
                flock($fp, LOCK_UN);
                $this->keepLockHandle($path, $fp);
            }
        }
    }

    /**
     * End of an exclusive session: write pending spatial indexes, close the journal entries of this
     * session, and make sure the generation moved so other processes drop their caches
     */
    private function finishWriteSession($dbname, $path, $ok){
        if($ok){
            $this->flushSpatialIndexes();
            if(!empty(self::$lockRegistry[$path]['flags']['j'])){
                // Data, indexes and meta of every journaled segment are written
                unset(self::$lockRegistry[$path]['flags']['j']);
                $this->saveLockState($path);
            }
        } else {
            // Partial failure: forget in-memory state, the files are the source of truth.
            // A journal entry stays, so the next lock holder rebuilds what may be half-written.
            $this->dirtySpatialIndexes = [];
            $this->invalidateDbCaches($dbname);
        }
        if(!self::$lockRegistry[$path]['bumped']){
            $this->saveLockState($path);
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
                    throw new \RuntimeException("noneDB: cannot open lock file for database '{$dbname}'");
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
                throw new \RuntimeException("noneDB: the lock file of database '{$dbname}' keeps being replaced");
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
        $gen = (int)array_shift($tokens);
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
        if(!array_key_exists($path, $this->generations) || $this->generations[$path] !== $gen){
            $this->invalidateDbCaches($dbname);
            $this->generations[$path] = $gen;
        }
        self::$lockRegistry[$path]['gen'] = $gen;
        self::$lockRegistry[$path]['flags'] = $flags;
    }

    /**
     * Write the state record of a database held exclusively. The first write of a session also bumps
     * the generation, so even a writer that dies half-way makes other processes drop their caches.
     */
    private function saveLockState($path){
        if(!self::$lockRegistry[$path]['bumped']){
            self::$lockRegistry[$path]['gen']++;
            self::$lockRegistry[$path]['bumped'] = true;
            $this->generations[$path] = self::$lockRegistry[$path]['gen'];
        }
        $flags = self::$lockRegistry[$path]['flags'];
        $state = (string)self::$lockRegistry[$path]['gen'];
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
        $flags = self::$lockRegistry[$path]['flags'];
        if(!empty($flags['v']) && empty($flags['j'])){
            return;
        }
        $this->withLock($dbname, true, function() use ($dbname, $path){
            // Re-read under the exclusive lock: another process may have done it meanwhile
            if(empty(self::$lockRegistry[$path]['flags']['v'])){
                $this->verifyDb($dbname, $path);
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
     * Index journal: called before a segment's data changes. Needed when the database has secondary
     * indexes, or meta counters (sharded) that follow the data.
     */
    private function markIndexesPending($dbname, $shardId){
        $path = $this->getLockPath($dbname);
        if(!isset(self::$lockRegistry[$path])){
            return;
        }
        $manifest = $this->getIndexManifest($dbname);
        if($shardId === null && empty($manifest['fields']) && empty($manifest['spatial'])){
            return;
        }
        $segment = $shardId === null ? 'main' : (string)$shardId;
        if(isset(self::$lockRegistry[$path]['pending'][$segment])){
            return;
        }
        self::$lockRegistry[$path]['pending'][$segment] = true;
        self::$lockRegistry[$path]['flags']['j'][$segment] = true;
        $this->saveLockState($path);
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
        foreach($segments as $shardId){
            if(!in_array($shardId, $known, true)){
                continue;
            }
            $this->repairSegmentIndex($dbname, $shardId);
            foreach($manifest['fields'] as $field){
                $this->writeFieldIndex($dbname, $field, $this->buildSegmentFieldIndex($dbname, $shardId, $field), $shardId);
            }
            foreach($manifest['spatial'] as $field){
                $index = $this->buildSegmentSpatialIndex($dbname, $field, $shardId,
                    isset($manifest['spatialOptions'][$field]['nodeSize']) ? ['nodeSize' => $manifest['spatialOptions'][$field]['nodeSize']] : []);
                $this->writeSpatialIndex($dbname, $field, $index, $shardId);
                if($shardId !== null){
                    $this->updateGlobalSpatialShard($dbname, $field, $shardId, $index);
                }
            }
        }
        if($this->isSharded($dbname)){
            foreach($manifest['fields'] as $field){
                $this->rebuildGlobalFieldIndex($dbname, $field);
            }
            // A write may have died between its data and its meta update
            $this->recountMeta($dbname);
            // A migration may have died after switching to shards but before removing the old file
            $legacy = $this->getDataPath($dbname);
            clearstatcache(true, $legacy);
            if(file_exists($legacy)){
                @unlink($legacy . '.jidx');
                @unlink($legacy);
                $this->markFileNotExists($legacy);
                unset($this->indexCache[$legacy . '.jidx'], $this->jsonlFormatCache[$legacy]);
                foreach($manifest['fields'] as $field){
                    $this->deleteFieldIndexFile($dbname, $field, null);
                }
                foreach($manifest['spatial'] as $field){
                    @unlink($this->getSpatialIndexPath($dbname, $field, null));
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
                throw new \RuntimeException("noneDB: could not lock database '{$dbname}'");
            }
            return;
        }
        $start = microtime(true);
        while(!flock($fp, $mode | LOCK_NB)){
            if((microtime(true) - $start) >= $this->lockTimeout){
                throw new \RuntimeException("noneDB: timed out after {$this->lockTimeout}s waiting for the lock on database '{$dbname}'");
            }
            usleep($this->lockRetryDelay);
        }
    }

    /**
     * First write of an exclusive session: bump the generation right away (see saveLockState)
     */
    private function noteWrite(){
        foreach(self::$lockRegistry as $path => $entry){
            if($entry['mode'] === LOCK_EX && !$entry['bumped']){
                $this->saveLockState($path);
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
            &$this->indexCache, &$this->shardedCache, &$this->metaCache, &$this->metaCacheTime,
            &$this->jsonlFormatCache, &$this->fieldIndexCache, &$this->spatialIndexCache,
            &$this->globalSpatialIndexCache,
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
        foreach(array_keys(self::$bufferCounts) as $key){
            if(strncmp((string)$key, $prefix, $len) === 0){
                unset(self::$bufferCounts[$key]);
            }
        }
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
        try {
            return json_encode($data, $flags | JSON_THROW_ON_ERROR);
        } catch(\JsonException $e){
            return false;
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
     * @return bool
     */
    private function writeFileAtomic($path, $content){
        $this->noteWrite();
        $dir = dirname($path);
        if(!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)){
            return false;
        }
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        $written = @file_put_contents($tmp, $content);
        if($written === false || $written !== strlen($content)){
            @unlink($tmp);
            return false;
        }
        if(!@rename($tmp, $path)){
            @unlink($tmp);
            return false;
        }
        clearstatcache(true, $path);
        return true;
    }

    /**
     * Append bytes to a file. Returns the offset the bytes start at, or false.
     * A partial write is rolled back so the file never ends in a torn line.
     * @return int|false
     */
    private function appendToFile($path, $bytes){
        $this->noteWrite();
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
            $length = strlen($bytes);
            $done = 0;
            while($done < $length){
                $n = fwrite($fp, $done === 0 ? $bytes : substr($bytes, $done));
                if($n === false || $n === 0){
                    ftruncate($fp, $offset);
                    return false;
                }
                $done += $n;
            }
            fflush($fp);
            return $offset;
        } finally {
            fclose($fp);
            clearstatcache(true, $path);
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
    private function atomicWrite($path, $data, $prettyPrint = false){
        $json = $this->encodeJson($data, $prettyPrint);
        if($json === false){
            return false;
        }
        return $this->writeFileAtomic($path, $json);
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
    // "s", the data size it covers. If the data file is shorter than "s" (e.g. a crash between the two
    // renames of a compaction) or the index is missing/corrupt, the index is rebuilt from the data.

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

        if(!$forceRefresh && isset($this->metaCache[$metaPath])){
            return $this->metaCache[$metaPath];
        }

        $meta = $this->readMeta($dbname);
        if($meta !== null){
            $this->metaCache[$metaPath] = $meta;
            return $meta;
        }
        unset($this->metaCache[$metaPath]);
        return null;
    }

    /**
     * Write shard metadata (caller holds the exclusive lock)
     * @param string $dbname
     * @param array $meta
     * @return bool
     * @throws \RuntimeException when the meta file can't be written
     */
    private function writeMeta($dbname, $meta){
        $path = $this->getMetaPath($dbname);
        if(!$this->atomicWrite($path, $meta, true)){
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

    private function newJsonlIndex(){
        return [
            'v' => 3,
            'format' => 'jsonl',
            'created' => time(),
            'n' => 0,
            'd' => 0,
            't' => 0,
            's' => 0,
            'o' => []
        ];
    }

    /**
     * Read a segment's JSONL index (byte offset map), repairing it when it is missing, corrupt or
     * doesn't match the data file.
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
        // A cached index was checked when it was loaded, and is dropped as soon as another process writes
        if(isset($this->indexCache[$path])){
            return $this->indexCache[$path];
        }

        // Missing or unreadable (pre-3.0 files have no index either): repaired or converted
        $index = $this->atomicRead($path, null);
        if(!is_array($index) || !isset($index['o']) || !is_array($index['o'])){
            return $this->repairSegmentIndex($dbname, $shardId);
        }

        // The data file can only grow between index writes; shorter means the index belongs to other data
        if(isset($index['s'])){
            clearstatcache(true, $dataPath);
            $size = @filesize($dataPath);
            if($size !== false && $size < $index['s']){
                return $this->repairSegmentIndex($dbname, $shardId);
            }
        }

        $this->indexCache[$path] = $index;
        return $index;
    }

    /**
     * Write a segment's JSONL index (caller holds the exclusive lock)
     * @param string $dbname
     * @param array $index
     * @param int|null $shardId
     * @return bool
     * @throws \RuntimeException when the index can't be written
     */
    private function writeJsonlIndex($dbname, $index, $shardId = null){
        $dataPath = $this->segDataPath($dbname, $shardId);
        $path = $dataPath . '.jidx';
        clearstatcache(true, $dataPath);
        $size = @filesize($dataPath);
        $index['s'] = $size === false ? 0 : $size;
        $index['updated'] = time();
        if(!$this->atomicWrite($path, $index)){
            unset($this->indexCache[$path]);
            throw new \RuntimeException("noneDB: failed to write index of database '{$dbname}'");
        }
        $this->indexCache[$path] = $index;
        return true;
    }

    /**
     * Rebuild a segment index from its data file (exclusive lock, upgrading a shared one).
     * Re-checks first: another process may have repaired it while we waited for the lock.
     * @param bool $force Rebuild even if the index on disk looks consistent
     * @return array|null
     */
    private function repairSegmentIndex($dbname, $shardId, $force = false){
        return $this->withLock($dbname, true, function() use ($dbname, $shardId, $force){
            $dataPath = $this->segDataPath($dbname, $shardId);
            $path = $dataPath . '.jidx';
            unset($this->indexCache[$path]);
            clearstatcache(true, $dataPath);
            if(!file_exists($dataPath)){
                return null;
            }
            // A pre-3.0 {"data": [...]} file (it has no index) is converted, never scanned as JSONL
            $this->ensureJsonlFormat($dbname, $shardId);
            if(!$force){
                $index = $this->atomicRead($path, null);
                if(is_array($index) && isset($index['o']) && is_array($index['o'])){
                    $size = @filesize($dataPath);
                    if(!isset($index['s']) || ($size !== false && $size >= $index['s'])){
                        $this->indexCache[$path] = $index;
                        return $index;
                    }
                }
            }
            $index = $this->rebuildSegmentIndexFromData($dbname, $shardId);
            if($shardId !== null){
                $this->recountMeta($dbname);
            }
            return $index;
        });
    }

    /**
     * Scan a data file and rebuild its index: the last line of a key wins, tombstones delete,
     * a torn last line (crash during append) is cut off. Caller holds the exclusive lock.
     * @return array
     */
    private function rebuildSegmentIndexFromData($dbname, $shardId){
        $dataPath = $this->segDataPath($dbname, $shardId);
        $old = $this->atomicRead($dataPath . '.jidx', null);

        $offsets = [];
        $lines = 0;
        $tombstones = 0;
        $maxKey = -1;
        $offset = 0;
        $fp = @fopen($dataPath, 'rb');
        if($fp !== false){
            while(($line = fgets($fp)) !== false){
                if(substr($line, -1) !== "\n"){
                    break; // torn tail
                }
                $json = rtrim($line, "\r\n");
                $record = json_decode($json, true);
                if(is_array($record)){
                    if(array_key_exists('__nonedb_deleted', $record) && count($record) === 1){
                        $k = (int)$record['__nonedb_deleted'];
                        unset($offsets[$k]);
                        $maxKey = max($maxKey, $k);
                        $tombstones++;
                    } elseif(isset($record['key']) && is_int($record['key'])){
                        $offsets[$record['key']] = [$offset, strlen($json)];
                        $maxKey = max($maxKey, $record['key']);
                    }
                }
                $offset += strlen($line);
                $lines++;
            }
            fclose($fp);
        }
        clearstatcache(true, $dataPath);
        if(file_exists($dataPath) && filesize($dataPath) > $offset){
            $fp = fopen($dataPath, 'c+b');
            if($fp !== false){
                ftruncate($fp, $offset);
                fclose($fp);
            }
        }

        ksort($offsets, SORT_NUMERIC);
        $index = $this->newJsonlIndex();
        if(is_array($old) && isset($old['created'])){
            $index['created'] = $old['created'];
        }
        $index['o'] = $offsets;
        $index['t'] = $tombstones;
        $index['d'] = max(0, $lines - count($offsets) - $tombstones);
        if($shardId === null){
            $index['n'] = max($maxKey + 1, is_array($old) && isset($old['n']) ? (int)$old['n'] : 0);
        } else {
            $base = $shardId * $this->shardSizeOf($dbname);
            $index['n'] = $maxKey >= $base ? $maxKey - $base + 1 : 0;
        }
        $this->writeJsonlIndex($dbname, $index, $shardId);
        return $this->indexCache[$this->getJsonlIndexPath($dbname, $shardId)];
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
        $maxKey = -1;
        foreach($meta['shards'] as $i => $shard){
            $index = $this->readJsonlIndex($dbname, (int)$shard['id']);
            $count = $index === null ? 0 : count($index['o']);
            $meta['shards'][$i]['count'] = $count;
            $total += $count;
            if($index !== null && !empty($index['o'])){
                $maxKey = max($maxKey, max(array_keys($index['o'])));
            }
        }
        $meta['totalRecords'] = $total;
        $meta['nextKey'] = max((int)($meta['nextKey'] ?? 0), $maxKey + 1);
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

        // A JSONL database always has its .jidx (or the index can be rebuilt from the lines).
        // Only a file without index that decodes as exactly {"data": [...]} is the old v2 format;
        // a JSONL record always has a "key" field, so a record whose first field is "data" is never mistaken.
        $isJsonl = true;
        if(!file_exists($path . '.jidx')){
            $content = @file_get_contents($path);
            if($content !== false && $content !== ''){
                $decoded = json_decode($content, true);
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
                $index['o'][$key] = [$offset, strlen($json)];
                $buffer .= $json . "\n";
                $offset += strlen($json) + 1;
            }
            $index['n'] = $slots;

            if(!$this->writeFileAtomic($path, $buffer)){
                return false;
            }
            $this->jsonlFormatCache[$path] = true;
            $this->writeJsonlIndex($dbname, $index, $shardId);
            return true;
        });
    }

    // ==========================================
    // RECORD I/O
    // ==========================================

    /**
     * Batch read multiple JSONL records (one open, 64KB buffered sequential reads)
     * @param string $path File path
     * @param array $offsets [key => [offset, length], ...]
     * @param bool $mismatch Set to true when a line doesn't hold the expected record
     * @return array [key => record, ...]
     */
    private function readJsonlRecordsBatch($path, $offsets, &$mismatch = false){
        $mismatch = false;
        if(empty($offsets)){
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
        $positions = [];
        $inOrder = true;
        $previous = -1;
        foreach($offsets as $key => $location){
            $positions[$key] = $location[0];
            if($location[0] < $previous){
                $inOrder = false;
            }
            $previous = $location[0];
        }
        if(!$inOrder){
            asort($positions, SORT_NUMERIC);
        }

        foreach($positions as $key => $position){
            $offset = (int)$position;
            $length = (int)$offsets[$key][1];
            if($length <= 0){
                $mismatch = true;
                continue;
            }

            if($offset >= $bufferStart && ($offset + $length) <= $bufferEnd){
                $json = substr($buffer, $offset - $bufferStart, $length);
            } else {
                fseek($handle, $offset, SEEK_SET);
                $buffer = (string)fread($handle, max($length, $bufferSize));
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
        for($attempt = 0; $attempt < 2; $attempt++){
            $index = $attempt === 0
                ? $this->readJsonlIndex($dbname, $shardId)
                : $this->repairSegmentIndex($dbname, $shardId, true);
            if($index === null){
                return [];
            }
            if($keys === null){
                $offsets = $index['o'];
            } else {
                $offsets = [];
                foreach($keys as $key){
                    if(isset($index['o'][$key])){
                        $offsets[$key] = $index['o'][$key];
                    }
                }
            }
            $mismatch = false;
            $records = $this->readJsonlRecordsBatch($this->segDataPath($dbname, $shardId), $offsets, $mismatch);
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
        $this->markIndexesPending($dbname, $shardId);
        $lines = [];
        foreach($records as $key => $record){
            unset($record['key']);
            $record['key'] = (int)$key;
            $lines[$key] = $this->encodeRecord($record);
        }
        $buffer = implode("\n", $lines) . "\n";
        $dataPath = $this->segDataPath($dbname, $shardId);
        $offset = $this->appendToFile($dataPath, $buffer);
        if($offset === false){
            throw new \RuntimeException("noneDB: failed to write data of database '{$dbname}'");
        }
        $this->markFileExists($dataPath);
        foreach($lines as $key => $json){
            $index['o'][$key] = [$offset, strlen($json)];
            $offset += strlen($json) + 1;
        }
    }

    /**
     * Append tombstones for deleted keys, so an index rebuilt from the data won't resurrect them
     */
    private function appendTombstones($dbname, $shardId, array $keys){
        if(empty($keys)){
            return;
        }
        $this->markIndexesPending($dbname, $shardId);
        $buffer = '';
        foreach($keys as $key){
            $buffer .= '{"__nonedb_deleted":' . (int)$key . "}\n";
        }
        if($this->appendToFile($this->segDataPath($dbname, $shardId), $buffer) === false){
            throw new \RuntimeException("noneDB: failed to write data of database '{$dbname}'");
        }
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
        if(!file_exists($path) && @touch($path) === false){
            throw new \RuntimeException("noneDB: cannot create data file of database '{$dbname}'");
        }
        $this->markFileExists($path);
        $this->jsonlFormatCache[$path] = true;
        $index = $this->newJsonlIndex();
        if($shardId === null){
            $existing = $this->atomicRead($path . '.jidx', null);
            if(is_array($existing) && isset($existing['o'])){
                return true;
            }
        }
        $this->writeJsonlIndex($dbname, $index, $shardId);
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

        $index = $this->readJsonlIndex($dbname, null);
        if($index === null){
            $this->createJsonlDatabase($dbname, null);
            $index = $this->readJsonlIndex($dbname, null);
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
        $this->writeJsonlIndex($dbname, $index, null);
        $this->afterRecordsChanged($dbname, null, [], $this->withKeys($records));

        if($this->shardingEnabled && $this->autoMigrate && $index['n'] >= $this->shardSize){
            $this->migrateToSharded($dbname);
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
        $previousMeta = $this->getCachedMeta($dbname);
        $meta['nextKey'] = $nextKey;
        $meta['totalRecords'] = (int)$meta['totalRecords'] + count($items);
        $this->writeMeta($dbname, $meta);

        try {
            foreach($byShard as $shardId => $records){
                $index = $this->readJsonlIndex($dbname, $shardId);
                if($index === null){
                    $this->createJsonlDatabase($dbname, $shardId);
                    $index = $this->readJsonlIndex($dbname, $shardId);
                }
                $this->appendSegmentRecords($dbname, $shardId, $index, $records);
                $index['n'] = max((int)$index['n'], max(array_keys($records)) - $shardId * $size + 1);
                $this->writeJsonlIndex($dbname, $index, $shardId);
                $this->afterRecordsChanged($dbname, $shardId, [], $this->withKeys($records));
            }
        } catch(\Throwable $e){
            // Nothing was appended for the failing shard; restore counters (keys stay reserved)
            if($previousMeta !== null){
                $previousMeta['nextKey'] = $nextKey;
                $this->writeMeta($dbname, $previousMeta);
                $this->recountMeta($dbname);
            }
            throw $e;
        }
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
        $index = $this->readJsonlIndex($dbname, $shardId);
        foreach(array_keys($newRecords) as $key){
            if(!isset($index['o'][$key])){
                unset($newRecords[$key], $oldRecords[$key]);
            }
        }
        if(empty($newRecords)){
            return 0;
        }
        $this->appendSegmentRecords($dbname, $shardId, $index, $newRecords);
        $index['d'] += count($newRecords);
        $this->writeJsonlIndex($dbname, $index, $shardId);
        $this->afterRecordsChanged($dbname, $shardId, $oldRecords, $this->withKeys($newRecords));
        $this->maybeCompact($dbname, $shardId);
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
        $index = $this->readJsonlIndex($dbname, $shardId);
        foreach(array_keys($oldRecords) as $key){
            if(!isset($index['o'][$key])){
                unset($oldRecords[$key]);
            }
        }
        if(empty($oldRecords)){
            return 0;
        }
        $keys = array_keys($oldRecords);
        $this->appendTombstones($dbname, $shardId, $keys);
        foreach($keys as $key){
            unset($index['o'][$key]);
        }
        $index['d'] += count($keys); // the old record lines
        $index['t'] = (int)($index['t'] ?? 0) + count($keys); // tombstone lines
        $this->writeJsonlIndex($dbname, $index, $shardId);
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

        $this->maybeCompact($dbname, $shardId);
        return count($keys);
    }

    /**
     * Keep field and spatial indexes in sync after records of a segment changed
     * @param array $removed [key => old record]
     * @param array $added [key => new record]
     */
    private function afterRecordsChanged($dbname, $shardId, array $removed, array $added){
        $this->applyFieldIndexChanges($dbname, $shardId, $removed, $added);
        $this->applySpatialIndexChanges($dbname, $shardId, $removed, $added);
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
        if($garbage > 0 && $garbage > (count($index['o']) + $garbage) * $this->jsonlGarbageThreshold){
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
            $index = $this->readJsonlIndex($dbname, $shardId);
            if($index === null){
                return ['compacted' => 0, 'freed' => 0];
            }
            $path = $this->segDataPath($dbname, $shardId);
            $records = $this->readSegmentRecords($dbname, $shardId);
            $index = $this->readJsonlIndex($dbname, $shardId);

            $newIndex = $this->newJsonlIndex();
            $newIndex['created'] = $index['created'] ?? time();
            $newIndex['n'] = $index['n'];
            $buffer = '';
            $offset = 0;
            foreach($records as $key => $record){
                $json = $this->encodeRecord($record);
                $newIndex['o'][$key] = [$offset, strlen($json)];
                $buffer .= $json . "\n";
                $offset += strlen($json) + 1;
            }

            if(!$this->writeFileAtomic($path, $buffer)){
                throw new \RuntimeException("noneDB: compaction of database '{$dbname}' failed while writing data");
            }
            $this->writeJsonlIndex($dbname, $newIndex, $shardId);

            return ['compacted' => count($records), 'freed' => (int)$index['d']];
        });
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
            $this->flushPendingBuffers($dbname);

            $index = $this->readJsonlIndex($dbname, null);
            if($index === null){
                return false;
            }
            $records = $this->readSegmentRecords($dbname, null);
            $size = $this->shardSize;
            $maxKey = empty($records) ? -1 : max(array_keys($records));
            $nextKey = max((int)$index['n'], $maxKey + 1);
            $numShards = max(1, intdiv(max($nextKey, 1) - 1, $size) + 1);

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
                $buffer = '';
                $offset = 0;
                foreach($shardRecords as $key => $record){
                    $json = $this->encodeRecord($record);
                    $shardIndex['o'][$key] = [$offset, strlen($json)];
                    $buffer .= $json . "\n";
                    $offset += strlen($json) + 1;
                }
                $shardPath = $this->getShardPath($dbname, $shardId);
                if(!$this->writeFileAtomic($shardPath, $buffer)){
                    throw new \RuntimeException("noneDB: migration of database '{$dbname}' failed while writing shard {$shardId}");
                }
                $this->markFileExists($shardPath);
                $this->jsonlFormatCache[$shardPath] = true;
                $this->writeJsonlIndex($dbname, $shardIndex, $shardId);

                $meta['shards'][] = [
                    "id" => $shardId,
                    "file" => "_s" . $shardId,
                    "count" => count($shardRecords),
                    "deleted" => 0
                ];
            }

            // Flip to sharded only after every shard is safely on disk
            $this->writeMeta($dbname, $meta);

            // Verify, then remove the non-sharded files
            $verified = 0;
            foreach(array_keys($byShard) as $shardId){
                $verified += count($this->readSegmentRecords($dbname, $shardId));
            }
            if($verified !== count($records)){
                throw new \RuntimeException("noneDB: migration of database '{$dbname}' could not be verified");
            }
            @unlink($legacyPath . '.jidx');
            @unlink($legacyPath);
            @unlink($legacyPath . '.backup');
            $this->markFileNotExists($legacyPath);
            unset($this->indexCache[$legacyPath . '.jidx'], $this->jsonlFormatCache[$legacyPath]);

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
     * Read all records from a buffer file (JSONL)
     * @param string $bufferPath
     * @return array
     */
    private function readBufferRecords($bufferPath){
        $records = [];
        $fp = @fopen($bufferPath, 'rb');
        if($fp === false){
            return $records;
        }
        while(($line = fgets($fp)) !== false){
            $line = trim($line);
            if($line === ''){
                continue;
            }
            $record = json_decode($line, true);
            if(is_array($record)){
                $records[] = $record;
            }
        }
        fclose($fp);
        return $records;
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
        $bufferPath = $this->getBufferPath($dbname);
        $files = glob($bufferPath . '.flushing*') ?: [];
        return array_values(array_filter($files, function($f){
            return preg_match('/\.nonedb\.buffer\.flushing(\.\d+)?$/', $f) === 1;
        }));
    }

    /**
     * Pre-3.2 per-shard buffer files (and interrupted flushes) of a database
     */
    private function legacyShardBufferFiles($dbname){
        if(!$this->isSharded($dbname)){
            return [];
        }
        $files = glob($this->dbBase($dbname) . '_s*.nonedb.buffer*') ?: [];
        return array_values(array_filter($files, function($f){
            return preg_match('/_s\d+\.nonedb\.buffer(\.flushing(\.\d+)?)?$/', $f) === 1;
        }));
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
        return $this->withLock($dbname, true, function() use ($dbname, $base){
            self::$flushingNow[$base] = true;
            try {
                return $this->flushPendingBuffersLocked($dbname);
            } finally {
                unset(self::$flushingNow[$base]);
            }
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
                if(!@rename($bufferPath, $flushingPath)){
                    throw new \RuntimeException("noneDB: could not flush the write buffer of database '{$dbname}'");
                }
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
        $records = $this->readBufferRecords($file);
        if(!empty($records)){
            $result = $this->insertRecords($dbname, $records, $startKey);
            if(isset($result['error'])){
                throw new \RuntimeException("noneDB: flushing the write buffer of '{$dbname}' failed: " . $result['error']);
            }
        }
        @unlink($file);
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
        $index = $this->readJsonlIndex($dbname, null);
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
        $index = $this->readJsonlIndex($dbname, $shardId);
        if($index === null){
            $this->createJsonlDatabase($dbname, $shardId);
            $index = $this->readJsonlIndex($dbname, $shardId);
        }
        // Same idempotent scheme as the current buffer: the first key goes into the file name
        if(preg_match('/\.flushing\.(\d+)$/', $file, $km)){
            $startKey = (int)$km[1];
        } else {
            $startKey = $shardId * $size + (int)$index['n'];
            $renamed = preg_replace('/(\.flushing)?$/', '', $file, 1) . '.flushing.' . $startKey;
            if(!@rename($file, $renamed)){
                throw new \RuntimeException("noneDB: could not flush a write buffer of database '{$dbname}'");
            }
            $file = $renamed;
        }
        $records = array_values(array_filter($this->readBufferRecords($file), function($r){
            return is_array($r) && !array_key_exists('key', $r);
        }));
        if(!empty($records)){
            $keyed = [];
            foreach($records as $i => $record){
                $keyed[$startKey + $i] = $record;
            }
            foreach(array_keys($keyed) as $key){
                if(isset($index['o'][$key])){
                    unset($keyed[$key]); // stored before an interruption
                }
            }
            $this->appendSegmentRecords($dbname, $shardId, $index, $keyed);
            $index['n'] = max((int)$index['n'], $startKey + count($records) - $shardId * $size);
            $this->writeJsonlIndex($dbname, $index, $shardId);
            $this->afterRecordsChanged($dbname, $shardId, [], $this->withKeys($keyed));
            $this->recountMeta($dbname);
        }
        @unlink($file);
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

        $lines = '';
        foreach($validItems as $item){
            $lines .= $this->encodeRecord($item) . "\n";
        }

        $bufferPath = $this->getBufferPath($dbname);
        if($this->shouldFlushBuffer($bufferPath, $dbname)){
            $this->flushPendingBuffers($dbname);
        }
        $count = $this->getBufferRecordCount($bufferPath);
        $this->setBufferFlag($dbname, true);
        if($this->appendToFile($bufferPath, $lines) === false){
            return ["n" => 0, "error" => "Failed to write buffer"];
        }
        clearstatcache(true, $bufferPath);
        self::$bufferCounts[$bufferPath] = [filesize($bufferPath), $count + count($validItems)];
        if(!isset($this->bufferLastFlush[$dbname])){
            $this->bufferLastFlush[$dbname] = time();
        }

        if($this->shouldFlushBuffer($bufferPath, $dbname)){
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
    private function shouldFlushBuffer($bufferPath, $flushKey){
        if(!$this->hasBuffer($bufferPath)){
            return false;
        }
        if(filesize($bufferPath) >= $this->bufferSizeLimit){
            return true;
        }
        if($this->getBufferRecordCount($bufferPath) >= $this->bufferCountLimit){
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
            } elseif(is_string($v) && preg_match('/^(0|[1-9][0-9]*)$/', $v)){
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

        $targets = $this->targetShardsFromGlobalIndex($dbname, $filters);
        foreach($segments as $segment){
            if($targets !== null && !in_array($segment, $targets, true)){
                continue;
            }
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
    // FIELD INDEXES
    // ==========================================
    // Which fields are indexed is recorded in {base}.nonedb.indexes ("manifest"), so new shards and
    // field names with any characters are handled. Each segment has one file per field:
    // {segment}.nonedb.fidx.{name} where name is the field itself when it matches [A-Za-z0-9_]+ and
    // "~" + hex(field) otherwise. Index files of format v2 are complete and exact; older files are
    // rebuilt on first use.

    private function getIndexManifestPath($dbname){
        return $this->dbBase($dbname) . '.nonedb.indexes';
    }

    /**
     * Indexed fields of a database: ['fields' => [...], 'spatial' => [...]]
     * Databases created before v3.2 have no manifest; it is derived from their index files.
     */
    private function getIndexManifest($dbname){
        $path = $this->getIndexManifestPath($dbname);
        if(isset($this->fieldIndexCache[$path])){
            return $this->fieldIndexCache[$path];
        }
        $manifest = $this->atomicRead($path, false);
        if($manifest === false && $this->isVerified($dbname)){
            $manifest = []; // no index list: no indexes (pre-3.2 databases got theirs when verified)
        } elseif(!is_array($manifest)){
            $manifest = $this->deriveIndexManifest($dbname);
        }
        $manifest = [
            'fields' => array_values(array_unique(array_map('strval', $manifest['fields'] ?? []))),
            'spatial' => array_values(array_unique(array_map('strval', $manifest['spatial'] ?? []))),
            'spatialOptions' => is_array($manifest['spatialOptions'] ?? null) ? $manifest['spatialOptions'] : [],
        ];
        $this->fieldIndexCache[$path] = $manifest;
        return $manifest;
    }

    private function saveIndexManifest($dbname, array $manifest){
        $manifest = [
            'v' => 1,
            'fields' => array_values(array_unique($manifest['fields'] ?? [])),
            'spatial' => array_values(array_unique($manifest['spatial'] ?? [])),
            'spatialOptions' => array_intersect_key($manifest['spatialOptions'] ?? [], array_flip(array_values(array_unique($manifest['spatial'] ?? [])))),
        ];
        $path = $this->getIndexManifestPath($dbname);
        if(empty($manifest['fields']) && empty($manifest['spatial'])){
            @unlink($path);
        } elseif(!$this->atomicWrite($path, $manifest)){
            throw new \RuntimeException("noneDB: failed to write index list of database '{$dbname}'");
        }
        $this->fieldIndexCache[$path] = ['fields' => $manifest['fields'], 'spatial' => $manifest['spatial'], 'spatialOptions' => $manifest['spatialOptions']];
    }

    /**
     * Pre-3.2 databases: read field names from existing index files (their "field" property is exact,
     * the file name may have been lossy)
     */
    private function deriveIndexManifest($dbname){
        $base = $this->dbBase($dbname);
        $hashBase = $this->dbDir . $this->hashDBName($dbname);
        $fields = [];
        foreach(array_merge(glob($base . '.nonedb.fidx.*') ?: [], glob($base . '_s*.nonedb.fidx.*') ?: []) as $file){
            $index = $this->atomicRead($file, null);
            if(is_array($index) && isset($index['field']) && is_string($index['field'])){
                $fields[] = $index['field'];
            } elseif(preg_match('/\.fidx\.(.+)$/', $file, $m)){
                $fields[] = $this->decodeIndexFieldName($m[1]);
            }
        }
        $spatial = [];
        foreach(array_merge(glob($hashBase . '.nonedb.sidx.*') ?: [], glob($hashBase . '_s*.nonedb.sidx.*') ?: []) as $file){
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
        return ['fields' => array_values(array_unique($fields)), 'spatial' => array_values(array_unique($spatial))];
    }

    /**
     * File-name-safe, reversible encoding of a field name
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
     * Get field index file path
     * @param string $dbname Database name
     * @param string $field Field name
     * @param int|null $shardId Shard ID or null for non-sharded
     * @return string Path to field index file
     */
    private function getFieldIndexPath($dbname, $field, $shardId = null){
        return $this->segDataPath($dbname, $shardId) . ".fidx." . $this->encodeIndexFieldName($field);
    }

    /**
     * Read a segment's field index. Returns null when the file is missing or predates v3.2
     * (such files may be incomplete and are rebuilt by ensureSegmentFieldIndex).
     * @return array|null
     */
    private function readFieldIndex($dbname, $field, $shardId = null){
        $path = $this->getFieldIndexPath($dbname, $field, $shardId);
        if(array_key_exists($path, $this->fieldIndexCache)){
            return $this->fieldIndexCache[$path];
        }
        $index = $this->atomicRead($path, null);
        if(!is_array($index) || ($index['v'] ?? 1) < 2 || !isset($index['values']) || !is_array($index['values'])){
            $index = null;
        }
        $this->fieldIndexCache[$path] = $index;
        return $index;
    }

    /**
     * Write field index to file (caller holds the exclusive lock)
     * @return bool Success
     */
    private function writeFieldIndex($dbname, $field, $index, $shardId = null){
        $path = $this->getFieldIndexPath($dbname, $field, $shardId);
        $index['v'] = 2;
        $index['field'] = (string)$field;
        $index['updated'] = time();
        if(!$this->atomicWrite($path, $index)){
            unset($this->fieldIndexCache[$path]);
            throw new \RuntimeException("noneDB: failed to write field index '{$field}' of database '{$dbname}'");
        }
        $this->fieldIndexCache[$path] = $index;
        $this->markFileExists($path);
        return true;
    }

    /**
     * Delete field index file
     * @return bool Success
     */
    private function deleteFieldIndexFile($dbname, $field, $shardId = null){
        $path = $this->getFieldIndexPath($dbname, $field, $shardId);
        unset($this->fieldIndexCache[$path]);
        $this->markFileNotExists($path);
        clearstatcache(true, $path);
        return !file_exists($path) || @unlink($path);
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
        if($field !== null){
            unset($this->fieldIndexCache[$this->getFieldIndexPath($dbname, $field, $shardId)]);
            return;
        }
        $prefix = $this->dbDir . $this->hashDBName($dbname);
        foreach(array_keys($this->fieldIndexCache) as $key){
            if(strpos($key, $prefix) === 0){
                unset($this->fieldIndexCache[$key]);
            }
        }
    }

    /**
     * Bucket key of a field value. Values of different types may share a bucket (1, "1", 1.0); results
     * are always re-checked with strict comparison, so that only costs a few extra reads.
     * @param mixed $value Field value
     * @return string Index key
     */
    private function fieldIndexValueKey($value){
        if($value === null){
            return '__null__';
        }
        if(is_bool($value)){
            return $value ? '__true__' : '__false__';
        }
        if(is_float($value) && $value == 0.0){
            return '0'; // -0.0 === 0.0
        }
        return (string)$value;
    }

    /**
     * True if a value can be stored in a field index
     */
    private function isIndexableValue($value){
        return is_scalar($value) || is_null($value);
    }

    /**
     * Build a segment's field index from its data
     * @return array
     */
    private function buildSegmentFieldIndex($dbname, $shardId, $field){
        $index = ['v' => 2, 'field' => (string)$field, 'created' => time(), 'values' => []];
        if($shardId !== null){
            $index['shardId'] = $shardId;
        }
        foreach($this->readSegmentRecords($dbname, $shardId) as $key => $record){
            if(array_key_exists($field, $record) && $this->isIndexableValue($record[$field])){
                $index['values'][$this->fieldIndexValueKey($record[$field])][] = (int)$key;
            }
        }
        return $index;
    }

    /**
     * A segment's field index, (re)built when missing or from an older version (exclusive lock)
     * @return array
     */
    private function ensureSegmentFieldIndex($dbname, $shardId, $field){
        $index = $this->readFieldIndex($dbname, $field, $shardId);
        if($index !== null){
            return $index;
        }
        return $this->withLock($dbname, true, function() use ($dbname, $shardId, $field){
            unset($this->fieldIndexCache[$this->getFieldIndexPath($dbname, $field, $shardId)]);
            $index = $this->readFieldIndex($dbname, $field, $shardId);
            if($index === null){
                $index = $this->buildSegmentFieldIndex($dbname, $shardId, $field);
                $this->writeFieldIndex($dbname, $field, $index, $shardId);
                $index = $this->readFieldIndex($dbname, $field, $shardId);
                if($shardId !== null){
                    $this->rebuildGlobalFieldIndex($dbname, $field);
                }
            }
            return $index;
        });
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
            $index = $this->ensureSegmentFieldIndex($dbname, $shardId, $field);
            $keys = $index['values'][$this->fieldIndexValueKey($value)] ?? [];
            $candidates = $candidates === null ? $keys : array_values(array_intersect($candidates, $keys));
            if(empty($candidates)){
                return [];
            }
        }
        return $candidates;
    }

    /**
     * Apply record changes of one segment to every field index of the database
     * @param array $removed [key => old record]
     * @param array $added [key => new record]
     */
    private function applyFieldIndexChanges($dbname, $shardId, array $removed, array $added){
        // Maintained even when fieldIndexEnabled is off (that flag only stops reads from using indexes),
        // so turning it back on never exposes a stale index
        if(empty($removed) && empty($added)){
            return;
        }
        foreach($this->getIndexedFields($dbname) as $field){
            $index = $this->readFieldIndex($dbname, $field, $shardId);
            if($index === null){
                // Missing (new shard) or pre-3.2 file: build it from the data, which already holds the change
                $index = $this->buildSegmentFieldIndex($dbname, $shardId, $field);
                $this->writeFieldIndex($dbname, $field, $index, $shardId);
                if($shardId !== null){
                    $this->rebuildGlobalFieldIndex($dbname, $field);
                }
                continue;
            }

            $touched = [];
            $removals = [];
            foreach($removed as $key => $record){
                if(array_key_exists($field, $record) && $this->isIndexableValue($record[$field])){
                    $removals[$this->fieldIndexValueKey($record[$field])][(int)$key] = true;
                }
            }
            foreach($removals as $valueKey => $keys){
                if(!isset($index['values'][$valueKey])){
                    continue;
                }
                $kept = [];
                foreach($index['values'][$valueKey] as $k){
                    if(!isset($keys[$k])){
                        $kept[] = $k;
                    }
                }
                if(empty($kept)){
                    unset($index['values'][$valueKey]);
                } else {
                    $index['values'][$valueKey] = $kept;
                }
                $touched[$valueKey] = true;
            }
            foreach($added as $key => $record){
                if(array_key_exists($field, $record) && $this->isIndexableValue($record[$field])){
                    $valueKey = $this->fieldIndexValueKey($record[$field]);
                    $index['values'][$valueKey][] = (int)$key;
                    $touched[$valueKey] = true;
                }
            }
            if(empty($touched)){
                continue;
            }
            $this->writeFieldIndex($dbname, $field, $index, $shardId);

            if($shardId !== null){
                $global = $this->readGlobalFieldIndex($dbname, $field);
                if($global === null){
                    $this->rebuildGlobalFieldIndex($dbname, $field);
                    continue;
                }
                $changed = false;
                foreach(array_keys($touched) as $valueKey){
                    $shards = $global['shardMap'][$valueKey] ?? [];
                    $has = in_array($shardId, $shards, true);
                    if(!empty($index['values'][$valueKey]) && !$has){
                        $shards[] = $shardId;
                        $global['shardMap'][$valueKey] = $shards;
                        $changed = true;
                    } elseif(empty($index['values'][$valueKey]) && $has){
                        $shards = array_values(array_diff($shards, [$shardId]));
                        if(empty($shards)){
                            unset($global['shardMap'][$valueKey]);
                        } else {
                            $global['shardMap'][$valueKey] = $shards;
                        }
                        $changed = true;
                    }
                }
                if($changed){
                    $this->writeGlobalFieldIndex($dbname, $field, $global);
                }
            }
        }
    }

    // ==================== GLOBAL FIELD INDEX (shard skip) ====================

    /**
     * Get path for global field index file
     */
    private function getGlobalFieldIndexPath($dbname, $field){
        return $this->dbBase($dbname) . ".nonedb.gfidx." . $this->encodeIndexFieldName($field);
    }

    /**
     * Read global field index (null when missing or predating v3.2)
     * @return array|null
     */
    private function readGlobalFieldIndex($dbname, $field){
        $path = $this->getGlobalFieldIndexPath($dbname, $field);
        if(array_key_exists($path, $this->fieldIndexCache)){
            return $this->fieldIndexCache[$path];
        }
        $index = $this->atomicRead($path, null);
        if(!is_array($index) || ($index['v'] ?? 1) < 2 || !isset($index['shardMap']) || !is_array($index['shardMap'])){
            $index = null;
        }
        $this->fieldIndexCache[$path] = $index;
        return $index;
    }

    /**
     * Write global field index (caller holds the exclusive lock)
     */
    private function writeGlobalFieldIndex($dbname, $field, $metadata){
        $path = $this->getGlobalFieldIndexPath($dbname, $field);
        $metadata['v'] = 2;
        $metadata['field'] = (string)$field;
        $metadata['updated'] = time();
        if(!$this->atomicWrite($path, $metadata)){
            unset($this->fieldIndexCache[$path]);
            throw new \RuntimeException("noneDB: failed to write global field index '{$field}' of database '{$dbname}'");
        }
        $this->fieldIndexCache[$path] = $metadata;
        return true;
    }

    /**
     * Rebuild the shard map of a field from the shard field indexes (exclusive lock)
     */
    private function rebuildGlobalFieldIndex($dbname, $field){
        $global = ['v' => 2, 'field' => (string)$field, 'shardMap' => []];
        foreach($this->listSegments($dbname) as $shardId){
            $index = $this->readFieldIndex($dbname, $field, $shardId);
            if($index === null){
                $index = $this->buildSegmentFieldIndex($dbname, $shardId, $field);
                $this->writeFieldIndex($dbname, $field, $index, $shardId);
            }
            foreach($index['values'] as $valueKey => $keys){
                if(!empty($keys)){
                    $global['shardMap'][(string)$valueKey][] = $shardId;
                }
            }
        }
        $this->writeGlobalFieldIndex($dbname, $field, $global);
        return $global;
    }

    /**
     * Shards that can contain matches for the filters (null = all shards / not sharded)
     * @return array|null
     */
    private function targetShardsFromGlobalIndex($dbname, array $filters){
        if(!$this->fieldIndexEnabled || !$this->isSharded($dbname) || empty($filters)){
            return null;
        }
        $indexed = $this->getIndexedFields($dbname);
        $targets = null;
        foreach($filters as $field => $value){
            if($field === 'key' || !$this->isIndexableValue($value) || !in_array((string)$field, $indexed, true)){
                continue;
            }
            $global = $this->readGlobalFieldIndex($dbname, $field);
            if($global === null){
                $global = $this->withLock($dbname, true, function() use ($dbname, $field){
                    return $this->rebuildGlobalFieldIndex($dbname, $field);
                });
            }
            $shards = array_map('intval', $global['shardMap'][$this->fieldIndexValueKey($value)] ?? []);
            $targets = $targets === null ? $shards : array_values(array_intersect($targets, $shards));
        }
        return $targets;
    }

    /**
     * Delete global field index file
     */
    private function deleteGlobalFieldIndex($dbname, $field){
        $path = $this->getGlobalFieldIndexPath($dbname, $field);
        clearstatcache(true, $path);
        if(file_exists($path)){
            @unlink($path);
        }
        unset($this->fieldIndexCache[$path]);
    }

    /**
     * After a migration to sharded format: rebuild every field and spatial index per shard and
     * remove the non-sharded index files
     */
    private function rebuildIndexesAfterMigration($dbname){
        $manifest = $this->getIndexManifest($dbname);
        foreach($manifest['fields'] as $field){
            $this->deleteFieldIndexFile($dbname, $field, null);
            foreach($this->listSegments($dbname) as $shardId){
                $this->writeFieldIndex($dbname, $field, $this->buildSegmentFieldIndex($dbname, $shardId, $field), $shardId);
            }
            $this->rebuildGlobalFieldIndex($dbname, $field);
        }
        foreach($manifest['spatial'] as $field){
            $this->rebuildSpatialIndexForDb($dbname, $field);
        }
        $this->saveIndexManifest($dbname, $manifest);
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
            $total += count($this->rebuildSegmentIndexFromData($dbname, $segment)['o']);
        }
        if($this->isSharded($dbname)){
            $this->recountMeta($dbname);
        }
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
            $total += count($index['o']);
            $created = $created === null ? ($index['created'] ?? null) : min($created, $index['created'] ?? $created);
            $updated = max((int)$updated, (int)($index['updated'] ?? 0));
        }
        $sharded = $this->isSharded($dbname);
        return [
            'exists' => true,
            'version' => 3,
            'created' => $created,
            'updated' => $updated,
            'totalRecords' => $total,
            'sharded' => $sharded,
            'path' => $sharded ? $this->getMetaPath($dbname) : $this->getJsonlIndexPath($dbname, null)
        ];
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

        // Create info file
        @file_put_contents($fullDBPath . "info", (string)time());

        // v3.0: Create empty JSONL format database
        if(@touch($fullDBPath) === false){
            throw new \RuntimeException("noneDB: cannot create database file for '{$dbname}'");
        }

        // Create empty JSONL index
        $index = [
            'v' => 3,
            'format' => 'jsonl',
            'created' => time(),
            'n' => 0,
            'd' => 0,
            'o' => []
        ];
        $this->writeJsonlIndex($dbname, $index);

        // Mark file as existing in cache
        $this->markFileExists($fullDBPath);
        $this->jsonlFormatCache[$fullDBPath] = true;

        return true;
    }

    /**
     * Delete a database and every file that belongs to it (data, shards, indexes, buffers, metadata)
     * @param string $dbname
     * @return array ['success' => bool, 'files' => int deleted file count]
     */
    public function dropDB($dbname){
        $dbname = $this->normalizeDbName($dbname);
        if(!$this->dbFilesExist($dbname)){
            return ['success' => false, 'files' => 0, 'error' => 'Database does not exist'];
        }
        return $this->withLock($dbname, true, function() use ($dbname){
            $base = $this->dbBase($dbname);
            $hashBase = $this->dbDir . $this->hashDBName($dbname);
            $lockPath = $this->getLockPath($dbname);
            $files = array_merge(
                glob($base . '.nonedb*') ?: [],
                glob($base . '_s*.nonedb*') ?: [],
                glob($hashBase . '.nonedb.*') ?: [],
                glob($hashBase . '_s*.nonedb.*') ?: []
            );
            $deleted = 0;
            foreach(array_unique($files) as $file){
                // The lock file stays: other processes may be waiting on it, and it carries the generation counter
                if($file === $lockPath || !is_file($file)){
                    continue;
                }
                if(@unlink($file)){
                    $deleted++;
                }
            }
            $this->invalidateDbCaches($dbname);
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
        foreach(glob($base . '_s*.nonedb') ?: [] as $shardFile){
            if(preg_match('/_s\d+\.nonedb$/', $shardFile)){
                $size += (int)filesize($shardFile);
            }
        }
        return array("name" => $dbname, "createdTime" => $created, "size" => $this->fileSizeConvert($size));
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
     * Uncached existence check used before taking a lock
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
        foreach($items as $item){
            if($this->hasReservedKeyField($item)){
                return [[], "You cannot set key name to key"];
            }
            if($this->encodeJson($item) === false){
                return [[], "insert data cannot be encoded as JSON (invalid UTF-8, NAN/INF or unsupported value)"];
            }
        }
        return [$items, null];
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
        $all = $this->find($dbname, 0);
        if($all === false) return false;
        $values = [];
        foreach($all as $record){
            if(isset($record[$field]) && !in_array($record[$field], $values, true)){
                $values[] = $record[$field];
            }
        }
        return $values;
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
     * Total order used by sort(): missing/null < booleans < numbers < strings < arrays.
     * Values of the same kind compare naturally (numbers numerically, strings with <=>).
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
        if(is_string($v)) return [3, $v];
        return [4, (string)json_encode($v)];
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
        // Fast-path: no filter = use index/meta counters
        if($filter === 0 || $filter === null || (is_array($filter) && empty($filter))){
            return $this->countFast($dbname);
        }
        $result = $this->findUnlocked($dbname, $filter);
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
        return $index !== null ? count($index['o']) : 0;
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
        $all = $this->find($dbname, 0);
        if($all === false) return false;

        $result = [];
        $regex = $this->likePatternToRegex((string)$pattern);

        foreach($all as $record){
            if(!isset($record[$field])) continue;
            $value = $record[$field];
            // Skip arrays and objects - can't do string matching on them
            if(is_array($value) || is_object($value)) continue;
            if(preg_match($regex, (string)$value) === 1){
                $result[] = $record;
            }
        }
        return $result;
    }

    /**
     * Turn a like() pattern into a safe regex: "^" anchors the start, a trailing "$" the end,
     * everything else is matched literally and case-insensitively (UTF-8 aware).
     * @param string $pattern
     * @return string
     */
    private function likePatternToRegex($pattern){
        $pattern = (string)$pattern;
        $start = $pattern !== '' && $pattern[0] === '^';
        $end = strlen($pattern) > ($start ? 1 : 0) && substr($pattern, -1) === '$';
        $body = substr($pattern, $start ? 1 : 0, strlen($pattern) - ($start ? 1 : 0) - ($end ? 1 : 0));
        $flags = preg_match('//u', $body) === 1 ? 'iu' : 'i';
        return '~' . ($start ? '^' : '') . preg_quote($body, '~') . ($end ? '$' : '') . '~' . $flags;
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
        $result = $this->find($dbname, $filter);
        if($result === false) return 0;
        $sum = 0;
        foreach($result as $record){
            if(isset($record[$field]) && is_numeric($record[$field])){
                $sum += $record[$field];
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
        $result = $this->find($dbname, $filter);
        if($result === false || count($result) === 0) return 0;
        $sum = 0;
        $count = 0;
        foreach($result as $record){
            if(isset($record[$field]) && is_numeric($record[$field])){
                $sum += $record[$field];
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
        $result = $this->find($dbname, $filter);
        if($result === false || count($result) === 0) return null;
        $min = null;
        foreach($result as $record){
            if(isset($record[$field])){
                if($min === null || $record[$field] < $min){
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
        $result = $this->find($dbname, $filter);
        if($result === false || count($result) === 0) return null;
        $max = null;
        foreach($result as $record){
            if(isset($record[$field])){
                if($max === null || $record[$field] > $max){
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
        $result = $this->find($dbname, $filter);
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
        $result = $this->find($dbname, $filter);
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
        $result = $this->find($dbname, $filter);
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
        $result = $this->find($dbname, $filter);
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
                "totalRecords" => count($index['o']),
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
        $this->flushPendingBuffers($dbname);
        if(!$this->dbExists($dbname)){
            if(!$this->autoCreateDB){
                return ['success' => false, 'indexed' => 0, 'values' => 0, 'error' => 'Database does not exist'];
            }
            $this->createDBUnlocked($dbname);
        }

        $indexed = 0;
        $values = [];
        $segments = $this->listSegments($dbname);
        foreach($segments as $segment){
            $index = $this->buildSegmentFieldIndex($dbname, $segment, $field);
            $this->writeFieldIndex($dbname, $field, $index, $segment);
            foreach($index['values'] as $valueKey => $keys){
                $indexed += count($keys);
                $values[(string)$valueKey] = true;
            }
        }
        if($this->isSharded($dbname)){
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
            if($legacy !== $this->getFieldIndexPath($dbname, $field, $segment) && is_file($legacy)){
                $old = $this->atomicRead($legacy, null);
                if(is_array($old) && ($old['field'] ?? null) === (string)$field){
                    @unlink($legacy);
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
            foreach($index['values'][$this->fieldIndexValueKey($value)] ?? [] as $key){
                $keys[] = (int)$key;
            }
        }
        sort($keys);
        return $keys;
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
        if (!is_array($geometry)) {
            return ['valid' => false, 'error' => 'Geometry must be an array', 'type' => null];
        }
        if (!isset($geometry['type'])) {
            return ['valid' => false, 'error' => 'Missing type property', 'type' => null];
        }

        $type = $geometry['type'];

        // GeometryCollection has different structure
        if ($type === 'GeometryCollection') {
            return $this->validateGeometryCollection($geometry);
        }

        if (!isset($geometry['coordinates'])) {
            return ['valid' => false, 'error' => 'Missing coordinates property', 'type' => null];
        }

        $coords = $geometry['coordinates'];

        if (!is_array($coords)) {
            return ['valid' => false, 'error' => 'Coordinates must be an array', 'type' => null];
        }

        switch ($type) {
            case 'Point':
                return $this->validateGeoJSONPoint($coords);
            case 'LineString':
                return $this->validateGeoJSONLineString($coords);
            case 'Polygon':
                return $this->validateGeoJSONPolygon($coords);
            case 'MultiPoint':
                return $this->validateGeoJSONMultiPoint($coords);
            case 'MultiLineString':
                return $this->validateGeoJSONMultiLineString($coords);
            case 'MultiPolygon':
                return $this->validateGeoJSONMultiPolygon($coords);
            default:
                return ['valid' => false, 'error' => "Unknown geometry type: $type", 'type' => null];
        }
    }

    /**
     * Validate Point coordinates [lon, lat] or [lon, lat, altitude]
     */
    private function validateGeoJSONPoint(array $coords): array {
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
    private function validateGeoJSONLineString(array $coords): array {
        if (!is_array($coords) || count($coords) < 2) {
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
    private function validateGeoJSONPolygon(array $coords): array {
        if (!is_array($coords) || count($coords) < 1) {
            return ['valid' => false, 'error' => 'Polygon must have at least one ring', 'type' => null];
        }
        foreach ($coords as $ringIndex => $ring) {
            if (!is_array($ring) || count($ring) < 4) {
                return ['valid' => false, 'error' => "Ring $ringIndex must have at least 4 positions", 'type' => null];
            }
            // Validate each position
            foreach ($ring as $i => $position) {
                $result = $this->validateGeoJSONPoint($position);
                if (!$result['valid']) {
                    return ['valid' => false, 'error' => "Invalid position at ring $ringIndex, index $i: " . $result['error'], 'type' => null];
                }
            }
            // Check if ring is closed (first == last, compared numerically: 29 and 29.0 are the same)
            $first = $ring[0];
            $last = $ring[count($ring) - 1];
            if ((float)$first[0] != (float)$last[0] || (float)$first[1] != (float)$last[1]) {
                return ['valid' => false, 'error' => "Ring $ringIndex must be closed (first and last positions must be identical)", 'type' => null];
            }
            if ($this->ringSelfIntersects($ring)) {
                return ['valid' => false, 'error' => "Ring $ringIndex must not intersect itself", 'type' => null];
            }
            if ($ringIndex > 0) {
                foreach ($ring as $position) {
                    if (!$this->pointInRing((float)$position[0], (float)$position[1], $coords[0]) && !$this->pointOnRing((float)$position[0], (float)$position[1], $coords[0])) {
                        return ['valid' => false, 'error' => "Hole $ringIndex must lie inside the outer ring", 'type' => null];
                    }
                }
            }
        }
        return ['valid' => true, 'error' => null, 'type' => 'Polygon'];
    }

    /**
     * True when two non-adjacent edges of a closed ring cross
     */
    private function ringSelfIntersects(array $ring): bool {
        $n = count($ring) - 1; // edges
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 2; $j < $n; $j++) {
                if ($i === 0 && $j === $n - 1) {
                    continue; // first and last edge share the closing vertex
                }
                if ($this->lineSegmentsIntersect($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])) {
                    return true;
                }
            }
        }
        return false;
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
    private function validateGeoJSONMultiPoint(array $coords): array {
        if (!is_array($coords) || count($coords) < 1) {
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
    private function validateGeoJSONMultiLineString(array $coords): array {
        if (!is_array($coords) || count($coords) < 1) {
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
    private function validateGeoJSONMultiPolygon(array $coords): array {
        if (!is_array($coords) || count($coords) < 1) {
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
        if (!isset($geometry['geometries']) || !is_array($geometry['geometries'])) {
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
     * @return array [minLon, minLat, maxLon, maxLat]
     */
    private function calculateMBR(array $geometry): array {
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
                    $mbr = $mbr === null ? $geomMBR : $this->mbrUnion($mbr, $geomMBR);
                }
                return $mbr ?? [0, 0, 0, 0];

            default:
                return [0, 0, 0, 0];
        }
    }

    /**
     * Calculate MBR from array of points
     * @param array $points Array of [lon, lat] positions
     * @return array [minLon, minLat, maxLon, maxLat]
     */
    private function mbrFromPoints(array $points): array {
        if (empty($points)) {
            return [0, 0, 0, 0];
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
     * Uses memoization cache for repeated calculations
     * @param float $lon1 Longitude of point 1
     * @param float $lat1 Latitude of point 1
     * @param float $lon2 Longitude of point 2
     * @param float $lat2 Latitude of point 2
     * @return float Distance in meters
     */
    public function haversineDistance(float $lon1, float $lat1, float $lon2, float $lat2): float {
        // Check cache first (memoization)
        $key = "$lon1,$lat1,$lon2,$lat2";
        if (isset($this->distanceCache[$key])) {
            return $this->distanceCache[$key];
        }

        $earthRadius = 6371000.0; // meters

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = $earthRadius * $c;

        // Cache result; when full, the older half makes room (the cache keeps working)
        if (count($this->distanceCache) >= 10000) {
            $this->distanceCache = array_slice($this->distanceCache, 5000, null, true);
        }
        $this->distanceCache[$key] = $distance;

        return $distance;
    }

    /**
     * Clear distance cache (call after each query for memory management)
     */
    public function clearDistanceCache(): void {
        $this->distanceCache = [];
    }

    /**
     * Convert a circle (center + radius) to a bounding box
     * @param float $lon Center longitude
     * @param float $lat Center latitude
     * @param float $radiusMeters Radius in meters
     * @return array [minLon, minLat, maxLon, maxLat]
     */
    private function circleToBBox(float $lon, float $lat, float $radiusMeters): array {
        // Approximate degrees per meter at this latitude
        $latDeg = $radiusMeters / 111000.0; // 1 degree lat ~ 111 km = 111000 m
        $lonDeg = $radiusMeters / (111000.0 * cos(deg2rad($lat)));

        // Near or over a pole every longitude is in range
        if ($lat + $latDeg >= 90 || $lat - $latDeg <= -90 || $lonDeg >= 180 || !is_finite($lonDeg)) {
            $lonDeg = 360;
        }

        return [
            max(-180, $lon - $lonDeg), // minLon
            max(-90, $lat - $latDeg),  // minLat
            min(180, $lon + $lonDeg),  // maxLon
            min(90, $lat + $latDeg)    // maxLat
        ];
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
     * Check if two line segments intersect
     * @param array $p1 First point of segment 1 [lon, lat]
     * @param array $p2 Second point of segment 1 [lon, lat]
     * @param array $p3 First point of segment 2 [lon, lat]
     * @param array $p4 Second point of segment 2 [lon, lat]
     * @return bool
     */
    private function lineSegmentsIntersect(array $p1, array $p2, array $p3, array $p4): bool {
        $d1 = $this->crossProductDirection($p3, $p4, $p1);
        $d2 = $this->crossProductDirection($p3, $p4, $p2);
        $d3 = $this->crossProductDirection($p1, $p2, $p3);
        $d4 = $this->crossProductDirection($p1, $p2, $p4);

        if ((($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) &&
            (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0))) {
            return true;
        }

        // Check collinear cases
        if ($d1 == 0 && $this->onSegment($p3, $p4, $p1)) return true;
        if ($d2 == 0 && $this->onSegment($p3, $p4, $p2)) return true;
        if ($d3 == 0 && $this->onSegment($p1, $p2, $p3)) return true;
        if ($d4 == 0 && $this->onSegment($p1, $p2, $p4)) return true;

        return false;
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
     * Check if two polygons intersect
     * @param array $poly1 First polygon coordinates
     * @param array $poly2 Second polygon coordinates
     * @return bool
     */
    private function polygonsIntersect(array $poly1, array $poly2): bool {
        // Handle GeoJSON Polygon objects or raw coordinates
        $coords1 = isset($poly1['coordinates']) ? $poly1['coordinates'] : $poly1;
        $coords2 = isset($poly2['coordinates']) ? $poly2['coordinates'] : $poly2;

        // 1. Quick MBR rejection test
        $mbr1 = $this->mbrFromPoints($coords1[0]);
        $mbr2 = $this->mbrFromPoints($coords2[0]);
        if (!$this->mbrOverlaps($mbr1, $mbr2)) {
            return false;
        }

        // 2. Check if any vertex of poly1 is inside poly2
        foreach ($coords1[0] as $point) {
            if ($this->pointInPolygon($point[0], $point[1], $coords2)) {
                return true;
            }
        }

        // 3. Check if any vertex of poly2 is inside poly1
        foreach ($coords2[0] as $point) {
            if ($this->pointInPolygon($point[0], $point[1], $coords1)) {
                return true;
            }
        }

        // 4. Check edge intersections
        $ring1 = $coords1[0];
        $ring2 = $coords2[0];

        for ($i = 0; $i < count($ring1) - 1; $i++) {
            for ($j = 0; $j < count($ring2) - 1; $j++) {
                if ($this->lineSegmentsIntersect($ring1[$i], $ring1[$i + 1], $ring2[$j], $ring2[$j + 1])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get centroid of a geometry (for distance calculations)
     * Uses caching for repeated geometry lookups
     * @param array $geometry GeoJSON geometry
     * @return array [lon, lat]
     */
    private function getGeometryCentroid(array $geometry): array {
        if (($geometry['type'] ?? '') === 'Point' && isset($geometry['coordinates'][0], $geometry['coordinates'][1])) {
            return [$geometry['coordinates'][0], $geometry['coordinates'][1]];
        }
        // Check cache first (use JSON hash as key)
        $cacheKey = md5((string)json_encode($geometry));
        if (isset($this->centroidCache[$cacheKey])) {
            return $this->centroidCache[$cacheKey];
        }

        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        $result = null;

        switch ($type) {
            case 'Point':
                $result = [$coords[0], $coords[1]];
                break;

            case 'LineString':
            case 'MultiPoint':
                $sumLon = $sumLat = 0;
                foreach ($coords as $point) {
                    $sumLon += $point[0];
                    $sumLat += $point[1];
                }
                $n = count($coords);
                $result = [$sumLon / $n, $sumLat / $n];
                break;

            case 'Polygon':
                // Centroid of outer ring
                $ring = $coords[0];
                $sumLon = $sumLat = 0;
                $n = count($ring) - 1; // Exclude closing point
                for ($i = 0; $i < $n; $i++) {
                    $sumLon += $ring[$i][0];
                    $sumLat += $ring[$i][1];
                }
                $result = [$sumLon / $n, $sumLat / $n];
                break;

            default:
                // For complex types, use MBR center
                $mbr = $this->calculateMBR($geometry);
                $result = [($mbr[0] + $mbr[2]) / 2, ($mbr[1] + $mbr[3]) / 2];
        }

        // Cache result; when full, the older half makes room
        if (count($this->centroidCache) >= 5000) {
            $this->centroidCache = array_slice($this->centroidCache, 2500, null, true);
        }
        $this->centroidCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Clear centroid cache
     */
    public function clearCentroidCache(): void {
        $this->centroidCache = [];
    }

    // ==========================================
    // SPATIAL INDEX - R-TREE ENGINE
    // ==========================================
    // One R-tree per segment and indexed field: {dbDir}{hash}[_sN].nonedb.sidx.{field}. Sharded databases
    // also keep {hash}.nonedb.gsidx.{field} with each shard's bounding box (shard skip). Which fields are
    // indexed is recorded in the index manifest. Index files of format v3 are complete; older files
    // (which could miss records) are rebuilt on first use.

    /**
     * Get path to spatial index file
     * @param string $dbname Database name
     * @param string $field Field name
     * @param int|null $shardId Shard ID (null for non-sharded)
     * @return string File path
     */
    private function getSpatialIndexPath(string $dbname, string $field, ?int $shardId = null): string {
        $hash = $this->hashDBName($dbname);
        $name = $this->encodeIndexFieldName($field);
        if ($shardId !== null) {
            return $this->dbDir . $hash . '_s' . $shardId . '.nonedb.sidx.' . $name;
        }
        return $this->dbDir . $hash . '.nonedb.sidx.' . $name;
    }

    /**
     * Get path to global spatial index file (shard MBR metadata)
     */
    private function getGlobalSpatialIndexPath(string $dbname, string $field): string {
        return $this->dbDir . $this->hashDBName($dbname) . '.nonedb.gsidx.' . $this->encodeIndexFieldName($field);
    }

    /**
     * Read a segment's R-tree. Null when missing or from a pre-3.2 version.
     */
    private function readSpatialIndex(string $dbname, string $field, ?int $shardId = null): ?array {
        $path = $this->getSpatialIndexPath($dbname, $field, $shardId);
        if (array_key_exists($path, $this->spatialIndexCache)) {
            return $this->spatialIndexCache[$path];
        }
        $index = $this->atomicRead($path, null);
        if (!is_array($index) || ($index['v'] ?? 0) < 3 || !isset($index['nodes'], $index['rootId'], $index['leafOf'])) {
            $index = null;
        }
        $this->spatialIndexCache[$path] = $index;
        return $index;
    }

    /**
     * Write a segment's R-tree (caller holds the exclusive lock)
     */
    private function writeSpatialIndex(string $dbname, string $field, array $index, ?int $shardId = null): bool {
        $path = $this->getSpatialIndexPath($dbname, $field, $shardId);
        $index['updated'] = time();
        if (!$this->atomicWrite($path, $index)) {
            unset($this->spatialIndexCache[$path]);
            throw new \RuntimeException("noneDB: failed to write spatial index '{$field}' of database '{$dbname}'");
        }
        $this->spatialIndexCache[$path] = $index;
        unset($this->dirtySpatialIndexes[$path]);
        return true;
    }

    /**
     * Keep an updated R-tree in memory; it is written before the write lock is released
     */
    private function markSpatialIndexDirty(string $dbname, string $field, array $index, ?int $shardId = null): void {
        $path = $this->getSpatialIndexPath($dbname, $field, $shardId);
        $this->spatialIndexCache[$path] = $index;
        $this->dirtySpatialIndexes[$path] = ['dbname' => $dbname, 'field' => $field, 'shardId' => $shardId];
    }

    /**
     * Write all dirty spatial indexes to disk (called automatically when a write lock is released)
     * @return int Number of indexes flushed
     */
    public function flushSpatialIndexes(): int {
        $flushed = 0;
        foreach ($this->dirtySpatialIndexes as $path => $info) {
            if (isset($this->spatialIndexCache[$path])) {
                $this->writeSpatialIndex($info['dbname'], $info['field'], $this->spatialIndexCache[$path], $info['shardId']);
                $flushed++;
            }
        }
        $this->dirtySpatialIndexes = [];
        return $flushed;
    }

    /**
     * Read global spatial index (shard MBR metadata); null when missing or pre-3.2
     */
    private function readGlobalSpatialIndex(string $dbname, string $field): ?array {
        $path = $this->getGlobalSpatialIndexPath($dbname, $field);
        if (array_key_exists($path, $this->globalSpatialIndexCache)) {
            return $this->globalSpatialIndexCache[$path];
        }
        $index = $this->atomicRead($path, null);
        if (!is_array($index) || ($index['v'] ?? 0) < 3 || !isset($index['shardMBRs']) || !is_array($index['shardMBRs'])) {
            $index = null;
        }
        $this->globalSpatialIndexCache[$path] = $index;
        return $index;
    }

    /**
     * Write global spatial index (caller holds the exclusive lock)
     */
    private function writeGlobalSpatialIndex(string $dbname, string $field, array $index): bool {
        $path = $this->getGlobalSpatialIndexPath($dbname, $field);
        $index['v'] = 3;
        $index['field'] = $field;
        $index['updated'] = time();
        if (!$this->atomicWrite($path, $index)) {
            unset($this->globalSpatialIndexCache[$path]);
            throw new \RuntimeException("noneDB: failed to write global spatial index '{$field}' of database '{$dbname}'");
        }
        $this->globalSpatialIndexCache[$path] = $index;
        return true;
    }

    /**
     * Create a new empty R-tree index structure
     * @param string $field Field name
     * @return array Empty index structure
     */
    private function createEmptyRTreeIndex(string $field): array {
        return [
            'v' => 3,
            'field' => $field,
            'type' => 'rtree',
            'nodeSize' => $this->rtreeNodeSize,
            'created' => time(),
            'updated' => time(),
            'rootId' => 0,
            'nextNodeId' => 1,
            'nodes' => [
                '0' => ['isLeaf' => true, 'mbr' => null, 'entries' => [], 'parent' => null]
            ],
            'leafOf' => []
        ];
    }

    /**
     * Add an entry to an in-memory R-tree
     */
    private function rtreeAddEntry(array &$index, int $key, array $geometry): void {
        if (isset($index['leafOf'][$key])) {
            $this->rtreeRemoveEntry($index, $key);
        }
        $mbr = $this->calculateMBR($geometry);
        $leafId = $this->rtreeChooseLeaf($index, $mbr);
        $index['nodes'][$leafId]['entries'][] = ['key' => $key, 'mbr' => $mbr, 'type' => $geometry['type']];
        $index['nodes'][$leafId]['mbr'] = $index['nodes'][$leafId]['mbr'] === null
            ? $mbr
            : $this->mbrUnion($index['nodes'][$leafId]['mbr'], $mbr);
        $index['leafOf'][$key] = $leafId;
        if (count($index['nodes'][$leafId]['entries']) > $index['nodeSize']) {
            $this->rtreeSplitNode($index, $leafId);
        }
        $this->rtreeAdjustTree($index, $leafId);
    }

    /**
     * Choose the best leaf node for inserting an entry (least enlargement, then smallest area)
     * @return string Leaf node ID
     */
    private function rtreeChooseLeaf(array &$index, array $entryMBR): string {
        $nodeId = (string)$index['rootId'];

        while (!$index['nodes'][$nodeId]['isLeaf']) {
            $minEnlargement = PHP_FLOAT_MAX;
            $bestChild = null;
            $bestArea = PHP_FLOAT_MAX;

            foreach ($index['nodes'][$nodeId]['children'] as $childId) {
                $child = $index['nodes'][$childId] ?? null;
                if ($child === null) {
                    continue;
                }
                $childMBR = $child['mbr'];
                if ($childMBR === null) {
                    if ($child['isLeaf']) {
                        $bestChild = $childId; // an empty leaf costs nothing
                        break;
                    }
                    continue; // empty internal node: never descend into it
                }
                $enlargement = $this->mbrEnlargement($childMBR, $entryMBR);
                $area = $this->mbrArea($childMBR);
                if ($enlargement < $minEnlargement || ($enlargement == $minEnlargement && $area < $bestArea)) {
                    $minEnlargement = $enlargement;
                    $bestArea = $area;
                    $bestChild = $childId;
                }
            }

            if ($bestChild === null) {
                // Internal node without usable children: turn it into an empty leaf
                $index['nodes'][$nodeId] = ['isLeaf' => true, 'mbr' => null, 'entries' => [], 'parent' => $index['nodes'][$nodeId]['parent']];
                return $nodeId;
            }
            $nodeId = (string)$bestChild;
        }

        return $nodeId;
    }

    /**
     * Split an overflowing node using Linear Split algorithm (O(n) seed selection)
     * @param array &$index R-tree index (modified in place)
     * @param string $nodeId Node to split
     */
    private function rtreeSplitNode(array &$index, string $nodeId): void {
        $node = $index['nodes'][$nodeId];
        $isLeaf = $node['isLeaf'];
        $entries = $isLeaf ? $node['entries'] : $node['children'];
        $n = count($entries);

        if ($n <= $index['nodeSize']) {
            return;
        }

        $mbrOf = function ($entry) use (&$index, $isLeaf) {
            return $isLeaf ? $entry['mbr'] : ($index['nodes'][$entry]['mbr'] ?? null);
        };

        // Linear split: seeds with the greatest normalized separation
        $minLon = $maxLon = $minLat = $maxLat = null;
        $minLonIdx = $maxLonIdx = $minLatIdx = $maxLatIdx = 0;
        $globalMinLon = $globalMinLat = PHP_FLOAT_MAX;
        $globalMaxLon = $globalMaxLat = -PHP_FLOAT_MAX;

        for ($i = 0; $i < $n; $i++) {
            $mbr = $mbrOf($entries[$i]);
            if ($mbr === null) continue;
            $globalMinLon = min($globalMinLon, $mbr[0]);
            $globalMaxLon = max($globalMaxLon, $mbr[2]);
            $globalMinLat = min($globalMinLat, $mbr[1]);
            $globalMaxLat = max($globalMaxLat, $mbr[3]);
            if ($minLon === null || $mbr[0] > $minLon) { $minLon = $mbr[0]; $minLonIdx = $i; }
            if ($minLat === null || $mbr[1] > $minLat) { $minLat = $mbr[1]; $minLatIdx = $i; }
            if ($maxLon === null || $mbr[2] < $maxLon) { $maxLon = $mbr[2]; $maxLonIdx = $i; }
            if ($maxLat === null || $mbr[3] < $maxLat) { $maxLat = $mbr[3]; $maxLatIdx = $i; }
        }

        $lonWidth = $globalMaxLon - $globalMinLon;
        $latWidth = $globalMaxLat - $globalMinLat;
        $lonSep = ($lonWidth > 0 && $minLon !== null) ? ($minLon - $maxLon) / $lonWidth : 0;
        $latSep = ($latWidth > 0 && $minLat !== null) ? ($minLat - $maxLat) / $latWidth : 0;

        if ($lonSep >= $latSep) {
            $seed1 = $minLonIdx;
            $seed2 = $maxLonIdx;
        } else {
            $seed1 = $minLatIdx;
            $seed2 = $maxLatIdx;
        }
        if ($seed1 === $seed2) {
            $seed2 = ($seed1 + 1) % $n;
        }

        $group1 = [$entries[$seed1]];
        $group2 = [$entries[$seed2]];
        $mbr1 = $mbrOf($entries[$seed1]);
        $mbr2 = $mbrOf($entries[$seed2]);
        $minEntries = max(1, (int)($index['nodeSize'] * 0.4));

        for ($i = 0; $i < $n; $i++) {
            if ($i == $seed1 || $i == $seed2) continue;
            $entry = $entries[$i];
            $entryMBR = $mbrOf($entry);
            $remaining = $n - $i - 1;

            if ($entryMBR === null) {
                if (count($group1) <= count($group2)) { $group1[] = $entry; } else { $group2[] = $entry; }
                continue;
            }
            if (count($group1) + $remaining <= $minEntries) {
                $group1[] = $entry;
                $mbr1 = $mbr1 === null ? $entryMBR : $this->mbrUnion($mbr1, $entryMBR);
                continue;
            }
            if (count($group2) + $remaining <= $minEntries) {
                $group2[] = $entry;
                $mbr2 = $mbr2 === null ? $entryMBR : $this->mbrUnion($mbr2, $entryMBR);
                continue;
            }
            $enlargement1 = $mbr1 === null ? 0 : $this->mbrEnlargement($mbr1, $entryMBR);
            $enlargement2 = $mbr2 === null ? 0 : $this->mbrEnlargement($mbr2, $entryMBR);
            if ($enlargement1 < $enlargement2 || ($enlargement1 == $enlargement2 && count($group1) <= count($group2))) {
                $group1[] = $entry;
                $mbr1 = $mbr1 === null ? $entryMBR : $this->mbrUnion($mbr1, $entryMBR);
            } else {
                $group2[] = $entry;
                $mbr2 = $mbr2 === null ? $entryMBR : $this->mbrUnion($mbr2, $entryMBR);
            }
        }

        $newNodeId = (string)$index['nextNodeId']++;
        $currentParent = $index['nodes'][$nodeId]['parent'] ?? null;

        if ($isLeaf) {
            $index['nodes'][$nodeId]['entries'] = $group1;
            $index['nodes'][$nodeId]['mbr'] = $mbr1;
            $index['nodes'][$newNodeId] = ['isLeaf' => true, 'mbr' => $mbr2, 'entries' => $group2, 'parent' => $currentParent];
            foreach ($group2 as $entry) {
                $index['leafOf'][$entry['key']] = $newNodeId;
            }
        } else {
            $index['nodes'][$nodeId]['children'] = $group1;
            $index['nodes'][$nodeId]['mbr'] = $mbr1;
            $index['nodes'][$newNodeId] = ['isLeaf' => false, 'mbr' => $mbr2, 'children' => $group2, 'parent' => $currentParent];
            foreach ($group2 as $childId) {
                $index['nodes'][$childId]['parent'] = $newNodeId;
            }
            foreach ($group1 as $childId) {
                $index['nodes'][$childId]['parent'] = $nodeId;
            }
        }

        if ($nodeId == (string)$index['rootId']) {
            $newRootId = (string)$index['nextNodeId']++;
            $index['nodes'][$newRootId] = [
                'isLeaf' => false,
                'mbr' => $mbr1 !== null && $mbr2 !== null ? $this->mbrUnion($mbr1, $mbr2) : ($mbr1 ?? $mbr2),
                'children' => [$nodeId, $newNodeId],
                'parent' => null
            ];
            $index['rootId'] = (int)$newRootId;
            $index['nodes'][$nodeId]['parent'] = $newRootId;
            $index['nodes'][$newNodeId]['parent'] = $newRootId;
        } elseif ($currentParent !== null) {
            $index['nodes'][$currentParent]['children'][] = $newNodeId;
            if (count($index['nodes'][$currentParent]['children']) > $index['nodeSize']) {
                $this->rtreeSplitNode($index, (string)$currentParent);
            }
        }
    }

    /**
     * Recompute one node's MBR from its entries/children
     */
    private function rtreeRecomputeMBR(array &$index, string $nodeId): void {
        $node = $index['nodes'][$nodeId];
        $mbr = null;
        if ($node['isLeaf']) {
            foreach ($node['entries'] as $entry) {
                $mbr = $mbr === null ? $entry['mbr'] : $this->mbrUnion($mbr, $entry['mbr']);
            }
        } else {
            foreach ($node['children'] as $childId) {
                $childMBR = $index['nodes'][$childId]['mbr'] ?? null;
                if ($childMBR !== null) {
                    $mbr = $mbr === null ? $childMBR : $this->mbrUnion($mbr, $childMBR);
                }
            }
        }
        $index['nodes'][$nodeId]['mbr'] = $mbr;
    }

    /**
     * Adjust MBRs of all ancestors of a node
     */
    private function rtreeAdjustTree(array &$index, string $nodeId): void {
        $parentId = $index['nodes'][$nodeId]['parent'] ?? null;
        while ($parentId !== null && isset($index['nodes'][$parentId])) {
            $this->rtreeRecomputeMBR($index, (string)$parentId);
            $parentId = $index['nodes'][$parentId]['parent'] ?? null;
        }
    }

    /**
     * Remove an entry from an in-memory R-tree, removing emptied nodes bottom-up
     * @return bool True if the key was indexed
     */
    private function rtreeRemoveEntry(array &$index, int $key): bool {
        $leafId = $index['leafOf'][$key] ?? null;
        if ($leafId === null || !isset($index['nodes'][$leafId])) {
            return false;
        }
        $leafId = (string)$leafId;
        $entries = $index['nodes'][$leafId]['entries'];
        foreach ($entries as $i => $entry) {
            if ($entry['key'] === $key) {
                array_splice($entries, $i, 1);
                break;
            }
        }
        $index['nodes'][$leafId]['entries'] = $entries;
        unset($index['leafOf'][$key]);

        // Condense: drop empty non-root nodes, walking up while parents become empty too
        $nodeId = $leafId;
        $rootId = (string)$index['rootId'];
        while ($nodeId !== $rootId) {
            $node = $index['nodes'][$nodeId];
            $empty = $node['isLeaf'] ? empty($node['entries']) : empty($node['children']);
            if (!$empty) {
                break;
            }
            $parentId = (string)$node['parent'];
            $index['nodes'][$parentId]['children'] = array_values(array_filter(
                $index['nodes'][$parentId]['children'],
                function ($c) use ($nodeId) { return (string)$c !== $nodeId; }
            ));
            unset($index['nodes'][$nodeId]);
            $nodeId = $parentId;
        }
        $this->rtreeRecomputeMBR($index, $nodeId);
        $this->rtreeAdjustTree($index, $nodeId);
        $this->rtreeCondenseTree($index);
        return true;
    }

    /**
     * Collapse the root: an internal root with no children becomes an empty leaf, one with a single
     * child is replaced by that child
     */
    private function rtreeCondenseTree(array &$index): void {
        while (true) {
            $rootId = (string)$index['rootId'];
            $root = $index['nodes'][$rootId];
            if ($root['isLeaf']) {
                return;
            }
            $children = $root['children'] ?? [];
            if (empty($children)) {
                $index['nodes'][$rootId] = ['isLeaf' => true, 'mbr' => null, 'entries' => [], 'parent' => null];
                return;
            }
            if (count($children) > 1) {
                return;
            }
            $newRootId = (string)$children[0];
            unset($index['nodes'][$rootId]);
            $index['rootId'] = (int)$newRootId;
            $index['nodes'][$newRootId]['parent'] = null;
        }
    }

    /**
     * Search R-tree for entries overlapping any of the given MBRs
     * @param array $searchMBR One MBR [minLon, minLat, maxLon, maxLat] or a list of MBRs
     * @return array Matching keys
     */
    private function rtreeSearchMBR(string $dbname, string $field, array $searchMBR, ?int $shardId = null): array {
        $index = $this->ensureSegmentSpatialIndex($dbname, $field, $shardId);
        $boxes = is_array($searchMBR[0] ?? null) ? $searchMBR : [$searchMBR];
        $results = [];
        foreach ($boxes as $box) {
            $found = [];
            $this->rtreeSearchNode($index, (string)$index['rootId'], $box, $found);
            foreach ($found as $key) {
                $results[$key] = $key;
            }
        }
        return array_values($results);
    }

    /**
     * Recursive search helper
     */
    private function rtreeSearchNode(array &$index, string $nodeId, array $searchMBR, array &$results): void {
        $node = $index['nodes'][$nodeId] ?? null;
        if ($node === null || $node['mbr'] === null || !$this->mbrOverlaps($node['mbr'], $searchMBR)) {
            return;
        }
        if ($node['isLeaf']) {
            foreach ($node['entries'] as $entry) {
                if ($this->mbrOverlaps($entry['mbr'], $searchMBR)) {
                    $results[] = $entry['key'];
                }
            }
        } else {
            foreach ($node['children'] as $childId) {
                $this->rtreeSearchNode($index, (string)$childId, $searchMBR, $results);
            }
        }
    }

    /**
     * Build a segment's R-tree from its data
     */
    private function buildSegmentSpatialIndex(string $dbname, string $field, ?int $shardId, array $options = []): array {
        $index = $this->createEmptyRTreeIndex($field);
        if (isset($options['nodeSize']) && (int)$options['nodeSize'] >= 4) {
            $index['nodeSize'] = (int)$options['nodeSize'];
        }
        foreach ($this->readSegmentRecords($dbname, $shardId) as $key => $record) {
            $geometry = $record[$field] ?? null;
            if (is_array($geometry) && $this->validateGeoJSON($geometry)['valid']) {
                $this->rtreeAddEntry($index, (int)$key, $geometry);
            }
        }
        return $index;
    }

    /**
     * A segment's R-tree, (re)built when missing or from an older version (exclusive lock)
     */
    private function ensureSegmentSpatialIndex(string $dbname, string $field, ?int $shardId): array {
        $index = $this->readSpatialIndex($dbname, $field, $shardId);
        if ($index !== null) {
            return $index;
        }
        return $this->withLock($dbname, true, function () use ($dbname, $field, $shardId) {
            unset($this->spatialIndexCache[$this->getSpatialIndexPath($dbname, $field, $shardId)]);
            $index = $this->readSpatialIndex($dbname, $field, $shardId);
            if ($index === null) {
                $nodeSize = $this->getIndexManifest($dbname)['spatialOptions'][$field]['nodeSize'] ?? null;
                $index = $this->buildSegmentSpatialIndex($dbname, $field, $shardId, $nodeSize ? ['nodeSize' => $nodeSize] : []);
                $this->writeSpatialIndex($dbname, $field, $index, $shardId);
                if ($shardId !== null) {
                    $this->updateGlobalSpatialShard($dbname, $field, $shardId, $index);
                }
            }
            return $index;
        });
    }

    /**
     * Record a shard's bounding box in the global spatial index
     */
    private function updateGlobalSpatialShard(string $dbname, string $field, int $shardId, array $index): void {
        $global = $this->readGlobalSpatialIndex($dbname, $field) ?? ['v' => 3, 'field' => $field, 'shardMBRs' => []];
        $mbr = $index['nodes'][(string)$index['rootId']]['mbr'] ?? null;
        $current = $global['shardMBRs'][(string)$shardId] ?? null;
        if ($mbr === $current) {
            return;
        }
        if ($mbr === null) {
            unset($global['shardMBRs'][(string)$shardId]);
        } else {
            $global['shardMBRs'][(string)$shardId] = $mbr;
        }
        $this->writeGlobalSpatialIndex($dbname, $field, $global);
    }

    /**
     * Rebuild a spatial index for the whole database (all segments + global shard boxes)
     */
    private function rebuildSpatialIndexForDb($dbname, $field){
        $manifest = $this->getIndexManifest($dbname);
        $nodeSize = $manifest['spatialOptions'][$field]['nodeSize'] ?? null;
        $options = $nodeSize ? ['nodeSize' => $nodeSize] : [];
        @unlink($this->getSpatialIndexPath($dbname, $field, null));
        unset($this->spatialIndexCache[$this->getSpatialIndexPath($dbname, $field, null)]);
        $indexed = 0;
        $sharded = $this->isSharded($dbname);
        if ($sharded) {
            @unlink($this->getGlobalSpatialIndexPath($dbname, $field));
            unset($this->globalSpatialIndexCache[$this->getGlobalSpatialIndexPath($dbname, $field)]);
        }
        foreach ($this->listSegments($dbname) as $segment) {
            $index = $this->buildSegmentSpatialIndex($dbname, $field, $segment, $options);
            $this->writeSpatialIndex($dbname, $field, $index, $segment);
            $indexed += count($index['leafOf']);
            if ($sharded) {
                $this->updateGlobalSpatialShard($dbname, $field, $segment, $index);
            }
        }
        if ($sharded && $this->readGlobalSpatialIndex($dbname, $field) === null) {
            $this->writeGlobalSpatialIndex($dbname, $field, ['v' => 3, 'field' => $field, 'shardMBRs' => []]);
        }
        return $indexed;
    }

    /**
     * Delete the spatial index files of one shard
     */
    private function removeSpatialShardFiles($dbname, $shardId){
        $prefix = $this->dbDir . $this->hashDBName($dbname) . '_s' . (int)$shardId . '.nonedb.sidx.';
        foreach (glob($prefix . '*') ?: [] as $file) {
            @unlink($file);
            unset($this->spatialIndexCache[$file], $this->dirtySpatialIndexes[$file]);
        }
        foreach ($this->getIndexManifest($dbname)['spatial'] as $field) {
            $global = $this->readGlobalSpatialIndex($dbname, $field);
            if ($global !== null && isset($global['shardMBRs'][(string)$shardId])) {
                unset($global['shardMBRs'][(string)$shardId]);
                $this->writeGlobalSpatialIndex($dbname, $field, $global);
            }
        }
    }

    /**
     * Keep spatial indexes in sync after records of a segment changed (called by the storage core)
     * @param array $removed [key => old record]
     * @param array $added [key => new record]
     */
    private function applySpatialIndexChanges($dbname, $shardId, array $removed, array $added){
        // Maintained even when spatialIndexEnabled is off; that flag only affects creating/using indexes
        if (empty($removed) && empty($added)) {
            return;
        }
        foreach ($this->getIndexManifest($dbname)['spatial'] as $field) {
            $existing = $this->readSpatialIndex($dbname, $field, $shardId);
            if ($existing === null) {
                // Missing (new shard) or pre-3.2: built from the data, which already holds the change
                $this->ensureSegmentSpatialIndex($dbname, $field, $shardId);
                continue;
            }
            $index = $existing;
            $changed = false;
            foreach ($removed as $key => $old) {
                if (isset($added[$key]) && ($old[$field] ?? null) === ($added[$key][$field] ?? null)) {
                    continue; // geometry unchanged
                }
                $changed = $this->rtreeRemoveEntry($index, (int)$key) || $changed;
            }
            foreach ($added as $key => $new) {
                if (isset($removed[$key]) && ($removed[$key][$field] ?? null) === ($new[$field] ?? null)) {
                    continue;
                }
                $geometry = $new[$field] ?? null;
                if (is_array($geometry) && $this->validateGeoJSON($geometry)['valid']) {
                    $this->rtreeAddEntry($index, (int)$key, $geometry);
                    $changed = true;
                }
            }
            if ($changed) {
                $this->markSpatialIndexDirty($dbname, $field, $index, $shardId);
                if ($shardId !== null) {
                    $this->updateGlobalSpatialShard($dbname, $field, $shardId, $index);
                }
            }
        }
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

        $manifest['spatial'][] = $field;
        if (isset($options['nodeSize']) && (int)$options['nodeSize'] >= 4) {
            $manifest['spatialOptions'][$field] = ['nodeSize' => (int)$options['nodeSize']];
        }
        $this->saveIndexManifest($dbname, $manifest);

        $indexed = $this->rebuildSpatialIndexForDb($dbname, $field);
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
            $path = $this->getSpatialIndexPath($dbname, $field, $segment);
            @unlink($path);
            unset($this->spatialIndexCache[$path], $this->dirtySpatialIndexes[$path]);
        }
        $globalPath = $this->getGlobalSpatialIndexPath($dbname, $field);
        @unlink($globalPath);
        unset($this->globalSpatialIndexCache[$globalPath]);

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
        $sharded = $this->isSharded($dbname);
        $global = ($indexed && $sharded) ? $this->readGlobalSpatialIndex($dbname, $field) : null;

        foreach ($this->listSegments($dbname) as $segment) {
            if (!$indexed) {
                $records += $this->readSegmentRecords($dbname, $segment);
                continue;
            }
            if ($global !== null) {
                $shardMBR = $global['shardMBRs'][(string)$segment] ?? null;
                $overlaps = false;
                foreach ($boxes as $box) {
                    if ($shardMBR !== null && $this->mbrOverlaps($shardMBR, $box)) {
                        $overlaps = true;
                        break;
                    }
                }
                if (!$overlaps) {
                    // Verify the shard box is current before skipping: it's rebuilt with the shard index
                    if ($this->readSpatialIndex($dbname, $field, $segment) !== null) {
                        continue;
                    }
                }
            }
            $keys = $this->rtreeSearchMBR($dbname, $field, $boxes, $segment);
            if (!empty($keys)) {
                $records += $this->readSegmentRecords($dbname, $segment, $keys);
            }
        }
        return $records;
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
     * Distance from a point to a polyline (meters)
     */
    private function distanceToPath(float $lon, float $lat, array $points): float {
        $n = count($points);
        if ($n === 0) {
            return INF;
        }
        $best = $this->haversineDistance($lon, $lat, (float)$points[0][0], (float)$points[0][1]);
        $kx = cos(deg2rad($lat));
        for ($i = 0; $i < $n - 1; $i++) {
            $ax = ((float)$points[$i][0] - $lon) * $kx;
            $ay = (float)$points[$i][1] - $lat;
            $bx = ((float)$points[$i + 1][0] - $lon) * $kx;
            $by = (float)$points[$i + 1][1] - $lat;
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $len2)) : 0.0;
            $px = (float)$points[$i][0] + ((float)$points[$i + 1][0] - (float)$points[$i][0]) * $t;
            $py = (float)$points[$i][1] + ((float)$points[$i + 1][1] - (float)$points[$i][1]) * $t;
            $best = min($best, $this->haversineDistance($lon, $lat, $px, $py));
        }
        return $best;
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
        if ($distanceMeters < 0) {
            return [];
        }
        $results = [];
        foreach ($this->spatialCandidates($dbname, $field, $this->circleToBBoxes($lon, $lat, $distanceMeters)) as $record) {
            $geometry = $record[$field] ?? null;
            if (!is_array($geometry) || !$this->validateGeoJSON($geometry)['valid']) {
                continue;
            }
            $distance = $this->distanceToGeometry($lon, $lat, $geometry);
            if ($distance <= $distanceMeters) {
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
        if ($minLat > $maxLat) {
            [$minLat, $maxLat] = [$maxLat, $minLat];
        }
        $boxes = $minLon <= $maxLon
            ? [[$minLon, $minLat, $maxLon, $maxLat]]
            : [[$minLon, $minLat, 180.0, $maxLat], [-180.0, $minLat, $maxLon, $maxLat]];

        $results = [];
        foreach ($this->spatialCandidates($dbname, $field, $boxes) as $record) {
            $geometry = $record[$field] ?? null;
            if (!is_array($geometry) || !$this->validateGeoJSON($geometry)['valid']) {
                continue;
            }
            $recordMBR = $this->calculateMBR($geometry);
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
        if ($k <= 0 || !$this->dbExists($dbname)) {
            return [];
        }

        $indexed = $this->spatialIndexEnabled && in_array($field, $this->getIndexManifest($dbname)['spatial'], true);
        $limit = $maxDistance ?? 20040000.0; // half the earth's circumference covers everything
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
                    if ($maxDistance === null || $distance <= $maxDistance) {
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

        $results = [];
        foreach ($this->spatialCandidates($dbname, $field, $boxes) as $record) {
            $geometry = $record[$field] ?? null;
            if (!is_array($geometry) || !$this->validateGeoJSON($geometry)['valid']) {
                continue;
            }
            foreach ($polygons as $coords) {
                if ($this->geometryWithinPolygon($geometry, $coords)) {
                    $results[] = $record;
                    break;
                }
            }
        }
        return $results;
    }

    /**
     * Check if a geometry lies entirely within a polygon: every vertex inside (not in a hole) and no
     * edge crossing the polygon's boundary
     */
    private function geometryWithinPolygon(array $geometry, array $polygonCoords): bool {
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];

        switch ($type) {
            case 'Point':
                return $this->pointInPolygon((float)$coords[0], (float)$coords[1], $polygonCoords);
            case 'MultiPoint':
                foreach ($coords as $point) {
                    if (!$this->pointInPolygon((float)$point[0], (float)$point[1], $polygonCoords)) {
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
                return $this->pathWithinPolygon($coords[0], $polygonCoords);
            case 'MultiPolygon':
                foreach ($coords as $poly) {
                    if (!$this->pathWithinPolygon($poly[0], $polygonCoords)) {
                        return false;
                    }
                }
                return true;
            case 'GeometryCollection':
                foreach ($geometry['geometries'] ?? [] as $g) {
                    if (!$this->geometryWithinPolygon($g, $polygonCoords)) {
                        return false;
                    }
                }
                return true;
        }
        return false;
    }

    /**
     * All vertices of a path inside the polygon and no segment crossing any of its rings
     */
    private function pathWithinPolygon(array $points, array $polygonCoords): bool {
        foreach ($points as $point) {
            if (!$this->pointInPolygon((float)$point[0], (float)$point[1], $polygonCoords)) {
                return false;
            }
        }
        $n = count($points);
        for ($i = 0; $i < $n - 1; $i++) {
            foreach ($polygonCoords as $ring) {
                $m = count($ring);
                for ($j = 0; $j < $m - 1; $j++) {
                    if ($this->segmentsCrossProperly($points[$i], $points[$i + 1], $ring[$j], $ring[$j + 1])) {
                        return false;
                    }
                }
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
        if(is_dir($this->dbDir)){
            foreach(scandir($this->dbDir) ?: [] as $file){
                if(preg_match("/^([0-9a-f]{20})-([A-Za-z0-9' -]+)(?:_s\\d+)?\\.nonedb\\.buffer(?:\\.flushing)?$/", $file, $m)
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
        return $this->dbFilesExist($dbname) ? $this->withLock($dbname, false, $fn) : $fn();
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
            if($shardId !== $lastShard && ($index === null || empty($index['o']))){
                $removed[] = $shardId;
            } else {
                $kept[] = $shard;
            }
        }
        if(empty($removed)){
            return;
        }
        $meta['shards'] = $kept;
        $this->writeMeta($dbname, $meta);

        $manifest = $this->getIndexManifest($dbname);
        foreach($removed as $shardId){
            $dataPath = $this->getShardPath($dbname, $shardId);
            foreach($manifest['fields'] as $field){
                $this->deleteFieldIndexFile($dbname, $field, $shardId);
            }
            $this->removeSpatialShardFiles($dbname, $shardId);
            @unlink($dataPath . '.jidx');
            @unlink($dataPath);
            $this->markFileNotExists($dataPath);
            unset($this->indexCache[$dataPath . '.jidx'], $this->jsonlFormatCache[$dataPath]);
        }
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
                return $operand;
            case '$like':
                return $this->likeRegex($operand);
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
        $numA = is_int($a) || is_float($a) || (is_string($a) && is_numeric($a));
        $numB = is_int($b) || is_float($b) || (is_string($b) && is_numeric($b));
        if ($numA && $numB) {
            return ($a + 0) <=> ($b + 0);
        }
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }
        return null;
    }

    /**
     * Regex for a like()/$like pattern ("^" start anchor, trailing "$" end anchor, rest literal)
     */
    private function likeRegex($pattern): string {
        if (!is_scalar($pattern)) {
            throw new \InvalidArgumentException('noneDB: like pattern must be a string');
        }
        return $this->callPrivateMethod('likePatternToRegex', (string)$pattern);
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
        $body = preg_replace('/(?<!\\\\)~/', '\\~', $pattern);
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
                return $hasField && in_array($fieldValue, $operand, true);
            case '$nin':
                return !$hasField || !in_array($fieldValue, $operand, true);
            case '$exists':
                return $operand ? $hasField : !$hasField;
            case '$like':
                if (!$hasField || !is_scalar($fieldValue) || is_bool($fieldValue)) {
                    return false;
                }
                return self::regexMatch($operand, (string)$fieldValue);
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
        foreach ($this->whereNotFilters as $group) {
            foreach ($group as $field => $value) {
                if (array_key_exists($field, $record) && $record[$field] === $value) {
                    return false;
                }
            }
        }

        foreach ($this->whereInFilters as $filter) {
            if (!array_key_exists($filter['field'], $record)) return false;
            if (!in_array($record[$filter['field']], $filter['values'], true)) return false;
        }

        foreach ($this->whereNotInFilters as $filter) {
            if (array_key_exists($filter['field'], $record) && in_array($record[$filter['field']], $filter['values'], true)) {
                return false;
            }
        }

        foreach ($this->likeFilters as $like) {
            $value = $record[$like['field']] ?? null;
            if (!is_scalar($value) || is_bool($value)) return false;
            if (!self::regexMatch($like['regex'], (string)$value)) return false;
        }

        foreach ($this->notLikeFilters as $notLike) {
            $value = $record[$notLike['field']] ?? null;
            if (is_scalar($value) && !is_bool($value) && self::regexMatch($notLike['regex'], (string)$value)) {
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
                if ($field === 'key' && empty($search['fields'])) continue; // the record key is not content
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
     * Case-insensitive substring test, multibyte aware when mbstring is available
     */
    private static function containsIgnoreCase(string $haystack, string $needle): bool {
        if (function_exists('mb_strtolower') && preg_match('//u', $haystack) === 1 && preg_match('//u', $needle) === 1) {
            return strpos(self::foldCase($haystack), self::foldCase($needle)) !== false;
        }
        return stripos($haystack, $needle) !== false;
    }

    /**
     * Lower-case for matching. Unicode lower-cases "İ" to "i" + combining dot (U+0307); the dot is
     * dropped so "İzmir", "IZMIR" and "izmir" all match each other.
     */
    private static function foldCase(string $s): string {
        return str_replace("\u{0307}", '', mb_strtolower($s, 'UTF-8'));
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
        $this->likeFilters[] = ['field' => $field, 'pattern' => $pattern, 'regex' => $this->likeRegex($pattern)];
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
        $this->whereInFilters[] = ['field' => $field, 'values' => array_values($values)];
        return $this;
    }

    /**
     * Filter where field value is NOT in given array (strict comparison)
     * @param string $field
     * @param array $values
     * @return self
     */
    public function whereNotIn(string $field, array $values): self {
        $this->whereNotInFilters[] = ['field' => $field, 'values' => array_values($values)];
        return $this;
    }

    /**
     * Exclude records where a field equals the value (strict comparison)
     * @param array $filters
     * @return self
     */
    public function whereNot(array $filters): self {
        if (!empty($filters)) {
            $this->whereNotFilters[] = $filters;
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
        $this->notLikeFilters[] = ['field' => $field, 'pattern' => $pattern, 'regex' => $this->likeRegex($pattern)];
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
     * Base records: pushes a single plain where() group down to find() so field indexes and key
     * lookups are used; otherwise all records.
     * @return array [records, bool whereAlreadyApplied]
     */
    private function baseRecords(): array {
        if (count($this->whereFilters) === 1 && empty($this->orWhereFilters)) {
            $group = $this->whereFilters[0];
            $plain = true;
            foreach ($group as $value) {
                if ($this->isOperatorSpec($value)) {
                    $plain = false;
                    break;
                }
            }
            if ($plain) {
                $records = $this->db->find($this->dbname, $group);
                return [$records === false ? [] : $records, true];
            }
        }
        $records = $this->db->find($this->dbname, 0);
        return [$records === false ? [] : $records, false];
    }

    /**
     * Filtered records (no join, grouping or projection)
     * @param bool $earlyExit Stop once offset+limit records are collected (only when order can't change)
     */
    private function filteredRecords(bool $earlyExit): array {
        // One shared lock for every read the query makes (count, spatial rounds, records), so they
        // all see the same state of the database
        $dbname = $this->callPrivateMethod('normalizeDbName', $this->dbname);
        return $this->callPrivateMethod('withReadLock', $dbname, function () use ($earlyExit) {
            return $this->filteredRecordsLocked($earlyExit);
        });
    }

    private function filteredRecordsLocked(bool $earlyExit): array {
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
        $target = ($earlyExit && $nearest === null && empty($this->sortKeys) && $this->limitCount !== null)
            ? $this->offsetCount + $this->limitCount
            : PHP_INT_MAX;
        // The usual case, one where() group: no need to go through the AND/OR logic per record
        $single = ($needWhere && count($where) === 1 && empty($orWhere)) ? $where[0] : null;

        while (true) {
            $boundary = null;
            $spatial = $this->spatialCandidates($nearestRadius, $boundary);
            if ($spatial !== null) {
                $records = array_values($spatial);
                $whereApplied = false;
            } else {
                [$records, $whereApplied] = $this->baseRecords();
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
            $record['_distance'] = round($this->callPrivateMethod('distanceToGeometry',
                (float)$this->distanceConfig['lon'], (float)$this->distanceConfig['lat'], $geometry), 4);
        }
        unset($record);
        return $records;
    }

    private function applyJoins(array $records): array {
        foreach ($this->joinConfigs as $join) {
            $foreignData = $this->db->find($join['foreignDb'], 0);
            if ($foreignData === false) {
                $foreignData = [];
            }
            $foreignIndexed = [];
            foreach ($foreignData as $fRecord) {
                $fk = $fRecord[$join['foreignKey']] ?? null;
                if (is_scalar($fk)) {
                    $foreignIndexed[$fk] = $fRecord;
                }
            }
            foreach ($records as &$record) {
                $local = $record[$join['localKey']] ?? null;
                $record[$join['alias']] = is_scalar($local) ? ($foreignIndexed[$local] ?? null) : null;
            }
            unset($record);
        }
        return $records;
    }

    private function applyGroupBy(array $records): array {
        $groups = [];
        foreach ($records as $record) {
            $value = $record[$this->groupByField] ?? null;
            $groupKey = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = ['_group' => $value, '_items' => [], '_count' => 0];
            }
            $groups[$groupKey]['_items'][] = $record;
            $groups[$groupKey]['_count']++;
        }

        foreach ($this->havingFilters as $having) {
            $groups = array_filter($groups, function ($group) use ($having) {
                if ($having['aggregate'] === 'count') {
                    $aggValue = $group['_count'];
                } elseif (!preg_match('/^(sum|avg|min|max):.+$/', $having['aggregate'])) {
                    return true; // unknown aggregate: ignored
                } else {
                    [$aggType, $aggField] = explode(':', $having['aggregate'], 2);
                    $values = [];
                    foreach ($group['_items'] as $item) {
                        if (isset($item[$aggField]) && is_numeric($item[$aggField]) && !is_bool($item[$aggField])) {
                            $values[] = $item[$aggField] + 0;
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
     * Execute query and get all results
     * @return array
     */
    public function get(): array {
        $canEarlyExit = empty($this->joinConfigs) && $this->groupByField === null && empty($this->sortKeys);
        $results = $this->filteredRecords($canEarlyExit);
        $results = $this->applyDistanceCalculation($results);
        $results = $this->applyJoins($results);
        if ($this->groupByField !== null) {
            $results = $this->applyGroupBy($results); // having() only applies to groups
        }
        $results = $this->applySort($results);
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $results = array_slice($results, $this->offsetCount, $this->limitCount);
        }
        return $this->applyProjection($results);
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
        $this->limitCount = 1;
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
        $results = $this->filteredRecords(true);
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $results = array_slice($results, $this->offsetCount, $this->limitCount);
        }
        return count($results);
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
        return $this->first() !== null;
    }

    // ==========================================
    // AGGREGATION METHODS
    // ==========================================

    /**
     * Numeric values of a field in the results (booleans and non-numeric values are ignored)
     */
    private function numericValues(string $field): array {
        $values = [];
        foreach ($this->get() as $record) {
            $value = $record[$field] ?? null;
            if ((is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
                $values[] = $value + 0;
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
        foreach ($this->get() as $record) {
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
        foreach ($this->get() as $record) {
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
        $values = [];
        foreach ($this->get() as $record) {
            if (isset($record[$field]) && !in_array($record[$field], $values, true)) {
                $values[] = $record[$field];
            }
        }
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
                return ['n' => 0, 'fields_removed' => $fieldsToRemove];
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
