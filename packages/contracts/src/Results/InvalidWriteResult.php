<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use InvalidArgumentException;

/**
 * A write result, a catalog error or a field path that breaks its invariants.
 */
#[Experimental]
final class InvalidWriteResult extends InvalidArgumentException
{
    public static function rejectedWithoutErrors(): self
    {
        return new self('A rejected write names at least one catalog error.');
    }

    public static function unexpectedErrors(Outcome $outcome): self
    {
        return new self(sprintf('A %s write has no errors; only a rejected one does.', $outcome->value));
    }

    public static function dryRunWithoutPlan(): self
    {
        return new self('A dry run returns the plan it computed, with its blast radius and diff.');
    }

    public static function unexpectedPlan(Outcome $outcome): self
    {
        return new self(sprintf('A %s write returns no plan; only a dry run does.', $outcome->value));
    }

    public static function unreadAggregate(string $aggregateKey): self
    {
        return new self(sprintf('A mutation changes the aggregate "%s", which the write did not read.', $aggregateKey));
    }

    public static function changeWithoutMutations(string $aggregateKey): self
    {
        return new self(sprintf('A change of the aggregate "%s" counts at least one mutation.', $aggregateKey));
    }

    public static function aggregateKey(string $value): self
    {
        return new self(sprintf('A version change names the aggregate\'s key, "<kind>:<id>", got "%s".', self::shown($value)));
    }

    public static function versionChange(string $aggregateKey): self
    {
        return new self(sprintf('A change of the aggregate "%s" gives it the version after the one read: one higher, or the first for one the write creates.', $aggregateKey));
    }

    public static function repeatedAggregate(string $aggregateKey): self
    {
        return new self(sprintf('A dry run summary names the aggregate "%s" once.', $aggregateKey));
    }

    public static function count(string $kind, int $count): self
    {
        return new self(sprintf('A count of a blast radius names a kind and is at least 1 for an aggregate kind, 0 for mutations, got %d for "%s".', $count, self::shown($kind)));
    }

    public static function repeatedKind(string $kind): self
    {
        return new self(sprintf('A blast radius counts the aggregate kind "%s" once.', self::shown($kind)));
    }

    public static function emptyMessage(ErrorCode $code): self
    {
        return new self(sprintf('A catalog error with the code %s needs its cause in plain language.', $code->value));
    }

    public static function pathSegment(string|int $segment): self
    {
        return new self(is_int($segment)
            ? sprintf('A field path index is 0 or more, got %d.', $segment)
            : sprintf('A field path name is a letter or an underscore followed by letters, digits and underscores, got "%s".', self::shown($segment)));
    }

    public static function path(string $value): self
    {
        return new self(sprintf(
            'A field path is a name followed by names after dots and indexes in brackets, such as "blocks[2].text", got "%s".',
            self::shown($value),
        ));
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > 64 ? substr($value, 0, 64).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}
