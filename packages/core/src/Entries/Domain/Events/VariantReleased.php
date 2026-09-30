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
 * A revision of a variant was released, variant.released version 1 (PRD 5.6, 7.2): readers see it
 * now where a placement makes the entry visible. The aggregate is the variant, at the version the
 * changeset left it at. It carries revision numbers, never a field's value (invariant 10); a
 * subscriber reads the variant's released state.
 */
#[Experimental]
final readonly class VariantReleased implements Event
{
    public const string NAME = 'variant.released';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'variant';

    public function __construct(
        private int $version,
        private VariantReleasedV1 $payload,
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
    public function payload(): VariantReleasedV1
    {
        return $this->payload;
    }
}
