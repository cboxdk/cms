<?php

declare(strict_types=1);

// The shared configuration from the testkit (GUARDRAILS 10). This file adds only the paths.

use Rector\Config\RectorConfigBuilder;

/** @var RectorConfigBuilder $config the builder returned by the testkit's rector.php */
$config = require __DIR__.'/vendor/cboxdk/cms-testkit/config/rector.php';

return $config
    ->withPaths([
        __DIR__.'/packages/*/bin',
        __DIR__.'/packages/*/config',
        __DIR__.'/packages/*/database',
        __DIR__.'/packages/*/src',
        __DIR__.'/packages/*/tests',
        __DIR__.'/tests',
        __DIR__.'/tools',
        __DIR__.'/workbench',
    ])
    ->withRootFiles();
