<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\Unit;

use HenkPoley\DocBlockDoctor\Application;
use PHPUnit\Framework\TestCase;

class ApplicationRunTest extends TestCase
{
    public function testRunOutputsHelpAndReturnsZero(): void
    {
        $app = new Application();
        ob_start();
        $result = $app->run(['doc-block-doctor', '--help']);
        $output = ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('--help', $output);
    }
}
