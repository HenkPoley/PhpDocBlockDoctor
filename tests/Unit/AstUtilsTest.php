<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\Unit;

use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\GlobalCache;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\TestCase;

class AstUtilsTest extends TestCase
{
    private AstUtils $astUtils;
    private NodeFinder $finder;

    protected function setUp(): void
    {
        $this->astUtils = new AstUtils();
        $this->finder = new NodeFinder();
        GlobalCache::clear();
    }

    /**
     * @throws \LogicException
     */
    public function testResolveSimpleMethodOnThis(): void
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

        // Parse the AST
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $parentConnector = new ParentConnectingVisitor();
        $traverser->addVisitor($parentConnector);
        // We also need to record classMethod nodes in GlobalCache so getCalleeKey can find context:
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u;
            private string $namespace = '';

            public function __construct(AstUtils $u)
            {
                $this->u = $u;
            }

            public function beforeTraverse(array $nodes)
            {
                // find namespace node if any
                $finder = new NodeFinder();
                $ns = $finder->findFirst($nodes, fn(Node $n) => $n instanceof Node\Stmt\Namespace_);
                if ($ns instanceof Node\Stmt\Namespace_ && $ns->name) {
                    $this->namespace = $ns->name->toString();
                }
                return null;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($node, $this->namespace);
                    GlobalCache::$astNodeMap[$key] = $node;
                    GlobalCache::$nodeKeyToFilePath[$key] = 'dummy'; // path is not used in this test
                }
            }
        });

        $traverser->traverse($ast);

        // Find the "foo" and "bar" method nodes by matching name:
        $fooMethod = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'foo');
        $barMethod = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'bar');
        $this->assertNotNull($fooMethod);
        $this->assertNotNull($barMethod);

        // Build a dummy MethodCall node manually:
        $calls = $this->finder->findInstanceOf(
            $barMethod->stmts ?? [],
            Node\Expr\MethodCall::class
        );
        $this->assertCount(1, $calls, 'Expected exactly one MethodCall inside bar()');

        $callNode = $calls[0];
        $resolvedKey = $this->astUtils->getCalleeKey(
            $callNode,
            'My\\Space',
            [],
            $barMethod
        );
        $this->assertSame('My\\Space\\A::foo', $resolvedKey);
    }

    /**
     * @throws \LogicException
     */
    public function testResolveNewConstructorCall(): void
    {
        $code = <<<'PHP'
        <?php
        namespace TestNS;

        class B {
            public function __construct() {}
            public function createme(): void {
                $obj = new B();
                $obj->bar();
            }
            public function bar(): void {}
        }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $parentConnector = new ParentConnectingVisitor();
        $traverser->addVisitor($parentConnector);
        // Record each method in GlobalCache
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u;
            private string $ns = '';

            public function __construct(AstUtils $u)
            {
                $this->u = $u;
            }

            public function beforeTraverse(array $nodes)
            {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) {
                    $this->ns = $nsNode->name->toString();
                }
                return null;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($node, $this->ns);
                    GlobalCache::$astNodeMap[$key] = $node;
                    GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        // Locate the MethodCall “$obj->bar();”
        $classB = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class, fn($n) => $n->name->name === 'B');
        $createme = null;
        foreach ($classB->stmts as $stm) {
            if ($stm instanceof Node\Stmt\ClassMethod && $stm->name->toString() === 'createme') {
                $createme = $stm;
                break;
            }
        }
        $this->assertNotNull($createme);
        $calls = $this->finder->findInstanceOf($createme->stmts, Node\Expr\MethodCall::class);
        // We expect only one call: “$obj->bar()”
        $this->assertCount(1, $calls);
        $resolved = $this->astUtils->getCalleeKey($calls[0], 'TestNS', [], $createme);
        $this->assertSame('TestNS\\B::bar', $resolved);
    }

    /**
     * @throws \LogicException
     */
    public function testResolveAssignedFromCall(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Foo;

        class C {
            public static function create(): C { return new C(); }
            public function bar(): void {}
        }

        class T {
            public function test(): void {
                $c = C::create();
                $c->bar();
            }
        }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $testMethod = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'test');
        $this->assertNotNull($testMethod);
        $call = $this->finder->findFirstInstanceOf($testMethod->stmts, Node\Expr\MethodCall::class);
        $resolved = $this->astUtils->getCalleeKey($call, 'Foo', [], $testMethod);
        $this->assertSame('Foo\\C::bar', $resolved);
    }

    /**
     * Ensure that a variable assigned from a call on itself does not cause infinite recursion.
     *
     * @throws \LogicException
     */
    public function testSelfAssignmentDoesNotRecurse(): void
    {
        $code = <<<'PHP'
        <?php
        namespace FooSelf;

        class C {
            public function create(): C { return new C(); }
            public function bar(): void {}

            public function test(): void {
                $c = new C();
                $c = $c->create();
                $c->bar();
            }
        }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $testMethod = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'test');
        $this->assertNotNull($testMethod);
        $call = $this->finder->findFirst(
            $testMethod->stmts,
            fn(Node $n) => $n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier && $n->name->toString() === 'bar'
        );
        $resolved = $this->astUtils->getCalleeKey($call, 'FooSelf', [], $testMethod);
        $this->assertSame('FooSelf\\C::bar', $resolved);
    }

    /**
     * @throws \LogicException
     */
    public function testResolvePromotedPropertyCall(): void
    {
        $code = <<<'PHP'
        <?php
        namespace P; 

        class Q {
            public function __construct(private R $r) {}

            public function foo(): void {
                $this->r->bar();
            }
        }

        class R { public function bar(): void {} }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $foo = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'foo');
        $this->assertNotNull($foo);
        $call = $this->finder->findFirstInstanceOf($foo->stmts, Node\Expr\MethodCall::class);
        $resolved = $this->astUtils->getCalleeKey($call, 'P', [], $foo);
        $this->assertSame('P\\R::bar', $resolved);
    }

    /**
     * @throws \LogicException
     */
    public function testResolveNullablePromotedPropertyCall(): void
    {
        $code = <<<'PHP'
        <?php
        namespace PPN;

        class Q {
            public function __construct(private ?R $r) {}

            public function foo(): void {
                $this->r->bar();
            }
        }

        class R { public function bar(): void {} }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $foo = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'foo');
        $this->assertNotNull($foo);
        $call = $this->finder->findFirstInstanceOf($foo->stmts, Node\Expr\MethodCall::class);
        $resolved = $this->astUtils->getCalleeKey($call, 'PPN', [], $foo);
        $this->assertSame('PPN\\R::bar', $resolved);
    }

    /**
     * @throws \LogicException
     */
    public function testResolveNullableTypedPropertyCall(): void
    {
        $code = <<<'PHP'
        <?php
        namespace PN;

        class Q {
            private ?R $r;

            public function __construct() {
                $this->r = new R();
            }

            public function foo(): void {
                $this->r->bar();
            }
        }

        class R { public function bar(): void {} }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $foo = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'foo');
        $this->assertNotNull($foo);
        $call = $this->finder->findFirstInstanceOf($foo->stmts, Node\Expr\MethodCall::class);
        $resolved = $this->astUtils->getCalleeKey($call, 'PN', [], $foo);
        $this->assertSame('PN\\R::bar', $resolved);
    }

    /**
     * @throws \LogicException
     */
    public function testResolveNullableTypedParameterCall(): void
    {
        $code = <<<'PHP'
        <?php
        namespace ParamNull;

        class R { public function bar(): void {} }

        class Q {
            public function foo(?R $r): void {
                $r->bar();
            }
        }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $foo = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'foo');
        $this->assertNotNull($foo);
        $call = $this->finder->findFirstInstanceOf($foo->stmts, Node\Expr\MethodCall::class);
        $resolved = $this->astUtils->getCalleeKey($call, 'ParamNull', [], $foo);
        $this->assertSame('ParamNull\\R::bar', $resolved);
    }

    /**
     * @throws \LogicException
     */
    public function testScalarTypedParameterIsIgnored(): void
    {
        $code = <<<'PHP'
        <?php
        namespace ScalarParam;

        class Q {
            public function foo(int $x): void {
                $x->bar();
            }
        }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $foo = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'foo');
        $this->assertNotNull($foo);
        $call = $this->finder->findFirstInstanceOf($foo->stmts, Node\Expr\MethodCall::class);
        $resolved = $this->astUtils->getCalleeKey($call, 'ScalarParam', [], $foo);
        $this->assertNull($resolved);
    }

    /**
     * @throws \LogicException
     */
    public function testResolveTraitPropertyCall(): void
    {
        $code = <<<'PHP'
        <?php
        namespace T;

        trait Tr {
            private U $u;

            public function foo(): void {
                $this->u->bar();
            }
        }

        class U { public function bar(): void {} }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $foo = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'foo');
        $this->assertNotNull($foo);
        $call = $this->finder->findFirstInstanceOf($foo->stmts, Node\Expr\MethodCall::class);
        $resolved = $this->astUtils->getCalleeKey($call, 'T', [], $foo);
        $this->assertSame('T\\U::bar', $resolved);
    }

    /**
     * @throws \LogicException
     */
    public function testResolveMagicStaticCall(): void
    {
        $code = <<<'PHP'
        <?php
        namespace M;

        class AssertionFailedException extends \Exception {}

        /**
         * @method static void string(mixed $v)
         */
        class Assert {
            public static function __callStatic(string $n, array $a): void {
                throw new AssertionFailedException();
            }
        }

        class UseCase {
            public function foo(): void {
                Assert::string('x');
            }
        }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $use = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'foo');
        $this->assertNotNull($use);
        $call = $this->finder->findFirstInstanceOf($use->stmts, Node\Expr\StaticCall::class);
        $resolved = $this->astUtils->getCalleeKey($call, 'M', [], $use);
        $this->assertSame('M\\Assert::__callStatic', $resolved);
    }

    /**
     * @throws \LogicException
     */
    public function testResolveMethodChainNullableReturn(): void
    {
        $code = <<<'PHP'
        <?php
        namespace X;

        class Factory {
            public function maybe(): ?Product {
                return new Product();
            }
        }

        class Product {
            public function work(): void {}
        }

        class Caller {
            private Factory $f;

            public function __construct(Factory $f) {
                $this->f = $f;
            }

            public function run(): void {
                $this->f->maybe()->work();
            }
        }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $run = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'run');
        $this->assertNotNull($run);
        $calls = $this->finder->findInstanceOf($run->stmts, Node\Expr\MethodCall::class);
        $targetCall = null;
        foreach ($calls as $c) {
            if ($c->name instanceof Node\Identifier && $c->name->toString() === 'work') {
                $targetCall = $c;
                break;
            }
        }
        $this->assertNotNull($targetCall);
        $resolved = $this->astUtils->getCalleeKey($targetCall, 'X', [], $run);
        $this->assertSame('X\\Product::work', $resolved);
    }
    /**
     * @throws \LogicException
     */
    public function testResolveMethodChainReturnNew(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Y;

        class Factory {
            public function create() {
                return new Product();
            }
        }

        class Product {
            public function work(): void {}
        }

        class Caller {
            private Factory $f;

            public function __construct(Factory $f) {
                $this->f = $f;
            }

            public function run(): void {
                $this->f->create()->work();
            }
        }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $run = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'run');
        $this->assertNotNull($run);
        $calls = $this->finder->findInstanceOf($run->stmts, Node\Expr\MethodCall::class);
        $targetCall = null;
        foreach ($calls as $c) {
            if ($c->name instanceof Node\Identifier && $c->name->toString() === 'work') {
                $targetCall = $c;
                break;
            }
        }
        $this->assertNotNull($targetCall);
        $resolved = $this->astUtils->getCalleeKey($targetCall, 'Y', [], $run);
        $this->assertSame('Y\\Product::work', $resolved);
    }

    /**
     * @throws \LogicException
     */
    public function testResolveParentStaticCallFallback(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Pitfalls\ParentMethodCall;

        class ParentClass {
            /**
             * @throws \RuntimeException
             */
            public function foo(): void {
                throw new \RuntimeException();
            }
        }

        class ChildClass extends ParentClass {
            public function callFoo(): void {
                parent::foo();
            }
        }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $callFoo = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'callFoo');
        $this->assertNotNull($callFoo);
        $call = $this->finder->findFirstInstanceOf($callFoo->stmts, Node\Expr\StaticCall::class);
        $this->assertNotNull($call);
        $resolved = $this->astUtils->getCalleeKey($call, 'Pitfalls\\ParentMethodCall', [], $callFoo);
        $this->assertSame('Pitfalls\\ParentMethodCall\\ParentClass::foo', $resolved);
    }

    /**
     * @throws \LogicException
     */
    public function testResolveParentStaticCallWithResolvedName(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Pitfalls\ParentMethodCall;

        class ParentClass {
            public function foo(): void {}
        }

        class ChildClass extends ParentClass {
            public function callFoo(): void {
                parent::foo();
            }
        }
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new class($this->astUtils) extends \PhpParser\NodeVisitorAbstract {
            private AstUtils $u; private string $ns = '';
            public function __construct(AstUtils $u) { $this->u = $u; }
            public function beforeTraverse(array $nodes) {
                $finder = new NodeFinder();
                $nsNode = $finder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
                if ($nsNode && $nsNode->name) { $this->ns = $nsNode->name->toString(); }
                return null;
            }
            public function enterNode(Node $n) {
                if ($n instanceof Node\Stmt\ClassMethod) {
                    $key = $this->u->getNodeKey($n, $this->ns); GlobalCache::$astNodeMap[$key] = $n; GlobalCache::$nodeKeyToFilePath[$key] = 'dummy';
                }
            }
        });
        $traverser->traverse($ast);

        $callFoo = $this->finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'callFoo');
        $this->assertNotNull($callFoo);
        $call = $this->finder->findFirstInstanceOf($callFoo->stmts, Node\Expr\StaticCall::class);
        $this->assertNotNull($call);
        $call->class->setAttribute('resolvedName', new Node\Name\FullyQualified('Pitfalls\\ParentMethodCall\\ParentClass'));
        $resolved = $this->astUtils->getCalleeKey($call, 'Pitfalls\\ParentMethodCall', [], $callFoo);
        $this->assertSame('Pitfalls\\ParentMethodCall\\ParentClass::foo', $resolved);
    }
}
