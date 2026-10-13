<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A language's variant of an entry is deleted (PRD 10.3): its head is withdrawn, so it has no
 * released revision and only a reinstatement could release it again (invariant 7), and its rows
 * in the type's table are removed. The entry and its other variants stay, and so do the variant's
 * revisions, which are history. It hides content and makes nothing public.
 */
#[Experimental]
final readonly class VariantWithdrawn implements Mutation
{
    public function __construct(
        public EntryId $entry,
        public VariantKey $variant,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return new VariantRef($this->entry, $this->variant);
    }
}
