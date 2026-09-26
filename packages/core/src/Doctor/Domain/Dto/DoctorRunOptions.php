<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What one cms:doctor run checks: the runtime checks, and with $dev also the development checks.
 */
#[Experimental]
final readonly class DoctorRunOptions
{
    /**
     * @param  bool  $dev  also run the development checks: Node, Playwright and its Chromium
     */
    public function __construct(
        public bool $dev = false,
    ) {}
}
