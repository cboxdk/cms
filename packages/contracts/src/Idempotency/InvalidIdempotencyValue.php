<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * An idempotency key, scope or content hash that is not in its form.
 */
#[Experimental]
final class InvalidIdempotencyValue extends InvalidArgumentException
{
    /** Input longer than this is cut in the message. */
    private const int SHOWN = 64;

    public static function key(string $value): self
    {
        return new self(sprintf(
            'An idempotency key is 1 to %d visible ASCII characters, without spaces, got "%s".',
            IdempotencyKey::MAX_LENGTH,
            self::shown($value),
        ));
    }

    public static function principal(PrincipalKind $kind, string $value): self
    {
        return new self(sprintf(
            'An idempotency scope names its %s with 1 to %d visible ASCII characters, without spaces, got "%s".',
            $kind->value,
            IdempotencyScope::MAX_PRINCIPAL_LENGTH,
            self::shown($value),
        ));
    }

    public static function commandType(string $value): self
    {
        return new self(sprintf(
            'An idempotency scope names a command type as dot-separated snake_case segments, for example "entry.release", got "%s".',
            self::shown($value),
        ));
    }

    public static function contentHash(string $value): self
    {
        return new self(sprintf(
            'A content hash is a SHA-256 digest as 64 hex digits, got "%s".',
            self::shown($value),
        ));
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > self::SHOWN ? substr($value, 0, self::SHOWN).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}
