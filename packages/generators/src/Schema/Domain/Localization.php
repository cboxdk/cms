<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How a type is localized (PRD 10.1): `none`, one shared variant with every field, or `variants`,
 * a variant per language and the fields that do not vary by language in the shared variant.
 */
#[Internal]
enum Localization: string
{
    case None = 'none';

    case Variants = 'variants';
}
