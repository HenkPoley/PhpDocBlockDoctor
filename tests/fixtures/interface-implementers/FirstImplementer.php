<?php
namespace Pitfalls\InterfaceImplementers;
class FirstImplementer implements FooImplementer {
    public function foo(): void {
        throw new \RuntimeException();
    }
}
