<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How a type is localized (PRD 10.1), the capability `localization` of its blueprint: `none`, the
 * one shared variant holds every field; `variants`, every language is a variant of the entry with
 * its own revisions and release, and the fields that do not vary by language live in the shared
 * variant (FieldDefinition::$translatable).
 */
#[Experimental]
enum Localization: string
{
    case None = 'none';

    case Variants = 'variants';
}
