<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\Tests\Unit;

use HenkPoley\DocBlockDoctor\Application;
use HenkPoley\DocBlockDoctor\ApplicationOptions;
use PHPUnit\Framework\TestCase;

class ApplicationParseOptionsTest extends TestCase
{
    /**
     * Helper to invoke the private parseOptions method.
     *
     * @param string[] $args
     */
    private function parse(array $args): ApplicationOptions
    {
        $app = new Application();
        $ref = new \ReflectionMethod(Application::class, 'parseOptions');
        $ref->setAccessible(true);
        /** @var ApplicationOptions $opt */
        $opt = $ref->invoke($app, $args);
        return $opt;
    }

    public function testDefaultsWhenNoPathProvided(): void
    {
        $orig = getcwd();
        $tmp = sys_get_temp_dir() . '/docblockdoctor-' . uniqid();
        mkdir($tmp);
        chdir($tmp);
        $opt = $this->parse(['doc-block-doctor']);
        chdir($orig);

        $this->assertSame(realpath($tmp), $opt->rootDir);
        $this->assertFalse($opt->verbose);
        $this->assertNull($opt->readDirs);
        $this->assertNull($opt->writeDirs);

        rmdir($tmp);
    }

    public function testFlagsAndDirectoriesParsed(): void
    {
        $opt = $this->parse([
            'doc-block-doctor',
            '-v',
            '--trace-throw-origins',
            '--trace-throw-call-sites',
            '--ignore-annotated-throws',
            '--read-dirs=src,tests',
            '--write-dirs=src,generated',
            '/my/project/',
        ]);

        $this->assertTrue($opt->verbose);
        $this->assertTrue($opt->traceOrigins);
        $this->assertTrue($opt->traceCallSites);
        $this->assertTrue($opt->ignoreAnnotatedThrows);
        $this->assertSame('/my/project', $opt->rootDir);
        $this->assertSame(['src', 'tests'], $opt->readDirs);
        $this->assertSame(['src', 'generated'], $opt->writeDirs);
    }
}
