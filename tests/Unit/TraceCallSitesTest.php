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
use HenkPoley\DocBlockDoctor\DocBlockUpdater;

class TraceCallSitesTest extends TestCase
{
    /**
     * @throws \LogicException
     */
    public function testTraceCallSitesAddsLineNumbers(): void
    {
        $code = "<?php\nfunction foo() {\n    throw new \RuntimeException();\n}\n";
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
            $direct    = GlobalCache::$directThrows[$key] ?? [];
            $annotated = GlobalCache::$annotatedThrows[$key] ?? [];
            $combined  = array_values(array_unique(array_merge($direct, $annotated)));
            sort($combined);
            GlobalCache::$resolvedThrows[$key] = $combined;
        }

        $tr2 = new NodeTraverser();
        $tr2->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $tr2->addVisitor(new ParentConnectingVisitor());
        $updater = new DocBlockUpdater($utils, 'dummy.php', false, true, true);
        $tr2->addVisitor($updater);
        $tr2->traverse($ast);

        $this->assertNotEmpty($updater->pendingPatches);
        $patch = $updater->pendingPatches[0];
        $this->assertStringContainsString('@throws \\RuntimeException :3', $patch['newDocText']);
    }
}
