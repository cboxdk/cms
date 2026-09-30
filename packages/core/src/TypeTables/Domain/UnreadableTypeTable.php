<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\TypeTables\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * A row of a type table, or a value for one, that does not have the form its type's fields give
 * it (PRD 11.6): a column the query did not return, a value of the wrong kind, a malformed array or
 * JSON document, or a field type without a column form. The kernel writes every row, so this is a
 * fault of the kernel or of a hand-written row, never of a caller's input.
 */
#[Internal]
final class UnreadableTypeTable extends LogicException
{
    public static function missingColumn(string $column): self
    {
        return new self(sprintf('The row of the type table has no column %s.', $column));
    }

    public static function value(string $column, string $expected): self
    {
        return new self(sprintf('The column %s of the type table does not hold %s.', $column, $expected));
    }

    public static function fieldType(string $column, string $fieldType): self
    {
        return new self(sprintf('The column %s holds a field of the type %s, which has no column form in a type table.', $column, $fieldType));
    }

    public static function encrypted(string $column): self
    {
        return new self(sprintf('The column %s holds an encrypted field, and the kernel holds no key to write its ciphertext (PRD 12.2).', $column));
    }
}
