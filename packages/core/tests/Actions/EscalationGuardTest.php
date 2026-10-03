<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\AdministrativePermissions;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\Dto\HeldGrant;
use Cbox\Cms\Core\Access\Domain\Dto\RoleContentChange;
use Cbox\Cms\Core\Access\Domain\Dto\RoleGrant;
use Cbox\Cms\Core\Access\Domain\Dto\StoredGrant;
use Cbox\Cms\Core\Access\Domain\EscalationGuard;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissionCatalog;

/*
 * The escalation guard (PRD 5.10, invariant 31) on its own: an actor gives a role only when it holds
 * each of the role's permissions on the node in the grant's locales, and has a classification
 * access there not below the role's ceiling; an administrative role needs step-up (PRD 5.16). The
 * tree is root, with news, sport below it, and culture; the role given is ROLE on sport unless a
 * test says otherwise. Which permissions are administrative comes from the kernel's commands and
 * queries.
 */

const GUARD_ROLE = '01936f5e-8a2b-7c3d-9e4f-000000000801';

const GUARD_NODE = '01936f5e-8a2b-7c3d-9e4f-000000000802';

/**
 * A grant the issuer holds: a role of its own by number, the ceiling, the path, the effect, the
 * locales and the permissions.
 *
 * @param  list<string>  $permissions
 * @param  list<string>|null  $locales
 */
function guardHeld(int $role, string $path, array $permissions, ClassificationAccess $ceiling = ClassificationAccess::Internal, GrantEffect $effect = GrantEffect::Allow, ?array $locales = null): HeldGrant
{
    return new HeldGrant(
        new Grant(RoleId::fromString(sprintf('01936f5e-8a2b-7c3d-9e4f-%012d', 810 + $role)), $ceiling, new NodePath($path), $effect, guardLocales($locales)),
        array_map(static fn (string $name): CommandName => new CommandName($name), $permissions),
    );
}

/**
 * @param  list<string>|null  $locales
 * @return list<Locale>|null
 */
function guardLocales(?array $locales): ?array
{
    return $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $locales);
}

function guardAdministrative(): AdministrativePermissions
{
    return new AdministrativePermissions(FakePermissionCatalog::kernel());
}

/**
 * @param  list<string>  $names
 * @return list<CommandName>
 */
function guardNames(array $names): array
{
    return array_map(static fn (string $name): CommandName => new CommandName($name), $names);
}

/**
 * The guard's decision on giving ROLE with the permissions and ceiling on the path in the locales.
 *
 * @param  list<HeldGrant>  $held
 * @param  list<string>  $permissions
 * @param  list<string>|null  $locales
 */
function guardDecision(array $held, array $permissions, string $path = 'root.news.sport', ?array $locales = null, ClassificationAccess $ceiling = ClassificationAccess::Internal, ClassificationAccess $credential = ClassificationAccess::Sensitive): Authorization
{
    return new EscalationGuard(guardAdministrative())->decide(
        new RoleGrant(
            RoleId::fromString(GUARD_ROLE),
            $ceiling,
            array_map(static fn (string $name): CommandName => new CommandName($name), $permissions),
            NodeId::fromString(GUARD_NODE),
            guardLocales($locales),
        ),
        new NodePath($path),
        $held,
        $credential,
    );
}

it('allows a role whose permissions the issuer holds on the node or above it, through one role or several, and a public role without permissions anywhere', function (): void {
    expect(guardDecision([guardHeld(1, 'root.news', ['entry.create', 'entry.revise'])], ['entry.create', 'entry.revise'])->allowed())->toBeTrue()
        ->and(guardDecision([guardHeld(1, 'root', ['entry.create']), guardHeld(2, 'root.news.sport', ['entry.revise'])], ['entry.create', 'entry.revise'])->allowed())->toBeTrue()
        ->and(guardDecision([], [], 'root.culture', ceiling: ClassificationAccess::Public)->allowed())->toBeTrue()
        ->and(guardDecision([], [], 'root.culture')->code)->toBe(ErrorCode::GrantEscalationRefused);
});

it('refuses a role with a permission the issuer lacks on the node', function (): void {
    $refusal = guardDecision([guardHeld(1, 'root.news', ['entry.create'])], ['entry.create', 'entry.publish']);

    expect($refusal->allowed())->toBeFalse()
        ->and($refusal->code)->toBe(ErrorCode::GrantEscalationRefused)
        ->and($refusal->reason)->toContain('entry.publish')
        ->and($refusal->reason)->toContain('in every locale');
});

it('refuses a role whose permissions the issuer holds only on a sibling node, or only above a deny', function (): void {
    expect(guardDecision([guardHeld(1, 'root.culture', ['entry.create'])], ['entry.create'])->code)->toBe(ErrorCode::GrantEscalationRefused)
        ->and(guardDecision([guardHeld(1, 'root.news.sport.football', ['entry.create'])], ['entry.create'])->code)->toBe(ErrorCode::GrantEscalationRefused)
        ->and(guardDecision([
            guardHeld(1, 'root', ['entry.create']),
            guardHeld(1, 'root.news', ['entry.create'], effect: GrantEffect::Deny),
        ], ['entry.create'])->code)->toBe(ErrorCode::GrantEscalationRefused);
});

it('refuses a role whose permissions the issuer holds only in another locale, or not in every locale of the grant', function (): void {
    $danish = [guardHeld(1, 'root.news', ['entry.create'], locales: ['da'])];

    expect(guardDecision($danish, ['entry.create'], locales: ['da'])->allowed())->toBeTrue()
        ->and(guardDecision($danish, ['entry.create'], locales: ['en'])->reason)->toContain('in the locale en')
        ->and(guardDecision($danish, ['entry.create'], locales: ['da', 'en'])->code)->toBe(ErrorCode::GrantEscalationRefused)
        ->and(guardDecision($danish, ['entry.create'])->code)->toBe(ErrorCode::GrantEscalationRefused);
});

it('refuses a role that reads above the issuer\'s classification access on the node, the credential\'s ceiling included', function (): void {
    $held = [guardHeld(1, 'root.news', ['entry.create'], ClassificationAccess::Personal), guardHeld(2, 'root.culture', ['entry.create'], ClassificationAccess::Sensitive)];

    expect(guardDecision($held, ['entry.create'], ceiling: ClassificationAccess::Personal)->allowed())->toBeTrue()
        ->and(guardDecision($held, ['entry.create'], ceiling: ClassificationAccess::Sensitive)->code)->toBe(ErrorCode::GrantEscalationRefused)
        ->and(guardDecision($held, ['entry.create'], ceiling: ClassificationAccess::Personal, credential: ClassificationAccess::Confidential)->reason)->toContain('above the confidential classification access')
        ->and(guardDecision([guardHeld(1, 'root', [], ClassificationAccess::Sensitive), guardHeld(2, 'root.news', ['entry.create'])], ['entry.create'], ceiling: ClassificationAccess::Sensitive)->allowed())->toBeTrue();
});

it('refuses an administrative role with step_up_required after the issuer\'s own rights, and knows which roles are administrative', function (): void {
    $administrator = [guardHeld(1, 'root', ['entry.create', 'grant.assign', 'role.create', 'actor.deactivate', 'actor.activate'], ClassificationAccess::Sensitive)];

    expect(guardDecision($administrator, ['entry.create', 'grant.assign'])->code)->toBe(ErrorCode::StepUpRequired)
        ->and(guardDecision($administrator, ['actor.deactivate'])->code)->toBe(ErrorCode::StepUpRequired)
        ->and(guardDecision($administrator, ['actor.activate'])->code)->toBe(ErrorCode::StepUpRequired)
        ->and(guardDecision([guardHeld(1, 'root', ['entry.create'])], ['entry.create', 'role.create'])->code)->toBe(ErrorCode::GrantEscalationRefused)
        ->and(guardAdministrative()->any(guardNames(['role.set_permissions'])))->toBeTrue()
        ->and(guardAdministrative()->any(guardNames(['grant.revoke'])))->toBeTrue()
        ->and(guardAdministrative()->any(guardNames(['actor.activate', 'entry.create'])))->toBeTrue()
        ->and(guardAdministrative()->any(guardNames(['actor.register', 'entry.create', 'actor.list'])))->toBeFalse()
        ->and(guardAdministrative()->any([]))->toBeFalse();
});

it('lets an issuer that holds them give a role that may only read roles and grants, because a query changes nothing', function (): void {
    $administrator = [guardHeld(1, 'root', ['role.list', 'grant.list', 'grant.assign'], ClassificationAccess::Sensitive)];

    expect(guardDecision($administrator, ['role.list', 'grant.list'])->allowed())->toBeTrue()
        ->and(guardAdministrative()->any(guardNames(['role.list', 'grant.list'])))->toBeFalse();
});

it('counts as administrative only a command the registry has, so a name that is no write gives no step-up', function (): void {
    $administrative = new AdministrativePermissions(new FakePermissionCatalog(['entry.create'], ['role.create', 'actor.deactivate']));

    expect($administrative->any(guardNames(['role.create', 'actor.deactivate', 'identity.map'])))->toBeFalse()
        ->and(new AdministrativePermissions(new FakePermissionCatalog(['identity.map']))->is(new CommandName('identity.map')))->toBeTrue()
        ->and(new AdministrativePermissions(new FakePermissionCatalog(['actor.reactivate', 'roles.create']))->is(new CommandName('roles.create')))->toBeFalse()
        ->and(new AdministrativePermissions(new FakePermissionCatalog(['actor.reactivate']))->is(new CommandName('actor.reactivate')))->toBeTrue();
});

it('refuses a role whose permissions the issuer holds on the node but not on a node below it, and allows it where a deeper allow gives them back', function (): void {
    $sport = guardHeld(1, 'root.news.sport', ['entry.create'], effect: GrantEffect::Deny);
    $refusal = guardDecision([guardHeld(1, 'root.news', ['entry.create']), $sport], ['entry.create'], 'root.news');

    expect($refusal->code)->toBe(ErrorCode::GrantEscalationRefused)
        ->and($refusal->reason)->toContain('on the node root.news.sport below the node')
        ->and(guardDecision([guardHeld(1, 'root.news', ['entry.create']), $sport], ['entry.create'], 'root.news.culture')->allowed())->toBeTrue()
        ->and(guardDecision([guardHeld(1, 'root.news', ['entry.create']), $sport, guardHeld(2, 'root.news.sport', ['entry.create'])], ['entry.create'], 'root.news')->allowed())->toBeTrue()
        ->and(guardDecision([guardHeld(1, 'root.news', ['entry.create']), $sport, guardHeld(1, 'root.news.sport.football', ['entry.create'])], ['entry.create'], 'root.news')->code)->toBe(ErrorCode::GrantEscalationRefused)
        ->and(guardDecision([
            guardHeld(1, 'root.news', ['entry.create']),
            guardHeld(1, 'root.news.sport', ['entry.create'], effect: GrantEffect::Deny, locales: ['en']),
        ], ['entry.create'], 'root.news', ['da'])->allowed())->toBeTrue()
        ->and(guardDecision([
            guardHeld(1, 'root.news', ['entry.create']),
            guardHeld(1, 'root.news.sport', ['entry.create'], effect: GrantEffect::Deny, locales: ['en']),
        ], ['entry.create'], 'root.news')->code)->toBe(ErrorCode::GrantEscalationRefused);
});

it('refuses an issuer that gives itself, or anyone, a new role on a node above its own deny (invariant 31)', function (): void {
    // The issuer holds R allow on news and R deny on sport. A new role R2 with R's permission and
    // no grants passes ceiling() and content(); giving it on news would reach sport.
    $held = [guardHeld(1, 'root.news', ['entry.create']), guardHeld(1, 'root.news.sport', ['entry.create'], effect: GrantEffect::Deny)];
    $created = new RoleContentChange(RoleId::fromString(GUARD_ROLE), ClassificationAccess::Internal, [new CommandName('entry.create')], [], []);

    expect(new EscalationGuard(guardAdministrative())->ceiling($created, ClassificationAccess::Internal)->allowed())->toBeTrue()
        ->and(new EscalationGuard(guardAdministrative())->content($created, [], $held)->allowed())->toBeTrue()
        ->and(guardDecision($held, ['entry.create'], 'root.news')->code)->toBe(ErrorCode::GrantEscalationRefused);
});

it('refuses a role that reads above the issuer\'s classification access on a node below the node', function (): void {
    $held = [
        guardHeld(1, 'root.news', ['entry.create'], ClassificationAccess::Personal),
        guardHeld(2, 'root.news', ['entry.create']),
        guardHeld(1, 'root.news.sport', ['entry.create'], ClassificationAccess::Personal, GrantEffect::Deny),
    ];

    expect(guardDecision($held, ['entry.create'], 'root.news', ceiling: ClassificationAccess::Internal)->allowed())->toBeTrue()
        ->and(guardDecision($held, ['entry.create'], 'root.news', ceiling: ClassificationAccess::Personal)->reason)->toContain('above the internal classification access')
        ->and(guardDecision($held, ['entry.create'], 'root.news.culture', ceiling: ClassificationAccess::Personal)->allowed())->toBeTrue();
});

it('refuses to add a permission to a role granted on a node where the issuer holds it, but not on a node below it', function (): void {
    $held = [guardHeld(1, 'root.news', ['entry.create', 'entry.publish']), guardHeld(1, 'root.news.sport', ['entry.create', 'entry.publish'], effect: GrantEffect::Deny)];
    $node = NodeId::fromString(GUARD_NODE);
    $change = new RoleContentChange(
        RoleId::fromString(GUARD_ROLE),
        null,
        [new CommandName('entry.publish')],
        [new StoredGrant(GrantId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000803'), ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000804'), RoleId::fromString(GUARD_ROLE), $node, GrantEffect::Allow, null, AggregateVersion::first(), false)],
        [new CommandName('entry.create')],
    );
    $refusal = new EscalationGuard(guardAdministrative())->content($change, [GUARD_NODE => new NodePath('root.news')], $held);

    expect($refusal->code)->toBe(ErrorCode::GrantEscalationRefused)
        ->and($refusal->reason)->toContain('below the node')
        ->and(new EscalationGuard(guardAdministrative())->content($change, [GUARD_NODE => new NodePath('root.news.culture')], $held)->allowed())->toBeTrue();
});
