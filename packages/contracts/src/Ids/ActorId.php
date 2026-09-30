<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * The id of an actor: whoever runs a command or a read, a person, an agent, an integration or a
 * service identity (PRD 5.3, 5.16, 6.1). It is a UUIDv7 from the IdGenerator, fixed when the actor
 * is created and never reused.
 */
#[Experimental]
final readonly class ActorId implements AggregateRef, Identifier
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
     * "actor:" and the UUID, unique across the kinds of aggregate.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return 'actor:'.$this->value->value;
    }
}
