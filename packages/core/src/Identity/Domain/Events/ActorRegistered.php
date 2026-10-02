<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventType;
use Override;

/**
 * An actor was registered, actor.registered version 1 (PRD 5.16, 7.2): the actor, pending at
 * version 1. It carries ids, the class and a count, never the profile, which is personal data
 * (invariant 10); a subscriber that needs the profile reads it under its own access.
 */
#[Experimental]
final readonly class ActorRegistered implements Event
{
    public const string NAME = 'actor.registered';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'actor';

    public function __construct(
        private int $version,
        private ActorRegisteredV1 $payload,
    ) {}

    #[Override]
    public static function type(): EventType
    {
        return new EventType(self::NAME, 1);
    }

    #[Override]
    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType(self::AGGREGATE), $this->payload->actor, $this->version);
    }

    #[Override]
    public function payload(): ActorRegisteredV1
    {
        return $this->payload;
    }
}
