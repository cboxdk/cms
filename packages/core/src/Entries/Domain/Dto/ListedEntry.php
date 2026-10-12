<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use DateTimeImmutable;

/**
 * An entry as entry.list gives it (PRD 5.4): its system columns, which every type shares. The
 * release state is the shared variant's, or null for an entry without a shared head; the last
 * change is the newest of the entry's creation, its revisions, its head snapshots and its
 * releases and withdrawals, in UTC.
 */
#[Experimental]
final readonly class ListedEntry
{
    public function __construct(
        public EntryId $id,
        public TypeId $type,
        public NodeId $home,
        public EntryLifecycle $lifecycle,
        public ?ReleaseState $state,
        public DateTimeImmutable $changedAt,
        public AggregateVersion $version,
    ) {}
}
