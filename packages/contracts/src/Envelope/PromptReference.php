<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A reference to the prompt an agent ran with (PRD 5.5). The prompt itself can be large and hold
 * personal data, so it is stored as classified content under the same deletion rules as anything
 * else, and the changeset holds only this reference. 1 to 1024 bytes of UTF-8 without
 * control characters.
 */
#[Experimental]
final readonly class PromptReference
{
    public function __construct(public string $value)
    {
        if (! EnvelopeText::isLine($value, 1024)) {
            throw InvalidEnvelope::provenanceText('prompt reference', $value);
        }
    }
}
