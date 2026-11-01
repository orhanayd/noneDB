<?php
// The switch of a database to the sharded layout (a generation switch, docs/STORAGE_FORMAT.md §5.2)
namespace noneDB\Internal\Sharding;

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
                    $this->recovery->fault('switch:shard-written', ['db' => $dbname, 'shard' => $shardId]); // @test
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
