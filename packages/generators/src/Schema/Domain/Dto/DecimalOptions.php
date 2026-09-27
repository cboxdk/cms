<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Schema\Domain\Bounds;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\DecimalFieldType;
use Cbox\Cms\Generators\Schema\Domain\OptionRules;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
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
        return DecimalFieldType::NAME;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        $problems = [];

        if ($this->scale > $this->precision) {
            $problems[] = OptionRules::problem(GenerateErrorCode::ScaleAbovePrecision, $field->below('scale'), sprintf(
                'scale %d is greater than precision %d. The precision is the number of digits and the scale the number of them after the decimal point, so make the scale at most the precision.',
                $this->scale,
                $this->precision,
            ));
        }

        if ($this->min !== null && $this->max !== null) {
            array_push($problems, ...OptionRules::range(Bounds::compareDecimals($this->min, $this->max), $this->min, $this->max, $field));
        }

        return $problems;
    }

    #[Override]
    public function nestedFields(): array
    {
        return [];
    }
}
