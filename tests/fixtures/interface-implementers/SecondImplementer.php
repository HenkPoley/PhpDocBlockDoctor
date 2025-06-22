<?php
namespace Pitfalls\InterfaceImplementers;
class SecondImplementer implements FooImplementer {
    public function foo(): void {
        throw new \LogicException();
    }
}
