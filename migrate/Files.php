<?php
// File operations of the tool: every write it relies on is flushed and synced, directories too
namespace noneDB\Migrate;

/**
 * @internal
 */
final class Files {
    /** The permissions of what the tool writes into a store: the engine's defaults (STORAGE_FORMAT.md §3) */
    public const FILE_MODE = 0640;
    public const DIR_MODE = 0750;

    /** The lock handles of the run by file name: on Windows a lock keeps every other handle from reading the file */
    private static $locked = [];

    /**
     * Write a file atomically: a temp file in the same directory, synced, renamed over the target, the directory
     * synced; $mode set on it whatever the umask
     * @throws Stop
     */
    public static function writeAtomic(string $path, string $bytes, int $mode = self::FILE_MODE): void {
        self::removeStaleTemps($path);
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        $fp = @fopen($tmp, 'xb');
        if($fp === false){
            throw new Stop('FAILED', "cannot create $tmp");
        }
        try {
            if(fwrite($fp, $bytes) !== strlen($bytes) || !fflush($fp) || !self::syncHandle($fp)){
                throw new Stop('FAILED', "cannot write $tmp");
            }
        } catch(Stop $e){
            fclose($fp);
            @unlink($tmp);
            throw $e;
        }
        fclose($fp);
        @chmod($tmp, $mode);
        self::renaming($path); // @test
        if(!@rename($tmp, $path)){
            @unlink($tmp);
            throw new Stop('FAILED', "cannot rename $tmp to $path");
        }
        self::syncDir(dirname($path));
    }

    /**
     * Remove the temps a crashed replacement of $path left ({name}.tmp{8 hex}, STORAGE_FORMAT.md §2: never read); the tool
     * writes only while it holds the store's locks, so no other writer is in the middle of one
     */
    public static function removeStaleTemps(string $path): void {
        $pattern = '/^' . preg_quote(basename($path), '/') . '\.tmp[0-9a-f]{8}$/';
        foreach(@scandir(dirname($path)) ?: [] as $entry){
            if(preg_match($pattern, $entry) === 1){
                @unlink(dirname($path) . '/' . $entry);
            }
        }
    }

    // @test-begin
    /** Test seam: NONEDB_MIGRATE_KILL "renaming:{file}#{n}" stops the process with SIGKILL before the n-th rename of {file} */
    private static function renaming(string $path): void {
        static $seen = [];
        $name = 'renaming:' . basename($path);
        $seen[$name] = ($seen[$name] ?? 0) + 1;
        if(getenv('NONEDB_MIGRATE_KILL') === $name . '#' . $seen[$name]){
            posix_kill(getmypid(), 9);
        }
    }
    // @test-end

    /**
     * Create a file that must not exist yet and write it (synced), for files written once; $mode set on it
     * @throws Stop
     */
    public static function writeNew(string $path, string $bytes, int $mode = self::FILE_MODE): void {
        $fp = @fopen($path, 'xb');
        if($fp !== false){
            @chmod($path, $mode);
        }
        if($fp === false || fwrite($fp, $bytes) !== strlen($bytes) || !fflush($fp) || !self::syncHandle($fp)){
            if($fp !== false){
                fclose($fp);
            }
            throw new Stop('FAILED', "cannot write $path");
        }
        fclose($fp);
    }

    /**
     * fsync of a handle: true when done; plain files only
     */
    public static function syncHandle($fp): bool {
        return @fsync($fp);
    }

    /**
     * fsync of a directory, so its new, renamed or removed entries survive a power loss (not possible on Windows)
     * @throws Stop
     */
    public static function syncDir(string $dir): void {
        if(PHP_OS_FAMILY === 'Windows'){
            return;
        }
        $fp = @fopen(rtrim($dir, '/') . '/', 'r');
        $ok = $fp !== false && @fsync($fp);
        if($fp !== false){
            fclose($fp);
        }
        if(!$ok){
            throw new Stop('FAILED', "cannot sync the directory $dir");
        }
    }

    /**
     * Create a directory and its missing parents, each with $mode whatever the umask
     * @throws Stop
     */
    public static function makeDir(string $dir, int $mode = self::DIR_MODE): void {
        $missing = [];
        for($at = rtrim($dir, '/'); $at !== '' && $at !== '.' && !is_dir($at); $at = dirname($at)){
            $missing[] = $at;
        }
        foreach(array_reverse($missing) as $new){
            if(!@mkdir($new, $mode) && !is_dir($new)){
                throw new Stop('FAILED', "cannot create the directory $new");
            }
            @chmod($new, $mode);
        }
    }

    /**
     * Open a lock file, created if missing (with FILE_MODE)
     * @return resource|false
     */
    public static function openLock(string $path) {
        $created = !is_file($path);
        $fp = @fopen($path, 'c+b');
        if($fp !== false && $created){
            @chmod($path, self::FILE_MODE);
        }
        return $fp;
    }

    /** The run has this file locked: from now on it is read through this handle */
    public static function keepLocked(string $path, $fp): void {
        self::$locked[basename($path)] = $fp;
    }

    /** The handle of a file the run has locked, at the file's start; null for any other file */
    private static function lockedHandle(string $path) {
        $fp = self::$locked[basename($path)] ?? null;
        if(!is_resource($fp)){
            return null;
        }
        rewind($fp);
        return $fp;
    }

    /** SHA-256 of a file */
    public static function sha256(string $path): string {
        $fp = self::lockedHandle($path);
        if($fp === null){
            return (string)@hash_file('sha256', $path);
        }
        $hash = hash_init('sha256');
        hash_update_stream($hash, $fp);
        return hash_final($hash);
    }

    /** The first bytes of a file, '' when it can't be read */
    public static function head(string $path, int $length): string {
        $fp = self::lockedHandle($path);
        return (string)($fp === null ? @file_get_contents($path, false, null, 0, $length) : fread($fp, $length));
    }

    /**
     * Copy a file, synced, and return the SHA-256 of what was copied. $mode: set on the copy (else the umask decides)
     * @throws Stop
     */
    public static function copy(string $from, string $to, ?int $mode = null): string {
        $held = self::lockedHandle($from);
        $in = $held ?? @fopen($from, 'rb');
        $out = $in === false ? false : @fopen($to, 'xb');
        if($in === false || $out === false){
            if($in !== false && $held === null){
                fclose($in);
            }
            throw new Stop('FAILED', "cannot copy $from to $to");
        }
        $hash = hash_init('sha256');
        $ok = true;
        while($ok && !feof($in)){
            $chunk = fread($in, 1048576);
            if($chunk === false){
                $ok = false;
                break;
            }
            hash_update($hash, $chunk);
            $ok = fwrite($out, $chunk) === strlen($chunk);
        }
        $ok = $ok && fflush($out) && self::syncHandle($out);
        if($held === null){
            fclose($in);
        }
        fclose($out);
        if(!$ok){
            throw new Stop('FAILED', "cannot copy $from to $to");
        }
        if($mode !== null){
            @chmod($to, $mode);
        }
        return hash_final($hash);
    }

    /**
     * Remove a file or directory tree
     * @throws Stop
     */
    public static function removeTree(string $path): void {
        if(is_link($path) || is_file($path)){
            if(!@unlink($path)){
                throw new Stop('FAILED', "cannot remove $path");
            }
            return;
        }
        if(!is_dir($path)){
            return;
        }
        foreach(scandir($path) ?: [] as $entry){
            if($entry !== '.' && $entry !== '..'){
                self::removeTree($path . '/' . $entry);
            }
        }
        if(!@rmdir($path)){
            throw new Stop('FAILED', "cannot remove $path");
        }
    }
}
