<?php

declare(strict_types=1);

// The shared configuration from the testkit (GUARDRAILS 10). This file adds only the paths and
// keeps Rector's caches in this checkout.

use Rector\Configuration\RectorConfigBuilder;

// Rector's file cache and the container cache of the PHPStan it runs default to the shared system
// temp directory; here they stay in the git-ignored .cache/ of this checkout, so parallel worktrees
// keep their own. They are siblings, because Rector clears its file cache by removing the whole
// directory. Rector creates the file cache itself but requires the container cache to exist.
$cache = __DIR__.'/.cache/rector';

if (! is_dir($cache.'/container') && ! mkdir($cache.'/container', 0o777, true) && ! is_dir($cache.'/container')) {
    throw new RuntimeException("Cannot create Rector's container cache directory {$cache}/container.");
}

/** @var RectorConfigBuilder $config the builder returned by the testkit's rector.php */
$config = require __DIR__.'/packages/testkit/config/rector.php';

return $config
    ->withPaths([
        __DIR__.'/examples',
        __DIR__.'/packages/*/bin',
        __DIR__.'/packages/*/config',
        __DIR__.'/packages/*/database',
        __DIR__.'/packages/*/src',
        __DIR__.'/packages/*/tests',
        __DIR__.'/tests',
        __DIR__.'/tools',
        __DIR__.'/workbench',
    ])
    ->withRootFiles()
    ->withCache(cacheDirectory: $cache.'/files', containerCacheDirectory: $cache.'/container');
