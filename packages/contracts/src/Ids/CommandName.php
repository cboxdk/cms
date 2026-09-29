<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Ids;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The name of a command type, as #[Command] declares it on the command DTO and without its
 * version, for example "entry.release" (PRD 6.1), or of a query type, as #[Query] declares it. It
 * has the form of Command::NAME_PATTERN: lowercase, at least two segments separated by dots, each
 * segment in snake_case.
 */
#[Experimental]
final readonly class CommandName
{
    public function __construct(public string $value)
    {
        if (preg_match(Command::NAME_PATTERN, $value) !== 1) {
            throw InvalidCommandName::malformed($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
