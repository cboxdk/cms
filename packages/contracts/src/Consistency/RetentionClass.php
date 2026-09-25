<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Consistency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How long a stored receipt is kept (PRD 8.4, and the receipts row in PRD 4).
 */
#[Experimental]
enum RetentionClass: string
{
    /**
     * Receipts that are evidence, such as withdrawal, redaction, erasure and release bundles.
     * They are kept by policy, not by a fixed period.
     */
    case Evidence = 'evidence';

    /** Every other receipt, kept for STANDARD_DAYS. */
    case Standard = 'standard';

    public const int STANDARD_DAYS = 7;

    /**
     * The fixed retention in days, or null when a policy decides.
     */
    public function days(): ?int
    {
        return match ($this) {
            self::Evidence => null,
            self::Standard => self::STANDARD_DAYS,
        };
    }
}
