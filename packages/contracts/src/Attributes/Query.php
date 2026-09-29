<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Attribute;
use Cbox\Cms\Contracts\Ids\CommandName;
use InvalidArgumentException;

/**
 * Declares the name and version of a query DTO, for example #[Query('note.title', version: 1)]
 * (GUARDRAILS 2.1, PRD 6.2), as #[Command] does for a command.
 *
 * The name has the form of Command::NAME_PATTERN, and commands and queries share the names: a
 * name and version belong to one command or one query. The version starts at 1.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Experimental]
final readonly class Query
{
    public function __construct(
        public string $name,
        public int $version,
    ) {
        if (preg_match(Command::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Query name "%s" must be dot-separated snake_case segments, for example "entry.find".',
                $name,
            ));
        }

        if ($version < 1) {
            throw new InvalidArgumentException(sprintf(
                'Query "%s" has version %d. Versions start at 1.',
                $name,
                $version,
            ));
        }
    }

    /**
     * The name as the value object the registry joins actions on (GUARDRAILS 2.2).
     */
    public function name(): CommandName
    {
        return new CommandName($this->name);
    }
}
