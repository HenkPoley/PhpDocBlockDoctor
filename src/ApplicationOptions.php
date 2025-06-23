<?php

namespace HenkPoley\DocBlockDoctor;

/**
 * Value object holding command line options for the application.
 */
class ApplicationOptions
{
    public bool $verbose = false;

    public bool $quiet = false;

    public bool $traceOrigins = false;

    public bool $traceCallSites = false;

    public bool $ignoreAnnotatedThrows = false;

    public string $rootDir = '';

    /** @var string[]|null */
    public ?array $readDirs = null;

    /** @var string[]|null */
    public ?array $writeDirs = null;
}
