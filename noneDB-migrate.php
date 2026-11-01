<?php
/**
 * https://github.com/orhanayd/noneDB
 *
 * noneDB-migrate.php: converts the databases of earlier noneDB releases (1.4 to 3.3-dev) to noneDB 4.
 * Its contract is docs/MIGRATION.md. It loads noneDB.php from its own directory.
 *
 *   NONEDB_SECRET=... php noneDB-migrate.php --dir=./db --dry-run
 *   NONEDB_SECRET=... php noneDB-migrate.php --dir=./db --backup-dir=/var/backups/nonedb --confirm-stopped
 *
 * Built from migrate/ by tools/build.php; edit those files, not this one.
 */

namespace noneDB\Migrate {

/**
 * @internal
 */
final class Stop extends \RuntimeException {
    /** Exit status of each result (docs/MIGRATION.md §13) */
    public const STATUS = ['AMBIGUOUS_SOURCE' => 2, 'UNSUPPORTED_SOURCE' => 2, 'MAINTENANCE_BUSY' => 3,
        'MAINTENANCE_IN_PROGRESS' => 4, 'FAILED' => 5];

    /** @var string one of the keys of STATUS */
    public $result;

    public function __construct(string $result, string $message, ?\Throwable $previous = null){
        parent::__construct($message, 0, $previous);
        $this->result = $result;
    }
}
}
namespace noneDB\Migrate {

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
}
namespace noneDB\Migrate\Readers {

/**
 * @internal
 */
final class Discovery {
    /** Entries of a noneDB 4 store (STORAGE_FORMAT.md §3.4): never sources */
    private const STORE_ENTRY = '/^(STORE|STORE\.2|STORE\.LOCK|STORE(\.2)?\.tmp.*|[0-9a-f]{32}\.ndb4|\.nonedb-maintenance|\.nonedb-retained|\.htaccess|index\.html|\.gitkeep|\.gitignore|\.DS_Store)$/';

    /**
     * The entries of $dir for the source key $key:
     * - databases: name => its files (a data file, a meta, or a write buffer holding records);
     * - leftovers: name => files of the key that belong to no database;
     * - keyFiles: files of the directory that only this key uses (its 3.2+ hash cache);
     * - sharedFiles: the 3.0/3.1 hash cache, which no key owns;
     * - otherKeys: files of the old formats that belong to another key;
     * - foreign: anything else that is neither a source nor part of a store;
     * - store: entries of a noneDB 4 store.
     * @return array<string, array>
     */
    public static function scan(string $dir, string $key): array {
        $out = ['databases' => [], 'leftovers' => [], 'keyFiles' => [], 'sharedFiles' => [], 'otherKeys' => [], 'foreign' => [], 'store' => []];
        $named = [];
        $hashOnly = [];
        $fingerprint = substr(hash_hmac('sha256', 'nonedb-key-fingerprint-v1', $key), 0, 16);
        foreach(scandir($dir) ?: [] as $entry){
            if($entry === '.' || $entry === '..'){
                continue;
            }
            if(preg_match(self::STORE_ENTRY, $entry)){
                $out['store'][] = $entry;
            } elseif(preg_match('/^([0-9a-f]{20})-([^_.]*)(?:_s\d+)?\.nonedb/', $entry, $m)){
                $named[$m[2]][$m[1]][] = $entry;
            } elseif(preg_match('/^([0-9a-f]{20})(?:_s\d+)?\.nonedb\./', $entry, $m)){
                $hashOnly[$m[1]][] = $entry;
            } elseif($entry === '.nonedb_hash_cache'){
                $out['sharedFiles'][] = $entry;
            } elseif(preg_match('/^\.nonedb_hash_cache_([0-9a-f]{16})$/', $entry, $m)){
                $out[$m[1] === $fingerprint ? 'keyFiles' : 'otherKeys'][] = $entry;
            } else {
                $out['foreign'][] = $entry;
            }
        }
        $ownHashes = [];
        foreach($named as $name => $byHash){
            $hash = self::hashOf((string)$name, $key);
            foreach($byHash as $h => $files){
                if($h === $hash){
                    $ownHashes[$h] = (string)$name;
                    $out['databases'][(string)$name] = $files;
                } else {
                    array_push($out['otherKeys'], ...$files);
                }
            }
        }
        foreach($hashOnly as $h => $files){
            if(isset($ownHashes[$h])){
                array_push($out['databases'][$ownHashes[$h]], ...$files);
            } else {
                array_push($out['otherKeys'], ...$files);
            }
        }
        foreach($out['databases'] as $name => $files){
            sort($files);
            if(!self::isDatabase($dir, (string)$name, self::hashOf((string)$name, $key), $files)){
                unset($out['databases'][$name]);
                $out['leftovers'][$name] = $files;
            } else {
                $out['databases'][$name] = $files;
            }
        }
        ksort($out['databases'], SORT_STRING);
        ksort($out['leftovers'], SORT_STRING);
        foreach(['keyFiles', 'sharedFiles', 'otherKeys', 'foreign', 'store'] as $list){
            sort($out[$list]);
        }
        return $out;
    }

    /** The name hash of every release: PBKDF2-SHA256, the name as password, the key as salt, 20 hex digits */
    public static function hashOf(string $name, string $key): string {
        return hash_pbkdf2('sha256', $name, $key, 1000, 20);
    }

    /**
     * A database has a data file (its own or a shard's), a meta, or a write buffer holding a record
     */
    private static function isDatabase(string $dir, string $name, string $hash, array $files): bool {
        $base = $hash . '-' . $name;
        foreach($files as $file){
            if($file === "$base.nonedb" || $file === "$base.nonedb.meta" || preg_match('/^' . preg_quote($base, '/') . '_s\d+\.nonedb$/', $file)){
                return true;
            }
            if(preg_match('/\.nonedb\.buffer(\.flushing(\.\d+)?)?$/', $file) && trim((string)@file_get_contents($dir . '/' . $file)) !== ''){
                return true;
            }
        }
        return false;
    }
}
}
namespace noneDB\Migrate\Readers {

use noneDB\Migrate\Stop;

/**
 * @internal
 */
final class Source {
    /** @var string */
    public $name;
    /** @var string[] its files, names in the directory */
    public $files;
    /** @var string READY, AMBIGUOUS_SOURCE or UNSUPPORTED_SOURCE */
    public $status = 'READY';
    /** @var string[] why it is not READY */
    public $reasons = [];
    /** @var string[] segment id => kind (W, J30, J32, J33, EMPTY) */
    public $kinds = [];
    /** @var string single or sharded */
    public $layout = 'single';
    /** @var int */
    public $shardSize;
    /** @var int */
    public $nextKey = 0;
    /** @var int live records, buffered ones included */
    public $count = 0;
    /** @var int records imported from write buffers */
    public $buffered = 0;
    /** @var string[] indexed fields */
    public $fields = [];
    /** @var array spatial field => node size (null: the engine's default) */
    public $spatial = [];
    /** @var int[] what is not carried over: reason => lines or entries */
    public $notCarried = [];

    /** @var string */
    private $dir;
    /** @var string "{hash}-{name}" */
    private $base;
    /** @var string "{hash}" */
    private $hash;
    /** @var array options: rebuildFromData, flushing (null, import, skip), shardSize */
    private $options;
    /** @var bool the lock record has an epoch: 3.3-dev wrote the database */
    private $epoch = false;
    /** @var bool a 3.2+ lock file exists */
    private $locked = false;
    /**
     * @var array segment id => [kind, path, jidx (its index file), slots: next local key, count: live records]. The live
     * records themselves are read again per segment when written or verified (content()), so memory stays bounded by
     * the largest segment
     */
    private $segments = [];
    /** @var bool reading again (content()): what was checked and reported is neither checked nor reported twice */
    private $quiet = false;
    /** @var array [segment id, its content] of the last segment read again */
    private $content = [null, null];
    /** @var array key => record of buffered records, by segment id */
    private $bufferedRecords = [];

    /** Line encoding of the engine (STORAGE_FORMAT.md §2, §6.1) */
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Read the database $name of $dir from its files (as Discovery grouped them)
     * @param array $options rebuildFromData (bool), flushing (null|'import'|'skip'), shardSize (int, single layout)
     */
    public static function read(string $dir, string $name, string $key, array $files, array $options): self {
        $source = new self();
        $source->dir = rtrim($dir, '/') . '/';
        $source->name = $name;
        $source->files = $files;
        $source->hash = Discovery::hashOf($name, $key);
        $source->base = $source->hash . '-' . $name;
        $source->options = $options + ['rebuildFromData' => false, 'flushing' => null, 'shardSize' => 10000];
        try {
            $source->readAll();
        } catch(Stop $e){
            $source->status = $e->result;
            $source->reasons[] = $e->getMessage();
        }
        return $source;
    }

    private function readAll(): void {
        if(preg_match("/^[A-Za-z0-9' -]+$/D", $this->name) !== 1){
            throw new Stop('UNSUPPORTED_SOURCE', "the name '{$this->name}' is not a valid noneDB 4 database name");
        }
        $lock = $this->path('.nonedb.lock');
        if(is_file($lock)){
            $this->locked = true;
            $this->epoch = preg_match('/^\d+:[0-9a-f]{8}(?: |$)/', \noneDB\Migrate\Files::head($lock, 128)) === 1;
        }
        if(is_file($this->path('.nonedb.meta'))){
            $this->readSharded();
        } elseif(is_file($this->path('.nonedb'))){
            $this->shardSize = (int)$this->options['shardSize'];
            $this->summarize('main', $this->path('.nonedb'), $this->path('.nonedb.jidx'));
        } elseif(preg_grep('/^' . preg_quote($this->base, '/') . '_s\d+\.nonedb$/', $this->files)){
            throw new Stop('AMBIGUOUS_SOURCE', 'shard files without a meta: an interrupted drop or a lost meta');
        } else {
            $this->shardSize = (int)$this->options['shardSize'];
            $this->segments['main'] = ['kind' => 'EMPTY', 'path' => null, 'jidx' => null, 'slots' => 0, 'count' => 0];
        }
        foreach($this->segments as $id => $segment){
            $this->kinds[$id] = $segment['kind'];
            $this->count += $segment['count'];
        }
        $this->readBuffers();
        $this->readIndexList();
        $this->count += $this->buffered;
    }

    /** Read a segment once to check it and count it (§4.1, §4.2); its records are not kept */
    private function summarize($id, string $path, string $jidxPath): void {
        $segment = $this->readSegment($id, $path, $jidxPath);
        $this->segments[$id] = ['kind' => $segment['kind'], 'path' => $path, 'jidx' => $jidxPath, 'slots' => $segment['slots'],
            'count' => count($segment['live'] ?? $segment['records'])];
    }

    /**
     * A segment's live records, read again: key => [offset, length] of a JSONL line, or key => record (W); [] for a
     * segment that holds none
     */
    private function content($id): array {
        if($this->content[0] !== $id){
            $segment = $this->segments[$id] ?? null;
            $this->quiet = true;
            try {
                $read = $segment === null || $segment['kind'] === 'EMPTY' ? [] : $this->readSegment($id, $segment['path'], $segment['jidx'], $segment['kind']);
            } finally {
                $this->quiet = false;
            }
            $this->content = [$id, $read['live'] ?? $read['records'] ?? []];
        }
        return $this->content[1];
    }

    // ------------------------------------------------------------------ layout

    private function readSharded(): void {
        $this->layout = 'sharded';
        $meta = json_decode((string)@file_get_contents($this->path('.nonedb.meta')), true);
        if(!is_array($meta) || !is_array($meta['shards'] ?? null) || !is_int($meta['shardSize'] ?? null) || $meta['shardSize'] < 1){
            throw new Stop('AMBIGUOUS_SOURCE', 'the meta is unreadable');
        }
        if(($meta['version'] ?? null) !== 1){
            throw new Stop('UNSUPPORTED_SOURCE', 'the meta has a version no release wrote');
        }
        $this->shardSize = $meta['shardSize'];
        if(isset($meta['nextKey']) && is_int($meta['nextKey'])){
            $this->raiseNextKey($meta['nextKey']);
        }
        foreach($meta['shards'] as $shard){
            $id = $shard['id'] ?? null;
            if(!is_int($id) || $id < 0 || isset($this->segments[$id])){
                throw new Stop('AMBIGUOUS_SOURCE', 'the meta lists a shard id that is not a distinct non-negative integer');
            }
            $this->summarize($id, $this->path("_s$id.nonedb"), $this->path("_s$id.nonedb.jidx"));
        }
    }

    // ------------------------------------------------------------------ segments

    /**
     * A segment's live set by its kind (§4.1); shard keys checked against the shard size (§4.2). $decided: the kind a
     * first read decided (read again: the same rule, without deciding anew)
     */
    private function readSegment($id, string $path, string $jidxPath, ?string $decided = null): array {
        $base = $id === 'main' ? 0 : $id * $this->shardSize;
        if(!is_file($path) || filesize($path) === 0){
            $segment = ['kind' => 'EMPTY', 'path' => null, 'live' => [], 'slots' => 0];
            $index = is_file($jidxPath) ? json_decode((string)file_get_contents($jidxPath), true) : null;
            if(is_array($index) && is_int($index['n'] ?? null)){
                $segment['slots'] = $index['n'];
                $this->raiseNextKey($base + $index['n']);
            }
            return $segment;
        }
        $whole = $decided === null || $decided === 'W' ? $this->wholeJson($path) : null;
        if($whole !== null){
            return $this->readWhole($id, $whole);
        }
        $index = is_file($jidxPath) ? json_decode((string)file_get_contents($jidxPath), true) : null;
        if($decided !== null){
            $kind = $decided;
        } elseif(is_array($index) && is_array($index['p'] ?? null) && is_array($index['l'] ?? null) && isset($index['s'])){
            $kind = 'J33';
        } elseif(is_array($index) && is_array($index['o'] ?? null) && ($index['v'] ?? null) === 3){
            $kind = isset($index['s']) ? 'J32' : 'J30';
        } elseif($this->epoch){
            $kind = 'J33'; // 3.3-dev: the data file is the database, its checkpoints (written now and then) only a shortcut
        } elseif($this->options['rebuildFromData']){
            $kind = 'J33';
            $this->noteNotCarried('index replaced by the data file (--rebuild-from-data)', 1);
        } else {
            throw new Stop('AMBIGUOUS_SOURCE', basename($path) . ': ' . (is_file($jidxPath) ? 'its key index is unreadable' : 'its key index is missing')
                . ' (--rebuild-from-data replays the data file; records an older release deleted may come back)');
        }
        if($kind === 'J33'){
            $segment = $this->replay($id, $path);
        } else {
            try {
                $segment = $this->indexed($id, $path, $index, $kind);
            } catch(Stop $e){
                if(!$this->options['rebuildFromData']){
                    throw $e;
                }
                $kind = 'J33'; // an index that doesn't fit its data, replaced by a replay on request (§6.2)
                $this->noteNotCarried('index replaced by the data file (--rebuild-from-data)', 1);
                $segment = $this->replay($id, $path);
            }
        }
        if(is_array($index) && is_int($index['n'] ?? null)){
            // The index's counter: the next key of a single segment, the next local key of a shard
            $this->raiseNextKey($base + $index['n']);
            $segment['slots'] = max($segment['slots'], $index['n']);
        }
        if($id !== 'main' && $kind !== 'J33'){
            foreach($segment['live'] as $k => $where){
                if(intdiv($k, $this->shardSize) !== $id){
                    throw new Stop('AMBIGUOUS_SOURCE', "shard $id holds key $k, outside its range for the meta's shard size {$this->shardSize}");
                }
            }
        }
        $segment['kind'] = $kind;
        return $segment;
    }

    /** The decoded file when it is one JSON object whose only member is "data", an array; else null */
    private function wholeJson(string $path): ?array {
        $fp = fopen($path, 'rb');
        $head = (string)fread($fp, 16);
        fclose($fp);
        if(preg_match('/^\s*\{\s*"data"\s*:/', $head) !== 1){
            return null;
        }
        $json = json_decode((string)file_get_contents($path), true);
        return is_array($json) && array_keys($json) === ['data'] && is_array($json['data']) ? $json['data'] : null;
    }

    /** W: every array entry is a record keyed by its position; other entries only count for the next key */
    private function readWhole($id, array $data): array {
        $base = $id === 'main' ? 0 : $id * $this->shardSize;
        $records = [];
        $slots = 0;
        foreach($data as $i => $entry){
            if(!is_int($i) || $i < 0){
                throw new Stop('AMBIGUOUS_SOURCE', "segment $id: its data has a member that is not a position");
            }
            if($id !== 'main' && $i >= $this->shardSize){
                throw new Stop('AMBIGUOUS_SOURCE', "shard $id holds more entries than the meta's shard size {$this->shardSize}");
            }
            $slots = max($slots, $i + 1);
            if(is_array($entry)){
                $records[$base + $i] = $entry;
            } elseif($entry !== null){
                $this->noteNotCarried('an entry that is not a record', 1);
            }
        }
        ksort($records);
        $this->raiseNextKey($base + $slots);
        return ['kind' => 'W', 'path' => null, 'records' => $records, 'slots' => $slots];
    }

    /**
     * J30, J32: the index's map is the live set. Every entry must point at a line that decodes to its key; an entry of
     * length 0 is not live; J32 lines after s are not live.
     */
    private function indexed($id, string $path, array $index, string $kind): array {
        $size = filesize($path);
        $fp = fopen($path, 'rb');
        $end = $size;
        if($kind === 'J32'){
            $s = $index['s'];
            if(!is_int($s) || $s < 0 || $s > $size || ($s > 0 && $this->byteAt($fp, $s - 1) !== "\n")){
                fclose($fp);
                throw new Stop('AMBIGUOUS_SOURCE', basename($path) . ': its key index covers bytes the data file does not have'
                    . ' (--rebuild-from-data replays the data file)');
            }
            $end = $s;
        }
        $live = [];
        foreach($index['o'] as $k => $where){
            $k = (int)$k;
            if(!is_array($where) || count($where) !== 2 || !is_int($where[0]) || !is_int($where[1]) || $where[0] < 0 || $where[1] < 0){
                fclose($fp);
                throw new Stop('AMBIGUOUS_SOURCE', basename($path) . ": the key index entry of key $k is malformed");
            }
            if($where[1] === 0){
                $this->noteNotCarried('a key index entry of length 0', 1);
                continue;
            }
            if($this->quiet){
                $live[$k] = $where; // read again: checked when first read, and the files are unchanged (fingerprints)
                continue;
            }
            fseek($fp, $where[0]);
            $line = (string)fread($fp, $where[1]);
            $record = strlen($line) === $where[1] && $where[0] + $where[1] <= $end ? json_decode($line, true) : null;
            if(!is_array($record) || ($record['key'] ?? null) !== $k){
                fclose($fp);
                throw new Stop('AMBIGUOUS_SOURCE', basename($path) . ": the key index points key $k at bytes that are not its record"
                    . ' (--rebuild-from-data replays the data file)');
            }
            $live[$k] = $where;
        }
        fclose($fp);
        ksort($live);
        if($this->quiet){
            return ['path' => $path, 'live' => $live, 'slots' => 0];
        }
        $scan = $this->scanLines($path, $kind === 'J32' ? $end : null);
        $this->noteNotCarried('lines that are not live (deleted or superseded records, tombstones)', $scan['before'] - count($live));
        if($scan['after'] > 0){
            $this->noteNotCarried('complete lines after the key index (3.2 never showed them)', $scan['after']);
        }
        return ['path' => $path, 'live' => $live, 'slots' => 0];
    }

    /**
     * J33: the data file is the database. The last line of a key wins, a tombstone removes it; a line that does
     * not decode is salvaged (STORAGE_FORMAT.md §6.1); shard lines outside the shard's range are not live.
     */
    private function replay($id, string $path): array {
        $base = $id === 'main' ? 0 : $id * $this->shardSize;
        $live = [];
        $slots = 0;
        $fp = fopen($path, 'rb');
        $offset = 0;
        while(($line = fgets($fp)) !== false){
            $length = strlen($line);
            if($line[$length - 1] !== "\n"){
                $this->noteNotCarried('an unterminated last line', 1);
                break;
            }
            $line = substr($line, 0, -1);
            [$type, $k, $at] = $this->classify($line);
            if($type !== null){
                $this->raiseNextKey($k + 1);
                if($id !== 'main' && intdiv($k, $this->shardSize) !== $id){
                    $type = null;
                } else {
                    $slots = max($slots, $k - $base + 1);
                }
            }
            if($type !== null && $at > 0){
                $this->noteNotCarried('bytes glued before a record in its line (the record is salvaged)', 1);
            }
            if($type === 'record'){
                $live[$k] = [$offset + $at, $length - 1 - $at];
            } elseif($type === 'tombstone'){
                unset($live[$k]);
            } else {
                $this->noteNotCarried('lines that are neither a record nor a tombstone', 1);
            }
            $offset += $length;
        }
        fclose($fp);
        ksort($live);
        return ['path' => $path, 'live' => $live, 'slots' => $slots];
    }

    /**
     * [record|tombstone|null, key, offset of the JSON in the line]: a whole line, else the salvage rule: from right
     * to left, the first "{" from which a record or tombstone decodes to the line end
     */
    private function classify(string $line): array {
        $value = json_decode($line, true);
        if(is_array($value)){
            return $this->kindOf($value, 0);
        }
        for($at = strrpos($line, '{'), $tries = 0; $at !== false && $at > 0 && $tries < 4096; $tries++){
            $value = json_decode(substr($line, $at), true);
            if(is_array($value) && ($kind = $this->kindOf($value, $at))[0] !== null){
                return $kind;
            }
            $at = strrpos($line, '{', $at - strlen($line) - 1);
        }
        return [null, 0, 0];
    }

    private function kindOf(array $value, int $at): array {
        if(count($value) === 1 && is_int($value['__nonedb_deleted'] ?? null) && $value['__nonedb_deleted'] >= 0){
            return ['tombstone', $value['__nonedb_deleted'], $at];
        }
        if(is_int($value['key'] ?? null) && $value['key'] >= 0){
            return ['record', $value['key'], $at];
        }
        return [null, 0, 0];
    }

    /**
     * Every key in a JSONL data file raises the next key (records and tombstones, live or not). Returns the number
     * of complete lines before $end (the bytes a 3.2 index covers; null: all) and after it.
     */
    private function scanLines(string $path, ?int $end): array {
        $fp = fopen($path, 'rb');
        $counts = ['before' => 0, 'after' => 0];
        $offset = 0;
        while(($line = fgets($fp)) !== false){
            $length = strlen($line);
            if($line[$length - 1] !== "\n"){
                break;
            }
            [$type, $k] = $this->classify(substr($line, 0, -1));
            if($type !== null){
                $this->raiseNextKey($k + 1);
            }
            $counts[$end !== null && $offset >= $end ? 'after' : 'before']++;
            $offset += $length;
        }
        fclose($fp);
        return $counts;
    }

    private function byteAt($fp, int $offset): string {
        fseek($fp, $offset);
        return (string)fread($fp, 1);
    }

    // ------------------------------------------------------------------ buffers (§4.3)

    private function readBuffers(): void {
        $current = [];
        $flushingK = [];
        $plain = [];
        foreach($this->files as $file){
            if(!preg_match('/^' . preg_quote($this->base, '/') . '(?:_s(\d+))?\.nonedb\.buffer(\.flushing(?:\.(\d+))?)?$/', $file, $m)){
                continue;
            }
            $records = $this->bufferRecords($this->dir . $file);
            if($records === []){
                continue;
            }
            $shard = ($m[1] ?? '') === '' ? null : (int)$m[1];
            if(($m[2] ?? '') === ''){
                $current[$shard ?? 'main'] = $records;
            } elseif(($m[3] ?? '') === ''){
                $plain[] = [$file, $records];
            } else {
                $flushingK[(int)$m[3]] = [$file, $records];
            }
        }
        // 1. shard buffers of 2.3-3.1: after the shard's slots
        foreach($current as $shard => $records){
            if($shard === 'main'){
                continue;
            }
            if(!isset($this->segments[$shard])){
                throw new Stop('AMBIGUOUS_SOURCE', "a write buffer of shard $shard, which the meta does not list (no release flushed it)");
            }
            $next = $shard * $this->shardSize + $this->segments[$shard]['slots'];
            foreach($records as $record){
                $this->addBuffered($next++, $record);
            }
        }
        // 2. interrupted flushes of 3.2/3.3-dev: from K on, skipping keys already stored
        ksort($flushingK);
        foreach($flushingK as $k => [$file, $records]){
            foreach($records as $record){
                if(!$this->isLive($k)){
                    $this->addBuffered($k, $record);
                }
                $k++;
            }
        }
        // 3. the current buffer: from the next key
        if(isset($current['main'])){
            if($this->layout === 'sharded' && !$this->locked){
                throw new Stop('AMBIGUOUS_SOURCE', 'a write buffer next to a meta, which releases before 3.2 never flushed');
            }
            $next = $this->nextKey;
            foreach($current['main'] as $record){
                $this->addBuffered($next++, $record);
            }
        }
        // 4. interrupted flushes of 2.3-3.1: only on request, fresh keys after everything else
        if($plain !== []){
            if($this->options['flushing'] === null){
                throw new Stop('AMBIGUOUS_SOURCE', 'an interrupted flush of a release before 3.2 (' . implode(', ', array_column($plain, 0))
                    . '): its records may or may not be stored already (--flushing=import or --flushing=skip)');
            }
            foreach($plain as [$file, $records]){
                if($this->options['flushing'] === 'skip'){
                    $this->noteNotCarried('records of an interrupted flush (--flushing=skip)', count($records));
                    continue;
                }
                $next = $this->nextKey;
                foreach($records as $record){
                    $this->addBuffered($next++, $record);
                }
            }
        }
    }

    /** The records of a buffer file: lines that decode to an array (blank lines ignored, others reported) */
    private function bufferRecords(string $path): array {
        $records = [];
        foreach(explode("\n", (string)file_get_contents($path)) as $line){
            if(trim($line) === ''){
                continue;
            }
            $record = json_decode($line, true);
            if(is_array($record)){
                $records[] = $record;
            } else {
                $this->noteNotCarried('buffer lines that are not a record', 1);
            }
        }
        return $records;
    }

    private function addBuffered(int $key, array $record): void {
        unset($record['key']);
        $id = $this->layout === 'single' ? 'main' : intdiv($key, $this->shardSize);
        $this->bufferedRecords[$id][$key] = $record;
        $this->buffered++;
        $this->raiseNextKey($key + 1);
    }

    private function isLive(int $key): bool {
        $id = $this->layout === 'single' ? 'main' : intdiv($key, $this->shardSize);
        return isset($this->bufferedRecords[$id][$key]) || isset($this->content($id)[$key]);
    }

    // ------------------------------------------------------------------ indexes

    /** Indexed fields and spatial fields: the release's index list, else the headers of its index files */
    private function readIndexList(): void {
        $list = json_decode((string)@file_get_contents($this->path('.nonedb.indexes')), true);
        if(is_array($list) && is_array($list['fields'] ?? null) && is_array($list['spatial'] ?? null)){
            $this->fields = array_values(array_filter($list['fields'], 'is_string'));
            foreach($list['spatial'] as $field){
                if(is_string($field)){
                    $size = $list['spatialOptions'][$field]['nodeSize'] ?? null;
                    $this->spatial[$field] = is_int($size) ? $size : null;
                }
            }
        } else {
            foreach($this->files as $file){
                if(preg_match('/\.nonedb\.(fidx|gfidx|fx|gfx)\./', $file)){
                    $field = $this->headerField($this->dir . $file);
                    if($field !== null && !in_array($field, $this->fields, true)){
                        $this->fields[] = $field;
                    }
                } elseif(preg_match('/\.nonedb\.(sidx|gsidx|rx|grx)\./', $file)){
                    $header = $this->header($this->dir . $file);
                    if(is_string($header['field'] ?? null) && !array_key_exists($header['field'], $this->spatial)){
                        $size = $header['nodeSize'] ?? $header['ns'] ?? null;
                        $this->spatial[$header['field']] = is_int($size) ? $size : null;
                    }
                }
            }
        }
        sort($this->fields, SORT_STRING);
        ksort($this->spatial, SORT_STRING);
    }

    private function headerField(string $path): ?string {
        $header = $this->header($path);
        return is_string($header['field'] ?? null) ? $header['field'] : null;
    }

    /** An index file's header: its first line as JSON (3.1 files are JSON encoded twice) */
    private function header(string $path): array {
        $fp = @fopen($path, 'rb');
        $line = $fp === false ? false : fgets($fp);
        if($fp !== false){
            fclose($fp);
        }
        $value = $line === false ? null : json_decode($line, true);
        if(is_string($value)){
            $value = json_decode($value, true);
        }
        return is_array($value) ? $value : [];
    }

    // ------------------------------------------------------------------ output

    /**
     * The record lines of a segment in key order, without "\n": a JSONL line as stored when it ends with its key,
     * else the record encoded as the engine encodes one
     * @return \Generator key => line
     */
    public function lines($id): \Generator {
        $content = $this->content($id);
        $kind = $this->segments[$id]['kind'] ?? 'EMPTY';
        $buffered = $this->bufferedRecords[$id] ?? [];
        $keys = array_merge(array_keys($content), array_keys($buffered));
        sort($keys);
        $fp = $kind !== 'W' && $content !== [] ? fopen($this->segments[$id]['path'], 'rb') : null;
        try {
            foreach($keys as $k){
                if(isset($buffered[$k])){
                    yield $k => self::encode($buffered[$k], $k);
                } elseif($fp !== null){
                    [$offset, $length] = $content[$k];
                    fseek($fp, $offset);
                    $line = (string)fread($fp, $length);
                    yield $k => substr($line, -strlen(",\"key\":$k}")) === ",\"key\":$k}" || $line === "{\"key\":$k}"
                        ? $line : self::encode(json_decode($line, true), $k);
                } else {
                    yield $k => self::encode($content[$k], $k);
                }
            }
        } finally {
            if($fp !== null){
                fclose($fp);
            }
        }
    }

    /** Segment ids that hold records (sharded: shard ids; single: main) */
    public function segmentIds(): array {
        $ids = [];
        foreach($this->segments as $id => $segment){
            if($segment['count'] > 0){
                $ids[$id] = true;
            }
        }
        foreach($this->bufferedRecords as $id => $records){
            $ids[$id] = true;
        }
        $ids = array_keys($ids);
        sort($ids);
        return $ids;
    }

    /** A record as a data line: its fields, then "key" (STORAGE_FORMAT.md §6.1) */
    public static function encode(array $record, int $key): string {
        unset($record['key']);
        $record['key'] = $key;
        try {
            return json_encode($record, self::JSON | JSON_THROW_ON_ERROR);
        } catch(\JsonException $e){
            throw new Stop('FAILED', "record $key cannot be encoded: " . $e->getMessage());
        }
    }

    private function path(string $suffix): string {
        return $this->dir . $this->base . $suffix;
    }

    private function raiseNextKey(int $key): void {
        if($key > $this->nextKey){
            $this->nextKey = $key;
        }
    }

    private function noteNotCarried(string $reason, int $count): void {
        if($count > 0 && !$this->quiet){
            $this->notCarried[$reason] = ($this->notCarried[$reason] ?? 0) + $count;
        }
    }
}
}
namespace noneDB\Migrate\Converter {

use noneDB\Internal\Storage\KeyIndex;
use noneDB\Internal\Storage\StoreRecord;
use noneDB\Migrate\Files;
use noneDB\Migrate\Readers\Source;
use noneDB\Migrate\Stop;

/**
 * @internal
 */
final class Writer {
    /**
     * Write $source into the store directory $storeDir under $key: the state record, generation 1 (segments with
     * their key-index checkpoints, shards.json for the sharded layout, metadata), CURRENT, and FORMAT last
     * (STORAGE_FORMAT.md §8.1). The segments hold the live records in key order; the segment of the highest key ever
     * used ends with its tombstone when that key is not live (§6.2). The checkpoints are written as compaction writes
     * them (§6.2 step 4, §6.4), so the engine never replays a whole migrated segment. Everything is synced. Returns the
     * database's directory.
     */
    public static function write(string $storeDir, string $key, Source $source): string {
        $home = rtrim($storeDir, '/') . '/' . StoreRecord::locatorOf($source->name, $key) . '.ndb4/';
        $generation = $home . 'generations/1/';
        Files::makeDir($generation . 'segments');
        Files::writeNew($home . 'LOCK', str_pad('1:' . bin2hex(random_bytes(4)), 128));
        $sharded = $source->layout === 'sharded';
        $last = $source->nextKey - 1;
        $lastSegment = $last < 0 ? null : ($sharded ? intdiv($last, $source->shardSize) : 'main');
        $ids = $source->segmentIds();
        if(!$sharded && !in_array('main', $ids, true)){
            $ids[] = 'main'; // a single-layout generation always has its main segment
        }
        if($lastSegment !== null && !in_array($lastSegment, $ids, true)){
            $ids[] = $lastSegment;
            sort($ids);
        }
        $shards = [];
        foreach($ids as $id){
            $path = $generation . 'segments/' . ($id === 'main' ? 'main' : 's' . $id) . '.jsonl';
            $fp = @fopen($path, 'xb');
            if($fp === false){
                throw new Stop('FAILED', "cannot create $path");
            }
            @chmod($path, Files::FILE_MODE);
            $index = ['kb' => '', 'p' => [], 'l' => []]; // key => offset, key => length of its line
            $offset = 0;
            $tail = '';
            $max = -1;
            $chunk = '';
            foreach($source->lines($id) as $k => $line){
                $index['p'][$k] = $offset;
                $index['l'][$k] = strlen($line);
                $offset += strlen($line) + 1;
                $chunk .= $line . "\n";
                $max = $k;
                if(strlen($chunk) >= 1048576){
                    self::put($fp, $chunk, $path);
                    $tail = substr($chunk, -256);
                    $chunk = '';
                }
            }
            $tombstones = 0;
            if($id === $lastSegment && $max < $last){
                $chunk .= '{"__nonedb_deleted":' . $last . "}\n";
                $offset += strlen('{"__nonedb_deleted":' . $last . "}\n");
                $max = $last;
                $tombstones = 1;
            }
            self::put($fp, $chunk, $path);
            if(!fflush($fp) || !Files::syncHandle($fp)){
                fclose($fp);
                throw new Stop('FAILED', "cannot sync $path");
            }
            fclose($fp);
            $tail = substr($tail . $chunk, -256);
            $base = $id === 'main' ? 0 : $id * $source->shardSize;
            [$layout, $slots, $body, , $live] = KeyIndex::encodeBody($index, $base);
            $header = ['kind' => 'kix', 'v' => 1, 'layout' => $layout, 'base' => $base, 'slots' => $slots, 'live' => $live,
                'n' => $max < $base ? 0 : $max - $base + 1, 'd' => 0, 't' => $tombstones, 's' => $offset,
                'h' => sprintf('%08x', crc32($tail)), 'e' => bin2hex(random_bytes(4)), 'created' => time(), 'updated' => time()];
            if($id !== 'main'){
                $header['z'] = $source->shardSize;
                $header['sid'] = $id;
            }
            Files::writeNew(substr($path, 0, -strlen('.jsonl')) . '.kix', KeyIndex::encodeFile($header, $body));
            if($sharded){
                $shards[] = ['id' => $id, 'count' => count($index['p']), 'deleted' => 0];
            }
        }
        Files::syncDir($generation . 'segments');
        $identity = ['storageFormat' => \noneDB::STORAGE_FORMAT, 'databaseId' => StoreRecord::uuid4(), 'name' => $source->name,
            'shardSize' => $source->shardSize, 'createdAt' => time(), 'formatOptions' => new \stdClass()];
        if($sharded){
            Files::writeNew($generation . 'shards.json', json_encode(['kind' => 'shards', 'v' => 1, 'shardSize' => $source->shardSize,
                'nextKey' => $source->nextKey, 'totalRecords' => array_sum(array_column($shards, 'count')), 'deletedCount' => 0,
                'shards' => $shards], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
        Files::writeNew($generation . 'metadata', StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'generation'] + $identity
            + ['generation' => 1, 'layout' => $source->layout]));
        Files::syncDir($generation);
        Files::syncDir($home . 'generations');
        Files::writeNew($home . 'CURRENT', StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'current',
            'databaseId' => $identity['databaseId'], 'generation' => 1]));
        Files::writeNew($home . 'FORMAT', StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'database'] + $identity));
        Files::syncDir($home);
        Files::syncDir($storeDir);
        return $home;
    }

    private static function put($fp, string $bytes, string $path): void {
        if($bytes !== '' && fwrite($fp, $bytes) !== strlen($bytes)){
            fclose($fp);
            throw new Stop('FAILED', "cannot write $path");
        }
    }
}
}
namespace noneDB\Migrate\Validator {

use noneDB\Migrate\Readers\Source;
use noneDB\Migrate\Stop;

/**
 * @internal
 */
final class Verifier {
    /** Keys read per find() */
    private const BATCH = 1000;
    /** Values of each indexed field compared with a scan */
    private const SAMPLES = 50;

    /**
     * records(), then totals(); $scan reads without indexes
     * @throws Stop
     */
    public static function verify(\noneDB $db, \noneDB $scan, Source $source): void {
        self::totals($db, $scan, $source, self::records($db, $source));
    }

    /**
     * Every record (===), read in batches of keys: one operation per batch, so the engine reads one segment at a time
     * (and frees memory between operations). On the way, the keys that hold each of up to SAMPLES values of every
     * indexed field: what a scan answers for them, for totals().
     * @throws Stop
     */
    public static function records(\noneDB $db, Source $source): array {
        $samples = [];
        foreach($source->segmentIds() as $id){
            $batch = [];
            foreach($source->lines($id) as $key => $line){
                $batch[$key] = json_decode($line, true);
                if(count($batch) === self::BATCH){
                    self::compare($db, $source->name, $batch);
                    self::sample($batch, $source, $samples);
                    $batch = [];
                }
            }
            self::compare($db, $source->name, $batch);
            self::sample($batch, $source, $samples);
        }
        return $samples;
    }

    /**
     * checkIntegrity(), the count, the next key, the indexed lookups of the sampled values against the keys records()
     * found, every spatial index against a scan of the whole world, and an empty recovery log
     * @throws Stop
     */
    public static function totals(\noneDB $db, \noneDB $scan, Source $source, array $samples): void {
        $name = $source->name;
        $integrity = $db->checkIntegrity($name);
        if(($integrity['ok'] ?? false) !== true){
            throw new Stop('FAILED', "$name: checkIntegrity() reports a problem: " . json_encode($integrity));
        }
        if($db->count($name) !== $source->count){
            throw new Stop('FAILED', "$name: count() is " . $db->count($name) . ", {$source->count} records were migrated");
        }
        $next = ($db->getShardInfo($name) ?: [])['nextKey'] ?? null;
        if($next !== $source->nextKey){
            throw new Stop('FAILED', "$name: the next key is " . var_export($next, true) . ", expected {$source->nextKey}");
        }
        foreach($samples as $field => $byValue){
            foreach($byValue as [$value, $keys]){
                if(self::keys($db->find($name, [$field => $value])) !== $keys){
                    throw new Stop('FAILED', "$name: the index of '$field' answers " . json_encode($value) . ' unlike the records');
                }
            }
        }
        foreach(array_keys($source->spatial) as $field){
            if(self::keys($db->withinBBox($name, $field, -180, -90, 180, 90)) !== self::keys($scan->withinBBox($name, $field, -180, -90, 180, 90))){
                throw new Stop('FAILED', "$name: the spatial index of '$field' answers unlike a scan");
            }
        }
        if($db->getRecoveryLog($name) !== []){
            throw new Stop('FAILED', "$name: the engine logged a recovery event while reading it: " . json_encode($db->getRecoveryLog($name)));
        }
    }

    /** Each expected record (key => record) must be what find() returns for its key */
    private static function compare(\noneDB $db, string $name, array $expected): void {
        if($expected === []){
            return;
        }
        $found = [];
        foreach($db->find($name, ['key' => array_keys($expected)]) as $record){
            $found[$record['key']] = $record;
        }
        foreach($expected as $key => $record){
            if(!array_key_exists($key, $found) || $found[$key] !== $record){
                throw new Stop('FAILED', "$name: record $key differs: expected " . json_encode($record) . ', found '
                    . json_encode($found[$key] ?? null));
            }
        }
        if(count($found) !== count($expected)){
            throw new Stop('FAILED', "$name: find() returns keys that were not migrated");
        }
    }

    /**
     * field => [serialized value => [value, keys holding it (ascending)]]: the first SAMPLES values of each indexed field
     * met, and every key that holds one of them
     */
    private static function sample(array $records, Source $source, array &$samples): void {
        foreach($records as $key => $record){
            foreach($source->fields as $field){
                if(!array_key_exists($field, $record) || (!is_scalar($record[$field]) && $record[$field] !== null)){
                    continue;
                }
                $id = serialize($record[$field]);
                if(isset($samples[$field][$id])){
                    $samples[$field][$id][1][] = $key;
                } elseif(count($samples[$field] ?? []) < self::SAMPLES){
                    $samples[$field][$id] = [$record[$field], [$key]];
                }
            }
        }
    }

    private static function keys(array $records): array {
        $keys = array_column($records, 'key');
        sort($keys);
        return $keys;
    }
}
}
namespace noneDB\Migrate\Backup {

use noneDB\Internal\Storage\StoreRecord;
use noneDB\Migrate\Files;
use noneDB\Migrate\Stop;

/**
 * @internal
 */
final class Backup {
    /**
     * Copy $files (names in $dir) into {$runDir}/files/ (directories 0700, files 0600), write manifest.json last (a
     * sealed record: $info plus every file's name, size, mtime and SHA-256), then read the copy back and compare
     * @return array the manifest
     * @throws Stop
     */
    public static function create(string $dir, string $runDir, array $files, array $info): array {
        Files::makeDir($runDir . '/files', 0700);
        @chmod($runDir, 0700);
        $entries = [];
        foreach($files as $file){
            $target = "$runDir/files/$file";
            if(is_file($target)){
                @unlink($target); // left by an interrupted backup: copied again
            }
            $stat = stat("$dir/$file");
            $entries[] = ['name' => $file, 'size' => $stat['size'], 'mtime' => $stat['mtime'], 'sha256' => Files::copy("$dir/$file", $target, 0600)];
        }
        Files::syncDir("$runDir/files");
        $manifest = ['magic' => 'NONEDB4', 'kind' => 'migration-backup'] + $info + ['files' => $entries];
        Files::writeAtomic("$runDir/manifest.json", StoreRecord::sealRecord($manifest), 0600);
        return self::verify($runDir);
    }

    /**
     * The manifest of a backup whose checksum and every file's size and SHA-256 verify
     * @throws Stop
     */
    public static function verify(string $runDir): array {
        $manifest = StoreRecord::openRecord(@file_get_contents("$runDir/manifest.json"), 'migration-backup');
        if($manifest === null || !is_array($manifest['files'] ?? null)){
            throw new Stop('FAILED', "$runDir: no valid manifest.json; the backup is incomplete");
        }
        foreach($manifest['files'] as $entry){
            $path = "$runDir/files/" . $entry['name'];
            if(!is_file($path) || filesize($path) !== $entry['size'] || hash_file('sha256', $path) !== $entry['sha256']){
                throw new Stop('FAILED', "$runDir: {$entry['name']} does not match the manifest");
            }
        }
        return $manifest;
    }
}
}
namespace noneDB\Migrate\Journal {

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
}
namespace noneDB\Migrate\Publisher {

use noneDB\Internal\Storage\StoreRecord;
use noneDB\Migrate\Backup\Backup;
use noneDB\Migrate\Converter\Writer;
use noneDB\Migrate\Files;
use noneDB\Migrate\Journal\Journal;
use noneDB\Migrate\Readers\Discovery;
use noneDB\Migrate\Readers\Source;
use noneDB\Migrate\Stop;
use noneDB\Migrate\Validator\Verifier;

/**
 * @internal
 */
final class Migration {
    /** @var string the directory, with a trailing slash */
    private $dir;
    /** @var string */
    private $sourceKey;
    /** @var string */
    private $targetKey;
    /** @var array cli options */
    private $options;
    /** @var resource[] held until the run ends */
    private $locks = [];

    private function __construct(string $dir, string $sourceKey, string $targetKey, array $options){
        $this->dir = rtrim($dir, '/') . '/';
        $this->sourceKey = $sourceKey;
        $this->targetKey = $targetKey;
        $this->options = $options + ['db' => null, 'rebuildFromData' => false, 'flushing' => null, 'shardSize' => 10000, 'verify' => false];
    }

    /**
     * The pre-check (§7.1): every database of the source key read and judged, nothing written
     * @return array the report
     */
    public static function check(string $dir, string $sourceKey, string $targetKey, array $options): array {
        return (new self($dir, $sourceKey, $targetKey, $options))->precheck()['report'];
    }

    /**
     * A migration, or the continuation of an interrupted one (§10)
     * @return array the report
     * @throws Stop
     */
    public static function run(string $dir, string $sourceKey, string $targetKey, array $options): array {
        $run = new self($dir, $sourceKey, $targetKey, $options);
        $journal = Journal::read($run->dir);
        if($journal !== null){
            $run->own($journal);
            return $run->resume($journal);
        }
        if(($closed = $run->unverified()) !== null){
            $run->lock([]);
            return $run->finish($closed);
        }
        $check = $run->precheck();
        if($check['report']['result'] !== 'READY'){
            throw new Stop($check['report']['result'], 'the pre-check found databases that cannot be migrated: ' . json_encode($check['report']));
        }
        if($check['targets'] === [] && $check['files'] === []){
            // No old file of the key: only a key a partial run left unregistered, with databases here, is registered
            $registered = in_array(StoreRecord::keyCheckOf($targetKey), $run->readStore()['keys'] ?? [], true);
            if($registered || !$run->hasDatabasesOf($targetKey)){
                return ['run' => null, 'databases' => [], 'keyRegistered' => $registered];
            }
        }
        $run->checkSpace($check['report']['bytes']);
        // Files of the store the run makes where there were none: a restore removes them again (§11). A STORE.LOCK without
        // a STORE record is one an earlier run stopped before its journal left (noneDB 4 creates none next to old files)
        $noStore = !file_exists($run->dir . 'STORE') && !file_exists($run->dir . 'STORE.2');
        $created = array_values(array_filter(['STORE.LOCK', '.htaccess', 'index.html'], function($file) use ($run, $noStore){
            return !file_exists($run->dir . $file) || ($file === 'STORE.LOCK' && $noStore);
        }));
        $run->lock(array_keys($check['targets']));
        $runId = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $journal = ['op' => 'migrate', 'id' => $runId, 'state' => 'DISCOVERED', 'targets' => array_keys($check['targets']),
            'completesKey' => $check['report']['completesKey'], 'sourceKeyCheck' => StoreRecord::keyCheckOf($sourceKey),
            'targetKeyCheck' => StoreRecord::keyCheckOf($targetKey), 'backup' => rtrim($run->options['backupDir'], '/') . '/' . $runId,
            'options' => array_intersect_key($run->options, array_flip(['rebuildFromData', 'flushing', 'shardSize', 'verify'])),
            'files' => $run->fingerprints($check['files']), 'created' => $created, 'records' => []];
        $run->write($journal);
        return $run->resume($journal);
    }

    /**
     * Roll back an interrupted run that has not committed (§10)
     * @return array ['run' => id, 'state' => the state it was in]
     * @throws Stop
     */
    public static function abort(string $dir): array {
        \noneDB::clearStaticCache(); // staging goes: Windows removes no file that is open
        $run = new self($dir, '', '', []);
        $journal = Journal::read($run->dir);
        if($journal === null || ($journal['op'] ?? null) !== 'migrate'){
            throw new Stop('FAILED', 'no interrupted migration to abort');
        }
        $run->lock([]);
        $index = array_search($journal['state'], Journal::STATES, true);
        if($index === false || $index >= array_search('COMMITTED', Journal::STATES, true)){
            throw new Stop('FAILED', "the run {$journal['id']} is {$journal['state']}: committed runs are completed, not aborted; run the tool again");
        }
        if($journal['state'] === 'PUBLISHING'){
            foreach($journal['published'] ?? [] as $locator){
                if(is_dir($run->dir . $locator)){
                    Files::removeTree($run->dir . $locator); // still carries m: never visible, the old files are untouched
                }
            }
            Files::syncDir($run->dir);
        }
        Files::removeTree($run->staging());
        Files::removeTree(Journal::path($run->dir));
        Files::removeStaleTemps(Journal::path($run->dir));
        Files::syncDir(dirname(Journal::path($run->dir)));
        return ['run' => $journal['id'], 'state' => $journal['state']];
    }

    // ------------------------------------------------------------------ pre-check

    /** ['report' => array, 'targets' => name => Source, 'files' => the files the run moves] */
    private function precheck(): array {
        if(!is_dir($this->dir)){
            throw new Stop('FAILED', "{$this->dir} is not a directory");
        }
        try {
            new \noneDB(['secretKey' => $this->targetKey, 'dbDir' => $this->staging()]);
        } catch(\InvalidArgumentException $e){
            throw new Stop('FAILED', 'noneDB 4 refuses the key or the configuration: ' . $e->getMessage(), $e);
        }
        $scan = Discovery::scan($this->dir, $this->sourceKey);
        $readOptions = array_intersect_key($this->options, array_flip(['rebuildFromData', 'flushing', 'shardSize']));
        $names = $this->options['db'] ?? array_keys($scan['databases']);
        foreach($names as $name){
            if(!isset($scan['databases'][$name])){
                throw new Stop('FAILED', "no database named '$name' with this key in {$this->dir}");
            }
        }
        $report = ['dir' => $this->dir, 'result' => 'READY', 'databases' => [], 'leftovers' => $scan['leftovers'],
            'otherKeys' => $scan['otherKeys'], 'foreign' => $scan['foreign'], 'completesKey' => false, 'bytes' => 0];
        $targets = [];
        foreach($scan['databases'] as $name => $files){
            $source = Source::read($this->dir, (string)$name, $this->sourceKey, $files, $readOptions);
            if($source->status === 'READY' && is_dir($this->dir . StoreRecord::locatorOf((string)$name, $this->targetKey) . '.ndb4')){
                $source->status = 'AMBIGUOUS_SOURCE';
                $source->reasons[] = 'TARGET_EXISTS: a noneDB 4 database of this name and key already exists; nothing is merged or overwritten';
            }
            $target = in_array($name, $names, true);
            $report['databases'][$name] = self::describe($source) + ['target' => $target];
            if($target){
                $targets[$name] = $source;
                if($source->status !== 'READY' && $report['result'] === 'READY'){
                    $report['result'] = $source->status;
                }
            }
        }
        // The files the run moves, by database ('': leftovers and files of the directory)
        $files = [];
        foreach($targets as $name => $source){
            $files[$name] = $source->files;
        }
        $report['completesKey'] = count($targets) === count($scan['databases']) && $report['result'] === 'READY';
        if($report['completesKey']){
            $own = array_merge($scan['keyFiles'], ...array_values($scan['leftovers']));
            if($scan['otherKeys'] === []){
                $own = array_merge($own, $scan['sharedFiles']); // the 3.0/3.1 hash cache, once no old file of any key is left
            }
            if($own !== []){
                sort($own);
                $files[''] = $own;
            }
        }
        foreach($files as $group){
            foreach($group as $file){
                $report['bytes'] += filesize($this->dir . $file);
            }
        }
        $store = $this->readStore();
        $report['store'] = $store === null ? 'none' : 'valid';
        return ['report' => $report, 'targets' => $targets, 'files' => $files];
    }

    /** True when the directory holds noneDB 4 databases whose directory names are this key's locators */
    private function hasDatabasesOf(string $key): bool {
        foreach(glob($this->dir . '*.ndb4', GLOB_ONLYDIR) ?: [] as $home){
            $format = StoreRecord::openRecord(@file_get_contents("$home/FORMAT"), 'database');
            if($format !== null && StoreRecord::locatorOf($format['name'], $key) . '.ndb4' === basename($home)){
                return true;
            }
        }
        return false;
    }

    /**
     * The backup and the staged databases take about the size of the old files each (§7.1); on one file system,
     * the sum
     * @throws Stop
     */
    private function checkSpace(int $bytes): void {
        $backup = rtrim($this->options['backupDir'], '/');
        while(!is_dir($backup) && dirname($backup) !== $backup){
            $backup = dirname($backup);
        }
        $same = @stat($backup)['dev'] === @stat($this->dir)['dev'];
        foreach(array_unique([$this->dir, $backup]) as $path){
            $free = @disk_free_space($path);
            $need = $same ? 2 * $bytes : $bytes;
            if($free !== false && $free < $need){
                throw new Stop('FAILED', "not enough free space in $path: $need bytes are needed, " . (int)$free . ' are free');
            }
        }
    }

    /** The report of one database */
    private static function describe(Source $source): array {
        return ['status' => $source->status, 'reasons' => $source->reasons, 'layout' => $source->layout, 'shardSize' => $source->shardSize,
            'segments' => $source->kinds, 'records' => $source->count, 'buffered' => $source->buffered, 'nextKey' => $source->nextKey,
            'fields' => $source->fields, 'spatial' => array_keys($source->spatial), 'notCarried' => $source->notCarried];
    }

    // ------------------------------------------------------------------ the run

    /** Continue the run from its journal's state */
    private function resume(array $journal): array {
        $this->lock($journal['targets']);
        $state = $journal['state'];
        if(in_array($state, ['DISCOVERED', 'BACKED_UP', 'STAGING', 'VERIFIED', 'PUBLISHING'], true)){
            $this->verifyFingerprints($journal);
            if($state === 'DISCOVERED'){
                $journal = $this->backup($journal);
                $state = 'BACKED_UP';
            } elseif($state !== 'PUBLISHING'){
                Backup::verify($journal['backup']);
            }
            if($state === 'BACKED_UP' || $state === 'STAGING' || $state === 'VERIFIED'){
                $journal = $this->stage($journal);
            }
            $journal = $this->publish($journal);
            $journal = $this->commit($journal);
        }
        return $this->finish($this->apply($journal));
    }

    private function backup(array $journal): array {
        $store = $this->readStore();
        Backup::create(rtrim($this->dir, '/'), $journal['backup'], self::names($journal['files']), ['run' => $journal['id'],
            'dir' => realpath($this->dir), 'created' => time(), 'sourceKeyCheck' => $journal['sourceKeyCheck'],
            'databases' => $journal['targets'], 'store' => $store]);
        $journal['state'] = 'BACKED_UP';
        $this->write($journal);
        return $journal;
    }

    /** Build every target in a staging store and verify it with the engine (§7.4, §7.5) */
    private function stage(array $journal): array {
        $journal['state'] = 'STAGING';
        $this->write($journal);
        $staging = $this->staging();
        Files::removeTree($staging);
        Files::makeDir($staging);
        $this->writeStore(rtrim($staging, '/'), ['storeId' => StoreRecord::uuid4(), 'seq' => 1, 'keys' => [StoreRecord::keyCheckOf($this->targetKey)]]);
        $sources = $this->sources($journal);
        foreach($sources as $source){
            Writer::write($staging, $this->targetKey, $source);
        }
        $db = new \noneDB(['secretKey' => $this->targetKey, 'dbDir' => $staging, 'autoCreateDB' => false]);
        $scan = new \noneDB(['secretKey' => $this->targetKey, 'dbDir' => $staging, 'autoCreateDB' => false, 'fieldIndexEnabled' => false, 'spatialIndexEnabled' => false]);
        $journal['records'] = [];
        foreach($sources as $name => $source){
            // Records first: the engine writes each segment's checkpoint at its first read, one segment at a time,
            // before the indexes read every segment in one operation
            $samples = Verifier::records($db, $source);
            foreach($source->fields as $field){
                $result = $db->createFieldIndex($name, $field);
                if(empty($result['success'])){
                    throw new Stop('FAILED', "$name: the field index of '$field' cannot be built: " . json_encode($result));
                }
            }
            foreach($source->spatial as $field => $nodeSize){
                $result = $db->createSpatialIndex($name, $field, $nodeSize === null ? [] : ['nodeSize' => $nodeSize]);
                if(empty($result['success'])){
                    throw new Stop('FAILED', "$name: the spatial index of '$field' cannot be built: " . json_encode($result));
                }
            }
            Verifier::totals($db, $scan, $source, $samples);
            $journal['records'][$name] = $source->count;
        }
        unset($db, $scan);
        $journal['state'] = 'VERIFIED';
        $this->write($journal);
        return $journal;
    }

    /** The targets read again from their files (verified unchanged) */
    private function sources(array $journal): array {
        $sources = [];
        foreach($journal['targets'] as $name){
            $source = Source::read($this->dir, $name, $this->sourceKey, array_keys($journal['files'][$name]), $journal['options']);
            if($source->status !== 'READY'){
                throw new Stop($source->status, "$name: " . implode('; ', $source->reasons));
            }
            $sources[$name] = $source;
        }
        return $sources;
    }

    /** Move every staged database into the directory, each carrying m (§7.6) */
    private function publish(array $journal): array {
        if($journal['state'] !== 'PUBLISHING'){
            $journal['state'] = 'PUBLISHING';
            $journal['published'] = array_map(function($name){
                return StoreRecord::locatorOf($name, $this->targetKey) . '.ndb4';
            }, $journal['targets']);
            $this->write($journal);
        }
        \noneDB::clearStaticCache(); // the engine's lock handles: Windows moves no directory with an open file in it
        foreach($journal['published'] as $locator){
            $staged = $this->staging() . $locator;
            if(is_dir($this->dir . $locator) && !is_dir($staged)){
                continue; // published before the interruption
            }
            if(!is_dir($staged) || is_dir($this->dir . $locator)){
                throw new Stop('FAILED', "$locator is neither only staged nor only published: the run's state contradicts its files; nothing was changed");
            }
            $this->verifyFingerprints($journal, $journal['targets'][array_search($locator, $journal['published'], true)]);
            self::writeState($staged . '/LOCK', ['m']);
            if(!@rename($staged, $this->dir . $locator)){
                throw new Stop('FAILED', "cannot move $locator into {$this->dir}");
            }
            Files::syncDir(rtrim($this->dir, '/'));
            Files::syncDir($this->staging());
        }
        return $journal;
    }

    /** The commit point: the journal records COMMITTED with the target STORE record (§7.7) */
    private function commit(array $journal): array {
        $this->verifyFingerprints($journal);
        $store = $this->readStore();
        $keyCheck = StoreRecord::keyCheckOf($this->targetKey);
        if($store === null){
            $store = ['storeId' => StoreRecord::uuid4(), 'seq' => 1, 'keys' => $journal['completesKey'] ? [$keyCheck] : []];
        } elseif($journal['completesKey'] && !in_array($keyCheck, $store['keys'], true)){
            $store['keys'][] = $keyCheck;
            sort($store['keys']);
            $store['seq']++;
        }
        $journal['state'] = 'COMMITTED';
        $journal['store'] = $store;
        $this->write($journal);
        return $journal;
    }

    /** After the commit, each step idempotent: STORE copies, retained sources, m cleared, staging removed (§7.7) */
    private function apply(array $journal): array {
        $this->writeStore(rtrim($this->dir, '/'), $journal['store']);
        foreach(['.htaccess' => "Require all denied\nDeny from all\n", 'index.html' => ''] as $file => $content){
            if(!file_exists($this->dir . $file)){
                Files::writeNew($this->dir . $file, $content); // no temp file: one would be a foreign entry of the store
            }
        }
        $retained = $this->dir . '.nonedb-retained/' . $journal['id'];
        Files::makeDir($retained . '/files');
        foreach(self::prints($journal['files']) as $file => $fingerprint){
            if(is_file($this->dir . $file)){
                $this->unlock($this->dir . $file);
                if(!@rename($this->dir . $file, "$retained/files/$file")){
                    throw new Stop('MAINTENANCE_IN_PROGRESS', "committed, not completed: cannot move $file into $retained; run again");
                }
            } elseif(!is_file("$retained/files/$file")){
                throw new Stop('FAILED', "$file is neither in the directory nor retained: the run's state contradicts its files");
            }
        }
        Files::syncDir("$retained/files");
        Files::syncDir(rtrim($this->dir, '/'));
        $entries = [];
        foreach(self::prints($journal['files']) as $file => $fingerprint){
            $entries[] = ['name' => $file, 'size' => $fingerprint[0], 'sha256' => $fingerprint[3]];
        }
        Files::writeAtomic("$retained/manifest.json", StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'migration-retained',
            'run' => $journal['id'], 'backup' => $journal['backup'], 'files' => $entries]));
        foreach($journal['published'] as $locator){
            if(is_file($this->dir . $locator . '/LOCK')){
                self::writeState($this->dir . $locator . '/LOCK', []);
            }
        }
        Files::removeTree($this->staging());
        // Closed before the final check: the engine opens no store while a maintenance journal is open
        Journal::close(rtrim($this->dir, '/'), $journal['backup'] . '/journal.done.json');
        return $journal;
    }

    /**
     * The closed journal of a run of these keys in this directory that has no final verification (a crash after the
     * close, §10), found through the retained files' manifest; null when there is none
     */
    private function unverified(): ?array {
        foreach(glob($this->dir . '.nonedb-retained/*/manifest.json') ?: [] as $path){
            $retained = StoreRecord::openRecord(@file_get_contents($path), 'migration-retained');
            $done = $retained === null ? null : StoreRecord::openRecord(@file_get_contents($retained['backup'] . '/journal.done.json'), 'journal');
            if($done !== null && $done['op'] === 'migrate' && $done['state'] === 'COMMITTED'
                && $done['sourceKeyCheck'] === StoreRecord::keyCheckOf($this->sourceKey) && $done['targetKeyCheck'] === StoreRecord::keyCheckOf($this->targetKey)){
                return $done;
            }
        }
        return null;
    }

    /**
     * Final check (§7.8) with a new engine instance: checkIntegrity() and count() of every migrated database, and with
     * --verify every record against the live set read from the backup. Recorded as FINAL_VERIFIED in the closed journal.
     */
    private function finish(array $journal): array {
        $report = ['run' => $journal['id'], 'backup' => $journal['backup'], 'databases' => $journal['records'],
            'keyRegistered' => $journal['completesKey'], 'retained' => '.nonedb-retained/' . $journal['id']];
        if($journal['completesKey']){
            \noneDB::clearStaticCache();
            $db = new \noneDB(['secretKey' => $this->targetKey, 'dbDir' => $this->dir, 'autoCreateDB' => false]);
            foreach($journal['records'] as $name => $count){
                if(($db->checkIntegrity($name)['ok'] ?? false) !== true || $db->count($name) !== $count){
                    throw new Stop('FAILED', "$name: the published database does not read back as verified; the backup is in {$journal['backup']}");
                }
            }
            if($journal['options']['verify']){
                $scan = new \noneDB(['secretKey' => $this->targetKey, 'dbDir' => $this->dir, 'autoCreateDB' => false, 'fieldIndexEnabled' => false, 'spatialIndexEnabled' => false]);
                $backup = Discovery::scan($journal['backup'] . '/files', $this->sourceKey);
                foreach($journal['targets'] as $name){
                    Verifier::verify($db, $scan, Source::read($journal['backup'] . '/files', $name, $this->sourceKey,
                        $backup['databases'][$name], $journal['options']));
                }
            }
            unset($db, $scan);
        }
        // The report first: a closed journal that records FINAL_VERIFIED always has it next to it
        Files::writeAtomic($journal['backup'] . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
        $journal['state'] = 'FINAL_VERIFIED';
        Files::writeAtomic($journal['backup'] . '/journal.done.json', StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'journal'] + $journal), 0600);
        return $report;
    }

    // ------------------------------------------------------------------ store, locks, fingerprints

    /**
     * STORE.LOCK, then the lock file of every target that has one (3.2, 3.3-dev), all non-blocking (§7.2)
     * @throws Stop MAINTENANCE_BUSY, nothing written
     */
    private function lock(array $targets): void {
        if($this->locks !== []){
            return;
        }
        $paths = [$this->dir . 'STORE.LOCK'];
        foreach($targets as $name){
            $path = $this->dir . Discovery::hashOf($name, $this->sourceKey) . "-$name.nonedb.lock";
            if(is_file($path)){
                $paths[] = $path;
            }
        }
        foreach($paths as $path){
            $fp = Files::openLock($path);
            $busy = $fp !== false && !flock($fp, LOCK_EX | LOCK_NB);
            if($fp === false || $busy){
                if($fp !== false){
                    fclose($fp);
                }
                foreach($this->locks as $held){
                    flock($held, LOCK_UN);
                    fclose($held);
                }
                $this->locks = [];
                throw $busy ? new Stop('MAINTENANCE_BUSY', basename($path) . ' is locked by another process; nothing was changed')
                    : new Stop('FAILED', "cannot open $path (permissions of the directory?); nothing was changed");
            }
            $this->locks[$path] = $fp;
            Files::keepLocked($path, $fp);
        }
    }

    /** The run belongs to these keys: a journal of another source or target key is never resumed */
    private function own(array $journal): void {
        if(($journal['op'] ?? null) !== 'migrate'){
            throw new Stop('MAINTENANCE_IN_PROGRESS', "another maintenance operation ({$journal['op']}) is open in {$this->dir}");
        }
        if($journal['sourceKeyCheck'] !== StoreRecord::keyCheckOf($this->sourceKey) || $journal['targetKeyCheck'] !== StoreRecord::keyCheckOf($this->targetKey)){
            throw new Stop('MAINTENANCE_IN_PROGRESS', "the interrupted run {$journal['id']} was started with other keys; run it again with them");
        }
    }

    /** group => file => [size, mtime, inode, sha256] */
    private function fingerprints(array $groups): array {
        $prints = [];
        foreach($groups as $group => $files){
            $prints[$group] = [];
            foreach($files as $file){
                $prints[$group][$file] = $this->fingerprint($file);
            }
        }
        return $prints;
    }

    /** A file of the run leaves the directory: its lock goes first */
    private function unlock(string $path): void {
        if(isset($this->locks[$path])){
            flock($this->locks[$path], LOCK_UN);
            fclose($this->locks[$path]);
            unset($this->locks[$path]);
        }
    }

    private function fingerprint(string $file): ?array {
        clearstatcache(true, $this->dir . $file);
        $stat = @stat($this->dir . $file);
        return $stat === false ? null : [$stat['size'], $stat['mtime'], $stat['ino'], Files::sha256($this->dir . $file)];
    }

    /** Every file of the groups: file => fingerprint */
    private static function prints(array $groups): array {
        return array_merge(...array_values($groups));
    }

    private static function names(array $groups): array {
        $names = array_keys(self::prints($groups));
        sort($names);
        return $names;
    }

    /**
     * The source files (of one database, or all) as the journal recorded them (§7.6); a change rolls the run back
     * @throws Stop AMBIGUOUS_SOURCE
     */
    private function verifyFingerprints(array $journal, ?string $group = null): void {
        foreach($group === null ? self::prints($journal['files']) : $journal['files'][$group] as $file => $print){
            if($this->fingerprint($file) !== $print){
                foreach($this->locks as $held){
                    flock($held, LOCK_UN);
                    fclose($held);
                }
                $this->locks = [];
                self::abort(rtrim($this->dir, '/'));
                throw new Stop('AMBIGUOUS_SOURCE', "$file changed during the run; the run was rolled back and nothing was migrated");
            }
        }
    }

    /**
     * The STORE record of the directory, null when there is none
     * @throws Stop a store whose copies are not valid and identical
     */
    private function readStore(): ?array {
        $copies = [];
        foreach(['STORE', 'STORE.2'] as $name){
            $path = $this->dir . $name;
            if(is_file($path)){
                $copies[$name] = StoreRecord::openRecord(@file_get_contents($path), 'store');
            }
        }
        if($copies === []){
            return null;
        }
        if(count($copies) !== 2 || in_array(null, $copies, true) || $copies['STORE'] !== $copies['STORE.2']){
            throw new Stop('FAILED', "the STORE record of {$this->dir} is not two valid identical copies: open the store with noneDB once (it repairs one damaged copy) or use repairStore()");
        }
        $store = $copies['STORE'];
        return ['storeId' => $store['storeId'], 'seq' => $store['seq'], 'keys' => $store['keys']];
    }

    /** Both STORE copies (STORE.2, then STORE), each written only when it differs (STORAGE_FORMAT.md §3.1) */
    private function writeStore(string $dir, array $store): void {
        $bytes = StoreRecord::sealRecord(['magic' => 'NONEDB4', 'kind' => 'store', 'storageFormat' => \noneDB::STORAGE_FORMAT] + $store);
        foreach(['STORE.2', 'STORE'] as $name){
            if(@file_get_contents("$dir/$name") !== $bytes){
                Files::writeAtomic("$dir/$name", $bytes);
            }
        }
    }

    /** A database's state record with a new epoch and $flags (STORAGE_FORMAT.md §4.2), synced */
    private static function writeState(string $lockPath, array $flags): void {
        $record = (string)@file_get_contents($lockPath, false, null, 0, 128);
        $gen = preg_match('/^(\d+):/', $record, $m) ? (int)$m[1] : 1;
        $fp = @fopen($lockPath, 'c+');
        $state = str_pad(implode(' ', array_merge([$gen . ':' . bin2hex(random_bytes(4))], $flags)), 128);
        if($fp === false || fwrite($fp, $state) !== 128 || !fflush($fp) || !Files::syncHandle($fp)){
            throw new Stop('FAILED', "cannot write the state record of $lockPath");
        }
        fclose($fp);
    }

    private function write(array $journal): void {
        Journal::write(rtrim($this->dir, '/'), $journal);
    }


    private function staging(): string {
        return $this->dir . '.nonedb-maintenance/staging/';
    }
}
}
namespace noneDB\Migrate\Restore {

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
}
namespace noneDB\Migrate {

use noneDB\Migrate\Publisher\Migration;
use noneDB\Migrate\Restore\Restore;

/**
 * @internal
 */
final class Cli {
    private const USAGE = <<<'TXT'
usage: NONEDB_SECRET=... php noneDB-migrate.php --dir=DIR [--config=FILE] (--dry-run | --backup-dir=DIR --confirm-stopped
         [--db=NAME,...] [--verify] [--rebuild-from-data] [--flushing=import|skip] [--shard-size=N] | --abort
         | --restore=RUNID --backup-dir=DIR | --cleanup=RUNID --backup-dir=DIR) [--json]
       NONEDB_SOURCE_SECRET: the key the old files were written with, when it differs (docs/MIGRATION.md §2)

TXT;
    private const FLAGS = ['dry-run', 'confirm-stopped', 'verify', 'rebuild-from-data', 'abort', 'json'];
    private const VALUES = ['dir', 'config', 'backup-dir', 'db', 'flushing', 'shard-size', 'restore', 'cleanup'];

    /** @return int the exit status (§13) */
    public static function main(array $args): int {
        ini_set('serialize_precision', '-1');
        $o = self::parse($args);
        if($o === null){
            fwrite(STDERR, self::USAGE);
            return 64;
        }
        $json = isset($o['json']);
        try {
            $result = self::dispatch($o);
        } catch(Stop $e){
            self::output($json, ['result' => $e->result, 'message' => $e->getMessage()]);
            return Stop::STATUS[$e->result];
        } catch(\Throwable $e){
            // Anything else: the run stays where its journal says, to be continued or aborted
            self::output($json, ['result' => 'FAILED', 'message' => get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')']);
            return Stop::STATUS['FAILED'];
        }
        self::output($json, $result);
        return isset($result['report']['result']) && $result['report']['result'] !== 'READY' ? Stop::STATUS[$result['report']['result']] : 0;
    }

    /** Options, or null for a usage error */
    private static function parse(array $args): ?array {
        $o = [];
        foreach($args as $arg){
            if(!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)){
                return null;
            }
            $flag = in_array($m[1], self::FLAGS, true);
            if((!$flag && !in_array($m[1], self::VALUES, true)) || $flag === isset($m[2]) || isset($o[$m[1]])){
                return null;
            }
            $o[$m[1]] = $m[2] ?? true;
        }
        $modes = count(array_intersect_key($o, array_flip(['dry-run', 'abort', 'restore', 'cleanup'])));
        if($modes > 1 || (isset($o['flushing']) && !in_array($o['flushing'], ['import', 'skip'], true))
            || (isset($o['shard-size']) && !preg_match('/^[1-9]\d*$/', $o['shard-size']))
            || ($modes === 0 && (!isset($o['backup-dir']) || !isset($o['confirm-stopped'])))
            || ((isset($o['restore']) || isset($o['cleanup'])) && !isset($o['backup-dir']))){
            return null;
        }
        return $o;
    }

    /**
     * @throws Stop
     */
    private static function dispatch(array $o): array {
        $config = [];
        if(isset($o['config'])){
            $config = json_decode((string)@file_get_contents($o['config']), true);
            if(!is_array($config)){
                throw new Stop('FAILED', "{$o['config']} is not a readable .nonedb config file");
            }
            if(isset($config['dbDir']) && is_string($config['dbDir']) && $config['dbDir'] !== '' && $config['dbDir'][0] !== '/'){
                $config['dbDir'] = dirname(realpath($o['config'])) . '/' . $config['dbDir']; // relative to the config file
            }
        }
        $dir = $o['dir'] ?? $config['dbDir'] ?? null;
        if(!is_string($dir) || $dir === ''){
            throw new Stop('FAILED', 'no directory: --dir or a config file with dbDir');
        }
        if(isset($o['abort'])){
            return ['aborted' => Migration::abort($dir)];
        }
        $target = getenv('NONEDB_SECRET');
        $target = is_string($target) && $target !== '' ? $target : ($config['secretKey'] ?? null);
        if(!is_string($target) || $target === ''){
            throw new Stop('FAILED', 'no key: set NONEDB_SECRET or use a config file with secretKey (never a command line argument)');
        }
        if(isset($o['restore'])){
            return ['restored' => Restore::restore($dir, $o['backup-dir'], $o['restore'], $target)];
        }
        if(isset($o['cleanup'])){
            return ['cleaned' => Restore::cleanup($dir, $o['backup-dir'], $o['cleanup'])];
        }
        $source = getenv('NONEDB_SOURCE_SECRET');
        $options = [
            'db' => isset($o['db']) ? explode(',', $o['db']) : null,
            'rebuildFromData' => isset($o['rebuild-from-data']),
            'flushing' => $o['flushing'] ?? null,
            'shardSize' => (int)($o['shard-size'] ?? $config['shardSize'] ?? 10000),
            'verify' => isset($o['verify']),
        ];
        $source = is_string($source) && $source !== '' ? $source : $target;
        if(isset($o['dry-run'])){
            return ['report' => Migration::check($dir, $source, $target, $options)];
        }
        $real = realpath($dir);
        $backup = rtrim($o['backup-dir'], '/') . '/';
        if($real === false || strncmp(self::absolute($backup), $real . '/', strlen($real) + 1) === 0){
            throw new Stop('FAILED', '--backup-dir must be outside --dir');
        }
        return ['migrated' => Migration::run($dir, $source, $target, $options + ['backupDir' => $backup])];
    }

    /** A path made absolute without requiring it to exist */
    private static function absolute(string $path): string {
        $real = realpath($path);
        if($real !== false){
            return $real . '/';
        }
        return ($path[0] === '/' ? '' : getcwd() . '/') . $path;
    }

    private static function output(bool $json, array $result): void {
        if($json){
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            return;
        }
        if(isset($result['message'])){
            fwrite(STDERR, "noneDB-migrate: {$result['result']}: {$result['message']}\n");
            return;
        }
        if(isset($result['report'])){
            $report = $result['report'];
            echo "noneDB-migrate: {$report['dir']}\n";
            foreach($report['databases'] as $name => $db){
                printf("  %-24s %-18s %s%s, %d records, next key %d%s\n", $name, $db['status'],
                    $db['layout'], $db['layout'] === 'sharded' ? " ({$db['shardSize']} per shard)" : '', $db['records'], $db['nextKey'],
                    $db['target'] ? '' : ' (not in this run)');
                foreach(array_merge($db['reasons'], array_map(function($reason, $count){ return "not carried over: $count $reason"; },
                    array_keys($db['notCarried']), $db['notCarried'])) as $line){
                    echo "      $line\n";
                }
            }
            foreach(['leftovers' => 'leftovers of this key', 'otherKeys' => 'files of other keys (untouched)',
                'foreign' => 'other entries (untouched)'] as $list => $label){
                if($report[$list] !== []){
                    echo "  $label: " . implode(', ', $list === 'leftovers' ? array_keys($report[$list]) : $report[$list]) . "\n";
                }
            }
            echo "  result: {$report['result']}" . ($report['completesKey'] ? '' : ' (the key would not be registered by this run)') . "\n";
            return;
        }
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    }
}
}
namespace {
if (PHP_SAPI === 'cli' && isset($argv[0]) && (realpath($argv[0]) === __FILE__ || isset($GLOBALS['_composer_bin_dir']))) {
    if (!class_exists('noneDB', false)) {
        require __DIR__ . '/noneDB.php';
    }
    exit(\noneDB\Migrate\Cli::main(array_slice($argv, 1)));
}
}
