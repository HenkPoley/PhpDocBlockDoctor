<?php
declare(strict_types=1);

use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;
use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\DocBlockUpdater;
use HenkPoley\DocBlockDoctor\GlobalCache;

class DocBlockUpdaterPatchTest extends TestCase
{
    private AstUtils $utils;
    private NodeFinder $finder;

    protected function setUp(): void
    {
        $this->utils = new AstUtils();
        $this->finder = new NodeFinder();
        GlobalCache::clear();
    }

    private function prepare(string $code, array $throws): array
    {
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8,4));
        $ast = $parser->parse($code) ?: [];
        $tr = new NodeTraverser();
        $tr->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $tr->addVisitor(new ParentConnectingVisitor());
        $tr->traverse($ast);
        $func = $this->finder->findFirstInstanceOf($ast, PhpParser\Node\Stmt\Function_::class);
        GlobalCache::$astNodeMap['foo'] = $func;
        GlobalCache::$nodeKeyToFilePath['foo'] = 'dummy.php';
        GlobalCache::$fileNamespaces['dummy.php'] = '';
        GlobalCache::$fileUseMaps['dummy.php'] = [];
        GlobalCache::$resolvedThrows['foo'] = $throws;
        return $ast;
    }

    public function testAddThrowsAnnotation(): void
    {
        $code = "<?php\nfunction foo() {}\n";
        $ast = $this->prepare($code, ['RuntimeException']);
        $tr = new NodeTraverser();
        $tr->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $tr->addVisitor(new ParentConnectingVisitor());
        $up = new DocBlockUpdater($this->utils, 'dummy.php', false, false);
        $tr->addVisitor($up);
        $tr->traverse($ast);
        $this->assertCount(1, $up->pendingPatches);
        $patch = $up->pendingPatches[0];
        $this->assertSame('add', $patch['type']);
        $this->assertStringContainsString('@throws \\RuntimeException', $patch['newDocText']);
    }

    public function testRemoveThrowsAnnotation(): void
    {
        $code = "<?php\n/**\n * @throws \\RuntimeException\n */\nfunction foo() {}\n";
        $ast = $this->prepare($code, []);
        $tr = new NodeTraverser();
        $tr->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $tr->addVisitor(new ParentConnectingVisitor());
        $up = new DocBlockUpdater($this->utils, 'dummy.php', false, false);
        $tr->addVisitor($up);
        $tr->traverse($ast);
        $this->assertCount(1, $up->pendingPatches);
        $patch = $up->pendingPatches[0];
        $this->assertSame('remove', $patch['type']);
    }

    public function testUpdateThrowsAnnotation(): void
    {
        $code = "<?php\n/**\n * @throws \\RuntimeException\n */\nfunction foo() {}\n";
        $ast = $this->prepare($code, ['InvalidArgumentException']);
        $tr = new NodeTraverser();
        $tr->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $tr->addVisitor(new ParentConnectingVisitor());
        $up = new DocBlockUpdater($this->utils, 'dummy.php', false, false);
        $tr->addVisitor($up);
        $tr->traverse($ast);
        $this->assertCount(1, $up->pendingPatches);
        $patch = $up->pendingPatches[0];
        $this->assertSame('update', $patch['type']);
        $this->assertStringContainsString('@throws \\InvalidArgumentException', $patch['newDocText']);
    }

    public function testNormalizeDocBlockStringUtility(): void
    {
        $up = new DocBlockUpdater($this->utils, 'dummy.php', false, false);
        $ref = new \ReflectionMethod(DocBlockUpdater::class, 'normalizeDocBlockString');
        $ref->setAccessible(true);
        $res = $ref->invoke($up, "\n Foo \n\n");
        $this->assertSame("/**\n *  Foo\n */", $res);
    }
}
