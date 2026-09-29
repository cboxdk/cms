<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A decimal number written as text, as the rules `decimal`, `min` and `max` read it: an optional
 * minus sign, digits, and optionally a point and digits, such as `-12.50`. It keeps the digits
 * before the point without leading zeros (`0` for none) and the digits after it without trailing
 * zeros, so `007.50` and `7.5` are the same number, and never a negative zero.
 */
#[Internal]
final readonly class DecimalNumber
{
    private const string PATTERN = '/\A(-?)([0-9]+)(?:\.([0-9]+))?\z/';

    private function __construct(
        public bool $negative,
        public string $whole,
        public string $fraction,
    ) {}

    /**
     * The number $value writes, or null when it writes none.
     */
    public static function parse(string $value): ?self
    {
        if (preg_match(self::PATTERN, $value, $parts) !== 1) {
            return null;
        }

        $whole = ltrim($parts[2], '0');
        $fraction = rtrim($parts[3] ?? '', '0');
        $whole = $whole === '' ? '0' : $whole;

        return new self($parts[1] === '-' && ($whole !== '0' || $fraction !== ''), $whole, $fraction);
    }

    /**
     * Whether a column numeric($precision, $scale) holds the number without rounding: at most
     * $precision - $scale digits before the point and at most $scale after it.
     */
    public function fits(int $precision, int $scale): bool
    {
        return strlen($this->fraction) <= $scale && ($this->whole === '0' ? 0 : strlen($this->whole)) <= $precision - $scale;
    }

    /**
     * -1, 0 or 1 as this number is less than, equal to or greater than $other.
     */
    public function compare(self $other): int
    {
        $sign = $this->sign();
        $otherSign = $other->sign();

        if ($sign !== $otherSign) {
            return $sign <=> $otherSign;
        }

        $byLength = strlen($this->whole) <=> strlen($other->whole);

        if ($byLength !== 0) {
            return $sign * $byLength;
        }

        $width = max(strlen($this->fraction), strlen($other->fraction));

        return $sign * (strcmp($this->whole.str_pad($this->fraction, $width, '0'), $other->whole.str_pad($other->fraction, $width, '0')) <=> 0);
    }

    private function sign(): int
    {
        if ($this->whole === '0' && $this->fraction === '') {
            return 0;
        }

        return $this->negative ? -1 : 1;
    }
}
