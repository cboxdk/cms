<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\CoreFieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
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
        return CoreFieldType::Date->value;
    }
}
