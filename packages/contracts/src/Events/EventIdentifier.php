<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\Identifier;

/**
 * An id as an event carries it: the canonical string of an Identifier, 1 to MAX_LENGTH visible
 * ASCII characters without spaces (PRD 7.2).
 *
 * The form keeps text out of the log: a title or a sentence has spaces, so it is refused even when
 * a class hands it over as an id (PRD 6.5 invariant 10). The event log reads the ids of a stored
 * event back as EventIdentifiers; a subscriber parses one into its own id type, such as
 * ChangesetId::fromString($id->toString()).
 */
#[Experimental]
final readonly class EventIdentifier implements Identifier
{
    public const int MAX_LENGTH = 255;

    public const string PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidEvent::identifier(strlen($value));
        }
    }

    /**
     * The id's canonical string, checked for the form.
     */
    public static function of(Identifier $id): self
    {
        return $id instanceof self ? $id : new self($id->toString());
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(Identifier $other): bool
    {
        return $this->value === $other->toString();
    }
}
