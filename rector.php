<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveArgumentFromDefaultParentCallRector;
use Rector\DeadCode\Rector\MethodCall\RemoveNullArgOnNullDefaultParamRector;
use Rector\Symfony\Set\SymfonySetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withPhpVersion(PhpVersion::PHP_74)
    ->withPhpSets()
    ->withSets([
        SymfonySetList::COMPOSER_BASED,
        SymfonySetList::SYMFONY_CODE_QUALITY,
    ])
    ->withCodeQualityLevel(0)
    ->withDeadCodeLevel(0)
    ->withTypeCoverageLevel(0)
    ->withRules([
        RemoveArgumentFromDefaultParentCallRector::class,
        RemoveNullArgOnNullDefaultParamRector::class,
    ])
    ->withImportNames();
