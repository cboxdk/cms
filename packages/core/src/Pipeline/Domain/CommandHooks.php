<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\Dto\BoundHook;

/**
 * The hooks that run for a version of a command (GUARDRAILS 2.4, PRD 6.3, 13.2): every hook
 * cms:build registered for it, of every phase, built and bound to its declaration. The command
 * pipeline picks each phase's hooks and orders them; the order this port gives is not part of it.
 */
#[Internal]
interface CommandHooks
{
    /**
     * @return list<BoundHook>
     *
     * @throws InvalidHook when a registered hook does not implement the interface of its phase
     */
    public function for(CommandName $command, int $version): array;
}
