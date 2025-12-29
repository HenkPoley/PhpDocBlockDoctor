<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\Unit;

use HenkPoley\DocBlockDoctor\Application;
use HenkPoley\DocBlockDoctor\GlobalCache;
use PhpParser\Node\Stmt\ClassMethod;
use PHPUnit\Framework\TestCase;

class ApplicationExtraTest extends TestCase
{
    protected function setUp(): void
    {
        GlobalCache::clear();
    }

    public function testStoreResolvedDataDetectsChanges(): void
    {
        $app = new Application();
        $ref = new \ReflectionMethod(Application::class, 'storeResolvedData');
        $ref->setAccessible(true);

        GlobalCache::setResolvedThrowsForKey('foo', ['RuntimeException']);
        GlobalCache::setThrowOriginsForKey('foo', ['RuntimeException' => ['orig']]);

        $resultSame = $ref->invoke($app, 'foo', ['RuntimeException'], ['RuntimeException' => ['orig']]);
        $this->assertFalse($resultSame, 'No change expected when data is identical');

        $resultChanged = $ref->invoke($app, 'foo', ['LogicException'], ['LogicException' => ['new']]);
        $this->assertTrue($resultChanged);
        $this->assertSame(['LogicException'], GlobalCache::getResolvedThrowsForKey('foo'));
        $this->assertSame(['LogicException' => ['new']], GlobalCache::getThrowOriginsForKey('foo'));
    }

    public function testPropagateInterfaceThrowsAggregatesImplementationData(): void
    {
        $ifaceKey = 'My\\Iface::bar';
        $implAKey = 'My\\ImplA::bar';
        $implBKey = 'My\\ImplB::bar';

        GlobalCache::addImplementation('My\\Iface', 'My\\ImplA');
        GlobalCache::addImplementation('My\\Iface', 'My\\ImplB');
        GlobalCache::setAstNode($ifaceKey, new ClassMethod('bar'));
        GlobalCache::setAstNode($implAKey, new ClassMethod('bar'));
        GlobalCache::setAstNode($implBKey, new ClassMethod('bar'));

        GlobalCache::setResolvedThrowsForKey($implAKey, ['RuntimeException']);
        GlobalCache::setThrowOriginsForKey($implAKey, ['RuntimeException' => ['A.php:1']]);
        GlobalCache::setResolvedThrowsForKey($implBKey, ['InvalidArgumentException']);
        GlobalCache::setThrowOriginsForKey($implBKey, ['InvalidArgumentException' => ['B.php:2']]);
        GlobalCache::setResolvedThrowsForKey($ifaceKey, []);
        GlobalCache::setThrowOriginsForKey($ifaceKey, []);

        $app = new Application();
        $m = new \ReflectionMethod(Application::class, 'propagateInterfaceThrows');
        $m->setAccessible(true);
        $changed = $m->invoke($app);

        $this->assertTrue($changed);
        $expected = ['InvalidArgumentException', 'RuntimeException'];
        sort($expected);
        $this->assertSame($expected, GlobalCache::getResolvedThrowsForKey($ifaceKey));
        $this->assertSame([
            'RuntimeException' => ['A.php:1'],
            'InvalidArgumentException' => ['B.php:2'],
        ], GlobalCache::getThrowOriginsForKey($ifaceKey));

        $changedAgain = $m->invoke($app);
        $this->assertFalse($changedAgain, 'Second call should detect no further changes');
    }

    public function testPrintHelpOutputsUsageText(): void
    {
        $app = new Application();
        $m = new \ReflectionMethod(Application::class, 'printHelp');
        $m->setAccessible(true);

        ob_start();
        $m->invoke($app);
        $out = ob_get_clean();

        $this->assertStringContainsString('Usage:', $out);
        $this->assertStringContainsString('DocBlockDoctor', $out);
    }

    public function testRunWithHelpFlagOutputsHelpAndExits(): void
    {
        $app = new Application();

        ob_start();
        $result = $app->run(['doc-block-doctor', '--help']);
        $out = ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Usage:', $out);
        $this->assertStringContainsString('DocBlockDoctor', $out);
    }
}
