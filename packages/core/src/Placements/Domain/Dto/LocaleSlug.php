<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;

/**
 * The slug a placement has in one locale (PRD 5.7).
 */
#[Experimental]
final readonly class LocaleSlug
{
    public function __construct(
        public Locale $locale,
        public Slug $slug,
    ) {}
}
