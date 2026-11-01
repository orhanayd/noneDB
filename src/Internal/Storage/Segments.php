<?php
// Segments of a database: their files, existence and the key ranges of the shards
namespace noneDB\Internal\Storage;

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
    private $directoryListings; // @test
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
        $this->directoryListings = &$this->rt->directoryListings; // @test
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
        $this->directoryListings++; // @test
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
