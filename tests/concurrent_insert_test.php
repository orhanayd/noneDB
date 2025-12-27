<?php
/**
 * Concurrent Insert Test
 *
 * Bu script iki farklı kullanıcının aynı anda insert yapması durumunu simüle eder.
 *
 * Kullanım:
 *   /Applications/MAMP/bin/php/php8.2.0/bin/php tests/concurrent_insert_test.php
 *
 * Beklenen: 200 kayıt (2 process x 100 insert)
 * Race condition varsa: < 200 kayıt (kayıp veriler)
 */

require_once __DIR__ . '/../noneDB.php';

// PHP executable path - bunu kendi sistemine göre ayarla
$phpPath = '/Applications/MAMP/bin/php/php8.2.0/bin/php';

// Ayarlar
$testDbName = 'concurrent_test_' . time();
$insertsPerProcess = 100;
$processCount = 2;

// Renk kodları
function green($text) { return "\033[32m{$text}\033[0m"; }
function red($text) { return "\033[31m{$text}\033[0m"; }
function yellow($text) { return "\033[33m{$text}\033[0m"; }
function blue($text) { return "\033[34m{$text}\033[0m"; }

echo blue("╔══════════════════════════════════════════════════════════════╗\n");
echo blue("║         noneDB Concurrent Insert Test                        ║\n");
echo blue("╚══════════════════════════════════════════════════════════════╝\n\n");

// Worker script - child process olarak çalışacak
$workerScript = <<<'PHP'
<?php
require_once __DIR__ . '/../noneDB.php';

$dbName = $argv[1];
$workerId = $argv[2];
$insertCount = (int)$argv[3];

$db = new noneDB();
$inserted = 0;

for ($i = 0; $i < $insertCount; $i++) {
    $result = $db->insert($dbName, [
        'worker_id' => $workerId,
        'sequence' => $i,
        'timestamp' => microtime(true),
        'data' => "Record {$i} from worker {$workerId}"
    ]);

    if (isset($result['n']) && $result['n'] > 0) {
        $inserted++;
    }

    // Küçük bir rastgele gecikme - race condition şansını artırır
    usleep(rand(0, 1000));
}

echo json_encode(['worker_id' => $workerId, 'inserted' => $inserted]);
PHP;

$workerFile = __DIR__ . '/concurrent_worker.php';
file_put_contents($workerFile, $workerScript);

// Temiz başlangıç
$db = new noneDB();

echo "Test Parametreleri:\n";
echo "  • Database: {$testDbName}\n";
echo "  • Process sayısı: {$processCount}\n";
echo "  • Her process'in insert sayısı: {$insertsPerProcess}\n";
echo "  • Beklenen toplam kayıt: " . ($processCount * $insertsPerProcess) . "\n\n";

echo yellow("▶ Process'ler başlatılıyor...\n\n");

// Parallel process'leri başlat
$processes = [];
$pipes = [];

for ($i = 0; $i < $processCount; $i++) {
    $workerId = "worker_{$i}";

    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];

    $cmd = "{$phpPath} {$workerFile} {$testDbName} {$workerId} {$insertsPerProcess}";
    $process = proc_open($cmd, $descriptorspec, $p);

    if (is_resource($process)) {
        $processes[$workerId] = $process;
        $pipes[$workerId] = $p;
        echo "  ✓ {$workerId} başlatıldı\n";
    }
}

echo "\n" . yellow("▶ Process'ler tamamlanması bekleniyor...\n\n");

// Sonuçları topla
$results = [];
foreach ($processes as $workerId => $process) {
    $output = stream_get_contents($pipes[$workerId][1]);
    $errors = stream_get_contents($pipes[$workerId][2]);

    fclose($pipes[$workerId][0]);
    fclose($pipes[$workerId][1]);
    fclose($pipes[$workerId][2]);

    $exitCode = proc_close($process);

    if ($output) {
        $result = json_decode($output, true);
        if ($result) {
            $results[$workerId] = $result;
            echo "  ✓ {$workerId}: {$result['inserted']} kayıt eklendi (rapor edilen)\n";
        }
    }

    if ($errors) {
        echo red("  ✗ {$workerId} hata: {$errors}\n");
    }
}

// Gerçek kayıt sayısını kontrol et
echo "\n" . yellow("▶ Veritabanı doğrulanıyor...\n\n");

$allRecords = $db->find($testDbName, []);
$actualCount = is_array($allRecords) ? count($allRecords) : 0;
$expectedCount = $processCount * $insertsPerProcess;

// Worker bazlı analiz
$workerCounts = [];
if (is_array($allRecords)) {
    foreach ($allRecords as $record) {
        if (isset($record['worker_id'])) {
            $wid = $record['worker_id'];
            $workerCounts[$wid] = ($workerCounts[$wid] ?? 0) + 1;
        }
    }
}

echo "Worker Bazlı Sonuçlar:\n";
foreach ($workerCounts as $wid => $count) {
    $status = $count === $insertsPerProcess ? green("✓") : red("✗");
    echo "  {$status} {$wid}: {$count}/{$insertsPerProcess} kayıt\n";
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "SONUÇ:\n";
echo "  • Beklenen kayıt sayısı: {$expectedCount}\n";
echo "  • Gerçek kayıt sayısı:   {$actualCount}\n";

if ($actualCount === $expectedCount) {
    echo "\n" . green("  ✓ BAŞARILI: Tüm kayıtlar doğru şekilde eklendi!\n");
    echo green("    Race condition tespit edilmedi.\n");
} else {
    $lost = $expectedCount - $actualCount;
    echo "\n" . red("  ✗ BAŞARISIZ: {$lost} kayıt kayboldu!\n");
    echo red("    Race condition tespit edildi!\n");
    echo "\n";
    echo yellow("  Çözüm önerileri:\n");
    echo "  1. Atomik dosya kilitleme (flock ile read-modify-write)\n";
    echo "  2. Veritabanı seviyesinde mutex\n";
    echo "  3. Optimistik kilitleme (version numarası ile)\n";
}
echo "═══════════════════════════════════════════════════════════════\n";

// Temizlik
unlink($workerFile);

// Test veritabanı dosyalarını temizle
$dbDir = __DIR__ . '/../db/';
$files = glob($dbDir . '*' . $testDbName . '*');
foreach ($files as $file) {
    unlink($file);
}

echo "\n" . blue("Test tamamlandı. Test veritabanı silindi.\n");
