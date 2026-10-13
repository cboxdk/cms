<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The condition of a conditional field (gap G3): the field is shown while it holds and hidden
 * while it does not. It is typed, without expressions: `eq`, `neq` and `in` compare one field's
 * value with text, whole numbers or booleans, `null` and `not_null` ask whether a field has a value,
 * and `all` and `any` combine conditions.
 *
 * A condition reads the values as the input holds them, the kept value of a hidden field
 * included (WhenHidden::Keep). A field without a value is neither equal to a value nor one of
 * several, so `neq` holds for it.
 */
#[Experimental]
final readonly class Condition
{
    /** The most conditions one `all` or `any` combines, and the most values of one `in`. */
    public const int MAX_ITEMS = 100;

    /**
     * @param  ?FieldReference  $field  the field compared, for every operator but all and any
     * @param  list<string|int|bool>  $values  one value for eq and neq, one or more for in, none otherwise
     * @param  list<Condition>  $conditions  one or more for all and any, none otherwise
     *
     * @throws InvalidRules when the arguments do not fit the operator
     */
    public function __construct(
        public ConditionOperator $operator,
        public ?FieldReference $field = null,
        public array $values = [],
        public array $conditions = [],
    ) {
        $count = count($this->values);
        $valid = $operator->comparesField()
            ? $field instanceof FieldReference && $conditions === [] && match ($operator) {
                ConditionOperator::Eq, ConditionOperator::Neq => $count === 1,
                ConditionOperator::In => $count >= 1 && $count <= self::MAX_ITEMS,
                default => $count === 0,
            }
        : ! $field instanceof FieldReference && $values === [] && $conditions !== [] && count($conditions) <= self::MAX_ITEMS;

        if (! $valid) {
            throw InvalidRules::condition($operator);
        }
    }

    public static function eq(FieldReference $field, string|int|bool $value): self
    {
        return new self(ConditionOperator::Eq, $field, [$value]);
    }

    public static function neq(FieldReference $field, string|int|bool $value): self
    {
        return new self(ConditionOperator::Neq, $field, [$value]);
    }

    /**
     * @param  list<string|int|bool>  $values
     */
    public static function in(FieldReference $field, array $values): self
    {
        return new self(ConditionOperator::In, $field, $values);
    }

    public static function isNull(FieldReference $field): self
    {
        return new self(ConditionOperator::Null, $field);
    }

    public static function notNull(FieldReference $field): self
    {
        return new self(ConditionOperator::NotNull, $field);
    }

    /**
     * @param  list<Condition>  $conditions
     */
    public static function all(array $conditions): self
    {
        return new self(ConditionOperator::All, conditions: $conditions);
    }

    /**
     * @param  list<Condition>  $conditions
     */
    public static function any(array $conditions): self
    {
        return new self(ConditionOperator::Any, conditions: $conditions);
    }

    /**
     * Whether the condition holds for the values.
     */
    public function holds(ConditionValues $values): bool
    {
        $field = $this->field;

        if (! $field instanceof FieldReference) {
            return $this->operator === ConditionOperator::All
                ? array_all($this->conditions, static fn (self $condition): bool => $condition->holds($values))
                : array_any($this->conditions, static fn (self $condition): bool => $condition->holds($values));
        }

        return match ($this->operator) {
            ConditionOperator::Null => $values->isNull($field),
            ConditionOperator::NotNull => ! $values->isNull($field),
            ConditionOperator::Neq => ! $values->is($field, $this->values[0]),
            default => array_any($this->values, static fn (string|int|bool $value): bool => $values->is($field, $value)),
        };
    }

    /**
     * Every field the condition reads, in the order it names them.
     *
     * @return list<FieldReference>
     */
    public function references(): array
    {
        if ($this->field instanceof FieldReference) {
            return [$this->field];
        }

        $references = [];

        foreach ($this->conditions as $condition) {
            array_push($references, ...$condition->references());
        }

        return $references;
    }
}
