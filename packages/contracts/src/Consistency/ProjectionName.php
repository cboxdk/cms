<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The name of a projection, such as "fragments", "edge" or "search" (PRD 8.2). It is one or more
 * snake_case segments separated by dots, so an addon can use its own prefix, and at most
 * MAX_LENGTH characters.
 */
#[Experimental]
final readonly class ProjectionName
{
    /** The longest Postgres identifier, so a name also fits where an identifier is needed. */
    public const int MAX_LENGTH = 63;

    private const string PATTERN = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*\z/';

    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidReceipt::projectionName($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
