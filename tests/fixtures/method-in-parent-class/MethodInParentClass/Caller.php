<?php
namespace Pitfalls\MethodInParentClass;

class Caller
{
    public function callChildClassWithMethodInParent(ChildClass $child): void
    {
        $child->methodInParent();
    }
}
