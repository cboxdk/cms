<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;

/**
 * The derivation translated_from of a language's variant (PRD 9.2, 10.3), as entry.get gives it:
 * the variant of the same entry and the number of its revision the variant was translated from.
 */
#[Experimental]
final readonly class TranslatedFrom
{
    public function __construct(
        public VariantKey $variant,
        public RevisionNumber $revision,
    ) {}
}
