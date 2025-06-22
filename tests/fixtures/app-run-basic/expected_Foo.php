<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\TestFixtures\AppRunBasic;

class Foo
{
    /**
     * @throws \RuntimeException
     */
    public function foo(): void
    {
        throw new \RuntimeException();
    }
}
