<?php
// getMetrics(): the counters of the process and the metadata of a database (docs/OPERATIONS.md §1)
namespace noneDB\Internal\Diagnostics;

use function array_sum, array_values, count, filesize, in_array, is_dir, is_file, is_link, memory_get_peak_usage,
    memory_get_usage, preg_match, scandir, strlen, strncmp, substr;

/**
 * @internal
 */
final class Metrics {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $integrityChecks;
    private $lastIntegrity;
    private $lockRegistry;
    private $metrics;
    private $noWrites;
    private $buffer;
    private $fieldIndex;
    private $layout;
    private $locks;
    private $shardInfo;
    private $sync;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->buffer, $this->fieldIndex, $this->layout, $this->locks, $this->shardInfo, $this->sync);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->integrityChecks = &$this->rt->integrityChecks;
        $this->lastIntegrity = &$this->rt->lastIntegrity;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->metrics = &$this->rt->metrics;
        $this->noWrites = &$this->rt->noWrites;
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
        $this->fieldIndex = null;
        $this->layout = null;
        $this->locks = null;
        $this->shardInfo = null;
        $this->sync = null;
        $this->k = null;
        $this->rt = null;
    }

    /**
     * ['process' => ...] and, for a database, ['database' => ...]: nothing is scanned or written
     */
    public function getMetrics($dbname){
        $metrics = ['process' => $this->processMetrics()];
        if($dbname !== null){
            $metrics['database'] = $this->databaseMetrics($this->layout->normalizeDbName($dbname));
        }
        return $metrics;
    }

    private function processMetrics(){
        $m = $this->metrics;
        $durability = $this->sync->getDurability();
        return [
            'indexCache' => ['hits' => $m['hits'], 'misses' => $m['misses'], 'evictions' => $m['evictions'],
                'limit' => \noneDB\Internal\Cache\CacheManager::cacheLimitBytes()],
            'indexReads' => ['pages' => $m['pages'], 'bytes' => $m['bytes']],
            'checkpoints' => ['written' => $m['checkpoints'], 'seconds' => $m['checkpointSeconds']],
            'compactions' => ['count' => $m['compactions'], 'seconds' => $m['compactionSeconds']],
            'locks' => ['acquired' => $m['locks'], 'waited' => $m['lockWaits'], 'waitSeconds' => $m['lockWaitSeconds'], 'timeouts' => $m['lockTimeouts']],
            'recovery' => ['events' => $m['recoveryEvents'], 'actions' => $m['recoveryActions']],
            'durability' => ['mode' => $durability['mode'], 'lastOperation' => $durability['lastOperation'],
                'lastSyncError' => $durability['lastSyncError'], 'syncs' => $durability['syncs']],
            'integrity' => $this->lastIntegrity,
            'memory' => ['usage' => memory_get_usage(), 'peak' => memory_get_peak_usage()],
        ];
    }

    /**
     * A database from its metadata, under its shared lock, writing nothing (as checkIntegrity() reads)
     */
    private function databaseMetrics($dbname){
        $this->noWrites++;
        try {
            return $this->locks->withLock($dbname, false, function() use ($dbname){
                if(!$this->layout->dbPresent($dbname)){
                    return ['name' => $dbname, 'exists' => false]; // a missing database is answered without a lock
                }
                $info = $this->shardInfo->getShardInfoUnlocked($dbname);
                $flags = $this->lockRegistry[$this->locks->getLockPath($dbname)]['flags'];
                $buffered = $this->buffer->getBufferRecordCount($this->buffer->getBufferPath($dbname));
                foreach($this->buffer->flushingFiles($dbname) as $file){
                    $buffered += count($this->buffer->readBufferRecords($file));
                }
                $manifest = $this->fieldIndex->getIndexManifest($dbname);
                $databaseId = $this->layout->readFormatRecord($dbname)['databaseId'];
                return [
                    'name' => $dbname,
                    'exists' => true,
                    'databaseId' => $databaseId,
                    'generation' => $this->layout->generationNumber($dbname),
                    'layout' => $info['sharded'] ? 'sharded' : 'single',
                    'shardSize' => $info['shardSize'],
                    'segments' => $info['sharded'] ? $info['shards'] : 1,
                    'records' => $info['totalRecords'],
                    'nextKey' => $info['nextKey'],
                    'bufferedRecords' => $buffered,
                    'fieldIndexes' => array_values($manifest['fields']),
                    'spatialIndexes' => array_values($manifest['spatial']),
                    'bytes' => $this->databaseBytes($dbname),
                    'state' => ['buffered' => !empty($flags['b']),
                        'recoveryPending' => !empty($flags['j']) || !empty($flags['c']) || !empty($flags['d']) || !empty($flags['g'])],
                    'integrity' => $this->integrityChecks[$databaseId] ?? null,
                ];
            });
        } finally {
            $this->noWrites--;
        }
    }

    /**
     * Sizes of the files of a database directory by their class (docs/OPERATIONS.md §1.2)
     */
    private function databaseBytes($dbname){
        $home = $this->layout->dbHome($dbname);
        $active = 'generations/' . $this->layout->generationNumber($dbname) . '/';
        $bytes = ['data' => 0, 'indexes' => 0, 'buffer' => 0, 'metadata' => 0, 'recovery' => 0, 'other' => 0];
        $walk = function($dir, $prefix) use (&$walk, &$bytes, $active){
            foreach(@scandir($dir) ?: [] as $entry){
                if($entry === '.' || $entry === '..' || is_link($dir . $entry)){
                    continue;
                }
                if(is_dir($dir . $entry)){
                    $walk($dir . $entry . '/', $prefix . $entry . '/');
                    continue;
                }
                if(!is_file($dir . $entry)){
                    continue;
                }
                $file = $prefix . $entry;
                $inActive = strncmp($file, $active, strlen($active)) === 0;
                $relative = $inActive ? substr($file, strlen($active)) : null;
                if($inActive && preg_match('#^segments/[^/]+\.jsonl$#', $relative) === 1){
                    $class = 'data';
                } elseif($inActive && preg_match('#^(?:segments/[^/]+\.kix|indexes/[^/]+\.(?:fx|gfx)|spatial/[^/]+\.(?:rx|grx))$#', $relative) === 1){
                    $class = 'indexes';
                } elseif($inActive && strncmp($relative, 'buffer/', 7) === 0){
                    $class = 'buffer';
                } elseif(in_array($inActive ? $relative : $file, $inActive ? ['metadata', 'shards.json', 'indexes.json'] : ['FORMAT', 'CURRENT', 'LOCK'], true)){
                    $class = 'metadata';
                } elseif(strncmp($file, 'recovery/', 9) === 0){
                    $class = 'recovery';
                } else {
                    $class = 'other';
                }
                $bytes[$class] += (int)@filesize($dir . $entry);
            }
        };
        $walk($home, '');
        $walk = null; // the closure refers to itself: freed now, not by the garbage collector
        $bytes['total'] = array_sum($bytes);
        return $bytes;
    }
}
