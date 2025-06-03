<?php
// tests/fixtures/catch-in-middle/MiddleCatch.php
namespace Pitfalls\CatchInMiddle;

class X {
    /**
     * @throws \\LogicException
     */
    public function foo(): void {
        throw new \LogicException("uh");
    }
}

class Y {
    /**
     * @throws \\RuntimeException
     */
    public function bar(): void {
        try {
            (new X())->foo();
        } catch (\LogicException $ex) {
            // swallowed here
        }
        throw new \RuntimeException("no-logic");
    }
}

class Z {
    public function top(): void {
        (new Y())->bar();
    }
}