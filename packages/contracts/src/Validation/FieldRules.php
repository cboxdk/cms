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
 *
 * A conditional field (gap G3) has a condition, `when`, and says what happens to its value while
 * the condition does not hold, `whenHidden`; a field has both or neither. A hidden field is never
 * required, whatever its presence, and holds no value when it is WhenHidden::Clear. The condition
 * reads siblings of the field and siblings of the groups it is in (FieldReference), which the
 * TypeRules and ExtensionRules that hold the field check it names (assertReferences()).
 */
#[Experimental]
final readonly class FieldRules
{
    public RuleName $type;

    /**
     * @param  list<Rule>  $rules  the type rule first
     * @param  list<FieldRules>  $fields  the nested fields of a group
     * @param  bool  $translatable  whether a language's variant holds the field, in a type with localization variants
     * @param  ?Condition  $when  the condition the field is shown on, or null for a field that is always shown
     * @param  ?WhenHidden  $whenHidden  what happens to the value of a hidden field, for a conditional field
     *
     * @throws InvalidRules
     */
    public function __construct(
        public FieldHandle $handle,
        public Presence $presence,
        public array $rules,
        public array $fields = [],
        public bool $translatable = false,
        public ?Condition $when = null,
        public ?WhenHidden $whenHidden = null,
    ) {
        if (($when instanceof Condition) !== ($whenHidden instanceof WhenHidden)) {
            throw InvalidRules::whenHidden($handle);
        }

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
     * Whether the field is shown for the values: it has no condition, or its condition holds.
     */
    public function shownFor(ConditionValues $values): bool
    {
        return ! $this->when instanceof Condition || $this->when->holds($values);
    }

    /**
     * Checks that the condition of every field, nested fields included, names a field it can
     * reach: a sibling, or a sibling of a group the field is in, within the fields given.
     *
     * @param  list<FieldRules>  $fields  the fields of one object
     * @param  list<list<FieldRules>>  $ancestors  the fields of each object the object is in, the outermost first
     *
     * @throws InvalidRules
     */
    public static function assertReferences(array $fields, array $ancestors = []): void
    {
        $scopes = [...$ancestors, $fields];

        foreach ($fields as $field) {
            foreach ($field->when?->references() ?? [] as $reference) {
                $scope = $scopes[count($scopes) - 1 - $reference->up] ?? null;

                if ($scope === null || ! array_any($scope, static fn (FieldRules $sibling): bool => $sibling->handle->equals($reference->handle))) {
                    throw InvalidRules::unknownReference($field->handle, $reference);
                }
            }

            if ($field->fields !== []) {
                self::assertReferences($field->fields, $scopes);
            }
        }
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
