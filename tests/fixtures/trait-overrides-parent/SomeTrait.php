<?php
namespace Pitfalls\TraitOverridesParent;

trait SomeTrait {
    public function foo(): void {
        throw new \RuntimeException();
    }
}
