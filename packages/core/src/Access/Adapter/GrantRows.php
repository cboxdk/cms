<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use UnexpectedValueException;

/**
 * Reads the columns of the rows the grant commands' functions return (PRD 5.10): text, integers,
 * booleans and a locale set, which a function gives as text[] and a query as its literal.
 */
#[Internal]
final readonly class GrantRows
{
    public static function row(mixed $row): object
    {
        return is_object($row) ? $row : throw new UnexpectedValueException(sprintf('A row is an object, got %s.', get_debug_type($row)));
    }

    /**
     * @return ($nullable is true ? string|null : string)
     */
    public static function text(object $row, string $column, bool $nullable = false): ?string
    {
        $value = self::value($row, $column);

        if ($value === null && $nullable) {
            return null;
        }

        return is_string($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a row is text, got %s.', $column, get_debug_type($value)));
    }

    public static function integer(object $row, string $column): int
    {
        $value = self::value($row, $column);

        return is_int($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a row is an integer, got %s.', $column, get_debug_type($value)));
    }

    public static function boolean(object $row, string $column): bool
    {
        $value = self::value($row, $column);

        return is_bool($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a row is a boolean, got %s.', $column, get_debug_type($value)));
    }

    /**
     * The locale set of a text[] column as Postgres writes its literal, or null for every locale.
     * Locales hold no character the literal quotes.
     *
     * @return list<Locale>|null
     */
    public static function locales(object $row, string $column): ?array
    {
        $literal = self::text($row, $column, nullable: true);

        if ($literal === null) {
            return null;
        }

        if (preg_match('/\A\{([A-Za-z0-9-]+(?:,[A-Za-z0-9-]+)*)\}\z/', $literal, $match) !== 1) {
            throw new UnexpectedValueException(sprintf('The column %s is a text array of locales, got "%s".', $column, $literal));
        }

        return array_map(static fn (string $locale): Locale => new Locale($locale), explode(',', $match[1]));
    }

    /**
     * The text[] literal of a locale set, or null for every locale.
     *
     * @param  list<Locale>|null  $locales
     */
    public static function literal(?array $locales): ?string
    {
        return $locales === null ? null : '{'.implode(',', array_map(static fn (Locale $locale): string => $locale->value, $locales)).'}';
    }

    private static function value(object $row, string $column): mixed
    {
        return property_exists($row, $column) ? $row->{$column} : throw new UnexpectedValueException(sprintf('A row has the column %s.', $column));
    }
}
