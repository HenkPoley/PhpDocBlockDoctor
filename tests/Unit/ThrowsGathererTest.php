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
        $this->assertArrayHasKey($key, GlobalCache::$directThrows);
        // The thrown LogicException is caught within the method, so no direct throws should be reported
        $this->assertSame([], GlobalCache::$directThrows[$key] ?? []);
    }

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
        $this->assertArrayHasKey($key, GlobalCache::$directThrows);
        $this->assertEqualsCanonicalizing(
            ['RuntimeException'],
            GlobalCache::$directThrows[$key]
        );
    }

    public function testCalculateDirectThrowsFromInstanceofCatch(): void
    {
        $code = <<<'PHP'
        <?php
        namespace T;
        class C {
            public function foo(): void {
                try {
                    throw new \ErrorException('fail');
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
        $this->assertArrayHasKey($key, GlobalCache::$directThrows);
        $this->assertEqualsCanonicalizing(
            ['ErrorException', 'Exception'],
            GlobalCache::$directThrows[$key]
        );
    }
}
