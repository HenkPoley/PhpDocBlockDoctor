<?php
namespace Pitfalls\Nonexistent;

class Example
{
    public function foo(): void
    {
        throw new \SomeVendor\Exception();
    }

    public function bar(): void
    {
        $this->foo();
    }
}
