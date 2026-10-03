<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Unit;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationContext;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\VerifiedAssertion;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Identity\LoginPolicy\Actions\CheckLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Adapter\PostgresIdpLinks;
use Cbox\Cms\Identity\LoginPolicy\Boundary\LoginPolicyConfig;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginAttempt;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\IdpLinks;
use Cbox\Cms\Identity\LoginPolicy\Domain\InvalidLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\LocalFactors;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginDecision;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginPolicyErrorCode;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginPolicyRefused;
use Cbox\Cms\Identity\Tests\LoginPolicy\LoginWorld;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Assert;
use ReflectionClass;

/*
 * The login policy per actor class and environment (PRD 5.16, "Loginpolitik", invariant 38): every
 * login path asks CheckLoginPolicy, and only its LoginDecision issues a session. The workbench's
 * environment lets a member of staff log in locally with a password, the default policy requires a
 * passkey or two factors, and a local login of an actor linked to an authoritative connection is
 * refused, read through the IdP links port.
 */

const LOGIN_NOW = LoginWorld::NOW;

/**
 * The policy of identity.php, the module's defaults, with $changes merged over it as an
 * application's configuration would be.
 *
 * @param  array<array-key, mixed>  $changes
 */
function defaultPolicy(array $changes = [], string $environment = 'testing'): LoginPolicy
{
    return LoginPolicyConfig::read(policyConfig($changes), $environment);
}

/**
 * The module's configuration with $changes merged over its login policy.
 *
 * @param  array<array-key, mixed>  $changes
 */
function policyConfig(array $changes = []): Repository
{
    /** @var array<string, mixed> $module */
    $module = require __DIR__.'/../../config/identity.php';

    return new Repository(['cbox-cms' => ['identity' => array_replace_recursive($module, ['policy' => $changes])]]);
}

/**
 * @param  list<string>  $amr
 */
function loginAttempt(ActorId $actor, LoginMethod $method = LoginMethod::Password, string $connection = 'local', array $amr = ['pwd'], ?string $acr = null): LoginAttempt
{
    $issuer = $connection === 'local' ? 'http://localhost' : 'https://login.example.test';

    return new LoginAttempt($actor, $method, new VerifiedAssertion(
        new ConnectionId($connection),
        new Issuer($issuer),
        new Subject('subject-1'),
        new DateTimeImmutable(LOGIN_NOW),
        array_map(static fn (string $value): AuthenticationMethod => new AuthenticationMethod($value), $amr),
        $acr === null ? null : new AuthenticationContext($acr),
    ));
}

function expectRefused(LoginWorld $world, LoginAttempt $attempt, LoginPolicyErrorCode $reason): void
{
    try {
        $world->check()->check($attempt);
    } catch (LoginPolicyRefused $refused) {
        expect($refused->reason)->toBe($reason)
            ->and($refused->getMessage())->not->toContain($attempt->actor->toString());

        return;
    }

    Assert::fail("Expected the login policy to refuse the login with {$reason->value}.");
}

it('allows a staff password login in the workbench environment and decides what the session stores', function (): void {
    $world = new LoginWorld(app(LoginPolicy::class));
    $actor = $world->actor();

    $decision = $world->check()->check(loginAttempt($actor->id));

    expect(app(LoginPolicy::class)->staff->localFactors)->toBe(LocalFactors::Password)
        ->and($decision->actor->equals($actor->id))->toBeTrue()
        ->and($decision->actorClass)->toBe(ActorClass::Staff)
        ->and($decision->credentialGeneration)->toEqual($actor->credentialGeneration)
        ->and($decision->connection->value)->toBe('local')
        ->and($decision->method)->toBe(LoginMethod::Password)
        ->and($decision->authTime->format(DATE_ATOM))->toBe('2026-10-02T09:00:00+00:00')
        ->and($decision->lifetimes->inactivityMinutes)->toBe(60)
        ->and($decision->lifetimes->absoluteMinutes)->toBe(720);
});

it('refuses a staff password login with login_factors_unavailable where the environment requires factors', function (): void {
    $world = new LoginWorld(defaultPolicy());

    expect($world->policy->staff->localFactors)->toBe(LocalFactors::PasskeyOrTwoFactors);

    expectRefused($world, loginAttempt($world->actor()->id), LoginPolicyErrorCode::FactorsUnavailable);
});

it('accepts a passkey or two factors where the environment requires factors', function (LoginMethod $method, string $amr): void {
    $world = new LoginWorld(defaultPolicy());

    expect($world->check()->check(loginAttempt($world->actor()->id, $method, amr: explode(',', $amr)))->method)->toBe($method);
})->with([
    'a passkey' => [LoginMethod::Passkey, 'hwk'],
    'a password and a one-time code' => [LoginMethod::Password, 'pwd,otp'],
    'a password with mfa in amr' => [LoginMethod::Password, 'pwd,mfa'],
]);

it('refuses a method the policy does not list', function (): void {
    $world = new LoginWorld(defaultPolicy(['staff' => ['local_factors' => 'password', 'methods' => ['password' => false]]]));

    expect($world->policy->staff->allowsMethod(LoginMethod::Password))->toBeFalse()
        ->and($world->policy->staff->allowsMethod(LoginMethod::MagicLink))->toBeFalse();

    expectRefused($world, loginAttempt($world->actor()->id), LoginPolicyErrorCode::MethodNotAllowed);
    expectRefused($world, loginAttempt($world->actor()->id, LoginMethod::MagicLink), LoginPolicyErrorCode::MethodNotAllowed);
});

it('refuses a method that does not belong to the connection', function (): void {
    $world = new LoginWorld(defaultPolicy(['staff' => ['local_factors' => 'password', 'connections' => ['entra' => true]]]));

    expectRefused($world, loginAttempt($world->actor()->id, LoginMethod::Password, 'entra'), LoginPolicyErrorCode::MethodNotAllowed);
    expectRefused($world, loginAttempt($world->actor()->id, LoginMethod::Federated), LoginPolicyErrorCode::MethodNotAllowed);
});

it('refuses an actor that is not active, and one that does not exist', function (ActorState $state): void {
    $world = new LoginWorld(app(LoginPolicy::class));

    expectRefused($world, loginAttempt($world->actor(state: $state)->id), LoginPolicyErrorCode::ActorNotActive);
})->with([ActorState::Pending, ActorState::Deactivated, ActorState::Deprovisioned]);

it('refuses an actor the directory does not have', function (): void {
    $world = new LoginWorld(app(LoginPolicy::class));

    expectRefused($world, loginAttempt(ActorId::fromString('0199c1f0-0000-7000-8000-000000000000')), LoginPolicyErrorCode::ActorNotActive);
});

it('refuses a service actor, which never logs in, before it looks at the state', function (ActorState $state): void {
    $world = new LoginWorld(app(LoginPolicy::class));

    expect($world->policy->of(ActorClass::Service))->toBeNull();

    expectRefused($world, loginAttempt($world->actor(ActorClass::Service, $state)->id), LoginPolicyErrorCode::ClassNotAllowed);
})->with([ActorState::Active, ActorState::Pending]);

it('refuses a local login of an actor linked to an authoritative connection (invariant 38)', function (): void {
    $world = new LoginWorld(defaultPolicy([
        'authoritative_connections' => ['entra'],
        'staff' => ['local_factors' => 'password', 'connections' => ['entra' => true]],
    ]));
    $linked = $world->actor();
    $world->links->link($linked->id, new IdpIdentity(new ConnectionId('entra'), new Issuer('https://login.example.test'), new Subject('s-1')));

    expectRefused($world, loginAttempt($linked->id), LoginPolicyErrorCode::AuthoritativeLink);
    expect($world->links->reads())->toBe(1);

    // The same actor through the authoritative connection, and an actor without the link locally, log in.
    expect($world->check()->check(loginAttempt($linked->id, LoginMethod::Federated, 'entra'))->connection->value)->toBe('entra')
        ->and($world->check()->check(loginAttempt($world->actor()->id))->connection->value)->toBe('local');
});

it('lets an actor linked to a connection that is not authoritative keep its local login', function (): void {
    $world = new LoginWorld(defaultPolicy(['staff' => ['local_factors' => 'password', 'connections' => ['google' => true]]]));
    $actor = $world->actor();
    $world->links->link($actor->id, new IdpIdentity(new ConnectionId('google'), new Issuer('https://accounts.google.com'), new Subject('s-1')));

    expect($world->check()->check(loginAttempt($actor->id))->method)->toBe(LoginMethod::Password)
        ->and($world->links->reads())->toBe(1);
});

it('refuses a connection the class does not list, and local login switched off', function (): void {
    $world = new LoginWorld(defaultPolicy(['staff' => ['local_factors' => 'password', 'connections' => ['local' => false, 'entra' => true]]]));

    expectRefused($world, loginAttempt($world->actor()->id), LoginPolicyErrorCode::ConnectionNotAllowed);
    expectRefused($world, loginAttempt($world->actor()->id, LoginMethod::Federated, 'okta'), LoginPolicyErrorCode::ConnectionNotAllowed);

    $off = new LoginWorld(defaultPolicy(['staff' => ['local_factors' => 'password', 'local_login' => false]]));

    expectRefused($off, loginAttempt($off->actor()->id), LoginPolicyErrorCode::LocalDisabled);
});

it('requires MFA in amr or acr of a federated login only when the policy names it', function (): void {
    $plain = new LoginWorld(defaultPolicy(['staff' => ['connections' => ['entra' => true]]]));

    expect($plain->check()->check(loginAttempt($plain->actor()->id, LoginMethod::Federated, 'entra', amr: []))->method)->toBe(LoginMethod::Federated);

    $world = new LoginWorld(defaultPolicy(['staff' => ['connections' => ['entra' => true], 'federated_amr' => ['mfa'], 'federated_acr' => ['urn:acr:mfa']]]));
    $actor = $world->actor()->id;

    expectRefused($world, loginAttempt($actor, LoginMethod::Federated, 'entra', amr: ['pwd']), LoginPolicyErrorCode::FactorsUnavailable);
    expectRefused($world, loginAttempt($actor, LoginMethod::Federated, 'entra', amr: [], acr: 'urn:acr:pwd'), LoginPolicyErrorCode::FactorsUnavailable);
    expect($world->check()->check(loginAttempt($actor, LoginMethod::Federated, 'entra', amr: ['pwd', 'mfa']))->connection->value)->toBe('entra')
        ->and($world->check()->check(loginAttempt($actor, LoginMethod::Federated, 'entra', amr: [], acr: 'urn:acr:mfa'))->connection->value)->toBe('entra');
});

it('gives end users their own policy: 30 days of inactivity and 90 days in all, and a password alone', function (): void {
    $world = new LoginWorld(defaultPolicy());

    $decision = $world->check()->check(loginAttempt($world->actor(ActorClass::EndUser)->id, LoginMethod::MagicLink));

    expect($decision->actorClass)->toBe(ActorClass::EndUser)
        ->and($decision->lifetimes->inactivityMinutes)->toBe(30 * 24 * 60)
        ->and($decision->lifetimes->absoluteMinutes)->toBe(90 * 24 * 60);
});

it('counts every decision by outcome and reason code, with no actor id', function (): void {
    $world = new LoginWorld(defaultPolicy());
    $actor = $world->actor();

    $world->check()->check(loginAttempt($actor->id, LoginMethod::Passkey, amr: ['hwk']));
    expectRefused($world, loginAttempt($actor->id), LoginPolicyErrorCode::FactorsUnavailable);

    $records = $world->telemetry->counters();

    expect(array_map(static fn (CounterRecord $record): array => [$record->name->value, $record->increment, $record->attributes->names(), $record->attributes->get('cms.outcome'), $record->attributes->get('cms.error.code')], $records))
        ->toBe([
            ['cms.login.decisions', 1, ['cms.outcome'], 'allowed', null],
            ['cms.login.decisions', 1, ['cms.error.code', 'cms.outcome'], 'refused', 'login_factors_unavailable'],
        ])
        ->and(json_encode(array_map(static fn (CounterRecord $record): array => $record->attributes->attributes, $records)))->not->toContain($actor->id->toString());
});

it('binds the policy of cbox-cms.identity.policy and the IdP links of the identity connection', function (): void {
    expect(app(LoginPolicy::class))->toBe(app(LoginPolicy::class))
        ->and(app(IdpLinks::class))->toBeInstanceOf(PostgresIdpLinks::class);
});

it('has the PRD\'s proposed defaults in identity.php', function (): void {
    $policy = defaultPolicy();

    expect($policy->authoritative)->toBe([])
        ->and(array_map(static fn (ConnectionId $connection): string => $connection->value, $policy->staff->connections))->toBe(['local'])
        ->and($policy->staff->localLogin)->toBeTrue()
        ->and($policy->staff->localFactors)->toBe(LocalFactors::PasskeyOrTwoFactors)
        ->and($policy->staff->methods)->toBe([LoginMethod::Password, LoginMethod::Passkey, LoginMethod::Invitation, LoginMethod::PasswordReset, LoginMethod::Federated])
        ->and([$policy->staff->lifetimes->inactivityMinutes, $policy->staff->lifetimes->absoluteMinutes])->toBe([60, 12 * 60])
        ->and($policy->endUser->localLogin)->toBeTrue()
        ->and($policy->endUser->localFactors)->toBe(LocalFactors::Password)
        ->and($policy->endUser->methods)->toBe(LoginMethod::cases())
        ->and([$policy->endUser->lifetimes->inactivityMinutes, $policy->endUser->lifetimes->absoluteMinutes])->toBe([30 * 24 * 60, 90 * 24 * 60]);
});

it('refuses a policy out of form and names the key, never its value', function (array $changes, string $key): void {
    try {
        defaultPolicy($changes);
    } catch (InvalidLoginPolicy $invalid) {
        expect($invalid->getMessage())->toContain($key)
            ->and(InvalidLoginPolicy::CODE)->toBe('login_policy_invalid');

        return;
    }

    Assert::fail("Expected the policy to be refused at {$key}.");
})->with([
    'an unknown method' => [['staff' => ['methods' => ['carrier_pigeon' => true]]], 'cbox-cms.identity.policy.staff.methods'],
    'methods as a list' => [['end_user' => ['methods' => [0 => 'password']]], 'cbox-cms.identity.policy.end_user.methods'],
    'a method switched on with a string' => [['staff' => ['methods' => ['password' => 'yes']]], 'cbox-cms.identity.policy.staff.methods'],
    'a connection out of form' => [['staff' => ['connections' => ['Entra' => true]]], 'cbox-cms.identity.policy.staff.connections'],
    'local login as a string' => [['staff' => ['local_login' => 'on']], 'cbox-cms.identity.policy.staff.local_login'],
    'unknown local factors' => [['staff' => ['local_factors' => 'sms']], 'cbox-cms.identity.policy.staff.local_factors'],
    'an amr value out of form' => [['staff' => ['federated_amr' => ['two words']]], 'cbox-cms.identity.policy.staff.federated_amr'],
    'an amr value twice' => [['staff' => ['federated_amr' => ['mfa', 'mfa']]], 'cbox-cms.identity.policy.staff.federated_amr'],
    'zero minutes of inactivity' => [['staff' => ['inactivity_minutes' => 0]], 'cbox-cms.identity.policy.staff.inactivity_minutes'],
    'inactivity longer than the absolute lifetime' => [['end_user' => ['inactivity_minutes' => 200000]], 'cbox-cms.identity.policy.end_user.absolute_minutes'],
    'the local connection marked authoritative' => [['authoritative_connections' => ['local']], 'authoritative_connections'],
]);

it('refuses a policy that is not a map', function (): void {
    expect(static fn (): LoginPolicy => LoginPolicyConfig::read(new Repository(['cbox-cms' => ['identity' => ['policy' => 'strict']]]), 'testing'))
        ->toThrow(InvalidLoginPolicy::class, 'cbox-cms.identity.policy must be');
});

it('decides only through the policy check: the decision has a private constructor', function (): void {
    $constructor = new ReflectionClass(LoginDecision::class)->getConstructor();

    expect($constructor?->isPrivate())->toBeTrue();
});
