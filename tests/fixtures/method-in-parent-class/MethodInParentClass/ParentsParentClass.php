<?php
namespace Pitfalls\MethodInParentClass;

use ParentsParentTrait;
use SomeTrait;

class ParentsParentClass extends ParentClass
{
    // use ParentsParentTrait;

    public function methodInParentsParent(): void
    {
        throw new \ErrorException();
    }
}

