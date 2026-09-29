<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A revision of one variant of an entry is created: an immutable snapshot of the variant's
 * fields (PRD 5.4). It does not move the variant's head; HeadMoved does.
 */
#[Experimental]
final readonly class RevisionCreated implements Mutation
{
    public function __construct(
        public EntryId $entry,
        public VariantKey $variant,
        public RevisionNumber $revision,
        public FieldValues $fields,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return new VariantRef($this->entry, $this->variant);
    }
}
