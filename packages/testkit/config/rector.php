<?php

declare(strict_types=1);

/*
 * Shared Rector configuration for the Cbox CMS kernel, the app template and addons.
 * GUARDRAILS 10, gate 2: PHP 8.5, Laravel 13, type declarations, code quality and dead code.
 *
 * A repo's rector.php requires this file and adds only its own paths:
 *
 *     return (require __DIR__.'/vendor/cboxdk/cms/packages/testkit/config/rector.php')
 *         ->withPaths([__DIR__.'/src', __DIR__.'/tests']);
 *
 * CI runs it as `rector process --dry-run`, which must report no changes.
 */

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelLevelSetList;

return RectorConfig::configure()
    ->withPhpSets(php85: true)
    ->withSets([LaravelLevelSetList::UP_TO_LARAVEL_130])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
