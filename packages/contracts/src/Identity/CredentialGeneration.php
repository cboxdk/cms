<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * An actor's credential generation (PRD 5.16). Every credential carries the generation its actor
 * had when it was issued, and is refused once that is lower than the actor's. Deactivation,
 * deprovisioning and actor.credentials_revoke count the actor's generation up, so everything the
 * actor holds is refused at once, without a clock that could differ between pods. It starts at 1.
 */
#[Experimental]
final readonly class CredentialGeneration
{
    public const int FIRST = 1;

    public function __construct(public int $value)
    {
        if ($value < self::FIRST) {
            throw InvalidIdentity::generation($value);
        }
    }

    public static function first(): self
    {
        return new self(self::FIRST);
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function isBelow(self $other): bool
    {
        return $this->value < $other->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
