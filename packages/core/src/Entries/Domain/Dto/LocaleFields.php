<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;

/**
 * The fields of one language's variant of an entry (PRD 10.1), as entry.create version 2 takes
 * them: the locale and the fields the language's variant holds, the type's translatable fields.
 */
#[Experimental]
final readonly class LocaleFields
{
    public function __construct(
        public Locale $locale,
        public FieldValues $fields,
    ) {}

    public function variant(): VariantKey
    {
        return VariantKey::of($this->locale);
    }
}
