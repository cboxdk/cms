<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Entries\Domain\Dto\PreviewSource;

/**
 * What entry.preview reads (PRD 5.4, 5.10), in the read transaction under its actor context: one
 * variant head of an entry whose home the context's regions reach or that its actor owns, and the
 * fields of its draft or of the revision asked for, read as the type is now. It is one statement,
 * whatever the number of revisions, placements or other variants the entry has (GUARDRAILS 4.1),
 * writes nothing and takes no lock.
 */
#[Internal]
interface EntryPreviews
{
    /**
     * The head and the revision, or null when the entry is absent, its home is out of reach or the
     * entry has no head of the variant. A null revision asks for the head's draft.
     */
    public function find(EntryId $entry, VariantKey $variant, ?RevisionNumber $revision): ?PreviewSource;
}
