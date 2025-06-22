<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\NewIntegration;

use HenkPoley\DocBlockDoctor\Application;
use HenkPoley\DocBlockDoctor\GlobalCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Composer\Autoload\ClassLoader;

class ThrowsAnnotationNecessityTest extends TestCase
{
    #[DataProvider('scenarioProvider')]
    public function testRemovingThrowsAnnotationsChangesResults(string $scenario): void
    {
        $fixturesRoot = __DIR__ . '/../fixtures';
        $origDir = $fixturesRoot . '/' . $scenario;
        $tmpRoot = sys_get_temp_dir() . '/doctor-annot-' . uniqid();
        mkdir($tmpRoot);
        $tmpScenario = $tmpRoot . '/' . $scenario;
        $this->copyFixtureWithoutThrows($origDir, $tmpScenario);

        $loader = new ClassLoader();
        $loader->addPsr4('Pitfalls\\', $tmpRoot);
        $scenarioNs = 'Pitfalls\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $scenario))) . '\\';
        $loader->addPsr4($scenarioNs, $tmpScenario);
        $loader->register(false);

        $args = [
            'doc-block-doctor',
            '--quiet',
            '--read-dirs=' . $tmpScenario,
            '--write-dirs=' . $tmpScenario,
        ];
        if (str_starts_with($scenario, 'ignore-throws-annotations')) {
            $args[] = '--ignore-annotated-throws';
        }
        $args[] = $tmpScenario;

        $app = new Application();
        $app->run($args);

        $expected = json_decode(file_get_contents($origDir . '/expected_results.json'), true, 512, JSON_THROW_ON_ERROR);
        $actual = GlobalCache::getAllResolvedThrows();

        $matches = $this->compareResults($expected['fullyQualifiedMethodKeys'], $actual);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmpRoot, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            if ($f->isFile()) {
                unlink($f->getPathname());
            } else {
                rmdir($f->getPathname());
            }
        }
        rmdir($tmpRoot);

        $this->assertFalse($matches, $scenario . ' still matches after removing @throws annotations');
    }

    private function compareResults(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $throws) {
            sort($throws);
            $act = $actual[$key] ?? [];
            sort($act);
            if ($act !== $throws) {
                return false;
            }
        }
        if (count($actual) !== count($expected)) {
            return false;
        }
        return true;
    }

    private function copyFixtureWithoutThrows(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0777, true);
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $target = $dst . '/' . $it->getSubPathName();
            if ($file->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0777, true);
                }
                continue;
            }
            if ($file->getExtension() === 'php' && !str_starts_with($file->getFilename(), 'expected_')) {
                $code = file_get_contents($file->getPathname());
                $code = preg_replace('/^\s*\*\s*@throws.*\R?/m', '', $code);
                file_put_contents($target, $code);
            } else {
                copy($file->getPathname(), $target);
            }
        }
    }

    public static function scenarioProvider(): array
    {
        $root = __DIR__ . '/../fixtures';
        $scenarios = [];
        foreach (new \DirectoryIterator($root) as $fi) {
            if ($fi->isDot() || !$fi->isDir()) {
                continue;
            }
            if (str_starts_with($fi->getFilename(), 'app-run-malformed')) {
                continue;
            }
            $scenarios[] = [$fi->getFilename()];
        }
        return $scenarios;
    }
}
