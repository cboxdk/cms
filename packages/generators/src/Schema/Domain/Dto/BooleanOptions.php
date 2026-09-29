<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnShape;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\PhpType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeScriptType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValueShape;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\BooleanFieldType;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * A `boolean` field. It has no choices of its own.
 */
#[Internal]
final readonly class BooleanOptions implements FieldOptions
{
    #[Override]
    public function typeName(): string
    {
        return BooleanFieldType::NAME;
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
        return new ColumnShape('boolean');
    }

    #[Override]
    public function describeValue(array $fields): ValueShape
    {
        return new ValueShape(new PhpType('bool', 'bool'), new TypeScriptType('boolean'), [new ValidationRule(ValidationRuleName::Boolean)]);
    }
}
