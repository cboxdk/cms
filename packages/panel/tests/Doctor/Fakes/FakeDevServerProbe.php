<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Doctor\Fakes;

use Cbox\Cms\Panel\Boundary\DevAddonsEnvironment;
use Cbox\Cms\Panel\Doctor\Domain\Probes\DevServerProbe;
use Cbox\Cms\Panel\Domain\Dto\DevAddons;
use Override;

/**
 * CBOX_CMS_PANEL_DEV_ADDONS and the environment a test gives.
 */
final readonly class FakeDevServerProbe implements DevServerProbe
{
    public function __construct(
        private ?string $setting = null,
        private string $environment = 'production',
    ) {}

    #[Override]
    public function setting(): ?string
    {
        return $this->setting;
    }

    #[Override]
    public function addons(): DevAddons
    {
        return DevAddonsEnvironment::parse($this->setting);
    }

    #[Override]
    public function environment(): string
    {
        return $this->environment;
    }
}
