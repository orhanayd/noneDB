<?php
// Putting a run's old files back, and removing the retained ones (docs/MIGRATION.md §11, §12)
namespace noneDB\Migrate\Restore;

use noneDB\Internal\Storage\StoreRecord;
use noneDB\Migrate\Backup\Backup;
use noneDB\Migrate\Files;
use noneDB\Migrate\Journal\Journal;
use noneDB\Migrate\Stop;

/**
 * @internal
 */
final class Restore {
    /**
     * The directory as before the run $runId (§11): its noneDB 4 databases move into the backup, the key ($key, the
     * run's target key) leaves the STORE record, the old files come back byte for byte
     * @return array report
     * @throws Stop
     */
    public static function restore(string $dir, string $backupDir, string $runId, string $key): array {
        $dir = rtrim($dir, '/') . '/';
        $runDir = rtrim($backupDir, '/') . '/' . $runId;
        $manifest = Backup::verify($runDir);
        $done = StoreRecord::openRecord(@file_get_contents("$runDir/journal.done.json"), 'journal');
        if($done === null){
            throw new Stop('FAILED', "$runDir has no closed journal: the run did not complete, run the tool again (or --abort it)");
        }
        if(StoreRecord::keyCheckOf($key) !== $done['targetKeyCheck']){
            throw new Stop('FAILED', "the run $runId registered another key: restore it with that key (NONEDB_SECRET)");
        }
        $open = Journal::read($dir);
        if($open !== null && ($open['op'] !== 'restore-old' || $open['id'] !== $runId)){
            throw new Stop('MAINTENANCE_IN_PROGRESS', "another maintenance operation ({$open['op']}) is open in $dir");
        }
        $locks = self::lock($dir, $done['published']);
        // A database the key gained after the run would lose its key: refused, nothing changed
        foreach(glob($dir . '*.ndb4', GLOB_ONLYDIR) ?: [] as $home){
            $format = StoreRecord::openRecord(@file_get_contents("$home/FORMAT"), 'database');
            if($format !== null && !in_array(basename($home), $done['published'], true)
                && StoreRecord::locatorOf($format['name'], $key) . '.ndb4' === basename($home)){
                foreach($locks as $fp){
                    fclose($fp);
                }
                throw new Stop('FAILED', "the key has a database created after the run ({$format['name']}): restoring would make it unreachable; nothing was changed");
            }
        }
        Journal::write(rtrim($dir, '/'), ['op' => 'restore-old', 'id' => $runId, 'state' => 'RESTORING']);
        Files::makeDir("$runDir/v4", 0700);
        foreach($done['published'] as $locator){
            if(isset($locks[$dir . $locator . '/LOCK'])){ // Windows moves no directory with an open file in it
                flock($locks[$dir . $locator . '/LOCK'], LOCK_UN);
                fclose($locks[$dir . $locator . '/LOCK']);
                unset($locks[$dir . $locator . '/LOCK']);
            }
            if(is_dir($dir . $locator) && !@rename($dir . $locator, "$runDir/v4/$locator")){
                throw new Stop('MAINTENANCE_IN_PROGRESS', "cannot move $locator into the backup; run --restore again");
            }
        }
        self::removeKey($dir, $runDir, $done['targetKeyCheck']);
        $retained = $dir . '.nonedb-retained/' . $runId . '/files/';
        foreach($manifest['files'] as $entry){
            $name = $entry['name'];
            if(is_file($dir . $name) && hash_file('sha256', $dir . $name) === $entry['sha256']){
                continue;
            }
            if(is_file($retained . $name) && hash_file('sha256', $retained . $name) === $entry['sha256']){
                if(!@rename($retained . $name, $dir . $name)){
                    throw new Stop('MAINTENANCE_IN_PROGRESS', "cannot move $name back; run --restore again");
                }
            } elseif(Files::copy("$runDir/files/$name", $dir . $name) !== $entry['sha256']){
                throw new Stop('MAINTENANCE_IN_PROGRESS', "$name does not copy back byte for byte; run --restore again");
            }
        }
        Files::syncDir(rtrim($dir, '/'));
        if(is_dir($dir . '.nonedb-retained/' . $runId)){
            Files::removeTree($dir . '.nonedb-retained/' . $runId);
        }
        Journal::close(rtrim($dir, '/'), "$runDir/restore.done.json");
        if(!is_file($dir . 'STORE') && !is_file($dir . 'STORE.2')){
            // No store is left: the files the run made, and the store's directories when empty, go too
            foreach($done['created'] as $file){
                if(is_file($dir . $file)){
                    Files::removeTree($dir . $file);
                }
            }
            foreach(['.nonedb-maintenance', '.nonedb-retained'] as $sub){
                if(is_dir($dir . $sub) && (scandir($dir . $sub) ?: []) === ['.', '..']){
                    Files::removeTree($dir . $sub);
                }
            }
            Files::syncDir(rtrim($dir, '/'));
        }
        foreach($locks as $fp){
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return ['run' => $runId, 'restored' => count($manifest['files']), 'v4' => "$runDir/v4"];
    }

    /**
     * Remove .nonedb-retained/$runId after verifying every file against the backup's manifest (§12)
     * @throws Stop
     */
    public static function cleanup(string $dir, string $backupDir, string $runId): array {
        $dir = rtrim($dir, '/') . '/';
        $manifest = Backup::verify(rtrim($backupDir, '/') . '/' . $runId);
        $retained = $dir . '.nonedb-retained/' . $runId;
        if(!is_dir($retained)){
            return ['run' => $runId, 'removed' => 0];
        }
        $done = StoreRecord::openRecord(@file_get_contents(rtrim($backupDir, '/') . "/$runId/journal.done.json"), 'journal');
        if(($done['state'] ?? null) !== 'FINAL_VERIFIED'){
            throw new Stop('FAILED', "the run $runId has no final verification; run the tool again first (it verifies the run); nothing was removed");
        }
        foreach($manifest['files'] as $entry){
            $path = "$retained/files/" . $entry['name'];
            if(is_file($path) && hash_file('sha256', $path) !== $entry['sha256']){
                throw new Stop('FAILED', "$path differs from the backup; nothing was removed");
            }
        }
        Files::removeTree($retained);
        Files::syncDir($dir . '.nonedb-retained');
        return ['run' => $runId, 'removed' => count($manifest['files'])];
    }

    /** STORE.LOCK, then the LOCK of every database of the run, all non-blocking */
    private static function lock(string $dir, array $published): array {
        $locks = [];
        $paths = [$dir . 'STORE.LOCK'];
        foreach($published as $locator){
            if(is_file($dir . $locator . '/LOCK')){
                $paths[] = $dir . $locator . '/LOCK';
            }
        }
        foreach($paths as $path){
            $fp = Files::openLock($path);
            $busy = $fp !== false && !flock($fp, LOCK_EX | LOCK_NB);
            if($fp === false || $busy){
                if($fp !== false){
                    fclose($fp);
                }
                foreach($locks as $held){
                    fclose($held);
                }
                throw $busy ? new Stop('MAINTENANCE_BUSY', basename(dirname($path)) . '/' . basename($path) . ' is locked by another process; nothing was changed')
                    : new Stop('FAILED', "cannot open $path (permissions of the directory?); nothing was changed");
            }
            $locks[$path] = $fp;
        }
        return $locks;
    }

    /** The run's key leaves the STORE record; when no key is left, the STORE copies move into the backup too */
    private static function removeKey(string $dir, string $runDir, string $keyCheck): void {
        $store = StoreRecord::openRecord(@file_get_contents($dir . 'STORE'), 'store');
        if($store === null){
            return; // removed by an earlier attempt
        }
        $keys = array_values(array_diff($store['keys'], [$keyCheck]));
        if($keys === []){
            foreach(['STORE', 'STORE.2'] as $name){
                if(is_file($dir . $name) && !@rename($dir . $name, "$runDir/v4/$name")){
                    throw new Stop('MAINTENANCE_IN_PROGRESS', "cannot move $name into the backup; run --restore again");
                }
            }
            return;
        }
        if($keys !== $store['keys']){
            $bytes = StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'store', 'storageFormat' => $store['storageFormat'],
                'storeId' => $store['storeId'], 'seq' => $store['seq'] + 1, 'keys' => $keys]);
            Files::writeAtomic($dir . 'STORE.2', $bytes);
            Files::writeAtomic($dir . 'STORE', $bytes);
        }
    }
}
