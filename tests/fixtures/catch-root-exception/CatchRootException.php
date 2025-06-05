<?php
// tests/fixtures/catch-root-exception/CatchRootException.php

declare(strict_types=1);

namespace Pitfalls\CatchRootException;

class Worker {
    public static function doSomething(): void {
        throw new MyException('fail');
    }
}

class Wrapper {
    public function __construct() {
        try {
            Worker::doSomething();
        } catch (\Exception $e) {
            throw new \Exception('Invalid configuration: ' . $e->getMessage());
        }
    }
}

class Runner {
    public function run(): void {
        new Wrapper();
    }
}
