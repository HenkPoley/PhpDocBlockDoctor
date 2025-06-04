<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\NewIntegration;

use PhpParser\PhpVersion;
use PHPUnit\Framework\TestCase;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeFinder;
use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\ThrowsGatherer;
use HenkPoley\DocBlockDoctor\GlobalCache;
use HenkPoley\DocBlockDoctor\DocBlockUpdater;

/**
 * This test runs on a single fixture directory, transforms it, and
 * compares the final printed PHP to an “expected_rewritten.php” file.
 */
class DocBlockUpdaterIntegrationTest extends TestCase
{
    public function testRewrittenDocblocksMatchExpected(): void
    {
        // Use the constructor-throws fixture to validate docblock rewriting
        $fixtureDir = __DIR__ . '/../fixtures/constructor-throws';
        $inputFile  = $fixtureDir . '/ThrowsInConstructor.php';
        $expectedOut = $fixtureDir . '/expected_rewritten.php';

        // 1) Read input
        $code = file_get_contents($inputFile);
        $this->assertNotFalse($code);

        // 2) First pass: gather throws + build GlobalCache
        GlobalCache::clear();
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast    = $parser->parse($code) ?: [];
        $traverser1 = new NodeTraverser();
        $traverser1->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser1->addVisitor(new ParentConnectingVisitor());
        $finder      = new NodeFinder();
        $astUtils    = new AstUtils();
        $tg          = new ThrowsGatherer($finder, $astUtils, $inputFile);
        $traverser1->addVisitor($tg);
        $traverser1->traverse($ast);

        // 3) Intermediate: propagate throws
        $directKeys = array_keys(GlobalCache::$astNodeMap);
        foreach ($directKeys as $methodKey) {
            $direct    = GlobalCache::$directThrows[$methodKey] ?? [];
            $annotated = GlobalCache::$annotatedThrows[$methodKey] ?? [];
            $initial   = array_values(array_unique(array_merge($direct, $annotated)));
            sort($initial);
            GlobalCache::$resolvedThrows[$methodKey] = $initial;
        }
        $maxIter = count($directKeys) + 1;
        $itCount = 0;
        do {
            $changed = false;
            $itCount++;
            foreach (GlobalCache::$astNodeMap as $methodKey => $node) {
                $allBase = array_values(array_unique(array_merge(
                    GlobalCache::$directThrows[$methodKey]    ?? [],
                    GlobalCache::$annotatedThrows[$methodKey] ?? []
                )));
                sort($allBase);
                $throwsFromCallees = [];
                // Collect all call nodes inside $node->stmts
                $callNodes = array_merge(
                    $finder->findInstanceOf($node->stmts, \PhpParser\Node\Expr\MethodCall::class),
                    $finder->findInstanceOf($node->stmts, \PhpParser\Node\Expr\StaticCall::class),
                    $finder->findInstanceOf($node->stmts, \PhpParser\Node\Expr\FuncCall::class),
                    $finder->findInstanceOf($node->stmts, \PhpParser\Node\Expr\New_::class)
                );
                $filePathOfFunc = GlobalCache::$nodeKeyToFilePath[$methodKey];
                $callerNamespace = GlobalCache::$fileNamespaces[$filePathOfFunc] ?? '';
                $callerUseMap    = GlobalCache::$fileUseMaps[$filePathOfFunc]  ?? [];
                foreach ($callNodes as $c) {
                    $calleeKey = $astUtils->getCalleeKey($c, $callerNamespace, $callerUseMap, $node);
                    if ($calleeKey && $calleeKey !== $methodKey) {
                        foreach (GlobalCache::$resolvedThrows[$calleeKey] ?? [] as $ex) {
                            $throwsFromCallees[] = $ex;
                        }
                    }
                }
                $newCombined = array_values(array_unique(array_merge($allBase, $throwsFromCallees)));
                sort($newCombined);
                $old = GlobalCache::$resolvedThrows[$methodKey] ?? [];
                if ($newCombined !== $old) {
                    GlobalCache::$resolvedThrows[$methodKey] = $newCombined;
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        } while ($itCount < $maxIter);

        // 4) Second pass: actually rewrite the AST (DocBlockUpdater)
        $traverser2 = new NodeTraverser();
        $traverser2->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser2->addVisitor(new ParentConnectingVisitor());
        $docUpd      = new DocBlockUpdater($astUtils, $inputFile);
        $traverser2->addVisitor($docUpd);
        $traverser2->traverse($ast);

        // 5) Apply textual patches collected by DocBlockUpdater so we compare exact source formatting
        $patchedCode = $code;
        $patches     = $docUpd->pendingPatches;
        usort($patches, static fn(array $a, array $b): int => $b['patchStart'] <=> $a['patchStart']);

        foreach ($patches as $p) {
            if ($p['type'] === 'remove') {
                $replacement = '';
            } elseif ($p['type'] === 'update') {
                // Compute indent from the original docblock line
                $lineStartPos = strrpos(substr($patchedCode, 0, $p['patchStart']), "\n");
                if ($lineStartPos !== false) {
                    $indent = substr(
                        $patchedCode,
                        $lineStartPos + 1,
                        $p['patchStart'] - ($lineStartPos + 1)
                    );
                } else {
                    $indent = '';
                }
                $lines = explode("\n", $p['newDocText']);
                foreach ($lines as &$l) {
                    $l = $indent . $l;
                }
                $replacement = implode("\n", $lines) . "\n";
            } else { // 'add'
                // Compute indentation for new DocBlock addition
                $indent = '';
                $newlinePos = strrpos($patchedCode, "\n", -strlen($patchedCode) + $p['patchStart']);
                if ($newlinePos !== false) {
                    $indent = substr(
                        $patchedCode,
                        $newlinePos + 1,
                        $p['patchStart'] - ($newlinePos + 1)
                    );
                }
                $lines = explode("\n", $p['newDocText']);
                foreach ($lines as &$l) {
                    $l = $indent . $l;
                }
                $replacement = implode("\n", $lines) . "\n";
            }
            $len = $p['patchEnd'] - $p['patchStart'] + 1;
            if ($len < 0) {
                $len = 0;
            }
            $patchedCode = substr_replace($patchedCode, $replacement, $p['patchStart'], $len);
        }

        // 6) Compare with expected rewritten code
        $expectedCode = file_get_contents($expectedOut);
        $this->assertNotFalse($expectedCode);
        $this->assertSame($expectedCode, $patchedCode, 'Rewritten code did not match expected for docblock rewrite');
    }
}