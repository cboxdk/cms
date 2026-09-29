<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Migrations\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;

/**
 * The names a type table and its indexes get in Postgres (PRD 11.6).
 *
 * - The table is `<owner>__<handle>` (TypeName::table()): an owner has no underscore and a handle no
 *   double underscore, so the encoding is injective, and no kernel table has a double underscore.
 *   The core's TypeTableAccess names the table's policies `<table>_actor` and `<table>_released`,
 *   so a table name has at most 54 bytes.
 * - An index is `<table>__<column>`, injective for the same reason, because a column is a handle, a
 *   system column `cms_*` or `ext__<namespace>__<handle>`. A name over 63 bytes keeps its first 53
 *   bytes and ends with `__` and the first 8 hex digits of the SHA-256 of the whole name, so it is
 *   never cut short by Postgres and stays the same on every run.
 */
#[Internal]
final readonly class TypeTableNames
{
    /** Postgres' limit for an identifier, NAMEDATALEN - 1. */
    public const int MAX_IDENTIFIER_BYTES = 63;

    /** The longest table name whose policy names `<table>_released` fit in 63 bytes. */
    public const int MAX_TABLE_BYTES = 54;

    /** What separates the table from the column in an index name. */
    public const string INDEX_SEPARATOR = '__';

    private const int HASH_DIGITS = 8;

    public static function table(TypeDescriptor $type): string
    {
        return new TypeName($type->name())->table();
    }

    public static function fits(string $table): bool
    {
        return strlen($table) <= self::MAX_TABLE_BYTES;
    }

    public static function index(string $table, string $column): string
    {
        $name = $table.self::INDEX_SEPARATOR.$column;

        if (strlen($name) <= self::MAX_IDENTIFIER_BYTES) {
            return $name;
        }

        $suffix = self::INDEX_SEPARATOR.substr(hash('sha256', $name), 0, self::HASH_DIGITS);

        return substr($name, 0, self::MAX_IDENTIFIER_BYTES - strlen($suffix)).$suffix;
    }
}
