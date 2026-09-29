<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A source an agent or an ingestion used (PRD 5.5), such as a URL or a feed's object id. 1 to
 * 2048 bytes of UTF-8 without control characters.
 */
#[Experimental]
final readonly class SourceReference
{
    public function __construct(public string $value)
    {
        if (! EnvelopeText::isLine($value, 2048)) {
            throw InvalidEnvelope::provenanceText('source reference', $value);
        }
    }
}
