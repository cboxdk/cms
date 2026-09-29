<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema\Fakes;

use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnShape;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\PhpType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeScriptType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValueShape;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * The options of a field of ColourFieldType. It has no rules that compare its values. A colour is
 * stored as text of the form `#rrggbb`.
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

    #[Override]
    public function describeColumn(string $column): ColumnShape
    {
        return new ColumnShape('text', [$column." ~ '^#[0-9a-f]{6}$'"]);
    }

    #[Override]
    public function describeValue(array $fields): ValueShape
    {
        return new ValueShape(new PhpType('string', 'string'), new TypeScriptType('string'), [new ValidationRule(ValidationRuleName::String)]);
    }
}
