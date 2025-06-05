<?php
// tests/fixtures/catch-parent-exception/CatchParentException.php
namespace Pitfalls\CatchParentException;

class Worker {
    public function doSomething(): void {
        throw new BananaPeelException('fail');
    }
}

class Wrapper {
    public function handle(): void {
        try {
            (new Worker())->doSomething();
        } catch (FruitException $e) {
            // handled
        }
    }
}

class Runner {
    public function run(): void {
        (new Wrapper())->handle();
    }
}
