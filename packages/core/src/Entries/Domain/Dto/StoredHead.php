<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * The head of one variant of an entry as a write reads it (PRD 5.4, 5.6): the variant's version,
 * the number of its current draft revision, the highest number any of its revisions has, which the
 * next revision follows, and the number of its released revision, or null when none is released.
 *
 * A release writes a published revision of its own after the revision it releases, so the highest
 * number can be above the draft's.
 */
#[Internal]
final readonly class StoredHead
{
    public function __construct(
        public AggregateVersion $version,
        public RevisionNumber $revision,
        public RevisionNumber $latest,
        public ?RevisionNumber $released,
    ) {}
}
