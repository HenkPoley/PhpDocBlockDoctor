<?php
declare(strict_types=1);

use PhpParser\PhpVersion;
use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeFinder;
use HenkPoley\DocBlockDoctor\ThrowsGatherer;
use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\GlobalCache;

class ThrowsGathererTest extends TestCase
{
    private AstUtils $utils;
    private NodeFinder $finder;

    protected function setUp(): void
    {
        $this->utils  = new AstUtils();
        $this->finder = new NodeFinder();
        GlobalCache::clear();
    }

    /**
     * @throws \LogicException
     */
    public function testCalculateDirectThrowsIgnoresCaught(): void
    {
        $code = <<<'PHP'
        <?php
        namespace T;
        class C {
            public function foo(): void {
                try {
                    throw new \LogicException("fail");
                } catch (\LogicException $e) {
                    // caught here, so should NOT appear in directThrows
                }
            }
        }
        PHP;

        $parser   = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast      = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $tg = new ThrowsGatherer($this->finder, $this->utils, 'dummyPath');
        $traverser->addVisitor($tg);
        $traverser->traverse($ast);

        $key = 'T\\C::foo';
        $this->assertArrayHasKey($key, GlobalCache::getDirectThrows());
        // The thrown LogicException is caught within the method, so no direct throws should be reported
        $this->assertSame([], GlobalCache::getDirectThrowsForKey($key));
    }

    /**
     * @throws \LogicException
     */
    public function testCalculateDirectThrowsFindsUncaught(): void
    {
        $code = <<<'PHP'
        <?php
        namespace T;
        class C {
            public function foo(): void {
                throw new \RuntimeException("fail");
            }
        }
        PHP;

        $parser   = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast      = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $tg = new ThrowsGatherer($this->finder, $this->utils, 'dummyPath');
        $traverser->addVisitor($tg);
        $traverser->traverse($ast);

        $key = 'T\\C::foo';
        $this->assertArrayHasKey($key, GlobalCache::getDirectThrows());
        $this->assertEqualsCanonicalizing(
            ['RuntimeException'],
            GlobalCache::getDirectThrowsForKey($key)
        );
    }

    /**
     * @throws \LogicException
     */
    public function testCalculateDirectThrowsFromInstanceofCatch(): void
    {
        $code = <<<'PHP'
        <?php
        namespace T;
        class C {
            public function foo(): void {
                try {
                    throw new \ErrorException('fail', 0, 1, '', 0, new \Exception('cause'));
                } catch (\Exception $e) {
                    if ($e instanceof \ErrorException) {
                        $prev = $e->getPrevious();
                        if ($prev instanceof \Exception) {
                            throw $prev;
                        }
                    }
                    throw $e;
                }
            }
        }
        PHP;

        $parser   = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast      = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $tg = new ThrowsGatherer($this->finder, $this->utils, 'dummyPath');
        $traverser->addVisitor($tg);
        $traverser->traverse($ast);

        $key = 'T\\C::foo';
        $this->assertArrayHasKey($key, GlobalCache::getDirectThrows());
        $this->assertEqualsCanonicalizing(
            ['Exception'],
            GlobalCache::getDirectThrowsForKey($key)
        );
    }

    /**
     * @throws \LogicException
     */
    public function testCalculateDirectThrowsFromInstanceofCatchWithoutInterveningThrow(): void
    {
        $code = <<<'PHP'
        <?php
        namespace T;
        class C {
            public function foo(): void {
                try {
                    throw new \RuntimeException('fail');
                } catch (\Throwable $e) {
                    if ($e instanceof \RuntimeException) {
                        // no throw here
                    }
                    throw $e;
                }
            }
        }
        PHP;

        $parser   = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast      = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $tg = new ThrowsGatherer($this->finder, $this->utils, 'dummyPath');
        $traverser->addVisitor($tg);
        $traverser->traverse($ast);

        $key = 'T\\C::foo';
        $this->assertArrayHasKey($key, GlobalCache::getDirectThrows());
        $this->assertEqualsCanonicalizing(
            ['RuntimeException', 'Throwable'],
            GlobalCache::getDirectThrowsForKey($key)
        );
        $origins = GlobalCache::getThrowOriginsForKey($key);
        $this->assertSame(
            ['T\\C::foo <- dummyPath:11'],
            $origins['RuntimeException'] ?? null
        );
    }

    /**
     * @throws \LogicException
     */
    public function testCalculateDirectThrowsCaughtByRootException(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Pitfalls\CatchRootException;
        class C {
            public function foo(): void {
                try {
                    throw new MyException('fail');
                } catch (\Exception $e) {
                    throw new \Exception('wrap');
                }
            }
        }
        PHP;

        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('Pitfalls\\CatchRootException\\', __DIR__ . '/../fixtures/catch-root-exception');
        $loader->register(false);

        $parser   = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast      = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $tg = new ThrowsGatherer($this->finder, $this->utils, 'dummyPath');
        $traverser->addVisitor($tg);
        $traverser->traverse($ast);

        $key = 'Pitfalls\\CatchRootException\\C::foo';
        $this->assertArrayHasKey($key, GlobalCache::getDirectThrows());
        $this->assertEqualsCanonicalizing(
            ['Exception'],
            GlobalCache::getDirectThrowsForKey($key)
        );
    }

    /**
     * @throws \LogicException
     */
    public function testCalculateDirectThrowsCaughtByParentException(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Pitfalls\CatchParentException;
        class C {
            public function foo(): void {
                try {
                    throw new BananaPeelException('fail');
                } catch (FruitException $e) {
                    // handled
                }
            }
        }
        PHP;

        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('Pitfalls\\CatchParentException\\', __DIR__ . '/../fixtures/CatchParentException');
        $loader->register(false);

        $parser   = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast      = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $tg = new ThrowsGatherer($this->finder, $this->utils, 'dummyPath');
        $traverser->addVisitor($tg);
        $traverser->traverse($ast);

        $key = 'Pitfalls\\CatchParentException\\C::foo';
        $this->assertArrayHasKey($key, GlobalCache::getDirectThrows());
        $this->assertSame([], GlobalCache::getDirectThrowsForKey($key));
    }

    /**
     * @throws \LogicException
     */
    public function testCalculateDirectThrowsCaughtByParentExceptionSameFile(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Pitfalls\CatchParentSameFile;
        class ParentException extends \Exception {}
        class ChildException extends ParentException {}
        class C {
            public function foo(): void {
                try {
                    throw new ChildException('fail');
                } catch (ParentException $e) {
                    // handled
                }
            }
        }
        PHP;

        $parser   = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast      = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $tg = new ThrowsGatherer($this->finder, $this->utils, 'dummyPath');
        $traverser->addVisitor($tg);
        $traverser->traverse($ast);

        $key = 'Pitfalls\\CatchParentSameFile\\C::foo';
        $this->assertArrayHasKey($key, GlobalCache::getDirectThrows());
        $this->assertSame([], GlobalCache::getDirectThrowsForKey($key));
    }

    /**
     * @throws \LogicException
     */
    public function testCalculateDirectThrowsFromClassStringVariable(): void
    {
        $code = <<<'PHP'
        <?php
        namespace T;
        class C {
            public function foo(): void {
                $exc = \RuntimeException::class;
                throw new $exc('fail');
            }
        }
        PHP;

        $parser   = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast      = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $tg = new ThrowsGatherer($this->finder, $this->utils, 'dummyPath');
        $traverser->addVisitor($tg);
        $traverser->traverse($ast);

        $key = 'T\\C::foo';
        $this->assertArrayHasKey($key, GlobalCache::getDirectThrows());
        $this->assertEquals(['RuntimeException'], GlobalCache::getDirectThrowsForKey($key));
    }
}
