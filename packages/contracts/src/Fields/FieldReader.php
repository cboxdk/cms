<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use BackedEnum;
use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the fields of one owner, one extender or one group out of the kernel's generic field
 * values (PRD 11.12), each as the PHP value of its field type. The records that cms:generate
 * writes from the blueprints build themselves with it, so a record never holds a value of the
 * wrong kind.
 *
 * Each field type has two methods: one for a field that must hold a value, such as `text()`, and
 * one for a field that may be absent or hold NullValue, such as `textOrNull()`, which returns
 * null for both. A value of another kind than the field type holds throws InvalidFieldValue with
 * the field's path, such as "supplier.company", and so does a required field without a value.
 */
#[Experimental]
final readonly class FieldReader
{
    private function __construct(
        private FieldMap $fields,
        private string $path,
    ) {}

    /**
     * The reader of a map of fields. Null, the map of an extender that adds no fields to an entry,
     * reads as an empty map, in which every field is absent.
     */
    public static function of(?FieldMap $fields): self
    {
        return new self($fields ?? new FieldMap, '');
    }

    public function text(string $handle): string
    {
        return $this->textOrNull($handle) ?? throw InvalidFieldValue::missing($this->pathOf($handle));
    }

    public function textOrNull(string $handle): ?string
    {
        $value = $this->valueOf($handle);

        return match (true) {
            ! $value instanceof FieldValue => null,
            $value instanceof TextValue => $value->value,
            default => throw InvalidFieldValue::kind($this->pathOf($handle), 'TextValue', $value),
        };
    }

    public function integer(string $handle): int
    {
        return $this->integerOrNull($handle) ?? throw InvalidFieldValue::missing($this->pathOf($handle));
    }

    public function integerOrNull(string $handle): ?int
    {
        $value = $this->valueOf($handle);

        return match (true) {
            ! $value instanceof FieldValue => null,
            $value instanceof IntegerValue => $value->value,
            default => throw InvalidFieldValue::kind($this->pathOf($handle), 'IntegerValue', $value),
        };
    }

    /**
     * The decimal in its canonical form, as DecimalValue keeps it, such as "12.5".
     *
     * @return numeric-string
     */
    public function decimal(string $handle): string
    {
        return $this->decimalOrNull($handle) ?? throw InvalidFieldValue::missing($this->pathOf($handle));
    }

    /**
     * @return numeric-string|null
     */
    public function decimalOrNull(string $handle): ?string
    {
        $value = $this->valueOf($handle);

        if (! $value instanceof FieldValue) {
            return null;
        }

        if (! $value instanceof DecimalValue || ! is_numeric($value->value)) {
            throw InvalidFieldValue::kind($this->pathOf($handle), 'DecimalValue', $value);
        }

        return $value->value;
    }

    public function boolean(string $handle): bool
    {
        return $this->booleanOrNull($handle) ?? throw InvalidFieldValue::missing($this->pathOf($handle));
    }

    public function booleanOrNull(string $handle): ?bool
    {
        $value = $this->valueOf($handle);

        return match (true) {
            ! $value instanceof FieldValue => null,
            $value instanceof BooleanValue => $value->value,
            default => throw InvalidFieldValue::kind($this->pathOf($handle), 'BooleanValue', $value),
        };
    }

    /**
     * A calendar date as midnight UTC of the day.
     */
    public function date(string $handle): DateTimeImmutable
    {
        return $this->dateOrNull($handle) ?? throw InvalidFieldValue::missing($this->pathOf($handle));
    }

    public function dateOrNull(string $handle): ?DateTimeImmutable
    {
        $value = $this->valueOf($handle);

        if (! $value instanceof FieldValue) {
            return null;
        }

        if (! $value instanceof DateValue) {
            throw InvalidFieldValue::kind($this->pathOf($handle), 'DateValue', $value);
        }

        return DateTimeImmutable::createFromFormat('!Y-m-d', $value->value, new DateTimeZone('UTC'))
            ?: throw InvalidFieldValue::date($value->value);
    }

    /**
     * An instant in UTC.
     */
    public function dateTime(string $handle): DateTimeImmutable
    {
        return $this->dateTimeOrNull($handle) ?? throw InvalidFieldValue::missing($this->pathOf($handle));
    }

    public function dateTimeOrNull(string $handle): ?DateTimeImmutable
    {
        $value = $this->valueOf($handle);

        return match (true) {
            ! $value instanceof FieldValue => null,
            $value instanceof DateTimeValue => $value->value,
            default => throw InvalidFieldValue::kind($this->pathOf($handle), 'DateTimeValue', $value),
        };
    }

    /**
     * A list as the kernel holds it, such as the blocks of a rich text field (PRD 11.10).
     */
    public function list(string $handle): ListValue
    {
        return $this->listOrNull($handle) ?? throw InvalidFieldValue::missing($this->pathOf($handle));
    }

    public function listOrNull(string $handle): ?ListValue
    {
        $value = $this->valueOf($handle);

        return match (true) {
            ! $value instanceof FieldValue => null,
            $value instanceof ListValue => $value,
            default => throw InvalidFieldValue::kind($this->pathOf($handle), 'ListValue', $value),
        };
    }

    /**
     * The option of a select field, as the case of its enum whose value is the field's text.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    public function choice(string $handle, string $enum): BackedEnum
    {
        return $this->choiceOrNull($handle, $enum) ?? throw InvalidFieldValue::missing($this->pathOf($handle));
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    public function choiceOrNull(string $handle, string $enum): ?BackedEnum
    {
        $text = $this->textOrNull($handle);

        return $text === null ? null : $this->caseOf($handle, $enum, $text);
    }

    /**
     * The options of a select field that allows several, in the order the list holds them.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return list<T>
     */
    public function choices(string $handle, string $enum): array
    {
        return $this->choicesOrNull($handle, $enum) ?? throw InvalidFieldValue::missing($this->pathOf($handle));
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return list<T>|null
     */
    public function choicesOrNull(string $handle, string $enum): ?array
    {
        $list = $this->listOrNull($handle);

        if (! $list instanceof ListValue) {
            return null;
        }

        $cases = [];

        foreach ($list->items as $index => $item) {
            if (! $item instanceof TextValue) {
                throw InvalidFieldValue::kind($this->pathOf($handle).'.'.$index, 'TextValue', $item);
            }

            $cases[] = $this->caseOf($handle, $enum, $item->value);
        }

        return $cases;
    }

    /**
     * The reader of a group field's fields, whose paths start with the group's.
     */
    public function group(string $handle): self
    {
        return $this->groupOrNull($handle) ?? throw InvalidFieldValue::missing($this->pathOf($handle));
    }

    public function groupOrNull(string $handle): ?self
    {
        $value = $this->valueOf($handle);

        return match (true) {
            ! $value instanceof FieldValue => null,
            $value instanceof GroupValue => new self($value->fields, $this->pathOf($handle)),
            default => throw InvalidFieldValue::kind($this->pathOf($handle), 'GroupValue', $value),
        };
    }

    /**
     * The readers of the items of a repeated group field, in order; an item's paths start with
     * the group's and its index, such as "dimensions.0".
     *
     * @return list<self>
     */
    public function groups(string $handle): array
    {
        return $this->groupsOrNull($handle) ?? throw InvalidFieldValue::missing($this->pathOf($handle));
    }

    /**
     * @return list<self>|null
     */
    public function groupsOrNull(string $handle): ?array
    {
        $list = $this->listOrNull($handle);

        if (! $list instanceof ListValue) {
            return null;
        }

        $items = [];

        foreach ($list->items as $index => $item) {
            $path = $this->pathOf($handle).'.'.$index;

            if (! $item instanceof GroupValue) {
                throw InvalidFieldValue::kind($path, 'GroupValue', $item);
            }

            $items[] = new self($item->fields, $path);
        }

        return $items;
    }

    /**
     * The field's value, or null when the field is absent or holds NullValue.
     */
    private function valueOf(string $handle): ?FieldValue
    {
        $value = $this->fields->get(new FieldHandle($handle));

        return $value instanceof NullValue ? null : $value;
    }

    private function pathOf(string $handle): string
    {
        return $this->path === '' ? $handle : $this->path.'.'.$handle;
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private function caseOf(string $handle, string $enum, string $text): BackedEnum
    {
        return $enum::tryFrom($text) ?? throw InvalidFieldValue::choice($this->pathOf($handle), $text);
    }
}
