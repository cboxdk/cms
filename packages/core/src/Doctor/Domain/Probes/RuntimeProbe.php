<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The versions of PHP and Laravel the process runs on.
 */
#[Internal]
interface RuntimeProbe
{
    /** Such as "8.5.10". */
    public function phpVersion(): string;

    /** Such as "13.4.0". */
    public function laravelVersion(): string;
}
