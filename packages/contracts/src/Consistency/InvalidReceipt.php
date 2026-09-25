<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * A receipt, a projection status or a projection name that breaks its invariants.
 */
#[Experimental]
final class InvalidReceipt extends InvalidArgumentException
{
    /** Input longer than this is cut in the message. */
    private const int SHOWN = 64;

    public static function missingChangeset(Outcome $outcome): self
    {
        return new self(sprintf(
            'A %s receipt needs a ChangesetId: the command committed a changeset.',
            $outcome->value,
        ));
    }

    public static function unexpectedChangeset(Outcome $outcome): self
    {
        return new self(sprintf(
            'A %s receipt has no ChangesetId: the command committed nothing.',
            $outcome->value,
        ));
    }

    public static function unexpectedProjections(Outcome $outcome): self
    {
        return new self(sprintf(
            'A %s receipt has no projection statuses: the command committed nothing.',
            $outcome->value,
        ));
    }

    public static function duplicateProjection(ProjectionName $projection): self
    {
        return new self(sprintf('The projection "%s" is listed twice in one receipt.', $projection->value));
    }

    public static function projectionName(string $value): self
    {
        return new self(sprintf(
            'A projection name is dot-separated snake_case segments of at most %d characters, for example "fragments" or "acme.search", got "%s".',
            ProjectionName::MAX_LENGTH,
            self::shown($value),
        ));
    }

    public static function missingAcknowledgement(ProjectionName $projection): self
    {
        return new self(sprintf('The acknowledged projection "%s" needs the time it acknowledged.', $projection->value));
    }

    public static function unexpectedAcknowledgement(ProjectionName $projection): self
    {
        return new self(sprintf('The pending projection "%s" has no acknowledgement time.', $projection->value));
    }

    public static function acknowledgedOutOfRange(ProjectionName $projection, DateTimeInterface $at): self
    {
        return new self(sprintf(
            'The projection "%s" acknowledged at %s. The time must be from 1970 to the end of 9999, in UTC.',
            $projection->value,
            $at->format(DateTimeInterface::RFC3339_EXTENDED),
        ));
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > self::SHOWN ? substr($value, 0, self::SHOWN).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}
