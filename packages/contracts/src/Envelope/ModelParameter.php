<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One parameter the model ran with, such as "temperature" and "0.2" (PRD 5.5), each 1 to
 * 255 bytes of UTF-8 without control characters.
 */
#[Experimental]
final readonly class ModelParameter
{
    public function __construct(
        public string $name,
        public string $value,
    ) {
        if (! EnvelopeText::isLine($name, 255)) {
            throw InvalidEnvelope::provenanceText('parameter name', $name);
        }

        if (! EnvelopeText::isLine($value, 255)) {
            throw InvalidEnvelope::provenanceText('parameter value', $value);
        }
    }
}
