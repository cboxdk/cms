<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Identity\Staff\Domain\Dto\RegisteredStaff;
use Cbox\Cms\Identity\Staff\Domain\Dto\StaffRegistration;
use Cbox\Cms\Identity\Staff\Domain\StaffRegistrationRefused;
use Cbox\Cms\Identity\Tests\Staff\FailingCredentialWrites;
use Cbox\Cms\Identity\Tests\Staff\ObservedCredentialWrites;
use Cbox\Cms\Identity\Tests\Staff\StaffWorld;
use Closure;
use PHPUnit\Framework\Assert;
use RuntimeException;

/*
 * RegisterLocalStaff (PRD 5.16, "Lokale konti") called directly with its DTO over StaffWorld's fakes
 * (GUARDRAILS 9): the fixed order actor.register (pending), the credential bound to the actor's id,
 * actor.activate, both commands as the installation operator; a login that has an account and a
 * password the policy refuses stop it before anything is written; a failing credential write after
 * actor.register leaves the actor pending, with no account and no activation, and a rerun for the
 * same login resumes the registration's operation with that actor instead of registering another;
 * a rerun after an activation that did not commit activates the pending actor with the rerun's
 * password.
 */

const STAFF_EMAIL = 'Mette.Holm@example.com';

const STAFF_PASSWORD = 'a long and quite unusual sentence';

function staffRegistration(string $email = STAFF_EMAIL, string $password = STAFF_PASSWORD): StaffRegistration
{
    return new StaffRegistration(new EmailAddress($email), new DisplayName('Mette Holm'), new Password($password));
}

/**
 * @param  Closure(): mixed  $register
 */
function staffRefusal(Closure $register): StaffRegistrationRefused
{
    try {
        $register();
    } catch (StaffRegistrationRefused $refused) {
        return $refused;
    }

    Assert::fail('The registration was not refused.');
}

it('registers the actor pending, binds the credential, then activates it, as the operator', function (): void {
    $world = new StaffWorld;

    $staff = $world->action()->register(staffRegistration());
    $actor = $world->find($staff->actor);
    $account = $world->accounts->ofActor($staff->actor);
    [$register, $activate] = $world->committer->pending;

    expect($world->committed())->toBe(['actor.register', 'actor.activate'])
        ->and($register->envelope->actor->equals($world->operator))->toBeTrue()
        ->and($activate->envelope->actor->equals($world->operator))->toBeTrue()
        ->and($actor?->class)->toBe(ActorClass::Staff)
        ->and($actor?->state)->toBe(ActorState::Active)
        ->and($account?->login->value)->toBe('mette.holm@example.com')
        ->and($account instanceof LocalAccount && password_verify(STAFF_PASSWORD, $account->hash->value))->toBeTrue();
});

it('binds the credential after actor.register committed and before actor.activate', function (): void {
    $world = new StaffWorld;
    $store = new ObservedCredentialWrites($world->accounts, $world);

    $world->action($store)->register(staffRegistration());

    expect($store->seen)->toBe(['actor.register; actor pending']);
});

it('refuses an email whose login has a local account with local_account_exists, before anything is written', function (): void {
    $world = new StaffWorld;
    $world->action()->register(staffRegistration('mette.holm@example.com'));
    $before = $world->committed();

    $refused = staffRefusal(static fn (): RegisteredStaff => $world->action()->register(staffRegistration(STAFF_EMAIL, 'another long and unusual sentence')));

    expect($refused->reason)->toBe(ErrorCode::LocalAccountExists)
        ->and($refused->pending)->toBeNull()
        ->and($refused->getMessage())->not->toContain('mette')
        ->and($world->committed())->toBe($before);
});

it('refuses a password the policy refuses, before anything is written', function (string $password, ErrorCode $code): void {
    $world = new StaffWorld;

    $refused = staffRefusal(static fn (): RegisteredStaff => $world->action()->register(staffRegistration(STAFF_EMAIL, $password)));

    expect($refused->reason)->toBe($code)
        ->and($refused->pending)->toBeNull()
        ->and($refused->getMessage())->not->toContain($password)
        ->and($world->committed())->toBe([])
        ->and($world->accounts->find(new LoginIdentifier('mette.holm@example.com')))->toBeNull();
})->with([
    'too short' => ['elevenchars', ErrorCode::PasswordTooShort],
    'too long' => [str_repeat('a', 1025), ErrorCode::PasswordTooLong],
    'breached' => [StaffWorld::BREACHED, ErrorCode::PasswordBreached],
]);

it('leaves the actor pending, with no account and no activation, when the credential write fails', function (): void {
    $world = new StaffWorld;

    try {
        $world->action(new FailingCredentialWrites($world->accounts))->register(staffRegistration());
        Assert::fail('The registration went on after the credential write failed.');
    } catch (RuntimeException $failed) {
        expect($failed->getMessage())->toBe(FailingCredentialWrites::MESSAGE);
    }

    $pending = $world->committer->pending[0]->plan->mutations()[0]->aggregate();
    $actor = $pending instanceof ActorId ? $world->find($pending) : null;

    expect($world->committed())->toBe(['actor.register'])
        ->and($actor?->state)->toBe(ActorState::Pending)
        ->and($actor instanceof Actor ? $world->accounts->ofActor($actor->id) : null)->toBeNull();
});

it('leaves the actor pending when another registration bound the login meanwhile', function (): void {
    $world = new StaffWorld;

    $refused = staffRefusal(static fn (): RegisteredStaff => $world->action(new ObservedCredentialWrites($world->accounts, $world, takenMeanwhile: true))->register(staffRegistration()));
    $actor = $refused->pending instanceof ActorId ? $world->find($refused->pending) : null;

    expect($refused->reason)->toBe(ErrorCode::LocalAccountExists)
        ->and($actor?->state)->toBe(ActorState::Pending)
        ->and($world->committed())->toBe(['actor.register'])
        ->and($refused->getMessage())->toContain('stays pending')
        ->and($refused->getMessage())->not->toContain('mette');
});

it('refuses with installation_operator_missing before cms:install, and writes nothing', function (): void {
    $world = new StaffWorld(installed: false);

    $refused = staffRefusal(static fn (): RegisteredStaff => $world->action()->register(staffRegistration()));

    expect($refused->reason)->toBe(ErrorCode::InstallationOperatorMissing)
        ->and($refused->pending)->toBeNull()
        ->and($world->committed())->toBe([]);
});

it('resumes a registration that stopped after actor.register with the same actor, and registers no second one', function (): void {
    $world = new StaffWorld;

    try {
        $world->action(new FailingCredentialWrites($world->accounts))->register(staffRegistration());
        Assert::fail('The registration went on after the credential write failed.');
    } catch (RuntimeException) {
    }

    $pending = $world->committer->pending[0]->plan->mutations()[0]->aggregate();
    $staff = $world->action()->register(staffRegistration());
    $registered = array_values(array_filter(
        $world->committer->pending,
        static fn (PendingChangeset $changeset): bool => $changeset->command->value === 'actor.register',
    ));

    expect($pending)->toBeInstanceOf(ActorId::class)
        ->and($pending instanceof ActorId && $staff->actor->equals($pending))->toBeTrue()
        ->and($world->committed())->toBe(['actor.register', 'actor.activate'])
        ->and($registered)->toHaveCount(1)
        ->and($world->find($staff->actor)?->state)->toBe(ActorState::Active)
        ->and($world->accounts->ofActor($staff->actor)?->login->value)->toBe('mette.holm@example.com');
});

it('resumes a registration whose activation was rejected after the bind, without refusing the login it bound', function (): void {
    $world = new StaffWorld;
    $world->failActivations = true;

    $refused = staffRefusal(static fn (): RegisteredStaff => $world->action()->register(staffRegistration()));
    $world->failActivations = false;
    $staff = $world->action()->register(staffRegistration());

    expect($refused->pending)->toBeInstanceOf(ActorId::class)
        ->and($refused->pending instanceof ActorId && $staff->actor->equals($refused->pending))->toBeTrue()
        ->and($world->committed())->toBe(['actor.register', 'actor.activate'])
        ->and($world->find($staff->actor)?->state)->toBe(ActorState::Active);
});

it('resumes a registration whose actor.activate was rejected, so a rerun with the same email gives an active actor', function (): void {
    $world = new StaffWorld;
    $world->failingActivations = 1;

    $refused = staffRefusal(static fn (): RegisteredStaff => $world->action()->register(staffRegistration()));
    $stopped = $refused->pending;

    expect($refused->reason)->toBe(ErrorCode::VersionConflict)
        ->and($stopped instanceof ActorId ? $world->find($stopped)?->state : null)->toBe(ActorState::Pending);

    $staff = $world->action()->register(staffRegistration('mette.holm@example.com', 'another long and unusual sentence'));
    $account = $world->accounts->ofActor($staff->actor);

    expect($stopped instanceof ActorId && $staff->actor->equals($stopped))->toBeTrue()
        ->and($world->find($staff->actor)?->state)->toBe(ActorState::Active)
        ->and($world->committed())->toBe(['actor.register', 'actor.activate'])
        ->and($account instanceof LocalAccount && password_verify('another long and unusual sentence', $account->hash->value))->toBeTrue()
        ->and($world->committer->pending[1]->envelope->actor->equals($world->operator))->toBeTrue();
});

it('refuses a rerun with a password the policy refuses, and leaves the pending actor and its hash as they were', function (): void {
    $world = new StaffWorld;
    $world->failingActivations = 1;
    $stopped = staffRefusal(static fn (): RegisteredStaff => $world->action()->register(staffRegistration()))->pending;
    $before = $stopped instanceof ActorId ? $world->accounts->ofActor($stopped) : null;

    $refused = staffRefusal(static fn (): RegisteredStaff => $world->action()->register(staffRegistration(STAFF_EMAIL, 'elevenchars')));

    expect($refused->reason)->toBe(ErrorCode::PasswordTooShort)
        ->and($stopped instanceof ActorId ? $world->find($stopped)?->state : null)->toBe(ActorState::Pending)
        ->and($stopped instanceof ActorId ? $world->accounts->ofActor($stopped)?->version : null)->toBe($before?->version)
        ->and($world->committed())->toBe(['actor.register']);
});

it('refuses an email whose account is bound to an actor that is not pending, as local_account_exists', function (): void {
    $world = new StaffWorld;
    $staff = $world->action()->register(staffRegistration());

    $refused = staffRefusal(static fn (): RegisteredStaff => $world->action()->register(staffRegistration()));

    expect($refused->reason)->toBe(ErrorCode::LocalAccountExists)
        ->and($refused->pending)->toBeNull()
        ->and($world->accounts->ofActor($staff->actor)?->version)->toBe(LocalAccount::FIRST_VERSION)
        ->and($world->committed())->toBe(['actor.register', 'actor.activate']);
});
