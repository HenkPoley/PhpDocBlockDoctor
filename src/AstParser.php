<?php

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeVisitor;

interface AstParser
{
    /**
     * @return Node[]|null
     * @throws Error
     */
    public function parse(string $code): ?array;

    /**
     * @param Node[] $ast
     * @param NodeVisitor[] $visitors
     */
    public function traverse(array $ast, array $visitors): void;
}
