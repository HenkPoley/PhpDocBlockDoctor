<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\DowngradeLevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    // uncomment to reach your current PHP version
    ->withPhpSets(php71: true)
    ->withTypeCoverageLevel(20)
    ->withDeadCodeLevel(1)
    ->withCodeQualityLevel(0)
    ->withSets([DowngradeLevelSetList::DOWN_TO_PHP_71]);