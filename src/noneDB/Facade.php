<?php
// The public API: every call goes to the instance's kernel (src/Internal)
trait noneDB_Facade {
    /** This library's version */
    public const VERSION = '4.0.0-rc.6';
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
