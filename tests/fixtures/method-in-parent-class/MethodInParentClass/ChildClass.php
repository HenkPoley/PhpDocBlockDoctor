<?php
namespace Pitfalls\MethodInParentClass;

class ChildClass extends ParentClass
{
    public function methodInChild(): void
    {
        throw new \BadMethodCallException();
    }
}
