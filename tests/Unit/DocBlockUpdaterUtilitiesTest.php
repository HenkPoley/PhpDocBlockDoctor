<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\Unit;

use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\DocBlockUpdater;
use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PHPUnit\Framework\TestCase;

class DocBlockUpdaterUtilitiesTest extends TestCase
{
    public function testSplitDocLines(): void
    {
        $utils = new AstUtils();
        $updater = new DocBlockUpdater($utils, 'dummy.php', false, false, true);
        $ref = new \ReflectionMethod(DocBlockUpdater::class, 'splitDocLines');
        $ref->setAccessible(true);
        $doc = "/**\n * Foo\n * Bar\n */";
        $result = $ref->invoke($updater, $doc);
        $this->assertSame(['', 'Foo', 'Bar', ''], $result);
    }

    public function testGetNodeSignatureForMessage(): void
    {
        $utils = $this->createMock(AstUtils::class);
        $utils->method('getNodeKey')->willReturn('foo');
        $fn = new Function_('foo');
        $updater = new DocBlockUpdater($utils, 'dummy.php', false, false, true);
        $ref = new \ReflectionMethod(DocBlockUpdater::class, 'getNodeSignatureForMessage');
        $ref->setAccessible(true);
        $this->assertSame('foo', $ref->invoke($updater, $fn));

        $utils2 = $this->createMock(AstUtils::class);
        $utils2->method('getNodeKey')->willReturn('');
        $fn2 = new Function_('bar');
        $updater2 = new DocBlockUpdater($utils2, 'dummy.php', false, false, true);
        $ref2 = new \ReflectionMethod(DocBlockUpdater::class, 'getNodeSignatureForMessage');
        $ref2->setAccessible(true);
        $this->assertSame('bar()', $ref2->invoke($updater2, $fn2));

        $utils3 = $this->createMock(AstUtils::class);
        $utils3->method('getNodeKey')->willReturn(null);
        $node = $this->createMock(Node::class);
        $updater3 = new DocBlockUpdater($utils3, 'dummy.php', false, false, true);
        $ref3 = new \ReflectionMethod(DocBlockUpdater::class, 'getNodeSignatureForMessage');
        $ref3->setAccessible(true);
        $this->assertSame('unknown_node_type', $ref3->invoke($updater3, $node));
    }

    public function testNormalizeDocBlockStringEmptyReturnsNull(): void
    {
        $utils = new AstUtils();
        $updater = new DocBlockUpdater($utils, 'dummy.php', false, false, true);
        $ref = new \ReflectionMethod(DocBlockUpdater::class, 'normalizeDocBlockString');
        $ref->setAccessible(true);
        $this->assertNull($ref->invoke($updater, " \n \n"));
    }
}
