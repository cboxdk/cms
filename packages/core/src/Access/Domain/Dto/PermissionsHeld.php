<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * What HeldPermissions gives for a principal (PRD 5.10, 13.4): its AccessContext, and the names
 * among those asked for that it may run somewhere, each once and sorted.
 */
#[Internal]
final readonly class PermissionsHeld
{
    /** @var list<CommandName> */
    public array $held;

    /**
     * @param  list<CommandName>  $held
     */
    public function __construct(
        public AccessContext $access,
        array $held,
    ) {
        $byName = [];

        foreach ($held as $name) {
            $byName[$name->value] = $name;
        }

        ksort($byName, SORT_STRING);
        $this->held = array_values($byName);
    }

    /**
     * Whether the principal may run the command or read somewhere.
     */
    public function holds(CommandName $permission): bool
    {
        return array_any($this->held, static fn (CommandName $held): bool => $held->value === $permission->value);
    }
}
