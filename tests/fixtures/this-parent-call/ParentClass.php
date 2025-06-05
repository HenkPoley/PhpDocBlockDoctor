<?php
namespace Pitfalls\ThisParentCall;

class ParentClass
{
    /**
     * @throws \RuntimeException
     */
    protected function inner(): void
    {
        throw new \RuntimeException();
    }
}
