<?php

namespace noneDB\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * noneDB.php is built from src/ by tools/build.php: the committed file must be the build
 */
class BuildTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        if (!is_dir(self::root() . '/src')) {
            $this->markTestSkipped('no src/ (installed package)');
        }
        require_once self::root() . '/tools/build.php';
    }

    /** @var string[] */
    private $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$dir/$entry";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** A copy of src/ in a temporary directory, for broken variants */
    private function copyOfSrc(): string
    {
        $dir = sys_get_temp_dir() . '/nonedb_build_' . bin2hex(random_bytes(4));
        $this->tempDirs[] = $dir;
        $copy = function (string $from, string $to) use (&$copy) {
            mkdir($to, 0777, true);
            foreach (scandir($from) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    is_dir("$from/$entry") ? $copy("$from/$entry", "$to/$entry") : copy("$from/$entry", "$to/$entry");
                }
            }
        };
        $copy(self::root() . '/src', "$dir/src");
        return $dir;
    }

    /**
     * @test test seams (tools/build.php): a line ending in "// @test" and the lines of a "// @test-begin" …
     * "// @test-end" block exist only in the test build; a line ending in "// @prod" only in noneDB.php,
     * without its marker. Every other line is in both, in the same order.
     */
    public function testSeamsSplitTheTwoBuilds(): void
    {
        $source = implode("\n", [
            'a();',
            '$x = plain(); // @prod',
            '$x = hooked(); // @test',
            'b(); // a comment',
            '    // @test-begin',
            '    function seam() {}',
            '    // @test-end',
            'c(); //  @test  ',
            'd();',
        ]);
        $this->assertSame(implode("\n", ['a();', '$x = plain();', 'b(); // a comment', 'd();']), nonedb_build_variant($source, false, 'f'));
        $this->assertSame(implode("\n", ['a();', '$x = hooked(); // @test', 'b(); // a comment', '    // @test-begin', '    function seam() {}',
            '    // @test-end', 'c(); //  @test  ', 'd();']), nonedb_build_variant($source, true, 'f'));
        foreach (['nested' => "// @test-begin\n// @test-begin\n// @test-end\n// @test-end", 'end alone' => "x();\n// @test-end",
                  'never ended' => "// @test-begin\nx();"] as $label => $broken) {
            foreach ([false, true] as $test) {
                try {
                    nonedb_build_variant($broken, $test, 'f');
                    $this->fail("$label: accepted");
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('@test-', $e->getMessage(), $label);
                }
            }
        }
    }

    /** @test the test build of src/ is valid PHP declaring the same classes, with the seams noneDB.php leaves out */
    public function theTestBuildHoldsTheSeams(): void
    {
        $test = nonedb_build(self::root(), true)['code']; // verified as the production build is
        $production = (string)file_get_contents(self::root() . '/noneDB.php');
        foreach (['faultHook', 'function fault(', 'writeFn', 'indexStats'] as $seam) {
            $this->assertStringContainsString($seam, $test, $seam);
            $this->assertStringNotContainsString($seam, $production, $seam);
        }
        $this->assertGreaterThan(substr_count($production, "\n"), substr_count($test, "\n"));
    }

    /** @test */
    public function noneDBphpIsTheBuildOfSrc(): void
    {
        $build = nonedb_build(self::root());
        $this->assertTrue($build['code'] === file_get_contents(self::root() . '/noneDB.php'),
            'noneDB.php differs from the build of src/: run php tools/build.php');
    }

    /** @test */
    public function everyModuleIsListedAndTheMapCoversTheClasses(): void
    {
        $build = nonedb_build(self::root());
        $listed = [];
        $previous = null;
        foreach ($build['map'] as [$class, $module, $first, $last]) {
            $listed[] = "$class/$module.php";
            $this->assertLessThanOrEqual($last, $first);
            if ($previous !== null && $previous[0] === $class) {
                $this->assertSame($previous[1] + 1, $first, "$class/$module follows the previous module");
            }
            $previous = [$class, $last];
        }
        $modules = [];
        foreach (['noneDB', 'noneDBQuery'] as $class) {
            foreach (glob(self::root() . "/src/$class/*.php") as $file) {
                $modules[] = "$class/" . basename($file);
            }
        }
        $classFiles = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/src/Internal', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $classFiles[] = str_replace('\\', '/', substr((string)$file, strlen(self::root() . '/src/')));
        }
        $manifest = require self::root() . '/src/manifest.php';
        $listedFiles = $manifest['files'];
        $listedModules = [];
        foreach ($build['map'] as [$class, $module]) {
            if (in_array($class, ['noneDB', 'noneDBQuery'], true)) {
                $listedModules[] = "$class/$module.php";
            }
        }
        sort($modules);
        sort($listedModules);
        sort($classFiles);
        sort($listedFiles);
        $this->assertSame($modules, $listedModules, 'every file in src/<class>/ is in the manifest, once');
        $this->assertSame($classFiles, $listedFiles, 'every file in src/Internal/ is in the manifest, once');
        $this->assertSame(count($listedFiles), count(array_unique($listedFiles)));
    }

    /** @test */
    public function checkModeAgreesWithTheFile(): void
    {
        $process = proc_open([PHP_BINARY, self::root() . '/tools/build.php', '--check'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
    }

    /** @test */
    public function malformedModulesAreRejected(): void
    {
        $cases = [
            'CR line ends' => function (string $s) { return str_replace("\n", "\r\n", $s); },
            'wrong trait name' => function (string $s) { return str_replace('trait noneDBQuery_Chain {', 'trait noneDBQuery_Other {', $s); },
            'text before the trait' => function (string $s) { return str_replace("trait noneDBQuery_Chain {", "\$x = 1;\ntrait noneDBQuery_Chain {", $s); },
            'unbalanced braces' => function (string $s) { return preg_replace('/\}\n\z/', "}\n}\n", $s); },
            'syntax error' => function (string $s) { return str_replace('public function where(', 'public function where(,', $s); },
            'a class in a body' => function (string $s) { return preg_replace('/\}\n\z/', "}\n}\nclass Extra {\n}\n", $s); },
        ];
        foreach ($cases as $name => $mutate) {
            $dir = $this->copyOfSrc();
            $file = "$dir/src/noneDBQuery/Chain.php";
            file_put_contents($file, $mutate((string)file_get_contents($file)));
            try {
                nonedb_build($dir);
                $this->fail("$name: accepted");
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage(), $name);
            }
        }
        $dir = $this->copyOfSrc();
        unlink("$dir/src/noneDBQuery/Chain.php");
        $this->expectException(\RuntimeException::class);
        nonedb_build($dir);
    }

    /** @test class files: one class named like the file, in a noneDB\Internal namespace, valid PHP */
    public function malformedClassFilesAreRejected(): void
    {
        $cases = [
            'CR line ends' => function (string $s) { return str_replace("\n", "\r\n", $s); },
            'another namespace' => function (string $s) { return str_replace('namespace noneDB\\Internal\\Query;', 'namespace Other;', $s); },
            'no namespace' => function (string $s) { return str_replace("namespace noneDB\\Internal\\Query;\n", '', $s); },
            'class named otherwise' => function (string $s) { return str_replace('final class Planner {', 'final class Planned {', $s); },
            'a second class' => function (string $s) { return $s . "class Extra {\n}\n"; },
            'a trait' => function (string $s) { return $s . "trait Extra {\n}\n"; },
            'syntax error' => function (string $s) { return preg_replace('/public function (\w+)\(/', 'public function $1(,', $s, 1); },
        ];
        foreach ($cases as $name => $mutate) {
            $dir = $this->copyOfSrc();
            $file = "$dir/src/Internal/Query/Planner.php";
            $before = (string)file_get_contents($file);
            $after = $mutate($before);
            $this->assertNotSame($before, $after, "$name: the mutation applies");
            file_put_contents($file, $after);
            try {
                nonedb_build($dir);
                $this->fail("$name: accepted");
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage(), $name);
            }
        }
        $dir = $this->copyOfSrc();
        unlink("$dir/src/Internal/Query/Planner.php");
        $this->expectException(\RuntimeException::class);
        nonedb_build($dir);
    }
}
