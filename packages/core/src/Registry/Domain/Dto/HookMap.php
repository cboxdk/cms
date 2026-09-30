<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * The hook map of a command (PRD 13.2): for each registered version of the command, lowest first,
 * every hook that runs for it in the order the command pipeline runs them, with its addon, phase,
 * priority and budget on its entry.
 */
#[Experimental]
final readonly class HookMap
{
    /**
     * @param  list<VersionHooks>  $versions  the command's registered versions, lowest first
     */
    public function __construct(
        public CommandName $command,
        public array $versions,
    ) {}
}
