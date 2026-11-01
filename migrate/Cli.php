<?php
// The command line of noneDB-migrate.php (docs/MIGRATION.md §2, §13)
namespace noneDB\Migrate;

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
        if($real === false || strncmp(self::absolute($backup), self::comparable($real) . '/', strlen($real) + 1) === 0){
            throw new Stop('FAILED', '--backup-dir must be outside --dir');
        }
        return ['migrated' => Migration::run($dir, $source, $target, $options + ['backupDir' => $backup])];
    }

    /** A path made absolute without requiring it to exist, to compare */
    private static function absolute(string $path): string {
        $real = realpath($path);
        if($real !== false){
            return self::comparable($real) . '/';
        }
        $path = self::comparable($path);
        return preg_match('~^([a-z]:)?/~i', $path) === 1 ? $path : self::comparable((string)getcwd()) . '/' . $path;
    }

    /** A path as paths of the system compare: on Windows / and \ are one and case doesn't count */
    private static function comparable(string $path): string {
        return DIRECTORY_SEPARATOR === '\\' ? strtolower(str_replace('\\', '/', $path)) : $path;
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
