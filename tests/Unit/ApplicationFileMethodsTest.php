<?php
declare(strict_types=1);

use HenkPoley\DocBlockDoctor\Application;
use HenkPoley\DocBlockDoctor\ApplicationOptions;
use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\FileSystem;
use HenkPoley\DocBlockDoctor\GlobalCache;
use HenkPoley\DocBlockDoctor\PhpParserAstParser;
use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;

class ApplicationFileMethodsTest extends TestCase
{
    private static function invokeCollect(Application $app, ApplicationOptions $opt): array
    {
        $ref = new \ReflectionMethod(Application::class, 'collectPhpFiles');
        $ref->setAccessible(true);
        /** @var string[] $files */
        $files = $ref->invoke($app, $opt);
        sort($files);
        return $files;
    }

    public function testCollectPhpFilesSkipsIgnoredDirs(): void
    {
        $tmp = sys_get_temp_dir() . '/collect_' . uniqid();
        mkdir($tmp . '/src', 0777, true);
        mkdir($tmp . '/.git');
        mkdir($tmp . '/node_modules');
        mkdir($tmp . '/cache');
        mkdir($tmp . '/sub/cache', 0777, true);

        file_put_contents($tmp . '/root.php', '<?php');
        file_put_contents($tmp . '/src/included.php', '<?php');
        file_put_contents($tmp . '/src/ignore.txt', 'no');
        file_put_contents($tmp . '/.git/git.php', '<?php');
        file_put_contents($tmp . '/node_modules/node.php', '<?php');
        file_put_contents($tmp . '/cache/cache.php', '<?php');
        file_put_contents($tmp . '/sub/cache/inner.php', '<?php');

        $app = new Application();
        $opt = new ApplicationOptions();
        $opt->rootDir = $tmp;
        $opt->readDirs = [$tmp];

        $files = self::invokeCollect($app, $opt);

        $expected = [realpath($tmp . '/root.php'), realpath($tmp . '/src/included.php')];
        sort($expected);
        $this->assertSame($expected, $files);

        // cleanup
        unlink($tmp . '/root.php');
        unlink($tmp . '/src/included.php');
        unlink($tmp . '/src/ignore.txt');
        unlink($tmp . '/.git/git.php');
        unlink($tmp . '/node_modules/node.php');
        unlink($tmp . '/cache/cache.php');
        unlink($tmp . '/sub/cache/inner.php');
        rmdir($tmp . '/src');
        rmdir($tmp . '/.git');
        rmdir($tmp . '/node_modules');
        rmdir($tmp . '/cache');
        rmdir($tmp . '/sub/cache');
        rmdir($tmp . '/sub');
        rmdir($tmp);
    }

    private static function invokeUpdate(Application $app, array $files, AstUtils $utils, ApplicationOptions $opt): array
    {
        $ref = new \ReflectionMethod(Application::class, 'updateFiles');
        $ref->setAccessible(true);
        /** @var string[] $out */
        $out = $ref->invoke($app, $files, $utils, $opt);
        return $out;
    }

    private function prepareGlobalCache(string $file, string $code, array $resolvedThrows = ['RuntimeException']): void
    {
        GlobalCache::clear();
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $tr = new NodeTraverser();
        $tr->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $tr->addVisitor(new ParentConnectingVisitor());
        $tr->traverse($ast);
        $func = $ast[0];
        GlobalCache::$astNodeMap['foo'] = $func;
        GlobalCache::$nodeKeyToFilePath['foo'] = $file;
        GlobalCache::setFileNamespace($file, '');
        GlobalCache::setFileUseMap($file, []);
        GlobalCache::$resolvedThrows['foo'] = $resolvedThrows;
    }

    public function testUpdateFilesWritesPatchedContent(): void
    {
        $code = "<?php\nfunction foo() { throw new \\RuntimeException(); }\n";
        $file = '/tmp/patch_' . uniqid() . '.php';
        $fs = new class($file, $code) implements FileSystem {
            public array $files; public int $writes = 0; public bool $fail = false;
            public function __construct(string $f, string $c) { $this->files = [$f => $c]; }
            public function getContents(string $path): string|false { return $this->files[$path] ?? false; }
            public function putContents(string $path, string $contents): bool { $this->writes++; if ($this->fail) return false; $this->files[$path] = $contents; return true; }
            public function isFile(string $path): bool { return isset($this->files[$path]); }
            public function isDir(string $path): bool { return true; }
            public function realPath(string $path): string|false { return $path; }
            public function getCurrentWorkingDirectory(): string|false { return '/'; }
        };
        $parser = new PhpParserAstParser();
        $app = new Application($fs, $parser);
        $this->prepareGlobalCache($file, $code);
        $opt = new ApplicationOptions();
        $opt->writeDirs = [dirname($file)];
        $opt->quiet = true;
        $utils = new AstUtils();
        $filesFixed = self::invokeUpdate($app, [$file], $utils, $opt);
        $this->assertSame([$file], $filesFixed);
        $this->assertGreaterThan(0, $fs->writes);
        $this->assertStringContainsString('@throws \\RuntimeException', $fs->files[$file]);
    }

    public function testUpdateFilesStopsAfterWriteFailures(): void
    {
        $code = "<?php\nfunction foo() { throw new \\RuntimeException(); }\n";
        $file = '/tmp/patch_' . uniqid() . '.php';
        $fs = new class($file, $code) implements FileSystem {
            public array $files; public int $writes = 0; public bool $fail = true;
            public function __construct(string $f, string $c) { $this->files = [$f => $c]; }
            public function getContents(string $path): string|false { return $this->files[$path] ?? false; }
            public function putContents(string $path, string $contents): bool { $this->writes++; return false; }
            public function isFile(string $path): bool { return isset($this->files[$path]); }
            public function isDir(string $path): bool { return true; }
            public function realPath(string $path): string|false { return $path; }
            public function getCurrentWorkingDirectory(): string|false { return '/'; }
        };
        $parser = new PhpParserAstParser();
        $app = new Application($fs, $parser);
        $this->prepareGlobalCache($file, $code);
        $opt = new ApplicationOptions();
        $opt->writeDirs = [dirname($file)];
        $utils = new AstUtils();
        ob_start();
        $filesFixed = self::invokeUpdate($app, [$file], $utils, $opt);
        $out = ob_get_clean();
        $this->assertSame([$file], $filesFixed);
        $this->assertGreaterThanOrEqual(3, $fs->writes);
        $this->assertSame($code, $fs->files[$file]);
        $this->assertStringContainsString('Warning: Max iterations for file', $out);
    }

    public function testUpdateFilesRemovesDocBlock(): void
    {
        $code = "<?php\n/**\n * @throws \\RuntimeException\n */\nfunction foo() {}\n";
        $file = '/tmp/patch_' . uniqid() . '.php';
        $fs = new class($file, $code) implements FileSystem {
            public array $files; public int $writes = 0; public bool $fail = false;
            public function __construct(string $f, string $c) { $this->files = [$f => $c]; }
            public function getContents(string $path): string|false { return $this->files[$path] ?? false; }
            public function putContents(string $path, string $contents): bool {
                $this->writes++; if ($this->fail) return false; $this->files[$path] = $contents; return true;
            }
            public function isFile(string $path): bool { return isset($this->files[$path]); }
            public function isDir(string $path): bool { return true; }
            public function realPath(string $path): string|false { return $path; }
            public function getCurrentWorkingDirectory(): string|false { return '/'; }
        };
        $parser = new PhpParserAstParser();
        $app = new Application($fs, $parser);
        $this->prepareGlobalCache($file, $code, []);
        $opt = new ApplicationOptions();
        $opt->writeDirs = [dirname($file)];
        $opt->quiet = true;
        $opt->ignoreAnnotatedThrows = true;
        $utils = new AstUtils();
        $filesFixed = self::invokeUpdate($app, [$file], $utils, $opt);
        $this->assertSame([$file], $filesFixed);
        $this->assertGreaterThan(0, $fs->writes);
        $this->assertSame("<?php\n\nfunction foo() {}\n", $fs->files[$file]);
    }

    public function testResolveDirectoriesHandlesDefaultsAndRelative(): void
    {
        $tmp = sys_get_temp_dir() . '/resolve_' . uniqid();
        mkdir($tmp . '/src', 0777, true);
        mkdir($tmp . '/tests');
        mkdir($tmp . '/vendor');

        $app = new Application();
        $method = new \ReflectionMethod(Application::class, 'resolveDirectories');
        $method->setAccessible(true);

        $opt = new ApplicationOptions();
        $opt->rootDir = $tmp;
        $method->invoke($app, $opt);
        $this->assertSame([
            $tmp . '/src',
            $tmp . '/tests',
            $tmp . '/vendor',
        ], $opt->readDirs);
        $this->assertSame([
            $tmp . '/src',
        ], $opt->writeDirs);

        $opt2 = new ApplicationOptions();
        $opt2->rootDir = $tmp;
        $opt2->readDirs = ['src', 'tests'];
        $opt2->writeDirs = ['tests'];
        $method->invoke($app, $opt2);
        $this->assertSame([
            $tmp . '/src',
            $tmp . '/tests',
        ], $opt2->readDirs);
        $this->assertSame([
            $tmp . '/tests',
        ], $opt2->writeDirs);

        rmdir($tmp . '/src');
        rmdir($tmp . '/tests');
        rmdir($tmp . '/vendor');
        rmdir($tmp);
    }

    public function testResolveThrowsGloballyUpdatesCache(): void
    {
        $code = "<?php\nfunction foo(){ throw new \\RuntimeException(); }\n";
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8,4));
        $ast = $parser->parse($code) ?: [];
        $func = $ast[0];

        GlobalCache::clear();
        GlobalCache::$astNodeMap['foo'] = $func;
        GlobalCache::$nodeKeyToFilePath['foo'] = 'dummy.php';
        GlobalCache::setFileNamespace('dummy.php', '');
        GlobalCache::setFileUseMap('dummy.php', []);
        GlobalCache::$directThrows['foo'] = ['RuntimeException'];
        GlobalCache::$resolvedThrows['foo'] = [];

        $app = new Application();
        $method = new \ReflectionMethod(Application::class, 'resolveThrowsGlobally');
        $method->setAccessible(true);
        $finder = new NodeFinder();
        $utils = new AstUtils();
        $opt = new ApplicationOptions();
        ob_start();
        $method->invoke($app, $finder, $utils, $opt);
        ob_end_clean();

        $this->assertSame(['RuntimeException'], GlobalCache::$resolvedThrows['foo']);
    }
}
