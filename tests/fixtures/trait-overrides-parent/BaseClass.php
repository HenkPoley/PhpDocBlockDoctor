<?php
namespace Pitfalls\TraitOverridesParent;

class BaseClass {
    public function foo(): void {
        throw new \LogicException();
    }
}
