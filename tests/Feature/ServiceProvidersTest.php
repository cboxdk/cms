<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature;

use Cbox\Cms\Cli\CliServiceProvider;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Tests\Process\ProcessEnvironment;
use Cbox\Cms\Generators\GeneratorsServiceProvider;
use Cbox\Cms\Http\HttpServiceProvider;
use Cbox\Cms\Mcp\McpServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Workbench\App\Providers\WorkbenchServiceProvider;

it('loads the service providers of core, http, mcp, cli and generators through package discovery', function (): void {
    expect(app()->getLoadedProviders())->toHaveKeys([
        CoreServiceProvider::class,
        HttpServiceProvider::class,
        McpServiceProvider::class,
        CliServiceProvider::class,
        GeneratorsServiceProvider::class,
    ]);
});

it('boots the workbench provider from testbench.yaml', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(WorkbenchServiceProvider::class);
});

it('gives pgsql_owner to the workbench\'s console processes only, so a process of it that serves HTTP boots', function (array $server, bool $owner): void {
    // The core refuses to boot a process that serves HTTP or runs queued jobs with the owner
    // connection (PRD 4.2); `testbench serve` is such a process.
    $current = Container::getInstance();
    $basePath = app()->basePath();

    try {
        $app = ProcessEnvironment::during(
            array_merge(['APP_RUNNING_IN_CONSOLE' => null, 'LARAVEL_OCTANE' => null, 'argv' => ['vendor/bin/testbench', 'migrate']], $server),
            static function () use ($basePath): Application {
                $app = new Application($basePath);
                $app->instance('config', new Repository(['database' => ['connections' => ['pgsql' => ['driver' => 'pgsql', 'username' => 'cms_app']]]]));
                $app->register(WorkbenchServiceProvider::class);
                $app->register(CoreServiceProvider::class);
                $app->boot();

                return $app;
            },
        );
    } finally {
        Container::setInstance($current);
    }

    expect($app->isBooted())->toBeTrue()
        ->and(CoreServiceProvider::ownerConnectionConfigured($app->make('config')))->toBe($owner);
})->with([
    'the console' => [[], true],
    'testbench serve' => [['APP_RUNNING_IN_CONSOLE' => 'false'], false],
]);
