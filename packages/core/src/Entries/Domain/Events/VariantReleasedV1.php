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
 * Version 1 of the payload of variant.released: the entry, the variant, the number of the revision
 * the command released, the number of the published revision the head now points at, which is the
 * released revision itself when it was published before and otherwise the one the release wrote
 * after it, and the number of the revision released before, or null for the variant's first
 * release.
 */
#[Experimental]
final readonly class VariantReleasedV1 implements EventPayload
{
    public function __construct(
        public EntryId $entry,
        public VariantRef $variant,
        public int $revision,
        public int $published,
        public ?int $previous,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('entry', EventDatum::identifier($this->entry))
            ->with('variant', EventDatum::identifier($this->variant))
            ->with('revision', EventDatum::integer($this->revision))
            ->with('published', EventDatum::integer($this->published))
            ->with('previous', $this->previous === null ? EventDatum::null() : EventDatum::integer($this->previous));
    }
}
