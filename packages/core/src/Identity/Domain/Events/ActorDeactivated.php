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
 * An actor was deactivated, actor.deactivated version 1 (PRD 5.16, 7.2): the actor at the version
 * its deactivation left it at. It carries ids, the source and counts, never text (invariant 10); a
 * subscriber reads the actor's state.
 */
#[Experimental]
final readonly class ActorDeactivated implements Event
{
    public const string NAME = 'actor.deactivated';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'actor';

    public function __construct(
        private int $version,
        private ActorDeactivatedV1 $payload,
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
    public function payload(): ActorDeactivatedV1
    {
        return $this->payload;
    }
}
