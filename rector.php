<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    // uncomment to reach your current PHP version
    ->withPhpSets(php71: true)
    ->withDowngradeSets(php71: true)
    ->withTypeCoverageLevel(50) // max: 50
    ->withDeadCodeLevel(49) // max: 49
    ->withCodeQualityLevel(64); // max: 71