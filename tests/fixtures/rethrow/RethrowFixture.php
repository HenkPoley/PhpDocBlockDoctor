<?php
// tests/fixtures/rethrow/RethrowFixture.php
namespace Pitfalls\Rethrow;

class Worker {
    public function doThing(): void {
        throw new \RuntimeException('fail');
    }
}

class Wrapper {
    public function callAndRethrow(): void {
        try {
            (new Worker())->doThing();
        } catch (\RuntimeException $ex) {
            throw $ex;
        }
    }
}

class Runner {
    public function start(): void {
        (new Wrapper())->callAndRethrow();
    }
}
