<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * An entry as a write reads it (PRD 5.4): its id, its type, its home node and its version, and the
 * head of the variant the write asked for, or null when the entry has no such variant.
 */
#[Internal]
final readonly class StoredEntry
{
    public function __construct(
        public EntryId $id,
        public TypeId $type,
        public NodeId $home,
        public AggregateVersion $version,
        public ?StoredHead $head,
    ) {}
}
