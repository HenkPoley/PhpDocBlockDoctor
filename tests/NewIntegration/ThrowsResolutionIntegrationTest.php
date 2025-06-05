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
    /**
     * @throws \LogicException
     */
    #[DataProvider('fixtureProvider')]
    public function testResolvedThrowsMatchFixture(string $scenario): void
    {
        // Register an autoloader so class existence checks succeed for fixtures
        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('Pitfalls\\', __DIR__ . '/../fixtures');
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
                        if ($this->isNodeWithinTry($call, $node)) {
                            // assume all exceptions from this call are caught
                            continue;
                        }
                        if ($utils->isNodeAfterExecutionEndingStmt($call, $node)) {
                            continue;
                        }
                        $calleeKey = null;
                        if (
                            $call instanceof \PhpParser\Node\Expr\MethodCall &&
                            $call->var instanceof \PhpParser\Node\Expr\New_ &&
                            $call->var->class instanceof \PhpParser\Node\Name &&
                            $call->name instanceof \PhpParser\Node\Identifier
                        ) {
                            $objClass = $utils->resolveNameNodeToFqcn(
                                $call->var->class,
                                $namespace,
                                $useMap,
                                false
                            );
                            if ($objClass !== '') {
                                $calleeKey = ltrim($objClass, '\\') . '::' . $call->name->toString();
                            }
                        } elseif (
                            $call instanceof \PhpParser\Node\Expr\MethodCall &&
                            $call->var instanceof \PhpParser\Node\Expr\Variable &&
                            $call->name instanceof \PhpParser\Node\Identifier
                        ) {
                            $assignExpr = $this->findAssignmentForVariable($node->stmts ?? [], $call->var, $call);
                            if ($assignExpr) {
                                $type = $this->resolveAssignedExprType($assignExpr, $namespace, $useMap, $node, $utils, $finder);
                                if ($type) {
                                    $calleeKey = ltrim($type, '\\') . '::' . $call->name->toString();
                                }
                            }
                        }
                        if ($calleeKey === null) {
                            $calleeKey = $utils->getCalleeKey($call, $namespace, $useMap, $node);
                        }
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

    private function isNodeWithinTry(\PhpParser\Node $node, \PhpParser\Node $boundary): bool
    {
        $parent = $node->getAttribute('parent');
        while ($parent && $parent !== $boundary) {
            if ($parent instanceof \PhpParser\Node\Stmt\TryCatch) {
                return true;
            }
            if ($parent instanceof \PhpParser\Node\FunctionLike && $parent !== $boundary) {
                break;
            }
            $parent = $parent->getAttribute('parent');
        }
        return false;
    }

    /**
     * @throws \LogicException
     */
    private function findAssignmentForVariable(array $stmts, \PhpParser\Node\Expr\Variable $var, \PhpParser\Node $boundary): ?\PhpParser\Node\Expr
    {
        $name = is_string($var->name) ? $var->name : null;
        if ($name === null) {
            return null;
        }
        $finder = new NodeFinder();
        $assigns = $finder->findInstanceOf($stmts, \PhpParser\Node\Expr\Assign::class);
        $best = null;
        $bestPos = -1;
        foreach ($assigns as $assign) {
            if ($assign->var instanceof \PhpParser\Node\Expr\Variable && $assign->var->name === $name) {
                $pos = $assign->getStartFilePos() ?? -1;
                $callPos = $boundary->getStartFilePos() ?? PHP_INT_MAX;
                if ($pos !== null && $pos < $callPos && $pos > $bestPos) {
                    $best = $assign->expr;
                    $bestPos = $pos;
                }
            }
        }
        return $best;
    }

    /**
     * @throws \LogicException
     */
    private function resolveAssignedExprType(\PhpParser\Node\Expr $expr, string $namespace, array $useMap, \PhpParser\Node $scopeNode, AstUtils $utils, NodeFinder $finder): ?string
    {
        if ($expr instanceof \PhpParser\Node\Expr\New_ && $expr->class instanceof \PhpParser\Node\Name) {
            return ltrim($utils->resolveNameNodeToFqcn($expr->class, $namespace, $useMap, false), '\\');
        }
        if ($expr instanceof \PhpParser\Node\Expr\MethodCall || $expr instanceof \PhpParser\Node\Expr\StaticCall) {
            $calleeKey = null;
            if ($expr instanceof \PhpParser\Node\Expr\MethodCall && $expr->var instanceof \PhpParser\Node\Expr\New_ && $expr->var->class instanceof \PhpParser\Node\Name && $expr->name instanceof \PhpParser\Node\Identifier) {
                $objFqcn = $utils->resolveNameNodeToFqcn($expr->var->class, $namespace, $useMap, false);
                if ($objFqcn !== '') {
                    $calleeKey = ltrim($objFqcn, '\\') . '::' . $expr->name->toString();
                }
            }
            if ($calleeKey === null) {
                $calleeKey = $utils->getCalleeKey($expr, $namespace, $useMap, $scopeNode);
            }
            if ($calleeKey && isset(GlobalCache::$astNodeMap[$calleeKey])) {
                $calleeNode = GlobalCache::$astNodeMap[$calleeKey];
                $file = GlobalCache::$nodeKeyToFilePath[$calleeKey];
                $ns   = GlobalCache::$fileNamespaces[$file] ?? '';
                $umap = GlobalCache::$fileUseMaps[$file] ?? [];
                if ($calleeNode->returnType instanceof \PhpParser\Node\Name) {
                    return ltrim($utils->resolveNameNodeToFqcn($calleeNode->returnType, $ns, $umap, false), '\\');
                }
                $returns = $finder->findInstanceOf($calleeNode->stmts ?? [], \PhpParser\Node\Stmt\Return_::class);
                foreach ($returns as $ret) {
                    if ($ret->expr instanceof \PhpParser\Node\Expr\New_ && $ret->expr->class instanceof \PhpParser\Node\Name) {
                        return ltrim($utils->resolveNameNodeToFqcn($ret->expr->class, $ns, $umap, false), '\\');
                    }
                }
            }
        }
        return null;
    }
}
