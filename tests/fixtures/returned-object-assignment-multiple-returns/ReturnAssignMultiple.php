<?php
// tests/fixtures/returned-object-assignment-multiple-returns/ReturnAssignMultiple.php
namespace Pitfalls\ReturnAssignMultiple;

class Factory {
    public function create(bool $flag) {
        if ($flag) {
            return new ProductA();
        }
        return new ProductB();
    }
}

class ProductA {
    public function work(): void {
        throw new \RuntimeException('A');
    }
}

class ProductB {
    public function work(): void {
        throw new \InvalidArgumentException('B');
    }
}

class Runner {
    private Factory $f;
    public function __construct(Factory $f) { $this->f = $f; }
    public function run(bool $flag): void {
        $p = $this->f->create($flag);
        $p->work();
    }
}
