<?php
/**
 * Concurrent Stress Test - Daha agresif test
 *
 * 5 process x 50 insert = 250 kayıt
 */

require_once __DIR__ . '/../noneDB.php';

$phpPath = '/Applications/MAMP/bin/php/php8.2.0/bin/php';
$testDbName = 'stress_test_' . time();
$insertsPerProcess = 50;
$processCount = 5;

function green($text) { return "\033[32m{$text}\033[0m"; }
function red($text) { return "\033[31m{$text}\033[0m"; }
function yellow($text) { return "\033[33m{$text}\033[0m"; }

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║      noneDB Concurrent STRESS Test (5 Process)             ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Worker script - NO delay version for maximum stress
$workerScript = <<<'PHP'
<?php
require_once __DIR__ . '/../noneDB.php';
$db = new noneDB();
$inserted = 0;
for ($i = 0; $i < (int)$argv[3]; $i++) {
    $result = $db->insert($argv[1], [
        'worker_id' => $argv[2],
        'seq' => $i,
        'ts' => microtime(true)
    ]);
    if (isset($result['n']) && $result['n'] > 0) $inserted++;
}
echo json_encode(['w' => $argv[2], 'n' => $inserted]);
PHP;

$workerFile = __DIR__ . '/stress_worker.php';
file_put_contents($workerFile, $workerScript);

$db = new noneDB();
$expectedCount = $processCount * $insertsPerProcess;

echo "Config: {$processCount} process x {$insertsPerProcess} insert = {$expectedCount} kayıt bekleniyor\n\n";

// Start all processes
$processes = [];
$pipes = [];
for ($i = 0; $i < $processCount; $i++) {
    $cmd = "{$phpPath} {$workerFile} {$testDbName} w{$i} {$insertsPerProcess}";
    $process = proc_open($cmd, [0=>["pipe","r"], 1=>["pipe","w"], 2=>["pipe","w"]], $p);
    if (is_resource($process)) {
        $processes["w{$i}"] = $process;
        $pipes["w{$i}"] = $p;
    }
}

// Collect results
$totalReported = 0;
foreach ($processes as $wid => $process) {
    $output = stream_get_contents($pipes[$wid][1]);
    fclose($pipes[$wid][0]);
    fclose($pipes[$wid][1]);
    fclose($pipes[$wid][2]);
    proc_close($process);

    if ($output) {
        $r = json_decode($output, true);
        if ($r) {
            $totalReported += $r['n'];
            echo "  {$wid}: {$r['n']} kayıt (rapor)\n";
        }
    }
}

// Verify
$allRecords = $db->find($testDbName, []);
$actualCount = is_array($allRecords) ? count($allRecords) : 0;

// Worker breakdown
$workerCounts = [];
if (is_array($allRecords)) {
    foreach ($allRecords as $rec) {
        if (isset($rec['worker_id'])) {
            $workerCounts[$rec['worker_id']] = ($workerCounts[$rec['worker_id']] ?? 0) + 1;
        }
    }
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "SONUÇ:\n";
echo "  • Worker'ların rapor ettiği: {$totalReported}\n";
echo "  • Beklenen:                  {$expectedCount}\n";
echo "  • Gerçek:                    {$actualCount}\n";

if ($actualCount === $expectedCount) {
    echo "\n" . green("  ✓ BAŞARILI - Veri kaybı yok!\n");
} else {
    $lost = $expectedCount - $actualCount;
    $lostPct = round(($lost / $expectedCount) * 100, 1);
    echo "\n" . red("  ✗ BAŞARISIZ - {$lost} kayıt kayboldu (%{$lostPct})\n");

    echo "\nWorker bazlı kayıp:\n";
    for ($i = 0; $i < $processCount; $i++) {
        $wid = "w{$i}";
        $actual = $workerCounts[$wid] ?? 0;
        $lost = $insertsPerProcess - $actual;
        if ($lost > 0) {
            echo red("  {$wid}: {$actual}/{$insertsPerProcess} ({$lost} kayıp)\n");
        } else {
            echo green("  {$wid}: {$actual}/{$insertsPerProcess} (tamam)\n");
        }
    }
}
echo "═══════════════════════════════════════════════════════════════\n";

// Cleanup
unlink($workerFile);
$files = glob(__DIR__ . '/../db/*' . $testDbName . '*');
foreach ($files as $f) unlink($f);
