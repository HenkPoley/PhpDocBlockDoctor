<?php
// tests/fixtures/method-chaining-multiple-returns/MultiReturnChain.php
namespace Pitfalls\MethodChainingMultipleReturns;

class Factory {
    public function create(bool $flag) {
        if ($flag) {
            return new ProductA();
        }
        return new ProductB();
    }
}

class ProductA {
    public function doWork(): void {
        throw new \RuntimeException('A');
    }
}

class ProductB {
    public function doWork(): void {
        throw new \InvalidArgumentException('B');
    }
}

class Caller {
    private Factory $f;

    public function __construct(Factory $f) {
        $this->f = $f;
    }

    public function run(bool $flag): void {
        $this->f->create($flag)->doWork();
    }
}
