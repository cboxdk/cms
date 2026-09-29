<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Codecs\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A decimal number as text (GUARDRAILS 2.2), so no digit is lost to a float: an optional minus
 * sign, digits, and optionally a point and digits, such as "-12.50". It keeps the sign, the digits
 * before the point without leading zeros ("0" for none) and the digits after it without trailing
 * zeros, so "012.50" and "12.5" are the same number.
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
     * The number $value writes, or null when it is not a decimal number.
     */
    public static function parse(string $value): ?self
    {
        if (preg_match(self::PATTERN, $value, $match) !== 1) {
            return null;
        }

        $whole = ltrim($match[2], '0');
        $fraction = rtrim($match[3] ?? '', '0');
        $whole = $whole === '' ? '0' : $whole;

        return new self($match[1] === '-' && ($whole !== '0' || $fraction !== ''), $whole, $fraction);
    }

    /**
     * The form of the number in a column numeric($precision, $scale): no leading zeros, exactly
     * $scale digits after the point (no point for scale 0), and never "-0". Null when the number has
     * more than $precision - $scale digits before the point, or more than $scale digits after it,
     * because Postgres would refuse or round it.
     *
     * @return numeric-string|null
     */
    public function fixed(int $precision, int $scale): ?string
    {
        if (strlen($this->fraction) > $scale || ($this->whole === '0' ? 0 : strlen($this->whole)) > $precision - $scale) {
            return null;
        }

        $fixed = ($this->negative ? '-' : '').$this->whole.($scale === 0 ? '' : '.'.str_pad($this->fraction, $scale, '0'));

        return is_numeric($fixed) ? $fixed : null;
    }

    /**
     * -1, 0 or 1 as this number is less than, equal to or greater than $other. Zero is never
     * negative, and the digits after the point have no trailing zeros, so two numbers of one sign
     * with as many digits before the point compare as their digits do.
     */
    public function compare(self $other): int
    {
        if ($this->negative !== $other->negative) {
            return $this->negative ? -1 : 1;
        }

        $magnitude = strlen($this->whole) <=> strlen($other->whole);

        if ($magnitude === 0) {
            $magnitude = strcmp($this->whole.$this->fraction, $other->whole.$other->fraction) <=> 0;
        }

        return $this->negative ? -$magnitude : $magnitude;
    }
}
