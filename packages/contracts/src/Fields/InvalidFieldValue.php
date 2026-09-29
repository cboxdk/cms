<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A field handle, a namespace or a field value that breaks its invariants.
 */
#[Experimental]
final class InvalidFieldValue extends InvalidArgumentException
{
    public static function handle(string $value): self
    {
        return new self(sprintf(
            'A field handle is lowercase snake_case of at most %d bytes without a double underscore, and neither "ext" nor starting with "cms_", got "%s".',
            63,
            self::shown($value),
        ));
    }

    public static function fieldNamespace(string $value): self
    {
        return new self(sprintf(
            'A field namespace is a lowercase letter followed by at most 19 lowercase letters and digits, and not "ext", got "%s".',
            self::shown($value),
        ));
    }

    public static function notUtf8(): self
    {
        return new self('A text value must be valid UTF-8.');
    }

    public static function decimal(string $value): self
    {
        return new self(sprintf(
            'A decimal value is an optional minus sign, digits, and optionally a point and digits, such as "-12.50", got "%s".',
            self::shown($value),
        ));
    }

    public static function date(string $value): self
    {
        return new self(sprintf(
            'A date value is a real date as YYYY-MM-DD from year 0001, such as "2026-09-29", got "%s".',
            self::shown($value),
        ));
    }

    public static function mapKey(string $key): self
    {
        return new self(sprintf(
            'A map key is 1 to %d bytes of UTF-8, got "%s".',
            255,
            self::shown($key),
        ));
    }

    public static function duplicateMapKey(string $key): self
    {
        return new self(sprintf('The key "%s" appears twice in one map value.', self::shown($key)));
    }

    public static function duplicateHandle(FieldHandle $handle): self
    {
        return new self(sprintf('The field "%s" appears twice in one field map.', $handle->value));
    }

    public static function duplicateNamespace(FieldNamespace $namespace): self
    {
        return new self(sprintf('The extension namespace "%s" appears twice in one set of field values.', $namespace->value));
    }

    /**
     * A required field that is absent or holds NullValue, at its path, such as "supplier.company".
     */
    public static function missing(string $path): self
    {
        return new self(sprintf('The field "%s" is required and holds no value.', self::shown($path)));
    }

    /**
     * A field whose value is of another kind than its field type holds.
     */
    public static function kind(string $path, string $expected, FieldValue $actual): self
    {
        $class = strrchr($actual::class, '\\');

        return new self(sprintf(
            'The field "%s" holds %s, expected %s.',
            self::shown($path),
            $class === false ? $actual::class : substr($class, 1),
            $expected,
        ));
    }

    /**
     * A select field whose text is none of its options.
     */
    public static function choice(string $path, string $value): self
    {
        return new self(sprintf('The field "%s" holds "%s", which is not one of its options.', self::shown($path), self::shown($value)));
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > 64 ? substr($value, 0, 64).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}
