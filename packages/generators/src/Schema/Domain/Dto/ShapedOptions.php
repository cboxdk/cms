<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnShape;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValueShape;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * The options of a field of an addon's field type (PRD 13.1, 11.12): the field type's name, such as
 * `reviews:stars`, and the options of the core field type its shape takes the form of, its base
 * (FieldTypeContribution::shape()). The field is checked, stored, typed and validated as its base,
 * and keeps its own name.
 */
#[Internal]
final readonly class ShapedOptions implements FieldOptions
{
    public function __construct(
        private string $name,
        public FieldOptions $base,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return $this->name;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        return $this->base->problems($field->below('options'));
    }

    #[Override]
    public function nestedFields(): array
    {
        return [];
    }

    #[Override]
    public function describeColumn(string $column): ColumnShape
    {
        return $this->base->describeColumn($column);
    }

    #[Override]
    public function describeValue(array $fields): ValueShape
    {
        return $this->base->describeValue([]);
    }
}
