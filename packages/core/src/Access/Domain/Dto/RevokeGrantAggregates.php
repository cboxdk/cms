<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Access\Domain\GuardedGrant;
use Override;

/**
 * What grant.revoke read (PRD 5.10, 6.2 phase 1): the grant, or null when no grant has the id or
 * the issuing actor's regions do not reach its node, and its role with its permissions. The kernel
 * checks both at commit under their locks.
 *
 * The command is authorized on the grant's node in its locales. Ending a deny gives the actor back
 * what the deny kept from it, so a deny's role is held to the escalation guard.
 */
#[Internal]
final readonly class RevokeGrantAggregates implements GuardedGrant
{
    public function __construct(
        public GrantId $id,
        public ?StoredGrant $grant,
        public ?StoredRole $role,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        if (! $this->grant instanceof StoredGrant) {
            return new ReadVersions(new ReadVersion($this->id, null));
        }

        return new ReadVersions(
            new ReadVersion($this->id, $this->grant->version),
            new ReadVersion($this->grant->role, $this->role?->version),
        );
    }

    /**
     * The grant's node in each of its locales, or in every locale; anywhere for a grant not read,
     * which the kernel rejects as version_conflict before it authorizes.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->grant instanceof StoredGrant
            ? AssignGrantAggregates::scope($this->grant->node, $this->grant->locales)
            : AuthorizationScope::anywhere();
    }

    /**
     * The role of a deny that has not ended; ending an allow takes rights away.
     */
    #[Override]
    public function escalation(): ?RoleGrant
    {
        if (! $this->grant instanceof StoredGrant || $this->grant->ended || $this->grant->effect !== GrantEffect::Deny || ! $this->role instanceof StoredRole) {
            return null;
        }

        return new RoleGrant($this->grant->role, $this->role->ceiling, $this->role->permissions, $this->grant->node, $this->grant->locales);
    }
}
