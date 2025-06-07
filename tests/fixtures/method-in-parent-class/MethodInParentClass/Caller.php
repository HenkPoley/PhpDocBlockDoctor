<?php

namespace Pitfalls\MethodInParentClass;

class Caller
{
    public function callChildClassWithMethodInParentsParent(ChildClass $child): void
    {
        $child->methodInParentsParent();
    }

    public function callChildClassWithMethodInParentsParentTrait(ChildClass $child): void
    {
        $child->methodInParentsParentTrait();
    }

    public function callChildClassWithMethodInParent(ChildClass $child): void
    {
        $child->methodInParent();
    }

    public function callChildClassWithParentMethodOverride(ChildClass $child): void
    {
        $child->methodInChild();
    }
}
