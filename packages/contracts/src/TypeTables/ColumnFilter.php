<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\TextValue;

/**
 * One filter of a type table query (PRD 8.8): the column of a filterable field, the operator and
 * the values, bound as parameters. A value is text, an integer, a decimal, a boolean, a date or a
 * date-time, never null: null is tested with the operators `null` and `not_null`.
 */
#[Experimental]
final readonly class ColumnFilter
{
    /** @var list<FieldValue> */
    public array $values;

    /**
     * @throws InvalidTypeTableQuery
     */
    public function __construct(
        public string $column,
        public FilterOperator $operator,
        FieldValue ...$values,
    ) {
        TypeTableQuery::assertColumn($column);

        $count = count($values);

        $counted = match (true) {
            $operator->takesOneValue() => $count === 1,
            $operator->takesValues() => $count >= 1,
            default => $count === 0,
        };

        if (! $counted) {
            throw InvalidTypeTableQuery::valueCount($operator, $count);
        }

        foreach ($values as $value) {
            self::assertComparable($column, $value);
        }

        $this->values = array_values($values);
    }

    /**
     * @throws InvalidTypeTableQuery when the value is not text, an integer, a decimal, a boolean, a
     *                               date or a date-time
     */
    public static function assertComparable(string $column, FieldValue $value): void
    {
        if (! $value instanceof TextValue
            && ! $value instanceof IntegerValue
            && ! $value instanceof DecimalValue
            && ! $value instanceof BooleanValue
            && ! $value instanceof DateValue
            && ! $value instanceof DateTimeValue) {
            throw InvalidTypeTableQuery::value($column, $value::class);
        }
    }
}
