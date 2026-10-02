<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Cbox\Cms\Contracts\Plans\Mutations\RolePermissionsSet;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions;

// An administrator creates the role "night_desk" that may create and revise entries and read
// paths, with role.create. The caller makes the role's id. Later role.set_permissions gives the
// role the full new list, at the version the caller read. The kernel holds each permission the
// change adds to the escalation guard on every node where the role is granted.

it('creates a role with its permissions, and replaces them at the version read', function (): void {
    $role = RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000911');

    $create = new CreateRole(
        $role,
        new RoleHandle('night_desk'),
        ClassificationAccess::Internal,
        [new CommandName('entry.create'), new CommandName('entry.revise'), new CommandName('path.resolve')],
    );
    $set = new SetRolePermissions($role, new AggregateVersion(1), [new CommandName('entry.revise'), new CommandName('path.resolve')]);

    expect($create->expectedVersions()->reads[0]->existed())->toBeFalse()
        ->and($create->handle->value)->toBe('night_desk')
        ->and($set->expectedVersions()->reads[0]->version?->value)->toBe(1)
        ->and($set->permissions)->toHaveCount(2);
});

it('keeps a role handle to lowercase letters, digits and underscores, and a role\'s permissions to one of each', function (): void {
    $role = RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000911');

    expect(static fn (): RoleHandle => new RoleHandle('Night desk'))->toThrow(InvalidIdentity::class)
        ->and(static fn (): RolePermissionsSet => new RolePermissionsSet($role, [new CommandName('entry.create'), new CommandName('entry.create')]))
        ->toThrow(InvalidMutation::class);
});
