<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\InvalidCommandName;
use Cbox\Cms\Core\Registry\Domain\Dto\HookMapRequest;

/**
 * Reads the argument of cms:hooks, the name of a command such as entry.create, into the request
 * of the MapHooks action; anything that is not a command's name is a usage error, exit 64.
 */
#[Internal]
final readonly class HookMapInput
{
    /**
     * @throws CliCallRefused
     */
    public static function read(mixed $command): HookMapRequest
    {
        if (! is_string($command)) {
            throw CliCallRefused::usage('Give the name of the command, such as entry.create.');
        }

        try {
            return new HookMapRequest(new CommandName($command));
        } catch (InvalidCommandName $invalid) {
            throw CliCallRefused::usage($invalid->getMessage(), $invalid);
        }
    }
}
