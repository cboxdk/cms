<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The code of a reason, such as "legal_request" or "factual_error": lowercase snake_case of at
 * most 63 bytes. Each command that requires a reason defines its codes. Unlike the free
 * text, the code is stored in the audit chain and in events (PRD 6.1, 12.12).
 */
#[Experimental]
final readonly class ReasonCode
{
    private const string PATTERN = '/\A[a-z][a-z0-9]*(_[a-z0-9]+)*\z/';

    public function __construct(public string $value)
    {
        if (strlen($value) > 63 || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidEnvelope::reasonCode($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
