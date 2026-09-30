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
 * A placement's window in one locale was set, placement.visibility_changed version 1 (PRD 5.7,
 * 6.4, 7.2): the placement at its new version, the locale, the visibility state before and after,
 * the window and the time of the next transition. It carries no slug and no presentation
 * (invariant 10).
 */
#[Experimental]
final readonly class PlacementVisibilityChanged implements Event
{
    public const string NAME = 'placement.visibility_changed';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'placement';

    public function __construct(
        private int $version,
        private PlacementVisibilityChangedV1 $payload,
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
    public function payload(): PlacementVisibilityChangedV1
    {
        return $this->payload;
    }
}
