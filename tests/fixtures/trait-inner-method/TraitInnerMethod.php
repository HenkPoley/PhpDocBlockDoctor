<?php
namespace Pitfalls\TraitInnerMethod;

trait HelperTrait {
    public function bar(): void {
        throw new \RuntimeException();
    }
    public function foo(): void {
        $this->bar();
    }
}

class UseTrait {
    use HelperTrait;
}

class Runner {
    public function run(): void {
        (new UseTrait())->foo();
    }
}
