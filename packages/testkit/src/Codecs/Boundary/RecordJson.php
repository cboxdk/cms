<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Codecs\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\InvalidRecordDocument;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use DateTimeZone;
use JsonException;
use stdClass;

/**
 * The JSON form of the records for FakeRecordCodecs, from the contracts alone: a text, a select's
 * option or a list of them, an integer, a decimal's string, a boolean, a date `YYYY-MM-DD`, a
 * date-time in UTC with microseconds, rich text's blocks and a group's object or a list of them,
 * every object's keys sorted. A value that does not fit its field is InvalidRecordDocument.
 */
#[Internal]
final readonly class RecordJson
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @throws InvalidRecordDocument
     */
    public static function value(TypeId $type, FieldDefinition $field, FieldValue $value): mixed
    {
        $kind = $field->valueType();

        return match (true) {
            $value instanceof NullValue => null,
            in_array($kind, ['text', 'long_text', 'select'], true) && $value instanceof TextValue => $value->value,
            $kind === 'select' && $value instanceof ListValue => array_map(static fn (FieldValue $item): mixed => self::value($type, $field, $item), $value->items),
            $kind === 'integer' && $value instanceof IntegerValue => $value->value,
            $kind === 'decimal' && $value instanceof DecimalValue => $value->value,
            $kind === 'boolean' && $value instanceof BooleanValue => $value->value,
            $kind === 'date' && $value instanceof DateValue => $value->value,
            $kind === 'datetime' && $value instanceof DateTimeValue => $value->value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            $kind === 'rich_text' && $value instanceof ListValue => array_map(self::block(...), $value->items),
            $kind === 'group' && $value instanceof GroupValue => self::group($type, $field, $value),
            $kind === 'group' && $value instanceof ListValue => array_map(static fn (FieldValue $item): stdClass => $item instanceof GroupValue
                ? self::group($type, $field, $item)
                : throw InvalidRecordDocument::refused($type, sprintf('%s holds a list of groups', $field->address())), $value->items),
            default => throw InvalidRecordDocument::refused($type, sprintf('%s is a field of the type %s and cannot hold a %s', $field->address(), $field->fieldType, $value::class)),
        };
    }

    /**
     * The object with its keys sorted, as the generated codecs write them.
     */
    public static function sorted(stdClass $object): stdClass
    {
        $keys = get_object_vars($object);
        ksort($keys, SORT_STRING);

        return (object) $keys;
    }

    /**
     * @throws InvalidRecordDocument
     */
    public static function encode(TypeId $type, stdClass $record): string
    {
        try {
            return json_encode($record, self::FLAGS);
        } catch (JsonException $refused) {
            throw InvalidRecordDocument::refused($type, $refused->getMessage(), $refused);
        }
    }

    private static function group(TypeId $type, FieldDefinition $group, GroupValue $value): stdClass
    {
        $object = new stdClass;

        foreach ($value->fields->fields as $named) {
            $nested = $group->field($named->handle) ?? throw InvalidRecordDocument::refused($type, sprintf('%s has no field %s', $group->address(), $named->handle->value));
            $object->{$named->handle->value} = self::value($type, $nested, $named->value);
        }

        return self::sorted($object);
    }

    /**
     * A block of rich text, or a value inside one, as the kernel holds Portable Text.
     */
    private static function block(FieldValue $value): mixed
    {
        if ($value instanceof MapValue) {
            $object = new stdClass;

            foreach ($value->entries as $entry) {
                $object->{$entry->key} = self::block($entry->value);
            }

            return self::sorted($object);
        }

        return match (true) {
            $value instanceof ListValue => array_map(self::block(...), $value->items),
            $value instanceof TextValue, $value instanceof IntegerValue, $value instanceof BooleanValue => $value->value,
            default => null,
        };
    }
}
