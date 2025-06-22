<?php
namespace Pitfalls\MethodInParentClass;

class ParentClass extends ParentsParentClass
{
    public function methodInParent(): void
    {
        throw new \LogicException();
    }

    public function methodInChild(): void
    {
        throw new \RuntimeException();
    }
}

