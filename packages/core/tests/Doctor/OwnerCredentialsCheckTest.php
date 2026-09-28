<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Adapter\FrameworkProcessProbe;
use Cbox\Cms\Core\Doctor\Domain\Checks\OwnerCredentialsCheck;
use Cbox\Cms\Core\Doctor\Domain\Probes\ProcessProbe;
use Cbox\Cms\Core\Process\Domain\Workload;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeProcessProbe;
use Cbox\Cms\Core\Tests\Process\ProcessEnvironment;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;

/*
 * postgres.owner_credentials (PRD 4.2, 13): only the maintenance process, a console process whose
 * environment declares it with CBOX_CMS_MAINTENANCE_PROCESS, may hold the owner role's connection.
 */

it('passes a process without the owner connection', function (bool $maintenance, Workload $workload): void {
    $result = new OwnerCredentialsCheck(new FakeProcessProbe(['pgsql'], $workload), 'pgsql_owner', $maintenance)->run();

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->blocking)->toBeFalse()
        ->and($result->explanation)->toBe('The owner connection pgsql_owner is not configured in this process, so code in it cannot log in as the owner role.');
})->with([
    'web' => [false, Workload::Http],
    'queue' => [false, Workload::Queue],
    'console' => [false, Workload::Console],
    'maintenance' => [true, Workload::Console],
]);

it('passes the declared maintenance process in the console', function (): void {
    $result = new OwnerCredentialsCheck(new FakeProcessProbe(['pgsql_owner']), 'pgsql_owner', true)->run();

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->explanation)->toBe('The owner connection pgsql_owner is configured in this process, which CBOX_CMS_MAINTENANCE_PROCESS=true in its environment declares the maintenance process, and it serves no HTTP and runs no queued jobs.');
});

it('fails the owner connection outside the maintenance process, and in any process that serves HTTP or runs queued jobs', function (bool $maintenance, Workload $workload, string $cause): void {
    $result = new OwnerCredentialsCheck(new FakeProcessProbe(['pgsql_owner'], $workload), 'pgsql_owner', $maintenance)->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->blocking)->toBeFalse()
        ->and($result->code)->toBe('doctor_owner_credentials_exposed')
        ->and($result->cause)->toBe($cause)
        ->and($result->fix)->toBe('Give the owner credentials only to the process that runs the migrations, cms:partitions:maintain and its schedule: remove database.connections.pgsql_owner from the configuration of the web and queue processes, which must not share a configuration cache with the maintenance process, and set CBOX_CMS_MAINTENANCE_PROCESS=true in the environment of the maintenance process alone, not in .env or the configuration.');
})->with([
    'a console process that is not declared' => [false, Workload::Console, 'The owner connection pgsql_owner is configured in this process, and CBOX_CMS_MAINTENANCE_PROCESS in its environment does not declare it the maintenance process, so it may be a web or queue process that shares the maintenance process\'s configuration.'],
    'a web process' => [false, Workload::Http, 'The owner connection pgsql_owner is configured in a process that serves HTTP.'],
    'a web process declared the maintenance process' => [true, Workload::Http, 'The owner connection pgsql_owner is configured in a process that serves HTTP.'],
    'a queue worker declared the maintenance process' => [true, Workload::Queue, 'The owner connection pgsql_owner is configured in a process that runs queued jobs.'],
]);

it('affects readiness only and needs no other check', function (): void {
    $check = new OwnerCredentialsCheck(new FakeProcessProbe, 'pgsql_owner', false);

    expect($check->id()->value)->toBe('postgres.owner_credentials')
        ->and($check->blocking())->toBeFalse()
        ->and($check->requires())->toBe([]);
});

it('binds the framework probe, which reads the configuration and the process', function (): void {
    $probe = app(ProcessProbe::class);

    expect($probe)->toBeInstanceOf(FrameworkProcessProbe::class)
        ->and($probe->connectionConfigured('pgsql_owner'))->toBeTrue()
        ->and($probe->connectionConfigured('pgsql_owner_missing'))->toBeFalse()
        ->and($probe->workload())->toBe(Workload::Console);

    config(['database.connections.pgsql_owner' => null]);

    expect($probe->connectionConfigured('pgsql_owner'))->toBeFalse();
});

it('reads the workload in the framework probe from the process, as the core refuses the owner connection by', function (array $server, Workload $workload): void {
    $config = config();
    $basePath = app()->basePath();

    $read = ProcessEnvironment::during(
        array_merge(['APP_RUNNING_IN_CONSOLE' => null, 'LARAVEL_OCTANE' => null, 'argv' => ['artisan', 'cms:doctor']], $server),
        static function () use ($config, $basePath): Workload {
            $current = Container::getInstance();

            try {
                return new FrameworkProcessProbe(new Application($basePath), $config)->workload();
            } finally {
                Container::setInstance($current);
            }
        },
    );

    expect($read)->toBe($workload);
})->with([
    'cms:doctor' => [[], Workload::Console],
    'HTTP' => [['APP_RUNNING_IN_CONSOLE' => 'false'], Workload::Http],
    'Octane' => [['LARAVEL_OCTANE' => '1'], Workload::Http],
    'a queue worker' => [['argv' => ['artisan', 'queue:work']], Workload::Queue],
]);
