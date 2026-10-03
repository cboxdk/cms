<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;

/**
 * What a command that creates a role or changes its permissions gives that the escalation guard
 * holds against the issuing actor (PRD 5.10, invariant 31): the ceiling of a role it creates, or
 * null when the ceiling stays; the permissions it adds that the registry knows; the grants of the
 * role that have not ended, on whose nodes the issuer must hold each added permission; and the
 * permissions the role held before, from which the guard decides whether the change makes it
 * administrative.
 */
#[Internal]
final readonly class RoleContentChange
{
    /**
     * @param  list<CommandName>  $added
     * @param  list<StoredGrant>  $grants
     * @param  list<CommandName>  $previous  the role's permissions before the change; none for a role it creates
     */
    public function __construct(
        public RoleId $role,
        public ?ClassificationAccess $ceiling,
        public array $added,
        public array $grants,
        public array $previous,
    ) {}

    /**
     * The grants that give the role: an allow that has not ended. A deny keeps the role from its
     * subtree, so what the role may run gives nothing there.
     *
     * @return list<StoredGrant>
     */
    public function allows(): array
    {
        return array_values(array_filter($this->grants, static fn (StoredGrant $grant): bool => $grant->effect === GrantEffect::Allow && ! $grant->ended));
    }
}
