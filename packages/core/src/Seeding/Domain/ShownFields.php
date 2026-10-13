<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Validation\FieldRules;

/**
 * The generated values of the fields that are shown (gap G3): a conditional field whose condition
 * does not hold for the values keeps none, so a hidden field never holds a value the kernel would
 * refuse, and a shown field keeps the value generated for it, which every required field has.
 *
 * A condition reads siblings and the siblings of the groups a field is in, never the fields inside
 * it, so the fields of one object settle before those nested in them. Within an object the
 * conditions read each other without a cycle, which the generators refuse, so leaving out the
 * values of hidden fields until nothing changes settles in at most as many rounds as the object has
 * fields.
 */
#[Internal]
final readonly class ShownFields
{
    /**
     * @param  list<FieldRules>  $rules  the fields of the object
     * @param  list<FieldMap>  $ancestors  the fields of each object the object is in, the outermost first
     */
    public static function of(array $rules, FieldMap $generated, array $ancestors = []): FieldMap
    {
        $shown = $generated;
        $counter = count($rules);

        for ($round = 0; $round <= $counter; $round++) {
            $named = [];

            foreach ($rules as $field) {
                $value = $generated->get($field->handle);

                if ($value instanceof FieldValue && $field->shownFor(new FieldMapConditionValues([...$ancestors, $shown]))) {
                    $named[] = new NamedValue($field->handle, $value);
                }
            }

            $next = new FieldMap(...$named);

            if ($next->equals($shown)) {
                break;
            }

            $shown = $next;
        }

        return self::nested($rules, $shown, [...$ancestors, $shown]);
    }

    /**
     * The values of the groups among the fields, with their own fields settled.
     *
     * @param  list<FieldRules>  $rules
     * @param  list<FieldMap>  $scopes
     */
    private static function nested(array $rules, FieldMap $fields, array $scopes): FieldMap
    {
        $named = [];

        foreach ($fields->fields as $field) {
            $rule = array_find($rules, static fn (FieldRules $candidate): bool => $candidate->handle->equals($field->handle));
            $value = $field->value;

            if ($rule instanceof FieldRules && $rule->fields !== []) {
                $value = match (true) {
                    $value instanceof GroupValue => new GroupValue(self::of($rule->fields, $value->fields, $scopes)),
                    $value instanceof ListValue => new ListValue(...array_map(
                        static fn (FieldValue $item): FieldValue => $item instanceof GroupValue ? new GroupValue(self::of($rule->fields, $item->fields, $scopes)) : $item,
                        $value->items,
                    )),
                    default => $value,
                };
            }

            $named[] = new NamedValue($field->handle, $value);
        }

        return new FieldMap(...$named);
    }
}
