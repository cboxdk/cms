<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Protocol\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The JSON Schema of the fields of a revision of any type (GUARDRAILS 2.4, PRD 11.12), as a command
 * that works for any type carries them, such as entry.create and entry.revise: the definitions a
 * kernel schema has under `$defs` when a property is bound to FieldValues with ValueBinding::fields().
 * The property refers to `#/$defs/fields`, and each definition below must be in the schema exactly
 * as it is here, because the codec's form of the fields is fixed: the core's JsonValues reads and
 * writes it, and the TypeScript runtime checks it, each with every rule these definitions state.
 *
 * - `fields`: an object of the owner's fields by handle, each a field value, with the extension
 *   fields under `ext`;
 * - `extension_fields`: an object of namespaces, each an object of that extender's fields by handle;
 * - `field_handle`: lowercase snake_case of at most 63 bytes, never `ext` or starting with `cms_`;
 * - `field_namespace`: 1 to 20 lowercase letters and digits, starting with a letter, never `ext`;
 * - `field_value`: a string, an integer, a boolean, null, a list of field values or an object of
 *   field values by a key that is not empty. A key has at most 255 bytes, which JSON Schema cannot
 *   state in bytes, so its description says it.
 */
#[Internal]
final readonly class FieldValuesSchema
{
    /** The definition the bound property refers to. */
    public const string REFERENCE = '#/$defs/fields';

    /** The definitions, by name, as JSON. */
    public const string DEFINITIONS = <<<'JSON'
        {
          "fields": {
            "description": "The fields by handle, in the input form the type's validator reads: the owner's fields, and each extender's fields under ext by its namespace. A text, a decimal (its canonical string), a date (YYYY-MM-DD) and a date-time (RFC 3339) are strings, an integer is an integer, a boolean a boolean, a list a list and a group an object of its fields. The kernel checks the values against the type's schema when it writes them.",
            "type": "object",
            "properties": {
              "ext": {"$ref": "#/$defs/extension_fields"}
            },
            "propertyNames": {"anyOf": [{"const": "ext"}, {"$ref": "#/$defs/field_handle"}]},
            "additionalProperties": {"$ref": "#/$defs/field_value"}
          },
          "extension_fields": {
            "description": "The extension fields, an object of fields by handle for each extender's namespace.",
            "type": "object",
            "propertyNames": {"$ref": "#/$defs/field_namespace"},
            "additionalProperties": {
              "type": "object",
              "propertyNames": {"$ref": "#/$defs/field_handle"},
              "additionalProperties": {"$ref": "#/$defs/field_value"}
            }
          },
          "field_handle": {
            "description": "A field's handle: lowercase snake_case of at most 63 characters without a double underscore, never ext and never starting with cms_.",
            "type": "string",
            "maxLength": 63,
            "pattern": "^(?!cms_)(?!ext$)[a-z][a-z0-9]*(_[a-z0-9]+)*$"
          },
          "field_namespace": {
            "description": "An extender's namespace: 1 to 20 lowercase letters and digits, starting with a letter, never ext.",
            "type": "string",
            "pattern": "^(?!ext$)[a-z][a-z0-9]{0,19}$"
          },
          "field_value": {
            "description": "A field's value: a string, an integer, a boolean, null, a list of values or an object of values by a key of 1 to 255 bytes.",
            "type": ["string", "integer", "boolean", "null", "array", "object"],
            "items": {"$ref": "#/$defs/field_value"},
            "propertyNames": {"minLength": 1},
            "additionalProperties": {"$ref": "#/$defs/field_value"}
          }
        }
        JSON;
}
