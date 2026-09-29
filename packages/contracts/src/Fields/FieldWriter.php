<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use BackedEnum;
use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns the PHP value of a field into the kernel's generic field value (PRD 11.12), the way
 * FieldReader reads it back: null into NullValue, and every other value into the value its field
 * type holds. The records that cms:generate writes from the blueprints convert themselves with it.
 */
#[Experimental]
final readonly class FieldWriter
{
    public static function text(?string $value): FieldValue
    {
        return $value === null ? new NullValue : new TextValue($value);
    }

    public static function integer(?int $value): FieldValue
    {
        return $value === null ? new NullValue : new IntegerValue($value);
    }

    /**
     * A decimal in the form DecimalValue takes, such as "-12.50"; it is kept in its canonical form.
     */
    public static function decimal(?string $value): FieldValue
    {
        return $value === null ? new NullValue : new DecimalValue($value);
    }

    public static function boolean(?bool $value): FieldValue
    {
        return $value === null ? new NullValue : new BooleanValue($value);
    }

    /**
     * The calendar date of the value in UTC.
     */
    public static function date(?DateTimeImmutable $value): FieldValue
    {
        return $value instanceof DateTimeImmutable ? new DateValue($value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d')) : new NullValue;
    }

    public static function dateTime(?DateTimeImmutable $value): FieldValue
    {
        return $value instanceof DateTimeImmutable ? new DateTimeValue($value) : new NullValue;
    }

    public static function list(?ListValue $value): FieldValue
    {
        return $value ?? new NullValue;
    }

    /**
     * The option of a select field as its text: the value of the enum case.
     */
    public static function choice(?BackedEnum $value): FieldValue
    {
        return $value instanceof BackedEnum ? new TextValue((string) $value->value) : new NullValue;
    }

    /**
     * The options of a select field that allows several, as a list of their texts in order.
     *
     * @param  list<BackedEnum>|null  $values
     */
    public static function choices(?array $values): FieldValue
    {
        return $values === null
            ? new NullValue
            : new ListValue(...array_map(static fn (BackedEnum $value): TextValue => new TextValue((string) $value->value), $values));
    }

    public static function group(?FieldMap $fields): FieldValue
    {
        return $fields instanceof FieldMap ? new GroupValue($fields) : new NullValue;
    }

    /**
     * The items of a repeated group field, each the map of its fields, in order.
     *
     * @param  list<FieldMap>|null  $items
     */
    public static function groups(?array $items): FieldValue
    {
        return $items === null
            ? new NullValue
            : new ListValue(...array_map(static fn (FieldMap $fields): GroupValue => new GroupValue($fields), $items));
    }
}
