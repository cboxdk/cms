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
 * Version 1 of the payload of variant.unreleased: the entry, the variant and the number of the
 * published revision that was released until now.
 */
#[Experimental]
final readonly class VariantUnreleasedV1 implements EventPayload
{
    public function __construct(
        public EntryId $entry,
        public VariantRef $variant,
        public int $revision,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('entry', EventDatum::identifier($this->entry))
            ->with('variant', EventDatum::identifier($this->variant))
            ->with('revision', EventDatum::integer($this->revision));
    }
}
