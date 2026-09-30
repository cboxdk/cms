<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use RuntimeException;

/**
 * cms:hooks was asked for a name the registry holds no command by (PRD 13.2): no #[Command] class
 * has it, or it is the name of a query, which runs no hooks.
 */
#[Experimental]
final class UnknownCommand extends RuntimeException
{
    /**
     * @param  list<CommandName>  $registered  the names of the registered commands
     */
    public static function notRegistered(CommandName $name, array $registered): self
    {
        return new self(sprintf(
            'No registered command is named %s. %s Run php artisan cms:actions to list every action, or php artisan cms:build when the command was added since the last build.',
            $name->value,
            $registered === []
                ? 'The registry holds no commands.'
                : 'The registered commands are '.implode(', ', array_map(static fn (CommandName $command): string => $command->value, $registered)).'.',
        ));
    }

    public static function query(CommandName $name): self
    {
        return new self(sprintf(
            '%s is a query. Hooks run for commands alone, in the command pipeline, so a query has no hook map.',
            $name->value,
        ));
    }
}
