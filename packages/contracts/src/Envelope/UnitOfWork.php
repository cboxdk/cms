<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The unit of work an internal issuer runs a command for, such as the event a subscriber handles
 * or the chunk a seed loads: "event:<event id>:<step>" or "seed:<seed>:<chunk>". The internal
 * issuer's idempotency key is derived from it, so running the same unit again, after a crash or a
 * retry, replays the first result instead of committing twice. One unit that issues several
 * commands of one type names each with its own unit.
 *
 * It is opaque: 1 to 255 visible ASCII characters, compared exactly.
 */
#[Experimental]
final readonly class UnitOfWork
{
    public function __construct(public string $value)
    {
        if (! EnvelopeText::isToken($value, 255)) {
            throw InvalidEnvelope::unitOfWork($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
