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
 * A pending actor was activated, actor.activated version 1 (PRD 5.16, 7.2): the actor at the
 * version its activation left it at. It carries the actor's id alone, never text (invariant 10).
 */
#[Experimental]
final readonly class ActorActivated implements Event
{
    public const string NAME = 'actor.activated';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'actor';

    public function __construct(
        private int $version,
        private ActorActivatedV1 $payload,
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
    public function payload(): ActorActivatedV1
    {
        return $this->payload;
    }
}
