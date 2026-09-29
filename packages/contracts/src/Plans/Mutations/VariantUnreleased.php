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
 * One variant of an entry has no released revision any more (PRD 5.4, 6.4). $revision is the
 * revision that was released until now.
 */
#[Experimental]
final readonly class VariantUnreleased implements Mutation
{
    public function __construct(
        public EntryId $entry,
        public VariantKey $variant,
        public RevisionNumber $revision,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return new VariantRef($this->entry, $this->variant);
    }
}
