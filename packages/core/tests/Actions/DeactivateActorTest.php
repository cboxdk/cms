<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\ActorDeactivated;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Tests\Identity\DeactivationFakes;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;

/*
 * actor.deactivate (PRD 5.16, 6.4) through the command pipeline, called directly with the action
 * and the fakes of the ports and contracts it reads (GUARDRAILS 9): the identity, the authorizer,
 * the committer and the stores. It covers success, the rejection of a caller that holds no grant
 * for it, the conflict at commit, and a deactivation that changes nothing.
 */

/**
 * @return list<string>
 */
function deactivationCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * @return list<string> each read as "<aggregate key> <version or ->"
 */
function deactivationReads(FakeChangesetCommitter $committer): array
{
    return array_map(
        static fn (ReadVersion $read): string => $read->aggregate->aggregateKey().' '.($read->version->value ?? '-'),
        $committer->pending[0]->reads->reads ?? [],
    );
}

it('plans the deactivation of an active actor with its source and commits it with both actors read', function (): void {
    $world = new DeactivationFakes;
    $target = $world->identity->addActor(ActorClass::Staff)->id;

    $result = $world->run($target, DeactivationSource::Inactivity);
    $mutations = $world->committer->pending[0]->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($mutations)->toEqual([new ActorDeactivated($target, DeactivationSource::Inactivity)])
        ->and($world->committer->pending[0]->command->value)->toBe('actor.deactivate')
        ->and(deactivationReads($world->committer))->toBe([$world->admin->aggregateKey().' 1', $target->aggregateKey().' 1'])
        ->and($world->authorizer->asked)->toHaveCount(1);
});

it('deactivates a pending actor, and the local source is the default', function (): void {
    $world = new DeactivationFakes;
    $target = $world->identity->addActor(ActorClass::Service, ActorState::Pending)->id;

    $result = $world->run($target);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending[0]->plan->mutations())->toEqual([new ActorDeactivated($target, DeactivationSource::Local)]);
});

it('rejects a caller that holds no grant for actor.deactivate as unauthorized and plans nothing', function (): void {
    $world = new DeactivationFakes;
    $target = $world->identity->addActor(ActorClass::Staff)->id;
    $world->authorizer = new FakeCommandAuthorizer('The actor holds no grant of a role that may run actor.deactivate.');

    $result = $world->run($target);

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(deactivationCodes($result))->toBe(['unauthorized'])
        ->and($result->errors[0]->message)->toBe('The actor holds no grant of a role that may run actor.deactivate.')
        ->and($world->committer->pending)->toBe([]);
});

it('fails with version_conflict when the actor changed after it was read', function (): void {
    $world = new DeactivationFakes;
    $target = $world->identity->addActor(ActorClass::Staff)->id;
    $world->commitWith(new VersionConflict(new StaleRead($target, new AggregateVersion(1), new AggregateVersion(2))));

    $result = $world->run($target);

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(deactivationCodes($result))->toBe(['version_conflict'])
        ->and($result->receipt->changesetId)->toBeNull()
        ->and($world->committer->pending)->toHaveCount(1);
});

it('rejects the deactivation of an actor that is not active or pending, or does not exist, as changing nothing', function (ActorState $state): void {
    $world = new DeactivationFakes;
    $target = $world->identity->addActor(ActorClass::Staff, $state)->id;
    $missing = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000ff');

    $existing = $world->run($target);
    $absent = $world->run($missing);
    $dryRun = $world->run($target, dryRun: true);

    expect(array_map(deactivationCodes(...), [$existing, $absent, $dryRun]))->toBe([['validation_failed'], ['validation_failed'], ['validation_failed']])
        ->and($existing->errors[0]->message)->toBe('The command actor.deactivate changes nothing here, so nothing was committed: its action planned no mutation for what it read.')
        ->and($world->committer->pending)->toBe([]);
})->with([
    'deactivated' => [ActorState::Deactivated],
    'deprovisioned' => [ActorState::Deprovisioned],
]);
