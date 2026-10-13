<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A revision of a language's variant of an entry is a translation of a revision of another variant
 * of the entry (PRD 9.2, 10.3): the derivation translated_from ($source, $sourceRevision) of the
 * revision $revision of $variant. The kernel records it, and a later release of the source with
 * other content marks the translation stale.
 */
#[Experimental]
final readonly class VariantDerived implements Mutation
{
    public function __construct(
        public EntryId $entry,
        public VariantKey $variant,
        public RevisionNumber $revision,
        public VariantKey $source,
        public RevisionNumber $sourceRevision,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return new VariantRef($this->entry, $this->variant);
    }
}
