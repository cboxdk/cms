<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The id that ties one call together across HTTP, MCP, CLI, jobs and sidecars (PRD 6.1), such as
 * a trace id. The surface takes it from the transport or makes one. It is opaque: 1 to 128
 * visible ASCII characters, compared exactly.
 */
#[Experimental]
final readonly class CorrelationId
{
    public function __construct(public string $value)
    {
        if (! EnvelopeText::isToken($value, 128)) {
            throw InvalidEnvelope::correlationId($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
