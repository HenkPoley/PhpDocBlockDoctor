<?php
namespace Pitfalls\MethodInParentClass;

class ParentClass
{
    /**
     * @throws \LogicException
     */
    public function methodInParent(): void
    {
        throw new \LogicException();
    }
}

