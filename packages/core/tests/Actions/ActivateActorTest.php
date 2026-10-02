<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\ActorActivated;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Tests\Identity\ActorCommandFakes;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;

/*
 * actor.activate (PRD 5.16, 6.4) through the command pipeline, called directly with the action and
 * the fakes of the ports and contracts it reads (GUARDRAILS 9). It covers the activation of a
 * pending actor, the refusal of an actor in any other state, a caller that holds no grant for it,
 * a version the caller did not read, and the conflict at commit.
 */

it('activates a pending actor read at the version the caller saw', function (): void {
    $world = new ActorCommandFakes;
    $pending = $world->identity->addActor(ActorClass::Staff, ActorState::Pending)->id;

    $result = $world->run(new ActivateActor($pending, new AggregateVersion(1)));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending[0]->command->value)->toBe('actor.activate')
        ->and($world->committer->pending[0]->plan->mutations())->toEqual([new ActorActivated($pending)])
        ->and($world->reads())->toBe([$world->admin->aggregateKey().' 1', $pending->aggregateKey().' 1']);
});

it('refuses to activate an actor that is not pending with validation_failed at the actor', function (ActorState $state): void {
    $world = new ActorCommandFakes;
    $actor = $world->identity->addActor(ActorClass::Service, $state)->id;

    $result = $world->run(new ActivateActor($actor, new AggregateVersion(1)));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(ActorCommandFakes::errors($result))->toBe(['validation_failed actor'])
        ->and($result->errors[0]->message)->toContain('is '.$state->value)
        ->and($world->committer->pending)->toBe([]);
})->with([
    'active' => [ActorState::Active],
    'deactivated' => [ActorState::Deactivated],
    'deprovisioned' => [ActorState::Deprovisioned],
]);

it('rejects a caller that holds no grant for actor.activate as unauthorized', function (): void {
    $world = new ActorCommandFakes;
    $pending = $world->identity->addActor(ActorClass::Staff, ActorState::Pending)->id;
    $world->authorizer = new FakeCommandAuthorizer('The actor holds no grant of a role that may run actor.activate.');

    expect(ActorCommandFakes::errors($world->run(new ActivateActor($pending, new AggregateVersion(1)))))->toBe(['unauthorized'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects an activation at a version the caller did not read, or of an actor that does not exist, with version_conflict', function (): void {
    $world = new ActorCommandFakes;
    $pending = $world->identity->addActor(ActorClass::Staff, ActorState::Pending)->id;

    $stale = $world->run(new ActivateActor($pending, new AggregateVersion(2)));
    $missing = $world->run(new ActivateActor(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000004ff'), new AggregateVersion(1)));

    expect(ActorCommandFakes::errors($stale))->toBe(['version_conflict'])
        ->and(ActorCommandFakes::errors($missing))->toBe(['version_conflict'])
        ->and($world->committer->pending)->toBe([]);
});

it('fails with version_conflict when the actor changed after it was read', function (): void {
    $world = new ActorCommandFakes;
    $pending = $world->identity->addActor(ActorClass::Staff, ActorState::Pending)->id;
    $world->commitWith(new VersionConflict(new StaleRead($pending, new AggregateVersion(1), new AggregateVersion(2))));

    $result = $world->run(new ActivateActor($pending, new AggregateVersion(1)));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(ActorCommandFakes::errors($result))->toBe(['version_conflict'])
        ->and($world->committer->pending)->toHaveCount(1);
});
