<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Core\Placements\Domain\Visibility;

/**
 * A placement in one locale, its PlacementLocale's released stage (PRD 5.7): its slug, its
 * visibility state, its window and whether it is canonical.
 */
#[Internal]
final readonly class StoredPlacementLocale
{
    public function __construct(
        public Locale $locale,
        public Slug $slug,
        public Visibility $visibility,
        public ?TimeWindow $window,
        public bool $canonical,
    ) {}
}
