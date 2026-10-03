<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Doctor\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Branding\Boundary\BrandingConfig;
use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Cbox\Cms\Panel\Doctor\Domain\Probes\BrandingProbe;
use Illuminate\Contracts\Config\Repository;
use Override;

/**
 * Reads the installation's brand from the configuration of this process and the files it names,
 * below the application's base path, as the panel reads it when it serves a page.
 */
#[Internal]
final readonly class ConfigBrandingProbe implements BrandingProbe
{
    public function __construct(
        private Repository $config,
        private string $basePath,
    ) {}

    #[Override]
    public function branding(): Branding
    {
        return BrandingConfig::read($this->config, $this->basePath);
    }
}
