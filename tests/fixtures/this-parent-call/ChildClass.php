<?php
namespace Pitfalls\ThisParentCall;

class ChildClass extends ParentClass
{
    public function outer(): void
    {
        $this->inner();
    }
}
