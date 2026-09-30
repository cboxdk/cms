<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * The id of an entry: the identity of a piece of content, with its type, its home node and its
 * lifecycle, and nothing else (PRD 5.3, 5.4). It is a UUIDv7, made by the IdGenerator contract.
 */
#[Experimental]
final readonly class EntryId implements AggregateRef, Identifier
{
    public function __construct(public Uuid7 $value) {}

    /**
     * Parses the canonical UUIDv7 string. Anything else throws InvalidUuid7.
     */
    public static function fromString(string $value): self
    {
        return new self(new Uuid7($value));
    }

    #[Override]
    public function toString(): string
    {
        return $this->value->value;
    }

    public function equals(self $other): bool
    {
        return $this->value->equals($other->value);
    }

    /**
     * "entry:" and the UUID, unique across the kinds of aggregate.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return 'entry:'.$this->value->value;
    }
}
