<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Why cms:generate refused to generate or write. Each case is an error code (GUARDRAILS 7.2).
 */
#[Internal]
enum GenerateErrorCode: string
{
    /** The configuration under cms.generators is missing a value or has an invalid one. */
    case InvalidConfig = 'generate_invalid_config';

    /** The schema file does not exist or cannot be read. */
    case SchemaMissing = 'generate_schema_missing';

    /** The schema file is not valid YAML. */
    case SchemaSyntax = 'generate_schema_syntax';

    /** The schema does not declare `format: m0-provisional`. */
    case SchemaFormat = 'generate_schema_format';

    /** A key is missing, unknown or has a value of the wrong kind. */
    case SchemaInvalid = 'generate_schema_invalid';

    /**
     * A blueprint file is of a later version than 1, or holds a value that the installed blueprint
     * schema allows and this cboxdk/cms-generators does not know. It needs a newer cboxdk/cms-generators.
     */
    case SchemaUnsupportedVersion = 'generate_schema_unsupported_version';

    /** Two types have the same handle. */
    case DuplicateType = 'generate_duplicate_type';

    /** Two fields of one type have the same handle. */
    case DuplicateField = 'generate_duplicate_field';

    /** A handle gives no valid PHP enum case name, or two handles give the same one. */
    case InvalidCaseName = 'generate_invalid_case_name';

    /** A generator produced a file outside its directory, or two files with the same path. */
    case InvalidOutput = 'generate_invalid_output';

    /** A generated file could not be written, or a stale one could not be removed. */
    case OutputUnwritable = 'generate_output_unwritable';
}
