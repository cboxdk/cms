<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;

/**
 * The kinds of value a generated DTO holds and its codec reads and writes (GUARDRAILS 2.2). Each
 * has one JSON form, fixed by the core's JsonValues, and the rules of the descriptor's vocabulary it
 * takes (ValidationRuleName).
 */
#[Internal]
enum CodecKind: string
{
    /** A string; rules `string`, `min_length`, `max_length` and `format`. */
    case Text = 'text';

    /** A JSON integer; rules `integer`, `min` and `max`. */
    case Integer = 'integer';

    /** A decimal in a string; rules `decimal` (precision and scale), `min` and `max`. */
    case Decimal = 'decimal';

    /** A JSON boolean; rule `boolean`. */
    case Boolean = 'boolean';

    /** `YYYY-MM-DD`; rules `date`, `min` and `max`. */
    case Date = 'date';

    /** RFC 3339 with an offset, written in UTC; rules `datetime`, `min` and `max`. */
    case Datetime = 'datetime';

    /** One of a list of strings; rule `in`. */
    case Choice = 'choice';

    /** A list of Portable Text blocks; rules `portable_text`, `styles`, `marks`, `lists` and `links`. */
    case PortableText = 'portable_text';

    /** An object of the properties of a CodecObject; rule `object`. */
    case Object = 'object';

    /** A list of one kind of item; rules `list`, `distinct`, `min_items` and `max_items`. */
    case List = 'list';

    /** An id value object in its canonical string, parsed by its static fromString(). No rules. */
    case Id = 'id';

    /** A case of a backed enum, by its value. No rules. */
    case Enum = 'enum';

    /**
     * A value object of one string, made with `new` from the string and written from its `value`
     * property, whose constructor checks it. No rules.
     */
    case Value = 'value';

    /**
     * A value object of one integer, made with `new` from the integer and written from its `value`
     * property, whose constructor checks it, such as a version; rules `min` and `max`, which the
     * codec checks before the constructor does.
     */
    case IntegerValue = 'integer_value';

    /**
     * The fields of a revision of any type (GUARDRAILS 2.4), Cbox\Cms\Contracts\Fields\FieldValues, in
     * the input form the type's validator reads: an object of the owner's fields by handle, with an
     * extender's fields under `ext` and its namespace. A command that works for any type names no
     * type the codec could read the values with, so each value is read as JSON gives it, and the
     * kernel checks the fields against the type's schema at the write stage. No rules: the form is
     * fixed.
     */
    case Fields = 'fields';

    /**
     * A JSON object of another contract, such as a record of a type's record contract or a document
     * of another kernel schema, written and read by that contract's own generated codec and held as
     * a value object of the object's JSON text, made with `new` from the text and written from its
     * `value`, whose constructor checks that the text is one JSON object. The contract that holds it
     * checks only that it is an object; its own contract's codec and validator check its keys. No
     * rules.
     */
    case Document = 'document';

    /**
     * The rules a value of this kind takes, in the type descriptor's vocabulary. The emitters refuse
     * any other, so no rule of a blueprint is ever dropped without being checked.
     *
     * @return list<ValidationRuleName>
     */
    public function rules(): array
    {
        return match ($this) {
            self::Text => [ValidationRuleName::String, ValidationRuleName::MinLength, ValidationRuleName::MaxLength, ValidationRuleName::Format],
            self::Integer => [ValidationRuleName::Integer, ValidationRuleName::Min, ValidationRuleName::Max],
            self::Decimal => [ValidationRuleName::Decimal, ValidationRuleName::Min, ValidationRuleName::Max],
            self::Boolean => [ValidationRuleName::Boolean],
            self::Date => [ValidationRuleName::Date, ValidationRuleName::Min, ValidationRuleName::Max],
            self::Datetime => [ValidationRuleName::Datetime, ValidationRuleName::Min, ValidationRuleName::Max],
            self::Choice => [ValidationRuleName::In],
            self::PortableText => [ValidationRuleName::PortableText, ValidationRuleName::Styles, ValidationRuleName::Marks, ValidationRuleName::Lists, ValidationRuleName::Links],
            self::Object => [ValidationRuleName::Object],
            self::List => [ValidationRuleName::List, ValidationRuleName::Distinct, ValidationRuleName::MinItems, ValidationRuleName::MaxItems],
            self::IntegerValue => [ValidationRuleName::Min, ValidationRuleName::Max],
            self::Id, self::Enum, self::Value, self::Fields, self::Document => [],
        };
    }

    /**
     * Whether the PHP type of a value of this kind is a class, so a value that is not null is an
     * instance of it.
     */
    public function isClass(): bool
    {
        return match ($this) {
            self::Date, self::Datetime, self::PortableText, self::Object, self::Id, self::Enum, self::Value, self::IntegerValue, self::Fields, self::Document => true,
            default => false,
        };
    }
}
