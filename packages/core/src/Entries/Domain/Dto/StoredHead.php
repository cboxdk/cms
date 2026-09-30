<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * The head of one variant of an entry as a write reads it (PRD 5.4): the variant's version and the
 * number of its current draft revision, the one the next revise follows.
 */
#[Internal]
final readonly class StoredHead
{
    public function __construct(
        public AggregateVersion $version,
        public RevisionNumber $revision,
    ) {}
}
