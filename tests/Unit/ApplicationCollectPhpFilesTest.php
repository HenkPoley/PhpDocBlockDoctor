<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\Unit;

use HenkPoley\DocBlockDoctor\Application;
use HenkPoley\DocBlockDoctor\ApplicationOptions;
use PHPUnit\Framework\TestCase;

class ApplicationCollectPhpFilesTest extends TestCase
{
    public function testCollectPhpFilesRecursesAndFilters(): void
    {
        $base = sys_get_temp_dir() . '/collect-' . uniqid();
        mkdir($base . '/sub', 0777, true);
        file_put_contents($base . '/a.php', '<?php');
        file_put_contents($base . '/b.txt', '');
        file_put_contents($base . '/sub/c.php', '<?php');
        mkdir($base . '/sub/node_modules');
        file_put_contents($base . '/sub/node_modules/d.php', '<?php');
        mkdir($base . '/.git');
        file_put_contents($base . '/.git/e.php', '<?php');

        $opt = new ApplicationOptions();
        $opt->readDirs = [$base . '/a.php', $base];

        $app = new Application();
        $ref = new \ReflectionMethod(Application::class, 'collectPhpFiles');
        $ref->setAccessible(true);
        $files = $ref->invoke($app, $opt);

        sort($files);
        $expected = [realpath($base . '/a.php'), realpath($base . '/sub/c.php')];
        sort($expected);
        $this->assertSame($expected, $files);
    }
}
