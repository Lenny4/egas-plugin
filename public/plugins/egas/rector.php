<?php
declare(strict_types=1);

use Rector\CodeQuality\Rector\Empty_\SimplifyEmptyCheckOnEmptyArrayRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodingStyle\Rector\ClassMethod\NewlineBeforeNewAssignSetRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;
use Utils\Rector\Rector\JsonUnescapedUnicodeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/includes',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        naming: true,
        instanceOf: true,
        earlyReturn: true,
        strictBooleans: false,

//        carbon: true,
//        rectorPreset: true,
    )
    ->withPhpSets(php82: true)
    ->withRules([
        JsonUnescapedUnicodeRector::class,
    ])
    ->withRules([
        DeclareStrictTypesRector::class,
    ])
    ->withSkip([
        DisallowedEmptyRuleFixerRector::class,
        FlipTypeControlToUseExclusiveTypeRector::class,
        ExplicitBoolCompareRector::class,
        SimplifyEmptyCheckOnEmptyArrayRector::class,
        NewlineAfterStatementRector::class,
        NewlineBeforeNewAssignSetRector::class,
    ]);
