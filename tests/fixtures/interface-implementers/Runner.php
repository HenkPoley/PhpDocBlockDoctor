<?php
namespace Pitfalls\InterfaceImplementers;
class Runner {
    private FooImplementer $impl;
    public function __construct(FooImplementer $impl) {
        $this->impl = $impl;
    }
    public function run(): void {
        $this->impl->foo();
    }
}
