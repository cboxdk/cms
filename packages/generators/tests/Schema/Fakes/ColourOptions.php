<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema\Fakes;

use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * The options of a field of ColourFieldType. It has no rules that compare its values.
 */
final readonly class ColourOptions implements FieldOptions
{
    public const bool DEFAULT_ALLOW_CUSTOM = false;

    public function __construct(
        public ?string $palette,
        public bool $allowCustom,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return ColourFieldType::NAME;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        return [];
    }

    #[Override]
    public function nestedFields(): array
    {
        return [];
    }
}
