<?php
declare(strict_types=1);

use HenkPoley\DocBlockDoctor\AstUtils;
use HenkPoley\DocBlockDoctor\ThrowsGatherer;
use HenkPoley\DocBlockDoctor\GlobalCache;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;

class AutoloadingTest extends TestCase
{
    private AstUtils $utils;
    private NodeFinder $finder;

    protected function setUp(): void
    {
        $this->utils = new AstUtils();
        $this->finder = new NodeFinder();
        GlobalCache::clear();
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function testThrowsGathererDoesNotAutoloadMissingClasses(): void
    {
        $code = <<<'PHP'
        <?php
        function foo() {
            throw new \Nonexistent\CustomException();
        }
        PHP;

        $autoloadCalled = false;
        $loader = function ($class) use (&$autoloadCalled) {
            $autoloadCalled = true;
            throw new RuntimeException('Autoload attempted for ' . $class);
        };
        spl_autoload_register($loader);
        try {
            $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
            $ast = $parser->parse($code) ?: [];
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
            $traverser->addVisitor(new ParentConnectingVisitor());
            $tg = new ThrowsGatherer($this->finder, $this->utils, 'dummy');
            $traverser->addVisitor($tg);
            try {
                $traverser->traverse($ast);
            } catch (RuntimeException $e) {
                $this->fail('Autoloader was triggered: ' . $e->getMessage());
            }
        } finally {
            spl_autoload_unregister($loader);
        }

        $this->assertFalse($autoloadCalled, 'Autoloader should not be called');
        // Non-existent classes should be filtered out
        $this->assertSame([], GlobalCache::getDirectThrowsForKey('foo'));
    }

    /**
     * @throws \LogicException
     */
    public function testThrowsGathererDoesNotAutoloadVendorClasses(): void
    {

        $code = "<?php
use Vend\\ChildException;
use Vend\\ParentException;
function fooVendor() {
    try {
        throw new ChildException();
    } catch (ParentException \$e) {
    }
}
";
        // Prepare a Composer class loader that points to a directory containing
        // "vendor" in its path, simulating third-party dependencies.
        $loader = new class extends \Composer\Autoload\ClassLoader {
            public bool $loaded = false;
            public function loadClass($class)
            {
                $this->loaded = true;
                return parent::loadClass($class);
            }
        };
        $loader->addPsr4('Vend\\', __DIR__ . '/../unit_fixtures/vendor-catch/vendor/Vend');
        $loader->register(false);

        try {
            $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
            $ast = $parser->parse($code) ?: [];
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
            $traverser->addVisitor(new ParentConnectingVisitor());
            $tg = new ThrowsGatherer($this->finder, $this->utils, 'dummy');
            $traverser->addVisitor($tg);
            $traverser->traverse($ast);
        } finally {
            $loader->unregister();
        }

        // The vendor class loader should not have been triggered.
        $this->assertFalse($loader->loaded, 'Vendor classes should not be autoloaded');
    }
}
