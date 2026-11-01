<?php
// A migration run: pre-check, locks, journal, backup, staging, verification, publish, commit (docs/MIGRATION.md §7-§10)
namespace noneDB\Migrate\Publisher;

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
        self::point('locked'); // @test
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
            $free = @disk_free_space($path); // @prod
            $free = getenv('NONEDB_MIGRATE_FREE') !== false ? (float)getenv('NONEDB_MIGRATE_FREE') : @disk_free_space($path); // @test
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
            self::point('published'); // @test
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
        self::point('applied:store'); // @test
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
        self::point('applied:retained'); // @test
        foreach($journal['published'] as $locator){
            if(is_file($this->dir . $locator . '/LOCK')){
                self::writeState($this->dir . $locator . '/LOCK', []);
            }
        }
        self::point('applied:unmarked'); // @test
        Files::removeTree($this->staging());
        // Closed before the final check: the engine opens no store while a maintenance journal is open
        Journal::close(rtrim($this->dir, '/'), $journal['backup'] . '/journal.done.json');
        self::point('closed'); // @test
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
        self::point('reported'); // @test
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
        self::point('state:' . $journal['state']); // @test
    }

    // @test-begin
    /**
     * Test seams at a point of the run: NONEDB_MIGRATE_TRACE (a file) gets the name of every point reached;
     * NONEDB_MIGRATE_CHANGE ({"at": point, "nth": n, "files": [...], "append": bytes}) appends to source files the n-th
     * time the point is reached (another process writing during the run); the process stops with SIGKILL at the point
     * NONEDB_MIGRATE_KILL names (a crash there)
     */
    private static function point(string $name): void {
        static $reached = [];
        $reached[$name] = ($reached[$name] ?? 0) + 1;
        if(($trace = getenv('NONEDB_MIGRATE_TRACE')) !== false){
            file_put_contents($trace, $name . "\n", FILE_APPEND);
        }
        $change = json_decode((string)getenv('NONEDB_MIGRATE_CHANGE'), true);
        if(is_array($change) && $change['at'] === $name && $change['nth'] === $reached[$name]){
            foreach($change['files'] as $file){
                file_put_contents($file, $change['append'], FILE_APPEND);
            }
        }
        if(getenv('NONEDB_MIGRATE_KILL') === $name){
            posix_kill(getmypid(), 9);
        }
    }
    // @test-end

    private function staging(): string {
        return $this->dir . '.nonedb-maintenance/staging/';
    }
}
