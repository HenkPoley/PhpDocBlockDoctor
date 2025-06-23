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
        $fixture = __DIR__ . '/../fixtures/single-line-method-docblock/InlineDocblock.php';
        copy($fixture, $tmpRoot . '/InlineDocblock.php');
        $expectedPath = __DIR__ . '/../fixtures/single-line-method-docblock/expected_rewritten.php';
        $expected = file_get_contents($expectedPath);
        $this->assertNotFalse($expected, 'Failed to read expected file: ' . $expectedPath);

        $app = new Application();
        ob_start();
        $app->run(['doc-block-doctor', '--verbose', $tmpRoot]);
        $output = ob_get_clean();

        $this->assertStringContainsString('=== Summary ===', $output);
        $this->assertStringContainsString('Files read (1):', $output);
        $this->assertStringContainsString('Files fixed (1):', $output);

        $resultPath = $tmpRoot . '/InlineDocblock.php';
        $result = file_get_contents($resultPath);
        $this->assertNotFalse($result, 'Failed to read rewritten file: ' . $resultPath);
        $this->assertSame($expected, $result, $resultPath . ' mismatch');

        unlink($tmpRoot . '/InlineDocblock.php');
        rmdir($tmpRoot);
    }
}
