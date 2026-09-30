<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventType;
use Override;

/**
 * A placement was created, placement.created version 1 (PRD 5.7, 7.2): the placement at its first
 * version, with its entry, its node and its site. It carries ids only, never a slug (invariant 10);
 * a subscriber reads the placement's state.
 */
#[Experimental]
final readonly class PlacementCreated implements Event
{
    public const string NAME = 'placement.created';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'placement';

    public function __construct(
        private int $version,
        private PlacementCreatedV1 $payload,
    ) {}

    #[Override]
    public static function type(): EventType
    {
        return new EventType(self::NAME, 1);
    }

    #[Override]
    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType(self::AGGREGATE), $this->payload->placement, $this->version);
    }

    #[Override]
    public function payload(): PlacementCreatedV1
    {
        return $this->payload;
    }
}
