<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Validation\ConditionOperator;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;

/**
 * A field's `when` as the blueprint writes it (gap G3): a field compared with `eq`, `neq` or `in`,
 * asked for a value with `null` or `not_null`, or `all` or `any` of other conditions. $location is
 * the place of the condition in its file, for the problems ConditionRules reports.
 */
#[Internal]
final readonly class FieldCondition
{
    /**
     * @param  list<string|int|bool>  $values  one value for eq and neq, one or more for in, none otherwise
     * @param  list<FieldCondition>  $conditions  the conditions of all and any, none otherwise
     */
    public function __construct(
        public ConditionOperator $operator,
        public ?ConditionReference $field,
        public array $values,
        public array $conditions,
        public SourceLocation $location,
    ) {}

    /**
     * The comparisons of the condition, itself or those all and any combine, depth first.
     *
     * @return list<FieldCondition>
     */
    public function comparisons(): array
    {
        if ($this->field instanceof ConditionReference) {
            return [$this];
        }

        $comparisons = [];

        foreach ($this->conditions as $condition) {
            array_push($comparisons, ...$condition->comparisons());
        }

        return $comparisons;
    }
}
