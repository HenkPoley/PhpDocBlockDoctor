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

class GlobalScopeCallTest extends TestCase
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
     * Ensure that top-level statements do not cause invalid calls to getCalleeKey.
     *
     * @throws \LogicException
     */
    public function testTopLevelCallIsIgnored(): void
    {
        $code = <<<'PHP'
        <?php
        class Foo { public function bar(): void {} }
        $f = new Foo();
        $f->bar();
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'dummy'));
        $traverser->traverse($ast);

        // Only the method inside Foo should be registered, the top-level call should be ignored
        $this->assertArrayHasKey('Foo::bar', GlobalCache::getAstNodeMap());
    }
}
