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
 * A placement's slug in one locale was changed, placement.slug_changed version 1 (PRD 5.9, 7.2):
 * the placement at its new version, its entry, the node its slug is looked up under and the
 * locale. It carries no slug, old or new, which is text (invariant 10); a subscriber that needs
 * the URL reads the placement. A rename is not a visibility change, so the invalidation purges the
 * keys softly.
 */
#[Experimental]
final readonly class PlacementSlugChanged implements Event
{
    public const string NAME = 'placement.slug_changed';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'placement';

    public function __construct(
        private int $version,
        private PlacementSlugChangedV1 $payload,
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
    public function payload(): PlacementSlugChangedV1
    {
        return $this->payload;
    }
}
