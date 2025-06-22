<?php
namespace Pitfalls\ThisParentCall;

class ParentClass
{
    protected function inner(): void
    {
        throw new \RuntimeException();
    }
}
