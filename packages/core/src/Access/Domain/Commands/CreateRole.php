<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\CommandName as Permission;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Creates a role (PRD 5.10, 6.4), version 1 of role.create: the caller's id of the new role, its
 * handle, the highest classification its holders read through it, and its permissions, the
 * command and query names of the registry it may run, each once. It expects the role absent; a
 * role with the id is version_conflict.
 *
 * The issuing actor needs role.create on some node, and the ceiling may not be above its own
 * classification access, or the role is refused with grant_escalation_refused (invariant 31). A
 * name the registry does not know, a name given twice and a handle another role has are
 * validation_failed. The new role is granted to nobody, so its permissions are held to the
 * escalation guard when a grant gives it (grant.assign) or a change adds to them.
 */
#[CommandName('role.create', version: 1)]
#[Experimental]
final readonly class CreateRole implements ExpectsVersions
{
    /**
     * @param  list<Permission>  $permissions
     */
    public function __construct(
        public RoleId $role,
        public RoleHandle $handle,
        public ClassificationAccess $ceiling,
        public array $permissions,
    ) {}

    /**
     * The role, absent.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::absent($this->role));
    }
}
