<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor;
if (!function_exists(__NAMESPACE__ . '\\class_exists')) {
    function class_exists(string $class, bool $autoload = true): bool
    {
        $overrides = $GLOBALS['__override_class_exists'] ?? [];
        if (array_key_exists($class, $overrides)) {
            return $overrides[$class];
        }
        return \class_exists($class, $autoload);
    }
}

namespace HenkPoley\DocBlockDoctor\Tests\Unit;

use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\ThrowsGatherer;
use HenkPoley\DocBlockDoctor\GlobalCache;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\TestCase;

class NameResolverReplaceNodesTrueTest extends TestCase
{
    private AstUtils $utils;
    private NodeFinder $finder;

    protected function setUp(): void
    {
        $this->utils  = new AstUtils();
        $this->finder = new NodeFinder();
        GlobalCache::clear();
    }

    public function testGetCalleeKeyWithReplaceNodesTrue(): void
    {
        $code = <<<'PHP'
        <?php
        namespace My\Space;

        class A {
            public function foo(): void {}
            public function bar(): void {
                $this->foo();
            }
        }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast    = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => true, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->utils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u;
            private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns);
                    GlobalCache::$astNodeMap[$key] = $n;
                    GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $bar = $this->finder->findFirst(
            $ast,
            fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'bar'
        );
        $this->assertNotNull($bar);
        $call = $this->finder->findFirstInstanceOf($bar->stmts, Node\Expr\MethodCall::class);
        $key  = $this->utils->getCalleeKey($call, 'My\\Space', [], $bar);
        $this->assertSame('My\\Space\\A::foo', $key);
    }

    public function testThrowsGathererWithReplaceNodesTrue(): void
    {
        $code = <<<'PHP'
        <?php
        namespace T;
        class C {
            public function foo(): void {
                throw new \RuntimeException('fail');
            }
        }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast    = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => true, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $tg = new ThrowsGatherer($this->finder, $this->utils, 'dummy');
        $traverser->addVisitor($tg);
        $traverser->traverse($ast);

        $key = 'T\\C::foo';
        $this->assertArrayHasKey($key, GlobalCache::$directThrows);
        $this->assertEqualsCanonicalizing(['RuntimeException'], GlobalCache::$directThrows[$key]);
    }
}
