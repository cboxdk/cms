<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\TextValue;

/**
 * That an edge of a relation filter carries the value in its target's filterable attribute
 * (PRD 5.11): equality on the attribute, of text, a select's option, an integer, a boolean or a
 * date, which the edge keeps as its filter key, the column the attribute's partial index on
 * relations compares.
 */
#[Experimental]
final readonly class AttributeFilter
{
    /**
     * @throws InvalidTypeTableQuery for a value of another kind
     */
    public function __construct(
        public FieldHandle $attribute,
        public FieldValue $value,
    ) {
        if (! $value instanceof TextValue && ! $value instanceof IntegerValue && ! $value instanceof BooleanValue && ! $value instanceof DateValue) {
            throw InvalidTypeTableQuery::value($attribute->value, $value::class);
        }
    }

    /**
     * The value as the edge's filter key holds it (textOf()).
     */
    public function text(): string
    {
        return self::textOf($this->value) ?? throw InvalidTypeTableQuery::value($this->attribute->value, $this->value::class);
    }

    /**
     * A filterable attribute's value as the filter key of an edge holds it: the text, the
     * integer's digits, `true` or `false`, or the day `YYYY-MM-DD`; null for a value of another
     * kind, such as no value.
     */
    public static function textOf(FieldValue $value): ?string
    {
        return match (true) {
            $value instanceof TextValue => $value->value,
            $value instanceof IntegerValue => (string) $value->value,
            $value instanceof BooleanValue => $value->value ? 'true' : 'false',
            $value instanceof DateValue => $value->value,
            default => null,
        };
    }
}
