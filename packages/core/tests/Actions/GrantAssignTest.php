<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\GrantAssigned;
use Cbox\Cms\Core\Access\Domain\Dto\StoredGrant;
use Cbox\Cms\Core\Access\Domain\GrantSlotRef;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Tests\Access\GrantActionWorld;
use Cbox\Cms\Core\Tests\Identity\ActorCommandFakes;
use Cbox\Cms\Core\Tests\Postgres\AccessWorld;

/*
 * grant.assign (PRD 5.10, 6.4, invariant 31) through the command pipeline, called directly with the
 * action and the fakes of the ports and contracts it reads (GUARDRAILS 9). The issuer holds
 * grant.assign on ROOT and a writer role on NEWS unless a test says otherwise. It covers a grant
 * within the issuer's rights, a deny, the escalation refusals (a permission the issuer lacks on the
 * node, holds only on a sibling node or only in another locale, and a ceiling above its access), an
 * administrative role, a target that may not get a grant, the other refusals, and the conflicts.
 */

function grantAssignWorld(): GrantActionWorld
{
    return new GrantActionWorld()
        ->hold(['grant.assign'], AccessWorld::ROOT)
        ->hold(['entry.create', 'entry.revise'], AccessWorld::NEWS);
}

it('grants a role whose permissions the issuer holds on the node, reading the grant, the actor, the role and the slot', function (): void {
    $world = grantAssignWorld();
    $target = $world->target();
    $role = $world->role(['entry.create']);

    $result = $world->run($world->assign($target, AccessWorld::SPORT, locales: ['da', 'en']));
    $slot = new GrantSlotRef($target, $role, NodeId::fromString(AccessWorld::SPORT));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending[0]->command->value)->toBe('grant.assign')
        ->and($world->committer->pending[0]->plan->mutations())->toEqual([new GrantAssigned(
            GrantId::fromString(GrantActionWorld::GRANT),
            $target,
            $role,
            NodeId::fromString(AccessWorld::SPORT),
            GrantEffect::Allow,
            [new Locale('da'), new Locale('en')],
        )])
        ->and($world->reads())->toBe([
            $world->issuer->aggregateKey().' 1',
            $target->aggregateKey().' 1',
            'grant:'.GrantActionWorld::GRANT.' -',
            $slot->aggregateKey().' -',
            'role:'.GrantActionWorld::ROLE.' 1',
        ]);
});

it('grants a service actor, and a deny of permissions the issuer does not hold, which takes rights away', function (): void {
    $world = grantAssignWorld();
    $world->role(['entry.publish', 'grant.assign'], ClassificationAccess::Sensitive);

    $service = $world->run($world->assign($world->target(ActorClass::Service), AccessWorld::NEWS, GrantEffect::Deny));

    expect($service->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending)->toHaveCount(1);
});

it('refuses a role with a permission the issuer lacks on the node, holds only on a sibling node or only in another locale, as an escalation', function (string $case): void {
    $world = new GrantActionWorld()->hold(['grant.assign'], AccessWorld::ROOT);
    $both = ['entry.create', 'entry.publish'];

    match ($case) {
        'one permission missing' => $world->hold(['entry.create'], AccessWorld::NEWS),
        'held only on a sibling node' => $world->hold($both, AccessWorld::CULTURE),
        default => $world->hold($both, AccessWorld::NEWS, locales: ['da']),
    };

    $world->role($both);
    $locales = match ($case) {
        'held only in another locale' => ['en'],
        'held in one of the grant\'s locales' => ['da', 'en'],
        default => null,
    };

    $result = $world->run($world->assign($world->target(), AccessWorld::NEWS, locales: $locales));

    expect(ActorCommandFakes::errors($result))->toBe(['grant_escalation_refused'])
        ->and($result->errors[0]->message)->toContain('invariant 31')
        ->and($world->committer->pending)->toBe([]);
})->with(['one permission missing', 'held only on a sibling node', 'held only in another locale', 'held in one of the grant\'s locales', 'held in a locale, granted in every locale']);

it('refuses a role that reads above the issuer\'s classification access on the node as an escalation', function (): void {
    $world = grantAssignWorld();
    $world->role(['entry.create'], ClassificationAccess::Confidential);

    $result = $world->run($world->assign($world->target(), AccessWorld::NEWS));

    expect(ActorCommandFakes::errors($result))->toBe(['grant_escalation_refused'])
        ->and($result->errors[0]->message)->toContain('confidential');
});

it('refuses an administrative role with step_up_required, even to an issuer that holds its permissions', function (string $permission): void {
    $world = grantAssignWorld()->hold(['grant.assign', 'grant.revoke', 'role.create', 'role.set_permissions', 'actor.deactivate'], AccessWorld::ROOT);
    $world->role(['entry.create', $permission]);

    $result = $world->run($world->assign($world->target(), AccessWorld::NEWS));

    expect(ActorCommandFakes::errors($result))->toBe(['step_up_required'])
        ->and($world->committer->pending)->toBe([]);
})->with(['grant.assign', 'grant.revoke', 'role.create', 'role.set_permissions', 'actor.deactivate']);

it('refuses an actor that is not an active staff or service actor with validation_failed at the actor', function (ActorClass $class, ActorState $state): void {
    $world = grantAssignWorld();
    $world->role(['entry.create']);

    $result = $world->run($world->assign($world->target($class, $state), AccessWorld::NEWS));

    expect(ActorCommandFakes::errors($result))->toBe(['validation_failed actor'])
        ->and($result->errors[0]->message)->toContain(sprintf('is a %s actor that is %s', $class->value, $state->value))
        ->and($world->committer->pending)->toBe([]);
})->with([
    'an end user' => [ActorClass::EndUser, ActorState::Active],
    'a pending staff actor' => [ActorClass::Staff, ActorState::Pending],
    'a deactivated staff actor' => [ActorClass::Staff, ActorState::Deactivated],
    'a deprovisioned service actor' => [ActorClass::Service, ActorState::Deprovisioned],
]);

it('refuses a role that does not exist, a role the actor holds on the node already and a locale named twice with validation_failed', function (): void {
    $world = grantAssignWorld();
    $target = $world->target();

    $missing = $world->run($world->assign($target, AccessWorld::NEWS));
    $world->role(['entry.create']);
    $twice = $world->run($world->assign($target, AccessWorld::NEWS, locales: ['da', 'da']));
    $world->reader->addGrant(new StoredGrant(
        GrantId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000699'),
        $target,
        RoleId::fromString(GrantActionWorld::ROLE),
        NodeId::fromString(AccessWorld::NEWS),
        GrantEffect::Deny,
        null,
        AggregateVersion::first(),
        false,
    ));
    $held = $world->run($world->assign($target, AccessWorld::NEWS));

    expect(ActorCommandFakes::errors($missing))->toBe(['validation_failed role'])
        ->and(ActorCommandFakes::errors($twice))->toBe(['validation_failed locales'])
        ->and(ActorCommandFakes::errors($held))->toBe(['validation_failed role'])
        ->and($held->errors[0]->message)->toContain('holds the role')
        ->and($world->committer->pending)->toBe([]);
});

it('rejects an issuer without grant.assign on the node as unauthorized', function (): void {
    $world = new GrantActionWorld()
        ->hold(['grant.assign'], AccessWorld::CULTURE)
        ->hold(['entry.create'], AccessWorld::ROOT);
    $world->role(['entry.create']);

    expect(ActorCommandFakes::errors($world->run($world->assign($world->target(), AccessWorld::NEWS))))->toBe(['unauthorized'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a grant whose id exists with version_conflict, and fails with it when the slot was taken after it was read', function (): void {
    $world = grantAssignWorld();
    $target = $world->target();
    $role = $world->role(['entry.create']);
    $world->stored($world->target(), AccessWorld::CULTURE);

    $exists = $world->run($world->assign($target, AccessWorld::NEWS));

    $fresh = grantAssignWorld();
    $other = $fresh->target();
    $fresh->role(['entry.create']);
    $slot = new GrantSlotRef($other, $role, NodeId::fromString(AccessWorld::NEWS));
    $fresh->commitWith(new VersionConflict(new StaleRead($slot, null, AggregateVersion::first())));
    $taken = $fresh->run($fresh->assign($other, AccessWorld::NEWS));

    expect(ActorCommandFakes::errors($exists))->toBe(['version_conflict'])
        ->and($world->committer->pending)->toBe([])
        ->and(ActorCommandFakes::errors($taken))->toBe(['version_conflict'])
        ->and($fresh->committer->pending)->toHaveCount(1);
});
