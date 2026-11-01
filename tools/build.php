<?php
/**
 * Builds the two artifacts from their sources:
 * - noneDB.php from src/: src/header.php, then every namespaced class file of src/manifest.php in a bracketed
 *   namespace block ("namespace noneDB\Internal\... { ... }"), then the global classes in "namespace { ... }": for
 *   each the text before it, "class <name> {", the bodies of its modules in order and "}".
 * - noneDB-migrate.php (the migration tool, docs/MIGRATION.md) from migrate/: migrate/header.php, every class file of
 *   migrate/manifest.php in a "namespace noneDB\Migrate... { ... }" block, then migrate/footer.php (its
 *   "namespace { ... }" block: the command line entry). The tool loads noneDB.php; the engine knows nothing of it.
 *
 *   php tools/build.php                write both (each only when its content changes)
 *   php tools/build.php --check        exit 1 when an artifact is not the build of its sources
 *   php tools/build.php --stdout       print the build of noneDB.php
 *   php tools/build.php --map          line ranges of the modules in noneDB.php
 *   php tools/build.php --test FILE    the test build of noneDB.php to FILE
 *   php tools/build.php --test-tool FILE   the test build of noneDB-migrate.php to FILE
 *
 * A class file <dir>/.../<Name>.php is "<?php\n", "//" comment lines, "namespace <prefix>...;\n" and the rest of the
 * file (use statements and one class named <Name>), copied as it is.
 * A module file src/<class>/<Module>.php is "<?php\n", "//" comment lines, "trait <class>_<Module> {\n",
 * the body and "}\n", LF line ends only. The traits are never loaded: they only let editors understand
 * the files. The output depends on nothing but the files.
 *
 * Test seams: the artifacts hold only what they need to run. A line ending in "// @test" (fault points, test hooks
 * and counters) and the lines from one ending in "// @test-begin" to one ending in "// @test-end" are left out of
 * them; a line ending in "// @prod" is its production form and is left out of the test build ($testBuild), which
 * the tests load (tests/bootstrap.php writes both to tests/build/).
 */

if (!function_exists('nonedb_build')) {
    /**
     * @return array ['code' => string, 'map' => list of [class, module, first line, last line]]
     * @throws RuntimeException when a file is missing or malformed, or the result isn't valid PHP
     */
    function nonedb_build(string $root, bool $testBuild = false): array
    {
        $src = rtrim($root, '/') . '/src/';
        nonedb_build_read($src . 'manifest.php'); // exists, LF only
        $manifest = (static function (string $file) {
            return require $file;
        })($src . 'manifest.php');
        if (!is_array($manifest) || !isset($manifest['header'], $manifest['classes']) || !is_array($manifest['classes'])) {
            throw new RuntimeException('src/manifest.php must return [header, classes]');
        }
        $code = nonedb_build_read($src . $manifest['header']);
        $read = function (string $file) use ($testBuild): string {
            return nonedb_build_variant(nonedb_build_read($file), $testBuild, $file);
        };
        $map = [];
        $expected = [];
        foreach ($manifest['files'] ?? [] as $file) {
            nonedb_build_class($src, $file, 'noneDB\\Internal', $read, $code, $map, $expected);
        }
        $code .= "namespace {\n";
        foreach ($manifest['classes'] as $class) {
            $name = $class['name'];
            $expected[] = $name;
            $code .= $class['before'] . "class $name {\n";
            foreach ($class['modules'] as $module) {
                $file = "$name/$module.php";
                $content = $read($src . $file);
                if (!preg_match('/\A<\?php\n(?:\/\/[^\n]*\n)*trait (\w+) \{\n(.*)\}\n\z/s', $content, $m)) {
                    throw new RuntimeException("src/$file: expected \"<?php\", // comments, \"trait {$name}_$module {\", the body, \"}\"");
                }
                if ($m[1] !== "{$name}_$module") {
                    throw new RuntimeException("src/$file: the trait must be named {$name}_$module");
                }
                $first = substr_count($code, "\n") + 1;
                $code .= $m[2];
                $map[] = [$name, $module, $first, substr_count($code, "\n")];
            }
            $code .= "}\n";
        }
        $code .= "}\n";
        nonedb_build_verify($code, $expected);
        return ['code' => $code, 'map' => $map];
    }

    /**
     * One variant of a source file: production (lines marked "// @test" and "// @test-begin" … "// @test-end"
     * blocks left out, "// @prod" markers removed) or the test build (lines marked "// @prod" left out)
     */
    function nonedb_build_variant(string $content, bool $testBuild, string $file): string
    {
        $out = [];
        $inBlock = false;
        foreach (explode("\n", $content) as $n => $line) {
            $marker = preg_match('#//\s*@(test-begin|test-end|test|prod)\s*$#', $line, $m) ? $m[1] : null;
            if ($marker === 'test-begin') {
                if ($inBlock) {
                    throw new RuntimeException("$file:" . ($n + 1) . ': nested @test-begin');
                }
                $inBlock = true;
            }
            $keep = $testBuild ? $marker !== 'prod' : !$inBlock && $marker !== 'test';
            if ($marker === 'test-end') {
                if (!$inBlock) {
                    throw new RuntimeException("$file:" . ($n + 1) . ': @test-end without @test-begin');
                }
                $inBlock = false;
            }
            if ($keep) {
                $out[] = $marker === 'prod' ? rtrim(preg_replace('#\s*//\s*@prod\s*$#', '', $line)) : $line;
            }
        }
        if ($inBlock) {
            throw new RuntimeException("$file: @test-begin without @test-end");
        }
        return implode("\n", $out);
    }

    /**
     * One class file of a manifest into a bracketed namespace block of $code: "<?php", // comments,
     * "namespace <$prefix>...;" and the rest, which declares the class named as the file
     */
    function nonedb_build_class(string $dir, string $file, string $prefix, callable $read, string &$code, array &$map, array &$expected): void
    {
        $content = $read($dir . $file);
        if (!preg_match('/\A<\?php\n(?:\/\/[^\n]*\n)*namespace (' . preg_quote($prefix, '/') . '(?:\\\\\w+)*);\n(.*)\z/s', $content, $m)) {
            throw new RuntimeException("$dir$file: expected \"<?php\", // comments, \"namespace $prefix...;\", the rest");
        }
        $name = basename($file, '.php');
        if (!preg_match('/^(?:final |abstract )?class ' . preg_quote($name, '/') . '\b/m', $m[2])) {
            throw new RuntimeException("$dir$file: the file must declare the class $name");
        }
        $first = substr_count($code, "\n") + 1;
        $code .= "namespace {$m[1]} {\n" . $m[2] . "}\n";
        $map[] = [$m[1], $name, $first, substr_count($code, "\n")];
        $expected[] = $name;
    }

    /**
     * noneDB-migrate.php from migrate/ (see the top of this file)
     * @return array ['code' => string, 'map' => list of [namespace, class, first line, last line]]
     * @throws RuntimeException when a file is missing or malformed, or the result isn't valid PHP
     */
    function nonedb_build_tool(string $root, bool $testBuild = false): array
    {
        $dir = rtrim($root, '/') . '/migrate/';
        nonedb_build_read($dir . 'manifest.php');
        $manifest = (static function (string $file) {
            return require $file;
        })($dir . 'manifest.php');
        if (!is_array($manifest) || !isset($manifest['header'], $manifest['files'], $manifest['footer']) || !is_array($manifest['files'])) {
            throw new RuntimeException('migrate/manifest.php must return [header, files, footer]');
        }
        $read = function (string $file) use ($testBuild): string {
            return nonedb_build_variant(nonedb_build_read($file), $testBuild, $file);
        };
        $code = nonedb_build_read($dir . $manifest['header']);
        $map = [];
        $expected = [];
        foreach ($manifest['files'] as $file) {
            nonedb_build_class($dir, $file, 'noneDB\\Migrate', $read, $code, $map, $expected);
        }
        if (!preg_match('/\A<\?php\n(?:\/\/[^\n]*\n)*(namespace \{\n.*\}\n)\z/s', $read($dir . $manifest['footer']), $m)) {
            throw new RuntimeException('migrate/' . $manifest['footer'] . ': expected "<?php", // comments, one "namespace {" block');
        }
        $code .= $m[1];
        nonedb_build_verify($code, $expected);
        return ['code' => $code, 'map' => $map];
    }

    function nonedb_build_read(string $file): string
    {
        if (!is_file($file)) {
            throw new RuntimeException("missing $file");
        }
        $content = (string)file_get_contents($file);
        if (strpos($content, "\r") !== false) {
            throw new RuntimeException("$file: CR characters (use LF line ends)");
        }
        return $content;
    }

    /**
     * The build declares exactly the expected classes, no trait, has balanced braces and passes php -l
     */
    function nonedb_build_verify(string $code, array $classes): void
    {
        $tokens = token_get_all($code);
        $declared = [];
        $depth = 0;
        $previous = null;
        foreach ($tokens as $i => $token) {
            if (is_array($token)) {
                if ($token[0] === T_TRAIT) {
                    throw new RuntimeException('the build contains a trait');
                }
                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                }
                if ($token[0] === T_CLASS && $previous !== T_DOUBLE_COLON && $previous !== T_NEW) {
                    for ($j = $i + 1; is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++) {
                    }
                    $declared[] = is_array($tokens[$j]) ? $tokens[$j][1] : '';
                }
                if ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                    $previous = $token[0];
                }
                continue;
            }
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                if (--$depth < 0) {
                    throw new RuntimeException('unbalanced braces in the build');
                }
            }
            $previous = $token;
        }
        if ($depth !== 0) {
            throw new RuntimeException('unbalanced braces in the build');
        }
        if ($declared !== $classes) {
            throw new RuntimeException('the build must declare exactly the classes ' . implode(', ', $classes) . ', found: ' . implode(', ', $declared));
        }
        $process = proc_open([PHP_BINARY, '-n', '-l'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot run php -l');
        }
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('php -l: ' . trim($output));
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $root = dirname(__DIR__);
    $mode = $argv[1] ?? '';
    $usage = "usage: php tools/build.php [--check|--stdout|--map|--test <file>|--test-tool <file>]\n";
    if (!in_array($mode, ['', '--check', '--stdout', '--map', '--test', '--test-tool'], true)) {
        fwrite(STDERR, $usage);
        exit(2);
    }
    try {
        $builds = [
            'noneDB.php' => nonedb_build($root, $mode === '--test'),
            'noneDB-migrate.php' => nonedb_build_tool($root, $mode === '--test-tool'),
        ];
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'build failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
    if ($mode === '--test' || $mode === '--test-tool') {
        // A test build (seams included) to a file the caller names
        $code = $builds[$mode === '--test' ? 'noneDB.php' : 'noneDB-migrate.php']['code'];
        $file = $argv[2] ?? '';
        if ($file === '' || file_put_contents($file, $code) !== strlen($code)) {
            fwrite(STDERR, $usage);
            exit(2);
        }
        exit(0);
    }
    if ($mode === '--stdout') {
        echo $builds['noneDB.php']['code'];
        exit(0);
    }
    if ($mode === '--map') {
        foreach ($builds['noneDB.php']['map'] as [$class, $module, $first, $last]) {
            printf("%-32s %-24s %6d-%d\n", $class, $module, $first, $last);
        }
        exit(0);
    }
    $status = 0;
    foreach ($builds as $name => $build) {
        $target = "$root/$name";
        $current = is_file($target) ? (string)file_get_contents($target) : null;
        if ($current === $build['code']) {
            echo "$name is up to date\n";
            continue;
        }
        if ($mode === '--check') {
            fwrite(STDERR, "$name is not the build of its sources: edit them and run php tools/build.php\n");
            $status = 1;
            continue;
        }
        $tmp = $target . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $build['code']) !== strlen($build['code']) || !rename($tmp, $target)) {
            @unlink($tmp);
            fwrite(STDERR, "cannot write $name\n");
            exit(1);
        }
        echo "$name built (" . substr_count($build['code'], "\n") . " lines)\n";
    }
    exit($status);
}
