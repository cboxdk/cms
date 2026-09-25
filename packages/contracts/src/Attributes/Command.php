<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Declares the name and version of a command DTO, for example
 * #[Command('entry.release', version: 1)] (GUARDRAILS 2.1, PRD 6.1).
 *
 * The name is lowercase, with at least two segments separated by dots, and each segment
 * in snake_case. The version starts at 1.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Experimental]
final readonly class Command
{
    /**
     * The form of a command name. \A and \z anchor the whole string, so a trailing newline is not
     * accepted as $ would.
     */
    public const string NAME_PATTERN = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+\z/';

    public function __construct(
        public string $name,
        public int $version,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Command name "%s" must be dot-separated snake_case segments, for example "entry.release".',
                $name,
            ));
        }

        if ($version < 1) {
            throw new InvalidArgumentException(sprintf(
                'Command "%s" has version %d. Versions start at 1.',
                $name,
                $version,
            ));
        }
    }
}
