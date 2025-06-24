<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\Unit;

use HenkPoley\DocBlockDoctor\GlobalCache;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\TestCase;

class GlobalCacheTest extends TestCase
{
    protected function setUp(): void
    {
        GlobalCache::clear();
    }

    public function testVariousCacheOperations(): void
    {
        // direct throws
        GlobalCache::addDirectThrow('foo', 'RuntimeException');
        GlobalCache::addDirectThrow('foo', 'InvalidArgumentException');
        $this->assertSame(
            ['RuntimeException', 'InvalidArgumentException'],
            GlobalCache::getDirectThrowsForKey('foo')
        );

        // annotated throws
        GlobalCache::addAnnotatedThrow('foo', 'LogicException');
        $this->assertSame(['LogicException'], GlobalCache::getAnnotatedThrowsForKey('foo'));

        // original descriptions
        GlobalCache::setOriginalDescription('foo', 'RuntimeException', 'desc');
        $this->assertSame(
            ['RuntimeException' => 'desc'],
            GlobalCache::getOriginalDescriptionsForKey('foo')
        );

        // resolved throws
        GlobalCache::addResolvedThrow('foo', 'RuntimeException');
        $this->assertSame(['RuntimeException'], GlobalCache::getResolvedThrowsForKey('foo'));

        // throw origins deduplication and max chains
        for ($i = 1; $i <= GlobalCache::MAX_ORIGIN_CHAINS + 1; $i++) {
            GlobalCache::addThrowOrigin('foo', 'RuntimeException', "chain{$i}");
        }
        $expectedOrigins = [];
        for ($i = 1; $i <= GlobalCache::MAX_ORIGIN_CHAINS; $i++) {
            $expectedOrigins[] = "chain{$i}";
        }
        $this->assertSame(
            ['RuntimeException' => $expectedOrigins],
            GlobalCache::getThrowOriginsForKey('foo')
        );

        // file namespace and use map
        GlobalCache::setFileNamespace('file.php', 'Foo');
        $this->assertSame('Foo', GlobalCache::getFileNamespace('file.php'));
        GlobalCache::setFileUseMap('file.php', ['A' => 'B']);
        $this->assertSame(['A' => 'B'], GlobalCache::getFileUseMap('file.php'));

        // AST node and file path
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse("<?php function foo() {}") ?: [];
        $func = $ast[0];
        GlobalCache::setAstNode('foo', $func);
        GlobalCache::setFilePathForKey('foo', 'file.php');
        $this->assertSame($func, GlobalCache::getAstNode('foo'));
        $this->assertSame('file.php', GlobalCache::getFilePathForKey('foo'));

        // class relations
        GlobalCache::setClassParent('MyClass', 'Base');
        $this->assertSame('Base', GlobalCache::getClassParent('MyClass'));
        GlobalCache::addTraitForClass('MyClass', 'Trait1');
        GlobalCache::addTraitForClass('MyClass', 'Trait2');
        $this->assertSame(['Trait1', 'Trait2'], GlobalCache::getTraitsForClass('MyClass'));
        GlobalCache::addImplementation('MyInterface', 'MyClass');
        $this->assertSame(['MyClass'], GlobalCache::getImplementations('MyInterface'));
    }
}
