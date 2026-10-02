<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\Dto\HeldGrant;
use Cbox\Cms\Core\Access\Domain\Dto\RoleGrant;
use Cbox\Cms\Core\Access\Domain\EscalationGuard;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;

/*
 * The escalation guard (PRD 5.10, invariant 31) on its own: an actor gives a role only when it holds
 * each of the role's permissions on the node in the grant's locales, and has a classification
 * access there not below the role's ceiling; an administrative role needs step-up (PRD 5.16). The
 * tree is root, with news, sport below it, and culture; the role given is ROLE on sport unless a
 * test says otherwise.
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

/**
 * The guard's decision on giving ROLE with the permissions and ceiling on the path in the locales.
 *
 * @param  list<HeldGrant>  $held
 * @param  list<string>  $permissions
 * @param  list<string>|null  $locales
 */
function guardDecision(array $held, array $permissions, string $path = 'root.news.sport', ?array $locales = null, ClassificationAccess $ceiling = ClassificationAccess::Internal, ClassificationAccess $credential = ClassificationAccess::Sensitive): Authorization
{
    return new EscalationGuard()->decide(
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
    $administrator = [guardHeld(1, 'root', ['entry.create', 'grant.assign', 'role.create', 'actor.deactivate'], ClassificationAccess::Sensitive)];

    expect(guardDecision($administrator, ['entry.create', 'grant.assign'])->code)->toBe(ErrorCode::StepUpRequired)
        ->and(guardDecision($administrator, ['actor.deactivate'])->code)->toBe(ErrorCode::StepUpRequired)
        ->and(guardDecision([guardHeld(1, 'root', ['entry.create'])], ['entry.create', 'role.create'])->code)->toBe(ErrorCode::GrantEscalationRefused)
        ->and(EscalationGuard::administrative([new CommandName('role.set_permissions')]))->toBeTrue()
        ->and(EscalationGuard::administrative([new CommandName('actor.activate'), new CommandName('entry.create')]))->toBeFalse()
        ->and(EscalationGuard::administrative([]))->toBeFalse();
});
