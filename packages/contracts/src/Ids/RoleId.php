<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * The id of a role: a set of permissions, the command names it may run, with a classification
 * ceiling (PRD 5.10, 12.2). It is a UUIDv7, made by the IdGenerator contract.
 */
#[Experimental]
final readonly class RoleId implements AggregateRef
{
    public function __construct(public Uuid7 $value) {}

    /**
     * Parses the canonical UUIDv7 string. Anything else throws InvalidUuid7.
     */
    public static function fromString(string $value): self
    {
        return new self(new Uuid7($value));
    }

    public function toString(): string
    {
        return $this->value->value;
    }

    public function equals(self $other): bool
    {
        return $this->value->equals($other->value);
    }

    /**
     * "role:" and the UUID, unique across the kinds of aggregate.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return 'role:'.$this->value->value;
    }
}
