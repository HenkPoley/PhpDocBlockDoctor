<?php
// tests/fixtures/single-line-method-docblock/InlineDocblock.php

declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\TestFixtures\SingleLineMethodDocblock;

class InlineDocblock
{
    /**
     * @param int[] $vals
     *
     * @throws \LogicException
     */
    public function compute(array $vals): void
    {
        throw new \LogicException();
    }

    /**
     * @throws \LogicException
     */
    public function run(): void
    {
        $this->compute([1]);
    }
}
