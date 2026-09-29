<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * An exact decimal number, as a decimal field holds it, kept as text so no digit is lost to a
 * float. The input is an optional minus sign, digits, and optionally a point and digits, such as
 * "-12.50". The value is canonical: no leading zeros before the point, no trailing zeros after it,
 * no point without digits after it, and "0" never negative, so "012.50" is "12.5".
 */
#[Experimental]
final readonly class DecimalValue implements FieldValue
{
    private const string PATTERN = '/\A(-?)([0-9]+)(?:\.([0-9]+))?\z/';

    public string $value;

    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value, $parts) !== 1) {
            throw InvalidFieldValue::decimal($value);
        }

        $whole = ltrim($parts[2], '0');
        $fraction = rtrim($parts[3] ?? '', '0');
        $digits = ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);

        $this->value = ($parts[1] === '-' && $digits !== '0' ? '-' : '').$digits;
    }

    #[Override]
    public function equals(FieldValue $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }
}
