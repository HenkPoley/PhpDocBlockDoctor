<?php

declare(strict_types=1);

use HenkPoley\DocBlockDoctor\UseStatementSimplifierSurgical;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\TestCase;

class UseStatementSimplifierSurgicalTest extends TestCase
{
    public function testSimplifySingleItemGroupUse(): void
    {
        $code = <<<'PHP'
        <?php
        use Foo\Bar\{Baz};
        class C {}
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $visitor = new UseStatementSimplifierSurgical();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $newCode = $code;
        $patches = $visitor->pendingPatches;
        usort($patches, static fn(array $a, array $b): int => $b['startPos'] <=> $a['startPos']);
        foreach ($patches as $patch) {
            $newCode = substr_replace($newCode, $patch['replacementText'], $patch['startPos'], $patch['length']);
        }

        $this->assertStringContainsString('use Foo\\Bar\\Baz;', $newCode);
    }

    public function testSimplifySingleItemGroupUseWithAlias(): void
    {
        $code = <<<'PHP'
        <?php
        use Foo\Bar\{Baz as Qux};
        class C {}
        PHP;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];
        $traverser = new NodeTraverser();
        $visitor = new UseStatementSimplifierSurgical();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $newCode = $code;
        $patches = $visitor->pendingPatches;
        usort($patches, static fn(array $a, array $b): int => $b['startPos'] <=> $a['startPos']);
        foreach ($patches as $patch) {
            $newCode = substr_replace($newCode, $patch['replacementText'], $patch['startPos'], $patch['length']);
        }

        $this->assertStringContainsString('use Foo\\Bar\\Baz as Qux;', $newCode);
    }
}
