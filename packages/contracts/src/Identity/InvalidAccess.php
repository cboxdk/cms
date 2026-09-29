<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A node path, access region or access context that breaks its rules.
 */
#[Experimental]
final class InvalidAccess extends InvalidArgumentException
{
    /** Input longer than this is cut in the message. */
    private const int SHOWN = 64;

    public static function path(string $value): self
    {
        $cut = strlen($value) > self::SHOWN ? substr($value, 0, self::SHOWN).'...' : $value;

        return new self(sprintf(
            'A node path is labels of 1 to 1000 letters, digits, underscores and hyphens joined by dots, got "%s".',
            addcslashes($cut, "\0..\37\177..\377\"\\"),
        ));
    }

    public static function exceptionOutside(NodePath $path, NodePath $exception): self
    {
        return new self(sprintf('An exception of an access region lies below its path %s, but %s does not.', $path->value, $exception->value));
    }

    public static function nestedExceptions(NodePath $outer, NodePath $inner): self
    {
        return new self(sprintf('The exceptions of an access region are disjoint, but %s is repeated in or below %s.', $inner->value, $outer->value));
    }

    public static function overlappingRegions(NodePath $outer, NodePath $inner): self
    {
        return new self(sprintf('The access regions of a context are disjoint, but %s is at or below %s.', $inner->value, $outer->value));
    }

    public static function aboveCeiling(ClassificationAccess $access, ClassificationAccess $ceiling): self
    {
        return new self(sprintf(
            'The classification access of a context never exceeds its principal\'s ceiling, %s, got %s.',
            $ceiling->value,
            $access->value,
        ));
    }
}
