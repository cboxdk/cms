<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\EntryId;
use Override;

/**
 * Version 1 of the payload of variant.withdrawn: the entry, the variant, its release state before
 * and the number of the revision that was released until now, or null when none was.
 */
#[Experimental]
final readonly class VariantWithdrawnV1 implements EventPayload
{
    public function __construct(
        public EntryId $entry,
        public VariantRef $variant,
        public ReleaseState $previous,
        public ?int $revision,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('entry', EventDatum::identifier($this->entry))
            ->with('variant', EventDatum::identifier($this->variant))
            ->with('previous', EventDatum::enum($this->previous))
            ->with('revision', $this->revision === null ? EventDatum::null() : EventDatum::integer($this->revision));
    }
}
