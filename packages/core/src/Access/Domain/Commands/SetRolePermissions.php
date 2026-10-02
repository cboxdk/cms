<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName as Permission;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Replaces a role's permissions (PRD 5.10, 6.4), version 1 of role.set_permissions: the role, the
 * version of it the caller read, and the full new list of the command and query names of the
 * registry it may run, each once. A role at another version, or none with the id, is
 * version_conflict; a list equal to the role's changes nothing and is validation_failed.
 *
 * The issuing actor needs role.set_permissions on some node, and must itself hold each permission
 * the list adds on every node where the role is granted, in the grant's locales, or the change is
 * refused with grant_escalation_refused (invariant 31). A change that makes a granted role
 * administrative, so that it may change roles, grants or who is active, needs step-up and is
 * refused with step_up_required. Every grant of the role moves to its next version with
 * grant.changed, so what its actor may do is compiled again.
 */
#[CommandName('role.set_permissions', version: 1)]
#[Experimental]
final readonly class SetRolePermissions implements ExpectsVersions
{
    /**
     * @param  list<Permission>  $permissions
     */
    public function __construct(
        public RoleId $role,
        public AggregateVersion $version,
        public array $permissions,
    ) {}

    /**
     * The role, at the version the caller read.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->role, $this->version));
    }
}
