<?php
// find(), insert(), update(), delete()
namespace noneDB\Internal\Core;

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
