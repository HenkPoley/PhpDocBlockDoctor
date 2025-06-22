<?php
// tests/fixtures/static-method-chaining-nullable/ChainStaticNullable.php
namespace Pitfalls\StaticMethodChainingNullable;

class Factory {
    public static function maybe(): ?Product {
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
        Factory::maybe()->doWork();
    }
}
