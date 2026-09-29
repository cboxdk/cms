<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A rule of the runtime validators (PRD 11.8, 11.12), the vocabulary cms:generate writes a type's
 * rules in. A field's rules start with its type rule, which says what kind of value the field
 * holds; the others narrow it. Whether a value is required is the field's Presence, not a rule.
 */
#[Experimental]
enum RuleName: string
{
    /** Text in UTF-8. */
    case String = 'string';

    /** A whole number. */
    case Integer = 'integer';

    /** A decimal number written as a string, with at most the arguments' precision and scale. */
    case Decimal = 'decimal';

    /** true or false. */
    case Boolean = 'boolean';

    /** A full date of RFC 3339, `YYYY-MM-DD`. */
    case Date = 'date';

    /** A date-time of RFC 3339 with its offset, such as `2026-09-29T12:00:00Z`. */
    case Datetime = 'datetime';

    /** An object of the field's nested fields, by handle. */
    case Object = 'object';

    /** A list: of the field's nested fields' objects when it has nested fields, else of options. */
    case List = 'list';

    /** A list of Portable Text blocks (PRD 11.10). */
    case PortableText = 'portable_text';

    /** Text of at least the argument's number of characters. */
    case MinLength = 'min_length';

    /** Text of at most the argument's number of characters. */
    case MaxLength = 'max_length';

    /** At least the argument, compared as the type rule's kind: a number, a date or an instant. */
    case Min = 'min';

    /** At most the argument, compared as the type rule's kind: a number, a date or an instant. */
    case Max = 'max';

    /** Text in the argument's format: `email` or `url`. */
    case Format = 'format';

    /** Text that is one of the arguments. */
    case In = 'in';

    /** A list whose items are each one of the arguments. */
    case ItemsIn = 'items_in';

    /** A list without an item twice. */
    case Distinct = 'distinct';

    /** A list of at least the argument's number of items. */
    case MinItems = 'min_items';

    /** A list of at most the argument's number of items. */
    case MaxItems = 'max_items';

    /** Portable Text whose blocks have only the arguments' styles. */
    case Styles = 'styles';

    /** Portable Text whose spans have only the arguments' decorator marks. */
    case Marks = 'marks';

    /** Portable Text whose list items are only of the arguments' kinds. */
    case Lists = 'lists';

    /** Portable Text whose links are only of the arguments' kinds: `url`. */
    case Links = 'links';

    /**
     * The formats of Format.
     *
     * @var list<string>
     */
    public const array FORMATS = ['email', 'url'];

    /**
     * Whether the rule is a type rule, the first rule of a field.
     */
    public function isType(): bool
    {
        return in_array($this, [
            self::String,
            self::Integer,
            self::Decimal,
            self::Boolean,
            self::Date,
            self::Datetime,
            self::Object,
            self::List,
            self::PortableText,
        ], true);
    }

    /**
     * The rules that may follow this type rule, each at most once. Empty for a rule that is not a
     * type rule.
     *
     * @return list<self>
     */
    public function modifiers(): array
    {
        return match ($this) {
            self::String => [self::MinLength, self::MaxLength, self::Format, self::In],
            self::Integer, self::Decimal, self::Date, self::Datetime => [self::Min, self::Max],
            self::List => [self::Distinct, self::ItemsIn, self::MinItems, self::MaxItems],
            self::PortableText => [self::Styles, self::Marks, self::Lists, self::Links],
            default => [],
        };
    }
}
