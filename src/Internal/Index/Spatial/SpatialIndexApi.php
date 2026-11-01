<?php
// The public spatial index operations
namespace noneDB\Internal\Index\Spatial;

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
