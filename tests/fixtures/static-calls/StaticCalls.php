<?php
// tests/fixtures/static-calls/StaticCalls.php
namespace Pitfalls\StaticCalls;

class A {
    /**

     */
    public static function a(): void {
        B::b();
    }
}

class B {
    /**

     */
    public static function b(): void {
        throw new \LogicException("error");
    }
}

// Integration: “A::a” should resolve “LogicException”, and “B::b” should also resolve “LogicException”.