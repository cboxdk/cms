<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Why cms:generate refused to generate or write, or cms:schema:editor could not edit a blueprint
 * file. Each case is an error code (GUARDRAILS 7.2).
 */
#[Internal]
enum GenerateErrorCode: string
{
    /** The configuration under cms.generators is missing a value or has an invalid one. */
    case InvalidConfig = 'generate_invalid_config';

    /** A schema root, or a blueprint file below one, does not exist or cannot be read. */
    case SchemaMissing = 'generate_schema_missing';

    /** A blueprint file is not valid YAML or not a valid blueprint of the blueprint schema v1. */
    case SchemaInvalid = 'generate_schema_invalid';

    /**
     * A blueprint file is of a later version than 1, or holds a value that the installed blueprint
     * schema allows and this cboxdk/cms-generators does not know. It needs a newer cboxdk/cms-generators.
     */
    case SchemaUnsupportedVersion = 'generate_schema_unsupported_version';

    /** Two blueprint files define a type with the same type_id, in one owner's schema root or in two. */
    case DuplicateTypeId = 'generate_duplicate_type_id';

    /** Two blueprint files of one owner define a type with the same handle. */
    case DuplicateTypeHandle = 'generate_duplicate_type_handle';

    /**
     * Two fields in one namespace have the same handle: the fields of a type, the fields that one
     * owner adds to one type in all its extension files, or the fields of one group.
     */
    case DuplicateFieldHandle = 'generate_duplicate_field_handle';

    /** Two options of one select field have the same value. */
    case DuplicateSelectValue = 'generate_duplicate_select_value';

    /** An extension's `extends` is a type_id that no blueprint file below the schema roots defines. */
    case UnknownExtendsTarget = 'generate_unknown_extends_target';

    /**
     * An extension extends a type of its own owner. A type has one owner, and only others extend it
     * (PRD 11.12, 13.3): the owner adds fields to its type in the type file, never under `ext`.
     */
    case ExtensionOfOwnType = 'generate_extension_of_own_type';

    /**
     * Two extension files of one owner for one type declare different versions. The fields one
     * owner adds to one type are one namespace with one version, the extender's part of the type's
     * composite version (PRD 11.2, 11.12 point 5), which upcasters are keyed on (PRD 11.4).
     */
    case ExtensionVersionMismatch = 'generate_extension_version_mismatch';

    /**
     * A field's column name is longer than 63 bytes, Postgres' limit for an identifier. The column
     * of an extension field is `ext__<namespace>__<handle>` (PRD 11.12).
     */
    case ColumnNameTooLong = 'generate_column_name_too_long';

    /** A field's `min` is greater than its `max`. */
    case MinAboveMax = 'generate_min_above_max';

    /** A field's `min_length` is greater than its `max_length`, or than the default `max_length` of its type. */
    case MinLengthAboveMaxLength = 'generate_min_length_above_max_length';

    /** A field's `min_items` is greater than its `max_items`. */
    case MinItemsAboveMaxItems = 'generate_min_items_above_max_items';

    /** A decimal field's `scale` is greater than its `precision`. */
    case ScaleAbovePrecision = 'generate_scale_above_precision';

    /**
     * A field's type is a `<namespace>:<handle>` that no field type contributor registered, so the
     * reader cannot resolve it in the field type registry (PRD 13.3, GUARDRAILS 2.4).
     */
    case UnknownFieldType = 'generate_unknown_field_type';

    /**
     * Types of two owners have the same handle. The generated code names a type by its handle
     * alone, so the handles of all owners share one space.
     */
    case HandleCollision = 'generate_handle_collision';

    /** A handle gives no valid PHP enum case name, or two handles give the same one. */
    case InvalidCaseName = 'generate_invalid_case_name';

    /** A generator produced a file outside its directory, or two files with the same path. */
    case InvalidOutput = 'generate_invalid_output';

    /** A generated file could not be written, or a stale one could not be removed. */
    case OutputUnwritable = 'generate_output_unwritable';

    /** cms:schema:editor could not write the editor line into a blueprint file. */
    case SchemaUnwritable = 'generate_schema_unwritable';
}
