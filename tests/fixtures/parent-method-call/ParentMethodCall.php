<?php
namespace Pitfalls\ParentMethodCall;

class ParentClass
{
    /**
     * @throws \RuntimeException
     */
    public function foo(): void
    {
        throw new \RuntimeException();
    }
}

class ChildClass extends ParentClass
{
    public function callFoo(): void
    {
        parent::foo();
    }
}
