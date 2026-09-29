<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A rule of the runtime validators (PRD 11.12), in the type descriptor's own vocabulary, so a
 * validator for PHP and one for TypeScript are generated from the same rules. Each rule checks the
 * value of one field; the rules of the fields in a group are the nested fields' own.
 */
#[Internal]
enum ValidationRuleName: string
{
    /** The value is present and not null. */
    case Required = 'required';

    /** The value may be absent or null; the other rules check it when it is present. */
    case Nullable = 'nullable';

    /** A string. */
    case String = 'string';

    /** An integer. */
    case Integer = 'integer';

    /** A decimal number as a string, with at most the arguments' precision and scale. */
    case Decimal = 'decimal';

    /** A boolean. */
    case Boolean = 'boolean';

    /** A full date of RFC 3339, `YYYY-MM-DD`. */
    case Date = 'date';

    /** A date-time of RFC 3339 with its offset. */
    case Datetime = 'datetime';

    /** A map of the nested fields' handles to their values. */
    case Object = 'object';

    /** A list. */
    case List = 'list';

    /** A list of Portable Text blocks (PRD 11.10). */
    case PortableText = 'portable_text';

    /** At least the argument's number of characters. */
    case MinLength = 'min_length';

    /** At most the argument's number of characters. */
    case MaxLength = 'max_length';

    /** At least the argument, compared as the field's type: a number, a date or an instant. */
    case Min = 'min';

    /** At most the argument, compared as the field's type: a number, a date or an instant. */
    case Max = 'max';

    /** A text in the argument's format: `email` or `url`. */
    case Format = 'format';

    /** One of the arguments. */
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

    /** Portable Text whose lists are only of the arguments' kinds. */
    case Lists = 'lists';

    /** Portable Text whose links are only of the arguments' kinds. */
    case Links = 'links';
}
