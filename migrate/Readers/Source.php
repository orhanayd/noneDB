<?php
// One database in an earlier format: its live records, next key, indexes and result (docs/MIGRATION.md §4-§6)
namespace noneDB\Migrate\Readers;

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
