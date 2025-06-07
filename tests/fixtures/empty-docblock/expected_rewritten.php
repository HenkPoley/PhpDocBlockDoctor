<?php
// tests/fixtures/empty-docblock/EmptyDocblock.php

declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\TestFixtures\EmptyDocblock;

class EmptyDocblock
{
    /**
     * @throws \ParseError
     */
    public function emptyDocblock(): void
    {
        throw new \ParseError();
    }
}
