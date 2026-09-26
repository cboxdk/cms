<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\CoreFieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
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
        return CoreFieldType::Integer->value;
    }
}
