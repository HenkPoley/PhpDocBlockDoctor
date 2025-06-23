<?php

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

class PhpParserAstParser implements AstParser
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 1));
    }

    /**
     * @return Node[]|null
     *
     * @throws \LogicException
     * @throws \RangeException
     */
    public function parse(string $code): ?array
    {
        return $this->parser->parse($code);
    }

    /**
     * @param Node[] $ast
     * @param NodeVisitor[] $visitors
     *
     * @throws \LogicException
     */
    public function traverse(array $ast, array $visitors): void
    {
        $traverser = new NodeTraverser();
        foreach ($visitors as $v) {
            $traverser->addVisitor($v);
        }
        $traverser->traverse($ast);
    }
}
