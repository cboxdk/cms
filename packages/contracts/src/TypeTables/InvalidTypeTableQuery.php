<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Schema\TypeName;
use InvalidArgumentException;

/**
 * A type table query, filter, order or cursor that breaks its rules, or that names a type, a
 * column or a use of a column its type does not allow (PRD 8.8: filters only on filterable fields,
 * sorting only on sortable fields).
 */
#[Experimental]
final class InvalidTypeTableQuery extends InvalidArgumentException
{
    /** Input longer than this is cut in the message. */
    private const int SHOWN = 64;

    public static function column(string $column): self
    {
        return new self(sprintf(
            'A column of a type table is a lowercase letter followed by lowercase letters, digits and underscores, at most 63 characters, got "%s".',
            self::shown($column),
        ));
    }

    public static function valueCount(FilterOperator $operator, int $count): self
    {
        $wanted = match (true) {
            $operator->takesOneValue() => 'exactly one value',
            $operator->takesValues() => 'one or more values',
            default => 'no value',
        };

        return new self(sprintf('The filter operator %s takes %s, got %d.', $operator->value, $wanted, $count));
    }

    public static function value(string $column, string $kind): self
    {
        return new self(sprintf(
            'A filter or cursor value is text, an integer, a decimal, a boolean, a date or a date-time, but the one for %s is a %s.',
            $column,
            $kind,
        ));
    }

    public static function limit(int $limit): self
    {
        return new self(sprintf('A page holds 1 to %d rows, got %d.', TypeTableQuery::MAX_LIMIT, $limit));
    }

    public static function repeatedOrder(string $column): self
    {
        return new self(sprintf('A query orders by each column once, but orders by %s twice.', $column));
    }

    public static function cursor(): self
    {
        return new self('A cursor holds a value for each column of the query\'s order, in the same order, and continues only a query with that order.');
    }

    public static function unknownType(TypeName $type): self
    {
        return new self(sprintf('The installation has no type %s.', $type->value));
    }

    public static function unknownColumn(TypeName $type, string $column): self
    {
        return new self(sprintf('The type %s has no field with the column %s.', $type->value, $column));
    }

    public static function notFilterable(TypeName $type, string $column): self
    {
        return new self(sprintf('The field of %s in the column %s is not filterable: its blueprint does not declare it filterable, so it has no index.', $type->value, $column));
    }

    public static function notSortable(TypeName $type, string $column): self
    {
        return new self(sprintf('The field of %s in the column %s is not sortable: its blueprint does not declare it sortable, so it has no index.', $type->value, $column));
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > self::SHOWN ? substr($value, 0, self::SHOWN).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}
