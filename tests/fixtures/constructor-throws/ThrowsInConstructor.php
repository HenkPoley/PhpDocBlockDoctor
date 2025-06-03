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

    public function createAndCall()
    {
        $obj = new ThrowsInConstructor();
        $obj->someMethod();
    }

    public function someMethod(): void
    {
        // no throw here
    }
}