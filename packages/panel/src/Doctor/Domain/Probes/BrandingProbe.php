<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Cbox\Cms\Panel\Branding\Domain\InvalidBranding;

/**
 * The installation's brand as this process reads it from cbox-cms.panel.branding.
 */
#[Internal]
interface BrandingProbe
{
    /**
     * @throws InvalidBranding
     */
    public function branding(): Branding;
}
