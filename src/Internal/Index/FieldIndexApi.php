<?php
// The public field index operations
namespace noneDB\Internal\Index;

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
