<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\Unit;

use HenkPoley\DocBlockDoctor\Application;
use HenkPoley\DocBlockDoctor\ApplicationOptions;
use HenkPoley\DocBlockDoctor\AstParser;
use HenkPoley\DocBlockDoctor\FileSystem;
use HenkPoley\DocBlockDoctor\AstUtils;
use PhpParser\Error;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;

class ApplicationDependencyInjectionTest extends TestCase
{
    public function testProcessFilesPass1HandlesParseError(): void
    {
        $fs = new class implements FileSystem {
            public function getContents(string $path): string|false { return '<?php echo "hi";'; }
            public function putContents(string $path, string $contents): bool { return true; }
            public function isFile(string $path): bool { return true; }
            public function isDir(string $path): bool { return true; }
            public function realPath(string $path): string|false { return $path; }
            public function getCurrentWorkingDirectory(): string|false { return '/tmp'; }
        };
        $parser = new class implements AstParser {
            public function parse(string $code): ?array { throw new Error('fail'); }
            public function traverse(array $ast, array $visitors): void {}
        };

        $app = new Application($fs, $parser);
        $ref = new \ReflectionMethod(Application::class, 'processFilesPass1');
        $ref->setAccessible(true);
        $nodeFinder = new NodeFinder();
        $astUtils   = new AstUtils();
        $opt = new ApplicationOptions();
        ob_start();
        $ref->invoke($app, ['/tmp/test.php'], $nodeFinder, $astUtils, $opt);
        $output = ob_get_clean();
        $this->assertStringContainsString('Parse error (Pass 1)', $output);
    }

    public function testProcessFilesPass1HandlesReadError(): void
    {
        $fs = new class implements FileSystem {
            public function getContents(string $path): string|false { return false; }
            public function putContents(string $path, string $contents): bool { return true; }
            public function isFile(string $path): bool { return true; }
            public function isDir(string $path): bool { return true; }
            public function realPath(string $path): string|false { return $path; }
            public function getCurrentWorkingDirectory(): string|false { return '/tmp'; }
        };
        $called = false;
        $parser = new class($called) implements AstParser {
            public bool $called = false;
            public function __construct(&$flag){$this->called =& $flag;}
            public function parse(string $code): ?array { $this->called = true; return []; }
            public function traverse(array $ast, array $visitors): void {}
        };

        $app = new Application($fs, $parser);
        $ref = new \ReflectionMethod(Application::class, 'processFilesPass1');
        $ref->setAccessible(true);
        $nodeFinder = new NodeFinder();
        $astUtils   = new AstUtils();
        $opt = new ApplicationOptions();
        ob_start();
        $ref->invoke($app, ['/tmp/test.php'], $nodeFinder, $astUtils, $opt);
        $output = ob_get_clean();
        $this->assertStringContainsString('Cannot read file', $output);
        $this->assertFalse($parser->called, 'Parser should not be called when file read fails');
    }
}
