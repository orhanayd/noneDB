<?php
// The recovery log of a database (docs/STORAGE_FORMAT.md §7.1)
namespace noneDB\Internal\Recovery;

use function array_merge, array_slice, clearstatcache, dirname, fclose, fgets, file_get_contents, filesize,
    fopen, is_array, is_dir, json_decode, json_encode, max, strpos, substr, time;
use const LOCK_EX;

/**
 * @internal
 */
final class RecoveryLog {
    /** @var \noneDB\Internal\Core\Kernel */
    private $k;
    /** @var \noneDB\Internal\Cache\Runtime */
    private $rt;

    private $lockRegistry;
    private $metrics;
    private $noWrites;
    private $pendingRecoveryEvents;
    private $io;
    private $layout;
    private $locks;

    public function __construct(\noneDB\Internal\Core\Kernel $k){
        $this->k = $k;
        $this->rt = $k->rt;
        unset($this->io, $this->layout, $this->locks);
    }

    /**
     * Bind what this service uses: the instance's values and the process-wide ones by reference, the
     * other services by handle (Kernel calls it once every service exists)
     */
    public function bind(): void {
        $this->lockRegistry = &$this->rt->lockRegistry;
        $this->metrics = &$this->rt->metrics;
        $this->noWrites = &$this->rt->noWrites;
        $this->pendingRecoveryEvents = &$this->rt->pendingRecoveryEvents;
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
        $this->io = null;
        $this->layout = null;
        $this->locks = null;
        $this->k = null;
        $this->rt = null;
    }


    private function getRecoveryLogPath($dbname){
        return $this->layout->dbHome($dbname) . 'recovery/log.jsonl';
    }

    /**
     * Append an entry to the database's recovery log (caller holds the exclusive lock). Best effort: a
     * failure to log never fails the operation. Past 1 MiB the log moves to log.1.jsonl (replacing it).
     */
    public function logRecoveryEvent($dbname, array $event, $dedupId = null){
        try {
            $path = $this->getRecoveryLogPath($dbname);
            if($dedupId !== null){
                $id = '"id":' . json_encode($dedupId);
                foreach([substr($path, 0, -6) . '.1.jsonl', $path] as $file){
                    $content = @file_get_contents($file);
                    // the id is followed by the next member or ends the entry
                    if($content !== false && (strpos($content, $id . ',') !== false || strpos($content, $id . '}') !== false)){
                        return; // already logged
                    }
                }
            }
            clearstatcache(true, $path);
            if(!is_dir(dirname($path))){
                $this->io->fsMkdir(dirname($path));
            }
            if(@filesize($path) > 1048576){
                $this->io->fsRename($path, substr($path, 0, -6) . '.1.jsonl');
            }
            $line = $this->io->encodeJson(['t' => time()] + $event);
            if($line !== false && $this->io->appendToFile($path, $line . "\n") !== false){
                $this->metrics['recoveryEvents']++;
                $this->metrics['recoveryActions'][$event['action']] = ($this->metrics['recoveryActions'][$event['action']] ?? 0) + 1;
            }
        } catch(\Throwable $ignored){
        }
    }

    /**
     * A recovery decision: logged at once under the exclusive lock, else kept in memory and logged by the
     * next exclusive session of this process (readers never write); getRecoveryLog() shows both
     */
    public function reportRecoveryEvent($dbname, array $event){
        $lock = $this->locks->getLockPath($dbname);
        if($this->noWrites === 0 && isset($this->lockRegistry[$lock]) && $this->lockRegistry[$lock]['mode'] === LOCK_EX){
            $this->logRecoveryEvent($dbname, $event, $event['id']);
        } else {
            $this->pendingRecoveryEvents[$lock][$event['id']] = ['t' => time()] + $event;
        }
    }

    public function getRecoveryLogEntries($dbname){
        $path = $this->getRecoveryLogPath($dbname);
        $entries = [];
        foreach([substr($path, 0, -6) . '.1.jsonl', $path] as $file){
            $fp = @fopen($file, 'rb');
            if($fp === false){
                continue;
            }
            while(($line = fgets($fp)) !== false){
                $entry = substr($line, -1) === "\n" ? json_decode($line, true) : null;
                if(is_array($entry)){
                    $entries[] = $entry;
                }
            }
            fclose($fp);
        }
        return $entries;
    }

    public function getRecoveryLog($dbname, $limit = 100){
        $dbname = $this->layout->normalizeDbName($dbname);
        $read = function() use ($dbname, $limit){
            // What readers of this process found and no write session logged yet: "persisted" => false
            $pending = [];
            foreach($this->pendingRecoveryEvents[$this->locks->getLockPath($dbname)] ?? [] as $event){
                $pending[] = $event + ['persisted' => false];
            }
            $limit = max(0, (int)$limit);
            return $limit === 0 ? [] : array_slice(array_merge($this->getRecoveryLogEntries($dbname), $pending), -$limit);
        };
        return $this->locks->withLock($dbname, false, $read);
    }
}
