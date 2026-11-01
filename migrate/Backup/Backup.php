<?php
// The verified backup of a run's source files (docs/MIGRATION.md §7.3, §8)
namespace noneDB\Migrate\Backup;

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
