<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
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
}
