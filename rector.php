<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    // uncomment to reach your current PHP version
    ->withPhpSets(php74: true)
    ->withDowngradeSets(php74: true)
    // ->withPreparedSets(typeDeclarations:true, deadCode: true, codeQuality: true)
    ->withTypeCoverageLevel(50) // max: 50
    ->withDeadCodeLevel(49) // max: 49
    ->withCodeQualityLevel(71); // max: 71