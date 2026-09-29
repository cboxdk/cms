<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cache;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A dependency key, fragment key, fragment or purge that is not in its form.
 */
#[Experimental]
final class InvalidCacheValue extends InvalidArgumentException
{
    /** Input longer than this is cut in the message. */
    private const int SHOWN = 64;

    public static function dependencyKey(string $value): self
    {
        return new self(sprintf(
            'A dependency key is "e-" or "n-" and a UUIDv7 in lower case, got "%s".',
            self::shown($value),
        ));
    }

    public static function fragmentKey(string $value): self
    {
        return new self(sprintf(
            'A fragment key is 1 to %d visible ASCII characters, without spaces, got "%s".',
            FragmentKey::MAX_LENGTH,
            self::shown($value),
        ));
    }

    public static function tooManyDependencies(int $count): self
    {
        return new self(sprintf(
            'A fragment depends on at most %d keys (PRD 9.6), got %d.',
            Fragment::MAX_DEPENDENCIES,
            $count,
        ));
    }

    public static function fragmentExpired(FragmentKey $key, DateTimeImmutable $validUntil, DateTimeImmutable $now): self
    {
        return new self(sprintf(
            'The fragment "%s" was valid until %s, which is not after the Clock\'s time %s.',
            self::shown($key->value),
            $validUntil->format('Y-m-d\TH:i:s.uP'),
            $now->format('Y-m-d\TH:i:s.uP'),
        ));
    }

    public static function fenceEnded(DependencyKey $key, DateTimeImmutable $fenceUntil, DateTimeImmutable $now): self
    {
        return new self(sprintf(
            'The purge fence of "%s" ends at %s, which is not after the Clock\'s time %s.',
            $key->toString(),
            $fenceUntil->format('Y-m-d\TH:i:s.uP'),
            $now->format('Y-m-d\TH:i:s.uP'),
        ));
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > self::SHOWN ? substr($value, 0, self::SHOWN).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}
