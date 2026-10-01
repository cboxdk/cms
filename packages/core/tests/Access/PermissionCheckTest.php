<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\PermissionCheck;

/*
 * Whether a permission holds (PRD 5.10), from the grants of the roles that carry it: on a node
 * through the nearest grant above it, in the target's locale when it has one, and anywhere when
 * one of the roles reaches a node.
 */

const PERMISSION_ROLE = '0192a0c0-0000-7000-8000-0000000052d1';

function permissionActor(): ActorPrincipal
{
    return new ActorPrincipal(ActorId::fromString('0192a0c0-0000-7000-8000-0000000052c1'), [], IssuerKind::Service, ClassificationAccess::Internal);
}

/**
 * @param  list<string>|null  $locales
 */
function permissionGrant(string $path, GrantEffect $effect = GrantEffect::Allow, ?array $locales = null): Grant
{
    return new Grant(RoleId::fromString(PERMISSION_ROLE), ClassificationAccess::Internal, new NodePath($path), $effect, $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $locales));
}

it('holds on a node an allow above reaches, and not below a deny', function (): void {
    $check = new PermissionCheck(new AccessCompiler);
    $grants = [permissionGrant('a'), permissionGrant('a.b', GrantEffect::Deny)];

    expect($check->holdsOn(permissionActor(), $grants, new NodePath('a.c'), null))->toBeTrue()
        ->and($check->holdsOn(permissionActor(), $grants, new NodePath('a.b.c'), null))->toBeFalse()
        ->and($check->holdsOn(permissionActor(), $grants, new NodePath('z'), null))->toBeFalse()
        ->and($check->holdsOn(permissionActor(), [], new NodePath('a'), null))->toBeFalse();
});

it('counts only the grants that hold in the target\'s locale', function (): void {
    $check = new PermissionCheck(new AccessCompiler);
    $grants = [permissionGrant('a'), permissionGrant('a.b', GrantEffect::Deny, ['da']), permissionGrant('c', locales: ['en'])];

    expect($check->holdsOn(permissionActor(), $grants, new NodePath('a.b'), new Locale('da')))->toBeFalse()
        ->and($check->holdsOn(permissionActor(), $grants, new NodePath('a.b'), new Locale('en')))->toBeTrue()
        ->and($check->holdsOn(permissionActor(), $grants, new NodePath('a.b'), null))->toBeTrue()
        ->and($check->holdsOn(permissionActor(), $grants, new NodePath('c'), new Locale('en')))->toBeTrue()
        ->and($check->holdsOn(permissionActor(), $grants, new NodePath('c'), new Locale('da')))->toBeFalse()
        ->and($check->holdsOn(permissionActor(), $grants, new NodePath('c'), null))->toBeTrue();
});

it('holds anywhere when a role reaches a node, and not with only denies', function (): void {
    $check = new PermissionCheck(new AccessCompiler);

    expect($check->holdsAnywhere(permissionActor(), [permissionGrant('a')]))->toBeTrue()
        ->and($check->holdsAnywhere(permissionActor(), [permissionGrant('a', GrantEffect::Deny)]))->toBeFalse()
        ->and($check->holdsAnywhere(permissionActor(), []))->toBeFalse();
});
