<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * An entry is created: its identity, its type and its home node, which owns its content
 * (PRD 5.4). The entry has no variant or revision yet; RevisionCreated makes those.
 */
#[Experimental]
final readonly class EntryCreated implements Mutation
{
    public function __construct(
        public EntryId $entry,
        public TypeId $type,
        public NodeId $home,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->entry;
    }
}
