<?php
// tests/fixtures/rethrow/RethrowFixture.php
namespace Pitfalls\Rethrow;

class Worker {
    /**
     * @throws \RuntimeException
     */
    public function doThing(): void {
        throw new \RuntimeException('fail');
    }
}

class Wrapper {
    /**
     * @throws \RuntimeException
     */
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
