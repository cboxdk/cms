<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A reference to one aggregate: the unit a command reads, checks the version of and changes
 * (PRD 5.2, 6.2). The typed ids of the aggregates implement it, such as EntryId and ActorId, and
 * so do references made of several values, such as VariantRef for one variant of an entry.
 */
#[Experimental]
interface AggregateRef
{
    /**
     * The aggregate's key: its kind, a colon and its id, such as "entry:<uuid>", in one canonical
     * form. Two references to the same aggregate have the same key, and references to different
     * aggregates, of any kind, never do.
     */
    public function aggregateKey(): string;
}
