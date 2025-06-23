<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    // uncomment to reach your current PHP version
    ->withPhpSets(php74: true)
    ->withDowngradeSets(php74: true)
    // ->withSets([\Rector\DowngradePhp84\Rector\MethodCall\DowngradeNewMethodCallWithoutParenthesesRector::class])
    // ->withPreparedSets(typeDeclarations:true, deadCode: true, codeQuality: true)
    ->withTypeCoverageLevel(50) // max: 50
    ->withDeadCodeLevel(49) // max: 49
    ->withCodeQualityLevel(71) // max: 71
    ->withSets([
        PHPUnitSetList::PHPUNIT_110,
    ])
    ->withRules([
        \Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector::class,
    ])
    ->withSkip([
        \Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector::class,
        \Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector::class,
        \Rector\DeadCode\Rector\ClassMethod\RemoveNullTagValueNodeRector::class,

        // Couple of ones that seem to break Psalm 'perfect' (>99.8%) score.
        \Rector\DeadCode\Rector\Cast\RecastingRemovalRector::class,
        \Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector::class,
        \Rector\CodeQuality\Rector\Foreach_\UnusedForeachValueToArrayKeysRector::class,
    ]);
