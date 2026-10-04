<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\Tests\Process\ProcessEnvironment;
use Cbox\Cms\Panel\Boundary\DevAddonsEnvironment;
use Cbox\Cms\Panel\Doctor\Boundary\EnvironmentDevServerProbe;
use Cbox\Cms\Panel\Doctor\Domain\Checks\DevServerCheck;
use Cbox\Cms\Panel\Tests\Doctor\Fakes\FakeDevServerProbe;

/*
 * cms:doctor's panel.dev_server (PRD 13.4): CBOX_CMS_PANEL_DEV_ADDONS is set only in the local
 * environment and can be read; it blocks and fails as a violation with
 * doctor_panel_dev_server_forbidden, because a process that serves HTTP refuses to boot with it.
 */

it('passes when the variable is not set, and in a local application that can read it', function (): void {
    $check = new DevServerCheck(new FakeDevServerProbe);

    expect($check->blocking())->toBeTrue()
        ->and($check->requires())->toBe([])
        ->and($check->run()->status)->toBe(CheckStatus::Pass)
        ->and($check->run()->explanation)->toContain('is not set');

    $local = new DevServerCheck(new FakeDevServerProbe('tally=http://localhost:5174,approvals=http://localhost:5175', 'local'))->run();

    expect($local->status)->toBe(CheckStatus::Pass)
        ->and($local->explanation)->toBe('The local application loads the panel UI of 2 addons from a dev server: approvals from http://localhost:5175, tally from http://localhost:5174.');
});

it('fails as a violation with doctor_panel_dev_server_forbidden outside local, and for a value it cannot read', function (?string $setting, string $environment, string $cause): void {
    $result = new DevServerCheck(new FakeDevServerProbe($setting, $environment))->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->code)->toBe(DevServerCheck::CODE)
        ->and(ErrorCode::tryFrom((string) $result->code))->toBe(ErrorCode::DoctorPanelDevServerForbidden)
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->blocking)->toBeTrue()
        ->and($result->cause)->toContain($cause)
        ->and($result->fix)->toContain(DevAddonsEnvironment::VARIABLE);
})->with([
    'production' => ['tally=http://localhost:5174', 'production', 'The application\'s environment is "production"'],
    'testing' => ['tally=http://localhost:5174', 'testing', 'The application\'s environment is "testing"'],
    'local with a value that is no pair' => ['tally', 'local', 'pair 1 is not <namespace>=<origin>'],
    'local with another host' => ['tally=https://cdn.example.test', 'local', 'not a loopback origin'],
]);

it('reads the variable and the environment from the process and the application', function (): void {
    $probe = new EnvironmentDevServerProbe(app());

    expect($probe->environment())->toBe('testing')
        ->and(ProcessEnvironment::during([DevAddonsEnvironment::VARIABLE => 'tally=http://localhost:5174'], static fn (): ?string => $probe->setting()))->toBe('tally=http://localhost:5174')
        ->and(ProcessEnvironment::during([DevAddonsEnvironment::VARIABLE => null], static fn (): ?string => $probe->setting()))->toBeNull();
});
