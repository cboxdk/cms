<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Override;

/**
 * Version 1 of the payload of entry.created: the entry, its type and its home node.
 */
#[Experimental]
final readonly class EntryCreatedV1 implements EventPayload
{
    public function __construct(
        public EntryId $entry,
        public TypeId $type,
        public NodeId $home,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('entry', EventDatum::identifier($this->entry))
            ->with('type', EventDatum::identifier($this->type))
            ->with('home', EventDatum::identifier($this->home));
    }
}
