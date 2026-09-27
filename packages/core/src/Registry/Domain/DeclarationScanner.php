<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;

/**
 * Finds the classes declared with #[Action], #[Command] and #[Hook] in the scan roots. Reflection
 * is allowed here, at build time, and nowhere at run time (GUARDRAILS 2.2).
 *
 * A scan never throws for a problem in the scanned code; it reports the problem in the Discovery,
 * so a build lists all of them at once.
 */
#[Internal]
interface DeclarationScanner
{
    public function scan(ScanRoots $roots): Discovery;
}
