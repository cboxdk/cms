<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The name and version of an event, such as variant.released version 1 (PRD 7.2, 7.13).
 *
 * The name is dot-separated snake_case segments, at least two, at most MAX_NAME_LENGTH
 * characters. The version is the version of the event's payload, from 1, and a payload that
 * changes shape gets the next one. An event class gives its type in Event::type(), so no event
 * name exists without its class (GUARDRAILS 2.4); the log stores both, and reads them back as an
 * EventType.
 */
#[Experimental]
final readonly class EventType
{
    public const int MAX_NAME_LENGTH = 63;

    public const string NAME_PATTERN = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+\z/';

    public function __construct(
        public string $name,
        public int $version,
    ) {
        if (strlen($name) > self::MAX_NAME_LENGTH || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw InvalidEvent::typeName();
        }

        if ($version < 1) {
            throw InvalidEvent::typeVersion($version);
        }
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name && $this->version === $other->version;
    }
}
