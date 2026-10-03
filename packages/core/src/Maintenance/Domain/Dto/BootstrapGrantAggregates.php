<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Access\Domain\Dto\AssignGrantAggregates;
use Cbox\Cms\Core\Access\Domain\Dto\CreateRoleAggregates;
use Cbox\Cms\Core\Access\Domain\Dto\RoleGrant;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;
use Cbox\Cms\Core\Access\Domain\GuardedGrant;
use Override;

/**
 * What access.bootstrap read (PRD 5.10, 6.2 phase 1): what role.create reads, when no role has the
 * bootstrap role's id ($creation), and what grant.assign reads ($assignment). The role is read
 * once: absent when it is created, else at the version grant.assign read it. $role is the role the
 * grant gives, as it stands after the plan: the one created, or the one read.
 *
 * The grant is authorized on its node in every locale, and held to the escalation guard with the
 * role it gives.
 */
#[Internal]
final readonly class BootstrapGrantAggregates implements GuardedGrant
{
    public function __construct(
        public ?CreateRoleAggregates $creation,
        public AssignGrantAggregates $assignment,
        public StoredRole $role,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        if (! $this->creation instanceof CreateRoleAggregates) {
            return $this->assignment->versions();
        }

        $role = $this->assignment->role->aggregateKey();
        $granted = array_filter(
            $this->assignment->versions()->reads,
            static fn (ReadVersion $read): bool => $read->aggregate->aggregateKey() !== $role,
        );

        return new ReadVersions(...$this->creation->versions()->reads, ...$granted);
    }

    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->assignment->authorizationScope();
    }

    #[Override]
    public function escalation(): RoleGrant
    {
        return new RoleGrant($this->role->id, $this->role->ceiling, $this->role->permissions, $this->assignment->slot->node, $this->assignment->locales);
    }

    /**
     * What grant.assign's refusals check: its reads, with the role as the plan leaves it.
     */
    public function planned(): AssignGrantAggregates
    {
        $assignment = $this->assignment;

        return new AssignGrantAggregates(
            $assignment->grant,
            $assignment->existing,
            $assignment->actor,
            $assignment->role,
            $this->role,
            $assignment->slot,
            $assignment->slotTaken,
            $assignment->effect,
            $assignment->locales,
            $assignment->actorGrants,
        );
    }
}
