<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\TypeTables\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\InvalidFieldValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use Cbox\Cms\Core\TypeTables\Domain\UnreadableTypeTable;
use DateTimeImmutable;
use JsonException;
use stdClass;

/**
 * The form of a type's fields in the columns of its type table (PRD 4.1, 11.6), read and written
 * by the kernel from the TypeCatalog without knowing the type (GUARDRAILS 2.4). decode() turns a
 * row, as PDO returns it, into the kernel's generic field values; encode() gives the column values
 * of field values, and binding() the parameter of a filter or cursor value.
 *
 * A column holds, by the field type of its field:
 *
 * - text, long_text and a select with one option: text;
 * - a select with several options: a text[] of the option values;
 * - integer: a bigint; decimal: a numeric; boolean: a boolean; date: a date;
 * - datetime: a timestamptz, read in any offset and held in UTC;
 * - rich_text: a jsonb array of Portable Text blocks (PRD 11.10);
 * - group: a jsonb object of the nested fields by handle, or a jsonb array of such objects when the
 *   group repeats. Inside it a text is a JSON string, an integer a JSON integer, a decimal its
 *   canonical string, a boolean a JSON boolean, a date `YYYY-MM-DD`, a date-time RFC 3339 in UTC
 *   with microseconds, a select with several options an array of strings and rich text its blocks.
 *
 * Null is NullValue. An encrypted field is bytea of ciphertext (PRD 12.2): decode() leaves it out,
 * because the kernel holds no key to it, and encode() refuses a value for it.
 */
#[Internal]
final readonly class TypeTableColumns
{
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private const string INSTANT = '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}(?::?[0-9]{2}){0,2})\z/';

    /**
     * The fields of a row of the type's table, the owner's and each extender's.
     *
     * @param  array<array-key, mixed>  $row  column name to the value PDO returned
     *
     * @throws UnreadableTypeTable
     */
    public static function decode(TypeDefinition $type, array $row): FieldValues
    {
        $own = [];
        $namespaces = [];
        $extensions = [];

        foreach ($type->fields as $field) {
            $column = $field->column;

            if (! $column instanceof ColumnDefinition || $field->encrypted) {
                continue;
            }

            if (! array_key_exists($column->name, $row)) {
                throw UnreadableTypeTable::missingColumn($column->name);
            }

            $named = new NamedValue($field->handle, self::column($field, $column, $row[$column->name]));

            if ($field->namespace instanceof FieldNamespace) {
                $namespaces[$field->namespace->value] = $field->namespace;
                $extensions[$field->namespace->value][] = $named;
            } else {
                $own[] = $named;
            }
        }

        return new FieldValues(new FieldMap(...$own), ...array_map(
            static fn (string $namespace, array $fields): ExtensionFields => new ExtensionFields($namespaces[$namespace], new FieldMap(...$fields)),
            array_keys($extensions),
            array_values($extensions),
        ));
    }

    /**
     * The value of a column as PDO returns it, as a field value.
     *
     * @throws UnreadableTypeTable
     */
    public static function column(FieldDefinition $field, ColumnDefinition $column, mixed $raw): FieldValue
    {
        if ($raw === null) {
            return new NullValue;
        }

        $name = $column->name;

        try {
            return match ($field->fieldType) {
                'text', 'long_text' => new TextValue(self::string($name, $raw)),
                'select' => str_ends_with($column->type, '[]') ? self::textArray($name, self::string($name, $raw)) : new TextValue(self::string($name, $raw)),
                'integer' => new IntegerValue(self::integer($name, $raw)),
                'decimal' => new DecimalValue(self::string($name, $raw)),
                'boolean' => new BooleanValue(self::boolean($name, $raw)),
                'date' => new DateValue(self::string($name, $raw)),
                'datetime' => new DateTimeValue(self::instant($name, self::string($name, $raw))),
                'rich_text' => self::richText($name, self::document($name, self::string($name, $raw))),
                'group' => self::group($field, $name, self::document($name, self::string($name, $raw))),
                default => throw UnreadableTypeTable::fieldType($name, $field->fieldType),
            };
        } catch (InvalidFieldValue $exception) {
            throw UnreadableTypeTable::value($name, 'a valid value: '.$exception->getMessage());
        }
    }

    /**
     * The column values of the fields the values hold, by column name. A field the values do not
     * hold is left out, so its column keeps its default.
     *
     * @return array<string, bool|int|string|null>
     *
     * @throws UnreadableTypeTable when a value does not fit its field, or is for an encrypted one
     */
    public static function encode(TypeDefinition $type, FieldValues $values): array
    {
        $row = [];

        foreach ($type->fields as $field) {
            $column = $field->column;

            if (! $column instanceof ColumnDefinition) {
                continue;
            }

            $value = $field->namespace instanceof FieldNamespace
                ? $values->extension($field->namespace)?->get($field->handle)
                : $values->own->get($field->handle);

            if (! $value instanceof FieldValue) {
                continue;
            }

            if ($value instanceof NullValue) {
                $row[$column->name] = null;

                continue;
            }

            if ($field->encrypted) {
                throw UnreadableTypeTable::encrypted($column->name);
            }

            $row[$column->name] = self::columnValue($field, $column, $value);
        }

        return $row;
    }

    /**
     * The parameter a filter or cursor value binds as: text, an integer, a decimal, a boolean, a
     * date or a date-time, which Postgres reads as the type of the column it is compared with.
     *
     * @throws UnreadableTypeTable for a value of another kind
     */
    public static function binding(string $column, FieldValue $value): bool|int|string
    {
        return match (true) {
            $value instanceof TextValue, $value instanceof DecimalValue, $value instanceof DateValue => $value->value,
            $value instanceof IntegerValue, $value instanceof BooleanValue => $value->value,
            $value instanceof DateTimeValue => $value->value->format('Y-m-d H:i:s.uP'),
            default => throw UnreadableTypeTable::value($column, 'a value that compares: text, an integer, a decimal, a boolean, a date or a date-time'),
        };
    }

    /**
     * @throws UnreadableTypeTable
     */
    private static function columnValue(FieldDefinition $field, ColumnDefinition $column, FieldValue $value): bool|int|string
    {
        $name = $column->name;

        return match (true) {
            in_array($field->fieldType, ['text', 'long_text'], true) && $value instanceof TextValue => $value->value,
            $field->fieldType === 'select' && ! str_ends_with($column->type, '[]') && $value instanceof TextValue => $value->value,
            $field->fieldType === 'select' && str_ends_with($column->type, '[]') && $value instanceof ListValue => self::arrayLiteral($name, $value),
            $field->fieldType === 'integer' && $value instanceof IntegerValue => $value->value,
            $field->fieldType === 'decimal' && $value instanceof DecimalValue => $value->value,
            $field->fieldType === 'boolean' && $value instanceof BooleanValue => $value->value,
            $field->fieldType === 'date' && $value instanceof DateValue => $value->value,
            $field->fieldType === 'datetime' && $value instanceof DateTimeValue => $value->value->format('Y-m-d H:i:s.uP'),
            $field->fieldType === 'rich_text' && $value instanceof ListValue => self::json($name, self::encodeRichText($name, $value)),
            $field->fieldType === 'group' => self::json($name, self::groupJson($field, $name, $value)),
            default => throw UnreadableTypeTable::value($name, sprintf('a %s for a field of the type %s', $value::class, $field->fieldType)),
        };
    }

    /**
     * A nested value of a group as JSON.
     *
     * @throws UnreadableTypeTable
     */
    private static function jsonValue(FieldDefinition $field, string $at, FieldValue $value): mixed
    {
        return match (true) {
            $value instanceof NullValue => null,
            in_array($field->fieldType, ['text', 'long_text', 'select'], true) && $value instanceof TextValue => $value->value,
            $field->fieldType === 'select' && $value instanceof ListValue => array_map(
                static fn (FieldValue $item): string => $item instanceof TextValue ? $item->value : throw UnreadableTypeTable::value($at, 'a list of option values'),
                $value->items,
            ),
            $field->fieldType === 'integer' && $value instanceof IntegerValue => $value->value,
            $field->fieldType === 'decimal' && $value instanceof DecimalValue => $value->value,
            $field->fieldType === 'boolean' && $value instanceof BooleanValue => $value->value,
            $field->fieldType === 'date' && $value instanceof DateValue => $value->value,
            $field->fieldType === 'datetime' && $value instanceof DateTimeValue => $value->value->format('Y-m-d\TH:i:s.u\Z'),
            $field->fieldType === 'rich_text' && $value instanceof ListValue => self::encodeRichText($at, $value),
            $field->fieldType === 'group' => self::groupJson($field, $at, $value),
            default => throw UnreadableTypeTable::value($at, sprintf('a %s for a field of the type %s', $value::class, $field->fieldType)),
        };
    }

    /**
     * A group as a JSON object of its nested fields, or a repeated group as a JSON array of them.
     *
     * @return stdClass|list<stdClass>
     *
     * @throws UnreadableTypeTable
     */
    private static function groupJson(FieldDefinition $group, string $at, FieldValue $value): stdClass|array
    {
        if ($value instanceof ListValue) {
            return array_map(
                static fn (FieldValue $item, int $index): stdClass => $item instanceof GroupValue
                    ? self::groupObject($group, $at.'.'.$index, $item)
                    : throw UnreadableTypeTable::value($at, 'a list of groups'),
                $value->items,
                array_keys($value->items),
            );
        }

        if (! $value instanceof GroupValue) {
            throw UnreadableTypeTable::value($at, 'a group');
        }

        return self::groupObject($group, $at, $value);
    }

    /**
     * @throws UnreadableTypeTable
     */
    private static function groupObject(FieldDefinition $group, string $at, GroupValue $value): stdClass
    {
        $object = new stdClass;

        foreach ($value->fields->fields as $named) {
            $nested = $group->field($named->handle);

            if (! $nested instanceof FieldDefinition) {
                throw UnreadableTypeTable::value($at, 'only the fields of its group, not '.$named->handle->value);
            }

            $object->{$named->handle->value} = self::jsonValue($nested, $at.'.'.$named->handle->value, $named->value);
        }

        return $object;
    }

    /**
     * @throws UnreadableTypeTable
     */
    private static function encodeRichText(string $at, ListValue $value): mixed
    {
        try {
            return JsonValues::encodeFieldValue($value);
        } catch (EncodingFailed $exception) {
            throw UnreadableTypeTable::value($at, 'Portable Text blocks: '.$exception->getMessage());
        }
    }

    /**
     * @throws UnreadableTypeTable
     */
    private static function json(string $column, mixed $value): string
    {
        try {
            return json_encode($value, self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw UnreadableTypeTable::value($column, 'a value JSON can hold: '.$exception->getMessage());
        }
    }

    /**
     * A text[] literal of option values, each quoted.
     *
     * @throws UnreadableTypeTable
     */
    private static function arrayLiteral(string $column, ListValue $value): string
    {
        $items = array_map(
            static fn (FieldValue $item): string => $item instanceof TextValue
                ? '"'.addcslashes($item->value, '"\\').'"'
                : throw UnreadableTypeTable::value($column, 'a list of option values'),
            $value->items,
        );

        return '{'.implode(',', $items).'}';
    }

    /**
     * @throws UnreadableTypeTable
     */
    private static function string(string $column, mixed $raw): string
    {
        if (! is_string($raw)) {
            throw UnreadableTypeTable::value($column, 'text');
        }

        return $raw;
    }

    /**
     * @throws UnreadableTypeTable
     */
    private static function integer(string $column, mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $raw) === 1 && (string) (int) $raw === $raw) {
            return (int) $raw;
        }

        throw UnreadableTypeTable::value($column, 'an integer');
    }

    /**
     * @throws UnreadableTypeTable
     */
    private static function boolean(string $column, mixed $raw): bool
    {
        return match ($raw) {
            true, 't' => true,
            false, 'f' => false,
            default => throw UnreadableTypeTable::value($column, 'a boolean'),
        };
    }

    /**
     * @throws UnreadableTypeTable
     */
    private static function instant(string $column, string $value): DateTimeImmutable
    {
        if (preg_match(self::INSTANT, $value) !== 1) {
            throw UnreadableTypeTable::value($column, 'a date-time with its offset');
        }

        return new DateTimeImmutable($value);
    }

    /**
     * The elements of a one-dimensional text[] as Postgres writes it, such as `{a,"b c",NULL}`.
     *
     * @throws UnreadableTypeTable
     */
    private static function textArray(string $column, string $literal): ListValue
    {
        if (! str_starts_with($literal, '{') || ! str_ends_with($literal, '}')) {
            throw UnreadableTypeTable::value($column, 'a text array');
        }

        $body = substr($literal, 1, -1);
        $length = strlen($body);
        $items = [];
        $at = 0;

        while ($at < $length) {
            if ($body[$at] === '"') {
                [$item, $at] = self::quoted($column, $body, $at + 1);
                $items[] = new TextValue($item);
            } else {
                $end = strpos($body, ',', $at);
                $end = $end === false ? $length : $end;
                $item = substr($body, $at, $end - $at);

                if ($item === '' || strpbrk($item, '{}"\\') !== false) {
                    throw UnreadableTypeTable::value($column, 'a text array');
                }

                $items[] = strcasecmp($item, 'NULL') === 0 ? new NullValue : new TextValue($item);
                $at = $end;
            }

            if ($at === $length) {
                break;
            }

            if ($body[$at] !== ',' || $at + 1 === $length) {
                throw UnreadableTypeTable::value($column, 'a text array');
            }

            $at++;
        }

        return new ListValue(...$items);
    }

    /**
     * The text of a quoted element that starts at $at, after its opening quote, and where the rest
     * of the literal starts.
     *
     * @return array{string, int}
     *
     * @throws UnreadableTypeTable
     */
    private static function quoted(string $column, string $body, int $at): array
    {
        $length = strlen($body);
        $item = '';

        while ($at < $length && $body[$at] !== '"') {
            if ($body[$at] === '\\') {
                $at++;
            }

            if ($at === $length) {
                break;
            }

            $item .= $body[$at];
            $at++;
        }

        if ($at === $length) {
            throw UnreadableTypeTable::value($column, 'a text array');
        }

        return [$item, $at + 1];
    }

    /**
     * @throws UnreadableTypeTable
     */
    private static function document(string $column, string $json): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw UnreadableTypeTable::value($column, 'a JSON document');
        }
    }

    /**
     * @throws UnreadableTypeTable
     */
    private static function richText(string $at, mixed $document): ListValue
    {
        try {
            $value = JsonValues::fieldValue($document, self::path($at));
        } catch (DecodingFailed $exception) {
            throw UnreadableTypeTable::value($at, 'Portable Text blocks: '.$exception->getMessage());
        }

        if (! $value instanceof ListValue) {
            throw UnreadableTypeTable::value($at, 'a list of Portable Text blocks');
        }

        return $value;
    }

    /**
     * The field path of a column or of a value inside it, such as `supplier.0.note`.
     */
    private static function path(string $at): FieldPath
    {
        $segments = explode('.', $at);

        return new FieldPath(array_shift($segments), ...array_map(
            static fn (string $segment): int|string => ctype_digit($segment) ? (int) $segment : $segment,
            $segments,
        ));
    }

    /**
     * A group from its JSON object, or a repeated group from its JSON array of objects.
     *
     * @throws UnreadableTypeTable
     */
    private static function group(FieldDefinition $group, string $at, mixed $document): FieldValue
    {
        if (is_array($document)) {
            return new ListValue(...array_map(
                static fn (mixed $item, int $index): GroupValue => $item instanceof stdClass
                    ? self::groupValue($group, $at.'.'.$index, $item)
                    : throw UnreadableTypeTable::value($at, 'a list of objects'),
                $document,
                array_keys($document),
            ));
        }

        if (! $document instanceof stdClass) {
            throw UnreadableTypeTable::value($at, 'an object or a list of objects');
        }

        return self::groupValue($group, $at, $document);
    }

    /**
     * @throws UnreadableTypeTable
     */
    private static function groupValue(FieldDefinition $group, string $at, stdClass $object): GroupValue
    {
        $fields = [];

        foreach (get_object_vars($object) as $handle => $value) {
            $nested = array_find($group->fields, static fn (FieldDefinition $field): bool => $field->handle->value === (string) $handle);

            if (! $nested instanceof FieldDefinition) {
                throw UnreadableTypeTable::value($at, 'only the fields of its group, not '.$handle);
            }

            $fields[] = new NamedValue($nested->handle, self::nested($nested, $at.'.'.$handle, $value));
        }

        return new GroupValue(new FieldMap(...$fields));
    }

    /**
     * A nested field of a group from its JSON value.
     *
     * @throws UnreadableTypeTable
     */
    private static function nested(FieldDefinition $field, string $at, mixed $value): FieldValue
    {
        if ($value === null) {
            return new NullValue;
        }

        return match ($field->fieldType) {
            'text', 'long_text' => new TextValue(self::string($at, $value)),
            'select' => is_array($value)
                ? new ListValue(...array_map(static fn (mixed $item): TextValue => new TextValue(self::string($at, $item)), $value))
                : new TextValue(self::string($at, $value)),
            'integer' => is_int($value) ? new IntegerValue($value) : throw UnreadableTypeTable::value($at, 'an integer'),
            'decimal' => new DecimalValue(self::string($at, $value)),
            'boolean' => is_bool($value) ? new BooleanValue($value) : throw UnreadableTypeTable::value($at, 'a boolean'),
            'date' => new DateValue(self::string($at, $value)),
            'datetime' => new DateTimeValue(self::instant($at, self::string($at, $value))),
            'rich_text' => self::richText($at, $value),
            'group' => self::group($field, $at, $value),
            default => throw UnreadableTypeTable::fieldType($at, $field->fieldType),
        };
    }
}
