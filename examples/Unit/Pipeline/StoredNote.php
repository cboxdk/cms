<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * A note as the shelf holds it: its id, the version of its shared variant and the revision its
 * head points at.
 */
final readonly class StoredNote
{
    public function __construct(
        public EntryId $note,
        public AggregateVersion $version,
        public RevisionNumber $head,
    ) {}
}
