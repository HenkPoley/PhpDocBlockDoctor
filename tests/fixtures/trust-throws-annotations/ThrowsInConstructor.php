<?php
// tests/fixtures/constructor-throws/ThrowsInConstructor.php

declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\TestFixtures\ConstructorThrows;

class ThrowsInConstructor
{
    public function __construct()
    {
        throw new \LogicException("fail");
    }

    /**
     * We annotate an exception that's not actually thrown here to make sure we 'believe' that.
     * @throws \RuntimeException
     */
    public function createAndCall()
    {
        $obj = new ThrowsInConstructor();
        $obj->someMethod();
    }

    public function createAndCallWithForeach()
    {
        $obj = new ThrowsInConstructor();
        foreach (['a', 'b'] as $key => $value) {
            $obj->someMethod($value);
        }
    }

    public function someMethod(): void
    {
        // no throw here
    }
}