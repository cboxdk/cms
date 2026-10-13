<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;

/**
 * Previews one revision of an entry as the delivery API would send it (PRD 5.4, 8.9, 13.4),
 * version 1 of entry.preview: the draft of the variant, the shared variant when it is null, or the revision with the number given, written
 * as its type's record through the record codecs at the public classification access. Every actor
 * may run it (ActorQuery), because it reads no more than the actor's own context reaches: an entry
 * whose home the actor does not reach and does not own reads as absent. It writes nothing and never
 * touches the fragment store.
 */
#[QueryName('entry.preview', version: 1)]
#[Experimental]
final readonly class PreviewEntry implements ActorQuery
{
    public function __construct(
        public EntryId $entry,
        public ?VariantKey $variant = null,
        public ?RevisionNumber $revision = null,
    ) {}
}
