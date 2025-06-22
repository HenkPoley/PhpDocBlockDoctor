<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\NewIntegration;

use HenkPoley\DocBlockDoctor\Application;
use HenkPoley\DocBlockDoctor\ApplicationOptions;
use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\GlobalCache;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThrowsResolutionIntegrationTest extends TestCase
{
    /**
     * @throws \LogicException
     */
    #[DataProvider('fixtureProvider')]
    public function testResolvedThrowsMatchFixture(string $scenario, bool $ignoreAnnotated): void
    {
        // Register an autoloader so class existence checks succeed for fixtures
        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('Pitfalls\\', __DIR__ . '/../fixtures');
        $scenarioNs = 'Pitfalls\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $scenario))) . '\\';
        $loader->addPsr4($scenarioNs, __DIR__ . '/../fixtures/' . $scenario);
        $loader->register(false);

        $fixtureRoot = __DIR__ . '/../fixtures/' . $scenario;

        $phpFiles = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $fixtureRoot,
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $phpFiles[] = $file->getPathname();
            }
        }

        $this->runApplicationPhases($phpFiles, $ignoreAnnotated);

        $expectedFile = $fixtureRoot . '/expected_results.json';
        $this->assertFileExists($expectedFile);
        $expectedData = json_decode(file_get_contents($expectedFile), true, 512, JSON_THROW_ON_ERROR);
        $allResolved = GlobalCache::getAllResolvedThrows();
        foreach ($expectedData['fullyQualifiedMethodKeys'] as $methodKey => $throws) {
            $this->assertArrayHasKey($methodKey, $allResolved, $methodKey . ' missing');
            $this->assertEqualsCanonicalizing($throws, GlobalCache::getResolvedThrowsForKey($methodKey), $methodKey);
        }
    }

    public static function fixtureProvider(): array
    {
        $fixturesRoot = __DIR__ . '/../fixtures';
        $scenarios = [];
        foreach (new \DirectoryIterator($fixturesRoot) as $fi) {
            if ($fi->isDot() || !$fi->isDir()) {
                continue;
            }
            if (str_starts_with($fi->getFilename(), 'app-run-malformed')) {
                // Integration tests for malformed PHP are handled elsewhere
                continue;
            }

            // TODO: Should only ignore the RuntimeException on \HenkPoley\DocBlockDoctor\TestFixtures\ConstructorThrows\ThrowsInConstructor::createAndCall
            $ignore = $fi->getFilename() === 'ThrowsInConstructor.php';
            $scenarios[] = [$fi->getFilename(), $ignore];
        }
        return $scenarios;
    }

    private function runApplicationPhases(array $phpFiles, bool $ignoreAnnotated): void
    {
        $app = new Application(new \HenkPoley\DocBlockDoctor\NativeFileSystem(), new \HenkPoley\DocBlockDoctor\PhpParserAstParser());
        $opts = new ApplicationOptions();
        $opts->ignoreAnnotatedThrows = $ignoreAnnotated;
        $opts->quiet = true;

        $finder = new NodeFinder();
        $utils  = new AstUtils();

        $ref = new \ReflectionClass($app);
        $pass1 = $ref->getMethod('processFilesPass1');
        $pass1->setAccessible(true);
        $resolve = $ref->getMethod('resolveThrowsGlobally');
        $resolve->setAccessible(true);

        $pass1->invoke($app, $phpFiles, $finder, $utils, $opts);
        $resolve->invoke($app, $finder, $utils, $opts);
    }

}
