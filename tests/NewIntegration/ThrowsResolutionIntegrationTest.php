<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\NewIntegration;

use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\GlobalCache;
use HenkPoley\DocBlockDoctor\ThrowsGatherer;
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
    #[DataProvider('fixtureProvider')]
    public function testResolvedThrowsMatchFixture(string $scenario): void
    {
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

        GlobalCache::clear();
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $finder = new NodeFinder();
        $utils  = new AstUtils();

        foreach ($phpFiles as $path) {
            $code = @file_get_contents($path);
            if ($code === false) {
                continue;
            }
            $ast = $parser->parse($code) ?: [];
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
            $traverser->addVisitor(new ParentConnectingVisitor());
            $traverser->addVisitor(new ThrowsGatherer($finder, $utils, $path));
            $traverser->traverse($ast);
        }

        foreach (array_keys(GlobalCache::$astNodeMap) as $key) {
            $direct = GlobalCache::$directThrows[$key] ?? [];
            $annotated = GlobalCache::$annotatedThrows[$key] ?? [];
            $combined = array_values(array_unique(array_merge($direct, $annotated)));
            sort($combined);
            GlobalCache::$resolvedThrows[$key] = $combined;
        }

        $maxIter = count(GlobalCache::$astNodeMap) + 5;
        $iteration = 0;
        do {
            $changed = false;
            $iteration++;
            foreach (GlobalCache::$astNodeMap as $methodKey => $node) {
                $filePath = GlobalCache::$nodeKeyToFilePath[$methodKey];
                $namespace = GlobalCache::$fileNamespaces[$filePath] ?? '';
                $useMap    = GlobalCache::$fileUseMaps[$filePath] ?? [];

                $baseThrows = array_values(array_unique(array_merge(
                    GlobalCache::$directThrows[$methodKey]    ?? [],
                    GlobalCache::$annotatedThrows[$methodKey] ?? []
                )));
                $throwsFromCallees = [];
                if (isset($node->stmts) && is_array($node->stmts)) {
                    $calls = array_merge(
                        $finder->findInstanceOf($node->stmts, \PhpParser\Node\Expr\MethodCall::class),
                        $finder->findInstanceOf($node->stmts, \PhpParser\Node\Expr\StaticCall::class),
                        $finder->findInstanceOf($node->stmts, \PhpParser\Node\Expr\FuncCall::class),
                        $finder->findInstanceOf($node->stmts, \PhpParser\Node\Expr\New_::class),
                    );
                    foreach ($calls as $call) {
                        $calleeKey = $utils->getCalleeKey($call, $namespace, $useMap, $node);
                        if ($calleeKey && $calleeKey !== $methodKey) {
                            foreach (GlobalCache::$resolvedThrows[$calleeKey] ?? [] as $ex) {
                                $throwsFromCallees[] = $ex;
                            }
                        }
                    }
                }
                $newThrows = array_values(array_unique(array_merge($baseThrows, $throwsFromCallees)));
                sort($newThrows);
                if ($newThrows !== (GlobalCache::$resolvedThrows[$methodKey] ?? [])) {
                    GlobalCache::$resolvedThrows[$methodKey] = $newThrows;
                    $changed = true;
                }
            }
        } while ($changed && $iteration < $maxIter);

        $expectedFile = $fixtureRoot . '/expected_results.json';
        $this->assertFileExists($expectedFile);
        $expectedData = json_decode(file_get_contents($expectedFile), true, 512, JSON_THROW_ON_ERROR);
        foreach ($expectedData['fullyQualifiedMethodKeys'] as $methodKey => $throws) {
            $this->assertArrayHasKey($methodKey, GlobalCache::$resolvedThrows, $methodKey . ' missing');
            $this->assertEqualsCanonicalizing($throws, GlobalCache::$resolvedThrows[$methodKey], $methodKey);
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
            $scenarios[] = [$fi->getFilename()];
        }
        return $scenarios;
    }
}
