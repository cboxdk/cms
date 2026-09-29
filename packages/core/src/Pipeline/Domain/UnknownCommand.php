<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * A command that no write action handles, or whose registered action is not a write action. A
 * surface only builds commands the registry lists, so this is a stale registry cache or a bug, not
 * bad input: run cms:build.
 */
#[Internal]
final class UnknownCommand extends LogicException
{
    public static function noAction(string $commandClass): self
    {
        return new self(sprintf('No write action handles the command %s. Declare one with #[Action(handles: ...)] and run cms:build.', $commandClass));
    }

    public static function version(string $command, int $version): self
    {
        return new self(sprintf('The command %s has version %d. Versions start at 1.', $command, $version));
    }

    public static function notAWriteAction(string $commandClass, string $actionClass): self
    {
        return new self(sprintf('The action %s registered for the command %s does not implement WriteAction. Run cms:build.', $actionClass, $commandClass));
    }
}
