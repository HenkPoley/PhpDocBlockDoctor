<?php
// tests/fixtures/constructor-throws/ThrowEndsExecution.php

declare(strict_types=1);

namespace Pitfalls\IfAllowsExecution;

class ThrowEndsExecution
{
    function __construct(private int $unknown)
    {
    }

    public function throwsOnlyOneBecauseThrowEndsTheFunction()
    {
        throw new \CompileError();
        // We never end up here
        throw new \AssertionError();
    }

    public function canThrowBoth()
    {
        // May be true depending on new ThrowEndsExecution(42) or not.
        if(42 === $this->unknown) {
            throw new \CompileError();
        }

        // We may end up here.
        throw new \AssertionError();
    }
}