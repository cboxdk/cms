<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Validation\TypeRules;
use LogicException;
use stdClass;

/**
 * The fields of a revision in the form the input validator reads, as JSON decodes it to objects
 * (PRD 11.12): an object of the owner's fields by handle, with the extension fields under `ext`,
 * an object of namespaces, each an object of that extender's fields.
 *
 * A text is a string, an integer an int, a decimal its canonical string, a boolean a bool, a date
 * YYYY-MM-DD and a date-time RFC 3339 in UTC with microseconds; a list is a list, and a group and a
 * map are objects, so an empty group is never read as a list.
 */
#[Internal]
final readonly class FieldValuesInput
{
    public static function of(FieldValues $fields): stdClass
    {
        $input = self::map($fields->own);

        if ($fields->extensions !== []) {
            $extensions = new stdClass;

            foreach ($fields->extensions as $extension) {
                $extensions->{$extension->namespace->value} = self::map($extension->fields);
            }

            $input->{TypeRules::EXTENSIONS_KEY} = $extensions;
        }

        return $input;
    }

    private static function map(FieldMap $fields): stdClass
    {
        $object = new stdClass;

        foreach ($fields->fields as $field) {
            $object->{$field->handle->value} = self::value($field->value);
        }

        return $object;
    }

    /**
     * One value in the same form: what a field of the input, a group's field or an item of a list
     * holds.
     */
    public static function value(FieldValue $value): mixed
    {
        return match (true) {
            $value instanceof NullValue => null,
            $value instanceof TextValue, $value instanceof DecimalValue, $value instanceof DateValue => $value->value,
            $value instanceof IntegerValue, $value instanceof BooleanValue => $value->value,
            $value instanceof DateTimeValue => $value->value->format('Y-m-d\TH:i:s.u\Z'),
            $value instanceof ListValue => array_map(self::value(...), $value->items),
            $value instanceof GroupValue => self::map($value->fields),
            $value instanceof MapValue => self::entries($value),
            default => throw new LogicException(sprintf('The field value %s has no input form.', $value::class)),
        };
    }

    private static function entries(MapValue $map): stdClass
    {
        $object = new stdClass;

        foreach ($map->entries as $entry) {
            $object->{$entry->key} = self::value($entry->value);
        }

        return $object;
    }
}
