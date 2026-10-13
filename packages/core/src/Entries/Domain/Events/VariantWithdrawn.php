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
 * A language's variant of an entry is deleted, variant.withdrawn version 1 (PRD 7.2, 10.3): its
 * head is withdrawn and readers see it nowhere, while the entry and its other variants stay. The
 * aggregate is the variant, at the version the changeset left it at. It carries the release state
 * before and the number of the revision that was released, never a field's value (invariant 10).
 */
#[Experimental]
final readonly class VariantWithdrawn implements Event
{
    public const string NAME = 'variant.withdrawn';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'variant';

    public function __construct(
        private int $version,
        private VariantWithdrawnV1 $payload,
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
    public function payload(): VariantWithdrawnV1
    {
        return $this->payload;
    }
}
