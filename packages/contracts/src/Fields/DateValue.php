<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * A calendar date without a time or a time zone, as a date field holds it: YYYY-MM-DD, a real date
 * from year 0001 to 9999, such as "2026-09-29".
 */
#[Experimental]
final readonly class DateValue implements FieldValue
{
    private const string PATTERN = '/\A(?<year>[0-9]{4})-(?<month>[0-9]{2})-(?<day>[0-9]{2})\z/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value, $parts) !== 1 || ! checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])) {
            throw InvalidFieldValue::date($value);
        }
    }

    #[Override]
    public function equals(FieldValue $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }
}
