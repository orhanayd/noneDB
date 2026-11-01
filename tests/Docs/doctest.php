<?php
// The PHP examples of the documentation, run against the built library: every ```php block of a
// document, in order, in one process per document, in a directory of its own with a .nonedb next to the script
// (so `new noneDB()` works as the documents show it) and NONEDB_SECRET in the environment. A block preceded by the line <!-- doctest: skip --> is not
// run (a signature, a fragment, an example of a file). A block fails on an exception, an error, a warning, a
// notice or a deprecation.
// usage: php tests/Docs/doctest.php [--library=noneDB.php] [document.md ...]   (default: the documents below)
error_reporting(E_ALL);
$root = dirname(__DIR__, 2);
$options = getopt('', ['library:'], $rest);
$library = realpath($options['library'] ?? $root . '/noneDB.php');
$documents = array_slice($argv, $rest);
if (empty($documents)) {
    $documents = array_merge([$root . '/README.md'], glob($root . '/docs/*.md'));
}
if ($library === false) {
    fwrite(STDERR, "no library\n");
    exit(2);
}

/** The runnable blocks of a document: [line, code] */
function blocks(string $markdown): array
{
    $blocks = [];
    $lines = explode("\n", $markdown);
    $skip = false;
    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $line = rtrim($lines[$i]);
        if ($line === '<!-- doctest: skip -->') {
            $skip = true;
            continue;
        }
        if (preg_match('/^(\s*)```(\w*)/', $line, $m) !== 1) {
            if (trim($line) !== '') {
                $skip = false;
            }
            continue;
        }
        $start = $i + 1;
        $code = [];
        for ($i++; $i < $n && preg_match('/^\s*```\s*$/', $lines[$i]) !== 1; $i++) {
            $code[] = substr($lines[$i], strlen($m[1]));
        }
        if ($m[2] === 'php' && !$skip) {
            $blocks[] = [$start, implode("\n", $code)];
        }
        $skip = false;
    }
    return $blocks;
}

function removeTree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            removeTree("$path/$entry");
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

$failed = 0;
$ran = 0;
foreach ($documents as $document) {
    $name = substr(realpath($document), strlen($root) + 1);
    $blocks = blocks((string)file_get_contents($document));
    if (empty($blocks)) {
        continue;
    }
    $dir = sys_get_temp_dir() . '/nonedb_doctest_' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    copy($library, "$dir/noneDB.php");
    file_put_contents("$dir/.nonedb", json_encode(['secretKey' => bin2hex(random_bytes(16)), 'dbDir' => './db/']));
    // Warnings, notices and deprecations fail the block; what the library suppresses with @ does not
    $script = "<?php\nset_error_handler(function (\$no, \$message, \$file, \$line) { if ((error_reporting() & \$no) === 0) { return false; } throw new ErrorException(\$message, 0, \$no, \$file, \$line); });\n"
        . "chdir(__DIR__);\nrequire_once __DIR__ . '/noneDB.php';\n";
    foreach ($blocks as $i => [$line, $code]) {
        $code = preg_replace('/^\s*<\?php\s*/', '', $code);
        // The documents include the library as an application does: here it is loaded once
        $code = preg_replace('/^\s*(include|require)(_once)?\s*\(?\s*([\'"])noneDB\.php\3\s*\)?\s*;/m', '', $code);
        $script .= "fwrite(STDERR, \"\\n@block $line\\n\");\n$code\n";
    }
    $script .= "fwrite(STDERR, \"\\n@done\\n\");\n";
    file_put_contents("$dir/doc.php", $script);
    // NONEDB_SECRET: the documents read keys from the environment, as applications should
    $env = getenv() + ['NONEDB_SECRET' => bin2hex(random_bytes(16))];
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', "$dir/doc.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
    stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $ran += count($blocks);
    if ($status !== 0 || strpos($errors, "\n@done\n") === false) {
        $failed++;
        $at = preg_match_all('/@block (\d+)/', $errors, $m) ? end($m[1]) : '?';
        $detail = trim(substr($errors, strrpos($errors, "@block $at") + strlen("@block $at")));
        echo "FAIL $name:$at\n" . preg_replace('/^/m', '    ', $detail) . "\n";
    } else {
        echo "ok   $name (" . count($blocks) . " blocks)\n";
    }
    removeTree($dir);
}
echo "$ran blocks, " . ($failed === 0 ? 'all documents passed' : "$failed documents failed") . "\n";
exit($failed === 0 ? 0 : 1);
