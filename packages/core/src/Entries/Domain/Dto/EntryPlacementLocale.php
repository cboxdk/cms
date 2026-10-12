<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Core\Placements\Domain\Visibility;

/**
 * A placement of an entry in one locale as entry.get gives it (PRD 5.7): its slug, its stored
 * visibility state, its window and whether it is the entry's canonical placement in the locale.
 */
#[Experimental]
final readonly class EntryPlacementLocale
{
    public function __construct(
        public Locale $locale,
        public Slug $slug,
        public Visibility $visibility,
        public ?TimeWindow $window,
        public bool $canonical,
    ) {}
}
