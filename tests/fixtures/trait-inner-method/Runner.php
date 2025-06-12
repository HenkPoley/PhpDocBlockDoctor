<?php
namespace Pitfalls\TraitInnerMethod;

class Runner {
    public function run(): void {
        (new UseTrait())->foo();
    }
}
