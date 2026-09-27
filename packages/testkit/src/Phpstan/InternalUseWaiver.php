<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * An explicit ignore of cboxCms.internalUse: a line of $file that PHPStan's own ignore comments
 * mark with the identifier, and how many times they name it there. Each naming hides one error.
 */
#[Internal]
final readonly class InternalUseWaiver
{
    public function __construct(
        public string $file,
        public int $line,
        public int $count,
    ) {}
}
