<?php

declare(strict_types=1);

use Cbox\Cms\Cli\CliServiceProvider;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Generators\GeneratorsServiceProvider;
use Cbox\Cms\Http\HttpServiceProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;

it('loads the service providers of core, http, cli and generators through package discovery', function (): void {
    expect(app()->getLoadedProviders())->toHaveKeys([
        CoreServiceProvider::class,
        HttpServiceProvider::class,
        CliServiceProvider::class,
        GeneratorsServiceProvider::class,
    ]);
});

it('boots the workbench provider from testbench.yaml', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(WorkbenchServiceProvider::class);
});
