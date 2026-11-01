<?php
// Compaction of segments (in-generation data file replacement)
namespace noneDB\Internal\Maintenance;

use function array_keys, array_values, asort, basename, bin2hex, count, fclose, fgets, fopen, is_array, json_decode, ksort,
    microtime, random_bytes, strlen, substr, time;
use const SORT_NUMERIC;

/**
 * @internal
 */
final class Compaction {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $indexCache;
    private $autoCompactThreshold;
    private $verifiedIndexes;
    private $metrics;
    private $checkpoints;
    private $io;
    private $locks;
    private $recovery;
    private $recoveryLog;
    private $scope;
    private $segments;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->checkpoints, $this->io, $this->locks, $this->recovery, $this->recoveryLog, $this->scope, $this->segments);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->indexCache = &$this->k->indexCache;
        $this->autoCompactThreshold = &$this->k->autoCompactThreshold;
        $this->verifiedIndexes = &$this->k->verifiedIndexes;
        $this->metrics = &$this->rt->metrics;
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
        $this->checkpoints = null;
        $this->io = null;
        $this->locks = null;
        $this->recovery = null;
        $this->recoveryLog = null;
        $this->scope = null;
        $this->segments = null;
        $this->k = null;
        $this->rt = null;
    }


    /**
     * After a write that left old lines in a segment ($index: the segment's index as the write left it).
     * Compaction replaces the data file, so it runs after the write committed (see the rollback scope); whether it
     * is due is decided here, with the index in hand: a write over many segments doesn't load each evicted index
     * again only to find nothing due
     */
    public function deferCompaction($dbname, $shardId, array $index){
        if(!$this->compactionDue($index)){
            return;
        }
        $this->scope->runIndependent($dbname, 'compact:' . ($shardId === null ? 'main' : $shardId), function() use ($dbname, $shardId){
            $index = $this->checkpoints->readJsonlIndex($dbname, $shardId);
            if($index !== null && $this->compactionDue($index)){
                $this->compactJsonl($dbname, $shardId);
            }
        });
    }

    /** True once a segment's garbage (old versions, tombstones) exceeds the threshold share of its lines */
    private function compactionDue(array $index){
        $garbage = (int)$index['d'];
        return $garbage > 0 && $garbage > (\noneDB\Internal\Storage\KeyIndex::count($index) + $garbage) * $this->autoCompactThreshold;
    }

    /**
     * Compact a segment: rewrite live records (keys and order preserved), drop old versions and
     * tombstones. Crash-safe: the data file is replaced first; if the index rename doesn't happen,
     * the shorter data file is detected and the index rebuilt from it.
     * @param string $dbname
     * @param int|null $shardId
     * @return array ['compacted' => int, 'freed' => int]
     */
    public function compactJsonl($dbname, $shardId = null){
        return $this->locks->withLock($dbname, true, function() use ($dbname, $shardId){
            $started = microtime(true);
            $this->scope->refuseUncommittedFile($this->segments->segDataPath($dbname, $shardId), 'compaction');
            $this->recovery->removeStaleTemp($this->segments->segDataPath($dbname, $shardId)); // {seg}.jsonl[.kix].tmp{8 hex}
            $plan = null;
            for($attempt = 0; $plan === null; $attempt++){
                if($attempt > 1){
                    throw new \RuntimeException("noneDB: data file of database '{$dbname}' is corrupt and could not be repaired");
                }
                // A record the index points at must verify; if not, rebuild the index once and plan again
                $index = $attempt === 0 ? $this->checkpoints->writableSegmentIndex($dbname, $shardId) : $this->checkpoints->repairSegmentIndex($dbname, $shardId, true);
                if($index === null){
                    return ['compacted' => 0, 'freed' => 0];
                }
                $plan = $this->planCompaction($dbname, $shardId, $index);
            }
            $path = $this->segments->segDataPath($dbname, $shardId);
            $live = $plan['live'];
            unset($plan['live']); // held once: each record's bytes are released once they are in the buffer
            ksort($live, SORT_NUMERIC);

            $newIndex = $this->checkpoints->newJsonlIndex();
            $newIndex['created'] = $index['created'] ?? time();
            $newIndex['n'] = $index['n'];
            $buffer = '';
            $offset = 0;
            $maxKey = -1;
            // A fresh index of distinct keys: its arrays filled directly (KeyIndex::set() per key checks a body)
            foreach($live as $key => &$json){
                $newIndex['p'][$key] = $offset;
                $newIndex['l'][$key] = strlen($json);
                $buffer .= $json . "\n";
                $offset += strlen($json) + 1;
                $maxKey = $key;
                $json = null; // in the buffer now: the record's string is freed, the peak stays near one copy
            }
            unset($json);
            $newIndex['live'] = count($live);
            $live = null;
            // Keep the highest key ever used: an index rebuilt from this file must never hand it out again
            $highest = ($shardId === null ? 0 : $shardId * $this->segments->shardSizeOf($dbname)) + (int)$index['n'] - 1;
            if($maxKey < $highest){
                $buffer .= '{"__nonedb_deleted":' . $highest . "}\n";
                $newIndex['t'] = 1;
            }
            // Lines that don't decode are dropped from the data file: their bytes go to quarantine first
            $events = [];
            foreach($plan['bad'] as $region){
                $quarantine = $this->recovery->quarantineBytes($dbname, $shardId, $path, $region[0], $region[0] + $region[1]);
                $events[] = ['action' => 'drop-line', 'id' => $quarantine['id'], 'file' => basename($path),
                    'quarantine' => $quarantine['file'], 'bytesVerified' => true, 'powerLossDurable' => $quarantine['durable']];
            }

            // The checkpoint goes first: it must never be taken for the new data file
            $this->locks->bumpEpoch($dbname);
            $this->io->fsUnlink(\noneDB\Internal\Storage\Checkpoints::ckptPath($path));
            $this->recovery->fault('compact:replace', ['db' => $dbname, 'shard' => $shardId]); // @test
            try {
                $replaced = $this->io->writeFileAtomic($path, $buffer, true);
            } catch(\RuntimeException $e){
                throw new \RuntimeException("noneDB: compaction of database '{$dbname}' failed while writing data: " . $e->getMessage(), $e->getCode(), $e);
            }
            if(!$replaced){
                throw new \RuntimeException("noneDB: compaction of database '{$dbname}' failed while writing data");
            }
            $this->recovery->fault('compact:replaced', ['db' => $dbname, 'shard' => $shardId]); // @test
            $newIndex['s'] = strlen($buffer);
            $newIndex['e'] = bin2hex(random_bytes(4));
            $this->indexCache[\noneDB\Internal\Storage\Checkpoints::ckptPath($path)] = $newIndex;
            $this->verifiedIndexes[\noneDB\Internal\Storage\Checkpoints::ckptPath($path)] = true;
            $this->checkpoints->writeCheckpoint($dbname, $shardId, $newIndex);
            foreach($events as $event){
                $this->recoveryLog->logRecoveryEvent($dbname, $event);
            }
            $this->metrics['compactions']++;
            $this->metrics['compactionSeconds'] += microtime(true) - $started;
            return ['compacted' => $newIndex['live'], 'freed' => (int)$index['d']];
        }, 'compact');
    }

    /**
     * Read a segment line by line for compaction: the JSON of every live record (verified against its
     * key) and the byte ranges of lines that are neither records nor tombstones
     * @return array|null ['live' => [key => json], 'bad' => [[offset, length], ...]]; null if the index
     *                    doesn't match the data
     */
    private function planCompaction($dbname, $shardId, array $index){
        // Every live key's offset and length (a decoded index: its arrays as they are; no array per record)
        [$offsets, $lengths] = \noneDB\Internal\Storage\KeyIndex::locate($index, null);
        asort($offsets, SORT_NUMERIC); // offsets are distinct: file order (a sort without a callback)
        // File order as two lists, no array per record: where each live line starts, and its key
        $starts = array_values($offsets);
        $keys = array_keys($offsets);
        unset($offsets);
        $count = count($keys);
        // Keys of this segment: a line with another shard's key is damaged, never a superseded record
        $size = $shardId === null ? null : $this->segments->shardSizeOf($dbname);
        $base = $shardId === null ? 0 : $shardId * $size;
        $limit = $shardId === null ? null : $base + $size;
        $fp = @fopen($this->segments->segDataPath($dbname, $shardId), 'rb');
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
                $owned = []; // positions in the lists of the records this line holds
                while($next < $count && $starts[$next] < $end){
                    $owned[] = $next++;
                }
                if(substr($line, -1) !== "\n"){
                    if(!empty($owned)){
                        return null;
                    }
                    $bad[] = [$pos, $length]; // unterminated tail
                    break;
                }
                $json = substr($line, 0, -1);
                $whole = count($owned) === 1 && (int)$starts[$owned[0]] === $pos && (int)$lengths[$keys[$owned[0]]] === strlen($json);
                foreach($owned as $i){
                    $key = (int)$keys[$i];
                    $sub = $whole ? $json : substr($line, (int)$starts[$i] - $pos, (int)$lengths[$key]);
                    $record = json_decode($sub, true);
                    if(!is_array($record) || !isset($record['key']) || $record['key'] !== $key){
                        return null;
                    }
                    $live[$key] = $sub;
                }
                if(!$whole){
                    if(!empty($owned)){
                        $bad[] = [$pos, $length]; // a salvaged record inside other bytes: keep the line
                    } elseif($this->checkpoints->classifyLine($json, $base, $limit) === null){
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
}
