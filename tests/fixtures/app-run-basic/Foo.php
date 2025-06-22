<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\TestFixtures\AppRunBasic;

class Foo
{
    public function foo(): void
    {
        throw new \RuntimeException();
    }
}
