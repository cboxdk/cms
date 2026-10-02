<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\ActorRegistered;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Tests\Identity\ActorCommandFakes;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;

/*
 * actor.register (PRD 5.16, 6.4) through the command pipeline, called directly with the action and
 * the fakes of the ports and contracts it reads (GUARDRAILS 9): the identity, the authorizer, the
 * committer and the stores. It covers a staff and a service registration, the refusals of an end
 * user and of a service actor without an active staff actor responsible for it, a caller that holds
 * no grant for it, an id that exists, and the conflict at commit.
 */

const REGISTER_NEW_ACTOR = '01936f5e-8a2b-7c3d-9e4f-000000000301';

function actorRegistration(ActorClass $class, ?ActorId $responsible = null, string $id = REGISTER_NEW_ACTOR): RegisterActor
{
    return new RegisterActor(ActorId::fromString($id), $class, new DisplayName('Mette Holm'), new EmailAddress('mette@example.com'), $responsible);
}

it('registers a staff actor pending with its profile and reads it as absent', function (): void {
    $world = new ActorCommandFakes;

    $result = $world->run(actorRegistration(ActorClass::Staff));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending[0]->command->value)->toBe('actor.register')
        ->and($world->committer->pending[0]->plan->mutations())->toEqual([new ActorRegistered(
            ActorId::fromString(REGISTER_NEW_ACTOR),
            ActorClass::Staff,
            new ActorProfile(new DisplayName('Mette Holm'), new EmailAddress('mette@example.com')),
        )])
        ->and($world->reads())->toEqualCanonicalizing([$world->admin->aggregateKey().' 1', 'actor:'.REGISTER_NEW_ACTOR.' -']);
});

it('registers a service actor with the active staff actor responsible for it, read at its version', function (): void {
    $world = new ActorCommandFakes;
    $responsible = $world->identity->addActor(ActorClass::Staff)->id;

    $result = $world->run(actorRegistration(ActorClass::Service, $responsible));
    $mutation = $world->committer->pending[0]->plan->mutations()[0];

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($mutation)->toBeInstanceOf(ActorRegistered::class)
        ->and($mutation instanceof ActorRegistered ? $mutation->responsible : null)->toEqual($responsible)
        ->and($world->reads())->toEqualCanonicalizing([$world->admin->aggregateKey().' 1', 'actor:'.REGISTER_NEW_ACTOR.' -', $responsible->aggregateKey().' 1']);
});

it('refuses an end user with validation_failed at its class and commits nothing', function (): void {
    $world = new ActorCommandFakes;

    $result = $world->run(actorRegistration(ActorClass::EndUser));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(ActorCommandFakes::errors($result))->toBe(['validation_failed class'])
        ->and($result->errors[0]->message)->toContain('block B13')
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a service actor without an active staff actor responsible for it', function (?string $state, ?ActorClass $class): void {
    $world = new ActorCommandFakes;
    $responsible = match (true) {
        $class instanceof ActorClass && is_string($state) => $world->identity->addActor($class, ActorState::from($state))->id,
        is_string($state) => ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000003ff'),
        default => null,
    };

    $result = $world->run(actorRegistration(ActorClass::Service, $responsible));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(ActorCommandFakes::errors($result))->toBe(['validation_failed responsible'])
        ->and($world->committer->pending)->toBe([]);
})->with([
    'none named' => [null, null],
    'one that does not exist' => ['active', null],
    'a pending staff actor' => ['pending', ActorClass::Staff],
    'a deactivated staff actor' => ['deactivated', ActorClass::Staff],
    'an active service actor' => ['active', ActorClass::Service],
]);

it('refuses a staff actor with a responsible person', function (): void {
    $world = new ActorCommandFakes;

    $result = $world->run(actorRegistration(ActorClass::Staff, $world->admin));

    expect(ActorCommandFakes::errors($result))->toBe(['validation_failed responsible'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a caller that holds no grant for actor.register as unauthorized', function (): void {
    $world = new ActorCommandFakes;
    $world->authorizer = new FakeCommandAuthorizer('The actor holds no grant of a role that may run actor.register.');

    $result = $world->run(actorRegistration(ActorClass::Staff));

    expect(ActorCommandFakes::errors($result))->toBe(['unauthorized'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects the registration of an id an actor has with version_conflict', function (): void {
    $world = new ActorCommandFakes;
    $taken = $world->identity->addActor(ActorClass::Staff, ActorState::Pending)->id;

    $result = $world->run(actorRegistration(ActorClass::Staff, id: $taken->toString()));

    expect(ActorCommandFakes::errors($result))->toBe(['version_conflict'])
        ->and($world->committer->pending)->toBe([]);
});

it('fails with version_conflict when another registration of the id committed first', function (): void {
    $world = new ActorCommandFakes;
    $world->commitWith(new VersionConflict(new StaleRead(ActorId::fromString(REGISTER_NEW_ACTOR), null, new AggregateVersion(1))));

    $result = $world->run(actorRegistration(ActorClass::Staff));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(ActorCommandFakes::errors($result))->toBe(['version_conflict'])
        ->and($result->receipt->changesetId)->toBeNull()
        ->and($world->committer->pending)->toHaveCount(1);
});

it('shows a hook that may not read personal data the registration without the profile', function (): void {
    $registered = new ActorRegistered(ActorId::fromString(REGISTER_NEW_ACTOR), ActorClass::Staff, new ActorProfile(new DisplayName('Mette Holm'), new EmailAddress('mette@example.com')));

    expect($registered->withoutClassified())->toEqual(new ActorRegistered(ActorId::fromString(REGISTER_NEW_ACTOR), ActorClass::Staff, null))
        ->and($registered->classification()->value)->toBe('personal');
});
