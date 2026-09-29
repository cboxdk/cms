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
use Cbox\Cms\Generators\Descriptor\Domain\SqlText;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDate;
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
        public ?BlueprintDate $min,
        public ?BlueprintDate $max,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return DateFieldType::NAME;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        if (! $this->min instanceof BlueprintDate || ! $this->max instanceof BlueprintDate) {
            return [];
        }

        return OptionRules::range(Bounds::compareDates($this->min, $this->max), $this->min->value, $this->max->value, $field);
    }

    #[Override]
    public function nestedFields(): array
    {
        return [];
    }

    #[Override]
    public function describeColumn(string $column): ColumnShape
    {
        return new ColumnShape('date', ShapeParts::boundChecks($column, $this->sql($this->min), $this->sql($this->max)));
    }

    /**
     * A DateTimeImmutable at midnight UTC in PHP, and the RFC 3339 full-date in TypeScript.
     */
    #[Override]
    public function describeValue(array $fields): ValueShape
    {
        return new ValueShape(
            new PhpType('DateTimeImmutable', 'DateTimeImmutable'),
            new TypeScriptType('string'),
            [new ValidationRule(ValidationRuleName::Date), ...ShapeParts::boundRules($this->min?->value, $this->max?->value)],
        );
    }

    private function sql(?BlueprintDate $bound): ?string
    {
        return $bound instanceof BlueprintDate ? SqlText::literal($bound->value).'::date' : null;
    }
}
