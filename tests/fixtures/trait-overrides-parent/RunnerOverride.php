<?php
namespace Pitfalls\TraitOverridesParent;

class RunnerOverride {
    public function run(): void {
        (new ChildOverride())->foo();
    }
}
