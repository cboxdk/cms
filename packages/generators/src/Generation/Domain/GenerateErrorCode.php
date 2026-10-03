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
    /** The configuration under cbox-cms.generators is missing a value or has an invalid one. */
    case InvalidConfig = 'generate_invalid_config';

    /** A schema root, or a blueprint file below one, does not exist or cannot be read. */
    case SchemaMissing = 'generate_schema_missing';

    /** A blueprint file is not valid YAML or not a valid blueprint of the blueprint schema v1. */
    case SchemaInvalid = 'generate_schema_invalid';

    /**
     * A blueprint file is of a later version than 1, or holds a value that the installed blueprint
     * schema allows and the generator of this cboxdk/cms does not know. It needs a newer cboxdk/cms.
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

    /**
     * A type has more than 200 top-level fields, its own and those its extensions add together
     * (PRD 11.6). Each is a column of the type's table (PRD 11.12).
     */
    case TooManyFields = 'generate_too_many_fields';

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
     * A type's owner and handle give no valid PHP enum case name, or two types of one owner give
     * the same one.
     */
    case InvalidCaseName = 'generate_invalid_case_name';

    /**
     * Two fields, options or extender namespaces of one type would get the same name in the
     * type's generated PHP records, such as the handles `size_1` and `size1`, or a name PHP
     * reserves; or two fields of one object, or two classes of the generated DTOs, get the same
     * PHP name: the group `b_v1` of the type `a` and the type `a_v1_b` both give the class
     * `AppAV1BV1`.
     */
    case NameCollision = 'generate_name_collision';

    /**
     * A type's table name, `<owner>__<handle>`, is longer than 54 bytes, so the names of its row
     * level security policies, `<table>_released` and `<table>_actor`, would pass Postgres' limit
     * of 63 bytes (PRD 11.6).
     */
    case TableNameTooLong = 'generate_table_name_too_long';

    /**
     * A schema lock in the migrations directory is not a lock cms:generate wrote: it is not valid
     * JSON, lacks a value, has an unknown format, or its file name does not match its table.
     */
    case LockInvalid = 'generate_lock_invalid';

    /**
     * A type whose table has a schema lock is no longer in the schema. Until schema evolution
     * (B3), a type table only grows: a type is never removed or renamed.
     */
    case TypeRemoved = 'generate_type_removed';

    /**
     * A type's id, stages or localization differ from its schema lock, which would change the
     * table's key or system columns. Until schema evolution (B3), only new types and new optional
     * fields change a type table.
     */
    case TableChanged = 'generate_table_changed';

    /**
     * A field whose column is in its type's schema lock is no longer in the schema. Until schema
     * evolution (B3), a field is never removed or renamed.
     */
    case FieldRemoved = 'generate_field_removed';

    /**
     * A field's column type, NOT NULL, CHECK constraints or index differ from its type's schema
     * lock. Until schema evolution (B3), an existing column never changes.
     */
    case FieldChanged = 'generate_field_changed';

    /**
     * A field added to a type that has a table is required, so its column would be NOT NULL on
     * existing rows. Until schema evolution (B3), a new field of an existing type is optional.
     */
    case RequiredFieldAdded = 'generate_required_field_added';

    /**
     * A field declared filterable or sortable whose field type the typed query builder cannot
     * compare: rich text, a group, or a select that allows several options (PRD 8.8).
     */
    case FieldNotQueryable = 'generate_field_not_queryable';

    /** A generator produced a file outside its directory, or two files with the same path. */
    case InvalidOutput = 'generate_invalid_output';

    /** A generated file could not be written, or a stale one could not be removed. */
    case OutputUnwritable = 'generate_output_unwritable';

    /** cms:panel:types was given a namespace no installed addon has. */
    case PanelAddonUnknown = 'generate_panel_addon_unknown';

    /** cms:panel:types could not read the registry cms:build compiles. */
    case RegistryUnreadable = 'generate_registry_unreadable';

    /** cms:schema:editor could not write the editor line into a blueprint file. */
    case SchemaUnwritable = 'generate_schema_unwritable';
}
