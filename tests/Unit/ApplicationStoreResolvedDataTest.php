<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\Unit;

use HenkPoley\DocBlockDoctor\Application;
use HenkPoley\DocBlockDoctor\GlobalCache;
use PHPUnit\Framework\TestCase;

class ApplicationStoreResolvedDataTest extends TestCase
{
    private function invokeStore(string $key, array $throws, array $origins): bool
    {
        $app = new Application();
        $ref = new \ReflectionMethod(Application::class, 'storeResolvedData');
        $ref->setAccessible(true);
        /** @var bool $res */
        $res = $ref->invoke($app, $key, $throws, $origins);
        return $res;
    }

    public function testStoreResolvedDataUpdatesCacheWhenDifferent(): void
    {
        GlobalCache::clear();
        GlobalCache::setResolvedThrowsForKey('foo', ['RuntimeException']);
        GlobalCache::setThrowOriginsForKey('foo', ['RuntimeException' => []]);

        $changed = $this->invokeStore('foo', ['LogicException'], ['LogicException' => []]);

        $this->assertTrue($changed);
        $this->assertSame(['LogicException'], GlobalCache::getResolvedThrowsForKey('foo'));
    }

    public function testStoreResolvedDataReturnsFalseWhenUnchanged(): void
    {
        GlobalCache::clear();
        GlobalCache::setResolvedThrowsForKey('bar', ['RuntimeException']);
        GlobalCache::setThrowOriginsForKey('bar', ['RuntimeException' => []]);

        $changed = $this->invokeStore('bar', ['RuntimeException'], ['RuntimeException' => []]);

        $this->assertFalse($changed);
        $this->assertSame(['RuntimeException'], GlobalCache::getResolvedThrowsForKey('bar'));
    }
}
