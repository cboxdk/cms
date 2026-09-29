<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventIdentifier;
use Cbox\Cms\Contracts\Events\InvalidEvent;

/**
 * An aggregate without a version, as a subscription parks it (PRD 7.8): its type and id, such as
 * entry and a UUID. Written as "<type>:<id>"; the type has no colon, so the first colon ends it.
 */
#[Experimental]
final readonly class AggregateKey
{
    public function __construct(
        public AggregateType $type,
        public EventIdentifier $id,
    ) {}

    public static function of(EventAggregate $aggregate): self
    {
        return new self($aggregate->type, $aggregate->id);
    }

    /**
     * Parses "<type>:<id>".
     *
     * @throws InvalidEvent when the type or the id is not in its form, or there is no colon
     */
    public static function fromString(string $value): self
    {
        $colon = strpos($value, ':');

        if ($colon === false) {
            throw InvalidEvent::aggregateType();
        }

        return new self(new AggregateType(substr($value, 0, $colon)), new EventIdentifier(substr($value, $colon + 1)));
    }

    public function toString(): string
    {
        return $this->type->value.':'.$this->id->value;
    }

    public function equals(self $other): bool
    {
        return $this->type->equals($other->type) && $this->id->equals($other->id);
    }
}
