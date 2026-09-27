<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Bounds;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\DateFieldType;
use Cbox\Cms\Generators\Schema\Domain\OptionRules;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * A `date` field, with optional bounds as full dates such as "2026-01-01" (RFC 3339 full-date).
 */
#[Internal]
final readonly class DateOptions implements FieldOptions
{
    public function __construct(
        public ?string $min,
        public ?string $max,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return DateFieldType::NAME;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        if ($this->min === null || $this->max === null) {
            return [];
        }

        return OptionRules::range(Bounds::compareDates($this->min, $this->max), $this->min, $this->max, $field);
    }

    #[Override]
    public function nestedFields(): array
    {
        return [];
    }
}
