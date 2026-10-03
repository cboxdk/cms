<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Unit;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\Tests\Process\ProcessEnvironment;
use Cbox\Cms\Identity\Doctor\Domain\Checks\LoginPolicyCheck;
use Cbox\Cms\Identity\Doctor\Domain\Probes\LoginPolicyProbe;
use Cbox\Cms\Identity\IdentityServiceProvider;
use Cbox\Cms\Identity\LoginPolicy\Boundary\LoginPolicyConfig;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\InvalidLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\LocalFactors;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakeLoginPolicyProbe;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;

/*
 * PRD 5.16: a local staff login needs a passkey or two factors. Only local and testing may lower it
 * to a password, so the login policy is refused when it lets staff log in locally with a password
 * alone in any other environment, production included: the reader refuses it, a process that
 * serves HTTP refuses to boot with it, and the blocking doctor check identity.login_policy says why
 * from a console process, which still boots.
 */

/**
 * The module's configuration with $changes merged over its login policy.
 *
 * @param  array<array-key, mixed>  $changes
 */
function staffPolicyConfig(array $changes = []): Repository
{
    /** @var array<string, mixed> $module */
    $module = require __DIR__.'/../../config/identity.php';

    return new Repository(['cbox-cms' => ['identity' => array_replace_recursive($module, ['policy' => $changes])]]);
}

/**
 * Boots an application with the identity module's provider in the given environment, as a process
 * that serves HTTP unless $server says otherwise, and puts the test's container back afterwards.
 *
 * @param  array<array-key, mixed>  $server
 */
function bootIdentityWithPolicy(string $environment, Repository $config, array $server = ['APP_RUNNING_IN_CONSOLE' => 'false']): Application
{
    $current = Container::getInstance();
    $basePath = app()->basePath();

    try {
        return ProcessEnvironment::during(
            array_merge(['APP_RUNNING_IN_CONSOLE' => null, 'LARAVEL_OCTANE' => null, 'argv' => ['vendor/bin/pest']], $server),
            static function () use ($environment, $config, $basePath): Application {
                $app = new Application($basePath);
                $app->instance('config', $config);
                $app['env'] = $environment;
                $app->register(IdentityServiceProvider::class);
                $app->boot();

                return $app;
            },
        );
    } finally {
        Container::setInstance($current);
    }
}

const PASSWORD_FOR_STAFF = ['staff' => ['local_factors' => 'password']];

it('refuses a policy that lets staff log in locally with a password alone outside local and testing', function (string $environment): void {
    expect(static fn (): LoginPolicy => LoginPolicyConfig::read(staffPolicyConfig(PASSWORD_FOR_STAFF), $environment))->toThrow(
        InvalidLoginPolicy::class,
        sprintf('The login policy is invalid: cbox-cms.identity.policy.staff.local_factors must be passkey_or_two_factors in the environment %s, because PRD 5.16 requires a passkey or two factors for a local staff login; password is allowed only in local and testing.', $environment),
    );
})->with(['production', 'staging']);

it('lets staff log in locally with a password alone in local and testing', function (string $environment): void {
    expect(LoginPolicyConfig::read(staffPolicyConfig(PASSWORD_FOR_STAFF), $environment)->staff->localFactors)->toBe(LocalFactors::Password);
})->with(['local', 'testing']);

it('keeps the default and a password for end users in production', function (): void {
    $policy = LoginPolicyConfig::read(staffPolicyConfig(), 'production');

    expect($policy->staff->localFactors)->toBe(LocalFactors::PasskeyOrTwoFactors)
        ->and($policy->endUser->localFactors)->toBe(LocalFactors::Password);
});

it('refuses to boot a process that serves HTTP in production with a password alone for staff', function (): void {
    expect(static fn (): Application => bootIdentityWithPolicy('production', staffPolicyConfig(PASSWORD_FOR_STAFF)))
        ->toThrow(InvalidLoginPolicy::class, 'cbox-cms.identity.policy.staff.local_factors must be passkey_or_two_factors in the environment production');
});

it('refuses to boot a process that serves HTTP with a policy out of form', function (): void {
    expect(static fn (): Application => bootIdentityWithPolicy('production', staffPolicyConfig(['staff' => ['local_login' => 'on']])))
        ->toThrow(InvalidLoginPolicy::class, 'cbox-cms.identity.policy.staff.local_login');
});

it('boots a process that serves HTTP with the default policy, and in local or testing with a password for staff', function (): void {
    expect(bootIdentityWithPolicy('production', staffPolicyConfig())->make(LoginPolicy::class)->staff->localFactors)->toBe(LocalFactors::PasskeyOrTwoFactors)
        ->and(bootIdentityWithPolicy('local', staffPolicyConfig(PASSWORD_FOR_STAFF))->make(LoginPolicy::class)->staff->localFactors)->toBe(LocalFactors::Password)
        ->and(bootIdentityWithPolicy('testing', staffPolicyConfig(PASSWORD_FOR_STAFF))->make(LoginPolicy::class)->staff->localFactors)->toBe(LocalFactors::Password);
});

it('boots a console process with a password for staff in production, so cms:doctor can say why', function (): void {
    expect(bootIdentityWithPolicy('production', staffPolicyConfig(PASSWORD_FOR_STAFF), ['argv' => ['artisan', 'cms:doctor']]))->toBeInstanceOf(Application::class);
});

it('passes identity.login_policy for a policy that holds and fails it with the reason otherwise', function (): void {
    $failure = new LoginPolicyCheck(new FakeLoginPolicyProbe(changes: PASSWORD_FOR_STAFF))->run();
    $verdict = static function (LoginPolicyProbe $probe): array {
        $result = new LoginPolicyCheck($probe)->run();

        return [$result->status, $result->failure, $result->code];
    };

    expect($verdict(new FakeLoginPolicyProbe))->toBe([CheckStatus::Pass, null, null])
        ->and($verdict(new FakeLoginPolicyProbe('local', PASSWORD_FOR_STAFF)))->toBe([CheckStatus::Pass, null, null])
        ->and($verdict(new FakeLoginPolicyProbe(changes: PASSWORD_FOR_STAFF)))->toBe([CheckStatus::Fail, FailureKind::Violation, LoginPolicyCheck::CODE])
        ->and($verdict(new FakeLoginPolicyProbe(changes: ['end_user' => ['inactivity_minutes' => 0]])))->toBe([CheckStatus::Fail, FailureKind::Violation, LoginPolicyCheck::CODE])
        ->and($failure->cause)->toBe('The login policy is invalid: cbox-cms.identity.policy.staff.local_factors must be passkey_or_two_factors in the environment production, because PRD 5.16 requires a passkey or two factors for a local staff login; password is allowed only in local and testing.')
        ->and(new LoginPolicyCheck(new FakeLoginPolicyProbe)->blocking())->toBeTrue()
        ->and(array_map(ErrorCode::tryFrom(...), [LoginPolicyCheck::CODE, InvalidLoginPolicy::CODE]))->not->toContain(null);
});

it('reads the policy of the application for identity.login_policy', function (): void {
    expect(new LoginPolicyCheck(app(LoginPolicyProbe::class))->run()->status)->toBe(CheckStatus::Pass);
});
