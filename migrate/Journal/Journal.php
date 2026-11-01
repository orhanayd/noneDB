<?php
// The maintenance journal of a migration (STORAGE_FORMAT.md §9.1, docs/MIGRATION.md §7, §10)
namespace noneDB\Migrate\Journal;

use noneDB\Internal\Storage\StoreRecord;
use noneDB\Migrate\Files;
use noneDB\Migrate\Stop;

/**
 * @internal
 */
final class Journal {
    /** The states of a migration, in order */
    public const STATES = ['DISCOVERED', 'BACKED_UP', 'STAGING', 'VERIFIED', 'PUBLISHING', 'COMMITTED', 'FINAL_VERIFIED'];

    public static function path(string $dir): string {
        return rtrim($dir, '/') . '/.nonedb-maintenance/journal.json';
    }

    /**
     * The open journal, null when there is none
     * @throws Stop an unreadable journal: nothing is decided from it
     */
    public static function read(string $dir): ?array {
        $path = self::path($dir);
        clearstatcache(true, $path);
        if(!is_file($path)){
            return null;
        }
        $journal = StoreRecord::openRecord(@file_get_contents($path), 'journal');
        if($journal === null){
            throw new Stop('FAILED', "$path is unreadable: the state of the interrupted maintenance is unknown; nothing was changed");
        }
        return $journal;
    }

    /** Write the journal (atomic, synced) */
    public static function write(string $dir, array $journal): void {
        Files::makeDir(dirname(self::path($dir)));
        Files::writeAtomic(self::path($dir), StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'journal'] + $journal));
    }

    /** Close the journal: it moves to $to (the run's backup directory) */
    public static function close(string $dir, string $to): void {
        $path = self::path($dir);
        if(!@rename($path, $to)){
            if(!@copy($path, $to) || !@unlink($path)){
                throw new Stop('FAILED', "cannot close the journal $path");
            }
        }
        Files::syncDir(dirname($path));
    }
}
