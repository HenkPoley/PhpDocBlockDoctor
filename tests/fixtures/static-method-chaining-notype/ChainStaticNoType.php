<?php
// tests/fixtures/static-method-chaining-notype/ChainStaticNoType.php
namespace Pitfalls\StaticMethodChainingNoType;

class Factory {
    public static function create() {
        return new Product();
    }
}

class Product {
    public function doWork(): void {
        throw new \RuntimeException('uh-oh');
    }
}

class Caller {
    public function runAll(): void {
        Factory::create()->doWork();
    }
}
