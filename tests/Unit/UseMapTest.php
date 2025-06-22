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

class UseMapTest extends TestCase
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
     */
    public function testUseStatementsSkipFunctionAndConstant(): void
    {
        $code = "<?php\n" .
            "namespace NS;\n" .
            "use Some\\Thing as AliasClass;\n" .
            "use function Other\\funcA;\n" .
            "use const Other\\CONST_B;\n" .
            "class Dummy {}";
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'file.php'));
        $traverser->traverse($ast);

        $map = GlobalCache::$fileUseMaps['file.php'] ?? [];
        $this->assertSame(['AliasClass' => 'Some\\Thing'], $map);
    }

    /**
     * @throws \LogicException
     */
    public function testGroupUseResolvesNames(): void
    {
        $code = "<?php\n" .
            "namespace NS;\n" .
            "use Foo\\Bar\\{Baz, Qux as Quux};\n" .
            "use function Foo\\Funcs\\{f1, f2};\n" .
            "use const Foo\\Consts\\{C1, C2};\n" .
            "class Dummy {}";
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'file2.php'));
        $traverser->traverse($ast);

        $expected = [
            'Baz' => 'Foo\\Bar\\Baz',
            'Quux' => 'Foo\\Bar\\Qux',
        ];
        $map = GlobalCache::$fileUseMaps['file2.php'] ?? [];
        ksort($map);
        $this->assertSame($expected, $map);
    }

    /**
     * @throws \LogicException
     */
    public function testGroupUseWithoutResolvedNamesUsesPrefix(): void
    {
        $code = "<?php\n" .
            "namespace NS;\n" .
            "use Foo\\Bar\\{Baz, Qux};\n" .
            "class Dummy {}";
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $tr1 = new NodeTraverser();
        $tr1->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $tr1->addVisitor(new ParentConnectingVisitor());
        $tr1->traverse($ast);
        foreach ($this->finder->findInstanceOf($ast, PhpParser\Node\Stmt\GroupUse::class) as $group) {
            foreach ($group->uses as $use) {
                $use->name->setAttribute('resolvedName', null);
            }
        }
        GlobalCache::clear();
        $tr2 = new NodeTraverser();
        $tr2->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'file3.php'));
        $tr2->traverse($ast);

        $expected = [
            'Baz' => 'Foo\\Bar\\Baz',
            'Qux' => 'Foo\\Bar\\Qux',
        ];
        $map = GlobalCache::$fileUseMaps['file3.php'] ?? [];
        ksort($map);
        $this->assertSame($expected, $map);
    }
}
