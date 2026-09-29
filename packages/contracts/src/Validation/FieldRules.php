<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use DateTimeImmutable;

/**
 * The runtime validator's rules for one field (PRD 11.6, 11.8, 11.12): its handle, whether it
 * needs a value, its rules and, for a group, its nested fields.
 *
 * The rules start with one type rule, followed by rules the type rule allows, each at most once
 * (RuleName::modifiers()). The bounds of `min` and `max` are values of the type rule's kind: whole
 * numbers for `integer`, decimal numbers for `decimal`, dates for `date` and date-times of RFC 3339
 * for `datetime`. An `object` has nested fields; a `list` has nested fields, a repeated group whose
 * items are each an object of them, or `items_in`, the options of its items; no other field has
 * nested fields. The nested fields have different handles.
 */
#[Experimental]
final readonly class FieldRules
{
    public RuleName $type;

    /**
     * @param  list<Rule>  $rules  the type rule first
     * @param  list<FieldRules>  $fields  the nested fields of a group
     *
     * @throws InvalidRules
     */
    public function __construct(
        public FieldHandle $handle,
        public Presence $presence,
        public array $rules,
        public array $fields = [],
    ) {
        $type = ($rules[0] ?? null)?->name;

        if ($type === null || ! $type->isType()) {
            throw InvalidRules::noTypeRule($handle);
        }

        $seen = [];

        foreach (array_slice($rules, 1) as $rule) {
            if ($rule->name->isType()) {
                throw InvalidRules::noTypeRule($handle);
            }

            if (! in_array($rule->name, $type->modifiers(), true) || isset($seen[$rule->name->value])) {
                throw InvalidRules::notAllowed($handle, $type, $rule->name);
            }

            $seen[$rule->name->value] = true;

            if ($rule->name === RuleName::Min || $rule->name === RuleName::Max) {
                $this->bound($type, $rule->arguments[0] ?? '');
            }
        }

        $hasItemsIn = isset($seen[RuleName::ItemsIn->value]);
        $nested = match ($type) {
            RuleName::Object => $fields !== [],
            RuleName::List => ($fields !== []) !== $hasItemsIn,
            default => $fields === [],
        };

        if (! $nested || ($fields !== [] && isset($seen[RuleName::Distinct->value]))) {
            throw InvalidRules::nestedFields($handle, $type);
        }

        self::assertDistinct($fields);
        $this->type = $type;
    }

    /**
     * The rule with the name among the rules after the type rule, or null.
     */
    public function rule(RuleName $name): ?Rule
    {
        foreach ($this->rules as $rule) {
            if ($rule->name === $name) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param  list<FieldRules>  $fields
     *
     * @throws InvalidRules
     */
    public static function assertDistinct(array $fields): void
    {
        $handles = [];

        foreach ($fields as $field) {
            if (isset($handles[$field->handle->value])) {
                throw InvalidRules::repeatedField($field->handle);
            }

            $handles[$field->handle->value] = true;
        }
    }

    private function bound(RuleName $type, string $bound): void
    {
        $valid = match ($type) {
            RuleName::Integer => RuleValues::integer($bound) !== null,
            RuleName::Decimal => DecimalNumber::parse($bound) instanceof DecimalNumber,
            RuleName::Date => RuleValues::date($bound),
            default => RuleValues::datetime($bound) instanceof DateTimeImmutable,
        };

        if (! $valid) {
            throw InvalidRules::bound($this->handle, $type, $bound);
        }
    }
}
