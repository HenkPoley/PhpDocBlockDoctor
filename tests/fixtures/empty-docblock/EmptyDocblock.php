<?php
// tests/fixtures/empty-docblock/EmptyDocblock.php

declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\TestFixtures\EmptyDocblock;

class EmptyDocblock
{
    /**
     */
    public function emptyDocblock(): void
    {
        throw new \ParseError();
    }
}
