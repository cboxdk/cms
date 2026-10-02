<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * One grant an actor holds, with the permissions of its role (PRD 5.10): what the escalation guard
 * holds a grant the actor gives against.
 */
#[Internal]
final readonly class HeldGrant
{
    /**
     * @param  list<CommandName>  $permissions  the command and query names the grant's role may run
     */
    public function __construct(
        public Grant $grant,
        public array $permissions,
    ) {}

    /**
     * Whether the grant's role may run the command or read.
     */
    public function permits(CommandName $permission): bool
    {
        return array_any($this->permissions, static fn (CommandName $held): bool => $held->value === $permission->value);
    }
}
