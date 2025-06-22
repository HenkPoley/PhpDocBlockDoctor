<?php

declare(strict_types=1);

use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\ThrowsGatherer;
use HenkPoley\DocBlockDoctor\GlobalCache;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\NodeTraverser;
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
        $code = <<<'PHP'
        <?php
        namespace NS;
        use Some\Thing as AliasClass;
        use function Other\funcA;
        use const Other\CONST_B;
        class Dummy {}
        PHP;
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
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
        $code = <<<'PHP'
        <?php
        namespace NS;
        use Foo\Bar\{Baz, Qux as Quux};
        use function Foo\Funcs\{f1, f2};
        use const Foo\Consts\{C1, C2};
        class Dummy {}
        PHP;
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
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
        $code = <<<'PHP'
        <?php
        namespace NS;
        use Foo\Bar\{Baz, Qux};
        class Dummy {}
        PHP;
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'file3.php'));
        $traverser->traverse($ast);

        $expected = [
            'Baz' => 'Foo\\Bar\\Baz',
            'Qux' => 'Foo\\Bar\\Qux',
        ];
        $map = GlobalCache::$fileUseMaps['file3.php'] ?? [];
        ksort($map);
        $this->assertSame($expected, $map);
    }
}
