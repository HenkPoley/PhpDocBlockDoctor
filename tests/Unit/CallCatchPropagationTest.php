<?php
declare(strict_types=1);

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeFinder;
use PhpParser\PhpVersion;
use PHPUnit\Framework\TestCase;
use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\ThrowsGatherer;
use HenkPoley\DocBlockDoctor\GlobalCache;

class CallCatchPropagationTest extends TestCase
{
    /**
     * @throws \LogicException
     */
    public function testExceptionsFromCallAreFilteredByCatch(): void
    {
        $code = <<<'PHP'
        <?php
        namespace TestNS;
        class Thrower {
            public function run(): void {
                throw new \RuntimeException('fail');
            }
        }
        class Wrapper {
            public function handle(): void {
                try {
                    $t = new Thrower();
                    $t->run();
                } catch (\Error $e) {
                } catch (\Exception $e) {
                }
            }
        }
        PHP;

        GlobalCache::clear();
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $ast    = $parser->parse($code) ?: [];
        $tr1 = new NodeTraverser();
        $tr1->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $tr1->addVisitor(new ParentConnectingVisitor());
        $finder = new NodeFinder();
        $utils  = new AstUtils();
        $tg     = new ThrowsGatherer($finder, $utils, 'dummy.php');
        $tr1->addVisitor($tg);
        $tr1->traverse($ast);

        foreach (array_keys(GlobalCache::getAstNodeMap()) as $key) {
            $direct    = GlobalCache::getDirectThrowsForKey($key);
            $annotated = GlobalCache::getAnnotatedThrowsForKey($key);
            $combined  = array_values(array_unique(array_merge($direct, $annotated)));
            sort($combined);
            GlobalCache::setResolvedThrowsForKey($key, $combined);
            if (!isset(GlobalCache::$throwOrigins[$key])) {
                GlobalCache::$throwOrigins[$key] = [];
            }
            foreach ($combined as $ex) {
                if (!isset(GlobalCache::$throwOrigins[$key][$ex])) {
                    GlobalCache::$throwOrigins[$key][$ex] = [];
                }
            }
        }

        foreach (GlobalCache::getAstNodeMap() as $funcKey => $funcNode) {
            $filePathOfFunc  = GlobalCache::getFilePathForKey($funcKey) ?? '';
            $callerNamespace = GlobalCache::getFileNamespace($filePathOfFunc);
            $callerUseMap    = GlobalCache::getFileUseMap($filePathOfFunc);

            $baseThrows = GlobalCache::getResolvedThrowsForKey($funcKey);
            $throwsFromCallees = [];
            if (isset($funcNode->stmts) && is_array($funcNode->stmts)) {
                $callNodes = array_merge(
                    $finder->findInstanceOf($funcNode->stmts, PhpParser\Node\Expr\MethodCall::class),
                    $finder->findInstanceOf($funcNode->stmts, PhpParser\Node\Expr\StaticCall::class),
                    $finder->findInstanceOf($funcNode->stmts, PhpParser\Node\Expr\FuncCall::class),
                    $finder->findInstanceOf($funcNode->stmts, PhpParser\Node\Expr\New_::class)
                );
                foreach ($callNodes as $callNode) {
                    if ($utils->isNodeAfterExecutionEndingStmt($callNode, $funcNode)) {
                        continue;
                    }
                    $calleeKey = $utils->getCalleeKey($callNode, $callerNamespace, $callerUseMap, $funcNode);
                    if ($calleeKey && $calleeKey !== $funcKey) {
                        foreach (GlobalCache::getResolvedThrowsForKey($calleeKey) as $ex) {
                            if ($utils->isExceptionCaught($callNode, $ex, $funcNode, $callerNamespace, $callerUseMap)) {
                                continue;
                            }
                            $throwsFromCallees[] = $ex;
                        }
                    }
                }
            }
            $newThrows = array_values(array_unique(array_merge($baseThrows, $throwsFromCallees)));
            sort($newThrows);
            GlobalCache::setResolvedThrowsForKey($funcKey, $newThrows);
        }

        $wrapperKey = 'TestNS\\Wrapper::handle';
        $all = GlobalCache::getResolvedThrows();
        $this->assertArrayHasKey($wrapperKey, $all);
        $this->assertSame([], GlobalCache::getResolvedThrowsForKey($wrapperKey));
    }
}
