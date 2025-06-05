<?php
declare(strict_types=1);

use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\ThrowsGatherer;
use HenkPoley\DocBlockDoctor\GlobalCache;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;

class AutoloadingTest extends TestCase
{
    private AstUtils $utils;
    private NodeFinder $finder;

    protected function setUp(): void
    {
        $this->utils = new AstUtils();
        $this->finder = new NodeFinder();
        GlobalCache::clear();
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function testThrowsGathererDoesNotAutoloadMissingClasses(): void
    {
        $code = <<<'PHP'
        <?php
        function foo() {
            throw new \Nonexistent\CustomException();
        }
        PHP;

        $autoloadCalled = false;
        $loader = function ($class) use (&$autoloadCalled) {
            $autoloadCalled = true;
            throw new RuntimeException('Autoload attempted for ' . $class);
        };
        spl_autoload_register($loader);
        try {
            $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
            $ast = $parser->parse($code) ?: [];
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
            $traverser->addVisitor(new ParentConnectingVisitor());
            $tg = new ThrowsGatherer($this->finder, $this->utils, 'dummy');
            $traverser->addVisitor($tg);
            try {
                $traverser->traverse($ast);
            } catch (RuntimeException $e) {
                $this->fail('Autoloader was triggered: ' . $e->getMessage());
            }
        } finally {
            spl_autoload_unregister($loader);
        }

        $this->assertFalse($autoloadCalled, 'Autoloader should not be called');
        // Non-existent classes should be filtered out
        $this->assertSame([], GlobalCache::$directThrows['foo'] ?? []);
    }
}
