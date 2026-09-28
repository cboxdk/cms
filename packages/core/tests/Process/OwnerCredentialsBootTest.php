<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Process;

use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Process\Boundary\ProcessWorkload;
use Cbox\Cms\Core\Process\Domain\OwnerCredentialsExposed;
use Cbox\Cms\Core\Process\Domain\Workload;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;

/*
 * Only the maintenance process, a console process, holds the owner role's credentials (PRD 4.2).
 * CoreServiceProvider refuses to boot a process that serves HTTP or runs queued jobs with the
 * owner connection in its configuration. It reads that from the process, so a configuration cache
 * that the web, queue and maintenance processes share cannot hide it. Each test boots a new
 * application with only the core's provider, as the process would.
 */

/**
 * Boots an application with CoreServiceProvider and the given configuration, in a process with
 * the given environment and argv, and puts the test's container back afterwards.
 *
 * @param  array<string, mixed>  $config
 * @param  array<array-key, mixed>  $server
 */
function bootProcess(array $config, array $server): Application
{
    $current = Container::getInstance();
    $basePath = app()->basePath();

    try {
        return ProcessEnvironment::during(
            array_merge(['APP_RUNNING_IN_CONSOLE' => null, 'LARAVEL_OCTANE' => null, 'argv' => ['vendor/bin/pest']], $server),
            static function () use ($config, $basePath): Application {
                $app = new Application($basePath);
                $app->instance('config', new Repository($config));
                $app->register(CoreServiceProvider::class);
                $app->boot();

                return $app;
            },
        );
    } finally {
        Container::setInstance($current);
    }
}

/**
 * @return array<string, mixed>
 */
function withOwnerConnection(string $name = 'pgsql_owner'): array
{
    return ['database' => ['connections' => [
        'pgsql' => ['driver' => 'pgsql', 'username' => 'cms_app'],
        $name => ['driver' => 'pgsql', 'username' => 'cms_owner'],
    ]]];
}

dataset('serving processes', [
    'PHP-FPM or php artisan serve' => [['APP_RUNNING_IN_CONSOLE' => 'false'], Workload::Http, 'a process that serves HTTP'],
    'an Octane worker, which PHP runs in the console' => [['LARAVEL_OCTANE' => '1', 'argv' => ['vendor/laravel/octane/bin/swoole-server']], Workload::Http, 'a process that serves HTTP'],
    'queue:work' => [['argv' => ['artisan', 'queue:work']], Workload::Queue, 'a process that runs queued jobs'],
    'queue:work after an option' => [['argv' => ['artisan', '--env=production', 'queue:work', 'redis']], Workload::Queue, 'a process that runs queued jobs'],
    'queue:listen' => [['argv' => ['artisan', 'queue:listen']], Workload::Queue, 'a process that runs queued jobs'],
    'horizon' => [['argv' => ['artisan', 'horizon']], Workload::Queue, 'a process that runs queued jobs'],
    'a Horizon supervisor' => [['argv' => ['artisan', 'horizon:supervisor', 'supervisor-1']], Workload::Queue, 'a process that runs queued jobs'],
    'a Horizon worker' => [['argv' => ['artisan', 'horizon:work', 'redis']], Workload::Queue, 'a process that runs queued jobs'],
]);

dataset('console processes', [
    'migrate on the owner connection' => [['argv' => ['artisan', 'migrate', '--database=pgsql_owner', '--force']]],
    'the scheduler' => [['argv' => ['artisan', 'schedule:work']]],
    'cms:doctor' => [['argv' => ['artisan', 'cms:doctor', '--json']]],
    'help for queue:work' => [['argv' => ['artisan', 'help', 'queue:work']]],
]);

it('refuses to boot a process that serves HTTP or runs queued jobs with the owner connection configured', function (array $server, Workload $workload, string $described): void {
    expect(static fn (): Application => bootProcess(withOwnerConnection(), $server))->toThrow(
        OwnerCredentialsExposed::class,
        sprintf('[owner_credentials_exposed] The owner connection [pgsql_owner] is configured in %s. Only the maintenance process, a console process that runs the migrations and the scheduler, may hold the owner role\'s credentials. Remove database.connections.pgsql_owner from the configuration of the web and queue processes, which must not share a configuration cache with the maintenance process.', $described),
    );
})->with('serving processes');

it('refuses it whatever the configuration says about the maintenance process', function (array $server): void {
    // A key in a configuration cache that every process shares declares every process alike.
    $config = array_merge(withOwnerConnection(), ['cbox-cms' => ['doctor' => ['maintenance_process' => true]]]);

    expect(static fn (): Application => bootProcess($config, $server))->toThrow(OwnerCredentialsExposed::class);
})->with([
    'HTTP' => [['APP_RUNNING_IN_CONSOLE' => 'false']],
    'Octane' => [['LARAVEL_OCTANE' => '1']],
    'a queue worker' => [['argv' => ['artisan', 'queue:work']]],
]);

it('refuses the owner connection that cbox-cms.doctor.owner_connection names as well', function (): void {
    $config = array_merge(withOwnerConnection('maintenance'), ['cbox-cms' => ['doctor' => ['owner_connection' => 'maintenance']]]);

    expect(static fn (): Application => bootProcess($config, ['APP_RUNNING_IN_CONSOLE' => 'false']))
        ->toThrow(OwnerCredentialsExposed::class, 'The owner connection [maintenance] is configured in a process that serves HTTP.');
});

it('boots a process that serves HTTP or runs queued jobs without the owner connection', function (array $server): void {
    $app = bootProcess(['database' => ['connections' => ['pgsql' => ['driver' => 'pgsql']]]], $server);

    expect($app->isBooted())->toBeTrue()
        ->and(CoreServiceProvider::ownerConnectionConfigured($app->make('config')))->toBeFalse();
})->with('serving processes');

it('boots a console process with the owner connection', function (array $server): void {
    $app = bootProcess(withOwnerConnection(), $server);

    expect($app->isBooted())->toBeTrue()
        ->and(CoreServiceProvider::ownerConnectionConfigured($app->make('config')))->toBeTrue();
})->with('console processes');

it('reads the workload from the process', function (array $server, Workload $workload): void {
    $read = ProcessEnvironment::during(
        array_merge(['APP_RUNNING_IN_CONSOLE' => null, 'LARAVEL_OCTANE' => null], $server),
        static function (): Workload {
            $current = Container::getInstance();

            try {
                return ProcessWorkload::of(new Application(app()->basePath()));
            } finally {
                Container::setInstance($current);
            }
        },
    );

    expect($read)->toBe($workload)
        ->and($read->mayHoldOwnerCredentials())->toBeFalse();
})->with('serving processes');

it('lets only a console process hold the owner credentials', function (): void {
    expect(Workload::Console->mayHoldOwnerCredentials())->toBeTrue()
        ->and(Workload::Http->mayHoldOwnerCredentials())->toBeFalse()
        ->and(Workload::Queue->mayHoldOwnerCredentials())->toBeFalse()
        ->and(Workload::Console->described())->toBe('a console process');
});
