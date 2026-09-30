<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Boundary;

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
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Pipeline\Boundary\FieldValuesInput;
use Cbox\Cms\Core\TypeTables\Boundary\TypeTableColumns;
use Cbox\Cms\Core\TypeTables\Domain\UnreadableTypeTable;
use JsonException;
use LogicException;
use stdClass;

/**
 * The stored forms of a revision's fields (PRD 4.1, 11.6), for the writers of the entry commands.
 *
 * - payload() is the content of a revision payload and of a head snapshot, format FORMAT_VERSION:
 *   the fields as the input validator reads them (FieldValuesInput), a JSON object of the owner's
 *   fields by handle with the extension fields under `ext`, each namespace an object of its fields.
 *   The revision's schema version, stored beside it, tells a reader the type of every field.
 * - fields() reads such a payload back into field values, with the type of the schema version it
 *   was written under, as the type table's JSON columns are read (TypeTableColumns), so a release
 *   writes the released row from the revision it releases.
 * - columns() is the type table's row: for every top-level field of the type, its column and the
 *   value the column takes, NULL for a field the revision leaves out or holds no value in. A text,
 *   a date, a decimal and an integer are given as they are, a boolean as `true` or `false`, a
 *   date-time in UTC with its microseconds, a list in a `text[]` column as an array literal, and a
 *   rich text, a group or a list in a `jsonb` column in the payload's form. Postgres takes each as
 *   the column's type.
 *
 * An encrypted field's column is `bytea` of ciphertext (PRD 12.2), and the kernel refuses a value
 * for it before the commit (the pipeline's validation), so here it always holds NULL.
 */
#[Internal]
final readonly class StoredContent
{
    /** The format of the stored payload; a change of its form is the next version. */
    public const int FORMAT_VERSION = 1;

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function payload(FieldValues $fields): string
    {
        return JsonText::encode(FieldValuesInput::of($fields));
    }

    /**
     * The fields of a payload, read with the type of the schema version it was written under.
     *
     * @throws LogicException when the payload is not a JSON object of the type's fields, which the kernel wrote it as
     */
    public static function fields(TypeDefinition $type, string $payload): FieldValues
    {
        try {
            $document = JsonText::decode($payload);
        } catch (DecodingFailed $exception) {
            throw new LogicException(sprintf('A payload of %s is not a JSON object: %s', $type->name->value, $exception->getMessage()), 0, $exception);
        }

        $extensions = $document->{TypeRules::EXTENSIONS_KEY} ?? null;
        unset($document->{TypeRules::EXTENSIONS_KEY});

        if ($extensions !== null && ! $extensions instanceof stdClass) {
            throw new LogicException(sprintf('The extension fields of a payload of %s are not a JSON object.', $type->name->value));
        }

        $namespaces = [];

        foreach (get_object_vars($extensions ?? new stdClass) as $namespace => $fields) {
            if (! $fields instanceof stdClass) {
                throw new LogicException(sprintf('The fields of the namespace "%s" in a payload of %s are not a JSON object.', $namespace, $type->name->value));
            }

            $extension = new FieldNamespace((string) $namespace);
            $namespaces[] = new ExtensionFields($extension, self::map($type, $extension, $fields));
        }

        return new FieldValues(self::map($type, null, $document), ...$namespaces);
    }

    /**
     * The fields of one owner, the type's owner when $namespace is null, from their JSON object.
     *
     * @throws LogicException when the object holds a field the type does not declare
     */
    private static function map(TypeDefinition $type, ?FieldNamespace $namespace, stdClass $object): FieldMap
    {
        $named = [];
        $values = get_object_vars($object);

        foreach ($type->fields as $field) {
            if ($field->namespace?->value !== $namespace?->value || ! array_key_exists($field->handle->value, $values)) {
                continue;
            }

            try {
                $named[] = new NamedValue($field->handle, TypeTableColumns::payloadValue($field, $field->address(), $values[$field->handle->value]));
            } catch (UnreadableTypeTable $exception) {
                throw new LogicException(sprintf('A payload of %s cannot be read: %s', $type->name->value, $exception->getMessage()), 0, $exception);
            }

            unset($values[$field->handle->value]);
        }

        if ($values !== []) {
            throw new LogicException(sprintf(
                'A payload of %s holds %s, which the type does not declare%s.',
                $type->name->value,
                implode(', ', array_map(static fn (int|string $handle): string => '"'.$handle.'"', array_keys($values))),
                $namespace instanceof FieldNamespace ? ' in the namespace '.$namespace->value : '',
            ));
        }

        return new FieldMap(...$named);
    }

    /**
     * The value of each top-level field's column, by column name, in the type's column order.
     *
     * @return array<string, string|int|null>
     *
     * @throws LogicException when a value does not fit its column, which the type's validator rules out
     */
    public static function columns(TypeDefinition $type, FieldValues $fields): array
    {
        $columns = [];

        foreach ($type->fields as $field) {
            $column = $field->column ?? throw new LogicException(sprintf('The top-level field "%s" of %s has no column.', $field->address(), $type->name->value));
            $value = self::valueOf($fields, $field);
            $columns[$column->name] = $value instanceof FieldValue && ! $value instanceof NullValue
                ? self::column($column, $field, $value)
                : null;
        }

        return $columns;
    }

    private static function valueOf(FieldValues $fields, FieldDefinition $field): ?FieldValue
    {
        $map = $field->namespace instanceof FieldNamespace ? $fields->extension($field->namespace) : $fields->own;

        return $map instanceof FieldMap ? $map->get($field->handle) : null;
    }

    private static function column(ColumnDefinition $column, FieldDefinition $field, FieldValue $value): string|int
    {
        if ($field->encrypted) {
            throw new LogicException(sprintf('The encrypted field "%s" holds a value, which the kernel refuses before the commit.', $field->address()));
        }

        if ($column->type === 'jsonb') {
            try {
                return json_encode(FieldValuesInput::value($value), self::JSON_FLAGS, JsonText::MAX_DEPTH);
            } catch (JsonException $exception) {
                throw new LogicException(sprintf('The field "%s" has no JSON form: %s', $field->address(), $exception->getMessage()), 0, $exception);
            }
        }

        if (str_ends_with($column->type, '[]')) {
            if (! $value instanceof ListValue) {
                throw self::misfit($field, $column, $value);
            }

            return '{'.implode(',', array_map(
                static fn (FieldValue $item): string => '"'.addcslashes((string) self::scalar($field, $column, $item), '"\\').'"',
                $value->items,
            )).'}';
        }

        return self::scalar($field, $column, $value);
    }

    private static function scalar(FieldDefinition $field, ColumnDefinition $column, FieldValue $value): string|int
    {
        return match (true) {
            $value instanceof TextValue, $value instanceof DecimalValue, $value instanceof DateValue => $value->value,
            $value instanceof IntegerValue => $value->value,
            $value instanceof BooleanValue => $value->value ? 'true' : 'false',
            $value instanceof DateTimeValue => $value->value->format('Y-m-d H:i:s.uP'),
            default => throw self::misfit($field, $column, $value),
        };
    }

    private static function misfit(FieldDefinition $field, ColumnDefinition $column, FieldValue $value): LogicException
    {
        return new LogicException(sprintf(
            'The field "%s" holds a %s, which its %s column cannot take; the type\'s validator refuses such a value before the commit.',
            $field->address(),
            $value::class,
            $column->type,
        ));
    }
}
