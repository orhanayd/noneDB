<?php
// Settings of an instance: the .nonedb file, the config array, dev mode, the secret key
namespace noneDB\Internal\Config;

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
        return PHP_OS_FAMILY; // @prod
        return \noneDB\Internal\Cache\Runtime::get()->osFamily ?? PHP_OS_FAMILY; // @test
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
