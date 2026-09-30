<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\InvalidFieldValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use stdClass;

/**
 * The JSON form of the fields of a command that works for any type (GUARDRAILS 2.4), such as
 * entry.create and entry.revise: the input form FieldValuesInput writes and the type's generated
 * validator reads (PRD 11.12), an object of the owner's fields by handle with the extension fields
 * under `ext`, an object of namespaces, each an object of that extender's fields by handle.
 *
 * The command names no type the codec could read the fields with, so read() takes each value as
 * JSON gives it, as JsonValues::fieldValue() does: a string as TextValue, an integer as
 * IntegerValue, a boolean as BooleanValue, null as NullValue, an array as ListValue and an object
 * as MapValue. A decimal, a date and a date-time are therefore text here, and a group a map. The
 * kernel validates the fields against the type's schema in that same input form, at the write
 * stage, and write() gives every value back as it was read, so what the validator checks and the
 * revision stores is the caller's document.
 *
 * SCHEMA is its JSON Schema, which the command codecs put under `$defs/fields`, with the value of
 * a field under `$defs/field_value`.
 */
#[Internal]
final readonly class FieldValuesJson
{
    /**
     * The `$defs` of a command's JSON Schema that describe the fields: `fields` and `field_value`.
     */
    public const string SCHEMA_DEFS = <<<'JSON'
        "fields": {
          "description": "The fields by handle, in the input form the type's validator reads: the owner's fields, and each extender's under ext by its namespace. A handle is lowercase snake_case of at most 63 bytes without a double underscore, never ext or starting with cms_. A text, a decimal (its canonical string), a date (YYYY-MM-DD) and a date-time (RFC 3339) are strings, an integer an integer, a boolean a boolean, a list a list and a group an object of its fields. The kernel checks the values against the type's schema.",
          "type": "object",
          "propertyNames": {"$ref": "#/$defs/field_handle"},
          "properties": {
            "ext": {
              "description": "The extension fields, by the extender's namespace: 1 to 20 lowercase letters and digits, starting with a letter.",
              "type": "object",
              "propertyNames": {"pattern": "^[a-z][a-z0-9]{0,19}$", "not": {"const": "ext"}},
              "additionalProperties": {
                "type": "object",
                "propertyNames": {"$ref": "#/$defs/field_handle"},
                "additionalProperties": {"$ref": "#/$defs/field_value"}
              }
            }
          },
          "additionalProperties": {"$ref": "#/$defs/field_value"}
        },
        "field_handle": {
          "type": "string",
          "maxLength": 63,
          "pattern": "^(?!cms_)[a-z][a-z0-9]*(_[a-z0-9]+)*$"
        },
        "field_value": {
          "description": "A field's value as JSON: a string, an integer, a boolean, null, a list of values or an object of values by key of 1 to 255 bytes.",
          "type": ["string", "integer", "boolean", "null", "array", "object"],
          "items": {"$ref": "#/$defs/field_value"},
          "propertyNames": {"minLength": 1},
          "additionalProperties": {"$ref": "#/$defs/field_value"}
        }
        JSON;

    /**
     * The fields the JSON value at $at holds.
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function read(mixed $value, FieldPath $at): FieldValues
    {
        if (! $value instanceof stdClass) {
            throw DecodingFailed::invalid($at, 'is not an object of fields by handle');
        }

        $own = [];
        $extensions = [];

        foreach (get_object_vars($value) as $key => $field) {
            $key = (string) $key;

            if ($key === TypeRules::EXTENSIONS_KEY) {
                $extensions = self::extensions($field, $at->then($key));

                continue;
            }

            $own[] = self::named($key, $field, $at);
        }

        return new FieldValues(new FieldMap(...$own), ...$extensions);
    }

    /**
     * The JSON form of the fields, the reverse of read(): keys sorted at every level, so the same
     * fields always give the same bytes.
     */
    public static function write(FieldValues $fields): stdClass
    {
        $input = get_object_vars(FieldValuesInput::of($fields));
        ksort($input, SORT_STRING);

        return (object) $input;
    }

    /**
     * @return list<ExtensionFields>
     *
     * @throws DecodingFailed with json_invalid
     */
    private static function extensions(mixed $value, FieldPath $at): array
    {
        if (! $value instanceof stdClass) {
            throw DecodingFailed::invalid($at, 'is not an object of extension fields by namespace');
        }

        $extensions = [];

        foreach (get_object_vars($value) as $namespace => $fields) {
            try {
                $name = new FieldNamespace((string) $namespace);
            } catch (InvalidFieldValue $invalid) {
                throw DecodingFailed::invalid($at, sprintf('has the key "%s", which is not a namespace: %s', $namespace, $invalid->getMessage()), $invalid);
            }

            $path = $at->then($name->value);

            if (! $fields instanceof stdClass) {
                throw DecodingFailed::invalid($path, 'is not an object of fields by handle');
            }

            $named = [];

            foreach (get_object_vars($fields) as $handle => $field) {
                $named[] = self::named((string) $handle, $field, $path);
            }

            $extensions[] = new ExtensionFields($name, new FieldMap(...$named));
        }

        return $extensions;
    }

    /**
     * @throws DecodingFailed with json_invalid
     */
    private static function named(string $handle, mixed $value, FieldPath $at): NamedValue
    {
        try {
            $name = new FieldHandle($handle);
        } catch (InvalidFieldValue $invalid) {
            throw DecodingFailed::invalid($at, sprintf('has the key "%s", which is not a field handle: %s', $handle, $invalid->getMessage()), $invalid);
        }

        return new NamedValue($name, JsonValues::fieldValue($value, $at->then($name->value)));
    }
}
