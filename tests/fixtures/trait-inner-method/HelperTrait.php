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
