<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\IntegerFieldType;
use Cbox\Cms\Generators\Schema\Domain\OptionRules;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * An `integer` field, with optional bounds and a unit for display.
 */
#[Internal]
final readonly class IntegerOptions implements FieldOptions
{
    public function __construct(
        public ?int $min,
        public ?int $max,
        public ?string $unit,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return IntegerFieldType::NAME;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        if ($this->min === null || $this->max === null) {
            return [];
        }

        return OptionRules::range($this->min <=> $this->max, (string) $this->min, (string) $this->max, $field);
    }

    #[Override]
    public function nestedFields(): array
    {
        return [];
    }
}
