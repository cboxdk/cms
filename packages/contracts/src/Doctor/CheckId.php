<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Doctor;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The stable name of a doctor check, such as `postgres.reachable` (PRD 3.3, 4.2).
 *
 * Two or more lowercase snake_case segments separated by dots, at most MAX_LENGTH characters. The
 * id is part of the JSON document of `cms:doctor --json`, so a check keeps its id.
 */
#[Experimental]
final readonly class CheckId
{
    public const int MAX_LENGTH = 63;

    public const string PATTERN = '/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/';

    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidDoctorCheck::id($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
