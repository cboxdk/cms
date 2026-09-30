<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventType;
use Override;

/**
 * A variant has no released revision any more, variant.unreleased version 1 (PRD 5.6, 6.4, 7.2):
 * its content was unpublished, and readers see it nowhere until it is released again. The
 * aggregate is the variant, at the version the changeset left it at. It carries the number of the
 * revision that was released, never a field's value (invariant 10).
 */
#[Experimental]
final readonly class VariantUnreleased implements Event
{
    public const string NAME = 'variant.unreleased';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'variant';

    public function __construct(
        private int $version,
        private VariantUnreleasedV1 $payload,
    ) {}

    #[Override]
    public static function type(): EventType
    {
        return new EventType(self::NAME, 1);
    }

    #[Override]
    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType(self::AGGREGATE), $this->payload->variant, $this->version);
    }

    #[Override]
    public function payload(): VariantUnreleasedV1
    {
        return $this->payload;
    }
}
