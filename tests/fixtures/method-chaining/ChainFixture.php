<?php
// tests/fixtures/method-chaining/ChainFixture.php
namespace Pitfalls\MethodChaining;

class Factory {
    public function make(): Product {
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
        $this->factory->make()->doWork();
    }
}