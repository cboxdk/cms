<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The id of a changeset: everything one command commits (PRD 6.1, 6.2 phase 7). It is a UUIDv7,
 * so its first 48 bits are the commit's unix milliseconds, and receipts are partitioned and
 * expired on it (PRD 4, 8.4). As an Identifier it can be carried by an event (PRD 7.2).
 */
#[Experimental]
final readonly class ChangesetId implements Identifier
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

    public function unixMilliseconds(): int
    {
        return $this->value->unixMilliseconds();
    }

    public function equals(self $other): bool
    {
        return $this->value->equals($other->value);
    }
}
