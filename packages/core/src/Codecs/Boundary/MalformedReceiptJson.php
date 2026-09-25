<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Codecs\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Throwable;
use UnexpectedValueException;

/**
 * JSON that ReceiptCodecV1 cannot decode into a receipt. The message names the path of the value,
 * such as "$.projections[1].state", and the cause is chained when there is one.
 */
#[Internal]
final class MalformedReceiptJson extends UnexpectedValueException
{
    /** Input longer than this is cut in the message. */
    private const int SHOWN = 64;

    public static function invalidJson(Throwable $previous): self
    {
        return new self(sprintf('A receipt is not valid JSON: %s', $previous->getMessage()), 0, $previous);
    }

    public static function unknownVersion(int $version): self
    {
        return new self(sprintf(
            'The receipt has codec_version %d. This codec reads version %d only.',
            $version,
            ReceiptCodecV1::VERSION,
        ));
    }

    public static function wrongType(string $path, string $expected, string $actual): self
    {
        return new self(sprintf('%s must be %s, got %s.', $path, $expected, $actual));
    }

    public static function missingField(string $path, string $field): self
    {
        return new self(sprintf('%s has no field "%s". Every field is required; an absent value is null.', $path, $field));
    }

    public static function unknownField(string $path, string $field): self
    {
        return new self(sprintf('%s has an unknown field "%s".', $path, self::shown($field)));
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function unknownValue(string $path, string $value, array $allowed): self
    {
        return new self(sprintf(
            '%s is "%s", which is not one of: %s.',
            $path,
            self::shown($value),
            implode(', ', $allowed),
        ));
    }

    public static function malformedId(string $path, Throwable $previous): self
    {
        return new self(sprintf('%s is not a changeset id: %s', $path, $previous->getMessage()), 0, $previous);
    }

    public static function malformedTime(string $path, string $value): self
    {
        return new self(sprintf(
            '%s is "%s". A time is UTC with microseconds, for example "2026-01-01T00:00:00.123456Z".',
            $path,
            self::shown($value),
        ));
    }

    public static function invalidReceipt(string $path, Throwable $previous): self
    {
        return new self(sprintf('%s is not a valid receipt: %s', $path, $previous->getMessage()), 0, $previous);
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > self::SHOWN ? substr($value, 0, self::SHOWN).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}
