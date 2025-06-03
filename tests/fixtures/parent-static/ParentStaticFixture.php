<?php
// tests/fixtures/parent-static/ParentStaticFixture.php
namespace Pitfalls\ParentStatic;

class Base {
    /**

     */
    public function __construct() {
        throw new \BadMethodCallException();
    }
}

class Child extends Base {
    /**

     */
    public function explicitThrow(): void {
        throw new \OverflowException();
    }

    public function makeParent(): Base {
        return new parent(); // should count as Base::__construct
    }
}