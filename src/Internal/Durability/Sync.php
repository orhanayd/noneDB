<?php
// fsync of files and directories, the syncs of durable mode and the durability report (docs/DURABILITY.md)
namespace noneDB\Internal\Durability;

use function basename, clearstatcache, fclose,
    file_exists, fopen, fsync, is_array, microtime, rename, rtrim, stream_get_meta_data, strlen, strncmp, substr,
    time, usleep;
use const LOCK_EX, PHP_OS_FAMILY;

/**
 * @internal
 */
final class Sync {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $dbDir;
    private $durable;
    private $lockTimeout;
    private $lastOperation;
    private $lastSyncError;
    private $lockRegistry;
    private $renameFn; // @test
    private $syncFn; // @test
    private $syncStats;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->dbDir = &$this->k->dbDir;
        $this->durable = &$this->k->durable;
        $this->lockTimeout = &$this->k->lockTimeout;
        $this->lastOperation = &$this->rt->lastOperation;
        $this->lastSyncError = &$this->rt->lastSyncError;
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->renameFn = &$this->rt->renameFn; // @test
        $this->syncFn = &$this->rt->syncFn; // @test
        $this->syncStats = &$this->rt->syncStats;
    }

    /**
     * Drop the handles to the kernel and the other services (the instance is gone)
     */
    public function unbind(): void {
        $this->k = null;
        $this->rt = null;
    }


    /**
     * fsync() a file handle where the stream supports it (plain files). Never emits a warning: the result
     * goes to the durability report.
     * @return bool|null Null when it can't be done here
     */
    public function syncHandle($fp, $path = null){
        $meta = @stream_get_meta_data($fp);
        if(!is_array($meta) || ($meta['wrapper_type'] ?? '') !== 'plainfile'){
            return null;
        }
        $ok = @fsync($fp); // @prod
        $ok = $this->syncFn !== null ? (bool)($this->syncFn)($fp, 'file', $path) : @fsync($fp); // @test
        $this->countSync('file', $ok, $path);
        return $ok;
    }

    /**
     * fsync() a directory so a new, renamed or deleted entry in it survives a power loss (not possible on
     * Windows)
     * @return bool|null Null when it can't be done here
     */
    public function syncDirectory($dir){
        if(PHP_OS_FAMILY === 'Windows'){
            return null;
        }
        $dir = rtrim($dir, '/\\');
        $fp = @fopen($dir . '/', 'r');
        if($fp === false){
            $this->countSync('dir', false, $dir);
            return false;
        }
        try {
            $ok = @fsync($fp); // @prod
            $ok = $this->syncFn !== null ? (bool)($this->syncFn)($fp, 'dir', $dir) : @fsync($fp); // @test
            $this->countSync('dir', $ok, $dir);
            return $ok;
        } finally {
            fclose($fp);
        }
    }

    private function countSync($kind, $ok, $path){
        $this->syncStats[$kind]++;
        if(!$ok){
            $this->syncStats['failed']++;
            $this->lastSyncError = ['path' => $path === null ? null : $this->relative((string)$path), 'op' => $kind, 'time' => time()];
        }
    }

    /**
     * A path relative to dbDir (the report and messages never show where dbDir is)
     */
    private function relative($path){
        $dir = (string)$this->dbDir;
        if($path === rtrim($dir, '/')){
            return '.';
        }
        return strncmp($path, $dir, strlen($dir)) === 0 ? substr($path, strlen($dir)) : basename($path);
    }

    /**
     * A sync durable mode requires (DURABILITY.md §3), done in durable mode only. A failure throws:
     * DURABILITY_FAILED before the write's commit point, OUTCOME_UNKNOWN after it.
     * @throws \RuntimeException
     */
    public function requireSync($fp, $path, $kind = 'file', $afterCommit = false){
        if($this->durable && !$this->trySync($fp, $path, $kind)){
            throw $this->failure($kind, $path, $afterCommit);
        }
    }

    /**
     * A sync durable mode requires, for a caller that undoes its change before it throws. Performance mode:
     * true, nothing synced.
     */
    public function trySync($fp, $path, $kind = 'file'): bool {
        if(!$this->durable){
            return true;
        }
        $ok = ($kind === 'dir' ? $this->syncDirectory($path) : $this->syncHandle($fp, $path)) === true;
        $this->noteRequired($kind, $ok);
        return $ok;
    }

    /**
     * Record a sync durable mode required for the running write operation and whether it succeeded
     * (getDurability()['lastOperation'])
     */
    public function noteRequired($kind, bool $ok){
        foreach($this->lockRegistry as $lock => $entry){
            if($entry['mode'] === LOCK_EX && isset($entry['syncs'])){
                $this->lockRegistry[$lock]['syncs'][$kind] = ($entry['syncs'][$kind] ?? true) && $ok;
            }
        }
    }

    /**
     * Sync the undo of a change in durable mode (a cut back append): true when it is durable or nothing
     * needs to be (performance mode)
     */
    public function syncUndo($fp, $path): bool {
        return !$this->durable || $this->syncHandle($fp, $path) === true;
    }

    /**
     * The exception of a required sync that failed
     */
    public function failure($kind, $path, $afterCommit, ?\Throwable $previous = null): \RuntimeException {
        $what = ($kind === 'dir' ? 'the directory ' : '') . $this->relative((string)$path);
        if($afterCommit){
            return new \RuntimeException("noneDB: fsync of {$what} failed after the commit point: the outcome is unknown, read the state again (docs/DURABILITY.md §2)", \noneDB::OUTCOME_UNKNOWN, $previous);
        }
        return new \RuntimeException("noneDB: fsync of {$what} failed; the write was rolled back (docs/DURABILITY.md §2)", \noneDB::DURABILITY_FAILED, $previous);
    }

    /**
     * rename() that retries where it can fail for a moment: on Windows while another process has the target
     * open (5, 10, 20, 40, 80, 160 ms, within lockTimeout). The target is never deleted first.
     */
    public function renameWithRetry($from, $to){
        $fn = $this->renameFn; // @test
        $retry = PHP_OS_FAMILY === 'Windows'; // @prod
        $retry = PHP_OS_FAMILY === 'Windows' || $fn !== null; // @test
        $started = microtime(true);
        foreach([0, 5, 10, 20, 40, 80, 160] as $i => $delay){
            if($delay > 0){
                if(!$retry || ($this->lockTimeout > 0 && microtime(true) - $started + $delay / 1000 > $this->lockTimeout)){
                    return false;
                }
                usleep($delay * 1000);
                clearstatcache(true, $from);
                if(!file_exists($from)){
                    return false;
                }
            }
            if(@rename($from, $to)){ // @prod
            if($fn !== null ? $fn($from, $to) : @rename($from, $to)){ // @test
                return true;
            }
        }
        return false;
    }

    /**
     * Record how a write operation ended (getDurability()['lastOperation'])
     * @param array $syncs kind => true (every required sync of that kind succeeded) | false
     */
    public function finishOperation($op, $dbname, array $syncs, $outcome, $reason = null){
        $required = [];
        $completed = [];
        if($syncs){ // durable mode only: every write of performance mode ends here with none
            foreach(['file', 'dir'] as $kind){
                if(isset($syncs[$kind])){
                    $required[] = $kind;
                    if($syncs[$kind]){
                        $completed[] = $kind;
                    }
                }
            }
        }
        $this->lastOperation = ['op' => $op, 'db' => (string)$dbname, 'outcome' => $outcome,
            'required' => $required, 'completed' => $completed, 'reason' => $reason];
    }

    /**
     * Durability of this instance and of the last write operation of the process (docs/DURABILITY.md §6)
     * @return array
     */
    public function getDurability(): array {
        $os = \noneDB\Internal\Config\Configuration::osFamily();
        $note = \noneDB\Internal\Config\Configuration::platformNote($os);
        return [
            'mode' => $this->durable ? 'durable' : 'performance',
            'platform' => ['os' => $os, 'durableSupported' => \noneDB\Internal\Config\Configuration::durableSupported(),
                'notes' => $note === '' ? [] : ['durable mode is not supported here: ' . $note]],
            'lastOperation' => $this->lastOperation,
            'lastSyncError' => $this->lastSyncError,
            'syncs' => $this->syncStats,
        ];
    }
}
