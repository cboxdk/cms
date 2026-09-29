<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnShape;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\PhpType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeScriptType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValueShape;
use Cbox\Cms\Generators\Descriptor\Domain\ShapeParts;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
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

    /**
     * `bigint`, because a PHP int has 64 bits and bounds can change without a new column type.
     */
    #[Override]
    public function describeColumn(string $column): ColumnShape
    {
        return new ColumnShape('bigint', ShapeParts::boundChecks($column, $this->bound($this->min), $this->bound($this->max)));
    }

    #[Override]
    public function describeValue(array $fields): ValueShape
    {
        $doc = $this->min === null && $this->max === null
            ? 'int'
            : sprintf('int<%s, %s>', $this->bound($this->min) ?? 'min', $this->bound($this->max) ?? 'max');

        return new ValueShape(
            new PhpType('int', $doc),
            new TypeScriptType('number'),
            [new ValidationRule(ValidationRuleName::Integer), ...ShapeParts::boundRules($this->bound($this->min), $this->bound($this->max))],
        );
    }

    private function bound(?int $bound): ?string
    {
        return $bound === null ? null : (string) $bound;
    }
}
