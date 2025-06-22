<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\NewIntegration;

use HenkPoley\DocBlockDoctor\Application;
use PHPUnit\Framework\TestCase;

class ApplicationRunIntegrationTest extends TestCase
{
    /**
     * @throws \LogicException
     */
    public function testRunModifiesFilesAndOutputsSummary(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/docblockdoctor-run-' . uniqid();
        mkdir($tmpRoot);
        copy(__DIR__ . '/../fixtures/single-line-method-docblock/InlineDocblock.php', $tmpRoot . '/InlineDocblock.php');
        $expected = file_get_contents(__DIR__ . '/../fixtures/single-line-method-docblock/expected_rewritten.php');

        $app = new Application();
        ob_start();
        $app->run(['doc-block-doctor', '--verbose', $tmpRoot]);
        $output = ob_get_clean();

        $this->assertStringContainsString('=== Summary ===', $output);
        $this->assertStringContainsString('Files read (1):', $output);
        $this->assertStringContainsString('Files fixed (1):', $output);

        $result = file_get_contents($tmpRoot . '/InlineDocblock.php');
        $this->assertSame($expected, $result);

        unlink($tmpRoot . '/InlineDocblock.php');
        rmdir($tmpRoot);
    }
}
