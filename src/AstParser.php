<?php

declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Node;
use PhpParser\NodeVisitor;

interface AstParser
{
    /**
     * @return Node[]|null
     *
     * @throws \LogicException
     * @throws \RangeException
     */
    public function parse(string $code): ?array;

    /**
     * @param Node[] $ast
     * @param NodeVisitor[] $visitors
     *
     * @throws \LogicException
     */
    public function traverse(array $ast, array $visitors): void;
}
