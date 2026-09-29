<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Pipeline\Domain\UnknownCommand;

/**
 * A command's name and version, from its #[Command], and the write action that handles it.
 */
#[Internal]
final readonly class ActionBinding
{
    /**
     * @param  WriteAction<Command, Aggregates>  $action  an action for the command's class, as the registry matched it
     */
    public function __construct(
        public CommandName $command,
        public int $version,
        public WriteAction $action,
    ) {
        if ($version < 1) {
            throw UnknownCommand::version($command->value, $version);
        }
    }
}
