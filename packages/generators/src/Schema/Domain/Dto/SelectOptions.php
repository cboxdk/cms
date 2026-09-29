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
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\SelectFieldType;
use Cbox\Cms\Generators\Schema\Domain\OptionRules;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * A `select` field: one choice from a fixed list, or several when `multiple` is true (default
 * false). Only a multiple select has `min_items` and `max_items`.
 */
#[Internal]
final readonly class SelectOptions implements FieldOptions
{
    public const bool DEFAULT_MULTIPLE = false;

    /**
     * @param  list<SelectOption>  $options  in the order of the file
     */
    public function __construct(
        public array $options,
        public bool $multiple,
        public ?int $minItems,
        public ?int $maxItems,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return SelectFieldType::NAME;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        $problems = [];
        $values = [];

        foreach ($this->options as $index => $option) {
            $value = $option->value->value;

            if (array_key_exists($value, $values)) {
                $problems[] = OptionRules::problem(GenerateErrorCode::DuplicateSelectValue, $field->below('options', $index, 'value'), sprintf(
                    'the value %s is already the value of the option at %s. The options of a select field need different values.',
                    $value,
                    $field->below('options', $values[$value], 'value')->describe(),
                ));
            } else {
                $values[$value] = $index;
            }
        }

        return [...$problems, ...OptionRules::items($this->minItems, $this->maxItems, $field)];
    }

    #[Override]
    public function nestedFields(): array
    {
        return [];
    }

    /**
     * One value as `text` that is one of the options, or several as `text[]` whose items are.
     */
    #[Override]
    public function describeColumn(string $column): ColumnShape
    {
        if (! $this->multiple) {
            return new ColumnShape('text', [sprintf('%s IN (%s)', $column, SqlText::literals($this->values()))]);
        }

        return new ColumnShape('text[]', [
            sprintf('%s <@ ARRAY[%s]::text[]', $column, SqlText::literals($this->values())),
            ...ShapeParts::boundChecks(
                'cardinality('.$column.')',
                $this->minItems === null ? null : (string) $this->minItems,
                $this->maxItems === null ? null : (string) $this->maxItems,
            ),
        ]);
    }

    /**
     * The union of the values as literal types, or a list of them for a multiple select.
     */
    #[Override]
    public function describeValue(array $fields): ValueShape
    {
        $literals = array_map(static fn (string $value): string => "'".$value."'", $this->values());
        $php = implode('|', $literals);
        $typeScript = implode(' | ', $literals);

        if (! $this->multiple) {
            return new ValueShape(
                new PhpType('string', $php),
                new TypeScriptType($typeScript),
                [new ValidationRule(ValidationRuleName::In, $this->values())],
                $this->options,
            );
        }

        return new ValueShape(
            new PhpType('array', 'list<'.$php.'>'),
            new TypeScriptType('Array<'.$typeScript.'>'),
            [
                new ValidationRule(ValidationRuleName::List),
                new ValidationRule(ValidationRuleName::Distinct),
                new ValidationRule(ValidationRuleName::ItemsIn, $this->values()),
                ...ShapeParts::itemRules($this->minItems, $this->maxItems),
            ],
            $this->options,
        );
    }

    /**
     * The values of the options, in the order of the file.
     *
     * @return list<string>
     */
    private function values(): array
    {
        return array_map(static fn (SelectOption $option): string => $option->value->value, $this->options);
    }
}
