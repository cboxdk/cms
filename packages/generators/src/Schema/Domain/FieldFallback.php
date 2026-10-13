<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The `fallback` of a translatable field (PRD 10.3): along the site's chain per variant, the
 * default, or per field.
 */
#[Internal]
enum FieldFallback: string
{
    case Variant = 'variant';

    case Field = 'field';
}
