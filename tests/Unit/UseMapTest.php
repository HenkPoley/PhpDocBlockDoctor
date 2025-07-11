<?php

declare(strict_types=1);

use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\ThrowsGatherer;
use HenkPoley\DocBlockDoctor\GlobalCache;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\NodeTraverser;
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

        $map = GlobalCache::getFileUseMap('file.php');
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
        $map = GlobalCache::getFileUseMap('file2.php');
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
        $map = GlobalCache::getFileUseMap('file3.php');
        ksort($map);
        $this->assertSame($expected, $map);
    }

    /**
     * @throws \LogicException
     */
    public function testThrowsGathererCachesNamespace(): void
    {
        $code = <<<'PHP'
        <?php
        namespace NS; 
        function foo(){}
        PHP;
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'nsfile.php'));
        $traverser->traverse($ast);

        $this->assertSame('NS', GlobalCache::getFileNamespace('nsfile.php'));
        $this->assertSame(['nsfile.php' => 'NS'], GlobalCache::getFileNamespaces());
    }

    /**
     * @throws \LogicException
     */
    public function testThrowsGathererCachesUseMap(): void
    {
        $code = <<<'PHP'
        <?php
        namespace NS;
        use Foo\Bar\Baz;
        class Dummy {}
        PHP;
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'umfile.php'));
        $traverser->traverse($ast);

        $expected = ['Baz' => 'Foo\\Bar\\Baz'];
        $this->assertSame($expected, GlobalCache::getFileUseMap('umfile.php'));
        $this->assertSame(['umfile.php' => $expected], GlobalCache::getFileUseMaps());
    }

    /**
     * @throws \LogicException
     */
    public function testThrowsGathererCachesNodeKeyToFilePath(): void
    {
        $code = <<<'PHP'
        <?php
        namespace NS;
        function foo(){}
        class C { public function bar(){} }
        PHP;
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'nkfile.php'));
        $traverser->traverse($ast);

        $expected = [
            'NS\\C::bar' => 'nkfile.php',
            'NS\\foo' => 'nkfile.php',
        ];
        $map = GlobalCache::getNodeKeyToFilePath();
        ksort($map);
        $this->assertSame($expected, $map);
    }

    /**
     * @throws \LogicException
     */
    public function testThrowsGathererCachesAstNodeMap(): void
    {
        $code = <<<'PHP'
        <?php
        namespace NS;
        function foo(){}
        class C { public function bar(){} }
        PHP;
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'anfile.php'));
        $traverser->traverse($ast);

        $expectedKeys = [
            'NS\\C::bar',
            'NS\\foo',
        ];
        $mapKeys = array_keys(GlobalCache::getAstNodeMap());
        sort($mapKeys);
        sort($expectedKeys);
        $this->assertSame($expectedKeys, $mapKeys);
    }

    /**
     * @throws \LogicException
     */
    public function testThrowsGathererCachesClassParents(): void
    {
        $code = <<<'PHP'
        <?php
        namespace NS;
        class ParentClass {}
        class ChildClass extends ParentClass {}
        PHP;
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'cpfile.php'));
        $traverser->traverse($ast);

        $expected = [
            'NS\\ChildClass' => 'NS\\ParentClass',
            'NS\\ParentClass' => null,
        ];
        $map = GlobalCache::getClassParents();
        ksort($map);
        ksort($expected);
        $this->assertSame($expected, $map);
    }

    /**
     * @throws \LogicException
     */
    public function testThrowsGathererCachesClassTraits(): void
    {
        $code = <<<'PHP'
        <?php
        namespace NS;
        trait T1 {}
        trait T2 {}
        class A { use T1; }
        class B { use T1; use T2; }
        PHP;
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'ctfile.php'));
        $traverser->traverse($ast);

        $expected = [
            'NS\\A' => ['NS\\T1'],
            'NS\\B' => ['NS\\T1', 'NS\\T2'],
        ];
        $map = GlobalCache::getClassTraits();
        ksort($map);
        foreach ($map as $k => $v) { sort($v); $map[$k] = $v; }
        ksort($expected);
        foreach ($expected as $k => $v) { sort($v); $expected[$k] = $v; }
        $this->assertSame($expected, $map);
    }

    /**
     * @throws \LogicException
     */
    public function testThrowsGathererCachesInterfaceImplementations(): void
    {
        $code = <<<'PHP'
        <?php
        namespace NS;
        interface Base {}
        interface Sub extends Base {}
        class A implements Base {}
        class B implements Sub {}
        PHP;
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'iface.php'));
        $traverser->traverse($ast);

        $expected = [
            'NS\\Base' => ['NS\\A', 'NS\\Sub'],
            'NS\\Sub' => ['NS\\B'],
        ];
        $map = GlobalCache::getInterfaceImplementations();
        ksort($map);
        foreach ($map as $k => $v) { sort($v); $map[$k] = $v; }
        ksort($expected);
        foreach ($expected as $k => $v) { sort($v); $expected[$k] = $v; }
        $this->assertSame($expected, $map);
    }

    /**
     * @throws \LogicException
     */
    public function testThrowsGathererCachesDirectThrows(): void
    {
        $code = <<<'PHP'
        <?php
        namespace NS;
        function foo(){ throw new \RuntimeException(); }
        function bar(){}
        PHP;
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'dtfile.php'));
        $traverser->traverse($ast);

        $expected = [
            'NS\\foo' => ['RuntimeException'],
            'NS\\bar' => [],
        ];
        $map = GlobalCache::getDirectThrows();
        ksort($map);
        foreach ($map as $k => $v) { sort($v); $map[$k] = $v; }
        ksort($expected);
        foreach ($expected as $k => $v) { sort($v); $expected[$k] = $v; }
        $this->assertSame($expected, $map);
    }

    /**
     * @throws \LogicException
     */
    public function testThrowsGathererCachesAnnotatedThrows(): void
    {
        $code = <<<'PHP'
        <?php
        namespace NS;
        /**
         * @throws \RuntimeException
         * @throws \InvalidArgumentException
         */
        function foo(){}
        function bar(){}
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'atfile.php'));
        $traverser->traverse($ast);

        $expected = [
            'NS\\foo' => ['RuntimeException', 'InvalidArgumentException'],
            'NS\\bar' => [],
        ];
        $map = GlobalCache::getAnnotatedThrows();
        ksort($map);
        foreach ($map as $k => $v) { sort($v); $map[$k] = $v; }
        ksort($expected);
        foreach ($expected as $k => $v) { sort($v); $expected[$k] = $v; }
        $this->assertSame($expected, $map);
    }

    /**
     * @throws \LogicException
     */
    public function testThrowsGathererCachesOriginalDescriptions(): void
    {
        $code = <<<'PHP'
        <?php
        namespace NS;
        /**
         * @throws \RuntimeException Foo bar
         * @throws \InvalidArgumentException Baz qux
         */
        function foo(){}
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'odfile.php'));
        $traverser->traverse($ast);

        $expected = [
            'NS\\foo' => [
                'RuntimeException' => 'Foo bar',
                'InvalidArgumentException' => 'Baz qux',
            ],
        ];
        $map = GlobalCache::getOriginalDescriptions();
        ksort($map);
        foreach ($map as $k => $v) { ksort($v); $map[$k] = $v; }
        ksort($expected);
        foreach ($expected as $k => $v) { ksort($v); $expected[$k] = $v; }
        $this->assertSame($expected, $map);
    }

    /**
     * @throws \LogicException
     */
    public function testResolvedThrowsCache(): void
    {
        $code = <<<'PHP'
        <?php
        namespace NS;
        function foo(){ throw new \RuntimeException(); }
        function bar(){}
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'rtfile.php'));
        $traverser->traverse($ast);

        foreach (array_keys(GlobalCache::getAstNodeMap()) as $key) {
            $direct = GlobalCache::getDirectThrowsForKey($key);
            GlobalCache::setResolvedThrowsForKey($key, $direct);
        }

        $expected = [
            'NS\\foo' => ['RuntimeException'],
            'NS\\bar' => [],
        ];
        $map = GlobalCache::getResolvedThrows();
        ksort($map);
        foreach ($map as $k => $v) { sort($v); $map[$k] = $v; }
        ksort($expected);
        foreach ($expected as $k => $v) { sort($v); $expected[$k] = $v; }
        $this->assertSame($expected, $map);
    }

    /**
     * @throws \LogicException
     */
    public function testThrowOriginsCache(): void
    {
        $code = <<<'PHP'
        <?php
        namespace NS;
        function foo(){ throw new \RuntimeException(); }
        function bar(){}
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ThrowsGatherer($this->finder, $this->utils, 'orig.php'));
        $traverser->traverse($ast);

        $expected = [
            'NS\\foo' => [
                'RuntimeException' => ['NS\\foo <- orig.php:3'],
            ],
            'NS\\bar' => [],
        ];
        $map = GlobalCache::getThrowOrigins();
        ksort($map);
        foreach ($map as $k => $exMap) {
            ksort($exMap);
            foreach ($exMap as $ex => $list) {
                sort($list);
                $exMap[$ex] = $list;
            }
            $map[$k] = $exMap;
        }
        ksort($expected);
        foreach ($expected as $k => $exMap) {
            if (is_array($exMap)) {
                ksort($exMap);
                foreach ($exMap as $ex => $list) {
                    sort($list);
                    $exMap[$ex] = $list;
                }
                $expected[$k] = $exMap;
            }
        }
        $this->assertSame($expected, $map);
    }
}
