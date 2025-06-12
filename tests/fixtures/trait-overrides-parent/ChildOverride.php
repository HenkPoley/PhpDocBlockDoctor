<?php
namespace Pitfalls\TraitOverridesParent;

class ChildOverride extends BaseClass {
    use SomeTrait;
    public function foo(): void {
        throw new \OverflowException();
    }
}
