<?php
namespace Pitfalls\TraitOverridesParent;

class Runner {
    public function run(): void {
        (new ChildClass())->foo();
    }
}
