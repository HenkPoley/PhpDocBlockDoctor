<?php
// tests/fixtures/static-calls/StaticCalls.php
namespace Pitfalls\StaticCalls;

class A {
    /**
     * @throws \LogicException
     */
    public static function a(): void {
        B::b();
    }
}

class B {
    /**
     * @throws \LogicException
     */
    public static function b(): void {
        throw new \LogicException("error");
    }
}

// Integration: “A::a” should resolve “LogicException”, and “B::b” should also resolve “LogicException”.