<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\Unit;

use HenkPoley\DocBlockDoctor\Application;
use HenkPoley\DocBlockDoctor\ApplicationOptions;
use HenkPoley\DocBlockDoctor\FileSystem;
use HenkPoley\DocBlockDoctor\AstParser;
use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\GlobalCache;
use HenkPoley\DocBlockDoctor\DocBlockUpdater;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PHPUnit\Framework\TestCase;

class ApplicationUpdateFilesTest extends TestCase
{
    private function makeAstParser(): AstParser
    {
        return new class implements AstParser {
            private $parser;
            public function __construct()
            {
                $this->parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
            }
            public function parse(string $code): ?array
            {
                return $this->parser->parse($code);
            }
            public function traverse(array $ast, array $visitors): void
            {
                $tr = new NodeTraverser();
                foreach ($visitors as $v) {
                    $tr->addVisitor($v);
                }
                $tr->traverse($ast);
            }
        };
    }

    public function testUpdateFilesAppliesPatch(): void
    {
        $code = "<?php\nfunction foo() {}\n";
        $file = sys_get_temp_dir() . '/upd-' . uniqid() . '.php';
        file_put_contents($file, $code);

        $fs = new class($file, $code) implements FileSystem {
            public array $files;
            public array $written = [];
            public function __construct(private string $path, string $code) { $this->files = [$path => $code]; }
            public function getContents(string $path): string|false { return $this->files[$path] ?? false; }
            public function putContents(string $path, string $contents): bool { $this->written[$path] = $contents; $this->files[$path] = $contents; return true; }
            public function isFile(string $path): bool { return isset($this->files[$path]); }
            public function isDir(string $path): bool { return false; }
            public function realPath(string $path): string|false { return $path; }
            public function getCurrentWorkingDirectory(): string|false { return sys_get_temp_dir(); }
        };

        $astParser = $this->makeAstParser();
        GlobalCache::clear();
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $func = $ast[0];
        GlobalCache::$astNodeMap['foo'] = $func;
        GlobalCache::$nodeKeyToFilePath['foo'] = $file;
        GlobalCache::$fileNamespaces[$file] = '';
        GlobalCache::$resolvedThrows['foo'] = ['RuntimeException'];

        $app = new Application($fs, $astParser);
        $ref = new \ReflectionMethod(Application::class, 'updateFiles');
        $ref->setAccessible(true);
        $opt = new ApplicationOptions();
        $opt->writeDirs = [dirname($file)];
        $opt->verbose = false;
        $result = $ref->invoke($app, [$file], new AstUtils(), $opt);

        $this->assertSame([$file], $result);
        $this->assertStringContainsString('@throws \\RuntimeException', $fs->files[$file]);
    }

    public function testUpdateFilesWriteFailureStillReturnsFile(): void
    {
        $code = "<?php\nfunction foo() {}\n";
        $file = sys_get_temp_dir() . '/upd-' . uniqid() . '.php';
        file_put_contents($file, $code);

        $fs = new class($file, $code) implements FileSystem {
            public array $files;
            public bool $fail = true;
            public array $written = [];
            public function __construct(private string $path, string $code) { $this->files = [$path => $code]; }
            public function getContents(string $path): string|false { return $this->files[$path] ?? false; }
            public function putContents(string $path, string $contents): bool { $this->written[$path] = $contents; return !$this->fail; }
            public function isFile(string $path): bool { return isset($this->files[$path]); }
            public function isDir(string $path): bool { return false; }
            public function realPath(string $path): string|false { return $path; }
            public function getCurrentWorkingDirectory(): string|false { return sys_get_temp_dir(); }
        };

        $astParser = $this->makeAstParser();
        GlobalCache::clear();
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $func = $ast[0];
        GlobalCache::$astNodeMap['foo'] = $func;
        GlobalCache::$nodeKeyToFilePath['foo'] = $file;
        GlobalCache::$fileNamespaces[$file] = '';
        GlobalCache::$resolvedThrows['foo'] = ['RuntimeException'];

        $app = new Application($fs, $astParser);
        $ref = new \ReflectionMethod(Application::class, 'updateFiles');
        $ref->setAccessible(true);
        $opt = new ApplicationOptions();
        $opt->writeDirs = [dirname($file)];
        $opt->verbose = false;
        $result = $ref->invoke($app, [$file], new AstUtils(), $opt);

        $this->assertSame([$file], $result);
        $this->assertSame($code, $fs->files[$file]);
    }
}
