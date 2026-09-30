<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The bounds of one seed run: GUARDRAILS 4.3's scale data set has ten million entries, so a run
 * takes up to a hundred million, and an entry's index fits the milliseconds of its id.
 */
#[Internal]
final readonly class SeedRequestLimits
{
    public const int MAX_ENTRIES = 100_000_000;
}
