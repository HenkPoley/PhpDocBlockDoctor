<?php
// tests/fixtures/method-chaining-notype/ChainNoType.php
namespace Pitfalls\MethodChainingNoType;

class Factory {
    public function build() {
        return new Product();
    }
}

class Product {
    /**
     */
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
        $this->factory->build()->doWork();
    }
}
