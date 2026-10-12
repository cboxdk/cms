<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Content;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * Reads the head of one variant of an entry (PRD 5.4, 5.6, 13.4): what a write action of an
 * addon reads in its resolve() before it plans a revision of the variant, and what the kernel's
 * own entry.get reads for every variant, so an addon needs no kernel-internal reader.
 *
 * head() runs on the connection of the caller's open transaction, the command transaction of a
 * write action's resolve() or the read transaction of a query action's handle(), under its actor
 * context, so row level security decides what it sees: an entry the actor's regions do not reach
 * reads as absent (PRD 5.10). It is a fixed number of lookups by key, whatever else the entry
 * holds (GUARDRAILS 4.1), writes nothing and takes no lock; the commit of a command locks what
 * was read and checks its version.
 *
 * It gives null when the entry is absent, when the entry has no head of the variant, and when
 * the entry's type is not in the TypeCatalog, whose field definitions the fields are read with.
 * A head whose draft revision was written under another schema version than the type's has null
 * fields, because the type cannot read them.
 */
#[Experimental]
interface VariantHeadReader
{
    public function head(EntryId $entry, VariantKey $variant): ?VariantHead;
}
