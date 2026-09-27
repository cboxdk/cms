<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Bounds;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\DatetimeFieldType;
use Cbox\Cms\Generators\Schema\Domain\OptionRules;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * A `datetime` field, a time in UTC, with optional bounds as RFC 3339 times such as
 * "2026-01-01T00:00:00Z", kept as written.
 */
#[Internal]
final readonly class DatetimeOptions implements FieldOptions
{
    public function __construct(
        public ?string $min,
        public ?string $max,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return DatetimeFieldType::NAME;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        if ($this->min === null || $this->max === null) {
            return [];
        }

        return OptionRules::range(Bounds::compareDatetimes($this->min, $this->max), $this->min, $this->max, $field);
    }

    #[Override]
    public function nestedFields(): array
    {
        return [];
    }
}
