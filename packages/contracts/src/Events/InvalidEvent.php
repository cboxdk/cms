<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A value of an event that is not in its form: an event type, an aggregate, an id, a hash, a field
 * of the payload's data, or a stored event read back from the log.
 *
 * The message never repeats a string it refuses. A refused string may be text from content, which
 * an event, and so the log line of its error, never carries (PRD 6.5 invariant 10).
 */
#[Experimental]
final class InvalidEvent extends InvalidArgumentException
{
    public static function typeName(): self
    {
        return new self('An event type name is dot-separated snake_case segments, at least two, such as "variant.released", at most 63 characters.');
    }

    public static function typeVersion(int $version): self
    {
        return new self(sprintf('An event type version starts at 1, got %d.', $version));
    }

    public static function aggregateType(): self
    {
        return new self(sprintf('An aggregate type is snake_case, such as "entry" or "variant", at most %d characters.', AggregateType::MAX_LENGTH));
    }

    public static function aggregateVersion(int $version): self
    {
        return new self(sprintf('An aggregate version starts at 1, got %d.', $version));
    }

    public static function identifier(int $length): self
    {
        return new self(sprintf(
            'An id in an event is 1 to %d visible ASCII characters without spaces; got a string of %d bytes that is not. An event carries ids, never text (PRD 6.5 invariant 10).',
            EventIdentifier::MAX_LENGTH,
            $length,
        ));
    }

    public static function textHash(): self
    {
        return new self('A text hash is a SHA-256 digest as 64 hex digits.');
    }

    public static function enumValue(int $length): self
    {
        return new self(sprintf(
            'The string value of an enum case in an event is a word of 1 to 63 letters, digits, "_", ".", ":" or "-"; got a string of %d bytes that is not. An event never carries text (PRD 6.5 invariant 10).',
            $length,
        ));
    }

    public static function fieldName(): self
    {
        return new self(sprintf('A field of event data is named in snake_case, at most %d characters.', EventData::MAX_NAME_LENGTH));
    }

    public static function duplicateField(string $name): self
    {
        return new self(sprintf('The event data already has a field "%s".', $name));
    }

    public static function missingField(string $name): self
    {
        return new self(sprintf('The event data has no field "%s".', $name));
    }

    public static function datumKind(DatumKind $expected, DatumKind $actual): self
    {
        return new self(sprintf('The event datum is of kind %s, not %s.', $actual->value, $expected->value));
    }

    public static function position(int $xid, int $eventId): self
    {
        return new self(sprintf('An event position has a transaction id and an event id of 0 or more, got (%d, %d).', $xid, $eventId));
    }

    public static function stored(string $reason): self
    {
        return new self('A stored event cannot be read: '.$reason);
    }
}
