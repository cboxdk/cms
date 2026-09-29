<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;

/**
 * The write action of a command, as the command pipeline asks for it (GUARDRAILS 2.1, PRD 13.2):
 * the action cms:build registered for the command's class, with the command's name and version
 * from its #[Command].
 */
#[Internal]
interface WriteActions
{
    /**
     * @throws UnknownCommand when no write action handles the command's class
     */
    public function for(Command $command): ActionBinding;
}
