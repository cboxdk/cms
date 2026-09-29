<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The model an agent's or an ingestion's output came from, and its version (PRD 5.5), each 1 to
 * 255 bytes of UTF-8 without control characters.
 */
#[Experimental]
final readonly class GenerationModel
{
    public function __construct(
        public string $name,
        public string $version,
    ) {
        if (! EnvelopeText::isLine($name, 255)) {
            throw InvalidEnvelope::provenanceText('model name', $name);
        }

        if (! EnvelopeText::isLine($version, 255)) {
            throw InvalidEnvelope::provenanceText('model version', $version);
        }
    }
}
