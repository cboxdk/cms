<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use UnexpectedValueException;

/**
 * Reads the columns of the rows the node lookups answer with. Postgres hands a row back as an
 * object of untyped values, so one place turns them into the typed values the Domain takes; a value
 * of another form than its column has is a broken schema, and throws.
 */
#[Internal]
final readonly class NodeRows
{
    public static function object(mixed $row): object
    {
        return is_object($row) ? $row : throw new UnexpectedValueException(sprintf('A row of a node lookup is an object, got %s.', get_debug_type($row)));
    }

    public static function text(object $row, string $column): string
    {
        return self::textOrNull($row, $column) ?? throw new UnexpectedValueException(sprintf('The column %s of a node row is not null.', $column));
    }

    public static function textOrNull(object $row, string $column): ?string
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        if ($value !== null && ! is_string($value)) {
            throw new UnexpectedValueException(sprintf('The column %s of a node row is text, got %s.', $column, get_debug_type($value)));
        }

        return $value;
    }

    public static function integer(object $row, string $column): int
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return is_int($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a node row is an integer, got %s.', $column, get_debug_type($value)));
    }

    public static function boolean(object $row, string $column): bool
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return match ($value) {
            true, 't', 'true' => true,
            false, 'f', 'false' => false,
            default => throw new UnexpectedValueException(sprintf('The column %s of a node row is a boolean, got %s.', $column, get_debug_type($value))),
        };
    }
}
