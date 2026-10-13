<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How a translatable field falls back when the reader's language has no value for it (PRD 10.3),
 * the key `fallback` of a translatable field in a type with `localization: variants`.
 */
#[Experimental]
enum FieldFallback: string
{
    /** The site's fallback chain applies to the whole variant: the default, so a page never mixes languages. */
    case Variant = 'variant';

    /** The field alone falls back along the site's chain when the variant has no value for it. */
    case Field = 'field';
}
