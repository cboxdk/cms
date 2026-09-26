<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\CoreFieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Override;

/**
 * A `decimal` field: a number without rounding, `numeric(precision, scale)` (PRD 11.6). The bounds
 * are decimal strings such as "-12.50", so they are never read as floats.
 */
#[Internal]
final readonly class DecimalOptions implements FieldOptions
{
    public function __construct(
        public int $precision,
        public int $scale,
        public ?string $min,
        public ?string $max,
        public ?string $unit,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return CoreFieldType::Decimal->value;
    }
}
