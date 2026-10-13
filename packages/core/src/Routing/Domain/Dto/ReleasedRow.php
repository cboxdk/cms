<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;

/**
 * One released row of an entry in its type's table (PRD 4.1, 11.6), as path.resolve reads it: the
 * variant whose row it is, the shared variant for a type without localization and a language for a
 * type with localization variants, whose row holds the shared fields too, and its fields.
 */
#[Internal]
final readonly class ReleasedRow
{
    public function __construct(
        public VariantKey $variant,
        public FieldValues $fields,
    ) {}
}
