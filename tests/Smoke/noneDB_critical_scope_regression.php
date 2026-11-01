<?php
/**
 * noneDB — Critical rollback-scope regression tests (standalone, no PHPUnit dependency).
 *
 * Run from the repository root:
 *     php tests/Smoke/noneDB_critical_scope_regression.php
 * Set NONEDB_PATH to test another copy of noneDB.php.
 * Uses only temporary databases and removes them afterwards.
 * Expected: 3 PASS, 0 FAIL.
 * PHPUnit: RollbackScopeTest::dataFileReplacementIsRefusedInsideAnUncommittedWrite.
 */
$library = getenv('NONEDB_PATH') ?: __DIR__ . '/../../noneDB.php';
if(!is_file($library)) { fwrite(STDERR,"noneDB.php not found: $library\n"); exit(2); }
require_once $library;
require_once __DIR__ . '/../Support/Testing.php'; // the test interface (internal values, methods)

function auditAssert($condition, $message) {
    if(!$condition) throw new RuntimeException($message);
}
function auditClean($path) {
    if(!is_dir($path)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f) {
        if($f->isDir()) @rmdir($f->getPathname());
        else @unlink($f->getPathname());
    }
    @rmdir($path);
}
function auditCase($name, $destructiveMethod, $sharding = false) {
    $dir = sys_get_temp_dir().'/nonedb-scope-'.bin2hex(random_bytes(8));
    mkdir($dir,0700,true);
    try {
        $db = new noneDB([
            'secretKey' => bin2hex(random_bytes(32)), 'dbDir' => $dir,
            'shardingEnabled' => $sharding, 'shardSize' => 5, 'bufferEnabled' => false,
        ]);
        auditAssert(($db->insert('victim',[['v'=>'original'],['v'=>'second']])['n'] ?? 0)===2,'setup insert failed');
        $thrown = null;
        try {
            noneDB\Tests\Support\Testing::call($db,'withLock','victim',true,function() use ($db,$destructiveMethod) {
                $db->insert('victim',['v'=>'uncommitted']);
                // A storage-file replacement/removal must not execute while an append rollback mark exists.
                $db->$destructiveMethod('victim');
                throw new RuntimeException('abort outer write');
            });
        } catch(Throwable $e) { $thrown = $e; }
        auditAssert($thrown!==null,'test failed to trigger an outer exception');
        $rows = $db->find('victim');
        $values = array_column($rows, 'v');
        auditAssert($values === ['original','second'],
            "Rollback must preserve previously committed records and remove uncommitted inserts; actual=".json_encode($values).
            "; exception=".get_class($thrown).'/'.$thrown->getCode());
        echo "PASS: {$name}\n";
        return true;
    } catch(Throwable $e) {
        echo "FAIL: {$name} :: ".get_class($e).": ".$e->getMessage()."\n";
        return false;
    } finally {
        auditClean($dir);
    }
}

$ok = 0;
$ok += auditCase('Dirty-scope dropDB is rejected before any deletion','dropDB') ? 1 : 0;
$ok += auditCase('Dirty-scope compact is rejected or safely deferred','compact') ? 1 : 0;
$ok += auditCase('Dirty-scope convertToSharded is rejected or safely deferred','convertToSharded',true) ? 1 : 0;
echo "Result: {$ok} passed, ".(3-$ok)." failed\n";
exit($ok===3?0:1);
