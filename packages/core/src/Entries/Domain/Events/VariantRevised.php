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
 * The head of a variant moved to a new revision, variant.revised version 1 (PRD 5.4, 7.2): an
 * entry.create gives its first, an entry.revise each next. The aggregate is the variant, at the
 * version the changeset left it at. It carries the revision numbers, never a field's value
 * (invariant 10); a subscriber reads the variant's state.
 */
#[Experimental]
final readonly class VariantRevised implements Event
{
    public const string NAME = 'variant.revised';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'variant';

    public function __construct(
        private int $version,
        private VariantRevisedV1 $payload,
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
    public function payload(): VariantRevisedV1
    {
        return $this->payload;
    }
}
