<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\TestFixtures\AppRunBasic;

class Bar
{
    public function call(): void
    {
        $f = new Foo();
        $f->foo();
    }
}
