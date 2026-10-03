<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\GrantRoleContentChanged;
use Cbox\Cms\Contracts\Plans\Mutations\RoleCreated;
use Cbox\Cms\Contracts\Plans\Mutations\RolePermissionsSet;
use Cbox\Cms\Core\Access\Domain\ActorGrantsRef;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions;
use Cbox\Cms\Core\Access\Domain\Dto\StoredGrant;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;
use Cbox\Cms\Core\Access\Domain\RoleGrantsRef;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Tests\Access\GrantActionWorld;
use Cbox\Cms\Core\Tests\Identity\ActorCommandFakes;
use Cbox\Cms\Core\Tests\Postgres\AccessWorld;

/*
 * role.create and role.set_permissions (PRD 5.10, 6.4, invariant 31) through the command pipeline,
 * called directly with the actions and the fakes of the ports and contracts they read
 * (GUARDRAILS 9). The issuer holds the role commands on ROOT and the permissions a test gives it.
 * ROLE is the role a test changes, with the grants a test gives it. It covers a new role, the
 * escalation guard on a role's content (a permission the issuer lacks on one of the nodes where
 * the role is granted, and a ceiling above its access), a change that makes a granted role
 * administrative, the names the registry does not know, and the conflicts.
 */

const ROLE_GRANT_NEWS = '01936f5e-8a2b-7c3d-9e4f-000000000611';

const ROLE_GRANT_CULTURE = '01936f5e-8a2b-7c3d-9e4f-000000000612';

const ROLE_HOLDER = '01936f5e-8a2b-7c3d-9e4f-000000000613';

function roleWorld(): GrantActionWorld
{
    return new GrantActionWorld()->hold(['role.create', 'role.set_permissions'], AccessWorld::ROOT);
}

/**
 * @param  list<string>  $names
 * @return list<CommandName>
 */
function permissionNames(array $names): array
{
    return array_map(static fn (string $name): CommandName => new CommandName($name), $names);
}

/**
 * @param  list<string>  $permissions
 */
function createRole(array $permissions, string $handle = 'desk', ClassificationAccess $ceiling = ClassificationAccess::Internal): CreateRole
{
    return new CreateRole(RoleId::fromString(GrantActionWorld::ROLE), new RoleHandle($handle), $ceiling, permissionNames($permissions));
}

/**
 * @param  list<string>  $permissions
 */
function setPermissions(array $permissions, int $version = 1): SetRolePermissions
{
    return new SetRolePermissions(RoleId::fromString(GrantActionWorld::ROLE), new AggregateVersion($version), permissionNames($permissions));
}

/**
 * ROLE with the permissions, granted to ROLE_HOLDER on each node given.
 *
 * @param  list<string>  $permissions
 * @param  array<string, string>  $grants  the node of each grant by its id
 */
function grantedRole(GrantActionWorld $world, array $permissions, array $grants, GrantEffect $effect = GrantEffect::Allow): void
{
    $world->role($permissions);

    foreach ($grants as $grant => $node) {
        $world->reader->addGrant(new StoredGrant(
            GrantId::fromString($grant),
            ActorId::fromString(ROLE_HOLDER),
            RoleId::fromString(GrantActionWorld::ROLE),
            NodeId::fromString($node),
            $effect,
            null,
            AggregateVersion::first(),
            false,
        ));
    }
}

it('creates a role with its handle, ceiling and permissions, reading its id and its handle', function (): void {
    $world = roleWorld();

    $result = $world->run(createRole(['entry.create', 'path.resolve', 'grant.assign']));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending[0]->command->value)->toBe('role.create')
        ->and($world->committer->pending[0]->plan->mutations())->toEqual([new RoleCreated(
            RoleId::fromString(GrantActionWorld::ROLE),
            new RoleHandle('desk'),
            ClassificationAccess::Internal,
            permissionNames(['entry.create', 'path.resolve', 'grant.assign']),
        )])
        ->and($world->reads())->toBe([
            $world->issuer->aggregateKey().' 1',
            'role:'.GrantActionWorld::ROLE.' -',
            'role_handle:desk -',
        ]);
});

it('refuses a role that reads above the issuer\'s classification access as an escalation, and an issuer without role.create as unauthorized', function (): void {
    $above = roleWorld()->run(createRole(['entry.create'], ceiling: ClassificationAccess::Confidential));
    $without = new GrantActionWorld()->hold(['role.set_permissions'], AccessWorld::ROOT);

    expect(ActorCommandFakes::errors($above))->toBe(['grant_escalation_refused'])
        ->and($above->errors[0]->message)->toContain('confidential')
        ->and(ActorCommandFakes::errors($without->run(createRole(['entry.create']))))->toBe(['unauthorized'])
        ->and($without->committer->pending)->toBe([]);
});

it('refuses a name the registry does not know, a name given twice and a handle another role has with validation_failed', function (): void {
    $world = roleWorld();
    $world->reader->addRole(new StoredRole(RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000699'), ClassificationAccess::Public, [], AggregateVersion::first()), new RoleHandle('taken'));

    $unknown = $world->run(createRole(['entry.create', 'entry.nothing', 'entry.create']));
    $taken = $world->run(createRole(['entry.create'], 'taken'));

    expect(ActorCommandFakes::errors($unknown))->toBe(['validation_failed permissions[1]', 'validation_failed permissions[2]'])
        ->and($unknown->errors[0]->message)->toContain('entry.nothing')
        ->and(ActorCommandFakes::errors($taken))->toBe(['validation_failed handle'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a role whose id exists with version_conflict', function (): void {
    $world = roleWorld();
    $world->role(['entry.create']);

    expect(ActorCommandFakes::errors($world->run(createRole(['entry.create']))))->toBe(['version_conflict'])
        ->and($world->committer->pending)->toBe([]);
});

it('sets a role\'s permissions and moves each of its grants, when the issuer holds each added permission where the role is granted', function (): void {
    $world = roleWorld()->hold(['entry.create', 'entry.publish'], AccessWorld::NEWS);
    grantedRole($world, ['entry.create'], [ROLE_GRANT_NEWS => AccessWorld::NEWS, ROLE_GRANT_CULTURE => AccessWorld::CULTURE], GrantEffect::Allow);
    $world->reader->addGrant(new StoredGrant(
        GrantId::fromString(ROLE_GRANT_CULTURE),
        ActorId::fromString(ROLE_HOLDER),
        RoleId::fromString(GrantActionWorld::ROLE),
        NodeId::fromString(AccessWorld::CULTURE),
        GrantEffect::Deny,
        null,
        AggregateVersion::first(),
        false,
    ));

    $result = $world->run(setPermissions(['entry.publish', 'entry.create']));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending[0]->command->value)->toBe('role.set_permissions')
        ->and($world->committer->pending[0]->plan->mutations())->toEqual([
            new RolePermissionsSet(RoleId::fromString(GrantActionWorld::ROLE), permissionNames(['entry.publish', 'entry.create'])),
            new GrantRoleContentChanged(GrantId::fromString(ROLE_GRANT_NEWS)),
            new GrantRoleContentChanged(GrantId::fromString(ROLE_GRANT_CULTURE)),
        ])
        ->and($world->reads())->toBe([
            $world->issuer->aggregateKey().' 1',
            new ActorGrantsRef(ActorId::fromString(ROLE_HOLDER))->aggregateKey().' 3',
            new ActorGrantsRef($world->issuer)->aggregateKey().' 3',
            'grant:'.ROLE_GRANT_NEWS.' 1',
            'grant:'.ROLE_GRANT_CULTURE.' 1',
            'role:'.GrantActionWorld::ROLE.' 1',
            new RoleGrantsRef(RoleId::fromString(GrantActionWorld::ROLE))->aggregateKey().' 3',
        ]);
});

it('takes permissions away from a granted role without the guard', function (): void {
    $world = roleWorld();
    grantedRole($world, ['entry.create', 'entry.publish'], [ROLE_GRANT_CULTURE => AccessWorld::CULTURE]);

    expect($world->run(setPermissions(['entry.publish']))->outcome())->toBe(Outcome::Committed);
});

it('refuses to add a permission the issuer lacks on one of the nodes where the role is granted as an escalation', function (): void {
    $world = roleWorld()->hold(['entry.create', 'entry.publish'], AccessWorld::NEWS);
    grantedRole($world, ['entry.create'], [ROLE_GRANT_NEWS => AccessWorld::NEWS, ROLE_GRANT_CULTURE => AccessWorld::CULTURE]);

    $result = $world->run(setPermissions(['entry.create', 'entry.publish']));

    expect(ActorCommandFakes::errors($result))->toBe(['grant_escalation_refused'])
        ->and($result->errors[0]->message)->toContain('entry.publish')
        ->and($result->errors[0]->message)->toContain(AccessWorld::CULTURE)
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a name the registry does not know and a name given twice with validation_failed at its place in the list', function (): void {
    $world = roleWorld();
    grantedRole($world, ['entry.create'], [ROLE_GRANT_CULTURE => AccessWorld::CULTURE]);

    $result = $world->run(setPermissions(['entry.create', 'entry.nothing', 'entry.create']));

    expect(ActorCommandFakes::errors($result))->toBe(['validation_failed permissions[1]', 'validation_failed permissions[2]'])
        ->and($result->errors[0]->message)->toContain('cms:actions')
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a change that makes a granted role administrative with step_up_required, and lets one that is granted nowhere become it', function (): void {
    $granted = roleWorld()->hold(['grant.assign'], AccessWorld::ROOT);
    grantedRole($granted, ['entry.create'], [ROLE_GRANT_NEWS => AccessWorld::NEWS]);
    $nowhere = roleWorld()->hold(['grant.assign'], AccessWorld::ROOT);
    $nowhere->role(['entry.create']);

    $refused = $granted->run(setPermissions(['entry.create', 'grant.assign']));

    expect(ActorCommandFakes::errors($refused))->toBe(['step_up_required'])
        ->and($granted->committer->pending)->toBe([])
        ->and($nowhere->run(setPermissions(['entry.create', 'grant.assign']))->outcome())->toBe(Outcome::Committed);
});

it('rejects a role at another version than the caller read, a role that does not exist and a list equal to the role\'s', function (): void {
    $world = roleWorld();

    $missing = $world->run(setPermissions(['entry.create']));
    $world->role(['entry.create', 'entry.revise']);
    $stale = $world->run(setPermissions(['entry.create'], version: 2));
    $same = $world->run(setPermissions(['entry.revise', 'entry.create']));

    expect(ActorCommandFakes::errors($missing))->toBe(['version_conflict'])
        ->and(ActorCommandFakes::errors($stale))->toBe(['version_conflict'])
        ->and(ActorCommandFakes::errors($same))->toBe(['validation_failed'])
        ->and($same->errors[0]->message)->toContain('changes nothing')
        ->and($world->committer->pending)->toBe([]);
});

it('fails with version_conflict when the role was granted again after the change read its grants', function (): void {
    $world = roleWorld();
    $role = RoleId::fromString(GrantActionWorld::ROLE);
    grantedRole($world, ['entry.create'], []);
    $world->commitWith(new VersionConflict(new StaleRead(new RoleGrantsRef($role), AggregateVersion::first(), new AggregateVersion(2))));

    $result = $world->run(setPermissions(['entry.revise']));

    expect(ActorCommandFakes::errors($result))->toBe(['version_conflict'])
        ->and($world->committer->pending)->toHaveCount(1);
});
