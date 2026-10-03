<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;

/**
 * Every placement of an entry at the version it was read at, and the first placement that is
 * visible now or later, in a locale, at the Clock's time, or null when none is: a placement that is
 * live or scheduled shows the entry to the public, a hidden, withdrawn or expired one does not.
 */
#[Internal]
final readonly class EntryPlacements
{
    public function __construct(
        public ReadVersions $reads,
        public ?PlacementId $shown,
    ) {}
}
