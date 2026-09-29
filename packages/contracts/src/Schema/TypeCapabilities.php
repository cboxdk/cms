<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The capabilities a type declares in its blueprint (PRD 3.1, 5, 10.1): its history, its stages,
 * its localization and whether its entries have routes. The kernel decides from them, never from
 * the type's name, what it does with the type's entries (GUARDRAILS 2.4).
 */
#[Experimental]
final readonly class TypeCapabilities
{
    public function __construct(
        public History $history,
        public Stages $stages,
        public Localization $localization,
        public bool $routable,
    ) {}
}
