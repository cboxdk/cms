<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Adapter\FrameworkProcessProbe;
use Cbox\Cms\Core\Doctor\Domain\Checks\OwnerCredentialsCheck;
use Cbox\Cms\Core\Doctor\Domain\Probes\ProcessProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeProcessProbe;

/*
 * postgres.owner_credentials (PRD 4.2, 13): only the maintenance process, which serves no HTTP,
 * may hold the owner role's connection.
 */

it('passes a process without the owner connection', function (bool $maintenance, bool $http): void {
    $result = new OwnerCredentialsCheck(new FakeProcessProbe(['pgsql'], $http), 'pgsql_owner', $maintenance)->run();

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->blocking)->toBeFalse()
        ->and($result->explanation)->toBe('The owner connection pgsql_owner is not configured in this process, so code in it cannot log in as the owner role.');
})->with([
    'web' => [false, true],
    'console' => [false, false],
    'maintenance' => [true, false],
]);

it('passes the declared maintenance process in the console', function (): void {
    $result = new OwnerCredentialsCheck(new FakeProcessProbe(['pgsql_owner']), 'pgsql_owner', true)->run();

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->explanation)->toContain('cbox-cms.doctor.maintenance_process declares the maintenance process');
});

it('fails the owner connection outside the maintenance process, and in any process that serves HTTP', function (bool $maintenance, bool $http, string $cause): void {
    $result = new OwnerCredentialsCheck(new FakeProcessProbe(['pgsql_owner'], $http), 'pgsql_owner', $maintenance)->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->blocking)->toBeFalse()
        ->and($result->code)->toBe('doctor_owner_credentials_exposed')
        ->and($result->cause)->toBe($cause)
        ->and($result->fix)->toContain('remove database.connections.pgsql_owner from the configuration of the web and queue processes');
})->with([
    'a console process that is not declared' => [false, false, 'The owner connection pgsql_owner is configured in this process, and cbox-cms.doctor.maintenance_process does not declare it the maintenance process, so it shares its configuration with the web and queue processes.'],
    'a web process' => [false, true, 'The owner connection pgsql_owner is configured in a process that serves HTTP.'],
    'a web process declared the maintenance process' => [true, true, 'The owner connection pgsql_owner is configured in a process that serves HTTP.'],
]);

it('affects readiness only and needs no other check', function (): void {
    $check = new OwnerCredentialsCheck(new FakeProcessProbe, 'pgsql_owner', false);

    expect($check->id()->value)->toBe('postgres.owner_credentials')
        ->and($check->blocking())->toBeFalse()
        ->and($check->requires())->toBe([]);
});

it('binds the framework probe, which reads the configuration and the console', function (): void {
    $probe = app(ProcessProbe::class);

    expect($probe)->toBeInstanceOf(FrameworkProcessProbe::class)
        ->and($probe->connectionConfigured('pgsql_owner'))->toBeTrue()
        ->and($probe->connectionConfigured('pgsql_owner_missing'))->toBeFalse()
        ->and($probe->servesHttp())->toBeFalse();

    config(['database.connections.pgsql_owner' => null]);

    expect($probe->connectionConfigured('pgsql_owner'))->toBeFalse();
});
