<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName as Permission;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Override;

/**
 * The one-time access bootstrap's changeset (PRD 5.10, 5.16, GUARDRAILS 2.1), version 1 of
 * access.bootstrap: the bootstrap role, with its id, handle, ceiling and permissions, and the grant
 * of it to a staff actor on a node, allowing in every locale. Its write action composes the plans
 * of role.create, when no role has the id, and of grant.assign into one plan, so the role and its
 * grant commit together or not at all. It expects the grant absent; a grant with the id is
 * version_conflict.
 *
 * It is a maintenance command, on no surface: only cms:access:bootstrap runs it, as the
 * installation operator through the bootstrap's pipeline, whose MaintenanceAuthorizer allows it and
 * nothing else. Through the kernel's authorizer the grant is held to the escalation guard, as a
 * grant.assign of the role is.
 */
#[CommandName('access.bootstrap', version: 1)]
#[Experimental]
final readonly class GrantBootstrapRole implements ExpectsVersions
{
    /**
     * @param  list<Permission>  $permissions
     */
    public function __construct(
        public GrantId $grant,
        public ActorId $actor,
        public NodeId $node,
        public RoleId $role,
        public RoleHandle $handle,
        public ClassificationAccess $ceiling,
        public array $permissions,
    ) {}

    /**
     * The grant, absent.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::absent($this->grant));
    }

    /**
     * The role.create this command creates the role with when no role has its id.
     */
    public function creation(): CreateRole
    {
        return new CreateRole($this->role, $this->handle, $this->ceiling, $this->permissions);
    }

    /**
     * The grant.assign of the role to the actor on the node, allowing in every locale.
     */
    public function assignment(): AssignGrant
    {
        return new AssignGrant($this->grant, $this->actor, $this->role, $this->node, GrantEffect::Allow);
    }
}
