<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\NewIntegration;

use HenkPoley\DocBlockDoctor\Application;
use HenkPoley\DocBlockDoctor\FileSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApplicationRunFixtureProjectsTest extends TestCase
{
    #[DataProvider('projectProvider')]
    public function testRunRewritesFixtures(string $scenario, array $files): void
    {
        $srcDir = __DIR__ . '/../fixtures/' . $scenario;
        $tmpRoot = sys_get_temp_dir() . '/docblockdoctor-run-' . uniqid();
        mkdir($tmpRoot);
        foreach ($files as $file) {
            copy($srcDir . '/' . $file, $tmpRoot . '/' . $file);
        }

        $app = new Application();
        ob_start();
        $app->run(['doc-block-doctor', '--verbose', $tmpRoot]);
        $output = ob_get_clean();
        $this->assertStringContainsString('=== Summary ===', $output);

        foreach ($files as $file) {
            $expectedPath = $srcDir . '/expected_' . $file;
            $expected     = file_get_contents($expectedPath);
            $this->assertNotFalse(
                $expected,
                'Failed to read expected file: ' . $expectedPath
            );
            $actualPath = $tmpRoot . '/' . $file;
            $actual     = file_get_contents($actualPath);
            $this->assertNotFalse(
                $actual,
                'Failed to read temporary file: ' . $actualPath
            );
            $this->assertSame(
                $expected,
                $actual,
                $actualPath . ' mismatch'
            );
            unlink($tmpRoot . '/' . $file);
        }
        rmdir($tmpRoot);
    }

    public static function projectProvider(): array
    {
        return [
            ['app-run-basic', ['Foo.php', 'Bar.php']],
        ];
    }

    public function testRunHandlesMissingFileGracefully(): void
    {
        $srcDir = __DIR__ . '/../fixtures/app-run-basic';
        $tmpRoot = sys_get_temp_dir() . '/docblockdoctor-run-' . uniqid();
        mkdir($tmpRoot);
        copy($srcDir . '/Foo.php', $tmpRoot . '/Foo.php');

        $failPath = realpath($tmpRoot . '/Foo.php') ?: ($tmpRoot . '/Foo.php');
        $fs = new class($failPath) implements FileSystem {
            private string $failPath;
            public function __construct(string $failPath) { $this->failPath = $failPath; }
            public function getContents(string $path): string|false { return $path === $this->failPath ? false : @file_get_contents($path); }
            public function putContents(string $path, string $contents): bool { return file_put_contents($path, $contents) !== false; }
            public function isFile(string $path): bool { return is_file($path); }
            public function isDir(string $path): bool { return is_dir($path); }
            public function realPath(string $path): string|false { return realpath($path); }
            public function getCurrentWorkingDirectory(): string|false { return getcwd(); }
        };

        $app = new Application($fs);
        ob_start();
        $app->run(['doc-block-doctor', '--verbose', $tmpRoot]);
        $output = ob_get_clean();

        $this->assertStringContainsString('Cannot read file', $output);
        $result = file_get_contents($tmpRoot . '/Foo.php');
        $original = file_get_contents($srcDir . '/Foo.php');
        $this->assertSame($original, $result);

        unlink($tmpRoot . '/Foo.php');
        rmdir($tmpRoot);
    }

    public function testRunHandlesWriteError(): void
    {
        $srcDir = __DIR__ . '/../fixtures/app-run-basic';
        $tmpRoot = sys_get_temp_dir() . '/docblockdoctor-run-' . uniqid();
        mkdir($tmpRoot);
        copy($srcDir . '/Foo.php', $tmpRoot . '/Foo.php');

        $failPath = realpath($tmpRoot . '/Foo.php') ?: ($tmpRoot . '/Foo.php');
        $fs = new class($failPath) implements FileSystem {
            private string $failPath;
            public function __construct(string $failPath) { $this->failPath = $failPath; }
            public function getContents(string $path): string|false { return @file_get_contents($path); }
            public function putContents(string $path, string $contents): bool {
                if ($path === $this->failPath) {
                    return false;
                }
                return file_put_contents($path, $contents) !== false;
            }
            public function isFile(string $path): bool { return is_file($path); }
            public function isDir(string $path): bool { return is_dir($path); }
            public function realPath(string $path): string|false { return realpath($path); }
            public function getCurrentWorkingDirectory(): string|false { return getcwd(); }
        };

        $app = new Application($fs);
        ob_start();
        $app->run(['doc-block-doctor', '--verbose', $tmpRoot]);
        ob_get_clean();

        $result = file_get_contents($tmpRoot . '/Foo.php');
        $original = file_get_contents($srcDir . '/Foo.php');
        $this->assertSame($original, $result);

        unlink($tmpRoot . '/Foo.php');
        rmdir($tmpRoot);
    }

    public function testRunHandlesMalformedPhp(): void
    {
        $srcDir = __DIR__ . '/../fixtures/app-run-malformed';
        $tmpRoot = sys_get_temp_dir() . '/docblockdoctor-run-' . uniqid();
        mkdir($tmpRoot);
        copy($srcDir . '/Broken.php', $tmpRoot . '/Broken.php');

        $app = new Application();
        ob_start();
        $app->run(['doc-block-doctor', '--verbose', $tmpRoot]);
        $output = ob_get_clean();

        $this->assertStringContainsString('Parse error (Pass 1)', $output);
        $result = file_get_contents($tmpRoot . '/Broken.php');
        $original = file_get_contents($srcDir . '/Broken.php');
        $this->assertSame($original, $result);

        unlink($tmpRoot . '/Broken.php');
        rmdir($tmpRoot);
    }
}
