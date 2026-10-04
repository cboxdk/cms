<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests;

use Cbox\Cms\Core\Tests\Process\ProcessEnvironment;
use Cbox\Cms\Panel\Boundary\DevAddonsEnvironment;
use Cbox\Cms\Panel\Domain\Dto\DevAddons;
use Cbox\Cms\Panel\Domain\InvalidDevAddons;
use Cbox\Cms\Panel\Domain\PanelDevServerForbidden;
use Cbox\Cms\Panel\PanelServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;

/*
 * The panel's provider refuses to boot with CBOX_CMS_PANEL_DEV_ADDONS outside the local
 * environment (PRD 13.4): in a process that serves HTTP or runs queued jobs it throws
 * PanelDevServerForbidden, and with a value it cannot read InvalidDevAddons, so no page is served
 * with a widened policy; a console process boots, so cms:doctor can report panel.dev_server; and
 * a local application boots with the dev servers bound.
 */

/**
 * Boots a fresh application with the panel's provider in the given environment and process.
 *
 * @param  array<array-key, mixed>  $server  the environment variables and argv of the process
 */
function bootPanel(string $environment, array $server): Application
{
    $current = Container::getInstance();
    $basePath = app()->basePath();

    try {
        return ProcessEnvironment::during(
            array_merge(['APP_RUNNING_IN_CONSOLE' => null, 'LARAVEL_OCTANE' => null, 'argv' => ['vendor/bin/pest']], $server),
            static function () use ($environment, $basePath): Application {
                $app = new Application($basePath);
                $app->instance('env', $environment);
                $app->instance('config', new Repository([]));
                $app->register(PanelServiceProvider::class);
                $app->boot();

                return $app;
            },
        );
    } finally {
        Container::setInstance($current);
    }
}

it('refuses to boot a process that serves HTTP or runs queued jobs with the variable outside local', function (array $server, string $environment): void {
    expect(fn (): Application => bootPanel($environment, [DevAddonsEnvironment::VARIABLE => 'tally=http://localhost:5174', ...$server]))
        ->toThrow(PanelDevServerForbidden::class, 'is set in the environment "'.$environment.'"');
})->with([
    'PHP-FPM in production' => [['APP_RUNNING_IN_CONSOLE' => '0'], 'production'],
    'an Octane worker in staging' => [['LARAVEL_OCTANE' => '1', 'argv' => ['vendor/laravel/octane/bin/swoole-server']], 'staging'],
    'a queue worker in testing' => [['argv' => ['artisan', 'queue:work']], 'testing'],
]);

it('refuses to serve pages with a value it cannot read, even in local', function (): void {
    expect(fn (): Application => bootPanel('local', ['APP_RUNNING_IN_CONSOLE' => '0', DevAddonsEnvironment::VARIABLE => 'tally']))
        ->toThrow(InvalidDevAddons::class, 'pair 1 is not <namespace>=<origin>');
});

it('boots a console process with the variable outside local, so cms:doctor can report it', function (): void {
    $app = bootPanel('production', ['argv' => ['artisan', 'cms:doctor'], DevAddonsEnvironment::VARIABLE => 'tally=http://localhost:5174']);

    expect($app->isBooted())->toBeTrue();
});

it('boots a local application that serves HTTP with the dev servers bound, and any application without the variable', function (): void {
    $local = bootPanel('local', ['APP_RUNNING_IN_CONSOLE' => '0', DevAddonsEnvironment::VARIABLE => 'tally=http://localhost:5174']);
    $production = bootPanel('production', ['APP_RUNNING_IN_CONSOLE' => '0', DevAddonsEnvironment::VARIABLE => null]);

    expect($local->isBooted())->toBeTrue()
        ->and($production->isBooted())->toBeTrue()
        ->and(PanelDevServerForbidden::CODE)->toBe('panel_dev_server_forbidden');

    $servers = ProcessEnvironment::during([DevAddonsEnvironment::VARIABLE => 'tally=http://localhost:5174'], static fn (): DevAddons => $local->make(DevAddons::class));

    expect($servers->servers)->toHaveCount(1)
        ->and($servers->servers[0]->origin)->toBe('http://localhost:5174');
});
