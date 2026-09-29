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
 * A revision of one variant of an entry becomes its released revision, the one readers see
 * where a placement makes it visible (PRD 5.4, 5.7, 6.4).
 */
#[Experimental]
final readonly class VariantReleased implements Mutation
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
