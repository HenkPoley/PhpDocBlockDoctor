<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\Unit;

use HenkPoley\DocBlockDoctor\DocBlockUpdater;
use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\GlobalCache;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PHPUnit\Framework\TestCase;
use PhpParser\NodeFinder;
use HenkPoley\DocBlockDoctor\ThrowsGatherer;

class DocBlockUpdaterPatchTest extends TestCase
{
    private function runUpdater(string $code, array $resolved, bool $traceOrigins = false, bool $traceCallSites = false, array $throwOrigins = []): array
    {
        $file = 'dummy.php';
        GlobalCache::clear();
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast = $parser->parse($code) ?: [];

        $tr1 = new NodeTraverser();
        $tr1->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $tr1->addVisitor(new ParentConnectingVisitor());
        $finder = new NodeFinder();
        $utils = new AstUtils();
        foreach ($ast as $node) {
            if ($node instanceof \PhpParser\Node\Stmt\Function_) {
                GlobalCache::$astNodeMap[$node->name->toString()] = $node;
                GlobalCache::$nodeKeyToFilePath[$node->name->toString()] = $file;
            }
        }
        GlobalCache::$fileNamespaces[$file] = '';
        foreach ($resolved as $key => $vals) {
            GlobalCache::$resolvedThrows[$key] = $vals;
        }
        foreach ($throwOrigins as $fn => $exMap) {
            foreach ($exMap as $ex => $chains) {
                GlobalCache::$throwOrigins[$fn][$ex] = $chains;
            }
        }

        $tr2 = new NodeTraverser();
        $tr2->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $tr2->addVisitor(new ParentConnectingVisitor());
        $updater = new DocBlockUpdater($utils, $file, $traceOrigins, $traceCallSites);
        $tr2->addVisitor($updater);
        $tr2->traverse($ast);
        return $updater->pendingPatches;
    }

    public function testAddThrowsAnnotation(): void
    {
        $code = "<?php\nfunction foo() { throw new \RuntimeException(); }";
        $patches = $this->runUpdater($code, ['foo' => ['RuntimeException']]);
        $this->assertCount(1, $patches);
        $this->assertSame('add', $patches[0]['type']);
        $this->assertStringContainsString('@throws \\RuntimeException', $patches[0]['newDocText']);
    }

    public function testRemoveThrowsAnnotation(): void
    {
        $code = "<?php\n/** @throws \\RuntimeException */\nfunction foo() {}";
        $patches = $this->runUpdater($code, ['foo' => []]);
        $this->assertCount(1, $patches);
        $this->assertSame('remove', $patches[0]['type']);
    }

    public function testUpdateThrowsAnnotation(): void
    {
        $code = "<?php\n/** @throws \\LogicException */\nfunction foo() { throw new \RuntimeException(); }";
        $patches = $this->runUpdater($code, ['foo' => ['RuntimeException']]);
        $this->assertCount(1, $patches);
        $this->assertSame('update', $patches[0]['type']);
        $this->assertStringContainsString('@throws \\RuntimeException', $patches[0]['newDocText']);
    }

    public function testTraceOriginsIncludesChains(): void
    {
        $code = "<?php\nfunction foo() { throw new \\RuntimeException(); }";
        $orig = ['foo' => ['RuntimeException' => ['dummy.php:3 <- foo']]];
        $patches = $this->runUpdater($code, ['foo' => ['RuntimeException']], true, false, $orig);
        $this->assertStringContainsString('dummy.php:3', $patches[0]['newDocText']);
    }

    public function testTraceCallSitesIncludesLineNumbers(): void
    {
        $code = "<?php\nfunction foo() { throw new \\RuntimeException(); }";
        $orig = ['foo' => ['RuntimeException' => ['dummy.php:3 <- foo']]];
        $patches = $this->runUpdater($code, ['foo' => ['RuntimeException']], false, true, $orig);
        $this->assertStringContainsString(':3', $patches[0]['newDocText']);
    }
}
