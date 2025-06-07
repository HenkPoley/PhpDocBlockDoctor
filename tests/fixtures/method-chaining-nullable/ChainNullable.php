<?php
// tests/fixtures/method-chaining-nullable/ChainNullable.php
namespace Pitfalls\MethodChainingNullable;

class Factory {
    public function maybe(): ?Product {
        return new Product();
    }
}

class Product {
    public function doWork(): void {
        throw new \RuntimeException("uh-oh");
    }
}

class Caller {
    /** @var Factory */
    private $factory;

    public function __construct(Factory $f) {
        $this->factory = $f;
    }

    public function runAll(): void {
        $this->factory->maybe()->doWork();
    }
}
