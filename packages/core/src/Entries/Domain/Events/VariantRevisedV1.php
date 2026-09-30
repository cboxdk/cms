<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\EntryId;
use Override;

/**
 * Version 1 of the payload of variant.revised: the entry, the variant, the number of the revision
 * the head moved to, and the number it moved from, or null for the variant's first revision.
 */
#[Experimental]
final readonly class VariantRevisedV1 implements EventPayload
{
    public function __construct(
        public EntryId $entry,
        public VariantRef $variant,
        public int $revision,
        public ?int $previous,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('entry', EventDatum::identifier($this->entry))
            ->with('variant', EventDatum::identifier($this->variant))
            ->with('revision', EventDatum::integer($this->revision))
            ->with('previous', $this->previous === null ? EventDatum::null() : EventDatum::integer($this->previous));
    }
}
