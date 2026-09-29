<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * The head of one variant of an entry, its current draft revision, moves from one revision to
 * another (PRD 5.4). $from is null when the variant had no head, for its first revision. The
 * head always moves: $from and $to differ.
 */
#[Experimental]
final readonly class HeadMoved implements Mutation
{
    public function __construct(
        public EntryId $entry,
        public VariantKey $variant,
        public ?RevisionNumber $from,
        public RevisionNumber $to,
    ) {
        if ($from instanceof RevisionNumber && $from->equals($to)) {
            throw InvalidMutation::headDidNotMove($to);
        }
    }

    #[Override]
    public function aggregate(): AggregateRef
    {
        return new VariantRef($this->entry, $this->variant);
    }
}
